<?php

namespace App\Observers\Core;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Log;

// Purges the user's public sitepage edge cache on any platform-connection write
// (dashboard CRUD + the refresh cron), so platform/product edits appear on the
// sitepage immediately instead of waiting for the edge TTL to lapse. This is the
// proper fix for the sitepage staleness (replaces the temporary low-TTL band-aid
// in partna-pages).
//
// Surgical direct purge (like ServiceCategoryObserver) rather than touch()ing the
// site: platform selections are NOT part of the public-profile payload — they're
// served by the dedicated public platforms endpoint — so there's no reason to
// roll the profile cache key. CloudflareCachePurgeJob is ShouldBeUnique, so the
// burst of writes from a multi-row save coalesces to one purge per handle.
class IntegrationConnectionObserver
{
    public bool $afterCommit = true;

    public function saved(IntegrationConnection $connection): void
    {
        $this->purge($connection);
    }

    public function deleted(IntegrationConnection $connection): void
    {
        $this->purge($connection);
    }

    public function restored(IntegrationConnection $connection): void
    {
        $this->purge($connection);
    }

    private function purge(IntegrationConnection $connection): void
    {
        try {
            $subdomain = User::query()
                ->with('site')
                ->find($connection->user_id)
                ?->site?->subdomain;

            if ($subdomain) {
                CloudflareCachePurgeJob::dispatch($subdomain);
            }
        } catch (\Throwable $e) {
            Log::warning('IntegrationConnectionObserver purge failed', [
                'platform_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
