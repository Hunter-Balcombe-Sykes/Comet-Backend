<?php

namespace App\Services\Media;

use App\Models\Core\Site\SiteMedia;
use App\Services\Moderation\R2QuarantineService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Manages direct-to-cloud upload flows for SiteMedia.
 *
 * Distinct from MediaUploadService (which handles server-proxied uploads).
 * This service issues pre-signed upload URLs so the client uploads directly
 * to R2, bypassing the backend for the binary payload.
 *
 * Routing logic:
 *   - CSAM scanning enabled  → quarantine bucket, processing_state='scanning'
 *   - CSAM scanning disabled → production media bucket, processing_state='pending'
 *
 * The scan-complete job (CsamScanCompleteJob) is responsible for promoting
 * clean media from quarantine to production and advancing the state to 'pending'.
 */
class SiteMediaService
{
    // Default signed URL validity (seconds). 10 minutes is enough for most
    // upload clients; clients retry on expiry rather than holding the URL.
    private const SIGNED_URL_TTL_SECONDS = 600;

    public function __construct(
        private readonly R2QuarantineService $quarantine,
    ) {}

    /**
     * Create a SiteMedia DB row and return a pre-signed upload URL.
     *
     * The client POSTs the binary directly to the returned URL. The backend
     * never receives the raw bytes — only the metadata committed here.
     *
     * @param  string  $siteId  UUID of the owning site.
     * @param  string  $mime    MIME type of the file to be uploaded (e.g. 'image/jpeg').
     * @param  string  $pool    Media pool identifier ('gallery', 'content', etc.).
     * @param  int     $maxSizeBytes  Maximum allowed upload size in bytes (passed to the presigned policy).
     * @return array{url: string, site_media_id: string, bucket: string, key: string}
     */
    public function createSignedUploadUrl(
        string $siteId,
        string $mime,
        string $pool,
        int $maxSizeBytes = 10 * 1024 * 1024, // 10 MiB default
    ): array {
        $csamEnabled = (bool) config('partna.moderation.csam.enabled', false);
        $mediaId = (string) Str::uuid();

        if ($csamEnabled) {
            return $this->createQuarantineUpload($siteId, $mediaId, $mime, $pool, $maxSizeBytes);
        }

        return $this->createProductionUpload($siteId, $mediaId, $mime, $pool, $maxSizeBytes);
    }

    /**
     * Route the upload to the quarantine bucket (CSAM scanning path).
     * The row is created with processing_state='scanning' so no public
     * delivery occurs until the scan job clears it.
     *
     * @return array{url: string, site_media_id: string, bucket: string, key: string}
     */
    private function createQuarantineUpload(
        string $siteId,
        string $mediaId,
        string $mime,
        string $pool,
        int $maxSizeBytes,
    ): array {
        $ext = $this->mimeToExtension($mime);
        // quarantine/ prefix makes it instantly identifiable in the bucket and
        // enforces a separate key-space from production objects.
        $key = "quarantine/{$siteId}/{$mediaId}.{$ext}";
        $bucketName = (string) config('filesystems.disks.r2_quarantine.bucket', 'partna-media-quarantine');

        $url = $this->quarantine->signedUploadUrl($key, $mime, $maxSizeBytes, self::SIGNED_URL_TTL_SECONDS);

        SiteMedia::create([
            'id'               => $mediaId,
            'site_id'          => $siteId,
            'pool'             => $pool,
            'bucket'           => $bucketName,
            'path'             => $key,
            'is_active'        => false, // not visible until scan clears and state advances
            'processing_state' => SiteMedia::PROCESSING_STATE_SCANNING,
            'original_mime'    => $mime,
        ]);

        Log::info('SiteMedia quarantine upload slot created', [
            'site_media_id' => $mediaId,
            'site_id'       => $siteId,
            'pool'          => $pool,
            'bucket'        => $bucketName,
        ]);

        return [
            'url'           => $url,
            'site_media_id' => $mediaId,
            'bucket'        => $bucketName,
            'key'           => $key,
        ];
    }

    /**
     * Route the upload to the production media bucket (CSAM scanning disabled).
     * The row starts as processing_state='pending' — the normal variant-processing
     * pipeline takes over once the client notifies the backend of upload completion.
     *
     * @return array{url: string, site_media_id: string, bucket: string, key: string}
     */
    private function createProductionUpload(
        string $siteId,
        string $mediaId,
        string $mime,
        string $pool,
        int $maxSizeBytes,
    ): array {
        $diskName = MediaDiskResolver::resolve();
        $ext = $this->mimeToExtension($mime);
        $key = "images/{$siteId}/{$mediaId}.{$ext}";
        $bucketName = (string) config("filesystems.disks.{$diskName}.bucket", 'sidest-media');

        $url = Storage::disk($diskName)->temporaryUploadUrl(
            $key,
            now()->addSeconds(self::SIGNED_URL_TTL_SECONDS),
            ['ContentType' => $mime],
        )['url'];

        SiteMedia::create([
            'id'               => $mediaId,
            'site_id'          => $siteId,
            'pool'             => $pool,
            'bucket'           => $bucketName,
            'path'             => $key,
            'is_active'        => false,
            'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
            'original_mime'    => $mime,
        ]);

        Log::info('SiteMedia production upload slot created', [
            'site_media_id' => $mediaId,
            'site_id'       => $siteId,
            'pool'          => $pool,
            'bucket'        => $bucketName,
        ]);

        return [
            'url'           => $url,
            'site_media_id' => $mediaId,
            'bucket'        => $bucketName,
            'key'           => $key,
        ];
    }

    /**
     * Map a MIME type to a safe file extension for key generation.
     * Falls back to 'bin' for unknown types — downstream processors
     * re-detect the real type from the binary header.
     */
    private function mimeToExtension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/webp'    => 'webp',
            'image/gif'     => 'gif',
            'image/heic'    => 'heic',
            'image/heif'    => 'heif',
            'video/mp4'     => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm'    => 'webm',
            'application/pdf' => 'pdf',
            default         => 'bin',
        };
    }
}
