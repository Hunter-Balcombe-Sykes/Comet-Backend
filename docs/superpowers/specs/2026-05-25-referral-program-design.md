# Referral Program — Backend Design

**Date:** 2026-05-25
**Status:** Design approved; **amended 2026-09-03** — the deferred settlement questions are now answered (§15). Ready for implementation plan
**Scope:** Partna backend only (Laravel 12 + Supabase + PostgreSQL). Frontend UX and dashboard implementation live in the separate frontend repo.
**Author:** Brainstorm session with Josh

---

## 1. Overview

A "referral signup" section that Partna professionals can toggle on their public site, alongside existing sections (gallery, links, etc.). A visitor enters their email and a desired handle; a Partna account is provisioned with their own site; the visitor is sent a magic link to verify. When the new user pays their first month's subscription (post-billing-launch), the referrer earns a credit worth **N months of their own subscription** (N configurable, default 1) — see §15 (R1, R4).

Because billing was stripped in the 2026-05-22 standalone strip, this feature ships in a **"track now, settle later"** posture: all referrals are recorded in the database with full lifecycle state; the credit-settlement step is wired to a stubbed billing event that becomes live when subscriptions return.

## 2. Goals

- Let a professional turn on a referral signup section as a toggleable block on their public site
- Let a visitor enter email + chosen handle inline and become a Partna user with their own site
- Attribute every signup to its referrer at the database level
- Earn the referrer a credit worth N months of subscription for any referred user who pays (R4)
- Build the data model durably enough that when billing returns, the settlement step is a one-line `EventServiceProvider` change

## 3. Non-goals

- Frontend / public-section UI / dashboard UI (separate frontend plan)
- Reintegrating Stripe / subscription billing (future)
- Cash-out via Stripe Connect — **decided 2026-09-03 (R1): designed for, not built.** The ledger stores an amount owed so a payout lane bolts on without touching attribution; v1 settles as an invoice credit only
- Custom referral codes / shareable URLs — the referrer's existing handle IS the referral identifier
- Referral leaderboards or gamification
- Multi-tier referrals (no "refer the referrer" chains)

## 4. Locked decisions (from brainstorm)

| Decision | Rationale |
|----------|-----------|
| Track now, settle later | Billing stripped 2026-05-22; design integrates cleanly when subs return |
| Inline email + handle on the public section | Matches the "like an email list" UX requested; commits the visitor to a chosen handle before they leave the page |
| Earn-trigger: verify email + pay first month | Matches "first month subscription fee" wording; payment is itself the strongest fraud filter |
| Editable headline / subheadline / CTA via `block.settings` JSONB | Niche-tailored pitches lift conversion; tiny code cost; matches existing block customization pattern |
| Lean anti-abuse posture | Payment requirement is the real fraud gate; cheap layered checks elsewhere |
| Dashboard: list with handles (NOT emails) + notifications on create + credit | Standard SaaS referral dashboard; privacy-preserving |
| Service-layer separation (controller → service → state machine) | Matches `CLAUDE.md`'s "business logic in `Services/`" rule |

## 5. Architecture approach

Service-layer separation, identical pattern to other Partna features:

- `PublicReferralSignupController` is thin — validates via FormRequest, calls a service, returns a Resource
- `ReferralSignupService` orchestrates: eligibility checks, handle reservation, Supabase Auth user creation, DB transaction for User + Site + Referral rows, `afterCommit` magic-link dispatch
- `ReferralStateMachine` is a pure FSM — validates legal transitions, no side effects
- `ReferralAttributionService` drives the state machine and dispatches side-effect jobs (KV sync, notifications)
- `ReferralQueryService` is read-only — builds the dashboard list and summary

**Alternatives considered and rejected:**

- **Fat controller** — single endpoint with all logic inline. Rejected: doesn't match Partna's codebase style.
- **Event/observer chain** — controller fires events, listeners handle attribution. Rejected: harder to trace, over-engineered for v1.

## 6. Data model

### 6.1 New block type

Extend the `site.blocks.block_type` CHECK constraint to include `'referral_signup'`. No new table for the block itself — it reuses the existing block infrastructure (`is_active`, `is_enabled`, `display_order`).

`block.settings` JSONB shape for this type:

```json
{
  "headline": null,
  "subheadline": null,
  "cta_label": null
}
```

Validation rules (in `BlockUpdateRequest` for this block type):
- Each field: `nullable|string|max:200`
- `headline` / `subheadline` / `cta_label`: strip HTML tags on store (`strip_tags` after trim); reject control characters
- All three fall back to platform defaults at render time if null

### 6.2 New table: `core.referrals`

Migration: `supabase/migrations/<timestamp>_create_core_referrals.sql` (raw SQL, no Laravel migration).

```sql
CREATE EXTENSION IF NOT EXISTS citext;

CREATE TABLE core.referrals (
  id                        UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  referrer_user_id          UUID NOT NULL REFERENCES core.users(id) ON DELETE SET NULL,
  referred_user_id          UUID NULL REFERENCES core.users(id) ON DELETE CASCADE,
  referred_email            CITEXT NOT NULL,
  referred_handle           TEXT NOT NULL,
  status                    TEXT NOT NULL CHECK (status IN (
                              'pending_verification',
                              'verified',
                              'eligible_pending_billing',
                              'credited',
                              'voided'
                            )),
  credit_kind               TEXT NULL,
  credited_amount_cents     INTEGER NULL,
  credited_currency         TEXT NULL CHECK (credited_currency ~ '^[A-Z]{3}$'),
  voided_reason             TEXT NULL,
  expires_at                TIMESTAMPTZ NOT NULL,
  magic_link_sent_at        TIMESTAMPTZ NULL,
  verified_at               TIMESTAMPTZ NULL,
  credited_at               TIMESTAMPTZ NULL,
  voided_at                 TIMESTAMPTZ NULL,
  created_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at                TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- First-attribution-wins: one ACTIVE referral per email, enforced at the DB level.
CREATE UNIQUE INDEX referrals_email_active_uniq
  ON core.referrals (referred_email)
  WHERE status <> 'voided';

CREATE INDEX referrals_referrer_status_idx ON core.referrals (referrer_user_id, status);
CREATE INDEX referrals_status_idx          ON core.referrals (status);
CREATE INDEX referrals_expires_idx         ON core.referrals (expires_at)
  WHERE status = 'pending_verification';

COMMENT ON COLUMN core.referrals.referred_email IS
  'Denormalised from core.users.email. Kept even if referred user is hard-deleted (audit).';
COMMENT ON COLUMN core.referrals.magic_link_sent_at IS
  'Populated after SendReferralMagicLinkJob confirms send. NULL = referrals:repair-pending will re-dispatch.';
COMMENT ON COLUMN core.referrals.expires_at IS
  'TTL for unverified shells. created_at + config("partna.referral.shell_ttl_days") (default 7).';
```

**Field rationale:**

- `referrer_user_id ON DELETE SET NULL` — keep the referral row for audit if the referrer is hard-deleted; flip to voided via observer.
- `referred_user_id ON DELETE CASCADE` — if the new user hard-deletes their account, the referral disappears (no one to credit).
- `referred_email CITEXT` — case-insensitive uniqueness without manual `LOWER()` everywhere.
- Denormalised `referred_email` alongside the FK — preserves the email for audit even after a cascade delete.
- Partial unique index on `referred_email WHERE status <> 'voided'` — enforces first-attribution-wins at the DB level; re-attempts allowed after a voided expiry.
- `magic_link_sent_at` — probe for the process-death recovery job (see §10.9).
- `voided_reason` as TEXT (not enum) — reasons evolve faster than migrations.

### 6.3 Lifecycle state machine

```
[POST /referrals/signup]
          │
          ▼
pending_verification ──(TTL hit + 15min grace, pruner voids)──▶ voided (magic_link_expired)
          │
          │ (magic link clicked → user-verified event)
          ▼
       verified ──────────────(mark_eligible, called synchronously)─────────▶ eligible_pending_billing
                                                                                       │
                                                                                       │ (first month paid — future billing webhook)
                                                                                       ▼
                                                                                   credited
                                                                                       │
                                                                                       │ (refund-window reversal — future)
                                                                                       ▼
                                                                                   voided (churned_before_first_month)
```

Legal transitions enumerated in `ReferralStateMachine::LEGAL_TRANSITIONS` constant; anything else throws `IllegalReferralTransition`.

```php
private const LEGAL_TRANSITIONS = [
    'pending_verification'     => ['verified', 'voided'],
    'verified'                 => ['eligible_pending_billing', 'voided'],
    'eligible_pending_billing' => ['credited', 'voided'],
    'credited'                 => ['voided'],   // refund-window reversal
    'voided'                   => [],            // terminal
];
```

## 7. API surface

### 7.1 Public endpoints (no auth; IP-throttled)

| Method | Path | Throttle | Purpose |
|--------|------|----------|---------|
| `GET`  | `/v1/public/handles/check` | `30,1` per IP/min | Realtime handle availability check. Query param `handle`. Returns `{ available: bool, reason: 'taken'\|'reserved'\|'invalid'\|null }` — booleans only, no suggestions, to keep enumeration cost high. The 30/min ceiling accommodates a debounced typing UX (legitimate visitors will hit ~5–15 lookups during one signup attempt). |
| `POST` | `/v1/public/referrals/signup` | `3,1` per IP/min + `3` per target email per day | Inline form submission. Body: `{ email, handle, referrer_handle }`. Atomic create + magic-link dispatch. |
| `POST` | `/v1/public/referrals/resend` | `1,1` per email/min | Re-issue magic link for an existing `pending_verification` row. Body: `{ email }`. Returns generic success regardless of whether row exists (no enumeration). |

### 7.2 Authenticated endpoints (`auth:supabase` middleware)

| Method | Path | Purpose |
|--------|------|---------|
| `GET`  | `/v1/user/referrals` | Paginated list owned by authed user. Returns: handle (NULL until verified), status, created_at, verified_at, credited_at, credited_amount_cents. **Never email.** |
| `GET`  | `/v1/user/referrals/summary` | Lightweight stats for the dashboard widget: `{ total, pending_verification, verified, eligible_pending_billing, credited, total_pending_amount_cents }`. |
| `PUT`  | `/v1/user/sites/{site}/blocks/{block}` | Existing endpoint — updates `block.settings` JSONB for `referral_signup` type. Existing `SiteBlockPolicy::update` covers authz. |

### 7.3 Submit flow

```
Visitor                Public API                    Backend services                Supabase Auth
   │                       │                              │                                  │
   │ GET /handles/check    │                              │                                  │
   ├──────────────────────▶│ throttle:3,1                │                                  │
   │                       │ HandleAvailabilityService::isAvailable($handle)                  │
   │◀──{available:true}────│                              │                                  │
   │                       │                              │                                  │
   │ POST /referrals/signup│                              │                                  │
   │ {email,handle,ref}    │                              │                                  │
   ├──────────────────────▶│ throttle:3,1 + target-email throttle (Redis)                    │
   │                       │ FormRequest validates                                            │
   │                       │ ReferrerEligibilityValidator (verified, not suspended, capability, block active) │
   │                       │ Self-referral check                                              │
   │                       │ Existing-user check                                              │
   │                       │                              │                                  │
   │                       │ SupabaseAdminService::createPasswordlessUser($email) ─────────▶ │
   │                       │ ◀──{ supabase_user_id }─────────────────────────────────────────│
   │                       │                                                                  │
   │                       │ DB::transaction {                                                │
   │                       │   INSERT INTO core.users (..., supabase_user_id) ── handle race │
   │                       │   INSERT INTO site.sites (NOT yet synced to KV)                 │
   │                       │   INSERT INTO core.referrals (status=pending_verification)      │
   │                       │ }                                                                │
   │                       │                                                                  │
   │                       │ DB::afterCommit ─▶ dispatch SendReferralMagicLinkJob            │
   │                       │                              SendReferralMagicLinkJob          │
   │                       │                              ├─ generate magic link ─────────▶ │
   │                       │                              ├─ send via mail ──────────────▶  │
   │                       │                              └─ UPDATE referrals               │
   │                       │                                  SET magic_link_sent_at=NOW()  │
   │◀──{status:'shell_created'}                                                              │
```

**On Supabase orphan risk:** if the DB transaction rolls back after `createPasswordlessUser` succeeds (e.g., handle race lost), the Supabase user is orphaned. Mitigated by:
- A `referrals:cleanup-orphan-auth-users` console command, scheduled every 6h, that lists Supabase users not present in `core.users` older than 1h and deletes them via admin API.

### 7.4 Verification flow

```
Visitor clicks magic link
   │
   ▼ (Supabase auth handles the redirect / token exchange)
Backend AuthCallback fires existing UserEmailVerified event
   │
   ▼
RecordReferralVerificationOnUserVerified listener (ShouldBeUnique by user.id)
   │
   ▼
ReferralAttributionService::recordVerification($user)
   │
   ▼ DB::transaction {
       Referral::where('referred_user_id', $user->id)
         ->where('status', 'pending_verification')
         ->where('expires_at', '>', now())
         ->lockForUpdate()                                  // pessimistic lock; webhook concurrency
         ->first()
       state machine: pending_verification → verified
       state machine: verified → eligible_pending_billing  (synchronous, no billing yet)
       UPDATE referrals SET verified_at=NOW(), status='eligible_pending_billing'
   }
   │
   ├─▶ dispatch SyncSubdomainToKvJob (site now publicly routable)
   ├─▶ dispatch ReferralVerifiedNotification (to referrer)
   └─▶ dispatch WelcomeReferredUserNotification (to new user)
```

### 7.5 Error catalogue

| Code | HTTP | Trigger | User-facing copy |
|------|------|---------|------------------|
| `EMAIL_EXISTS` | 409 | Email already in `core.users` | "You already have a Partna account. Log in instead." |
| `HANDLE_TAKEN` | 409 | Unique violation on `core.users.handle_lc` | "Just taken. Try another." |
| `PENDING_REFERRAL` | 409 | Partial unique index hit on `referrals.referred_email` | "We already sent you a magic link. [Resend in 60s]" |
| `SELF_REFERRAL` | 422 | Visitor email = referrer email | "You can't refer yourself." |
| `REFERRER_NOT_ACCEPTING` | 422 | Block disabled, capability revoked, referrer suspended, or referrer unverified | "This page isn't accepting new signups right now." (generic to prevent enumeration) |
| `UPSTREAM_AUTH_UNAVAILABLE` | 503 | Supabase admin call failed | "Something went wrong. Please try again." |
| `TARGET_EMAIL_THROTTLED` | 422 | Per-email cap of 3 magic links per day hit | Generic 422 — same copy as `REFERRER_NOT_ACCEPTING` to prevent target-spam confirmation |
| `INVALID_HANDLE` | 422 | Handle fails format / reserved-word check | "That handle isn't allowed." |
| Throttle | 429 | IP throttle | "Hold on a sec, try again in a minute." |

## 8. Services, jobs, listeners, commands

### 8.1 Services (`app/Services/Referrals/`)

| Class | Responsibility | Key methods |
|-------|----------------|-------------|
| `ReferralSignupService` | Orchestrates the public POST. Calls Supabase admin → opens DB transaction → creates rows → dispatches magic link afterCommit | `submit(SubmitDto $dto): SubmitResult` |
| `ReferralStateMachine` | Pure FSM. Validates legal transitions. No side effects. | `transition(Referral $r, string $event, array $context = []): void` |
| `ReferralAttributionService` | Drives the state machine + dispatches side-effect jobs. Called from listeners. | `recordVerification(User $user): void`<br>`recordFirstMonthPaid(User $user, int $amountCents, string $currency): void`<br>`recordChurn(User $user): void` *(stub for refund window)* |
| `ReferralQueryService` | Read-side for the dashboard. No state mutation. | `listForReferrer(User $referrer, int $page): LengthAwarePaginator`<br>`summaryForReferrer(User $referrer): SummaryDto` |
| `ReferrerEligibilityValidator` | Centralizes the "is this referrer allowed to accept signups right now?" rule. Used inside `ReferralSignupService`. | `validate(User $referrer): void` (throws `ReferrerIneligible`) |

Existing services that need extension:

| Class | Change |
|-------|--------|
| `App\Services\Auth\SupabaseAdminService` | Add `createPasswordlessUser(string $email): string` (returns supabase_user_id) and `deleteUser(string $supabaseUserId): void` (cleanup). If service doesn't exist yet, create it. |
| `App\Services\Site\HandleAvailabilityService` | Must check `core.users.handle_lc` AND `site.professional_handle_aliases.expires_at > NOW()` (reclaim window respected). Add reserved-word list. |

### 8.2 Jobs (`app/Jobs/Referrals/`)

| Job | Trigger | Notes |
|-----|---------|-------|
| `SendReferralMagicLinkJob` | `DB::afterCommit` from `ReferralSignupService::submit()` | Implements `ShouldQueueAfterCommit`. Calls Supabase `generateLink` + sends via mail. Updates `referrals.magic_link_sent_at` on success. 3-retry exponential backoff; fails to dead-letter + Nightwatch alert. |
| `PruneExpiredReferralShellsJob` | Scheduled hourly | `WHERE status='pending_verification' AND expires_at < NOW() - INTERVAL '15 minutes'` (grace window). Voids the row + hard-deletes shell User + Site (never reached KV, safe). |

### 8.3 Listeners (`app/Listeners/Referrals/`)

| Listener | Event | Notes |
|----------|-------|-------|
| `RecordReferralVerificationOnUserVerified` | `App\Events\Auth\UserEmailVerified` (existing) | Implements `ShouldBeUnique` keyed on user.id. Calls `ReferralAttributionService::recordVerification($user)`. Idempotent — no-ops if no matching referral or already past `pending_verification`. |
| `RecordReferralCreditOnFirstMonthPaid` | `App\Events\Billing\FirstMonthPaid` (future, stubbed) | No-op until billing returns. Stubbing the binding now so the future wiring is a one-line `EventServiceProvider` change. |

### 8.4 Notifications (`app/Notifications/Referrals/`)

Standard Laravel `Notification` classes; channels: `mail` + `database`.

| Class | Recipient | When | Subject |
|-------|-----------|------|---------|
| `ReferralVerifiedNotification` | referrer | `pending_verification → verified` | `🎉 @{handle} joined Partna via your site` |
| `ReferralCreditedNotification` | referrer | `eligible_pending_billing → credited` (post-billing only) | `💰 {N} month{s} of subscription credited — @{handle} paid their first month` (amount resolved at settlement, R4) |
| `WelcomeReferredUserNotification` | new user | On verification | `Welcome to Partna` |

Mail templates: `resources/views/mail/referrals/*.blade.php`.

Per-notification capability gate: each dispatcher calls `AccountCapabilities::for($user)->can('use_referral_section')` before sending — fail-closed pattern per `account-capability-audit` skill.

### 8.5 Console commands (`app/Console/Commands/Referrals/`)

| Command | Schedule | Purpose |
|---------|----------|---------|
| `referrals:prune-expired` | hourly | Voids expired `pending_verification` rows + cleans up shell User/Site (uses 15-min grace window) |
| `referrals:repair-pending` | every 5 min | Finds `pending_verification` rows older than 2 min with `magic_link_sent_at IS NULL`; re-dispatches `SendReferralMagicLinkJob`. Process-death recovery. |
| `referrals:cleanup-orphan-auth-users` | every 6h | Lists Supabase Auth users not present in `core.users` older than 1h; deletes via admin API. Handles the distributed-txn orphan case. |
| `referrals:backfill-state` | on demand | Idempotent state recompute from `User.email_verified_at` (and future billing data). For when a webhook is missed. |
| `referrals:show {id}` | on demand | Support utility — prints a referral's current state, history, and timestamps. |
| `referrals:audit-stale-shells` | on demand | Counts `pending_verification` rows older than the configured TTL grace window. Used for ad-hoc ops checks; outputs JSON for scripting. |

Schedule registrations in `app/Console/Kernel.php`.

### 8.6 Policies (`app/Policies/`)

`ReferralPolicy` extending `BasePolicy`:

```php
public function viewAny(User $user): bool { return true; }
public function view(User $user, Referral $referral): bool
{
    return $user->id === $referral->referrer_user_id;
}
```

Register in `AppServiceProvider::boot()`:
```php
Gate::policy(Referral::class, ReferralPolicy::class);
```

`PolicyCoverageTest` sweep enforces this registration — adding the model without the policy entry fails CI.

### 8.7 Config additions (`config/partna.php`)

```php
'referral' => [
    'enabled'                       => env('PARTNA_REFERRAL_ENABLED', true),
    'shell_ttl_days'                => env('PARTNA_REFERRAL_SHELL_TTL_DAYS', 7),
    'prune_grace_minutes'           => env('PARTNA_REFERRAL_PRUNE_GRACE_MIN', 15),
    // R4: the reward is denominated in MONTHS of the referrer's own tier price,
    // resolved at settlement time from config('partna.billing.prices'), not a
    // frozen cents value. Closes open item #4 — a hardcoded 2000 silently
    // decouples from the real price the first time it moves.
    'credit_kind'                   => 'referral_subscription_months',
    'reward_months_per_referral'    => env('PARTNA_REFERRAL_REWARD_MONTHS', 1),
    'default_credit_currency'       => 'AUD',
    // R3: bounded liability, tunable without a deploy. Sized so no honest
    // referrer reaches it; it is a backstop, not a product limit.
    'referrer_lifetime_cap_months'  => env('PARTNA_REFERRAL_CAP_MONTHS', 120),
    // R2: clawback window. A refund or cancel inside the referred user's first
    // billing period reverses the credit (credited -> voided).
    'clawback_within_first_period'  => env('PARTNA_REFERRAL_CLAWBACK', true),
    'magic_link_target_throttle'    => env('PARTNA_REFERRAL_TARGET_THROTTLE_DAILY', 3),
    'orphan_cleanup_after_minutes'  => env('PARTNA_REFERRAL_ORPHAN_CLEANUP_MIN', 60),
    'handle_check_throttle'         => ['requests' => 30, 'minutes' => 1],
    'signup_throttle'               => ['requests' => 3,  'minutes' => 1],
    'resend_throttle'               => ['requests' => 1,  'minutes' => 1],
],
```

### 8.8 AccountCapabilities additions

In `App\Services\Accounts\AccountCapabilities::individualCapabilities()`:

```php
'can_use_referral_section' => true,
'notification_categories'  => [
    // ...existing...
    'referrals' => ['mail' => true, 'database' => true],
],
```

### 8.9 Routes

`routes/api/publicSite.php`:
```php
Route::middleware('throttle:partna.referral.handle_check')->group(function () {
    Route::get('/v1/public/handles/check',     [HandleAvailabilityController::class, 'check']);
});
Route::middleware('throttle:partna.referral.signup')->group(function () {
    Route::post('/v1/public/referrals/signup', [PublicReferralSignupController::class, 'store']);
});
Route::middleware('throttle:partna.referral.resend')->group(function () {
    Route::post('/v1/public/referrals/resend', [PublicReferralSignupController::class, 'resend']);
});
```

Throttle definitions in `RouteServiceProvider::boot()` reference the matching `config('partna.referral.*_throttle')` entries (see §8.7) so the requests-per-minute caps stay in one place.

`routes/api/user.php`:
```php
Route::get('/v1/user/referrals',         [UserReferralController::class, 'index']);
Route::get('/v1/user/referrals/summary', [UserReferralController::class, 'summary']);
```

## 9. Edge cases & error handling

### 9.1 Race conditions

| Race | Catch | Response |
|------|-------|----------|
| Two visitors submit same handle simultaneously | `UNIQUE` on `core.users.handle_lc` | Second submitter gets 409 `HANDLE_TAKEN`. Tx rolled back; first submitter wins. |
| Two visitors submit same email simultaneously | Partial unique index on `core.referrals` | Second gets 409 `PENDING_REFERRAL`. |
| Verification webhook fires twice | `ShouldBeUnique` on listener + `lockForUpdate()` in service | Second call no-ops. |
| Magic link clicked at TTL boundary | 15-minute grace window in pruner | Click within grace still verifies. |
| Block disabled between page load and submit | Pre-create check inside transaction | Returns 422 `REFERRER_NOT_ACCEPTING`. |

### 9.2 External-service failures

| Failure | Behavior |
|---------|----------|
| Supabase `createPasswordlessUser` fails | Return 503 `UPSTREAM_AUTH_UNAVAILABLE`. DB transaction never opened. |
| Supabase succeeds but DB tx rolls back later | Supabase user orphaned. `referrals:cleanup-orphan-auth-users` (6h) cleans up. |
| `SendReferralMagicLinkJob` fails | 3-retry exponential backoff; final fail → dead-letter + Nightwatch alert + `referrals:repair-pending` may pick it up. |
| Verification webhook never arrives | Referral sits in `pending_verification` until TTL voids it. `referrals:backfill-state` available on demand. |
| `SyncSubdomainToKvJob` fails post-verification | Standard retry; final fail → Nightwatch alert. Manual replay via existing `subdomains:sync`. Site exists but URL 404s until replay. |
| Mail provider down | DB notification (in-app) still delivers. Mail retry independently. |

### 9.3 Lifecycle / hard-delete cascades

| Event | Behavior |
|-------|----------|
| Referrer soft-deletes account (30d window) | Referrals untouched. `AccountCapabilities` returns false for deleted users → dispatchers skip. |
| Referrer hard-deletes (post-retention) | `referrer_user_id` set NULL via `ON DELETE SET NULL`. Observer on `User::deleting` voids referrals with reason `referrer_deleted`. |
| Referred user soft-deletes pre-credit | Referral untouched. If reactivated and pays, credit fires. |
| Referred user hard-deletes pre-credit | `ON DELETE CASCADE` deletes the referral entirely. |
| Referred user churns / refunds first month | **R2, decided 2026-09-03.** `ReferralAttributionService::recordChurn()` transitions `credited → voided` (reason `churned_before_first_month`). No longer a stub: the billing spec's §8.4 emits the trigger from `charge.refunded` and from `customer.subscription.deleted` landing inside the referred user's first billing period. The Stripe balance credit is reversed with a matching debit — a credit already consumed by an invoice cannot be un-applied, so the debit sits against future invoices rather than clawing money back |

### 9.4 State-machine guards

- `ReferralStateMachine::transition()` is the only legal write path; direct DB writes that skip states are not part of the design surface
- `IllegalReferralTransition` thrown on any non-listed transition
- Voided is terminal; re-attempts after a void create a new row (partial unique index allows it)

### 9.5 Authorization edges

| Attempt | Defence |
|---------|---------|
| User A reads B's referrals | Policy + query scoped to `referrer_user_id = $authUser->id` |
| Enable block without capability | `BlockUpdateRequest` + `BlockObserver::saving` check |
| `referrer_handle` doesn't exist | FormRequest `exists` rule, but returns generic `REFERRER_NOT_ACCEPTING` |
| Referrer is soft-deleted | FormRequest scoped `whereNull('deleted_at')` |
| Probe handle-check endpoint at scale | `throttle:3,1` per IP + Cloudflare WAF |

### 9.6 Observability

Per `CLAUDE.md`'s Nightwatch + Cloud logs discipline:

| Signal | Surface |
|--------|---------|
| State transition | `Log::info('referral.transition', {referral_id, from, to, reason})` — Nightwatch breadcrumb |
| Failed magic-link send | Job throws on final retry → Nightwatch exception |
| Pruner found nothing > 24h | Hourly log of `referral.pruned.count`; alertable on zero-flat-line |
| Stale shells > TTL still alive | `referrals:audit-stale-shells` artisan command — on-demand audit |
| Webhook duplicate fires | Counter `referral.webhook.duplicate`; alert on sustained spike (possible replay) |
| Unique-violation hits | Counter `referral.signup.collision.{handle,email}` |

No PII (no emails) in any log line. Verified by `account-capability-audit` skill sweep.

## 10. Additional edge cases (folded in)

### 10.1 Distributed transaction (Supabase ↔ DB)

Supabase admin call is **outside** the DB transaction (call ordering: Supabase first, then DB tx). Orphan Supabase users cleaned up by `referrals:cleanup-orphan-auth-users` cron. Documented trade-off — no true distributed-txn framework in use.

### 10.2 Magic-link TTL vs shell TTL

Supabase magic links default to 1h validity; our shell TTL is 7d. **Fix:** configure Supabase Auth project setting to extend link TTL to 168h. Resend endpoint (§7.1) handles the "lost in spam, click later" case.

### 10.3 Pruner ↔ verification race

Pruner uses `expires_at < NOW() - INTERVAL '15 minutes'` grace window — eliminates the boundary race where verification arrives just as the row is voided.

### 10.4 State-machine concurrency

Verification listener uses `SELECT ... FOR UPDATE` + `ShouldBeUnique` (keyed on user.id). Defence in depth against duplicate webhook delivery.

### 10.5 Target-email weaponization

Per-target-email throttle (Redis counter, key `referral:magic_link_throttle:{normalized_email}`, TTL 24h, cap 3). Prevents harassment via unsolicited magic-link emails. Returns generic 422 — never confirms the target.

### 10.6 Referrer eligibility preconditions

`ReferrerEligibilityValidator` checks:
- `email_verified_at IS NOT NULL`
- Not suspended (`is_active`)
- Not soft-deleted
- `AccountCapabilities::can('use_referral_section')` currently true
- `referral_signup` block on referrer's primary site is `is_active = true` AND `is_enabled = true` (capability + intent + visibility together)

All failures return the same generic `REFERRER_NOT_ACCEPTING` 422 — no enumeration.

### 10.7 Handle-pool interaction

`HandleAvailabilityService` MUST check both:
- `core.users.handle_lc` (current owners)
- `site.professional_handle_aliases.expires_at > NOW()` (still in reclaim window)
- Reserved-word list

Both checks applied at `GET /handles/check` AND at in-transaction reservation in `ReferralSignupService`.

### 10.8 Stored XSS on `block.settings` JSONB

Defence in depth:
- `BlockUpdateRequest` strips HTML tags + rejects raw `<`, `>`, `&` in `headline`/`subheadline`/`cta_label`
- Public renderer (frontend) escapes by default — verified by integration test that asserts a stored `<script>` payload comes back escaped via the public Resource transformer

### 10.9 Process-death recovery

`magic_link_sent_at` column on `core.referrals` is set only after Supabase confirms the send. The `referrals:repair-pending` command (every 5 min) finds `pending_verification` rows older than 2 min with `magic_link_sent_at IS NULL` and re-dispatches the job. Independent of queue layer.

### 10.10 Email enumeration via `EMAIL_EXISTS`

**Documented decision:** keep specific code. Industry norm (Twitter, GitHub, Vercel all do this); UX value > enumeration risk for our threat model. Revisit if a specific abuse pattern emerges.

### 10.11 Track-now-settle-later cutoff (deferred decision)

When billing launches, policy choice on max age for `eligible_pending_billing` referrals to remain creditable. Not blocking this design — see §13.

## 11. Testing strategy

### 11.1 Test layout

```
tests/
├── Unit/Services/Referrals/
│   ├── ReferralStateMachineTest.php
│   ├── ReferrerEligibilityValidatorTest.php
│   └── HandleAvailabilityServiceTest.php
├── Feature/Referrals/
│   ├── PublicReferralSignupTest.php
│   ├── HandleCheckTest.php
│   ├── MagicLinkResendTest.php
│   ├── UserReferralListTest.php
│   ├── UserReferralSummaryTest.php
│   ├── VerificationListenerTest.php
│   ├── PruneExpiredShellsTest.php
│   ├── RepairPendingMagicLinkTest.php
│   └── OrphanAuthCleanupTest.php
├── Feature/Security/
│   ├── ReferralXssTest.php
│   ├── ReferralEnumerationTest.php
│   └── ReferralPiiLeakageTest.php
└── Pest/Factories/ReferralFactory.php   # states: pending(), verified(), eligible(), credited(), voided('reason')
```

### 11.2 Critical scenarios (must pass before merge)

**Happy path:**
- Full lifecycle: POST signup → magic link dispatched → simulate verification webhook → user_verified_at populated → referral status → eligible_pending_billing → SyncSubdomainToKvJob dispatched → notifications dispatched → GET /referrals returns the row

**Races:**
- Same-handle concurrent submit: first wins; second returns 409 HANDLE_TAKEN
- Same-email concurrent submit: partial unique index catches; returns 409 PENDING_REFERRAL (Postgres-only)
- Webhook fires twice: listener idempotent (one state transition; one notification)
- Magic link clicked within 15-min grace: verifies
- Magic link clicked past grace: listener no-ops; visitor sees expired
- Block disabled between page-load and submit: 422 REFERRER_NOT_ACCEPTING
- Supabase fails: 503 + no DB rows created
- afterCommit orphan: `referrals:repair-pending` finds + re-dispatches

**Security:**
- XSS payload in headline roundtrips as escaped
- Referral list never includes `email` field
- Target-email throttle: 4th magic link to same email/day returns generic 422
- Self-referral blocked (including case variants via CITEXT)
- Handle check returns booleans only
- User A can't list B's referrals
- `PolicyCoverageTest` sweep passes
- Dispatcher skipped when capability denied

**State machine (table-driven):**
- Every legal transition allowed
- Every illegal transition throws

### 11.3 DB strategy

SQLite for the bulk of tests (fast). Postgres-tagged tests for `CITEXT`, partial unique index, CHECK constraints — `--group=postgres` in CI runs these against a disposable Supabase branch DB.

### 11.4 Test doubles

`FakeSupabaseAdminService` for `SupabaseAdminService`. `Queue::fake()` for dispatch assertions. `Event::fake()` for verification flow tests. Laravel `travel()` helper for TTL boundary tests.

### 11.5 Post-merge manual verification (per `verify-before-completion` skill)

1. `cloud env:logs partna development --tail 50` — no exceptions on the new endpoints
2. Submit a real test referral via curl against `dev-api.partna.au`; magic link arrives in real inbox
3. Click link; confirm `core.users.email_verified_at` populates and `SUBDOMAIN_KV` gets the entry (Cloudflare dashboard)
4. `/v1/user/referrals` returns the new row for the test referrer
5. Nightwatch: `referral.transition` breadcrumbs flowing; no slow-route alerts
6. `php artisan referrals:prune-expired --dry-run` against dev — touches nothing yet

## 12. Implementation order (input to the writing-plans skill)

The order below is the suggested execution sequence — each phase produces something verifiable.

1. **Phase 1 — Schema & models**
   - Migration: extend `block_type` CHECK + create `core.referrals`
   - `Referral` model + `ReferralFactory` + states
   - Eloquent casts for `block.settings` (for the new block type)
2. **Phase 2 — State machine & validators (pure logic, unit-testable)**
   - `ReferralStateMachine` + tests
   - `ReferrerEligibilityValidator` + tests
   - `HandleAvailabilityService` extension + tests
3. **Phase 3 — Policies & registration**
   - `ReferralPolicy` + AppServiceProvider registration
   - Confirm `PolicyCoverageTest` sweep passes
4. **Phase 4 — Supabase admin service**
   - `SupabaseAdminService::createPasswordlessUser` + `deleteUser`
   - `FakeSupabaseAdminService` test double
   - **Deployment step:** in Supabase dashboard for projects `glncumufgaqcmqhzwrxm` (dev) and `edplucmvkcnokyygxqsb` (prod), set Auth → Magic Link → expiry to 168 hours (matches `shell_ttl_days = 7`). Note this in the runbook.
5. **Phase 5 — Public signup path**
   - `BlockUpdateRequest` extension (HTML strip on `referral_signup` settings)
   - `PublicReferralSignupRequest` (FormRequest)
   - `PublicReferralSignupController` (thin) + `HandleAvailabilityController`
   - `ReferralSignupService` (orchestration)
   - `SendReferralMagicLinkJob`
   - Routes + throttle definitions in `RouteServiceProvider`
   - Endpoint tests (happy path + every error code)
6. **Phase 6 — Verification path**
   - Hook `RecordReferralVerificationOnUserVerified` to existing `UserEmailVerified` event
   - `ReferralAttributionService::recordVerification`
   - `WelcomeReferredUserNotification`
   - `ReferralVerifiedNotification`
   - Verification listener tests
7. **Phase 7 — Console commands**
   - `referrals:prune-expired` (+ scheduled)
   - `referrals:repair-pending` (+ scheduled)
   - `referrals:cleanup-orphan-auth-users` (+ scheduled)
   - `referrals:backfill-state` (on-demand)
   - `referrals:show {id}` (on-demand)
   - Command tests
8. **Phase 8 — Authenticated dashboard endpoints**
   - `UserReferralController` (index + summary)
   - `ReferralQueryService`
   - `ReferralResource` (handle, status, dates, amount — no email)
   - Endpoint tests (authz, pagination, PII leakage)
9. **Phase 9 — Capability + config**
   - `AccountCapabilities::individualCapabilities()` updates
   - `config/partna.php` `referral` section
   - Capability gate tests on every dispatcher
10. **Phase 10 — Future-billing stub**
    - `RecordReferralCreditOnFirstMonthPaid` (no-op listener bound to future event)
    - `ReferralCreditedNotification` class (unwired)
    - `ReferralAttributionService::recordFirstMonthPaid` (real impl, just no caller yet)
11. **Phase 11 — Security & observability**
    - XSS roundtrip test
    - Enumeration tests
    - Nightwatch breadcrumb instrumentation
    - PII leakage sweep tests
12. **Phase 12 — Post-merge dev verification**
    - Manual checklist (§11.5)
    - Update operator docs at `docs/referrals/` (runbook for the cleanup + repair commands)

## 13. Open questions

Billing has returned (`docs/superpowers/specs/2026-09-02-stripe-subscriptions-design.md`),
so items 2, 4 and 5 are **now answered** — see §15. Two remain.

1. **Cutoff policy for `eligible_pending_billing` referrals.** Is there a max age beyond
   which a verified-pending referral can no longer be credited? Options: no cutoff /
   N months from referral creation / N months from billing-launch. **Still open** — but
   note it is now bounded in practice by R3's per-referrer cap, so it is a fairness
   question rather than a liability one.
2. ~~Refund-window reversal.~~ **Answered — R2 (§15).**
3. **Notification batching on billing-launch day.** The first settlement pass could fire
   many `ReferralCreditedNotification`s at once. Still needs a "settlement mode" toggle.
   **Still open**, and now concrete: it fires the first time the settle half is switched
   on after billing stage 3.
4. ~~Default subscription price.~~ **Answered — R4 (§15).**
5. ~~Cash-out vs invoice credit.~~ **Answered — R1 (§15).**

## 14. Relationship to the billing plan

This program ships as its **own plan**, not as a stage of the subscriptions plan. The
two halves have different dependencies and only one of them waits:

| Half | Needs billing? | Notes |
|---|---|---|
| **Track** — §5–§9: the block, inline signup, provisioning, attribution, the state machine as far as `eligible_pending_billing` | **No** | Shippable independently, today |
| **Settle** — `eligible_pending_billing → credited`, and R2's reversal | **Yes** | Needs a first payment to be possible, i.e. billing **stage 3**. Stage 4 (enforcement) is NOT a prerequisite |

The billing design owes this one three named things (its §10.2): `grantAccountCredit()`
public, `SubscriptionFirstPaymentSucceeded` emitted on the first invoice, and the R2
clawback triggers in its webhook lane. Nothing else in that design is load-bearing here.

⚠️ **Settlement calls `grantAccountCredit()`, NOT `grantFreeMonths()`.** They are
different operations (billing spec D18). A trial extension is meaningless for a referrer
who has been paying for a year, which is precisely the profile that accumulates rewards;
a Stripe customer balance credit works in every account state and is real monetary
value. Routing settlement through the comp method would silently no-op for the accounts
that earn the most.

## 15. Settlement decisions (2026-09-03, owner)

Taken once billing returned. These close §13 items 2, 4 and 5.

| # | Decision | Rationale |
|---|---|---|
| **R1** | Reward is a **Stripe customer balance credit**, not a trial extension and not cash. A payout lane is **designed for, not built** | Real monetary value, state-independent, and needs no Connect — which is the explicit non-goal of both this spec and the billing one. The ledger already stores an amount owed, so Connect can be added later without touching attribution. **Accepted cost:** an influencer who is not a paying Partna user gets nothing until that lane exists |
| **R2** | **Claw back** if the referred user refunds or churns inside their first billing period (`credited → voided`) | Removes the profit from the cheapest exploit — pay one month, take the reward, refund. Uses the `recordChurn()` stub and the `credited → voided` transition this design already has; the trigger comes from the billing webhook lane |
| **R3** | **Per-referrer cap** in config, generous default | Turns an unbounded liability into a bounded one and gives a dial to turn down without a deploy. R2 is what lets the cap stay high enough that no honest referrer notices it — the two defend different failures |
| **R4** | Reward denominated in **N months × the referrer's tier price**, resolved at settlement | Replaces the frozen `default_credit_amount_cents = 2000`, which decouples from reality the first time a price moves. N is configurable, default 1 |
| **R5** | Ships as a **separate plan**; the track half is unblocked, the settle half waits on billing stage 3 | Settlement is downstream of a payment that cannot exist until stage 3, and bundling would enlarge the riskiest stage of the billing plan for no gain |

**Economics to keep in view.** At the default N=1, a referred user who pays one month
and churns nets **zero, minus Stripe's fee** (~1.75% + $0.30 domestic AU). That is a
deliberate customer-acquisition cost and it only pays back on retention past month one —
which is what R2 protects and R3 bounds. Raising `reward_months_per_referral` above 1
makes every referral negative until the referred user's (N+1)th month; it is a growth
lever with a real cost, not a free dial.

## 16. References

- `CLAUDE.md` — project rules (Supabase-only migrations, policies, capability gates, no Laravel migrations)
- `AI_CONTEXT.md` — domain model
- `docs/handle-redirects.md` — handle alias lifecycle (`reclaim_until`, `expires_at`)
- `app/Services/Accounts/AccountCapabilities.php` — capability source-of-truth
- `app/Http/Controllers/Api/PublicSite/BootstrapController.php` — existing account-creation funnel
- `partna-plan-check` and `account-capability-audit` skills — enforced patterns this design conforms to
