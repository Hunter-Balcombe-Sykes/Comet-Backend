# Staff Audit Log (OPS-2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a platform-wide audit trail of every staff write so B7 (uploads) and all future admin endpoints have a structural attribution record from day one.

**Architecture:** A new `core.staff_audit_log` table records one row per staff write request via a single piece of middleware attached to both staff route groups. The middleware deliberately captures **no request body** — only `{actor, route, method, URL route bindings, status_code, IP, UA, timestamp}`. This eliminates the per-route payload-scrub allowlist (the most error-prone part of the audit plan as originally written) and removes the risk of a secret leaking to the log. Body-detail forensics, where useful, can be added later as opt-in `StaffAuditService::record(...)` calls invoked from specific controllers — the table is forward-compatible because `payload_summary` is a `jsonb` column.

**Tech Stack:** PHP 8.5, Laravel 12, PostgreSQL (Supabase-hosted, `core.*` schema), Pest 4 + PHPUnit, SQLite in-memory for tests (with the `ATTACH DATABASE ':memory:' AS core` pattern used throughout `tests/Feature/Staff/`).

**Dependency it satisfies:** `audits/open/audit-2026-05-08-staff-admin-coverage.md:92` — *"B7 (uploads) should land after #OPS-2 (audit log) — file replacements are exactly the kind of change that needs a footprint."* This plan ships OPS-2 before B7 begins.

---

## File Structure

**Created:**

- `supabase/migrations/20260517300000_create_staff_audit_log.sql` — DDL inside `BEGIN`/`COMMIT`. Table + RLS + grants. No index file needed (initial table; concurrent-index rule only applies to existing populated tables).
- `app/Models/Core/Staff/StaffAuditEntry.php` — Eloquent model bound to `core.staff_audit_log`. Mirrors the conventions of `App\Models\Core\Staff\PartnaStaff`.
- `database/factories/Core/Staff/StaffAuditEntryFactory.php` — factory for tests. Mirrors existing factory locations under `database/factories/Core/...`.
- `app/Services/Audit/StaffAuditService.php` — single static-style entry point `record(...)`. The audit subsystem is small enough that a new `Services/Audit/` namespace is the right home (rather than parking it inside `Services/Auth/` or a generic `Services/Logging/`).
- `app/Http/Middleware/Logging/RecordStaffAuditEntry.php` — `terminate()`-based middleware that fires after the response is sent, then writes the audit row. Lives alongside `LogLeadRateLimits.php`.
- `tests/Unit/Services/Audit/StaffAuditServiceTest.php` — unit coverage for the service.
- `tests/Feature/Middleware/RecordStaffAuditEntryTest.php` — middleware-level coverage (constructs a request, runs middleware, asserts row).
- `tests/Feature/Staff/StaffAuditLogIntegrationTest.php` — real-route end-to-end coverage. Hits one staff write endpoint via `$this->patchJson(...)` and asserts a row landed.

**Modified:**

- `bootstrap/app.php:79-93` — add one alias `'staff.audit' => RecordStaffAuditEntry::class` to the `$middleware->alias([...])` call.
- `routes/api/staff.php:50` — add `'staff.audit'` to the read group's middleware array. (Reads are NOT logged by default — see Task 5; this exists to make it trivially toggleable later.)
- `routes/api/staff.php:225` — add `'staff.audit'` to the admin write group's middleware array. This is the load-bearing wiring.

**Not touched:**

- Anything under `app/Http/Controllers/Api/Staff/` — the whole design goal of this plan is that controllers stay untouched. The middleware is the only attachment point.
- `routes/api/professional.php` or `routes/api/publicSite.php` — out of scope. This is a staff-only audit log.

---

## Design decisions locked in before writing code

These are non-negotiable for this plan. Each one shaped the schema/middleware contract; revisit only if a task surfaces a concrete blocker.

| Decision | Choice | Why |
|----------|--------|-----|
| **Request body capture** | NONE — `payload_summary` only stores route bindings (e.g., `{professional: '<uuid>', customer: '<uuid>'}`). | Eliminates the payload-scrub allowlist and the secret-leak risk. Body-detail capture can be added later as opt-in `StaffAuditService::record(['payload_summary' => [...]])` calls from specific controllers. |
| **Which HTTP methods are logged** | `POST`, `PATCH`, `PUT`, `DELETE`. `GET`/`HEAD`/`OPTIONS` are skipped. | Reads carry no risk of unauthorised state change; logging them would 5–10× the table volume without forensic value. Easy to expand later. |
| **Which responses are logged** | All status codes including 4xx/5xx — `status_code` is a column. | A staff member *attempting* a write that returns 403/422 is still forensically interesting ("who tried to suspend brand X last week?"). Filtering to 2xx-only would hide intent. |
| **Where middleware fires** | `terminate()` (after response is sent to client). | Audit-log writes should never add latency to the response. `terminate()` runs in the FPM after-response phase so DB I/O doesn't block the client. |
| **Failure mode** | If the audit insert throws, log a warning and swallow. | An audit-log outage must never block a staff action. The `Log::warning('staff.audit.write_failed', [...])` line is the breadcrumb for "we lost some audit data" — Nightwatch will surface it if it becomes recurrent. |
| **`professional_id` resolution** | From `$request->route('professional')` if present, else NULL. | Most staff write routes have `{professional}` as the first route binding. Routes without it (e.g., `/staff/commission-payouts/{payout}/retry`) get NULL — the route name + payload_summary still identifies the target. |
| **`impersonator_staff_id`** | Nullable column from day one, always NULL until OPS-1 (impersonation) ships. | Plan dependency at `audits/open/audit-2026-05-08-staff-admin-coverage.md:91`. Adding the column later would force a backfill migration. |
| **Index strategy** | One composite index on `(staff_id, created_at DESC)`, one on `(professional_id, created_at DESC) WHERE professional_id IS NOT NULL`. | Forensic queries are always "what did staff X do" or "what happened to brand Y". `created_at DESC` is the universal order. No need for a `route` index yet — staff endpoints are <50 and the table will be small. |
| **Retention** | None implemented in this plan. Append-only forever. | At <100 staff writes/day pre-beta, the table will be tiny for years. Add a partitioning or TTL strategy when row count crosses ~10M. |
| **RLS + append-only enforcement** | `app_backend INSERT+SELECT only` (split policies — no `FOR ALL`); explicit `REVOKE UPDATE, DELETE`; trigger raises unconditionally on UPDATE/DELETE. Staff `SELECT` policy for `role IN ('admin','support')`. No tenant policy. | Audit data is staff-only AND append-only by design. Three-layer enforcement survives any future migration that re-grants schema-wide privileges or replaces the RLS policies. Stricter than `core.professional_deletion_audit` (which uses `FOR ALL`) — tightened in response to SCHEMA-1 of the plan audit (`audits/ops-2-plan-audit/audit-2026-05-17-full.md`). Mirrors the append-only discipline of `commerce.order_events`. |

---

## Task 1: Migration — create `core.staff_audit_log`

**Files:**
- Create: `supabase/migrations/20260517300000_create_staff_audit_log.sql`

**Schema rationale:**

- `id uuid pk default gen_random_uuid()` — UUID per CLAUDE.md "UUID primary keys on all tables".
- `staff_id uuid` — actor. FK to `core.partna_staff(id)` with `ON DELETE SET NULL` so a deleted staff member doesn't wipe the audit history. Snapshot the staff email at insert (`staff_email_snapshot text`) so post-deletion rows are still legible.
- `impersonator_staff_id uuid null` — same shape, always NULL until OPS-1 ships.
- `professional_id uuid null` — target tenant. `ON DELETE SET NULL`, with `professional_handle_snapshot text` to survive hard-delete. Pattern matches `core.professional_deletion_audit`, `core.wallet_currency_switch_audit`, `core.brand_status_history`.
- `route text not null` — Laravel's `$request->route()->getName() ?? $request->route()->uri()`. Examples: `'staff.professionals.update'`, `'staff/professionals/{professional}/services/{service}'`.
- `http_method text not null` — `POST`, `PATCH`, `PUT`, `DELETE`. CHECK constraint.
- `status_code smallint not null` — `200`, `204`, `403`, etc. CHECK 100–599.
- `payload_summary jsonb not null default '{}'::jsonb` — route bindings only.
- `ip inet null` — Postgres `inet` type, not `text`. Cheaper to index later if we ever want abuse detection.
- `user_agent text null`.
- `created_at timestamptz not null default now()`.

- [ ] **Step 1.1: Create the migration file**

```sql
-- File: supabase/migrations/20260517300000_create_staff_audit_log.sql
-- OPS-2: Platform audit log of every staff write. One row per POST/PATCH/PUT/DELETE
-- against /staff/* routes. Append-only. RLS: staff-read only.
--
-- Body capture is deliberately omitted; payload_summary holds route bindings only.
-- Body detail can be added per-endpoint later via StaffAuditService::record().
--
-- impersonator_staff_id is nullable today and always NULL until OPS-1 (impersonation)
-- ships. Including the column from day one avoids a backfill later.
--
-- FK pattern mirrors core.professional_deletion_audit:
--   * ON DELETE SET NULL on both staff_id and professional_id
--   * *_snapshot text columns so audit rows survive hard-deletes
-- so the audit row is still legible after the actor or target is gone.

BEGIN;

CREATE TABLE core.staff_audit_log (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),

    staff_id uuid NULL REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    staff_email_snapshot text NULL,

    impersonator_staff_id uuid NULL REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    impersonator_email_snapshot text NULL,

    professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    professional_handle_snapshot text NULL,

    route text NOT NULL,
    http_method text NOT NULL,
    status_code smallint NOT NULL,
    payload_summary jsonb NOT NULL DEFAULT '{}'::jsonb,

    ip inet NULL,
    user_agent text NULL,

    created_at timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT staff_audit_log_http_method_check
        CHECK (http_method IN ('POST', 'PATCH', 'PUT', 'DELETE')),
    CONSTRAINT staff_audit_log_status_code_check
        CHECK (status_code BETWEEN 100 AND 599)
);

-- Forensic query: "what did staff X do?" — most common path.
CREATE INDEX idx_staff_audit_log_staff_created
    ON core.staff_audit_log (staff_id, created_at DESC);

-- Forensic query: "what happened to brand Y?" — second most common path.
-- Partial index because a meaningful fraction of writes have no professional binding
-- (e.g. /staff/commission-payouts/{payout}/retry).
CREATE INDEX idx_staff_audit_log_professional_created
    ON core.staff_audit_log (professional_id, created_at DESC)
    WHERE professional_id IS NOT NULL;

-- Lock down. app_backend INSERT-only; admin/support staff read; nobody else.
--
-- Append-only is enforced at THREE layers (belt-and-suspenders, because the
-- schema-level grant in 20260403000000_v2_baseline.sql gives app_backend
-- UPDATE/DELETE on every core.* table by default — that grant alone would
-- allow accidental mutation of audit rows despite RLS intent):
--   1. Split RLS policies: FOR INSERT + FOR SELECT, never FOR ALL.
--   2. Explicit REVOKE UPDATE, DELETE — overrides the schema-level grant.
--   3. A BEFORE UPDATE OR DELETE trigger that raises unconditionally —
--      survives any future migration that re-grants privileges or replaces
--      the RLS policies. Mirrors commerce.order_events append-only discipline.
--
-- This is stricter than the existing core.*_audit tables (which use FOR ALL
-- and lean on application discipline). Tightened in response to SCHEMA-1 in
-- audits/ops-2-plan-audit/audit-2026-05-17-full.md.

ALTER TABLE core.staff_audit_log ENABLE ROW LEVEL SECURITY;

CREATE POLICY staff_audit_log_app_backend_insert
    ON core.staff_audit_log
    FOR INSERT
    TO app_backend
    WITH CHECK (true);

CREATE POLICY staff_audit_log_app_backend_select
    ON core.staff_audit_log
    FOR SELECT
    TO app_backend
    USING (true);

CREATE POLICY staff_audit_log_staff_select
    ON core.staff_audit_log
    FOR SELECT
    TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid()
          AND ps.role IN ('admin', 'support')
    ));

-- Layer 2: explicit revoke overrides the schema-level baseline grant from
-- 20260403000000_v2_baseline.sql (which granted app_backend UPDATE, DELETE).
-- The subsequent GRANT SELECT, INSERT is additive and only restores the
-- two operations we want app_backend to perform.
REVOKE UPDATE, DELETE ON core.staff_audit_log FROM app_backend;
GRANT SELECT, INSERT ON core.staff_audit_log TO app_backend;

-- Layer 3: trigger-level rejection. Catches anything the grant + policy layers
-- might miss after future migrations (e.g., a migration that re-runs the
-- baseline GRANT or replaces the policies). The trigger is unconditional —
-- there is no legitimate UPDATE/DELETE path on an append-only audit log.
CREATE OR REPLACE FUNCTION core.reject_staff_audit_log_mutation()
    RETURNS trigger
    LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'core.staff_audit_log is append-only (OPS-2). UPDATE and DELETE are not permitted.';
END;
$$;

CREATE TRIGGER staff_audit_log_reject_mutation
    BEFORE UPDATE OR DELETE ON core.staff_audit_log
    FOR EACH ROW
    EXECUTE FUNCTION core.reject_staff_audit_log_mutation();

COMMIT;

COMMENT ON TABLE core.staff_audit_log IS
    'OPS-2: append-only audit log of staff writes. One row per POST/PATCH/PUT/DELETE under /staff/*.';
COMMENT ON COLUMN core.staff_audit_log.payload_summary IS
    'Route bindings only by default (e.g., {"professional":"<uuid>"}). Body detail is opt-in per controller via StaffAuditService::record().';
COMMENT ON COLUMN core.staff_audit_log.impersonator_staff_id IS
    'Always NULL until OPS-1 (impersonation) ships. Column included from day one to avoid backfill.';
COMMENT ON COLUMN core.staff_audit_log.staff_email_snapshot IS
    'Frozen at insert. Survives staff hard-delete (FK is ON DELETE SET NULL).';
COMMENT ON COLUMN core.staff_audit_log.professional_handle_snapshot IS
    'Frozen at insert. Survives professional hard-delete (FK is ON DELETE SET NULL).';
COMMENT ON TRIGGER staff_audit_log_reject_mutation ON core.staff_audit_log IS
    'Append-only enforcement layer 3 of 3. Never drop without replacing — the table is intentionally immutable.';
```

- [ ] **Step 1.2: Verify migration parses locally**

Run: `php artisan tinker --execute="echo file_get_contents('supabase/migrations/20260517300000_create_staff_audit_log.sql');"`
Expected: file content printed. (We can't run the migration via Laravel — there's a guard against Laravel migrations. The user pushes Supabase migrations manually with `supabase db push`.)

- [ ] **Step 1.3: Commit just the migration**

```bash
git add supabase/migrations/20260517300000_create_staff_audit_log.sql
git commit -m "feat(audit): add core.staff_audit_log migration (OPS-2)"
```

> **Manual push step (Josh):** When the rest of this plan is green, run `supabase link --project-ref glncumufgaqcmqhzwrxm && supabase db push --dry-run && supabase db push` for dev. Production push waits until the feature has run on dev for at least a day.

---

## Task 2: Model + Factory

**Files:**
- Create: `app/Models/Core/Staff/StaffAuditEntry.php`
- Create: `database/factories/Core/Staff/StaffAuditEntryFactory.php`

- [ ] **Step 2.1: Write the failing test**

Create `tests/Unit/Models/StaffAuditEntryTest.php`:

```php
<?php

use App\Models\Core\Staff\StaffAuditEntry;

it('uses the core.staff_audit_log table', function () {
    expect((new StaffAuditEntry)->getTable())->toBe('core.staff_audit_log');
});

it('has uuid primary key with non-incrementing string type', function () {
    $model = new StaffAuditEntry;
    expect($model->incrementing)->toBeFalse()
        ->and($model->getKeyType())->toBe('string');
});

it('casts payload_summary to array and timestamps to datetime', function () {
    $model = new StaffAuditEntry;
    $casts = $model->getCasts();
    expect($casts)->toHaveKey('payload_summary', 'array')
        ->and($casts)->toHaveKey('created_at', 'datetime');
});

it('exposes a working factory', function () {
    expect(StaffAuditEntry::factory()->make())->toBeInstanceOf(StaffAuditEntry::class);
});
```

- [ ] **Step 2.2: Run the test, confirm failure**

Run: `php artisan test --compact tests/Unit/Models/StaffAuditEntryTest.php`
Expected: 4 failures with "Class StaffAuditEntry not found" or similar.

- [ ] **Step 2.3: Create the model**

Create `app/Models/Core/Staff/StaffAuditEntry.php`:

```php
<?php

namespace App\Models\Core\Staff;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// OPS-2: Append-only audit log of every staff write. One row inserted by
// App\Http\Middleware\Logging\RecordStaffAuditEntry after a /staff/* write
// response is sent. Body capture is deliberately omitted from the default
// path; payload_summary holds route bindings only.
class StaffAuditEntry extends BaseModel
{
    use HasFactory, HasUuids;

    protected $table = 'core.staff_audit_log';

    public $incrementing = false;

    protected $keyType = 'string';

    // No updated_at — this table is append-only. Laravel will still set
    // created_at automatically because UPDATED_AT is the only constant we
    // override to null.
    const UPDATED_AT = null;

    protected $fillable = [
        'staff_id',
        'staff_email_snapshot',
        'impersonator_staff_id',
        'impersonator_email_snapshot',
        'professional_id',
        'professional_handle_snapshot',
        'route',
        'http_method',
        'status_code',
        'payload_summary',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'payload_summary' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'staff_id');
    }

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'impersonator_staff_id');
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }
}
```

- [ ] **Step 2.4: Create the factory**

Create `database/factories/Core/Staff/StaffAuditEntryFactory.php`:

```php
<?php

namespace Database\Factories\Core\Staff;

use App\Models\Core\Staff\StaffAuditEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class StaffAuditEntryFactory extends Factory
{
    protected $model = StaffAuditEntry::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'staff_id' => (string) Str::uuid(),
            'staff_email_snapshot' => fake()->safeEmail(),
            'impersonator_staff_id' => null,
            'impersonator_email_snapshot' => null,
            'professional_id' => (string) Str::uuid(),
            'professional_handle_snapshot' => 'test-brand',
            'route' => 'staff.professionals.update',
            'http_method' => 'PATCH',
            'status_code' => 200,
            'payload_summary' => ['professional' => (string) Str::uuid()],
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now(),
        ];
    }
}
```

- [ ] **Step 2.5: Run the test, confirm pass**

Run: `php artisan test --compact tests/Unit/Models/StaffAuditEntryTest.php`
Expected: 4 PASS.

- [ ] **Step 2.6: Commit**

```bash
git add app/Models/Core/Staff/StaffAuditEntry.php \
        database/factories/Core/Staff/StaffAuditEntryFactory.php \
        tests/Unit/Models/StaffAuditEntryTest.php
git commit -m "feat(audit): add StaffAuditEntry model + factory"
```

---

## Task 3: `StaffAuditService` — the recording helper

**Files:**
- Create: `app/Services/Audit/StaffAuditService.php`
- Create: `tests/Unit/Services/Audit/StaffAuditServiceTest.php`

The service has exactly one public method — `record(...)`. The middleware calls it; future opt-in body-capture from controllers will call it directly with an extra `payload_summary` array.

**Method signature** (final, locked in before tests are written):

```php
public function record(
    ?PartnaStaff $staff,
    ?PartnaStaff $impersonator,
    ?Professional $professional,
    string $route,
    string $httpMethod,
    int $statusCode,
    array $payloadSummary = [],
    ?string $ip = null,
    ?string $userAgent = null,
): ?StaffAuditEntry
```

Returns the inserted `StaffAuditEntry`, or `null` if the insert was swallowed due to an exception (logged via `Log::warning('staff.audit.write_failed', ...)`).

- [ ] **Step 3.1: Write the failing service test**

Create `tests/Unit/Services/Audit/StaffAuditServiceTest.php`:

```php
<?php

use App\Models\Core\Professional\Professional;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\Staff\StaffAuditEntry;
use App\Services\Audit\StaffAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS core");
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.staff_audit_log (
        id TEXT PRIMARY KEY,
        staff_id TEXT,
        staff_email_snapshot TEXT,
        impersonator_staff_id TEXT,
        impersonator_email_snapshot TEXT,
        professional_id TEXT,
        professional_handle_snapshot TEXT,
        route TEXT NOT NULL,
        http_method TEXT NOT NULL,
        status_code INTEGER NOT NULL,
        payload_summary TEXT NOT NULL DEFAULT "{}",
        ip TEXT,
        user_agent TEXT,
        created_at TEXT
    )');
});

it('inserts a row capturing the staff, target, route, and method', function () {
    $staff = new PartnaStaff();
    $staff->id = (string) Str::uuid();
    $staff->primary_email = 'support@partna.au';
    $staff->role = PartnaStaff::ROLE_SUPPORT;

    $professional = new Professional();
    $professional->id = (string) Str::uuid();
    $professional->handle = 'acme-brand';

    $entry = (new StaffAuditService())->record(
        staff: $staff,
        impersonator: null,
        professional: $professional,
        route: 'staff.professionals.update',
        httpMethod: 'PATCH',
        statusCode: 200,
        payloadSummary: ['professional' => $professional->id],
        ip: '203.0.113.42',
        userAgent: 'PestTest',
    );

    expect($entry)->toBeInstanceOf(StaffAuditEntry::class)
        ->and($entry->staff_id)->toBe($staff->id)
        ->and($entry->staff_email_snapshot)->toBe('support@partna.au')
        ->and($entry->professional_id)->toBe($professional->id)
        ->and($entry->professional_handle_snapshot)->toBe('acme-brand')
        ->and($entry->route)->toBe('staff.professionals.update')
        ->and($entry->http_method)->toBe('PATCH')
        ->and($entry->status_code)->toBe(200)
        ->and($entry->payload_summary)->toBe(['professional' => $professional->id])
        ->and($entry->ip)->toBe('203.0.113.42')
        ->and($entry->user_agent)->toBe('PestTest');
});

it('accepts a null professional and null staff', function () {
    $entry = (new StaffAuditService())->record(
        staff: null,
        impersonator: null,
        professional: null,
        route: 'staff.commission-payouts.retry',
        httpMethod: 'POST',
        statusCode: 202,
    );

    expect($entry)->toBeInstanceOf(StaffAuditEntry::class)
        ->and($entry->staff_id)->toBeNull()
        ->and($entry->professional_id)->toBeNull()
        ->and($entry->payload_summary)->toBe([]);
});

it('swallows insert failures and returns null while logging a warning', function () {
    Log::spy();

    // Drop the table to force the insert to throw.
    DB::connection('pgsql')->statement('DROP TABLE core.staff_audit_log');

    $entry = (new StaffAuditService())->record(
        staff: null,
        impersonator: null,
        professional: null,
        route: 'staff.professionals.update',
        httpMethod: 'PATCH',
        statusCode: 200,
    );

    expect($entry)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) =>
            $message === 'staff.audit.write_failed'
            && isset($context['exception'])
        );
});
```

- [ ] **Step 3.2: Run the test, confirm failure**

Run: `php artisan test --compact tests/Unit/Services/Audit/StaffAuditServiceTest.php`
Expected: 3 failures — "Class StaffAuditService not found".

- [ ] **Step 3.3: Implement the service**

Create `app/Services/Audit/StaffAuditService.php`:

```php
<?php

namespace App\Services\Audit;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\Staff\StaffAuditEntry;
use Illuminate\Support\Facades\Log;
use Throwable;

// OPS-2: writes one row per staff write to core.staff_audit_log.
// Invoked by RecordStaffAuditEntry middleware after the response is sent.
// May also be called directly from controllers that want to record extra
// body-detail forensics (e.g., previous_media_id / new_media_id on uploads).
//
// Failure mode: if the insert throws, we log a warning and return null —
// audit-log unavailability must never block a staff action.
class StaffAuditService
{
    public function record(
        ?PartnaStaff $staff,
        ?PartnaStaff $impersonator,
        ?Professional $professional,
        string $route,
        string $httpMethod,
        int $statusCode,
        array $payloadSummary = [],
        ?string $ip = null,
        ?string $userAgent = null,
    ): ?StaffAuditEntry {
        try {
            return StaffAuditEntry::query()->create([
                'staff_id' => $staff?->id,
                'staff_email_snapshot' => $staff?->primary_email,
                'impersonator_staff_id' => $impersonator?->id,
                'impersonator_email_snapshot' => $impersonator?->primary_email,
                'professional_id' => $professional?->id,
                'professional_handle_snapshot' => $professional?->handle,
                'route' => $route,
                'http_method' => $httpMethod,
                'status_code' => $statusCode,
                'payload_summary' => $payloadSummary,
                'ip' => $ip,
                'user_agent' => $userAgent,
            ]);
        } catch (Throwable $e) {
            Log::warning('staff.audit.write_failed', [
                'exception' => $e->getMessage(),
                'route' => $route,
                'http_method' => $httpMethod,
            ]);

            return null;
        }
    }
}
```

- [ ] **Step 3.4: Run the test, confirm pass**

Run: `php artisan test --compact tests/Unit/Services/Audit/StaffAuditServiceTest.php`
Expected: 3 PASS.

- [ ] **Step 3.5: Commit**

```bash
git add app/Services/Audit/StaffAuditService.php \
        tests/Unit/Services/Audit/StaffAuditServiceTest.php
git commit -m "feat(audit): add StaffAuditService with fail-soft record()"
```

---

## Task 4: `RecordStaffAuditEntry` middleware

**Files:**
- Create: `app/Http/Middleware/Logging/RecordStaffAuditEntry.php`
- Create: `tests/Feature/Middleware/RecordStaffAuditEntryTest.php`

**Middleware contract** (locked in before tests):

- Implements `terminate(Request $request, Response $response)` — runs **after** the response is sent.
- Skips entirely if `$request->method()` is not in `['POST', 'PATCH', 'PUT', 'DELETE']`.
- Resolves the actor from `$request->attributes->get('partna_staff')` (set by `EnsurePartnaStaff` upstream). If absent, sets `staff = null` and still records (the row will show "unauthenticated write attempt", which is useful forensics).
- Resolves the target from `$request->route('professional')` — accepts either the Eloquent `Professional` instance (when route-model binding is in effect) or a string UUID (when no binding). Snapshots the handle when the model is available.
- `payload_summary` is `$request->route()->parameters()` filtered to scalar values only. Route-model-bound parameters are reduced to `{key => $model->id}`. No request body is read.
- Catches all `Throwable` — even a misconfigured middleware run should never crash the request after-image.

- [ ] **Step 4.1: Write the failing middleware test**

Create `tests/Feature/Middleware/RecordStaffAuditEntryTest.php`:

```php
<?php

use App\Http\Middleware\Logging\RecordStaffAuditEntry;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\Staff\StaffAuditEntry;
use App\Services\Audit\StaffAuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS core");
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.staff_audit_log (
        id TEXT PRIMARY KEY,
        staff_id TEXT,
        staff_email_snapshot TEXT,
        impersonator_staff_id TEXT,
        impersonator_email_snapshot TEXT,
        professional_id TEXT,
        professional_handle_snapshot TEXT,
        route TEXT NOT NULL,
        http_method TEXT NOT NULL,
        status_code INTEGER NOT NULL,
        payload_summary TEXT NOT NULL DEFAULT "{}",
        ip TEXT,
        user_agent TEXT,
        created_at TEXT
    )');
});

function makeStaffRequest(string $method, string $uri, array $bindings = []): Request
{
    $request = Request::create($uri, $method);
    $route = new RoutingRoute([$method], $uri, fn () => null);
    $route->name('staff.professionals.update');
    $route->parameters = $bindings;
    $request->setRouteResolver(fn () => $route);

    return $request;
}

it('records a row for POST/PATCH/PUT/DELETE writes', function (string $method) {
    $staff = new PartnaStaff();
    $staff->id = (string) Str::uuid();
    $staff->primary_email = 'support@partna.au';

    $professional = new Professional();
    $professional->id = (string) Str::uuid();
    $professional->handle = 'acme';

    $request = makeStaffRequest($method, '/staff/professionals/'.$professional->id, [
        'professional' => $professional,
    ]);
    $request->attributes->set('partna_staff', $staff);

    $middleware = new RecordStaffAuditEntry(new StaffAuditService());

    $response = new Response('', 200);
    $middleware->terminate($request, $response);

    expect(StaffAuditEntry::query()->count())->toBe(1);
    $row = StaffAuditEntry::query()->first();
    expect($row->http_method)->toBe($method)
        ->and($row->staff_id)->toBe($staff->id)
        ->and($row->professional_id)->toBe($professional->id)
        ->and($row->professional_handle_snapshot)->toBe('acme')
        ->and($row->status_code)->toBe(200)
        ->and($row->payload_summary)->toBe(['professional' => $professional->id]);
})->with(['POST', 'PATCH', 'PUT', 'DELETE']);

it('skips GET/HEAD/OPTIONS requests', function (string $method) {
    $request = makeStaffRequest($method, '/staff/professionals');
    $middleware = new RecordStaffAuditEntry(new StaffAuditService());
    $middleware->terminate($request, new Response('', 200));

    expect(StaffAuditEntry::query()->count())->toBe(0);
})->with(['GET', 'HEAD', 'OPTIONS']);

it('records the row even when status code is 4xx', function () {
    $staff = new PartnaStaff();
    $staff->id = (string) Str::uuid();

    $request = makeStaffRequest('DELETE', '/staff/professionals/123/force', [
        'professional' => '123',
    ]);
    $request->attributes->set('partna_staff', $staff);

    $middleware = new RecordStaffAuditEntry(new StaffAuditService());
    $middleware->terminate($request, new Response('Forbidden', 403));

    $row = StaffAuditEntry::query()->first();
    expect($row)->not->toBeNull()
        ->and($row->status_code)->toBe(403);
});

it('records a null staff_id when partna_staff is missing from the request', function () {
    $request = makeStaffRequest('POST', '/staff/notifications');
    // No partna_staff attribute — simulating a write that somehow got past auth.

    $middleware = new RecordStaffAuditEntry(new StaffAuditService());
    $middleware->terminate($request, new Response('', 200));

    $row = StaffAuditEntry::query()->first();
    expect($row)->not->toBeNull()
        ->and($row->staff_id)->toBeNull();
});

it('accepts a string professional binding when route-model binding is not in effect', function () {
    $staff = new PartnaStaff();
    $staff->id = (string) Str::uuid();

    $professionalId = (string) Str::uuid();
    $request = makeStaffRequest('PATCH', '/staff/professionals/'.$professionalId, [
        'professional' => $professionalId,
    ]);
    $request->attributes->set('partna_staff', $staff);

    $middleware = new RecordStaffAuditEntry(new StaffAuditService());
    $middleware->terminate($request, new Response('', 200));

    $row = StaffAuditEntry::query()->first();
    expect($row->professional_id)->toBe($professionalId)
        ->and($row->professional_handle_snapshot)->toBeNull();
});

it('serialises route bindings to scalar UUIDs in payload_summary', function () {
    $staff = new PartnaStaff();
    $staff->id = (string) Str::uuid();

    $professional = new Professional();
    $professional->id = (string) Str::uuid();
    $professional->handle = 'acme';

    $request = makeStaffRequest('PATCH', '/staff/professionals/'.$professional->id.'/services/abc', [
        'professional' => $professional,
        'service' => 'service-uuid-abc',
    ]);
    $request->attributes->set('partna_staff', $staff);

    $middleware = new RecordStaffAuditEntry(new StaffAuditService());
    $middleware->terminate($request, new Response('', 200));

    $row = StaffAuditEntry::query()->first();
    expect($row->payload_summary)->toBe([
        'professional' => $professional->id,
        'service' => 'service-uuid-abc',
    ]);
});
```

- [ ] **Step 4.2: Run the test, confirm failure**

Run: `php artisan test --compact tests/Feature/Middleware/RecordStaffAuditEntryTest.php`
Expected: All tests fail — "Class RecordStaffAuditEntry not found".

- [ ] **Step 4.3: Implement the middleware**

Create `app/Http/Middleware/Logging/RecordStaffAuditEntry.php`:

```php
<?php

namespace App\Http\Middleware\Logging;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Staff\PartnaStaff;
use App\Services\Audit\StaffAuditService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// OPS-2: append-only audit log middleware. Attached to both /staff/* route
// groups. Fires in terminate() so the audit insert never adds latency to the
// response, and swallows all errors — audit-log outages must not block staff.
//
// Captures actor, target, route, method, status, route bindings, IP, UA.
// Deliberately does NOT capture request body — body-detail forensics is opt-in
// per controller via StaffAuditService::record(['payload_summary' => [...]]).
class RecordStaffAuditEntry
{
    private const WRITE_METHODS = ['POST', 'PATCH', 'PUT', 'DELETE'];

    public function __construct(private readonly StaffAuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! in_array($request->method(), self::WRITE_METHODS, true)) {
            return;
        }

        try {
            $staff = $request->attributes->get('partna_staff');
            $staff = $staff instanceof PartnaStaff ? $staff : null;

            $professionalParam = $request->route()?->parameter('professional');
            $professional = $professionalParam instanceof Professional ? $professionalParam : null;
            $professionalIdFromString = (is_string($professionalParam) && $professionalParam !== '')
                ? $professionalParam
                : null;

            $this->audit->record(
                staff: $staff,
                impersonator: null,
                professional: $professional,
                route: $request->route()?->getName() ?? ($request->route()?->uri() ?? $request->path()),
                httpMethod: $request->method(),
                statusCode: $response->getStatusCode(),
                payloadSummary: $this->summariseBindings($request, $professionalIdFromString),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (Throwable $e) {
            // Belt-and-suspenders — StaffAuditService already catches DB errors,
            // but this guards against parameter-resolution issues we haven't
            // anticipated.
            Log::warning('staff.audit.middleware_failed', [
                'exception' => $e->getMessage(),
                'route' => $request->path(),
            ]);
        }
    }

    /**
     * Reduce route parameters to a scalar map: Eloquent models become their ID,
     * scalars pass through. Non-scalar, non-Model values are dropped.
     *
     * @return array<string, string|int|bool|float>
     */
    private function summariseBindings(Request $request, ?string $professionalIdFromString): array
    {
        $params = $request->route()?->parameters() ?? [];
        $summary = [];

        foreach ($params as $key => $value) {
            if ($value instanceof Model) {
                $summary[$key] = (string) $value->getKey();
            } elseif (is_scalar($value)) {
                $summary[$key] = $value;
            }
        }

        // Backstop: if the professional came through as a raw string we
        // already captured it for the FK field, but route()->parameters()
        // returns the same thing so this is usually a no-op.
        if ($professionalIdFromString !== null && ! isset($summary['professional'])) {
            $summary['professional'] = $professionalIdFromString;
        }

        return $summary;
    }
}
```

- [ ] **Step 4.4: Run the test, confirm pass**

Run: `php artisan test --compact tests/Feature/Middleware/RecordStaffAuditEntryTest.php`
Expected: All tests PASS.

- [ ] **Step 4.5: Run Pint**

Run: `vendor/bin/pint --dirty`
Expected: any formatting nits applied silently. If style errors are introduced, Pint fixes them in place.

- [ ] **Step 4.6: Commit**

```bash
git add app/Http/Middleware/Logging/RecordStaffAuditEntry.php \
        tests/Feature/Middleware/RecordStaffAuditEntryTest.php
git commit -m "feat(audit): add RecordStaffAuditEntry middleware (terminate-phase, body-free)"
```

---

## Task 5: Register alias + wire into route groups

**Files:**
- Modify: `bootstrap/app.php`
- Modify: `routes/api/staff.php`

The middleware is attached to **both** groups (read and admin write). The middleware filters by HTTP method internally, so attaching it to the read group is a no-op today — `GET` requests early-return in `terminate()`. We attach it there too so that if a write endpoint is ever added to the read group by accident (e.g., a future `POST /staff/...` that doesn't need admin role), the audit row is still captured.

- [ ] **Step 5.1: Add the alias in `bootstrap/app.php`**

Open `bootstrap/app.php` and add the import + alias.

Add the import near the top, alphabetically with the others:

```php
use App\Http\Middleware\Logging\RecordStaffAuditEntry;
```

Modify the `$middleware->alias([...])` block at line 79–93 to include the new entry:

```php
$middleware->alias([
    'supabase.jwt' => VerifySupabaseJwt::class,
    'current.pro' => LoadCurrentProfessional::class,
    'staff' => EnsurePartnaStaff::class,
    'staff.admin' => EnsurePartnaAdmin::class,
    'staff.audit' => RecordStaffAuditEntry::class,
    'lead.log' => LogLeadRateLimits::class,
    'plan' => RequirePlan::class,
    'hydrogen.key' => VerifyHydrogenApiKey::class,
    'shopify.session' => VerifyShopifySessionToken::class,
    'feature' => FeatureGate::class,
    'captcha' => VerifyTurnstileCaptcha::class,
    'brand-funding-gate' => BrandFundingGate::class,
    'brand.only' => \App\Http\Middleware\EnsureBrandAccount::class,
    'affiliate.only' => \App\Http\Middleware\EnsureAffiliateAccount::class,
]);
```

- [ ] **Step 5.2: Wire middleware into both staff route groups**

In `routes/api/staff.php` line 50, change the read group middleware array from:

```php
->middleware(['supabase.jwt', 'staff', 'throttle:staff'])
```

to:

```php
->middleware(['supabase.jwt', 'staff', 'throttle:staff', 'staff.audit'])
```

In line 225, change the admin write group middleware array from:

```php
->middleware(['supabase.jwt', 'staff', 'staff.admin', 'throttle:staff'])
```

to:

```php
->middleware(['supabase.jwt', 'staff', 'staff.admin', 'throttle:staff', 'staff.audit'])
```

> **Why `'staff.audit'` goes last in both arrays:** Laravel resolves middleware top-to-bottom for `handle()` and bottom-to-top for `terminate()`. Putting `staff.audit` last makes its `terminate()` fire first (after the response) which is desirable — if any other middleware throws in its `terminate()`, ours still has the partna_staff attribute set on the request.

- [ ] **Step 5.3: Verify the routes still parse**

Run: `php artisan route:list --path=staff | head -30`
Expected: routes listed without exceptions; middleware column shows `staff.audit` on `/staff/*` routes.

- [ ] **Step 5.4: Commit**

```bash
git add bootstrap/app.php routes/api/staff.php
git commit -m "feat(audit): wire staff.audit middleware to both staff route groups"
```

---

## Task 6: Integration test — real staff write end-to-end

**Files:**
- Create: `tests/Feature/Staff/StaffAuditLogIntegrationTest.php`

This test hits a real staff write endpoint via the Laravel test client and asserts an audit row was inserted. It validates the full chain: JWT verification → EnsurePartnaStaff → controller → terminate-phase audit write.

The endpoint chosen is `PATCH /staff/professionals/{professional}` (admin-notes update) — it's the simplest existing staff admin write, and there's already test scaffolding for it in `tests/Feature/Staff/StaffAdminNotesTest.php` that we can mirror.

- [ ] **Step 6.1: Write the integration test**

Create `tests/Feature/Staff/StaffAuditLogIntegrationTest.php`:

```php
<?php

use App\Models\Core\Professional\Professional;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\Staff\StaffAuditEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS core");
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY,
        handle TEXT,
        display_name TEXT,
        professional_type TEXT,
        status TEXT,
        admin_notes TEXT,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.partna_staff (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT,
        role TEXT,
        primary_email TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.staff_audit_log (
        id TEXT PRIMARY KEY,
        staff_id TEXT,
        staff_email_snapshot TEXT,
        impersonator_staff_id TEXT,
        impersonator_email_snapshot TEXT,
        professional_id TEXT,
        professional_handle_snapshot TEXT,
        route TEXT NOT NULL,
        http_method TEXT NOT NULL,
        status_code INTEGER NOT NULL,
        payload_summary TEXT NOT NULL DEFAULT "{}",
        ip TEXT,
        user_agent TEXT,
        created_at TEXT
    )');
});

it('inserts an audit row when staff PATCHes a professional', function () {
    $authUid = (string) Str::uuid();

    DB::table('core.partna_staff')->insert([
        'id' => $staffId = (string) Str::uuid(),
        'auth_user_id' => $authUid,
        'role' => 'admin',
        'primary_email' => 'admin@partna.au',
    ]);

    DB::table('core.professionals')->insert([
        'id' => $professionalId = (string) Str::uuid(),
        'handle' => 'acme',
        'display_name' => 'Acme Brand',
        'professional_type' => 'brand',
        'status' => 'active',
    ]);

    // Stub Supabase JWT verification via the same attribute it sets — handled in
    // tests by directly setting supabase_uid on the request through the
    // testing helper. Match whatever the project's existing staff feature tests do.
    $response = $this
        ->withHeaders(['Authorization' => 'Bearer fake-staff-jwt'])
        ->withServerVariables(['HTTP_X_TEST_SUPABASE_UID' => $authUid])
        ->patchJson("/api/staff/professionals/{$professionalId}", [
            'admin_notes' => 'VIP — do not suspend',
        ]);

    $response->assertSuccessful();

    $row = StaffAuditEntry::query()->first();
    expect($row)->not->toBeNull()
        ->and($row->staff_id)->toBe($staffId)
        ->and($row->staff_email_snapshot)->toBe('admin@partna.au')
        ->and($row->professional_id)->toBe($professionalId)
        ->and($row->professional_handle_snapshot)->toBe('acme')
        ->and($row->http_method)->toBe('PATCH')
        ->and($row->status_code)->toBe(200);
});

it('does NOT insert an audit row when staff GETs a professional', function () {
    $authUid = (string) Str::uuid();

    DB::table('core.partna_staff')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => $authUid,
        'role' => 'support',
        'primary_email' => 'support@partna.au',
    ]);

    DB::table('core.professionals')->insert([
        'id' => $professionalId = (string) Str::uuid(),
        'handle' => 'acme',
        'display_name' => 'Acme Brand',
        'professional_type' => 'brand',
        'status' => 'active',
    ]);

    $this
        ->withHeaders(['Authorization' => 'Bearer fake-staff-jwt'])
        ->withServerVariables(['HTTP_X_TEST_SUPABASE_UID' => $authUid])
        ->getJson("/api/staff/professionals/{$professionalId}")
        ->assertSuccessful();

    expect(StaffAuditEntry::query()->count())->toBe(0);
});
```

**If the project doesn't use `HTTP_X_TEST_SUPABASE_UID`** (it's a hypothetical helper), check `tests/Feature/Staff/StaffAdminNotesTest.php`, `tests/Feature/Staff/EnsurePartnaStaffMiddlewareTest.php`, and `tests/TestCase.php` for the real test-side JWT shim and copy it. The Pest helpers `$this->withHeaders(...)` and `$this->patchJson(...)` are standard Laravel — the only project-specific piece is the JWT bypass.

- [ ] **Step 6.2: Run the test, confirm pass**

Run: `php artisan test --compact tests/Feature/Staff/StaffAuditLogIntegrationTest.php`
Expected: 2 PASS.

If the JWT shim isn't working: pause, read `tests/Feature/Staff/StaffAdminNotesTest.php` and `tests/Feature/Staff/EnsurePartnaStaffMiddlewareTest.php` to learn how those tests pass through `EnsurePartnaStaff`, then update the test setup to match.

- [ ] **Step 6.3: Commit**

```bash
git add tests/Feature/Staff/StaffAuditLogIntegrationTest.php
git commit -m "test(audit): cover staff PATCH end-to-end through audit middleware"
```

---

## Task 7: Final verification + push to dev

- [ ] **Step 7.1: Run the full audit test set**

Run:
```bash
php artisan test --compact \
    tests/Unit/Models/StaffAuditEntryTest.php \
    tests/Unit/Services/Audit/StaffAuditServiceTest.php \
    tests/Feature/Middleware/RecordStaffAuditEntryTest.php \
    tests/Feature/Staff/StaffAuditLogIntegrationTest.php
```
Expected: all PASS.

- [ ] **Step 7.2: Sanity-check that other staff tests still pass**

Run: `php artisan test --compact tests/Feature/Staff/StaffAdminNotesTest.php`
Expected: PASS. (If this fails, the new middleware is interfering with existing staff flows — debug before continuing.)

- [ ] **Step 7.3: Run the broader feature suite**

Run: `php artisan test --compact tests/Feature/Staff/`
Expected: no regressions. New middleware should be inert against all existing tests because no test sets up the `core.staff_audit_log` table — the service swallows the missing-table error and returns null.

> If staff tests fail because the audit middleware crashes when `core.staff_audit_log` doesn't exist in their SQLite setup: confirm the `StaffAuditService` catches the throw (Task 3.3 test #3). If it does and tests still fail, it likely means tests are asserting `Log::warning` counts and our warning is being counted — adjust the warning channel or filter out the audit warning in those tests.

- [ ] **Step 7.4: Confirm Pint is clean**

Run: `vendor/bin/pint --dirty`
Expected: "All files already formatted" or a small number of auto-applied changes.

- [ ] **Step 7.5: Push the migration to dev Supabase (Josh)**

Josh runs these — not the agent:

```
! supabase link --project-ref glncumufgaqcmqhzwrxm
! supabase db push --dry-run
! supabase db push
```

Verify the dry-run output names exactly one migration: `20260517300000_create_staff_audit_log.sql`.

- [ ] **Step 7.6: Manual smoke test on dev**

Hit a staff write on dev-api.partna.au (e.g., `PATCH /api/staff/professionals/{id}` from the staff dashboard) and confirm a row landed:

```sql
SELECT * FROM core.staff_audit_log ORDER BY created_at DESC LIMIT 5;
```

Expected: one row matching the action you just took. `staff_email_snapshot` should be your admin email; `professional_handle_snapshot` should be the brand's handle.

- [ ] **Step 7.7: Verify append-only enforcement on dev (SCHEMA-1 hardening)**

Run these three queries in the Supabase SQL editor on dev. Each must fail with the exception message from the trigger — that's how we know all three layers are intact.

```sql
-- Should fail: "core.staff_audit_log is append-only (OPS-2). UPDATE and DELETE are not permitted."
UPDATE core.staff_audit_log SET status_code = 999 WHERE id = (SELECT id FROM core.staff_audit_log LIMIT 1);

-- Should fail with the same exception.
DELETE FROM core.staff_audit_log WHERE id = (SELECT id FROM core.staff_audit_log LIMIT 1);

-- Should fail with "permission denied" (grant layer, before the trigger even fires).
-- Run as the app_backend role if possible — confirms the REVOKE took effect.
-- If the SQL editor runs as the table owner, this query may succeed and only the trigger
-- will block, which is still acceptable — the trigger is the load-bearing defence.
```

Expected: at least the first two queries fail with the trigger exception. If either succeeds, the migration didn't apply correctly — investigate `pg_trigger` for the staff_audit_log table before continuing.

---

## Self-review checklist

Before handing off:

- **Spec coverage:** every column in the OPS-2 spec at `audits/open/audit-2026-05-08-staff-admin-coverage.md:141` is in the migration. ✓
- **Impersonator support:** column included from day one, always-null until OPS-1. ✓
- **B7 dependency:** middleware is attached to the admin write group, so all of B7's upload endpoints are auto-logged the moment they ship. ✓
- **Scrub problem:** non-existent — no body is captured. ✓
- **Latency impact:** zero — middleware writes in `terminate()`. ✓
- **Failure mode:** documented and tested — exceptions during write are swallowed and logged. ✓
- **RLS:** stricter than `core.professional_deletion_audit` (which uses `FOR ALL`). Split `FOR INSERT` + `FOR SELECT` policies, explicit `REVOKE UPDATE, DELETE`, plus a rejection trigger. Three-layer append-only enforcement per SCHEMA-1 of `audits/ops-2-plan-audit/audit-2026-05-17-full.md`. ✓
- **Append-only enforcement verified post-deploy:** Step 7.7 runs UPDATE and DELETE attempts against the dev table and asserts they fail with the trigger exception. ✓
- **No `php artisan make:migration`:** migration is hand-written SQL in `supabase/migrations/`, per CLAUDE.md "Never create Laravel migration files." ✓
- **No Resource class on the audit log:** we never expose audit rows over an API; they're queried directly by support via SQL. If a future "audit log UI" is needed, add a `StaffAuditEntryResource` at that time — YAGNI today. ✓

---

## Out of scope (explicitly NOT in this plan)

- **Body-detail capture for upload endpoints (B7).** Once B7 is implemented, individual upload controllers can opt-in to richer `payload_summary` via `StaffAuditService::record([...])` calls with `previous_media_id` / `new_media_id`. That work belongs in the B7 plan, not here.
- **OPS-1 impersonation.** The column is reserved; the implementation waits for its own ticket.
- **Audit log UI surface.** No admin dashboard reads `core.staff_audit_log` yet — query directly via SQL when support needs forensics. Add a UI when frequency justifies it.
- **Retention/partitioning.** Append-only; address when row count crosses ~10M.
- **Backfill of historical staff actions.** No data exists prior to this migration; nothing to backfill.
- **Production push.** Dev push happens in Step 7.5; promote to prod after at least 24h of dev observation.
