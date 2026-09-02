<?php

namespace App\Routing;

use App\Services\Analytics\Concerns\EscalatesRepeatedFaults;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Log;

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
    use EscalatesRepeatedFaults;

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
        // Google Forms short links (2026-09-02, teegandyson): forms.gle answers
        // a browser-shaped fetch with a client-side deep-link page and no
        // share meta; the docs.google.com destination carries og:image.
        'forms.gle',
    ];

    /** @return list<string> */
    public static function platformShortHosts(): array
    {
        return self::PLATFORM_SHORT_HOSTS;
    }

    private const SUCCESS_TTL_SECONDS = 86400;

    private const FAILURE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly CacheLockService $cache,
    ) {}

    /**
     * The final destination when $url is a known short link and the redirect
     * chain resolves — otherwise $url unchanged. Never throws.
     */
    public function expandIfShort(string $url): string
    {
        if (! $this->isShort($url)) {
            return $url;
        }

        // CCH-2: this was an unlocked read-fetch-write on a global (non-user-
        // scoped) key — an uncoordinated stampede on a hot short link stampedes
        // the upstream host. Single-flight through CacheLockService instead.
        //
        // rememberLockedNullable, not rememberLocked: resolveFinal() can
        // legitimately return null (unresolvable/failed expansion), and
        // rememberLocked's docblock forbids that — feeding null through it
        // would write null to both the primary key and its stale twin,
        // poisoning the last-known-good expansion on a transient failure.
        // Do not "upgrade" this to rememberLocked.
        $key = CacheKeyGenerator::shortLinkExpansion($url);

        $expanded = $this->cache->rememberLockedNullable(
            $key,
            self::SUCCESS_TTL_SECONDS,
            fn (): ?string => $this->resolveFinal($url),
            // Do NOT change these TTLs — a long negative TTL on a transient
            // failure is a worse bug than the stampede this fix closes.
            nullTtl: self::FAILURE_TTL_SECONDS,
            lockSeconds: 20,
            // blockSeconds 2, deliberately below the 5s default:
            // RoutingController::preview runs inside an 8s FetchBudget, and
            // SafeUrlFetcher throws once remaining() <= 0 — a 5s block would
            // burn 62% of that budget before the fetch even starts.
            blockSeconds: 2,
        );

        // Deploy-window absorber, not defensive noise: legacy '' sentinels
        // written by the pre-lock code are !== null, so rememberLockedNullable
        // would hand one back verbatim and this method would return an empty
        // string as the "expanded" URL for up to FAILURE_TTL_SECONDS after
        // deploy. Safe to delete once those old entries have all expired.
        return is_string($expanded) && $expanded !== '' ? $expanded : $url;
    }

    /**
     * Resolve $url's redirect chain to its terminal destination, or null if it
     * doesn't resolve to something worth caching as an expansion.
     */
    private function resolveFinal(string $url): ?string
    {
        $final = null;

        try {
            // Redirect-only resolution (T24/issue 21, 2026-08-28): only the
            // chain matters, and the DESTINATION's behaviour must not veto it.
            // tryFetch()'s all-or-nothing contract lost the already-resolved
            // finalUrl whenever the terminal host bot-blocked (spoti.fi →
            // open.spotify.com refused the GET → null → negative-cached for
            // an hour → the Spotify connect never happened). Same lesson as
            // T1.5g's byte-cap incarnation, now closed structurally:
            // tryResolveFinalUrl keeps the last resolved hop through terminal
            // failures.
            $candidate = $this->fetcher->tryResolveFinalUrl($url);

            if (is_string($candidate)
                && $candidate !== ''
                && ! $this->isShort($candidate)
                && preg_match('~^https?://~i', $candidate) === 1
            ) {
                $final = $candidate;
            }
        } catch (\Throwable $e) {
            // tryFetch already swallows the expected failure shapes; anything
            // else (budget exhaustion mid-run) is equally a "keep the URL", so
            // this stays fail-open. CCH-5: it was also entirely SILENT, and the
            // null it returns is negative-cached for FAILURE_TTL_SECONDS — so a
            // real defect looked exactly like "not expandable" for an hour with
            // nothing reaching Nightwatch. Breadcrumb every time; only a
            // SUSTAINED run escalates (EscalatesRepeatedFaults, same as
            // ContentPopularityReader). The TTLs are deliberate — do not touch.
            // #W2-SEC-17: $url is user-pasted, and for THIS class's target
            // hosts (bit.ly-style shorteners, on.soundcloud.com, spotify.link)
            // the path IS the opaque short-code — the whole identifying secret,
            // not an incidental slug — so it gets dropped entirely, matching
            // MediaMirror's host-only log-hygiene convention (host, never path,
            // e.g. app/Services/Media/MediaMirror.php ~:479-483). Logging
            // host+path would reconstruct the pasted short URL verbatim, which
            // is the same leak one segment over. Path length is logged instead
            // of the path itself, as a coarse diagnostic signal that carries no
            // identifying content. This is strictly about what gets LOGGED: the
            // routing lane still stores the URL verbatim in
            // routing.link_observations by design.
            Log::warning('routing.shortlink_expand_failed', [
                'host' => parse_url($url, PHP_URL_HOST),
                'path_length' => strlen((string) parse_url($url, PHP_URL_PATH)),
                'error' => $e->getMessage(),
            ]);
            self::escalateIfSustained($e, 'shortlink_expand');
        }

        return $final;
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
