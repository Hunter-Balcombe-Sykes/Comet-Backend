-- Services cutover — legacy teardown, 2 of 3.
--
-- ROLLBACK: NONE. Pre-image: the same pg_dump named in 20260818000100, which
-- covers all three tables and was count-matched against live exactly.
--
-- content.* twin: content.items kind='service' — the owner-authored half
-- under the manual source since slice 3a, the Fresha half under the
-- connection source since 3b. Everything this table's columns carried has a
-- home there, and each one is exercised by a test rather than assumed:
--   * ordering        -> site.section_items.sort_key, ONE scale for both
--                        halves (spec §3.4). sort_key carries no uniqueness
--                        constraint, which is why the whole class of 23505
--                        collisions this table produced cannot recur.
--   * is_active       -> a pool exclude for the manual half; the connection
--                        blob's hiddenServiceIds for the Fresha half (D3a).
--   * deleted_at with deleted_origin='user'
--                     -> content.items.removed_at, one-way: ProjectionWriter
--                        never clears it, so a re-listing vendor cannot
--                        resurrect an owner-deleted service.
--   * deleted_at with deleted_origin='sync'
--                     -> content.source_items.removed_at, which the projector
--                        DOES clear on reappearance. Restore-on-return is
--                        native there; nothing had to be built for it.
--   * is_manual       -> the presence of a content.manual_overrides row.
--   * source/external_id -> content.sources.kind + content.source_items.record_key.
--
-- Dies with the table: services_anon_select / services_pro_all /
-- services_staff_all (RLS), set_timestamp_services (trigger),
-- services_user_fk, and services_user_sort_order_uq — the global partial
-- UNIQUE (user_id, sort_order) WHERE deleted_at IS NULL that forced every
-- reorder to renumber both halves together.
--
-- ACCEPTED LOSS (owner ruling 2026-08-17, spec §2.1): the legacy uuids cease
-- to resolve anywhere. No mapping is minted; the management surface addresses
-- services by content.items.id, and the wire manifest records the break. The
-- 25 'sync'-deleted rows are not carried — the connector reproduces that
-- state natively (3a §2).
--
-- COUNT DRIFT, recorded rather than smoothed over: the spec's §1.2 census read
-- 82 rows (28 soft-deleted = 25 'sync' + 3 NULL-origin) on 2026-08-17. At drop
-- time it reads 79 (25 soft-deleted, all 'sync'). Nothing in this project
-- writes the table and no migration ran between those readings, so the 3
-- pre-deleted_origin rows were removed by something external. The backup gate
-- re-derived the counts immediately before the dump rather than trusting
-- either figure, and the dump holds the 79 that actually existed.
--
-- No IF EXISTS — fail loudly on schema drift rather than silently skipping.

BEGIN;

DROP TABLE site.services;

COMMIT;
