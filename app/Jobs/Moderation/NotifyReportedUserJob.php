<?php

namespace App\Jobs\Moderation;

use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\AccountBannedNotification;
use App\Notifications\Moderation\AccountSuspendedNotification;
use App\Notifications\Moderation\ContentHiddenNotification;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotifyReportedUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 60;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {
        // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
        $this->queue = 'notifications';
    }

    public function handle(): void
    {
        $case  = ModerationCase::query()->findOrFail($this->caseId);
        $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);
        $entry->update(['status' => 'dispatched', 'dispatched_at' => now(), 'attempts' => $entry->attempts + 1]);

        // No owner on record — nothing to notify, still mark complete.
        if ($case->reportable_owner_user_id === null) {
            $entry->update(['status' => 'completed', 'completed_at' => now()]);
            return;
        }

        $user = User::query()->find($case->reportable_owner_user_id);
        if ($user === null) {
            $entry->update(['status' => 'completed', 'completed_at' => now()]);
            return;
        }

        // Fail-closed: don't notify users who've been suspended/banned (capability gate).
        if (! AccountCapabilities::for($user)->receive_moderation_notifications) {
            $entry->update(['status' => 'completed', 'completed_at' => now()]);
            return;
        }

        // Load the most recent decision to determine which notification to send.
        $decision = $case->decisions()->latest('decided_at')->firstOrFail();

        $notification = match ($decision->decision_type) {
            'hide_content', 'hide_site' => new ContentHiddenNotification($decision),
            'suspend_user'              => new AccountSuspendedNotification($decision),
            'ban_user'                  => new AccountBannedNotification($decision),
            default                     => null,
        };

        if ($notification !== null) {
            $user->notify($notification);
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
        Log::error('Moderation notification job permanently failed', [
            'job'           => static::class,
            'action_log_id' => $this->actionLogId,
            'case_id'       => $this->caseId,
            'error'         => $e->getMessage(),
        ]);
    }
}
