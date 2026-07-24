<?php

namespace App\Console\Commands;

use App\Jobs\Platforms\RefreshConnectionJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Console\Command;

/**
 * REV1 remediation: accounts claimed BEFORE ClaimSiteService started forcing
 * a re-enrich still carry a google-business payload stripped of reviews at
 * pre-account build time (stripThirdPartyPii) with no trigger to ever
 * refresh it — GoogleBusinessFetch's own 40h detailsFetchedAt freshness
 * cache means even the next scheduled refresh (if it ever ran) may have been
 * a no-op cache hit. Same mechanism as the claim-time fix: clear
 * detailsFetchedAt, dispatch the same RefreshConnectionJob the hourly cron
 * and manual-refresh button use. Safe to re-run — a connection that already
 * has reviews is excluded by the query itself.
 */
class BackfillClaimedGoogleBusinessReviewsCommand extends Command
{
    protected $signature = 'google-business:backfill-claimed-reviews {--dry-run}';

    protected $description = 'Force a real re-fetch for every claimed account whose Google Business connection is missing reviews.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $connections = IntegrationConnection::query()
            ->where('platform', 'google-business')
            ->where('is_active', true)
            ->whereNotNull('place_id')
            ->whereRaw("payload -> 'reviews' IS NULL")
            ->whereIn('user_id', User::query()->where('status', 'active')->select('id'))
            ->get();

        $this->info("Found {$connections->count()} claimed account(s) with a Google Business connection missing reviews.");

        if ($dryRun) {
            return self::SUCCESS;
        }

        foreach ($connections as $connection) {
            $payload = $connection->payload;
            unset($payload['detailsFetchedAt']);
            $connection->updateQuietly(['payload' => $payload, 'last_refresh_status' => 'pending']);

            RefreshConnectionJob::dispatch($connection->id, 'google-business', manual: true);
        }

        $this->info('Dispatched.');

        return self::SUCCESS;
    }
}
