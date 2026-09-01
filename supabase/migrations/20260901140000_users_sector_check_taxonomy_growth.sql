-- The sector taxonomy grew (F5, 2026-09-01: eleven sectors for the Google
-- categories that classified to nothing — retail-store, grocer, liquor-store,
-- market, veterinarian, medical-clinic, optometrist, pet-services, laundry,
-- locksmith, museum-gallery) and the CHECK did not: the first live backfill
-- write failed users_sector_check on readings-carlton -> retail-store.
--
-- The list below is GENERATED from SectorTaxonomy::all() at the commit that
-- widened it, not hand-typed — the PHP taxonomy is the source of truth and
-- this constraint is its mirror. Regenerate rather than editing by hand.
--
-- ROLLBACK: re-add the previous 70-slug constraint from git history.

-- Split per CONVENTIONS §2 (2026-09-01): the original single
-- `ADD CONSTRAINT ... CHECK` took ACCESS EXCLUSIVE on core.users and scanned
-- every row under it — a write outage on a populated table, and the reason
-- guard-no-unsafe-migrations rejected this file. NOT VALID does the catalog
-- write and releases; VALIDATE re-scans under SHARE UPDATE EXCLUSIVE, which
-- lets reads and writes continue. Guard Check 8 requires the COMMIT between
-- them — bundling holds the heavier lock through the whole scan and defeats
-- the split.
--
-- Already applied on dev (as ledger version 20260901033334, stamped by the
-- Supabase MCP at apply time rather than from this filename); the end state is
-- identical, so this rewrite governs a from-zero apply — notably the prod
-- cutover, where core.users will not stay empty.

-- Step A — swap the constraint in, unvalidated.
BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE "core"."users" DROP CONSTRAINT IF EXISTS "users_sector_check";
ALTER TABLE "core"."users" ADD CONSTRAINT "users_sector_check"
    CHECK (sector IS NULL OR sector = ANY (ARRAY['restaurant'::text, 'cafe'::text, 'bakery'::text, 'bar'::text, 'food-truck'::text, 'caterer'::text, 'personal-chef'::text, 'hair-salon'::text, 'barber'::text, 'nail-technician'::text, 'makeup-artist'::text, 'esthetician'::text, 'spa'::text, 'tattoo-artist'::text, 'brows-lashes'::text, 'personal-trainer'::text, 'gym'::text, 'yoga-instructor'::text, 'nutritionist'::text, 'physiotherapist'::text, 'chiropractor'::text, 'therapist'::text, 'dentist'::text, 'medical-clinic'::text, 'optometrist'::text, 'veterinarian'::text, 'accountant'::text, 'lawyer'::text, 'financial-advisor'::text, 'consultant'::text, 'real-estate-agent'::text, 'insurance-broker'::text, 'mortgage-broker'::text, 'marketing-agency'::text, 'it-services'::text, 'virtual-assistant'::text, 'clothing-boutique'::text, 'jewellery'::text, 'florist'::text, 'gift-shop'::text, 'homewares'::text, 'artisan-maker'::text, 'retail-store'::text, 'grocer'::text, 'liquor-store'::text, 'market'::text, 'plumber'::text, 'electrician'::text, 'builder'::text, 'painter'::text, 'cleaner'::text, 'landscaper'::text, 'handyman'::text, 'removalist'::text, 'pest-control'::text, 'pet-services'::text, 'laundry'::text, 'locksmith'::text, 'accommodation'::text, 'event-venue'::text, 'event-planner'::text, 'wedding-planner'::text, 'bartender'::text, 'mechanic'::text, 'car-detailer'::text, 'auto-electrician'::text, 'tyre-service'::text, 'photographer'::text, 'videographer'::text, 'graphic-designer'::text, 'artist'::text, 'musician'::text, 'content-creator'::text, 'writer'::text, 'museum-gallery'::text, 'tutor'::text, 'life-coach'::text, 'music-teacher'::text, 'driving-instructor'::text, 'dance-instructor'::text, 'course-creator'::text, 'other'::text])) NOT VALID;

COMMIT;

-- Step B — validate in its OWN transaction.
BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE "core"."users" VALIDATE CONSTRAINT "users_sector_check";

COMMIT;
