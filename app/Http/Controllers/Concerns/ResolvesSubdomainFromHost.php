<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

// V2: Extracts the subdomain from the request host by stripping the configured public domain suffix, with route parameter fallback.
trait ResolvesSubdomainFromHost
{
    /**
     * Resolve subdomain from a trusted Origin, then the X-Site-Subdomain header,
     * then query/input (subdomain or slug keys), then host.
     */
    protected function resolveSiteSubdomain(Request $request): ?string
    {
        // P2-35: a browser sends an UNforgeable Origin. When it names a genuine
        // tenant mini-site (<handle>.public_domain, label not reserved) we trust it
        // over the client-supplied X-Site-Subdomain header — so a page on
        // attacker.partna.au cannot inject leads/enquiries/subscriptions into
        // another tenant by spoofing the header. Requests with no Origin
        // (server/proxy/SSR calls) or a reserved/first-party Origin fall through to
        // the existing header → query → host resolution unchanged. This is
        // defence-in-depth: it closes the browser-driven cross-tenant vector; a
        // non-browser client can still forge both, so the published-site check and
        // bot-protection remain the primary guards.
        $fromOrigin = $this->subdomainFromTrustedOrigin($request);
        if ($fromOrigin !== null) {
            return $fromOrigin;
        }

        $fromHeader = trim((string) $request->header('X-Site-Subdomain', ''));
        if ($fromHeader !== '') {
            return strtolower($fromHeader);
        }

        foreach (['subdomain', 'slug'] as $key) {
            $fromQuery = trim((string) $request->query($key, ''));
            if ($fromQuery !== '') {
                return strtolower($fromQuery);
            }
            $fromInput = trim((string) $request->input($key, ''));
            if ($fromInput !== '') {
                return strtolower($fromInput);
            }
        }

        $fromHost = $this->resolveSubdomainFromHost($request);

        return $fromHost ? strtolower($fromHost) : null;
    }

    /**
     * Extract a tenant subdomain from the request Origin, but only when it is a
     * genuine mini-site host: <handle>.public_domain, a single DNS-safe label that
     * is not a reserved subdomain. Returns null for an absent, first-party,
     * reserved, nested, or malformed Origin — leaving the header/query/host
     * resolution in charge. (P2-35)
     */
    private function subdomainFromTrustedOrigin(Request $request): ?string
    {
        $origin = trim((string) $request->header('Origin', ''));
        if ($origin === '') {
            return null;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        $publicDomain = config('partna.public_domain');
        if (! is_string($host) || $host === '' || ! $publicDomain) {
            return null;
        }

        $suffix = '.'.ltrim((string) $publicDomain, '.');
        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $handle = strtolower(substr($host, 0, -strlen($suffix)));

        // Single-label tenant handles only: reject nested (a.b.domain) and any
        // value that isn't a DNS-safe handle.
        if ($handle === '' || str_contains($handle, '.')) {
            return null;
        }
        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $handle)) {
            return null;
        }

        // Reserved labels (www, app, api, …) are never real tenants — treat such
        // an Origin as first-party and defer to the existing resolution.
        $reserved = array_map('strtolower', (array) config('partna.reserved_subdomains', []));
        if (in_array($handle, $reserved, true)) {
            return null;
        }

        return $handle;
    }

    /**
     * Extract subdomain from requests host based on public domain config.
     */
    protected function resolveSubdomainFromHost(Request $request): ?string
    {
        // Try route parameter first
        $routeSubdomain = $request->route('subdomain');
        if (is_string($routeSubdomain) && $routeSubdomain !== '') {
            return strtolower($routeSubdomain);
        }

        // Extract from host
        $host = $request->getHost();
        $publicDomain = config('partna.public_domain');

        if (! $publicDomain || ! str_ends_with($host, $publicDomain)) {
            return null;
        }

        $suffix = '.'.ltrim($publicDomain, '.');
        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $subdomain = substr($host, 0, -strlen($suffix));

        return $subdomain !== '' ? strtolower($subdomain) : null;
    }
}
