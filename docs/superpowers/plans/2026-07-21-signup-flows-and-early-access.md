# Signup Flows & Early Access — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Partna's three signup flows (self-serve, ManyChat/staff cold, early access) share one build-and-claim engine — adding auto-publish-on-claim, email-gated claims, a pluggable notification port, and a staff-approved early-access build flow.

**Architecture:** Extend the existing pre-account rails (`PreAccountBuildService` → `GeneratePreAccountSiteJob` → `ClaimSiteService`). No new subsystem. New surface: two nullable columns + a widened CHECK, a `ClaimNotifier` port (email driver now, DM driver a deferred stub), and an `ApproveEarlyAccessBuildJob`.

**Tech Stack:** PHP 8.2 / Laravel 12, Pest 4 (SQLite in-memory), Supabase Postgres (raw SQL migrations under `supabase/migrations/`), Redis/Horizon queues.

**Spec:** `docs/superpowers/specs/2026-07-21-signup-flows-and-early-access-design.md`

## Global Constraints

- **No Laravel migrations.** All schema changes are raw SQL under `supabase/migrations/`, filename `YYYYMMDDHHMMSS_name.sql`, wrapped `BEGIN; … COMMIT;` with `SET LOCAL lock_timeout='2s'; SET LOCAL statement_timeout='10s';`. A composer guard rejects Laravel migration files.
- **SQLite ≠ Postgres.** Tests run on SQLite (`tests/Pest.php` stubs); it does NOT enforce CHECK/NOT NULL. Any new column used in a test must be added to the relevant `tests/Pest.php` `setup*Table()` stub, and any constraint behavior must be verified against the real DDL.
- **Resource classes** for all API responses; **Form Requests** for input; **Policies** (`authorizeForUser`) for authz — never inline 403s.
- **Provisional users have no mail route** (`User::routeNotificationForMail()` is null). Claim/notify emails go to `contact_email` directly via `Mail::queue(new …Mailable())`, **after** any DB transaction commits — never `$user->notify()`, never inside a `DB::transaction()` closure.
- **`pre_account_builds.user_id` / `built_by_staff_id` are never fillable** — `->associate()` only. `build_state` / `claimed_at` / `failure_code` are set via `forceFill()`.
- **UUID PKs**, `pgsql` connection pinned by `BaseModel`.
- Frontend early-access form change (collect IG handle / Google business + emit `source_type`/`source_ref`) is a **separate-repo dependency**, out of scope here.

---

## File-structure map

**New files:**
- `supabase/migrations/20260721120000_signup_flows_early_access.sql` — schema changes.
- `app/Services/PreAccount/ClaimNotifier.php` — notification port.
- `app/Services/PreAccount/Notifications/ClaimDmChannel.php` — DM channel interface.
- `app/Services/PreAccount/Notifications/NullClaimDmChannel.php` — deferred no-op DM driver.
- `app/Mail/PreAccount/ClaimInviteMail.php` — claim-invite email.
- `resources/views/emails/account/claim-invite.blade.php` — email view.
- `app/Jobs/PreAccount/ApproveEarlyAccessBuildJob.php` — early-access approval worker.
- Test files per task under `tests/Feature/PreAccount/` and `tests/Unit/`.

**Modified files:**
- `app/Models/Core/User/PreAccountBuild.php` — `contact_email` fillable, `VIA_EARLY_ACCESS` const, `@property`.
- `app/Models/Core/EarlyAccess/EarlyAccessSignup.php` — `source_type`/`source_ref`/`user_id` fillable.
- `app/Services/PreAccount/ClaimSiteService.php` — email-gate + auto-publish.
- `app/Http/Controllers/Api/PublicSite/ClaimController.php` — `CLAIM_EMAIL_MISMATCH` mapping.
- `app/Services/PreAccount/PreAccountBuildService.php` — `builtVia`/`contactEmail`/null-expiry params.
- `app/Jobs/PreAccount/GeneratePreAccountSiteJob.php` — IG-deactivation + Flow-2 notify.
- `app/Http/Requests/Api/Staff/UserSite/StaffCreatePreAccountBuildRequest.php` — optional `contact_email`.
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php` — pass `contact_email`.
- `app/Http/Requests/Api/PublicSite/PublicEarlyAccessSignupRequest.php` — `source_type`/`source_ref`.
- `app/Services/EarlyAccess/EarlyAccessService.php` — trigger build + link on signup.
- `app/Http/Controllers/Api/Staff/EarlyAccess/StaffEarlyAccessController.php` — `approve` / `approveBulk`.
- `routes/api/staff.php` — approval routes.
- `app/Console/Commands/PruneExpiredPreAccountBuilds.php` — explicit NULL-expiry guard.
- `app/Providers/AppServiceProvider.php` — bind `ClaimDmChannel`.
- `tests/Pest.php` — schema stubs for new columns.

**Task order:** 1 (schema) → 2 (claim changes) → 3 (notifier) → 4 (Flow 2) → 5 (build-engine EA support) → 6 (EA signup→build) → 7 (approval) → 8 (prune). Each task ends green + committed.

---

## Task 1: Schema + model foundation

**Files:**
- Create: `supabase/migrations/20260721120000_signup_flows_early_access.sql`
- Modify: `app/Models/Core/User/PreAccountBuild.php`, `app/Models/Core/EarlyAccess/EarlyAccessSignup.php`
- Modify: `tests/Pest.php` (`setupPreAccountBuildsTable`, `setupEarlyAccessTable`)
- Test: `tests/Feature/PreAccount/SignupFlowsSchemaTest.php`

**Interfaces:**
- Produces: `PreAccountBuild::VIA_EARLY_ACCESS = 'early_access'`; `PreAccountBuild.contact_email` (nullable string, fillable); `PreAccountBuild.expires_at` may be null; `EarlyAccessSignup.source_type`/`source_ref`/`user_id` (fillable).

- [ ] **Step 1: Write the migration**

Create `supabase/migrations/20260721120000_signup_flows_early_access.sql`:

```sql
-- Signup flows & early access (spec 2026-07-21).
-- pre_account_builds: + contact_email (notify + email-gate value), + 'early_access'
--   built_via, expires_at nullable (early-access builds don't expire until approved).
-- early_access_signups: + source_type/source_ref (resolvable build source) + user_id
--   link to the provisional user.
--
-- guard:no-unsafe-migrations:disable-file
-- pre-beta / no live customers (CLAUDE.md) — same near-empty-table exemption class as
-- 20260718000000_pre_account_sites.sql; the CHECK swap below mirrors that file's
-- users_status_check pattern (direct DROP/ADD on a near-empty table).

BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

-- pre_account_builds ---------------------------------------------------------
ALTER TABLE core.pre_account_builds
    ADD COLUMN IF NOT EXISTS contact_email text NULL;

-- Widen built_via to admit early-access-originated builds (inline CHECK is
-- auto-named pre_account_builds_built_via_check).
ALTER TABLE core.pre_account_builds DROP CONSTRAINT pre_account_builds_built_via_check;
ALTER TABLE core.pre_account_builds ADD CONSTRAINT pre_account_builds_built_via_check
    CHECK (built_via IN ('signup', 'staff', 'early_access'));

-- Early-access builds have no expiry until a staff approval opens the claim
-- window. NULL = never-expire. (pre_account_builds_expiry_idx stays valid:
-- Postgres `expires_at < now()` never matches a NULL row, so prune skips them.)
ALTER TABLE core.pre_account_builds ALTER COLUMN expires_at DROP NOT NULL;

-- early_access_signups -------------------------------------------------------
-- NB: the existing `source` column means marketing-vs-manual origin — DO NOT
-- reuse it. These are the resolvable build source (handle / place id).
ALTER TABLE core.early_access_signups
    ADD COLUMN IF NOT EXISTS source_type text NULL
        CHECK (source_type IS NULL OR source_type IN ('instagram', 'google_business')),
    ADD COLUMN IF NOT EXISTS source_ref  text NULL,
    ADD COLUMN IF NOT EXISTS user_id     uuid NULL REFERENCES core.users(id) ON DELETE SET NULL;

-- One early-access signup per provisional user.
CREATE UNIQUE INDEX IF NOT EXISTS early_access_signups_user_id_unique
    ON core.early_access_signups (user_id) WHERE user_id IS NOT NULL;

COMMIT;
```

- [ ] **Step 2: Update the SQLite test stubs**

In `tests/Pest.php`, append defensive ALTERs to `setupPreAccountBuildsTable()` (after its `CREATE TABLE IF NOT EXISTS`, ~line 378):

```php
    foreach (['contact_email TEXT NULL'] as $col) {
        try {
            DB::connection('pgsql')->statement('ALTER TABLE core.pre_account_builds ADD COLUMN '.$col);
        } catch (Throwable $e) { /* already exists — ignore */ }
    }
```

And to `setupEarlyAccessTable()` (after its `CREATE TABLE IF NOT EXISTS`, ~line 1556):

```php
    foreach (['source_type TEXT NULL', 'source_ref TEXT NULL', 'user_id TEXT NULL'] as $col) {
        try {
            DB::connection('pgsql')->statement('ALTER TABLE core.early_access_signups ADD COLUMN '.$col);
        } catch (Throwable $e) { /* already exists — ignore */ }
    }
```

- [ ] **Step 3: Update the models**

In `app/Models/Core/User/PreAccountBuild.php`: add the constant next to `VIA_STAFF`:

```php
    public const VIA_EARLY_ACCESS = 'early_access';
```

Add `'contact_email'` to `$fillable`:

```php
    protected $fillable = [
        'source_type', 'source_ref', 'source_ref_lc', 'built_via',
        'created_ip_hash', 'expires_at', 'contact_email',
    ];
```

Add the `@property` line to the docblock (near `expires_at`):

```php
 * @property string|null $contact_email Notify address + email-gate value; NULL = first-come claim.
```

In `app/Models/Core/EarlyAccess/EarlyAccessSignup.php`: add to `$fillable` (after `'platforms'`): `'source_type', 'source_ref', 'user_id',`.

- [ ] **Step 4: Write the failing test**

Create `tests/Feature/PreAccount/SignupFlowsSchemaTest.php`:

```php
<?php

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;

beforeEach(function () {
    setupUsersTable();
    setupPreAccountBuildsTable();
    setupEarlyAccessTable();
});

it('persists an early-access build with contact_email and null expiry', function () {
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);

    $build = new PreAccountBuild([
        'source_type' => 'instagram',
        'source_ref' => 'prospect',
        'source_ref_lc' => 'prospect',
        'built_via' => PreAccountBuild::VIA_EARLY_ACCESS,
        'contact_email' => 'lead@example.com',
        'expires_at' => null,
    ]);
    $build->build_state = PreAccountBuild::STATE_PENDING;
    $build->user()->associate($user);
    $build->save();

    $fresh = $build->fresh();
    expect($fresh->built_via)->toBe('early_access')
        ->and($fresh->contact_email)->toBe('lead@example.com')
        ->and($fresh->expires_at)->toBeNull();
});
```

- [ ] **Step 5: Run — expect PASS** (models + stubs already updated)

Run: `php artisan test tests/Feature/PreAccount/SignupFlowsSchemaTest.php`
Expected: PASS. (If it fails on an unknown column, the stub ALTER in Step 2 is missing.)

- [ ] **Step 6: Commit**

```bash
git add supabase/migrations/20260721120000_signup_flows_early_access.sql tests/Pest.php \
  app/Models/Core/User/PreAccountBuild.php app/Models/Core/EarlyAccess/EarlyAccessSignup.php \
  tests/Feature/PreAccount/SignupFlowsSchemaTest.php
git commit -m "feat(signup): schema + model foundation for early-access builds & email-gated claims"
```

---

## Task 2: Email-gated + auto-publishing claim

**Files:**
- Modify: `app/Services/PreAccount/ClaimSiteService.php`
- Modify: `app/Http/Controllers/Api/PublicSite/ClaimController.php`
- Test: `tests/Feature/PreAccount/ClaimSiteServiceTest.php` (extend), `tests/Feature/PreAccount/ClaimEndpointTest.php` (extend)

**Interfaces:**
- Consumes: `PreAccountBuild.contact_email` (Task 1).
- Produces: `ClaimSiteService::claim()` now throws `CLAIM_EMAIL_MISMATCH` when a build's `contact_email` doesn't match the verified email, and sets `Site.is_published = true` on success.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PreAccount/ClaimSiteServiceTest.php`:

```php
it('auto-publishes the site on claim', function () {
    [$user, $site, $build] = makeReadyBuild();
    expect($site->fresh()->is_published)->toBeFalse();

    app(App\Services\PreAccount\ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    expect($site->fresh()->is_published)->toBeTrue();
});

it('rejects a claim whose verified email does not match an email-gated build', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['contact_email' => 'owner@example.com'])->save();

    expect(fn () => app(App\Services\PreAccount\ClaimSiteService::class)
        ->claim('auth-uid-1', 'someone-else@example.com', 'janedoe'))
        ->toThrow(RuntimeException::class, 'CLAIM_EMAIL_MISMATCH');

    expect($user->fresh()->status)->toBe('unclaimed');
});

it('allows a claim whose verified email matches an email-gated build (case-insensitive)', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['contact_email' => 'Owner@Example.com'])->save();

    $result = app(App\Services\PreAccount\ClaimSiteService::class)
        ->claim('auth-uid-1', 'owner@example.com', 'janedoe');

    expect($result['professional']->status)->toBe('active')
        ->and($site->fresh()->is_published)->toBeTrue();
});
```

- [ ] **Step 2: Run — expect FAIL**

Run: `php artisan test tests/Feature/PreAccount/ClaimSiteServiceTest.php`
Expected: FAIL — no email-gate, `is_published` still false.

- [ ] **Step 3: Add the email-gate** in `ClaimSiteService::claim()`, immediately after the `BUILD_FAILED` block (after the `$build` is loaded/locked):

```php
            // Email-gate (spec §3.2): a build carrying a contact_email may only be
            // claimed by someone who verified control of THAT inbox via Supabase OTP.
            // Absent contact_email = first-come (unchanged). Case-insensitive.
            if ($build->contact_email !== null
                && strtolower(trim($verifiedEmail)) !== strtolower(trim($build->contact_email))) {
                throw new RuntimeException('CLAIM_EMAIL_MISMATCH');
            }
```

- [ ] **Step 4: Add auto-publish** in the same method, after `$professional->status = 'active';` add the display-name fallback, and after the `$professional` save + `claimed_at` stamp add the publish write (still inside the transaction closure):

```php
            // display_name is populated by the source generator, but fall back to
            // the handle so auto-publish below can never be blocked by an empty name
            // (the UpdateSiteRequest publish guard doesn't run on this direct write).
            if (empty($professional->display_name)) {
                $professional->display_name = $professional->handle;
            }
```

Then, after `$build->forceFill(['claimed_at' => now()])->save();`:

```php
            // Auto-publish on claim (spec §3.3). Flow 2 sites are already published
            // (no-op); Flow 1 / early-access flip live here.
            if (! (bool) $site->is_published) {
                $site->is_published = true;
                $site->unpublished_at = null;
                $site->save();
            }
```

- [ ] **Step 5: Map the new code in the controller.** In `ClaimController::store()`'s `match`, add before `default`:

```php
                'CLAIM_EMAIL_MISMATCH' => $this->error('This site is reserved for a different email address.', 409, [], ['code' => 'CLAIM_EMAIL_MISMATCH']),
```

Also add `CLAIM_EMAIL_MISMATCH` to the `@throws` line in `ClaimSiteService::claim()`'s docblock.

- [ ] **Step 6: Run — expect PASS**

Run: `php artisan test tests/Feature/PreAccount/ClaimSiteServiceTest.php`
Expected: PASS.

- [ ] **Step 7: Add an endpoint-level test** in `tests/Feature/PreAccount/ClaimEndpointTest.php`:

```php
it('409s a mismatched email on an email-gated build', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['contact_email' => 'owner@example.com'])->save();
    Queue::fake();

    actingAsUser(claimJwtUser('auth-uid-1', 'intruder@example.com'));
    $this->postJson('/api/claim', ['subdomain' => 'janedoe'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'CLAIM_EMAIL_MISMATCH');
});
```

- [ ] **Step 8: Run + commit**

Run: `php artisan test tests/Feature/PreAccount/ClaimEndpointTest.php tests/Feature/PreAccount/ClaimSiteServiceTest.php`
Expected: PASS.

```bash
git add app/Services/PreAccount/ClaimSiteService.php app/Http/Controllers/Api/PublicSite/ClaimController.php tests/Feature/PreAccount/ClaimSiteServiceTest.php tests/Feature/PreAccount/ClaimEndpointTest.php
git commit -m "feat(claim): email-gated claims + auto-publish on claim"
```

---

## Task 3: Notification port (ClaimNotifier + email driver + DM stub)

**Files:**
- Create: `app/Services/PreAccount/Notifications/ClaimDmChannel.php`, `app/Services/PreAccount/Notifications/NullClaimDmChannel.php`
- Create: `app/Mail/PreAccount/ClaimInviteMail.php`, `resources/views/emails/account/claim-invite.blade.php`
- Create: `app/Services/PreAccount/ClaimNotifier.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/PreAccount/ClaimNotifierTest.php`

**Interfaces:**
- Produces: `ClaimNotifier::notify(PreAccountBuild $build): void` — sends `ClaimInviteMail` to `$build->contact_email` when set, and always calls the bound `ClaimDmChannel`. Claim URL = `{app.frontend_url}/claim/{subdomain}`.
- Produces: `ClaimDmChannel::send(PreAccountBuild $build, string $claimUrl): void` (interface).

- [ ] **Step 1: Create the DM channel seam**

`app/Services/PreAccount/Notifications/ClaimDmChannel.php`:

```php
<?php

namespace App\Services\PreAccount\Notifications;

use App\Models\Core\User\PreAccountBuild;

// Deferred seam (spec §3.1): the "DM the person their claim link" channel.
// The real driver (an open-source ManyChat alternative) implements this later;
// nothing else in the claim/build core changes when it lands.
interface ClaimDmChannel
{
    public function send(PreAccountBuild $build, string $claimUrl): void;
}
```

`app/Services/PreAccount/Notifications/NullClaimDmChannel.php`:

```php
<?php

namespace App\Services\PreAccount\Notifications;

use App\Models\Core\User\PreAccountBuild;
use Illuminate\Support\Facades\Log;

// No-op DM driver until the real integration ships. Logs so we can confirm the
// dispatch point fires in prod without sending anything.
class NullClaimDmChannel implements ClaimDmChannel
{
    public function send(PreAccountBuild $build, string $claimUrl): void
    {
        Log::info('claim.dm.stub', ['build_id' => $build->id, 'source_type' => $build->source_type]);
    }
}
```

- [ ] **Step 2: Create the Mailable + view**

`app/Mail/PreAccount/ClaimInviteMail.php`:

```php
<?php

namespace App\Mail\PreAccount;

use App\Mail\BaseTransactionalMail;

// "Your Partna site is ready — claim it" email. Goes to the build's
// contact_email directly (provisional users have no mail route). Same template
// family as the auth/early-access emails.
class ClaimInviteMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $claimUrl,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject('Your Partna site is ready — claim it')
            ->view('emails.account.claim-invite');
    }
}
```

`resources/views/emails/account/claim-invite.blade.php` (mirror `emails/account/early-access-invite.blade.php`'s layout; minimal body):

```blade
@extends('emails.layouts.partna')

@section('content')
    <h1>Your Partna site is ready</h1>
    <p>We've built a site for you. Claim it to make it yours and take control of your page.</p>
    <p><a href="{{ $claimUrl }}" class="btn">Claim your site</a></p>
    <p>If the button doesn't work, paste this link into your browser:<br>{{ $claimUrl }}</p>
@endsection
```

*(Confirm the exact layout/section names against `resources/views/emails/account/early-access-invite.blade.php` when implementing — match that file's `@extends`/`@section` and button class.)*

- [ ] **Step 3: Create the notifier**

`app/Services/PreAccount/ClaimNotifier.php`:

```php
<?php

namespace App\Services\PreAccount;

use App\Mail\PreAccount\ClaimInviteMail;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\Notifications\ClaimDmChannel;
use Illuminate\Support\Facades\Mail;

// One "invite this person to claim their site" concept fanning out to every
// channel we have contact info for (spec §3.1). Email ships now; DM is a bound
// stub. Call AFTER any surrounding DB transaction commits (Mailable dispatch
// discipline — see EarlyAccessService::invite()).
class ClaimNotifier
{
    public function __construct(private readonly ClaimDmChannel $dm) {}

    public function notify(PreAccountBuild $build): void
    {
        $site = $build->user?->site;
        if ($site === null) {
            return;
        }

        $claimUrl = rtrim((string) config('app.frontend_url'), '/').'/claim/'.$site->subdomain;

        if ($build->contact_email !== null && trim($build->contact_email) !== '') {
            Mail::queue(new ClaimInviteMail($build->contact_email, $claimUrl));
        }

        // DM channel: no-op stub today (spec §3.1 deferred seam).
        $this->dm->send($build, $claimUrl);
    }
}
```

- [ ] **Step 4: Bind the DM channel** in `app/Providers/AppServiceProvider.php` `register()`:

```php
        $this->app->bind(
            \App\Services\PreAccount\Notifications\ClaimDmChannel::class,
            \App\Services\PreAccount\Notifications\NullClaimDmChannel::class,
        );
```

- [ ] **Step 5: Write the failing test**

`tests/Feature/PreAccount/ClaimNotifierTest.php`:

```php
<?php

use App\Mail\PreAccount\ClaimInviteMail;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimNotifier;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    config(['app.frontend_url' => 'https://app.partna.au']);
});

it('emails the claim link when a build has a contact_email', function () {
    Mail::fake();
    $user = User::factory()->create(['status' => 'unclaimed']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe']);
    $build = PreAccountBuild::factory()->make(['contact_email' => 'lead@example.com']);
    $build->user()->associate($user);
    $build->save();

    app(ClaimNotifier::class)->notify($build->fresh());

    Mail::assertQueued(ClaimInviteMail::class, fn ($m) =>
        $m->recipientEmail === 'lead@example.com'
        && $m->claimUrl === 'https://app.partna.au/claim/janedoe');
});

it('sends no email when contact_email is null', function () {
    Mail::fake();
    $user = User::factory()->create(['status' => 'unclaimed']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe']);
    $build = PreAccountBuild::factory()->make(['contact_email' => null]);
    $build->user()->associate($user);
    $build->save();

    app(ClaimNotifier::class)->notify($build->fresh());

    Mail::assertNothingQueued();
});
```

- [ ] **Step 6: Run + commit**

Run: `php artisan test tests/Feature/PreAccount/ClaimNotifierTest.php`
Expected: PASS.

```bash
git add app/Services/PreAccount/ClaimNotifier.php app/Services/PreAccount/Notifications/ app/Mail/PreAccount/ClaimInviteMail.php resources/views/emails/account/claim-invite.blade.php app/Providers/AppServiceProvider.php tests/Feature/PreAccount/ClaimNotifierTest.php
git commit -m "feat(claim): ClaimNotifier port with email driver + deferred DM stub"
```

---

## Task 4: Flow 2 — staff builds carry a contact_email and notify

**Files:**
- Modify: `app/Http/Requests/Api/Staff/UserSite/StaffCreatePreAccountBuildRequest.php`
- Modify: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php`
- Modify: `app/Services/PreAccount/PreAccountBuildService.php` (add `contactEmail` param — see Interfaces)
- Modify: `app/Jobs/PreAccount/GeneratePreAccountSiteJob.php` (notify after publish)
- Test: `tests/Feature/PreAccount/StaffBuildEndpointTest.php` (extend), `tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php`

**Interfaces:**
- Produces: `PreAccountBuildService::requestBuild(..., ?string $contactEmail = null)` persists `contact_email` on the build. (The `builtVia`/null-expiry params are added in Task 5; add ONLY `contactEmail` here to keep the diff reviewable.)
- Produces: `GeneratePreAccountSiteJob`, after a published build reaches ready, calls `ClaimNotifier::notify($build)`.

- [ ] **Step 1: Add `contactEmail` to `requestBuild`.** Append the param to the signature:

```php
        bool $publish = false,
        ?int $expiresDays = null,
        ?string $contactEmail = null,
    ): array {
```

In the `new PreAccountBuild([...])` array, add `'contact_email' => $contactEmail,`. Thread `$contactEmail` into the transaction closure `use (...)` list.

- [ ] **Step 2: Accept it in the request.** In `StaffCreatePreAccountBuildRequest::rules()` add:

```php
            'contact_email' => ['nullable', 'email:rfc', 'max:320'],
```

- [ ] **Step 3: Pass it in the controller.** In `StaffPreAccountBuildController::store()`'s `requestBuild(...)` call add:

```php
                contactEmail: $data['contact_email'] ?? null,
```

- [ ] **Step 4: Notify after publish.** In `GeneratePreAccountSiteJob::handle()`, replace the `if ($this->publish) { ... }` block with:

```php
        if ($this->publish) {
            $site->update(['is_published' => true]);
            SyncSubdomainToKvJob::dispatch($user->id);
            // Cold/marketing builds (Flow 2) go live immediately — invite the
            // person to claim via whatever channels we have (spec §3.1). Early-
            // access builds are unpublished here, so they never notify from this
            // path; their invite fires at staff approval instead.
            app(\App\Services\PreAccount\ClaimNotifier::class)->notify($build->fresh());
        }
```

- [ ] **Step 5: Write the failing tests**

Append to `tests/Feature/PreAccount/StaffBuildEndpointTest.php`:

```php
it('stores a contact_email on a staff build', function () {
    actingAsStaff(staffBuildActor());
    Queue::fake();

    $this->postJson('/api/staff/builds', [
        'account_type' => 'partna', 'source_type' => 'instagram',
        'source_ref' => 'prospect', 'contact_email' => 'prospect@example.com',
    ])->assertStatus(202);

    expect(PreAccountBuild::firstOrFail()->contact_email)->toBe('prospect@example.com');
});
```

Create `tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php` (notify-on-publish):

```php
<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Mail\PreAccount\ClaimInviteMail;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\SourceGeneratorRegistry;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    config(['app.frontend_url' => 'https://app.partna.au']);
});

it('notifies via email when a published build with contact_email reaches ready', function () {
    Mail::fake();
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Jane']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe', 'is_published' => false]);
    $build = PreAccountBuild::factory()->make(['source_type' => 'instagram', 'contact_email' => 'lead@example.com']);
    $build->build_state = PreAccountBuild::STATE_PENDING;
    $build->user()->associate($user);
    $build->save();

    // Stub the generator so no real scrape runs.
    $this->mock(SourceGeneratorRegistry::class, function ($mock) {
        $gen = new class {
            public function generate($user, $site, $ref): void {}
        };
        $mock->shouldReceive('for')->andReturn($gen);
    });

    (new GeneratePreAccountSiteJob($build->id, publish: true))->handle(app(SourceGeneratorRegistry::class));

    expect($build->fresh()->build_state)->toBe(PreAccountBuild::STATE_READY);
    Mail::assertQueued(ClaimInviteMail::class, fn ($m) => $m->recipientEmail === 'lead@example.com');
});
```

- [ ] **Step 6: Run + commit**

Run: `php artisan test tests/Feature/PreAccount/StaffBuildEndpointTest.php tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php`
Expected: PASS.

```bash
git add app/Http/Requests/Api/Staff/UserSite/StaffCreatePreAccountBuildRequest.php app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php app/Services/PreAccount/PreAccountBuildService.php app/Jobs/PreAccount/GeneratePreAccountSiteJob.php tests/Feature/PreAccount/StaffBuildEndpointTest.php tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php
git commit -m "feat(builds): Flow 2 staff builds carry contact_email + notify on publish"
```

---

## Task 5: Build engine — early-access build mode (built_via, null expiry, IG off-treadmill)

**Files:**
- Modify: `app/Services/PreAccount/PreAccountBuildService.php`
- Modify: `app/Jobs/PreAccount/GeneratePreAccountSiteJob.php`
- Test: `tests/Feature/PreAccount/PreAccountBuildServiceTest.php` (extend), `tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php` (extend)

**Interfaces:**
- Produces: `PreAccountBuildService::requestBuild(..., ?string $builtVia = null)`. When `$builtVia === PreAccountBuild::VIA_EARLY_ACCESS`, `built_via='early_access'` and `expires_at=NULL`.
- Produces: `GeneratePreAccountSiteJob` deactivates the Instagram `IntegrationConnection` (`is_active=false`) when the build is a still-unapproved early-access IG build (`built_via='early_access' && source_type='instagram' && expires_at IS NULL`).

- [ ] **Step 1: Add `builtVia` + null-expiry to `requestBuild`.** Add the param:

```php
        ?string $contactEmail = null,
        ?string $builtVia = null,
    ): array {
```

Change the expiry computation:

```php
        // Early-access builds have no expiry until a staff approval opens the
        // 30-day claim window (spec §3.5); every other build expires from creation.
        $expiresAt = $builtVia === PreAccountBuild::VIA_EARLY_ACCESS
            ? null
            : now()->addDays($expiresDays ?? (int) config('partna.pre_account.expiry_days', 30));
```

Change the `built_via` assignment in the `new PreAccountBuild([...])`:

```php
                    'built_via' => $builtVia ?? ($staff ? PreAccountBuild::VIA_STAFF : PreAccountBuild::VIA_SIGNUP),
```

Add `$builtVia` to the closure `use (...)` list.

- [ ] **Step 2: Deactivate the IG connection for dark early-access builds.** In `GeneratePreAccountSiteJob::handle()`, after the `generate()` succeeds and before `forceFill(['build_state' => STATE_READY])`, add:

```php
        // Keep dark, unapproved early-access Instagram builds OFF the Apify refresh
        // treadmill (spec §3.4): a site nobody has claimed must not be re-scraped via
        // Apify on the ~12h cadence. GBP (official Places API) stays active. The
        // signal is expires_at IS NULL = not yet approved; approval sets expires_at
        // and re-scrapes, which the seeder reactivates.
        if ($build->built_via === PreAccountBuild::VIA_EARLY_ACCESS
            && $build->source_type === 'instagram'
            && $build->expires_at === null) {
            \App\Models\Core\Site\IntegrationConnection::query()
                ->where('user_id', $user->id)
                ->where('platform', 'instagram')
                ->update(['is_active' => false]);
        }
```

- [ ] **Step 3: Write the failing tests**

Append to `tests/Feature/PreAccount/PreAccountBuildServiceTest.php` (uses that file's existing generator-stubbing `beforeEach`; if none, stub `SourceGeneratorRegistry` as in Task 4):

```php
it('creates an early-access build with null expiry and built_via early_access', function () {
    $result = app(App\Services\PreAccount\PreAccountBuildService::class)->requestBuild(
        accountType: 'partna', sourceType: 'instagram', rawSourceRef: 'ea_prospect',
        sourceName: null, ipHash: null, staff: null, publish: false,
        expiresDays: null, contactEmail: 'lead@example.com',
        builtVia: PreAccountBuild::VIA_EARLY_ACCESS,
    );

    $build = $result['build'];
    expect($build->built_via)->toBe('early_access')
        ->and($build->expires_at)->toBeNull()
        ->and($build->contact_email)->toBe('lead@example.com');
});
```

Append to `tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php`:

```php
it('deactivates the IG connection for a dark early-access build', function () {
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Jane']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'ea_jane', 'is_published' => false]);
    $build = PreAccountBuild::factory()->make([
        'source_type' => 'instagram', 'built_via' => PreAccountBuild::VIA_EARLY_ACCESS, 'expires_at' => null,
    ]);
    $build->build_state = PreAccountBuild::STATE_PENDING;
    $build->user()->associate($user);
    $build->save();

    // Generator stub that seeds an ACTIVE IG connection (mirrors the real seeder).
    $this->mock(SourceGeneratorRegistry::class, function ($mock) use ($user) {
        $gen = new class($user) {
            public function __construct(private $user) {}
            public function generate($u, $site, $ref): void {
                App\Models\Core\Site\IntegrationConnection::create([
                    'user_id' => $this->user->id, 'platform' => 'instagram',
                    'resource_id' => 'instagram', 'payload' => [], 'is_active' => true,
                ]);
            }
        };
        $mock->shouldReceive('for')->andReturn($gen);
    });

    (new GeneratePreAccountSiteJob($build->id, publish: false))->handle(app(SourceGeneratorRegistry::class));

    $conn = App\Models\Core\Site\IntegrationConnection::where('user_id', $user->id)->where('platform', 'instagram')->first();
    expect((bool) $conn->is_active)->toBeFalse();
});
```

*(If the test needs the `platform_connections` table, `setupSitesTable()` already creates it — confirm `setupSitesTable()` is in `beforeEach`.)*

- [ ] **Step 4: Run + commit**

Run: `php artisan test tests/Feature/PreAccount/PreAccountBuildServiceTest.php tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php`
Expected: PASS.

```bash
git add app/Services/PreAccount/PreAccountBuildService.php app/Jobs/PreAccount/GeneratePreAccountSiteJob.php tests/Feature/PreAccount/PreAccountBuildServiceTest.php tests/Feature/PreAccount/GeneratePreAccountSiteJobTest.php
git commit -m "feat(builds): early-access build mode — null expiry + IG off refresh treadmill"
```

---

## Task 6: Early-access signup builds a dark site

**Files:**
- Modify: `app/Http/Requests/Api/PublicSite/PublicEarlyAccessSignupRequest.php`
- Modify: `app/Services/EarlyAccess/EarlyAccessService.php`
- Test: `tests/Feature/EarlyAccess/EarlyAccessBuildTest.php`

**Interfaces:**
- Consumes: `PreAccountBuildService::requestBuild(..., contactEmail, builtVia)` (Task 5).
- Produces: `EarlyAccessService::signupFromMarketing($data)` — when `$data['source_type']`/`$data['source_ref']` are present and the row is newly created, triggers an early-access build and stamps `early_access_signups.user_id`.

- [ ] **Step 1: Require a resolvable source in the request.** In `PublicEarlyAccessSignupRequest::rules()` add:

```php
            'source_type' => ['required', 'string', Rule::in(array_keys(config('partna.pre_account.generators', [])))],
            'source_ref' => ['required', 'string', 'max:300'],
```

Keep the existing `platforms` rule (still captured for analytics; the build uses `source_type`/`source_ref`).

- [ ] **Step 2: Inject the build service + trigger the build.** In `EarlyAccessService`, add a constructor injecting `PreAccountBuildService`:

```php
    public function __construct(
        private readonly \App\Services\PreAccount\PreAccountBuildService $builds,
    ) {}
```

In `signupFromMarketing()`, after the `firstOrCreate` and BEFORE the `wasRecentlyCreated` mail block, add the build trigger (best-effort — a bad handle must still capture the lead and keep the uniform response). Use this block verbatim:

```php
        // Site-first early access: build a dark site the person can later claim
        // (spec Flow 3). Best-effort — a malformed handle still captures the lead;
        // staff see an unlinked row and can correct the source. Only build once,
        // on first create, when a source was supplied.
        if ($signup->wasRecentlyCreated
            && ! empty($data['source_type']) && ! empty($data['source_ref'])
            && $signup->user_id === null) {
            try {
                $result = $this->builds->requestBuild(
                    accountType: $data['type'],
                    sourceType: $data['source_type'],
                    rawSourceRef: $data['source_ref'],
                    sourceName: null,
                    ipHash: null,
                    staff: null,
                    publish: false,
                    expiresDays: null,
                    contactEmail: $emailLc,
                    builtVia: \App\Models\Core\User\PreAccountBuild::VIA_EARLY_ACCESS,
                );
                $signup->forceFill([
                    'source_type' => $data['source_type'],
                    'source_ref' => $data['source_ref'],
                    'user_id' => $result['build']->user_id,
                ])->save();
            } catch (\Throwable $e) {
                report($e);
            }
        }
```

- [ ] **Step 3: Pass source through the controller.** In `PublicEarlyAccessController::store()`, add to the `signupFromMarketing([...])` array:

```php
            'source_type' => $data['source_type'],
            'source_ref' => $data['source_ref'],
```

- [ ] **Step 4: Write the failing test**

`tests/Feature/EarlyAccess/EarlyAccessBuildTest.php`:

```php
<?php

use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\User\PreAccountBuild;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupEarlyAccessTable();
    setupEmailSubscriptionsTable();
    Queue::fake();
});

it('creates a dark early-access build and links the signup on first signup', function () {
    $this->postJson('/api/public/early-access', [
        'email' => 'lead@example.com', 'type' => 'partna',
        'platforms' => ['instagram', 'tiktok'],
        'source_type' => 'instagram', 'source_ref' => 'ea_handle',
    ])->assertOk();

    $signup = EarlyAccessSignup::firstOrFail();
    expect($signup->user_id)->not->toBeNull()
        ->and($signup->source_ref)->toBe('ea_handle');

    $build = PreAccountBuild::where('user_id', $signup->user_id)->firstOrFail();
    expect($build->built_via)->toBe('early_access')
        ->and($build->expires_at)->toBeNull()
        ->and($build->contact_email)->toBe('lead@example.com');
    Queue::assertPushed(App\Jobs\PreAccount\GeneratePreAccountSiteJob::class);
});
```

- [ ] **Step 5: Run + commit**

Run: `php artisan test tests/Feature/EarlyAccess/EarlyAccessBuildTest.php`
Expected: PASS.

```bash
git add app/Http/Requests/Api/PublicSite/PublicEarlyAccessSignupRequest.php app/Services/EarlyAccess/EarlyAccessService.php app/Http/Controllers/Api/PublicSite/PublicEarlyAccessController.php tests/Feature/EarlyAccess/EarlyAccessBuildTest.php
git commit -m "feat(early-access): signup builds a dark, linked pre-account site"
```

---

## Task 7: Staff approval — re-scrape (IG), open claim window, notify

**Files:**
- Create: `app/Jobs/PreAccount/ApproveEarlyAccessBuildJob.php`
- Modify: `app/Http/Controllers/Api/Staff/EarlyAccess/StaffEarlyAccessController.php`
- Modify: `routes/api/staff.php`
- Test: `tests/Feature/PreAccount/ApproveEarlyAccessBuildJobTest.php`, `tests/Feature/Staff/StaffEarlyAccessApproveTest.php`

**Interfaces:**
- Consumes: `PreAccountBuild.expires_at` null-vs-set, `SourceGeneratorRegistry`, `ClaimNotifier`.
- Produces: `ApproveEarlyAccessBuildJob(string $signupId)` — health-checks/re-scrapes the linked build, sets `expires_at = now()+30d`, flips the signup to `invited`, and calls `ClaimNotifier::notify`.
- Produces: routes `POST /api/staff/early-access/{signup}/approve` and `POST /api/staff/early-access/approve-bulk`.

- [ ] **Step 1: Create the approval job**

`app/Jobs/PreAccount/ApproveEarlyAccessBuildJob.php`:

```php
<?php

namespace App\Jobs\PreAccount;

use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\ClaimNotifier;
use App\Services\PreAccount\SourceGenerationException;
use App\Services\PreAccount\SourceGeneratorRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Staff "allow claiming" (spec Flow 3): freshen the dark early-access site,
// open its 30-day claim window, and invite the person. Runs on the scraping
// lane; a bulk approval fans one job per signup. Idempotent-ish: re-approving
// an already-invited row re-scrapes and re-notifies (a resend).
class ApproveEarlyAccessBuildJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $signupId)
    {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->signupId;
    }

    public function handle(SourceGeneratorRegistry $registry, ClaimNotifier $notifier): void
    {
        $signup = EarlyAccessSignup::find($this->signupId);
        if ($signup === null || $signup->user_id === null) {
            Log::info('early_access.approve.no_link', ['signup_id' => $this->signupId]);

            return;
        }

        $build = PreAccountBuild::where('user_id', $signup->user_id)->first();
        if ($build === null || $build->claimed_at !== null || $build->built_via !== PreAccountBuild::VIA_EARLY_ACCESS) {
            return;
        }

        $user = $build->user;
        $site = $user?->site;
        if ($user === null || $site === null) {
            return;
        }

        // Freshen: re-scrape IG (Apify) so the invited person sees current content
        // and the connection reactivates (seeder sets is_active=true); or heal a
        // build that failed at signup. A healthy GBP build is left alone — it stays
        // fresh on the official-API treadmill (spec §3.4).
        $needsScrape = $build->build_state === PreAccountBuild::STATE_FAILED
            || $build->source_type === 'instagram';

        if ($needsScrape) {
            $build->forceFill(['build_state' => PreAccountBuild::STATE_BUILDING])->save();
            try {
                $registry->for($build->source_type)->generate($user, $site, $build->source_ref);
            } catch (SourceGenerationException $e) {
                $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => $e->failureCode])->save();
                Log::warning('early_access.approve.scrape_failed', ['build_id' => $build->id, 'failure_code' => $e->failureCode]);

                return;
            } catch (Throwable $e) {
                $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED])->save();
                report($e);

                return;
            }
            $build->forceFill(['build_state' => PreAccountBuild::STATE_READY])->save();
        }

        // Open the 30-day claim window (this also lifts the "dark, unapproved"
        // IG-deactivation signal for any future re-generation).
        $build->forceFill(['expires_at' => now()->addDays((int) config('partna.pre_account.expiry_days', 30))])->save();

        $signup->forceFill(['status' => EarlyAccessSignup::STATUS_INVITED, 'invited_at' => now()])->save();

        // After the writes commit: invite the person to claim (email; DM stub).
        $notifier->notify($build->fresh());
    }
}
```

- [ ] **Step 2: Add controller actions.** In `StaffEarlyAccessController`, add:

```php
    // POST /api/staff/early-access/{signup}/approve — allow this lead to claim.
    public function approve(EarlyAccessSignup $signup): JsonResponse
    {
        $staff = request()->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $signup);

        ApproveEarlyAccessBuildJob::dispatch($signup->id);

        return $this->success(['ok' => true], 202);
    }

    // POST /api/staff/early-access/approve-bulk — {ids?:[], all_waitlisted?:bool}.
    public function approveBulk(StaffEarlyAccessApproveBulkRequest $request): JsonResponse
    {
        $staff = request()->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', EarlyAccessSignup::class);

        $data = $request->validated();
        $query = EarlyAccessSignup::query()->whereNotNull('user_id');
        if (! empty($data['ids'])) {
            $query->whereIn('id', $data['ids']);
        } elseif (! empty($data['all_waitlisted'])) {
            $query->where('status', EarlyAccessSignup::STATUS_WAITLIST);
        } else {
            return $this->error('Provide ids[] or all_waitlisted.', 422);
        }

        $count = 0;
        $query->lazyById()->each(function (EarlyAccessSignup $s) use (&$count) {
            ApproveEarlyAccessBuildJob::dispatch($s->id);
            $count++;
        });

        return $this->success(['dispatched' => $count], 202);
    }
```

Add the imports at the top of the controller: `use App\Jobs\PreAccount\ApproveEarlyAccessBuildJob;`, `use App\Http\Requests\Api\Staff\EarlyAccess\StaffEarlyAccessApproveBulkRequest;`.

Create `app/Http/Requests/Api/Staff/EarlyAccess/StaffEarlyAccessApproveBulkRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Staff\EarlyAccess;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// Bulk "allow claiming": explicit ids[] OR all_waitlisted. authorize() is
// final-true from BaseFormRequest; the staffManage policy runs in the controller.
class StaffEarlyAccessApproveBulkRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['required_without:all_waitlisted', 'array', 'max:500'],
            'ids.*' => ['uuid', Rule::exists('pgsql.core.early_access_signups', 'id')],
            'all_waitlisted' => ['required_without:ids', 'boolean'],
        ];
    }
}
```

- [ ] **Step 3: Register routes.** In `routes/api/staff.php`, in the **admin-write** group (alongside the other `/early-access` writes), add:

```php
        Route::post('/early-access/{signup}/approve', [StaffEarlyAccessController::class, 'approve'])->whereUuid('signup');
        Route::post('/early-access/approve-bulk', [StaffEarlyAccessController::class, 'approveBulk']);
```

- [ ] **Step 4: Write the failing job test**

`tests/Feature/PreAccount/ApproveEarlyAccessBuildJobTest.php`:

```php
<?php

use App\Jobs\PreAccount\ApproveEarlyAccessBuildJob;
use App\Mail\PreAccount\ClaimInviteMail;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\SourceGeneratorRegistry;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupEarlyAccessTable();
    config(['app.frontend_url' => 'https://app.partna.au']);
});

it('re-scrapes IG, opens the window, flips to invited, and emails the invite', function () {
    Mail::fake();
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Jane']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'ea_jane']);
    $build = PreAccountBuild::factory()->make([
        'source_type' => 'instagram', 'built_via' => PreAccountBuild::VIA_EARLY_ACCESS,
        'expires_at' => null, 'contact_email' => 'lead@example.com',
    ]);
    $build->build_state = PreAccountBuild::STATE_READY;
    $build->user()->associate($user);
    $build->save();
    $signup = EarlyAccessSignup::create([
        'email' => 'lead@example.com', 'email_lc' => 'lead@example.com', 'type' => 'partna',
        'status' => EarlyAccessSignup::STATUS_WAITLIST, 'source' => 'marketing', 'user_id' => $user->id,
    ]);

    $this->mock(SourceGeneratorRegistry::class, function ($mock) {
        $gen = new class {
            public function generate($u, $s, $r): void {}
        };
        $mock->shouldReceive('for')->andReturn($gen);
    });

    (new ApproveEarlyAccessBuildJob($signup->id))->handle(app(SourceGeneratorRegistry::class), app(App\Services\PreAccount\ClaimNotifier::class));

    expect($build->fresh()->expires_at)->not->toBeNull()
        ->and($signup->fresh()->status)->toBe('invited');
    Mail::assertQueued(ClaimInviteMail::class, fn ($m) => $m->recipientEmail === 'lead@example.com');
});
```

- [ ] **Step 5: Write the endpoint test**

`tests/Feature/Staff/StaffEarlyAccessApproveTest.php` (mirror `StaffBuildEndpointTest`'s `beforeEach` incl. the `audit.staff_audit_log` stub + `actingAsStaff(staffBuildActor())` with `ROLE_ADMIN`):

```php
it('dispatches an approval job for a single signup', function () {
    // ... setupUsersTable/Sites/PreAccountBuilds/EarlyAccess + audit stub (copy from StaffBuildEndpointTest) ...
    Queue::fake();
    $user = User::factory()->create(['status' => 'unclaimed']);
    $signup = EarlyAccessSignup::create([
        'email' => 'l@e.com', 'email_lc' => 'l@e.com', 'type' => 'partna',
        'status' => 'waitlist', 'source' => 'marketing', 'user_id' => $user->id,
    ]);

    actingAsStaff(staffBuildActor()); // ROLE_ADMIN — staffManage is admin-only
    $this->postJson("/api/staff/early-access/{$signup->id}/approve")->assertStatus(202);

    Queue::assertPushed(App\Jobs\PreAccount\ApproveEarlyAccessBuildJob::class,
        fn ($j) => $j->signupId === $signup->id);
});
```

- [ ] **Step 6: Run + commit**

Run: `php artisan test tests/Feature/PreAccount/ApproveEarlyAccessBuildJobTest.php tests/Feature/Staff/StaffEarlyAccessApproveTest.php`
Expected: PASS.

```bash
git add app/Jobs/PreAccount/ApproveEarlyAccessBuildJob.php app/Http/Controllers/Api/Staff/EarlyAccess/StaffEarlyAccessController.php app/Http/Requests/Api/Staff/EarlyAccess/StaffEarlyAccessApproveBulkRequest.php routes/api/staff.php tests/Feature/PreAccount/ApproveEarlyAccessBuildJobTest.php tests/Feature/Staff/StaffEarlyAccessApproveTest.php
git commit -m "feat(early-access): staff approval re-scrapes, opens claim window, notifies"
```

---

## Task 8: Prune — never delete an unapproved (NULL-expiry) build

**Files:**
- Modify: `app/Console/Commands/PruneExpiredPreAccountBuilds.php`
- Test: `tests/Feature/PreAccount/PruneExpiredPreAccountBuildsTest.php` (extend or create)

**Interfaces:**
- Consumes: nullable `expires_at`.
- Produces: `builds:prune-expired` explicitly skips `expires_at IS NULL` builds.

- [ ] **Step 1: Write the failing test**

Create/extend `tests/Feature/PreAccount/PruneExpiredPreAccountBuildsTest.php`:

```php
<?php

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    // + any teardown tables the command touches (media/subdomain aliases) — copy
    //   from the existing prune test's beforeEach if present.
});

it('never prunes an unapproved early-access build (null expires_at)', function () {
    $user = User::factory()->create(['status' => 'unclaimed']);
    $build = PreAccountBuild::factory()->make([
        'built_via' => PreAccountBuild::VIA_EARLY_ACCESS, 'expires_at' => null,
    ]);
    $build->build_state = PreAccountBuild::STATE_READY;
    $build->user()->associate($user);
    $build->save();

    $this->artisan('builds:prune-expired')->assertExitCode(0);

    expect(PreAccountBuild::whereKey($build->id)->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run — expect PASS** (SQL NULL comparison already excludes it), then make it **explicit** for intent. In `PruneExpiredPreAccountBuilds::handle()`, change the candidate query's first branch to guard NULL explicitly:

```php
        $candidates = PreAccountBuild::live()
            ->where(fn ($q) => $q
                ->where(fn ($qq) => $qq->whereNotNull('expires_at')->where('expires_at', '<', $cutoff))
                ->orWhere(fn ($qq) => $qq->where('build_state', PreAccountBuild::STATE_FAILED)->where('updated_at', '<', $failedCutoff)))
            ->pluck('id');
```

Add a one-line comment above it: `// NULL expires_at = unapproved early-access build → never-expire (spec §3.5).`

- [ ] **Step 3: Run + commit**

Run: `php artisan test tests/Feature/PreAccount/PruneExpiredPreAccountBuildsTest.php`
Expected: PASS.

```bash
git add app/Console/Commands/PruneExpiredPreAccountBuilds.php tests/Feature/PreAccount/PruneExpiredPreAccountBuildsTest.php
git commit -m "fix(prune): never delete unapproved early-access builds (null expires_at)"
```

---

## Task 9: Full-suite gate + deploy

- [ ] **Step 1: Run the full suite**

Run: `composer test`
Expected: PASS (no regressions). Fix any breakage before proceeding — pay attention to `PolicyCoverageTest`, `AuditPipelineIntegrityTest`, and any GDPR export/purge coverage test (`contact_email` is new PII — see Step 3).

- [ ] **Step 2: Pint**

Run: `php artisan pint --dirty` then re-run affected tests.

- [ ] **Step 3: GDPR coverage for `contact_email`** — `contact_email` is new stored PII on `pre_account_builds`. Check `DataExportPayloadBuilder` / `AccountDeletionService` for how `pre_account_builds` is exported/purged; if the row is exported column-by-column, add `contact_email` to that projection. If a `DataExportCoverageTest`-style guard exists, satisfy it. (If `pre_account_builds` is teardown-deleted on account deletion already, no purge change is needed.)

- [ ] **Step 4: Apply the migration to dev Supabase** (gated — confirm with Josh first). Dev ref `glncumufgaqcmqhzwrxm`. Either `supabase db push` (after `supabase link --project-ref glncumufgaqcmqhzwrxm`) or the Supabase MCP `apply_migration` with the file contents. Verify with a `\d core.pre_account_builds` / `\d core.early_access_signups` check that `contact_email`, the widened `built_via` CHECK, nullable `expires_at`, and the new early-access columns landed.

---

## Self-review notes (coverage vs spec)

- Spec §3.1 notification port → Task 3 (+ wired in Tasks 4, 7). §3.2 email-gate → Task 2. §3.3 auto-publish → Task 2. §3.4 refresh split (IG off treadmill) → Task 5 (deactivate) + Task 7 (reactivate on approval; GBP untouched = stays on official-API treadmill). §3.5 lifecycle (null expiry, prune) → Tasks 5, 7, 8.
- §4 flows: Flow 1 auto-publish → Task 2. Flow 2 contact_email + notify → Task 4. Flow 3 → Tasks 5–7.
- §5 data model → Task 1. §6 API → Tasks 2 (claim), 4 (staff builds), 6 (early-access), 7 (approval). §7 services/jobs → Tasks 3, 5, 7.
- §8 security/privacy: email-gate (Task 2), Apify minimization (Task 5), `contact_email` PII (Task 9 Step 3).
- **Open detail resolved:** the "IG off-treadmill vs staff-preview render" tension (spec §11.1) — Task 5 uses mechanism (a) (`is_active=false`). **Verify during Task 5 that the staff preview render of a dark early-access site reads the connection payload regardless of `is_active`;** if the staff preview query filters `is_active=true`, adjust that query to include the connection for staff, OR switch to a `refresh_paused`-column mechanism (extra migration). Flag to Josh if the preview path filters on `is_active`.
- **Not yet built (spec out-of-scope, flag to frontend):** the early-access form must emit `source_type`/`source_ref`; the claim page must live at `{frontend}/claim/{subdomain}`; the staff dashboard needs approve / approve-bulk buttons + a pre-built-site preview.
