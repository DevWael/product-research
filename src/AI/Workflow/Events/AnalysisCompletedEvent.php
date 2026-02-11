<?php

declare(strict_types=1);

namespace ProductResearch\AI\Workflow\Events;

use NeuronAI\Workflow\Event;
use ProductResearch\AI\Schema\CompetitorProfile;

/**
 * Emitted after AI analysis of all competitors.
 * Carries validated CompetitorProfile objects.
 */
final class AnalysisCompletedEvent implements Event
{
    /** @var CompetitorProfile[] */
    public readonly array $competitorProfiles;

    /** @var array<string, mixed> */
    public readonly array $analysisReport;

    /**
     * @param CompetitorProfile[]  $competitorProfiles
     * @param array<string, mixed> $analysisReport
     */
    public function __construct(array $competitorProfiles, array $analysisReport = [])
    {
        $this->competitorProfiles = $competitorProfiles;
        $this->analysisReport     = $analysisReport;
    }
}
