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
-- ALREADY APPLIED on dev (ledger: 20260823120000). No DDL here — a single
-- DELETE, no ALTER, so there is no lock to amplify by bundling it with schema
-- work. Do NOT re-express this as a re-runnable command: it is a ONE-SHOT
-- bounded by "the scoring job had not yet run" at the moment it executed.
-- Dev data confirms non-item-id keys are still being written TODAY (hours
-- after this ran) for shop_product/link_item/listen_item/service — a
-- re-runnable version of this same key-shape predicate would delete rows the
-- scoring job is actively producing, not just legacy leftovers. Any future
-- equivalent needs a `computed_at < <cutoff>` bound to distinguish "old" from
-- "in-progress".
--
-- ROLLBACK: none needed (derived rows, recomputed by the scheduled job).
BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

DELETE FROM analytics.content_popularity_scores
    WHERE content_type IN ('shop_product', 'link_item');

COMMIT;
