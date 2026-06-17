<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\MetadataParser;
use App\Services\SmartLinks\SafeUrlFetcher;

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
     * be fetched.
     *
     * @return array{url:string, name:?string, description:?string, favicon:?string, logo:?string}|null
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
        $logo = $this->parser->absolutize($meta->og('og:image'), $finalUrl) ?? $meta->headerLogoUrl;

        return [
            'url' => $finalUrl,
            'name' => $name,
            'description' => $description,
            'favicon' => $favicon,
            'logo' => $logo,
        ];
    }

    /** @param array<string,string> $meta */
    private function metaName(array $meta, string $key): ?string
    {
        $v = $meta[$key] ?? null;

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
