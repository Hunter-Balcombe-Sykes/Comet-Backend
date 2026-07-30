-- Content selection: add the 'ig-photo' entry type — an individual Instagram
-- post image picked from the library (referenced by its R2-mirrored URL),
-- distinct from the ig-post/ig-reel AUTO slots which carry no reference.
-- external_ref holds the image URL, mirroring the google-photo shape.
--
-- RESTRUCTURED 2026-07-30 (audit LC-ROLLBACK). This file originally re-added both
-- CHECKs as a plain `ADD CONSTRAINT ... CHECK (...)`, which validates every existing
-- row under ACCESS EXCLUSIVE (CONVENTIONS.md §2) and failed guard Check 3. That
-- failure aborted `composer test` at the guard step, BEFORE Pest — so the suite had
-- not executed in CI since this file merged (c3bca835, 2026-07-30T03:41Z). Both ADDs
-- are now NOT VALID and the two VALIDATE statements moved to 20260730090001, per the
-- repo's established _not_valid / _validate file pair (20260729150000 + ...150001).
--
-- It is applied to DEV (2026-07-30) but NOT to PROD — prod's ledger holds only
-- 20260726000000 and 20260726101212 — so it is still PENDING against production and
-- executes there at the next db push. A forward repair migration cannot make an
-- earlier file's lock window shorter, so the restructure is done here, in place. The
-- RESULTING SCHEMA is byte-for-byte what dev already carries (both CHECKs present,
-- same expressions, validated — PostgreSQL leaves no trace of having passed through
-- NOT VALID once VALIDATE has run), which is the condition
-- docs/migration-guidelines.md §"Editing already-applied migrations" sets for editing
-- an applied file. Same manoeuvre, same reasoning as 20260727110000 / ...110002.
--
-- Both changes WIDEN an allowed set (adding 'ig-photo'), so every existing row
-- already satisfies the new constraints and ...090001's VALIDATE cannot fail.
--
-- DROP ... IF EXISTS makes the file replay-safe and a clean no-op on dev — the same
-- reasoning 20260727110002 records.
--
-- ROLLBACK: CONDITIONAL — re-adding the narrower CHECKs FAILS (23514) while any
--           entry_type = 'ig-photo' row exists, so those rows must go first, which
--           is user data loss rather than a clean revert:
--             DELETE FROM site.content_selection WHERE entry_type = 'ig-photo';
--             ALTER TABLE site.content_selection
--               DROP CONSTRAINT IF EXISTS content_selection_entry_type_check,
--               DROP CONSTRAINT IF EXISTS content_selection_ref_shape;
--           then re-add both from their pre-image at
--           20260726000000_baseline_pilot.sql:1520 and :1522. 20260730090001 has no
--           independent reverse; dropping the constraints here covers it.

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE "site"."content_selection"
    DROP CONSTRAINT IF EXISTS "content_selection_entry_type_check";

ALTER TABLE "site"."content_selection"
    ADD CONSTRAINT "content_selection_entry_type_check"
    CHECK (("entry_type" = ANY (ARRAY['upload'::"text", 'google-photo'::"text", 'ig-reel'::"text", 'ig-post'::"text", 'ig-photo'::"text"])))
    NOT VALID;

ALTER TABLE "site"."content_selection"
    DROP CONSTRAINT IF EXISTS "content_selection_ref_shape";

ALTER TABLE "site"."content_selection"
    ADD CONSTRAINT "content_selection_ref_shape"
    CHECK (
        (("entry_type" = 'upload'::"text") AND ("media_id" IS NOT NULL) AND ("external_ref" IS NULL))
        OR (("entry_type" = ANY (ARRAY['google-photo'::"text", 'ig-photo'::"text"])) AND ("external_ref" IS NOT NULL) AND ("media_id" IS NULL))
        OR (("entry_type" = ANY (ARRAY['ig-reel'::"text", 'ig-post'::"text"])) AND ("media_id" IS NULL) AND ("external_ref" IS NULL))
    )
    NOT VALID;

COMMIT;
