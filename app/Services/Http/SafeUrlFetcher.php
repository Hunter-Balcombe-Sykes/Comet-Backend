<?php

namespace App\Services\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SSRF-guarded outbound fetcher. Every custom-link and platform-scraper fetch
 * of a user-supplied URL goes through here.
 *
 * Guarantees before any byte is fetched:
 *   - scheme ∈ {http, https}
 *   - host resolves to public IPs only (rejects private/loopback/link-local/
 *     reserved ranges incl. the 169.254.169.254 cloud-metadata endpoint)
 *   - redirects are followed manually (max 5) and EACH hop is re-validated
 *
 * Not a hostile-pentest-grade defence (DNS-rebinding TOCTOU is out of scope),
 * but closes the practical SSRF surface of "paste an internal URL".
 */
class SafeUrlFetcher
{
    /** Fallback defaults for config('partna.http_fetch.*') — kept in sync with config/partna.php. */
    private const MAX_REDIRECTS = 5;

    private const TIMEOUT_SECONDS = 8;

    /** 10 MB — fallback for config('partna.http_fetch.max_bytes'). */
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** CFG-2: fallback for config('partna.http_fetch.user_agent') — browser-ish UA, some providers 403 obvious bots / empty UAs. */
    private const USER_AGENT = 'Mozilla/5.0 (compatible; PartnaBot/1.0; +https://partna.au)';

    /**
     * CFG-2: fallback for config('partna.http_fetch.fallback_user_agent') — honest bot UA
     * for the 403 retry. Some hosting WAFs (SiteGround et al.) 403 any `Mozilla/…` UA
     * whose TLS fingerprint isn't a real browser — spoofed-browser and
     * `Mozilla/5.0 (compatible; …)` strings both trip it, while a plain non-Mozilla bot
     * UA passes. Must NOT start with "Mozilla/". Kept public — callers assert against it.
     */
    public const FALLBACK_USER_AGENT = 'PartnaBot/1.0 (+https://partna.au)';

    /** Fallback default for config('partna.http_fetch.connect_timeout_seconds'). */
    private const CONNECT_TIMEOUT_SECONDS = 3;

    private readonly int $maxRedirects;

    private readonly int $timeoutSeconds;

    private readonly int $maxBytes;

    private readonly int $connectTimeoutSeconds;

    private readonly string $userAgent;

    private readonly string $fallbackUserAgent;

    /**
     * The wall-clock budget is NOT owned here — it's a request/job-scoped
     * concern shared with other collaborators (e.g. YoutubeThumbnailResolver,
     * which deliberately bypasses this class for its SSRF-free i.ytimg.com
     * probes but must still respect the same deadline). See FetchBudget's
     * docblock for why this was extracted out of SafeUrlFetcher.
     */
    public function __construct(private readonly FetchBudget $budget)
    {
        $this->maxRedirects = (int) config('partna.http_fetch.max_redirects', self::MAX_REDIRECTS);
        $this->timeoutSeconds = (int) config('partna.http_fetch.timeout_seconds', self::TIMEOUT_SECONDS);
        $this->maxBytes = (int) config('partna.http_fetch.max_bytes', self::MAX_BYTES);
        $this->connectTimeoutSeconds = (int) config('partna.http_fetch.connect_timeout_seconds', self::CONNECT_TIMEOUT_SECONDS);
        $this->userAgent = (string) config('partna.http_fetch.user_agent', self::USER_AGENT);
        $this->fallbackUserAgent = (string) config('partna.http_fetch.fallback_user_agent', self::FALLBACK_USER_AGENT);
    }

    /**
     * Fetch a URL safely. Returns the final response body + metadata, or throws.
     *
     * @return array{status:int, body:string, finalUrl:string, contentType:string, etag:?string, lastModified:?string}
     *
     * @throws SafeUrlException
     * @throws ConnectionException
     */
    public function fetch(string $url, array $headers = []): array
    {
        $merged = array_merge([
            'User-Agent' => $this->userAgent,
            'Accept' => 'text/html,application/json,application/ld+json;q=0.9,*/*;q=0.8',
        ], $headers);

        $result = $this->fetchFollowingRedirects($url, $merged);

        // Anti-bot 403s: some hosting WAFs block any `Mozilla/…` UA whose TLS
        // fingerprint isn't a real browser (spoofed-browser AND
        // "(compatible; …)" bot strings both trip it) while an honest
        // non-Mozilla bot UA passes. Retry once with FALLBACK_USER_AGENT —
        // only on 403, only when the UA could be the reason, and never
        // worse than the original outcome.
        if ($result['status'] === 403 && str_starts_with((string) $merged['User-Agent'], 'Mozilla/')) {
            try {
                $retry = $this->fetchFollowingRedirects(
                    $url,
                    array_merge($merged, ['User-Agent' => $this->fallbackUserAgent]),
                );
                if ($retry['status'] < 400) {
                    return $retry;
                }
            } catch (SafeUrlException|ConnectionException) {
                // Retry failed harder than the original (SSRF re-check or
                // transport failure) — keep the 403.
            }
        }

        return $result;
    }

    /**
     * The core fetch loop: follow up to maxRedirects 3xx hops, SSRF-validating
     * every hop, with the headers exactly as given (no defaults merged here).
     *
     * @return array{status:int, body:string, finalUrl:string, contentType:string, etag:?string, lastModified:?string}
     *
     * @throws SafeUrlException
     * @throws ConnectionException
     */
    private function fetchFollowingRedirects(string $url, array $headers): array
    {
        $current = $url;

        for ($hop = 0; $hop <= $this->maxRedirects; $hop++) {
            // Budget check BEFORE assertSafe(): a DNS-resolving assertSafe() call
            // is itself real wall-clock time we don't want to spend once the
            // deadline has already passed.
            $remaining = $this->budget->remaining();
            if ($remaining !== null && $remaining <= 0) {
                throw new SafeUrlException("Fetch budget exhausted for {$current}");
            }

            $this->assertSafe($current);

            // No open budget → the flat per-hop timeout, unchanged. With a
            // budget, the hop gets whatever is smaller: the configured per-hop
            // ceiling, or what's left of the operation's wall clock.
            $hopTimeout = $remaining === null
                ? $this->timeoutSeconds
                : (int) max(1, ceil(min($this->timeoutSeconds, $remaining)));

            $response = Http::withHeaders($headers)
                ->timeout($hopTimeout)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->withoutRedirecting()
                ->get($current);

            $status = $response->status();

            // Follow 3xx manually so each Location is re-validated.
            if ($status >= 300 && $status < 400 && $response->header('Location')) {
                $location = $response->header('Location');
                $current = $this->resolveRedirect($current, $location);

                continue;
            }

            $this->assertWithinByteCap($response);

            return [
                'status' => $status,
                'body' => $response->body(),
                'finalUrl' => $current,
                'contentType' => (string) $response->header('Content-Type'),
                // Conditional-request validators (Plan 5). getHeaderLine() returns ''
                // when absent → null. A 304 lands here (no Location header ⇒ not a
                // followed redirect), carrying its validators back to the caller.
                'etag' => $response->header('ETag') ?: null,
                'lastModified' => $response->header('Last-Modified') ?: null,
            ];
        }

        throw new SafeUrlException("Too many redirects fetching {$url}");
    }

    /**
     * fetch(), but transport-level failures (unresolvable host, SSRF
     * rejection, timeout, refused connection) return null instead of
     * throwing — the same swallow semantics fetchMany() applies per URL.
     * HTTP error statuses (403/404/500…) still return the response array.
     *
     * The platform scrapers use this: a user-pasted URL that doesn't resolve
     * is "platform unavailable", not an application error.
     *
     * @return array{status:int, body:string, finalUrl:string, contentType:string, etag:?string, lastModified:?string}|null
     */
    public function tryFetch(string $url, array $headers = []): ?array
    {
        try {
            return $this->fetch($url, $headers);
        } catch (SafeUrlException|ConnectionException) {
            return null;
        }
    }

    /**
     * Fetch multiple URLs concurrently with the same SSRF guarantees as fetch().
     *
     * Each URL is pre-validated (scheme + public-IP check). The initial GETs fire
     * concurrently via Http::pool with redirects disabled. Any 3xx response is then
     * resolved + re-validated and followed in a second concurrent pass. This two-pass
     * approach caps redirect chains to MAX_REDIRECTS total hops per URL (matching the
     * serial fetch() contract) while keeping the bulk of I/O parallel.
     *
     * URLs that fail validation or exceed MAX_REDIRECTS are silently dropped (null) —
     * unlike fetch(), which throws. Callers needing a hard failure per URL use fetch().
     *
     * @param  array<string>  $urls  Original URLs to fetch (duplicates are de-duped).
     * @param  array<string,string>  $headers  Additional HTTP headers to include.
     * @return array<string, array{status:int, body:string, finalUrl:string, contentType:string}|null>
     *                                                                                                 Keyed by original URL; null if skipped/failed.
     */
    public function fetchMany(array $urls, array $headers = []): array
    {
        if ($urls === []) {
            return [];
        }

        $mergedHeaders = array_merge([
            'User-Agent' => $this->userAgent,
            'Accept' => 'text/html,application/json,application/ld+json;q=0.9,*/*;q=0.8',
        ], $headers);

        $results = $this->fetchManyFollowingRedirects($urls, $mergedHeaders);

        // WS-B1: the same one-shot honest-UA fallback fetch() applies. Some hosting
        // WAFs (SiteGround et al.) 403 any `Mozilla/…` UA whose TLS fingerprint isn't
        // a real browser, while an honest non-Mozilla bot UA passes. Retry ONLY the
        // URLs that came back 403, only when the browser UA could be the reason, once,
        // and never worse than the original 403 — so bulk refresh fleets (fetchMany)
        // of WAF-guarded WooCommerce stores don't silently fail where a single fetch()
        // would have recovered.
        if (str_starts_with((string) $mergedHeaders['User-Agent'], 'Mozilla/')) {
            $blocked = [];
            foreach ($results as $original => $res) {
                if (is_array($res) && $res['status'] === 403) {
                    $blocked[] = $original;
                }
            }

            if ($blocked !== []) {
                $retry = $this->fetchManyFollowingRedirects(
                    $blocked,
                    array_merge($mergedHeaders, ['User-Agent' => $this->fallbackUserAgent]),
                );
                foreach ($retry as $original => $res) {
                    if (is_array($res) && $res['status'] < 400) {
                        $results[$original] = $res;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * The concurrent redirect-following core of fetchMany(): pre-validate every URL,
     * fire the current round via Http::pool with redirects disabled, then resolve +
     * SSRF-re-validate + follow any 3xx in the next round, capped to MAX_REDIRECTS
     * hops per URL. Headers are used exactly as given (no defaults merged here), so
     * fetchMany() can re-run it with an alternate User-Agent for the 403 fallback.
     *
     * @param  array<string>  $urls  Original URLs to fetch (duplicates are de-duped).
     * @param  array<string,string>  $mergedHeaders
     * @return array<string, array{status:int, body:string, finalUrl:string, contentType:string}|null>
     *                                                                                                 Keyed by original URL; null if skipped/failed.
     */
    private function fetchManyFollowingRedirects(array $urls, array $mergedHeaders): array
    {
        // Results keyed by original URL; populated as each URL resolves.
        $results = array_fill_keys($urls, null);

        // Map original URL → current URL being fetched (tracks redirect target per URL).
        $pending = [];
        foreach ($urls as $original) {
            try {
                $this->assertSafe($original);
                $pending[$original] = ['current' => $original, 'hops' => 0];
            } catch (SafeUrlException) {
                // Pre-validation failed — leave null in results.
            }
        }

        // Up to MAX_REDIRECTS rounds; each round issues all still-pending URLs in one pool batch.
        while ($pending !== []) {
            $originals = array_keys($pending);
            $currentUrls = array_column($pending, 'current');

            // Fire the current round's URLs, capped to the configured concurrency so a
            // large organiser (many event pages) can't burst all at once against the
            // target host (SCALE-3, WAF-ban risk). Indices key the pool → map back.
            $responses = $this->pooledGet($currentUrls, $mergedHeaders);

            $nextPending = [];
            foreach ($originals as $i => $original) {
                $response = $responses[(string) $i] ?? null;
                if (! ($response instanceof Response)) {
                    // Connection error — drop this URL.
                    continue;
                }

                $status = $response->status();

                if ($status >= 300 && $status < 400 && $response->header('Location')) {
                    // 3xx: resolve and re-validate the redirect target before following.
                    $location = $response->header('Location');
                    $next = $this->resolveRedirect($pending[$original]['current'], $location);
                    $hops = $pending[$original]['hops'] + 1;

                    if ($hops > $this->maxRedirects) {
                        // Too many hops — drop this URL.
                        continue;
                    }

                    try {
                        // SSRF re-validation: every redirect target must also resolve to a
                        // public IP. This prevents an allow-listed host from 30x-redirecting
                        // to an internal address (open-redirect SSRF).
                        $this->assertSafe($next);
                        $nextPending[$original] = ['current' => $next, 'hops' => $hops];
                    } catch (SafeUrlException) {
                        // Redirect target failed SSRF check — drop silently.
                    }
                } else {
                    // Terminal response (2xx, 4xx, 5xx). Drop it (leave null) when
                    // the body blows the byte cap — same swallow semantics as a
                    // failed fetch.
                    if ($this->exceedsByteCap($response)) {
                        continue;
                    }
                    $results[$original] = [
                        'status' => $status,
                        'body' => $response->body(),
                        'finalUrl' => $pending[$original]['current'],
                        'contentType' => (string) $response->header('Content-Type'),
                    ];
                }
            }

            $pending = $nextPending;
        }

        return $results;
    }

    /**
     * GET a list of URLs concurrently, capped at the configured pool concurrency.
     * Preserves the numeric index as the pool key (via $pool->as((string) $i)) so the
     * caller maps responses back to its $originals; chunks are merged key-preserving.
     *
     * @param  array<int,string>  $currentUrls  0-indexed list of URLs for this round.
     * @param  array<string,string>  $mergedHeaders
     * @return array<string, Response|\Throwable> Keyed by "$i". Absent when the budget
     *                                            ran out before that chunk fired — see below.
     */
    private function pooledGet(array $currentUrls, array $mergedHeaders): array
    {
        $max = max(1, (int) config('partna.refresh.host_limits.fetch_many.pool_concurrency', 6));
        $responses = [];

        foreach (array_chunk($currentUrls, $max, true) as $chunk) {
            // Same budget check as fetchFollowingRedirects(), applied per chunk
            // rather than per URL — a pool batch is one round-trip, so there's no
            // cheaper place to cut it short. UNLIKE fetchFollowingRedirects(),
            // this must NOT throw: fetchMany()'s documented contract is to
            // silently drop URLs it couldn't reach (unlike fetch(), which
            // throws) — a caller such as BandcampScraper::enrichPrices() never
            // wraps this in a try/catch, so a throw here would violate that
            // caller's contract, not enforce ours. Breaking leaves the
            // remaining indices absent from $responses, which
            // fetchManyFollowingRedirects() already treats as "dropped" via
            // its `$responses[(string) $i] ?? null` lookup against $results'
            // array_fill_keys(..., null) baseline.
            $remaining = $this->budget->remaining();
            if ($remaining !== null && $remaining <= 0) {
                // One line per exhausted pooledGet() call (not per dropped URL)
                // — the only way to tell "dropped for budget" apart from
                // "dropped because the vendor 404'd", which otherwise look
                // identical to the caller.
                Log::warning('http_fetch.pooled_get.budget_exhausted', [
                    'dropped' => count($currentUrls) - count($responses),
                    'remaining_seconds' => $remaining,
                ]);

                break;
            }
            $chunkTimeout = $remaining === null
                ? $this->timeoutSeconds
                : (int) max(1, ceil(min($this->timeoutSeconds, $remaining)));

            $batch = Http::pool(fn (Pool $pool) => array_map(
                fn (int $i, string $url) => $pool->as((string) $i)
                    ->timeout($chunkTimeout)
                    ->connectTimeout($this->connectTimeoutSeconds)
                    ->withHeaders($mergedHeaders)
                    ->withoutRedirecting()
                    ->get($url),
                array_keys($chunk),
                array_values($chunk),
            ));
            $responses += $batch;
        }

        return $responses;
    }

    /**
     * Public SSRF policy check without fetching — for callers that hand the
     * URL to another system (e.g. the brand-scan Worker) but must still
     * enforce our public-address policy first.
     *
     * @throws SafeUrlException
     */
    public function assertPublicUrl(string $url): void
    {
        $this->assertSafe($url);
    }

    /**
     * Reject a terminal response whose body would blow the configured byte cap.
     *
     * Guzzle has already buffered the body by the time we see it, so this cannot
     * prevent the download itself — the cap's job is to stop an oversized/hostile
     * body from being handed to the scrapers/parsers downstream, and to reject a
     * server that *declares* an oversized Content-Length up front.
     *
     * @throws SafeUrlException
     */
    private function assertWithinByteCap(Response $response): void
    {
        if ($this->exceedsByteCap($response)) {
            throw new SafeUrlException("Response body exceeds the {$this->maxBytes}-byte cap");
        }
    }

    /** Declared Content-Length OR actual buffered body length over the byte cap. */
    private function exceedsByteCap(Response $response): bool
    {
        if ((int) $response->header('Content-Length') > $this->maxBytes) {
            return true;
        }

        return strlen($response->body()) > $this->maxBytes;
    }

    /** Resolve a (possibly relative) redirect Location against the current URL. */
    private function resolveRedirect(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$host}{$location}";
        }

        return "{$scheme}://{$host}/".ltrim($location, '/');
    }

    /** @throws SafeUrlException */
    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new SafeUrlException("Refusing non-http(s) URL: {$url}");
        }
        if ($host === '') {
            throw new SafeUrlException("URL has no host: {$url}");
        }

        // Literal IP hosts are checked directly; named hosts are resolved.
        $candidates = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : $this->resolveHost($host);

        if (empty($candidates)) {
            throw new SafeUrlException("Host did not resolve: {$host}");
        }

        foreach ($candidates as $ip) {
            // NO_PRIV_RANGE blocks 10/8, 172.16/12, 192.168/16, fc00::/7;
            // NO_RES_RANGE blocks 127/8, 169.254/16, ::1, 0.0.0.0, etc.
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new SafeUrlException("URL resolves to a non-public address ({$ip}): {$url}");
            }
        }
    }

    /** @return string[] resolved IPv4 + IPv6 addresses */
    private function resolveHost(string $host): array
    {
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $rec) {
                if (! empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }

        return $ips;
    }
}
