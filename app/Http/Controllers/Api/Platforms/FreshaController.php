<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Services\SmartLinks\SafeUrlFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// Test-mode endpoints for the Fresha integration. Saves a Fresha store URL
// globally (single-tenant test cache, no auth) and returns the staff list
// extracted from the page's __NEXT_DATA__ blob.
//
// Approach proven and documented in:
//   ~/Developer/platform link capabilites/fresha.md
//
// Promotion plan: when the test is done, extract scrape logic to
// App\Services\Platforms\FreshaScraper, persist via a platform_connections
// table per user, and wire to /account/platforms in Partna-Frontend.
class FreshaController extends ApiController
{
    private const CACHE_KEY = 'platforms.fresha.url';

    private const CACHE_TTL_DAYS = 30;

    // Matches both locale-prefixed and bare slug URLs:
    //   https://www.fresha.com/a/<slug>
    //   https://www.fresha.com/en-GB/a/<slug>
    private const URL_PATTERN = '#^https?://(www\.)?fresha\.com/(?:[a-z]{2,3}(-[a-z]{2})?/)?a/[a-z0-9-]+/?$#i';

    private const SCRAPE_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    // POST /api/platforms/fresha/connect
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500', 'regex:'.self::URL_PATTERN],
        ]);

        $url = $this->stripLocale($validated['url']);
        Cache::put(self::CACHE_KEY, $url, now()->addDays(self::CACHE_TTL_DAYS));

        return $this->success([
            'url' => $url,
            'team' => $this->fetchTeam($url),
        ]);
    }

    // GET /api/platforms/fresha/team
    public function team(): JsonResponse
    {
        $url = Cache::get(self::CACHE_KEY);
        if (! $url) {
            return $this->error('No Fresha URL connected yet. POST one to /connect first.', 404);
        }

        return $this->success([
            'url' => $url,
            'team' => $this->fetchTeam($url),
        ]);
    }

    // GET /api/platforms/fresha/url — peek at what's saved without re-scraping.
    public function show(): JsonResponse
    {
        return $this->success(['url' => Cache::get(self::CACHE_KEY)]);
    }

    // DELETE /api/platforms/fresha — clear the saved URL.
    public function forget(): JsonResponse
    {
        Cache::forget(self::CACHE_KEY);

        return $this->success(['url' => null]);
    }

    // ── internals ────────────────────────────────────────────────

    // Drop the locale segment so we always cache the canonical /a/<slug> form.
    private function stripLocale(string $url): string
    {
        return preg_replace('#fresha\.com/[a-z]{2,3}(-[a-z]{2})?/a/#i', 'fresha.com/a/', $url) ?? $url;
    }

    /**
     * @return list<array{employeeId:string, displayName:string, jobTitle:?string, avatarUrl:?string, rating:?float}>
     */
    private function fetchTeam(string $url): array
    {
        $response = $this->fetcher->fetch($url, ['User-Agent' => self::SCRAPE_USER_AGENT]);

        if ($response['status'] !== 200) {
            abort(502, "Fresha returned HTTP {$response['status']}");
        }

        if (! preg_match('#<script id="__NEXT_DATA__"[^>]*>(.+?)</script>#s', $response['body'], $m)) {
            abort(502, 'Fresha page did not contain __NEXT_DATA__ — structure may have changed.');
        }

        $data = json_decode($m[1], true);
        if (! is_array($data)) {
            abort(502, 'Failed to decode __NEXT_DATA__ JSON.');
        }

        $edges = data_get($data, 'props.pageProps.data.location.employeeProfiles.edges', []);
        if (! is_array($edges)) {
            return [];
        }

        return array_values(array_map(static function (array $edge): array {
            $node = $edge['node'] ?? [];

            return [
                'employeeId' => (string) ($node['employeeId'] ?? ''),
                'displayName' => (string) ($node['displayName'] ?? ''),
                'jobTitle' => $node['jobTitle'] ?? null,
                'avatarUrl' => data_get($node, 'avatar.url'),
                'rating' => isset($node['rating']) ? (float) $node['rating'] : null,
            ];
        }, $edges));
    }
}
