<?php

namespace App\Services\Notifications\Dispatchers;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

// In-app notice that a user connected an integration, naming it. Non-critical by
// design: NotificationPublisher escalates to email ONLY for critical rows, so
// bell-only is enforced by the engine rather than by remembering. Best-effort —
// a notification failure must never break a connect.
class IntegrationNotifier
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
        private readonly PlatformRegistry $registry,
    ) {}

    /**
     * Fires once per connect EPISODE. The dedupe key is the connection's UUID:
     * idx_platform_connections_unique_active is partial (WHERE deleted_at IS NULL),
     * so a disconnect→reconnect mints a new row and notifies again, while a
     * reconnect in place keeps its id and stays silent — that being a change to an
     * existing connection, not the addition of one.
     *
     * Both guards below live HERE rather than at the call sites: ConnectFetchJob is
     * dispatched by EventsController for a deferred organiser connect, so a
     * call-site guard would require proving no event/link row can ever reach that
     * job, for every present and future dispatcher of it.
     */
    public function connected(IntegrationConnection $connection): void
    {
        // Confirmed success only — deferred connects write 'pending' first, and
        // terminal failures land 'error' / 'unavailable'.
        if ($connection->last_refresh_status !== 'ok') {
            return;
        }

        // Individual links and events are not integrations the user "added";
        // notifying per row would mean eight bells for eight custom links.
        if (in_array($connection->resource_kind, ['event', 'link'], true)) {
            return;
        }

        $label = $this->platformLabel($connection->platform);

        try {
            $this->publisher->publish(
                userId: (string) $connection->user_id,
                frontendType: 'Success',
                category: 'integration_connected',
                title: "{$label} connected",
                body: "Your {$label} connection is live and will now show on your Partna page.",
                dedupeKey: "integration_connected:{$connection->id}",
                ctaUrl: '/account/integrations',
                primaryActionLabel: 'View',
                retentionConfigKey: 'integration_connected',
                critical: false,
            );
        } catch (Throwable $e) {
            report($e);
            Log::warning('IntegrationNotifier: publish failed', [
                'user_id' => $connection->user_id,
                'connection_id' => $connection->id,
                'platform' => $connection->platform,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** Registry label first ("YouTube Music"), Str::headline as the fallback. */
    private function platformLabel(?string $platform): string
    {
        $platform = trim((string) $platform);
        if ($platform === '') {
            return 'Integration';
        }

        return $this->registry->get($platform)?->getLabel() ?? Str::headline($platform);
    }
}
