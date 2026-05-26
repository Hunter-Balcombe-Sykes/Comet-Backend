-- Cover three unindexed foreign keys flagged by index audit, and drop a
-- redundant single-column index on site.blocks.
--
-- Each FK uses ON DELETE SET NULL, so most historical rows will eventually
-- have NULL. Partial indexes (WHERE col IS NOT NULL) keep the index small
-- while still serving cascade lookups and "rows attributed to X" queries.

-- 1. analytics.lead_submissions.customer_id
--    Set when an anonymous lead becomes an attributed customer. Without this
--    index, customer hard-deletes and "leads-from-customer" lookups
--    seq-scan what will become the largest analytics table.
CREATE INDEX IF NOT EXISTS lead_submissions_customer_id_idx
    ON analytics.lead_submissions (customer_id)
    WHERE customer_id IS NOT NULL;

-- 2. brand.brand_partner_link_events.actor_professional_id
--    Audit log row recording which professional triggered a link-state event.
--    Low query frequency, but professional hard-deletes scan the entire
--    audit table and audit logs grow unbounded.
CREATE INDEX IF NOT EXISTS brand_partner_link_events_actor_idx
    ON brand.brand_partner_link_events (actor_professional_id)
    WHERE actor_professional_id IS NOT NULL;

-- 3. brand.brand_affiliate_invites.claimed_professional_id
--    Set when an invite is claimed. Small table today, but same cascade-scan
--    story; cheap to fix preemptively.
CREATE INDEX IF NOT EXISTS brand_affiliate_invites_claimed_idx
    ON brand.brand_affiliate_invites (claimed_professional_id)
    WHERE claimed_professional_id IS NOT NULL;

-- 4. Drop redundant single-column index on site.blocks.
--    link_blocks_professional_id_idx (professional_id) is a strict left-prefix
--    of link_blocks_pro_group_sort_idx (professional_id, block_group, sort_order),
--    so the planner will never prefer the narrower one. Dropping it removes
--    write amplification on every blocks INSERT/UPDATE.
DROP INDEX IF EXISTS site.link_blocks_professional_id_idx;
