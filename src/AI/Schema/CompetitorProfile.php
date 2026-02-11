<?php

declare(strict_types=1);

namespace ProductResearch\AI\Schema;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

/**
 * Structured output schema for a competitor product profile.
 *
 * Neuron AI generates JSON schema from these PHP attributes,
 * validates the LLM response, and retries up to 3 times
 * if validation fails. Replaces manual JSON parsing entirely.
 */
final class CompetitorProfile
{
    #[SchemaProperty(description: 'Product name', required: true)]
    #[NotBlank]
    public string $name = '';

    #[SchemaProperty(description: 'Current or sale price', required: false)]
    public ?float $currentPrice = null;

    #[SchemaProperty(description: 'Original price before discount', required: false)]
    public ?float $originalPrice = null;

    #[SchemaProperty(description: 'Currency code e.g. USD, EUR, GBP', required: true)]
    #[NotBlank]
    public string $currency = '';

    #[SchemaProperty(description: 'Source product URL', required: false)]
    public ?string $url = null;

    #[SchemaProperty(description: 'Availability: In stock, Out of stock, or Pre-order', required: false)]
    public ?string $availability = null;

    #[SchemaProperty(description: 'Shipping details and costs', required: false)]
    public ?string $shippingInfo = null;

    #[SchemaProperty(description: 'Store or seller name', required: false)]
    public ?string $sellerName = null;

    #[SchemaProperty(description: 'Product rating from 0 to 5', required: false)]
    public ?float $rating = null;

    /** @var ProductVariation[] */
    #[SchemaProperty(description: 'Product variations (size, color, etc.)', required: false)]
    public array $variations = [];

    /** @var string[] */
    #[SchemaProperty(description: 'Key product features and highlights', required: false)]
    public array $features = [];

    /** @var string[] */
    #[SchemaProperty(description: 'Product image URLs', required: false)]
    public array $images = [];

    /**
     * Convert to array for JSON storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'          => $this->name,
            'current_price' => $this->currentPrice,
            'original_price'=> $this->originalPrice,
            'currency'      => $this->currency,
            'url'           => $this->url,
            'availability'  => $this->availability,
            'shipping_info' => $this->shippingInfo,
            'seller_name'   => $this->sellerName,
            'rating'        => $this->rating,
            'variations'    => array_map(
                static function (mixed $v): array {
                    if ($v instanceof ProductVariation) {
                        return $v->toArray();
                    }
                    return (array) $v;
                },
                $this->variations
            ),
            'features'      => $this->features,
            'images'        => $this->images,
        ];
    }
}
