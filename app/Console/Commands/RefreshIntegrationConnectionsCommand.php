<?php

namespace App\Console\Commands;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\PlatformRefresher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

// Pilot daily refresh of the auto-content platform connections (YouTube latest,
// Eventbrite events, Apple latest release), stalest rows first. Other platforms
// are intentionally NOT queried — see PlatformRefresher for why (static links
// have nothing to refresh; Instagram/Fresha/Shopify are deferred). Mirrors
// smartlinks:refresh.
class RefreshIntegrationConnectionsCommand extends Command
{
    protected $signature = 'integrations:refresh {--limit=300 : Max connections to refresh this run} {--throttle-ms=200 : Politeness delay between fetches}';

    protected $description = 'Re-fetch stale auto-content platform connections (pilot).';

    public function handle(PlatformRefresher $refresher): int
    {
        $limit = (int) $this->option('limit');
        $throttleMs = (int) $this->option('throttle-ms');

        $connections = IntegrationConnection::query()
            ->active()
            ->whereIn('platform', PlatformRefresher::REFRESHABLE)
            ->orderByRaw('last_refreshed_at ASC NULLS FIRST')
            ->limit($limit)
            ->get();

        $ok = 0;
        $failed = 0;
        foreach ($connections as $connection) {
            try {
                $refreshed = $refresher->refresh($connection);
                $refreshed->last_refresh_status === 'ok' ? $ok++ : $failed++;
            } catch (\Throwable $e) {
                // Surface to Nightwatch — the continue-on-error loop otherwise turns
                // a systemic failure (broken scraper, schema drift) into N silent
                // warnings + a healthy-looking summary, with zero exception events.
                report($e);
                $failed++;
                Log::warning('integrations:refresh failed for a connection', [
                    'platform_connection_id' => $connection->id,
                    'platform' => $connection->platform,
                    'message' => $e->getMessage(),
                ]);
            }
            if ($throttleMs > 0) {
                usleep($throttleMs * 1000);
            }
        }

        $this->info("Platform connections refreshed: {$ok} ok, {$failed} failed (of {$connections->count()} stale).");

        return self::SUCCESS;
    }
}
