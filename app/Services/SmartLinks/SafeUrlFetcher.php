<?php

namespace App\Services\SmartLinks;

use Illuminate\Support\Facades\Http;

/**
 * SSRF-guarded outbound fetcher. Every smart-link / custom-link fetch of a
 * user-supplied URL goes through here.
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
