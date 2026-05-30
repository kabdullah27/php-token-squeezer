<?php

declare(strict_types=1);

namespace TokenSqueezer\Monitors;

/**
 * Tracks token usage, latency, and estimated cost across all AI requests.
 *
 * Access via TokenSqueezer::usage()
 */
class TokenMonitor
{
    protected array $records   = [];
    protected int   $cacheHits = 0;

    /**
     * Record a completed AI request.
     */
    public function record(
        string $provider,
        int    $inputTokens,
        int    $outputTokens,
        int    $latencyMs,
    ): void {
        $this->records[] = [
            'provider'      => $provider,
            'input_tokens'  => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens'  => $inputTokens + $outputTokens,
            'latency_ms'    => $latencyMs,
            'estimated_cost'=> $this->estimateCost($provider, $inputTokens, $outputTokens),
            'timestamp'     => now()->toISOString(),
        ];
    }

    public function recordCacheHit(string $key): void
    {
        $this->cacheHits++;
    }

    /**
     * Return aggregated usage summary.
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
            'total_requests'    => $totalRequests,
            'cache_hits'        => $this->cacheHits,
            'cache_hit_rate'    => "{$cacheHitRate}%",
            'total_input_tokens' => $totalInput,
            'total_output_tokens' => $totalOutput,
            'total_tokens'      => $totalInput + $totalOutput,
            'avg_latency_ms'    => round($avgLatency),
            'estimated_cost_usd' => '$' . number_format($totalCost, 4),
            'by_provider'       => $this->byProvider(),
            'records'           => $this->records,
        ];
    }

    public function reset(): void
    {
        $this->records   = [];
        $this->cacheHits = 0;
    }

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

    /**
     * Approximate cost in USD per 1M tokens (prices as of 2025).
     * Update these to reflect current pricing.
     */
    protected function estimateCost(string $provider, int $input, int $output): float
    {
        [$inRate, $outRate] = match ($provider) {
            'openai'  => [0.15, 0.60],   // gpt-4o-mini per 1M
            'claude'  => [0.25, 1.25],   // claude-haiku per 1M
            'gemini'  => [0.075, 0.30],  // gemini-1.5-flash per 1M
            'kimi'    => [0.12, 0.12],   // moonshot-v1-8k per 1M (approx)
            'mimo'    => [0.14, 0.28],   // mimo-v2.5 per 1M ($0.14/$0.28)
            'ollama'  => [0.0, 0.0],     // local, free
            default   => [0.50, 1.50],
        };

        return ($input / 1_000_000 * $inRate) + ($output / 1_000_000 * $outRate);
    }
}
