`★ Insight ─────────────────────────────────────`
Three interesting patterns surfaced across this adjudication:
1. `VideoVariantService::processVariants` has **two more** `fopen` leak sites (lines 196, 222) beyond what DeepSeek scoped to `MediaUploadService` — they share the exact same root cause but run in a long-lived queue worker where handle exhaustion compounds across many jobs.
2. DeepSeek's fix for MEDIA-4 directly contradicts the codebase's own explicit design philosophy documented in `VideoVariantService::deleteVariants`'s docblock: "Best-effort on storage, unconditional on DB." The correct fix is to *add logging*, not change the DB-delete semantics.
3. `MediaVariant::getUrlAttribute` uses a CDN base-URL fast-path in production — so `$adapter->url()` (which could presign) only runs on misconfigured disks. No presigned URL leakage finding warranted.
`─────────────────────────────────────────────────`

# Media Pipeline Audit — 2026-05-31

**Branch:** development
**Lens:** Media/video pipeline, presigned URL leakage, storage authz, orphaned media after delete failures, MIME validation before public-bucket write, variant-generation idempotency
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Services/Media/MediaUploadService.php`
- `app/Services/Media/ImageVariantService.php`
- `app/Services/Media/VideoVariantService.php`
- `app/Services/Media/MediaDiskResolver.php`
- `app/Models/Core/MediaVariant.php`
- `app/Models/Core/Site/SiteMedia.php`
- `app/Jobs/ProcessImageVariantsJob.php`
- `app/Jobs/ProcessVideoVariantsJob.php`
- `app/Jobs/DeleteMediaArtifactsJob.php`
- `app/Http/Controllers/Api/User/Uploads/UserUploadController.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#MEDIA-4** · P2 — `ImageVariantService::deleteVariants` silently swallows per-file storage failures, orphaning files on R2
    - **Where:** `app/Services/Media/ImageVariantService.php:263-275` (`deleteVariants`)
    - **Affects:** Any image deletion where the R2 `delete()` call returns `false` or throws for a subset of variants. Files accumulate on the bucket with no application-level reference and no Nightwatch alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Mirror the existing `VideoVariantService::deleteVariants` pattern: collect per-file failures, log them at `error` level so Nightwatch surfaces them, and keep the unconditional DB-row delete.
        - Do **not** skip the DB delete on failure — the codebase's explicit design philosophy (documented in `VideoVariantService::deleteVariants`'s docblock: "Best-effort on storage, unconditional on DB") is that the DB row is the user-facing "this media exists" flag and must always be cleared; orphaned storage objects are an out-of-band ops concern surfaced via error logs.
    - **Technical:** The `foreach` loop calls `$disk->delete($variant->path)` without inspecting the return value or wrapping in try/catch, then unconditionally calls `$variant->delete()`. The DB row is always removed regardless of whether the storage operation succeeded. `VideoVariantService::deleteVariants` explicitly handles this asymmetry: it collects `$failures`, logs them at `error` level with path and error detail, and still clears all DB rows — this pattern is the correct template. The gap here is purely observability: orphaned R2 objects are invisible unless an ops engineer manually audits bucket contents.
    - **Plain English:** When you delete a photo, the app removes it from the catalog whether or not it successfully cleared the file from the storage shelf. If the shelf was temporarily unavailable, the catalog entry is gone but the physical file remains forever — no alarm is raised. The video pipeline handles this correctly by logging an error when cleanup fails; the photo pipeline needs the same safety net.
    - **Evidence:**
        ```php
        // ImageVariantService::deleteVariants — return value ignored, no catch, no logging
        foreach ($variants as $variant) {
            $disk->delete($variant->path);
            $variant->delete();
        }
        ```
        ```php
        // Contrast: VideoVariantService::deleteVariants — correct pattern
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
        ```

- [ ] **#MEDIA-2** · P2 — Image variant re-processing orphans old variant files when output content hash differs
    - **Where:** `app/Services/Media/ImageVariantService.php` (`processVariants` — encode → hash → upload → `updateOrCreate`)
    - **Affects:** Any image whose variants are re-processed after a `config/partna.php` image-quality or dimension change. Old WebP files accumulate on R2 with no DB pointer and no cleanup path.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Before calling `MediaVariant::updateOrCreate`, fetch the existing row's `path` for that `(media_id, variant_key, artifact_type)` tuple. If the stored path differs from the new content-hashed path, delete the old file from the disk after confirming the new one is stored.
        - The most likely real-world trigger is a `partna.image_variants` quality or target-size config change that causes re-processing to produce different output bytes and therefore a different hash. Fixing this before re-processing tooling is added avoids a storage-accounting surprise.
    - **Technical:** `processVariants` derives a storage path from a content hash (`$storagePath = "{$basePath}/{$variantName}_{$hash}.webp"`) and calls `MediaVariant::updateOrCreate` which updates the DB row to point at the new path. The old file at the previous hash-derived path is never deleted. Under current usage the content hash is deterministic for a given original (same bytes → same hash → same path → overwrite, no orphan), but the hash changes whenever variant encode parameters change. A quality or dimension config update followed by a re-process run would produce new paths for all affected images, silently accumulating the old WebP objects on R2.
    - **Plain English:** Every time a photo is re-processed (which happens automatically if you change how photos are saved), the system puts the new copy in a new spot on the shelf based on a fingerprint of the file's contents. It never throws away the old copy. After a config tweak touches thousands of images, you'd have double the photo files on the storage bill with only half of them actually in use.
    - **Evidence:**
        ```php
        // ImageVariantService::processVariants — new path computed, old path never deleted
        $hash = substr($hash, 0, 16);

        // Content-hashed filename: optimized_abc123def456.webp
        $storagePath = "{$basePath}/{$variantName}_{$hash}.webp";

        $payload = file_get_contents($tmpFile);
        if ($payload === false) {
            throw new \RuntimeException('Failed to read encoded WebP for upload.');
        }
        $disk->put($storagePath, $payload, 'public');

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
        ```

- [ ] **#MEDIA-3** · P2 — Video variant re-processing orphans old MP4 and poster files when output hash differs
    - **Where:** `app/Services/Media/VideoVariantService.php:192-231` (`processVariants` — MP4 upload loop + poster upload)
    - **Affects:** Any video re-processed after an FFmpeg version bump, bitrate config change, or resolution config change. Old MP4 and poster files on R2 are never cleaned up. Because video files are 50–200 MB each, two orphaned artifacts per re-processed video compound quickly.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Same fix as MEDIA-2: query the existing `MediaVariant` row for `(media_id, variant_key, artifact_type)` before each `updateOrCreate`, compare the stored `path` with the new content-hashed path, and delete the old remote file after confirming the new one is stored.
        - Apply to both the MP4 loop and the poster upload within the same method.
    - **Technical:** Structurally identical to the image variant issue. The video loop computes `{variantKey}_{hash}.mp4` paths and upserts `MediaVariant` rows; the poster does the same with `poster_{hash}.jpg`. If encode parameters change (e.g., `video_bitrate_kbps` in `config/partna.php`) and the job is re-run, new content-hashed paths are written to the DB while the old files remain on R2. At video file sizes, a single re-processing run over even a few dozen users could orphan gigabytes.
    - **Plain English:** Same shelf problem as the photo issue, but now we're talking about movie files — each one 50–200 MB. Forgetting to toss the old encoded copy after a quality config change is like keeping every previous cut of every film on the server. The storage bill climbs fast and there's no automated way to find or reclaim the dead files.
    - **Evidence:**
        ```php
        // VideoVariantService::processVariants — MP4 loop, old path never deleted
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
        ```

- [ ] **#MEDIA-1** · P2 — Video path leaks file handles when S3 `put()` throws — three sites
    - **Where:** `app/Services/Media/MediaUploadService.php:212-217` (video original upload); `app/Services/Media/VideoVariantService.php:196-199` (MP4 artifact upload); `app/Services/Media/VideoVariantService.php:222-225` (poster upload)
    - **Affects:** Video uploads and video variant processing jobs where the R2/S3 write fails (network blip, credential error, rate limit). Leaked file handles accumulate in the PHP-FPM worker (uploads) and in long-lived Horizon queue workers (video processing), eventually exhausting per-process descriptor limits under sustained error load.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap all three `fopen` / `Storage::put` call sites in `try/finally` blocks that close the stream unconditionally — mirror the pattern already used in `ImageVariantService::storeOriginal`.
        - Add a `$stream === false` guard before each `put()` call (also absent at all three video sites, present in the image path) so a `fopen` failure throws cleanly rather than passing `false` to Flysystem.
        - A single regression test that mocks the disk to throw on `put()` and asserts the handle is closed covers all three.
    - **Technical:** All three video-path call sites open a stream with `fopen`, pass it to Flysystem, then close it with a post-`put` `if (is_resource($stream)) { fclose($stream); }` guard. If `put()` throws, control leaves the `if` block and the handle stays open. The image path (`ImageVariantService::storeOriginal`) uses `try/finally` precisely to cover this failure mode, with a comment explaining the intent: "Flysystem-S3 closes the resource on success; the finally covers failure paths." `MediaUploadService` runs in PHP-FPM (request-scoped — handles are reclaimed at request end), but `VideoVariantService` runs inside a Horizon worker that processes many videos per lifetime; leaked handles accumulate across jobs until the worker is restarted.
    - **Plain English:** Three places in the video pipeline open a "pipe" to hand a file to cloud storage. If the cloud storage rejects the transfer, the code never closes the pipe. For photo uploads this is already handled correctly, but the video side was missed. A queue worker processing videos all day keeps accumulating these unclosed pipes until the operating system cuts it off — at which point the worker crashes and video processing stops entirely until it's restarted.
    - **Evidence:**
        ```php
        // MediaUploadService::storeOriginal — video branch (line 212): no try/finally
        $stream = fopen($file->getRealPath(), 'rb');
        // 'private' — original is a re-processing source only…
        Storage::disk($mediaDisk)->put($path, $stream, 'private');
        if (is_resource($stream)) {
            fclose($stream);
        }
        ```
        ```php
        // VideoVariantService::processVariants — MP4 loop (line 196): same gap
        $stream = fopen($mp4, 'rb');
        $disk->put($remotePath, $stream, 'public');
        if (is_resource($stream)) {
            fclose($stream);
        }
        ```
        ```php
        // VideoVariantService::processVariants — poster upload (line 222): same gap
        $stream = fopen($tmpPoster, 'rb');
        $disk->put($posterRemotePath, $stream, ['visibility' => 'public', 'ContentType' => 'image/jpeg']);
        if (is_resource($stream)) {
            fclose($stream);
        }
        ```
        ```php
        // Correct pattern — ImageVariantService::storeOriginal
        $stream = fopen($realPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open uploaded file for streaming.');
        }
        try {
            $this->disk()->put($path, $stream, 'private');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        ```

---

## P3 — Nice to have

- [ ] **#MEDIA-5** · P3 — Crash window between `storeOriginal` success and DB path update can leave an unreferenced original on R2
    - **Where:** `app/Services/Media/MediaUploadService.php` (inside `upload()`, between `storeOriginal` and `$media->update(['path' => $originalPath])`)
    - **Affects:** Any upload where the PHP-FPM worker is OOM-killed or SIGKILL'd after the R2 `put()` succeeds but before the `$media->update(...)` commits. The `SiteMedia` row persists with `path = ''`, the original file sits on R2 with no pointer.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - The pragmatic first step is logging: after `$media->update(['path' => $originalPath])`, emit a structured log entry with `media_id`, `path`, and a `checkpoint: path_written` tag — this gives ops a breadcrumb if a `path = ''` row turns up.
        - A more robust approach writes the `path` inside the same DB transaction as `createMediaRow` (as a provisional placeholder, updated once the R2 write is confirmed), but this requires restructuring the upload flow and is a larger change. Logging the checkpoint is a proportionate first pass.
    - **Technical:** `createMediaRow` commits the `SiteMedia` row inside a transaction with `path = ''`. `storeOriginal` then writes the file to R2 outside any transaction. `$media->update(['path' => $originalPath])` is a separate non-transactional write. A SIGKILL between the R2 write and the DB update leaves `path = ''` in the DB — the row appears in media lists but the file is unreachable through any app-level path. The `SiteMedia.path = ''` guard in other query paths would prevent serving it, but cleanup would require a manual ops pass to match the dangling R2 object.
    - **Plain English:** Writing a book to the shelf and updating the library catalog are two separate steps. If the power goes out between them, the book is on the shelf but the catalog still shows the slot as empty. You'd never find it through the app — it just quietly takes up storage space indefinitely. Adding a log entry after updating the catalog gives ops a searchable trail to catch this if it ever happens.
    - **Evidence:**
        ```php
        // MediaUploadService::upload() — storeOriginal outside transaction, separate update
        $media = $this->createMediaRow($site, $pool, $maxItems, $mediaType, $file, $altText, $caption);

        $basePath = $isVideo
            ? "videos/{$pro->id}/{$media->id}"
            : "images/{$pro->id}/{$media->id}";

        try {
            $originalPath = $this->storeOriginal($file, $basePath, $media->id, $isVideo);
        } catch (Throwable $e) {
            Log::error('Failed to store original', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
            $media->delete();

            throw new OriginalStoreFailedException('Failed to store file: '.$e->getMessage(), 0, $e);
        }

        $media->update(['path' => $originalPath]); // ← crash here = unreferenced file on R2
        ```
