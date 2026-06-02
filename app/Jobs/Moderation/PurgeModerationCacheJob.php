<?php

namespace App\Jobs\Moderation;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bridges moderation decisions to the existing edge-cache machinery.
 * Reuses SyncSubdomainToKvJob — the canonical (and only) writer to SUBDOMAIN_KV.
 */
class PurgeModerationCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 60;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {
        // Enforcement action (takes a hidden site off the edge) — must not sit
        // behind a default-queue backlog. Queueable::$queue is untyped; assign in
        // constructor to avoid PHP 8.4 trait conflict.
        $this->queue = 'moderation_high';
    }

    public function handle(): void
    {
        $case  = ModerationCase::query()->findOrFail($this->caseId);
        $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);

        $entry->update(['status' => 'dispatched', 'dispatched_at' => now(), 'attempts' => $entry->attempts + 1]);

        if ($case->reportable_owner_user_id !== null) {
            SyncSubdomainToKvJob::dispatch($case->reportable_owner_user_id);
        }

        $entry->update(['status' => 'completed', 'completed_at' => now()]);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        ActionLogEntry::query()->where('id', $this->actionLogId)->update([
            'status'    => 'failed',
            'failed_at' => now(),
        ]);
        Log::error('Moderation enforcement job permanently failed', [
            'job'           => static::class,
            'action_log_id' => $this->actionLogId,
            'case_id'       => $this->caseId,
            'error'         => $e->getMessage(),
        ]);
    }
}
