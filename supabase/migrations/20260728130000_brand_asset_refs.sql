-- Brand asset refs (plan §12): which owned media asset plays which brand role
-- for a given connection.
--
-- PER-CONNECTION, deliberately, and this is the whole design decision. The
-- existing workplace-logo pipeline keys its singleton on
-- site.site_media (site_id, purpose) — which collides the moment one user has
-- two connections on the same platform, because both want the `logo_square`
-- slot for the same site and only one can have it. A store logo belongs to the
-- STORE, not to the page it appears on, so the key is the connection.
--
-- Roles stay a short closed list: these are slots a renderer asks for by name
-- ("give me this store's square logo"), not a free-form tag space. A new role
-- is a rendering decision and gets a migration.
--
-- asset_id is nullable and ON DELETE SET NULL: an asset can be purged (DMCA,
-- disconnect, GDPR erasure) while the ref survives to record that the role was
-- once filled and by what source. A ref row with a null asset is how "we had
-- this logo and no longer serve it" is told apart from "we never fetched one".
--
-- site.site_media remains site chrome (workplace logos). This table never
-- touches it.
--
-- ROLLBACK: DROP TABLE IF EXISTS content.brand_asset_refs CASCADE;
--           The role→asset mapping is re-derivable by re-running the
--           brand-asset ingest, but source_url (the DMCA takedown path's
--           only record of provenance) and attribution are NOT.

BEGIN;

CREATE TABLE "content"."brand_asset_refs" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "connection_id" uuid NOT NULL REFERENCES "site"."platform_connections" ("id") ON DELETE CASCADE,
    "role" text NOT NULL CHECK ("role" IN ('logo_square', 'logo_full', 'favicon')),
    "asset_id" uuid REFERENCES "content"."media_assets" ("id") ON DELETE SET NULL,
    -- The URL the bytes came from, kept for attribution and for the takedown
    -- path: a DMCA request names a source, not an asset id.
    "source_url" text,
    -- Owned bytes carry an attribution obligation (plan §12) — recorded per
    -- ref so a renderer can honour it without a join back to the connection.
    "attribution" text,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now()
);

-- One asset per role per connection. The upsert target for the ingest job.
CREATE UNIQUE INDEX "idx_brand_asset_refs_role"
    ON "content"."brand_asset_refs" ("connection_id", "role");

-- Reverse lookup for the purge path: "what still points at this asset?"
CREATE INDEX "idx_brand_asset_refs_asset"
    ON "content"."brand_asset_refs" ("asset_id")
    WHERE "asset_id" IS NOT NULL;

-- RLS + grants, in the naming the rest of the content schema uses
-- (20260727140000's loop generates exactly this shape). ALTER DEFAULT
-- PRIVILEGES from that migration would cover the grants, but stating them
-- keeps the table correct even when it is created by a different role.
ALTER TABLE "content"."brand_asset_refs" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "content"."brand_asset_refs" FORCE ROW LEVEL SECURITY;
CREATE POLICY "content_brand_asset_refs_app_backend_all" ON "content"."brand_asset_refs"
    TO "app_backend" USING (true) WITH CHECK (true);
GRANT ALL ON TABLE "content"."brand_asset_refs" TO "app_backend";
GRANT ALL ON TABLE "content"."brand_asset_refs" TO "service_role";

COMMIT;
