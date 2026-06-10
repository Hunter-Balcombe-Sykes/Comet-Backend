`★ Insight ─────────────────────────────────────`
**MAIL-5 drops on verification:** `AccountDeletionService` uses `Str::random(64)` → SHA-256 hash stored in the DB. The raw token in the URL is 384 bits of entropy — cryptographically equivalent to a signed URL for this purpose. The `cancelUrl` has no token because cancellation is gated by the authenticated `/api/user` JWT on the backend. DeepSeek's concern was valid in principle but the implementation is already secure.

**MAIL-6 tier:** `EventServiceProvider` really does extend `Illuminate\Foundation\Support\Providers\EventServiceProvider` (not base `ServiceProvider`), so the missing `parent::boot()` really would swallow any future `$listen`/`$subscribe` registration silently. Stays P2.

**MAIL-1 count verified:** Exactly 4 of 15 mailables correctly extend `BaseTransactionalMail` (all in `Auth/`). The other 11 bypass it.
`─────────────────────────────────────────────────`

# Email & Provider Boot Audit — 2026-05-24

**Branch:** development
**Lens:** service provider boot bugs, dangerous singletons, mail XSS, unsigned mail links, PII in emails, global helper footguns, email-send layer correctness
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Providers/AppServiceProvider.php
- app/Providers/EventServiceProvider.php
- app/Providers/DatabaseServiceProvider.php
- app/Mail/BaseTransactionalMail.php
- app/Mail/Auth/EmailConfirmMail.php
- app/Mail/Auth/InviteMail.php
- app/Mail/Auth/MagicLinkMail.php
- app/Mail/Auth/PasswordResetMail.php
- app/Mail/Gdpr/ProfessionalDataExportMail.php
- app/Mail/HandleAliasExpiringMail.php
- app/Mail/Notifications/AccountDeletionCancelledMail.php
- app/Mail/Notifications/AccountDeletionRequestedMail.php
- app/Mail/Notifications/AccountDeletionScheduledMail.php
- app/Mail/Notifications/FeatureAnnouncementMail.php
- app/Mail/Notifications/IncidentMail.php
- app/Mail/Notifications/PolicyUpdateMail.php
- app/Mail/Notifications/ProfileTaskMail.php
- app/Mail/SiteEnquiryNotification.php
- app/Mail/StaffBroadcastMail.php
- app/Services/Professional/AccountDeletionService.php
- resources/views/mail/layouts/partna.blade.php
- resources/views/emails/auth/email-confirm.blade.php
- resources/views/emails/notifications/*.blade.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 4 complete

---

## P1 — Fix before pilot launch

- [ ] **#MAIL-1** · P1 — 11 of 15 mailable classes bypass `BaseTransactionalMail`, missing pipeline header and sender centralisation
    - **Where:** app/Mail/Notifications/AccountDeletionCancelledMail.php, AccountDeletionRequestedMail.php, AccountDeletionScheduledMail.php, FeatureAnnouncementMail.php, IncidentMail.php, PolicyUpdateMail.php, ProfileTaskMail.php · app/Mail/Gdpr/ProfessionalDataExportMail.php · app/Mail/HandleAliasExpiringMail.php · app/Mail/SiteEnquiryNotification.php · app/Mail/StaffBroadcastMail.php
    - **Affects:** Every email sent through those 11 paths — account deletion lifecycle confirmations, enquiry notifications, staff broadcasts, GDPR data exports, handle-alias warnings, and all four notification broadcast categories. These emails bypass the canonical from/reply-to defaults and omit the `X-Partna-Pipeline: transactional` header, breaking bounce attribution and analytics bucketing in Resend/Postmark. A future change to the sender identity in `BaseTransactionalMail` will not propagate to 11 of 15 templates.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Change each of the 11 classes to extend `BaseTransactionalMail` instead of `Mailable`.
        - For classes using the `Envelope`/`Content` fluent API (`HandleAliasExpiringMail`, `SiteEnquiryNotification`), either refactor to the `build()` + `buildEnvelope()` pattern that `Auth/` mails use, or call `->from()` / `->replyTo()` / `->withSymfonyMessage()` explicitly inside `envelope()` — the latter is acceptable if the `ShouldQueue` fluent interface is the reason for the split.
        - Add an architecture test (`tests/Architecture/MailTest.php`) that uses `Pest`'s `arch()` to assert every class in `app/Mail/` either extends `BaseTransactionalMail` or is `BaseTransactionalMail` itself.
    - **Technical:** `BaseTransactionalMail::buildEnvelope()` centralises three concerns: the from address (`config('mail.from.address')`), the reply-to, and the `X-Partna-Pipeline: transactional` custom header. The 11 bypassing classes rely on Laravel's implicit fallback to `config('mail.from')` for the sender — which does work today — but the `X-Partna-Pipeline` header is never added, so Resend/Postmark cannot bucket these emails for bounce-rate analytics or suppression list management. Additionally, because the from address is not explicitly set, a future call to `Mail::alwaysFrom()` in a test or a change to how the mail driver picks the fallback sender could silently change the sending identity for 11 of 15 templates while leaving the Auth templates unchanged. The four Auth mails (`EmailConfirmMail`, `InviteMail`, `MagicLinkMail`, `PasswordResetMail`) are the correct pattern.
    - **Plain English:** You built a company email signature block that stamps every outgoing email with your name, contact address, and a tracking tag. It works perfectly — for 4 of your 15 email templates. The other 11 templates were wired up without plugging into that block, so they go out with whatever default the post office happens to assign, and your email delivery service has no way to identify them as yours for bounce tracking. If your company address changes, you update one file, but 11 emails still go out with the old address until someone hunts them all down. The fix is to make every template inherit from the same base block.
    - **Evidence:**
        ```php
        // app/Mail/Notifications/AccountDeletionCancelledMail.php — one of 11 that bypass BaseTransactionalMail
        class AccountDeletionCancelledMail extends Mailable
        {
            use Queueable, SerializesModels;

            public function __construct(
                public readonly string $displayName,
            ) {}

            public function build(): self
            {
                return $this
                    ->subject('Your account deletion has been cancelled')
                    ->view('emails.account.deletion-cancelled');
            }
        }
        ```
        ```php
        // app/Mail/BaseTransactionalMail.php — the centralised base that 11 mails bypass
        public function buildEnvelope(): self
        {
            return $this
                ->from(
                    config('mail.from.address', 'hello@partna.au'),
                    config('mail.from.name', 'Partna')
                )
                ->replyTo(
                    config('mail.from.address', 'hello@partna.au'),
                    config('mail.from.name', 'Partna')
                )
                ->withSymfonyMessage(function ($message): void {
                    $message->getHeaders()->addTextHeader('X-Partna-Pipeline', 'transactional');
                });
        }
        ```

---

## P2 — Should fix

- [ ] **#MAIL-2** · P2 — Unescaped notification title in email preheader `@section` across four broadcast templates
    - **Where:** resources/views/emails/notifications/feature_announcement.blade.php:2, incident.blade.php:2, policy_update.blade.php:2, profile_tasks.blade.php:2
    - **Affects:** Inbox preview text for every feature announcement, incident alert, policy update, and profile-task broadcast email. A notification title containing HTML markup would render as literal tag characters in the recipient's inbox preview snippet (what Gmail/Apple Mail show before you open the email), appearing as broken gibberish and eroding trust.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In each of the four templates, wrap the preheader value in `e()`: `@section('preheader', e($notification->title))`.
        - Alternatively, move the preheader assignment into `_partial-content.blade.php` so all four categories share a single source of truth.
    - **Technical:** Blade's `@section('name', $value)` syntax stores the raw PHP value without HTML-escaping. The paired `@yield('preheader')` in `resources/views/mail/layouts/partna.blade.php` echoes that stored string verbatim — identical to `{!! $value !!}`. The double-curly `{{ }}` convention used everywhere else in Blade does escape; the `@section` shorthand does not. Staff-authored notification titles are the input source, so the realistic risk is accidental markup (copy-pasting from a rich-text editor) rather than deliberate injection, but the resulting broken preview is a real UX issue. The `e()` helper applies `htmlspecialchars()` and costs nothing.
    - **Plain English:** When you receive an email, your inbox shows a short preview of the content before you click it. The code takes the notification title — which a staff member types — and drops it into that preview slot without cleaning it first. If the title accidentally contains any HTML formatting characters (like `<b>` or `&`), they'd appear as raw code in everyone's inbox list, making the email look broken or suspicious. Every other place in the email templates already cleans text before displaying it — this slot was just missed.
    - **Evidence:**
        ```php
        // resources/views/emails/notifications/feature_announcement.blade.php
        @extends('mail.layouts.partna')
        @section('preheader', $notification->title)
        @section('content')
            @include('emails.notifications._partial-content')
        @endsection
        ```
        ```php
        // resources/views/mail/layouts/partna.blade.php — preheader yielded raw
        <div style="display:none; font-size:1px; color:#ffffff; ...">
            @yield('preheader')&zwnj;&nbsp;&zwnj;&nbsp;...
        </div>
        ```

- [ ] **#MAIL-3** · P2 — `SiteEnquiryNotification` subject built from unsanitised user input — CRLF header injection
    - **Where:** app/Mail/SiteEnquiryNotification.php:24
    - **Affects:** Every enquiry notification email sent to site owners. A visitor submitting a contact form with `\r\n` sequences in their name or subject field could inject arbitrary email headers into the outbound notification.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Strip carriage-return and line-feed characters from both `$this->enquiry->name` and `$this->enquiry->subject` before interpolation: `preg_replace('/[\r\n]+/', ' ', $value)`.
        - Apply the same sanitisation to both fields, not just the subject. The resulting subject should then be passed through `Str::limit(..., 77)`.
    - **Technical:** The envelope subject is constructed as `Str::limit("New enquiry from {$this->enquiry->name} — {$this->enquiry->subject}", 77)`. Both values come from a public-facing contact form with no server-side stripping of control characters. Symfony Mailer does fold the `Subject` header and strip bare `\r\n` sequences before sending — so this is not directly exploitable against the current mail driver. The risk is transport-layer fragility: a future swap to a raw SMTP driver, a misconfigured relay, or a custom mailer decorator could bypass that sanitisation. Defense-in-depth says the application should never pass unsanitised user input into a header slot, regardless of what the transport does downstream.
    - **Plain English:** Someone fills out your contact form and puts hidden line-break characters in their name. The code takes that name and glues it directly into the email's subject line. Most email systems try to catch this trick, but they're the last line of defense — if they ever fail (during an upgrade or config change), those hidden characters could make the email system misread the subject line and add unexpected content to the email. The fix is a one-liner that strips those invisible characters before they reach the email builder.
    - **Evidence:**
        ```php
        // app/Mail/SiteEnquiryNotification.php:24
        public function envelope(): Envelope
        {
            return new Envelope(
                subject: Str::limit("New enquiry from {$this->enquiry->name} — {$this->enquiry->subject}", 77),
            );
        }
        ```

- [ ] **#MAIL-4** · P2 — 6-digit OTP printed in email subject and preheader — visible on phone lock screen
    - **Where:** app/Mail/Auth/EmailConfirmMail.php:21 · resources/views/emails/auth/email-confirm.blade.php:1
    - **Affects:** Every user signing up or changing their email address. The verification code is exposed in both the subject line and the Blade preheader section, meaning it appears in push notification banners and on the lock screen of iOS and Android devices without requiring the device to be unlocked.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the subject to a static string: `'Verify your Partna email address'` (no code embedded).
        - Change the preheader section in `email-confirm.blade.php` to a static prompt, e.g. `@section('preheader', 'Open this email to get your verification code.')`.
        - The code is already rendered prominently as a large monospace pill inside the email body — no UX is lost.
    - **Technical:** `->subject("Your Partna verification code: {$this->code}")` puts the 6-digit OTP in plain text in the MIME `Subject` header. `resources/views/emails/auth/email-confirm.blade.php` line 1 repeats it: `@section('preheader', "Your Partna verification code: {$code}")`. iOS Mail and Android Gmail both surface the subject in push notification banners and on the lock screen. An attacker with brief physical proximity to a locked device can read the OTP without touching it — sufficient to verify a stolen email address on their own device. Removing the code from the subject and preheader closes this shoulder-surfing window entirely at zero UX cost.
    - **Plain English:** When a new user signs up, your system sends them a 6-digit code to prove they own their email address. On most phones, that email pops up as a notification on the lock screen — showing the subject line, which currently includes the code itself. Anyone standing near the phone can read the code without unlocking it. The code is already shown big and clear inside the email body, so there's no reason to also put it in the notification preview. Changing the subject to "Verify your Partna email address" fixes it with a one-word edit.
    - **Evidence:**
        ```php
        // app/Mail/Auth/EmailConfirmMail.php:21
        public function build(): self
        {
            return $this->buildEnvelope()
                ->to($this->recipientEmail)
                ->subject("Your Partna verification code: {$this->code}")
                ->view('emails.auth.email-confirm');
        }
        ```
        ```php
        // resources/views/emails/auth/email-confirm.blade.php:1 — code also in preheader
        @section('preheader', "Your Partna verification code: {$code}")
        ```

- [ ] **#MAIL-5** · P2 — `EventServiceProvider::boot()` overrides parent without calling `parent::boot()`, silently swallowing future `$listen`/`$subscribe` registrations
    - **Where:** app/Providers/EventServiceProvider.php:20–30
    - **Affects:** Future event-listener wiring. The parent class (`Illuminate\Foundation\Support\Providers\EventServiceProvider`) registers listeners from the `$listen` array and processes `$subscribe` in its `boot()` method. Today `$listen` is empty so the omission is harmless — but any developer who later adds a listener to `$listen` will find it silently never fires, with no error or warning.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `parent::boot();` as the first line of `EventServiceProvider::boot()`, before the `::observe()` calls.
        - Optionally, add a comment above it noting why the call is required, so future reviewers don't "clean it up" as an apparent no-op.
    - **Technical:** The class correctly extends `Illuminate\Foundation\Support\Providers\EventServiceProvider` (aliased as `ServiceProvider`), not base `Illuminate\Support\ServiceProvider`. The parent's `boot()` calls `$this->registerListeners()` which resolves the `$listen` array and `$this->subscribe()` which resolves `$subscribe`. Overriding `boot()` without `parent::boot()` short-circuits both. The `AppServiceProvider` registers cache/scheduler event listeners via `Event::listen()` directly — those are unaffected — but the class-based `$listen` and `$subscribe` arrays (the conventional Laravel pattern documented in every tutorial) are dead letter until this is fixed.
    - **Plain English:** Your event system has a built-in registration table — you fill in a list of listeners and the framework automatically wires them up when the app starts. The code that does that wiring is in the parent class. Your `EventServiceProvider` has replaced the parent's startup routine with its own, but forgot to call the parent's routine first. Right now the list is empty so nothing breaks. The moment any developer adds an entry to that list — following standard Laravel docs — their listener will silently do nothing, and they'll spend time debugging something that looks like it should work.
    - **Evidence:**
        ```php
        // app/Providers/EventServiceProvider.php — extends the real EventServiceProvider but never calls parent::boot()
        class EventServiceProvider extends ServiceProvider  // aliased from Illuminate\Foundation\Support\Providers\EventServiceProvider
        {
            protected $listen = [];

            public function boot(): void
            {
                User::observe(ProfessionalObserver::class);
                Site::observe(SiteObserver::class);
                Block::observe(BlockObserver::class);
                Service::observe(ServiceObserver::class);
                ServiceCategory::observe(ServiceCategoryObserver::class);
                Customer::observe(CustomerObserver::class);
                SiteMedia::observe(SiteMediaObserver::class);
            }
        }
        ```
