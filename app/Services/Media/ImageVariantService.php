<?php

namespace App\Services\Media;

use App\Models\Core\MediaVariant;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generates universal WebP image variants from an uploaded original and
 * persists them to the configured media disk (S3-compatible / R2).
 *
 * Every image (gallery or content) gets the same configured variant set.
 * Current default produces two full-resolution variants:
 * - optimized (target-size adaptive quality)
 * - maximized (highest quality)
 *
 * Requires the GD extension with WebP support (ships with PHP 8.2+).
 */
// V2: Generates WebP image variants from uploads via GD. Content-hashed storage on Cloudflare R2 with adaptive quality targeting.
class ImageVariantService
{
    private const ALLOWED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Safe extensions for R2 object keys — anything else falls back to the canonical default. */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov', 'webm'];

    // Constructor-injected so it can be swapped in tests; defaults to a plain
    // instance for the many `new ImageVariantService` call-sites (job, tests).
    public function __construct(
        private readonly ImagePaletteExtractor $paletteExtractor = new ImagePaletteExtractor,
    ) {}

    /**
     * Process an original image into all configured variants, store them
     * on the media disk, and persist ImageVariant rows for the given image.
     *
     * @param  string  $originalTmpPath  Absolute path to the temp original.
     * @param  string  $imageId  UUID of the SiteMedia row.
     * @param  string  $basePath  e.g. "images/<proId>/<imageId>"
     * @return array<string, MediaVariant> keyed by variant name
     */
    public function processVariants(
        string $originalTmpPath,
        string $imageId,
        string $basePath,
        string $siteId = '',
        // Palette metadata (dominant colour + swatches) is read by
        // SiteAccentResolver as an accent-colour candidate for both gallery
        // AND design-pool (logo) media — the former ImageryPaletteFactor
        // consumer this comment used to describe was retired with the whole
        // contribution-ledger machine; ProcessLogoVariantsJob passes true.
        bool $extractPalette = true,
    ): array {
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('GD extension is not loaded. Cannot process image variants.');
        }

        if (! function_exists('imagewebp')) {
            throw new \RuntimeException('GD WebP support is not available. Cannot generate WebP variants.');
        }

        Log::info('Starting image variant processing', [
            'image_id' => $imageId,
            'base_path' => $basePath,
        ]);

        $disk = $this->disk();
        $definitions = $this->variantDefinitions();

        $sourceImage = $this->loadImage($originalTmpPath);

        if (! $sourceImage) {
            throw new \RuntimeException('Failed to create GD image from the uploaded file.');
        }

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);

        // Extract dominant-colour + palette metadata from the already-decoded
        // source (#76). Best-effort and fully isolated: a failure here must NEVER
        // affect variant generation or fail the upload — the ImageryPaletteFactor
        // simply abstains for a NULL palette. Persisted via a scoped query-builder
        // update so it bypasses SiteMediaObserver (no cache churn on a metadata write).
        if ($extractPalette) {
            $this->storePalette($sourceImage, $imageId, $siteId);
        }

        $created = [];

        try {
            foreach ($definitions as $variantName => $def) {
                $quality = max(1, min(100, (int) ($def['quality'] ?? 92)));
                $minQuality = max(1, min($quality, (int) ($def['min_quality'] ?? 60)));
                $targetKb = max(0, (int) ($def['target_kb'] ?? 0));
                $targetBytes = $targetKb > 0 ? ($targetKb * 1024) : null;
                $fit = (string) ($def['fit'] ?? 'inside');

                $preserveResolution = filter_var($def['preserve_resolution'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if ($preserveResolution) {
                    [$cropX, $cropY, $cropW, $cropH, $dstW, $dstH] = [0, 0, $sourceWidth, $sourceHeight, $sourceWidth, $sourceHeight];
                } else {
                    $targetW = max(1, (int) ($def['width'] ?? $sourceWidth));
                    $targetH = max(1, (int) ($def['height'] ?? $sourceHeight));

                    // Backward-compatible path for capped variants.
                    [$cropX, $cropY, $cropW, $cropH, $dstW, $dstH] = $this->calculateDimensions(
                        $sourceWidth, $sourceHeight, $targetW, $targetH, $fit,
                    );
                }

                $canvas = imagecreatetruecolor($dstW, $dstH);
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);

                imagecopyresampled(
                    $canvas, $sourceImage,
                    0, 0, $cropX, $cropY,
                    $dstW, $dstH, $cropW, $cropH,
                );

                // --- Encode to WebP ---
                $tmpFile = tempnam(sys_get_temp_dir(), 'sidest_img_');
                if (! is_string($tmpFile) || $tmpFile === '') {
                    throw new \RuntimeException('Failed to create temp file for WebP encoding.');
                }

                try {
                    $encodedQuality = $quality;
                    if ($targetBytes !== null) {
                        $encodedQuality = $this->encodeWebpToTargetSize(
                            image: $canvas,
                            tmpFile: $tmpFile,
                            maxQuality: $quality,
                            minQuality: $minQuality,
                            targetBytes: $targetBytes,
                        );
                    } else {
                        $this->encodeWebp($canvas, $tmpFile, $quality);
                    }

                    $fileBytes = filesize($tmpFile);
                    if ($fileBytes === false) {
                        throw new \RuntimeException('Failed to determine encoded WebP file size.');
                    }

                    if ($targetBytes !== null && $fileBytes > $targetBytes) {
                        Log::warning('Image variant exceeded target size at min quality.', [
                            'image_id' => $imageId,
                            'site_id' => $siteId,
                            'variant' => $variantName,
                            'target_kb' => $targetKb,
                            'actual_kb' => (int) round($fileBytes / 1024),
                            'quality_used' => $encodedQuality,
                        ]);
                    }

                    $hash = hash_file('sha256', $tmpFile);
                    if (! is_string($hash)) {
                        throw new \RuntimeException('Failed to hash encoded WebP variant.');
                    }
                    $hash = substr($hash, 0, 16);

                    $storagePath = "{$basePath}/{$variantName}_{$hash}.webp";

                    // Capture old path before upload for orphan cleanup on re-process.
                    $existingPath = MediaVariant::where([
                        'media_id' => $imageId,
                        'variant_key' => $variantName,
                        'artifact_type' => 'webp',
                    ])->value('path');

                    // --- Upload to bucket ---
                    $payload = file_get_contents($tmpFile);
                    if ($payload === false) {
                        throw new \RuntimeException('Failed to read encoded WebP for upload.');
                    }
                    if ($disk->put($storagePath, $payload, 'public') === false) {
                        throw new \RuntimeException("Media disk rejected the variant write ({$storagePath}).");
                    }

                    // Delete old file when re-processing produces a different content-hashed path.
                    if ($existingPath && $existingPath !== $storagePath) {
                        try {
                            $disk->delete($existingPath);
                        } catch (\Throwable $e) {
                            Log::warning('ImageVariantService: failed to delete orphaned variant file.', [
                                'image_id' => $imageId,
                                'variant' => $variantName,
                                'old_path' => $existingPath,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    // --- Upsert DB row ---
                    $variant = MediaVariant::updateOrCreate(
                        [
                            'media_id' => $imageId,
                            'variant_key' => $variantName,
                            'artifact_type' => 'webp',
                        ],
                        [
                            'disk' => $this->diskName(),
                            'path' => $storagePath,
                            'mime' => 'image/webp',
                            'width' => $dstW,
                            'height' => $dstH,
                            'file_size_bytes' => $fileBytes,
                            'content_hash' => $hash,
                        ],
                    );

                    $created[$variantName] = $variant;
                } finally {
                    @unlink($tmpFile);
                    unset($canvas);
                }
            }
        } finally {
            unset($sourceImage);
        }

        Log::info('Image variant processing completed', [
            'image_id' => $imageId,
            'variant_count' => count($created),
        ]);

        return $created;
    }

    /**
     * Extract and persist colour-palette metadata for a media row from its decoded
     * source image (#76 Part A). Best-effort: any failure is swallowed and logged
     * at debug level — the row's palette simply stays NULL, and SiteAccentResolver
     * skips it as a candidate. Writes via a scoped query-builder update so the palette metadata
     * write does NOT trip SiteMediaObserver (which would purge the edge cache and
     * re-resolve presets on every image process — the READY transition already
     * handles the intentional purge).
     *
     * $siteId is threaded only for log correlation.
     */
    private function storePalette(\GdImage $sourceImage, string $imageId, string $siteId): void
    {
        try {
            $palette = $this->paletteExtractor->fromGd($sourceImage);
            if ($palette === null) {
                return; // no usable palette (e.g. fully transparent / neutral) — leave NULL
            }

            SiteMedia::query()
                ->where('id', $imageId)
                ->whereNull('deleted_at')
                ->update([
                    'dominant_color' => $palette['dominant'],
                    'palette' => json_encode($palette),
                ]);
        } catch (\Throwable $e) {
            // Palette is a non-essential enrichment — never let it disrupt processing.
            Log::debug('ImageVariantService: palette extraction failed (non-fatal).', [
                'image_id' => $imageId,
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Normalize a client-supplied extension against the allowlist.
     * Falls back to $default if the value is empty or not on the list.
     */
    public function safeExtension(string $clientExt, string $default = 'jpg'): string
    {
        $normalized = strtolower(ltrim($clientExt, '.'));

        return in_array($normalized, self::ALLOWED_EXTENSIONS, true) ? $normalized : $default;
    }

    /**
     * Store the original upload to a location on the media disk
     * (kept for disaster-recovery / re-processing).
     *
     * Byte-sniffs the MIME via finfo BEFORE writing — the client-supplied
     * extension/MIME is never trusted as the gate. Uploads stream through
     * fopen() so we don't buffer the whole file in PHP memory.
     *
     * Returns the storage path of the original.
     */
    public function storeOriginal(UploadedFile $file, string $basePath): string
    {
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw new \RuntimeException('Uploaded file is not resolvable to a real path.');
        }

        // Sniff before doing anything else — bad input is rejected before we
        // hash, derive a key, or open a disk connection.
        $this->assertImageMime($realPath);

        $ext = $this->safeExtension($file->getClientOriginalExtension() ?? '', 'jpg');
        $hash = substr(hash_file('sha256', $realPath), 0, 16);
        $path = "{$basePath}/original_{$hash}.{$ext}";

        $stream = fopen($realPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open uploaded file for streaming.');
        }

        try {
            // 'private' — originals are kept only as a re-processing source;
            // the public deliverable is the variants (ACL 'public') in
            // MediaVariant rows. Document originals are a separate path
            // (UserDocumentController) and remain 'public'.
            //
            // The return value MUST be checked: on the non-throwing
            // public_dev alias a failed write is a false return, and this
            // method's path was then stamped onto the DB row as if stored —
            // ProcessImageVariantsJob later died on "Original file not found
            // on media disk" (Nightwatch #493, 2026-09-04) with the actual
            // failure long gone.
            $stored = $this->disk()->put($path, $stream, 'private');
            if ($stored === false) {
                throw new \RuntimeException("Media disk rejected the original write ({$path}).");
            }
        } finally {
            // Flysystem-S3 closes the resource on success; the finally covers
            // failure paths. Double-fclose on a closed handle is harmless.
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $path;
    }

    /**
     * Delete all image variant files (and DB rows) for a given SiteMedia item,
     * plus the original file stored at the image's path.
     */
    public function deleteVariants(string $imageId, ?string $originalPath = null): void
    {
        $variants = MediaVariant::where('media_id', $imageId)
            ->where('artifact_type', 'webp')
            ->get();
        $disk = $this->disk();

        $failures = [];
        foreach ($variants as $variant) {
            try {
                $deleted = $disk->delete($variant->path);
                if ($deleted === false) {
                    $failures[] = ['path' => $variant->path, 'error' => 'delete returned false'];
                }
            } catch (\Throwable $e) {
                $failures[] = [
                    'path' => $variant->path,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ];
            }
            $variant->delete();
        }

        // Also remove the original if a path was provided
        if ($originalPath) {
            try {
                if ($disk->exists($originalPath)) {
                    $deleted = $disk->delete($originalPath);
                    if ($deleted === false) {
                        $failures[] = ['path' => $originalPath, 'error' => 'delete returned false'];
                    }
                }
            } catch (\Throwable $e) {
                $failures[] = [
                    'path' => $originalPath,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ];
            }
        }

        if ($failures !== []) {
            // report() surfaces this to Nightwatch — Log::error alone raises no
            // alert. DB rows are already cleared above regardless of this branch;
            // this only flags that orphaned storage objects may remain.
            report(new \RuntimeException(sprintf(
                'ImageVariantService::deleteVariants: %d storage delete failure(s) for image %s',
                count($failures),
                $imageId,
            )));
            Log::error('ImageVariantService::deleteVariants: storage delete failures; DB rows cleared, orphans may remain.', [
                'image_id' => $imageId,
                'failure_count' => count($failures),
                'failures' => array_slice($failures, 0, 20),
            ]);
        }
    }

    /**
     * Build a variant map (variant_key → public URL) for a SiteMedia image item.
     *
     * @return array<string, string>
     */
    public function variantUrls(string $imageId): array
    {
        return MediaVariant::where('media_id', $imageId)
            ->where('artifact_type', 'webp')
            ->get()
            ->mapWithKeys(fn (MediaVariant $v) => [$v->variant_key => $v->url])
            ->all();
    }

    /**
     * Resolve the effective media disk name used for all reads/writes.
     */
    public function resolvedDiskName(): string
    {
        return $this->diskName();
    }

    private function diskName(): string
    {
        return MediaDiskResolver::resolve();
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * Universal variant definitions (same for all images).
     */
    private function variantDefinitions(): array
    {
        $definitions = (array) config('partna.image_variants', []);

        if ($definitions !== []) {
            return $definitions;
        }

        // NOTE: this fallback MUST mirror config/partna.php image_variants exactly.
        // It runs only when config is empty (sparse test environments).
        return [
            'optimized' => [
                'format' => 'webp',
                'width' => 2400,
                'height' => 2400,
                'fit' => 'inside',
                'quality' => 92,
                'min_quality' => 60,
                'target_kb' => 500,
            ],
            'maximized' => [
                'format' => 'webp',
                'width' => 4000,
                'height' => 4000,
                'fit' => 'inside',
                'quality' => 92,
            ],
        ];
    }

    /**
     * Load an image file into a GD resource regardless of source format.
     *
     * Refuses images whose pixel count exceeds partna.image_max_pixels by
     * throwing UnprocessableImageException BEFORE any bitmap memory is
     * allocated. This is the defense against image-bomb uploads — a tiny
     * JPEG/PNG file can decode to a huge bitmap (4 bytes per pixel), which
     * the worker cannot survive even with the canvas caps applied.
     *
     * The getimagesize() call reads only the file header (a few KB), not
     * the pixel data, so the guard is effectively free.
     */
    private function loadImage(string $path): \GdImage|false
    {
        // Sniff actual bytes before getimagesize — prevents a crafted file from
        // claiming a safe format while hiding a decompression bomb in its content stream.
        $this->assertImageMime($path);

        $info = @getimagesize($path);
        if (! $info) {
            return false;
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $maxPixels = (int) config('partna.image_max_pixels', 24_000_000);

        if ($width * $height > $maxPixels) {
            throw new UnprocessableImageException(sprintf(
                'Image dimensions exceed safe processing limit (%d x %d = %d pixels, max %d).',
                $width,
                $height,
                $width * $height,
                $maxPixels,
            ));
        }

        return match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };
    }

    /**
     * Byte-sniff the file's MIME via finfo and reject anything outside the
     * allowed-image allowlist. Throws UnprocessableImageException on miss.
     *
     * Used by both storeOriginal() (gate the upload to disk) and loadImage()
     * (gate GD decoding) so client-supplied extension/MIME is never trusted.
     */
    private function assertImageMime(string $path): void
    {
        $actualMime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($actualMime, self::ALLOWED_IMAGE_MIMES, true)) {
            throw new UnprocessableImageException(
                "Rejected: MIME type '{$actualMime}' is not an accepted image format."
            );
        }
    }

    /**
     * Encode an image as WebP at the given quality and return file size.
     */
    private function encodeWebp(\GdImage $image, string $tmpFile, int $quality): int
    {
        if (@imagewebp($image, $tmpFile, $quality) === false) {
            throw new \RuntimeException("Failed to encode WebP at quality {$quality}.");
        }

        clearstatcache(true, $tmpFile);
        $size = filesize($tmpFile);
        if ($size === false) {
            throw new \RuntimeException('Failed to read encoded WebP size.');
        }

        return $size;
    }

    /**
     * Binary-search the highest quality that stays under target bytes.
     *
     * Returns the quality used for the final file in $tmpFile.
     */
    private function encodeWebpToTargetSize(
        \GdImage $image,
        string $tmpFile,
        int $maxQuality,
        int $minQuality,
        int $targetBytes,
    ): int {
        $lower = max(1, min(100, $minQuality));
        $upper = max($lower, min(100, $maxQuality));

        $bestQuality = null;

        while ($lower <= $upper) {
            $mid = intdiv($lower + $upper, 2);
            $size = $this->encodeWebp($image, $tmpFile, $mid);

            if ($size <= $targetBytes) {
                $bestQuality = $mid;
                $lower = $mid + 1;

                continue;
            }

            $upper = $mid - 1;
        }

        if ($bestQuality !== null) {
            $this->encodeWebp($image, $tmpFile, $bestQuality);

            return $bestQuality;
        }

        // Could not satisfy target even at min quality.
        $this->encodeWebp($image, $tmpFile, max(1, min(100, $minQuality)));

        return max(1, min(100, $minQuality));
    }

    /**
     * Calculate crop / resize geometry.
     *
     * @return array [cropX, cropY, cropW, cropH, dstW, dstH]
     */
    private function calculateDimensions(
        int $srcW, int $srcH,
        int $maxW, int $maxH,
        string $fit,
    ): array {
        if ($fit === 'cover') {
            $srcRatio = $srcW / $srcH;
            $dstRatio = $maxW / $maxH;

            if ($srcRatio > $dstRatio) {
                $cropH = $srcH;
                $cropW = (int) round($srcH * $dstRatio);
                $cropX = (int) round(($srcW - $cropW) / 2);
                $cropY = 0;
            } else {
                $cropW = $srcW;
                $cropH = (int) round($srcW / $dstRatio);
                $cropX = 0;
                $cropY = (int) round(($srcH - $cropH) / 2);
            }

            return [$cropX, $cropY, $cropW, $cropH, $maxW, $maxH];
        }

        // "inside" – fit within bounds, no crop, never upscale
        $ratio = min($maxW / $srcW, $maxH / $srcH, 1);
        $dstW = (int) round($srcW * $ratio);
        $dstH = (int) round($srcH * $ratio);

        return [0, 0, $srcW, $srcH, $dstW, $dstH];
    }
}
