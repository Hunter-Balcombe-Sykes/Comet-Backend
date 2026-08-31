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

    public const UTM_MAX_LENGTH = 128;

    // PGR-18: marketing tools routinely embed a subscriber email in a UTM query
    // param (e.g. ?utm_content=a%40b.com) — same PII shape referrer() already
    // strips via the query string, but UTM values arrive as bare strings, not URLs.
    private const EMAIL_LIKE_PATTERN = '/[^\s@]+@[^\s@]+\.[^\s@]+/';

    /**
     * PRIV-2: family => detection pattern, checked IN ORDER — capture group 1 is the
     * major version. Edge and Opera UAs also embed "Chrome/...", and every WebKit UA
     * (including Chrome's) embeds "Safari/...", so the more specific tokens must be
     * matched first or they'd be misclassified as Chrome/Safari. Safari's true version
     * is the "Version/" token, not the "Safari/" WebKit-build number.
     *
     * @var array<string, string>
     */
    private const UA_FAMILY_PATTERNS = [
        'Edge' => '/\bEdg(?:e|A|iOS)?\/(\d+)/',
        'Opera' => '/\b(?:OPR|Opera)\/(\d+)/',
        'Chrome' => '/\b(?:Chrome|CriOS)\/(\d+)/',
        'Firefox' => '/\b(?:Firefox|FxiOS)\/(\d+)/',
        'Safari' => '/\bVersion\/(\d+)(?:\.\d+)*.*\bSafari\//',
    ];

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
     * PRIV-2: reduce the User-Agent to "<Family>/<major version>" (e.g. "Chrome/141"),
     * discarding the OS/device/engine-build tail that makes a full UA string a strong
     * cross-site fingerprint. device_type is derived separately from the RAW UA at
     * ingest — DetectsClientInfo::detectDeviceType() runs on $request->userAgent()
     * BEFORE this sanitiser is applied, and nothing downstream re-parses the STORED
     * string — so reducing what's persisted here doesn't touch device/OS detection.
     * Returns null for a missing/empty UA, 'Other' for a UA that matches no known family.
     */
    public static function userAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        foreach (self::UA_FAMILY_PATTERNS as $family => $pattern) {
            if (preg_match($pattern, $userAgent, $m) === 1) {
                return $family.'/'.$m[1];
            }
        }

        return 'Other';
    }

    /**
     * Normalise an outbound click destination, or null when the value is not a
     * destination a visitor could have navigated to.
     *
     * Accepts http, https, mailto and tel. mailto:/tel: are here because the
     * tracker has always fired on them — it fires on any anchor whose origin
     * differs from the page's, and both schemes parse to the opaque origin
     * "null" — while the request validated `url:http,https`, which rejects
     * both. Every contact link-out 422'd, so the highest-intent signal a
     * professional page emits recorded zero rows from launch to 2026-09-01.
     *
     * Accepting them means normalising them, because neither is a stable
     * string as typed:
     *   - tel: one number has as many spellings as the owner's keyboard
     *     allowed (+61 400 000 000 / +61 (400) 000-000 / 0400.000.000). Left
     *     raw, each spelling is its own url value AND its own dedup key, so a
     *     double-tap counts twice and the dashboard shows one contact point as
     *     three. Visual separators go; the RFC 3966 ;params tail stays.
     *   - mailto: the ?subject=/?body= tail is template copy the owner wrote,
     *     not part of the destination, and body text is a free-form PII
     *     surface this table has no business holding (same reasoning as
     *     referrer()'s query-string strip). It goes; the address is lowercased.
     *
     * Length is deliberately NOT capped here — ClickRequest's `max:2048` owns
     * that so an over-long URL reports as over-long rather than as a bad scheme.
     */
    public static function clickUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);
        $scheme = strstr($url, ':', true);
        if ($scheme === false) {
            return null;
        }

        $scheme = strtolower($scheme);
        $rest = substr($url, strlen($scheme) + 1);

        return match ($scheme) {
            'http', 'https' => filter_var($scheme.':'.$rest, FILTER_VALIDATE_URL) !== false
                && parse_url($scheme.':'.$rest, PHP_URL_HOST) !== null
                    ? $scheme.':'.$rest
                    : null,
            'tel' => self::telDestination($rest),
            'mailto' => self::mailtoDestination($rest),
            default => null,
        };
    }

    /** tel: subscriber number stripped of visual separators; ;params preserved. */
    private static function telDestination(string $rest): ?string
    {
        [$number, $params] = array_pad(explode(';', strtolower($rest), 2), 2, null);

        $number = (string) preg_replace('/[\s().\-]/', '', (string) $number);
        // 3 digits is the shortest dialable thing (000, 911); 20 is E.164's ceiling.
        if (preg_match('/^\+?[0-9]{3,20}$/', $number) !== 1) {
            return null;
        }

        if ($params === null || $params === '') {
            return 'tel:'.$number;
        }

        // ;ext=12, ;phone-context=+61 — an allowlist, not a passthrough, so the
        // tail can't smuggle arbitrary text into the url column.
        return preg_match('/^[a-z0-9=;+.\-]{1,64}$/', $params) === 1
            ? 'tel:'.$number.';'.$params
            : null;
    }

    /** mailto: address only — lowercased, ?subject=/?body= discarded. */
    private static function mailtoDestination(string $rest): ?string
    {
        $address = strstr($rest, '?', true);
        if ($address === false) {
            $address = $rest;
        }

        $address = strtolower(trim($address));

        // Deliberately looser than a full RFC 5322 check and stricter than
        // EMAIL_LIKE_PATTERN: one recipient, one @, one dot in the domain.
        return preg_match('/^[^\s@,]+@[^\s@,]+\.[^\s@,]+$/', $address) === 1
            ? 'mailto:'.$address
            : null;
    }

    /**
     * PGR-18: cap a UTM query param and drop it entirely if it carries an
     * email-like substring — mirrors referrer()'s drop-on-suspicion behaviour
     * rather than trying to excise just the matched substring. Returns null for
     * a missing/empty value or one that looks like it embeds an email.
     */
    public static function utmParam(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match(self::EMAIL_LIKE_PATTERN, $value) === 1) {
            return null;
        }

        return Str::limit($value, self::UTM_MAX_LENGTH, '');
    }
}
