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

        // Cloudflare's purge_cache `files` accepts at most 30 URLs per request on
        // non-Enterprise plans. A full sitepage purge (root + 15 deep-link
        // sub-paths + each one's SWR shadow + the API subrequest) exceeds that, so
        // chunk into <=30-URL batches — one POST each.
        foreach (array_chunk(array_values($urls), 30) as $chunk) {
            Http::withToken($this->apiToken)
                ->asJson()
                ->acceptJson()
                ->post($this->url(), ['files' => $chunk])
                ->throw();
        }
    }

    /**
     * Purge the full cache chain for one individual's public profile. The router
     * Worker keys the edge cache by request PATH, so a profile occupies many keys:
     *   • Root page (`https://<handle>.partna.au/`, slash + slash-less variants).
     *   • Every deep-link sub-page — `/shop`, `/book`, `/listen`, … (the SitepageId
     *     taxonomy). Each is a SEPARATE edge key; purging only the root left these
     *     serving pre-mutation HTML until their s-maxage lapsed (observed 24 h).
     *   • The SWR stale shadow for each of the above (`/_swr-shadow<path>`, 7-day
     *     TTL — cloudflare-worker/src/index.js `staleShadowKey`). On a primary MISS
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

        // Canonical public-site base domain — the single source of truth shared
        // with PublicSiteController/ResolvesSubdomainFromHost. Drives the zone
        // these purge targets hit, so staging/non-prod TLDs resolve correctly
        // instead of always pointing at partna.au.
        $baseDomain = config('partna.public_domain');

        // One base host per domain this profile is reachable under: the canonical
        // .partna.au subdomain, plus a custom domain (Cloudflare for SaaS) which is
        // cached under its OWN host key — the router keys caches.default by full URL
        // incl. Host, so a content change must bust both. Same zone → one purge run.
        $bases = ["https://{$h}.{$baseDomain}"];
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
                ->limit(100)
                ->pluck('product_handle')
                ->all();
        } catch (\Throwable $e) {
            Log::debug('CloudflarePurgeService: product-handle lookup failed, purging pages only', ['handle' => $h, 'error' => $e->getMessage()]);
            $productHandles = [];
        }

        // Menu item detail pages (`/menu/<uuid>`, route added 2026-07-17) —
        // same per-URL edge-key staleness the products fix closed; same
        // bounded, never-break-the-purge pattern. (Events item pages
        // deliberately NOT enumerated: their ids live inside platform
        // payload JSON, not rows — they age out via the page TTL instead.)
        try {
            $menuItemIds = DB::connection('pgsql')->table('site.menu_items as mi')
                ->join('site.menus as m', 'm.id', '=', 'mi.menu_id')
                ->join('core.users as u', 'u.id', '=', 'm.user_id')
                ->where('u.handle_lc', $h)
                ->limit(150)
                ->pluck('mi.id')
                ->all();
        } catch (\Throwable $e) {
            Log::debug('CloudflarePurgeService: menu-item lookup failed, purging pages only', ['handle' => $h, 'error' => $e->getMessage()]);
            $menuItemIds = [];
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
                $urls[] = "{$base}/products/{$productHandle}";
                $urls[] = "{$base}/_swr-shadow/products/{$productHandle}";
            }
            // Each menu item detail page + its shadow.
            foreach ($menuItemIds as $menuItemId) {
                $urls[] = "{$base}/menu/{$menuItemId}";
                $urls[] = "{$base}/_swr-shadow/menu/{$menuItemId}";
            }
        }

        $apiBase = rtrim((string) config('app.url', ''), '/');
        if ($apiBase !== '') {
            // The Astro Worker subrequest target — `IndividualProfileController@show`.
            // Without this entry the §28.8 endpoint's edge cache (`cacheTtl: 300`)
            // pins the rendered HTML to stale data for up to 5 minutes after a
            // mutation, regardless of how aggressively we purge the page URLs.
            $urls[] = "{$apiBase}/api/public/profiles/{$h}";
            // The platform-integrations subrequest (`PublicIntegrationController`)
            // that the sitepage reads for cards (e.g. the Instagram gallery card).
            // Its own fetch is `cacheTtl: 0`, so this is belt-and-braces — but it
            // guarantees a display-toggle flip never leaves a stale card wire.
            $urls[] = "{$apiBase}/api/public/profiles/{$h}/integrations";
        }

        $this->purgeUrls($urls);
    }

    private function url(): string
    {
        return "https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/purge_cache";
    }
}
