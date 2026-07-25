<?php

namespace App\Observers\Core;

use App\Jobs\Platforms\DeleteMirroredMediaJob;
use App\Models\Core\Site\ContentSelection;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Services\Platforms\EventSlugSync;
use App\Services\Platforms\IdentitySync;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Payloads\InstagramPayload;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Site\ContentSelectionService;
use Illuminate\Support\Facades\Log;

// Purges the user's public sitepage edge cache on a MEANINGFUL platform-connection
// write (dashboard CRUD + the refresh cron — create, payload change, or
// (de)activation; not status-only writes like last_visited_at), so platform/
// product edits appear on the sitepage immediately instead of waiting for the
// edge TTL to lapse. This is the proper fix for the sitepage staleness (replaces
// the temporary low-TTL band-aid in partna-pages).
//
// Also touches the site so the public.profile:{handle}:{ts} Redis cache key
// rolls forward (page presence can depend on a connection's own payload via
// PlatformDescriptor::isComplete — see below). CloudflareCachePurgeJob is
// ShouldBeUnique, so the burst of writes from a multi-row save coalesces to
// one purge per handle.
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

            // Roll site.updated_at so the public.profile:{handle}:{ts} cache key
            // (IndividualProfileController) rotates too — the CDN purge above
            // only covers the edge cache. Needed since page presence can now
            // depend on a connection's own payload (PlatformDescriptor::
            // isComplete — fresha's saved selection today; shop is NOT covered
            // here, see below). Without this, completing a booking selection
            // wouldn't surface the Services page until something unrelated
            // happened to touch the site.
            //
            // A full (not quiet) touch() is required, not just a raw timestamp
            // bump: SiteCacheService::raiseResolveFloor() — called via
            // SiteObserver::saved() → invalidateSite() — is what defeats the
            // short-TTL handle.resolve cache that ALSO caches updated_at_ts;
            // skipping the observer here would rotate the DB column but leave
            // stale in-flight resolve-cache entries pointing at the old ts for
            // up to that cache's TTL.
            //
            // Scoped to hasCompletenessPredicate() platforms ONLY — this
            // "meaningful change" gate above also fires for every platform's
            // routine scheduled refresh (RefreshConnectionJob/PlatformRefresher:
            // youtube, instagram, GBP, ...), and SiteObserver::saved() reacts
            // to touch() with its own CloudflareCachePurgeJob + cache
            // invalidation + conditional warm job, on top of the CDN purge
            // this observer already dispatches above. Touching for every
            // platform would multiply that cost platform-wide for content that
            // was never presence-gated in the first place. A descriptor-driven
            // check (rather than a hardcoded platform list) means a future
            // platform that opts into complete() is covered automatically.
            if (app(PlatformRegistry::class)->get($connection->platform)?->hasCompletenessPredicate()) {
                $connection->user?->site?->touch();
            }
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

        // Pretty-URL slugs for the events sitepage section (connect + every
        // daily refresh land here). Best-effort — a failure here must never
        // break the connection save; the pages app's raw-hex-id fallback still
        // resolves the item until the next successful sync mints the slug.
        if (in_array($connection->platform, EventSlugSync::PLATFORMS, true)
            && ($connection->wasRecentlyCreated || $connection->wasChanged('payload'))) {
            // Free the slugs of events that dropped OUT of the payload FIRST,
            // before minting the new ones below. Order matters: a single
            // payload write that drops one event and adds a DIFFERENT,
            // identically-named event in the SAME write — the shape of a
            // recurring weekly/monthly event, where each occurrence has a
            // distinct link (hence a distinct id) but the same display name —
            // must free the old occurrence's base slug before the new one is
            // synced, or ensureCurrent() finds the base still squatted by the
            // still-live sibling row and permanently mints a `-2` suffix.
            // Gated on a real payload change ONLY. wasRecentlyCreated is
            // deliberately not part of this gate — it is a STICKY per-instance
            // flag Laravel never clears after the insert, so the
            // create-a-placeholder-then-update-the-same-instance pattern the
            // connect flows use would silently skip retirement forever. A
            // genuine create can't retire anything regardless, and for the
            // right reason: syncChanges() only runs inside performUpdate(), so
            // wasChanged('payload') is false on a true create and this gate is
            // never entered. (It would still be safe if it were:
            // performInsert() never calls syncOriginal(), so on a SYNCHRONOUS
            // create — no open transaction, see updated()'s note — this
            // observer's callback fires before syncOriginal() runs,
            // getOriginal('payload') is still null, and eventIds(null) parses
            // to [], short-circuiting below; on the deferred/in-transaction
            // path syncOriginal() has already run by the time the callback
            // fires, so before == after instead.)
            if ($connection->wasChanged('payload')) {
                $this->retireVanishedEventSlugs($connection);
            }

            $this->syncEventSlugs($connection);
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
     * Mint/reuse item_slugs for every event carried in this connection's
     * payload (account row's `upcoming` list, or a standalone/custom row) —
     * the same `resource_kind === 'event'` branch EventsPlatformController
     * uses to tell the two row shapes apart (EventsPlatformController.php:318).
     * Best-effort — a failure here must never break the connection save.
     */
    private function syncEventSlugs(IntegrationConnection $connection): void
    {
        try {
            $events = EventSlugSync::extractEvents($connection->resource_kind, $connection->payload);
            app(EventSlugSync::class)->syncEvents($connection->user_id, $events);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('IntegrationConnectionObserver event-slug sync failed', [
                'platform_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Free the item_slugs of events this connection USED to carry and no longer
     * does (an organiser refresh that dropped a finished show, or the owner
     * hiding one — hiding prunes it from the payload). Without this, a retired
     * event squats its slug forever: item_slugs_unique_slug is NON-partial, so
     * next year's identically-named event lands on `-2` permanently.
     *
     * An id may be forgotten ONLY when all three hold:
     *   1. it appears in THIS connection's pre-write payload;
     *   2. it does NOT appear in this connection's post-write payload;
     *   3. it does NOT appear in ANY other live connection of this user on an
     *      events platform — REGARDLESS of is_active.
     *
     * (3) is not paranoia. site.item_slugs has no connection column, so the
     * (user_id, item_type='event') scope spans every event connection the user
     * owns, and EventsPayload::id() is sha1(link)-derived — the SAME event
     * genuinely yields the SAME id under two connections (an eventbrite
     * organiser row and a hand-pasted events-custom row, say). Diffing without
     * (3) would wipe the siblings' slugs on every refresh. The rule is
     * deliberately maximally inclusive: keeping a slug that could have been
     * freed costs one squatted row, freeing one that is still live breaks a
     * public URL.
     *
     * The ORIGINAL resource_kind drives the pre-write parse — extractEvents()
     * branches on it, so applying the new kind to the old payload can mis-parse
     * (and silently return zero ids, or the wrong ones).
     *
     * Every failure mode here is fail-SAFE: an unreadable/absent original
     * parses to [] and returns early, so the worst case is a slug that stays
     * squatted (one wasted row) rather than a live public URL going dead.
     *
     * Best-effort — a failure here must never break the connection save.
     */
    private function retireVanishedEventSlugs(IntegrationConnection $connection): void
    {
        try {
            $before = EventSlugSync::eventIds(
                $connection->getOriginal('resource_kind') ?? $connection->resource_kind,
                $connection->getOriginal('payload'),
            );
            if ($before === []) {
                return;
            }

            $after = EventSlugSync::eventIds($connection->resource_kind, $connection->payload);
            $vanished = array_values(array_diff($before, $after));
            if ($vanished === []) {
                return;
            }

            $vanished = array_values(array_diff($vanished, $this->siblingEventIds($connection)));
            if ($vanished === []) {
                return;
            }

            app(EventSlugSync::class)->retireEvents($connection->user_id, $vanished);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('IntegrationConnectionObserver event-slug retirement failed', [
                'platform_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Every event id still claimed by one of this user's OTHER event
     * connections. Deliberately unfiltered by is_active — an inactive
     * connection is hidden from the sitepage, not deleted, and its slugs must
     * survive so re-activating it doesn't resurrect a dead URL. Soft-deleted
     * rows ARE excluded (the default SoftDeletes scope): a disconnected
     * connection has already given its ids up via deleted().
     *
     * @return list<string>
     */
    private function siblingEventIds(IntegrationConnection $connection): array
    {
        $ids = [];
        $siblings = IntegrationConnection::query()
            ->where('user_id', $connection->user_id)
            ->whereIn('platform', EventSlugSync::PLATFORMS)
            ->where('id', '!=', $connection->id)
            ->get(['id', 'resource_kind', 'payload']);

        foreach ($siblings as $sibling) {
            foreach (EventSlugSync::eventIds($sibling->resource_kind, $sibling->payload) as $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Disconnecting an event connection frees the slugs of every event it
     * carried, minus any the user's remaining connections still claim (same
     * sibling rule as retireVanishedEventSlugs). The payload is still populated
     * on the in-memory model at deleted() time, so no re-read is needed.
     *
     * Idempotent — forgetMany() on already-absent keys is a no-op, so a later
     * force-delete re-firing deleted() is harmless.
     *
     * Best-effort — a failure here must never break the disconnect.
     */
    private function retireEventSlugsOnDelete(IntegrationConnection $connection): void
    {
        if (! in_array($connection->platform, EventSlugSync::PLATFORMS, true)) {
            return;
        }

        try {
            $ids = EventSlugSync::eventIds($connection->resource_kind, $connection->payload);
            if ($ids === []) {
                return;
            }

            $ids = array_values(array_diff($ids, $this->siblingEventIds($connection)));
            if ($ids === []) {
                return;
            }

            app(EventSlugSync::class)->retireEvents($connection->user_id, $ids);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('IntegrationConnectionObserver event-slug delete retirement failed', [
                'platform_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'message' => $e->getMessage(),
            ]);
        }
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

            $payload = InstagramPayload::fromArray($connection->payload);
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
        $this->retireEventSlugsOnDelete($connection);
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

        // deleted() HARD-deletes the slug rows (a retired row would still squat
        // the slug), so a restore has to re-mint them — otherwise the connection
        // comes back slug-less until its next payload change.
        if (in_array($connection->platform, EventSlugSync::PLATFORMS, true)) {
            $this->syncEventSlugs($connection);
        }
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
