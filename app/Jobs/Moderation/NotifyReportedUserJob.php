<?php

namespace App\Jobs\Moderation;

use App\Jobs\Moderation\Concerns\DedupesRecipientSends;
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
use Throwable;

class NotifyReportedUserJob implements ShouldBeUnique, ShouldQueue
{
    use DedupesRecipientSends;
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

        // Load the most recent decision to determine which notification to send.
        $decision = $case->decisions()->latest('decided_at')->firstOrFail();

        // Suspension/ban notices are exempt from the capability gate: the status
        // write and this job race inside one afterCommit (dispatch order is
        // deliberately order-free), so by the time we run the account is often
        // ALREADY suspended — and the email telling the user their account was
        // closed must still send (right-to-notice). Content-hidden keeps the
        // gate: no reason to email an account that's already suspended/banned
        // about content tidy-ups.
        $isClosureNotice = in_array($decision->decision_type, ['suspend_user', 'ban_user'], true);
        if (! $isClosureNotice && ! AccountCapabilities::for($user)->receive_moderation_notifications) {
            $this->markCompleted($entry);

            return;
        }

        $notification = match ($decision->decision_type) {
            'hide_content', 'hide_site' => new ContentHiddenNotification($decision),
            'suspend_user' => new AccountSuspendedNotification($decision),
            'ban_user' => new AccountBannedNotification($decision),
            default => null,
        };

        if ($notification !== null) {
            $recipient = 'user:'.$user->id;
            if ($this->claimRecipient($entry->id, $recipient)) {
                try {
                    $user->notify($notification);
                } catch (Throwable $e) {
                    $this->releaseRecipient($entry->id, $recipient);
                    throw $e;
                }
            }
        }

        $this->markCompleted($entry);
    }
}
