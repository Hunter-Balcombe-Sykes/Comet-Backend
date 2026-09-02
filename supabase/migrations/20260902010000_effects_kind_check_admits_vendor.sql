-- Wave 4 wiring follow-up (2026-09-02): the ScrapeCreators lanes record
-- their billed calls as kind='vendor' (BilledEffectContext('vendor', …) in
-- every Item 8/10/11 driver), and the live effects_kind_check — written
-- 2026-07-29 against the then-complete writer set — rejects it, so every
-- eager run of the new lanes died on 23514 before the vendor call was even
-- attempted. Caught live 2026-09-02 (first eager runs after the wiring
-- deploy); the suite never trips it because tests run with billed effects
-- recording disabled, so nothing writes this table there.
--
-- Same NOT VALID + VALIDATE shape as the 2026-07-29 pair. Existing rows all
-- carry the old kinds, so VALIDATE cannot fail.
--
-- ROLLBACK:
--   ALTER TABLE ingest.effects DROP CONSTRAINT IF EXISTS effects_kind_check;
--   ALTER TABLE ingest.effects ADD CONSTRAINT effects_kind_check
--       CHECK (kind IN ('http', 'actor', 'api', 'ai')) NOT VALID;

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE "ingest"."effects" DROP CONSTRAINT IF EXISTS "effects_kind_check";
ALTER TABLE "ingest"."effects"
    ADD CONSTRAINT "effects_kind_check" CHECK ("kind" IN ('http', 'actor', 'api', 'ai', 'vendor')) NOT VALID;

COMMIT;

-- VALIDATE in its OWN transaction (CONVENTIONS.md §2): bundled with the
-- ADD ... NOT VALID above, the validation scan would inherit that statement's
-- heavier lock instead of running under SHARE UPDATE EXCLUSIVE, which is a
-- write stall on a populated table. Split 2026-09-02 — the guard
-- (scripts/guard-no-unsafe-migrations.php check 8) had been reporting this,
-- but CI's `test` job aborts at the composer-audit step BEFORE reaching the
-- guard, so nothing enforced it.
BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE "ingest"."effects" VALIDATE CONSTRAINT "effects_kind_check";

COMMIT;
