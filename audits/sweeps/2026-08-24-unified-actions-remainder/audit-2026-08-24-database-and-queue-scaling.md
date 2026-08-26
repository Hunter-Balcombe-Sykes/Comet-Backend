# Database & Queue Scaling Audit — 2026-08-24

**Branch:** development
**Lens:** Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by Claude (adjudicator tier)
**Source files audited:**
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- app/Models/Analytics/ActionEvent.php
- app/Models/Core/Site/Site.php
- app/Models/Core/Site/Workplace.php
- app/Models/Core/User/PreAccountBuild.php
- app/Jobs/Platforms/CommerceProbeJob.php
- app/Jobs/Platforms/ShopBrandConnectJob.php
- app/Jobs/Platforms/ShopInitialFillJob.php
- app/Services/Analytics/ActionScorer.php
- app/Services/Analytics/AnalyticsEvent.php
- app/Services/Analytics/ContentFreshness.php
- app/Services/Analytics/ContentPopularityReader.php
- app/Services/Analytics/ItemFamily.php
- app/Services/Platforms/AppleSearch.php
- app/Services/Platforms/ConnectionDisplayName.php
- app/Services/Platforms/GoogleBusinessApifyScraper.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/GoogleBusinessService.php
- app/Services/Platforms/InstagramAutoSync.php
- app/Services/Platforms/LinkInBioApiUnroller.php
- app/Services/Platforms/LinkInBioDetector.php
- app/Services/Platforms/LinkInBioInlinePayloadReader.php
- app/Services/Platforms/LinkRouter.php
- app/Services/Platforms/MediaPageReader.php
- app/Services/Platforms/MediaSeeder.php
- app/Services/Platforms/Payloads/GoogleBusinessPayload.php
- app/Services/Platforms/Registry/PlatformDescriptor.php
- app/Services/Platforms/Strategies/Connect/YoutubeConnect.php
- app/Services/Platforms/Strategies/Fetch/AppleMusicFetch.php
- app/Services/Platforms/Strategies/Fetch/ShopFetch.php
- app/Services/Platforms/YoutubeScraper.php
- app/Console/Commands/ComputeContentPopularityScores.php
- app/Console/Commands/FixturesCaptureCommand.php
- app/Console/Commands/FixturesVerifyCommand.php
- app/Console/Commands/ResetTestUserCommand.php
- app/Http/Controllers/Api/User/Analytics/DevInsightsController.php
- app/Http/Controllers/Api/User/SiteManagement/UserSiteActionsController.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Controllers/Api/PublicSite/ClaimController.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php
- app/Http/Controllers/Api/Content/PoolController.php
- app/Http/Controllers/Api/Routing/RoutingController.php
- app/Http/Controllers/Api/Routing/SuggestionsController.php
- app/Ingest/Projection/FreshaServiceProjector.php
- app/Routing/ConnectionIdentity.php
- app/Routing/Importers/LinkInBioImporter.php
- app/Routing/IriCanonicalizer.php
- app/Routing/LinkRoutingService.php
- app/Routing/Probes/LinkProbeWorker.php
- app/Routing/RoutingContext.php
- app/Routing/SecretParams.php
- app/Routing/ShortLinkExpander.php
- app/Routing/SourceReconciler.php
- app/Site/Actions/ActionCandidates.php
- app/Site/Actions/ActionId.php
- app/Site/Actions/ActionSettings.php
- app/Site/Actions/ActionSlots.php
- app/Site/Actions/ConnectionProfileUrl.php
- app/Site/Pools/PoolOrdering.php
- app/Site/Pools/PoolResolver.php
- app/Site/Pools/PoolSectionProvisioner.php
- app/Site/Pools/PoolWire.php
- supabase/migrations/20260820100000_storefronts_products_autoselected_at.sql
- supabase/migrations/20260820110000_single_account_social_convergence.sql
- supabase/migrations/20260823100000_unified_actions.sql
- supabase/migrations/20260823100001_unified_actions_validate.sql
- supabase/migrations/20260823120000_item_scores_keyed_by_id.sql
- supabase/migrations/20260823130000_service_category_family.sql
- supabase/migrations/20260823130001_service_category_family_validate.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 4 complete
- P2 Medium: 6 of 8 complete
- P3 Low: 0 of 5 complete

---

## P1 — Fix before pilot launch

- [ ] **SCALE-1** · P1 — Accepting a legacy synced-platform suggestion can hold an HTTP worker for ~110s on an inline Apify scrape
    - **Where:** app/Http/Controllers/Api/Routing/SuggestionsController.php:341
    - **Affects:** Any user accepting an Instagram/Google-Business synced-platform suggestion in the inbox; PHP-FPM/Octane worker pool; API latency for unrelated requests during the hold.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Move `applyFinding()` into a queued job and return `202 Accepted` immediately, polling for status client-side.
        - Preserve the existing lock-ordering discipline verbatim when moving this into a job: the code comment explicitly documents that `applyFinding()` must stay outside the platform lock and must fully release before the settle lock is taken (§9.4 of the U1 plan) — do not restructure that ordering while extracting it into a job.
        - Add `$timeout`, `$tries`, and a `failed()` handler sized to the ~110s scrape budget on the new queued path.
    - **Technical:** `acceptPayloadFinding()` calls `$autoSync->applyFinding()` synchronously from a controller action, and the code's own comment states it "can run an inline Apify scrape (~110s)." That holds a request worker for up to two minutes per accept. At modest concurrency (a handful of users accepting suggestions around the same time) this exhausts the worker pool, and client-side retries on a slow response multiply the in-flight work. The lock-ordering comment on this exact line makes clear this is a deliberate, carefully-reasoned design — the fix must extract the call into a job without disturbing that reasoning, not just wrap it in `dispatch()`.
    - **Plain English:** When someone accepts a suggested Instagram or Google listing, the system can disappear for almost two minutes fetching fresh data from Apify before it replies — like a support agent who vanishes into the back room for two minutes to finish one customer's paperwork. A few customers doing this at once ties up every agent at the counter. The fix is to hand the two-minute paperwork to a back-office queue and give the customer a ticket instead of making them (and everyone behind them) wait at the counter.
    - **Evidence:**
        ```php
        // applyFinding stays OUTSIDE the platform lock — it writes OTHER
        // platforms' rows and can run an inline Apify scrape (~110s), which a
        // 10s-TTL lock would expire in the middle of, reopening the very
        // lost-update window the lock exists to close. It also takes its own
        // booking/reservations XOR lock internally, and this call fully
        // releasing first is what keeps that ordering acyclic (§9.4 of the U1
        // plan). Do not move it inside the closure below.
        if (! $autoSync->applyFinding((string) $user->id, $located['finding'])) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }
        ```

- [ ] **SCALE-2** · P1 — Public sitepage pool hydration fetches every pool's full 500-item library on every cache miss, then discards it
    - **Where:** app/Site/Pools/PoolWire.php:111-117, 184-195
    - **Affects:** Public sitepage resolution — the hottest backend path. On a viral cache miss, DB read volume and PHP memory multiply well past what the visitor's page actually needs before the response is served.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a public-only code path through `hydrateItems()`/`itemPayloads()` that hydrates only `$plan['selectionIds']`, skipping `libraryIds` entirely for the public payload consumer.
        - Keep the current library-inclusive hydrate for the dashboard/actions consumer (`UserSiteActionsController`), which genuinely needs the library for the swap picker.
    - **Technical:** `PoolWire::forSite()` builds `$allIds` by appending both `...$plan['selectionIds']` and `...$plan['libraryIds']` for every pool and passes the union to one shared `hydrateItems()` call — but the output loop that follows uses only `$resolved['selection']`; `$resolved['library']` is never read. `PoolResolver::LIBRARY_LIMIT` is 500 per pool across ~7-9 pools, so a single public cache miss can hydrate up to ~3,500-4,500 item payloads (each pulling ~20 facet-table queries per the file's own docblock) only to throw away everything but the selected items. Recent commits `f263a284a` ("Batch pool hydration: plan -> one hydrate -> assemble") and `a0739ffd6` consolidated what used to be nine independent per-pool hydrations into one shared batch specifically to fix `GET /site/actions` latency (244 queries → ~60) — a real improvement — but that refactor shares one hydrate across three consumers (public payload, action candidates, scoring job) without a mode that skips the library for the one consumer (public payload) that never uses it.
    - **Plain English:** Building a visitor's page now quietly fetches the full inventory of every shelf in the back room — up to thousands of items — even though the visitor only ever sees what's on display. When one user's page goes viral and the cache has to rebuild, the back room gets swamped pulling inventory nobody will see, right at the moment speed matters most.
    - **Evidence:**
        ```php
        $allIds = [];
        foreach ($plans as $plan) {
            array_push($allIds, ...$plan['selectionIds'], ...$plan['libraryIds']);
        }

        try {
            [$payloads, $stores] = $this->pools->hydrateItems($site, array_values(array_unique($allIds)));
        }
        ```
        ```php
        $out[$pool] = [
            'items' => array_map(
                static function (array $item): array {
                    foreach (PoolResolver::DASHBOARD_ONLY_ITEM_KEYS as $key) {
                        unset($item[$key]);
                    }

                    return $item;
                },
                $resolved['selection'],
            ),
        ```

- [ ] **SCALE-3** · P1 — The 15-minute popularity-scoring pipeline re-aggregates a site's entire raw event history on every run, with no lower time bound
    - **Where:** app/Console/Commands/ComputeContentPopularityScores.php:510-585 (`aggregateItems`); app/Services/Analytics/ActionScorer.php:166-181 (`aggregate`)
    - **Affects:** The scheduled `analytics:compute-popularity` command (`routes/console.php`, every 15 minutes, 16-minute `withoutOverlapping` lock) for every active site; DB read volume and job runtime grow without bound as a site's event history accumulates, hitting hardest on a viral site's `analytics.item_views` / `analytics.link_clicks` / `analytics.action_events` / `analytics.section_views` volume.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add an `occurred_at >=` lower bound to `aggregateItems()`'s four raw-table queries and to `ActionScorer::aggregate()`'s query, sized to the day-bucket half-life window already used for decay weighting (90 days) rather than reading from the beginning of time every run.
        - If a longer lookback is genuinely needed for the decay math, persist per-day rollups incrementally instead of re-scanning full history each tick.
    - **Technical:** The team already fixed one half of this scaling problem — the code comments at `ComputeContentPopularityScores.php:110-122` and `:183-187` (marked "SCALE-3" from a prior audit) show the *site selection* for a scheduled run was narrowed to sites with recent activity. But the *event-range* read inside `aggregateItems()` and `ActionScorer::aggregate()` was not: both query `analytics.item_views` / `analytics.link_clicks` / `analytics.action_events` / `analytics.section_views` with only a `site_id` filter and no `occurred_at` lower bound, then `groupByRaw(...)->get()->each(...)`. For a site whose page goes viral and stays active, this becomes a full-history re-scan of that site's entire raw event table on every 15-minute tick, forever — the exact "hottest write path re-read on every cache-miss cycle" shape this lens is built to catch, except here it fires on a schedule rather than a cache miss, compounding as history grows.
    - **Plain English:** Every 15 minutes, this job re-reads a site's *entire* history of page views and clicks — not just what's new — just to compute today's popularity ranking. That's fine for a small account, but once someone's page goes viral and stays busy, the job keeps re-reading a growing mountain of old records every 15 minutes, forever, and it never gets any faster no matter how good the site selection filter is.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->table('analytics.item_views')
            ->where('site_id', $site->id)
            ->selectRaw("item_type, item_id, {$day} as day, COUNT(*) as impressions")
            ->groupByRaw("item_type, item_id, {$day}")
            ->get()
            ->each(function ($r) use ($bump, $now): void {
        ```
        ```php
        DB::connection('pgsql')->table('analytics.action_events')
            ->where('site_id', $site->id)
            ->selectRaw("action_id, event, {$day} as day, COUNT(DISTINCT COALESCE(session_id, visitor_id, id)) as sessions")
            ->groupByRaw("action_id, event, {$day}")
            ->get()
            ->each(function ($r) use (&$exposures, &$taps, $now): void {
        ```

- [ ] **SCALE-4** · P1 — ShopBrandConnectJob's 75s timeout does not cover the inline catalogue fill it performs after settle, and this exact failure has already occurred live
    - **Where:** app/Jobs/Platforms/ShopBrandConnectJob.php:58, 216-241
    - **Affects:** Every shop connect that reaches the post-settle initial catalogue fill and auto-select; the job can be killed mid-fill, leaving an empty or partially filled product library and stranding the once-only auto-select.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Dispatch `ShopInitialFillJob` (already exists, `$timeout = 240`, built for exactly this fill+select tail — see its own docblock referencing this job) instead of running `ShopCatalog::syncLatest()` and `ShopAutoSelector::selectInitial()` inline after `settle()`.
        - Keep `ShopBrandConnectJob` scoped to profile fetch, guarded settle, cache purge, and logo dispatch, matching what `ShopInitialFillJob` was built to offload for the seeder lane.
    - **Technical:** `ShopBrandConnectJob::$timeout = 75` is sized for one profile fetch (`connect_budget_seconds`, default 20s, plus retry headroom), but `handle()` runs `ShopCatalog::syncLatest()` (N product upserts) and `ShopAutoSelector::selectInitial()` inline afterward. The sibling job `ShopInitialFillJob`'s own docblock documents the live incident: "Measured live (T5/T7, 2026-08-20): a 7-product fill against a remote DB ran ~70-80s and the 75s ceiling killed the worker between fill and select." `ShopInitialFillJob` was built with `$timeout = 240` specifically to absorb this workload for the `StoreBrandSeeder` (scan-suggested) lane — but `ShopBrandConnectJob`'s own inline fill+select, which triggered the original incident, was never redirected to it. At thousands of users connecting shops concurrently, every connect's fill is one 75s roll of the dice against a workload already measured to routinely need 70-80s+.
    - **Plain English:** Connecting a shop is supposed to also stock the product shelf and pick a few items to feature — but the clock the system gives itself for that whole job is shorter than the job has already been measured to take. This exact failure happened in production once already: the timer cut the worker off mid-restock, and the shelf stayed empty until the next scheduled refresh. The team already built a fix for this — a job with a longer timer built for exactly this task — but the original connect job never got switched over to using it.
    - **Evidence:**
        ```php
        public int $timeout = 75;
        ```
        ```php
        try {
            app(ShopCatalog::class)->syncLatest(
                $shop->storeByCollection($this->collectionId) ?? $store,
                (string) $store->userId,
            );
        } catch (Throwable $e) {
            Log::warning('shop.brand_connect_job.initial_fill_failed', [
        ```
        ```php
        // NOT ShopBrandConnectJob's 75s: that ceiling covers ONE profile fetch,
        // while this job does the whole catalogue fill (N product upserts) plus
        // the auto-select. Measured live (T5/T7, 2026-08-20): a 7-product fill
        // against a remote DB ran ~70-80s and the 75s ceiling killed the worker
        // between fill and select — the once-only select then waited for the 6h
        // ShopFetch late hook instead of firing at connect time.
        ```

## P2 — Should fix

- [x] **SCALE-5** · P2 — Link-in-bio importer fetches up to 50 pages of one host sequentially with no per-request delay
    - **Resolved 2026-08-26** — pacing, not volume: `MAX_PAGES` stays at 50. `import()` now calls `paceNextFetch()` once per iteration on BOTH loop paths (the unavailable branch pauses too — a 403 burst is what escalates a soft throttle into a hard block), reading `config('partna.routing.link_in_bio.page_delay_ms')`, default 250ms via `PARTNA_LINK_IN_BIO_PAGE_DELAY_MS`. Never before the first page, never after the last, no-op at <= 0. Uses `Illuminate\Support\Sleep`, not `usleep()`, so the spacing is assertable without a slow suite. **Never sleeps on a request path**: gated on `app()->runningInConsole()`, so a queue worker paces and an inline `sync` dispatch inside an HTTP request does not — the only production caller is `LinkInBioScanJob::handle()`, and Octane (the one case that would misreport that gate) is not installed. Measured against recorded fixtures: 50 pages -> 49 sleeps x 250ms = 12,250ms simulated; 1 page -> 0 sleeps; unfaked 3 pages at 50ms -> 135.5ms real wall-clock, so it genuinely sleeps rather than recording an intent. Five tests added, mutation-proved twice (no-op the pacer -> red; drop the last-page guard -> red, including on the single-page case). **NOT done, surfaced instead:** the second bullet's shared delay budget with the scheduled `integrations:refresh` path. That is a per-host token bucket in Redis keyed on the registrable host, consulted by both this loop and the batch refresh's fetches — cross-cutting shared state, its own plan. See RESULT-PART-3.md.
    - **Where:** app/Routing/Importers/LinkInBioImporter.php:53, 121-134
    - **Affects:** The bio-link host being imported from (Linktree, Beacons, etc.); a single import run can burst up to 50 rapid requests at one host, risking a throttle/WAF block for that user's import (and any concurrent import against the same host).
    - **Effort:** S-M (~1–2h)
    - **What to do:**
        - Add a small delay (or token-bucket) between successive `tryFetch()` calls in the `$pages` loop.
        - Share the delay budget with the scheduled `integrations:refresh` batch path so a concurrent user import and a scheduled refresh against the same host cannot amplify each other.
    - **Technical:** `import()` iterates `$pages` (capped at `MAX_PAGES = 50`) and calls `$this->fetcher->tryFetch($pageUrl)` back-to-back with no delay between requests. `SafeUrlFetcher` provides SSRF protection and retry handling but no per-host pacing. Fifty rapid sequential requests to one host inside a single import is exactly the shape a bio-link aggregator's own bot-detection is built to catch, and a block would degrade every future import from that host, not just this one user's.
    - **Plain English:** When someone imports their link-in-bio page, the system can knock on that host's door up to 50 times in a row with no pause between knocks. To that host, this looks like an automated attack rather than one person's import, and their defenses may block us — breaking imports for everyone, not just this one user.
    - **Evidence:**
        ```php
        private const MAX_PAGES = 50;
        ```
        ```php
        foreach ($pages as $pageUrl) {
            $response = $this->fetcher->tryFetch($pageUrl);

            if ($response === null || $response['status'] !== 200 || $response['body'] === '') {
                $unavailable++;
        ```

- [x] **SCALE-6** · P2 — Pool section curation is read with no row limit on both the public payload path and the presence probe
    - **Resolved 2026-08-26** — the primary remedy (column projection) landed in `baa54b91e` as `#SCALE-13`: all THREE `site.section_items` reads — `hasSelection()`, `plan()` and `preloadCuration()` — now select `['section_id','item_id','state','sort_key']`; `id` and `created_at` are read nowhere. Measured on a 2000-row section: 432,000 -> 274,000 bytes returned, same 2000 rows. The secondary suggestion (an early-exit query shape for `hasSelection()`) is deliberately NOT done: the comment above its `in_array('review', ...)` branch records that answering from `site.section_items` alone is what lets the presence probe succeed where `content.*` is absent, and it is pinned by `PresenceProbeEscalationTest`/`PresenceProbeLoggingTest`. A `LIMIT 1` does not drop in there.
    - **Where:** app/Site/Pools/PoolResolver.php:116-118 (`hasSelection`), 230-232 (`plan`)
    - **Affects:** Public sitepage payload build and page-presence probing for heavily-curated users; both queries load a section's entire curation history in full before filtering in PHP.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Project only the columns each caller needs (`item_id`, `state`, `sort_key`) rather than `select *`.
        - For `hasSelection()`, which only needs to know whether ANY non-excluded candidate exists, consider an early-exit query shape instead of materialising the full curation set.
    - **Technical:** Both `plan()` and `hasSelection()` run `DB::table('site.section_items')->where('section_id', $section->id)->get()` with no `limit` and no column projection, then filter pinned/excluded state in PHP. Practical row counts here are bounded by how many items a single owner has manually pinned or excluded for one pool section — typically small — but the pattern is unbounded by construction and duplicated across two call sites, and `hasSelection()` in particular only needs a boolean answer.
    - **Plain English:** Every time the system checks what's pinned or hidden on one section of a profile, it pulls every pin/hide record ever made for that section into memory before checking. For most owners that's a short list, but the code has no ceiling on how long that list is allowed to get.
    - **Evidence:**
        ```php
        $curation ??= DB::connection('pgsql')->table('site.section_items')
            ->where('section_id', $section->id)
            ->get();
        ```
        ```php
        $curation = DB::connection('pgsql')->table('site.section_items')
            ->where('section_id', $section->id)
            ->get();
        ```

- [ ] **SCALE-7** · P2 — Staff batch pre-account build endpoint runs up to 500 synchronous build calls inside one HTTP request
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php:165-189
    - **Affects:** Staff importing builds via CSV; the staff API worker pool during a batch import, and the requesting staff member's own request timeout.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Dispatch a queued batch job that processes the parsed rows off-request and returns `202 Accepted` with a status the staff UI can poll.
        - Keep the per-row failure collection (row index + code) in the job's result payload so the staff UX is unchanged.
    - **Technical:** `batch()` loops over up to 500 CSV rows and calls `$this->builds->requestBuild(...)` synchronously for each, inside the controller action, before responding. Each call performs database writes and (per `requestBuild`'s contract) can enqueue further side effects. This is staff-only and infrequent, so it is not a public-facing DoS surface, but a full 500-row batch still risks the requesting staff session's own request timing out and ties up a staff API worker for the whole run.
    - **Plain English:** When a staff member uploads a spreadsheet of up to 500 new sites to create, the system processes every single row, one at a time, while that staff member's browser just sits there waiting — instead of saying "got it, we'll work through this in the background" and freeing up the counter for the next task.
    - **Evidence:**
        ```php
        foreach ($rows as $i => $row) {
            $email = $row['contact_email'] ?? null;
            if ($email !== null && $email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed[] = ['row' => $i + 1, 'code' => 'INVALID_EMAIL', 'message' => "Invalid email: {$email}"];

                continue;
            }

            try {
                $result = $this->builds->requestBuild(
                    accountType: (string) ($row['account_type'] ?? ''),
        ```

- [x] **SCALE-8** · P2 — The suggestions inbox performs a per-intent connection lookup and occasional write for up to 100 rows on every GET
    - **Resolved 2026-08-26** — **the hidden write was the real finding and it is gone.** `resolveSwapIncumbent()` split into a pure `decideSwapIncumbent()` (mutates `$intent` in place — which is what the JSON renders — and REPORTS the columns to persist) and the persisting wrapper, which only `accept()` now calls. The GET issues zero writes. Safe because the persistence was a pre-warm, never a correctness requirement: `accept()` re-resolves before acting, `SuggestionApplier` reads `conflicting_connection_id` off the in-memory object rather than re-reading the row, `findIntent()` matches `('proposed','blocked')` so a not-yet-flipped intent is still findable, and `CheckStuckSourceIntentsCommand` counts both states together so the backlog alarm does not move. The N+1 collapsed alongside it: one `whereIn('surface_key', ...)` read grouped in memory, skipped entirely when no intent on the page needs resolving. **Measured, N=100 intents (40 `cap_reached` across 40 single-account surfaces): 83 queries (43 reads + 40 WRITES) -> 4 queries (4 reads + 0 writes).** JSON body byte-identical, verified by comparing full responses across all six branch cases. Mutation-proved: reintroducing a persist on the GET path turns BOTH inbox tests red — the second one only after this fix added an explicit null-check BEFORE `accept()`, because `accept()`'s own write had been absorbing the premature one and hiding it.
    - **Where:** app/Http/Controllers/Api/Routing/SuggestionsController.php:75-77, 416-461
    - **Affects:** Suggestions inbox load latency; Postgres statement volume when many users open their inbox around the same time; `routing.source_intents` write volume on a read endpoint.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the per-intent `resolveSwapIncumbent()` lookup with one `IntegrationConnection` query using `whereIn('surface_key', $surfaceKeys)`, grouping results in memory.
        - Batch the `routing.source_intents` corrective updates into one set-based statement, or defer them to a queued reconciliation pass rather than performing them during the GET.
    - **Technical:** `index()` fetches up to 100 intents (`->limit(100)`) and calls `resolveSwapIncumbent()` for each. For every `cap_reached` intent still missing a resolved conflict, that method issues a separate `IntegrationConnection` SELECT and sometimes an `UPDATE routing.source_intents`. This is both an N+1 read pattern and write-during-a-GET amplification; unlike most per-user reads on this platform, 100 rows clears this lens's "load < 50 rows" always-drop threshold, so it's worth fixing.
    - **Plain English:** Opening the suggestions inbox can check and sometimes silently update up to 100 separate records one-by-one behind the scenes, each a separate trip to the database, instead of checking them all at once.
    - **Evidence:**
        ```php
        $suggestions = $intents->map(function (object $intent) use ($user): array {
            $surface = CompiledCatalog::surface($intent->surface_key);
            $this->resolveSwapIncumbent($user, $intent, $surface);
        ```
        ```php
        $others = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('surface_key', $intent->surface_key)
            ->where('resource_id', '!=', (string) $intent->identifier)
            ->orderBy('created_at')
            ->pluck('id');
        ```

- [x] **SCALE-9** · P2 — Public payload build unconditionally runs a dashboard-only duplicate-detection join and then strips the result
    - **Resolved 2026-08-26** — same fix as the delta sweep's `#API-7`; see its entry. `itemPayloads()`/`hydrateItems()` take `bool $withDuplicateCandidates = true` (the `$withLibrary` idiom already in this class), `PoolWire::forSite()` passes false, and the `content.identity_candidates` join is skipped outright on the public path. Public wire byte-identical: `duplicateCandidates` was already in `DASHBOARD_ONLY_ITEM_KEYS` and already stripped by `PoolWire`, and the key is still emitted as `[]` so the array shape does not vary. Measured: public 82 -> 81 queries (`identity_candidates` 1 -> 0), dashboard 25 -> 25. Mutation-proved both directions; full suite 9319 passed / 3 skipped / 0 failed.
    - **Where:** app/Site/Pools/PoolResolver.php:72, 880-892
    - **Affects:** Public sitepage response time and DB read volume on every payload build, including cache-miss rebuilds.
    - **Technical:** `itemPayloads()` unconditionally joins `content.identity_candidates` for every item id passed in and attaches `duplicateCandidates` to each resulting payload. `PoolResolver::DASHBOARD_ONLY_ITEM_KEYS` (line 72) already lists `duplicateCandidates`, and `PoolWire::forSite` strips that key before building the public wire. So every public sitepage build — the hottest read path on this platform — performs this join and builds the in-memory duplicate map for data no visitor will ever see.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Gate the `content.identity_candidates` query behind a flag passed into `itemPayloads()`, set only when the dashboard (owner-facing "possible duplicates" UI) is the caller.
        - Skip `duplicateCandidates` construction entirely on the public/`PoolWire` path.
    - **Plain English:** For every visitor page build, the system also quietly checks "does this item look like a duplicate of another one?" — a question only the site owner's dashboard ever asks — and then throws the answer away before showing the visitor anything.
    - **Evidence:**
        ```php
        $candidateRows = DB::connection('pgsql')->table('content.identity_candidates as ic')
            ->join('content.items as li', 'li.id', '=', 'ic.left_item_id')
            ->join('content.items as ri', 'ri.id', '=', 'ic.right_item_id')
            ->whereNull('ic.dismissed_at')
            ->whereNull('li.removed_at')->whereNull('ri.removed_at')
            ->where(fn ($w) => $w->whereIn('ic.left_item_id', $ids)->orWhereIn('ic.right_item_id', $ids))
            ->get(['ic.left_item_id', 'ic.right_item_id', 'ic.evidence', 'li.headline_cache as left_headline', 'ri.headline_cache as right_headline']);
        ```

- [ ] **SCALE-10** · P2 — Pasting a link performs up to 45s of synchronous vendor I/O on the request path
    - **Where:** app/Http/Controllers/Api/Routing/RoutingController.php:148-151, 198-201
    - **Affects:** Users pasting links; worker capacity and API latency for other routes during link-processing bursts.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Keep only fast, cached decisions synchronous; move item classification/seed and full route expansion to a queued job with a `202`/polling contract for anything that would exceed a much lower synchronous budget (e.g. 5-10s).
        - Add a per-user concurrency limit on this endpoint so one user pasting several links in a row cannot occupy several workers at once.
    - **Technical:** `store()` opens a `connect_budget_seconds` window (default 45) for both the content-item/event seed branch and the fallback `routing->route()` call. The budget bounds worst-case latency, but still holds a web worker for up to 45s while waiting on external fetches (short-link expansion, item classification). A burst of pasted links from one or more users can occupy several workers concurrently at up to 45s each.
    - **Plain English:** Every time someone pastes a link, the system can leave the checkout counter to make a phone call that lasts up to 45 seconds before it can respond. A few people pasting links at the same time can tie up several counters at once.
    - **Evidence:**
        ```php
        $result = $this->budget->open(
            (float) config('partna.http_fetch.connect_budget_seconds', 45),
            fn (): array => $this->routing->route($url, RoutingContext::forUser($user, 'paste')),
        );
        ```

- [x] **SCALE-11** · P2 — ContentFreshness loads every non-removed item across a site's catalogue on every scoring run, no chunking
    - **Resolved 2026-08-26** — de-duplication moved into SQL. `content.f_published`'s PK is `(item_id, source_id)`, so the LEFT JOIN multiplied rows per source and PHP re-derived a minimum the database can compute: now `MIN(fp.published_from)` with `GROUP BY i.id, i.kind, i.first_seen_at`, one row per item, and the two accumulation loops collapse to one. Fail-open `catch (QueryException)` untouched. Measured on 300 items x 3 sources: 900 -> 300 rows returned, 1 query either way; at 3300 items the heap delta for the read fell 6,384,040 -> 375,472 bytes. Coverage was VACUOUS before this (nothing in the suite made two `f_published` rows for one item, so MIN vs MAX was invisible) — `ContentFreshnessTest` gained a two-source case that fails under a MIN->MAX mutation, plus an all-NULL fallback case.
    - **Where:** app/Services/Analytics/ContentFreshness.php:47-58
    - **Affects:** Popularity/freshness scoring for prolific users with many shop/menu/service/gallery/link items; memory during the scheduled scoring job.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Aggregate the earliest `published_from` in SQL (`MIN(fp.published_from)` grouped by item) rather than fetching every joined row and de-duplicating in PHP.
        - If a full-collection read is unavoidable, use `cursor()`/`LazyCollection` instead of `get()`.
    - **Technical:** `boostsForSite()` loads every non-removed item for a site (joined against every `content.f_published` row, which can multiply the result set per item) with a single `get()`, then de-duplicates into a PHP array. For a user with a large or heavily-multi-sourced catalogue this becomes a multi-thousand-row in-memory collection before de-duplication, on every scoring tick.
    - **Plain English:** Before computing how "fresh" a user's content looks, the system asks for every item they've ever posted, including duplicate records from different sources, just to work out when each one first appeared — instead of asking the database to just hand back the earliest date directly.
    - **Evidence:**
        ```php
        $rows = DB::connection('pgsql')->table('content.items as i')
            ->leftJoin('content.f_published as fp', 'fp.item_id', '=', 'i.id')
            ->where('i.user_id', $site->user_id)
            ->whereIn('i.kind', array_keys(ItemFamily::KIND_TO_FAMILY))
            ->whereNull('i.removed_at')
            ->get(['i.id', 'i.kind', 'i.first_seen_at', 'fp.published_from']);
        ```

- [x] **SCALE-12** · P2 — ContentPopularityReader loads a site's full popularity-score set with no cursor across three read methods
    - **WONTFIX 2026-08-26 — the suggested remedy is a regression, measured not argued.** `lazy()` pages with plain LIMIT/OFFSET (`BuildsQueries::lazy()` re-issues `forPage(...)->get()`), not a keyset cursor. Measured at N=20,000 rows for one site: `->get()` = 1 query / ~10 MB peak delta; `->lazy(1000)` = **21 queries** / ~0-2 MB. The 8 MB is bought with 21x the round-trips on a path that sits behind the 60s public-profile cache. Worse, the table's only uniqueness is `UNIQUE(site_id, content_type, content_key)` — there is NO unique key on `(site_id, content_type, rank)`, which is what these queries order by — so OFFSET paging silently skips rows when the table mutates mid-scan. Demonstrated empirically: 5000 uniquely-ranked rows, `lazy(1000)`, delete 7 already-visited rows after page 1, and the 7 rows at ranks 1001-1007 were **never visited** — no error, no log. `ComputeContentPopularityScores` upserts this exact table on a schedule while `PoolResolver::popularityRanks()` reads it, so that race is live. Row counts here are also per-site and bounded per family, not unbounded. If memory ever bites at higher N the answer is `chunkById()` on the `id` PK (which IS unique), not `lazy()`. Measurement script + raw output: `.audit-work/part3/measure-13c.php`.
    - **Where:** app/Services/Analytics/ContentPopularityReader.php:33-59 (`forSite`), 68-90 (`actionScoresForSite`), 125-148 (`itemScoresForSite`)
    - **Affects:** Public sitepage payload builds on a cache miss (this reader sits behind the 60s public-profile cache, per its own docblock) and the popularity scoring job, for sites with a large content catalogue.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Use `cursor()`/`LazyCollection` while building each of the three output maps instead of materialising the full `get()` result first.
        - Where two of the three methods are called in the same request/job, consider sharing one fetched dataset instead of three separate full reads.
    - **Technical:** All three reader methods run `->get(...)` against `analytics.content_popularity_scores` scoped only by `site_id` (and `content_type` for two of them), with no row limit, then loop to build a keyed map. `forSite()` is on the public sitepage cache-miss path per its own docblock ("One indexed read per build ... behind the 60s public-profile cache"). Row count scales with a site's total scored content (item + category + action families), which is unbounded by construction, though `actionScoresForSite()` is lower-risk since actions are bounded by configured slots. All three share the identical anti-pattern and should be fixed together.
    - **Plain English:** When a visitor's page needs to be rebuilt after the cache expires, up to three separate reads pull in a site's *entire* set of popularity scores in one go before sorting them into a lookup table — for a site with a large catalogue, that's like unloading a whole delivery truck before checking which boxes are actually needed.
    - **Evidence:**
        ```php
        $rows = DB::connection('pgsql')
            ->table('analytics.content_popularity_scores')
            ->where('site_id', $siteId)
            ->where('content_type', '!=', ActionScorer::CONTENT_TYPE)
            ->orderBy('content_type')
            ->orderBy('rank')
            ->get(['content_type', 'content_key', 'rank']);
        ```
        ```php
        $rows = DB::connection('pgsql')
            ->table('analytics.content_popularity_scores')
            ->where('site_id', $siteId)
            ->where('content_type', '!=', ActionScorer::CONTENT_TYPE)
            ->get(['content_key', 'score']);
        ```

## P3 — Nice to have

- [ ] **SCALE-13** · P3 — Social-connection convergence migration lacked `lock_timeout`/`statement_timeout` guards its sibling migrations use
    - **Where:** supabase/migrations/20260820110000_single_account_social_convergence.sql
    - **Affects:** `site.platform_connections` write availability, had this migration hit contention at deploy time. Already applied; documented here for template consistency on future large-table cleanup migrations.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - For future one-time data-convergence migrations against live tables, follow the pattern the two later migrations in this same batch (`20260823100000_unified_actions.sql`, `20260823120000_item_scores_keyed_by_id.sql`) already establish: `SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s';` before the write.
    - **Technical:** This migration's `UPDATE site.platform_connections` (soft-deleting duplicate social connections past the first per user+surface) carries no `lock_timeout`/`statement_timeout`, unlike the two `unified_actions`/`item_scores` migrations in the same recent batch, which both explicitly set both. The migration's own header notes "Dev impact measured before writing: exactly one affected user," and it has already been applied with no incident — so this is a template-consistency finding for future migrations of this shape, not a live risk today. Prod carries no customer data yet, so re-verifying this specific file's blast radius is moot.
    - **Plain English:** This one-time cleanup script didn't set a safety timer the way two very similar cleanup scripts written a few days later did. It ran fine (only one row was affected), but the missing safety net means a future script copied from this one instead of the newer ones would be missing a protection the team has since adopted as standard.
    - **Evidence:**
        ```sql
        with ranked as (
            select id,
                   row_number() over (
                       partition by user_id, surface_key
                       order by is_active desc, is_primary desc, created_at asc, id asc
                   ) as rn
            from site.platform_connections
            where routing_class = 'social'
              and deleted_at is null
        )
        update site.platform_connections pc
        set deleted_at = now(), is_active = false, updated_at = now()
        from ranked
        where ranked.id = pc.id
          and ranked.rn > 1;
        ```

- [ ] **SCALE-14** · P3 — Staff batch-build CSV upload is fully read into memory before the 500-row cap is applied
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php:204-227
    - **Affects:** Staff batch build uploads; PHP memory on the staff API worker serving the request. Staff-only, infrequent.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `file_get_contents()` + `preg_split()` with streaming `fgetcsv()`/`SplFileObject` iteration, stopping after `$cap + 1` rows.
    - **Technical:** `parseCsv()` reads the entire uploaded file into a string, splits it into every line, builds an assoc row per line, and only afterwards slices to 500 rows — memory usage scales with file size, not the 500-row cap. Staff-only and low-frequency, so this is polish rather than an urgent fix.
    - **Plain English:** Before trimming an uploaded spreadsheet down to the first 500 rows, the system first loads the entire file into memory, however large it is. A very large accidental upload could use far more memory than the 500-row limit implies.
    - **Evidence:**
        ```php
        $content = (string) file_get_contents($file->getRealPath());
        $lines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];
        ```

- [ ] **SCALE-15** · P3 — Link preview makes an up-to-8s synchronous fetch on every debounced keystroke pause
    - **Where:** app/Http/Controllers/Api/Routing/RoutingController.php:69-82
    - **Affects:** Users typing URLs into the link form; worker pool on a high-frequency, client-debounced endpoint.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Consider a tighter `preview_budget_seconds` or a cached short-link expansion for repeated previews of the same URL.
    - **Technical:** `preview()` opens an 8-second fetch budget on a debounced typing path. The endpoint's own comment notes it already degrades gracefully to the unexpanded answer on a budget miss, and frequency is naturally capped by client-side debounce, so this is lower-severity polish rather than an urgent fix.
    - **Plain English:** The link-preview box makes a phone call every time someone pauses typing, and that call can take up to 8 seconds. It should feel instant, falling back gracefully when it can't be.
    - **Evidence:**
        ```php
        $result = $this->budget->open(
            (float) config('partna.http_fetch.preview_budget_seconds', 8),
            fn (): array => $this->routing->preview($url, RoutingContext::forUser($user, 'paste')),
        );
        ```

- [ ] **SCALE-16** · P3 — RUM beacon endpoint writes a synchronous log line for every accepted request on the public path
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php:596-631
    - **Affects:** Application log pipeline under high public sitepage traffic.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Sample the RUM beacon traffic before logging, or route it to an async log channel, if log volume becomes a measured problem.
    - **Technical:** The RUM endpoint performs `Log::info()` synchronously in the request path for every non-bot beacon. Under a viral page load this can produce a high volume of small synchronous log writes. This is low-severity — Laravel's default log drivers are fast and non-blocking in most configurations — so it's flagged as an observability-cost item to watch, not an urgent fix.
    - **Plain English:** Every visitor's page-load timing gets written to the log file before the response can complete. At small scale this is invisible; at very high traffic it's worth watching whether the logging pipeline keeps up.
    - **Evidence:**
        ```php
        Log::info('rum', [
            'handle' => hash('sha256', strtolower($handle)),
            'ttfb_ms' => isset($payload['ttfb']) ? (int) $payload['ttfb'] : null,
            'dom_ms' => isset($payload['dom']) ? (int) $payload['dom'] : null,
            'load_ms' => isset($payload['load']) ? (int) $payload['load'] : null,
            'fcp_ms' => isset($payload['fcp']) ? (int) $payload['fcp'] : null,
            'lkg' => isset($payload['lkg']) ? (bool) $payload['lkg'] : false,
            'ua' => AnalyticsEventSanitizer::userAgent($request->userAgent()),
            'country' => $request->header('cf-ipcountry'),
        ]);
        ```

- [ ] **SCALE-17** · P3 — Dev-insights endpoint aggregates a site's full analytics history, twice per page-score field, on every request
    - **Where:** app/Http/Controllers/Api/User/Analytics/DevInsightsController.php:91-130 (`pageScores`), 158-210 (`itemScores`)
    - **Affects:** The authenticated owner's own dev-insights view (`GET /api/professional/dev-insights`); not user-reachable by anyone but the site owner, and self-scoped to that owner's own event volume.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add the same `$since` date bound the endpoint's own `daily_series` methods already use, unless the summary boxes genuinely need all-time totals.
        - Combine the two separate `section_views` aggregate queries (impressions, dwell) into one query selecting both.
    - **Technical:** `pageScores()` and `itemScores()` both aggregate full-history totals from `analytics.section_views`, `analytics.link_clicks`, and `analytics.item_views` with only a `site_id` filter, no `occurred_at` bound — unlike the same controller's `pageDailySeries()`/`itemDailySeries()` methods, which do bound by `$since`. Docblock marks this "DEV / testing endpoint... Not production-critical," and it only ever reads the requesting owner's own event history (not cross-user), so severity is low, but the query volume grows unbounded with that owner's own traffic on every page open.
    - **Plain English:** This screen's summary boxes ignore the date range used everywhere else on the same page and instead total up a user's entire history every time it's opened — for a busy account, opening this screen gets slower over time.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->table('analytics.section_views')
            ->where('site_id', $siteId)->whereNotNull('section_key')
            ->selectRaw('section_key, COUNT(*) as n')->groupBy('section_key')->pluck('n', 'section_key')

        DB::connection('pgsql')->table('analytics.section_views')
            ->where('site_id', $siteId)->whereNotNull('section_key')
            ->selectRaw('section_key, COALESCE(SUM(duration_ms), 0) as n')->groupBy('section_key')->pluck('n', 'section_key')
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Pool hydration hot-path efficiency:** SCALE-2, SCALE-6, SCALE-9
    - **Why grouped:** All three are `PoolWire`/`PoolResolver` — the same shared hydration pipeline for public sitepage resolution — trimming work the public wire never uses (library payloads, duplicate-candidate join, unbounded curation reads).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Analytics popularity read efficiency:** SCALE-11, SCALE-12, SCALE-17
    - **Why grouped:** Same subsystem (analytics popularity scoring reads) and same anti-pattern (unbounded `get()` against `content.items`/`analytics.content_popularity_scores` instead of a cursor or SQL-side aggregate).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Routing/paste request-path backpressure:** SCALE-8, SCALE-10, SCALE-15
    - **Why grouped:** Same controller family (`SuggestionsController`/`RoutingController`), same theme (bounding synchronous work on user-triggered routing requests).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Staff batch pre-account import hygiene:** SCALE-7, SCALE-14
    - **Why grouped:** Same file (`StaffPreAccountBuildController`), same endpoint (`POST /api/staff/builds/batch`).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **SCALE-1 — SuggestionsController inline Apify scrape:** P1, and the existing code carries an explicit, detailed lock-ordering warning ("Do not move it inside the closure below") that a bundled/rushed extraction into a queued job risks violating — needs its own plan and sign-off.
- **SCALE-3 — Full-history analytics aggregation:** L-effort item touching the core scoring pipeline (`ComputeContentPopularityScores` + `ActionScorer`) shared by every active site.
- **SCALE-4 — ShopBrandConnectJob timeout/inline fill:** P1, and the job's compare-and-set settle logic and lock timing are intricate enough (see the class's own extensive locking commentary) that this needs isolated review, not a bundled pass.
- **SCALE-13 — Migration timeout consistency:** touches DB migration convention — always standalone per policy.
- **SCALE-5 — LinkInBioImporter per-host delay:** small, self-contained, no natural bundle partner.
- **SCALE-16 — RUM beacon synchronous log:** small, self-contained, no natural bundle partner.
