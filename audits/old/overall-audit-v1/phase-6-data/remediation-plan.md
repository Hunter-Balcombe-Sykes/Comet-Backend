# Partna Phase 6 Data Integrity & Privacy — Consolidated Remediation Plan

> **FROZEN as of 2026-05-12.** This plan is no longer the source of truth for status, regressions, or post-baseline annotations. Live status — including all changes from PR #12 onwards and any new findings introduced after this date — lives in `audits/MASTER-REMEDIATION-PLAN.md`. This file is preserved as provenance for `Original ID: Phase 6 Pattern X` references in the master plan. Do not edit; if a status change is needed, update the master instead.

**Date:** 2026-05-12
**Branch:** development
**Source:** 7 audits across `audits/phase-6-data/`, adjudicated by `claude-sonnet-4-6` over `deepseek-v4-pro` drafts
**Lens:** Data integrity & privacy — FK hygiene, soft-delete coherence, orphan rows, PII inventory, retention, GDPR redact/export symmetry, CHECK-constraint discipline

## Summary

- **41 reported findings**, **33 unique** after deduplication (8 cross-audit duplicates — see matrix below)
- **Tier breakdown (reported):** 1 P0 · 5 P1 · 28 P2 · 7 P3
- **Tier breakdown (unique):** 1 P0 · 5 P1 · 22 P2 · 5 P3
- **Eight foundational patterns close 27 of 33 unique findings** (1 P0 · 5 P1 · 18 P2 · 3 P3)
- **6 standalone fixes** for the rest (4 P2 · 2 P3)
- **One cross-phase dependency** (Phase 3 cache layer) — see "Cross-phase coordination" below
- **Estimated total:** ~5–6 days (1–1.5 weeks) of focused work to close all 33 findings

Phase 6 has the largest finding count of any phase to date but the highest pattern density: 27 of 33 unique findings collapse into 8 root-cause sweeps. The dominant shape is **DB-level guards missing on application-enforced invariants** — every `status` / `role` / `type` / `pool` / `provider` column the application treats as an enum has no DB CHECK to back it up. The single P0 (`stripe_connect_status` rejects the only value the disconnect path writes) is the live manifestation of this gap. The second cluster is **GDPR/retention contract gaps** — `PurgeSoftDeleted` is missing two PII-bearing models, `RedactCustomerJob` is asymmetric with `ExportCustomerDataJob` on one analytics table, and the global newsletter list has no erasure path at all. The third is **audit-table hardening** — two audit tables created after the SET NULL + RLS pattern was established (`brand_status_history`, `wallet_currency_switch_audit`) shipped with `CASCADE` and no RLS, contradicting the rest of the schema.

## Cross-audit duplicates (collapse on fix)

| Finding | Audits | Same root cause |
|---------|--------|-----------------|
| `PurgeSoftDeleted` omits `Enquiry` | DATA-A#DATA-2 (P1) ≡ DATA-B#DATA-6 (P2) ≡ DATA-C2b#DATA-2 (P2) | Pattern 3 — take P1 as canonical (visitor PII) |
| `site.site_media.pool` has no CHECK constraint | DATA-A2#DATA-4 (P2) ≡ DATA-D#DATA-2 (P2) | Pattern 7 |
| `notifications.email_subscriptions.status` has no CHECK constraint | DATA-A#DATA-8 (P3) ≡ DATA-B#DATA-7 (P2) | Pattern 7 — take DATA-B's P2 (marketing-consent impact) |
| `public.failed_jobs.failed_at` is timezone-naive | DATA-A#DATA-9 (P3) ≡ DATA-D#DATA-5 (P3) | Standalone |
| `brand_status_history` uses `ON DELETE CASCADE` (audit-trail loss) | DATA-A2#DATA-5 (P2) ≡ DATA-D#DATA-1 (P1, broader) | Pattern 2 — take DATA-D's broader framing (includes `wallet_currency_switch_audit`) |
| Three audit tables lack RLS (`professional_deletion_audit`, `wallet_currency_switch_audit`, `brand_status_history`) | DATA-A#DATA-1 (P1) — also referenced by DATA-D#DATA-1's "additionally" clause | Pattern 2 — same migration |
| `Enquiry` model has no `$hidden` for PII | DATA-B#DATA-4 (P2) — also called out by DATA-A#DATA-2 evidence | Pattern 5 |

**Related (overlapping scope, distinct prescriptions — bundle the PR):**

| Findings | Why bundle |
|----------|------------|
| DATA-A#DATA-1 (RLS gap on 3 audit tables) + DATA-D#DATA-1 (CASCADE→SET NULL on 2 of those 3 tables) | Same migration touches the same DDL surface. Pattern 2 handles both axes at once. |
| DATA-B#DATA-3 (`WaitlistSignup` no `$hidden`) + DATA-B#DATA-4 (`Enquiry` no `$hidden`) + DATA-C2a#DATA-1 (Supabase admin logs raw email) | All PII-at-rest hardening in app-layer code; reviewer mental model is identical. |
| DATA-A#DATA-2 + DATA-B#DATA-6 + DATA-C2b#DATA-2 (purge omits `Enquiry`/`ServiceCategory`) | Single one-line fix to `PurgeSoftDeleted`. |
| DATA-A2#DATA-6 (global `email_subscriptions` no GDPR path) + DATA-B#DATA-5 (`analytics.lead_submissions` not nulled on redact) | Both are GDPR redact-path completeness. One PR to `RedactCustomerJob` + a new staff endpoint. |

## Cross-phase coordination

| Phase 6 finding | Cross-phase dependency | Sequencing |
|-----------------|------------------------|------------|
| DATA-C2b#DATA-1 (OAuth tokens serialized in Redis `pro:model:*` cache) | **Phase 3 Pattern 1** hardened `ProfessionalCacheService` with `rememberLocked` SWR. Removing `squareIntegration` from the eager-load is a one-line change but it lives in the cache surface Phase 3 just stabilised. | Land Phase 3 Pattern 1 first if not yet in `development`; this fix then becomes additive (drop the eager-load + recache). If Phase 3 is already shipped, this is independent. |
| DATA-A2#DATA-7 (13 mutable tables missing `updated_at` triggers) | **Phase 3 SWR caches** (CLAUDE.md: "push-invalidated on every commerce write") depend on `updated_at` freshness signals. Tables without the trigger can silently produce stale-then-fresh inversions when a non-Eloquent path (queue job, raw `DB::update`, Supabase dashboard) writes a row. | No ordering constraint with Phase 3 (which is on the read side). Land Pattern 9 (this fix) at any time; Phase 3 caches become strictly more correct after. |
| DATA-A#DATA-7 (failed `site_media` rows count against gallery limit) + Phase 4 Pattern 2 (migration safety conventions) | The gallery trigger update is one of the first migrations that exercises Phase 4 Pattern 2's `CREATE INDEX CONCURRENTLY` + `NOT VALID` + `VALIDATE CONSTRAINT` convention on a populated table (`site.site_media`). | Land Phase 4 Pattern 2 first (conventions doc + composer guard); this migration is then the proof artifact that the convention works on a non-empty surface. |

## Source audit files

- `audit-2026-05-12-data-a.md` (**DATA-A**: migrations scope — 2 P1, 5 P2, 2 P3)
- `audit-2026-05-12--data-integrity--privacy-fk-hygiene-soft-delete-co-2.md` (**DATA-A2**: migrations scope, second pass — 0 P1, 7 P2, 2 P3)
- `audit-2026-05-12--data-integrity--privacy-fk-hygiene-soft-delete-co-3.md` (**DATA-B**: models + factories — 1 P1, 6 P2, 3 P3)
- `audit-2026-05-12--data-integrity--privacy-fk-hygiene-soft-delete-co-4.md` (**DATA-D**: enums + migrations — 1 P1, 3 P2, 1 P3)
- `audit-2026-05-12--data-integrity--privacy-fk-hygiene-soft-delete-co-6.md` (**DATA-C1**: webhooks + GDPR jobs — 1 P0, 1 P2)
- `audit-2026-05-12--data-integrity--privacy-fk-hygiene-soft-delete-co-8.md` (**DATA-C2a**: privacy-critical services — 1 P1, 1 P2, 1 P3)
- `audit-2026-05-12--data-integrity--privacy-fk-hygiene-soft-delete-co-9.md` (**DATA-C2b**: remaining services — 0 P1, 2 P2, 1 P3)

---

# Part 1 — Foundational fixes

Order is severity-then-leverage: the P0 lands first because the disconnect path is presently broken; then the two P1 audit-table fixes because they touch the same DDL; then the P1 retention + soft-delete + PII patterns; then the high-volume P2 sweeps (CHECK constraints, FK indexes, updated_at triggers). Pattern 7 (CHECK sweep) closes the largest single block of findings (10) but lands later because it's mechanical and reviewer-light — slotting it after the structural P1s preserves reviewer attention budget.

**Order:** Pattern 1 (P0 stripe_connect_status) → Pattern 2 (P1 audit-table hardening) → Pattern 3 (P1 retention purge sweep) → Pattern 4 (P1 soft-delete cascade coherence) → Pattern 5 (P1 PII inventory hardening) → Pattern 6 (P2 GDPR redact-path symmetry) → Pattern 7 (P2 CHECK constraint sweep) → Pattern 8 (P2 FK index sweep) → Pattern 9 (P2 updated_at trigger sweep).

## Pattern 1 — Add `'disconnected'` to `stripe_connect_status` CHECK ✅ **Closed by PR #39 (`012285c`), 2026-05-13**

**Closes 1 unique finding (1 P0):** DATA-C1#DATA-1

**Effort:** ~0.5h

### Root cause

`supabase/migrations/20260403000000_v2_baseline.sql:240` defines `pro_stripe_connect_status_check CHECK (stripe_connect_status IN ('not_connected', 'onboarding', 'active', 'restricted'))`. Two code paths write `'disconnected'` to this column:

- `StripeConnectWebhookController::handleAccountDeauthorized()` (line 207) — fired by Stripe's `account.application.deauthorized` webhook when an affiliate revokes access on Stripe's side.
- `StripeConnectService::disconnectAccount()` (line 273) — fired by the dashboard's Disconnect button.

Both paths produce a `check_violation` (SQLSTATE 23514) on every call. The webhook handler returns 500 to Stripe, which retries the webhook indefinitely; the service raises 500 to the API caller. The affiliate is left stuck in whatever state they were in (`active` or `restricted`). Two downstream `if ($pro->stripe_connect_status === 'disconnected')` guards (`StripeConnectService.php:138, 192`) are permanently unreachable.

The correct fix pattern was already applied to `brand_status` in `20260505000000_redesign_brand_status_stages.sql`: `DROP CONSTRAINT IF EXISTS` + `ADD CONSTRAINT ... CHECK (..., 'disconnected', ...)`. The same procedure was simply never applied to `stripe_connect_status`.

### What to do

- [x] **Step 1 — Write the migration.** Create `supabase/migrations/<timestamp>_add_disconnected_to_stripe_connect_status.sql`:
    ```sql
    ALTER TABLE core.professionals
        DROP CONSTRAINT IF EXISTS pro_stripe_connect_status_check;

    ALTER TABLE core.professionals
        ADD CONSTRAINT pro_stripe_connect_status_check
        CHECK (stripe_connect_status IN ('not_connected', 'onboarding', 'active', 'restricted', 'disconnected'));
    ```
    Per Phase 4 Pattern 2, the `DROP`/`ADD` pair runs cleanly inside a single transaction on a near-empty table. Post-launch the same pattern is `NOT VALID` first → `VALIDATE CONSTRAINT` second; current `core.professionals` row count is small enough that the in-transaction variant is acceptable.
- [x] **Step 2 — Verify writer surface is exhaustive.** `rg "stripe_connect_status\s*=>" app/` should return three writers: `StripeConnectService::syncStatus()` (`'not_connected'`, `'onboarding'`, `'active'`, `'restricted'`), `StripeConnectService::disconnectAccount()` (`'disconnected'`), `StripeConnectWebhookController::handleAccountDeauthorized()` (`'disconnected'`). All values must appear in the new CHECK; confirm before pushing.
- [x] **Step 3 — Backfill is unnecessary.** No row currently holds `'disconnected'` (the constraint has never allowed the value). Replay or repair of stuck disconnects can wait until the constraint is relaxed: any in-flight Stripe webhook retry will succeed on the next attempt after deploy.
- [x] **Step 4 — Test coverage.** Add a Pest test `tests/Feature/Webhooks/StripeConnectDeauthorizationTest.php` that:
    1. Seeds a professional with `stripe_connect_status = 'active'`.
    2. Posts an `account.application.deauthorized` webhook payload to the controller.
    3. Asserts `$professional->fresh()->stripe_connect_status === 'disconnected'` and 200 response.
    Same test in `StripeConnectServiceTest` for `disconnectAccount()`.

### Plain English

There's a list of allowed states for each affiliate's Stripe connection ("active", "restricted", etc.). When the app tries to move someone to "disconnected" — because they clicked Disconnect, or because Stripe told us they revoked access — the database checks its list, doesn't see "disconnected" on it, and refuses the operation. Every disconnect is broken today. The same fix was made for the brand-status column a week ago; nobody went back and applied it to the Stripe-status column. One migration adds the missing value to the list.

### Why this is highest priority

The disconnect path is currently 100% broken. Today this affects nobody because there are no real affiliates. On pilot day one, the first affiliate who revokes Stripe access surfaces:

1. A stuck `active`/`restricted` status in the dashboard with no recovery without a manual SQL update.
2. An infinite-loop Stripe webhook retry that fills the failed-jobs queue and (post Phase 4 Pattern 3 sweep) eventually drops to dead-letter.
3. Two dead `if` branches in `StripeConnectService` that gate re-onboarding and status-fetch behaviour.

The fix is the smallest in the plan (one migration, 5 lines of SQL) and the cost of *not* fixing it scales linearly with pilot brand count. Ship it first, then the rest.

---

## Pattern 2 — Audit-table FK + RLS hardening

**Closes 4 unique findings (1 P1 from DATA-A + 1 P1 from DATA-D · 1 P2 absorbed):** DATA-A#DATA-1, DATA-D#DATA-1 (which absorbs DATA-A2#DATA-5)

**Effort:** ~1h

### Root cause

Three `core.*` audit tables were created **after** the mass-RLS migration (`20260420200000`) and the SET-NULL-on-professional-delete pattern was established (`20260419000002_nullable_commission_fks.sql`). All three shipped with one or both of the post-pattern gaps:

| Table | Created in | Missing RLS? | FK uses CASCADE? |
|-------|------------|--------------|-------------------|
| `core.professional_deletion_audit` | 20260419000001 | **Yes** | No (already SET NULL) |
| `core.wallet_currency_switch_audit` | 20260504200000 | **Yes** | **Yes** |
| `core.brand_status_history` | 20260505000001 | **Yes** | **Yes** |

**RLS gap impact:** `app_backend` has `BYPASSRLS`, so Laravel is unaffected. PostgREST applies the authenticated role, which has no policy on these tables, so Postgres defaults to allow-all. Any authenticated Supabase JWT user can `GET /rest/v1/professional_deletion_audit` and receive every row — including `professional_email_snapshot` for users who requested deletion.

**CASCADE gap impact:** When `PurgeDeletedProfessionalsJob` hard-deletes a professional, all audit rows referencing that professional are silently CASCADE-deleted. `wallet_currency_switch_audit` is explicitly described as "AUSTRAC-grade" financial audit; `brand_status_history` is the only record of lifecycle transitions. Neither table has snapshot columns, so the rows simply vanish.

The reference implementation is `core.data_export_audit` (`20260425000002`) — `ENABLE ROW LEVEL SECURITY`, `ON DELETE SET NULL` on `professional_id`, and `*_snapshot` columns for post-deletion identity.

### What to do

- [ ] **Step 1 — Write a single migration that fixes both axes for all three tables.** Create `supabase/migrations/<timestamp>_harden_audit_tables.sql`:
    ```sql
    -- RLS + policies for all three audit tables
    -- (professional_deletion_audit: staff-only; wallet + brand: tenant-scoped + staff-all)

    -- 1. professional_deletion_audit
    ALTER TABLE core.professional_deletion_audit ENABLE ROW LEVEL SECURITY;

    CREATE POLICY professional_deletion_audit_app_backend_all
        ON core.professional_deletion_audit FOR ALL TO app_backend
        USING (true) WITH CHECK (true);

    CREATE POLICY professional_deletion_audit_staff_select
        ON core.professional_deletion_audit FOR SELECT TO authenticated
        USING (EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
        ));

    -- 2. wallet_currency_switch_audit — flip CASCADE to SET NULL + RLS + snapshot column
    ALTER TABLE core.wallet_currency_switch_audit
        ADD COLUMN IF NOT EXISTS professional_handle_snapshot text;

    ALTER TABLE core.wallet_currency_switch_audit
        ALTER COLUMN professional_id DROP NOT NULL;

    ALTER TABLE core.wallet_currency_switch_audit
        DROP CONSTRAINT wallet_currency_switch_audit_professional_id_fkey;

    ALTER TABLE core.wallet_currency_switch_audit
        ADD CONSTRAINT wallet_currency_switch_audit_professional_fk
        FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;

    ALTER TABLE core.wallet_currency_switch_audit ENABLE ROW LEVEL SECURITY;

    CREATE POLICY wallet_currency_switch_audit_app_backend_all
        ON core.wallet_currency_switch_audit FOR ALL TO app_backend
        USING (true) WITH CHECK (true);

    CREATE POLICY wallet_currency_switch_audit_tenant_select
        ON core.wallet_currency_switch_audit FOR SELECT TO authenticated
        USING (professional_id = (
            SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
        ));

    CREATE POLICY wallet_currency_switch_audit_staff_select
        ON core.wallet_currency_switch_audit FOR SELECT TO authenticated
        USING (EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
        ));

    -- 3. brand_status_history — same shape as wallet audit
    ALTER TABLE core.brand_status_history
        ADD COLUMN IF NOT EXISTS professional_handle_snapshot text;

    ALTER TABLE core.brand_status_history
        ALTER COLUMN professional_id DROP NOT NULL;

    ALTER TABLE core.brand_status_history
        DROP CONSTRAINT brand_status_history_professional_id_fkey;

    ALTER TABLE core.brand_status_history
        ADD CONSTRAINT brand_status_history_professional_fk
        FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;

    ALTER TABLE core.brand_status_history ENABLE ROW LEVEL SECURITY;

    CREATE POLICY brand_status_history_app_backend_all
        ON core.brand_status_history FOR ALL TO app_backend
        USING (true) WITH CHECK (true);

    CREATE POLICY brand_status_history_tenant_select
        ON core.brand_status_history FOR SELECT TO authenticated
        USING (professional_id = (
            SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
        ));

    CREATE POLICY brand_status_history_staff_select
        ON core.brand_status_history FOR SELECT TO authenticated
        USING (EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
        ));

    GRANT SELECT, INSERT ON core.professional_deletion_audit TO app_backend;
    GRANT SELECT, INSERT ON core.wallet_currency_switch_audit TO app_backend;
    GRANT SELECT, INSERT ON core.brand_status_history TO app_backend;
    ```
    Verify exact existing constraint names with `\d core.wallet_currency_switch_audit` / `\d core.brand_status_history` before drop — Postgres auto-names FK constraints as `<table>_<column>_fkey` but the v2 baseline occasionally diverges.
- [ ] **Step 2 — Populate `professional_handle_snapshot` at insert time.** Update writers:
    - `app/Services/Professional/BrandStatusService.php` (the raw `DB::table('core.brand_status_history')->insert(...)` call) — add `'professional_handle_snapshot' => $professional->handle`.
    - The wallet-currency-switch writer (locate via `rg "wallet_currency_switch_audit" app/`) — same shape.
- [ ] **Step 3 — Update staff UI for NULL `professional_id`.** Any staff dashboard that joins these audit tables to `core.professionals` must `LEFT JOIN` and render "Deleted account ({handle_snapshot})" when `professional_id IS NULL`. Audit: `rg "brand_status_history|wallet_currency_switch_audit" app/Http/Controllers/Api/Staff/`.
- [ ] **Step 4 — Test coverage.**
    - `tests/Feature/Security/AuditTableRLSTest.php` — assert that an unauthenticated PostgREST request and a wrong-tenant PostgREST request both receive 0 rows from each audit table (uses PostgREST anon key against the test schema).
    - `tests/Feature/Professional/AccountDeletionTest.php` — assert that hard-delete of a professional leaves audit rows intact with `professional_id = NULL` and `professional_handle_snapshot` populated.

### Plain English

Three back-office filing cabinets in the system are missing two safety features:

1. **No lock on the door.** Other filing cabinets check who's at the door and only show files that belong to them; these three show every file to anyone with a valid key. One of them contains "people who asked to be deleted, with their email" — exactly the data you don't want everyone reading.
2. **Auto-shredder set to wrong mode.** When an account is permanently closed, the audit history for that account in two of the cabinets is shredded along with it. The third cabinet correctly keeps the records with a "name on file" note. Two of three were built before that pattern existed; they just need the same setup.

One migration adds the lock and changes the shredder mode for all three cabinets. The code that writes to those cabinets gets a one-line addition to drop the "name on file" note alongside each new row.

### Why this is the second-highest priority

DATA-A#DATA-1's RLS gap is the only finding in the plan that's actively exfiltrating PII today — `professional_email_snapshot` is readable to any authenticated Supabase JWT, no policy check required. The CASCADE finding is the same set of tables and the same migration; bundling them is one PR instead of two. Both are P1, both ship before pilot.

---

## Pattern 3 — Retention purge sweep (Enquiry + ServiceCategory + failed media)

**Closes 4 unique findings (1 P1 · 2 P2 · 1 P2):** DATA-A#DATA-2 (canonical P1), DATA-B#DATA-6 (dup, P2), DATA-C2b#DATA-2 (dup, P2 — adds ServiceCategory), DATA-A#DATA-7 (failed `site_media` accumulating)

**Effort:** ~2h
**Status:** Done — commit 05a13f1 on development, 2026-05-13

### Root cause

`app/Console/Commands/PurgeSoftDeleted.php` purges `Customer::class`, `Service::class`, and `SiteMedia::class` past the 30-day retention window. Three models use the `SoftDeletes` trait but are absent from the purge loop:

- **`Enquiry`** — contains visitor PII from contact-form submissions (name, email, phone, message, ip_hash, user_agent). When a professional archives an enquiry from their inbox, the row is never hard-deleted. The 30-day retention guarantee documented in CLAUDE.md (`SOFT_DELETE_RETENTION_DAYS`) silently fails for this model. Note: `RedactCustomerJob` hard-deletes enquiries by email on GDPR request, so GDPR compliance is intact — first-party retention is what breaks. The P1 framing reflects PII volume (visitors who never registered).
- **`ServiceCategory`** — no PII, but the same `SoftDeletes` + missing-purge pattern. Adding it costs nothing.
- **Failed `SiteMedia` rows** — `processing_state = 'failed'` is a terminal state that `PurgeSoftDeleted` does not GC because the row has `deleted_at IS NULL`. The `enforce_site_gallery_max6` trigger counts failed rows against the 6-slot gallery limit, so a failed video upload permanently reduces effective capacity from 6 to 5.

### What to do

- [ ] **Step 1 — Add `Enquiry` and `ServiceCategory` to `PurgeSoftDeleted`** (`app/Console/Commands/PurgeSoftDeleted.php:31`).
    ```php
    $total += $this->purgeModel(Customer::class, $cutoff);
    $total += $this->purgeModel(Service::class, $cutoff);
    $total += $this->purgeModel(SiteMedia::class, $cutoff);
    $total += $this->purgeModel(Enquiry::class, $cutoff);        // new
    $total += $this->purgeModel(ServiceCategory::class, $cutoff); // new
    ```
    Verify the command is scheduled in `routes/console.php` (currently `'03:20'` daily — confirmed).
- [ ] **Step 2 — Update the gallery trigger to exclude failed rows.** Write `supabase/migrations/<timestamp>_exclude_failed_media_from_gallery_count.sql`:
    ```sql
    CREATE OR REPLACE FUNCTION site.enforce_site_gallery_max6()
    RETURNS TRIGGER LANGUAGE plpgsql AS $$
    DECLARE cnt int;
    BEGIN
        IF NEW.pool <> 'gallery' THEN
            RETURN NEW;
        END IF;
        SELECT count(*) INTO cnt
        FROM site.site_media si
        WHERE si.site_id = NEW.site_id
          AND si.pool = 'gallery'
          AND si.deleted_at IS NULL
          AND si.processing_state <> 'failed'  -- new: failed rows don't count
          AND (TG_OP <> 'UPDATE' OR si.id <> NEW.id);
        IF cnt >= 6 THEN
            RAISE EXCEPTION 'gallery_max6';
        END IF;
        RETURN NEW;
    END;
    $$;
    ```
- [ ] **Step 3 — Add a separate cleanup pass for failed media older than 7 days.** Extend `PurgeSoftDeleted::handle()` with one additional query:
    ```php
    $failedCutoff = now()->subDays(7);
    $failedMediaDeleted = SiteMedia::query()
        ->where('processing_state', 'failed')
        ->where('created_at', '<', $failedCutoff)
        ->each(function (SiteMedia $media) {
            // Delete physical files (variants too — see Pattern 4 Step 2) then forceDelete.
            $media->forceDelete();
        });
    ```
    Use a separate progress counter and log line. The 7-day window is shorter than the 30-day soft-delete retention because a failed upload is not a recoverable user action — it's terminal state.
- [ ] **Step 4 — Test coverage.**
    - `tests/Feature/Commands/PurgeSoftDeletedTest.php` — assert `Enquiry::factory()->create(['deleted_at' => now()->subDays(35)])` is hard-deleted after running the command. Same fixture for `ServiceCategory`.
    - Assert `SiteMedia::factory()->create(['processing_state' => 'failed', 'created_at' => now()->subDays(10)])` is hard-deleted; same fixture at 5 days old is preserved.
    - Update `tests/Feature/Site/GalleryMaxLimitTest.php` (if it exists; create if not) — assert that a failed `SiteMedia` row does not count against the 6-slot limit.

### Plain English

The retention rule says deleted items disappear permanently after 30 days. Three things break that rule today:

1. When a business owner archives a contact-form message, the visitor's name, email, and phone number sit in the database forever. The cleanup script doesn't know to clear them out.
2. Same for service categories the owner archives.
3. When a portfolio upload fails (corrupted file, transcoding error), the broken record takes up one of the six gallery slots and counts against the limit, forever — until the owner manually finds and deletes it. They'd rather upload another file in that slot.

Fixes: two lines added to the daily cleanup script, one trigger update so failed uploads don't count against the slot limit, and a 7-day cleanup for the failed rows themselves.

### Why this is third priority

P1 visitor-PII retention gap is the headline. The other two are P2/P3 quality-of-life but ride in the same PR. Total scope is one command file, one trigger migration, one test class — half a day at most.

---

## Pattern 4 — Professional soft-delete cascade coherence

**Closes 2 unique findings (1 P1 · 1 P2):** DATA-B#DATA-1 (Professional soft-delete leaves public site reachable, child models stale), DATA-B#DATA-2 (SiteMedia force-delete orphans variant files)

**Effort:** ~3h

### Root cause

Two distinct soft-delete coherence gaps, both rooted in the same misalignment between Eloquent `SoftDeletes` and Postgres FK semantics:

**DATA-B#DATA-1:** `Professional` uses `SoftDeletes` (`deleted_at` lifecycle column). The public site endpoint resolves sites by subdomain without joining `core.professionals.deleted_at`. Six child models (`BrandProfile`, `ProfessionalIntegration`, `BrandPartnerLink`, `Subscription`, `EmailSubscription`, `BrandStoreSettings`) hold no `SoftDeletes` themselves and do not filter by the parent's `deleted_at`. The FK CASCADE on `core.professionals` fires only on **hard** delete, not on Eloquent's `delete()` (which only updates `deleted_at`). Net result: a soft-deleted brand's storefront stays live and publicly reachable by subdomain, and every cross-professional query still surfaces the soft-deleted tenant's child rows.

**DATA-B#DATA-2:** `SiteMedia` uses `SoftDeletes`. When `PurgeSoftDeleted` eventually calls `forceDelete()` on a `SiteMedia` row, Postgres CASCADE-deletes the `site.media_variants` rows directly at the DB layer — Eloquent's `MediaVariant::forceDeleted` event never fires. Any observer that uses that event to clean up S3/R2 files for variants is silently bypassed. Variant files orphan on cloud storage, consuming pay-by-byte object storage forever.

### What to do

- [x] **Step 1 — Unpublish the site on `pending_deletion` transition** (`app/Services/Professional/AccountDeletionService.php`).
    - At the point in the state machine where `deletion_confirmed_at` is set (or `pending_deletion` is entered, whichever is the canonical lifecycle hook), explicitly write:
        ```php
        if ($professional->site) {
            $professional->site->forceFill([
                'is_published' => false,
                'unpublished_at' => now(),
            ])->save();
        }
        ```
    - This is the load-bearing fix — it removes public reachability immediately rather than waiting for soft-delete → hard-delete (30+ days later).
- [x] **Step 2 — Audit public-facing routes.** `rg "subdomain" app/Http/Controllers/Api/PublicSite/` — every controller that resolves a site by subdomain must either:
    - Join `core.professionals` and filter `deleted_at IS NULL`, OR
    - Filter `Site::where('is_published', true)` (which Step 1 covers).
    Confirm both layers are present. The public-site payload view (`site.public_site_payload`) should also be audited — it likely already filters `is_published = true` but the join to `professionals.deleted_at` may be implicit-only.
- [x] **Step 3 — Staff-side warning banner for soft-deleted parents.** In every staff controller that loads child models for a professional (`StaffProfessionalController`, `StaffSubscriptionManagementController`, etc.), surface a flag in the response:
    ```php
    'parent_status' => $professional->trashed() ? 'soft_deleted' : 'active',
    ```
    Frontend renders a banner. Low priority but closes the "staff sees stale data with no signal" gap.
- [x] **Step 4 — Force-deleted observer on `SiteMedia`** (`app/Models/Core/Site/SiteMedia.php` or new `app/Observers/SiteMediaObserver.php`).
    ```php
    protected static function booted(): void
    {
        static::forceDeleting(function (SiteMedia $media) {
            // Collect variant paths BEFORE the cascade fires.
            $variantPaths = $media->mediaVariants()->pluck('path')->all();

            // After the DB row deletes (cascade fires automatically), clean up storage.
            $disk = Storage::disk($media->media_disk ?? config('partna.media.default_disk'));
            foreach ($variantPaths as $path) {
                if ($path) {
                    $disk->delete($path);
                }
            }
        });
    }
    ```
    Use `forceDeleting` (before-event), not `forceDeleted` — variant rows are gone after the cascade. Pre-collect paths from the relation, then the cascade runs, then clean up storage.
- [x] **Step 5 — Document the contract on `MediaVariant`.**
    Add a docblock to `app/Models/Core/MediaVariant.php` noting: "Wholly owned by parent `SiteMedia`. Lifecycle: parent's `forceDeleting` observer collects variant paths and deletes storage; DB CASCADE removes variant rows. Do not call `MediaVariant::delete()` directly."
- [x] **Step 6 — Test coverage.**
    - `tests/Feature/Professional/AccountDeletionTest.php` — assert that soft-deleting a `Professional` flips `Site::is_published` to false and sets `unpublished_at`. Assert public-site endpoint returns 404 for the subdomain after soft-delete (not 200 with cached data).
    - `tests/Feature/Site/SiteMediaForceDeleteTest.php` — use `Storage::fake()`. Create a `SiteMedia` with two `MediaVariant` rows pointing at faked storage paths. Call `forceDelete()`. Assert all variant files are removed from storage, and DB rows are gone.

### Plain English

**Site stays live after deletion:** Deleting a brand account marks the brand's main file as "in the trash." But the brand's actual storefront — the public-facing shop people visit — is a separate record that doesn't know about that trash mark. So a deleted brand's shop keeps serving requests indefinitely, complete with all the linked profiles and integrations. The fix is: the moment we mark the account deleted, also flip the shop's "published" switch off. The whole system already respects that switch, so the shop disappears immediately.

**Variant files orphan in cloud storage:** When a photo is permanently deleted from the system, the database wipes the photo record and all of its different size copies (thumbnail, full-size, etc.). But the actual image files in cloud storage only get cleaned up because the system listens for a "photo deleted" event — and the size-copies are deleted at the database layer in a way that doesn't trigger an event. So the size-copy files sit in cloud storage forever, costing money. The fix is: before the database cascade fires, we grab the list of file paths, then clean them up afterward.

### Why this is fourth priority

DATA-B#DATA-1 is P1 because a public-facing storefront for a deleted account is the kind of finding that makes legal nervous — "we deleted them but their shop kept selling" is not a great look. Step 1 alone closes the public exposure in a one-line change; the rest is defence-in-depth. DATA-B#DATA-2 is P2 (cost, not security) but lives in the same conceptual file (soft-delete coherence) and lands cleaner as one PR.

---

## Pattern 5 — PII inventory hardening (`$hidden` + log scrubbing + pre-purge redaction)

**Closes 4 unique findings (1 P1 · 3 P2):** DATA-C2a#DATA-1 (Supabase admin logs raw email — P1), DATA-A#DATA-3 (pending_deletion grace period leaves PII readable — P2), DATA-B#DATA-3 (`WaitlistSignup` no `$hidden` — P2), DATA-B#DATA-4 (`Enquiry` no `$hidden` — P2)

**Effort:** ~3h

### Root cause

PII reaches surfaces it shouldn't via four distinct paths:

1. **Log aggregator** — `SupabaseAdminService::createUser()` logs the full email on every Supabase user-creation failure. Transient failures (network blips, Supabase 5xx) compound across retries: 10 retry attempts → 10 emails in Nightwatch. Log aggregators have their own retention schedules unrelated to GDPR.
2. **Live DB columns post-deletion-confirmed** — When a professional confirms account deletion, `deletion_confirmed_at` is stamped but `phone`, `primary_email`, `first_name`, `last_name`, and `location_*` columns are untouched for the full 30-day grace period. Staff and the professional themselves still see live PII.
3. **Eloquent model serialisation** — `WaitlistSignup` and `Enquiry` both store PII (`name`, `email`, `phone`, etc.) in `$fillable` with no matching `$hidden`. Any `$model->toArray()` call — queue serialisation, log statements, broadcast events — emits raw PII. `WaitlistSignup` notably hides `consent_ip_hash` (the team understood the pattern) but not the actual contact details. `BrandAffiliateInvite` is the codebase exemplar: it correctly hides `email`, `email_lc`, `phone`, `first_name`, `last_name`, `message`, `token`.
4. **`Enquiry` notification jobs** carrying enquiry context emit submitter identity via implicit `toArray()` in payload serialisation.

### What to do

- [x] **Step 1 — Scrub Supabase admin logs** (`app/Services/Auth/SupabaseAdminService.php:69-73`). _Closed by commit `a90e1e7` — only one `Log::error` lived in the class; `email_fingerprint` helper added, callers audited (BrandSignupService logs no emails)._
    Replace `'email' => $email` in both `Log::error` calls with `'email_fingerprint' => hash('sha256', strtolower(trim($email)))`. A SHA-256 hex prefix is enough to correlate retries across log lines without storing the address. Add a one-line helper `private function emailFingerprint(string $email): string` at the bottom of the class and use it everywhere `$email` would otherwise hit a log.
    - **Audit the rest of the class** with `rg "Log::|->log(" app/Services/Auth/SupabaseAdminService.php` to catch any other email-bearing log calls. Apply the same fingerprint pattern.
    - **Audit callers:** `rg "SupabaseAdminService" app/` — `BrandSignupService`, setup wizard controllers. Same scan: anywhere `$email` is in a log array, replace with the fingerprint helper.
- [x] **Step 2 — Pseudonymise PII columns on `deletion_confirmed_at` stamp** (`app/Services/Professional/AccountDeletionService.php` — the `confirm()` or equivalent state-machine handler). _Closed by commit `a90e1e7`. Extracted to private `pseudonymiseAccountPii()` called AFTER `logAuditEvent()` so the EVENT_CONFIRMED audit row captures real email; `cancel()` / `adminCancel()` now restore primary_email from the audit snapshot via `restoreEmailFromAuditSnapshot()`._
    At the point where `deletion_confirmed_at` is set:
    ```php
    $professional->forceFill([
        'phone' => 'redacted',
        'primary_email' => "deleted+{$professional->id}@partna.au",
        'first_name' => 'Deleted',
        'last_name' => null,
        'location_street_address' => null,
        'location_postcode' => null,
        'location_city' => null,
        'location_state' => null,
        'location_country' => null,
    ])->save();
    ```
    - `core.professional_deletion_audit` already snapshots `professional_email_snapshot` and `professional_handle_snapshot`, so account-recovery flows can re-hydrate identity from the audit row without keeping live PII columns intact.
    - Keep `handle`, `display_name`, `auth_user_id`, `deleted_at`, `status` intact for the recovery window — these are required for "undo deletion within 30 days" to function.
    - This change interacts with `RedactCustomerJob` (Pattern 6) — they target different actors (account-owner deletion vs. customer-of-brand erasure request) but the column-clearing logic should be extracted to a shared trait or helper if both grow.
- [x] **Step 3 — Add `$hidden` to `WaitlistSignup`** (`app/Models/Core/Waitlist/WaitlistSignup.php`). _Closed by commit `a90e1e7`. GDPR `customers/redact` scope coverage for waitlist signups remains as a separate follow-up (no EU applicants yet)._
    ```php
    protected $hidden = [
        'consent_ip_hash',
        'consent_user_agent',
        'name',          // new
        'email',         // new
        'email_lc',      // new
        'phone',         // new
    ];
    ```
    Audit all `WaitlistSignup` usage for legitimate display paths and route them through a dedicated `WaitlistSignupResource` that explicitly surfaces these fields. Confirm: GDPR `customers/redact` scope covers waitlist signups (currently does not — flag as a follow-up if EU applicants exist).
- [x] **Step 4 — Add `$hidden` to `Enquiry`** (`app/Models/Core/Site/Enquiry.php`). _Closed by commit `a90e1e7`. `EnquiryResource` already exists; uses direct attribute access (`$this->email`) and is unaffected by `$hidden`._
    ```php
    protected $hidden = [
        'email',
        'phone',
        'name',
        'ip_hash',
        'user_agent',
    ];
    ```
    The GDPR redact path already hard-deletes enquiry rows by email (verified in `RedactCustomerJob`); no change to that path. Create an `EnquiryResource` for any controller surfacing enquiry data to authenticated professionals.
- [x] **Step 5 — Audit other PII-bearing models for similar gaps.** _Closed by commit `a90e1e7` (audit only — no implementation; follow-ups recorded below)._ Four gaps identified, all out of scope per the audit:
    - `Customer` — `$fillable` contains `email`, `phone`, `full_name`, `notes`; `$hidden` only covers `external_id`.
    - `EmailSubscription` — `$fillable` contains `email`, `full_name`, `email_lc`; `$hidden` covers token + telemetry but not the PII.
    - `PartnaStaff` — `$fillable` contains `primary_email`, `name`, `phone`; `$hidden` only covers `auth_user_id`.
    - `Professional` — `$fillable` contains `primary_email`, `phone`, `first_name`, `last_name`, `public_contact_*`; `$hidden` covers Stripe + deletion_token_hash + auth_user_id but not the PII. (Deletion path is now covered by Step 2 pseudonymisation, but whole-model serialisation paths — queue, broadcast, naked `Log::info($pro)` — still leak.)
- [x] **Step 6 — Test coverage.** _Closed by commit `a90e1e7` via TDD._
    - `tests/Unit/Auth/SupabaseAdminServiceTest.php` — fingerprint assertion added (`it('logs an email_fingerprint instead of the raw email on createUser failure')`).
    - `tests/Feature/Professional/AccountDeletion/ConfirmDeletionTest.php` — pseudonymisation + confirm→cancel round-trip tests added; `AccountDeletionTestCase` schema extended with the PII columns.
    - `tests/Unit/Models/WaitlistSignupTest.php` (new) and `tests/Unit/Models/EnquiryModelTest.php` — both assert PII keys do not appear in `toArray()`.

### Plain English

**PII in logs:** Every time the system can't create a Supabase account (which happens during retry storms — even one slow internet moment causes many retries), we write the person's email into the monitoring system's logs. The monitoring system has its own deletion schedule that has nothing to do with privacy law. If a user later asks us to delete their data, we can wipe the database, but the email is in the logs and stays there. The fix is to scramble the email into a fingerprint — enough to tell which user the error was about, not enough to identify anyone.

**Live PII during the grace period:** When a user confirms account deletion, we keep their data for 30 days in case they change their mind. Right now that data is fully intact and readable by staff. The recovery only needs the user's handle and login ID — the actual personal details (phone, address, real name) can be replaced with placeholders immediately. We already keep a snapshot of the email in an audit table for recovery — so the live record doesn't need to.

**Models leak PII when serialised:** When the system writes a waitlist signup or contact-form message into a background job, log, or notification, the visitor's name and email travel completely exposed. Every other PII-bearing model in the system has a privacy wrapper that hides those fields by default; these two were missed.

### Why this is fifth priority

DATA-C2a#DATA-1 is the clearest P1: log aggregators are not a controlled PII surface, retention is opaque, and the breach happens on every retry storm. DATA-A#DATA-3 is GDPR Article 17 — "without undue delay" — and the recovery skeleton is already in the audit table. The `$hidden` gaps are P2 but ride free with the PII-hardening mental model. One PR to four files (`SupabaseAdminService`, `AccountDeletionService`, `WaitlistSignup`, `Enquiry`).

---

## Pattern 6 — GDPR redact-path completeness

**Closes 2 unique findings (2 P2):** DATA-A2#DATA-6 (global `email_subscriptions` no erasure path), DATA-B#DATA-5 (`RedactCustomerJob` does not null `analytics.lead_submissions.customer_id`)

**Effort:** ~2h

### Root cause

The GDPR redact path is built around `RedactCustomerJob` (Shopify customer/redact webhook). Two gaps in coverage:

1. **Global newsletter subscribers** (`notifications.email_subscriptions WHERE professional_id IS NULL`) — the platform-wide marketing list. `RedactCustomerJob:104-108` filters `WHERE professional_id = $professionalId`, so global rows are never touched. The `unsubscribe_token` mechanism grants opt-out rights but not erasure — under GDPR Article 17 these are distinct obligations.
2. **`analytics.lead_submissions.customer_id`** — the FK to `core.customers` has `ON DELETE SET NULL`, but `RedactCustomerJob` *anonymises* the customer row rather than deleting it, so the FK trigger never fires. Lead submissions retain a live `customer_id` pointing at the anonymised customer plus `ip_hash` and `user_agent` recorded at form-submission time. `ExportCustomerDataJob` includes lead submissions in the export payload — confirming the data is PII-bearing — but the redact path is asymmetric.

### What to do

- [ ] **Step 1 — Add `analytics.lead_submissions` cleanup to `RedactCustomerJob`** (`app/Jobs/Shopify/Gdpr/RedactCustomerJob.php`).
    After the customer-anonymisation block and before the success log:
    ```php
    $scrubbedLeads = DB::connection('pgsql')
        ->table('analytics.lead_submissions')
        ->where('customer_id', $customer->id)
        ->update([
            'customer_id' => null,
            'ip_hash' => null,
            'user_agent' => null,
        ]);
    ```
    Add `$scrubbedLeads` to the completion log line alongside `$deletedSubs` / `$deletedEnquiries` so the redaction count is visible in Nightwatch.
- [ ] **Step 2 — Add the corresponding cleanup to `RedactShopJob`** if `lead_submissions` rows are also linked by `professional_id` (audit the migration). Same shape: scrub `customer_id`, `ip_hash`, `user_agent` for rows under the shop's professional.
- [ ] **Step 3 — Create staff-triggered erasure endpoint for global subscribers** (or extend an existing GDPR-request controller).
    - Endpoint shape: `POST /staff/gdpr/erase-newsletter-subscriber` with body `{ email: string }`. Behind staff auth + policy gate.
    - Handler: `DB::connection('pgsql')->table('notifications.email_subscriptions')->whereNull('professional_id')->where('email_lc', strtolower($request->input('email')))->delete();`
    - Log to `core.gdpr_requests` table (the standard erasure-request audit) with `request_type = 'erasure'`, `subject_email_hash = sha256($email)`, `actioned_by_staff_id = $staff->id`.
    - **Alternative (lower-effort):** Extend `RedactCustomerJob` to also delete the global row: add `OR (professional_id IS NULL AND email_lc = $emailLc)` to the existing `WHERE`. Trade-off: a Shopify customer/redact webhook now affects platform-wide subscriptions too, which may be desired (one redact request → all subscriptions vanish) or surprising (a Shopify-brand-scoped request silently affects Partna's own list). Recommend the staff endpoint variant for explicitness.
- [ ] **Step 4 — Update `ExportCustomerDataJob` test** to assert that post-redact, the customer has zero remaining linked lead submissions (`customer_id IS NULL` for any row that previously referenced them).
- [ ] **Step 5 — Test coverage.**
    - `tests/Feature/Gdpr/RedactCustomerJobTest.php` — extend to assert `analytics.lead_submissions` rows are nulled. Use a fixture with two lead submissions: one linked, one not. After redact, the linked one has `customer_id = NULL` and `ip_hash = NULL`; the unrelated one is untouched.
    - `tests/Feature/Staff/GdprErasureTest.php` — staff erasure endpoint integration test. Two fixtures: subscriber with `professional_id = NULL` and subscriber with `professional_id = $other`. Assert only the global one is deleted.

### Plain English

The "delete my data" workflow is mostly correct, but it misses two spots:

1. **Platform newsletter:** If someone signs up for Partna's own newsletter (separate from any specific brand's list), and later asks us to delete their data, we currently have no way to do it. They can unsubscribe — which stops emails — but their record stays in the database. Unsubscribing is taking yourself off the call list; data deletion is shredding the card. We owe the second one too.
2. **Form-submission analytics:** When someone submits a contact form on a brand's site, we log it for analytics. The "delete my data" job correctly wipes the user's profile, email subscriptions, and the actual enquiry message, but it doesn't touch the analytics log entry — which still has a reference to the (now scrambled) profile, plus the browser fingerprint from when they filled out the form. The data-export job already knows to include this log; the deletion job should be symmetric.

### Why this is sixth priority

Both P2. P1-tier patterns ship first because they have current-user impact at pilot scale; Pattern 6 has zero-customer impact today but ships before any GDPR request can be processed under the current shape. Half a day of work, two files plus a staff endpoint.

---

## Pattern 7 — CHECK constraint sweep on enum-like columns

**Closes 8 unique findings (8 P2 + 1 P3 absorbed):** DATA-A2#DATA-3 (`site.blocks.block_type`), DATA-A2#DATA-4 + DATA-D#DATA-2 (`site.site_media.pool` — dup), DATA-A2#DATA-8 (`billing.subscriptions.status`), DATA-A#DATA-4 (`commerce.commission_movements.rate_source`), DATA-A#DATA-5 (`core.professional_integrations.provider`), DATA-A#DATA-8 + DATA-B#DATA-7 (`notifications.email_subscriptions.status` — dup), DATA-D#DATA-3 (`core.partna_staff.role`), DATA-C2b#DATA-3 (`core.brand_status_history.from_status`/`to_status`)

**Effort:** ~1 day (single migration, multiple ALTERs)

### Root cause

Eight columns the application treats as enums have no DB-level CHECK constraint. The pattern: application code uses an exact-string `=` comparison (`role === 'admin'`, `pool === 'gallery'`, `status === 'subscribed'`); any other string the DB accepts produces silent failure. A typo, a future schema-drifting bulk import, or a raw `DB::update()` writes the bad value; downstream code reads a value it doesn't recognise and falls through to a "this user has no permissions" / "this image is in no pool" / "this subscriber is unsubscribed" silent state.

| Column | Application enum | Effect of typo |
|--------|------------------|----------------|
| `site.blocks.block_type` | link/service/contact/gallery/about/... | Hydrogen has no component for the unknown type — site renders nothing for that block |
| `site.site_media.pool` | gallery/content/design/product/brand_gallery/documents | Row is invisible to every read path and bypasses gallery-limit enforcement |
| `billing.subscriptions.status` | Stripe lifecycle: trialing/active/incomplete/past_due/canceled/unpaid/paused | Subscription-gating logic enters unhandled branch |
| `commerce.commission_movements.rate_source` | product_metafield/metafield_override/brand_default/platform_default/manual/pending | Cross-reconciliation with `commerce.orders.rate_source` (already constrained) breaks |
| `core.professional_integrations.provider` | shopify | Integration silently fails — `WHERE provider = 'shopify'` finds nothing |
| `notifications.email_subscriptions.status` | subscribed/unsubscribed | `isMarketingOptedIn()` returns false; user silently stops receiving emails |
| `core.partna_staff.role` | admin/support | Staff member authenticates but has zero admin access; no error message |
| `core.brand_status_history.from_status`/`to_status` | BrandStatus enum (7 values) | History rows accept any string; audit-trail parsing tools break |

### What to do

- [ ] **Step 1 — Write a single migration with one `ADD CONSTRAINT ... NOT VALID` per column, then a `VALIDATE CONSTRAINT` pass.** Per Phase 4 Pattern 2 convention, the two-step approach is safe on any table size:
    ```sql
    -- supabase/migrations/<timestamp>_add_enum_check_constraints.sql

    -- 1. Add as NOT VALID (lock-light, instant)
    ALTER TABLE site.blocks
        ADD CONSTRAINT blocks_block_type_check
        CHECK (block_type IN ('link', 'service', 'contact', 'gallery', 'about', 'hero', 'testimonials')) NOT VALID;
        -- Cross-reference against the live block-type picker in dashboard JS / app/Enums/ before merging.

    ALTER TABLE site.site_media
        ADD CONSTRAINT site_media_pool_check
        CHECK (pool IN ('gallery', 'content', 'design', 'product', 'brand_gallery', 'documents')) NOT VALID;
        -- Confirm exact list from config/sidest.php image_pools keys.

    ALTER TABLE billing.subscriptions
        ADD CONSTRAINT subscriptions_status_check
        CHECK (status IN ('trialing', 'active', 'incomplete', 'incomplete_expired', 'past_due', 'canceled', 'unpaid', 'paused')) NOT VALID;
        -- Pin against STRIPE_API_VERSION; revisit on upgrade.

    ALTER TABLE commerce.commission_movements
        ADD CONSTRAINT commission_movements_rate_source_check
        CHECK (rate_source IN ('product_metafield', 'metafield_override', 'brand_default', 'platform_default', 'manual', 'pending')) NOT VALID;

    ALTER TABLE core.professional_integrations
        ADD CONSTRAINT professional_integrations_provider_check
        CHECK (provider IN ('shopify')) NOT VALID;

    ALTER TABLE notifications.email_subscriptions
        ADD CONSTRAINT email_subscriptions_status_check
        CHECK (status IN ('subscribed', 'unsubscribed')) NOT VALID;

    ALTER TABLE core.partna_staff
        ADD CONSTRAINT partna_staff_role_check
        CHECK (role IN ('admin', 'support')) NOT VALID;

    ALTER TABLE core.brand_status_history
        ADD CONSTRAINT brand_status_history_from_status_check
        CHECK (from_status IS NULL OR from_status IN ('onboarding', 'shopify_linked', 'shopify_configured', 'storefront_live', 'ready_for_affiliates', 'disconnected', 'systems_down')) NOT VALID;

    ALTER TABLE core.brand_status_history
        ADD CONSTRAINT brand_status_history_to_status_check
        CHECK (to_status IN ('onboarding', 'shopify_linked', 'shopify_configured', 'storefront_live', 'ready_for_affiliates', 'disconnected', 'systems_down')) NOT VALID;

    -- 2. Validate in a separate transaction (lock-light, but check existing values first)
    -- Run from psql, NOT in the migration file:
    -- ALTER TABLE site.blocks VALIDATE CONSTRAINT blocks_block_type_check;
    -- (etc. — one VALIDATE per constraint, each in its own transaction)
    ```
- [ ] **Step 2 — Inspect existing values before VALIDATE.** For each constraint:
    ```sql
    SELECT DISTINCT block_type FROM site.blocks WHERE block_type NOT IN ('link', 'service', ...);
    ```
    If any rows have non-conforming values: write a one-shot `UPDATE` migration to fix them before `VALIDATE`. Most tables are near-empty pre-launch so this is fast.
- [ ] **Step 3 — Document enum/CHECK pairing.** For each enum class in `app/Enums/`, add a one-line PHPDoc reference to the corresponding DB CHECK constraint: `@see supabase/migrations/<timestamp>_add_enum_check_constraints.sql`. Closes the documentation gap the audits identified.
- [ ] **Step 4 — Add a CI lint or test sweep** asserting that every `app/Enums/` class has a corresponding DB CHECK constraint in the migration set. Long-term institutional fix — drop on follow-up if not feasible.
- [ ] **Step 5 — Test coverage.**
    - `tests/Feature/Database/CheckConstraintsTest.php` — one test per constraint that asserts:
        ```php
        expect(fn () => DB::insert("INSERT INTO core.partna_staff (auth_user_id, role) VALUES (?, 'admin ')", [$uuid]))
            ->toThrow(QueryException::class);
        ```
        Use `expectException(QueryException::class)` on `'admin '` (trailing space), `'Admin'` (capitalised), etc.

### Plain English

Eight different columns in the database act like "pick one of these" choices: a staff role is admin or support, a media file is in the gallery or design pool, a subscription is active or trialing, etc. The application code always checks for the exact spelling. But the database itself has no rule saying which values are allowed — anyone with raw write access can store a typo, and the application then silently treats that row as if it doesn't exist (or has no permissions, or is unsubscribed, etc.). Adding the rules is a single migration with one short statement per column.

### Why this is seventh priority

Largest single block of findings (8 unique, after dedup) but lowest individual severity (all P2/P3) and mechanical implementation. Slotting after the P1 patterns means reviewer attention is on functional changes first; this PR is a sweep that one reviewer can approve in a single pass.

---

## Pattern 8 — FK index sweep

**Closes 3 unique findings (3 P2/P3):** DATA-A2#DATA-1 (`site.sites.theme_id`), DATA-A2#DATA-2 (`commerce.affiliate_product_selections.brand_professional_id`), DATA-A#DATA-9 (`core.wallet_currency_switch_audit.topup_id` — also a soft-FK gap, addressed under Pattern 2)

**Effort:** ~1h

### Root cause

Postgres never auto-creates indexes on FK columns. When a referenced row is deleted (or the FK is `SET NULL`'d), Postgres must locate every row that references it — a full sequential scan without an index. Three FK columns in the schema lack supporting indexes:

| Column | FK behaviour | Lookup type |
|--------|-------------|-------------|
| `site.sites.theme_id` | `ON DELETE SET NULL` | Theme deletion scans all sites |
| `commerce.affiliate_product_selections.brand_professional_id` | `ON DELETE CASCADE` from `core.professionals` | Brand hard-delete scans table; brand-scoped RLS policy can't use the composite `(affiliate_pro, brand_pro)` index for brand-alone filters |
| `core.wallet_currency_switch_audit.topup_id` | Soft FK (no DB constraint — see Pattern 2 for the related FK + RLS work) | "What currency switch resulted from this topup?" full-scans the audit table |

### What to do

- [ ] **Step 1 — Single migration with three `CREATE INDEX CONCURRENTLY` (Phase 4 Pattern 2 convention — outside any `BEGIN`/`COMMIT`).** Write `supabase/migrations/<timestamp>_add_missing_fk_indexes.sql`:
    ```sql
    -- No BEGIN; — CREATE INDEX CONCURRENTLY cannot run in a transaction.

    CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_sites_theme_id
        ON site.sites (theme_id) WHERE theme_id IS NOT NULL;

    CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_aps_brand_professional_id
        ON commerce.affiliate_product_selections (brand_professional_id);

    CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_wcsa_topup_id
        ON core.wallet_currency_switch_audit (topup_id) WHERE topup_id IS NOT NULL;
    ```
    Pattern 2's FK addition (Pattern 6 of this plan, for `wallet_currency_switch_audit.topup_id`) and this index can land in the same migration if the existing v2 baseline lookup pattern allows (verify `\d core.wallet_currency_switch_audit` shows the new FK column is otherwise constraint-free).
- [ ] **Step 2 — Verify partial-index applicability.** The `sites.theme_id` index is partial because `site_default_theme` trigger ensures most sites have `theme_id` set, so the partial form stays compact. The `topup_id` partial covers the same nullability.
- [ ] **Step 3 — Test coverage.** Not strictly necessary — indexes are silent perf — but a `tests/Feature/Database/IndexCoverageTest.php` sweep asserting every FK has a supporting index (via `information_schema.referential_constraints` joined to `pg_index`) catches the next missed addition.

### Plain English

When a database deletes a row that other rows refer to (a theme, a brand professional), Postgres has to scan every potentially-referring row to clean them up. Without an index it reads the whole table; with an index it jumps to the relevant rows. Three FK columns are missing this index. Brand-professional deletion currently scans the full affiliate-selections table — fine today (tiny table), slow at a million selections. The fix is one migration with three index creations.

### Why this is eighth priority

All three are P2/P3 perf. No current customer impact. Mechanical single-migration. Ships near the end so the migration-convention work (Phase 4 Pattern 2) has bedded in.

---

## Pattern 9 — `updated_at` trigger backfill on 13 mutable tables

**Closes 1 unique finding (1 P2):** DATA-A2#DATA-7

**Effort:** ~2h

### Root cause

`public.set_updated_at()` is the schema's `BEFORE UPDATE` trigger function. The baseline migration applies it to a core set of tables (`professionals`, `customers`, `sites`, `blocks`, `site_media`, `commission_ledger_entries`, billing tables). 13 mutable tables with `updated_at timestamptz` columns were created without the corresponding trigger binding.

Eloquent's ORM sets `updated_at` in PHP on `Model::save()`, but four code paths bypass Eloquent:

1. **Raw `DB::update()` / `DB::statement` calls** — e.g., `RedactCustomerJob`'s direct table updates, `BrandStatusService::sync()` raw inserts.
2. **Queue-job bulk operations** — `update()` on a query builder vs. iterating models.
3. **Trigger-fired side effects** — e.g., `commerce.brand_affiliate_rollup` updates from order triggers.
4. **Supabase dashboard edits** — direct SQL through the Supabase UI.

For tables without the DB trigger, `updated_at` stays at its old value after any of these paths writes the row. CLAUDE.md documents that the SWR commerce-side caches are "push-invalidated on every commerce write" — this guarantee silently fails for tables where a non-Eloquent path writes the row.

### What to do

- [x] **Step 1 — Add triggers in a single migration.** Write `supabase/migrations/<timestamp>_add_updated_at_triggers.sql`:
    ```sql
    CREATE OR REPLACE TRIGGER set_timestamp_services
        BEFORE UPDATE ON site.services
        FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

    CREATE OR REPLACE TRIGGER set_timestamp_enquiries
        BEFORE UPDATE ON site.enquiries
        FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

    CREATE OR REPLACE TRIGGER set_timestamp_brand_profiles
        BEFORE UPDATE ON brand.brand_profiles
        FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

    CREATE OR REPLACE TRIGGER set_timestamp_brand_partner_links
        BEFORE UPDATE ON brand.brand_partner_links
        FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

    CREATE OR REPLACE TRIGGER set_timestamp_brand_affiliate_invites
        BEFORE UPDATE ON brand.brand_affiliate_invites
        FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

    CREATE OR REPLACE TRIGGER set_timestamp_brand_store_settings
        BEFORE UPDATE ON brand.brand_store_settings
        FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

    CREATE OR REPLACE TRIGGER set_timestamp_affiliate_product_selections
        BEFORE UPDATE ON commerce.affiliate_product_selections
        FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

    CREATE OR REPLACE TRIGGER set_timestamp_notifications
        BEFORE UPDATE ON notifications.notifications
        FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

    CREATE OR REPLACE TRIGGER set_timestamp_notification_receipts
        BEFORE UPDATE ON notifications.notification_receipts
        FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

    CREATE OR REPLACE TRIGGER set_timestamp_notification_email_preferences
        BEFORE UPDATE ON notifications.notification_email_preferences
        FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

    CREATE OR REPLACE TRIGGER set_timestamp_notification_email_policies
        BEFORE UPDATE ON notifications.notification_email_policies
        FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

    CREATE OR REPLACE TRIGGER set_timestamp_email_subscriptions
        BEFORE UPDATE ON notifications.email_subscriptions
        FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

    CREATE OR REPLACE TRIGGER set_timestamp_gdpr_requests
        BEFORE UPDATE ON core.gdpr_requests
        FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
    ```
- [x] **Step 2 — Verify the trigger function exists.** `\df public.set_updated_at` in psql. If it doesn't (baseline drift), the migration is incomplete; locate or recreate.
- [x] **Step 3 — Add a CI sweep.** Write `tests/Feature/Database/UpdatedAtTriggerCoverageTest.php` that asserts every table with an `updated_at timestamptz` column has a `set_updated_at` trigger registered in `information_schema.triggers`. Catches the next omission.

### Plain English

Every row in the database has a "last modified" timestamp. The app correctly bumps this timestamp every time it saves a record. But several other paths write to rows directly without going through the app: background jobs, database scripts, the Supabase admin dashboard. In those cases the timestamp stays at its old value — making caches think nothing changed and incident timelines look stale. The fix is a database-level trigger on each table that bumps the timestamp no matter who writes the row. 13 tables are missing it; one migration adds them all.

### Why this is ninth priority

P2 but mechanical and low-risk. Sequencing last so the soft-delete / GDPR / CHECK work has shipped first; this migration is purely additive and breaks nothing.

---

# Part 2 — Standalone fixes

Six fixes that don't share a root-cause pattern with anything else. Each can land independently in any order after Part 1; PR bundling notes in Appendix A indicate two that pair naturally.

## DATA-A#DATA-6 · P2 — `commerce.brand_affiliate_rollup` has no documented or implemented rebuild procedure

- **Where:** `supabase/migrations/20260506000000_create_orders_schema.sql` (rollup trigger definitions)
- **Effort:** M (~3h)
- **What to do:**
    - Add `app/Console/Commands/RebuildBrandAffiliateRollup.php` (Artisan command). Workflow:
        1. `TRUNCATE commerce.brand_affiliate_rollup` (inside a transaction with `LOCK TABLE`).
        2. Iterate `commerce.orders WHERE status NOT IN ('stub','cancelled','voided')` and apply `rollup_apply_delta()` semantics from PHP — or, more simply, `INSERT INTO commerce.brand_affiliate_rollup SELECT ...` aggregating from `commerce.orders` directly.
        3. Apply `commerce.commission_movements WHERE entry_type = 'clawback'` updates to `reversed_commission_cents`.
        4. Re-enable triggers if disabled.
    - Add a nightly integrity check command (`partna:audit-rollup-integrity`) that runs:
        ```sql
        SELECT
            b.brand_professional_id,
            SUM(o.commission_cents) - r.commission_cents AS delta
        FROM commerce.orders o
        JOIN commerce.brand_affiliate_rollup r ON r.brand_professional_id = o.brand_professional_id
        WHERE o.status NOT IN ('stub','cancelled','voided')
        GROUP BY b.brand_professional_id, r.commission_cents
        HAVING SUM(o.commission_cents) - r.commission_cents != 0;
        ```
        Alert via Nightwatch on any non-zero delta.
    - Document the procedure in `docs/runbooks/rollup-rebuild.md` (or wherever existing runbooks live).
- **Plain English:** The brand dashboard's "earned commissions" number comes from a summary table that auto-updates on every order. If something ever knocks the summary out of sync (a database restore, a bulk operation with triggers disabled, a future migration), the dashboard shows wrong numbers and nobody notices until a brand complains. We need a one-command rebuild that recomputes the summary from the actual order records, plus a nightly sanity check that yells if the two numbers ever disagree.

## DATA-C1#DATA-2 · P2 — Shopify `shop/update` and `themes/publish` webhooks have Redis-only dedup

- **Where:** `app/Http/Controllers/Api/Webhooks/ShopifyShopUpdateWebhookController.php:60-64` and `ShopifyThemePublishedWebhookController.php:55`
- **Effort:** S (~1h)
- **What to do:**
    - Pass `$webhookId` (already extracted from the `X-Shopify-Webhook-Id` header) into `ProcessShopifyShopUpdateJob` and `SyncShopifyBrandDesignJob`.
    - Inside each job, use the same DB-level dedup pattern as `ProcessShopifyOrderWebhookJob`: `WebhookEvent::firstOrCreate(['shopify_event_id' => $webhookId], [...])` and return early if the event already exists.
    - Alternative: `Cache::add("shopify:webhook:{$webhookId}", true, 86400)` inside the job — survives Redis flush if the dedup window is short enough that 24h covers Shopify's redelivery window. The DB approach is more durable.
- **Plain English:** Most Shopify notifications use a two-lock system — a fast lock in memory, plus a permanent lock in the database. Two notification types (shop-updated and theme-published) only have the memory lock. If the memory store is cleared (which happens on every deploy), a duplicate notification slips through. Today the worst-case is "import the same data twice" (harmless). But after a brand customises shop settings, a delayed duplicate notification could silently overwrite the customisation. One hour of work brings these two endpoints in line with the rest of the family.

## DATA-C2a#DATA-2 · P2 — `CommissionPayoutItem` hard-deleted on refund, severing per-order audit chain

- **Where:** `app/Services/Stripe/CommissionPayoutRefundService.php:113-118` and `app/Services/Stripe/CommissionPayoutService.php` (`revalidatePayoutOrders()`)
- **Effort:** M (~3h)
- **What to do:**
    - Add a migration: `ALTER TABLE commerce.commission_payout_items ADD COLUMN status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'removed_by_refund')), ADD COLUMN removed_at timestamptz, ADD COLUMN removal_reason text;`.
    - Replace `$item->delete()` calls with `$item->forceFill(['status' => 'removed_by_refund', 'removed_at' => now(), 'removal_reason' => 'refund'])->save()`.
    - Add `WHERE status = 'active'` scope to every aggregation query that sums `amount_cents` across payout items. Audit with `rg "commission_payout_items" app/` and `rg "CommissionPayoutItem" app/`.
    - Note: the existing CI test (`29b7eb1`) that asserts financial models do not use Laravel `SoftDeletes` is not violated — `status` is an explicit column, not the trait.
- **Plain English:** When a refund cancels part of a payout, the system erases the line items for the cancelled orders. The totals at the bottom of the payout still add up, but six months later (or during a tax audit), there's no way to prove which orders were originally included or which ones were refunded out. The fix is to mark items as "removed by refund" rather than erasing them — same end state for the math, full audit trail preserved. Australian financial recordkeeping requires 7 years; one column closes the gap.

## DATA-C2a#DATA-3 · P3 — Two services bypass `NotificationPublisher::publish()`

- **Where:** `app/Services/Store/SelectionCleanupService.php:62`, `app/Services/Professional/BrandPartnerLinkNotifier.php:58`
- **Effort:** S (~1h)
- **What to do:**
    - Replace both direct `Notification::query()->create([...])` calls with `app(NotificationPublisher::class)->publish(...)` (or constructor-inject the publisher).
    - Supply a stable `dedupeKey`: `"selections_cleanup.{$affiliateProfessionalId}.{$brandProfessionalId}"` and `"link_removed.{$affiliate->id}.{$brand->id}"`.
    - Drop the explicit `type` / `severity` / `ends_at` literals — let `NotificationPublisher` derive these from `frontendType` + the config-driven retention key.
- **Plain English:** The notification system has a "don't send the same message twice" rule that requires every message to have a unique fingerprint. Two places in the code write notifications directly without that fingerprint — like two employees hand-writing messages to bypass the duplicate-detection system. The fix is routing every notification through the same central function so the duplicate check always applies.

## DATA-C2b#DATA-1 · P2 — OAuth credentials (`squareIntegration`) serialised into Redis model cache

- **Where:** `app/Services/Cache/ProfessionalCacheService.php:147` (`getByAuthId` model cache)
- **Effort:** S (~1h)
- **What to do:**
    - Remove `'squareIntegration'` from the eager-load list in `getByAuthId()`. The relation should be loaded explicitly only on the code paths that need it (Square API call sites — `SquareService`, `SquareSyncJob`).
    - If a dashboard flag is needed on the auth path (e.g., "user has Square connected"), project only a boolean via a separate lean query or surface it via the existing `pro:payload:*` cache (which does not include integration credentials).
    - Add a note to the PII/secret inventory documenting that any existing Redis RDB snapshots may contain OAuth tokens — treat as containing secrets until the cache is rolled.
    - **Sequencing:** if Phase 3 Pattern 1 has not shipped to `development`, land it first. The cache-key invalidation pattern Phase 3 establishes is the same pattern this fix relies on for the post-deploy refresh.
- **Plain English:** Every login request pulls a snapshot of the user's account from a fast-access memory store. That snapshot accidentally includes the user's Square payment-app credentials. The memory store is locked, but its files are stored as a single unencrypted dump — anyone who gets the dump gets every user's Square credentials at once. The fix is to stop including those credentials in the snapshot: we only need them when actually calling Square, not on every auth check.

## DATA-D#DATA-4 · P2 — `brand.brand_store_settings.default_commission_rate` precision mismatch

- **Where:** `supabase/migrations/20260403000000_v2_baseline.sql` (`brand.brand_store_settings`)
- **Effort:** S (~0.5h)
- **What to do:**
    - Single-statement migration: `ALTER TABLE brand.brand_store_settings ALTER COLUMN default_commission_rate TYPE numeric(7,4);`.
    - Widening precision is non-destructive — existing `numeric(5,2)` values cast to `numeric(7,4)` losslessly.
    - The existing `bss_commission_range` CHECK (`>= 0 AND <= 100`) is compatible; no constraint update needed.
- **Plain English:** Every table in the schema that records commission rates stores them to 4 decimal places (12.3456%) — except the settings table where brands set the default, which stores 2 (12.34%). The mismatch is invisible today because brands tend to set round percentages, but the moment a brand tries 12.3333%, the settings table silently rounds to 12.33% and orders calculate at the lower rate. No warning, no error. The fix widens the column to match the rest of the schema.

## DATA-A#DATA-9 (≡ DATA-D#DATA-5) · P3 — `public.failed_jobs.failed_at` timezone-naive

- **Where:** `supabase/migrations/20260403000000_v2_baseline.sql` (`public.failed_jobs`)
- **Effort:** S (~0.5h)
- **What to do:**
    - `ALTER TABLE public.failed_jobs ALTER COLUMN failed_at TYPE timestamptz USING failed_at AT TIME ZONE 'UTC';`
    - Safe assumption: Laravel's clock is UTC, so the cast is correct without further data adjustment.
- **Plain English:** Every timestamp in the database includes the timezone — except one wall clock in the server room that logs failed background jobs. During a 3am production incident, correlating failed jobs against other events requires manual offset math; it's an easy mistake to make under pressure. Ten-second fix.

## DATA-B#DATA-8, DATA-B#DATA-9, DATA-B#DATA-10 · P3 — Eloquent layer consistency (BelongsTo + casts)

- **Where:** `app/Models/Analytics/LeadSubmission.php`, `app/Models/Retail/BrandStoreSettings.php`
- **Effort:** S (~0.5h, bundled)
- **What to do:**
    - **LeadSubmission:** Add `professional(): BelongsTo`, `site(): BelongsTo`, `customer(): BelongsTo` mirroring `CartEvent`/`SiteVisit`/`LinkClick`. FK constraints already exist at DB level — this is purely the Eloquent contract.
    - **BrandStoreSettings:** Add `public function professional(): BelongsTo { return $this->belongsTo(Professional::class, 'professional_id'); }`. FK + UNIQUE already enforced at DB.
    - **BrandStoreSettings casts:** Add `'hydrogen_install_confirmed' => 'boolean'` to `$casts`. Cosmetic consistency with every other typed column on the model.
- **Plain English:** Three small consistency gaps on Eloquent models. Every other analytics model has built-in connectors to load its parent professional/site; `LeadSubmission` was the odd one out. Same for `BrandStoreSettings` — the database knows about the brand relationship, but the application code never declared it. And one yes/no field is missing an explicit type label that every other column on the same model has. None of these are bugs today; they're maintenance hazards for the next developer who assumes consistency. Half-hour fix.

---

# Appendix A — Suggested PR bundling

Order PRs land in: P0 first (Pattern 1), then P1 patterns (2, 3, 4, 5) interleaved with mechanically light P2s where they share files.

| PR bundle | Findings | Pattern | Notes |
|-----------|----------|---------|-------|
| `stripe-disconnect-check-constraint` | DATA-C1#DATA-1 | Pattern 1 | Ships first — P0, ~30 min, unblocks pilot. |
| `audit-table-hardening` | DATA-A#DATA-1 + DATA-D#DATA-1 (+ DATA-A2#DATA-5 absorbed) | Pattern 2 | Single migration; RLS + FK + snapshot columns for 3 audit tables in one shot. |
| `retention-purge-sweep` | DATA-A#DATA-2 + DATA-B#DATA-6 + DATA-C2b#DATA-2 + DATA-A#DATA-7 | Pattern 3 | One PR: `PurgeSoftDeleted` 2-line addition + gallery-trigger update + failed-media cleanup. |
| `professional-soft-delete-coherence` | DATA-B#DATA-1 + DATA-B#DATA-2 | Pattern 4 | `AccountDeletionService` + `SiteMedia` observer; one PR, ~half day. |
| `pii-hardening-sweep` | DATA-C2a#DATA-1 + DATA-A#DATA-3 + DATA-B#DATA-3 + DATA-B#DATA-4 | Pattern 5 | Touches 4 unrelated files but reviewer mental model is identical. |
| `gdpr-redact-symmetry` | DATA-A2#DATA-6 + DATA-B#DATA-5 | Pattern 6 | `RedactCustomerJob` extension + new staff endpoint. |
| `enum-check-constraint-sweep` | 8 P2 findings — see Pattern 7 | Pattern 7 | Largest single migration. Lands after structural P1s have shipped. |
| `fk-index-sweep` | DATA-A2#DATA-1 + DATA-A2#DATA-2 + DATA-A#DATA-9 (index half) | Pattern 8 | Three `CREATE INDEX CONCURRENTLY` in one migration. |
| `updated-at-trigger-sweep` | DATA-A2#DATA-7 | Pattern 9 | 13 triggers, one migration. |
| `rollup-rebuild-command` | DATA-A#DATA-6 | Standalone | New Artisan command + integrity check job + runbook entry. |
| `shopify-webhook-db-dedup` | DATA-C1#DATA-2 | Standalone | Bundles cleanly with `audit-table-hardening` if they ship in the same week (both touch the `webhook_events` / GDPR-adjacent surface). |
| `commission-payout-item-soft-tombstone` | DATA-C2a#DATA-2 | Standalone | Migration + service refactor + aggregation query updates. Land independently. |
| `notification-publisher-bypass-fix` | DATA-C2a#DATA-3 | Standalone | Two files, ~1h. Smallest in plan. |
| `redis-cache-credential-removal` | DATA-C2b#DATA-1 | Standalone | Requires Phase 3 Pattern 1 to be in `development` first. |
| `commission-rate-precision-align` | DATA-D#DATA-4 | Standalone | One-statement migration. Bundles with `enum-check-constraint-sweep` if reviewer is reviewing migration changes anyway. |
| `failed-jobs-timezone` | DATA-A#DATA-9 (column-half) | Standalone | One-statement migration. Bundle with FK index sweep. |
| `eloquent-model-consistency` | DATA-B#DATA-8 + DATA-B#DATA-9 + DATA-B#DATA-10 | Standalone | Pure model-class additions; smallest non-trivial PR. |

---

# Appendix B — Verification

After each pattern lands, verify with:

```bash
composer test                                       # full Pest suite
php artisan test --compact --filter=Webhook         # webhook controller tests
php artisan test --compact --filter=Gdpr            # GDPR job + endpoint tests
php artisan test --compact --filter=AccountDeletion # soft-delete cascade tests
php artisan test --compact --filter=Purge           # PurgeSoftDeleted coverage
php artisan test --compact --filter=Database        # CHECK / index / trigger sweeps
```

Pattern-specific spot checks:

- **Pattern 1 (`stripe_connect_status`):**
    - Tinker: `$pro = Professional::factory()->create(['stripe_connect_status' => 'active']); $pro->update(['stripe_connect_status' => 'disconnected']); $pro->fresh()->stripe_connect_status === 'disconnected';` — must return true without throwing.
- **Pattern 2 (audit tables):**
    - PostgREST anon-key request: `GET /rest/v1/professional_deletion_audit` — must return 0 rows.
    - Hard-delete a professional via tinker; assert their audit rows survive with `professional_id = NULL` and `professional_handle_snapshot` populated.
- **Pattern 3 (purge sweep):**
    - Create `Enquiry::factory()->create(['deleted_at' => now()->subDays(35)])`. Run `php artisan partna:purge-soft-deletes`. Assert row is gone.
    - Same for `ServiceCategory`.
    - Create `SiteMedia::factory()->create(['processing_state' => 'failed', 'created_at' => now()->subDays(10)])`. Run purge. Assert row is gone.
- **Pattern 4 (soft-delete cascade):**
    - Soft-delete a professional. `curl https://<subdomain>.api-dev.partna.au/site/payload` — must return 404.
    - Force-delete a `SiteMedia` with 2 variants on `Storage::fake()`. Assert `Storage::assertMissing()` for both variant paths.
- **Pattern 5 (PII hardening):**
    - Trigger a Supabase admin failure (mock 500). Inspect the captured `Log::error` payload — `email` key absent, `email_fingerprint` present.
    - `WaitlistSignup::factory()->create()->toArray()` — must not contain `name`, `email`, `phone`.
- **Pattern 6 (GDPR symmetry):**
    - Run `RedactCustomerJob` on a customer with linked `lead_submissions` rows. Assert all linked rows have `customer_id = NULL` and `ip_hash = NULL` after the job.
- **Pattern 7 (CHECK sweep):**
    - One spot-check per constraint via `expect(fn () => DB::insert(...))->toThrow(QueryException::class)` on a known-bad value.
- **Pattern 8 (FK indexes):**
    - `EXPLAIN DELETE FROM core.professionals WHERE id = ?` — assert the plan uses `idx_aps_brand_professional_id` for the cascade scan, not a seq scan.
- **Pattern 9 (updated_at triggers):**
    - For each of the 13 tables: `DB::statement('UPDATE <table> SET <field> = ? WHERE id = ?', ...);` then `SELECT updated_at FROM <table> WHERE id = ?` — assert `updated_at` is within the last 2 seconds.

---

# Appendix C — What this plan does NOT cover

For continuity with the Phase 1–4 plan format:

- **No Phase 7 work is implied here.** Lenses still unaudited: vendor secret rotation cadence, full Cloudflare KV key inventory beyond GDPR/Stripe paths, audit-table query performance under historical row counts, retention policy for `core.gdpr_requests` itself.
- **No backfill of unconstrained data.** Pattern 7's CHECK additions use `NOT VALID` → `VALIDATE CONSTRAINT`. If `VALIDATE` fails on any table, write a separate one-shot `UPDATE` migration to repair the data first. Don't add the constraint with `VALID` from day one if there's any chance of non-conforming data.
- **No deletion of `core.failed_jobs` rows older than N days.** The failed-jobs table grows monotonically. A retention policy (`partna:purge-failed-jobs`) is out of scope but a clean follow-up.
- **No automated rollback for `stripe_connect_status = 'disconnected'` rows that pre-existed the constraint relax.** None exist today (the constraint prevented insertion), so no backfill required.
- **No further audit of remaining `app/Services/` subdirs not covered by DATA-C2a/C2b.** Adjudication context window blocked a complete sweep — the privacy-relevant subdirs were prioritised. Remaining subdirs may be revisited in a Phase 7 pass.
- **No fix for the `BrandPartnerLink` / `Subscription` / `BrandStoreSettings` SoftDeletes additions** named in DATA-B#DATA-1 as "long-term." Pattern 4's `is_published = false` + `unpublished_at` on the site row covers the urgent public-exposure case; deeper trait additions are deferred until there's a concrete need for cross-table soft-delete coherence (e.g., a feature that queries soft-deleted brands' partner links).
- **No mass repair of historical `updated_at` timestamps** that are stale due to the missing triggers (DATA-A2#DATA-7). Trigger fires on next UPDATE; pre-existing stale timestamps are accepted as-is.
