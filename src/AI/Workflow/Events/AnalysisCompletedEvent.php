<?php

declare(strict_types=1);

namespace ProductResearch\AI\Workflow\Events;

use NeuronAI\Workflow\Event;
use ProductResearch\AI\Schema\CompetitorProfile;

/**
 * Emitted after AI analysis of all competitors.
 * Carries validated CompetitorProfile objects.
 *
 * @package ProductResearch\AI\Workflow\Events
 * @since   1.0.0
 */
final class AnalysisCompletedEvent implements Event
{
    /** @var CompetitorProfile[] */
    public readonly array $competitorProfiles;

    /** @var array<string, mixed> */
    public readonly array $analysisReport;

    /**
     * Create the event.
     *
     * @since 1.0.0
     *
     * @param CompetitorProfile[]  $competitorProfiles Validated competitor profiles.
     * @param array<string, mixed> $analysisReport     Optional raw analysis data.
     */
    public function __construct(array $competitorProfiles, array $analysisReport = [])
    {
        $this->competitorProfiles = $competitorProfiles;
        $this->analysisReport     = $analysisReport;
    }
}
