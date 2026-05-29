<?php

namespace App\Jobs\Moderation;

use App\Models\Moderation\NcmecSubmission;
use App\Services\Moderation\NcmecSubmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Single-try job — outbox row drives all retry orchestration.
 *
 * $tries=1 is intentional: the job performs exactly one attempt and marks the
 * outbox row accordingly. Subsequent attempts come from
 * ModerationRetryNcmecSubmissionsCommand (Task 16), which re-dispatches a fresh
 * job instance. This ensures the outbox row is always the source of truth and
 * Horizon's built-in retry never races against the scheduled command.
 */
class FileCyberTipReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Outbox drives retry — one try per dispatch. See class docblock. */
    public int $tries = 1;

    public int $timeout = 60;

    /**
     * $tries=1 means this array is never consulted, but JobHygienePolicyTest
     * requires every ShouldQueue job to declare $backoff.
     */
    public array $backoff = [60];

    public function __construct(public readonly string $submissionId)
    {
        // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
        $this->queue = 'moderation_high';
    }

    public function handle(NcmecSubmissionService $service): void
    {
        $sub = NcmecSubmission::query()->find($this->submissionId);
        if ($sub === null) {
            return;
        }

        // Idempotency guard — already done or permanently failed.
        if (in_array($sub->status, ['submitted', 'manual_fallback_required'], true)) {
            return;
        }

        $service->submit($sub);
    }
}
