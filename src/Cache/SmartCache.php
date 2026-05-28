<?php

declare(strict_types=1);

namespace TokenSqueezer\Cache;

/**
 * Context-aware cache with auto-generated keys.
 *
 * Drivers:
 *   array — in-memory (per request, useful for tests)
 *   file  — disk-based (no Redis needed)
 *   redis — Redis via predis/predis (install separately)
 *   laravel — delegates to Laravel's Cache facade (auto-detected)
 */
class SmartCache
{
    protected string $driver;
    protected string $prefix;
    protected int    $defaultTtl;
    protected array  $store = []; // for array driver

    public function __construct(array $config)
    {
        $this->driver     = $config['driver']  ?? 'array';
        $this->prefix     = $config['prefix']  ?? 'tsq:';
        $this->defaultTtl = $config['ttl']     ?? 300;

        if ($this->driver === 'laravel' && !class_exists(\Illuminate\Support\Facades\Cache::class)) {
            $this->driver = 'array';
        }
    }

    public function get(string $key): mixed
    {
        $key = $this->prefix . $key;

        return match ($this->driver) {
            'array'   => $this->arrayGet($key),
            'file'    => $this->fileGet($key),
            'redis'   => $this->redisGet($key),
            'laravel' => \Illuminate\Support\Facades\Cache::get($key),
            default   => null,
        };
    }

    public function put(string $key, mixed $value, ?int $ttl = null): void
    {
        $key = $this->prefix . $key;
        $ttl = $ttl ?? $this->defaultTtl;

        match ($this->driver) {
            'array'   => $this->arrayPut($key, $value, $ttl),
            'file'    => $this->filePut($key, $value, $ttl),
            'redis'   => $this->redisPut($key, $value, $ttl),
            'laravel' => \Illuminate\Support\Facades\Cache::put($key, $value, $ttl),
            default   => null,
        };
    }

    public function forget(string $key): void
    {
        $key = $this->prefix . $key;
        match ($this->driver) {
            'array'   => (function () use ($key) { unset($this->store[$key]); })(),
            'file'    => $this->fileDelete($key),
            'laravel' => \Illuminate\Support\Facades\Cache::forget($key),
            default   => null,
        };
    }

    /**
     * Auto-generate a deterministic cache key from provider + context + schema.
     */
    public function generateKey(string $provider, string $compressedCtx, array $schema): string
    {
        return md5($provider . $compressedCtx . implode(',', $schema));
    }

    // ── Array Driver ─────────────────────────────────────────────────────────

    protected function arrayGet(string $key): mixed
    {
        $entry = $this->store[$key] ?? null;
        if (!$entry) return null;
        if (time() > $entry['expires']) {
            unset($this->store[$key]);
            return null;
        }
        return $entry['value'];
    }

    protected function arrayPut(string $key, mixed $value, int $ttl): void
    {
        $this->store[$key] = ['value' => $value, 'expires' => time() + $ttl];
    }

    // ── File Driver ──────────────────────────────────────────────────────────

    protected function cacheDir(): string
    {
        $dir = sys_get_temp_dir() . '/token-squeezer-cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    protected function filePath(string $key): string
    {
        return $this->cacheDir() . '/' . md5($key) . '.cache';
    }

    protected function fileGet(string $key): mixed
    {
        $path = $this->filePath($key);
        if (!file_exists($path)) return null;
        $data = unserialize(file_get_contents($path));
        if (!$data || time() > $data['expires']) {
            @unlink($path);
            return null;
        }
        return $data['value'];
    }

    protected function filePut(string $key, mixed $value, int $ttl): void
    {
        file_put_contents(
            $this->filePath($key),
            serialize(['value' => $value, 'expires' => time() + $ttl])
        );
    }

    protected function fileDelete(string $key): void
    {
        @unlink($this->filePath($key));
    }

    // ── Redis Driver ─────────────────────────────────────────────────────────

    protected ?\Redis $redis = null;

    protected function redisClient(): \Redis
    {
        if (!$this->redis) {
            $this->redis = new \Redis();
            $this->redis->connect(
                $this->driver['host'] ?? '127.0.0.1',
                $this->driver['port'] ?? 6379,
            );
        }
        return $this->redis;
    }

    protected function redisGet(string $key): mixed
    {
        $val = $this->redisClient()->get($key);
        return $val !== false ? unserialize($val) : null;
    }

    protected function redisPut(string $key, mixed $value, int $ttl): void
    {
        $this->redisClient()->setex($key, $ttl, serialize($value));
    }
}
