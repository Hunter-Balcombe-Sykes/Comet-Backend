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
     * Returns true if the User-Agent string matches a known bot, headless browser, or scripting tool.
     * Empty/null UAs are treated as bots — no legitimate browser omits the header.
     */
    protected function isBotUserAgent(?string $ua): bool
    {
        if (! $ua || trim($ua) === '') {
            return true;
        }

        $u = strtolower($ua);

        $signals = [
            // Generic bot signals
            'bot', 'spider', 'crawler',
            // SEO / index crawlers
            'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'rogerbot',
            // Social media crawlers
            'facebookexternalhit', 'twitterbot', 'linkedinbot', 'pinterestbot',
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

        foreach ($signals as $signal) {
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
        if (! $ua) {
            return null;
        }

        $u = strtolower($ua);

        // Bots
        if (str_contains($u, 'bot') || str_contains($u, 'spider') || str_contains($u, 'crawler')) {
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
}
