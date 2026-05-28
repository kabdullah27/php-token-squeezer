# TokenSqueezer 🗜️

**General-purpose AI token optimization library for PHP & Laravel.**

Compress context, cut token usage by up to 80%, and call any AI provider through one fluent API — not just for trading, but for *any* domain.

```
composer require yourusername/token-squeezer
```

---

## Features

- **Fluent chain API** — readable, intuitive, testable
- **Multi-provider** — OpenAI, Claude, Gemini, Kimi, Ollama, or your own driver
- **Smart compression** — 3 built-in modes + plugin support
- **Auto-caching** — context-hash keys, pluggable drivers (array / file / redis / laravel)
- **Schema enforcement** — define expected JSON keys, auto-fill missing
- **Token monitoring** — usage tracking, cost estimation, latency stats
- **Zero Laravel dependency** — works in plain PHP too
- **Dry-run / inspect** — preview prompt + compression without calling AI

---

## Quick Start

### 1. Install

```bash
composer require yourusername/token-squeezer
```

### 2. Laravel Setup (auto-discovery works automatically)

```bash
php artisan vendor:publish --tag=token-squeezer-config
```

Add to your `.env`:

```env
TSQ_PROVIDER=openai
OPENAI_API_KEY=sk-...
TSQ_CACHE_DRIVER=laravel
```

### 3. Plain PHP Setup

```php
use TokenSqueezer\TokenSqueezer;
use TokenSqueezer\CompressMode;

TokenSqueezer::configure([
    'default_provider' => 'openai',
    'providers' => [
        'openai' => [
            'api_key' => getenv('OPENAI_API_KEY'),
            'model'   => 'gpt-4o-mini',
        ],
    ],
    'cache'   => ['driver' => 'file'],
    'monitor' => true,
]);
```

---

## Usage Examples

### Trading / Financial Analysis

```php
$result = TokenSqueezer::analyze()
    ->context([
        'symbol'       => 'BTCUSDT',
        'price'        => 104500,
        'rsi'          => 74,
        'macd'         => 'cross_up',
        'trend'        => 'bullish',
        'volume_spike' => true,
    ])
    ->compress(CompressMode::AGGRESSIVE)
    ->schema(['trend', 'risk', 'action'])
    ->temperature(0.1)
    ->maxTokens(80)
    ->cache(ttl: 60)
    ->via('openai')
    ->run();

// ['trend' => 'bullish', 'risk' => 'medium', 'action' => 'wait breakout confirmation']
```

---

### E-commerce Product Scoring

```php
$result = TokenSqueezer::analyze()
    ->context([
        'product'   => 'Nike Air Max 90',
        'price'     => 1200000,
        'stock'     => 3,
        'rating'    => 4.7,
        'reviews'   => 842,
        'category'  => 'sneakers',
        'discount'  => 15,
    ])
    ->compress(CompressMode::BALANCED)
    ->schema(['score', 'label', 'recommendation'])
    ->temperature(0.2)
    ->maxTokens(100)
    ->cache(ttl: 600)
    ->via('gemini')
    ->run();
```

---

### Customer Support Ticket Triage

```php
$result = TokenSqueezer::analyze()
    ->context([
        'subject'  => 'Cannot login after password reset',
        'tier'     => 'premium',
        'age_days' => 2,
        'tags'     => 'auth, reset, blocked',
    ])
    ->compress(CompressMode::BALANCED)
    ->prompt('Triage this support ticket: {{context}}. Assign priority and team.')
    ->schema(['priority', 'team', 'estimated_resolution_hours'])
    ->temperature(0.1)
    ->maxTokens(80)
    ->cache(ttl: 120)
    ->run();
```

---

### Content Moderation

```php
$result = TokenSqueezer::analyze()
    ->context([
        'text'        => substr($userComment, 0, 200),
        'user_age'    => 17,
        'platform'    => 'forum',
        'lang'        => 'id',
    ])
    ->compress(CompressMode::MINIMAL)
    ->schema(['safe', 'category', 'action'])
    ->temperature(0.0)
    ->maxTokens(60)
    ->via('claude')
    ->run();
```

---

### SEO / Content Scoring

```php
$result = TokenSqueezer::analyze()
    ->context([
        'title_len'    => 62,
        'meta_len'     => 148,
        'h1_count'     => 1,
        'word_count'   => 1450,
        'keyword_density' => 1.8,
        'readability'  => 'grade_8',
        'images_alt'   => false,
    ])
    ->compress(CompressMode::AGGRESSIVE)
    ->schema(['seo_score', 'issues', 'priority_fix'])
    ->temperature(0.1)
    ->maxTokens(120)
    ->cache(ttl: 3600)
    ->run();
```

---

### HR / Resume Screening

```php
$result = TokenSqueezer::analyze()
    ->context([
        'role'          => 'Backend Engineer',
        'years_exp'     => 4,
        'skills'        => 'PHP, Laravel, MySQL, Redis, Docker',
        'education'     => 'S1 Informatika',
        'english_level' => 'intermediate',
        'location'      => 'Jakarta',
        'salary_expect' => 15000000,
    ])
    ->compress(CompressMode::BALANCED)
    ->schema(['fit_score', 'strengths', 'gaps', 'proceed'])
    ->temperature(0.2)
    ->maxTokens(150)
    ->via('openai', 'gpt-4o-mini')
    ->run();
```

---

## Advanced Usage

### Custom System Prompt

```php
TokenSqueezer::analyze()
    ->context(['risk_score' => 87, 'country' => 'ID', 'amount' => 500_000_000])
    ->system('You are a fraud detection engine. Be conservative. Always return JSON.')
    ->schema(['fraud_likelihood', 'flags', 'block'])
    ->temperature(0.0)
    ->maxTokens(80)
    ->run();
```

### Custom Prompt with {{context}} Injection

```php
TokenSqueezer::analyze()
    ->context(['order_id' => 'ORD-9182', 'status' => 'pending', 'delay_days' => 4])
    ->prompt('Summarize this order situation: {{context}}. What should we tell the customer?')
    ->asText()      // plain text instead of JSON
    ->temperature(0.3)
    ->maxTokens(80)
    ->run();
```

### Inspect Without Calling AI (debug)

```php
$info = TokenSqueezer::analyze()
    ->context(['symbol' => 'ETH', 'rsi' => 68, 'trend' => 'bullish'])
    ->compress(CompressMode::AGGRESSIVE)
    ->schema(['trend', 'risk'])
    ->inspect();

// $info['estimated_reduction'] => "72% (from 124 to 35 chars)"
// $info['compressed_context']  => "SMBL=ETH|RSI=68|TRND=BULL"
// $info['prompt']              => [...]
```

### Custom Compressor Plugin

```php
use TokenSqueezer\Contracts\CompressorInterface;

class MyDomainCompressor implements CompressorInterface
{
    public function compress(array $context): string
    {
        // your custom logic
        return implode(' ', array_map(
            fn($k, $v) => strtoupper($k[0]) . ":{$v}",
            array_keys($context),
            $context
        ));
    }
}

TokenSqueezer::analyze()
    ->context($data)
    ->compress(CompressMode::CUSTOM)
    ->addCompressor(new MyDomainCompressor())
    ->schema(['result'])
    ->run();
```

### Custom Provider Driver

```php
use TokenSqueezer\Providers\ProviderFactory;

ProviderFactory::extend('deepseek', MyDeepSeekProvider::class);

TokenSqueezer::analyze()
    ->context($data)
    ->via('deepseek', 'deepseek-chat')
    ->run();
```

### Token Usage Monitoring

```php
// After multiple requests:
$usage = TokenSqueezer::usage();

// $usage:
// [
//   'total_requests'      => 42,
//   'cache_hits'          => 31,
//   'cache_hit_rate'      => '73.8%',
//   'total_input_tokens'  => 1840,
//   'total_output_tokens' => 430,
//   'avg_latency_ms'      => 487,
//   'estimated_cost_usd'  => '$0.0004',
//   'by_provider'         => ['openai' => [...], 'claude' => [...]],
// ]

TokenSqueezer::resetUsage();
```

---

## Compression Modes

| Mode         | What it does                                                  | Reduction |
|--------------|---------------------------------------------------------------|-----------|
| `MINIMAL`    | Normalize whitespace, stringify booleans                      | ~20%      |
| `BALANCED`   | + strip stopwords, abbreviate common phrases                  | ~50%      |
| `AGGRESSIVE` | + encode to shortcodes, max density, drop all prose           | ~75-80%   |
| `CUSTOM`     | Only your plugins run — full control                          | You decide |

---

## Cache TTL Guide

| Use Case           | Suggested TTL |
|--------------------|---------------|
| Real-time data     | 30–60 sec     |
| Per-request scores | 2–5 min       |
| Product catalog    | 10–30 min     |
| Static analysis    | 1–24 hours    |

---

## Supported Providers

| Provider  | Default Model         | Notes                      |
|-----------|-----------------------|----------------------------|
| `openai`  | `gpt-4o-mini`         | Cheapest, fastest           |
| `claude`  | `claude-haiku-4-5-*`  | Great for structured JSON   |
| `gemini`  | `gemini-1.5-flash`    | Free tier available         |
| `kimi`    | `moonshot-v1-8k`      | OpenAI-compatible           |
| `ollama`  | `llama3`              | Local, zero API cost        |
| custom    | Your driver           | Implement ProviderInterface |

---

## Environment Variables

```env
TSQ_PROVIDER=openai
TSQ_CACHE_ENABLED=true
TSQ_CACHE_DRIVER=laravel    # array | file | redis | laravel
TSQ_CACHE_TTL=300
TSQ_CACHE_PREFIX=tsq:
TSQ_MONITOR=true

OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o-mini

ANTHROPIC_API_KEY=...
CLAUDE_MODEL=claude-haiku-4-5-20251001

GEMINI_API_KEY=...
KIMI_API_KEY=...

OLLAMA_URL=http://localhost:11434/api/chat
OLLAMA_MODEL=llama3
```

---

## License

MIT
