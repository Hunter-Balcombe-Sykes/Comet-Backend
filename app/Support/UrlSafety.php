<?php

namespace App\Support;

/**
 * Shared http/https scheme gate for URLs rendered as anchor hrefs or
 * validated on write (#API-1). One implementation for both surfaces so the
 * write-path allowlist (LinkBlockRequestHelpers) and the emit-path gate
 * (SiteActionsService, SitepageDataResolverService) cannot drift apart.
 */
final class UrlSafety
{
    /**
     * Emit-path href gate: return the trimmed URL only when its scheme is
     * http/https, else null. Parses the scheme (fail-closed — a missing/
     * malformed scheme, javascript:, data:, or a relative/scheme-less URL all
     * return null) so no non-navigational URL can land in the payload as a
     * button href, regardless of which writer populated the source.
     */
    public static function safeHref(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return ($scheme === 'http' || $scheme === 'https') ? $url : null;
    }

    /**
     * Write-path boolean form: reject schemes other than http/https for
     * custom links. Blocks javascript:, data:, file:, ftp:, and similar
     * XSS / exfiltration vectors. Delegates to safeHref() so the two entry
     * points can never disagree.
     */
    public static function isAllowedScheme(string $url): bool
    {
        return self::safeHref($url) !== null;
    }
}
