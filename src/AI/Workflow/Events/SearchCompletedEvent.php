<?php

declare(strict_types=1);

namespace ProductResearch\AI\Workflow\Events;

use NeuronAI\Workflow\Event;

/**
 * Emitted after Tavily search completes.
 * Carries search results and extracted URLs for preview.
 *
 * @package ProductResearch\AI\Workflow\Events
 * @since   1.0.0
 */
final class SearchCompletedEvent implements Event
{
    /** @var array<int, array<string, mixed>> */
    public readonly array $searchResults;

    /** @var array<int, string> */
    public readonly array $urls;

    public readonly string $query;

    /**
     * Create the event.
     *
     * @since 1.0.0
     *
     * @param array<int, array<string, mixed>> $searchResults Raw Tavily search results.
     * @param array<int, string>               $urls          Extracted competitor URLs.
     * @param string                           $query         Search query used.
     */
    public function __construct(array $searchResults, array $urls, string $query)
    {
        $this->searchResults = $searchResults;
        $this->urls          = $urls;
        $this->query         = $query;
    }
}
