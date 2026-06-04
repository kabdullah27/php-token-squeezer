<?php

declare(strict_types=1);

namespace TokenSqueezer;

use TokenSqueezer\Builders\AnalysisBuilder;
use TokenSqueezer\Builders\BatchBuilder;
use TokenSqueezer\Monitors\TokenMonitor;
use TokenSqueezer\Contracts\ProviderInterface;
use TokenSqueezer\Events\EventDispatcher;

/**
 * TokenSqueezer — General-purpose AI token optimization library
 *
 * Usage:
 *   TokenSqueezer::analyze()
 *       ->context(['key' => 'value'])
 *       ->compress(CompressMode::AGGRESSIVE)
 *       ->schema(['result', 'reason'])
 *       ->temperature(0.1)
 *       ->maxTokens(120)
 *       ->cache(ttl: 300)
 *       ->via('openai')
 *       ->run();
 */
class TokenSqueezer
{
    protected static array $config = [];
    protected static ?TokenMonitor $monitor = null;
    protected static array $providers = [];

    /**
     * Start a new analysis chain.
     */
    public static function analyze(): AnalysisBuilder
    {
        return new AnalysisBuilder(static::$config, static::$monitor);
    }

    /**
     * Start a batch analysis for multiple context arrays.
     *
     * Each item in $items is a context array (same shape as ->context() in AnalysisBuilder).
     * All shared settings (compress, schema, provider, etc.) are configured via
     * the returned BatchBuilder using the same fluent API.
     *
     * @param  list<array<string, mixed>>  $items
     *
     * @example
     *   TokenSqueezer::batch([
     *       ['symbol' => 'BTC', 'rsi' => 74],
     *       ['symbol' => 'ETH', 'rsi' => 55],
     *   ])
     *   ->compress(CompressMode::AGGRESSIVE)
     *   ->schema(['trend', 'risk'])
     *   ->via('openai')
     *   ->run();
     */
    public static function batch(array $items): BatchBuilder
    {
        return new BatchBuilder($items, static::$config, static::$monitor);
    }

    /**
     * Bootstrap the library with a config array.
     *
     * @param  array{
     *     default_provider: string,
     *     providers: array<string, array>,
     *     cache: array,
     *     compress: array,
     *     monitor: bool
     * } $config
     */
    public static function configure(array $config): void
    {
        static::$config = array_merge(static::defaults(), $config);

        if ($config['monitor'] ?? true) {
            static::$monitor = new TokenMonitor();
        }
    }

    /**
     * Retrieve token usage statistics for the current session.
     */
    public static function usage(): array
    {
        return static::$monitor?->summary() ?? [];
    }

    /**
     * Retrieve accumulated cross-request usage stats (requires Laravel + persistence enabled).
     * Returns null if no stats have been accumulated yet.
     */
    public static function persistentUsage(): ?array
    {
        return static::$monitor?->persistentSummary();
    }

    /**
     * Enable cross-request stats persistence (called automatically in Laravel).
     * In plain PHP, call this manually if you need persistent stats.
     */
    public static function enablePersistentUsage(string $cacheKey = 'tsq:usage_agg'): void
    {
        static::$monitor?->enablePersistence($cacheKey);
    }

    /**
     * Reset usage statistics for the current session.
     */
    public static function resetUsage(): void
    {
        static::$monitor?->reset();
    }

    /**
     * Wipe accumulated persistent usage stats.
     */
    public static function resetPersistentUsage(): void
    {
        static::$monitor?->resetPersistent();
    }

    /**
     * Register a listener for a TokenSqueezer event.
     *
     * Works in plain PHP. In Laravel, prefer EventServiceProvider $listen.
     *
     * @param  class-string  $eventClass  e.g. AnalysisCompleted::class
     * @param  callable      $listener
     *
     * @example
     *   TokenSqueezer::listen(AnalysisCompleted::class, function ($e) {
     *       Log::info('AI done', ['provider' => $e->provider, 'tokens' => $e->inputTokens]);
     *   });
     */
    public static function listen(string $eventClass, callable $listener): void
    {
        EventDispatcher::listen($eventClass, $listener);
    }

    /**
     * Get the current active configuration.
     */
    public static function getConfig(): array
    {
        return static::$config;
    }

    /**
     * Default configuration values.
     */
    protected static function defaults(): array
    {
        return [
            'default_provider' => 'openai',
            'providers'        => [],
            'cache'            => [
                'enabled' => true,
                'driver'  => 'array', // array | redis | file
                'prefix'  => 'tsq:',
                'ttl'     => 300,
            ],
            'compress' => [
                'default_mode'     => CompressMode::BALANCED,
                'strip_stopwords'  => true,
                'lowercase_keys'   => true,
                'max_context_keys' => 20,
            ],
            'monitor' => true,
            'timeout' => 15,
            'retries' => 2,
        ];
    }
}
