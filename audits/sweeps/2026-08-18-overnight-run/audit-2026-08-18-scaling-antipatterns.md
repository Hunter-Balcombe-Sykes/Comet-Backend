# Scaling Antipatterns Audit — 2026-08-18

**Branch:** audit-fix/instagram-wave-findings-2026-08-18
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude` (adjudicator tier)
**Source files audited:**
- app/Ingest/Projection/ProjectionWriter.php
- app/Content/Identity/Resolver.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Observers/Core/SiteObserver.php
- app/Services/Platforms/IntegrationConnectionCacheRefresher.php
- app/Site/Documents/SiteCacheLanes.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Ingest/Projection/IdentityKeyDeriver.php
- app/Ingest/Projection/ProjectorRegistry.php
- app/Ingest/Projection/AppleMusicReleaseProjector.php, AppleMusicTrackProjector.php, FreshaServiceProjector.php, InstagramMediaProjector.php
- app/Content/Identity/KeyClass.php
- app/Http/Resources/Routing/RoutingConnectionResource.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 3 of 4 complete
- P3 Low: 1 of 4 complete

---

## P2 — Should fix

- [x] **CACHE-1** · P2 — Category 2: `recordCandidates` inserts one row per identity candidate in an unbatched loop
    - **Resolved 2026-08-26** — `recordCandidates()` collects rows and writes them with chunked multi-row `insertOrIgnore()` on the file's existing `writeChunk()` bound. Same guards, and semantics preserved exactly: rows are deduped WITHIN the batch on `(left_item_id, right_item_id)` **first-wins**, because the same pair can arise from two different key values and the per-row loop's second `insertOrIgnore` was silently swallowed by `idx_identity_candidates_pair` — a naive batch would have changed which `evidence` persists. `(left, right)` is NOT normalised; that index is directional and both orderings legitimately coexist. `content.identity_candidates` has no `updated_at`, and no consumer keys off a row timestamp (checked all four readers). Write statements for one shared key, before -> after: 50 members 1,225 -> 1; 200 members 19,900 -> 1; 1000 members 499,500 -> 1. Pinned in the **Postgres** lane (`composer test:pg`, mandatory here — a green SQLite run says nothing about this writer): batch count, first-wins evidence, the reversed `(b,a)` row, and a chunk-SPANNING case asserting 9 statements for 45 rows at chunk=5 with every pair present exactly once. Full lane 249 passed / 3 skipped, with 2 failures pre-existing (`LanderFoldAtomicityTest`, `ingest.record_state` first-creator-wins ordering) — verified pre-existing by restoring the test files from HEAD and re-running. `IdentityScope.php`, the kill switch, the closure bound, the advisory lock, the transaction boundary and the resolve scope are ALL untouched; `ProjectionWriterScopedResolveTest`'s scoped-vs-whole-kind differential still passes.
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:930-946 (`recordCandidates`)
    - **Affects:** Any projection run (connector sync or manual write) where several of a user's items share a loose evidential key (title, author, etc.) — DB round-trips scale with the number of candidate pairs, not the size of the record just written.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Accumulate candidate rows into an array and issue chunked multi-row `insertOrIgnore` calls (mirror the `array_chunk(..., $this->writeChunk())` pattern `replaceCollections` already uses).
        - No behavioural change needed beyond batching — the dedup/ignore semantics stay identical.
    - **Technical:** `recordCandidates` loops over every `Candidate` the pure `Resolver` returned and issues one `DB::table('content.identity_candidates')->insertOrIgnore()` per row. Candidate count comes from `Resolver::resolve()`'s evidential-tier pass, which is itself `O(m²)` in the number of items sharing a loose key (see CACHE-6 below) — so a single write (manual add or connector run) that happens to touch a crowded evidential key can synchronously issue dozens to low-hundreds of individual INSERT statements. This runs on the same call path as `resolveItems()`, which today fires on every `writeManualItem()` call (CACHE-2) and every non-empty `projectStream()` run (CACHE-4), so its frequency compounds with those findings. Canonical replacement: chunked multi-row inserts, exactly as `replaceCollections` already does for `item_media`/`offers`/`item_tags`.
    - **Plain English:** When the system notices several of your items might be duplicates of each other, it writes a separate "maybe these two are the same" note to the database for every single pair — one at a time. If ten items share a similar name, that's 45 separate database writes instead of one bulk write. Batching them together is a small, low-risk fix.
    - **Evidence:**
        ```php
        foreach ($candidates as $candidate) {
            $left = $itemByCoord[$candidate->left] ?? null;
            $right = $itemByCoord[$candidate->right] ?? null;
            if ($left === null || $right === null || $left === $right) {
                continue;
            }

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

- [x] **CACHE-2** · P2 — Category 1: `writeManualItem` recomputes the whole user's identity graph on every single owner write
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:410 (`writeManualItem` → `resolveItems`)
    - **Affects:** Owner-authored manual content writes (hand-adds, backfillers, `MenuScanApplier`, `ShopContentWriter::syncProducts`) — latency and DB read volume scale with the user's total live item count for the kind, not with the single row being written.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Batch manual writes at the call-site boundary and invoke `resolveItems` once per batch instead of once per row, mirroring the pattern already applied to `bumpSite()`'s cache lanes (see the `bumpSite()` docblock cited below).
        - Where a true single-row API is required, consider an incremental resolver path that only evaluates the new coord's keys against existing identity keys rather than re-running the full union-find over every live source item for the kind.
    - **Technical:** `writeManualItem` unconditionally calls `$this->resolveItems($userId, $kind)` after every transaction, and `resolveItems` re-reads every live `source_item`, every `identity_key`, and every `identity_decision` for that user+kind before re-running the pure resolver. The class's own docblock on `bumpSite()` already documents that batch callers (`MenuScanApplier`, `ShopContentWriter::syncProducts`, the backfillers) call `writeManualItem()` "once PER ROW" — that reasoning was used to justify keeping cache-invalidation lanes 2+3 out of `writeManualItem` and pushed to the caller, but the identical per-row cost in `resolveItems()` was not addressed by that fix. A backfill of N owner items therefore invokes the O(N)-scoped resolver N times — O(N²) work for that backfill. Canonical replacement: batch at the request/backfill boundary and resolve once per batch.
    - **Plain English:** Imagine re-reading your entire filing cabinet every time you add one new folder to it. Adding 500 folders one at a time means reading through the whole (growing) cabinet 500 times. The code already fixed this for a couple of related side-effects but the core "re-check everything" step still runs on every single add.
    - **Evidence:**
        ```php
        $itemByCoord = $this->resolveItems($userId, $kind);
        ```
        ```php
         * per-item primitive — its batch callers (MenuScanApplier,
         * ShopContentWriter::syncProducts, the backfillers) call writeManualItem()
         * once PER ROW, so firing lanes 2+3 here would issue N sites.updated_at
         * writes and N edge purges for one request where one of each is correct.
        ```

- [ ] **CACHE-3** · P2 — Category 2: singleton facet writes are one UPSERT per facet per record, unlike the batched collection-facet path beside them
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:976-992, 1016-1020 (`writeFacets` → `upsertSingletonFacet`)
    - **Affects:** Any projection run with several records carrying typed facets (menu items, shop products, reviews, etc.) — write volume is O(records × facets) individual UPSERT statements per run.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Accumulate singleton facet rows per target table across the whole `$byItem` batch (the batching boundary already exists one level up) and issue one chunked multi-row `upsert` per facet table, same shape as `replaceCollections`.
        - Keep the per-column URL-minimisation and column-allowlist filtering logic intact — only the write shape changes.
    - **Technical:** `writeFacets` already batches at the item level (`replaceCollections($contentSourceId, $userId, $byItem)` runs once for the whole run's items — its own docblock cites this as the fix for a prior finding, "SCALE-17/#CACHE-2"). But immediately after that call, the nested `foreach ($byItem …) foreach ($group …) foreach ($facets …)` loop still calls `upsertSingletonFacet()` once per (item, record, facet), and that method issues its own single-row `DB::table("content.{$facet}")->upsert()` per call. A connector run syncing 100 items with 3 facets each issues 300 individual UPSERTs where the sibling collection-facet path (media/offers/tags) issues three chunked multi-row inserts total. This is the same shape of problem the team already fixed for collections, left unfixed for singletons.
    - **Plain English:** When a sync imports 100 products and each one has 3 pieces of info to save (a name, a link, a price), the code currently saves each piece separately — 300 tiny database writes. The code already does this efficiently for photos and tags on the same page; extending the same batching to these other fields is the fix.
    - **Evidence:**
        ```php
        foreach ($byItem as $itemId => $group) {
            foreach ($group as $projection) {
                $facets = (array) ($projection['facets'] ?? []);
                ...
                foreach ($facets as $facet => $columns) {
                    $this->upsertSingletonFacet($itemId, $contentSourceId, (string) $facet, (array) $columns);
                }
            }
        }
        ```
        ```php
        DB::table("content.{$facet}")->upsert(
            [$row + ['item_id' => $itemId, 'source_id' => $contentSourceId, 'updated_at' => now()]],
            ['item_id', 'source_id'],
            array_merge(array_keys($row), ['updated_at']),
        );
        ```

- [x] **CACHE-4** · P2 — Category 1: a connector run touching one record rebuilds identity + caches for the user's entire kind
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:241-246 (`projectStream`)
    - **Affects:** Every scheduled connector projection (YouTube, Instagram, Google Business, Fresha, Eventbrite, Gumroad, menu platforms) — cost per run scales with the user's total live item count for the kind, not with the number of records the run actually changed.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Scope `resolveItems` to the coords touched by this run plus anything sharing a key with them, falling back to a full resolve only when a merge could plausibly span beyond that set.
        - Scope `refreshItemCaches` to the items actually resolved as changed this run rather than every item `resolveItems` returns for the kind.
        - This is the highest-leverage fix in the cluster (CACHE-1/2/3 all sit downstream of the same `resolveItems` call) — an incremental resolver here reduces cost for the manual-write path too.
    - **Technical:** At the end of `projectStream`, `if ($projections !== [])` gates a call to `resolveItems($userId, $projector::kind())`, which (per its own implementation, verified above) loads every live source item, every identity key, and every decision for that user+kind and re-runs the full union-find — not scoped to the records this run touched. The result, `$itemByCoord`, is then handed to `refreshItemCaches($userId, array_values(array_unique(array_values($itemByCoord))))`, which likewise refreshes caches for every item the resolver returned — i.e. the whole kind, not the delta. A scheduled run that lands one changed record (e.g. one YouTube video updated) pays for a full-kind identity recompute and cache pass. This is the canonical rebuild-on-write shape: cost scales with total data size, not event payload size.
    - **Plain English:** Whenever the system notices even one new thing has changed on a connected account, it re-checks and re-labels every single thing that account has ever synced, not just the one that changed. For someone with a large connected catalogue, a routine one-item update pays the cost of a full recheck of everything.
    - **Evidence:**
        ```php
        $itemByCoord = [];
        if ($projections !== []) {
            $itemByCoord = $this->resolveItems($userId, $projector::kind());
            $this->writeFacets($contentSourceId, $userId, $projections, $itemByCoord);
            $this->refreshItemCaches($userId, array_values(array_unique(array_values($itemByCoord))));
        }
        ```

## P3 — Nice to have

- [ ] **CACHE-5** · P3 — Category 2: `bindGroup` issues one anchor INSERT per coord and one merge call per loser
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:721-731, 741-743 (`bindGroup`)
    - **Affects:** Identity groups spanning several connections/platforms for one item — write volume scales with group size, which in practice is small (a handful of platforms per item).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collect missing anchors and issue one chunked multi-row `insert`.
        - Extend `mergeInto` to accept a list of loser ids so the anchor-redirect and source_items repoint use `whereIn` across all losers in one statement instead of one call per loser.
    - **Technical:** `bindGroup` loops over every coord in a resolved group and issues a separate single-row insert for each missing `content.item_anchors` row, then loops over "losers" (non-winning item ids in the group) and calls `mergeInto()` once per loser, each of which runs several individual UPDATEs. Group size is bounded by how many platforms/sources can plausibly describe one real-world item (typically low single digits), so this is a low-volume tail rather than a scale risk — worth doing opportunistically, not urgently.
    - **Plain English:** When the same item is found on several different platforms, the system writes one confirmation note per platform and merges old duplicate records one at a time, instead of doing it all in a single batch. Groups are small in practice, so this isn't costing much today, but it's an easy tidy-up.
    - **Evidence:**
        ```php
        foreach ($group as $coord) {
            $existing = $anchors->firstWhere('coord', $coord);
            if ($existing === null) {
                DB::table('content.item_anchors')->insert([
                    'coord' => $coord,
                    'user_id' => $userId,
                    'item_id' => $winner,
                    'bound_at' => now(),
                ]);
            }
        }
        ```

- [x] **CACHE-6** · P3 — Category 5: `Resolver`'s evidential-tier pass is an O(m²) nested loop over every member of a shared loose key
    - **Resolved 2026-08-26** — capped, and the cap is SURFACED. `Resolver::resolve()` step 5 now takes `maxMembersPerKey` / `maxCandidatesPerKey` as ARGUMENTS (defaults 100 / 200, mirroring `config('partna.ingest.*')`): the member cap bounds the ITERATION, the candidate cap bounds what is appended and breaks out of that key's loops immediately. Two knobs deliberately — the candidate cap alone does not bound the work, because if nearly every pair is already grouped or cut the loops can run the full O(m^2) without ever appending enough to trip it. **The caps arrive as DATA, never as a config read inside the resolver** — the same rule `LinkProjector` follows for detector suspensions, and what keeps a resolve reproducible from its arguments alone; `ProjectionWriter::resolveItemsLocked()` owns the config read and the logging, because user + kind live at that seam. ONE `Log::warning` per RUN with a bounded 5-key sample, never one per key or per pair: a silent cap means items quietly stop being offered for merge, which is invisible on a green run. Growth curve for one shared key, before -> after: 50 members 1,225 -> 200 pairs; 200 members 19,900 -> 200; 1000 members 499,500 -> 200 (122.5ms -> 2.3ms). Deterministic first-N, not a sample, pinned by a two-run identity test; below the caps the candidates, their order and their evidence are unchanged.
    - **Where:** app/Content/Identity/Resolver.php:77-87 (`resolve`)
    - **Affects:** Projection CPU time for a user whose items happen to share a loose title/author key — cost is quadratic in the size of that key's member set.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Cap the number of pairwise candidates generated per evidential key, or bucket members before pairing (e.g. by a cheap prefix/hash) to bound comparisons per key.
        - Keep the resolver pure — this is an algorithmic change, not a persistence one.
    - **Technical:** The evidential-tier candidate pass runs a classic double loop over every member sharing a key value, generating a `Candidate` for every unmerged pair. This is pure in-memory CPU with no I/O, and per-user item cardinality on this platform is small (individual professional catalogues, not marketplace-scale inventories), so the "5,000 items sharing a title" scenario DeepSeek's draft cited is not representative of Partna's actual per-user scale. Still a real algorithmic smell worth bounding before shop/menu integrations grow larger per-user catalogues.
    - **Plain English:** If a bunch of items share a similar name, the system compares every one of them against every other one to spot possible duplicates. At today's typical catalogue sizes this is fast, but the comparison count grows much faster than the number of items, so it's worth capping now before it becomes a problem.
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

- [ ] **CACHE-7** · P3 — Category 2: `refreshItemCaches` still writes and mints slugs one item at a time for the changed tail
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:1653-1658, 1671-1678 (`refreshItemCaches`)
    - **Affects:** Projection runs where many items' cached headline/facets actually changed in one pass — the read side is already batched (SCALE-8), but the write-back and slug mint remain per item.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Batch the changed-row updates into a single multi-row upsert (e.g. `CASE`-expression update or a values-list upsert) instead of one `UPDATE ... WHERE id = ?` per changed item.
        - Consider deferring `ContentItemSlugAllocator::ensureCurrent` calls to a queued follow-up when a batch has many slug-eligible changes.
    - **Technical:** The method's own docblock (SCALE-8) documents that the *read* side was already rewritten from 20 queries-per-item to batched per-chunk queries. What remains unbatched is the write-back: each item whose resolved headline/facets actually changed gets its own single-row `UPDATE content.items`, and each slug-eligible item calls `ensureCurrent()` individually. Bounded to `BATCH_SIZE` (500) per chunk and gated to only genuinely-changed rows, so this is a minor tail rather than the primary cost — reasonable to fold into a future pass on this file rather than a standalone effort.
    - **Plain English:** After the expensive part of a sync was already fixed to check things in bulk, the final "save what changed" step still saves one item at a time. It's a much smaller version of the same pattern, worth cleaning up when this file is next touched.
    - **Evidence:**
        ```php
        if ($changed) {
            DB::table('content.items')->where('id', $itemId)->update([
                'headline_cache' => $headline,
                'facets_cache' => json_encode($present),
            ]);
        }
        ```
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

- [ ] **CACHE-8** · P3 — Category 5: `IntegrationConnectionObserver` dispatches several independent side effects per connection write instead of one chained job
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:61-144 (`saved`), 204-224 (`deleted`), 402-419 (`restored`)
    - **Affects:** Bulk connection lifecycle events (a mass platform-policy disconnect, a GDPR-driven purge, a scheduled multi-platform refresh wave) — queue traffic per affected connection carries a small constant-factor multiplier (roughly 2–4x) rather than one dispatch per meaningful change.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Consolidate the conditional side effects (`maybeFetchMenu`, `refresher->refresh`, the completeness-gated `site->touch()`, `syncIngestSource`) behind a single post-commit coordinator per connection write, using `Bus::chain` where ordering matters.
        - Preserve every existing gating condition (`wasRecentlyCreated`/`wasChanged(...)` checks) exactly — they are what already bounds this to *meaningful* writes, not every save.
        - Do **not** remove the `site->touch()` call for completeness-gated platforms — it is required to reach `SiteCacheService::raiseResolveFloor()` via `SiteObserver`, which is the only thing that defeats the short-TTL `handle.resolve` cache (documented in this file's own comments); a prior draft of this finding proposed removing it, which would reopen the exact staleness bug this code was written to fix.
    - **Technical:** `saved()`, `deleted()`, and `restored()` each independently invoke `maybeFetchMenu()` (conditionally dispatches `MenuFetchJob`, `ShouldBeUnique`), `$this->refresher->refresh()` (dispatches `CloudflareCachePurgeJob`, also `ShouldBeUnique`), a completeness-gated `$connection->user?->site?->touch()` (fans out through `SiteObserver::saved()` into a direct `SiteCacheService::invalidateSite()` call, another `CloudflareCachePurgeJob` dispatch, and a conditional `WarmPublicSiteCacheJob` dispatch), and `syncIngestSource()` (a direct write plus an occasional `RunSourceJob` dispatch on new/reselected sources). Most of the individual jobs already carry `ShouldBeUnique` protection and most call sites are gated to genuinely meaningful changes (`wasRecentlyCreated`/`wasChanged(...)`), which meaningfully bounds the blast radius DeepSeek's draft described — this is not the "avalanche" the original draft suggested. What remains is a real, if modest, per-write multiplier: a bulk lifecycle event (e.g. many users disconnecting the same platform after a policy change, or a GDPR-driven batch purge) produces roughly 2–4x the queue dispatches and one extra synchronous cache-invalidation call per affected connection compared to a single coordinated job. Worth consolidating for hygiene and queue headroom, not urgent for pilot.
    - **Plain English:** Saving or deleting one connected account currently rings 2 to 4 separate background alarms instead of one coordinated one. Each alarm is individually well-behaved (duplicates get automatically merged), so this isn't dangerous today, but if a large batch of accounts is ever disconnected at once — say, after a platform policy change — the background queue does more work than it strictly needs to. Combining these into one coordinated job would be tidier and cheaper at scale.
    - **Evidence:**
        ```php
        public function deleted(IntegrationConnection $connection): void
        {
            $this->refresher->refresh($connection);

            if (app(PlatformRegistry::class)->get($connection->platform)?->hasCompletenessPredicate()) {
                $connection->user?->site?->touch();
            }

            $this->cleanupMirroredMedia($connection);
            $this->syncIngestSource($connection);
        }
        ```
        ```php
        public function restored(IntegrationConnection $connection): void
        {
            $this->maybeFetchMenu($connection);
            $this->refresher->refresh($connection);

            if (app(PlatformRegistry::class)->get($connection->platform)?->hasCompletenessPredicate()) {
                $connection->user?->site?->touch();
            }

            $this->syncIngestSource($connection);
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Identity resolution over-invalidation:** #CACHE-1, #CACHE-2, #CACHE-3, #CACHE-4
    - **Why grouped:** All four sit in `app/Ingest/Projection/ProjectionWriter.php` and share one root cause — `resolveItems()`/`writeFacets()` recompute across the user's whole kind on every write instead of the delta. #CACHE-4 (scoping `resolveItems`) is the highest-leverage fix and should land first; #CACHE-2 and #CACHE-1 benefit directly from the same incremental-resolver work.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet). Escalate implement → Opus for #CACHE-4 — the incremental-resolver scoping is genuinely gnarly (must not silently break cross-stream identity merges, see the file's own SCALE-7 comment on this exact hazard).

- **Bundle 2 — ProjectionWriter/Resolver micro-batching cleanup:** #CACHE-5, #CACHE-6, #CACHE-7
    - **Why grouped:** Same file/class family, same shape (per-row writes or per-pair CPU work that should batch), lower urgency than Bundle 1 — reasonable to fold into one pass whenever this file is next opened for Bundle 1's work.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

- **Bundle 3 — Connection observer job-dispatch consolidation:** #CACHE-8
    - **Why grouped:** Single finding, isolated to `IntegrationConnectionObserver.php` — no other findings share this file.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

None.
