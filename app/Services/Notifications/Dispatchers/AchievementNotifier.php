<?php

namespace App\Services\Notifications\Dispatchers;

use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Models\Core\Notifications\Notification;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Log;
use Throwable;

// OV-H: celebratory "achievement" notifications for user milestones. Non-critical
// (in-app only, auto-expiring). Each method is dedupe-keyed so a milestone fires
// exactly once. Best-effort: a notification failure never breaks the host flow.
class AchievementNotifier
{
    public function __construct(private readonly NotificationPublisher $publisher) {}

    /**
     * First enquiry a user has ever received. Wired at the post-persist enquiry
     * notification job (which already detects the first-ever enquiry).
     */
    public function firstEnquiry(string $userId): void
    {
        $this->safePublish(
            userId: $userId,
            title: 'You received your first enquiry',
            body: 'Someone just reached out through your Partna page. Open your inbox to read it and reply.',
            dedupeKey: "achievement:first_enquiry:{$userId}",
            ctaUrl: '/account/enquiries',
        );
    }

    /**
     * Single insertion point so every achievement shares the same category, type,
     * retention and failure-isolation semantics.
     */
    private function safePublish(string $userId, string $title, string $body, string $dedupeKey, string $ctaUrl): void
    {
        try {
            // Checked BEFORE publish(): publish() always returns the row (new
            // or pre-existing) on its insertOrIgnore conflict, so its return
            // value can't tell "just created" from "already fired" by itself
            // (same reasoning as NotifyWeeklySummary's digest-email gate).
            $isNew = ! Notification::query()
                ->where('user_id', $userId)
                ->where('dedupe_key', $dedupeKey)
                ->exists();

            $notification = $this->publisher->publish(
                userId: $userId,
                frontendType: 'Success',
                category: 'achievement',
                title: $title,
                body: $body,
                dedupeKey: $dedupeKey,
                ctaUrl: $ctaUrl,
                primaryActionLabel: 'View',
                retentionConfigKey: 'achievement',
                critical: false,
            );

            // Achievements stay critical=false deliberately (auto-expiring,
            // non-critical severity) — publish() only auto-dispatches email
            // for critical notifications, so the celebration email is
            // dispatched here instead, on the same 'mail' queue publish()
            // itself would have used.
            if ($isNew && $notification !== null && config('partna.notifications.email_enabled', false)) {
                SendTransactionalNotificationEmailJob::dispatch(
                    $notification->id,
                    'achievement',
                    $userId,
                )->onQueue('mail');
            }
        } catch (Throwable $e) {
            // Never let a celebratory notification break the real work that triggered it.
            report($e);
            Log::warning('AchievementNotifier: publish failed', [
                'user_id' => $userId,
                'dedupe_key' => $dedupeKey,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
