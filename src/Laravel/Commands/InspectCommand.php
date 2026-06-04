<?php

declare(strict_types=1);

namespace TokenSqueezer\Laravel\Commands;

use Illuminate\Console\Command;
use TokenSqueezer\TokenSqueezer;
use TokenSqueezer\CompressMode;

/**
 * Dry-run analysis: show compressed context and built prompt without calling AI.
 *
 *   php artisan tsq:inspect --context='{"symbol":"BTC","rsi":74}'
 *   php artisan tsq:inspect --context='...' --provider=openai --mode=aggressive
 */
class InspectCommand extends Command
{
    protected $signature = 'tsq:inspect
                            {--context=  : JSON string of the context to inspect}
                            {--provider= : Provider name (default: from config)}
                            {--mode=     : Compression mode: minimal|balanced|aggressive|rtk (default: balanced)}
                            {--schema=   : Comma-separated expected output keys, e.g. trend,risk}';

    protected $description = 'Dry-run: inspect compressed context and prompt without calling AI';

    public function handle(): int
    {
        $contextJson = $this->option('context');
        if (!$contextJson) {
            $this->error('Please provide --context as a JSON string.');
            $this->line('  Example: php artisan tsq:inspect --context=\'{"symbol":"BTC","rsi":74}\'');
            return self::FAILURE;
        }

        $context = json_decode($contextJson, true);
        if (!is_array($context)) {
            $this->error('Invalid JSON for --context.');
            return self::FAILURE;
        }

        // Build mode
        $modeValue = $this->option('mode') ?? 'balanced';
        $mode      = CompressMode::tryFrom($modeValue);
        if (!$mode) {
            $this->error("Unknown mode [{$modeValue}]. Options: minimal, balanced, aggressive, rtk");
            return self::FAILURE;
        }

        // Schema
        $schemaRaw = $this->option('schema');
        $schema    = $schemaRaw ? array_map('trim', explode(',', $schemaRaw)) : [];

        // Build and run inspect
        $builder = TokenSqueezer::analyze()
            ->context($context)
            ->compress($mode);

        if ($this->option('provider')) {
            $builder->via($this->option('provider'));
        }
        if ($schema) {
            $builder->schema($schema);
        }

        $info = $builder->inspect();

        $this->components->info('TokenSqueezer — Inspect Result');

        $this->table(
            ['Field', 'Value'],
            [
                ['Provider',            $info['provider']],
                ['Mode',                $modeValue],
                ['Compression',         $info['estimated_reduction']],
                ['Compressed Context',  $info['compressed_context']],
                ['Fallback Chain',      implode(' → ', $info['fallback_chain'] ?: ['(none)'])],
                ['Temperature',         $info['temperature']],
                ['Max Tokens',          $info['max_tokens']],
            ]
        );

        $this->newLine();
        $this->components->info('Built Prompt (messages)');
        foreach ($info['prompt'] as $msg) {
            $role    = strtoupper($msg['role']);
            $content = mb_strimwidth($msg['content'], 0, 300, '…');
            $this->line("  <comment>[{$role}]</comment> {$content}");
        }

        return self::SUCCESS;
    }
}
