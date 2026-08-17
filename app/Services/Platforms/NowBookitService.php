<?php

namespace App\Services\Platforms;

// NowBookit reservations — connect by booking link, render the keyless NowBookit
// booking widget in an iframe. The widget needs the venue's accountid + venueid
// (both public widget params, read straight from the booking URL's query
// string), so there's no server-side scrape. Mirrors the OpenTable contract: a
// URL in, an embedUrl out.
class NowBookitService
{
    /** True for any nowbookit.com host. */
    public function isNowBookitUrl(string $url): bool
    {
        $host = strtolower((string) parse_url(PlatformInput::urlish($url), PHP_URL_HOST));

        return (bool) preg_match('~(^|\.)nowbookit\.com$~', $host);
    }

    /**
     * The accountid + venueid from a NowBookit booking URL's query string. Both
     * are required for the embed; null when either is missing (e.g. a bare
     * nowbookit.com link without the venue params).
     *
     * @return array{accountId:string, venueId:string}|null
     */
    public function parseIds(string $url): ?array
    {
        $query = (string) parse_url(PlatformInput::urlish($url), PHP_URL_QUERY);
        parse_str($query, $params);

        // accountid is a UUID on every current NowBookit link (both real
        // fixtures in the 2026-08-18 sweep: bookings.nowbookit.com/?accountid=
        // cd39dab2-…&venueid=14544); legacy links carry digits. Both are ids
        // we pass straight back into the widget URL, so accept either shape.
        // venueid stays numeric.
        $accountId = $this->accountId($params['accountid'] ?? $params['accountId'] ?? null);
        $venueId = $this->digits($params['venueid'] ?? $params['venueId'] ?? null);

        return ($accountId !== null && $venueId !== null)
            ? ['accountId' => $accountId, 'venueId' => $venueId]
            : null;
    }

    /** Optional venue name from a venuename/venue query param; null when absent. */
    public function nameFromUrl(string $url): ?string
    {
        $query = (string) parse_url(PlatformInput::urlish($url), PHP_URL_QUERY);
        parse_str($query, $params);
        $name = $params['venuename'] ?? $params['venueName'] ?? $params['venue'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /** Keyless NowBookit booking-widget URL for an iframe. */
    public function embedUrl(string $accountId, string $venueId): string
    {
        $params = http_build_query([
            'accountid' => $accountId,
            'venueid' => $venueId,
            'theme' => 'light',
        ]);

        return "https://booking.nowbookit.com/steps/sitting-details?{$params}";
    }

    private function accountId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = trim($value);

        return preg_match('~^(\d+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$~i', $v) === 1 ? $v : null;
    }

    private function digits(mixed $value): ?string
    {
        return is_string($value) && preg_match('~^\d+$~', trim($value)) === 1 ? trim($value) : null;
    }
}
