<?php

declare(strict_types=1);

namespace ProductResearch\Admin;

/**
 * Adds a "Competitors" column to the WooCommerce product list table
 * showing the competitor count and price position badge.
 *
 * @package ProductResearch\Admin
 * @since   1.0.0
 */
class ProductListColumns
{
    private bool $registered = false;

    /**
     * Register hooks for product list columns.
     *
     * Guards against duplicate registration via an internal flag.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;
        add_filter('manage_edit-product_columns', [$this, 'addColumn']);
        add_action('manage_product_posts_custom_column', [$this, 'renderColumn'], 10, 2);
    }

    /**
     * Insert the "Competitors" column after the "Price" column.
     *
     * Falls back to appending at the end if the "price" column
     * is not present in the column list.
     *
     * @since 1.0.0
     *
     * @param  array<string, string> $columns Existing column key => label map.
     * @return array<string, string> Modified column map.
     */
    public function addColumn(array $columns): array
    {
        $result = [];

        foreach ($columns as $key => $label) {
            $result[$key] = $label;

            if ($key === 'price') {
                $result['pr_competitors'] = __('Competitors', 'product-research');
            }
        }

        // Fallback: if "price" column wasn't found, append at end.
        if (!isset($result['pr_competitors'])) {
            $result['pr_competitors'] = __('Competitors', 'product-research');
        }

        return $result;
    }

    /**
     * Render the badge for the "Competitors" column.
     *
     * Outputs a colored badge with competitor count and price-position
     * indicator (below/at/above average).
     *
     * @since 1.0.0
     *
     * @param  string $column Column key.
     * @param  int    $postId Product post ID.
     * @return void
     */
    public function renderColumn(string $column, int $postId): void
    {
        if ($column !== 'pr_competitors') {
            return;
        }

        $badge = get_post_meta($postId, '_pr_badge_data', true);

        if (!is_array($badge) || empty($badge['competitor_count'])) {
            echo '<span class="pr-badge pr-badge--none">—</span>';
            return;
        }

        $count    = (int) $badge['competitor_count'];
        $position = $badge['price_position'] ?? 'unknown';

        $positionClass = match ($position) {
            'below'   => 'pr-badge--below',
            'at'      => 'pr-badge--at',
            'above'   => 'pr-badge--above',
            default   => 'pr-badge--unknown',
        };

        $positionLabel = match ($position) {
            'below'   => __('Below avg', 'product-research'),
            'at'      => __('At avg', 'product-research'),
            'above'   => __('Above avg', 'product-research'),
            default   => '',
        };

        printf(
            '<span class="pr-badge %s" title="%s">%d <span class="pr-badge__dot"></span></span>',
            esc_attr($positionClass),
            esc_attr($positionLabel),
            $count
        );
    }

    /**
     * Enqueue badge styles on product list screens.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function enqueueAssets(): void
    {
        $screen = get_current_screen();

        if (!$screen || $screen->id !== 'edit-product') {
            return;
        }

        wp_enqueue_style(
            'pr-badge',
            plugins_url('assets/css/badge.css', dirname(__DIR__)),
            [],
            '1.0.0'
        );
    }
}
