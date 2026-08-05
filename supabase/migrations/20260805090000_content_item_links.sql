-- Per-item platform links (platforms-as-sources plan, 2026-08-05).
--
-- Embed-only platforms (Spotify, SoundCloud, Mixcloud, Tidal) can never be
-- item SOURCES — they have no item stream to ingest — so the item itself
-- carries the user's hand-saved "this same release on Spotify" links. One
-- row per (item, platform); the API enforces alternates-only (a platform
-- that IS the item's synced source never gets a manual row) and
-- platform-domain URL validation. ItemMerger repoints these on identity
-- merges like every other per-item table.
--
-- ROLLBACK: DROP TABLE IF EXISTS content.item_links;
--           IRREVERSIBLE: every hand-saved cross-platform link.

BEGIN;

CREATE TABLE "content"."item_links" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "platform" text NOT NULL,
    "url" text NOT NULL,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT "item_links_unique" UNIQUE ("item_id", "platform")
);

ALTER TABLE "content"."item_links" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "content"."item_links" FORCE ROW LEVEL SECURITY;
CREATE POLICY "content_item_links_app_backend_all" ON "content"."item_links"
    TO "app_backend" USING (true) WITH CHECK (true);

GRANT ALL ON "content"."item_links" TO "app_backend";
GRANT ALL ON "content"."item_links" TO "service_role";

COMMIT;
