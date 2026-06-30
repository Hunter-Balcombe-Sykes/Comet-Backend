<?php

namespace App\Services\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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
    private const MAX_REDIRECTS = 5;

    private const TIMEOUT_SECONDS = 8;

    /** Browser-ish UA — some providers 403 obvious bots / empty UAs. */
    private const USER_AGENT = 'Mozilla/5.0 (compatible; PartnaBot/1.0; +https://partna.au)';

    /**
     * Fetch a URL safely. Returns the final response body + metadata, or throws.
     *
     * @return array{status:int, body:string, finalUrl:string, contentType:string}
     *
     * @throws SafeUrlException
     */
    public function fetch(string $url, array $headers = []): array
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $this->assertSafe($current);

            $response = Http::withHeaders(array_merge([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/json,application/ld+json;q=0.9,*/*;q=0.8',
            ], $headers))
                ->timeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->get($current);

            $status = $response->status();

            // Follow 3xx manually so each Location is re-validated.
            if ($status >= 300 && $status < 400 && $response->header('Location')) {
                $location = $response->header('Location');
                $current = $this->resolveRedirect($current, $location);

                continue;
            }

            return [
                'status' => $status,
                'body' => $response->body(),
                'finalUrl' => $current,
                'contentType' => (string) $response->header('Content-Type'),
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
     * @return array{status:int, body:string, finalUrl:string, contentType:string}|null
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
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'text/html,application/json,application/ld+json;q=0.9,*/*;q=0.8',
        ], $headers);

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

            // Fire all current URLs in one concurrent pool; key by index to map back.
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (int $i, string $url) => $pool->as((string) $i)
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders($mergedHeaders)
                    ->withoutRedirecting()
                    ->get($url),
                array_keys($currentUrls),
                $currentUrls,
            ));

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

                    if ($hops > self::MAX_REDIRECTS) {
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
                    // Terminal response (2xx, 4xx, 5xx) — record it.
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
    private function assertSafe(string $url): void
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
