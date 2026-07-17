# Observability Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Observability: logging gaps, silent failures, missing Nightwatch instrumentation — jobs that swallow exceptions silently, inbound callbacks that 200-but-don't-process, missing Nightwatch coverage, log calls that obscure rather than illuminate
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Console/Commands/ComputeContentPopularityScores.php
- app/Console/Commands/BackfillMediaPaletteCommand.php
- app/Console/Commands/BackfillWebsiteAnalysesCommand.php
- app/Console/Commands/ResolveAllDesignPresetsCommand.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Moderation/Concerns/HasActionLogLifecycle.php
- app/Services/Audit/StaffAuditService.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Media/ImageVariantService.php
- app/Services/Moderation/EvidenceSnapshotService.php
- app/Services/Platforms/FreshaScraper.php
- app/Services/Platforms/ShopCatalog.php
- app/Services/Platforms/AppleSearch.php
- app/Services/Platforms/YoutubeScraper.php
- app/Services/Platforms/WooCommerceScraper.php
- app/Services/Platforms/PlatformRefresher.php
- app/Services/Platforms/Strategies/Fetch/ShopFetch.php
- app/Services/Platforms/Strategies/Fetch/FreshaFetch.php
- app/Services/Platforms/Strategies/Fetch/AppleMusicFetch.php
- app/Services/Platforms/Strategies/Fetch/YoutubeFetch.php
- app/Http/Controllers/Api/Platforms/ShopController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **OBS-1** · P1 — FreshaScraper's per-employee menu falls back to whole-location and reports 'ok' forever; the code's own comment promises Nightwatch visibility it never delivers
    - **Where:** app/Services/Platforms/FreshaScraper.php:203-224
    - **Affects:** Every Fresha connection in per-employee booking mode. A rotated `BOOKING_INIT_HASH`/client version silently and *permanently* downgrades the per-stylist menu to the whole-location menu with zero operator signal.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the `catch (Throwable $e)` block, call `report($e)` in addition to the existing `Log::warning` so a transport-level failure reaches Nightwatch.
        - On the non-2xx branch (`! $response->ok()`), call `report(new \RuntimeException('fresha.employee_services.http_error: '.$response->status()))` so a persisted hash/version rotation is distinguishable from a one-off blip.
        - Do not rely on `PlatformRefresher`'s circuit breaker here — it can't see this failure (see Technical).
    - **Technical:** `fetchEmployeeServices` catches `Throwable` and non-2xx responses and returns `null` with only `Log::warning` calls — the comment directly above the catch block explicitly states the intent ("Surface silent failures so a rotated BOOKING_INIT_HASH/client version is visible in Nightwatch"), but `Log::warning` is a breadcrumb, not a Nightwatch signal (per the canonical alert model, only exceptions/`report()`/auto-detected slow paths page). Worse, this failure is *not* caught by the platform's existing circuit breaker: in `FreshaFetch::fetch()` (app/Services/Platforms/Strategies/Fetch/FreshaFetch.php:43-53), a `null` return from `fetchEmployeeServices` triggers a fallback to `fetchLocation()`/`extractServices()` (the whole-location scrape), which typically succeeds since it's a more stable public page. Because `$services` ends up non-empty, `FetchUnavailableException` is never thrown, so `PlatformRefresher::recordFailure()` (app/Services/Platforms/PlatformRefresher.php:60-61, 88-109) never increments `consecutive_failures` and the connection is recorded as `last_refresh_status = 'ok'` on every subsequent refresh. Since the code comment itself calls a hash rotation "the documented rotation inevitability," this WILL happen, and once it does the connection will silently and permanently serve a coarser menu while reporting healthy — there is no path (Nightwatch or user-facing) by which anyone learns of it.
    - **Plain English:** Fresha (the booking platform) occasionally changes an internal security code your Fresha integration depends on — the code even admits this is inevitable. When it happens, the system quietly switches from showing a stylist's own specific services to showing the whole shop's full menu instead, and marks the connection as "all good" forever after. Nobody — not the engineering team, not the business owner — ever finds out unless a customer complains that their booking page looks wrong.
    - **Evidence:**
        ```php
        try {
            $response = Http::withHeaders([
                // ...
            ])->timeout(12)->post(self::GRAPHQL_URL, $payload);
        } catch (Throwable $e) {
            // Surface silent failures so a rotated BOOKING_INIT_HASH/client version
            // is visible in Nightwatch instead of silently degrading to the
            // whole-location menu (the documented rotation inevitability).
            Log::warning('fresha.employee_services.failed', [
                'reason' => 'exception',
                'slug' => $slug,
                'employee_id' => $employeeId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
        ```
    - `[Adjudicated: elevated from vendor-services-1 DeepSeek draft OBS-1 (P1, confidence 0.9); tier confirmed after tracing FreshaFetch/PlatformRefresher interaction]`

- [ ] **OBS-2** · P1 — ShopCatalog::syncLatest swallows fetch failures as "nothing changed," resetting the failure counter and defeating the platform's own circuit breaker
    - **Where:** app/Services/Platforms/ShopCatalog.php:77-83
    - **Affects:** Every shop brand with "auto-latest" enabled. A persistently-blocking store (bot detection, dead URL) never surfaces as a failure — the scheduled sync silently no-ops forever, and the "the scheduled refresh retries" promise made to the user in `ShopController` is never honoured with a retry that actually succeeds or an alert that it can't.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `syncLatest`, log the swallowed `HttpException` with structured context (`brand_id`, `url`, exception message) instead of a bare `return null`.
        - In `ShopFetch::fetch()` (app/Services/Platforms/Strategies/Fetch/ShopFetch.php:57-61), distinguish "every brand's `syncLatest` failed" from "nothing to sync": throw `FetchUnavailableException` on the former so `PlatformRefresher::recordFailure()` actually increments `consecutive_failures` and the existing `PlatformHealthNotifier` circuit breaker can trip, rather than the current `FetchNotModifiedException` which routes through `recordNotModified()` and *resets* `consecutive_failures` to 0 on every failed cycle.
    - **Technical:** `syncLatest()` catches `HttpException` from `providerProducts()` and returns `null` with no log call. The caller, `ShopFetch::fetch()`, treats every `syncLatest() === null` brand as merely "not synced this round" and — when *all* brands in the batch return null — throws `FetchNotModifiedException('shop')` (line 60), not `FetchUnavailableException`. `PlatformRefresher::refresh()` (app/Services/Platforms/PlatformRefresher.php:50-51) routes `FetchNotModifiedException` to `recordNotModified()`, which sets `last_refresh_status = 'ok'` and explicitly `consecutive_failures = 0` — the exact opposite of what should happen on a fetch failure. This is a genuine bug, not just a missing log: it actively erases the failure signal the codebase's own circuit-breaker design (documented in `PlatformRefresher`'s class comment) depends on, so `PlatformHealthNotifier::connectionRefreshFailing()` can never trip for a permanently-blocked store. Compounding this, `ShopController::setProducts()` (app/Http/Controllers/Api/Platforms/ShopController.php:242-245) tells the dashboard "the scheduled refresh retries" when a manual sync fails — a promise this bug silently breaks, since the retries never accumulate a distinguishable failure state.
    - **Plain English:** When a shop's automatic "always show the newest products" feature can't reach the store (the store is blocking automated requests, for example), the system currently records this exactly the same as "nothing new to update" — a perfectly healthy outcome. That's like a delivery service marking a package "delivered" every time the customer refuses to answer the door. The shop's products silently freeze in place, the owner is told a retry is happening, but nothing ever actually recovers or gets flagged.
    - **Evidence:**
        ```php
        public function syncLatest(ShopBrand $brand): ?int
        {
            try {
                $catalog = $this->providerProducts($brand->toBrandArray());
            } catch (HttpException) {
                return null;
            }
        ```
        ```php
        // ShopFetch::fetch()
        if ($synced === 0) {
            // Every latest-mode store was unreachable this cycle — selections
            // untouched, nothing to publish.
            throw new FetchNotModifiedException('shop');
        }
        ```
    - `[Adjudicated: vendor-services-1 DeepSeek draft OBS-2 (P2, confidence 0.85) elevated to P1 after discovering the recordNotModified/consecutive_failures interaction — a cross-file invariant DeepSeek missed]`

## P2 — Should fix

- [ ] **OBS-3** · P2 — Ranked-actions computation failure in the popularity-score command is caught and only logged, never reported to Nightwatch
    - **Where:** app/Console/Commands/ComputeContentPopularityScores.php:275-286
    - **Affects:** The derived "ranked actions" ordering layer used by the dashboard — a broken computation goes stale with no alert, though core page/item scores are unaffected (the fail-open is intentional and correct there).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` alongside the existing `Log::warning` call so a sustained failure of the ranked-actions layer reaches Nightwatch.
        - Keep the fail-open behavior (never let this exception break page/item score writes) — only the alerting is missing.
    - **Technical:** `computeForSite()` deliberately wraps `computeActions()` in a `try/catch (\Throwable $e)` so an action-layer fault degrades to "no rankedActions refresh" rather than corrupting the (unrelated) page/item score writes — this fail-open design is correct and should stay. The gap is purely instrumentation: the catch block calls only `Log::warning`, which is a breadcrumb (per the canonical Nightwatch alert model, only exceptions/`report()` trigger paging), so a sustained bug in `RankedActionsComputer` across every site's nightly run produces zero operator-facing signal.
    - **Plain English:** This command recomputes which actions ("Book now," "Shop," etc.) should be highlighted on a professional's page, ranked by popularity. If that specific calculation breaks, the rest of the analytics job keeps working fine, but the ranking freezes and nobody on the team is told — it just quietly stops updating.
    - **Evidence:**
        ```php
        try {
            $actionResult = $this->computeActions($site, $rows);
            $rows = array_merge($rows, $actionResult['rows']);
            if ($actionResult['deletes'] !== []) {
                $deletes[RankedActionsComputer::CONTENT_TYPE] = $actionResult['deletes'];
            }
        } catch (\Throwable $e) {
            Log::warning('analytics.ranked_actions_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }
        ```
    - `[Adjudicated: jobs-hooks DeepSeek draft OBS-1 (P1, confidence 0.9); re-tiered P2 — fail-open is intentional/documented and scoped to a secondary derived layer, not routing/auth/irreversible-work]`

- [ ] **OBS-4** · P2 — ImageVariantService::deleteVariants logs storage-delete failures with good structured context but never escalates to Nightwatch
    - **Where:** app/Services/Media/ImageVariantService.php:345-386
    - **Affects:** Media cleanup on delete/reprocess — a sustained R2/S3 delete failure accumulates orphaned storage objects indefinitely with no operator alert (DB rows are correctly cleared regardless).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - When `$failures !== []`, in addition to the existing `Log::error`, call `report(new \RuntimeException("Image variant storage delete failed for {$imageId}: ".count($failures).' file(s)'));` so a systemic storage outage reaches Nightwatch rather than only Cloud logs.
    - **Technical:** The method already does the right thing for correctness — DB rows are cleared even when storage deletes fail, and failures are logged with good structured context (`image_id`, `failure_count`, `failures`). The only gap is that `Log::error` alone doesn't page anyone; per the canonical alert model, Nightwatch reacts to exceptions/`report()`, not log severity. A sustained storage-provider outage (all deletes failing) would silently leak orphaned objects with zero operator visibility until someone notices the storage bill.
    - **Plain English:** When old profile images are deleted, the system tries to remove the actual files from storage too. If that file-removal keeps failing (say, the storage provider is having issues), the system correctly doesn't block the user — but it also never tells anyone the trash isn't actually being taken out, so orphaned files quietly pile up.
    - **Evidence:**
        ```php
        if ($failures !== []) {
            Log::error('ImageVariantService::deleteVariants: storage delete failures; DB rows cleared, orphans may remain.', [
                'image_id' => $imageId,
                'failure_count' => count($failures),
                'failures' => array_slice($failures, 0, 20),
            ]);
        }
        ```
    - `[Adjudicated: vendor-services-1 DeepSeek draft OBS-4 (P2, confidence 0.7); confirmed verbatim, tier retained]`

- [ ] **OBS-5** · P2 — Multiple long-running artisan commands lack a `$timeout` property, so a hung run is invisible to Nightwatch's slow-command detection
    - **Where:** app/Console/Commands/ComputeContentPopularityScores.php, app/Console/Commands/BackfillMediaPaletteCommand.php, app/Console/Commands/ResolveAllDesignPresetsCommand.php
    - **Affects:** Nightwatch operators — none of these commands declare a `$timeout`, so Nightwatch's auto-slow-detection has no baseline to compare against; a hung DB query or stuck GD palette extraction blocks a scheduler slot silently.
    - **Effort:** S (~1h)
    - **What to do:**
        - Add a `protected $timeout` (or the equivalent Nightwatch-recognized property) to each of the three commands reflecting realistic worst-case runtime (e.g. a per-site/per-row budget × expected row count).
        - Confirmed excluded from this finding: `BackfillWebsiteAnalysesCommand` — its `handle()` only chunks a query and dispatches `AnalyzePreviousWebsiteJob`/analysis jobs, doing no heavy synchronous work itself, so it isn't a slow-command risk in the same way.
    - **Technical:** `ComputeContentPopularityScores` does chunked-but-synchronous per-site aggregation across `analytics.section_views`/`link_clicks`/`item_views` for every published site; `BackfillMediaPaletteCommand` synchronously runs GD palette extraction per image; `ResolveAllDesignPresetsCommand` synchronously re-resolves `DesignPresetResolver` per site with an active connection. None declare `$timeout`, so Nightwatch's auto-detected "slow command" alerting (which compares actual runtime against a declared baseline) has nothing to compare against for these three.
    - **Plain English:** These are background maintenance scripts that loop over potentially thousands of rows one at a time. If one gets stuck — a slow database query, a corrupted image file — nobody is told, because the monitoring system doesn't know how long the script *should* take and so can't flag "this is taking way too long."
    - **Evidence:**
        ```php
        // ComputeContentPopularityScores — no $timeout declared anywhere in the class
        class ComputeContentPopularityScores extends Command
        {
            protected $signature = 'analytics:compute-popularity
                                    {--dry-run : Report computed scores without writing}
                                    {--site= : Restrict to a single site id (uuid)}';
            protected $description = 'Recompute content_popularity_scores (pages + scored items) from raw analytics events.';
        ```
    - `[Adjudicated: jobs-hooks DeepSeek draft OBS-4 (P2, confidence 0.8); scope narrowed — BackfillWebsiteAnalysesCommand dropped after confirming it only dispatches jobs and does no heavy synchronous work]`

- [ ] **OBS-6** · P2 — GoogleBusinessEnrichJob's soft-failure branch marks the connection 'unavailable' with zero logging
    - **Where:** app/Jobs/Platforms/GoogleBusinessEnrichJob.php:111-118
    - **Affects:** Users whose Google Business enrichment fails without an exception (Apify returned nothing AND the website harvest found nothing) — the core Place Details card still renders, but repeated soft failures are invisible to operators.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::warning('google_business.enrich.soft_unavailable', ['user_id' => $this->userId, 'place_id' => $this->placeId]);` before `$this->mark($connection, 'unavailable')` on this branch.
        - Consider incrementing a lightweight failure counter (mirroring `consecutive_failures` on `IntegrationConnection`, already used by the platform's refresh circuit breaker) so a sustained Apify outage is distinguishable from an isolated place with genuinely no enrichable data.
    - **Technical:** This is a normal (non-exception) return path, so it never reaches the job's `failed(Throwable $e)` method — which correctly calls `report($e)` and `Log::error` for genuine exceptions. The soft path — "Apify and the in-house harvest both came back empty" — sets `apify_status = 'unavailable'` via `mark()` with no log call at all. A systemic Apify actor outage would silently mark every new Google Business connection 'unavailable' with Horizon reporting all jobs as successfully completed.
    - **Plain English:** When connecting a Google Business listing, this job tries to fill in extra details (menu, ordering links, etc.) from two sources. If both sources come up empty, the system just marks that part "unavailable" and moves on — which is fine for one bad listing, but if the underlying data source is broken for everyone, nobody on the team is told; it just looks like a string of unlucky listings.
    - **Evidence:**
        ```php
        if ($enrichment === null && $harvest === []) {
            // Soft failure: keep the Place Details payload, just mark the Apify
            // layer 'unavailable' so the dashboard stops polling. No hard fail —
            // the core card is unaffected and a re-connect can retry.
            $this->mark($connection, 'unavailable');

            return;
        }
        ```
    - `[Adjudicated: jobs-hooks DeepSeek draft OBS-2 (P2, confidence 0.85); confirmed verbatim, tier retained]`

- [ ] **OBS-7** · P2 — MenuFetchJob's soft-failure branch marks the menu 'unavailable' with zero logging or health-notifier call
    - **Where:** app/Jobs/Platforms/MenuFetchJob.php:183-189
    - **Affects:** Users whose online-ordering menu fails to scrape from every connected platform — the dashboard shows the menu as unavailable, but a sustained/systemic Apify menu-actor outage produces no operator alert (unlike a thrown exception, which correctly triggers `report()` + `PlatformHealthNotifier::menuScrapeFailed()` via `failed()`).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log a warning with `user_id` and the attempted platforms before the early return: `Log::warning('menu.fetch.all_platforms_empty', ['user_id' => $this->userId]);`
        - Since `failed()` already calls `app(PlatformHealthNotifier::class)->menuScrapeFailed(...)` for thrown exceptions, consider routing this same-outcome-but-not-an-exception branch through the same notifier for consistency, so a sustained empty-scrape streak gets the same user-facing heads-up as a hard failure.
    - **Technical:** `if (array_filter($menus) === [])` is reached when every connected platform's scrape returned nothing, without any platform throwing — a normal return, not an exception, so it never reaches `failed()`. `failed()` correctly instruments genuine exceptions (`report($e)`, structured `Log::error`, and `PlatformHealthNotifier::menuScrapeFailed()`), but this soft-empty branch bypasses all three. `RetryUnavailableMenusCommand` (referenced elsewhere in the codebase) re-dispatches this job for menus in this state, so a persistent block (e.g., a store blocking scrapes) will retry silently and indefinitely without ever notifying the user or an operator.
    - **Plain English:** When none of a user's connected food-ordering platforms (Uber Eats, DoorDash) can be scraped successfully, the menu is marked "unavailable" and a retry job will keep trying later — but if that keeps failing, nobody is ever told, unlike a hard crash which does trigger a notification.
    - **Evidence:**
        ```php
        // Nothing usable from ANY connected platform — keep the last menu, mark
        // unavailable so the dashboard stops polling. A manual refresh retries.
        if (array_filter($menus) === []) {
            $menu->forceFill(['fetch_status' => 'unavailable', 'last_fetched_at' => $now])->save();

            return;
        }
        ```
    - `[Adjudicated: jobs-hooks DeepSeek draft OBS-3 (P2, confidence 0.85); confirmed verbatim, tier retained]`

## Suggested Bundled Sessions

- **Bundle 1 — Platform-scraper silent-degradation fixes:** #OBS-1, #OBS-2, #OBS-6, #OBS-7
    - **Why grouped:** Same root-cause pattern (a fetch/scrape failure returns null/empty and is recorded as a quiet non-alerting status) across the Platforms scraper/job layer; #OBS-1 and #OBS-2 additionally require tracing into `PlatformRefresher`/`ShopFetch`/`FreshaFetch`, so reviewing them together avoids re-deriving that context twice.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet). #OBS-2 touches the shared `PlatformRefresher` failure-classification path used by every platform — escalate implement → Opus for that item specifically, or split it into its own standalone session (see below) if the plan reveals broader blast radius.

- **Bundle 2 — Instrumentation/logging hygiene:** #OBS-3, #OBS-4, #OBS-5
    - **Why grouped:** All three are "add `report()`/`$timeout`, keep existing behavior" changes with no logic changes to the surrounding fail-open design — low-risk, mechanical, and independent of each other.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet); combine plan+impl given the small size of each change.

## Standalone — do NOT bundle

- **#OBS-2 — ShopCatalog::syncLatest defeats the circuit breaker** · standalone: this is a correctness fix to shared failure-classification logic in `PlatformRefresher`/`ShopFetch` (not just a missing log), used by every platform's scheduled refresh — changing how failures are classified needs its own plan + sign-off given the blast radius, and it is the most consequential P1 in this run.
