# Trust & Safety Foundation — Plan B: Staff Workflow + Outcome Propagation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the reporting workflow fully operational end-to-end. After this plan: staff can log in (AAL2), see a queue, triage/take/decide on cases, and outcomes propagate through the system (suspend, hide, notify reporter + reported user, purge edge cache). Lifecycle commands (SLA scan, PII redaction, decision reversal, case show) are operational. Capability gates wired into every dispatcher.

**Architecture:** Decision-driven outcome dispatch. `ModerationDecisionService::decide()` is the only legal path to write a `decisions` row; it inserts a `decisions` row + N `action_log` rows in one transaction, then dispatches jobs `afterCommit`. The dispatcher maps each `decision_type` to the appropriate set of jobs. Staff endpoints are thin controllers calling `ModerationCaseService`/`ModerationDecisionService` and returning `CaseResource`/`CaseDetailResource`. AAL2 enforced via existing `require.aal2` middleware. Capability gates checked in every job dispatcher (fail-closed).

**Tech Stack:** Laravel 12 (PHP 8.2), Supabase PostgreSQL, Pest 4, Redis, Laravel Horizon, existing `SyncSubdomainToKvJob` + `CloudflarePurgeService`.

**Spec:** `docs/superpowers/specs/2026-05-26-trust-and-safety-foundation-design.md`

**Depends on:** Plan A (`docs/superpowers/plans/2026-05-26-trust-and-safety-foundation-plan-a.md`) — schema, models, policies, state machine, audit service, content report submit path must be in place.

**Companion plan to follow:** Plan C — CSAM scanning pipeline. Independent of Plan B's runtime but shares the dispatcher this plan builds (`QuarantineMediaJob` is wired here; Plan C's `CsamMatchHandlerService` uses the same `ModerationDecisionService::decide()` entry point for auto-actions).

---

## Pre-flight checklist

- [ ] `git fetch origin && git checkout development && git pull origin development` — pull latest before branching
- [ ] Plan A merged into `development` (this plan depends on Plan A's models, schemas, factories, services)
- [ ] Switch to feature branch off the latest tip: `git checkout -b feat/ts-foundation-plan-b`
- [ ] `composer test` passes (Plan A baseline)
- [ ] Local Supabase running with Plan A migrations applied
- [ ] AAL2 staff-route setup is functional (see `docs/auth/mfa-foundation.md`)

## Codebase-state assumptions (verified against origin/development on 2026-05-27)

- `App\Models\Core\User\User` is the User model (not `Core\Professional\User`).
- `App\Models\Core\Site\Site` is the Site model with `user()` relation (FK column is `user_id`, renamed from `professional_id` in mig `20260527030000`).
- `App\Models\Core\Staff\PartnaStaff` has columns `id`, `auth_user_id`, `role`, `primary_email`, `name`, `phone`. **No `is_active` / `is_on_call`** — all `role='admin'` rows are treated as on-call.
- `App\Services\Accounts\AccountCapabilities::for($user)` returns a `final readonly AccountCapabilitySet` value object with constructor-injected named properties. **No `->can(string)` method** — Task 14 extends the constructor.
- `core.users.status` enum (`active` | `suspended` | `disabled` | `pending_deletion`) is what suspend/ban decisions update. No `moderation_state` column on users.
- `site.sites.moderation_state` IS added (in Plan A Task 3) — distinct from `is_published`.
- `actingAsUser($user, $claims)` and `aal2ClaimsWithFreshTotp()` already exist in `tests/Pest.php`. This plan adds a sibling `actingAsStaff($staff, $claims)` in Task 17 (before the first staff endpoint test).
- The `audit` schema already exists (created by mig `20260527010000_reorganize_schemas.sql`).

---

## File structure (what gets created or modified)

```
app/Services/Moderation/
  ModerationCaseService.php
  ModerationDecisionService.php
  ModerationActionDispatcher.php

app/DTOs/Moderation/
  TriageDto.php
  DecisionDto.php
  EscalationDto.php

app/Http/Controllers/Api/Staff/
  StaffCaseController.php

app/Http/Requests/Staff/
  TriageCaseRequest.php
  DecideOnCaseRequest.php
  EscalateCaseRequest.php

app/Http/Resources/Moderation/
  CaseResource.php
  CaseDetailResource.php
  CaseSignalResource.php
  EvidenceResource.php
  DecisionResource.php
  AuditEventResource.php

app/Jobs/Moderation/
  SuspendUserJob.php
  SuspendSiteJob.php
  QuarantineMediaJob.php
  NotifyReportedUserJob.php
  NotifyReporterJob.php
  NotifyOnCallStaffJob.php
  PurgeModerationCacheJob.php

app/Notifications/Moderation/
  ContentHiddenNotification.php
  AccountSuspendedNotification.php
  AccountBannedNotification.php
  ReportOutcomeNotification.php
  CsamAutoActionStaffNotification.php
  CaseEscalatedStaffNotification.php

app/Console/Commands/Moderation/
  ModerationSlaScanCommand.php
  ModerationRedactReporterPiiCommand.php
  ModerationShowCaseCommand.php
  ModerationReverseDecisionCommand.php

app/Services/Accounts/AccountCapabilities.php          (extend individualCapabilities)
app/Console/Kernel.php                                  (schedule sla-scan)
config/horizon.php                                      (add moderation_high lane)
routes/api/staff.php                                    (add /v1/staff/cases routes)

resources/views/mail/moderation/
  content-hidden.blade.php
  account-suspended.blade.php
  account-banned.blade.php
  report-outcome.blade.php
  csam-auto-action.blade.php
  case-escalated.blade.php

tests/Unit/Services/Moderation/
  ModerationCaseServiceTest.php
  ModerationDecisionServiceTest.php
  ModerationActionDispatcherTest.php

tests/Feature/Moderation/
  StaffCaseIndexTest.php
  StaffCaseShowTest.php
  StaffCaseTriageTest.php
  StaffCaseTakeReleaseTest.php
  StaffCaseDecideTest.php
  StaffCaseEscalateTest.php
  CsamOverrideTwoStaffEnforcementTest.php
  OutcomeJobsTest.php
  CapabilityGatedDispatcherTest.php

tests/Feature/Commands/Moderation/
  ModerationSlaScanCommandTest.php
  ModerationRedactReporterPiiCommandTest.php
  ModerationShowCaseCommandTest.php
  ModerationReverseDecisionCommandTest.php

tests/Feature/Security/
  ModerationAal2EnforcementTest.php
  StaffCaseAuthorizationTest.php

docs/moderation/
  README.md                                             (update operator runbook)
  staff-workflow.md                                     (new)
```

---

## Phase 1 — Case lifecycle service

### Task 1: `ModerationCaseService::triage`

**Files:**
- Create: `app/Services/Moderation/ModerationCaseService.php`
- Create: `app/DTOs/Moderation/TriageDto.php`
- Create: `tests/Unit/Services/Moderation/ModerationCaseServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Moderation/ModerationCaseServiceTest.php`:

```php
<?php

use App\DTOs\Moderation\TriageDto;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\AuditEvent;
use App\Models\Moderation\ModerationCase;
use App\Services\Moderation\CaseStateMachine;
use App\Services\Moderation\IllegalCaseTransition;
use App\Services\Moderation\ModerationCaseService;

it('triages an open case → triaged + records audit + updates priority', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create(['status' => 'open', 'priority' => 5]);
    $dto   = new TriageDto(priority: 2, notes: 'high signal volume');

    $updated = app(ModerationCaseService::class)->triage($case, $staff, $dto);

    expect($updated->status)->toBe('triaged');
    expect($updated->priority)->toBe(2);
    expect($updated->triaged_by_staff_id)->toBe($staff->id);
    expect($updated->triaged_at)->not->toBeNull();

    $audit = AuditEvent::query()->where('action', 'case.triaged')->latest('created_at')->first();
    expect($audit->actor_staff_id)->toBe($staff->id);
    expect($audit->target_id)->toBe($case->id);
});

it('refuses to triage a resolved case', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->resolved()->create();
    $dto   = new TriageDto(priority: null, notes: null);

    expect(fn () => app(ModerationCaseService::class)->triage($case, $staff, $dto))
        ->toThrow(IllegalCaseTransition::class);
});

it('preserves existing priority when dto.priority is null', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create(['status' => 'open', 'priority' => 3]);
    $dto   = new TriageDto(priority: null, notes: null);

    $updated = app(ModerationCaseService::class)->triage($case, $staff, $dto);
    expect($updated->priority)->toBe(3);
});
```

- [ ] **Step 2: Run to fail**

Run: `php artisan test tests/Unit/Services/Moderation/ModerationCaseServiceTest.php`
Expected: FAIL.

- [ ] **Step 3: Create the DTO**

Create `app/DTOs/Moderation/TriageDto.php`:

```php
<?php

namespace App\DTOs\Moderation;

final class TriageDto
{
    public function __construct(
        public readonly ?int $priority,
        public readonly ?string $notes,
    ) {}
}
```

- [ ] **Step 4: Create the service**

Create `app/Services/Moderation/ModerationCaseService.php`:

```php
<?php

namespace App\Services\Moderation;

use App\DTOs\Moderation\TriageDto;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\DB;

/**
 * Case-side mutations: triage, take, release.
 * Decisions live in ModerationDecisionService.
 */
class ModerationCaseService
{
    public function __construct(
        private readonly CaseStateMachine $sm,
        private readonly ModerationAuditService $audit,
    ) {}

    public function triage(ModerationCase $case, PartnaStaff $staff, TriageDto $dto): ModerationCase
    {
        return DB::transaction(function () use ($case, $staff, $dto) {
            $this->sm->transition($case, 'triaged');

            $case->triaged_by_staff_id = $staff->id;
            $case->triaged_at = now();
            if ($dto->priority !== null) {
                $case->priority = $dto->priority;
            }
            $case->save();

            $this->audit->recordStaffAction(
                $staff,
                'case.triaged',
                'ModerationCase',
                $case->id,
                ['notes' => $dto->notes, 'priority' => $case->priority],
            );

            return $case;
        });
    }
}
```

- [ ] **Step 5: Run + commit**

Run: `php artisan test tests/Unit/Services/Moderation/ModerationCaseServiceTest.php`
Expected: PASS.

```bash
git add app/DTOs/Moderation/TriageDto.php app/Services/Moderation/ModerationCaseService.php tests/Unit/Services/Moderation/ModerationCaseServiceTest.php
git commit -m "feat(moderation): ModerationCaseService::triage with audit"
```

---

### Task 2: `ModerationCaseService::take` + `release`

**Files:**
- Modify: `app/Services/Moderation/ModerationCaseService.php` (add methods)
- Modify: `tests/Unit/Services/Moderation/ModerationCaseServiceTest.php` (append)

- [ ] **Step 1: Append failing tests**

Append to `tests/Unit/Services/Moderation/ModerationCaseServiceTest.php`:

```php
it('takes a triaged case (triaged → under_review) and records audit', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->triaged()->create();

    $updated = app(ModerationCaseService::class)->take($case, $staff);

    expect($updated->status)->toBe('under_review');
    expect(AuditEvent::query()->where('action', 'case.taken')->exists())->toBeTrue();
});

it('releases a taken case (under_review → triaged) and records audit', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->underReview()->create();

    $updated = app(ModerationCaseService::class)->release($case, $staff);

    expect($updated->status)->toBe('triaged');
    expect(AuditEvent::query()->where('action', 'case.released')->exists())->toBeTrue();
});

it('rejects take when case is not in triaged status (race)', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->resolved()->create();

    expect(fn () => app(ModerationCaseService::class)->take($case, $staff))
        ->toThrow(IllegalCaseTransition::class);
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL — methods missing.

- [ ] **Step 3: Add the methods to `ModerationCaseService`**

Append to `app/Services/Moderation/ModerationCaseService.php`:

```php
public function take(ModerationCase $case, PartnaStaff $staff): ModerationCase
{
    return DB::transaction(function () use ($case, $staff) {
        $this->sm->transition($case, 'under_review');
        $case->save();

        $this->audit->recordStaffAction(
            $staff, 'case.taken', 'ModerationCase', $case->id, []
        );

        return $case;
    });
}

public function release(ModerationCase $case, PartnaStaff $staff): ModerationCase
{
    return DB::transaction(function () use ($case, $staff) {
        $this->sm->transition($case, 'triaged');
        $case->save();

        $this->audit->recordStaffAction(
            $staff, 'case.released', 'ModerationCase', $case->id, []
        );

        return $case;
    });
}
```

- [ ] **Step 4: Run + commit**

```bash
php artisan test tests/Unit/Services/Moderation/ModerationCaseServiceTest.php
git add app/Services/Moderation/ModerationCaseService.php tests/Unit/Services/Moderation/ModerationCaseServiceTest.php
git commit -m "feat(moderation): ModerationCaseService::take + release"
```

---

## Phase 2 — Decision service + action dispatcher

### Task 3: `DecisionDto`

**Files:**
- Create: `app/DTOs/Moderation/DecisionDto.php`
- Create: `tests/Unit/DTOs/Moderation/DecisionDtoTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/DTOs/Moderation/DecisionDtoTest.php`:

```php
<?php

use App\DTOs\Moderation\DecisionDto;

it('exposes typed decision fields', function () {
    $dto = new DecisionDto(
        decisionType:           'hide_site',
        reason:                 'repeated spam',
        secondStaffApprovalId:  null,
    );
    expect($dto->decisionType)->toBe('hide_site');
    expect($dto->reason)->toBe('repeated spam');
    expect($dto->secondStaffApprovalId)->toBeNull();
});

it('captures the CSAM override two-staff approver id', function () {
    $dto = new DecisionDto(
        decisionType:           'override_csam_auto_action',
        reason:                 'confirmed false positive',
        secondStaffApprovalId:  '11111111-1111-1111-1111-111111111111',
    );
    expect($dto->secondStaffApprovalId)->toBe('11111111-1111-1111-1111-111111111111');
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create the DTO**

Create `app/DTOs/Moderation/DecisionDto.php`:

```php
<?php

namespace App\DTOs\Moderation;

final class DecisionDto
{
    public function __construct(
        public readonly string $decisionType,
        public readonly string $reason,
        public readonly ?string $secondStaffApprovalId,
    ) {}
}
```

- [ ] **Step 4: Run + commit**

```bash
php artisan test tests/Unit/DTOs/Moderation/DecisionDtoTest.php
git add app/DTOs/Moderation/DecisionDto.php tests/Unit/DTOs/Moderation/DecisionDtoTest.php
git commit -m "feat(moderation): add DecisionDto"
```

---

### Task 4: `ModerationActionDispatcher` (decision_type → action mapping)

**Files:**
- Create: `app/Services/Moderation/ModerationActionDispatcher.php`
- Create: `tests/Unit/Services/Moderation/ModerationActionDispatcherTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Moderation/ModerationActionDispatcherTest.php`:

```php
<?php

use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Services\Moderation\ModerationActionDispatcher;

it('writes action_log rows for hide_site (suspend_site + sync_subdomain_kv + notify_reported_user)', function () {
    $decision = Decision::factory()->create(['decision_type' => 'hide_site']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    $types = ActionLogEntry::query()->where('decision_id', $decision->id)->pluck('action_type')->all();
    expect($types)->toContain('suspend_site', 'sync_subdomain_kv', 'notify_reported_user');
});

it('writes action_log rows for suspend_user (suspend_user + suspend_site + sync_subdomain_kv + notify_reported_user)', function () {
    $decision = Decision::factory()->create(['decision_type' => 'suspend_user']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    $types = ActionLogEntry::query()->where('decision_id', $decision->id)->pluck('action_type')->all();
    expect($types)->toContain('suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_reported_user');
});

it('does not include notify_reporter when the case has no reporter_email', function () {
    $decision = Decision::factory()->create(['decision_type' => 'warn']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    $types = ActionLogEntry::query()->where('decision_id', $decision->id)->pluck('action_type')->all();
    expect($types)->not->toContain('notify_reporter');
});

it('emits no actions for dismiss decision', function () {
    $decision = Decision::factory()->create(['decision_type' => 'dismiss']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    expect(ActionLogEntry::query()->where('decision_id', $decision->id)->count())->toBe(0);
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create the dispatcher**

Create `app/Services/Moderation/ModerationActionDispatcher.php`:

```php
<?php

namespace App\Services\Moderation;

use App\Jobs\Moderation\NotifyOnCallStaffJob;
use App\Jobs\Moderation\NotifyReportedUserJob;
use App\Jobs\Moderation\NotifyReporterJob;
use App\Jobs\Moderation\PurgeModerationCacheJob;
use App\Jobs\Moderation\SuspendSiteJob;
use App\Jobs\Moderation\SuspendUserJob;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\Decision;
use Illuminate\Support\Facades\DB;

/**
 * Translates a decision_type into the set of action_log rows + Horizon job dispatches.
 *
 * Action rows are written inside the transaction that created the decision (callers wrap).
 * Jobs are dispatched afterCommit so they don't fire if the surrounding transaction rolls back.
 */
class ModerationActionDispatcher
{
    private const ACTIONS_BY_DECISION = [
        'dismiss'                   => [],
        'warn'                      => ['notify_reported_user'],
        'hide_content'              => ['notify_reported_user', 'purge_cloudflare_cache'],
        'hide_site'                 => ['suspend_site', 'sync_subdomain_kv', 'notify_reported_user'],
        'suspend_user'              => ['suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_reported_user'],
        'ban_user'                  => ['suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_reported_user'],
        'override_csam_auto_action' => ['notify_oncall_staff'],
        'escalate_law_enforcement'  => ['notify_oncall_staff'],
        'escalate_esafety'          => ['notify_oncall_staff'],
    ];

    public function dispatchFor(Decision $decision): void
    {
        $actionTypes = self::ACTIONS_BY_DECISION[$decision->decision_type] ?? [];

        $caseSignals = CaseSignal::query()->where('case_id', $decision->case_id)->get();
        $hasReporterEmail = $caseSignals->whereNotNull('reporter_email')->isNotEmpty();
        if ($hasReporterEmail) {
            $actionTypes[] = 'notify_reporter';
        }

        $rows = collect($actionTypes)->map(fn (string $type) => ActionLogEntry::create([
            'decision_id'   => $decision->id,
            'action_type'   => $type,
            'action_target' => ['case_id' => $decision->case_id],
            'status'        => 'pending',
        ]));

        DB::afterCommit(function () use ($rows, $decision) {
            foreach ($rows as $row) {
                $this->dispatchJob($row->id, $row->action_type, $decision->case_id);
            }
        });
    }

    private function dispatchJob(string $actionLogId, string $type, string $caseId): void
    {
        match ($type) {
            'suspend_user'         => SuspendUserJob::dispatch($actionLogId, $caseId),
            'suspend_site'         => SuspendSiteJob::dispatch($actionLogId, $caseId),
            'sync_subdomain_kv'    => PurgeModerationCacheJob::dispatch($actionLogId, $caseId),
            'purge_cloudflare_cache' => PurgeModerationCacheJob::dispatch($actionLogId, $caseId),
            'notify_reported_user' => NotifyReportedUserJob::dispatch($actionLogId, $caseId),
            'notify_reporter'      => NotifyReporterJob::dispatch($actionLogId, $caseId),
            'notify_oncall_staff'  => NotifyOnCallStaffJob::dispatch($actionLogId, $caseId),
        };
    }
}
```

- [ ] **Step 4: Create stub jobs (real implementations in later tasks)**

Create `app/Jobs/Moderation/SuspendUserJob.php`:

```php
<?php

namespace App\Jobs\Moderation;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SuspendUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $actionLogId, public readonly string $caseId) {}

    public function handle(): void
    {
        // Implemented in Task 7.
    }
}
```

Create identical stubs for `SuspendSiteJob.php`, `PurgeModerationCacheJob.php`, `NotifyReportedUserJob.php`, `NotifyReporterJob.php`, `NotifyOnCallStaffJob.php` — each with `actionLogId` + `caseId` constructor args and empty `handle()`.

- [ ] **Step 5: Run + commit**

```bash
composer dump-autoload
php artisan test tests/Unit/Services/Moderation/ModerationActionDispatcherTest.php
git add app/Services/Moderation/ModerationActionDispatcher.php app/Jobs/Moderation/ tests/Unit/Services/Moderation/ModerationActionDispatcherTest.php
git commit -m "feat(moderation): ModerationActionDispatcher + outcome job stubs"
```

---

### Task 5: `ModerationDecisionService::decide`

**Files:**
- Create: `app/Services/Moderation/ModerationDecisionService.php`
- Create: `tests/Unit/Services/Moderation/ModerationDecisionServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Moderation/ModerationDecisionServiceTest.php`:

```php
<?php

use App\DTOs\Moderation\DecisionDto;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\AuditEvent;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Services\Moderation\ModerationDecisionService;
use Illuminate\Support\Facades\Queue;

it('writes a decision + dispatches actions + transitions case to resolved', function () {
    Queue::fake();
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->underReview()->create();
    $dto   = new DecisionDto('hide_site', 'repeated spam', null);

    $decision = app(ModerationDecisionService::class)->decide($case, $staff, $dto);

    expect($decision)->toBeInstanceOf(Decision::class);
    expect($decision->decision_type)->toBe('hide_site');
    expect($decision->decided_by_staff_id)->toBe($staff->id);

    expect(ActionLogEntry::query()->where('decision_id', $decision->id)->count())->toBeGreaterThan(0);

    $case->refresh();
    expect($case->status)->toBe('resolved');

    expect(AuditEvent::query()->where('action', 'case.decided')->exists())->toBeTrue();
});

it('rejects a CSAM override without second_staff_approval_id', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create(['status' => 'auto_actioned']);
    $dto   = new DecisionDto('override_csam_auto_action', 'false positive', null);

    expect(fn () => app(ModerationDecisionService::class)->decide($case, $staff, $dto))
        ->toThrow(\InvalidArgumentException::class, 'requires second_staff_approval_id');
});

it('rejects a CSAM override where second_staff equals deciding staff', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create(['status' => 'auto_actioned']);
    $dto   = new DecisionDto('override_csam_auto_action', 'fp', $staff->id);

    expect(fn () => app(ModerationDecisionService::class)->decide($case, $staff, $dto))
        ->toThrow(\InvalidArgumentException::class, 'second staff must differ');
});

it('records CSAM override with second_staff_approved_at timestamp', function () {
    Queue::fake();
    $staff1 = PartnaStaff::factory()->create();
    $staff2 = PartnaStaff::factory()->create();
    $case   = ModerationCase::factory()->create(['status' => 'auto_actioned']);
    $dto    = new DecisionDto('override_csam_auto_action', 'fp', $staff2->id);

    $decision = app(ModerationDecisionService::class)->decide($case, $staff1, $dto);

    expect($decision->second_staff_approval_id)->toBe($staff2->id);
    expect($decision->second_staff_approved_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create the service**

Create `app/Services/Moderation/ModerationDecisionService.php`:

```php
<?php

namespace App\Services\Moderation;

use App\DTOs\Moderation\DecisionDto;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

/**
 * The single legal write path for moderation.decisions.
 * Writes a decision row, dispatches the action set via ModerationActionDispatcher,
 * transitions the case to resolved, and records an audit row — all in one DB transaction.
 */
class ModerationDecisionService
{
    public function __construct(
        private readonly CaseStateMachine $sm,
        private readonly ModerationActionDispatcher $dispatcher,
        private readonly ModerationAuditService $audit,
    ) {}

    public function decide(ModerationCase $case, PartnaStaff $staff, DecisionDto $dto): Decision
    {
        $this->validateCsamOverride($dto, $staff);

        return DB::transaction(function () use ($case, $staff, $dto) {
            $decision = Decision::create([
                'case_id'                   => $case->id,
                'decision_type'             => $dto->decisionType,
                'reason'                    => $dto->reason,
                'decided_by_staff_id'       => $staff->id,
                'decided_by_system'         => false,
                'auto_actioned'             => false,
                'second_staff_approval_id'  => $dto->secondStaffApprovalId,
                'second_staff_approved_at'  => $dto->secondStaffApprovalId !== null ? now() : null,
            ]);

            $this->sm->transition($case, 'resolved');
            $case->resolved_at = now();
            $case->save();

            $this->dispatcher->dispatchFor($decision);

            $this->audit->recordStaffAction(
                $staff,
                'case.decided',
                'ModerationCase',
                $case->id,
                ['decision_type' => $dto->decisionType, 'decision_id' => $decision->id],
            );

            return $decision;
        });
    }

    private function validateCsamOverride(DecisionDto $dto, PartnaStaff $staff): void
    {
        if ($dto->decisionType !== 'override_csam_auto_action') {
            return;
        }

        if ($dto->secondStaffApprovalId === null) {
            throw new InvalidArgumentException(
                'CSAM override requires second_staff_approval_id'
            );
        }

        if ($dto->secondStaffApprovalId === $staff->id) {
            throw new InvalidArgumentException(
                'CSAM override second staff must differ from deciding staff'
            );
        }
    }
}
```

- [ ] **Step 4: Run + commit**

```bash
php artisan test tests/Unit/Services/Moderation/ModerationDecisionServiceTest.php
git add app/Services/Moderation/ModerationDecisionService.php tests/Unit/Services/Moderation/ModerationDecisionServiceTest.php
git commit -m "feat(moderation): ModerationDecisionService::decide with CSAM override guards"
```

---

## Phase 3 — Outcome jobs (full implementations)

### Task 6: `SuspendUserJob` real implementation

**Files:**
- Modify: `app/Jobs/Moderation/SuspendUserJob.php`
- Create: `tests/Feature/Moderation/SuspendUserJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/SuspendUserJobTest.php`:

```php
<?php

use App\Jobs\Moderation\SuspendUserJob;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Models\Core\Site\Site;

it('flips users.status to suspended and marks action_log completed', function () {
    $user = User::factory()->create(['status' => 'active']);
    $site = Site::factory()->for($user, 'user')->create();
    $case = ModerationCase::factory()->create([
        'reportable_type'          => 'Site',
        'reportable_id'            => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'suspend_user']);
    $entry    = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_user']);

    (new SuspendUserJob($entry->id, $case->id))->handle();

    expect($user->fresh()->status)->toBe('suspended');
    expect($entry->fresh()->status)->toBe('completed');
    expect($entry->fresh()->completed_at)->not->toBeNull();
});

it('is idempotent (running twice does not error)', function () {
    $user = User::factory()->create(['status' => 'suspended']);
    $site = Site::factory()->for($user, 'user')->create();
    $case = ModerationCase::factory()->create([
        'reportable_type'          => 'Site',
        'reportable_id'            => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'suspend_user']);
    $entry    = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_user']);

    (new SuspendUserJob($entry->id, $case->id))->handle();
    (new SuspendUserJob($entry->id, $case->id))->handle();

    expect($user->fresh()->status)->toBe('suspended');
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL — job stubbed.

- [ ] **Step 3: Implement the job**

Replace `app/Jobs/Moderation/SuspendUserJob.php`:

```php
<?php

namespace App\Jobs\Moderation;

use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SuspendUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $case = ModerationCase::query()->findOrFail($this->caseId);
            $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);

            $entry->update(['status' => 'dispatched', 'dispatched_at' => now(), 'attempts' => $entry->attempts + 1]);

            if ($case->reportable_owner_user_id !== null) {
                // Map decision_type to the existing core.users.status enum:
                //   suspend_user → 'suspended' (reversible by support)
                //   ban_user     → 'disabled'  (permanent — admin-only to reverse)
                $decision = $case->decisions()->latest('decided_at')->first();
                $newStatus = match ($decision?->decision_type) {
                    'ban_user' => 'disabled',
                    default    => 'suspended',  // suspend_user, override paths, csam auto-action
                };

                User::query()
                    ->where('id', $case->reportable_owner_user_id)
                    ->update(['status' => $newStatus]);
            }

            $entry->update(['status' => 'completed', 'completed_at' => now()]);
        });
    }
}
```

- [ ] **Step 4: Run + commit**

```bash
php artisan test tests/Feature/Moderation/SuspendUserJobTest.php
git add app/Jobs/Moderation/SuspendUserJob.php tests/Feature/Moderation/SuspendUserJobTest.php
git commit -m "feat(moderation): implement SuspendUserJob"
```

---

### Task 7: `SuspendSiteJob` real implementation

**Files:**
- Modify: `app/Jobs/Moderation/SuspendSiteJob.php`
- Create: `tests/Feature/Moderation/SuspendSiteJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/SuspendSiteJobTest.php`:

```php
<?php

use App\Jobs\Moderation\SuspendSiteJob;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Models\Core\Site\Site;

it('flips sites.moderation_state to hidden', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create(['moderation_state' => 'active']);
    $case = ModerationCase::factory()->create([
        'reportable_type'          => 'Site',
        'reportable_id'            => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry    = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_site']);

    (new SuspendSiteJob($entry->id, $case->id))->handle();

    expect($site->fresh()->moderation_state)->toBe('hidden');
    expect($entry->fresh()->status)->toBe('completed');
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Implement the job**

Replace `app/Jobs/Moderation/SuspendSiteJob.php`:

```php
<?php

namespace App\Jobs\Moderation;

use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use App\Models\Core\Site\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SuspendSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $case = ModerationCase::query()->findOrFail($this->caseId);
            $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);

            $entry->update(['status' => 'dispatched', 'dispatched_at' => now(), 'attempts' => $entry->attempts + 1]);

            if ($case->reportable_type === 'Site') {
                Site::query()->where('id', $case->reportable_id)
                    ->update(['moderation_state' => 'hidden']);
            }

            $entry->update(['status' => 'completed', 'completed_at' => now()]);
        });
    }
}
```

- [ ] **Step 4: Run + commit**

```bash
php artisan test tests/Feature/Moderation/SuspendSiteJobTest.php
git add app/Jobs/Moderation/SuspendSiteJob.php tests/Feature/Moderation/SuspendSiteJobTest.php
git commit -m "feat(moderation): implement SuspendSiteJob"
```

---

### Task 8: `QuarantineMediaJob` (called from Plan C, scaffolded here)

**Files:**
- Modify: `app/Jobs/Moderation/QuarantineMediaJob.php`
- Create: `tests/Feature/Moderation/QuarantineMediaJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/QuarantineMediaJobTest.php`:

```php
<?php

use App\Jobs\Moderation\QuarantineMediaJob;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('sets site_media.processing_state to quarantined', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $mediaId = Str::uuid()->toString();
    DB::insert(
        "INSERT INTO site.site_media (id, site_id, bucket, path, processing_state) VALUES (?, ?, 'public-assets', 'p.jpg', 'scanning')",
        [$mediaId, $site->id]
    );

    $case = ModerationCase::factory()->csamMatch()->create([
        'reportable_type' => 'SiteMedia',
        'reportable_id'   => $mediaId,
    ]);
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create();
    $entry    = ActionLogEntry::factory()->forDecision($decision)->create([
        'action_type'   => 'quarantine_media',
        'action_target' => ['site_media_id' => $mediaId],
    ]);

    (new QuarantineMediaJob($entry->id, $case->id))->handle();

    $row = DB::selectOne("SELECT processing_state FROM site.site_media WHERE id = ?", [$mediaId]);
    expect($row->processing_state)->toBe('quarantined');
})->group('postgres');
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Implement the job**

Create `app/Jobs/Moderation/QuarantineMediaJob.php`:

```php
<?php

namespace App\Jobs\Moderation;

use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class QuarantineMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $case  = ModerationCase::query()->findOrFail($this->caseId);
            $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);

            $entry->update(['status' => 'dispatched', 'dispatched_at' => now(), 'attempts' => $entry->attempts + 1]);

            $mediaId = $entry->action_target['site_media_id'] ?? $case->reportable_id;

            DB::update(
                "UPDATE site.site_media SET processing_state = 'quarantined' WHERE id = ?",
                [$mediaId]
            );

            $entry->update(['status' => 'completed', 'completed_at' => now()]);
        });
    }
}
```

- [ ] **Step 4: Run + commit**

```bash
php artisan test tests/Feature/Moderation/QuarantineMediaJobTest.php --group=postgres
git add app/Jobs/Moderation/QuarantineMediaJob.php tests/Feature/Moderation/QuarantineMediaJobTest.php
git commit -m "feat(moderation): implement QuarantineMediaJob"
```

---

### Task 9: `PurgeModerationCacheJob` (existing SyncSubdomainToKvJob integration)

**Files:**
- Modify: `app/Jobs/Moderation/PurgeModerationCacheJob.php`
- Create: `tests/Feature/Moderation/PurgeModerationCacheJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/PurgeModerationCacheJobTest.php`:

```php
<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Jobs\Moderation\PurgeModerationCacheJob;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\Bus;

it('dispatches SyncSubdomainToKvJob for the affected site', function () {
    Bus::fake();
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $case = ModerationCase::factory()->create([
        'reportable_type' => 'Site',
        'reportable_id'   => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'sync_subdomain_kv']);

    (new PurgeModerationCacheJob($entry->id, $case->id))->handle();

    Bus::assertDispatched(SyncSubdomainToKvJob::class);
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Implement the job**

Create `app/Jobs/Moderation/PurgeModerationCacheJob.php`:

```php
<?php

namespace App\Jobs\Moderation;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Bridges moderation decisions to the existing edge-cache machinery.
 * Reuses SyncSubdomainToKvJob — the canonical (and only) writer to SUBDOMAIN_KV.
 */
class PurgeModerationCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {}

    public function handle(): void
    {
        $case  = ModerationCase::query()->findOrFail($this->caseId);
        $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);

        $entry->update(['status' => 'dispatched', 'dispatched_at' => now(), 'attempts' => $entry->attempts + 1]);

        if ($case->reportable_owner_user_id !== null) {
            SyncSubdomainToKvJob::dispatch($case->reportable_owner_user_id);
        }

        $entry->update(['status' => 'completed', 'completed_at' => now()]);
    }
}
```

- [ ] **Step 4: Run + commit**

```bash
php artisan test tests/Feature/Moderation/PurgeModerationCacheJobTest.php
git add app/Jobs/Moderation/PurgeModerationCacheJob.php tests/Feature/Moderation/PurgeModerationCacheJobTest.php
git commit -m "feat(moderation): PurgeModerationCacheJob bridges to SyncSubdomainToKvJob"
```

---

## Phase 4 — Notifications + notification jobs

### Task 10: Notification classes

**Files:**
- Create: `app/Notifications/Moderation/ContentHiddenNotification.php`
- Create: `app/Notifications/Moderation/AccountSuspendedNotification.php`
- Create: `app/Notifications/Moderation/AccountBannedNotification.php`
- Create: `app/Notifications/Moderation/ReportOutcomeNotification.php`
- Create: `app/Notifications/Moderation/CsamAutoActionStaffNotification.php`
- Create: `app/Notifications/Moderation/CaseEscalatedStaffNotification.php`
- Create mail templates under `resources/views/mail/moderation/`

- [ ] **Step 1: Write a smoke test for one of them**

Create `tests/Unit/Notifications/Moderation/NotificationsSmokeTest.php`:

```php
<?php

use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\AccountSuspendedNotification;
use App\Notifications\Moderation\ContentHiddenNotification;
use App\Notifications\Moderation\ReportOutcomeNotification;

it('builds mail message for ContentHiddenNotification', function () {
    $case     = ModerationCase::factory()->create();
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site', 'reason' => 'spam']);

    $n = new ContentHiddenNotification($decision);
    $mail = $n->toMail(new \stdClass);
    expect($mail->subject)->toContain('hidden');
});

it('AccountSuspendedNotification mail message includes reason', function () {
    $case     = ModerationCase::factory()->create();
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'suspend_user', 'reason' => 'repeated violations']);

    $n = new AccountSuspendedNotification($decision);
    $mail = $n->toMail(new \stdClass);
    expect($mail->introLines)->not->toBeEmpty();
});

it('ReportOutcomeNotification mail message contains outcome key', function () {
    $case     = ModerationCase::factory()->create();
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site', 'reason' => 'spam']);

    $n = new ReportOutcomeNotification($decision);
    $array = $n->toArray(new \stdClass);
    expect($array)->toHaveKey('outcome');
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL — classes missing.

- [ ] **Step 3: Create `ContentHiddenNotification`**

Create `app/Notifications/Moderation/ContentHiddenNotification.php`:

```php
<?php

namespace App\Notifications\Moderation;

use App\Models\Moderation\Decision;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContentHiddenNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Decision $decision) {}

    public function via(object $notifiable): array { return ['mail', 'database']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Partna content has been hidden')
            ->view('mail.moderation.content-hidden', ['decision' => $this->decision]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'decision_id'   => $this->decision->id,
            'case_id'       => $this->decision->case_id,
            'decision_type' => $this->decision->decision_type,
            'reason'        => $this->decision->reason,
        ];
    }
}
```

- [ ] **Step 4: Create the other five notifications**

Create `app/Notifications/Moderation/AccountSuspendedNotification.php`:

```php
<?php

namespace App\Notifications\Moderation;

use App\Models\Moderation\Decision;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountSuspendedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Decision $decision) {}

    public function via(object $notifiable): array { return ['mail', 'database']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Partna account has been suspended')
            ->line('Your account has been suspended for the following reason:')
            ->line($this->decision->reason ?? 'A violation of our community standards.')
            ->line('You may submit an appeal through your account dashboard.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'decision_id'   => $this->decision->id,
            'reason'        => $this->decision->reason,
            'decided_at'    => $this->decision->decided_at,
        ];
    }
}
```

Create `app/Notifications/Moderation/AccountBannedNotification.php`:

```php
<?php

namespace App\Notifications\Moderation;

use App\Models\Moderation\Decision;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountBannedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Decision $decision) {}

    public function via(object $notifiable): array { return ['mail', 'database']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Partna account has been permanently closed')
            ->line('Your account has been permanently closed for the following reason:')
            ->line($this->decision->reason ?? 'A serious violation of our community standards.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'decision_id' => $this->decision->id,
            'reason'      => $this->decision->reason,
        ];
    }
}
```

Create `app/Notifications/Moderation/ReportOutcomeNotification.php`:

```php
<?php

namespace App\Notifications\Moderation;

use App\Models\Moderation\Decision;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportOutcomeNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Decision $decision) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $outcome = match ($this->decision->decision_type) {
            'dismiss'      => 'We reviewed your report and determined no action was needed.',
            'warn'         => 'We reviewed your report and warned the user.',
            'hide_content',
            'hide_site'    => 'We reviewed your report and removed the content.',
            'suspend_user',
            'ban_user'     => 'We reviewed your report and took action against the account.',
            default        => 'We reviewed your report.',
        };

        return (new MailMessage)
            ->subject('Update on the page you reported')
            ->line('Thank you for your report.')
            ->line($outcome);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'outcome'       => $this->decision->decision_type,
            'decision_id'   => $this->decision->id,
        ];
    }
}
```

Create `app/Notifications/Moderation/CsamAutoActionStaffNotification.php`:

```php
<?php

namespace App\Notifications\Moderation;

use App\Models\Moderation\ModerationCase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CsamAutoActionStaffNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly ModerationCase $case) {}

    public function via(object $notifiable): array { return ['mail', 'database']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Partna T&S] CSAM auto-action — review required')
            ->line("Case ID: {$this->case->id}")
            ->line('A CSAM hash match was detected. The user has been auto-suspended and the upload quarantined.')
            ->line('Please review the case and confirm the auto-action.')
            ->action('Review case', config('app.url') . "/staff/cases/{$this->case->id}");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'case_id'   => $this->case->id,
            'case_type' => $this->case->case_type,
        ];
    }
}
```

Create `app/Notifications/Moderation/CaseEscalatedStaffNotification.php`:

```php
<?php

namespace App\Notifications\Moderation;

use App\Models\Moderation\Decision;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CaseEscalatedStaffNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Decision $decision) {}

    public function via(object $notifiable): array { return ['mail', 'database']; }

    public function toMail(object $notifiable): MailMessage
    {
        $target = match ($this->decision->decision_type) {
            'escalate_law_enforcement' => 'law enforcement',
            'escalate_esafety'         => 'eSafety Commissioner',
            default                    => 'an external authority',
        };

        return (new MailMessage)
            ->subject("[Partna T&S] Escalation to {$target}")
            ->line("Case {$this->decision->case_id} has been escalated to {$target}.")
            ->line('Reason: ' . ($this->decision->reason ?? 'see audit log'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'decision_id' => $this->decision->id,
            'case_id'     => $this->decision->case_id,
        ];
    }
}
```

- [ ] **Step 5: Create the mail blade templates**

Create `resources/views/mail/moderation/content-hidden.blade.php`:

```blade
@component('mail::message')
# Your content has been hidden

We removed your content from public view for the following reason:

> {{ $decision->reason ?? 'A violation of our community standards.' }}

If you believe this was a mistake, you can submit an appeal from your account dashboard.

Thanks,
Partna Trust & Safety
@endcomponent
```

Create stub templates for `account-suspended.blade.php`, `account-banned.blade.php`, `report-outcome.blade.php`, `csam-auto-action.blade.php`, `case-escalated.blade.php` following the same pattern.

- [ ] **Step 6: Run + commit**

```bash
php artisan test tests/Unit/Notifications/Moderation/NotificationsSmokeTest.php
git add app/Notifications/Moderation/ resources/views/mail/moderation/ tests/Unit/Notifications/Moderation/
git commit -m "feat(moderation): notification classes + mail templates"
```

---

### Task 11: `NotifyReportedUserJob` — real implementation

**Files:**
- Modify: `app/Jobs/Moderation/NotifyReportedUserJob.php`
- Create: `tests/Feature/Moderation/NotifyReportedUserJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/NotifyReportedUserJobTest.php`:

```php
<?php

use App\Jobs\Moderation\NotifyReportedUserJob;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\AccountSuspendedNotification;
use App\Notifications\Moderation\ContentHiddenNotification;
use Illuminate\Support\Facades\Notification;

it('sends ContentHiddenNotification for hide_site decisions', function () {
    Notification::fake();
    $user = User::factory()->create();
    $case = ModerationCase::factory()->create([
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reported_user']);

    (new NotifyReportedUserJob($entry->id, $case->id))->handle();

    Notification::assertSentTo($user, ContentHiddenNotification::class);
});

it('sends AccountSuspendedNotification for suspend_user decisions', function () {
    Notification::fake();
    $user = User::factory()->create();
    $case = ModerationCase::factory()->create([
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'suspend_user']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reported_user']);

    (new NotifyReportedUserJob($entry->id, $case->id))->handle();

    Notification::assertSentTo($user, AccountSuspendedNotification::class);
});

it('skips notification when user capability denies it', function () {
    Notification::fake();
    $user = User::factory()->create(['status' => 'suspended']);  // capability returns false
    $case = ModerationCase::factory()->create([
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reported_user']);

    (new NotifyReportedUserJob($entry->id, $case->id))->handle();

    Notification::assertNothingSent();
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Implement the job**

Replace `app/Jobs/Moderation/NotifyReportedUserJob.php`:

```php
<?php

namespace App\Jobs\Moderation;

use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\AccountBannedNotification;
use App\Notifications\Moderation\AccountSuspendedNotification;
use App\Notifications\Moderation\ContentHiddenNotification;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyReportedUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {}

    public function handle(): void
    {
        $case  = ModerationCase::query()->findOrFail($this->caseId);
        $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);
        $entry->update(['status' => 'dispatched', 'dispatched_at' => now(), 'attempts' => $entry->attempts + 1]);

        if ($case->reportable_owner_user_id === null) {
            $entry->update(['status' => 'completed', 'completed_at' => now()]);
            return;
        }

        $user = User::query()->find($case->reportable_owner_user_id);
        if ($user === null) {
            $entry->update(['status' => 'completed', 'completed_at' => now()]);
            return;
        }

        if (! AccountCapabilities::for($user)->receive_moderation_notifications) {
            $entry->update(['status' => 'completed', 'completed_at' => now()]);
            return;
        }

        $decision = $case->decisions()->latest('decided_at')->firstOrFail();

        $notification = match ($decision->decision_type) {
            'hide_content', 'hide_site' => new ContentHiddenNotification($decision),
            'suspend_user'              => new AccountSuspendedNotification($decision),
            'ban_user'                  => new AccountBannedNotification($decision),
            default                     => null,
        };

        if ($notification !== null) {
            $user->notify($notification);
        }

        $entry->update(['status' => 'completed', 'completed_at' => now()]);
    }
}
```

- [ ] **Step 4: Run + commit**

```bash
php artisan test tests/Feature/Moderation/NotifyReportedUserJobTest.php
git add app/Jobs/Moderation/NotifyReportedUserJob.php tests/Feature/Moderation/NotifyReportedUserJobTest.php
git commit -m "feat(moderation): implement NotifyReportedUserJob with capability gate"
```

---

### Task 12: `NotifyReporterJob` real implementation

**Files:**
- Modify: `app/Jobs/Moderation/NotifyReporterJob.php`
- Create: `tests/Feature/Moderation/NotifyReporterJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/NotifyReporterJobTest.php`:

```php
<?php

use App\Jobs\Moderation\NotifyReporterJob;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\ReportOutcomeNotification;
use Illuminate\Support\Facades\Notification;

it('sends ReportOutcomeNotification to all reporters with reporter_email', function () {
    Notification::fake();
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => 'r1@example.com']);
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => 'r2@example.com']);
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => null]); // anonymous — skipped

    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reporter']);

    (new NotifyReporterJob($entry->id, $case->id))->handle();

    // Two notifications sent: one each to r1@ and r2@; none to anonymous
    Notification::assertCount(2);
});

it('skips when all reporters were anonymous', function () {
    Notification::fake();
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => null]);
    $decision = Decision::factory()->forCase($case)->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reporter']);

    (new NotifyReporterJob($entry->id, $case->id))->handle();

    Notification::assertNothingSent();
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Implement the job**

Replace `app/Jobs/Moderation/NotifyReporterJob.php`:

```php
<?php

namespace App\Jobs\Moderation;

use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\ReportOutcomeNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class NotifyReporterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {}

    public function handle(): void
    {
        $case  = ModerationCase::query()->findOrFail($this->caseId);
        $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);
        $entry->update(['status' => 'dispatched', 'dispatched_at' => now(), 'attempts' => $entry->attempts + 1]);

        $decision = $case->decisions()->latest('decided_at')->firstOrFail();

        $reporters = CaseSignal::query()
            ->where('case_id', $case->id)
            ->whereNotNull('reporter_email')
            ->pluck('reporter_email')
            ->unique();

        foreach ($reporters as $email) {
            Notification::route('mail', $email)->notify(new ReportOutcomeNotification($decision));
        }

        $entry->update(['status' => 'completed', 'completed_at' => now()]);
    }
}
```

- [ ] **Step 4: Run + commit**

```bash
php artisan test tests/Feature/Moderation/NotifyReporterJobTest.php
git add app/Jobs/Moderation/NotifyReporterJob.php tests/Feature/Moderation/NotifyReporterJobTest.php
git commit -m "feat(moderation): implement NotifyReporterJob (skip anonymous)"
```

---

### Task 13: `NotifyOnCallStaffJob` real implementation

**Files:**
- Modify: `app/Jobs/Moderation/NotifyOnCallStaffJob.php`
- Create: `tests/Feature/Moderation/NotifyOnCallStaffJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/NotifyOnCallStaffJobTest.php`:

```php
<?php

use App\Jobs\Moderation\NotifyOnCallStaffJob;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\CaseEscalatedStaffNotification;
use App\Notifications\Moderation\CsamAutoActionStaffNotification;
use Illuminate\Support\Facades\Notification;

it('notifies on-call staff with CsamAutoAction for csam_match cases', function () {
    Notification::fake();
    $staff = PartnaStaff::factory()->create(['role' => 'admin']);
    $case  = ModerationCase::factory()->csamMatch()->create();
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_oncall_staff']);

    (new NotifyOnCallStaffJob($entry->id, $case->id))->handle();

    Notification::assertSentTo($staff, CsamAutoActionStaffNotification::class);
});

it('notifies on-call staff with Escalated for escalate_law_enforcement', function () {
    Notification::fake();
    $staff = PartnaStaff::factory()->create(['role' => 'admin']);
    $case  = ModerationCase::factory()->create();
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'escalate_law_enforcement']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_oncall_staff']);

    (new NotifyOnCallStaffJob($entry->id, $case->id))->handle();

    Notification::assertSentTo($staff, CaseEscalatedStaffNotification::class);
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Implement the job**

Replace `app/Jobs/Moderation/NotifyOnCallStaffJob.php`:

```php
<?php

namespace App\Jobs\Moderation;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\CaseEscalatedStaffNotification;
use App\Notifications\Moderation\CsamAutoActionStaffNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class NotifyOnCallStaffJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {}

    public function handle(): void
    {
        $case  = ModerationCase::query()->findOrFail($this->caseId);
        $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);
        $entry->update(['status' => 'dispatched', 'dispatched_at' => now(), 'attempts' => $entry->attempts + 1]);

        // On-call routing: all admin staff are treated as on-call. The
        // PartnaStaff table has no is_on_call column today.
        $oncall = PartnaStaff::query()->where('role', 'admin')->get();
        if ($oncall->isEmpty()) {
            $entry->update(['status' => 'completed', 'completed_at' => now()]);
            return;
        }

        $latestDecision = $case->decisions()->latest('decided_at')->first();

        $notification = match (true) {
            $case->case_type === 'csam_match' => new CsamAutoActionStaffNotification($case),
            $latestDecision !== null && str_starts_with($latestDecision->decision_type, 'escalate_')
                => new CaseEscalatedStaffNotification($latestDecision),
            default => new CsamAutoActionStaffNotification($case),  // generic fallback
        };

        Notification::send($oncall, $notification);

        $entry->update(['status' => 'completed', 'completed_at' => now()]);
    }
}
```

- [ ] **Step 4: Run + commit**

```bash
php artisan test tests/Feature/Moderation/NotifyOnCallStaffJobTest.php
git add app/Jobs/Moderation/NotifyOnCallStaffJob.php tests/Feature/Moderation/NotifyOnCallStaffJobTest.php
git commit -m "feat(moderation): implement NotifyOnCallStaffJob"
```

---

## Phase 5 — Capabilities + config

### Task 14: `AccountCapabilities` additions

**Files:**
- Modify: `app/Services/Accounts/AccountCapabilities.php`
- Create: `tests/Feature/Moderation/AccountCapabilityModerationTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/AccountCapabilityModerationTest.php`:

```php
<?php

use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;

it('active user can receive moderation notifications', function () {
    $user = User::factory()->create(['status' => 'active']);
    expect(AccountCapabilities::for($user)->receive_moderation_notifications)->toBeTrue();
});

it('suspended user cannot receive moderation notifications', function () {
    $user = User::factory()->create(['status' => 'suspended']);
    expect(AccountCapabilities::for($user)->receive_moderation_notifications)->toBeFalse();
});

it('banned user cannot receive moderation notifications', function () {
    $user = User::factory()->create(['status' => 'disabled']);
    expect(AccountCapabilities::for($user)->receive_moderation_notifications)->toBeFalse();
});

it('active user can_be_reported', function () {
    $user = User::factory()->create(['status' => 'active']);
    expect(AccountCapabilities::for($user)->can_be_reported)->toBeTrue();
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Extend `AccountCapabilitySet`'s constructor**

`AccountCapabilitySet` is a `final readonly class` with constructor-injected named bool/string properties — there is no `->can(string)` method (we checked on origin/development). Add the two new capability properties to the constructor and update the factory in `AccountCapabilities::individualCapabilities()`.

Modify `app/Services/Accounts/AccountCapabilitySet.php`:

```php
<?php

namespace App\Services\Accounts;

final readonly class AccountCapabilitySet
{
    public function __construct(
        public bool $can_edit_design,
        public string $notification_categories,
        public string $worker_kv_type,
        public bool $can_submit_feedback,
        // NEW — moderation capabilities (Plan B, T&S foundation):
        public bool $can_be_reported,
        public bool $receive_moderation_notifications,
    ) {}
}
```

- [ ] **Step 4: Update `AccountCapabilities::individualCapabilities()` to pass the new args**

Modify `app/Services/Accounts/AccountCapabilities.php`:

```php
private static function individualCapabilities(User $pro): AccountCapabilitySet
{
    // status enum on core.users: 'active' | 'suspended' | 'disabled' | 'pending_deletion'.
    // Reports are accepted against any account that's currently active. Moderation
    // notifications are silenced for users we've already actioned (suspended/disabled).
    $status = $pro->status ?? 'active';

    return new AccountCapabilitySet(
        can_edit_design: true,
        notification_categories: 'profile,platform',
        worker_kv_type: 'individual',
        can_submit_feedback: true,
        can_be_reported: $status === 'active',
        receive_moderation_notifications: in_array($status, ['active'], true),
    );
}
```

If callers elsewhere construct an `AccountCapabilitySet` directly with positional args, they will now miss the two new fields and throw at the type checker. The factory pattern through `AccountCapabilities::for($user)` is the only sanctioned path — verify there are no direct constructions before this lands.

- [ ] **Step 5: Run + commit**

```bash
php artisan test tests/Feature/Moderation/AccountCapabilityModerationTest.php
git add app/Services/Accounts/AccountCapabilitySet.php app/Services/Accounts/AccountCapabilities.php tests/Feature/Moderation/AccountCapabilityModerationTest.php
git commit -m "feat(moderation): extend AccountCapabilitySet with can_be_reported + receive_moderation_notifications"
```

---

### Task 15: Horizon queue lane + config additions

**Files:**
- Modify: `config/horizon.php`
- Modify: `config/partna.php`

- [ ] **Step 1: Add the lane to Horizon**

Modify `config/horizon.php`. In each `environments` block (production, local, etc.), add a `moderation_high` supervisor entry:

```php
'moderation_high' => [
    'connection'    => 'redis',
    'queue'         => ['moderation_high'],
    'balance'       => 'auto',
    'minProcesses'  => 1,
    'maxProcesses'  => 3,
    'memory'        => 128,
    'tries'         => 3,
    'timeout'       => 60,
    'nice'          => 0,
],
```

- [ ] **Step 2: Extend `config/partna.php` with full moderation section**

Modify `config/partna.php`. Ensure the `moderation` block exists (started in Plan A); add the queue and auto-action settings:

```php
'moderation' => [
    'enabled'                     => env('PARTNA_MODERATION_ENABLED', true),
    'csam_scan_enabled'           => env('PARTNA_CSAM_SCAN_ENABLED', false),
    'auto_actions_enabled'        => env('PARTNA_MODERATION_AUTO_ACTIONS_ENABLED', true),
    'reporting' => [
        // ...established in Plan A
    ],
    'queue' => [
        'high_priority_lane' => env('PARTNA_MODERATION_HIGH_LANE', 'moderation_high'),
    ],
    'sla' => [
        'severity_5_hours'   => 1,
        'severity_4_hours'   => 4,
        'severity_3_hours'   => 24,
        'severity_2_hours'   => 72,
        'severity_1_hours'   => 168,
        'breach_warning_min' => 120, // alert when within 2h of breach
    ],
],
```

- [ ] **Step 3: Tag the high-priority jobs to use the lane**

Modify each of `FileCyberTipReportJob` (will be created in Plan C — leave for now), `NotifyOnCallStaffJob`, `SuspendUserJob` to add:

```php
public string $queue = 'moderation_high';

// OR via constructor:
public function __construct(...)
{
    $this->onQueue(config('partna.moderation.queue.high_priority_lane'));
    // ...
}
```

Apply to `NotifyOnCallStaffJob` and `SuspendUserJob` in this task; `FileCyberTipReportJob` happens in Plan C.

- [ ] **Step 4: Commit**

```bash
git add config/horizon.php config/partna.php app/Jobs/Moderation/NotifyOnCallStaffJob.php app/Jobs/Moderation/SuspendUserJob.php
git commit -m "feat(moderation): add moderation_high Horizon queue lane + config"
```

---

## Phase 6 — Staff endpoints

### Task 16: `CaseResource` + `CaseDetailResource`

**Files:**
- Create: `app/Http/Resources/Moderation/CaseResource.php`
- Create: `app/Http/Resources/Moderation/CaseDetailResource.php`
- Create: `app/Http/Resources/Moderation/CaseSignalResource.php`
- Create: `app/Http/Resources/Moderation/EvidenceResource.php`
- Create: `app/Http/Resources/Moderation/DecisionResource.php`
- Create: `tests/Unit/Http/Resources/Moderation/CaseResourceTest.php`

- [ ] **Step 1: Write the failing test (PII leakage focus)**

Create `tests/Unit/Http/Resources/Moderation/CaseResourceTest.php`:

```php
<?php

use App\Http\Resources\Moderation\CaseResource;
use App\Http\Resources\Moderation\CaseDetailResource;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\Evidence;
use App\Models\Moderation\ModerationCase;
use Illuminate\Http\Request;

it('CaseResource never exposes reporter_email', function () {
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => 'leak@example.com']);

    $array = (new CaseResource($case))->toArray(Request::create('/'));
    $json  = json_encode($array);
    expect($json)->not->toContain('leak@example.com');
});

it('CaseDetailResource includes signals + evidence + decisions but never raw reporter_email', function () {
    $case = ModerationCase::factory()->create();
    $signal = CaseSignal::factory()->forCase($case)->create(['reporter_email' => 'leak@example.com', 'reason_details' => 'visible']);
    Evidence::factory()->forCase($case)->create();

    $array = (new CaseDetailResource($case->load(['signals', 'evidence', 'decisions'])))
        ->toArray(Request::create('/'));
    $json = json_encode($array);

    expect($array)->toHaveKey('signals');
    expect($array)->toHaveKey('evidence');
    expect($json)->not->toContain('leak@example.com');
    expect($json)->toContain('visible');  // reason_details is safe
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create `CaseResource`**

Create `app/Http/Resources/Moderation/CaseResource.php`:

```php
<?php

namespace App\Http\Resources\Moderation;

use Illuminate\Http\Resources\Json\JsonResource;

class CaseResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                       => $this->id,
            'case_type'                => $this->case_type,
            'reportable_type'          => $this->reportable_type,
            'reportable_id'            => $this->reportable_id,
            'reportable_owner_user_id' => $this->reportable_owner_user_id,
            'severity'                 => $this->severity,
            'status'                   => $this->status,
            'signal_count'             => $this->signal_count,
            'priority'                 => $this->priority,
            'auto_actioned'            => $this->auto_actioned,
            'triaged_at'               => $this->triaged_at?->toIso8601String(),
            'resolved_at'              => $this->resolved_at?->toIso8601String(),
            'created_at'               => $this->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Create `CaseSignalResource`**

Create `app/Http/Resources/Moderation/CaseSignalResource.php`:

```php
<?php

namespace App\Http\Resources\Moderation;

use Illuminate\Http\Resources\Json\JsonResource;

class CaseSignalResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'signal_source'  => $this->signal_source,
            'reason_code'    => $this->reason_code,
            'reason_details' => $this->reason_details,
            'created_at'     => $this->created_at?->toIso8601String(),
            // NOTE: reporter_email and reporter_ip_hash never exposed
        ];
    }
}
```

- [ ] **Step 5: Create `EvidenceResource`**

Create `app/Http/Resources/Moderation/EvidenceResource.php`:

```php
<?php

namespace App\Http\Resources\Moderation;

use Illuminate\Http\Resources\Json\JsonResource;

class EvidenceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'evidence_type' => $this->evidence_type,
            'payload'       => $this->payload,
            'content_hash'  => $this->content_hash,
            'captured_at'   => $this->captured_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 6: Create `DecisionResource`**

Create `app/Http/Resources/Moderation/DecisionResource.php`:

```php
<?php

namespace App\Http\Resources\Moderation;

use Illuminate\Http\Resources\Json\JsonResource;

class DecisionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                       => $this->id,
            'decision_type'            => $this->decision_type,
            'reason'                   => $this->reason,
            'decided_by_staff_id'      => $this->decided_by_staff_id,
            'decided_by_system'        => $this->decided_by_system,
            'auto_actioned'            => $this->auto_actioned,
            'supersedes_decision_id'   => $this->supersedes_decision_id,
            'decided_at'               => $this->decided_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 7: Create `CaseDetailResource`**

Create `app/Http/Resources/Moderation/CaseDetailResource.php`:

```php
<?php

namespace App\Http\Resources\Moderation;

use Illuminate\Http\Resources\Json\JsonResource;

class CaseDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'case'      => (new CaseResource($this->resource))->toArray($request),
            'signals'   => CaseSignalResource::collection($this->whenLoaded('signals')),
            'evidence'  => EvidenceResource::collection($this->whenLoaded('evidence')),
            'decisions' => DecisionResource::collection($this->whenLoaded('decisions')),
        ];
    }
}
```

- [ ] **Step 8: Run + commit**

```bash
php artisan test tests/Unit/Http/Resources/Moderation/CaseResourceTest.php
git add app/Http/Resources/Moderation/ tests/Unit/Http/Resources/Moderation/
git commit -m "feat(moderation): CaseResource family with PII-safe serialisation"
```

---

### Task 17: `StaffCaseController::index` (list cases)

**Files:**
- Create: `app/Http/Controllers/Api/Staff/StaffCaseController.php`
- Modify: `routes/api/staff.php`
- Create: `tests/Feature/Moderation/StaffCaseIndexTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/StaffCaseIndexTest.php`:

```php
<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;

it('returns 401 for unauthenticated requests', function () {
    $this->getJson('/v1/staff/cases')->assertStatus(401);
});

it('returns 403 when staff is not AAL2', function () {
    // AAL2 enforcement happens via require.aal2 middleware — covered in Phase 9 tests
    $this->markTestIncomplete('Covered by ModerationAal2EnforcementTest');
});

it('lists cases sorted by severity DESC then priority ASC then created_at ASC', function () {
    $staff = PartnaStaff::factory()->create();
    ModerationCase::factory()->create(['severity' => 1, 'priority' => 5, 'status' => 'open']);
    $sev5  = ModerationCase::factory()->csamMatch()->create();
    ModerationCase::factory()->create(['severity' => 3, 'priority' => 2, 'status' => 'open']);

    $res = $this->actingAsStaff($staff)->getJson('/v1/staff/cases');
    $res->assertOk();

    $first = $res->json('data.0.id');
    expect($first)->toBe($sev5->id);
});

it('filters by status', function () {
    $staff = PartnaStaff::factory()->create();
    ModerationCase::factory()->resolved()->create();
    ModerationCase::factory()->create(['status' => 'open']);

    $res = $this->actingAsStaff($staff)->getJson('/v1/staff/cases?status=resolved');
    expect($res->json('data'))->toHaveCount(1);
});

it('filters by case_type', function () {
    $staff = PartnaStaff::factory()->create();
    ModerationCase::factory()->csamMatch()->create();
    ModerationCase::factory()->create(['case_type' => 'content_report']);

    $res = $this->actingAsStaff($staff)->getJson('/v1/staff/cases?case_type=csam_match');
    expect($res->json('data'))->toHaveCount(1);
});
```

You'll need a global Pest helper `actingAsStaff()`. Add to `tests/Pest.php` (alongside the existing `actingAsUser` + `aal2ClaimsWithFreshTotp` helpers — read those for the canonical pattern before writing this):

```php
/**
 * Authenticate the test HTTP client as a PartnaStaff member with AAL2 claims.
 * Stubs out VerifySupabaseJwt + EnsurePartnaStaff so route attributes are set
 * the same way the production middleware would set them.
 *
 * Mirrors actingAsUser() (tests/Pest.php). Pairs with aal2ClaimsWithFreshTotp()
 * when a staff route needs fresh-AAL2 instead of sticky-AAL2.
 */
function actingAsStaff(
    \App\Models\Core\Staff\PartnaStaff $staff,
    array $claims = [],
): \Pest\Support\HigherOrderTapProxy {
    $authUserId = $staff->auth_user_id ?? (string) \Illuminate\Support\Str::uuid();

    // Staff routes are gated by require.aal2 — default to AAL2 unless the test
    // overrides (e.g. to assert the gate rejects AAL1).
    $defaultClaims = array_merge([
        'sub'             => $authUserId,
        'email'           => $staff->primary_email ?? "staff-{$staff->id}@partna.au",
        'email_verified'  => true,
        'aal'             => 'aal2',
        'amr'             => [['method' => 'totp', 'timestamp' => time()]],
        'session_id'      => (string) \Illuminate\Support\Str::uuid(),
    ], $claims);

    // Stub VerifySupabaseJwt — same shape as actingAsUser uses.
    app()->bind(\App\Http\Middleware\Auth\VerifySupabaseJwt::class, function () use ($authUserId, $defaultClaims) {
        return new class($authUserId, $defaultClaims)
        {
            public function __construct(
                private readonly string $uid,
                private readonly array $claims,
            ) {}

            public function handle(\Illuminate\Http\Request $request, \Closure $next)
            {
                $request->attributes->set('supabase_uid', $this->uid);
                $request->attributes->set('supabase_claims', $this->claims);
                $request->attributes->set('supabase_aal', $this->claims['aal'] ?? 'aal1');
                $request->attributes->set('supabase_amr', $this->claims['amr'] ?? []);
                $request->attributes->set('supabase_session_id', $this->claims['session_id'] ?? null);

                return $next($request);
            }
        };
    });

    // Stub EnsurePartnaStaff to inject the staff record on the request.
    app()->bind(\App\Http\Middleware\EnsurePartnaStaff::class, function () use ($staff) {
        return new class($staff)
        {
            public function __construct(private readonly \App\Models\Core\Staff\PartnaStaff $s) {}

            public function handle(\Illuminate\Http\Request $request, \Closure $next)
            {
                $request->attributes->set('partna_staff', $this->s);
                return $next($request);
            }
        };
    });

    return test();
}
```

**Important:** add this helper to `tests/Pest.php` (not `tests/TestCase.php`) — it's a global function in the same file as `actingAsUser`, matching the existing convention in this codebase.

- [ ] **Step 2: Run to fail**

Expected: FAIL — controller missing.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/Staff/StaffCaseController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\Moderation\CaseResource;
use App\Models\Moderation\ModerationCase;
use Illuminate\Http\Request;

class StaffCaseController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user(), 'viewAny', ModerationCase::class);

        $query = ModerationCase::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->query('case_type')) {
            $query->where('case_type', $type);
        }
        if ($sev = $request->query('severity_gte')) {
            $query->where('severity', '>=', (int) $sev);
        }

        $query->orderByDesc('severity')->orderBy('priority')->orderBy('created_at');

        return CaseResource::collection($query->paginate(25));
    }
}
```

- [ ] **Step 4: Add the route**

Modify `routes/api/staff.php`. Add (inside the AAL2 + auth group):

```php
use App\Http\Controllers\Api\Staff\StaffCaseController;

Route::middleware(['auth:supabase', 'require.aal2'])->group(function () {
    Route::get('/v1/staff/cases', [StaffCaseController::class, 'index'])->name('staff.cases.index');
});
```

- [ ] **Step 5: Run + commit**

```bash
php artisan test tests/Feature/Moderation/StaffCaseIndexTest.php
git add app/Http/Controllers/Api/Staff/StaffCaseController.php routes/api/staff.php tests/Feature/Moderation/StaffCaseIndexTest.php tests/TestCase.php
git commit -m "feat(moderation): GET /v1/staff/cases queue listing"
```

---

### Task 18: `StaffCaseController::show`

**Files:**
- Modify: `app/Http/Controllers/Api/Staff/StaffCaseController.php`
- Modify: `routes/api/staff.php`
- Create: `tests/Feature/Moderation/StaffCaseShowTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/StaffCaseShowTest.php`:

```php
<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\Evidence;
use App\Models\Moderation\ModerationCase;

it('returns case detail with eager-loaded signals + evidence + decisions', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create();
    Evidence::factory()->forCase($case)->create();

    $res = $this->actingAsStaff($staff)->getJson("/v1/staff/cases/{$case->id}");
    $res->assertOk();
    $res->assertJsonStructure([
        'data' => ['case', 'signals', 'evidence', 'decisions'],
    ]);
});

it('returns 404 for non-existent case', function () {
    $staff = PartnaStaff::factory()->create();
    $res = $this->actingAsStaff($staff)->getJson('/v1/staff/cases/00000000-0000-0000-0000-000000000000');
    $res->assertStatus(404);
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL — controller method missing.

- [ ] **Step 3: Add `show()` to the controller**

Append to `app/Http/Controllers/Api/Staff/StaffCaseController.php`:

```php
public function show(Request $request, string $caseId): CaseDetailResource
{
    $case = ModerationCase::query()->with(['signals', 'evidence', 'decisions'])->findOrFail($caseId);
    $this->authorizeForUser($request->user(), 'view', $case);
    return new CaseDetailResource($case);
}
```

And add the use statement:

```php
use App\Http\Resources\Moderation\CaseDetailResource;
```

- [ ] **Step 4: Add the route**

Append to `routes/api/staff.php` (inside the same middleware group):

```php
Route::get('/v1/staff/cases/{case}', [StaffCaseController::class, 'show'])->name('staff.cases.show');
```

- [ ] **Step 5: Run + commit**

```bash
php artisan test tests/Feature/Moderation/StaffCaseShowTest.php
git add app/Http/Controllers/Api/Staff/StaffCaseController.php routes/api/staff.php tests/Feature/Moderation/StaffCaseShowTest.php
git commit -m "feat(moderation): GET /v1/staff/cases/{id} detail"
```

---

### Task 19: `StaffCaseController::triage` + FormRequest

**Files:**
- Create: `app/Http/Requests/Staff/TriageCaseRequest.php`
- Modify: `app/Http/Controllers/Api/Staff/StaffCaseController.php`
- Modify: `routes/api/staff.php`
- Create: `tests/Feature/Moderation/StaffCaseTriageTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/StaffCaseTriageTest.php`:

```php
<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;

it('triages an open case', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create(['status' => 'open']);

    $res = $this->actingAsStaff($staff)
        ->postJson("/v1/staff/cases/{$case->id}/triage", ['priority' => 2, 'notes' => 'looks bad']);

    $res->assertOk();
    expect($case->fresh()->status)->toBe('triaged');
    expect($case->fresh()->priority)->toBe(2);
});

it('rejects triage on resolved case with 422', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->resolved()->create();

    $res = $this->actingAsStaff($staff)->postJson("/v1/staff/cases/{$case->id}/triage", []);
    $res->assertStatus(422);
});

it('rejects priority out of range', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create(['status' => 'open']);

    $res = $this->actingAsStaff($staff)->postJson("/v1/staff/cases/{$case->id}/triage", ['priority' => 99]);
    $res->assertStatus(422);
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create the FormRequest**

Create `app/Http/Requests/Staff/TriageCaseRequest.php`:

```php
<?php

namespace App\Http\Requests\Staff;

use App\DTOs\Moderation\TriageDto;
use Illuminate\Foundation\Http\FormRequest;

class TriageCaseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'priority' => ['nullable', 'integer', 'min:1', 'max:10'],
            'notes'    => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function toDto(): TriageDto
    {
        return new TriageDto(
            priority: $this->input('priority'),
            notes:    $this->input('notes'),
        );
    }
}
```

- [ ] **Step 4: Add `triage()` to the controller**

Append to `app/Http/Controllers/Api/Staff/StaffCaseController.php`:

```php
public function triage(TriageCaseRequest $request, string $caseId): CaseResource
{
    $case = ModerationCase::query()->findOrFail($caseId);
    $this->authorizeForUser($request->user(), 'triage', $case);

    try {
        $updated = app(\App\Services\Moderation\ModerationCaseService::class)
            ->triage($case, $request->user(), $request->toDto());
    } catch (\App\Services\Moderation\IllegalCaseTransition $e) {
        abort(422, $e->getMessage());
    }

    return new CaseResource($updated);
}
```

Add use statement:

```php
use App\Http\Requests\Staff\TriageCaseRequest;
```

- [ ] **Step 5: Add the route**

Append to `routes/api/staff.php`:

```php
Route::post('/v1/staff/cases/{case}/triage', [StaffCaseController::class, 'triage'])->name('staff.cases.triage');
```

- [ ] **Step 6: Run + commit**

```bash
php artisan test tests/Feature/Moderation/StaffCaseTriageTest.php
git add app/Http/Requests/Staff/TriageCaseRequest.php app/Http/Controllers/Api/Staff/StaffCaseController.php routes/api/staff.php tests/Feature/Moderation/StaffCaseTriageTest.php
git commit -m "feat(moderation): POST /v1/staff/cases/{id}/triage"
```

---

### Task 20: `StaffCaseController::take` + `release`

**Files:**
- Modify: `app/Http/Controllers/Api/Staff/StaffCaseController.php`
- Modify: `routes/api/staff.php`
- Create: `tests/Feature/Moderation/StaffCaseTakeReleaseTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/StaffCaseTakeReleaseTest.php`:

```php
<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;

it('takes a triaged case → under_review', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->triaged()->create();

    $res = $this->actingAsStaff($staff)->postJson("/v1/staff/cases/{$case->id}/take");
    $res->assertOk();
    expect($case->fresh()->status)->toBe('under_review');
});

it('releases an under_review case back to triaged', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->underReview()->create();

    $res = $this->actingAsStaff($staff)->postJson("/v1/staff/cases/{$case->id}/release");
    $res->assertOk();
    expect($case->fresh()->status)->toBe('triaged');
});

it('rejects take on already-taken case with 422', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->underReview()->create();

    $res = $this->actingAsStaff($staff)->postJson("/v1/staff/cases/{$case->id}/take");
    $res->assertStatus(422);
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Add the controller methods**

Append to `StaffCaseController`:

```php
public function take(Request $request, string $caseId): CaseResource
{
    $case = ModerationCase::query()->findOrFail($caseId);
    $this->authorizeForUser($request->user(), 'take', $case);

    try {
        $updated = app(\App\Services\Moderation\ModerationCaseService::class)
            ->take($case, $request->user());
    } catch (\App\Services\Moderation\IllegalCaseTransition $e) {
        abort(422, $e->getMessage());
    }
    return new CaseResource($updated);
}

public function release(Request $request, string $caseId): CaseResource
{
    $case = ModerationCase::query()->findOrFail($caseId);
    $this->authorizeForUser($request->user(), 'release', $case);

    try {
        $updated = app(\App\Services\Moderation\ModerationCaseService::class)
            ->release($case, $request->user());
    } catch (\App\Services\Moderation\IllegalCaseTransition $e) {
        abort(422, $e->getMessage());
    }
    return new CaseResource($updated);
}
```

- [ ] **Step 4: Add routes**

Append to `routes/api/staff.php`:

```php
Route::post('/v1/staff/cases/{case}/take',    [StaffCaseController::class, 'take'])->name('staff.cases.take');
Route::post('/v1/staff/cases/{case}/release', [StaffCaseController::class, 'release'])->name('staff.cases.release');
```

- [ ] **Step 5: Run + commit**

```bash
php artisan test tests/Feature/Moderation/StaffCaseTakeReleaseTest.php
git add app/Http/Controllers/Api/Staff/StaffCaseController.php routes/api/staff.php tests/Feature/Moderation/StaffCaseTakeReleaseTest.php
git commit -m "feat(moderation): POST /take + /release endpoints"
```

---

### Task 21: `StaffCaseController::decide` + `DecideOnCaseRequest`

**Files:**
- Create: `app/Http/Requests/Staff/DecideOnCaseRequest.php`
- Modify: `app/Http/Controllers/Api/Staff/StaffCaseController.php`
- Modify: `routes/api/staff.php`
- Create: `tests/Feature/Moderation/StaffCaseDecideTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/StaffCaseDecideTest.php`:

```php
<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;

it('records a decision and transitions case to resolved', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->underReview()->create();

    $res = $this->actingAsStaff($staff)->postJson("/v1/staff/cases/{$case->id}/decide", [
        'decision_type' => 'hide_site',
        'reason'        => 'repeated spam after warning',
    ]);
    $res->assertOk();

    expect($case->fresh()->status)->toBe('resolved');
    expect(Decision::query()->where('case_id', $case->id)->count())->toBe(1);
});

it('rejects CSAM override without second_staff_approval_id with 422', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create(['status' => 'auto_actioned', 'case_type' => 'csam_match']);

    $res = $this->actingAsStaff($staff)->postJson("/v1/staff/cases/{$case->id}/decide", [
        'decision_type' => 'override_csam_auto_action',
        'reason'        => 'false positive',
    ]);
    $res->assertStatus(422);
});

it('accepts CSAM override with second_staff_approval_id', function () {
    $staff1 = PartnaStaff::factory()->create();
    $staff2 = PartnaStaff::factory()->create();
    $case   = ModerationCase::factory()->create(['status' => 'auto_actioned', 'case_type' => 'csam_match']);

    $res = $this->actingAsStaff($staff1)->postJson("/v1/staff/cases/{$case->id}/decide", [
        'decision_type'           => 'override_csam_auto_action',
        'reason'                  => 'confirmed false positive',
        'second_staff_approval_id' => $staff2->id,
    ]);
    $res->assertOk();
});

it('rejects when second_staff_approval_id equals deciding staff', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create(['status' => 'auto_actioned']);

    $res = $this->actingAsStaff($staff)->postJson("/v1/staff/cases/{$case->id}/decide", [
        'decision_type'           => 'override_csam_auto_action',
        'reason'                  => 'fp',
        'second_staff_approval_id' => $staff->id,
    ]);
    $res->assertStatus(422);
});

it('rejects reason shorter than 10 chars', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->underReview()->create();

    $res = $this->actingAsStaff($staff)->postJson("/v1/staff/cases/{$case->id}/decide", [
        'decision_type' => 'dismiss',
        'reason'        => 'short',
    ]);
    $res->assertStatus(422);
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create the FormRequest**

Create `app/Http/Requests/Staff/DecideOnCaseRequest.php`:

```php
<?php

namespace App\Http\Requests\Staff;

use App\DTOs\Moderation\DecisionDto;
use Illuminate\Foundation\Http\FormRequest;

class DecideOnCaseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'decision_type' => ['required', 'string', 'in:' . implode(',', self::ALLOWED)],
            'reason'        => ['required', 'string', 'min:10', 'max:2000'],
            'second_staff_approval_id' => [
                'required_if:decision_type,override_csam_auto_action',
                'nullable',
                'uuid',
                'exists:core.partna_staff,id',
                'different:' . ($this->user()?->id ?? 'NEVER'),
            ],
        ];
    }

    public function toDto(): DecisionDto
    {
        return new DecisionDto(
            decisionType:           $this->string('decision_type')->toString(),
            reason:                 $this->string('reason')->toString(),
            secondStaffApprovalId:  $this->input('second_staff_approval_id'),
        );
    }

    public const ALLOWED = [
        'dismiss', 'warn', 'hide_content', 'hide_site',
        'suspend_user', 'ban_user', 'override_csam_auto_action',
        'escalate_law_enforcement', 'escalate_esafety',
    ];
}
```

- [ ] **Step 4: Add the controller method**

Append to `StaffCaseController`:

```php
public function decide(DecideOnCaseRequest $request, string $caseId): \App\Http\Resources\Moderation\DecisionResource
{
    $case = ModerationCase::query()->findOrFail($caseId);
    $this->authorizeForUser($request->user(), 'decide', $case);

    try {
        $decision = app(\App\Services\Moderation\ModerationDecisionService::class)
            ->decide($case, $request->user(), $request->toDto());
    } catch (\App\Services\Moderation\IllegalCaseTransition | \InvalidArgumentException $e) {
        abort(422, $e->getMessage());
    }

    return new \App\Http\Resources\Moderation\DecisionResource($decision);
}
```

Add use statement:

```php
use App\Http\Requests\Staff\DecideOnCaseRequest;
```

- [ ] **Step 5: Add the route**

Append to `routes/api/staff.php`:

```php
Route::post('/v1/staff/cases/{case}/decide', [StaffCaseController::class, 'decide'])->name('staff.cases.decide');
```

- [ ] **Step 6: Run + commit**

```bash
php artisan test tests/Feature/Moderation/StaffCaseDecideTest.php
git add app/Http/Requests/Staff/DecideOnCaseRequest.php app/Http/Controllers/Api/Staff/StaffCaseController.php routes/api/staff.php tests/Feature/Moderation/StaffCaseDecideTest.php
git commit -m "feat(moderation): POST /v1/staff/cases/{id}/decide with CSAM override guards"
```

---

### Task 22: `StaffCaseController::escalate` + `EscalateCaseRequest`

**Files:**
- Create: `app/Http/Requests/Staff/EscalateCaseRequest.php`
- Modify: `app/Http/Controllers/Api/Staff/StaffCaseController.php`
- Modify: `routes/api/staff.php`
- Create: `tests/Feature/Moderation/StaffCaseEscalateTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Moderation/StaffCaseEscalateTest.php`:

```php
<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;

it('escalates to law enforcement and records a decision', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->underReview()->create();

    $res = $this->actingAsStaff($staff)->postJson("/v1/staff/cases/{$case->id}/escalate", [
        'escalation_target' => 'law_enforcement',
        'notes'             => 'CSAM evidence preserved; tip filed; this is a follow-up.',
    ]);
    $res->assertOk();

    expect(Decision::query()
        ->where('case_id', $case->id)
        ->where('decision_type', 'escalate_law_enforcement')
        ->exists())->toBeTrue();
});

it('rejects unknown escalation_target', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->underReview()->create();

    $res = $this->actingAsStaff($staff)->postJson("/v1/staff/cases/{$case->id}/escalate", [
        'escalation_target' => 'mars',
        'notes'             => 'why',
    ]);
    $res->assertStatus(422);
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create the DTO + FormRequest**

Create `app/DTOs/Moderation/EscalationDto.php`:

```php
<?php

namespace App\DTOs\Moderation;

final class EscalationDto
{
    public function __construct(
        public readonly string $target,    // 'law_enforcement' | 'esafety'
        public readonly string $notes,
    ) {}

    public function toDecisionType(): string
    {
        return match ($this->target) {
            'law_enforcement' => 'escalate_law_enforcement',
            'esafety'         => 'escalate_esafety',
        };
    }
}
```

Create `app/Http/Requests/Staff/EscalateCaseRequest.php`:

```php
<?php

namespace App\Http\Requests\Staff;

use App\DTOs\Moderation\EscalationDto;
use Illuminate\Foundation\Http\FormRequest;

class EscalateCaseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'escalation_target' => ['required', 'string', 'in:law_enforcement,esafety'],
            'notes'             => ['required', 'string', 'min:20', 'max:4000'],
        ];
    }

    public function toDto(): EscalationDto
    {
        return new EscalationDto(
            target: $this->string('escalation_target')->toString(),
            notes:  $this->string('notes')->toString(),
        );
    }
}
```

- [ ] **Step 4: Add the controller method**

Append to `StaffCaseController`:

```php
public function escalate(EscalateCaseRequest $request, string $caseId): \App\Http\Resources\Moderation\DecisionResource
{
    $case = ModerationCase::query()->findOrFail($caseId);
    $this->authorizeForUser($request->user(), 'escalate', $case);

    $esc = $request->toDto();
    $dto = new \App\DTOs\Moderation\DecisionDto(
        decisionType:           $esc->toDecisionType(),
        reason:                 $esc->notes,
        secondStaffApprovalId:  null,
    );

    try {
        $decision = app(\App\Services\Moderation\ModerationDecisionService::class)
            ->decide($case, $request->user(), $dto);
    } catch (\App\Services\Moderation\IllegalCaseTransition $e) {
        abort(422, $e->getMessage());
    }
    return new \App\Http\Resources\Moderation\DecisionResource($decision);
}
```

Add use statements:

```php
use App\Http\Requests\Staff\EscalateCaseRequest;
```

- [ ] **Step 5: Add the route**

Append to `routes/api/staff.php`:

```php
Route::post('/v1/staff/cases/{case}/escalate', [StaffCaseController::class, 'escalate'])->name('staff.cases.escalate');
```

- [ ] **Step 6: Run + commit**

```bash
php artisan test tests/Feature/Moderation/StaffCaseEscalateTest.php
git add app/DTOs/Moderation/EscalationDto.php app/Http/Requests/Staff/EscalateCaseRequest.php app/Http/Controllers/Api/Staff/StaffCaseController.php routes/api/staff.php tests/Feature/Moderation/StaffCaseEscalateTest.php
git commit -m "feat(moderation): POST /v1/staff/cases/{id}/escalate"
```

---

## Phase 7 — Lifecycle commands

### Task 23: `moderation:sla-scan` command

**Files:**
- Create: `app/Console/Commands/Moderation/ModerationSlaScanCommand.php`
- Modify: `app/Console/Kernel.php`
- Create: `tests/Feature/Commands/Moderation/ModerationSlaScanCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Commands/Moderation/ModerationSlaScanCommandTest.php`:

```php
<?php

use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\Log;

it('logs warnings for cases approaching SLA breach', function () {
    Log::spy();
    ModerationCase::factory()->create([
        'status'     => 'open',
        'sla_due_at' => now()->addMinutes(60),
    ]);
    ModerationCase::factory()->create([
        'status'     => 'open',
        'sla_due_at' => now()->addHours(24),  // not near breach
    ]);

    $this->artisan('moderation:sla-scan')->assertSuccessful();

    Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'sla.breach_risk'))->once();
});

it('emits no warnings when no cases are near breach', function () {
    Log::spy();
    ModerationCase::factory()->create(['status' => 'open', 'sla_due_at' => now()->addDays(5)]);

    $this->artisan('moderation:sla-scan')->assertSuccessful();
    Log::shouldNotHaveReceived('warning');
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/Moderation/ModerationSlaScanCommand.php`:

```php
<?php

namespace App\Console\Commands\Moderation;

use App\Models\Moderation\ModerationCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ModerationSlaScanCommand extends Command
{
    protected $signature = 'moderation:sla-scan';
    protected $description = 'Warn on cases approaching SLA breach (configurable lead time).';

    public function handle(): int
    {
        $leadMinutes = config('partna.moderation.sla.breach_warning_min', 120);
        $cutoff      = now()->addMinutes($leadMinutes);

        $atRisk = ModerationCase::query()
            ->whereIn('status', ['open', 'triaged', 'under_review'])
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', $cutoff)
            ->get(['id', 'severity', 'sla_due_at']);

        foreach ($atRisk as $case) {
            $minutes = now()->diffInMinutes($case->sla_due_at, false);
            Log::warning('moderation.sla.breach_risk', [
                'case_id'         => $case->id,
                'severity'        => $case->severity,
                'due_in_minutes'  => $minutes,
            ]);
        }

        $this->info("Scanned. {$atRisk->count()} cases near SLA breach.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Schedule the command**

Modify `app/Console/Kernel.php`. In the `schedule()` method, add:

```php
$schedule->command('moderation:sla-scan')->everyFifteenMinutes();
```

- [ ] **Step 5: Run + commit**

```bash
php artisan test tests/Feature/Commands/Moderation/ModerationSlaScanCommandTest.php
git add app/Console/Commands/Moderation/ModerationSlaScanCommand.php app/Console/Kernel.php tests/Feature/Commands/Moderation/ModerationSlaScanCommandTest.php
git commit -m "feat(moderation): moderation:sla-scan command (every 15min)"
```

---

### Task 24: `moderation:redact-reporter-pii {case_id}`

**Files:**
- Create: `app/Console/Commands/Moderation/ModerationRedactReporterPiiCommand.php`
- Create: `tests/Feature/Commands/Moderation/ModerationRedactReporterPiiCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Commands/Moderation/ModerationRedactReporterPiiCommandTest.php`:

```php
<?php

use App\Models\Moderation\AuditEvent;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\ModerationCase;

it('clears reporter_email and reporter_ip_hash on signals for the case', function () {
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create([
        'reporter_email'   => 'leak@example.com',
        'reporter_ip_hash' => 'somehash',
    ]);
    CaseSignal::factory()->forCase($case)->create([
        'reporter_email'   => 'leak2@example.com',
        'reporter_ip_hash' => 'somehash2',
    ]);

    $this->artisan("moderation:redact-reporter-pii {$case->id} --reason='gdpr erasure'")
        ->assertSuccessful();

    $signals = CaseSignal::query()->where('case_id', $case->id)->get();
    foreach ($signals as $s) {
        expect($s->reporter_email)->toBeNull();
        expect($s->reporter_ip_hash)->toBeNull();
    }

    $audit = AuditEvent::query()->where('action', 'reporter.pii_redacted')->latest('created_at')->first();
    expect($audit)->not->toBeNull();
    expect($audit->actor_kind)->toBe('system');
});

it('fails when case does not exist', function () {
    $this->artisan('moderation:redact-reporter-pii 00000000-0000-0000-0000-000000000000 --reason=test')
        ->assertFailed();
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/Moderation/ModerationRedactReporterPiiCommand.php`:

```php
<?php

namespace App\Console\Commands\Moderation;

use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\ModerationCase;
use App\Services\Moderation\ModerationAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ModerationRedactReporterPiiCommand extends Command
{
    protected $signature = 'moderation:redact-reporter-pii {case_id} {--reason=}';
    protected $description = 'Clear reporter_email + reporter_ip_hash from case_signals for a case (GDPR erasure).';

    public function handle(ModerationAuditService $audit): int
    {
        $caseId = $this->argument('case_id');
        $reason = $this->option('reason') ?: 'unspecified';

        $case = ModerationCase::query()->find($caseId);
        if ($case === null) {
            $this->error("Case {$caseId} not found.");
            return self::FAILURE;
        }

        DB::transaction(function () use ($case, $audit, $reason) {
            CaseSignal::query()
                ->where('case_id', $case->id)
                ->update([
                    'reporter_email'   => null,
                    'reporter_ip_hash' => null,
                ]);

            $audit->recordSystemAction(
                'reporter.pii_redacted',
                'ModerationCase',
                $case->id,
                ['reason' => $reason],
            );
        });

        $this->info("Reporter PII redacted for case {$case->id}.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run + commit**

```bash
php artisan test tests/Feature/Commands/Moderation/ModerationRedactReporterPiiCommandTest.php
git add app/Console/Commands/Moderation/ModerationRedactReporterPiiCommand.php tests/Feature/Commands/Moderation/ModerationRedactReporterPiiCommandTest.php
git commit -m "feat(moderation): moderation:redact-reporter-pii command (GDPR erasure)"
```

---

### Task 25: `moderation:show-case` (support utility)

**Files:**
- Create: `app/Console/Commands/Moderation/ModerationShowCaseCommand.php`
- Create: `tests/Feature/Commands/Moderation/ModerationShowCaseCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Commands/Moderation/ModerationShowCaseCommandTest.php`:

```php
<?php

use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\Decision;
use App\Models\Moderation\Evidence;
use App\Models\Moderation\ModerationCase;

it('prints a case in JSON to stdout', function () {
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create();
    Evidence::factory()->forCase($case)->create();
    Decision::factory()->forCase($case)->create();

    $this->artisan("moderation:show-case {$case->id}")
        ->expectsOutputToContain('"case_id"')
        ->expectsOutputToContain('"signals"')
        ->expectsOutputToContain('"evidence"')
        ->expectsOutputToContain('"decisions"')
        ->assertSuccessful();
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/Moderation/ModerationShowCaseCommand.php`:

```php
<?php

namespace App\Console\Commands\Moderation;

use App\Models\Moderation\ModerationCase;
use Illuminate\Console\Command;

class ModerationShowCaseCommand extends Command
{
    protected $signature = 'moderation:show-case {case_id}';
    protected $description = 'Print a case + signals + evidence + decisions as JSON (support utility).';

    public function handle(): int
    {
        $case = ModerationCase::query()
            ->with(['signals', 'evidence', 'decisions'])
            ->find($this->argument('case_id'));

        if ($case === null) {
            $this->error('Case not found.');
            return self::FAILURE;
        }

        $this->line(json_encode([
            'case_id'   => $case->id,
            'case'      => $case->toArray(),
            'signals'   => $case->signals->map->toArray(),
            'evidence'  => $case->evidence->map->toArray(),
            'decisions' => $case->decisions->map->toArray(),
        ], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run + commit**

```bash
php artisan test tests/Feature/Commands/Moderation/ModerationShowCaseCommandTest.php
git add app/Console/Commands/Moderation/ModerationShowCaseCommand.php tests/Feature/Commands/Moderation/ModerationShowCaseCommandTest.php
git commit -m "feat(moderation): moderation:show-case support utility"
```

---

### Task 26: `moderation:reverse-decision {decision_id}` (pre-appeals stop-gap)

**Files:**
- Create: `app/Console/Commands/Moderation/ModerationReverseDecisionCommand.php`
- Create: `tests/Feature/Commands/Moderation/ModerationReverseDecisionCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Commands/Moderation/ModerationReverseDecisionCommandTest.php`:

```php
<?php

use App\Models\Moderation\AuditEvent;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;

it('writes a new decision with supersedes_decision_id pointing at the original', function () {
    $case     = ModerationCase::factory()->resolved()->create();
    $original = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);

    $this->artisan("moderation:reverse-decision {$original->id} --reason='discovered mistaken context'")
        ->assertSuccessful();

    $reversal = Decision::query()
        ->where('supersedes_decision_id', $original->id)
        ->latest('decided_at')
        ->first();

    expect($reversal)->not->toBeNull();
    expect($reversal->decision_type)->toBe('dismiss');
    expect(AuditEvent::query()->where('action', 'decision.reversed')->exists())->toBeTrue();
});

it('fails when the decision does not exist', function () {
    $this->artisan('moderation:reverse-decision 00000000-0000-0000-0000-000000000000 --reason=test')
        ->assertFailed();
});
```

- [ ] **Step 2: Run to fail**

Expected: FAIL.

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/Moderation/ModerationReverseDecisionCommand.php`:

```php
<?php

namespace App\Console\Commands\Moderation;

use App\Models\Moderation\Decision;
use App\Services\Moderation\ModerationAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ModerationReverseDecisionCommand extends Command
{
    protected $signature = 'moderation:reverse-decision {decision_id} {--reason=}';
    protected $description = 'Reverse a prior decision (pre-appeals stop-gap); creates a new decision with supersedes_decision_id.';

    public function handle(ModerationAuditService $audit): int
    {
        $reason = $this->option('reason') ?: 'unspecified';
        $original = Decision::query()->find($this->argument('decision_id'));

        if ($original === null) {
            $this->error('Decision not found.');
            return self::FAILURE;
        }

        DB::transaction(function () use ($original, $audit, $reason) {
            $reversal = Decision::create([
                'case_id'                => $original->case_id,
                'decision_type'          => 'dismiss',
                'reason'                 => "Reversal of {$original->id}: {$reason}",
                'decided_by_staff_id'    => null,
                'decided_by_system'      => true,
                'auto_actioned'          => false,
                'supersedes_decision_id' => $original->id,
            ]);

            $audit->recordSystemAction(
                'decision.reversed',
                'Decision',
                $original->id,
                ['reversal_decision_id' => $reversal->id, 'reason' => $reason],
            );
        });

        $this->info('Decision reversed.');
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run + commit**

```bash
php artisan test tests/Feature/Commands/Moderation/ModerationReverseDecisionCommandTest.php
git add app/Console/Commands/Moderation/ModerationReverseDecisionCommand.php tests/Feature/Commands/Moderation/ModerationReverseDecisionCommandTest.php
git commit -m "feat(moderation): moderation:reverse-decision (pre-appeals stop-gap)"
```

---

## Phase 8 — Security tests

### Task 27: AAL2 enforcement sweep test

**Files:**
- Create: `tests/Feature/Security/ModerationAal2EnforcementTest.php`

- [ ] **Step 1: Write the test**

Create `tests/Feature/Security/ModerationAal2EnforcementTest.php`:

```php
<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;

it('returns 401 for unauthenticated requests to every staff case endpoint', function () {
    $case = ModerationCase::factory()->create();
    $routes = [
        ['GET',  '/v1/staff/cases'],
        ['GET',  "/v1/staff/cases/{$case->id}"],
        ['POST', "/v1/staff/cases/{$case->id}/triage"],
        ['POST', "/v1/staff/cases/{$case->id}/take"],
        ['POST', "/v1/staff/cases/{$case->id}/release"],
        ['POST', "/v1/staff/cases/{$case->id}/decide"],
        ['POST', "/v1/staff/cases/{$case->id}/escalate"],
    ];

    foreach ($routes as [$method, $url]) {
        $this->json($method, $url)->assertStatus(401);
    }
});

it('returns 401 when staff is authenticated but AAL1 (no MFA)', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create();

    // Override aal claim to aal1; the require.aal2 middleware should reject.
    // BasePolicy::requiresAal2() returns 401 (not 403) so frontend triggers a
    // step-up MFA challenge.
    $res = actingAsStaff($staff, ['aal' => 'aal1', 'amr' => []])
        ->getJson('/v1/staff/cases');

    $res->assertStatus(401);
});
```

- [ ] **Step 2: Run + commit**

```bash
php artisan test tests/Feature/Security/ModerationAal2EnforcementTest.php
git add tests/Feature/Security/ModerationAal2EnforcementTest.php
git commit -m "test(security): AAL2 enforcement on staff moderation routes"
```

---

### Task 28: Capability-gated dispatcher sweep test

**Files:**
- Create: `tests/Feature/Moderation/CapabilityGatedDispatcherTest.php`

- [ ] **Step 1: Write the test**

Create `tests/Feature/Moderation/CapabilityGatedDispatcherTest.php`:

```php
<?php

use App\Jobs\Moderation\NotifyReportedUserJob;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\ContentHiddenNotification;
use Illuminate\Support\Facades\Notification;

it('does not notify users whose receive_moderation_notifications capability is false', function () {
    Notification::fake();
    $user = User::factory()->create(['status' => 'disabled']);
    $case = ModerationCase::factory()->create(['reportable_owner_user_id' => $user->id]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reported_user']);

    (new NotifyReportedUserJob($entry->id, $case->id))->handle();

    Notification::assertNothingSent();
    expect($entry->fresh()->status)->toBe('completed'); // skipped cleanly, not failed
});

it('still notifies users whose state is active', function () {
    Notification::fake();
    $user = User::factory()->create(['status' => 'active']);
    $case = ModerationCase::factory()->create(['reportable_owner_user_id' => $user->id]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reported_user']);

    (new NotifyReportedUserJob($entry->id, $case->id))->handle();

    Notification::assertSentTo($user, ContentHiddenNotification::class);
});
```

- [ ] **Step 2: Run + commit**

```bash
php artisan test tests/Feature/Moderation/CapabilityGatedDispatcherTest.php
git add tests/Feature/Moderation/CapabilityGatedDispatcherTest.php
git commit -m "test(moderation): dispatcher respects AccountCapabilities gate"
```

---

## Phase 9 — Docs + verification

### Task 29: Update operator runbook with staff workflow

**Files:**
- Modify: `docs/moderation/README.md`
- Create: `docs/moderation/staff-workflow.md`

- [ ] **Step 1: Update README to mark Plan B complete**

Modify `docs/moderation/README.md`. Update the status table to:

```markdown
| Capability | Status |
|------------|--------|
| Public `POST /v1/public/report` | ✅ live |
| Cases dedup + merge | ✅ live |
| Evidence snapshotting (Site target) | ✅ live |
| Audit log (`audit.moderation_events`) | ✅ live |
| Staff queue endpoints | ✅ live |
| Decision pipeline + outcome jobs | ✅ live |
| AAL2-gated staff workflow | ✅ live |
| Lifecycle commands (SLA scan, redact, show, reverse) | ✅ live |
| CSAM scanning | ⏳ Plan C |
```

Add a "Staff workflow" section:

```markdown
## Staff workflow

See `docs/moderation/staff-workflow.md` for the day-to-day staff playbook.
```

- [ ] **Step 2: Create `docs/moderation/staff-workflow.md`**

Create `docs/moderation/staff-workflow.md`:

```markdown
# Staff moderation workflow

## Daily flow

1. **Log in with MFA** (AAL2 required for all staff moderation routes)
2. **Check the queue** — `GET /v1/staff/cases?status=open`
3. **Triage** — `POST /v1/staff/cases/{id}/triage` with priority + notes
4. **Take** the case (claim a single-staff lock) — `POST /v1/staff/cases/{id}/take`
5. **Review** the evidence snapshot, signals, related cases
6. **Decide** — `POST /v1/staff/cases/{id}/decide` with `{decision_type, reason}` (min 10 chars)
7. **Or escalate** — `POST /v1/staff/cases/{id}/escalate` for law enforcement / eSafety

## CSAM auto-actions

CSAM matches arrive as `case_type='csam_match'`, severity 5, status `auto_actioned`.
The system has already:
- Suspended the user
- Hidden the site
- Quarantined the media
- Filed (or queued for filing) the NCMEC CyberTipline report

Staff role: **confirm** the auto-action via `decide` with `decision_type='dismiss'` (no-op), OR **override** if a false positive is suspected.

Override requires:
- `decision_type='override_csam_auto_action'`
- `reason` (written explanation)
- `second_staff_approval_id` — a DIFFERENT staff member who has reviewed and approved the override
- AAL2 from BOTH staff members (deciding + approver)

## Useful commands

```bash
# Show a case
php artisan moderation:show-case <case_id>

# Manually reverse a decision (pre-appeals)
php artisan moderation:reverse-decision <decision_id> --reason="…"

# GDPR erasure
php artisan moderation:redact-reporter-pii <case_id> --reason="user request"
```

## Decision matrix

| Outcome | When | Effects |
|---------|------|---------|
| `dismiss` | False alarm, off-platform, not actionable | No effects; case closed |
| `warn` | Borderline / first violation | Notify reported user; case closed |
| `hide_content` | Specific content needs removal | Cache purge; notify reported user |
| `hide_site` | Site-level issue | Suspend site; purge cache; notify |
| `suspend_user` | User-level violation | Suspend user + site; cache purge; notify |
| `ban_user` | Permanent | Suspend everything; notify; cannot self-restore |
| `override_csam_auto_action` | Confirmed false positive | Reverses the auto-suspend; **requires 2nd staff** |
| `escalate_law_enforcement` | Criminal content | Records decision + notifies on-call; no public effects |
| `escalate_esafety` | AU-specific notice/coordination | Records decision + notifies on-call |
```

- [ ] **Step 3: Commit**

```bash
git add docs/moderation/README.md docs/moderation/staff-workflow.md
git commit -m "docs(moderation): staff workflow runbook for Plan B"
```

---

### Task 30: End-to-end verification + PR

- [ ] **Step 1: Full test suite passes**

Run: `composer test && php artisan test --group=postgres`
Expected: all tests pass.

- [ ] **Step 2: Smoke test the full reporting → decision → outcome flow on dev**

```bash
# 1. Submit a report
curl -X POST 'http://localhost:8000/v1/public/report' \
  -H 'Content-Type: application/json' \
  -d '{
    "target_type": "Site",
    "target_handle": "<dev-test-handle>",
    "reason_code": "spam",
    "details": "manual smoke",
    "reporter_email": "smoke@example.com",
    "turnstile_token": "<dev-bypass-token>"
  }'

# 2. Confirm case landed
php artisan moderation:show-case <case-id-from-tinker>

# 3. Triage + decide as staff
curl -X POST 'http://localhost:8000/v1/staff/cases/<id>/triage' \
  -H 'Authorization: Bearer <aal2-token>' -H 'Content-Type: application/json' \
  -d '{"priority": 2, "notes": "manual smoke triage"}'

curl -X POST 'http://localhost:8000/v1/staff/cases/<id>/take' \
  -H 'Authorization: Bearer <aal2-token>'

curl -X POST 'http://localhost:8000/v1/staff/cases/<id>/decide' \
  -H 'Authorization: Bearer <aal2-token>' -H 'Content-Type: application/json' \
  -d '{"decision_type": "hide_site", "reason": "manual smoke decision"}'

# 4. Confirm outcomes
# - users.moderation_state should NOT change (this is hide_site, not suspend)
# - sites.moderation_state should be 'hidden'
# - SyncSubdomainToKvJob should have fired (check Horizon dashboard)
# - reporter should have received outcome email (check Mailhog / dev mail trap)
```

- [ ] **Step 3: Check Cloud logs for exceptions**

Run: `cloud env:logs partna development --minutes 15 | grep -i error`
Expected: no exceptions on `/v1/staff/cases*`.

- [ ] **Step 4: Verify Nightwatch dashboards**

Open Nightwatch dashboard. Confirm:
- No slow routes on `/v1/staff/cases*`
- No slow jobs on any `App\Jobs\Moderation\*`
- `moderation.case.*` breadcrumbs flowing

- [ ] **Step 5: Open the PR**

```bash
git push -u origin feat/ts-foundation-plan-b
gh pr create --base development --title "feat(moderation): T&S foundation Plan B — staff workflow + outcomes" \
  --body "$(cat <<'EOF'
## Summary
- Adds `ModerationCaseService` (triage/take/release), `ModerationDecisionService` (decide), `ModerationActionDispatcher` (decision_type → jobs)
- 6 outcome jobs implemented: `SuspendUserJob`, `SuspendSiteJob`, `QuarantineMediaJob`, `PurgeModerationCacheJob`, `NotifyReportedUserJob`, `NotifyReporterJob`, `NotifyOnCallStaffJob`
- 6 notification classes + mail templates
- 6 staff endpoints: index, show, triage, take, release, decide, escalate (all AAL2-gated)
- `AccountCapabilities` extended for `can_be_reported` and `receive_moderation_notifications` (fail-closed on suspended/banned)
- 4 lifecycle commands: `sla-scan` (scheduled), `redact-reporter-pii`, `show-case`, `reverse-decision`
- Horizon `moderation_high` queue lane
- AAL2 enforcement sweep test; capability-gated dispatcher test

After this PR: reporting workflow is fully operational end-to-end.

## Test plan
- [ ] `composer test` passes
- [ ] `php artisan test --group=postgres` passes
- [ ] Manual smoke: report → triage → take → decide → outcome verified
- [ ] Verify SyncSubdomainToKvJob fires on hide_site
- [ ] Verify reporter receives outcome email
- [ ] Verify reported user receives statement-of-reasons email
- [ ] Verify CSAM override requires 2nd staff (422 without it)
- [ ] No exceptions in Nightwatch on new routes

Spec: `docs/superpowers/specs/2026-05-26-trust-and-safety-foundation-design.md`
Plan: `docs/superpowers/plans/2026-05-26-trust-and-safety-foundation-plan-b.md`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review checklist

- [ ] `ModerationDecisionService` is the only legal path to write a `decisions` row (no controller bypasses)
- [ ] CSAM override requires both `second_staff_approval_id` AND that it differs from deciding staff
- [ ] All outcome jobs are idempotent (running twice has no extra effect)
- [ ] All outcome jobs update `action_log` rows (dispatched/completed timestamps + attempts counter)
- [ ] `NotifyReportedUserJob` and `NotifyReporterJob` consult `AccountCapabilities` before sending
- [ ] Anonymous signals (no `reporter_email`) cleanly skip `NotifyReporterJob` (no errors)
- [ ] `PurgeModerationCacheJob` dispatches `SyncSubdomainToKvJob` (the canonical KV writer)
- [ ] All staff routes are AAL2-gated via `require.aal2` middleware
- [ ] `CaseResource` / `CaseDetailResource` never expose `reporter_email` or `reporter_ip_hash`
- [ ] `moderation:reverse-decision` writes a new decision with `supersedes_decision_id` (doesn't mutate the original)
- [ ] `moderation:redact-reporter-pii` clears columns + records an audit row
- [ ] `moderation:sla-scan` is scheduled every 15 min and logs at warning level
- [ ] Horizon `moderation_high` lane defined; high-priority jobs use it
- [ ] All commits follow project commit message style

---

## What ships after Plan B

- Full reporting workflow operational: visitor reports → cases land → staff triage/decide → outcomes fire
- Reporters receive outcome emails when their email is on the signal
- Reported users receive statement-of-reasons emails for `hide_*` / `suspend_*` / `ban_*` outcomes
- Edge cache is purged when a site is hidden (via existing `SyncSubdomainToKvJob`)
- Staff have an AAL2-gated REST API for the queue
- `moderation:reverse-decision` provides a pre-appeals stop-gap for "we got it wrong"
- CSAM scanning still not live (Plan C)

**Next:** Plan C wires up the R2 quarantine bucket, Cloudflare CSAM webhook, NCMEC CyberTipline outbox, and the auto-action pipeline. Plan B's `ModerationDecisionService::decide()` and `ModerationActionDispatcher` are the entry points Plan C reuses for auto-actions.
