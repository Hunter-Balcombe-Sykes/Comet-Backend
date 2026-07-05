<?php

namespace Tests\Feature\User\AccountDeletion;

use Illuminate\Support\Facades\DB;

// Shared SQLite schema setup for account-deletion feature tests.
// Mirrors the pattern from BrandBootstrapTest — attaches each schema as its own
// in-memory DB so schema-qualified table names (core.*, commerce.*, etc.) resolve.
class AccountDeletionTestCase
{
    public static function boot(): void
    {
        $sqlite = config('database.connections.sqlite');
        config([
            'database.default' => 'sqlite',
            'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
            'supabase.url' => 'https://test.supabase.co',
            'supabase.service_role_key' => 'test-service-role-key',
            'app.frontend_url' => 'https://app.sidest.test',
            'partna.soft_delete_retention_days' => 30,
        ]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $conn = DB::connection('pgsql');

        foreach (['core', 'brand', 'commerce', 'notifications', 'billing', 'site', 'audit', 'moderation'] as $schema) {
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
            professional_type TEXT DEFAULT "professional",
            account_type TEXT NULL,
            status TEXT DEFAULT "active",
            onboarding_step INTEGER DEFAULT 0,
            stripe_manual_balance_cents INTEGER DEFAULT 0,
            deletion_token_hash TEXT,
            deletion_requested_at TEXT,
            deletion_confirmed_at TEXT,
            deletion_previous_status TEXT,
            deletion_mail_sent_at TEXT,
            phone TEXT,
            first_name TEXT,
            last_name TEXT,
            location_street_address TEXT,
            location_postcode TEXT,
            location_city TEXT,
            location_state TEXT,
            location_country TEXT,
            public_contact_email TEXT,
            public_contact_number TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS audit.user_deletion_audit (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            professional_handle_snapshot TEXT NOT NULL,
            professional_email_snapshot TEXT NOT NULL,
            event TEXT NOT NULL,
            actor_type TEXT,
            actor_id TEXT,
            actor_handle_snapshot TEXT,
            reason TEXT,
            ip_address TEXT,
            user_agent TEXT,
            metadata TEXT,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.partna_staff (
            id TEXT PRIMARY KEY,
            auth_user_id TEXT,
            role TEXT,
            name TEXT,
            primary_email TEXT,
            phone TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_profiles (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            abn TEXT,
            acn TEXT,
            legal_business_name TEXT,
            business_type TEXT,
            brand_status TEXT DEFAULT "deactivated",
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            provider TEXT,
            access_token TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
            id TEXT PRIMARY KEY,
            brand_user_id TEXT,
            affiliate_user_id TEXT,
            status TEXT,
            amount_cents INTEGER,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS commerce.brand_commission_topups (
            id TEXT PRIMARY KEY,
            brand_user_id TEXT,
            status TEXT,
            amount_cents INTEGER,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.customers (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.services (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.sites (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            subdomain TEXT,
            is_published INTEGER,
            unpublished_at TEXT,
            settings TEXT,
            deleted_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.site_media (
            id TEXT PRIMARY KEY,
            site_id TEXT,
            user_id TEXT,
            pool TEXT,
            path TEXT,
            media_type TEXT,
            processing_state TEXT,
            is_active INTEGER,
            deleted_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            stripe_subscription_id TEXT,
            status TEXT,
            cancel_at_period_end INTEGER DEFAULT 0,
            created_at TEXT,
            updated_at TEXT
        )');

        // site.enquiries — purge job iterates this table via soft-delete sweep.
        // Empty schema is enough for the test (sweep returns zero rows).
        $conn->statement('CREATE TABLE IF NOT EXISTS site.enquiries (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            customer_email TEXT,
            customer_phone TEXT,
            customer_name TEXT,
            message TEXT,
            deleted_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        // site.service_categories — also swept by the GC for legacy soft-deleted rows.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.service_categories (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            name TEXT,
            sort_order INTEGER DEFAULT 0,
            deleted_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        // site.blocks — swept by the soft-delete GC since Block gained SoftDeletes.
        // Empty schema is enough for the test (sweep returns zero rows).
        $conn->statement('CREATE TABLE IF NOT EXISTS site.blocks (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            site_id TEXT,
            block_group TEXT,
            block_type TEXT,
            settings TEXT,
            sort_order INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            is_enabled INTEGER DEFAULT 1,
            deleted_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement("CREATE TABLE IF NOT EXISTS core.feedback (
            id TEXT PRIMARY KEY,
            user_id TEXT NULL,
            reply_email TEXT NULL,
            kind TEXT NOT NULL,
            severity TEXT NULL,
            message TEXT NOT NULL,
            page_url TEXT NULL,
            user_agent TEXT NULL,
            viewport TEXT NULL,
            app_version TEXT NULL,
            request_id TEXT NULL,
            status TEXT NOT NULL DEFAULT 'new',
            internal_notes TEXT NOT NULL DEFAULT '[]',
            tags TEXT NOT NULL DEFAULT '[]',
            source TEXT NOT NULL DEFAULT 'dashboard',
            ip_hash TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL,
            deleted_at TEXT NULL
        )");

        // audit.data_export_audit — purge() deletes R2 ZIPs before forceDelete sets user_id NULL.
        $conn->statement("CREATE TABLE IF NOT EXISTS audit.data_export_audit (
            id TEXT PRIMARY KEY,
            user_id TEXT NULL,
            professional_handle_snapshot TEXT NOT NULL DEFAULT '',
            professional_email_snapshot TEXT NULL,
            triggered_by TEXT NOT NULL DEFAULT 'self',
            triggered_by_staff_id TEXT NULL,
            recipient_email TEXT NOT NULL DEFAULT '',
            send_to TEXT NULL,
            status TEXT NOT NULL DEFAULT 'completed',
            file_path TEXT NULL,
            file_size_bytes INTEGER NULL,
            file_sha256 TEXT NULL,
            record_counts TEXT NULL,
            error_message TEXT NULL,
            email_sent_at TEXT NULL,
            created_at TEXT NULL,
            completed_at TEXT NULL
        )");

        // core.waitlist_signups — purge() deletes by email_lc.
        $conn->statement('CREATE TABLE IF NOT EXISTS core.waitlist_signups (
            id TEXT PRIMARY KEY,
            name TEXT NULL,
            email TEXT NULL,
            email_lc TEXT NULL,
            phone TEXT NULL,
            applicant_type TEXT NULL,
            pilot_program_opt_in INTEGER NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )');

        // moderation.case_signals — purge() nulls out reporter PII.
        // SQLite does not enforce FKs by default, so the cases table is omitted here.
        $conn->statement("CREATE TABLE IF NOT EXISTS moderation.case_signals (
            id TEXT PRIMARY KEY,
            case_id TEXT NOT NULL,
            signal_source TEXT NOT NULL DEFAULT 'content_report',
            signal_data TEXT NOT NULL DEFAULT '{}',
            reporter_user_id TEXT NULL,
            reporter_email TEXT NULL,
            reporter_ip_hash TEXT NULL,
            reason_code TEXT NOT NULL DEFAULT 'spam',
            reason_details TEXT NULL,
            created_at TEXT NULL
        )");

        // moderation.cases — required FK target for moderation.evidence.
        // SQLite doesn't enforce FKs, so a minimal schema is enough.
        $conn->statement('CREATE TABLE IF NOT EXISTS moderation.cases (
            id TEXT PRIMARY KEY,
            reportable_type TEXT NOT NULL DEFAULT \'Site\',
            reportable_id TEXT NOT NULL DEFAULT \'\',
            status TEXT NOT NULL DEFAULT \'open\',
            created_at TEXT NULL,
            updated_at TEXT NULL
        )');

        // moderation.evidence — PRIV-4: purge() tombstones reported-user PII in payload.
        $conn->statement("CREATE TABLE IF NOT EXISTS moderation.evidence (
            id TEXT PRIMARY KEY,
            case_id TEXT NOT NULL,
            signal_id TEXT NULL,
            evidence_type TEXT NOT NULL DEFAULT 'content_snapshot',
            payload TEXT NOT NULL DEFAULT '{}',
            content_hash TEXT NULL,
            captured_at TEXT NULL
        )");

        // notifications.email_subscriptions — purge() deletes global (user_id IS NULL) rows by email_lc.
        $conn->statement("CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
            id TEXT PRIMARY KEY,
            user_id TEXT NULL,
            list_key TEXT NOT NULL DEFAULT 'marketing',
            email TEXT NOT NULL DEFAULT '',
            full_name TEXT NULL,
            status TEXT NOT NULL DEFAULT 'subscribed',
            subscribed_at TEXT NULL,
            unsubscribed_at TEXT NULL,
            unsubscribe_token TEXT NOT NULL DEFAULT '',
            consent_source TEXT NULL,
            consent_ip_hash TEXT NULL,
            consent_user_agent TEXT NULL,
            email_lc TEXT NOT NULL DEFAULT '',
            qr_slug TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )");
    }
}
