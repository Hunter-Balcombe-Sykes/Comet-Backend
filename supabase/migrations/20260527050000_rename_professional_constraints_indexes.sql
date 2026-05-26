-- ==========================================================================
-- Rename FK constraints + indexes still containing 'professional_' (2026-05-26)
--
-- Cosmetic cleanup deferred by PR #125. All FK constraint names, CHECK
-- constraint names, unique constraint names, and standalone index names that
-- still embed 'professional_' get flipped to 'user_' for consistency with the
-- renamed columns/tables. No behavioural change — these are pure name changes
-- and the underlying objects (constraints, indexes) behave identically.
--
-- Notes:
--   - PG 12+ auto-renames indexes backed by renamed PRIMARY KEY / UNIQUE
--     constraints, so 6 of the 35 'professional_'-named indexes get renamed
--     transitively via the constraint renames below. Those are NOT listed in
--     the ALTER INDEX section.
--   - Foreign-key constraint renames do NOT touch any index (FKs don't own
--     indexes — they rely on the referenced table's PK).
--   - CHECK constraint values such as actor_type IN ('professional', ...) are
--     deliberately left as data — renaming a value requires a row-level
--     UPDATE and is a separate semantic question, not a naming cleanup.
-- ==========================================================================

BEGIN;

-- --------------------------------------------------------------------------
-- 1. Rename CONSTRAINTS (33)
-- --------------------------------------------------------------------------

-- analytics (6 FK)
ALTER TABLE analytics.lead_submissions    RENAME CONSTRAINT lead_submissions_professional_fk    TO lead_submissions_user_fk;
ALTER TABLE analytics.link_clicks         RENAME CONSTRAINT link_clicks_professional_fk         TO link_clicks_user_fk;
ALTER TABLE analytics.section_views       RENAME CONSTRAINT section_views_professional_fk       TO section_views_user_fk;
ALTER TABLE analytics.site_metrics_daily  RENAME CONSTRAINT site_metrics_daily_professional_fk  TO site_metrics_daily_user_fk;
ALTER TABLE analytics.site_metrics_hourly RENAME CONSTRAINT site_metrics_hourly_professional_fk TO site_metrics_hourly_user_fk;
ALTER TABLE analytics.site_visits         RENAME CONSTRAINT site_visits_professional_fk         TO site_visits_user_fk;

-- audit (3 FK + 1 PK + 2 CHECK on user_deletion_audit)
ALTER TABLE audit.data_export_audit  RENAME CONSTRAINT data_export_audit_professional_fk       TO data_export_audit_user_fk;
ALTER TABLE audit.handle_change_log  RENAME CONSTRAINT handle_change_log_professional_id_fkey  TO handle_change_log_user_id_fkey;
ALTER TABLE audit.staff_audit_log    RENAME CONSTRAINT staff_audit_log_professional_fk         TO staff_audit_log_user_fk;
ALTER TABLE audit.user_deletion_audit RENAME CONSTRAINT professional_deletion_audit_actor_type_check TO user_deletion_audit_actor_type_check;
ALTER TABLE audit.user_deletion_audit RENAME CONSTRAINT professional_deletion_audit_event_check      TO user_deletion_audit_event_check;
ALTER TABLE audit.user_deletion_audit RENAME CONSTRAINT professional_deletion_audit_pkey             TO user_deletion_audit_pkey;
ALTER TABLE audit.user_deletion_audit RENAME CONSTRAINT professional_deletion_audit_professional_fk  TO user_deletion_audit_user_fk;

-- core (1 FK + 3 on user_confirmation_preferences + 2 on user_handle_aliases)
ALTER TABLE core.feature_flag_overrides       RENAME CONSTRAINT feature_flag_overrides_professional_id_fkey TO feature_flag_overrides_user_id_fkey;
ALTER TABLE core.user_confirmation_preferences RENAME CONSTRAINT professional_confirmation_preferences_pkey                    TO user_confirmation_preferences_pkey;
ALTER TABLE core.user_confirmation_preferences RENAME CONSTRAINT professional_confirmation_preferences_professional_action_uq  TO user_confirmation_preferences_user_action_uq;
ALTER TABLE core.user_confirmation_preferences RENAME CONSTRAINT professional_confirmation_preferences_professional_fk         TO user_confirmation_preferences_user_fk;
ALTER TABLE core.user_handle_aliases           RENAME CONSTRAINT professional_handle_aliases_pkey                              TO user_handle_aliases_pkey;
ALTER TABLE core.user_handle_aliases           RENAME CONSTRAINT professional_handle_aliases_professional_id_fkey              TO user_handle_aliases_user_id_fkey;

-- notifications (6)
ALTER TABLE notifications.email_subscriptions            RENAME CONSTRAINT email_subscriptions_professional_fk                       TO email_subscriptions_user_fk;
ALTER TABLE notifications.notification_email_policies    RENAME CONSTRAINT notification_email_policies_professional_id_fkey          TO notification_email_policies_user_id_fkey;
ALTER TABLE notifications.notification_email_preferences RENAME CONSTRAINT notification_email_preferences_professional_category_uq   TO notification_email_preferences_user_category_uq;
ALTER TABLE notifications.notification_email_preferences RENAME CONSTRAINT notification_email_preferences_professional_id_fkey       TO notification_email_preferences_user_id_fkey;
ALTER TABLE notifications.notification_receipts          RENAME CONSTRAINT notification_receipts_professional_id_fkey                TO notification_receipts_user_id_fkey;
ALTER TABLE notifications.notifications                  RENAME CONSTRAINT notifications_professional_fk                             TO notifications_user_fk;

-- site (9)
ALTER TABLE site.blocks             RENAME CONSTRAINT blocks_professional_fk                          TO blocks_user_fk;
ALTER TABLE site.customers          RENAME CONSTRAINT customers_professional_fk                       TO customers_user_fk;
ALTER TABLE site.enquiries          RENAME CONSTRAINT enquiries_professional_fk                       TO enquiries_user_fk;
ALTER TABLE site.service_categories RENAME CONSTRAINT service_categories_id_professional_unique       TO service_categories_id_user_unique;
ALTER TABLE site.service_categories RENAME CONSTRAINT service_categories_professional_fk              TO service_categories_user_fk;
ALTER TABLE site.services           RENAME CONSTRAINT services_category_belongs_to_professional       TO services_category_belongs_to_user;
ALTER TABLE site.services           RENAME CONSTRAINT services_professional_fk                        TO services_user_fk;
ALTER TABLE site.sites              RENAME CONSTRAINT sites_professional_fk                           TO sites_user_fk;

-- --------------------------------------------------------------------------
-- 2. Rename INDEXES (29 — the 6 backed by PK/UNIQUE constraints renamed above
--    auto-rename via PG 12+ behaviour and are not listed here.)
-- --------------------------------------------------------------------------

-- analytics
ALTER INDEX analytics.analytics_link_clicks_professional_occurred_idx   RENAME TO analytics_link_clicks_user_occurred_idx;
ALTER INDEX analytics.section_views_professional_occurred_idx           RENAME TO section_views_user_occurred_idx;
ALTER INDEX analytics.site_metrics_daily_professional_day_idx           RENAME TO site_metrics_daily_user_day_idx;
ALTER INDEX analytics.site_metrics_hourly_professional_hour_idx         RENAME TO site_metrics_hourly_user_hour_idx;
ALTER INDEX analytics.analytics_site_visits_professional_occurred_idx   RENAME TO analytics_site_visits_user_occurred_idx;

-- audit
ALTER INDEX audit.data_export_audit_professional_status_created_idx     RENAME TO data_export_audit_user_status_created_idx;
ALTER INDEX audit.idx_staff_audit_log_professional_created              RENAME TO idx_staff_audit_log_user_created;
ALTER INDEX audit.idx_pda_professional_id                               RENAME TO idx_user_deletion_audit_user_id;

-- core (standalone indexes — pkeys + action_uq already renamed via constraint)
ALTER INDEX core.professional_confirmation_preferences_professional_idx RENAME TO user_confirmation_preferences_user_idx;
ALTER INDEX core.professional_handle_aliases_expires_at_idx             RENAME TO user_handle_aliases_expires_at_idx;
ALTER INDEX core.professional_handle_aliases_handle_lc_uq               RENAME TO user_handle_aliases_handle_lc_uq;
ALTER INDEX core.professional_handle_aliases_professional_idx           RENAME TO user_handle_aliases_user_idx;

-- notifications (standalone indexes — category_uq already renamed via constraint)
ALTER INDEX notifications.uq_notif_email_policies_per_professional      RENAME TO uq_notif_email_policies_per_user;
ALTER INDEX notifications.notification_receipts_notification_professional_uq RENAME TO notification_receipts_notification_user_uq;

-- site (standalone indexes — id_user_unique already renamed via constraint)
ALTER INDEX site.core_link_blocks_professional_sort_idx                 RENAME TO core_link_blocks_user_sort_idx;
ALTER INDEX site.customers_professional_deleted_at_idx                  RENAME TO customers_user_deleted_at_idx;
ALTER INDEX site.customers_professional_email_search_idx                RENAME TO customers_user_email_search_idx;
ALTER INDEX site.customers_professional_email_unique                    RENAME TO customers_user_email_unique;
ALTER INDEX site.customers_professional_id_idx                          RENAME TO customers_user_id_idx;
ALTER INDEX site.customers_professional_name_search_idx                 RENAME TO customers_user_name_search_idx;
ALTER INDEX site.customers_professional_phone_search_idx                RENAME TO customers_user_phone_search_idx;
ALTER INDEX site.customers_professional_phone_unique                    RENAME TO customers_user_phone_unique;
ALTER INDEX site.enquiries_professional_created_idx                     RENAME TO enquiries_user_created_idx;
ALTER INDEX site.service_categories_professional_sort_idx               RENAME TO service_categories_user_sort_idx;
ALTER INDEX site.service_categories_unique_title_per_professional       RENAME TO service_categories_unique_title_per_user;
ALTER INDEX site.services_professional_category_sort_idx                RENAME TO services_user_category_sort_idx;
ALTER INDEX site.services_professional_id_deleted_at_idx                RENAME TO services_user_id_deleted_at_idx;
ALTER INDEX site.services_professional_sort_order_uq                    RENAME TO services_user_sort_order_uq;
ALTER INDEX site.sites_professional_unique                              RENAME TO sites_user_unique;

COMMIT;
