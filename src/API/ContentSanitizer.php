<?php

declare(strict_types=1);

namespace ProductResearch\API;

/**
 * Sanitizes raw extracted HTML/text before AI analysis.
 *
 * Strips non-product content, extracts relevant sections,
 * and truncates to token budget to manage costs and context limits.
 *
 * @package ProductResearch\API
 * @since   1.0.0
 */
final class ContentSanitizer
{
    private int $defaultTokenBudget;

    /**
     * Create the sanitizer.
     *
     * @since 1.0.0
     *
     * @param int $defaultTokenBudget Approximate token ceiling (~4 chars/token).
     */
    public function __construct(int $defaultTokenBudget = 4000)
    {
        $this->defaultTokenBudget = $defaultTokenBudget;
    }

    /**
     * Full sanitization pipeline: clean → extract → truncate.
     *
     * @since 1.0.0
     *
     * @param  string   $rawContent  Raw HTML or text from a competitor page.
     * @param  int|null $tokenBudget Optional token budget override.
     * @return string   Cleaned, relevant, token-limited text.
     */
    public function sanitize(string $rawContent, ?int $tokenBudget = null): string
    {
        $budget = $tokenBudget ?? $this->defaultTokenBudget;

        $cleaned   = $this->stripNonContent($rawContent);
        $extracted = $this->extractProductSections($cleaned);
        $text      = $this->normalizeWhitespace($extracted);

        return $this->truncateToTokenBudget($text, $budget);
    }

    /**
     * Estimate token count (rough ~4 chars per token).
     *
     * @since 1.0.0
     *
     * @param  string $text Input text.
     * @return int    Estimated token count.
     */
    public function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Strip scripts, styles, nav, footer, and HTML tags.
     *
     * @since 1.0.0
     *
     * @param  string $html Raw HTML content.
     * @return string Plain text with non-content elements removed.
     */
    private function stripNonContent(string $html): string
    {
        // Remove script and style blocks entirely
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;

        // Remove nav, header, footer, aside elements
        $html = preg_replace('/<(nav|header|footer|aside)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;

        // Remove form elements
        $html = preg_replace('/<form\b[^>]*>.*?<\/form>/is', '', $html) ?? $html;

        // Remove HTML comments
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

        // Strip remaining HTML tags
        $text = wp_strip_all_tags($html);

        return $text;
    }

    /**
     * Extract product-relevant sections via keyword heuristics.
     *
     * Looks for text blocks containing pricing, variation,
     * description, and feature keywords. Returns surrounding context
     * lines for each match.
     *
     * @since 1.0.0
     *
     * @param  string $text Stripped plain text.
     * @return string Relevant lines joined with newlines.
     */
    public function extractProductSections(string $text): string
    {
        $keywords = [
            'price', 'cost', '$', '€', '£', '¥',
            'add to cart', 'buy now', 'in stock', 'out of stock',
            'size', 'color', 'colour', 'material', 'weight',
            'variation', 'option', 'select',
            'description', 'features', 'specifications', 'specs',
            'shipping', 'delivery', 'free shipping',
            'rating', 'review', 'stars',
            'availability', 'pre-order',
            'sku', 'model', 'brand',
        ];

        $lines    = explode("\n", $text);
        $relevant = [];
        $context  = 3; // Lines of context around matches

        foreach ($lines as $index => $line) {
            if ($this->lineContainsKeywords($line, $keywords)) {
                $start = max(0, $index - $context);
                $end   = min(count($lines) - 1, $index + $context);

                for ($i = $start; $i <= $end; $i++) {
                    $relevant[$i] = $lines[$i];
                }
            }
        }

        if (empty($relevant)) {
            return $text;
        }

        ksort($relevant);

        return implode("\n", $relevant);
    }

    /**
     * Check if a line contains any of the given keywords.
     *
     * @since 1.0.0
     *
     * @param  string          $line     Text line to check.
     * @param  array<string>   $keywords Keywords to search for (case-insensitive).
     * @return bool
     */
    private function lineContainsKeywords(string $line, array $keywords): bool
    {
        $lower = mb_strtolower($line);

        foreach ($keywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize excessive whitespace.
     *
     * Collapses multiple blank lines and spaces to single instances.
     *
     * @since 1.0.0
     *
     * @param  string $text Input text.
     * @return string Normalized text.
     */
    private function normalizeWhitespace(string $text): string
    {
        // Collapse multiple blank lines to single
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        // Collapse multiple spaces to single
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Truncate text to fit within token budget.
     *
     * @since 1.0.0
     *
     * @param  string $text        Input text.
     * @param  int    $tokenBudget Maximum token count.
     * @return string Truncated text (or original if within budget).
     */
    private function truncateToTokenBudget(string $text, int $tokenBudget): string
    {
        $maxChars = $tokenBudget * 4;

        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars);
    }
}
