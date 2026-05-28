<?php

declare(strict_types=1);

namespace TokenSqueezer\Contracts;

interface ProviderInterface
{
    /**
     * Send a completion request to the AI provider.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  float  $temperature
     * @param  int    $maxTokens
     * @return array{content: string, usage: array{input_tokens: int, output_tokens: int}}
     */
    public function complete(array $messages, float $temperature, int $maxTokens): array;

    /**
     * Return the provider name identifier.
     */
    public function name(): string;
}
