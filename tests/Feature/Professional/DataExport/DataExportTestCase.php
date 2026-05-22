<?php

namespace Tests\Feature\Professional\DataExport;

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

        foreach (['core', 'commerce', 'notifications', 'billing', 'site', 'analytics', 'brand'] as $schema) {
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
            account_type TEXT NULL,
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

        $conn->statement('CREATE TABLE IF NOT EXISTS core.data_export_audit (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
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
            completed_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.customers (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            email TEXT,
            phone TEXT,
            full_name TEXT,
            source TEXT,
            notes TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT,
            redacted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_profiles (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            industry TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
            id TEXT PRIMARY KEY,
            brand_professional_id TEXT,
            affiliate_professional_id TEXT,
            created_at TEXT,
            deleted_at TEXT NULL
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            provider TEXT,
            shop_domain TEXT,
            last_sync_at TEXT,
            access_token TEXT,
            refresh_token TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.services (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            name TEXT,
            duration_minutes INTEGER,
            price_cents INTEGER,
            created_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.service_categories (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            name TEXT,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.sites (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            subdomain TEXT,
            settings TEXT,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.blocks (
            id TEXT PRIMARY KEY,
            site_id TEXT,
            type TEXT,
            sort_order INTEGER,
            settings TEXT,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.enquiries (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            name TEXT,
            email TEXT,
            phone TEXT,
            subject TEXT,
            message TEXT,
            ip_hash TEXT,
            user_agent TEXT,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.site_media (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            pool TEXT,
            purpose TEXT,
            path TEXT,
            width INTEGER,
            height INTEGER,
            caption TEXT,
            alt_text TEXT,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
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

        $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_preferences (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            category TEXT,
            opted_in INTEGER,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_policies (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            category TEXT,
            policy TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS analytics.booking_events (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
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

        $conn->statement('CREATE TABLE IF NOT EXISTS analytics.lead_submissions (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            customer_id TEXT,
            occurred_at TEXT,
            outcome TEXT,
            form_started_at_ms INTEGER,
            subdomain TEXT,
            site_id TEXT,
            referrer TEXT,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            plan_id TEXT,
            status TEXT,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_movements (
            id TEXT PRIMARY KEY,
            affiliate_professional_id TEXT,
            brand_professional_id TEXT,
            amount_cents INTEGER,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
            id TEXT PRIMARY KEY,
            affiliate_professional_id TEXT,
            brand_professional_id TEXT,
            status TEXT,
            amount_cents INTEGER,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_deletion_audit (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            professional_handle_snapshot TEXT,
            professional_email_snapshot TEXT,
            event TEXT,
            actor_type TEXT,
            actor_id TEXT,
            actor_handle_snapshot TEXT,
            reason TEXT,
            created_at TEXT
        )');
    }
}
