<?php

declare(strict_types=1);

namespace TokenSqueezer\Builders;

use TokenSqueezer\CompressMode;
use TokenSqueezer\Monitors\TokenMonitor;

/**
 * Fluent builder for batch AI analysis.
 *
 * Processes each item sequentially. Partial failures are captured per-item
 * and do NOT abort the remaining items unless ->stopOnError() is called.
 *
 * Usage:
 *   TokenSqueezer::batch([
 *       ['symbol' => 'BTC', 'rsi' => 74],
 *       ['symbol' => 'ETH', 'rsi' => 55],
 *   ])
 *   ->compress(CompressMode::AGGRESSIVE)
 *   ->schema(['trend', 'risk'])
 *   ->via('openai')
 *   ->run();
 *
 *   // Returns:
 *   // [
 *   //   ['index' => 0, 'result' => [...], 'error' => null],
 *   //   ['index' => 1, 'result' => [...], 'error' => null],
 *   // ]
 */
class BatchBuilder
{
    // ── Compression ───────────────────────────────────────────────────────────
    protected CompressMode $compressMode = CompressMode::BALANCED;
    protected array $customCompressors   = [];
    protected bool  $caveman             = false;

    // ── Output schema ─────────────────────────────────────────────────────────
    protected array  $schema      = [];
    protected string $outputFormat = 'json';

    // ── AI Parameters ─────────────────────────────────────────────────────────
    protected float  $temperature = 0.1;
    protected int    $maxTokens   = 120;
    protected string $provider    = '';
    protected string $model       = '';

    // ── Fallback Chain ────────────────────────────────────────────────────────
    protected array $fallbackProviders = [];

    // ── Prompt ────────────────────────────────────────────────────────────────
    protected string $systemPrompt    = '';
    protected string $userPrompt      = '';
    protected array  $promptVariables = [];

    // ── Cache ────────────────────────────────────────────────────────────────
    protected bool $cacheEnabled = true;
    protected int  $cacheTtl     = 300;

    // ── Batch behaviour ───────────────────────────────────────────────────────
    protected bool $stopOnError = false;

    // ── Internals ─────────────────────────────────────────────────────────────
    protected array         $config;
    protected ?TokenMonitor $monitor;

    /**
     * @param  list<array<string, mixed>>  $items  Each item is a context array
     */
    public function __construct(
        protected array $items,
        array $config,
        ?TokenMonitor $monitor,
    ) {
        $this->config   = $config;
        $this->monitor  = $monitor;
        $this->provider = $config['default_provider'] ?? 'openai';
        $this->compressMode = CompressMode::from(
            $config['compress']['default_mode'] ?? CompressMode::BALANCED->value
        );
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Fluent setters — mirror AnalysisBuilder API for consistency
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function compress(CompressMode $mode = CompressMode::BALANCED): static
    {
        $this->compressMode = $mode;
        return $this;
    }

    public function addCompressor(string|object $compressor): static
    {
        $this->customCompressors[] = $compressor;
        return $this;
    }

    public function schema(array $keys): static
    {
        $this->schema       = $keys;
        $this->outputFormat = 'json';
        return $this;
    }

    public function asText(): static
    {
        $this->outputFormat = 'text';
        $this->schema       = [];
        return $this;
    }

    public function temperature(float $value): static
    {
        $this->temperature = max(0.0, min(2.0, $value));
        return $this;
    }

    public function maxTokens(int $tokens): static
    {
        $this->maxTokens = max(1, $tokens);
        return $this;
    }

    public function via(string $provider, string $model = ''): static
    {
        $this->provider = $provider;
        if ($model) {
            $this->model = $model;
        }
        return $this;
    }

    public function fallback(string ...$providers): static
    {
        $this->fallbackProviders = array_values($providers);
        return $this;
    }

    public function system(string $prompt): static
    {
        $this->systemPrompt = $prompt;
        return $this;
    }

    public function prompt(string $prompt): static
    {
        $this->userPrompt = $prompt;
        return $this;
    }

    public function with(array $variables): static
    {
        $this->promptVariables = array_merge($this->promptVariables, $variables);
        return $this;
    }

    public function caveman(bool $enabled = true): static
    {
        $this->caveman = $enabled;
        return $this;
    }

    public function cache(int $ttl = 300): static
    {
        $this->cacheEnabled = true;
        $this->cacheTtl     = $ttl;
        return $this;
    }

    public function noCache(): static
    {
        $this->cacheEnabled = false;
        return $this;
    }

    /**
     * Stop processing remaining items on first error.
     * Default: continue on error, capture per-item.
     */
    public function stopOnError(bool $stop = true): static
    {
        $this->stopOnError = $stop;
        return $this;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Execute
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Run all items sequentially and return results.
     *
     * Each entry in the returned array contains:
     *   - index:  int          — original position in $items
     *   - result: array|string — parsed AI response, or null on error
     *   - error:  string|null  — error message if this item failed
     *
     * @return list<array{index: int, result: array|string|null, error: string|null}>
     */
    public function run(): array
    {
        $results = [];

        foreach ($this->items as $index => $context) {
            $result = $this->runSingle((int) $index, (array) $context);
            $results[] = $result;

            if ($this->stopOnError && $result['error'] !== null) {
                break;
            }
        }

        return $results;
    }

    /**
     * Run a single item through an AnalysisBuilder.
     *
     * Reuses AnalysisBuilder so all features (rate limit, fallback, cache,
     * compression) work identically for batch items.
     *
     * @return array{index: int, result: array|string|null, error: string|null}
     */
    protected function runSingle(int $index, array $context): array
    {
        try {
            $builder = (new AnalysisBuilder($this->config, $this->monitor))
                ->context($context)
                ->compress($this->compressMode)
                ->temperature($this->temperature)
                ->maxTokens($this->maxTokens)
                ->via($this->provider, $this->model)
                ->caveman($this->caveman);

            // Forward optional settings only when set
            if ($this->schema) {
                $builder->schema($this->schema);
            }
            if ($this->outputFormat === 'text') {
                $builder->asText();
            }
            if ($this->systemPrompt) {
                $builder->system($this->systemPrompt);
            }
            if ($this->userPrompt) {
                $builder->prompt($this->userPrompt);
            }
            if ($this->promptVariables) {
                $builder->with($this->promptVariables);
            }
            if ($this->fallbackProviders) {
                $builder->fallback(...$this->fallbackProviders);
            }
            foreach ($this->customCompressors as $c) {
                $builder->addCompressor($c);
            }
            if ($this->cacheEnabled) {
                $builder->cache($this->cacheTtl);
            } else {
                $builder->noCache();
            }

            return [
                'index'  => $index,
                'result' => $builder->run(),
                'error'  => null,
            ];

        } catch (\Throwable $e) {
            return [
                'index'  => $index,
                'result' => null,
                'error'  => $e->getMessage(),
            ];
        }
    }
}
