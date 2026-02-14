<?php

declare(strict_types=1);

namespace ProductResearch\AI\Workflow\Nodes;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StartEvent;
use NeuronAI\Workflow\WorkflowState;
use ProductResearch\AI\Workflow\Events\SearchCompletedEvent;
use ProductResearch\API\TavilyClient;
use ProductResearch\Cache\CacheManager;
use ProductResearch\Report\ReportPostType;
use ProductResearch\Report\ReportRepository;
use ProductResearch\Security\Logger;

/**
 * Search node: finds competitor products via Tavily Search API.
 *
 * Constructs a search query from product data, executes the search,
 * caches results, and emits URLs for admin preview.
 *
 * @package ProductResearch\AI\Workflow\Nodes
 * @since   1.0.0
 */
final class SearchNode extends Node
{
    private TavilyClient $tavily;
    private CacheManager $cache;
    private ReportRepository $reports;
    private Logger $logger;

    /**
     * Create the search node with required dependencies.
     *
     * @since 1.0.0
     *
     * @param TavilyClient     $tavily  Tavily API client for web search.
     * @param CacheManager     $cache   Transient-based cache.
     * @param ReportRepository $reports Report persistence layer.
     * @param Logger           $logger  Sanitized error logging.
     */
    public function __construct(
        TavilyClient $tavily,
        CacheManager $cache,
        ReportRepository $reports,
        Logger $logger
    ) {
        $this->tavily  = $tavily;
        $this->cache   = $cache;
        $this->reports = $reports;
        $this->logger  = $logger;
    }

    /**
     * Execute the search step of the workflow.
     *
     * Builds a query from product state, checks the cache, calls Tavily,
     * persists successful results, and emits a {@see SearchCompletedEvent}.
     *
     * @since 1.0.0
     *
     * @param  StartEvent    $event Trigger event from the workflow engine.
     * @param  WorkflowState $state Shared state carrying product_id, report_id, etc.
     * @return SearchCompletedEvent
     *
     * @throws \Throwable If the Tavily search API call fails.
     */
    public function __invoke(StartEvent $event, WorkflowState $state): SearchCompletedEvent
    {
        $reportId  = $state->get('report_id');
        $productId = $state->get('product_id');

        $this->reports->updateStatus(
            $reportId,
            ReportPostType::STATUS_SEARCHING,
            __('Searching for competitors...', 'product-research')
        );

        $query    = $this->buildSearchQuery($state);
        $cacheKey = $this->cache->generateKey($productId, 'search', $query);
        $cached   = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $this->buildEvent($cached, $query);
        }

        try {
            $results = $this->tavily->search($query);
            $this->cache->set($cacheKey, $results);
        } catch (\Throwable $e) {
            $this->logger->log(sprintf('Search failed for product %d: %s', $productId, $e->getMessage()));
            throw $e;
        }

        // Save search query to report
        $this->reports->update($reportId, [
            ReportPostType::META_SEARCH_QUERY => $query,
        ]);

        return $this->buildEvent($results, $query);
    }

    /**
     * Build the search query from product state data.
     *
     * Combines product title, category, and brand into a search-optimised
     * query string with the suffix "price buy".
     *
     * @since 1.0.0
     *
     * @param  WorkflowState $state Shared workflow state.
     * @return string        The constructed search query.
     */
    private function buildSearchQuery(WorkflowState $state): string
    {
        $parts = [];

        $title = $state->get('product_title', '');
        if ($title !== '') {
            $parts[] = sprintf('"%s"', $title);
        }

        $category = $state->get('product_category', '');
        if ($category !== '') {
            $parts[] = $category;
        }

        $brand = $state->get('product_brand', '');
        if ($brand !== '') {
            $parts[] = $brand;
        }

        $parts[] = 'price buy';

        return implode(' ', $parts);
    }

    /**
     * Build a SearchCompletedEvent from Tavily response.
     *
     * Extracts non-empty URLs from the search results array.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $results Raw Tavily search response.
     * @param  string               $query   The search query used.
     * @return SearchCompletedEvent
     */
    private function buildEvent(array $results, string $query): SearchCompletedEvent
    {
        $searchResults = $results['results'] ?? [];
        $urls          = array_map(
            static fn(array $r): string => $r['url'] ?? '',
            $searchResults
        );
        $urls = array_values(array_filter($urls));

        return new SearchCompletedEvent($searchResults, $urls, $query);
    }
}
