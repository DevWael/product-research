<?php

declare(strict_types=1);

namespace ProductResearch\Ajax;

use NeuronAI\Chat\Messages\UserMessage;
use ProductResearch\AI\Agent\ProductAnalysisAgent;
use ProductResearch\AI\Agent\RecommendationAgent;
use ProductResearch\AI\Schema\CompetitorProfile;
use ProductResearch\AI\Schema\RecommendationOutput;
use ProductResearch\API\ContentSanitizer;
use ProductResearch\API\TavilyClient;
use ProductResearch\Cache\CacheManager;
use ProductResearch\Currency\CurrencyConverter;
use ProductResearch\Report\ReportPostType;
use ProductResearch\Report\ReportRepository;
use ProductResearch\Security\Logger;

/**
 * AJAX handler for the research workflow.
 *
 * Provides endpoints for starting research, confirming URLs,
 * polling status, and retrieving reports.
 * Includes security guards: concurrent lock, cooldown, credit budget.
 *
 * Uses direct service calls instead of the Neuron Workflow engine
 * since we need an HTTP request/response boundary between
 * search (preview) and extract+analyze steps.
 *
 * @package ProductResearch\Ajax
 * @since   1.0.0
 */
final class ResearchHandler
{
    private TavilyClient $tavily;
    private ContentSanitizer $sanitizer;
    private ReportRepository $reports;
    private CacheManager $cache;
    private Logger $logger;
    private CurrencyConverter $converter;

    /**
     * Create the research handler with all required services.
     *
     * @since 1.0.0
     *
     * @param TavilyClient      $tavily    Tavily API client for search and extraction.
     * @param ContentSanitizer  $sanitizer HTML/text sanitizer for extracted content.
     * @param ReportRepository  $reports   Report persistence layer.
     * @param CacheManager      $cache     Transient-based cache service.
     * @param Logger            $logger    Sanitized error logging.
     * @param CurrencyConverter $converter Currency normalisation service.
     */
    public function __construct(
        TavilyClient $tavily,
        ContentSanitizer $sanitizer,
        ReportRepository $reports,
        CacheManager $cache,
        Logger $logger,
        CurrencyConverter $converter
    ) {
        $this->tavily    = $tavily;
        $this->sanitizer = $sanitizer;
        $this->reports   = $reports;
        $this->cache     = $cache;
        $this->logger    = $logger;
        $this->converter = $converter;
    }

    /**
     * Step 1: Run Tavily search and return URL preview for user confirmation.
     *
     * Enforces concurrent lock, per-product cooldown, and daily credit budget
     * before executing the search. Merges bookmarked URLs into results.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function handleStartResearch(): void
    {
        $this->verifyRequest();

        $productId = $this->getProductId();

        // Security guard: concurrent request lock
        $inProgress = $this->reports->getInProgress($productId);
        if ($inProgress !== null) {
            wp_send_json_success([
                'report_id' => $inProgress['id'],
                'status'    => $inProgress['status'],
                'message'   => __('Analysis already in progress.', 'product-research'),
                'resuming'  => true,
            ]);
        }

        // Security guard: per-product cooldown
        if (! $this->checkCooldown($productId)) {
            wp_send_json_error([
                'message' => __('Please wait before running another analysis on this product.', 'product-research'),
            ], 429);
        }

        // Security guard: daily credit budget
        if (! $this->checkCreditBudget()) {
            wp_send_json_error([
                'message' => __('Daily API credit budget exceeded.', 'product-research'),
            ], 429);
        }

        $product = wc_get_product($productId);
        if (! $product) {
            wp_send_json_error([
                'message' => __('Product not found.', 'product-research'),
            ], 404);
        }

        // Create report
        $reportId = $this->reports->create($productId);
        if ($reportId === 0) {
            wp_send_json_error([
                'message' => __('Failed to create report.', 'product-research'),
            ], 500);
        }

        try {
            // Build search query from product data
            $query = $this->buildSearchQuery($product);

            // Check cache first
            $cacheKey = $this->cache->generateKey($productId, 'search', $query);
            $results  = $this->cache->get($cacheKey);

            if (! is_array($results)) {
                $excludeRaw = get_option(
                    'pr_exclude_domains',
                    implode("\n", \ProductResearch\API\TavilyClient::DEFAULT_EXCLUDE_DOMAINS)
                );
                $excludeDomains = array_filter(array_map('trim', explode("\n", (string) $excludeRaw)));

                $results = $this->tavily->search($query, [
                    'exclude_domains' => $excludeDomains,
                ]);
                $this->cache->set($cacheKey, $results);
            }

            // Extract URLs from results
            $searchResults = $results['results'] ?? [];
            $urls = array_values(array_filter(array_map(
                static fn(array $r): string => $r['url'] ?? '',
                $searchResults
            )));

            // Merge bookmarked URLs into search results
            $bookmarkedUrls = get_post_meta($productId, '_pr_bookmarked_urls', true);
            if (is_array($bookmarkedUrls) && ! empty($bookmarkedUrls)) {
                foreach ($bookmarkedUrls as $bmUrl) {
                    if (! in_array($bmUrl, $urls, true)) {
                        $urls[]          = $bmUrl;
                        $searchResults[] = [
                            'url'     => $bmUrl,
                            'title'   => __('Bookmarked Competitor', 'product-research'),
                            'content' => '',
                            'score'   => 0,
                            'bookmarked' => true,
                        ];
                    }
                }
            }

            // Save search data to report
            $this->reports->update($reportId, [
                ReportPostType::META_SEARCH_QUERY   => $query,
                ReportPostType::META_COMPETITOR_DATA => $searchResults,
            ]);

            $this->reports->updateStatus(
                $reportId,
                ReportPostType::STATUS_PREVIEWING,
                __('Review search results', 'product-research')
            );

            wp_send_json_success([
                'report_id'      => $reportId,
                'search_results' => $searchResults,
                'urls'           => $urls,
                'query'          => $query,
                'status'         => ReportPostType::STATUS_PREVIEWING,
            ]);
        } catch (\Throwable $e) {
            $this->logger->log(sprintf('Start research failed: %s', $e->getMessage()));
            $this->reports->updateStatus($reportId, ReportPostType::STATUS_FAILED, $e->getMessage());

            wp_send_json_error([
                'message' => __('Search failed. Please check your API configuration.', 'product-research'),
                'debug'   => defined('WP_DEBUG') && WP_DEBUG ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Step 2: Extract content from confirmed URLs, save for analysis.
     *
     * Returns immediately after extraction so the frontend can
     * drive per-URL AI analysis without hitting PHP timeout.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function handleConfirmUrls(): void
    {
        $this->verifyRequest();

        $reportId = $this->getReportId();
        $urls     = $this->getSelectedUrls();

        if (empty($urls)) {
            wp_send_json_error([
                'message' => __('No valid URLs selected. Please select at least one competitor.', 'product-research'),
            ], 400);
        }

        $report = $this->reports->findById($reportId);
        if ($report === null) {
            wp_send_json_error(['message' => __('Report not found.', 'product-research')], 404);
        }

        // Save selected URLs
        $this->reports->update($reportId, [
            ReportPostType::META_SELECTED_URLS => $urls,
        ]);

        try {
            // --- Extract ---
            $this->reports->updateStatus(
                $reportId,
                ReportPostType::STATUS_EXTRACTING,
                __('Extracting competitor data...', 'product-research')
            );

            $extractedContent = [];
            $failedUrls       = [];

            // Tavily extract() takes an array of URLs and returns batch results
            $extractResponse = $this->tavily->extract($urls);
            $extractResults  = $extractResponse['results'] ?? [];

            // Map extracted content by URL
            foreach ($extractResults as $result) {
                $resultUrl  = $result['url'] ?? '';
                $rawContent = $result['raw_content'] ?? '';

                if ($rawContent !== '') {
                    $sanitized = $this->sanitizer->sanitize($rawContent);

                    if ($sanitized !== '') {
                        $extractedContent[] = [
                            'url'     => $resultUrl,
                            'content' => $sanitized,
                        ];
                    } else {
                        $failedUrls[] = $resultUrl;
                    }
                } else {
                    $failedUrls[] = $resultUrl;
                }
            }

            // Check for URLs that weren't returned in extract results
            $extractedUrls = array_column($extractResults, 'url');
            foreach ($urls as $url) {
                if (! in_array($url, $extractedUrls, true) && ! in_array($url, $failedUrls, true)) {
                    $failedUrls[] = $url;
                }
            }

            if (empty($extractedContent)) {
                throw new \RuntimeException('No competitor data could be extracted from the selected URLs.');
            }

            // Save extracted content directly (bypass setMeta's JSON encoding
            // because scraped HTML content can produce invalid JSON).
            update_post_meta($reportId, ReportPostType::META_EXTRACTED_CONTENT, $extractedContent);

            // Reset analysis results and save any extraction errors
            $this->reports->update($reportId, [
                ReportPostType::META_ANALYSIS_RESULT => [],
                ReportPostType::META_ERROR_DETAILS   => ! empty($failedUrls)
                    ? ['failed_urls' => $failedUrls]
                    : null,
            ]);

            $this->reports->updateStatus(
                $reportId,
                ReportPostType::STATUS_ANALYZING,
                __('Analyzing competitor data...', 'product-research')
            );

            wp_send_json_success([
                'report_id'  => $reportId,
                'status'     => ReportPostType::STATUS_ANALYZING,
                'total_urls' => count($extractedContent),
                'message'    => __('Extraction complete. Starting analysis...', 'product-research'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->log(sprintf('Extraction failed for report %d: %s', $reportId, $e->getMessage()));

            $this->reports->updateStatus($reportId, ReportPostType::STATUS_FAILED, $e->getMessage());

            wp_send_json_error([
                'message' => __('Extraction failed. Please try again.', 'product-research'),
                'debug'   => defined('WP_DEBUG') && WP_DEBUG ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Step 3: Analyze a single URL via AI.
     *
     * Called by the frontend in a loop — one call per extracted URL.
     * Each call processes one item and stays within PHP's 30s limit.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function handleAnalyzeUrl(): void
    {
        // Keep PHP running even if Nginx drops the connection (fastcgi_read_timeout).
        // The AI call takes 30-90s; Nginx may kill the connection at 60s but PHP
        // will finish and save the result. The JS retry will find it immediately.
        // ignore_user_abort(true);
        // @ini_set('max_execution_time', '120');
        // @set_time_limit(120);

        $this->verifyRequest();

        $reportId = $this->getReportId();
        $urlIndex = absint($_POST['url_index'] ?? 0);

        $report = $this->reports->findById($reportId);
        if ($report === null) {
            wp_send_json_error(['message' => __('Report not found.', 'product-research')], 404);
        }

        $extractedContent = get_post_meta($reportId, ReportPostType::META_EXTRACTED_CONTENT, true);
        if (! is_array($extractedContent) || empty($extractedContent)) {
            wp_send_json_error(['message' => __('No extracted content found.', 'product-research')], 400);
        }

        if (! isset($extractedContent[$urlIndex])) {
            wp_send_json_error(['message' => __('Invalid URL index.', 'product-research')], 400);
        }

        $item  = $extractedContent[$urlIndex];
        $total = count($extractedContent);

        // ── Check if this URL was already analyzed ──────────────────────
        // A previous request may have timed out at the Nginx level but
        // continued running in the background (ignore_user_abort).
        // If it completed, the result is already in the database.
        $existingRaw      = get_post_meta($reportId, ReportPostType::META_ANALYSIS_RESULT, true);
        $existingProfiles = is_string($existingRaw) && $existingRaw !== ''
            ? (json_decode($existingRaw, true) ?? [])
            : (is_array($existingRaw) ? $existingRaw : []);

        foreach ($existingProfiles as $existing) {
            if (isset($existing['url']) && $existing['url'] === $item['url']) {
                // Already analyzed — return immediately.
                wp_send_json_success([
                    'report_id' => $reportId,
                    'profile'   => $existing,
                    'progress'  => [
                        'current' => $urlIndex + 1,
                        'total'   => $total,
                    ],
                ]);
                return; // wp_send_json_success calls die(), but be explicit.
            }
        }

        $this->reports->updateStatus(
            $reportId,
            ReportPostType::STATUS_ANALYZING,
            sprintf(
                /* translators: %1$d: current URL number, %2$d: total URLs */
                __('Analyzing competitor %1$d of %2$d...', 'product-research'),
                $urlIndex + 1,
                $total
            )
        );

        try {
            $agent = ProductAnalysisAgent::make();

            // Truncate content to reduce LLM inference time.
            // Product data (name, price, variations) is in the first few KB;
            // the rest is navigation/footer noise that slows the call.
            $content = mb_substr($item['content'], 0, 8000);

            /** @var CompetitorProfile $profile */
            $profile = $agent->structured(
                new UserMessage($content),
                CompetitorProfile::class
            );

            // Ensure the source URL is always set (AI may not extract it)
            if (empty($profile->url)) {
                $profile->url = $item['url'];
            }

            $profileArray = $profile->toArray();

            // Append to existing analysis results — re-read to avoid race conditions.
            // Note: setMeta() stores arrays as JSON strings, so we must decode/re-encode.
            $rawProfiles        = get_post_meta($reportId, ReportPostType::META_ANALYSIS_RESULT, true);
            $currentProfiles    = is_string($rawProfiles) ? (json_decode($rawProfiles, true) ?? []) : (is_array($rawProfiles) ? $rawProfiles : []);
            $currentProfiles[]  = $profileArray;

            update_post_meta($reportId, ReportPostType::META_ANALYSIS_RESULT, wp_slash(wp_json_encode($currentProfiles)));

            wp_send_json_success([
                'report_id' => $reportId,
                'profile'   => $profileArray,
                'progress'  => [
                    'current' => $urlIndex + 1,
                    'total'   => $total,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->log(sprintf('AI analysis failed for %s: %s', $item['url'], $e->getMessage()));

            // Record the failed URL but don't fail the whole report
            $rawErrors    = get_post_meta($reportId, ReportPostType::META_ERROR_DETAILS, true);
            $errorDetails = is_array($rawErrors) ? $rawErrors : [];

            $errorDetails['failed_urls']   = $errorDetails['failed_urls'] ?? [];
            $errorDetails['failed_urls'][] = $item['url'];

            update_post_meta($reportId, ReportPostType::META_ERROR_DETAILS, $errorDetails);

            // Return success with error flag so frontend continues the loop
            wp_send_json_success([
                'report_id' => $reportId,
                'profile'   => null,
                'error'     => $e->getMessage(),
                'failed_url' => $item['url'],
                'progress'  => [
                    'current' => $urlIndex + 1,
                    'total'   => $total,
                ],
            ]);
        }
    }

    /**
     * Step 4: Finalize the report after all URLs have been analyzed.
     *
     * Normalises currencies, builds summary statistics, stores price
     * history, and optionally generates AI recommendations.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function handleFinalizeReport(): void
    {
        $this->verifyRequest();

        $reportId = $this->getReportId();

        $report = $this->reports->findById($reportId);
        if ($report === null) {
            wp_send_json_error(['message' => __('Report not found.', 'product-research')], 404);
        }

        $rawProfiles = get_post_meta($reportId, ReportPostType::META_ANALYSIS_RESULT, true);
        $profiles    = is_string($rawProfiles) && $rawProfiles !== '' ? (json_decode($rawProfiles, true) ?? []) : (is_array($rawProfiles) ? $rawProfiles : []);

        if (empty($profiles)) {
            $this->reports->updateStatus($reportId, ReportPostType::STATUS_FAILED, 'AI analysis returned no valid results.');

            wp_send_json_error([
                'message' => __('No competitors could be analyzed. Please try again.', 'product-research'),
            ], 500);
        }

        // Build structured report matching the format expected by the JS metabox.
        // The async workflow path (ReportNode) does this automatically;
        // the sequential AJAX path must do it here.

        // --- Currency normalization ---
        $storeCurrency = $this->converter->getStoreCurrency();
        $this->converter->normalizeProfiles($profiles, $storeCurrency);

        $prices = CurrencyConverter::extractValidPricesFromArrays($profiles);

        $summary = [
            'total_competitors' => count($profiles),
            'lowest_price'      => ! empty($prices) ? round(min($prices), 2) : 0,
            'highest_price'     => ! empty($prices) ? round(max($prices), 2) : 0,
            'avg_price'         => ! empty($prices) ? round(array_sum($prices) / count($prices), 2) : 0,
            'key_findings'      => $this->buildKeyFindings($profiles),
        ];

        $structuredReport = [
            'competitors' => $profiles,
            'summary'     => $summary,
        ];

        // Overwrite the flat profiles array with the structured report.
        $this->reports->update($reportId, [
            ReportPostType::META_ANALYSIS_RESULT => $structuredReport,
        ]);

        // Clean up extracted content (no longer needed)
        delete_post_meta($reportId, ReportPostType::META_EXTRACTED_CONTENT);

        $this->reports->updateStatus(
            $reportId,
            ReportPostType::STATUS_COMPLETE,
            __('Analysis complete', 'product-research')
        );

        // Update cooldown timestamp
        update_post_meta($report['product_id'], '_pr_last_analysis', time());

        // Store badge data for product list column
        $product = wc_get_product($report['product_id']);
        $productPrice = $product ? (float) $product->get_price() : 0.0;
        update_post_meta($report['product_id'], '_pr_badge_data', [
            'competitor_count' => count($profiles),
            'price_position'  => $this->calculatePricePosition($productPrice, $prices),
        ]);

        // Store price history snapshot
        $priceHistory = get_post_meta($report['product_id'], '_pr_price_history', true);
        $priceHistory = is_array($priceHistory) ? $priceHistory : [];
        $priceHistory[] = [
            'date'           => wp_date('Y-m-d'),
            'product_price'  => $productPrice,
            'avg_price'      => $summary['avg_price'],
            'lowest_price'   => $summary['lowest_price'],
            'highest_price'  => $summary['highest_price'],
            'competitors'    => count($profiles),
            'store_currency' => $storeCurrency,
        ];
        // Cap at 20 entries
        $priceHistory = array_slice($priceHistory, -20);
        update_post_meta($report['product_id'], '_pr_price_history', $priceHistory);

        // Auto-generate recommendations if enabled
        $recommendations = [];
        if (get_option('pr_auto_recommendations', false)) {
            try {
                $agent  = RecommendationAgent::make();
                $prompt = sprintf(
                    "Product: %s\nPrice: %s\n\nCompetitor Data:\n%s",
                    $product ? $product->get_name() : 'Unknown',
                    $product ? $product->get_price() : 'N/A',
                    wp_json_encode($structuredReport, JSON_PRETTY_PRINT)
                );
                $output = $agent->structured(new UserMessage($prompt), RecommendationOutput::class);
                $recommendations = $output->toArray();
                update_post_meta($reportId, '_pr_recommendations', $recommendations);
            } catch (\Throwable $e) {
                $this->logger->log(sprintf('Auto-recommendations failed for report %d: %s', $reportId, $e->getMessage()), 'warning');
            }
        }

        // Clean up old recommendation cache (previously stored on the product, now on the report)
        delete_post_meta($report['product_id'], '_pr_recommendations');

        wp_send_json_success([
            'report_id'       => $reportId,
            'status'          => ReportPostType::STATUS_COMPLETE,
            'report'          => $structuredReport,
            'recommendations' => $recommendations,
            'failed_urls'     => $report['error_details']['failed_urls'] ?? [],
            'price_history'   => $priceHistory,
        ]);
    }

    /**
     * Cancel an in-progress report.
     *
     * Only non-terminal reports can be cancelled.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function handleCancelReport(): void
    {
        $this->verifyRequest();

        $reportId = $this->getReportId();
        $report   = $this->reports->findById($reportId);

        if ($report === null) {
            wp_send_json_error(['message' => __('Report not found.', 'product-research')], 404);
        }

        // Only cancel non-terminal reports.
        if (in_array($report['status'], [ReportPostType::STATUS_COMPLETE, ReportPostType::STATUS_FAILED], true)) {
            wp_send_json_error(['message' => __('Report is already finished.', 'product-research')], 400);
        }

        $this->reports->updateStatus(
            $reportId,
            ReportPostType::STATUS_FAILED,
            __('Cancelled by user.', 'product-research')
        );

        // Clean up extracted content (no longer needed).
        delete_post_meta($reportId, ReportPostType::META_EXTRACTED_CONTENT);

        $this->logger->log(sprintf('Report %d cancelled by user.', $reportId));

        wp_send_json_success([
            'report_id' => $reportId,
            'status'    => ReportPostType::STATUS_FAILED,
            'message'   => __('Analysis cancelled.', 'product-research'),
        ]);
    }

    /**
     * Get current report status for polling.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function handleGetStatus(): void
    {
        $this->verifyRequest();

        $reportId = $this->getReportId();
        $report   = $this->reports->findById($reportId);

        if ($report === null) {
            wp_send_json_error(['message' => __('Report not found.', 'product-research')], 404);
        }

        wp_send_json_success([
            'report_id' => $reportId,
            'status'    => $report['status'],
            'message'   => $report['progress_message'],
        ]);
    }

    /**
     * Get completed report data.
     *
     * Includes recommendations and price history in the response.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function handleGetReport(): void
    {
        $this->verifyRequest();

        $reportId = $this->getReportId();
        $report   = $this->reports->findById($reportId);

        if ($report === null) {
            wp_send_json_error(['message' => __('Report not found.', 'product-research')], 404);
        }

        $recommendations = get_post_meta($reportId, '_pr_recommendations', true);

        // Include price history so the chart updates when loading any report
        $priceHistory = get_post_meta($report['product_id'], '_pr_price_history', true);
        $priceHistory = is_array($priceHistory) ? $priceHistory : [];

        wp_send_json_success([
            'report_id'       => $reportId,
            'status'          => $report['status'],
            'report'          => $report['analysis_result'] ?? [],
            'recommendations' => is_array($recommendations) ? $recommendations : [],
            'created'         => $report['created_at'],
            'price_history'   => $priceHistory,
        ]);
    }

    /**
     * Generate on-demand AI recommendations for a completed report.
     *
     * Returns cached recommendations when available; otherwise invokes
     * the RecommendationAgent and persists the result.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function handleGetRecommendations(): void
    {
        $this->verifyRequest();

        $reportId = $this->getReportId();
        $report   = $this->reports->findById($reportId);

        if ($report === null) {
            wp_send_json_error(['message' => __('Report not found.', 'product-research')], 404);
        }

        if ($report['status'] !== ReportPostType::STATUS_COMPLETE) {
            wp_send_json_error(['message' => __('Report is not complete.', 'product-research')], 400);
        }

        // Return cached recommendations if available
        $cached = get_post_meta($reportId, '_pr_recommendations', true);
        if (is_array($cached) && ! empty($cached)) {
            wp_send_json_success(['recommendations' => $cached]);
        }

        $product = wc_get_product($report['product_id']);
        if (! $product) {
            wp_send_json_error(['message' => __('Product not found.', 'product-research')], 404);
        }

        try {
            $agent  = RecommendationAgent::make();
            $prompt = sprintf(
                "Product: %s\nPrice: %s\n\nCompetitor Data:\n%s",
                $product->get_name(),
                $product->get_price(),
                wp_json_encode($report['analysis_result'] ?? [], JSON_PRETTY_PRINT)
            );
            $output = $agent->structured(new UserMessage($prompt), RecommendationOutput::class);
            $recommendations = $output->toArray();

            // Cache for future requests
            update_post_meta($reportId, '_pr_recommendations', $recommendations);

            wp_send_json_success(['recommendations' => $recommendations]);
        } catch (\Throwable $e) {
            $this->logger->log(sprintf('Recommendations failed for report %d: %s', $reportId, $e->getMessage()));
            wp_send_json_error(['message' => __('Failed to generate recommendations. Please try again.', 'product-research')], 500);
        }
    }

    /**
     * Build a search query from product data.
     *
     * Combines product name, category, brand, SKU, and tags
     * into a Tavily-optimised search string.
     *
     * @since 1.0.0
     *
     * @param  \WC_Product $product WooCommerce product instance.
     * @return string Constructed search query.
     */
    private function buildSearchQuery(\WC_Product $product): string
    {
        $parts = [];

        $name = $product->get_name();
        if ($name !== '') {
            $parts[] = sprintf('"%s"', $name);
        }

        $categoryIds = $product->get_category_ids();
        if (! empty($categoryIds)) {
            $term = get_term($categoryIds[0], 'product_cat');
            if ($term && ! is_wp_error($term)) {
                $parts[] = $term->name;
            }
        }

        $brand = $product->get_attribute('brand');
        if ($brand !== '') {
            $parts[] = $brand;
        }

        $sku = $product->get_sku();
        if ($sku !== '') {
            $parts[] = $sku;
        }

        $tagIds = $product->get_tag_ids();
        if (! empty($tagIds)) {
            $tags = array_map(static function (int $id): string {
                $term = get_term($id, 'product_tag');
                return ($term && ! is_wp_error($term)) ? $term->name : '';
            }, array_slice($tagIds, 0, 3));
            $parts = array_merge($parts, array_filter($tags));
        }

        $parts[] = '"add to cart" OR "buy now" OR "shop" price';

        return implode(' ', $parts);
    }

    /**
     * Verify nonce and capability.
     *
     * Terminates with a 403 JSON error if verification fails.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function verifyRequest(): void
    {
        if (! check_ajax_referer('pr_research_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'product-research')], 403);
        }

        $capability = apply_filters('pr_required_capability', get_option('pr_capability', 'edit_products'));

        if (! current_user_can($capability)) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'product-research')], 403);
        }
    }

    /**
     * Get and validate product ID from request.
     *
     * Terminates with a 400 JSON error if invalid.
     *
     * @since 1.0.0
     *
     * @return int Validated product post ID.
     */
    private function getProductId(): int
    {
        $productId = absint($_POST['product_id'] ?? 0);

        if ($productId === 0) {
            wp_send_json_error(['message' => __('Invalid product ID.', 'product-research')], 400);
        }

        return $productId;
    }

    /**
     * Get and validate report ID from request.
     *
     * Terminates with a 400 JSON error if invalid.
     *
     * @since 1.0.0
     *
     * @return int Validated report post ID.
     */
    private function getReportId(): int
    {
        $reportId = absint($_POST['report_id'] ?? $_GET['report_id'] ?? 0);

        if ($reportId === 0) {
            wp_send_json_error(['message' => __('Invalid report ID.', 'product-research')], 400);
        }

        return $reportId;
    }

    /**
     * Get selected URLs from request.
     *
     * Handles both array and JSON-encoded string inputs.
     *
     * @since 1.0.0
     *
     * @return array<string> Sanitised URL list.
     */
    private function getSelectedUrls(): array
    {
        $raw = $_POST['selected_urls'] ?? [];

        $this->logger->log(sprintf(
            'getSelectedUrls raw input type=%s value=%s',
            gettype($raw),
            is_string($raw) ? substr($raw, 0, 500) : 'array(' . count($raw) . ')'
        ));

        if (is_array($raw)) {
            $urls = $raw;
        } else {
            // Frontend sends JSON.stringify(urls) — decode the JSON string
            $unslashed = wp_unslash((string) $raw);
            $urls      = json_decode($unslashed, true);

            if (! is_array($urls)) {
                $this->logger->log(sprintf('json_decode failed for: %s', substr($unslashed, 0, 200)));
                $urls = [];
            }
        }

        return array_values(array_filter(array_map('esc_url_raw', $urls)));
    }

    /**
     * Check per-product cooldown.
     *
     * @since 1.0.0
     *
     * @param  int  $productId WooCommerce product post ID.
     * @return bool True if cooldown has elapsed or is bypassed.
     */
    private function checkCooldown(int $productId): bool
    {
        $forceRefresh = ! empty($_POST['force_refresh']);
        if ($forceRefresh) {
            return true;
        }

        $cooldownMinutes = (int) get_option('pr_cooldown_minutes', 5);
        if ($cooldownMinutes === 0) {
            return true;
        }

        $lastAnalysis = (int) get_post_meta($productId, '_pr_last_analysis', true);
        if ($lastAnalysis === 0) {
            return true;
        }

        return (time() - $lastAnalysis) >= ($cooldownMinutes * 60);
    }

    /**
     * Check daily credit budget.
     *
     * @since 1.0.0
     *
     * @return bool True if budget is not exceeded or is unlimited.
     */
    private function checkCreditBudget(): bool
    {
        $budget = (int) get_option('pr_daily_credit_budget', 0);

        if ($budget === 0) {
            return true; // Unlimited
        }

        return $this->tavily->getCreditsUsedToday() < $budget;
    }

    /**
     * Determine price position relative to competitor prices.
     *
     * Returns 'below' when the product is ≤90% of the average,
     * 'above' when ≥110%, and 'at' otherwise.
     *
     * @since 1.0.0
     *
     * @param  float   $productPrice    Our product's price.
     * @param  float[] $competitorPrices Array of competitor prices.
     * @return string  'below', 'at', or 'above'.
     */
    private function calculatePricePosition(float $productPrice, array $competitorPrices): string
    {
        if (empty($competitorPrices) || $productPrice <= 0) {
            return 'at';
        }

        $avg = array_sum($competitorPrices) / count($competitorPrices);

        if ($productPrice < $avg * 0.9) {
            return 'below';
        }

        if ($productPrice > $avg * 1.1) {
            return 'above';
        }

        return 'at';
    }

    /**
     * Delete a completed or failed report.
     *
     * Returns the updated history list so the frontend can re-render.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function handleDeleteReport(): void
    {
        $this->verifyRequest();

        $reportId = $this->getReportId();
        $report   = $this->reports->findById($reportId);

        if ($report === null) {
            wp_send_json_error(['message' => __('Report not found.', 'product-research')], 404);
        }

        // Prevent deleting in-progress reports.
        $terminalStatuses = [ReportPostType::STATUS_COMPLETE, ReportPostType::STATUS_FAILED];
        if (! in_array($report['status'], $terminalStatuses, true)) {
            wp_send_json_error([
                'message' => __('Cannot delete a report that is still in progress.', 'product-research'),
            ], 400);
        }

        $productId = $report['product_id'];

        if (! $this->reports->delete($reportId)) {
            wp_send_json_error([
                'message' => __('Failed to delete report.', 'product-research'),
            ], 500);
        }

        $this->logger->log(sprintf('Report %d deleted by user.', $reportId));

        // Return updated history so the frontend can rebuild.
        $history = $this->reports->findByProduct($productId, 20);

        wp_send_json_success([
            'deleted'   => $reportId,
            'history'   => $history,
        ]);
    }

    /**
     * Bulk-delete multiple completed/failed reports.
     *
     * Accepts an array of report IDs via POST['report_ids'].
     * Skips non-terminal and invalid reports silently.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function handleDeleteReports(): void
    {
        $this->verifyRequest();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verifyRequest()
        $raw = isset($_POST['report_ids']) ? wp_unslash($_POST['report_ids']) : [];
        $ids = is_array($raw) ? array_map('absint', $raw) : [];
        $ids = array_filter($ids);

        if (empty($ids)) {
            wp_send_json_error(['message' => __('No reports selected.', 'product-research')], 400);
        }

        $terminalStatuses = [ReportPostType::STATUS_COMPLETE, ReportPostType::STATUS_FAILED];
        $deleted   = [];
        $productId = 0;

        foreach ($ids as $id) {
            $report = $this->reports->findById($id);
            if ($report === null) {
                continue;
            }
            if (! in_array($report['status'], $terminalStatuses, true)) {
                continue;
            }
            if ($productId === 0) {
                $productId = $report['product_id'];
            }
            if ($this->reports->delete($id)) {
                $deleted[] = $id;
            }
        }

        $this->logger->log(sprintf('Bulk-deleted %d reports: [%s]', count($deleted), implode(', ', $deleted)));

        $history = $productId > 0 ? $this->reports->findByProduct($productId, 20) : [];

        wp_send_json_success([
            'deleted' => $deleted,
            'history' => $history,
        ]);
    }

    /**
     * Build human-readable key findings from competitor profiles.
     *
     * Summarises price range, conversion warnings, discounts,
     * and product variations into translatable strings.
     *
     * @since 1.0.0
     *
     * @param  array<int, array<string, mixed>> $profiles Analysed competitor profiles.
     * @return array<int, string> List of finding sentences.
     */
    private function buildKeyFindings(array $profiles): array
    {
        $findings = [];

        // Use converted prices, excluding failed and zero
        $prices = CurrencyConverter::extractValidPricesFromArrays($profiles);

        if (! empty($prices)) {
            $storeCurrency = $profiles[0]['store_currency'] ?? ($profiles[0]['currency'] ?? '');
            $findings[]    = sprintf(
                /* translators: %1$s: currency, %2$.2f: lowest price, %3$.2f: highest price, %4$d: competitor count */
                __('Price range: %1$s %2$.2f – %1$s %3$.2f across %4$d competitors', 'product-research'),
                $storeCurrency,
                min($prices),
                max($prices),
                count($profiles)
            );
        }

        // Failed conversion warning
        $failed = array_filter($profiles, static fn(array $p): bool => ($p['conversion_status'] ?? '') === 'failed');
        if (! empty($failed)) {
            $findings[] = sprintf(
                __('[!] %d competitor(s) could not have their prices converted — excluded from averages', 'product-research'),
                count($failed)
            );
        }

        // Discounts
        $discounted = array_filter($profiles, static fn(array $p): bool => ! empty($p['original_price']) && $p['original_price'] > 0);
        if (! empty($discounted)) {
            $findings[] = sprintf(
                __('%d competitor(s) currently offering discounts', 'product-research'),
                count($discounted)
            );
        }

        // Variations
        $withVariations = array_filter($profiles, static fn(array $p): bool => ! empty($p['variations']));
        if (! empty($withVariations)) {
            $findings[] = sprintf(
                __('%d competitor(s) offer product variations', 'product-research'),
                count($withVariations)
            );
        }

        return $findings;
    }
}
