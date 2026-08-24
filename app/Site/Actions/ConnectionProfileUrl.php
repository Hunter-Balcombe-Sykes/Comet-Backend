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
            default => UrlSafety::safeHref($payload['url'] ?? $payload['link'] ?? null),
        };
    }
}
