<?php

declare(strict_types=1);

namespace ProductResearch\Admin;

use ProductResearch\Report\ReportPostType;
use ProductResearch\Report\ReportRepository;

/**
 * Metabox on the WooCommerce product edit page.
 *
 * Displays first-run empty state, in-progress status, results,
 * and report history. Scaffolds the container for JS rendering.
 */
final class MetaBox
{
    private ReportRepository $reports;

    public function __construct(ReportRepository $reports)
    {
        $this->reports = $reports;
    }

    /**
     * Register the metabox.
     */
    public function register(): void
    {
        add_meta_box(
            'pr-competitive-intelligence',
            __('Competitive Intelligence', 'product-research'),
            [$this, 'render'],
            'product',
            'normal',
            'high'
        );
    }

    /**
     * Render the metabox HTML.
     */
    public function render(\WP_Post $post): void
    {
        $productId = $post->ID;

        // Check for in-progress report
        $inProgress = $this->reports->getInProgress($productId);

        // Get latest completed report
        $latestReport = $this->reports->getLatest($productId);

        // Get report history
        $history = $this->reports->findByProduct($productId, 5);

        // Pass data to JS
        wp_nonce_field('pr_research_nonce', 'pr_nonce');

        $jsData = [
            'productId'     => $productId,
            'inProgress'    => $inProgress,
            'latestReport'  => $latestReport,
            'history'       => $history,
            'nonce'         => wp_create_nonce('pr_research_nonce'),
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'strings'       => $this->getStrings(),
        ];

        printf(
            '<div id="pr-metabox-root" data-config="%s"></div>',
            esc_attr(wp_json_encode($jsData))
        );
    }

    /**
     * Translatable UI strings for JS.
     *
     * @return array<string, string>
     */
    private function getStrings(): array
    {
        return [
            'firstRunTitle'       => __('Competitive Intelligence', 'product-research'),
            'firstRunDescription' => __('Analyze competitor products across the web. Get pricing, variations, and market insights powered by AI.', 'product-research'),
            'firstRunCta'         => __('Start Analysis', 'product-research'),
            'firstRunTime'        => __('Estimated time: 1-3 minutes', 'product-research'),
            'searching'           => __('Searching for competitors...', 'product-research'),
            'previewing'          => __('Review and select competitors to analyze', 'product-research'),
            'extracting'          => __('Extracting product data...', 'product-research'),
            'analyzing'           => __('Analyzing with AI...', 'product-research'),
            'complete'            => __('Analysis Complete', 'product-research'),
            'failed'              => __('Analysis Failed', 'product-research'),
            'confirmSelection'    => __('Analyze Selected', 'product-research'),
            'selectAll'           => __('Select All', 'product-research'),
            'deselectAll'         => __('Deselect All', 'product-research'),
            'retry'               => __('Try Again', 'product-research'),
            'newAnalysis'         => __('New Analysis', 'product-research'),
            'exportCsv'           => __('Export CSV', 'product-research'),
            'exportPdf'           => __('Export PDF', 'product-research'),
            'viewHistory'         => __('View History', 'product-research'),
            'priceRange'          => __('Price Range', 'product-research'),
            'competitors'         => __('Competitors', 'product-research'),
            'avgPrice'            => __('Avg Price', 'product-research'),
            'keyFindings'         => __('Key Findings', 'product-research'),
            'features'            => __('Common Features', 'product-research'),
            'variations'          => __('Variations', 'product-research'),
            'noResults'           => __('No competitor data found.', 'product-research'),
            'cancel'              => __('Cancel', 'product-research'),
        ];
    }
}
