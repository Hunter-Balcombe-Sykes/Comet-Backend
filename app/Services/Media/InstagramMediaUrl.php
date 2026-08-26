<?php

namespace App\Services\Media;

use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Instagram media URL expiry + refresh (plan 3 R3, 2026-08-27).
 *
 * Instagram signs every fbcdn/cdninstagram media URL with an `oe=<hex epoch>`
 * expiry — videos live ~24h, images ~2-8 days — and the Apify profile actor
 * serves cached crawls whose URLs can be DEAD ON ARRIVAL (live evidence: 16
 * stuck video assets, stored `oe` stamps up to 4 days pre-fetch). Two
 * capabilities, both verified live 2026-08-26/27:
 *
 *  - preFlight: parse the expiry and refuse to spend a fetch on a URL we
 *    KNOW is dead;
 *  - refresh: get a freshly-signed URL for a post by shortcode —
 *    `instagram.com/p/{shortCode}/embed/` first (free; verified serving
 *    fresh URLs with no login from the Laravel Cloud egress itself), the
 *    single-post Apify scrape as the guaranteed fallback (runs on Apify's
 *    proxies; ~a cent per call). Carousel images match children by
 *    POSITION from the actor payload — the embed page only shows child 0.
 *
 * Terminal degradation stays what it was: no fresh URL → poster-only, which
 * every consumer already renders.
 */
class InstagramMediaUrl
{
    private const EMBED_UA = 'Mozilla/5.0 (compatible; PartnaBot/1.0; +https://partna.au)';

    /** Rule 3 (OutboundHttpGuard pattern D): the only variable URL segment. */
    private const SHORTCODE_PATTERN = '/^[A-Za-z0-9_-]{5,20}$/';

    /** Skew: a URL expiring within this window is as good as dead. */
    private const EXPIRY_SKEW_SECONDS = 300;

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /** Unix epoch the URL's signature lapses, or null when it carries no oe=. */
    public function expiresAt(string $url): ?int
    {
        if (preg_match('/[?&]oe=([0-9A-Fa-f]{6,12})(?:&|$)/', $url, $m) !== 1) {
            return null;
        }

        return (int) hexdec($m[1]);
    }

    /** True only when the URL PROVABLY cannot serve (signed and lapsed). */
    public function isExpired(string $url): bool
    {
        $expiresAt = $this->expiresAt($url);

        return $expiresAt !== null && $expiresAt <= (time() + self::EXPIRY_SKEW_SECONDS);
    }

    /**
     * A freshly-signed URL for the post's media, or null. $position selects
     * a carousel child for images (0 = single/first).
     *
     * @param  'video'|'image'  $kind
     */
    public function freshUrl(string $shortCode, string $kind, int $position = 0): ?string
    {
        if (preg_match(self::SHORTCODE_PATTERN, $shortCode) !== 1) {
            return null;
        }

        // Embed leg — free, prod-egress-verified. Only authoritative for the
        // video and the FIRST image; deeper carousel children need the actor.
        if ($kind === 'video' || $position === 0) {
            $fresh = $this->fromEmbedPage($shortCode, $kind);
            if ($fresh !== null) {
                Log::info('media_mirror.refreshed', ['short_code' => $shortCode, 'kind' => $kind, 'leg' => 'embed']);

                return $fresh;
            }
        }

        $fresh = $this->fromSinglePostScrape($shortCode, $kind, $position);
        if ($fresh !== null) {
            Log::info('media_mirror.refreshed', ['short_code' => $shortCode, 'kind' => $kind, 'leg' => 'actor']);
        }

        return $fresh;
    }

    private function fromEmbedPage(string $shortCode, string $kind): ?string
    {
        $result = $this->fetcher->tryFetch(
            "https://www.instagram.com/p/{$shortCode}/embed/",
            ['User-Agent' => self::EMBED_UA],
        );
        $html = is_array($result) && ($result['status'] ?? 0) < 400 ? (string) ($result['body'] ?? '') : '';
        if ($html === '') {
            return null;
        }

        // The embed payload double-escapes: "video_url\":\"https:\\/\\/…\"".
        $field = $kind === 'video' ? 'video_url' : 'display_url';
        if (preg_match('/'.$field.'\\\\?":\\\\?"((?:[^"\\\\]|\\\\.)+?)\\\\?"/', $html, $m) !== 1) {
            return null;
        }
        $url = str_replace(['\\/', '\\u0026'], ['/', '&'], stripslashes($m[1]));

        return $this->validFbcdnUrl($url) ? $url : null;
    }

    private function fromSinglePostScrape(string $shortCode, string $kind, int $position): ?string
    {
        $token = config('services.apify.token');
        if (! $token) {
            return null;
        }

        try {
            $resp = Http::timeout(90)
                ->post('https://api.apify.com/v2/acts/apify~instagram-scraper/run-sync-get-dataset-items?token='.$token.'&timeout=80', [
                    'directUrls' => ["https://www.instagram.com/p/{$shortCode}/"],
                    'resultsType' => 'posts',
                    'resultsLimit' => 1,
                ]);
        } catch (\Throwable $e) {
            Log::info('media_mirror.refresh_actor_failed', ['short_code' => $shortCode, 'error' => $e->getMessage()]);

            return null;
        }
        if (! $resp->successful()) {
            return null;
        }

        $post = (array) ($resp->json()[0] ?? []);
        if ($kind === 'video') {
            $url = (string) ($post['videoUrl'] ?? '');

            return $this->validFbcdnUrl($url) ? $url : null;
        }

        // Images: carousel children by position; a single post is child 0.
        $children = (array) ($post['childPosts'] ?? []);
        $candidate = $children === []
            ? (string) ($post['displayUrl'] ?? '')
            : (string) (($children[$position] ?? $children[0] ?? [])['displayUrl'] ?? '');

        return $this->validFbcdnUrl($candidate) ? $candidate : null;
    }

    /** Only Instagram's own CDNs are acceptable refresh targets. */
    private function validFbcdnUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return preg_match('/(^|\.)cdninstagram\.com$|(^|\.)fbcdn\.net$/', $host) === 1;
    }
}
