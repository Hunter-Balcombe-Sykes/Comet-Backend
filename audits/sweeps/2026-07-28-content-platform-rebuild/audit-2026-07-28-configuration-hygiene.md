# Configuration Hygiene Audit — 2026-07-28

**Branch:** development
**Lens:** Configuration Hygiene — env() outside config, missing .env.example keys, feature flags without safe defaults, hardcoded values that should be config-driven
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- config/partna.php
- config/horizon.php
- .env.example
- routes/api.php
- routes/console.php
- app/Services/Media/MediaDiskResolver.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Console/Commands/LaunchCheckEnvCommand.php
- app/Jobs/**/*.php (queue-routing sweep)
- app/Ingest/Connectors/{EventbriteConnector,HumanitixConnector,AppleMusicConnector,ApplePodcastsConnector,FreshaConnector,TwitchConnector}.php
- app/Ingest/Landing/Lander.php, app/Ingest/Runtime/{EffectLedger,SourceScheduler}.php
- app/Routing/{LinkProjector,IriCanonicalizer}.php, app/Routing/Probes/{LinkProbeWorker,ProbeBudget,ProbeGate,ProbeOutcome}.php, app/Routing/Importers/{ImportRun,LinkInBioImporter,WebsiteImporter}.php
- app/Services/Brand/BrandAssetPipeline.php, app/Services/Platforms/{CustomLinkSeeder,GoogleBusinessApifyScraper,GoogleBusinessService}.php
- app/Services/PublicSite/SiteActionsService.php, app/Services/Content/{SectionTracer,ItemMerger}.php
- app/Services/Analytics/AnalyticsQueryService.php
- app/Services/Http/SafeUrlFetcher.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 1 of 1 complete
- P3 Low: 0 of 19 complete

---

## P2 — Should fix

- [x] **#CFG-1** · P2 — Bot protection fail-open defaults to `true` — a future enforce-mode deploy silently bypasses CAPTCHA on verification failure
    - **Where:** config/partna.php:2098 (`bot_protection.fail_open`)
    - **Affects:** All public mutation endpoints once `BOT_PROTECTION_MODE=enforce` is turned on for a given environment (signup, enquiry, subscribe, report) — a misconfigured or unreachable CAPTCHA provider means every request passes until the circuit-breaker's `failure_threshold` is reached.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the default to `(bool) env('BOT_PROTECTION_FAIL_OPEN', false)` so a fresh `enforce`-mode deploy fails closed unless an operator explicitly opts into fail-open.
        - Update the `.env.example` comment (currently "Pre-pilot default; revisit fail-closed per sensitive surface after first real incident") to reflect the new default and note which surfaces, if any, still need fail-open.
    - **Technical:** `bot_protection.mode` defaults to `off` and `driver` to `null`, so this gap has zero effect until an environment is deliberately switched to `enforce` with a real Turnstile/hCaptcha driver configured — it is not exploitable today. But once that switch happens, `fail_open=true` means a throwing/timing-out CAPTCHA call falls through to `$next($request)` instead of rejecting, and the circuit-breaker (`failure_threshold` default 5) only kicks in after several failures have already passed through. The `.env.example` comment shows this was a deliberate pre-pilot choice with an explicit intent to revisit — this finding is that revisit.
    - **Plain English:** Think of a bouncer checking IDs with a scanner. If the scanner breaks, the bouncer can either turn everyone away (safe) or wave everyone through (risky). Right now the system is set to "wave everyone through" once bot-checking is actually turned on for a site. It costs nothing today because bot-checking itself is off by default, but if an operator turns it on for a real launch and the CAPTCHA service has an outage, bots get in silently. Flipping the default to "turn people away when the scanner is broken" is the safer starting point.
    - **Evidence:**
        ```php
        'fail_open' => (bool) env('BOT_PROTECTION_FAIL_OPEN', true),
        ```

## P3 — Nice to have

- [ ] **#CFG-2** · P3 — Duplicate 24-char Accept-header string in `SafeUrlFetcher::fetch()` and `fetchMany()`
    - **Where:** app/Services/Http/SafeUrlFetcher.php:106, :248
    - **Affects:** Maintainers updating the outbound-fetch content-type policy — a change applied to one method and not the other silently diverges single-URL vs bulk-fetch behaviour.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Extract the Accept header string into a private class constant and reference it from both methods.
    - **Technical:** Category 4. Both `fetch()` and `fetchMany()` build the identical `Accept: text/html,application/json,application/ld+json;q=0.9,*/*;q=0.8` header inline. A future change (e.g. adding `application/xml` support) applied to only one call site produces inconsistent behaviour between single and bulk fetches with no test to catch the drift.
    - **Plain English:** The same instruction — "here's what kind of pages I can read" — is written out twice in the same file. If someone updates one copy and forgets the other, the tool starts behaving differently depending on whether it's fetching one page or many at once.
    - **Evidence:**
        ```php
        'Accept' => 'text/html,application/json,application/ld+json;q=0.9,*/*;q=0.8',
        ```

- [ ] **#CFG-3** · P3 — `AnalyticsQueryService` scatters hardcoded result-size limits across six methods
    - **Where:** app/Services/Analytics/AnalyticsQueryService.php:203, :265, :285, :344, :431, :514
    - **Affects:** Product tuning of dashboard panel sizes without a code deploy; the 8/10/12 split reads as arbitrary to anyone auditing just the config.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add `config('partna.analytics.limits.*')` keys (`top_links_limit`=10, `top_sections_limit`=12, `top_pages_limit`=12, `top_cities_limit`=6, `top_countries_display`=4, `top_items_default_limit`=8) and read from them.
    - **Technical:** Category 4. `topLinks()` (`->limit(10)`), `topSections()` (`->limit(12)`), `topPages()` (`->take(12)`), `cities()` (default param 6), `countries()` (`->take(4)`), and `topItemsBySection()` (default param 8) all hardcode panel sizes with no config path — a product decision to change any panel size currently requires a deploy.
    - **Plain English:** The analytics dashboard's "top 10 links," "top 12 sections," "top 6 cities" panels each have their count baked into the code. Moving these to a settings file means panel sizes can be tuned without shipping new code.
    - **Evidence:**
        ```php
        ->limit(10)
        ->limit(12)
        ->take(12)
        public function cities(string $userId, Carbon $from, Carbon $to, int $limit = 6): array
        $top = $raw->take(4)->map(...)
        private function topItemsBySection(string $userId, Carbon $from, Carbon $to, array $sectionKeys, int $limit = 8): array
        ```

- [ ] **#CFG-4** · P3 — `AnalyticsQueryService` hardcodes the live-visitor heartbeat window and engaged-session floor
    - **Where:** app/Services/Analytics/AnalyticsQueryService.php:548-549, :567
    - **Affects:** Backend/frontend drift risk — the live-visitor window is derived from an assumed 25s frontend heartbeat that isn't wired to anything.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Extract the 75s live-visitor window and 10s engaged-session floor into `config('partna.analytics.session_heartbeat_seconds', 25)` / `config('partna.analytics.engaged_session_floor_seconds', 10)`.
    - **Technical:** Category 4. `liveVisitors()` hardcodes `now()->subSeconds(75)` with the comment "three missed 25s heartbeats = gone" — if the frontend's ping cadence ever changes, this backend check silently drifts out of sync. `sessionsAggregate()` hardcodes `duration_seconds >= 10` twice in raw SQL.
    - **Plain English:** The "who's online now" counter assumes visitors ping the server every 25 seconds and gives up after three missed pings. That assumption lives only in a code comment. If the front-end team changes the ping rate and doesn't tell the backend team, the live counter quietly breaks.
    - **Evidence:**
        ```php
        ->selectRaw('COUNT(*) FILTER (WHERE duration_seconds >= 10) as engaged_sessions')
        ->selectRaw('COALESCE(ROUND(AVG(duration_seconds) FILTER (WHERE duration_seconds >= 10)), 0) as avg_duration_seconds')
        ...
        ->where('last_seen_at', '>=', now()->subSeconds(75))
        ```

- [ ] **#CFG-5** · P3 — `ItemMerger::MAX_DECISION_PAIRS` hardcoded as a private constant
    - **Where:** app/Services/Content/ItemMerger.php:39
    - **Affects:** Support/on-call tuning the duplicate-merge safeguard without a redeploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Extract to `config('partna.limits.identity.max_decision_pairs', 100)`; keep `pairBound()` as a convenience accessor over it.
    - **Technical:** Category 4. Caps the cross-product of coords between two merging items at 100 to stop a mis-shaped projection from quietly writing thousands of decision rows. Single-location constant with a public getter (`pairBound()`), so drift risk is low relative to #CFG-6, but it's still a magic number a redeploy is needed to change.
    - **Plain English:** When two duplicate profile items are merged, the system writes one record per shared identifier, capped at 100 as a safety net against a bug generating thousands. That cap of 100 is buried in one file — moving it to settings makes it visible and adjustable without shipping code.
    - **Evidence:**
        ```php
        private const MAX_DECISION_PAIRS = 100;
        ```

- [ ] **#CFG-6** · P3 — `SectionTracer::CANDIDATE_SCAN_LIMIT` hardcoded, manually synced with `DocumentBuilder`
    - **Where:** app/Services/Content/SectionTracer.php:27
    - **Affects:** The trace diagnostic tool and the live page builder drifting apart if only one is tuned.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Extract to `config('partna.limits.section_candidate_scan_limit', 200)`; reference from both `SectionTracer` and `DocumentBuilder::ruleCandidates()`.
    - **Technical:** Category 4 (same pattern the lens calls out for duplicated API version strings). The comment says "Mirrors `DocumentBuilder::ruleCandidates()`' own bound" — a manual-consistency promise across two classes. If the builder's window is tuned for performance and the tracer isn't, the diagnostic ("why isn't my item showing?") silently starts answering from a different candidate pool than the live page.
    - **Plain English:** The page builder and its diagnostic tool both cap "candidates considered" at 200, with a comment saying "keep these the same." If someone tunes one and forgets the other, the tool meant to explain why an item isn't showing starts giving wrong answers.
    - **Evidence:**
        ```php
        /** Mirrors DocumentBuilder::ruleCandidates()' own bound. */
        private const CANDIDATE_SCAN_LIMIT = 200;
        ```

- [ ] **#CFG-7** · P3 — `SiteActionsService::CUSTOM_LABEL_MAX` hardcoded, manually synced with the request validator
    - **Where:** app/Services/PublicSite/SiteActionsService.php:66
    - **Affects:** Owners whose custom-link labels get truncated at a different length than the dashboard validator advertises, if the two drift.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Extract to `config('partna.limits.custom_action_label_max_length', 80)`; reference from both the FormRequest rule and this service.
    - **Technical:** Category 4. The constant's own comment states it "mirrors the request validator's max:80" — a manual sync contract between two independent files. If the validator is later changed to `max:120` without updating this constant, the emit path silently truncates labels the validator accepted.
    - **Plain English:** A label-length limit of 80 is written in two separate places — what the dashboard accepts, and what the public page displays. If someone updates only the front door, labels get silently chopped until a visitor complains.
    - **Evidence:**
        ```php
        // Defensive cap on a CUSTOM action label at emit time (mirrors the request
        // validator's max:80). Bounds a stray/unvalidated write, not a normal one.
        private const CUSTOM_LABEL_MAX = 80;
        ```

- [ ] **#CFG-8** · P3 — `GoogleBusinessService` hardcodes Places API retry count and backoff delay
    - **Where:** app/Services/Platforms/GoogleBusinessService.php:124, :167
    - **Affects:** Resilience tuning during a flaky Google Places API period.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Extract `DETAILS_MAX_ATTEMPTS` to `config('partna.google_places.max_attempts', 2)` and the `usleep(200_000)` backoff to `config('partna.google_places.retry_delay_microseconds', 200_000)`.
    - **Technical:** Category 4. Both the attempt count and backoff are baked-in constants with no env override, unlike the sibling `limits.places.*` caps in `config/partna.php` which are already fully env-driven.
    - **Plain English:** When a Google Places call fails, the system retries once after a short pause. How many tries and how long the pause are fixed in code — changing them today needs a developer and a deploy.
    - **Evidence:**
        ```php
        private const DETAILS_MAX_ATTEMPTS = 2;
        ...
        if ($attempt < self::DETAILS_MAX_ATTEMPTS) {
            usleep(200_000);
        ```

- [ ] **#CFG-9** · P3 — `GoogleBusinessApifyScraper` hardcodes a 110s HTTP timeout
    - **Where:** app/Services/Platforms/GoogleBusinessApifyScraper.php:52
    - **Affects:** Adjusting the Apify run-sync wait as latency changes, without a deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Replace `->timeout(110)` with `config('partna.apify.timeout', 110)`.
    - **Technical:** Category 4. Every other Apify-adjacent budget in this codebase (`limits.apify.*`, `limits.places.*`) is env-configurable; this HTTP client timeout is the odd one out.
    - **Plain English:** The service waits up to 110 seconds for Apify to answer. That number is fixed in code — if Apify gets slower, nobody can raise it without shipping a new release.
    - **Evidence:**
        ```php
        $response = Http::withToken($token)
            ->timeout(110)
        ```

- [ ] **#CFG-10** · P3 — `CustomLinkSeeder::MAX_LINKS` hardcoded
    - **Where:** app/Services/Platforms/CustomLinkSeeder.php:29
    - **Affects:** Per-environment tuning of the custom-link cap (e.g. looser limits in staging).
    - **Effort:** S (~0.5–1h)
    - **What to do:** Extract to `config('partna.limits.custom_links_max', 20)`.
    - **Technical:** Category 4. A plain hardcoded cap with no config or env path.
    - **Plain English:** The limit of 20 custom links per user is painted directly into the code. Moving it to settings lets the team adjust it centrally instead of shipping code.
    - **Evidence:**
        ```php
        public const MAX_LINKS = 20;
        ```

- [ ] **#CFG-11** · P3 — `BrandAssetPipeline` hardcodes max upload size and thumbnail edge length
    - **Where:** app/Services/Brand/BrandAssetPipeline.php:43, :46
    - **Affects:** Ops adjusting logo size/thumbnail limits without a deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Extract `MAX_BYTES` to `config('partna.brand_asset.max_bytes', 4 * 1024 * 1024)` and `VARIANT_EDGE` to `config('partna.brand_asset.variant_edge', 512)`.
    - **Technical:** Category 4. Both are plain private constants with no env override, unlike the parallel `PARTNA_IMAGE_MAX_UPLOAD_KB` / `PARTNA_GALLERY_IMAGE_MAX` pattern already used for general image uploads.
    - **Plain English:** The maximum logo file size and thumbnail size are locked in the code. Moving them to settings makes them adjustable per environment without a new release.
    - **Evidence:**
        ```php
        private const MAX_BYTES = 4 * 1024 * 1024;
        private const VARIANT_EDGE = 512;
        ```

- [ ] **#CFG-12** · P3 — Importer daily/link caps hardcoded across three classes
    - **Where:** app/Routing/Importers/ImportRun.php:21, app/Routing/Importers/LinkInBioImporter.php:39, :42, app/Routing/Importers/WebsiteImporter.php:28
    - **Affects:** Support's ability to bump an import limit for a legitimate one-off case without a deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Extract `DAILY_LIMIT` (3), `MAX_LINKS` (100/200), and `MAX_PAGES` (20) into `config('partna.limits.import_daily', 3)`, `config('partna.limits.import_bio_max_links', 100)`, `config('partna.limits.import_website_max_links', 200)`, `config('partna.limits.import_bio_max_pages', 20)`.
    - **Technical:** Category 4. All four are pure integer caps with no downstream formatting dependency, making them low-risk config extractions.
    - **Plain English:** Import speed limits (3 runs/day, 100 links from a bio page, 200 from a website) are written directly in code. A support person can't raise a limit for one user without a developer shipping new code.
    - **Evidence:**
        ```php
        private const DAILY_LIMIT = 3;
        private const MAX_LINKS = 100;
        private const MAX_PAGES = 20;
        private const MAX_LINKS = 200;
        ```

- [ ] **#CFG-13** · P3 — `ProbeGate::PROBE_CONFIDENCE` hardcoded
    - **Where:** app/Routing/Probes/ProbeGate.php:41
    - **Affects:** Tuning probe-derived confidence as real accuracy data accumulates, without a deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add `config('partna.routing.probe.confidence', 90)` and read it from `ProbeOutcome::toProjection()` instead of the class constant.
    - **Technical:** Category 4. The docblock explains the 90 is "deliberately not 100" — exactly the kind of empirically-tunable value that benefits from a config path as real probe data accumulates.
    - **Plain English:** A successful storefront probe reports "90% sure." That number is fixed in code; letting the team adjust it as they learn how reliable probes really are shouldn't require a full deploy.
    - **Evidence:**
        ```php
        public const PROBE_CONFIDENCE = 90;
        ```

- [ ] **#CFG-14** · P3 — `LinkProjector::FLOOR` confidence threshold hardcoded
    - **Where:** app/Routing/LinkProjector.php:16
    - **Affects:** Tuning the routing confidence floor from real traffic data.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Extract to `config('partna.routing.confidence_floor', 35)`.
    - **Technical:** Category 4. Gates whether a detector match is considered at all; every sibling probe threshold in this same subsystem (`ProbeGate`, `ProbeBudget`) already reads from config — this is the one constant in the routing pipeline with no config path.
    - **Plain English:** The router has a "minimum confidence" dial set to 35, glued in place. Every neighboring dial in the same subsystem can already be turned via settings; this one needs a code change instead.
    - **Evidence:**
        ```php
        private const FLOOR = 35;
        ```

- [ ] **#CFG-15** · P3 — Probe budget/cooldown config keys carry their defaults at the call site, not in `config/partna.php`
    - **Where:** app/Routing/Probes/ProbeBudget.php:111, :116, :121; app/Routing/Probes/LinkProbeWorker.php:126; app/Routing/Probes/ProbeGate.php:144
    - **Affects:** Discoverability of the probe subsystem's tunables — a developer reading `config/partna.php` alone cannot see these keys or their defaults exist.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add `partna.routing.probe.{budget_seconds,per_run_cap,global_daily_cap,user_daily_cap,cooldown_minutes}` to `config/partna.php` with today's values (15, 6, 2000, 40, 720) as the canonical defaults; keep the call sites reading `config('key')` without a duplicated fallback.
    - **Technical:** Category 5. These calls already correctly use `config()` (not `env()`), so there's no caching-bypass risk — but the default lives twice: once implicitly (call-site fallback) and, were the key ever added to the config file with a different value, again in the file. Consolidating to one place removes the two-sources-of-truth risk.
    - **Plain English:** Several routing "probe" settings have their default values written on sticky notes at each place they're read, rather than in the shared settings file. It works today, but if someone later adds these to the settings file with a different number, the sticky notes and the settings file would disagree.
    - **Evidence:**
        ```php
        return (int) config('partna.routing.probe.per_run_cap', 6);
        return (int) config('partna.routing.probe.global_daily_cap', 2000);
        return (int) config('partna.routing.probe.user_daily_cap', 40);
        (float) config('partna.routing.probe.budget_seconds', 15),
        now()->addMinutes((int) config('partna.routing.probe.cooldown_minutes', self::COOLDOWN_MINUTES)),
        ```

- [ ] **#CFG-16** · P3 — `Lander`/`EffectLedger`/`SourceScheduler` operational constants hardcoded
    - **Where:** app/Ingest/Landing/Lander.php:27, :35; app/Ingest/Runtime/EffectLedger.php:25; app/Ingest/Runtime/SourceScheduler.php:26, :29
    - **Affects:** On-call ability to tune deletion sensitivity, billed-effect abandonment window, and scheduler fairness during an incident, without a deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Extract `TOMBSTONE_RUNS` (3), `GUARD_THRESHOLD` (0.4), `ABANDON_AFTER_SECONDS` (900), `ALPHA` (0.3), and `STRANDED_AFTER_SECONDS` (7200) into `config('partna.ingest.*')` keys with today's values as defaults.
    - **Technical:** Category 4. These five constants across three core ingest classes govern deletion safety, billing-retry policy, and scheduler fairness — genuine operational knobs an on-call engineer would want to adjust live during an incident rather than via code deploy.
    - **Plain English:** Five settings that control when the system deletes stale records, gives up on a stuck billing attempt, or releases a dead worker's claim are hard-wired into the code. An on-call engineer can't turn any of these knobs at 3am without shipping code.
    - **Evidence:**
        ```php
        private const TOMBSTONE_RUNS = 3;
        private const GUARD_THRESHOLD = 0.4;
        private const ABANDON_AFTER_SECONDS = 900;
        private const ALPHA = 0.3;
        private const STRANDED_AFTER_SECONDS = 7200;
        ```

- [ ] **#CFG-17** · P3 — `TwitchConnector` hardcodes the Helix 429 retry delay
    - **Where:** app/Ingest/Connectors/TwitchConnector.php:162
    - **Affects:** Tuning backoff during a Twitch-side rate-limit incident.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Replace the literal `120` with `(int) config('partna.ingest.twitch_rate_limit_retry_seconds', 120)`.
    - **Technical:** Category 4. Twitch's Helix API doesn't reliably send `Retry-After`, making this hardcoded fallback the only backoff lever; other connectors' retry cadences are already config-driven.
    - **Plain English:** When Twitch says "slow down," the connector always waits exactly 2 minutes before retrying. If Twitch needed a longer pause during an incident, there's no way to ask for it without a code deploy.
    - **Evidence:**
        ```php
        yield new Deferred(120, 'helix rate limited');
        ```

- [ ] **#CFG-18** · P3 — Fetch-size caps hardcoded across four ingest connectors
    - **Where:** app/Ingest/Connectors/EventbriteConnector.php:47, app/Ingest/Connectors/HumanitixConnector.php:44, app/Ingest/Connectors/AppleMusicConnector.php:52, app/Ingest/Connectors/ApplePodcastsConnector.php:43
    - **Affects:** Operators tuning per-connector run cost; a user whose event/album count exceeds the cap silently gets truncated data.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Extract `MAX_EVENT_PAGES` (20) and `LOOKUP_LIMIT` (200) into `config('partna.ingest.max_event_pages_per_run')` / `config('partna.ingest.apple_lookup_limit')`.
    - **Technical:** Category 4. Two connector pairs share the identical constant name and value with no config path — a cost-control lever an operator would want during an incident or scale-up.
    - **Plain English:** Four connectors each have "how many things to fetch" baked directly into the code. Lowering the cap during an incident, or raising it for a heavy user, currently needs a full code change and deploy.
    - **Evidence:**
        ```php
        // EventbriteConnector.php / HumanitixConnector.php
        private const MAX_EVENT_PAGES = 20;

        // AppleMusicConnector.php / ApplePodcastsConnector.php
        private const LOOKUP_LIMIT = 200;
        ```

- [ ] **#CFG-19** · P3 — Hardcoded queue name `'ingest'` bypasses the config-driven queue-routing pattern
    - **Where:** app/Jobs/Ingest/RunSourceJob.php:52
    - **Affects:** Queue routing for all ingest source runs — every other job in `app/Jobs/` reads its queue name from `config('partna.queues.*')`.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Replace `$this->onQueue('ingest')` with `$this->onQueue(config('partna.queues.ingest', 'ingest'))`, adding the `partna.queues.ingest` key.
    - **Technical:** Category 4. A repo-wide sweep of every `onQueue()` call in `app/Jobs/` confirms `RunSourceJob` is the sole holdout using a bare string literal — every sibling job (`IngestBrandAssetJob`, `SendSubscriptionConfirmationJob`, `SyncSubdomainToKvJob`, etc.) reads `config('partna.queues.*')` with a matching fallback. `config/horizon.php` does define an `ingest` supervisor queue, so this isn't a dead-queue issue — it's a config-bypass inconsistency.
    - **Plain English:** Every other job in the system picks its queue by reading a label from the settings file — change the setting, and the job moves lanes. This one job has the lane name painted directly onto it, so changing it needs a code change instead of a config change.
    - **Evidence:**
        ```php
        $this->onQueue('ingest');
        ```

- [ ] **#CFG-20** · P3 — Keep-alive scheduler ping hardcodes its HTTP timeout and retry
    - **Where:** routes/console.php:264
    - **Affects:** Laravel Cloud pod warm-keeping — tuning the ping's patience without a deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add a `partna.keepalive` config block (`timeout_seconds`, `retry_delay_ms`) defaulting to today's values (3, 200) and read from it in the closure.
    - **Technical:** Category 4. This is the only place in `routes/console.php` with inline HTTP client literals; every other outbound HTTP call in the system reads its timeout from `config('partna.http_fetch.*')`. Deliberately not folded into that shared key — the keep-alive's 3s timeout is intentionally tighter than the 8s default for user-facing fetches.
    - **Plain English:** The system pings itself every minute to stay warm on Laravel Cloud, with a 3-second patience and one retry baked directly into the code. Every other outbound request in the system reads its timeout from a shared settings file; this one doesn't.
    - **Evidence:**
        ```php
        Http::timeout(3)->retry(1, 200)->get($url);
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Ingest connector & runtime constants:** #CFG-16, #CFG-17, #CFG-18
    - **Why grouped:** Same subsystem (`app/Ingest/`), identical root-cause pattern (operational tuning constant with no config path).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Routing/probe subsystem constants:** #CFG-13, #CFG-14, #CFG-15, #CFG-12
    - **Why grouped:** Same subsystem (`app/Routing/`), same pattern (confidence thresholds and caps that should live in `config/partna.php`).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Platform/brand service hardcoded knobs:** #CFG-9, #CFG-8, #CFG-10, #CFG-11
    - **Why grouped:** Same subsystem (`app/Services/Platforms/`, `app/Services/Brand/`), same pattern (HTTP timeout/retry/size caps that should be config-driven).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Cross-file magic-number sync risk:** #CFG-7, #CFG-6, #CFG-5
    - **Why grouped:** Same failure mode across `app/Services/PublicSite/`, `app/Services/Content/` — a constant whose value must stay manually in sync with another file/validator.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Analytics query limits:** #CFG-3, #CFG-4
    - **Why grouped:** Same file (`AnalyticsQueryService.php`), same pattern (panel sizes and time windows hardcoded).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 6 — Misc queue/HTTP config extraction:** #CFG-19, #CFG-20, #CFG-2
    - **Why grouped:** Unrelated files but the same trivial single-value config-extraction pattern; low enough risk to batch into one session.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#CFG-1 — Bot protection fail-open default** · security-relevant default flip on a not-yet-enforced feature; warrants its own plan and sign-off rather than being folded into an unrelated hardening batch.
