<?php

namespace App\Services\Cloudflare;

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

        Http::withToken($this->apiToken)
            ->asJson()
            ->acceptJson()
            ->post($this->url(), ['files' => array_values($urls)])
            ->throw();
    }

    /**
     * Purge the full cache chain for one individual's public profile:
     *   1. Page URL (`https://<handle>.partna.au/`) — what visitors hit. Cached by
     *      the router Worker via `caches.default.put` with `s-maxage=10`.
     *   2. SWR stale shadow (`https://<handle>.partna.au/_swr-shadow/`) — the
     *      router Worker stores a second copy under a `_swr-shadow` path prefix
     *      with a 7-day TTL (cloudflare-worker/src/index.js `staleShadowKey`).
     *      On a primary cache MISS the Worker serves the shadow immediately and
     *      kicks off an origin refresh in the background. Without this purge,
     *      the first refresh after a mutation still hits the stale shadow and
     *      shows pre-mutation content — and the visitor who triggered the
     *      refresh always sees stale, even though the next visitor sees fresh.
     *   3. Backend API subrequest URL (`<app.url>/api/public/profiles/<handle>`) —
     *      the Astro Worker calls this with `cf: {cacheTtl: 300, cacheEverything: true}`,
     *      so without this purge the edge holds the API response for 5 minutes
     *      and re-renders stale HTML even after the page URL has been evicted.
     *
     * All URLs sit in the same Cloudflare zone (the configured public domain),
     * so one purge_cache request covers everything.
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

        $urls = [
            // Page URL — root path and slash-less variant. Cloudflare treats
            // these as distinct cache keys, so list both.
            "https://{$h}.{$baseDomain}/",
            "https://{$h}.{$baseDomain}",
            // SWR stale shadow — the router Worker's second cache layer,
            // 7-day TTL. Without purging this, post-mutation refreshes serve
            // pre-mutation content from the shadow.
            "https://{$h}.{$baseDomain}/_swr-shadow/",
        ];

        // Custom domain (Cloudflare for SaaS): its sitepage is cached at the edge
        // under its OWN host key (e.g. https://tuesdae.co/), entirely separate from
        // the .partna.au URLs above — the router Worker keys caches.default by the
        // full request URL incl. Host. Same zone, so one purge_cache call evicts
        // both. Without these, a content change never busts the custom-domain cache.
        $domain = strtolower(trim((string) $customDomain));
        if ($domain !== '') {
            $urls[] = "https://{$domain}/";
            $urls[] = "https://{$domain}";
            $urls[] = "https://{$domain}/_swr-shadow/";
        }

        $apiBase = rtrim((string) config('app.url', ''), '/');
        if ($apiBase !== '') {
            // The Astro Worker subrequest target — `IndividualProfileController@show`.
            // Without this entry the §28.8 endpoint's edge cache (`cacheTtl: 300`)
            // pins the rendered HTML to stale data for up to 5 minutes after a
            // mutation, regardless of how aggressively we purge the page URL.
            $urls[] = "{$apiBase}/api/public/profiles/{$h}";
        }

        $this->purgeUrls($urls);
    }

    private function url(): string
    {
        return "https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/purge_cache";
    }
}
