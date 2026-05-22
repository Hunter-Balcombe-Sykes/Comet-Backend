-- Add BEFORE UPDATE set_updated_at triggers to the 13 mutable tables that were
-- created without them. Non-Eloquent write paths (raw DB::update, query-builder
-- bulk ops, trigger-fired side effects, Supabase dashboard edits) bypass PHP
-- timestamps — these triggers are the only reliable guarantor for those paths.
--
-- Analytics rollup tables (analytics.*_daily / *_hourly) are intentionally
-- excluded: their updated_at is set explicitly by the rollup maintainer functions.

CREATE OR REPLACE TRIGGER set_timestamp_services
    BEFORE UPDATE ON site.services
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE TRIGGER set_timestamp_enquiries
    BEFORE UPDATE ON site.enquiries
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE TRIGGER set_timestamp_brand_profiles
    BEFORE UPDATE ON brand.brand_profiles
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE TRIGGER set_timestamp_brand_partner_links
    BEFORE UPDATE ON brand.brand_partner_links
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE TRIGGER set_timestamp_brand_affiliate_invites
    BEFORE UPDATE ON brand.brand_affiliate_invites
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE TRIGGER set_timestamp_brand_store_settings
    BEFORE UPDATE ON brand.brand_store_settings
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE TRIGGER set_timestamp_affiliate_product_selections
    BEFORE UPDATE ON commerce.affiliate_product_selections
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE TRIGGER set_timestamp_notifications
    BEFORE UPDATE ON notifications.notifications
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE TRIGGER set_timestamp_notification_receipts
    BEFORE UPDATE ON notifications.notification_receipts
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE TRIGGER set_timestamp_notification_email_preferences
    BEFORE UPDATE ON notifications.notification_email_preferences
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE TRIGGER set_timestamp_notification_email_policies
    BEFORE UPDATE ON notifications.notification_email_policies
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE TRIGGER set_timestamp_email_subscriptions
    BEFORE UPDATE ON notifications.email_subscriptions
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE TRIGGER set_timestamp_gdpr_requests
    BEFORE UPDATE ON core.gdpr_requests
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
