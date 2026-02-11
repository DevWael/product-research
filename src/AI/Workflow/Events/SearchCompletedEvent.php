<?php

declare(strict_types=1);

namespace ProductResearch\AI\Workflow\Events;

use NeuronAI\Workflow\Event;

/**
 * Emitted after Tavily search completes.
 * Carries search results and extracted URLs for preview.
 */
final class SearchCompletedEvent implements Event
{
    /** @var array<int, array<string, mixed>> */
    public readonly array $searchResults;

    /** @var array<int, string> */
    public readonly array $urls;

    public readonly string $query;

    /**
     * @param array<int, array<string, mixed>> $searchResults
     * @param array<int, string>               $urls
     */
    public function __construct(array $searchResults, array $urls, string $query)
    {
        $this->searchResults = $searchResults;
        $this->urls          = $urls;
        $this->query         = $query;
    }
}
