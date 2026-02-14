<?php

declare(strict_types=1);

namespace ProductResearch\Cache;

/**
 * Cache service wrapping WordPress Transients API.
 *
 * @package ProductResearch\Cache
 * @since   1.0.0
 */
final class CacheManager
{
    private const PREFIX = 'pr_cache_';

    private int $ttlSeconds;

    /**
     * Create the cache manager.
     *
     * @since 1.0.0
     *
     * @param int $ttlHours Default time-to-live in hours.
     */
    public function __construct(int $ttlHours = 24)
    {
        $this->ttlSeconds = $ttlHours * HOUR_IN_SECONDS;
    }

    /**
     * Get cached data.
     *
     * @since 1.0.0
     *
     * @param  string      $key Transient key (without prefix).
     * @return mixed|false  Cached data, or false if not found.
     */
    public function get(string $key): mixed
    {
        return get_transient(self::PREFIX . $key);
    }

    /**
     * Store data in cache.
     *
     * @since 1.0.0
     *
     * @param  string   $key  Transient key (without prefix).
     * @param  mixed    $data Data to cache (must be serializable).
     * @param  int|null $ttl  Optional TTL override in seconds.
     * @return bool     True on success.
     */
    public function set(string $key, mixed $data, ?int $ttl = null): bool
    {
        return set_transient(
            self::PREFIX . $key,
            $data,
            $ttl ?? $this->ttlSeconds
        );
    }

    /**
     * Delete cached data.
     *
     * @since 1.0.0
     *
     * @param  string $key Transient key (without prefix).
     * @return bool   True on success.
     */
    public function delete(string $key): bool
    {
        return delete_transient(self::PREFIX . $key);
    }

    /**
     * Generate a cache key based on product ID and type.
     *
     * @since 1.0.0
     *
     * @param  int    $productId WooCommerce product ID.
     * @param  string $type      Cache category (e.g. 'search', 'extract').
     * @param  string $extra     Additional discriminator (e.g. query string).
     * @return string MD5 hash suitable as a transient key.
     */
    public function generateKey(int $productId, string $type, string $extra = ''): string
    {
        $raw = sprintf('%d_%s_%s', $productId, $type, $extra);

        return md5($raw);
    }
}
