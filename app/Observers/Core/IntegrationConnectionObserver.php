<?php

namespace App\Observers\Core;

use App\Ingest\ConnectorRegistry;
use App\Ingest\FacebookEventsSourceProvisioner;
use App\Ingest\Runtime\SourceScheduler;
use App\Ingest\SourceProvisioner;
use App\Jobs\Ingest\RunSourceJob;
use App\Jobs\Platforms\DeleteMirroredMediaJob;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Routing\ConnectionIdentity;
use App\Services\Platforms\IdentitySync;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Payloads\InstagramPayload;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\PreAccount\BuildProgress;
use App\Site\Pools\AutoSyncSetting;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
    // LOAD-BEARING (LIFE-16): App\Routing\SourceReconciler::reconcile() now
    // wraps the intent write + applyIntent()'s IntegrationConnection::save()
    // calls + the settle-UPDATE in one DB::transaction (routing.source_intents
    // must never show `applied` with a NULL connection_id after a mid-write
    // failure). $afterCommit = true is what keeps every side effect below
    // (the Cloudflare purge, the `$connection->user?->site?->touch()`
    // cascade, and syncIngestSource()'s `catch (QueryException)`) OUT of that
    // transaction — a QueryException caught INSIDE the transaction would
    // leave the Postgres transaction ABORTED (SQLSTATE 25P02) for every
    // statement after it. Removing or weakening this flag silently reopens
    // that trap. Pinned by
    // tests/Feature/Routing/SourceReconcilerDeferredSideEffectsTest.php.
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
        $this->maybeFetchMenu($connection);

        // YouTube duplicates (2026-09-02, jordan): the Linktree lane can place
        // one channel twice — by @handle and by UC id — and the rows dedupe
        // by resource_id, which differs. Once a connect fills channelId,
        // keep the OLDEST row for that channel and retire the rest.
        $channelId = $connection->platform === 'youtube' ? FeedPayload::fromArray((array) $connection->payload)->channelId : null;
        if ($connection->platform === 'youtube' && is_string($channelId) && $channelId !== '') {
            $this->retireDuplicateYoutubeRows($connection, $channelId);
        }

        // Purge + preset resolve both gate on MEANINGFUL changes only — a
        // connect (created), a payload refresh, or an (de)activation — not
        // status-only writes like last_visited_at / refresh status. Both
        // downstream jobs are ShouldBeUnique + idempotent, so a burst
        // coalesces to one purge + one rebuild.
        // Hidden pre-scrape rows (A.3) skip every public-facing effect — KV
        // bump, edge purge, site touch, identity fold, menu fetch, Instagram
        // auto — but KEEP ingest provisioning below: the whole point of a
        // hidden connect is real items in the library before the person says
        // yes. HiddenConnections::reveal() flips visibility, and that flip
        // (wasChanged('visibility'), no longer hidden) is the connect moment
        // for each gate here.
        if (! $connection->isHidden()
            && ($connection->wasRecentlyCreated
            || $connection->wasChanged('payload')
            || $connection->wasChanged('display_settings')
            || $connection->wasChanged('is_active')
            || $connection->wasChanged('visibility'))) {
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
            && ! $connection->isHidden()
            && ($connection->wasRecentlyCreated || $connection->wasChanged('payload') || $connection->wasChanged('visibility'))) {
            $this->syncIdentityFromGoogle($connection);
        }

        // The event-slug sync/retire block that stood here is GONE (slice 7
        // Phase 6). It maintained site.item_slugs, whose LAST reader moved to
        // content.item_slugs in slice 2 Task 9 — events reach the public wire
        // through `profile.pools.events` and PoolResolver serves their
        // slug/aliases from the content lane. The block was writing a registry
        // nothing read; ContentItemSlugAllocator (SLUGGED_KINDS carries
        // 'event') mints the live slugs off ProjectionWriter instead.

        // Ingest source provisioning (plan §4): a connect, a payload write
        // (deferred connects fill the identity keys AFTER the placeholder
        // insert), or an (de)activation keeps the ingest.sources seam in step.
        if ($connection->wasRecentlyCreated
            || $connection->wasChanged('payload')
            || $connection->wasChanged('is_active')) {
            $this->syncIngestSource($connection);
        }

        // Instagram connect → turn the pool auto-sync flag back on (best-effort,
        // never breaks the save). Slice 7 unit E retired the two sibling hooks
        // that seeded/reserved site.content_selection rows; pool:media pins are
        // the curation lane now, and they carry no connect-time seed.
        if (($connection->wasRecentlyCreated || $connection->wasChanged('visibility'))
            && ! $connection->isHidden()
            && $connection->platform === Platform::Instagram->value) {
            $this->enableContentInstagramAuto($connection);
        }

        // A.9: a VISIBLE connect answers any standing proposed intent for the
        // same account — the settle gap SuggestionsController::index's
        // docblock recorded now has its home. Hidden pre-scrape creates skip
        // this: their intent was settled by the lane that made them.
        if ($connection->wasRecentlyCreated && ! $connection->isHidden()) {
            $this->settleMatchingIntents($connection);
        }
    }

    /** Best-effort: a settle failure must never fail the connect that triggered it. */
    private function settleMatchingIntents(IntegrationConnection $connection): void
    {
        try {
            $intents = DB::table('routing.source_intents')
                ->where('user_id', $connection->user_id)
                ->where('surface_key', $connection->surface_key)
                ->whereIn('state', ['proposed', 'blocked'])
                ->get(['id', 'surface_key', 'identifier']);
            if ($intents->isEmpty()) {
                return;
            }

            $identity = app(ConnectionIdentity::class);
            $rows = collect([$connection]);
            foreach ($intents as $intent) {
                if ($identity->matchWithin($rows, (string) $intent->surface_key, (string) $intent->identifier) === null) {
                    continue;
                }
                // Live-state re-check in the WHERE, same as routing:settle-connected:
                // the person can answer the card between read and write.
                DB::table('routing.source_intents')
                    ->where('id', $intent->id)
                    ->whereIn('state', ['proposed', 'blocked'])
                    ->update([
                        'state' => 'applied',
                        'block_reason' => null,
                        'connection_id' => $connection->id,
                        'resolved_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        } catch (\Throwable $e) {
            // Log, don't report(): the daily routing:settle-connected sweep
            // re-covers anything this misses, so a settle hiccup is noise on
            // the exception rail (and the connect that triggered it succeeded).
            Log::warning('routing.settle_on_connect_failed', [
                'connection_id' => (string) $connection->id,
                'error' => $e->getMessage(),
            ]);
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
            // 2026-08-05: the switch is the connection's own sparse
            // display_settings.auto_sync_latest (absent = ON). A fresh
            // connect turns it back on — same semantics the old site-column
            // flip had — by stripping a lingering false from THIS row.
            // Direct DB write: this runs inside the model's own saved event,
            // and a model save here would re-enter the observer.
            $settings = (array) ($connection->display_settings ?? []);
            if (($settings[AutoSyncSetting::KEY] ?? null) === false) {
                unset($settings[AutoSyncSetting::KEY]);
                DB::connection('pgsql')->table('site.platform_connections')
                    ->where('id', $connection->id)
                    ->update([
                        'display_settings' => $settings === [] ? null : json_encode($settings),
                        'updated_at' => now(),
                    ]);

                // #CCH-1: this raw update bypasses Eloquent, so saved()'s own
                // wasChanged('display_settings') gate — which already fired its
                // purge earlier in THIS saved() call, before this strip ran —
                // never sees it. CLAUDE.md requires a non-Eloquent write to
                // invalidate explicitly, so this is that call.
                //
                // ⚠️ It does NOT guarantee a second purge, and nobody should
                // read it as one: CloudflareCachePurgeJob is ShouldBeUnique with
                // a ~35s coalesce window whose lock is taken against the real
                // cache store, so this same-handle, same-request dispatch is
                // swallowed every time. The race is already covered anyway —
                // that job's handle() schedules a follow-up purge 15s after its
                // OWN execution, which is long after this synchronous strip has
                // committed, and purgeHandle() evicts rather than reading
                // display_settings. Kept as the explicit invalidation the write
                // path owes, and as cover if the coalesce window or the
                // follow-up schedule ever changes.
                //
                // Edge-purge only, matching what that gate already does for
                // Instagram (no completeness predicate ⇒ no site.touch()) —
                // NOT SiteCacheLanes::bust(): this is a connect-time flag strip
                // on site.platform_connections, not a content.* pool mutation.
                // fresh() is defensive only — refresh() reads user_id, which the
                // strip does not change.
                $this->refresher->refresh($connection->fresh());
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

    public function deleted(IntegrationConnection $connection): void
    {
        // Disconnect drops this integration's contributions; affected columns
        // re-resolve to the next-best source / manual / default.
        $this->refresher->refresh($connection);

        // Roll site.updated_at (same hasCompletenessPredicate() gate and
        // reasoning as saved() above) so the public.profile:{handle}:{ts}
        // cache key rotates on disconnect too — otherwise a completeness-
        // gated platform (fresha, shop) can keep advertising page presence
        // from the now-gone connection until something unrelated touches the
        // site. Placed right after refresh() — refresher->refresh() never
        // throws (catches internally) — and BEFORE the best-effort cleanup
        // calls below, so a failure in any of them can never skip this.
        // Resolved by user_id, NOT by walking $connection->user?->site. This hook
        // runs during ACCOUNT TEARDOWN as well as an ordinary disconnect
        // (PruneExpiredPreAccountBuilds deletes connection rows through Eloquent
        // so the R2 reclaim fires), and there the user row is on its way out with
        // neither relation loaded. Model::preventLazyLoading is armed everywhere
        // except production, so that two-hop walk THREW — and because this
        // observer is $afterCommit, Laravel ran it inside DB::transaction() after
        // the commit, so the delete had already landed and only the command's
        // return value was lost. Live on dev 2026-08-19: "Pruned 0 of 1 (1 failed,
        // will retry next run)" with every row in fact deleted.
        //
        // One query instead of two, and it cannot lazy-load by construction.
        if (app(PlatformRegistry::class)->get($connection->platform)?->hasCompletenessPredicate()) {
            Site::query()->where('user_id', $connection->user_id)->first()?->touch();
        }

        $this->cleanupMirroredMedia($connection);
        $this->syncIngestSource($connection);
        // Connections BEFORE fields: the website_import arm below reads the
        // same field_sources stamp that clearListingSourcedWorkplaceFields()
        // erases.
        $this->disconnectListingSourcedConnections($connection);
        $this->clearListingSourcedWorkplaceFields($connection);
    }

    /**
     * The connection half of FI-15 (retest 2026-08-20): disconnecting the
     * Google Business listing also takes the MACHINE-created connections it
     * seeded. Verified live: after the seven T9 listing swaps, the ST. ALi
     * business account still carried Kings Domain's fresha/facebook/instagram
     * plus another roster business's doordash/uber_eats — every listing's
     * socials, booking and ordering rows (payload source 'google-business')
     * simply accumulated. website_import rows (the previous_website content
     * scan) leave too, but ONLY when that website itself came from the
     * listing (same machine-source stamp check as the field clearing below) —
     * a website the owner typed in produces the same source tag and its
     * connections must survive. User-connected rows carry neither tag and are
     * never touched. Deleting through the models on purpose: each child's own
     * deleted() hooks (media reclaim, ingest sync, cache purge) must run, and
     * none of them is a listing so this cannot recurse.
     */
    private function disconnectListingSourcedConnections(IntegrationConnection $connection): void
    {
        if ($connection->surface_key !== 'google_business.listing') {
            return;
        }

        try {
            $site = Site::query()->where('user_id', $connection->user_id)->first();
            $workplace = $site === null ? null : Workplace::query()->where('site_id', (string) $site->id)->first();
            $sources = ($workplace !== null && is_array($workplace->field_sources)) ? $workplace->field_sources : [];
            $websiteWasMachine = in_array($sources['previous_website']['source'] ?? null, ['google-business', 'website-scan'], true);

            $listingSourced = IntegrationConnection::query()
                ->where('user_id', $connection->user_id)
                ->whereKeyNot($connection->getKey())
                ->get()
                ->filter(function (IntegrationConnection $candidate) use ($websiteWasMachine): bool {
                    $source = CardPayload::fromArray((array) $candidate->payload)->source();

                    return $source === 'google-business'
                        || ($websiteWasMachine && $source === 'website_import');
                });

            foreach ($listingSourced as $candidate) {
                $candidate->delete();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * FI-15 (T9 live, 2026-08-20): disconnecting the Google Business listing
     * takes its MACHINE-sourced workplace card fields with it. Verified live:
     * after seven listing swaps on the business test account, the ST. ALi
     * workplace still carried Kings Domain's description ("Superior Cuts,
     * Fit for a King.", source website-scan) — seedWorkplace() fills
     * only-when-blank and nothing ever cleared the OLD listing's values, so
     * every later listing inherited them. Scoped to fields whose recorded
     * field_sources entry names a machine source; a user-typed value carries
     * no such stamp and is never touched. Best-effort, same rule as every
     * other cleanup in this hook.
     */
    private function clearListingSourcedWorkplaceFields(IntegrationConnection $connection): void
    {
        if ($connection->surface_key !== 'google_business.listing') {
            return;
        }

        try {
            $site = Site::query()->where('user_id', $connection->user_id)->first();
            $workplace = $site === null ? null : Workplace::query()->where('site_id', (string) $site->id)->first();
            if ($workplace === null) {
                return;
            }

            $sources = is_array($workplace->field_sources) ? $workplace->field_sources : [];
            $changed = false;
            foreach (['previous_website', 'category', 'description'] as $key) {
                $source = $sources[$key]['source'] ?? null;
                if (in_array($source, ['google-business', 'website-scan'], true)) {
                    $workplace->{$key} = null;
                    unset($sources[$key]);
                    $changed = true;
                }
            }

            if ($changed) {
                $workplace->field_sources = $sources;
                $workplace->save();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Keep the connection's ingest.sources row in step (create on connect,
     * auto_sync off on disconnect/deactivate, identifier refresh on payload
     * change). Best-effort — a failure here must never break the save.
     * SourceProvisioner::sync() now resolves its own insert race with
     * insertOrIgnore (#LIFE-14), so no benign QueryException reaches this
     * method at all today; the UniqueConstraintViolationException arm exists
     * so a FUTURE write in this seam that races on ingest.sources' unique
     * constraint stays as quiet as the one that provoked it, without letting
     * any OTHER DB fault (a real connection loss, a missing-table 42P01 from
     * an under-provisioned test DB, a statement timeout) share that silence
     * (#LIFE-13) — those are now reported and logged loudly instead.
     */
    private function syncIngestSource(IntegrationConnection $connection): void
    {
        try {
            $result = app(SourceProvisioner::class)->sync($connection);
            $this->maybeRunEagerly($connection, $result);
            // Item 11a (2026-09-01): a facebook connection's satellite events
            // source — AFTER the parent sync above on purpose: the provisioner
            // reads the parent facebook row's identifier that sync just wrote.
            $this->maybeRunEagerly($connection, app(FacebookEventsSourceProvisioner::class)->sync($connection));
        } catch (UniqueConstraintViolationException) {
            // The benign, expected outcome of a concurrent duplicate save, and
            // the ONLY quiet arm here. SourceProvisioner's insert is
            // insertOrIgnore, so it does not raise this at all — this exists
            // so a future write in this seam that races on ingest.sources'
            // unique constraint stays as quiet as the one that provoked it,
            // without any other DB fault sharing the silence (#LIFE-13).
        } catch (QueryException $e) {
            report($e);
            Log::warning('IntegrationConnectionObserver ingest-source sync query failure', [
                'platform_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('IntegrationConnectionObserver ingest-source sync failed', [
                'platform_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run a NEWLY provisioned source once, immediately, when its connector asks
     * for it (Manifest::runsEagerlyOnConnect: every Free connector, plus the
     * paid ones that opt in). For a paid connector this is the only trigger it
     * has: SourceProvisioner sets auto_sync = (cost === Free) and
     * SourceScheduler::scoreDue() selects only auto_sync = true, so without
     * this an instagram source is created and then never runs. For a free one
     * it is what makes "connect → content" immediate instead of next-tick (F21).
     *
     * `created` (and `reselected`) ONLY, and that gate is load-bearing: syncIngestSource() is also
     * called from the payload-change path and from restored(). Firing on
     * `updated` would buy a paid actor call on EVERY refresh of the connection.
     *
     * Claimed before dispatch, never after. RunSourceJob does not claim its own
     * source — claimDue() does that for the scheduler's callers — so an
     * unclaimed dispatch could run concurrently with a scheduler tick and
     * double-charge the vendor. The claim stops two OVERLAPPING dispatches; it
     * is NOT what keeps a duplicate save from spending money — the
     * created/reselected gate above is, and after #LIFE-14 an insert race's
     * loser reports 'updated'/'unchanged', never 'created', so it never
     * reaches this method for that save at all. (Two gaps this does NOT
     * close, both pre-existing #LIFE-5 design and out of scope here: the
     * claim is released once the winner's job finishes, so a loser that
     * reaches claimOne() after that point still claims and runs; and
     * needs_eager_run is set before the claim and only cleared by a
     * qualifying outcome in release(), so a loser that sets it after the
     * winner already cleared it can still buy one deferred paid run.)
     *
     * Best-effort by construction: this sits inside syncIngestSource()'s
     * try/catch, so a failure here can never break the connection save or the
     * pre-account build that triggered it.
     *
     * @param  array{status: string, source_key?: string, reason?: string}  $result
     */
    private function maybeRunEagerly(IntegrationConnection $connection, array $result): void
    {
        // Not `?? null`: every SourceProvisioner::sync() return path sets
        // `status`, so the coalesce was dead code (phpstan nullCoalesce.offset).
        // 'reselected' = the source's selection_ref changed (a Fresha team
        // member re-pick). The connector's manifest still gates the run, so a
        // paid connector never gets a bonus call from a payload write.
        if (! in_array($result['status'], ['created', 'reselected'], true)) {
            return;
        }

        $sourceKey = $result['source_key'] ?? null;
        if ($sourceKey === null || ! ConnectorRegistry::has($sourceKey)) {
            return;
        }

        if (! ConnectorRegistry::manifestFor($sourceKey)->runsEagerlyOnConnect()) {
            return;
        }

        // T3 (2026-08-27): a payload-reading connector's eager run is pointless
        // before the first fetch — it can only ingest the empty answer (and on
        // fresha it DID, projecting "no_selection" builds). The selection
        // landing with the fetched payload flips selection_ref → 'reselected'
        // above re-enters here with the data actually present.
        if ($result['status'] === 'created'
            && $connection->last_refreshed_at === null
            && ConnectorRegistry::manifestFor($sourceKey)->eagerNeedsFetchedPayload) {
            Log::info('ingest.eager_run.deferred_until_fetch', [
                'connection_id' => $connection->id,
                'source_key' => $sourceKey,
            ]);

            return;
        }

        // sync() just inserted this row; re-read it rather than threading the id
        // back through the provisioner's return contract for one caller.
        $sourceId = DB::table('ingest.sources')
            ->where('connection_id', $connection->id)
            ->where('source_key', $sourceKey)
            ->value('id');

        if ($sourceId === null) {
            return;
        }

        // #LIFE-5: record the obligation BEFORE trying to act on it. Everything
        // below can fail — the claim can be lost, the dispatch can throw, the
        // queue can accept the job and never run it — and none of those paths
        // used to leave a trace the scheduler could act on, because auto_sync is
        // false on exactly the connectors this matters for. With the flag set
        // first, SourceScheduler::scoreDue() picks the source up on its own
        // schedule no matter which of those happens, and release() clears the
        // flag the moment a run actually lands.
        DB::table('ingest.sources')->where('id', $sourceId)->update([
            'needs_eager_run' => true,
            'updated_at' => now(),
        ]);

        $scheduler = app(SourceScheduler::class);
        $runId = (string) Str::uuid();
        if (! $scheduler->claimOne((string) $sourceId, $runId)) {
            // Someone else owns the row already — their run covers this connect.
            return;
        }

        try {
            RunSourceJob::dispatch((string) $sourceId);
        } catch (\Throwable $e) {
            // The claim is released by RunSourceJob's own finally/failed() once
            // the job RUNS — but a dispatch that never lands (queue
            // unreachable) reaches neither, leaving the row claimed until
            // releaseStranded()'s 2h backstop. Release it here so the source is
            // left in a clean, re-runnable state instead.
            //
            // This does not re-run it HERE — but #LIFE-5 means it no longer
            // has to. needs_eager_run was set above and is cleared only by a
            // LANDING outcome, so release('error') below leaves it set and
            // SourceScheduler::scoreDue() picks the source up on its next due
            // tick despite auto_sync being false. Before that flag existed this
            // was the end of the line: the user's media never arrived at all.
            $scheduler->release((string) $sourceId, 'error', false);

            throw $e;
        }
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
    private function retireDuplicateYoutubeRows(IntegrationConnection $connection, string $channelId): void
    {
        try {
            $rows = IntegrationConnection::query()
                ->where('user_id', $connection->user_id)
                ->where('platform', 'youtube')
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('resource_id', $channelId)->orWhere('payload->channelId', $channelId))
                ->orderBy('created_at')
                ->get();
            if ($rows->count() < 2) {
                return;
            }
            $keep = $rows->first();
            foreach ($rows as $row) {
                if ((string) $row->id === (string) $keep->id) {
                    continue;
                }
                Log::info('youtube.duplicate_channel_retired', ['user_id' => (string) $connection->user_id, 'channel_id' => $channelId, 'kept' => (string) $keep->id, 'retired' => (string) $row->id]);
                $row->delete();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

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

    /**
     * An ordering connection on a MENU platform (Uber Eats / DoorDash /
     * Square) triggers the shared menu fetch no matter which path wrote it —
     * GoogleBusinessAutoSync's own seed used to be the ONLY dispatcher, so an
     * ordering link that arrived via the router (Instagram bio, Linktree
     * unroll, a paste) never filled the menus pool (overnight 2026-08-18
     * F17: RUH's uber_eats.order landed with 0 dishes). MenuFetchJob is
     * ShouldBeUnique per user and idempotent, so a burst coalesces.
     */
    private function maybeFetchMenu(IntegrationConnection $connection): void
    {
        if ((string) $connection->routing_class !== 'ordering') {
            return;
        }
        if ($connection->isHidden()) {
            return;
        }
        if (! ($connection->wasRecentlyCreated || $connection->wasChanged('payload') || ($connection->wasChanged('is_active') && $connection->is_active) || $connection->wasChanged('visibility'))) {
            return;
        }
        $url = (string) (CardPayload::fromArray((array) $connection->payload)->url() ?? '');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return;
        }
        // Host-only ON PURPOSE, and it must stay that way. Adding the
        // store_path_pattern check here (the obvious "defence in depth" after
        // MenuSource::scrapableSlug) would be a regression: editing a
        // connection from a real /store/ url to a /brand/ one is exactly when
        // the job MUST run, because that is the dispatch that notices the plan
        // went null and clears the now-orphaned scraped dishes. Waking the job
        // for a brand page is already free — MenuSource nulls the slug, so no
        // plan is built and no Apify target is billed — so the only thing a
        // path check here could buy is a skipped queue job, at the price of a
        // stale menu that outlives its link.
        foreach ((array) config('partna.menu.platforms', []) as $slug => $spec) {
            $pattern = $spec['host_pattern'] ?? null;
            if (is_string($pattern) && $pattern !== '' && preg_match($pattern, $host)) {
                // Setup progress (2026-09-02): the menu is OWED from the
                // dispatch, not from the job's first line — the signup's
                // "done" must not flip true in the queue gap between them.
                BuildProgress::noteForUser((string) $connection->user_id, PreAccountBuildEvent::STAGE_MENU, PreAccountBuildEvent::STATUS_STARTED, 'Reading your menu');
                MenuFetchJob::dispatch((string) $connection->user_id)->afterCommit();

                return;
            }
        }
    }

    public function restored(IntegrationConnection $connection): void
    {
        $this->maybeFetchMenu($connection);
        $this->refresher->refresh($connection);

        // Same hasCompletenessPredicate() touch as deleted()/saved() — a
        // restore can bring page presence back for a completeness-gated
        // platform, so the profile cache key must roll here too.
        if (app(PlatformRegistry::class)->get($connection->platform)?->hasCompletenessPredicate()) {
            $connection->user?->site?->touch();
        }

        $this->syncIngestSource($connection);

        // The event-slug re-mint that stood here went with the sync block in
        // saved() (slice 7 Phase 6) — site.item_slugs has no reader left, and
        // the content lane re-mints off the projection on the next run.
    }

    /**
     * Reclaim mirrored R2 media when an Instagram connection is disconnected
     * (CONS-21). Soft-delete is the disconnect signal and there is no
     * restore-with-images flow, so cleaning now — rather than waiting for the
     * 30-day hard-delete purge — is safe and frees storage immediately.
     *
     * Best-effort — a failure here (a bad payload parse, a dispatch failure)
     * must never break the rest of deleted()'s disconnect cleanup.
     */
    private function cleanupMirroredMedia(IntegrationConnection $connection): void
    {
        try {
            $folder = InstagramPayload::fromArray($connection->payload)->folder;
            if ($connection->platform === Platform::Instagram->value && $folder) {
                DeleteMirroredMediaJob::dispatch($folder);
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('IntegrationConnectionObserver mirrored-media disconnect cleanup failed', [
                'platform_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
