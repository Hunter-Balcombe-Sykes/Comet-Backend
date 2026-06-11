<?php

namespace App\Services\Platforms;

// Tolerant pre-parsing for user-pasted platform inputs. Users paste whatever
// their share sheet / address bar gives them: scheme-less domains
// ("fearnoevil.com.au"), mobile hosts, tracking query strings, trailing junk,
// or just a bare @handle. Every platform parser calls urlish() first so its
// `^https?://` anchors keep working on scheme-less input, then applies its own
// platform-specific extraction.
class PlatformInput
{
    /**
     * Normalise a pasted string toward a fetchable URL without mangling bare
     * handles:
     *   - trims whitespace + wrapping quotes/angle brackets (mail clients add
     *     them around pasted links)
     *   - "http://…" / "HTTPS://…" → lowercased scheme, https preferred for
     *     scheme-less input
     *   - "www.x.com/y", "x.com.au/y" (host-looking, no scheme) → "https://" prefix
     *   - "@name" / "name" (no dot before any slash) → returned untouched
     */
    public static function urlish(string $input): string
    {
        $s = trim($input);
        $s = trim($s, "\"'<> \t\n\r");

        if ($s === '') {
            return $s;
        }

        // Already has a scheme — normalise its case so later regex anchors on
        // `https?://` match pastes like "HTTPS://Site.com".
        if (preg_match('~^(https?)://(.*)$~is', $s, $m)) {
            return strtolower($m[1]).'://'.$m[2];
        }

        // Other schemes (ftp:, mailto:, javascript:) are never platform links —
        // return as-is and let the platform parser reject them.
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $s)) {
            return $s;
        }

        // Scheme-less host: the first path-less segment looks like host.tld
        // ("site.com", "www.site.com.au/shop?x=1"). Bare handles ("name",
        // "@name") contain no dot before a slash and stay untouched.
        $firstSegment = explode('/', $s, 2)[0];
        if (preg_match('~^([a-z0-9-]+\.)+[a-z]{2,}(:\d+)?$~i', $firstSegment)) {
            return 'https://'.$s;
        }

        return $s;
    }

    /** Bare-handle reduction: trim + drop a single leading "@". */
    public static function token(string $input): string
    {
        return ltrim(trim($input), '@');
    }

    /**
     * True when the input reads as a bare handle — no scheme, no dots, no
     * slashes — so platforms with username-addressed profiles can map it
     * straight onto their canonical URL.
     */
    public static function isBareToken(string $input, string $pattern = '~^[A-Za-z0-9._-]{2,60}$~'): bool
    {
        return (bool) preg_match($pattern, self::token($input));
    }
}
