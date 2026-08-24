# Caching Gold-Standard Adherence Audit — 2026-08-18

**Branch:** audit-fix/instagram-wave-findings-2026-08-18
**Lens:** Caching: gold-standard adherence — measuring every cache read/write against `CacheLockService::rememberLocked`, TTL jitter, stale-while-revalidate, push-invalidation, version-token, lock hygiene, bounded-TTL, and centralised-key-generation conventions (`CCH` prefix)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Http/Resources/Routing/RoutingConnectionResource.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php
- app/Services/Platforms/ConnectionDisplayName.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/Normalizers/MediumNormalizer.php
- app/Services/Platforms/NowBookitService.php
- app/Services/Platforms/Registry/DerivedDescriptorFactory.php
- app/Services/Platforms/Strategies/Connect/BrandLinkConnect.php
- app/Http/Controllers/Api/Platforms/DisplaySettingsController.php
- app/Http/Controllers/Api/Platforms/FreshaController.php
- app/Http/Controllers/Api/Platforms/GenericPlatformController.php
- app/Http/Controllers/Api/Platforms/RefreshController.php
- app/Http/Controllers/Api/Routing/RoutingController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **#CCH-1** · P2 · Category 4 — Instagram auto-sync flag write bypasses the observer's own cache-invalidation gate
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:184-193 (`enableContentInstagramAuto()`)
    - **Affects:** Newly-connected Instagram accounts; the Cloudflare edge purge / site-touch cascade that every other `display_settings` write triggers.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After the raw `DB::table('site.platform_connections')->update(...)`, call `$this->refresher->refresh($connection->fresh())` explicitly so the edge purge observes the post-strip `display_settings`.
        - Prefer this over reordering `saved()` — the strip has to run *after* the row exists, and the meaningful-change gate at the top of `saved()` has already evaluated by the time `enableContentInstagramAuto()` runs.
    - **Technical:** `saved()`'s "meaningful change" gate (lines 70-107) checks `wasRecentlyCreated || wasChanged('payload') || wasChanged('display_settings') || wasChanged('is_active')` and, when true, calls `$this->refresher->refresh($connection)` (a Cloudflare edge purge keyed off the connection's site) plus a conditional `$connection->user?->site?->touch()`. For a fresh Instagram connect, that gate already fires once — on `wasRecentlyCreated` — *before* `enableContentInstagramAuto()` runs at the bottom of `saved()`. `enableContentInstagramAuto()` then mutates `display_settings` via a raw `DB::table(...)->update()` that bypasses the Eloquent model entirely, so no `wasChanged('display_settings')` ever fires for it and no second invalidation is attempted. Per this repo's own written rule ("Any new write path that bypasses Eloquent... MUST invalidate the affected cache keys explicitly; it will not be caught by an observer" — CLAUDE.md, Cache/Queue), this write needed its own explicit invalidation call and doesn't have one. The practical exposure is narrow — `IntegrationConnectionCacheRefresher::refresh()` only dispatches an edge purge, not a Redis payload rebuild — but the purge that already ran reflects the pre-strip `display_settings`, so a purge triggered by an unrelated later write is the only thing that picks up the corrected auto-sync flag.
    - **Plain English:** When someone connects Instagram, the system sends out a "go refresh your copy of this page" notice right away — but then, a moment later in the same process, it quietly tweaks one more setting on that same connection without sending a second notice. It's like updating a memo after the courier has already left with the old version; the recipient doesn't see the correction until someone happens to send another memo later.
    - **Evidence:**
        ```php
        $settings = (array) ($connection->display_settings ?? []);
        if (($settings[AutoSyncSetting::KEY] ?? null) === false) {
            unset($settings[AutoSyncSetting::KEY]);
            DB::connection('pgsql')->table('site.platform_connections')
                ->where('id', $connection->id)
                ->update([
                    'display_settings' => $settings === [] ? null : json_encode($settings),
                    'updated_at' => now(),
                ]);
        }
        ```

- [x] **#CCH-2** · P2 · Category 4 — Deferred Fresha reconnect merges the payload, leaving the previous salon's `teamMenuCache` live under the new URL
    - **Where:** app/Http/Controllers/Api/Platforms/FreshaController.php:245-252 (write: `connectDeferred()`), app/Http/Controllers/Api/Platforms/FreshaController.php:356-359 (read: `team()`)
    - **Affects:** Fresha users on the deferred-connect flow (`config('partna.connect.deferred')` includes `fresha`) who reconnect to a *different* salon while the previous salon's up-to-24h `teamMenuCache` is still fresh.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `connectDeferred()`'s write, explicitly null out `teamMenuCache` / `teamMenuCachedAt` alongside the existing `'teamMenu' => null` clear.
        - Add a regression test that reconnects to a second URL via the deferred path and asserts `team()` (without `?refresh=1`) does not return the first salon's roster.
    - **Technical:** `connectDeferred()` calls `writeConnection($user, [...], pending: true)`. `writeConnection()` forwards `pending` straight into `upsertConnection(..., mergePayload: $pending, ...)`, and `upsertConnection()` merges rather than replaces when `mergePayload` is true: `$values['payload'] = [...($existing->payload ?? []), ...$values['payload']]`. The deferred write's payload — `{url, selection, connectMode, teamMenu: null, connectPendingAt, ...}` — carries no `teamMenuCache` key, so the merge preserves whatever `teamMenuCache`/`teamMenuCachedAt` the *previous* connection wrote. `team()` reads `payload['teamMenuCache']` and gates freshness purely on `teamMenuCachedAt` via `teamCacheIsFresh()` — it never checks that the cached roster's URL matches the current `payload['url']`. The synchronous (non-deferred) `connect()` path is unaffected: it calls `writeConnection()` without `pending: true`, so `mergePayload` is false and the payload is replaced wholesale, wiping the stale cache. This is therefore live only on the deferred-connect flow.
    - **Plain English:** If someone disconnects one salon on Fresha and connects a different one through the "instant" reconnect flow, the app can keep showing the *old* salon's staff list for up to a day, because the code only clears one of the two places that roster is remembered and quietly carries the other one forward into the new connection.
    - **Evidence:**
        ```php
        // Write path (connectDeferred) — clears teamMenu but not teamMenuCache
        $row = $this->writeConnection($user, [
            'url' => $url,
            'selection' => $existing->selection?->toArray(),
            'connectMode' => $mode,
            'teamMenu' => null,
            'connectPendingAt' => now()->toIso8601String(),
            ...($carriedRaw !== null ? ['raw' => $carriedRaw] : []),
        ], pending: true);
        ```
        ```php
        // Read path (team()) — serves teamMenuCache if fresh, no URL check
        $cached = is_array($payload['teamMenuCache'] ?? null) ? $payload['teamMenuCache'] : null;
        if ($cached !== null && ! $request->boolean('refresh') && $this->teamCacheIsFresh($payload)) {
            return $this->success(['url' => $url, ...$cached]);
        }
        ```

- [x] **#CCH-3** · P2 · Category 10 — `buildPools()` swallows `QueryException` on the hottest read path in the codebase and caches the empty result under the full TTL
    - **Where:** app/Services/PublicSite/IndividualProfilePayloadBuilder.php:241-248 (`buildPools()`), app/Services/PublicSite/IndividualProfilePayloadBuilder.php:690-693 (`lastBuildDegraded()`)
    - **Affects:** Visitors to `GET /api/public/profiles/{handle}` (via `IndividualProfileController` / `WarmPublicSiteCacheJob`) during any transient DB error hit while resolving a content pool; their watch/listen/media/menus/shop/services/custom-links sections vanish from the public payload for up to the full primary+stale cache window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Let `QueryException` bubble out of `buildPools()` in production so `rememberLocked`'s closure fails instead of caching a degraded payload, and Nightwatch surfaces the transient fault.
        - If a partial-payload fallback is still wanted for genuinely missing test-env tables, route it through the same degraded-state mechanism `SitepageDataResolverService::safeQuery()`/`hasDegraded()` already provides (and that `lastBuildDegraded()` reads) — e.g. flag the resolver degraded from `buildPools()` too — so the short `degradedCacheTtl()` window applies instead of the normal one.
    - **Technical:** This is the same class of bug already fixed once on this exact code path — `tests/Feature/PublicSite/DegradedPayloadTtlTest.php` (`#CCH-5`, `audits/archive/sweeps/2026-07-28-content-platform-rebuild/CONSOLIDATED.md`) shipped `SitepageDataResolverService::safeQuery()` setting `$this->degraded = true` on a caught `QueryException`, with `IndividualProfileController` reading `$this->builder->lastBuildDegraded()` afterward to shorten the cache entry via `shortenDegraded()`. That fix, however, only covers `SitepageDataResolverService`'s own probes. `buildPools()` has its own, separate `try { $this->pools->resolve($site, $pool); } catch (QueryException) { return []; }` around `PoolResolver::resolve()` that never touches `$this->resolver->hasDegraded()` — the same flag `lastBuildDegraded()` reads. A DB hiccup mid-pool-resolution therefore produces a payload with every pool silently empty, gets cached under the normal `cacheTtl()` (60s) plus the SWR `:stale` companion (10× that, per `CacheLockService`'s pattern) — up to roughly ten minutes of a professional's watch/listen/media/services/shop sections disappearing from their live public page, with no Nightwatch alert (the comment justifying the catch talks about "partial test envs" missing tables, but the catch itself is unconditional and equally swallows a real production connection blip).
    - **Plain English:** This is the single busiest page in the whole system — everyone's public profile. If the database has a one-second hiccup while the code is gathering someone's videos, playlists, or shop items, this bit of code shrugs and pretends there simply aren't any, then saves that "empty" version to the fast cache for potentially ten minutes. A blink-and-you'd-miss-it database blip can make a chunk of a real person's page disappear for visitors long after the database is fine again, and nobody on the team gets alerted. There's already a mechanism elsewhere in this same file that handles exactly this kind of hiccup correctly (short cache, alarm raised) — this one code path just doesn't use it.
    - **Evidence:**
        ```php
        try {
            $resolved = $this->pools->resolve($site, $pool);
        } catch (QueryException) {
            // Partial test envs may not provision the content/sections
            // tables (the getContentMedia precedent); in production they
            // always exist. A missing lane yields no pools, never a 500.
            return [];
        }
        ```
        ```php
        public function lastBuildDegraded(): bool
        {
            return $this->resolver->hasDegraded();
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Fresha payload-merge cache staleness:** #CCH-2
    - **Why grouped:** single-item bundle — isolated to `FreshaController`'s deferred-connect write path, no shared file/root-cause with the other findings.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — IntegrationConnectionObserver missed invalidation:** #CCH-1
    - **Why grouped:** single-item bundle — isolated to one observer method, unrelated to the other two files.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Public payload degraded-cache gap:** #CCH-3
    - **Why grouped:** single-item bundle, but should be reviewed against the existing `DegradedPayloadTtlTest.php` suite and `SitepageDataResolverService::hasDegraded()` mechanism to keep both degradation paths consistent.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet — this sits on the public sitepage payload path, so review should re-run `tests/Feature/PublicSite/DegradedPayloadTtlTest.php` and `tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php` explicitly, not just the new test.

## Standalone — do NOT bundle

None — no P0, auth, money, migration/schema, or L/XL-effort findings survived adjudication.
