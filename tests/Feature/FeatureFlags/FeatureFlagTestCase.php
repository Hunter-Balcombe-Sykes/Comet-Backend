<?php

namespace Tests\Feature\FeatureFlags;

/**
 * Boots the minimum SQLite schema for FeatureFlagService tests.
 *
 * Thin alias over the canonical tests/Pest.php helpers — the DDL lives there and
 * only there. This used to be a second hand-copied definition and it drifted: it
 * carried a phantom `brand_id` column (dropped from core.feature_flag_overrides
 * by the 2026-05-22 standalone strip-down) which made a real Postgres 42703 on
 * POST /staff/feature-flags/{key}/overrides pass green for two months (#FFLAG-1).
 * Never re-inline a CREATE TABLE here.
 */
class FeatureFlagTestCase
{
    public static function boot(): void
    {
        setupUsersTable();          // also boots core.partna_staff (Pest.php:362)
        setupFeatureFlagsTable();
    }
}
