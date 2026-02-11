<?php

declare(strict_types=1);

namespace ProductResearch\AI\Workflow\Events;

use NeuronAI\Workflow\Event;

/**
 * Emitted after content extraction and sanitization.
 * Carries sanitized competitor content and failed URLs.
 */
final class ExtractionCompletedEvent implements Event
{
    /** @var array<int, array<string, mixed>> */
    public readonly array $extractedData;

    /** @var array<int, string> */
    public readonly array $failedUrls;

    /**
     * @param array<int, array<string, mixed>> $extractedData Each item: ['url' => ..., 'content' => ..., 'images' => ...]
     * @param array<int, string>               $failedUrls
     */
    public function __construct(array $extractedData, array $failedUrls = [])
    {
        $this->extractedData = $extractedData;
        $this->failedUrls    = $failedUrls;
    }
}
