# Event Dispatching

TokenSqueezer fires events at key points in the request lifecycle.
Works in **plain PHP** and **Laravel** — no extra config required.

---

## Available Events

| Event | Fired When |
|---|---|
| `AnalysisCompleted` | Every successful `run()` (even if served from cache) |
| `AnalysisFailed` | All providers exhausted, about to throw exception |
| `CacheHit` | Result served from cache (no AI call made) |
| `BatchCompleted` | `batch()->run()` finishes all items |

---

## Plain PHP

```php
use TokenSqueezer\TokenSqueezer;
use TokenSqueezer\Events\AnalysisCompleted;
use TokenSqueezer\Events\AnalysisFailed;
use TokenSqueezer\Events\CacheHit;
use TokenSqueezer\Events\BatchCompleted;

// Register listeners before running analysis
TokenSqueezer::listen(AnalysisCompleted::class, function (AnalysisCompleted $e) {
    error_log("✅ [{$e->provider}] {$e->inputTokens}in / {$e->outputTokens}out — {$e->latencyMs}ms");
});

TokenSqueezer::listen(AnalysisFailed::class, function (AnalysisFailed $e) {
    error_log("❌ All providers failed: " . implode(', ', array_keys($e->errors)));
});

TokenSqueezer::listen(CacheHit::class, function (CacheHit $e) {
    error_log("⚡ Cache hit for provider [{$e->provider}]");
});

TokenSqueezer::listen(BatchCompleted::class, function (BatchCompleted $e) {
    error_log("📦 Batch done: {$e->succeeded}/{$e->total} ok in {$e->durationMs}ms");
});
```

---

## Laravel

### Option 1 — EventServiceProvider (recommended)

Register in `App\Providers\EventServiceProvider`:

```php
protected $listen = [
    \TokenSqueezer\Events\AnalysisCompleted::class => [
        \App\Listeners\LogAiUsage::class,
    ],
    \TokenSqueezer\Events\AnalysisFailed::class => [
        \App\Listeners\AlertOnAiFailure::class,
    ],
];
```

Generate listener:

```bash
php artisan event:generate
```

### Option 2 — Inline in AppServiceProvider

```php
use TokenSqueezer\Events\AnalysisCompleted;

Event::listen(AnalysisCompleted::class, function (AnalysisCompleted $e) {
    Log::channel('ai')->info('analysis', [
        'provider' => $e->provider,
        'tokens'   => $e->inputTokens + $e->outputTokens,
        'cost_est' => '$' . number_format(($e->inputTokens * 0.15 + $e->outputTokens * 0.60) / 1_000_000, 6),
        'ms'       => $e->latencyMs,
    ]);
});
```

---

## Event Properties

### `AnalysisCompleted`

```php
$e->provider      // string   — 'openai', 'claude', etc.
$e->result        // array|string — parsed AI response
$e->inputTokens   // int
$e->outputTokens  // int
$e->latencyMs     // int
$e->fromCache     // bool — true if served from cache
```

### `AnalysisFailed`

```php
$e->errors        // array<string, string> — ['openai' => 'timeout', 'claude' => '429']
```

### `CacheHit`

```php
$e->provider      // string — primary provider name
$e->cacheKey      // string — hashed cache key
```

### `BatchCompleted`

```php
$e->total         // int — total items submitted
$e->succeeded     // int — items with no error
$e->failed        // int — items with error
$e->durationMs    // int — wall-clock time for entire batch
```

---

## Common Patterns

### Send to Datadog / custom metrics

```php
TokenSqueezer::listen(AnalysisCompleted::class, function (AnalysisCompleted $e) {
    Datadog::gauge('tsq.tokens', $e->inputTokens + $e->outputTokens, ['provider' => $e->provider]);
    Datadog::gauge('tsq.latency_ms', $e->latencyMs, ['provider' => $e->provider]);
});
```

### Alert on repeated failures

```php
TokenSqueezer::listen(AnalysisFailed::class, function (AnalysisFailed $e) {
    Slack::send('#alerts', '🚨 All AI providers failed: ' . implode(', ', array_keys($e->errors)));
});
```

### Count cache saves

```php
TokenSqueezer::listen(CacheHit::class, function (CacheHit $e) {
    Cache::increment('tsq:cache_hit_count');
});
```
