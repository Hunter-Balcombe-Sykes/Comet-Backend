<?php

namespace App\Services\Cloudflare;

use App\Enums\SitepageId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Wraps the Cloudflare zone cache-purge REST API.
// POST https://api.cloudflare.com/client/v4/zones/{zone_id}/purge_cache
//   body: {"files":["https://<handle>.partna.au/", ...]} | {"purge_everything": true}
//
// Used by CloudflareCachePurgeJob (and only that job) to invalidate the edge
// cache for individual public profile pages when the underlying site data changes.
// Reads `services.cloudflare.cache_purge_token` and `services.cloudflare.zone_id`
// — NEVER env() directly, so `php artisan config:cache` is respected (audit CFG).
//
// Gracefully no-ops when unconfigured (local dev without CF credentials).
class CloudflarePurgeService
{
    // SCALE-101: gap between chunk POSTs on a multi-chunk purgeUrls() call, so a
    // burst of chunks doesn't slam Cloudflare's purge_cache endpoint back-to-back.
    //
    // Worst-case URL count for ONE purgeHandle() call (re-derived 2026-07-20,
    // verified against source — the previous "~20 chunks ~3s" claim here was
    // wrong by ~2.5x):
    //   subpages : SitepageId::canonicalOrder() = 16 cases - 'home' (it's the
    //              root) = 15, + privacy/terms/about = 18 pages x 2
    //              (page + SWR shadow) + 3 root urls (base/, base, root
    //              shadow)                                              =  39 / host
    //   products : ->limit(100) x 2 (page + shadow)                     = 200 / host
    //   menu     : ->limit(150) x 2                                     = 300 / host
    //   events   : ->limit(30) connection rows, each contributing up to
    //              5 events (EventbriteFetch/HumanitixFetch -> *Scraper
    //              ::fetchEvents() default $limit=5) into one deduped id
    //              set, final set capped at 100 by array_slice(...,0,100),
    //              x 2                                                  = 200 / host
    //   -------------------------------------------------------------------------
    //   (39+200+300+200) x 2 hosts (canonical + optional custom domain)
    //   + 2 API urls (profile + integrations)                          = 1,480 URLs
    //   chunked at 30/request (Cloudflare's `files` limit)              =    50 chunks
    //                                                                       (49 gaps)
    //
    // At the OLD flat 150ms/chunk pacing that's 49 x 150ms = 7.35s of GUARANTEED
    // sleep alone — 49% of CloudflareCachePurgeJob::$timeout=15 — before any of
    // the 50 sequential blocking Http::post()->throw() round trips to Cloudflare.
    // A power-user profile (shop + menu + events populated, plus a custom domain)
    // could plausibly exceed the job timeout on sleep + network combined, with no
    // partial-progress recovery on a mid-purge kill.
    //
    // Two changes close the SLEEP side of that gap:
    //   1. Pacing cut to 50ms/chunk — still enough gap to smooth the burst.
    //   2. A hard ceiling on TOTAL pacing time per purgeUrls() call
    //      (PACING_BUDGET_MICROSECONDS): once hit, remaining chunks fire with no
    //      further sleep. This bounds guaranteed sleep at 2s NO MATTER how large
    //      the URL set grows — a future limit increase (e.g. raising the product
    //      or menu-item cap) can't silently re-inflate guaranteed sleep in
    //      lockstep with chunk count the way a flat per-chunk delay does — and
    //      leaves >=13s of the 15s timeout for the HTTP round trips themselves.
    // This only bounds the sleep contribution. The dominant remaining risk at max
    // volume is the ~50 sequential blocking HTTP calls' own network time; raising
    // CloudflareCachePurgeJob::$timeout with real margin is the complementary fix
    // and is recommended as a follow-up (that file is out of scope here).
    //
    // Plain usleep (not a pool) because purgeUrls' chunk order matters for
    // nothing and the volumes here don't justify Http::pool's added complexity.
    private const CHUNK_PACING_MICROSECONDS = 50_000;

    // Hard ceiling on total time slept across one purgeUrls() call — see the
    // derivation above. floor(2_000_000 / 50_000) = 40 real sleeps max, however
    // many chunks the call has.
    private const PACING_BUDGET_MICROSECONDS = 2_000_000;

    // Running total of microseconds actually paced THIS purgeUrls() call; reset
    // at the top of purgeUrls() so budget never bleeds across calls even if a
    // single instance is (re)used for more than one purge.
    private int $microsecondsPacedSoFar = 0;

    private readonly string $zoneId;

    private readonly string $apiToken;

    private readonly bool $configured;

    public function __construct()
    {
        $this->zoneId = (string) config('services.cloudflare.zone_id', '');
        $this->apiToken = (string) config('services.cloudflare.cache_purge_token', '');
        $this->configured = $this->zoneId !== '' && $this->apiToken !== '';
    }

    /**
     * Purge a set of absolute URLs from the Cloudflare edge cache.
     *
     * @param  list<string>  $urls
     */
    public function purgeUrls(array $urls): void
    {
        if (! $this->configured) {
            // See CloudflareKvService::guardUnconfigured for the rationale —
            // silent no-op in dev, hard fail in prod/staging so a missing
            // CLOUDFLARE_CACHE_PURGE_TOKEN can't quietly stop cache busts.
            if (app()->environment('production', 'staging')) {
                throw new \RuntimeException(
                    'CloudflarePurgeService is not configured (zone_id, cache_purge_token required). '
                    .'Refusing to silently no-op purge in '.app()->environment().'.'
                );
            }

            Log::debug('CloudflarePurgeService: skipping purge (not configured)', ['url_count' => count($urls)]);

            return;
        }

        if ($urls === []) {
            return;
        }

        // Fresh budget per call (see the microsecondsPacedSoFar docblock above).
        $this->microsecondsPacedSoFar = 0;

        // Cloudflare's purge_cache `files` accepts at most 30 URLs per request on
        // non-Enterprise plans. A full sitepage purge (root + 15 deep-link
        // sub-paths + each one's SWR shadow + the API subrequest) exceeds that, so
        // chunk into <=30-URL batches — one POST each, paced up to a bounded
        // total budget (SCALE-101).
        // CFG-3: this 30 stays a literal, unlike the DB-query caps below in
        // purgeHandle() — it's Cloudflare's hard API ceiling, not a tunable knob.
        foreach (array_chunk(array_values($urls), 30) as $i => $chunk) {
            if ($i > 0 && $this->microsecondsPacedSoFar < self::PACING_BUDGET_MICROSECONDS) {
                $this->paceBetweenChunks();
                $this->microsecondsPacedSoFar += self::CHUNK_PACING_MICROSECONDS;
            }

            // cache-edge-reconcile/LIFE-1 residual: bound each call explicitly —
            // without this, Laravel's blanket defaults (connect 10s / total 30s)
            // apply, and a handful of slow-but-not-hung chunks can exhaust the
            // whole job timeout while the rest of the catalog goes unpurged. 10s
            // (not 5s) — we have no p99 latency data for this endpoint, and 5s
            // risks failing purges that currently succeed.
            Http::withToken($this->apiToken)
                ->asJson()
                ->acceptJson()
                ->timeout(10)
                ->connectTimeout(3)
                ->post($this->url(), ['files' => $chunk])
                ->throw();
        }
    }

    /** Extracted so tests can spy on the pacing mechanism without a real sleep. */
    protected function paceBetweenChunks(): void
    {
        usleep(self::CHUNK_PACING_MICROSECONDS);
    }

    /**
     * Purge the full cache chain for one individual's public profile. The router
     * Worker keys the edge cache by request PATH, so a profile occupies many keys:
     *   • Root page (`https://<handle>.partna.au/`, slash + slash-less variants).
     *   • Every deep-link sub-page — `/shop`, `/book`, `/listen`, … (the SitepageId
     *     taxonomy). Each is a SEPARATE edge key; purging only the root left these
     *     serving pre-mutation HTML until their s-maxage lapsed (observed 24 h —
     *     sync note: cloudflare-worker/src/index.js `PRIMARY_CACHE_TTL_S`, bump both together).
     *   • The SWR stale shadow for each of the above (`/_swr-shadow<path>`, 7-day
     *     TTL — cloudflare-worker/src/index.js `staleShadowKey`, sync note: `STALE_SHADOW_TTL_S`
     *     in the same file — bump both together). On a primary MISS
     *     the Worker serves the shadow and refreshes in the background, so without
     *     purging it the first post-mutation visitor still sees stale content.
     *   • Backend API subrequest (`<app.url>/api/public/profiles/<handle>`), which
     *     the Astro Worker edge-caches (`cacheTtl: 300`) — stale for up to 5 min
     *     otherwise, re-rendering old HTML even after the page keys are evicted.
     *
     * A custom domain (Cloudflare for SaaS) adds the same set under its own host.
     * All sit in one Cloudflare zone; purgeUrls chunks them to the 30-URL limit.
     */
    public function purgeHandle(string $handle, ?string $customDomain = null): void
    {
        $h = strtolower(trim($handle));
        if ($h === '') {
            return;
        }

        // SEC-1: encode before the handle lands in any purge URL. Handles are
        // validated to [a-z0-9-] at write time — a value in that set is left
        // byte-for-byte unchanged by rawurlencode() (unreserved chars), so this
        // is defence-in-depth against a malformed/legacy value injecting an
        // extra path segment or query string, not a behaviour change for valid ones.
        $encodedHandle = rawurlencode($h);

        // Canonical public-site base domain — the single source of truth shared
        // with PublicSiteController/ResolvesSubdomainFromHost. Drives the zone
        // these purge targets hit, so staging/non-prod TLDs resolve correctly
        // instead of always pointing at partna.au.
        $baseDomain = config('partna.public_domain');

        // One base host per domain this profile is reachable under: the canonical
        // .partna.au subdomain, plus a custom domain (Cloudflare for SaaS) which is
        // cached under its OWN host key — the router keys caches.default by full URL
        // incl. Host, so a content change must bust both. Same zone → one purge run.
        $bases = ["https://{$encodedHandle}.{$baseDomain}"];
        $domain = strtolower(trim((string) $customDomain));
        if ($domain !== '') {
            $bases[] = "https://{$domain}";
        }

        // The deep-link sub-pages (SitepageId taxonomy minus 'home', which IS the
        // root). Each is its own edge key, so each needs its own purge — this is the
        // bug the root-only purge had: /shop, /book, … stayed stale for 24 h.
        $subPages = array_values(array_filter(
            SitepageId::canonicalOrder(),
            static fn (string $page): bool => $page !== 'home',
        ));

        // Legal pages are standalone routes outside the SitepageId taxonomy —
        // without these, a policy edit stays cached at the edge for 24h.
        $subPages[] = 'privacy';
        $subPages[] = 'terms';
        // /about is a standalone route too (conditional content blocks).
        $subPages[] = 'about';

        // Shop product detail pages (`/products/<handle>`) are their own edge
        // keys too — the fixed page taxonomy above doesn't know them, which
        // left freshly-deployed PDPs stale for 24h (2026-07-16). Bounded +
        // deduped; purgeUrls chunks to the API's 30-URL limit either way.
        // Never let this optional lookup break the purge itself.
        try {
            // BaseModel pins pgsql — match it (the default connection differs
            // in tests, and must not be assumed in prod either).
            $productHandles = DB::connection('pgsql')->table('site.shop_products as p')
                ->join('site.shop_brands as b', 'b.id', '=', 'p.brand_id')
                ->join('site.platform_connections as c', 'c.id', '=', 'b.connection_id')
                ->join('core.users as u', 'u.id', '=', 'c.user_id')
                ->where('u.handle_lc', $h)
                ->whereNull('c.deleted_at')
                ->whereRaw("p.data->>'handle' IS NOT NULL")
                ->selectRaw("DISTINCT p.data->>'handle' AS product_handle")
                ->limit((int) config('partna.cloudflare_purge.products_limit', 100))
                ->pluck('product_handle')
                ->all();
        } catch (\Throwable $e) {
            // OBS-101: this lookup failing silently degrades to page-only purges
            // (stale PDPs) with zero signal — must reach Nightwatch, not just a
            // log line the default 'warning' log_level filters out (config/nightwatch.php).
            report($e);
            Log::warning('CloudflarePurgeService: product-handle lookup failed, purging pages only', ['handle' => $h, 'error' => $e->getMessage()]);
            $productHandles = [];
        }

        // Menu item detail pages (`/menu/<uuid>`, route added 2026-07-17) —
        // same per-URL edge-key staleness the products fix closed; same
        // bounded, never-break-the-purge pattern.
        try {
            $menuItemIds = DB::connection('pgsql')->table('site.menu_items as mi')
                ->join('site.menus as m', 'm.id', '=', 'mi.menu_id')
                ->join('core.users as u', 'u.id', '=', 'm.user_id')
                ->where('u.handle_lc', $h)
                ->limit((int) config('partna.cloudflare_purge.menu_items_limit', 150))
                ->pluck('mi.id')
                ->all();
        } catch (\Throwable $e) {
            // OBS-101: same bug as the product-handle catch above — bump to
            // warning + report() so a stale-menu-page regression pages instead
            // of vanishing into a log level Nightwatch never reads.
            report($e);
            Log::warning('CloudflarePurgeService: menu-item lookup failed, purging pages only', ['handle' => $h, 'error' => $e->getMessage()]);
            $menuItemIds = [];
        }

        // Event detail pages (`/events/<id>`) — ids live inside the event
        // connections' payload JSON (standalone kind:'event' rows are the
        // event; account rows carry next/upcoming lists). Enumerated since
        // 2026-07-17 (reversing the earlier age-out-via-TTL call): the pages
        // now render refreshable enriched fields (description/venue/times),
        // so 24h of staleness after a refresh reads as a bug, not decay.
        try {
            $eventPayloads = DB::connection('pgsql')->table('site.platform_connections as c')
                ->join('core.users as u', 'u.id', '=', 'c.user_id')
                ->where('u.handle_lc', $h)
                ->whereIn('c.platform', ['eventbrite', 'humanitix', 'events-custom'])
                ->whereNull('c.deleted_at')
                ->limit((int) config('partna.cloudflare_purge.event_connections_limit', 30))
                ->pluck('c.payload')
                ->all();

            $eventIdSet = [];
            foreach ($eventPayloads as $rawPayload) {
                $payload = is_array($rawPayload) ? $rawPayload : json_decode((string) $rawPayload, true);
                if (! is_array($payload)) {
                    continue;
                }
                $next = $payload['next'] ?? null;
                $candidates = [
                    $payload,
                    ...(is_array($next) ? [$next] : []),
                    ...array_values(array_filter((array) ($payload['upcoming'] ?? []), 'is_array')),
                ];
                foreach ($candidates as $event) {
                    $id = $event['id'] ?? null;
                    // Path-safe ids only — these become purge URL segments.
                    if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1) {
                        $eventIdSet[$id] = true;
                    }
                }
            }
            $eventIds = array_slice(array_keys($eventIdSet), 0, (int) config('partna.cloudflare_purge.event_ids_limit', 100));
        } catch (\Throwable $e) {
            // OBS-101: same bug as the product-handle catch above — bump to
            // warning + report() so a stale-event-page regression pages instead
            // of vanishing into a log level Nightwatch never reads.
            report($e);
            Log::warning('CloudflarePurgeService: event-id lookup failed, purging pages only', ['handle' => $h, 'error' => $e->getMessage()]);
            $eventIds = [];
        }

        $urls = [];
        foreach ($bases as $base) {
            // Root — slash + slash-less are distinct keys — and its shadow.
            $urls[] = "{$base}/";
            $urls[] = $base;
            $urls[] = "{$base}/_swr-shadow/";
            // Each sub-page + its SWR shadow (`/_swr-shadow<path>`).
            foreach ($subPages as $page) {
                $urls[] = "{$base}/{$page}";
                $urls[] = "{$base}/_swr-shadow/{$page}";
            }
            // Each product detail page + its shadow.
            foreach ($productHandles as $productHandle) {
                // SEC-1: product handles come from scraped shop data (Shopify), not
                // our own validated handle format — encode before it lands in a URL.
                $encodedProductHandle = rawurlencode($productHandle);
                $urls[] = "{$base}/products/{$encodedProductHandle}";
                $urls[] = "{$base}/_swr-shadow/products/{$encodedProductHandle}";
            }
            // Each menu item detail page + its shadow.
            foreach ($menuItemIds as $menuItemId) {
                $urls[] = "{$base}/menu/{$menuItemId}";
                $urls[] = "{$base}/_swr-shadow/menu/{$menuItemId}";
            }
            // Each event detail page + its shadow.
            foreach ($eventIds as $eventId) {
                $urls[] = "{$base}/events/{$eventId}";
                $urls[] = "{$base}/_swr-shadow/events/{$eventId}";
            }
        }

        $apiBase = rtrim((string) config('app.url', ''), '/');
        if ($apiBase !== '') {
            // The Astro Worker subrequest target — `IndividualProfileController@show`.
            // Without this entry the §28.8 endpoint's edge cache (`cacheTtl: 300`)
            // pins the rendered HTML to stale data for up to 5 minutes after a
            // mutation, regardless of how aggressively we purge the page URLs.
            $urls[] = "{$apiBase}/api/public/profiles/{$encodedHandle}";
            // The platform-integrations subrequest (`PublicIntegrationController`)
            // that the sitepage reads for cards (e.g. the Instagram gallery card).
            // Its own fetch is `cacheTtl: 0`, so this is belt-and-braces — but it
            // guarantees a display-toggle flip never leaves a stale card wire.
            $urls[] = "{$apiBase}/api/public/profiles/{$encodedHandle}/integrations";
        }

        // cache-edge-reconcile/LIFE-1 residual: surface a catalog approaching the
        // practical purge ceiling (chunking + job timeout budget) BEFORE it starts
        // failing outright, rather than only finding out via failed purges.
        $volumeThreshold = (int) config('partna.cache.purge_url_volume_warning_threshold', 900);
        if (count($urls) > $volumeThreshold) {
            Log::warning('cloudflare.purge.url_volume_high', [
                'handle' => $h,
                'url_count' => count($urls),
                'threshold' => $volumeThreshold,
            ]);
        }

        $this->purgeUrls($urls);
    }

    private function url(): string
    {
        return "https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/purge_cache";
    }
}
