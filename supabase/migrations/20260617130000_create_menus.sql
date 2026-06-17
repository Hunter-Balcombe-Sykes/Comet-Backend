-- =====================================================================
-- site.menus — fetched food-ordering menu (Uber Eats / DoorDash)
-- =====================================================================
-- One row per user. Holds the menu CONTENT scraped from the user's connected
-- online-ordering platform (categories → items → name/price/description/image)
-- plus the store rating. This is the SINGLE source of truth for menu content:
-- the per-platform online-ordering links in site.platform_connections stay
-- just links and never duplicate menu values. Content priority is
-- Uber Eats > DoorDash, resolved at fetch time (source records the winner).
--
-- Per-item order links (pickup / delivery) are NOT stored here — they are
-- computed at read time from the user's live online-ordering entries so they
-- always reflect the current links. Dashboard-only: never served to the
-- public sitepage.

CREATE TABLE IF NOT EXISTS site.menus (
    id              uuid PRIMARY KEY,
    user_id         uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
    source          text NOT NULL CHECK (source IN ('uber-eats', 'doordash')),
    store_url       text NOT NULL,
    rating          numeric(3,2),
    review_count    integer,
    currency        text,
    categories      jsonb NOT NULL DEFAULT '[]'::jsonb,
    fetch_status    text NOT NULL DEFAULT 'pending'
                        CHECK (fetch_status IN ('pending', 'ok', 'unavailable')),
    last_fetched_at timestamptz,
    created_at      timestamptz,
    updated_at      timestamptz,
    deleted_at      timestamptz
);

-- One live menu per user. Soft-deleted rows are excluded so a user whose
-- ordering links were removed (menu cleared) can re-create a fresh row later.
CREATE UNIQUE INDEX IF NOT EXISTS idx_menus_user_unique
    ON site.menus (user_id)
    WHERE deleted_at IS NULL;
