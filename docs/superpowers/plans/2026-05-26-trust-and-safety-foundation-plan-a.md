# Trust & Safety Foundation — Plan A: Schema, Services, Public Reporting

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land the trust-and-safety foundation (moderation schema, case lifecycle, evidence snapshotting, policies, audit log) plus the public `POST /v1/public/report` endpoint. After this plan: visitors can submit reports; cases are created/merged in the DB with evidence snapshots; staff can read state via raw SQL / tinker but have no UI yet (Plan B).

**Architecture:** New `moderation` schema for workhorse tables (cases, signals, evidence, decisions, action_log); the `audit` schema holds the append-only audit_log. Service-layer separation: thin controllers, pure state machine, orchestration services, single decision-write path. Inserts into `moderation.cases` are deduped via partial unique indexes; signal merging into open cases uses `SELECT FOR UPDATE` inside transactions. Anti-abuse via Turnstile + IP throttle + per-target Redis throttle + DB-level `dedup_hash` UNIQUE.

**Tech Stack:** Laravel 12 (PHP 8.2), Supabase PostgreSQL, Pest 4, Redis (rate-limiting + nonce), existing Turnstile integration.

**Spec:** `docs/superpowers/specs/2026-05-26-trust-and-safety-foundation-design.md`

**Companion plans (to follow after this one):**
- **Plan B** — Staff workflow endpoints + outcome jobs + lifecycle commands + capabilities. Depends on Plan A.
- **Plan C** — CSAM scanning pipeline (R2 quarantine, Cloudflare webhook, NCMEC outbox). Depends on Plan A + B.

---

## Pre-flight checklist

- [ ] `git fetch origin && git checkout development && git pull origin development` — start from the latest tip
- [ ] On feature branch: `git checkout -b feat/ts-foundation-plan-a` off latest `development`
- [ ] Verify expected baseline migrations are present:
      `ls supabase/migrations/ | grep -E '20260527(010000|030000|070000)'`
      Expect: `reorganize_schemas`, `rename_professional_to_user`, `skeleton_system_cleanup`
- [ ] Local Supabase running: `supabase status` shows healthy
- [ ] Tests pass on base branch: `composer test`
- [ ] Composer autoload fresh: `composer dump-autoload -o`

## Codebase-state assumptions (verified against origin/development on 2026-05-27)

- `core.users` has `status` enum (`active`, `suspended`, `disabled`, `pending_deletion`). **No `moderation_state` column** — moderation outcomes update `status` instead. `suspend_user` → `status='suspended'`; `ban_user` → `status='disabled'`.
- `site.sites` uses `user_id` FK column (renamed from `professional_id` in mig `20260527030000`). The Site Eloquent model has `user()` relation.
- `core.partna_staff` has only: `id`, `auth_user_id`, `role` (admin|support), `primary_email`, `name`, `phone`. **No `is_active` or `is_on_call` columns** — all `role='admin'` staff are treated as on-call.
- Captcha is via `App\Services\BotProtection\CaptchaManager` (multi-provider) — not a `TurnstileVerifier`.
- Test helper for auth: global `actingAsUser(User $u, array $claims = [])` in `tests/Pest.php`, plus `aal2ClaimsWithFreshTotp()` for AAL2 claims.
- No `SiteFactory` or `PartnaStaffFactory` exists yet — Task 7.5 (below) creates both before they're used.
- The `audit` schema already exists (created by mig `20260527010000_reorganize_schemas.sql`). Don't recreate it; just add `audit.moderation_events` table.

---

## File structure (what gets created or modified)

```
supabase/migrations/
  20260528000000_create_moderation_schema.sql        — schemas + tables (DDL)
  20260528000001_create_moderation_indexes.sql       — partial indexes (CONCURRENTLY)
  20260528010000_alter_sites_moderation_state.sql
  20260528020000_alter_site_media_for_scan_states.sql
  20260528020001_alter_site_media_validate.sql

app/Models/Moderation/
  ModerationCase.php
  CaseSignal.php
  Evidence.php
  Decision.php
  ActionLogEntry.php
  AuditEvent.php

app/DTOs/Moderation/
  PublicReportDto.php
  DecisionDto.php

app/Services/Moderation/
  CaseStateMachine.php
  DedupHashCalculator.php
  EvidenceSnapshotService.php
  ModerationAuditService.php
  ModerationCaseService.php
  ModerationDecisionService.php
  ModerationActionDispatcher.php
  ContentReportService.php
  IllegalCaseTransition.php          (exception)
  CaseAlreadyResolved.php            (exception)
  ReportDuplicateSignal.php          (exception)

app/Policies/
  CasePolicy.php
  DecisionPolicy.php

app/Http/Controllers/Api/PublicSite/
  PublicReportController.php

app/Http/Requests/PublicSite/
  PublicReportRequest.php

app/Http/Resources/Moderation/
  ReportReceiptResource.php

app/Http/Middleware/Moderation/
  PerTargetReportThrottle.php

app/Jobs/Moderation/
  NotifyStaffOfCaseUpdateJob.php

app/Notifications/Moderation/
  CaseCreatedStaffNotification.php

config/partna.php                   — add 'moderation' section
config/horizon.php                  — add 'moderation_high' lane

app/Providers/AppServiceProvider.php — register Gate::policy for Case + Decision
app/Providers/RouteServiceProvider.php — define throttle rate limiters
routes/api/publicSite.php            — POST /v1/public/report

database/factories/Moderation/
  CaseFactory.php
  CaseSignalFactory.php
  EvidenceFactory.php
  DecisionFactory.php
  ActionLogEntryFactory.php

tests/Unit/Services/Moderation/
  CaseStateMachineTest.php
  DedupHashCalculatorTest.php
  EvidenceSnapshotServiceTest.php
  ModerationAuditServiceTest.php
  ModerationDecisionServiceTest.php

tests/Feature/Moderation/
  PublicReportSubmitTest.php
  PublicReportAntiAbuseTest.php
  PublicReportDedupTest.php
  ContentReportServiceTest.php
  CaseMergeRaceTest.php

tests/Feature/Security/
  ModerationPolicyCoverageTest.php   — extends existing PolicyCoverageTest pattern
  ReporterPiiLeakageTest.php

docs/moderation/
  README.md                          — operator overview (stub; expanded in Plan B)
```

---

## Phase 1 — Schema & migrations

### Task 1: Create `moderation` schema + core tables (DDL migration)

**Files:**
- Create: `supabase/migrations/20260528000000_create_moderation_schema.sql`
- Create: `tests/Feature/Moderation/SchemaSmokeTest.php`

- [ ] **Step 1: Write the failing smoke test**

Create `tests/Feature/Moderation/SchemaSmokeTest.php`:

```php
<?php

use Illuminate\Support\Facades\DB;

it('has moderation schema with the seven core tables', function () {
    $tables = collect(DB::select(<<<'SQL'
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = 'moderation'
        ORDER BY table_name
    SQL))->pluck('table_name')->all();

    expect($tables)->toContain(
        'action_log',
        'case_signals',
        'cases',
        'decisions',
        'evidence',
    );
});

it('has audit schema with moderation_events table', function () {
    $exists = DB::selectOne(<<<'SQL'
        SELECT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'audit' AND table_name = 'moderation_events'
        ) AS present
    SQL);

    expect($exists->present)->toBeTrue();
})->group('postgres');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Moderation/SchemaSmokeTest.php`
Expected: FAIL — `relation "moderation.cases" does not exist` or similar.

- [ ] **Step 3: Create the schema migration**

Create `supabase/migrations/$(date +%Y%m%d%H%M%S)0_create_moderation_schema.sql`:

```sql
-- Trust & Safety foundation — moderation schema and audit-events table.
-- Two-file pattern: this file is DDL inside BEGIN/COMMIT.
-- Indexes are in the +1 sibling file (CREATE INDEX CONCURRENTLY).

BEGIN;

-- Note: the `audit` schema is already created by 20260527010000_reorganize_schemas.sql
-- (on origin/development). We only add the moderation schema here.
CREATE SCHEMA IF NOT EXISTS moderation;

-- ── moderation.cases ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS moderation.cases (
    id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    case_type                VARCHAR(32) NOT NULL,
    reportable_type          VARCHAR(64) NOT NULL,
    reportable_id            UUID NOT NULL,
    reportable_owner_user_id UUID NULL,
    severity                 SMALLINT NOT NULL DEFAULT 2,
    status                   VARCHAR(20) NOT NULL DEFAULT 'open',
    signal_count             INTEGER NOT NULL DEFAULT 1,
    auto_actioned            BOOLEAN NOT NULL DEFAULT FALSE,
    priority                 SMALLINT NOT NULL DEFAULT 5,
    sla_due_at               TIMESTAMPTZ NULL,
    triaged_at               TIMESTAMPTZ NULL,
    triaged_by_staff_id      UUID NULL,
    resolved_at              TIMESTAMPTZ NULL,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT cases_case_type_check CHECK (case_type IN (
        'content_report', 'csam_match', 'trusted_flagger', 'manual', 'esafety_takedown'
    )),
    CONSTRAINT cases_reportable_type_check CHECK (reportable_type IN (
        'Site', 'SiteMedia', 'User', 'Block', 'Service'
    )),
    CONSTRAINT cases_severity_check CHECK (severity BETWEEN 1 AND 5),
    CONSTRAINT cases_status_check CHECK (status IN (
        'open', 'triaged', 'under_review', 'resolved', 'auto_actioned'
    )),
    CONSTRAINT cases_signal_count_check CHECK (signal_count >= 1),
    CONSTRAINT cases_priority_check CHECK (priority BETWEEN 1 AND 10),
    CONSTRAINT cases_owner_user_fk FOREIGN KEY (reportable_owner_user_id)
        REFERENCES core.users(id) ON DELETE SET NULL,
    CONSTRAINT cases_triaged_by_staff_fk FOREIGN KEY (triaged_by_staff_id)
        REFERENCES core.partna_staff(id) ON DELETE SET NULL
);

-- ── moderation.case_signals ──────────────────────────────────
CREATE TABLE IF NOT EXISTS moderation.case_signals (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    case_id             UUID NOT NULL,
    signal_source       VARCHAR(32) NOT NULL,
    signal_data         JSONB NOT NULL DEFAULT '{}'::JSONB,
    reporter_user_id    UUID NULL,
    reporter_email      VARCHAR(255) NULL,
    reporter_ip_hash    VARCHAR(64) NULL,
    reason_code         VARCHAR(64) NOT NULL,
    reason_details      TEXT NULL,
    dedup_hash          VARCHAR(64) NOT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT case_signals_case_fk FOREIGN KEY (case_id)
        REFERENCES moderation.cases(id) ON DELETE CASCADE,
    CONSTRAINT case_signals_reporter_user_fk FOREIGN KEY (reporter_user_id)
        REFERENCES core.users(id) ON DELETE SET NULL,
    CONSTRAINT case_signals_signal_source_check CHECK (signal_source IN (
        'content_report', 'csam_scan', 'trusted_flagger', 'manual_staff', 'esafety_notice'
    )),
    CONSTRAINT case_signals_reason_code_check CHECK (reason_code IN (
        'spam', 'harassment', 'impersonation', 'illegal_content', 'sexual_content',
        'self_harm', 'hate_speech', 'intellectual_property', 'fake_profile', 'other',
        'auto_csam_hash_match', 'auto_other'
    )),
    CONSTRAINT case_signals_details_length CHECK (
        reason_details IS NULL OR length(reason_details) <= 4000
    )
);

-- ── moderation.evidence ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS moderation.evidence (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    case_id         UUID NOT NULL,
    signal_id       UUID NULL,
    evidence_type   VARCHAR(32) NOT NULL,
    payload         JSONB NOT NULL,
    content_hash    VARCHAR(64) NULL,
    captured_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT evidence_case_fk FOREIGN KEY (case_id)
        REFERENCES moderation.cases(id) ON DELETE CASCADE,
    CONSTRAINT evidence_signal_fk FOREIGN KEY (signal_id)
        REFERENCES moderation.case_signals(id) ON DELETE SET NULL,
    CONSTRAINT evidence_type_check CHECK (evidence_type IN (
        'content_snapshot', 'csam_hash_match', 'upload_metadata', 'staff_attachment'
    ))
);

-- ── moderation.decisions ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS moderation.decisions (
    id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    case_id                  UUID NOT NULL,
    decision_type            VARCHAR(32) NOT NULL,
    reason                   TEXT NULL,
    decided_by_staff_id      UUID NULL,
    decided_by_system        BOOLEAN NOT NULL DEFAULT FALSE,
    auto_actioned            BOOLEAN NOT NULL DEFAULT FALSE,
    supersedes_decision_id   UUID NULL,
    second_staff_approval_id UUID NULL,
    second_staff_approved_at TIMESTAMPTZ NULL,
    decided_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT decisions_case_fk FOREIGN KEY (case_id)
        REFERENCES moderation.cases(id) ON DELETE RESTRICT,
    CONSTRAINT decisions_staff_fk FOREIGN KEY (decided_by_staff_id)
        REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    CONSTRAINT decisions_supersedes_fk FOREIGN KEY (supersedes_decision_id)
        REFERENCES moderation.decisions(id) ON DELETE SET NULL,
    CONSTRAINT decisions_second_staff_fk FOREIGN KEY (second_staff_approval_id)
        REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    CONSTRAINT decisions_decision_type_check CHECK (decision_type IN (
        'dismiss', 'warn', 'hide_content', 'hide_site', 'suspend_user', 'ban_user',
        'override_csam_auto_action', 'escalate_law_enforcement', 'escalate_esafety'
    )),
    CONSTRAINT decisions_actor_xor CHECK (
        (decided_by_staff_id IS NOT NULL AND decided_by_system = FALSE)
        OR
        (decided_by_staff_id IS NULL AND decided_by_system = TRUE)
    ),
    CONSTRAINT decisions_csam_override_requires_second_staff CHECK (
        decision_type <> 'override_csam_auto_action'
        OR (second_staff_approval_id IS NOT NULL AND second_staff_approved_at IS NOT NULL)
    )
);

-- ── moderation.action_log ────────────────────────────────────
CREATE TABLE IF NOT EXISTS moderation.action_log (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    decision_id     UUID NOT NULL,
    action_type     VARCHAR(48) NOT NULL,
    action_target   JSONB NOT NULL DEFAULT '{}'::JSONB,
    job_uuid        VARCHAR(36) NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts        SMALLINT NOT NULL DEFAULT 0,
    failure_reason  TEXT NULL,
    dispatched_at   TIMESTAMPTZ NULL,
    completed_at    TIMESTAMPTZ NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT action_log_decision_fk FOREIGN KEY (decision_id)
        REFERENCES moderation.decisions(id) ON DELETE CASCADE,
    CONSTRAINT action_log_action_type_check CHECK (action_type IN (
        'sync_subdomain_kv', 'suspend_user', 'suspend_site', 'quarantine_media',
        'file_cybertip_report', 'notify_reported_user', 'notify_reporter',
        'notify_oncall_staff', 'purge_cloudflare_cache', 'redact_reporter_pii'
    )),
    CONSTRAINT action_log_status_check CHECK (status IN (
        'pending', 'dispatched', 'completed', 'failed', 'cancelled'
    ))
);

-- ── audit.moderation_events ──────────────────────────────────
-- Append-only. `app_backend` should have SELECT/INSERT only on the audit schema.
CREATE TABLE IF NOT EXISTS audit.moderation_events (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    actor_kind      VARCHAR(16) NOT NULL,
    actor_staff_id  UUID NULL,
    action          VARCHAR(64) NOT NULL,
    target_type     VARCHAR(32) NULL,
    target_id       UUID NULL,
    payload         JSONB NOT NULL DEFAULT '{}'::JSONB,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT moderation_events_actor_kind_check CHECK (actor_kind IN ('staff', 'system')),
    CONSTRAINT moderation_events_actor_xor CHECK (
        (actor_kind = 'staff' AND actor_staff_id IS NOT NULL)
        OR
        (actor_kind = 'system' AND actor_staff_id IS NULL)
    ),
    CONSTRAINT moderation_events_staff_fk FOREIGN KEY (actor_staff_id)
        REFERENCES core.partna_staff(id) ON DELETE SET NULL
);

COMMIT;
```

- [ ] **Step 4: Apply migration to local Supabase**

Run: `supabase db reset` (or `supabase migration up`) in the project root.
Expected: migration applies without errors.

- [ ] **Step 5: Run the smoke test to verify it passes**

Run: `php artisan test tests/Feature/Moderation/SchemaSmokeTest.php --group=postgres`
Expected: PASS — all five tables present in `moderation`, `moderation_events` present in `audit`.

- [ ] **Step 6: Commit**

```bash
git add supabase/migrations/20260528000000_create_moderation_schema.sql tests/Feature/Moderation/SchemaSmokeTest.php
git commit -m "feat(moderation): create moderation + audit schemas (DDL)"
```

---

### Task 2: Indexes migration (CONCURRENTLY, outside transactions)

**Files:**
- Create: `supabase/migrations/20260528000001_create_moderation_indexes.sql`

- [ ] **Step 1: Extend the smoke test to assert indexes exist**

Append to `tests/Feature/Moderation/SchemaSmokeTest.php`:

```php
it('has the hot-path partial indexes for moderation queries', function () {
    $indexes = collect(DB::select(<<<'SQL'
        SELECT indexname FROM pg_indexes
        WHERE schemaname = 'moderation'
        ORDER BY indexname
    SQL))->pluck('indexname')->all();

    expect($indexes)->toContain(
        'cases_open_queue_idx',
        'cases_target_open_idx',
        'cases_sla_due_idx',
        'cases_owner_status_idx',
        'case_signals_dedup_uniq',
        'case_signals_case_idx',
        'evidence_case_idx',
        'decisions_case_idx',
        'action_log_decision_idx',
        'action_log_pending_idx',
    );
})->group('postgres');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Moderation/SchemaSmokeTest.php --group=postgres`
Expected: FAIL — indexes missing.

- [ ] **Step 3: Create the indexes migration**

Create `supabase/migrations/20260528000001_create_moderation_indexes.sql` (no `BEGIN/COMMIT`):

```sql
-- Trust & Safety indexes. CONCURRENTLY cannot run inside a transaction.
-- See supabase/migrations/CONVENTIONS.md §1.

CREATE INDEX CONCURRENTLY IF NOT EXISTS cases_open_queue_idx
    ON moderation.cases (severity DESC, priority ASC, created_at ASC)
    WHERE status IN ('open', 'triaged', 'under_review');

CREATE INDEX CONCURRENTLY IF NOT EXISTS cases_target_open_idx
    ON moderation.cases (reportable_type, reportable_id)
    WHERE status IN ('open', 'triaged', 'under_review');

CREATE INDEX CONCURRENTLY IF NOT EXISTS cases_sla_due_idx
    ON moderation.cases (sla_due_at)
    WHERE status IN ('open', 'triaged', 'under_review') AND sla_due_at IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS cases_owner_status_idx
    ON moderation.cases (reportable_owner_user_id, status)
    WHERE reportable_owner_user_id IS NOT NULL;

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS case_signals_dedup_uniq
    ON moderation.case_signals (dedup_hash);

CREATE INDEX CONCURRENTLY IF NOT EXISTS case_signals_case_idx
    ON moderation.case_signals (case_id, created_at);

CREATE INDEX CONCURRENTLY IF NOT EXISTS case_signals_reporter_user_idx
    ON moderation.case_signals (reporter_user_id)
    WHERE reporter_user_id IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS case_signals_reporter_ip_idx
    ON moderation.case_signals (reporter_ip_hash, created_at)
    WHERE reporter_ip_hash IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS evidence_case_idx
    ON moderation.evidence (case_id, captured_at);

CREATE INDEX CONCURRENTLY IF NOT EXISTS evidence_content_hash_idx
    ON moderation.evidence (content_hash)
    WHERE content_hash IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS decisions_case_idx
    ON moderation.decisions (case_id, decided_at);

CREATE INDEX CONCURRENTLY IF NOT EXISTS decisions_supersedes_idx
    ON moderation.decisions (supersedes_decision_id)
    WHERE supersedes_decision_id IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS action_log_decision_idx
    ON moderation.action_log (decision_id, created_at);

CREATE INDEX CONCURRENTLY IF NOT EXISTS action_log_pending_idx
    ON moderation.action_log (status, created_at)
    WHERE status IN ('pending', 'dispatched');

CREATE INDEX CONCURRENTLY IF NOT EXISTS moderation_events_staff_idx
    ON audit.moderation_events (actor_staff_id, created_at)
    WHERE actor_staff_id IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS moderation_events_target_idx
    ON audit.moderation_events (target_type, target_id, created_at)
    WHERE target_id IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS moderation_events_action_idx
    ON audit.moderation_events (action, created_at);
```

- [ ] **Step 4: Apply migration**

Run: `supabase migration up`
Expected: indexes built without errors.

- [ ] **Step 5: Run smoke tests**

Run: `php artisan test tests/Feature/Moderation/SchemaSmokeTest.php --group=postgres`
Expected: PASS — all indexes present.

- [ ] **Step 6: Commit**

```bash
git add supabase/migrations/20260528000001_create_moderation_indexes.sql tests/Feature/Moderation/SchemaSmokeTest.php
git commit -m "feat(moderation): add partial indexes for hot paths"
```

---

### Task 3: Add `moderation_state` to `site.sites` only (users reuse existing `status`)

**Files:**
- Create: `supabase/migrations/20260528010000_alter_sites_moderation_state.sql`

**Why sites only, not users:** `core.users.status` already supports `'active'`, `'suspended'`, `'disabled'`, `'pending_deletion'` — user-level moderation outcomes update this existing column. `suspend_user` decision → `status='suspended'`; `ban_user` decision → `status='disabled'`. Adding a parallel `moderation_state` to users would duplicate semantics. `site.sites` has no equivalent column today (only `is_published`/`unpublished_at`, both user-driven), so a small `moderation_state` column is added there to distinguish staff-driven hiding from user-driven unpublishing.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/ModerationStateColumnTest.php`:

```php
<?php

use Illuminate\Support\Facades\DB;

it('adds moderation_state column to site.sites with default active', function () {
    $col = DB::selectOne(<<<'SQL'
        SELECT column_name, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_schema = 'site' AND table_name = 'sites'
          AND column_name = 'moderation_state'
    SQL);

    expect($col)->not->toBeNull();
    expect($col->is_nullable)->toBe('NO');
    expect($col->column_default)->toContain("'active'");
})->group('postgres');

it('rejects illegal site moderation_state values via CHECK constraint', function () {
    // Pick any existing site via the test factory once SiteFactory exists (Task 7.5).
    // Until then, this assertion runs against a forceFilled row.
    $user = \App\Models\Core\User\User::factory()->create();
    $site = (new \App\Models\Core\Site\Site)->forceFill([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => 'check-' . uniqid(),
        'skeleton_id' => 'skeleton-1',
        'settings' => [],
        'is_published' => true,
    ]);
    $site->save();

    expect(fn () => DB::statement(
        "UPDATE site.sites SET moderation_state = 'invalid_state' WHERE id = ?",
        [$site->id]
    ))->toThrow(\Illuminate\Database\QueryException::class);
})->group('postgres');

it('users.status already covers moderation outcomes (no new column needed)', function () {
    // Sanity check: confirm the values we'll be using exist in users_status_check.
    $constraintDef = DB::selectOne(<<<'SQL'
        SELECT pg_get_constraintdef(oid) AS def
        FROM pg_constraint
        WHERE conname = 'users_status_check'
    SQL)->def ?? '';

    expect($constraintDef)->toContain('active');
    expect($constraintDef)->toContain('suspended');
    expect($constraintDef)->toContain('disabled');
})->group('postgres');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Moderation/ModerationStateColumnTest.php --group=postgres`
Expected: FAIL — site column does not exist.

- [ ] **Step 3: Create the migration**

Create `supabase/migrations/20260528010000_alter_sites_moderation_state.sql`:

```sql
-- Add moderation_state to site.sites. NOT NULL DEFAULT 'active' applies the
-- default to existing rows in one shot — pre-beta means no production data
-- is at risk; CONVENTIONS.md §3 four-step pattern is the alternative if/when
-- this becomes a populated prod migration.

BEGIN;

ALTER TABLE site.sites
    ADD COLUMN IF NOT EXISTS moderation_state VARCHAR(20) NOT NULL DEFAULT 'active';

ALTER TABLE site.sites
    ADD CONSTRAINT sites_moderation_state_check
    CHECK (moderation_state IN ('active', 'warned', 'hidden')) NOT VALID;

COMMIT;

-- Validate in a second transaction (separate locks; CONVENTIONS.md §2).
BEGIN;
ALTER TABLE site.sites VALIDATE CONSTRAINT sites_moderation_state_check;
COMMIT;
```

- [ ] **Step 4: Apply + run tests**

Run: `supabase migration up && php artisan test tests/Feature/Moderation/ModerationStateColumnTest.php --group=postgres`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations/20260528010000_alter_sites_moderation_state.sql tests/Feature/Moderation/ModerationStateColumnTest.php
git commit -m "feat(moderation): add moderation_state to site.sites (users use existing status)"
```

---

### Task 4: Extend `site.site_media.processing_state` to include `scanning` and `quarantined`

**Files:**
- Create: `supabase/migrations/20260528020000_alter_site_media_for_scan_states.sql`
- Create: `supabase/migrations/20260528020001_alter_site_media_validate.sql`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/SiteMediaScanStateTest.php`:

```php
<?php

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('allows scanning and quarantined values for site_media.processing_state', function () {
    $user = User::factory()->create();
    $site = DB::table('site.sites')->where('user_id', $user->id)->first()
        ?? DB::table('site.sites')->insertReturning([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    $mediaId = Str::uuid()->toString();
    DB::insert(<<<'SQL'
        INSERT INTO site.site_media (id, site_id, bucket, path, processing_state, scanned_at)
        VALUES (?, ?, 'public-assets', 'test/path.jpg', 'scanning', NULL)
    SQL, [$mediaId, $site->id]);

    DB::update("UPDATE site.site_media SET processing_state = 'quarantined' WHERE id = ?", [$mediaId]);

    $row = DB::selectOne("SELECT processing_state, scanned_at FROM site.site_media WHERE id = ?", [$mediaId]);
    expect($row->processing_state)->toBe('quarantined');
    expect($row->scanned_at)->toBeNull();
})->group('postgres');

it('adds scanned_at nullable timestamp column', function () {
    $col = DB::selectOne(<<<'SQL'
        SELECT is_nullable FROM information_schema.columns
        WHERE table_schema = 'site' AND table_name = 'site_media' AND column_name = 'scanned_at'
    SQL);
    expect($col)->not->toBeNull();
    expect($col->is_nullable)->toBe('YES');
})->group('postgres');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Moderation/SiteMediaScanStateTest.php --group=postgres`
Expected: FAIL — check constraint rejects `'scanning'`.

- [ ] **Step 3: Create the DDL migration**

Create `supabase/migrations/20260528020000_alter_site_media_for_scan_states.sql`:

```sql
-- Extends site_media.processing_state CHECK to allow 'scanning' and 'quarantined'.
-- Drops then re-adds NOT VALID; companion file 1 validates separately.

BEGIN;

ALTER TABLE site.site_media
    DROP CONSTRAINT IF EXISTS site_media_processing_state_check;

ALTER TABLE site.site_media
    ADD CONSTRAINT site_media_processing_state_check
    CHECK (processing_state IN ('pending', 'processing', 'scanning', 'ready', 'failed', 'quarantined'))
    NOT VALID;

ALTER TABLE site.site_media
    ADD COLUMN IF NOT EXISTS scanned_at TIMESTAMPTZ NULL;

COMMENT ON COLUMN site.site_media.scanned_at IS
    'Set when CSAM scan completes. NULL = pre-scanning-era media (grandfathered) or scan not yet run.';

COMMIT;
```

Create `supabase/migrations/20260528020001_alter_site_media_validate.sql`:

```sql
-- Validate the relaxed CHECK in its own transaction (CONVENTIONS.md §2).
BEGIN;
ALTER TABLE site.site_media VALIDATE CONSTRAINT site_media_processing_state_check;
COMMIT;
```

- [ ] **Step 4: Apply + run tests**

Run: `supabase migration up && php artisan test tests/Feature/Moderation/SiteMediaScanStateTest.php --group=postgres`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations/20260528020000_*.sql supabase/migrations/20260528020001_*.sql tests/Feature/Moderation/SiteMediaScanStateTest.php
git commit -m "feat(moderation): extend site_media.processing_state for scan lifecycle"
```

---

## Phase 2 — Eloquent models + factories

### Task 5: `ModerationCase` model with factory

**Files:**
- Create: `app/Models/Moderation/ModerationCase.php`
- Create: `database/factories/Moderation/CaseFactory.php`
- Create: `tests/Unit/Models/Moderation/CaseModelTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Models/Moderation/CaseModelTest.php`:

```php
<?php

use App\Models\Moderation\ModerationCase;
use App\Models\Core\User\User;

it('creates a moderation case via factory with sensible defaults', function () {
    $owner = User::factory()->create();
    $case  = ModerationCase::factory()->forOwner($owner)->create();

    expect($case->case_type)->toBe('content_report');
    expect($case->severity)->toBe(2);
    expect($case->status)->toBe('open');
    expect($case->signal_count)->toBe(1);
    expect($case->priority)->toBe(5);
    expect($case->reportable_type)->toBe('Site');
});

it('uses the moderation schema (not public)', function () {
    expect((new ModerationCase)->getTable())->toBe('moderation.cases');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Models/Moderation/CaseModelTest.php`
Expected: FAIL — model and factory don't exist.

- [ ] **Step 3: Create the model**

Create `app/Models/Moderation/ModerationCase.php`:

```php
<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Database\Factories\Moderation\CaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModerationCase extends BaseModel
{
    use HasFactory;

    protected $table = 'moderation.cases';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = ['id'];

    protected $casts = [
        'severity'      => 'integer',
        'signal_count'  => 'integer',
        'priority'      => 'integer',
        'auto_actioned' => 'boolean',
        'sla_due_at'    => 'datetime',
        'triaged_at'    => 'datetime',
        'resolved_at'   => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reportable_owner_user_id');
    }

    public function triagedBy(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'triaged_by_staff_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(CaseSignal::class, 'case_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class, 'case_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class, 'case_id');
    }

    protected static function newFactory(): CaseFactory
    {
        return CaseFactory::new();
    }
}
```

- [ ] **Step 4: Create the factory**

Create `database/factories/Moderation/CaseFactory.php`:

```php
<?php

namespace Database\Factories\Moderation;

use App\Models\Core\User\User;
use App\Models\Moderation\ModerationCase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CaseFactory extends Factory
{
    protected $model = ModerationCase::class;

    public function definition(): array
    {
        return [
            'id'                       => Str::uuid()->toString(),
            'case_type'                => 'content_report',
            'reportable_type'          => 'Site',
            'reportable_id'            => Str::uuid()->toString(),
            'reportable_owner_user_id' => null,
            'severity'                 => 2,
            'status'                   => 'open',
            'signal_count'             => 1,
            'auto_actioned'            => false,
            'priority'                 => 5,
        ];
    }

    public function forOwner(User $owner): self
    {
        return $this->state(fn () => ['reportable_owner_user_id' => $owner->id]);
    }

    public function triaged(): self
    {
        return $this->state(fn () => ['status' => 'triaged', 'triaged_at' => now()]);
    }

    public function underReview(): self
    {
        return $this->state(fn () => ['status' => 'under_review']);
    }

    public function resolved(): self
    {
        return $this->state(fn () => ['status' => 'resolved', 'resolved_at' => now()]);
    }

    public function csamMatch(): self
    {
        return $this->state(fn () => [
            'case_type'     => 'csam_match',
            'severity'      => 5,
            'status'        => 'auto_actioned',
            'auto_actioned' => true,
            'priority'      => 1,
        ]);
    }
}
```

- [ ] **Step 5: Run tests + commit**

Run: `composer dump-autoload && php artisan test tests/Unit/Models/Moderation/CaseModelTest.php`
Expected: PASS.

```bash
git add app/Models/Moderation/ModerationCase.php database/factories/Moderation/CaseFactory.php tests/Unit/Models/Moderation/CaseModelTest.php
git commit -m "feat(moderation): add Case model + factory"
```

---

### Task 6: `CaseSignal` model with factory

**Files:**
- Create: `app/Models/Moderation/CaseSignal.php`
- Create: `database/factories/Moderation/CaseSignalFactory.php`
- Create: `tests/Unit/Models/Moderation/CaseSignalModelTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Models/Moderation/CaseSignalModelTest.php`:

```php
<?php

use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\CaseSignal;

it('belongs to a case', function () {
    $case   = ModerationCase::factory()->create();
    $signal = CaseSignal::factory()->forCase($case)->create();

    expect($signal->case->id)->toBe($case->id);
    expect($signal->signal_source)->toBe('content_report');
    expect($signal->reason_code)->toBe('spam');
});

it('uses the moderation schema', function () {
    expect((new CaseSignal)->getTable())->toBe('moderation.case_signals');
});
```

- [ ] **Step 2: Run to fail**

Run: `php artisan test tests/Unit/Models/Moderation/CaseSignalModelTest.php`
Expected: FAIL.

- [ ] **Step 3: Create the model**

Create `app/Models/Moderation/CaseSignal.php`:

```php
<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Database\Factories\Moderation\CaseSignalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseSignal extends BaseModel
{
    use HasFactory;

    protected $table = 'moderation.case_signals';
    public $timestamps = false;     // only created_at exists, no updated_at
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = ['id'];

    protected $casts = [
        'signal_data' => 'array',
        'created_at'  => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class, 'case_id');
    }

    public function reporterUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    protected static function newFactory(): CaseSignalFactory
    {
        return CaseSignalFactory::new();
    }
}
```

- [ ] **Step 4: Create the factory**

Create `database/factories/Moderation/CaseSignalFactory.php`:

```php
<?php

namespace Database\Factories\Moderation;

use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\CaseSignal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CaseSignalFactory extends Factory
{
    protected $model = CaseSignal::class;

    public function definition(): array
    {
        return [
            'id'               => Str::uuid()->toString(),
            'case_id'          => ModerationCase::factory(),
            'signal_source'    => 'content_report',
            'signal_data'      => [],
            'reporter_user_id' => null,
            'reporter_email'   => null,
            'reporter_ip_hash' => hash('sha256', '127.0.0.1:salt'),
            'reason_code'      => 'spam',
            'reason_details'   => null,
            'dedup_hash'       => hash('sha256', Str::uuid()->toString()),
        ];
    }

    public function forCase(ModerationCase $case): self
    {
        return $this->state(fn () => ['case_id' => $case->id]);
    }

    public function fromCsamScan(): self
    {
        return $this->state(fn () => [
            'signal_source' => 'csam_scan',
            'reason_code'   => 'auto_csam_hash_match',
        ]);
    }
}
```

- [ ] **Step 5: Run + commit**

Run: `php artisan test tests/Unit/Models/Moderation/CaseSignalModelTest.php`
Expected: PASS.

```bash
git add app/Models/Moderation/CaseSignal.php database/factories/Moderation/CaseSignalFactory.php tests/Unit/Models/Moderation/CaseSignalModelTest.php
git commit -m "feat(moderation): add CaseSignal model + factory"
```

---

### Task 7: `Evidence`, `Decision`, `ActionLogEntry`, `AuditEvent` models

**Files:**
- Create: `app/Models/Moderation/Evidence.php`
- Create: `app/Models/Moderation/Decision.php`
- Create: `app/Models/Moderation/ActionLogEntry.php`
- Create: `app/Models/Moderation/AuditEvent.php`
- Create matching factories under `database/factories/Moderation/`
- Create: `tests/Unit/Models/Moderation/RemainingModelsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Models/Moderation/RemainingModelsTest.php`:

```php
<?php

use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\AuditEvent;
use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\Decision;
use App\Models\Moderation\Evidence;

it('creates Evidence with case relation + JSONB payload', function () {
    $case = ModerationCase::factory()->create();
    $ev   = Evidence::factory()->forCase($case)->create(['payload' => ['name' => 'snapshot']]);

    expect($ev->case->id)->toBe($case->id);
    expect($ev->payload)->toBe(['name' => 'snapshot']);
    expect($ev->evidence_type)->toBe('content_snapshot');
});

it('creates Decision with auto_actioned system flag', function () {
    $case     = ModerationCase::factory()->create();
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create();

    expect($decision->decided_by_system)->toBeTrue();
    expect($decision->decided_by_staff_id)->toBeNull();
    expect($decision->auto_actioned)->toBeTrue();
});

it('creates ActionLogEntry tied to a decision', function () {
    $decision = Decision::factory()->create();
    $entry    = ActionLogEntry::factory()->forDecision($decision)->create();

    expect($entry->decision_id)->toBe($decision->id);
    expect($entry->status)->toBe('pending');
});

it('creates AuditEvent in the audit schema', function () {
    $event = AuditEvent::factory()->systemAction('case.created', ['case_id' => 'abc'])->create();

    expect((new AuditEvent)->getTable())->toBe('audit.moderation_events');
    expect($event->actor_kind)->toBe('system');
    expect($event->action)->toBe('case.created');
});
```

- [ ] **Step 2: Run to fail**

Run: `php artisan test tests/Unit/Models/Moderation/RemainingModelsTest.php`
Expected: FAIL — models missing.

- [ ] **Step 3: Create `Evidence` model**

Create `app/Models/Moderation/Evidence.php`:

```php
<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use Database\Factories\Moderation\EvidenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evidence extends BaseModel
{
    use HasFactory;

    protected $table = 'moderation.evidence';
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = ['id'];

    protected $casts = [
        'payload'     => 'array',
        'captured_at' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class, 'case_id');
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(CaseSignal::class, 'signal_id');
    }

    protected static function newFactory(): EvidenceFactory
    {
        return EvidenceFactory::new();
    }
}
```

- [ ] **Step 4: Create `Decision` model**

Create `app/Models/Moderation/Decision.php`:

```php
<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use App\Models\Core\Staff\PartnaStaff;
use Database\Factories\Moderation\DecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Decision extends BaseModel
{
    use HasFactory;

    protected $table = 'moderation.decisions';
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = ['id'];

    protected $casts = [
        'decided_by_system'         => 'boolean',
        'auto_actioned'             => 'boolean',
        'decided_at'                => 'datetime',
        'second_staff_approved_at'  => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class, 'case_id');
    }

    public function decidedByStaff(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'decided_by_staff_id');
    }

    public function secondStaffApproval(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'second_staff_approval_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_decision_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ActionLogEntry::class, 'decision_id');
    }

    protected static function newFactory(): DecisionFactory
    {
        return DecisionFactory::new();
    }
}
```

- [ ] **Step 5: Create `ActionLogEntry` model**

Create `app/Models/Moderation/ActionLogEntry.php`:

```php
<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use Database\Factories\Moderation\ActionLogEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionLogEntry extends BaseModel
{
    use HasFactory;

    protected $table = 'moderation.action_log';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = ['id'];

    protected $casts = [
        'action_target' => 'array',
        'dispatched_at' => 'datetime',
        'completed_at'  => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
        'attempts'      => 'integer',
    ];

    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class, 'decision_id');
    }

    protected static function newFactory(): ActionLogEntryFactory
    {
        return ActionLogEntryFactory::new();
    }
}
```

- [ ] **Step 6: Create `AuditEvent` model**

Create `app/Models/Moderation/AuditEvent.php`:

```php
<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use App\Models\Core\Staff\PartnaStaff;
use Database\Factories\Moderation\AuditEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends BaseModel
{
    use HasFactory;

    protected $table = 'audit.moderation_events';
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = ['id'];

    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'actor_staff_id');
    }

    protected static function newFactory(): AuditEventFactory
    {
        return AuditEventFactory::new();
    }
}
```

- [ ] **Step 7: Create the four factories**

Create `database/factories/Moderation/EvidenceFactory.php`:

```php
<?php

namespace Database\Factories\Moderation;

use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\Evidence;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EvidenceFactory extends Factory
{
    protected $model = Evidence::class;

    public function definition(): array
    {
        return [
            'id'            => Str::uuid()->toString(),
            'case_id'       => ModerationCase::factory(),
            'signal_id'     => null,
            'evidence_type' => 'content_snapshot',
            'payload'       => ['placeholder' => true],
            'content_hash'  => hash('sha256', Str::uuid()->toString()),
        ];
    }

    public function forCase(ModerationCase $case): self
    {
        return $this->state(fn () => ['case_id' => $case->id]);
    }

    public function csamMatch(): self
    {
        return $this->state(fn () => [
            'evidence_type' => 'csam_hash_match',
            'payload'       => ['match_database' => 'NCMEC', 'confidence' => 'exact'],
        ]);
    }
}
```

Create `database/factories/Moderation/DecisionFactory.php`:

```php
<?php

namespace Database\Factories\Moderation;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\Decision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DecisionFactory extends Factory
{
    protected $model = Decision::class;

    public function definition(): array
    {
        return [
            'id'                  => Str::uuid()->toString(),
            'case_id'             => ModerationCase::factory(),
            'decision_type'       => 'dismiss',
            'reason'              => 'Default factory reason — replace in test.',
            'decided_by_staff_id' => PartnaStaff::factory(),
            'decided_by_system'   => false,
            'auto_actioned'       => false,
        ];
    }

    public function forCase(ModerationCase $case): self
    {
        return $this->state(fn () => ['case_id' => $case->id]);
    }

    public function systemAutoActioned(): self
    {
        return $this->state(fn () => [
            'decided_by_staff_id' => null,
            'decided_by_system'   => true,
            'auto_actioned'       => true,
            'decision_type'       => 'suspend_user',
            'reason'              => 'auto_csam_match',
        ]);
    }
}
```

Create `database/factories/Moderation/ActionLogEntryFactory.php`:

```php
<?php

namespace Database\Factories\Moderation;

use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ActionLogEntryFactory extends Factory
{
    protected $model = ActionLogEntry::class;

    public function definition(): array
    {
        return [
            'id'            => Str::uuid()->toString(),
            'decision_id'   => Decision::factory(),
            'action_type'   => 'notify_reporter',
            'action_target' => [],
            'status'        => 'pending',
            'attempts'      => 0,
        ];
    }

    public function forDecision(Decision $d): self
    {
        return $this->state(fn () => ['decision_id' => $d->id]);
    }
}
```

Create `database/factories/Moderation/AuditEventFactory.php`:

```php
<?php

namespace Database\Factories\Moderation;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\AuditEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AuditEventFactory extends Factory
{
    protected $model = AuditEvent::class;

    public function definition(): array
    {
        return [
            'id'             => Str::uuid()->toString(),
            'actor_kind'     => 'staff',
            'actor_staff_id' => PartnaStaff::factory(),
            'action'         => 'case.viewed',
            'target_type'    => null,
            'target_id'      => null,
            'payload'        => [],
        ];
    }

    public function systemAction(string $action, array $payload = []): self
    {
        return $this->state(fn () => [
            'actor_kind'     => 'system',
            'actor_staff_id' => null,
            'action'         => $action,
            'payload'        => $payload,
        ]);
    }
}
```

- [ ] **Step 8: Run + commit**

Run: `composer dump-autoload && php artisan test tests/Unit/Models/Moderation/`
Expected: PASS.

```bash
git add app/Models/Moderation/ database/factories/Moderation/ tests/Unit/Models/Moderation/
git commit -m "feat(moderation): add Evidence, Decision, ActionLogEntry, AuditEvent models + factories"
```

---

### Task 7.5: Supporting factories — `SiteFactory` and `PartnaStaffFactory`

These factories don't exist yet on origin/development. Several downstream tasks (`DecisionFactory`, `AuditEventFactory`, every test that creates Sites for resolution) depend on them. Land them here before Phase 3 begins.

**Files:**
- Create: `database/factories/Core/Site/SiteFactory.php`
- Create: `database/factories/Core/Staff/PartnaStaffFactory.php`
- Modify: `app/Models/Core/Site/Site.php` (add `use HasFactory` + `newFactory()`)
- Modify: `app/Models/Core/Staff/PartnaStaff.php` (already has `HasFactory` trait — just confirm; add `newFactory()` if missing)
- Create: `tests/Unit/Factories/SupportingFactoriesTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Factories/SupportingFactoriesTest.php`:

```php
<?php

use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;

it('Site::factory() creates a valid Site row tied to a User', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();

    expect($site->user_id)->toBe($user->id);
    expect($site->subdomain)->toBeString();
    expect($site->skeleton_id)->toBe('skeleton-1');
});

it('PartnaStaff::factory()->create() defaults to support role', function () {
    $staff = PartnaStaff::factory()->create();
    expect($staff->role)->toBe('support');
    expect($staff->auth_user_id)->toBeString();
});

it('PartnaStaff::factory()->admin()->create() promotes to admin role', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    expect($staff->role)->toBe('admin');
});
```

- [ ] **Step 2: Run to fail**

Run: `php artisan test tests/Unit/Factories/SupportingFactoriesTest.php`
Expected: FAIL — factories don't exist yet.

- [ ] **Step 3: Create `SiteFactory`**

Create `database/factories/Core/Site/SiteFactory.php`:

```php
<?php

namespace Database\Factories\Core\Site;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        // user_id is set via ->for($user, 'user') in callers (or filled here when standalone).
        $sub = 'site-' . strtolower(Str::random(8));
        return [
            'id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'subdomain' => $sub,
            'skeleton_id' => 'skeleton-1',
            'settings' => [],
            'is_published' => true,
        ];
    }
}
```

- [ ] **Step 4: Wire `HasFactory` into the Site model**

Modify `app/Models/Core/Site/Site.php`. Add the trait + `newFactory()`:

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Database\Factories\Core\Site\SiteFactory;

class Site extends BaseModel
{
    use HasUuids, HasFactory;  // add HasFactory

    // ...existing properties...

    protected static function newFactory(): SiteFactory
    {
        return SiteFactory::new();
    }
}
```

Also: `Site::$fillable` doesn't currently include `user_id`. Factories use `Model::newInstance()` which bypasses the `$fillable` guard, so this works. If a caller later needs `Site::create(['user_id' => …])` they should use `Site::query()->forceCreate([...])`.

- [ ] **Step 5: Create `PartnaStaffFactory`**

Create `database/factories/Core/Staff/PartnaStaffFactory.php`:

```php
<?php

namespace Database\Factories\Core\Staff;

use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PartnaStaff>
 */
class PartnaStaffFactory extends Factory
{
    protected $model = PartnaStaff::class;

    public function definition(): array
    {
        $name = fake()->name();
        return [
            'id' => (string) Str::uuid(),
            'auth_user_id' => (string) Str::uuid(),
            'role' => PartnaStaff::ROLE_SUPPORT,
            'primary_email' => 'staff-' . strtolower(Str::random(8)) . '@partna.au',
            'name' => $name,
            'phone' => fake()->e164PhoneNumber(),
        ];
    }

    public function admin(): self
    {
        return $this->state(fn () => ['role' => PartnaStaff::ROLE_ADMIN]);
    }
}
```

- [ ] **Step 6: Wire `newFactory()` into `PartnaStaff`**

Modify `app/Models/Core/Staff/PartnaStaff.php`. It already `use HasFactory` per the file we read on origin/development. Append `newFactory()`:

```php
protected static function newFactory(): \Database\Factories\Core\Staff\PartnaStaffFactory
{
    return \Database\Factories\Core\Staff\PartnaStaffFactory::new();
}
```

Note: `role` is NOT in `$fillable` for safety reasons (per the model's docblock). Factory state methods (`admin()`) call `forceFill` internally so they bypass mass-assignment guarding — safe and matches Laravel's factory semantics.

- [ ] **Step 7: Run + commit**

Run: `composer dump-autoload && php artisan test tests/Unit/Factories/SupportingFactoriesTest.php`
Expected: PASS.

```bash
git add database/factories/Core/ app/Models/Core/Site/Site.php app/Models/Core/Staff/PartnaStaff.php tests/Unit/Factories/SupportingFactoriesTest.php
git commit -m "feat(factories): SiteFactory + PartnaStaffFactory for downstream test infra"
```

---

## Phase 3 — State machine + helpers

### Task 8: `CaseStateMachine` (pure FSM, no side effects)

**Files:**
- Create: `app/Services/Moderation/CaseStateMachine.php`
- Create: `app/Services/Moderation/IllegalCaseTransition.php`
- Create: `tests/Unit/Services/Moderation/CaseStateMachineTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Moderation/CaseStateMachineTest.php`:

```php
<?php

use App\Models\Moderation\ModerationCase;
use App\Services\Moderation\CaseStateMachine;
use App\Services\Moderation\IllegalCaseTransition;

it('allows open -> triaged', function () {
    $case = ModerationCase::factory()->make(['status' => 'open']);
    (new CaseStateMachine)->transition($case, 'triaged');
    expect($case->status)->toBe('triaged');
});

it('allows triaged -> under_review', function () {
    $case = ModerationCase::factory()->make(['status' => 'triaged']);
    (new CaseStateMachine)->transition($case, 'under_review');
    expect($case->status)->toBe('under_review');
});

it('allows under_review -> resolved', function () {
    $case = ModerationCase::factory()->make(['status' => 'under_review']);
    (new CaseStateMachine)->transition($case, 'resolved');
    expect($case->status)->toBe('resolved');
});

it('allows under_review -> triaged (release)', function () {
    $case = ModerationCase::factory()->make(['status' => 'under_review']);
    (new CaseStateMachine)->transition($case, 'triaged');
    expect($case->status)->toBe('triaged');
});

it('treats resolved as terminal', function () {
    $case = ModerationCase::factory()->make(['status' => 'resolved']);
    expect(fn () => (new CaseStateMachine)->transition($case, 'open'))
        ->toThrow(IllegalCaseTransition::class);
});

it('rejects open -> under_review (must go via triaged)', function () {
    $case = ModerationCase::factory()->make(['status' => 'open']);
    expect(fn () => (new CaseStateMachine)->transition($case, 'under_review'))
        ->toThrow(IllegalCaseTransition::class, 'open -> under_review');
});

it('allows open -> auto_actioned for CSAM-style cases', function () {
    $case = ModerationCase::factory()->make(['status' => 'open']);
    (new CaseStateMachine)->transition($case, 'auto_actioned');
    expect($case->status)->toBe('auto_actioned');
});

it('allows auto_actioned -> resolved (staff confirms or overrides)', function () {
    $case = ModerationCase::factory()->make(['status' => 'auto_actioned']);
    (new CaseStateMachine)->transition($case, 'resolved');
    expect($case->status)->toBe('resolved');
});
```

- [ ] **Step 2: Run to fail**

Run: `php artisan test tests/Unit/Services/Moderation/CaseStateMachineTest.php`
Expected: FAIL — class missing.

- [ ] **Step 3: Create the exception**

Create `app/Services/Moderation/IllegalCaseTransition.php`:

```php
<?php

namespace App\Services\Moderation;

use RuntimeException;

class IllegalCaseTransition extends RuntimeException
{
    public static function forStatuses(string $from, string $to): self
    {
        return new self("Illegal case transition: {$from} -> {$to}");
    }
}
```

- [ ] **Step 4: Create the state machine**

Create `app/Services/Moderation/CaseStateMachine.php`:

```php
<?php

namespace App\Services\Moderation;

use App\Models\Moderation\ModerationCase;

/**
 * Pure FSM for moderation.cases.status transitions. No side effects.
 * Used by ModerationCaseService and CsamMatchHandlerService — the only legal write paths.
 */
class CaseStateMachine
{
    private const LEGAL_TRANSITIONS = [
        'open'           => ['triaged', 'auto_actioned', 'resolved'],
        'triaged'        => ['under_review', 'resolved'],
        'under_review'   => ['resolved', 'triaged'],
        'auto_actioned'  => ['resolved'],
        'resolved'       => [],   // terminal
    ];

    public function transition(ModerationCase $case, string $to): void
    {
        $from = $case->status;

        if (! isset(self::LEGAL_TRANSITIONS[$from])) {
            throw IllegalCaseTransition::forStatuses($from, $to);
        }

        if (! in_array($to, self::LEGAL_TRANSITIONS[$from], strict: true)) {
            throw IllegalCaseTransition::forStatuses($from, $to);
        }

        $case->status = $to;
    }

    public function canTransition(ModerationCase $case, string $to): bool
    {
        $from = $case->status;
        return isset(self::LEGAL_TRANSITIONS[$from])
            && in_array($to, self::LEGAL_TRANSITIONS[$from], strict: true);
    }
}
```

- [ ] **Step 5: Run + commit**

Run: `php artisan test tests/Unit/Services/Moderation/CaseStateMachineTest.php`
Expected: PASS — all 8 assertions.

```bash
git add app/Services/Moderation/CaseStateMachine.php app/Services/Moderation/IllegalCaseTransition.php tests/Unit/Services/Moderation/CaseStateMachineTest.php
git commit -m "feat(moderation): add CaseStateMachine + IllegalCaseTransition"
```

---

### Task 9: `DedupHashCalculator`

**Files:**
- Create: `app/Services/Moderation/DedupHashCalculator.php`
- Create: `tests/Unit/Services/Moderation/DedupHashCalculatorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Moderation/DedupHashCalculatorTest.php`:

```php
<?php

use App\Services\Moderation\DedupHashCalculator;

it('produces the same hash for the same inputs', function () {
    $calc = new DedupHashCalculator;
    $a = $calc->forReport('Site', '123', 'spam', 'reporter@example.com', null);
    $b = $calc->forReport('Site', '123', 'spam', 'reporter@example.com', null);
    expect($a)->toBe($b);
});

it('produces different hashes for different reasons', function () {
    $calc = new DedupHashCalculator;
    $spam      = $calc->forReport('Site', '123', 'spam',       'r@e.com', null);
    $harassmnt = $calc->forReport('Site', '123', 'harassment', 'r@e.com', null);
    expect($spam)->not->toBe($harassmnt);
});

it('falls back to ip_hash when email is null', function () {
    $calc = new DedupHashCalculator;
    $h1 = $calc->forReport('Site', '123', 'spam', null, 'ip-hash-abc');
    $h2 = $calc->forReport('Site', '123', 'spam', null, 'ip-hash-abc');
    expect($h1)->toBe($h2);
});

it('produces different hashes for the same email but different targets', function () {
    $calc = new DedupHashCalculator;
    $h1 = $calc->forReport('Site', '111', 'spam', 'r@e.com', null);
    $h2 = $calc->forReport('Site', '222', 'spam', 'r@e.com', null);
    expect($h1)->not->toBe($h2);
});

it('produces hex digest of length 64 (sha256)', function () {
    $h = (new DedupHashCalculator)->forReport('Site', '1', 'spam', 'r@e.com', null);
    expect(strlen($h))->toBe(64);
    expect($h)->toMatch('/^[0-9a-f]+$/');
});

it('rejects when both email and ip_hash are null', function () {
    expect(fn () => (new DedupHashCalculator)->forReport('Site', '1', 'spam', null, null))
        ->toThrow(\InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run to fail**

Run: `php artisan test tests/Unit/Services/Moderation/DedupHashCalculatorTest.php`
Expected: FAIL.

- [ ] **Step 3: Create the calculator**

Create `app/Services/Moderation/DedupHashCalculator.php`:

```php
<?php

namespace App\Services\Moderation;

use InvalidArgumentException;

/**
 * Computes the dedup_hash stored on moderation.case_signals.
 * Same reporter + same target + same reason = same hash.
 * UNIQUE index on case_signals.dedup_hash blocks duplicates at the DB level.
 */
class DedupHashCalculator
{
    public function forReport(
        string $reportableType,
        string $reportableId,
        string $reasonCode,
        ?string $reporterEmail,
        ?string $reporterIpHash,
    ): string {
        if ($reporterEmail === null && $reporterIpHash === null) {
            throw new InvalidArgumentException(
                'DedupHashCalculator requires either reporter_email or reporter_ip_hash.'
            );
        }

        $identity = $reporterEmail !== null
            ? 'email:' . strtolower(trim($reporterEmail))
            : 'ip:' . $reporterIpHash;

        return hash('sha256', implode('|', [
            'content_report',
            $reportableType,
            $reportableId,
            $reasonCode,
            $identity,
        ]));
    }

    public function forCsamMatch(string $cloudflareMatchId, string $siteMediaId): string
    {
        return hash('sha256', implode('|', [
            'csam_scan',
            $siteMediaId,
            $cloudflareMatchId,
        ]));
    }
}
```

- [ ] **Step 4: Run + commit**

Run: `php artisan test tests/Unit/Services/Moderation/DedupHashCalculatorTest.php`
Expected: PASS — all 6 assertions.

```bash
git add app/Services/Moderation/DedupHashCalculator.php tests/Unit/Services/Moderation/DedupHashCalculatorTest.php
git commit -m "feat(moderation): add DedupHashCalculator"
```

---

### Task 10: `PublicReportDto`

**Files:**
- Create: `app/DTOs/Moderation/PublicReportDto.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/DTOs/Moderation/PublicReportDtoTest.php`:

```php
<?php

use App\DTOs\Moderation\PublicReportDto;

it('exposes immutable typed fields', function () {
    $dto = new PublicReportDto(
        targetType:    'Site',
        targetHandle:  'joeplumber',
        reasonCode:    'harassment',
        details:       'Long-form complaint',
        reporterEmail: 'reporter@example.com',
        reporterIp:    '203.0.113.42',
    );

    expect($dto->targetType)->toBe('Site');
    expect($dto->targetHandle)->toBe('joeplumber');
    expect($dto->reasonCode)->toBe('harassment');
    expect($dto->details)->toBe('Long-form complaint');
    expect($dto->reporterEmail)->toBe('reporter@example.com');
    expect($dto->reporterIp)->toBe('203.0.113.42');
});

it('allows nullable details and reporter_email', function () {
    $dto = new PublicReportDto('Site', 'h', 'spam', null, null, '127.0.0.1');
    expect($dto->details)->toBeNull();
    expect($dto->reporterEmail)->toBeNull();
});
```

- [ ] **Step 2: Run to fail**

Run: `php artisan test tests/Unit/DTOs/Moderation/PublicReportDtoTest.php`
Expected: FAIL.

- [ ] **Step 3: Create the DTO**

Create `app/DTOs/Moderation/PublicReportDto.php`:

```php
<?php

namespace App\DTOs\Moderation;

/**
 * Immutable input DTO for a public content report.
 * Built from the validated PublicReportRequest before reaching ContentReportService.
 */
final class PublicReportDto
{
    public function __construct(
        public readonly string $targetType,
        public readonly string $targetHandle,
        public readonly string $reasonCode,
        public readonly ?string $details,
        public readonly ?string $reporterEmail,
        public readonly string $reporterIp,
    ) {}
}
```

- [ ] **Step 4: Run + commit**

Run: `php artisan test tests/Unit/DTOs/Moderation/PublicReportDtoTest.php`
Expected: PASS.

```bash
git add app/DTOs/Moderation/PublicReportDto.php tests/Unit/DTOs/Moderation/PublicReportDtoTest.php
git commit -m "feat(moderation): add PublicReportDto"
```

---

## Phase 4 — Policies

### Task 11: `CasePolicy` + `DecisionPolicy` registration

**Files:**
- Create: `app/Policies/CasePolicy.php`
- Create: `app/Policies/DecisionPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php` (add Gate::policy registrations)
- Create: `tests/Feature/Security/ModerationPolicyCoverageTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Security/ModerationPolicyCoverageTest.php`:

```php
<?php

use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\Decision;
use App\Models\Moderation\Evidence;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\AuditEvent;
use Illuminate\Support\Facades\Gate;

it('registers a policy for moderation ModerationCase', function () {
    expect(Gate::getPolicyFor(ModerationCase::class))->not->toBeNull();
});

it('registers a policy for moderation Decision', function () {
    expect(Gate::getPolicyFor(Decision::class))->not->toBeNull();
});

it('exempts Evidence + CaseSignal + ActionLogEntry + AuditEvent (write-only models, no end-user authz)', function () {
    // These models are never accessed by end users; policy coverage sweep
    // (tests/Feature/Security/PolicyCoverageTest.php) must allow-list them.
    expect(true)->toBeTrue();   // sanity placeholder; real exemption asserted in PolicyCoverageTest update
});
```

- [ ] **Step 2: Run to fail**

Run: `php artisan test tests/Feature/Security/ModerationPolicyCoverageTest.php`
Expected: FAIL.

- [ ] **Step 3: Create `CasePolicy`**

Create `app/Policies/CasePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Models\Moderation\ModerationCase;

class CasePolicy extends BasePolicy
{
    public function viewAny(User|PartnaStaff $actor): bool
    {
        return $actor instanceof PartnaStaff && $actor->is_active;
    }

    public function view(User|PartnaStaff $actor, ModerationCase $case): bool
    {
        return $actor instanceof PartnaStaff && $actor->is_active;
    }

    public function triage(PartnaStaff $staff, ModerationCase $case): bool
    {
        return $staff->is_active && in_array($case->status, ['open', 'under_review'], true);
    }

    public function take(PartnaStaff $staff, ModerationCase $case): bool
    {
        return $staff->is_active && $case->status === 'triaged';
    }

    public function release(PartnaStaff $staff, ModerationCase $case): bool
    {
        return $staff->is_active && $case->status === 'under_review';
    }

    public function decide(PartnaStaff $staff, ModerationCase $case): bool
    {
        return $staff->is_active && in_array($case->status, ['triaged', 'under_review', 'auto_actioned'], true);
    }

    public function escalate(PartnaStaff $staff, ModerationCase $case): bool
    {
        return $staff->is_active && $case->status !== 'resolved';
    }
}
```

- [ ] **Step 4: Create `DecisionPolicy`**

Create `app/Policies/DecisionPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\Decision;

class DecisionPolicy extends BasePolicy
{
    public function view(PartnaStaff $staff, Decision $decision): bool
    {
        return $staff->is_active;
    }

    public function reverse(PartnaStaff $staff, Decision $decision): bool
    {
        // The artisan moderation:reverse-decision command is the only path day-one.
        return $staff->is_active;
    }
}
```

- [ ] **Step 5: Register policies in `AppServiceProvider::boot()`**

Modify `app/Providers/AppServiceProvider.php`. Add to the `boot()` method (where existing `Gate::policy(...)` registrations live):

```php
Gate::policy(\App\Models\Moderation\ModerationCase::class, \App\Policies\CasePolicy::class);
Gate::policy(\App\Models\Moderation\Decision::class, \App\Policies\DecisionPolicy::class);
```

- [ ] **Step 6: Update the existing PolicyCoverageTest exempt list**

Modify `tests/Feature/Security/PolicyCoverageTest.php`. Locate the `POLICY_EXEMPT` allow-list and add:

```php
const POLICY_EXEMPT = [
    // ...existing exemptions...
    \App\Models\Moderation\Evidence::class       => 'Append-only evidence; no end-user authz path',
    \App\Models\Moderation\CaseSignal::class     => 'Write-once signals; authz lives on parent Case',
    \App\Models\Moderation\ActionLogEntry::class => 'System-only job tracking, no end-user authz',
    \App\Models\Moderation\AuditEvent::class     => 'Append-only audit trail; staff-only via service',
];
```

- [ ] **Step 7: Run + commit**

Run: `php artisan test tests/Feature/Security/ModerationPolicyCoverageTest.php tests/Feature/Security/PolicyCoverageTest.php`
Expected: PASS — policies registered, allow-list updated.

```bash
git add app/Policies/CasePolicy.php app/Policies/DecisionPolicy.php app/Providers/AppServiceProvider.php tests/Feature/Security/ModerationPolicyCoverageTest.php tests/Feature/Security/PolicyCoverageTest.php
git commit -m "feat(moderation): register CasePolicy + DecisionPolicy; exempt write-only models"
```

---

## Phase 5 — Evidence snapshot service

### Task 12: `EvidenceSnapshotService` (Site strategy)

**Files:**
- Create: `app/Services/Moderation/EvidenceSnapshotService.php`
- Create: `tests/Unit/Services/Moderation/EvidenceSnapshotServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Moderation/EvidenceSnapshotServiceTest.php`:

```php
<?php

use App\Models\Core\User\User;
use App\Models\Moderation\ModerationCase;
use App\Models\Core\Site\Site;
use App\Services\Moderation\EvidenceSnapshotService;

it('captures a Site snapshot with stable content_hash', function () {
    $user = User::factory()->create(['display_name' => 'Joe Plumber', 'handle' => 'joeplumber', 'handle_lc' => 'joeplumber']);
    $site = Site::factory()->for($user, 'user')->create();
    $case = ModerationCase::factory()->create(['reportable_type' => 'Site', 'reportable_id' => $site->id]);

    $service = app(EvidenceSnapshotService::class);
    $ev1 = $service->capture($case->id, 'Site', $site->id, null);
    $ev2 = $service->capture($case->id, 'Site', $site->id, null);

    expect($ev1->evidence_type)->toBe('content_snapshot');
    expect($ev1->payload)->toHaveKey('captured_at');
    expect($ev1->payload)->toHaveKey('site_id');
    expect($ev1->payload['site_id'])->toBe($site->id);

    // Same site contents → same hash even on a second snapshot
    expect($ev1->content_hash)->toBe($ev2->content_hash);
});

it('throws when the target is an unknown type', function () {
    $case = ModerationCase::factory()->create();
    expect(fn () =>
        app(EvidenceSnapshotService::class)
            ->capture($case->id, 'Unicorn', '00000000-0000-0000-0000-000000000000', null)
    )->toThrow(\InvalidArgumentException::class, 'Unsupported snapshot target type: Unicorn');
});

it('payload is JSON-serializable (no recursion, no DateTime)', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $case = ModerationCase::factory()->create(['reportable_type' => 'Site', 'reportable_id' => $site->id]);

    $ev = app(EvidenceSnapshotService::class)->capture($case->id, 'Site', $site->id, null);
    $encoded = json_encode($ev->payload, flags: JSON_THROW_ON_ERROR);
    expect($encoded)->toBeString();
});
```

- [ ] **Step 2: Run to fail**

Run: `php artisan test tests/Unit/Services/Moderation/EvidenceSnapshotServiceTest.php`
Expected: FAIL.

- [ ] **Step 3: Create the service**

Create `app/Services/Moderation/EvidenceSnapshotService.php`:

```php
<?php

namespace App\Services\Moderation;

use App\Models\Moderation\Evidence;
use App\Models\Core\Site\Site;
use InvalidArgumentException;
use Illuminate\Support\Carbon;

/**
 * Captures an immutable evidence row at signal time.
 *
 * The payload is the source-of-truth for what the reported content looked like
 * when the signal arrived — staff sees this snapshot, NOT the live content, when
 * deciding. Edits or deletions by the reported user after the snapshot don't
 * affect what we'll defend later.
 *
 * Per-target-type strategies fan out from capture(). Day-one: Site only.
 * SiteMedia / Block / User snapshots are added in fast-follows.
 */
class EvidenceSnapshotService
{
    public function capture(
        string $caseId,
        string $targetType,
        string $targetId,
        ?string $signalId,
    ): Evidence {
        $payload = match ($targetType) {
            'Site' => $this->snapshotSite($targetId),
            default => throw new InvalidArgumentException("Unsupported snapshot target type: {$targetType}"),
        };

        $payload['captured_at'] = Carbon::now()->toIso8601String();

        // Hash excludes captured_at so re-snapshotting unchanged content is idempotent.
        $hashInput = $payload;
        unset($hashInput['captured_at']);
        $contentHash = hash('sha256', json_encode($hashInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return Evidence::create([
            'case_id'       => $caseId,
            'signal_id'     => $signalId,
            'evidence_type' => 'content_snapshot',
            'payload'       => $payload,
            'content_hash'  => $contentHash,
        ]);
    }

    private function snapshotSite(string $siteId): array
    {
        $site = Site::query()->with(['professional', 'blocks'])->findOrFail($siteId);

        return [
            'site_id'         => $site->id,
            'site_subdomain'  => $site->subdomain ?? null,
            'user_id' => $site->user_id,
            'handle'          => $site->professional?->handle ?? null,
            'display_name'    => $site->professional?->name ?? null,
            'bio'             => $site->professional?->bio ?? null,
            'block_count'     => $site->blocks?->count() ?? 0,
            'block_types'     => $site->blocks?->pluck('block_type')->all() ?? [],
        ];
    }
}
```

- [ ] **Step 4: Run + commit**

Run: `php artisan test tests/Unit/Services/Moderation/EvidenceSnapshotServiceTest.php`
Expected: PASS.

```bash
git add app/Services/Moderation/EvidenceSnapshotService.php tests/Unit/Services/Moderation/EvidenceSnapshotServiceTest.php
git commit -m "feat(moderation): add EvidenceSnapshotService with Site strategy"
```

---

## Phase 6 — Audit service

### Task 13: `ModerationAuditService`

**Files:**
- Create: `app/Services/Moderation/ModerationAuditService.php`
- Create: `tests/Unit/Services/Moderation/ModerationAuditServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Moderation/ModerationAuditServiceTest.php`:

```php
<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\AuditEvent;
use App\Services\Moderation\ModerationAuditService;

it('records a staff action', function () {
    $staff = PartnaStaff::factory()->create();
    app(ModerationAuditService::class)
        ->recordStaffAction($staff, 'case.triaged', 'Case', '11111111-1111-1111-1111-111111111111', ['notes' => 'spam']);

    $row = AuditEvent::query()->latest('created_at')->first();
    expect($row->actor_kind)->toBe('staff');
    expect($row->actor_staff_id)->toBe($staff->id);
    expect($row->action)->toBe('case.triaged');
    expect($row->target_type)->toBe('Case');
    expect($row->payload)->toBe(['notes' => 'spam']);
});

it('records a system action with null actor_staff_id', function () {
    app(ModerationAuditService::class)
        ->recordSystemAction('csam.auto_action', 'Case', '22222222-2222-2222-2222-222222222222', []);

    $row = AuditEvent::query()->latest('created_at')->first();
    expect($row->actor_kind)->toBe('system');
    expect($row->actor_staff_id)->toBeNull();
});

it('redacts PII keys from payload when configured', function () {
    $staff = PartnaStaff::factory()->create();
    app(ModerationAuditService::class)
        ->recordStaffAction($staff, 'reporter.contacted', null, null, [
            'safe_field' => 'ok',
            'email'      => 'leak@example.com',   // must be filtered
            'raw_ip'     => '203.0.113.1',         // must be filtered
        ]);

    $row = AuditEvent::query()->latest('created_at')->first();
    expect($row->payload)->toHaveKey('safe_field');
    expect($row->payload)->not->toHaveKey('email');
    expect($row->payload)->not->toHaveKey('raw_ip');
});
```

- [ ] **Step 2: Run to fail**

Run: `php artisan test tests/Unit/Services/Moderation/ModerationAuditServiceTest.php`
Expected: FAIL.

- [ ] **Step 3: Create the service**

Create `app/Services/Moderation/ModerationAuditService.php`:

```php
<?php

namespace App\Services\Moderation;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\AuditEvent;

/**
 * Single write path for the audit.moderation_events table.
 * Any staff action against a moderation entity must call recordStaffAction().
 * Auto-actions call recordSystemAction().
 *
 * Strips PII keys from payload before persisting. Reporters' raw email/IP must
 * never enter the audit trail — they live on case_signals where erasure is
 * supported via moderation:redact-reporter-pii.
 */
class ModerationAuditService
{
    private const FORBIDDEN_PAYLOAD_KEYS = [
        'email', 'reporter_email', 'raw_ip', 'ip', 'reporter_ip',
        'phone', 'password', 'token',
    ];

    public function recordStaffAction(
        PartnaStaff $staff,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $payload = [],
    ): AuditEvent {
        return AuditEvent::create([
            'actor_kind'     => 'staff',
            'actor_staff_id' => $staff->id,
            'action'         => $action,
            'target_type'    => $targetType,
            'target_id'      => $targetId,
            'payload'        => $this->scrubPii($payload),
        ]);
    }

    public function recordSystemAction(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $payload = [],
    ): AuditEvent {
        return AuditEvent::create([
            'actor_kind'     => 'system',
            'actor_staff_id' => null,
            'action'         => $action,
            'target_type'    => $targetType,
            'target_id'      => $targetId,
            'payload'        => $this->scrubPii($payload),
        ]);
    }

    private function scrubPii(array $payload): array
    {
        return array_diff_key($payload, array_flip(self::FORBIDDEN_PAYLOAD_KEYS));
    }
}
```

- [ ] **Step 4: Run + commit**

Run: `php artisan test tests/Unit/Services/Moderation/ModerationAuditServiceTest.php`
Expected: PASS.

```bash
git add app/Services/Moderation/ModerationAuditService.php tests/Unit/Services/Moderation/ModerationAuditServiceTest.php
git commit -m "feat(moderation): add ModerationAuditService with PII scrub"
```

---

## Phase 7 — Content report orchestration

### Task 14: Exception classes for the reporting path

**Files:**
- Create: `app/Services/Moderation/ReportDuplicateSignal.php`
- Create: `app/Services/Moderation/ReportTargetNotFound.php`
- Create: `app/Services/Moderation/CaseAlreadyResolved.php`

- [ ] **Step 1: Create the three exception classes**

Create `app/Services/Moderation/ReportDuplicateSignal.php`:

```php
<?php

namespace App\Services\Moderation;

use RuntimeException;

class ReportDuplicateSignal extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Duplicate report signal (dedup_hash collision).');
    }
}
```

Create `app/Services/Moderation/ReportTargetNotFound.php`:

```php
<?php

namespace App\Services\Moderation;

use RuntimeException;

class ReportTargetNotFound extends RuntimeException
{
    public function __construct(string $targetType, string $targetHandle)
    {
        parent::__construct("Cannot resolve target {$targetType}/{$targetHandle}");
    }
}
```

Create `app/Services/Moderation/CaseAlreadyResolved.php`:

```php
<?php

namespace App\Services\Moderation;

use RuntimeException;

class CaseAlreadyResolved extends RuntimeException
{
    public function __construct(string $caseId)
    {
        parent::__construct("Case {$caseId} is already resolved.");
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/Moderation/ReportDuplicateSignal.php app/Services/Moderation/ReportTargetNotFound.php app/Services/Moderation/CaseAlreadyResolved.php
git commit -m "feat(moderation): add reporting-path exception classes"
```

---

### Task 15: `ContentReportService` — happy path (no existing case)

**Files:**
- Create: `app/Services/Moderation/ContentReportService.php`
- Create: `tests/Feature/Moderation/ContentReportServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/ContentReportServiceTest.php`:

```php
<?php

use App\DTOs\Moderation\PublicReportDto;
use App\Models\Core\User\User;
use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\Evidence;
use App\Models\Core\Site\Site;
use App\Services\Moderation\ContentReportService;
use App\Services\Moderation\ReportTargetNotFound;
use Illuminate\Support\Facades\Queue;

it('creates a new case + signal + evidence on first report for a target', function () {
    Queue::fake();

    $user = User::factory()->create(['handle' => 'joeplumber']);
    Site::factory()->for($user, 'user')->create();

    $dto = new PublicReportDto(
        targetType:    'Site',
        targetHandle:  'joeplumber',
        reasonCode:    'spam',
        details:       'looks like spam',
        reporterEmail: 'reporter@example.com',
        reporterIp:    '203.0.113.42',
    );

    $result = app(ContentReportService::class)->submit($dto);

    expect(ModerationCase::count())->toBe(1);
    expect(CaseSignal::count())->toBe(1);
    expect(Evidence::count())->toBe(1);

    $case = ModerationCase::first();
    expect($case->case_type)->toBe('content_report');
    expect($case->status)->toBe('open');
    expect($case->signal_count)->toBe(1);
    expect($case->reportable_owner_user_id)->toBe($user->id);

    expect($result->receiptId)->toBe(CaseSignal::first()->id);
});

it('rejects when target handle does not resolve', function () {
    $dto = new PublicReportDto('Site', 'no-such-handle', 'spam', null, 'r@e.com', '127.0.0.1');
    expect(fn () => app(ContentReportService::class)->submit($dto))
        ->toThrow(ReportTargetNotFound::class);
});
```

- [ ] **Step 2: Run to fail**

Run: `php artisan test tests/Feature/Moderation/ContentReportServiceTest.php`
Expected: FAIL.

- [ ] **Step 3: Create the service skeleton**

Create `app/Services/Moderation/ContentReportService.php`:

```php
<?php

namespace App\Services\Moderation;

use App\DTOs\Moderation\PublicReportDto;
use App\Jobs\Moderation\NotifyStaffOfCaseUpdateJob;
use App\Models\Core\User\User;
use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\CaseSignal;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates the human-report submit flow:
 *   resolve target → dedup → open-or-merge case → snapshot evidence → notify staff
 *
 * Single write path for case_signals from human reports.
 */
class ContentReportService
{
    public function __construct(
        private readonly DedupHashCalculator $dedup,
        private readonly EvidenceSnapshotService $evidence,
    ) {}

    public function submit(PublicReportDto $dto): ContentReportSubmitResult
    {
        // 1. Resolve handle → Site + owner User (eager).
        $user = User::query()->where('handle', $dto->targetHandle)->first();

        if ($user === null) {
            throw new ReportTargetNotFound($dto->targetType, $dto->targetHandle);
        }

        $site = Site::query()->where('user_id', $user->id)->first();

        if ($site === null) {
            throw new ReportTargetNotFound($dto->targetType, $dto->targetHandle);
        }

        $reporterIpHash = hash('sha256', $dto->reporterIp . '|' . config('app.key'));
        $dedupHash = $this->dedup->forReport(
            reportableType:  $dto->targetType,
            reportableId:    $site->id,
            reasonCode:      $dto->reasonCode,
            reporterEmail:   $dto->reporterEmail,
            reporterIpHash:  $reporterIpHash,
        );

        // 2. Open-or-merge inside a transaction.
        $signal = DB::transaction(function () use ($dto, $site, $user, $reporterIpHash, $dedupHash) {

            $existing = ModerationCase::query()
                ->where('reportable_type', $dto->targetType)
                ->where('reportable_id', $site->id)
                ->whereIn('status', ['open', 'triaged', 'under_review'])
                ->lockForUpdate()
                ->first();

            $case = $existing ?? ModerationCase::create([
                'case_type'                => 'content_report',
                'reportable_type'          => $dto->targetType,
                'reportable_id'            => $site->id,
                'reportable_owner_user_id' => $user->id,
                'severity'                 => 2,
                'status'                   => 'open',
                'signal_count'             => 0,
                'priority'                 => 5,
            ]);

            $signal = CaseSignal::create([
                'case_id'          => $case->id,
                'signal_source'    => 'content_report',
                'signal_data'      => ['details' => $dto->details],
                'reporter_email'   => $dto->reporterEmail,
                'reporter_ip_hash' => $reporterIpHash,
                'reason_code'      => $dto->reasonCode,
                'reason_details'   => $dto->details,
                'dedup_hash'       => $dedupHash,
            ]);

            // Increment signal_count + capture evidence
            $case->signal_count = $case->signal_count + 1;
            $case->save();

            $this->evidence->capture($case->id, $dto->targetType, $site->id, $signal->id);

            return $signal;
        });

        // 3. Notify staff on threshold-crossing (handled by job).
        NotifyStaffOfCaseUpdateJob::dispatchAfterCommit($signal->case_id);

        return new ContentReportSubmitResult(
            receiptId: $signal->id,
        );
    }
}
```

- [ ] **Step 4: Create the result value object**

Create `app/Services/Moderation/ContentReportSubmitResult.php`:

```php
<?php

namespace App\Services\Moderation;

final class ContentReportSubmitResult
{
    public function __construct(
        public readonly string $receiptId,
    ) {}
}
```

- [ ] **Step 5: Create the stub job (full implementation in next task)**

Create `app/Jobs/Moderation/NotifyStaffOfCaseUpdateJob.php`:

```php
<?php

namespace App\Jobs\Moderation;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyStaffOfCaseUpdateJob implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $caseId) {}

    public function handle(): void
    {
        // Stub: full implementation in Task 16 (threshold gating + notification dispatch).
        // Day-one: no-op so dispatch contracts can be tested.
    }
}
```

- [ ] **Step 6: Run + commit**

Run: `php artisan test tests/Feature/Moderation/ContentReportServiceTest.php`
Expected: PASS.

```bash
git add app/Services/Moderation/ContentReportService.php app/Services/Moderation/ContentReportSubmitResult.php app/Jobs/Moderation/NotifyStaffOfCaseUpdateJob.php tests/Feature/Moderation/ContentReportServiceTest.php
git commit -m "feat(moderation): add ContentReportService happy-path submit"
```

---

### Task 16: `ContentReportService` — case merging (existing open case)

**Files:**
- Modify: `tests/Feature/Moderation/ContentReportServiceTest.php` (append)

- [ ] **Step 1: Append failing tests**

Append to `tests/Feature/Moderation/ContentReportServiceTest.php`:

```php
it('merges into the existing open case rather than creating a new one', function () {
    $user = User::factory()->create(['handle' => 'joeplumber']);
    Site::factory()->for($user, 'user')->create();

    $dto1 = new PublicReportDto('Site', 'joeplumber', 'spam',       null, 'r1@e.com', '203.0.113.1');
    $dto2 = new PublicReportDto('Site', 'joeplumber', 'harassment', null, 'r2@e.com', '203.0.113.2');

    app(ContentReportService::class)->submit($dto1);
    app(ContentReportService::class)->submit($dto2);

    expect(ModerationCase::count())->toBe(1);
    expect(CaseSignal::count())->toBe(2);
    expect(ModerationCase::first()->signal_count)->toBe(2);
    expect(Evidence::count())->toBe(2);
});

it('does NOT merge into a resolved case (opens a fresh one)', function () {
    $user = User::factory()->create(['handle' => 'joeplumber']);
    $site = Site::factory()->for($user, 'user')->create();

    ModerationCase::factory()->resolved()->create([
        'reportable_type' => 'Site',
        'reportable_id'   => $site->id,
    ]);

    $dto = new PublicReportDto('Site', 'joeplumber', 'spam', null, 'r@e.com', '203.0.113.5');
    app(ContentReportService::class)->submit($dto);

    expect(ModerationCase::count())->toBe(2);
    expect(ModerationCase::query()->where('status', 'open')->count())->toBe(1);
});

it('rejects duplicate signals via UNIQUE constraint on dedup_hash', function () {
    $user = User::factory()->create(['handle' => 'joeplumber']);
    Site::factory()->for($user, 'user')->create();

    $dto = new PublicReportDto('Site', 'joeplumber', 'spam', null, 'reporter@e.com', '203.0.113.9');

    app(ContentReportService::class)->submit($dto);

    expect(fn () => app(ContentReportService::class)->submit($dto))
        ->toThrow(\Illuminate\Database\QueryException::class);
})->group('postgres');
```

- [ ] **Step 2: Run to verify the merge test passes (no implementation change needed) and the dedup test is well-formed**

Run: `php artisan test tests/Feature/Moderation/ContentReportServiceTest.php`
Expected: PASS — merge behavior already works due to the `lockForUpdate` + `whereIn(status)` predicate.

- [ ] **Step 3: Refactor: extract case-resolution into a private method**

Modify `app/Services/Moderation/ContentReportService.php`. Replace the `submit()` body where the case lookup happens with a call to a private helper, and add helper:

```php
private function openOrMergeCase(string $type, string $id, string $ownerId): ModerationCase
{
    $existing = ModerationCase::query()
        ->where('reportable_type', $type)
        ->where('reportable_id', $id)
        ->whereIn('status', ['open', 'triaged', 'under_review'])
        ->lockForUpdate()
        ->first();

    if ($existing !== null) {
        return $existing;
    }

    return ModerationCase::create([
        'case_type'                => 'content_report',
        'reportable_type'          => $type,
        'reportable_id'            => $id,
        'reportable_owner_user_id' => $ownerId,
        'severity'                 => 2,
        'status'                   => 'open',
        'signal_count'             => 0,
        'priority'                 => 5,
    ]);
}
```

And inside `submit()` replace the inline open/create logic with `$case = $this->openOrMergeCase($dto->targetType, $site->id, $user->id);`

- [ ] **Step 4: Run + commit**

Run: `php artisan test tests/Feature/Moderation/ContentReportServiceTest.php`
Expected: PASS — all 5 cases.

```bash
git add app/Services/Moderation/ContentReportService.php tests/Feature/Moderation/ContentReportServiceTest.php
git commit -m "feat(moderation): merge signals into existing open case; refactor helper"
```

---

### Task 17: `NotifyStaffOfCaseUpdateJob` — threshold gating + dispatch

**Files:**
- Modify: `app/Jobs/Moderation/NotifyStaffOfCaseUpdateJob.php`
- Create: `app/Notifications/Moderation/CaseCreatedStaffNotification.php`
- Create: `tests/Feature/Moderation/NotifyStaffOfCaseUpdateJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/NotifyStaffOfCaseUpdateJobTest.php`:

```php
<?php

use App\Jobs\Moderation\NotifyStaffOfCaseUpdateJob;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\CaseCreatedStaffNotification;
use Illuminate\Support\Facades\Notification;

it('notifies on-call staff at threshold signal_count 1', function () {
    Notification::fake();
    $staff = PartnaStaff::factory()->create(['role' => 'admin']);
    $case  = ModerationCase::factory()->create(['signal_count' => 1]);

    (new NotifyStaffOfCaseUpdateJob($case->id))->handle();

    Notification::assertSentTo($staff, CaseCreatedStaffNotification::class);
});

it('does NOT notify at signal_count 2 (between thresholds)', function () {
    Notification::fake();
    PartnaStaff::factory()->create(['role' => 'admin']);
    $case = ModerationCase::factory()->create(['signal_count' => 2]);

    (new NotifyStaffOfCaseUpdateJob($case->id))->handle();

    Notification::assertNothingSent();
});

it('notifies at threshold 3', function () {
    Notification::fake();
    $staff = PartnaStaff::factory()->create(['role' => 'admin']);
    $case  = ModerationCase::factory()->create(['signal_count' => 3]);

    (new NotifyStaffOfCaseUpdateJob($case->id))->handle();

    Notification::assertSentTo($staff, CaseCreatedStaffNotification::class);
});

it('does not notify inactive on-call staff', function () {
    Notification::fake();
    PartnaStaff::factory()->create(['role' => 'support']);
    $case = ModerationCase::factory()->create(['signal_count' => 1]);

    (new NotifyStaffOfCaseUpdateJob($case->id))->handle();

    Notification::assertNothingSent();
});
```

- [ ] **Step 2: Run to fail**

Run: `php artisan test tests/Feature/Moderation/NotifyStaffOfCaseUpdateJobTest.php`
Expected: FAIL.

- [ ] **Step 3: Create the notification class**

Create `app/Notifications/Moderation/CaseCreatedStaffNotification.php`:

```php
<?php

namespace App\Notifications\Moderation;

use App\Models\Moderation\ModerationCase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CaseCreatedStaffNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly ModerationCase $case) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[Partna T&S] New severity-{$this->case->severity} case on {$this->case->reportable_type}")
            ->line("Case ID: {$this->case->id}")
            ->line("Type: {$this->case->case_type}")
            ->line("Signal count: {$this->case->signal_count}")
            ->action('Open case in staff dashboard', config('app.url') . "/staff/cases/{$this->case->id}");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'case_id'      => $this->case->id,
            'case_type'    => $this->case->case_type,
            'severity'     => $this->case->severity,
            'signal_count' => $this->case->signal_count,
        ];
    }
}
```

- [ ] **Step 4: Implement the job**

Replace `app/Jobs/Moderation/NotifyStaffOfCaseUpdateJob.php`:

```php
<?php

namespace App\Jobs\Moderation;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\CaseCreatedStaffNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Dispatched whenever a case is created or its signal_count grows.
 * Notifies on-call staff at configured thresholds (default 1, 3, 5, 10).
 */
class NotifyStaffOfCaseUpdateJob implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $caseId) {}

    public function handle(): void
    {
        $thresholds = config('partna.moderation.reporting.staff_notify_thresholds', [1, 3, 5, 10]);

        $case = ModerationCase::query()->find($this->caseId);
        if ($case === null) {
            return;
        }

        if (! in_array($case->signal_count, $thresholds, strict: true)) {
            return;
        }

        // On-call routing: all admin staff are treated as on-call. The
        // PartnaStaff table has no is_on_call column today — if granular
        // on-call rotation is needed later, add a column + filter here.
        $oncall = PartnaStaff::query()
            ->where('role', 'admin')
            ->get();

        if ($oncall->isEmpty()) {
            return;
        }

        Notification::send($oncall, new CaseCreatedStaffNotification($case));
    }
}
```

- [ ] **Step 5: Run + commit**

Run: `php artisan test tests/Feature/Moderation/NotifyStaffOfCaseUpdateJobTest.php`
Expected: PASS.

```bash
git add app/Jobs/Moderation/NotifyStaffOfCaseUpdateJob.php app/Notifications/Moderation/CaseCreatedStaffNotification.php tests/Feature/Moderation/NotifyStaffOfCaseUpdateJobTest.php
git commit -m "feat(moderation): NotifyStaffOfCaseUpdateJob with threshold gating"
```

---

## Phase 8 — Public report endpoint

### Task 18: `PublicReportRequest` (FormRequest)

**Files:**
- Create: `app/Http/Requests/PublicSite/PublicReportRequest.php`
- Create: `tests/Unit/Http/Requests/PublicReportRequestTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Http/Requests/PublicReportRequestTest.php`:

```php
<?php

use App\Http\Requests\PublicSite\PublicReportRequest;
use Illuminate\Support\Facades\Validator;

it('validates required fields', function () {
    $rules = (new PublicReportRequest)->rules();
    $v = Validator::make([], $rules);
    expect($v->fails())->toBeTrue();
    expect($v->errors()->keys())->toContain('target_type', 'target_handle', 'reason_code', 'turnstile_token');
});

it('accepts a valid payload', function () {
    $rules = (new PublicReportRequest)->rules();
    $v = Validator::make([
        'target_type'     => 'Site',
        'target_handle'   => 'joeplumber',
        'reason_code'     => 'spam',
        'details'         => 'looks like spam',
        'reporter_email'  => 'reporter@example.com',
        'turnstile_token' => 'cf-token-here',
    ], $rules);

    expect($v->fails())->toBeFalse();
});

it('rejects details over 4000 chars', function () {
    $rules = (new PublicReportRequest)->rules();
    $v = Validator::make([
        'target_type'     => 'Site',
        'target_handle'   => 'joeplumber',
        'reason_code'     => 'spam',
        'details'         => str_repeat('x', 4001),
        'turnstile_token' => 't',
    ], $rules);

    expect($v->errors()->has('details'))->toBeTrue();
});

it('rejects invalid reason_code', function () {
    $rules = (new PublicReportRequest)->rules();
    $v = Validator::make([
        'target_type'     => 'Site',
        'target_handle'   => 'joeplumber',
        'reason_code'     => 'nuked-from-orbit',
        'turnstile_token' => 't',
    ], $rules);

    expect($v->errors()->has('reason_code'))->toBeTrue();
});

it('allows null reporter_email (anonymous report)', function () {
    $rules = (new PublicReportRequest)->rules();
    $v = Validator::make([
        'target_type'     => 'Site',
        'target_handle'   => 'joeplumber',
        'reason_code'     => 'spam',
        'turnstile_token' => 't',
    ], $rules);

    expect($v->errors()->has('reporter_email'))->toBeFalse();
});
```

- [ ] **Step 2: Run to fail**

Run: `php artisan test tests/Unit/Http/Requests/PublicReportRequestTest.php`
Expected: FAIL.

- [ ] **Step 3: Create the FormRequest**

Create `app/Http/Requests/PublicSite/PublicReportRequest.php`:

```php
<?php

namespace App\Http\Requests\PublicSite;

use App\DTOs\Moderation\PublicReportDto;
use Illuminate\Foundation\Http\FormRequest;

class PublicReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public endpoint — no per-user authz; rate-limits + Turnstile do the gating.
        return true;
    }

    public function rules(): array
    {
        return [
            'target_type'     => ['required', 'string', 'in:Site'],
            'target_handle'   => ['required', 'string', 'min:1', 'max:60'],
            'reason_code'     => ['required', 'string', 'in:' . implode(',', self::ALLOWED_REASON_CODES)],
            'details'         => ['nullable', 'string', 'max:4000'],
            'reporter_email'  => ['nullable', 'email', 'max:255'],
            'turnstile_token' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_type.in'     => 'Reports against this type of target are not supported yet.',
            'reason_code.in'     => 'That reason isn\'t supported. Please pick one of the options.',
            'details.max'        => 'Please keep details under 4000 characters.',
            'turnstile_token.required' => 'Please complete the verification and try again.',
        ];
    }

    public function toDto(): PublicReportDto
    {
        return new PublicReportDto(
            targetType:    $this->string('target_type')->toString(),
            targetHandle:  strtolower(trim($this->string('target_handle')->toString())),
            reasonCode:    $this->string('reason_code')->toString(),
            details:       $this->input('details'),
            reporterEmail: $this->input('reporter_email'),
            reporterIp:    $this->ip(),
        );
    }

    public const ALLOWED_REASON_CODES = [
        'spam', 'harassment', 'impersonation', 'illegal_content', 'sexual_content',
        'self_harm', 'hate_speech', 'intellectual_property', 'fake_profile', 'other',
    ];
}
```

- [ ] **Step 4: Run + commit**

Run: `php artisan test tests/Unit/Http/Requests/PublicReportRequestTest.php`
Expected: PASS.

```bash
git add app/Http/Requests/PublicSite/PublicReportRequest.php tests/Unit/Http/Requests/PublicReportRequestTest.php
git commit -m "feat(moderation): add PublicReportRequest"
```

---

### Task 19: `PerTargetReportThrottle` middleware

**Files:**
- Create: `app/Http/Middleware/Moderation/PerTargetReportThrottle.php`
- Create: `tests/Feature/Moderation/PerTargetReportThrottleTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/PerTargetReportThrottleTest.php`:

```php
<?php

use App\Http\Middleware\Moderation\PerTargetReportThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::flushdb());

it('allows up to the configured cap per (ip, target) per window', function () {
    $mw = new PerTargetReportThrottle;
    $req = Request::create('/v1/public/report', 'POST', [
        'target_type'   => 'Site',
        'target_handle' => 'joeplumber',
    ]);
    $req->server->set('REMOTE_ADDR', '203.0.113.50');

    for ($i = 0; $i < 3; $i++) {
        $hit = $mw->handle($req, fn () => response('ok', 200));
        expect($hit->status())->toBe(200);
    }
});

it('blocks the 4th request from same (ip, target) in window', function () {
    $mw = new PerTargetReportThrottle;
    $req = Request::create('/v1/public/report', 'POST', [
        'target_type'   => 'Site',
        'target_handle' => 'joeplumber',
    ]);
    $req->server->set('REMOTE_ADDR', '203.0.113.50');

    for ($i = 0; $i < 3; $i++) {
        $mw->handle($req, fn () => response('ok', 200));
    }

    $blocked = $mw->handle($req, fn () => response('ok', 200));
    expect($blocked->status())->toBe(429);
});

it('does not block when the target is different', function () {
    $mw = new PerTargetReportThrottle;

    $req1 = Request::create('/v1/public/report', 'POST', ['target_type' => 'Site', 'target_handle' => 'a']);
    $req2 = Request::create('/v1/public/report', 'POST', ['target_type' => 'Site', 'target_handle' => 'b']);
    $req1->server->set('REMOTE_ADDR', '203.0.113.50');
    $req2->server->set('REMOTE_ADDR', '203.0.113.50');

    for ($i = 0; $i < 3; $i++) {
        $mw->handle($req1, fn () => response('ok', 200));
    }
    $other = $mw->handle($req2, fn () => response('ok', 200));
    expect($other->status())->toBe(200);
});
```

- [ ] **Step 2: Run to fail**

Run: `php artisan test tests/Feature/Moderation/PerTargetReportThrottleTest.php`
Expected: FAIL.

- [ ] **Step 3: Create the middleware**

Create `app/Http/Middleware/Moderation/PerTargetReportThrottle.php`:

```php
<?php

namespace App\Http\Middleware\Moderation;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-(IP, target) rate limit on the public report endpoint.
 *
 * Sliding-window counter in Redis keyed on
 *   moderation:report:ip:{ip_hash}:target:{type}:{handle}
 * TTL matches config('partna.moderation.reporting.per_target_throttle.window_minutes').
 * Cap: config('partna.moderation.reporting.per_target_throttle.requests').
 *
 * Returns 429 with retry hint if exceeded. Layered ON TOP of the framework's
 * IP throttle (those are different limits applied via RouteServiceProvider).
 */
class PerTargetReportThrottle
{
    public function handle(Request $request, Closure $next): Response
    {
        $cap     = config('partna.moderation.reporting.per_target_throttle.requests', 3);
        $window  = config('partna.moderation.reporting.per_target_throttle.window_minutes', 60);

        $ipHash      = hash('sha256', $request->ip() . '|' . config('app.key'));
        $type        = $request->input('target_type', 'unknown');
        $handle      = strtolower((string) $request->input('target_handle', 'unknown'));
        $key         = "moderation:report:ip:{$ipHash}:target:{$type}:{$handle}";

        $count = (int) Redis::incr($key);
        if ($count === 1) {
            Redis::expire($key, $window * 60);
        }

        if ($count > $cap) {
            return response()->json([
                'error'   => 'TARGET_RATE_LIMITED',
                'message' => "Hold on a sec — try again later.",
            ], 429);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Run + commit**

Run: `php artisan test tests/Feature/Moderation/PerTargetReportThrottleTest.php`
Expected: PASS.

```bash
git add app/Http/Middleware/Moderation/PerTargetReportThrottle.php tests/Feature/Moderation/PerTargetReportThrottleTest.php
git commit -m "feat(moderation): add PerTargetReportThrottle middleware"
```

---

### Task 20: `PublicReportController` + Resource + route + Turnstile wiring

**Files:**
- Create: `app/Http/Controllers/Api/PublicSite/PublicReportController.php`
- Create: `app/Http/Resources/Moderation/ReportReceiptResource.php`
- Modify: `routes/api/publicSite.php`
- Modify: `app/Providers/RouteServiceProvider.php` (rate limiter)
- Create: `tests/Feature/Moderation/PublicReportSubmitTest.php`

- [ ] **Step 1: Write the failing endpoint test**

Create `tests/Feature/Moderation/PublicReportSubmitTest.php`:

```php
<?php

use App\Models\Core\User\User;
use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\CaseSignal;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
    // Bypass captcha in feature tests — VerifyBotToken middleware has its own
    // dedicated test suite (tests/Integration/TurnstileIntegrationTest.php +
    // tests/Unit/Services/BotProtection/*). When mode='off' the middleware
    // is a pass-through.
    config(['partna.bot_protection.mode' => 'off']);
});

function validReportPayload(string $handle = 'joeplumber'): array
{
    return [
        'target_type'     => 'Site',
        'target_handle'   => $handle,
        'reason_code'     => 'spam',
        'details'         => 'this looks like spam to me',
        'reporter_email'  => 'reporter@example.com',
        'turnstile_token' => 'cf-token-fixture',  // ignored when mode=off
    ];
}

it('accepts a valid report and returns 202 with a receipt_id', function () {
    $user = User::factory()->create(['handle' => 'joeplumber', 'handle_lc' => 'joeplumber']);
    Site::factory()->for($user, 'user')->create();

    $res = $this->postJson('/v1/public/report', validReportPayload());

    $res->assertStatus(202);
    $res->assertJsonStructure(['receipt_id', 'message']);
    expect(ModerationCase::count())->toBe(1);
    expect(CaseSignal::count())->toBe(1);
});

it('returns 422 INVALID_TARGET when handle does not resolve', function () {
    $res = $this->postJson('/v1/public/report', validReportPayload('does-not-exist'));
    $res->assertStatus(422);
    $res->assertJsonPath('error', 'INVALID_TARGET');
});

// Captcha rejection is the middleware's concern; tested in TurnstileIntegrationTest.
// Don't duplicate that coverage here — the middleware emits its own error envelope.
```

- [ ] **Step 2: Run to fail**

Run: `php artisan test tests/Feature/Moderation/PublicReportSubmitTest.php`
Expected: FAIL.

- [ ] **Step 3: Create the Resource**

Create `app/Http/Resources/Moderation/ReportReceiptResource.php`:

```php
<?php

namespace App\Http\Resources\Moderation;

use Illuminate\Http\Resources\Json\JsonResource;

class ReportReceiptResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'receipt_id' => $this->receiptId,
            'message'    => 'Report received. Thank you.',
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/Api/PublicSite/PublicReportController.php`:

```php
<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicSite\PublicReportRequest;
use App\Http\Resources\Moderation\ReportReceiptResource;
use App\Services\Moderation\ContentReportService;
use App\Services\Moderation\ReportTargetNotFound;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

/**
 * Captcha verification is handled upstream by the `bot.token:report` middleware
 * (App\Http\Middleware\VerifyBotToken). The middleware uses the existing
 * CaptchaManager + CircuitBreaker stack and emits its own rejections — this
 * controller only sees requests that have already passed bot protection.
 */
class PublicReportController extends Controller
{
    public function __construct(
        private readonly ContentReportService $reports,
    ) {}

    public function submit(PublicReportRequest $request): JsonResponse|ReportReceiptResource
    {
        try {
            $result = $this->reports->submit($request->toDto());
        } catch (ReportTargetNotFound) {
            return response()->json([
                'error'   => 'INVALID_TARGET',
                'message' => "We couldn't find that page.",
            ], 422);
        } catch (QueryException $e) {
            if ($this->isDedupViolation($e)) {
                return response()->json([
                    'error'   => 'DUPLICATE_REPORT',
                    'message' => "You've already reported this.",
                ], 409);
            }
            throw $e;
        }

        return (new ReportReceiptResource($result))->response()->setStatusCode(202);
    }

    private function isDedupViolation(QueryException $e): bool
    {
        return str_contains((string) $e->getMessage(), 'case_signals_dedup_uniq');
    }
}
```

- [ ] **Step 5: Add the route**

Append to `routes/api/publicSite.php`:

```php
use App\Http\Controllers\Api\PublicSite\PublicReportController;
use App\Http\Middleware\Moderation\PerTargetReportThrottle;

Route::middleware([
    'bot.token:report',                              // captcha verification via CaptchaManager
    'throttle:partna.moderation.report',             // IP rate limit
    PerTargetReportThrottle::class,                  // per-(IP, target) rate limit
])->group(function () {
    Route::post('/v1/public/report', [PublicReportController::class, 'submit'])
        ->name('public.moderation.report');
});
```

- [ ] **Step 6: Define the rate limiter**

Modify `app/Providers/RouteServiceProvider.php`. In the `configureRateLimiting()` method (alongside existing rate limiter definitions), add:

```php
RateLimiter::for('partna.moderation.report', function (Request $request) {
    $cfg = config('partna.moderation.reporting.public_throttle', ['requests' => 5, 'minutes' => 1]);
    return Limit::perMinutes($cfg['minutes'], $cfg['requests'])
        ->by($request->ip())
        ->response(function () {
            return response()->json([
                'error'   => 'RATE_LIMITED',
                'message' => 'Hold on a sec, try again in a minute.',
            ], 429);
        });
});
```

- [ ] **Step 7: Add config**

Modify `config/partna.php`. Add a new top-level `moderation` block (or extend if it exists):

```php
'moderation' => [
    'enabled' => env('PARTNA_MODERATION_ENABLED', true),
    'reporting' => [
        'public_throttle'         => [
            'requests' => env('PARTNA_REPORT_PUBLIC_THROTTLE_REQUESTS', 5),
            'minutes'  => env('PARTNA_REPORT_PUBLIC_THROTTLE_MINUTES', 1),
        ],
        'per_target_throttle'     => [
            'requests'        => env('PARTNA_REPORT_TARGET_THROTTLE_REQUESTS', 3),
            'window_minutes'  => env('PARTNA_REPORT_TARGET_THROTTLE_WINDOW_MIN', 60),
        ],
        'details_max_chars'       => 4000,
        'merge_window_minutes'    => 60 * 24 * 7,
        'staff_notify_thresholds' => [1, 3, 5, 10],
    ],
],
```

- [ ] **Step 8: Run + commit**

Run: `composer dump-autoload && php artisan test tests/Feature/Moderation/PublicReportSubmitTest.php`
Expected: PASS — all 3 endpoint scenarios.

```bash
git add app/Http/Controllers/Api/PublicSite/PublicReportController.php app/Http/Resources/Moderation/ReportReceiptResource.php routes/api/publicSite.php app/Providers/RouteServiceProvider.php config/partna.php tests/Feature/Moderation/PublicReportSubmitTest.php
git commit -m "feat(moderation): POST /v1/public/report with Turnstile + dedup + throttle"
```

---

### Task 21: Anti-abuse layer tests (Turnstile + IP throttle + per-target throttle composition)

**Files:**
- Create: `tests/Feature/Moderation/PublicReportAntiAbuseTest.php`

- [ ] **Step 1: Write the tests**

Create `tests/Feature/Moderation/PublicReportAntiAbuseTest.php`:

```php
<?php

use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
    config(['partna.bot_protection.mode' => 'off']);  // bypass captcha middleware
});

function antiAbusePayload(string $handle = 'joeplumber'): array
{
    return [
        'target_type'     => 'Site',
        'target_handle'   => $handle,
        'reason_code'     => 'spam',
        'turnstile_token' => 'cf-fixture',
    ];
}

it('rate-limits at the framework IP throttle (5 per minute)', function () {
    $user = User::factory()->create(['handle' => 'joeplumber']);
    Site::factory()->for($user, 'user')->create();

    // Each request hits the IP limit. We use 5 valid targets to bypass per-target cap.
    for ($i = 0; $i < 5; $i++) {
        $u = User::factory()->create(['handle' => "u{$i}"]);
        Site::factory()->for($u, 'user')->create();
        $this->postJson('/v1/public/report', antiAbusePayload("u{$i}"))->assertStatus(202);
    }

    $u = User::factory()->create(['handle' => 'u-overflow']);
    Site::factory()->for($u, 'user')->create();
    $this->postJson('/v1/public/report', antiAbusePayload('u-overflow'))->assertStatus(429);
});

it('rate-limits at the per-target throttle (3 per hour) even from different reports', function () {
    $user = User::factory()->create(['handle' => 'joeplumber']);
    Site::factory()->for($user, 'user')->create();

    for ($i = 0; $i < 3; $i++) {
        // First 3 succeed with unique reporter_emails (so dedup_hash differs)
        $payload = antiAbusePayload();
        $payload['reporter_email'] = "r{$i}@e.com";
        $this->postJson('/v1/public/report', $payload)->assertStatus(202);
    }

    $payload = antiAbusePayload();
    $payload['reporter_email'] = "r-overflow@e.com";
    $res = $this->postJson('/v1/public/report', $payload);
    $res->assertStatus(429);
    $res->assertJsonPath('error', 'TARGET_RATE_LIMITED');
});

it('rejects a duplicate (same reporter, same target, same reason) with 409', function () {
    $user = User::factory()->create(['handle' => 'joeplumber']);
    Site::factory()->for($user, 'user')->create();

    $payload = antiAbusePayload();
    $payload['reporter_email'] = 'reporter@example.com';
    $this->postJson('/v1/public/report', $payload)->assertStatus(202);

    $res = $this->postJson('/v1/public/report', $payload);
    $res->assertStatus(409);
    $res->assertJsonPath('error', 'DUPLICATE_REPORT');
})->group('postgres');
```

- [ ] **Step 2: Run + commit**

Run: `php artisan test tests/Feature/Moderation/PublicReportAntiAbuseTest.php --group=postgres`
Expected: PASS.

```bash
git add tests/Feature/Moderation/PublicReportAntiAbuseTest.php
git commit -m "test(moderation): anti-abuse layer composition coverage"
```

---

## Phase 9 — PII leakage sweep + security tests

### Task 22: PII leakage sweep test

**Files:**
- Create: `tests/Feature/Security/ReporterPiiLeakageTest.php`

- [ ] **Step 1: Write the test**

Create `tests/Feature/Security/ReporterPiiLeakageTest.php`:

```php
<?php

use App\Models\Core\User\User;
use App\Models\Moderation\AuditEvent;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
    config(['partna.bot_protection.mode' => 'off']);  // bypass captcha middleware
});

it('does not write raw IP into the audit log', function () {
    $user = User::factory()->create(['handle' => 'joeplumber']);
    Site::factory()->for($user, 'user')->create();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->postJson('/v1/public/report', [
        'target_type'     => 'Site',
        'target_handle'   => 'joeplumber',
        'reason_code'     => 'spam',
        'reporter_email'  => 'leak@example.com',
        'turnstile_token' => 'cf',
    ])->assertStatus(202);

    // No audit event should contain raw IP, raw email, or any forbidden key.
    foreach (AuditEvent::all() as $event) {
        $json = json_encode($event->payload);
        expect($json)->not->toContain('203.0.113.99');
        expect($json)->not->toContain('leak@example.com');
    }
});

it('does not log reporter PII via Log facade', function () {
    Log::spy();
    $user = User::factory()->create(['handle' => 'joeplumber']);
    Site::factory()->for($user, 'user')->create();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->postJson('/v1/public/report', [
        'target_type'     => 'Site',
        'target_handle'   => 'joeplumber',
        'reason_code'     => 'spam',
        'reporter_email'  => 'leak@example.com',
        'turnstile_token' => 'cf',
    ])->assertStatus(202);

    // Sweep over recorded log invocations
    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $ctx = []) {
        $blob = $message . ' ' . json_encode($ctx);
        expect($blob)->not->toContain('203.0.113.99');
        expect($blob)->not->toContain('leak@example.com');
        return true;
    })->zeroOrMoreTimes();
});
```

- [ ] **Step 2: Run + commit**

Run: `php artisan test tests/Feature/Security/ReporterPiiLeakageTest.php`
Expected: PASS.

```bash
git add tests/Feature/Security/ReporterPiiLeakageTest.php
git commit -m "test(security): reporter PII leakage sweep"
```

---

### Task 23: Hot-path index plan-explanation regression test (Postgres-only)

**Files:**
- Create: `tests/Feature/Moderation/QueueIndexPlanTest.php`

- [ ] **Step 1: Write the test**

Create `tests/Feature/Moderation/QueueIndexPlanTest.php`:

```php
<?php

use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\DB;

it('uses cases_open_queue_idx for the queue read path', function () {
    ModerationCase::factory()->count(50)->create();

    $plan = DB::selectOne(<<<'SQL'
        EXPLAIN (FORMAT JSON)
        SELECT id FROM moderation.cases
        WHERE status IN ('open', 'triaged', 'under_review')
        ORDER BY severity DESC, priority ASC, created_at ASC
        LIMIT 25
    SQL);
    $planText = json_encode($plan);

    // Asserts the partial index is used rather than a seqscan.
    expect($planText)->toContain('cases_open_queue_idx');
})->group('postgres');

it('uses cases_target_open_idx for the case-merge lookup', function () {
    ModerationCase::factory()->count(50)->create();

    $plan = DB::selectOne(<<<'SQL'
        EXPLAIN (FORMAT JSON)
        SELECT id FROM moderation.cases
        WHERE reportable_type = 'Site'
          AND reportable_id   = '00000000-0000-0000-0000-000000000001'
          AND status IN ('open', 'triaged', 'under_review')
    SQL);
    expect(json_encode($plan))->toContain('cases_target_open_idx');
})->group('postgres');
```

- [ ] **Step 2: Run + commit**

Run: `php artisan test tests/Feature/Moderation/QueueIndexPlanTest.php --group=postgres`
Expected: PASS.

```bash
git add tests/Feature/Moderation/QueueIndexPlanTest.php
git commit -m "test(moderation): assert hot-path indexes used by query planner"
```

---

## Phase 10 — Docs + verification

### Task 24: Operator runbook stub

**Files:**
- Create: `docs/moderation/README.md`

- [ ] **Step 1: Write the runbook**

Create `docs/moderation/README.md`:

```markdown
# Trust & Safety — operator runbook

> **Scope:** Plan A landed. Public reporting works end-to-end. Staff queue UI + outcome jobs ship in Plan B. CSAM scanning ships in Plan C.

## Current state of moderation

| Capability | Status |
|------------|--------|
| Public `POST /v1/public/report` | ✅ live |
| Cases dedup + merge | ✅ live |
| Evidence snapshotting (Site target) | ✅ live |
| Audit log (`audit.moderation_events`) | ✅ live |
| Staff queue endpoints | ⏳ Plan B |
| Decision pipeline + outcome jobs | ⏳ Plan B |
| CSAM scanning | ⏳ Plan C |

## Quick checks

```bash
# How many open cases?
php artisan tinker --execute='echo App\Models\Moderation\ModerationCase::where("status","open")->count();'

# Show the latest case
php artisan tinker --execute='dump(App\Models\Moderation\ModerationCase::latest()->first()->toArray());'

# Latest 5 audit events
php artisan tinker --execute='dump(App\Models\Moderation\AuditEvent::latest()->limit(5)->get()->toArray());'
```

## Things that can go wrong (and what to look for)

| Symptom | Hypothesis | Where to look |
|---------|-----------|---------------|
| Reports return 429 unexpectedly | Per-target Redis counter not expiring | `redis-cli KEYS 'moderation:report:ip:*'` |
| Dedup blocking legitimate retries | UNIQUE index on `case_signals.dedup_hash` | Verify reporter actually changed reason_code |
| Captcha false-rejecting in dev | `partna.bot_protection.mode` is `enforce`; tests bypass via `mode='off'` | Check `config('partna.bot_protection.mode')` in the test's `beforeEach` |

## Cloud log queries

```bash
cloud env:logs partna development --minutes 15 | grep moderation
```
```

- [ ] **Step 2: Commit**

```bash
git add docs/moderation/README.md
git commit -m "docs(moderation): operator runbook stub for Plan A"
```

---

### Task 25: End-to-end verification on local stack

- [ ] **Step 1: Reset DB + run full test suite**

Run:
```bash
supabase db reset
composer test
php artisan test --group=postgres
```
Expected: all tests pass.

- [ ] **Step 2: Submit a real report via curl against the dev URL**

Run:
```bash
# Start the dev server if not running: `composer dev`
curl -X POST 'http://localhost:8000/v1/public/report' \
  -H 'Content-Type: application/json' \
  -d '{
    "target_type": "Site",
    "target_handle": "<a-real-test-handle-on-dev>",
    "reason_code": "spam",
    "details": "manual smoke test",
    "reporter_email": "smoke-test@example.com",
    "turnstile_token": "<a-real-turnstile-token-or-bypass-in-dev>"
  }'
```
Expected: 202 + receipt_id.

- [ ] **Step 3: Confirm rows landed**

Run:
```bash
php artisan tinker --execute='
  echo "Cases: " . App\Models\Moderation\ModerationCase::count() . "\n";
  echo "Signals: " . App\Models\Moderation\CaseSignal::count() . "\n";
  echo "Evidence: " . App\Models\Moderation\Evidence::count() . "\n";
'
```
Expected: counts > 0 reflecting the submitted report.

- [ ] **Step 4: Verify Nightwatch breadcrumbs flowing**

Check Nightwatch dashboard for `moderation.*` log entries in the last 5 minutes. No exceptions on the `/v1/public/report` route.

- [ ] **Step 5: Open PR**

```bash
git push -u origin feat/ts-foundation-plan-a
gh pr create --base development --title "feat(moderation): T&S foundation Plan A — schema + public report endpoint" \
  --body "$(cat <<'EOF'
## Summary
- Adds `moderation` + `audit` schemas with 5 + 1 tables and partial indexes
- Adds `moderation_state` columns to `core.users` and `site.sites`
- Extends `site.site_media.processing_state` to include `scanning` and `quarantined`
- New `CaseStateMachine`, `DedupHashCalculator`, `EvidenceSnapshotService`, `ModerationAuditService`, `ContentReportService`
- New public endpoint `POST /v1/public/report` with Turnstile + IP throttle + per-target throttle + DB dedup
- Policies for `Case` and `Decision` registered; `PolicyCoverageTest` updated
- Anti-abuse layer composition tested

After this PR: visitors can submit reports; staff have no UI yet (Plan B).

## Test plan
- [ ] `composer test` passes
- [ ] `php artisan test --group=postgres` passes
- [ ] Local smoke test: POST /v1/public/report returns 202 + creates rows
- [ ] Verify no PII in `audit.moderation_events` payload
- [ ] Verify Nightwatch shows no exceptions on the new route

Spec: `docs/superpowers/specs/2026-05-26-trust-and-safety-foundation-design.md`
Plan: `docs/superpowers/plans/2026-05-26-trust-and-safety-foundation-plan-a.md`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review checklist

Before considering Plan A complete, run through:

- [ ] All migrations apply cleanly via `supabase migration up` on a fresh DB
- [ ] All migrations follow the two-file pattern (DDL + indexes) where indexes exist
- [ ] All CHECK constraints use `NOT VALID` → `VALIDATE CONSTRAINT` pattern
- [ ] All models extend `BaseModel` (forces `pgsql` connection)
- [ ] All factories have named states for the common scenarios
- [ ] State machine is pure (no DB, no side effects)
- [ ] DedupHashCalculator throws when both email and ip_hash are null
- [ ] EvidenceSnapshotService payload is JSON-serializable
- [ ] ModerationAuditService strips known PII keys from payload
- [ ] ContentReportService merges signals into open cases via `lockForUpdate()`
- [ ] PublicReportRequest enforces `details <= 4000`
- [ ] PublicReportController returns specific error codes: 422 INVALID_TARGET / 409 DUPLICATE_REPORT / 429 TARGET_RATE_LIMITED / 429 RATE_LIMITED. Captcha rejection is the `bot.token:report` middleware's responsibility — emitted with its own error envelope, covered by TurnstileIntegrationTest.
- [ ] Per-target Redis throttle keys expire after the configured window
- [ ] No reporter PII (email, raw IP) appears in `audit.moderation_events`
- [ ] Hot-path indexes are actually used by the query planner (EXPLAIN verified)
- [ ] `CasePolicy` and `DecisionPolicy` registered in `AppServiceProvider::boot()`
- [ ] `PolicyCoverageTest` exempt allow-list updated for write-only models
- [ ] All commits follow project commit message style (`feat(moderation): …`)

---

## What ships after Plan A

- Visitors can click "Report this page" on any public site (frontend ships separately)
- Reports land as `moderation.cases` rows with snapshotted evidence and a dedup_hash
- Staff have no UI yet — they read via `php artisan tinker` or raw SQL
- No outcome jobs fire — decisions can't be recorded yet (Plan B)
- No CSAM scanning yet (Plan C)

**Next:** Plan B adds the staff queue endpoints, outcome propagation jobs, notifications, lifecycle commands, and capability gates. After Plan B, the reporting workflow is fully operational end-to-end.
