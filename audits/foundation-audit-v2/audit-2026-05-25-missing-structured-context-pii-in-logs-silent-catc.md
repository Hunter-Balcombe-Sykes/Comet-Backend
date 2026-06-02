`★ Insight ─────────────────────────────────────`
**Adjudication pattern:** All three findings have verbatim evidence in the provided sources. AUDIT-1 is the most structurally interesting: the forceDelete succeeds, the professional row no longer exists, and the bare `::create()` after it has no safety net — meaning the fix must still return `true` (don't retry a purge on a deleted row) while ensuring the failure is reported. That's a subtler constraint than DeepSeek's fix description captures.
`─────────────────────────────────────────────────`

# Observability & Audit Integrity Audit — 2026-05-25

**Branch:** development
**Lens:** missing structured context, PII in logs, silent catch blocks, gaps in exception/slow-job coverage, audit-log integrity
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Professional/AccountDeletionService.php
- app/Services/Audit/StaffAuditService.php
- app/Services/Notifications/NotificationPublisher.php
- app/Services/Professional/SectionVisibilityService.php
- app/Http/Middleware/Logging/LogLeadRateLimits.php
- app/Http/Middleware/Logging/RecordStaffAuditEntry.php
- app/Services/FeatureFlags/FeatureFlagService.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#AUDIT-1** · P1 — Purge-event audit entry silently dropped on DB failure; GDPR erasure trail is lost
    - **Where:** `app/Services/Professional/AccountDeletionService.php` — `purge()`, the bare `ProfessionalDeletionAuditEntry::create(…)` call immediately after `forceDelete()`
    - **Affects:** GDPR Articles 17 and 30 compliance — a transient DB connection drop after the professional row is hard-deleted silently skips writing the `EVENT_PURGED` audit entry, leaving no system-level record that the erasure ever completed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the final `ProfessionalDeletionAuditEntry::create([…])` in a `try-catch (Throwable $e)` block.
        - Inside the catch, log an error with `$handleSnapshot`, `$emailSnapshot`, and `$e->getMessage()`, then call `report($e)` so Nightwatch surfaces the gap as a tracked exception.
        - **Still `return true` inside the catch** — the professional row is already gone; returning `false` would cause `PurgeSoftDeleted` to re-queue the purge on a non-existent row on the next daily run, producing a cascade of failed audit writes.
    - **Technical:** After `forceDelete()` the professional row no longer exists; the audit table's FK is NULLable specifically to allow this `EVENT_PURGED` row. `$handleSnapshot` and `$emailSnapshot` are captured at the top of `purge()` before the delete, so they are available to the catch block. The failure mode is rare (Supabase transient drop) but not hypothetical: Supabase documents connection-pool exhaustion and brief maintenance windows. `Log::error` alone does not create a Nightwatch exception event; only `report()` does.
    - **Plain English:** This is the step that writes "this account was permanently deleted" into the permanent record-book. Right now, if the database hiccups at exactly that moment, the deletion still goes through — but the paperwork never gets filed. Legally, we erased someone's data with no evidence trail. The fix makes the system file a loud complaint to the ops team when the paperwork can't be written, while still completing the deletion rather than retrying it pointlessly.
    - **Evidence:**
        ```php
        // After forceDelete() — no try-catch around the audit write
        ProfessionalDeletionAuditEntry::create([
            'professional_id' => null,
            'professional_handle_snapshot' => $handleSnapshot,
            'professional_email_snapshot' => $emailSnapshot,
            'event' => ProfessionalDeletionAuditEntry::EVENT_PURGED,
            'actor_type' => ProfessionalDeletionAuditEntry::ACTOR_TYPE_SYSTEM,
        ]);

        return true;
        ```

---

## P2 — Should fix

- [ ] **#CTX-1** · P2 — Multiple request-scoped log statements lack `X-Request-Id` correlation
    - **Where:**
        - `app/Services/Notifications/NotificationPublisher.php` — dropped-notification warnings in `publish()` and `passesCapabilityGate()`
        - `app/Services/Professional/SectionVisibilityService.php` — `reevaluateEnabled()` catch block
        - `app/Http/Middleware/Logging/LogLeadRateLimits.php` — `terminate()` catch block (has `$request` in scope)
        - `app/Http/Middleware/Logging/RecordStaffAuditEntry.php` — outer `terminate()` catch block
    - **Affects:** Operations — during incidents, these log entries cannot be tied to the originating HTTP request without manual timestamp joins across access logs and application logs.
    - **Effort:** M (~2–4h) — four files; each site is a one-liner addition to the context array.
    - **What to do:**
        - Add `'request_id' => request()?->header('X-Request-Id')` to every `Log::warning` / `Log::error` that runs inside a web-request lifecycle (middlewares, service methods called from controllers).
        - For `LogLeadRateLimits` and `RecordStaffAuditEntry`, prefer `$request->header('X-Request-Id')` directly since the `Request` object is already in scope in `terminate()` — avoids the global helper entirely.
        - `SectionVisibilityService::reevaluateEnabled()` may be called from both HTTP and queue contexts; `request()?->header(…)` gracefully yields `null` in queue context — no conditional guard is needed.
        - Use the exact pattern already established in `FeatureFlagService::enabled()` and `StaffAuditService::record()`.
    - **Technical:** Commit `584c9018` extended the `request_id` pattern to Supabase auth-hook logs; the four sites listed above were not covered by that sweep. In Nightwatch, correlating a `NotificationPublisher` dropped-notification warning to the originating request currently requires timestamp guesswork; `request_id` makes it a single indexed lookup. The `passesCapabilityGate()` DB-failure path in `NotificationPublisher` is particularly high-value: a capability lookup failure is silently fail-open and the only breadcrumb is this warning.
    - **Plain English:** Imagine every alarm at a hospital says "something went wrong" with no patient name or room number. Finding out which patient triggered the alarm means searching through paper records by timestamp. Adding the request ID to every log entry is like stamping the patient wristband number on every alarm — support can pull up the full picture for a specific visitor's session in one step.
    - **Evidence:**
        ```php
        // NotificationPublisher — empty professional_id warning (no request_id)
        Log::warning('NotificationPublisher: dropped notification — empty professional_id', [
            'category' => $category,
            'frontend_type' => $frontendType,
        ]);
        ```
        ```php
        // SectionVisibilityService — reevaluateEnabled catch (no request_id)
        Log::warning('Section is_enabled reevaluation failed', [
            'professional_id' => $professionalId,
            'site_id' => $siteId,
            'block_type' => $blockType,
            'message' => $e->getMessage(),
        ]);
        ```
        ```php
        // LogLeadRateLimits — terminate catch ($request in scope, but no request_id)
        Log::warning('lead.rate_limit_log_failed', [
            'exception' => $e->getMessage(),
            'path' => $request->path(),
        ]);
        ```
        ```php
        // RecordStaffAuditEntry — outer terminate catch (no request_id)
        Log::warning('staff.audit.middleware_failed', [
            'exception' => $e->getMessage(),
            'route' => $request->path(),
        ]);
        ```

- [ ] **#AUDIT-2** · P2 — Staff-audit write failures log a warning but never create a Nightwatch exception event
    - **Where:** `app/Services/Audit/StaffAuditService.php` — `catch (Throwable $e)` block in `record()`
    - **Affects:** Security monitoring — a persistent failure on `core.staff_audit_log` (connection exhaustion, disk full, schema mismatch) produces only `Log::warning` breadcrumbs; Nightwatch does not alert on warnings, only on `report()`-ed exceptions, so the outage could go unnoticed for days while staff actions accumulate unrecorded.
    - **Effort:** S (~0.5–1h) — single line addition.
    - **What to do:**
        - Add `report($e);` immediately after the existing `Log::warning('staff.audit.write_failed', …)` call.
    - **Technical:** The `catch` block correctly does not re-throw (`StaffAuditService` is designed to be non-blocking — audit failures must never reject a staff request). But non-blocking does not mean invisible. `Log::warning` feeds a breadcrumb trail; `report($e)` feeds the Nightwatch exception dashboard and its alert rules. Without `report()`, the only detection path for a broken audit table is a human manually querying the log aggregator — there is no automated signal.
    - **Plain English:** The staff audit trail is your security camera for admin actions. If the recording system breaks, today we leave a sticky note in the filing cabinet. The sticky note won't wake anyone up. Adding `report()` turns the broken recorder into a proper alarm that the ops team sees alongside every other system failure — exactly like they'd want to know if the actual security cameras went offline.
    - **Evidence:**
        ```php
        } catch (Throwable $e) {
            // B3/P2-12: request_id correlates the warning to the NGINX/Cloudflare
            // access log entry — same pattern as FeatureFlagService / NotificationPublisher.
            Log::warning('staff.audit.write_failed', [
                'exception' => $e->getMessage(),
                'route' => $route,
                'http_method' => $httpMethod,
                'request_id' => request()?->header('X-Request-Id'),
            ]);

            return null;
        }
        ```
