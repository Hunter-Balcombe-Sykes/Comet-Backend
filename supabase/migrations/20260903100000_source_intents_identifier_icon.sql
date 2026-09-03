-- ROLLBACK: ALTER TABLE routing.source_intents DROP COLUMN IF EXISTS identifier_icon;
-- Additive and nullable, so the drop is an exact structural inverse. It does
-- discard every icon captured since this ran; those are re-derivable by
-- re-probing the surface, so nothing unrecoverable is lost.
-- The suggestion's own icon (setup dialog, owner ask 2026-09-03): a store's
-- favicon/logo captured by the probe that already fetched the page, so the
-- stores pass can show THE STORE'S mark instead of the provider's. Nullable,
-- written by the same coalesce-don't-clobber rule as identifier_label.
ALTER TABLE routing.source_intents
    ADD COLUMN IF NOT EXISTS identifier_icon TEXT NULL;
