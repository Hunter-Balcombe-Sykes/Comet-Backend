<?php

namespace App\Jobs;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Concerns\GuardsMediaProcessing;
use App\Models\Core\MediaVariant;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use App\Services\Media\LogoProcessorClient;
use App\Services\Media\UnprocessableImageException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Logo pipeline: background removal (rembg) + optional SVG vectorization (VTracer),
 * delegated to the self-hosted logo-processor container, then the standard WebP
 * variant generation run over the background-removed PNG.
 *
 * Dispatched INSTEAD of ProcessImageVariantsJob for design-pool LOGO singletons
 * (logo_full / logo_square) when partna.logo_removal.enabled is true. Covers and
 * gallery/content images keep the standard GD-only path.
 *
 * State: pending → processing → ready | failed. On terminal failure the job falls
 * back to ProcessImageVariantsJob so the user's logo still appears (with its
 * original background) instead of failing outright. Mirrors ProcessImageVariantsJob's
 * locking + redelivery guards.
 */
class ProcessLogoVariantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use GuardsMediaProcessing;

    public int $tries = 3;

    // A container cold start is retry-worthy, but we want to reach the WebP
    // fallback within a few minutes if the service is genuinely down.
    public array $backoff = [30, 120, 300];

    // Generous: cold container start + model load + removal + trace. The HTTP call
    // itself is bounded by partna.logo_removal.timeout. R1-JOB-1: kept below
    // supervisor-1's 300s worker timeout (config/horizon.php) — Illuminate\Queue\
    // Worker::timeoutForJob() prefers the job's own $timeout over the supervisor's,
    // so equalling 300 left zero margin for this job's own hard kill to land before
    // the finally-block lock release / failed() fallback dispatch could run.
    public int $timeout = 280;

    public function __construct(
        public readonly string $originalPath,
        public readonly string $imageId,
        public readonly string $basePath,
        public readonly string $siteId = '',
    ) {
        $this->onQueue(config('partna.queues.images', 'images'));
    }

    public function handle(ImageVariantService $imageService, LogoProcessorClient $client): void
    {
        // Same lock strategy as ProcessImageVariantsJob: keyed on image_id so a
        // crash-then-retry can re-acquire after the TTL, and a redelivered job
        // mid-process returns instead of racing on Storage writes.
        $lockKey = "logo:processing-lock:{$this->imageId}";
        if (! $this->acquireProcessingLock($lockKey)) {
            Log::info('ProcessLogoVariantsJob: another worker is processing this logo, skipping.', [
                'image_id' => $this->imageId,
            ]);

            return;
        }

        try {
            $this->runHandle($imageService, $client);
        } finally {
            $this->releaseProcessingLock($lockKey);
        }
    }

    private function runHandle(ImageVariantService $imageService, LogoProcessorClient $client): void
    {
        $siteMedia = SiteMedia::withTrashed()->find($this->imageId);
        if (! $siteMedia || $siteMedia->trashed() || $this->isInTerminalState($siteMedia)) {
            Log::info('ProcessLogoVariantsJob: row missing/trashed/terminal, skipping.', [
                'image_id' => $this->imageId,
            ]);

            return;
        }

        SiteMedia::query()
            ->where('id', $this->imageId)
            ->whereNull('deleted_at')
            ->update([
                'processing_state' => SiteMedia::PROCESSING_STATE_PROCESSING,
                'processing_error' => null,
            ]);

        $diskName = $imageService->resolvedDiskName();
        $disk = Storage::disk($diskName);

        if (! $disk->exists($this->originalPath)) {
            // Nothing to process and nothing to fall back to — terminal failure.
            $this->markFailed('Original file not found on media disk.');
            $this->fail(new \RuntimeException('Original file not found on media disk.'));

            return;
        }

        $transparentTmp = null;
        try {
            $originalBytes = $disk->get($this->originalPath);
            if ($originalBytes === null || $originalBytes === '') {
                throw new \RuntimeException('Original file is empty.');
            }

            // 1) Background removal + (optional) vectorization in the container.
            $result = $client->process(
                imageBytes: $originalBytes,
                filename: basename($this->originalPath),
                mime: (string) ($siteMedia->original_mime ?? 'image/png'),
            );

            // 2) Generate the standard WebP variants from the background-removed PNG.
            //    ImageVariantService keeps alpha (imagesavealpha), so they're transparent.
            //    The .tmp file has no extension, but ImageVariantService byte-sniffs MIME,
            //    so it is recognised as image/png regardless of the filename.
            $transparentTmp = tempnam(sys_get_temp_dir(), 'sidest_logo_');
            if ($transparentTmp === false || file_put_contents($transparentTmp, $result->pngTransparent) === false) {
                throw new \RuntimeException('Failed to buffer the transparent PNG.');
            }

            $imageService->processVariants(
                originalTmpPath: $transparentTmp,
                imageId: $this->imageId,
                basePath: $this->basePath,
                siteId: $this->resolveSiteId($siteMedia),
                // Logos ARE palette-scanned (A2) — SiteAccentResolver reads
                // site_media.dominant_color as an accent-colour candidate. The
                // former skip (#76 MAJOR-1: "the palette factor never reads
                // them") is stale — that factor (ImageryPaletteFactor) was
                // retired with the whole contribution-ledger machine.
                extractPalette: true,
            );

            // 3) Store the SVG (when produced) as its own MediaVariant artifact.
            if ($result->vectorized()) {
                $this->storeSvgVariant($disk, $diskName, (string) $result->svg, $result->meta);
            }

            // 4) Square logos also emit a 192px icon PNG — the sitepage favicon
            //    feed (resolver exposes it as url_icon).
            if ($siteMedia->purpose === SiteMedia::PURPOSE_LOGO_SQUARE) {
                $this->storeIconVariant($disk, $diskName, $result->pngTransparent);
            }

            SiteMedia::query()
                ->where('id', $this->imageId)
                ->whereNull('deleted_at')
                ->update([
                    'processing_state' => SiteMedia::PROCESSING_STATE_READY,
                    'processing_error' => null,
                ]);

            $this->purgeEdgeCache($siteMedia);

            Log::info('ProcessLogoVariantsJob: completed.', [
                'image_id' => $this->imageId,
                'removed' => $result->meta['removed'] ?? null,
                'vectorized' => $result->vectorized(),
            ]);
        } catch (UnprocessableImageException $e) {
            // The transparent PNG itself is unprocessable. Don't burn retries —
            // fail now; failed() falls back to the standard pipeline over the original.
            Log::warning('ProcessLogoVariantsJob: unprocessable image, failing to standard fallback.', [
                'image_id' => $this->imageId,
                'error' => $e->getMessage(),
            ]);
            $this->fail($e);
        } catch (Throwable $e) {
            // Transient (container down, transport, etc.) — retry; failed() falls
            // back to the standard pipeline once retries are exhausted.
            Log::error('ProcessLogoVariantsJob: processing failed (will retry, then fall back).', [
                'image_id' => $this->imageId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            throw $e;
        } finally {
            if (is_string($transparentTmp) && file_exists($transparentTmp)) {
                @unlink($transparentTmp);
            }
        }
    }

    /**
     * Persist the SVG to the media disk + a MediaVariant(variant_key='vector',
     * artifact_type='svg') row. The .svg extension makes Flysystem serve it as
     * image/svg+xml; it is delivered via <img>/<picture>, so script-in-SVG is inert.
     */
    private function storeSvgVariant(Filesystem $disk, string $diskName, string $svg, array $meta): void
    {
        $hash = substr(hash('sha256', $svg), 0, 16);
        $path = "{$this->basePath}/vector_{$hash}.svg";

        $existingPath = MediaVariant::where([
            'media_id' => $this->imageId,
            'variant_key' => 'vector',
            'artifact_type' => 'svg',
        ])->value('path');

        if ($disk->put($path, $svg, 'public') === false) {
            throw new \RuntimeException("Media disk rejected the SVG variant write ({$path}).");
        }

        if ($existingPath && $existingPath !== $path) {
            try {
                $disk->delete($existingPath);
            } catch (Throwable $e) {
                report($e);
                Log::warning('ProcessLogoVariantsJob: failed to delete orphaned svg.', [
                    'image_id' => $this->imageId,
                    'old_path' => $existingPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        MediaVariant::updateOrCreate(
            ['media_id' => $this->imageId, 'variant_key' => 'vector', 'artifact_type' => 'svg'],
            [
                'disk' => $diskName,
                'path' => $path,
                'mime' => 'image/svg+xml',
                'width' => isset($meta['width']) ? (int) $meta['width'] : null,
                'height' => isset($meta['height']) ? (int) $meta['height'] : null,
                'file_size_bytes' => strlen($svg),
                'content_hash' => $hash,
            ],
        );
    }

    /**
     * 192×192 contain-fit transparent PNG from the processed logo — the
     * favicon/apple-touch source for sitepages. Best-effort: an icon failure
     * never fails the pipeline (the logo itself is already READY-bound).
     */
    private function storeIconVariant(Filesystem $disk, string $diskName, string $pngTransparent): void
    {
        try {
            $src = @imagecreatefromstring($pngTransparent);
            if ($src === false) {
                return;
            }

            $size = 192;
            $icon = imagecreatetruecolor($size, $size);
            imagealphablending($icon, false);
            imagesavealpha($icon, true);
            imagefill($icon, 0, 0, imagecolorallocatealpha($icon, 0, 0, 0, 127));

            $w = imagesx($src);
            $h = imagesy($src);
            $scale = min($size / max(1, $w), $size / max(1, $h), 1);
            $dw = max(1, (int) round($w * $scale));
            $dh = max(1, (int) round($h * $scale));
            imagealphablending($icon, true);
            imagecopyresampled($icon, $src, intdiv($size - $dw, 2), intdiv($size - $dh, 2), 0, 0, $dw, $dh, $w, $h);
            imagealphablending($icon, false);

            ob_start();
            imagepng($icon);
            $bytes = (string) ob_get_clean();
            imagedestroy($icon);
            imagedestroy($src);
            if ($bytes === '') {
                return;
            }

            $hash = substr(hash('sha256', $bytes), 0, 16);
            $path = "{$this->basePath}/icon_{$hash}.png";

            $existingPath = MediaVariant::where([
                'media_id' => $this->imageId,
                'variant_key' => 'icon',
                'artifact_type' => 'png',
            ])->value('path');

            if ($disk->put($path, $bytes, 'public') === false) {
                throw new \RuntimeException("Media disk rejected the icon variant write ({$path}).");
            }

            if ($existingPath && $existingPath !== $path) {
                try {
                    $disk->delete($existingPath);
                } catch (Throwable $e) {
                    report($e);
                    Log::warning('ProcessLogoVariantsJob: failed to delete orphaned icon.', [
                        'image_id' => $this->imageId, 'old_path' => $existingPath, 'error' => $e->getMessage(),
                    ]);
                }
            }

            MediaVariant::updateOrCreate(
                ['media_id' => $this->imageId, 'variant_key' => 'icon', 'artifact_type' => 'png'],
                [
                    'disk' => $diskName,
                    'path' => $path,
                    'mime' => 'image/png',
                    'width' => $size,
                    'height' => $size,
                    'file_size_bytes' => strlen($bytes),
                    'content_hash' => $hash,
                ],
            );
        } catch (Throwable $e) {
            report($e);
            Log::warning('ProcessLogoVariantsJob: icon variant generation failed.', [
                'image_id' => $this->imageId, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function purgeEdgeCache(SiteMedia $siteMedia): void
    {
        try {
            $subdomain = $siteMedia->site?->subdomain;
            if (is_string($subdomain) && $subdomain !== '') {
                CloudflareCachePurgeJob::dispatch($subdomain);
            }
        } catch (Throwable $e) {
            report($e);
            Log::warning('ProcessLogoVariantsJob: cache purge dispatch failed.', [
                'image_id' => $this->imageId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Terminal failure handler. Rather than 500 the user's logo, reset to pending
     * and run the standard (no-removal) pipeline so the original logo still
     * appears. The original file is intentionally NOT deleted — the fallback
     * reads it. If that also fails, ProcessImageVariantsJob::failed() is terminal.
     */
    public function failed(Throwable $e): void
    {
        report($e);

        // An SVG original has NO raster fallback (2026-08-28). The standard
        // pipeline byte-sniffs and rejects image/svg+xml by design (SVG is an
        // XSS vector there), so dispatching it guarantees a SECOND exception —
        // which is exactly the pair Nightwatch has been showing: a logo
        // processor 500 followed by "MIME type 'image/svg+xml' is not an
        // accepted image format". The two issues were one incident.
        //
        // The SVG is still a perfectly good logo: this pipeline already serves
        // vector variants through <img> (storeSvgVariant), where script-in-SVG
        // is inert, and LogoAutoGrabber::svgIsSafe() sanitised these bytes at
        // ingest. So publish it as the vector variant instead of throwing it
        // at a decoder that cannot read it — the user keeps their logo, minus
        // only the background removal the container would have done.
        if (str_ends_with(strtolower($this->originalPath), '.svg')) {
            $this->fallBackToRawSvg();

            return;
        }

        $reset = SiteMedia::query()
            ->where('id', $this->imageId)
            ->whereNull('deleted_at')
            ->update([
                'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
                'processing_error' => null,
            ]);

        if ($reset === 0) {
            Log::info('ProcessLogoVariantsJob: row gone at failure; no fallback dispatched.', [
                'image_id' => $this->imageId,
            ]);

            return;
        }

        Log::warning('ProcessLogoVariantsJob: falling back to standard WebP variants (no background removal).', [
            'image_id' => $this->imageId,
        ]);

        ProcessImageVariantsJob::dispatch(
            originalPath: $this->originalPath,
            imageId: $this->imageId,
            basePath: $this->basePath,
            siteId: $this->siteId,
        );
    }

    private function resolveSiteId(SiteMedia $siteMedia): string
    {
        return $this->siteId !== '' ? $this->siteId : (string) ($siteMedia->site_id ?? '');
    }

    /**
     * Terminal fallback for an SVG original (2026-08-28): publish the source
     * SVG itself as the `vector` variant and mark the media ready, so a logo
     * survives a processor outage instead of vanishing.
     *
     * Deliberately writes ONLY the vector variant — the raster tiers stay
     * absent, which every consumer already tolerates (the vector variant is
     * the one the logo surfaces prefer). If even this fails, the media is
     * marked failed with a readable reason rather than left processing.
     */
    private function fallBackToRawSvg(): void
    {
        try {
            $imageService = app(ImageVariantService::class);
            $diskName = $imageService->resolvedDiskName();
            $disk = Storage::disk($diskName);

            $svg = $disk->exists($this->originalPath) ? $disk->get($this->originalPath) : null;
            if (! is_string($svg) || $svg === '') {
                $this->markFailed('Logo processor failed and the SVG original was unreadable.');

                return;
            }

            $this->storeSvgVariant($disk, $diskName, $svg, []);

            SiteMedia::query()
                ->where('id', $this->imageId)
                ->whereNull('deleted_at')
                ->update([
                    'processing_state' => SiteMedia::PROCESSING_STATE_READY,
                    'processing_error' => null,
                ]);

            Log::warning('ProcessLogoVariantsJob: processor unavailable; served the original SVG as the vector variant.', [
                'image_id' => $this->imageId,
            ]);
        } catch (Throwable $e) {
            report($e);
            $this->markFailed('Logo processor failed and the SVG fallback could not be stored.');
        }
    }

    private function markFailed(string $reason): void
    {
        SiteMedia::query()
            ->where('id', $this->imageId)
            ->whereNull('deleted_at')
            ->update([
                'processing_state' => SiteMedia::PROCESSING_STATE_FAILED,
                'processing_error' => $reason,
            ]);
    }
}
