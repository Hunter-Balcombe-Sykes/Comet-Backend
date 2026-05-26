-- Standalone-pages seed: one test individual professional for local development.
-- Safe to run repeatedly (idempotent via deterministic UUID and upsert).
-- Updated 2026-05-24 after standalone strip (removed enterprise/ambassador scenarios).

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'core'
          AND table_name = 'users'
    ) THEN
        RAISE NOTICE 'Skipping seed: core.users table is missing. Run migrations first.';
        RETURN;
    END IF;

    -- ============================================================
    -- Test professional (individual)
    -- ============================================================
    INSERT INTO core.users (
        id,
        auth_user_id,
        handle,
        handle_lc,
        display_name,
        first_name,
        last_name,
        primary_email,
        account_type,
        status,
        onboarding_step
    )
    VALUES (
        '20000000-0000-0000-0000-000000000001',
        '30000000-0000-0000-0000-000000000001',
        'test-professional',
        'test-professional',
        'Test Professional',
        'Test',
        'Professional',
        'test@example.com',
        'individual',
        'active',
        0
    )
    ON CONFLICT (id) DO UPDATE
    SET
        handle          = EXCLUDED.handle,
        handle_lc       = EXCLUDED.handle_lc,
        display_name    = EXCLUDED.display_name,
        first_name      = EXCLUDED.first_name,
        last_name       = EXCLUDED.last_name,
        primary_email   = EXCLUDED.primary_email,
        account_type    = EXCLUDED.account_type,
        status          = EXCLUDED.status,
        onboarding_step = EXCLUDED.onboarding_step;

    RAISE NOTICE 'Seed complete: 1 test professional inserted/updated.';
END $$;
