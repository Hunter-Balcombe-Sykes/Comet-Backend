<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Services\SmartLinks\SafeUrlFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// Test-mode endpoints for TikTok. The simplest integration — no scraper
// service, no API key. From a username we fetch the public profile page and
// read the inlined __UNIVERSAL_DATA_FOR_REHYDRATION__ blob for the name,
// avatar, and follower count. Single-tenant cache, no auth, no migration.
//
// Caveat: TikTok avatar URLs are signed + expiring, and TikTok may serve an
// anti-bot page to datacenter IPs (so a server-side fetch can intermittently
// fail). Fine for a dashboard-only test; production would need a residential
// proxy or the official API.
class TiktokController extends ApiController
{
    private const SELECTION_KEY = 'platforms.tiktok.selection';

    private const CACHE_TTL_DAYS = 30;

    private const SCRAPE_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    // POST /api/platforms/tiktok/connect — fetch the profile, store + return.
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate(['username' => ['required', 'string', 'max:80']]);

        $username = $this->normalizeUsername($validated['username']);
        if ($username === '') {
            return $this->error('Enter your TikTok username.', 422);
        }

        $profile = $this->fetchProfile($username);
        if ($profile === null) {
            return $this->error('Could not load that TikTok profile (private, not found, or blocked).', 404);
        }

        $selection = ['username' => $username, ...$profile];
        Cache::put(self::SELECTION_KEY, $selection, now()->addDays(self::CACHE_TTL_DAYS));

        return $this->success($selection);
    }

    // GET /api/platforms/tiktok/selection
    public function selection(): JsonResponse
    {
        return $this->success(['selection' => Cache::get(self::SELECTION_KEY)]);
    }

    // DELETE /api/platforms/tiktok
    public function forget(): JsonResponse
    {
        Cache::forget(self::SELECTION_KEY);

        return $this->success(['selection' => null]);
    }

    // ── internals ────────────────────────────────────────────────

    private function normalizeUsername(string $input): string
    {
        $s = trim($input);
        if (preg_match('~tiktok\.com/@([A-Za-z0-9._]+)~i', $s, $m)) {
            return $m[1];
        }

        return ltrim($s, '@');
    }

    // Slice the JSON body of a <script> tag identified by $marker (an attribute
    // string). Avoids a regex capture over a very large payload.
    private function extractScriptJson(string $html, string $marker): ?string
    {
        $pos = strpos($html, $marker);
        if ($pos === false) {
            return null;
        }
        $open = strpos($html, '>', $pos);
        if ($open === false) {
            return null;
        }
        $close = strpos($html, '</script>', $open);
        if ($close === false) {
            return null;
        }

        return substr($html, $open + 1, $close - $open - 1);
    }

    /**
     * @return array{nickname:?string, avatarUrl:?string, followerCount:?int, verified:bool}|null
     */
    private function fetchProfile(string $username): ?array
    {
        $url = 'https://www.tiktok.com/@'.rawurlencode($username);
        $headers = [
            'User-Agent' => self::SCRAPE_USER_AGENT,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
        ];

        // TikTok intermittently serves a tiny anti-bot challenge page instead
        // of the real profile (esp. from datacenter IPs). The full page carries
        // the __UNIVERSAL_DATA_FOR_REHYDRATION__ blob; retry a few times until we
        // get it. Extract by string slicing — the blob is ~300 KB and a
        // non-greedy regex capture blows PCRE's backtrack limit.
        $json = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $res = $this->fetcher->fetch($url, $headers);
            if ($res['status'] === 200) {
                $json = $this->extractScriptJson($res['body'], 'id="__UNIVERSAL_DATA_FOR_REHYDRATION__"');
                if ($json !== null) {
                    break;
                }
            }
        }
        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }

        // TikTok namespaces under a literal dotted key — "webapp.user-detail"
        // is ONE key, so reach it directly rather than via data_get's dot path.
        $userInfo = $data['__DEFAULT_SCOPE__']['webapp.user-detail']['userInfo'] ?? null;
        if (! is_array($userInfo)) {
            return null;
        }
        $user = $userInfo['user'] ?? null;
        $stats = $userInfo['stats'] ?? null;
        if (! is_array($user)) {
            return null;
        }

        return [
            'nickname' => data_get($user, 'nickname'),
            'avatarUrl' => data_get($user, 'avatarLarger') ?? data_get($user, 'avatarMedium'),
            'followerCount' => isset($stats['followerCount']) ? (int) $stats['followerCount'] : null,
            'verified' => (bool) data_get($user, 'verified'),
        ];
    }
}
