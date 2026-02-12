<?php

declare(strict_types=1);

namespace ProductResearch\Report;

/**
 * CRUD service for report post type.
 *
 * Uses WP_Query internally. Supports session persistence
 * via getInProgress() for detecting non-complete reports.
 */
final class ReportRepository
{
    /**
     * Create a new report.
     *
     * @param array<string, mixed> $data
     */
    public function create(int $productId, array $data = []): int
    {
        $postId = wp_insert_post([
            'post_type'   => ReportPostType::POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => sprintf(
                'Report for Product #%d — %s',
                $productId,
                wp_date('Y-m-d H:i:s')
            ),
        ], true);

        if (is_wp_error($postId)) {
            return 0;
        }

        update_post_meta($postId, ReportPostType::META_PRODUCT_ID, $productId);
        update_post_meta($postId, ReportPostType::META_STATUS, ReportPostType::STATUS_PENDING);

        foreach ($data as $key => $value) {
            $this->setMeta($postId, $key, $value);
        }

        return $postId;
    }

    /**
     * Find all reports for a product, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByProduct(int $productId, int $limit = 10): array
    {
        $query = new \WP_Query([
            'post_type'      => ReportPostType::POST_TYPE,
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'   => ReportPostType::META_PRODUCT_ID,
                    'value' => $productId,
                    'type'  => 'NUMERIC',
                ],
            ],
        ]);

        return array_map([$this, 'formatReport'], $query->posts ?: []);
    }

    /**
     * Find a single report by ID.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $reportId): ?array
    {
        $post = get_post($reportId);

        if (! $post || $post->post_type !== ReportPostType::POST_TYPE) {
            return null;
        }

        return $this->formatReport($post);
    }

    /**
     * Update a report's meta data.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $reportId, array $data): void
    {
        foreach ($data as $key => $value) {
            $this->setMeta($reportId, $key, $value);
        }
    }

    /**
     * Get the most recent completed report for a product.
     *
     * @return array<string, mixed>|null
     */
    public function getLatest(int $productId): ?array
    {
        $query = new \WP_Query([
            'post_type'      => ReportPostType::POST_TYPE,
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'   => ReportPostType::META_PRODUCT_ID,
                    'value' => $productId,
                    'type'  => 'NUMERIC',
                ],
                [
                    'key'   => ReportPostType::META_STATUS,
                    'value' => ReportPostType::STATUS_COMPLETE,
                ],
            ],
        ]);

        $posts = $query->posts ?: [];

        return ! empty($posts) ? $this->formatReport($posts[0]) : null;
    }

    /**
     * Get any in-progress (non-complete, non-failed) report for a product.
     * Used for session persistence and concurrent request guards.
     *
     * @return array<string, mixed>|null
     */
    public function getInProgress(int $productId): ?array
    {
        $query = new \WP_Query([
            'post_type'      => ReportPostType::POST_TYPE,
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'   => ReportPostType::META_PRODUCT_ID,
                    'value' => $productId,
                    'type'  => 'NUMERIC',
                ],
                [
                    'key'     => ReportPostType::META_STATUS,
                    'value'   => [ReportPostType::STATUS_COMPLETE, ReportPostType::STATUS_FAILED],
                    'compare' => 'NOT IN',
                ],
            ],
        ]);

        $posts = $query->posts ?: [];

        return ! empty($posts) ? $this->formatReport($posts[0]) : null;
    }

    /**
     * Delete reports older than a given number of days for a product.
     */
    public function deleteOlderThan(int $days, int $productId = 0): int
    {
        $args = [
            'post_type'      => ReportPostType::POST_TYPE,
            'posts_per_page' => 50,
            'date_query'     => [
                ['before' => sprintf('%d days ago', $days)],
            ],
            'fields'         => 'ids',
        ];

        if ($productId > 0) {
            $args['meta_query'] = [
                [
                    'key'   => ReportPostType::META_PRODUCT_ID,
                    'value' => $productId,
                    'type'  => 'NUMERIC',
                ],
            ];
        }

        $query   = new \WP_Query($args);
        $deleted = 0;

        foreach ($query->posts ?: [] as $postId) {
            if (wp_delete_post((int) $postId, true)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Update report status and progress message.
     */
    public function updateStatus(int $reportId, string $status, string $message = ''): void
    {
        update_post_meta($reportId, ReportPostType::META_STATUS, $status);

        if ($message !== '') {
            update_post_meta($reportId, ReportPostType::META_PROGRESS_MESSAGE, $message);
        }
    }

    /**
     * Format a WP_Post into a report array.
     *
     * @return array<string, mixed>
     */
    private function formatReport(\WP_Post $post): array
    {
        return [
            'id'               => $post->ID,
            'product_id'       => (int) get_post_meta($post->ID, ReportPostType::META_PRODUCT_ID, true),
            'search_query'     => get_post_meta($post->ID, ReportPostType::META_SEARCH_QUERY, true),
            'competitor_data'  => $this->getJsonMeta($post->ID, ReportPostType::META_COMPETITOR_DATA),
            'analysis_result'  => $this->getJsonMeta($post->ID, ReportPostType::META_ANALYSIS_RESULT),
            'status'           => get_post_meta($post->ID, ReportPostType::META_STATUS, true) ?: ReportPostType::STATUS_PENDING,
            'progress_message' => get_post_meta($post->ID, ReportPostType::META_PROGRESS_MESSAGE, true),
            'selected_urls'    => $this->getJsonMeta($post->ID, ReportPostType::META_SELECTED_URLS),
            'error_details'    => $this->getJsonMeta($post->ID, ReportPostType::META_ERROR_DETAILS),
            'recommendations'  => $this->getRecommendations($post->ID),
            'created_at'       => $post->post_date,
        ];
    }

    /**
     * Get cached recommendations for a report.
     *
     * @return array<int, array<string, string>>
     */
    private function getRecommendations(int $postId): array
    {
        $recs = get_post_meta($postId, '_pr_recommendations', true);

        return is_array($recs) ? $recs : [];
    }

    /**
     * Get JSON-decoded meta value.
     *
     * @return mixed
     */
    private function getJsonMeta(int $postId, string $key): mixed
    {
        $raw = get_post_meta($postId, $key, true);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return json_decode($raw, true);
    }

    /**
     * Set a meta value, JSON-encoding arrays/objects.
     */
    private function setMeta(int $postId, string $key, mixed $value): void
    {
        if (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value);
        }

        // wp_slash() counteracts the wp_unslash() that update_post_meta
        // applies internally, preserving backslash-escaped characters in JSON.
        update_post_meta($postId, $key, is_string($value) ? wp_slash($value) : $value);
    }
}
