<?php

declare(strict_types=1);

namespace ProductResearch\Export;

use ProductResearch\Report\ReportRepository;

/**
 * Export service for generating CSV and PDF downloads from reports.
 *
 * PDF generation uses a simple HTML-to-PDF approach via browser print.
 */
final class ReportExporter
{
    private ReportRepository $reports;

    public function __construct(ReportRepository $reports)
    {
        $this->reports = $reports;
    }

    /**
     * Handle CSV export AJAX request.
     */
    public function handleCsvExport(): void
    {
        $this->verifyRequest();

        $reportId = absint($_GET['report_id'] ?? 0);
        $report   = $this->reports->findById($reportId);

        if (! $report || empty($report['analysis_result'])) {
            wp_die(esc_html__('Report not found or incomplete.', 'product-research'));
        }

        $data = $report['analysis_result'];
        $competitors = $data['competitors'] ?? [];

        $filename = sprintf('competitive-research-%d-%s.csv', $report['product_id'], wp_date('Y-m-d'));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // Header row
        fputcsv($output, [
            'Product Name',
            'Current Price',
            'Original Price',
            'Currency',
            'URL',
            'Availability',
            'Shipping',
            'Seller',
            'Rating',
            'Variations Count',
            'Features',
        ]);

        // Data rows
        foreach ($competitors as $comp) {
            fputcsv($output, [
                $comp['name'] ?? '',
                $comp['current_price'] ?? '',
                $comp['original_price'] ?? '',
                $comp['currency'] ?? '',
                $comp['url'] ?? '',
                $comp['availability'] ?? '',
                $comp['shipping_info'] ?? '',
                $comp['seller_name'] ?? '',
                $comp['rating'] ?? '',
                count($comp['variations'] ?? []),
                implode('; ', $comp['features'] ?? []),
            ]);
        }

        fclose($output);
        exit;
    }

    /**
     * Handle PDF export — outputs HTML for browser print.
     */
    public function handlePdfExport(): void
    {
        $this->verifyRequest();

        $reportId = absint($_GET['report_id'] ?? 0);
        $report   = $this->reports->findById($reportId);

        if (! $report || empty($report['analysis_result'])) {
            wp_die(esc_html__('Report not found or incomplete.', 'product-research'));
        }

        $data        = $report['analysis_result'];
        $summary     = $data['summary'] ?? [];
        $competitors = $data['competitors'] ?? [];
        $productId   = $report['product_id'];

        $product = wc_get_product($productId);
        $title   = $product ? $product->get_name() : sprintf('Product #%d', $productId);

        header('Content-Type: text/html; charset=utf-8');

        echo '<!DOCTYPE html><html><head>';
        echo '<meta charset="utf-8">';
        echo '<title>' . esc_html(sprintf(__('Competitive Analysis — %s', 'product-research'), $title)) . '</title>';
        echo '<style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; color: #1d2327; }
            h1 { font-size: 24px; border-bottom: 2px solid #2271b1; padding-bottom: 8px; }
            .summary { display: flex; gap: 16px; margin: 16px 0; }
            .summary-card { flex: 1; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 6px; padding: 12px; text-align: center; }
            .summary-card .label { font-size: 11px; text-transform: uppercase; color: #646970; }
            .summary-card .value { font-size: 22px; font-weight: 700; }
            table { width: 100%; border-collapse: collapse; margin: 12px 0; font-size: 13px; }
            th, td { padding: 6px 10px; border: 1px solid #dcdcde; text-align: left; }
            th { background: #f0f0f1; font-weight: 600; }
            .findings { background: #fcf9e8; border: 1px solid #dba617; border-radius: 4px; padding: 10px; margin: 12px 0; }
            @media print { body { padding: 0; } }
        </style>';
        echo '</head><body>';

        echo '<h1>' . esc_html(sprintf(__('Competitive Analysis: %s', 'product-research'), $title)) . '</h1>';
        echo '<p>' . esc_html(sprintf(__('Generated: %s', 'product-research'), wp_date('F j, Y g:i A'))) . '</p>';

        // Summary
        if (! empty($summary)) {
            echo '<div class="summary">';
            echo '<div class="summary-card"><div class="label">' . esc_html__('Competitors', 'product-research') . '</div><div class="value">' . intval($summary['total_competitors'] ?? 0) . '</div></div>';
            echo '<div class="summary-card"><div class="label">' . esc_html__('Price Range', 'product-research') . '</div><div class="value">' . esc_html(($summary['lowest_price'] ?? 0) . ' – ' . ($summary['highest_price'] ?? 0)) . '</div></div>';
            echo '<div class="summary-card"><div class="label">' . esc_html__('Avg Price', 'product-research') . '</div><div class="value">' . esc_html((string) ($summary['avg_price'] ?? 0)) . '</div></div>';
            echo '</div>';

            if (! empty($summary['key_findings'])) {
                echo '<div class="findings"><strong>' . esc_html__('Key Findings', 'product-research') . '</strong><ul>';
                foreach ($summary['key_findings'] as $finding) {
                    echo '<li>' . esc_html($finding) . '</li>';
                }
                echo '</ul></div>';
            }
        }

        // Competitors table
        if (! empty($competitors)) {
            echo '<h2>' . esc_html__('Competitor Details', 'product-research') . '</h2>';
            echo '<table><thead><tr><th>' . esc_html__('Product', 'product-research') . '</th><th>' . esc_html__('Price', 'product-research') . '</th><th>' . esc_html__('Availability', 'product-research') . '</th><th>' . esc_html__('Rating', 'product-research') . '</th><th>' . esc_html__('Seller', 'product-research') . '</th></tr></thead><tbody>';
            foreach ($competitors as $comp) {
                echo '<tr>';
                echo '<td>' . esc_html($comp['name'] ?? '') . '</td>';
                echo '<td>' . esc_html(($comp['currency'] ?? '') . ' ' . ($comp['current_price'] ?? '')) . '</td>';
                echo '<td>' . esc_html($comp['availability'] ?? '—') . '</td>';
                echo '<td>' . esc_html(($comp['rating'] ?? '—') . (isset($comp['rating']) ? '/5' : '')) . '</td>';
                echo '<td>' . esc_html($comp['seller_name'] ?? '—') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<script>window.print();</script>';
        echo '</body></html>';
        exit;
    }

    /**
     * Verify nonce and capability.
     */
    private function verifyRequest(): void
    {
        if (! check_ajax_referer('pr_research_nonce', 'nonce', false)) {
            wp_die(esc_html__('Security check failed.', 'product-research'));
        }

        $capability = apply_filters('pr_required_capability', get_option('pr_capability', 'edit_products'));

        if (! current_user_can($capability)) {
            wp_die(esc_html__('Insufficient permissions.', 'product-research'));
        }
    }
}
