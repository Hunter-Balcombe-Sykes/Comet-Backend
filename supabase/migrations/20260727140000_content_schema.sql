-- Content schema (plan §5/§6): items, computed identity, and TYPED facets.
--
-- The two rules this schema exists to enforce:
--
--   C1  Every durable content value is a typed column in a typed table. There
--       is no bag column for content anywhere below. `payload` jsonb is what
--       we are replacing.
--   C7  Identity is COMPUTED by a pure resolver over per-source records plus
--       human decisions. Nothing here has a unique index on an identity key
--       value: two sources claiming the same GTIN is an input to resolution,
--       never a constraint violation.
--
-- ROLLBACK: DROP SCHEMA IF EXISTS content CASCADE;
--           IRREVERSIBLE DATA LOSS. Every projected item, all 15 facet
--           tables, media_assets, item_slugs (public URLs), manual_overrides
--           (per-field user locks), item_merges (append-only audit ledger).
--           CASCADE also removes content.source_routes (20260727150000) and
--           content.brand_asset_refs (20260728130000).

CREATE SCHEMA IF NOT EXISTS "content";

-- ── sources: the contribution CHANNEL (not the per-record unit). ────────────
-- A user has exactly one 'manual' source, created at signup, at max priority:
-- that is what makes "the user outranks the machine" (C8) a data fact rather
-- than a special case in code.
CREATE TABLE "content"."sources" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
    "kind" text NOT NULL CHECK ("kind" IN ('connection', 'manual', 'import')),
    "connection_id" uuid REFERENCES "site"."platform_connections" ("id") ON DELETE CASCADE,
    "import_run_id" uuid,
    "label" text,
    "priority" integer NOT NULL DEFAULT 100,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX "idx_content_sources_manual" ON "content"."sources" ("user_id")
    WHERE ("kind" = 'manual');
CREATE UNIQUE INDEX "idx_content_sources_connection" ON "content"."sources" ("connection_id")
    WHERE ("connection_id" IS NOT NULL);
CREATE INDEX "idx_content_sources_user" ON "content"."sources" ("user_id", "priority" DESC);

-- ── items: the spine. Deliberately thin. ───────────────────────────────────
-- No pool, no is_selected, no sort_order, no identifier column: placement is
-- §7's business, selection is a section's business, and identity is computed.
CREATE TABLE "content"."items" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
    "kind" text NOT NULL CHECK ("kind" IN (
        'video', 'track', 'release', 'episode', 'channel', 'service', 'menu_item',
        'product', 'event', 'link', 'media', 'review', 'document', 'article'
    )),
    -- Caches for dashboard filtering ONLY. Serving resolvers join live tables;
    -- these are maintained by one writer and reconciled nightly.
    "headline_cache" text,
    "facets_cache" jsonb NOT NULL DEFAULT '[]'::jsonb,
    "eligible_cache" jsonb NOT NULL DEFAULT '[]'::jsonb,
    -- THE user-delete mechanism (C8). Never cleared by reappearance:
    -- disposition/suppressed_at/retired_at all collapse into this one column.
    "removed_at" timestamp with time zone,
    "review_flag" text,
    "first_seen_at" timestamp with time zone NOT NULL DEFAULT now(),
    "last_seen_at" timestamp with time zone NOT NULL DEFAULT now(),
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE INDEX "idx_content_items_user_kind" ON "content"."items" ("user_id", "kind")
    WHERE ("removed_at" IS NULL);
CREATE INDEX "idx_content_items_gc" ON "content"."items" ("last_seen_at")
    WHERE ("removed_at" IS NOT NULL);

-- ── source_items: the per-external-record unit. ────────────────────────────
-- `coord` = platform:account_ref:external_key — stable across reconnects,
-- which is what stops a reconnect looking like a mass deletion followed by a
-- mass creation.
CREATE TABLE "content"."source_items" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "coord" text NOT NULL,
    "stream_id" uuid,
    "record_key" text,
    "item_id" uuid REFERENCES "content"."items" ("id") ON DELETE SET NULL,
    "kind" text NOT NULL,
    "projector_version" integer NOT NULL DEFAULT 1,
    "first_seen_at" timestamp with time zone NOT NULL DEFAULT now(),
    "last_seen_at" timestamp with time zone NOT NULL DEFAULT now(),
    "removed_at" timestamp with time zone,
    CONSTRAINT "source_items_coord_unique" UNIQUE ("source_id", "coord")
);

CREATE INDEX "idx_source_items_item" ON "content"."source_items" ("item_id");
CREATE INDEX "idx_source_items_stream" ON "content"."source_items" ("stream_id");

-- ── identity_keys: evidence, NOT constraints. ──────────────────────────────
-- Deliberately NO unique index on (class, value): two sources reporting the
-- same ISRC is the exact signal the resolver consumes. A constraint here
-- would turn a merge candidate into a write failure.
CREATE TABLE "content"."identity_keys" (
    "id" bigserial PRIMARY KEY,
    "source_item_id" uuid NOT NULL REFERENCES "content"."source_items" ("id") ON DELETE CASCADE,
    "key_class" text NOT NULL,
    "key_value" text NOT NULL,
    "tier" text NOT NULL CHECK ("tier" IN ('joining', 'corroborating', 'evidential')),
    "created_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE INDEX "idx_identity_keys_lookup" ON "content"."identity_keys" ("key_class", "key_value");
CREATE INDEX "idx_identity_keys_source_item" ON "content"."identity_keys" ("source_item_id");

-- ── identity_decisions: the user overruling the machine (C8). ──────────────
CREATE TABLE "content"."identity_decisions" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
    "verdict" text NOT NULL CHECK ("verdict" IN ('same', 'different')),
    "left_coord" text NOT NULL,
    "right_coord" text NOT NULL,
    "decided_at" timestamp with time zone NOT NULL DEFAULT now(),
    "decided_by" text
);

CREATE UNIQUE INDEX "idx_identity_decisions_pair" ON "content"."identity_decisions"
    ("user_id", "left_coord", "right_coord");

-- ── item_anchors: stable ids across recomputation. ─────────────────────────
-- The resolver is pure and re-runnable, so item ids must NOT be re-minted on
-- every run: an anchor binds a coord to the item id it first resolved into,
-- and a split redirects rather than orphaning.
CREATE TABLE "content"."item_anchors" (
    "coord" text NOT NULL,
    "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "bound_at" timestamp with time zone NOT NULL DEFAULT now(),
    "superseded_by" uuid REFERENCES "content"."items" ("id"),
    PRIMARY KEY ("user_id", "coord")
);

CREATE INDEX "idx_item_anchors_item" ON "content"."item_anchors" ("item_id");

-- ── item_merges / candidates: the audit trail and the queue. ───────────────
CREATE TABLE "content"."item_merges" (
    "id" bigserial PRIMARY KEY,
    "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
    "kept_item_id" uuid,
    "discarded_item_id" uuid,
    "reason" text NOT NULL,
    "detail" jsonb NOT NULL DEFAULT '{}'::jsonb,
    "merged_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE TABLE "content"."identity_candidates" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
    "left_item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "right_item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "score" integer NOT NULL,
    "evidence" jsonb NOT NULL DEFAULT '{}'::jsonb,
    "dismissed_at" timestamp with time zone,
    "created_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX "idx_identity_candidates_pair" ON "content"."identity_candidates"
    ("user_id", "left_item_id", "right_item_id");
CREATE INDEX "idx_identity_candidates_open" ON "content"."identity_candidates" ("user_id")
    WHERE ("dismissed_at" IS NULL);

-- ── manual_overrides: the C2-compliant lock. ───────────────────────────────
-- A typed PER-COLUMN override row. This is what replaces is_manual flags:
-- editing one field freezes THAT field, not its siblings; reverting is
-- deleting the row; and a NULL value is an explicit clear rather than "unset".
CREATE TABLE "content"."manual_overrides" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "facet" text NOT NULL,
    "column_name" text NOT NULL,
    "value" jsonb,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT "manual_overrides_unique" UNIQUE ("item_id", "facet", "column_name")
);

-- ── Typed facets. Singletons: one row per (item, source). ──────────────────
-- Per-source rows are what make conflict resolution possible at all: we keep
-- what each source said and resolve at projection, rather than overwriting
-- and losing the disagreement.

CREATE TABLE "content"."f_text" (
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "headline" text,
    "body" text,
    "summary" text,
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    PRIMARY KEY ("item_id", "source_id")
);

CREATE TABLE "content"."f_link" (
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "url" text NOT NULL,
    "canonical_url" text,
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    PRIMARY KEY ("item_id", "source_id")
);

CREATE TABLE "content"."f_duration" (
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "seconds" integer,
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    PRIMARY KEY ("item_id", "source_id")
);

-- `verbatim` keeps the vendor's own wording ("3 months ago") for the dashboard
-- and provenance; the public document always renders the absolute form, which
-- is what makes a 7-day cached document safe (plan §9).
CREATE TABLE "content"."f_published" (
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "published_from" timestamp with time zone,
    "published_to" timestamp with time zone,
    "verbatim" text,
    "precision" text CHECK ("precision" IS NULL OR "precision" IN ('year', 'month', 'day', 'time')),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    PRIMARY KEY ("item_id", "source_id")
);

-- Wall clock + zone, never a bare UTC instant: an event at 7pm local stays at
-- 7pm local when a jurisdiction changes its DST rules. `content:retime` exists
-- for exactly that case.
CREATE TABLE "content"."f_occurrence" (
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "starts_at_local" timestamp without time zone,
    "ends_at_local" timestamp without time zone,
    "timezone" text,
    "zone_confidence" text CHECK ("zone_confidence" IS NULL OR "zone_confidence" IN ('explicit', 'inferred', 'assumed')),
    "starts_at_utc" timestamp with time zone,
    "is_all_day" boolean NOT NULL DEFAULT false,
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    PRIMARY KEY ("item_id", "source_id")
);

CREATE INDEX "idx_f_occurrence_upcoming" ON "content"."f_occurrence" ("starts_at_utc");

CREATE TABLE "content"."f_embed" (
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "provider" text NOT NULL,
    "embed_key" text NOT NULL,
    "variant" text,
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    PRIMARY KEY ("item_id", "source_id")
);

CREATE TABLE "content"."f_playable" (
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "stream_url" text,
    "preview_url" text,
    "is_explicit" boolean,
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    PRIMARY KEY ("item_id", "source_id")
);

CREATE TABLE "content"."f_authored" (
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "creator" text,
    "creator_url" text,
    "collaborators" jsonb,
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    PRIMARY KEY ("item_id", "source_id")
);

CREATE TABLE "content"."f_catalog" (
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "release_type" text,
    "track_number" integer,
    "disc_number" integer,
    "isrc" text,
    "gtin" text,
    "sku" text,
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    PRIMARY KEY ("item_id", "source_id")
);

CREATE TABLE "content"."f_place" (
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "venue_name" text,
    "address" text,
    "locality" text,
    "region" text,
    "country_code" text,
    "latitude" double precision,
    "longitude" double precision,
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    PRIMARY KEY ("item_id", "source_id")
);

CREATE TABLE "content"."f_rated" (
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "rating" double precision,
    "rating_max" double precision,
    "ratings_count" integer,
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    PRIMARY KEY ("item_id", "source_id")
);

CREATE TABLE "content"."f_review" (
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "author_name" text,
    "author_photo_url" text,
    "rating" double precision,
    "text" text,
    "reviewed_at" timestamp with time zone,
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    PRIMARY KEY ("item_id", "source_id")
);

CREATE TABLE "content"."f_channel" (
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "handle" text,
    "followers" integer,
    "avatar_url" text,
    "is_live" boolean,
    "verified" boolean,
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    PRIMARY KEY ("item_id", "source_id")
);

CREATE TABLE "content"."f_file" (
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "file_url" text,
    "mime_type" text,
    "size_bytes" bigint,
    "page_count" integer,
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    PRIMARY KEY ("item_id", "source_id")
);

-- ── Collections: set-union facets. Resolved by UNION, never by winner. ─────

-- media_assets are entities, not per-item rows: the same image used by three
-- items is one asset with one fingerprint and one palette.
CREATE TABLE "content"."media_assets" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
    "fingerprint" text NOT NULL,
    "source_url" text,
    "storage_path" text,
    "mime_type" text,
    "width" integer,
    "height" integer,
    "dims_confidence" text CHECK ("dims_confidence" IS NULL OR "dims_confidence" IN ('measured', 'declared', 'guessed')),
    "palette" jsonb,
    "variant_family" text CHECK ("variant_family" IS NULL OR "variant_family" IN ('google', 'shopify', 'ytimg', 'native', 'proxy')),
    "blurhash" text,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT "media_assets_fingerprint_unique" UNIQUE ("user_id", "fingerprint")
);

CREATE TABLE "content"."item_media" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "asset_id" uuid REFERENCES "content"."media_assets" ("id") ON DELETE SET NULL,
    "role" text NOT NULL CHECK ("role" IN ('cover', 'gallery', 'poster', 'avatar', 'logo')),
    "position" integer NOT NULL DEFAULT 0,
    "alt_text" text,
    "created_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE INDEX "idx_item_media_item" ON "content"."item_media" ("item_id", "role", "position");

-- Offers are a SET and are never resolved to a winner: two platforms selling
-- the same dish at different prices are both true, and hiding one would be a
-- lie about where the user can buy.
CREATE TABLE "content"."offers" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "channel" text,
    "variant_label" text,
    "amount_minor" bigint,
    "currency" text,
    "qualifier" text NOT NULL DEFAULT 'exact'
        CHECK ("qualifier" IN ('exact', 'from', 'upto', 'range', 'free', 'variable', 'on_request')),
    "amount_max_minor" bigint,
    "url" text,
    "availability" text,
    "updated_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE INDEX "idx_offers_item" ON "content"."offers" ("item_id");

CREATE TABLE "content"."item_variants" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "label" text NOT NULL,
    "sku" text,
    "position" integer NOT NULL DEFAULT 0
);

CREATE TABLE "content"."item_tags" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "tag" text NOT NULL,
    "tag_type" text
);

CREATE INDEX "idx_item_tags_item" ON "content"."item_tags" ("item_id");

CREATE TABLE "content"."f_action" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "intent" text NOT NULL,
    "label" text,
    "url" text NOT NULL,
    "position" integer NOT NULL DEFAULT 0
);

CREATE INDEX "idx_f_action_item" ON "content"."f_action" ("item_id");

CREATE TABLE "content"."item_refs" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "ref_type" text NOT NULL,
    "ref_value" text NOT NULL
);

-- ── Collections (categories/groupings) + slugs. ────────────────────────────
CREATE TABLE "content"."collections" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
    "parent_id" uuid REFERENCES "content"."collections" ("id") ON DELETE CASCADE,
    "label" text NOT NULL,
    "kind" text,
    "position" integer NOT NULL DEFAULT 0,
    "is_user_created" boolean NOT NULL DEFAULT false,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE INDEX "idx_collections_user" ON "content"."collections" ("user_id", "position");

CREATE TABLE "content"."collection_items" (
    "collection_id" uuid NOT NULL REFERENCES "content"."collections" ("id") ON DELETE CASCADE,
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "source_id" uuid REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "position" integer NOT NULL DEFAULT 0,
    PRIMARY KEY ("collection_id", "item_id")
);

CREATE TABLE "content"."item_slugs" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
    "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
    "slug" text NOT NULL,
    "is_current" boolean NOT NULL DEFAULT true,
    "created_at" timestamp with time zone NOT NULL DEFAULT now()
);

-- Non-partial unique: an old slug must keep pointing somewhere, so a
-- retired slug still occupies its name rather than being reusable.
CREATE UNIQUE INDEX "idx_item_slugs_unique" ON "content"."item_slugs" ("user_id", "slug");
CREATE INDEX "idx_item_slugs_item" ON "content"."item_slugs" ("item_id") WHERE "is_current";

-- ── RLS + grants. ──────────────────────────────────────────────────────────
DO $$
DECLARE t text;
BEGIN
    FOR t IN
        SELECT tablename FROM pg_tables WHERE schemaname = 'content'
    LOOP
        EXECUTE format('ALTER TABLE content.%I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('ALTER TABLE content.%I FORCE ROW LEVEL SECURITY', t);
        EXECUTE format('CREATE POLICY %I ON content.%I TO app_backend USING (true) WITH CHECK (true)', 'content_'||t||'_app_backend_all', t);
    END LOOP;
END $$;

GRANT USAGE ON SCHEMA "content" TO "service_role";
GRANT USAGE ON SCHEMA "content" TO "app_backend";
GRANT ALL ON ALL TABLES IN SCHEMA "content" TO "service_role";
GRANT ALL ON ALL TABLES IN SCHEMA "content" TO "app_backend";
GRANT ALL ON ALL SEQUENCES IN SCHEMA "content" TO "app_backend";
ALTER DEFAULT PRIVILEGES IN SCHEMA "content" GRANT ALL ON TABLES TO "service_role";
ALTER DEFAULT PRIVILEGES IN SCHEMA "content" GRANT ALL ON TABLES TO "app_backend";
ALTER DEFAULT PRIVILEGES IN SCHEMA "content" GRANT ALL ON SEQUENCES TO "app_backend";
