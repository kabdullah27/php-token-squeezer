<?php

declare(strict_types=1);

namespace TokenSqueezer\Laravel\Commands;

use Illuminate\Console\Command;
use TokenSqueezer\TokenSqueezer;

/**
 * Show accumulated TokenSqueezer usage statistics.
 *
 *   php artisan tsq:usage
 *   php artisan tsq:usage --reset
 */
class UsageCommand extends Command
{
    protected $signature = 'tsq:usage
                            {--reset : Wipe the accumulated stats and exit}';

    protected $description = 'Show TokenSqueezer token usage and cost statistics';

    public function handle(): int
    {
        if ($this->option('reset')) {
            TokenSqueezer::resetPersistentUsage();
            $this->info('✅ TokenSqueezer persistent stats have been reset.');
            return self::SUCCESS;
        }

        $stats = TokenSqueezer::persistentUsage();

        if (empty($stats)) {
            $this->warn('No persistent stats found.');
            $this->line('  • Make sure <comment>TSQ_MONITOR=true</comment> is set.');
            $this->line('  • Stats are accumulated after each request.');
            return self::SUCCESS;
        }

        $this->components->info('TokenSqueezer — Accumulated Usage Stats');

        // Summary table
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Requests',       number_format($stats['total_requests'])],
                ['Total Input Tokens',   number_format($stats['total_input_tokens'])],
                ['Total Output Tokens',  number_format($stats['total_output_tokens'])],
                ['Total Cost (est.)',    '$' . number_format($stats['total_cost_usd'], 4)],
                ['First Seen',           $stats['first_seen'] ?? '—'],
                ['Last Seen',            $stats['last_seen']  ?? '—'],
            ]
        );

        // Per-provider table
        if (!empty($stats['by_provider'])) {
            $this->newLine();
            $this->components->info('By Provider');
            $rows = [];
            foreach ($stats['by_provider'] as $provider => $data) {
                $rows[] = [
                    ucfirst($provider),
                    number_format($data['requests']),
                    number_format($data['input_tokens']),
                    number_format($data['output_tokens']),
                    '$' . number_format($data['cost_usd'], 4),
                ];
            }
            $this->table(
                ['Provider', 'Requests', 'Input Tokens', 'Output Tokens', 'Cost (est.)'],
                $rows
            );
        }

        $this->newLine();
        $this->line('  Run <comment>php artisan tsq:usage --reset</comment> to clear stats.');

        return self::SUCCESS;
    }
}
