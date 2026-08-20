-- First-connect Sell seeding (owner spec, 2026-08-20): the once-only marker
-- for ShopAutoSelector. Deliberately a SEPARATE column from
-- products_curated_at — that one means "the user hand-picked" and suppresses
-- scheduled ShopFetch syncs (#SEM-1); this one only records that the one-shot
-- auto-select has run, and must never affect sync scheduling.
alter table content.storefronts
    add column if not exists products_autoselected_at timestamptz null;
