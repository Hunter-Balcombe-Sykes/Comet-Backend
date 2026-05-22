-- Move storefront access token out of plaintext provider_metadata into an encrypted column.
-- The app-layer 'encrypted' cast wraps this in AES-256-CBC before storing.
ALTER TABLE core.professional_integrations
    ADD COLUMN IF NOT EXISTS storefront_token TEXT;
