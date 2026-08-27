-- Person-scoped reviews (owner, 2026-08-28): persist Fresha's structured
-- staff attribution. FreshaReviewProjector has emitted `staff_name` since
-- T23b, but ProjectionWriter's column list never carried it, so the value was
-- silently dropped at write time. It is the professional's OWN name (never
-- reviewer PII), and the partna reviews scope matches on it.
--
-- ROLLBACK: ALTER TABLE "content"."f_review" DROP COLUMN "staff_name";

ALTER TABLE "content"."f_review" ADD COLUMN IF NOT EXISTS "staff_name" text NULL;
