<?php

namespace App\Services\Notifications\Dispatchers;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

// OV-H: platform-health warnings/errors. A connection that has tripped its
// consecutive-failure circuit breaker is CRITICAL (in-app + email — the user must
// reconnect for content to keep syncing). Transient scrape failures (e.g. menu) are
// non-critical (in-app only, auto-expiring). Best-effort: never breaks the host flow.
class PlatformHealthNotifier
{
    public function __construct(private readonly NotificationPublisher $publisher) {}

    /**
     * Called after EVERY platform refresh failure. Fires a critical notification only
     * at the moment the connection trips the consecutive-failure circuit breaker
     * (`consecutive_failures >= partna.refresh.max_consecutive_failures`) — the point
     * where the dispatcher stops refreshing it entirely (see
     * IntegrationConnection::scopeDueForRefresh). Dedupe keeps it to one per connection.
     */
    public function connectionRefreshFailing(IntegrationConnection $connection): void
    {
        $max = (int) config('partna.refresh.max_consecutive_failures', 10);

        // Only escalate once the breaker has actually tripped — earlier failures are
        // transient and self-heal on the next successful refresh.
        if ((int) $connection->consecutive_failures < $max) {
            return;
        }

        $label = $this->platformLabel($connection->platform);

        $this->safePublish(
            userId: (string) $connection->user_id,
            frontendType: 'Warning',
            category: 'platform_connection',
            title: "Reconnect your {$label}",
            body: "We couldn't refresh your {$label} connection after several attempts, so your page may be showing outdated content. Reconnect it to start syncing again.",
            dedupeKey: "platform_connection_failed:{$connection->id}",
            ctaUrl: '/account/integrations',
            critical: true,
            retentionConfigKey: null,
        );
    }

    /**
     * Terminal menu-scrape failure (all retries exhausted). Non-critical: the menu
     * self-heals via the retry cron, so this is an in-app heads-up, not an email.
     */
    public function menuScrapeFailed(string $userId): void
    {
        $this->safePublish(
            userId: $userId,
            frontendType: 'Warning',
            category: 'content_scrape',
            title: "We couldn't update your menu",
            body: "Your latest menu couldn't be fetched from your provider. We'll keep retrying automatically — if it persists, check your online-ordering link.",
            dedupeKey: "content_scrape:menu_failed:{$userId}",
            ctaUrl: '/account/integrations',
            critical: false,
            retentionConfigKey: 'content_scrape',
        );
    }

    /** Slug → human label, e.g. "google_business" → "Google Business", "instagram" → "Instagram". */
    private function platformLabel(?string $platform): string
    {
        $platform = trim((string) $platform);

        return $platform === '' ? 'platform' : Str::headline($platform);
    }

    private function safePublish(
        string $userId,
        string $frontendType,
        string $category,
        string $title,
        string $body,
        string $dedupeKey,
        string $ctaUrl,
        bool $critical,
        ?string $retentionConfigKey,
    ): void {
        try {
            $this->publisher->publish(
                userId: $userId,
                frontendType: $frontendType,
                category: $category,
                title: $title,
                body: $body,
                dedupeKey: $dedupeKey,
                ctaUrl: $ctaUrl,
                primaryActionLabel: 'View',
                retentionConfigKey: $retentionConfigKey,
                critical: $critical,
            );
        } catch (Throwable $e) {
            // A platform refresh / scrape must never fail because of a notification.
            report($e);
            Log::warning('PlatformHealthNotifier: publish failed', [
                'user_id' => $userId,
                'category' => $category,
                'dedupe_key' => $dedupeKey,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
