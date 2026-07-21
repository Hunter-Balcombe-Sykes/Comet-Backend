<?php

namespace Tests\Feature\User\DataExport;

use Illuminate\Support\Facades\DB;

// Shared SQLite schema setup for data-export feature tests.
// Mirrors AccountDeletionTestCase — attaches each schema as its own
// in-memory DB so schema-qualified table names (core.*, site.*, brand.*, etc.) resolve.
class DataExportTestCase
{
    public static function boot(): void
    {
        $sqlite = config('database.connections.sqlite');
        config([
            'database.default' => 'sqlite',
            'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
            'partna.gdpr.queue' => 'gdpr',
            'partna.gdpr.signed_url_ttl_days' => 7,
            'partna.gdpr.dedup_window_minutes' => 30,
            'partna.media_disk' => 'media',
        ]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $conn = DB::connection('pgsql');

        foreach (['core', 'commerce', 'notifications', 'billing', 'site', 'analytics', 'brand', 'audit', 'moderation'] as $schema) {
            try {
                $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
            } catch (\Throwable) {
            }
        }

        $conn->statement('CREATE TABLE IF NOT EXISTS core.users (
            id TEXT PRIMARY KEY,
            auth_user_id TEXT,
            handle TEXT,
            handle_lc TEXT,
            display_name TEXT,
            primary_email TEXT,
            public_contact_email TEXT,
            professional_type TEXT DEFAULT "professional",
            account_type TEXT NULL CHECK (account_type IN (\'partna\',\'business\')),
            status TEXT DEFAULT "active",
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.partna_staff (
            id TEXT PRIMARY KEY,
            auth_user_id TEXT,
            role TEXT,
            name TEXT,
            primary_email TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS audit.data_export_audit (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            professional_handle_snapshot TEXT NOT NULL,
            professional_email_snapshot TEXT,
            triggered_by TEXT NOT NULL,
            triggered_by_staff_id TEXT,
            recipient_email TEXT NOT NULL,
            send_to TEXT,
            status TEXT NOT NULL DEFAULT "queued",
            file_path TEXT,
            file_size_bytes INTEGER,
            file_sha256 TEXT,
            record_counts TEXT,
            error_message TEXT,
            created_at TEXT,
            completed_at TEXT,
            email_sent_at TEXT,
            email_delivery_status TEXT
        )');

        // Column list mirrors PRODUCTION (baseline :401-416 + external_id/
        // marketing_opt_in_cached present since baseline, redacted_at added by
        // 20260527160000). external_id/marketing_opt_in_cached were previously
        // missing here — a SELECT naming them would silently return the column
        // NAME as a string literal on SQLite rather than erroring (see file-level
        // trap note in the task prompt), masking a real Postgres 42703.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.customers (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            email TEXT,
            phone TEXT,
            full_name TEXT,
            source TEXT,
            notes TEXT,
            external_id TEXT,
            marketing_opt_in_cached INTEGER,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT,
            redacted_at TEXT
        )');

        // No user_id FK — joined by email_lc only.
        $conn->statement('CREATE TABLE IF NOT EXISTS core.early_access_signups (
            id TEXT PRIMARY KEY,
            email TEXT,
            email_lc TEXT,
            type TEXT,
            workplace_or_industry TEXT,
            platforms TEXT,
            status TEXT,
            source TEXT,
            invited_at TEXT,
            invite_token_hash TEXT,
            invite_meta TEXT,
            invited_by TEXT,
            signed_up_at TEXT,
            consent_ip_hash TEXT,
            consent_user_agent TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        // Permanent pre-account build origin record (1:1 with core.users via
        // user_id UNIQUE + ON DELETE CASCADE). Column list mirrors PRODUCTION
        // (supabase/migrations/20260718200000_pre_account_sites.sql +
        // 20260721120000_signup_flows_early_access.sql's contact_email add).
        $conn->statement('CREATE TABLE IF NOT EXISTS core.pre_account_builds (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            source_type TEXT,
            source_ref TEXT,
            source_ref_lc TEXT,
            built_via TEXT,
            built_by_staff_id TEXT,
            build_state TEXT DEFAULT "pending",
            failure_code TEXT,
            created_ip_hash TEXT,
            expires_at TEXT,
            claimed_at TEXT,
            contact_email TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        // Rows persist post user-delete (ON DELETE SET NULL) — pre-deletion DSAR disclosure is important.
        $conn->statement('CREATE TABLE IF NOT EXISTS audit.handle_change_log (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            old_handle TEXT,
            new_handle TEXT,
            reason TEXT,
            actor_id TEXT,
            ip_address TEXT,
            user_agent TEXT,
            changed_at TEXT
        )');

        // Survives the user (ON DELETE SET NULL) — DSAR must disclose IP/UA/reason snapshots while the user can still request them.
        $conn->statement('CREATE TABLE IF NOT EXISTS audit.user_deletion_audit (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            professional_handle_snapshot TEXT,
            professional_email_snapshot TEXT,
            event TEXT,
            ip_address TEXT,
            user_agent TEXT,
            metadata TEXT,
            actor_type TEXT,
            actor_id TEXT,
            actor_handle_snapshot TEXT,
            reason TEXT,
            created_at TEXT
        )');

        // user_id references auth.users(id) — joined via core.users.auth_user_id.
        $conn->statement('CREATE TABLE IF NOT EXISTS audit.auth_factor_events (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            session_id TEXT,
            event_type TEXT,
            factor_id TEXT,
            factor_type TEXT,
            ip TEXT,
            user_agent TEXT,
            metadata TEXT,
            webhook_id TEXT,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.user_confirmation_preferences (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            action_key TEXT,
            skip_confirmation INTEGER,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_profiles (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            industry TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
            id TEXT PRIMARY KEY,
            brand_user_id TEXT,
            affiliate_user_id TEXT,
            created_at TEXT,
            deleted_at TEXT NULL
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            provider TEXT,
            shop_domain TEXT,
            last_sync_at TEXT,
            access_token TEXT,
            refresh_token TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        // Column list mirrors PRODUCTION (baseline :930-955; professional_id
        // renamed to user_id by 20260527030000:44). streamServices()'s
        // ->select() allowlist (PRIV-2) names all 15 columns explicitly, so a
        // stub gap here would silently return the column NAME as a string
        // literal on SQLite rather than erroring.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.services (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            title TEXT,
            description TEXT,
            category TEXT,
            price_cents INTEGER,
            currency_code TEXT,
            duration_minutes INTEGER,
            is_active INTEGER,
            sort_order INTEGER,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT,
            category_id TEXT,
            deleted_origin TEXT
        )');

        // Column list mirrors PRODUCTION (baseline :905-917; professional_id
        // renamed to user_id by 20260527030000:43). streamServiceCategories()'s
        // ->select() allowlist (PRIV-2) names all 7 columns explicitly.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.service_categories (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            title TEXT,
            sort_order INTEGER,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        // Column list mirrors PRODUCTION (baseline :707-721 + the additions
        // enumerated in site()'s ->select() allowlist). This table is the
        // highest-churn table in the schema (PRIV-2) — the export now names
        // every column explicitly, so a stub missing one would silently
        // return the column NAME as a string literal on SQLite (the
        // 2026-07-19 GDPR-export trap) rather than erroring.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.sites (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            subdomain TEXT,
            is_published INTEGER,
            settings TEXT,
            created_at TEXT,
            updated_at TEXT,
            subdomain_changed_at TEXT,
            unpublished_at TEXT,
            architecture_id TEXT,
            moderation_state TEXT,
            custom_domain TEXT,
            custom_domain_status TEXT,
            custom_domain_verified_at TEXT,
            custom_domain_cf_id TEXT,
            custom_domain_primary INTEGER,
            show_branding INTEGER,
            charlie_enabled INTEGER,
            services_auto_sync_enabled INTEGER,
            booking_mode TEXT,
            manual_booking_url TEXT,
            content_instagram_auto_enabled INTEGER,
            shop_link_mode TEXT,
            shop_auto_latest INTEGER
        )');

        // Joined to user via site_id → sites.user_id.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.site_subdomain_aliases (
            id TEXT PRIMARY KEY,
            site_id TEXT,
            subdomain TEXT,
            reclaim_until TEXT,
            expires_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.user_handle_aliases (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            handle TEXT,
            reclaim_until TEXT,
            expires_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        // Column list mirrors PRODUCTION (baseline creates the table with
        // `block_type`, not `type` — the previous stub used the wrong name).
        // site()'s ->select() allowlist (PRIV-2) now names every column
        // explicitly, so any stub gap here would silently return the column
        // NAME as a string literal on SQLite rather than erroring.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.blocks (
            id TEXT PRIMARY KEY,
            site_id TEXT,
            user_id TEXT,
            block_group TEXT,
            block_type TEXT,
            title TEXT,
            url TEXT,
            sort_order INTEGER,
            is_active INTEGER,
            live_check_enabled INTEGER,
            category TEXT,
            platform TEXT,
            handle TEXT,
            icon_key TEXT,
            is_enabled INTEGER,
            settings TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.enquiries (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            name TEXT,
            email TEXT,
            phone TEXT,
            subject TEXT,
            message TEXT,
            ip_hash TEXT,
            user_agent TEXT,
            created_at TEXT
        )');

        // Column list mirrors PRODUCTION (site.site_media in the standalone
        // baseline), NOT the export query's SELECT list. The previous fixture
        // declared user_id/width/height — none of which exist in Postgres —
        // so it made a broken query valid on SQLite and the export's media
        // section 500'd only in production. Scope column is site_id.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.site_media (
            id TEXT PRIMARY KEY,
            site_id TEXT,
            bucket TEXT,
            pool TEXT,
            purpose TEXT,
            path TEXT,
            media_type TEXT,
            original_filename TEXT,
            caption TEXT,
            alt_text TEXT,
            sort_order INTEGER,
            is_active INTEGER,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            email_lc TEXT,
            list_key TEXT,
            email TEXT,
            full_name TEXT,
            status TEXT,
            subscribed_at TEXT,
            unsubscribed_at TEXT,
            consent_source TEXT,
            created_at TEXT
        )');

        // Targeted dashboard messages — body text contains user-specific content.
        // critical (delivery-escalation flag) added by 20260711000400:12.
        $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            type TEXT,
            title TEXT,
            body TEXT,
            cta_url TEXT,
            severity TEXT,
            critical INTEGER,
            starts_at TEXT,
            ends_at TEXT,
            primary_action_label TEXT,
            secondary_action_label TEXT,
            secondary_action_url TEXT,
            category TEXT,
            dedupe_key TEXT,
            email_sent_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        // Per-user read/dismiss timestamps — behavioural data tied to identified user.
        $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_receipts (
            id TEXT PRIMARY KEY,
            notification_id TEXT,
            user_id TEXT,
            read_at TEXT,
            dismissed_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        // Columns are category_key/enabled, not category/opted_in (baseline :1054-1063;
        // rename 20260527030000:58 — the rename touched the column NAME, not just the FK).
        $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_preferences (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            category_key TEXT,
            enabled INTEGER,
            created_at TEXT,
            updated_at TEXT
        )');

        // Columns are category_key/mode, not category/policy (baseline :1067-1078;
        // rename 20260527030000:57).
        $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_policies (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            category_key TEXT,
            mode TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS analytics.booking_events (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            occurred_at TEXT,
            status TEXT,
            source TEXT,
            customer_name TEXT,
            customer_email TEXT,
            customer_phone TEXT,
            amount_paid_cents INTEGER,
            currency_code TEXT,
            raw_payload TEXT,
            created_at TEXT
        )');

        // Column list mirrors PRODUCTION (baseline :1189-1205; professional_id
        // renamed to user_id by 20260527030000). No created_at column exists on
        // this table — a prior version of this stub carried a spurious one.
        // ip_hash/user_agent are required here: Customer::redact() (PRIV-1
        // cascade) UPDATEs both on this exact table. Cross-checked against the
        // real-DDL stub in tests/Unit/Models/CustomerRedactTest.php — the two agree.
        $conn->statement('CREATE TABLE IF NOT EXISTS analytics.lead_submissions (
            id TEXT PRIMARY KEY,
            occurred_at TEXT,
            subdomain TEXT,
            site_id TEXT,
            user_id TEXT,
            customer_id TEXT,
            ip_hash TEXT,
            user_agent TEXT,
            referrer TEXT,
            outcome TEXT,
            form_started_at_ms INTEGER
        )');

        // In-app feedback submissions. ip_hash and user_agent stored separately — excluded from DSAR.
        // type/area/target (OV-D taxonomy) mirror 20260711153000_feedback_type_area_target.sql (PRIV-6).
        $conn->statement('CREATE TABLE IF NOT EXISTS core.feedback (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            reply_email TEXT,
            kind TEXT,
            severity TEXT,
            type TEXT,
            area TEXT,
            target TEXT,
            message TEXT,
            page_url TEXT,
            user_agent TEXT,
            viewport TEXT,
            app_version TEXT,
            request_id TEXT,
            status TEXT DEFAULT "new",
            internal_notes TEXT DEFAULT "[]",
            tags TEXT DEFAULT "[]",
            source TEXT DEFAULT "dashboard",
            ip_hash TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        // Moderation cases — reportable_owner_user_id links a case to the user whose content was reported.
        $conn->statement('CREATE TABLE IF NOT EXISTS moderation.cases (
            id TEXT PRIMARY KEY,
            case_type TEXT,
            reportable_type TEXT,
            reportable_id TEXT,
            reportable_owner_user_id TEXT,
            severity INTEGER DEFAULT 2,
            status TEXT DEFAULT "open",
            signal_count INTEGER DEFAULT 1,
            auto_actioned INTEGER DEFAULT 0,
            priority INTEGER DEFAULT 5,
            sla_due_at TEXT,
            triaged_at TEXT,
            triaged_by_staff_id TEXT,
            resolved_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        // Moderation case signals — reporter_ip_hash and dedup_hash are technical fingerprints excluded from DSAR.
        $conn->statement('CREATE TABLE IF NOT EXISTS moderation.case_signals (
            id TEXT PRIMARY KEY,
            case_id TEXT,
            signal_source TEXT,
            signal_data TEXT DEFAULT "{}",
            reporter_user_id TEXT,
            reporter_email TEXT,
            reporter_ip_hash TEXT,
            reason_code TEXT,
            reason_details TEXT,
            dedup_hash TEXT,
            created_at TEXT
        )');

        // Per-site design kit (1:1 with site.sites). All var columns NULLABLE.
        // typography_font_heading/typography_font_body DROPPED by
        // 20260603000001_drop_orphan_design_kit_typography_cols.sql — not present
        // in production. This table is exempt from the T2 column-existence guard
        // (SELECT_STAR_EXEMPT), so this stub only needs to stay non-hostile, not
        // exhaustive.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.design_kits (
            site_id TEXT PRIMARY KEY,
            color_accent TEXT,
            color_text TEXT,
            typography_font_family TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            plan_id TEXT,
            status TEXT,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_movements (
            id TEXT PRIMARY KEY,
            affiliate_user_id TEXT,
            brand_user_id TEXT,
            amount_cents INTEGER,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
            id TEXT PRIMARY KEY,
            affiliate_user_id TEXT,
            brand_user_id TEXT,
            status TEXT,
            amount_cents INTEGER,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS audit.user_deletion_audit (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            professional_handle_snapshot TEXT,
            professional_email_snapshot TEXT,
            event TEXT,
            actor_type TEXT,
            actor_id TEXT,
            actor_handle_snapshot TEXT,
            reason TEXT,
            created_at TEXT
        )');

        // PRIV-3: live platform connections (Instagram, Shopify, Fresha, etc.).
        // Mirrors the real table's columns including the internal refresh
        // machinery (last_refresh_error, consecutive_failures, apify_status,
        // place_id, refresh_etag, refresh_last_modified) so a test can prove
        // those are excluded from the export allowlist.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.platform_connections (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            platform TEXT,
            resource_id TEXT,
            resource_kind TEXT,
            canonical_key TEXT,
            payload TEXT,
            display_settings TEXT,
            sort_order INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            last_visited_at TEXT,
            last_refreshed_at TEXT,
            last_refresh_status TEXT,
            last_refresh_error TEXT,
            consecutive_failures INTEGER DEFAULT 0,
            apify_status TEXT,
            place_id TEXT,
            refresh_etag TEXT,
            refresh_last_modified TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        // PRIV-4: visitor analytics. Fingerprint columns (ip_hash, visitor_id,
        // session_id, user_agent) are included in the stub (mirrors prod) so a
        // test can prove they are excluded from the export allowlist.
        $conn->statement('CREATE TABLE IF NOT EXISTS analytics.site_visits (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            site_id TEXT,
            occurred_at TEXT,
            session_id TEXT,
            visitor_id TEXT,
            ip_hash TEXT,
            user_agent TEXT,
            referrer TEXT,
            utm_source TEXT,
            utm_medium TEXT,
            utm_campaign TEXT,
            country_code TEXT,
            region_code TEXT,
            city TEXT,
            device_type TEXT,
            latitude REAL,
            longitude REAL,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS analytics.link_clicks (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            site_id TEXT,
            link_block_id TEXT,
            occurred_at TEXT,
            session_id TEXT,
            visitor_id TEXT,
            ip_hash TEXT,
            user_agent TEXT,
            url TEXT,
            platform TEXT,
            product_id TEXT,
            product_title TEXT,
            section_key TEXT,
            label TEXT,
            referrer TEXT,
            utm_source TEXT,
            utm_medium TEXT,
            utm_campaign TEXT,
            country_code TEXT,
            region_code TEXT,
            device_type TEXT,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS analytics.section_views (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            site_id TEXT,
            block_id TEXT,
            section_key TEXT,
            occurred_at TEXT,
            session_id TEXT,
            visitor_id TEXT,
            ip_hash TEXT,
            user_agent TEXT,
            referrer TEXT,
            utm_source TEXT,
            utm_medium TEXT,
            utm_campaign TEXT,
            country_code TEXT,
            device_type TEXT,
            duration_ms INTEGER,
            created_at TEXT
        )');

        // user_id nullable (fail-open write path — mirrors prod).
        $conn->statement('CREATE TABLE IF NOT EXISTS analytics.item_views (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            site_id TEXT,
            item_type TEXT,
            item_id TEXT,
            item_title TEXT,
            section_key TEXT,
            occurred_at TEXT,
            session_id TEXT,
            visitor_id TEXT,
            ip_hash TEXT,
            user_agent TEXT,
            referrer TEXT,
            country_code TEXT,
            device_type TEXT,
            created_at TEXT
        )');

        // FOUND-4: workplace card promoted from settings JSONB to child table.
        // previous_website_analysis (20260701220001) is included so PRIV-5 tests can
        // prove it's excluded from the export's explicit allow-list, not just absent
        // from the fixture.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.workplaces (
            site_id TEXT PRIMARY KEY,
            name TEXT NULL,
            address TEXT NULL,
            address_line1 TEXT NULL,
            city TEXT NULL,
            state TEXT NULL,
            postcode TEXT NULL,
            country TEXT NULL,
            latitude REAL NULL,
            longitude REAL NULL,
            phone TEXT NULL,
            website TEXT NULL,
            previous_website TEXT NULL,
            previous_website_analysis TEXT NULL,
            category TEXT NULL,
            description TEXT NULL,
            opening_hours TEXT NULL,
            contact_email TEXT NULL,
            field_sources TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )');
    }
}
