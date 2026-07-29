-- #MIG-2 step 1 of 3 (CONVENTIONS.md §2 and §3).
--
-- NOT VALID adds the constraint with a catalog write only — no full-table scan
-- under ACCESS EXCLUSIVE. New INSERTs/UPDATEs are enforced immediately;
-- existing rows are checked by ...110003.
--
-- The two chk_..._not_null constraints are the four-step pattern's scaffolding
-- and are dropped again in ...110003 once SET NOT NULL has taken over.
--
-- DROP CONSTRAINT IF EXISTS first makes this file idempotent AND makes it a
-- clean no-op on dev, where platform_connections_routing_class_check already
-- exists (validated) from the original 20260727110000 apply: it is dropped and
-- re-added NOT VALID here, then re-validated in ...110003, landing on the exact
-- same end state.
--
-- These are added AFTER the backfill, not before, on purpose: a NOT VALID CHECK
-- is enforced against the NEW row image on every UPDATE, so adding
-- "routing_class IS NOT NULL" before the routing_class backfill would make the
-- surface_key pass (which leaves routing_class NULL) fail.

BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE "site"."platform_connections"
    DROP CONSTRAINT IF EXISTS "platform_connections_routing_class_check";
ALTER TABLE "site"."platform_connections"
    DROP CONSTRAINT IF EXISTS "chk_platform_connections_surface_key_not_null";
ALTER TABLE "site"."platform_connections"
    DROP CONSTRAINT IF EXISTS "chk_platform_connections_routing_class_not_null";

ALTER TABLE "site"."platform_connections"
    ADD CONSTRAINT "platform_connections_routing_class_check"
    CHECK ("routing_class" IN ('social', 'content', 'events', 'shop', 'booking', 'reservations', 'ordering', 'link', 'ignore'))
    NOT VALID;

ALTER TABLE "site"."platform_connections"
    ADD CONSTRAINT "chk_platform_connections_surface_key_not_null"
    CHECK ("surface_key" IS NOT NULL) NOT VALID;

ALTER TABLE "site"."platform_connections"
    ADD CONSTRAINT "chk_platform_connections_routing_class_not_null"
    CHECK ("routing_class" IS NOT NULL) NOT VALID;

COMMIT;
