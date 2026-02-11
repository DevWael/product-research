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
use ProductResearch\Report\ReportRepository;
use ProductResearch\Security\Logger;

/**
 * Product Research Workflow: Search → Extract → Analyze → Report.
 *
 * Orchestrates 4 Neuron AI workflow nodes with injected dependencies.
 */
final class ProductResearchWorkflow extends Workflow
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

    /**
     * Define the workflow node sequence.
     *
     * @return array<Node>
     */
    protected function nodes(): array
    {
        return [
            new SearchNode($this->tavily, $this->cache, $this->reports, $this->logger),
            new ExtractNode($this->tavily, $this->sanitizer, $this->cache, $this->reports, $this->logger),
            new AnalyzeNode($this->reports, $this->logger),
            new ReportNode($this->reports),
        ];
    }
}
