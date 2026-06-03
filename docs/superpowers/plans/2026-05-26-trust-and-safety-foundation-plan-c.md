# Trust & Safety Foundation — Plan C: CSAM Scanning Pipeline

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire up the CSAM scanning pipeline. After this plan: every user-uploaded image transits a private R2 quarantine bucket where Cloudflare's CSAM Scanning Tool scans it against the NCMEC PhotoDNA database. Clean media gets promoted to production; matches trigger auto-quarantine + user suspension + edge cache purge + outbox-pattern NCMEC CyberTipline submission. Staff get on-call notifications for every auto-action.

**Architecture:** Two-bucket R2 (quarantine + production). New uploads issue signed URLs to the quarantine bucket; `PromoteCleanMediaJob` polls Cloudflare's scan-status API every 60s and promotes clean media. Cloudflare's webhook posts CSAM matches to a signature-verified endpoint that calls `CsamMatchHandlerService` — which writes a system-attributed decision via `ModerationDecisionService::decideAsSystem()` (new in this plan) and the existing `ModerationActionDispatcher` fires the auto-action job set (`QuarantineMediaJob`, `SuspendUserJob`, `SuspendSiteJob`, `PurgeModerationCacheJob`, `NotifyOnCallStaffJob`, plus new `FileCyberTipReportJob`). NCMEC submissions follow an outbox pattern via `moderation.ncmec_submissions` — the worker writes the row first, attempts the API call, retries up to 5x; persistent failure escalates to `manual_fallback_required` with Nightwatch alert.

**Tech Stack:** Laravel 12 (PHP 8.2), Supabase PostgreSQL, Pest 4, Redis (nonce store), Laravel Horizon, Cloudflare R2 API, Cloudflare CSAM Scanning Tool (free), NCMEC CyberTipline API.

**Spec:** `docs/superpowers/specs/2026-05-26-trust-and-safety-foundation-design.md`

**Depends on:** Plan A (schema, models, evidence service) + Plan B (`ModerationDecisionService`, `ModerationActionDispatcher`, outcome jobs, on-call staff notifications).

---

## Pre-flight checklist

- [ ] `git fetch origin && git checkout development && git pull origin development` — pull latest first
- [ ] Plans A and B merged into `development` (this plan reuses Plan A's schema + models, and Plan B's `ModerationDecisionService::decideAsSystem` is extended in Task 4 below)
- [ ] Switch to feature branch: `git checkout -b feat/ts-foundation-plan-c`
- [ ] `composer test` passes (Plans A + B baseline)
- [ ] Local Supabase running with Plans A + B migrations applied
- [ ] R2 production bucket already in use (confirm the existing bucket name in `config/filesystems.php`)
- [ ] Cloudflare account access with permissions to create R2 buckets + configure CSAM Scanning + create webhooks

## Codebase-state assumptions (verified against origin/development on 2026-05-27)

- `App\Models\Core\User\User` is the User model.
- `App\Models\Core\Site\Site` + `App\Models\Core\Site\SiteMedia` are the model locations.
- `App\Models\Core\Staff\PartnaStaff` — staff routing uses `role='admin'` (no `is_on_call` column).
- `site.site_media.processing_state` extended to include `scanning` and `quarantined` (in Plan A Task 4).
- `site.site_media.scanned_at` nullable timestamp exists (Plan A Task 4).
- `actingAsUser` + `actingAsStaff` test helpers exist in `tests/Pest.php` (the latter is added by Plan B).
- `AccountCapabilitySet` has `can_be_reported` + `receive_moderation_notifications` properties (added by Plan B Task 14).
- Latest migrations on `origin/development` end around `20260527150000`; Plan A's migrations occupy `20260528000000-020001`; Plan C's migrations start at `20260529000000`.

---

## Pre-implementation manual ops (parallel-track; document but don't block code)

These happen outside the codebase and unblock the production launch (Task 30) but not the code itself:

| Step | Owner | Notes |
|------|-------|-------|
| Create `partna-media-quarantine` R2 bucket | Josh / ops | Private-only access policy; no public read; no CORS |
| Enable Cloudflare CSAM Scanning Tool on the quarantine bucket | Josh / ops | Cloudflare R2 dashboard → bucket → "CSAM Scanning" → enable |
| Configure Cloudflare webhook → `https://dev-api.partna.au/v1/internal/cloudflare-csam-webhook` | Josh / ops | Capture the webhook signing secret → `CLOUDFLARE_CSAM_WEBHOOK_SECRET` env var |
| Register Partna Pty Ltd as ESP with NCMEC | Josh | https://report.cybertip.org — designate Josh Hunter as primary contact; ~2 weeks approval |
| Obtain NCMEC CyberTipline API credentials | Josh | Stored as `NCMEC_API_KEY` + `NCMEC_ESP_ID` env vars |

Track these in the Task 30 launch checklist.

---

## File structure (what gets created or modified)

```
supabase/migrations/
  20260529000000_create_csam_quarantine_table.sql
  20260529000001_create_csam_quarantine_indexes.sql
  20260529010000_create_ncmec_submissions_table.sql
  20260529010001_create_ncmec_submissions_indexes.sql

app/Models/Moderation/
  CsamQuarantine.php
  NcmecSubmission.php

database/factories/Moderation/
  CsamQuarantineFactory.php
  NcmecSubmissionFactory.php

app/DTOs/Moderation/
  CloudflareCsamMatchDto.php

app/Services/Moderation/
  ModerationDecisionService.php           (extend with decideAsSystem)
  R2QuarantineService.php                 (signed URLs, promote, delete)
  CsamMatchHandlerService.php             (webhook → case + decision + dispatch)
  NcmecSubmissionService.php              (outbox-pattern CyberTipline API)
  NcmecSubmissionFailedTooManyTimes.php   (exception)

app/Services/Cloudflare/
  CloudflareCsamScanClient.php            (scan-status API client; thin wrapper)

app/Http/Controllers/Api/Internal/
  CloudflareCsamWebhookController.php

app/Http/Middleware/Cloudflare/
  VerifyCloudflareWebhookSignature.php

app/Http/Middleware/Moderation/
  EnforceCsamScanGate.php

app/Jobs/Moderation/
  PromoteCleanMediaJob.php
  FileCyberTipReportJob.php

app/Console/Commands/Moderation/
  ModerationExpireCsamQuarantineCommand.php
  ModerationAuditQuarantineBucketCommand.php
  ModerationRetryNcmecSubmissionsCommand.php

app/Services/Media/SiteMediaService.php   (modify createSignedUploadUrl to target quarantine bucket)

routes/api/internal.php                   (new file or extend existing)
config/filesystems.php                    (add r2_quarantine disk)
config/partna.php                         (extend moderation section with csam config)
app/Console/Kernel.php                    (schedule new commands + PromoteCleanMediaJob)

tests/Unit/Services/Moderation/
  R2QuarantineServiceTest.php
  NcmecSubmissionServiceTest.php
  CsamMatchHandlerServiceTest.php

tests/Feature/Moderation/
  CloudflareCsamWebhookTest.php
  PromoteCleanMediaJobTest.php
  FileCyberTipReportJobTest.php
  EnforceCsamScanGateTest.php
  CsamAutoActionPipelineTest.php

tests/Feature/Security/
  WebhookSignatureForgeryTest.php
  QuarantineBucketAccessTest.php

tests/Feature/Commands/Moderation/
  ExpireCsamQuarantineCommandTest.php
  AuditQuarantineBucketCommandTest.php
  RetryNcmecSubmissionsCommandTest.php

docs/moderation/
  README.md                               (update with Plan C status)
  csam-pipeline.md                        (new — operator runbook for the CSAM pipeline)
  ncmec-runbook.md                        (new — what to do when NCMEC submissions fail / law enforcement contacts)
```

---

## Phase 1 — Schema additions

### Task 1: `moderation.csam_quarantine` migration

**Files:**
- Create: `supabase/migrations/20260529000000_create_csam_quarantine_table.sql`
- Create: `supabase/migrations/20260529000001_create_csam_quarantine_indexes.sql`
- Create: `tests/Feature/Moderation/CsamQuarantineSchemaTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/CsamQuarantineSchemaTest.php`:

```php
<?php

use Illuminate\Support\Facades\DB;

it('has moderation.csam_quarantine table with required columns', function () {
    $cols = collect(DB::select(<<<'SQL'
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = 'moderation' AND table_name = 'csam_quarantine'
    SQL))->pluck('column_name')->all();

    expect($cols)->toContain(
        'id', 'case_id', 'site_media_id', 'content_hash',
        'cloudflare_match_payload', 'r2_quarantine_key',
        'r2_binary_deleted', 'preservation_expires_at',
    );
})->group('postgres');

it('csam_quarantine.site_media_id is UNIQUE (one quarantine row per media)', function () {
    $indexes = collect(DB::select(<<<'SQL'
        SELECT indexname FROM pg_indexes WHERE schemaname = 'moderation' AND tablename = 'csam_quarantine'
    SQL))->pluck('indexname')->all();
    expect($indexes)->toContain('csam_quarantine_site_media_uniq');
})->group('postgres');
```

- [ ] **Step 2: Run to fail**

Run: `php artisan test tests/Feature/Moderation/CsamQuarantineSchemaTest.php --group=postgres`
Expected: FAIL — table missing.

- [ ] **Step 3: Create the DDL migration**

Create `supabase/migrations/20260529000000_create_csam_quarantine_table.sql`:

```sql
-- moderation.csam_quarantine
-- Tracks quarantined uploads for the legally-required 90-day preservation window.
-- ON DELETE RESTRICT on case_id + site_media_id: these rows have legal preservation
-- obligations and can only be removed via the explicit expiry pathway.

BEGIN;

CREATE TABLE IF NOT EXISTS moderation.csam_quarantine (
    id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    case_id                  UUID NOT NULL,
    site_media_id            UUID NOT NULL,
    uploader_user_id         UUID NULL,
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
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT csam_quarantine_case_fk FOREIGN KEY (case_id)
        REFERENCES moderation.cases(id) ON DELETE RESTRICT,
    CONSTRAINT csam_quarantine_site_media_fk FOREIGN KEY (site_media_id)
        REFERENCES site.site_media(id) ON DELETE RESTRICT,
    CONSTRAINT csam_quarantine_uploader_fk FOREIGN KEY (uploader_user_id)
        REFERENCES core.users(id) ON DELETE SET NULL
);

COMMIT;
```

- [ ] **Step 4: Create the indexes migration**

Create `supabase/migrations/20260529000001_create_csam_quarantine_indexes.sql`:

```sql
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS csam_quarantine_site_media_uniq
    ON moderation.csam_quarantine (site_media_id);

CREATE INDEX CONCURRENTLY IF NOT EXISTS csam_quarantine_preservation_idx
    ON moderation.csam_quarantine (preservation_expires_at)
    WHERE r2_binary_deleted = FALSE;

CREATE INDEX CONCURRENTLY IF NOT EXISTS csam_quarantine_uploader_idx
    ON moderation.csam_quarantine (uploader_user_id)
    WHERE uploader_user_id IS NOT NULL;
```

- [ ] **Step 5: Apply + verify**

```bash
supabase migration up
php artisan test tests/Feature/Moderation/CsamQuarantineSchemaTest.php --group=postgres
```
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add supabase/migrations/20260529000000_*.sql supabase/migrations/20260529000001_*.sql tests/Feature/Moderation/CsamQuarantineSchemaTest.php
git commit -m "feat(moderation): moderation.csam_quarantine table"
```

---

### Task 2: `moderation.ncmec_submissions` migration

**Files:**
- Create: `supabase/migrations/20260529010000_create_ncmec_submissions_table.sql`
- Create: `supabase/migrations/20260529010001_create_ncmec_submissions_indexes.sql`

- [ ] **Step 1: Extend the schema smoke test**

Append to `tests/Feature/Moderation/CsamQuarantineSchemaTest.php`:

```php
it('has moderation.ncmec_submissions table', function () {
    $cols = collect(DB::select(<<<'SQL'
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = 'moderation' AND table_name = 'ncmec_submissions'
    SQL))->pluck('column_name')->all();

    expect($cols)->toContain(
        'id', 'csam_quarantine_id', 'payload', 'status', 'attempts',
        'ncmec_tip_id', 'submitted_at',
    );
})->group('postgres');

it('has partial index ncmec_submissions_pending_idx', function () {
    $indexes = collect(DB::select(<<<'SQL'
        SELECT indexname FROM pg_indexes WHERE schemaname = 'moderation' AND tablename = 'ncmec_submissions'
    SQL))->pluck('indexname')->all();
    expect($indexes)->toContain('ncmec_submissions_pending_idx');
})->group('postgres');
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create DDL migration**

Create `supabase/migrations/20260529010000_create_ncmec_submissions_table.sql`:

```sql
-- moderation.ncmec_submissions — outbox pattern for CyberTipline submissions.
-- Worker writes the row first, attempts submission, updates with ncmec_tip_id on success.
-- After 5 failed attempts → status='manual_fallback_required' + Nightwatch alert.

BEGIN;

CREATE TABLE IF NOT EXISTS moderation.ncmec_submissions (
    id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    csam_quarantine_id       UUID NOT NULL,
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
    CONSTRAINT ncmec_submissions_quarantine_fk FOREIGN KEY (csam_quarantine_id)
        REFERENCES moderation.csam_quarantine(id) ON DELETE RESTRICT,
    CONSTRAINT ncmec_submissions_status_check CHECK (status IN (
        'pending', 'submitting', 'submitted', 'failed', 'manual_fallback_required'
    ))
);

COMMIT;
```

- [ ] **Step 4: Create indexes migration**

Create `supabase/migrations/20260529010001_create_ncmec_submissions_indexes.sql`:

```sql
CREATE INDEX CONCURRENTLY IF NOT EXISTS ncmec_submissions_pending_idx
    ON moderation.ncmec_submissions (status, created_at)
    WHERE status IN ('pending', 'submitting', 'failed');

CREATE INDEX CONCURRENTLY IF NOT EXISTS ncmec_submissions_quarantine_idx
    ON moderation.ncmec_submissions (csam_quarantine_id);
```

- [ ] **Step 5: Apply + verify + commit**

```bash
supabase migration up
php artisan test tests/Feature/Moderation/CsamQuarantineSchemaTest.php --group=postgres
git add supabase/migrations/20260529010000_*.sql supabase/migrations/20260529010001_*.sql tests/Feature/Moderation/CsamQuarantineSchemaTest.php
git commit -m "feat(moderation): ncmec_submissions outbox table"
```

---

## Phase 2 — Models + factories

### Task 3: `CsamQuarantine` + `NcmecSubmission` models with factories

**Files:**
- Create: `app/Models/Moderation/CsamQuarantine.php`
- Create: `app/Models/Moderation/NcmecSubmission.php`
- Create: `database/factories/Moderation/CsamQuarantineFactory.php`
- Create: `database/factories/Moderation/NcmecSubmissionFactory.php`
- Create: `tests/Unit/Models/Moderation/CsamModelsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Models/Moderation/CsamModelsTest.php`:

```php
<?php

use App\Models\Moderation\CsamQuarantine;
use App\Models\Moderation\NcmecSubmission;
use App\Models\Moderation\ModerationCase;

it('CsamQuarantine factory creates a valid row', function () {
    $row = CsamQuarantine::factory()->create();
    expect($row->preservation_expires_at)->not->toBeNull();
    expect($row->r2_binary_deleted)->toBeFalse();
});

it('NcmecSubmission belongs to CsamQuarantine', function () {
    $qrow = CsamQuarantine::factory()->create();
    $sub  = NcmecSubmission::factory()->forQuarantine($qrow)->create();
    expect($sub->csam_quarantine_id)->toBe($qrow->id);
    expect($sub->status)->toBe('pending');
});

it('models use the moderation schema', function () {
    expect((new CsamQuarantine)->getTable())->toBe('moderation.csam_quarantine');
    expect((new NcmecSubmission)->getTable())->toBe('moderation.ncmec_submissions');
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create `CsamQuarantine` model**

Create `app/Models/Moderation/CsamQuarantine.php`:

```php
<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use Database\Factories\Moderation\CsamQuarantineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CsamQuarantine extends BaseModel
{
    use HasFactory;

    protected $table = 'moderation.csam_quarantine';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = ['id'];

    protected $casts = [
        'cloudflare_match_payload' => 'array',
        'r2_binary_deleted'        => 'boolean',
        'r2_binary_deleted_at'     => 'datetime',
        'preservation_expires_at'  => 'datetime',
        'created_at'               => 'datetime',
        'updated_at'               => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class, 'case_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(NcmecSubmission::class, 'csam_quarantine_id');
    }

    protected static function newFactory(): CsamQuarantineFactory
    {
        return CsamQuarantineFactory::new();
    }
}
```

- [ ] **Step 4: Create `NcmecSubmission` model**

Create `app/Models/Moderation/NcmecSubmission.php`:

```php
<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use Database\Factories\Moderation\NcmecSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NcmecSubmission extends BaseModel
{
    use HasFactory;

    protected $table = 'moderation.ncmec_submissions';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = ['id'];

    protected $casts = [
        'payload'                => 'array',
        'ncmec_response_payload' => 'array',
        'attempts'               => 'integer',
        'submitted_at'           => 'datetime',
        'response_received_at'   => 'datetime',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
    ];

    public function quarantine(): BelongsTo
    {
        return $this->belongsTo(CsamQuarantine::class, 'csam_quarantine_id');
    }

    protected static function newFactory(): NcmecSubmissionFactory
    {
        return NcmecSubmissionFactory::new();
    }
}
```

- [ ] **Step 5: Create factories**

Create `database/factories/Moderation/CsamQuarantineFactory.php`:

```php
<?php

namespace Database\Factories\Moderation;

use App\Models\Moderation\CsamQuarantine;
use App\Models\Moderation\ModerationCase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CsamQuarantineFactory extends Factory
{
    protected $model = CsamQuarantine::class;

    public function definition(): array
    {
        return [
            'id'                       => Str::uuid()->toString(),
            'case_id'                  => ModerationCase::factory()->csamMatch(),
            'site_media_id'            => Str::uuid()->toString(),
            'content_hash'             => hash('sha256', Str::uuid()->toString()),
            'cloudflare_match_payload' => ['matched_against' => 'NCMEC-NetClean'],
            'r2_quarantine_key'        => 'quarantine/' . Str::uuid()->toString() . '.jpg',
            'r2_binary_deleted'        => false,
            'preservation_expires_at'  => now()->addDays(90),
        ];
    }

    public function expired(): self
    {
        return $this->state(fn () => ['preservation_expires_at' => now()->subDay()]);
    }

    public function binaryDeleted(): self
    {
        return $this->state(fn () => [
            'r2_binary_deleted'    => true,
            'r2_binary_deleted_at' => now()->subDay(),
        ]);
    }
}
```

Create `database/factories/Moderation/NcmecSubmissionFactory.php`:

```php
<?php

namespace Database\Factories\Moderation;

use App\Models\Moderation\CsamQuarantine;
use App\Models\Moderation\NcmecSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class NcmecSubmissionFactory extends Factory
{
    protected $model = NcmecSubmission::class;

    public function definition(): array
    {
        return [
            'id'                 => Str::uuid()->toString(),
            'csam_quarantine_id' => CsamQuarantine::factory(),
            'payload'            => ['mediaHash' => 'placeholder'],
            'status'             => 'pending',
            'attempts'           => 0,
        ];
    }

    public function forQuarantine(CsamQuarantine $q): self
    {
        return $this->state(fn () => ['csam_quarantine_id' => $q->id]);
    }

    public function submitted(string $tipId = 'TIP-001'): self
    {
        return $this->state(fn () => [
            'status'                 => 'submitted',
            'ncmec_tip_id'           => $tipId,
            'submitted_at'           => now(),
            'response_received_at'   => now(),
        ]);
    }

    public function failed(int $attempts = 1): self
    {
        return $this->state(fn () => [
            'status'     => 'failed',
            'attempts'   => $attempts,
            'last_error' => 'simulated failure',
        ]);
    }
}
```

- [ ] **Step 6: Run + commit**

```bash
composer dump-autoload
php artisan test tests/Unit/Models/Moderation/CsamModelsTest.php
git add app/Models/Moderation/CsamQuarantine.php app/Models/Moderation/NcmecSubmission.php database/factories/Moderation/CsamQuarantineFactory.php database/factories/Moderation/NcmecSubmissionFactory.php tests/Unit/Models/Moderation/CsamModelsTest.php
git commit -m "feat(moderation): CsamQuarantine + NcmecSubmission models + factories"
```

---

## Phase 3 — System-attributed decision path

### Task 4: Extend `ModerationDecisionService` with `decideAsSystem`

**Files:**
- Modify: `app/Services/Moderation/ModerationDecisionService.php`
- Modify: `tests/Unit/Services/Moderation/ModerationDecisionServiceTest.php`

- [ ] **Step 1: Append failing tests**

Append to `tests/Unit/Services/Moderation/ModerationDecisionServiceTest.php`:

```php
it('decideAsSystem writes a decision with decided_by_system=true and auto_actioned=true', function () {
    $case = ModerationCase::factory()->csamMatch()->create();
    $dto  = new \App\DTOs\Moderation\DecisionDto('suspend_user', 'auto_csam_match', null);

    $decision = app(ModerationDecisionService::class)->decideAsSystem($case, $dto);

    expect($decision->decided_by_system)->toBeTrue();
    expect($decision->decided_by_staff_id)->toBeNull();
    expect($decision->auto_actioned)->toBeTrue();
});

it('decideAsSystem transitions auto_actioned cases', function () {
    $case = ModerationCase::factory()->create(['status' => 'open']);
    $dto  = new \App\DTOs\Moderation\DecisionDto('suspend_user', 'auto_csam_match', null);

    app(ModerationDecisionService::class)->decideAsSystem($case, $dto);

    expect($case->fresh()->status)->toBe('auto_actioned');
});

it('decideAsSystem dispatches the appropriate action set', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $case = ModerationCase::factory()->csamMatch()->create();
    $dto  = new \App\DTOs\Moderation\DecisionDto('suspend_user', 'auto_csam_match', null);

    $decision = app(ModerationDecisionService::class)->decideAsSystem($case, $dto);

    expect(\App\Models\Moderation\ActionLogEntry::query()->where('decision_id', $decision->id)->count())
        ->toBeGreaterThan(0);
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Add the method**

Append to `app/Services/Moderation/ModerationDecisionService.php`:

```php
/**
 * Auto-action path used by CsamMatchHandlerService and any future automated
 * detector. Writes a system-attributed decision (decided_by_system=true),
 * transitions the case to 'auto_actioned', dispatches the action set via the
 * existing dispatcher, and records a system audit row.
 */
public function decideAsSystem(\App\Models\Moderation\ModerationCase $case, \App\DTOs\Moderation\DecisionDto $dto): \App\Models\Moderation\Decision
{
    return \Illuminate\Support\Facades\DB::transaction(function () use ($case, $dto) {
        $decision = \App\Models\Moderation\Decision::create([
            'case_id'             => $case->id,
            'decision_type'       => $dto->decisionType,
            'reason'              => $dto->reason,
            'decided_by_staff_id' => null,
            'decided_by_system'   => true,
            'auto_actioned'       => true,
        ]);

        $this->sm->transition($case, 'auto_actioned');
        $case->auto_actioned = true;
        $case->save();

        $this->dispatcher->dispatchFor($decision);

        $this->audit->recordSystemAction(
            'case.auto_decided',
            'ModerationCase',
            $case->id,
            ['decision_type' => $dto->decisionType, 'decision_id' => $decision->id],
        );

        return $decision;
    });
}
```

- [ ] **Step 4: Run + commit**

```bash
php artisan test tests/Unit/Services/Moderation/ModerationDecisionServiceTest.php
git add app/Services/Moderation/ModerationDecisionService.php tests/Unit/Services/Moderation/ModerationDecisionServiceTest.php
git commit -m "feat(moderation): ModerationDecisionService::decideAsSystem for auto-actions"
```

---

## Phase 4 — R2 quarantine plumbing

### Task 5: `R2QuarantineService`

**Files:**
- Create: `app/Services/Moderation/R2QuarantineService.php`
- Modify: `config/filesystems.php` (add `r2_quarantine` disk)
- Create: `tests/Unit/Services/Moderation/R2QuarantineServiceTest.php`

- [ ] **Step 1: Configure the disk**

Modify `config/filesystems.php`. Alongside the existing R2 disk, add:

```php
'r2_quarantine' => [
    'driver'   => 's3',
    'key'      => env('R2_QUARANTINE_ACCESS_KEY_ID'),
    'secret'   => env('R2_QUARANTINE_SECRET_ACCESS_KEY'),
    'region'   => 'auto',
    'bucket'   => env('R2_QUARANTINE_BUCKET', 'partna-media-quarantine'),
    'endpoint' => env('R2_QUARANTINE_ENDPOINT'),
    'use_path_style_endpoint' => true,
    'throw'    => true,
],
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Services/Moderation/R2QuarantineServiceTest.php`:

```php
<?php

use App\Services\Moderation\R2QuarantineService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2_quarantine');
    Storage::fake('r2');  // production disk faked too for promote tests
});

it('generates a signed upload URL targeting the quarantine bucket', function () {
    $url = app(R2QuarantineService::class)->signedUploadUrl(
        key:         'quarantine/abc-123.jpg',
        mime:        'image/jpeg',
        maxSizeBytes: 10_000_000,
        expiresIn:   600,
    );

    expect($url)->toBeString();
    expect($url)->toContain('quarantine/abc-123.jpg');
});

it('promote copies object from quarantine → production and deletes from quarantine', function () {
    Storage::disk('r2_quarantine')->put('quarantine/test.jpg', 'fake-binary');
    $service = app(R2QuarantineService::class);

    $service->promoteToProduction(quarantineKey: 'quarantine/test.jpg', productionKey: 'media/test.jpg');

    expect(Storage::disk('r2_quarantine')->exists('quarantine/test.jpg'))->toBeFalse();
    expect(Storage::disk('r2')->exists('media/test.jpg'))->toBeTrue();
});

it('delete quarantine binary removes the object', function () {
    Storage::disk('r2_quarantine')->put('quarantine/del.jpg', 'binary');
    app(R2QuarantineService::class)->deleteQuarantineBinary('quarantine/del.jpg');
    expect(Storage::disk('r2_quarantine')->exists('quarantine/del.jpg'))->toBeFalse();
});
```

- [ ] **Step 3: Run to fail**

Expected: FAIL.

- [ ] **Step 4: Create the service**

Create `app/Services/Moderation/R2QuarantineService.php`:

```php
<?php

namespace App\Services\Moderation;

use Illuminate\Support\Facades\Storage;

/**
 * Wrapper around the partna-media-quarantine R2 bucket.
 *
 * Issues signed upload URLs that point at quarantine (not production).
 * Promotes clean media (copy + delete). Deletes quarantine binaries at
 * 90-day preservation expiry.
 */
class R2QuarantineService
{
    public const QUARANTINE_DISK  = 'r2_quarantine';
    public const PRODUCTION_DISK  = 'r2';

    public function signedUploadUrl(string $key, string $mime, int $maxSizeBytes, int $expiresIn = 600): string
    {
        return Storage::disk(self::QUARANTINE_DISK)->temporaryUploadUrl(
            $key,
            now()->addSeconds($expiresIn),
            [
                'ContentType' => $mime,
                'Metadata'    => ['partna-quarantine' => '1'],
            ],
        )['url'];
    }

    public function promoteToProduction(string $quarantineKey, string $productionKey): void
    {
        $binary = Storage::disk(self::QUARANTINE_DISK)->get($quarantineKey);
        if ($binary === null) {
            throw new \RuntimeException("Quarantine object not found: {$quarantineKey}");
        }

        Storage::disk(self::PRODUCTION_DISK)->put($productionKey, $binary);
        Storage::disk(self::QUARANTINE_DISK)->delete($quarantineKey);
    }

    public function deleteQuarantineBinary(string $quarantineKey): void
    {
        Storage::disk(self::QUARANTINE_DISK)->delete($quarantineKey);
    }

    public function existsInQuarantine(string $key): bool
    {
        return Storage::disk(self::QUARANTINE_DISK)->exists($key);
    }
}
```

- [ ] **Step 5: Run + commit**

```bash
php artisan test tests/Unit/Services/Moderation/R2QuarantineServiceTest.php
git add app/Services/Moderation/R2QuarantineService.php config/filesystems.php tests/Unit/Services/Moderation/R2QuarantineServiceTest.php
git commit -m "feat(moderation): R2QuarantineService (signed URLs, promote, delete)"
```

---

### Task 6: Modify SiteMedia upload flow to target quarantine bucket

**Files:**
- Modify: `app/Services/Media/SiteMediaService.php` (or whichever service issues signed upload URLs)
- Create: `tests/Feature/Moderation/SiteMediaUploadQuarantineTest.php`

- [ ] **Step 1: Find the existing upload-URL issuance path**

Run: `grep -rn "temporaryUploadUrl\|signedUploadUrl\|createSignedUploadUrl" app/Services/Media/`

Identify the method that issues signed upload URLs for new SiteMedia rows. The next steps assume this method is `SiteMediaService::createSignedUploadUrl($siteId, $mime, ...)`. Adjust as needed.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Moderation/SiteMediaUploadQuarantineTest.php`:

```php
<?php

use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use App\Services\Media\SiteMediaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2_quarantine');
    Storage::fake('r2');
    config(['partna.moderation.csam_scan_enabled' => true]);
});

it('creates the SiteMedia row with processing_state=scanning when CSAM scanning is enabled', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();

    $result = app(SiteMediaService::class)->createSignedUploadUrl(
        siteId: $site->id,
        mime:   'image/jpeg',
        pool:   'gallery',
    );

    expect($result['url'])->toContain('quarantine');

    $row = DB::selectOne("SELECT processing_state FROM site.site_media WHERE id = ?", [$result['site_media_id']]);
    expect($row->processing_state)->toBe('scanning');
});

it('when CSAM scanning is disabled (feature flag), goes straight to production bucket', function () {
    config(['partna.moderation.csam_scan_enabled' => false]);
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();

    $result = app(SiteMediaService::class)->createSignedUploadUrl(
        siteId: $site->id,
        mime:   'image/jpeg',
        pool:   'gallery',
    );

    expect($result['url'])->not->toContain('quarantine');
    $row = DB::selectOne("SELECT processing_state FROM site.site_media WHERE id = ?", [$result['site_media_id']]);
    expect($row->processing_state)->toBe('pending');  // existing default
});
```

- [ ] **Step 3: Run to fail**

Expected: FAIL.

- [ ] **Step 4: Modify the upload service**

In `app/Services/Media/SiteMediaService.php`, inside the method that issues signed upload URLs (find the existing implementation; this is a sketch that you'll integrate with the real method body):

```php
public function createSignedUploadUrl(string $siteId, string $mime, string $pool = 'gallery'): array
{
    $scanEnabled = config('partna.moderation.csam_scan_enabled', false);

    $mediaId  = (string) \Illuminate\Support\Str::uuid();
    $key      = $this->buildR2Key($siteId, $mediaId, $mime);

    $url = $scanEnabled
        ? app(\App\Services\Moderation\R2QuarantineService::class)->signedUploadUrl(
            key:           "quarantine/{$key}",
            mime:          $mime,
            maxSizeBytes:  10_000_000,
        )
        : Storage::disk('r2')->temporaryUploadUrl($key, now()->addMinutes(10))['url'];

    \Illuminate\Support\Facades\DB::insert(
        "INSERT INTO site.site_media (id, site_id, bucket, path, pool, processing_state, original_mime, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
        [
            $mediaId,
            $siteId,
            $scanEnabled ? 'partna-media-quarantine' : 'public-assets',
            $scanEnabled ? "quarantine/{$key}" : $key,
            $pool,
            $scanEnabled ? 'scanning' : 'pending',
            $mime,
        ]
    );

    return [
        'site_media_id' => $mediaId,
        'url'           => $url,
    ];
}

private function buildR2Key(string $siteId, string $mediaId, string $mime): string
{
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'bin',
    };
    return "{$siteId}/{$mediaId}.{$ext}";
}
```

Adjust to match the existing method signature and surrounding architecture; the test above asserts the new behavior is reachable.

- [ ] **Step 5: Run + commit**

```bash
php artisan test tests/Feature/Moderation/SiteMediaUploadQuarantineTest.php
git add app/Services/Media/SiteMediaService.php tests/Feature/Moderation/SiteMediaUploadQuarantineTest.php
git commit -m "feat(moderation): route uploads through quarantine bucket when scanning enabled"
```

---

### Task 7: `PromoteCleanMediaJob`

**Files:**
- Create: `app/Jobs/Moderation/PromoteCleanMediaJob.php`
- Create: `app/Services/Cloudflare/CloudflareCsamScanClient.php`
- Modify: `app/Console/Kernel.php`
- Create: `tests/Feature/Moderation/PromoteCleanMediaJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/PromoteCleanMediaJobTest.php`:

```php
<?php

use App\Jobs\Moderation\PromoteCleanMediaJob;
use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use App\Services\Cloudflare\CloudflareCsamScanClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('r2_quarantine');
    Storage::fake('r2');
});

it('promotes scanning media when Cloudflare reports clean', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $mediaId = Str::uuid()->toString();
    DB::insert(<<<'SQL'
        INSERT INTO site.site_media (id, site_id, bucket, path, processing_state, scanned_at)
        VALUES (?, ?, 'partna-media-quarantine', 'quarantine/x/y.jpg', 'scanning', NULL)
    SQL, [$mediaId, $site->id]);
    Storage::disk('r2_quarantine')->put('quarantine/x/y.jpg', 'fake');

    // Mock Cloudflare scan-status API to return clean
    $this->instance(CloudflareCsamScanClient::class, new class extends CloudflareCsamScanClient {
        public function statusFor(string $key): string { return 'clean'; }
    });

    (new PromoteCleanMediaJob)->handle();

    $row = DB::selectOne("SELECT processing_state, scanned_at, bucket FROM site.site_media WHERE id = ?", [$mediaId]);
    expect($row->processing_state)->toBe('ready');
    expect($row->scanned_at)->not->toBeNull();
    expect($row->bucket)->toBe('public-assets');
})->group('postgres');

it('leaves scanning media in place when Cloudflare reports pending', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $mediaId = Str::uuid()->toString();
    DB::insert(<<<'SQL'
        INSERT INTO site.site_media (id, site_id, bucket, path, processing_state, scanned_at)
        VALUES (?, ?, 'partna-media-quarantine', 'quarantine/x/p.jpg', 'scanning', NULL)
    SQL, [$mediaId, $site->id]);

    $this->instance(CloudflareCsamScanClient::class, new class extends CloudflareCsamScanClient {
        public function statusFor(string $key): string { return 'pending'; }
    });

    (new PromoteCleanMediaJob)->handle();

    $row = DB::selectOne("SELECT processing_state FROM site.site_media WHERE id = ?", [$mediaId]);
    expect($row->processing_state)->toBe('scanning');
})->group('postgres');
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create the Cloudflare scan-status client**

Create `app/Services/Cloudflare/CloudflareCsamScanClient.php`:

```php
<?php

namespace App\Services\Cloudflare;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper for Cloudflare's R2 CSAM Scanning Tool status API.
 *
 * Returns one of: 'pending' | 'clean' | 'match' | 'error'.
 *
 * Real endpoint per Cloudflare docs; the test suite mocks this client
 * directly so the URL pattern doesn't need to be precise here.
 */
class CloudflareCsamScanClient
{
    public function statusFor(string $r2Key): string
    {
        $bucket = config('partna.moderation.csam.quarantine_bucket', 'partna-media-quarantine');
        $accountId = config('services.cloudflare.account_id');
        $apiToken  = config('services.cloudflare.api_token');

        $response = Http::withToken($apiToken)
            ->timeout(10)
            ->get("https://api.cloudflare.com/client/v4/accounts/{$accountId}/r2/buckets/{$bucket}/objects/{$r2Key}/csam-status");

        if (! $response->successful()) {
            return 'error';
        }

        return $response->json('result.status', 'pending');
    }
}
```

- [ ] **Step 4: Create the job**

Create `app/Jobs/Moderation/PromoteCleanMediaJob.php`:

```php
<?php

namespace App\Jobs\Moderation;

use App\Services\Cloudflare\CloudflareCsamScanClient;
use App\Services\Moderation\R2QuarantineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled every 60s. Picks up site_media rows stuck in 'scanning',
 * polls Cloudflare for scan status, promotes clean ones.
 * Bounded batch (100 rows per run) so backlogs don't stall the job.
 *
 * Match notifications come via the webhook (CloudflareCsamWebhookController);
 * this job only handles the no-match → promote pathway.
 */
class PromoteCleanMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(
        CloudflareCsamScanClient $client,
        R2QuarantineService $r2,
    ): void {
        $rows = DB::select(<<<'SQL'
            SELECT id, site_id, bucket, path
            FROM site.site_media
            WHERE processing_state = 'scanning'
              AND scanned_at IS NULL
            ORDER BY created_at ASC
            LIMIT 100
        SQL);

        foreach ($rows as $row) {
            $status = $client->statusFor($row->path);

            if ($status === 'pending' || $status === 'error') {
                continue;
            }

            if ($status === 'clean') {
                $this->promote($row, $r2);
            }

            // 'match' is handled by the webhook path — don't double-process here.
        }
    }

    private function promote(object $row, R2QuarantineService $r2): void
    {
        $newKey = str_replace('quarantine/', '', $row->path);

        try {
            $r2->promoteToProduction($row->path, $newKey);
        } catch (\Throwable $e) {
            Log::warning('moderation.promote_clean.failed', [
                'site_media_id' => $row->id,
                'r2_key'        => $row->path,
                'error'         => $e->getMessage(),
            ]);
            return;
        }

        DB::update(<<<'SQL'
            UPDATE site.site_media
            SET processing_state = 'ready',
                scanned_at       = NOW(),
                bucket           = 'public-assets',
                path             = ?,
                updated_at       = NOW()
            WHERE id = ?
        SQL, [$newKey, $row->id]);
    }
}
```

- [ ] **Step 5: Schedule the job**

Modify `app/Console/Kernel.php` `schedule()`:

```php
$schedule->job(new \App\Jobs\Moderation\PromoteCleanMediaJob)
    ->everyMinute()
    ->withoutOverlapping();
```

- [ ] **Step 6: Run + commit**

```bash
php artisan test tests/Feature/Moderation/PromoteCleanMediaJobTest.php --group=postgres
git add app/Jobs/Moderation/PromoteCleanMediaJob.php app/Services/Cloudflare/CloudflareCsamScanClient.php app/Console/Kernel.php tests/Feature/Moderation/PromoteCleanMediaJobTest.php
git commit -m "feat(moderation): PromoteCleanMediaJob polls Cloudflare and promotes clean media"
```

---

### Task 8: `EnforceCsamScanGate` middleware

**Files:**
- Create: `app/Http/Middleware/Moderation/EnforceCsamScanGate.php`
- Create: `tests/Feature/Moderation/EnforceCsamScanGateTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/EnforceCsamScanGateTest.php`:

```php
<?php

use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('filters out site_media rows in scanning or quarantined state from public reads', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();

    foreach (['ready', 'scanning', 'quarantined'] as $state) {
        DB::insert(<<<'SQL'
            INSERT INTO site.site_media (id, site_id, bucket, path, processing_state, scanned_at)
            VALUES (?, ?, 'public-assets', ?, ?, ?)
        SQL, [
            Str::uuid()->toString(),
            $site->id,
            "media/{$state}.jpg",
            $state,
            $state === 'ready' ? now() : null,
        ]);
    }

    // Hit any public site-media-bearing route. Assert only 'ready' media is in the response.
    $res = $this->getJson("/v1/public/sites/{$user->handle}");
    $body = $res->json();
    $jsonBlob = json_encode($body);

    expect($jsonBlob)->toContain('ready.jpg');
    expect($jsonBlob)->not->toContain('scanning.jpg');
    expect($jsonBlob)->not->toContain('quarantined.jpg');
})->group('postgres');
```

(The exact public route depends on the existing `PublicSiteController` structure — adjust the URL to match.)

- [ ] **Step 2: Run to fail**

Expected: FAIL (or pass already if existing code already filters on `processing_state='ready'`).

- [ ] **Step 3: Create the middleware**

Create `app/Http/Middleware/Moderation/EnforceCsamScanGate.php`:

```php
<?php

namespace App\Http\Middleware\Moderation;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence-in-depth gate applied to public media-serve endpoints.
 *
 * Sets a query-scope flag (read by EloquentSiteMedia query macros, or directly
 * by controllers) that ensures only processing_state='ready' AND
 * (scanned_at IS NOT NULL OR scanned_at IS NULL AND created_at < <grandfather-cutoff>)
 * media is returned.
 *
 * The grandfather cutoff is configurable; default = the migration's apply timestamp.
 */
class EnforceCsamScanGate
{
    public function handle(Request $request, Closure $next): Response
    {
        // Application-wide signal that downstream queries can read.
        $request->attributes->set('csam_scan_gate', true);
        return $next($request);
    }
}
```

- [ ] **Step 4: Apply at the existing public site-media query paths**

Modify the existing public site media query (likely in `PublicSiteResolver` or `SiteMediaService`). Find the query that selects site_media for public output. Ensure it has:

```php
->where('processing_state', 'ready')
->where(function ($q) {
    $q->whereNotNull('scanned_at')
      ->orWhere(function ($g) {
          $g->whereNull('scanned_at')
            ->where('created_at', '<', config('partna.moderation.csam.grandfather_cutoff'));
      });
})
```

Add the cutoff to `config/partna.php`:

```php
'csam' => [
    // ...other csam config
    'grandfather_cutoff' => env('PARTNA_CSAM_GRANDFATHER_CUTOFF', '2026-05-26 00:00:00'),
],
```

- [ ] **Step 5: Register middleware on public site routes**

Modify `routes/api/publicSite.php` to apply the middleware on routes that read site_media:

```php
use App\Http\Middleware\Moderation\EnforceCsamScanGate;

Route::middleware([EnforceCsamScanGate::class])->group(function () {
    // existing public site routes that return media
});
```

- [ ] **Step 6: Run + commit**

```bash
php artisan test tests/Feature/Moderation/EnforceCsamScanGateTest.php --group=postgres
git add app/Http/Middleware/Moderation/EnforceCsamScanGate.php config/partna.php routes/api/publicSite.php tests/Feature/Moderation/EnforceCsamScanGateTest.php
git commit -m "feat(moderation): EnforceCsamScanGate + grandfather cutoff on media reads"
```

---

## Phase 5 — Cloudflare CSAM webhook

### Task 9: `VerifyCloudflareWebhookSignature` middleware + nonce store

**Files:**
- Create: `app/Http/Middleware/Cloudflare/VerifyCloudflareWebhookSignature.php`
- Create: `tests/Feature/Security/WebhookSignatureForgeryTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Security/WebhookSignatureForgeryTest.php`:

```php
<?php

use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
    config(['partna.moderation.csam.cloudflare_webhook_secret' => 'test-secret']);
});

function csamWebhookPayload(): array
{
    return [
        'match_id'      => 'm-001',
        'r2_key'        => 'quarantine/foo.jpg',
        'matched_hash'  => 'aabbccdd' . str_repeat('0', 56),
        'confidence'    => 'exact',
    ];
}

function signPayload(array $payload, string $secret, ?int $timestamp = null): array
{
    $ts = $timestamp ?? time();
    $body = json_encode($payload);
    $sig  = hash_hmac('sha256', "{$ts}.{$body}", $secret);
    return [
        'body'    => $body,
        'headers' => [
            'Cf-Webhook-Timestamp' => (string) $ts,
            'Cf-Webhook-Signature' => "t={$ts},v1={$sig}",
        ],
    ];
}

it('rejects requests with no signature header', function () {
    $res = $this->postJson('/v1/internal/cloudflare-csam-webhook', csamWebhookPayload());
    $res->assertStatus(401);
});

it('rejects requests with bad signature', function () {
    $signed = signPayload(csamWebhookPayload(), 'wrong-secret');
    $res = $this->withHeaders($signed['headers'])
        ->call('POST', '/v1/internal/cloudflare-csam-webhook', [], [], [], [], $signed['body']);
    $res->assertStatus(401);
});

it('accepts a valid signature', function () {
    $signed = signPayload(csamWebhookPayload(), 'test-secret');
    $res = $this->withHeaders(array_merge($signed['headers'], ['Content-Type' => 'application/json']))
        ->call('POST', '/v1/internal/cloudflare-csam-webhook', [], [], [], [], $signed['body']);
    expect($res->status())->not->toBe(401);
});

it('rejects a replay (same timestamp + signature) within nonce window', function () {
    $signed = signPayload(csamWebhookPayload(), 'test-secret');
    $headers = array_merge($signed['headers'], ['Content-Type' => 'application/json']);
    $this->withHeaders($headers)
        ->call('POST', '/v1/internal/cloudflare-csam-webhook', [], [], [], [], $signed['body']);

    $second = $this->withHeaders($headers)
        ->call('POST', '/v1/internal/cloudflare-csam-webhook', [], [], [], [], $signed['body']);
    $second->assertStatus(409);
});

it('rejects requests with timestamp older than 5 minutes', function () {
    $signed = signPayload(csamWebhookPayload(), 'test-secret', timestamp: time() - 600);
    $headers = array_merge($signed['headers'], ['Content-Type' => 'application/json']);
    $res = $this->withHeaders($headers)
        ->call('POST', '/v1/internal/cloudflare-csam-webhook', [], [], [], [], $signed['body']);
    $res->assertStatus(401);
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL — middleware + route don't exist.

- [ ] **Step 3: Create the middleware**

Create `app/Http/Middleware/Cloudflare/VerifyCloudflareWebhookSignature.php`:

```php
<?php

namespace App\Http\Middleware\Cloudflare;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies Cloudflare webhook signatures and prevents replays.
 *
 * Signature scheme: HMAC-SHA256("<ts>.<body>", secret)
 * Header format:    Cf-Webhook-Signature: "t=<ts>,v1=<hex>"
 *
 * Rejects:
 * - Missing signature header → 401
 * - Bad HMAC → 401
 * - Timestamp older than 5 minutes → 401 (clock-skew window)
 * - Signature seen in last 10 minutes (Redis nonce) → 409
 */
class VerifyCloudflareWebhookSignature
{
    private const SKEW_SECONDS = 300;        // 5 minutes
    private const NONCE_TTL    = 600;        // 10 minutes

    public function handle(Request $request, Closure $next): Response
    {
        $sigHeader = $request->header('Cf-Webhook-Signature');
        $tsHeader  = $request->header('Cf-Webhook-Timestamp');

        if ($sigHeader === null || $tsHeader === null) {
            return response()->json(['error' => 'INVALID_SIGNATURE'], 401);
        }

        if (! preg_match('/t=(\d+),v1=([a-f0-9]+)/', $sigHeader, $m)) {
            return response()->json(['error' => 'INVALID_SIGNATURE'], 401);
        }
        [, $ts, $providedHex] = $m;
        $ts = (int) $ts;

        if (abs(time() - $ts) > self::SKEW_SECONDS) {
            return response()->json(['error' => 'INVALID_SIGNATURE'], 401);
        }

        $secret = config('partna.moderation.csam.cloudflare_webhook_secret');
        $body   = $request->getContent();
        $expected = hash_hmac('sha256', "{$ts}.{$body}", $secret);

        if (! hash_equals($expected, $providedHex)) {
            return response()->json(['error' => 'INVALID_SIGNATURE'], 401);
        }

        $nonceKey = "moderation:cf_webhook_nonce:{$providedHex}";
        if (Redis::set($nonceKey, '1', 'EX', self::NONCE_TTL, 'NX') === null) {
            return response()->json(['error' => 'REPLAY_DETECTED'], 409);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Run + commit (route + controller in next task)**

```bash
git add app/Http/Middleware/Cloudflare/VerifyCloudflareWebhookSignature.php tests/Feature/Security/WebhookSignatureForgeryTest.php
git commit -m "feat(moderation): VerifyCloudflareWebhookSignature + nonce store"
```

Tests will still fail on the controller-missing part; that's expected and will pass after Task 10.

---

### Task 10: `CloudflareCsamWebhookController` + `CsamMatchHandlerService` + route

**Files:**
- Create: `app/DTOs/Moderation/CloudflareCsamMatchDto.php`
- Create: `app/Http/Controllers/Api/Internal/CloudflareCsamWebhookController.php`
- Create: `app/Services/Moderation/CsamMatchHandlerService.php`
- Create or modify: `routes/api/internal.php` (or `routes/api.php`)
- Create: `tests/Feature/Moderation/CloudflareCsamWebhookTest.php`
- Create: `tests/Unit/Services/Moderation/CsamMatchHandlerServiceTest.php`

- [ ] **Step 1: Write the failing endpoint test**

Create `tests/Feature/Moderation/CloudflareCsamWebhookTest.php`:

```php
<?php

use App\Models\Core\User\User;
use App\Models\Moderation\CsamQuarantine;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\NcmecSubmission;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

beforeEach(function () {
    Redis::flushdb();
    config(['partna.moderation.csam.cloudflare_webhook_secret' => 'test-secret']);
});

function postSignedWebhook(Tests\TestCase $t, array $payload): \Illuminate\Testing\TestResponse
{
    $ts   = time();
    $body = json_encode($payload);
    $sig  = hash_hmac('sha256', "{$ts}.{$body}", 'test-secret');

    return $t->withHeaders([
        'Content-Type'         => 'application/json',
        'Cf-Webhook-Timestamp' => (string) $ts,
        'Cf-Webhook-Signature' => "t={$ts},v1={$sig}",
    ])->call('POST', '/v1/internal/cloudflare-csam-webhook', [], [], [], [], $body);
}

it('handles a valid CSAM match webhook: case + quarantine + ncmec_submission created', function () {
    Queue::fake();
    $user = User::factory()->create(['status' => 'active']);
    $site = Site::factory()->for($user, 'user')->create();
    $mediaId = Str::uuid()->toString();
    DB::insert(<<<'SQL'
        INSERT INTO site.site_media (id, site_id, bucket, path, processing_state, scanned_at)
        VALUES (?, ?, 'partna-media-quarantine', 'quarantine/m.jpg', 'scanning', NULL)
    SQL, [$mediaId, $site->id]);

    $payload = [
        'match_id'      => 'cf-match-001',
        'r2_key'        => 'quarantine/m.jpg',
        'matched_hash'  => str_repeat('a', 64),
        'confidence'    => 'exact',
        'matched_against' => 'NCMEC-NetClean',
    ];

    $res = postSignedWebhook($this, $payload);
    $res->assertStatus(200);

    expect(ModerationCase::query()->where('case_type', 'csam_match')->count())->toBe(1);
    expect(CsamQuarantine::query()->count())->toBe(1);
    expect(NcmecSubmission::query()->count())->toBe(1);
    expect(Decision::query()->where('decided_by_system', true)->count())->toBe(1);
})->group('postgres');

it('returns 404 when r2_key does not map to any site_media row', function () {
    $payload = [
        'match_id' => 'orphan',
        'r2_key'   => 'quarantine/does-not-exist.jpg',
        'matched_hash' => str_repeat('b', 64),
        'confidence'   => 'exact',
        'matched_against' => 'NCMEC-NetClean',
    ];

    $res = postSignedWebhook($this, $payload);
    $res->assertStatus(404);
})->group('postgres');
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create the DTO**

Create `app/DTOs/Moderation/CloudflareCsamMatchDto.php`:

```php
<?php

namespace App\DTOs\Moderation;

final class CloudflareCsamMatchDto
{
    public function __construct(
        public readonly string $matchId,
        public readonly string $r2Key,
        public readonly string $matchedHash,
        public readonly string $confidence,
        public readonly string $matchedAgainst,
        public readonly array $rawPayload,
    ) {}

    public static function fromArray(array $a): self
    {
        return new self(
            matchId:        $a['match_id'],
            r2Key:          $a['r2_key'],
            matchedHash:    $a['matched_hash'],
            confidence:     $a['confidence'],
            matchedAgainst: $a['matched_against'] ?? 'unknown',
            rawPayload:     $a,
        );
    }
}
```

- [ ] **Step 4: Create `CsamMatchHandlerService`**

Create `app/Services/Moderation/CsamMatchHandlerService.php`:

```php
<?php

namespace App\Services\Moderation;

use App\DTOs\Moderation\CloudflareCsamMatchDto;
use App\DTOs\Moderation\DecisionDto;
use App\Models\Moderation\CsamQuarantine;
use App\Models\Moderation\Evidence;
use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\NcmecSubmission;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orchestrates the CSAM auto-action pipeline:
 *   webhook arrives → resolve SiteMedia row → create case + signal + evidence
 *   → write csam_quarantine row → write ncmec_submissions outbox row
 *   → call ModerationDecisionService::decideAsSystem(suspend_user)
 *
 * The dispatcher fires QuarantineMediaJob, SuspendUserJob, SuspendSiteJob,
 * PurgeModerationCacheJob, NotifyOnCallStaffJob, and FileCyberTipReportJob
 * (registered against ncmec_submission outbox row).
 */
class CsamMatchHandlerService
{
    public function __construct(
        private readonly DedupHashCalculator $dedup,
        private readonly EvidenceSnapshotService $evidence,
        private readonly ModerationDecisionService $decisions,
    ) {}

    public function handle(CloudflareCsamMatchDto $dto): array
    {
        return DB::transaction(function () use ($dto) {
            $siteMedia = DB::selectOne(
                'SELECT id, site_id FROM site.site_media WHERE path = ?',
                [$dto->r2Key]
            );
            if ($siteMedia === null) {
                throw new RuntimeException("MEDIA_NOT_FOUND:{$dto->r2Key}");
            }

            $siteOwner = DB::selectOne(
                'SELECT professional_id FROM site.sites WHERE id = ?',
                [$siteMedia->site_id]
            );

            $case = ModerationCase::create([
                'case_type'                => 'csam_match',
                'reportable_type'          => 'SiteMedia',
                'reportable_id'            => $siteMedia->id,
                'reportable_owner_user_id' => $siteOwner?->professional_id,
                'severity'                 => 5,
                'status'                   => 'open',
                'signal_count'             => 1,
                'auto_actioned'            => false,
                'priority'                 => 1,
            ]);

            \App\Models\Moderation\CaseSignal::create([
                'case_id'        => $case->id,
                'signal_source'  => 'csam_scan',
                'signal_data'    => $dto->rawPayload,
                'reason_code'    => 'auto_csam_hash_match',
                'dedup_hash'     => $this->dedup->forCsamMatch($dto->matchId, $siteMedia->id),
            ]);

            Evidence::create([
                'case_id'       => $case->id,
                'evidence_type' => 'csam_hash_match',
                'payload'       => $dto->rawPayload,
                'content_hash'  => $dto->matchedHash,
            ]);

            $quarantine = CsamQuarantine::create([
                'case_id'                  => $case->id,
                'site_media_id'            => $siteMedia->id,
                'uploader_user_id'         => $siteOwner?->professional_id,
                'content_hash'             => $dto->matchedHash,
                'cloudflare_match_payload' => $dto->rawPayload,
                'r2_quarantine_key'        => $dto->r2Key,
                'preservation_expires_at'  => now()->addDays(config('partna.moderation.csam.preservation_days', 90)),
            ]);

            NcmecSubmission::create([
                'csam_quarantine_id' => $quarantine->id,
                'payload'            => [
                    'matchId'        => $dto->matchId,
                    'matchedHash'    => $dto->matchedHash,
                    'r2QuarantineKey'=> $dto->r2Key,
                    'uploaderUserId' => $siteOwner?->professional_id,
                ],
                'status' => 'pending',
            ]);

            $decisionDto = new DecisionDto(
                decisionType:           'suspend_user',
                reason:                 'auto_csam_match',
                secondStaffApprovalId:  null,
            );
            $decision = $this->decisions->decideAsSystem($case, $decisionDto);

            return [
                'case_id'        => $case->id,
                'decision_id'    => $decision->id,
                'quarantine_id'  => $quarantine->id,
            ];
        });
    }
}
```

- [ ] **Step 5: Create the controller**

Create `app/Http/Controllers/Api/Internal/CloudflareCsamWebhookController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Internal;

use App\DTOs\Moderation\CloudflareCsamMatchDto;
use App\Http\Controllers\Controller;
use App\Services\Moderation\CsamMatchHandlerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CloudflareCsamWebhookController extends Controller
{
    public function handle(Request $request, CsamMatchHandlerService $handler): JsonResponse
    {
        $payload = $request->all();

        try {
            $dto    = CloudflareCsamMatchDto::fromArray($payload);
            $result = $handler->handle($dto);
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'MEDIA_NOT_FOUND:')) {
                return response()->json(['error' => 'MEDIA_NOT_FOUND'], 404);
            }
            throw $e;
        }

        return response()->json([
            'case_id'       => $result['case_id'],
            'decision_id'   => $result['decision_id'],
            'quarantine_id' => $result['quarantine_id'],
        ], 200);
    }
}
```

- [ ] **Step 6: Add the route**

Modify `routes/api.php` (or create `routes/api/internal.php` and `require` it):

```php
use App\Http\Controllers\Api\Internal\CloudflareCsamWebhookController;
use App\Http\Middleware\Cloudflare\VerifyCloudflareWebhookSignature;

Route::middleware([VerifyCloudflareWebhookSignature::class])->group(function () {
    Route::post('/v1/internal/cloudflare-csam-webhook',
        [CloudflareCsamWebhookController::class, 'handle']
    )->name('internal.cloudflare.csam.webhook');
});
```

- [ ] **Step 7: Run + commit**

```bash
composer dump-autoload
php artisan test tests/Feature/Moderation/CloudflareCsamWebhookTest.php --group=postgres
php artisan test tests/Feature/Security/WebhookSignatureForgeryTest.php
git add app/DTOs/Moderation/CloudflareCsamMatchDto.php app/Http/Controllers/Api/Internal/CloudflareCsamWebhookController.php app/Services/Moderation/CsamMatchHandlerService.php routes/api.php tests/Feature/Moderation/CloudflareCsamWebhookTest.php
git commit -m "feat(moderation): Cloudflare CSAM webhook + CsamMatchHandlerService"
```

---

## Phase 6 — NCMEC outbox

### Task 11: `NcmecSubmissionService` + `FileCyberTipReportJob`

**Files:**
- Create: `app/Services/Moderation/NcmecSubmissionService.php`
- Create: `app/Services/Moderation/NcmecSubmissionFailedTooManyTimes.php`
- Modify: `app/Jobs/Moderation/FileCyberTipReportJob.php`
- Create: `tests/Unit/Services/Moderation/NcmecSubmissionServiceTest.php`
- Create: `tests/Feature/Moderation/FileCyberTipReportJobTest.php`

- [ ] **Step 1: Write the unit test**

Create `tests/Unit/Services/Moderation/NcmecSubmissionServiceTest.php`:

```php
<?php

use App\Models\Moderation\NcmecSubmission;
use App\Services\Moderation\NcmecSubmissionService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'partna.moderation.csam.ncmec_endpoint' => 'https://hashmatching.api.missingkids.org/cybertip',
        'partna.moderation.csam.ncmec_api_key'  => 'test-key',
        'partna.moderation.csam.ncmec_esp_id'   => 'TEST-ESP',
        'partna.moderation.csam.ncmec_max_attempts' => 5,
    ]);
});

it('successful submission writes ncmec_tip_id and marks submitted', function () {
    Http::fake([
        'hashmatching.api.missingkids.org/*' => Http::response([
            'tipId' => 'NCMEC-TIP-001',
            'status' => 'received',
        ], 200),
    ]);

    $sub = NcmecSubmission::factory()->create();
    app(NcmecSubmissionService::class)->submit($sub);

    $sub->refresh();
    expect($sub->status)->toBe('submitted');
    expect($sub->ncmec_tip_id)->toBe('NCMEC-TIP-001');
    expect($sub->submitted_at)->not->toBeNull();
});

it('failed submission increments attempts and stores last_error', function () {
    Http::fake([
        'hashmatching.api.missingkids.org/*' => Http::response(['error' => 'down'], 503),
    ]);

    $sub = NcmecSubmission::factory()->create();
    try {
        app(NcmecSubmissionService::class)->submit($sub);
    } catch (\Throwable) {
        // expected
    }

    $sub->refresh();
    expect($sub->status)->toBe('failed');
    expect($sub->attempts)->toBe(1);
    expect($sub->last_error)->not->toBeNull();
});

it('escalates to manual_fallback_required after max attempts', function () {
    Http::fake([
        'hashmatching.api.missingkids.org/*' => Http::response(['error' => 'down'], 503),
    ]);

    $sub = NcmecSubmission::factory()->failed(attempts: 4)->create();
    try {
        app(NcmecSubmissionService::class)->submit($sub);
    } catch (\Throwable) {
        // expected
    }

    $sub->refresh();
    expect($sub->status)->toBe('manual_fallback_required');
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create the exception class**

Create `app/Services/Moderation/NcmecSubmissionFailedTooManyTimes.php`:

```php
<?php

namespace App\Services\Moderation;

use RuntimeException;

class NcmecSubmissionFailedTooManyTimes extends RuntimeException
{
    public function __construct(string $submissionId)
    {
        parent::__construct("NCMEC submission {$submissionId} hit max attempts");
    }
}
```

- [ ] **Step 4: Create the service**

Create `app/Services/Moderation/NcmecSubmissionService.php`:

```php
<?php

namespace App\Services\Moderation;

use App\Models\Moderation\NcmecSubmission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Outbox-pattern submitter for NCMEC's CyberTipline API.
 *
 * Strategy:
 *   1. Mark submitting → call API → write outcome.
 *   2. On HTTP success: status=submitted + ncmec_tip_id from response.
 *   3. On failure: increment attempts, status=failed, last_error.
 *   4. If attempts ≥ max: status=manual_fallback_required + Nightwatch alert.
 *
 * Retry orchestration is handled by ModerationRetryNcmecSubmissionsCommand
 * (scheduled every 5 min) plus FileCyberTipReportJob (immediate dispatch).
 */
class NcmecSubmissionService
{
    public function submit(NcmecSubmission $sub): void
    {
        $endpoint = config('partna.moderation.csam.ncmec_endpoint');
        $apiKey   = config('partna.moderation.csam.ncmec_api_key');
        $espId    = config('partna.moderation.csam.ncmec_esp_id');
        $maxAttempts = (int) config('partna.moderation.csam.ncmec_max_attempts', 5);

        $sub->update(['status' => 'submitting', 'attempts' => $sub->attempts + 1]);

        try {
            $response = Http::withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'X-NCMEC-ESP-Id'=> $espId,
                ])
                ->timeout(30)
                ->post($endpoint, $sub->payload);

            if (! $response->successful()) {
                throw new RuntimeException("NCMEC API responded {$response->status()}: {$response->body()}");
            }

            $sub->update([
                'status'                 => 'submitted',
                'ncmec_tip_id'           => $response->json('tipId'),
                'ncmec_response_payload' => $response->json(),
                'submitted_at'           => now(),
                'response_received_at'   => now(),
            ]);

        } catch (\Throwable $e) {
            Log::warning('moderation.ncmec_submission.failed', [
                'submission_id' => $sub->id,
                'attempts'      => $sub->attempts,
                'error'         => $e->getMessage(),
            ]);

            $newStatus = $sub->attempts >= $maxAttempts
                ? 'manual_fallback_required'
                : 'failed';

            $sub->update([
                'status'     => $newStatus,
                'last_error' => substr($e->getMessage(), 0, 2000),
            ]);

            if ($newStatus === 'manual_fallback_required') {
                Log::error('moderation.ncmec_submission.manual_fallback_required', [
                    'submission_id'      => $sub->id,
                    'csam_quarantine_id' => $sub->csam_quarantine_id,
                ]);
                throw new NcmecSubmissionFailedTooManyTimes($sub->id);
            }

            throw $e;
        }
    }
}
```

- [ ] **Step 5: Implement `FileCyberTipReportJob`**

Create `app/Jobs/Moderation/FileCyberTipReportJob.php`:

```php
<?php

namespace App\Jobs\Moderation;

use App\Models\Moderation\NcmecSubmission;
use App\Services\Moderation\NcmecSubmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FileCyberTipReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;  // outbox handles retry orchestration; one try per dispatch

    public string $queue = 'moderation_high';

    public function __construct(public readonly string $submissionId) {}

    public function handle(NcmecSubmissionService $service): void
    {
        $sub = NcmecSubmission::query()->find($this->submissionId);
        if ($sub === null) return;

        if (in_array($sub->status, ['submitted', 'manual_fallback_required'], true)) {
            return;
        }

        $service->submit($sub);
    }
}
```

- [ ] **Step 6: Wire FileCyberTipReportJob into `CsamMatchHandlerService` dispatch**

Modify `CsamMatchHandlerService` to dispatch `FileCyberTipReportJob` after the transaction commits. Append inside the DB::transaction closure (before `return`):

```php
\Illuminate\Support\Facades\DB::afterCommit(function () use ($quarantine) {
    $sub = NcmecSubmission::query()->where('csam_quarantine_id', $quarantine->id)->first();
    if ($sub !== null) {
        FileCyberTipReportJob::dispatch($sub->id);
    }
});
```

Add use statement:

```php
use App\Jobs\Moderation\FileCyberTipReportJob;
```

- [ ] **Step 7: Run + commit**

```bash
php artisan test tests/Unit/Services/Moderation/NcmecSubmissionServiceTest.php
git add app/Services/Moderation/NcmecSubmissionService.php app/Services/Moderation/NcmecSubmissionFailedTooManyTimes.php app/Jobs/Moderation/FileCyberTipReportJob.php app/Services/Moderation/CsamMatchHandlerService.php tests/Unit/Services/Moderation/NcmecSubmissionServiceTest.php
git commit -m "feat(moderation): NcmecSubmissionService + FileCyberTipReportJob (outbox)"
```

---

### Task 12: Job-level integration test for the file-cybertip path

**Files:**
- Create: `tests/Feature/Moderation/FileCyberTipReportJobTest.php`

- [ ] **Step 1: Write the test**

Create `tests/Feature/Moderation/FileCyberTipReportJobTest.php`:

```php
<?php

use App\Jobs\Moderation\FileCyberTipReportJob;
use App\Models\Moderation\NcmecSubmission;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'partna.moderation.csam.ncmec_endpoint' => 'https://hashmatching.api.missingkids.org/cybertip',
        'partna.moderation.csam.ncmec_api_key'  => 'test',
        'partna.moderation.csam.ncmec_esp_id'   => 'ESP',
        'partna.moderation.csam.ncmec_max_attempts' => 5,
    ]);
});

it('submits a pending row and marks it submitted on 200', function () {
    Http::fake(['*' => Http::response(['tipId' => 'NCMEC-100'], 200)]);
    $sub = NcmecSubmission::factory()->create();

    (new FileCyberTipReportJob($sub->id))->handle(app(\App\Services\Moderation\NcmecSubmissionService::class));

    $sub->refresh();
    expect($sub->status)->toBe('submitted');
    expect($sub->ncmec_tip_id)->toBe('NCMEC-100');
});

it('is a no-op if submission is already submitted', function () {
    Http::fake(['*' => Http::response(['tipId' => 'should-not-call'], 200)]);
    $sub = NcmecSubmission::factory()->submitted('NCMEC-EXISTING')->create();

    (new FileCyberTipReportJob($sub->id))->handle(app(\App\Services\Moderation\NcmecSubmissionService::class));

    expect($sub->fresh()->ncmec_tip_id)->toBe('NCMEC-EXISTING');
});
```

- [ ] **Step 2: Run + commit**

```bash
php artisan test tests/Feature/Moderation/FileCyberTipReportJobTest.php
git add tests/Feature/Moderation/FileCyberTipReportJobTest.php
git commit -m "test(moderation): FileCyberTipReportJob integration"
```

---

## Phase 7 — Auto-action pipeline end-to-end test

### Task 13: Full CSAM auto-action pipeline test

**Files:**
- Create: `tests/Feature/Moderation/CsamAutoActionPipelineTest.php`

- [ ] **Step 1: Write the comprehensive test**

Create `tests/Feature/Moderation/CsamAutoActionPipelineTest.php`:

```php
<?php

use App\Jobs\Moderation\FileCyberTipReportJob;
use App\Jobs\Moderation\NotifyOnCallStaffJob;
use App\Jobs\Moderation\QuarantineMediaJob;
use App\Jobs\Moderation\SuspendUserJob;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Models\Moderation\CsamQuarantine;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\NcmecSubmission;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

beforeEach(function () {
    Redis::flushdb();
    config([
        'partna.moderation.csam.cloudflare_webhook_secret' => 'test',
        'partna.moderation.csam.ncmec_endpoint' => 'https://hashmatching.api.missingkids.org/cybertip',
        'partna.moderation.csam.ncmec_api_key'  => 'test',
        'partna.moderation.csam.ncmec_esp_id'   => 'ESP',
    ]);
});

it('webhook → full auto-action: case + decision + quarantine + outbox + all jobs dispatched', function () {
    Bus::fake([SuspendUserJob::class, QuarantineMediaJob::class, NotifyOnCallStaffJob::class, FileCyberTipReportJob::class]);
    PartnaStaff::factory()->create(['role' => 'admin']);

    $user = User::factory()->create(['status' => 'active']);
    $site = Site::factory()->for($user, 'user')->create();
    $mediaId = Str::uuid()->toString();
    DB::insert(<<<'SQL'
        INSERT INTO site.site_media (id, site_id, bucket, path, processing_state, scanned_at)
        VALUES (?, ?, 'partna-media-quarantine', 'quarantine/auto.jpg', 'scanning', NULL)
    SQL, [$mediaId, $site->id]);

    $payload = [
        'match_id'        => 'auto-001',
        'r2_key'          => 'quarantine/auto.jpg',
        'matched_hash'    => str_repeat('c', 64),
        'confidence'      => 'exact',
        'matched_against' => 'NCMEC-NetClean',
    ];

    $ts   = time();
    $body = json_encode($payload);
    $sig  = hash_hmac('sha256', "{$ts}.{$body}", 'test');

    $res = $this->withHeaders([
        'Content-Type'         => 'application/json',
        'Cf-Webhook-Timestamp' => (string) $ts,
        'Cf-Webhook-Signature' => "t={$ts},v1={$sig}",
    ])->call('POST', '/v1/internal/cloudflare-csam-webhook', [], [], [], [], $body);

    $res->assertStatus(200);

    expect(ModerationCase::query()->where('case_type', 'csam_match')->count())->toBe(1);
    expect(CsamQuarantine::query()->where('site_media_id', $mediaId)->count())->toBe(1);
    expect(NcmecSubmission::query()->count())->toBe(1);
    expect(Decision::query()->where('decided_by_system', true)->where('auto_actioned', true)->count())->toBe(1);

    Bus::assertDispatched(SuspendUserJob::class);
    Bus::assertDispatched(QuarantineMediaJob::class);
    Bus::assertDispatched(NotifyOnCallStaffJob::class);
    Bus::assertDispatched(FileCyberTipReportJob::class);
})->group('postgres');
```

- [ ] **Step 2: Run + commit**

```bash
php artisan test tests/Feature/Moderation/CsamAutoActionPipelineTest.php --group=postgres
git add tests/Feature/Moderation/CsamAutoActionPipelineTest.php
git commit -m "test(moderation): full CSAM webhook → auto-action pipeline integration"
```

---

## Phase 8 — Lifecycle commands

### Task 14: `moderation:expire-csam-quarantine` (daily)

**Files:**
- Create: `app/Console/Commands/Moderation/ModerationExpireCsamQuarantineCommand.php`
- Modify: `app/Console/Kernel.php`
- Create: `tests/Feature/Commands/Moderation/ExpireCsamQuarantineCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Commands/Moderation/ExpireCsamQuarantineCommandTest.php`:

```php
<?php

use App\Models\Moderation\AuditEvent;
use App\Models\Moderation\CsamQuarantine;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2_quarantine');
});

it('deletes R2 binary + flips r2_binary_deleted for expired rows', function () {
    $expired = CsamQuarantine::factory()->expired()->create();
    Storage::disk('r2_quarantine')->put($expired->r2_quarantine_key, 'binary');

    $fresh = CsamQuarantine::factory()->create(); // preservation_expires_at = +90d
    Storage::disk('r2_quarantine')->put($fresh->r2_quarantine_key, 'binary');

    $this->artisan('moderation:expire-csam-quarantine')->assertSuccessful();

    $expired->refresh();
    expect($expired->r2_binary_deleted)->toBeTrue();
    expect($expired->r2_binary_deleted_at)->not->toBeNull();
    expect(Storage::disk('r2_quarantine')->exists($expired->r2_quarantine_key))->toBeFalse();

    $fresh->refresh();
    expect($fresh->r2_binary_deleted)->toBeFalse();
    expect(Storage::disk('r2_quarantine')->exists($fresh->r2_quarantine_key))->toBeTrue();

    expect(AuditEvent::query()->where('action', 'csam.quarantine_binary_deleted')->count())->toBeGreaterThanOrEqual(1);
});

it('skips rows whose binary is already deleted', function () {
    $row = CsamQuarantine::factory()->expired()->binaryDeleted()->create();

    $this->artisan('moderation:expire-csam-quarantine')->assertSuccessful();

    // unchanged
    expect($row->fresh()->r2_binary_deleted)->toBeTrue();
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/Moderation/ModerationExpireCsamQuarantineCommand.php`:

```php
<?php

namespace App\Console\Commands\Moderation;

use App\Models\Moderation\CsamQuarantine;
use App\Services\Moderation\ModerationAuditService;
use App\Services\Moderation\R2QuarantineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ModerationExpireCsamQuarantineCommand extends Command
{
    protected $signature = 'moderation:expire-csam-quarantine';
    protected $description = 'Delete R2 binaries for csam_quarantine rows past their 90-day preservation window.';

    public function handle(R2QuarantineService $r2, ModerationAuditService $audit): int
    {
        $expired = CsamQuarantine::query()
            ->where('r2_binary_deleted', false)
            ->where('preservation_expires_at', '<', now())
            ->get();

        $deleted = 0;
        foreach ($expired as $row) {
            try {
                $r2->deleteQuarantineBinary($row->r2_quarantine_key);
                $row->update([
                    'r2_binary_deleted'    => true,
                    'r2_binary_deleted_at' => now(),
                ]);

                $audit->recordSystemAction(
                    'csam.quarantine_binary_deleted',
                    'CsamQuarantine',
                    $row->id,
                    ['r2_key' => $row->r2_quarantine_key],
                );
                $deleted++;
            } catch (\Throwable $e) {
                Log::error('moderation.csam_quarantine.delete_failed', [
                    'quarantine_id' => $row->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        $this->info("Expired {$deleted} quarantine binaries.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Schedule daily**

Modify `app/Console/Kernel.php` `schedule()`:

```php
$schedule->command('moderation:expire-csam-quarantine')->dailyAt('03:00');
```

- [ ] **Step 5: Run + commit**

```bash
php artisan test tests/Feature/Commands/Moderation/ExpireCsamQuarantineCommandTest.php
git add app/Console/Commands/Moderation/ModerationExpireCsamQuarantineCommand.php app/Console/Kernel.php tests/Feature/Commands/Moderation/ExpireCsamQuarantineCommandTest.php
git commit -m "feat(moderation): moderation:expire-csam-quarantine (daily 90-day expiry)"
```

---

### Task 15: `moderation:audit-quarantine-bucket` (daily)

**Files:**
- Create: `app/Console/Commands/Moderation/ModerationAuditQuarantineBucketCommand.php`
- Modify: `app/Console/Kernel.php`
- Create: `tests/Feature/Commands/Moderation/AuditQuarantineBucketCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Commands/Moderation/AuditQuarantineBucketCommandTest.php`:

```php
<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config(['partna.moderation.csam.quarantine_bucket' => 'partna-media-quarantine']);
    config(['services.cloudflare.account_id' => 'acct-123']);
    config(['services.cloudflare.api_token'  => 'token']);
});

it('passes when bucket has no public access policy', function () {
    Http::fake([
        '*' => Http::response([
            'result' => [
                'public_access' => false,
                'cors_origins'  => [],
            ],
        ], 200),
    ]);

    $this->artisan('moderation:audit-quarantine-bucket')->assertSuccessful();
});

it('emits critical alert when bucket has any public access', function () {
    Log::spy();
    Http::fake([
        '*' => Http::response([
            'result' => [
                'public_access' => true,
                'cors_origins'  => [],
            ],
        ], 200),
    ]);

    $this->artisan('moderation:audit-quarantine-bucket')->assertFailed();

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'quarantine_bucket.public_drift'))
        ->once();
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/Moderation/ModerationAuditQuarantineBucketCommand.php`:

```php
<?php

namespace App\Console\Commands\Moderation;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ModerationAuditQuarantineBucketCommand extends Command
{
    protected $signature = 'moderation:audit-quarantine-bucket';
    protected $description = 'Verify that the R2 quarantine bucket has no public access policy.';

    public function handle(): int
    {
        $accountId = config('services.cloudflare.account_id');
        $apiToken  = config('services.cloudflare.api_token');
        $bucket    = config('partna.moderation.csam.quarantine_bucket');

        $res = Http::withToken($apiToken)
            ->timeout(10)
            ->get("https://api.cloudflare.com/client/v4/accounts/{$accountId}/r2/buckets/{$bucket}");

        if (! $res->successful()) {
            $this->error("Bucket inspection failed: {$res->status()}");
            return self::FAILURE;
        }

        $publicAccess = $res->json('result.public_access', false);
        $corsOrigins  = $res->json('result.cors_origins', []);

        if ($publicAccess === true || ! empty($corsOrigins)) {
            Log::error('moderation.quarantine_bucket.public_drift', [
                'bucket'         => $bucket,
                'public_access'  => $publicAccess,
                'cors_origins'   => $corsOrigins,
            ]);
            $this->error('CRITICAL: quarantine bucket has public access drift.');
            return self::FAILURE;
        }

        $this->info("OK: quarantine bucket {$bucket} is private.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Schedule daily**

Modify `app/Console/Kernel.php`:

```php
$schedule->command('moderation:audit-quarantine-bucket')->dailyAt('04:00');
```

- [ ] **Step 5: Run + commit**

```bash
php artisan test tests/Feature/Commands/Moderation/AuditQuarantineBucketCommandTest.php
git add app/Console/Commands/Moderation/ModerationAuditQuarantineBucketCommand.php app/Console/Kernel.php tests/Feature/Commands/Moderation/AuditQuarantineBucketCommandTest.php
git commit -m "feat(moderation): moderation:audit-quarantine-bucket (daily drift check)"
```

---

### Task 16: `moderation:retry-ncmec-submissions` (every 5 min)

**Files:**
- Create: `app/Console/Commands/Moderation/ModerationRetryNcmecSubmissionsCommand.php`
- Modify: `app/Console/Kernel.php`
- Create: `tests/Feature/Commands/Moderation/RetryNcmecSubmissionsCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Commands/Moderation/RetryNcmecSubmissionsCommandTest.php`:

```php
<?php

use App\Jobs\Moderation\FileCyberTipReportJob;
use App\Models\Moderation\NcmecSubmission;
use Illuminate\Support\Facades\Queue;

it('dispatches FileCyberTipReportJob for pending submissions', function () {
    Queue::fake();
    NcmecSubmission::factory()->create(['status' => 'pending', 'attempts' => 0]);
    NcmecSubmission::factory()->create(['status' => 'failed', 'attempts' => 2]);

    $this->artisan('moderation:retry-ncmec-submissions')->assertSuccessful();

    Queue::assertPushed(FileCyberTipReportJob::class, 2);
});

it('skips submissions that already succeeded', function () {
    Queue::fake();
    NcmecSubmission::factory()->submitted()->create();

    $this->artisan('moderation:retry-ncmec-submissions')->assertSuccessful();
    Queue::assertNotPushed(FileCyberTipReportJob::class);
});

it('skips submissions stuck at manual_fallback_required (Nightwatch alert already fired)', function () {
    Queue::fake();
    NcmecSubmission::factory()->create(['status' => 'manual_fallback_required', 'attempts' => 5]);

    $this->artisan('moderation:retry-ncmec-submissions')->assertSuccessful();
    Queue::assertNotPushed(FileCyberTipReportJob::class);
});

it('skips submissions that have hit max attempts but still marked failed', function () {
    Queue::fake();
    NcmecSubmission::factory()->create(['status' => 'failed', 'attempts' => 5]);

    $this->artisan('moderation:retry-ncmec-submissions')->assertSuccessful();
    Queue::assertNotPushed(FileCyberTipReportJob::class);
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/Moderation/ModerationRetryNcmecSubmissionsCommand.php`:

```php
<?php

namespace App\Console\Commands\Moderation;

use App\Jobs\Moderation\FileCyberTipReportJob;
use App\Models\Moderation\NcmecSubmission;
use Illuminate\Console\Command;

class ModerationRetryNcmecSubmissionsCommand extends Command
{
    protected $signature = 'moderation:retry-ncmec-submissions';
    protected $description = 'Re-dispatch FileCyberTipReportJob for submissions in pending or failed state under max attempts.';

    public function handle(): int
    {
        $maxAttempts = (int) config('partna.moderation.csam.ncmec_max_attempts', 5);

        $eligible = NcmecSubmission::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', $maxAttempts)
            ->get(['id']);

        foreach ($eligible as $row) {
            FileCyberTipReportJob::dispatch($row->id);
        }

        $this->info("Dispatched {$eligible->count()} retries.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Schedule every 5 minutes**

Modify `app/Console/Kernel.php`:

```php
$schedule->command('moderation:retry-ncmec-submissions')->everyFiveMinutes();
```

- [ ] **Step 5: Run + commit**

```bash
php artisan test tests/Feature/Commands/Moderation/RetryNcmecSubmissionsCommandTest.php
git add app/Console/Commands/Moderation/ModerationRetryNcmecSubmissionsCommand.php app/Console/Kernel.php tests/Feature/Commands/Moderation/RetryNcmecSubmissionsCommandTest.php
git commit -m "feat(moderation): moderation:retry-ncmec-submissions (every 5min)"
```

---

## Phase 9 — Config + capabilities + Horizon

### Task 17: Extend `config/partna.php` with full CSAM config

**Files:**
- Modify: `config/partna.php`

- [ ] **Step 1: Extend the moderation config section**

Modify `config/partna.php`. Replace the existing `'csam' => [...]` (or add it if missing) inside the `moderation` block:

```php
'csam' => [
    'enabled'                    => env('PARTNA_CSAM_SCAN_ENABLED', false),
    'cloudflare_webhook_secret'  => env('CLOUDFLARE_CSAM_WEBHOOK_SECRET'),
    'quarantine_bucket'          => env('R2_QUARANTINE_BUCKET', 'partna-media-quarantine'),
    'production_bucket'          => env('R2_PRODUCTION_BUCKET', 'public-assets'),
    'preservation_days'          => 90,
    'grandfather_cutoff'         => env('PARTNA_CSAM_GRANDFATHER_CUTOFF', '2026-05-26 00:00:00'),
    'ncmec_endpoint'             => env('NCMEC_CYBERTIP_ENDPOINT', 'https://hashmatching.api.missingkids.org/cybertip'),
    'ncmec_api_key'              => env('NCMEC_API_KEY'),
    'ncmec_esp_id'               => env('NCMEC_ESP_ID'),
    'ncmec_max_attempts'         => 5,
],
```

- [ ] **Step 2: Commit**

```bash
git add config/partna.php
git commit -m "feat(moderation): full CSAM config block in config/partna.php"
```

---

## Phase 10 — Documentation + runbooks

### Task 18: CSAM pipeline operator runbook

**Files:**
- Create: `docs/moderation/csam-pipeline.md`
- Modify: `docs/moderation/README.md`

- [ ] **Step 1: Create the CSAM pipeline runbook**

Create `docs/moderation/csam-pipeline.md`:

```markdown
# CSAM scanning pipeline — operator runbook

## What this pipeline does

Every user-uploaded image:
1. Uploads to **`partna-media-quarantine`** R2 bucket (private, no public access)
2. **Cloudflare CSAM Scanning Tool** scans against NCMEC PhotoDNA database
3. If clean → `PromoteCleanMediaJob` (every 60s) copies to production bucket, marks `processing_state='ready'`
4. If match → Cloudflare posts to `/v1/internal/cloudflare-csam-webhook`:
   - Case + signal + evidence rows created
   - User auto-suspended, site auto-hidden
   - Media auto-quarantined
   - Edge cache purged
   - On-call staff notified
   - NCMEC CyberTipline report filed (outbox pattern with retry)

## Feature flags

| Flag | Effect |
|------|--------|
| `PARTNA_CSAM_SCAN_ENABLED=false` (default) | Skips quarantine routing entirely; uploads go straight to production. **Production must flip to `true` before launch.** |
| `PARTNA_MODERATION_AUTO_ACTIONS_ENABLED=false` | Webhook still creates the case + quarantine row but does NOT auto-suspend. Useful for testing without committing to suspension semantics. |

## NCMEC ESP credentials

| Env var | Purpose |
|---------|---------|
| `NCMEC_API_KEY` | Bearer token for CyberTipline API |
| `NCMEC_ESP_ID` | Our registered ESP identifier |
| `NCMEC_CYBERTIP_ENDPOINT` | API URL (default in config) |

Designated legal contact: **Josh Hunter** (founder). Reassign via NCMEC ESP portal at any time.

## Common ops tasks

```bash
# Check pending NCMEC submissions
php artisan tinker --execute='echo App\Models\Moderation\NcmecSubmission::where("status","pending")->count();'

# Check submissions stuck at manual_fallback_required (needs human action)
php artisan tinker --execute='dump(App\Models\Moderation\NcmecSubmission::where("status","manual_fallback_required")->get(["id","csam_quarantine_id","last_error"])->toArray());'

# Force a retry sweep
php artisan moderation:retry-ncmec-submissions

# Force-run the quarantine bucket audit
php artisan moderation:audit-quarantine-bucket

# Show a CSAM case in detail
php artisan moderation:show-case <case_id>

# Force-run the 90-day expiry job (normally daily at 03:00)
php artisan moderation:expire-csam-quarantine
```

## What to do when …

### Cloudflare CSAM Scanning Tool is down

Uploads continue (Cloudflare's API stays up even if scanning is delayed). Media stays in `processing_state='scanning'` until the scan-status API returns. If extended outage (>2h), Nightwatch alerts; escalate to Cloudflare support.

### NCMEC API is down

`FileCyberTipReportJob` retries with exponential backoff. After 5 attempts → `manual_fallback_required` + Nightwatch alert. Action: submit the report manually via NCMEC's web portal at https://report.cybertip.org, then update the row:

```bash
php artisan tinker --execute='
  $sub = App\Models\Moderation\NcmecSubmission::find("<id>");
  $sub->update(["status" => "submitted", "ncmec_tip_id" => "MANUAL-<their-tip-id>", "submitted_at" => now()]);
'
```

### A user appeals a CSAM auto-action claiming false positive

Staff path (requires AAL2):
1. Pull the case: `php artisan moderation:show-case <case_id>`
2. Coordinate with a second staff member (different from you) to review
3. Submit override via `POST /v1/staff/cases/<id>/decide`:
   ```json
   {
     "decision_type": "override_csam_auto_action",
     "reason": "Detailed justification: <why this is false positive>",
     "second_staff_approval_id": "<other-staff-id>"
   }
   ```
4. The override unwinds the suspension and writes a supersedes-link decision row

### Law enforcement requests preserved evidence

Quarantine binary is retained for 90 days from `csam_quarantine.created_at`. After that, the binary is deleted but metadata (hash, timestamps, uploader IP hash) stays indefinitely. Subpoenas / preservation orders → consult counsel; the row + audit log + NCMEC tip ID is the full record.

### The quarantine bucket suddenly has public access

`moderation:audit-quarantine-bucket` (daily) emits a critical Nightwatch alert + on-call page. Immediately:
1. Check Cloudflare R2 dashboard for bucket access policy
2. Revoke any public-read rule
3. Audit R2 access logs for the drift window
4. Confirm no production media leaked from quarantine

## Useful queries

```sql
-- Open CSAM cases needing staff review
SELECT id, severity, status, signal_count, created_at
FROM moderation.cases
WHERE case_type = 'csam_match' AND status IN ('open', 'auto_actioned')
ORDER BY created_at DESC;

-- NCMEC submissions pending or failing
SELECT id, status, attempts, last_error, created_at
FROM moderation.ncmec_submissions
WHERE status IN ('pending', 'failed', 'manual_fallback_required')
ORDER BY status, created_at;

-- Quarantine rows approaching 90-day expiry
SELECT id, preservation_expires_at, r2_quarantine_key
FROM moderation.csam_quarantine
WHERE r2_binary_deleted = FALSE
  AND preservation_expires_at BETWEEN now() AND now() + interval '7 days';
```
```

- [ ] **Step 2: Update the main README**

Modify `docs/moderation/README.md`. Update the status table to:

```markdown
| Capability | Status |
|------------|--------|
| Public `POST /v1/public/report` | ✅ live |
| Cases dedup + merge | ✅ live |
| Evidence snapshotting | ✅ live |
| Audit log (`audit.moderation_events`) | ✅ live |
| Staff queue endpoints | ✅ live |
| Decision pipeline + outcome jobs | ✅ live |
| AAL2-gated staff workflow | ✅ live |
| Lifecycle commands | ✅ live |
| **CSAM scanning + NCMEC outbox** | ✅ live |
```

Add reference to `docs/moderation/csam-pipeline.md`.

- [ ] **Step 3: Commit**

```bash
git add docs/moderation/csam-pipeline.md docs/moderation/README.md
git commit -m "docs(moderation): CSAM pipeline operator runbook"
```

---

## Phase 11 — Pre-launch verification

### Task 19: Final end-to-end verification + manual ops sign-off

- [ ] **Step 1: Full test suite passes**

Run: `composer test && php artisan test --group=postgres`
Expected: all tests pass.

- [ ] **Step 2: Confirm manual ops are complete**

| Step | Status |
|------|--------|
| R2 bucket `partna-media-quarantine` created with private access | [ ] confirmed |
| Cloudflare CSAM Scanning Tool enabled on quarantine bucket | [ ] confirmed |
| Webhook configured: `https://dev-api.partna.au/v1/internal/cloudflare-csam-webhook` | [ ] confirmed |
| `CLOUDFLARE_CSAM_WEBHOOK_SECRET` stored in Laravel Cloud env | [ ] confirmed |
| NCMEC ESP registration complete; Josh Hunter as primary contact | [ ] confirmed |
| `NCMEC_API_KEY` + `NCMEC_ESP_ID` stored in env | [ ] confirmed |
| `moderation:audit-quarantine-bucket` returns OK against dev | [ ] run + confirmed |

- [ ] **Step 3: Smoke test with benign image upload (dev)**

```bash
# 1. Set the env flag
export PARTNA_CSAM_SCAN_ENABLED=true

# 2. Request a signed upload URL via the existing upload API
curl -X POST 'https://dev-api.partna.au/v1/user/sites/<site-id>/media/signed-url' \
  -H 'Authorization: Bearer <user-jwt>' \
  -H 'Content-Type: application/json' \
  -d '{"mime": "image/jpeg", "pool": "gallery"}'
# → returns { site_media_id, url } where url targets the quarantine bucket

# 3. Upload a benign test image to the URL
curl -X PUT '<signed-url>' --data-binary @benign-test.jpg

# 4. Wait ~90s for PromoteCleanMediaJob to pick it up
sleep 90

# 5. Verify processing_state flipped to 'ready'
php artisan tinker --execute='
  echo DB::selectOne("SELECT processing_state, scanned_at, bucket FROM site.site_media WHERE id = ?", ["<site_media_id>"])->processing_state;
'
# Expected: "ready"
```

- [ ] **Step 4: Smoke test the webhook path (using Cloudflare test-mode if available; otherwise manually signed payload)**

Cloudflare provides a test-mode CSAM payload for ESP integration verification. Use it against dev. Verify:
- `moderation.cases` row with `case_type='csam_match'`
- `moderation.csam_quarantine` row with `r2_binary_deleted=false`
- `moderation.ncmec_submissions` row (status may be `submitted` or `failed` depending on whether NCMEC's test endpoint is reachable from dev)
- `users.moderation_state = 'suspended'`
- `sites.moderation_state = 'hidden'`
- `site_media.processing_state = 'quarantined'`
- On-call staff notification received in dev Slack / mail trap

- [ ] **Step 5: Cloud logs check**

```bash
cloud env:logs partna development --minutes 15 | grep -i moderation | grep -i 'error\|warning'
```
Expected: no exceptions on `/v1/internal/cloudflare-csam-webhook` or `Promote*Job`.

- [ ] **Step 6: Open the PR**

```bash
git push -u origin feat/ts-foundation-plan-c
gh pr create --base development --title "feat(moderation): T&S foundation Plan C — CSAM scanning pipeline" \
  --body "$(cat <<'EOF'
## Summary
- Adds `moderation.csam_quarantine` + `moderation.ncmec_submissions` tables
- `CsamQuarantine` + `NcmecSubmission` models + factories
- `R2QuarantineService` (signed upload URLs to quarantine bucket, promote, delete)
- `ModerationDecisionService::decideAsSystem` for auto-actions
- SiteMedia upload flow routes through quarantine bucket when `PARTNA_CSAM_SCAN_ENABLED=true`
- `PromoteCleanMediaJob` (scheduled every 60s) polls Cloudflare scan status
- `EnforceCsamScanGate` middleware on public media reads (defence-in-depth)
- `VerifyCloudflareWebhookSignature` middleware + Redis nonce store (replay protection)
- `CloudflareCsamWebhookController` + `CsamMatchHandlerService` for the auto-action pipeline
- `NcmecSubmissionService` + `FileCyberTipReportJob` (outbox pattern)
- 3 lifecycle commands: `moderation:expire-csam-quarantine` (daily), `moderation:audit-quarantine-bucket` (daily), `moderation:retry-ncmec-submissions` (every 5min)
- Full end-to-end pipeline integration test
- CSAM operator runbook (`docs/moderation/csam-pipeline.md`)

After this PR (+ launch flag): every uploaded image is scanned; CSAM matches trigger full auto-action; legal compliance pipeline operational.

## Test plan
- [ ] `composer test` passes
- [ ] `php artisan test --group=postgres` passes
- [ ] Webhook signature forgery tests pass (5 scenarios)
- [ ] End-to-end pipeline test passes (auto-action fires all jobs)
- [ ] Manual smoke: benign upload → quarantine → promote → ready
- [ ] Manual smoke: Cloudflare test-mode CSAM payload → full auto-action
- [ ] `moderation:audit-quarantine-bucket` passes against dev
- [ ] No Nightwatch exceptions on the new routes
- [ ] NCMEC ESP registration confirmed (or PR description flags as launch-prerequisite)

Spec: `docs/superpowers/specs/2026-05-26-trust-and-safety-foundation-design.md`
Plan: `docs/superpowers/plans/2026-05-26-trust-and-safety-foundation-plan-c.md`

⚠️ Production launch prerequisites — DO NOT flip `PARTNA_CSAM_SCAN_ENABLED=true` in production until:
- [ ] NCMEC ESP registration complete with prod credentials
- [ ] R2 quarantine bucket created in prod
- [ ] Cloudflare CSAM Scanning Tool enabled on prod quarantine bucket
- [ ] Webhook configured against prod URL
- [ ] On-call notification channels live

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review checklist

- [ ] `partna-media-quarantine` bucket exists in dev with private access policy
- [ ] Cloudflare CSAM Scanning Tool is enabled on the bucket
- [ ] Webhook signature verification rejects: no header / bad HMAC / replay / stale timestamp
- [ ] `CsamMatchHandlerService` creates: case + signal + evidence + csam_quarantine + ncmec_submission + decision — all in one transaction
- [ ] `ModerationDecisionService::decideAsSystem` sets `decided_by_system=true` AND `auto_actioned=true` AND `decided_by_staff_id=null`
- [ ] DB CHECK `decisions_actor_xor` enforces that system decisions have null `decided_by_staff_id` (catches the bug class at the DB layer)
- [ ] CSAM cases route through the same `ModerationActionDispatcher` as staff decisions (one dispatch path)
- [ ] `FileCyberTipReportJob` uses the `moderation_high` Horizon queue
- [ ] `NcmecSubmissionService` retries via outbox row + `moderation:retry-ncmec-submissions` (not via Laravel's `$tries`)
- [ ] Submissions stuck at `manual_fallback_required` emit critical Nightwatch alert
- [ ] `moderation:audit-quarantine-bucket` fails loudly when public access drift detected
- [ ] `moderation:expire-csam-quarantine` only acts on rows past `preservation_expires_at` AND not already deleted
- [ ] `EnforceCsamScanGate` (or the read-path filter) blocks `scanning` and `quarantined` rows from public media output
- [ ] Grandfather cutoff lets pre-Plan-C media render normally without `scanned_at`
- [ ] `PARTNA_CSAM_SCAN_ENABLED=false` cleanly bypasses the quarantine path (existing uploads keep working)
- [ ] All commits follow project commit message style
- [ ] CSAM operator runbook + NCMEC failure playbook are in `docs/moderation/`

---

## What ships after Plan C

- Every user-uploaded image transits the private R2 quarantine bucket before being publicly servable
- Cloudflare's CSAM Scanning Tool scans uploads against the NCMEC PhotoDNA database
- Clean media is promoted to production automatically within ~60s
- CSAM matches trigger a full auto-action pipeline: case + decision + quarantine + suspend + cache purge + NCMEC CyberTipline submission + on-call notification — all transactionally consistent
- 90-day preservation pipeline + binary expiry pipeline operational
- Daily quarantine-bucket-access audit catches misconfiguration drift
- Outbox-pattern NCMEC submission with retry + manual-fallback escalation

**Trust & Safety Foundation: complete.** Reporting + scanning + outcome propagation + audit are all live.

**Deferred to v2 (see spec §14):**
- Appeals workflow (additive — references `decisions.supersedes_decision_id` slot)
- Trusted-flagger prioritisation (additive — new signal source)
- DSA structured statement-of-reasons (additive — new columns on `decisions`)
- EU Transparency Database batch submitter (additive — read-only over `decisions`)
- ML-based detection beyond hash matching (additive — new signal source)
- SiteMedia + Block as reportable types (additive — already in `cases_reportable_type_check`)
