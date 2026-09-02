<?php

namespace App\Services\Platforms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A `*.square.site` root is a Square Online WEBSITE, not a booking page: it
 * carries no merchant id, so nothing can be read from it. Most of them do
 * link out to the owner's Square Appointments page ("Book now"), and that
 * link is what we want as the Square connection (owner, 2026-09-02: the
 * golden path is the deep link, however it was pasted or harvested). One
 * GET of the site's HTML, the first appointments URL in it wins; anything
 * else (unreachable, no link) leaves the caller with the URL it had.
 */
final class SquareSiteBookingResolver
{
    private const PATTERN = '~https?://(?:[a-z0-9-]+\.)*squareup\.com/appointments/(?:book/)?[a-z0-9]{8,32}[^"\'\s<>]*~i';

    public static function isSiteRoot(string $url): bool
    {
        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));

        return preg_match('~(^|\.)square\.site$~', $host) === 1
            && SquareBookingPage::parseUrl($url)['merchant'] === null;
    }

    public function resolve(string $url): ?string
    {
        if (! self::isSiteRoot($url)) {
            return null;
        }
        try {
            $response = Http::timeout(10)->withHeaders(['Accept' => 'text/html'])->get($url);
            if (! $response->ok()) {
                return null;
            }
            $html = html_entity_decode($response->body(), ENT_QUOTES | ENT_HTML5);
        } catch (Throwable $e) {
            Log::info('square.site_booking_resolver.unreachable', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
        if (preg_match(self::PATTERN, $html, $m) !== 1) {
            return null;
        }
        $parsed = SquareBookingPage::parseUrl($m[0]);
        if ($parsed['merchant'] === null) {
            return null;
        }

        return SquareBookingPage::bookingUrl($parsed['merchant'], $parsed['unit'], $parsed['teamMember']);
    }
}
