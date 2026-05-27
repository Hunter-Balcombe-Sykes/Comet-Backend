<?php

namespace App\Jobs\Moderation;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyStaffOfCaseUpdateJob implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $caseId) {}

    public function handle(): void
    {
        // Stub: full implementation in Task 17 (threshold gating + notification dispatch).
        // Day-one: no-op so dispatch contracts can be tested.
    }
}
