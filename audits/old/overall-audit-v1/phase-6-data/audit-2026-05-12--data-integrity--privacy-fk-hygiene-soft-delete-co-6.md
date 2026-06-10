`★ Insight ─────────────────────────────────────`
**The most critical finding here isn't in either DeepSeek draft** — it's a schema/code drift: the `stripe_connect_status` CHECK constraint never had `'disconnected'` added when the disconnect path was built. This is exactly the category of silent data-layer bug this lens targets: DB says "illegal value," code writes it anyway, crash on a real path, no test catches it because it's pre-beta.

The `brand_status` column shows the *correct* pattern: when the redesign added `'disconnected'`, migration `20260505000000` explicitly dropped and re-created the constraint. That procedure was simply never applied to `stripe_connect_status`.
`─────────────────────────────────────────────────`

Now producing the final audit:

---

# Data Integrity & Privacy Audit — 2026-05-12

**Branch:** development
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- supabase/migrations/20260403000000_v2_baseline.sql
- supabase/migrations/20260423000001_create_gdpr_requests.sql
- supabase/migrations/20260425000002_create_data_export_audit.sql
- supabase/migrations/20260505000000_redesign_brand_status_stages.sql
- app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyShopUpdateWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyThemePublishedWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrderWebhookController.php
- app/Services/Stripe/StripeConnectService.php

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P0 — Must fix before any real user touches the system

- [x] **#DATA-1** · P0 — `stripe_connect_status` CHECK constraint missing `'disconnected'` — disconnect path always crashes — **Closed by PR #39 (`012285c`), 2026-05-13. Migration `20260513100000_add_disconnected_to_stripe_connect_status_check.sql` applied to dev; prod pending next `development → production` promotion.**
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql:240 (constraint definition) / app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php:207 / app/Services/Stripe/StripeConnectService.php:273
    - **Affects:** Every affiliate who revokes Stripe access — either from the Partna dashboard (via `disconnectAccount()`) or from their Stripe dashboard (which fires `account.application.deauthorized`). Both code paths write `'disconnected'`, which the DB rejects. The disconnect can never complete, and the Stripe webhook permanently fails with repeated 500s.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a migration that drops `pro_stripe_connect_status_check` and re-creates it with `'disconnected'` included, following the exact pattern used in `20260505000000_redesign_brand_status_stages.sql` for `brand_status`.
        - Confirm all values the application writes appear in the new constraint: `'not_connected'`, `'onboarding'`, `'active'`, `'restricted'`, `'disconnected'`.
        - Verify `StripeConnectService::determineAccountStatus()` returns only values in that set (confirmed: returns `'active'`, `'restricted'`, or `'onboarding'` — all valid).
    - **Technical:** The baseline migration (`20260403000000_v2_baseline.sql:240`) defines `CONSTRAINT pro_stripe_connect_status_check CHECK (stripe_connect_status IN ('not_connected', 'onboarding', 'active', 'restricted'))`. No subsequent migration drops or modifies this constraint (confirmed via full grep of `supabase/migrations/`). Both `StripeConnectWebhookController::handleAccountDeauthorized()` (line 207) and `StripeConnectService::disconnectAccount()` (line 273) call `$professional->update(['stripe_connect_status' => 'disconnected'])`. Postgres evaluates the CHECK constraint on every UPDATE — this write always raises a `check_violation` (`23514`) exception. The service method raises a 500 to the API caller; the webhook handler raises a 500 to Stripe, which retries the `account.application.deauthorized` webhook indefinitely and never marks the affiliate disconnected locally. The comparison pattern was done correctly for `brand_status` in migration `20260505000000`: `DROP CONSTRAINT IF EXISTS` + `ADD CONSTRAINT ... CHECK (..., 'disconnected', ...)`. The same procedure is simply absent for `stripe_connect_status`. Additionally, `StripeConnectService` reads `if ($professional->stripe_connect_status === 'disconnected')` at lines 138 and 192 to gate re-onboarding and status-fetch behaviour — this guard is permanently unreachable while the write fails.
    - **Plain English:** Your system has a list of allowed states for each affiliate's Stripe connection — like a bouncer with a guest list. When the code tries to move an affiliate to "disconnected" (either because they click Disconnect in the dashboard, or because Stripe tells you they've pulled the plug), the database bouncer checks its list, doesn't see "disconnected" on it, and refuses the update. The operation crashes every single time. The affiliate stays stuck in whatever state they were in, the dashboard never updates, and Stripe keeps retrying the notification every few hours forever. It's a short fix — add "disconnected" to the guest list — but until it's done, the entire account-disconnect path is broken.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260403000000_v2_baseline.sql:240
        CONSTRAINT pro_stripe_connect_status_check CHECK (stripe_connect_status IN ('not_connected', 'onboarding', 'active', 'restricted')),
        ```
        ```php
        // app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php:207
        // handleAccountDeauthorized — fired on account.application.deauthorized webhook
        $professional->update(['stripe_connect_status' => 'disconnected']);
        ```
        ```php
        // app/Services/Stripe/StripeConnectService.php:270-274
        public function disconnectAccount(Professional $professional): void
        {
            $professional->update([
                'stripe_connect_status' => 'disconnected',
            ]);
        }
        ```
        ```sql
        -- Correct pattern already applied to brand_status (20260505000000_redesign_brand_status_stages.sql):
        ALTER TABLE brand.brand_profiles
          DROP CONSTRAINT IF EXISTS chk_brand_profiles_brand_status;
        ALTER TABLE brand.brand_profiles
          ADD CONSTRAINT chk_brand_profiles_brand_status
          CHECK (brand_status IN (
            'onboarding', 'shopify_linked', 'shopify_configured',
            'storefront_live', 'ready_for_affiliates', 'disconnected', 'systems_down'
          ));
        ```

---

## P2 — Should fix

- [ ] **#DATA-2** · P2 — Shopify `shop/update` and `themes/publish` webhooks have Redis-only dedup; no DB-level idempotency guard
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyShopUpdateWebhookController.php:60-64 / app/Http/Controllers/Api/Webhooks/ShopifyThemePublishedWebhookController.php:55
    - **Affects:** Brand profile re-sync (`ProcessShopifyShopUpdateJob`) and design-token import (`SyncShopifyBrandDesignJob`). If Redis is flushed between webhook delivery and processing (deploy, cache clear, eviction), Shopify's at-least-once redelivery bypasses the dedup guard and dispatches duplicate jobs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pass `$webhookId` into `ProcessShopifyShopUpdateJob` and `SyncShopifyBrandDesignJob` as an explicit idempotency key, and use `Cache::add()` inside the job (same pattern used in the order webhook family).
        - Alternatively, adopt the GDPR webhook pattern: `firstOrCreate` on a `shopify_webhook_events` table using `webhook_id` as the unique key, so dedup survives Redis loss.
        - The `$webhookId` header is already extracted in both controllers — it just isn't forwarded to the job.
    - **Technical:** The order-webhook family (`ShopifyOrderWebhookController`, `ShopifyOrdersCancelledWebhookController`, `ShopifyOrdersEditedWebhookController`, `ShopifyOrdersUpdatedWebhookController`, `ShopifyRefundsCreateWebhookController`) passes `$eventId` into their jobs for durable DB-level dedup inside `ProcessShopifyOrderWebhookJob` / `ProcessShopifyOrderUpdatedWebhookJob`. The docblock in `ShopifyOrderWebhookController` explicitly notes "durable DB-level idempotency." Both `ShopifyShopUpdateWebhookController` and `ShopifyThemePublishedWebhookController` extract `$webhookId` from the header but do not pass it to the dispatched job — the job receives only `professional_id` + `payload` or `integration->id`. A Redis flush (common during deploys) clears the `Cache::add` dedup key; the next Shopify retry creates a second job dispatch with no guard. Because these jobs perform sync operations (re-importing shop profile or design tokens from Shopify), double-processing is likely idempotent at the data level, which is why this is P2 rather than P1. The risk is stale-data overwrites if two concurrent deliveries race: job A reads current Shopify state → job B reads the same state → both write → one silently wins. Under load this is benign; the concern is a delayed redelivery arriving after a brand has manually customised settings.
    - **Plain English:** Most Shopify notifications in the system have two locks: one fast lock in memory (Redis) and one permanent lock in the database. The permanent lock means even if the memory lock disappears — say, during a server restart — a duplicate notification can't slip through. But the "shop updated" and "theme published" notifications only have the memory lock. If memory is cleared, those notifications can run twice. It's unlikely to cause visible data problems most of the time (both runs would import the same information), but if a duplicate arrives late — after a brand has made manual changes — it could silently overwrite them. Adding the permanent lock costs under an hour and brings these two endpoints in line with the rest of the Shopify webhook family.
    - **Evidence:**
        ```php
        // ShopifyShopUpdateWebhookController.php — $webhookId extracted but not forwarded
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');
        // ...
        ProcessShopifyShopUpdateJob::dispatch(
            (string) $integration->professional_id,
            $payload
        );

        // ShopifyThemePublishedWebhookController.php — $webhookId extracted but not forwarded
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');
        // ...
        SyncShopifyBrandDesignJob::dispatch((string) $integration->id);

        // Contrast — ShopifyOrderWebhookController.php correctly passes $eventId for DB-level dedup:
        ProcessShopifyOrderWebhookJob::dispatch(
            (string) $integration->professional_id,
            $payload,
            $eventId,
        );
        ```
