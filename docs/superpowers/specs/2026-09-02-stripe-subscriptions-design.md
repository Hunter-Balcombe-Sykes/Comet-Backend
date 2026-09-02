# Stripe Subscriptions — Backend Design

**Date:** 2026-09-02
**Status:** Design approved; **revised 2026-09-02** after a codebase verification pass — see §18. Ready for implementation plan
**Scope:** Partna backend (Laravel 12 + Supabase + PostgreSQL). Dashboard UI lives in the frontend repo and is called out where this design imposes obligations on it.
**Author:** Brainstorm session with Josh

---

## 1. Overview

Partna charges individual professionals a monthly subscription to keep their site
live. There are **two products, and they are the two account types**: `partna` and
`business`. There is **no free tier**.

An account gets its site for free while it is unclaimed (nobody owns it, nobody can
be billed). On claim, the owner gets **30 days free**, then must pay. If they do not,
the site goes dark and the dashboard becomes read-only until they do.

Billing was stripped from this codebase on 2026-05-22 along with all brand / affiliate
/ commerce code. Nothing of it survives: no `stripe/stripe-php`, no `billing` schema,
no Stripe columns on `core.users`. This design rebuilds the subscription half from
zero. It deliberately leaves room for Stripe Connect and payouts, which are planned
to return later (`AI_CONTEXT.md`: "reintegration planned post-pilot").

**Naming hazard.** Every existing "subscription" symbol in `app/` means *email*
subscription (`EmailSubscription`, `SubscriptionConfirmationMail`,
`SendSubscriptionConfirmationJob`, `notifications.email_subscriptions`). All new
billing code lives under the `App\Billing\` namespace and the `billing` schema so the
two never collide in a grep.

---

## 2. Goals

- Charge for both account types, with the type itself being the product
- Give every newly claimed account 30 free days without asking for a card
- Let staff comp longer free periods, and comp permanently
- Take a claimed account's site dark when it stops paying, reversibly
- Delete long-abandoned unpaid accounts and recycle their handles
- Keep the public read path free of any dependency on Stripe's availability
- Leave the door open for Connect / payouts without a second Stripe integration

## 3. Non-goals

- Stripe Connect, payouts, commissions (later, separate plan)
- The referral program (spec `2026-05-25-referral-program-design.md`, later — see §10.2)
- Annual billing (a second Stripe price, addable later with no schema change)
- Dashboard/frontend implementation (separate repo; obligations noted in §11)
- Tax handling beyond what Stripe computes

---

## 4. Locked decisions

| # | Decision | Rationale |
|---|----------|-----------|
| D1 | **Tier is `account_type`**, not a separate axis. No `plan_key` column | The two products *are* the two site types. `AccountCapabilities` already reads `account_type` as its only type input, so entitlement enforcement is largely already written |
| D2 | **No free tier** | Owner ruling |
| D3 | Unclaimed pre-account sites stay live and unbilled, indefinitely | They have no owner to bill. Preserves the pre-claim demo, which was ruled on twice (`ee1c22784` reverted 2026-08-25; publish gate carve-out 2026-09-01) |
| D4 | **Claim is free**; 30-day trial starts at claim | Lowest friction; claim flow needs no payment step and no frontend change |
| D5 | Unpaid ⇒ site dark (404) + `status='disabled'` + dashboard read-only | Reuses the publish gate shipped 2026-09-01 and the `EnforcePendingDeletionReadOnly` pattern |
| D6 | **The KV entry is never retired for a lapse** | Retiring it makes the Worker serve `unclaimedHtml()` — "this address is free" — inviting a stranger to claim a handle its owner still holds. KV is a routing pointer, not a visibility control |
| D7 | Stripe owns the trial clock; **we seed the anchor date** | No expiry cron of our own; but the date is decided locally at claim so there is no window where a claimed account reads as unpaid (§7) |
| D8 | **Stripe Elements**, all billing UI self-hosted | Owner ruling. Never leaves `partna.au`. PCI scope stays SAQ-A — card data goes browser→Stripe directly |
| D9 | **`stripe/stripe-php`, not Cashier** | Payouts/Connect are planned to return. Cashier does not do Connect, so under (A)+(B) it is a *second* integration carried alongside stripe-php forever. Also keeps subscription state as denormalised columns rather than a relation, preserving `AccountCapabilities` purity, and avoids two ledgers for one fact. Full reasoning in §5 |
| D10 | Plan switches take effect **immediately, prorated**, both directions | Owner ruling |
| D11 | On downgrade, Business-only content **keeps rendering; the WRITE seam closes** | **Corrected 2026-09-02** — the original ruling ("kept in the DB but stops rendering") depended on a render-time capability veto that was deleted from `presentPageIds` on 2026-09-01, over the exact capability this design would reuse, with *"none may be added back"* written at the site. The tier boundary lives on the writer instead. See §12.1 |
| D12 | Disabled accounts are **pruned after 90 days**, handle released | Mirrors `builds:prune-expired`; reuses `AccountDeletionService::purge()` |
| D13 | `past_due` stays **live**; disable on `unpaid` / `canceled` | Stripe's smart-retry window (~2–3 weeks) resolves most lapses, typically an expired card. Darkening a working business on the first failed charge and restoring it two days later is worse for everyone. Cost: a genuinely non-paying account stays live for the retry window |
| D14 | Currency **AUD**, monthly interval only for v1 | Matches the pre-strip billing design; annual is a second price later |
| D15 | Prices are **config**, per environment | Stripe test-mode and live-mode price IDs differ; they must not be hardcoded |
| D16 | A claimer **inherits the build's `account_type`**; it is not chosen at claim | `account_type` is stamped when the pre-account build is created (`config('partna.pre_account.sources')` is keyed by it) and §8.5 makes the webhook its only later writer. Keeps D4's promise that claim needs no payment step and no frontend change. Switching product is a billing action (`POST /api/billing/plan`), not a settings edit |
| D17 | `account_type` is **dropped, not rejected**, on `PATCH /api/me` | Closes the free-upgrade hole (§8.5) without 422-ing a stale dashboard that still posts the whole user object. Mirrors how `skeleton_id` is already handled |

---

## 5. SDK choice: `stripe/stripe-php`

Recorded because it was reconsidered mid-design and reversed.

The initial recommendation was Laravel Cashier, on the assumption that Partna was a
pure **(A)** case — the platform charging professionals. Cashier is genuinely strong
there: it handles proration on swap, trial→paid transitions, dunning and SCA, all of
which are easy to get subtly wrong.

The deciding fact is that Partna is **(A) + (B)**: subscriptions now, Connect and
payouts later. Cashier does not do Connect. Under (A)+(B) the choice is not
"Cashier or stripe-php" but "stripe-php" versus "Cashier *plus* stripe-php" — two
SDKs, two webhook lanes, two models of what a Stripe customer is, indefinitely.

Three supporting reasons:

1. **The entitlement seam wants denormalised state.** `AccountCapabilities::for()`
   is pure and zero-I/O; every input is a column already loaded on the user. Cashier
   puts subscription state in a relation. Projecting Stripe's state onto columns on
   `core.users` keeps the resolver pure — and with Cashier you would carry *both*
   Cashier's tables and the projected columns, i.e. two ledgers for one fact.
   `AccountCapabilitySet`'s own docblock anticipates this: *"Capabilities for
   commerce/payout/brand features were removed in the 2026-05-22 strip and will be
   re-added as named params here when reintegrated."*
2. **There is working prior art in git**, written to this repo's conventions
   (schema-qualified tables, UUID keys, Resource classes, Policies). Recover with
   `git show 8e97b9015^:app/Http/Controllers/Concerns/ValidatesStripeWebhookPayload.php`
   — it already handles v1-snapshot vs v2-thin event shapes, dedup, and Connect's
   `account_mismatch` guard. ~40 Stripe/billing files exist at that ref, including
   `CreatePaymentMethodSetupRequest` and `SyncPaymentMethodSessionRequest`, which are
   the Elements setup path this design needs.
3. **The no-Laravel-migrations rule stops being a problem** rather than a managed
   one. No publish step, no `database/migrations` collision with the composer guard,
   no renaming published migrations to fit a non-default schema.

**Accepted cost.** The strongest case for stripe-php pairs it with Stripe's hosted
Checkout + Billing Portal, which D8 rules out. With stripe-php **and** Elements we own
dunning handling, proration on swap, SCA orchestration and trial→paid transitions
ourselves. This is a chosen cost, partially offset by the prior art above.

---

## 6. Data model

### 6.1 Projection onto `core.users`

Additive columns. Keeps `AccountCapabilities` pure — the public read path never
queries a relation and never calls Stripe.

```
stripe_customer_id       text        NULL UNIQUE
plan_status              text        NULL   -- trialing|active|past_due|unpaid|canceled
plan_current_period_end  timestamptz NULL
plan_event_at            timestamptz NULL   -- ordering guard (§8.3)
comp_trial_days          int         NULL   -- NULL = config default (30)
billing_exempt           boolean     NOT NULL DEFAULT false
```

`status` needs **no** migration: `'disabled'` is already legal under
`users_status_check` (verified against `20260726000000_baseline_pilot.sql:1176` —
the CHECK admits `active|suspended|disabled|pending_deletion|unclaimed`).
`account_type` is the plan; there is no `plan_key` (D1).

**Which ledger is authoritative.** These columns and `billing.subscriptions` hold the
same fact twice — the duplication D9 criticises Cashier for. The split is deliberate
and the rule is one line: **`core.users` is the entitlement ledger, `billing.subscriptions`
is the audit ledger.** Every read that decides what a user may see or do
(`hasActiveSubscription()`, the publish gate, the middleware) reads the `core.users`
columns and nothing else — that is what keeps the public read path free of a join and
`AccountCapabilities` free of I/O. `billing.subscriptions` exists so Connect/payout
tables have something to join later and so a mirror drift is diagnosable; no
entitlement decision may read it. `billing:reconcile-subscriptions` (§7) is the only
code allowed to compare the two.

### 6.2 `billing` schema

Sized so Connect/payout tables can join it later without rework.

```sql
billing.subscriptions
  id uuid PK, user_id uuid → core.users ON DELETE CASCADE,
  stripe_subscription_id text UNIQUE, stripe_price_id text,
  stripe_status text, trial_ends_at timestamptz,
  current_period_end timestamptz, canceled_at timestamptz,
  created_at, updated_at

billing.webhook_events
  id uuid PK, stripe_event_id text UNIQUE, event_type text,
  payload jsonb, event_created_at timestamptz, processed_at timestamptz
```

Raw SQL in `supabase/migrations/`, one `CONCURRENTLY` statement per file, `app_backend`
GRANTs and RLS ported in the same migration per baseline conventions.

### 6.3 State machine

```
unclaimed ──claim──> trialing ──pays──> active ──────────────┐
 (no sub,            (30d or comp,      (paying)             │
  unbillable)         no card)             │ card fails      │
                         │ trial ends      │ dunning         │
                         │ no card         ▼                 │
                         │            past_due  (STAYS LIVE) │
                         ▼                 │ Stripe gives up │
                    unpaid / canceled  ◄───┘                 │
                         │                                   │
                         ▼                        pays / grantFreeMonths()
                  status = 'disabled'  ◄──────────────────────┘
                  site 404s, dashboard read-only except billing/GDPR
                         │ 90 days
                         ▼
                  prune: purge + handle released

billing_exempt = true ⇒ always in good standing, skips all of the above
```

`User::hasActiveSubscription()` — `billing_exempt || plan_status IN ('trialing','active','past_due')`.
Reads loaded columns only. No query, no network.

---

## 7. The claim → subscribe seam

**Problem.** If Stripe is called after commit and `plan_status` is briefly `NULL`,
every account reads as not-in-good-standing between claiming and Stripe responding —
darkening every site for an HTTP round-trip, and permanently if Stripe is down.

**Resolution — decide the trial locally, hand the date to Stripe.**

```
ClaimSiteService  (ONE transaction, row-locked, unchanged in shape)
  ├─ status                   = 'active'        (existing, line ~153)
  ├─ claimed_at               = now()           (existing)
  ├─ plan_status              = 'trialing'      NEW
  ├─ plan_current_period_end  = now() + (comp_trial_days ?? config default)
  └─ plan_event_at            = NULL            (no Stripe event yet)
        │ COMMIT — locks released, response returns
        ▼
  CreateStripeSubscriptionJob   ($afterCommit; Stripe idempotency key = user id)
    ├─ create Stripe customer
    ├─ create subscription with trial_end = plan_current_period_end   (OUR date)
    └─ write stripe_customer_id + billing.subscriptions row
        │
        ▼
  Stripe runs the clock from the date we chose
```

This refines D7 rather than reversing it: Stripe still fires every transition; we seed
the anchor instead of letting Stripe derive it from API response time.

- **No dark window** — the mirror is written in the claim transaction.
- **No third-party call inside the row lock** — claim latency is unchanged.
- **Stripe outage fails open** — the user still gets the free month they are owed.
  Exposure is bounded to 30 days of something already intended.
- **The tier is inherited, not chosen** (D16). `account_type` already carries the
  build's value before this transaction opens, and the claim writes nothing to it —
  §8.5's sole-writer rule is in force from the first moment a subscription exists.
  A claimer who was built as the wrong product switches via `POST /api/billing/plan`
  once claimed; there is no tier field in the claim payload. **Consequence to accept:**
  a `business` build that is claimed and never switched is billed as Business from the
  first invoice. Staff correct a miscategorised build before it is claimed, through the
  existing staff surface — not through a new public write path onto the tier column.

`plan_event_at = NULL` is deliberate: the ordering guard's `IS NULL` branch lets the
first genuine Stripe event through.

**Idempotency — two keys, and the column is the real guard.** The job makes *two*
Stripe calls, so it needs **two distinct keys**, namespaced per operation:
`partna:cust:{user_id}` and `partna:sub:{user_id}`. One key across both requests is not
a smaller version of this — Stripe rejects a key replayed with a different request body,
so the subscription call would fail outright against the customer call's key.

**Stripe idempotency keys expire after 24 hours**, which matters because the caller most
likely to retry is `billing:reconcile-subscriptions`, firing precisely when something has
been stuck for a while. Past 24h the key protects nothing and a second customer is
minted. The durable guard is therefore the **`stripe_customer_id IS NOT NULL` check plus
its UNIQUE constraint**, re-read inside the job; the Stripe key only covers the
same-hour timeout-and-retry case. A reconcile pass MUST NOT assume the key still holds.

**Reconciliation.** `billing:reconcile-subscriptions`, scheduled, finds claimed
accounts with `plan_status='trialing'` and `stripe_customer_id IS NULL` beyond a
threshold and re-dispatches; also repairs stale mirrors in the other direction.
Failures escalate via the `$reportScheduledFailure` convention in `routes/console.php`.

**Skipped entirely:** `billing_exempt` accounts (no Stripe objects at all) and
unclaimed provisional users (no owner to bill).

---

## 8. Webhook lane

### 8.1 Transport

One route in the existing `throttle:webhooks` group in `routes/api.php`, guarded by a
new `VerifyStripeWebhook` middleware sitting alongside `VerifyResendWebhookSignature`.
Same house pattern, same fail-closed posture: **503 when the signing secret is unset**.
Stripe signs with its own scheme (`Stripe-Signature`, HMAC + timestamp tolerance), so
the verifier is new code; the seam is not. Secret in `config/services.php` under a new
`stripe` block.

### 8.2 Flow

```
POST /api/internal/webhooks/stripe
  1. verify signature                       → bad: 400
  2. INSERT billing.webhook_events           → conflict: already seen, 200, stop
     (stripe_event_id UNIQUE)
  3. dispatch ProjectStripeEventJob          → 200 immediately (Stripe needs a fast ack)
  4. projection applies the ordering guard   → stale: 0 rows, logged "superseded"
```

### 8.3 The two guards (two different failures)

- `stripe_event_id UNIQUE` stops **exact replays**.
- `plan_event_at` stops **out-of-order delivery**. Stripe does not guarantee ordering,
  and step 3 queues the work, so jobs *will* run out of order under retry:

```sql
UPDATE core.users
   SET plan_status = :status, plan_current_period_end = :period_end,
       account_type = :type, plan_event_at = :event_created
 WHERE id = :id
   AND (plan_event_at IS NULL OR plan_event_at <= :event_created);
```

**The comparison is `<=`, not `<`, and that is load-bearing.** Stripe's `event.created`
has **one-second** resolution, and a first charge routinely emits
`customer.subscription.updated` and `invoice.payment_succeeded` inside the same second.
Under a strict `<` the second event to be processed matches zero rows and is discarded
as "superseded" — so a subscribe can project `trialing` and silently drop the `active`
that followed it, which is the precise state this design gates the whole site on.

`<=` is safe here only because the two guards are layered: exact replays are already
stopped by `stripe_event_id UNIQUE` at step 2, so `<=` cannot re-apply an event, it can
only let a genuine same-second sibling through. Within a single second the order is
last-writer-wins and undecidable — which is the correct trade, because dropping a real
transition is strictly worse than resolving a one-second tie arbitrarily. Anything
needing a stronger ordering than one second must not be inferred from `event.created`.

Without this, a late `customer.subscription.updated` arriving after a `deleted`
silently resurrects a cancelled plan into a live entitlement.

### 8.4 Events handled

| Event | Effect |
|---|---|
| `customer.subscription.created` / `.updated` | Project `plan_status`, `plan_current_period_end`, and `account_type` from the price. If the new status is `unpaid` or `canceled`, also set `status = 'disabled'`; if it returns to `trialing`/`active`/`past_due` from `disabled`, restore `status = 'active'`. Flush capability cache. Bust cache lanes if visibility changed |
| `customer.subscription.deleted` | `status = 'disabled'` |
| `customer.subscription.trial_will_end` | ~3 days out → "your free month is ending" notification |
| `invoice.payment_succeeded` | Restore good standing. On the **first**, emit `SubscriptionFirstPaymentSucceeded` (§10.2) |
| `invoice.payment_failed` | Dunning notification to the owner |
| `payment_method.attached` / `customer.updated` | Refresh `pm_type` / `pm_last_four` for the dashboard |

### 8.5 `account_type` is written here and nowhere else — but it is not today

A plan switch changes the Stripe price; the resulting webhook flips `account_type`.
The initiating controller never writes it — otherwise a declined card leaves an
account holding Business capabilities it is not paying for.

⚠️ **This is a statement of intent, not of current fact, and closing the gap is a
stage-4 deliverable — not a consequence of anything else in this design.** As shipped
today the tier column is owner-writable:

- `app/Models/Core/User/User.php` — `account_type` is in `$fillable`
- `app/Http/Requests/Api/User/UpdateUserRequest.php:31` —
  `'account_type' => ['sometimes', 'required', Rule::in([partna, business])]`

So `PATCH /api/me {"account_type":"business"}` is a **free upgrade**, granting
`can_use_multipage_site`, `can_book_storewide`, `google_business_full_sync` and
`workplace_brand_is_site_identity` for nothing. It is the exact failure this section
names as its own rationale, reachable by any authenticated user, and every other part
of this design assumes it is already impossible.

**The fix is one line, and it is deliberately not the obvious one.**

*Remove the `account_type` rule from `UpdateUserRequest` — do NOT remove it from
`$fillable`.* Un-filling the column looks like the stronger fix and is a trap: Eloquent
factories construct through `fill()`, so `UserFactory` (`database/factories/UserFactory.php:39`)
would silently drop the attribute and mint users with a NULL tier, taking
`users_account_type_check` with it across ~250 test files that seed `account_type`
directly. `UserBootstrapService`, `PreAccountBuildService`, `ShowcaseSeedCommand` and
`FleetPlacesCommand` all mass-assign it legitimately too. The request rule is the entire
owner-facing attack surface: `StaffUpdateUserRequest` does not accept the field at all,
and the remaining writers are signup and build-creation, both of which run before any
subscription exists.

Removed, not `prohibited` (D17): an old dashboard still posting the whole user object is
accepted and the field dropped, exactly as `skeleton_id` is handled on
`PATCH /api/professional/site`. A 422 would break a client that is merely stale, and the
security outcome is identical.

**Pin it.** An architecture test asserting no Form Request outside the pre-account /
bootstrap lane validates `account_type` — the rule is invisible at the call site and a
future "let users pick their plan in settings" PR reintroduces it in one line without
ever reading this spec.

---

## 9. Enforcement

### 9.1 Public visibility

One clause added to the publish gate, in both public read paths
(`IndividualProfileController::show`, `PublicIntegrationController::show`):

```php
'unpublished' => $site !== null && ! $pro->isUnclaimed()
    && (! $site->is_published || ! $pro->hasActiveSubscription()),
```

`is_published` remains **owner intent** and is never written by billing. Two reasons:
the owner could otherwise un-dark themselves through the normal UI, and on payment we
would not know whether to republish a site the owner had deliberately unpublished.

The unclaimed carve-out is untouched, so the demo fleet is unaffected (D3).
`PublishGateTest`'s mutation gate is extended to cover the new clause.

**This verdict rides the `handle.resolve` cache, and that has two consequences the
implementation must not discover at runtime.** The clause is computed inside the
resolve closure — deliberately, because computing it in `IndividualProfilePayloadBuilder`
would bake a 404 into the rotation-keyed payload cache, which is never deleted, only
abandoned. So:

1. **Enforcement fails OPEN on warm cache at rollout.** `IndividualProfileController`'s
   own docblock records that resolve entries written before a gate ships carry no key
   for it, and `?? false` reads that absence as "not gated". At the moment stage 4 is
   switched on, every warm entry therefore ignores billing standing for the remainder
   of its TTL. This is the right direction to fail — nobody's site goes dark by
   surprise — but it means **enforcement is not observable for the first TTL after
   the flag flips**, and a verification pass run inside that window will read as a
   false negative. Verify against a cold handle.
2. **Busting it is not what §9.4's three lanes do.** See §9.4 — this is the correction
   that matters most in this section.

### 9.2 Dashboard — `EnforceBillingStandingReadOnly`

Modelled on `EnforcePendingDeletionReadOnly`. Safe methods pass; writes get
**402 Payment Required** with a structured body the dashboard renders a reactivate
prompt from.

**It gates on billing standing, not on `status`.** The trigger is
`! $pro->hasActiveSubscription()`, deliberately **not** `status === 'disabled'`.
`'disabled'` is a general-purpose status a staff member may also set for moderation
reasons; an account disabled for moderation is often still paying, and must not be
shown a "pay to continue" prompt. Billing standing and moderation standing are
independent gates that happen to share a status value.

Two mandatory `withoutMiddleware()` exclusions:

- **The billing routes.** The deadlock that middleware's own docblock warns about: a
  disabled user who cannot reach the pay endpoint can never stop being disabled.
- **Account deletion and data export.** A GDPR right cannot be paywalled. Erasure and
  portability stay available regardless of billing standing.

### 9.3 Capabilities — a real layer for tier, an inert one for lapses

`AccountCapabilities` keeps its current shape and purity. `account_type` reaches it the
way any other column change does, so a tier flip genuinely moves `can_use_multipage_site`,
`can_book_storewide`, `google_business_full_sync` and `workplace_brand_is_site_identity` —
that is what makes §12.1's write seam enforceable.

**Do not, however, read this as a third enforcement layer for non-payment.** The
resolver does read `status`, but only into `can_be_reported` and
`receive_moderation_notifications`. Setting `status = 'disabled'` withdraws **no**
content capability whatsoever. All real lapse enforcement is exactly two things — the
publish-gate clause (§9.1) and `EnforceBillingStandingReadOnly` (§9.2) — and a plan that
counts capabilities as a third will under-test both.

`AccountCapabilities::flushCache()` is likewise weaker than it looks: it nulls a
**per-process** `WeakMap` keyed on the model instance. Called from the projection job it
clears a queue worker's memo and has no effect on any web process, which rehydrates the
user per request anyway. Keep the call (it is correct for the worker's own remaining
work) but do not count it as invalidation — the cross-process staleness that actually
matters is the payload and resolve caches, handled in §9.4.

### 9.4 Cache invalidation — the three lanes are necessary and NOT sufficient

The public payload is cached and CDN-fronted, so flipping `plan_status` changes nothing
a visitor sees until TTL expiry. Every visibility transition — into `disabled` **and
back out of it on payment** — must invalidate, in a **post-commit** context:

```php
SiteCacheLanes::bust([$site->id]);                        // lanes 1-3 (note: array of IDs)
app(SiteCacheService::class)->invalidateSitePayload($site); // lane 4 — REQUIRED, see below
```

**Corrected 2026-09-02.** The original text prescribed `SiteCacheLanes::bust($site)`
alone and claimed it covered the restore-on-payment case. It does not, and the failure
is silent in the worst direction — an account that has just paid stays dark.

`SiteCacheLanes::bust()` (`app/Site/Documents/SiteCacheLanes.php`) does three things:
`BuildState::bump()`, a **raw** `DB::connection('pgsql')->table('site.sites')->update([...])`,
and a Cloudflare purge **delayed 15 s** (`EDGE_PURGE_DELAY_SECONDS`). Two problems:

- **The signature is `bust(array $siteIds)`**, not `bust($site)`.
- **The gate's verdict is not in any lane it touches.** §9.1's clause lives in the
  `handle.resolve:{handle}` entry, not the payload. Lane 2 rolls the *payload* key —
  but the request 404s at the resolve stage and never reaches it. And because lane 2
  is a **raw query-builder update, `SiteObserver` never fires** (CLAUDE.md's own rule:
  a write that bypasses Eloquent invalidates nothing), so
  `SiteCacheService::invalidateSitePayload()` — the only code that deletes
  `handle.resolve` and raises `handle.resolve.floor` — never runs.

So `invalidateSitePayload($site)` is not belt-and-braces here; it is the only lane that
clears the gate. It must be called **post-commit**: its own docblock states that
`raiseResolveFloor` inside an open transaction publishes the post-write key before the
data is visible, letting a racing reader cache pre-commit state under the authoritative
key for the full payload TTL plus its stale window.

Two residual caveats to state rather than discover. The resolve key is derived from
`users.handle_lc` but busted by `site.subdomain`, so a **diverged** row is never busted
(0 of 268 on dev as of 2026-09-01, repaired by `ConvergeSiteSubdomains`, not by a
constraint). And lane 3's 15 s delay means the edge still serves a dark render for up
to that long after payment — acceptable, but "someone who has just paid must not wait
out a TTL" is true of the origin and approximately true of the edge, not exactly.

---

## 10. Comping and the referral hook

### 10.1 `grantFreeMonths(User $user, int $months, string $reason): void`

A public service method — not a private helper — because §10.2 reuses it. Three
branches by account state:

| State | Implementation |
|---|---|
| No subscription yet (pre-claim / provisional) | Write `comp_trial_days`; read at subscription creation |
| `trialing` | Update the Stripe subscription's `trial_end` += N months |
| `active` / paying | Stripe customer balance credit of N × price, auto-applied to the next invoice |

`$reason` is persisted so there is an audit trail of who received free time and why.
Exposed as a staff endpoint inside the `require.aal2` group, which already carries
`RecordStaffAuditEntry`. `billing_exempt` is a separate toggle for permanent comps.

### 10.2 Referral program — deferred, with two obligations

The referral program is out of scope (its own spec exists and is approved but
unimplemented: `docs/superpowers/specs/2026-05-25-referral-program-design.md`). That
spec was written in a *"track now, settle later"* posture expecting billing to return,
and states its settlement step should become *"a one-line `EventServiceProvider`
change"*. Its earn-trigger is **"verify email + pay first month."**

This design therefore owes it exactly two things:

1. `grantFreeMonths()` is public (§10.1) — the referral reward is the same call from a
   different trigger.
2. `SubscriptionFirstPaymentSucceeded` is emitted from the webhook lane on the first
   successful invoice (§8.4).

Explicitly **not** done now: no `referred_by_user_id` column. Attribution only matters
once referral links exist, and none will until that plan ships. YAGNI.

---

## 11. API surface

All behind `supabase.jwt` + `LoadCurrentUser`, all excluded from
`EnforceBillingStandingReadOnly`, all in a dedicated throttle bucket.

| Endpoint | Purpose |
|---|---|
| `GET /api/billing/state` | Plan, status, trial end, card last-4, next invoice date |
| `POST /api/billing/setup-intent` | Returns the `client_secret` for the Elements card field |
| `POST /api/billing/subscribe` | Attach payment method + activate. **Three-state return** |
| `POST /api/billing/plan` | Swap `partna` ↔ `business`, prorated |
| `DELETE /api/billing/subscription` | Cancel at period end — the month is already paid for |
| `POST /api/billing/subscription/resume` | Undo a pending cancellation |
| `GET /api/billing/invoices` | Invoice list + hosted PDF links, proxied from Stripe, short cache |

The Stripe **publishable** key joins the Google Maps key on the existing authenticated
`GET /api/config/integrations` (`routes/api/user.php`). The secret key never leaves the
server and is never returned by any endpoint.

**Frontend obligation.** `POST /api/billing/subscribe` returns one of `succeeded`,
`requires_action` (with a payment-intent client secret), or `failed`. The middle state
is SCA/3DS and in Australia it is the normal path, not an edge case. A frontend that
treats it as failure will report working cards as declined.

---

## 12. Interactions with existing rules

### 12.1 The tier boundary is a WRITE seam. No render veto. (Corrected 2026-09-02)

**The original ruling here has been reversed.** It read: *"capabilities derived from the
paid tier DO gate presentation"* — content retained but hidden while downgraded — and
justified itself as a narrow departure from a rule written for capabilities that swing
by *accident of data*. That justification does not survive contact with the file it
cites, and the mechanism it assumed no longer exists.

**What is actually in the code, as of 2026-09-01 — one day before this spec was
written:**

- `SitepageId::BUSINESS_ONLY` (`= ['menu','reviews']`, gated on `can_use_multipage_site`)
  and its successor `PAGE_CAPABILITY` were **both deleted**. Only
  `STANDARD_ONLY = ['listen']` remains, and it gates *against* Business, not for it.
  There is no constant left to hang a tier veto on.
- `SitepageDataResolverService::presentPageIds` carries, at the exact line the veto
  occupied: **`// NO capability veto stands here, and none may be added back.`** — and
  the comment names `! $caps->can_use_multipage_site` over `SitepageId::BUSINESS_ONLY`
  as the first of the two vetoes it is prohibiting. That is precisely the capability
  and precisely the constant a tier veto would reuse.
- `SitepageId.php` records why: ollies, a Google-sourced cafe filed
  `account_type=partna`, shipped 105 ingested menu items in its public payload with no
  page to render them. The rule the veto broke is stated as *"A page that exists but is
  silently dropped at render is the failure mode that produced 'my Menu page disappeared
  and nothing told me why'."*

The accident-vs-decision distinction was a reasonable argument, but it points the wrong
way here. **This design makes `account_type` strictly more volatile than `sector` ever
was**: §8.5 hands sole write authority to an asynchronous third-party webhook, delivered
out of order, behind a same-second ordering guard, over a network that fails. A render
veto on that column re-creates the retired incident with a *worse* trigger — a page
vanishing because a Stripe event was late.

**The ruling (D11, owner decision 2026-09-02): gate the writer, leave the renderer
alone.**

| | Behaviour |
|---|---|
| `presentPageIds` | **Unchanged.** No tier clause is added. The prohibition stands |
| Business-only write paths | Return **403** when the capability is false |
| On downgrade | Existing pages keep rendering; no content is hidden, moved or deleted |
| On re-upgrade | Writes unlock; nothing to restore, because nothing was withdrawn |

**Accepted cost, stated plainly:** one month of Business buys a multipage site that
keeps rendering after the downgrade. The tier boundary constrains what an account may
*build*, not what it may *keep*. That is the same trade the codebase already made for
Menu, and it is the cheaper mistake — the alternative silently unpublishes work the
owner can see in their dashboard and cannot explain.

Note this only ever bites a **paying** account swapping Business → Partna. An account
that stops paying has its whole site 404'd by §9.1 regardless, so D11 never governs the
non-payment case.

**Implementation note.** The seam already exists and has a house shape: an inline
capability check at the top of the mutating controller method, returning
`$this->error('…', 403)` — see `MenuController` (three sites), `MenuContentController`,
`SquareController` and `FreshaController::disconnect`. Use that, **not**
`abort_unless(..., 403)`, which `tests/Feature/Architecture/InlineAuthBypassGuardTest`
rejects. The distinction is documented at `MenuController`: a capability 403 is a
role/restriction gate and is deliberately separate from the ownership gate that follows
it. `tests/Feature/Platforms/SectorCapabilityGatingTest` is the pattern to extend.

### 12.2 `past_due` is treated as good standing

`hasActiveSubscription()` includes `past_due` (D13). This is deliberate: it is Stripe's
"card failed, still retrying" state, and its retry window resolves most lapses.

---

## 13. Launch migration

Every account claimed before this ships was claimed under a free regime.

`billing:enroll-existing` — run **once**, at stage 2 — enrols existing claimed accounts
with a trial ending **30 days from launch date, NOT from their original claim date**.

Anchoring to original claim date would retroactively expire the entire existing user
base the moment enforcement is switched on. This is the single most damaging thing to
get wrong on deploy day.

`billing_exempt` and unclaimed accounts are skipped.

---

## 14. Shipping order

Each stage is safe in isolation. Stages 1–3 run the whole system in "observe" mode:
subscriptions are created, webhooks project state, cards can be entered — and nothing
anywhere reads `hasActiveSubscription()` to make a decision.

| # | Ships | Blast radius |
|---|---|---|
| 1 | Schema, config, `stripe/stripe-php`, webhook lane | **None** — nothing reads it. Verify signature, dedup and the ordering guard against Stripe test mode + `stripe listen` |
| 2 | Claim seam, reconciliation, `billing:enroll-existing` | **None visible** — everyone becomes `trialing`; nothing gates on it |
| 3 | Billing API + Elements frontend | **None visible** — people *can* pay; not paying still costs nothing |
| 4 | **Enforcement**: publish-gate clause, middleware, cache lanes + `invalidateSitePayload` (§9.4), the `account_type` closure (§8.5), Business-only write 403s (§12.1) | ⚠️ **The dangerous one.** Behind `config('partna.billing.enforcement_enabled')` so it can be killed without a redeploy or rollback. **Two items here are NOT covered by that flag and must ship regardless:** the §8.5 request-rule removal (an open free-upgrade hole today, independent of billing) and §12.1's write 403s (a capability gate, not an enforcement gate). **Verify against a cold handle** — §9.1 fails open for the first resolve TTL after the flag flips, so a warm-cache check reads as a false negative |
| 5 | Prune job | Low — the first real deletion is 90 days after stage 4 regardless |

---

## 15. Retention and pruning

`billing:prune-disabled`, daily, following `routes/console.php` conventions
(`withoutOverlapping`, `onFailure($reportScheduledFailure(...))`):

```
disabled 60 days → warning email
disabled 83 days → final warning (7 days out)
disabled 90 days → AccountDeletionService::purge()
                   → handle released, KV retired, audit links nulled
billing_exempt   → skipped entirely
```

It reuses `purge()` rather than introducing a second deletion route. That path's
historical blocker — append-only audit rows rejecting the FK `SET NULL` cascade — was
fixed on **both** deletion paths in July 2026 and rehearsed against the live dev
schema. Introducing a third route is precisely the mistake that left
`builds:prune-expired` failing nightly (Nightwatch #308).

Note `Customer::redact()` carries a known-open `23502` defect, but `purge()` does not
route through it, so this path is unaffected. That defect remains separate work.

---

## 16. Testing

- **Stripe payloads are recorded fixtures** in `tests/fixtures/recorded/` with
  `MANIFEST.json` rows, never hand-typed (`RecordedFixtureManifestGuardTest`).
- **Out-of-order replay test is mandatory** — deliver `subscription.updated` after
  `subscription.deleted` and assert the cancelled state survives.
- **Duplicate-delivery test** — same `stripe_event_id` twice, assert one projection.
- **Postgres, not just SQLite.** The new columns and the conditional `UPDATE ... WHERE
  plan_event_at <= :x` need verifying against real DDL; a green `composer test` says
  nothing about a NOT NULL or CHECK mismatch.
- **Publish-gate mutation gate** extended for the subscription clause.
- **Deadlock regression test** — assert a `disabled` account can still reach the
  billing endpoints, the deletion endpoint and the export endpoint.
- **Cache-lane test** — assert all three lanes fire on both the darken and the restore
  transition (`PoolCacheLaneSeamTest` is the pattern), **and separately that
  `handle.resolve:{handle}` is deleted and its floor raised** (§9.4). The lane assertions
  pass without this; a test that only checks the three lanes reproduces the exact bug
  this spec shipped with. The restore direction is the one that matters — assert a paid
  account is served, not merely that a cache key moved.
- **Same-second event test** (§8.3) — deliver `customer.subscription.updated` and
  `invoice.payment_succeeded` bearing an **identical** `event.created`, assert both
  project. This is the test that fails under the original `<` guard, and no other test
  in this list catches it.
- **Free-upgrade regression** (§8.5) — `PATCH /api/me {"account_type":"business"}` as a
  `partna` account, assert 2xx **and** that `account_type` is unchanged. Plus the
  architecture test pinning that no Form Request outside the pre-account/bootstrap lane
  validates `account_type`.
- **Downgrade retains presentation** (§12.1) — flip a site with Business-only pages to
  `partna`, assert `presentPageIds` output is **unchanged** and the corresponding write
  endpoints now 403. The negative half is the point: it is what stops a future PR
  reintroducing the render veto.
- **`status='disabled'` withdraws no capability** (§9.3) — a deliberately inverted
  assertion, so nobody later mistakes capabilities for a third enforcement layer.

---

## 17. Open items (owner input, not design gaps)

- **Price amounts** for `partna` and `business`. Config values, set at launch; they do
  not affect any structure in this design.
- **Stripe account / product setup** — create the two products and their AUD monthly
  prices in test mode, then live, and record the price IDs per environment.

---

## 18. Correction log

This spec was reviewed against the codebase on 2026-09-02. Every claim below was a
premise the design rested on that did not hold; each is now corrected in place.

| § | Was | Now |
|---|---|---|
| D11 / §12.1 | Paid tier gates presentation | **Reversed.** Write-seam only — the render veto was deleted 2026-09-01 with *"none may be added back"* at the site, and `account_type` under this design is more volatile than the column that caused the original incident |
| §8.5 | "`account_type` written here and nowhere else" | **Not true today.** `UpdateUserRequest:31` accepts it — an open free upgrade. Closed by removing the request rule, *not* by un-filling the column (250-test blast radius via factory `fill()`) |
| §9.4 | `SiteCacheLanes::bust($site)` covers it | **It does not.** Wrong signature, and the gate's verdict lives in `handle.resolve`, which `bust()` never touches — its lane-2 raw update bypasses `SiteObserver`. Paid accounts would stay dark |
| §8.3 | `plan_event_at < :event_created` | **`<=`.** `event.created` is second-resolution; a first charge emits two events in one second and the strict `<` silently drops the second |
| §7 | One idempotency key (the user id) | **Two keys**, namespaced per operation; Stripe rejects a key replayed with a different body. Keys expire at 24 h, so `stripe_customer_id` is the durable guard |
| §9.3 | "already reads `status`, so `'disabled'` flows through" | True but **inert** — `status` reaches only two moderation flags. Capabilities are not a third enforcement layer for lapses; `flushCache()` is per-process |
| §9.1 | (unstated) | Enforcement **fails open for one resolve TTL** after the stage-4 flag flips. Verify cold |
| §6.1 | (unstated) | `core.users` is the **entitlement** ledger; `billing.subscriptions` is the **audit** ledger and no entitlement read may touch it |
| §7 / D16 | (unstated) | A claimer **inherits** the build's tier; there is no tier field in the claim payload |

**Verified and unchanged:** `users_status_check` already admits `'disabled'`; the prior
art at `8e97b9015^` is real; the `throttle:webhooks` group and the
`VerifyResendWebhookSignature` pattern exist as described; §9.2's GDPR/deletion
`withoutMiddleware` exclusions are already the house pattern (`routes/api/user.php:342-345`);
no `subscription` symbol exists on the `User` model, so §1's naming hazard is correctly
scoped; and composer/`config/services.php` carry no Stripe residue.
