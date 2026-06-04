# Artisan Commands

TokenSqueezer ships 3 Artisan commands for debugging, monitoring, and cache management.

> **Requires Laravel.** Commands are auto-registered via package discovery — no extra setup needed.

---

## `tsq:usage` — Token Usage Stats

Shows accumulated token usage and estimated cost across all requests.

```bash
php artisan tsq:usage
```

**Output example:**

```
 INFO  TokenSqueezer — Accumulated Usage Stats

 ┌───────────────────────┬──────────────┐
 │ Metric                │ Value        │
 ├───────────────────────┼──────────────┤
 │ Total Requests        │ 1,240        │
 │ Total Input Tokens    │ 86,400       │
 │ Total Output Tokens   │ 21,600       │
 │ Total Cost (est.)     │ $0.0259      │
 │ First Seen            │ 2025-06-01…  │
 │ Last Seen             │ 2025-06-04…  │
 └───────────────────────┴──────────────┘

 INFO  By Provider

 ┌──────────┬──────────┬──────────────┬───────────────┬─────────────┐
 │ Provider │ Requests │ Input Tokens │ Output Tokens │ Cost (est.) │
 ├──────────┼──────────┼──────────────┼───────────────┼─────────────┤
 │ Openai   │ 1,100    │ 77,000       │ 19,250        │ $0.0231     │
 │ Claude   │ 140      │ 9,800        │ 2,450         │ $0.0028     │
 └──────────┴──────────┴──────────────┴───────────────┴─────────────┘
```

### How stats are collected

Stats are accumulated automatically per-request via the `AnalysisCompleted` event.
They persist in Laravel cache under the key `tsq:usage_agg` for 30 days.

### Reset stats

```bash
php artisan tsq:usage --reset
```

---

## `tsq:inspect` — Dry-run Inspect

Preview how your context gets compressed and what prompt is sent — **without calling any AI provider**.

```bash
php artisan tsq:inspect --context='{"symbol":"BTC","rsi":74,"trend":"bullish"}'
```

**With options:**

```bash
php artisan tsq:inspect \
  --context='{"symbol":"BTC","rsi":74,"trend":"bullish"}' \
  --provider=claude \
  --mode=aggressive \
  --schema=trend,risk,action
```

### Options

| Option | Default | Description |
|---|---|---|
| `--context` | *(required)* | JSON string of your context |
| `--provider` | From config | Provider name (`openai`, `claude`, etc.) |
| `--mode` | `balanced` | Compression mode: `minimal`, `balanced`, `aggressive`, `rtk` |
| `--schema` | *(none)* | Comma-separated expected output keys |

**Output example:**

```
 INFO  TokenSqueezer — Inspect Result

 ┌─────────────────────┬────────────────────────────────────────┐
 │ Field               │ Value                                  │
 ├─────────────────────┼────────────────────────────────────────┤
 │ Provider            │ openai                                 │
 │ Mode                │ aggressive                             │
 │ Compression         │ 68% (from 47 to 15 chars)              │
 │ Compressed Context  │ SMBL=BTC|RSI=74|TRND=BULL              │
 │ Fallback Chain      │ (none)                                 │
 │ Temperature         │ 0.1                                    │
 │ Max Tokens          │ 120                                    │
 └─────────────────────┴────────────────────────────────────────┘

 INFO  Built Prompt (messages)

  [SYSTEM] You are a concise AI analyst. Be precise…
  [USER]   SMBL=BTC|RSI=74|TRND=BULL
           Return JSON: trend, risk, action.
```

### Use cases

- Debug why compression is too aggressive / not enough
- Verify prompt structure before going to production
- Estimate token count without spending API credits
- Check provider and fallback chain config

---

## `tsq:cache:clear` — Clear Cache

Remove TokenSqueezer cached responses and stats.

```bash
# Clear everything (file cache + Laravel cache keys)
php artisan tsq:cache:clear

# Clear only file cache
php artisan tsq:cache:clear --driver=file

# Clear only Laravel cache keys
php artisan tsq:cache:clear --driver=laravel
```

**Output example:**

```
  file: removed 42 cache file(s) from /tmp/token-squeezer-cache
  laravel: removed 1 cache key(s)
✅ TokenSqueezer cache cleared. (43 entries removed)
```

### What gets cleared

| Driver | What's removed |
|---|---|
| `file` | All `.cache` files in `/tmp/token-squeezer-cache/` |
| `laravel` | `tsq:usage_agg` (persistent stats key) |

> **Note:** Redis-backed and array-backed cached AI responses are scoped by hash key and will expire automatically per their configured TTL. Use `tsq:cache:clear --driver=laravel` to also wipe the stats aggregate.

---

## Summary

| Command | Purpose |
|---|---|
| `php artisan tsq:usage` | View accumulated token/cost stats |
| `php artisan tsq:usage --reset` | Wipe accumulated stats |
| `php artisan tsq:inspect --context='{...}'` | Dry-run: see compressed prompt |
| `php artisan tsq:cache:clear` | Clear cached responses + stats |
