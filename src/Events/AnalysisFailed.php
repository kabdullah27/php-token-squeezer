<?php

declare(strict_types=1);

namespace TokenSqueezer\Events;

/**
 * Fired when all providers in the chain have failed.
 */
final class AnalysisFailed
{
    public function __construct(
        /**
         * Map of provider name => error message for every attempted provider.
         * @var array<string, string>
         */
        public readonly array $errors,
    ) {}
}
