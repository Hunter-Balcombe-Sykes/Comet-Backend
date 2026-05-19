<?php

namespace Tests\Feature\FeatureFlags;

use Illuminate\Support\Facades\DB;

/**
 * Boots the minimum SQLite schema for FeatureFlagService tests.
 * Follows the same pattern as the global Pest.php helpers (attachTestSchemas +
 * CREATE TABLE IF NOT EXISTS on the 'pgsql' connection, which TestCase::setUp
 * has already redirected to in-memory SQLite).
 */
class FeatureFlagTestCase
{
    public static function boot(): void
    {
        // Attach schema namespaces to the SQLite in-memory connection. Inlined
        // here rather than calling attachTestSchemas() to avoid duplicate-function
        // issues with the worktree + main-repo Pest.php both being scanned.
        $conn = DB::connection('pgsql');
        foreach (['core', 'brand'] as $schema) {
            try {
                $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
            } catch (\Throwable) {
            }
        }

        $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
            id TEXT PRIMARY KEY,
            handle TEXT,
            display_name TEXT,
            primary_email TEXT,
            status TEXT DEFAULT "active",
            professional_type TEXT DEFAULT "professional",
            account_type TEXT NULL,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_profiles (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            brand_status TEXT DEFAULT "building",
            setup_complete INTEGER NULL,
            business_website TEXT NULL,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.feature_flags (
            key TEXT PRIMARY KEY,
            description TEXT,
            default_enabled INTEGER DEFAULT 0,
            rollout_percent INTEGER DEFAULT 0,
            deleted_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.feature_flag_overrides (
            id TEXT PRIMARY KEY,
            flag_key TEXT,
            professional_id TEXT,
            brand_id TEXT,
            enabled INTEGER DEFAULT 0,
            reason TEXT,
            expires_at TEXT,
            created_by TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
    }
}
