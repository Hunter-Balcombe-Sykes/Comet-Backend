-- ==========================================================================
-- Enable RLS on all tables that were missing it in the v2 baseline.
-- Also adds policies for notifications.notifications / notification_receipts
-- (RLS was enabled but no policies were defined — default-deny made them
-- inaccessible to all non-superusers).
-- Covers brand.brand_partner_link_events from 20260420000000.
--
-- The app_backend role (used by Laravel's direct DB connection) is granted
-- BYPASSRLS so it is not subject to these policies — it already has full
-- table-level GRANT and is the trusted backend service account.
-- ==========================================================================

BEGIN;

-- app_backend is the Laravel service DB user; it must bypass RLS so that
-- queue workers, webhooks, and background jobs are never silently blocked.
ALTER ROLE app_backend BYPASSRLS;

-- ==========================================================================
-- core.waitlist_signups  (public sign-up form — no professional ownership)
-- ==========================================================================
ALTER TABLE core.waitlist_signups ENABLE ROW LEVEL SECURITY;

CREATE POLICY waitlist_public_insert ON core.waitlist_signups
    FOR INSERT TO anon WITH CHECK (true);

CREATE POLICY waitlist_staff_all ON core.waitlist_signups TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- core.professional_integrations  (OAuth tokens — strict ownership)
-- ==========================================================================
ALTER TABLE core.professional_integrations ENABLE ROW LEVEL SECURITY;

CREATE POLICY pro_integrations_pro_all ON core.professional_integrations TO authenticated
    USING (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY pro_integrations_staff_all ON core.professional_integrations TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- core.professional_confirmation_preferences
-- ==========================================================================
ALTER TABLE core.professional_confirmation_preferences ENABLE ROW LEVEL SECURITY;

CREATE POLICY confirmation_prefs_pro_all ON core.professional_confirmation_preferences TO authenticated
    USING (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY confirmation_prefs_staff_all ON core.professional_confirmation_preferences TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- site.media_variants  (inherits access rules of parent site_media)
-- ==========================================================================
ALTER TABLE site.media_variants ENABLE ROW LEVEL SECURITY;

CREATE POLICY media_variants_pro_all ON site.media_variants TO authenticated
    USING (EXISTS (
        SELECT 1 FROM site.site_media sm
        JOIN site.sites si ON si.id = sm.site_id
        JOIN core.professionals p ON p.id = si.professional_id
        WHERE sm.id = media_variants.media_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL
    ))
    WITH CHECK (EXISTS (
        SELECT 1 FROM site.site_media sm
        JOIN site.sites si ON si.id = sm.site_id
        JOIN core.professionals p ON p.id = si.professional_id
        WHERE sm.id = media_variants.media_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL
    ));

CREATE POLICY media_variants_staff_all ON site.media_variants TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY media_variants_public_read ON site.media_variants FOR SELECT TO anon
    USING (EXISTS (
        SELECT 1 FROM site.site_media sm
        JOIN site.sites si ON si.id = sm.site_id
        WHERE sm.id = media_variants.media_id AND sm.deleted_at IS NULL AND si.is_published = true
    ));

-- ==========================================================================
-- site.service_categories
-- ==========================================================================
ALTER TABLE site.service_categories ENABLE ROW LEVEL SECURITY;

CREATE POLICY service_categories_pro_all ON site.service_categories TO authenticated
    USING (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY service_categories_staff_all ON site.service_categories TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY service_categories_public_read ON site.service_categories FOR SELECT TO anon
    USING (deleted_at IS NULL AND EXISTS (
        SELECT 1 FROM site.sites si
        WHERE si.professional_id = service_categories.professional_id AND si.is_published = true
    ));

-- ==========================================================================
-- brand.brand_profiles
-- ==========================================================================
ALTER TABLE brand.brand_profiles ENABLE ROW LEVEL SECURITY;

CREATE POLICY brand_profiles_pro_all ON brand.brand_profiles TO authenticated
    USING (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

-- Linked affiliates can read their brand's profile (needed for dashboard display)
CREATE POLICY brand_profiles_affiliate_select ON brand.brand_profiles FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM brand.brand_partner_links l
        JOIN core.professionals p ON p.id = l.affiliate_professional_id
        WHERE l.brand_professional_id = brand_profiles.professional_id
          AND p.auth_user_id = auth.uid()
          AND p.deleted_at IS NULL
    ));

CREATE POLICY brand_profiles_staff_all ON brand.brand_profiles TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- brand.brand_partner_links
-- ==========================================================================
ALTER TABLE brand.brand_partner_links ENABLE ROW LEVEL SECURITY;

-- Both parties to a link can see it
CREATE POLICY partner_links_party_select ON brand.brand_partner_links FOR SELECT TO authenticated
    USING (
        affiliate_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY partner_links_brand_insert ON brand.brand_partner_links FOR INSERT TO authenticated
    WITH CHECK (
        brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

-- Either party can remove the link; staff can also remove
CREATE POLICY partner_links_party_delete ON brand.brand_partner_links FOR DELETE TO authenticated
    USING (
        affiliate_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY partner_links_staff_update ON brand.brand_partner_links FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- brand.brand_affiliate_invites
-- ==========================================================================
ALTER TABLE brand.brand_affiliate_invites ENABLE ROW LEVEL SECURITY;

CREATE POLICY affiliate_invites_brand_all ON brand.brand_affiliate_invites TO authenticated
    USING (brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY affiliate_invites_claimed_select ON brand.brand_affiliate_invites FOR SELECT TO authenticated
    USING (claimed_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY affiliate_invites_staff_all ON brand.brand_affiliate_invites TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- brand.brand_store_settings
-- ==========================================================================
ALTER TABLE brand.brand_store_settings ENABLE ROW LEVEL SECURITY;

CREATE POLICY store_settings_brand_all ON brand.brand_store_settings TO authenticated
    USING (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

-- Linked affiliates can read commission rate and hold days for their brand
CREATE POLICY store_settings_affiliate_select ON brand.brand_store_settings FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM brand.brand_partner_links l
        JOIN core.professionals p ON p.id = l.affiliate_professional_id
        WHERE l.brand_professional_id = professional_id
          AND p.auth_user_id = auth.uid()
          AND p.deleted_at IS NULL
    ));

CREATE POLICY store_settings_staff_all ON brand.brand_store_settings TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- brand.brand_team_memberships
-- ==========================================================================
ALTER TABLE brand.brand_team_memberships ENABLE ROW LEVEL SECURITY;

-- Brand owner and team members can both see memberships
CREATE POLICY team_memberships_party_select ON brand.brand_team_memberships FOR SELECT TO authenticated
    USING (
        brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR member_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY team_memberships_brand_write ON brand.brand_team_memberships FOR INSERT TO authenticated
    WITH CHECK (
        brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY team_memberships_brand_update ON brand.brand_team_memberships FOR UPDATE TO authenticated
    USING (
        brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    )
    WITH CHECK (
        brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY team_memberships_brand_delete ON brand.brand_team_memberships FOR DELETE TO authenticated
    USING (
        brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

-- ==========================================================================
-- brand.brand_partner_link_events  (audit log — append-only for app backend)
-- ==========================================================================
ALTER TABLE brand.brand_partner_link_events ENABLE ROW LEVEL SECURITY;

CREATE POLICY partner_link_events_party_select ON brand.brand_partner_link_events FOR SELECT TO authenticated
    USING (
        affiliate_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

-- app_backend inserts via BYPASSRLS; direct authenticated inserts blocked
CREATE POLICY partner_link_events_staff_insert ON brand.brand_partner_link_events FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- commerce.affiliate_product_selections
-- ==========================================================================
ALTER TABLE commerce.affiliate_product_selections ENABLE ROW LEVEL SECURITY;

CREATE POLICY product_selections_affiliate_all ON commerce.affiliate_product_selections TO authenticated
    USING (affiliate_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (affiliate_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

-- Brand can see which of their products their affiliates have selected
CREATE POLICY product_selections_brand_select ON commerce.affiliate_product_selections FOR SELECT TO authenticated
    USING (brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY product_selections_staff_all ON commerce.affiliate_product_selections TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- commerce.commission_ledger_entries  (financial record — both parties can read)
-- ==========================================================================
ALTER TABLE commerce.commission_ledger_entries ENABLE ROW LEVEL SECURITY;

CREATE POLICY ledger_entries_party_select ON commerce.commission_ledger_entries FOR SELECT TO authenticated
    USING (
        brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR affiliate_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

-- Ledger writes only via app_backend (BYPASSRLS) or staff for manual corrections
CREATE POLICY ledger_entries_staff_write ON commerce.commission_ledger_entries FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY ledger_entries_staff_update ON commerce.commission_ledger_entries FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- commerce.commission_payouts
-- ==========================================================================
ALTER TABLE commerce.commission_payouts ENABLE ROW LEVEL SECURITY;

CREATE POLICY payouts_party_select ON commerce.commission_payouts FOR SELECT TO authenticated
    USING (
        brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR affiliate_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY payouts_staff_write ON commerce.commission_payouts FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY payouts_staff_update ON commerce.commission_payouts FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- commerce.commission_payout_items
-- ==========================================================================
ALTER TABLE commerce.commission_payout_items ENABLE ROW LEVEL SECURITY;

CREATE POLICY payout_items_party_select ON commerce.commission_payout_items FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM commerce.commission_payouts cp
        WHERE cp.id = payout_id
          AND (
              cp.brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
              OR cp.affiliate_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
              OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
          )
    ));

CREATE POLICY payout_items_staff_insert ON commerce.commission_payout_items FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- commerce.brand_commission_topups
-- ==========================================================================
ALTER TABLE commerce.brand_commission_topups ENABLE ROW LEVEL SECURITY;

CREATE POLICY commission_topups_brand_select ON commerce.brand_commission_topups FOR SELECT TO authenticated
    USING (
        brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY commission_topups_staff_write ON commerce.brand_commission_topups FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY commission_topups_staff_update ON commerce.brand_commission_topups FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- notifications.notifications  (RLS was already enabled but had no policies)
-- professional_id IS NULL means broadcast notification visible to all pros
-- ==========================================================================
CREATE POLICY notifications_pro_select ON notifications.notifications FOR SELECT TO authenticated
    USING (
        professional_id IS NULL
        OR professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY notifications_staff_write ON notifications.notifications FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY notifications_staff_update ON notifications.notifications FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY notifications_staff_delete ON notifications.notifications FOR DELETE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- notifications.notification_receipts  (RLS was already enabled but had no policies)
-- ==========================================================================
CREATE POLICY notification_receipts_pro_all ON notifications.notification_receipts TO authenticated
    USING (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY notification_receipts_staff_all ON notifications.notification_receipts TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- notifications.notification_email_preferences
-- ==========================================================================
ALTER TABLE notifications.notification_email_preferences ENABLE ROW LEVEL SECURITY;

CREATE POLICY email_prefs_pro_all ON notifications.notification_email_preferences TO authenticated
    USING (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY email_prefs_staff_all ON notifications.notification_email_preferences TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- notifications.notification_email_policies  (system config — staff + pro read)
-- professional_id IS NULL = global defaults readable by all authenticated pros
-- ==========================================================================
ALTER TABLE notifications.notification_email_policies ENABLE ROW LEVEL SECURITY;

CREATE POLICY email_policies_read ON notifications.notification_email_policies FOR SELECT TO authenticated
    USING (
        professional_id IS NULL
        OR professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY email_policies_staff_write ON notifications.notification_email_policies FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY email_policies_staff_update ON notifications.notification_email_policies FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY email_policies_staff_delete ON notifications.notification_email_policies FOR DELETE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- analytics.booking_events
-- brand_professional_id is nullable — brand pro can see events for their brand
-- ==========================================================================
ALTER TABLE analytics.booking_events ENABLE ROW LEVEL SECURITY;

CREATE POLICY booking_events_pro_select ON analytics.booking_events FOR SELECT TO authenticated
    USING (
        professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY booking_events_staff_insert ON analytics.booking_events FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- analytics.site_metrics_daily
-- ==========================================================================
ALTER TABLE analytics.site_metrics_daily ENABLE ROW LEVEL SECURITY;

CREATE POLICY site_metrics_daily_pro_select ON analytics.site_metrics_daily FOR SELECT TO authenticated
    USING (
        professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY site_metrics_daily_staff_write ON analytics.site_metrics_daily FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY site_metrics_daily_staff_update ON analytics.site_metrics_daily FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- analytics.booking_metrics_daily
-- ==========================================================================
ALTER TABLE analytics.booking_metrics_daily ENABLE ROW LEVEL SECURITY;

CREATE POLICY booking_metrics_daily_pro_select ON analytics.booking_metrics_daily FOR SELECT TO authenticated
    USING (
        professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY booking_metrics_daily_staff_write ON analytics.booking_metrics_daily FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY booking_metrics_daily_staff_update ON analytics.booking_metrics_daily FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- analytics.brand_metrics_daily
-- ==========================================================================
ALTER TABLE analytics.brand_metrics_daily ENABLE ROW LEVEL SECURITY;

CREATE POLICY brand_metrics_daily_pro_select ON analytics.brand_metrics_daily FOR SELECT TO authenticated
    USING (
        brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY brand_metrics_daily_staff_write ON analytics.brand_metrics_daily FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY brand_metrics_daily_staff_update ON analytics.brand_metrics_daily FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- analytics.brand_commission_daily  (both brand and affiliate parties can read)
-- ==========================================================================
ALTER TABLE analytics.brand_commission_daily ENABLE ROW LEVEL SECURITY;

CREATE POLICY brand_commission_daily_party_select ON analytics.brand_commission_daily FOR SELECT TO authenticated
    USING (
        brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR affiliate_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY brand_commission_daily_staff_write ON analytics.brand_commission_daily FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY brand_commission_daily_staff_update ON analytics.brand_commission_daily FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- analytics.brand_affiliate_daily
-- ==========================================================================
ALTER TABLE analytics.brand_affiliate_daily ENABLE ROW LEVEL SECURITY;

CREATE POLICY brand_affiliate_daily_party_select ON analytics.brand_affiliate_daily FOR SELECT TO authenticated
    USING (
        brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR affiliate_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY brand_affiliate_daily_staff_write ON analytics.brand_affiliate_daily FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY brand_affiliate_daily_staff_update ON analytics.brand_affiliate_daily FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- analytics.professional_metrics_daily  (affiliate's own commission analytics)
-- ==========================================================================
ALTER TABLE analytics.professional_metrics_daily ENABLE ROW LEVEL SECURITY;

CREATE POLICY professional_metrics_daily_pro_select ON analytics.professional_metrics_daily FOR SELECT TO authenticated
    USING (
        affiliate_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY professional_metrics_daily_staff_write ON analytics.professional_metrics_daily FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY professional_metrics_daily_staff_update ON analytics.professional_metrics_daily FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- analytics.professional_customer_daily
-- ==========================================================================
ALTER TABLE analytics.professional_customer_daily ENABLE ROW LEVEL SECURITY;

CREATE POLICY professional_customer_daily_pro_select ON analytics.professional_customer_daily FOR SELECT TO authenticated
    USING (
        affiliate_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY professional_customer_daily_staff_write ON analytics.professional_customer_daily FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY professional_customer_daily_staff_update ON analytics.professional_customer_daily FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- analytics.site_metrics_hourly
-- ==========================================================================
ALTER TABLE analytics.site_metrics_hourly ENABLE ROW LEVEL SECURITY;

CREATE POLICY site_metrics_hourly_pro_select ON analytics.site_metrics_hourly FOR SELECT TO authenticated
    USING (
        professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY site_metrics_hourly_staff_write ON analytics.site_metrics_hourly FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY site_metrics_hourly_staff_update ON analytics.site_metrics_hourly FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- analytics.booking_metrics_hourly
-- ==========================================================================
ALTER TABLE analytics.booking_metrics_hourly ENABLE ROW LEVEL SECURITY;

CREATE POLICY booking_metrics_hourly_pro_select ON analytics.booking_metrics_hourly FOR SELECT TO authenticated
    USING (
        professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY booking_metrics_hourly_staff_write ON analytics.booking_metrics_hourly FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY booking_metrics_hourly_staff_update ON analytics.booking_metrics_hourly FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- analytics.brand_metrics_hourly
-- ==========================================================================
ALTER TABLE analytics.brand_metrics_hourly ENABLE ROW LEVEL SECURITY;

CREATE POLICY brand_metrics_hourly_pro_select ON analytics.brand_metrics_hourly FOR SELECT TO authenticated
    USING (
        brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY brand_metrics_hourly_staff_write ON analytics.brand_metrics_hourly FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY brand_metrics_hourly_staff_update ON analytics.brand_metrics_hourly FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- analytics.professional_metrics_hourly
-- ==========================================================================
ALTER TABLE analytics.professional_metrics_hourly ENABLE ROW LEVEL SECURITY;

CREATE POLICY professional_metrics_hourly_pro_select ON analytics.professional_metrics_hourly FOR SELECT TO authenticated
    USING (
        affiliate_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY professional_metrics_hourly_staff_write ON analytics.professional_metrics_hourly FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY professional_metrics_hourly_staff_update ON analytics.professional_metrics_hourly FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- public.failed_jobs  (Laravel queue infrastructure — internal only)
-- ==========================================================================
ALTER TABLE public.failed_jobs ENABLE ROW LEVEL SECURITY;

CREATE POLICY failed_jobs_staff_all ON public.failed_jobs TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- public.job_batches  (Laravel queue infrastructure — internal only)
-- ==========================================================================
ALTER TABLE public.job_batches ENABLE ROW LEVEL SECURITY;

CREATE POLICY job_batches_staff_all ON public.job_batches TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

COMMIT;
