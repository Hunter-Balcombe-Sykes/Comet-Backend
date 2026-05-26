-- The v2 baseline (20260403000000_v2_baseline.sql) defined
-- pro_stripe_connect_status_check with values
-- ('not_connected', 'onboarding', 'active', 'restricted')
-- but StripeConnectService::disconnectAccount() and the
-- account.application.deauthorized webhook handler both write
-- 'disconnected' — preserving stripe_connect_account_id for a one-click
-- soft-reconnect. The constraint rejected the write, causing 500s on the
-- POST /stripe/connect/disconnect endpoint and on the deauth webhook.
--
-- Drop and re-add the constraint with 'disconnected' included.

ALTER TABLE core.professionals
    DROP CONSTRAINT IF EXISTS pro_stripe_connect_status_check;

ALTER TABLE core.professionals
    ADD CONSTRAINT pro_stripe_connect_status_check
    CHECK (stripe_connect_status IN ('not_connected', 'onboarding', 'active', 'restricted', 'disconnected'));
