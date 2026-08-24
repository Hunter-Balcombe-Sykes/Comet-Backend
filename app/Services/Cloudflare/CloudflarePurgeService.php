<?php

namespace App\Services\Cloudflare;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Wraps the Cloudflare zone cache-purge REST API.
// POST https://api.cloudflare.com/client/v4/zones/{zone_id}/purge_cache
//   body: {"prefixes":["<handle>.partna.au/"]} | {"files":[...]} | {"purge_everything": true}
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
    // Since the 2026-08-19 prefix-purge rewrite purgeHandle() sends ONE prefix
    // request plus a single ≤3-URL API chunk, so this fires zero times on that
    // path; kept for any caller with a genuinely large file list.
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
     * Purge every edge key for one individual's public profile (owner plan,
     * 2026-08-19 — instant sitepage freshness).
     *
     * ONE prefix purge per host: `<handle>.<baseDomain>/` (plus the custom
     * domain's host when one is served). Cloudflare's purge-by-prefix
     * evicts every key under that host — the root, every deep-link page,
     * every product/menu/event detail page AND the router's `/_swr-shadow/*`
     * twins — in a single request (proven on dev 2026-08-19: `hit` → prefix
     * purge → `origin` on / and /about). This replaces the URL enumeration
     * that used to list up to ~2,481 files across ~83 sequential requests
     * and made a purge take 20–40 s; with a ~1 s purge the job's lock is
     * ~1 s wide too, and a rapid second edit is no longer swallowed.
     *
     * The backend API subrequests the Astro Worker renders from
     * (`<app.url>/api/public/profiles/<handle>` + `/integrations`) live on the
     * API host, which the pages fetch edge-caches for 30 s — they go by
     * single-file purge in the same call, one chunk. Both must die together or
     * the render is rebuilt from a stale half. (A third URL, the `/platforms`
     * alias, was purged here until it retired 2026-08-20.)
     */
    public function purgeHandle(string $handle, ?string $customDomain = null): void
    {
        $h = strtolower(trim($handle));
        if ($h === '') {
            return;
        }

        // SEC-1: encode before the handle lands in any purge target. Handles are
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

        // One host prefix per domain this profile is reachable under: the
        // canonical .partna.au subdomain, plus a custom domain (Cloudflare for
        // SaaS) which is cached under its OWN host key — the router keys
        // caches.default by full URL incl. Host. Same zone → one purge run.
        $prefixes = ["{$encodedHandle}.{$baseDomain}/"];
        $domain = strtolower(trim((string) $customDomain));
        if ($domain !== '') {
            $prefixes[] = "{$domain}/";
        }

        $urls = [];
        $apiBase = rtrim((string) config('app.url', ''), '/');
        if ($apiBase !== '') {
            // The Astro Worker subrequest target — `IndividualProfileController@show`.
            $urls[] = "{$apiBase}/api/public/profiles/{$encodedHandle}";
            // The platform-integrations subrequest (`PublicIntegrationController`).
            // Purging the profile payload alone left this one serving the
            // pre-mutation cards. (Its `/platforms` alias retired 2026-08-20.)
            $urls[] = "{$apiBase}/api/public/profiles/{$encodedHandle}/integrations";
        }

        Log::info('cloudflare.purge.handle', ['handle' => $h, 'mode' => 'prefix', 'prefixes' => count($prefixes), 'api_urls' => count($urls)]);

        // API wire FIRST, HTML second: the render that follows the HTML purge
        // reads the API, so the API's cached copy must already be gone or the
        // fresh HTML is built from the pre-edit payload and re-pinned.
        $this->purgeUrls($urls);
        $this->purgePrefixes($prefixes);
    }

    /**
     * Purge every cached key under the given `host/path` prefixes — one
     * request (Cloudflare accepts up to 30 per call; a profile needs one or
     * two). Available on every plan since 2025-04-03.
     *
     * @param  list<string>  $prefixes
     */
    public function purgePrefixes(array $prefixes): void
    {
        if (! $this->configured) {
            if (app()->environment('production', 'staging')) {
                throw new \RuntimeException(
                    'CloudflarePurgeService is not configured (zone_id, cache_purge_token required). '
                    .'Refusing to silently no-op purge in '.app()->environment().'.'
                );
            }
            Log::debug('CloudflarePurgeService: skipping prefix purge (not configured)', ['prefix_count' => count($prefixes)]);

            return;
        }
        if ($prefixes === []) {
            return;
        }

        foreach (array_chunk($prefixes, 30) as $chunk) {
            Http::withToken($this->apiToken)
                ->asJson()
                ->acceptJson()
                ->timeout(10)
                ->connectTimeout(3)
                ->post($this->url(), ['prefixes' => $chunk])
                ->throw();
        }
    }

    private function url(): string
    {
        return "https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/purge_cache";
    }
}
