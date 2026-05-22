-- Add setup_complete flag for brand onboarding wizard tracking
ALTER TABLE brand.brand_profiles
    ADD COLUMN IF NOT EXISTS setup_complete boolean NOT NULL DEFAULT false;
