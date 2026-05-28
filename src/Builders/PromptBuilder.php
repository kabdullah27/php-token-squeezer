<?php

declare(strict_types=1);

namespace TokenSqueezer\Builders;

use TokenSqueezer\Builders\BuiltPrompt;

/**
 * Assembles the compressed context + schema into a token-efficient prompt.
 */
class PromptBuilder
{
    public function __construct(
        protected string $compressed,
        protected array  $schema,
        protected string $format,
        protected string $system,
        protected string $user,
        protected array  $variables,
    ) {}

    public function build(): BuiltPrompt
    {
        $system = $this->resolveSystem();
        $user   = $this->resolveUser();

        return new BuiltPrompt($system, $user);
    }

    protected function resolveSystem(): string
    {
        if ($this->system) {
            return $this->interpolate($this->system);
        }

        $base = 'You are a concise AI analyst. Be precise. No explanations unless asked.';

        if ($this->format === 'json') {
            $keys = implode(', ', $this->schema);
            $base .= " Respond only with valid JSON containing: {$keys}. No extra text.";
        }

        return $base;
    }

    protected function resolveUser(): string
    {
        if ($this->user) {
            $prompt = $this->interpolate($this->user);
            return str_replace('{{context}}', $this->compressed, $prompt);
        }

        // Auto-build compact prompt
        $lines   = [];
        $lines[] = $this->compressed;

        if ($this->schema && $this->format === 'json') {
            $keys    = implode(', ', $this->schema);
            $lines[] = "Return JSON: {$keys}.";
        }

        return implode("\n", $lines);
    }

    protected function interpolate(string $template): string
    {
        foreach ($this->variables as $key => $value) {
            $template = str_replace("{{{$key}}}", (string) $value, $template);
        }
        return $template;
    }
}
