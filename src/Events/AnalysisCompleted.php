<?php

declare(strict_types=1);

namespace TokenSqueezer\Events;

/**
 * Fired after a successful AI analysis (including fallback providers).
 */
final class AnalysisCompleted
{
    public function __construct(
        /** The provider that successfully responded */
        public readonly string       $provider,
        /** Parsed result returned to the caller */
        public readonly array|string $result,
        public readonly int          $inputTokens,
        public readonly int          $outputTokens,
        public readonly int          $latencyMs,
        /** true when the result was served from cache */
        public readonly bool         $fromCache = false,
    ) {}
}
