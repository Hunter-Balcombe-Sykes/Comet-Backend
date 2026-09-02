-- ROLLBACK: DROP TABLE site.logo_candidates;
-- Logo candidates (setup-dialog run A.10, decision 13). For a sign-up
-- business build the auto-grabber STORES every slot-passing logo candidate
-- (bytes mirrored to the media disk) instead of silently uploading the
-- first passer — the setup dialog's logo pass offers them and the person
-- picks. Promote reads the mirrored bytes back through uploadSingleton, so
-- the choice works even when the source URL has rotted by setup time.
-- New table — inline CHECKs are lock-safe here (no rows to validate).
BEGIN;
CREATE TABLE "site"."logo_candidates" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "site_id" uuid NOT NULL REFERENCES "site"."sites" ("id") ON DELETE CASCADE,
    "slot" text NOT NULL CHECK ("slot" IN ('square', 'full')),
    "source_url" text NULL,
    "storage_path" text NOT NULL,
    "trust" integer NOT NULL DEFAULT 0,
    "width" integer NULL,
    "height" integer NULL,
    "state" text NOT NULL DEFAULT 'proposed' CHECK ("state" IN ('proposed', 'promoted', 'dismissed')),
    "created_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE INDEX idx_logo_candidates_site ON "site"."logo_candidates" ("site_id", "state");
COMMIT;
