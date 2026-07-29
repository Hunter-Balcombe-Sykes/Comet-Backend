<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

// V2: Detects client country code from CDN headers (Cloudflare, CloudFront, Vercel) and device type from user agent strings.
trait DetectsClientInfo
{
    /**
     * Detect country code from CDN headers (Cloudflare, CloudFront, Vercel).
     */
    protected function detectCountryCode(Request $request): ?string
    {
        // X-Visitor-Country first: sitepage beacons arrive via the partna-pages
        // Worker proxy, so CF-IPCountry on THIS request reflects the Worker's
        // egress, not the visitor. The middleware forwards the original
        // request.cf country/region under X-Visitor-* headers.
        $code =
            $request->header('X-Visitor-Country')
            ?? $request->header('CF-IPCountry') // Cloudflare
            ?? $request->header('CloudFront-Viewer-Country') // AWS CloudFront
            ?? $request->header('X-Vercel-IP-Country'); // Vercel

        if (! is_string($code)) {
            return null;
        }

        $code = strtoupper(trim($code));

        if (! preg_match('/^[A-Z]{2}$/', $code)) {
            return null;
        }

        return $code;
    }

    /**
     * #SEM-6: hoisted from a local var so detectDeviceType()'s bot check and any
     * test asserting parity between the two can reference the SAME list — a
     * signal added here can never again silently miss the other consumer.
     */
    private const BOT_UA_SIGNALS = [
        // Generic bot signals
        'bot', 'spider', 'crawler',
        // SEO / index crawlers
        'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'rogerbot',
        // Social media crawlers
        'facebookexternalhit', 'twitterbot', 'linkedinbot',
        // Search engines (explicit in case generic 'bot' substring misses)
        'yandexbot', 'baiduspider', 'slurp',
        // Scripting / CLI tools
        'python-requests', 'python-urllib',
        'curl/', 'wget/',
        'libwww-perl',
        // Headless browsers and test automation
        'headlesschrome', 'phantomjs', 'puppeteer',
        'playwright', 'selenium',
    ];

    /**
     * Returns true if the User-Agent string matches a known bot, headless browser, or scripting tool.
     * Empty/null UAs are treated as bots — no legitimate browser omits the header.
     */
    protected function isBotUserAgent(?string $ua): bool
    {
        if (! $ua || trim($ua) === '') {
            return true;
        }

        $u = strtolower($ua);

        foreach (self::BOT_UA_SIGNALS as $signal) {
            if (str_contains($u, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect devices type from user agent.
     */
    protected function detectDeviceType(?string $ua): ?string
    {
        // Absent UA stays null (unknown), not 'bot' — deliberately diverges from
        // isBotUserAgent()'s deny-by-default (empty UA = bot) so existing rows'
        // device_type shape is preserved for the one caller with no bot filter
        // of its own (AnalyticsController::pageview()).
        if (! $ua) {
            return null;
        }

        $u = strtolower($ua);

        // #SEM-6: delegate to isBotUserAgent() instead of re-testing a 3-substring
        // subset of its 23-signal list. That subset missed facebookexternalhit,
        // slurp, python-requests/urllib, curl/wget, libwww-perl, and headless
        // browsers (HeadlessChrome/PhantomJS/Puppeteer/Playwright/Selenium), all of
        // which reach here via pageview() (the only caller — see class docblock).
        if ($this->isBotUserAgent($ua)) {
            return 'bot';
        }

        // Tablet
        if (str_contains($u, 'ipad') || str_contains($u, 'tablet')) {
            return 'tablet';
        }

        // Mobile
        if (str_contains($u, 'mobi') || str_contains($u, 'iphone') || str_contains($u, 'android')) {
            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * Detect the ISO-3166-2 region suffix (e.g. NSW, VIC for AU) from the
     * partna-pages proxy header or Cloudflare's managed-transform header.
     */
    protected function detectRegionCode(Request $request): ?string
    {
        $code =
            $request->header('X-Visitor-Region')
            ?? $request->header('CF-Region-Code');

        if (! is_string($code)) {
            return null;
        }

        $code = strtoupper(trim($code));

        if (! preg_match('/^[A-Z0-9]{1,3}$/', $code)) {
            return null;
        }

        return $code;
    }

    /**
     * Detect the visitor's city (free text, e.g. "South Melbourne") from the
     * partna-pages proxy header. Best-effort demographics only — the edge doesn't
     * always resolve a city, and the value is NEVER trusted for auth/routing.
     * Sanitised to a safe printable subset with a length cap; any anomaly returns
     * null so a malformed header can never poison ingestion.
     */
    protected function detectCity(Request $request): ?string
    {
        $city = $request->header('X-Visitor-City')
            ?? $request->header('CF-IPCity');

        if (! is_string($city)) {
            return null;
        }

        $city = trim($city);

        // Reject empty / over-long values, then allow only letters (incl. accented),
        // combining marks, digits, spaces, and common place punctuation.
        if ($city === '' || mb_strlen($city) > 120) {
            return null;
        }

        if (! preg_match("/^[\\p{L}\\p{M}0-9 .'\\-]+$/u", $city)) {
            return null;
        }

        return $city;
    }

    /**
     * Visitor latitude from the partna-pages proxy header (request.cf.latitude
     * forwarded as X-Visitor-Lat). Best-effort demographics only, same trust
     * level as detectCity(); anything non-numeric or out of range returns null.
     */
    protected function detectLatitude(Request $request): ?float
    {
        return $this->parseCoordinate($request->header('X-Visitor-Lat'), 90.0);
    }

    /**
     * Visitor longitude from the partna-pages proxy header (request.cf.longitude
     * forwarded as X-Visitor-Lon). Same rules as detectLatitude().
     */
    protected function detectLongitude(Request $request): ?float
    {
        return $this->parseCoordinate($request->header('X-Visitor-Lon'), 180.0);
    }

    private function parseCoordinate(mixed $value, float $bound): ?float
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        $parsed = (float) $value;

        if (! is_finite($parsed) || abs($parsed) > $bound) {
            return null;
        }

        // PRIV-9: round to city-block precision (~11m) at ingest rather than storing
        // raw GPS-grade coordinates. Matches the 4dp rounding AnalyticsQueryService::cities()
        // already applies at read time, so this only closes the raw-precision-at-rest gap.
        return round($parsed, 4);
    }
}
