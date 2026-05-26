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
     *   2. Backend API subrequest URL (`<app.url>/api/public/profiles/<handle>`) —
     *      the Astro Worker calls this with `cf: {cacheTtl: 300, cacheEverything: true}`,
     *      so without this purge the edge holds the API response for 5 minutes
     *      and re-renders stale HTML even after the page URL has been evicted.
     *
     * All URLs sit in the same Cloudflare zone (`partna.au`), so one
     * purge_cache request covers everything.
     */
    public function purgeHandle(string $handle): void
    {
        $h = strtolower(trim($handle));
        if ($h === '') {
            return;
        }

        $urls = [
            // Page URL — root path and slash-less variant. Cloudflare treats
            // these as distinct cache keys, so list both.
            "https://{$h}.partna.au/",
            "https://{$h}.partna.au",
        ];

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
