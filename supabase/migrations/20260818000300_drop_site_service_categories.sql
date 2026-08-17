-- Services cutover — legacy teardown, 3 of 3. Parent last.
--
-- ROLLBACK: NONE. Pre-image: the same pg_dump named in 20260818000100.
--
-- content.* twin: content.collections kind='service_category', keyed
-- (user_id, kind, external_ref) on the vendor's own category id (3b §3.3).
-- Ordering moves from this table's sort_order to
-- content.collections.position, renumbered through
-- ServiceCollections::reposition() under the service-categories:{user}
-- advisory key.
--
-- Dies with the table: service_categories_pro_all / _public_read /
-- _staff_all (RLS), trg_service_categories_updated_at (trigger),
-- service_categories_user_fk, service_categories_id_user_unique,
-- service_categories_unique_title_per_user.
--
-- Also gone with it, and worth naming because they were load-bearing until
-- this project: the by-id fall-backs on both staff category controllers, and
-- the raw `service-layout:{user_id}` advisory key that
-- StaffServiceCategoryManagementController::destroyLegacy() held solely to
-- detach assignment rows before soft-deleting a category here. Nothing in the
-- service lane takes that key any more.
--
-- No IF EXISTS — fail loudly on schema drift rather than silently skipping.

BEGIN;

DROP TABLE site.service_categories;

COMMIT;
