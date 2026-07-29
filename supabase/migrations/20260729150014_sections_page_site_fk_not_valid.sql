-- DINT-7. site.sections carries both page_id and site_id as independent NOT
-- NULL FKs to different tables (20260727150000_sections_and_documents.sql:36-37)
-- with nothing correlating them, so a section could claim site A while its page
-- belongs to site B. SectionPolicy.php:69 authorises off sections.site_id
-- directly while other paths join through pages -- the two would disagree.
--
-- A COMPOSITE FK rather than the trigger the finding suggests:
--   * declarative -- no PL/pgSQL function to keep in sync, nothing to forget on
--     a COPY or a manual UPDATE, and it shows up in \d site.sections;
--   * checked on INSERT *and* on UPDATE of either column, which a BEFORE
--     trigger only achieves if it is written for both;
--   * a plain CHECK cannot express it at all -- CHECK may not contain a
--     subquery, which is presumably why the column was left unguarded.
--
-- ON UPDATE CASCADE: pages.site_id is effectively immutable today, but if a
-- page were ever moved between sites its sections must follow rather than break.
-- ON DELETE CASCADE matches the existing page_id FK's behaviour exactly, so
-- deleting a page still removes its sections.
--
-- The pre-existing single-column FK sections.page_id -> pages.id is left in
-- place. It is logically subsumed by this one, but dropping it is a separate
-- ACCESS EXCLUSIVE alter with no benefit; the duplicate check costs one extra
-- index probe per insert on a table that sees a handful of writes per site.
--
-- Data: dev 1 section, 0 mismatches; 20260729150011 repairs any stray first.
--
-- ROLLBACK: ALTER TABLE site.sections DROP CONSTRAINT IF EXISTS sections_page_site_fk;

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE "site"."sections"
    ADD CONSTRAINT "sections_page_site_fk"
    FOREIGN KEY ("page_id", "site_id") REFERENCES "site"."pages" ("id", "site_id")
    ON UPDATE CASCADE ON DELETE CASCADE
    NOT VALID;

COMMIT;
