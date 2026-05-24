`★ Insight ─────────────────────────────────────`
**On silent-fail operational hazards:** The `configured` guard in both Cloudflare services correctly no-ops for local dev, but it has no environment awareness. In a 12-factor app, this creates an asymmetry: the developer gets a clear local experience, but a production misconfiguration (typo'd env var, missing secret rotation) produces zero error signal. The fix isn't to remove the guard — it's to make the guard environment-aware (`app()->isProduction()`) or add a deploy-time health check that asserts config completeness.
`─────────────────────────────────────────────────`

Based on verification:

- **R2-1** (video predictable paths): Videos are uploaded with `processing_state` (`pending → processing → ready`) meaning they live in the public bucket from the moment they're stored, even before processing completes. On a public-profile builder the final *ready* content is public-by-design, but in the `pending`/`processing` window the artifact exists at a guessable path with no auth gate. However, a mediaId UUID must first be known — and that UUID is only exposed via authenticated API endpoints. The enumeration path requires an auth bypass, making this P3 (defense-in-depth / consistency with the image service's hashing approach).

- **R2-2** (originals stored public): The filename is content-hashed identically to image variants. No meaningful security delta over an already-public variant — a leaked variant hash doesn't expose the original hash. Drop.

- **R2-3** (silent no-op on misconfigured Cloudflare): Evidence verified verbatim. Exactly 0.7 confidence, but the failure mode is real and production-impactful (subdomain routing table silently stops updating → new handles never write to KV → Cloudflare Worker can't route traffic). Retained at P2.

---

# Cloudflare Worker Signature Verification, R2 URL Leakage & Public Bucket Scope Audit — 2026-05-24

**Branch:** development
**Lens:** Cloudflare worker signature verification, R2 presigned URL leakage, public bucket scope
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Cloudflare/CloudflareKvService.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Media/ImageVariantService.php
- app/Services/Media/VideoVariantService.php
- app/Services/Media/MediaDiskResolver.php
- app/Services/Media/UnprocessableImageException.php
- app/Services/Media/PlaceholderLimitExceededException.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#CF-1** · P2 — Cloudflare KV and Purge services silently no-op in production when misconfigured
    - **Where:** app/Services/Cloudflare/CloudflareKvService.php:55–58; app/Services/Cloudflare/CloudflarePurgeService.php:46–49
    - **Affects:** Production deployments where any Cloudflare config key is absent or misspelled — new user handles are silently never written to the KV routing table, and the edge cache is silently never purged after profile updates.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an `app()->isProduction()` (or `app()->environment('production', 'staging')`) guard inside the `! $this->configured` branch: throw a `\RuntimeException` (or `Log::critical`) instead of returning silently.
        - Add a `php artisan config:validate` step (or Horizon/deploy boot check) that asserts `services.cloudflare.account_id`, `kv_namespace_id`, `api_token`, `zone_id`, and `cache_purge_token` are all non-empty in production.
        - Keep the silent no-op for `local` and `testing` environments — that behaviour is correct.
    - **Technical:** Both services gate every operation on `if (! $this->configured) { Log::debug(...); return; }` with no awareness of the current environment. The code comment explicitly describes this as "local dev without CF credentials" — but the guard fires equally in production if any env var is absent, misspelled, or dropped during a secret rotation. When `CloudflareKvService::put()` no-ops, `SyncSubdomainToKvJob` completes successfully (no exception), the job is marked done, and the Cloudflare Worker never receives the new routing entry. The failure is undetectable until a user notices their subdomain doesn't resolve. Same for `CloudflarePurgeService::purgeUrls()` — cached stale profile pages stay cached at the edge indefinitely with no error logged above `debug` level.
    - **Plain English:** Imagine a postal sorting machine that, when it runs out of labels, keeps accepting packages and marking each one "shipped" — without actually attaching an address. Packages pile up, no one is notified, and customers never receive their deliveries. These two Cloudflare services behave the same way: if their credentials are missing for any reason in production, they accept work, quietly do nothing, and report success. The only symptom is that new profile pages don't appear at their web address, or old pages show stale content — and there's no alarm.
    - **Evidence:**
        ```php
        // CloudflareKvService.php
        if (! $this->configured) {
            Log::debug('CloudflareKvService: skipping put (not configured)', ['key' => $key]);

            return;
        }
        ```
        ```php
        // CloudflarePurgeService.php
        if (! $this->configured) {
            Log::debug('CloudflarePurgeService: skipping purge (not configured)', ['url_count' => count($urls)]);

            return;
        }
        ```

---

## P3 — Nice to have

- [ ] **#R2-1** · P3 — Video artifacts use predictable paths while image variants use content-hashed filenames
    - **Where:** app/Services/Media/VideoVariantService.php (upload section, step 7); app/Services/Media/ImageVariantService.php:processVariants
    - **Affects:** Defense-in-depth for video content stored in the public R2 bucket — paths like `videos/{proId}/{mediaId}/optimized.mp4` are fully deterministic once a `mediaId` UUID is known, unlike image variants which carry a 16-hex-char content hash.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - After encoding each MP4, compute `substr(hash_file('sha256', $tmpMp4), 0, 16)` and incorporate it into the remote path — e.g. `{$variantKey}_{$hash}.mp4`.
        - Apply the same hash-per-variant approach to the HLS directory name and playlist file — e.g. `hls/{$variantKey}_{$hash}/playlist.m3u8`.
        - Update `adaptive.m3u8` and `MediaVariant` DB rows to reference the hashed paths.
        - Apply the same approach to `poster.jpg` → `poster_{$hash}.jpg`.
    - **Technical:** `ImageVariantService` generates `optimized_abc123def456.webp` by hashing the encoded temp file. If a `mediaId` UUID is obtained, guessing an image URL still requires brute-forcing 2⁶⁴ combinations. `VideoVariantService` skips this: `$remotePath = "{$basePath}/{$variantKey}.mp4"` is fully deterministic. Videos also spend time in the `pending` and `processing` states while artifacts are already uploaded to the public bucket — in that window, a guessable path + a known `mediaId` (visible in authenticated API responses) is sufficient to fetch an unprocessed artifact. The hashing approach is already built and understood from the image service; porting it to the video service is a mechanical lift.
    - **Plain English:** Your image storage system gives every photo a random-looking code in its web address, so even if someone learns a photo's ID number they still can't guess the full address. Your video storage skips that step — the address is entirely predictable from the ID number alone. This isn't an immediate crisis (someone still needs the ID number first, and those are only handed out to logged-in users), but it's a consistency gap worth closing to match the security model already established for images.
    - **Evidence:**
        ```php
        // ImageVariantService.php — content-hashed filename
        $hash = hash_file('sha256', $tmpFile);
        $hash = substr($hash, 0, 16);
        $storagePath = "{$basePath}/{$variantName}_{$hash}.webp";
        ```
        ```php
        // VideoVariantService.php — fixed, predictable filename
        $remotePath = "{$basePath}/{$variantKey}.mp4";
        $stream = fopen($mp4, 'rb');
        $disk->put($remotePath, $stream, 'public');
        ```

- [ ] **#R2-2** · P3 — Original uploads stored publicly with no documentation of the intent
    - **Where:** app/Services/Media/ImageVariantService.php:storeOriginal (~line 191)
    - **Affects:** Users who upload high-resolution source files — the originals land in the public R2 bucket at a content-hashed path alongside processed variants.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If originals are disaster-recovery only and never served to end users, change `'public'` to `'private'` in the `storeOriginal` call and update `deleteVariants` to handle private-disk deletes.
        - If originals are intentionally public (e.g. "download original" feature), add a short comment to `storeOriginal` documenting that decision so future developers don't silently change it.
    - **Technical:** `$this->disk()->put($path, file_get_contents($file->getRealPath()), 'public')` stores the original at `{$basePath}/original_{$hash}.{$ext}` with public ACL. The docblock says originals are kept for "disaster-recovery / re-processing" — a purpose that does not require public read access. The content hash makes the path unguessable in isolation, so there is no active exposure path; however, if a variant hash and the original hash were ever correlated (they are computed from different files so are independent), the original would be equally reachable. The fix or the comment takes under an hour.
    - **Plain English:** When you upload a photo, the system saves both the finished, resized version *and* your full original file to the same publicly-accessible storage. The original gets a scrambled address (so strangers can't stumble onto it), but the code comment says originals are only kept as a backup copy — which normally wouldn't need to be publicly readable at all. Either change it to a private backup, or leave a note in the code explaining why public access is intentional, so no one changes it accidentally in the future.
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
