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
 *
 * @package ProductResearch\AI\Schema
 * @since   1.0.0
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

    #[SchemaProperty(description: 'Store or seller name', required: true)]
    #[NotBlank]
    public string $sellerName = '';

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

    // ─── Currency normalization (set at finalization, NOT part of AI schema) ──

    /** @var float|null Price converted to the store's base currency. */
    public ?float  $convertedPrice         = null;

    /** @var float|null Original price converted to the store's base currency. */
    public ?float  $convertedOriginalPrice = null;

    /** @var string|null The store's base currency code that prices were converted to. */
    public ?string $storeCurrency          = null;

    /** @var string|null Conversion status: 'converted', 'same_currency', 'failed', or 'skipped'. */
    public ?string $conversionStatus       = null;

    /**
     * Convert to array for JSON storage.
     *
     * Includes both AI-extracted fields and currency normalization fields
     * set during report finalization by {@see ReportNode}.
     *
     * @since 1.0.0
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
            'features'          => $this->features,
            'images'            => $this->images,
            'converted_price'          => $this->convertedPrice,
            'converted_original_price' => $this->convertedOriginalPrice,
            'store_currency'           => $this->storeCurrency,
            'conversion_status'        => $this->conversionStatus,
        ];
    }
}
