-- #SCALE-1, the occurrence half. The events pool sorts on a correlated
-- `MIN(starts_at_utc)` with the same shape as the recency sort above.
--
-- The existing idx_f_occurrence_upcoming is on (starts_at_utc) alone and cannot
-- serve this probe — the correlation is on item_id, which that index does not
-- lead with. Separate file because a CONCURRENTLY statement must be alone in its
-- own file (CONVENTIONS.md §1, guard Check 6).
-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS "content"."idx_f_occurrence_item_starts";
CREATE INDEX CONCURRENTLY IF NOT EXISTS "idx_f_occurrence_item_starts" ON "content"."f_occurrence" ("item_id", "starts_at_utc");
