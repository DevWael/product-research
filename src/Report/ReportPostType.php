<?php

declare(strict_types=1);

namespace ProductResearch\Report;

/**
 * Custom Post Type registration for pr_report.
 *
 * Reports are internal (not public), storing competitive analysis
 * data linked to WooCommerce products.
 */
final class ReportPostType
{
    public const POST_TYPE = 'pr_report';

    // Meta keys.
    public const META_PRODUCT_ID       = '_pr_product_id';
    public const META_SEARCH_QUERY     = '_pr_search_query';
    public const META_COMPETITOR_DATA  = '_pr_competitor_data';
    public const META_ANALYSIS_RESULT  = '_pr_analysis_result';
    public const META_STATUS           = '_pr_status';
    public const META_PROGRESS_MESSAGE = '_pr_progress_message';
    public const META_SELECTED_URLS    = '_pr_selected_urls';
    public const META_EXTRACTED_CONTENT = '_pr_extracted_content';
    public const META_ERROR_DETAILS    = '_pr_error_details';

    // Status constants.
    public const STATUS_PENDING    = 'pending';
    public const STATUS_SEARCHING  = 'searching';
    public const STATUS_PREVIEWING = 'previewing';
    public const STATUS_EXTRACTING = 'extracting';
    public const STATUS_ANALYZING  = 'analyzing';
    public const STATUS_COMPLETE   = 'complete';
    public const STATUS_FAILED     = 'failed';

    /**
     * Register the report post type.
     */
    public static function register(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels'              => [
                'name'          => __('Product Research Reports', 'product-research'),
                'singular_name' => __('Report', 'product-research'),
            ],
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'supports'            => ['title'],
            'can_export'          => false,
            'exclude_from_search' => true,
        ]);

        self::registerMeta();
    }

    /**
     * Register post meta fields with proper types.
     * All structured data stored as JSON strings.
     */
    private static function registerMeta(): void
    {
        $stringMeta = [
            self::META_SEARCH_QUERY,
            self::META_COMPETITOR_DATA,
            self::META_ANALYSIS_RESULT,
            self::META_STATUS,
            self::META_PROGRESS_MESSAGE,
            self::META_SELECTED_URLS,
            self::META_ERROR_DETAILS,
        ];

        foreach ($stringMeta as $key) {
            register_post_meta(self::POST_TYPE, $key, [
                'type'              => 'string',
                'single'            => true,
                'sanitize_callback' => 'sanitize_text_field',
                'show_in_rest'      => false,
            ]);
        }

        register_post_meta(self::POST_TYPE, self::META_PRODUCT_ID, [
            'type'              => 'integer',
            'single'            => true,
            'sanitize_callback' => 'absint',
            'show_in_rest'      => false,
        ]);
    }
}
