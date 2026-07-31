# Scaling Antipatterns Audit — 2026-07-28

**Branch:** development
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching — analytics ingest, notification fan-out, observer-triggered work
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/Analytics/AnalyticsQueryService.php
- app/Services/Analytics/AnalyticsCacheService.php
- app/Http/Controllers/Api/User/Analytics/UserAnalyticsController.php
- app/Http/Controllers/Api/User/Analytics/DevInsightsController.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Observers/Core/BlockObserver.php
- app/Observers/Core/SiteObserver.php
- app/Services/Platforms/EventSlugSync.php
- app/Ingest/Projection/ProjectionWriter.php
- app/Ingest/Runtime/RunExecutor.php
- app/Jobs/Ingest/RunSourceJob.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
- app/Services/Notifications/NotificationPublisher.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 6 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#CACHE-1** · P2 — `refreshItemCaches` runs 18+ per-facet `exists()` queries per item touched in a projection run
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:673-683 (`refreshItemCaches`)
    - **Affects:** Ingest background workers (queue `ingest`) — every content item touched by a projection run (identity resolve/merge, catalog sync) pays 14 singleton-facet + 4 collection `exists()` checks, serialized.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the per-facet `exists()` loop with one query per facet table across the whole `$itemIds` batch (e.g. `whereIn('item_id', $itemIds)->distinct()->pluck('item_id')`), then look up membership from an in-memory set instead of issuing 18 queries per item.
        - Category 5 canonical replacement: batch the presence check — a `selectRaw` over one small `UNION ALL` (or a single query per facet table keyed by the whole batch) rather than a per-item, per-facet loop.
    - **Technical:** For every item in `$itemIds`, the method issues 14 `exists()` calls (one per `SINGLETON_FACETS` key) plus 4 more for `item_media`/`offers`/`item_tags`/`f_action` — 18 round trips per item, run inside the same synchronous `RunSourceJob` that already lands and projects a source. A catalog-sync run that touches 100 items (a menu import, a large YouTube channel resolve) issues ~1,800 queries in one job execution. This is read-side query amplification proportional to items-touched, not to the triggering event's payload size, matching category (5)'s "synchronous multi-table work... on the ingest thread."
    - **Plain English:** After updating a batch of products, the system re-checks each product against 18 separate lists one at a time to figure out what information it has, instead of checking each list once for the whole batch. For a big catalog update this turns into thousands of tiny lookups that could have been a handful of bulk ones, slowing down the background job that keeps a professional's content in sync.
    - **Evidence:**
        ```php
        $present = [];
        foreach (array_keys(self::SINGLETON_FACETS) as $facet) {
            if (DB::table("content.{$facet}")->where('item_id', $itemId)->exists()) {
                $present[] = $facet;
            }
        }
        foreach (['item_media', 'offers', 'item_tags', 'f_action'] as $collection) {
            if (DB::table("content.{$collection}")->where('item_id', $itemId)->exists()) {
                $present[] = $collection;
            }
        }
        ```

- [ ] **#CACHE-2** · P2 — `replaceCollections` deletes and re-inserts media/offers/tags one row at a time per projected item
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:559-605 (`replaceCollections`)
    - **Affects:** Ingest background workers — every item with media, offers, or tags pays a `DELETE` plus one `INSERT` per row per collection on every projection pass, even when the set is unchanged.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collect all rows for `media`/`offers`/`tags` into arrays first (media still needs `ensureMediaAsset()` per entry to resolve `asset_id`, but that can run before the batch insert), then issue one `DB::table(...)->insert($rows)` per collection instead of one insert per row.
        - Canonical replacement per category (2): bulk insert bounded by the item's own payload size (a carousel's media count, a menu's offer count) instead of a query per row.
    - **Technical:** For each item with projected media/offers/tags, the method does `DELETE ... WHERE item_id = ? AND source_id = ?` followed by one `INSERT` per array entry inside a `foreach`. An Instagram carousel (up to 10 media) or a menu-sync catalog (dozens of items × several offers each) turns one ingest run into hundreds of individual write round trips. Write volume scales with the connection's own catalog cardinality, not with the triggering webhook/poll payload size — the canonical write-amplification shape in category (2).
    - **Plain English:** Every time a professional's photos, prices, or tags refresh from a connected platform, the system throws out the old list and rebuilds it one item at a time — one database trip per photo, per price, per tag — instead of dropping in the whole new list in one go. For someone with a large catalog, that's hundreds of tiny database round trips that could be a handful of bulk ones.
    - **Evidence:**
        ```php
        DB::table('content.item_media')->where('item_id', $itemId)->where('source_id', $contentSourceId)->delete();
        foreach ($media as $position => $entry) {
            $assetId = $this->ensureMediaAsset($itemId, (array) $entry);
            DB::table('content.item_media')->insert([
                'id' => (string) Str::uuid(),
                'item_id' => $itemId,
                'source_id' => $contentSourceId,
                'asset_id' => $assetId,
                'role' => (string) (((array) $entry)['role'] ?? 'gallery'),
                'position' => $position,
                'alt_text' => ((array) $entry)['alt'] ?? null,
                'created_at' => now(),
            ]);
        }
        ```

- [ ] **#CACHE-3** · P2 — Projection runs synchronously inside the same ingest job as landing, with no chunking for large streams
    - **Where:** app/Ingest/Runtime/RunExecutor.php:168-186 (`execute`); called from app/Jobs/Ingest/RunSourceJob.php ($timeout = 120, queue `ingest`)
    - **Affects:** Ingest queue throughput — a source with many changed records (a large YouTube channel, a big menu sync) occupies one `ingest`-queue worker for the full land+project duration before it can pick up the next claimed source.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Respect the documented design intent (the docblock explicitly ties this to "plan §4 Landing → Projection... content rows exist the moment records land, not on some later sweep") — do not split landing and projection into a fully decoupled sweep, which would regress that freshness guarantee.
        - Instead, bound the cost: dispatch `projectStream()` as a *chained* follow-up job (`Bus::chain`) immediately after landing completes, so the current `RunSourceJob` releases its claim and the worker picks up the next due source while projection runs as its own queued unit — preserving "projected in the same run" semantics without monopolizing one worker slot for the full land+project duration.
        - Nightwatch already alerts on slow jobs (per project monitoring policy) — no separate observability work needed, but confirm the `ingest` queue's slow-job threshold is tuned for this job now that projection is included in its runtime.
    - **Technical:** `RunExecutor::execute()` calls `$this->projections->projectStream($source, $streamId, $streamName)` inline, in the same `RunSourceJob::handle()` invocation that lands records, and this is a **deliberate** architectural choice documented in `ProjectionWriter`'s class docblock ("plan §4... idempotently... never fatal to the fetch: the record log is durable"). The risk is not correctness — a projection failure is caught, logged as an anomaly, and recoverable via `ingest:project` — but queue throughput: `RunSourceJob` has `$tries = 1` and a 120s timeout, and a source with a large stream (a power user's catalog) extends that job's runtime by however long `projectStream()` takes, delaying the next claimed source on the same worker slot. This is category (5)'s "hot-path heavy work... should be fire-and-forget" pattern, tempered by the fact the coupling is intentional for read-freshness.
    - **Plain English:** When a professional's connected platform (YouTube, a menu app) sends updated content, one background worker both saves the raw data AND turns it into the polished content rows shown on the site — back to back, in the same breath. That's deliberate, so changes show up immediately. But if one person has an unusually large catalog, their single sync job ties up a worker for longer, and everyone else's smaller updates queue up behind it. The fix keeps content showing up right away while letting the "save" step hand off the "polish" step as its own quick errand, so the worker is free to move to the next person sooner.
    - **Evidence:**
        ```php
        if (($landed['changed'] > 0 || $landed['tombstoned'] > 0)
            && ProjectorRegistry::has((string) $source['source_key'], $streamName)) {
            try {
                $this->projections->projectStream($source, $streamId, $streamName);
            } catch (\Throwable $e) {
                report($e);
                $notes[] = ['code' => 'projection_error', 'message' => $e->getMessage()];
                DB::table('ingest.anomalies')->insert([
        ```

- [ ] **#CACHE-4** · P2 — Google Business identity sync runs synchronously inside `IntegrationConnectionObserver::saved()`
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:97-100, 180-189 (`syncIdentityFromGoogle`)
    - **Affects:** Every Google Business connection create/refresh — identity fields (workplaces, user mirror columns) are written inline during the connection's save cycle instead of being deferred.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Dispatch a dedicated queued job (e.g. `SyncGoogleIdentityJob`, `ShouldBeUnique` per user id) from the observer instead of calling `IdentitySync::applyFromGooglePayload` inline.
        - Follow the observer's own established pattern: `DeleteMirroredMediaJob::dispatch($folder)` is already fire-and-forget for Instagram cleanup in this same file — the GB identity sync is the outlier.
    - **Technical:** When a Google Business connection is created or its payload changes (including the daily refresh cron), `syncIdentityFromGoogle()` resolves the payload and calls `IdentitySync::applyFromGooglePayload($user, ...)` — a service that writes `core.workplaces` and mirror columns on `core.users` — synchronously inside the Eloquent `saved()` callback. At scale (a daily cron refreshing many GB connections), each refresh does identity reconciliation inline, adding latency to both the manual-reconnect API response and the refresh cron's per-connection throughput.
    - **Plain English:** When a pro's Google Business listing refreshes, the system immediately copies their business name, address, and hours into their profile — right there in the middle of the refresh, before anything else can happen. The system already knows how to hand this kind of work to a background worker (it does exactly that for Instagram photo cleanup two lines away); the identity copy should follow the same pattern so the refresh itself stays fast.
    - **Evidence:**
        ```php
        if ($connection->platform === Platform::GoogleBusiness->value
            && ($connection->wasRecentlyCreated || $connection->wasChanged('payload'))) {
            $this->syncIdentityFromGoogle($connection);
        }
        ```
        ```php
        private function syncIdentityFromGoogle(IntegrationConnection $connection): void
        {
            $payload = GoogleBusinessPayload::fromArray($connection->payload);
            $user = $connection->user;
            if ($user === null || $payload->name() === null) {
                return;
            }

            app(IdentitySync::class)->applyFromGooglePayload($user, $payload->toArray());
        }
        ```

- [ ] **#CACHE-5** · P2 — Event-slug retirement on disconnect runs inline in `IntegrationConnectionObserver::deleted()`
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:447-455, 318-344 (`retireEventSlugsOnDelete`)
    - **Affects:** Every user disconnecting an event-platform integration — slug retirement (potentially dozens of rows via `siblingEventIds()` cross-referencing plus `EventSlugSync::retireEvents()`) happens inline before the disconnect API response returns.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Dispatch the retirement as a queued job from `deleted()`, the same way `cleanupMirroredMedia()` already dispatches `DeleteMirroredMediaJob` two lines above it in this method.
        - Key the job `ShouldBeUnique` on `(user_id, connection_id)` so a rapid disconnect→reconnect→disconnect can't stack duplicate retirement jobs.
    - **Technical:** `deleted()` calls `retireEventSlugsOnDelete()`, which parses the connection's payload for event ids, queries every sibling event-platform connection via `siblingEventIds()` to avoid freeing still-claimed slugs, then calls `EventSlugSync::retireEvents()` — all synchronously, in the same request/job that fired the disconnect. This is structurally identical to `retireVanishedEventSlugs()` (#CACHE-6) but on the delete path. For an organizer with 50 events, disconnecting means the sibling cross-reference query plus up to 50 slug frees complete before the API responds.
    - **Plain English:** When a pro disconnects their event platform, the system immediately cleans up every pretty URL for every event they had listed — right there in the "disconnect" action, before telling the pro "done." The cleanup doesn't need to block that response; it can be handed to a background worker the same way photo cleanup already is, two lines above this code.
    - **Evidence:**
        ```php
        public function deleted(IntegrationConnection $connection): void
        {
            $this->refresher->refresh($connection);
            $this->cleanupMirroredMedia($connection);
            $this->retireEventSlugsOnDelete($connection);
            $this->syncIngestSource($connection);
        }
        ```
        ```php
        private function cleanupMirroredMedia(IntegrationConnection $connection): void
        {
            $folder = InstagramPayload::fromArray($connection->payload)->folder;
            if ($connection->platform === Platform::Instagram->value && $folder) {
                DeleteMirroredMediaJob::dispatch($folder);
            }
        }
        ```

- [ ] **#CACHE-6** · P2 — Event-slug sync and retirement run inline in `IntegrationConnectionObserver::saved()` on every connect and daily refresh
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:106-138, 198-277 (`syncEventSlugs`, `retireVanishedEventSlugs`)
    - **Affects:** Every event-platform connection create/refresh (daily cron + manual reconnects) — per-event slug insert/update and per-vanished-event retirement happen inline before the save's `afterCommit` cycle completes, instead of being deferred to a queue.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract `syncEventSlugs()` and `retireVanishedEventSlugs()` into one dedicated queued job (e.g. `SyncEventSlugsJob`), dispatched from the observer, `ShouldBeUnique` on `(user_id, connection_id)` so repeated payload writes within a debounce window coalesce.
        - Keep the observer's fire-and-forget error handling in the job, not the observer — mirrors `DeleteMirroredMediaJob::dispatch()`, the pattern this same class already uses for Instagram media cleanup.
    - **Technical:** `syncEventSlugs()` iterates every event in the connection's payload calling `EventSlugSync::syncEvents()`, which itself loops per event calling `ItemSlugAllocator::ensureCurrent()` (confirmed in `app/Services/Platforms/EventSlugSync.php:115-128` — a `foreach` over events with one slug-allocator call per event). `retireVanishedEventSlugs()` additionally diffs pre/post-write payloads and cross-references every sibling event connection via `siblingEventIds()`. Both run synchronously in `saved()`, which fires on every connect and every daily refresh. An organizer with 50 events means ~50 slug operations plus a sibling-connection scan inline on every refresh; the daily cron refreshing many such connections serializes this cost per connection on the refresh worker.
    - **Plain English:** Every time a pro's event listings refresh — daily, automatically — the system updates the pretty web address for every single event right then and there, before the refresh is even "done." Someone with 50 events triggers 50 little updates in a row on one worker. The system already knows how to hand this kind of work to a background delivery person (it does this for Instagram photo cleanup); the event URL updates should follow the same pattern so a refresh completes quickly regardless of catalog size.
    - **Evidence:**
        ```php
        if (in_array($connection->platform, EventSlugSync::PLATFORMS, true)
            && ($connection->wasRecentlyCreated || $connection->wasChanged('payload'))) {
            if ($connection->wasChanged('payload')) {
                $this->retireVanishedEventSlugs($connection);
            }

            $this->syncEventSlugs($connection);
        }
        ```
        ```php
        private function syncEventSlugs(IntegrationConnection $connection): void
        {
            try {
                $events = EventSlugSync::extractEvents($connection->resource_kind, $connection->payload);
                app(EventSlugSync::class)->syncEvents($connection->user_id, $events);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('IntegrationConnectionObserver event-slug sync failed', [
        ```
        ```php
        // EventSlugSync::syncEvents — per-event loop, one allocator call each:
        public function syncEvents(string $userId, array $events): void
        {
            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }
                $id = $event['id'] ?? null;
                $name = $event['name'] ?? null;
                if (! is_string($id) || $id === '' || ! is_string($name) || $name === '') {
                    continue;
                }
                $this->slugs->ensureCurrent($userId, ItemSlugAllocator::TYPE_EVENT, $id, $name);
            }
        }
        ```

## P3 — Nice to have

- [ ] **#CACHE-7** · P3 — Connection save conditionally touches the site, triggering a full `SiteObserver` cascade inline (deliberately scoped)
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:86-88 (`$connection->user?->site?->touch()`)
    - **Affects:** Connection saves for platforms with completeness predicates (Fresha and similar) — the `touch()` fires `SiteObserver::saved()`, which dispatches `CloudflareCachePurgeJob`, invalidates the site's Redis cache keys, and conditionally warms the cache, all inline in the same save cycle.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - No functional change required — the code already gates this to `hasCompletenessPredicate()` platforms only, with an in-code rationale explaining why untargeted touching would multiply `SiteObserver`'s cascade cost platform-wide.
        - Optional hardening: wrap the touch in a timed span so a slow-job/slow-route alert can catch cascade latency growth if a future completeness-predicate platform has much higher connection volume than Fresha does today.
    - **Technical:** `touch()` bumps `site.sites.updated_at`, which fires `SiteObserver::saved()` — itself dispatching `CloudflareCachePurgeJob` (already `ShouldBeUnique`, so bursts coalesce) plus `SiteCacheService` invalidation and a conditional cache-warm job — all synchronously inside the connection's `saved()` callback. The code's own comment explains this is intentional and already scope-limited: the "meaningful change" gate above fires on every platform's routine refresh, and touching for every platform would multiply `SiteObserver`'s cascade cost platform-wide, so it's restricted to `hasCompletenessPredicate()` platforms via a descriptor check rather than a hardcoded list. This is a documented trade-off, not an oversight.
    - **Plain English:** For a small set of platforms where "is this page ready to show?" depends on the connection's own data, saving that connection kicks off a chain reaction — clear the edge cache, rotate cache keys, maybe re-warm the cache — all in the same breath as the save. The team already limited this chain reaction to only the platforms that truly need it, like only running sprinklers in the rooms with plants instead of the whole building. The one remaining question is what happens if a future platform in that small set gets a lot more volume than today's; a timer on the sprinkler system would catch that early.
    - **Evidence:**
        ```php
        if (app(PlatformRegistry::class)->get($connection->platform)?->hasCompletenessPredicate()) {
            $connection->user?->site?->touch();
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Ingest projection background hardening:** #CACHE-1, #CACHE-2, #CACHE-3
    - **Why grouped:** Same file cluster (`ProjectionWriter` + `RunExecutor`), same root pattern — synchronous per-item/per-row work inside the `ingest`-queue job that should batch or hand off without regressing the documented land→project freshness guarantee.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — IntegrationConnectionObserver side-effect deferral:** #CACHE-4, #CACHE-5, #CACHE-6, #CACHE-7
    - **Why grouped:** Same file, same pattern — inline synchronous side-effect work (identity sync, slug sync/retirement, site touch) that the observer's own `DeleteMirroredMediaJob::dispatch()` precedent shows how to defer.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.
