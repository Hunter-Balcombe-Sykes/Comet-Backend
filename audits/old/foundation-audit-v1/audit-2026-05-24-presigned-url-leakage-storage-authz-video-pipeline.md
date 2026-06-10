Now I have enough to adjudicate. Key findings from tool inspection:

- **MEDIA-1**: Confirmed — `storeOriginal()` is called in the controller before the async processing job, so arbitrary-extension files land on R2 before any MIME validation. However, R2/S3 objects can't execute PHP; RCE is not possible. Real risk is HTML/SVG phishing from the public bucket domain → **retier P0→P1**.
- **MEDIA-2**: The controller uses `$this->mediaService->safeExtension()` for video uploads too (line 165 of the controller). The video extensions in `ALLOWED_EXTENSIONS` are intentional — the same `safeExtension()` call serves both image and video original storage paths. **Drop MEDIA-2**.
- **MEDIA-3**: All active call sites pass the original path (AccountDeletionService uses `$media->path ?: null`, which is null only for media with no stored file — correct behavior). The risk is future callers forgetting the optional parameter. **Retier P1→P2**.
- **MEDIA-4**: Confirmed — throw on first file failure leaves DB rows intact. Even on retry, `allFiles()` recovers remaining files but DB rows are never cleaned. **Keep P1**.
- **MEDIA-5**: Public ACL is by design — this is a public-profile platform. The finding itself notes "may be intentional." **Drop**.
- **MEDIA-6**: Confidence 0.6, theoretical on container-isolated workers. **Drop**.
- **MEDIA-7**: Confidence 0.5, Laravel production mode already suppresses `$_ENV`. **Drop**.

`★ Insight ─────────────────────────────────────`
The image upload pipeline has an interesting defense-in-depth gap: `storeOriginal()` → R2 write → async job → `loadImage()` MIME check. The check is architecturally correct for preventing *processing* of bad files, but the original is already persisted in public storage before validation fires. This is a common pattern antipattern in async media pipelines — the sync boundary (HTTP response) and the validation boundary (job) are misaligned.
`─────────────────────────────────────────────────`

# Media Pipeline Audit — 2026-05-24

**Branch:** development
**Lens:** presigned URL leakage, storage authz, video pipeline integrity, orphaned media, MIME validation
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Media/ImageVariantService.php
- app/Services/Media/VideoVariantService.php
- app/Services/Media/MediaDiskResolver.php
- app/Services/Media/UnprocessableImageException.php
- app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php
- app/Jobs/ProcessImageVariantsJob.php
- app/Jobs/DeleteMediaArtifactsJob.php
- app/Services/Professional/AccountDeletionService.php
- app/Services/Streaming/KickApiClient.php
- app/Services/Streaming/LiveStatusInjector.php
- app/Services/Streaming/LiveStatusPoller.php
- app/Services/Streaming/StreamingTokenManager.php
- app/Services/Streaming/TwitchApiClient.php
- app/Jobs/Streaming/CheckStreamingLiveStatusJob.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#MEDIA-1** · P1 — `storeOriginal()` persists arbitrary file types to the public media bucket with no MIME validation
    - **Where:** app/Services/Media/ImageVariantService.php:219–227
    - **Affects:** Any user who can upload images — an attacker can store HTML, SVG, XML, or any file on the public R2 bucket using a spoofed extension (`shell.html` renamed to `shell.jpg`). The bucket's public-read ACL makes the stored object immediately accessible at a predictable URL, enabling phishing pages, stored XSS via SVG, and content injection under the Partna media domain.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `finfo(FILEINFO_MIME_TYPE)` byte-sniff at the top of `storeOriginal()` that rejects anything not in `ALLOWED_IMAGE_MIMES`.
        - Throw `UnprocessableImageException` on rejection (consistent with `loadImage()`'s existing pattern).
        - This check must run before `$this->disk()->put(...)` — not after.
    - **Technical:** The upload controller calls `$this->mediaService->storeOriginal($file, $basePath)` synchronously in the HTTP request and then dispatches `ProcessImageVariantsJob` asynchronously. The MIME sniff in `loadImage()` only fires inside the job, after the file is already on R2. `storeOriginal()` currently only passes the client-supplied extension through `safeExtension()`, which is a string allowlist — trivially bypassed by renaming any file. Because R2/S3 serves objects as static files (not executed), RCE on the server is not possible; however, HTML and SVG files are served with the content-type implied by the extension, enabling stored XSS and phishing pages hosted under the Partna media domain. If variant processing fails (e.g., `UnprocessableImageException` thrown in the job), the `failed()` handler cleans up via `cleanupR2Artifacts()` — but that cleanup path is also the only teardown, meaning the window between upload and job failure leaves the object live.
    - **Plain English:** Imagine a photo drop-box that checks whether someone's ID says "photographer" (the file extension) but never looks at what's actually inside the envelope. Right now, anyone can slide a fake business card through that slot — renamed as a `.jpg` but actually an HTML page — and it lands in the public gallery where anyone on the internet can access it. Since the gallery is on the Partna domain, a phishing page or a script-injecting SVG stored there looks like it came from Partna itself.
    - **Evidence:**
        ```php
        public function storeOriginal(UploadedFile $file, string $basePath): string
        {
            $ext = $this->safeExtension($file->getClientOriginalExtension() ?? '', 'jpg');
            $hash = substr(hash_file('sha256', $file->getRealPath()), 0, 16);
            $path = "{$basePath}/original_{$hash}.{$ext}";

            $this->disk()->put($path, file_get_contents($file->getRealPath()), 'public');

            return $path;
        }
        ```
        The validation that exists fires only later, inside the async job:
        ```php
        // In loadImage() — called by ProcessImageVariantsJob, not by storeOriginal()
        $actualMime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($actualMime, self::ALLOWED_IMAGE_MIMES, true)) {
            throw new UnprocessableImageException(
                "Rejected: MIME type '{$actualMime}' is not an accepted image format."
            );
        }
        ```

- [ ] **#MEDIA-4** · P1 — `VideoVariantService::deleteVariants()` aborts on the first storage failure, permanently orphaning DB rows
    - **Where:** app/Services/Media/VideoVariantService.php:338–360
    - **Affects:** Data integrity for any video deletion that hits a transient R2/S3 error — `MediaVariant` DB rows are never cleaned up because the delete call comes after the file loop, which never completes. The rows remain visible to consumers but their storage paths reference non-existent or partially-deleted files.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Switch to a best-effort file deletion loop: collect failures into an array, log each, and continue to the next file rather than throwing.
        - Move `MediaVariant::where('media_id', $mediaId)->delete()` to run unconditionally after the loop (DB cleanup should not be gated on 100% storage success).
        - After the loop, if any file deletions failed, throw a summarising exception so the job can be retried for storage cleanup only — with DB already clean.
    - **Technical:** The file-deletion loop throws `\RuntimeException` on the first failure — either a caught `\Throwable` or a `false` return from `$disk->delete()`. The `MediaVariant::where('media_id', $mediaId)->delete()` call is positioned *after* this loop with the comment "Delete DB rows only after storage cleanup succeeds." This means a single transient R2 error leaves: (a) some files deleted from storage, (b) remaining files still on disk, and (c) all DB rows intact pointing at a mix of live and gone paths. Since `DeleteMediaArtifactsJob` uses the DB rows as the authoritative list for cleanup, partial failure produces an inconsistent state that no retry can fully resolve — the already-deleted files won't be re-listed by `allFiles()` on retry, but the DB rows for them still exist.
    - **Plain English:** When the system deletes a video, it goes through the storage files one by one. If any single file throws an error — even a temporary network hiccup — the whole operation stops immediately and walks away. The database entries for that video are left completely intact, meaning the app still thinks all those video files exist. Even a retry can't fully fix this because some files were already quietly deleted while the records weren't. It's like a bookkeeper who stops mid-audit and leaves the ledger in an inconsistent state.
    - **Evidence:**
        ```php
        foreach ($files as $file) {
            try {
                $deleted = $disk->delete($file);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "Failed to delete video artifact [{$file}].",
                    0,
                    $e
                );
            }

            if ($deleted === false) {
                throw new \RuntimeException("Failed to delete video artifact [{$file}].");
            }
        }

        // Delete DB rows only after storage cleanup succeeds.
        MediaVariant::where('media_id', $mediaId)->delete();
        ```

---

## P2 — Should fix

- [ ] **#MEDIA-3** · P2 — `ImageVariantService::deleteVariants()` silently skips the original file when `$originalPath` is null
    - **Where:** app/Services/Media/ImageVariantService.php:233–248
    - **Affects:** Storage hygiene — any new call site that omits `$originalPath` will clean DB rows and variant files but leave the original upload on R2 indefinitely.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit and harden the method signature: if every real caller always has an original path (they currently do), make `$originalPath` a required `string` parameter with an explicit empty-string guard instead of `?string = null`.
        - Alternatively, derive the original path from the `SiteMedia` row inside the method so callers never need to pass it explicitly.
    - **Technical:** Current call sites (`ProfessionalUploadController`, `ProfessionalGalleryController`, `ProcessImageVariantsJob::cleanupR2Artifacts()`) all pass the original path correctly. However, `AccountDeletionService::purgeImageArtifacts()` uses `$media->path ?: null`, which silently passes `null` for any media row with an empty `path` string — the intended safe case for media stuck in `PENDING` before storage. The optional-parameter pattern creates a latent trap: future call sites (observers, admin tools, batch cleaners) may omit the argument without realising they're silently skipping the most storage-expensive artifact. The GD-generated original is typically the largest object in the bucket by file size.
    - **Plain English:** The "delete this image" function has an optional input for the location of the original full-resolution file. If a caller doesn't pass it — easy to forget since the function still works without it — all the smaller thumbnail versions get cleaned up but the original file stays on the storage bucket forever, accumulating quietly. It's like a demolition crew that removes all the furniture from a house but forgets to knock down the walls because the address was listed as optional on the work order.
    - **Evidence:**
        ```php
        public function deleteVariants(string $imageId, ?string $originalPath = null): void
        {
            // ... deletes variant DB rows and files ...

            // Also remove the original if a path was provided
            if ($originalPath && $disk->exists($originalPath)) {
                $disk->delete($originalPath);
            }
        }
        ```
        ```php
        // AccountDeletionService — passes null for empty-path media
        app(ImageVariantService::class)->deleteVariants($media->id, $media->path ?: null);
        ```

- [ ] **#MEDIA-2** · P2 — `storeOriginal()` loads the entire file into memory via `file_get_contents()`, making large uploads a memory-exhaustion vector on the worker
    - **Where:** app/Services/Media/ImageVariantService.php:225
    - **Affects:** PHP worker stability — a user uploading a large image (multi-megabyte raw JPEG or PNG) causes a full in-memory copy of the file payload in addition to the GD bitmap allocation during processing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `file_get_contents($file->getRealPath())` with `fopen($file->getRealPath(), 'rb')` and pass the stream handle to `$this->disk()->put()`. Laravel's Flysystem adapter accepts stream resources and will stream the upload to R2 without buffering the entire file in PHP memory.
        - Mirror the pattern already used in `VideoVariantService` (line ~198: `$stream = fopen($mp4, 'rb'); $disk->put($remotePath, $stream, 'public');`).
    - **Technical:** `Storage::disk()->put($path, string $contents, ...)` sends the entire file as a string argument. PHP must hold the full payload in memory for the duration of the S3 `PutObject` call. For a 20 MB raw JPEG — plausible from a modern phone — combined with the GD bitmap (~4 bytes/pixel × 24 MP = ~96 MB) this pushes a single request well into the hundreds of megabytes. The video path already uses stream-based upload correctly (`fopen` → `$disk->put($remotePath, $stream, 'public')`). The inconsistency is a direct fix target.
    - **Plain English:** When a user uploads a photo, the backend currently reads the entire file into its working memory before sending it to cloud storage — like printing out a full document just to fax it, rather than feeding the original through the fax machine. Large photos from modern phones can be tens of megabytes, and holding the whole thing in memory at once puts pressure on the server. The video uploader already does this the right way; the image uploader just needs the same treatment.
    - **Evidence:**
        ```php
        $this->disk()->put($path, file_get_contents($file->getRealPath()), 'public');
        ```
        Versus the correct streaming pattern already used in the same codebase (VideoVariantService):
        ```php
        $stream = fopen($mp4, 'rb');
        $disk->put($remotePath, $stream, 'public');
        if (is_resource($stream)) {
            fclose($stream);
        }
        ```
