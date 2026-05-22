-- Add custom domain support to brand store settings.
-- Phase 1: data model only. Verification/TLS provisioning logic comes in Phase 4.
-- platform = use {subdomain}.sidest.co (default)
-- custom   = use brand's own domain once verified + TLS provisioned

ALTER TABLE brand.brand_store_settings
  ADD COLUMN IF NOT EXISTS domain_mode text NOT NULL DEFAULT 'platform',
  ADD COLUMN IF NOT EXISTS custom_domain text NULL,
  ADD COLUMN IF NOT EXISTS custom_domain_verified_at timestamptz NULL,
  ADD COLUMN IF NOT EXISTS custom_domain_tls_provisioned_at timestamptz NULL;

ALTER TABLE brand.brand_store_settings
  ADD CONSTRAINT brand_store_settings_domain_mode_check
  CHECK (domain_mode IN ('platform', 'custom'));
