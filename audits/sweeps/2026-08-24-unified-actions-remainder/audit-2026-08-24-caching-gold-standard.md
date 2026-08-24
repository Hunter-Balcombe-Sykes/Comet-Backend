Line numbers confirmed match the file exactly. Now producing the final adjudicated audit.

# Caching: gold-standard adherence Audit — 2026-08-24

**Branch:** development
**Lens:** Caching: gold-standard adherence — deviations from `CacheLockService::rememberLocked` / `SiteCacheService::getPublicSitePayload` across single-flight locking, TTL jitter, SWR, push-invalidation, version tokens, lock hygiene, TTL boundedness, and key-generation centralisation.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Routing/ShortLinkExpander.php
- app/Routing/Probes/LinkProbeWorker.php
- app/Routing/Probes/ProbeGate.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Services/Analytics/AnalyticsDedupGuard.php
- app/Services/Analytics/Ingestors/QueuedIngestor.php
- app/Services/Analytics/Ingestors/SyncIngestor.php
- app/Services/Analytics/ContentPopularityReader.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Services/Platforms/Registry/PlatformDescriptor.php
- app/Services/Platforms/AppleSearch.php
- app/Jobs/Platforms/ShopBrandConnectJob.php
- app/Http/Controllers/Api/Platforms/ShopController.php
- app/Http/Controllers/Api/Routing/SuggestionsController.php
- app/Services/Brand/StoreBrandSeeder.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 11 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **CCH-1** · P1 — `AppleSearch::itunes()` reads/writes a globally-shared iTunes cache key with no single-flight lock
    - **Where:** app/Services/Platforms/AppleSearch.php:109-133
    - **Affects:** Every user whose sitepage or dashboard triggers an Apple Music/Podcasts lookup for the same artist/show/genre — the cache key is global (not scoped per user), and iTunes's keyless endpoint is a shared ~20 req/min/IP budget across the whole fleet.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inject `App\Services\Cache\CacheLockService`.
        - Replace the `Cache::get`/`Cache::put` pair with `$this->cache->rememberLocked(CacheKeyGenerator::itunesResponse($path), (int) config('partna.refresh.host_limits.itunes.cache_ttl_seconds'), fn () => $this->fetchDecoded($path))`, keeping the existing "only cache a valid decoded response" gate inside the closure.
    - **Technical:** `itunes()` does `Cache::get($key)` → on miss, `SafeUrlFetcher::tryFetch()` → `Cache::put($key, $json, $ttl)`, with no `Cache::lock` anywhere in the path. Because the key (`CacheKeyGenerator::itunesResponse($path)`) is not scoped per user, any two concurrent connect/refresh operations touching the same artist, podcast, or genre lookup (a popular artist being connected by several users around the same time, or the 6-hourly refresh cron firing across many sites at once) each independently re-hit `itunes.apple.com`. The class's own comment notes iTunes is "keyless, ~20 req/min/IP" — a shared, easily-exhausted budget — so an uncoordinated stampede here can degrade or 429 the lookup for every user on the platform, not just the ones racing. `CacheLockService::rememberLocked` elects a single regenerator and blocks/serves-stale for the rest.
    - **Plain English:** Our system asks Apple for the same artist's info from multiple places at once instead of one person asking and sharing the answer. Apple only allows us a small number of requests per minute total, shared across every user — so a pile-up here can lock everyone out of Apple Music lookups for a while, not just the person who caused it.
    - **Evidence:**
        ```php
        $key = CacheKeyGenerator::itunesResponse($path);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $res = $this->fetcher->tryFetch('https://itunes.apple.com'.$path, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }
        $json = json_decode($res['body'], true);
        if (! is_array($json)) {
            return null;
        }

        Cache::put($key, $json, (int) config('partna.refresh.host_limits.itunes.cache_ttl_seconds'));

        return $json;
        ```

- [ ] **CCH-2** · P1 — `ShortLinkExpander::expandIfShort()` reads/writes a globally-shared short-link cache key with no single-flight lock
    - **Where:** app/Routing/ShortLinkExpander.php:68-103
    - **Affects:** Every paste-preview, link-in-bio import, and routing pass across every user — the cache key (`'shortlink:'.sha1($url)`) is global, so a trending short link shared by many users (or re-scanned across many sites in a batch import) triggers one outbound fetch per concurrent miss.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Inject `App\Services\Cache\CacheLockService` into `ShortLinkExpander`.
        - Replace `Cache::get` + `Cache::put` with `$this->cache->rememberLocked($key, $ttl, fn () => ...)` for the success path.
        - Keep the negative-cache (failure) case short-TTL and explicit — `rememberLockedNullable` is a reasonable fit if the "empty string means give up" sentinel is reframed as `null`.
    - **Technical:** The method does an unlocked `Cache::get`, then — on miss — an unlocked `SafeUrlFetcher::tryFetch($url)` call, then an unlocked `Cache::put`. Because the key is a hash of the URL alone (not user-scoped), concurrent callers across different users/imports that miss on the same short URL at the same time all independently fetch it. `CacheLockService::rememberLocked` would single-flight the regeneration.
    - **Plain English:** If ten people paste or import the same short link at once, our server calls the external website ten times instead of once. A lock makes it one phone call with nine people waiting for (or getting a recent cached copy of) the same result.
    - **Evidence:**
        ```php
        $key = 'shortlink:'.sha1($url);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached === '' ? $url : $cached;
        }

        $final = null;

        try {
            $result = $this->fetcher->tryFetch($url);
            $candidate = $result['finalUrl'] ?? null;

            if (is_string($candidate)
                && $candidate !== ''
                && ! $this->isShort($candidate)
                && preg_match('~^https?://~i', $candidate) === 1
            ) {
                $final = $candidate;
            }
        } catch (\Throwable) {
            // tryFetch already swallows the expected failure shapes; anything
            // else (budget exhaustion mid-run) is equally a "keep the URL".
        }

        Cache::put($key, $final ?? '', $final === null ? self::FAILURE_TTL_SECONDS : self::SUCCESS_TTL_SECONDS);

        return $final ?? $url;
        ```

## P2 — Should fix

- [ ] **CCH-3** · P2 — `ShortLinkExpander` writes hardcoded TTLs with no jitter
    - **Where:** app/Routing/ShortLinkExpander.php:52-54, 101
    - **Affects:** Short-link cache entries expire in synchronised lockstep across the fleet, risking a coordinated refetch burst at the expiry boundary.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Fixed by the CCH-2 rewrite (`CacheLockService` jitters automatically) — if that lands first, this closes for free.
        - Standalone: call `JitteredTtl::applyJitter($ttl)` at the write site before `Cache::put`.
    - **Technical:** `Cache::put($key, $final ?? '', $final === null ? self::FAILURE_TTL_SECONDS : self::SUCCESS_TTL_SECONDS)` receives literal `86400`/`3600` from class constants with no jitter applied. Entries created around the same time (e.g. during a batch link-in-bio import wave) expire at exactly the same moment.
    - **Plain English:** Every cached short link currently expires at the exact same relative time, like every parking meter in a street running out at once. Staggering expiry times slightly means everyone doesn't rush to refetch at the same moment.
    - **Evidence:**
        ```php
        private const SUCCESS_TTL_SECONDS = 86400;

        private const FAILURE_TTL_SECONDS = 3600;
        // ...
        Cache::put($key, $final ?? '', $final === null ? self::FAILURE_TTL_SECONDS : self::SUCCESS_TTL_SECONDS);
        ```

- [ ] **CCH-4** · P2 — `ShortLinkExpander` has no stale-while-revalidate companion
    - **Where:** app/Routing/ShortLinkExpander.php:68-103
    - **Affects:** Callers arriving after a short-link cache entry expires all block on a live external fetch instead of one caller regenerating while the rest read last-good.
    - **Effort:** M (~2–4h) — folds into the CCH-2 fix
    - **What to do:**
        - Standardise on `CacheLockService::rememberLocked`, which maintains the `$key:stale` companion automatically.
    - **Technical:** The `Cache::get` miss → synchronous fetch → `Cache::put` sequence has no `:stale` companion. When the primary key expires, every concurrent caller recomputes synchronously.
    - **Plain English:** When a stored short link expires, everyone currently has to wait while the server checks again — a queue forms. The gold standard keeps yesterday's answer to hand out immediately while one worker quietly refreshes it.
    - **Evidence:**
        ```php
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached === '' ? $url : $cached;
        }
        ```

- [ ] **CCH-5** · P2 — `ShortLinkExpander` swallows every exception and caches an invisible negative sentinel
    - **Where:** app/Routing/ShortLinkExpander.php:96-101
    - **Affects:** Error observability. A defect or budget exhaustion is cached as "not expandable" for up to an hour with nothing reaching Nightwatch.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `report($e)` on the catch before falling through to the negative-cache write (or narrow the catch to the specific throwable shapes that are genuinely expected).
    - **Technical:** `catch (\Throwable) {}` discards everything, and the subsequent `Cache::put($key, '', self::FAILURE_TTL_SECONDS)` persists an empty-string negative-cache sentinel. A real defect becomes a fleet-wide stale-empty for up to an hour with no exception surfaced anywhere Nightwatch can see it — `Log::warning`-equivalent silence via the bare catch.
    - **Plain English:** If something goes wrong while checking a short link, the system quietly writes down "nothing to see here" and doesn't tell anyone. For the next hour every user gets that wrong answer. We should at least sound an alert when that happens.
    - **Evidence:**
        ```php
        } catch (\Throwable) {
            // tryFetch already swallows the expected failure shapes; anything
            // else (budget exhaustion mid-run) is equally a "keep the URL".
        }

        Cache::put($key, $final ?? '', $final === null ? self::FAILURE_TTL_SECONDS : self::SUCCESS_TTL_SECONDS);
        ```

- [ ] **CCH-6** · P2 — `AppleSearch::itunes()` writes a literal int TTL with no jitter
    - **Where:** app/Services/Platforms/AppleSearch.php:130
    - **Affects:** All cached iTunes responses; synchronised TTL expiry across the fleet.
    - **Effort:** S (~0.5–1h) — folds into the CCH-1 fix
    - **What to do:**
        - Fixed for free by the CCH-1 `CacheLockService` rewrite; standalone, call `JitteredTtl::applyJitter($ttl)` before `Cache::put`.
    - **Technical:** `Cache::put($key, $json, (int) config('partna.refresh.host_limits.itunes.cache_ttl_seconds'))` writes a raw config-driven TTL with no jitter.
    - **Plain English:** All our Apple notes have the exact same expiry time, so they all vanish at once and every server rushes Apple at the same moment.
    - **Evidence:**
        ```php
        Cache::put($key, $json, (int) config('partna.refresh.host_limits.itunes.cache_ttl_seconds'));
        ```

- [ ] **CCH-7** · P2 — `AppleSearch::itunes()` missing stale-while-revalidate companion
    - **Where:** app/Services/Platforms/AppleSearch.php:109-133
    - **Affects:** Apple Music/Podcasts readers during primary-key expiry; no last-good fallback while a fresh lookup runs.
    - **Effort:** S (~0.5–1h) — folds into the CCH-1 fix
    - **What to do:**
        - Standardise this read on `CacheLockService::rememberLocked`.
    - **Technical:** The `Cache::get` + fallback-fetch + `Cache::put` sequence has no `:stale` companion, so an expired key forces every concurrent caller onto a synchronous network fetch.
    - **Plain English:** If Apple is slow, we could keep showing the last good answer while one worker quietly fetches a fresh one instead of making everyone wait.
    - **Evidence:**
        ```php
        $key = CacheKeyGenerator::itunesResponse($path);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        ```

- [ ] **CCH-8** · P2 — `ShopBrandConnectJob`'s settle lock uses the default cache store instead of the dedicated `cache_locks` connection
    - **Where:** app/Jobs/Platforms/ShopBrandConnectJob.php:173-176
    - **Affects:** The compare-and-set write that settles a connecting shop brand — if `Cache::flush()` is ever run against the default data store, this held lock releases early, letting a second writer race the same guarded update.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::lock($key, 10)` with `Cache::store('cache_locks')->lock($key, 10)`, keeping `->block(5, ...)`.
        - Key generation (`CacheKeyGenerator::platformConnectionLock`) is already correct — only the store binding needs to change.
    - **Technical:** Gold-standard lock hygiene requires lock keys to live on the `cache_locks` Redis connection so `Cache::flush()` on the data store can't release a held lock mid-critical-section. This bespoke lock guards a compare-and-set write (`settle()`/`markTerminal()` on `connect_status = 'pending'`) and uses the default store instead.
    - **Plain English:** Our padlock is kept on the same shelf as the data it's protecting. If that shelf is ever wiped, the padlock disappears too, and two workers could edit the same shop record at once. Put the padlock in a separate, dedicated cabinet.
    - **Evidence:**
        ```php
        try {
            Cache::lock($key, 10)->block(5, function () use ($store, $profile, &$settled) {
                $settled = $this->settle($store, $profile);
            });
        } catch (LockTimeoutException $e) {
        ```

- [ ] **CCH-9** · P2 — `SuggestionsController::acceptPayloadFinding` lock uses the default cache store instead of `cache_locks`
    - **Where:** app/Http/Controllers/Api/Routing/SuggestionsController.php:348-349
    - **Affects:** Contended per-user platform-connection settlement writes — the same `Cache::flush()`-releases-a-held-lock exposure as CCH-8, on a different call site of the same `CacheKeyGenerator::platformConnectionLock` lock.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `Cache::store('cache_locks')->lock(CacheKeyGenerator::platformConnectionLock($located['holder'], (string) $user->id), 10)->block(5, ...)`.
    - **Technical:** Same root cause as CCH-8 — a bespoke `Cache::lock()` bypassing the dedicated `cache_locks` connection that `CacheLockService` derives automatically. This settlement races `GoogleBusinessEnrichJob::persist()` rewriting the same payload under the same key (per the code's own comment), which is exactly the contended span lock hygiene exists to protect.
    - **Plain English:** Same issue as the shop-connect job: the lock lives in the wrong place, so an emergency cache wipe could accidentally let two saves overlap on the same user's connection.
    - **Evidence:**
        ```php
        Cache::lock(CacheKeyGenerator::platformConnectionLock($located['holder'], (string) $user->id), 10)
            ->block(5, fn () => $this->bridge->settlePayloadFinding($located['connection'], $located['index'], 'seeded'));
        ```

- [ ] **CCH-10** · P2 — `ShopController::brandProducts` product-picker cache has no single-flight lock
    - **Where:** app/Http/Controllers/Api/Platforms/ShopController.php:839-843
    - **Affects:** The authenticated owner of a connected shop brand, when the picker's 10-minute cache is cold — repeated opens/reloads within the same window each independently re-scrape the upstream store.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inject `CacheLockService` and replace the `Cache::remember` call with `$this->cache->rememberLocked($this->catalogKey($id), self::applyJitter(self::CATALOG_TTL_MINUTES * 60), fn () => ...)`.
    - **Technical:** `Cache::remember` takes no lock; jitter is already correctly applied via `JitteredTtl::applyJitter` (`app/Http/Controllers/Api/Platforms/ShopController.php:100,841` — no separate finding needed for that half). The cache key is per-brand (`catalogKey($id)`, scoped to one connected store), so concurrent misses are bounded to that one owner's own overlapping requests (double-click, multiple tabs) rather than a fleet-wide, cross-user stampede — this is a materially smaller blast radius than CCH-1/CCH-2's globally-shared keys, hence P2 rather than P1.
    - **Plain English:** If the same owner opens the product picker in two tabs at once while the cache is cold, we scrape their store twice instead of once. Low-stakes, but still wasted work and vendor load worth fixing with the same one-at-a-time pattern used elsewhere.
    - **Evidence:**
        ```php
        $products = Cache::remember(
            $this->catalogKey($id),
            self::applyJitter(self::CATALOG_TTL_MINUTES * 60),
            fn () => $this->budget->open($seconds, fn () => $this->providerProducts($map[$id])),
        );
        ```

- [ ] **CCH-11** · P2 — `ContentPopularityReader` caches a query failure as a valid empty ranking behind the public-profile cache
    - **Where:** app/Services/Analytics/ContentPopularityReader.php:39-51 (`forSite`), 68-83 (`actionScoresForSite`), 125-140 (`itemScoresForSite`)
    - **Affects:** Public sitepage visitors — a transient `analytics.content_popularity_scores` query failure is silently absorbed as "no ranks" and, per the class's own docblock, this read sits "behind the 60s public-profile cache," so the empty result can be cached fleet-wide for that window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Let `QueryException` bubble from all three methods instead of returning `[]`, so a real outage surfaces to Nightwatch instead of degrading silently.
        - If fail-open behaviour is genuinely wanted for a degraded-analytics UX, move the catch outside the cached-payload boundary so the failure itself isn't what gets cached, or document the trade-off explicitly.
    - **Technical:** Each method wraps its `DB::connection('pgsql')->table(...)->get(...)` in a `try { } catch (QueryException $e) { Log::warning(...); return []; }`. `Log::warning` is invisible to Nightwatch. Because the docblock states these reads happen "behind the 60s public-profile cache," a caught failure produces a successful-looking empty return that the outer cache layer has no way to distinguish from "genuinely no ranks yet" — the analytics blip is cached as truth for the TTL.
    - **Plain English:** If the scoreboard database hiccups once, the page quietly saves a blank scoreboard and shows it to every visitor for the next minute, with no alarm raised. Better to let the alarm ring so someone notices, rather than hiding the hiccup behind a wrong-but-successful-looking answer.
    - **Evidence:**
        ```php
        try {
            $rows = DB::connection('pgsql')
                ->table('analytics.content_popularity_scores')
                ->where('site_id', $siteId)
                ->where('content_type', '!=', ActionScorer::CONTENT_TYPE)
                ->orderBy('content_type')
                ->orderBy('rank')
                ->get(['content_type', 'content_key', 'rank']);
        } catch (QueryException $e) {
            Log::warning('analytics.popularity_read_failed', ['site_id' => $siteId, 'error' => $e->getMessage()]);

            return [];
        }
        ```

- [ ] **CCH-12** · P2 — Google Business listing disconnect clears public workplace fields without rotating the public-profile cache key
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:304-335 (write, `clearListingSourcedWorkplaceFields`); read: `SiteCacheService`'s timestamp-keyed `public.profile:{handle}:{ts}` (`app/Services/Cache/CacheKeyGenerator.php:315-317`, `app/Services/Cache/SiteCacheService.php:661-663`)
    - **Affects:** Public sitepage visitors — after a Google Business listing is disconnected, the previously-listing-sourced description/category/previous-website fields can keep showing the old values until the profile cache's TTL lapses.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `google-business` to the set of platforms whose disconnect rolls `site.updated_at` (via `PlatformDescriptor::complete()`'s completeness gate, or a separate, explicit touch for this specific write), or call `$connection->user?->site?->touch()` directly at the end of `clearListingSourcedWorkplaceFields()`.
    - **Technical:** `deleted()`'s `Site::query()->where('user_id', ...)->first()?->touch()` (line 232-234) is gated on `PlatformDescriptor::hasCompletenessPredicate()`, and `->complete()` is opted into by exactly two platforms today — `fresha` and `shop` (per `PlatformDescriptor.php:576-581`'s own docblock and confirmed by `tests/Feature/Architecture/DastIdorCoverageDriftTest.php:324`'s "'shop' is one of exactly two `hasCompletenessPredicate()` platforms" comment). `google-business` is not one of them, so the site-touch that rolls the `public.profile:{handle}:{ts}` key never fires for a google-business disconnect, even though `clearListingSourcedWorkplaceFields()` — called later in the same `deleted()` hook — performs a real `$workplace->save()` that changes public-facing fields.
    - **Plain English:** When someone disconnects their Google Business listing, the system removes the old business bio from the database but keeps showing the old version on the public page until the cache naturally expires. The page needs to be told "this just changed, show the new version now" at the moment the change happens.
    - **Evidence:**
        ```php
        private function clearListingSourcedWorkplaceFields(IntegrationConnection $connection): void
        {
            if ($connection->surface_key !== 'google_business.listing') {
                return;
            }

            try {
                $site = Site::query()->where('user_id', $connection->user_id)->first();
                $workplace = $site === null ? null : Workplace::query()->where('site_id', (string) $site->id)->first();
                if ($workplace === null) {
                    return;
                }

                $sources = is_array($workplace->field_sources) ? $workplace->field_sources : [];
                $changed = false;
                foreach (['previous_website', 'category', 'description'] as $key) {
                    $source = $sources[$key]['source'] ?? null;
                    if (in_array($source, ['google-business', 'website-scan'], true)) {
                        $workplace->{$key} = null;
                        unset($sources[$key]);
                        $changed = true;
                    }
                }

                if ($changed) {
                    $workplace->field_sources = $sources;
                    $workplace->save();
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
        ```

- [ ] **CCH-13** · P2 — Google Business identity sync writes public identity fields without rotating the public-profile cache key
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:117-120 (`saved()` call site), 158-167 (`syncIdentityFromGoogle`); read: same `public.profile:{handle}:{ts}` key as CCH-12
    - **Affects:** Public sitepage visitors — a Google Business connect or a payload refresh folds identity fields (name, etc.) into workplace/user rows via `IdentitySync`, but the same `hasCompletenessPredicate()` gate that excludes `google-business` (see CCH-12) means the profile cache key doesn't rotate for this write either.
    - **Effort:** S (~0.5–1h) — same fix as CCH-12; bundle together
    - **What to do:**
        - Same remediation as CCH-12 — once google-business disconnect/connect/refresh rolls the site touch unconditionally (or is added to the completeness-gated set), this closes for free.
    - **Technical:** `saved()`'s meaningful-change block (lines 72-109) only touches the site when `hasCompletenessPredicate()` is true, which excludes google-business (per CCH-12). `syncIdentityFromGoogle()` fires on `wasRecentlyCreated || wasChanged('payload')` (line 117-119) — the exact same condition set that gates the meaningful-change block — so a google-business connect or payload refresh that changes identity-fold data never rotates the public-profile cache key, even though `IdentitySync::applyFromGooglePayload()` writes fields that can appear on the public page.
    - **Plain English:** Connecting or refreshing a Google Business listing updates the business's name and details behind the scenes, but the public page doesn't get told to refresh, so visitors can see outdated info until the cache times out on its own.
    - **Evidence:**
        ```php
        if ($connection->platform === Platform::GoogleBusiness->value
            && ($connection->wasRecentlyCreated || $connection->wasChanged('payload'))) {
            $this->syncIdentityFromGoogle($connection);
        }
        ```

## P3 — Nice to have

- [ ] **CCH-14** · P3 — `ShortLinkExpander` builds its cache key ad-hoc instead of through `CacheKeyGenerator`
    - **Where:** app/Routing/ShortLinkExpander.php:68
    - **Affects:** Cache-key consistency; a future second writer/reader constructing the key differently would silently miss.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CacheKeyGenerator::shortLink(string $url): string` and use it for both read and write, replacing the inline `'shortlink:'.sha1($url)`.
    - **Technical:** The key is built directly at the call site via string concatenation and `sha1()` rather than through the centralised generator every other cache read in the codebase uses. Low impact today (only one call site), but it's the exact drift shape the gold standard's key-centralisation rule exists to prevent.
    - **Plain English:** The cache key is written on a sticky note instead of in the shared address book. If someone writes it down slightly differently somewhere else later, lookups silently miss.
    - **Evidence:**
        ```php
        $key = 'shortlink:'.sha1($url);
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Global-key stampede fixes on external-fetch caches:** CCH-1, CCH-2, CCH-3, CCH-4, CCH-5, CCH-6, CCH-7, CCH-14
    - **Why grouped:** All in `AppleSearch`/`ShortLinkExpander`; all resolve to "route the read through `CacheLockService::rememberLocked`," which closes the single-flight, jitter, and SWR gaps in one shot per file. CCH-14's key-centralisation fits naturally into the same `ShortLinkExpander` touch.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Lock connection hygiene:** CCH-8, CCH-9
    - **Why grouped:** Same root cause (`Cache::lock()` on the default store instead of `cache_locks`) on the same `CacheKeyGenerator::platformConnectionLock` key, two different call sites.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Google Business disconnect/connect cache-touch gap:** CCH-12, CCH-13
    - **Why grouped:** Same root cause (`google-business` excluded from `hasCompletenessPredicate()`'s site-touch gate) across `deleted()` and `saved()` in the same observer — one fix closes both.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Fail-open read swallows a real error as cached truth:** CCH-11
    - **Why grouped:** Standalone-sized but low-risk (three sibling methods in one file, same fix shape) — could ride with Bundle 3 if the same session has capacity, otherwise its own small session.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **CCH-10 — `ShopController::brandProducts` missing single-flight lock** · touches an authenticated dashboard write-adjacent read path (product picker feeding `setProducts`); run with its own plan/review rather than folding into Bundle 1 so the picker's existing budget/error-handling contract (the 502→422 remap) isn't disturbed by an unrelated batch of edits.
