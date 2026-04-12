<?php

namespace App\Console\Commands;

use App\Services\PaymentPipelineService;
use Illuminate\Console\Command;

class PaymentRun extends Command
{
    protected $signature = 'payment:run {--file= : Path to input file}';

    protected $description = 'Run the payment pipeline simulation';

    public function handle(PaymentPipelineService $pipeline): int
    {
        $this->line('╔══════════════════════════════════════════════════════════════╗');
        $this->line('║              Payment Pipeline Simulation v1.0                ║');
        $this->line('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $this->line('  Available Commands:');
        $this->line('  ─────────────────────────────────────────────────────────────');
        $this->line('  CREATE <payment_id> <amount> <currency> <merchant_id>');
        $this->line('  AUTHORIZE <payment_id>');
        $this->line('  CAPTURE <payment_id>');
        $this->line('  VOID <payment_id> [reason_code]');
        $this->line('  REFUND <payment_id> [amount]          (omit amount = full refund)');
        $this->line('  SETTLE <payment_id>');
        $this->line('  SETTLEMENT <batch_id>');
        $this->line('  STATUS <payment_id>');
        $this->line('  LIST');
        $this->line('  AUDIT <payment_id>');
        $this->line('  EXIT');
        $this->newLine();

        $this->line('  Notes:');
        $this->line('  • Amounts use fixed precision e.g. 100.00');
        $this->line('  • Supported currencies: MYR, USD, SGD, EUR, GBP');
        $this->line('  • Payments above 500 trigger PRE_SETTLEMENT_REVIEW automatically');
        $this->line('  • You can add inline comments after the 3rd token e.g. CREATE P1 10.00 MYR M1 # note');
        $this->newLine();

        $this->line('  ─────────────────────────────────────────────────────────────');
        $this->newLine();

        $file = $this->option('file');

        if ($file !== null) {
            return $this->runFromFile($pipeline, $file);
        }

        return $this->runFromStdin($pipeline);
    }

    private function runFromFile(PaymentPipelineService $pipeline, string $file): int
    {
        if (! file_exists($file)) {
            $this->line("[ERROR] File not found: {$file}");

            return self::FAILURE;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $line) {
            if ($this->processLine($pipeline, $line)) {
                break;
            }
        }

        return self::SUCCESS;
    }

    private function runFromStdin(PaymentPipelineService $pipeline): int
    {
        $this->line('Enter commands (type EXIT to quit):');
        $this->newLine();

        $stdin = fopen('php://stdin', 'r');

        try {
            while (($line = fgets($stdin)) !== false) {
                if ($this->processLine($pipeline, rtrim($line, "\n"))) {
                    break;
                }
            }
        } finally {
            fclose($stdin);
        }

        return self::SUCCESS;
    }

    /**
     * Process one line. Returns true if the loop should stop (EXIT sentinel).
     */
    private function processLine(PaymentPipelineService $pipeline, string $line): bool
    {
        try {
            $output = $pipeline->handle($line);
        } catch (\Throwable $e) {
            $this->line('[ERROR] Unexpected error: '.$e->getMessage());

            return false;
        }

        if ($output === null) {
            return false;
        }

        if ($output === '__EXIT__') {
            $this->line('Goodbye.');

            return true;
        }

        $this->line($output);

        return false;
    }
}
