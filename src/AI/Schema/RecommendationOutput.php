<?php

declare(strict_types=1);

namespace ProductResearch\AI\Schema;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * Wrapper schema for the AI recommendation agent output.
 *
 * The agent returns exactly 3 recommendations.
 */
final class RecommendationOutput
{
    /** @var Recommendation[] */
    #[SchemaProperty(description: 'List of 3 strategic recommendations', required: true)]
    public array $recommendations = [];

    /**
     * Convert to array for JSON storage.
     *
     * @return array<int, array<string, string>>
     */
    public function toArray(): array
    {
        return array_map(
            static function (mixed $r): array {
                if ($r instanceof Recommendation) {
                    return $r->toArray();
                }
                return (array) $r;
            },
            $this->recommendations
        );
    }
}
