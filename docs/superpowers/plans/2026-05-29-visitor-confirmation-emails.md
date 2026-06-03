# Visitor Confirmation Emails Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a public visitor submits an enquiry or joins a newsletter list, the visitor receives a confirmation email.

**Architecture:** Tier-2 "external recipient" transactional email — a dedicated `ShouldQueue` job per case that re-fetches the recipient at run time (UUID-only payload), checks a per-block toggle + a per-recipient rate limit, sends a `BaseTransactionalMail` subclass, and stamps a `confirmation_sent_at` idempotency column. No `AccountCapabilities` gate (public-submission origin, like `SendEnquiryNotificationJob`). Not registered in `config('partna.notifications.mailables')` — that registry is for account-holder notifications only.

**Tech Stack:** PHP 8.2 / Laravel 12, Pest 4, Supabase Postgres (raw-SQL migrations), Redis-backed `RateLimiter`, SQLite-in-memory test schema (manual `CREATE TABLE` helpers).

**Spec:** `docs/superpowers/specs/2026-05-29-visitor-confirmation-emails-design.md`

**Commits:** Per project workflow, Josh runs commits (or explicitly approves). Each task ends with the commit command for completeness; an executor should stage + prepare it and confirm before committing, and never push.

---

## File structure

**Create**
- `supabase/migrations/20260530010000_add_visitor_confirmation_sent_at.sql` — adds `confirmation_sent_at` to both source tables.
- `app/Mail/EnquiryConfirmationMail.php` — visitor enquiry receipt mailable.
- `app/Mail/SubscriptionConfirmationMail.php` — visitor subscription receipt mailable (with unsubscribe).
- `resources/views/emails/enquiry-confirmation.blade.php` — plain-HTML view (matches sibling `enquiry-notification.blade.php`).
- `resources/views/emails/subscription-confirmation.blade.php` — plain-HTML view with unsubscribe link.
- `app/Jobs/Notifications/SendEnquiryConfirmationJob.php`
- `app/Jobs/Notifications/SendSubscriptionConfirmationJob.php`
- `tests/Feature/Mail/VisitorConfirmationMailTest.php` — mailable build/render assertions.
- `tests/Feature/Notifications/EnquiryConfirmationEmailTest.php` — enquiry job behaviour.
- `tests/Feature/Notifications/SubscriptionConfirmationEmailTest.php` — subscription job behaviour.
- `tests/Feature/Notifications/SubscriptionConfirmationDispatchTest.php` — controller genuine-opt-in dispatch.

**Modify**
- `app/Models/Core/Site/Enquiry.php` — `confirmation_sent_at` fillable + cast.
- `app/Models/Core/Notifications/EmailSubscription.php` — `confirmation_sent_at` fillable + cast.
- `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php` — dispatch the enquiry confirmation job.
- `app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php` — genuine-opt-in detection + stamp reset + dispatch.
- `config/partna.php` — `throttle.visitor_confirmation_per_hour`.
- `tests/Helpers/EnquiryInboxTestHelpers.php` — add `confirmation_sent_at` to the `site.enquiries` test schema.
- `tests/Pest.php` — add `confirmation_sent_at` to `setupEmailSubscriptionsTable()`.
- `tests/Feature/Contact/PublicEnquirySubmissionTest.php` — assert the enquiry confirmation job is dispatched.

---

## Task 1: Schema migration, model casts, and test-schema columns

**Files:**
- Create: `supabase/migrations/20260530010000_add_visitor_confirmation_sent_at.sql`
- Modify: `app/Models/Core/Site/Enquiry.php:36` (fillable) and `:63` (casts)
- Modify: `app/Models/Core/Notifications/EmailSubscription.php:43` (fillable) and `:50` (casts)
- Modify: `tests/Helpers/EnquiryInboxTestHelpers.php` (`site.enquiries` CREATE)
- Modify: `tests/Pest.php` (`setupEmailSubscriptionsTable()`)

- [ ] **Step 1: Write the migration**

Create `supabase/migrations/20260530010000_add_visitor_confirmation_sent_at.sql`:

```sql
-- =====================================================================
-- Visitor confirmation idempotency stamp
-- =====================================================================
-- Adds confirmation_sent_at to the two public-submission source tables.
-- Set once the visitor-facing confirmation email is delivered, so job
-- retries / Horizon scale-out never double-send. Separate from
-- site.enquiries.email_sent_at, which tracks the PROFESSIONAL's inbox
-- notification (a different recipient + concern).
--
-- On a genuine re-subscribe (unsubscribed -> subscribed) the column is
-- reset to NULL in PublicEmailSubscriptionController so a real opt-in
-- re-confirms. NULLABLE, no DB default.
-- =====================================================================

BEGIN;

ALTER TABLE site.enquiries
    ADD COLUMN IF NOT EXISTS confirmation_sent_at timestamptz NULL;

ALTER TABLE notifications.email_subscriptions
    ADD COLUMN IF NOT EXISTS confirmation_sent_at timestamptz NULL;

COMMIT;
```

- [ ] **Step 2: Add the column to the `Enquiry` model**

In `app/Models/Core/Site/Enquiry.php`, add `'confirmation_sent_at'` to `$fillable` (after `'email_sent_at',` on line 36):

```php
        'email_sent_at',
        'confirmation_sent_at',
```

And add the cast in `$casts` (after `'email_sent_at' => 'datetime',` on line 63):

```php
        'email_sent_at' => 'datetime',
        'confirmation_sent_at' => 'datetime',
```

- [ ] **Step 3: Add the column to the `EmailSubscription` model**

In `app/Models/Core/Notifications/EmailSubscription.php`, add `'confirmation_sent_at'` to `$fillable` (after `'email_lc',` on line 42):

```php
        'email_lc',
        'confirmation_sent_at',
```

And add to `$casts` (after `'unsubscribed_at' => 'datetime',` on line 47):

```php
        'unsubscribed_at' => 'datetime',
        'confirmation_sent_at' => 'datetime',
```

- [ ] **Step 4: Add the column to the enquiry test schema**

In `tests/Helpers/EnquiryInboxTestHelpers.php`, inside `setupContactInboxSchema()`'s `site.enquiries` CREATE TABLE, add the column after `email_sent_at TEXT NULL,`:

```sql
            email_sent_at TEXT NULL,
            confirmation_sent_at TEXT NULL,
```

- [ ] **Step 5: Add the column to the subscriptions test schema**

In `tests/Pest.php`, inside `setupEmailSubscriptionsTable()`'s CREATE TABLE, add after `unsubscribe_token TEXT NOT NULL,`:

```sql
        unsubscribe_token TEXT NOT NULL,
        confirmation_sent_at TEXT NULL,
```

- [ ] **Step 6: Verify the suite still boots (no behaviour yet)**

Run: `php artisan test tests/Feature/Notifications/MailableCategoryCoverageTest.php`
Expected: PASS (existing test unaffected — sanity that config + schema load).

- [ ] **Step 7: Commit**

```bash
git add supabase/migrations/20260530010000_add_visitor_confirmation_sent_at.sql app/Models/Core/Site/Enquiry.php app/Models/Core/Notifications/EmailSubscription.php tests/Helpers/EnquiryInboxTestHelpers.php tests/Pest.php
git commit -m "feat(email): add confirmation_sent_at idempotency column for visitor confirmations"
```

---

## Task 2: Config throttle key

**Files:**
- Modify: `config/partna.php:782` (throttle block)

- [ ] **Step 1: Add the per-recipient throttle**

In `config/partna.php`, inside the `'throttle' => [ ... ]` block, add after the `enquiry_notification_per_hour` line (line 782):

```php
        'enquiry_notification_per_hour' => (int) env('PARTNA_ENQUIRY_NOTIFY_PER_HOUR', env('SIDEST_ENQUIRY_NOTIFY_PER_HOUR', 10)),
        // Max visitor-facing confirmation emails per recipient address per hour
        // (shared across enquiry + subscription confirmations). Public forms send
        // to attacker-controllable addresses, so this caps email-bombing.
        'visitor_confirmation_per_hour' => (int) env('PARTNA_VISITOR_CONFIRMATION_PER_HOUR', 5),
```

- [ ] **Step 2: Verify config loads**

Run: `php artisan tinker --execute="echo config('partna.throttle.visitor_confirmation_per_hour');"`
Expected: `5`

- [ ] **Step 3: Commit**

```bash
git add config/partna.php
git commit -m "feat(email): add visitor_confirmation_per_hour throttle config"
```

---

## Task 3: `EnquiryConfirmationMail` + view

**Files:**
- Create: `app/Mail/EnquiryConfirmationMail.php`
- Create: `resources/views/emails/enquiry-confirmation.blade.php`
- Test: `tests/Feature/Mail/VisitorConfirmationMailTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Mail/VisitorConfirmationMailTest.php`:

```php
<?php

use App\Mail\EnquiryConfirmationMail;

it('builds the enquiry confirmation with subject, body, and pro reply-to', function () {
    $mail = new EnquiryConfirmationMail(
        proDisplayName: 'Test Pro',
        visitorName: 'Sarah',
        subject: 'Press',
        siteUrl: 'https://testpro.partna.au',
        replyToEmail: 'pro@example.com',
    );

    $mail->assertHasSubject('We received your enquiry — Test Pro');
    $mail->assertSeeInHtml('Test Pro');
    $mail->assertSeeInHtml('Press');
    $mail->assertHasReplyTo('pro@example.com');
});

it('falls back to the Partna reply-to when the pro has no contact email', function () {
    $mail = new EnquiryConfirmationMail(
        proDisplayName: 'Test Pro',
        visitorName: 'Sarah',
        subject: 'Press',
        siteUrl: 'https://testpro.partna.au',
        replyToEmail: null,
    );

    $mail->assertHasReplyTo('hello@partna.au');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Mail/VisitorConfirmationMailTest.php`
Expected: FAIL — `Class "App\Mail\EnquiryConfirmationMail" not found`.

- [ ] **Step 3: Write the mailable**

Create `app/Mail/EnquiryConfirmationMail.php`:

```php
<?php

namespace App\Mail;

// Visitor-facing "we received your enquiry" receipt, sent to the person who
// submitted the contact form. Reply-To is set to the professional's contact
// inbox so a visitor reply reaches them directly. Tier-2 transactional email:
// not registered in config('partna.notifications.mailables').
class EnquiryConfirmationMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $proDisplayName,
        public readonly string $visitorName,
        public readonly string $subject,
        public readonly string $siteUrl,
        public readonly ?string $replyToEmail,
    ) {}

    public function build(): self
    {
        $this->buildEnvelope()
            ->subject("We received your enquiry — {$this->proDisplayName}")
            ->view('emails.enquiry-confirmation', [
                'proDisplayName' => $this->proDisplayName,
                'visitorName' => $this->visitorName,
                'subject' => $this->subject,
                'siteUrl' => $this->siteUrl,
            ]);

        // Replace the Partna default reply-to so visitor replies reach the pro.
        // buildEnvelope() seeds the default; we drop it and set the pro inbox.
        if ($this->replyToEmail !== null && trim($this->replyToEmail) !== '') {
            $this->replyTo = [];
            $this->replyTo(trim($this->replyToEmail), $this->proDisplayName);
        }

        return $this;
    }
}
```

- [ ] **Step 4: Write the view**

Create `resources/views/emails/enquiry-confirmation.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>We received your enquiry</title></head>
<body style="font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; font-size: 14px; color: #111; line-height: 1.5;">
    <h2 style="margin: 0 0 16px;">Thanks{{ $visitorName !== '' ? ', '.$visitorName : '' }} — we've got your enquiry</h2>

    <p style="margin: 0 0 12px;">{{ $proDisplayName }} has received your message about &ldquo;{{ $subject }}&rdquo; and will get back to you soon.</p>

    <p style="margin: 0 0 12px;">You can reply directly to this email if you need to add anything.</p>

    <p style="margin: 16px 0 0;">
        <a href="{{ $siteUrl }}" style="color: #3a6efc;">Visit {{ $proDisplayName }}'s page</a>
    </p>
</body>
</html>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Mail/VisitorConfirmationMailTest.php`
Expected: PASS (2 passed).

- [ ] **Step 6: Commit**

```bash
git add app/Mail/EnquiryConfirmationMail.php resources/views/emails/enquiry-confirmation.blade.php tests/Feature/Mail/VisitorConfirmationMailTest.php
git commit -m "feat(email): add EnquiryConfirmationMail visitor receipt"
```

---

## Task 4: `SubscriptionConfirmationMail` + view

**Files:**
- Create: `app/Mail/SubscriptionConfirmationMail.php`
- Create: `resources/views/emails/subscription-confirmation.blade.php`
- Test: `tests/Feature/Mail/VisitorConfirmationMailTest.php` (append)

- [ ] **Step 1: Add the failing test**

Append to `tests/Feature/Mail/VisitorConfirmationMailTest.php`:

```php
it('builds the subscription confirmation with subject, body, and unsubscribe link', function () {
    $mail = new App\Mail\SubscriptionConfirmationMail(
        proDisplayName: 'Test Pro',
        siteUrl: 'https://testpro.partna.au',
        unsubscribeUrl: 'https://api.partna.au/api/public/unsubscribe/tok123',
        visitorName: 'Sarah',
    );

    $mail->assertHasSubject("You're subscribed — Test Pro");
    $mail->assertSeeInHtml('Test Pro');
    $mail->assertSeeInHtml('https://api.partna.au/api/public/unsubscribe/tok123');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Mail/VisitorConfirmationMailTest.php`
Expected: FAIL — `Class "App\Mail\SubscriptionConfirmationMail" not found`.

- [ ] **Step 3: Write the mailable**

Create `app/Mail/SubscriptionConfirmationMail.php`:

```php
<?php

namespace App\Mail;

// Visitor-facing "you're subscribed" receipt, sent to the person who joined a
// newsletter list. Carries the unsubscribe link + RFC 8058 one-click headers
// (same pattern as StaffBroadcastMail). Tier-2 transactional email: not
// registered in config('partna.notifications.mailables').
class SubscriptionConfirmationMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly string $proDisplayName,
        public readonly string $siteUrl,
        public readonly string $unsubscribeUrl,
        public readonly ?string $visitorName = null,
    ) {}

    public function build(): self
    {
        return $this->buildEnvelope()
            ->subject("You're subscribed — {$this->proDisplayName}")
            ->view('emails.subscription-confirmation', [
                'proDisplayName' => $this->proDisplayName,
                'siteUrl' => $this->siteUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'visitorName' => $this->visitorName,
            ])
            ->withSymfonyMessage(function ($message): void {
                // RFC 8058 one-click unsubscribe — required by Gmail/Yahoo bulk
                // rules. Mirrors StaffBroadcastMail. buildEnvelope() already added
                // its own withSymfonyMessage callback; both run.
                $headers = $message->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', '<'.$this->unsubscribeUrl.'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }
}
```

- [ ] **Step 4: Write the view**

Create `resources/views/emails/subscription-confirmation.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>You're subscribed</title></head>
<body style="font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; font-size: 14px; color: #111; line-height: 1.5;">
    <h2 style="margin: 0 0 16px;">You're subscribed{{ $visitorName ? ', '.$visitorName : '' }}</h2>

    <p style="margin: 0 0 12px;">Thanks for joining {{ $proDisplayName }}'s list. You'll hear about news and updates straight from them.</p>

    <p style="margin: 16px 0 12px;">
        <a href="{{ $siteUrl }}" style="color: #3a6efc;">Visit {{ $proDisplayName }}'s page</a>
    </p>

    <hr style="border: none; border-top: 1px solid #ddd; margin: 16px 0;">

    <p style="margin: 0; color: #666; font-size: 12px;">
        Didn't sign up, or changed your mind?
        <a href="{{ $unsubscribeUrl }}" style="color: #666;">Unsubscribe</a>.
    </p>
</body>
</html>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Mail/VisitorConfirmationMailTest.php`
Expected: PASS (3 passed).

- [ ] **Step 6: Commit**

```bash
git add app/Mail/SubscriptionConfirmationMail.php resources/views/emails/subscription-confirmation.blade.php tests/Feature/Mail/VisitorConfirmationMailTest.php
git commit -m "feat(email): add SubscriptionConfirmationMail visitor receipt"
```

---

## Task 5: `SendEnquiryConfirmationJob`

**Files:**
- Create: `app/Jobs/Notifications/SendEnquiryConfirmationJob.php`
- Test: `tests/Feature/Notifications/EnquiryConfirmationEmailTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Notifications/EnquiryConfirmationEmailTest.php`:

```php
<?php

require_once __DIR__.'/../../Helpers/EnquiryInboxTestHelpers.php';

use App\Jobs\Notifications\SendEnquiryConfirmationJob;
use App\Mail\EnquiryConfirmationMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

function seedConfirmableEnquiry(array $enquiryOverrides = [], array $blockSettings = ['notification_email' => 'pro@example.com']): array
{
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    seedContactBlock($siteId, $user->id, $blockSettings);
    $enquiryId = seedInboxEnquiry($user->id, $siteId, array_merge([
        'email' => 'visitor@example.com',
        'name' => 'Vee',
        'subject' => 'Press',
    ], $enquiryOverrides));

    return [$user, $enquiryId];
}

it('sends a confirmation to the visitor and stamps confirmation_sent_at', function () {
    Mail::fake();
    [, $enquiryId] = seedConfirmableEnquiry();

    (new SendEnquiryConfirmationJob($enquiryId))->handle();

    Mail::assertSent(EnquiryConfirmationMail::class, fn ($m) => $m->hasTo('visitor@example.com'));

    $row = DB::connection('pgsql')->table('site.enquiries')->where('id', $enquiryId)->first();
    expect($row->confirmation_sent_at)->not->toBeNull();
});

it('is idempotent — does not re-send once confirmation_sent_at is set', function () {
    Mail::fake();
    [, $enquiryId] = seedConfirmableEnquiry(['confirmation_sent_at' => now()->toDateTimeString()]);

    (new SendEnquiryConfirmationJob($enquiryId))->handle();

    Mail::assertNothingSent();
});

it('respects the per-block send_visitor_confirmation = false toggle', function () {
    Mail::fake();
    [, $enquiryId] = seedConfirmableEnquiry([], [
        'notification_email' => 'pro@example.com',
        'send_visitor_confirmation' => false,
    ]);

    (new SendEnquiryConfirmationJob($enquiryId))->handle();

    Mail::assertNothingSent();
});

it('drops the send when the per-recipient rate limit is exceeded', function () {
    Mail::fake();
    [, $enquiryId] = seedConfirmableEnquiry();

    $key = 'visitor_confirmation:'.hash('sha256', 'visitor@example.com');
    $limit = (int) config('partna.throttle.visitor_confirmation_per_hour', 5);
    for ($i = 0; $i < $limit; $i++) {
        RateLimiter::hit($key, 3600);
    }

    (new SendEnquiryConfirmationJob($enquiryId))->handle();

    Mail::assertNothingSent();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Notifications/EnquiryConfirmationEmailTest.php`
Expected: FAIL — `Class "App\Jobs\Notifications\SendEnquiryConfirmationJob" not found`.

- [ ] **Step 3: Write the job**

Create `app/Jobs/Notifications/SendEnquiryConfirmationJob.php`:

```php
<?php

namespace App\Jobs\Notifications;

use App\Mail\EnquiryConfirmationMail;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

// Sends the visitor-facing "we received your enquiry" confirmation to the
// person who submitted the contact form. No capability gate: public-submission
// origin, exactly like SendEnquiryNotificationJob. UUID-only payload — the
// visitor's email is re-fetched at handle() time so it never sits in Redis.
class SendEnquiryConfirmationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public array $backoff = [30, 90, 180];

    public int $timeout = 30;

    public function __construct(public readonly string $enquiryId)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        // Lock + idempotency check in one transaction (mirrors SendEnquiryNotificationJob).
        $enquiry = DB::transaction(function () {
            $e = Enquiry::query()->lockForUpdate()->find($this->enquiryId);
            if ($e === null) {
                return null;
            }
            if ($e->confirmation_sent_at !== null) {
                return false;
            }

            return $e;
        });

        if ($enquiry === null) {
            Log::warning('SendEnquiryConfirmationJob: enquiry not found', ['enquiry_id' => $this->enquiryId]);

            return;
        }
        if ($enquiry === false) {
            return; // already confirmed on a previous attempt
        }

        $recipient = trim((string) $enquiry->email);
        if ($recipient === '') {
            return; // redacted / no email — nothing to confirm
        }

        // Contact block holds the per-block toggle + the pro's reply-to inbox.
        $block = Block::query()
            ->where('site_id', $enquiry->site_id)
            ->where('block_group', 'sections')
            ->where('block_type', 'contact')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if ($block !== null && data_get($block->settings, 'send_visitor_confirmation', true) === false) {
            return; // professional disabled visitor confirmations
        }

        if (! $this->withinRateLimit($recipient)) {
            return;
        }

        $user = $enquiry->user;
        $proName = trim((string) ($user->display_name ?? '')) ?: 'the team';
        $siteUrl = ($user && $user->handle) ? 'https://'.$user->handle.'.partna.au' : 'https://partna.au';
        $replyTo = $block ? trim((string) data_get($block->settings, 'notification_email', '')) : '';

        Mail::to($recipient)->send(new EnquiryConfirmationMail(
            proDisplayName: $proName,
            visitorName: trim((string) ($enquiry->name ?? '')),
            subject: (string) $enquiry->subject,
            siteUrl: $siteUrl,
            replyToEmail: $replyTo !== '' ? $replyTo : null,
        ));

        $enquiry->forceFill(['confirmation_sent_at' => now()])->saveQuietly();
    }

    // Per-recipient hourly cap (shared bucket with the subscription confirmation),
    // keyed by a hash so no raw email lands in a Redis key.
    private function withinRateLimit(string $email): bool
    {
        $key = 'visitor_confirmation:'.hash('sha256', strtolower(trim($email)));
        $limit = (int) config('partna.throttle.visitor_confirmation_per_hour', 5);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            Log::warning('SendEnquiryConfirmationJob: visitor confirmation rate limit exceeded', ['key' => $key]);

            return false;
        }
        RateLimiter::hit($key, 3600);

        return true;
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        Log::error('SendEnquiryConfirmationJob failed permanently', [
            'enquiry_id' => $this->enquiryId,
            'error' => $e->getMessage(),
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Notifications/EnquiryConfirmationEmailTest.php`
Expected: PASS (4 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Notifications/SendEnquiryConfirmationJob.php tests/Feature/Notifications/EnquiryConfirmationEmailTest.php
git commit -m "feat(email): add SendEnquiryConfirmationJob with toggle + rate limit + idempotency"
```

---

## Task 6: `SendSubscriptionConfirmationJob`

**Files:**
- Create: `app/Jobs/Notifications/SendSubscriptionConfirmationJob.php`
- Test: `tests/Feature/Notifications/SubscriptionConfirmationEmailTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Notifications/SubscriptionConfirmationEmailTest.php`:

```php
<?php

use App\Jobs\Notifications\SendSubscriptionConfirmationJob;
use App\Mail\SubscriptionConfirmationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupEmailSubscriptionsTable();
});

// Returns [userId, siteId, subscriptionId]. Seeds an active subscribed row.
function seedConfirmableSubscription(array $subOverrides = [], ?array $newsletterBlockSettings = null): array
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $handle = 'pro-'.substr($userId, 0, 8);

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'Test Pro',
        'primary_email' => $handle.'@example.com',
        'status' => 'active',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => $handle,
        'is_published' => 1,
    ]);

    if ($newsletterBlockSettings !== null) {
        DB::connection('pgsql')->table('site.blocks')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'site_id' => $siteId,
            'block_group' => 'sections',
            'block_type' => 'newsletter',
            'is_active' => 1,
            'settings' => json_encode($newsletterBlockSettings),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    $subId = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert(array_merge([
        'id' => $subId,
        'user_id' => $userId,
        'list_key' => 'marketing',
        'email' => 'sub@example.com',
        'email_lc' => 'sub@example.com',
        'full_name' => 'Sarah',
        'status' => 'subscribed',
        'subscribed_at' => now()->toDateTimeString(),
        'unsubscribe_token' => 'tok-'.substr($subId, 0, 12),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $subOverrides));

    return [$userId, $siteId, $subId];
}

it('sends a confirmation to the subscriber and stamps confirmation_sent_at', function () {
    Mail::fake();
    [, , $subId] = seedConfirmableSubscription();

    (new SendSubscriptionConfirmationJob($subId))->handle();

    Mail::assertSent(SubscriptionConfirmationMail::class, fn ($m) => $m->hasTo('sub@example.com'));

    $row = DB::connection('pgsql')->table('notifications.email_subscriptions')->where('id', $subId)->first();
    expect($row->confirmation_sent_at)->not->toBeNull();
});

it('is idempotent once confirmation_sent_at is set', function () {
    Mail::fake();
    [, , $subId] = seedConfirmableSubscription(['confirmation_sent_at' => now()->toDateTimeString()]);

    (new SendSubscriptionConfirmationJob($subId))->handle();

    Mail::assertNothingSent();
});

it('does not send when the subscription is no longer subscribed', function () {
    Mail::fake();
    [, , $subId] = seedConfirmableSubscription(['status' => 'unsubscribed', 'unsubscribed_at' => now()->toDateTimeString()]);

    (new SendSubscriptionConfirmationJob($subId))->handle();

    Mail::assertNothingSent();
});

it('respects the per-block send_visitor_confirmation = false toggle', function () {
    Mail::fake();
    [, , $subId] = seedConfirmableSubscription([], ['send_visitor_confirmation' => false]);

    (new SendSubscriptionConfirmationJob($subId))->handle();

    Mail::assertNothingSent();
});

it('drops the send when the per-recipient rate limit is exceeded', function () {
    Mail::fake();
    [, , $subId] = seedConfirmableSubscription();

    $key = 'visitor_confirmation:'.hash('sha256', 'sub@example.com');
    $limit = (int) config('partna.throttle.visitor_confirmation_per_hour', 5);
    for ($i = 0; $i < $limit; $i++) {
        RateLimiter::hit($key, 3600);
    }

    (new SendSubscriptionConfirmationJob($subId))->handle();

    Mail::assertNothingSent();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Notifications/SubscriptionConfirmationEmailTest.php`
Expected: FAIL — `Class "App\Jobs\Notifications\SendSubscriptionConfirmationJob" not found`.

- [ ] **Step 3: Write the job**

Create `app/Jobs/Notifications/SendSubscriptionConfirmationJob.php`:

```php
<?php

namespace App\Jobs\Notifications;

use App\Mail\SubscriptionConfirmationMail;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Site\Block;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

// Sends the visitor-facing "you're subscribed" confirmation to the person who
// joined a newsletter list. No capability gate: public-submission origin, same
// as SendEnquiryNotificationJob. UUID-only payload — email re-fetched at handle().
class SendSubscriptionConfirmationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public array $backoff = [30, 90, 180];

    public int $timeout = 30;

    public function __construct(public readonly string $subscriptionId)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $sub = DB::transaction(function () {
            $s = EmailSubscription::query()->lockForUpdate()->find($this->subscriptionId);
            if ($s === null) {
                return null;
            }
            if ($s->confirmation_sent_at !== null) {
                return false;
            }

            return $s;
        });

        if ($sub === null) {
            Log::warning('SendSubscriptionConfirmationJob: subscription not found', ['subscription_id' => $this->subscriptionId]);

            return;
        }
        if ($sub === false) {
            return; // already confirmed
        }

        // An unsubscribe could have landed between dispatch and run — don't
        // confirm a subscription that is no longer active.
        if ($sub->status !== 'subscribed') {
            return;
        }

        $recipient = trim((string) $sub->email);
        if ($recipient === '') {
            return;
        }

        $user = $sub->user;

        // Newsletter block holds the per-block toggle. Resolved via the pro's site.
        $block = null;
        if ($user && ($site = $user->site)) {
            $block = Block::query()
                ->where('site_id', $site->id)
                ->where('block_group', 'sections')
                ->where('block_type', 'newsletter')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();
        }

        if ($block !== null && data_get($block->settings, 'send_visitor_confirmation', true) === false) {
            return;
        }

        if (! $this->withinRateLimit($recipient)) {
            return;
        }

        $proName = trim((string) ($user->display_name ?? '')) ?: 'the team';
        $siteUrl = ($user && $user->handle) ? 'https://'.$user->handle.'.partna.au' : 'https://partna.au';
        $unsubscribeUrl = route('public.unsubscribe', ['token' => $sub->unsubscribe_token]);

        Mail::to($recipient)->send(new SubscriptionConfirmationMail(
            proDisplayName: $proName,
            siteUrl: $siteUrl,
            unsubscribeUrl: $unsubscribeUrl,
            visitorName: $sub->full_name ?: null,
        ));

        $sub->forceFill(['confirmation_sent_at' => now()])->saveQuietly();
    }

    private function withinRateLimit(string $email): bool
    {
        $key = 'visitor_confirmation:'.hash('sha256', strtolower(trim($email)));
        $limit = (int) config('partna.throttle.visitor_confirmation_per_hour', 5);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            Log::warning('SendSubscriptionConfirmationJob: visitor confirmation rate limit exceeded', ['key' => $key]);

            return false;
        }
        RateLimiter::hit($key, 3600);

        return true;
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        Log::error('SendSubscriptionConfirmationJob failed permanently', [
            'subscription_id' => $this->subscriptionId,
            'error' => $e->getMessage(),
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Notifications/SubscriptionConfirmationEmailTest.php`
Expected: PASS (5 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Notifications/SendSubscriptionConfirmationJob.php tests/Feature/Notifications/SubscriptionConfirmationEmailTest.php
git commit -m "feat(email): add SendSubscriptionConfirmationJob with toggle + rate limit + idempotency"
```

---

## Task 7: Wire the enquiry controller dispatch

**Files:**
- Modify: `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php` (import + dispatch at line ~128)
- Test: `tests/Feature/Contact/PublicEnquirySubmissionTest.php` (append)

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Contact/PublicEnquirySubmissionTest.php` (the file already imports `Bus` and defines `seedPublishedContactSite` + `validEnquiryPayload`). Add the job import near the top imports:

```php
use App\Jobs\Notifications\SendEnquiryConfirmationJob;
```

And add the test:

```php
it('dispatches SendEnquiryConfirmationJob to confirm receipt to the visitor', function () {
    seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk();

    Bus::assertDispatched(SendEnquiryConfirmationJob::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Contact/PublicEnquirySubmissionTest.php --filter="dispatches SendEnquiryConfirmationJob"`
Expected: FAIL — `SendEnquiryConfirmationJob` was not dispatched.

- [ ] **Step 3: Add the dispatch**

In `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php`, add the import alongside the existing `DispatchEnquiryNotificationsJob` import at the top of the file:

```php
use App\Jobs\Notifications\SendEnquiryConfirmationJob;
```

Then, in `submit()`, add the dispatch immediately after the existing line 128 (`DispatchEnquiryNotificationsJob::dispatch((string) $enquiry->id);`):

```php
        // 8) Queue notification dispatch off the hot path — rate-limit + email handled in the adapter.
        DispatchEnquiryNotificationsJob::dispatch((string) $enquiry->id);

        // 9) Confirm receipt to the visitor (Tier-2 transactional; gated in the job).
        SendEnquiryConfirmationJob::dispatch((string) $enquiry->id);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Contact/PublicEnquirySubmissionTest.php`
Expected: PASS (all enquiry submission tests, including the new one).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php tests/Feature/Contact/PublicEnquirySubmissionTest.php
git commit -m "feat(email): dispatch visitor enquiry confirmation from PublicEnquiryController"
```

---

## Task 8: Wire the subscription controller genuine-opt-in dispatch

**Files:**
- Modify: `app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php` (import + opt-in detection + dispatch)
- Test: `tests/Feature/Notifications/SubscriptionConfirmationDispatchTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Notifications/SubscriptionConfirmationDispatchTest.php`:

```php
<?php

use App\Jobs\Notifications\SendSubscriptionConfirmationJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Str;

beforeEach(function () {
    config(['partna.throttle.enabled' => false]);
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupEmailSubscriptionsTable();
    setupCustomersTable();
});

function seedPublishedSubscribeSite(string $subdomain = 'subpro'): string
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => $subdomain,
        'handle_lc' => $subdomain,
        'display_name' => 'Sub Pro',
        'primary_email' => 'subpro@example.com',
        'status' => 'active',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => $subdomain,
        'is_published' => 1,
    ]);

    return $userId;
}

it('dispatches the confirmation on a brand-new subscribe', function () {
    seedPublishedSubscribeSite();
    Bus::fake();

    $this->postJson('/api/public/subscribe', ['email' => 'new@example.com'], [
        'X-Site-Subdomain' => 'subpro',
    ])->assertOk();

    Bus::assertDispatched(SendSubscriptionConfirmationJob::class);
});

it('does NOT dispatch on a redundant re-submit of an already-subscribed address', function () {
    $userId = seedPublishedSubscribeSite();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'list_key' => 'marketing',
        'email' => 'already@example.com',
        'email_lc' => 'already@example.com',
        'status' => 'subscribed',
        'subscribed_at' => now()->toDateTimeString(),
        'unsubscribe_token' => 'tok-already',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    Bus::fake();

    $this->postJson('/api/public/subscribe', ['email' => 'already@example.com'], [
        'X-Site-Subdomain' => 'subpro',
    ])->assertOk();

    Bus::assertNotDispatched(SendSubscriptionConfirmationJob::class);
});

it('dispatches again when a previously-unsubscribed address re-subscribes', function () {
    $userId = seedPublishedSubscribeSite();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'list_key' => 'marketing',
        'email' => 'back@example.com',
        'email_lc' => 'back@example.com',
        'status' => 'unsubscribed',
        'unsubscribed_at' => now()->toDateTimeString(),
        'unsubscribe_token' => 'tok-back',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    Bus::fake();

    $this->postJson('/api/public/subscribe', ['email' => 'back@example.com'], [
        'X-Site-Subdomain' => 'subpro',
    ])->assertOk();

    Bus::assertDispatched(SendSubscriptionConfirmationJob::class);
});
```

> `setupUsersTable`, `setupSitesTable`, `setupBlocksTable`, `setupEmailSubscriptionsTable`, and `setupCustomersTable` are all defined in `tests/Pest.php` and globally available to Pest tests (the subscribe flow upserts a Customer, hence `setupCustomersTable`).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Notifications/SubscriptionConfirmationDispatchTest.php`
Expected: FAIL — `SendSubscriptionConfirmationJob` was not dispatched (first test).

- [ ] **Step 3: Add opt-in detection + dispatch to the controller**

In `app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php`, add the import at the top:

```php
use App\Jobs\Notifications\SendSubscriptionConfirmationJob;
```

Capture the prior state right after the `if (! $subscription) { ... } else { ... }` block (immediately after the closing brace of that block, before the name logic at line ~80):

```php
        // Whether this request is a *genuine* opt-in: a brand-new row, or a
        // previously-unsubscribed address opting back in. A redundant re-submit
        // of an already-subscribed address must NOT re-confirm (anti-abuse).
        $isNewSubscription = ! $subscription->exists;
        $priorStatus = $isNewSubscription ? null : $subscription->status;
        $isGenuineOptIn = $isNewSubscription || $priorStatus === 'unsubscribed';
```

Reset the stamp on a genuine re-subscribe so a real opt-in re-confirms. Add immediately before `$subscription->save();` (line ~94):

```php
        // A genuine re-subscribe should confirm again — clear the prior stamp.
        if ($isGenuineOptIn) {
            $subscription->confirmation_sent_at = null;
        }

        $subscription->save();
```

Dispatch the confirmation just before the `return $this->success([...])` at the end of `subscribe()` (after the customer-upsert try/catch, line ~116):

```php
        if ($isGenuineOptIn) {
            SendSubscriptionConfirmationJob::dispatch((string) $subscription->id);
        }

        return $this->success([
            'ok' => true,
            'subscribed' => true,
            'list_key' => $listKey,
        ]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Notifications/SubscriptionConfirmationDispatchTest.php`
Expected: PASS (3 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php tests/Feature/Notifications/SubscriptionConfirmationDispatchTest.php
git commit -m "feat(email): dispatch subscription confirmation on genuine opt-in"
```

---

## Task 9: Full verification

**Files:** none (verification only)

- [ ] **Step 1: Run the full notification + contact + mail test surface**

Run: `php artisan test tests/Feature/Notifications tests/Feature/Mail tests/Feature/Contact`
Expected: PASS (all green, including the new files).

- [ ] **Step 2: Confirm the new mailables are NOT in the category registry**

Run: `php artisan test tests/Feature/Notifications/MailableCategoryCoverageTest.php`
Expected: PASS — the two new mailables are intentionally absent from `config('partna.notifications.mailables')`, so this sweep must still pass with no new exemptions.

- [ ] **Step 3: Confirm both mailables satisfy the architecture sweep**

Run: `php artisan test tests/Feature/Architecture/MailExtendsBaseTransactionalTest.php`
Expected: PASS — both extend `BaseTransactionalMail`.

- [ ] **Step 4: Style pass**

Run: `php artisan pint app/Mail app/Jobs/Notifications app/Http/Controllers/Api/PublicSite config/partna.php`
Expected: no diffs (or auto-fixed; re-run tests if anything changed).

- [ ] **Step 5: Full suite**

Run: `composer test`
Expected: PASS.

- [ ] **Step 6: Commit any Pint fixes**

```bash
git add -A
git commit -m "chore(email): pint formatting for visitor confirmation feature"
```

---

## Deployment note (not a code task)

The migration (`supabase/migrations/20260530010000_add_visitor_confirmation_sent_at.sql`) must be pushed to Supabase per the project workflow ("push to supabase dev/prod" → `supabase link` → `db push --dry-run` → `db push`). Dev (`glncumufgaqcmqhzwrxm`) first; confirm with Josh before prod. The columns are additive + NULLABLE, so deploy is non-breaking and can land before or with the app code.
