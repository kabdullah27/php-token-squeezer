<?php

declare(strict_types=1);

namespace TokenSqueezer\Compressors;

use TokenSqueezer\Contracts\CompressorInterface;

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// 1. KeyValueCompressor
//    Converts a flat array into "key:value key:value" format
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class KeyValueCompressor implements CompressorInterface
{
    /** Keys to always skip (high-cardinality / useless for AI) */
    protected array $skip = ['id', 'created_at', 'updated_at', 'uuid'];

    public function compress(array $context): string
    {
        $parts = [];
        foreach ($context as $key => $value) {
            if (in_array($key, $this->skip, true)) {
                continue;
            }
            $k      = strtolower(str_replace(['_', '-', '.'], '', $key));
            $v      = $this->stringify($value);
            $parts[] = "{$k}:{$v}";
        }
        return implode(' ', $parts);
    }

    protected function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        }
        return (string) $value;
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// 2. StopwordStripper
//    Removes common English/Indonesian stopwords from values
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class StopwordStripper implements CompressorInterface
{
    protected array $stopwords = [
        // English
        'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
        'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
        'should', 'may', 'might', 'shall', 'can', 'need', 'dare', 'ought',
        'of', 'in', 'to', 'for', 'on', 'with', 'at', 'by', 'from', 'up',
        'about', 'into', 'through', 'during', 'before', 'after', 'above',
        'it', 'its', 'this', 'that', 'these', 'those', 'and', 'or', 'but',
        'very', 'just', 'also', 'now', 'then', 'when', 'where', 'which',
        // Indonesian
        'yang', 'dan', 'di', 'ke', 'dari', 'ini', 'itu', 'dengan', 'untuk',
        'adalah', 'ada', 'tidak', 'pada', 'oleh', 'atau', 'juga', 'sudah',
        'akan', 'bisa', 'dapat', 'lebih', 'seperti', 'karena', 'serta',
    ];

    public function compress(array $context): string
    {
        // StopwordStripper works on string input — accepts the previous output
        // In practice called via pipeline where input is already serialized
        return '';
    }

    /**
     * Strip stopwords from a pre-serialized string.
     */
    public function strip(string $text): string
    {
        $words    = explode(' ', $text);
        $filtered = array_filter($words, fn($w) => !in_array(strtolower($w), $this->stopwords, true));
        return implode(' ', array_values($filtered));
    }

    /**
     * CompressorInterface compatibility: runs on context as string conversion.
     */
    public function compressString(string $text): string
    {
        return $this->strip($text);
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// 3. PhraseAbbreviator
//    Replaces common multi-word phrases with short codes
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class PhraseAbbreviator implements CompressorInterface
{
    /**
     * Map: phrase → abbreviation
     * Domain-agnostic by default; extend via config.
     */
    protected array $abbreviations = [
        // Status
        'high risk'        => 'HR',
        'low risk'         => 'LR',
        'medium risk'      => 'MR',
        'no risk'          => 'NR',
        'high confidence'  => 'HC',
        'low confidence'   => 'LC',
        'not applicable'   => 'N/A',
        // Sentiment
        'very positive'    => 'V+',
        'positive'         => 'POS',
        'negative'         => 'NEG',
        'very negative'    => 'V-',
        'neutral'          => 'NEU',
        // Trend
        'upward trend'     => 'UP',
        'downward trend'   => 'DOWN',
        'sideways'         => 'SIDE',
        'bullish'          => 'BULL',
        'bearish'          => 'BEAR',
        'overbought'       => 'OB',
        'oversold'         => 'OS',
        // Actions
        'buy signal'       => 'BUY',
        'sell signal'      => 'SELL',
        'hold position'    => 'HOLD',
        'take profit'      => 'TP',
        'stop loss'        => 'SL',
        // Time
        'last 24 hours'    => '24h',
        'last 7 days'      => '7d',
        'last 30 days'     => '30d',
        'year to date'     => 'YTD',
        // E-commerce
        'free shipping'    => 'FS',
        'out of stock'     => 'OOS',
        'in stock'         => 'IS',
        'add to cart'      => 'ATC',
        // ML / AI
        'true positive'    => 'TP',
        'false positive'   => 'FP',
        'true negative'    => 'TN',
        'false negative'   => 'FN',
        'machine learning' => 'ML',
        'natural language' => 'NL',
    ];

    public function compress(array $context): string
    {
        return '';
    }

    public function abbreviate(string $text): string
    {
        foreach ($this->abbreviations as $phrase => $abbr) {
            $text = str_ireplace($phrase, $abbr, $text);
        }
        return $text;
    }

    /**
     * Add custom abbreviations at runtime.
     */
    public function addAbbreviations(array $map): static
    {
        $this->abbreviations = array_merge($this->abbreviations, array_change_key_case($map, CASE_LOWER));
        return $this;
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// 4. ShortcodeEncoder (AGGRESSIVE mode)
//    Encodes numeric values with units, strips padding, max density
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class ShortcodeEncoder implements CompressorInterface
{
    public function compress(array $context): string
    {
        $parts = [];
        foreach ($context as $key => $value) {
            $k = strtoupper(preg_replace('/[aeiou_\-\.]/i', '', $key) ?: $key);
            $k = substr($k, 0, 6);
            $v = $this->encode($value);
            $parts[] = "{$k}={$v}";
        }
        return implode('|', $parts);
    }

    protected function encode(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'T' : 'F';
        }
        if (is_float($value)) {
            return (string) round($value, 2);
        }
        // Abbreviate long strings
        if (is_string($value) && strlen($value) > 20) {
            return substr($value, 0, 8) . '..';
        }
        return (string) $value;
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// 5. StateEncoder (domain plugin)
//    For state-machine outputs — maps state arrays to single labels
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class StateEncoder implements CompressorInterface
{
    public function __construct(protected array $stateMap = []) {}

    public function compress(array $context): string
    {
        $parts = [];
        foreach ($context as $key => $value) {
            $strVal = (string) $value;
            $encoded = $this->stateMap[$strVal] ?? $strVal;
            $parts[] = "{$key}:{$encoded}";
        }
        return implode(' ', $parts);
    }
}
