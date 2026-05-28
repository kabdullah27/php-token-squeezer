<?php

declare(strict_types=1);

namespace TokenSqueezer\Contracts;

interface CompressorInterface
{
    /**
     * Compress a flat key-value context array into a compact string.
     *
     * @param  array<string, mixed>  $context
     * @return string
     */
    public function compress(array $context): string;
}
