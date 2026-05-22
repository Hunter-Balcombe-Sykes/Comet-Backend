-- Master Pattern 23: CHECK constraint sweep on enum-like columns.
--
-- Eight columns treated as application-level enums had no DB-level constraint.
-- A typo or raw DB::update() could write an unrecognised value that silently
-- falls through to a "no permissions / no pool / unsubscribed" state with no
-- error. This migration locks each column to its valid set using the two-step
-- NOT VALID + VALIDATE pattern (§2 of CONVENTIONS.md) to avoid write downtime.
--
-- Step 1: ADD CONSTRAINT ... NOT VALID — lock-light, instant, blocks only new writes.
-- Step 2: VALIDATE CONSTRAINT — sequential scan in a separate transaction, weaker lock.

-- ─── Step 1: add all constraints as NOT VALID ───────────────────────────────

BEGIN;

-- site.blocks.block_type
-- Valid values: 'link' (link blocks) + all section types from config/partna.php.
ALTER TABLE site.blocks
    ADD CONSTRAINT blocks_block_type_check
    CHECK (block_type IN (
        'link',
        'gallery', 'services', 'shop', 'booking', 'contacts_collection',
        'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter',
        'countdown', 'contact', 'credentials', 'experience', 'bio'
    )) NOT VALID;

-- site.site_media.pool
-- Valid values: all POOL_* constants from SiteMedia model.
ALTER TABLE site.site_media
    ADD CONSTRAINT site_media_pool_check
    CHECK (pool IN (
        'gallery', 'content', 'design', 'product', 'brand_gallery', 'documents'
    )) NOT VALID;

-- billing.subscriptions.status
-- Valid values: all STATUS_* constants from Subscription model.
-- 'trialing' is intentionally excluded — the Stripe webhook maps it to 'incomplete'
-- because Partna does not use trials (see StripeWebhookController).
ALTER TABLE billing.subscriptions
    ADD CONSTRAINT subscriptions_status_check
    CHECK (status IN (
        'active', 'past_due', 'unpaid', 'canceled',
        'incomplete', 'incomplete_expired', 'paused'
    )) NOT VALID;

-- commerce.commission_movements.rate_source
-- Drop the legacy not-blank-only CHECK (from the v2 baseline, preserved through the
-- commission_ledger_entries→commission_movements rename) and replace it with a full
-- enum constraint matching commerce.orders.chk_orders_rate_source.
-- @see 20260510400000_extend_orders_rate_source_constraint.sql
ALTER TABLE commerce.commission_movements
    DROP CONSTRAINT IF EXISTS commission_ledger_rate_source_not_blank;

ALTER TABLE commerce.commission_movements
    ADD CONSTRAINT commission_movements_rate_source_check
    CHECK (rate_source IN (
        'product_metafield', 'metafield_override', 'brand_default',
        'platform_default', 'manual', 'pending'
    )) NOT VALID;

-- core.professional_integrations.provider
-- Valid values: all PROVIDER_* constants from ProfessionalIntegration model.
-- 'square' and 'fresha' are included because the constants + DB rows may exist
-- historically, even though booking-based integrations are no longer being built.
ALTER TABLE core.professional_integrations
    ADD CONSTRAINT professional_integrations_provider_check
    CHECK (provider IN ('shopify', 'square', 'fresha')) NOT VALID;

-- notifications.email_subscriptions.status
-- Valid values: subscribed / unsubscribed.
ALTER TABLE notifications.email_subscriptions
    ADD CONSTRAINT email_subscriptions_status_check
    CHECK (status IN ('subscribed', 'unsubscribed')) NOT VALID;

-- core.partna_staff.role
-- Valid values: admin / support.
ALTER TABLE core.partna_staff
    ADD CONSTRAINT partna_staff_role_check
    CHECK (role IN ('admin', 'support')) NOT VALID;

-- core.brand_status_history.from_status / to_status
-- from_status IS NULL is valid (the first transition has no prior state).
-- All BrandStatus enum values from app/Enums/BrandStatus.php.
ALTER TABLE core.brand_status_history
    ADD CONSTRAINT brand_status_history_from_status_check
    CHECK (from_status IS NULL OR from_status IN (
        'onboarding', 'shopify_linked', 'shopify_configured', 'storefront_live',
        'ready_for_affiliates', 'disconnected', 'systems_down'
    )) NOT VALID;

ALTER TABLE core.brand_status_history
    ADD CONSTRAINT brand_status_history_to_status_check
    CHECK (to_status IN (
        'onboarding', 'shopify_linked', 'shopify_configured', 'storefront_live',
        'ready_for_affiliates', 'disconnected', 'systems_down'
    )) NOT VALID;

COMMIT;

-- ─── Step 2: validate each constraint (separate transactions) ───────────────
-- Each VALIDATE does a sequential scan with a weaker lock than ADD CONSTRAINT.
-- Run one at a time — if any scan finds a bad row, it fails here (not in step 1).

ALTER TABLE site.blocks VALIDATE CONSTRAINT blocks_block_type_check;
ALTER TABLE site.site_media VALIDATE CONSTRAINT site_media_pool_check;
ALTER TABLE billing.subscriptions VALIDATE CONSTRAINT subscriptions_status_check;
ALTER TABLE commerce.commission_movements VALIDATE CONSTRAINT commission_movements_rate_source_check;
ALTER TABLE core.professional_integrations VALIDATE CONSTRAINT professional_integrations_provider_check;
ALTER TABLE notifications.email_subscriptions VALIDATE CONSTRAINT email_subscriptions_status_check;
ALTER TABLE core.partna_staff VALIDATE CONSTRAINT partna_staff_role_check;
ALTER TABLE core.brand_status_history VALIDATE CONSTRAINT brand_status_history_from_status_check;
ALTER TABLE core.brand_status_history VALIDATE CONSTRAINT brand_status_history_to_status_check;

-- DOWN (manual rollback — supabase db push is one-way; run in SQL editor if needed):
-- ALTER TABLE site.blocks DROP CONSTRAINT IF EXISTS blocks_block_type_check;
-- ALTER TABLE site.site_media DROP CONSTRAINT IF EXISTS site_media_pool_check;
-- ALTER TABLE billing.subscriptions DROP CONSTRAINT IF EXISTS subscriptions_status_check;
-- ALTER TABLE commerce.commission_movements DROP CONSTRAINT IF EXISTS commission_movements_rate_source_check;
-- ALTER TABLE core.professional_integrations DROP CONSTRAINT IF EXISTS professional_integrations_provider_check;
-- ALTER TABLE notifications.email_subscriptions DROP CONSTRAINT IF EXISTS email_subscriptions_status_check;
-- ALTER TABLE core.partna_staff DROP CONSTRAINT IF EXISTS partna_staff_role_check;
-- ALTER TABLE core.brand_status_history DROP CONSTRAINT IF EXISTS brand_status_history_from_status_check;
-- ALTER TABLE core.brand_status_history DROP CONSTRAINT IF EXISTS brand_status_history_to_status_check;
-- Restore old not-blank constraint: ALTER TABLE commerce.commission_movements ADD CONSTRAINT commission_ledger_rate_source_not_blank CHECK (btrim(rate_source) <> '');
