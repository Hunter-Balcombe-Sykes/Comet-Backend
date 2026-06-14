<?php

namespace App\Jobs\Notifications;

use App\Models\Core\Notifications\Notification;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// V2: Sends category-specific transactional emails. Respects feature flags and user email preferences.
// Categories with a capability gate are listed in CAPABILITY_GATE_MAP. Categories absent from the map bypass the gate
// (e.g. analytics_weekly, profile_tasks) — they have no account_type restriction.
class SendTransactionalNotificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Category → AccountCapabilitySet property name. Categories absent from this map
     * bypass the capability gate (e.g. analytics_weekly, profile_tasks have no
     * account_type restriction — all types can receive them when they have an email).
     *
     * @var array<string, string>
     */
    // Keys MUST match the category strings registered in
    // config('partna.notifications.mailables') — silent fallthrough on a
    // mistyped key would bypass the gate for financially-sensitive mail.
    // `payout_warnings`, `brand_links`, `subscriptions`, `policy_update`,
    // `incident`, `feature_announcement`, `integrations`, `analytics_*`,
    // and `profile_tasks` are intentionally absent — they apply to all
    // account types (or use a non-account-type policy elsewhere).
    // No commerce/brand notification gates — all categories gated here are
    // only applicable to brand/partner accounts (dropped in standalone strip).
    private const CAPABILITY_GATE_MAP = [];

    /**
     * Expose the capability gate map for the preference controller's category filter.
     * Returns a copy so callers cannot mutate the internal map.
     *
     * @return array<string, string>
     */
    public static function capabilityGateMap(): array
    {
        return self::CAPABILITY_GATE_MAP;
    }

    public int $tries = 3;

    // Surface deterministic failures fast — fail after 2 consecutive throws
    // instead of burning the full backoff window before Horizon alerts.
    public int $maxExceptions = 2;

    public array $backoff = [30, 120, 300];

    public int $timeout = 30;

    public function __construct(
        public readonly string $notificationId,
        public readonly string $category,
        public readonly string $userId,
    ) {
        $this->onQueue(config('partna.queues.notifications', 'notifications'));
    }

    public function handle(): void
    {
        if (! config('partna.notifications.email_enabled', false)) {
            if (config('app.debug')) {
                Log::debug('Notification email skipped: feature disabled', [
                    'category' => $this->category,
                ]);
            }

            return;
        }

        // Early-exit if this category has no mailable (in-app-only or unregistered).
        // Avoids unnecessary DB queries when there's nothing to send.
        $class = config("partna.notifications.mailables.{$this->category}");
        if (! is_string($class) || ! class_exists($class)) {
            if (config('app.debug')) {
                Log::debug('Notification email skipped: category has no mailable', [
                    'category' => $this->category,
                ]);
            }

            return;
        }

        // Capability gate: check account_type restrictions for categories that have them.
        // Gate fires after feature-flag + mailable checks but before the DB queries so
        // incapable recipients exit cheaply. Categories not in CAPABILITY_GATE_MAP are
        // unrestricted (analytics_weekly, profile_tasks, etc.).
        $capabilityProperty = self::CAPABILITY_GATE_MAP[$this->category] ?? null;
        if ($capabilityProperty !== null) {
            // Fail-closed for gated categories: a missing/hard-deleted
            // professional is treated as incapable. Otherwise a deleted
            // account could still receive a payouts/commissions email if
            // the row vanishes between dispatch and run.
            $pro = User::find($this->userId);
            if (! $pro || ! AccountCapabilities::for($pro)->{$capabilityProperty}) {
                if (config('app.debug')) {
                    Log::debug('Transactional email skipped: capability gate', [
                        'user_id' => $this->userId,
                        'category' => $this->category,
                        'capability' => $capabilityProperty,
                        'professional_found' => $pro !== null,
                        'job' => self::class,
                    ]);
                }

                return;
            }
        }

        // Skip suspended or disabled accounts — they should not receive transactional
        // emails regardless of category. Check after the capability gate (which may
        // already resolve the user) but before the heavier DB queries.
        $recipient = User::query()
            ->where('id', $this->userId)
            ->whereNull('deleted_at')
            ->first();

        if (! $recipient || $recipient->status !== 'active') {
            if (config('app.debug')) {
                Log::debug('Transactional email skipped: account not active', [
                    'user_id' => $this->userId,
                    'category' => $this->category,
                    'status' => $recipient?->status,
                ]);
            }

            return;
        }

        if (! NotificationPublisher::resolveEmailEnabled($this->userId, $this->category)) {
            if (config('app.debug')) {
                Log::debug('Notification email skipped: user preference disabled', [
                    'category' => $this->category,
                    'user_id' => $this->userId,
                ]);
            }

            return;
        }

        // Lock the row to prevent concurrent workers both reading email_sent_at = null.
        // At-least-once semantics: stamp happens after send, so a crash between send and
        // stamp will cause a retry to re-send. For financially-sensitive emails this is
        // preferable to never sending.
        $notification = DB::transaction(function () {
            $n = Notification::query()->lockForUpdate()->find($this->notificationId);
            if ($n === null || $n->email_sent_at !== null) {
                return null;
            }

            return $n;
        });

        if ($notification === null) {
            return; // already sent or notification deleted
        }

        // Re-use the $recipient model resolved above — avoids a second DB round-trip.
        $email = $recipient->primary_email;

        if (! $email) {
            // Non-transient: retrying 3× will never produce an email. Mark the
            // job failed so Horizon's failed-jobs counter increments and
            // Nightwatch alerts via failed() — critical for payout/commission categories.
            $this->fail(new \RuntimeException(
                'no primary_email on record for professional '.$this->userId
            ));

            return;
        }

        $mailable = $this->buildMailable($notification, $class);
        if ($mailable === null) {
            // Non-transient: the mailable class is misconfigured. Same reasoning as above.
            $this->fail(new \RuntimeException(
                'mailable instantiation failed for category '.$this->category.' (class '.$class.')'
            ));

            return;
        }

        Mail::to($email)->send($mailable);

        $notification->forceFill(['email_sent_at' => now()])->saveQuietly();
    }

    public function failed(\Throwable $e): void
    {
        report($e);

        Log::error('Transactional notification email failed', [
            'notification_id' => $this->notificationId,
            'category' => $this->category,
            'user_id' => $this->userId,
            'message' => $e->getMessage(),
        ]);
    }

    private function buildMailable(Notification $notification, string $class): ?Mailable
    {
        $mailable = new $class($notification);

        if (! $mailable instanceof Mailable) {
            return null;
        }

        return $mailable;
    }
}
