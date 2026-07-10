<?php

namespace App\Services\Notifications\Dispatchers;

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
            $this->publisher->publish(
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
