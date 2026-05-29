<?php

namespace App\Console\Commands\Moderation;

use App\Jobs\Moderation\FileCyberTipReportJob;
use App\Models\Moderation\NcmecSubmission;
use Illuminate\Console\Command;

class ModerationRetryNcmecSubmissionsCommand extends Command
{
    protected $signature = 'moderation:retry-ncmec-submissions';
    protected $description = 'Re-dispatch FileCyberTipReportJob for submissions in pending or failed state under max attempts.';

    public function handle(): int
    {
        $maxAttempts = (int) config('partna.moderation.csam.ncmec_max_attempts', 5);

        // Also include 'submitting' rows that are stale (>10 min old) to recover
        // from crash-orphaned rows — FileCyberTipReportJob uses $tries=1 and the
        // write-before-attempt pattern, so a mid-call process death leaves the row
        // in 'submitting' with no other recovery path.
        $staleSubmittingCutoff = now()->subMinutes(10);

        $eligible = NcmecSubmission::query()
            ->where(function ($q) use ($maxAttempts, $staleSubmittingCutoff) {
                $q->whereIn('status', ['pending', 'failed'])
                  ->where('attempts', '<', $maxAttempts);
            })
            ->orWhere(function ($q) use ($staleSubmittingCutoff) {
                $q->where('status', 'submitting')
                  ->where('updated_at', '<', $staleSubmittingCutoff);
            })
            ->get(['id']);

        foreach ($eligible as $row) {
            FileCyberTipReportJob::dispatch($row->id);
        }

        $this->info("Dispatched {$eligible->count()} retries.");
        return self::SUCCESS;
    }
}
