<?php

declare(strict_types=1);

namespace TokenSqueezer\Laravel\Commands;

use Illuminate\Console\Command;
use TokenSqueezer\Cache\SmartCache;

/**
 * Clear TokenSqueezer cache entries.
 *
 *   php artisan tsq:cache:clear              # clears file + laravel cache
 *   php artisan tsq:cache:clear --driver=file
 */
class CacheClearCommand extends Command
{
    protected $signature = 'tsq:cache:clear
                            {--driver= : Specific driver to clear: file|laravel|all (default: all)}';

    protected $description = 'Clear TokenSqueezer cached AI responses';

    public function handle(): int
    {
        $driver = $this->option('driver') ?? 'all';
        $config = config('token-squeezer.cache', []);
        $prefix = $config['prefix'] ?? 'tsq:';

        $cleared = 0;

        if (in_array($driver, ['all', 'file'])) {
            $cleared += $this->clearFileCache();
        }

        if (in_array($driver, ['all', 'laravel'])) {
            $cleared += $this->clearLaravelCache($prefix);
        }

        $this->info("✅ TokenSqueezer cache cleared. ({$cleared} entries removed)");

        return self::SUCCESS;
    }

    protected function clearFileCache(): int
    {
        $dir   = sys_get_temp_dir() . '/token-squeezer-cache';
        $count = 0;

        if (!is_dir($dir)) {
            return 0;
        }

        foreach (glob("{$dir}/*.cache") ?: [] as $file) {
            @unlink($file);
            $count++;
        }

        if ($count > 0) {
            $this->line("  <comment>file</comment>: removed {$count} cache file(s) from {$dir}");
        }

        return $count;
    }

    protected function clearLaravelCache(string $prefix): int
    {
        // Laravel's Cache facade does not support prefix-based deletion on all drivers.
        // We flush the known fixed keys used by the package.
        $keys  = ['tsq:usage_agg'];  // persistent stats key
        $count = 0;

        foreach ($keys as $key) {
            if (\Illuminate\Support\Facades\Cache::forget($key)) {
                $count++;
            }
        }

        if ($count > 0) {
            $this->line("  <comment>laravel</comment>: removed {$count} cache key(s)");
        }

        return $count;
    }
}
