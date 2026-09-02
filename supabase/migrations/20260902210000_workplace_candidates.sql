-- Google Business listing candidates (setup-dialog run A.5, decision 6).
-- FreshaWorkplaceLinker used to search, pick ONE confident venue and connect
-- it in a single call, refusing on ambiguity — runners-up were discarded.
-- Candidates now persist so the setup dialog's listing pass can ask instead:
-- accept connects (state adopted, siblings superseded), dismiss records the
-- refusal. Business accounts never get rows here — their brand IS the listing.
CREATE TABLE "site"."workplace_candidates" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
    "site_id" uuid REFERENCES "site"."sites" ("id") ON DELETE CASCADE,
    "place_id" text NOT NULL,
    "name" text NOT NULL,
    "address" text,
    "lat" double precision,
    "lng" double precision,
    "photo_url" text,
    "rating" numeric(3, 2),
    "review_count" integer,
    "source" text NOT NULL CHECK ("source" IN ('bio_mention', 'fresha')),
    "corroboration" jsonb NOT NULL DEFAULT '[]'::jsonb,
    "state" text NOT NULL DEFAULT 'proposed'
        CHECK ("state" IN ('proposed', 'adopted', 'dismissed', 'superseded')),
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT "workplace_candidates_user_place_unique" UNIQUE ("user_id", "place_id")
);

CREATE INDEX "idx_workplace_candidates_user_state"
    ON "site"."workplace_candidates" ("user_id", "state");
