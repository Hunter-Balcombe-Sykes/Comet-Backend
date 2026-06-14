<?php

namespace App\Jobs\Moderation;

use App\Jobs\Moderation\Concerns\HasActionLogLifecycle;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\AccountBannedNotification;
use App\Notifications\Moderation\AccountSuspendedNotification;
use App\Notifications\Moderation\ContentHiddenNotification;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotifyReportedUserJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use HasActionLogLifecycle;

    public int $timeout = 60;

    // 5-min lock expiry so a crashed worker can't hold the lock forever.
    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {
        // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
        $this->queue = 'notifications';
    }

    public function uniqueId(): string
    {
        return $this->actionLogId;
    }

    public function handle(): void
    {
        $case = ModerationCase::query()->findOrFail($this->caseId);
        $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);
        // Idempotency — a retry after success must not re-send.
        if ($entry->status === 'completed') {
            return;
        }
        $this->markDispatched($entry);

        // No owner on record — nothing to notify, still mark complete.
        if ($case->reportable_owner_user_id === null) {
            $this->markCompleted($entry);

            return;
        }

        $user = User::query()->find($case->reportable_owner_user_id);
        if ($user === null) {
            $this->markCompleted($entry);

            return;
        }

        // Fail-closed: don't notify users who've been suspended/banned (capability gate).
        if (! AccountCapabilities::for($user)->receive_moderation_notifications) {
            $this->markCompleted($entry);

            return;
        }

        // Load the most recent decision to determine which notification to send.
        $decision = $case->decisions()->latest('decided_at')->firstOrFail();

        $notification = match ($decision->decision_type) {
            'hide_content', 'hide_site' => new ContentHiddenNotification($decision),
            'suspend_user' => new AccountSuspendedNotification($decision),
            'ban_user' => new AccountBannedNotification($decision),
            default => null,
        };

        if ($notification !== null) {
            $user->notify($notification);
        }

        $this->markCompleted($entry);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        ActionLogEntry::query()->where('id', $this->actionLogId)->update([
            'status' => 'failed',
            'failed_at' => now(),
        ]);
        Log::error('Moderation notification job permanently failed', [
            'job' => static::class,
            'action_log_id' => $this->actionLogId,
            'case_id' => $this->caseId,
            'error' => $e->getMessage(),
        ]);
    }
}
