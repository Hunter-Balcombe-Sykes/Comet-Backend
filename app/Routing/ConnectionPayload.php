<?php

namespace App\Routing;

/**
 * The write-time payload for a connection the router creates.
 *
 * This is deliberately thin. A connection's payload has two authors: the link
 * write (identity — where the thing is) and the refresh job (content — its
 * name, thumbnail, latest items). Only the first is known here, so only the
 * first is written; `last_refresh_status = 'pending'` is what says the rest is
 * still owed.
 *
 * The keys are not arbitrary. `PublicIntegrationConnectionResource::ALLOWLIST`
 * filters the payload per platform before it reaches the public wire, and the
 * sitepage renderer reads exactly `url` — plus `username` for Instagram
 * (apps/pages src/content/platforms/social-links.ts:115). A payload missing
 * them survives every backend test and then renders an empty page, which is
 * precisely how the showcase accounts came to serve `"payload":[]` for all 20
 * of their connections. Both writers now go through here so the two cannot
 * drift again.
 */
final class ConnectionPayload
{
    /**
     * @param  string  $canonicalUrl  the canonicalised URL of the resource
     * @param  string  $identifier  the resolved identity (handle, id, composite)
     * @param  string  $identifierKind  the surface's `identifier_kind`
     * @param  string  $origin  provenance — which surface asked for this write
     * @return array<string, string>
     */
    public static function forWrite(
        string $canonicalUrl,
        string $identifier,
        string $identifierKind,
        string $origin,
    ): array {
        $payload = ['url' => $canonicalUrl, 'source' => $origin];

        // A handle IS the display identity, and several platforms' public
        // contracts expose it rather than the URL. Composite/opaque ids are not
        // handles and must not be presented as one.
        if ($identifierKind === 'handle' && $identifier !== '' && ! str_contains($identifier, '/')) {
            $payload['username'] = $identifier;
        }

        return $payload;
    }
}
