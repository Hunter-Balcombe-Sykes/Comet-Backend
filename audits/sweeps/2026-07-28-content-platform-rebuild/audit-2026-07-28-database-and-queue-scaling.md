# Database & Queue Scaling Audit — 2026-07-28

**Branch:** development
**Lens:** Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- config/horizon.php, config/queue.php
- app/Models/Core/Site/SiteMedia.php
- app/Jobs/Ingest/RunSourceJob.php, app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php, app/Jobs/Platforms/EnrichLinkCardJob.php, app/Jobs/Platforms/GoogleBusinessEnrichJob.php, app/Jobs/Site/BuildSiteDocumentJob.php
- app/Services/Analytics/AnalyticsQueryService.php
- app/Services/Platforms/CustomLinkSeeder.php, GoogleBusinessApifyScraper.php, WebsiteLinkHarvester.php
- app/Console/Commands/IngestProjectCommand.php, SiteBuildDocumentsCommand.php, PurgeSoftDeleted.php
- app/Http/Controllers/Api/Site/PageController.php, SectionItemController.php
- app/Http/Resources/Site/PageResource.php, SectionResource.php, SectionItemResource.php
- app/Ingest/Landing/Lander.php
- app/Ingest/Projection/ProjectionWriter.php
- app/Site/Documents/DocumentBuilder.php
- app/Routing/SyncFindingsBridge.php, PlacementPolicy.php
- supabase/migrations/20260728100000_retire_pinterest.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 7 complete  (SCALE-3 re-graded to P3, SCALE-9 to P2 — see their entries)
- P2 Medium: 1 of 13 complete  (+SCALE-9, re-graded from P1 and FIXED)
- P3 Low: 0 of 7 complete  (+SCALE-3, re-graded from P1, deliberately not fixed)

---

## P1 — Fix before pilot launch

- [ ] **SCALE-1** · P1 — `IngestProjectCommand` loads every matching source into memory without chunking
    - **Where:** app/Console/Commands/IngestProjectCommand.php:31-35
    - **Affects:** Any `ingest:project` run against more than a few hundred sources — the `--rebuild` path, the scheduled projection pass, and ad-hoc operator runs against the newly-shipped 21-connector fleet (per `11c399ab`, `f6112c73`).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->get()` with `->cursor()` so the outer loop streams sources one row at a time.
    - **Technical:** `DB::table('ingest.sources')->...->get()` materialises every matching row into a PHP `Collection` before the projection loop starts. With the fleet of connectors landed across the last dozen commits, `ingest.sources` grows one row per user per connected platform — thousands of rows is a near-term reality, not a hypothetical. `cursor()` streams rows one at a time with no behavior change to the loop body.
    - **Plain English:** The projection command loads every source it's about to process into memory before doing any work, like a delivery driver loading every package onto the truck before starting the route. With a few hundred packages it's fine; with thousands, the truck runs out of room. Streaming them one at a time avoids that.
    - **Evidence:**
        ```php
        $sources = DB::table('ingest.sources')
            ->when($this->option('user'), fn ($q, $user) => $q->where('user_id', $user))
            ->when($this->option('source'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('created_at')
            ->get();
        ```
    - `[confidence: 0.9]`

- [ ] **SCALE-2** · P1 — `IngestProjectCommand` issues one `streams` query per source (N+1)
    - **Where:** app/Console/Commands/IngestProjectCommand.php:45-46
    - **Affects:** Every `ingest:project` run — each source triggers a separate round-trip to fetch its streams.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collect all source IDs up front, fetch all streams in one query (`whereIn('source_id', $sourceIds)->get()->groupBy('source_id')`), and look up locally inside the loop.
    - **Technical:** The outer loop iterates every source, and for each one the inner query `DB::table('ingest.streams')->where('source_id', $source->id)->get(...)` fires a separate SQL round-trip. With 2,000 sources that's 2,001 queries. A single `whereIn` preload collapses this to two queries total.
    - **Plain English:** For every source, the command separately asks the database "what streams does this one have?" instead of asking once for the whole batch. At scale, that's the difference between two trips to the database and two thousand.
    - **Evidence:**
        ```php
        foreach ($sources as $source) {
            $streams = DB::table('ingest.streams')->where('source_id', $source->id)->get(['id', 'stream_name']);
        ```
    - `[confidence: 0.9]`

- [ ] **SCALE-3** · P3 (re-graded from P1, 2026-07-29, unit-12 review) — `SiteBuildDocumentsCommand` loads all eligible site IDs into memory via `pluck()`; not a memory problem, and the audit's own prescribed fix does not fix anything
    - **Where:** app/Console/Commands/SiteBuildDocumentsCommand.php:50-56
    - **Affects:** The 5-minute sweeper (`--stale`, normally near-zero rows) and fleet-rebuild runs (`--all`, a rare `BUILDER_REVISION`-bump event).
    - **Effort:** S (~0.5–1h) — **but do not spend it on `cursor()`.**
    - **Re-grade rationale:**
        - **Arithmetic:** one UUID string + `Collection` slot overhead per eligible site ≈ 170 bytes; at 10k sites that is ~1.7 MB. Not a memory finding at pilot scale.
        - **The prescribed fix is a no-op.** `pdo_pgsql` has no unbuffered query mode — libpq materialises the entire result set client-side at `PQexec` time regardless of whether the caller reads it via `get()`, `pluck()`, or `cursor()`. `cursor()` only avoids re-wrapping rows into a `Collection`; it does not bound memory. Shipping it would look like a fix and change nothing measurable.
        - **The real cost is the dispatch loop**, not the ID fetch: `foreach ($siteIds as $siteId) { BuildSiteDocumentJob::dispatch(...) }` is up to 10,000 serial Redis round-trips inside a `->withoutOverlapping(10)` scheduled command (`routes/console.php:449-454`) — ~5–50s depending on RTT. That is SCALE-18's `Bus::batch()` territory, not this finding's.
    - **What to do (if a change is wanted at all):** leave `pluck()`/`SiteBuildDocumentsCommand` alone. If a reviewer insists on belt-and-braces, `chunkById(1000, ..., 'site_id')` is the only real memory-bounding option — **never `cursor()`** — and `$siteIds->count()` at :67 must become an incremented counter, since it is only correct today because the collection is fully materialised.
    - **Plain English:** The list of sites due for a rebuild is small enough (about 1.7 MB at 10,000 sites) that holding it in memory isn't a real problem, and the fix the audit suggested (`cursor()`) wouldn't actually reduce memory use anyway — Postgres's driver loads the full result either way. The one place a genuine cost lives is dispatching thousands of rebuild jobs one at a time, which is a separate finding (SCALE-18).
    - **Evidence:**
        ```php
        if ($this->option('stale')) {
            $siteIds = DB::table('site.site_build_state')
                ->whereColumn('content_revision', '>', 'built_revision')
                ->pluck('site_id');
        } elseif ($this->option('all')) {
            $siteIds = DB::table('site.sites')
                ->where('is_published', true)
                ->pluck('id');
        }
        ```
    - `[confidence: 0.9]`

- [ ] **SCALE-4** · P1 — `Lander::land()` issues 4 database round-trips per landed record
    - **Where:** app/Ingest/Landing/Lander.php:53-98
    - **Affects:** Every ingest run for every stream across the connector fleet. At 1,000 records per run this is 4,000 queries; at a viral-scale replay this scales linearly with record count.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Batch `record_versions` inserts with a multi-row `insertOrIgnore`, then bulk-fetch version IDs with one `whereIn` on `(stream_id, key, doc_hash)`.
        - Batch the `record_state` upsert into a single multi-row `upsert` call.
        - Collapse the "demote previous current version" UPDATE into one bulk statement keyed off the batch's changed keys.
    - **Technical:** Each iteration of the `foreach ($records as $record)` loop does an `insertOrIgnore`, a conditional demotion `update`, a `value('id')` fetch, and an `upsert` — four round-trips per record, none of them batched across the record set. At pre-beta scale this is invisible; as the connector fleet (Instagram, YouTube, Twitch, menu connectors, etc.) accrues historical content per user, a single stream replay of tens of thousands of records becomes a multi-second-to-minutes blocking write.
    - **Plain English:** Landing new content is done one item at a time — check it in, update the ledger, look it up again, update its status — like a librarian re-shelving a thousand books one trip at a time instead of using a cart.
    - **Evidence:**
        ```php
        $inserted = DB::table('ingest.record_versions')->insertOrIgnore([
            'stream_id' => $streamId,
            'key' => $record->key,
            'doc_hash' => $hash,
            'doc' => json_encode($doc),
            'first_seen_run' => $runId,
            'first_seen_at' => now(),
            'is_current' => true,
        ]);

        if ($inserted > 0) {
            $changed++;
            DB::table('ingest.record_versions')
                ->where('stream_id', $streamId)
                ->where('key', $record->key)
                ->where('doc_hash', '!=', $hash)
                ->update(['is_current' => false]);
        }

        $versionId = DB::table('ingest.record_versions')
            ->where('stream_id', $streamId)
            ->where('key', $record->key)
            ->where('doc_hash', $hash)
            ->value('id');

        DB::table('ingest.record_state')->upsert([[ /* ... */ ]], ['stream_id', 'key'], [ /* ... */ ]);
        ```
    - `[confidence: 0.9]`

- [ ] **SCALE-5** · P1 — `Lander::foldAbsence()` loads every non-tombstoned record for a stream into memory
    - **Where:** app/Ingest/Landing/Lander.php:128-131
    - **Affects:** Every ingest run on streams that support deletion. A stream with hundreds of thousands of live records materialises all of them into a PHP collection on every run.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Replace `->get()` with `->cursor()`/`LazyCollection` for the dominance-check pass, or push the whole absence fold into a single SQL statement operating on rows that are actually candidates (not seen this run).
    - **Technical:** `$live = DB::table('ingest.record_state')->...->get(...)` loads every live row for the stream before the dominance loop even starts. At high record counts this is real memory pressure held for the duration of every ingest run, not just a rare rebuild.
    - **Plain English:** To check which records quietly vanished, the system pulls every currently-known record for a stream into memory at once, then checks each one — like emptying an entire filing cabinet onto the floor just to find the folders that are missing, instead of checking the cabinet in place.
    - **Evidence:**
        ```php
        $live = DB::table('ingest.record_state')
            ->where('stream_id', $streamId)
            ->whereNull('tombstoned_at')
            ->get(['key', 'current_version_id', 'absent_runs']);
        ```
    - `[confidence: 0.85]`

- [ ] **SCALE-6** · P1 — `ProjectionWriter::projectStream()` loads every current record for a stream into memory
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:80-87
    - **Affects:** Every `ingest:project` run. A stream with a large volume of current records (a long-running YouTube channel, a large menu, a multi-year event archive) materialises all rows plus their JSONB `doc` column into PHP memory, and this runs synchronously inside `supervisor-ingest`'s single worker.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Use `->cursor()` or chunked batches to process records without holding the full result set in memory.
    - **Technical:** The join between `ingest.record_state` and `ingest.record_versions` returns the full JSONB `doc` for every current, non-tombstoned record and materialises it with `->get()`. At high record counts with multi-KB docs, this is real memory pressure on a worker capped at 192 MiB (`supervisor-ingest`'s deliberately small memory budget per `config/horizon.php`'s own commentary).
    - **Plain English:** Projecting a stream's content reads every single item into memory before processing any of it — like reading an entire book into your head before taking your first note, instead of one chapter at a time.
    - **Evidence:**
        ```php
        $records = DB::table('ingest.record_state as rs')
            ->join('ingest.record_versions as rv', function ($join) {
                $join->on('rv.stream_id', '=', 'rs.stream_id')->on('rv.id', '=', 'rs.current_version_id');
            })
            ->where('rs.stream_id', $streamId)
            ->whereNull('rs.tombstoned_at')
            ->orderBy('rv.first_seen_at')
            ->get(['rs.key', 'rv.doc', 'rv.first_seen_at']);
        ```
    - `[confidence: 0.9]`

- [ ] **SCALE-7** · P1 — `ProjectionWriter::resolveItems()` loads all of a user's source items unbounded, and feeds a PHP-materialised ID array into `whereIn` twice
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:294-357
    - **Affects:** Every projection run. A user with many connected platforms, each contributing thousands of historical items, produces an unbounded `->get()` plus two `whereIn(..., $rows->pluck('id')->all())` calls whose bind-list grows linearly with the user's total item count.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Replace both `whereIn('source_item_id'/'id', $rows->pluck('id')->all())` calls with subqueries (`whereIn('source_item_id', DB::table('content.source_items as si')->join(...)->where(...)->select('si.id'))`) so Postgres never receives a giant literal ID list.
        - Chunk the initial `si`/`identity_keys`/`identity_decisions` reads for very large per-user, per-kind item counts.
    - **Technical:** Three separate unbounded `->get()` calls (`rows`, `keysBySourceItem`, `decisions`) plus a fourth `whereIn(..., $rows->pluck('id')->all())->get()->each(...)` at the end of the method all scale with a user's cumulative source-item count for one content kind. Beyond tens of thousands of items, the `whereIn` literal-list approach risks Postgres bind/query-length limits, independent of the memory cost of materialising `$rows` in the first place. A subquery-based `whereIn` avoids both failure modes at once.
    - **Plain English:** When figuring out which items from different platforms are really the same thing, the system pulls every single item card the user has ever collected into one big pile, then writes out every card's ID by hand into its next database question. For a user with years of content across many platforms, that pile — and that hand-written list — gets big enough to choke the database.
    - **Evidence:**
        ```php
        $rows = DB::table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->where('cs.user_id', $userId)
            ->where('si.kind', $kind)
            ->whereNull('si.removed_at')
            ->get(['si.id', 'si.coord', 'si.source_id', 'si.kind', 'si.first_seen_at']);
        // ...
        $keysBySourceItem = DB::table('content.identity_keys')
            ->whereIn('source_item_id', $rows->pluck('id')->all())
            ->get(['source_item_id', 'key_class', 'key_value'])
            ->groupBy('source_item_id');
        // ...
        DB::table('content.source_items')
            ->whereIn('id', $rows->pluck('id')->all())
            ->get(['id', 'coord', 'item_id'])
            ->each(function (object $row) use ($itemByCoord) { /* ... */ });
        ```
    - `[confidence: 0.9]`

- [ ] **SCALE-8** · P1 — `ProjectionWriter::refreshItemCaches()` issues ~19 queries per item on every projection run
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:656-691
    - **Affects:** Every projection run that touches items — not an edge case. After resolving items, this method executes one `f_text` join, 13 singleton-facet `exists()` checks, and 4 collection-table `exists()` checks per item, plus one UPDATE.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Fetch all `f_text` contributions for the whole batch in one `whereIn('item_id', $itemIds)` query, group in PHP.
        - Fetch presence for each facet/collection table in one `SELECT DISTINCT item_id ... WHERE item_id IN (...)` per table rather than per item.
        - Batch the final `content.items` UPDATE into one multi-row upsert.
    - **Technical:** Unlike SCALE-15/SCALE-16 below (which only fire for the subset of records that went absent this run), this method runs for every changed item on every ordinary projection pass — it is the common case, not an edge case. For 1,000 changed items this is ~19,000 round-trips per run. Collapsing per-table `exists()` checks into one `whereIn`-scoped query per table (18 tables total) plus one batched contribution fetch and one batched UPDATE reduces this to ~20 queries regardless of item count.
    - **Plain English:** After sorting out which items belong together, the system walks to each item's file cabinet separately to update its summary label — a thousand separate trips for a thousand items. Bringing all thousand labels to one desk and updating them together does the same job in a fraction of the trips.
    - **Evidence:**
        ```php
        foreach ($itemIds as $itemId) {
            $contributions = DB::table('content.f_text as ft')
                ->join('content.sources as cs', 'cs.id', '=', 'ft.source_id')
                ->where('ft.item_id', $itemId)
                ->get(['ft.source_id', 'ft.headline', 'cs.priority', 'ft.updated_at'])
                ->map(fn (object $row) => new Contribution(/* ... */))
                ->all();

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

            DB::table('content.items')->where('id', $itemId)->update([ /* ... */ ]);
        }
        ```
    - `[confidence: 0.95]`

- [x] **SCALE-9** · P2 (re-graded from P1, 2026-07-29, unit-12 review — N is real and larger than stated, but this is NOT the hottest read path) — `DocumentBuilder` issues one query per displayed item during document composition
    - **Where:** app/Site/Documents/DocumentBuilder.php:171-175, 380-385 (`resolveSection` → `itemPayload`)
    - **Affects:** `BuildSiteDocumentJob::handle()` (queued, `$timeout = 60`, `$tries = 1`) and the artisan/5-minute-scheduler path in `SiteBuildDocumentsCommand`. **Not the visitor read path** — grep confirms nothing outside `DocumentBuilder` itself and tests reads `site.site_documents`; no controller, route, or resource touches it. The original write-up's "Every public sitepage cache miss… the platform's hottest backend read path" is **false** and is struck.
    - **Effort:** S (~0.5–1h)
    - **Re-grade rationale — the N is bigger than "dozens":**
        - `limit_n` is nullable and defaults to `null` for every preset section except `home.actions`, so the `break` at :179 usually never fires — the loop runs the full candidate list, up to `ruleCandidates()`'s 200 cap.
        - A full preset site has ~13 sections; any pilot creator with a live Instagram/YouTube connector trivially exceeds 200 media/video items, so several sections sit at the 200-item cap.
        - **Worst case ≈ 2 (compose) + 13×202 (per-section + per-item) + 4 (build envelope) ≈ 2,632 queries** for one `build()`. Typical modest pilot user ≈ 60–150. This is not an N+1 of 20.
    - **What to do:** bring `resolveSection()`/`itemPayload()` up to the shape `App\Services\Content\SectionTracer::itemsById()` already uses on the same table (`whereIn('id', ...)->get()->keyBy('id')`) — the two drifted from a shared origin; don't invent a new pattern. Keep iterating `$candidateIds` and looking up in the map — do **not** iterate the map itself, which would silently reorder pins-first ordering and dedupe repeated candidate ids. Keep `itemPayload()`'s returned array (`['id','kind','headline']`) byte-identical in key order and shape — it feeds `json_encode($document)`, and any reordering changes `content_hash` fleet-wide, triggering a full version-bump/CDN-purge storm on the next sweeper pass.
    - **Technical:** the real risk is build-pipeline reliability, not visitor latency: a ~2,600-query build against Supavisor (~1–3ms/round-trip) is 3–8s, survivable alone but degrades sharply under connection-pool pressure during a fleet rebuild; `BuildSiteDocumentJob` has `$tries = 1` (no retry by design), so a timeout leaves the site stale until the 5-minute sweeper re-queues and re-runs the same slow build — a plausible livelock under fleet rebuild, not a latency problem for a waiting visitor.
    - **Plain English:** Building the one document a page is generated from asks the database for its display items one at a time instead of once for the whole batch — and there can be thousands of these one-at-a-time asks per site, not dozens. But no visitor is ever waiting on this: it only runs in the background job/command that rebuilds a site's page, and the actual risk is that a slow build can time out and never retry, leaving a site looking stale until the next sweep — not that a page loads slowly for someone viewing it.
    - **Evidence:**
        ```php
        foreach ($candidateIds as $itemId) {
            if (isset($excluded[$itemId])) {
                continue;
            }
            $item = $this->itemPayload($itemId);
            if ($item !== null) {
                $items[] = $item;
            }
            if ($section->limit_n !== null && count($items) >= (int) $section->limit_n) {
                break;
            }
        }
        // ...
        private function itemPayload(string $itemId): ?array
        {
            $item = DB::table('content.items')->where('id', $itemId)->first();
        ```
    - `[confidence: 0.95]`

## P2 — Should fix

- [ ] **SCALE-10** · P2 — Four active Horizon queues have no long-wait notification thresholds
    - **Where:** config/horizon.php:53-67 (the `waits` array) vs. the queue lists at lines 134 (`supervisor-1`) and 231 (`supervisor-ingest`)
    - **Affects:** Operators monitoring queue health; no alert fires when `platform_refresh`, `platform_connect`, `cloudflare_bulk`, or `ingest` back up.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'redis:platform_refresh' => 300` and `'redis:platform_connect' => 300` (scraping-tier threshold, matching `redis_scraping:scraping`).
        - Add `'redis:cloudflare_bulk' => 600` (bulk purge fan-out can legitimately be slow during a takedown).
        - Add `'redis:ingest' => 300` (the 15-minute dispatcher runs light jobs; a stuck connector could back it up silently).
    - **Technical:** Horizon's `waits` array maps `{connection}:{queue}` to a seconds threshold that triggers its built-in long-wait notification. All four queues named in `supervisor-1` and `supervisor-ingest` have no corresponding entry — every other active queue does. During a Cloudflare outage or connector stall, these can grow unnoticed until Nightwatch catches a job exception, which never fires for congestion alone.
    - **Plain English:** Every background work queue except four has a sensor that beeps when work piles up too long. These four have no sensor — if one jams, nobody finds out until a customer notices something didn't happen. Adding the missing sensors is a one-line config change per queue.
    - **Evidence:**
        ```php
        'waits' => [
            'redis:moderation_high' => 30,
            'redis:notifications' => 60,
            'redis:default' => 60,
            'redis:cloudflare' => 120,
            'redis:cache-warm' => 300,
            'redis:analytics' => 300,
            'redis:images' => 300,
            'redis:streaming' => 120,
            'redis:mail' => 120,
            'redis_scraping:scraping' => 300,
            'redis_scraping:gdpr' => 600,
            'redis_video:videos' => 300,
        ],
        ```
    - `[confidence: 0.95]`

- [ ] **SCALE-11** · P2 — `SiteMedia`'s force-delete hook serialises per-file storage I/O
    - **Where:** app/Models/Core/Site/SiteMedia.php:202-242 (the `forceDeleting` closure)
    - **Affects:** GDPR account deletion (`AccountDeletionService`), admin bulk media purge (`StaffCustomerManagementController`), and the routine 30-day-retention purge (`PurgeSoftDeleted::purgeModel`/`purgeFailedMedia`, which already chunks the DB query at 500 rows but still calls this hook once per row).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Collect variant/original paths inside the hook (no storage calls), dispatch a `DeleteMediaFilesJob` with the path list, and have that job issue batched/concurrent deletes.
        - Keep the existing per-file `catch (\Throwable)` resilience inside the batch job.
    - **Technical:** The `forceDeleting` event fires once per `SiteMedia` row. Its handler queries `mediaVariants` (one DB query per row), then loops calling `Storage::disk(...)->exists()` and `->delete()` per variant plus the original upload — serial HTTP round-trips against R2/S3. `PurgeSoftDeleted` already chunks the surrounding DB query at 500 rows (confirmed: `->chunk(500, ...)`), which bounds memory, but does nothing to bound the per-row storage I/O inside this hook — a user with many uploads still produces thousands of serial HTTP calls within the purge run.
    - **Plain English:** When someone deletes their account, every photo and video needs wiping from cloud storage. The system does this one file at a time — like clearing a warehouse by carrying out one box, walking back, then grabbing the next. For a user with thousands of uploads, that walk takes long enough to risk timing out the cleanup job. Writing down the list of files and handing it to a batch process that clears them in parallel fixes it.
    - **Evidence:**
        ```php
        static::forceDeleting(function (SiteMedia $media): void {
            $variantPaths = $media->mediaVariants()
                ->whereNotNull('path')
                ->get(['disk', 'path']);

            foreach ($variantPaths as $variant) {
                try {
                    $disk = Storage::disk((string) $variant->disk);
                    if ($disk->exists($variant->path)) {
                        $disk->delete($variant->path);
                    }
                } catch (\Throwable $e) {
                    report($e);
                    Log::warning('Failed to delete variant file during SiteMedia force-delete', [ /* ... */ ]);
                }
            }

            if ($media->path) {
                try {
                    $mediaDisk = Storage::disk((string) config('partna.media_disk'));
                    if ($mediaDisk->exists($media->path)) {
                        $mediaDisk->delete($media->path);
                    }
                } catch (\Throwable $e) {
                    report($e);
                    Log::warning('Failed to delete original file during SiteMedia force-delete', [ /* ... */ ]);
                }
            }
        });
        ```
    - `[confidence: 0.85]`

- [ ] **SCALE-12** · P2 — `BuildSiteDocumentJob` lands on the `default` queue with no explicit lane assignment
    - **Where:** app/Jobs/Site/BuildSiteDocumentJob.php:44-47
    - **Affects:** Site document builds — every content change queues a build competing with all other unclassified `default`-queue work on `supervisor-1`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->onQueue('cache-warm')` in the constructor to match the job's role as a cache-population step, or explicitly document that `default` is the intended lane.
    - **Technical:** The job never calls `$this->onQueue()`, so it lands on `redis`'s default queue name, `default` (confirmed in `config/queue.php`). `default` sits second in `supervisor-1`'s strict priority order (after `moderation_high`), ahead of `cloudflare`/`cache-warm`/etc., so this isn't starved by other work today — but a burst of rapid content bumps on one viral site (analytics-driven updates, link-card re-scans) dispatches a matching burst of `BuildSiteDocumentJob`s onto `default`, where they compete with genuinely miscellaneous unclassified work rather than being isolated to a cache-population lane.
    - **Plain English:** Every kind of background task that doesn't have its own assigned lane shares one line. Document rebuilds — which spike when a page goes viral — currently ride that shared line instead of having their own, so a viral moment's rebuild burst sits next to unrelated background jobs.
    - **Evidence:**
        ```php
        public function __construct(
            public readonly string $siteId,
            public readonly string $channel = 'live',
        ) {}
        ```
    - `[confidence: 0.85]`

- [ ] **SCALE-13** · P2 — Staff aggregate analytics queries pass unbounded user-ID arrays into `whereIn`
    - **Where:** app/Services/Analytics/AnalyticsQueryService.php:856-867 (`scopedTable`)
    - **Affects:** Staff aggregate dashboard when a segment filter resolves to a large user-ID set — `visitsAggregate`, `clicksAggregate`, `visitsByBucket`, `clicksByBucket`, `topSections`, `topPages`, `platformClicks`, `sessionsAggregate` all funnel through this method (per its own docblock, deliberately built for staff reuse — "OV-A scope injection").
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Keep the `null`-scope (all users) path as-is — already efficient.
        - For the array scope, either chunk the ID list into batches of ~500, or write the IDs to a temp table and `JOIN` so Postgres can hash-join instead of scanning a large `= ANY(ARRAY[...])`.
        - Add a configurable cap (`config('partna.analytics.staff_segment_max_users', 2000)`) that rejects oversized segments with a 422.
    - **Technical:** Postgres converts a large `IN (...)` list into `= ANY(ARRAY[...])`, but the planner still traverses that array per candidate row. On `analytics.site_visits` (the hottest analytics table), a staff query over a large segment scans within the array on every row rather than using an index or hash join. Not reachable at today's pre-beta scale (no live customers), but the code path is deliberately built for staff segment reuse, so it will be hit as soon as staff segmentation goes live.
    - **Plain English:** Filtering the staff dashboard by a large group of users currently means checking every single traffic record against a long hand-written list of IDs, one by one. Putting that list into a proper lookup table instead lets the database match records to it far more efficiently as the list grows.
    - **Evidence:**
        ```php
        private function scopedTable(string $table, string|array|null $userScope): Builder
        {
            $query = DB::table($table);

            if (is_string($userScope)) {
                $query->where('user_id', $userScope);
            } elseif (is_array($userScope)) {
                $query->whereIn('user_id', $userScope);
            }

            return $query;
        }
        ```
    - `[confidence: 0.85]`

- [ ] **SCALE-14** · P2 — `IngestProjectCommand::dropDerivedRows` feeds unbounded `pluck()->all()` arrays into `whereIn` DELETEs
    - **Where:** app/Console/Commands/IngestProjectCommand.php:123-152
    - **Affects:** The `--rebuild` path — a source with a large content-item count produces DELETE queries carrying a proportionally large bound-parameter list.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Chunk `$sourceItemIds`/`$itemIds` with `array_chunk($ids, 500)` and issue one DELETE per chunk, or replace the `whereIn(...)` DELETEs with subquery-based deletes.
    - **Technical:** `pluck('id')->all()` and `pluck('item_id')->unique()->all()` materialise full ID lists in PHP, then `whereIn` serialises them into the SQL text for each of the 15+ facet-table DELETEs that follow. A user with a large ingested-item count produces DELETE statements with a correspondingly large bound-parameter list.
    - **Plain English:** Rebuilding cleans up a user's old data by writing one database command that lists every item by ID — like handing someone a packing slip hundreds of pages long. Breaking it into smaller batches keeps each command manageable.
    - **Evidence:**
        ```php
        $sourceItemIds = DB::table('content.source_items')
            ->where('source_id', $contentSourceId)
            ->where('stream_id', $streamId)
            ->pluck('id')
            ->all();
        // ...
        $itemIds = DB::table('content.source_items')
            ->whereIn('id', $sourceItemIds)
            ->whereNotNull('item_id')
            ->pluck('item_id')
            ->unique()
            ->all();

        foreach (['item_media', 'offers', 'item_tags', 'f_action'] as $collection) {
            DB::table("content.{$collection}")
                ->whereIn('item_id', $itemIds)
                ->where('source_id', $contentSourceId)
                ->delete();
        }
        ```
    - `[confidence: 0.8]`

- [ ] **SCALE-15** · P2 — `Lander::orderValueFor()` issues one query per absent key inside `foldAbsence`
    - **Where:** app/Ingest/Landing/Lander.php:184-203
    - **Affects:** Streams that declare an `orderField` — the dominance check fetches the full doc JSONB per absent key, on the subset of records that went missing this run (not every run).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Include the order value in the initial `$live` query via a JSON-extraction expression (`doc->>'field'`) so no per-row fetch is needed.
    - **Technical:** `orderValueFor()` runs one query per dominated-absent key, fetching the entire JSONB `doc` just to read one scalar field. Filtered on an indexed `(stream_id, key, is_current)`, it's fast per-call but still a round-trip per key — extracting the field in the initial `foldAbsence` query removes the N+1 entirely.
    - **Plain English:** To check whether a missing item was really removed, the system fetches the item's entire file just to look at one date on it. Noting that date when the item was first scanned avoids the extra trip.
    - **Evidence:**
        ```php
        private function orderValueFor(string $streamId, string $key, StreamSpec $spec): mixed
        {
            if ($spec->orderField === null) {
                return null;
            }

            $doc = DB::table('ingest.record_versions')
                ->where('stream_id', $streamId)
                ->where('key', $key)
                ->where('is_current', true)
                ->value('doc');
        ```
    - `[confidence: 0.9]`

- [ ] **SCALE-16** · P2 — `Lander::foldAbsence()` issues one UPDATE per dominated-absent key
    - **Where:** app/Ingest/Landing/Lander.php:164-178
    - **Affects:** Streams where records genuinely disappear — each vanished key gets its own UPDATE, on the subset of records absent this run.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Compute new `absent_runs` values in PHP and issue one batch UPDATE (a `CASE` expression or multi-row upsert keyed on `(stream_id, key)`), followed by a single bulk UPDATE for keys crossing the tombstone threshold.
    - **Technical:** The loop updates one row at a time via individual `where(...)->update(...)` calls. A single `UPDATE ... CASE ... WHERE (stream_id, key) IN (...)` collapses this to one statement plus one bulk tombstone UPDATE.
    - **Plain English:** When a batch of items are confirmed missing, the system fills out one form per item and files each separately instead of submitting a single spreadsheet.
    - **Evidence:**
        ```php
        $tombstoned = 0;
        foreach ($dominatedAbsent as $row) {
            $runs = (int) $row->absent_runs + 1;
            $update = ['absent_runs' => $runs, 'absent_since' => DB::raw('COALESCE(absent_since, now())')];

            if ($runs >= self::TOMBSTONE_RUNS) {
                $update['tombstoned_at'] = now();
                $tombstoned++;
            }

            DB::table('ingest.record_state')
                ->where('stream_id', $streamId)
                ->where('key', $row->key)
                ->update($update);
        }
        ```
    - `[confidence: 0.85]`

- [ ] **SCALE-17** · P2 — `ProjectionWriter::replaceCollections()` issues per-row INSERTs inside loops for media, offers, and tags
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:548-606
    - **Affects:** Every projection run that produces items with media, offers, or tags — an item with 10 gallery images, 2 offers, and 3 tags is 15 individual INSERTs plus 3 DELETEs, per item.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Accumulate rows across the batch into flat arrays and issue one multi-row `insert()` per table.
        - Hoist `ensureMediaAsset()`'s per-entry lookup into a batch `whereIn` fingerprint check before the insert loop.
    - **Technical:** The method deletes then re-inserts media/offers/tags one row at a time in three separate loops. Accumulating rows in PHP and calling `insert($rows)` once per table per item batch collapses many individual statements into a handful.
    - **Plain English:** Updating an item's photos means deleting the old album, then re-adding each photo with a separate trip — for a thousand items, that's thousands of trips. Deleting the old albums and adding all new photos in one batch order does the same job faster.
    - **Evidence:**
        ```php
        DB::table('content.item_media')->where('item_id', $itemId)->where('source_id', $contentSourceId)->delete();
        foreach ($media as $position => $entry) {
            $assetId = $this->ensureMediaAsset($itemId, (array) $entry);
            DB::table('content.item_media')->insert([ /* ... */ ]);
        }

        DB::table('content.offers')->where('item_id', $itemId)->where('source_id', $contentSourceId)->delete();
        foreach ($offers as $offer) {
            $offer = (array) $offer;
            DB::table('content.offers')->insert([ /* ... */ ]);
        }

        DB::table('content.item_tags')->where('item_id', $itemId)->where('source_id', $contentSourceId)->delete();
        foreach ($tags as $tag) {
            // ...
            DB::table('content.item_tags')->insert([ /* ... */ ]);
        }
        ```
    - `[confidence: 0.9]`

- [ ] **SCALE-18** · P2 — `SiteBuildDocumentsCommand` fan-out loop dispatches one job per site with no `Bus::batch` tracking
    - **Where:** app/Console/Commands/SiteBuildDocumentsCommand.php:63-67
    - **Affects:** Fleet-rebuild (`--all`) and bulk-stale runs — the command reports how many builds it queued, with no visibility into how many succeeded, failed, or are still running.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the dispatch loop in `Bus::batch($jobs)->dispatch()` so Horizon tracks completion/failure counts, and add a `catch()`/`then()` summary log.
    - **Technical:** The loop dispatches each `BuildSiteDocumentJob` individually with no aggregate handle. If a subset fail (e.g. a section references a deleted content item), the only signal is individual failed-job records that age out per `horizon.php`'s `trim` config. `Bus::batch` gives a single trackable outcome.
    - **Plain English:** A fleet rebuild currently sends thousands of letters and drops them in the mailbox at once — you know you sent them, but not how many arrived until someone complains. A tracked batch gives back a single receipt: "delivered X, failed Y" so problems surface immediately.
    - **Evidence:**
        ```php
        foreach ($siteIds as $siteId) {
            BuildSiteDocumentJob::dispatch((string) $siteId, $channel);
        }

        $this->info(sprintf('Queued %d document build(s).', $siteIds->count()));
        ```
    - `[confidence: 0.7]`

- [ ] **SCALE-19** · P2 — `SyncFindingsBridge::conflicts()` loads every blocked conflict for a user, unbounded, to find one
    - **Where:** app/Routing/SyncFindingsBridge.php:55-65 (`conflicts`), used by `findConflict()` at line 48-52
    - **Affects:** The Instagram synced-modal endpoint (`GET /platforms/instagram/synced`) and any caller of `findConflict()` — every request materialises the user's full blocked-conflict list.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Push the `legacyPlatform` filter into the query in `findConflict()` rather than loading everything and filtering in PHP with `Collection::first()`.
        - Add a `->limit()` on `conflicts()` as a defensive cap.
    - **Technical:** `conflicts()` runs an unbounded `->get()->values()` over `routing.source_intents` for the user, and `findConflict()` then does an in-PHP linear scan (`->first(fn...)`) to find one match by surface key. As bio-harvest/website-scan imports run repeatedly, blocked-conflict rows accumulate over time; every synced-modal open re-fetches and re-scans the whole set for a lookup the database could answer directly with one more `WHERE`.
    - **Plain English:** This is like filing every receipt ever received into one folder, then flipping through the whole folder every time someone asks about one specific shop. The folder grows every week even though the question is always about one shop — filtering at the database instead of after loading everything avoids the growing flip-through.
    - **Evidence:**
        ```php
        private function conflicts(User $user): Collection
        {
            return DB::table('routing.source_intents')
                ->where('user_id', $user->id)
                ->where('state', 'blocked')
                ->where('block_reason', 'conflict')
                ->whereIn('origin', self::BIO_ORIGINS)
                ->orderByDesc('first_seen_at')
                ->get()
                ->values();
        }

        public function findConflict(User $user, string $legacyPlatform): ?object
        {
            return $this->conflicts($user)
                ->first(fn (object $intent) => LegacyPlatformMap::legacyFor($intent->surface_key) === $legacyPlatform);
        }
        ```
    - `[confidence: 0.85]`

- [ ] **SCALE-20** · P2 — `PlacementPolicy::isTombstoned()` issues one EXISTS query per link during batch imports
    - **Where:** app/Routing/PlacementPolicy.php:67, 135-146 (`isTombstoned`, called from `decide()`)
    - **Affects:** Website and link-in-bio import runs — one tombstones-table query per routed link.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Have the importers collect all projected `(surfaceKey, identifier)` pairs for the batch, issue one `whereIn` tombstone query for the whole import, and pass a pre-computed lookup into `decide()`.
        - Until that refactor lands, keep imports queue-only (not on a request cycle) so the sequential queries don't compete with user-facing request latency.
    - **Technical:** Every routed link in an import (website scan, link-in-bio scan) calls `PlacementPolicy::decide()`, which calls `isTombstoned()` for non-direct-request origins — one `whereIn(...)->exists()` query per link. At a bounded 200-link-per-import cap this is a latency concern, not a throughput emergency; imports are already rate-limited (3/day/user), but it compounds as concurrent imports scale with user count.
    - **Plain English:** A bouncer checking IDs one person at a time works fine for a trickle of visitors, but when a bus of 200 arrives, walking inside to check the list and back 200 times is slow. Bringing the list outside so all 200 are checked in one pass is the fix.
    - **Evidence:**
        ```php
        if ($context->user !== null && ! $context->isDirectRequest() && $this->isTombstoned($context->user, $projection)) {
            return Placement::reject('tombstoned', $surfaceKey);
        }
        // ...
        private function isTombstoned(User $user, Projection $projection): bool
        {
            $refs = [$projection->surfaceKey];
            if ($projection->identifier !== null) {
                $refs[] = $projection->surfaceKey.':'.$projection->identifier;
            }

            return DB::table('routing.item_tombstones')
                ->where('user_id', $user->id)
                ->whereIn('source_ref', $refs)
                ->exists();
        }
        ```
    - `[confidence: 0.8]`

- [ ] **SCALE-21** · P2 — Migration's bulk UPDATE on `site.platform_connections` has no `lock_timeout` or batching
    - **Where:** supabase/migrations/20260728100000_retire_pinterest.sql:20-25
    - **Affects:** All reads/writes to `site.platform_connections` while this migration applies.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `SET LOCAL lock_timeout = '2s'` (or similar) ahead of the UPDATE, so a lock conflict fails fast and visibly rather than blocking silently.
    - **Technical:** The UPDATE takes a `RowExclusiveLock` on every matching row for the statement's duration. `core.users` is currently 0 rows in prod (per the pilot-readiness state) and Pinterest was a minor connector, so the realistic row count today is small — this is a hardening/pattern concern for migration safety, not an active risk at current scale. `site.platform_connections` is read on public-profile resolution, so the same unguarded pattern applied to a genuinely hot, large table later would be a real risk; adding a `lock_timeout` here establishes the safer pattern going forward.
    - **Plain English:** This one-time cleanup could briefly freeze the connections table while it works if Pinterest connections existed in volume — like a shop shutting its front door to rearrange one shelf. At today's near-zero user count it's a non-event; the fix is about the pattern being safe as the table grows.
    - **Evidence:**
        ```sql
        UPDATE "site"."platform_connections"
           SET "deleted_at" = now(),
               "is_active" = false,
               "updated_at" = now()
         WHERE "surface_key" = 'pinterest.profile'
           AND "deleted_at" IS NULL;
        ```
    - `[confidence: 0.7]`

## P3 — Nice to have

- [ ] **SCALE-22** · P3 — Apify `run-sync-get-dataset-items` call blocks a worker for up to 110 seconds
    - **Where:** app/Services/Platforms/GoogleBusinessApifyScraper.php:51-56; consumed by app/Jobs/Platforms/GoogleBusinessEnrichJob.php:41,78
    - **Affects:** `supervisor-long`'s single-process `scraping` lane during Google Business enrichment refreshes.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Long-term: switch to Apify's async run + poll pattern (start actor run, store run ID, poll in a delayed job) instead of `run-sync-get-dataset-items`.
    - **Technical:** The tactical mitigation the draft proposed — `GoogleBusinessEnrichJob` declaring `$timeout = 130` and living on a dedicated low-concurrency queue — is **already in place**: `GoogleBusinessEnrichJob::$timeout = 130` and it dispatches onto `config('partna.queues.scraping', 'scraping')`, which `config/horizon.php`'s `supervisor-long` deliberately runs at `maxProcesses => 1` with the explicit rationale (per that file's own commentary) that "external APIs are rate-limited, not CPU-bound." The blocking-call concern is real but was a conscious, already-isolated tradeoff, not an oversight — downgraded from the draft's P2 since the immediate risk is already contained; only the longer-term async rewrite remains as an improvement.
    - **Plain English:** This call puts a worker on hold for up to two minutes waiting on an external service. The team already gave that kind of work its own single-lane queue so it can't crowd out anything else — this finding just notes that leaving a callback instead of waiting on hold would be even better, someday.
    - **Evidence:**
        ```php
        $response = Http::withToken($token)
            ->timeout(110)
            ->post(
                'https://api.apify.com/v2/acts/'.config('services.apify.actors.google_places').'/run-sync-get-dataset-items',
                $this->input($placeId),
            );
        ```
    - `[confidence: 0.8]`

- [ ] **SCALE-23** · P3 — `WebsiteLinkHarvester::extractLinks` parses up to 3 MB of HTML into a full in-memory DOM tree just to read `<a href>`s
    - **Where:** app/Services/Platforms/WebsiteLinkHarvester.php:216 (3 MB gate in `harvest()`), 423-436 (`extractLinks`)
    - **Affects:** Memory pressure during website-link harvesting (Google Business enrichment, previous-website scans, link-in-bio scans).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Lower the HTML size gate, or switch `extractLinks()` to a streaming regex-based `<a href>` extractor that avoids building a full DOM tree.
    - **Technical:** `DOMDocument::loadHTML()` builds a complete tree representation of the page even though `extractLinks()` only reads `href` attributes off `<a>` tags. `harvest()` gates at 3 MB before calling this, but `harvestHtml()`/`allOutboundLinks()` (used directly by `ScanPreviousWebsiteContentJob`) call `extractLinks()` on HTML sourced straight from `SafeUrlFetcher`, whose own cap is 10 MB — so some call paths can build a DOM tree from HTML larger than the 3 MB the draft's evidence focused on.
    - **Plain English:** This unpacks an entire shipping container just to read the label on one box. If several large pages are being scanned at once, the memory used to build full page models — when only the links are needed — adds up.
    - **Evidence:**
        ```php
        private function extractLinks(string $html, string $baseUrl): array
        {
            $doc = new \DOMDocument;
            $prev = libxml_use_internal_errors(true);
            $loaded = $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        ```
    - `[confidence: 0.7]`

- [ ] **SCALE-24** · P3 — `AnalyticsQueryService::countries()` loads every country row before discarding all but the top 4
    - **Where:** app/Services/Analytics/AnalyticsQueryService.php:193-214
    - **Affects:** The professional dashboard's "Top Countries" widget. Bounded at ~195 countries, so memory impact is trivial — flagged for query-planner hygiene, not a crash risk.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Push a `LIMIT` into the SQL for the top-N rows and issue a second `SUM` (or a window-function `CASE`) for the "OTHER" bucket.
    - **Technical:** The query groups and orders in SQL but calls `->get()` before PHP discards everything past the top 4 with `->take(4)`. For the single-user path the effect is negligible (~195 rows max); on the staff aggregate path (`$userScope = null`, scanning across all users) pushing the limit into SQL matters more.
    - **Plain English:** This asks for a full report of every country ever visited from, then reads only the first four lines and throws the rest away. Letting the database stop after finding the top few avoids the wasted work.
    - **Evidence:**
        ```php
        $raw = DB::table('analytics.site_visits')
            ->where('user_id', $userId)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("COALESCE(country_code, 'UN') as country_code, COUNT(DISTINCT {$this->uniqueVisitorExpr()}) as visitors")
            ->groupByRaw("COALESCE(country_code, 'UN')")
            ->orderByDesc('visitors')
            ->get();

        $top = $raw->take(4)->map(fn ($r) => [ /* ... */ ])->all();
        ```
    - `[confidence: 0.8]`

- [ ] **SCALE-25** · P3 — `AnalyticsQueryService::regions()` returns all rows with no limit, unlike sibling geo methods
    - **Where:** app/Services/Analytics/AnalyticsQueryService.php:483-498
    - **Affects:** The "Regions" chart. Practically bounded at ~50-100 rows for large countries, but inconsistent with `countries()`/`cities()`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `->limit()` matching the pattern used by `cities($limit)` and `countries()`'s top-4 behavior.
    - **Technical:** This is the only geo-breakdown method with neither a SQL `LIMIT` nor a PHP `->take(N)`. Adding one is defense-in-depth against a country with unusually fine-grained region resolution.
    - **Plain English:** Every other geography chart knows when to stop listing results; this one doesn't. Adding a cutoff keeps it consistent and readable.
    - **Evidence:**
        ```php
        return DB::table('analytics.site_visits')
            ->where('user_id', $userId)
            ->where('country_code', $countryCode)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("COALESCE(region_code, 'UN') as region_code, COUNT(DISTINCT {$this->uniqueVisitorExpr()}) as visitors")
            ->groupByRaw("COALESCE(region_code, 'UN')")
            ->orderByDesc('visitors')
            ->get()
        ```
    - `[confidence: 0.75]`

- [ ] **SCALE-26** · P3 — `visitsByBucket()`/`clicksByBucket()` return hourly-bucketed results without a LIMIT
    - **Where:** app/Services/Analytics/AnalyticsQueryService.php:125-153
    - **Affects:** The dashboard's "Views"/"Clicks" time-series charts. A 365-day range with `hourly=true` produces up to 8,760 rows for a chart that typically renders ~30-90 points.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Auto-fall-back to daily bucketing when the requested range exceeds a threshold (e.g. > 90 days with `hourly=true`).
    - **Technical:** Neither method caps rows returned; Postgres handles the volume fine, but JSON serialization and client-side chart rendering both churn on data that gets aggressively downsampled anyway.
    - **Plain English:** Asking for an hourly breakdown of a full year currently returns nearly 9,000 data points for a chart that can only show about 50 bars — switching to daily summaries automatically for wide ranges avoids the oversized response.
    - **Evidence:**
        ```php
        public function visitsByBucket(string|array|null $userScope, Carbon $from, Carbon $to, bool $hourly): Collection
        {
            [$bucketExpr, $bucketGroup] = $this->bucketExpressions($hourly);

            return $this->scopedTable('analytics.site_visits', $userScope)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw("{$bucketExpr} as day, COUNT(*) as count")
                ->groupByRaw($bucketGroup)
                ->orderBy('day')
                ->get();
        }
        ```
    - `[confidence: 0.7]`

- [ ] **SCALE-27** · P3 — `ProjectionWriter::recordCandidates()` issues one `insertOrIgnore` per candidate in a loop
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:455-474
    - **Affects:** Projection runs where the resolver produces many evidential-tier candidate pairs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Accumulate candidate rows in a PHP array and issue a single `insertOrIgnore($rows)` after the loop.
    - **Technical:** Each `insertOrIgnore` call is a separate round-trip; the query builder accepts an array of rows for one bulk call. Candidates are typically low-volume today, so this is a low-effort win rather than an active bottleneck.
    - **Plain English:** The system writes each "maybe these are the same?" note on its own separate sticky note and files each one individually instead of filing them together on one page.
    - **Evidence:**
        ```php
        foreach ($candidates as $candidate) {
            $left = $itemByCoord[$candidate->left] ?? null;
            $right = $itemByCoord[$candidate->right] ?? null;
            if ($left === null || $right === null || $left === $right) {
                continue;
            }

            DB::table('content.identity_candidates')->insertOrIgnore([ /* ... */ ]);
        }
        ```
    - `[confidence: 0.9]`

## Suggested Bundled Sessions

- **Bundle 1 — Ingest console command batch hygiene:** SCALE-1, SCALE-2, SCALE-14
    - **Why grouped:** All three live in `IngestProjectCommand.php` and share the same "unbounded/N+1 query" root cause.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Site-build fleet command hygiene:** SCALE-3, SCALE-18
    - **Why grouped:** Both live in `SiteBuildDocumentsCommand.php`; the `cursor()` and `Bus::batch()` changes touch the same dispatch loop.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Ingest landing/projection micro-batching:** SCALE-5, SCALE-15, SCALE-16, SCALE-27
    - **Why grouped:** All are same-file (`Lander.php`/`ProjectionWriter.php`), same-pattern (per-row loop → batched statement) fixes at M/S effort.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Analytics query result-set limits:** SCALE-13, SCALE-24, SCALE-25, SCALE-26
    - **Why grouped:** All in `AnalyticsQueryService.php`; all "cap an unbounded/oversized result set" fixes.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Horizon/queue routing hygiene:** SCALE-10, SCALE-12
    - **Why grouped:** Both are queue-shape/observability config changes in the same subsystem.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 6 — Routing placement batch-query hygiene:** SCALE-19, SCALE-20
    - **Why grouped:** Both in `app/Routing`; both are per-item query loops during batch imports.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 7 — Platform scan hardening:** SCALE-22, SCALE-23
    - **Why grouped:** Both in `app/Services/Platforms`; both are low-urgency latency/memory hardening for the website-scan pipeline.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **SCALE-4 — `Lander::land()` per-record query batching** · L-effort.
- **SCALE-6 — `ProjectionWriter::projectStream()` unbounded read** · L-effort.
- **SCALE-7 — `ProjectionWriter::resolveItems()` unbounded read + bind-list overflow** · L-effort.
- **SCALE-8 — `ProjectionWriter::refreshItemCaches()` per-item query storm** · L-effort.
- **SCALE-9 — `DocumentBuilder` N+1, build-pipeline reliability (NOT visitor read path — re-graded P2)** · N is larger than originally stated (~2,632 queries worst case); isolate for careful before/after verification even though effort is S.
- **SCALE-11 — `SiteMedia` force-delete storage I/O** · touches GDPR account-deletion data path; isolate for sign-off.
- **SCALE-21 — Migration `lock_timeout` hardening** · DB migration.
