# Database & Queue Scaling Audit — 2026-08-18

**Branch:** audit-fix/instagram-wave-findings-2026-08-18
**Lens:** Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude`
**Source files audited:**
- app/Http/Resources/Routing/RoutingConnectionResource.php
- routes/console.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php
- app/Services/Media/MediaMirror.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/Registry/DerivedDescriptorFactory.php
- app/Console/Commands/EnrichPendingCardsCommand.php
- app/Console/Commands/RefreshItemCachesCommand.php
- app/Console/Commands/ReshapePoolSectionsCommand.php
- app/Console/Commands/RetireLegacyGooglePhotoRecordsCommand.php
- app/Content/Identity/Resolver.php
- app/Ingest/Landing/Lander.php
- app/Ingest/Projection/ProjectionWriter.php
- app/Site/Pools/BorrowedMedia.php
- app/Site/Pools/ItemLinkRules.php
- app/Site/Pools/PoolResolver.php
- app/Site/Sections/SectionCandidates.php
- app/Console/Commands/RefreshIntegrationConnectionsCommand.php
- app/Console/Commands/BackfillSubdomainKvCommand.php
- app/Catalog/CompiledCatalog.php
- config/horizon.php
- config/partna.php
- supabase/migrations/20260819001000_link_observations_allow_commerce_probe.sql
- supabase/migrations/20260819001100_item_media_role_video.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 2 of 6 complete
- P2 Medium: 7 of 16 complete
- P3 Low: 0 of 7 complete

---

## P1 — Fix before pilot launch

- [x] **#SCALE-1** · P1 — Pool default/occurrence sort runs a correlated scalar subquery per candidate row on the public hot path
    - **Resolved 2026-08-25** (`audit-fix/scale-memory-sort-2026-08-25`): the correlated subquery STAYS — it is load-bearing (both facet tables are keyed `(item_id, source_id)`, so the join this finding asks for emits an item once per source; `SectionCandidateOrderingTest` pins that). What was actually wrong is that each probe found the row on the PK and then heap-fetched for the sorted column. Two covering indexes (`20260825120000`/`120001`) let Postgres serve it as an Index Only Scan Backward + LIMIT 1. Measured on dev over a 548-item library: **4099 shared buffers / 7.6ms → 3013 / 5.0ms**. Two reshapes were MEASURED and rejected: the pre-aggregated join this finding asks for is **108 buffers but 7.7ms** and plans as a Seq Scan + HashAggregate over EVERY user's facet rows, so its cost tracks total platform content instead of the one site being rendered — backwards for a per-site render; and collapsing the recency branch's two SubPlans to one only holds behind an `OFFSET 0` fence (1545 buffers / 2.5ms), which has no spelling the query builder can emit for both Postgres and the SQLite test lane. Reasoning is in the code at the ORDER BY; indexes pinned by `tests/Schema/IndexCoverageTest.php`.
    - **Where:** app/Site/Sections/SectionCandidates.php:116-130
    - **Affects:** Public sitepage pool resolution (watch/listen/media/shop/services/menus/custom_links via recency sort, events via occurrence sort) on every cache-miss/rebuild render.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the correlated `(SELECT MIN/MAX ... WHERE fo.item_id = content.items.id)` in `orderByRaw` with a pre-aggregated join or window function computed once over the candidate set before the `LIMIT`.
        - In the meantime, confirm covering composite indexes exist on `content.f_occurrence(item_id, starts_at_utc)` and `content.f_published(item_id, published_from)`.
    - **Technical:** `ruleCandidates()` orders by a correlated scalar subquery against `content.f_occurrence`/`content.f_published` keyed to the outer `content.items` row. Because the sort key is a correlated subquery, Postgres generally must evaluate it for every row matching the `WHERE` predicates before it can determine the top `CANDIDATE_SCAN_LIMIT` (200) rows — the `LIMIT` bounds the *output*, not the subquery evaluations. `PoolResolver::resolve()`/`hasSelection()` call this on every pool resolution, and per the class docblock this is now the single executor shared by `DocumentBuilder`, `SectionTracer`, and `PoolResolver` — i.e. the actual public-page render path, not a diagnostic-only tool. A user with a large content library (thousands of Instagram photos, a large menu, a large shop) pays for a per-row lookup on every cache-miss/rebuild.
    - **Plain English:** To sort a big content library by date, the site currently looks up each item's date one at a time in a separate lookup before picking the newest 200 to show. The bigger a professional's library gets, the slower their page is to rebuild after a cache miss — and a popular page hits cache misses more often, not less.
    - **Evidence:**
        ```php
        'occurrence' => $query->orderByRaw(
            '(SELECT MIN(fo.starts_at_utc) FROM content.f_occurrence fo'
            .' WHERE fo.item_id = content.items.id) ASC NULLS LAST'
        ),
        // ...
        default => $query->orderByRaw(
            'COALESCE((SELECT MAX(fp.published_from) FROM content.f_published fp'
            .' WHERE fp.item_id = content.items.id), content.items.first_seen_at) DESC, content.items.id DESC'
        ),
        ```

- [ ] **#SCALE-2** · P1 — Auto-selection ("newest per source") runs a correlated COUNT subquery scanning the whole source per candidate row
    - **Where:** app/Site/Sections/SectionCandidates.php:330-373 (`connectionSourceLatestArm`), 393-428 (`storefrontLatestArm`)
    - **Affects:** Public pool resolution for `latest_per_auto_source` / `latest_n_per_auto_source` pools (watch/listen/media default, shop storefront auto-selection) — the default rule shape for most connected sources.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Replace the correlated `count(*) ... < ?` subquery with a `ROW_NUMBER() OVER (PARTITION BY source_id ORDER BY ...)` computed once per source rather than re-scanned per candidate.
        - Replace `storefrontLatestArm()`'s correlated `whereNotExists` similarly as storefront catalogues grow.
        - Verify composite indexes on `content.source_items(source_id, item_id, removed_at)` and `content.f_published(item_id, source_id, published_from)`.
    - **Technical:** For every candidate row, the `whereRaw` correlated subquery re-joins `content.source_items`/`content.items`/`content.f_published` scoped to that row's `source_id` and counts strictly-newer same-source items. This is O(candidates × source size) — an Instagram account with thousands of media items re-scans its own history for every one of those items on every pool resolve. `storefrontLatestArm()` has the analogous `whereNotExists` shape for shop catalogues. Standalone (L effort) — needs its own plan given the query is load-bearing for correctness (tie-breaking on first_seen_at + id, per the code's own F1 comment about an earlier live incident).
    - **Plain English:** To find "the newest post from this account," the site re-checks every other post from that same account, for every candidate it's considering — instead of sorting the account's posts once and taking the top one. For an account with thousands of posts, that's like re-sorting an entire closet to find the most recent hanger, over and over.
    - **Evidence:**
        ```php
        $e->whereRaw(
            '(select count(*) from content.source_items as si2'
            .' join content.items as i2 on i2.id = si2.item_id'
            .' left join content.f_published as p2 on p2.item_id = i2.id and p2.source_id = si2.source_id'
            .' left join content.f_published as p1 on p1.item_id = content.items.id and p1.source_id = content.source_items.source_id'
            .' where si2.source_id = content.source_items.source_id'
            .' and si2.removed_at is null and i2.removed_at is null'
            .' and i2.id <> content.items.id'
            .' and '.$kindSql
            .' and (COALESCE(p2.published_from, i2.first_seen_at) > COALESCE(p1.published_from, content.items.first_seen_at)'
            .' or (COALESCE(p2.published_from, i2.first_seen_at) = COALESCE(p1.published_from, content.items.first_seen_at)'
            .' and i2.id > content.items.id))'
            .') < ?',
            [...($kinds ?? []), $n]
        );
        ```

- [x] **#SCALE-3** · P1 — MediaMirror loads entire fetched media bodies into PHP memory before storing
    - **Resolved 2026-08-25** (`audit-fix/scale-memory-sort-2026-08-25`): the body now streams to a temp file via the new `SafeUrlFetcher::tryFetchToFile()` and never becomes a PHP string on the video path — `hash_file()` + `Storage::writeStream()` replace `hash()` + `put()`. The image path still needs a string (GD's `imagecreatefromstring()` takes one) but only AFTER the 15 MB check has bounded it, so the 80 MB video ceiling no longer reaches PHP memory on any path. The sink is threaded through the EXISTING `send()` redirect loop rather than given its own, so every SSRF guarantee is unchanged and pinned by new hop-revalidation tests. Temp file is deleted in a `finally` around the whole mirror, pinned on both the success and failure paths. Also fixed in passing: a `false` return from a non-throwing disk was treated as success, writing a `storage_path` for an object that does not exist.
    - **Where:** app/Services/Media/MediaMirror.php:77-145
    - **Affects:** Media-mirror pipeline (`images`/media queue) during any burst of owned-media (Instagram) mirroring — e.g. a first-time connect or a viral spike driving many concurrent mirrors.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a streaming/spool-to-temp-file fetch path so bytes are written incrementally instead of held as one PHP string via `$response['body']`.
        - Hash incrementally while streaming, then hand a file path/stream to `Storage::put()` and `WebpEncoder` instead of a full in-memory string.
    - **Technical:** `mirror()` calls `SafeUrlFetcher::tryFetch()` capped at `MAX_VIDEO_BYTES` (80 MB) and then performs `strlen($body)`, `substr($body, 4, 4)`, `hash('sha256', $body)`, and `Storage::put($path, $body)` — every one of these requires the full body resident in PHP memory, and `Storage::put()` on a string body typically copies it again into the Flysystem write stream. `MirrorMediaAssetJob` dispatches one job per asset (`ProjectionWriter::dispatchMirrors()`), and a first-sync of a large Instagram account or a viral-spike burst of concurrent mirror jobs can each allocate up to 80 MB simultaneously on `supervisor-1`'s shared workers (`memory` restart threshold 256 MB per worker in `config/horizon.php`).
    - **Plain English:** Right now, fetching one photo or video means holding the whole file — up to 80 MB for a video — in the computer's short-term memory all at once while checking it and saving it. If several of these happen at the same time (like during a burst of new photos on a professional's page), the system can run out of that memory. Streaming the file to disk in small pieces instead avoids the pile-up.
    - **Evidence:**
        ```php
        $response = $this->fetcher->withMaxBytes(self::MAX_VIDEO_BYTES)->tryFetch($sourceUrl);
        // ...
        $body = $response['body'];
        if (strlen($body) > 12 && substr($body, 4, 4) === 'ftyp') {
            if (strlen($body) > self::MAX_VIDEO_BYTES) {
                return $this->fail($assetId, 'video_too_large', $sourceUrl);
            }
            $path = 'content-media/'.$userId.'/'.substr(hash('sha256', $body), 0, 32).'.mp4';
            try {
                Storage::disk(config('partna.media_disk'))->put($path, $body, ['ContentType' => 'video/mp4']);
        ```

- [ ] **#SCALE-4** · P1 — One `item_anchors` query per resolved identity group on every projection run
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:655-660 (`resolveItems`), 704-710 (`bindGroup`)
    - **Affects:** Every scheduled connector projection run (Instagram, Fresha, menus, Apple Music, etc.), for every user with more than a handful of live source items.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Preload all anchors for the union of coords across every group in one `whereIn('coord', $allGroupCoords)` query, keyed by coord, and pass the resulting map into `bindGroup()`.
        - Keep `bindGroup()`'s own writes (insert/redirect) unchanged; only the read moves.
    - **Technical:** `resolveItems()` iterates `$resolution->groups` and calls `bindGroup()` once per group; in steady state groups are mostly singletons (one per live source item), so `bindGroup()` runs one `content.item_anchors` query per item in the run. This is a genuine, currently-unfixed N+1 distinct from the adjacent `keysBySourceItem` query, which the code's own "SCALE-7" comment documents as already having been converted to a bind-list-safe subquery — that fix did not touch `bindGroup()`'s per-group anchor read. For a connector run landing thousands of items (a large Instagram/Fresha catalogue), this is thousands of sequential round-trips on every scheduled sync.
    - **Plain English:** Every time the system files away one piece of content, it walks back to the filing cabinet and opens the same drawer again — instead of pulling everything it needs for the whole batch in one trip. For an account with thousands of items, that's thousands of extra trips on every routine sync.
    - **Evidence:**
        ```php
        foreach ($resolution->groups as $group) {
            $itemId = $this->bindGroup($userId, $kind, $group);
            foreach ($group as $coord) {
                $itemByCoord[$coord] = $itemId;
            }
        }
        ```
        ```php
        $anchors = DB::table('content.item_anchors')
            ->where('user_id', $userId)
            ->whereIn('coord', $group)
            ->orderBy('bound_at')
            ->get(['coord', 'item_id', 'superseded_by', 'bound_at']);
        ```

- [ ] **#SCALE-5** · P1 — Singleton facet upserts fire one query per facet per item on every projection run
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:988-990 (`writeFacets`), 1016-1020 (`upsertSingletonFacet`)
    - **Affects:** Every scheduled connector projection run — write amplification scales with `items in run × populated singleton facets` (up to 13 facet tables: f_text, f_link, f_duration, f_published, f_occurrence, f_embed, f_playable, f_authored, f_catalog, f_place, f_rated, f_review, f_channel, f_file).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Accumulate rows per facet table across the batch and issue one multi-row `upsert()` per facet per `writeChunk()` batch, mirroring the batching `replaceCollections()` already does for collection facets (media/offers/tags/variants/collections).
        - Preserve the `['item_id', 'source_id']` conflict key and column list per facet.
    - **Technical:** The team already fixed this exact write-amplification pattern once in this file: `replaceCollections()`'s own docblock (SCALE-17/#CACHE-2) explains that collection facets used to cost "18 statements on its own" per item and were rewritten to chunked multi-row inserts. That fix did not extend to the SINGLETON facets — `writeFacets()` still calls `upsertSingletonFacet()` once per (item, facet) pair, and each call is its own `upsert()`. A run landing 1,000 records with even 3-4 populated singleton facets each is 3,000-4,000 individual upsert round-trips, on top of the source-item and identity-key writes already in the per-record transaction.
    - **Plain English:** Each piece of content has several small facts about it (a headline, a link, a date, etc.). The system currently files each fact as its own separate paperwork trip, one at a time — even though the same batching trick was already applied to a similar part of this exact file. Filing them together in one trip per batch would be much faster during every routine sync.
    - **Evidence:**
        ```php
        foreach ($facets as $facet => $columns) {
            $this->upsertSingletonFacet($itemId, $contentSourceId, (string) $facet, (array) $columns);
        }
        ```
        ```php
        DB::table("content.{$facet}")->upsert(
            [$row + ['item_id' => $itemId, 'source_id' => $contentSourceId, 'updated_at' => now()]],
            ['item_id', 'source_id'],
            array_merge(array_keys($row), ['updated_at']),
        );
        ```

- [ ] **#SCALE-6** · P1 — New CHECK constraint on the partitioned `routing.link_observations` table added without `NOT VALID`
    - **Where:** supabase/migrations/20260819001000_link_observations_allow_commerce_probe.sql:16-22
    - **Affects:** Routing observation ingest (`CommerceProbeJob` and every other observation writer) during whenever this migration is applied against a database with existing partition data.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add the widened CHECK as `NOT VALID`, then run `ALTER TABLE ... VALIDATE CONSTRAINT` as a separate follow-up step outside the deploy critical path.
        - Set `SET lock_timeout` / `statement_timeout` before the `ALTER` so a long validation on any one partition can't hold the write path open indefinitely.
    - **Technical:** This migration's own comment states `routing.link_observations` is "partitioned by month" and that "ALTER on the parent rewrites the constraint on every partition" — the team already identified the mechanism. Postgres `ADD CONSTRAINT ... CHECK` without `NOT VALID` validates every existing row under an `ACCESS EXCLUSIVE` lock before committing. The migration's stated purpose is urgent (it fixes a live bug where every `commerce_probe` observation write has been silently failing the old CHECK and losing the observation), so applying it promptly matters — but as written it risks blocking observation writes for however long the full-partition-set validation takes, exactly on the write path this fix is meant to restore. This is dated 2026-08-19 in its own filename (newer than the latest commit in the provided git log), so it has very likely not been applied yet — the safer two-step form costs nothing to switch to before that happens.
    - **Plain English:** This is a real bug fix — right now, a certain kind of activity log entry is silently being thrown away because the database rejects it. The fix is good, but the way it's written makes the database stop and double-check every single old log entry (across many months of history) before it will accept the fix, freezing new log writes for however long that check takes. Applying the new rule immediately and checking the old history quietly in the background avoids that freeze.
    - **Evidence:**
        ```sql
        ALTER TABLE routing.link_observations
            DROP CONSTRAINT IF EXISTS link_observations_source_check;

        ALTER TABLE routing.link_observations
            ADD CONSTRAINT link_observations_source_check
            CHECK (source = ANY (ARRAY['paste', 'website_import', 'link_in_bio',
                'bio_harvest', 'google_business', 'staff', 'reproject', 'commerce_probe']));
        ```

## P2 — Should fix

- [ ] **#SCALE-7** · P2 — Collection upsert writes the whole batch in one unbounded statement while its own read-back is chunked
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:1284-1312 (`upsertCollections`)
    - **Affects:** Connector runs that surface many collections/categories in one pass (large menu category sets, large product collection sets).
    - **Effort:** S (~0.5–1h)
    - **What to do:** Wrap the `content.collections` `upsert()` call in `array_chunk($rows, $this->writeChunk())`, matching the chunked read-back a few lines below it in the same method.
    - **Technical:** `upsertCollections()` builds `$rows` for every wanted collection and issues a single `DB::table('content.collections')->upsert($rows, ...)` call with no chunking, while the read-back immediately below it (`foreach (array_chunk(array_values($wanted), $this->writeChunk()) as $chunk)`) is already chunked — an inconsistency within the same method. A connector surfacing thousands of categories in one run risks a single oversized statement.
    - **Plain English:** One part of this filing step checks in arriving boxes a trayful at a time, but the very next step tries to file them all in one giant stack. A large menu or product catalogue could jam on that one step.
    - **Evidence:**
        ```php
        DB::table('content.collections')->upsert(
            $rows,
            ['user_id', 'kind', 'external_ref'],
            ['label', 'updated_at'],
        );
        ```

- [ ] **#SCALE-8** · P2 — Projection writer accumulates the entire stream's projections in PHP memory before writing facets
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:143-246 (`projectStream`)
    - **Affects:** Large single-run connector syncs (a big first-time Instagram/Fresha catalogue import).
    - **Effort:** L (~1–2d)
    - **What to do:** Restructure so `writeFacets()`/`resolveItems()` are invoked per page of `lazy(500)` records rather than once after the full stream has been traversed, verifying `resolveItems()`'s per-user-per-kind re-query (not per-record) makes this safe to page — unlike the row/identity-key reads flagged and explicitly rejected as un-chunkable elsewhere in this same file.
    - **Technical:** Records are read via `lazy(500)`, but `$projections[$coord] = $projection` accumulates across the WHOLE stream before `resolveItems()`/`writeFacets()`/`refreshItemCaches()` run once at the end. Unlike the identity-resolution row set (which the code's own comments explain must be read whole for correctness — see the note on SCALE-4 above), `resolveItems()` re-queries live state fresh each call, so chunking this accumulation does not carry the same correctness risk. Standalone (L effort) given the restructuring needs to preserve same-source replace semantics.
    - **Plain English:** Content comes in on a conveyor belt in small batches, which is good — but the system piles every batch up at the end before putting any of it away. A very large one-time import (thousands of photos) turns that pile into a memory strain the smaller, steady batches don't need to cause.
    - **Evidence:**
        ```php
        $projections = [];
        foreach ($records as $record) {
            // ...
            $projections[$coord] = $projection;
        }
        // ...
        if ($projections !== []) {
            $itemByCoord = $this->resolveItems($userId, $projector::kind());
            $this->writeFacets($contentSourceId, $userId, $projections, $itemByCoord);
        }
        ```

- [ ] **#SCALE-9** · P2 — Slug maintenance runs `ensureCurrent()` once per item inside the projection cache-refresh batch loop
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:1671-1678 (`refreshItemCaches`)
    - **Affects:** Every projection run for a slugged kind (events, media, products, etc.) — the batch's per-table existence checks were already fixed (see the file's own SCALE-8 comment); this per-item call was not.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Preload existing current-slug state for the whole batch in one query (`content.item_slugs WHERE item_id IN (batch) AND is_current`), and only invoke `ensureCurrent()` for items whose slug is missing or whose headline actually changed.
    - **Technical:** `refreshItemCaches()`'s own docblock (SCALE-8) documents that the OTHER 18 per-item existence checks in this same method were rewritten from one-per-item to one-per-table-per-batch. The slug call was left out of that rewrite — it still runs `$this->slugs->ensureCurrent(...)` inside `foreach ($batch as $itemId)`, and per its own comment "the common path costs one SELECT" — meaning this is the one remaining per-item DB round-trip in an otherwise fully-batched method.
    - **Plain English:** This step already got a speed fix for almost everything it checks — except one: making sure each item's public web address is current. That one still gets checked item-by-item instead of as a group, undoing part of the earlier fix's benefit.
    - **Evidence:**
        ```php
        if ($headline !== null && $headline !== ''
            && in_array((string) $row->kind, ContentItemSlugAllocator::SLUGGED_KINDS, true)) {
            try {
                $this->slugs->ensureCurrent((string) $row->user_id, (string) $itemId, (string) $headline);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        ```

- [x] **#SCALE-10** · P2 — Evidential-key candidate generation is O(n²) per shared key with no cap
    - **Resolved 2026-08-26** — capped, and the cap is SURFACED. `Resolver::resolve()` step 5 now takes `maxMembersPerKey` / `maxCandidatesPerKey` as ARGUMENTS (defaults 100 / 200, mirroring `config('partna.ingest.*')`): the member cap bounds the ITERATION, the candidate cap bounds what is appended and breaks out of that key's loops immediately. Two knobs deliberately — the candidate cap alone does not bound the work, because if nearly every pair is already grouped or cut the loops can run the full O(m^2) without ever appending enough to trip it. **The caps arrive as DATA, never as a config read inside the resolver** — the same rule `LinkProjector` follows for detector suspensions, and what keeps a resolve reproducible from its arguments alone; `ProjectionWriter::resolveItemsLocked()` owns the config read and the logging, because user + kind live at that seam. ONE `Log::warning` per RUN with a bounded 5-key sample, never one per key or per pair: a silent cap means items quietly stop being offered for merge, which is invisible on a green run. Growth curve for one shared key, before -> after: 50 members 1,225 -> 200 pairs; 200 members 19,900 -> 200; 1000 members 499,500 -> 200 (122.5ms -> 2.3ms). Deterministic first-N, not a sample, pinned by a two-run identity test; below the caps the candidates, their order and their evidence are unchanged.
    - **Where:** app/Content/Identity/Resolver.php:75-87
    - **Affects:** Any user whose catalogue has many items sharing one weak (evidential-tier) identity key — e.g. a generic track/episode title repeated across a large music or podcast catalogue.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Cap candidate-pair generation per key value at a configurable ceiling (e.g. `config('partna.ingest.max_candidates_per_key')`), sampling or taking the first N members rather than every pair.
    - **Technical:** For every evidential key value, `resolve()` runs a full nested-pair loop (`for $i`, `for $j = $i+1`) over every member sharing that key, appending a `Candidate` per pair not already grouped or cut. Evidential keys never merge automatically — they only ever surface for human review via `content.identity_candidates` — so this is a pure CPU/memory concern, not a correctness one, but a user with thousands of items sharing one loose title value could generate an enormous candidate set that both stalls the projection worker and floods the candidates table (see #SCALE-11).
    - **Plain English:** If a user's catalogue has thousands of items all sharing one generic title (like "Live" or "Untitled"), the system tries to compare every single one of those items against every other one — producing a mountain of "maybe these are the same?" notes instead of a manageable shortlist.
    - **Evidence:**
        ```php
        foreach ($this->keyIndex($items, KeyTier::Evidential, $poisoned) as $keyValue => $members) {
            for ($i = 0; $i < count($members); $i++) {
                for ($j = $i + 1; $j < count($members); $j++) {
                    $a = $members[$i];
                    $b = $members[$j];
                    if ($groups->find($a) !== $groups->find($b) && ! $this->isCut($cuts, $a, $b)) {
                        $candidates[] = new Candidate($a, $b, $keyValue);
                    }
                }
            }
        }
        ```

- [x] **#SCALE-11** · P2 — Identity candidates are inserted one row at a time
    - **Resolved 2026-08-26** — `recordCandidates()` collects rows and writes them with chunked multi-row `insertOrIgnore()` on the file's existing `writeChunk()` bound. Same guards, and semantics preserved exactly: rows are deduped WITHIN the batch on `(left_item_id, right_item_id)` **first-wins**, because the same pair can arise from two different key values and the per-row loop's second `insertOrIgnore` was silently swallowed by `idx_identity_candidates_pair` — a naive batch would have changed which `evidence` persists. `(left, right)` is NOT normalised; that index is directional and both orderings legitimately coexist. `content.identity_candidates` has no `updated_at`, and no consumer keys off a row timestamp (checked all four readers). Write statements for one shared key, before -> after: 50 members 1,225 -> 1; 200 members 19,900 -> 1; 1000 members 499,500 -> 1. Pinned in the **Postgres** lane (`composer test:pg`, mandatory here — a green SQLite run says nothing about this writer): batch count, first-wins evidence, the reversed `(b,a)` row, and a chunk-SPANNING case asserting 9 statements for 45 rows at chunk=5 with every pair present exactly once. Full lane 249 passed / 3 skipped, with 2 failures pre-existing (`LanderFoldAtomicityTest`, `ingest.record_state` first-creator-wins ordering) — verified pre-existing by restoring the test files from HEAD and re-running. `IdentityScope.php`, the kill switch, the closure bound, the advisory lock, the transaction boundary and the resolve scope are ALL untouched; `ProjectionWriterScopedResolveTest`'s scoped-vs-whole-kind differential still passes.
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:928-947 (`recordCandidates`)
    - **Affects:** Any projection run whose identity resolution surfaces evidential-tier candidates — directly amplified by #SCALE-10's uncapped candidate generation.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Collect candidate rows and issue chunked multi-row `insertOrIgnore()` calls using the same `writeChunk()` bound the rest of this file already uses.
    - **Technical:** `recordCandidates()` loops over `$candidates` and issues one `DB::table('content.identity_candidates')->insertOrIgnore([...])` per candidate — a straightforward N+1 write. Low-frequency in the common case (most groups don't produce candidates), but directly compounds with #SCALE-10 when a weak evidential key is widely shared.
    - **Plain English:** Each "maybe these are the same" note is filed away one at a time with its own trip to the cabinet, instead of a batch of notes being filed together.
    - **Evidence:**
        ```php
        foreach ($candidates as $candidate) {
            // ...
            DB::table('content.identity_candidates')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'left_item_id' => $left,
                'right_item_id' => $right,
                'score' => 50,
                'evidence' => json_encode(['key' => $candidate->evidence]),
                'created_at' => now(),
            ]);
        }
        ```

- [ ] **#SCALE-12** · P2 — Media-mirror dispatch enqueues one job per asset synchronously inside the projection loop
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:1470-1501 (`dispatchMirrors`)
    - **Affects:** First-ever projection of an image-heavy connector account (e.g. a new Instagram connect with hundreds of media items); `images`/media queue depth.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Batch-enqueue with `Bus::batch($jobs, allowFailures: true)` in chunks, or accept the current shape but cap the per-run dispatch count and spill the remainder to a delayed follow-up.
    - **Technical:** `dispatchMirrors()` loops over `$needing` (assets still missing storage) and calls `MirrorMediaAssetJob::dispatch()` once per asset inside the synchronous projection request/job. A first-ever sync of a large Instagram account can create hundreds to low-thousands of queued jobs in one invocation, each an immediate Redis write, landing on a queue `config/horizon.php` documents as already prone to head-of-line starvation against `analytics` on `supervisor-1` (maxProcesses 2).
    - **Plain English:** The moment a large photo gallery is first connected, the system fires off a separate delivery truck for every single photo, all at once, instead of grouping them into a few trucks.
    - **Evidence:**
        ```php
        foreach ($needing as $assetId) {
            // ::dispatch(), never Bus::dispatch(new ...) — the latter
            // silently drops ShouldBeUnique.
            MirrorMediaAssetJob::dispatch($userId, (string) $assetId, $slice[(string) $assetId]);
        }
        ```

- [x] **#SCALE-13** · P2 — Pool resolution reads every `site.section_items` row for a section with no cap on accumulated excluded items
    - **Resolved 2026-08-26** — narrowed all three `site.section_items` reads (`hasSelection`, `plan`, `preloadCuration`) to `['section_id','item_id','state','sort_key']`; `id` and `created_at` are read nowhere. Measured on a 2000-row section: 432,000 -> 274,000 bytes returned, same 2000 rows. NOT fixed and deliberately not attempted: nothing prunes accumulated `state='excluded'` rows — a retention rule is a write path and a separate decision, carried forward in RESULT-PART-3.md.
    - **Where:** app/Site/Pools/PoolResolver.php:114-116 (`hasSelection`), 193-195 (`resolve`)
    - **Affects:** Public pool payload and dashboard pool page for any section whose owner has excluded many items over time.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Narrow the select to `['item_id', 'state', 'sort_key']` instead of `->get()` on the whole row.
        - Consider a retention/pruning rule for long-accumulated `state = 'excluded'` rows, since nothing currently prunes them.
    - **Technical:** Both `resolve()` and `hasSelection()` run an unfiltered `DB::table('site.section_items')->where('section_id', $section->id)->get()` on every pool resolution — this runs on the public hot path (no document cache; the class docblock states pools are "always as live as is possible"). Pinned items are naturally bounded by what a user curates by hand, but excluded items accumulate indefinitely with no pruning path, so a section's curation-row count can grow without bound over a site's lifetime.
    - **Plain English:** Every time a professional's page is shown, the system re-reads every single thing that owner has ever hidden from that section — forever, with no cleanup. Over years of use, this list only grows, slowing down every page load a little more.
    - **Evidence:**
        ```php
        $curation = DB::connection('pgsql')->table('site.section_items')
            ->where('section_id', $section->id)
            ->get();
        ```

- [x] **#SCALE-14** · P2 — Pool item-payload build selects the full JSONB `platform_connections.payload` for every source row on the public hot path
    - **Resolved 2026-08-26** — the JSONB left the fan-out. `$sourceRows` returns one row per (item, source), so `payload` was re-materialised once per item; it is now fetched once per DISTINCT connection into `$payloadByConnection` (same idiom as the `$ingestByConnection` read beside it) and looked up by connection id. Same rows, same keys, same wire. Measured with 200 items on one connection carrying a ~230 KB payload: 46,065,400 -> 230,327 bytes of payload material, 1 -> 2 queries (queue driver `sync`). Mutation-checked: forcing `$payloadByConnection = []` turns PoolLaneTest's `accountName` and PoolSourceLivenessTest's fallback-url assertions red, so the coverage is not vacuous.
    - **Where:** app/Site/Pools/PoolResolver.php:528-550 (`itemPayloads`)
    - **Affects:** Public pool payload and dashboard item sheet for users with large connector payloads (Google Business place details, Instagram media metadata).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Select only the JSONB keys the payload actually needs (`payload->>'url'`, `payload->>'name'`) instead of the whole column, or split a dashboard-only wider read out from this public-path query.
    - **Technical:** `$sourceRows` selects `site.platform_connections.payload as payload` in full for every source item in the resolved set (up to `LIBRARY_LIMIT` 500 items plus the selection), then PHP `json_decode`s the whole thing in `$sourcesByItem`/`$sourcePlatforms` to extract a handful of scalar fields. Connector payloads can carry substantial embedded data (Google Business place details, menu/reservation/order snapshots), so this can pull several MB of JSON per public request for a user with a large connected footprint.
    - **Plain English:** To show a small badge and a link on the page, the code opens each connected account's entire file and carries the whole thing to the front desk — even though it only ever reads one or two lines from it. For accounts with large connected profiles, that's extra weight on every visit to a busy page.
    - **Evidence:**
        ```php
        ->get([
            'content.source_items.item_id',
            'content.source_items.last_seen_at',
            'content.sources.kind as source_kind',
            'site.platform_connections.id as connection_id',
            'site.platform_connections.platform as platform',
            'site.platform_connections.surface_key as surface_key',
            'site.platform_connections.payload as payload',
            'site.platform_connections.is_active as is_active',
        ])
        ```

- [x] **#SCALE-15** · P2 — Image fetches are always capped at the 80 MB video limit, so an oversized image is downloaded in full before the 15 MB image limit rejects it
    - **Resolved 2026-08-25** — closed by the #SCALE-3 streaming rewrite in the same commit. The 80 MB fetch ceiling stays (we cannot know the bytes are not a reel until they arrive), but it now lands on temp disk instead of the heap, and the 15 MB image cap is applied to the FILE before anything reads it into a string. Pinned by 'rejects an oversized image on its file size, before it is ever read into a string' — which asserts the reason is `body_rejected`, not `undecodable`, i.e. the size gate fires before the decoder.
    - **Where:** app/Services/Media/MediaMirror.php:32-35, 77, 107-109
    - **Affects:** Any projected image entry whose source is unusually large — inflates network egress and transient memory for rejected images by up to ~5x.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply `MAX_BYTES` (the smaller, image-appropriate cap) before download when the entry is known to be an image, or add a lightweight Content-Type/size preflight so the larger video cap is only used once video is actually indicated.
    - **Technical:** `withMaxBytes(self::MAX_VIDEO_BYTES)` (80 MB) is set unconditionally before the response body is inspected for type; the image-size check (`strlen($body) > self::MAX_BYTES`, 15 MB) only runs after the fetch has already completed. An oversized image can therefore be downloaded in full (up to 80 MB) before being rejected for exceeding a 15 MB limit.
    - **Plain English:** Before even opening a package, the system always rents a truck big enough for an 80 MB video — even when it's actually fetching a photo that's only supposed to be allowed up to 15 MB. It only checks the size after the whole thing has already arrived.
    - **Evidence:**
        ```php
        private const MAX_BYTES = 15728640;
        private const MAX_VIDEO_BYTES = 83886080;
        // ...
        $response = $this->fetcher->withMaxBytes(self::MAX_VIDEO_BYTES)->tryFetch($sourceUrl);
        // ...
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return $this->fail($assetId, 'body_rejected', $sourceUrl);
        }
        ```

- [x] **#SCALE-16** · P2 — Pending-card enrichment safety net loads its whole backlog with `->get()` and dispatches synchronously in a loop
    - **Resolved 2026-08-26** — `->get()` + `foreach` becomes `chunkById(100)`, dispatching inside the callback. `chunkById` keysets on the PK rather than paging by OFFSET, which matters here specifically: the dispatched job flips `last_refresh_status` out of this query's own WHERE, inline under the `sync` driver, and an OFFSET page window would shift under it. Proven, not assumed — a real inline run over 240 rows spanning three pages left 0 rows `pending` and reported `dispatched 240 of 240`. Measured at N=2000: heap delta 12,081,056 -> 4,163,168 bytes, 1 -> 21 queries, identical 1800 jobs dispatched.
    - **Where:** app/Console/Commands/EnrichPendingCardsCommand.php:26-45
    - **Affects:** `platforms:enrich-pending-cards` (scheduled daily, safety net for stuck enrichments) — normally near-empty, but grows to a real backlog after a queue outage.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Replace `->get()` with `->chunkById(100)` and dispatch within the chunk callback.
    - **Technical:** This "safety net" command (added by commit `e8e0c2d0f` alongside the sibling `content:refresh-item-caches` command below) loads every still-pending row into one `Collection` and loops, dispatching `EnrichLinkCardJob` per row. Under normal operation the `pending AND older-than` filter keeps this tiny, but after any queue-side outage the backlog is exactly this command's job to drain — at which point it materializes the whole backlog in memory and floods the queue in one tick. The team applied `chunkById()` for the structurally identical pattern in `BackfillSubdomainKvCommand` (see its own "SCALE-2" comment); this command was not updated to match.
    - **Plain English:** This daily safety check is supposed to catch cards that got stuck. Normally there are none, so it's cheap — but after any hiccup in the background workers, it could suddenly have to pick up a whole backlog at once, and it currently does that in one big armful instead of a few manageable trips.
    - **Evidence:**
        ```php
        $rows = IntegrationConnection::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('last_refresh_status', 'pending')
            ->whereNull('last_refreshed_at')
            ->where('created_at', '<', now()->subMinutes((int) $this->option('older-than')))
            ->get();

        $dispatched = 0;
        foreach ($rows as $row) {
            // ...
            EnrichLinkCardJob::dispatch((string) $row->user_id, $family, (string) $row->resource_id, $url, (string) $row->surface_key);
            $dispatched++;
        }
        ```

- [x] **#SCALE-17** · P2 — Item-cache repair safety net loads every stale item into memory and groups by user in PHP
    - **Resolved 2026-08-26** — restructured into two phases: phase 1 collects the DISTINCT `user_id`s matching the filter (a deduped uuid column, and write-free so OFFSET paging cannot skip); phase 2 runs `chunkById(500, ..., 'i.id', 'id')` SCOPED TO ONE USER at a time. The per-user scoping is load-bearing and was learned the hard way: keyset-paging the whole backlog by `i.id` (the obvious reading of the finding) scatters one user's items across pages because ids are random UUIDs, fragmenting the batches `refreshItemCaches()` charges ~18 queries apiece for — that first cut measured **+54% queries for no memory win**. Scoped per user: N=2000 2441 -> 2461 queries (+0.8%), heap 4,378,848 -> 3,354,216; N=20,000 20,881 -> 20,941 (+0.3%), heap 40,491,432 -> 30,541,928. Identical item-id set refreshed at both sizes. Safe to split a user's ids across calls because `refreshCachesFor()` -> `refreshItemCaches()` already `array_chunk`s internally and is purely per-item with no whole-user semantics (verified at `ProjectionWriter.php:2581-2719`); `ProjectionWriter` itself is untouched. Both commands had NO test invoking `handle()` — `tests/Feature/Console/RepairCommandBackfillTest.php` is new and pins the paging, the multi-user clone guard and `--dry-run` writing nothing; it fails if `chunkById` is downgraded to `chunk`.
    - **Where:** app/Console/Commands/RefreshItemCachesCommand.php:47-57
    - **Affects:** `content:refresh-item-caches` (scheduled daily 03:25, added by commit `e8e0c2d0f` for X4) — a healthy database is a cheap no-op read, but a systemic projection bug or a wide backfill can leave many items stale at once.
    - **Effort:** M (~2–4h)
    - **What to do:** Stream the stale-item query with `chunkById()`/`LazyCollection` and call `refreshCachesFor()` per chunk instead of materializing and grouping the full result set first.
    - **Technical:** `$query->get(['i.id', 'i.user_id'])->groupBy('user_id')` pulls every row matching the (default: stale-only) filter into one `Collection` before any processing starts. This mirrors the exact anti-pattern the sibling `EnrichPendingCardsCommand` (#SCALE-16) has, added in the same commit for a different repair purpose; both are "healthy path is cheap, unhealthy path is unbounded" safety nets that should stream rather than materialize.
    - **Plain English:** This nightly repair job is supposed to fix a handful of items whose cached title went stale. On a healthy day it's nearly free — but if something upstream goes wrong for a while, it could have to fix many items at once, and right now it tries to load and sort all of them into memory before doing any of the actual work.
    - **Evidence:**
        ```php
        $byUser = $query->get(['i.id', 'i.user_id'])->groupBy('user_id');
        $total = $byUser->sum(fn ($rows) => $rows->count());
        // ...
        foreach ($byUser as $userId => $rows) {
            $writer->refreshCachesFor((string) $userId, $rows->pluck('id')->map(fn ($id) => (string) $id)->all());
        }
        ```

- [ ] **#SCALE-18** · P2 — Pool-shape reshape command loads every matching section for a pool in one unbounded pass
    - **Where:** app/Console/Commands/ReshapePoolSectionsCommand.php:55-57
    - **Affects:** `content:reshape-pool-sections` — a manual, operator-run command invoked after a pool's canonical rule shape changes (per its docblock, this is now the standing tool for "every future shape change").
    - **Effort:** S (~0.5–1h)
    - **What to do:** Replace the unfiltered `->get(['id', 'site_id', 'rule', 'order_by'])` with `chunkById(500)` and process/reshape per chunk.
    - **Technical:** `$sections = DB::table('site.sections')->where('key', PoolRegistry::sectionKey($pool))->get(...)` loads every site's section row for the given pool at once. Manually invoked, not scheduled — but its own docblock states it is the standing tool for every future pool-shape migration, and at "thousands of users" scale each pool has one section per site, so this is exactly the kind of admin tool that will be run against a large table as the platform grows.
    - **Plain English:** This tool is meant to be run by an engineer whenever a content-section's internal shape changes. It currently grabs every affected professional's section row across the whole platform in one go before doing any work — fine today, but it will need to change before it's run against a much larger user base.
    - **Evidence:**
        ```php
        $sections = DB::connection('pgsql')->table('site.sections')
            ->where('key', PoolRegistry::sectionKey($pool))
            ->get(['id', 'site_id', 'rule', 'order_by']);
        ```

- [ ] **#SCALE-19** · P2 — Reshape command does a per-section site lookup and dispatches a Cloudflare purge inside the section loop
    - **Where:** app/Console/Commands/ReshapePoolSectionsCommand.php:81-99
    - **Affects:** `site.sites` write load and Cloudflare cache-purge rate limits during a manual pool-shape migration run.
    - **Technical:** Inside the same per-section loop as #SCALE-18, each reshaped section performs its own `DB::table('site.sites')->where('id', ...)->first(...)`, its own `site.sites` update, and its own `CloudflareCachePurgeJob::dispatch()` — one round-trip and one vendor purge call per section, rather than a preloaded site map and a batched/throttled purge. At the scale this tool is explicitly designed to eventually run at (a platform-wide shape migration across thousands of sites), this is both an N+1 query shape and an unthrottled burst against Cloudflare's purge API.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Preload site rows for all affected `site_id`s once (single `whereIn`), update in bulk, and either batch the Cloudflare purge calls or add pacing between dispatches.
    - **Plain English:** For every section it fixes, this tool separately walks over to look up which site owns it, then makes its own phone call to tell Cloudflare to refresh that site's cache — one lookup and one phone call per section, instead of one lookup for everyone and a handful of grouped calls.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->table('site.sections')
            ->where('id', $section->id)
            ->update([...]);
        $reshaped++;

        $site = DB::connection('pgsql')->table('site.sites')
            ->where('id', $section->site_id)->first(['id', 'subdomain']);
        if ($site !== null) {
            BuildState::bump((string) $site->id);
            DB::connection('pgsql')->table('site.sites')
                ->where('id', $site->id)->update(['updated_at' => now()]);
            if ((string) ($site->subdomain ?? '') !== '') {
                CloudflareCachePurgeJob::dispatch($site->subdomain);
            }
        }
        ```

- [ ] **#SCALE-20** · P2 — `platforms:enrich-pending-cards` scheduled entry uses a bare `withoutOverlapping()` with no `runInBackground`/`onFailure`
    - **Where:** routes/console.php:499-502
    - **Affects:** Reliability/observability of the daily card-enrichment safety net (see #SCALE-16, same command).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace bare `->withoutOverlapping()` (defaults to a 1440-minute/24h lock) with an explicit TTL sized to measured runtime.
        - Add `->runInBackground()` and `->onFailure($reportScheduledFailure('platforms:enrich-pending-cards'))`, matching the file's own documented scheduler conventions (routes/console.php:11-25).
    - **Technical:** This entry violates the file's own stated conventions header, which every other entry in the file follows: an explicit `withoutOverlapping(N)` TTL, `runInBackground()` for daily/cron-scale tasks, and `onFailure()` wired to the shared Nightwatch-reporting closure. A crashed run leaves a 24-hour lock and no alert fires — the next day's safety-net run is silently skipped.
    - **Plain English:** This daily safety check has a lock that, if the check crashes, stays locked for a full day with no alarm — meaning the next day's check quietly never runs, and nobody finds out.
    - **Evidence:**
        ```php
        Schedule::command('platforms:enrich-pending-cards --older-than=30')
            ->dailyAt('03:20')
            ->withoutOverlapping()
            ->onOneServer();
        ```

- [ ] **#SCALE-21** · P2 — `content:refresh-item-caches` scheduled entry uses a bare `withoutOverlapping()` with no `runInBackground`/`onFailure`
    - **Where:** routes/console.php:506-509
    - **Affects:** Reliability/observability of the daily item-cache repair safety net (see #SCALE-17, same command; added by commit `e8e0c2d0f`).
    - **Effort:** S (~0.5–1h)
    - **What to do:** Same fix as #SCALE-20 — explicit TTL, `->runInBackground()`, `->onFailure($reportScheduledFailure('content:refresh-item-caches'))`.
    - **Technical:** Identical gap to #SCALE-20, same root cause, on the sibling command added in the same commit. Both should be fixed together.
    - **Plain English:** Same problem as the entry above, on the sibling nightly repair job added at the same time — a crash leaves a day-long silent lock with no alert.
    - **Evidence:**
        ```php
        Schedule::command('content:refresh-item-caches')
            ->dailyAt('03:25')
            ->withoutOverlapping()
            ->onOneServer();
        ```

- [ ] **#SCALE-22** · P2 — New CHECK constraint on `content.item_media` added without `NOT VALID`
    - **Where:** supabase/migrations/20260819001100_item_media_role_video.sql:14-19
    - **Affects:** Media-item writes during whenever this migration is applied against a database with existing `content.item_media` rows.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Same two-step fix as #SCALE-6 — add the widened `role` CHECK as `NOT VALID`, validate separately, with `lock_timeout`/`statement_timeout` set around the DDL.
    - **Technical:** Same mechanism as #SCALE-6 (`ADD CONSTRAINT ... CHECK` without `NOT VALID` takes an `ACCESS EXCLUSIVE` lock for a full-table validation scan), on a table (`content.item_media`) that accumulates a row per gallery/video asset per item — smaller blast radius than the partitioned `link_observations` table, but the same fix applies and this migration is dated alongside it (2026-08-19, per its own header for owner ruling R7).
    - **Plain English:** Same issue as the routing-observations migration above, on a different table: the database re-checks every existing photo/video record before letting new "video" entries in, briefly blocking new media writes while it does.
    - **Evidence:**
        ```sql
        ALTER TABLE content.item_media
            DROP CONSTRAINT IF EXISTS item_media_role_check;

        ALTER TABLE content.item_media
            ADD CONSTRAINT item_media_role_check
            CHECK (role IN ('cover', 'gallery', 'poster', 'avatar', 'logo', 'video'));
        ```

## P3 — Nice to have

- [ ] **#SCALE-23** · P3 — Absence folding builds two in-memory copies of the candidate set before its bounded write transaction
    - **Where:** app/Ingest/Landing/Lander.php:429-444 (`foldAbsence`)
    - **Affects:** Absence/tombstone folding on large streams — already substantially mitigated by existing chunking on both sides.
    - **Effort:** M (~2–4h)
    - **What to do:** Low priority given existing mitigation; if revisited, stream dominated-row selection directly into the write-chunk loop rather than building `$dominatedAbsent` fully first.
    - **Technical:** `$candidates` (from `liveNotSeen()`) and `$dominatedAbsent` (the coverage-dominated subset) are both fully materialized in PHP before the write transaction opens — two arrays of lightweight row objects. Both the candidate read and the subsequent writes are already chunked in 500-row batches, and `$dominatedAbsent` is a filtered subset of `$candidates`, typically much smaller. Minor overhead, not a meaningful risk at current chunk sizes.
    - **Plain English:** Before writing anything down, the system makes a small extra list in its head of everything it's about to write — already done in reasonably-sized batches on both sides, so this is a minor tidy-up rather than a real risk.
    - **Evidence:**
        ```php
        $dominatedAbsent = [];
        foreach (array_chunk($candidates, 500) as $chunk) {
            // ...
            foreach ($chunk as $row) {
                $orderValue = $orderValues[$row->key] ?? null;
                if ($coverage->dominates($row->key, $orderValue)) {
                    $dominatedAbsent[] = $row;
                }
            }
        }
        ```

- [ ] **#SCALE-24** · P3 — Absence order-value lookup fetches the full JSONB document to read one field
    - **Where:** app/Ingest/Landing/Lander.php:598-619 (`orderValuesFor`)
    - **Affects:** Absence folding when a stream's coverage needs an order-field comparison (already batched per-chunk, not per-key).
    - **Effort:** S (~0.5–1h)
    - **What to do:** Use Postgres JSON extraction server-side (`->selectRaw('key, doc->>? as order_value', [$spec->orderField])`) instead of decoding the whole `doc` column in PHP, with a fallback for the SQLite test lane.
    - **Technical:** The batched N+1 fix here (documented as "SCALE-5" in the file's own comments) already turned this from one query per key into one query per 500-key chunk. What remains is that each of those chunk queries still selects the whole `doc` JSONB blob (which can carry long captions/review bodies) only to extract one scalar field in PHP.
    - **Plain English:** To read the date on the front of each envelope, the system still opens the whole envelope and unpacks the entire letter inside — even though the date is right there on the outside and the database could just hand back that one line.
    - **Evidence:**
        ```php
        $rows = DB::table('ingest.record_versions')
            ->where('stream_id', $streamId)
            ->whereIn('key', $keys)
            ->where('is_current', true)
            ->get(['key', 'doc']);

        $values = [];
        foreach ($rows as $row) {
            $decoded = is_string($row->doc) ? json_decode($row->doc, true) : $row->doc;
            $values[$row->key] = is_array($decoded) ? ($decoded[$spec->orderField] ?? null) : null;
        }
        ```

- [ ] **#SCALE-25** · P3 — Google Business ordering seed re-fetches the same user row per store group
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:561-564 (`seedOrdering`)
    - **Affects:** A Google Business enrichment whose ordering block lists multiple store groups — a small, bounded N per connect event, not a routine hot-path query.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Hoist `User::find($userId)` above the `foreach ($stores as $storeKey => $group)` loop and capture it in the closure, reusing it for both `LinkRouter::routeOrdering()` calls.
    - **Technical:** Inside the per-store loop (already under a lock, already eager-loading existing ordering rows to avoid a separate N+1 per the adjacent comment), each new store group re-runs `User::find($userId)` for the same user. Small N in practice (a handful of ordering providers per Google Business listing), one-time per connect/re-enrich event.
    - **Plain English:** If a business's Google listing has several delivery-store links, the system looks up that same business's account record separately for each one — like re-checking someone's ID for every item in one order instead of once at the start.
    - **Evidence:**
        ```php
        $user = User::find($userId);
        $routed = $user === null
            ? null
            : $this->linkRouter->routeOrdering($user, $repUrl);
        ```

- [ ] **#SCALE-26** · P3 — Reservation-platform existence check issues one query per platform
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:273-280 (`hasAnyReservation`)
    - **Affects:** Every Google Business enrichment carrying a reservation link — up to 4 sequential queries, held inside the reservations lock window.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Replace the loop with one `IntegrationConnection::where('user_id', $userId)->whereIn('platform', self::RESERVATION_PLATFORMS)->exists()`.
    - **Technical:** `hasAnyReservation()` calls `has()` (a separate `exists()` query) once per entry in `RESERVATION_PLATFORMS`, worst case 4 round-trips, while holding `withReservationsXorLock()`. A single `whereIn(...)->exists()` removes the multiplication.
    - **Plain English:** This checks four different reservation systems one at a time instead of asking the database about all four in a single question.
    - **Evidence:**
        ```php
        private function hasAnyReservation(string $userId): bool
        {
            foreach (self::RESERVATION_PLATFORMS as $platform) {
                if ($this->has($userId, $platform)) {
                    return true;
                }
            }

            return false;
        }
        ```

- [ ] **#SCALE-27** · P3 — Booking existence check loops platforms with per-platform queries
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:311 (`seedBooking`)
    - **Affects:** Every Google Business enrichment carrying a booking link — up to 3 sequential queries, held inside the booking lock window.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Replace `collect(self::BOOKING_PLATFORMS)->contains(fn ($p) => $this->has($userId, $p))` with one `whereIn('platform', self::BOOKING_PLATFORMS)->exists()`.
    - **Technical:** `Collection::contains()` short-circuits on first match but each `$this->has()` call is its own `exists()` query — worst case 3, inside `withBookingXorLock()`.
    - **Plain English:** Checking which of three booking systems is already connected currently means three separate database questions instead of one.
    - **Evidence:**
        ```php
        if (collect(self::BOOKING_PLATFORMS)->contains(fn ($p) => $this->has($userId, $p))) {
        ```

- [ ] **#SCALE-28** · P3 — Social-link seed path performs one existence query per platform
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:721 (`seedSocials`)
    - **Affects:** Every Google Business enrichment carrying social links — up to 5 sequential queries (Facebook, TikTok, X, LinkedIn, Instagram).
    - **Effort:** S (~0.5–1h)
    - **What to do:** Preload the user's connected platforms in one `whereIn(...)->pluck('platform')` and check membership in memory.
    - **Technical:** The social-seed loop calls `$this->has($userId, $platform)` once per available social URL, each its own `exists()` query — same pattern as #SCALE-26/#SCALE-27, same file, same lock-window class of concern.
    - **Plain English:** The system checks five different social platforms one at a time instead of looking them all up together.
    - **Evidence:**
        ```php
        if ($this->has($userId, $platform)) {
            $findings[] = $this->conflictFinding($platform, $platform, 'social', $label, $url, [
                'remove' => [$platform], 'write' => $write,
        ```

- [ ] **#SCALE-29** · P3 — Weekly Cloudflare KV backfill scheduler entry lacks `runInBackground()`
    - **Where:** routes/console.php:340-345
    - **Affects:** The every-minute keep-alive tick and other due scheduler tasks during the Sunday 04:00 KV resync window, if `--all` on a large user base takes long enough to matter.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add `->runInBackground()`, matching the file's own convention for cron-scale tasks that shouldn't block the per-minute scheduler tick.
    - **Technical:** `BackfillSubdomainKvCommand` itself already streams via `chunkById(500)` for `--all` (its own "SCALE-2" comment confirms this was fixed), so the unbounded-memory half of the original concern doesn't hold. What remains is that its scheduled entry has an explicit TTL and `onFailure()` but no `->runInBackground()`, unlike the file's other weekly/daily entries — a `--all --queue` run dispatching thousands of `SyncSubdomainToKvJob`s could run long enough to hold up the scheduler's own per-minute tick.
    - **Plain English:** This weekly maintenance task already streams through its user list in safe batches — that part is fine. It's just missing the one setting that lets it run in the background instead of potentially holding up the once-a-minute health check that keeps the app warm.
    - **Evidence:**
        ```php
        Schedule::command('partna:backfill-subdomain-kv', ['--all', '--queue'])
            ->weeklyOn(0, '04:00') // Sunday 04:00 UTC — off-peak for AU/NZ
            ->onOneServer()
            ->withoutOverlapping(120)
            ->description('Weekly resync of Cloudflare KV subdomain routing entries')
            ->onFailure($reportScheduledFailure('backfill-subdomain-kv'));
        ```

## Suggested Bundled Sessions

- **Bundle 1 — ProjectionWriter write-amplification:** #SCALE-4, #SCALE-5, #SCALE-7, #SCALE-9, #SCALE-11, #SCALE-12
    - **Why grouped:** All in `app/Ingest/Projection/ProjectionWriter.php`, all the same root pattern (per-item/per-record DB round-trips that the file's own history shows the team has repeatedly batched elsewhere) — one session can apply the established `writeChunk()` batching idiom across all six.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Lander.php absence-folding polish:** #SCALE-23, #SCALE-24
    - **Why grouped:** Same file, same method family (`foldAbsence`/`orderValuesFor`), both minor/already-mitigated.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Identity resolution candidate volume:** #SCALE-10, #SCALE-11
    - **Why grouped:** #SCALE-10 (Resolver.php) generates the candidates #SCALE-11 (ProjectionWriter.php) writes one-at-a-time; capping generation and batching the write are complementary fixes for the same failure mode. (Note: #SCALE-11 also appears in Bundle 1 — implementer's choice which session picks it up, not both.)
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — PoolResolver public-path read shaping:** #SCALE-1, #SCALE-13, #SCALE-14
    - **Why grouped:** Same subsystem (public pool resolution hot path — `SectionCandidates`/`PoolResolver`), same theme (unbounded/oversized reads on every page render).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — GoogleBusinessAutoSync per-platform existence checks:** #SCALE-25, #SCALE-26, #SCALE-27, #SCALE-28
    - **Why grouped:** Same file, same exact `has()`-in-a-loop pattern repeated four times; one pass converts all four to `whereIn(...)->exists()` or a single preloaded set.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 6 — Console-command chunking sweep:** #SCALE-16, #SCALE-17
    - **Why grouped:** Both added/touched together (commit `e8e0c2d0f`), both the identical "safety-net command loads its whole backlog with `->get()`" pattern, both fixable with the same `chunkById()` idiom already used in `BackfillSubdomainKvCommand`.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 7 — ReshapePoolSectionsCommand:** #SCALE-18, #SCALE-19
    - **Why grouped:** Same file, same command, same reshape loop — the unbounded read and the per-section N+1/Cloudflare-purge issue should be fixed together to avoid re-touching the loop twice.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 8 — Scheduler hygiene in routes/console.php:** #SCALE-20, #SCALE-21, #SCALE-29
    - **Why grouped:** Same file, same fix shape (align a scheduled entry with the file's own documented conventions header), no code outside routes/console.php touched.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 9 — MediaMirror fetch/size handling:** #SCALE-3, #SCALE-15
    - **Why grouped:** Same file (`MediaMirror.php`), same `mirror()` method — the streaming rewrite for #SCALE-3 is a natural place to also apply the type-aware byte cap for #SCALE-15.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet — escalate implement → Opus if the streaming rewrite touches `WebpEncoder`'s contract.

## Standalone — do NOT bundle

- **#SCALE-2 — SectionCandidates correlated COUNT/NOT EXISTS auto-selection query** · L effort, and the fix must preserve documented tie-breaking correctness (a prior live incident, F1) on the hottest public-page read path.
- **#SCALE-6 — `routing.link_observations` CHECK constraint without `NOT VALID`** · DB migration/schema change.
- **#SCALE-8 — ProjectionWriter whole-stream projection accumulation** · L effort; restructuring the write boundary needs its own plan and sign-off given identity-resolution correctness is load-bearing throughout this file.
- **#SCALE-22 — `content.item_media` CHECK constraint without `NOT VALID`** · DB migration/schema change.
