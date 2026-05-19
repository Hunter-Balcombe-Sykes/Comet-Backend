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
     * Purge the canonical individual public-profile URL for a handle.
     * The Astro Worker subrequest target is `https://<handle>.partna.au/`.
     */
    public function purgeHandle(string $handle): void
    {
        $h = strtolower(trim($handle));
        if ($h === '') {
            return;
        }

        // Purge both the root path (`/`) and the slash-less variant defensively —
        // Cloudflare treats them as distinct cache keys.
        $this->purgeUrls([
            "https://{$h}.partna.au/",
            "https://{$h}.partna.au",
        ]);
    }

    private function url(): string
    {
        return "https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/purge_cache";
    }
}
