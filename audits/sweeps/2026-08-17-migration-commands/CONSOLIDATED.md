# Pre-Merge Bundle Audit — 2026-08-17

**Branch:** review/programme-convergence
**Lens:** pre-merge bundle: migration safety, API contract, configuration hygiene, test coverage, test-prod parity over the convergence migration commands and backfillers
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Services/Migration/BorrowedAssetPruner.php
- app/Services/Migration/CustomLinkBackfiller.php
- app/Services/Migration/LinkOnlyPayloadNormalizer.php
- app/Services/Migration/MediaUploadBackfiller.php
- app/Services/Migration/StandaloneEventBackfiller.php
- app/Console/Commands/BackfillContentItemSlugs.php
- app/Console/Commands/BackfillCustomLinks.php
- app/Console/Commands/BackfillLinkPayloads.php
- app/Console/Commands/BackfillMediaPaletteCommand.php
- app/Console/Commands/BackfillPreviousWebsiteContentScanCommand.php
- app/Console/Commands/BackfillSocialLinksCommand.php
- app/Console/Commands/BackfillStandaloneEvents.php
- app/Console/Commands/BackfillSubdomainKvCommand.php
- app/Console/Commands/BackfillUploadMediaCommand.php
- app/Console/Commands/BackfillUserKvEntries.php
- app/Console/Commands/CatalogCompileCommand.php
- app/Console/Commands/CatalogSyncCommand.php
- app/Console/Commands/CheckPlatformRefreshBacklogCommand.php
- app/Console/Commands/CheckStuckSourceIntentsCommand.php
- app/Console/Commands/CleanupOrphanedLifestyleConnections.php
- app/Console/Commands/CleanupStuckMediaProcessingCommand.php
- app/Console/Commands/ComputeContentPopularityScores.php
- app/Console/Commands/ContentRepairEventItemsCommand.php
- app/Console/Commands/ContentRetireChannelKindCommand.php
- app/Console/Commands/ConvergeSiteSubdomainsCommand.php
- app/Console/Commands/EnforcePlatformLinkCapCommand.php
- app/Console/Commands/GcOrphanedPlatformMediaCommand.php
- app/Console/Commands/GcOrphanedVideoArtifactsCommand.php
- app/Console/Commands/IngestAnomaliesCommand.php
- app/Console/Commands/IngestBackfillSourcesCommand.php
- app/Console/Commands/IngestDispatchCommand.php
- app/Console/Commands/IngestEffectsCommand.php
- app/Console/Commands/IngestProjectCommand.php
- app/Console/Commands/IngestStrandedCommand.php
- app/Console/Commands/MigrateHiddenEventsToPoolExcludes.php
- app/Console/Commands/Moderation/*.php
- app/Console/Commands/NotifyHandleAliasExpiry.php
- app/Console/Commands/NotifyUnansweredEnquiries.php
- app/Console/Commands/NotifyWeeklySummary.php
- app/Console/Commands/ProvisionShopPinsCommand.php
- app/Console/Commands/Prune*.php (all prune/purge commands)
- app/Console/Commands/ReconcileEnquiryNotifications.php
- app/Console/Commands/ReconcileIncompleteBookingCommand.php
- app/Console/Commands/ReconcileStuckPreAccountBuilds.php
- app/Console/Commands/ReconcileTrackedSessions.php
- app/Console/Commands/RefreshIntegrationConnectionsCommand.php
- app/Console/Commands/ReshapeMediaSectionsCommand.php
- app/Console/Commands/RetryUnavailableMenusCommand.php
- app/Console/Commands/RoutingCorpusCommand.php
- app/Console/Commands/RoutingReprojectCommand.php
- app/Console/Commands/ShowcaseSeedCommand.php
- app/Console/Commands/SiteBuildDocumentsCommand.php
- app/Console/Commands/SweepPurgedVideoArtifactsCommand.php
- app/Console/Commands/SweepStaleExportsCommand.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 4 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#TEST-1** · P1 — `ProvisionShopPinsCommand`'s three-lane invalidation is unguarded by CI
    - **Where:** app/Console/Commands/ProvisionShopPinsCommand.php
    - **Affects:** Shop-page owners after pin backfill; if any invalidation lane is dropped in a refactor, pinned products may not appear on the live/edge sitepage.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a feature test for the non-dry path asserting `BuildState::bump`, `site.sites.updated_at` update, and `CloudflareCachePurgeJob` dispatch occur exactly once per touched site.
        - Add a dry-run test asserting none of those side-effects fire.
    - **Technical:** This command raw-inserts `site.section_items` pins and manually invalidates Redis payload cache, model cache, and Cloudflare edge cache via `invalidate()`. Its own docblock states "No CI check enforces this," which is current, verbatim source — not a stale comment. A structural sweep mirroring the house `JobHygienePolicyTest`/`PolicyCoverageTest` pattern would catch a dropped lane before it reaches a merge.
    - **Plain English:** Imagine re-ordering shelves but forgetting to tell the website and the CDN to refresh. The command's own notes admit no automated check watches for that mistake — so a future edit could quietly break it and nothing would catch it before it ships.
    - **Evidence:**
        ```php
        // Raw-write seam: all three lanes by hand (spec §4). bump() alone is not
        // enough — the payload cache key composes from sites.updated_at, and the
        // CDN outlives the origin write. No CI check enforces this.
        ```

- [ ] **#TEST-2** · P1 — `ContentRepairEventItemsCommand` retirement test proves only `removed_at`, not cache/edge invalidation
    - **Where:** app/Console/Commands/ContentRepairEventItemsCommand.php
    - **Affects:** Repair operators and users whose published sitepages may keep serving retired event items after a `--retire` run; a green test gives false confidence that invalidation happened.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add assertions that a non-dry `--retire` run bumps `BuildState`, touches `site.sites.updated_at`, and dispatches `CloudflareCachePurgeJob` for each affected site.
        - Add a case that fails when any of the three invalidation side-effects is missing.
    - **Technical:** The command raw-writes `content.items.removed_at` and must then invalidate Redis/edge caches by hand because no Eloquent observer fires. The in-code comment records that the specific SQLite masking bug behind the original incident (a double-quoted `"deleted_at"` filter silently compiling to a string literal) has since been removed from the query — but the underlying gap this finding is about is untouched: the retirement `UPDATE` still commits before the site/invalidation work runs, and the comment states plainly that the existing test "asserted `removed_at` only, so it passed while proving nothing about the invalidation." See #MIG-2 for the companion ordering fix this test should exercise.
    - **Plain English:** This is like checking only the "deleted" flag on an event and never confirming the public website or CDN was told to refresh. The test says the job passed while the site could still show the retired event.
    - **Evidence:**
        ```php
        // The test asserted removed_at
        // only, so it passed while proving nothing about the invalidation.
        ```

- [ ] **#MIG-1** · P1 — `BackfillPreviousWebsiteContentScanCommand`'s dispatch stagger resets every 200-row chunk instead of spreading across the whole run
    - **Where:** app/Console/Commands/BackfillPreviousWebsiteContentScanCommand.php
    - **Affects:** Every existing Workplace with a `previous_website`; the scraping queue and the billed Mistral OCR / MenuAiExtractor spend on a real fleet-wide run.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the per-chunk `$i` with a cumulative position across the whole run (e.g. `$chunkIndex * self::DISPATCH_SPREAD_SECONDS + floor($i / self::BATCH_SIZE * self::DISPATCH_SPREAD_SECONDS)`), so the spread scales with total population rather than repeating every 200 rows.
        - Verify the corrected delay curve in `--dry-run` output before the next real run.
    - **Technical:** `$i` is re-indexed to `0..199` for every 200-row `$batch->values()` chunk (`chunk()` preserves original keys, but `values()` resets them), so `floor($i / 200 * 300)` does correctly ramp from 0 to ~298s — but it ramps identically for *every* chunk. Since `->delay()` only sets `available_at` (no actual sleep between chunks), a fleet of N workplaces produces `ceil(N/200)` overlapping copies of the same 0–298s ramp rather than one smooth 300s spread across the whole population. For a large install base this collapses the intended throttle into simultaneous bursts of size `N/200` at each point along the ramp, hitting the scraping queue and the billed OCR/AI-extraction path in waves instead of a trickle — precisely the failure the command's own docblock says the spread exists to prevent.
    - **Plain English:** The command promises to mail the re-scan requests over five minutes, but the postmark machine resets its page counter every 200 envelopes and restarts the same five-minute stamping pattern each time. Run it on a big enough mailing list and multiple pages hit "send now" together instead of trickling out — hitting the paid scanning service in bursts rather than a steady drip.
    - **Evidence:**
        ```php
        foreach ($batch->values() as $i => $workplace) {
            $site = $workplace->site;
            if ($site === null) {
                continue;
            }
            $delaySeconds = (int) floor($i / self::BATCH_SIZE * self::DISPATCH_SPREAD_SECONDS);
            ScanPreviousWebsiteContentJob::dispatch(
                (string) $site->user_id,
                (string) $workplace->site_id,
                trim((string) $workplace->previous_website),
            )->delay(now()->addSeconds($delaySeconds));
            $dispatched++;
        }
        ```

- [ ] **#MIG-2** · P1 — `ContentRepairEventItemsCommand` retires items before resolving invalidation targets; any failure between the two leaves a half-applied destructive update
    - **Where:** app/Console/Commands/ContentRepairEventItemsCommand.php
    - **Affects:** `content.items` retired via `--retire` and the public sitepage caches for affected sites.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Resolve the affected `site.sites` rows and subdomains **before** the `content.items` retirement update, as `PurgeReviewHeadlinePiiCommand` already does for the analogous review-PII purge.
        - Wrap the reachable DB mutations in a transaction and dispatch `CloudflareCachePurgeJob`/cache busts after commit, or at minimum `report()` loudly if the invalidation half cannot complete.
    - **Technical:** The specific trigger behind the incident this command's comment documents — a `whereNull('deleted_at')` filter against `site.sites`, a column that table has never had — is already removed from the current query, so that exact 42703 failure can no longer recur. The structural risk it exposed remains, though: `UPDATE content.items SET removed_at ...` still commits immediately, and only afterward does the code resolve `site.sites` and perform the three manual invalidations (`BuildState::bump`, `updated_at` touch, `CloudflareCachePurgeJob::dispatch`). Any other fault in that window — a transient DB disconnect, a Cloudflare dispatch failure, a killed worker — reproduces the same half-applied shape: retired in the DB, still served from cache/edge. Precomputing the affected sites first and moving invalidation after a committed transaction (with loud `report()` on partial failure) closes the whole class of failure, not just the one instance already patched.
    - **Plain English:** This is like moving a batch of files to the archive, then going to update the index cards. The specific card-jam that broke it before is fixed, but the process is still "move first, update the index second" — so a different interruption at the same point would leave the same result: the file room says the files are gone, but the front desk still points customers at the old shelf.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->table('content.items')
            ->whereIn('id', $orphaned->pluck('id')->all())
            ->update(['removed_at' => now(), 'updated_at' => now()]);

        $sites = DB::connection('pgsql')->table('site.sites')
            ->whereIn('user_id', $orphaned->pluck('user_id')->unique()->all())
            ->get(['id', 'subdomain']);
        ```

## P2 — Should fix

- [ ] **#MIG-3** · P2 — `BackfillContentItemSlugs` uses `cursor()` on pgsql and an N+1 existence check per item
    - **Where:** app/Console/Commands/BackfillContentItemSlugs.php
    - **Affects:** The one-off content slug backfill; command memory, open result-set duration, and runtime against a large `content.items` table.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `cursor()` with `chunkById()` — the pattern this codebase already documents and uses in `IngestProjectCommand` specifically because `pdo_pgsql` has no unbuffered fetch mode.
        - Preload `content.item_slugs` for each chunk in one query and check membership from that array instead of an `exists()` query per item.
    - **Technical:** `IngestProjectCommand.php` states this exact rule verbatim: *"chunkById(id), NOT ->get()/cursor(): pdo_pgsql has no unbuffered fetch mode, so cursor() would still buffer the whole result set in libpq while pinning one open result set for the entire multi-hour run."* `BackfillContentItemSlugs` violates that documented codebase invariant, plus issues a separate `content.item_slugs ... exists()` round trip per row. For a large `content.items` population this is materially slower and holds one open result set for longer than necessary, though as a one-off backfill (not a hot path) the urgency is moderate.
    - **Plain English:** The script asks the warehouse for the entire shelf of boxes before starting, then walks back to the front desk to check one binder page for every individual box — one slow check per box, plus a full-shelf load held open the whole time. Better to bring one pallet at a time and check the binder once per pallet.
    - **Evidence:**
        ```php
        ->cursor()
        ->each(function (object $item) use ($allocator, $dry, &$minted, &$skipped, &$failed) {
            $userId = (string) $item->user_id;
            $itemId = (string) $item->id;
        ```
        ```php
        $hasCurrent = DB::connection('pgsql')->table('content.item_slugs')
            ->where('user_id', $userId)->where('item_id', $itemId)
            ->where('is_current', true)->exists();
        ```

- [ ] **#MIG-4** · P2 — `BackfillMediaPaletteCommand` streams an unbounded media backlog via `cursor()` with inline per-row R2/image work
    - **Where:** app/Console/Commands/BackfillMediaPaletteCommand.php
    - **Affects:** Existing gallery images needing palette extraction; R2 read load, temp disk, and image-decode CPU on the operator running the one-off.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Use `chunkById()` rather than `cursor()` on pgsql, matching the pattern this codebase documents in `IngestProjectCommand` (see #MIG-3).
        - For a large backlog, dispatch per-chunk queued jobs with `$backoff` instead of doing the R2 stream + decode inline in the console process, so one slow/failing image doesn't stall the whole sweep.
    - **Technical:** The query uses `->cursor()`, which — per this codebase's own documented understanding — still buffers the result set under `pdo_pgsql`, while each row does a network stream from R2, a temp-file copy, and an image decode inline. The command's own comment already correctly documents that `$timeout` is not read by `Illuminate\Console\Command` and treats `--limit` as the real mitigation, so that half of DeepSeek's original finding is already handled by design and is dropped here — the actionable gap is the `cursor()`/inline-work combination on a backlog with no natural size bound.
    - **Plain English:** The command tries to re-paint every photo in the archive in one sitting, holding the whole shelf's worth of boxes open while it works through them one at a time. The safer design is to process a tray of photos at a time.
    - **Evidence:**
        ```php
        foreach ($query->cursor() as $media) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }
            $processed++;

            try {
                $palette = $this->extractForRow($extractor, $disk, (string) $media->path);
        ```

- [ ] **#MIG-5** · P2 — `BorrowedAssetPruner` materializes every doomed asset ID before chunking the deletes
    - **Where:** app/Services/Migration/BorrowedAssetPruner.php
    - **Affects:** `content.media_assets` growth and the prune command's memory footprint as borrowed assets accumulate.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `pluck('id')->all()` with a chunked query over the same expired/unreferenced predicate, deleting up to `CHUNK` rows per iteration.
        - Add an optional `--limit` and progress logging so the operator can bound and watch the run.
    - **Technical:** The pruner computes `$doomed` by materializing every candidate UUID into a PHP array before the already-chunked delete loop runs. Since borrowed assets grow by ten per place per Google sync until this cleaner runs, the array size is unbounded even though the delete half is chunked. UUID lists are cheap in absolute terms, so this is a hardening item rather than an urgent one, but it's a straightforward fix to make the whole method streaming.
    - **Plain English:** The tool writes every overdue account number on a giant whiteboard before it starts calling customers. The board gets bigger forever, even though the calling is already done in batches of 500 — better to keep a single page of 500 numbers at a time.
    - **Evidence:**
        ```php
        $doomed = (clone $expired)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('content.item_media')
                ->whereColumn('content.item_media.asset_id', 'content.media_assets.id'))
            ->pluck('id')
            ->all();

        $result['pruned'] = count($doomed);

        if ($dryRun || $doomed === []) {
            return $result;
        }

        foreach (array_chunk($doomed, self::CHUNK) as $slice) {
            DB::connection('pgsql')->table('content.media_assets')->whereIn('id', $slice)->delete();
        }
        ```

- [ ] **#MIG-6** · P2 — `ConvergeSiteSubdomainsCommand` commits the raw subdomain rename before cache and KV invalidation
    - **Where:** app/Console/Commands/ConvergeSiteSubdomainsCommand.php
    - **Affects:** `site.sites.subdomain` convergence and the Redis/Cloudflare KV routing state for affected users during the repair.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Resolve all cache keys and the KV alias set **before** the DB update (the command already does this today for logging purposes — `cacheKeysFor()`/`kvPlanFor()` run before the write), then perform the update in a transaction and dispatch invalidation after commit.
        - If a cache/KV step fails, `report()` loudly and leave the row flagged for retry rather than silently reporting "written."
    - **Technical:** `DB::connection('pgsql')->table('site.sites')->update([...])` commits immediately, then `Cache::deleteMultiple($keys)` and the conditional `SyncSubdomainToKvJob::dispatch()` run separately with no transaction wrapping the three. A failure after the update leaves the canonical subdomain moved while stale cache/KV entries persist. This is the same ordering pattern as #MIG-2, and the fix is the same shape. Severity is capped for now by the command's own documented scope: it is explicitly a pre-beta repair tool for drift accumulated before the allocator fix, and the class docblock notes prod currently has zero users (`core.users = 0`), so today's blast radius on prod is effectively nil. On dev, where real accounts exist, a stale-cache window is real but self-healing (bounded by TTL), and this command is run under `--apply` confirmation, not automatically.
    - **Plain English:** The repair changes the store name in the master ledger, then tells the sign-makers. If the sign company doesn't get the message, the ledger says "new name" while customers are still routed by the old sign for a while. Right now there's no live traffic in production to be misrouted, but the same gap would matter once there is.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->table('site.sites')
            ->where('id', $row->site_id)
            ->update(['subdomain' => $new, 'updated_at' => now()]);

        Cache::deleteMultiple($keys);

        if ($kvUsers !== []) {
            SyncSubdomainToKvJob::dispatch((string) $row->user_id);
        }
        ```

- [ ] **#TEST-3** · P2 — Enquiry notification reconciliation's `SKIP LOCKED` concurrency contract has no Postgres-lane test, unlike the analogous claim-vs-prune race
    - **Where:** app/Console/Commands/ReconcileEnquiryNotifications.php (transaction with `lock('for update skip locked')`)
    - **Affects:** Enquiry notification recovery when two scheduler ticks or servers overlap; a regression could double-handle or block under concurrency.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `tests/Postgres/` concurrency test asserting two overlapping reconciles take disjoint enquiry slices under `SKIP LOCKED`, following the shape already shipped for the identical pattern in `PreAccountBuildService`'s claim-vs-prune race (`tests/Postgres/ClaimConcurrencyTest.php`) and the ingest source scheduler (`tests/Postgres/SourceSchedulerConcurrencyTest.php`).
        - If a real-Postgres run is impractical for this command specifically, at minimum add a schema-lane assertion that the exact `lock('for update skip locked')` clause is present.
    - **Technical:** The command's own comment states the SQLite lane cannot exercise this guarantee and explicitly cites `PreAccountBuildService`'s claim-vs-prune pattern as the precedent it mirrors — but that precedent now has a shipped Postgres-lane concurrency test (`ClaimConcurrencyTest.php`), while this command's own equivalent does not. Given the repo already has the `tests/Postgres/` infrastructure and two working examples of this exact test shape, closing this gap is a matter of following an established pattern rather than building new test infrastructure.
    - **Plain English:** The system is designed so two janitors cleaning the same list don't grab the same ticket. A sibling system that works the same way already has a rehearsal for this; this one still doesn't, even though the same rehearsal room is available.
    - **Evidence:**
        ```php
        // SKIP LOCKED so an overlapping run (or a second server) takes a
        // different slice rather than blocking — mirrors the claim-vs-prune
        // pattern in PreAccountBuildService. SQLite ignores the lock clause
        // (Feature suite unaffected); the Postgres behavior is the contract.
        ```

## P3 — Nice to have

- [ ] **#MIG-7** · P3 — `PruneExpiredFeatureFlagOverridesCommand` hard-deletes expired overrides with no dry-run or batching
    - **Where:** app/Console/Commands/PruneExpiredFeatureFlagOverridesCommand.php
    - **Affects:** `core.feature_flag_overrides` table and any operational flag overrides that have expired.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `--dry-run` option mirroring the sibling prune commands (`PruneNotifications`, `PruneOldFeedbackSubmissionsCommand`, etc.).
        - Batch the delete with a bounded `limit()` loop so the table cannot force a long-running single statement as it grows.
    - **Technical:** The command runs a single hard `delete()` with no preview and no batch size. Every other prune command in this bundle follows a `--dry-run` + batched-delete convention; this one is the outlier. The table is almost certainly small today (operational flag overrides), so this is low urgency, but bringing it in line with the sibling commands' convention costs little.
    - **Plain English:** This is a "delete expired post-it notes" button with no "show me first" option. It probably only trashes a few notes now, but every other cleanup tool in this codebase lets the operator preview first — this one should too.
    - **Evidence:**
        ```php
        $deleted = FeatureFlagOverride::whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Backfill streaming hygiene:** #MIG-3, #MIG-4
    - **Why grouped:** Same root cause (`cursor()` on pgsql, contradicting the codebase's own documented `chunkById()` convention) across two independent one-off backfill commands.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Event-item repair ordering + coverage:** #MIG-2, #TEST-2
    - **Why grouped:** Same file, same root cause — the retirement/invalidation ordering fix and the test that should have caught it belong in one review.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Raw-write invalidation & concurrency test coverage:** #TEST-1, #TEST-3
    - **Why grouped:** Both are net-new test additions (no production code paths change) closing coverage gaps the source itself documents; same review shape, low risk to bundle.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Housekeeping prunes:** #MIG-5, #MIG-7
    - **Why grouped:** Both are small, low-risk hardening fixes to one-off/scheduled prune commands with no cross-file dependency.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#MIG-1 — Backfill dispatch stagger reset:** standalone — the fix changes dispatch timing for a command that spends against a billed third-party OCR/AI-extraction budget; verify the corrected delay curve in isolation before pairing with any other change.
- **#MIG-6 — Subdomain convergence write ordering:** standalone — raw writes to `site.sites` (core routing data) with cross-system KV/cache consequences; needs its own plan and sign-off regardless of P2 tier.
