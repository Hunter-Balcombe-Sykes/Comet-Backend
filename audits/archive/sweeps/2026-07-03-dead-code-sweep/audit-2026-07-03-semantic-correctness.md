# Semantic Correctness Audit — 2026-07-03

**Branch:** development
**Lens:** Semantic Correctness: code that compiles and type-checks but does the wrong thing (plausible-but-wrong logic invisible to Larastan)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/User/AccountDeletionService.php
- app/Services/User/Concerns/ResolvesDeletedEmail.php
- app/Jobs/Design/AnalyzePreviousWebsiteJob.php
- app/Models/Core/Site/Workplace.php
- app/Http/Controllers/Api/User/Account/UserDocumentController.php
- app/Models/Core/Site/SiteMedia.php
- app/Console/Commands/PurgeSoftDeleted.php
- app/Console/Commands/GcOrphanedVideoArtifactsCommand.php
- app/Services/FeatureFlags/FeatureFlagService.php
- vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php (verification only)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 1 complete

---

## P3 — Nice to have

- [ ] **#SEM-1** · P3 — Purge audit entry stores the pseudonymised email instead of the resolved original
    - **Where:** app/Services/User/AccountDeletionService.php:468 (snapshot capture), 516 (real-email resolution), 554-565 (audit create)
    - **Affects:** Forensic readability of the `EVENT_PURGED` audit row. The real email remains fully recoverable from the earlier `EVENT_CONFIRMED` row (via `restoreEmailFromAuditSnapshot`/`resolveDeletedAccountEmail`), so no data is lost — but the final "purged" receipt itself records `deleted+{id}@partna.au` rather than the real address it had every opportunity to record correctly.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `purge()`, use `$lookupEmail` (resolved at line 516) instead of the stale `$emailSnapshot` (captured at line 468) when building the `EVENT_PURGED` audit row at lines 554-565.
        - Since `$lookupEmail` is resolved after the snapshot capture block, either move the snapshot assignment down or simply substitute `$lookupEmail ?? $emailSnapshot` directly in the `create()` call.
    - **Technical:** `purge()` captures `$emailSnapshot` from `$professional->primary_email` at line 468 — at that point in the lifecycle `primary_email` already reads `deleted+{id}@partna.au`, because `executeConfirmation()` (called during `confirm()`/`adminInitiate()`, 30 days earlier) ran `pseudonymiseAccountPii()` inside the same transaction as the `EVENT_CONFIRMED` audit write (see the load-bearing ordering comment at lines 163-167: "logAuditEvent reads `$professional->primary_email` to snapshot it, so it MUST run before `pseudonymiseAccountPii` overwrites the live value"). `purge()` later calls `resolveDeletedAccountEmail()` (line 516) — verified in `app/Services/User/Concerns/ResolvesDeletedEmail.php:37-56` — which detects the `deleted+` prefix and re-resolves the real address from the most recent `EVENT_CONFIRMED`/`EVENT_REQUESTED`/`EVENT_ADMIN_INITIATED` audit row, storing it in `$lookupEmail`. This resolved value is correctly used for the waitlist, feedback, and cross-tenant subscription erasure lookups (lines 520-526) — but the final `UserDeletionAuditEntry::create()` call at lines 554-565 still writes the earlier, stale `$emailSnapshot` into `professional_email_snapshot`, inconsistent with `$handleSnapshot` (handles are never pseudonymised, so that value is genuinely live) and inconsistent with the correct-value pattern used three lines later in the same method.
    - **Plain English:** When an account is fully erased after its 30-day waiting period, the system writes a final "purged" receipt to the audit log. That receipt is supposed to record the account's real email address for future reference — but the email had already been scrambled into a placeholder during the waiting period (a privacy safeguard), and the final receipt writes down that scrambled placeholder instead of the real address, even though the code looks up the real address for other purposes moments later in the same function. The real email is still safely on file in an earlier "deletion confirmed" receipt, so nothing is actually lost — it's just that the final receipt is less useful than it should be for a support agent trying to look something up.
    - **Evidence:**
        ```php
        $handleSnapshot = (string) ($professional->handle ?? '');
        $emailSnapshot = (string) ($professional->primary_email ?? '');
        ```
        ```php
        $lookupEmail = $this->resolveDeletedAccountEmail($professional);
        ```
        ```php
        UserDeletionAuditEntry::create([
            'user_id' => null,
            'professional_handle_snapshot' => $handleSnapshot,
            'professional_email_snapshot' => $emailSnapshot,
            'event' => UserDeletionAuditEntry::EVENT_PURGED,
            'actor_type' => UserDeletionAuditEntry::ACTOR_TYPE_SYSTEM,
            // Ledger of R2 video paths for the orphan sweep (P1-08). Null when
            // the account had no videos, so the metadata column stays clean.
            'metadata' => $videoArtifactPaths !== []
                ? ['video_artifact_paths' => $videoArtifactPaths]
                : null,
        ]);
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Purge-audit forensic accuracy:** #SEM-1
    - **Why grouped:** single-file, single-method, mechanical one-line substitution; no companion finding from this scan shares the file or root cause.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet). No escalation needed — trivial variable substitution.

## Standalone — do NOT bundle

None.
