`★ Insight ─────────────────────────────────────`
The source confirms both findings: (1) `pseudonymiseAccountPii()` at line 127/273 runs after `executeConfirmation()` returns — outside the DB transaction in `executeConfirmation()`; (2) lines 50–70 show the two-update token write/rollback pattern with no lock between them. The comment at line 123–125 confirms the audit-before-pseudonymise ordering is intentional.
`─────────────────────────────────────────────────`

# Lifecycle & Atomicity Audit — 2026-05-24

**Branch:** development
**Lens:** missing transactions, race conditions, soft-delete consistency, FK/unique constraint gaps, N+1 writes
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Services/Professional/AccountDeletionService.php`
- `supabase/migrations/20260526000000_baseline_standalone_user.sql`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 2 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **DEL-1** · P2 — `pseudonymiseAccountPii` runs outside the confirmation transaction, leaving a window where status is `pending_deletion` but PII is un-wiped on DB failure
    - **Where:** `app/Services/Professional/AccountDeletionService.php:127` (also line 273 in `adminInitiate()`)
    - **Affects:** Any user who confirms account deletion if the DB write in `pseudonymiseAccountPii()` fails after `executeConfirmation()` commits — their status flips but their live PII remains in the row indefinitely.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Before calling `executeConfirmation()`, snapshot `$professional->primary_email` into a local variable (e.g., `$emailSnapshot = $professional->primary_email`).
        - Move the `pseudonymiseAccountPii()` call inside a wrapping `DB::transaction()` that covers both `executeConfirmation()` and the PII wipe, OR extend `executeConfirmation()`'s internal transaction to also call `pseudonymiseAccountPii()`.
        - Pass `$emailSnapshot` to `logAuditEvent()` (or let it read from the already-saved audit row) so the pre-wipe email is captured before the transaction commits — preserving the intentional audit-before-pseudonymise ordering.
        - Apply the same fix to `adminInitiate()` (line 273).
        - Add a test that simulates a DB error mid-`pseudonymiseAccountPii()` and asserts the professional's status reverts (i.e., the whole operation rolls back).
    - **Technical:** `executeConfirmation()` wraps its writes in `DB::transaction()` (line 143), committing the `status = 'pending_deletion'` flip and site unpublish atomically. `pseudonymiseAccountPii()` then runs as a separate `forceFill()->save()` call outside any transaction. If the database is unavailable or raises a constraint error at that second save, the status is permanently `pending_deletion` but `primary_email`, `phone`, etc. remain live PII — a GDPR/retention inconsistency with no automated recovery path. The existing comment (lines 123–125) explains the audit-ordering rationale; the fix preserves it by snapshotting the email before the unified transaction begins, then logging the audit event (which reads from the snapshot) before committing the PII wipe.
    - **Plain English:** When a user clicks "Confirm delete my account," the system marks them as "deletion in progress" first, then immediately overwrites their personal details with placeholder text. Those are two separate database writes. If the server crashes or the database hiccups between them, the account is stuck in the deletion queue but the real name and email are still sitting in the database — exactly what the deletion was supposed to clear. The fix is to wrap both writes in a single all-or-nothing operation, so either both succeed together or both get undone together.
    - **Evidence:**
        ```php
        $deletesAt = $this->executeConfirmation($professional);

        // Order matters: audit row must capture the REAL primary_email before we
        // pseudonymise. pseudonymiseAccountPii() is a one-way write that destroys
        // the live PII, so it always runs after the audit snapshot is durable.
        $this->logAuditEvent($professional, ProfessionalDeletionAuditEntry::EVENT_CONFIRMED, $request);
        $this->pseudonymiseAccountPii($professional);
        ```

- [x] **DEL-2** · P2 — Deletion token write and mail-failure rollback use two unguarded `update()` calls, creating a race window on double-submit
    - **Where:** `app/Services/Professional/AccountDeletionService.php:50–70`
    - **Affects:** Users who double-tap "Request deletion" — the second request can observe the first token mid-flight and, if mail then fails, clear a token the second request wrote rather than its own.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the token write and mail send in a single `DB::transaction()`. On mail failure, throw inside the transaction so the token write rolls back automatically — no manual second `update()` needed.
        - Alternatively, add a `lockForUpdate()` when reading the user row before writing the token, to prevent a second concurrent request from reading stale state.
        - Remove the manual rollback `update()` block; let the transaction handle cleanup.
    - **Technical:** The `request()` method writes `deletion_token_hash` and `deletion_requested_at` at line 50, then attempts `Mail::send()` in a try/catch at line 57. On mail failure, a second `update()` at line 67 clears the token. There is no lock or transaction between these two writes. A concurrent request that slips in between lines 50 and 67 can: (a) read the first token as valid, or (b) overwrite with its own token, only for the catch block of the first request to null it out. At current scale this is a low-frequency edge case, not an everyday hazard — hence P2 rather than P1. The clean fix is transactional: wrap the `update()` + `Mail::send()` in `DB::transaction()`; throw on mail failure; let the rollback handle cleanup. This collapses two database round-trips into one and eliminates the race entirely.
    - **Plain English:** Asking the system to delete your account writes a secret code to the database, then tries to email it to you. If the email fails, it runs a second database operation to erase the code. Between those two database operations there's a brief window where another request (say, you hit the button twice) could interfere — writing its own code, or accidentally erasing the wrong one. The fix is to treat the "write code + send email" as a single all-or-nothing step: if the email fails, the database write automatically undoes itself, with no second cleanup operation needed.
    - **Evidence:**
        ```php
        $professional->update([
            'deletion_token_hash' => $tokenHash,
            'deletion_requested_at' => now(),
        ]);

        // ...

        } catch (\Throwable $e) {
            // Mail failed — roll back token so user can retry cleanly.
            $professional->update([
                'deletion_token_hash' => null,
                'deletion_requested_at' => null,
            ]);
        ```
