-- =====================================================================
-- site.content_selection — ordered background-content picks (<=15)
-- =====================================================================
-- The "Content Selection": up to 15 ordered entries a user chooses to use as
-- sitepage background content. Entries are heterogeneous references resolved at
-- read time — we deliberately do NOT copy Google/Instagram media:
--   entry_type='upload'       → media_id → a site.site_media row (pool='content')
--   entry_type='google-photo' → external_ref → a Google photo `ref` (stable
--                               resource name) held in the connection payload
--   entry_type='ig-reel'      → the latest Instagram reel (resolved live; auto slot 1)
--   entry_type='ig-post'      → the latest Instagram post (resolved live; auto slot 2)
--
-- position is 1..15, unique per site. RLS is OFF to match the sibling 1:1
-- app-managed table site.workplaces — access is gated in the Laravel policy
-- layer (ContentSelectionPolicy), not at the DB. app_backend gets the standard
-- CRUD grant. media_id ON DELETE CASCADE drops an entry if its upload is HARD
-- deleted; soft-deletes are reconciled in the app (unselect != delete upload).

BEGIN;

CREATE TABLE IF NOT EXISTS site.content_selection (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    site_id      uuid NOT NULL REFERENCES site.sites (id) ON DELETE CASCADE,
    position     smallint NOT NULL,
    entry_type   text NOT NULL CHECK (entry_type IN ('upload', 'google-photo', 'ig-reel', 'ig-post')),
    media_id     uuid REFERENCES site.site_media (id) ON DELETE CASCADE,
    external_ref text,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT content_selection_position_range CHECK (position >= 1 AND position <= 15),
    CONSTRAINT content_selection_site_position_unique UNIQUE (site_id, position),
    -- upload → media_id only; google-photo → external_ref only; ig-* → neither
    CONSTRAINT content_selection_ref_shape CHECK (
        (entry_type = 'upload'       AND media_id IS NOT NULL AND external_ref IS NULL)
     OR (entry_type = 'google-photo' AND external_ref IS NOT NULL AND media_id IS NULL)
     OR (entry_type IN ('ig-reel', 'ig-post') AND media_id IS NULL AND external_ref IS NULL)
    )
);

GRANT SELECT, INSERT, UPDATE, DELETE ON site.content_selection TO app_backend;

COMMIT;

-- ROLLBACK:
-- DROP TABLE IF EXISTS site.content_selection;
