<?php

declare(strict_types=1);

namespace TokenSqueezer\Exceptions;

class TokenSqueezedException extends \RuntimeException {}
class ParseException extends TokenSqueezedException {}
class ProviderException extends TokenSqueezedException {}
class CacheException extends TokenSqueezedException {}

/**
 * Thrown when a provider's rate limit is exceeded.
 * Contains the provider name and seconds until reset.
 */
class RateLimitException extends TokenSqueezedException
{
    public function __construct(
        string $provider,
        public readonly int $retryAfterSeconds = 0,
    ) {
        parent::__construct("Rate limit exceeded for provider [{$provider}]. Retry after {$retryAfterSeconds}s.");
    }
}

/**
 * Thrown when all providers in the fallback chain have failed.
 * Contains the list of errors per provider.
 */
class FallbackExhaustedException extends TokenSqueezedException
{
    /** @param array<string, string> $errors provider => error message */
    public function __construct(public readonly array $errors)
    {
        $summary = implode('; ', array_map(
            fn($p, $e) => "[{$p}]: {$e}",
            array_keys($errors),
            $errors
        ));
        parent::__construct("All providers failed. Errors: {$summary}");
    }
}
