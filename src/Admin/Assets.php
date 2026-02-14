<?php

declare(strict_types=1);

namespace ProductResearch\Admin;

/**
 * Asset management: enqueue JS and CSS only on WooCommerce product edit pages.
 *
 * @package ProductResearch\Admin
 * @since   1.0.0
 */
final class Assets
{
    private string $pluginUrl;
    private string $pluginPath;

    /**
     * Create the asset manager.
     *
     * @since 1.0.0
     *
     * @param string $pluginUrl  Plugin directory URL (trailing slash).
     * @param string $pluginPath Plugin directory filesystem path (trailing slash).
     */
    public function __construct(string $pluginUrl, string $pluginPath)
    {
        $this->pluginUrl  = $pluginUrl;
        $this->pluginPath = $pluginPath;
    }

    /**
     * Enqueue admin assets on product edit screens.
     *
     * Registers Chart.js from CDN and enqueues the metabox JS/CSS bundle.
     *
     * @since 1.0.0
     *
     * @param  string $hook Current admin page hook suffix.
     * @return void
     */
    public function enqueue(string $hook): void
    {
        if (! $this->isProductEditScreen($hook)) {
            return;
        }

        $version = $this->getVersion();

        wp_enqueue_style(
            'pr-metabox',
            $this->pluginUrl . 'assets/css/metabox.css',
            [],
            $version
        );

        wp_register_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4',
            [],
            '4.4.7',
            true
        );

        wp_enqueue_script(
            'pr-metabox',
            $this->pluginUrl . 'assets/js/metabox.js',
            ['jquery', 'chartjs'],
            $version,
            true
        );
    }

    /**
     * Enqueue settings page assets.
     *
     * @since 1.0.0
     *
     * @param  string $hook Current admin page hook suffix.
     * @return void
     */
    public function enqueueSettings(string $hook): void
    {
        if ($hook !== 'woocommerce_page_pr-settings') {
            return;
        }

        wp_enqueue_style(
            'pr-settings',
            $this->pluginUrl . 'assets/css/settings.css',
            [],
            $this->getVersion()
        );
    }

    /**
     * Check if current screen is a WooCommerce product edit page.
     *
     * @since 1.0.0
     *
     * @param  string $hook Current admin page hook suffix.
     * @return bool   True if on a product post.php or post-new.php screen.
     */
    private function isProductEditScreen(string $hook): bool
    {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return false;
        }

        $screen = get_current_screen();

        return $screen !== null && $screen->post_type === 'product';
    }

    /**
     * Get version string for cache busting.
     *
     * Reads the plugin header to obtain the current version.
     *
     * @since 1.0.0
     *
     * @return string Plugin version, or '1.0.0' as fallback.
     */
    private function getVersion(): string
    {
        $file = $this->pluginPath . 'product-research.php';

        if (file_exists($file)) {
            $data = get_plugin_data($file, false, false);
            return $data['Version'] ?? '1.0.0';
        }

        return '1.0.0';
    }
}
