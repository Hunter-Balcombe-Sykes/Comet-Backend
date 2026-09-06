<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;
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
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

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
            // A user-supplied host (*.square.site, regex-checked above), so the
            // SSRF-safe fetcher with its size cap, never a bare Http call.
            $response = $this->fetcher->fetch($url, ['Accept' => 'text/html']);
            if ($response['status'] !== 200) {
                return null;
            }
            $html = html_entity_decode($response['body'], ENT_QUOTES | ENT_HTML5);
        } catch (Throwable $e) {
            Log::info('square.site_booking_resolver.unreachable', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
        if (preg_match(self::PATTERN, $html, $m) !== 1) {
            // A Square Online site can EMBED Appointments (homepage type
            // "appointments") instead of linking out — the config JSON then
            // carries the merchant token but no appointments URL anywhere in
            // the HTML (Akro Studio, A.12 proof, 2026-09-03). Only trust the
            // token when the site declares the appointments feature, so a
            // plain storefront with a stray merchant_id stays a website.
            $embedsAppointments = preg_match('~"featuresets"\s*:\s*\[[^\]]*"appointments"~', $html) === 1
                || preg_match('~"(?:homePage|typeID)"\s*:\s*"appointments"~', $html) === 1;
            if (! $embedsAppointments || preg_match('~"merchant_id"\s*:\s*"(ML[A-Z0-9]{8,24})"~', $html, $t) !== 1) {
                return null;
            }

            // A bare merchant_id is NOT a resolvable booking page on its own —
            // Square's own path validator reports it "invalid" even for a
            // real, single-location merchant (Akro Studio proof, 2026-09-06:
            // book.squareup.com/appointments/{merchant_id} served a client-
            // rendered "invalid" shell with no /location/ suffix, while
            // pairing the SAME merchant_id with this site's own published
            // location id served "valid"). These single-purpose "homepage IS
            // the appointments widget" sites publish that location under
            // whichever ecommerce feature's *_location_ids array happens to
            // be on (shipping/pickup/store, all the same physical place for
            // the one-location business this template shape implies — a
            // genuinely multi-location business doesn't render as a single
            // template-typed homepage) — reusing it here, not because it's
            // conceptually the right field, but because it's the only place
            // this site shape publishes the token at all. No such token
            // published → stay a website rather than link to a page already
            // proven to read as broken.
            if (preg_match('~"[a-z_]*location_ids?"\s*:\s*\[\s*"([A-Z0-9]{8,32})"~', $html, $u) !== 1) {
                return null;
            }

            return SquareBookingPage::bookingUrl($t[1], $u[1], null);
        }
        $parsed = SquareBookingPage::parseUrl($m[0]);
        if ($parsed['merchant'] === null) {
            return null;
        }

        return SquareBookingPage::bookingUrl($parsed['merchant'], $parsed['unit'], $parsed['teamMember']);
    }
}
