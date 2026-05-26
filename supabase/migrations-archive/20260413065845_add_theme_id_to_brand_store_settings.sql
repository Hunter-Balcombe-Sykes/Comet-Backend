-- Add theme_id to brand.brand_store_settings.
--
-- Constrained to the five supported store theme variants (1–5). The matching
-- application-side validation lives in
-- UpdateBrandStoreSettingsRequest::rules() ('in:1,2,3,4,5'). NOT NULL with a
-- default of 1 so existing rows backfill automatically.
--
-- Mirrors the version already applied to the remote DB (this file was
-- recreated locally to reconcile the migration history — the schema change
-- itself has been live since 2026-04-13).

ALTER TABLE brand.brand_store_settings
    ADD COLUMN theme_id SMALLINT NOT NULL DEFAULT 1
        CONSTRAINT brand_store_settings_theme_id_check CHECK (theme_id IN (1, 2, 3, 4, 5));
