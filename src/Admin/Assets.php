<?php

declare(strict_types=1);

namespace ProductResearch\Admin;

/**
 * Asset management: enqueue JS and CSS only on WooCommerce product edit pages.
 */
final class Assets
{
    private string $pluginUrl;
    private string $pluginPath;

    public function __construct(string $pluginUrl, string $pluginPath)
    {
        $this->pluginUrl  = $pluginUrl;
        $this->pluginPath = $pluginPath;
    }

    /**
     * Enqueue admin assets on product edit screens.
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
