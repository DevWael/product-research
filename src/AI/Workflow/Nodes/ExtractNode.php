<?php

declare(strict_types=1);

namespace ProductResearch\AI\Workflow\Nodes;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use ProductResearch\AI\Workflow\Events\ExtractionCompletedEvent;
use ProductResearch\AI\Workflow\Events\SearchCompletedEvent;
use ProductResearch\API\ContentSanitizer;
use ProductResearch\API\TavilyClient;
use ProductResearch\Cache\CacheManager;
use ProductResearch\Report\ReportPostType;
use ProductResearch\Report\ReportRepository;
use ProductResearch\Security\Logger;

/**
 * Extract node: retrieves and sanitizes content from selected competitor URLs.
 *
 * Takes admin-filtered URLs, calls Tavily Extract, sanitizes content,
 * and emits clean data ready for AI analysis.
 */
final class ExtractNode extends Node
{
    private TavilyClient $tavily;
    private ContentSanitizer $sanitizer;
    private CacheManager $cache;
    private ReportRepository $reports;
    private Logger $logger;

    public function __construct(
        TavilyClient $tavily,
        ContentSanitizer $sanitizer,
        CacheManager $cache,
        ReportRepository $reports,
        Logger $logger
    ) {
        $this->tavily    = $tavily;
        $this->sanitizer = $sanitizer;
        $this->cache     = $cache;
        $this->reports   = $reports;
        $this->logger    = $logger;
    }

    public function __invoke(SearchCompletedEvent $event, WorkflowState $state): ExtractionCompletedEvent
    {
        $reportId    = $state->get('report_id');
        $selectedUrls = $state->get('selected_urls', $event->urls);
        $maxCompetitors = (int) get_option('pr_max_competitors', 5);

        // Limit to max competitors setting
        $urls = array_slice($selectedUrls, 0, $maxCompetitors);

        $this->reports->updateStatus(
            $reportId,
            ReportPostType::STATUS_EXTRACTING,
            sprintf(__('Extracting content from %d competitor sites...', 'product-research'), count($urls))
        );

        try {
            $response = $this->tavily->extract($urls);
        } catch (\Throwable $e) {
            $this->logger->log(sprintf('Extraction failed: %s', $e->getMessage()));
            throw $e;
        }

        $extracted  = [];
        $failedUrls = [];

        // Process successful extractions
        foreach ($response['results'] ?? [] as $result) {
            $url     = $result['url'] ?? '';
            $content = $result['raw_content'] ?? '';
            $images  = $result['images'] ?? [];

            if ($content === '') {
                $failedUrls[] = $url;
                continue;
            }

            $sanitized = $this->sanitizer->sanitize($content);

            $extracted[] = [
                'url'     => $url,
                'content' => $sanitized,
                'images'  => $images,
            ];

            $this->reports->updateStatus(
                $reportId,
                ReportPostType::STATUS_EXTRACTING,
                sprintf(
                    __('Extracted %d/%d competitors...', 'product-research'),
                    count($extracted),
                    count($urls)
                )
            );
        }

        // Collect failed extractions
        foreach ($response['failed_results'] ?? [] as $failed) {
            $failedUrls[] = $failed['url'] ?? 'unknown';
        }

        if (! empty($failedUrls)) {
            $this->logger->log(
                sprintf('Failed to extract %d URLs: %s', count($failedUrls), implode(', ', $failedUrls)),
                'warning'
            );
        }

        // Store competitor data in report
        $this->reports->update($reportId, [
            ReportPostType::META_COMPETITOR_DATA => $extracted,
        ]);

        return new ExtractionCompletedEvent($extracted, $failedUrls);
    }
}
