`★ Insight ─────────────────────────────────────`
- Both `ProcessVideoVariantsJob::failed()` and `ProcessImageVariantsJob::failed()` implement `cleanupR2Artifacts()` hooks — this completely invalidates DeepSeek MEDIA-1's "no automated recovery" claim. Reading job files before accepting "missing cleanup" findings is essential adjudication discipline.
- Flysystem v3's S3 adapter throws `UnableToDeleteFile` on failure (void return, no `false`) — this changes the data-consistency analysis for `ImageVariantService::deleteVariants()` vs `VideoVariantService::deleteVariants()` where the orphan risk profile is different.
- The `SiteMedia::forceDeleting` hook acts as a 30-day last-resort cleaner even when `cleanupR2Artifacts()` partially fails — the pipeline has more defense-in-depth than the draft scan detected.
`─────────────────────────────────────────────────`

Now I have all the verification I need. DeepSeek MEDIA-1 is dropped (the `failed()` hook with `cleanupR2Artifacts()` exists in both job files — the "no automated recovery" claim is factually wrong). MEDIA-2 through MEDIA-4 survive with evidence confirmed verbatim. Final document follows.

---

# Media Pipeline Audit — 2026-05-25

**Branch:** development
**Lens:** presigned URL leakage, storage authz, video pipeline integrity, orphaned media, MIME validation
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Media/ImageVariantService.php
- app/Services/Media/VideoVariantService.php
- app/Services/Media/MediaUploadService.php
- app/Services/Media/MediaDiskResolver.php
- app/Models/Core/MediaVariant.php
- app/Models/Core/Site/SiteMedia.php
- app/Jobs/ProcessImageVariantsJob.php
- app/Jobs/ProcessVideoVariantsJob.php
- app/Jobs/DeleteMediaArtifactsJob.php
- app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php
- app/Http/Resources/GalleryImageResource.php
- app/Services/Streaming/KickApiClient.php
- app/Services/Streaming/LiveStatusInjector.php
- app/Services/Streaming/LiveStatusPoller.php
- app/Services/Streaming/StreamingTokenManager.php
- app/Services/Streaming/TwitchApiClient.php
- app/Jobs/Streaming/CheckStreamingLiveStatusJob.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

> **Adjudicator note — draft MEDIA-1 dropped:** The draft claimed `VideoVariantService::processVariants()` had "no automated recovery" for partial R2 uploads. After reading `ProcessVideoVariantsJob.php`, `failed()` (lines 195–213) explicitly calls `cleanupR2Artifacts()` → `VideoVariantService::deleteVariants()`, which uses `allFiles($basePrefix)` to enumerate and delete every R2 object under the media directory. The content-hash naming strategy makes retry attempts idempotent (same source → same paths → R2 put overwrites partial artifacts). The claim is factually wrong; dropping.

---

## P2 — Should fix

- [ ] **#MEDIA-1** · P2 — `VideoVariantService::deleteVariants` unconditionally wipes DB rows even when R2 storage deletions fail, creating untracked orphaned objects with no automated reconciliation
    - **Where:** app/Services/Media/VideoVariantService.php:309–354 (`deleteVariants`) and app/Services/Media/ImageVariantService.php:196–210 (`deleteVariants`)
    - **Affects:** All users who delete video media, and any automated cleanup path (`DeleteMediaArtifactsJob`, `ProcessVideoVariantsJob::failed()`). Over months, transient R2 blips during deletion accumulate unreferenced storage objects that are invisible to the application and invisible to any cleanup pass.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - For `VideoVariantService::deleteVariants()`: the unconditional DB delete is an intentional design decision (noted in its own docblock). Add a scheduled audit command (`media:reconcile-orphans`) that pages through `MediaVariant`-less media directories on R2 by comparing `allFiles("videos/")` prefixes against `SiteMedia` UUIDs, logging any prefix that has no matching DB row.
        - For `ImageVariantService::deleteVariants()`: wrap `$disk->delete()` in a `try/catch`, log failures at `error` level (so Nightwatch surfaces them), and skip `$variant->delete()` for any path where storage delete threw — preserving DB row as a cleanup record.
        - On both code paths, the `SiteMedia::forceDeleting` hook (30-day hard-delete) acts as a last-resort cleaner; document this explicitly in both `deleteVariants` docblocks so the next engineer understands the full chain.
    - **Technical:** `VideoVariantService::deleteVariants()` explicitly documents its design: "Best-effort on storage, unconditional on DB: the DB row is the user-facing 'this media exists' flag, so we always scrub it — any orphaned storage files become an out-of-band ops concern, logged at error level so Nightwatch surfaces them." The log fires on failure but there is no automated path that acts on those Nightwatch alerts. `ImageVariantService::deleteVariants()` has no try/catch at all: in Flysystem v3, the S3 adapter's `delete()` is `void` and throws `UnableToDeleteFile` on failure, which aborts the loop mid-iteration (preserving consistency for unprocessed variants) but exits silently to the caller with no error logged. Both services are called from `DeleteMediaArtifactsJob`, `ProcessVideoVariantsJob::failed()`, `ProcessImageVariantsJob::cleanupR2Artifacts()`, and `ProfessionalUploadController::destroy()`.
    - **Plain English:** When a video is deleted, the app removes the database record immediately regardless of whether the actual files were successfully removed from cloud storage. If cloud storage hiccups at the moment of deletion, the app "forgets" about the files while they continue to quietly exist — and accumulate costs — on storage. There's no janitor scheduled to go back and check. The fix is to either hold off on erasing the database record until storage confirms success, or run a periodic reconciliation pass that finds files storage kept that the database forgot about.
    - **Evidence:**
        ```php
        // VideoVariantService::deleteVariants — DB delete runs unconditionally after best-effort storage pass:

        // Deferred-unconditional: runs even if listing threw or per-file deletes failed.
        MediaVariant::where('media_id', $mediaId)->delete();

        if ($failures !== []) {
            Log::error('VideoVariantService::deleteVariants: storage delete failures; DB rows cleared, orphans may remain.', [
                'media_id' => $mediaId,
                'base_prefix' => $basePrefix,
                'failure_count' => count($failures),
                'failures' => array_slice($failures, 0, 20),
            ]);
        }
        ```
        ```php
        // ImageVariantService::deleteVariants — no error handling on disk delete, no logging:
        foreach ($variants as $variant) {
            $disk->delete($variant->path);
            $variant->delete();
        }
        ```

- [ ] **#MEDIA-2** · P2 — `dispatchImageJob` total-failure silently leaves SiteMedia in `PENDING` with no `failed()` hook path to invoke `markFailed()`
    - **Where:** app/Services/Media/MediaUploadService.php:277–317 (`dispatchImageJob`)
    - **Affects:** Any user whose image upload hits the exact intersection of queue dispatch failure (Redis unavailable) and sync fallback failure (GD exception or identical worker-side error). The SiteMedia row stays `PENDING` indefinitely; the original file sits on R2 with `private` ACL consuming storage; no Nightwatch alert is wired to stuck-PENDING rows; the `ProcessImageVariantsJob::failed()` hook — which would call `markFailed()` and `cleanupR2Artifacts()` — is never invoked because the job was never enqueued.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the outermost catch block (after both queue dispatch and sync fallback fail), call `SiteMedia::query()->where('id', $imageId)->whereNull('deleted_at')->update(['processing_state' => SiteMedia::PROCESSING_STATE_FAILED, 'processing_error' => 'Image processing unavailable; please try again.'])`.
        - Also attempt to delete the original file from R2 (mirror the `rollbackFailedVideoDispatch` pattern): `Storage::disk($this->imageService->resolvedDiskName())->delete($originalPath)`.
        - Add a `Log::critical` call (not just `error`) so Nightwatch surfaces this as an alertable event — the sync fallback failing means the worker itself is unhealthy, not just the queue.
        - Consider a scheduled command that queries SiteMedia rows stuck in `pending` or `processing` for longer than the configured job timeout + grace period, logs them, and transitions to `failed`.
    - **Technical:** `dispatchImageJob` is documented as "best-effort and NEVER throws." This asymmetry with the video path is deliberate (`dispatchVideoJob` throws on failure and the caller invokes `rollbackFailedVideoDispatch()`). When the queue dispatch succeeds, `ProcessImageVariantsJob::failed()` (called after all three retries exhaust) correctly calls `markFailed()` and `cleanupR2Artifacts()`. But when `dispatch()` itself throws (Redis connection refused) and the sync fallback also throws, no job is ever enqueued, and the `failed()` hook never fires. The SiteMedia row stays `PENDING` with a committed original file on R2 and no recovery path unless a human notices the stuck row or a reconciliation cron is added.
    - **Plain English:** When someone uploads a photo, the app stores the file and then hands it to a background worker to process it. If that handoff completely fails — the worker queue is unavailable and the backup process also crashes — the app just logs a note and moves on, leaving the upload permanently stuck in a "processing…" state. The user sees an endless spinner and the file silently occupies storage forever. The video upload path handles this correctly (it cancels and tells the user to retry). The image path needs the same safety valve: if all fallbacks fail, tell the row it failed and clean up the file.
    - **Evidence:**
        ```php
        // dispatchImageJob — total failure path: no markFailed(), no storage cleanup
        try {
            ProcessImageVariantsJob::dispatch(
                originalPath: $originalPath,
                imageId: $imageId,
                basePath: $basePath,
            );
        } catch (Throwable $e) {
            Log::error('Queue dispatch failed for image; trying synchronous fallback.', [
                'image_id' => $imageId, 'error' => $e->getMessage(),
            ]);
            try {
                ProcessImageVariantsJob::dispatchSync(
                    originalPath: $originalPath,
                    imageId: $imageId,
                    basePath: $basePath,
                );
            } catch (Throwable $syncError) {
                Log::error('Synchronous image variant processing also failed.', [
                    'image_id' => $imageId, 'error' => $syncError->getMessage(),
                ]);
                // ← SiteMedia stays 'pending'. Original stays on R2. No markFailed(). No cleanup.
            }
        }
        ```
        ```php
        // Compare: video path THROWS on failure; caller invokes rollbackFailedVideoDispatch()
        /**
         * Video dispatch THROWS on failure — caller rolls back DB + storage and returns 503.
         */
        private function dispatchVideoJob(string $mediaId, string $originalPath, string $basePath): void
        ```

## P3 — Nice to have

- [ ] **#MEDIA-3** · P3 — `ALLOWED_EXTENSIONS` constant is defined on `ImageVariantService` but also gates video upload path extension normalisation, creating a hidden cross-service coupling
    - **Where:** app/Services/Media/ImageVariantService.php:27 (`ALLOWED_EXTENSIONS`) and app/Services/Media/MediaUploadService.php:213 (call site)
    - **Affects:** Developer ergonomics. A future engineer adding a new video container format (e.g., `.mkv`) would naturally update `VideoVariantService` and `config/partna.php` but miss the extension allowlist buried in an image-namespaced class.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract `ALLOWED_EXTENSIONS` (or separate `ALLOWED_IMAGE_EXTENSIONS` / `ALLOWED_VIDEO_EXTENSIONS`) to `MediaDiskResolver` or a new `MediaConstants` final class under `App\Services\Media`.
        - Update both `ImageVariantService::safeExtension()` and `MediaUploadService::storeOriginal()` to reference the shared constant.
        - Add a comment at the `safeExtension()` call site in `storeOriginal()` noting that the method is shared between image and video upload paths.
    - **Technical:** `ImageVariantService::safeExtension()` is called from `MediaUploadService::storeOriginal()` for video files: `$ext = $this->imageService->safeExtension($file->getClientOriginalExtension() ?? '', 'mp4')`. The allowlist `ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov', 'webm']` mixes image and video extensions on a class whose name signals it is image-only. The coupling is invisible to static analysis — a refactor splitting image and video services would silently remove the video extension gate with no type error or test failure. Note: `safeExtension()` governs storage path naming only; the security gate for videos is `probeAndValidate()` via ffprobe (codec, resolution, duration), which is unaffected.
    - **Plain English:** The list of allowed file extensions for both photos and videos is stored inside a file called "ImageVariantService" — like labelling the video archive keys on the photo-room keyring. It works fine today, but anyone reorganising the keyrings would naturally look at the video room's own drawer and never find the photo room's copy. Adding a new video format would quietly fail to be whitelisted, causing video uploads to silently get renamed to `.mp4` regardless of the original container.
    - **Evidence:**
        ```php
        // ImageVariantService.php:27 — video extensions co-located on an image-namespaced class:
        private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov', 'webm'];

        // MediaUploadService.php:213 — called for the *video* upload path:
        $ext = $this->imageService->safeExtension($file->getClientOriginalExtension() ?? '', 'mp4');
        ```
