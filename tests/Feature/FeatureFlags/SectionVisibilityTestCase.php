<?php

namespace Tests\Feature\FeatureFlags;

use Illuminate\Support\Facades\DB;

// Boots SQLite in-memory with the minimum schema needed to exercise
// SectionVisibilityService::checkBookingRequirements. Follows the pattern
// from tests/Feature/Professional/AccountDeletion/AccountDeletionTestCase.
class SectionVisibilityTestCase
{
    public static function boot(): void
    {
        $sqlite = config('database.connections.sqlite');
        config([
            'database.default' => 'sqlite',
            'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
        ]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $conn = DB::connection('pgsql');

        foreach (['core', 'site'] as $schema) {
            try {
                $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
            } catch (\Throwable) {
            }
        }

        $conn->statement('CREATE TABLE IF NOT EXISTS core.users (
            id TEXT PRIMARY KEY,
            handle TEXT,
            display_name TEXT,
            primary_email TEXT,
            status TEXT DEFAULT "active",
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            provider TEXT,
            access_token TEXT,
            external_account_id TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.services (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            title TEXT,
            price_cents INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            sort_order INTEGER DEFAULT 0,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.blocks (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            site_id TEXT,
            block_group TEXT,
            block_type TEXT,
            settings TEXT,
            is_enabled INTEGER DEFAULT 1,
            is_active INTEGER DEFAULT 1,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');
    }
}
