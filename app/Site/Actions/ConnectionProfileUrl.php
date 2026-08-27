<?php

namespace App\Site\Actions;

use App\Models\Core\Site\IntegrationConnection;
use App\Support\UrlSafety;

/**
 * A connection's public destination URL — the profile / channel / booking
 * page a visitor is sent to. Lifted from the retired SiteActionsService::platformConnectionUrl
 * (2026-08-23): Instagram and YouTube rebuild from the stored handle, every
 * other platform carries `url` (or legacy `link`) in its payload. Always
 * through UrlSafety so a stored javascript:/data: url can never reach the wire.
 */
final class ConnectionProfileUrl
{
    public static function for(IntegrationConnection $conn): ?string
    {
        $payload = $conn->payload;

        return match (strtolower((string) $conn->platform)) {
            'instagram' => isset($payload['username']) && trim((string) $payload['username']) !== ''
                ? UrlSafety::safeHref('https://www.instagram.com/'.trim((string) $payload['username']))
                : UrlSafety::safeHref($payload['url'] ?? null),
            'youtube' => isset($payload['handle']) && trim((string) $payload['handle']) !== ''
                ? UrlSafety::safeHref('https://www.youtube.com/@'.trim((string) $payload['handle']))
                : UrlSafety::safeHref($payload['url'] ?? null),
            // T25 (owner, 2026-08-28): an EMPLOYEE-mode Fresha connection's
            // destination is the booking flow with that staff member
            // preselected — not the venue root, which made the fallback Book
            // action (Services page absent) dump visitors on the venue's
            // whole staff list. Storewide/unselected keep the canonical root.
            'fresha' => UrlSafety::safeHref(self::freshaBookingUrl($payload) ?? $payload['url'] ?? null),
            default => UrlSafety::safeHref($payload['url'] ?? $payload['link'] ?? null),
        };
    }

    /**
     * The employee-preselected booking URL, or null when the selection is
     * not employee-mode / the payload lacks the canonical venue url. Mirrors
     * FreshaConnector::bookingDeepLink's shape minus the per-service
     * offerItemId (this is the venue-level CTA).
     *
     * @param  array<string, mixed>  $payload
     */
    private static function freshaBookingUrl(array $payload): ?string
    {
        $selection = $payload['selection'] ?? null;
        if (! is_array($selection) || ($selection['mode'] ?? null) !== 'employee') {
            return null;
        }
        $employeeId = trim((string) data_get($selection, 'employee.employeeId', ''));
        $base = trim((string) ($payload['url'] ?? ''));
        if ($employeeId === '' || $base === '') {
            return null;
        }

        return rtrim($base, '/').'/booking?employeeId='.rawurlencode($employeeId);
    }
}
