-- DINT-3: NOT adding the FKs the finding proposes, and recording why in the
-- catalog so the next audit does not re-raise it.
--
-- content.item_merges is an APPEND-ONLY AUDIT LEDGER whose entire purpose is to
-- outlive the rows it names. Every referential action available is wrong here:
--
--   ON DELETE SET NULL  -- what the finding proposes -- would empty the ledger
--     on write. ItemMerger::merge() inserts the item_merges row at
--     app/Services/Content/ItemMerger.php:58-68 and then, five statements later
--     IN THE SAME TRANSACTION, hard-deletes the discarded item at
--     ItemMerger.php:76. The FK would null discarded_item_id on the row just
--     written, for EVERY user-driven merge. ProjectionWriter::mergeInto()
--     (app/Ingest/Projection/ProjectionWriter.php:504-518) has the same shape.
--     kept_item_id is not exempt either: a later merge that discards a
--     previously-kept item would null the older entry.
--   ON DELETE CASCADE would delete the audit row outright -- strictly worse.
--   RESTRICT / NO ACTION would make every merge fail at the delete.
--
-- The columns are therefore raw ids by design, exactly as an application log
-- would store them. Integrity is provided instead by user_id's FK
-- (20260727140000_content_schema.sql:137, ON DELETE CASCADE from core.users),
-- which is what makes the ledger disappear on account deletion.
--
-- Pinned by tests/Postgres/ItemMergeAuditSurvivalTest.php.

COMMENT ON COLUMN "content"."item_merges"."kept_item_id" IS
    'content.items.id at merge time. DELIBERATELY NO FK (audit DINT-3): item_merges is an append-only '
    'ledger that must outlive the items it names; ItemMerger::merge() hard-deletes the discarded item in '
    'the same transaction that writes this row, so ON DELETE SET NULL would empty the ledger on write '
    'and CASCADE would delete it. May name a row that no longer exists -- that is the point.';

COMMENT ON COLUMN "content"."item_merges"."discarded_item_id" IS
    'content.items.id at merge time. DELIBERATELY NO FK -- see kept_item_id. This item is hard-deleted '
    'by ItemMerger.php:76 immediately after this row is written.';
