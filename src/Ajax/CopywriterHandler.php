<?php

declare(strict_types=1);

namespace ProductResearch\Ajax;

use NeuronAI\Chat\Messages\UserMessage;
use ProductResearch\AI\Agent\CopywriterAgent;
use ProductResearch\AI\Schema\CopywriterOutput;
use ProductResearch\Report\ReportRepository;
use ProductResearch\Security\Logger;

/**
 * AJAX handler for AI product description generation.
 *
 * Takes competitor analysis data and a tone preference,
 * then calls the CopywriterAgent for structured output.
 *
 * @package ProductResearch\Ajax
 * @since   1.0.0
 */
final class CopywriterHandler
{
    private ReportRepository $reports;
    private Logger $logger;

    /**
     * Create the copywriter handler.
     *
     * @since 1.0.0
     *
     * @param ReportRepository $reports Report persistence layer.
     * @param Logger           $logger  Sanitized error logging.
     */
    public function __construct(ReportRepository $reports, Logger $logger)
    {
        $this->reports = $reports;
        $this->logger  = $logger;
    }

    /**
     * Generate an AI product description from competitor analysis.
     *
     * Reads the report and product data, invokes the CopywriterAgent,
     * sanitises the HTML output, and returns it as JSON.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function handleGenerateCopy(): void
    {
        $this->verifyRequest();

        $reportId = absint($_POST['report_id'] ?? 0);
        $tone     = sanitize_text_field(wp_unslash($_POST['tone'] ?? 'professional'));

        $report = $this->reports->findById($reportId);
        if ($report === null) {
            wp_send_json_error(['message' => __('Report not found.', 'product-research')], 404);
        }

        $product = wc_get_product($report['product_id']);
        if (! $product) {
            wp_send_json_error(['message' => __('Product not found.', 'product-research')], 404);
        }

        $analysisResult = $report['analysis_result'] ?? [];

        try {
            $agent  = CopywriterAgent::make();
            $prompt = sprintf(
                "Product: %s\nCurrent Price: %s\nTone: %s\n\nCompetitor Analysis Data:\n%s",
                $product->get_name(),
                $product->get_price() ?: 'N/A',
                $tone,
                wp_json_encode($analysisResult, JSON_PRETTY_PRINT)
            );

            $output = $agent->structured(new UserMessage($prompt), CopywriterOutput::class);

            // Sanitize HTML output
            $result = $output->toArray();
            $result['full_description'] = wp_kses_post($result['full_description']);

            wp_send_json_success(['copy' => $result]);
        } catch (\Throwable $e) {
            $this->logger->log(
                sprintf('Copywriter failed for report %d: %s', $reportId, $e->getMessage()),
                'warning'
            );

            wp_send_json_error([
                'message' => __('Failed to generate product description. Please try again.', 'product-research'),
                'debug'   => defined('WP_DEBUG') && WP_DEBUG ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Verify nonce and capabilities.
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

        if (! current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'product-research')], 403);
        }
    }
}
