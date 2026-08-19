# Configuration Hygiene Audit — 2026-08-18

**Branch:** HEAD
**Lens:** Configuration Hygiene — `env()` outside config, missing `.env.example` keys, feature flags without safe defaults, config values used inconsistently (hardcoded vs. config-driven)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- config/partna.php
- routes/console.php
- app/Ingest/Connectors/AppleMusicConnector.php
- app/Ingest/Landing/Lander.php
- app/Ingest/Projection/IdentityKeyDeriver.php
- app/Ingest/Projection/ProjectionWriter.php
- app/Ingest/SourceProvisioner.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Media/MediaMirror.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/Http/SafeUrlFetcher.php
- app/Console/Commands/PruneNotifications.php
- config/services.php
- .env.example

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 5 complete

---

## P3 — Nice to have

- [ ] **#CFG-1** · P3 — Ingest source-provisioning interval floor hardcoded instead of config-driven
    - **Where:** app/Ingest/SourceProvisioner.php:33
    - **Affects:** Operators/QA tuning the minimum scheduling interval for ingest sources; staging/test environments that want a shorter floor to exercise the scheduler end-to-end.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `config('partna.ingest.max_interval_floor_secs', 604800)` to `config/partna.php` with an `.env.example` placeholder comment.
        - Replace the `self::MAX_INTERVAL_FLOOR_SECS` reference with the config lookup, keeping the constant as the fallback value.
    - **Technical:** `private const MAX_INTERVAL_FLOOR_SECS = 604800;` is a schedule-ceiling magic number applied to every provisioned `ingest.sources` row (`max_interval_secs`). It's an operational limit, not an algorithmic invariant, so it fits the platform's "limits live in `config/partna.php`" convention; today changing it requires a code deploy.
    - **Plain English:** There's a rule baked into the code that says "never let the scheduler wait less than seven days between certain checks." That rule has no switch — a test environment that wants a shorter wait to verify things quickly can't get one without a developer changing and redeploying code.
    - **Evidence:**
        ```php
        private const MAX_INTERVAL_FLOOR_SECS = 604800;
        ```

- [ ] **#CFG-2** · P3 — Identity price-band width hardcoded instead of config-driven
    - **Where:** app/Ingest/Projection/IdentityKeyDeriver.php:31
    - **Affects:** Future tuning of price-band granularity for identity matching across different verticals (services vs. shop vs. music).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `config('partna.identity.price_band', 5)` to `config/partna.php`.
        - Replace the `self::PRICE_BAND` usage in `priceBand()` with the config value, casting to int and falling back to the existing constant.
    - **Technical:** `private const PRICE_BAND = 5;` sets the width of the evidential `NamePriceBand` matching key. It's a business-tuning constant (how aggressively near-duplicate items are bucketed by price), not a protocol-level invariant, and is a reasonable config candidate under the platform's "limits live in config" convention.
    - **Plain English:** The system groups items into $5 price bands when it looks for near-duplicates. That $5 width is fixed in the code. If the team ever wants a wider or narrower band for a particular content type, it currently requires a code change and redeploy instead of a setting change.
    - **Evidence:**
        ```php
        private const PRICE_BAND = 5;
        ```

- [ ] **#CFG-3** · P3 — Apple Music lookup page size hardcoded instead of config-driven
    - **Where:** app/Ingest/Connectors/AppleMusicConnector.php:52
    - **Affects:** Apple Music catalogue pulls; tuning the iTunes Lookup page size for vendor-rate or test purposes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `config('partna.ingest.connectors.apple_music.lookup_limit', 200)` to `config/partna.php`.
        - Replace the `self::LOOKUP_LIMIT` references in the album/song lookups with the config value.
    - **Technical:** `private const LOOKUP_LIMIT = 200;` is a page-size constant used across both the album and song lookup requests and the "more results available" (`Covered`) checks. Page sizes are an explicit config candidate under this lens; currently there is no environment lever to lower it during a vendor slowdown or raise it later without a release.
    - **Plain English:** The Apple Music connector pulls at most 200 items per request, but that number is welded into the code. If Apple's API gets slow and the team wants to shrink that temporarily, or raise it later, someone has to ship new code — there's no dial to turn.
    - **Evidence:**
        ```php
        private const LOOKUP_LIMIT = 200;
        ```

- [ ] **#CFG-4** · P3 — Google auto-sync ordering-connection cap hardcoded instead of config-driven
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:41
    - **Affects:** Operators tuning how many ordering connections Google Business auto-sync seeds per user; changing the cap currently requires a code deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `config('partna.limits.ordering.google_business_max', 10)` to `config/partna.php`.
        - Replace `self::MAX_ORDERING` in `seedOrdering()` with the config lookup.
    - **Technical:** `private const MAX_ORDERING = 10;` bounds how many ordering-platform connections a single auto-sync run will seed. Partna centralizes operational limits in `config/partna.php`; this one is invisible to operators and can't be tuned without a redeploy, unlike the sibling `auto_booking.global_daily_cap`/`menu_cache_seconds` limits in the same subsystem, which already are config-driven.
    - **Plain English:** When Google auto-sync adds food-ordering links for a business, it stops after 10. That number is hardcoded. If the team wants to raise or lower it, they have to edit and redeploy code instead of flipping a setting — inconsistent with the neighboring auto-booking limits, which already are settings.
    - **Evidence:**
        ```php
        private const MAX_ORDERING = 10;
        ```

- [ ] **#CFG-5** · P3 — Ingest candidate-chunking batch size hardcoded in some Lander paths while a sibling path in the same file is config-driven
    - **Where:** app/Ingest/Landing/Lander.php:430, 489, 514, 568
    - **Affects:** Ingest operators tuning batch/page sizes for the coverage-dominance and absent-candidate sweeps; ability to shrink chunk size per environment without a deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `config('partna.ingest.land_sweep_chunk', 500)` (or reuse the existing `partna.ingest.land_chunk` key) and replace the inline `500` literals in `array_chunk($candidates, 500)`, `array_chunk($dominatedAbsent, 500)` (both call sites), and `->limit(500)` with the config lookup.
    - **Technical:** Category 4. Earlier in the same file, `$chunkSize = max(1, (int) config('partna.ingest.land_chunk', 500));` (line 56) already reads its batch size from config. The coverage-dominance sweep and absent-candidate pagination later in the same class instead use bare `500` literals with no config reference at all, so the same class has two different conventions for the same kind of value — tuning one path (e.g. to relieve DB load) silently leaves the other unaffected.
    - **Plain English:** One part of the ingest sweep already has a dial for its batch size; a few lines later, a very similar batching operation in the same file has that number welded in instead. If an operator turns down the dial to ease database load, the welded-in parts don't respond.
    - **Evidence:**
        ```php
        foreach (array_chunk($candidates, 500) as $chunk) {
        ...
        foreach (array_chunk($dominatedAbsent, 500) as $chunk) {
        ...
        ->limit(500)
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Extract hardcoded ingest/platform limits to `config/partna.php`:** #CFG-1, #CFG-2, #CFG-3, #CFG-4, #CFG-5
    - **Why grouped:** Same root-cause pattern (a private class constant governing an operational limit that should be a `config('partna.*')` lookup) and each is a mechanical, independent one-line-per-file change with no shared coupling risk.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

None.
