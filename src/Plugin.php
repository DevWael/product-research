<?php

declare(strict_types=1);

namespace ProductResearch;

use ProductResearch\Admin\Assets;
use ProductResearch\Admin\MetaBox;
use ProductResearch\Admin\ProductListColumns;
use ProductResearch\Admin\SettingsPage;
use ProductResearch\Ajax\BookmarkHandler;
use ProductResearch\Ajax\CopywriterHandler;
use ProductResearch\Ajax\ResearchHandler;
use ProductResearch\Export\ReportExporter;
use ProductResearch\Report\ReportPostType;

/**
 * Main plugin orchestrator.
 *
 * Hooks into the WordPress lifecycle (init, admin_menu, admin_init,
 * add_meta_boxes, admin_enqueue_scripts, and wp_ajax_*) and delegates
 * work to domain classes managed by the {@see Container}.
 *
 * @package ProductResearch
 * @since   1.0.0
 */
final class Plugin
{
    /** @var Container The service container providing all plugin dependencies. */
    private Container $container;

    /**
     * Create the plugin orchestrator.
     *
     * @since 1.0.0
     *
     * @param Container $container Fully-wired service container.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Initialize all plugin hooks.
     *
     * Registers the custom post type, admin-side UI hooks, and all
     * AJAX handlers for the research workflow, export, bookmarks,
     * and copywriter features.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init(): void
    {
        $this->registerPostType();
        $this->registerAdminHooks();
        $this->registerAjaxHandlers();
    }

    /**
     * Register the report custom post type.
     *
     * Schedules {@see ReportPostType::register()} on the WordPress `init`
     * action so the `pr_report` CPT is available throughout the lifecycle.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function registerPostType(): void
    {
        add_action('init', static function (): void {
            ReportPostType::register();
        });
    }

    /**
     * Register admin-only hooks: settings page, metabox, assets.
     *
     * Hooks into `admin_menu`, `admin_init`, `add_meta_boxes`, and
     * `admin_enqueue_scripts` to set up the settings page, product
     * list columns, the research metabox, and all CSS/JS assets.
     * Exits early on front-end requests.
     *
     * @since 1.0.0
     *
     * @return void
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
     * Register AJAX handlers for research workflow, export, bookmarks, and copywriter.
     *
     * Maps `wp_ajax_*` actions to their corresponding handler methods.
     * Research and report actions are routed to {@see ResearchHandler},
     * export actions to {@see ReportExporter}, bookmark actions to
     * {@see BookmarkHandler}, and the copywriter action to {@see CopywriterHandler}.
     *
     * @since 1.0.0
     *
     * @return void
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
            'pr_delete_report'       => 'handleDeleteReport',
            'pr_delete_reports'      => 'handleDeleteReports',
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

        // Bookmark handlers
        add_action('wp_ajax_pr_add_bookmark', function (): void {
            $this->container->get(BookmarkHandler::class)->handleAddBookmark();
        });

        add_action('wp_ajax_pr_remove_bookmark', function (): void {
            $this->container->get(BookmarkHandler::class)->handleRemoveBookmark();
        });

        // Copywriter handler
        add_action('wp_ajax_pr_generate_copy', function (): void {
            $this->container->get(CopywriterHandler::class)->handleGenerateCopy();
        });
    }
}
