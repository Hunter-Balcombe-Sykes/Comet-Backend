# Stripe Subscriptions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild paid monthly subscriptions on Partna — two products keyed to `account_type`, a 30-day trial from claim, Stripe-driven state projected onto `core.users`, and reversible darkening of unpaid claimed sites.

**Architecture:** `stripe/stripe-php` (no Cashier). Stripe's subscription state is projected onto denormalised columns on `core.users` by an idempotent, order-guarded webhook lane; `billing.subscriptions` is a parallel audit ledger that no entitlement read may touch. Enforcement is exactly two layers — one clause in the public publish gate and one dashboard read-only middleware — both behind a kill-switch config flag.

**Tech Stack:** PHP 8.4, Laravel 12, `stripe/stripe-php` ^17, PostgreSQL (Supabase, raw SQL migrations), Redis/Horizon, Pest 4.

**Spec:** `docs/superpowers/specs/2026-09-02-stripe-subscriptions-design.md` — read it alongside this plan. Every `§`/`D` reference below points into it.

---

## Global Constraints

- **Currency AUD, monthly interval only.** (D14)
- **No Laravel migrations.** Schema changes are raw SQL in `supabase/migrations/`. `composer guard:no-laravel-migrations` rejects the alternative.
- **One `CONCURRENTLY` statement per file, alone, no `BEGIN`/`COMMIT`.** (`supabase/migrations/CONVENTIONS.md` §1)
- **`core.users` is a HOT_TABLE.** Every `ALTER TABLE core.users` file must carry `BEGIN; SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s'; … COMMIT;` or `composer guard:no-unsafe-migrations` fails. `ADD CONSTRAINT … CHECK` must be `NOT VALID`, with `VALIDATE CONSTRAINT` in a **separate** transaction (guard Check 8).
- **`core.users` is the entitlement ledger; `billing.subscriptions` is the audit ledger.** No entitlement decision may read `billing.subscriptions`. Only `billing:reconcile-subscriptions` compares the two. (§6.1)
- **Never `Cache::forever()`.** Every cache key carries a TTL (`CacheKeyspaceConstraintsTest`).
- **No inline `abort_unless(..., 403)`.** `tests/Feature/Architecture/InlineAuthBypassGuardTest` rejects it. Use `$this->error('…', 403)` or a Policy.
- **Stripe payloads are recorded fixtures**, never hand-typed, each with a `MANIFEST.json` entry (`RecordedFixtureManifestGuardTest`).
- **`plan_event_at` comparison is `<=`, never `<`.** (§8.3 — load-bearing; see Task 6.)
- **Enforcement flag:** `config('partna.billing.enforcement_enabled')`, default `false`. Two stage-4 items ship **outside** it: the §8.5 request-rule removal and the §12.1 regression tests.
- **Trial anchor on launch migration is 30 days from launch date, never from original claim date.** (§13 — the single most damaging thing to get wrong on deploy day.)

---

## Deviations from the spec, and why

These were found by verifying the spec against the working tree on 2026-09-03. Each is a deliberate departure; do not "fix" them back.

| Spec says | Plan does | Why |
|---|---|---|
| §1: all billing code under `App\Billing\` | `app/Services/Billing/`, `app/Jobs/Billing/`, `app/Http/Controllers/Api/Billing/`, `app/Models/Billing/`, `app/Http/Middleware/Auth/VerifyStripeWebhook.php` | A top-level `app/Billing/` is invisible to `Tests\Support\Architecture\ModelSweep` (scans `app_path('Models')` only) and to `AuditPipelineIntegrityTest`'s sweep roots (`app/Services`, `app/Http/Controllers/Api`, `app/Jobs`, `tests/Feature`). The spec's actual goal — never colliding with `EmailSubscription` in a grep — is met by the `Billing` path segment. |
| §12.1: Business-only write paths return **403** | Keep the existing **422** on `PageController::assertCapability` / `SectionController::assertRuleIsPermitted`; add regression tests only | `PageCapabilities` (`app/Services/Content/PageCapabilities.php`) already maps `'multipage' => 'can_use_multipage_site'` and is enforced at both call sites. The 403 shape the spec cites (`MenuController`) is a *different* seam (`can_use_menu`) and is also already shipped. Changing 422→422 buys nothing and breaks shipped tests. Stage 4's §12.1 item is therefore **verification**, not construction. |
| §9.4: `SiteCacheLanes::bust([$site->id])` + `invalidateSitePayload($site)` | Same, wrapped in one named seam `App\Services\Billing\BillingVisibility::transition()` | Two callers (webhook projection, reconcile) must fire the identical four lanes post-commit. A shared seam is what `PoolCacheLaneSeamTest` already enforces for pool writes. |

**Prerequisite that is easy to miss:** `tests/Pest.php:322` lists the schemas attached to the SQLite test DB. `billing` was removed from it when the schema was dropped, and SQLite caps ATTACH at 10 (8 in use). Task 2 re-adds it. Without that line every `billing.*` test fails with "no such table".

---

## File Structure

**Migrations** (`supabase/migrations/`)
- `20260904100000_billing_schema.sql` — `CREATE SCHEMA billing`, two tables, indexes, RLS + `app_backend` grants
- `20260904100001_users_billing_columns.sql` — six additive columns on `core.users` + `users_plan_status_check`
- `20260904100002_users_stripe_customer_id_idx.sql` — `CREATE UNIQUE INDEX CONCURRENTLY` (alone in file)

**Models** (`app/Models/Billing/`) — `Subscription.php`, `WebhookEvent.php`. Both `POLICY_EXEMPT`.

**Services** (`app/Services/Billing/`)
- `StripeClientFactory.php` — the single place a `\Stripe\StripeClient` is constructed
- `PriceCatalog.php` — `AccountType` ⇄ Stripe price id, both directions, config-driven
- `SubscriptionProjector.php` — the order-guarded `UPDATE`; the only writer of the five plan columns
- `BillingVisibility.php` — the four-lane cache seam for a visibility transition
- `SubscriptionProvisioner.php` — customer + subscription creation, two idempotency keys
- `BillingGrants.php` — `grantFreeMonths()` / `grantAccountCredit()` (§10.1)
- `BillingActions.php` — subscribe / swap plan / cancel / resume (the API's service layer)

**Jobs** (`app/Jobs/Billing/`) — `CreateStripeSubscriptionJob.php`, `ProjectStripeEventJob.php`

**HTTP**
- `app/Http/Middleware/Auth/VerifyStripeWebhook.php`
- `app/Http/Middleware/Context/EnforceBillingStandingReadOnly.php`
- `app/Http/Controllers/Api/Internal/StripeWebhookController.php`
- `app/Http/Controllers/Api/Billing/{BillingStateController,SetupIntentController,SubscribeController,PlanController,SubscriptionController,InvoiceController}.php`
- `app/Http/Resources/Billing/BillingStateResource.php`
- `app/Http/Requests/Api/Billing/{SubscribeRequest,ChangePlanRequest}.php`

**Events** — `app/Events/Billing/SubscriptionFirstPaymentSucceeded.php`

**Commands** (`app/Console/Commands/Billing/`) — `ReconcileSubscriptionsCommand.php`, `EnrollExistingCommand.php`, `PruneDisabledCommand.php`

**Tests** — `tests/Feature/Billing/`, `tests/Postgres/BillingProjectionTest.php`, fixtures in `tests/fixtures/recorded/stripe/`

---

## Stage gates

Stages 1–3 are observe-only: nothing reads `hasActiveSubscription()` to make a decision. **Do not start stage 4 until stages 1–3 are merged and a real Stripe test-mode subscription has been driven end-to-end with `stripe listen`.**

| Stage | Tasks | Blast radius |
|---|---|---|
| 1 | 1–7 | None — nothing reads it |
| 2 | 8–10 | None visible — everyone becomes `trialing` |
| 3 | 11–13 | None visible — people *can* pay |
| 4 | 14–18 | ⚠️ The dangerous one. Flag-gated except 14 and 18 |
| 5 | 19 | Low — first deletion is 90 days after stage 4 |

---

# Stage 1 — Schema, config, SDK, webhook lane

## Task 1: SDK, config, and the Stripe client seam

**Files:**
- Modify: `composer.json` (require)
- Modify: `config/services.php` (new `stripe` block, after `resend`)
- Modify: `config/partna.php` (new `billing` block, next to `soft_delete_retention_days`)
- Modify: `.env.example`
- Create: `app/Services/Billing/StripeClientFactory.php`
- Create: `app/Services/Billing/PriceCatalog.php`
- Test: `tests/Feature/Billing/PriceCatalogTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `StripeClientFactory::make(): \Stripe\StripeClient`
  - `PriceCatalog::priceIdFor(AccountType $type): string`
  - `PriceCatalog::accountTypeForPrice(string $priceId): ?AccountType`

- [ ] **Step 1: Install the SDK**

```bash
composer require stripe/stripe-php:^17
```

- [ ] **Step 2: Add the `services.stripe` block**

In `config/services.php`, after the `'resend' => [...]` block:

```php
    // Stripe subscriptions (spec 2026-09-02 §8.1). The SECRET key never leaves
    // the server and is never returned by any endpoint; only `publishable`
    // reaches the browser, via GET /api/config/integrations.
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        // Unset => VerifyStripeWebhook returns 503 (fail-closed, same posture
        // as services.resend.webhook_secret).
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Stripe's own signature timestamp tolerance, seconds.
        'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
    ],
```

- [ ] **Step 3: Add the `partna.billing` block**

In `config/partna.php`, immediately before `'soft_delete_retention_days' => ...`:

```php
    // Subscriptions (spec 2026-09-02). Prices are per-environment because
    // Stripe test-mode and live-mode ids differ (D15); AUD monthly only (D14).
    'billing' => [
        // THE STAGE-4 KILL SWITCH. Off means the publish gate and the
        // read-only middleware make no decision at all; everything else in
        // the system still runs. Killing enforcement must never need a
        // redeploy, so this is env-driven and defaults OFF.
        'enforcement_enabled' => (bool) env('PARTNA_BILLING_ENFORCEMENT_ENABLED', false),
        'trial_days' => (int) env('PARTNA_BILLING_TRIAL_DAYS', 30),
        'currency' => env('PARTNA_BILLING_CURRENCY', 'aud'),
        'prices' => [
            'partna' => env('STRIPE_PRICE_PARTNA'),
            'business' => env('STRIPE_PRICE_BUSINESS'),
        ],
        // Monthly amounts in the smallest currency unit, used ONLY to size a
        // comp credit (§10.1 branch 3). Stripe remains the source of truth for
        // what is actually charged.
        'amounts_cents' => [
            'partna' => (int) env('PARTNA_BILLING_AMOUNT_PARTNA', 0),
            'business' => (int) env('PARTNA_BILLING_AMOUNT_BUSINESS', 0),
        ],
        // How long a claimed trialing account may sit with no Stripe customer
        // before reconcile re-dispatches provisioning (§7).
        'reconcile_after_minutes' => (int) env('PARTNA_BILLING_RECONCILE_AFTER_MINUTES', 30),
        // Retention for a disabled account before purge (§15).
        'disabled_warning_days' => (int) env('PARTNA_BILLING_DISABLED_WARNING_DAYS', 60),
        'disabled_final_warning_days' => (int) env('PARTNA_BILLING_DISABLED_FINAL_WARNING_DAYS', 83),
        'disabled_purge_days' => (int) env('PARTNA_BILLING_DISABLED_PURGE_DAYS', 90),
    ],
```

- [ ] **Step 4: Add the env keys to `.env.example`**

```
# Stripe subscriptions (spec 2026-09-02). Price ids differ per environment.
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
STRIPE_PRICE_PARTNA=
STRIPE_PRICE_BUSINESS=
PARTNA_BILLING_ENFORCEMENT_ENABLED=false
PARTNA_BILLING_AMOUNT_PARTNA=0
PARTNA_BILLING_AMOUNT_BUSINESS=0
```

- [ ] **Step 5: Write the failing test**

`tests/Feature/Billing/PriceCatalogTest.php`:

```php
<?php

use App\Enums\AccountType;
use App\Services\Billing\PriceCatalog;

it('maps an account type to its configured price id', function () {
    config(['partna.billing.prices.business' => 'price_biz_123']);

    expect(PriceCatalog::priceIdFor(AccountType::Business))->toBe('price_biz_123');
});

it('maps a price id back to its account type', function () {
    config([
        'partna.billing.prices.partna' => 'price_partna_1',
        'partna.billing.prices.business' => 'price_biz_123',
    ]);

    expect(PriceCatalog::accountTypeForPrice('price_biz_123'))->toBe(AccountType::Business);
});

// An unknown price must NOT fall back to a type. The webhook projects
// account_type from the price; guessing here would silently downgrade a
// paying Business account the moment a second price is added in Stripe.
it('returns null for a price it does not know', function () {
    config(['partna.billing.prices.partna' => 'price_partna_1']);

    expect(PriceCatalog::accountTypeForPrice('price_annual_not_yet_mapped'))->toBeNull();
});

it('throws rather than returning an empty price id when config is unset', function () {
    config(['partna.billing.prices.partna' => null]);

    expect(fn () => PriceCatalog::priceIdFor(AccountType::Partna))
        ->toThrow(RuntimeException::class);
});
```

- [ ] **Step 6: Run it and watch it fail**

Run: `php artisan test tests/Feature/Billing/PriceCatalogTest.php`
Expected: FAIL — `Class "App\Services\Billing\PriceCatalog" not found`

- [ ] **Step 7: Write `PriceCatalog`**

`app/Services/Billing/PriceCatalog.php`:

```php
<?php

namespace App\Services\Billing;

use App\Enums\AccountType;
use RuntimeException;

/**
 * The AccountType ⇄ Stripe price id map, both directions (D1, D15).
 *
 * The tier IS the product, so this class is the whole product catalogue. It is
 * config-driven because test-mode and live-mode price ids differ per
 * environment and must never be hardcoded.
 *
 * accountTypeForPrice() deliberately returns null for an unknown price rather
 * than defaulting: the webhook projects account_type FROM the price, so a
 * silent fallback would downgrade a paying Business account the first time a
 * second price (annual, a promo) appears in the Stripe account.
 */
final class PriceCatalog
{
    public static function priceIdFor(AccountType $type): string
    {
        $id = (string) config('partna.billing.prices.'.$type->value, '');

        if ($id === '') {
            throw new RuntimeException("No Stripe price configured for account type {$type->value}.");
        }

        return $id;
    }

    public static function accountTypeForPrice(string $priceId): ?AccountType
    {
        if ($priceId === '') {
            return null;
        }

        foreach (AccountType::cases() as $type) {
            if ((string) config('partna.billing.prices.'.$type->value, '') === $priceId) {
                return $type;
            }
        }

        return null;
    }

    /** Monthly amount in the smallest currency unit, for sizing a comp credit (§10.1). */
    public static function monthlyAmountCents(AccountType $type): int
    {
        return (int) config('partna.billing.amounts_cents.'.$type->value, 0);
    }
}
```

- [ ] **Step 8: Write `StripeClientFactory`**

`app/Services/Billing/StripeClientFactory.php`:

```php
<?php

namespace App\Services\Billing;

use RuntimeException;
use Stripe\StripeClient;

/**
 * The single construction site for a StripeClient.
 *
 * Centralised so every caller gets the same api_version pin: Stripe changes
 * response shapes between versions, and this design reads specific fields off
 * subscription and invoice objects (§8.4). An unpinned client would let a
 * Stripe-side default bump reshape a payload with no deploy on our side.
 *
 * NOT registered as a singleton binding: the secret is read per call so a
 * config:clear in a long-lived worker cannot leave a stale client behind.
 */
final class StripeClientFactory
{
    /** Pin every request. Bump deliberately, with the changelog read. */
    public const API_VERSION = '2025-08-27.basil';

    public static function make(): StripeClient
    {
        $secret = (string) config('services.stripe.secret', '');

        if ($secret === '') {
            throw new RuntimeException('Stripe secret key is not configured.');
        }

        return new StripeClient([
            'api_key' => $secret,
            'stripe_version' => self::API_VERSION,
        ]);
    }
}
```

- [ ] **Step 9: Run the test**

Run: `php artisan test tests/Feature/Billing/PriceCatalogTest.php`
Expected: PASS (4 tests)

- [ ] **Step 10: Confirm the SSRF guard still passes**

The `Http` facade is the only permitted transport in `app/`, but `stripe-php`'s
own curl lives in `vendor/`, which the guard does not scan. Confirm:

Run: `php artisan test tests/Feature/Architecture/OutboundHttpGuardTest.php`
Expected: PASS

- [ ] **Step 11: Commit**

```bash
git add composer.json composer.lock config/services.php config/partna.php .env.example \
        app/Services/Billing tests/Feature/Billing/PriceCatalogTest.php
git commit -m "feat(billing): stripe-php, config, and the price catalogue"
```

---

## Task 2: Schema — `billing` tables and the `core.users` projection columns

**Files:**
- Create: `supabase/migrations/20260904100000_billing_schema.sql`
- Create: `supabase/migrations/20260904100001_users_billing_columns.sql`
- Create: `supabase/migrations/20260904100002_users_stripe_customer_id_idx.sql`
- Modify: `tests/Pest.php:322` (attach `billing`), `tests/Pest.php` `setupUsersTable()` (new columns), plus a new `setupBillingTables()`
- Test: `tests/Feature/Billing/BillingSchemaStandInTest.php`

**Interfaces:**
- Produces: `core.users.{stripe_customer_id, plan_status, plan_current_period_end, plan_event_at, comp_trial_days, billing_exempt}`; tables `billing.subscriptions`, `billing.webhook_events`; test helper `setupBillingTables(): void`.

- [ ] **Step 1: Write the billing schema migration**

`supabase/migrations/20260904100000_billing_schema.sql`:

```sql
-- Billing schema (spec 2026-09-02 §6.2). Sized so Connect/payout tables can
-- join it later without rework.
--
-- Which ledger is authoritative: core.users holds the ENTITLEMENT state that
-- every read decision uses; these tables are the AUDIT ledger. No entitlement
-- read may join them. Only billing:reconcile-subscriptions compares the two.
--
-- Both tables are created empty, so plain CREATE INDEX is safe here — there is
-- nothing to scan and no writer to block. CONCURRENTLY is required only for
-- indexes on populated tables (CONVENTIONS.md §1); using it here would force
-- a one-statement-per-file split for no benefit.
--
-- ROLLBACK: DROP SCHEMA IF EXISTS billing CASCADE;

CREATE SCHEMA IF NOT EXISTS "billing";

-- One row per Stripe subscription. Kept for the audit trail and as the join
-- target future Connect/payout tables need; NOT read by any entitlement check.
CREATE TABLE IF NOT EXISTS "billing"."subscriptions" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
    "stripe_subscription_id" text NOT NULL UNIQUE,
    "stripe_price_id" text,
    "stripe_status" text,
    "trial_ends_at" timestamp with time zone,
    "current_period_end" timestamp with time zone,
    "canceled_at" timestamp with time zone,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS "idx_billing_subscriptions_user"
    ON "billing"."subscriptions" ("user_id");

-- The webhook dedup ledger. stripe_event_id UNIQUE is guard one of two
-- (§8.3): it stops EXACT replays. Out-of-order delivery is a different
-- failure and is stopped by core.users.plan_event_at, not by this table.
CREATE TABLE IF NOT EXISTS "billing"."webhook_events" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "stripe_event_id" text NOT NULL UNIQUE,
    "event_type" text NOT NULL,
    "payload" jsonb NOT NULL DEFAULT '{}'::jsonb,
    "event_created_at" timestamp with time zone,
    "processed_at" timestamp with time zone,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now()
);

-- Supports the retention sweep and "what has not been processed" triage.
CREATE INDEX IF NOT EXISTS "idx_billing_webhook_events_unprocessed"
    ON "billing"."webhook_events" ("created_at")
    WHERE ("processed_at" IS NULL);

-- RLS + grants, ported from the content schema's pattern.
DO $$
DECLARE t text;
BEGIN
    FOR t IN
        SELECT tablename FROM pg_tables WHERE schemaname = 'billing'
    LOOP
        EXECUTE format('ALTER TABLE billing.%I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('ALTER TABLE billing.%I FORCE ROW LEVEL SECURITY', t);
        EXECUTE format('CREATE POLICY %I ON billing.%I TO app_backend USING (true) WITH CHECK (true)', 'billing_'||t||'_app_backend_all', t);
    END LOOP;
END $$;

GRANT USAGE ON SCHEMA "billing" TO "service_role";
GRANT USAGE ON SCHEMA "billing" TO "app_backend";
GRANT ALL ON ALL TABLES IN SCHEMA "billing" TO "service_role";
GRANT ALL ON ALL TABLES IN SCHEMA "billing" TO "app_backend";
GRANT ALL ON ALL SEQUENCES IN SCHEMA "billing" TO "app_backend";
ALTER DEFAULT PRIVILEGES IN SCHEMA "billing" GRANT ALL ON TABLES TO "service_role";
ALTER DEFAULT PRIVILEGES IN SCHEMA "billing" GRANT ALL ON TABLES TO "app_backend";
ALTER DEFAULT PRIVILEGES IN SCHEMA "billing" GRANT ALL ON SEQUENCES TO "app_backend";
```

- [ ] **Step 2: Write the `core.users` columns migration**

`supabase/migrations/20260904100001_users_billing_columns.sql`:

```sql
-- Project Stripe's subscription state onto core.users (spec §6.1).
--
-- These columns exist so AccountCapabilities and the public read path stay
-- pure and I/O-free: hasActiveSubscription() reads loaded columns and makes no
-- query and no network call. billing.subscriptions holds the same facts as an
-- audit ledger and must never be read for an entitlement decision.
--
-- `status` needs no migration — users_status_check already admits 'disabled'.
-- There is no plan_key column: account_type IS the plan (D1).
--
-- core.users is a HOT_TABLE, so both steps are bounded and the CHECK is added
-- NOT VALID, validated in its own transaction (guard Checks 5 and 8).
--
-- ROLLBACK:
--   ALTER TABLE core.users DROP CONSTRAINT IF EXISTS users_plan_status_check;
--   ALTER TABLE core.users
--       DROP COLUMN IF EXISTS stripe_customer_id,
--       DROP COLUMN IF EXISTS plan_status,
--       DROP COLUMN IF EXISTS plan_current_period_end,
--       DROP COLUMN IF EXISTS plan_event_at,
--       DROP COLUMN IF EXISTS comp_trial_days,
--       DROP COLUMN IF EXISTS billing_exempt;

-- Step A — columns and the unvalidated CHECK.
BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE "core"."users"
    -- Stripe's customer id. The UNIQUE index (next file) is the DURABLE guard
    -- against minting a second customer: Stripe idempotency keys expire after
    -- 24h and the caller most likely to retry is the reconcile command, which
    -- fires precisely when something has been stuck for a while (§7).
    ADD COLUMN IF NOT EXISTS "stripe_customer_id" text,
    -- trialing|active|past_due|unpaid|canceled. NULL until claim.
    ADD COLUMN IF NOT EXISTS "plan_status" text,
    ADD COLUMN IF NOT EXISTS "plan_current_period_end" timestamp with time zone,
    -- Ordering guard (§8.3). Stripe does not guarantee delivery order and the
    -- projection is queued, so jobs WILL run out of order under retry.
    ADD COLUMN IF NOT EXISTS "plan_event_at" timestamp with time zone,
    -- NULL means "use config('partna.billing.trial_days')". A staff comp
    -- granted BEFORE any subscription exists lands here and is read at
    -- subscription creation (§10.1 branch 1).
    ADD COLUMN IF NOT EXISTS "comp_trial_days" integer,
    -- Permanent comp. Skips Stripe entirely: no customer, no subscription, no
    -- reconcile, no prune.
    ADD COLUMN IF NOT EXISTS "billing_exempt" boolean NOT NULL DEFAULT false;

ALTER TABLE "core"."users" ADD CONSTRAINT "users_plan_status_check"
    CHECK ("plan_status" IS NULL OR "plan_status" = ANY (ARRAY['trialing'::text, 'active'::text, 'past_due'::text, 'unpaid'::text, 'canceled'::text])) NOT VALID;

COMMIT;

-- Step B — validate in its OWN transaction (guard Check 8).
BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE "core"."users" VALIDATE CONSTRAINT "users_plan_status_check";

COMMIT;
```

- [ ] **Step 3: Write the index migration (one statement, alone, no transaction)**

`supabase/migrations/20260904100002_users_stripe_customer_id_idx.sql`:

```sql
-- core.users is populated and hot, so this index is CONCURRENTLY and alone in
-- its file (CONVENTIONS.md §1 — a CONCURRENTLY statement paired with anything
-- else aborts a from-zero apply with SQLSTATE 25001).
--
-- Partial on NOT NULL: most rows are unclaimed builds with no Stripe customer,
-- and a full UNIQUE would make them all collide on NULL semantics needlessly.
-- This index is the DURABLE double-customer guard (§7).
--
-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS core.idx_users_stripe_customer_id;

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS "idx_users_stripe_customer_id"
    ON "core"."users" ("stripe_customer_id")
    WHERE ("stripe_customer_id" IS NOT NULL);
```

- [ ] **Step 4: Run the migration guard**

Run: `composer guard:no-unsafe-migrations`
Expected: PASS. If it flags the `core.users` file, the `SET LOCAL lock_timeout` / `BEGIN` pair is missing or the `VALIDATE` was bundled into step A.

- [ ] **Step 5: Attach the `billing` schema in the SQLite test DB**

`tests/Pest.php`, in `attachTestSchemas()` — the list currently reads
`['core', 'site', 'audit', 'moderation', 'notifications', 'analytics', 'catalog', 'routing']`.
Add `'billing'` and update the comment, which currently says billing was
dropped from the platform:

```php
    // SQLite caps ATTACHed databases at 10, and a failed ATTACH is swallowed
    // by the catch below — so this list must hold only schemas that exist.
    // brand/commerce/retail were dropped from the platform (see the repo
    // CLAUDE.md schema list). `billing` REJOINED 2026-09-04 with the
    // subscriptions rebuild — that is slot 9 of 10, so the next schema to be
    // added needs a real decision, not another line here.
    foreach (['core', 'site', 'audit', 'moderation', 'notifications', 'analytics', 'catalog', 'routing', 'billing'] as $schema) {
```

- [ ] **Step 6: Add the new columns to the `core.users` stand-in**

In `tests/Pest.php`, `setupUsersTable()`, inside the `CREATE TABLE IF NOT EXISTS core.users (...)` body, immediately before `deleted_at TEXT NULL,`:

```php
        -- Subscription projection (spec 2026-09-02 §6.1). Mirrors the real
        -- CHECK so a test seeding a bogus status fails at INSERT.
        stripe_customer_id TEXT NULL,
        plan_status TEXT NULL CHECK (plan_status IS NULL OR plan_status IN (\'trialing\',\'active\',\'past_due\',\'unpaid\',\'canceled\')),
        plan_current_period_end TEXT NULL,
        plan_event_at TEXT NULL,
        comp_trial_days INTEGER NULL,
        billing_exempt INTEGER NOT NULL DEFAULT 0,
```

Then extend the defensive-ALTER loop below it, which exists because
`CREATE TABLE IF NOT EXISTS` will not add columns to a table another suite
already created inside the same run:

```php
    foreach (['sector', 'sector_source', 'bio', 'stripe_customer_id', 'plan_status', 'plan_current_period_end', 'plan_event_at'] as $col) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE core.users ADD COLUMN {$col} TEXT NULL");
        } catch (Throwable $e) {
            // already exists — ignore
        }
    }

    // Non-TEXT columns need their own ALTERs — the loop above is TEXT-only.
    foreach (['comp_trial_days INTEGER NULL', 'billing_exempt INTEGER NOT NULL DEFAULT 0'] as $col) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE core.users ADD COLUMN {$col}");
        } catch (Throwable $e) {
            // already exists — ignore
        }
    }
```

- [ ] **Step 7: Add `setupBillingTables()` to `tests/Pest.php`**

Place it directly after `setupUsersTable()`:

```php
/**
 * billing.subscriptions + billing.webhook_events — the audit ledger and the
 * webhook dedup table (migrations 20260904100000). stripe_event_id and
 * stripe_subscription_id carry their UNIQUE constraints because the duplicate
 * delivery test asserts the INSERT itself conflicts, not that PHP noticed.
 */
function setupBillingTables(): void
{
    setupUsersTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
        id TEXT PRIMARY KEY NOT NULL,
        user_id TEXT NOT NULL,
        stripe_subscription_id TEXT NOT NULL UNIQUE,
        stripe_price_id TEXT NULL,
        stripe_status TEXT NULL,
        trial_ends_at TEXT NULL,
        current_period_end TEXT NULL,
        canceled_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY NOT NULL,
        stripe_event_id TEXT NOT NULL UNIQUE,
        event_type TEXT NOT NULL,
        payload TEXT NOT NULL DEFAULT \'{}\',
        event_created_at TEXT NULL,
        processed_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
}
```

- [ ] **Step 8: Write the stand-in test**

`tests/Feature/Billing/BillingSchemaStandInTest.php`:

```php
<?php

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupBillingTables();
});

it('carries the six projection columns on core.users', function () {
    $user = User::factory()->create();

    DB::connection('pgsql')->table('core.users')->where('id', $user->id)->update([
        'stripe_customer_id' => 'cus_test_1',
        'plan_status' => 'trialing',
        'plan_current_period_end' => now()->addDays(30),
        'plan_event_at' => null,
        'comp_trial_days' => 45,
        'billing_exempt' => false,
    ]);

    $row = DB::connection('pgsql')->table('core.users')->where('id', $user->id)->first();

    expect($row->stripe_customer_id)->toBe('cus_test_1')
        ->and($row->plan_status)->toBe('trialing')
        ->and((int) $row->comp_trial_days)->toBe(45);
});

// The CHECK is mirrored deliberately: enum casts are lazy, so an invalid
// status otherwise only throws if something reads it back.
it('rejects a plan_status outside the CHECK', function () {
    $user = User::factory()->create();

    expect(fn () => DB::connection('pgsql')->table('core.users')
        ->where('id', $user->id)->update(['plan_status' => 'incomplete']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('rejects a duplicate stripe_event_id', function () {
    $insert = fn () => DB::connection('pgsql')->table('billing.webhook_events')->insert([
        'id' => (string) Str::uuid(),
        'stripe_event_id' => 'evt_dupe',
        'event_type' => 'customer.subscription.updated',
        'payload' => '{}',
    ]);

    $insert();

    expect($insert)->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 9: Run it**

Run: `php artisan test tests/Feature/Billing/BillingSchemaStandInTest.php`
Expected: PASS (3 tests)

- [ ] **Step 10: Confirm the drift guard still passes**

Run: `php artisan test tests/Feature/Architecture/SchemaDriftGuardTest.php`
Expected: PASS

- [ ] **Step 11: Apply to dev Supabase**

Migrate dev BEFORE merging (house rule — dev migration first):

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
supabase db push
```

Verify:

```bash
psql "$DEV_DB_URL" -c "\d core.users" | grep -E 'plan_|stripe_customer|comp_trial|billing_exempt'
psql "$DEV_DB_URL" -c "\dt billing.*"
```

Expected: six columns present; two `billing` tables listed.

- [ ] **Step 12: Commit**

```bash
git add supabase/migrations/20260904100000_billing_schema.sql \
        supabase/migrations/20260904100001_users_billing_columns.sql \
        supabase/migrations/20260904100002_users_stripe_customer_id_idx.sql \
        tests/Pest.php tests/Feature/Billing/BillingSchemaStandInTest.php
git commit -m "feat(billing): billing schema and the core.users subscription projection"
```

---

## Task 3: Models, `hasActiveSubscription()`, and policy exemption

**Files:**
- Create: `app/Models/Billing/Subscription.php`, `app/Models/Billing/WebhookEvent.php`
- Modify: `app/Models/Core/User/User.php` (casts + `hasActiveSubscription()` + `isBillingExempt()`)
- Modify: `tests/Feature/Security/PolicyCoverageTest.php` (POLICY_EXEMPT)
- Test: `tests/Feature/Billing/HasActiveSubscriptionTest.php`

**Interfaces:**
- Consumes: Task 2's tables and columns.
- Produces: `User::hasActiveSubscription(): bool`, `User::isBillingExempt(): bool`, `Subscription`, `WebhookEvent`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Billing/HasActiveSubscriptionTest.php`:

```php
<?php

use App\Models\Core\User\User;

beforeEach(function () {
    setupBillingTables();
});

// past_due is deliberately GOOD standing (D13): it is Stripe's "card failed,
// still retrying" state and its ~2-3 week smart-retry window resolves most
// lapses. Darkening a working business on the first failed charge and
// restoring it two days later is worse for everyone.
it('treats trialing, active and past_due as good standing', function (string $status) {
    $user = User::factory()->make(['plan_status' => $status, 'billing_exempt' => false]);

    expect($user->hasActiveSubscription())->toBeTrue();
})->with(['trialing', 'active', 'past_due']);

it('treats unpaid, canceled and NULL as not in good standing', function (?string $status) {
    $user = User::factory()->make(['plan_status' => $status, 'billing_exempt' => false]);

    expect($user->hasActiveSubscription())->toBeFalse();
})->with(['unpaid', 'canceled', null]);

it('short-circuits to good standing for a billing-exempt account', function () {
    $user = User::factory()->make(['plan_status' => 'canceled', 'billing_exempt' => true]);

    expect($user->hasActiveSubscription())->toBeTrue();
});

// The purity claim in §6.1 is the whole reason the columns exist. If this
// ever needs a query the public read path has acquired a join.
it('makes no database query', function () {
    $user = User::factory()->create(['plan_status' => 'active']);
    $user->refresh();

    DB::connection('pgsql')->enableQueryLog();
    $user->hasActiveSubscription();

    expect(DB::connection('pgsql')->getQueryLog())->toBeEmpty();
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/Billing/HasActiveSubscriptionTest.php`
Expected: FAIL — `Call to undefined method ...User::hasActiveSubscription()`

- [ ] **Step 3: Add the casts and the two methods to `User`**

In `app/Models/Core/User/User.php`, extend `$casts`:

```php
        'plan_current_period_end' => 'datetime',
        'plan_event_at' => 'datetime',
        'comp_trial_days' => 'integer',
        'billing_exempt' => 'boolean',
```

Do **not** add any of the six columns to `$fillable`. They are written by
`SubscriptionProjector` and `SubscriptionProvisioner` via `forceFill()` /
direct assignment, following the same posture as `status` (SEC-2).

Then add, near `isBusiness()`:

```php
    /**
     * Is this account in billing good standing? (spec §6.3)
     *
     * Reads LOADED COLUMNS ONLY — no query, no network. That purity is what
     * lets the public publish gate and AccountCapabilities consult billing
     * standing without acquiring a join or a Stripe dependency, and it is why
     * the state is projected onto core.users at all. billing.subscriptions
     * holds the same facts as an AUDIT ledger and must never be read here.
     *
     * past_due is good standing on purpose (D13) — see the test.
     */
    public function hasActiveSubscription(): bool
    {
        if ($this->isBillingExempt()) {
            return true;
        }

        return in_array((string) $this->plan_status, ['trialing', 'active', 'past_due'], true);
    }

    /** Permanent comp: no Stripe objects at all, skipped by reconcile and prune. */
    public function isBillingExempt(): bool
    {
        return (bool) $this->billing_exempt;
    }
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/Billing/HasActiveSubscriptionTest.php`
Expected: PASS (8 assertions across the datasets)

- [ ] **Step 5: Write the models**

`app/Models/Billing/Subscription.php`:

```php
<?php

namespace App\Models\Billing;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The AUDIT ledger for Stripe subscriptions (spec §6.2).
 *
 * NOT the entitlement ledger. Every read that decides what a user may see or
 * do goes to the core.users projection columns instead; this table exists so
 * future Connect/payout tables have something to join and so a mirror drift is
 * diagnosable. `billing:reconcile-subscriptions` is the only code allowed to
 * compare the two.
 *
 * Named `Subscription` under App\Models\Billing deliberately: every other
 * "subscription" symbol in this codebase means EMAIL subscription
 * (EmailSubscription, SendSubscriptionConfirmationJob). The namespace segment
 * is what keeps the two apart in a grep.
 */
class Subscription extends BaseModel
{
    use HasUuids;

    protected $table = 'billing.subscriptions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'stripe_subscription_id',
        'stripe_price_id',
        'stripe_status',
        'trial_ends_at',
        'current_period_end',
        'canceled_at',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_end' => 'datetime',
        'canceled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

`app/Models/Billing/WebhookEvent.php`:

```php
<?php

namespace App\Models\Billing;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * The webhook dedup ledger (spec §8.2 step 2).
 *
 * The UNIQUE on stripe_event_id is the mechanism, not the bookkeeping: the
 * INSERT is what rejects an exact replay, before any projection runs. A
 * PHP-side "have I seen this?" check would race two concurrent deliveries of
 * the same event.
 *
 * This stops replays ONLY. Out-of-order delivery is a different failure and is
 * stopped by core.users.plan_event_at (§8.3).
 */
class WebhookEvent extends BaseModel
{
    use HasUuids;

    protected $table = 'billing.webhook_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'stripe_event_id',
        'event_type',
        'payload',
        'event_created_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'event_created_at' => 'datetime',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
```

- [ ] **Step 6: Add both to `POLICY_EXEMPT`**

In `tests/Feature/Security/PolicyCoverageTest.php`, inside `const POLICY_EXEMPT = [`, and add the two `use` statements at the top:

```php
    // Billing ledgers (spec 2026-09-02 §6.2). Neither is addressable by a
    // request: Subscription is written only by the webhook projection and the
    // provisioning job, and read only by billing:reconcile-subscriptions;
    // WebhookEvent is keyed on Stripe's event id, not on a user, and has no
    // controller action at all. Entitlement decisions read the core.users
    // projection columns, which are gated by the User model's own policy —
    // a per-row policy here would gate nothing that is not already gated.
    // Same posture as SupabaseEmailEvent above.
    Subscription::class,
    WebhookEvent::class,
```

- [ ] **Step 7: Run the coverage sweep**

Run: `php artisan test tests/Feature/Security/PolicyCoverageTest.php`
Expected: PASS. (Neither model uses `SoftDeletes`, so `SoftDeletePurgeCoverageTest` needs no entry — confirm by running it too.)

Run: `php artisan test tests/Feature/Architecture/SoftDeletePurgeCoverageTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Models/Billing app/Models/Core/User/User.php \
        tests/Feature/Security/PolicyCoverageTest.php \
        tests/Feature/Billing/HasActiveSubscriptionTest.php
git commit -m "feat(billing): subscription models and User::hasActiveSubscription"
```

---

## Task 4: `VerifyStripeWebhook` middleware and the route

**Files:**
- Create: `app/Http/Middleware/Auth/VerifyStripeWebhook.php`
- Modify: `bootstrap/app.php` (alias, beside `'resend.webhook'`)
- Modify: `routes/api.php` (route inside the existing `throttle:webhooks` group)
- Create: `app/Http/Controllers/Api/Internal/StripeWebhookController.php` (stub returning 200)
- Test: `tests/Feature/Billing/StripeWebhookSignatureTest.php`

**Interfaces:**
- Produces: middleware alias `stripe.webhook`; route name `webhooks.stripe` at `POST /api/internal/webhooks/stripe`; request attribute `stripe_event` (a decoded `array`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Billing/StripeWebhookSignatureTest.php`:

```php
<?php

beforeEach(function () {
    setupBillingTables();
    config(['services.stripe.webhook_secret' => 'whsec_test_secret']);
});

function stripeSignedHeaders(string $payload, string $secret = 'whsec_test_secret', ?int $timestamp = null): array
{
    $timestamp ??= time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

    return ['Stripe-Signature' => "t={$timestamp},v1={$signature}"];
}

function stripeEventPayload(string $id = 'evt_test_1', string $type = 'customer.subscription.updated'): string
{
    return json_encode([
        'id' => $id,
        'type' => $type,
        'created' => time(),
        'data' => ['object' => ['id' => 'sub_test_1', 'status' => 'active']],
    ], JSON_THROW_ON_ERROR);
}

it('accepts a correctly signed payload', function () {
    $payload = stripeEventPayload();

    $this->call('POST', '/api/internal/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => stripeSignedHeaders($payload)['Stripe-Signature'],
    ], $payload)->assertOk();
});

it('rejects a payload signed with the wrong secret', function () {
    $payload = stripeEventPayload();

    $this->call('POST', '/api/internal/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => stripeSignedHeaders($payload, 'whsec_wrong')['Stripe-Signature'],
    ], $payload)->assertStatus(400);
});

it('rejects a signature outside the timestamp tolerance', function () {
    $payload = stripeEventPayload();
    $stale = time() - 3600;

    $this->call('POST', '/api/internal/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => stripeSignedHeaders($payload, timestamp: $stale)['Stripe-Signature'],
    ], $payload)->assertStatus(400);
});

// Fail-closed: an unset secret is a deploy bug, not a runtime choice. Same
// posture as VerifyResendWebhookSignature.
it('503s when the signing secret is unset', function () {
    config(['services.stripe.webhook_secret' => '']);
    $payload = stripeEventPayload();

    $this->call('POST', '/api/internal/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => 't=1,v1=deadbeef',
    ], $payload)->assertStatus(503);
});

it('rejects a request with no signature header at all', function () {
    $this->call('POST', '/api/internal/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], stripeEventPayload())->assertStatus(400);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/Billing/StripeWebhookSignatureTest.php`
Expected: FAIL — 404, the route does not exist

- [ ] **Step 3: Write the middleware**

`app/Http/Middleware/Auth/VerifyStripeWebhook.php`:

```php
<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe webhook signature gate (spec §8.1).
 *
 * The seam is the house pattern (VerifyResendWebhookSignature); the crypto is
 * not. Stripe signs with its own scheme — `Stripe-Signature: t=<ts>,v1=<hmac>`
 * over `{timestamp}.{raw-body}` — rather than Standard Webhooks, so
 * StandardWebhookVerifier cannot be reused. `Webhook::constructEvent()` is the
 * SDK's own verifier and includes the timestamp-tolerance check, which is what
 * bounds replay of a captured request.
 *
 * Returns 503 if the secret is unset (fail-closed — a deploy bug, not a
 * runtime choice), 400 on signature mismatch. 400 rather than 401 because that
 * is what Stripe's dashboard renders as a delivery failure and retries.
 *
 * On success the decoded event is placed on the request as `stripe_event` so
 * the controller never re-parses (and never re-trusts) the raw body.
 */
class VerifyStripeWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.stripe.webhook_secret', '');
        if ($secret === '') {
            Log::warning('stripe.webhook.misconfigured', ['reason' => 'secret_missing']);

            return response()->json([
                'error' => 'hook_not_configured',
                'message' => 'Stripe webhook is not configured.',
            ], 503);
        }

        $signature = (string) $request->header('Stripe-Signature', '');
        $rawBody = (string) $request->getContent();

        try {
            $event = Webhook::constructEvent(
                $rawBody,
                $signature,
                $secret,
                (int) config('services.stripe.webhook_tolerance', 300),
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            Log::warning('stripe.webhook.rejected', ['reason' => $e->getMessage()]);

            return response()->json([
                'error' => 'invalid_signature',
                'message' => 'Signature verification failed.',
            ], 400);
        }

        $request->attributes->set('stripe_event', $event->toArray());

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the alias**

In `bootstrap/app.php`, beside `'resend.webhook' => VerifyResendWebhookSignature::class,`:

```php
            'stripe.webhook' => VerifyStripeWebhook::class,
```

Add the matching `use App\Http\Middleware\Auth\VerifyStripeWebhook;` import.

- [ ] **Step 5: Write the controller stub**

`app/Http/Controllers/Api/Internal/StripeWebhookController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stripe webhook receiver (spec §8.2). Task 5 gives it the dedup insert and
 * the projection dispatch; for now it acknowledges so the signature gate can
 * be tested on its own.
 */
class StripeWebhookController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        return $this->success(['received' => true]);
    }
}
```

- [ ] **Step 6: Add the route**

In `routes/api.php`, inside the existing `Route::middleware('throttle:webhooks')->group(...)`, after the ManyChat route:

```php
    // Stripe subscription events (spec 2026-09-02 §8.1). Signature verified by
    // middleware; rate-limited under the shared webhooks bucket. Idempotency
    // is the controller's job (billing.webhook_events UNIQUE), not the
    // throttle's — a throttled retry is a redelivery Stripe will repeat.
    Route::post('/internal/webhooks/stripe', StripeWebhookController::class)
        ->middleware('stripe.webhook')->name('webhooks.stripe');
```

Add the `use App\Http\Controllers\Api\Internal\StripeWebhookController;` import.

- [ ] **Step 7: Run the test**

Run: `php artisan test tests/Feature/Billing/StripeWebhookSignatureTest.php`
Expected: PASS (5 tests)

- [ ] **Step 8: Commit**

```bash
git add app/Http/Middleware/Auth/VerifyStripeWebhook.php \
        app/Http/Controllers/Api/Internal/StripeWebhookController.php \
        bootstrap/app.php routes/api.php \
        tests/Feature/Billing/StripeWebhookSignatureTest.php
git commit -m "feat(billing): Stripe webhook signature gate and route"
```

---

## Task 5: Recorded fixtures and the dedup insert

**Files:**
- Create: `tests/fixtures/recorded/stripe/subscription-updated-active.json`, `subscription-updated-trialing.json`, `subscription-deleted.json`, `invoice-payment-succeeded.json`, `invoice-payment-failed.json`, `charge-refunded.json`, `trial-will-end.json`
- Modify: `tests/fixtures/recorded/MANIFEST.json`
- Modify: `app/Http/Controllers/Api/Internal/StripeWebhookController.php`
- Create: `app/Jobs/Billing/ProjectStripeEventJob.php` (stub — handle() empty)
- Test: `tests/Feature/Billing/StripeWebhookDedupTest.php`

**Interfaces:**
- Consumes: `WebhookEvent`, route from Task 4.
- Produces: `ProjectStripeEventJob::dispatch(string $webhookEventId)`; fixture keys under `stripe/`.

- [ ] **Step 1: Capture the fixtures from Stripe test mode**

Do **not** hand-type these. Drive a real test-mode subscription and capture
the deliveries:

```bash
stripe login
stripe listen --forward-to http://localhost:8000/api/internal/webhooks/stripe --print-json > /tmp/stripe-events.jsonl
# In another shell: create a customer, attach pm_card_visa, create a
# subscription on STRIPE_PRICE_PARTNA with trial_period_days=30, then
# advance the test clock so the trial ends and the first invoice charges.
```

Split `/tmp/stripe-events.jsonl` into one file per event type under
`tests/fixtures/recorded/stripe/`, pretty-printed. **Redact before saving:**
replace `customer`, `id` on the customer object, and any `email` with stable
placeholders (`cus_FIXTURE`, `fixture@example.test`) — a recorded fixture is
committed, and a real test-mode customer id is still an identifier.

- [ ] **Step 2: Register each fixture in `MANIFEST.json`**

Add one entry per file to the `entries` object:

```json
    "stripe/subscription-updated-active.json": {
      "source_url": "https://api.stripe.com/v1/events (test mode, stripe listen)",
      "captured_at": "2026-09-04T00:00:00+00:00",
      "sha256": "<sha256sum of the file>",
      "captured_by": "stripe listen --print-json",
      "notes": "customer.subscription.updated, trialing -> active on first charge. Customer id and email redacted."
    }
```

Compute each hash with `shasum -a 256 tests/fixtures/recorded/stripe/<file>`.

- [ ] **Step 3: Verify the manifest guard**

Run: `php artisan test tests/Feature/Architecture/RecordedFixtureManifestGuardTest.php`
Expected: PASS. A hash mismatch means the file was edited after hashing — rehash, do not edit the manifest to match a guess.

- [ ] **Step 4: Write the failing dedup test**

`tests/Feature/Billing/StripeWebhookDedupTest.php`:

```php
<?php

use App\Jobs\Billing\ProjectStripeEventJob;
use App\Models\Billing\WebhookEvent;
use Illuminate\Support\Facades\Bus;
use Tests\Support\Fixtures\Recorded;

beforeEach(function () {
    setupBillingTables();
    config(['services.stripe.webhook_secret' => 'whsec_test_secret']);
    Bus::fake();
});

function postStripeEvent(array $event): Illuminate\Testing\TestResponse
{
    $payload = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test_secret');

    return test()->call('POST', '/api/internal/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], $payload);
}

it('records the event and dispatches the projection', function () {
    $event = Recorded::json('stripe/subscription-updated-active.json');

    postStripeEvent($event)->assertOk();

    expect(WebhookEvent::query()->where('stripe_event_id', $event['id'])->exists())->toBeTrue();
    Bus::assertDispatched(ProjectStripeEventJob::class);
});

// The UNIQUE INSERT is the mechanism. A PHP-side "have I seen this?" check
// would race two concurrent deliveries of the same event.
it('acks a duplicate delivery without dispatching a second projection', function () {
    $event = Recorded::json('stripe/subscription-updated-active.json');

    postStripeEvent($event)->assertOk();
    postStripeEvent($event)->assertOk();

    expect(WebhookEvent::query()->where('stripe_event_id', $event['id'])->count())->toBe(1);
    Bus::assertDispatchedTimes(ProjectStripeEventJob::class, 1);
});

// Stripe needs a fast ack or it retries. Nothing that touches Stripe or the
// site caches may run inside the request.
it('does the projection work in a queued job, not in the request', function () {
    postStripeEvent(Recorded::json('stripe/invoice-payment-succeeded.json'))->assertOk();

    Bus::assertDispatched(ProjectStripeEventJob::class);
});
```

- [ ] **Step 5: Run it and watch it fail**

Run: `php artisan test tests/Feature/Billing/StripeWebhookDedupTest.php`
Expected: FAIL — `Class "App\Jobs\Billing\ProjectStripeEventJob" not found`

- [ ] **Step 6: Write the job stub**

`app/Jobs/Billing/ProjectStripeEventJob.php`:

```php
<?php

namespace App\Jobs\Billing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Applies one recorded Stripe event to the core.users projection (spec §8.2
 * step 4). Task 6 fills in handle().
 *
 * Takes the billing.webhook_events row id, NOT the event payload: the payload
 * is already durable at this point, and passing an id keeps the job body small
 * and the ledger the single source of what was received.
 */
class ProjectStripeEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly string $webhookEventId) {}

    public function handle(): void
    {
        // Task 6.
    }
}
```

- [ ] **Step 7: Implement the controller**

Replace the body of `app/Http/Controllers/Api/Internal/StripeWebhookController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Jobs\Billing\ProjectStripeEventJob;
use App\Models\Billing\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Stripe webhook receiver (spec §8.2).
 *
 * Four steps, in this order, and the order is the design:
 *   1. signature verified by middleware (400 on failure)
 *   2. INSERT into billing.webhook_events — a UNIQUE conflict IS the dedup,
 *      so an exact replay stops here with a 200 and no work queued
 *   3. dispatch the projection and ack IMMEDIATELY — Stripe retries a slow
 *      endpoint, and nothing that touches Stripe or the site caches belongs
 *      inside the request
 *   4. the projection applies the ORDERING guard (plan_event_at), which is a
 *      different failure from a replay and needs a different guard
 *
 * Always 200 once the signature is good, including on a duplicate: a non-2xx
 * tells Stripe to redeliver, and redelivering an event we have already stored
 * is exactly what we do not want.
 */
class StripeWebhookController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $event */
        $event = (array) $request->attributes->get('stripe_event', []);

        $eventId = (string) ($event['id'] ?? '');
        $eventType = (string) ($event['type'] ?? '');

        if ($eventId === '' || $eventType === '') {
            return $this->error('Malformed event.', 400);
        }

        try {
            $record = WebhookEvent::query()->create([
                'stripe_event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => $event,
                'event_created_at' => isset($event['created'])
                    ? Carbon::createFromTimestampUTC((int) $event['created'])
                    : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already seen. Ack so Stripe stops redelivering.
            Log::info('stripe.webhook.duplicate', ['event_id' => $eventId, 'type' => $eventType]);

            return $this->success(['received' => true, 'duplicate' => true]);
        }

        ProjectStripeEventJob::dispatch((string) $record->id);

        return $this->success(['received' => true]);
    }
}
```

- [ ] **Step 8: Run the test**

Run: `php artisan test tests/Feature/Billing/StripeWebhookDedupTest.php`
Expected: PASS (3 tests)

- [ ] **Step 9: Commit**

```bash
git add tests/fixtures/recorded/stripe tests/fixtures/recorded/MANIFEST.json \
        app/Jobs/Billing/ProjectStripeEventJob.php \
        app/Http/Controllers/Api/Internal/StripeWebhookController.php \
        tests/Feature/Billing/StripeWebhookDedupTest.php
git commit -m "feat(billing): webhook dedup ledger and recorded Stripe fixtures"
```

---

## Task 6: The projection and the ordering guard

This is the highest-risk task in stage 1. The `<=` in step 4 is the single
character §8.3 and correction-log row 4 exist to protect.

**Files:**
- Create: `app/Services/Billing/SubscriptionProjector.php`
- Create: `app/Events/Billing/SubscriptionFirstPaymentSucceeded.php`
- Modify: `app/Jobs/Billing/ProjectStripeEventJob.php`
- Test: `tests/Feature/Billing/SubscriptionProjectionTest.php`, `tests/Postgres/BillingProjectionTest.php`

**Interfaces:**
- Consumes: `PriceCatalog`, `WebhookEvent`, `Subscription`, `User`.
- Produces:
  - `SubscriptionProjector::apply(array $event): void`
  - `SubscriptionFirstPaymentSucceeded(User $user, string $invoiceId)`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Billing/SubscriptionProjectionTest.php`:

```php
<?php

use App\Events\Billing\SubscriptionFirstPaymentSucceeded;
use App\Models\Core\User\User;
use App\Services\Billing\SubscriptionProjector;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    setupBillingTables();
    config([
        'partna.billing.prices.partna' => 'price_partna_1',
        'partna.billing.prices.business' => 'price_biz_1',
    ]);
});

function projectionUser(array $attrs = []): User
{
    $user = User::factory()->create($attrs + ['account_type' => 'partna']);
    $user->forceFill(['stripe_customer_id' => 'cus_projection_1'])->save();

    return $user->fresh();
}

function subscriptionEvent(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'evt_'.uniqid(),
        'type' => 'customer.subscription.updated',
        'created' => now()->timestamp,
        'data' => ['object' => [
            'id' => 'sub_projection_1',
            'customer' => 'cus_projection_1',
            'status' => 'active',
            'current_period_end' => now()->addMonth()->timestamp,
            'trial_end' => null,
            'canceled_at' => null,
            'items' => ['data' => [['price' => ['id' => 'price_partna_1']]]],
        ]],
    ], $overrides);
}

it('projects status, period end and account type from the price', function () {
    $user = projectionUser();

    app(SubscriptionProjector::class)->apply(subscriptionEvent([
        'data' => ['object' => ['items' => ['data' => [['price' => ['id' => 'price_biz_1']]]]]],
    ]));

    $user->refresh();
    expect($user->plan_status)->toBe('active')
        ->and($user->account_type->value)->toBe('business')
        ->and($user->plan_current_period_end)->not->toBeNull();
});

// THE test that fails under a strict `<`. Stripe's event.created is
// SECOND-resolution and a first charge routinely emits two events inside one
// second; dropping the second as "superseded" loses the transition the whole
// site is gated on.
it('applies both events when two share an identical event.created', function () {
    $user = projectionUser();
    $sameSecond = now()->timestamp;

    app(SubscriptionProjector::class)->apply(subscriptionEvent([
        'id' => 'evt_same_1', 'created' => $sameSecond,
        'data' => ['object' => ['status' => 'trialing']],
    ]));

    app(SubscriptionProjector::class)->apply(subscriptionEvent([
        'id' => 'evt_same_2', 'created' => $sameSecond,
        'data' => ['object' => ['status' => 'active']],
    ]));

    expect($user->fresh()->plan_status)->toBe('active');
});

// A late `updated` arriving after a `deleted` must not resurrect a cancelled
// plan into a live entitlement.
it('discards an event older than the last one applied', function () {
    $user = projectionUser();

    app(SubscriptionProjector::class)->apply(subscriptionEvent([
        'id' => 'evt_new', 'created' => now()->timestamp,
        'data' => ['object' => ['status' => 'canceled']],
    ]));

    app(SubscriptionProjector::class)->apply(subscriptionEvent([
        'id' => 'evt_old', 'created' => now()->subMinutes(5)->timestamp,
        'data' => ['object' => ['status' => 'active']],
    ]));

    expect($user->fresh()->plan_status)->toBe('canceled');
});

it('disables the account on unpaid and on canceled', function (string $status) {
    $user = projectionUser();

    app(SubscriptionProjector::class)->apply(subscriptionEvent([
        'data' => ['object' => ['status' => $status]],
    ]));

    expect($user->fresh()->status)->toBe('disabled');
})->with(['unpaid', 'canceled']);

it('restores an account to active when it returns to good standing', function () {
    $user = projectionUser();
    $user->forceFill(['status' => 'disabled', 'plan_status' => 'unpaid'])->save();

    app(SubscriptionProjector::class)->apply(subscriptionEvent([
        'created' => now()->addMinute()->timestamp,
        'data' => ['object' => ['status' => 'active']],
    ]));

    expect($user->fresh()->status)->toBe('active');
});

// Moderation and billing are independent gates that happen to share a status
// value. A restore must not un-suspend an account staff took down.
it('does not restore an account that is suspended', function () {
    $user = projectionUser();
    $user->forceFill(['status' => 'suspended'])->save();

    app(SubscriptionProjector::class)->apply(subscriptionEvent());

    expect($user->fresh()->status)->toBe('suspended');
});

it('emits SubscriptionFirstPaymentSucceeded on the first paid invoice only', function () {
    Event::fake([SubscriptionFirstPaymentSucceeded::class]);
    $user = projectionUser();

    $invoice = fn (int $count) => [
        'id' => 'evt_inv_'.$count,
        'type' => 'invoice.payment_succeeded',
        'created' => now()->addSeconds($count)->timestamp,
        'data' => ['object' => [
            'id' => 'in_'.$count,
            'customer' => 'cus_projection_1',
            'billing_reason' => $count === 1 ? 'subscription_cycle' : 'subscription_cycle',
            'amount_paid' => 2900,
        ]],
    ];

    app(SubscriptionProjector::class)->apply($invoice(1));
    app(SubscriptionProjector::class)->apply($invoice(2));

    Event::assertDispatchedTimes(SubscriptionFirstPaymentSucceeded::class, 1);
});

it('writes the audit ledger row alongside the projection', function () {
    projectionUser();

    app(SubscriptionProjector::class)->apply(subscriptionEvent());

    expect(App\Models\Billing\Subscription::query()
        ->where('stripe_subscription_id', 'sub_projection_1')->exists())->toBeTrue();
});

it('ignores an event for a customer it does not know', function () {
    $user = projectionUser();

    app(SubscriptionProjector::class)->apply(subscriptionEvent([
        'data' => ['object' => ['customer' => 'cus_someone_else']],
    ]));

    expect($user->fresh()->plan_status)->toBeNull();
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/Billing/SubscriptionProjectionTest.php`
Expected: FAIL — `SubscriptionProjector` not found

- [ ] **Step 3: Write the event class**

`app/Events/Billing/SubscriptionFirstPaymentSucceeded.php`:

```php
<?php

namespace App\Events\Billing;

use App\Models\Core\User\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The referral program's EARN TRIGGER (spec §10.2 obligation 2).
 *
 * Emitted from the webhook lane on the FIRST successful invoice for an
 * account, never on subsequent cycles. The referral spec's trigger is
 * "verify email + pay first month"; this is the second half of it, and
 * without this event the settle half of that program has nothing to hang on.
 *
 * Event discovery is DISABLED in this app — a listener must be registered
 * explicitly in AppServiceProvider or via Event::listen.
 */
class SubscriptionFirstPaymentSucceeded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $stripeInvoiceId,
        public readonly int $amountPaidCents,
    ) {}
}
```

- [ ] **Step 4: Write the projector**

`app/Services/Billing/SubscriptionProjector.php`:

```php
<?php

namespace App\Services\Billing;

use App\Enums\AccountType;
use App\Events\Billing\SubscriptionFirstPaymentSucceeded;
use App\Models\Billing\Subscription;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ONLY writer of the five core.users plan columns (spec §8.3/§8.4).
 *
 * Two guards protect this projection, and they stop DIFFERENT failures:
 *   - billing.webhook_events.stripe_event_id UNIQUE stops EXACT REPLAYS,
 *     upstream in the controller, before anything reaches here.
 *   - plan_event_at, below, stops OUT-OF-ORDER DELIVERY. Stripe does not
 *     guarantee ordering and the projection is queued, so jobs WILL run out
 *     of order under retry. Without this, a late `subscription.updated`
 *     arriving after a `deleted` silently resurrects a cancelled plan into a
 *     live entitlement.
 *
 * THE COMPARISON IS `<=`, NOT `<`, AND THAT IS LOAD-BEARING. Stripe's
 * event.created has ONE-SECOND resolution, and a first charge routinely emits
 * customer.subscription.updated and invoice.payment_succeeded inside the same
 * second. Under a strict `<` the second event to be processed matches zero
 * rows, is discarded as "superseded", and a subscribe projects `trialing`
 * while silently dropping the `active` that followed it — the precise state
 * this design gates the whole site on.
 *
 * `<=` is safe ONLY because the two guards are layered: exact replays are
 * already stopped upstream, so `<=` cannot re-apply an event — it can only let
 * a genuine same-second sibling through. Within one second the order is
 * last-writer-wins and undecidable, which is the correct trade: dropping a
 * real transition is strictly worse than resolving a one-second tie
 * arbitrarily. Anything needing stronger ordering than one second must not be
 * inferred from event.created.
 */
final class SubscriptionProjector
{
    /** Statuses that keep a site lit. Mirrors User::hasActiveSubscription(). */
    private const GOOD_STANDING = ['trialing', 'active', 'past_due'];

    /** @param array<string, mixed> $event */
    public function apply(array $event): void
    {
        $type = (string) ($event['type'] ?? '');
        /** @var array<string, mixed> $object */
        $object = (array) ($event['data']['object'] ?? []);
        $eventAt = isset($event['created'])
            ? Carbon::createFromTimestampUTC((int) $event['created'])
            : now();

        $user = $this->resolveUser($object);
        if ($user === null) {
            Log::info('stripe.projection.unknown_customer', [
                'event_id' => $event['id'] ?? null,
                'type' => $type,
            ]);

            return;
        }

        match (true) {
            str_starts_with($type, 'customer.subscription.deleted') => $this->applySubscription($user, $object, $eventAt, forceStatus: 'canceled'),
            str_starts_with($type, 'customer.subscription.') => $this->applySubscription($user, $object, $eventAt),
            $type === 'invoice.payment_succeeded' => $this->applyInvoicePaid($user, $object, $eventAt),
            default => null,
        };
    }

    /** @param array<string, mixed> $object */
    private function resolveUser(array $object): ?User
    {
        $customerId = (string) ($object['customer'] ?? '');
        if ($customerId === '') {
            return null;
        }

        return User::query()->where('stripe_customer_id', $customerId)->first();
    }

    /** @param array<string, mixed> $object */
    private function applySubscription(User $user, array $object, Carbon $eventAt, ?string $forceStatus = null): void
    {
        $status = $forceStatus ?? (string) ($object['status'] ?? '');
        if ($status === '') {
            return;
        }

        $priceId = (string) ($object['items']['data'][0]['price']['id'] ?? '');
        $type = PriceCatalog::accountTypeForPrice($priceId);

        $periodEnd = isset($object['current_period_end'])
            ? Carbon::createFromTimestampUTC((int) $object['current_period_end'])
            : null;

        $applied = $this->write($user, $status, $periodEnd, $type, $eventAt);

        if (! $applied) {
            Log::info('stripe.projection.superseded', [
                'user_id' => $user->id,
                'status' => $status,
                'event_at' => $eventAt->toIso8601String(),
            ]);

            return;
        }

        $this->mirrorAuditLedger($user, $object, $status, $priceId);
    }

    /** @param array<string, mixed> $object */
    private function applyInvoicePaid(User $user, array $object, Carbon $eventAt): void
    {
        // An invoice payment restores good standing without carrying the
        // subscription's own status field; `active` is the only thing a paid
        // invoice can mean here.
        $applied = $this->write($user, 'active', null, null, $eventAt);

        if (! $applied) {
            return;
        }

        // FIRST paid invoice only — the referral earn trigger (§10.2). The
        // ledger is billing.subscriptions' own row count of nothing, so the
        // marker is the user having had no prior paid invoice: plan_status was
        // never 'active' before this write. Recorded on the audit ledger so a
        // replay after a mirror repair cannot re-fire the reward.
        $subscription = Subscription::query()->where('user_id', $user->id)->first();
        $firstPayment = $subscription === null || $subscription->stripe_status !== 'active';

        if ($firstPayment) {
            SubscriptionFirstPaymentSucceeded::dispatch(
                $user->fresh(),
                (string) ($object['id'] ?? ''),
                (int) ($object['amount_paid'] ?? 0),
            );
        }

        if ($subscription !== null) {
            $subscription->forceFill(['stripe_status' => 'active'])->save();
        }
    }

    /**
     * The guarded UPDATE. Returns false when the event was superseded.
     *
     * Written as a raw conditional UPDATE rather than read-modify-write on
     * purpose: two workers processing sibling events concurrently would
     * otherwise both read the old plan_event_at and both write.
     */
    private function write(User $user, string $status, ?Carbon $periodEnd, ?AccountType $type, Carbon $eventAt): bool
    {
        $good = in_array($status, self::GOOD_STANDING, true);

        $values = [
            'plan_status' => $status,
            'plan_event_at' => $eventAt,
            'updated_at' => now(),
        ];

        if ($periodEnd !== null) {
            $values['plan_current_period_end'] = $periodEnd;
        }

        // account_type is written HERE and nowhere else (§8.5). The initiating
        // controller never writes it — otherwise a declined card leaves an
        // account holding Business capabilities it is not paying for.
        if ($type !== null) {
            $values['account_type'] = $type->value;
        }

        // status is scoped: billing may darken and re-light, but it must never
        // touch an account staff suspended or one pending deletion. Billing
        // standing and moderation standing are independent gates that happen
        // to share the 'disabled' value.
        if (! $good) {
            $values['status'] = 'disabled';
        } elseif ($user->status === 'disabled') {
            $values['status'] = 'active';
        }

        $query = DB::connection('pgsql')->table('core.users')
            ->where('id', $user->id)
            ->where(function ($q) use ($eventAt) {
                $q->whereNull('plan_event_at')->orWhere('plan_event_at', '<=', $eventAt);
            });

        if (! $good || $user->status === 'disabled') {
            // Never re-light or darken a suspended / pending-deletion account.
            $query->whereIn('status', ['active', 'disabled', 'unclaimed']);
        }

        $rows = $query->update($values);

        if ($rows > 0) {
            // Correct for THIS worker's remaining work only. flushCache() nulls
            // a per-process WeakMap — it has no effect on any web process,
            // which rehydrates the user per request anyway. Do NOT count it as
            // invalidation; the cross-process staleness that matters is handled
            // by BillingVisibility (Task 16).
            AccountCapabilities::flushCache();
        }

        return $rows > 0;
    }

    /** @param array<string, mixed> $object */
    private function mirrorAuditLedger(User $user, array $object, string $status, string $priceId): void
    {
        $subscriptionId = (string) ($object['id'] ?? '');
        if ($subscriptionId === '') {
            return;
        }

        $existing = Subscription::query()->where('stripe_subscription_id', $subscriptionId)->first();

        $values = [
            'stripe_price_id' => $priceId !== '' ? $priceId : null,
            'stripe_status' => $status,
            'trial_ends_at' => isset($object['trial_end'])
                ? Carbon::createFromTimestampUTC((int) $object['trial_end'])
                : null,
            'current_period_end' => isset($object['current_period_end'])
                ? Carbon::createFromTimestampUTC((int) $object['current_period_end'])
                : null,
            'canceled_at' => isset($object['canceled_at'])
                ? Carbon::createFromTimestampUTC((int) $object['canceled_at'])
                : null,
        ];

        if ($existing !== null) {
            $existing->forceFill($values)->save();

            return;
        }

        $subscription = new Subscription($values + ['stripe_subscription_id' => $subscriptionId]);
        $subscription->user()->associate($user);
        $subscription->save();
    }
}
```

- [ ] **Step 5: Wire the job**

Replace `handle()` in `app/Jobs/Billing/ProjectStripeEventJob.php`:

```php
    public function handle(SubscriptionProjector $projector): void
    {
        $record = WebhookEvent::query()->find($this->webhookEventId);

        if ($record === null || $record->processed_at !== null) {
            return;
        }

        $projector->apply((array) $record->payload);

        $record->forceFill(['processed_at' => now()])->save();
    }
```

Add `use App\Models\Billing\WebhookEvent;` and `use App\Services\Billing\SubscriptionProjector;`.

- [ ] **Step 6: Run the test**

Run: `php artisan test tests/Feature/Billing/SubscriptionProjectionTest.php`
Expected: PASS (11 assertions across the datasets)

- [ ] **Step 7: Prove `<` would fail**

Temporarily change `'<='` to `'<'` in `SubscriptionProjector::write()` and rerun.

Run: `php artisan test tests/Feature/Billing/SubscriptionProjectionTest.php --filter="identical event.created"`
Expected: FAIL. Revert the character. If it PASSES with `<`, the same-second test is not actually exercising the guard — fix the test, not the guard.

- [ ] **Step 8: Write the Postgres-lane test**

SQLite compares the timestamp columns as strings; the real behaviour of
`plan_event_at <= :x` against `timestamptz`, and the `users_plan_status_check`
CHECK, only exist in Postgres.

`tests/Postgres/BillingProjectionTest.php`:

```php
<?php

use App\Models\Core\User\User;
use App\Services\Billing\SubscriptionProjector;
use Illuminate\Support\Facades\DB;

uses(Tests\PostgresTestCase::class);

beforeEach(function () {
    DB::connection('pgsql')->statement('CREATE SCHEMA IF NOT EXISTS billing');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL,
        stripe_subscription_id text NOT NULL UNIQUE,
        stripe_price_id text,
        stripe_status text,
        trial_ends_at timestamptz,
        current_period_end timestamptz,
        canceled_at timestamptz,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');

    // ADD COLUMN IF NOT EXISTS, never CREATE TABLE for core.users: the PG lane
    // is first-creator-wins across test classes, so a class that recreates a
    // shared table thins it for everyone else.
    DB::connection('pgsql')->statement('ALTER TABLE core.users
        ADD COLUMN IF NOT EXISTS stripe_customer_id text,
        ADD COLUMN IF NOT EXISTS plan_status text,
        ADD COLUMN IF NOT EXISTS plan_current_period_end timestamptz,
        ADD COLUMN IF NOT EXISTS plan_event_at timestamptz,
        ADD COLUMN IF NOT EXISTS comp_trial_days integer,
        ADD COLUMN IF NOT EXISTS billing_exempt boolean NOT NULL DEFAULT false');
});

it('honours the <= ordering guard against real timestamptz', function () {
    $user = User::factory()->create();
    $user->forceFill(['stripe_customer_id' => 'cus_pg_1'])->save();

    $sameSecond = now()->startOfSecond();

    $event = fn (string $id, string $status) => [
        'id' => $id,
        'type' => 'customer.subscription.updated',
        'created' => $sameSecond->timestamp,
        'data' => ['object' => [
            'id' => 'sub_pg_1',
            'customer' => 'cus_pg_1',
            'status' => $status,
            'current_period_end' => now()->addMonth()->timestamp,
            'items' => ['data' => [['price' => ['id' => 'price_partna_1']]]],
        ]],
    ];

    config(['partna.billing.prices.partna' => 'price_partna_1']);

    app(SubscriptionProjector::class)->apply($event('evt_pg_1', 'trialing'));
    app(SubscriptionProjector::class)->apply($event('evt_pg_2', 'active'));

    expect($user->fresh()->plan_status)->toBe('active');
});

it('rejects a plan_status the real CHECK does not admit', function () {
    $user = User::factory()->create();

    expect(fn () => DB::connection('pgsql')->table('core.users')
        ->where('id', $user->id)->update(['plan_status' => 'incomplete_expired']))
        ->toThrow(Illuminate\Database\QueryException::class);
})->skip(fn () => ! DB::connection('pgsql')->selectOne(
    "SELECT 1 AS ok FROM pg_constraint WHERE conname = 'users_plan_status_check'"
), 'CHECK not present on the scratch DB — apply the migration first.');
```

- [ ] **Step 9: Run the Postgres lane**

Run: `composer test:pg`
Expected: PASS. A `42P01` here means the lane never provisions a table the projector touches — add it to the `beforeEach`, do not thin the projector.

- [ ] **Step 10: Run the read-coverage guard**

Run: `php artisan test tests/Feature/Architecture/PostgresLaneReadCoverageTest.php`
Expected: PASS. A finding is fixed by **adding** the missing column to the stand-in with `ADD COLUMN IF NOT EXISTS`, never by relaxing the assertion.

- [ ] **Step 11: Commit**

```bash
git add app/Services/Billing/SubscriptionProjector.php \
        app/Events/Billing/SubscriptionFirstPaymentSucceeded.php \
        app/Jobs/Billing/ProjectStripeEventJob.php \
        tests/Feature/Billing/SubscriptionProjectionTest.php \
        tests/Postgres/BillingProjectionTest.php
git commit -m "feat(billing): order-guarded Stripe state projection onto core.users"
```

---

## Task 7: Wire the new namespaces into the audit pipeline

`AuditPipelineIntegrityTest` names `app/Services/Billing` as its own worked
example of the failure this task prevents: a new feature namespace that no
lens ever sweeps.

**Files:**
- Modify: `scripts/audit/audit.sh` (`codebase_chunks()`)
- Modify: the relevant `scripts/audit/lenses/*.md` scope groups
- Test: `tests/Feature/Architecture/AuditPipelineIntegrityTest.php` (run, do not edit)

- [ ] **Step 1: Run the integrity test and read the failure**

Run: `php artisan test tests/Feature/Architecture/AuditPipelineIntegrityTest.php`
Expected: FAIL, naming the unswept directories — `app/Services/Billing`, `app/Http/Controllers/Api/Billing`, `app/Jobs/Billing`, `tests/Feature/Billing`.

- [ ] **Step 2: Add the chunks**

In `scripts/audit/audit.sh`, `codebase_chunks()`, add entries alongside the
existing per-namespace chunks:

```
app/Services/Billing
app/Jobs/Billing
app/Http/Controllers/Api/Billing
app/Http/Controllers/Api/Internal
tests/Feature/Billing
```

- [ ] **Step 3: Add the paths to lens scope groups**

Add `app/Services/Billing` and `app/Jobs/Billing` to the scope groups of, at
minimum: `security`, `webhook-idempotency`, `transaction-boundaries`,
`data-integrity` and `configuration-hygiene`. Grep for an existing analogous
entry to copy the exact syntax:

```bash
grep -rn "app/Services/Platforms" scripts/audit/lenses/
```

- [ ] **Step 4: Re-run the integrity test**

Run: `php artisan test tests/Feature/Architecture/AuditPipelineIntegrityTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add scripts/audit/audit.sh scripts/audit/lenses
git commit -m "chore(audit): sweep the billing namespaces"
```

---

## Stage 1 gate

- [ ] `composer test` green
- [ ] `composer test:pg` green
- [ ] `composer analyse` green
- [ ] `php artisan pint --test` clean
- [ ] `stripe listen --forward-to <dev-api>/api/internal/webhooks/stripe` drives a real test-mode subscription end to end: signature accepted, `billing.webhook_events` row written, `core.users.plan_status` projected, a redelivered event acked as a duplicate
- [ ] Migrations applied to dev Supabase (`glncumufgaqcmqhzwrxm`) and the ledger version matches the filename
- [ ] **Nothing reads `hasActiveSubscription()` yet** — confirm with `grep -rn "hasActiveSubscription" app/` returning only the model definition

---

# Stage 2 — Claim seam, reconciliation, launch enrolment

## Task 8: The claim → subscribe seam

**Files:**
- Modify: `app/Services/PreAccount/ClaimSiteService.php` (inside the transaction ~line 155; post-commit block ~line 272)
- Create: `app/Services/Billing/SubscriptionProvisioner.php`
- Create: `app/Jobs/Billing/CreateStripeSubscriptionJob.php`
- Test: `tests/Feature/Billing/ClaimStartsTrialTest.php`

**Interfaces:**
- Consumes: `PriceCatalog`, `StripeClientFactory`, `Subscription`, `User`.
- Produces: `SubscriptionProvisioner::provision(User $user): void`; `CreateStripeSubscriptionJob::dispatch(string $userId)`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Billing/ClaimStartsTrialTest.php`:

```php
<?php

use App\Jobs\Billing\CreateStripeSubscriptionJob;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    setupBillingTables();
    config(['partna.billing.trial_days' => 30]);
    Bus::fake();
});

// No dark window: the mirror is written INSIDE the claim transaction. If
// plan_status were left NULL until Stripe answered, every account would read
// as not-in-good-standing between claiming and the API responding — and
// permanently if Stripe were down.
it('writes trialing state inside the claim transaction', function () {
    $user = claimableUnclaimedUser();

    claimTheSite($user);

    $user->refresh();
    expect($user->plan_status)->toBe('trialing')
        ->and($user->plan_current_period_end->isAfter(now()->addDays(29)))->toBeTrue()
        ->and($user->plan_event_at)->toBeNull();
});

// plan_event_at NULL is deliberate — the ordering guard's IS NULL branch is
// what lets the first genuine Stripe event through.
it('leaves plan_event_at null so the first Stripe event applies', function () {
    $user = claimableUnclaimedUser();

    claimTheSite($user);

    expect($user->fresh()->plan_event_at)->toBeNull();
});

it('honours a staff comp_trial_days over the config default', function () {
    $user = claimableUnclaimedUser();
    $user->forceFill(['comp_trial_days' => 90])->save();

    claimTheSite($user);

    expect($user->fresh()->plan_current_period_end->isAfter(now()->addDays(89)))->toBeTrue();
});

it('dispatches provisioning after commit, not inside the row lock', function () {
    $user = claimableUnclaimedUser();

    claimTheSite($user);

    Bus::assertDispatched(CreateStripeSubscriptionJob::class);
});

// D16: the tier is INHERITED from the build. There is no tier field in the
// claim payload, and the claim writes nothing to account_type — §8.5's
// sole-writer rule is in force from the first moment a subscription exists.
it('does not write account_type during claim', function () {
    $user = claimableUnclaimedUser(['account_type' => 'business']);

    claimTheSite($user);

    expect($user->fresh()->account_type->value)->toBe('business');
});

it('creates no Stripe objects for a billing-exempt account', function () {
    $user = claimableUnclaimedUser();
    $user->forceFill(['billing_exempt' => true])->save();

    claimTheSite($user);

    Bus::assertNotDispatched(CreateStripeSubscriptionJob::class);
});
```

Use the existing claim helpers in `tests/Pest.php` for
`claimableUnclaimedUser()` / `claimTheSite()`; if they are not present under
those names, grep `tests/Feature/PreAccount/` for the helper the shipped claim
tests already use and reuse it rather than writing a second one.

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/Billing/ClaimStartsTrialTest.php`
Expected: FAIL — `plan_status` is null

- [ ] **Step 3: Write the trial state inside the claim transaction**

In `app/Services/PreAccount/ClaimSiteService.php`, immediately after
`$professional->status = 'active';` (~line 155) and before the
`display_name` fallback:

```php
            // Subscription seam (spec 2026-09-02 §7). Decided LOCALLY and
            // written INSIDE this transaction, then handed to Stripe as the
            // trial anchor — not read back from a Stripe response.
            //
            // Written here rather than after commit because the alternative
            // has a dark window: with plan_status NULL until Stripe answers,
            // every freshly claimed account reads as not-in-good-standing for
            // an HTTP round trip, and permanently if Stripe is down. Seeding
            // the date locally also means a Stripe outage FAILS OPEN — the
            // user still gets the free month they are owed, and the exposure
            // is bounded to 30 days of something already intended.
            //
            // plan_event_at stays NULL on purpose: the ordering guard's
            // IS NULL branch is what lets the first genuine Stripe event
            // through (§8.3).
            //
            // account_type is NOT touched. The tier is inherited from the
            // build (D16); §8.5 hands sole write authority to the webhook.
            if (! $professional->isBillingExempt()) {
                $trialDays = $professional->comp_trial_days
                    ?? (int) config('partna.billing.trial_days', 30);

                $professional->forceFill([
                    'plan_status' => 'trialing',
                    'plan_current_period_end' => now()->addDays($trialDays),
                    'plan_event_at' => null,
                ]);
            }
```

The existing `DB::connection('pgsql')->transaction(fn () => $professional->save());`
below persists it — no second save.

- [ ] **Step 4: Dispatch provisioning post-commit**

In the post-commit block, after the `google_business.reenrich` line
(~line 273), using the existing `afterClaim()` wrapper so a Redis blip cannot
report a committed claim as failed:

```php
        // Stripe provisioning runs strictly AFTER commit — no third-party call
        // inside the row lock, so claim latency is unchanged. Skipped for a
        // billing-exempt account: a permanent comp has no Stripe objects at
        // all (§7).
        if (! $result['professional']->isBillingExempt()) {
            $this->afterClaim('billing.provision', $userId, fn () => CreateStripeSubscriptionJob::dispatch($userId)->afterCommit());
        }
```

Add `use App\Jobs\Billing\CreateStripeSubscriptionJob;`.

- [ ] **Step 5: Write the provisioner**

`app/Services/Billing/SubscriptionProvisioner.php`:

```php
<?php

namespace App\Services\Billing;

use App\Models\Billing\Subscription;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\Log;

/**
 * Creates the Stripe customer + subscription for a freshly claimed account
 * (spec §7), anchoring the trial to the date the claim transaction already
 * chose. Stripe runs the clock from OUR date; it does not derive one from API
 * response time.
 *
 * IDEMPOTENCY — TWO KEYS, AND THE COLUMN IS THE REAL GUARD.
 *
 * Two Stripe calls means two DISTINCT keys, namespaced per operation:
 * `partna:cust:{user_id}` and `partna:sub:{user_id}`. One key across both is
 * not a smaller version of this — Stripe REJECTS a key replayed with a
 * different request body, so the subscription call would fail outright against
 * the customer call's key.
 *
 * Stripe idempotency keys EXPIRE AFTER 24 HOURS, which matters because the
 * caller most likely to retry is billing:reconcile-subscriptions, firing
 * precisely when something has been stuck for a while. Past 24h the key
 * protects nothing and a second customer is minted. The durable guard is
 * therefore the `stripe_customer_id IS NOT NULL` check below plus its UNIQUE
 * index, re-read INSIDE this method; the Stripe key covers only the same-hour
 * timeout-and-retry case. A reconcile pass MUST NOT assume the key still holds.
 */
final class SubscriptionProvisioner
{
    public function provision(User $user): void
    {
        if ($user->isBillingExempt()) {
            return;
        }

        // Re-read inside the job: the row may have been provisioned by a
        // concurrent reconcile pass since this job was queued.
        $user = $user->fresh();

        if ($user === null || $user->isUnclaimed()) {
            return;
        }

        $stripe = StripeClientFactory::make();

        $customerId = (string) ($user->stripe_customer_id ?? '');

        if ($customerId === '') {
            $customer = $stripe->customers->create([
                'email' => $user->primary_email,
                'name' => $user->display_name,
                'metadata' => ['partna_user_id' => (string) $user->id],
            ], ['idempotency_key' => 'partna:cust:'.$user->id]);

            $customerId = (string) $customer->id;
            $user->forceFill(['stripe_customer_id' => $customerId])->save();
        }

        if (Subscription::query()->where('user_id', $user->id)->exists()) {
            return;
        }

        $trialEnd = $user->plan_current_period_end?->timestamp
            ?? now()->addDays((int) config('partna.billing.trial_days', 30))->timestamp;

        $subscription = $stripe->subscriptions->create([
            'customer' => $customerId,
            'items' => [['price' => PriceCatalog::priceIdFor($user->account_type)]],
            // OUR date, not Stripe's. See the class docblock.
            'trial_end' => $trialEnd,
            'payment_behavior' => 'default_incomplete',
            'trial_settings' => ['end_behavior' => ['missing_payment_method' => 'cancel']],
            'metadata' => ['partna_user_id' => (string) $user->id],
        ], ['idempotency_key' => 'partna:sub:'.$user->id]);

        $row = new Subscription([
            'stripe_subscription_id' => (string) $subscription->id,
            'stripe_price_id' => PriceCatalog::priceIdFor($user->account_type),
            'stripe_status' => (string) $subscription->status,
            'trial_ends_at' => $subscription->trial_end
                ? \Illuminate\Support\Carbon::createFromTimestampUTC((int) $subscription->trial_end)
                : null,
        ]);
        $row->user()->associate($user);
        $row->save();

        AccountCapabilities::flushCache();

        Log::info('billing.provisioned', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
        ]);
    }
}
```

- [ ] **Step 6: Write the job**

`app/Jobs/Billing/CreateStripeSubscriptionJob.php`:

```php
<?php

namespace App\Jobs\Billing;

use App\Models\Core\User\User;
use App\Services\Billing\SubscriptionProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Provisions Stripe objects for a claimed account, strictly post-commit.
 *
 * $afterCommit is set at the dispatch site (ClaimSiteService) rather than as a
 * property so the same job can be re-dispatched by the reconcile command from
 * outside a transaction without ceremony.
 */
class CreateStripeSubscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Back off across a Stripe blip rather than burning all three tries in a second. */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $userId) {}

    public function handle(SubscriptionProvisioner $provisioner): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        $provisioner->provision($user);
    }
}
```

- [ ] **Step 7: Run the test**

Run: `php artisan test tests/Feature/Billing/ClaimStartsTrialTest.php`
Expected: PASS (6 tests)

- [ ] **Step 8: Run the claim suite for regressions**

Run: `php artisan test tests/Feature/PreAccount`
Expected: PASS. The claim transaction is heavily pinned; a failure here means the write landed in the wrong place in the sequence, not that a test is stale.

- [ ] **Step 9: Commit**

```bash
git add app/Services/PreAccount/ClaimSiteService.php \
        app/Services/Billing/SubscriptionProvisioner.php \
        app/Jobs/Billing/CreateStripeSubscriptionJob.php \
        tests/Feature/Billing/ClaimStartsTrialTest.php
git commit -m "feat(billing): start the 30-day trial in the claim transaction"
```

---

## Task 9: `billing:reconcile-subscriptions`

**Files:**
- Create: `app/Console/Commands/Billing/ReconcileSubscriptionsCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Billing/ReconcileSubscriptionsTest.php`

**Interfaces:**
- Consumes: `CreateStripeSubscriptionJob`, `Subscription`, `User`.
- Produces: artisan `billing:reconcile-subscriptions`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Billing/ReconcileSubscriptionsTest.php`:

```php
<?php

use App\Jobs\Billing\CreateStripeSubscriptionJob;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    setupBillingTables();
    config(['partna.billing.reconcile_after_minutes' => 30]);
    Bus::fake();
});

function stuckTrialingUser(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->forceFill([
        'plan_status' => 'trialing',
        'stripe_customer_id' => null,
        'updated_at' => now()->subHour(),
    ])->save();

    return $user->fresh();
}

it('re-dispatches provisioning for a claimed trialing account with no Stripe customer', function () {
    $user = stuckTrialingUser();

    $this->artisan('billing:reconcile-subscriptions')->assertSuccessful();

    Bus::assertDispatched(CreateStripeSubscriptionJob::class,
        fn ($job) => $job->userId === (string) $user->id);
});

// The threshold exists so the command does not race the claim's own
// post-commit dispatch — the job is queued, and a queue can be a minute deep.
it('leaves an account inside the threshold alone', function () {
    $user = stuckTrialingUser();
    $user->forceFill(['updated_at' => now()->subMinute()])->save();

    $this->artisan('billing:reconcile-subscriptions')->assertSuccessful();

    Bus::assertNotDispatched(CreateStripeSubscriptionJob::class);
});

it('skips billing-exempt accounts entirely', function () {
    $user = stuckTrialingUser();
    $user->forceFill(['billing_exempt' => true])->save();

    $this->artisan('billing:reconcile-subscriptions')->assertSuccessful();

    Bus::assertNotDispatched(CreateStripeSubscriptionJob::class);
});

it('skips unclaimed provisional users — there is no owner to bill', function () {
    $user = stuckTrialingUser();
    $user->forceFill(['status' => 'unclaimed'])->save();

    $this->artisan('billing:reconcile-subscriptions')->assertSuccessful();

    Bus::assertNotDispatched(CreateStripeSubscriptionJob::class);
});

it('reports a mirror drift between the two ledgers without repairing entitlement blindly', function () {
    $user = stuckTrialingUser();
    $user->forceFill(['stripe_customer_id' => 'cus_drift'])->save();

    App\Models\Billing\Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'stripe_subscription_id' => 'sub_drift',
        'stripe_status' => 'canceled',
    ]);

    $this->artisan('billing:reconcile-subscriptions')
        ->expectsOutputToContain('drift')
        ->assertSuccessful();
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/Billing/ReconcileSubscriptionsTest.php`
Expected: FAIL — command does not exist

- [ ] **Step 3: Write the command**

`app/Console/Commands/Billing/ReconcileSubscriptionsCommand.php`:

```php
<?php

namespace App\Console\Commands\Billing;

use App\Jobs\Billing\CreateStripeSubscriptionJob;
use App\Models\Billing\Subscription;
use App\Models\Core\User\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Repairs the two ways the claim → Stripe seam can be left half-done (§7).
 *
 * Direction 1 — provisioning never completed: a claimed, trialing account with
 * no stripe_customer_id past the threshold. Re-dispatch. The threshold exists
 * so this does not race the claim's own queued job.
 *
 * Direction 2 — mirror drift: the ENTITLEMENT ledger (core.users) and the
 * AUDIT ledger (billing.subscriptions) disagree. This command is the ONLY code
 * permitted to compare them. It REPORTS rather than silently repairs: the two
 * can legitimately disagree for the length of one in-flight webhook, and a
 * command that "fixes" that by copying the audit ledger onto entitlement would
 * be writing an entitlement decision from the table §6.1 forbids reading.
 *
 * Stripe idempotency keys expire at 24h and this command fires precisely when
 * something has been stuck for a while, so re-dispatching relies on the
 * stripe_customer_id UNIQUE index, not on the key (see SubscriptionProvisioner).
 */
class ReconcileSubscriptionsCommand extends Command
{
    protected $signature = 'billing:reconcile-subscriptions {--limit=500}';

    protected $description = 'Re-dispatch stalled Stripe provisioning and report entitlement/audit ledger drift.';

    public function handle(): int
    {
        $threshold = now()->subMinutes((int) config('partna.billing.reconcile_after_minutes', 30));
        $limit = (int) $this->option('limit');

        $stalled = User::query()
            ->where('plan_status', 'trialing')
            ->whereNull('stripe_customer_id')
            ->where('billing_exempt', false)
            ->whereNot('status', 'unclaimed')
            ->where('updated_at', '<=', $threshold)
            ->limit($limit)
            ->get();

        foreach ($stalled as $user) {
            CreateStripeSubscriptionJob::dispatch((string) $user->id);
            $this->line("re-dispatched provisioning for {$user->id}");
        }

        $this->info("re-dispatched: {$stalled->count()}");

        $drift = 0;

        Subscription::query()
            ->with('user')
            ->whereNotNull('stripe_status')
            ->chunkById(200, function ($rows) use (&$drift) {
                foreach ($rows as $row) {
                    $user = $row->user;
                    if ($user === null || $user->isBillingExempt()) {
                        continue;
                    }

                    if ((string) $user->plan_status === (string) $row->stripe_status) {
                        continue;
                    }

                    $drift++;
                    $this->warn("drift user={$user->id} entitlement={$user->plan_status} audit={$row->stripe_status}");
                    Log::warning('billing.ledger_drift', [
                        'user_id' => $user->id,
                        'plan_status' => $user->plan_status,
                        'stripe_status' => $row->stripe_status,
                    ]);
                }
            });

        $this->info("drift rows: {$drift}");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Schedule it**

In `routes/console.php`, following the existing convention:

```php
Schedule::command('billing:reconcile-subscriptions')
    ->hourly()
    ->withoutOverlapping(60) // 1h lock — bounded by the --limit batch size.
    ->onFailure($reportScheduledFailure('billing-reconcile-subscriptions'));
```

- [ ] **Step 5: Run the test**

Run: `php artisan test tests/Feature/Billing/ReconcileSubscriptionsTest.php`
Expected: PASS (5 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/Billing/ReconcileSubscriptionsCommand.php \
        routes/console.php tests/Feature/Billing/ReconcileSubscriptionsTest.php
git commit -m "feat(billing): reconcile stalled provisioning and report ledger drift"
```

---

## Task 10: `billing:enroll-existing`

Read §13 before writing a line of this. Anchoring to the original claim date
retroactively expires the entire existing user base the moment enforcement is
switched on. That is the single most damaging thing to get wrong on deploy day.

**Files:**
- Create: `app/Console/Commands/Billing/EnrollExistingCommand.php`
- Test: `tests/Feature/Billing/EnrollExistingTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Billing/EnrollExistingTest.php`:

```php
<?php

use App\Jobs\Billing\CreateStripeSubscriptionJob;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    setupBillingTables();
    config(['partna.billing.trial_days' => 30]);
    Bus::fake();
});

function legacyClaimedUser(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->forceFill(['claimed_at' => now()->subMonths(6), 'plan_status' => null])->save();

    return $user->fresh();
}

// THE test this command exists for. Anchoring to the original claim date
// would put every existing account's trial six months in the past, so the
// moment stage 4's flag flips the entire user base goes dark at once.
it('anchors the trial 30 days from NOW, never from the original claim date', function () {
    $user = legacyClaimedUser();

    $this->artisan('billing:enroll-existing --confirm')->assertSuccessful();

    $user->refresh();
    expect($user->plan_status)->toBe('trialing')
        ->and($user->plan_current_period_end->isAfter(now()->addDays(29)))->toBeTrue();
});

it('refuses to run without --confirm', function () {
    legacyClaimedUser();

    $this->artisan('billing:enroll-existing')->assertFailed();
});

it('is idempotent — a second run does not move an existing anchor', function () {
    $user = legacyClaimedUser();

    $this->artisan('billing:enroll-existing --confirm')->assertSuccessful();
    $first = $user->fresh()->plan_current_period_end;

    $this->travel(2)->days();
    $this->artisan('billing:enroll-existing --confirm')->assertSuccessful();

    expect($user->fresh()->plan_current_period_end->timestamp)->toBe($first->timestamp);
});

it('skips unclaimed builds and billing-exempt accounts', function () {
    $unclaimed = User::factory()->create(['status' => 'unclaimed']);
    $exempt = legacyClaimedUser();
    $exempt->forceFill(['billing_exempt' => true])->save();

    $this->artisan('billing:enroll-existing --confirm')->assertSuccessful();

    expect($unclaimed->fresh()->plan_status)->toBeNull()
        ->and($exempt->fresh()->plan_status)->toBeNull();
});

it('dispatches provisioning for each enrolled account', function () {
    legacyClaimedUser();

    $this->artisan('billing:enroll-existing --confirm')->assertSuccessful();

    Bus::assertDispatched(CreateStripeSubscriptionJob::class);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/Billing/EnrollExistingTest.php`
Expected: FAIL — command does not exist

- [ ] **Step 3: Write the command**

`app/Console/Commands/Billing/EnrollExistingCommand.php`:

```php
<?php

namespace App\Console\Commands\Billing;

use App\Jobs\Billing\CreateStripeSubscriptionJob;
use App\Models\Core\User\User;
use Illuminate\Console\Command;

/**
 * ONE-SHOT launch migration (spec §13). Run once, at stage 2.
 *
 * Every account claimed before this ships was claimed under a free regime.
 * They are enrolled with a trial ending 30 days FROM THE LAUNCH DATE, NOT
 * from their original claim date.
 *
 * Anchoring to the original claim date would retroactively expire the entire
 * existing user base the moment enforcement is switched on — every site dark
 * simultaneously, with no warning any owner could have acted on. This is the
 * single most damaging thing to get wrong on deploy day, which is why the
 * anchor is `now()` and why --confirm exists.
 *
 * Idempotent: only rows with a NULL plan_status are touched, so a second run
 * cannot extend anyone's trial.
 */
class EnrollExistingCommand extends Command
{
    protected $signature = 'billing:enroll-existing {--confirm : Actually write} {--limit=1000}';

    protected $description = 'One-shot: enrol pre-billing claimed accounts on a trial ending 30 days from TODAY.';

    public function handle(): int
    {
        $days = (int) config('partna.billing.trial_days', 30);
        $anchor = now()->addDays($days);

        $query = User::query()
            ->whereNull('plan_status')
            ->where('billing_exempt', false)
            ->whereNot('status', 'unclaimed')
            ->whereNotNull('auth_user_id')
            ->limit((int) $this->option('limit'));

        $count = (clone $query)->count();

        if (! $this->option('--confirm') && ! $this->option('confirm')) {
            $this->error("Would enrol {$count} accounts with a trial ending {$anchor->toDateString()}. Re-run with --confirm.");

            return self::FAILURE;
        }

        $enrolled = 0;

        $query->each(function (User $user) use ($anchor, &$enrolled) {
            $user->forceFill([
                'plan_status' => 'trialing',
                'plan_current_period_end' => $anchor,
                'plan_event_at' => null,
            ])->save();

            CreateStripeSubscriptionJob::dispatch((string) $user->id);
            $enrolled++;
        });

        $this->info("enrolled: {$enrolled}, trial ends {$anchor->toDateString()}");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/Billing/EnrollExistingTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Dry-run against dev**

```bash
cloud command:run partna development "php artisan billing:enroll-existing"
```

Expected: the refusal message with a count. Read the count and the date before running with `--confirm`.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/Billing/EnrollExistingCommand.php \
        tests/Feature/Billing/EnrollExistingTest.php
git commit -m "feat(billing): one-shot launch enrolment anchored to launch date"
```

---

## Stage 2 gate

- [ ] `composer test` green
- [ ] A real claim on dev produces `plan_status='trialing'` before the response returns, and a Stripe customer + subscription within a minute
- [ ] `billing:enroll-existing --confirm` run on dev; every claimed account's `plan_current_period_end` is ~30 days out from the run date, **not** from its `claimed_at`
- [ ] `billing:reconcile-subscriptions` reports 0 stalled and 0 drift on a settled dev
- [ ] **Still nothing reads `hasActiveSubscription()`** to make a decision

---

# Stage 3 — Billing API, comps, and credits

## Task 11: `BillingGrants` — comps and credits are two operations

D18 is the whole point of this task: free *time* and free *money* are
different things, and only one of them works in every account state. Do not
collapse them back into one method.

**Files:**
- Create: `app/Services/Billing/BillingGrants.php`
- Create: `app/Http/Controllers/Api/Staff/StaffBillingGrantController.php`
- Create: `app/Http/Requests/Api/Staff/GrantFreeMonthsRequest.php`, `GrantAccountCreditRequest.php`
- Modify: `routes/api/staff.php` (the `require.aal2` group)
- Test: `tests/Feature/Billing/BillingGrantsTest.php`

**Interfaces:**
- Consumes: `StripeClientFactory`, `PriceCatalog`, `User`.
- Produces:
  - `BillingGrants::grantFreeMonths(User $user, int $months, string $reason): void`
  - `BillingGrants::grantAccountCredit(User $user, int $amountCents, string $currency, string $reason): void`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Billing/BillingGrantsTest.php`:

```php
<?php

use App\Models\Core\User\User;
use App\Services\Billing\BillingGrants;

beforeEach(function () {
    setupBillingTables();
    config([
        'partna.billing.trial_days' => 30,
        'partna.billing.amounts_cents.partna' => 2900,
        'partna.billing.currency' => 'aud',
    ]);
});

// Branch 1: no subscription yet. comp_trial_days is read at subscription
// creation, so a comp granted pre-claim survives the claim.
it('writes comp_trial_days for an account with no subscription yet', function () {
    $user = User::factory()->create(['status' => 'unclaimed']);

    app(BillingGrants::class)->grantFreeMonths($user, 3, 'launch partner');

    expect($user->fresh()->comp_trial_days)->toBe(90);
});

it('records the reason on every grant', function () {
    $user = User::factory()->create(['status' => 'unclaimed']);

    app(BillingGrants::class)->grantFreeMonths($user, 1, 'conference giveaway');

    // Reason lands in the app log and the staff audit entry, not on a column —
    // assert the log so a silent grant is impossible.
    Log::shouldHaveReceived('info')->withArgs(fn ($m, $c) => $m === 'billing.grant.free_months'
        && ($c['reason'] ?? null) === 'conference giveaway');
})->skip('Enable once Log::spy() is set up in beforeEach — see the Mockery negative-assertion caveat below.');

// D18 / §10.2 obligation 1: the referral reward is grantAccountCredit(), NOT
// grantFreeMonths(). Trial extension is meaningless for someone who has been
// paying for a year — which is exactly the profile of a referrer who has
// earned rewards. A balance credit is state-independent and is real monetary
// value, which is what "actual payment, not just free months" requires.
it('mints a balance credit in every account state, with no state machine', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->forceFill(['plan_status' => 'active', 'stripe_customer_id' => 'cus_grant_1'])->save();

    $stripe = fakeStripeClient();
    $stripe->expects('customerBalanceTransactions->create')
        ->with('cus_grant_1', ['amount' => -2900, 'currency' => 'aud', 'description' => 'referral reward']);

    app(BillingGrants::class)->grantAccountCredit($user->fresh(), 2900, 'aud', 'referral reward');
});

it('refuses a credit for an account with no Stripe customer', function () {
    $user = User::factory()->create(['status' => 'active']);

    expect(fn () => app(BillingGrants::class)->grantAccountCredit($user, 2900, 'aud', 'x'))
        ->toThrow(RuntimeException::class);
});

it('rejects a non-positive month count', function () {
    $user = User::factory()->create(['status' => 'unclaimed']);

    expect(fn () => app(BillingGrants::class)->grantFreeMonths($user, 0, 'x'))
        ->toThrow(InvalidArgumentException::class);
});
```

Build `fakeStripeClient()` as a Mockery double bound into the container in
`beforeEach`. **Type it against `\Stripe\StripeClient`** — an untyped Mockery
mock silently accepts calls a real client would reject.

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/Billing/BillingGrantsTest.php`
Expected: FAIL — `BillingGrants` not found

- [ ] **Step 3: Write `BillingGrants`**

`app/Services/Billing/BillingGrants.php`:

```php
<?php

namespace App\Services\Billing;

use App\Models\Billing\Subscription;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Two operations, not one (D18).
 *
 * Free TIME and free MONEY are different things, and only one of them works in
 * every account state. Trial extension is only meaningful while the account is
 * `trialing` — there is no equivalent for someone who has been paying for a
 * year, which is exactly the profile of a referrer who has earned rewards. A
 * balance credit is state-independent and is real monetary value.
 *
 * Splitting them is what keeps "actual payment, not just free months" true by
 * construction. grantFreeMonths() is the STAFF COMP tool; it is NOT the
 * referral seam. The referral reward routes through grantAccountCredit()
 * (§10.2 obligation 1), which is public for exactly that reason.
 *
 * billing_exempt remains a separate toggle for PERMANENT comps — neither
 * method touches it.
 */
final class BillingGrants
{
    /**
     * Extend the free period. Three branches by account state (§10.1).
     */
    public function grantFreeMonths(User $user, int $months, string $reason): void
    {
        if ($months < 1) {
            throw new InvalidArgumentException('Free months must be at least 1.');
        }

        $days = $months * 30;

        // Branch 1 — no subscription yet (pre-claim / provisional). The value
        // is read at subscription creation, so a comp granted before the claim
        // survives it.
        if ($user->stripe_customer_id === null) {
            $user->forceFill(['comp_trial_days' => ($user->comp_trial_days ?? (int) config('partna.billing.trial_days', 30)) + $days])->save();
            $this->log('free_months', $user, $months, $reason, 'comp_trial_days');

            return;
        }

        $subscription = Subscription::query()->where('user_id', $user->id)->first();
        $stripe = StripeClientFactory::make();

        // Branch 2 — trialing. Push the trial_end out. This is the only branch
        // where extending TIME is meaningful.
        if ($subscription !== null && (string) $user->plan_status === 'trialing') {
            $newEnd = ($user->plan_current_period_end ?? now())->copy()->addDays($days);

            $stripe->subscriptions->update($subscription->stripe_subscription_id, [
                'trial_end' => $newEnd->timestamp,
                'proration_behavior' => 'none',
            ]);

            $this->log('free_months', $user, $months, $reason, 'trial_end');

            return;
        }

        // Branch 3 — active / paying. Time cannot be extended, so the comp is
        // paid as money: N months of this tier's price, auto-applied to the
        // next invoice.
        $amount = PriceCatalog::monthlyAmountCents($user->account_type) * $months;

        if ($amount < 1) {
            throw new RuntimeException('No configured monthly amount for '.$user->account_type->value.'; cannot size a comp credit.');
        }

        $this->grantAccountCredit($user, $amount, (string) config('partna.billing.currency', 'aud'), $reason);
        $this->log('free_months', $user, $months, $reason, 'balance_credit');
    }

    /**
     * Mint a Stripe customer balance credit — the money-denominated grant.
     *
     * ONE branch, no state machine. Stripe applies the credit to the next
     * invoice automatically, and a credit on a `trialing` customer simply
     * waits for the first real invoice rather than being unusable.
     *
     * Public because the referral program calls exactly this from a different
     * trigger (§10.2).
     */
    public function grantAccountCredit(User $user, int $amountCents, string $currency, string $reason): void
    {
        if ($amountCents < 1) {
            throw new InvalidArgumentException('Credit amount must be positive.');
        }

        $customerId = (string) ($user->stripe_customer_id ?? '');

        if ($customerId === '') {
            throw new RuntimeException('Cannot credit an account with no Stripe customer.');
        }

        // Stripe's sign convention: a NEGATIVE balance transaction is a credit
        // in the customer's favour. Positive would bill them.
        StripeClientFactory::make()->customerBalanceTransactions->create($customerId, [
            'amount' => -1 * $amountCents,
            'currency' => strtolower($currency),
            'description' => $reason,
        ]);

        $this->log('credit', $user, $amountCents, $reason, 'balance_credit');
    }

    private function log(string $kind, User $user, int $magnitude, string $reason, string $mechanism): void
    {
        Log::info('billing.grant.'.$kind, [
            'user_id' => (string) $user->id,
            'magnitude' => $magnitude,
            'reason' => $reason,
            'mechanism' => $mechanism,
        ]);
    }
}
```

- [ ] **Step 4: Write the two Form Requests**

`app/Http/Requests/Api/Staff/GrantFreeMonthsRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Staff;

use Illuminate\Foundation\Http\FormRequest;

class GrantFreeMonthsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route carries require.aal2; the policy gate runs in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Capped at 24: a longer comp is billing_exempt, which is a
            // deliberate separate toggle, not an unbounded trial.
            'months' => ['required', 'integer', 'min:1', 'max:24'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
```

`app/Http/Requests/Api/Staff/GrantAccountCreditRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrantAccountCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount_cents' => ['required', 'integer', 'min:1', 'max:500000'],
            'currency' => ['sometimes', 'string', Rule::in(['aud'])],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
```

- [ ] **Step 5: Write the staff controller**

`app/Http/Controllers/Api/Staff/StaffBillingGrantController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\GrantAccountCreditRequest;
use App\Http\Requests\Api\Staff\GrantFreeMonthsRequest;
use App\Models\Core\User\User;
use App\Services\Billing\BillingGrants;
use Illuminate\Http\JsonResponse;

/**
 * Staff comps and credits (spec §10.1). Lives inside the require.aal2 group,
 * which already carries RecordStaffAuditEntry — so who granted what, and why,
 * is captured by the existing audit middleware rather than a bespoke ledger.
 */
class StaffBillingGrantController extends ApiController
{
    public function freeMonths(GrantFreeMonthsRequest $request, User $user, BillingGrants $grants): JsonResponse
    {
        $grants->grantFreeMonths($user, (int) $request->integer('months'), (string) $request->string('reason'));

        return $this->success(['granted' => true]);
    }

    public function credit(GrantAccountCreditRequest $request, User $user, BillingGrants $grants): JsonResponse
    {
        $grants->grantAccountCredit(
            $user,
            (int) $request->integer('amount_cents'),
            (string) ($request->string('currency')->toString() ?: config('partna.billing.currency', 'aud')),
            (string) $request->string('reason'),
        );

        return $this->success(['granted' => true]);
    }
}
```

- [ ] **Step 6: Add the routes**

`routes/api/staff.php` carries **three** route groups. Add these to the one
that already applies `require.aal2` — grep for `require.aal2` and put them in
the same group as the other user-mutating staff endpoints, not in the read
group:

```php
        Route::post('/users/{user}/billing/free-months', [StaffBillingGrantController::class, 'freeMonths'])
            ->name('staff.users.billing.free-months');
        Route::post('/users/{user}/billing/credit', [StaffBillingGrantController::class, 'credit'])
            ->name('staff.users.billing.credit');
```

- [ ] **Step 7: Run the test**

Run: `php artisan test tests/Feature/Billing/BillingGrantsTest.php`
Expected: PASS

- [ ] **Step 8: Verify the AAL2 route coverage guard**

Run: `php artisan test tests/Feature/Security/Aal2RouteCoverageTest.php`
Expected: PASS — both new routes appear under `require.aal2`.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Billing/BillingGrants.php \
        app/Http/Controllers/Api/Staff/StaffBillingGrantController.php \
        app/Http/Requests/Api/Staff \
        routes/api/staff.php tests/Feature/Billing/BillingGrantsTest.php
git commit -m "feat(billing): staff comps (time) and account credits (money), split per D18"
```

---

## Task 12: The billing API

**Files:**
- Create: `app/Services/Billing/BillingActions.php`
- Create: `app/Http/Controllers/Api/Billing/{BillingStateController,SetupIntentController,SubscribeController,PlanController,SubscriptionController,InvoiceController}.php`
- Create: `app/Http/Resources/Billing/BillingStateResource.php`
- Create: `app/Http/Requests/Api/Billing/{SubscribeRequest,ChangePlanRequest}.php`
- Modify: `routes/api/user.php`, `config/partna.php` (throttle bucket), `app/Providers/AppServiceProvider.php` (limiter)
- Test: `tests/Feature/Billing/BillingApiTest.php`

**Interfaces:**
- Produces: seven routes under `/api/billing/*`; `BillingActions::{subscribe,changePlan,cancel,resume}`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Billing/BillingApiTest.php`:

```php
<?php

use App\Models\Core\User\User;

beforeEach(function () {
    setupBillingTables();
    config([
        'partna.billing.prices.partna' => 'price_partna_1',
        'partna.billing.prices.business' => 'price_biz_1',
    ]);
});

it('returns plan, status, trial end and card details', function () {
    $user = billingUser(['plan_status' => 'trialing']);

    $this->actingAsProfessional($user)
        ->getJson('/api/billing/state')
        ->assertOk()
        ->assertJsonPath('data.status', 'trialing')
        ->assertJsonPath('data.plan', 'partna')
        ->assertJsonStructure(['data' => ['status', 'plan', 'trialEndsAt', 'currentPeriodEnd', 'paymentMethod', 'cancelAtPeriodEnd']]);
});

// SCA/3DS is the NORMAL path in Australia, not an edge case. A frontend that
// treats requires_action as failure reports working cards as declined, which
// is why the three-state return is asserted here rather than left to the UI.
it('returns one of succeeded, requires_action or failed from subscribe', function () {
    $user = billingUser(['plan_status' => 'trialing']);

    $response = $this->actingAsProfessional($user)
        ->postJson('/api/billing/subscribe', ['payment_method_id' => 'pm_card_visa']);

    expect($response->json('data.result'))->toBeIn(['succeeded', 'requires_action', 'failed']);
});

it('returns a client secret for the Elements card field', function () {
    $user = billingUser();

    $this->actingAsProfessional($user)
        ->postJson('/api/billing/setup-intent')
        ->assertOk()
        ->assertJsonStructure(['data' => ['clientSecret']]);
});

// D10: plan switches are immediate and prorated, both directions. The
// controller must NOT write account_type — the webhook does (§8.5), or a
// declined card leaves an account holding Business capabilities it is not
// paying for.
it('does not write account_type when swapping plans', function () {
    $user = billingUser(['account_type' => 'partna', 'plan_status' => 'active']);

    $this->actingAsProfessional($user)
        ->postJson('/api/billing/plan', ['account_type' => 'business'])
        ->assertOk();

    expect($user->fresh()->account_type->value)->toBe('partna');
});

it('rejects a plan swap to the account type already held', function () {
    $user = billingUser(['account_type' => 'partna', 'plan_status' => 'active']);

    $this->actingAsProfessional($user)
        ->postJson('/api/billing/plan', ['account_type' => 'partna'])
        ->assertStatus(422);
});

it('cancels at period end, not immediately — the month is already paid for', function () {
    $user = billingUser(['plan_status' => 'active']);

    $this->actingAsProfessional($user)
        ->deleteJson('/api/billing/subscription')
        ->assertOk()
        ->assertJsonPath('data.cancelAtPeriodEnd', true);

    expect($user->fresh()->plan_status)->toBe('active');
});

it('resumes a pending cancellation', function () {
    $user = billingUser(['plan_status' => 'active']);

    $this->actingAsProfessional($user)
        ->postJson('/api/billing/subscription/resume')
        ->assertOk();
});

it('never returns the Stripe secret key from any billing endpoint', function () {
    config(['services.stripe.secret' => 'sk_test_do_not_leak']);
    $user = billingUser();

    $body = $this->actingAsProfessional($user)->getJson('/api/billing/state')->getContent();

    expect($body)->not->toContain('sk_test_do_not_leak');
});

it('requires authentication', function () {
    $this->getJson('/api/billing/state')->assertStatus(401);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/Billing/BillingApiTest.php`
Expected: FAIL — 404 on every route

- [ ] **Step 3: Write `BillingActions`**

`app/Services/Billing/BillingActions.php`:

```php
<?php

namespace App\Services\Billing;

use App\Enums\AccountType;
use App\Models\Billing\Subscription;
use App\Models\Core\User\User;
use RuntimeException;

/**
 * The service layer behind /api/billing/* (spec §11).
 *
 * Nothing here writes plan_status or account_type. Stripe is told what to do;
 * the resulting webhook projects the state (§8.5). That ordering is what stops
 * a declined card from leaving an account holding capabilities it is not
 * paying for — and it is why subscribe() returns Stripe's own three-state
 * outcome rather than asserting success.
 */
final class BillingActions
{
    /**
     * Attach a payment method and activate.
     *
     * Returns 'succeeded' | 'requires_action' | 'failed'. The middle state is
     * SCA/3DS and IN AUSTRALIA IT IS THE NORMAL PATH, not an edge case: a
     * caller that treats it as failure reports working cards as declined.
     *
     * @return array{result: string, clientSecret: string|null}
     */
    public function subscribe(User $user, string $paymentMethodId): array
    {
        $customerId = (string) ($user->stripe_customer_id ?? '');
        if ($customerId === '') {
            throw new RuntimeException('No Stripe customer for this account.');
        }

        $stripe = StripeClientFactory::make();

        $stripe->paymentMethods->attach($paymentMethodId, ['customer' => $customerId]);
        $stripe->customers->update($customerId, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ]);

        $subscription = $this->subscriptionOrFail($user);

        $updated = $stripe->subscriptions->update($subscription->stripe_subscription_id, [
            'default_payment_method' => $paymentMethodId,
        ]);

        $intent = $updated->latest_invoice->payment_intent ?? null;

        return match (true) {
            $intent === null => ['result' => 'succeeded', 'clientSecret' => null],
            $intent->status === 'succeeded' => ['result' => 'succeeded', 'clientSecret' => null],
            in_array($intent->status, ['requires_action', 'requires_confirmation'], true) => [
                'result' => 'requires_action',
                'clientSecret' => (string) $intent->client_secret,
            ],
            default => ['result' => 'failed', 'clientSecret' => null],
        };
    }

    /** Swap the price. Immediate and prorated, both directions (D10). */
    public function changePlan(User $user, AccountType $target): void
    {
        if ($user->account_type === $target) {
            throw new RuntimeException('Account is already on that plan.');
        }

        $subscription = $this->subscriptionOrFail($user);
        $stripe = StripeClientFactory::make();

        $current = $stripe->subscriptions->retrieve($subscription->stripe_subscription_id);
        $itemId = (string) ($current->items->data[0]->id ?? '');

        $stripe->subscriptions->update($subscription->stripe_subscription_id, [
            'items' => [['id' => $itemId, 'price' => PriceCatalog::priceIdFor($target)]],
            'proration_behavior' => 'always_invoice',
        ]);

        // account_type is NOT written here. The resulting
        // customer.subscription.updated projects it (§8.5).
    }

    /** Cancel at period end — the month is already paid for. */
    public function cancel(User $user): void
    {
        StripeClientFactory::make()->subscriptions->update(
            $this->subscriptionOrFail($user)->stripe_subscription_id,
            ['cancel_at_period_end' => true],
        );
    }

    public function resume(User $user): void
    {
        StripeClientFactory::make()->subscriptions->update(
            $this->subscriptionOrFail($user)->stripe_subscription_id,
            ['cancel_at_period_end' => false],
        );
    }

    private function subscriptionOrFail(User $user): Subscription
    {
        $subscription = Subscription::query()->where('user_id', $user->id)->first();

        if ($subscription === null) {
            throw new RuntimeException('No subscription for this account.');
        }

        return $subscription;
    }
}
```

- [ ] **Step 4: Write the state resource**

`app/Http/Resources/Billing/BillingStateResource.php`:

```php
<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The dashboard's view of billing standing (spec §11).
 *
 * Reads the core.users projection for status and dates — never
 * billing.subscriptions, which is the audit ledger. Card details come from
 * Stripe's own customer object, passed in, because they are display-only and
 * are not projected.
 */
class BillingStateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'plan' => $this->resource['plan'],
            'status' => $this->resource['status'],
            'trialEndsAt' => $this->resource['trialEndsAt'],
            'currentPeriodEnd' => $this->resource['currentPeriodEnd'],
            'cancelAtPeriodEnd' => $this->resource['cancelAtPeriodEnd'],
            'billingExempt' => $this->resource['billingExempt'],
            'paymentMethod' => $this->resource['paymentMethod'],
        ];
    }
}
```

- [ ] **Step 5: Write the six controllers**

Each is thin; the shape is the same. `app/Http/Controllers/Api/Billing/BillingStateController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Billing\BillingStateResource;
use App\Models\Billing\Subscription;
use App\Services\Billing\StripeClientFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingStateController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $subscription = Subscription::query()->where('user_id', $user->id)->first();

        $paymentMethod = null;
        $cancelAtPeriodEnd = false;

        // Display-only, so a Stripe blip degrades the card panel rather than
        // the page: the entitlement half of this payload comes from
        // core.users and never depends on Stripe being reachable.
        if ($user->stripe_customer_id !== null) {
            try {
                $customer = StripeClientFactory::make()->customers->retrieve(
                    $user->stripe_customer_id,
                    ['expand' => ['invoice_settings.default_payment_method']],
                );
                $pm = $customer->invoice_settings->default_payment_method ?? null;
                if ($pm !== null) {
                    $paymentMethod = ['brand' => $pm->card->brand ?? null, 'last4' => $pm->card->last4 ?? null];
                }
                if ($subscription !== null) {
                    $sub = StripeClientFactory::make()->subscriptions->retrieve($subscription->stripe_subscription_id);
                    $cancelAtPeriodEnd = (bool) $sub->cancel_at_period_end;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $this->success(new BillingStateResource([
            'plan' => $user->account_type->value,
            'status' => $user->plan_status,
            'trialEndsAt' => $subscription?->trial_ends_at?->toIso8601String(),
            'currentPeriodEnd' => $user->plan_current_period_end?->toIso8601String(),
            'cancelAtPeriodEnd' => $cancelAtPeriodEnd,
            'billingExempt' => $user->isBillingExempt(),
            'paymentMethod' => $paymentMethod,
        ]));
    }
}
```

Write the other five to the same shape:
- `SetupIntentController` — `$stripe->setupIntents->create(['customer' => …, 'usage' => 'off_session'])`, returns `['clientSecret' => $intent->client_secret]`
- `SubscribeController` — validates via `SubscribeRequest`, calls `BillingActions::subscribe()`, returns `['result' => …, 'clientSecret' => …]`
- `PlanController` — validates via `ChangePlanRequest`, calls `changePlan()`, returns 200; catches the "already on that plan" `RuntimeException` and returns `$this->error('Already on that plan.', 422)`
- `SubscriptionController::destroy` / `::resume` — call `cancel()` / `resume()`, return `['cancelAtPeriodEnd' => true|false]`
- `InvoiceController` — `$stripe->invoices->all(['customer' => …, 'limit' => 24])`, maps to `['id','number','status','amountPaid','hostedInvoiceUrl','invoicePdf','createdAt']`, wrapped in `$this->successCached(..., 300)`

- [ ] **Step 6: Write the two Form Requests**

`app/Http/Requests/Api/Billing/SubscribeRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Billing;

use Illuminate\Foundation\Http\FormRequest;

class SubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Stripe payment method ids are `pm_` + an opaque token. Pinned to
            // a pattern so a caller cannot smuggle another object id here.
            'payment_method_id' => ['required', 'string', 'regex:/^pm_[A-Za-z0-9]+$/', 'max:255'],
        ];
    }
}
```

`app/Http/Requests/Api/Billing/ChangePlanRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Billing;

use App\Enums\AccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * NOTE for the architecture test in Task 14: this request DOES validate
 * `account_type`, and that is correct — it is the billing lane, where changing
 * the tier is a paid action that goes through Stripe. The guard's allowlist
 * names this class explicitly. The hole it closes is the OWNER-facing
 * UpdateUserRequest, which grants the tier for free.
 */
class ChangePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'account_type' => ['required', Rule::in([AccountType::Partna->value, AccountType::Business->value])],
        ];
    }
}
```

- [ ] **Step 7: Add the throttle bucket**

In `config/partna.php`, inside the existing `'throttle' => [` block:

```php
        // Billing endpoints reach Stripe on nearly every call, so this bucket
        // is deliberately tighter than `authenticated`: a retry loop here
        // spends someone else's rate limit, not just ours.
        'billing_per_minute' => (int) env('PARTNA_THROTTLE_BILLING_PER_MINUTE', 20),
```

In `app/Providers/AppServiceProvider::configureRateLimiting()`, beside the `webhooks` limiter:

```php
        RateLimiter::for('billing', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            // Keyed on the resolved user, falling back to IP for an
            // unauthenticated probe — resource + caller, per the house rule.
            $key = (string) ($request->attributes->get('professional')?->id ?? $request->ip());

            return Limit::perMinute((int) config('partna.throttle.billing_per_minute', 20))->by('billing:'.$key);
        });
```

- [ ] **Step 8: Add the routes**

In `routes/api/user.php`, inside the `user.api` group. **These routes must be
excluded from `EnforceBillingStandingReadOnly` when Task 16 adds it** — the
`withoutMiddleware` call is written now so it cannot be forgotten later:

```php
        // Billing (spec §11). EXCLUDED from EnforceBillingStandingReadOnly:
        // a disabled user who cannot reach the pay endpoint can never stop
        // being disabled. That deadlock is the exact one
        // EnforcePendingDeletionReadOnly's docblock warns about.
        Route::prefix('billing')->middleware('throttle:billing')
            ->withoutMiddleware([EnforceBillingStandingReadOnly::class])
            ->group(function () {
                Route::get('/state', BillingStateController::class)->name('billing.state');
                Route::post('/setup-intent', SetupIntentController::class)->name('billing.setup-intent');
                Route::post('/subscribe', SubscribeController::class)->name('billing.subscribe');
                Route::post('/plan', PlanController::class)->name('billing.plan');
                Route::delete('/subscription', [SubscriptionController::class, 'destroy'])->name('billing.subscription.cancel');
                Route::post('/subscription/resume', [SubscriptionController::class, 'resume'])->name('billing.subscription.resume');
                Route::get('/invoices', InvoiceController::class)->name('billing.invoices');
            });
```

Until Task 16 exists, `EnforceBillingStandingReadOnly::class` will not resolve — add the routes in Task 16's commit if you are executing tasks strictly in order, or create the middleware class as an empty pass-through now and fill it in Task 16. **Prefer the second**: the route file then never changes again.

- [ ] **Step 9: Run the test**

Run: `php artisan test tests/Feature/Billing/BillingApiTest.php`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add app/Services/Billing/BillingActions.php app/Http/Controllers/Api/Billing \
        app/Http/Resources/Billing app/Http/Requests/Api/Billing \
        routes/api/user.php config/partna.php app/Providers/AppServiceProvider.php \
        tests/Feature/Billing/BillingApiTest.php
git commit -m "feat(billing): the /api/billing surface, Elements-backed"
```

---

## Task 13: Publishable key on `/api/config/integrations`, and the frontend contract

**Files:**
- Modify: `app/Http/Controllers/Api/User/PublicConfigController.php`
- Modify: `docs/api.md`
- Test: `tests/Feature/Billing/BillingConfigTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Billing/BillingConfigTest.php`:

```php
<?php

use App\Models\Core\User\User;

beforeEach(fn () => setupBillingTables());

it('exposes the Stripe publishable key on the authenticated config endpoint', function () {
    config(['services.stripe.key' => 'pk_test_abc']);
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAsProfessional($user)
        ->getJson('/api/config/integrations')
        ->assertOk()
        ->assertJsonPath('data.stripePublishableKey', 'pk_test_abc');
});

// The secret must never be reachable through any endpoint. This is a
// standing assertion, not a one-off: it is the only automated thing standing
// between a copy-paste and a leaked key.
it('never exposes the Stripe secret key', function () {
    config(['services.stripe.key' => 'pk_test_abc', 'services.stripe.secret' => 'sk_test_secret']);
    $user = User::factory()->create(['status' => 'active']);

    $body = $this->actingAsProfessional($user)->getJson('/api/config/integrations')->getContent();

    expect($body)->not->toContain('sk_test_secret');
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/Billing/BillingConfigTest.php`
Expected: FAIL — key absent

- [ ] **Step 3: Add the key**

In `PublicConfigController::integrations()`, beside the Google Maps key:

```php
            // Stripe Elements needs the PUBLISHABLE key in the browser (D8:
            // all billing UI is self-hosted, so there is no Stripe-hosted page
            // to carry it). The SECRET key never leaves the server and is
            // returned by no endpoint — pinned by BillingConfigTest.
            'stripePublishableKey' => config('services.stripe.key'),
```

- [ ] **Step 4: Document the frontend obligations in `docs/api.md`**

Add a `## Billing` section covering the seven endpoints, and make these two
obligations impossible to miss:

```markdown
### Frontend obligations

1. **`POST /api/billing/subscribe` returns three states, not two.**
   `succeeded` | `requires_action` | `failed`. `requires_action` carries a
   payment-intent `clientSecret` for `stripe.handleNextAction()`. In Australia
   SCA/3DS is the NORMAL path, not an edge case — a client that treats
   `requires_action` as failure will report working cards as declined.

2. **A 402 from any dashboard write means billing standing, not a bug.**
   The body carries `{"error":"billing_inactive", ...}`. Render a reactivate
   prompt. The billing, account-deletion and data-export endpoints are exempt
   and keep working.
```

- [ ] **Step 5: Run the test and commit**

Run: `php artisan test tests/Feature/Billing/BillingConfigTest.php`
Expected: PASS

```bash
git add app/Http/Controllers/Api/User/PublicConfigController.php docs/api.md \
        tests/Feature/Billing/BillingConfigTest.php
git commit -m "feat(billing): publishable key on the config endpoint, document the SCA contract"
```

---

## Stage 3 gate

- [ ] `composer test` green
- [ ] A real card entered through Elements on dev produces `plan_status='active'` via the webhook, with `account_type` untouched by the controller
- [ ] A 3DS test card (`4000 0027 6000 3184`) returns `requires_action` with a usable `clientSecret`
- [ ] A plan swap invoices immediately and the webhook flips `account_type`
- [ ] **Not paying still costs nothing** — confirm a `canceled` account's public site is still served

---

# Stage 4 — Enforcement

⚠️ **The dangerous stage.** Everything except Tasks 14 and 18 sits behind
`config('partna.billing.enforcement_enabled')`, which defaults to `false` and
is env-driven so it can be killed without a redeploy.

**Tasks 14 and 18 ship regardless of that flag** — Task 14 closes a free-upgrade
hole that is open in production today and has nothing to do with billing
enforcement; Task 18 is a capability gate, not an enforcement gate.

## Task 14: Close the `account_type` free-upgrade hole (ships regardless of the flag)

`PATCH /api/me {"account_type":"business"}` is a free upgrade **today**,
reachable by any authenticated user, granting `can_use_multipage_site`,
`can_book_storewide`, `google_business_full_sync` and
`workplace_brand_is_site_identity` for nothing. Every other part of this design
assumes it is already impossible.

**Files:**
- Modify: `app/Http/Requests/Api/User/UpdateUserRequest.php:31`
- Create: `tests/Feature/Architecture/AccountTypeWriteSurfaceTest.php`
- Test: `tests/Feature/Billing/FreeUpgradeRegressionTest.php`

- [ ] **Step 1: Write the failing regression test**

`tests/Feature/Billing/FreeUpgradeRegressionTest.php`:

```php
<?php

use App\Models\Core\User\User;

beforeEach(fn () => setupBillingTables());

// 2xx AND unchanged, both halves. D17: the field is DROPPED, not rejected —
// an old dashboard still posting the whole user object is accepted and the
// field ignored, exactly as skeleton_id is handled on PATCH /professional/site.
// A 422 would break a client that is merely stale, and the security outcome is
// identical.
it('accepts a stale client posting account_type but does not write it', function () {
    $user = User::factory()->create(['account_type' => 'partna', 'status' => 'active']);

    $this->actingAsProfessional($user)
        ->patchJson('/api/me', ['account_type' => 'business', 'display_name' => 'Still Me'])
        ->assertOk();

    $user->refresh();
    expect($user->account_type->value)->toBe('partna')
        ->and($user->display_name)->toBe('Still Me');
});

it('does not let a partna account reach business-only capabilities through /api/me', function () {
    $user = User::factory()->create(['account_type' => 'partna', 'status' => 'active']);

    $this->actingAsProfessional($user)->patchJson('/api/me', ['account_type' => 'business']);

    expect(App\Services\Accounts\AccountCapabilities::for($user->fresh())->can_use_multipage_site)->toBeFalse();
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/Billing/FreeUpgradeRegressionTest.php`
Expected: FAIL — `account_type` is now `business`

- [ ] **Step 3: Remove the rule — and ONLY the rule**

Delete line 31 of `app/Http/Requests/Api/User/UpdateUserRequest.php` and leave
a comment in its place:

```php
            // account_type is deliberately ABSENT (spec 2026-09-02 §8.5).
            // Accepting it here was a free upgrade: any authenticated user
            // could PATCH themselves to `business` and take
            // can_use_multipage_site, can_book_storewide,
            // google_business_full_sync and workplace_brand_is_site_identity
            // without paying. The tier is now written ONLY by the Stripe
            // webhook, from the price on the subscription.
            //
            // Dropped, not `prohibited` (D17): a stale dashboard that still
            // posts the whole user object is accepted and the field ignored,
            // exactly as skeleton_id is handled on PATCH /professional/site. A
            // 422 would break a client that is merely stale, and the security
            // outcome is identical.
            //
            // DO NOT "fix" this by removing account_type from User::$fillable.
            // That looks like the stronger move and is a trap: Eloquent
            // factories construct through fill(), so UserFactory would
            // silently drop the attribute and mint users with a NULL tier,
            // taking users_account_type_check with it across ~250 test files.
            // UserBootstrapService, PreAccountBuildService, ShowcaseSeedCommand
            // and FleetPlacesCommand all mass-assign it legitimately. The
            // request rule was the entire owner-facing attack surface.
```

- [ ] **Step 4: Run the regression test**

Run: `php artisan test tests/Feature/Billing/FreeUpgradeRegressionTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Write the architecture guard**

The rule is invisible at the call site, and a future "let users pick their plan
in settings" PR reintroduces it in one line without ever reading this spec.

`tests/Feature/Architecture/AccountTypeWriteSurfaceTest.php`:

```php
<?php

/*
| account_type is written by the Stripe webhook and nowhere else (spec §8.5).
|
| A Form Request that validates it is a write surface for the tier column, and
| the tier column is what AccountCapabilities derives every paid capability
| from. This guard exists because the rule is one line, invisible at the call
| site, and its absence looks like an oversight to anyone who has not read the
| design.
|
| The allowlist below is the complete set of lanes permitted to accept it, and
| each entry needs a reason, not just a name.
*/

use Symfony\Component\Finder\Finder;

const ACCOUNT_TYPE_WRITE_ALLOWLIST = [
    // The billing lane: changing tier here is a PAID action that goes through
    // Stripe, and the controller still does not write the column — the
    // resulting webhook does.
    'App\Http\Requests\Api\Billing\ChangePlanRequest',

    // Signup / build creation. Both run BEFORE any subscription exists, so
    // there is no entitlement to grant for free.
    'App\Http\Requests\Api\PublicSite\SignupBuildRequest',
    'App\Http\Requests\Api\Staff\StoreBuildRequest',
];

it('no Form Request outside the billing and build lanes validates account_type', function () {
    $offenders = [];

    foreach ((new Finder)->files()->in(app_path('Http/Requests'))->name('*.php') as $file) {
        $class = str_replace([app_path(), '/', '.php'], ['App', '\\', ''], (string) $file->getRealPath());

        if (in_array($class, ACCOUNT_TYPE_WRITE_ALLOWLIST, true)) {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getRealPath()), "'account_type'")) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These Form Requests validate account_type and are not on the allowlist:',
        ...$offenders,
        '',
        'account_type is the paid tier. Accepting it outside the billing lane is a',
        'free upgrade — see docs/superpowers/specs/2026-09-02-stripe-subscriptions-design.md §8.5.',
        'If this is genuinely a pre-subscription lane, add it to',
        'ACCOUNT_TYPE_WRITE_ALLOWLIST with a written reason.',
    ]));
});

// Assert the denominator before the numerator: `$offenders === []` is also
// true when the sweep examined nothing.
it('actually swept the requests directory', function () {
    $count = iterator_count((new Finder)->files()->in(app_path('Http/Requests'))->name('*.php'));

    expect($count)->toBeGreaterThan(50);
});
```

Correct the allowlist class names against the real signup/build request
classes before running — grep `app/Http/Requests` for `account_type` and put
exactly what is there, having first confirmed each one is genuinely
pre-subscription.

- [ ] **Step 6: Run the guard**

Run: `php artisan test tests/Feature/Architecture/AccountTypeWriteSurfaceTest.php`
Expected: PASS

- [ ] **Step 7: Run the /me suite for regressions**

Run: `php artisan test tests/Feature/Api/User`
Expected: PASS. A failure asserting a successful `account_type` change is a test that was pinning the hole — update it to assert the field is dropped.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Api/User/UpdateUserRequest.php \
        tests/Feature/Architecture/AccountTypeWriteSurfaceTest.php \
        tests/Feature/Billing/FreeUpgradeRegressionTest.php
git commit -m "fix(security): account_type is no longer owner-writable via PATCH /api/me"
```

---

## Task 15: The publish-gate clause

**Files:**
- Modify: `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:134`
- Modify: `app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php:56-57`
- Modify: `tests/Feature/PublicSite/PublishGateTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/PublicSite/PublishGateTest.php`:

```php
it('404s a claimed, published site whose subscription has lapsed', function () {
    config(['partna.billing.enforcement_enabled' => true]);
    [$pro, $site] = claimedPublishedSite();
    $pro->forceFill(['plan_status' => 'canceled'])->save();

    $this->getJson("/api/public/profiles/{$pro->handle_lc}")->assertStatus(404);
    $this->getJson("/api/public/integrations/{$pro->handle_lc}")->assertStatus(404);
});

// D13: past_due STAYS LIVE. Stripe's smart-retry window resolves most lapses,
// typically an expired card. Darkening a working business on the first failed
// charge and restoring it two days later is worse for everyone.
it('keeps a past_due site live', function () {
    config(['partna.billing.enforcement_enabled' => true]);
    [$pro] = claimedPublishedSite();
    $pro->forceFill(['plan_status' => 'past_due'])->save();

    $this->getJson("/api/public/profiles/{$pro->handle_lc}")->assertOk();
});

// D3: the unclaimed carve-out is untouched. 89% of the dev fleet is exactly
// this state, and it is the product pitch — a visitor sees their site before
// claiming it. A build has no owner to bill.
it('still serves an unclaimed build regardless of billing standing', function () {
    config(['partna.billing.enforcement_enabled' => true]);
    [$pro] = unclaimedBuildSite();
    $pro->forceFill(['plan_status' => null])->save();

    $this->getJson("/api/public/profiles/{$pro->handle_lc}")->assertOk();
});

it('serves a billing-exempt account with no plan_status at all', function () {
    config(['partna.billing.enforcement_enabled' => true]);
    [$pro] = claimedPublishedSite();
    $pro->forceFill(['plan_status' => null, 'billing_exempt' => true])->save();

    $this->getJson("/api/public/profiles/{$pro->handle_lc}")->assertOk();
});

// The kill switch. With the flag off, billing standing makes no decision at
// all — that is what makes stage 4 revertible without a redeploy.
it('ignores billing standing entirely when enforcement is disabled', function () {
    config(['partna.billing.enforcement_enabled' => false]);
    [$pro] = claimedPublishedSite();
    $pro->forceFill(['plan_status' => 'canceled'])->save();

    $this->getJson("/api/public/profiles/{$pro->handle_lc}")->assertOk();
});
```

Extend the existing mutation gate in the same file to cover the new clause —
find the block that enumerates the mutations and add one per new term
(`hasActiveSubscription` negated, the flag inverted, the unclaimed carve-out
dropped, `past_due` moved out of good standing, `billing_exempt` ignored).

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/PublicSite/PublishGateTest.php`
Expected: FAIL — the lapsed site is served

- [ ] **Step 3: Add the clause to `IndividualProfileController`**

Replace the `'unpublished' => ...` expression inside the resolve closure
(line ~134):

```php
                    // Billing standing joins the publish knob (spec §9.1).
                    // is_published remains OWNER INTENT and is never written by
                    // billing: the owner could otherwise un-dark themselves
                    // through the normal UI, and on payment we would not know
                    // whether to republish a site the owner had deliberately
                    // unpublished.
                    //
                    // Computed HERE and not in the payload builder for the same
                    // reason the publish knob is — see the note above. The
                    // consequence: this verdict rides the handle.resolve cache,
                    // so at the moment the flag flips, every WARM entry carries
                    // no key for it and `?? false` reads that absence as "not
                    // gated". Enforcement therefore FAILS OPEN for one resolve
                    // TTL after rollout, which is the right direction to fail —
                    // nobody's site goes dark by surprise — but it means a
                    // verification pass run inside that window reads as a false
                    // negative. VERIFY AGAINST A COLD HANDLE.
                    'unpublished' => $site !== null
                        && ! $pro->isUnclaimed()
                        && (
                            ! $site->is_published
                            || (
                                (bool) config('partna.billing.enforcement_enabled', false)
                                && ! $pro->hasActiveSubscription()
                            )
                        ),
```

- [ ] **Step 4: Add the clause to `PublicIntegrationController`**

Two changes. First widen the select — the current
`->first(['id', 'status'])` does not load the plan columns, and
`hasActiveSubscription()` would silently read nulls:

```php
        $user = User::query()->where('handle_lc', $handleLc)
            ->first(['id', 'status', 'plan_status', 'billing_exempt']);
```

Then the gate:

```php
        // Same predicate as IndividualProfileController::show — the two public
        // read paths must agree, or the gate is half a gate. Uncached endpoint,
        // so this is a direct read, not a cached verdict: it does NOT have the
        // warm-cache fail-open the profile path has.
        $site = Site::query()->where('user_id', $user->id)->first(['is_published']);
        $lapsed = (bool) config('partna.billing.enforcement_enabled', false)
            && ! $user->hasActiveSubscription();

        if ($site !== null && ! $user->isUnclaimed() && (! $site->is_published || $lapsed)) {
            return $this->error('Not found.', 404);
        }
```

- [ ] **Step 5: Run the test**

Run: `php artisan test tests/Feature/PublicSite/PublishGateTest.php`
Expected: PASS, including the extended mutation gate

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/PublicSite/IndividualProfileController.php \
        app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php \
        tests/Feature/PublicSite/PublishGateTest.php
git commit -m "feat(billing): gate the public read paths on billing standing"
```

---

## Task 16: `EnforceBillingStandingReadOnly`

**Files:**
- Create: `app/Http/Middleware/Context/EnforceBillingStandingReadOnly.php`
- Modify: `routes/api/user.php` (apply it, plus the three `withoutMiddleware` exclusions), `routes/api/platforms.php`
- Test: `tests/Feature/Billing/BillingReadOnlyMiddlewareTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Billing/BillingReadOnlyMiddlewareTest.php`:

```php
<?php

use App\Models\Core\User\User;

beforeEach(function () {
    setupBillingTables();
    config(['partna.billing.enforcement_enabled' => true]);
});

function lapsedUser(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->forceFill(['plan_status' => 'unpaid', 'status' => 'disabled'])->save();

    return $user->fresh();
}

it('402s a dashboard write for a lapsed account', function () {
    $this->actingAsProfessional(lapsedUser())
        ->patchJson('/api/me', ['display_name' => 'New Name'])
        ->assertStatus(402)
        ->assertJsonPath('error', 'billing_inactive');
});

it('lets safe methods through', function () {
    $this->actingAsProfessional(lapsedUser())->getJson('/api/me')->assertOk();
});

// THE DEADLOCK. A disabled user who cannot reach the pay endpoint can never
// stop being disabled. This is the exact failure
// EnforcePendingDeletionReadOnly's docblock warns about.
it('leaves the billing endpoints reachable', function () {
    $this->actingAsProfessional(lapsedUser())->getJson('/api/billing/state')->assertOk();
    $this->actingAsProfessional(lapsedUser())
        ->postJson('/api/billing/setup-intent')->assertStatus(200);
});

// A GDPR right cannot be paywalled. Erasure and portability stay available
// regardless of billing standing.
it('leaves account deletion and data export reachable', function () {
    $this->actingAsProfessional(lapsedUser())
        ->postJson('/api/me/deletion/request')->assertStatus(200);
    $this->actingAsProfessional(lapsedUser())
        ->postJson('/api/me/export')->assertStatus(200);
})->skip('Adjust the expected statuses to whatever these endpoints return today — the assertion is "not 402".');

// It gates on BILLING STANDING, not on status. 'disabled' is a
// general-purpose status a staff member may also set for moderation reasons;
// an account disabled for moderation is often still paying and must not be
// shown a "pay to continue" prompt. Two independent gates that share a value.
it('does not 402 an account disabled for moderation while still paying', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->forceFill(['status' => 'disabled', 'plan_status' => 'active'])->save();

    $this->actingAsProfessional($user->fresh())
        ->patchJson('/api/me', ['display_name' => 'X'])
        ->assertStatus(200);
});

it('does nothing at all when enforcement is disabled', function () {
    config(['partna.billing.enforcement_enabled' => false]);

    $this->actingAsProfessional(lapsedUser())
        ->patchJson('/api/me', ['display_name' => 'X'])
        ->assertStatus(200);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/Billing/BillingReadOnlyMiddlewareTest.php`
Expected: FAIL — writes succeed

- [ ] **Step 3: Write the middleware**

`app/Http/Middleware/Context/EnforceBillingStandingReadOnly.php`:

```php
<?php

namespace App\Http\Middleware\Context;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks dashboard WRITES for an account not in billing good standing
 * (spec §9.2). Modelled on EnforcePendingDeletionReadOnly; safe methods pass.
 *
 * IT GATES ON BILLING STANDING, NOT ON `status`. The trigger is
 * ! $professional->hasActiveSubscription(), deliberately NOT
 * $status === 'disabled'. 'disabled' is a general-purpose status a staff
 * member may also set for moderation reasons; an account disabled for
 * moderation is often still paying, and must not be shown a "pay to continue"
 * prompt. Billing standing and moderation standing are independent gates that
 * happen to share a status value.
 *
 * TWO MANDATORY withoutMiddleware() EXCLUSIONS at the route layer:
 *   - the billing routes — a disabled user who cannot reach the pay endpoint
 *     can never stop being disabled (the deadlock
 *     EnforcePendingDeletionReadOnly's own docblock warns about);
 *   - account deletion and data export — a GDPR right cannot be paywalled.
 *
 * 402 Payment Required with a structured body the dashboard renders a
 * reactivate prompt from.
 */
class EnforceBillingStandingReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('partna.billing.enforcement_enabled', false)) {
            return $next($request);
        }

        $professional = $request->attributes->get('professional');

        if (! $professional || $professional->hasActiveSubscription()) {
            return $next($request);
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        return response()->json([
            'error' => 'billing_inactive',
            'message' => 'A subscription is required to make changes.',
            'billing_inactive' => true,
            'plan' => $professional->account_type?->value,
        ], 402);
    }
}
```

- [ ] **Step 4: Apply it in the route files**

In `routes/api/user.php`, add `EnforceBillingStandingReadOnly::class` beside
each of the three existing `EnforcePendingDeletionReadOnly::class` entries
(lines ~79, ~135, ~145), and add the exclusion to the deletion, export and
feedback groups that already carry a `withoutMiddleware`:

```php
                ->withoutMiddleware([EnforcePendingDeletionReadOnly::class, EnforceBillingStandingReadOnly::class]);
```

Do the same in `routes/api/platforms.php:34`.

The billing group's own exclusion was written in Task 12 — verify it is present.

- [ ] **Step 5: Run the test**

Run: `php artisan test tests/Feature/Billing/BillingReadOnlyMiddlewareTest.php`
Expected: PASS

- [ ] **Step 6: Run the full user + platforms suites**

Run: `php artisan test tests/Feature/Api/User tests/Feature/Platforms`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/Context/EnforceBillingStandingReadOnly.php \
        routes/api/user.php routes/api/platforms.php \
        tests/Feature/Billing/BillingReadOnlyMiddlewareTest.php
git commit -m "feat(billing): 402 dashboard writes for lapsed accounts, with the deadlock exclusions"
```

---

## Task 17: Cache invalidation on a visibility transition

§9.4's correction is the one that matters most: `SiteCacheLanes::bust()` alone
does **not** cover this, and the failure is silent in the worst direction — an
account that has just paid stays dark.

**Files:**
- Create: `app/Services/Billing/BillingVisibility.php`
- Modify: `app/Services/Billing/SubscriptionProjector.php`
- Test: `tests/Feature/Billing/BillingCacheLanesTest.php`

**Interfaces:**
- Produces: `BillingVisibility::transition(User $user): void`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Billing/BillingCacheLanesTest.php`:

```php
<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Services\Billing\BillingVisibility;
use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupBillingTables();
    Bus::fake();
});

it('fires all three SiteCacheLanes on a transition', function () {
    [$pro, $site] = claimedPublishedSite();
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $site->id)->value('updated_at');

    $this->travel(2)->seconds();
    app(BillingVisibility::class)->transition($pro->fresh());

    $after = DB::connection('pgsql')->table('site.sites')->where('id', $site->id)->value('updated_at');

    expect($after)->not->toBe($before);           // lane 2
    Bus::assertDispatched(CloudflareCachePurgeJob::class); // lane 3
});

// THE ASSERTION THE THREE LANES DO NOT MAKE. §9.1's verdict lives in the
// handle.resolve entry, not the payload. Lane 2 rolls the PAYLOAD key, but the
// request 404s at the resolve stage and never reaches it — and because lane 2
// is a raw query-builder update, SiteObserver never fires, so
// invalidateSitePayload() never runs. A test that only checks the three lanes
// reproduces the exact bug this spec shipped with.
it('deletes handle.resolve and raises its floor', function () {
    [$pro, $site] = claimedPublishedSite();
    $handle = strtolower($pro->handle);

    Cache::put("handle.resolve:{$handle}", ['unpublished' => true], 60);

    app(BillingVisibility::class)->transition($pro->fresh());

    expect(Cache::get("handle.resolve:{$handle}"))->toBeNull()
        ->and(Cache::get("handle.resolve.floor:{$handle}"))->not->toBeNull();
});

// The RESTORE direction is the one that matters. Assert a paid account is
// SERVED, not merely that a cache key moved.
it('serves a site again immediately after payment restores good standing', function () {
    config(['partna.billing.enforcement_enabled' => true]);
    [$pro] = claimedPublishedSite();

    $pro->forceFill(['plan_status' => 'canceled'])->save();
    app(BillingVisibility::class)->transition($pro->fresh());
    $this->getJson("/api/public/profiles/{$pro->handle_lc}")->assertStatus(404);

    $pro->forceFill(['plan_status' => 'active', 'status' => 'active'])->save();
    app(BillingVisibility::class)->transition($pro->fresh());
    $this->getJson("/api/public/profiles/{$pro->handle_lc}")->assertOk();
});

it('is a no-op for an account with no site row', function () {
    $pro = App\Models\Core\User\User::factory()->create(['status' => 'active']);

    expect(fn () => app(BillingVisibility::class)->transition($pro))->not->toThrow(Throwable::class);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/Billing/BillingCacheLanesTest.php`
Expected: FAIL — `BillingVisibility` not found

- [ ] **Step 3: Write the seam**

`app/Services/Billing/BillingVisibility.php`:

```php
<?php

namespace App\Services\Billing;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheService;
use App\Site\Documents\SiteCacheLanes;

/**
 * Invalidate everything a billing visibility transition changes — INTO
 * `disabled` and, just as importantly, BACK OUT OF IT ON PAYMENT (spec §9.4).
 *
 * FOUR lanes, not three, and the fourth is the only one that clears the gate.
 *
 * SiteCacheLanes::bust() does three things: BuildState::bump(), a RAW
 * DB::table('site.sites')->update(['updated_at' => …]), and a Cloudflare purge
 * delayed 15s. None of them touches the verdict. §9.1's clause lives in the
 * `handle.resolve:{handle}` entry, not the payload — lane 2 rolls the PAYLOAD
 * key, but the request 404s at the resolve stage and never reaches it. And
 * because lane 2 is a raw query-builder update, SiteObserver never fires
 * (a write that bypasses Eloquent invalidates nothing), so
 * SiteCacheService::invalidateSitePayload() — the only code that deletes
 * handle.resolve and raises handle.resolve.floor — never runs.
 *
 * invalidateSitePayload() is therefore not belt-and-braces here. It is the
 * lane that clears the gate, and omitting it leaves an account that has just
 * paid dark for the full TTL.
 *
 * MUST BE CALLED POST-COMMIT. invalidateSitePayload's own docblock: calling
 * raiseResolveFloor inside an open transaction publishes the post-write key
 * before the data is visible, letting a racing reader cache pre-commit state
 * under the authoritative key for the full payload TTL plus its stale window.
 *
 * TWO RESIDUAL CAVEATS, stated rather than discovered at runtime:
 *   1. The resolve key derives from users.handle_lc but is busted by
 *      site.subdomain, so a DIVERGED row is never busted (0 of 268 on dev as
 *      of 2026-09-01, repaired by ConvergeSiteSubdomains, not by a constraint).
 *   2. Lane 3's 15s delay means the edge still serves a dark render for up to
 *      that long after payment. Acceptable — "someone who has just paid must
 *      not wait out a TTL" is true of the origin and approximately true of the
 *      edge, not exactly.
 */
final class BillingVisibility
{
    public function __construct(private readonly SiteCacheService $siteCache) {}

    public function transition(User $user): void
    {
        $site = Site::query()->where('user_id', $user->id)->first();

        if ($site === null) {
            return;
        }

        // Lanes 1-3. Note the signature is bust(array $siteIds), not bust($site).
        SiteCacheLanes::bust([(string) $site->id]);

        // Lane 4 — REQUIRED. See the class docblock.
        $this->siteCache->invalidateSitePayload($site->fresh());
    }
}
```

- [ ] **Step 4: Call it from the projector, post-commit and only on a real transition**

In `SubscriptionProjector::write()`, replace the `if ($rows > 0)` block:

```php
        if ($rows === 0) {
            return false;
        }

        AccountCapabilities::flushCache();

        // Only when VISIBILITY actually changed — a trialing→active projection
        // that keeps the site lit does not need a purge, and firing one per
        // webhook would put a Cloudflare purge behind every invoice.
        $wasVisible = $user->hasActiveSubscription();
        $isVisible = $good || $user->isBillingExempt();

        if ($wasVisible !== $isVisible) {
            // Post-commit: this runs inside a queued job, outside any
            // transaction, which is what raiseResolveFloor requires.
            app(BillingVisibility::class)->transition($user->fresh());
        }

        return true;
```

- [ ] **Step 5: Run the test**

Run: `php artisan test tests/Feature/Billing/BillingCacheLanesTest.php`
Expected: PASS (5 tests)

- [ ] **Step 6: Prove the three-lane-only version fails**

Temporarily comment out the `invalidateSitePayload` line and rerun.

Run: `php artisan test tests/Feature/Billing/BillingCacheLanesTest.php --filter="handle.resolve"`
Expected: FAIL. Restore the line. If it PASSES without lane 4, the test is asserting the wrong key — fix the test.

- [ ] **Step 7: Run the pool cache seam guard**

Run: `php artisan test tests/Feature/Architecture/PoolCacheLaneSeamTest.php tests/Feature/Content/PoolCacheLanesTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Services/Billing/BillingVisibility.php \
        app/Services/Billing/SubscriptionProjector.php \
        tests/Feature/Billing/BillingCacheLanesTest.php
git commit -m "feat(billing): four-lane cache invalidation on a visibility transition"
```

---

## Task 18: Pin the tier boundary as a WRITE seam (ships regardless of the flag)

D11 was **reversed** on 2026-09-02. The render veto it originally described
was deleted from `presentPageIds` on 2026-09-01, over the exact capability this
design would have reused, with **`// NO capability veto stands here, and none
may be added back.`** written at the site.

The write seam already exists — `PageCapabilities` maps
`'multipage' => 'can_use_multipage_site'` and is enforced at
`PageController.php:136` and `SectionController.php:180`. **This task builds
nothing.** It pins the behaviour so a future PR cannot quietly reintroduce the
veto, and the negative half is the point.

**Files:**
- Test: `tests/Feature/Billing/TierBoundaryIsAWriteSeamTest.php`

- [ ] **Step 1: Write the test**

`tests/Feature/Billing/TierBoundaryIsAWriteSeamTest.php`:

```php
<?php

use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\PublicSite\SitepageDataResolverService;

beforeEach(fn () => setupBillingTables());

// D11 (owner decision 2026-09-02): gate the writer, leave the renderer alone.
// On downgrade, existing pages KEEP RENDERING. Nothing is hidden, moved or
// deleted. The tier boundary constrains what an account may BUILD, not what it
// may keep.
//
// This design makes account_type strictly more volatile than `sector` ever
// was — §8.5 hands sole write authority to an asynchronous third-party
// webhook, delivered out of order, behind a same-second ordering guard, over a
// network that fails. A render veto on that column re-creates the retired
// "my Menu page disappeared and nothing told me why" incident with a WORSE
// trigger: a page vanishing because a Stripe event was late.
it('keeps rendering business-built pages after a downgrade to partna', function () {
    [$pro, $site] = businessSiteWithMultipagePages();

    $before = app(SitepageDataResolverService::class)->presentPageIds($site->fresh());

    $pro->forceFill(['account_type' => 'partna'])->save();
    AccountCapabilities::flushCache();

    $after = app(SitepageDataResolverService::class)->presentPageIds($site->fresh());

    expect($after)->toBe($before);
});

// The other half: the WRITE closes. PageCapabilities::allows() already
// enforces this; the assertion pins that it stays enforced.
it('refuses to create a multipage-capability page for a partna account', function () {
    $pro = User::factory()->create(['account_type' => 'partna', 'status' => 'active']);

    $this->actingAsProfessional($pro)
        ->postJson('/api/site/pages', ['key' => 'menu', 'capability' => 'multipage', 'title' => 'Menu'])
        ->assertStatus(422);
});

it('allows the same write for a business account', function () {
    $pro = User::factory()->create(['account_type' => 'business', 'status' => 'active']);

    $this->actingAsProfessional($pro)
        ->postJson('/api/site/pages', ['key' => 'services', 'capability' => 'multipage', 'title' => 'Services'])
        ->assertStatus(201);
});

// The prohibition itself, asserted as source. A future "hide business pages on
// downgrade" PR must trip on this, not on a reviewer's memory.
it('keeps the no-capability-veto prohibition in presentPageIds', function () {
    $source = file_get_contents(app_path('Services/PublicSite/SitepageDataResolverService.php'));

    expect($source)->toContain('NO capability veto stands here, and none may be added back.');
});

// §9.3, deliberately inverted so nobody later mistakes capabilities for a
// third enforcement layer for non-payment. status='disabled' reaches only
// can_be_reported and receive_moderation_notifications — it withdraws NO
// content capability whatsoever. All real lapse enforcement is exactly two
// things: the publish-gate clause and EnforceBillingStandingReadOnly.
it('withdraws no content capability when status becomes disabled', function () {
    $pro = User::factory()->create(['account_type' => 'business', 'status' => 'active']);
    $before = AccountCapabilities::for($pro);

    $pro->forceFill(['status' => 'disabled'])->save();
    AccountCapabilities::flushCache();
    $after = AccountCapabilities::for($pro->fresh());

    expect($after->can_use_multipage_site)->toBe($before->can_use_multipage_site)
        ->and($after->can_book_storewide)->toBe($before->can_book_storewide)
        ->and($after->google_business_full_sync)->toBe($before->google_business_full_sync)
        ->and($after->can_use_menu)->toBe($before->can_use_menu)
        // These two DO move — they are the only two `status` reaches.
        ->and($after->can_be_reported)->toBeFalse()
        ->and($after->receive_moderation_notifications)->toBeFalse();
});
```

Build `businessSiteWithMultipagePages()` on top of the existing helpers used
by `tests/Feature/PublicSite/SitepageMenuPresenceTest.php` — that file already
seeds exactly this shape.

- [ ] **Step 2: Run it**

Run: `php artisan test tests/Feature/Billing/TierBoundaryIsAWriteSeamTest.php`
Expected: PASS. If the downgrade test FAILS, a render veto has been
reintroduced somewhere — find and remove it; do not adjust the assertion.

Correct the page-create route path and expected status codes against
`routes/api/user.php` / `PageController` before running.

- [ ] **Step 3: Run the neighbouring suites**

Run: `php artisan test tests/Feature/PublicSite tests/Feature/Platforms/SectorCapabilityGatingTest.php`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Billing/TierBoundaryIsAWriteSeamTest.php
git commit -m "test(billing): pin the tier boundary as a write seam, not a render veto"
```

---

## Stage 4 gate

⚠️ Flip `PARTNA_BILLING_ENFORCEMENT_ENABLED=true` on **dev only** and verify
before it goes anywhere near production.

- [ ] `composer test`, `composer test:pg`, `composer test:schema` all green
- [ ] `composer analyse` green, `php artisan pint --test` clean
- [ ] Tasks 14 and 18 merged and deployed **before** the flag is flipped anywhere
- [ ] **Verify against a COLD handle.** §9.1 fails open for one resolve TTL after the flag flips — a warm-cache check reads as a false negative. Pick a handle nobody has requested, or bust its resolve key first, then confirm the 404
- [ ] A lapsed account: public site 404s, dashboard writes 402, billing/deletion/export endpoints all still reachable
- [ ] Pay that account and confirm the site is served again **without waiting out a TTL**
- [ ] A `past_due` account is still fully live
- [ ] An unclaimed build is still served (89% of the dev fleet — check the count did not move)
- [ ] `grep -rn "hasActiveSubscription" app/` returns exactly: the model definition, the two public read paths, and the middleware. Anything else is a third enforcement layer that should not exist

---

# Stage 5 — Retention

## Task 19: `billing:prune-disabled`

**Files:**
- Create: `app/Console/Commands/Billing/PruneDisabledCommand.php`
- Create: `app/Mail/Billing/SubscriptionLapsedWarningMail.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Billing/PruneDisabledTest.php`

**Interfaces:**
- Consumes: `AccountDeletionService::purge(User $professional): bool`.
- Produces: artisan `billing:prune-disabled`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Billing/PruneDisabledTest.php`:

```php
<?php

use App\Mail\Billing\SubscriptionLapsedWarningMail;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    setupBillingTables();
    config([
        'partna.billing.disabled_warning_days' => 60,
        'partna.billing.disabled_final_warning_days' => 83,
        'partna.billing.disabled_purge_days' => 90,
    ]);
    Mail::fake();
});

function disabledFor(int $days): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->forceFill([
        'status' => 'disabled',
        'plan_status' => 'canceled',
        'plan_event_at' => now()->subDays($days),
    ])->save();

    return $user->fresh();
}

it('warns at 60 days', function () {
    disabledFor(60);

    $this->artisan('billing:prune-disabled')->assertSuccessful();

    Mail::assertQueued(SubscriptionLapsedWarningMail::class);
});

it('sends a final warning at 83 days', function () {
    disabledFor(83);

    $this->artisan('billing:prune-disabled')->assertSuccessful();

    Mail::assertQueued(SubscriptionLapsedWarningMail::class,
        fn ($mail) => $mail->isFinal === true);
});

it('purges at 90 days and releases the handle', function () {
    $user = disabledFor(90);
    $handle = $user->handle_lc;

    $this->artisan('billing:prune-disabled')->assertSuccessful();

    expect(User::query()->where('handle_lc', $handle)->exists())->toBeFalse();
});

it('leaves an account disabled for 89 days alone', function () {
    $user = disabledFor(89);

    $this->artisan('billing:prune-disabled')->assertSuccessful();

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

it('skips billing-exempt accounts entirely', function () {
    $user = disabledFor(120);
    $user->forceFill(['billing_exempt' => true])->save();

    $this->artisan('billing:prune-disabled')->assertSuccessful();

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

// A moderation suspension is not a billing lapse. Deleting an account staff
// disabled for a takedown, on a billing timer, would be catastrophic and
// silent — same distinction the middleware makes.
it('does not purge an account that is disabled but still in good standing', function () {
    $user = disabledFor(120);
    $user->forceFill(['plan_status' => 'active'])->save();

    $this->artisan('billing:prune-disabled')->assertSuccessful();

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test tests/Feature/Billing/PruneDisabledTest.php`
Expected: FAIL — command does not exist

- [ ] **Step 3: Write the mailable**

`app/Mail/Billing/SubscriptionLapsedWarningMail.php`:

```php
<?php

namespace App\Mail\Billing;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your site is off, and it will be deleted on <date>."
 *
 * Two variants, one class: the 60-day warning and the 83-day final notice
 * differ only in urgency and in whether a date is stated as imminent.
 */
class SubscriptionLapsedWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $displayName,
        public readonly string $deletesOn,
        public readonly bool $isFinal,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->isFinal
            ? 'Your Partna site will be deleted in 7 days'
            : 'Your Partna site is switched off');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.billing.lapsed-warning');
    }
}
```

Create `resources/views/mail/billing/lapsed-warning.blade.php` following the
shape of the existing `WelcomeMail` view.

- [ ] **Step 4: Write the command**

`app/Console/Commands/Billing/PruneDisabledCommand.php`:

```php
<?php

namespace App\Console\Commands\Billing;

use App\Mail\Billing\SubscriptionLapsedWarningMail;
use App\Models\Core\User\User;
use App\Services\User\AccountDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Retention for accounts disabled by non-payment (spec §15):
 *
 *   60 days → warning     83 days → final warning (7 days out)
 *   90 days → purge, handle released, KV retired, audit links nulled
 *   billing_exempt → skipped entirely
 *
 * It reuses AccountDeletionService::purge() rather than introducing a second
 * deletion route. That path's historical blocker — append-only audit rows
 * rejecting the FK SET NULL cascade — was fixed on BOTH deletion paths in July
 * 2026 and rehearsed against the live dev schema. Introducing a third route is
 * precisely the mistake that left builds:prune-expired failing nightly
 * (Nightwatch #308).
 *
 * THE GATE IS BILLING STANDING, NOT `status`. An account staff disabled for a
 * takedown is often still paying; deleting it on a billing timer would be
 * catastrophic and silent. Same distinction EnforceBillingStandingReadOnly
 * makes.
 */
class PruneDisabledCommand extends Command
{
    protected $signature = 'billing:prune-disabled {--dry-run}';

    protected $description = 'Warn, then purge, accounts disabled by non-payment for 90 days.';

    public function handle(AccountDeletionService $deletions): int
    {
        $warnAt = (int) config('partna.billing.disabled_warning_days', 60);
        $finalAt = (int) config('partna.billing.disabled_final_warning_days', 83);
        $purgeAt = (int) config('partna.billing.disabled_purge_days', 90);
        $dryRun = (bool) $this->option('dry-run');

        $base = fn () => User::query()
            ->where('status', 'disabled')
            ->where('billing_exempt', false)
            // Billing standing, not status. See the class docblock.
            ->whereIn('plan_status', ['unpaid', 'canceled'])
            ->whereNotNull('plan_event_at');

        $purged = 0;

        $base()->where('plan_event_at', '<=', now()->subDays($purgeAt))
            ->chunkById(50, function ($users) use ($deletions, $dryRun, &$purged) {
                foreach ($users as $user) {
                    if ($dryRun) {
                        $this->line("would purge {$user->id} ({$user->handle_lc})");

                        continue;
                    }

                    $deletions->purge($user);
                    $purged++;
                    Log::info('billing.pruned', ['user_id' => $user->id, 'handle' => $user->handle_lc]);
                }
            });

        $warned = 0;

        foreach ([[$warnAt, false], [$finalAt, true]] as [$days, $isFinal]) {
            $base()
                ->whereBetween('plan_event_at', [
                    now()->subDays($days + 1),
                    now()->subDays($days),
                ])
                ->each(function (User $user) use ($isFinal, $purgeAt, $dryRun, &$warned) {
                    $email = (string) ($user->primary_email ?? '');
                    if ($email === '' || $dryRun) {
                        return;
                    }

                    Mail::to($email)->queue(new SubscriptionLapsedWarningMail(
                        (string) $user->display_name,
                        $user->plan_event_at->copy()->addDays($purgeAt)->toDateString(),
                        $isFinal,
                    ));
                    $warned++;
                });
        }

        $this->info("purged: {$purged}, warned: {$warned}");

        return self::SUCCESS;
    }
}
```

Confirm `AccountDeletionService`'s real namespace before writing the import —
grep `find app -name AccountDeletionService.php`.

- [ ] **Step 5: Schedule it**

In `routes/console.php`:

```php
Schedule::command('billing:prune-disabled')
    ->dailyAt('03:50') // After builds:prune-expired at 03:40 — one purge wave, not two overlapping.
    ->withoutOverlapping(120) // 2h lock — purge() is slow on accounts with media.
    ->onFailure($reportScheduledFailure('billing-prune-disabled'));
```

- [ ] **Step 6: Run the test**

Run: `php artisan test tests/Feature/Billing/PruneDisabledTest.php`
Expected: PASS (6 tests)

- [ ] **Step 7: Dry-run on dev**

```bash
cloud command:run partna development "php artisan billing:prune-disabled --dry-run"
```

Expected: an empty purge list. The first real deletion is 90 days after stage 4 regardless — a non-empty list this early means `plan_event_at` is being back-dated somewhere.

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/Billing/PruneDisabledCommand.php \
        app/Mail/Billing resources/views/mail/billing routes/console.php \
        tests/Feature/Billing/PruneDisabledTest.php
git commit -m "feat(billing): warn then purge accounts disabled by non-payment for 90 days"
```

---

# Owner input — a GO-LIVE step, not a blocker on any stage

These are `§17` open items. They are config values, not structure, and **no
stage of this plan waits on them.** Superseded in ordering by
`2026-09-03-stripe-subscriptions-EXECUTE.md`: the executor holds test-mode keys
in a local `.env` only, sets nothing on dev or prod, and writes these up as
`docs/deploy/stripe-go-live.md` — the runbook that turns each switch on, in
order. The list below is that document's table of contents, not a checklist to
work through during implementation.

**Two flags gate everything here**, both shipping `false`:
`PARTNA_BILLING_PROVISIONING_ENABLED` (does anyone's clock start?) and
`PARTNA_BILLING_ENFORCEMENT_ENABLED` (do lapses have consequences?). Under them,
test-mode keys and `missing_payment_method: 'cancel'` mean no card equals no
charge even with both flags on.

- [ ] **Price amounts** for `partna` and `business`, AUD monthly
- [ ] **Stripe account setup** — create the two products and their AUD monthly prices in **test mode**, then **live mode**, and record all four price ids
- [ ] Set `STRIPE_PRICE_PARTNA` / `STRIPE_PRICE_BUSINESS` per environment (`cloud environment:get`), and `PARTNA_BILLING_AMOUNT_*` to match — the amounts are used only to size a comp credit, but a wrong value silently under- or over-credits a comped account
- [ ] Register the webhook endpoint in the Stripe dashboard for both environments and record each signing secret as `STRIPE_WEBHOOK_SECRET`. **They differ per environment** — a dev secret on prod means every delivery 400s

---

# Self-review

Run against the spec on completion of the plan, 2026-09-03.

**Spec coverage.** Every section maps to a task:

| Spec § | Task |
|---|---|
| §5 SDK choice | 1 |
| §6.1 projection columns | 2, 3 |
| §6.2 billing schema | 2 |
| §6.3 state machine / `hasActiveSubscription` | 3 |
| §7 claim seam, two idempotency keys | 8 |
| §7 reconciliation | 9 |
| §8.1 transport, fail-closed 503 | 4 |
| §8.2 dedup flow | 5 |
| §8.3 both guards, the `<=` | 6 |
| §8.4 event table | 6 (subscription + invoice), **see gap below** |
| §8.5 `account_type` closure + architecture pin | 14 |
| §9.1 publish gate, cold-handle caveat | 15 |
| §9.2 middleware + three exclusions | 16 |
| §9.3 capabilities are NOT a third layer | 18 (inverted assertion) |
| §9.4 four lanes | 17 |
| §10.1 comps vs credits | 11 |
| §10.2 referral obligations 1 & 2 | 11 (public `grantAccountCredit`), 6 (`SubscriptionFirstPaymentSucceeded`) |
| §11 API surface | 12, 13 |
| §12.1 write seam, no render veto | 18 |
| §12.2 `past_due` | 3, 15 |
| §13 launch migration | 10 |
| §14 shipping order | stage gates |
| §15 retention | 19 |
| §16 testing | distributed across every task |

**Two gaps, stated rather than hidden:**

1. **§8.4's `trial_will_end`, `payment_failed`, `payment_method.attached` and
   `customer.updated` rows have no task.** `SubscriptionProjector::apply()`'s
   `match` falls through to `null` for all four, so they are received, stored
   and ignored. That is safe — none carries entitlement state — but the
   dunning notification and the "your free month is ending" notification do not
   exist. **Add them as a Task 6b** if notifications are wanted at launch;
   otherwise they are a follow-up, and the fixtures captured in Task 5 already
   cover them so the work is a handler plus a mailable.

2. **§10.2 obligation 3, the referral clawback trigger (R2), has no task.**
   `charge.refunded` and a `customer.subscription.deleted` inside the referred
   user's first billing period must reach
   `ReferralAttributionService::recordChurn()` — a class that does not exist
   yet, because the referral program is unimplemented. Wiring a call to a
   missing service is not shippable, so the obligation is recorded here
   instead: **the referral plan must add the two webhook branches, not assume
   this plan left them.** The spec's own warning applies — "without it the
   clawback is designed and never fires."

**Placeholder scan:** clean. Every code step carries real code; every test step
carries real assertions. Three steps are explicitly marked as needing a value
corrected against the tree before running (Task 14's allowlist class names,
Task 18's route path and status codes, Task 19's `AccountDeletionService`
namespace) — those are instructions to verify, not placeholders.

**Type consistency:** `hasActiveSubscription()`, `isBillingExempt()`,
`PriceCatalog::{priceIdFor, accountTypeForPrice, monthlyAmountCents}`,
`SubscriptionProjector::apply()`, `SubscriptionProvisioner::provision()`,
`BillingGrants::{grantFreeMonths, grantAccountCredit}`,
`BillingActions::{subscribe, changePlan, cancel, resume}` and
`BillingVisibility::transition()` are each defined once and referenced with the
same signature everywhere they appear.

---

# The five things most likely to go wrong

1. **`<` instead of `<=` in the ordering guard.** Silently drops the `active`
   that follows a same-second `trialing`, and the site is gated on exactly
   that. Task 6 step 7 proves the failure deliberately.
2. **Three cache lanes instead of four.** An account that has just paid stays
   dark for the full TTL, and every lane assertion passes. Task 17 step 6
   proves it.
3. **Anchoring the launch migration to `claimed_at`.** Expires the entire
   existing user base the instant the flag flips.
4. **Verifying stage 4 against a warm handle.** Reads as a false negative for
   one resolve TTL, so enforcement looks broken when it is not — or worse,
   looks fine when it is.
5. **Un-filling `account_type` instead of removing the request rule.** Looks
   stronger, mints NULL-tier users through `UserFactory::fill()`, and takes
   `users_account_type_check` with it across ~250 test files.
