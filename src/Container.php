<?php

declare(strict_types=1);

namespace ProductResearch;

use ProductResearch\Admin\Assets;
use ProductResearch\Admin\MetaBox;
use ProductResearch\Admin\SettingsPage;
use ProductResearch\Ajax\ResearchHandler;
use ProductResearch\API\ContentSanitizer;
use ProductResearch\API\TavilyClient;
use ProductResearch\Cache\CacheManager;
use ProductResearch\Export\ReportExporter;
use ProductResearch\Report\ReportRepository;
use ProductResearch\Security\Encryption;
use ProductResearch\Security\Logger;
/**
 * Lightweight service container.
 *
 * Uses a PHP array to map service IDs to factory closures.
 * Services are lazily instantiated on first get() call.
 */
final class Container
{
    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    public function __construct()
    {
        $this->registerServices();
    }

    /**
     * @param string $id
     * @return mixed
     * @throws \RuntimeException If service not found.
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

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    /**
     * Register a service factory.
     */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * Register all plugin services.
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

        $this->set(ResearchHandler::class, function (self $c): ResearchHandler {
            return new ResearchHandler(
                $c->get(TavilyClient::class),
                $c->get(ContentSanitizer::class),
                $c->get(ReportRepository::class),
                $c->get(CacheManager::class),
                $c->get(Logger::class)
            );
        });
    }
}
