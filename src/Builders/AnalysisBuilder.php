<?php

declare(strict_types=1);

namespace TokenSqueezer\Builders;

use TokenSqueezer\CompressMode;
use TokenSqueezer\Compressors\CompressorPipeline;
use TokenSqueezer\Monitors\TokenMonitor;
use TokenSqueezer\Cache\SmartCache;
use TokenSqueezer\Parsers\ResponseParser;
use TokenSqueezer\Providers\ProviderFactory;
use TokenSqueezer\Exceptions\TokenSqueezedException;
use TokenSqueezer\Exceptions\FallbackExhaustedException;
use TokenSqueezer\Exceptions\RateLimitException;
use TokenSqueezer\RateLimiter\RateLimiter;

/**
 * Fluent builder for AI analysis requests.
 *
 * Every method returns $this for chaining.
 * Call ->run() at the end to execute.
 */
class AnalysisBuilder
{
    // ── Context ──────────────────────────────────────────────────────────────
    protected array  $rawContext       = [];
    protected string $systemPrompt     = '';
    protected string $userPrompt       = '';
    protected array  $promptVariables  = [];

    // ── Compression ───────────────────────────────────────────────────────────
    protected CompressMode $compressMode = CompressMode::BALANCED;
    protected array $customCompressors   = [];
    protected bool $caveman              = false;

    // ── Output schema ─────────────────────────────────────────────────────────
    protected array  $schema      = [];
    protected string $outputFormat = 'json'; // json | text

    // ── AI Parameters ─────────────────────────────────────────────────────────
    protected float  $temperature = 0.1;
    protected int    $maxTokens   = 120;
    protected string $provider    = '';
    protected string $model       = '';

    // ── Fallback Chain ────────────────────────────────────────────────────────
    /** @var list<string> Additional providers to try if primary fails */
    protected array $fallbackProviders = [];

    // ── Cache ────────────────────────────────────────────────────────────────
    protected bool $cacheEnabled = true;
    protected int  $cacheTtl     = 300;
    protected ?string $cacheKey  = null;

    // ── Internals ─────────────────────────────────────────────────────────────
    protected array         $config;
    protected ?TokenMonitor $monitor;

    public function __construct(array $config, ?TokenMonitor $monitor)
    {
        $this->config    = $config;
        $this->monitor   = $monitor;
        $this->provider  = $config['default_provider'] ?? 'openai';
        $this->compressMode = CompressMode::from(
            $config['compress']['default_mode'] ?? CompressMode::BALANCED->value
        );
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Context
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Set the context data that will be compressed and injected into the prompt.
     *
     * @param  array<string, mixed> $data  Flat or nested key-value data.
     * @return static
     *
     * @example
     *   ->context(['symbol' => 'BTC', 'rsi' => 74, 'trend' => 'bullish'])
     *   ->context(['user_score' => 92, 'tier' => 'gold', 'country' => 'ID'])
     */
    public function context(array $data): static
    {
        $this->rawContext = $data;
        return $this;
    }

    /**
     * Set a custom system prompt. Supports {{variable}} placeholders.
     *
     * @return static
     */
    public function system(string $prompt): static
    {
        $this->systemPrompt = $prompt;
        return $this;
    }

    /**
     * Set a custom user prompt. Supports {{variable}} placeholders.
     * Use {{context}} to inject the compressed context automatically.
     *
     * @return static
     *
     * @example
     *   ->prompt('Analyze: {{context}}. Return trend and risk.')
     */
    public function prompt(string $prompt): static
    {
        $this->userPrompt = $prompt;
        return $this;
    }

    /**
     * Inject additional variables into prompt placeholders.
     *
     * @return static
     */
    public function with(array $variables): static
    {
        $this->promptVariables = array_merge($this->promptVariables, $variables);
        return $this;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Compression
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Set compression aggressiveness.
     *
     * @return static
     *
     * @example
     *   ->compress(CompressMode::AGGRESSIVE)
     *   ->compress(CompressMode::MINIMAL)
     */
    public function compress(CompressMode $mode = CompressMode::BALANCED): static
    {
        $this->compressMode = $mode;
        return $this;
    }

    /**
     * Add a custom compressor class to the pipeline.
     * Must implement Contracts\CompressorInterface.
     *
     * @param  string|object $compressor  Class name or instance.
     * @return static
     */
    public function addCompressor(string|object $compressor): static
    {
        $this->customCompressors[] = $compressor;
        return $this;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Output / Schema
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Define the JSON keys you expect in the response.
     * Automatically adds a JSON instruction to the prompt.
     *
     * @param  string[]  $keys  Keys the AI should return.
     * @return static
     *
     * @example
     *   ->schema(['trend', 'risk', 'action'])
     *   ->schema(['verdict', 'score', 'reason', 'next_step'])
     */
    public function schema(array $keys): static
    {
        $this->schema       = $keys;
        $this->outputFormat = 'json';
        return $this;
    }

    /**
     * Request plain text output instead of JSON.
     *
     * @return static
     */
    public function asText(): static
    {
        $this->outputFormat = 'text';
        $this->schema       = [];
        return $this;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // AI Parameters
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Set sampling temperature. Lower = more deterministic.
     *
     * @param  float  $value  Between 0.0 and 2.0. Default: 0.1
     * @return static
     */
    public function temperature(float $value): static
    {
        $this->temperature = max(0.0, min(2.0, $value));
        return $this;
    }

    /**
     * Set max tokens for the AI response.
     *
     * @param  int  $tokens  Default: 120
     * @return static
     */
    public function maxTokens(int $tokens): static
    {
        $this->maxTokens = max(1, $tokens);
        return $this;
    }

    /**
     * Choose the AI provider to use for this request.
     *
     * @param  string  $provider  'openai' | 'claude' | 'gemini' | 'kimi' | 'mimo' | 'ollama'
     * @param  string  $model     Optional model override (e.g. 'gpt-4o-mini')
     * @return static
     */
    public function via(string $provider, string $model = ''): static
    {
        $this->provider = $provider;
        if ($model) {
            $this->model = $model;
        }
        return $this;
    }

    /**
     * Define fallback providers to try if the primary provider fails.
     *
     * Providers are tried in the order given. A provider is skipped if its
     * rate limit is already exhausted.
     *
     * @param  string  ...$providers  Provider names, e.g. 'claude', 'gemini'
     * @return static
     *
     * @example
     *   ->via('openai')->fallback('claude', 'gemini')
     */
    public function fallback(string ...$providers): static
    {
        $this->fallbackProviders = array_values($providers);
        return $this;
    }

    /**
     * Enable/disable Caveman mode (high output token density/compression).
     *
     * @return static
     */
    public function caveman(bool $enabled = true): static
    {
        $this->caveman = $enabled;
        return $this;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Cache
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Enable caching for this request.
     *
     * @param  int          $ttl  Cache lifetime in seconds. Default: 300
     * @param  string|null  $key  Optional explicit cache key. Auto-generated if null.
     * @return static
     */
    public function cache(int $ttl = 300, ?string $key = null): static
    {
        $this->cacheEnabled = true;
        $this->cacheTtl     = $ttl;
        $this->cacheKey     = $key;
        return $this;
    }

    /**
     * Disable caching for this request (bypass cache entirely).
     *
     * @return static
     */
    public function noCache(): static
    {
        $this->cacheEnabled = false;
        return $this;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Execute
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Execute the analysis chain and return the parsed response.
     *
     * Tries the primary provider first. If it fails (connection error or API error),
     * it falls through to each fallback provider in order.
     *
     * @return array|string  Parsed JSON as array, or plain text string.
     * @throws FallbackExhaustedException  if all providers fail
     * @throws RateLimitException          if primary provider is rate-limited and no fallback succeeds
     */
    public function run(): array|string
    {
        // 1. Compress context (once, shared across all provider attempts)
        $pipeline      = new CompressorPipeline($this->compressMode, $this->customCompressors);
        $compressedCtx = $pipeline->compress($this->rawContext);

        // 2. Build prompt (once, shared across all provider attempts)
        $promptBuilder = new PromptBuilder(
            compressed: $compressedCtx,
            schema:     $this->schema,
            format:     $this->outputFormat,
            system:     $this->systemPrompt,
            user:       $this->userPrompt,
            variables:  $this->promptVariables,
            caveman:    $this->caveman,
        );
        $prompt = $promptBuilder->build();

        // 3. Check cache (use primary provider name as part of key)
        $cache    = new SmartCache($this->config['cache'] ?? []);
        $cacheKey = $this->cacheKey ?? $cache->generateKey($this->provider, $compressedCtx, $this->schema);

        if ($this->cacheEnabled && $cached = $cache->get($cacheKey)) {
            $this->monitor?->recordCacheHit($cacheKey);
            return $cached;
        }

        // 4. Build provider chain: primary + fallbacks
        $chain  = array_merge([$this->provider], $this->fallbackProviders);
        $errors = [];

        foreach ($chain as $providerName) {
            $providerConfig = $this->config['providers'][$providerName] ?? [];

            // 4a. Check rate limit before attempting the provider
            try {
                RateLimiter::check($providerName, $providerConfig);
            } catch (RateLimitException $e) {
                // Rate-limited → record and try next in chain
                $errors[$providerName] = $e->getMessage();
                continue;
            }

            // 4b. Attempt the provider
            try {
                $provider    = ProviderFactory::make($providerName, $providerConfig, $this->model);
                $startTime   = microtime(true);
                $rawResponse = $provider->complete(
                    messages:    $prompt->toMessages(),
                    temperature: $this->temperature,
                    maxTokens:   $this->maxTokens,
                );
                $elapsed = microtime(true) - $startTime;

                // 4c. Track usage for the provider that succeeded
                $this->monitor?->record(
                    provider:     $providerName,
                    inputTokens:  $rawResponse['usage']['input_tokens']  ?? 0,
                    outputTokens: $rawResponse['usage']['output_tokens'] ?? 0,
                    latencyMs:    (int) ($elapsed * 1000),
                );

                // 4d. Parse response
                $parser = new ResponseParser($this->outputFormat, $this->schema);
                $result = $parser->parse($rawResponse['content'] ?? '');

                // 4e. Store in cache
                if ($this->cacheEnabled) {
                    $cache->put($cacheKey, $result, $this->cacheTtl);
                }

                return $result;

            } catch (TokenSqueezedException $e) {
                // Provider failed → record and try next in chain
                $errors[$providerName] = $e->getMessage();
            }
        }

        // All providers exhausted
        throw new FallbackExhaustedException($errors);
    }

    /**
     * Dry-run: returns the compressed context and built prompt without calling AI.
     * Useful for debugging and token estimation.
     */
    public function inspect(): array
    {
        $pipeline      = new CompressorPipeline($this->compressMode, $this->customCompressors);
        $compressedCtx = $pipeline->compress($this->rawContext);

        $promptBuilder = new PromptBuilder(
            compressed: $compressedCtx,
            schema:     $this->schema,
            format:     $this->outputFormat,
            system:     $this->systemPrompt,
            user:       $this->userPrompt,
            variables:  $this->promptVariables,
        );
        $prompt = $promptBuilder->build();

        return [
            'original_context'    => $this->rawContext,
            'compressed_context'  => $compressedCtx,
            'estimated_reduction' => $this->estimateReduction($this->rawContext, $compressedCtx),
            'prompt'              => $prompt->toMessages(),
            'provider'            => $this->provider,
            'fallback_chain'      => $this->fallbackProviders,
            'temperature'         => $this->temperature,
            'max_tokens'          => $this->maxTokens,
        ];
    }

    protected function estimateReduction(array $raw, string $compressed): string
    {
        $rawLen  = strlen(json_encode($raw));
        $cmpLen  = strlen($compressed);
        $pct     = $rawLen > 0 ? round((1 - $cmpLen / $rawLen) * 100) : 0;
        return "{$pct}% (from {$rawLen} to {$cmpLen} chars)";
    }
}
