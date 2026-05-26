-- Rename sidest_staff table to partna_staff (brand rename: Side St → Partna).
-- Mirrors the precedent at 20260404000003_rename_comet_staff_to_sidest_staff.sql.
--
-- Postgres updates RLS policies, FKs, indexes, and triggers automatically via
-- OID, so a single ALTER TABLE is sufficient. Policy NAMES (e.g.
-- sidest_staff_select_authenticated) remain as-is, matching the prior pattern
-- where the Comet→Sidest rename did not rename policies either.
ALTER TABLE core.sidest_staff RENAME TO partna_staff;
