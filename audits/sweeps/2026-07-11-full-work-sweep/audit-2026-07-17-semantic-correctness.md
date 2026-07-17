# Semantic Correctness Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Semantic Correctness — code that compiles and type-checks but does the wrong thing (real-method-wrong-contract, config/flag misuse, plausible-but-wrong magic values, logic contradicting intent, codebase-idiom drift)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Services/Accounts/LifestyleConnectionCleanup.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/PublicSite/SiteActionsService.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/SiteProvisioningService.php
- app/Services/User/UserBootstrapService.php
- app/Support/BusinessName.php
- app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php
- app/Http/Controllers/Api/User/Account/UserSelfController.php
- app/Http/Controllers/Api/User/Profile/SectorController.php
- app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Policies/EarlyAccessSignupPolicy.php
- app/Policies/FeatureAvailabilityPolicy.php
- app/Policies/FeedbackPolicy.php
- app/Policies/UserSegmentPolicy.php
- app/Policies/UserSelfPolicy.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 1 complete

---

## P3 — Nice to have

- [ ] **SEM-1** · P3 — Instagram reel mirror leaks a file descriptor when the R2 `put()` call throws
    - **Where:** app/Jobs/Platforms/InstagramConnectJob.php:397-413
    - **Affects:** Horizon queue workers processing `InstagramConnectJob` during an R2/Storage outage or transient network fault — each failed reel mirror leaks one open file handle on the worker process.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `fopen`/`put` pair in its own `try/finally` (nested inside the existing outer `try`) so `fclose($stream)` always runs regardless of whether `Storage::disk('media')->put()` throws.
        - Alternatively, close the stream in the outer `catch` block before returning null, checking `is_resource($stream)` first (the variable may be undefined if `fopen` itself failed).
    - **Technical:** `mirrorVideo()` opens a read stream on the downloaded temp file (`fopen($tmp, 'r')`) and hands it to `Storage::disk('media')->put($path, $stream)`. The `fclose($stream)` call sits on the line immediately after `put()`, inside the same `try` block. If `put()` throws (R2 unreachable, network timeout, disk-full on the underlying adapter), execution jumps straight to the outer `catch (Throwable $e)`, skipping the `fclose()` call entirely. The `finally` block only does `@unlink($tmp)` — it never touches `$stream`. On a long-running Horizon worker this leaks one file descriptor per failed mirror; a sustained R2 outage across many Instagram-connect attempts would accumulate leaked descriptors until the worker hits the OS `ulimit`. This is a genuine, verified control-flow gap (confirmed by reading the exact code — not an assumption about library behavior), but the practical blast radius is small: Horizon workers recycle periodically and typical `ulimit -n` values are in the thousands, so this would need a sustained outage plus many connect attempts before it manifests as a crash.
    - **Plain English:** Imagine opening a filing-cabinet drawer to grab a folder, then someone yanks the whole cabinet away before you can close the drawer — you walk off, drawer still open. Do that enough times and the room fills with open drawers. This code opens a temporary file handle to upload an Instagram reel to cloud storage; if that upload fails, the handle never gets closed. It's a slow leak that would only become visible after many repeated failures during a cloud-storage outage, not a day-to-day problem.
    - **Evidence:**
        ```php
        // Stream temp file → R2 (no second in-memory copy of the video).
        $stream = fopen($tmp, 'r');
        if ($stream === false) {
            return null;
        }
        Storage::disk('media')->put($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return Storage::disk('media')->url($path);
        } catch (Throwable $e) {
            report($e);

            return null;
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Instagram reel-mirror stream cleanup:** SEM-1
    - **Why grouped:** Single isolated finding, single file/method — nothing else in this audit shares its root cause.
    - **Model:** Plan: Opus (combine plan+implement given S effort) · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.
