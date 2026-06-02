Good — all verification complete. Key findings from my checks:

1. **ENV-1**: `MediaDiskResolver` is confirmed as the **only** file in `app/` using `$_ENV`/`$_SERVER` directly. The logic uses the superglobal as a presence *flag* and returns `$configured` (not the superglobal value), so it's functionally correct but inconsistent with the rest of the codebase. `config/partna.php` uses `env()` consistently. Downgrade to P3.

2. **ENV-2**: The `$e->getMessage()` pattern is widespread (verified 12+ call sites), but crucially `AccountDeletionService.php:517` writes it into the **audit table** metadata (not just logs) — that table outlives log retention windows. All exception sources are infrastructure operations (DB, S3, queue, OAuth transport), not API response bodies that typically carry PII. The codebase clearly knows how to do this right — `SupabaseAdminService` uses SHA-256 fingerprinting, `deleteSupabaseAuthUser` explicitly drops response bodies with a comment explaining why. P3 confirmed.

3. No hardcoded secrets or credentials found in any file.

`★ Insight ─────────────────────────────────────`
The key pattern to note: `MediaDiskResolver` uses `$_ENV`/`$_SERVER` as a *presence check* (then returns `config()`, not the superglobal value). Laravel's `env()` reads directly from the process environment even after `config:cache` — the PHPdoc rationale about "Laravel Cloud caches config at deploy time" is partially misleading, making this a good candidate to simplify before it confuses a future maintainer.
`─────────────────────────────────────────────────`

---

# Env / Credentials / Secrets Audit — 2026-05-25

**Branch:** development
**Lens:** env reads outside config layer, hardcoded secrets, plaintext credentials/PII leaking into logs and exception messages
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Media/MediaDiskResolver.php
- app/Services/Media/ImageVariantService.php
- app/Services/Media/MediaUploadService.php
- app/Services/Media/VideoVariantService.php
- app/Services/Streaming/StreamingTokenManager.php
- app/Services/Streaming/KickApiClient.php
- app/Services/Streaming/TwitchApiClient.php
- app/Services/Streaming/LiveStatusInjector.php
- app/Services/Streaming/LiveStatusPoller.php
- app/Services/Professional/AccountDeletionService.php
- app/Services/Professional/SectionVisibilityService.php
- app/Services/Professional/DataExport/DataExportPayloadBuilder.php
- app/Services/Professional/DataExport/DataExportService.php
- app/Services/Professional/DataExport/DataExportZipWriter.php
- app/Services/Professional/ProfessionalBootstrapService.php
- app/Services/Auth/SupabaseAdminService.php
- app/Services/Auth/SupabaseAuthHookService.php
- app/Services/Auth/TokenRevocationService.php

## Progress

- P3 Low: 0 of 2 complete

---

## P3 — Nice to have

- [ ] **#ENV-1** · P3 — MediaDiskResolver probes `$_ENV`/`$_SERVER` directly; `env()` is equivalent and idiomatic
    - **Where:** app/Services/Media/MediaDiskResolver.php:32–36
    - **Affects:** Developer experience and consistency audits only — the code is functionally correct and behaves identically to the `env()` equivalent.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the four-way superglobal probe with `env('PARTNA_MEDIA_DISK') ?: env('SIDEST_MEDIA_DISK')`. Laravel's `env()` reads directly from the live process environment even when config is cached, so it handles the Laravel Cloud runtime-injection scenario without needing direct superglobal access.
        - Update (or remove) the class-level docblock comment that explains the `$_ENV`/`$_SERVER` rationale — once the probe is gone, the comment becomes misleading.
        - This is the only file in `app/` that reads `$_ENV`/`$_SERVER` directly (confirmed by codebase search). Consolidating removes the one exception to an otherwise consistent pattern.
    - **Technical:** `MediaDiskResolver::resolve()` probes `$_ENV['PARTNA_MEDIA_DISK']`, `$_SERVER['PARTNA_MEDIA_DISK']`, and their `SIDEST_MEDIA_DISK` equivalents, then returns `$configured` (the `config()` value) when any are present — it never actually uses the superglobal's value. The comment states this is required because Laravel Cloud injects platform env vars at runtime after config is cached, making `env()`/`config()` stale. This is partially inaccurate: Laravel's `Illuminate\Support\Env::get()` reads from the live process environment (`$_ENV`, `$_SERVER`, `getenv()`) in the same order, even post-cache. The direct superglobal probes are therefore redundant. No recent commit touches this file (adjudicated 2026-05-25).
    - **Plain English:** The whole codebase checks a central rulebook when it needs a setting. This one file goes around the rulebook and reads from the same raw source directly, then — oddly — uses the rulebook's value anyway. It works, but it's the only place in the code that does things this way, which makes it look suspicious to any future reader or automated code scanner. Switching to the standard method is a two-line change with identical behaviour.
    - **Evidence:**
        ```php
        $explicit = $_ENV['PARTNA_MEDIA_DISK'] ?? $_SERVER['PARTNA_MEDIA_DISK']
            ?? $_ENV['SIDEST_MEDIA_DISK'] ?? $_SERVER['SIDEST_MEDIA_DISK'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '') {
            return $configured;
        }
        ```

- [ ] **#ENV-2** · P3 — `$e->getMessage()` passed raw into log contexts and one audit-table row across multiple services
    - **Where:** app/Services/Professional/AccountDeletionService.php (lines ~82, ~223, ~377, ~450, ~499, ~511, ~517, ~622); app/Services/Media/MediaUploadService.php (lines ~112, ~245, ~259, ~288, ~303, ~313); app/Services/Media/VideoVariantService.php (~393); app/Services/Streaming/StreamingTokenManager.php (~100); app/Services/Professional/SectionVisibilityService.php (~360)
    - **Affects:** Log aggregator (Nightwatch) retention and the `core.professional_deletion_audit` table. If any dependency ever throws with PII or a credential fragment in its message, that string enters storage that GDPR erasure cannot reach. The deletion-audit case (line ~517 of AccountDeletionService) is the highest-persistence risk: audit rows have no TTL or retention window, unlike Nightwatch logs.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Audit the deletion-audit metadata write first — `AccountDeletionService.php:517` embeds `$e->getMessage()` in the `metadata` JSON column of `core.professional_deletion_audit`. Replace with a static label: `['reason' => 'force_delete_failed', 'exception_class' => get_class($e)]`.
        - For the `StreamingTokenManager` catch block, the exception comes from the OAuth HTTP transport. Replace `'message' => $e->getMessage()` with `'exception_class' => get_class($e)` — the platform and status already provide the diagnostic signal needed for a circuit-breaker decision.
        - For the remaining `Log::error` / `Log::warning` sites in `MediaUploadService`, `VideoVariantService`, and `AccountDeletionService` (mail-dispatch failures), the exception sources are infrastructure (S3, Redis, queue, SMTP). These are lower risk but can be tightened cheaply with the same `get_class($e)` substitution.
        - `SupabaseAdminService` and `deleteSupabaseAuthUser` already demonstrate the right pattern with explicit comments — use them as the template.
    - **Technical:** Laravel's `Log::error('...', ['error' => $e->getMessage()])` stores whatever string the throwing class produced. Today's callers use Guzzle, PDO, and Laravel's queue/mail infrastructure — none of which typically embed user PII in connection/transport exceptions. The risk is fragile rather than imminent: a dependency upgrade, a custom exception thrown with a raw email in its message, or a future code path that wraps a GoTrue response body inside a RuntimeException (which `SupabaseAdminService` already explicitly guards against) would silently persist that data. The one concrete concern is `AccountDeletionService.php:517`, which writes the exception message into `core.professional_deletion_audit` — a table with no retention TTL that outlives both log windows and the user's own data after hard-delete. A `forceDelete()` failure today would produce a PDO/Eloquent error message (not PII), but the table schema does not enforce that invariant and a future change could break it silently.
    - **Plain English:** When something breaks, the app writes a crash report. Most crash reports go into a temporary log system that eventually discards old entries. But one crash report in the account-deletion flow gets written into a permanent record book that we keep forever. The crash message probably only says things like "database connection refused," but the code as written would also preserve anything more sensitive that the crash message happened to contain. The fix is to record what type of problem occurred (like "database error") instead of the exact message, following the careful approach already used elsewhere in the same file.
    - **Evidence:**
        ```php
        // AccountDeletionService.php — purge() forceDelete catch: written to audit TABLE (permanent)
        $this->logAuditEvent(
            $professional,
            ProfessionalDeletionAuditEntry::EVENT_PURGE_FAILED,
            null,
            ['reason' => 'force_delete_failed', 'error' => $e->getMessage()],
            ProfessionalDeletionAuditEntry::ACTOR_TYPE_SYSTEM,
        );

        // StreamingTokenManager.php — refreshToken() catch: OAuth transport exception
        Log::critical('streaming.auth_failure', [
            'platform' => $platform,
            'message' => $e->getMessage(),
        ]);

        // VideoVariantService.php — deleteVariants(): S3/R2 list failure
        Log::error('VideoVariantService::deleteVariants list failed; DB rows still cleared.', [
            'media_id' => $mediaId,
            'base_prefix' => $basePrefix,
            'error' => $listError->getMessage(),
            'exception' => get_class($listError),
        ]);
        ```
