# New Outbound Emails Implementation Plan (2026-08-12)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

## Status: SHIPPED (2026-08-12, same day as written)

All 8 tasks executed inline, TDD, one commit per task, full suite green
(7,423 tests) before push. Two mid-execution deviations worth recording,
both caught by tests rather than assumed correct:

- **Task 4 (welcome email):** the naive "send on claim success" design broke
  on a re-read of `ClaimSiteService::claim()` — it has a silent
  idempotency-first retry branch, and `SignupSideEffects::createWelcomeNotification`
  already had an in-app dedupe boundary. Reused that boundary (changed its
  return type to `int`, the insertOrIgnore row count) instead of inventing a
  second one.
- **Task 6 (weekly digest) and Task 7 (achievement mail):** discovered
  `NotificationPublisher::publish()` always returns the notification row on
  an insertOrIgnore conflict — never `null` — so "check `publish()`'s return
  value for null" (the plan's original design) would have silently re-sent
  the email on every re-run. Both were rewritten to check row existence
  BEFORE calling `publish()`. Task 7 additionally discovered `critical`
  double-duty (email-eligibility AND in-app auto-expiry) — flipping it would
  have made achievement notifications persist in the bell forever, so the
  email dispatch was pulled out into a direct call in `AchievementNotifier`
  instead of touching that flag.
- **Task 3's cache write** (`SecurityEventController`'s cooldown) initially
  used a raw `Cache::add()` and failed `RawCacheCallGuardTest` (GS-1) on the
  full-suite run — extracted into `SecurityEventCooldownService` +
  `CacheKeyGenerator::securityEventCooldown()`.
- Registering `AchievementMail` broke a pre-existing test
  (`OvhCriticalSeverityTest`) whose "unmapped category" fixture happened to
  be `achievement` — swapped to `content_scrape`, still genuinely unmapped.

**Still outside this repo's reach** (per the plan's own scope note): the
dashboard needs two one-line additions — `POST /me/security-events
{"event":"password_changed"}` after a successful Supabase password update,
and `{"event":"two_factor_enabled"}` after MFA enrolment succeeds — before
those two emails fire in practice. The endpoint is live and tested; nothing
calls it yet.

**Goal:** Add the seven genuinely useful new outbound emails the 2026-08-11 audit
identified as missing: two-factor-removed, password-changed, two-factor-enabled,
welcome, enquiry-unanswered reminder, weekly analytics digest, and achievement
emails — all riding the existing (2026-08-12) email infrastructure.

**Architecture:** Everything reuses what shipped in the email overhaul: mailables
extend `BaseTransactionalMail` (auto text/plain part, Resend tag, pipeline header,
queue lane), category-driven mail extends `CategoryNotificationMail` (one view,
signed one-click unsubscribe), scheduled sends go through `NotificationPublisher`
(dedupe, per-user category preferences). No schema changes anywhere — dedupe rides
the publisher's dedupe keys and short-TTL cache, never a new column.

**Tech Stack:** Laravel 12 (Comet-Backend), Pest, Blade email views on
`mail.layouts.partna`, Resend transport, Supabase Auth.

## Global Constraints

- AU English, sentence case, verbiage canon (Site · Platform · Handle; Log in / Sign up).
- Every new Mailable extends `App\Mail\BaseTransactionalMail` — `MailExtendsBaseTransactionalTest` enforces this; support classes go under `App\Mail\Support` (exempt).
- Every new category registered in `config('partna.notifications.mailables')` needs a literal `publish(category: 'x', ...)` call site or a justified entry in `MAILABLE_COVERAGE_EXEMPT` (`tests/Feature/Notifications/MailableCategoryCoverageTest.php`).
- Every new email gets a fixture entry in `app/Http/Controllers/Dev/MailPreviewController.php` and a visual check at `http://localhost:8000/dev/emails` **before** its commit.
- Copy conventions from the shipped overhaul: fixed action H1 + separate greeting; `x-mail.fine-print` under the CTA (with `:url` fallback when there's a button); preheader states expiry/urgency where one exists; one CTA per email; no hardcoded colours — the palette in the templates is `#171717` text, `#7d7d7d`/`#8f8f8f` muted, `#f2f2f2` wells, `#1367fb` links (match the auth templates exactly).
- Auth in feature tests: `actingAsUser($pro)->postJson(...)` (`tests/Pest.php:105`). Table helpers also live in `tests/Pest.php` (`setupUsersTable()`, `setupNotificationEmailPreferencesTable()`, …).
- `vendor/bin/pint --dirty` before every commit. Work on `development`, commit per task, `git fetch` before committing (multi-chat rule). Push only at the end (Laravel Cloud deploys from `development`).
- The category email pipeline is behind `PARTNA_NOTIFICATIONS_EMAIL_ENABLED`; direct-queued transactional mail (security notices, welcome, digest) is not — this mirrors how enquiry confirmations already work.

## Deliberately NOT planned (checked against the code, rejected)

- **Platform sync-broken alert** — ALREADY EXISTS. `PlatformHealthNotifier::connectionRefreshFailing()` publishes a critical (in-app + email) "Reconnect your {platform}" when the circuit breaker trips. Don't build a second one.
- **New-device / new-login notice** — needs device-fingerprint storage that doesn't exist; infra project, not an email task.
- **Deletion-completed confirmation** — impossible by design: PII (including the email address) is pseudonymised at *confirm* time (`AccountDeletionService`, PRIV-1), 30 days before `purge()` runs. Retaining the address just to email it would contradict the privacy posture.
- **Custom-domain lifecycle emails** — `CustomDomainController` verify is user-driven (they're watching the dashboard when it flips); a "broke later" email needs a scheduled CF re-poll that doesn't exist. Revisit if/when a domain-health sweep is built.
- **Email-change notice to the OLD address** — requires enabling Supabase `double_confirm_changes` (currently deliberately off; `email_change_current` is ignored in the hook). Auth-flow product decision, not an email task.
- **Site-gone-quiet win-back** — marketing cadence/product decision; park until owner wants it.

---

### Task 1: Shared category-unsubscribe URL helper

The weekly digest (Task 6) is a custom mailable that must carry the same signed
one-click unsubscribe as category mail. Extract the URL builder so both use one
implementation.

**Files:**
- Create: `app/Mail/Support/CategoryUnsubscribe.php`
- Modify: `app/Mail/Notifications/CategoryNotificationMail.php` (the `unsubscribeUrl()` method)
- Test: `tests/Unit/Mail/CategoryUnsubscribeTest.php`

**Interfaces:**
- Produces: `CategoryUnsubscribe::urlFor(?string $userId, string $category): ?string` — null when the category can't be opted out of (empty, `critical`, mandatory) or `$userId` is null/empty; otherwise the signed `public.notification-unsubscribe` URL. Task 6 consumes this.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Mail/CategoryUnsubscribeTest.php

use App\Mail\Support\CategoryUnsubscribe;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('builds a signed unsubscribe URL for an optional category', function () {
    $url = CategoryUnsubscribe::urlFor('00000000-0000-4000-8000-000000000001', 'feature_announcement');

    expect($url)->toContain('/public/notification-unsubscribe/00000000-0000-4000-8000-000000000001/feature_announcement')
        ->and($url)->toContain('signature=');
});

it('returns null for critical, mandatory, empty category and missing user', function () {
    expect(CategoryUnsubscribe::urlFor('00000000-0000-4000-8000-000000000001', 'critical'))->toBeNull()
        ->and(CategoryUnsubscribe::urlFor('00000000-0000-4000-8000-000000000001', 'policy_update'))->toBeNull()
        ->and(CategoryUnsubscribe::urlFor('00000000-0000-4000-8000-000000000001', ''))->toBeNull()
        ->and(CategoryUnsubscribe::urlFor(null, 'feature_announcement'))->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Mail/CategoryUnsubscribeTest.php`
Expected: FAIL — `Class "App\Mail\Support\CategoryUnsubscribe" not found`

- [ ] **Step 3: Implement the helper**

```php
<?php
// app/Mail/Support/CategoryUnsubscribe.php

namespace App\Mail\Support;

use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\URL;

/**
 * One place that decides whether a notification category is opt-out-able and
 * builds its signed one-click unsubscribe URL. Shared by
 * CategoryNotificationMail and any custom mailable that carries a
 * category-scoped unsubscribe (the weekly digest).
 */
final class CategoryUnsubscribe
{
    public static function urlFor(?string $userId, string $category): ?string
    {
        // critical always emails; mandatory categories resolve to enabled at
        // send time regardless of any stored preference row.
        if ($category === '' || $category === 'critical') {
            return null;
        }

        if (NotificationPublisher::isMandatory($category)) {
            return null;
        }

        if (! is_string($userId) || $userId === '') {
            return null;
        }

        return URL::signedRoute('public.notification-unsubscribe', [
            'userId' => $userId,
            'category' => $category,
        ]);
    }
}
```

- [ ] **Step 4: Delegate from CategoryNotificationMail**

In `app/Mail/Notifications/CategoryNotificationMail.php`, replace the whole body
of `unsubscribeUrl()` with:

```php
    protected function unsubscribeUrl(): ?string
    {
        $userId = $this->notification->user_id;

        return \App\Mail\Support\CategoryUnsubscribe::urlFor(
            is_string($userId) ? $userId : null,
            static::CATEGORY,
        );
    }
```

Remove the now-unused `use App\Services\Notifications\NotificationPublisher;`
and `use Illuminate\Support\Facades\URL;` imports from that file.

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Unit/Mail/CategoryUnsubscribeTest.php tests/Feature/Notifications/PublicNotificationEmailUnsubscribeTest.php tests/Feature/Architecture/MailExtendsBaseTransactionalTest.php`
Expected: PASS (the arch test already exempts `App\Mail\Support`)

- [ ] **Step 6: Commit**

```bash
git add app/Mail/Support/CategoryUnsubscribe.php app/Mail/Notifications/CategoryNotificationMail.php tests/Unit/Mail/CategoryUnsubscribeTest.php
git commit -m "refactor(mail): extract category unsubscribe URL builder for reuse"
```

---

### Task 2: Two-factor-removed security notice (backend emit)

Removing a second factor is the highest-value security tripwire — it's what an
attacker does first — and it already flows through the backend
(`MfaController::destroy`), so no frontend work is needed.

**Files:**
- Create: `app/Mail/Security/TwoFactorRemovedMail.php`
- Create: `resources/views/emails/security/two-factor-removed.blade.php`
- Modify: `app/Http/Controllers/Api/User/Account/MfaController.php` (`destroy()` success path)
- Modify: `app/Http/Controllers/Dev/MailPreviewController.php` (fixture)
- Test: `tests/Feature/Mail/SecurityNoticeMailTest.php` (created here, extended in Task 3)

**Interfaces:**
- Produces: `new TwoFactorRemovedMail(string $recipientEmail, ?string $displayName)` — Task 8's gallery sweep relies on this constructor shape; Task 3 mirrors it for its two mailables.

- [ ] **Step 1: Write the failing render test**

```php
<?php
// tests/Feature/Mail/SecurityNoticeMailTest.php

use App\Mail\Security\TwoFactorRemovedMail;

it('renders the two-factor-removed notice', function () {
    $html = (new TwoFactorRemovedMail('sam@example.com', 'Sam'))->render();

    expect($html)->toContain('Two-factor authentication was removed')
        ->and($html)->toContain('Hi Sam,')
        ->and($html)->toContain('change your password');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Mail/SecurityNoticeMailTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create the mailable**

```php
<?php
// app/Mail/Security/TwoFactorRemovedMail.php

namespace App\Mail\Security;

use App\Mail\BaseTransactionalMail;

/**
 * Security notice: a second factor was removed from the account. Sent from
 * MfaController::destroy — the one MFA mutation that flows through this
 * backend. Notice-only; carries no links to act on the change itself.
 */
class TwoFactorRemovedMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly ?string $displayName,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject('Two-factor authentication was removed from your account')
            ->view('emails.security.two-factor-removed');
    }
}
```

- [ ] **Step 4: Create the view**

```blade
{{-- resources/views/emails/security/two-factor-removed.blade.php --}}
@extends('mail.layouts.partna')

@section('preheader', 'Two-factor authentication was just removed from your Partna account.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Two-factor authentication was removed
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        {{ $displayName ? "Hi {$displayName}," : 'Hi,' }}
    </p>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Two-factor authentication was just removed from your Partna account ({{ $recipientEmail }}). Your account is now protected by your password alone.
    </p>

    <x-mail.fine-print>
        If this was you, no action is needed. If you didn't do this, change your password now and re-enable two-factor authentication from Settings &rarr; Security — someone may have access to your account.
    </x-mail.fine-print>
@endsection

@section('footer_note', 'This is a transactional email related to your account security.')
```

- [ ] **Step 5: Run the render test**

Run: `php artisan test tests/Feature/Mail/SecurityNoticeMailTest.php`
Expected: PASS

- [ ] **Step 6: Wire the emit into MfaController::destroy**

`destroy()` does NOT resolve a `User` model — it works entirely off
`$uid = (string) $request->attributes->get('supabase_uid')` (the Supabase auth
user id) and calls `$this->admin->unenrollMfaFactor($uid, $factorId)` directly
against the Supabase Admin API (confirmed by reading the method in full — see
`app/Http/Controllers/Api/User/Account/MfaController.php:50-74`). To email the
notice we need the local `User` row keyed by `auth_user_id = $uid`. Insert
this AFTER `$this->repo->record(...)` and BEFORE `return $this->success(...)`:

```php
        // Security notice — fire-and-forget: a mail failure must never fail
        // the factor removal itself. auth_user_id, not id — $uid is the
        // Supabase auth id, not our primary key.
        try {
            $professional = \App\Models\Core\User\User::query()->where('auth_user_id', $uid)->first();
            $email = (string) ($professional?->primary_email ?? '');
            if ($email !== '') {
                Mail::to($email)->queue(new \App\Mail\Security\TwoFactorRemovedMail($email, $professional?->display_name));
            }
        } catch (\Throwable $e) {
            Log::warning('mfa.factor_removed_mail_failed', ['auth_user_id' => $uid, 'error' => $e->getMessage()]);
        }
```

Add `use Illuminate\Support\Facades\Mail;` to the imports (`Log` is already imported).

- [ ] **Step 7: Add a Mail::fake assertion to the existing MFA test**

The real success test is `tests/Feature/Account/UnenrollMfaFactorTest.php`,
`it('calls Supabase Admin API and records unenroll event when within 60s', ...)`
(uses `Http::fake` for the Supabase Admin call + `actingAsUser($pro, aal2ClaimsWithFreshTotp(30))`).
`createTenant()` (`tests/Pest.php:1316`) already sets `primary_email` to
`{$handle}@example.test`, so no extra setup is needed — just add `Mail::fake()`
before the request and the assertion after:

```php
use App\Mail\Security\TwoFactorRemovedMail;
use Illuminate\Support\Facades\Mail;   // add alongside the existing Http import

// inside the existing success test, before the request:
Mail::fake();
// ...existing Http::fake + actingAsUser(...)->deleteJson(...)->assertOk() + Http::assertSent(...)...
Mail::assertQueued(TwoFactorRemovedMail::class, fn ($m) => $m->recipientEmail === $pro->primary_email);
```

- [ ] **Step 8: Add the preview fixture**

In `app/Http/Controllers/Dev/MailPreviewController.php`, `groups()`, add a new
group after `'Auth'`:

```php
            'Security' => [
                'two-factor-removed' => ['label' => 'Two-factor removed', 'make' => fn () => new \App\Mail\Security\TwoFactorRemovedMail('sam@example.com', 'Sam')],
            ],
```

Visual check: `php artisan serve --port=8000`, open
`http://localhost:8000/dev/emails/two-factor-removed` — logo, headline,
fine-print, footer all correct; screenshot before committing.

- [ ] **Step 9: Run tests and commit**

Run: `php artisan test --filter="SecurityNoticeMail|Mfa"`
Expected: PASS

```bash
git add app/Mail/Security resources/views/emails/security app/Http/Controllers/Api/User/Account/MfaController.php app/Http/Controllers/Dev/MailPreviewController.php tests/Feature/Mail/SecurityNoticeMailTest.php
git commit -m "feat(mail): two-factor-removed security notice from MfaController::destroy"
```

---

### Task 3: Security-events endpoint + password-changed / two-factor-enabled notices

Password changes and MFA *enrolment* happen client-side against Supabase — the
backend never sees them. A tiny authed notice-only endpoint lets the dashboard
ping the backend after those succeed. Trust model: an authenticated client can
only trigger mail about ITS OWN account, and the endpoint sends notices, never
makes security decisions — so client-trust is acceptable.

> **Cross-repo note (NOT in this repo's execution):** the dashboard must call
> `POST /me/security-events {"event":"password_changed"}` after
> `supabase.auth.updateUser({password})` resolves, and
> `{"event":"two_factor_enabled"}` after `mfa.enroll` verification succeeds.
> One line each; until wired, the endpoint sits unused and harmless.

**Files:**
- Create: `app/Mail/Security/PasswordChangedMail.php`
- Create: `app/Mail/Security/TwoFactorEnabledMail.php`
- Create: `resources/views/emails/security/password-changed.blade.php`
- Create: `resources/views/emails/security/two-factor-enabled.blade.php`
- Create: `app/Http/Controllers/Api/User/Account/SecurityEventController.php`
- Modify: `routes/api/user.php` (route, inside the authed group near `/me/notifications`, `routes/api/user.php:440-449`)
- Modify: `app/Http/Controllers/Dev/MailPreviewController.php` (two fixtures)
- Test: `tests/Feature/Mail/SecurityNoticeMailTest.php` (extend), `tests/Feature/User/SecurityEventControllerTest.php`

**Interfaces:**
- Consumes: `actingAsUser($pro)` test helper (`tests/Pest.php:105`); `ResolveCurrentUser::currentUser($request)` (same trait `UserEmailSubscriptionController` uses).
- Produces: `POST /api/me/security-events` accepting `{event: 'password_changed'|'two_factor_enabled'}`, 200 `{ok: true}`, 422 on unknown events; both mailables share the `(string $recipientEmail, ?string $displayName)` constructor.

- [ ] **Step 1: Extend the render tests (failing)**

Append to `tests/Feature/Mail/SecurityNoticeMailTest.php`:

```php
use App\Mail\Security\PasswordChangedMail;
use App\Mail\Security\TwoFactorEnabledMail;

it('renders the password-changed notice', function () {
    $html = (new PasswordChangedMail('sam@example.com', 'Sam'))->render();

    expect($html)->toContain('Your password was changed')
        ->and($html)->toContain('reset it now');
});

it('renders the two-factor-enabled notice', function () {
    $html = (new TwoFactorEnabledMail('sam@example.com', 'Sam'))->render();

    expect($html)->toContain('Two-factor authentication is on');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Feature/Mail/SecurityNoticeMailTest.php`
Expected: FAIL — classes not found

- [ ] **Step 3: Create both mailables**

```php
<?php
// app/Mail/Security/PasswordChangedMail.php

namespace App\Mail\Security;

use App\Mail\BaseTransactionalMail;

// Security notice: password changed (client-side via Supabase; the dashboard
// pings POST /me/security-events afterwards). Notice-only.
class PasswordChangedMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly ?string $displayName,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject('Your Partna password was changed')
            ->view('emails.security.password-changed');
    }
}
```

```php
<?php
// app/Mail/Security/TwoFactorEnabledMail.php

namespace App\Mail\Security;

use App\Mail\BaseTransactionalMail;

// Security notice: a second factor was enrolled (client-side via Supabase).
class TwoFactorEnabledMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly ?string $displayName,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject('Two-factor authentication is on')
            ->view('emails.security.two-factor-enabled');
    }
}
```

- [ ] **Step 4: Create both views**

```blade
{{-- resources/views/emails/security/password-changed.blade.php --}}
@extends('mail.layouts.partna')

@section('preheader', 'Your Partna password was just changed.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Your password was changed
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        {{ $displayName ? "Hi {$displayName}," : 'Hi,' }}
    </p>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        The password for your Partna account ({{ $recipientEmail }}) was just changed.
    </p>

    <x-mail.fine-print>
        If this was you, no action is needed. If you didn't do this, reset it now from the log-in page ("Forgot password") — someone may have access to your account.
    </x-mail.fine-print>
@endsection

@section('footer_note', 'This is a transactional email related to your account security.')
```

```blade
{{-- resources/views/emails/security/two-factor-enabled.blade.php --}}
@extends('mail.layouts.partna')

@section('preheader', 'Two-factor authentication is now protecting your Partna account.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Two-factor authentication is on
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        {{ $displayName ? "Hi {$displayName}," : 'Hi,' }}
    </p>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Two-factor authentication was just turned on for your Partna account ({{ $recipientEmail }}). From now on, logging in takes your password and a code from your authenticator app.
    </p>

    <x-mail.fine-print>
        If you didn't do this, change your password now — someone may have access to your account.
    </x-mail.fine-print>
@endsection

@section('footer_note', 'This is a transactional email related to your account security.')
```

- [ ] **Step 5: Run the render tests**

Run: `php artisan test tests/Feature/Mail/SecurityNoticeMailTest.php`
Expected: PASS (all four tests)

- [ ] **Step 6: Write the failing controller test**

```php
<?php
// tests/Feature/User/SecurityEventControllerTest.php

use App\Mail\Security\PasswordChangedMail;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    setupUsersTable();
    Cache::flush();
});

it('queues the password-changed notice for the acting user', function () {
    Mail::fake();
    $pro = User::factory()->create(['status' => 'active']);

    actingAsUser($pro)->postJson('/api/me/security-events', ['event' => 'password_changed'])
        ->assertOk();

    Mail::assertQueued(PasswordChangedMail::class, fn ($m) => $m->recipientEmail === $pro->primary_email);
});

it('dedupes repeat pings inside the cooldown window', function () {
    Mail::fake();
    $pro = User::factory()->create(['status' => 'active']);

    actingAsUser($pro)->postJson('/api/me/security-events', ['event' => 'password_changed'])->assertOk();
    actingAsUser($pro)->postJson('/api/me/security-events', ['event' => 'password_changed'])->assertOk();

    Mail::assertQueuedCount(1);
});

it('rejects unknown events', function () {
    Mail::fake();
    $pro = User::factory()->create(['status' => 'active']);

    actingAsUser($pro)->postJson('/api/me/security-events', ['event' => 'account_hacked'])
        ->assertStatus(422);

    Mail::assertNothingQueued();
});
```

- [ ] **Step 7: Run to verify failure**

Run: `php artisan test tests/Feature/User/SecurityEventControllerTest.php`
Expected: FAIL — 404 (route missing)

- [ ] **Step 8: Create the controller**

```php
<?php
// app/Http/Controllers/Api/User/Account/SecurityEventController.php

namespace App\Http\Controllers\Api\User\Account;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Mail\Security\PasswordChangedMail;
use App\Mail\Security\TwoFactorEnabledMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Notice-only pings from the dashboard for account mutations that happen
 * client-side against Supabase (password change, MFA enrolment) — the backend
 * never sees those, so the client tells it afterwards and we email the notice.
 *
 * Trust model: authenticated, self-scoped (you can only trigger mail about
 * your own account), sends notices only — never a security decision. A short
 * Cache::add cooldown stops replay spam from a stolen token being used to
 * flood the inbox.
 */
class SecurityEventController extends ApiController
{
    use ResolveCurrentUser;

    private const EVENTS = [
        'password_changed' => PasswordChangedMail::class,
        'two_factor_enabled' => TwoFactorEnabledMail::class,
    ];

    private const COOLDOWN_SECONDS = 300;

    public function store(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $event = trim((string) $request->input('event', ''));
        if (! array_key_exists($event, self::EVENTS)) {
            return $this->error('Unknown security event.', 422);
        }

        // Same event inside the cooldown → acknowledged, not re-sent.
        if (! Cache::add("security-event-mail:{$user->id}:{$event}", 1, self::COOLDOWN_SECONDS)) {
            return $this->success(['ok' => true, 'deduped' => true]);
        }

        $email = (string) ($user->primary_email ?? '');
        if ($email !== '') {
            $class = self::EVENTS[$event];
            Mail::to($email)->queue(new $class($email, $user->display_name));
        }

        return $this->success(['ok' => true]);
    }
}
```

- [ ] **Step 9: Register the route**

In `routes/api/user.php`, add the import at the top (alphabetical among the
`Account` controllers):

```php
use App\Http\Controllers\Api\User\Account\SecurityEventController;
```

and inside the authed group, next to the `/me/notifications` block (~line 440):

```php
        // Security-event pings (password change / MFA enrolment happen
        // client-side against Supabase; the dashboard tells us afterwards).
        Route::post('/me/security-events', [SecurityEventController::class, 'store']);
```

- [ ] **Step 10: Run the tests**

Run: `php artisan test tests/Feature/User/SecurityEventControllerTest.php tests/Feature/Mail/SecurityNoticeMailTest.php`
Expected: PASS

- [ ] **Step 11: Preview fixtures + visual check**

Add to the `'Security'` gallery group (Task 2):

```php
                'password-changed' => ['label' => 'Password changed', 'make' => fn () => new \App\Mail\Security\PasswordChangedMail('sam@example.com', 'Sam')],
                'two-factor-enabled' => ['label' => 'Two-factor enabled', 'make' => fn () => new \App\Mail\Security\TwoFactorEnabledMail('sam@example.com', 'Sam')],
```

Visual check both at `http://localhost:8000/dev/emails`.

- [ ] **Step 12: Commit**

```bash
git add app/Mail/Security resources/views/emails/security app/Http/Controllers/Api/User/Account/SecurityEventController.php routes/api/user.php app/Http/Controllers/Dev/MailPreviewController.php tests/Feature/Mail/SecurityNoticeMailTest.php tests/Feature/User/SecurityEventControllerTest.php
git commit -m "feat(mail): password-changed + two-factor-enabled notices via /me/security-events"
```

---

### Task 4: Welcome email at claim

Signup is site-first: `POST /claim` (`ClaimSiteService::claim()`) binds the
verified Supabase user to their pre-account site — that's the moment the
account exists. One welcome email: your site's address, the three first steps.

**Read this before writing code — two things change the naive approach:**

1. `claim()` already fires an in-app "Welcome to Partna" notification via
   `SignupSideEffects::createWelcomeNotification($professional)` (called at
   `app/Services/PreAccount/ClaimSiteService.php:132`, INSIDE the
   `DB::connection('pgsql')->transaction(...)` closure). It's deduped with
   `Notification::query()->insertOrIgnore([...'dedupe_key' => 'welcome:'.$professional->id...])`
   (`app/Services/User/SignupSideEffects.php:61-71`) — currently returns
   `void`. `insertOrIgnore()` itself returns the number of rows actually
   inserted; the welcome email should reuse this exact boundary rather than
   inventing a second one, so this task changes that method's return type.
2. `claim()` has an idempotency-first branch
   (`app/Services/PreAccount/ClaimSiteService.php:53-55` —
   `if ($professional->auth_user_id === $uid) { return [...]; }`, a double-tap
   or network retry by the rightful owner) that returns EARLY, before the
   welcome-notification line ever runs. But the method's POST-COMMIT block
   (`app/Services/PreAccount/ClaimSiteService.php:135-168` — cache
   invalidation, `SyncSubdomainToKvJob`, Cloudflare purge; genuinely outside
   the transaction closure, which has already committed by the time this
   code runs) executes UNCONDITIONALLY on every call, retries included. The
   email must be gated on "this call actually just created the account", not
   just "this call succeeded" — otherwise a retried claim spams the welcome
   email. The insertOrIgnore count from point 1 is exactly that signal.

**Files:**
- Create: `app/Mail/Account/WelcomeMail.php`
- Create: `resources/views/emails/account/welcome.blade.php`
- Modify: `app/Services/User/SignupSideEffects.php` (`createWelcomeNotification` return type)
- Modify: `app/Services/PreAccount/ClaimSiteService.php` (capture the flag inside the transaction, send the mail in the post-commit block)
- Modify: `app/Http/Controllers/Dev/MailPreviewController.php` (fixture)
- Test: `tests/Feature/Mail/WelcomeMailTest.php` + a `Mail::fake` assertion in the existing claim tests

**Interfaces:**
- Produces: `new WelcomeMail(string $recipientEmail, string $handle)` — the view derives the public URL as `https://{$handle}.partna.au`.
- Modifies: `SignupSideEffects::createWelcomeNotification(User $professional): int` (was `void`) — returns rows inserted (0 or 1). `UserBootstrapService.php:177`'s call site (the retired/unreachable create branch) needs no change — it already discards the return value as a plain statement.

- [ ] **Step 1: Write the failing render test**

```php
<?php
// tests/Feature/Mail/WelcomeMailTest.php

use App\Mail\Account\WelcomeMail;

it('renders the welcome email with the site address', function () {
    $html = (new WelcomeMail('sam@example.com', 'sams-cafe'))->render();

    expect($html)->toContain('Welcome to Partna')
        ->and($html)->toContain('sams-cafe.partna.au')
        ->and($html)->toContain('Open your dashboard');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Feature/Mail/WelcomeMailTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create the mailable**

```php
<?php
// app/Mail/Account/WelcomeMail.php

namespace App\Mail\Account;

use App\Mail\BaseTransactionalMail;

// One-shot welcome, queued when ClaimSiteService::claim() succeeds — the
// moment the account + site exist. Claim is once-per-account, so no dedupe
// machinery is needed.
class WelcomeMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $handle,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject('Welcome to Partna — your site is live')
            ->view('emails.account.welcome');
    }
}
```

- [ ] **Step 4: Create the view**

```blade
{{-- resources/views/emails/account/welcome.blade.php --}}
@extends('mail.layouts.partna')

@php($siteUrl = "https://{$handle}.partna.au")
@php($dashboardUrl = rtrim((string) config('app.frontend_url', 'https://app.partna.au'), '/'))

@section('preheader', 'Your Partna site is live — here are the first three things to do.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Welcome to Partna
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Your site is live at <a href="{{ $siteUrl }}" style="color:#1367fb; text-decoration:none;">{{ $handle }}.partna.au</a>.
    </p>

    <p class="body-text text-primary" style="margin: 0 0 8px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Three things worth doing first:
    </p>

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0;">
        <tr><td style="padding: 6px 0; font-size: 16px; line-height: 1.5; color: #171717;">1.&nbsp;&nbsp;Connect your first platform, so your content syncs itself.</td></tr>
        <tr><td style="padding: 6px 0; font-size: 16px; line-height: 1.5; color: #171717;">2.&nbsp;&nbsp;Pick your accent and style — your site takes it everywhere.</td></tr>
        <tr><td style="padding: 6px 0; font-size: 16px; line-height: 1.5; color: #171717;">3.&nbsp;&nbsp;Share your link wherever people already find you.</td></tr>
    </table>

    <x-mail.button :href="$dashboardUrl">Open your dashboard</x-mail.button>
@endsection

@section('footer_note', "You're receiving this because you just created a Partna account.")
```

- [ ] **Step 5: Run the render test**

Run: `php artisan test tests/Feature/Mail/WelcomeMailTest.php`
Expected: PASS

- [ ] **Step 6: Change `createWelcomeNotification` to report whether it inserted**

In `app/Services/User/SignupSideEffects.php`, change the signature and return
statement of `createWelcomeNotification`:

```php
    public function createWelcomeNotification(User $professional): int
```

Its body already ends with a single `Notification::query()->insertOrIgnore([...]);`
statement (`SignupSideEffects.php:61-71`) — change that line's leading
statement to `return Notification::query()->insertOrIgnore([` (i.e. just add
`return` in front of the existing call; `insertOrIgnore()` already returns
`int`). No other change to the method body.

`app/Services/User/UserBootstrapService.php:177` calls this as a bare statement
(`$this->sideEffects->createWelcomeNotification($professional);`) — a bare
statement ignores a return value in PHP, so that call site needs NO edit.

- [ ] **Step 7: Capture the flag inside the transaction, send after commit**

In `app/Services/PreAccount/ClaimSiteService.php`, change line 132 from:

```php
            $this->sideEffects->createWelcomeNotification($professional);
```

to:

```php
            $isNewClaim = $this->sideEffects->createWelcomeNotification($professional) > 0;
```

and the transaction's final `return` (line 133) from:

```php
            return ['professional' => $professional->fresh(), 'site' => $site->fresh()];
```

to:

```php
            return ['professional' => $professional->fresh(), 'site' => $site->fresh(), 'is_new_claim' => $isNewClaim];
```

The idempotency-first branch (`ClaimSiteService.php:53-55`) already returns
its own array literal without an `is_new_claim` key — leave it as-is; reading
`$result['is_new_claim'] ?? false` below treats a retry as "not new" for free.

Then, in the post-commit block — right after the existing
`$this->reEnrichClaimedGoogleBusinessConnection($result['professional']);` line
(`ClaimSiteService.php:143`) and before the `EDGE-1` comment block — add:

```php
        // Welcome email — genuinely post-commit (the transaction above has
        // already committed by this point) and gated on is_new_claim so a
        // double-tap / network retry through the idempotency-first branch
        // (which never sets this flag) can never re-send it.
        if (($result['is_new_claim'] ?? false) === true) {
            $email = (string) ($result['professional']->primary_email ?? '');
            if ($email !== '') {
                try {
                    Mail::to($email)->queue(new WelcomeMail($email, (string) $result['site']->subdomain));
                } catch (\Throwable $e) {
                    Log::warning('claim.welcome_mail_failed', ['user_id' => $result['professional']->id, 'error' => $e->getMessage()]);
                }
            }
        }
```

Add imports at the top of the file if not already present:

```php
use App\Mail\Account\WelcomeMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
```

- [ ] **Step 8: Assert in the existing claim tests**

Find them: `grep -rln "ClaimSiteService\|->postJson('/api/claim'\|->postJson(\"/api/claim\"" tests/Feature | head`.
In the test covering a genuinely NEW successful claim, add `Mail::fake()`
before the act and `Mail::assertQueued(WelcomeMail::class)` after. In the test
covering the idempotency-first retry path (same `auth_user_id` claiming
twice), add `Mail::fake()` + `Mail::assertNotQueued(WelcomeMail::class)` (or
`Mail::assertQueuedCount(0)` if that's cleaner against the existing test
structure) to lock in the anti-duplicate behaviour Step 7 exists for.

- [ ] **Step 9: Preview fixture + visual check**

In the gallery's `'Account'` group, first entry:

```php
                'welcome' => ['label' => 'Welcome', 'make' => fn () => new \App\Mail\Account\WelcomeMail('sam@example.com', 'sams-cafe')],
```

Visual check at `http://localhost:8000/dev/emails/welcome`.

- [ ] **Step 10: Run tests and commit**

Run: `php artisan test --filter="WelcomeMail|Claim|SignupSideEffects|Bootstrap"`
Expected: PASS (the `SignupSideEffects`/`Bootstrap` filters catch the return-type change's blast radius — `UserBootstrapService`'s bare-statement call site should be untouched, but run its tests to confirm)

```bash
git add app/Mail/Account resources/views/emails/account/welcome.blade.php app/Services/User/SignupSideEffects.php app/Services/PreAccount/ClaimSiteService.php app/Http/Controllers/Dev/MailPreviewController.php tests/Feature/Mail/WelcomeMailTest.php
git commit -m "feat(mail): welcome email on successful site claim, deduped on the existing insertOrIgnore boundary"
```

---

### Task 5: Enquiry-unanswered reminder (new category + scheduled sweep)

An enquiry unread for 48 hours is a customer walking away. One reminder per
enquiry, ever — the publisher's dedupe key guarantees it with no schema change.
Riding the category machinery gives in-app + email + preference toggle + signed
unsubscribe for free.

**Files:**
- Create: `app/Mail/Notifications/EnquiryReminderMail.php`
- Create: `app/Console/Commands/NotifyUnansweredEnquiries.php`
- Modify: `config/partna.php` — `notifications.mailables` (add `enquiry_reminder`, near the `'inbox'` entry ~line 1982) and `notification_retention_days` (add `'enquiry_reminder' => 14`, ~line 1975)
- Modify: `routes/console.php` (schedule)
- Modify: `app/Http/Controllers/Dev/MailPreviewController.php` (fixture)
- Test: `tests/Feature/Console/NotifyUnansweredEnquiriesTest.php`

**Interfaces:**
- Consumes: `NotificationPublisher::publish(userId, frontendType, category, title, body, dedupeKey, ctaUrl, primaryActionLabel, ..., retentionConfigKey, critical)` (`app/Services/Notifications/NotificationPublisher.php:33`); `Enquiry` model — `read_at` null = unread, `status` enum with `spam`/`archived` states (`app/Models/Core/Site/Enquiry.php`).
- Produces: category key `enquiry_reminder`; command `partna:notify-unanswered-enquiries`.

- [ ] **Step 1: Register the category + mailable + retention**

In `config/partna.php` inside `notifications.mailables` (keep the existing
comment style), add after the `'inbox'` line:

```php
            'enquiry_reminder' => EnquiryReminderMail::class,  // unread-enquiry nudge (partna:notify-unanswered-enquiries)
```

with the import at the top of the file's `use` block:
`use App\Mail\Notifications\EnquiryReminderMail;` (mirror how the other
Notifications mailables are imported there — check the top of the file; if the
registry uses FQCN strings instead of imports, follow that style).

In `notification_retention_days`, add:

```php
        'enquiry_reminder' => 14,  // superseded by the enquiry being handled either way
```

- [ ] **Step 2: Create the mailable (whole file)**

```php
<?php
// app/Mail/Notifications/EnquiryReminderMail.php

namespace App\Mail\Notifications;

// Unread-enquiry nudge. All content lives on the Notification row published by
// partna:notify-unanswered-enquiries; the shared category view renders it.
class EnquiryReminderMail extends CategoryNotificationMail
{
    protected const CATEGORY = 'enquiry_reminder';
}
```

- [ ] **Step 3: Write the failing command test**

```php
<?php
// tests/Feature/Console/NotifyUnansweredEnquiriesTest.php

use App\Models\Core\Notifications\Notification;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\User;

beforeEach(function () {
    setupUsersTable();
    // Enquiries + notifications table helpers — find the exact names with
    // `grep -n "function setup" tests/Pest.php | grep -i "enquir\|notif"`
    // and call the ones the existing enquiry tests use (e.g.
    // tests/Feature/Notifications/DispatchEnquiryNotificationsJobTest.php's
    // beforeEach shows the required set).
    setupNotificationsTables();
    setupEnquiriesTable();
});

it('publishes one reminder per stale unread enquiry and never twice', function () {
    $pro = User::factory()->create(['status' => 'active']);
    $stale = Enquiry::factory()->create([
        'user_id' => $pro->id,
        'read_at' => null,
        'created_at' => now()->subHours(60),
    ]);
    // Fresh (under 48h) and already-read enquiries are ignored.
    Enquiry::factory()->create(['user_id' => $pro->id, 'read_at' => null, 'created_at' => now()->subHours(12)]);
    Enquiry::factory()->create(['user_id' => $pro->id, 'read_at' => now(), 'created_at' => now()->subHours(60)]);

    $this->artisan('partna:notify-unanswered-enquiries')->assertSuccessful();
    $this->artisan('partna:notify-unanswered-enquiries')->assertSuccessful(); // idempotent

    $rows = Notification::query()->where('category', 'enquiry_reminder')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->user_id)->toBe($pro->id)
        ->and($rows->first()->title)->toContain('waiting');
});
```

(If `Enquiry::factory()` does not exist, build rows the way
`DispatchEnquiryNotificationsJobTest` does — `(new Enquiry)->forceFill([...])->save()`
with the same required columns; copy its arrangement verbatim.)

- [ ] **Step 4: Run to verify failure**

Run: `php artisan test tests/Feature/Console/NotifyUnansweredEnquiriesTest.php`
Expected: FAIL — command not found

- [ ] **Step 5: Create the command**

```php
<?php
// app/Console/Commands/NotifyUnansweredEnquiries.php

namespace App\Console\Commands;

use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

// Nudges the owner about enquiries still unread 48h after arrival. One
// reminder per enquiry EVER — the publisher's dedupe key is the enquiry id, so
// re-runs and overlapping schedules are no-ops. The window is bounded at 7
// days so enabling this on an old backlog doesn't flood anyone's inbox.
class NotifyUnansweredEnquiries extends Command
{
    protected $signature = 'partna:notify-unanswered-enquiries {--dry-run}';

    protected $description = 'Remind owners about enquiries unread for 48 hours';

    public function handle(NotificationPublisher $publisher): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $count = 0;

        Enquiry::query()
            ->whereNull('read_at')
            ->whereNotIn('status', ['spam', 'archived', 'replied'])
            ->whereBetween('created_at', [now()->subDays(7), now()->subHours(48)])
            ->orderBy('created_at')
            ->chunkById(200, function ($enquiries) use ($publisher, $dryRun, &$count): void {
                foreach ($enquiries as $enquiry) {
                    $count++;
                    if ($dryRun) {
                        continue;
                    }

                    $name = trim((string) $enquiry->name) !== '' ? trim((string) $enquiry->name) : 'A visitor';
                    $subject = Str::limit(trim((string) $enquiry->subject), 60);

                    $publisher->publish(
                        userId: (string) $enquiry->user_id,
                        frontendType: 'Warning',
                        category: 'enquiry_reminder',
                        title: "{$name} is still waiting to hear back",
                        body: "Their enquiry \"{$subject}\" arrived on {$enquiry->created_at->toFormattedDateString()} and hasn't been opened yet. A quick reply keeps the lead warm.",
                        dedupeKey: "enquiry_reminder:{$enquiry->id}",
                        ctaUrl: '/account/features/enquiries',
                        primaryActionLabel: 'Open enquiries',
                        retentionConfigKey: 'enquiry_reminder',
                        critical: false,
                    );
                }
            });

        $this->info(($dryRun ? '[dry-run] would remind about ' : 'Published reminders for ')."{$count} enquiries.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Run the test**

Run: `php artisan test tests/Feature/Console/NotifyUnansweredEnquiriesTest.php`
Expected: PASS

- [ ] **Step 7: Schedule it**

In `routes/console.php`, near the other notification schedules (the
`notifications:prune-unsubscribed-subscriptions` block ~line 368 shows the house
style — `onOneServer`, `withoutOverlapping`, `onFailure($reportScheduledFailure(...))`):

```php
// Unread-enquiry nudges — hourly; the 48h window + per-enquiry dedupe make
// cadence a non-issue (each enquiry can only ever produce one reminder).
Schedule::command('partna:notify-unanswered-enquiries')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure($reportScheduledFailure('partna:notify-unanswered-enquiries'));
```

- [ ] **Step 8: Coverage + preview + full category checks**

Run: `php artisan test tests/Feature/Notifications/MailableCategoryCoverageTest.php`
Expected: PASS (the command's literal `category: 'enquiry_reminder'` satisfies the sweep)

Gallery fixture, in the `'Category notifications'` group:

```php
                'notif-enquiry-reminder' => ['label' => 'Enquiry reminder', 'make' => fn () => new \App\Mail\Notifications\EnquiryReminderMail($this->fakeNotification('enquiry_reminder', "Riley O'Brien is still waiting to hear back", 'Their enquiry "Booking for Saturday" arrived on Aug 10, 2026 and hasn\'t been opened yet. A quick reply keeps the lead warm.'))],
```

Visual check: headline, body, "Manage notification emails · Unsubscribe" footer.

- [ ] **Step 9: Commit**

```bash
git add config/partna.php app/Mail/Notifications/EnquiryReminderMail.php app/Console/Commands/NotifyUnansweredEnquiries.php routes/console.php app/Http/Controllers/Dev/MailPreviewController.php tests/Feature/Console/NotifyUnansweredEnquiriesTest.php
git commit -m "feat(mail): unread-enquiry reminder — new enquiry_reminder category + hourly sweep"
```

---

### Task 6: Weekly analytics digest with real numbers

Upgrade the in-app stub (`NotifyWeeklySummary`) into the real thing: last full
week's visits / visitors / taps + top link, in-app AND email. The email is a
custom mailable (numbers don't fit the generic category view) but stays
preference-gated under `analytics_weekly` and carries the category's signed
unsubscribe via Task 1's helper.

**Files:**
- Create: `app/Mail/Account/WeeklyDigestMail.php`
- Create: `resources/views/emails/account/weekly-digest.blade.php`
- Modify: `app/Console/Commands/NotifyWeeklySummary.php` (full rewrite of `handle()`)
- Modify: `app/Http/Controllers/Dev/MailPreviewController.php` (fixture)
- Test: `tests/Feature/Mail/WeeklyDigestMailTest.php`, extend the existing `NotifyWeeklySummary` tests (find: `grep -rln "notify-weekly-summary\|NotifyWeeklySummary" tests/`)

**Interfaces:**
- Consumes: `AnalyticsQueryService::visitsAggregate($userId, $from, $to)` → stdClass `{total_visits, unique_visitors, last_visit_at}`; `clicksAggregate(...)` → `{total_clicks, unique_clickers, last_click_at}`; `topLinks($userId, $from, $to)` → Collection of `{url, platform, label, section_key, clicks}` (`app/Services/Analytics/AnalyticsQueryService.php:92,102,257`); `CategoryUnsubscribe::urlFor()` (Task 1); `NotificationPublisher::resolveEmailEnabled($userId, 'analytics_weekly')`; `publish()` returns `?Notification` — non-null means "new this week", which is the email-send gate (dedupe for free).
- Produces: `new WeeklyDigestMail(string $recipientEmail, ?string $displayName, string $weekLabel, int $visits, int $visitors, int $taps, ?string $topLinkLabel, ?int $topLinkClicks, ?string $unsubscribeUrl)`.

- [ ] **Step 1: Write the failing render test**

```php
<?php
// tests/Feature/Mail/WeeklyDigestMailTest.php

use App\Mail\Account\WeeklyDigestMail;

it('renders the digest with the week numbers and unsubscribe affordance', function () {
    $html = (new WeeklyDigestMail(
        'sam@example.com', 'Sam', '4–10 August',
        214, 161, 38, 'Instagram', 17,
        'https://api.partna.au/api/public/notification-unsubscribe/u/analytics_weekly?signature=x',
    ))->render();

    expect($html)->toContain('Your week on Partna')
        ->and($html)->toContain('214')
        ->and($html)->toContain('161')
        ->and($html)->toContain('38')
        ->and($html)->toContain('Instagram')
        ->and($html)->toContain('Unsubscribe');
});

it('omits the top-link row and unsubscribe when absent', function () {
    $html = (new WeeklyDigestMail('sam@example.com', null, '4–10 August', 3, 2, 0, null, null, null))->render();

    expect($html)->not->toContain('Most tapped')
        ->and($html)->not->toContain('Unsubscribe');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Feature/Mail/WeeklyDigestMailTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create the mailable**

```php
<?php
// app/Mail/Account/WeeklyDigestMail.php

namespace App\Mail\Account;

use App\Mail\BaseTransactionalMail;

/**
 * Weekly analytics digest — real numbers for the last full week. Sent by
 * partna:notify-weekly-summary alongside the in-app notification; gated there
 * by the analytics_weekly preference, so it carries that category's one-click
 * unsubscribe headers (RFC 8058) like any category mail would.
 */
class WeeklyDigestMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly ?string $displayName,
        public readonly string $weekLabel,
        public readonly int $visits,
        public readonly int $visitors,
        public readonly int $taps,
        public readonly ?string $topLinkLabel,
        public readonly ?int $topLinkClicks,
        public readonly ?string $unsubscribeUrl,
    ) {}

    public function build(): self
    {
        $mail = $this->buildEnvelope()
            ->to($this->recipientEmail)
            ->subject("Your week on Partna — {$this->visits} visits")
            ->view('emails.account.weekly-digest');

        if ($this->unsubscribeUrl !== null) {
            $url = $this->unsubscribeUrl;
            $mail->withSymfonyMessage(function ($message) use ($url): void {
                $headers = $message->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', '<'.$url.'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
        }

        return $mail;
    }
}
```

- [ ] **Step 4: Create the view**

```blade
{{-- resources/views/emails/account/weekly-digest.blade.php --}}
@extends('mail.layouts.partna')

@php($dashboardUrl = rtrim((string) config('app.frontend_url', 'https://app.partna.au'), '/'))

@section('preheader', "Your site last week: {$visits} visits, {$taps} taps.")

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Your week on Partna
    </h1>

    <p class="body-text text-secondary" style="margin: 0 0 20px 0; font-size: 14px; line-height: 1.5; color: #7d7d7d;">
        {{ $weekLabel }}
    </p>

    {{-- Stat band: three equal cells on the well surface. Table-based on
         purpose — see the layout header comment about Outlook. --}}
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 20px 0;">
        <tr>
            <td width="33%" align="center" style="background-color:#f2f2f2; border-radius:12px 0 0 12px; padding: 18px 8px;">
                <div style="font-size: 30px; font-weight: 600; line-height: 1.1; color:#171717;">{{ number_format($visits) }}</div>
                <div style="font-size: 12px; line-height: 1.5; color:#7d7d7d;">Visits</div>
            </td>
            <td width="34%" align="center" style="background-color:#f2f2f2; padding: 18px 8px; border-left: 1px solid #ffffff; border-right: 1px solid #ffffff;">
                <div style="font-size: 30px; font-weight: 600; line-height: 1.1; color:#171717;">{{ number_format($visitors) }}</div>
                <div style="font-size: 12px; line-height: 1.5; color:#7d7d7d;">Visitors</div>
            </td>
            <td width="33%" align="center" style="background-color:#f2f2f2; border-radius:0 12px 12px 0; padding: 18px 8px;">
                <div style="font-size: 30px; font-weight: 600; line-height: 1.1; color:#171717;">{{ number_format($taps) }}</div>
                <div style="font-size: 12px; line-height: 1.5; color:#7d7d7d;">Taps</div>
            </td>
        </tr>
    </table>

    @if ($topLinkLabel !== null)
        <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 15px; line-height: 1.5; color: #171717;">
            Most tapped: <strong>{{ $topLinkLabel }}</strong>@if ($topLinkClicks !== null) &nbsp;·&nbsp; {{ number_format($topLinkClicks) }} {{ $topLinkClicks === 1 ? 'tap' : 'taps' }}@endif
        </p>
    @endif

    <x-mail.button :href="$dashboardUrl.'/analytics'">See full analytics</x-mail.button>
@endsection

@if (($unsubscribeUrl ?? null) !== null)
    @section('footer_note')
        You're receiving this weekly summary because you have an account at Partna.
        <span style="white-space:nowrap;"><a href="{{ rtrim((string) config('app.frontend_url', 'https://app.partna.au'), '/') }}/settings/notifications" style="color:#8f8f8f; text-decoration:underline;">Manage notification emails</a></span>
        &nbsp;·&nbsp;
        <span style="white-space:nowrap;"><a href="{{ $unsubscribeUrl }}" style="color:#8f8f8f; text-decoration:underline;">Unsubscribe</a></span>
    @endsection
@endif
```

- [ ] **Step 5: Run the render tests**

Run: `php artisan test tests/Feature/Mail/WeeklyDigestMailTest.php`
Expected: PASS

- [ ] **Step 6: Rewrite NotifyWeeklySummary::handle()**

Replace the class body of `app/Console/Commands/NotifyWeeklySummary.php` (keep
signature/description; the stub's generic in-app copy is superseded):

```php
    public function handle(NotificationPublisher $publisher, AnalyticsQueryService $analytics): int
    {
        $week = now()->format('o-\WW');
        $dryRun = (bool) $this->option('dry-run');

        // Last FULL ISO week (Mon–Sun), so Monday's run reports a closed window.
        $from = now()->subWeek()->startOfWeek();
        $to = now()->subWeek()->endOfWeek();
        $weekLabel = $from->format('j M').'–'.$to->format('j M');

        $emailEnabled = (bool) config('partna.notifications.email_enabled', false);
        $count = 0;
        $emailed = 0;

        User::query()
            ->where('status', 'active')
            ->has('site')
            ->select(['id', 'display_name'])
            ->chunkById(200, function ($users) use ($publisher, $analytics, $week, $weekLabel, $from, $to, $dryRun, $emailEnabled, &$count, &$emailed): void {
                foreach ($users as $user) {
                    $visits = $analytics->visitsAggregate((string) $user->id, $from, $to);
                    $clicks = $analytics->clicksAggregate((string) $user->id, $from, $to);

                    $totalVisits = (int) $visits->total_visits;
                    $totalTaps = (int) $clicks->total_clicks;

                    // Quiet week → no digest at all. A "0 visits" email is a
                    // churn letter, and the in-app nudge would just restate it.
                    if ($totalVisits === 0 && $totalTaps === 0) {
                        continue;
                    }

                    $count++;
                    if ($dryRun) {
                        continue;
                    }

                    $notification = $publisher->publish(
                        userId: (string) $user->id,
                        frontendType: 'Info',
                        category: 'analytics_weekly',
                        title: 'Your week on Partna',
                        body: "Last week ({$weekLabel}): {$totalVisits} visits from {$visits->unique_visitors} visitors, {$totalTaps} taps. Open analytics for the full picture.",
                        dedupeKey: "analytics_weekly:{$user->id}:{$week}",
                        ctaUrl: '/account/overview',
                        primaryActionLabel: 'View dashboard',
                        retentionConfigKey: 'analytics_weekly',
                        critical: false,
                    );

                    // publish() returns null when this week's row already exists
                    // — that's the email dedupe too: only a NEW row emails.
                    if ($notification === null || ! $emailEnabled) {
                        continue;
                    }

                    if (! NotificationPublisher::resolveEmailEnabled((string) $user->id, 'analytics_weekly')) {
                        continue;
                    }

                    $email = (string) (User::query()->whereKey($user->id)->value('primary_email') ?? '');
                    if ($email === '') {
                        continue;
                    }

                    $top = $analytics->topLinks((string) $user->id, $from, $to)->first();

                    Mail::to($email)->queue(new WeeklyDigestMail(
                        recipientEmail: $email,
                        displayName: $user->display_name,
                        weekLabel: $weekLabel,
                        visits: $totalVisits,
                        visitors: (int) $visits->unique_visitors,
                        taps: $totalTaps,
                        topLinkLabel: $top?->label ?? $top?->platform,
                        topLinkClicks: $top ? (int) $top->clicks : null,
                        unsubscribeUrl: CategoryUnsubscribe::urlFor((string) $user->id, 'analytics_weekly'),
                    ));
                    $emailed++;
                }
            });

        $this->info(($dryRun ? '[dry-run] would notify ' : 'Published weekly summary to ')."{$count} users, emailed {$emailed} (week {$week}).");

        return self::SUCCESS;
    }
```

Imports to add at the top of the file:

```php
use App\Mail\Account\WeeklyDigestMail;
use App\Mail\Support\CategoryUnsubscribe;
use App\Services\Analytics\AnalyticsQueryService;
use Illuminate\Support\Facades\Mail;
```

Note the `primary_email` fetch: the chunk selects only `id, display_name` (the
stub selected `id`); either add `primary_email` to the select if it is a plain
column, or keep the `value()` lookup as written if it's an accessor — check
`app/Models/Core/User/User.php` for whether `primary_email` is a column or an
attribute accessor, and use the cheaper form.

- [ ] **Step 7: Extend the existing command tests**

Find them (`grep -rln "NotifyWeeklySummary\|notify-weekly-summary" tests/`) and:
1. Fix any assertion that expects the old generic body / the old "notify every
   user" behaviour — quiet users are now skipped.
2. Add a `Mail::fake()` case: user with visit rows in the window +
   `config(['partna.notifications.email_enabled' => true])` →
   `Mail::assertQueued(WeeklyDigestMail::class)`; second run → no second queue
   (publish() dedupe). Analytics table helpers: the existing analytics query
   tests show the setup (`grep -rln "site_visits" tests/ | head`).
3. Quiet user (no rows) → no notification row, nothing queued.

- [ ] **Step 8: Preview fixture + visual check**

Gallery `'Account'` group:

```php
                'weekly-digest' => ['label' => 'Weekly digest', 'make' => fn () => new \App\Mail\Account\WeeklyDigestMail('sam@example.com', 'Sam', '4–10 Aug', 214, 161, 38, 'Instagram', 17, 'https://api.partna.au/preview-unsubscribe')],
```

Visual check: the stat band reads as one rounded well split in three; check
mobile toggle too (numbers must not wrap).

- [ ] **Step 9: Run tests and commit**

Run: `php artisan test --filter="WeeklyDigest|WeeklySummary"`
Expected: PASS

```bash
git add app/Mail/Account/WeeklyDigestMail.php resources/views/emails/account/weekly-digest.blade.php app/Console/Commands/NotifyWeeklySummary.php app/Http/Controllers/Dev/MailPreviewController.php tests/Feature/Mail/WeeklyDigestMailTest.php
git commit -m "feat(mail): weekly analytics digest with real numbers, pref-gated + one-click unsubscribe"
```

---

### Task 7: Achievement emails

`AchievementNotifier` already publishes in-app milestones under the
`achievement` category (registered with a null mailable). Registering a
mailable turns them into celebration emails through the standard pipeline —
preference-gated, unsubscribable, deduped.

**Files:**
- Create: `app/Mail/Notifications/AchievementMail.php`
- Modify: `config/partna.php` — change `'achievement' => null` to `'achievement' => AchievementMail::class` (in the OV-H block ~line 1991)
- Modify: `app/Http/Controllers/Dev/MailPreviewController.php` (fixture)
- Test: covered by the existing pipeline tests + gallery render; add one render test below

- [ ] **Step 1: Create the mailable (whole file)**

```php
<?php
// app/Mail/Notifications/AchievementMail.php

namespace App\Mail\Notifications;

// Celebration mail for AchievementNotifier milestones (first enquiry, visit
// milestones). Registered against the existing `achievement` category, so it
// inherits its dedupe, preference toggle and one-click unsubscribe.
class AchievementMail extends CategoryNotificationMail
{
    protected const CATEGORY = 'achievement';
}
```

- [ ] **Step 2: Register it**

In `config/partna.php`, the OV-H auto-dispatchers block: change

```php
            'achievement' => null,                                // in-app only (milestones / first-enquiry)
```

to

```php
            'achievement' => AchievementMail::class,              // milestones / first-enquiry — celebration mail
```

(with the import following the file's established style, as in Task 5 Step 1.)

- [ ] **Step 3: Add a render test**

Append to `tests/Feature/Mail/WeeklyDigestMailTest.php`… no — its own file:

```php
<?php
// tests/Feature/Mail/AchievementMailTest.php

use App\Mail\Notifications\AchievementMail;
use App\Models\Core\Notifications\Notification;

it('renders an achievement through the shared category view with unsubscribe', function () {
    $n = (new Notification)->forceFill([
        'user_id' => '00000000-0000-4000-8000-000000000001',
        'category' => 'achievement',
        'title' => 'Your first enquiry just arrived',
        'body' => 'Someone reached out through your site. Reply while it\'s warm.',
        'cta_url' => 'https://app.partna.au/contact',
        'primary_action_label' => 'Open enquiries',
    ]);

    $html = (new AchievementMail($n))->render();

    expect($html)->toContain('Your first enquiry just arrived')
        ->and($html)->toContain('Unsubscribe');
});
```

- [ ] **Step 4: Run tests (render + coverage + email-job family)**

Run: `php artisan test tests/Feature/Mail/AchievementMailTest.php tests/Feature/Notifications/MailableCategoryCoverageTest.php --filter="Achievement|Coverage"`
Expected: PASS (`AchievementNotifier` already carries the literal `category: 'achievement'` publish call site)

- [ ] **Step 5: Preview fixture + visual check**

Gallery `'Category notifications'` group:

```php
                'notif-achievement' => ['label' => 'Achievement', 'make' => fn () => new \App\Mail\Notifications\AchievementMail($this->fakeNotification('achievement', 'Your first enquiry just arrived', 'Someone reached out through your site. Reply while it\'s warm.'))],
```

- [ ] **Step 6: Commit**

```bash
git add app/Mail/Notifications/AchievementMail.php config/partna.php app/Http/Controllers/Dev/MailPreviewController.php tests/Feature/Mail/AchievementMailTest.php
git commit -m "feat(mail): achievement celebration emails via the existing achievement category"
```

---

### Task 8: Full verification and ship

**Files:** none new — verification + push.

- [ ] **Step 1: Full gallery sweep**

`php artisan serve --port=8000` → `http://localhost:8000/dev/emails`. Every NEW
entry (two-factor-removed, password-changed, two-factor-enabled, welcome,
enquiry reminder, weekly digest, achievement): light, dark toggle (must look
identical — always-light), mobile toggle. Screenshot each; anything off gets
fixed before proceeding.

- [ ] **Step 2: Text-part spot check**

```bash
php artisan tinker --execute='
config(["mail.default" => "array"]);
Mail::to("t@example.com")->send(new App\Mail\Account\WeeklyDigestMail("t@example.com","Sam","4–10 Aug",214,161,38,"Instagram",17,null));
$m = app("mail.manager")->mailer("array")->getSymfonyTransport()->messages()->first()->getOriginalMessage();
echo $m->getTextBody();
'
```

Expected: clean plain text — numbers present, no leaked URLs from image-only
anchors, no MSO noise.

- [ ] **Step 3: Full test suite**

Run: `php artisan test`
Expected: 0 failures. (Budget ~15 minutes.)

- [ ] **Step 4: Pint + audit-pipeline check**

```bash
vendor/bin/pint --dirty
php artisan test tests/Feature/Architecture tests/Feature/Security/BotProtectionCoverageTest.php
```

Expected: PASS — new namespaces (`app/Mail/Security`, `app/Mail/Account`) live
under paths the audit lenses already read (`app/Mail` is in the
services-integrations arm); the new route is authed (`/me/...`), so no
bot-protection exemption is needed.

- [ ] **Step 5: Update this plan's status + ship**

Append a dated "shipped" note to this file (or delete it per repo doctrine once
fully shipped), then:

```bash
git fetch && git status   # multi-chat rule — rebase if behind
git pull --rebase origin development
php artisan test --filter="Mail|Notification"   # re-verify if rebased over changes
git push origin development
```

Post-deploy: `cloud env:logs partna development --minutes 10` for anything
loud, and remind the owner of the two cross-repo one-liners (Task 3's dashboard
pings) that unlock password-changed and two-factor-enabled in production.
```

---

## Self-review notes (done at plan time)

- Every new Mailable extends `BaseTransactionalMail` directly or via
  `CategoryNotificationMail` → arch test safe; `CategoryUnsubscribe` goes in
  the exempt `App\Mail\Support`.
- New categories: `enquiry_reminder` (emit site = its command → coverage test
  satisfied); `achievement` gains a mailable but already had its literal emit
  in `AchievementNotifier`. `analytics_weekly` stays `null` in the registry
  (email is direct-queued by the command), which the unsubscribe controller
  accepts (`array_key_exists`) and the coverage sweep doesn't flag (it checks
  emit sites, and NotifyWeeklySummary carries the literal).
- Constructor shapes referenced across tasks match: security mails
  `(string, ?string)`; `WelcomeMail(string, string)`; `WeeklyDigestMail`'s 9
  args are named in Task 6 Steps 1/3/6 identically.
- No schema changes anywhere; every dedupe is a publisher dedupe key or a
  `Cache::add` TTL.
