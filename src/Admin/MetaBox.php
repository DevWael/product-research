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

        // Product data for charts
        $product       = wc_get_product($productId);
        $productPrice  = $product ? (float) $product->get_price() : 0.0;
        $productCurrency = $product ? get_woocommerce_currency_symbol() : '';

        // Price history snapshots
        $priceHistory = get_post_meta($productId, '_pr_price_history', true);
        $priceHistory = is_array($priceHistory) ? $priceHistory : [];

        // Bookmarked competitor URLs
        $bookmarkedUrls = get_post_meta($productId, '_pr_bookmarked_urls', true);
        $bookmarkedUrls = is_array($bookmarkedUrls) ? $bookmarkedUrls : [];

        $jsData = [
            'productId'       => $productId,
            'inProgress'      => $inProgress,
            'latestReport'    => $latestReport,
            'history'         => $history,
            'productPrice'    => $productPrice,
            'productCurrency' => $productCurrency,
            'priceHistory'    => $priceHistory,
            'bookmarkedUrls'  => $bookmarkedUrls,
            'nonce'           => wp_create_nonce('pr_research_nonce'),
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'strings'         => $this->getStrings(),
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
            'recommendations'     => __('AI Recommendations', 'product-research'),
            'getRecommendations'  => __('Get AI Recommendations', 'product-research'),
            'loadingRecs'         => __('Generating recommendations...', 'product-research'),
            'recsFailed'          => __('Failed to load recommendations.', 'product-research'),
            // Chart strings
            'priceChart'          => __('Price Comparison', 'product-research'),
            'yourProduct'         => __('Your Product', 'product-research'),
            'chartNoData'         => __('No price data available for chart.', 'product-research'),
            // History strings
            'priceHistory'        => __('Price History', 'product-research'),
            'historyNoData'       => __('Run at least two analyses to see price trends.', 'product-research'),
            // Bookmark strings
            'bookmark'            => __('Bookmark', 'product-research'),
            'bookmarked'          => __('Bookmarked', 'product-research'),
            'unbookmark'          => __('Remove Bookmark', 'product-research'),
            // Copywriter strings
            'generateCopy'        => __('Generate Product Description', 'product-research'),
            'copyLoading'         => __('Writing product copy...', 'product-research'),
            'copyFailed'          => __('Failed to generate copy.', 'product-research'),
            'copyTitle'           => __('AI Product Description', 'product-research'),
            'applyCopy'           => __('Apply to Product', 'product-research'),
            'copyApplied'         => __('Description updated!', 'product-research'),
            'revertCopy'          => __('Revert to Original', 'product-research'),
            'toneProfessional'    => __('Professional', 'product-research'),
            'toneCasual'          => __('Casual', 'product-research'),
            'toneLuxury'          => __('Luxury', 'product-research'),
            'toneDiscount'        => __('Discount', 'product-research'),
            // Comparison strings
            'compare'             => __('Compare', 'product-research'),
            'compareSelected'     => __('Compare Selected', 'product-research'),
            'comparisonTitle'     => __('Side-by-Side Comparison', 'product-research'),
            'selectToCompare'     => __('Select 2-3 competitors to compare.', 'product-research'),
            'closeComparison'     => __('Close', 'product-research'),
        ];
    }
}
