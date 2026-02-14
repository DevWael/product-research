<?php

declare(strict_types=1);

namespace ProductResearch\AI\Schema;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

/**
 * Structured output schema for a single product variation.
 *
 * Neuron AI validates LLM output against these typed properties
 * and validation attributes automatically, retrying on failure.
 *
 * @package ProductResearch\AI\Schema
 * @since   1.0.0
 */
final class ProductVariation
{
    #[SchemaProperty(description: 'Variation type: size, color, material, etc.', required: true)]
    #[NotBlank]
    public string $type = '';

    #[SchemaProperty(description: 'Variation value: e.g. XL, Red, Cotton', required: true)]
    #[NotBlank]
    public string $value = '';

    #[SchemaProperty(description: 'Variation-specific price if different from base', required: false)]
    public ?float $price = null;

    #[SchemaProperty(description: 'Variation availability status', required: false)]
    public ?string $availability = null;

    /**
     * Convert to array for JSON storage.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type'         => $this->type,
            'value'        => $this->value,
            'price'        => $this->price,
            'availability' => $this->availability,
        ];
    }
}
