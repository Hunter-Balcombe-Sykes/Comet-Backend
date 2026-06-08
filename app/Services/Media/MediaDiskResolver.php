<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;

/**
 * Canonical disk-name resolution for all media operations (images + videos).
 *
 * Both ImageVariantService and VideoVariantService delegate to this class so
 * that storage-routing logic is maintained in exactly one place. A divergent
 * update to only one service would otherwise silently deposit images and
 * videos in different buckets, with no immediate error.
 *
 * Resolution order:
 *  1. If any PARTNA_MEDIA_DISK / SIDEST_MEDIA_DISK superglobal is present,
 *     return that probed value directly — the cached config may predate the
 *     runtime-injected env var and must not be trusted over the live probe.
 *  2. If config is still the sentinel value 'media' and filesystems.default
 *     is an S3-backed disk, fall back to that disk and log a warning.
 *  3. Otherwise return the configured value as-is.
 *
 * The direct $_ENV/$_SERVER probes are intentional: Laravel Cloud caches
 * config at deploy time but injects platform env vars into the process
 * environment at runtime, so env()/config() won't reflect them.
 */
final class MediaDiskResolver
{
    public static function resolve(): string
    {
        $configured = (string) config('partna.media_disk', 'media');

        $explicit = $_ENV['PARTNA_MEDIA_DISK'] ?? $_SERVER['PARTNA_MEDIA_DISK']
            ?? $_ENV['SIDEST_MEDIA_DISK'] ?? $_SERVER['SIDEST_MEDIA_DISK'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        if ($configured === 'media') {
            $default = (string) config('filesystems.default', 'local');
            $defaultConfig = config("filesystems.disks.{$default}");

            if (
                $default !== '' &&
                $default !== 'local' &&
                $default !== 'media' &&
                is_array($defaultConfig) &&
                (($defaultConfig['driver'] ?? null) === 's3')
            ) {
                Log::warning('PARTNA_MEDIA_DISK not set (legacy fallback: SIDEST_MEDIA_DISK); using filesystems.default disk for media operations.', [
                    'configured_media_disk' => $configured,
                    'fallback_disk' => $default,
                ]);

                return $default;
            }
        }

        return $configured;
    }
}
