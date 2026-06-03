# Enquiry Inbox — Design

**Status:** Draft (post-audit + post-self-review + post-rebase, 2026-05-27 — re-verified against `development` HEAD after `professional → user` rename + bot-protection foundation merge)
**Date:** 2026-05-26 (drafted) / 2026-05-27 (re-verified)
**Owner:** Josh
**Target:** v1 (beta)
**Supersedes (partial):** [2026-04-22-contact-section-block-design.md](2026-04-22-contact-section-block-design.md) — keeps the foundational schema; replaces the "read/unread + email-only" notification model.

## Problem

The `contact` block pipeline shipped in April: public `POST /public/enquiry` persists a `site.enquiries` row, auto-creates a `Customer`, and emails the professional via `SendEnquiryNotificationJob`. The dashboard exposes `GET /me/enquiries` (currently `PATCH` for status updates and `DELETE` for soft delete).

What's missing for the professional's actual workflow:

1. **No in-dashboard notification on submit.** The only "you have an enquiry" signal is the email. The bell-icon inbox (`notifications.notifications`) is silent. Professionals who want to operate inside the dashboard get nothing.
2. **No channel choice.** Email always fires if `notification_email` is set. There's no per-block toggle for "in-app only".
3. **No detail endpoint.** The list exists; `GET /me/enquiries/{id}` does not.
4. **Status model is too thin.** `read_at` alone doesn't support an inbox the professional works out of. There's no "replied" or "archived" — every interaction collapses to a single boolean.
5. **No linked-contact context on the detail view.** An enquiry from a returning person looks identical to a first-time enquiry. The professional can't see "this is the same email that sent two enquiries last month and is on the marketing list".
6. **Frontend isn't rendering the contact block as a form.** Theme work in the separate `@partna/themes` package — out of this repo's scope but in scope for the handoff appendix.

## Scope

**In scope (this spec):**
- Backend: new in-app notification dispatch on enquiry submit, alongside the existing email path
- Backend: per-block `notification_channels` setting (in_app / email / both), default `[in_app]`
- Backend: five-state inbox model (`new` / `read` / `replied` / `archived` / `spam`)
- Backend: `customer_id` FK on `site.enquiries` for direct contact linkage
- Backend: `notification_id` FK on `site.enquiries` linking to the in-app notification row
- Backend: detail endpoint returning enquiry + linked customer + this person's enquiry history
- Backend: status transition endpoints (`/read`, `/replied`, `/archive`, `/spam`, `/restore`); existing `DELETE` retained
- Backend: counts endpoint for dashboard tab badges
- Backend: `EnquiryPolicy` registered (per CLAUDE.md authorisation doctrine — no inline 403)
- Backend: confirm `bot.token:enquiry` middleware behaviour for this surface (the middleware is ALREADY applied to `POST /public/enquiry` per `routes/api/publicSite.php`; this work confirms `BOT_PROTECTION_MODE` is set to at least `shadow` in dev, then `enforce` once frontend ships the widget)
- Backend: spam-mark side-effects (Customer cleanup, per-pro hashed-email blocklist)
- Backend: PII redaction path — `Customer::redact()` (new method) cascading to `Enquiry::redact()` (new) → notification title+body
- Backend: `upsertEnquiryCustomer()` refactor — currently returns `void`, must return `Customer` for the new `customer_id` link
- Backend: register `'inbox'` category in `config('partna.notifications.mailables')` registry
- Backend: NO `ApiController::error()` extension needed — the bot-protection middleware (`VerifyBotToken`) already has its own error-code surface (`captcha_missing`, `captcha_unavailable`, etc.); the enquiry submission path inherits that for free, and our own controller paths use the existing `error(message, status)` shape.
- Backend: refactor `NotificationPublisher::publish()` to return `?Notification` (currently returns `void`; needed so callers can capture the inserted/existing notification id — benefits all future publish callers, not just this work)
- Backend: async dispatch — notification fan-out runs in a queued job after the public response, not on the hot path
- Pest tests for all of the above

**Frontend handoff (separate session, separate repo):**
- Theme: contact block form rendering in `@partna/themes`
- Dashboard: `/dashboard/enquiries` list + detail UI; bell-icon integration
- Block authoring: `notification_channels` UI control
- Turnstile widget rendering

**Out of scope, deliberately:**
- In-dashboard reply composer — pro composes externally via mailto link; "Mark as replied" updates status
- Threading customer replies back (would need IMAP / inbound email infra)
- Per-user notification preferences (today: per-block; later: per-user defaults with per-block override — schema supports this evolution)
- SMS / push channels (channel adapter pattern leaves room)
- Spam ML classifier (Turnstile + honeypot + per-pro blocklist is enough for v1)
- Changes to the newsletter / `contacts_collection` block (different block, different purpose)
- Optimistic locking on status transitions — last-write-wins is acceptable for the solo-pro inbox; revisit if it becomes a real issue

## Architecture

```
       PUBLIC SITE                          BACKEND                          DASHBOARD

  Contact block form          PublicEnquiryController                        GET    /me/enquiries
  POST /public/enquiry  ──►   1. Validate + Turnstile                        GET    /me/enquiries/counts        ◄── NEW
  (name/email/subject/  │     2. Resolve site + active contact block         GET    /me/enquiries/{id}          ◄── NEW
   message + honeypot   │     3. Spam-blocklist pre-check (silent 200)       POST   /me/enquiries/{id}/read     ◄── NEW (replaces PATCH)
   + timing + Turnstile)│     4. DB::transaction:                            POST   /me/enquiries/{id}/replied  ◄── NEW
                        │        - upsert Customer (catches unique race)     POST   /me/enquiries/{id}/archive  ◄── NEW
                        │        - create Enquiry w/ customer_id, status=new POST   /me/enquiries/{id}/spam     ◄── NEW
                        │     5. dispatch DispatchEnquiryNotificationsJob    POST   /me/enquiries/{id}/restore  ◄── NEW
                        ▼        (queued — public response returns now)      DELETE /me/enquiries/{id}          (exists)
                    site.enquiries
                    (+status, customer_id, notification_id)                  Bell icon polls
                                                                             GET /me/notifications
                    DispatchEnquiryNotificationsJob (Horizon)                (auto-includes enquiry notifications)
                    - resolves channels[] from block settings
                    - for each: InAppAdapter / EmailAdapter
                    - InAppAdapter writes notification_id back to enquiry
```

### Architectural rules

1. **Notification dispatch is fan-out, not branching.** The controller does not `if (channel === 'email')`. A new `EnquiryNotificationDispatcher` service resolves an array of channels from block settings, then iterates. Adding push/SMS later is one new adapter, no controller changes.
2. **`SendEnquiryNotificationJob` becomes one channel adapter among several.** A new sibling adapter writes to `notifications.notifications` via the existing `NotificationPublisher` service (which auto-creates per-recipient receipts and handles the `notifications.notifications.type` CHECK + `mailables` registry). Bypassing the publisher would skip receipt creation and any future realtime dispatch.
3. **Enquiry status is a state machine, not flag soup.** A `status` enum drives everything (`new` / `read` / `replied` / `archived` / `spam`), with paired audit timestamps. Transitions are model methods (`markRead()`, `markReplied()`, etc.), not scattered controller logic.
4. **Dispatch runs in a queued job, NOT on the public response hot path.** `DispatchEnquiryNotificationsJob` is `dispatch()`ed after the DB transaction commits. The public `POST /public/enquiry` returns immediately. This isolates submitter response latency from publisher/realtime/email latency and gives Horizon retries for free.

## Data model

All schema changes are one Supabase migration. No Laravel migrations (per CLAUDE.md hard rule). Migration file: `supabase/migrations/2026XXXXXXXXXX_enquiry_inbox.sql`.

### New enum type

```sql
CREATE TYPE enquiry_status AS ENUM ('new', 'read', 'replied', 'archived', 'spam');
```

This MUST be the first statement in the migration — the `ALTER TABLE` that adds the `status` column references it. Adding values later is cheap (`ALTER TYPE enquiry_status ADD VALUE ...`); removing values is not. The chosen five values are the full intended set for v1; any later additions (e.g., `'snoozed'`) extend, not replace.

### `site.enquiries` — additive only

| Column | Type | Notes |
|---|---|---|
| `status` | `enquiry_status` NOT NULL DEFAULT `'new'` | NEW. Backfilled from `read_at`. |
| `customer_id` | `uuid` nullable FK → `site.customers(id)` ON DELETE SET NULL | NEW. Set during the existing upsert (which now returns `Customer`). Backfilled by email join at migration time. |
| `notification_id` | `uuid` nullable FK → `notifications.notifications(id)` ON DELETE SET NULL | NEW. The in-app notification spawned by this enquiry (written back by `InAppEnquiryNotificationAdapter` after the publisher succeeds). Lets the detail-view auto-read step locate the receipt without parsing `cta_url`. Cross-schema FK is intentional and documented. |
| `replied_at` | `timestamptz` nullable | NEW. Set on transition → replied. |
| `archived_at` | `timestamptz` nullable | NEW. |
| `spam_at` | `timestamptz` nullable | NEW. |
| `read_at` | (existing) | Kept. Transition to `status='read'` sets this too. Retained for backwards compatibility with anything serializing the old `is_read`/`read_at` fields. |

### `site.blocks.settings` for `block_type='contact'`

JSON-only — no schema change. `UpsertSectionBlockRequest::contactRules()` validates the new key:

```json
{
  "headline": "Get in touch",
  "description": "...",
  "notification_email": "owner@example.com",
  "subject_options": ["..."],
  "notification_channels": ["in_app"]
}
```

- `notification_channels`: array of `'in_app' | 'email'`. Default `['in_app']` when missing. Validation: at least one element; `'email'` requires `notification_email` to be present.

### `notifications.notifications` — no schema change

The existing CHECK constraint on `type` is `IN ('Success', 'Critical', 'Warning', 'Invitation', 'To do', 'Info')`. **No amendment needed** — `NotificationPublisher::publish()` calls `Notification::normalizeFrontendType($frontendType)` before insertion, and unknown frontend types (including `'enquiry.received'`) fall through to the `'Info'` default. The `type` column will store `'Info'`; the discriminator that distinguishes enquiry notifications from other inbox notifications is `category='inbox'` plus the `dedupe_key` prefix `'enquiry:'`.

(The first-pass audit reported this as a P0 schema mismatch; closer inspection of the normalization path revealed it's a non-issue. Documenting here so the next reader doesn't trip on the same surface-level concern.)

### `config/partna.php` — register `'inbox'` category

`NotificationPublisher::categories()` returns `array_keys(config('partna.notifications.mailables'))`. `'inbox'` is currently absent. Add:

```php
'inbox' => null,  // in-app only — no mailable
```

`null` mailable means no email rendering for this category (we send the email via the separate `SendEnquiryNotificationJob`, which is intentional — different rate-limit and PII-at-handle-time policy than the generic transactional mailables).

### Indexes

```sql
CREATE INDEX CONCURRENTLY idx_enquiries_pro_status_created
  ON site.enquiries (user_id, status, created_at DESC)
  WHERE deleted_at IS NULL;

CREATE INDEX CONCURRENTLY idx_enquiries_customer
  ON site.enquiries (customer_id)
  WHERE deleted_at IS NULL;

CREATE INDEX CONCURRENTLY idx_enquiries_notification
  ON site.enquiries (notification_id)
  WHERE notification_id IS NOT NULL;
```

**All indexes use `CONCURRENTLY`** — required by the repo's composer guard (per commit `3bce8ce0`) for any index on a populated table. `CREATE INDEX CONCURRENTLY` cannot run inside a transaction block; each must be its own statement.

**The earlier draft's partial index `WHERE status = 'new'` is intentionally dropped.** The composite `(user_id, status, created_at DESC)` covers the counts query (`GROUP BY status WHERE user_id = ?`), the new-badge query, and any status-filtered list query equally well. A separate partial index would add write amplification with no read-side win at the scale we expect (< 50k rows per pro).

### Backfill order

`CREATE INDEX CONCURRENTLY` cannot run in a transaction; everything else can. Execute in this order:

1. `CREATE TYPE enquiry_status AS ENUM ('new', 'read', 'replied', 'archived', 'spam');`
2. `ALTER TABLE site.enquiries ADD COLUMN status enquiry_status NOT NULL DEFAULT 'new';` (Postgres 11+ handles constant defaults in metadata, no row rewrite).
3. `UPDATE site.enquiries SET status = 'read' WHERE read_at IS NOT NULL;` (one-shot backfill).
4. Add the remaining nullable columns in one statement:
   ```sql
   ALTER TABLE site.enquiries
     ADD COLUMN customer_id uuid,
     ADD COLUMN notification_id uuid,
     ADD COLUMN replied_at timestamptz,
     ADD COLUMN archived_at timestamptz,
     ADD COLUMN spam_at timestamptz,
     ADD COLUMN redacted_at timestamptz;
   ```
5. Backfill `customer_id`:
   ```sql
   UPDATE site.enquiries e
   SET customer_id = c.id
   FROM site.customers c
   WHERE c.user_id = e.user_id
     AND lower(c.email) = lower(e.email)
     AND c.deleted_at IS NULL;
   ```
   The `deleted_at IS NULL` filter avoids linking to soft-deleted customer rows. There's a unique index on `(user_id, lower(email)) WHERE email IS NOT NULL` for customers (regardless of soft-delete state — verified at baseline migration line 404), so the join is single-row.
6. `notification_id` gets no backfill — historical enquiries never had an in-app notification.
7. Add FK constraints:
   ```sql
   ALTER TABLE site.enquiries
     ADD CONSTRAINT enquiries_customer_fk FOREIGN KEY (customer_id)
       REFERENCES site.customers(id) ON DELETE SET NULL,
     ADD CONSTRAINT enquiries_notification_fk FOREIGN KEY (notification_id)
       REFERENCES notifications.notifications(id) ON DELETE SET NULL;
   ```
8. `CREATE INDEX CONCURRENTLY` statements (each as its own statement, outside any transaction block — see Indexes section).
9. **Drop the now-redundant existing index** (after the new composite is built and verified):
   ```sql
   DROP INDEX CONCURRENTLY IF EXISTS site.enquiries_user_created_idx;
   ```
   The new composite `(user_id, status, created_at DESC) WHERE deleted_at IS NULL` covers everything the old `(user_id, created_at DESC) WHERE deleted_at IS NULL` did (Postgres can use a leading-prefix index even when middle columns aren't filtered).

## Models and file paths

Verified against the live codebase (2026-05-26):

| Concept | Real path |
|---|---|
| Enquiry model | `app/Models/Core/Site/Enquiry.php` |
| EnquiryResource (existing — extend) | `app/Http/Resources/EnquiryResource.php` (NOT under an `Enquiry/` subfolder) |
| EnquiryDetailResource (NEW) | `app/Http/Resources/EnquiryDetailResource.php` |
| Existing dashboard controller (extend with new methods) | `app/Http/Controllers/Api/User/Customers/UserEnquiryController.php` |
| Public submit controller | `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php` |
| Policy (new) | `app/Policies/EnquiryPolicy.php` |
| Notification dispatcher (new service) | `app/Services/Notifications/EnquiryNotificationDispatcher.php` |
| Notification adapters (new) | `app/Services/Notifications/Adapters/InAppEnquiryNotificationAdapter.php`, `EmailEnquiryNotificationAdapter.php` |
| Queued dispatch job (new) | `app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php` |

### Enquiry model — required changes

Current state (verified):

```php
protected $fillable = [
    'user_id', 'site_id', 'name', 'email', 'phone', 'subject',
    'message', 'ip_hash', 'user_agent', 'read_at', 'email_sent_at',
];

protected $hidden = [
    'name', 'email', 'phone', 'message', 'ip_hash', 'user_agent',
];

protected $casts = [
    'read_at' => 'datetime', 'created_at' => 'datetime',
    'updated_at' => 'datetime', 'deleted_at' => 'datetime',
    'email_sent_at' => 'datetime',
];
```

**Required additions:**

```php
// Add to $fillable:
'status', 'customer_id', 'notification_id',
'replied_at', 'archived_at', 'spam_at', 'redacted_at',

// Add to $casts:
'replied_at'  => 'datetime',
'archived_at' => 'datetime',
'spam_at'     => 'datetime',
'redacted_at' => 'datetime',
'status'      => EnquiryStatus::class,  // PHP enum cast — define App\Enums\EnquiryStatus

// $hidden stays as-is — PII fields already covered. notification_id is non-PII, can be exposed.

// Add relationships:
public function customer(): BelongsTo
{
    return $this->belongsTo(Customer::class, 'customer_id');
}

public function notification(): BelongsTo
{
    return $this->belongsTo(Notification::class, 'notification_id');
}

// Add transition methods (single-responsibility, idempotent):
public function markRead(): void   { $this->update(['status' => 'read', 'read_at' => now()]); }
public function markReplied(): void { $this->update(['status' => 'replied', 'replied_at' => now()]); }
public function archive(): void    { $this->update(['status' => 'archived', 'archived_at' => now()]); }
public function markSpam(): void   { $this->update(['status' => 'spam', 'spam_at' => now()]); }
public function restore(): void    { $this->update(['status' => 'new']); }  // does not auto-recreate soft-deleted Customer
public function redact(): void     { /* see PII handling section */ }
```

### EnquiryResource — current state vs required state

**Current** (`app/Http/Resources/EnquiryResource.php`):

```php
return [
    'id'         => (string) $this->id,
    'name'       => $this->name,
    'email'      => $this->email,
    'phone'      => $this->phone,
    'subject'    => $this->subject,
    'message'    => $this->message,
    'read_at'    => optional($this->read_at)->toIso8601String(),
    'is_read'    => $this->read_at !== null,
    'created_at' => optional($this->created_at)->toIso8601String(),
];
```

**Required additions:**

```php
'status'      => $this->status,            // 'new'|'read'|'replied'|'archived'|'spam'
'replied_at'  => optional($this->replied_at)->toIso8601String(),
'archived_at' => optional($this->archived_at)->toIso8601String(),
'spam_at'     => optional($this->spam_at)->toIso8601String(),
'updated_at'  => optional($this->updated_at)->toIso8601String(),
// is_read and read_at stay (backwards compat). is_read becomes `$this->status !== 'new'`.
```

### EnquiryDetailResource (new)

Extends `EnquiryResource` and adds:

```php
'mailto_url' => sprintf(
    'mailto:%s?subject=%s',
    rawurlencode((string) $this->email),
    rawurlencode('Re: ' . (string) $this->subject),
),
'customer'   => $this->whenLoaded('customer', fn () => new CustomerResource($this->customer)),
'history'    => $this->whenLoaded('historyForDetailView', fn () =>
    $this->historyForDetailView->map(fn ($e) => [
        'id'         => (string) $e->id,
        'subject'    => $e->subject,
        'created_at' => optional($e->created_at)->toIso8601String(),
        'status'     => $e->status,
    ])
),
```

The controller pre-loads `customer` and attaches `historyForDetailView` (a Collection of up to `ENQUIRY_HISTORY_LIMIT = 10` siblings filtered `WHERE redacted_at IS NULL AND deleted_at IS NULL`) before passing to the resource — avoids any N+1 risk inside the resource.

## Submission flow

`PublicEnquiryController::submit($request, $resolver)`:

1. Validate (existing): `PublicEnquiryRequest` — honeypot `website`, timing window, name, email, subject ∈ subject_options, message. The `bot.token:enquiry` middleware (already applied on the route since the bot-protection-foundation merge) runs before the controller and validates the captcha token according to `BOT_PROTECTION_MODE` (off/shadow/enforce).
2. Resolve site from subdomain (existing): `PublicSiteResolver::resolvePublishedSite`.
3. Resolve active contact block (existing) — exact query:
   ```php
   Block::where('site_id', $site->id)
        ->where('block_group', 'sections')
        ->where('block_type', 'contact')
        ->active()
        ->first();
   ```
   Returns 404 if none. The `site_id` + `block_group` scope is critical for tenant isolation — `Block::active()` only adds `is_active=true`.
4. **Spam-blocklist pre-check** (NEW): if `HMAC_SHA256(secret, lower(email))` is in the Redis sorted set for this pro AND not expired (see Spam blocklist section), return fake 200 OK with the same shape as a honeypot hit (`['ok' => true]` only, no `enquiry_id`).
5. `DB::transaction`:
   ```php
   try {
       $customer = $this->upsertEnquiryCustomer(...);  // refactored to return Customer
   } catch (UniqueConstraintViolationException $e) {
       // Concurrent submission won the insert race.
       $customer = Customer::where('user_id', $pro->id)
                            ->whereRaw('lower(email) = ?', [strtolower($email)])
                            ->firstOrFail();
   }
   $enquiry = Enquiry::create([
       ...,
       'customer_id' => $customer->id,
       'status' => 'new',
   ]);
   ```
   **The `upsertEnquiryCustomer` method is being refactored from returning `void` to returning the `Customer` instance.** The unique index `customers_user_email_unique` on `(user_id, lower(email))` enforces uniqueness; we catch the race and re-fetch.
6. After the transaction commits: `DispatchEnquiryNotificationsJob::dispatch($enquiry->id)`. This is async — the public response does not wait for notification dispatch.
7. Return `['ok' => true]`. **No `enquiry_id` in the response** — the submitter has no use for it and exposing it widens the attack surface for enumeration. The fake-200 from the honeypot/blocklist paths matches this shape exactly.

### `DispatchEnquiryNotificationsJob` (queued, Horizon)

`app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php`:

1. Reload `$enquiry` and its block fresh from DB (avoids stale PII in serialized payload).
2. Resolve `$channels = $block->settings['notification_channels'] ?? ['in_app']`.
3. For each channel, invoke the adapter via `EnquiryNotificationDispatcher`. Adapter failures are logged + reported to Nightwatch; the job retries per Horizon defaults.

### `EnquiryNotificationDispatcher`

`app/Services/Notifications/EnquiryNotificationDispatcher.php` (chose `Dispatcher` suffix deliberately — this is fan-out logic, not a CRUD service; the adapter pattern is the design intent). Two adapters:

- **`InAppEnquiryNotificationAdapter`** uses the real `NotificationPublisher` signature (named args, required `$dedupeKey`). **Prerequisite work:** publisher's `publish()` is refactored from `void` to `?Notification` return type so the adapter can capture the inserted-or-existing notification id (returns null on capability-gate drop or invalid-input drop; same dedup behaviour via the existing `(user_id, dedupe_key)` unique index):
  ```php
  $notification = $publisher->publish(
      userId: $pro->id,
      frontendType:   'enquiry.received',         // normalizes to type='Info' internally
      category:       'inbox',                     // registered in mailables registry; this is the real discriminator
      title:          __('notifications.enquiry.title'),  // PII-free; e.g. "New enquiry"
      body:           Str::limit($enquiry->message, 140),
      dedupeKey:      "enquiry:{$enquiry->id}",   // idempotent across retries
      ctaUrl:         "/dashboard/enquiries/{$enquiry->id}",
  );

  if ($notification !== null) {
      $enquiry->notification_id = $notification->id;
      $enquiry->saveQuietly();  // notification_id is in $fillable; saveQuietly avoids tripping future observers
  }
  ```
  - **Title is PII-free** so a GDPR redaction doesn't have to scrub it. Notification `body` is the only PII surface in the notifications row and is covered by the redact cascade.
  - **`dedupeKey: "enquiry:{$enquiry->id}"`** makes the publish idempotent against Horizon retries — re-dispatching the job won't create duplicate notification rows. The unique index `notifications_dedupe_key_per_pro_uq` on `(user_id, dedupe_key)` enforces this at the DB layer.
  - **`type` column stores `'Info'`** (the result of `Notification::normalizeFrontendType('enquiry.received')` — falls through to the `'Info'` default). The frontend-side discriminator is `category='inbox'` and/or `dedupe_key LIKE 'enquiry:%'`, NOT the `type` column.
  - On `publish()` returning `null` (capability gate, invalid input), `notification_id` stays null. The auto-read step in `show()` is a no-op in that case. Nightwatch logs the gap via the publisher's own warning path.

- **`EmailEnquiryNotificationAdapter`** owns the per-pro hourly rate-limit (which currently lives in the controller — must be moved here as part of this work):
  ```php
  if (RateLimiter::tooManyAttempts("enquiry_email:{$pro->id}", $hourlyMax)) {
      return; // silently drop; logged to Nightwatch
  }
  RateLimiter::hit("enquiry_email:{$pro->id}", 3600);
  SendEnquiryNotificationJob::dispatch($enquiry->id, $block->id);
  ```
  Job internals (PII-at-handle-time pattern, `email_sent_at` write) are unchanged.

The adapter list is registered in the dispatcher's constructor. Adding push is one new adapter class.

### Idempotency & race notes

- **Customer upsert race:** caught at the controller level via `UniqueConstraintViolationException` → re-fetch (see step 5 above).
- **Notification publish:** `dedupeKey` makes the publisher's insert idempotent under Horizon job retries.
- **Concurrent status transitions** (two browser tabs racing `markReplied` vs `archive`): last-write-wins by design. No optimistic locking for v1. The counts cache TTL bounds visible divergence to ≤ 60s.
- **`notification_id` write-back:** uses `saveQuietly()` so it doesn't fire model events. Failure here is logged to Nightwatch; auto-read in `show()` has the fallback path described above.
- **Per-IP rate limit** (3/min): known to false-positive on shared NAT (carrier NAT, corporate proxies). The per-subdomain limit (100/min) is the primary defence; per-IP is a secondary backstop. Revisit if Turnstile soak shows meaningful false-positive rates.

## Dashboard flow

### Endpoints

All on `routes/api/professional.php`, all JWT-auth + `EnquiryPolicy`-gated.

| Method + path | Controller method | Behaviour |
|---|---|---|
| `GET /me/enquiries` | `index` (extend) | Paginated list. New query params: `?status=new\|read\|replied\|archived\|spam` (default: excludes `archived` and `spam`); `?search=` (existing). Pagination via existing `paginatedResponse()` trait. |
| `GET /me/enquiries/counts` | `counts` NEW | `{new: int, read: int, replied: int, archived: int, spam: int}`. **No server-side cache** — a per-pro `GROUP BY status` on an indexed column is sub-millisecond at expected scale. Frontend should apply optimistic updates after each transition to avoid the round-trip on every click. |
| `GET /me/enquiries/{enquiry}` | `show` NEW | Full enquiry + linked Customer + last `ENQUIRY_HISTORY_LIMIT=10` enquiries from same `customer_id`. Eager-loads `customer` to avoid N+1. Auto-transitions `new → read` and marks the corresponding `notifications.notifications` receipt as read. |
| `POST /me/enquiries/{enquiry}/read` | `markRead` NEW | Sets `status='read'` + `read_at=now()` unconditionally (idempotent — same response if already read or in any other status). |
| `PATCH /me/enquiries/{enquiry}` | `update` (existing — kept as backwards-compat shim) | Existing endpoint accepting `{read: bool}`. Enhanced to ALSO set `status='read'`/`status='new'` based on the boolean, alongside `read_at`. The new `POST .../read` is the preferred path going forward; `PATCH` is retained until we've confirmed no frontend consumers remain (verify before removing in a future cleanup). |
| `POST /me/enquiries/{enquiry}/replied` | `markReplied` NEW | `status='replied'`, `replied_at=now()`. Idempotent (calling on already-replied returns 200 with no DB change). |
| `POST /me/enquiries/{enquiry}/archive` | `archive` NEW | `status='archived'`, `archived_at=now()`. Idempotent. |
| `POST /me/enquiries/{enquiry}/spam` | `markSpam` NEW | `status='spam'`, `spam_at=now()`. Triggers spam side-effects (see Security). Idempotent. |
| `POST /me/enquiries/{enquiry}/restore` | `restore` NEW | Any status back to `new`. Used for undo (e.g., unarchive, un-spam). Does NOT auto-recreate a soft-deleted Customer. |
| `DELETE /me/enquiries/{enquiry}` | `destroy` (existing) | Soft delete; 30-day retention via existing `SOFT_DELETE_RETENTION_DAYS`. |

### State transition matrix

All POST transitions accept any current status and write the target status. All are idempotent (calling `/archive` on an already-archived enquiry returns 200 with no DB change). This deliberately collapses the matrix — the alternative (rejecting "invalid" transitions like `archive → replied`) gives no real-world benefit and complicates the dashboard with error toasts.

Frontend MUST handle 200 as success regardless of prior state. There is no 409 / 422 response for transitions; the only error responses are 404 (cross-tenant or missing) and 5xx (server fault).

### Counts → tab badge mapping

The dashboard tabs are `New • Read • Replied • Archived • Spam`. Tab badges map 1:1:

- `New` tab badge → `counts.new`
- `Read` tab badge → `counts.read`
- `Replied` tab badge → `counts.replied`
- `Archived` tab badge → `counts.archived`
- `Spam` tab badge → `counts.spam`

The bell-icon top-level badge → `counts.new` (only unread enquiries warrant attention).

Default list (no `?status=` filter) returns `new + read + replied`. `archived` and `spam` only appear when explicitly filtered.

### Authorisation

New `EnquiryPolicy` in `app/Policies/EnquiryPolicy.php`, extends `BasePolicy`, registered via `Gate::policy(Enquiry::class, EnquiryPolicy::class)` in `AppServiceProvider::boot()`. Methods:

| Policy method | Used by | Rule |
|---|---|---|
| `viewAny` | `index`, `counts` | Authenticated user (any pro can list their own; uniform `user_id` filter in controller) |
| `view` | `show` | `$user->id === $enquiry->user_id` |
| `update` | `markRead`, `markReplied`, `archive`, `markSpam`, `restore` | `$user->id === $enquiry->user_id` |
| `delete` | `destroy` | `$user->id === $enquiry->user_id` |

All write transitions intentionally collapse to a single `update` gate — the user-facing model is "I own this enquiry; I can change its state". If we ever need per-transition gating (e.g., "junior staff can mark-read but not mark-spam"), each transition gets its own policy method then. YAGNI for v1.

Controller calls `$this->authorizeForUser($user, 'view', $enquiry)` (not `authorize` — Supabase JWT means `Auth::user()` is null, per CLAUDE.md). Policy denial on a tenant-foreign enquiry returns **404, not 403** (per CLAUDE.md tenant-isolation pattern: returning 403 would leak existence).

`tests/Feature/Security/PolicyCoverageTest.php` will auto-pass once the policy is registered.

### Resources

See "Models and file paths" section above for current/required `EnquiryResource` shape and the new `EnquiryDetailResource` contract. Key API-level behavior:

- `EnquiryResource` retains existing `is_read` and `read_at` for backwards compat; gains `status`, `replied_at`, `archived_at`, `spam_at`.
- `EnquiryDetailResource` adds: `mailto_url` (server-built, `rawurlencode()` on email and subject so special chars `&`, `?`, `+`, non-ASCII don't corrupt the link), `customer` (eager-loaded), `history` (up to `ENQUIRY_HISTORY_LIMIT = 10` sibling enquiries, filtered `WHERE redacted_at IS NULL AND deleted_at IS NULL` so redacted historicals never leak their subjects).

### Auto-read on detail view

When `show()` transitions an enquiry from `new → read`:

1. Updates `status = 'read'`, `read_at = now()`.
2. If `enquiry->notification_id` is set, loads that notification and calls:
   ```php
   app(NotificationListingService::class)->markRead($notification, $pro->id);
   ```
   (The real method — `NotificationReceipt::markRead()` does NOT exist; the publisher pattern lives in `NotificationListingService::markRead(Notification, string)`.)
3. If `notification_id` is null (legacy enquiry or in_app channel wasn't selected), the auto-read step is a no-op.

Idempotent throughout: calling `show()` on an already-read enquiry runs the transitions but they are no-ops.

## Security & retention

### Existing protections (unchanged)

- Honeypot `website` field — silent 200 OK on hit, logged as abuse.
- Timing bot check `form_started_at_ms ∈ [2.5s, 12h]`.
- Rate limit: 3/min per IP, 100/min per subdomain (`throttle:leads`).

### Bot-protection — already wired, just confirm mode

The `bot-protection-foundation` work (merged into `development` 2026-05-27) replaced the dormant `VerifyTurnstileCaptcha` middleware with a full driver-based system. Relevant facts:

- **Middleware:** `app/Http/Middleware/VerifyBotToken.php`, aliased as `bot.token:{action}`.
- **Already on the enquiry route:** `routes/api/publicSite.php` has `->middleware(['lead.log', 'throttle:leads', 'bot.token:enquiry'])`. No spec work needed to apply it.
- **Config knobs** (in `.env`):
  - `BOT_PROTECTION_DRIVER` = `null` | `turnstile` | `hcaptcha` | `fake` (default `null` locally; `turnstile` in deployed envs).
  - `BOT_PROTECTION_MODE` = `off` | `shadow` | `enforce` (default `off` locally; `enforce` in deployed envs).
  - `BOT_PROTECTION_FAIL_OPEN` = `true` (pre-pilot default — provider outages let traffic through).
  - `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET` (frontend + backend respectively).
- **Error codes** (already surfaced by `VerifyBotToken::reject()`):
  - `captcha_missing` — no token submitted (frontend forgot to render the widget).
  - `captcha_unavailable` — 503; provider outage with `BOT_PROTECTION_FAIL_OPEN=false`.
  - Provider-verification failures (forged / expired tokens) surface from `CaptchaManager::verify()` via `CaptchaProviderException`.
- **Shadow mode** observation-only: logs reject reasons but never blocks. Use it for one week in dev after the frontend widget ships.

**Work this spec adds on top of bot-protection:** none on the middleware itself. The detailed runbook lives at `docs/auth/bot-protection-supabase.md` and the design spec at `docs/superpowers/specs/2026-05-26-bot-protection-foundation-design.md` — read those for the full ops surface.

**Rollout sequence for this surface specifically:**
1. Frontend ships the Turnstile widget on the contact form (passes `action="enquiry"`).
2. Set `BOT_PROTECTION_MODE=shadow` in dev. Watch `bot_protection.shadow_reject` log lines for 1 week.
3. Flip to `enforce` in dev. Smoke-test real submissions.
4. Promote `enforce` to prod after the dev soak is clean.

### New: Mark-as-spam downstream behaviour

When a pro hits spam, the controller runs inside a `DB::transaction` with explicit row locks:

```php
DB::transaction(function () use ($enquiry, $pro) {
    $enquiry->markSpam();  // status, spam_at

    if ($enquiry->customer_id) {
        $customer = Customer::whereKey($enquiry->customer_id)
                            ->lockForUpdate()
                            ->first();

        if ($customer && $customer->source === 'enquiry') {
            // No touchpoints other than this email-linked record?
            $hasOtherEnquiries = Enquiry::where('customer_id', $customer->id)
                                         ->where('id', '!=', $enquiry->id)
                                         ->exists();
            $hasSubscription = EmailSubscription::where('user_id', $pro->id)
                                                  ->whereRaw('lower(email) = ?', [strtolower($customer->email)])
                                                  ->exists();

            if (! $hasOtherEnquiries && ! $hasSubscription && empty($customer->external_id)) {
                $customer->delete();  // soft delete
            }
        }
    }

    $this->spamBlocklist->add($pro->id, $enquiry->email);
});
```

The `lockForUpdate()` on the Customer row prevents a concurrent enquiry submission from racing the touchpoint check.

### New: Spam blocklist (per-pro)

Stored as a Redis **sorted set** at `enquiry_spam:{user_id}`, member = `HMAC_SHA256(app_key, lower(email))`, score = unix expiry timestamp (90 days out).

```php
// Add:
$expiresAt = now()->addDays(90)->timestamp;
Redis::zadd("enquiry_spam:{$proId}", $expiresAt, $this->hash($email));
Redis::zremrangebyscore("enquiry_spam:{$proId}", 0, now()->timestamp); // clean expired
Redis::expire("enquiry_spam:{$proId}", 7776000); // 90 days, refreshes key TTL on every add

// Check:
$score = Redis::zscore("enquiry_spam:{$proId}", $this->hash($email));
return $score !== null && $score >= now()->timestamp;

// hash():
return hash_hmac('sha256', strtolower($email), config('app.key'));
```

**Why HMAC, not plain SHA-256:** plain hashes of common emails are trivially reversible by dictionary attack. HMAC with the app key forces an attacker who somehow reads the Redis set to brute-force per email, per set, with no shortcuts.

**Size cap:** sorted set members capped at 500 per pro (oldest expired members evicted first via `ZREMRANGEBYRANK ... 0 -501` after each add). Bounds memory: 500 × ~100 bytes ≈ 50KB per pro × 10k pros = ~500MB Redis worst case.

**Failover semantics:** Redis is configured with AOF persistence in this stack (verify with infra). On a cluster failover with persistence loss, the blocklist resets to empty — pros' previously-spammed senders can submit again until re-flagged. Acceptable degraded-mode behaviour; documented for operators.

The set is NEVER exposed via any API endpoint.

### PII handling

The Enquiry, Customer, and notification rows all contain personal data subject to GDPR / Australian Privacy Principles. Handling rules:

- **`Enquiry::$hidden`** already hides `name`, `email`, `message` from default serialization (existing behaviour).
- **Soft-delete** via the existing 30-day `SOFT_DELETE_RETENTION_DAYS` retention path.
- **`Customer::redact()` is a NEW method** — must be implemented. Nulls PII fields on the Customer row and sets `redacted_at`. Then cascades:
  ```php
  public function redact(): void
  {
      $this->update([
          'email' => null, 'full_name' => null, 'phone' => null, 'notes' => null,
          'redacted_at' => now(),
      ]);
      Enquiry::where('customer_id', $this->id)->each->redact();
  }
  ```
- **`Enquiry::redact()` is also a NEW method.** Nulls `name`, `email`, `message`, sets `redacted_at`, AND scrubs the linked notification:
  ```php
  public function redact(): void
  {
      $this->update([
          'name' => null, 'email' => null, 'message' => null,
          'redacted_at' => now(),
      ]);
      if ($this->notification_id) {
          Notification::where('id', $this->notification_id)->update([
              'title' => '[redacted]',
              'body'  => '[redacted]',
          ]);
      }
  }
  ```
- **Notification `title` and `body` are both redacted.** The title is already PII-free at creation (`"New enquiry"`, no name), but the body holds the first 140 chars of the message — definitely PII.
- **History list filter:** the detail-view history query excludes rows with `redacted_at IS NOT NULL` so redacted historical subjects never leak.

### Logging discipline

Anything written to Nightwatch / server logs hashes email (`hash('sha256', $email)`) — matches the pattern at `PublicEmailSubscriptionController.php:112`. Raw PII never goes to logs. The existing `lead.log` middleware on the public endpoint stays as-is. User-agent strings (stored plaintext in `analytics.lead_submissions`) are out of scope for this spec — that's an existing classification covering all lead capture flows.

### Capability gating

In-app dispatch goes through `NotificationPublisher`, which checks `SendTransactionalNotificationEmailJob::CAPABILITY_GATE_MAP`. **That map is currently empty**, so for v1 every account type passes the gate — fine since the spec is for individual professionals only. The hook is there: when a future account type needs to opt out of in-app notifications, add `'inbox' => 'canReceiveInAppNotifications'` to the map.

The email path is intentionally not capability-gated (existing doctrine — contact form is universal).

## Testing

| Test file | Cases |
|---|---|
| `tests/Feature/Contact/PublicEnquirySubmissionTest.php` (extend) | `customer_id` populated; `status='new'` set; **public response shape is `{ok: true}` only, no `enquiry_id`**; `DispatchEnquiryNotificationsJob` queued (assert via `Bus::fake()`); `upsertEnquiryCustomer` returns Customer and handles `UniqueConstraintViolationException` race via re-fetch; silent 200 when sender's email HMAC is in pro's spam blocklist. |
| `tests/Feature/Enquiry/EnquiryInboxControllerTest.php` (new) | Each new endpoint: happy path; 404 cross-tenant; all transitions idempotent (calling twice = same state); auto-read on detail view sets `status='read'` + calls `NotificationListingService::markRead`; detail view returns `mailto_url` correctly encoded with `&` / `?` / non-ASCII; counts endpoint accuracy across all 5 states; history filtered by `redacted_at IS NULL`. |
| `tests/Feature/Enquiry/EnquiryNotificationDispatcherTest.php` (new) | Channel resolution: block setting respected; default `[in_app]` when missing; both adapters fire when both channels present; `InAppEnquiryNotificationAdapter` writes `notification_id` back to enquiry; dedupeKey makes publish idempotent under retry; `EmailEnquiryNotificationAdapter` respects per-pro hourly rate limit (moved from controller); job retries on adapter failure per Horizon defaults. |
| `tests/Feature/Enquiry/EnquirySpamTest.php` (new) | Mark-as-spam soft-deletes lone Customer; preserves Customer with other touchpoints (other enquiry, email subscription, external_id); spam side-effects run inside transaction with `lockForUpdate`; adds email HMAC to Redis sorted set with 90-day score; subsequent submission from same email returns silent 200 `{ok: true}`; expired set members are evicted on next add; restore does NOT auto-recreate the soft-deleted Customer. |
| `tests/Feature/Enquiry/EnquiryPolicyTest.php` (new) | Each policy method × (owner / other-pro / no-user); cross-tenant 404 not 403; `tests/Feature/Security/PolicyCoverageTest.php` auto-passes once policy is registered. |
| `tests/Feature/Enquiry/EnquiryRedactionTest.php` (new) | `Customer::redact()` cascades to Enquiry; `Enquiry::redact()` nulls PII + sets `redacted_at` + scrubs linked notification title and body; history list excludes redacted enquiries. |
| `tests/Feature/Contact/ContactSectionConfigTest.php` (extend) | `notification_channels` validation: rejected when not array, rejected when invalid channel name, rejected when contains `email` but `notification_email` is null, accepted when key missing (defaults to `[in_app]`). |
| Bot-protection coverage | Already covered by the bot-protection-foundation spec's own test suite (per-surface action-tag round-trip tests; FakeProvider unit tests). This spec does NOT add new bot-protection tests — we inherit the foundation. |

## Rollout

1. Branch off `development`. Write the Supabase migration with the ordering documented above.
2. Dev DB: `supabase db push --dry-run`, review SQL, then `supabase db push`. The migration includes `CREATE INDEX CONCURRENTLY` statements; Supabase CLI handles these correctly outside transaction blocks.
3. Verify backfill counts in dev DB:
   - `status='read'` count == pre-migration `read_at IS NOT NULL` count.
   - `customer_id IS NOT NULL` count ≥ 99% (some old enquiries may have orphaned emails).
   - After a smoke-test enquiry: the inserted `notifications.notifications` row has `category='inbox'`, `type='Info'` (the result of normalizing `'enquiry.received'`), and `dedupe_key='enquiry:{enquiry_id}'`.
4. Code deploy to dev (`development` branch). Backend changes only.
5. Manual smoke test in dev: submit enquiry on a dev site → confirm `site.enquiries` row, `site.customers` row, `notifications.notifications` row populated. `enquiry.notification_id` matches the notification id. Watch `cloud env:logs partna development --minutes 10` for unexpected exceptions.
6. Hand off frontend appendix to the frontend Claude. Wait for frontend to ship the dashboard inbox UI, contact block renderer, and Turnstile widget.
7. Once frontend is live in dev: set `BOT_PROTECTION_MODE=shadow` in Laravel Cloud dev env (driver should already be `turnstile`). Soak for 1 week, watching `bot_protection.shadow_reject` log lines in Nightwatch.
8. Flip `BOT_PROTECTION_MODE=enforce` in dev. Smoke-test enquiry submissions with a real Turnstile widget. Watch for `bot_protection.fail_open` warnings.
9. Promote to `production`: merge `development` → `production`, push migration to prod (`supabase link --project-ref edplucmvkcnokyygxqsb` then `db push --dry-run` then `db push`), deploy code, then set `BOT_PROTECTION_MODE=enforce` in prod.

## Frontend handoff

This appendix is the contract for the frontend session (separate repo `partna-frontend` + `@partna/themes` package). Backend implementation and frontend implementation are decoupled — they only need to agree on this contract.

### Authentication

- **Public endpoints** (`POST /public/enquiry`): no auth header.
- **Dashboard endpoints** (`/me/enquiries/*`): require `Authorization: Bearer <supabase_access_token>` header. The frontend already sets this globally for `/me/*` routes.

### API base URL — config-injected, not hardcoded

The `@partna/themes` package is framework-agnostic and must NOT hardcode an environment-specific URL. The consumer (Astro shell, Hydrogen shell, etc.) injects the base URL as configuration:

```ts
// In theme component:
fetch(`${config.apiBaseUrl}/public/enquiry`, { ... });
```

Environment values:
- Production: `https://api.partna.au`
- Development: `https://dev-api.partna.au`

### Theme contract — contact block renderer (`@partna/themes`)

Block settings shape (what the theme receives in `block.settings`):

```ts
type ContactBlockSettings = {
  headline?: string;          // max 80 chars
  description?: string;       // max 300 chars
  notification_email?: string; // backend-only — DO NOT render
  subject_options?: string[];  // ≤10, max 60 chars each — merged with platform defaults
  notification_channels?: ('in_app' | 'email')[];  // backend-only — DO NOT render
};
```

Form fields to render (all required unless noted):

- `name` — text, 1–120 chars
- `email` — type=email, validated
- `subject` — select, options = platform defaults from `config('partna.contact_subject_defaults')` ∪ `subject_options`
- `message` — textarea, 1–2000 chars
- `website` — hidden honeypot field; MUST be in DOM, MUST be empty when submitted (any value triggers silent abuse-log + fake 200)
- `form_started_at_ms` — hidden, set to `Date.now()` on mount
- `cf-turnstile-response` — Cloudflare Turnstile widget token. The `VerifyBotToken` middleware (alias `bot.token:enquiry`) reads this header/field. Render the widget always when `BOT_PROTECTION_MODE` is `shadow` or `enforce`; backend driver config (`BOT_PROTECTION_DRIVER`) determines which provider validates the token. Mount with `action="enquiry"` (the action tag must match the route's middleware action). Load `https://challenges.cloudflare.com/turnstile/v0/api.js`.

Submit target: `POST {config.apiBaseUrl}/public/enquiry`. JSON body.

**Success response shape:** `{"ok": true}` — no `enquiry_id` (intentionally omitted to avoid PII surface). The honeypot and spam-blocklist paths return the identical shape so client behavior is uniform on success.

Success UX: form replaced with confirmation message. No redirect.

**Error response shapes:**

```json
// 422 from PublicEnquiryRequest validation (standard ApiController::error()):
{"message": "...", "errors": {"name": ["..."], ...}}

// 422 from VerifyBotToken middleware (verified at app/Http/Middleware/VerifyBotToken.php):
{
  "message": "Verification failed.",
  "error": "captcha_missing",          // or other captcha error code
  "captcha": {
    "should_retry": true,
    "should_rerender": true
  }
}

// 429 rate limit:
{"message": "Too many requests, try again in a minute"}

// 503 from VerifyBotToken when fail_open=false and provider is down:
{
  "message": "Verification temporarily unavailable.",
  "error": "captcha_unavailable",
  "captcha": {
    "should_retry": true,
    "should_rerender": false
  }
}

// 5xx generic (Laravel default):
{"message": "Couldn't send right now, try again shortly"}
```

Error UX:
- `422` with `errors.{field}` → field-level validation errors; scroll to first error.
- `422` with `error: "captcha_*"` and `captcha.should_rerender: true` → re-render the Turnstile widget and prompt user to retry.
- `429` → show `message` as a toast.
- `503` with `error: "captcha_unavailable"` and `captcha.should_retry: true` → show "Spam check is temporarily down — try again in a moment" with a retry button.
- `5xx` → generic "Couldn't send right now, try again shortly".

### Dashboard contract — enquiry inbox UI (`partna-frontend`)

**New routes:**
- `/dashboard/enquiries` — list with status tabs (New • Read • Replied • Archived • Spam). Counts from `GET /me/enquiries/counts`. Default tab: All visible statuses (i.e., default list excludes archived and spam).
- `/dashboard/enquiries/{id}` — detail. Three panels: enquiry / contact card / history list (each history item links to its own detail page).

**API endpoints to consume:** see "Endpoints" table above.

**Tab badges:** the bell-icon top-level badge is `counts.new`. Each tab badge is `counts.{tab_name}` 1:1. After any status transition click, the frontend should **optimistically update** its local copy of `counts` (decrement source, increment destination) AND issue a fresh `GET /me/enquiries/counts` to reconcile. The endpoint has no server-side cache; the GET is sub-millisecond at expected scale.

**Bell-icon inbox:** the existing `/me/notifications` poller automatically includes `type='enquiry.received'` rows. Bell click navigates to `cta_url` (= `/dashboard/enquiries/{id}`). Opening the detail page auto-marks both the enquiry and the notification as read; the frontend should optimistically decrement the bell badge on detail-view navigation.

**Reply UX (v1):** use the server-built `mailto_url` field from the detail response — it is already URL-encoded for special characters. Click handler:
```ts
window.location.href = enquiry.mailto_url;  // e.g., "mailto:foo%40example.com?subject=Re%3A%20..."
```
A separate "Mark as replied" button on the same panel calls `POST /me/enquiries/{id}/replied`. The flow: click email → compose externally → come back → click "Mark as replied".

**Transition responses:** all `POST /me/enquiries/{id}/{action}` endpoints are **idempotent** — they return 200 regardless of prior state, including when called on an enquiry already in that state. The frontend does NOT need to handle 409 / 422 for "invalid" transitions; there are no such errors. The only error responses are 404 (cross-tenant or missing) and 5xx.

**Block authoring UI** (already exists in dashboard, needs one addition): the contact-block settings form needs a new control for `notification_channels` — two checkboxes ("Notify me in the dashboard" pre-checked; "Email me at {notification_email}", disabled if `notification_email` is empty). Validation: at least one must be checked; "email" cannot be checked unless `notification_email` is set.

### Bot-protection config (already wired via bot-protection-foundation)

Per `docs/auth/bot-protection-supabase.md`, the env vars needed in Laravel Cloud dev:

- `BOT_PROTECTION_DRIVER=turnstile`
- `BOT_PROTECTION_MODE=shadow` (initially; flip to `enforce` after 1-week soak)
- `BOT_PROTECTION_FAIL_OPEN=true` (pre-pilot default — keep until ATO incident motivates flipping)
- `TURNSTILE_SECRET=...` (backend)
- `TURNSTILE_SITE_KEY=...` (frontend's site-key env)

Rollout: frontend renders the Turnstile widget with `action="enquiry"`; backend already accepts the token at `POST /public/enquiry` via the existing `bot.token:enquiry` middleware. After the dev soak is clean, flip `BOT_PROTECTION_MODE=enforce` in dev, then push the same env change to prod.

## Verification checklist before marking done

- `composer test` green (enforces "no Laravel migrations" guard automatically)
- `php artisan pint` clean
- Manual smoke test in dev: enquiry submitted → all 3 places (DB row, customer row, `notifications.notifications` row) populated; `enquiry.notification_id` matches notification id
- `cloud env:logs partna development --minutes 10` shows zero unexpected exceptions
- Nightwatch dev project shows no new exception class for `Enquiry*`
- Detail endpoint `GET /me/enquiries/{id}` returns < 50ms p95 in dev with realistic enquiry counts
- Submitting an enquiry with a sender email previously flagged spam returns silent `{"ok": true}` (no DB write)
- Marking a lone-touchpoint enquiry as spam soft-deletes the Customer; marking a multi-touchpoint enquiry as spam preserves the Customer
- `Customer::redact()` cascades through Enquiry rows; redacted notification title and body show `'[redacted]'`
- All POST status transitions return 200 when called twice in a row (idempotency)
- `mailto_url` in detail response correctly encodes `&`, `?`, `+`, non-ASCII subjects
- Public response shape is `{"ok": true}` only — no `enquiry_id` field present
- Bot-protection middleware in `shadow` mode logs `bot_protection.shadow_reject` lines but never blocks; in `enforce` mode rejects with the verified-shape 422 / 503 (see Frontend handoff > Error response shapes).
