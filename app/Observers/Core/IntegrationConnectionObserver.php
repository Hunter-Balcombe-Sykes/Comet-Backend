<?php

namespace App\Observers\Core;

use App\Jobs\Platforms\DeleteMirroredMediaJob;
use App\Models\Core\Site\ContentSelection;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Services\Platforms\IdentitySync;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Payloads\InstagramPayload;
use App\Services\Platforms\Registry\Platform;
use App\Services\Site\ContentSelectionService;
use Illuminate\Support\Facades\Log;

// Purges the user's public sitepage edge cache on a MEANINGFUL platform-connection
// write (dashboard CRUD + the refresh cron — create, payload change, or
// (de)activation; not status-only writes like last_visited_at), so platform/
// product edits appear on the sitepage immediately instead of waiting for the
// edge TTL to lapse. This is the proper fix for the sitepage staleness (replaces
// the temporary low-TTL band-aid in partna-pages).
//
// Surgical direct purge (like ServiceCategoryObserver) rather than touch()ing the
// site: platform selections are NOT part of the public-profile payload — they're
// served by the dedicated public platforms endpoint — so there's no reason to
// roll the profile cache key. CloudflareCachePurgeJob is ShouldBeUnique, so the
// burst of writes from a multi-row save coalesces to one purge per handle.
class IntegrationConnectionObserver
{
    public bool $afterCommit = true;

    // Defaulted (not just container-resolved) because a couple of tests
    // instantiate this observer directly with `new` to exercise updated()'s
    // Instagram-only logic in isolation — IntegrationConnectionCacheRefresher
    // has no dependencies of its own, so this default is always safe.
    public function __construct(
        private readonly IntegrationConnectionCacheRefresher $refresher = new IntegrationConnectionCacheRefresher,
    ) {}

    public function saved(IntegrationConnection $connection): void
    {
        // Purge + preset resolve both gate on MEANINGFUL changes only — a
        // connect (created), a payload refresh, or an (de)activation — not
        // status-only writes like last_visited_at / refresh status. Both
        // downstream jobs are ShouldBeUnique + idempotent, so a burst
        // coalesces to one purge + one rebuild.
        if ($connection->wasRecentlyCreated
            || $connection->wasChanged('payload')
            || $connection->wasChanged('display_settings')
            || $connection->wasChanged('is_active')) {
            $this->refresher->refresh($connection);
        }

        // Central-identity fold: a google-business connect OR a refresh that
        // changed the payload folds Google's identity fields into the canonical
        // stores (workplaces + a couple of users mirror columns). Covers both
        // write paths — connect (updateOrCreate) and ScheduledRefresh (->update)
        // both land here. IdentitySync writes workplaces + users, never the
        // connection, so there is no recursion.
        if ($connection->platform === Platform::GoogleBusiness->value
            && ($connection->wasRecentlyCreated || $connection->wasChanged('payload'))) {
            $this->syncIdentityFromGoogle($connection);
        }

        // Content-selection connect hooks (best-effort, never break the save):
        //   - google-business connect → one-time seed of google-photo picks
        //   - instagram connect       → turn the content Instagram-auto flag on
        //     and reserve the ig slots once the payload can fill them. The IG
        //     connect writes a pending placeholder row FIRST (empty payload) and
        //     the scraped payload lands in a later update — so the slot
        //     reconcile must also run on payload changes, not just the create,
        //     or the reserved slots never materialize for the placeholder flow.
        if ($connection->wasRecentlyCreated) {
            if ($connection->platform === Platform::GoogleBusiness->value) {
                $this->seedContentFromGoogle($connection);
            } elseif ($connection->platform === Platform::Instagram->value) {
                $this->enableContentInstagramAuto($connection);
                $this->reconcileContentInstagramSlots($connection);
            }
        } elseif ($connection->platform === Platform::Instagram->value
            && $connection->wasChanged('payload')) {
            $this->reconcileContentInstagramSlots($connection);
        }
    }

    /**
     * Resolve the connection's owner and fold its google-business payload into
     * the identity stores. The payload is read through the typed
     * GoogleBusinessPayload DTO (no raw ->payload access — this observer is on
     * the migrated read-path allowlist). Gated on a non-empty name so a bare or
     * failed card never triggers a write. IdentitySync is container-resolved
     * (not a ctor dep) so the tests that `new` this observer for the Instagram-
     * only path stay dependency-free, and is best-effort internally — a missing
     * user is guarded here so a stray orphan row can't throw.
     */
    private function syncIdentityFromGoogle(IntegrationConnection $connection): void
    {
        $payload = GoogleBusinessPayload::fromArray($connection->payload);
        $user = $connection->user;
        if ($user === null || $payload->name() === null) {
            return;
        }

        app(IdentitySync::class)->applyFromGooglePayload($user, $payload->toArray());
    }

    /**
     * One-time seed of the content selection with the owner's Google Business
     * photos on a GB connect. ContentSelectionService::maybeSeedFromGoogle is
     * itself a no-op when the user already has upload/google-photo picks, so
     * this is safe to fire on every GB connect. Best-effort — a failure here
     * must never break the connection save.
     */
    private function seedContentFromGoogle(IntegrationConnection $connection): void
    {
        try {
            $site = $connection->user?->site;
            if ($site instanceof Site) {
                app(ContentSelectionService::class)->maybeSeedFromGoogle($site);
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('IntegrationConnectionObserver content google-seed failed', [
                'platform_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Turn the content Instagram-auto flag on when an Instagram account is
     * connected (the reel+post slots then reserve automatically on the next
     * selection read/toggle). The user can turn it back off. Best-effort — a
     * failure here must never break the connection save. Note: the IG connect
     * creates a pending placeholder row first, so this fires on that insert;
     * flipping the site flag is idempotent and independent of the payload.
     */
    private function enableContentInstagramAuto(IntegrationConnection $connection): void
    {
        try {
            $site = $connection->user?->site;
            if ($site instanceof Site && ! $site->content_instagram_auto_enabled) {
                $site->content_instagram_auto_enabled = true;
                $site->save();
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('IntegrationConnectionObserver content instagram-auto enable failed', [
                'platform_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reserve the content-selection ig slots (reel@1 / post@2) once they can
     * actually be filled. Gated on: the auto flag still on (turning it off is
     * how a user removes the slots — never resurrect past that), no ig rows
     * already reserved (later refreshes must not reshuffle), and the payload
     * carrying at least one reservable kind. Delegates the actual slot
     * arithmetic to ContentSelectionService::setInstagramAuto so the shift-
     * down/overflow behavior matches the manual toggle exactly. Best-effort —
     * a failure here must never break the connection save.
     */
    private function reconcileContentInstagramSlots(IntegrationConnection $connection): void
    {
        try {
            $site = $connection->user?->site;
            if (! $site instanceof Site) {
                return;
            }

            // The user->site relation may have been memoized on this model
            // instance before the user toggled auto off (connect placeholder →
            // payload update within one request/job) — a stale true here would
            // resurrect slots the user just removed. Re-read before gating.
            $site->refresh();
            if (! $site->content_instagram_auto_enabled) {
                return;
            }

            $hasSlots = ContentSelection::query()
                ->where('site_id', $site->id)
                ->whereIn('entry_type', ContentSelection::IG_TYPES)
                ->exists();
            if ($hasSlots) {
                return;
            }

            $payload = InstagramPayload::fromArray($connection->payload ?? []);
            if ($payload->videoUrl === null && ($payload->images[0] ?? null) === null) {
                return;
            }

            app(ContentSelectionService::class)->setInstagramAuto($site, true);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('IntegrationConnectionObserver content instagram-slot reconcile failed', [
                'platform_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function deleted(IntegrationConnection $connection): void
    {
        // Disconnect drops this integration's contributions; affected columns
        // re-resolve to the next-best source / manual / default.
        $this->refresher->refresh($connection);
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
        if ($connection->platform !== Platform::Instagram->value) {
            return;
        }

        try {
            $old = InstagramPayload::fromArray($connection->getOriginal('payload'))->folder;
            $new = InstagramPayload::fromArray($connection->payload)->folder;
            if ($old && $new && $old !== $new) {
                DeleteMirroredMediaJob::dispatch($old);
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('IntegrationConnectionObserver mirrored-media cleanup dispatch failed', [
                'platform_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function restored(IntegrationConnection $connection): void
    {
        $this->refresher->refresh($connection);
    }

    /**
     * Reclaim mirrored R2 media when an Instagram connection is disconnected
     * (CONS-21). Soft-delete is the disconnect signal and there is no
     * restore-with-images flow, so cleaning now — rather than waiting for the
     * 30-day hard-delete purge — is safe and frees storage immediately.
     */
    private function cleanupMirroredMedia(IntegrationConnection $connection): void
    {
        $folder = InstagramPayload::fromArray($connection->payload)->folder;
        if ($connection->platform === Platform::Instagram->value && $folder) {
            DeleteMirroredMediaJob::dispatch($folder);
        }
    }
}
