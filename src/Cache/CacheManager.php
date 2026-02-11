<?php

declare(strict_types=1);

namespace ProductResearch\Cache;

/**
 * Cache service wrapping WordPress Transients API.
 */
final class CacheManager
{
    private const PREFIX = 'pr_cache_';

    private int $ttlSeconds;

    public function __construct(int $ttlHours = 24)
    {
        $this->ttlSeconds = $ttlHours * HOUR_IN_SECONDS;
    }

    /**
     * Get cached data.
     *
     * @return mixed|false False if not found.
     */
    public function get(string $key): mixed
    {
        return get_transient(self::PREFIX . $key);
    }

    /**
     * Store data in cache.
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
     */
    public function delete(string $key): bool
    {
        return delete_transient(self::PREFIX . $key);
    }

    /**
     * Generate a cache key based on product ID and type.
     */
    public function generateKey(int $productId, string $type, string $extra = ''): string
    {
        $raw = sprintf('%d_%s_%s', $productId, $type, $extra);

        return md5($raw);
    }
}
