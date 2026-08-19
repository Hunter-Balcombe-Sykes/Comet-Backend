<?php

namespace App\Services\Platforms;

use App\Services\Http\MetadataParser;
use App\Services\Http\SafeUrlFetcher;

// Fetches a URL once and snapshots its public identity (name, description,
// favicon, logo) for a branded link card. Shared by the custom-links
// integration, the smart-detect category fallbacks (booking / reservations),
// and the online-ordering entries. No refresh loop — one-shot at add time.
// SafeUrlFetcher also rejects SSRF-y targets.
class LinkCardScraper
{
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly MetadataParser $parser,
    ) {}

    /** Require an absolutable http(s) URL; default a bare domain to https. */
    public function normalizeUrl(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        if (! preg_match('~^https?://~i', $input)) {
            $input = 'https://'.$input;
        }
        $parts = parse_url($input);
        if (! is_array($parts) || ! isset($parts['host']) || ! str_contains($parts['host'], '.')) {
            return null;
        }

        return $input;
    }

    /**
     * Fetch the page and snapshot its public identity. Null when the page can't
     * be fetched. `storefront` names the store platform whose runtime markers
     * the HTML carries (T4 — the preview offers "connect it as a store" off
     * the page already in hand), null for a plain page.
     *
     * @return array{url:string, name:?string, description:?string, favicon:?string, logo:?string, storefront:?string}|null
     */
    public function snapshot(string $url): ?array
    {
        $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200 || ! is_string($res['body'] ?? null)) {
            return null;
        }

        $finalUrl = is_string($res['finalUrl'] ?? null) && $res['finalUrl'] !== '' ? $res['finalUrl'] : $url;
        $meta = $this->parser->parse($res['body'], $finalUrl);

        $name = $meta->og('og:title')
            ?? ($meta->title !== null && trim($meta->title) !== '' ? trim($meta->title) : null)
            ?? (parse_url($finalUrl, PHP_URL_HOST) ?: null);
        $description = $meta->og('og:description') ?? $this->metaName($meta->meta, 'description');
        $favicon = $meta->faviconUrl
            ?? $meta->appleTouchIconUrl
            ?? $this->parser->absolutize('/favicon.ico', $finalUrl);
        // The share image: og:image, then its secure twin, then Twitter's card
        // image (which many pages set INSTEAD of og:image), then a header
        // logo. Tested 2026-08-17: ABC/Vercel/Stripe hit on og:image; pages
        // that publish only twitter:image used to come back imageless.
        $share = $meta->og('og:image')
            ?? $meta->og('og:image:secure_url')
            ?? $this->metaName($meta->meta, 'twitter:image')
            ?? $this->metaName($meta->meta, 'twitter:image:src');
        $logo = $this->parser->absolutize($share, $finalUrl) ?? $meta->headerLogoUrl;

        return [
            'url' => $finalUrl,
            'name' => $name,
            'description' => $description,
            'favicon' => $favicon,
            'logo' => $logo,
            'storefront' => StorefrontMarkers::detect($res['body']),
        ];
    }

    /**
     * snapshot() but NEVER null — when the page can't be fetched (bot-blocked
     * platforms like Uber Eats / DoorDash, JS-only pages, timeouts), fall back to
     * a minimal card derived from the URL itself, so ANY link can still be
     * attached as a branded card. The server never re-fetches a stored link, so
     * skipping the fetch here introduces no SSRF surface.
     *
     * @return array{url:string, name:?string, description:?string, favicon:?string, logo:?string}
     */
    public function snapshotOrMinimal(string $url): array
    {
        return $this->snapshot($url) ?? $this->minimalCard($url);
    }

    /**
     * A best-effort card from the URL alone — the host as the name and a
     * conventional /favicon.ico guess (the dashboard shows a placeholder if it
     * doesn't load). Public so the async connect flow can call it directly to
     * return an immediate placeholder card before the snapshot job runs.
     *
     * @return array{url:string, name:?string, description:?string, favicon:?string, logo:?string}
     */
    public function minimalCard(string $url): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $domain = (string) preg_replace('~^www\.~', '', $host);

        return [
            'url' => $url,
            'name' => $domain !== '' ? $domain : $url,
            'description' => null,
            // The page itself is often bot-blocked (Uber Eats / DoorDash), so we
            // can't read its <link rel="icon">. Google's favicon service resolves
            // a brand icon for any domain; a miss degrades to a glyph in the UI.
            'favicon' => $domain !== '' ? 'https://www.google.com/s2/favicons?domain='.urlencode($domain).'&sz=64' : null,
            'logo' => null,
            'storefront' => null,
        ];
    }

    /** @param array<string,string> $meta */
    private function metaName(array $meta, string $key): ?string
    {
        $v = $meta[$key] ?? null;

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
