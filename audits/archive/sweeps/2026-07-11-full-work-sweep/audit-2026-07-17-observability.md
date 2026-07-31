# Observability Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Observability: logging gaps, silent failures, missing Nightwatch instrumentation — jobs that swallow exceptions, inbound callbacks that 200-but-don't-process, missing Nightwatch coverage, log calls that obscure rather than illuminate
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Console/Commands/CleanupOrphanedLifestyleConnections.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Services/Cloudflare/CloudflareCustomHostnameService.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Platforms/BigCartelScraper.php
- app/Services/Platforms/DoorDashMenuDriver.php
- app/Services/Platforms/GenericShopScraper.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/IdentitySync.php
- app/Services/Platforms/InstagramAutoSync.php
- app/Services/Platforms/InstagramScraper.php
- app/Services/Platforms/MenuMerger.php
- app/Services/Platforms/MenuScanApplier.php
- app/Services/Platforms/Normalizers/FacebookNormalizer.php
- app/Services/Platforms/Payloads/InstagramPayload.php
- app/Services/Platforms/PlatformScraper.php
- app/Services/Platforms/Registry/PlatformDescriptor.php
- app/Services/Platforms/ShopifyScraper.php
- app/Services/Platforms/UberEatsMenuDriver.php
- app/Services/Platforms/WebsiteLinkHarvester.php
- app/Services/Platforms/WooCommerceScraper.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **OBS-1** · P2 — `CloudflarePurgeService` logs a product-purge degradation at `debug`, a level Nightwatch's own log filter drops by default
    - **Where:** app/Services/Cloudflare/CloudflarePurgeService.php:129-146 (`purgeHandle`'s product-handle lookup)
    - **Affects:** Shop product-detail edge-cache invalidation for every Partna storefront — a sustained DB/schema failure on the product-handle join silently degrades every purge to "pages only," and product pages never get busted again until their natural edge TTL, with nothing surfacing to on-call.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Bump `Log::debug` to at least `Log::warning` and add `report($e)`, matching the codebase's own established pattern for "must never break the caller, but a genuine failure must still reach Nightwatch" (the identical fix — `report($e)` in an isolated best-effort catch — is already applied throughout `app/Jobs/Platforms/InstagramConnectJob.php`'s R2-mirror catches).
        - Add a regression test exercising the catch path itself (force the product-handle join to error, e.g. by dropping/renaming a joined table in a Pest test) — `CloudflarePurgeServiceTest.php` currently has 10 passing cases and every one of them hits the query's success path; none exercises the degrade-to-pages-only branch.
    - **Technical:** `config/nightwatch.php`'s `filtering.log_level` defaults to `env('LOG_LEVEL', 'warning')` — Nightwatch's own log-shipping pipeline drops anything below `warning` out of the box, so a `Log::debug` call in this catch block is invisible to Nightwatch by construction, independent of whatever the app's own `LOG_LEVEL` is set to. This code was added recently (`7c753f7f fix(cache): purgeHandle also purges shop product detail pages`) specifically to close a 24h staleness gap for product pages; the try/catch around the DB join is correctly scoped ("never let this optional lookup break the purge itself"), but the failure path it protects against reintroduces the exact staleness problem the commit fixed, just silently, for however long the underlying join stays broken.
    - **Plain English:** When someone updates their shop, the system clears the cached copy of every page so visitors see the newest version. Finding which specific product pages to clear requires one extra database lookup; if that lookup breaks, the code correctly skips it rather than failing the whole cache-clear — but it writes the failure to a logbook page that the monitoring system is configured to never read. If this lookup breaks for good (not just a blip), product pages could stay stale indefinitely and nobody would be told.
    - **Evidence:**
        ```php
        try {
            $productHandles = DB::connection('pgsql')->table('site.shop_products as p')
                // … joins and query …
                ->pluck('product_handle')
                ->all();
        } catch (\Throwable $e) {
            Log::debug('CloudflarePurgeService: product-handle lookup failed, purging pages only', ['handle' => $h, 'error' => $e->getMessage()]);
            $productHandles = [];
        }
        ```

## P3 — Nice to have

- [ ] **OBS-2** · P3 — `InstagramScraper::latestMedia` emits an unconditional `Log::info` diagnostic on every scrape
    - **Where:** app/Services/Platforms/InstagramScraper.php:208-216
    - **Affects:** `cloud env:logs partna development` signal-to-noise for anyone manually triaging Instagram-related issues — every profile scrape (connect + periodic refresh) writes a structured info entry regardless of outcome.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Gate the `Log::info` behind `app()->isLocal()`, or drop it once the reels-ordering question it was added to answer is confirmed resolved — the comment marks it as a point-in-time diagnostic, not a permanent operational signal.
    - **Technical:** `config/nightwatch.php`'s `filtering.log_level` defaults to `warning`, so this `Log::info` call was never reaching Nightwatch's alert stream in the first place — the cost here is purely operational-log noise in `cloud env:logs`, not a missed alert. The comment ("confirms from the dev logs whether Apify is returning reels at all for this account") reads as a temporary investigation aid left in place after the investigation concluded.
    - **Plain English:** Every time someone connects or refreshes their Instagram, the system writes a diagnostic note into the operational logbook, even when everything worked fine. It doesn't trigger any alerts, but it does mean anyone scrolling through the logs to debug a real problem has to wade through a note for every single successful scrape too.
    - **Evidence:**
        ```php
        Log::info('instagram.latest_media', [
            'user_id' => $userId,
            'posts' => count($posts),
            'videos' => count(array_filter($posts, fn ($p) => is_array($p) && data_get($p, 'type') === 'Video')),
            'picked_photo' => $photo !== null,
            'picked_video' => $video !== null,
        ]);
        ```

- [ ] **OBS-3** · P3 — `CloudflareCustomHostnameService::delete()` never checks the API response, so the 3 call sites that already catch its failures never receive one
    - **Where:** app/Services/Cloudflare/CloudflareCustomHostnameService.php:91-98
    - **Affects:** Custom-domain disconnect/cleanup for users with a connected domain — a failed Cloudflare hostname deletion (bad token, transient outage, rate limit) leaves the certificate/hostname active on Cloudflare's zone while Partna's own `site.custom_domain*` columns show it disconnected. A lingering hostname can also 409 a future `create()` attempt to reuse that same domain.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->throw()` after `->timeout(5)->delete($this->base()."/{$id}")`. All three current call sites in `app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php` (lines 61, 92, 172) already wrap `$this->cf->delete(...)` in `catch (Throwable $e) { report($e); }` — they're dead code today because `delete()` can't throw on a non-2xx response; adding `->throw()` alone makes the failure Nightwatch-visible with zero other changes.
        - Add a test file for `CloudflareCustomHostnameService` — none currently exists (`create()`/`get()`/`delete()` all have zero direct test coverage), including a case asserting a non-2xx `delete()` response throws.
    - **Technical:** Guzzle/`Http::` returns a `Response` object for every status code unless `->throw()` (or an explicit status check) is added — `create()` and `get()` in this same class both call `->throw()` correctly, but `delete()` was written without it, silently discarding 401/404/500 responses. Because every caller of `delete()` already anticipates and reports a `Throwable`, this is a one-line fix that activates existing, already-deployed error handling rather than requiring new call-site changes.
    - **Plain English:** When a user disconnects their custom domain, the app tells Cloudflare to remove the certificate. If Cloudflare's API has a bad day and returns an error instead of succeeding, the current code doesn't notice — it just moves on. The domain stays live on Cloudflare's side while Partna's own records say it's gone, which can later block the same domain from being reconnected. The three places in the app that call this already know how to report a failure loudly — they're just never given the chance to, because this one method never tells them anything went wrong.
    - **Evidence:**
        ```php
        public function delete(string $id): void
        {
            if (! $this->configured || $id === '') {
                return;
            }
            Http::withToken($this->apiToken)->timeout(5)->delete($this->base()."/{$id}");
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Vendor-I/O failure visibility hygiene:** #OBS-1, #OBS-2, #OBS-3
    - **Why grouped:** all three are single-file, S-effort fixes in the Platforms/Cloudflare vendor-I/O layer, all following the same established fix pattern (bump log severity / add `report($e)` / add `->throw()` to activate an already-present catch) plus a small test addition — no shared file, but a coherent one-session batch.
    - **Model:** Plan: Opus (combine plan+impl) · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.
