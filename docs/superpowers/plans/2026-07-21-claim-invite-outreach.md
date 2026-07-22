# Claim-Invite Outreach Extensions — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add three extensions to the already-shipped claim-notify system: send idempotency (`invited_at`), a review-before-send toggle (`auto_invite`) with a manual send endpoint, and a CSV batch build endpoint.

**Architecture:** Extend, don't rebuild. `ClaimInviteMail` + `ClaimNotifier` + the email-gated claim already ship. We add two columns to `core.pre_account_builds`, make `ClaimNotifier` idempotent, gate the existing auto-send-on-publish behind `auto_invite`, and add two thin staff endpoints (`POST /builds/{build}/invite`, `POST /builds/batch`) that reuse `PreAccountBuildService::requestBuild` and `ClaimNotifier`.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4, PostgreSQL (Supabase) prod / SQLite in-memory tests, raw SQL migrations in `supabase/migrations/`.

## Global Constraints

- **No Laravel migrations** — schema changes are raw SQL in `supabase/migrations/` (composer guard rejects Laravel migrations).
- **Tests run SQLite, prod is Postgres** — every new column must also be added to the SQLite stub in `tests/Pest.php` (`setupPreAccountBuildsTable()`), and constraint-bound writes verified against the raw Postgres DDL.
- **Authorization via policy** — use `authorizeForUser($staff, 'staffCreate', PreAccountBuild::class)`, never inline 403. `PreAccountBuildPolicy::staffCreate` already returns `true` for any staff role; reuse it.
- **`build_state` / `claimed_at` / `invited_at` are NOT fillable** — write them via `forceFill([...])->save()` (a dropped write here is a silent no-op).
- **Default behaviour must not change** — `auto_invite` defaults `true`, which preserves today's auto-send-on-publish exactly.
- Run `composer test` and `php artisan pint` before declaring done. Commit after each task.

---

### Task 1: Add `invited_at` + `auto_invite` columns

**Files:**
- Create: `supabase/migrations/20260721130000_claim_invite_outreach.sql`
- Modify: `app/Models/Core/User/PreAccountBuild.php`
- Modify: `tests/Pest.php:379-387` (the defensive ALTER loop in `setupPreAccountBuildsTable()`)
- Test: `tests/Feature/PreAccount/PreAccountBuildOutreachColumnsTest.php`

**Interfaces:**
- Produces: `PreAccountBuild->invited_at` (`Carbon|null`, not fillable), `PreAccountBuild->auto_invite` (`bool`, fillable, default `true`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PreAccount/PreAccountBuildOutreachColumnsTest.php`:

```php
<?php

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
});

it('defaults auto_invite to true and invited_at to null', function () {
    $user = User::factory()->create(['status' => 'unclaimed']);
    $build = PreAccountBuild::factory()->make();
    $build->user()->associate($user);
    $build->save();

    $fresh = $build->fresh();
    expect($fresh->auto_invite)->toBeTrue()
        ->and($fresh->invited_at)->toBeNull();
});

it('casts auto_invite to boolean and is mass-assignable', function () {
    $user = User::factory()->create(['status' => 'unclaimed']);
    $build = PreAccountBuild::factory()->make(['auto_invite' => false]);
    $build->user()->associate($user);
    $build->save();

    expect($build->fresh()->auto_invite)->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/PreAccount/PreAccountBuildOutreachColumnsTest.php`
Expected: FAIL — `auto_invite` is not mass-assignable / column missing, so it is null/unset.

- [ ] **Step 3: Write the migration**

Create `supabase/migrations/20260721130000_claim_invite_outreach.sql`:

```sql
-- Claim-invite outreach extensions (spec 2026-07-21-claim-invite-outreach-design).
-- + invited_at  — send idempotency + "already invited" signal (ClaimNotifier stamps it)
-- + auto_invite — false = publish the site but DEFER the invite for manual review + send

BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE core.pre_account_builds
    ADD COLUMN IF NOT EXISTS invited_at  timestamptz NULL,
    ADD COLUMN IF NOT EXISTS auto_invite boolean NOT NULL DEFAULT true;

COMMIT;
```

- [ ] **Step 4: Update the model**

In `app/Models/Core/User/PreAccountBuild.php`, add two `@property` lines to the docblock (after the `contact_email` line, ~L26):

```php
 * @property Carbon|null $invited_at When the claim invite was sent (ClaimNotifier stamps it after queueing the mail). NULL = not yet invited — the idempotency guard.
 * @property bool $auto_invite false = publish the site but DEFER the claim invite for manual review + POST /builds/{build}/invite. Default true = auto-send on publish (unchanged).
```

Add `'auto_invite'` to `$fillable` (invited_at stays OUT — written via forceFill):

```php
    protected $fillable = [
        'source_type', 'source_ref', 'source_ref_lc', 'built_via',
        'created_ip_hash', 'expires_at', 'contact_email', 'auto_invite',
    ];
```

Add casts:

```php
    protected $casts = [
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
        'invited_at' => 'datetime',
        'auto_invite' => 'boolean',
    ];
```

- [ ] **Step 5: Update the SQLite test stub**

In `tests/Pest.php`, extend the defensive ALTER loop in `setupPreAccountBuildsTable()` (currently `foreach (['contact_email TEXT NULL'] as $col)`):

```php
    foreach ([
        'contact_email TEXT NULL',
        'invited_at TEXT NULL',
        'auto_invite INTEGER NOT NULL DEFAULT 1',
    ] as $col) {
        try {
            DB::connection('pgsql')->statement('ALTER TABLE core.pre_account_builds ADD COLUMN '.$col);
        } catch (Throwable $e) {
            // already exists — ignore
        }
    }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/PreAccount/PreAccountBuildOutreachColumnsTest.php`
Expected: PASS (both tests).

- [ ] **Step 7: Commit**

```bash
git add supabase/migrations/20260721130000_claim_invite_outreach.sql app/Models/Core/User/PreAccountBuild.php tests/Pest.php tests/Feature/PreAccount/PreAccountBuildOutreachColumnsTest.php
git commit -m "feat(pre-account): add invited_at + auto_invite columns"
```

---

### Task 2: Make `ClaimNotifier` idempotent and stamp `invited_at`

**Files:**
- Modify: `app/Services/PreAccount/ClaimNotifier.php`
- Test: `tests/Feature/PreAccount/ClaimNotifierTest.php` (add cases)

**Interfaces:**
- Consumes: `PreAccountBuild->invited_at`, `PreAccountBuild->contact_email`.
- Produces: `ClaimNotifier::notify()` sends at most once per build; stamps `invited_at` only when an email is actually queued.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PreAccount/ClaimNotifierTest.php`:

```php
it('stamps invited_at and does not re-send on a second notify', function () {
    Mail::fake();
    $user = User::factory()->create(['status' => 'unclaimed']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe']);
    $build = PreAccountBuild::factory()->make(['contact_email' => 'lead@example.com']);
    $build->user()->associate($user);
    $build->save();

    app(ClaimNotifier::class)->notify($build->fresh());
    expect($build->fresh()->invited_at)->not->toBeNull();

    app(ClaimNotifier::class)->notify($build->fresh());

    Mail::assertQueued(ClaimInviteMail::class, 1); // exactly one, not two
});

it('does not stamp invited_at when there is no contact_email', function () {
    Mail::fake();
    $user = User::factory()->create(['status' => 'unclaimed']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe']);
    $build = PreAccountBuild::factory()->make(['contact_email' => null]);
    $build->user()->associate($user);
    $build->save();

    app(ClaimNotifier::class)->notify($build->fresh());

    expect($build->fresh()->invited_at)->toBeNull();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Feature/PreAccount/ClaimNotifierTest.php`
Expected: FAIL — `invited_at` is null after notify (no stamping yet); second notify queues a second mail.

- [ ] **Step 3: Implement idempotency + stamping**

Replace the body of `notify()` in `app/Services/PreAccount/ClaimNotifier.php`:

```php
    public function notify(PreAccountBuild $build): void
    {
        // Idempotency: a job retry or a re-publish must not re-send (spec §3).
        if ($build->invited_at !== null) {
            return;
        }

        $site = $build->user?->site;
        if ($site === null) {
            return;
        }

        $claimUrl = rtrim((string) config('app.frontend_url'), '/').'/claim/'.$site->subdomain;

        $sent = false;
        if ($build->contact_email !== null && trim($build->contact_email) !== '') {
            Mail::queue(new ClaimInviteMail($build->contact_email, $claimUrl));
            $sent = true;
        }

        // DM channel: no-op stub today (spec §3.1 deferred seam).
        $this->dm->send($build, $claimUrl);

        // Stamp only when an email actually went out, and AFTER queueing so a
        // transport failure leaves the build re-sendable. A first-come build
        // (no contact_email) stays un-stamped so a later-added email can still
        // be invited via POST /builds/{build}/invite.
        if ($sent) {
            $build->forceFill(['invited_at' => now()])->save();
        }
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test tests/Feature/PreAccount/ClaimNotifierTest.php`
Expected: PASS (all four tests, including the two pre-existing ones).

- [ ] **Step 5: Commit**

```bash
git add app/Services/PreAccount/ClaimNotifier.php tests/Feature/PreAccount/ClaimNotifierTest.php
git commit -m "feat(pre-account): idempotent ClaimNotifier stamps invited_at"
```

---

### Task 3: `auto_invite` plumbing + gate the auto-send

**Files:**
- Modify: `app/Services/PreAccount/PreAccountBuildService.php` (`requestBuild` signature + build array + transaction `use`)
- Modify: `app/Http/Requests/Api/Staff/UserSite/StaffCreatePreAccountBuildRequest.php`
- Modify: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php` (`store`)
- Modify: `app/Jobs/PreAccount/GeneratePreAccountSiteJob.php:112-120`
- Test: `tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php` (add case), `tests/Feature/PreAccount/StaffBuildEndpointTest.php` (add case)

**Interfaces:**
- Consumes: `PreAccountBuild->auto_invite` (Task 1), idempotent `ClaimNotifier::notify` (Task 2).
- Produces: `requestBuild(..., bool $autoInvite = true)`; the auto-send at `GeneratePreAccountSiteJob` now fires only when `publish && $build->auto_invite`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php` (reuse the `SiteSourceGenerator` anon-class stub pattern already in that file — copy the `$this->mock(SourceGeneratorRegistry::class, ...)` block from the "notifies via email..." test at L101-122):

```php
it('does not notify when auto_invite is false even if published', function () {
    Mail::fake();
    Queue::fake([SyncSubdomainToKvJob::class]);
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Jane']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janereview', 'is_published' => false]);
    $build = PreAccountBuild::factory()->make([
        'source_type' => 'instagram', 'contact_email' => 'lead@example.com', 'auto_invite' => false,
    ]);
    $build->build_state = PreAccountBuild::STATE_PENDING;
    $build->user()->associate($user);
    $build->save();

    $this->mock(SourceGeneratorRegistry::class, function ($mock) {
        $gen = new class implements SiteSourceGenerator
        {
            public function normalizeRef(string $raw): string { return $raw; }
            public function dedupeKey(string $normalizedRef): string { return $normalizedRef; }
            public function handleSeed(string $normalizedRef, ?string $sourceName): string { return $normalizedRef; }
            public function generate($user, $site, $ref): void {}
        };
        $mock->shouldReceive('for')->andReturn($gen);
    });

    (new GeneratePreAccountSiteJob($build->id, publish: true))->handle(app(SourceGeneratorRegistry::class));

    expect($build->user->fresh()->site->is_published)->toBeTrue() // still publishes
        ->and($build->fresh()->invited_at)->toBeNull();
    Mail::assertNothingQueued();                                   // but no invite
});
```

Add to `tests/Feature/PreAccount/StaffBuildEndpointTest.php`:

```php
it('persists auto_invite=false on a staff build', function () {
    actingAsStaff(staffBuildActor());
    Queue::fake();

    $this->postJson('/api/staff/builds', [
        'account_type' => 'partna', 'source_type' => 'instagram',
        'source_ref' => 'review_me', 'contact_email' => 'p@example.com', 'auto_invite' => false,
    ])->assertStatus(202);

    expect(PreAccountBuild::firstOrFail()->auto_invite)->toBeFalse();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php tests/Feature/PreAccount/StaffBuildEndpointTest.php`
Expected: FAIL — the job still notifies (Mail queued) despite `auto_invite=false`; the endpoint ignores `auto_invite`.

- [ ] **Step 3: Thread `autoInvite` through `requestBuild`**

In `app/Services/PreAccount/PreAccountBuildService.php`, add the parameter (after `?string $builtVia = null,`):

```php
        ?string $builtVia = null,
        bool $autoInvite = true,
    ): array {
```

Add `$autoInvite` to the transaction closure `use (...)` list (currently `use ($accountType, $sourceType, $ref, $refLc, $sourceName, $ipHash, $staff, $expiresAt, $contactEmail, $builtVia)`):

```php
            ) use (
                $accountType, $sourceType, $ref, $refLc, $sourceName, $ipHash, $staff, $expiresAt, $contactEmail, $builtVia, $autoInvite
            ) {
```

Add `auto_invite` to the `new PreAccountBuild([...])` array (after `'contact_email' => $contactEmail,`):

```php
                    'contact_email' => $contactEmail,
                    'auto_invite' => $autoInvite,
```

- [ ] **Step 4: Accept `auto_invite` in the request + controller**

In `app/Http/Requests/Api/Staff/UserSite/StaffCreatePreAccountBuildRequest.php`, add to `rules()`:

```php
            'auto_invite' => ['sometimes', 'boolean'],
```

In `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php`, pass it in the `requestBuild(...)` call (after `contactEmail: ...`):

```php
                contactEmail: $data['contact_email'] ?? null,
                autoInvite: (bool) ($data['auto_invite'] ?? true),
```

- [ ] **Step 5: Gate the auto-send in the job**

In `app/Jobs/PreAccount/GeneratePreAccountSiteJob.php`, change the publish block (L112-120) so the notify is conditional on `auto_invite`:

```php
        if ($this->publish) {
            $site->update(['is_published' => true]);
            SyncSubdomainToKvJob::dispatch($user->id);
            // Cold/marketing builds (Flow 2) go live immediately. auto_invite=false
            // publishes but defers the invite for manual review + send (spec §4).
            if ($build->auto_invite) {
                app(ClaimNotifier::class)->notify($build->fresh());
            }
        }
```

- [ ] **Step 6: Run to verify pass**

Run: `php artisan test tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php tests/Feature/PreAccount/StaffBuildEndpointTest.php`
Expected: PASS — new cases pass; the existing "notifies via email when a published build..." test still passes (default `auto_invite=true`).

- [ ] **Step 7: Commit**

```bash
git add app/Services/PreAccount/PreAccountBuildService.php app/Http/Requests/Api/Staff/UserSite/StaffCreatePreAccountBuildRequest.php app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php app/Jobs/PreAccount/GeneratePreAccountSiteJob.php tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php tests/Feature/PreAccount/StaffBuildEndpointTest.php
git commit -m "feat(pre-account): auto_invite toggle defers auto-send on publish"
```

---

### Task 4: Manual send endpoint `POST /api/staff/builds/{build}/invite`

**Files:**
- Modify: `routes/api/staff.php` (add route in the staff-viewing group, next to `/builds` at L62)
- Modify: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php` (add `invite`)
- Test: `tests/Feature/PreAccount/StaffInviteEndpointTest.php`

**Interfaces:**
- Consumes: idempotent `ClaimNotifier::notify` (Task 2), `PreAccountBuild->invited_at`/`contact_email`.
- Produces: `POST /api/staff/builds/{build}/invite` → 200 + `PreAccountBuildStatusResource` on success; 409 `BUILD_NOT_READY`, 422 `NO_CONTACT_EMAIL`, 409 `ALREADY_INVITED` on guard failure.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PreAccount/StaffInviteEndpointTest.php`:

```php
<?php

use App\Mail\PreAccount\ClaimInviteMail;
use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupPartnaStaffTable();
    config(['app.frontend_url' => 'https://app.partna.au']);

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY, staff_id TEXT, staff_email_snapshot TEXT,
        impersonator_staff_id TEXT, impersonator_email_snapshot TEXT, user_id TEXT,
        professional_handle_snapshot TEXT, route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\', status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\', ip_hash TEXT, user_agent TEXT, created_at TEXT
    )');
});

function inviteStaffActor(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

function readyBuild(?string $email = 'lead@example.com'): PreAccountBuild
{
    $user = User::factory()->create(['status' => 'unclaimed']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe', 'is_published' => true]);
    $build = PreAccountBuild::factory()->make(['contact_email' => $email, 'auto_invite' => false]);
    $build->build_state = PreAccountBuild::STATE_READY;
    $build->user()->associate($user);
    $build->save();

    return $build;
}

it('sends the invite and stamps invited_at', function () {
    Mail::fake();
    actingAsStaff(inviteStaffActor());
    $build = readyBuild();

    $this->postJson("/api/staff/builds/{$build->id}/invite")->assertStatus(200);

    Mail::assertQueued(ClaimInviteMail::class, fn ($m) => $m->recipientEmail === 'lead@example.com');
    expect($build->fresh()->invited_at)->not->toBeNull();
});

it('rejects a second invite with ALREADY_INVITED', function () {
    Mail::fake();
    actingAsStaff(inviteStaffActor());
    $build = readyBuild();

    $this->postJson("/api/staff/builds/{$build->id}/invite")->assertStatus(200);
    $this->postJson("/api/staff/builds/{$build->id}/invite")
        ->assertStatus(409)
        ->assertJsonPath('code', 'ALREADY_INVITED');

    Mail::assertQueued(ClaimInviteMail::class, 1);
});

it('rejects a build with no contact_email', function () {
    Mail::fake();
    actingAsStaff(inviteStaffActor());
    $build = readyBuild(email: null);

    $this->postJson("/api/staff/builds/{$build->id}/invite")
        ->assertStatus(422)
        ->assertJsonPath('code', 'NO_CONTACT_EMAIL');
    Mail::assertNothingQueued();
});

it('rejects a build that is not ready/published', function () {
    Mail::fake();
    actingAsStaff(inviteStaffActor());
    $user = User::factory()->create(['status' => 'unclaimed']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'pend', 'is_published' => false]);
    $build = PreAccountBuild::factory()->make(['contact_email' => 'x@example.com']);
    $build->build_state = PreAccountBuild::STATE_PENDING;
    $build->user()->associate($user);
    $build->save();

    $this->postJson("/api/staff/builds/{$build->id}/invite")
        ->assertStatus(409)
        ->assertJsonPath('code', 'BUILD_NOT_READY');
});

it('rejects non-staff callers', function () {
    $plain = User::factory()->create();
    $build = readyBuild();

    actingAsUser($plain)
        ->postJson("/api/staff/builds/{$build->id}/invite")
        ->assertStatus(403);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Feature/PreAccount/StaffInviteEndpointTest.php`
Expected: FAIL — route/method `invite` does not exist (404/405).

- [ ] **Step 3: Add the route**

In `routes/api/staff.php`, immediately after the `/builds` route (L62), add:

```php
        // Manual claim-invite send for a staff-built site (spec §5). Any staff
        // role; guards enforce ready+published+contact_email+not-already-sent.
        Route::post('/builds/{build}/invite', [StaffPreAccountBuildController::class, 'invite'])
            ->whereUuid('build');
```

- [ ] **Step 4: Implement the controller method**

In `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php`, add the import and method. Add near the top imports:

```php
use App\Services\PreAccount\ClaimNotifier;
```

Add the method to the class:

```php
    // POST /api/staff/builds/{build}/invite — manual send for auto_invite=false
    // builds staff wanted to eyeball first. Reuses ClaimNotifier (idempotent).
    public function invite(PreAccountBuild $build): JsonResponse
    {
        $staff = request()->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffCreate', PreAccountBuild::class);

        $published = (bool) ($build->user?->site?->is_published ?? false);
        if ($build->build_state !== PreAccountBuild::STATE_READY || ! $published) {
            return $this->error('Build is not ready to invite.', 409, [], ['code' => 'BUILD_NOT_READY']);
        }
        if ($build->contact_email === null || trim($build->contact_email) === '') {
            return $this->error('Build has no contact email.', 422, [], ['code' => 'NO_CONTACT_EMAIL']);
        }
        if ($build->invited_at !== null) {
            return $this->error('Build already invited.', 409, [], ['code' => 'ALREADY_INVITED']);
        }

        app(ClaimNotifier::class)->notify($build);

        $build->loadMissing('user.site');

        return $this->success((new PreAccountBuildStatusResource($build))->resolve());
    }
```

- [ ] **Step 5: Run to verify pass**

Run: `php artisan test tests/Feature/PreAccount/StaffInviteEndpointTest.php`
Expected: PASS (all five cases).

- [ ] **Step 6: Commit**

```bash
git add routes/api/staff.php app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php tests/Feature/PreAccount/StaffInviteEndpointTest.php
git commit -m "feat(pre-account): manual claim-invite send endpoint"
```

---

### Task 5: CSV batch endpoint `POST /api/staff/builds/batch`

**Files:**
- Create: `app/Http/Requests/Api/Staff/UserSite/StaffBatchPreAccountBuildRequest.php`
- Modify: `routes/api/staff.php` (add route next to `/builds`)
- Modify: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php` (add `batch` + private `parseCsv`)
- Test: `tests/Feature/PreAccount/StaffBatchBuildEndpointTest.php`

**Interfaces:**
- Consumes: `PreAccountBuildService::requestBuild(..., contactEmail, autoInvite)`.
- Produces: `POST /api/staff/builds/batch` (multipart `file`) → 200 `{built, reused, failed[], truncated}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PreAccount/StaffBatchBuildEndpointTest.php`:

```php
<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupPartnaStaffTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY, staff_id TEXT, staff_email_snapshot TEXT,
        impersonator_staff_id TEXT, impersonator_email_snapshot TEXT, user_id TEXT,
        professional_handle_snapshot TEXT, route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\', status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\', ip_hash TEXT, user_agent TEXT, created_at TEXT
    )');
});

function batchStaffActor(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

it('builds one row per CSV line and reports the summary', function () {
    actingAsStaff(batchStaffActor());
    Queue::fake();

    $csv = "account_type,source_type,source_ref,source_name,contact_email,auto_invite\n"
        ."partna,instagram,alice_ig,,alice@example.com,false\n"
        ."partna,instagram,bob_ig,,bob@example.com,true\n";
    $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

    $this->post('/api/staff/builds/batch', ['file' => $file])
        ->assertStatus(200)
        ->assertJsonPath('data.built', 2)
        ->assertJsonPath('data.failed', []);

    Queue::assertPushed(GeneratePreAccountSiteJob::class, 2);
    expect(PreAccountBuild::where('source_ref_lc', 'alice_ig')->firstOrFail()->auto_invite)->toBeFalse()
        ->and(PreAccountBuild::where('source_ref_lc', 'bob_ig')->firstOrFail()->contact_email)->toBe('bob@example.com');
});

it('collects a bad row without aborting the batch', function () {
    actingAsStaff(batchStaffActor());
    Queue::fake();

    $csv = "account_type,source_type,source_ref,source_name,contact_email,auto_invite\n"
        ."partna,tiktok,nope,,x@example.com,true\n"          // tiktok = invalid source
        ."partna,instagram,good_ig,,good@example.com,true\n";
    $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

    $this->post('/api/staff/builds/batch', ['file' => $file])
        ->assertStatus(200)
        ->assertJsonPath('data.built', 1)
        ->assertJsonPath('data.failed.0.row', 1);

    Queue::assertPushed(GeneratePreAccountSiteJob::class, 1);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Feature/PreAccount/StaffBatchBuildEndpointTest.php`
Expected: FAIL — route/method `batch` does not exist (404).

- [ ] **Step 3: Create the request**

Create `app/Http/Requests/Api/Staff/UserSite/StaffBatchPreAccountBuildRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Staff\UserSite;

use App\Http\Requests\BaseFormRequest;

// CSV batch marketing-build upload. Row-level validation happens per row in the
// controller (a bad row is reported, not fatal); only the file itself is
// validated here. authorize() is inherited final from BaseFormRequest.
class StaffBatchPreAccountBuildRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:2048'],
        ];
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/api/staff.php`, immediately after the `/builds/{build}/invite` route from Task 4, add:

```php
        // CSV batch marketing builds (spec §6): one requestBuild per row.
        Route::post('/builds/batch', [StaffPreAccountBuildController::class, 'batch']);
```

- [ ] **Step 5: Implement `batch` + `parseCsv`**

In `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php`, add imports:

```php
use App\Http\Requests\Api\Staff\UserSite\StaffBatchPreAccountBuildRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
```

Add the methods:

```php
    // POST /api/staff/builds/batch — CSV loop over requestBuild. Per-row failures
    // are collected (row index + code), never fatal. Row cap logged if hit.
    public function batch(StaffBatchPreAccountBuildRequest $request): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffCreate', PreAccountBuild::class);

        $rows = $this->parseCsv($request->file('file'));

        $cap = 500;
        $truncated = false;
        if (count($rows) > $cap) {
            $rows = array_slice($rows, 0, $cap);
            $truncated = true;
            Log::warning('staff builds batch truncated to cap', ['cap' => $cap]);
        }

        $built = 0;
        $reused = 0;
        $failed = [];

        foreach ($rows as $i => $row) {
            $email = $row['contact_email'] ?? null;
            if ($email !== null && $email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed[] = ['row' => $i + 1, 'code' => 'INVALID_EMAIL', 'message' => "Invalid email: {$email}"];

                continue;
            }

            try {
                $result = $this->builds->requestBuild(
                    accountType: (string) ($row['account_type'] ?? ''),
                    sourceType: (string) ($row['source_type'] ?? ''),
                    rawSourceRef: (string) ($row['source_ref'] ?? ''),
                    sourceName: ($row['source_name'] ?? null) ?: null,
                    ipHash: null,
                    staff: $staff,
                    publish: true,
                    contactEmail: ($email ?: null),
                    autoInvite: filter_var($row['auto_invite'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
                );
                $result['reused'] ? $reused++ : $built++;
            } catch (PreAccountBuildException $e) {
                $failed[] = ['row' => $i + 1, 'code' => $e->errorCode, 'message' => $e->getMessage()];
            }
        }

        return $this->success([
            'built' => $built,
            'reused' => $reused,
            'failed' => $failed,
            'truncated' => $truncated,
        ]);
    }

    /**
     * Parse an uploaded CSV into assoc rows keyed by the header line.
     *
     * @return array<int, array<string, string|null>>
     */
    private function parseCsv(UploadedFile $file): array
    {
        $content = (string) file_get_contents($file->getRealPath());
        $lines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];
        if (count($lines) < 2) {
            return [];
        }

        $header = array_map('trim', str_getcsv((string) array_shift($lines)));
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line);
            $row = [];
            foreach ($header as $idx => $key) {
                $row[$key] = isset($values[$idx]) ? trim((string) $values[$idx]) : null;
            }
            $rows[] = $row;
        }

        return $rows;
    }
```

- [ ] **Step 6: Run to verify pass**

Run: `php artisan test tests/Feature/PreAccount/StaffBatchBuildEndpointTest.php`
Expected: PASS (both cases).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Api/Staff/UserSite/StaffBatchPreAccountBuildRequest.php routes/api/staff.php app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php tests/Feature/PreAccount/StaffBatchBuildEndpointTest.php
git commit -m "feat(pre-account): CSV batch marketing-build endpoint"
```

---

### Task 6: Full-suite verification + style

**Files:** none (verification only)

- [ ] **Step 1: Run the full pre-account suite**

Run: `php artisan test tests/Feature/PreAccount`
Expected: PASS — including untouched tests (email-gate `CLAIM_EMAIL_MISMATCH` regression, existing notify tests).

- [ ] **Step 2: Run the whole suite**

Run: `composer test`
Expected: PASS. If a schema-drift test (`SignupFlowsSchemaTest`) asserts the exact column set of `pre_account_builds`, update its expected columns to include `invited_at` + `auto_invite`.

- [ ] **Step 3: Style**

Run: `php artisan pint app/ tests/ routes/`
Expected: no diffs on the touched files (or auto-fixed). Commit any style-only changes separately:

```bash
git add -A && git commit -m "style: pint"
```

- [ ] **Step 4: Apply the migration to dev Supabase**

Per CLAUDE.md, apply via `supabase db push` (dry-run first) or Supabase MCP against the dev ref `glncumufgaqcmqhzwrxm`. This is additive (two nullable/defaulted columns), safe on the live dev DB.

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
supabase db push
```

---

## Self-Review

**Spec coverage:**
- Gap 1 (idempotency / `invited_at`) → Task 1 (column) + Task 2 (stamp/guard). ✅
- Gap 2 (`auto_invite` review-then-send) → Task 1 (column) + Task 3 (plumbing + job gate) + Task 4 (manual send endpoint). ✅
- Gap 3 (CSV batch) → Task 5. ✅
- Spec §7 "default behaviour unchanged" → Task 3 default `auto_invite=true`; existing job notify test stays green. ✅
- Spec §8 deferred (unsubscribe/bounce/audit) → intentionally not in this plan. ✅
- Spec §10 "email-gate regression must survive" → Task 6 Step 1. ✅

**Placeholder scan:** none — every step has concrete code/commands.

**Type consistency:** `invited_at` (Carbon|null, forceFill), `auto_invite` (bool, fillable) used identically across Tasks 1–5. `requestBuild(..., bool $autoInvite = true)` matches the controller call and the batch call. Guard codes (`BUILD_NOT_READY`, `NO_CONTACT_EMAIL`, `ALREADY_INVITED`, `INVALID_EMAIL`) are asserted in tests exactly as returned.
