-- Sections + versioned documents (plan §7/§9): the surface model that
-- replaces the Pool enum, and the one artefact the public site reads.
--
-- The shape of the idea: PAGES are the navigation unit (one nav row per page,
-- never per section — a menu with five groups is still one "Menu" link), and
-- SECTIONS are what a page is made of. A section decides its own membership
-- from a bounded rule, plus the user's pins and excludes. Nothing carries an
-- `is_selected` column, because selection is a section's opinion rather than
-- a property of an item.
--
-- ROLLBACK: DROP TABLE IF EXISTS site.design_kit_restyles, site.site_build_state,
--             site.site_documents, content.source_routes, site.section_groups,
--             site.section_items, site.sections, site.pages
--           CASCADE;
--           IRREVERSIBLE: every curated section, every pin/exclude in
--           section_items, every built document version. DocumentBuilder can
--           rebuild only for sites whose sections survive elsewhere -- they
--           do not.

-- ── pages ──────────────────────────────────────────────────────────────────
CREATE TABLE "site"."pages" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "site_id" uuid NOT NULL REFERENCES "site"."sites" ("id") ON DELETE CASCADE,
    "key" text NOT NULL,
    "label" text NOT NULL,
    "sort_order" integer NOT NULL DEFAULT 0,
    "order_mode" text NOT NULL DEFAULT 'manual' CHECK ("order_mode" IN ('manual', 'auto')),
    "is_hidden" boolean NOT NULL DEFAULT false,
    -- From a closed registry, checked at WRITE time (plan §7): the read-time
    -- capability filter dies with SitepageId, so a page that should not exist
    -- must fail to be created rather than be quietly hidden on render.
    "capability" text,
    "preset_key" text,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT "pages_key_unique_per_site" UNIQUE ("site_id", "key")
);

CREATE INDEX "idx_pages_site_order" ON "site"."pages" ("site_id", "sort_order")
    WHERE (NOT "is_hidden");

-- ── sections ───────────────────────────────────────────────────────────────
CREATE TABLE "site"."sections" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "page_id" uuid NOT NULL REFERENCES "site"."pages" ("id") ON DELETE CASCADE,
    "site_id" uuid NOT NULL REFERENCES "site"."sites" ("id") ON DELETE CASCADE,
    -- Nullable, unique where set: carries preset lineage and gives support a
    -- stable handle ("your Menu section"), while analytics keys stay on ids.
    "key" text,
    "label" text,
    "slot" text NOT NULL DEFAULT 'body' CHECK ("slot" IN ('body', 'header_actions', 'footer')),
    "kind" text NOT NULL CHECK ("kind" IN ('collection', 'richtext', 'contact_form', 'newsletter', 'map', 'document', 'policy')),
    "sort_order" integer NOT NULL DEFAULT 0,
    -- The bounded rule DSL: at most 5 predicates over 7 operators, validated
    -- in code and rendered to the user as a sentence rather than a query.
    "rule" jsonb NOT NULL DEFAULT '{}'::jsonb,
    "mode" text NOT NULL DEFAULT 'automatic' CHECK ("mode" IN ('hand_picked', 'automatic', 'mixed')),
    "order_by" text NOT NULL DEFAULT 'recency',
    "limit_n" integer,
    "group_by" text CHECK ("group_by" IS NULL OR "group_by" IN ('category', 'source', 'month', 'letter')),
    "render" text NOT NULL DEFAULT 'cards' CHECK ("render" IN ('cards', 'rows', 'tiles', 'buttons', 'carousel')),
    "density" text,
    "price_mode" text,
    "min_items" integer NOT NULL DEFAULT 1,
    "on_empty" text NOT NULL DEFAULT 'hide' CHECK ("on_empty" IN ('hide', 'show_message', 'show_anyway')),
    -- inherit = follow the stream's SourceProfile default. A Catalogue hides
    -- what vanished; a durable kind says "no longer on X" instead.
    "stale_display" text NOT NULL DEFAULT 'inherit' CHECK ("stale_display" IN ('inherit', 'show', 'hide')),
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX "idx_sections_key_unique" ON "site"."sections" ("site_id", "key")
    WHERE ("key" IS NOT NULL);
CREATE INDEX "idx_sections_page" ON "site"."sections" ("page_id", "sort_order");

-- Per-section curation. `pinned` and `excluded` are the ONLY two verbs: an
-- ingest run may never write either (C4).
CREATE TABLE "site"."section_items" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "section_id" uuid NOT NULL REFERENCES "site"."sections" ("id") ON DELETE CASCADE,
    "item_id" uuid NOT NULL,
    "state" text NOT NULL CHECK ("state" IN ('pinned', 'excluded')),
    -- Fractional so a drag between two neighbours never rewrites the list.
    "sort_key" double precision,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT "section_items_unique" UNIQUE ("section_id", "item_id")
);

CREATE INDEX "idx_section_items_section" ON "site"."section_items" ("section_id", "state");

-- Group label/order overrides — needed because a group_by other than
-- category has no natural row to hang a label on.
CREATE TABLE "site"."section_groups" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "section_id" uuid NOT NULL REFERENCES "site"."sections" ("id") ON DELETE CASCADE,
    "group_key" text NOT NULL,
    "label" text,
    "sort_order" integer NOT NULL DEFAULT 0,
    "is_hidden" boolean NOT NULL DEFAULT false,
    CONSTRAINT "section_groups_unique" UNIQUE ("section_id", "group_key")
);

-- ── source_routes: what a connection feeds, per destination (plan §8). ─────
CREATE TABLE "content"."source_routes" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
    "connection_id" uuid REFERENCES "site"."platform_connections" ("id") ON DELETE CASCADE,
    "source_id" uuid REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
    "destination_kind" text NOT NULL CHECK ("destination_kind" IN ('item_kind', 'profile_field')),
    "destination" text NOT NULL,
    "is_enabled" boolean NOT NULL DEFAULT true,
    "sync_scope" text NOT NULL DEFAULT 'all' CHECK ("sync_scope" IN ('all', 'latest_n')),
    "scope_selection" integer,
    "priority" integer NOT NULL DEFAULT 100,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT "source_routes_unique" UNIQUE ("connection_id", "destination_kind", "destination")
);

CREATE INDEX "idx_source_routes_user" ON "content"."source_routes" ("user_id");

-- ── The public artefact: versioned documents + a CAS build protocol. ───────
CREATE TABLE "site"."site_documents" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "site_id" uuid NOT NULL REFERENCES "site"."sites" ("id") ON DELETE CASCADE,
    "version" bigint NOT NULL,
    "channel" text NOT NULL DEFAULT 'live' CHECK ("channel" IN ('live', 'draft')),
    "document" jsonb NOT NULL,
    -- Byte-identical rebuilds insert nothing and purge nothing: the hash is
    -- what makes a rebuild storm harmless.
    "content_hash" text NOT NULL,
    "builder_revision" integer NOT NULL,
    "warnings" jsonb NOT NULL DEFAULT '[]'::jsonb,
    "built_at" timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT "site_documents_version_unique" UNIQUE ("site_id", "channel", "version")
);

CREATE INDEX "idx_site_documents_current" ON "site"."site_documents" ("site_id", "channel", "version" DESC);

CREATE TABLE "site"."site_build_state" (
    "site_id" uuid PRIMARY KEY REFERENCES "site"."sites" ("id") ON DELETE CASCADE,
    -- Monotonic: every content write bumps this. The builder compares it to
    -- built_revision under CAS, which is what stops two concurrent builders
    -- from publishing each other's stale output.
    "content_revision" bigint NOT NULL DEFAULT 0,
    "built_revision" bigint NOT NULL DEFAULT 0,
    "building_since" timestamp with time zone,
    "popularity_floor_at" timestamp with time zone,
    "last_built_at" timestamp with time zone,
    "updated_at" timestamp with time zone NOT NULL DEFAULT now()
);

-- ── Design-kit restyle snapshots (plan §13): undo for "restyle from brand". ─
CREATE TABLE "site"."design_kit_restyles" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "site_id" uuid NOT NULL REFERENCES "site"."sites" ("id") ON DELETE CASCADE,
    "snapshot" jsonb NOT NULL,
    "applied_at" timestamp with time zone NOT NULL DEFAULT now(),
    "undone_at" timestamp with time zone
);

-- ── RLS + grants. ──────────────────────────────────────────────────────────
DO $$
DECLARE t text;
BEGIN
    FOR t IN SELECT unnest(ARRAY['pages', 'sections', 'section_items', 'section_groups', 'site_documents', 'site_build_state', 'design_kit_restyles'])
    LOOP
        EXECUTE format('ALTER TABLE site.%I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('ALTER TABLE site.%I FORCE ROW LEVEL SECURITY', t);
        EXECUTE format('CREATE POLICY %I ON site.%I TO app_backend USING (true) WITH CHECK (true)', 'site_'||t||'_app_backend_all', t);
    END LOOP;

    EXECUTE 'ALTER TABLE content.source_routes ENABLE ROW LEVEL SECURITY';
    EXECUTE 'ALTER TABLE content.source_routes FORCE ROW LEVEL SECURITY';
    EXECUTE 'CREATE POLICY content_source_routes_app_backend_all ON content.source_routes TO app_backend USING (true) WITH CHECK (true)';
END $$;

GRANT ALL ON ALL TABLES IN SCHEMA "site" TO "app_backend";
GRANT ALL ON ALL TABLES IN SCHEMA "content" TO "app_backend";
GRANT ALL ON ALL TABLES IN SCHEMA "site" TO "service_role";
GRANT ALL ON ALL TABLES IN SCHEMA "content" TO "service_role";
