<?php

namespace App\Services\Moderation;

use Illuminate\Support\Facades\Storage;

/**
 * Wrapper around the partna-media-quarantine R2 bucket.
 *
 * Issues signed upload URLs that point at quarantine (not production).
 * Promotes clean media (copy + delete). Deletes quarantine binaries at
 * 90-day preservation expiry.
 */
class R2QuarantineService
{
    public const QUARANTINE_DISK = 'r2_quarantine';
    // 'media' is the S3-compatible production disk (Laravel Cloud Object Storage
    // or any S3/R2 bucket). Configured via AWS_* env vars (Laravel Cloud injects
    // these automatically for its managed storage resource).
    public const PRODUCTION_DISK = 'media';

    /**
     * Generate a pre-signed upload URL for the quarantine bucket.
     *
     * The URL is short-lived (default 10 min) and restricted to the given MIME
     * type. A custom metadata header tags the object as quarantined so any
     * accidental public-bucket mis-config is identifiable at the object level.
     *
     * @return string The pre-signed upload URL.
     */
    public function signedUploadUrl(string $key, string $mime, int $maxSizeBytes, int $expiresIn = 600): string
    {
        return Storage::disk(self::QUARANTINE_DISK)->temporaryUploadUrl(
            $key,
            now()->addSeconds($expiresIn),
            [
                'ContentType' => $mime,
                'Metadata'    => ['partna-quarantine' => '1'],
            ],
        )['url'];
    }

    /**
     * Copy a quarantine object to the production R2 bucket, then delete it
     * from quarantine. Used when a false-positive review clears a media item.
     *
     * @throws \RuntimeException If the quarantine object is not found.
     */
    public function promoteToProduction(string $quarantineKey, string $productionKey): void
    {
        $binary = Storage::disk(self::QUARANTINE_DISK)->get($quarantineKey);

        if ($binary === null) {
            throw new \RuntimeException("Quarantine object not found: {$quarantineKey}");
        }

        Storage::disk(self::PRODUCTION_DISK)->put($productionKey, $binary);
        Storage::disk(self::QUARANTINE_DISK)->delete($quarantineKey);
    }

    /**
     * Hard-delete a quarantine binary (called at preservation_expires_at by
     * the moderation:purge-quarantine-binaries command).
     */
    public function deleteQuarantineBinary(string $quarantineKey): void
    {
        Storage::disk(self::QUARANTINE_DISK)->delete($quarantineKey);
    }

    /** Check whether a quarantine object still exists (e.g. before a NCMEC submission). */
    public function existsInQuarantine(string $key): bool
    {
        return Storage::disk(self::QUARANTINE_DISK)->exists($key);
    }
}
