<?php

namespace App\Services\Media;

use App\Models\Core\MediaVariant;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Transcodes a video original into MP4 + HLS variants and a poster image,
 * stores all artifacts on the media disk, and persists MediaVariant rows.
 *
 * Processing order (avoids extra re-encodes):
 *   1. FFprobe  – extract metadata + validate duration
 *   2. FFmpeg   – encode optimized MP4 (720p)
 *   3. FFmpeg   – encode maximized MP4 (1080p)
 *   4. FFmpeg   – package HLS segments from each MP4 (stream-copy, no re-encode)
 *   5. Build    – write master adaptive HLS playlist
 *   6. FFmpeg   – extract poster JPEG at 1s mark
 *   7. Upload   – stream all artifacts to media disk
 *   8. DB upsert – persist one MediaVariant row per logical artifact
 *   9. SiteMedia update – set processing_state, duration_ms, poster_path
 *
 * Storage layout under the media disk:
 *   videos/{proId}/{mediaId}/
 *     original_{hash}.{ext}
 *     optimized.mp4
 *     maximized.mp4
 *     hls/
 *       optimized/playlist.m3u8  + seg_*.ts
 *       maximized/playlist.m3u8  + seg_*.ts
 *       adaptive.m3u8             (master playlist)
 *     poster.jpg
 *
 * Requires ffmpeg and ffprobe to be available (configured via config/partna.php).
 */
// V2: Transcodes videos to MP4 + HLS via FFmpeg. Feature-flagged (PARTNA_VIDEO_UPLOADS_ENABLED). Uses dedicated redis_video connection.
class VideoVariantService
{
    /** Codecs we will transcode. hevc = H.265 (ffprobe reports 'hevc', not 'h265'). */
    private const ALLOWED_CODECS = ['h264', 'hevc', 'vp9'];

    /** 4K ceiling: long edge ≤ 3840px, short edge ≤ 2160px. */
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
        $tmpDirs = [];

        try {
            // --- 2 & 3. Encode MP4 variants ---
            $mp4Paths = [];
            foreach ($variantDefs as $variantKey => $def) {
                $tmpMp4 = $this->makeTmpFile("sidest_mp4_{$variantKey}_", '.mp4');
                $this->encodeMp4($localOriginalPath, $tmpMp4, $def, $encodingTimeout);
                $mp4Paths[$variantKey] = $tmpMp4;
            }

            // --- 4. Package HLS from each MP4 ---
            $hlsDirs = [];
            foreach ($mp4Paths as $variantKey => $mp4) {
                $tmpHlsDir = sys_get_temp_dir().'/sidest_hls_'.$variantKey.'_'.uniqid();
                if (! mkdir($tmpHlsDir, 0755, true)) {
                    throw new \RuntimeException("Failed to create HLS temp dir: {$tmpHlsDir}");
                }
                $tmpDirs[] = $tmpHlsDir;
                $this->packageHls($mp4, $tmpHlsDir.'/playlist.m3u8', $encodingTimeout);
                $hlsDirs[$variantKey] = $tmpHlsDir;
            }

            // --- 5. Build adaptive master playlist ---
            $adaptiveContent = $this->buildAdaptivePlaylist($variantDefs);

            // --- 6. Extract poster ---
            $tmpPoster = $this->makeTmpFile('sidest_poster_', '.jpg');
            $this->extractPoster($localOriginalPath, $tmpPoster, $encodingTimeout);

            // --- 7. Upload all artifacts ---
            $disk = $this->disk();
            $diskName = $this->resolvedDiskName();

            // Upload MP4s
            foreach ($mp4Paths as $variantKey => $mp4) {
                $remotePath = "{$basePath}/{$variantKey}.mp4";
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
                        'metadata' => ['resolution' => $def['resolution'] ?? null],
                    ]
                );
            }

            // Upload HLS segments + playlists
            foreach ($hlsDirs as $variantKey => $hlsDir) {
                $remoteHlsBase = "{$basePath}/hls/{$variantKey}";
                foreach (scandir($hlsDir) ?: [] as $file) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }
                    $localFile = "{$hlsDir}/{$file}";
                    $remotePath = "{$remoteHlsBase}/{$file}";
                    $mime = str_ends_with($file, '.m3u8') ? 'application/vnd.apple.mpegurl' : 'video/mp2t';
                    $stream = fopen($localFile, 'rb');
                    $disk->put($remotePath, $stream, ['visibility' => 'public', 'ContentType' => $mime]);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                $playlistPath = "{$remoteHlsBase}/playlist.m3u8";
                $def = $variantDefs[$variantKey] ?? [];
                MediaVariant::updateOrCreate(
                    ['media_id' => $mediaId, 'variant_key' => $variantKey, 'artifact_type' => 'hls_playlist'],
                    [
                        'disk' => $diskName,
                        'path' => $playlistPath,
                        'mime' => 'application/vnd.apple.mpegurl',
                        'duration_ms' => $durationMs,
                        'metadata' => ['resolution' => $def['resolution'] ?? null],
                    ]
                );
            }

            // Upload adaptive master playlist
            $adaptiveRemotePath = "{$basePath}/hls/adaptive.m3u8";
            $disk->put($adaptiveRemotePath, $adaptiveContent, [
                'visibility' => 'public',
                'ContentType' => 'application/vnd.apple.mpegurl',
            ]);

            MediaVariant::updateOrCreate(
                ['media_id' => $mediaId, 'variant_key' => 'adaptive', 'artifact_type' => 'hls_playlist'],
                [
                    'disk' => $diskName,
                    'path' => $adaptiveRemotePath,
                    'mime' => 'application/vnd.apple.mpegurl',
                ]
            );

            // Upload poster
            $posterRemotePath = "{$basePath}/poster.jpg";
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
                ]
            );

            // --- 8. Update SiteMedia ---
            SiteMedia::query()
                ->where('id', $mediaId)
                ->whereNull('deleted_at')
                ->update([
                    'processing_state' => SiteMedia::PROCESSING_STATE_READY,
                    'processing_error' => null,
                    'duration_ms' => $durationMs,
                    'poster_path' => $posterRemotePath,
                ]);

            Log::info('VideoVariantService: completed', [
                'media_id' => $mediaId,
                'duration_ms' => $durationMs,
            ]);
        } finally {
            // Cleanup temp files and dirs
            foreach ($mp4Paths ?? [] as $mp4) {
                if (file_exists($mp4)) {
                    @unlink($mp4);
                }
            }
            if (isset($tmpPoster) && file_exists($tmpPoster)) {
                @unlink($tmpPoster);
            }
            foreach ($tmpDirs as $dir) {
                $this->removeDir($dir);
            }
        }
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

    private function packageHls(string $mp4Input, string $playlistOutput, int $timeout): void
    {
        $ffmpeg = $this->ffmpegBinary();
        $outputDir = dirname($playlistOutput);
        $segmentPattern = $outputDir.'/seg_%03d.ts';

        $cmd = [
            $ffmpeg, '-y', '-i', $mp4Input,
            '-c', 'copy', '-f', 'hls',
            '-hls_time', '6', '-hls_playlist_type', 'vod',
            '-hls_segment_filename', $segmentPattern,
            $playlistOutput,
        ];

        $output = $this->runCommand($cmd, $exitCode, $timeout);

        if ($exitCode !== 0) {
            throw new \RuntimeException("ffmpeg HLS packaging failed (exit {$exitCode}): {$output}");
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

    /**
     * Build the adaptive (master) HLS playlist content.
     *
     * @param  array<string, array<string, mixed>>  $variantDefs
     */
    private function buildAdaptivePlaylist(array $variantDefs): string
    {
        $lines = ['#EXTM3U'];

        foreach ($variantDefs as $variantKey => $def) {
            // Guard against config values containing newlines or special chars
            // that would corrupt the line-based M3U8 format.
            if (! preg_match('/^[a-zA-Z0-9_-]+$/', (string) $variantKey)) {
                throw new \RuntimeException("Invalid video variant key: {$variantKey}");
            }

            $videoBitrate = (int) ($def['video_bitrate_kbps'] ?? 2000);
            $audioBitrate = (int) ($def['audio_bitrate_kbps'] ?? 128);
            $bandwidth = ($videoBitrate + $audioBitrate) * 1000;
            $resolution = strtoupper((string) ($def['resolution'] ?? '1280x720'));

            // Resolution must be WxH (digits only) — e.g. 1280x720, 1920X1080.
            if (! preg_match('/^\d+X\d+$/', $resolution)) {
                throw new \RuntimeException("Invalid video resolution for variant '{$variantKey}': {$resolution}");
            }

            $lines[] = "#EXT-X-STREAM-INF:BANDWIDTH={$bandwidth},RESOLUTION={$resolution}";
            $lines[] = "{$variantKey}/playlist.m3u8";
        }

        return implode("\n", $lines)."\n";
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

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = "{$dir}/{$file}";
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
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

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
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
