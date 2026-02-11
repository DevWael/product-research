<?php

declare(strict_types=1);

namespace ProductResearch\Ajax;

use NeuronAI\Chat\Messages\UserMessage;
use ProductResearch\AI\Agent\ProductAnalysisAgent;
use ProductResearch\AI\Schema\CompetitorProfile;
use ProductResearch\API\ContentSanitizer;
use ProductResearch\API\TavilyClient;
use ProductResearch\Cache\CacheManager;
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
 */
final class ResearchHandler
{
    private TavilyClient $tavily;
    private ContentSanitizer $sanitizer;
    private ReportRepository $reports;
    private CacheManager $cache;
    private Logger $logger;

    public function __construct(
        TavilyClient $tavily,
        ContentSanitizer $sanitizer,
        ReportRepository $reports,
        CacheManager $cache,
        Logger $logger
    ) {
        $this->tavily    = $tavily;
        $this->sanitizer = $sanitizer;
        $this->reports   = $reports;
        $this->cache     = $cache;
        $this->logger    = $logger;
    }

    /**
     * Step 1: Run Tavily search and return URL preview for user confirmation.
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
                $results = $this->tavily->search($query);
                $this->cache->set($cacheKey, $results);
            }

            // Extract URLs from results
            $searchResults = $results['results'] ?? [];
            $urls = array_values(array_filter(array_map(
                static fn(array $r): string => $r['url'] ?? '',
                $searchResults
            )));

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
     * Step 2: Extract content from confirmed URLs, run AI analysis, save report.
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

            // --- Analyze ---
            $this->reports->updateStatus(
                $reportId,
                ReportPostType::STATUS_ANALYZING,
                __('Analyzing competitor data...', 'product-research')
            );

            $profiles = [];
            $agent    = ProductAnalysisAgent::make();

            foreach ($extractedContent as $item) {
                try {
                    /** @var CompetitorProfile $profile */
                    $profile = $agent->structured(
                        new UserMessage($item['content']),
                        CompetitorProfile::class
                    );

                    // Ensure the source URL is always set (AI may not extract it)
                    if (empty($profile->url)) {
                        $profile->url = $item['url'];
                    }

                    $profiles[] = $profile->toArray();
                } catch (\Throwable $e) {
                    $this->logger->log(sprintf('AI analysis failed for %s: %s', $item['url'], $e->getMessage()));
                    $failedUrls[] = $item['url'];
                }
            }

            if (empty($profiles)) {
                throw new \RuntimeException('AI analysis returned no valid results.');
            }

            // --- Save Report ---
            $this->reports->update($reportId, [
                ReportPostType::META_ANALYSIS_RESULT => $profiles,
                ReportPostType::META_ERROR_DETAILS   => ! empty($failedUrls)
                    ? ['failed_urls' => $failedUrls]
                    : null,
            ]);

            $this->reports->updateStatus(
                $reportId,
                ReportPostType::STATUS_COMPLETE,
                __('Analysis complete', 'product-research')
            );

            // Update cooldown timestamp
            update_post_meta($report['product_id'], '_pr_last_analysis', time());

            wp_send_json_success([
                'report_id'   => $reportId,
                'status'      => ReportPostType::STATUS_COMPLETE,
                'report'      => $profiles,
                'failed_urls' => $failedUrls,
            ]);
        } catch (\Throwable $e) {
            $this->logger->log(sprintf('Analysis failed for report %d: %s', $reportId, $e->getMessage()));

            $this->reports->updateStatus($reportId, ReportPostType::STATUS_FAILED, $e->getMessage());

            wp_send_json_error([
                'message' => __('Analysis failed. Please try again.', 'product-research'),
                'debug'   => defined('WP_DEBUG') && WP_DEBUG ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get current report status for polling.
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
     */
    public function handleGetReport(): void
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
            'report'    => $report['analysis_result'] ?? [],
            'created'   => $report['created_at'],
        ]);
    }

    /**
     * Build a search query from product data.
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

        $parts[] = 'price buy';

        return implode(' ', $parts);
    }

    /**
     * Verify nonce and capability.
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
     * @return array<string>
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
     */
    private function checkCreditBudget(): bool
    {
        $budget = (int) get_option('pr_daily_credit_budget', 0);

        if ($budget === 0) {
            return true; // Unlimited
        }

        return $this->tavily->getCreditsUsedToday() < $budget;
    }
}
