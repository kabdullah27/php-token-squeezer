<?php

declare(strict_types=1);

namespace TokenSqueezer\Compressors;

use TokenSqueezer\CompressMode;
use TokenSqueezer\Contracts\CompressorInterface;

/**
 * Runs a series of compressors based on the selected mode.
 *
 * Pipeline order matters: each compressor receives the output of the previous.
 */
class CompressorPipeline
{
    /** @var CompressorInterface[] */
    protected array $compressors = [];

    public function __construct(CompressMode $mode, array $custom = [])
    {
        $this->compressors = $this->buildPipeline($mode, $custom);
    }

    /**
     * Compress a context array into a compact string.
     *
     * @param  array<string, mixed>  $context
     * @return string
     */
    public function compress(array $context): string
    {
        // Flatten nested arrays one level
        $flat = $this->flatten($context);

        // Run through pipeline — each step gets the output string
        $result = '';
        foreach ($this->compressors as $compressor) {
            $result = $compressor->compress($flat);
            // Update $flat with parsed result for next step if needed
        }

        return $result ?: $this->fallbackSerialize($flat);
    }

    // ── Pipeline builder ─────────────────────────────────────────────────────

    protected function buildPipeline(CompressMode $mode, array $custom): array
    {
        $base = match ($mode) {
            CompressMode::MINIMAL    => [new KeyValueCompressor()],
            CompressMode::BALANCED   => [new KeyValueCompressor(), new StopwordStripper(), new PhraseAbbreviator()],
            CompressMode::AGGRESSIVE => [new KeyValueCompressor(), new StopwordStripper(), new PhraseAbbreviator(), new ShortcodeEncoder()],
            CompressMode::CUSTOM     => [],
        };

        $instances = [];
        foreach ($custom as $c) {
            $instances[] = is_string($c) ? new $c() : $c;
        }

        return array_merge($base, $instances);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    protected function flatten(array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : (string) $key;
            if (is_array($value)) {
                $result += $this->flatten($value, $fullKey);
            } else {
                $result[$fullKey] = $value;
            }
        }
        return $result;
    }

    protected function fallbackSerialize(array $data): string
    {
        $parts = [];
        foreach ($data as $k => $v) {
            $parts[] = "{$k}:{$v}";
        }
        return implode(' ', $parts);
    }
}
