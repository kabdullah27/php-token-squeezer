<?php

declare(strict_types=1);

namespace TokenSqueezer;

/**
 * Compression aggressiveness levels.
 *
 * MINIMAL    — Light cleanup: remove extra whitespace, normalize quotes.
 * BALANCED   — Also removes stopwords, shortens common phrases.
 * AGGRESSIVE — Converts everything to short-codes, drops all prose.
 * CUSTOM     — Use your own Compressor pipeline.
 */
enum CompressMode: string
{
    case MINIMAL    = 'minimal';
    case BALANCED   = 'balanced';
    case AGGRESSIVE = 'aggressive';
    case RTK        = 'rtk';
    case CUSTOM     = 'custom';
}
