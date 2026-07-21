<?php

namespace App\Support\Concerns;

// B7/PRIV-1: shared client-metadata minimisation for audit/compliance log rows.
// hashClientIp() mirrors HashesClientData's HMAC-SHA256 scheme (same key, same
// algorithm) so an IP hash is comparable across call sites. summariseUserAgent()
// ports the browser-family/platform parsing TokenRevocationService uses for its
// Redis session metadata (SEC-3) — collapsing a raw UA string to e.g.
// "Chrome / macOS" keeps enough signal to eyeball a login without retaining the
// full fingerprintable string. TokenRevocationService itself is NOT migrated onto
// this trait (out of scope for B7) — it could adopt it later to de-duplicate the
// parsing logic.
trait MinimisesClientData
{
    protected function hashClientIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, config('app.key'));
    }

    protected function summariseUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return $this->parseUaBrowserFamily($userAgent).' / '.$this->parseUaPlatform($userAgent);
    }

    /** Order matters — Edge/Opera/Samsung must be checked before Chrome/Safari. */
    private function parseUaBrowserFamily(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/Edg(?:e|\/)/i', $ua) => 'Edge',
            (bool) preg_match('/OPR\//i', $ua) => 'Opera',
            (bool) preg_match('/SamsungBrowser/i', $ua) => 'Samsung Browser',
            (bool) preg_match('/(?:Chrome|CriOS)\/[\d.]+/i', $ua) => 'Chrome',
            (bool) preg_match('/FxiOS\//i', $ua) => 'Firefox',
            (bool) preg_match('/Firefox\/[\d.]+/i', $ua) => 'Firefox',
            // Mobile Safari (iOS WebKit) before generic Safari.
            (bool) preg_match('/Version\/[\d.]+ Mobile.*Safari/i', $ua) => 'iOS Safari',
            (bool) preg_match('/Safari\/[\d.]+/i', $ua) => 'Safari',
            default => 'Other',
        };
    }

    private function parseUaPlatform(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/iPhone|iPad|iOS/i', $ua) => 'iOS',
            (bool) preg_match('/Android/i', $ua) => 'Android',
            (bool) preg_match('/Windows/i', $ua) => 'Windows',
            (bool) preg_match('/Macintosh|Mac OS X/i', $ua) => 'macOS',
            (bool) preg_match('/Linux/i', $ua) => 'Linux',
            default => 'Other',
        };
    }
}
