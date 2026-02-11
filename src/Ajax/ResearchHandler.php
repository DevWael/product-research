<?php

declare(strict_types=1);

namespace ProductResearch\Ajax;

use NeuronAI\Workflow\StartEvent;
use ProductResearch\AI\Workflow\ProductResearchWorkflow;
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
     * Start research: create report, run search, return preview data.
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
            $workflow = $this->createWorkflow();

            $state = new \NeuronAI\Workflow\WorkflowState();
            $state->set('report_id', $reportId);
            $state->set('product_id', $productId);
            $state->set('product_title', $product->get_name());
            $state->set('product_category', $this->getProductCategory($product));
            $state->set('product_brand', $this->getProductBrand($product));

            // Run search node only (manually invoke first node)
            $searchNode  = $workflow->nodes()[0] ?? null;
            $searchEvent = $searchNode(new StartEvent(), $state);

            // Update status to previewing
            $this->reports->updateStatus(
                $reportId,
                ReportPostType::STATUS_PREVIEWING,
                __('Review search results', 'product-research')
            );

            wp_send_json_success([
                'report_id'      => $reportId,
                'search_results' => $searchEvent->searchResults,
                'urls'           => $searchEvent->urls,
                'query'          => $searchEvent->query,
                'status'         => ReportPostType::STATUS_PREVIEWING,
            ]);
        } catch (\Throwable $e) {
            $this->logger->log(sprintf('Start research failed: %s', $e->getMessage()));

            $this->reports->updateStatus($reportId, ReportPostType::STATUS_FAILED, $e->getMessage());

            wp_send_json_error([
                'message' => __('Search failed. Please check your API configuration.', 'product-research'),
            ], 500);
        }
    }

    /**
     * Confirm selected URLs and continue workflow (Extract → Analyze → Report).
     */
    public function handleConfirmUrls(): void
    {
        $this->verifyRequest();

        $reportId = $this->getReportId();
        $urls     = $this->getSelectedUrls();

        $report = $this->reports->findById($reportId);
        if ($report === null) {
            wp_send_json_error(['message' => __('Report not found.', 'product-research')], 404);
        }

        // Save selected URLs
        $this->reports->update($reportId, [
            ReportPostType::META_SELECTED_URLS => $urls,
        ]);

        try {
            $workflow = $this->createWorkflow();

            $state = new \NeuronAI\Workflow\WorkflowState();
            $state->set('report_id', $reportId);
            $state->set('product_id', $report['product_id']);
            $state->set('selected_urls', $urls);

            // Build search event from stored data
            $searchResults = $report['competitor_data'] ?? [];
            $searchEvent   = new \ProductResearch\AI\Workflow\Events\SearchCompletedEvent(
                $searchResults,
                $urls,
                $report['search_query'] ?? ''
            );

            // Run extract → analyze → report nodes
            $nodes = $workflow->nodes();

            $extractEvent  = ($nodes[1])($searchEvent, $state);
            $analyzeEvent  = ($nodes[2])($extractEvent, $state);
            $stopEvent     = ($nodes[3])($analyzeEvent, $state);

            // Update cooldown timestamp
            update_post_meta($report['product_id'], '_pr_last_analysis', time());

            $finalReport = $this->reports->findById($reportId);

            wp_send_json_success([
                'report_id' => $reportId,
                'status'    => ReportPostType::STATUS_COMPLETE,
                'report'    => $finalReport['analysis_result'] ?? [],
            ]);
        } catch (\Throwable $e) {
            $this->logger->log(sprintf('Analysis failed for report %d: %s', $reportId, $e->getMessage()));

            $this->reports->updateStatus($reportId, ReportPostType::STATUS_FAILED, $e->getMessage());
            $this->reports->update($reportId, [
                ReportPostType::META_ERROR_DETAILS => [
                    'message' => __('Analysis failed. Some competitors may have been unreachable.', 'product-research'),
                    'code'    => 'analysis_error',
                ],
            ]);

            wp_send_json_error([
                'message' => __('Analysis failed. Please try again.', 'product-research'),
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
        $urls = $_POST['selected_urls'] ?? [];

        if (! is_array($urls)) {
            $urls = json_decode(sanitize_text_field((string) $urls), true) ?? [];
        }

        return array_map('esc_url_raw', array_filter($urls));
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

    /**
     * Get primary product category name.
     */
    private function getProductCategory(\WC_Product $product): string
    {
        $categoryIds = $product->get_category_ids();

        if (empty($categoryIds)) {
            return '';
        }

        $term = get_term($categoryIds[0], 'product_cat');

        return ($term && ! is_wp_error($term)) ? $term->name : '';
    }

    /**
     * Get product brand (from common brand attribute or taxonomy).
     */
    private function getProductBrand(\WC_Product $product): string
    {
        // Check common brand attribute
        $brand = $product->get_attribute('brand');
        if ($brand !== '') {
            return $brand;
        }

        // Check pa_brand taxonomy
        $terms = wp_get_post_terms($product->get_id(), 'pa_brand');
        if (! is_wp_error($terms) && ! empty($terms)) {
            return $terms[0]->name;
        }

        return '';
    }

    /**
     * Create the workflow instance with dependencies.
     */
    private function createWorkflow(): ProductResearchWorkflow
    {
        return new ProductResearchWorkflow(
            $this->tavily,
            $this->sanitizer,
            $this->cache,
            $this->reports,
            $this->logger
        );
    }
}
