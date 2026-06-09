<?php

namespace App\Observers\Core;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Platforms\DeleteMirroredMediaJob;
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
        $this->cleanupMirroredMedia($connection);
    }

    /**
     * Re-saving a selection overwrites payload via updateOrCreate with NO delete
     * event, so a changed `_folder` orphans the OLD folder in R2 (CONS-21).
     * Reclaim it. Dispatches only when old and new are both real, distinct
     * folders — skipping pending→ready (null→folder), ready→pending (folder→null),
     * and async re-scrape (folder unchanged).
     *
     * Relies on getOriginal() reflecting the pre-update payload, which holds here
     * because no platform write runs in a DB transaction (writes use a Redis lock,
     * not DB::transaction), so this afterCommit observer fires synchronously. If
     * that ever changes the comparison fails SAFE: it can only skip a cleanup,
     * never delete the live folder.
     */
    public function updated(IntegrationConnection $connection): void
    {
        if ($connection->platform !== 'instagram') {
            return;
        }

        $old = data_get($connection->getOriginal('payload'), '_folder');
        $new = data_get($connection->payload, '_folder');
        if ($old && $new && $old !== $new) {
            DeleteMirroredMediaJob::dispatch($old);
        }
    }

    public function restored(IntegrationConnection $connection): void
    {
        $this->purge($connection);
    }

    /**
     * Reclaim mirrored R2 media when an Instagram connection is disconnected
     * (CONS-21). Soft-delete is the disconnect signal and there is no
     * restore-with-images flow, so cleaning now — rather than waiting for the
     * 30-day hard-delete purge — is safe and frees storage immediately.
     */
    private function cleanupMirroredMedia(IntegrationConnection $connection): void
    {
        $folder = data_get($connection->payload, '_folder');
        if ($connection->platform === 'instagram' && $folder) {
            DeleteMirroredMediaJob::dispatch($folder);
        }
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
            // Surface to Nightwatch — without report() a persistent failure (Redis
            // outage, broken user→site join) silently serves stale edge cache with
            // only a breadcrumb log. Do not re-throw: an observer must not crash the
            // parent write.
            report($e);
            Log::warning('IntegrationConnectionObserver purge failed', [
                'platform_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
