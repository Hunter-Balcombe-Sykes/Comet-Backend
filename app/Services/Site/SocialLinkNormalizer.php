<?php

namespace App\Services\Site;

use InvalidArgumentException;

/**
 * Single source of truth for social link validation, normalization, and canonical
 * URL building. Reads the registry from config('partna.social_platforms') and
 * applies platform-specific rules to user input.
 *
 * Used by:
 *   - UserLinkBlockController on link create/update (write path)
 *   - PublicConfigController for the GET /public/config/social-platforms endpoint
 *   - BackfillSocialLinksCommand for the one-shot tagging migration
 *
 * Security model:
 *   - Handle inputs are validated against bounded ASCII-only regexes (homoglyph + ReDoS protection).
 *   - URL inputs are parsed via parse_url() and host-checked against an allowlist.
 *   - Canonical URLs are always rebuilt from url_template, which is https-only.
 *   - getPublicRegistry() strips internal validation fields so they never leak to clients.
 *
 * See docs/social-links.md for the full conceptual model.
 */
class SocialLinkNormalizer
{
    /**
     * Return the public-safe view of the registry — display name, icon key,
     * placeholder, and category only. Strips handle_pattern, host_allowlist,
     * url_path_extractor, url_template, and handle_location so internal
     * validation logic never reaches the wire.
     *
     * `default_category` in the stored registry is renamed to `category` in
     * the output — consumers don't need to know there's an override mechanism.
     *
     * @return array<int, array{key: string, display_name: string, icon_key: string, placeholder: string, category: string}>
     */
    public function getPublicRegistry(): array
    {
        $registry = config('partna.social_platforms', []);
        $public = [];

        foreach ($registry as $key => $config) {
            $public[] = [
                'key' => $key,
                'display_name' => $config['display_name'],
                'icon_key' => $config['icon_key'],
                'placeholder' => $config['placeholder'],
                'category' => $config['default_category'],
            ];
        }

        return $public;
    }

    public function isKnownPlatform(string $key): bool
    {
        return array_key_exists($key, config('partna.social_platforms', []));
    }

    /**
     * Validate and normalize a social link from either a handle or a URL.
     *
     * Algorithm:
     *   1. Handle path: strip leading '@', trim whitespace, validate against the
     *      platform's handle_pattern, build canonical URL via url_template.
     *   2. URL path: parse host via parse_url(), check against host_allowlist.
     *      Try to extract a handle via url_path_extractor — if it matches,
     *      recurse into the handle path (gives a clean canonical URL). If not
     *      (e.g. a deep link to a post), keep the URL as-is, no handle extracted.
     *   3. Neither provided: throw.
     *
     * @return array{url: string, handle: string|null, icon_key: string, display_name: string, platform_key: string}
     *
     * @throws InvalidArgumentException with a user-friendly message on validation failure.
     */
    public function normalize(string $platformKey, ?string $handle, ?string $url): array
    {
        $config = $this->resolvePlatform($platformKey);

        // Handle path takes precedence — produces the cleanest canonical URL.
        if ($handle !== null && $handle !== '') {
            return $this->normalizeHandle($platformKey, $config, $handle);
        }

        if ($url !== null && $url !== '') {
            return $this->normalizeUrl($platformKey, $config, $url);
        }

        throw new InvalidArgumentException("Provide either a handle or a URL for {$config['display_name']}.");
    }

    /**
     * Try to extract a clean handle from a URL belonging to this platform.
     * Returns null when the URL doesn't follow the simple `host/{handle}` shape
     * (e.g. deep links like instagram.com/p/abc123 — valid URL, no handle to lift).
     *
     * Used by the backfill command and the URL-path normalization flow.
     */
    public function extractHandleFromUrl(string $platformKey, string $url): ?string
    {
        $config = $this->resolvePlatform($platformKey);

        $parsed = parse_url($url);
        if (! is_array($parsed) || ! isset($parsed['host'])) {
            return null;
        }

        // parse_url() decodes punycode for us, so an IDN host like xn--... will
        // be returned as the punycode string itself. Our host_allowlist is plain
        // ASCII, so any punycode lookalike fails the allowlist check naturally.
        // rtrim strips trailing dot from FQDN notation (e.g. "foo.substack.com.").
        $host = rtrim(strtolower($parsed['host']), '.');

        // Subdomain-mode platforms encode the handle in the leftmost label.
        if (($config['handle_location'] ?? 'path') === 'subdomain') {
            return $this->extractSubdomainHandle($config, $host);
        }

        if (! isset($parsed['path'])) {
            return null;
        }

        if (! in_array($host, $config['host_allowlist'], true)) {
            return null;
        }

        if (preg_match($config['url_path_extractor'], $parsed['path'], $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return array{url: string, handle: string, icon_key: string, display_name: string, platform_key: string}
     */
    private function normalizeHandle(string $platformKey, array $config, string $handle): array
    {
        $cleaned = ltrim(trim($handle), '@');

        if (preg_match($config['handle_pattern'], $cleaned) !== 1) {
            throw new InvalidArgumentException(
                "Invalid {$config['display_name']} handle. Expected format: {$config['placeholder']}."
            );
        }

        // Both path-mode and subdomain-mode use {handle} substitution; the
        // url_template baked the mode-specific shape in at registry time.
        return [
            'url' => str_replace('{handle}', $cleaned, $config['url_template']),
            'handle' => $cleaned,
            'icon_key' => $config['icon_key'],
            'display_name' => $config['display_name'],
            'platform_key' => $platformKey,
        ];
    }

    /**
     * @return array{url: string, handle: string|null, icon_key: string, display_name: string, platform_key: string}
     */
    private function normalizeUrl(string $platformKey, array $config, string $url): array
    {
        $parsed = parse_url($url);

        if (! is_array($parsed) || ! isset($parsed['host'])) {
            throw new InvalidArgumentException(
                "That doesn't look like a valid URL for {$config['display_name']}."
            );
        }

        // rtrim strips trailing dot from FQDN notation (e.g. "foo.substack.com.").
        $host = rtrim(strtolower($parsed['host']), '.');

        if (($config['handle_location'] ?? 'path') === 'subdomain') {
            return $this->normalizeSubdomainUrl($platformKey, $config, $host, $parsed);
        }

        // Path-mode (existing behaviour)
        if (! in_array($host, $config['host_allowlist'], true)) {
            throw new InvalidArgumentException(
                "That URL doesn't belong to {$config['display_name']}. Expected one of: ".implode(', ', $config['host_allowlist']).'.'
            );
        }

        // Try to extract a handle from the path. If we can, recurse into the
        // handle path so the stored URL is the clean canonical form (no query
        // params, no www, always https). If we can't (deep link, post URL),
        // keep the URL as-is — but we can't guarantee https/canonicalization
        // for arbitrary deep links, so we at least force the scheme to https.
        $path = $parsed['path'] ?? '/';
        if (preg_match($config['url_path_extractor'], $path, $matches) === 1) {
            return $this->normalizeHandle($platformKey, $config, $matches[1]);
        }

        // Lenient deep-link path: rebuild the URL with forced https + the original
        // path/query/fragment. No handle extracted.
        $rebuilt = 'https://'.$host.$path;
        if (isset($parsed['query']) && $parsed['query'] !== '') {
            $rebuilt .= '?'.$parsed['query'];
        }
        if (isset($parsed['fragment']) && $parsed['fragment'] !== '') {
            $rebuilt .= '#'.$parsed['fragment'];
        }

        return [
            'url' => $rebuilt,
            'handle' => null,
            'icon_key' => $config['icon_key'],
            'display_name' => $config['display_name'],
            'platform_key' => $platformKey,
        ];
    }

    /**
     * Subdomain-mode URL normalization.
     *
     * Host validation is a labelled-suffix check against the registry's base
     * domain (host_allowlist[0]). The leading dot is essential — without it,
     * `evilsubstack.com` would match `substack.com`. That is an open-phishing
     * vulnerability; see docs/social-links.md §8 and the spec's §8.2.
     *
     * If the host is the bare base (e.g. `substack.com` with no subdomain), we
     * reject — there's no handle to extract and no sensible canonical URL.
     *
     * If the leftmost label passes the handle_pattern AND the path is the bare
     * root (`/` or empty), recurse into normalizeHandle to get the clean URL.
     * Otherwise fall back to lenient storage: keep the URL, force https, no
     * handle extracted (e.g. `alice.substack.com/p/my-post`).
     *
     * @param  array{host?: string, path?: string, query?: string, fragment?: string}  $parsed
     * @return array{url: string, handle: string|null, icon_key: string, display_name: string, platform_key: string}
     */
    private function normalizeSubdomainUrl(string $platformKey, array $config, string $host, array $parsed): array
    {
        $base = $config['host_allowlist'][0];

        // Labelled-suffix match: host must be "X.base" for some non-empty X.
        // Bare base (`substack.com`) is rejected — no handle present.
        if ($host === $base || ! str_ends_with($host, '.'.$base)) {
            throw new InvalidArgumentException(
                "That URL doesn't belong to {$config['display_name']}. Expected a {$base} subdomain."
            );
        }

        $candidate = $this->extractSubdomainHandle($config, $host);

        $path = $parsed['path'] ?? '/';
        $hasQuery = isset($parsed['query']) && $parsed['query'] !== '';
        $hasFragment = isset($parsed['fragment']) && $parsed['fragment'] !== '';

        // Root-URL fast path: candidate must be extractable AND
        // path must be empty-or-slash-only AND no query/fragment.
        if (
            $candidate !== null &&
            in_array($path, ['', '/'], true) &&
            ! $hasQuery &&
            ! $hasFragment
        ) {
            return $this->normalizeHandle($platformKey, $config, $candidate);
        }

        // Lenient deep-link path: preserve the URL but force https. Candidate
        // handle may be invalid (e.g. multi-label subdomain) or URL has extra
        // path/query — we still trust the host validation above and store as-is.
        $rebuilt = 'https://'.$host.$path;
        if ($hasQuery) {
            $rebuilt .= '?'.$parsed['query'];
        }
        if ($hasFragment) {
            $rebuilt .= '#'.$parsed['fragment'];
        }

        return [
            'url' => $rebuilt,
            'handle' => null,
            'icon_key' => $config['icon_key'],
            'display_name' => $config['display_name'],
            'platform_key' => $platformKey,
        ];
    }

    /**
     * Extract the handle from the leftmost subdomain label for subdomain-mode platforms.
     *
     * Returns the handle string if the host is a valid "X.base" subdomain and the
     * leftmost label matches handle_pattern. Returns null if the host is not on this
     * platform, or if the leftmost label does not pass handle_pattern (e.g. a
     * multi-label subdomain). Never throws — callers that need throw semantics on a
     * bad host must perform their own host validation first (see normalizeSubdomainUrl).
     */
    private function extractSubdomainHandle(array $config, string $host): ?string
    {
        $base = $config['host_allowlist'][0];

        if ($host === $base || ! str_ends_with($host, '.'.$base)) {
            return null; // not on this platform
        }

        $subdomainPortion = substr($host, 0, strlen($host) - strlen($base) - 1);
        $labels = explode('.', $subdomainPortion);
        $candidate = $labels[0] ?? '';

        if (preg_match($config['handle_pattern'], $candidate) === 1) {
            return $candidate;
        }

        return null; // valid host, no extractable handle (e.g. multi-label subdomain)
    }

    /**
     * @return array{display_name: string, icon_key: string, placeholder: string, handle_pattern: string, url_template: string, host_allowlist: array<int, string>, url_path_extractor: string, default_category: string, handle_location: string}
     */
    private function resolvePlatform(string $platformKey): array
    {
        $registry = config('partna.social_platforms', []);

        if (! isset($registry[$platformKey])) {
            throw new InvalidArgumentException("Unknown social platform: {$platformKey}.");
        }

        return $registry[$platformKey];
    }
}
