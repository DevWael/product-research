<?php

declare(strict_types=1);

namespace ProductResearch\AI\Schema;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

/**
 * Structured output schema for AI-generated product copy.
 *
 * Neuron AI generates JSON schema from these PHP attributes,
 * validates the LLM response, and retries up to 3 times
 * if validation fails.
 */
final class CopywriterOutput
{
    #[SchemaProperty(description: 'SEO-optimized product title', required: true)]
    #[NotBlank]
    public string $title = '';

    #[SchemaProperty(description: 'Short product description for excerpts (1-2 sentences)', required: true)]
    #[NotBlank]
    public string $shortDescription = '';

    #[SchemaProperty(description: 'Full product description with HTML formatting (paragraphs, bullet lists)', required: true)]
    #[NotBlank]
    public string $fullDescription = '';

    /** @var string[] */
    #[SchemaProperty(description: 'SEO keywords for the product (3-8 keywords)', required: false)]
    public array $seoKeywords = [];

    /** @var string[] */
    #[SchemaProperty(description: 'Key competitive advantages extracted from competitor analysis', required: false)]
    public array $competitiveAdvantages = [];

    /**
     * Convert to array for JSON transport.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title'                  => $this->title,
            'short_description'      => $this->shortDescription,
            'full_description'       => $this->fullDescription,
            'seo_keywords'           => $this->seoKeywords,
            'competitive_advantages' => $this->competitiveAdvantages,
        ];
    }
}
