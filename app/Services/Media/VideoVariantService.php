<?php

namespace App\Services\Media;

use App\Models\Core\MediaVariant;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Transcodes a video original into two MP4 tiers and a poster image,
 * stores all artifacts on the media disk, and persists MediaVariant rows.
 *
 * Processing order (avoids extra re-encodes):
 *   1. FFprobe  – extract metadata + validate duration
 *   2. FFmpeg   – encode optimized MP4 (720p)
 *   3. FFmpeg   – encode maximized MP4 (1080p)
 *   4. FFmpeg   – extract poster JPEG at 1s mark
 *   5. Upload   – stream all artifacts to media disk
 *   6. DB upsert – persist one MediaVariant row per logical artifact
 *   7. SiteMedia update – set processing_state, duration_ms, poster_path
 *
 * Storage layout under the media disk (all artifact paths are
 * content-hashed so the CDN treats re-processed files as fresh URLs —
 * mirrors the image variant pattern, prevents stale-cache reads):
 *   videos/{proId}/{mediaId}/
 *     original_{hash}.{ext}
 *     optimized_{hash}.mp4
 *     maximized_{hash}.mp4
 *     poster_{hash}.jpg
 *
 * Requires ffmpeg and ffprobe to be available (configured via config/partna.php).
 */
// V2: Transcodes videos to two MP4 tiers (720p + 1080p) + a poster via FFmpeg.
// Feature-flagged (PARTNA_VIDEO_UPLOADS_ENABLED). Uses dedicated redis_video connection.
class VideoVariantService
{
    /** Codecs we will transcode. hevc = H.265 (ffprobe reports 'hevc', not 'h265'). */
    private const ALLOWED_CODECS = ['h264', 'hevc', 'vp9'];

    /**
     * 4K ceiling: long edge ≤ 3840px, short edge ≤ 2160px. This guards FFmpeg
     * decode cost/memory (full-res frame buffers), NOT file size — a small but
     * highly-compressed 8K source would otherwise blow up the worker. Output is
     * always downscaled to ≤1080p regardless.
     */
    private const MAX_RESOLUTION_LONG = 3840;

    private const MAX_RESOLUTION_SHORT = 2160;

    /* ------------------------------------------------------------------ */
    /*  Public API */
    /* ------------------------------------------------------------------ */

    /**
     * Run FFprobe on a local file and return metadata array.
     * Throws \RuntimeException if the binary fails.
     *
     * @return array<string, mixed>
     */
    public function probe(string $localPath): array
    {
        $ffprobe = $this->ffprobeBinary();

        // Array-form Process: each element is a literal argument; no shell interpolation.
        $process = new Process([
            $ffprobe, '-v', 'quiet', '-print_format', 'json',
            '-show_format', '-show_streams', $localPath,
        ]);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            $exitCode = $process->getExitCode();
            $err = $process->getErrorOutput() ?: $process->getOutput();
            throw new \RuntimeException("ffprobe failed (exit {$exitCode}): {$err}");
        }

        $data = json_decode($process->getOutput(), true);
        if (! is_array($data)) {
            throw new \RuntimeException('ffprobe returned non-JSON output.');
        }

        return $data;
    }

    /**
     * Probe a local file and validate it contains at least one video stream
     * and does not exceed the configured maximum duration.
     *
     * Call this in the upload controller before storing to R2 so malformed
     * containers and over-length videos are rejected at the HTTP boundary,
     * not on the worker.
     *
     * @return array<string, mixed> Raw ffprobe data (reuse to avoid a second probe).
     *
     * @throws \RuntimeException if the container is unreadable, has no video stream, or exceeds max duration.
     */
    public function probeAndValidate(string $localPath): array
    {
        $probe = $this->probe($localPath);

        $videoStream = null;
        foreach ($probe['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') {
                $videoStream = $stream;
                break;
            }
        }

        if ($videoStream === null) {
            throw new \RuntimeException('File does not contain a recognisable video stream.');
        }

        $codec = strtolower((string) ($videoStream['codec_name'] ?? ''));
        if (! in_array($codec, self::ALLOWED_CODECS, true)) {
            throw new \RuntimeException(
                "Unsupported video codec '{$codec}'. Allowed: ".implode(', ', self::ALLOWED_CODECS).'.'
            );
        }

        $width = (int) ($videoStream['width'] ?? 0);
        $height = (int) ($videoStream['height'] ?? 0);
        $longEdge = max($width, $height);
        $shortEdge = min($width, $height);
        if ($longEdge > self::MAX_RESOLUTION_LONG || $shortEdge > self::MAX_RESOLUTION_SHORT) {
            throw new \RuntimeException(
                "Video resolution {$width}×{$height} exceeds the maximum allowed (3840×2160 / 4K)."
            );
        }

        $durationMs = $this->extractDurationMs($probe);
        $maxDurSec = (int) config('partna.video_max_duration_seconds', 300);

        if ($durationMs > $maxDurSec * 1000) {
            $actualSec = (int) round($durationMs / 1000);
            throw new \RuntimeException(
                "Video is too long ({$actualSec}s). Maximum allowed duration is {$maxDurSec}s."
            );
        }

        return $probe;
    }

    /**
     * Process a video original into all configured artifacts.
     *
     * @throws \RuntimeException on unrecoverable processing errors
     */
    public function processVariants(
        string $localOriginalPath,
        string $mediaId,
        string $basePath,
    ): void {
        Log::info('VideoVariantService: starting', [
            'media_id' => $mediaId,
            'base_path' => $basePath,
        ]);

        // --- 1. Probe metadata + validate codec, resolution, and duration ---
        $probe = $this->probeAndValidate($localOriginalPath);
        $durationMs = $this->extractDurationMs($probe);

        // Timeout budget: 2× video duration + 60s, floored at 120s for very short clips.
        $encodingTimeout = max(120, (int) round($durationMs / 1000) * 2 + 60);

        $variantDefs = (array) config('partna.video_variants', []);

        try {
            // --- 2 & 3. Encode MP4 variants ---
            $mp4Paths = [];
            foreach ($variantDefs as $variantKey => $def) {
                $tmpMp4 = $this->makeTmpFile("sidest_mp4_{$variantKey}_", '.mp4');
                $this->encodeMp4($localOriginalPath, $tmpMp4, $def, $encodingTimeout);
                $mp4Paths[$variantKey] = $tmpMp4;
            }

            // --- 4. Extract poster ---
            $tmpPoster = $this->makeTmpFile('sidest_poster_', '.jpg');
            $this->extractPoster($localOriginalPath, $tmpPoster, $encodingTimeout);

            // --- 5. Upload all artifacts ---
            $disk = $this->disk();
            $diskName = $this->resolvedDiskName();

            // Upload MP4s. Content-hash each so re-encoding the same source
            // produces identical paths (idempotent) while a different source
            // produces a new URL — the CDN can't serve a stale half-overwritten
            // file mid-replace. Mirrors ImageVariantService.
            foreach ($mp4Paths as $variantKey => $mp4) {
                $hash = substr((string) hash_file('sha256', $mp4), 0, 16);

                $remotePath = "{$basePath}/{$variantKey}_{$hash}.mp4";
                $stream = fopen($mp4, 'rb');
                $disk->put($remotePath, $stream, 'public');
                if (is_resource($stream)) {
                    fclose($stream);
                }

                $def = $variantDefs[$variantKey] ?? [];
                // Unique key: (media_id, variant_key, artifact_type)
                MediaVariant::updateOrCreate(
                    ['media_id' => $mediaId, 'variant_key' => $variantKey, 'artifact_type' => 'mp4'],
                    [
                        'disk' => $diskName,
                        'path' => $remotePath,
                        'mime' => 'video/mp4',
                        'bitrate_kbps' => (int) ($def['video_bitrate_kbps'] ?? 0) + (int) ($def['audio_bitrate_kbps'] ?? 0),
                        'file_size_bytes' => filesize($mp4) ?: null,
                        'duration_ms' => $durationMs,
                        'content_hash' => $hash,
                        'metadata' => ['resolution' => $def['resolution'] ?? null],
                    ]
                );
            }

            // Upload poster
            $posterHash = substr((string) hash_file('sha256', $tmpPoster), 0, 16);
            $posterRemotePath = "{$basePath}/poster_{$posterHash}.jpg";
            $stream = fopen($tmpPoster, 'rb');
            $disk->put($posterRemotePath, $stream, ['visibility' => 'public', 'ContentType' => 'image/jpeg']);
            if (is_resource($stream)) {
                fclose($stream);
            }

            MediaVariant::updateOrCreate(
                ['media_id' => $mediaId, 'variant_key' => 'poster', 'artifact_type' => 'poster'],
                [
                    'disk' => $diskName,
                    'path' => $posterRemotePath,
                    'mime' => 'image/jpeg',
                    'file_size_bytes' => filesize($tmpPoster) ?: null,
                    'content_hash' => $posterHash,
                ]
            );

            // --- 8. Update SiteMedia ---
            $this->markReady($mediaId, $durationMs, $posterRemotePath);

            Log::info('VideoVariantService: completed', [
                'media_id' => $mediaId,
                'duration_ms' => $durationMs,
            ]);
        } finally {
            // Cleanup temp files
            foreach ($mp4Paths ?? [] as $mp4) {
                if (file_exists($mp4)) {
                    @unlink($mp4);
                }
            }
            if (isset($tmpPoster) && file_exists($tmpPoster)) {
                @unlink($tmpPoster);
            }
        }
    }

    /**
     * Advance a video SiteMedia row to ready and touch the parent Site so
     * SiteObserver::saved fires (Cloudflare purge + cache-key roll + local
     * Redis invalidation). Mass-updates via the query builder bypass
     * SiteMediaObserver — the same gap the reorder controller works around
     * with $site->touch(). Without this, content-pool and gallery videos
     * surface only after the public_profile cache TTL expires (60 s default).
     *
     * Use a model load + save() rather than a mass update so the observer
     * chain fires naturally. The DB cost is one extra SELECT, paid once
     * per video at completion — well worth a push-fresh public profile.
     */
    public function markReady(string $mediaId, int $durationMs, ?string $posterPath): void
    {
        $media = SiteMedia::query()
            ->whereNull('deleted_at')
            ->find($mediaId);

        if (! $media) {
            return; // Soft-deleted mid-processing; nothing to advance.
        }

        $media->processing_state = SiteMedia::PROCESSING_STATE_READY;
        $media->processing_error = null;
        $media->duration_ms = $durationMs;
        $media->poster_path = $posterPath;
        $media->save(); // SiteMediaObserver::saved → $site->touch() → cache roll.
    }

    /**
     * Delete all media_variants DB rows and all files under $basePath on disk.
     * Called by DeleteMediaArtifactsJob (async) for video cleanup.
     *
     * Best-effort on storage, unconditional on DB: the DB row is the
     * user-facing "this media exists" flag, so we always scrub it — any
     * orphaned storage files become an out-of-band ops concern, logged at
     * error level so Nightwatch surfaces them. Throwing on a transient R2
     * blip here would re-queue the job and amplify the failure.
     */
    public function deleteVariants(string $mediaId, string $basePath): void
    {
        $disk = $this->disk();
        $basePrefix = $this->normalizeVideoCleanupBasePath($basePath);

        $files = [];
        $listError = null;

        try {
            $files = $disk->allFiles($basePrefix);
        } catch (\Throwable $e) {
            $listError = $e;
        }

        $failures = [];
        foreach ($files as $file) {
            try {
                $deleted = $disk->delete($file);
                if ($deleted === false) {
                    $failures[] = ['path' => $file, 'error' => 'delete returned false'];
                }
            } catch (\Throwable $e) {
                $failures[] = [
                    'path' => $file,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ];
            }
        }

        // Deferred-unconditional: runs even if listing threw or per-file deletes failed.
        MediaVariant::where('media_id', $mediaId)->delete();

        if ($listError !== null) {
            Log::error('VideoVariantService::deleteVariants list failed; DB rows still cleared.', [
                'media_id' => $mediaId,
                'base_prefix' => $basePrefix,
                'error' => $listError->getMessage(),
                'exception' => get_class($listError),
            ]);
        }

        if ($failures !== []) {
            Log::error('VideoVariantService::deleteVariants: storage delete failures; DB rows cleared, orphans may remain.', [
                'media_id' => $mediaId,
                'base_prefix' => $basePrefix,
                'failure_count' => count($failures),
                // Cap the sample so pathological cases don't bloat log lines.
                'failures' => array_slice($failures, 0, 20),
            ]);
        }
    }

    /**
     * Resolve the effective media disk name.
     * Mirrors the logic in ImageVariantService for consistency.
     */
    public function resolvedDiskName(): string
    {
        return $this->diskName();
    }

    /* ------------------------------------------------------------------ */
    /*  Private helpers */
    /* ------------------------------------------------------------------ */

    private function encodeMp4(string $input, string $output, array $def, int $timeout): void
    {
        $ffmpeg = $this->ffmpegBinary();
        $resolution = (string) ($def['resolution'] ?? '1280x720');
        [$width, $height] = array_map('intval', explode('x', $resolution, 2));
        $videoBitrate = (int) ($def['video_bitrate_kbps'] ?? 2000);
        $audioBitrate = (int) ($def['audio_bitrate_kbps'] ?? 128);

        // Scale to fit within resolution bounds while maintaining aspect ratio.
        // The scale filter uses -2 to keep dimensions divisible by 2 (required by libx264).
        $scaleFilter = "scale='if(gt(iw,{$width}),{$width},-2)':'if(gt(ih,{$height}),{$height},-2)'";

        $cmd = [
            $ffmpeg, '-y', '-i', $input,
            '-vf', $scaleFilter,
            '-c:v', 'libx264',
            '-b:v', "{$videoBitrate}k",
            '-maxrate', ((int) ($videoBitrate * 1.5)).'k',
            '-bufsize', ((int) ($videoBitrate * 2)).'k',
            '-profile:v', 'main', '-level', '4.0',
            '-movflags', '+faststart',
            '-c:a', 'aac', '-b:a', "{$audioBitrate}k", '-ar', '44100',
            $output,
        ];

        $output_str = $this->runCommand($cmd, $exitCode, $timeout);

        if ($exitCode !== 0) {
            throw new \RuntimeException("ffmpeg MP4 encoding failed (exit {$exitCode}): {$output_str}");
        }
    }

    private function extractPoster(string $input, string $output, int $timeout): void
    {
        $ffmpeg = $this->ffmpegBinary();

        $cmd = [$ffmpeg, '-y', '-i', $input, '-ss', '00:00:01', '-frames:v', '1', '-q:v', '3', $output];
        $outputStr = $this->runCommand($cmd, $exitCode, $timeout);

        if ($exitCode !== 0 || ! file_exists($output)) {
            // Non-fatal: try frame at 0s as fallback
            $cmd2 = [$ffmpeg, '-y', '-i', $input, '-frames:v', '1', '-q:v', '3', $output];
            $this->runCommand($cmd2, $exitCode2, $timeout);

            if ($exitCode2 !== 0 || ! file_exists($output)) {
                Log::warning('VideoVariantService: could not extract poster frame; writing placeholder JPEG.', [
                    'error' => $outputStr,
                ]);

                if (! $this->writePlaceholderJpeg($output)) {
                    throw new \RuntimeException('Could not extract poster frame and GD is unavailable to generate a placeholder.');
                }
            }
        }
    }

    /**
     * Write a minimal 1×1 black JPEG to $path as a last-resort poster placeholder.
     * Returns false if the GD extension is not loaded.
     */
    private function writePlaceholderJpeg(string $path): bool
    {
        if (! function_exists('imagecreatetruecolor')) {
            return false;
        }

        $img = imagecreatetruecolor(1, 1);
        if ($img === false) {
            return false;
        }

        $written = imagejpeg($img, $path, 85);
        imagedestroy($img);

        return $written;
    }

    private function extractDurationMs(array $probe): int
    {
        $duration = $probe['format']['duration'] ?? null;
        if ($duration === null) {
            // Fall back to the first video stream
            foreach ($probe['streams'] ?? [] as $stream) {
                if (($stream['codec_type'] ?? '') === 'video' && isset($stream['duration'])) {
                    $duration = $stream['duration'];
                    break;
                }
            }
        }

        return $duration !== null ? (int) round((float) $duration * 1000) : 0;
    }

    private function makeTmpFile(string $prefix, string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if (! $path) {
            throw new \RuntimeException("Failed to create temp file ({$prefix}).");
        }

        // Rename to add the desired extension so FFmpeg knows the container.
        $withExt = $path.$suffix;
        rename($path, $withExt);

        return $withExt;
    }

    /**
     * Run an FFmpeg/FFprobe command via Symfony Process and return combined stdout+stderr.
     * $exitCode is set by reference.
     *
     * Uses array-form Process — each element is passed as a literal argument,
     * bypassing the shell entirely and eliminating injection via argument composition.
     */
    private function runCommand(array $cmd, ?int &$exitCode = null, int $timeout = 30): string
    {
        $process = new Process($cmd);
        $process->setTimeout($timeout);
        $process->run();

        $exitCode = $process->getExitCode() ?? 0;

        return $process->getOutput().$process->getErrorOutput();
    }

    /**
     * Legacy jobs may pass the original file path instead of the media directory.
     * Normalize to the directory prefix expected by allFiles().
     */
    private function normalizeVideoCleanupBasePath(string $basePath): string
    {
        $normalized = trim($basePath);
        if ($normalized === '') {
            throw new \RuntimeException('Video cleanup base path cannot be empty.');
        }

        $leaf = basename($normalized);
        if (str_contains($leaf, '.')) {
            $normalized = dirname($normalized);
        }

        $normalized = trim($normalized, '/');
        if ($normalized === '' || $normalized === '.') {
            throw new \RuntimeException('Video cleanup base path resolved to an invalid directory.');
        }

        return $normalized;
    }

    private function diskName(): string
    {
        return MediaDiskResolver::resolve();
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    private function ffmpegBinary(): string
    {
        return (string) config('partna.ffmpeg_binary', 'ffmpeg');
    }

    private function ffprobeBinary(): string
    {
        return (string) config('partna.ffprobe_binary', 'ffprobe');
    }
}
