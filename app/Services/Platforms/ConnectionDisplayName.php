<?php

namespace App\Services\Platforms;

use App\Catalog\CompiledCatalog;

/**
 * THE one place a connection's human name comes from (owner ruling R9,
 * overnight 2026-08-18): the Platforms table's first column showed the
 * platform label or a raw URL for most link-only rows because identity was
 * scattered across payload keys and 11 brand surfaces had no handle grammar.
 *
 * Precedence, best first:
 *   1. payload.display_name — an explicit, fetched or owner-set name.
 *   2. payload.name when it is the connected THING, not the brand label
 *      (Google place name, YouTube channel, Instagram fullName, a store, an
 *      og:title from EnrichLinkCardJob) — cleaned of "| Brand" tails.
 *   3. a native identity key — username / handle / artist / login / slug /
 *      organiser — with the platform-native prefix (@ for the @-platforms).
 *   4. a handle captured from payload.url by the surface's own catalog
 *      detector (github.com/{handle}, {handle}.substack.com, kick.com/{h}…).
 *   5. null — the caller falls back to a URL label.
 */
final class ConnectionDisplayName
{
    /** Surfaces whose handle reads with an @ everywhere users see it. */
    private const AT_PREFIX = ['x.profile', 'threads.profile', 'tiktok.profile', 'medium.profile', 'instagram.profile', 'youtube.channel', 'telegram.channel', 'snapchat.profile'];

    /** @param array<string, mixed> $payload */
    public static function for(string $surfaceKey, array $payload): ?string
    {
        $displayName = self::text($payload['display_name'] ?? null);
        if ($displayName !== null) {
            return $displayName;
        }

        $brand = self::brandLabel($surfaceKey);
        // Feed platforms store the LATEST ITEM's title under payload.name
        // (legacy shape) — a channel is its handle, not its newest video.
        $nameIsContentTitle = in_array($surfaceKey, ['youtube.channel', 'vimeo.account', 'bandcamp.artist', 'youtube_music.channel', 'apple_music.artist', 'apple_podcasts.show', 'soundcloud.player'], true);
        $name = self::text($payload['name'] ?? null);
        if ($name !== null && ! $nameIsContentTitle && ! self::isBrandLabel($name, $brand, $surfaceKey)) {
            return self::cleanTitle($name, $brand);
        }

        foreach (['username', 'handle', 'artist', 'artistName', 'artist_name', 'login', 'organiser', 'slug', 'store'] as $key) {
            $value = self::text($payload[$key] ?? null);
            if ($value !== null && ! str_contains($value, '://')) {
                return self::prefixed($surfaceKey, $value);
            }
        }

        // Apple's connect stores the artist page under `input`; a slug in that
        // path ("/artist/tame-impala/290242959") is the artist's own name.
        $input = self::text($payload['input'] ?? null);
        if ($input !== null && preg_match('~/artist/([a-z0-9-]+)/\d+~i', $input, $m)) {
            return mb_convert_case(str_replace('-', ' ', $m[1]), MB_CASE_TITLE, 'UTF-8');
        }

        $url = self::text($payload['url'] ?? null);
        if ($url !== null) {
            $handle = self::handleFromUrl($surfaceKey, $url);
            if ($handle !== null && ! self::looksOpaque($handle)) {
                return self::prefixed($surfaceKey, $handle);
            }
            $store = self::storeNameFromUrl($surfaceKey, $url);
            if ($store !== null) {
                return $store;
            }
        }
        if ($name !== null && $nameIsContentTitle) {
            return self::cleanTitle($name, $brand);
        }

        return null;
    }

    /** A captured "handle" that is really an opaque id (an Uber Eats store id, a hash). */
    private static function looksOpaque(string $value): bool
    {
        // Mixed case + digits at ≥16 chars is an id (Uber Eats "rebBnbA2UJmwycojX-Gb8w");
        // a human slug is lower-case words joined by dashes.
        return strlen($value) >= 16 && preg_match('/\d/', $value) === 1 && preg_match('/[A-Z]/', $value) === 1 && preg_match('/[a-z]/', $value) === 1;
    }

    /**
     * Ordering / booking stores: the human slug in the path, humanised.
     *   ubereats.com/au/store/{slug}/{id}         → Slug
     *   doordash.com/store/{slug}-{id}            → Slug
     *   menulog.com.au/restaurants-{slug}-{pc}/…  → Slug
     *   bopple.app/{slug}, mryum.com/{slug}       → Slug
     */
    private static function storeNameFromUrl(string $surfaceKey, string $url): ?string
    {
        if (! str_ends_with($surfaceKey, '.order') && ! str_ends_with($surfaceKey, '.reserve') && ! str_ends_with($surfaceKey, '.book')) {
            return null;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $slug = null;
        if (preg_match('~/store/([a-z0-9]+(?:-[a-z0-9]+)*)~i', $path, $m)) {
            $slug = $m[1];
        } elseif (preg_match('~/restaurants-([a-z0-9-]+?)-\d{3,5}(?:/|$)~i', $path, $m)) {
            $slug = $m[1];
        } elseif (preg_match('~^/([a-z0-9]+(?:-[a-z0-9]+)+)/?$~i', $path, $m)) {
            $slug = $m[1];
        }
        if ($slug === null) {
            return null;
        }
        $slug = preg_replace('/-\d+$/', '', $slug) ?? $slug;
        $words = array_filter(explode('-', $slug), fn ($w) => $w !== '' && ! preg_match('/^\d+$/', $w));

        return $words === [] ? null : mb_convert_case(implode(' ', $words), MB_CASE_TITLE, 'UTF-8');
    }

    /** The handle a surface's catalog detector captures from a URL, if any. */
    public static function handleFromUrl(string $surfaceKey, string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '/');
        if ($host === '') {
            return null;
        }
        foreach (CompiledCatalog::detectors() as $detector) {
            if (($detector['surface_key'] ?? null) !== $surfaceKey || empty($detector['identifier_capture'])) {
                continue;
            }
            $capture = (string) $detector['identifier_capture'];
            $registrable = (string) ($detector['registrable_key'] ?? '');
            if ($registrable !== '' && $host !== $registrable && ! str_ends_with($host, '.'.$registrable)) {
                continue;
            }
            $sub = $detector['subdomain_pattern'] ?? null;
            if (is_string($sub) && $sub !== '') {
                $label = $registrable !== '' && str_ends_with($host, '.'.$registrable) ? substr($host, 0, -strlen('.'.$registrable)) : $host;
                if (@preg_match($sub, $label, $m) === 1 && isset($m[$capture]) && $m[$capture] !== '') {
                    return $m[$capture];
                }
            }
            $pathPattern = $detector['path_pattern'] ?? null;
            if (is_string($pathPattern) && $pathPattern !== '' && @preg_match($pathPattern, $path, $m) === 1 && isset($m[$capture]) && $m[$capture] !== '') {
                return $m[$capture];
            }
        }

        return null;
    }

    private static function prefixed(string $surfaceKey, string $value): string
    {
        $value = ltrim($value, '@');
        if (in_array($surfaceKey, self::AT_PREFIX, true)) {
            return '@'.$value;
        }
        if ($surfaceKey === 'reddit.profile') {
            return str_starts_with($value, 'u/') || str_starts_with($value, 'r/') ? $value : 'u/'.$value;
        }
        if ($surfaceKey === 'discord.server') {
            return 'discord.gg/'.$value;
        }

        return $value;
    }

    private static function brandLabel(string $surfaceKey): ?string
    {
        $surface = CompiledCatalog::surface($surfaceKey);
        $label = $surface['display_name'] ?? null;
        if (! is_string($label) || $label === '') {
            $brands = CompiledCatalog::brands();
            $label = $brands[$surface['brand_key'] ?? '']['label'] ?? null;
        }

        return is_string($label) && $label !== '' ? $label : null;
    }

    private static function isBrandLabel(string $name, ?string $brand, string $surfaceKey): bool
    {
        $n = mb_strtolower(trim($name));
        if ($brand !== null && $n === mb_strtolower($brand)) {
            return true;
        }
        $brandKey = explode('.', $surfaceKey)[0];

        return $n === str_replace(['_', '-'], ' ', $brandKey) || $n === $brandKey;
    }

    /** "torvalds - Overview" → "torvalds"; "Acme | Patreon" → "Acme"; "Acme (@acme) • Instagram" → "Acme". */
    private static function cleanTitle(string $title, ?string $brand): string
    {
        $t = trim($title);
        $t = preg_replace('/\s*[|•·–—-]\s*(overview|home|official site|official website)\s*$/iu', '', $t) ?? $t;
        if ($brand !== null) {
            $t = preg_replace('/\s*[|•·–—-]\s*'.preg_quote($brand, '/').'\s*$/iu', '', $t) ?? $t;
            $t = preg_replace('/\s+on\s+'.preg_quote($brand, '/').'\s*$/iu', '', $t) ?? $t;
        }
        $t = preg_replace('/\s*\(@[A-Za-z0-9._-]+\)\s*$/', '', $t) ?? $t;
        // "Atolea Jewelry | Waterproof Jewelry With …" → the site's own name.
        if (str_contains($t, ' | ')) {
            $t = trim(explode(' | ', $t, 2)[0]);
        }

        return trim($t) !== '' ? trim($t) : trim($title);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
