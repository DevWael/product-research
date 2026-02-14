<?php

declare(strict_types=1);

namespace ProductResearch\AI\Workflow;

use NeuronAI\Workflow\Workflow;
use ProductResearch\AI\Workflow\Nodes\AnalyzeNode;
use ProductResearch\AI\Workflow\Nodes\ExtractNode;
use ProductResearch\AI\Workflow\Nodes\ReportNode;
use ProductResearch\AI\Workflow\Nodes\SearchNode;
use ProductResearch\API\ContentSanitizer;
use ProductResearch\API\TavilyClient;
use ProductResearch\Cache\CacheManager;
use ProductResearch\Currency\CurrencyConverter;
use ProductResearch\Report\ReportRepository;
use ProductResearch\Security\Logger;

/**
 * Product Research Workflow: Search → Extract → Analyze → Report.
 *
 * Orchestrates 4 Neuron AI workflow nodes with injected dependencies.
 * Each node communicates via typed events emitted along the pipeline.
 *
 * @package ProductResearch\AI\Workflow
 * @since   1.0.0
 */
final class ProductResearchWorkflow extends Workflow
{
    private TavilyClient $tavily;
    private ContentSanitizer $sanitizer;
    private CacheManager $cache;
    private ReportRepository $reports;
    private Logger $logger;
    private CurrencyConverter $converter;

    /**
     * Create the workflow with all required service dependencies.
     *
     * @since 1.0.0
     *
     * @param TavilyClient      $tavily    HTTP client for Tavily Search & Extract APIs.
     * @param ContentSanitizer  $sanitizer Cleans raw HTML/text before AI analysis.
     * @param CacheManager      $cache     Transient-based cache for API responses.
     * @param ReportRepository  $reports   Persistence layer for report CPT.
     * @param Logger            $logger    Sanitized error logging.
     * @param CurrencyConverter $converter Normalizes prices to the store's base currency.
     */
    public function __construct(
        TavilyClient $tavily,
        ContentSanitizer $sanitizer,
        CacheManager $cache,
        ReportRepository $reports,
        Logger $logger,
        CurrencyConverter $converter
    ) {
        $this->tavily    = $tavily;
        $this->sanitizer = $sanitizer;
        $this->cache     = $cache;
        $this->reports   = $reports;
        $this->logger    = $logger;
        $this->converter = $converter;
    }

    /**
     * Define the workflow node sequence.
     *
     * Returns nodes in execution order: Search → Extract → Analyze → Report.
     * Each node receives its required dependencies at construction time.
     *
     * @since 1.0.0
     *
     * @return array<Node> Ordered list of workflow nodes.
     */
    protected function nodes(): array
    {
        return [
            new SearchNode($this->tavily, $this->cache, $this->reports, $this->logger),
            new ExtractNode($this->tavily, $this->sanitizer, $this->cache, $this->reports, $this->logger),
            new AnalyzeNode($this->reports, $this->logger),
            new ReportNode($this->reports, $this->converter),
        ];
    }
}
