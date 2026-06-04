<?php

declare(strict_types=1);

namespace TokenSqueezer\Events;

/**
 * Fired when a TokenSqueezer::batch()->run() call completes.
 */
final class BatchCompleted
{
    public function __construct(
        public readonly int   $total,
        public readonly int   $succeeded,
        public readonly int   $failed,
        /** Wall-clock duration for the entire batch in milliseconds */
        public readonly int   $durationMs,
    ) {}
}
