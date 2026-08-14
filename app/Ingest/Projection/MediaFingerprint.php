<?php

namespace App\Ingest\Projection;

use App\Routing\SecretParams;

/**
 * The ONE definition of an image entry's fingerprint, extracted so two callers
 * cannot drift apart: ProjectionWriter dedupes content.media_assets on it, and
 * IdentityKeyDeriver emits the same string as the ContentDigest identity key.
 * A second implementation would let an asset and its own digest disagree.
 */
final class MediaFingerprint
{
    /**
     * #PRIV-5: minimise BEFORE the fingerprint is computed, so the fingerprint
     * and the stored source_url derive from the same string — the fingerprint
     * is the UNIQUE (user_id, fingerprint) dedupe key, and a fingerprint
     * computed from the raw URL while a minimised URL is stored would let a
     * re-run mint a second row for the same image. The vendor's stable ref is
     * PREFERRED over the URL (slice 1a §3.1): Instagram URLs re-sign on every
     * sync (`oh`/`oe` params survive minimisation), so a URL-keyed asset
     * re-mints per sync the moment a projector emits url beside ref. The URL
     * is the fallback for entries with no ref.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: ?string, 1: ?string} [fingerprint, minimised source url]
     */
    public static function for(array $entry): array
    {
        // Upload shape (slice 1a §3.4): the stable ref IS the site_media id.
        // Inside the url- namespace by construction — only this method mints
        // 'upload:' fingerprints, so no existing row can collide.
        $siteMediaId = self::uploadSiteMediaId($entry);
        if ($siteMediaId !== null) {
            return ['url-'.sha1('upload:'.$siteMediaId), null];
        }

        $url = isset($entry['url']) && is_string($entry['url']) && $entry['url'] !== ''
            ? SecretParams::minimiseUrl($entry['url'])
            : null;
        $url = ($url === '' ? null : $url); // minimiseUrl fails closed to ''
        $ref = isset($entry['ref']) && is_string($entry['ref']) && $entry['ref'] !== '' ? $entry['ref'] : null;

        $fingerprint = $ref ?? $url;

        return [$fingerprint === null ? null : 'url-'.sha1($fingerprint), $url];
    }

    /**
     * The upload shape: a non-empty site_media_id IS the discriminator (slice 1a §3.4).
     * Public because the asset row build needs the same discriminator the
     * fingerprint used, and two readings of "is this an upload" would drift.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function uploadSiteMediaId(array $entry): ?string
    {
        $id = $entry['site_media_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
