<?php

declare(strict_types=1);

namespace ProductResearch;

use ProductResearch\Admin\Assets;
use ProductResearch\Admin\MetaBox;
use ProductResearch\Admin\ProductListColumns;
use ProductResearch\Admin\SettingsPage;
use ProductResearch\Ajax\BookmarkHandler;
use ProductResearch\Ajax\CopywriterHandler;
use ProductResearch\Ajax\ResearchHandler;
use ProductResearch\API\ContentSanitizer;
use ProductResearch\API\TavilyClient;
use ProductResearch\Cache\CacheManager;
use ProductResearch\Export\ReportExporter;
use ProductResearch\Report\ReportRepository;
use ProductResearch\Currency\CurrencyConverter;
use ProductResearch\Security\Encryption;
use ProductResearch\Security\Logger;
/**
 * Lightweight service container.
 *
 * Uses a PHP array to map service IDs to factory closures.
 * Services are lazily instantiated on first get() call and then
 * cached for subsequent requests within the same lifecycle.
 *
 * @package ProductResearch
 * @since   1.0.0
 */
final class Container
{
    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /**
     * Create the container and register all plugin service factories.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        $this->registerServices();
    }

    /**
     * Resolve a service from the container.
     *
     * On the first call for a given ID the factory closure is invoked and
     * the resulting instance is cached. Subsequent calls return the cached
     * instance (singleton within this container's lifecycle).
     *
     * @since 1.0.0
     *
     * @param  string $id Fully-qualified class name used as the service identifier.
     * @return mixed  The resolved service instance.
     *
     * @throws \RuntimeException If no factory has been registered for the given ID.
     */
    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (! $this->has($id)) {
            throw new \RuntimeException(
                sprintf('Service "%s" not found in container.', $id)
            );
        }

        $this->instances[$id] = ($this->factories[$id])($this);

        return $this->instances[$id];
    }

    /**
     * Check whether a service factory is registered.
     *
     * @since 1.0.0
     *
     * @param  string $id Fully-qualified class name used as the service identifier.
     * @return bool   True if a factory exists for the given ID, false otherwise.
     */
    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    /**
     * Register (or replace) a service factory.
     *
     * If an instance was previously cached for this ID it is evicted,
     * ensuring the new factory is used on the next get() call.
     *
     * @since 1.0.0
     *
     * @param string   $id      Fully-qualified class name used as the service identifier.
     * @param callable $factory Closure that receives the Container and returns the service instance.
     *
     * @return void
     */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * Register all plugin service factories.
     *
     * Each factory closure defines how to instantiate a service and wire
     * its dependencies. Closures are invoked lazily — only when the
     * service is first requested via get().
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function registerServices(): void
    {
        $this->set(Logger::class, static fn(): Logger => new Logger());

        $this->set(Encryption::class, static fn(): Encryption => new Encryption());

        $this->set(CacheManager::class, static function (): CacheManager {
            $ttlHours = (int) get_option('pr_cache_ttl', 24);
            return new CacheManager($ttlHours);
        });

        $this->set(TavilyClient::class, function (self $c): TavilyClient {
            return new TavilyClient(
                $c->get(Encryption::class),
                $c->get(CacheManager::class),
                $c->get(Logger::class)
            );
        });

        $this->set(ContentSanitizer::class, static function (): ContentSanitizer {
            $tokenBudget = (int) get_option('pr_token_budget', 4000);
            return new ContentSanitizer($tokenBudget);
        });

        $this->set(ReportRepository::class, static fn(): ReportRepository => new ReportRepository());

        $this->set(ReportExporter::class, function (self $c): ReportExporter {
            return new ReportExporter($c->get(ReportRepository::class));
        });

        $this->set(SettingsPage::class, function (self $c): SettingsPage {
            return new SettingsPage($c->get(Encryption::class));
        });

        $this->set(MetaBox::class, function (self $c): MetaBox {
            return new MetaBox($c->get(ReportRepository::class));
        });

        $this->set(Assets::class, static function (): Assets {
            return new Assets(
                plugin_dir_url(dirname(__DIR__) . '/product-research.php'),
                plugin_dir_path(dirname(__DIR__) . '/product-research.php')
            );
        });

        $this->set(ProductListColumns::class, static fn(): ProductListColumns => new ProductListColumns());

        $this->set(CurrencyConverter::class, static function (): CurrencyConverter {
            return new CurrencyConverter(function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD');
        });

        $this->set(ResearchHandler::class, function (self $c): ResearchHandler {
            return new ResearchHandler(
                $c->get(TavilyClient::class),
                $c->get(ContentSanitizer::class),
                $c->get(ReportRepository::class),
                $c->get(CacheManager::class),
                $c->get(Logger::class),
                $c->get(CurrencyConverter::class)
            );
        });

        $this->set(BookmarkHandler::class, function (self $c): BookmarkHandler {
            return new BookmarkHandler(
                $c->get(ReportRepository::class)
            );
        });

        $this->set(CopywriterHandler::class, function (self $c): CopywriterHandler {
            return new CopywriterHandler(
                $c->get(ReportRepository::class),
                $c->get(Logger::class)
            );
        });
    }
}
