<?php

declare(strict_types=1);

namespace ProductResearch;

use ProductResearch\Admin\Assets;
use ProductResearch\Admin\MetaBox;
use ProductResearch\Admin\ProductListColumns;
use ProductResearch\Admin\SettingsPage;
use ProductResearch\Ajax\ResearchHandler;
use ProductResearch\Export\ReportExporter;
use ProductResearch\Report\ReportPostType;

/**
 * Main plugin orchestrator.
 *
 * Hooks into WordPress lifecycle and delegates to domain classes.
 */
final class Plugin
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Initialize all plugin hooks.
     */
    public function init(): void
    {
        $this->registerPostType();
        $this->registerAdminHooks();
        $this->registerAjaxHandlers();
    }

    /**
     * Register the report custom post type.
     */
    private function registerPostType(): void
    {
        add_action('init', static function (): void {
            ReportPostType::register();
        });
    }

    /**
     * Register admin-only hooks: settings page, metabox, assets.
     */
    private function registerAdminHooks(): void
    {
        if (! is_admin()) {
            return;
        }

        add_action('admin_menu', function (): void {
            $this->container->get(SettingsPage::class)->registerMenu();
        });

        add_action('admin_init', function (): void {
            $this->container->get(SettingsPage::class)->registerSettings();
            $this->container->get(ProductListColumns::class)->register();
        });

        add_action('add_meta_boxes', function (): void {
            $this->container->get(MetaBox::class)->register();
        });

        add_action('admin_enqueue_scripts', function (string $hook): void {
            $this->container->get(Assets::class)->enqueue($hook);
            $this->container->get(Assets::class)->enqueueSettings($hook);
            $this->container->get(ProductListColumns::class)->enqueueAssets();
        });
    }

    /**
     * Register AJAX handlers for research workflow and export.
     */
    private function registerAjaxHandlers(): void
    {
        $ajaxActions = [
            'pr_start_research'      => 'handleStartResearch',
            'pr_confirm_urls'        => 'handleConfirmUrls',
            'pr_analyze_url'         => 'handleAnalyzeUrl',
            'pr_finalize_report'     => 'handleFinalizeReport',
            'pr_cancel_report'       => 'handleCancelReport',
            'pr_get_status'          => 'handleGetStatus',
            'pr_get_report'          => 'handleGetReport',
            'pr_get_recommendations' => 'handleGetRecommendations',
        ];

        foreach ($ajaxActions as $action => $method) {
            add_action("wp_ajax_{$action}", function () use ($method): void {
                $this->container->get(ResearchHandler::class)->{$method}();
            });
        }

        add_action('wp_ajax_pr_export_csv', function (): void {
            $this->container->get(ReportExporter::class)->handleCsvExport();
        });

        add_action('wp_ajax_pr_export_pdf', function (): void {
            $this->container->get(ReportExporter::class)->handlePdfExport();
        });
    }
}
