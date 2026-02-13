<?php

declare(strict_types=1);

namespace ProductResearch\Ajax;

use ProductResearch\Report\ReportRepository;

/**
 * AJAX handler for competitor bookmarking.
 *
 * Stores bookmarked competitor URLs as product meta for
 * persistence across research sessions.
 */
final class BookmarkHandler
{
    private ReportRepository $reports;

    public function __construct(ReportRepository $reports)
    {
        $this->reports = $reports;
    }

    /**
     * Add a competitor URL to the product's bookmark list.
     */
    public function handleAddBookmark(): void
    {
        $this->verifyRequest();

        $productId = $this->getProductId();
        $url       = esc_url_raw(wp_unslash($_POST['url'] ?? ''));

        if ($url === '') {
            wp_send_json_error(['message' => __('Invalid URL.', 'product-research')], 400);
        }

        $bookmarks = get_post_meta($productId, '_pr_bookmarked_urls', true);
        $bookmarks = is_array($bookmarks) ? $bookmarks : [];

        if (! in_array($url, $bookmarks, true)) {
            $bookmarks[] = $url;
            update_post_meta($productId, '_pr_bookmarked_urls', $bookmarks);
        }

        wp_send_json_success(['bookmarked' => true, 'url' => $url]);
    }

    /**
     * Remove a competitor URL from the product's bookmark list.
     */
    public function handleRemoveBookmark(): void
    {
        $this->verifyRequest();

        $productId = $this->getProductId();
        $url       = esc_url_raw(wp_unslash($_POST['url'] ?? ''));

        $bookmarks = get_post_meta($productId, '_pr_bookmarked_urls', true);
        $bookmarks = is_array($bookmarks) ? $bookmarks : [];

        $bookmarks = array_values(array_filter(
            $bookmarks,
            static fn(string $bm): bool => $bm !== $url
        ));

        update_post_meta($productId, '_pr_bookmarked_urls', $bookmarks);

        wp_send_json_success(['bookmarked' => false, 'url' => $url]);
    }

    /**
     * Verify nonce and capabilities.
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

    /**
     * Get and validate product ID from the request.
     */
    private function getProductId(): int
    {
        $productId = absint($_POST['product_id'] ?? 0);

        if ($productId === 0 || get_post_type($productId) !== 'product') {
            wp_send_json_error(['message' => __('Invalid product.', 'product-research')], 400);
        }

        return $productId;
    }
}
