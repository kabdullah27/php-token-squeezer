<?php

declare(strict_types=1);

namespace TokenSqueezer\Monitors;

/**
 * Tracks token usage, latency, and estimated cost across all AI requests.
 *
 * In-memory stats are per-request lifecycle (as usual for PHP).
 * Call enablePersistence() to also accumulate cross-request stats in Laravel cache.
 *
 * Access via TokenSqueezer::usage()
 */
class TokenMonitor
{
    protected array $records   = [];
    protected int   $cacheHits = 0;

    // ── Persistent stats ──────────────────────────────────────────────────────
    protected bool   $persistEnabled = false;
    protected string $persistKey     = 'tsq:usage_agg';

    /**
     * Enable cross-request stats persistence via Laravel cache.
     * Call this in TokenSqueezerServiceProvider::boot() or your AppServiceProvider.
     *
     * Stats are accumulated (not overwritten) and stored for 30 days.
     */
    public function enablePersistence(string $cacheKey = 'tsq:usage_agg'): static
    {
        $this->persistEnabled = true;
        $this->persistKey     = $cacheKey;
        return $this;
    }

    /**
     * Record a completed AI request.
     */
    public function record(
        string $provider,
        int    $inputTokens,
        int    $outputTokens,
        int    $latencyMs,
    ): void {
        $cost = $this->estimateCost($provider, $inputTokens, $outputTokens);

        $this->records[] = [
            'provider'       => $provider,
            'input_tokens'   => $inputTokens,
            'output_tokens'  => $outputTokens,
            'total_tokens'   => $inputTokens + $outputTokens,
            'latency_ms'     => $latencyMs,
            'estimated_cost' => $cost,
            'timestamp'      => $this->nowIso(),
        ];

        // Accumulate into persistent store (best-effort, no lock — OK for monitoring)
        if ($this->persistEnabled) {
            $this->accumulatePersistentStats($provider, $inputTokens, $outputTokens, $cost);
        }
    }

    public function recordCacheHit(string $key): void
    {
        $this->cacheHits++;
    }

    /**
     * Return aggregated usage summary for the current request session.
     */
    public function summary(): array
    {
        $totalInput  = array_sum(array_column($this->records, 'input_tokens'));
        $totalOutput = array_sum(array_column($this->records, 'output_tokens'));
        $totalCost   = array_sum(array_column($this->records, 'estimated_cost'));
        $avgLatency  = count($this->records) > 0
            ? array_sum(array_column($this->records, 'latency_ms')) / count($this->records)
            : 0;

        $totalRequests = count($this->records) + $this->cacheHits;
        $cacheHitRate  = $totalRequests > 0 ? round($this->cacheHits / $totalRequests * 100, 1) : 0;

        return [
            'total_requests'      => $totalRequests,
            'cache_hits'          => $this->cacheHits,
            'cache_hit_rate'      => "{$cacheHitRate}%",
            'total_input_tokens'  => $totalInput,
            'total_output_tokens' => $totalOutput,
            'total_tokens'        => $totalInput + $totalOutput,
            'avg_latency_ms'      => round($avgLatency),
            'estimated_cost_usd'  => '$' . number_format($totalCost, 4),
            'by_provider'         => $this->byProvider(),
            'records'             => $this->records,
        ];
    }

    /**
     * Return accumulated cross-request stats from persistent store.
     * Returns null if persistence is not enabled or no data exists yet.
     */
    public function persistentSummary(): ?array
    {
        if (!$this->persistEnabled || !class_exists(\Illuminate\Support\Facades\Cache::class)) {
            return null;
        }
        return \Illuminate\Support\Facades\Cache::get($this->persistKey);
    }

    /**
     * Reset in-memory stats for current session.
     */
    public function reset(): void
    {
        $this->records   = [];
        $this->cacheHits = 0;
    }

    /**
     * Wipe persistent accumulated stats from cache.
     */
    public function resetPersistent(): void
    {
        if ($this->persistEnabled && class_exists(\Illuminate\Support\Facades\Cache::class)) {
            \Illuminate\Support\Facades\Cache::forget($this->persistKey);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    protected function byProvider(): array
    {
        $grouped = [];
        foreach ($this->records as $r) {
            $p = $r['provider'];
            $grouped[$p] ??= ['requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0];
            $grouped[$p]['requests']++;
            $grouped[$p]['input_tokens']  += $r['input_tokens'];
            $grouped[$p]['output_tokens'] += $r['output_tokens'];
            $grouped[$p]['cost']          += $r['estimated_cost'];
        }
        return $grouped;
    }

    protected function accumulatePersistentStats(
        string $provider,
        int    $input,
        int    $output,
        float  $cost,
    ): void {
        if (!class_exists(\Illuminate\Support\Facades\Cache::class)) {
            return;
        }

        $agg = \Illuminate\Support\Facades\Cache::get($this->persistKey, [
            'total_requests'      => 0,
            'total_input_tokens'  => 0,
            'total_output_tokens' => 0,
            'total_cost_usd'      => 0.0,
            'by_provider'         => [],
            'first_seen'          => $this->nowIso(),
            'last_seen'           => null,
        ]);

        $agg['total_requests']++;
        $agg['total_input_tokens']  += $input;
        $agg['total_output_tokens'] += $output;
        $agg['total_cost_usd']      += $cost;
        $agg['last_seen']            = $this->nowIso();

        $agg['by_provider'][$provider] ??= [
            'requests'      => 0,
            'input_tokens'  => 0,
            'output_tokens' => 0,
            'cost_usd'      => 0.0,
        ];
        $agg['by_provider'][$provider]['requests']++;
        $agg['by_provider'][$provider]['input_tokens']  += $input;
        $agg['by_provider'][$provider]['output_tokens'] += $output;
        $agg['by_provider'][$provider]['cost_usd']      += $cost;

        // 30-day TTL — soft reset by running tsq:usage --reset
        \Illuminate\Support\Facades\Cache::put($this->persistKey, $agg, 60 * 60 * 24 * 30);
    }

    /**
     * Approximate cost in USD per 1M tokens (prices as of 2025).
     */
    protected function estimateCost(string $provider, int $input, int $output): float
    {
        [$inRate, $outRate] = match ($provider) {
            'openai'  => [0.15, 0.60],   // gpt-4o-mini per 1M
            'claude'  => [0.25, 1.25],   // claude-haiku per 1M
            'gemini'  => [0.075, 0.30],  // gemini-1.5-flash per 1M
            'kimi'    => [0.12, 0.12],   // moonshot-v1-8k per 1M (approx)
            'mimo'    => [0.14, 0.28],   // mimo-v2.5 per 1M
            'ollama'  => [0.0, 0.0],     // local, free
            default   => [0.50, 1.50],
        };
        return ($input / 1_000_000 * $inRate) + ($output / 1_000_000 * $outRate);
    }

    protected function nowIso(): string
    {
        // Works in plain PHP (no Laravel dependency)
        return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }
}

