<?php

declare(strict_types=1);

namespace ProductResearch\API;

use ProductResearch\Security\Encryption;
use ProductResearch\Security\Logger;
use ProductResearch\Cache\CacheManager;

/**
 * HTTP client for Tavily Search and Extract APIs.
 *
 * Uses wp_remote_post() with retry + exponential backoff.
 *
 * @package ProductResearch\API
 * @since   1.0.0
 */
final class TavilyClient
{
    private const SEARCH_URL  = 'https://api.tavily.com/search';
    private const EXTRACT_URL = 'https://api.tavily.com/extract';
    private const MAX_RETRIES = 3;

    /** @var string[] Default domains to exclude from search results. */
    public const DEFAULT_EXCLUDE_DOMAINS = [
        'amazon.com',
        'ebay.com',
        'walmart.com',
        'aliexpress.com',
        'alibaba.com',
        'temu.com',
        'wish.com',
        'target.com',
        'bestbuy.com',
        'costco.com',
        'etsy.com',
        'facebook.com',
        'instagram.com',
        'twitter.com',
        'pinterest.com',
        'youtube.com',
        'reddit.com',
    ];

    private Encryption $encryption;
    private CacheManager $cache;
    private Logger $logger;

    /**
     * Create the Tavily client.
     *
     * @since 1.0.0
     *
     * @param Encryption   $encryption  AES-256 encryption for API key storage.
     * @param CacheManager $cache       WordPress-transient cache.
     * @param Logger       $logger      Sanitized error logging.
     */
    public function __construct(
        Encryption $encryption,
        CacheManager $cache,
        Logger $logger
    ) {
        $this->encryption = $encryption;
        $this->cache      = $cache;
        $this->logger     = $logger;
    }

    /**
     * Search for competitors via Tavily Search API.
     *
     * @since 1.0.0
     *
     * @param  string               $query   Natural-language search query.
     * @param  array<string, mixed>  $options Override default search parameters.
     * @return array<string, mixed>  Tavily API response body.
     *
     * @throws \RuntimeException On API failure or missing API key.
     */
    public function search(string $query, array $options = []): array
    {
        $apiKey = $this->getApiKey();

        $body = array_merge([
            'query'                 => $query,
            'search_depth'          => get_option('pr_tavily_search_depth', 'fast'),
            'max_results'           => (int) get_option('pr_max_search_results', 10),
            'include_images'        => (bool) get_option('pr_include_images', true),
            'include_raw_content'   => false,
            'include_answer'        => false,
        ], $options);

        return $this->request(self::SEARCH_URL, $body, $apiKey);
    }

    /**
     * Extract content from URLs via Tavily Extract API.
     *
     * Uses a generous 120-second timeout because advanced-depth
     * extraction can take up to 30 seconds per URL.
     *
     * @since 1.0.0
     *
     * @param  array<string>        $urls    URLs to extract content from.
     * @param  array<string, mixed>  $options Override default extract parameters.
     * @return array<string, mixed>  Tavily API response body.
     *
     * @throws \RuntimeException On API failure or missing API key.
     */
    public function extract(array $urls, array $options = []): array
    {
        $apiKey = $this->getApiKey();

        $body = array_merge([
            'urls'          => $urls,
            'extract_depth' => get_option('pr_tavily_extract_depth', 'advanced'),
        ], $options);

        // Extract requests can take much longer than search (30s per URL at
        // advanced depth).  Use a generous timeout so WordPress doesn't abort
        // the HTTP call before Tavily finishes.
        return $this->request(self::EXTRACT_URL, $body, $apiKey, 120);
    }

    /**
     * Track API credit usage for daily budget enforcement.
     *
     * Stores a running total in a transient keyed by today's date.
     *
     * @since 1.0.0
     *
     * @param int $credits Number of credits consumed by the request.
     * @return void
     */
    public function trackCredits(int $credits): void
    {
        $key     = 'pr_credits_' . wp_date('Y-m-d');
        $current = (int) get_transient($key);

        set_transient($key, $current + $credits, DAY_IN_SECONDS);
    }

    /**
     * Get total credits used today.
     *
     * @since 1.0.0
     *
     * @return int Credits consumed since midnight (UTC).
     */
    public function getCreditsUsedToday(): int
    {
        $key = 'pr_credits_' . wp_date('Y-m-d');

        return (int) get_transient($key);
    }

    /**
     * Make an HTTP request with retry and exponential backoff.
     *
     * Retries on WP errors, 429, and 5xx responses. Throws on
     * non-200 responses with parseable error detail and after
     * exhausting all retries.
     *
     * @since 1.0.0
     *
     * @param  string               $url     API endpoint URL.
     * @param  array<string, mixed>  $body    JSON-encodable request body.
     * @param  string               $apiKey  Decrypted Tavily API key.
     * @param  int                  $timeout HTTP timeout in seconds.
     * @return array<string, mixed>  Decoded JSON response.
     *
     * @throws \RuntimeException On failure after all retries.
     */
    private function request(string $url, array $body, string $apiKey, int $timeout = 30): array
    {
        $lastError = '';

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                $delay = (int) (pow(2, $attempt) * 1000000); // Exponential backoff in microseconds
                usleep($delay);
            }

            $response = wp_remote_post($url, [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                ],
                'body'    => wp_json_encode($body),
                'timeout' => $timeout,
            ]);

            if (is_wp_error($response)) {
                $lastError = $response->get_error_message();
                $this->logger->log(sprintf('Tavily API WP Error (attempt %d): %s', $attempt + 1, $lastError));
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);

            if ($code === 429) {
                $lastError = 'Rate limit exceeded';
                $this->logger->log(sprintf('Tavily API rate limited (attempt %d)', $attempt + 1), 'warning');
                continue;
            }

            if ($code >= 500) {
                $lastError = sprintf('Server error: HTTP %d', $code);
                $this->logger->log(sprintf('Tavily API server error (attempt %d): HTTP %d', $attempt + 1, $code));
                continue;
            }

            $responseBody = wp_remote_retrieve_body($response);
            $data         = json_decode($responseBody, true);

            if (! is_array($data)) {
                $lastError = 'Invalid JSON response';
                $this->logger->log(sprintf('Tavily API invalid JSON (attempt %d)', $attempt + 1));
                continue;
            }

            if ($code !== 200) {
                $errorMsg = $data['detail'] ?? $data['error'] ?? 'Unknown error';

                // Tavily returns {"detail": {"error": "..."}} for auth errors
                if (is_array($errorMsg)) {
                    $errorMsg = $errorMsg['error'] ?? $errorMsg['message'] ?? wp_json_encode($errorMsg);
                }

                throw new \RuntimeException(
                    sprintf('Tavily API error (HTTP %d): %s', $code, sanitize_text_field((string) $errorMsg))
                );
            }

            // Track credit usage if available
            if (isset($data['usage']['credits'])) {
                $this->trackCredits((int) $data['usage']['credits']);
            }

            return $data;
        }

        throw new \RuntimeException(
            sprintf('Tavily API failed after %d retries: %s', self::MAX_RETRIES, $lastError)
        );
    }

    /**
     * Get decrypted Tavily API key.
     *
     * @since 1.0.0
     *
     * @return string Plain-text API key.
     *
     * @throws \RuntimeException If no API key configured or decryption fails.
     */
    private function getApiKey(): string
    {
        $encrypted = get_option('pr_tavily_api_key', '');

        if ($encrypted === '') {
            throw new \RuntimeException(
                __('Tavily API key not configured. Please set it in Product Research settings.', 'product-research')
            );
        }

        $key = $this->encryption->decrypt($encrypted);

        if ($key === '') {
            throw new \RuntimeException(
                __('Failed to decrypt Tavily API key. Please re-enter it in settings.', 'product-research')
            );
        }

        return $key;
    }
}
