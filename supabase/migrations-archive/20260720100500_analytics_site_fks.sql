-- SCHEMA-3 (NARROWED) — FK on analytics.item_views.site_id and
-- analytics.content_popularity_scores.site_id, ON DELETE CASCADE, mirroring
-- the sibling analytics.section_views_site_fk
-- (20260526000000_baseline_standalone_user.sql:1235). Both tables were
-- created 2026-07-09, after the FK-safety convention was established
-- (GRANDFATHERED_CUTOFF = 20260514100000), and dropped the FK entirely
-- rather than just its NOT VALID qualifier — this is a real regression from
-- the established sibling pattern, not a deliberate simplification.
--
-- Two deliberate narrowings vs. the audit finding's original proposal:
--
--   (a) NO user_id FK. The finding also proposed
--       item_views.user_id REFERENCES core.users(id) ON DELETE CASCADE.
--       Omitted: site.sites already cascades from core.users
--       (sites_professional_fk, baseline:719), so a user deletion reaches
--       these rows via site_id -> site.sites -> core.users regardless.
--       item_views.user_id is documented in its own column comment as
--       "nullable = fail-open" (a denormalized convenience column, not a
--       cleanup path) — adding a second FK there is a write-path failure
--       mode for zero additional cleanup benefit.
--
--   (b) content_popularity_scores is deliberately NOT added to
--       PurgeRawAnalyticsEvents::TABLES (verified against
--       app/Console/Commands/PurgeRawAnalyticsEvents.php — it is absent from
--       the TABLES map). computed_at is a recompute timestamp, not an event
--       time: age-based purging on that column would delete LIVE ranks for
--       any site the nightly job stopped recomputing (e.g. a temporarily
--       inactive site), not stale orphans. ON DELETE CASCADE from this new
--       FK is the correct and sufficient cleanup mechanism for this table —
--       no purge-command change needed.
--
-- Write-path note: PostgresEventWriter flushes analytics.item_views rows via
-- insertOrIgnore, which suppresses PRIMARY KEY / UNIQUE conflicts but NOT FK
-- violations — so a site deleted between an item-seen event's enqueue and its
-- batch flush can now fail that row (previously it would have written a
-- silently orphaned row instead). This brings item_views to parity with
-- section_views, which has carried this exact FK shape since the baseline
-- without incident.
--
-- This file adds both FKs NOT VALID only. VALIDATE runs in a separate file
-- (20260720100600) so validation can be deferred independently if a future
-- environment turns out to have orphans this dev census didn't catch.
--
-- Dev census (glncumufgaqcmqhzwrxm, 2026-07-20): item_views.site_id orphans =
-- 0; content_popularity_scores.site_id orphans = 0.

-- Window 1 (item_views): add the FK in NOT VALID form.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE analytics.item_views
    ADD CONSTRAINT item_views_site_fk
    FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE NOT VALID;

COMMIT;

-- Window 2 (content_popularity_scores): add the FK in NOT VALID form, in its
-- own transaction so the two tables' catalog locks don't overlap.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE analytics.content_popularity_scores
    ADD CONSTRAINT content_popularity_scores_site_fk
    FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE NOT VALID;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE analytics.item_views DROP CONSTRAINT IF EXISTS item_views_site_fk;
-- COMMIT;
-- BEGIN;
-- ALTER TABLE analytics.content_popularity_scores DROP CONSTRAINT IF EXISTS content_popularity_scores_site_fk;
-- COMMIT;
