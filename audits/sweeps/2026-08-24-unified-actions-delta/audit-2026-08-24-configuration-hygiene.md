# Configuration Hygiene Audit — 2026-08-24

**Branch:** development
**Lens:** Configuration Hygiene — `env()` calls outside config, missing `.env.example` keys, feature flags without safe defaults, config values used inconsistently
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- config/checkpoint.php
- config/partna.php
- routes/api.php
- bootstrap/catalog/compiled.php
- app/Ingest/Connectors/FreshaConnector.php
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
- app/Jobs/Platforms/CommerceProbeJob.php
- app/Jobs/Platforms/ShopBrandConnectJob.php
- app/Jobs/Platforms/ShopInitialFillJob.php
- app/Console/Commands/ComputeContentPopularityScores.php
- app/Console/Commands/FixturesCaptureCommand.php
- app/Console/Commands/FixturesVerifyCommand.php
- app/Console/Commands/ResetTestUserCommand.php
- app/Services/Brand/StoreBrandSeeder.php
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
- app/Services/Shop/ShopAutoSelector.php
- app/Services/Shop/ShopContentReader.php
- app/Services/Content/LinkPoolReader.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/Site/UpdateSiteAction.php
- app/Services/Analytics/ActionScorer.php
- app/Services/Analytics/AnalyticsEvent.php
- app/Services/Analytics/ContentFreshness.php
- app/Services/Analytics/ContentPopularityReader.php
- app/Services/Analytics/ItemFamily.php
- app/Services/Profile/SectorTaxonomy.php
- .env.example

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 9 complete

---

## P2 — Should fix

- [ ] **#CFG-1** · P2 — Refresh conditional-request flag defaults to enabled
    - **Where:** config/partna.php:2017-2019; .env.example:354
    - **Affects:** Every platform-refresh fetch strategy using ETag/If-None-Match on a fresh or new environment.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change to `(bool) env('PARTNA_REFRESH_CONDITIONAL_ENABLED', false)`.
        - Change `.env.example:354` from `PARTNA_REFRESH_CONDITIONAL_ENABLED=true` to `PARTNA_REFRESH_CONDITIONAL_ENABLED=false` with a one-line comment that it's opt-in until conditional-request behavior is verified against each upstream.
    - **Technical:** The config file's own comment calls this "a global kill-switch... set false to force full fetches everywhere if an upstream starts mis-answering conditional requests," which is itself an admission the behavior is unproven-safe by default. Both `config/partna.php` and `.env.example` currently ship `true`, so a fresh environment (and the checked-in example file itself) enable an optimization with a known upstream-misbehavior failure mode with zero opt-in. This is the same pattern this codebase already treats as unsafe for capability-gated features (see CFG-2 below) — the fix is symmetric.
    - **Plain English:** This is a shortcut the system uses when talking to outside websites, meant to save time by asking "has anything changed?" instead of re-downloading everything. The code's own notes admit some outside websites answer that question wrong. Right now every brand-new copy of the system starts with the shortcut turned on, so it inherits that risk before anyone has checked it's safe in that environment.
    - **Evidence:**
        ```php
        'conditional' => [
            'enabled' => (bool) env('PARTNA_REFRESH_CONDITIONAL_ENABLED', true),
        ],
        ```

- [ ] **#CFG-2** · P2 — Auto-booking connect flag defaults to enabled everywhere it's read
    - **Where:** config/partna.php:1880-1881; app/Services/Platforms/LinkRouter.php:319; app/Routing/SourceReconciler.php:229; app/Services/Platforms/GoogleBusinessAutoSync.php:345; .env.example:459
    - **Affects:** Every new/staging environment; unclaimed pre-account users whose Instagram/Google Business connect flow discovers a Fresha booking link.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the config default: `'enabled' => (bool) env('PARTNA_AUTO_BOOKING_ENABLED', false)`.
        - Drop the redundant `true` fallback in the three `config('partna.connect.auto_booking.enabled', true)` call sites — read `config('partna.connect.auto_booking.enabled')` (the config array key always exists) so there is exactly one place the default lives, not four.
        - Uncomment `.env.example:459` to `PARTNA_AUTO_BOOKING_ENABLED=false` so it's visible rather than silently defaulting via config.
    - **Technical:** This is one root cause repeated at four call sites: the config file's own `env()` default is `true`, and all three consumers (`LinkRouter::seedBooking()`, `SourceReconciler`, `GoogleBusinessAutoSync::seedBooking()`) redundantly re-supply `true` as their own `config()` fallback — so even if the config default were fixed, three call sites would still silently re-enable it if the config array shape ever changed. This flag gates automatic outbound scraping of a discovered booking-platform page on a user's behalf (the config file's own comment: "Ceiling on outbound salon-page scrapes per day across the whole install... an unbounded outbound request made on a user's say-so is an amplification vector aimed at someone else"). A capability-gated, outbound-fetch-triggering feature defaulting to *enabled* is exactly the failure mode the safe-off doctrine exists to prevent.
    - **Plain English:** When the system spots a booking page linked from someone's Instagram, this switch decides whether to automatically go fetch that page's menu — real network requests to an outside website, on the user's behalf, without them asking. That switch currently defaults to "on" in four different places in the code, so a brand-new environment (or a slip in any one of the four spots) starts making those automatic requests before anyone decided that was wanted.
    - **Evidence:**
        ```php
        // config/partna.php
        'auto_booking' => [
            'enabled' => (bool) env('PARTNA_AUTO_BOOKING_ENABLED', true),

        // app/Services/Platforms/LinkRouter.php
        && (bool) config('partna.connect.auto_booking.enabled', true)

        // app/Routing/SourceReconciler.php
        && (bool) config('partna.connect.auto_booking.enabled', true)

        // app/Services/Platforms/GoogleBusinessAutoSync.php
        && (bool) config('partna.connect.auto_booking.enabled', true)
        ```

- [ ] **#CFG-3** · P2 — Shop-brand connect limit has drifted: `StoreBrandSeeder` caps at 5, `ShopController`/`ConnectStoreFromProductJob` cap at 10
    - **Where:** app/Services/Brand/StoreBrandSeeder.php:53; app/Http/Controllers/Api/Platforms/ShopController.php:105; app/Jobs/Platforms/ConnectStoreFromProductJob.php:56
    - **Affects:** Any user connecting more than 5 shop brands via the link-paste / auto-probe path (`CommerceProbeJob` → `StoreBrandSeeder`), where they hit an undocumented lower ceiling than the dashboard's own advertised limit.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `'limits' => ['shop' => ['max_brands' => (int) env('PARTNA_SHOP_MAX_BRANDS', 10)]]` to `config/partna.php`.
        - Replace all three `private const MAX_BRANDS` declarations (`5` in `StoreBrandSeeder`, `10` in `ShopController`, `10` in `ConnectStoreFromProductJob`) with `config('partna.limits.shop.max_brands')`.
        - Add a regression test asserting the auto-probe path (`StoreBrandSeeder::seed()`) and the manual-connect path (`ShopController::addBrand`) refuse at the same count.
    - **Technical:** These three constants are explicitly documented as duplicates that must "keep in lockstep" (`StoreBrandSeeder`'s own docblock: "Mirrors ShopController::MAX_BRANDS / the legacy ShopBrandSeeder's own copy — keep in lockstep") and `ConnectStoreFromProductJob`'s docblock says the same ("Mirrors ShopController::MAX_BRANDS"). They have not stayed in lockstep: `ShopController::MAX_BRANDS = 10` and `ConnectStoreFromProductJob::MAX_BRANDS = 10` agree, but `StoreBrandSeeder::MAX_BRANDS = 5` does not. A user who has connected 5-9 stores through the auto-detection path (LinkRouter → `CommerceProbeJob` → `StoreBrandSeeder`) will be silently refused a store the manual dashboard flow (`ShopController::addBrand`) would still accept — an inconsistent, undocumented enforcement of what is supposed to be one business rule. This is exactly the "config values used inconsistently" pattern the lens targets, escalated past a style nit because the values have actually diverged.
    - **Plain English:** The app is supposed to let everyone connect up to 10 shops, and that number is written into the code in three different places. Two of the three say "10" — the third one, used only when a shop link is auto-detected instead of manually added, still says "5". Someone who already has 5 shops connected and then pastes a new shop link will be told "no, you're at the limit" even though the dashboard says the limit is 10. It's the same rule written three times, and one copy silently fell out of sync with the other two.
    - **Evidence:**
        ```php
        // app/Services/Brand/StoreBrandSeeder.php
        /**
         * Mirrors ShopController::MAX_BRANDS / the legacy ShopBrandSeeder's own
         * copy — keep in lockstep. The reserved individual-products bucket
         * (is_individual = true) never counts against this.
         */
        private const MAX_BRANDS = 5;

        // app/Http/Controllers/Api/Platforms/ShopController.php
        private const MAX_BRANDS = 10;

        // app/Jobs/Platforms/ConnectStoreFromProductJob.php
        /** Mirrors ShopController::MAX_BRANDS. */
        private const MAX_BRANDS = 10;
        ```

## P3 — Nice to have

- [ ] **#CFG-4** · P3 — DAST self-test canary route reads `env()` directly instead of a config value
    - **Where:** routes/api.php:231
    - **Affects:** Boot-time route registration; only ever exercised by `scripts/dast/tests/dast-selftest.sh`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'dast_canary' => (bool) env('DAST_CANARY', false)` to `config/partna.php`.
        - Replace `env('DAST_CANARY')` in `routes/api.php` with `config('partna.dast_canary')`.
    - **Technical:** Category 1 — `env()` outside `config/` is a real hygiene inconsistency in principle. In practice, verified against `scripts/dast/active/bring-up.sh` (the only caller that ever sets `DAST_CANARY=1`), the self-test pipeline boots via plain `php artisan serve` and never runs `config:cache` or `route:cache`, so the theoretical cache-inconsistency failure mode this pattern normally causes cannot occur on the one path that actually sets this variable. Fixing it is still worthwhile for consistency with the rest of the codebase's config-access convention, but it is not a live risk.
    - **Plain English:** A hidden test-only door in the app is currently wired to check its switch directly, instead of through the same control panel every other setting uses. In this specific case the way the test script starts the app means the switch is always read fresh, so nothing is actually broken today — but it's still worth tidying so this door works the same way as everything else if that ever changes.
    - **Evidence:**
        ```php
        if (env('DAST_CANARY')) {
            Route::get('/__dast_canary', fn (Request $request) => response('<div>'.$request->query('x').'</div>'));
        }
        ```

- [ ] **#CFG-5** · P3 — Link-in-bio import budgets are hardcoded class constants
    - **Where:** app/Routing/Importers/LinkInBioImporter.php:50, 53, 59
    - **Affects:** Link-in-bio import tuning (link cap, page cap, probe budget) across environments.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `MAX_LINKS`, `MAX_PAGES`, `MAX_PROBES` into `config/partna.php` under `partna.routing.link_in_bio.*` with today's values as defaults.
        - Replace the class constants with `config('partna.routing.link_in_bio.max_links')` etc.
    - **Technical:** These are per-run operational caps of the same kind `config/partna.php`'s `limits.*` block already centralizes for Apify/Places/pagination. Hardcoding them here means tuning requires a code deploy instead of an env change.
    - **Plain English:** How many links, pages, and probe-questions the import job is allowed per run are welded into the code. Adjusting any of them needs a code change and redeploy instead of a settings tweak.
    - **Evidence:**
        ```php
        private const MAX_LINKS = 300;

        /** Hard cap on pages fetched in one run. */
        private const MAX_PAGES = 50;

        /** Probe budget per RUN — parity with the legacy RouteContext::DEFAULT_MAX_PROBES. */
        private const MAX_PROBES = 18;
        ```

- [ ] **#CFG-6** · P3 — Probe wall-clock budget's only home is a service-level `config()` fallback
    - **Where:** app/Routing/Probes/LinkProbeWorker.php:128
    - **Affects:** Commerce-link probe runtime ceiling.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Define `partna.routing.probe.budget_seconds` explicitly in `config/partna.php` (e.g. `(float) env('PARTNA_ROUTING_PROBE_BUDGET_SECONDS', 15)`), so the value has one documented home instead of only existing as an inline `config()` fallback.
    - **Technical:** `config('partna.routing.probe.budget_seconds', 15)` works today, but `15` is currently the ONLY place this value is defined — it is not present anywhere in `config/partna.php`. Centralizing it makes the tunable limit discoverable in the config file alongside its siblings rather than only visible by reading this one call site.
    - **Plain English:** The timer that stops a slow probe currently only exists as a fallback number buried in one file, not as a labeled setting anyone would find by looking at the settings file.
    - **Evidence:**
        ```php
        (float) config('partna.routing.probe.budget_seconds', 15),
        ```

- [ ] **#CFG-7** · P3 — Short-link expansion cache TTLs are hardcoded literals
    - **Where:** app/Routing/ShortLinkExpander.php:52, 54
    - **Affects:** Short-link expansion caching/retention tuning.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `SUCCESS_TTL_SECONDS` and `FAILURE_TTL_SECONDS` into `config/partna.php` (e.g. `partna.routing.short_link.success_ttl` / `failure_ttl`).
        - Replace the constants with `config()` reads carrying today's values as defaults.
    - **Technical:** Cache TTLs are operational knobs, not invariant logic — same class of value the codebase already centralizes elsewhere in `partna.limits.*`.
    - **Plain English:** How long the app remembers where a shortened link points — one day on success, one hour on failure — is carved into the code rather than being an adjustable setting.
    - **Evidence:**
        ```php
        private const SUCCESS_TTL_SECONDS = 86400;

        private const FAILURE_TTL_SECONDS = 3600;
        ```

- [ ] **#CFG-8** · P3 — Job retry/backoff/timeout values hardcoded across three platform-connect jobs
    - **Where:** app/Jobs/Platforms/CommerceProbeJob.php:50-56; app/Jobs/Platforms/ShopBrandConnectJob.php:51-58; app/Jobs/Platforms/ShopInitialFillJob.php:35-50
    - **Affects:** Platform connect/catalogue job reliability, particularly if the related HTTP fetch budget (`partna.http_fetch.connect_budget_seconds`) is ever raised.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract `tries`, `backoff`, `timeout`, `uniqueFor` for these three jobs into `config/partna.php` under a `jobs.*` section.
        - Explicitly tie `ShopBrandConnectJob::$timeout` to `config('partna.http_fetch.connect_budget_seconds')` plus fixed headroom (e.g. `+55`) instead of the standalone literal `75`, since the class's own comment already states this dependency without enforcing it in code.
    - **Technical:** `ShopBrandConnectJob`'s own comment states its 75s timeout "must exceed `config('partna.http_fetch.connect_budget_seconds')`... with headroom," but nothing in code enforces that relationship — raising the fetch budget config without also editing this hardcoded literal would silently let the worker die before the fetch guard completes. The other two jobs' timeout/backoff/uniqueFor values are the same class of tunable that currently requires a code deploy to adjust.
    - **Plain English:** These jobs each have a stopwatch and a retry plan written directly into the code. One of them even has a comment admitting it depends on a *different* adjustable setting elsewhere — but nothing actually keeps the two in sync, so changing that other setting without also editing this file could make the job time out too early.
    - **Evidence:**
        ```php
        // CommerceProbeJob.php
        public int $tries = 2;
        public array $backoff = [30];
        public int $timeout = 90;
        public int $uniqueFor = 300;

        // ShopBrandConnectJob.php
        public int $tries = 3;
        public array $backoff = [5, 20];
        // Must exceed config('partna.http_fetch.connect_budget_seconds') (20s
        // default) with headroom, same reasoning as ConnectFetchJob::$timeout.
        public int $timeout = 75;
        public int $uniqueFor = 120;

        // ShopInitialFillJob.php
        public int $tries = 2;
        public array $backoff = [10];
        public int $timeout = 240;
        public int $uniqueFor = 300;
        ```

- [ ] **#CFG-9** · P3 — Google Business API HTTP timeouts hardcoded
    - **Where:** app/Services/Platforms/GoogleBusinessService.php:245, 295, 766
    - **Affects:** Places/Street View request resilience tuning.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the three `Http::timeout(...)` literals into `config('partna.limits.google_places.timeouts.*')` with today's values (6s search-text, 5s place-details, 5s street-view) as defaults.
    - **Technical:** Magic timeout literals inline in an HTTP-calling service; the same tunable-without-redeploy pattern `partna.limits.*` already covers for Apify/Places spend caps in this same config file.
    - **Plain English:** The "how long to wait before giving up on Google" numbers are written directly into the code. If Google gets slow, there's no setting to adjust — someone has to edit and redeploy the code.
    - **Evidence:**
        ```php
        $res = Http::timeout(6)
            ->withHeaders([...])
            ->post(self::SEARCH_TEXT_URL, $body);

        $res = Http::timeout(5)
            ->withHeaders([...])
            ->get('https://places.googleapis.com/v1/places/'.rawurlencode($placeId));

        $res = Http::timeout(5)->get('https://maps.googleapis.com/maps/api/streetview/metadata', [
        ```

- [ ] **#CFG-10** · P3 — Action-scoring tuning constants hardcoded alongside config-driven weights
    - **Where:** app/Services/Analytics/ActionScorer.php:42, 44, 46, 48
    - **Affects:** Ability to tune smart-action ranking (demand half-life, anti-thrash blend, rank-swap hysteresis) without a deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract `HALF_LIFE_DAYS`, `BLEND_NEW`, `BLEND_PREV`, `RANK_SWAP_THRESHOLD` into `config('partna.actions.*')` with today's values as defaults, alongside the existing `partna.actions.prior_k` / `.weights` / `.freshness_half_life_days` keys this class already reads.
    - **Technical:** `ActionScorer` already reads three config keys under `partna.actions.*` (`prior_k`, `weights`, `freshness_half_life_days`), so the scoring surface is split between config-driven and compile-time-constant tuning for values of the identical kind — same root-cause pattern as CFG-11/CFG-12 below (analytics scoring: some knobs config, some hardcoded).
    - **Plain English:** Part of this scoring system's dials are on a settings panel, and part are welded into the machine. Changing the welded ones needs a code change; changing the panel ones doesn't.
    - **Evidence:**
        ```php
        private const HALF_LIFE_DAYS = 90.0;

        private const BLEND_NEW = 0.7;

        private const BLEND_PREV = 0.3;

        private const RANK_SWAP_THRESHOLD = 0.10;
        ```

- [ ] **#CFG-11** · P3 — Duplicate freshness constants risk silent drift from the configured `pools.smart.link_item` values
    - **Where:** app/Services/Analytics/ContentFreshness.php:32, 35
    - **Affects:** Dev-insights reporting; anyone updating `config/partna.php`'s `pools.smart.link_item` weights without knowing this duplicate exists.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Derive these dev-insights values from `config('partna.pools.smart.link_item')` instead of standalone constants, or, if they must stay as a stable public API, add a test asserting `ContentFreshness::HALF_LIFE_DAYS === config('partna.pools.smart.link_item.half_life_days')` (and `W_ITEM` vs `.fresh`) so drift fails CI.
    - **Technical:** Both constants are commented as mirroring `config('partna.pools.smart.link_item')` (`half_life_days: 14.0`, `fresh: 3.0`) and, verified against `config/partna.php:918` and `ItemFamily::weightsFor()`, currently DO match — so this is not yet a live bug, but a same-root-cause duplicate-source-of-truth risk to CFG-10/CFG-12: two independent numbers with a documented "these should match" comment and no enforcement.
    - **Plain English:** There are two copies of the same tuning numbers — one in the settings file, one hardcoded here with a comment saying "this should match." They currently do match, but nothing would catch it if someone changed the settings copy and forgot the hardcoded one.
    - **Evidence:**
        ```php
        /** Kept for the dev-insights surface; link_item's half-life in config matches. */
        public const HALF_LIFE_DAYS = 14.0;

        /** The link_item boost at age 0 (config `pools.smart.link_item.fresh`). */
        public const W_ITEM = 3.0;
        ```

- [ ] **#CFG-12** · P3 — Content-popularity scoring tuning constants hardcoded while sibling signal weights are config-driven
    - **Where:** app/Console/Commands/ComputeContentPopularityScores.php:76, 84, 88, 91, 93, 95, 97, 122
    - **Affects:** Operators tuning popularity decay/blend/batch behavior; consistency with the same command's own `pools.smart` weight reads.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract `HALF_LIFE_DAYS`, `SCORE_FLOOR`, `BLEND_NEW`, `BLEND_PREV`, `RANK_SWAP_THRESHOLD`, `SITE_CHUNK`, `RECENT_EVENTS_WINDOW_MINUTES`, and `$timeout = 600` into `config/partna.php` under `partna.analytics.popularity.*`.
        - Replace the constants/literal with `config()` reads carrying today's values as defaults.
    - **Technical:** Same root cause as CFG-10/CFG-11 — this command already delegates signal weights to `config('partna.php')`'s `pools.smart` block, but leaves decay half-life, score floor, blend weights, rank-swap hysteresis, batch chunk size, and the recent-events window as hardcoded constants. All three findings (ActionScorer, ContentFreshness, this command) are the same analytics-scoring "half config, half hardcoded" pattern and should be fixed together.
    - **Plain English:** This command already lets you adjust some of its scoring behavior from a settings file, but seven other dials that do the same kind of job — how fast old content fades, how much weight new vs. old scores get, how many sites it processes in a batch — are all bolted inside the code instead.
    - **Evidence:**
        ```php
        // Signal weights live in config/partna.php `pools.smart` (ItemFamily).
        private const HALF_LIFE_DAYS = 90.0;
        private const SCORE_FLOOR = 0.05;
        private const BLEND_NEW = 0.7;
        private const BLEND_PREV = 0.3;
        private const RANK_SWAP_THRESHOLD = 0.10;
        private const SITE_CHUNK = 200;
        private const RECENT_EVENTS_WINDOW_MINUTES = 60;
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Feature-flag safe defaults:** #CFG-1, #CFG-2
    - **Why grouped:** Same root cause — a capability flag defaulting to `true` instead of `false`; both are one-line `config/partna.php` + `.env.example` edits.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Analytics scoring: config-ify the remaining hardcoded tuning constants:** #CFG-10, #CFG-11, #CFG-12
    - **Why grouped:** Identical pattern across three files (partially config-driven scoring, rest hardcoded); fixing them together avoids re-deriving the same `partna.analytics.*` / `partna.actions.*` config shape three separate times.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Routing operational limits to config:** #CFG-5, #CFG-6, #CFG-7
    - **Why grouped:** All three live under `app/Routing/`, all are hardcoded operational budgets/TTLs of the same "should live in `partna.limits.*`" shape.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Job/service hardcoded timeouts:** #CFG-8, #CFG-9
    - **Why grouped:** Same pattern (hardcoded `Http::timeout()` / job `$timeout`/`$backoff` literals) across job classes and a platform service; #CFG-8 already documents a real coupling to `partna.http_fetch.connect_budget_seconds` worth fixing alongside #CFG-9's timeouts.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — DAST canary config-ification:** #CFG-4
    - **Why grouped:** Single-file, single-line change with no dependency on any other finding — fine to absorb opportunistically the next time `routes/api.php` is open.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#CFG-3 — Shop-brand connect limit drift (`StoreBrandSeeder` 5 vs `ShopController`/`ConnectStoreFromProductJob` 10)** · standalone: touches user-facing enforcement of a limit across three files with duplicated business logic — needs its own plan to confirm the correct canonical value (10, per the two files that agree) before editing the seeder, plus a regression test proving both the auto-probe and manual-connect paths now refuse at the same count.
