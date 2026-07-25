-- =====================================================================
-- audit.staff_audit_log — hash raw IPs + allow GET reads (B4: OBS-3/PRIV-4/DINT-1)
--
-- DINT-1: raw IPs (`ip inet`) were stored permanently in this append-only
-- table. The row-level reject-mutation trigger (core.reject_staff_audit_log_mutation)
-- blocks UPDATE, so historical rows can never be hashed retroactively — the
-- column is dropped outright (losing the raw-IP history is acceptable and
-- privacy-desirable) and replaced with a one-way HMAC-SHA256 digest computed
-- by StaffAuditService::record() before insert (same convention as
-- site.enquiries.ip_hash / core.feedback.ip_hash).
--
-- PRIV-4: the audit middleware only covered WRITE methods, so staff GET reads
-- of user PII (professional detail, customers, email subscribers, enquiries)
-- left no audit trail. RecordStaffAuditEntry now also records a small,
-- explicitly-allowlisted set of PII-returning GET routes — widen the
-- http_method CHECK to permit 'GET' rows.
-- =====================================================================

-- Step 1 — DINT-1: drop the raw IP column, add the hashed replacement.
-- Both are metadata-only on an unpopulated/near-empty column set (no rewrite
-- scan), safe as a single ACCESS EXCLUSIVE-but-instant operation.
BEGIN;

ALTER TABLE audit.staff_audit_log DROP COLUMN ip;
ALTER TABLE audit.staff_audit_log ADD COLUMN ip_hash text;

-- Step 2 — PRIV-4: widen the http_method CHECK to allow 'GET'. NOT VALID per
-- CONVENTIONS.md §2 — avoids an ACCESS EXCLUSIVE table scan; validated below
-- in a separate transaction under the lighter SHARE UPDATE EXCLUSIVE lock.
ALTER TABLE audit.staff_audit_log DROP CONSTRAINT staff_audit_log_http_method_check;
ALTER TABLE audit.staff_audit_log
    ADD CONSTRAINT staff_audit_log_http_method_check
    CHECK (http_method IN ('POST', 'PATCH', 'PUT', 'DELETE', 'GET')) NOT VALID;

COMMIT;

-- Step 3 — validate the widened CHECK in its own transaction (SHARE UPDATE
-- EXCLUSIVE only — existing rows are all POST/PATCH/PUT/DELETE, a strict
-- subset of the new list, so this passes trivially).
BEGIN;

ALTER TABLE audit.staff_audit_log VALIDATE CONSTRAINT staff_audit_log_http_method_check;

COMMIT;
