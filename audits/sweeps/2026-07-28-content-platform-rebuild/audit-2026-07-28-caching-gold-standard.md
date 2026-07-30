# Caching Gold-Standard Adherence Audit — 2026-07-28

**Branch:** development
**Lens:** Caching: gold-standard adherence — bundle scan across Group A (Cache services/key generators), Group B (Dashboard/API read paths), Group C (Write paths), Group D (Site/handle/capability resolution), Group E (Notifications/analytics/streaming), Group F (Catalog/Routing controllers)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Analytics/AnalyticsCacheService.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Http/Controllers/Api/PublicSite/IndividualProfileController.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Services/Platforms/IntegrationConnectionCacheRefresher.php
- app/Services/Platforms/CustomLinkSeeder.php
- app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php
- app/Http/Controllers/Api/Platforms/InstagramController.php
- app/Http/Controllers/Api/Platforms/ShopController.php
- app/Routing/Probes/ProbeGate.php
- app/Routing/Probes/LinkProbeWorker.php
- config/cache.php
- app/Http/Resources/Platforms/* (reviewed, no cache logic — clean)
- app/Services/Design/*, app/Services/Site/SiteCacheInvalidator.php (reviewed, no findings — clean)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 1 of 5 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#CCH-1** · P2 — Bespoke lock helpers construct `Cache::lock()` on the default store; connection-pinning claim needs verifying, not re-fixing
    - **Where:** app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php:333, :382; app/Http/Controllers/Api/Platforms/InstagramController.php:81, :269; app/Services/Platforms/CustomLinkSeeder.php:132
    - **Affects:** N/A — no action needed, see below.
    - **Effort:** N/A
    - **What to do:** None.
    - **Technical:** DRAFT findings across three chunks flagged these five call sites for constructing `Cache::lock($key, ...)` against the "default" store rather than `Cache::store('cache_locks')->lock(...)`. Checked against `config/cache.php`: the `redis` store (the app default, `CACHE_STORE` env defaults to `redis`) already sets `'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'cache_locks')`. Laravel's `RedisStore::lock()` resolves through this `lock_connection` config regardless of caller — `CacheLockService` gets no special routing that a bespoke `Cache::lock()` call lacks. All three draft findings (platforms-services CCH-1, platforms-controllers CCH-2, platforms-controllers CCH-3) rest on a false premise: the connection separation the gold standard requires is already enforced at the config layer, not something each call site must repeat. Dropped, not fixed — nothing here deviates from the standard.
    - **Plain English:** Three drafts flagged the same "lock in the wrong cabinet" pattern in five places. Checking the actual master config showed the cabinet assignment is already handled centrally — every lock in the app, however it's created, already lands in the dedicated compartment. No fix needed.
    - **Evidence:**
        ```php
        // config/cache.php — applies to every Cache::lock() call app-wide, not just CacheLockService's:
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'cache_locks'),
        ],
        ```

- [ ] **#CCH-2** · P2 — Probe cooldown cache uses plain `Cache::get` with no single-flight lock; two Horizon workers processing the same URL both run the full probe cascade
    - **Where:** app/Routing/Probes/ProbeGate.php:125-131 (`cachedAnswer`), app/Routing/Probes/ProbeGate.php:58-60 (`allows`), app/Routing/Probes/LinkProbeWorker.php:86-115 (`probe`)
    - **Affects:** Horizon workers running link-probe jobs — a batch import or two users pasting the same storefront URL close together can trigger duplicate 2–5-request probe cascades against the same third-party host, wasting probe budget and risking upstream rate-limiting.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `LinkProbeWorker::probe()`'s check→gate→cascade→remember flow in `CacheLockService::rememberLocked`, keyed on `CacheKeyGenerator::routingProbeUrl(...)`, so a concurrent caller blocks on the lock and reads the freshly-cached answer instead of re-running the cascade.
        - Move the `ProbeBudget::tryClaim` check inside the locked closure so a blocked caller doesn't spend budget only to discard it.
    - **Technical:** `ProbeGate::cachedAnswer()` is a bare `Cache::get()`; `LinkProbeWorker::probe()` checks it, then calls `allows()` (which checks it again), then runs the outbound cascade before writing the result back via `remember()`. The window between the miss and the write is unguarded — two workers that both see `null` both pass the budget gate and both run the full cascade. This is a queue-only path (per the class's own docstring — "Nothing here may be called from a request cycle"), so the blast radius is wasted probe budget and duplicate third-party traffic, not a user-facing outage; that bounds it to P2 rather than P1.
    - **Plain English:** Two workers can check "has anyone already looked into this link?" at the exact same moment, both see "no," and both go do the (relatively expensive) legwork of checking the storefront platforms one by one — when only one of them needed to. It's wasted effort, and if it happens on a busy import, it could look like abuse to the outside store's servers. Making the first worker's check-and-look "reserve" the job for a moment fixes it.
    - **Evidence:**
        ```php
        // ProbeGate::cachedAnswer — plain get, no lock
        public function cachedAnswer(Iri $iri): ?array
        {
            $url = $iri->canonical ?? $iri->raw;
            $cached = Cache::get(CacheKeyGenerator::routingProbeUrl($url));

            return is_array($cached) ? $cached : null;
        }
        ```
        ```php
        // LinkProbeWorker::probe — check-then-act race between cache read and cache write
        $cached = $this->gate->cachedAnswer($iri);
        if ($cached !== null) {
            return $this->fromCache($cached);
        }

        $decision = $this->gate->allows($iri, $userId);
        if (! $decision['allowed']) {
            return ProbeOutcome::refused((string) $decision['reason']);
        }

        $outcome = $this->run($iri);
        ```

- [ ] **#CCH-3** · P2 — `Cache::put` in `ProbeGate::remember` uses a `DateTimeInterface` TTL, bypassing the jitter helper
    - **Where:** app/Routing/Probes/ProbeGate.php:139-146
    - **Affects:** Probe cooldown entries written close together (bulk link imports) — all expire at the same wall-clock instant, causing a re-probe spike for those URLs 12 hours later.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `now()->addMinutes(...)` with a plain int-seconds TTL and route the write through `JitteredTtl::applyJitter($ttl)` (or through `CacheLockService`, which jitters automatically).
    - **Technical:** `Cache::put()` with a `DateTimeInterface` argument sidesteps `JitteredTtl::applyJitter()`, which only operates on integer TTLs. A batch of links imported together writes several `now()->addMinutes(...)` entries with near-identical expiry instants; twelve hours later they all lapse together, and every probe for those URLs re-runs its cascade in the same window — the exact synchronized-expiry pattern the jitter standard exists to prevent.
    - **Plain English:** Every probe answer gets a "good until" stamp set exactly 12 hours out. Import a batch of links together and dozens of these stamps land on the same minute — twelve hours later they all expire at once, and every one gets re-checked simultaneously instead of trickling in. A small random spread on the expiry avoids the pile-up.
    - **Evidence:**
        ```php
        public function remember(Iri $iri, array $answer): void
        {
            Cache::put(
                CacheKeyGenerator::routingProbeUrl($iri->canonical ?? $iri->raw),
                $answer,
                now()->addMinutes((int) config('partna.routing.probe.cooldown_minutes', self::COOLDOWN_MINUTES)),
            );
        }
        ```

- [x] **#CCH-4** · P2 — `IntegrationConnectionObserver::deleted()` and `restored()` don't roll the site's Redis profile-cache key forward for completeness-gated platforms
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:447-455 (`deleted()`), app/Observers/Core/IntegrationConnectionObserver.php:519-530 (`restored()`) — write sites. Read site: app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:122-139 (`public.profile:{handle}:{ts}` key, `$ts` sourced from `site.updated_at`).
    - **Affects:** Individual professionals' public sitepage cache — disconnecting or restoring a completeness-gated integration (Fresha and similar) purges the Cloudflare edge cache but leaves the application-level Redis payload cache keyed off the pre-change `site.updated_at`, so it still reflects the old connection state until an unrelated site write or TTL expiry rotates it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$connection->user?->site?->touch()` inside the same `hasCompletenessPredicate()` gate in `deleted()` and `restored()` that `saved()` already uses.
    - **Technical:** `saved()` correctly calls `$connection->user?->site?->touch()` for `hasCompletenessPredicate()` platforms specifically to roll the `public.profile:{handle}:{ts}` key forward (confirmed at `IndividualProfileController.php:122`, keyed by `site.updated_at`). `deleted()` and `restored()` only call `IntegrationConnectionCacheRefresher::refresh()`, which — confirmed by reading the class — purges only the Cloudflare edge cache (`CloudflareCachePurgeJob`) and never touches the site row. Laravel's `saved` event does not re-fire the `wasChanged('payload')`/`wasChanged('is_active')` gate meaningfully on a `restored()` call (the model's dirty-tracking state doesn't reflect a genuine payload/is_active change on restore), so `restored()` needs its own explicit touch, and `deleted()` never runs `saved()` at all. This is a genuine, unrelated gap from the `saved()`-cascade-cost finding already recorded in `audits/sweeps/2026-07-28-content-platform-rebuild/audit-2026-07-28-scaling-antipatterns.md` #CACHE-7 (which is about touch() being *too eager* on save, not missing on delete/restore) — no conflict with that prior finding.
    - **Plain English:** Connecting a booking platform like Fresha correctly makes the professional's page show a new section right away, because the code bumps a timestamp that rolls the cache forward. Disconnecting that same platform doesn't bump the same timestamp — so the outward-facing CDN cache clears (visitors eventually see the truth), but the backend's own cached copy of the page data still thinks the old connection exists. The same gap exists on reconnecting after a disconnect. It's a two-line fix mirroring what already happens on connect.
    - **Evidence:**
        ```php
        // saved() — correctly touches for completeness platforms:
        if (app(PlatformRegistry::class)->get($connection->platform)?->hasCompletenessPredicate()) {
            $connection->user?->site?->touch();
        }

        // deleted() — no touch():
        public function deleted(IntegrationConnection $connection): void
        {
            $this->refresher->refresh($connection);
            $this->cleanupMirroredMedia($connection);
            $this->retireEventSlugsOnDelete($connection);
            $this->syncIngestSource($connection);
        }

        // restored() — no touch():
        public function restored(IntegrationConnection $connection): void
        {
            $this->refresher->refresh($connection);
            $this->syncIngestSource($connection);

            if (in_array($connection->platform, EventSlugSync::PLATFORMS, true)) {
                $this->syncEventSlugs($connection);
            }
        }
        ```

- [ ] **#CCH-5** · P2 — `safeQuery` swallows `QueryException` and caches the degraded default inside the public sitepage payload's single-flight lock
    - **Where:** app/Services/PublicSite/SitepageDataResolverService.php:369-383 (`safeQuery`), consumed by `presentPageIds()` (e.g. lines 264, 273-280, 283-290), reached via app/Services/PublicSite/IndividualProfilePayloadBuilder.php → app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:124-139 (`CacheLockService::rememberLocked`)
    - **Affects:** Public sitepage visitors — a transient DB error (pool exhaustion, brief outage) during a presence probe (services/links/gallery existence checks) gets cached as "page section absent" for the full public-payload TTL, silently hiding a section of a professional's live page.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Let `QueryException` bubble out of `safeQuery` so it also escapes the `rememberLocked` closure — a failed regeneration must not populate the cache, and Nightwatch needs the throw to alert on it.
        - If graceful degradation of presence-detection is required, cache the negative result under a short, dedicated TTL (mirroring the `menuScrapeBlocked` negative-marker pattern) rather than letting it ride inside the full-TTL payload cache.
    - **Technical:** `safeQuery()` catches `QueryException` and returns the caller-supplied default (`false` for the boolean presence probes at `presentPageIds()`'s call sites). Traced the call chain: `IndividualProfileController::show()` wraps `$this->builder->build($pro, $site)` in `CacheLockService::rememberLocked` (confirmed at `IndividualProfileController.php:124-138`), and the builder's presence detection reaches `SitepageDataResolverService::presentPageIds()`, whose `safeQuery`-wrapped probes feed directly into the returned page list. A transient fault during that single regeneration therefore gets memoized as the "real" result for the whole payload TTL — a services/links/gallery page silently vanishing from the public sitepage until the next natural bust, with only a `Log::warning` (invisible to Nightwatch per the architecture doctrine — Nightwatch only fires on thrown exceptions).
    - **Plain English:** This code has a safety net that turns into a trap. If the database hiccups for a split second while checking "does this professional have a Gallery page?", the code just says "assume no" and moves on — and that assumption gets locked into the cache for as long as the whole page is cached. A one-second database blip can make a whole section of someone's public page disappear for minutes, with no error shown to anyone and nothing alerting the team.
    - **Evidence:**
        ```php
        private function safeQuery(\Closure $query, mixed $default, ?string $probe = null, ?Site $site = null): mixed
        {
            try {
                return $query();
            } catch (QueryException $e) {
                Log::warning('sitepage.presence_probe_failed', [
                    'probe' => $probe,
                    'site_id' => $site?->id,
                    'user_id' => $site?->user_id,
                    'error' => $e->getMessage(),
                ]);

                return $default;
            }
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Routing probe cache hardening:** #CCH-2, #CCH-3
    - **Why grouped:** Same file pair (`ProbeGate.php` + `LinkProbeWorker.php`), same subsystem (link-probe cooldown cache) — the single-flight fix and the jitter fix touch the same `remember()`/`cachedAnswer()` surface and are best reviewed together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#CCH-4 — IntegrationConnectionObserver deleted()/restored() cache-touch gap** · unrelated file/subsystem to the routing-probe bundle; small, isolated fix best reviewed on its own against the platform-connection lifecycle tests.
- **#CCH-5 — safeQuery cache-pollution on transient DB fault** · touches the public sitepage payload's cached closure directly; isolate from other bundles so its fix can be verified against `IndividualProfileController`'s cache tests without unrelated noise.
