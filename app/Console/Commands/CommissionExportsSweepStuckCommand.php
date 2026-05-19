<?php

namespace App\Console\Commands;

use App\Models\Commerce\CommissionExportAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CommissionExportsSweepStuckCommand extends Command
{
    protected $signature = 'commission-exports:sweep-stuck';
    protected $description = 'Mark commission export audits stuck in processing as failed.';

    public function handle(): int
    {
        $threshold = (int) config('partna.exports.commission.stuck_watchdog_minutes', 60);
        $count = 0;

        CommissionExportAudit::stuckInProcessing($threshold)->chunk(100, function ($rows) use (&$count, $threshold) {
            foreach ($rows as $audit) {
                $audit->markFailed('processing watchdog timeout');
                Log::warning('commission_export.swept_stuck', [
                    'audit_id' => $audit->id,
                    'age_minutes' => $audit->processing_at?->diffInMinutes(now()) ?? null,
                    'threshold_minutes' => $threshold,
                ]);
                $count++;
            }
        });

        $this->info("Swept {$count} stuck export(s) → failed.");
        return self::SUCCESS;
    }
}
