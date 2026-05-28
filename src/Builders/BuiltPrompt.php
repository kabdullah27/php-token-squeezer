<?php

declare(strict_types=1);

namespace TokenSqueezer\Builders;

/**
 * Value object representing an assembled, ready-to-send prompt.
 */
readonly class BuiltPrompt
{
    public function __construct(
        public string $system,
        public string $user,
    ) {}

    /**
     * Convert to the messages array format used by most AI providers.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function toMessages(): array
    {
        $messages = [];

        if ($this->system) {
            $messages[] = ['role' => 'system', 'content' => $this->system];
        }

        $messages[] = ['role' => 'user', 'content' => $this->user];

        return $messages;
    }

    /**
     * Estimate token count (rough: 1 token ≈ 4 chars).
     */
    public function estimatedTokens(): int
    {
        return (int) ceil((strlen($this->system) + strlen($this->user)) / 4);
    }
}
