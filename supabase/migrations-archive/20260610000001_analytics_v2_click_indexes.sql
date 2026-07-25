-- Analytics v2 — partial indexes on the (populated) link_clicks table.
-- Separate file, no BEGIN/COMMIT: CONCURRENTLY cannot run inside a transaction
-- (CONVENTIONS §1 two-file pattern). The dashboard groups clicks by platform
-- and by product over a (user, date-range) window; most legacy rows have
-- neither label, so partial indexes keep them out.

CREATE INDEX CONCURRENTLY IF NOT EXISTS link_clicks_user_platform_idx
    ON analytics.link_clicks (user_id, platform, occurred_at DESC)
    WHERE platform IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS link_clicks_user_product_idx
    ON analytics.link_clicks (user_id, product_id, occurred_at DESC)
    WHERE product_id IS NOT NULL;
