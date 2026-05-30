<?php

declare(strict_types=1);

namespace TokenSqueezer\Parsers;

use TokenSqueezer\Exceptions\ParseException;

/**
 * Parses and validates AI response content.
 *
 * - JSON mode: extracts JSON even if wrapped in markdown code fences
 * - Text mode: returns cleaned plain text
 * - Schema validation: warns if expected keys are missing
 */
class ResponseParser
{
    public function __construct(
        protected string $format,
        protected array  $schema,
    ) {}

    /**
     * Parse raw AI response string into structured output.
     *
     * @return array|string
     */
    public function parse(string $raw): array|string
    {
        $cleaned = $this->clean($raw);

        if ($this->format === 'json') {
            return $this->parseJson($cleaned);
        }

        return $cleaned;
    }

    protected function clean(string $raw): string
    {
        // Strip markdown code fences
        $raw = preg_replace('/^```(?:json)?\n?/m', '', $raw);
        $raw = preg_replace('/```\s*$/m', '', $raw);
        return trim($raw);
    }

    protected function parseJson(string $raw): array
    {
        // Strip control characters (like raw newlines inside strings, BOMs, or \r) that break json_decode
        $cleanRaw = preg_replace('/[\x00-\x1F\x7F]/', '', $raw);
        if (empty($cleanRaw)) {
            $cleanRaw = $raw;
        }

        // Try direct decode on cleaned raw
        $decoded = json_decode($cleanRaw, true);
        if (is_array($decoded)) {
            return $this->validate($decoded);
        }

        // Try to extract JSON substring on cleaned raw
        if (preg_match('/\{.*\}/s', $cleanRaw, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $this->validate($decoded);
            }
        }

        // Fallback: build from text
        return $this->buildFallback($raw);
    }

    protected function validate(array $data): array
    {
        if (!$this->schema) {
            return $data;
        }

        // Fill missing keys with null
        foreach ($this->schema as $key) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = null;
            }
        }

        // Only return schema keys (strip unexpected keys)
        return array_intersect_key($data, array_flip($this->schema));
    }

    protected function buildFallback(string $raw): array
    {
        $result = [];
        foreach ($this->schema as $key) {
            $result[$key] = null;
        }
        $result['_raw'] = $raw;
        $result['_error'] = 'Could not parse JSON response';
        return $result;
    }
}
