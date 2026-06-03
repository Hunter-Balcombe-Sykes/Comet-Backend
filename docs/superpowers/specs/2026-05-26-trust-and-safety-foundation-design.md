# Trust & Safety Foundation — Backend Design

**Date:** 2026-05-26
**Status:** Design approved; ready for implementation plan
**Scope:** Partna backend only (Laravel 12 + Supabase + PostgreSQL). Frontend "Report this page" affordance and staff queue UI live in the separate frontend repo.
**Author:** Brainstorm session with Josh

---

## 1. Overview

A unified trust-and-safety foundation for Partna that handles two distinct ingestion pipelines feeding a single moderation backbone:

1. **Content reporting** — any visitor on `<handle>.partna.au` can click "Report this page" and submit a report (anonymous or with email). Reports are deduped, snapshotted, and surfaced to staff for review.
2. **CSAM hash scanning** — every user-uploaded image transits a quarantine R2 bucket where Cloudflare's CSAM Scanning Tool checks it against the NCMEC PhotoDNA database before promotion to the production bucket. Matches trigger automatic quarantine, account suspension, and a CyberTipline report.

Both pipelines write into a shared `moderation` schema centered on a `cases` table. A case can be fed by multiple signals (5 reports against the same site = 1 case with signal_count=5). Cases produce immutable decisions; decisions trigger actions (cache purge, suspension, notifications, NCMEC submission). The result: one queue, one decision log, one audit trail, one propagation layer — regardless of how the signal arrived.

The foundation is deliberately shaped to scale: every deferred feature (appeals, trusted flaggers, DSA statement-of-reasons enums, ML-based detection, transparency reporting) is purely additive against this schema. No retrofit migrations required when those features land.

## 2. Goals

- Provide a public "Report this page" endpoint with anti-abuse layering (Turnstile, IP + per-target rate limits, dedup) that any visitor can use without an account
- Allow anonymous reports while preventing trivial weaponization
- Capture an immutable evidence snapshot at signal time so we can defend decisions months later even if the reported content has been edited
- Detect known CSAM at the upload boundary and auto-action with full legal preservation
- File CyberTipline reports to NCMEC within the regulatory window with retry + audit
- Give staff a single AAL2-gated queue covering both reports and CSAM matches, sorted by severity then age
- Propagate moderation outcomes through the existing `SyncSubdomainToKvJob` so hidden pages disappear from the edge cache atomically with the decision
- Build the data model durably enough that appeals, trusted flaggers, transparency reporting, and additional detection categories are additive — no schema migrations of existing rows

## 3. Non-goals

- Frontend "Report this page" button, modal, and staff queue UI (separate frontend plan)
- Appeals workflow (deferred — additive new table when needed; see §14)
- DSA trusted-flagger registration / prioritization (deferred — additive new table; triggered when EU traffic is material)
- DSA statement-of-reasons enum schema (deferred — start as `decision_reason` text; formalize when EU transparency reporting becomes real)
- EU Transparency Database batch submitter (deferred — same trigger)
- Reporter reputation scoring (deferred — not needed below ~thousands of reports/month)
- ML-based detection beyond hash matching (deferred — separate signal source, additive)
- Detection of non-CSAM illegal categories (terrorism, NCII) via Cloudflare's tool (deferred — same plumbing, opt in per category later)
- Backfill-scan of existing production media (decided: grandfathered; new uploads only — see §4)
- Manual eSafety takedown notice workflow (deferred — schema supports it via `case_type='manual'`; UI/process work follows)
- SLA dashboards and priority scoring algorithms (start with `priority` int + age-sorted queue)

## 4. Locked decisions

From the design brainstorm (in conversation order):

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Anonymous reports allowed? | **Yes** — `reporter_email` nullable | Lowers barrier for harassment victims; abuse mitigated by Turnstile + IP rate limit + dedup |
| Reportable targets day-one? | **Public Site only** | Polymorphism stays in schema; only `App\Models\Core\Professional\User` (= the Site owner) is wired up. SiteMedia + Block fast-follow |
| Auto-notify reported user on outcome? | **Yes for hide/suspend; no for warn** | Aligns with industry "statement of reasons" practice; warn-level outcomes don't yet have an internal warnings UI |
| Separate R2 quarantine bucket? | **Yes** | Cleaner security boundary; quarantine has zero public access policy; no path-aware bucket policies needed |
| Backfill-scan existing production media? | **No** | Pre-beta, effectively no existing media; schema reserves a `scanned_at` nullable timestamp so future backfill is a `WHERE scanned_at IS NULL` job if ever needed |
| CSAM 90-day quarantine retention | **Delete binary at 90 days, keep metadata indefinitely** | Legal preservation period over; reduces stored CSAM footprint; case + audit metadata satisfies long-term audit |
| Auto-suspend on CSAM match? | **Yes, with override path** | Industry standard; hash matching near-zero false-positive; override requires AAL2 + reason + second-staff approval |
| Scope of Cloudflare scanning day-one? | **CSAM only** | Each illegal-content category has independent compliance ramifications; add layer-by-layer when ready |
| NCMEC designated contact? | **Josh Hunter (founder)** | Default for small team; can be reassigned via NCMEC ESP portal at any time |
| Unified `cases` abstraction over separate report/scan-match tables? | **Yes** | One queue, one decision log, one propagation layer; future signal sources (eSafety, ML, trusted flaggers) plug in without touching the back half |
| Decisions immutable, appeals create new decisions? | **Yes** | Legally defensible audit trail; appeals additive (deferred but reserved) |

## 5. Architecture approach

**Signals → Cases → Decisions → Actions.** Four layers, separated by responsibility.

```
SIGNALS (how problems enter)
  • Human reporter via POST /v1/public/report
  • Cloudflare CSAM webhook → POST /v1/internal/cloudflare-csam-webhook
  • [future: trusted_flagger_signal, ML-classifier, eSafety_takedown_notice]
                                        │
                                        ▼ (signals get bundled into)
CASES (the unit of review/action)
  • moderation.cases — polymorphic reportable_type/_id, severity, status, signal_count
  • One case per (reportable_type, reportable_id) while status is open/triaged/under_review
                                        │
                                        ▼ (cases produce)
DECISIONS (immutable)
  • moderation.decisions — case_id, decision_type, reason, decided_by_*, auto_actioned
  • Never updated. Appeals (future) create NEW decisions linked via supersedes_decision_id
                                        │
                                        ▼ (decisions trigger)
ACTIONS (effects, tracked in moderation.action_log)
  • SyncSubdomainToKvJob          (existing job — purge edge cache)
  • SuspendUserJob                 (set users.moderation_state)
  • SuspendSiteJob                 (set sites.moderation_state)
  • QuarantineMediaJob             (set site_media.processing_state='quarantined')
  • FileCyberTipReportJob          (NCMEC submission via outbox pattern)
  • NotifyReportedUserJob          (statement of reasons email)
  • NotifyReporterJob              (outcome notification, only if reporter_email present)
  • NotifyOnCallStaffJob           (Slack/email — for CSAM auto-actions)
```

Service-layer separation, identical pattern to other Partna features:

- `PublicReportController` is thin — Turnstile + FormRequest, calls service, returns 202
- `CloudflareCsamWebhookController` verifies signature, calls service, returns 200
- `ContentReportService` orchestrates the human-report pipeline (eligibility, dedup, snapshot, case-merge-or-create, dispatch)
- `CsamMatchHandlerService` orchestrates the auto-action pipeline (case + decision + quarantine + suspend + NCMEC)
- `ModerationCaseService` is the case lifecycle layer (open → triaged → under_review → resolved/auto_actioned)
- `ModerationDecisionService` writes decisions + dispatches the appropriate action jobs based on `decision_type`
- `EvidenceSnapshotService` captures the immutable evidence row at signal time
- `NcmecSubmissionService` handles the outbox-pattern CyberTipline submission

**Alternatives considered and rejected:**

- **Two parallel features (separate report + CSAM tables, separate queues, separate decision logs)** — rejected. Doubles the propagation surface, makes future signal sources require their own pipeline, and the staff queue becomes a multi-tab mess. The `cases` abstraction is the load-bearing architectural choice.
- **Treat reports as the canonical entity; CSAM creates a synthetic report** — rejected. Forces the report shape onto a signal that has no reporter and no narrative reason. The `cases + signals` model represents both faithfully.
- **Native PostgreSQL enums for `decision_type`, `case_type`, etc.** — rejected per project convention. `varchar + CHECK` constraints allow new values without `ALTER TYPE` migrations.

## 6. Data model

### 6.1 New schema

```sql
CREATE SCHEMA IF NOT EXISTS moderation;
```

Joins the existing schema set: `public`, `core`, `site`, `notifications`, `analytics`, **`moderation`**.

### 6.2 New table: `moderation.cases`

Migration: `supabase/migrations/<timestamp>_create_moderation_schema.sql` (DDL inside `BEGIN/COMMIT`).

```sql
CREATE TABLE moderation.cases (
    id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    case_type                VARCHAR(32) NOT NULL,
    reportable_type          VARCHAR(64) NOT NULL,
    reportable_id            UUID NOT NULL,
    reportable_owner_user_id UUID NULL REFERENCES core.users(id) ON DELETE SET NULL,
    severity                 SMALLINT NOT NULL DEFAULT 2,
    status                   VARCHAR(20) NOT NULL DEFAULT 'open',
    signal_count             INTEGER NOT NULL DEFAULT 1,
    auto_actioned            BOOLEAN NOT NULL DEFAULT FALSE,
    priority                 SMALLINT NOT NULL DEFAULT 5,
    sla_due_at               TIMESTAMPTZ NULL,
    triaged_at               TIMESTAMPTZ NULL,
    triaged_by_staff_id      UUID NULL REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    resolved_at              TIMESTAMPTZ NULL,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT cases_case_type_check CHECK (case_type IN (
        'content_report',
        'csam_match',
        'trusted_flagger',
        'manual',
        'esafety_takedown'
    )),
    CONSTRAINT cases_reportable_type_check CHECK (reportable_type IN (
        'Site',
        'SiteMedia',
        'User',
        'Block',
        'Service'
    )),
    CONSTRAINT cases_severity_check CHECK (severity BETWEEN 1 AND 5),
    CONSTRAINT cases_status_check CHECK (status IN (
        'open',
        'triaged',
        'under_review',
        'resolved',
        'auto_actioned'
    )),
    CONSTRAINT cases_signal_count_check CHECK (signal_count >= 1),
    CONSTRAINT cases_priority_check CHECK (priority BETWEEN 1 AND 10)
);
```

**Indexes** (separate file `<timestamp+1>_create_moderation_indexes.sql`, `CREATE INDEX CONCURRENTLY`):

```sql
-- Hot path: staff queue (only open work indexed)
CREATE INDEX CONCURRENTLY IF NOT EXISTS cases_open_queue_idx
    ON moderation.cases (severity DESC, priority ASC, created_at ASC)
    WHERE status IN ('open', 'triaged', 'under_review');

-- Dedup / case-merge lookup: "is there an open case on this target?"
CREATE INDEX CONCURRENTLY IF NOT EXISTS cases_target_open_idx
    ON moderation.cases (reportable_type, reportable_id)
    WHERE status IN ('open', 'triaged', 'under_review');

-- SLA breach scanner
CREATE INDEX CONCURRENTLY IF NOT EXISTS cases_sla_due_idx
    ON moderation.cases (sla_due_at)
    WHERE status IN ('open', 'triaged', 'under_review') AND sla_due_at IS NOT NULL;

-- Owner lookup (e.g. "all open cases against user X")
CREATE INDEX CONCURRENTLY IF NOT EXISTS cases_owner_status_idx
    ON moderation.cases (reportable_owner_user_id, status)
    WHERE reportable_owner_user_id IS NOT NULL;
```

**Field rationale:**

- `case_type` widens to absorb future signal sources without schema changes.
- `reportable_type` + `reportable_id` is the polymorphic target. `reportable_owner_user_id` is denormalised for "all cases against a user" queries — kept in sync by the service layer at write time; `ON DELETE SET NULL` preserves the case if the user is hard-deleted.
- `severity` is 1–5: 1 = trivial, 5 = legal-mandatory action. CSAM matches always severity 5. Human reports default to 2; escalates with `signal_count`.
- `signal_count` is denormalised — read-heavy queue endpoint can't afford joining and aggregating signals every render. Updated transactionally when signals merge into a case.
- `priority` separates from `severity`: severity is "how bad if true," priority is "how urgent." A severity-3 case with 10 signals may be priority 2; a severity-5 CSAM auto-actioned case is priority 1 for "needs review of auto-action."
- Partial indexes on open states only — historical cases (years of resolved work) don't bloat the hot path.

### 6.3 New table: `moderation.case_signals`

```sql
CREATE TABLE moderation.case_signals (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    case_id             UUID NOT NULL REFERENCES moderation.cases(id) ON DELETE CASCADE,
    signal_source       VARCHAR(32) NOT NULL,
    signal_data         JSONB NOT NULL DEFAULT '{}'::JSONB,
    reporter_user_id    UUID NULL REFERENCES core.users(id) ON DELETE SET NULL,
    reporter_email      VARCHAR(255) NULL,
    reporter_ip_hash    VARCHAR(64) NULL,
    reason_code         VARCHAR(64) NOT NULL,
    reason_details      TEXT NULL,
    dedup_hash          VARCHAR(64) NOT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT case_signals_signal_source_check CHECK (signal_source IN (
        'content_report',
        'csam_scan',
        'trusted_flagger',
        'manual_staff',
        'esafety_notice'
    )),
    CONSTRAINT case_signals_reason_code_check CHECK (reason_code IN (
        'spam',
        'harassment',
        'impersonation',
        'illegal_content',
        'sexual_content',
        'self_harm',
        'hate_speech',
        'intellectual_property',
        'fake_profile',
        'other',
        'auto_csam_hash_match',
        'auto_other'
    )),
    CONSTRAINT case_signals_details_length CHECK (
        reason_details IS NULL OR length(reason_details) <= 4000
    )
);

-- Strict dedup at the DB level — same signal can never be recorded twice
CREATE UNIQUE INDEX case_signals_dedup_uniq ON moderation.case_signals (dedup_hash);

CREATE INDEX case_signals_case_idx ON moderation.case_signals (case_id, created_at);
CREATE INDEX case_signals_reporter_user_idx ON moderation.case_signals (reporter_user_id)
    WHERE reporter_user_id IS NOT NULL;
CREATE INDEX case_signals_reporter_ip_idx ON moderation.case_signals (reporter_ip_hash, created_at)
    WHERE reporter_ip_hash IS NOT NULL;
```

**Field rationale:**

- `signal_data` JSONB carries source-specific payload — Cloudflare's match metadata for CSAM signals; the original FormRequest input for human reports.
- `reporter_email` is nullable per locked decision (anonymous reports allowed). Stored as plain VARCHAR — not CITEXT — because it's not used for joins, only for outbound contact and dedup.
- `reporter_ip_hash` is `sha256(ip + APP_KEY)` — preserves "is this a high-volume reporter" lookup without retaining the raw IP (PII minimisation; regulators are picky here).
- `dedup_hash` = `sha256(reportable_type + reportable_id + (reporter_email | reporter_ip_hash) + reason_code)`. Same person reporting the same target for the same reason → unique constraint blocks. Different reason from same person → new signal, may or may not merge into the same case.
- `reason_code` is a small enum-via-CHECK; widens by migration when new categories emerge.

### 6.4 New table: `moderation.evidence`

```sql
CREATE TABLE moderation.evidence (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    case_id             UUID NOT NULL REFERENCES moderation.cases(id) ON DELETE CASCADE,
    signal_id           UUID NULL REFERENCES moderation.case_signals(id) ON DELETE SET NULL,
    evidence_type       VARCHAR(32) NOT NULL,
    payload             JSONB NOT NULL,
    content_hash        VARCHAR(64) NULL,
    captured_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT evidence_type_check CHECK (evidence_type IN (
        'content_snapshot',
        'csam_hash_match',
        'upload_metadata',
        'staff_attachment'
    ))
);

CREATE INDEX evidence_case_idx ON moderation.evidence (case_id, captured_at);
CREATE INDEX evidence_content_hash_idx ON moderation.evidence (content_hash)
    WHERE content_hash IS NOT NULL;
```

**Field rationale:**

- The `payload` JSONB shape varies by `evidence_type`. For `content_snapshot` it's a frozen capture of the target's name, bio, gallery URLs, block contents, captured via `EvidenceSnapshotService` at signal time. For `csam_hash_match` it's the Cloudflare match payload (matched hash, source database, confidence). For `upload_metadata` it's the upload context (filename, mime, uploader IP at upload time, R2 key).
- **Critical correctness property**: evidence rows are insert-only. The service layer never updates them. The original_filename / content_hash / gallery URLs captured at report time are what staff sees, even if the user has since edited or deleted the underlying content.

### 6.5 New table: `moderation.decisions`

```sql
CREATE TABLE moderation.decisions (
    id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    case_id                  UUID NOT NULL REFERENCES moderation.cases(id) ON DELETE RESTRICT,
    decision_type            VARCHAR(32) NOT NULL,
    reason                   TEXT NULL,
    decided_by_staff_id      UUID NULL REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    decided_by_system        BOOLEAN NOT NULL DEFAULT FALSE,
    auto_actioned            BOOLEAN NOT NULL DEFAULT FALSE,
    supersedes_decision_id   UUID NULL REFERENCES moderation.decisions(id) ON DELETE SET NULL,
    second_staff_approval_id UUID NULL REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    second_staff_approved_at TIMESTAMPTZ NULL,
    decided_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT decisions_decision_type_check CHECK (decision_type IN (
        'dismiss',
        'warn',
        'hide_content',
        'hide_site',
        'suspend_user',
        'ban_user',
        'override_csam_auto_action',
        'escalate_law_enforcement',
        'escalate_esafety'
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

CREATE INDEX decisions_case_idx ON moderation.decisions (case_id, decided_at);
CREATE INDEX decisions_supersedes_idx ON moderation.decisions (supersedes_decision_id)
    WHERE supersedes_decision_id IS NOT NULL;
```

**Field rationale:**

- `decisions_actor_xor` ensures exactly one of `decided_by_staff_id` (human) or `decided_by_system=true` is set — never both, never neither. Prevents the bug class where a decision row has ambiguous attribution.
- `decisions_csam_override_requires_second_staff` enforces at the DB level that overriding a CSAM auto-action requires a second-staff approval. This is the kind of constraint that gets removed in a sloppy migration if it lives only in application code; making it a DB constraint means even direct SQL writes can't bypass it.
- `supersedes_decision_id` is the appeals hook. Day-one: never populated. When appeals land, an appeal-overturn writes a new decision with `supersedes_decision_id` pointing to the original. Both rows stay; lineage is queryable.
- `ON DELETE RESTRICT` on `case_id` — cases can't be hard-deleted while decisions exist. Resolved cases are kept indefinitely (compliance + audit). Deletion is via redaction (clear PII columns), not row removal.

### 6.6 New table: `moderation.action_log`

```sql
CREATE TABLE moderation.action_log (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    decision_id         UUID NOT NULL REFERENCES moderation.decisions(id) ON DELETE CASCADE,
    action_type         VARCHAR(48) NOT NULL,
    action_target       JSONB NOT NULL DEFAULT '{}'::JSONB,
    job_uuid            VARCHAR(36) NULL,
    status              VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts            SMALLINT NOT NULL DEFAULT 0,
    failure_reason      TEXT NULL,
    dispatched_at       TIMESTAMPTZ NULL,
    completed_at        TIMESTAMPTZ NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT action_log_action_type_check CHECK (action_type IN (
        'sync_subdomain_kv',
        'suspend_user',
        'suspend_site',
        'quarantine_media',
        'file_cybertip_report',
        'notify_reported_user',
        'notify_reporter',
        'notify_oncall_staff',
        'purge_cloudflare_cache',
        'redact_reporter_pii'
    )),
    CONSTRAINT action_log_status_check CHECK (status IN (
        'pending',
        'dispatched',
        'completed',
        'failed',
        'cancelled'
    ))
);

CREATE INDEX action_log_decision_idx ON moderation.action_log (decision_id, created_at);
CREATE INDEX action_log_pending_idx ON moderation.action_log (status, created_at)
    WHERE status IN ('pending', 'dispatched');
```

**Why a separate action_log instead of dispatching jobs and trusting Horizon's records:** moderation actions must be auditable on a timeline of months/years. Horizon's job records are pruned. Our action_log retains the dispatch record + completion status forever, with the link back to the decision that triggered it. If a `suspend_user` action fails silently, the partial index `WHERE status IN ('pending', 'dispatched')` makes the orphan visible to a Nightwatch alert.

### 6.7 New table: `moderation.audit_log`

```sql
CREATE TABLE moderation.audit_log (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    actor_kind      VARCHAR(16) NOT NULL,
    actor_staff_id  UUID NULL REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    action          VARCHAR(64) NOT NULL,
    target_type     VARCHAR(32) NULL,
    target_id       UUID NULL,
    payload         JSONB NOT NULL DEFAULT '{}'::JSONB,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT audit_log_actor_kind_check CHECK (actor_kind IN ('staff', 'system')),
    CONSTRAINT audit_log_actor_xor CHECK (
        (actor_kind = 'staff' AND actor_staff_id IS NOT NULL)
        OR
        (actor_kind = 'system' AND actor_staff_id IS NULL)
    )
);

CREATE INDEX audit_log_staff_idx ON moderation.audit_log (actor_staff_id, created_at)
    WHERE actor_staff_id IS NOT NULL;
CREATE INDEX audit_log_target_idx ON moderation.audit_log (target_type, target_id, created_at)
    WHERE target_id IS NOT NULL;
CREATE INDEX audit_log_action_idx ON moderation.audit_log (action, created_at);
```

**Rationale:** every staff action against any moderation entity writes here. Independent of decision/action records — this is the "who touched what, when" log used for staff-conduct investigations, regulator transparency requests, and the "who dismissed this case I'm now reopening?" workflow.

### 6.8 New table: `moderation.csam_quarantine`

```sql
CREATE TABLE moderation.csam_quarantine (
    id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    case_id                  UUID NOT NULL REFERENCES moderation.cases(id) ON DELETE RESTRICT,
    site_media_id            UUID NOT NULL REFERENCES site.site_media(id) ON DELETE RESTRICT,
    uploader_user_id         UUID NULL REFERENCES core.users(id) ON DELETE SET NULL,
    uploader_ip_hash         VARCHAR(64) NULL,
    upload_user_agent        TEXT NULL,
    original_filename        VARCHAR(255) NULL,
    original_mime            VARCHAR(100) NULL,
    original_size_bytes      BIGINT NULL,
    content_hash             VARCHAR(128) NOT NULL,
    cloudflare_match_payload JSONB NOT NULL,
    r2_quarantine_key        TEXT NOT NULL,
    r2_binary_deleted        BOOLEAN NOT NULL DEFAULT FALSE,
    r2_binary_deleted_at     TIMESTAMPTZ NULL,
    preservation_expires_at  TIMESTAMPTZ NOT NULL,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX csam_quarantine_site_media_uniq ON moderation.csam_quarantine (site_media_id);
CREATE INDEX csam_quarantine_preservation_idx ON moderation.csam_quarantine (preservation_expires_at)
    WHERE r2_binary_deleted = FALSE;
CREATE INDEX csam_quarantine_uploader_idx ON moderation.csam_quarantine (uploader_user_id)
    WHERE uploader_user_id IS NOT NULL;
```

**Field rationale:**

- `preservation_expires_at` = `created_at + 90 days`. The `moderation:expire-csam-quarantine` job (daily) finds rows with `r2_binary_deleted=false AND preservation_expires_at < NOW()`, deletes the binary from R2, sets `r2_binary_deleted=true` + timestamp. Metadata stays.
- `content_hash` is stored as `VARCHAR(128)` (room for SHA-512 or composite hashes); Cloudflare's match payload may include both the matched hash and a separate perceptual hash.
- `ON DELETE RESTRICT` on both case_id and site_media_id — these are legally preserved records; can only be removed via the explicit 90-day expiry path.

### 6.9 New table: `moderation.ncmec_submissions`

```sql
CREATE TABLE moderation.ncmec_submissions (
    id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    csam_quarantine_id       UUID NOT NULL REFERENCES moderation.csam_quarantine(id) ON DELETE RESTRICT,
    payload                  JSONB NOT NULL,
    status                   VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts                 SMALLINT NOT NULL DEFAULT 0,
    ncmec_tip_id             VARCHAR(64) NULL,
    ncmec_response_payload   JSONB NULL,
    last_error               TEXT NULL,
    submitted_at             TIMESTAMPTZ NULL,
    response_received_at     TIMESTAMPTZ NULL,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT ncmec_submissions_status_check CHECK (status IN (
        'pending',
        'submitting',
        'submitted',
        'failed',
        'manual_fallback_required'
    ))
);

CREATE INDEX ncmec_submissions_pending_idx ON moderation.ncmec_submissions (status, created_at)
    WHERE status IN ('pending', 'submitting', 'failed');
CREATE INDEX ncmec_submissions_quarantine_idx ON moderation.ncmec_submissions (csam_quarantine_id);
```

**Why an outbox table instead of dispatching the API call from a job directly:** if the worker dies mid-submission, we lose visibility into whether NCMEC received the report. With the outbox row, the worker writes the row first, marks it `submitting`, calls the API, then updates to `submitted` with `ncmec_tip_id` from the response. A separate `moderation:retry-ncmec-submissions` console command picks up stuck rows. The status `manual_fallback_required` covers the case where automated submission has failed N times — Nightwatch alerts and a human files via NCMEC's web portal.

### 6.10 Modifications to existing tables

#### `site.site_media`

Extend the `processing_state` CHECK constraint to add `'scanning'` and `'quarantined'`:

```sql
-- Two-step: NOT VALID then VALIDATE per migrations CONVENTIONS.md
BEGIN;
ALTER TABLE site.site_media
    DROP CONSTRAINT IF EXISTS site_media_processing_state_check;
ALTER TABLE site.site_media
    ADD CONSTRAINT site_media_processing_state_check
    CHECK (processing_state IN ('pending', 'processing', 'scanning', 'ready', 'failed', 'quarantined'))
    NOT VALID;
COMMIT;

BEGIN;
ALTER TABLE site.site_media VALIDATE CONSTRAINT site_media_processing_state_check;
COMMIT;
```

Add a `scanned_at` nullable timestamp:

```sql
BEGIN;
ALTER TABLE site.site_media ADD COLUMN scanned_at TIMESTAMPTZ NULL;
COMMENT ON COLUMN site.site_media.scanned_at IS
    'Set when CSAM scan completes. NULL = pre-scanning-era media (grandfathered) or scan not yet run.';
COMMIT;
```

**State transitions** (extending the existing upload-side machine):

```
                  upload                   variants
   pending ─────────────────▶ processing ──────────────▶ scanning ─┐
                                                                    │
                                                                    ├──── scan clean ──▶ ready
                                                                    │
                                                                    └──── scan match ──▶ quarantined
                              ▲                                                              │
                              │                                                              ▼
                              └─────────────── failed (retryable) ◀────────── (terminal — manual override only)
```

**Read-path impact**: every existing site_media read in user-facing endpoints (`PublicSiteResolver`, gallery rendering, `SiteCacheService`) must filter on `processing_state='ready'` OR be reviewed explicitly. The current code already filters most paths on `processing_state='ready'`; an audit task in Phase 1 confirms 100% coverage.

#### `core.users`

Add `moderation_state`:

```sql
BEGIN;
ALTER TABLE core.users
    ADD COLUMN moderation_state VARCHAR(20) NOT NULL DEFAULT 'active';
ALTER TABLE core.users
    ADD CONSTRAINT users_moderation_state_check
    CHECK (moderation_state IN ('active', 'warned', 'suspended', 'banned'));
COMMIT;
```

Distinguished from existing soft-delete and `is_active`:

- `deleted_at` — user-initiated account deletion (soft-delete with 30-day retention)
- `is_active` — operational flag (account lifecycle, e.g. unverified-email lockout)
- `moderation_state` — **staff-driven** state via moderation decisions only

Read-path impact: same as above — any code that "serves a user" needs to gate on `moderation_state IN ('active', 'warned')`. Suspended/banned users can still authenticate (so they can read the suspension notice) but their site does not render publicly.

#### `site.sites`

Add the same column:

```sql
BEGIN;
ALTER TABLE site.sites
    ADD COLUMN moderation_state VARCHAR(20) NOT NULL DEFAULT 'active';
ALTER TABLE site.sites
    ADD CONSTRAINT sites_moderation_state_check
    CHECK (moderation_state IN ('active', 'warned', 'hidden'));
COMMIT;
```

Site-level state independent of user-level state — a site can be hidden while the user remains active (one site has a problem, others are fine). Future-proofs against the multi-site case if Partna ever grows beyond one-site-per-user.

### 6.11 Case lifecycle state machine

```
[signal arrives]
     │
     ▼
   open ─────────────────────────────────────────────────┐
     │                                                    │
     │ (staff opens for review)                           │ (CSAM auto-action — instant)
     ▼                                                    ▼
  triaged                                          auto_actioned
     │                                                    │
     │ (staff records decision)                           │ (staff confirms auto-action)
     ▼                                                    │  OR (staff overrides via decision_type='override_csam_auto_action')
under_review                                              ▼
     │                                                resolved
     │ (staff records decision)
     ▼
  resolved
```

Legal transitions enumerated in `CaseStateMachine::LEGAL_TRANSITIONS`. Direct DB writes that skip states are not part of the design surface; the service layer is the only write path.

```php
private const LEGAL_TRANSITIONS = [
    'open'           => ['triaged', 'auto_actioned', 'resolved'],
    'triaged'        => ['under_review', 'resolved'],
    'under_review'   => ['resolved', 'triaged'],
    'auto_actioned'  => ['resolved'],   // staff confirms or overrides; both lead to resolved
    'resolved'       => [],              // terminal (appeals create new decisions on same case but don't reopen status)
];
```

## 7. API surface

### 7.1 Public endpoints (no auth)

| Method | Path | Throttle | Purpose |
|--------|------|----------|---------|
| `POST` | `/v1/public/report` | `5,1` per IP/min + `3,60` per (IP, target)/hour | Submit a content report. Body: `{ target_type, target_handle, reason_code, details?, reporter_email? }`. Turnstile-gated. Returns 202 with opaque receipt id. |

**Anti-abuse layering:**

1. Turnstile token required (already wired into the auth flow per `8132b0b4-era` work)
2. IP rate limit: `throttle:partna.report.submit` = 5/min
3. Per-target rate limit (Redis counter, key `moderation:report:ip:{ip_hash}:target:{type}:{id}`, TTL 1h, cap 3) — same IP can't bury one target in reports
4. Dedup unique index on `case_signals.dedup_hash` — exact-duplicate signals rejected at DB level
5. Body field length CHECK: `details <= 4000 chars`

**Request shape:**

```json
{
  "target_type": "Site",
  "target_handle": "joeplumber",
  "reason_code": "harassment",
  "details": "Free-text up to 4000 chars",
  "reporter_email": "reporter@example.com",
  "turnstile_token": "0x4AAAAAAAEXAMPLE..."
}
```

**Response (202):**

```json
{
  "receipt_id": "9d2f8a7c-...-...",
  "message": "Report received. Thank you."
}
```

The receipt_id is the case_signal.id, opaque to the caller. No information leak about whether the report merged into an existing case.

### 7.2 Internal endpoints (signature-verified, not user-facing)

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `POST` | `/v1/internal/cloudflare-csam-webhook` | Cloudflare webhook signature header | Receive a CSAM match notification from Cloudflare's CSAM Scanning Tool. Body shape per Cloudflare's webhook docs (matched hash, R2 key, match confidence). |

**Signature verification:** `Cf-Webhook-Signature` header HMAC'd against `CLOUDFLARE_CSAM_WEBHOOK_SECRET` env var. Mismatch → 401. Replays (same signature, ≥5min old) rejected via a Redis nonce store with 10-min TTL.

### 7.3 Authenticated staff endpoints (`auth:supabase` + `require.aal2`)

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/v1/staff/cases` | Paginated case queue. Filters: `?status=open`, `?case_type=csam_match`, `?severity_gte=3`. Default sort: severity DESC, priority ASC, age ASC. |
| `GET` | `/v1/staff/cases/{id}` | Full case detail — case row + all signals + all evidence + all decisions + related cases on same target + audit log entries |
| `POST` | `/v1/staff/cases/{id}/triage` | Move case from `open` → `triaged`. Body: `{ priority?: int, notes?: string }`. Records audit entry. |
| `POST` | `/v1/staff/cases/{id}/take` | Claim a case (`under_review` status). Single-staff lock to prevent duplicate work. Body: `{ }`. |
| `POST` | `/v1/staff/cases/{id}/release` | Release a claimed case back to `triaged`. Body: `{ }`. |
| `POST` | `/v1/staff/cases/{id}/decide` | Record a decision. Body: `{ decision_type, reason, second_staff_approval_id? }`. Validates the second-staff requirement for CSAM overrides. |
| `POST` | `/v1/staff/cases/{id}/escalate` | Manual escalation to law enforcement or eSafety. Body: `{ escalation_target: 'law_enforcement' \| 'esafety', notes }`. Records a `decision_type='escalate_*'` row + writes a sealed audit entry. |

All endpoints gated by `CasePolicy` (see §8.6). All return `CaseResource` / `CaseDetailResource` — no raw model leakage per project convention.

### 7.4 Submit flow (human report)

```
Visitor on <handle>.partna.au
   │                                                                                  Backend services
   │ Clicks "Report this page"                                                              │
   │ Frontend modal collects {reason_code, details?, reporter_email?, turnstile}            │
   │                                                                                        │
   │ POST /v1/public/report                                                                 │
   ├───────────────────────────────────────────────────────────────────────────────────────▶│
   │   ├─ Turnstile verification (existing middleware)                                      │
   │   ├─ IP throttle :5,1                                                                  │
   │   ├─ Per-(IP,target) Redis counter check (cap 3/hour)                                  │
   │   └─ PublicReportRequest validates                                                     │
   │                                                                                        │
   │                                              ContentReportService::submit($dto)        │
   │                                                  │                                     │
   │                                                  ├─ resolve handle → Site / owner_user │
   │                                                  ├─ compute dedup_hash                 │
   │                                                  ├─ DB::transaction {                  │
   │                                                  │     existing_case = lookup open     │
   │                                                  │       case for (target_type, id)    │
   │                                                  │       — partial index hot path     │
   │                                                  │     if existing:                    │
   │                                                  │       insert case_signal            │
   │                                                  │       UPDATE cases SET              │
   │                                                  │         signal_count = +1           │
   │                                                  │     else:                           │
   │                                                  │       insert case (severity=2)      │
   │                                                  │       insert case_signal            │
   │                                                  │     EvidenceSnapshotService         │
   │                                                  │       ::capture(case_id, target)    │
   │                                                  │  }                                  │
   │                                                  │                                     │
   │                                                  └─ DB::afterCommit ─▶                 │
   │                                                       NotifyStaffOfCaseUpdateJob       │
   │                                                       (only if signal_count thresholds │
   │                                                        crossed: 1, 3, 5, 10)           │
   │◀──{ receipt_id, message }────────────────────────────────────────────────────────────  │
```

### 7.5 CSAM match flow (auto-action)

```
User upload → R2 quarantine bucket (existing signed-URL flow, redirected to quarantine)
   │
   ▼
SiteMedia row created with processing_state='scanning'
   │
   ▼
[Cloudflare CSAM Scanning Tool runs against quarantine bucket]
   │
   ├── No match path:
   │    PromoteCleanMediaJob (scheduled every 60s) finds SiteMedia rows with
   │    processing_state='scanning' that have completed scan via Cloudflare's
   │    scan-status API:
   │      → copies binary from quarantine → production bucket
   │      → DELETE from quarantine bucket
   │      → UPDATE site_media SET processing_state='ready', scanned_at=NOW()
   │
   └── Match path:
        Cloudflare posts to POST /v1/internal/cloudflare-csam-webhook
                 │
                 ▼
        CloudflareCsamWebhookController
           ├─ verify signature
           ├─ verify replay nonce
           └─ CsamMatchHandlerService::handle($payload)
                 │
                 ├─ DB::transaction {
                 │     insert moderation.cases (case_type='csam_match',
                 │       severity=5, status='auto_actioned', auto_actioned=true,
                 │       priority=1)
                 │     insert moderation.case_signals (signal_source='csam_scan',
                 │       reason_code='auto_csam_hash_match', signal_data=match payload)
                 │     insert moderation.evidence (evidence_type='csam_hash_match',
                 │       payload, content_hash)
                 │     insert moderation.csam_quarantine (
                 │       site_media_id, content_hash, r2_quarantine_key,
                 │       preservation_expires_at = NOW() + INTERVAL '90 days')
                 │     insert moderation.ncmec_submissions (status='pending', payload)
                 │     insert moderation.decisions (decision_type='suspend_user',
                 │       decided_by_system=true, auto_actioned=true,
                 │       reason='auto_csam_match')
                 │     insert moderation.action_log rows for each downstream action
                 │  }
                 │
                 └─ DB::afterCommit ─▶ dispatch:
                       QuarantineMediaJob       (UPDATE site_media SET processing_state='quarantined')
                       SuspendUserJob           (UPDATE users SET moderation_state='suspended')
                       SuspendSiteJob           (UPDATE sites SET moderation_state='hidden')
                       SyncSubdomainToKvJob     (purge edge cache — existing job, reuse)
                       FileCyberTipReportJob    (ncmec_submissions outbox row → API call)
                       NotifyOnCallStaffJob     (Slack + email — humans must know)
```

### 7.6 Error catalogue (public report endpoint)

| Code | HTTP | Trigger | User-facing copy |
|------|------|---------|------------------|
| `INVALID_TARGET` | 422 | target_type/target_handle doesn't resolve to a known entity | "We couldn't find that page." |
| `DUPLICATE_REPORT` | 409 | dedup_hash already exists in case_signals | "You've already reported this." |
| `TARGET_RATE_LIMITED` | 429 | Per-(IP, target) cap of 3/hour hit | "Hold on a sec — try again later." |
| `INVALID_TURNSTILE` | 422 | Turnstile token missing or rejected | "Please complete the verification and try again." |
| `INVALID_REASON_CODE` | 422 | reason_code not in allowed set | "That reason isn't supported. Please pick one of the options." |
| `DETAILS_TOO_LONG` | 422 | details > 4000 chars | "Please keep details under 4000 characters." |
| Throttle | 429 | IP rate limit | "Hold on a sec, try again in a minute." |

### 7.7 Error catalogue (Cloudflare webhook)

| Code | HTTP | Trigger | Cloudflare retries |
|------|------|---------|--------------------|
| `INVALID_SIGNATURE` | 401 | HMAC mismatch | No (signals misconfiguration) |
| `REPLAY_DETECTED` | 409 | Webhook signature already processed within nonce window | No |
| `MEDIA_NOT_FOUND` | 404 | R2 key doesn't map to a known SiteMedia row | Yes (Cloudflare retries with backoff) |
| `INTERNAL_ERROR` | 500 | Service-layer exception | Yes |

## 8. Services, jobs, listeners, commands

### 8.1 Services (`app/Services/Moderation/`)

| Class | Responsibility | Key methods |
|-------|----------------|-------------|
| `ContentReportService` | Orchestrates POST /report. Resolves target, dedupes, opens or merges into a case, snapshots evidence. | `submit(PublicReportDto $dto): SubmitResult` |
| `CsamMatchHandlerService` | Orchestrates Cloudflare webhook → case + decision + quarantine + outbox + dispatch | `handle(CloudflareCsamMatchDto $dto): void` |
| `CaseStateMachine` | Pure FSM. Validates legal transitions. No side effects. | `transition(Case $c, string $event): void` |
| `ModerationCaseService` | Case-side mutations: triage, take, release, resolve. Uses CaseStateMachine. | `triage(Case $c, PartnaStaff $staff, ?int $priority, ?string $notes): void` etc. |
| `ModerationDecisionService` | Writes decisions + dispatches action_log entries + jobs. The single decision-write path. | `decide(Case $c, PartnaStaff $staff, DecisionDto $dto): Decision` |
| `EvidenceSnapshotService` | Captures immutable evidence rows at signal time. Per-target-type strategy (Site, SiteMedia, etc.) | `capture(string $caseId, string $targetType, string $targetId, ?string $signalId): Evidence` |
| `NcmecSubmissionService` | Outbox-pattern CyberTipline submission. Retry + manual-fallback escalation. | `submit(NcmecSubmission $row): void` |
| `ModerationActionDispatcher` | Translates decision_type → action_log rows + Horizon dispatch | `dispatchFor(Decision $d): void` |
| `ModerationAuditService` | Single write path for audit_log. Called by every staff-action endpoint. | `recordStaffAction(PartnaStaff $staff, string $action, ?string $targetType, ?string $targetId, array $payload = []): void`<br>`recordSystemAction(...)` |

### 8.2 Jobs (`app/Jobs/Moderation/`)

All jobs implement `ShouldQueueAfterCommit` where they're dispatched from inside DB transactions.

| Job | Trigger | Notes |
|-----|---------|-------|
| `NotifyStaffOfCaseUpdateJob` | Dispatched from `ContentReportService` and `CsamMatchHandlerService` | Sends in-app notification + email to on-call staff group. Threshold-gated: only fires on signal_count crossing 1/3/5/10. |
| `QuarantineMediaJob` | Dispatched from `CsamMatchHandlerService` | `UPDATE site_media SET processing_state='quarantined'`. Idempotent. |
| `SuspendUserJob` | Dispatched from any decision_type='suspend_user' | `UPDATE users SET moderation_state='suspended'`. Idempotent. |
| `SuspendSiteJob` | Same | `UPDATE sites SET moderation_state='hidden'`. Idempotent. |
| `FileCyberTipReportJob` | Dispatched from CSAM auto-action; also re-dispatched by retry command | Calls `NcmecSubmissionService::submit()` on a specific outbox row. Exponential backoff: 1m, 5m, 30m, 2h, 12h. After 5 retries, status → `manual_fallback_required` + Nightwatch alert. |
| `NotifyReportedUserJob` | Dispatched from any decision_type IN ('hide_*', 'suspend_*', 'ban_*') | Sends statement-of-reasons email to the reported user. Skipped if `decision_type='warn'`. |
| `NotifyReporterJob` | Dispatched from any decision_type on a case with reporter_email present | Sends outcome notification. Skipped if no reporter_email. |
| `NotifyOnCallStaffJob` | Dispatched from CSAM auto-actions and severity-5 manual cases | Slack webhook + email. High priority Horizon lane. |
| `PromoteCleanMediaJob` | Scheduled every 60s | Finds `site_media WHERE processing_state='scanning' AND scanned_at IS NULL`. For each, polls Cloudflare scan-status; if clean, copies quarantine → production, deletes from quarantine, marks `ready` + `scanned_at`. |
| `PurgeMediaFromCacheJob` | Dispatched from QuarantineMediaJob (afterCommit) | Calls existing `CloudflarePurgeService` to invalidate the media URL at the edge. |

**Horizon queue lane:** add a `moderation_high` lane in `config/horizon.php` for time-sensitive jobs (`FileCyberTipReportJob`, `NotifyOnCallStaffJob`, `SyncSubdomainToKvJob` dispatched from moderation paths). Existing default lane handles the rest.

### 8.3 Listeners

No listeners — moderation paths are explicit service-method calls. Event-driven moderation creates ambiguity about when actions fire; we route through service calls so the call site is grep-able.

### 8.4 Notifications (`app/Notifications/Moderation/`)

Standard Laravel `Notification` classes; channels: `mail` + `database` (for in-app).

| Class | Recipient | When | Subject |
|-------|-----------|------|---------|
| `CaseCreatedStaffNotification` | on-call staff group | Case opened, signal_count thresholds | `[Partna T&S] New {severity} case on @{handle}` |
| `CaseEscalatedStaffNotification` | on-call staff group | Manual escalation recorded | `[Partna T&S] Escalation: {target}` |
| `CsamAutoActionStaffNotification` | on-call staff group | CSAM auto-action fired | `[Partna T&S] CSAM auto-action: @{handle} — review required` |
| `ContentHiddenNotification` | reported user | decision_type='hide_*' | `Your Partna page has been hidden` |
| `AccountSuspendedNotification` | reported user | decision_type='suspend_user' | `Your Partna account has been suspended` |
| `AccountBannedNotification` | reported user | decision_type='ban_user' | `Your Partna account has been permanently closed` |
| `ReportOutcomeNotification` | reporter (if email present) | Any terminal decision | `Update on the page you reported` |

Mail templates: `resources/views/mail/moderation/*.blade.php`. Statement-of-reasons templates include: the decision, the reason text (staff-written), the policy section it relates to, the appeal path (placeholder text until appeals ship).

Per-notification capability gate: each dispatcher calls `AccountCapabilities::for($user)->can('receive_moderation_notifications')` before sending to the reported user — fail-closed pattern per `account-capability-audit` skill.

### 8.5 Console commands (`app/Console/Commands/Moderation/`)

| Command | Schedule | Purpose |
|---------|----------|---------|
| `moderation:retry-ncmec-submissions` | every 5 min | Finds `ncmec_submissions WHERE status IN ('pending', 'failed') AND attempts < 5`; re-dispatches `FileCyberTipReportJob` |
| `moderation:expire-csam-quarantine` | daily at 03:00 | Finds `csam_quarantine WHERE r2_binary_deleted = FALSE AND preservation_expires_at < NOW()`; deletes R2 binary; flips `r2_binary_deleted` flag |
| `moderation:audit-quarantine-bucket` | daily at 04:00 | Verifies the R2 quarantine bucket has no public access policy. Fails loudly (Nightwatch + on-call) if drift detected. |
| `moderation:sla-scan` | every 15 min | Finds cases with `sla_due_at < NOW() + INTERVAL '2 hours' AND status IN (...)`. Surfaces breach risk via Nightwatch. |
| `moderation:redact-reporter-pii {case_id}` | on demand | GDPR/privacy erasure — clears reporter_email and reporter_ip_hash from case_signals rows for a given case. Records audit entry. |
| `moderation:show-case {case_id}` | on demand | Support utility — prints case + signals + evidence + decisions + actions. JSON for piping. |
| `moderation:reverse-decision {decision_id} --reason="…"` | on demand | Creates a new decision row with `supersedes_decision_id` set; reverses the original action via the same dispatch path. Pre-appeals stop-gap for "we got it wrong." |

Schedule registrations in `app/Console/Kernel.php`.

### 8.6 Policies (`app/Policies/`)

`CasePolicy` and `DecisionPolicy` extending `BasePolicy`:

```php
// CasePolicy
public function viewAny(User|PartnaStaff $actor): bool
{
    return $actor instanceof PartnaStaff;  // staff-only listing
}

public function view(User|PartnaStaff $actor, Case $case): bool
{
    return $actor instanceof PartnaStaff
        || (
            // owner of the reported entity may view (limited resource)
            // FUTURE: gated by capability flag, default off
            false
        );
}

public function triage(PartnaStaff $staff, Case $case): bool { return true; }
public function take(PartnaStaff $staff, Case $case): bool { return true; }
public function decide(PartnaStaff $staff, Case $case): bool { return true; }
public function escalate(PartnaStaff $staff, Case $case): bool { return true; }
```

All staff actions on cases require AAL2 (route-level middleware). Policies additionally enforce that the staff member isn't suspended.

CSAM override (`decision_type='override_csam_auto_action'`) additionally requires the FormRequest to include `second_staff_approval_id` referring to a *different* staff row — enforced in `DecideOnCaseRequest`:

```php
public function rules(): array
{
    return [
        'decision_type' => ['required', /* in enum */],
        'reason'        => ['required', 'string', 'min:10', 'max:2000'],
        'second_staff_approval_id' => [
            'required_if:decision_type,override_csam_auto_action',
            'uuid',
            'exists:core.partna_staff,id',
            'different:' . $this->user()->id,
        ],
    ];
}
```

Register policies in `AppServiceProvider::boot()`:

```php
Gate::policy(Case::class, CasePolicy::class);
Gate::policy(Decision::class, DecisionPolicy::class);
```

`PolicyCoverageTest` sweep will require both registrations — adding the models without the policy entries fails CI.

### 8.7 Config additions (`config/partna.php`)

```php
'moderation' => [
    'enabled'                     => env('PARTNA_MODERATION_ENABLED', true),
    'csam_scan_enabled'           => env('PARTNA_CSAM_SCAN_ENABLED', false),
    'auto_actions_enabled'        => env('PARTNA_MODERATION_AUTO_ACTIONS_ENABLED', true),
    'csam' => [
        'cloudflare_webhook_secret'  => env('CLOUDFLARE_CSAM_WEBHOOK_SECRET'),
        'quarantine_bucket'          => env('R2_QUARANTINE_BUCKET', 'partna-media-quarantine'),
        'production_bucket'          => env('R2_PRODUCTION_BUCKET', 'partna-media-production'),
        'preservation_days'          => 90,
        'ncmec_endpoint'             => env('NCMEC_CYBERTIP_ENDPOINT'),
        'ncmec_api_key'              => env('NCMEC_API_KEY'),
        'ncmec_esp_id'               => env('NCMEC_ESP_ID'),
        'ncmec_max_attempts'         => 5,
    ],
    'reporting' => [
        'public_throttle'            => ['requests' => 5, 'minutes' => 1],
        'per_target_throttle'        => ['requests' => 3, 'window_minutes' => 60],
        'details_max_chars'          => 4000,
        'merge_window_minutes'       => 60 * 24 * 7, // 7 days — signals on same target within window merge
        'staff_notify_thresholds'    => [1, 3, 5, 10],
    ],
    'queue' => [
        'high_priority_lane' => env('PARTNA_MODERATION_HIGH_LANE', 'moderation_high'),
    ],
],
```

### 8.8 AccountCapabilities additions

In `App\Services\Accounts\AccountCapabilities::individualCapabilities()`:

```php
'can_be_reported'                  => true,   // your site can receive reports
'receive_moderation_notifications' => true,   // can receive statement-of-reasons emails
```

Suspended users (`moderation_state='suspended'`) have these flip to `false` — preventing the system from sending them further notifications post-suspension if a follow-up case is opened.

### 8.9 Routes

`routes/api/publicSite.php`:

```php
Route::middleware('throttle:partna.moderation.report')->group(function () {
    Route::post('/v1/public/report', [PublicReportController::class, 'submit']);
});
```

`routes/api.php` (or a new `routes/api/internal.php`):

```php
Route::middleware('cloudflare.webhook.signature')->group(function () {
    Route::post('/v1/internal/cloudflare-csam-webhook',
        [CloudflareCsamWebhookController::class, 'handle']);
});
```

`routes/api/staff.php`:

```php
Route::middleware(['auth:supabase', 'require.aal2'])->group(function () {
    Route::get   ('/v1/staff/cases',                [StaffCaseController::class, 'index']);
    Route::get   ('/v1/staff/cases/{case}',         [StaffCaseController::class, 'show']);
    Route::post  ('/v1/staff/cases/{case}/triage',  [StaffCaseController::class, 'triage']);
    Route::post  ('/v1/staff/cases/{case}/take',    [StaffCaseController::class, 'take']);
    Route::post  ('/v1/staff/cases/{case}/release', [StaffCaseController::class, 'release']);
    Route::post  ('/v1/staff/cases/{case}/decide',  [StaffCaseController::class, 'decide']);
    Route::post  ('/v1/staff/cases/{case}/escalate',[StaffCaseController::class, 'escalate']);
});
```

Throttle definitions in `RouteServiceProvider::boot()` reference `config('partna.moderation.reporting.public_throttle')`.

### 8.10 Middleware

| Middleware | Where | Purpose |
|------------|-------|---------|
| `VerifyCloudflareWebhookSignature` | `App\Http\Middleware\Cloudflare\VerifyCloudflareWebhookSignature` | HMAC verification on the CSAM webhook. Compares `Cf-Webhook-Signature` header against `CLOUDFLARE_CSAM_WEBHOOK_SECRET`. Replay guard via Redis nonce store. |
| `EnforceCsamScanGate` | applied to media-serve and media-list controllers | Filters out `site_media WHERE processing_state IN ('scanning', 'quarantined')` — defence-in-depth on top of the existing `processing_state='ready'` filter. |

## 9. Edge cases & error handling

### 9.1 Race conditions

| Race | Catch | Response |
|------|-------|----------|
| Two reports for same target arrive in same millisecond | `SELECT ... FOR UPDATE` on open case lookup inside the transaction; whichever wins inserts the case, the other merges as signal | Both reporters get 202. signal_count reflects both. |
| Cloudflare webhook fires twice (Cloudflare retry semantics) | Unique constraint on `case_signals.dedup_hash` (signal_data-derived for CSAM) | Second call returns 409 from controller; Cloudflare stops retrying |
| Staff "take" same case simultaneously | Use `WHERE status IN ('open','triaged')` predicate on the UPDATE; first wins; second gets 409 `ALREADY_TAKEN` | UI re-renders case as "taken by X" |
| Decision recorded twice on same case | UNIQUE constraint or service-layer guard? Service-layer — DB UNIQUE on case_id alone is wrong (multiple decisions are legal post-appeal); guard uses `WHERE status NOT IN ('resolved')` predicate | Second decide-call gets 409 `CASE_ALREADY_RESOLVED` |
| Auto-action and human-decide on same case race | Auto-action transitions `open → auto_actioned` first; human-decide on `auto_actioned` is a confirm-or-override flow with explicit `decision_type='override_csam_auto_action'` semantics | No conflict — both paths legal |

### 9.2 External-service failures

| Failure | Behavior |
|---------|----------|
| Cloudflare scan-status API down (PromoteCleanMediaJob) | Media stays `processing_state='scanning'` indefinitely; resumes when API returns. Nightwatch alert if any row stuck >2h. |
| Cloudflare CSAM Scanning Tool itself unavailable | Quarantine bucket still accepts uploads; no promotion happens; uploads are user-visible as "pending review." If extended outage, on-call escalates to Cloudflare support. **We do not promote media without a scan.** |
| Cloudflare webhook delivery fails | Cloudflare retries with exponential backoff. We rely on their delivery guarantees. |
| NCMEC API down | Outbox row stays `pending`; retry job picks up; after 5 retries → `manual_fallback_required` + Nightwatch + on-call notification. Manual submission via NCMEC's web portal as fallback. |
| R2 deletion fails (preservation expiry) | `r2_binary_deleted` stays false; daily job retries. After 7 days of retry failure, Nightwatch alert. |
| Supabase Auth (suspend user) call fails | `SuspendUserJob` retries 3× exponential; on final fail → dead-letter + Nightwatch. Manual intervention required — but `users.moderation_state='suspended'` is set regardless, so site rendering already gates. |
| `SyncSubdomainToKvJob` fails | Standard existing retry; final fail → Nightwatch. Manual replay via existing artisan command. Page may still resolve from edge cache until cache TTL or manual purge. |

### 9.3 Lifecycle / hard-delete cascades

| Event | Behavior |
|-------|----------|
| Reported user soft-deletes account (30d window) | Cases untouched. `AccountCapabilities::can('receive_moderation_notifications')` returns false → outbound notifications skip. |
| Reported user hard-deletes (post-retention) | `reportable_owner_user_id` set NULL (`ON DELETE SET NULL`). Open cases stay open with denormalised reportable_id; queue shows them with "user deleted" label. Resolved cases unaffected. |
| Reported SiteMedia hard-deleted | `RESTRICT` on csam_quarantine — can't be deleted if quarantine row exists. Soft-delete still allowed. Application code prevents media deletion if a case references it (case_signals.signal_data preserves the snapshot ID anyway). |
| Reporter (if registered) hard-deletes | `reporter_user_id` set NULL. `reporter_email` value preserved unless redacted via `moderation:redact-reporter-pii`. |
| Staff hard-deleted (unusual — typically suspended) | All `decided_by_staff_id`, `triaged_by_staff_id`, `second_staff_approval_id` set NULL. Audit log entries preserved with staff_id=null + the original name captured in `payload`. |

### 9.4 State-machine guards

- `CaseStateMachine::transition()` is the only legal write path for `cases.status`; direct DB writes that skip states are not part of the design surface
- `IllegalCaseTransition` thrown on any non-listed transition
- `resolved` is terminal — appeals create new decisions on the same case but don't reopen status
- The single exception: `under_review → triaged` (staff releases a case they took)

### 9.5 Authorization edges

| Attempt | Defence |
|---------|---------|
| Non-staff hits `/v1/staff/cases` | `auth:supabase` middleware → user is supabase user but not staff; `require.aal2` + policy check → 403 |
| Staff without AAL2 | `require.aal2` middleware → 403 |
| Staff dismisses CSAM case with `decision_type='dismiss'` | Allowed only when CSAM match is a confirmed false positive; FormRequest enforces this requires the `override_csam_auto_action` decision type, not `dismiss`. `dismiss` on CSAM case → 422 `INVALID_DECISION_FOR_CASE_TYPE`. |
| Staff approves their own CSAM override (using own ID as second_staff_approval_id) | FormRequest `different:` rule rejects |
| Cloudflare webhook with forged signature | `VerifyCloudflareWebhookSignature` middleware → 401 |
| Replay of valid Cloudflare webhook | Redis nonce store → 409 |
| `PolicyCoverageTest` sweep | Will require `Case` and `Decision` model registration |

### 9.6 Observability

Per `CLAUDE.md`'s Nightwatch + Cloud logs discipline:

| Signal | Surface |
|--------|---------|
| Case opened (any type) | `Log::info('moderation.case.opened', {case_id, case_type, severity})` |
| Case merged (signal_count incremented) | `Log::info('moderation.case.merged', {case_id, new_signal_count})` |
| Auto-action fired | `Log::warning('moderation.auto_action', {case_id, decision_type})` — Nightwatch breadcrumb |
| Decision recorded | `Log::info('moderation.decision', {decision_id, case_id, decision_type, auto_actioned})` |
| Action job failed | Job throws → Nightwatch exception |
| NCMEC submission `manual_fallback_required` | Critical Nightwatch alert + on-call page |
| Quarantine bucket public-access drift | Critical Nightwatch alert (from `moderation:audit-quarantine-bucket`) |
| SLA breach risk | `Log::warning('moderation.sla.breach_risk', {case_id, due_in_minutes})` |
| Webhook signature failure | `Log::warning('moderation.webhook.signature_failure', {ip, header_present})` — sustained spike alertable |
| Open queue size (gauge, periodically logged) | `Log::info('moderation.queue.size', {open, triaged, under_review})` |

No reporter PII (no emails, no raw IPs) in any log line. The `reporter_ip_hash` is OK to log as it's already one-way hashed. `account-capability-audit` skill sweep will check this.

## 10. Additional edge cases (folded in)

### 10.1 Cloudflare's scan-on-upload semantics

Cloudflare's CSAM Scanning Tool, when enabled on an R2 bucket, scans uploads automatically and posts matches to the configured webhook. **It does not post negatives.** The `PromoteCleanMediaJob` exists because we have to actively check "has the scan completed and what's the result?" — via Cloudflare's R2 scan-status API. The job's poll logic:

1. List quarantine bucket objects with our naming convention
2. For each, check scan status via Cloudflare API
3. If `complete + clean`: promote
4. If `complete + match`: should already be in our DB via webhook — log a divergence warning if not
5. If `pending`: skip, will retry next iteration

The job is idempotent and rate-limited (max 100 objects per run) so a backlog doesn't stall it.

### 10.2 Existing media grandfathering

All `site.site_media` rows existing before the migration get `scanned_at=NULL`. The migration does **not** transition them to `processing_state='scanning'` — they stay `'ready'`. Defence-in-depth `EnforceCsamScanGate` middleware allows `processing_state='ready' AND (scanned_at IS NOT NULL OR scanned_at IS NULL AND created_at < $cutoff)` so grandfathered media renders normally. If Josh ever decides to backfill, the gate flips off the grandfather clause and the backfill job populates `scanned_at`.

### 10.3 Magic-handle change between report and review

A handle rename (e.g. `joeplumber` → `jplumber`) doesn't break case integrity — `reportable_id` is the UUID, not the handle. Staff queue display resolves the current handle at render time; evidence rows preserve the old handle in the snapshot for forensic clarity.

### 10.4 Multi-target reports

If a visitor reports "this page" (Site) but the offensive content is a specific photo (SiteMedia), staff can manually retarget the case during triage — `POST /v1/staff/cases/{id}/retarget` (deferred from v1 scope; staff records a new signal pointing at the correct target and resolves the original). Day-one: staff handles via comments and resolves manually.

### 10.5 Reporter follow-up via opaque receipt_id

The receipt_id returned at submit is the case_signals.id. Reporter can later GET `/v1/public/report/{receipt_id}` (deferred from v1) to see "received / under review / resolved" — without learning what the decision was beyond "we looked into it." Day-one: outcome notification via email is the only feedback channel.

### 10.6 Anonymous reports with no follow-up channel

Anonymous reports (`reporter_email=NULL`) can't be notified of outcomes. The `NotifyReporterJob` checks for email presence and skips. No-op; no fallback channel.

### 10.7 Reporter PII GDPR / erasure rights

Reporter email and IP hash are PII (recital 30 of GDPR treats IPs as identifiers). On erasure request:

- `moderation:redact-reporter-pii {case_id}` clears `reporter_email`, `reporter_ip_hash` from all case_signals for that case
- The case + decision + audit log entries remain (legitimate-interest basis for retention)
- Audit log entry records the redaction

Day-one: no public-facing erasure endpoint. Staff handles erasure requests via the artisan command.

### 10.8 Duplicate webhook delivery from Cloudflare

Cloudflare's webhook retry semantics may deliver the same notification twice (network blip + retry). The signature itself is replay-attacked via the Redis nonce store. But the same payload with a *fresh* signature (Cloudflare re-sends with a new timestamp) gets through the nonce check — that's where the `case_signals.dedup_hash` UNIQUE constraint catches it. The CSAM signal's dedup_hash includes the Cloudflare match ID, so same match → same hash → unique constraint violation → controller returns 409.

### 10.9 Statement-of-reasons content vs DSA structured format

Day-one: free-text `reason` field on decisions. Statement-of-reasons emails use this text plus a fixed boilerplate framing. Future v2 (when EU transparency reporting becomes real): add structured columns to decisions for `decision_visibility`, `decision_provision`, `category_specification` per the DSA SOR schema. The text column remains. Migration is additive.

### 10.10 Two-staff approval for CSAM override

The `decisions_csam_override_requires_second_staff` DB constraint enforces that the second_staff column is populated for the relevant decision_type. The application-level FormRequest additionally requires `second_staff_approval_id` to be a *different* staff member than the deciding staff. There's a theoretical bypass via direct SQL — but the DB constraint catches the column-presence requirement, and operations-via-direct-SQL is in the threat model only for emergency response. Two-control surfaces (DB constraint + application validation) is enough.

### 10.11 Cloudflare webhook for content other than CSAM

Cloudflare's scanning may grow to include other categories. The webhook handler is hardcoded day-one to expect CSAM-shape payloads. When other categories enable, the handler dispatches based on `payload.category` — but no day-one scope creep. CSAM only.

### 10.12 Quarantine bucket access audit

`moderation:audit-quarantine-bucket` is critical infrastructure. It:

1. Calls Cloudflare R2 API to fetch the quarantine bucket's access policy
2. Asserts policy has no public-read rule
3. Asserts no allowed CORS origins beyond expected internal-only set
4. On drift: critical Nightwatch alert + on-call page

This is the kind of thing that gets silently broken by a misconfigured Terraform apply — the daily audit is the smoke alarm.

### 10.13 Staff suspension cascading

If a staff member is suspended via internal disciplinary action, the `core.partna_staff.is_active` flag flips. Their existing in-flight `under_review` cases need handling — release-on-suspend job runs in the existing staff-suspension flow, moving those cases back to `triaged`. Out of scope for this design; covered by existing staff-management work.

### 10.14 Cache invalidation timing

When a `decision_type='hide_site'` or `suspend_user` fires, the order is:

1. DB rows update (`sites.moderation_state` / `users.moderation_state`)
2. `SyncSubdomainToKvJob` dispatched (afterCommit)
3. KV write removes the routing entry
4. Cloudflare cache purge for the public URL

Between step 1 and step 4, a stale cache entry may serve the hidden page. Mitigation: KV write is the authoritative gate — once removed, even cached responses won't serve because the worker won't route. The cache purge accelerates removal but isn't load-bearing.

## 11. Testing strategy

### 11.1 Test layout

```
tests/
├── Unit/Services/Moderation/
│   ├── CaseStateMachineTest.php
│   ├── EvidenceSnapshotServiceTest.php
│   ├── ModerationDecisionServiceTest.php
│   └── NcmecSubmissionServiceTest.php
├── Feature/Moderation/
│   ├── PublicReportSubmitTest.php
│   ├── PublicReportAntiAbuseTest.php
│   ├── CloudflareCsamWebhookTest.php
│   ├── StaffCaseQueueTest.php
│   ├── StaffCaseDecideTest.php
│   ├── StaffCaseEscalateTest.php
│   ├── CsamMatchHandlerTest.php
│   ├── CsamAutoActionPipelineTest.php
│   ├── PromoteCleanMediaJobTest.php
│   ├── ExpireCsamQuarantineTest.php
│   ├── RetryNcmecSubmissionsTest.php
│   └── AuditQuarantineBucketTest.php
├── Feature/Security/
│   ├── ModerationPolicyCoverageTest.php   (extends existing PolicyCoverageTest patterns)
│   ├── ModerationAal2EnforcementTest.php
│   ├── ModerationPiiLeakageTest.php
│   ├── CsamOverrideTwoStaffEnforcementTest.php
│   └── WebhookSignatureForgeryTest.php
└── Pest/Factories/
    ├── CaseFactory.php          (states: open(), triaged(), reviewing(), resolved(), autoActioned())
    ├── CaseSignalFactory.php
    ├── EvidenceFactory.php
    ├── DecisionFactory.php
    └── CsamQuarantineFactory.php
```

### 11.2 Critical scenarios (must pass before merge)

**Happy path — human report:**
- POST /v1/public/report with valid payload → 202 + receipt_id
- DB has: cases row (open, severity 2), case_signals row, evidence row (content_snapshot)
- Resubmitting same payload → 409 DUPLICATE_REPORT
- New report on same target → 202; signal_count = 2; staff notification fires (threshold crossed)

**Happy path — CSAM match:**
- POST /v1/internal/cloudflare-csam-webhook with valid signature → 200
- DB has: cases row (csam_match, severity 5, status=auto_actioned), case_signals row, evidence row (csam_hash_match), csam_quarantine row, ncmec_submissions row, decisions row (decided_by_system=true), action_log rows
- Site_media.processing_state='quarantined'
- Users.moderation_state='suspended'
- Sites.moderation_state='hidden'
- SyncSubdomainToKvJob dispatched
- NotifyOnCallStaffJob dispatched

**Happy path — staff review:**
- Staff GET /v1/staff/cases → paginated list, CSAM case at top by severity DESC
- Staff POST /v1/staff/cases/{id}/triage → status=triaged, audit log entry
- Staff POST /v1/staff/cases/{id}/take → status=under_review
- Staff POST /v1/staff/cases/{id}/decide with decision_type=hide_site → case resolved, decision row, action_log rows dispatched

**Races:**
- Two concurrent reports for same target: signal_count = 2, one case
- Two staff concurrently take same case: second gets 409
- Decision attempted twice on same case (post-resolved): second gets 409
- Cloudflare webhook delivered twice with same signature: second gets 409 from replay store
- Cloudflare webhook delivered twice with different signatures, same payload: second gets 409 from dedup_hash UNIQUE

**Anti-abuse:**
- IP rate limit: 6th request from same IP in 1m → 429
- Per-target throttle: 4th report on same (IP, target) in 1h → 429
- Missing Turnstile token → 422
- Reason code not in allowed set → 422
- Details > 4000 chars → 422

**Security:**
- Non-staff hits /v1/staff/cases → 403
- Staff without AAL2 → 403
- Reporter email never appears in /v1/staff/cases response (PII leakage)
- CSAM override without second_staff_approval_id → 422
- CSAM override with same staff as second approver → 422
- Cloudflare webhook with bad signature → 401
- Forged webhook with valid HMAC but old timestamp → 409 (nonce/replay)
- `ModerationPolicyCoverageTest` passes
- Dispatcher skipped when reported user's capabilities denied (e.g. already deleted)

**State machine (table-driven):**
- Every legal case transition allowed
- Every illegal transition throws `IllegalCaseTransition`

**CSAM quarantine lifecycle:**
- Auto-action creates quarantine row with `preservation_expires_at = NOW() + 90 days`
- `moderation:expire-csam-quarantine` after 90 days: deletes R2 binary, sets `r2_binary_deleted=true`
- Metadata row remains queryable after binary deletion

**NCMEC outbox:**
- Submission attempt → ncmec_submissions row created
- Successful API call → status='submitted', ncmec_tip_id populated
- Failed API call: attempts increments, status='failed', last_error captured
- 5 failures → status='manual_fallback_required' + Nightwatch alert
- `moderation:retry-ncmec-submissions` picks up `failed` rows with attempts < 5

### 11.3 DB strategy

SQLite for the bulk of tests (fast). Postgres-tagged tests for:
- Partial unique indexes (`case_signals.dedup_hash`, the open-case index)
- CHECK constraints (CSAM override two-staff requirement, decision actor XOR)
- `ON DELETE RESTRICT` semantics on csam_quarantine

Tagged `--group=postgres` in CI; runs against a disposable Supabase branch DB.

### 11.4 Test doubles

- `FakeCloudflareCsamClient` for scan-status API + webhook payloads
- `FakeNcmecClient` for CyberTipline API submissions
- `FakeR2Client` for quarantine/production bucket operations
- `Queue::fake()` for dispatch assertions
- Laravel `travel()` helper for preservation_expires_at testing

### 11.5 Post-merge manual verification (per `verification-before-completion` skill)

1. `cloud env:logs partna development --tail 50` — no exceptions on the new endpoints
2. Submit a real test report via curl against `dev-api.partna.au`; confirm case + evidence rows created
3. Submit a benign image upload; confirm it transits quarantine → production within ~60s and `scanned_at` is populated
4. (Deferred) Submit a Cloudflare test-mode CSAM webhook (Cloudflare provides a sandbox); confirm full auto-action pipeline fires end-to-end including ncmec_submissions row
5. Run `moderation:audit-quarantine-bucket` against dev — confirms bucket is private
6. Nightwatch: `moderation.*` breadcrumbs flowing; no slow-route alerts
7. PolicyCoverageTest passes locally

## 12. Implementation order (input to the writing-plans skill)

Each phase produces something verifiable. PRs sized for individual review.

1. **Phase 1 — Schema & models** *(~1 day)*
   - Migration A: `<ts>_create_moderation_schema.sql` — schema + tables (`cases`, `case_signals`, `evidence`, `decisions`, `action_log`, `audit_log`, `csam_quarantine`, `ncmec_submissions`)
   - Migration B: `<ts+1>_create_moderation_indexes.sql` — all `CREATE INDEX CONCURRENTLY` outside transactions
   - Migration C: `<ts+2>_alter_site_media_for_scanning.sql` — extend `processing_state` CHECK (NOT VALID + VALIDATE pattern), add `scanned_at`
   - Migration D: `<ts+3>_alter_users_sites_moderation_state.sql` — add `moderation_state` columns to `core.users` and `site.sites`
   - Eloquent models: `Case`, `CaseSignal`, `Evidence`, `Decision`, `ActionLogEntry`, `AuditLogEntry`, `CsamQuarantine`, `NcmecSubmission` — all extending `BaseModel`
   - Factories with named states

2. **Phase 2 — State machine, value objects, dedup hash** *(~0.5 day)*
   - `CaseStateMachine` + tests
   - `DedupHashCalculator` (per signal_source) + tests
   - DTOs: `PublicReportDto`, `CloudflareCsamMatchDto`, `DecisionDto`

3. **Phase 3 — Policies & registration** *(~0.5 day)*
   - `CasePolicy`, `DecisionPolicy` extending `BasePolicy`
   - `AppServiceProvider::boot()` registrations
   - `ModerationPolicyCoverageTest` extension to existing sweep

4. **Phase 4 — Evidence snapshot service** *(~0.5 day)*
   - `EvidenceSnapshotService` with per-target-type strategy
   - Site snapshot capture (name, bio, gallery image URLs, block contents)
   - Unit tests asserting immutability + content_hash determinism

5. **Phase 5 — Public report endpoint** *(~1 day)*
   - `PublicReportRequest` (FormRequest)
   - `PublicReportController` (thin)
   - `ContentReportService` (orchestration + case-merge-or-create)
   - `NotifyStaffOfCaseUpdateJob`
   - `CaseResource` (without PII leakage)
   - Routes + throttle definitions in `RouteServiceProvider`
   - Per-target Redis throttle middleware (`PerTargetReportThrottle`)
   - Endpoint tests (happy path + every error code + anti-abuse)

6. **Phase 6 — Staff case management endpoints** *(~1 day)*
   - `StaffCaseController` (index, show, triage, take, release, decide, escalate)
   - `ModerationCaseService` (lifecycle mutations using CaseStateMachine)
   - `ModerationDecisionService` (decision write + action_log dispatch)
   - `ModerationActionDispatcher` (decision_type → action mapping)
   - `ModerationAuditService` (single audit_log writer)
   - `CaseDetailResource` (full case + signals + evidence + decisions + audit slice)
   - Endpoint tests (authz, AAL2, two-staff-approval, state-machine boundaries)

7. **Phase 7 — Outcome jobs** *(~1 day)*
   - `SuspendUserJob`, `SuspendSiteJob`, `QuarantineMediaJob`
   - `NotifyReportedUserJob`, `NotifyReporterJob`, `NotifyOnCallStaffJob`
   - Hook `SyncSubdomainToKvJob` into the dispatcher (existing job — verify the call site)
   - Hook `CloudflarePurgeService` into the dispatcher for media-level purges
   - Notification classes + mail templates
   - Capability gate on every dispatcher
   - Job tests (idempotency, capability gate, dispatch order)

8. **Phase 8 — R2 quarantine bucket plumbing** *(~1 day)*
   - **Manual ops step (parallel):** create `partna-media-quarantine` bucket in Cloudflare with private access policy; document in runbook
   - `R2QuarantineService` — signed upload URLs to quarantine bucket, list/promote/delete operations
   - Modify existing media upload flow (`SiteMediaService::createSignedUploadUrl` or equivalent) to issue quarantine URLs and set `processing_state='scanning'`
   - `PromoteCleanMediaJob` — scheduled every 60s
   - `EnforceCsamScanGate` middleware applied to media-serve / list endpoints
   - Integration tests with `FakeR2Client`

9. **Phase 9 — Cloudflare CSAM webhook** *(~0.5 day)*
   - `VerifyCloudflareWebhookSignature` middleware
   - Redis nonce/replay store
   - `CloudflareCsamWebhookController`
   - `CsamMatchHandlerService` (case + decision + quarantine row + outbox + dispatch)
   - Webhook tests (signature, replay, dedup)
   - **Manual ops step:** enable Cloudflare CSAM Scanning Tool on the quarantine bucket; configure webhook target

10. **Phase 10 — NCMEC integration** *(~0.5 day)*
    - **Manual ops step:** register Partna Pty Ltd as ESP with NCMEC; designated legal contact = Josh Hunter; obtain API credentials
    - `NcmecSubmissionService` — outbox row → API call → response capture
    - `FileCyberTipReportJob` (afterCommit; on quarantine row insert)
    - `moderation:retry-ncmec-submissions` console command
    - Submission tests with `FakeNcmecClient`

11. **Phase 11 — Lifecycle commands** *(~0.5 day)*
    - `moderation:expire-csam-quarantine` (daily)
    - `moderation:audit-quarantine-bucket` (daily)
    - `moderation:sla-scan` (every 15 min)
    - `moderation:redact-reporter-pii {case_id}` (on demand)
    - `moderation:show-case {case_id}` (on demand)
    - `moderation:reverse-decision {decision_id}` (on demand)
    - Schedule registrations in `app/Console/Kernel.php`
    - Command tests

12. **Phase 12 — Capability, config, feature flags** *(~0.5 day)*
    - `AccountCapabilities::individualCapabilities()` updates
    - `config/partna.php` `moderation` section
    - Horizon `moderation_high` queue lane config
    - Feature flags wired through (`PARTNA_CSAM_SCAN_ENABLED` etc.)

13. **Phase 13 — Security, observability, audit** *(~0.5 day)*
    - PII leakage sweep tests
    - AAL2 enforcement sweep
    - Webhook forgery test suite
    - Nightwatch breadcrumb instrumentation
    - Audit `EnforceCsamScanGate` covers all media-serve paths

14. **Phase 14 — Post-merge dev verification** *(~0.5 day)*
    - Manual checklist (§11.5)
    - Update operator docs at `docs/moderation/` (runbook for retry, redact, expire commands; CSAM incident response procedure)
    - Document NCMEC ESP contact details in `docs/auth/runbooks/` (alongside MFA operator runbook)

**Total: ~9 days backend** (revised up from the ~6.5-day estimate during ultrathink — the per-target Redis throttle, two-bucket R2 plumbing, and the outbox-pattern retry logic each carry more weight than originally scoped).

## 13. Production launch prerequisites

Code can land behind feature flags without these. **Do not flip `PARTNA_CSAM_SCAN_ENABLED=true` in production until all of the following are confirmed:**

- [ ] NCMEC ESP registration complete; API credentials in production env vars
- [ ] Designated legal contact: **Josh Hunter** (founder) — recorded in NCMEC ESP portal; reassignable later via portal
- [ ] Cloudflare CSAM Scanning Tool enabled on `partna-media-quarantine`
- [ ] `partna-media-quarantine` R2 bucket created with private-only access policy
- [ ] Webhook target configured at Cloudflare → `/v1/internal/cloudflare-csam-webhook` with secret stored in `CLOUDFLARE_CSAM_WEBHOOK_SECRET`
- [ ] `moderation:audit-quarantine-bucket` passes locally and on dev
- [ ] Smoke test: benign image upload transits quarantine → production
- [ ] (Deferred) Cloudflare test-mode CSAM payload smoke-tests the full auto-action pipeline
- [ ] On-call notification channels (Slack webhook URL, email distribution) configured for `NotifyOnCallStaffJob`
- [ ] Nightwatch alert routes confirmed for: NCMEC manual fallback, quarantine bucket drift, SLA breach

## 14. Open questions (deferred — not blocking design)

These can be decided when triggered. Stubs and schema reserves are in place; no design change needed today.

1. **Appeals workflow.** When the first user appeal request arrives (or when EU traffic is material), implement: appeals table referencing decisions; staff endpoint to record appeal-overturn or uphold; new decision row written via `supersedes_decision_id`. Pure addition; no migration of existing rows.
2. **Trusted flagger registration.** When formal NGO partnerships or DSA Article 22 applicability materialise, add `moderation.trusted_flaggers` table with priority weighting in queue sort.
3. **DSA statement-of-reasons enum schema.** When EU transparency reporting becomes operationally real, add structured columns to decisions: `decision_visibility`, `decision_provision`, `category_specification`. Migration is additive.
4. **EU Transparency Database batch submitter.** When EU traffic is material, build a batch console command that queries decisions in the SOR schema and submits to the EU TDB. Pure read-only; no upstream effect.
5. **ML-based detection categories.** Cloudflare's tool can detect NCII, terrorism, and other categories. Each is a new signal source with its own legal compliance ramifications; layer in case-by-case.
6. **SiteMedia and Block reportable types.** Day-one is Site-only. Fast-follow: enable `target_type=SiteMedia` and `target_type=Block` via small frontend changes; backend already supports.
7. **Reporter follow-up endpoint.** GET `/v1/public/report/{receipt_id}` returns coarse status ("received / under review / resolved") without leaking the decision. Pure addition.
8. **Backfill scanning for grandfathered media.** When/if you decide to scan existing media, the schema reserve (`scanned_at NULL` indicates not-yet-scanned) makes it a `WHERE scanned_at IS NULL` job.
9. **Decision-history audit endpoint for owners.** A future "moderation history" page where reported users can see all decisions against them (DSA Article 17). Schema supports it; UI is the work.
10. **Reporter reputation scoring.** When report volume justifies it, score reporters by historical accuracy (decisions on their reports: confirmed vs. dismissed). Adjusts case priority. Additive — new column on `case_signals.reporter_score_at_submit` and a derived view.
11. **Australia-specific eSafety integration.** Currently NCMEC reports satisfy Australian reporting under cross-border MOU. If eSafety opens a direct ESP-style API, integrate via the same outbox pattern.

## 15. References

- `CLAUDE.md` — project rules (Supabase-only migrations, policies, capability gates, AAL2 for staff)
- `AI_CONTEXT.md` — domain model and account-type ground truth
- `supabase/migrations/CONVENTIONS.md` — index, CHECK, NOT NULL, FK migration patterns
- `supabase/migrations/20260526000000_baseline_standalone_user.sql` — baseline schema reference
- `app/Services/Cloudflare/CloudflarePurgeService.php` — existing cache purge service to reuse
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php` — the **only** writer to `SUBDOMAIN_KV`; moderation outcomes that hide a page dispatch this
- `app/Services/Accounts/AccountCapabilities.php` — capability source-of-truth
- `app/Policies/BasePolicy.php` — policy base class
- `tests/Feature/Security/PolicyCoverageTest.php` — sweep test enforcing policy registration
- `docs/auth/mfa-foundation.md` + `docs/auth/mfa-foundation-runbook.md` — AAL2 mechanics that gate staff endpoints
- `docs/handle-redirects.md` — handle alias lifecycle (relevant for evidence snapshotting when handles rename)
- `partna-plan-check` and `account-capability-audit` skills — enforced patterns this design conforms to
- NCMEC CyberTipline API: https://www.missingkids.org/cybertipline
- Cloudflare CSAM Scanning Tool: https://developers.cloudflare.com/cache/reference/csam-scanning/
- DSA Article 16, 17, 20 — notice-and-action, statement of reasons, appeals (referenced as design alignment for future v2 layers)
- Australian Online Safety Act 2021 + BOSE 2022 — Australian regulatory frame
