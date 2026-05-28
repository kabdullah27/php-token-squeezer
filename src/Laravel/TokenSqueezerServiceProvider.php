<?php

declare(strict_types=1);

namespace TokenSqueezer\Laravel;

use Illuminate\Support\ServiceProvider;
use TokenSqueezer\TokenSqueezer;

class TokenSqueezerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/token-squeezer.php', 'token-squeezer');

        $this->app->singleton('token-squeezer', function ($app) {
            $config = $app['config']['token-squeezer'] ?? [];
            TokenSqueezer::configure($config);
            return TokenSqueezer::class;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/token-squeezer.php' => config_path('token-squeezer.php'),
            ], 'token-squeezer-config');
        }
    }
}
