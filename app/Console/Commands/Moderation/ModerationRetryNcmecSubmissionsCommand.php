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

        $eligible = NcmecSubmission::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', $maxAttempts)
            ->get(['id']);

        foreach ($eligible as $row) {
            FileCyberTipReportJob::dispatch($row->id);
        }

        $this->info("Dispatched {$eligible->count()} retries.");
        return self::SUCCESS;
    }
}
