<?php

declare(strict_types=1);

namespace TokenSqueezer\Events;

/**
 * Fired when a result is served from cache (no AI call made).
 */
final class CacheHit
{
    public function __construct(
        public readonly string $provider,
        public readonly string $cacheKey,
    ) {}
}
