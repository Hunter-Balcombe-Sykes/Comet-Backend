-- Field bindings (plan §14): per-field priority rows that decide which source
-- may write which workplace-identity field — the explicit successor to the
-- IdentitySync LAW ("business: Google overwrites; partna: Google fills
-- blanks"), which until now lived only as a boolean branch in code.
--
-- One row = (site, field, source). `priority` orders sources per field:
-- 0 is reserved for `manual` and always wins — a manual row IS the field-side
-- lock (C2: no is_locked flag anywhere; the row is the lock). `mode` carries
-- the law's two verbs: `overwrite` (the source is authoritative for the
-- field) vs `fill_blank` (the source may only fill an empty field).
-- `is_enabled` is the platform-side gate — a user switching a platform's
-- identity feed off keeps the row (and its priority) but stops its writes.
--
-- Rows are SEEDED BY PRESET at instantiation (business preset: GBP
-- overwrite; partna preset: GBP fill-blank) and are deliberately sparse: a
-- missing binding means "this source may not write this field" — the
-- resolver fails closed.
--
-- The sector law is NOT expressed here: sector lives on core.users with its
-- own provenance column and its own rule (manual permanent; first non-Google
-- source wins), carried verbatim in FieldBindingResolver's sector path.

BEGIN;

CREATE TABLE "site"."field_bindings" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "site_id" uuid NOT NULL REFERENCES "site"."sites" ("id") ON DELETE CASCADE,
    -- A workplace identity field name (name, phone, website, address_line1…).
    -- Kept TEXT rather than a CHECK: the field set grows with the identity
    -- streams and the resolver validates against its own closed list.
    "field" text NOT NULL,
    -- 'manual' or an ingest source key ('google_business', 'instagram', …).
    "source_key" text NOT NULL,
    -- Lower wins. 0 is manual's seat, enforced below.
    "priority" integer NOT NULL DEFAULT 100,
    "mode" text NOT NULL DEFAULT 'fill_blank' CHECK ("mode" IN ('overwrite', 'fill_blank')),
    "is_enabled" boolean NOT NULL DEFAULT true,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT "field_bindings_unique" UNIQUE ("site_id", "field", "source_key"),
    -- Manual is priority 0 and priority 0 is manual — the invariant the
    -- resolver's "manual always wins" rests on, held by the schema.
    CONSTRAINT "field_bindings_manual_priority" CHECK (
        ("source_key" = 'manual' AND "priority" = 0)
        OR ("source_key" <> 'manual' AND "priority" > 0)
    )
);

CREATE INDEX "idx_field_bindings_site_field"
    ON "site"."field_bindings" ("site_id", "field", "priority");

ALTER TABLE "site"."field_bindings" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "site"."field_bindings" FORCE ROW LEVEL SECURITY;
CREATE POLICY "site_field_bindings_app_backend_all" ON "site"."field_bindings"
    TO "app_backend" USING (true) WITH CHECK (true);
GRANT ALL ON TABLE "site"."field_bindings" TO "app_backend";
GRANT ALL ON TABLE "site"."field_bindings" TO "service_role";

COMMIT;
