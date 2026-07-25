# Database & Queue Scaling Audit — 2026-07-10

**Branch:** audit-fix/analytics-master-2026-07-10
**Lens:** Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Console/Commands/ComputeContentPopularityScores.php
- app/Http/Resources/Content/ContentLibraryUploadResource.php
- app/Http/Resources/SiteResource.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Design/AnalyzeConnectionWebsitesJob.php
- app/Models/Core/Site/Site.php
- app/Models/Core/Site/SiteMedia.php
- app/Services/Analytics/AnalyticsQueryService.php
- app/Services/Analytics/Writers/PostgresEventWriter.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- config/horizon.php
- routes/console.php
- supabase/migrations/20260526000000_baseline_standalone_user.sql
- supabase/migrations/20260527030000_rename_professional_to_user.sql
- supabase/migrations/20260527050000_rename_professional_constraints_indexes.sql
- supabase/migrations/20260610000000_analytics_v2_clicks_sessions.sql
- supabase/migrations/20260610000001_analytics_v2_click_indexes.sql
- supabase/migrations/20260709042716_create_content_popularity_scores.sql
- supabase/migrations/20260709042911_create_item_views.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **SCALE-1** · P2 — `analytics:compute-popularity` full-sweeps every published site every 15 minutes
    - **Where:** routes/console.php:74-79, app/Console/Commands/ComputeContentPopularityScores.php:124
    - **Affects:** Popularity-score freshness for all published sites; DB query volume on the `analytics.*` tables every 15 minutes; scheduler headroom as site count grows.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Scope the 15-minute run to sites with events in a recent lookback window (e.g. join against `MAX(occurred_at)` across `section_views`/`link_clicks`/`item_views` in the last 2h) so idle sites are skipped.
        - Keep a slower full daily sweep (the original cadence) to catch long-tail freshness/seed decay for sites with no new events.
        - Revisit `BLEND_NEW`/`BLEND_PREV`/`HALF_LIFE_DAYS`, which the file's own docblock says were tuned for a daily cadence — at 15-minute intervals the 0.7/0.3 blend barely smooths between runs.
    - **Technical:** Commit `457e1dd2` moved this from `->dailyAt('02:40')` to `->everyFifteenMinutes()` for dev validation of the ONE theme, and the code comment directly above the schedule entry already flags it as a pre-prod-scale revisit item. The command does use `chunkById(self::SITE_CHUNK)` (200/batch), so it is not at OOM risk — the real cost is redundant work: `computeForSite()` issues roughly 5–10 queries per site (2 for page aggregation, 2 for item aggregation, plus one `previous`-scores lookup per content_type touched), all re-run for every published site every 15 minutes regardless of whether that site had any traffic. At a few thousand published sites this is tens of thousands of avoidable queries per tick, and the `withoutOverlapping(14)` lock leaves only a 1-minute margin before the next tick fires — a slow run has almost no headroom.
    - **Plain English:** Every 15 minutes, the system recalculates "what's popular" for literally every professional's page — even the ones nobody visited since the last check. As the number of professionals grows, this wastes more and more database effort re-checking pages with nothing new to report, and could eventually make the calculator run out of time before the next check starts. We should only recheck pages that actually got new visits.
    - **Evidence:**
        ```php
        // CADENCE (2026-07-09): every 15 min while validating the ONE theme, so page +
        // item scores reflect real browsing without a manual trigger. ⚠️ REVISIT before
        // real prod scale — this full-sweeps EVERY published site each run (wasteful at
        // scale; should scope to sites with recent events), and the 0.7/0.3 hysteresis
        // blend + 90-day half-life were tuned for a DAILY cadence (at 15-min the blend
        // barely smooths). Was: ->dailyAt('02:40'). The daily 03:00 purge still bounds
        // the retained window this reads.
        Schedule::command('analytics:compute-popularity')
            ->everyFifteenMinutes()
            ->onOneServer()
            ->withoutOverlapping(14) // 14min lock (< 15min cadence): releases immediately on a normal run; a stuck run's lock clears before the next tick.
            ->runInBackground()
            ->onFailure($reportScheduledFailure('compute-popularity'));
        ```
        ```php
        $query = Site::query()->where('is_published', true)->with('user');
        ```

- [ ] **SCALE-2** · P2 — Analytics ingest has no batching at any layer — every event (pageview, click, session-ping, dwell alike) is one queued job and one DB round-trip
    - **Where:** app/Services/Analytics/Writers/PostgresEventWriter.php:28-31,75-82; app/Jobs/Analytics/RecordAnalyticsEventJob.php:40-44
    - **Affects:** Analytics dashboard write throughput during a traffic spike on a single professional's page — every visitor's pageview/click/25s session-ping/dwell beacon becomes its own `analytics`-queue job and its own Postgres statement.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Confirm this is an accepted trade-off for pilot (the `supervisor-analytics` comment in `config/horizon.php` already caps it at `maxProcesses => 2` deliberately, "to prevent analytics backlogs from starving critical queues" — i.e. the team already chose to let this queue lag under load rather than steal capacity). If that's still the intent, no urgent action is needed before pilot.
        - If viral-spike freshness of the dashboard matters sooner, the real fix is the "future BufferedIngestor" already referenced in `PostgresEventWriter`'s own comments — batch N events per flush (Redis-buffered, periodic drain) rather than one job per event — not a narrower fix inside `writeMany()`, since `writeMany()` is currently never called with more than one event.
        - A cheaper interim lever: increase the session-ping interval (currently 25s) or raise `AnalyticsDedupGuard`'s window to cut event volume directly, before investing in a buffering layer.
    - **Technical:** `PostgresEventWriter::writeMany()` does batch `SiteVisit`/`LinkClick`/`SectionView`/`ItemView` rows into arrays before calling `insertOrIgnore()` — but every ingest path (`RecordAnalyticsEventJob::handle()` and `SyncIngestor`) calls `$writer->write($event)`, which is `writeMany([$event])` — a single-element array, always. There is no code path today that calls `writeMany()` with more than one event, so the "batched" tables get zero real batching benefit in production either; the entire pipeline is one-event-per-job by design, not just for `TYPE_SESSION_PING`/`TYPE_SECTION_DWELL`. At a viral spike (10K concurrent visitors × 25s heartbeat ≈ 400 pings/sec, plus a comparable dwell-annotation rate), this is low-thousands of individual Postgres statements/sec funneled through a queue that Horizon deliberately caps at 2 processes in production. The consequence is queue backlog and delayed analytics writes, not a broken public sitepage (sitepage resolution is edge-cached and does not depend on ingest completing) — so this is a dashboard-freshness/throughput concern, not a correctness or availability one.
    - **Plain English:** Every single visitor action on a page — arriving, clicking a link, staying on a section — gets logged one action at a time, like a cashier ringing up one item, walking to the stockroom, and coming back before ringing up the next. If one professional's page suddenly goes viral, thousands of these single trips pile up. Right now the system is deliberately built to let that pile-up happen slowly rather than let it slow down more important things (like sending emails) — which is a reasonable choice for now, but worth revisiting once real traffic spikes start happening.
    - **Evidence:**
        ```php
        public function write(AnalyticsEvent $event): void
        {
            $this->writeMany([$event]);
        }
        ```
        ```php
        public function handle(AnalyticsEventWriter $writer, AnalyticsCacheService $cache): void
        {
            $event = AnalyticsEvent::fromArray($this->payload);

            $writer->write($event);
        ```
        ```php
        foreach ($sessionEvents as $event) {
            $this->upsertSession($event);
        }
        // Dwell AFTER section inserts so a same-batch impression row exists
        // before its dwell annotation looks for it.
        foreach ($dwellEvents as $event) {
            $this->applySectionDwell($event);
        }
        ```

- [ ] **SCALE-3** · P2 — Cloudflare purge abandons remaining URL batches when one batch fails
    - **Where:** app/Services/Cloudflare/CloudflarePurgeService.php:65-71
    - **Affects:** Every profile-edit / image-upload / design-kit-resolve cache purge — `purgeHandle()` now routinely emits 30+ URLs (root + 15 deep-link sub-pages + their SWR shadows + the API subrequest, per host), so the 30-URL chunk boundary is crossed on nearly every purge, not just custom-domain edge cases.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Collect per-chunk failures instead of letting `->throw()` abort the loop; continue sending remaining chunks even if one fails.
        - Retry failed chunks individually with a short backoff before giving up.
        - Log structured chunk-failure counts so a sustained partial-purge rate is visible (Nightwatch can alert on the exception if the retry also exhausts).
    - **Technical:** Commit `877096c4` ("purge deep-link sub-pages + shadows, not just the profile root") widened `purgeHandle()`'s URL set specifically to fix stale sub-pages — a single base domain alone now produces enough URLs to exceed the 30-URL Cloudflare batch limit, so `array_chunk(..., 30)` yields ≥2 chunks on essentially every purge. `purgeUrls()`'s `->throw()` on the first failed chunk propagates out of the loop, abandoning every chunk after it. A transient Cloudflare 429/5xx on chunk 2 leaves `/shop`, `/book`, and the remaining deep-link sub-pages serving pre-mutation HTML until the next content change triggers another purge — which, per the file's own docblock, was previously observed to leave stale content for up to 24h.
    - **Plain English:** When a professional edits their page, we tell Cloudflare to clear out the old cached version — but we have to ask in batches of 30 web addresses at a time because Cloudflare won't take more in one request. If Cloudflare has a hiccup partway through and rejects the second batch, we just give up on the rest. That means some parts of their page (like the shop or booking section) can keep showing old content until they make another edit. We should keep going and retry just the batch that failed.
    - **Evidence:**
        ```php
        foreach (array_chunk(array_values($urls), 30) as $chunk) {
            Http::withToken($this->apiToken)
                ->asJson()
                ->acceptJson()
                ->post($this->url(), ['files' => $chunk])
                ->throw();
        }
        ```

## P3 — Nice to have

- [ ] **SCALE-4** · P3 — `SiteResource::designKitVars()` issues a raw query per resource, an N+1 trap if the resource is ever collected
    - **Where:** app/Http/Resources/SiteResource.php:65, app/Models/Core/Site/Site.php:179-201
    - **Affects:** No live endpoint today (`SiteResource` is only ever constructed as `new SiteResource($site)` — six single-instance call sites, no `SiteResource::collection()` usage anywhere in the codebase). The risk is purely latent.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If a future staff/dashboard multi-site list endpoint is added, eager-load `site.design_kits` in bulk (a query scope or a join) before mapping to `SiteResource` rather than calling `designKitVars()` per row.
        - Cheaper guard in the meantime: assert in `SiteResource` (or a test) that it's never used via `::collection()`, so the trap can't be introduced silently.
    - **Technical:** `Site::designKitVars()` runs `DB::connection('pgsql')->table('site.design_kits')->where('site_id', $this->id)->first()` unconditionally on every call — there is no Eloquent relation or caching layer backing it (by design, per the file's own comment: writes go through a raw builder in `UserSiteController::writeDesignKit`, and this mirrors that). Today's 6 call sites are all single-`Site` responses, so this costs exactly one query per request. If a future endpoint calls `SiteResource::collection($sites)`, this becomes N extra queries with no code change needed to trigger it — a silent regression rather than an obvious bug.
    - **Plain English:** Each site's visual-style settings live in their own little box, separate from the main site record. Right now we only ever open one box at a time because we only ever show one site's info per request. But nothing stops a future "show me all my sites" feature from accidentally opening a separate box for every single site it lists — worth a small guardrail now so that mistake can't happen quietly later.
    - **Evidence:**
        ```php
        'design_kit' => (object) $this->resource->designKitVars(),
        ```
        ```php
        public function designKitVars(): array
        {
            try {
                $row = DB::connection('pgsql')
                    ->table('site.design_kits')
                    ->where('site_id', $this->id)
                    ->first();
        ```

- [ ] **SCALE-5** · P3 — `AnalyzeConnectionWebsitesJob` re-queries `shopBrands()->get()` per connection instead of eager-loading
    - **Where:** app/Jobs/Design/AnalyzeConnectionWebsitesJob.php:103, 189, 254
    - **Affects:** Users with more than one shop-platform connection, during design-analysis runs — extra per-connection queries. Bounded by a per-user connection count that stays small (a handful of platforms) and by `MAX_ANALYSES_PER_RUN = 2`, which already caps work per run.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->with('shopBrands')` to the `IntegrationConnection::query()` calls in `handle()` and in `failed()`'s requery, so `connectionNeedsAnalyses()`/`brandNeedsAnalysis()` iterate an eager-loaded collection instead of re-querying.
        - Replace the inner `$connection->shopBrands()->get()` calls with the loaded `$connection->shopBrands` relation.
    - **Technical:** Three call sites each issue `$connection->shopBrands()->get()` independently: the static `connectionNeedsAnalyses()` helper (line 103, called both from `handle()`'s self-continue check and `failed()`'s kill-recovery check), and the main processing loop in `handle()` (line 189). For a user with N shop connections this is roughly 2N queries in the normal path (main loop + self-continue check) and up to 3N when `failed()` also runs. This is a real inefficiency but not a scaling risk today — per-user connection counts on this individual-sitepage platform stay small, and the job's own `MAX_ANALYSES_PER_RUN = 2` budget already bounds the work done per invocation.
    - **Plain English:** When the design engine checks a user's connected shops for outdated style analysis, it asks the database for that connection's list of shops separately, more than once, instead of asking once and reusing the answer. Because each user only has a few connections, this wastes a small, not large, amount of database effort — worth tidying up when convenient, not urgent.
    - **Evidence:**
        ```php
        return $connection->shopBrands()->get()->contains(
            fn (ShopBrand $b) => self::brandNeedsAnalysis($b),
        );
        ```
        ```php
        foreach ($connection->shopBrands()->get() as $brand) {
        ```
        ```php
        ->contains(fn (IntegrationConnection $c) => self::connectionNeedsAnalyses($c));
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Analytics ingest & scoring throughput hardening:** SCALE-1, SCALE-2
    - **Why grouped:** Both are the same root pattern (analytics subsystem doing more per-tick/per-event DB work than it needs to at scale) and both are policy/tuning decisions rather than urgent bugs — sensible to review and decide together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Cloudflare purge partial-failure resilience:** SCALE-3
    - **Why grouped:** Single-file, self-contained fix; kept as its own session since it needs care around retry/backoff design rather than being tacked onto an unrelated bundle.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Latent N+1 hardening:** SCALE-4, SCALE-5
    - **Why grouped:** Same root-cause pattern (missing eager-load, currently harmless but a trap for a future collection/list use) across two different files — a natural low-effort pairing.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.
