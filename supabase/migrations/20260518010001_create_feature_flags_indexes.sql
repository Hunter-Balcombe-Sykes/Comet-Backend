-- CONCURRENTLY is required by migration lint conventions (§1), even on new tables.
-- These run outside any transaction block to satisfy the CONCURRENTLY requirement.
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_pro_unique
    ON core.feature_flag_overrides (flag_key, professional_id)
    WHERE brand_id IS NULL;

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_brand_unique
    ON core.feature_flag_overrides (flag_key, brand_id)
    WHERE brand_id IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_pro_lookup
    ON core.feature_flag_overrides (professional_id, flag_key)
    WHERE professional_id IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_brand_lookup
    ON core.feature_flag_overrides (brand_id, flag_key)
    WHERE brand_id IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_expires_at
    ON core.feature_flag_overrides (expires_at)
    WHERE expires_at IS NOT NULL;

-- Powers the admin override list query (ORDER BY created_at DESC at the staff endpoint)
CREATE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_flag_key_created
    ON core.feature_flag_overrides (flag_key, created_at DESC);

-- Powers the active-flag scan from the resolver (soft-delete aware)
CREATE INDEX CONCURRENTLY IF NOT EXISTS feature_flags_active
    ON core.feature_flags (key)
    WHERE deleted_at IS NULL;
