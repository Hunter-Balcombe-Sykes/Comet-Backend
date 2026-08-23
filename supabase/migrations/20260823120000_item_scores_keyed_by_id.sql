-- Item popularity families keyed by content.items.id (smart ordering v2,
-- docs/superpowers/plans/2026-08-23-smart-ordering-v2-handoff.md A1).
--
-- shop_product rows were keyed by f_catalog.handle and link_item rows by
-- f_link.url; every other family already keyed by item id. The scoring job
-- (analytics:compute-popularity) now writes item ids for every family and
-- PoolResolver reads them that way, so the legacy-keyed rows would never
-- match again and would only fade out over ~5 runs. Delete them outright —
-- they recompute within 15 minutes from the raw events.
--
-- ROLLBACK: none needed (derived rows, recomputed by the scheduled job).
BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

DELETE FROM analytics.content_popularity_scores
    WHERE content_type IN ('shop_product', 'link_item');

COMMIT;
