<?php

namespace App\Console\Commands;

use App\Models\Core\Gdpr\DataExportAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * P2-14: Reconcile DataExportAudit rows orphaned in the PROCESSING state.
 *
 * ExportUserDataJob has a 600s timeout and $tries = 3. Laravel's failed()
 * hook fires on retry exhaustion, but NOT on SIGKILL. A worker killed between
 * markProcessing() and completion leaves the audit row stuck in 'processing'
 * forever — the early-return guard in handle() then blocks all future retries.
 *
 * The 1-hour threshold safely exceeds the job's 600s timeout plus worst-case
 * backoff (60s + 300s), so any row still processing after an hour can only
 * exist because the worker died unexpectedly.
 */
class SweepStaleExportsCommand extends Command
{
    protected $signature = 'gdpr:sweep-stale-exports';

    protected $description = 'Fail DataExportAudit rows stuck in processing longer than 1 hour (worker-death recovery).';

    public function handle(): int
    {
        // 1-hour threshold: safely exceeds the job's 600s timeout + backoff
        // headroom. Any row older than this can only be stranded by a SIGKILL.
        $cutoff = now()->subHour();
        $swept = 0;

        DataExportAudit::query()
            ->where('status', DataExportAudit::STATUS_PROCESSING)
            ->where('created_at', '<', $cutoff)
            // cursor() keeps memory bounded if a pathological backlog
            // accumulates (e.g. repeated infrastructure incidents).
            ->cursor()
            ->each(function (DataExportAudit $audit) use (&$swept): void {
                // Reuse markFailed() so completed_at and error_message stay
                // consistent with the rest of the lifecycle. Do NOT hand-roll
                // a bulk UPDATE — it would skip the truncation and timestamp logic.
                $audit->markFailed('stale processing — worker death assumed');
                $swept++;
            });

        $this->info("Swept {$swept} stale export audit row(s) → failed.");

        Log::info('gdpr.sweep_stale_exports.complete', ['swept' => $swept]);

        return self::SUCCESS;
    }
}
