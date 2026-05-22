BEGIN;

CREATE TABLE core.feature_flags (
    key text PRIMARY KEY,
    description text NOT NULL DEFAULT '',
    default_enabled boolean NOT NULL DEFAULT false,
    rollout_percent smallint NOT NULL DEFAULT 0,
    deleted_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT feature_flags_rollout_percent_range CHECK (rollout_percent >= 0 AND rollout_percent <= 100),
    CONSTRAINT feature_flags_key_length CHECK (length(key) <= 128),
    CONSTRAINT feature_flags_key_format CHECK (key ~ '^[a-z][a-z0-9_]*$')
);

CREATE TABLE core.feature_flag_overrides (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    flag_key text NOT NULL REFERENCES core.feature_flags(key) ON DELETE CASCADE,
    professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    brand_id uuid NULL REFERENCES brand.brand_profiles(id) ON DELETE CASCADE,
    enabled boolean NOT NULL,
    reason text NULL,
    expires_at timestamptz NULL,
    created_by uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT feature_flag_overrides_scope_set CHECK (
        professional_id IS NOT NULL OR brand_id IS NOT NULL
    )
);

COMMIT;
