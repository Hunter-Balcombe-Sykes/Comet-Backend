# Visitor Confirmation Emails — Design

**Date:** 2026-05-29
**Status:** Approved (design); pending implementation plan
**Author:** Josh + Claude

## Goal

When a public visitor submits their email to a professional's site — either by
**sending an enquiry** or by **joining the newsletter/mailing list** — the
*visitor* (the person who entered their email) receives a confirmation email.

Today both flows notify only the *professional*; the visitor gets nothing back.

## Scope

- **Enquiry confirmation** — "We received your enquiry" receipt to the visitor.
- **Subscription confirmation** — "You're subscribed" acknowledgement to the visitor.

Single opt-in (simple acknowledgement) — the visitor is subscribed / their
enquiry is recorded immediately, as today. No double opt-in / confirm-link flow.

### Out of scope (deliberately)

- **Double opt-in** (pending status + confirm endpoint). Considered and rejected
  for now; the existing consent logging (`consent_source`, `consent_ip_hash`,
  `consent_user_agent`) covers our needs at pre-beta.
- **Feature flag.** No `SIDEST_*` env kill-switch. The per-block toggle is the
  only control surface; `MAIL_MAILER=log` already neutralises sends off-prod.
- **Shared job base class.** Two self-contained jobs, matching the existing
  convention (`SendEnquiryNotificationJob` and `SendStaffBroadcastEmailToSubscriberJob`
  share no base). A little duplication beats a premature abstraction here.
- **Preference-awareness for repeat visitors.** A future feature, not a rework —
  subscription confirmations already carry an unsubscribe token; enquiry
  confirmations are one-shot receipts that legitimately need no opt-out.

## Architectural placement (why this is the right foundation)

The codebase has a **deliberate two-tier email architecture**:

- **Tier 1 — User notifications:** `NotificationPublisher::publish(category: 'X')`
  for the platform *account holder*. Creates an in-app `Notification`, respects
  per-category user preferences, gated by `AccountCapabilities`, optionally sends
  a registered category Mailable via `SendTransactionalNotificationEmailJob`.
  Registry: `config('partna.notifications.mailables')`.

- **Tier 2 — External / operational transactional email:** a dedicated job +
  `Mail::to()->send()` of a `BaseTransactionalMail` subclass. **Not** in the
  category registry, **no** preference gating. Examples: `SendEnquiryNotificationJob`
  (pro's configured inbox), `SendStaffBroadcastEmailToSubscriberJob` (subscribers),
  `FeedbackSubmittedMail`, `HandleAliasExpiringMail`.

Visitor confirmations are unambiguously **Tier 2** — the recipient is *not* a
`User`. They have no account, no notification preferences, and no in-app inbox to
publish to. The ruling is encoded in `config/partna.php`:

```php
'inbox' => null,  // in-app only — enquiry inbox; no mailable (email goes via SendEnquiryNotificationJob)
```

i.e. the pro's *in-app* enquiry notification goes through the publisher, but the
*email* is split out to a dedicated Tier-2 job. Visitor confirmations follow the
same split and must **not** be registered in the category registry. Routing them
through `NotificationPublisher` would be wrong — it would try to create
`Notification` rows for a non-existent user.

The only hard architectural contract is
`arch('every mailable extends BaseTransactionalMail')`
(`tests/Feature/Architecture/MailExtendsBaseTransactionalTest.php`), which this
design satisfies.

## Components

### 1. Schema migration (Supabase, raw SQL)

One migration in `supabase/migrations/` adding an idempotency stamp to both
source tables:

```sql
ALTER TABLE site.enquiries
  ADD COLUMN confirmation_sent_at timestamptz NULL;
ALTER TABLE notifications.email_subscriptions
  ADD COLUMN confirmation_sent_at timestamptz NULL;
```

`NULL` = not yet sent. Mirrors the existing `email_sent_at` idempotency convention
on `site.enquiries` (which tracks the *professional's* notification — a separate
concern, hence a separate column). Add `confirmation_sent_at` to each model's
`$casts` (`datetime`) and `$fillable`/guarded as appropriate.

### 2. Triggers (controller dispatch)

**Enquiry** — `PublicEnquiryController::submit`, immediately after the existing
`DispatchEnquiryNotificationsJob::dispatch(...)`:

```php
SendEnquiryConfirmationJob::dispatch((string) $enquiry->id);
```

Every enquiry is a fresh row → always dispatch (gating happens at job time).

**Subscription** — `PublicEmailSubscriptionController::subscribe`. Dispatch **only
on a genuine opt-in**:

- Capture, before mutation: `$isNew = ! $subscription->exists;`
  `$priorStatus = $subscription->status;` (null for a new instance).
- A *genuine opt-in* = new row, **or** existing row whose `$priorStatus === 'unsubscribed'`.
- On a genuine opt-in for an *existing* (previously unsubscribed) row, reset the
  stamp so a real re-subscribe re-sends: `$subscription->confirmation_sent_at = null;`
  (new rows are already null).
- After `save()`, if genuine opt-in: `SendSubscriptionConfirmationJob::dispatch((string) $subscription->id);`
- A redundant re-submit of an already-`subscribed` address: **no dispatch**
  (prevents using the public form to re-spam an address).

### 3. Jobs (Tier 2 — mirror `SendEnquiryNotificationJob`)

Shared shape for both:
- `implements ShouldQueue`, `onQueue('notifications')`.
- `tries = 3`, `maxExceptions = 2`, `backoff = [30, 90, 180]`, `timeout = 30`.
- Constructor takes the **UUID only** (no PII in the Redis payload); recipient
  email is re-fetched at `handle()` time.
- `handle()`:
  1. `DB::transaction` + `lockForUpdate()` the source row. Return `null` if gone,
     `false` if `confirmation_sent_at !== null` (already sent).
  2. Resolve the governing **section block** and check the per-block toggle
     `data_get($block->settings, 'send_visitor_confirmation', true)` — default
     `true`, missing block ⇒ default ⇒ send. If `false`, return (no send, no stamp).
  3. **Per-recipient rate limit** (abuse control): key
     `visitor_confirmation:{sha256(lower(email))}` (hashed — no PII in Redis keys),
     limit `config('partna.throttle.visitor_confirmation_per_hour', 5)` per hour,
     shared across both confirmation types. On exceed: log a warning keyed by the
     hash and return (no send, no stamp).
  4. `Mail::to($recipientEmail)->send($mailable)`.
  5. `forceFill(['confirmation_sent_at' => now()])->saveQuietly();`
- `failed(\Throwable $e)`: `report($e)` + `Log::error` keyed by the source UUID
  only (no recipient email — log retention exceeds GDPR scope).
- **No `AccountCapabilities` gate** — public-submission origin, exactly like
  `SendEnquiryNotificationJob`. Add a class-doc comment citing that precedent so
  the capability audit doesn't read it as a missed gate.

**`SendEnquiryConfirmationJob(string $enquiryId)`**
- Resolves the `contact` section block by the enquiry's `site_id`
  (`block_group = 'sections'`, `block_type = 'contact'`, `is_active = true`,
  `deleted_at IS NULL`) — same lookup `DispatchEnquiryNotificationsJob` uses.
- Recipient = `enquiry->email`.
- Reply-To = the block's `notification_email` (trimmed) when present, else the
  Partna default — so the visitor can reply straight to the professional.

**`SendSubscriptionConfirmationJob(string $subscriptionId)`**
- Resolves the subscription, its `User` (`user_id`) and the user's `Site`, then the
  `newsletter` section block by that `site_id` (`block_group = 'sections'`,
  `block_type = 'newsletter'`, active) for the toggle.
- Recipient = `subscription->email`.
- Builds the unsubscribe URL: `route('public.unsubscribe', ['token' => $sub->unsubscribe_token])`.

### 4. Mailables (extend `BaseTransactionalMail`, use the `mail/layouts/partna` layout)

**`EnquiryConfirmationMail`**
- Subject: `"We received your enquiry — {display_name}"` (CR/LF stripped by base).
- `replyTo(...)` overridden to the resolved pro `notification_email` when present.
- View `emails.enquiry-confirmation`: brief "thanks, {pro} will be in touch",
  echoes the visitor's subject, links the pro's public site.

**`SubscriptionConfirmationMail`**
- Subject: `"You're subscribed — {display_name}"`.
- View `emails.subscription-confirmation`: confirms the list, links the pro's site.
- Carries the unsubscribe footer link **and** the RFC 8058 one-click headers,
  reusing the `StaffBroadcastMail` pattern:
  ```php
  $headers->addTextHeader('List-Unsubscribe', '<'.$this->unsubscribeUrl.'>');
  $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
  ```

**Personalisation source:** professional `display_name` + `handle` from the `User`
model; public site URL built as `https://{handle}.partna.au` (reuse an existing
handle→URL helper if one exists; otherwise construct from `handle` + the public
apex domain). Resolve at `handle()` time and pass into the mailable constructor as
plain data (keeps the Redis payload PII-free; only the source UUID is serialised).

### 5. Config

Add `config('partna.throttle.visitor_confirmation_per_hour')` (default `5`)
alongside the existing `enquiry_notification_per_hour` throttle.

## Data flow

**Enquiry**
```
Visitor submits contact form
  → PublicEnquiryController::submit
      → Enquiry created + Customer upserted (unchanged)
      → DispatchEnquiryNotificationsJob (pro notification — unchanged)
      → SendEnquiryConfirmationJob(enquiryId)        [NEW]
           lock → toggle → rate-limit → Mail::to(visitor) → stamp
```

**Subscription**
```
Visitor submits newsletter form
  → PublicEmailSubscriptionController::subscribe
      → EmailSubscription markSubscribed + Customer upsert (unchanged)
      → if genuine opt-in: SendSubscriptionConfirmationJob(subscriptionId)  [NEW]
           lock → toggle → rate-limit → Mail::to(visitor, +unsub) → stamp
```

## Error handling

- Source row missing at handle time → warn + return (no retry storm).
- Block missing → toggle defaults to `true` → still send.
- Rate-limit exceeded → silent no-op + hashed-key warning; no stamp.
- Mail transport failure → normal retry/backoff; permanent failure → `failed()`
  logs by UUID only.
- Customer-upsert failures in the controllers are already isolated and must not
  block dispatch (existing behaviour preserved).

## Testing (Pest, `Mail::fake()` + existing helpers)

`EnquiryInboxTestHelpers` for the enquiry side; the subscription test scaffolding
for the newsletter side.

- Visitor receives the confirmation on enquiry submit.
- Visitor receives the confirmation on a *new* subscribe and on
  `unsubscribed → subscribed`.
- **No** confirmation on a redundant re-submit of an already-subscribed address.
- Per-block toggle `send_visitor_confirmation = false` ⇒ no send.
- Idempotency: running the job twice sends exactly one email; `confirmation_sent_at`
  set after success.
- Rate limit: more than N sends to the same address within the window are dropped.
- Enquiry Reply-To resolves to the pro's `notification_email`; falls back cleanly
  when absent.
- Subscription email contains a valid `route('public.unsubscribe', token)` link and
  the `List-Unsubscribe` headers.
- Both new mailables satisfy `MailExtendsBaseTransactionalTest`.
- Both new mailables are **absent** from `config('partna.notifications.mailables')`
  (Tier-2 placement) — no `MailableCategoryCoverageTest` regression.

## Files

**Add**
- `supabase/migrations/<ts>_add_confirmation_sent_at.sql`
- `app/Jobs/Notifications/SendEnquiryConfirmationJob.php`
- `app/Jobs/Notifications/SendSubscriptionConfirmationJob.php`
- `app/Mail/EnquiryConfirmationMail.php`
- `app/Mail/SubscriptionConfirmationMail.php`
- `resources/views/emails/enquiry-confirmation.blade.php`
- `resources/views/emails/subscription-confirmation.blade.php`
- Tests under `tests/Feature/` (+ `tests/Unit/` for job idempotency/locking).

**Modify**
- `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php` (dispatch)
- `app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php`
  (genuine-opt-in detection, stamp reset, dispatch)
- `app/Models/Core/Site/Enquiry.php` (`confirmation_sent_at` cast)
- `app/Models/Core/Notifications/EmailSubscription.php` (`confirmation_sent_at` cast)
- `config/partna.php` (`throttle.visitor_confirmation_per_hour`)
```
