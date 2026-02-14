<?php

declare(strict_types=1);

namespace ProductResearch\Currency;

/**
 * Currency conversion service using frankfurter.app API.
 *
 * Normalizes competitor prices to the WooCommerce store currency
 * at report finalization time. Caches exchange rates via WordPress
 * transients (24h TTL) with a merge strategy to preserve rates
 * from concurrent requests.
 *
 * @package ProductResearch\Currency
 * @since   1.0.0
 */
final class CurrencyConverter
{
    private string $storeCurrency;

    /**
     * Create the converter.
     *
     * @since 1.0.0
     *
     * @param string $storeCurrency ISO 4217 store currency code (e.g. 'USD').
     */
    public function __construct(string $storeCurrency)
    {
        $this->storeCurrency = $storeCurrency;
    }

    /**
     * Return the injected store currency.
     *
     * @since 1.0.0
     *
     * @return string ISO 4217 currency code.
     */
    public function getStoreCurrency(): string
    {
        return $this->storeCurrency;
    }

    /**
     * Extract valid converted prices from array-based profiles.
     *
     * Filters out profiles with failed conversion status and zero/null prices.
     * Use this instead of duplicating the filter logic inline.
     *
     * @since 1.0.0
     *
     * @param  array[] $profiles Array of competitor profile arrays.
     * @return float[] Filtered positive prices.
     */
    public static function extractValidPricesFromArrays(array $profiles): array
    {
        return array_filter(array_map(static function (array $p): ?float {
            if (($p['conversion_status'] ?? '') === 'failed') {
                return null;
            }
            $price = $p['converted_price'] ?? $p['current_price'] ?? null;
            return ($price !== null && $price > 0) ? (float) $price : null;
        }, $profiles));
    }

    /**
     * Extract valid converted prices from CompetitorProfile objects.
     *
     * @since 1.0.0
     *
     * @param  object[] $profiles Array of CompetitorProfile objects.
     * @return float[] Filtered positive prices.
     */
    public static function extractValidPricesFromObjects(array $profiles): array
    {
        return array_filter(array_map(static function (object $p): ?float {
            if (($p->conversionStatus ?? null) === 'failed') {
                return null;
            }
            $price = $p->convertedPrice ?? $p->currentPrice ?? null;
            return ($price !== null && $price > 0) ? (float) $price : null;
        }, $profiles));
    }

    /**
     * Fetch exchange rates from frankfurter.app in a single batch call.
     *
     * Uses the store currency as the base (`from`) and fetches rates
     * for all required target currencies in one HTTP request.
     *
     * Results are merged into the existing transient cache so that
     * concurrent report finalizations don't overwrite each other's data.
     *
     * @since 1.0.0
     *
     * @param  string   $from         Base currency ISO code.
     * @param  string[] $toCurrencies Target currency ISO codes.
     * @return array<string, float>   Map of currency => rate. Empty on failure.
     */
    public function getRates(string $from, array $toCurrencies): array
    {
        if (empty($toCurrencies)) {
            return [];
        }

        $cacheKey = 'pr_fx_rates_' . $from;
        $existing = get_transient($cacheKey);
        $existing = is_array($existing) ? $existing : [];

        // Check if all requested rates are already cached.
        $missing = array_filter(
            $toCurrencies,
            static fn(string $c): bool => ! isset($existing[$c])
        );

        if (empty($missing)) {
            return $existing;
        }

        // Fetch only the missing currencies from the API.
        $toParam = implode(',', $missing);
        $url     = sprintf(
            'https://api.frankfurter.app/latest?from=%s&to=%s',
            rawurlencode($from),
            rawurlencode($toParam)
        );

        $response = wp_remote_get($url, ['timeout' => 5]);

        if (is_wp_error($response)) {
            error_log(sprintf(
                '[ProductResearch] CurrencyConverter: HTTP error fetching rates from=%s to=%s: %s',
                $from,
                $toParam,
                $response->get_error_message()
            ));
            return $existing;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log(sprintf(
                '[ProductResearch] CurrencyConverter: Non-200 response (%d) from=%s to=%s',
                $code,
                $from,
                $toParam
            ));
            return $existing;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (! is_array($body) || ! isset($body['rates'])) {
            error_log(sprintf(
                '[ProductResearch] CurrencyConverter: Malformed response from=%s to=%s',
                $from,
                $toParam
            ));
            return $existing;
        }

        // Merge new rates into existing cache (never overwrite).
        $merged = array_merge($existing, $body['rates']);
        set_transient($cacheKey, $merged, DAY_IN_SECONDS);

        return $merged;
    }

    /**
     * Normalize array-based profiles (ResearchHandler path).
     *
     * Sets `converted_price`, `converted_original_price`, `store_currency`,
     * and `conversion_status` on each profile array.
     *
     * @since 1.0.0
     *
     * @param  array  &$profiles     Array of competitor profile arrays (by reference).
     * @param  string $storeCurrency ISO 4217 store currency code.
     * @return void
     */
    public function normalizeProfiles(array &$profiles, string $storeCurrency): void
    {
        // Collect unique currencies that differ from the store currency.
        $unique = [];
        foreach ($profiles as $p) {
            $c = $p['currency'] ?? '';
            if ($c !== '' && $c !== $storeCurrency && ! in_array($c, $unique, true)) {
                $unique[] = $c;
            }
        }

        // Single batch API call for all foreign currencies.
        $rates = ! empty($unique)
            ? $this->getRates($storeCurrency, $unique)
            : [];

        foreach ($profiles as &$p) {
            $currency      = $p['currency'] ?? '';
            $currentPrice  = (float) ($p['current_price'] ?? 0);
            $originalPrice = (float) ($p['original_price'] ?? 0);

            if ($currency === '' || $currency === $storeCurrency) {
                // Same currency — no conversion needed.
                $p['converted_price']          = $currentPrice;
                $p['converted_original_price'] = $originalPrice;
                $p['store_currency']           = $storeCurrency;
                $p['conversion_status']        = 'same_currency';
                continue;
            }

            if (isset($rates[$currency]) && $rates[$currency] > 0) {
                // Rate available — convert.
                $rate = (float) $rates[$currency];
                $p['converted_price']          = round($currentPrice / $rate, 2);
                $p['converted_original_price'] = $originalPrice > 0
                    ? round($originalPrice / $rate, 2)
                    : 0.0;
                $p['store_currency']           = $storeCurrency;
                $p['conversion_status']        = 'converted';
            } else {
                // Rate unavailable — mark as failed, keep raw prices.
                $p['converted_price']          = $currentPrice;
                $p['converted_original_price'] = $originalPrice;
                $p['store_currency']           = $storeCurrency;
                $p['conversion_status']        = 'failed';
            }
        }
        unset($p);
    }

    /**
     * Normalize CompetitorProfile objects (ReportNode path).
     *
     * Same logic as normalizeProfiles() but reads/writes object properties
     * instead of array keys.
     *
     * @since 1.0.0
     *
     * @param  array  $profiles      Array of CompetitorProfile objects.
     * @param  string $storeCurrency ISO 4217 store currency code.
     * @return void
     */
    public function normalizeProfileObjects(array $profiles, string $storeCurrency): void
    {
        // Collect unique currencies that differ from the store currency.
        $unique = [];
        foreach ($profiles as $p) {
            $c = $p->currency ?? '';
            if ($c !== '' && $c !== $storeCurrency && ! in_array($c, $unique, true)) {
                $unique[] = $c;
            }
        }

        // Single batch API call for all foreign currencies.
        $rates = ! empty($unique)
            ? $this->getRates($storeCurrency, $unique)
            : [];

        foreach ($profiles as $p) {
            $currency      = $p->currency ?? '';
            $currentPrice  = $p->currentPrice ?? 0.0;
            $originalPrice = $p->originalPrice ?? 0.0;

            if ($currency === '' || $currency === $storeCurrency) {
                $p->convertedPrice          = (float) $currentPrice;
                $p->convertedOriginalPrice  = (float) $originalPrice;
                $p->storeCurrency           = $storeCurrency;
                $p->conversionStatus        = 'same_currency';
                continue;
            }

            if (isset($rates[$currency]) && $rates[$currency] > 0) {
                $rate = (float) $rates[$currency];
                $p->convertedPrice          = round((float) $currentPrice / $rate, 2);
                $p->convertedOriginalPrice  = $originalPrice > 0
                    ? round((float) $originalPrice / $rate, 2)
                    : 0.0;
                $p->storeCurrency           = $storeCurrency;
                $p->conversionStatus        = 'converted';
            } else {
                $p->convertedPrice          = (float) $currentPrice;
                $p->convertedOriginalPrice  = (float) $originalPrice;
                $p->storeCurrency           = $storeCurrency;
                $p->conversionStatus        = 'failed';
            }
        }
    }
}
