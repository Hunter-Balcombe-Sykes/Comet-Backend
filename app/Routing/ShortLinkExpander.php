<?php

namespace App\Routing;

use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Cache;

/**
 * The shortener-expansion layer IriCanonicalizer's docblock always promised
 * ("a runtime concern layered above this by the observer") and nobody built
 * (FI-3, scan-refinement run 2026-08-20). Reproduced live before this
 * existed: linktr.ee/samakhurst carried on.soundcloud.com/fh433tMk6lU9xgP3TM,
 * which expands to the SoundCloud ARTIST profile — but with no expansion it
 * fell to no-rule-matched and became a custom link card. Worse, the old
 * Hosts.php alias (on.soundcloud.com → soundcloud.com, removed with this)
 * let a LOWERCASE short code match the profile detector and mint a fake
 * SoundCloud "account" named after the code.
 *
 * One expansion pass per URL: SafeUrlFetcher follows the whole 3xx chain
 * internally (SSRF-validating every hop, capped at max_redirects), so the
 * result is terminal by construction — if it still lands on a short host,
 * something is looping and we give up rather than recurse.
 *
 * linktr.ee (and the other aggregator hosts) are deliberately NOT here even
 * though the canonicalizer lists linktr.ee as a shortener: an aggregator
 * page is CONTENT to unroll (LinkInBioDetector → LinkInBioImporter), not a
 * redirect to follow — expanding it would just return itself.
 *
 * Results are cached both ways (successes long, failures shorter) so
 * re-scans and the paste preview → paste route pair don't re-fetch.
 */
class ShortLinkExpander
{
    /**
     * Platform-owned short hosts. IriCanonicalizer rejects these as
     * 'shortener' too (via platformShortHosts()) — belt and braces: expansion
     * runs BEFORE canonicalize, so this only fires when expansion failed, and
     * without it an unreachable on.soundcloud.com code would fall through to
     * the soundcloud.com detectors via the registrable key (on. is a genuine
     * subdomain) and mint a fake profile.
     */
    private const PLATFORM_SHORT_HOSTS = [
        'on.soundcloud.com', 'spotify.link', 'spoti.fi', 'fb.watch', 'fb.me',
    ];

    /** @return list<string> */
    public static function platformShortHosts(): array
    {
        return self::PLATFORM_SHORT_HOSTS;
    }

    private const SUCCESS_TTL_SECONDS = 86400;

    private const FAILURE_TTL_SECONDS = 3600;

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * The final destination when $url is a known short link and the redirect
     * chain resolves — otherwise $url unchanged. Never throws.
     */
    public function expandIfShort(string $url): string
    {
        if (! $this->isShort($url)) {
            return $url;
        }

        $key = 'shortlink:'.sha1($url);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached === '' ? $url : $cached;
        }

        $final = null;

        try {
            // The body is irrelevant — only the redirect chain matters — but
            // HEAD support is unreliable on these hosts, so a GET it is. NO
            // withMaxBytes() tightening (T1.5g live lesson, 2026-08-20): the
            // fetcher THROWS when a body exceeds the cap rather than
            // truncating, and the DESTINATION page (a real SoundCloud profile,
            // ~300KB) tripped a 4KB cap after the redirect chain had already
            // resolved — the finalUrl was known and thrown away. The default
            // 10MB cap bounds abuse; wall-clock is bounded by the caller's
            // FetchBudget.
            $result = $this->fetcher->tryFetch($url);
            $candidate = $result['finalUrl'] ?? null;

            if (is_string($candidate)
                && $candidate !== ''
                && ! $this->isShort($candidate)
                && preg_match('~^https?://~i', $candidate) === 1
            ) {
                $final = $candidate;
            }
        } catch (\Throwable) {
            // tryFetch already swallows the expected failure shapes; anything
            // else (budget exhaustion mid-run) is equally a "keep the URL".
        }

        Cache::put($key, $final ?? '', $final === null ? self::FAILURE_TTL_SECONDS : self::SUCCESS_TTL_SECONDS);

        return $final ?? $url;
    }

    /** Whether this URL's host is on the expandable short-host list. */
    public function isShort(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower(rtrim($host, '.'));

        foreach (self::PLATFORM_SHORT_HOSTS as $short) {
            if ($host === $short) {
                return true;
            }
        }

        foreach (IriCanonicalizer::shortenerDomains() as $domain) {
            // Aggregators are unrollable pages, not redirects — see docblock.
            if ($domain === 'linktr.ee') {
                continue;
            }
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }
}
