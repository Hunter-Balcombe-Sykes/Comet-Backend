# Database & Queue Scaling Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Console/Commands/BackfillWebsiteAnalysesCommand.php
- app/Console/Commands/ComputeContentPopularityScores.php
- app/Console/Commands/PruneNotifications.php
- app/Console/Commands/PurgeRawAnalyticsEvents.php
- app/Console/Commands/ResolveAllDesignPresetsCommand.php
- app/Console/Commands/BackfillMediaPaletteCommand.php
- app/Http/Resources/SiteResource.php
- app/Http/Resources/Staff/StaffUserListResource.php
- app/Models/Core/Site/Site.php
- app/Models/Core/Site/ShopBrand.php
- app/Models/Core/Site/IntegrationConnection.php
- app/Jobs/Design/AnalyzeConnectionWebsitesJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Services/Notifications/NotificationPublisher.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php
- app/Http/Controllers/Api/Platforms/ShopController.php
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- routes/console.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **SCALE-1** · P2 — `PruneNotifications` issues one unbounded `DELETE` per run instead of batching like its sibling purge command
    - **Where:** app/Console/Commands/PruneNotifications.php:36
    - **Affects:** The `notifications.notifications` table (and cascaded `notification_receipts` rows) on every professional. Runs daily at 03:25 automatically — not an opt-in operator action.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Batch the delete the same way `PurgeRawAnalyticsEvents::purgeBatched()` already does: loop `->limit($batchSize)->delete()` until a partial batch is returned.
        - Pull the batch size from `config('partna.analytics.purge_batch_size', 10_000)` or a dedicated notifications-purge config key so both purge commands share one convention.
    - **Technical:** `routes/console.php`'s schedule comment for this command claims the job is "bounded by retention-window batch size," but `PruneNotifications::handle()` has no batching at all — it runs a single `$q->delete()` over every non-critical notification whose `ends_at` is more than 30 days old. `PurgeRawAnalyticsEvents` (same file family, same cadence style) deliberately batches with a `do…while` loop specifically to avoid long-running transactions on hot tables. As notification volume grows with the user base, this single statement's row count grows unbounded, and the table's `ON DELETE CASCADE` to `notification_receipts` amplifies the same statement's lock/I/O footprint. A slow run risks lengthening the daily maintenance window and holding table locks longer than intended.
    - **Plain English:** Every night the system cleans out old notifications people have already seen. Right now it does this cleanup in one giant sweep instead of small batches — like emptying an entire recycling bin into the truck in one heave instead of a few manageable bags. As the number of professionals grows, that one heave gets heavier and could jam things up. The system already knows how to do this safely in small batches for a similar cleanup job elsewhere — this one should copy that pattern.
    - **Evidence:**
        ```php
        Schedule::command('partna:prune-notifications', ['--days' => 30])
            ->dailyAt('03:25')
            ->onOneServer()
            ->withoutOverlapping(120) // 2h lock — bounded by retention-window batch size.
        ```
        ```php
        $deleted = $q->delete(); // relies ON DELETE CASCADE to remove receipts
        ```

- [ ] **SCALE-2** · P2 — Unbounded `->get()` in `BackfillWebsiteAnalysesCommand` when `--retry-failures` is used
    - **Where:** app/Console/Commands/BackfillWebsiteAnalysesCommand.php:78
    - **Affects:** Operators running `design:backfill-website-analyses --retry-failures`. At scale (thousands of active shop/custom connections), this loads every matching row into memory at once before looping.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `foreach ($outsideConnections()->get() as $connection)` with `$outsideConnections()->chunk(200, function ($chunk) { ... })` or `->cursor()`.
    - **Technical:** `$outsideConnections()->get()` materialises the full matching `IntegrationConnection` result set into memory before the mutate-and-update loop runs. This is operator-invoked (not scheduled), so today's blast radius is low, but as the connection table grows into the tens of thousands the command risks memory pressure and a long-running, deploy-blocking foreground process. The fix is a one-line swap to `chunk()`/`cursor()` — no behavior change, since each row is already updated independently.
    - **Plain English:** When an engineer runs the repair tool with the "retry failures" switch, it grabs every single matching record into memory at once — like emptying an entire filing cabinet onto a desk before reading each paper. As the user base grows, that desk gets too crowded. The fix is to process records in small stacks instead of all at once.
    - **Evidence:**
        ```php
        if ($this->option('retry-failures')) {
            foreach ($outsideConnections()->get() as $connection) {
                $payload = is_array($connection->payload) ? $connection->payload : [];
                $changed = false;
                if ($connection->platform === Platform::Custom->value) {
                    if ($this->stripFailedAnalysis($payload)) {
                        $changed = true;
                    }
                } else {
                    foreach ($payload as $key => $brand) {
                        if (is_array($brand) && $this->stripFailedAnalysis($payload[$key])) {
                            $changed = true;
                        }
                    }
                }
                if ($changed) {
                    IntegrationConnection::withoutEvents(fn () => $connection->update(['payload' => $payload]));
                }
            }
        }
        ```

- [ ] **SCALE-3** · P2 — `analytics:compute-popularity` full-sweeps every published site every 15 minutes
    - **Where:** routes/console.php:73-88, app/Console/Commands/ComputeContentPopularityScores.php:148-157
    - **Affects:** Scheduler runtime and Postgres load, automatically every 15 minutes. The command re-computes popularity for EVERY published site each tick regardless of whether it received any new analytics events.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Scope the sweep to sites with events since the last run (e.g. a `last_popularity_computed_at` timestamp on `site.sites`, or a `MAX(occurred_at)`-since-cutoff filter joined against the raw event tables before `chunkById`).
        - Alternatively, move back toward a coarser cadence for the full sweep and dispatch a lighter per-site queue job for sites that just received a live event, so the 15-minute tick isn't paying for idle sites.
    - **Technical:** The scheduler entry's own comment (added 2026-07-09, still present and unresolved) flags this directly: the cadence was dropped from daily to every-15-minutes for ONE-theme validation, but the command still iterates every `is_published = true` site via `chunkById(200, ...)` on every tick, and the 0.7/0.3 blend + 90-day half-life were tuned assuming a daily run, not 96 runs/day. `withoutOverlapping(14)` prevents concurrent runs, but as the published-site count grows, a run that creeps past 14 minutes causes the scheduler to skip ticks, and the blend's math degrades further at higher cadence (per the comment: "at 15-min the blend barely smooths"). This is a known, self-documented gap, not a hypothetical.
    - **Plain English:** Every 15 minutes the system recalculates a "how popular is this page" score for every single professional's page — even pages nobody has visited in weeks. Imagine repainting every piece of gym equipment every 15 minutes whether it's been used or not; that's a lot of wasted effort. The fix is to only redo the score for equipment (pages) that actually got used since the last check.
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
        ```
        ```php
        $query = Site::query()->where('is_published', true)->with('user');
        ```

## P3 — Nice to have

- [ ] **SCALE-4** · P3 — `SiteResource::designKitVars()` issues a raw query per resource, a latent N+1 trap if the resource is ever collected
    - **Where:** app/Http/Resources/SiteResource.php:65, app/Models/Core/Site/Site.php:178-200
    - **Affects:** No live endpoint today — verified `SiteResource` is only ever constructed as `new SiteResource($site)` (six single-instance call sites across `UserSiteController`, `SiteVisibilityController`, `StaffSiteManagementController`, `UserSelfController`); no `SiteResource::collection()` usage exists anywhere in the codebase. The risk is purely latent.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If a future staff/dashboard multi-site list endpoint is added, eager-load `site.design_kits` in bulk before mapping to `SiteResource` rather than calling `designKitVars()` per row.
        - Cheaper guard in the meantime: add a test asserting `SiteResource` is never used via `::collection()`, so the trap can't be introduced silently later.
    - **Technical:** `Site::designKitVars()` runs `DB::connection('pgsql')->table('site.design_kits')->where('site_id', $this->id)->first()` unconditionally — there's no Eloquent relation or caching layer backing it (by design, mirroring the raw-builder write path in `UserSiteController::writeDesignKit`). Every current call site constructs exactly one `SiteResource`, so today's cost is exactly one query per request. Nothing currently prevents a future `SiteResource::collection($sites)` call from silently introducing N extra queries with zero code change to the resource itself.
    - **Plain English:** Each site's visual-style settings live in a separate little box from the main site record. Right now we only ever open one box at a time because we only ever show one site's info per request. Nothing stops a future "show me all my sites" feature from accidentally opening a separate box for every site it lists — worth a small guardrail now so that mistake can't happen quietly later.
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

- [ ] **SCALE-5** · P3 — `AnalyzeConnectionWebsitesJob` re-queries `shopBrands()->get()` per connection at three separate call sites instead of eager-loading
    - **Where:** app/Jobs/Design/AnalyzeConnectionWebsitesJob.php:103, 189, 249-254
    - **Affects:** Users with more than one shop-platform connection, during design-analysis job runs (main `handle()` loop, its self-continue check, and the `failed()` kill-recovery re-dispatch check). Bounded by small per-user connection counts and by `MAX_ANALYSES_PER_RUN = 2`, which already caps work per invocation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->with('shopBrands')` to the `IntegrationConnection::query()` calls in both `handle()` and `failed()`.
        - Replace the `$connection->shopBrands()->get()` calls inside `connectionNeedsAnalyses()` and the main loop with property access (`$connection->shopBrands`) so they reuse the eager-loaded collection.
    - **Technical:** `handle()` fetches its connections with `->get()` but never eager-loads `shopBrands`. The main loop then calls `$connection->shopBrands()->get()` once per shop connection (line 189), and the static `connectionNeedsAnalyses()` helper — invoked both from `handle()`'s self-continue check (line 220) and `failed()`'s kill-recovery check (line 254) — independently calls `$connection->shopBrands()->get()` again. For a user with N shop connections this is roughly 2N-3N extra queries per job invocation. Per-user connection counts on this individual-sitepage platform stay small (a handful of platforms), and `MAX_ANALYSES_PER_RUN` already bounds the expensive scraping work, so this is real but low-impact inefficiency, not a scaling risk today.
    - **Plain English:** When the design engine checks a user's connected shops for outdated style analysis, it asks the database for that connection's list of shops separately, more than once, instead of asking once and reusing the answer. Because each user only has a few connections, this wastes a small amount of effort — worth tidying up when convenient, not urgent.
    - **Evidence:**
        ```php
        $connections = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereIn('platform', OutsideWebsitesFactor::SOURCE_PLATFORMS)
            ->get();
        ```
        ```php
        foreach ($connection->shopBrands()->get() as $brand) {
        ```
        ```php
        return $connection->shopBrands()->get()->contains(
            fn (ShopBrand $b) => self::brandNeedsAnalysis($b),
        );
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Scheduled/operator command scale hardening:** SCALE-1, SCALE-2, SCALE-3
    - **Why grouped:** Same root pattern (unbounded or unscoped work in `app/Console/Commands/`, scheduled or operator-invoked) with the same class of fix (batch/chunk/scope).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Design-kit/shop N+1 latent hardening:** SCALE-4, SCALE-5
    - **Why grouped:** Both are latent/low-impact N+1 traps around the design-kit and shop-brand read paths, same fix shape (eager-load or guard test), no urgency.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.
