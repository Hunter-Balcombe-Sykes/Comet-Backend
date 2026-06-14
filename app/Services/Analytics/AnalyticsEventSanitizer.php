<?php

namespace App\Services\Analytics;

use Illuminate\Support\Str;

/**
 * Centralises PII minimisation for analytics writes (PRIV-5/6). Marketing-tool
 * query strings routinely embed subscriber emails (e.g. ?utm_content=a%40b.com),
 * and a full User-Agent is a strong cross-site fingerprint — neither adds
 * dashboard value beyond origin+path and the already-derived device_type.
 *
 * Shared by PostgresEventWriter (the main pipeline) and LogLeadRateLimits so the
 * same rule applies everywhere a visitor referrer / UA is persisted.
 */
class AnalyticsEventSanitizer
{
    public const REFERRER_MAX_LENGTH = 512;

    public const USER_AGENT_MAX_LENGTH = 256;

    /**
     * Strip the query string + fragment from a referrer URL and cap its length.
     * Keeps origin + path (forensic value) without the GDPR retention burden of
     * UTM-embedded PII. Returns null for a missing/unparseable referrer.
     */
    public static function referrer(?string $referrer): ?string
    {
        if ($referrer === null || $referrer === '') {
            return null;
        }

        $parts = parse_url($referrer);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        $path = $parts['path'] ?? '';

        return Str::limit($scheme.'://'.$host.$path, self::REFERRER_MAX_LENGTH, '');
    }

    /**
     * Cap the User-Agent at 256 chars — long enough for every legitimate browser
     * family + version, short enough to drop the appended comment blocks that
     * inflate the fingerprint. device_type is derived separately, so the raw UA
     * adds no dashboard value beyond this. Returns null for an empty UA.
     */
    public static function userAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return Str::limit($userAgent, self::USER_AGENT_MAX_LENGTH, '');
    }
}
