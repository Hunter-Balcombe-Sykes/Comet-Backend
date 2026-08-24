# Migration Safety Audit — 2026-08-24

**Branch:** development
**Lens:** Migration safety: lock-on-deploy risk, backfill ordering, online DDL hygiene, reversibility
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- supabase/migrations/20260820100000_storefronts_products_autoselected_at.sql
- supabase/migrations/20260820110000_single_account_social_convergence.sql
- supabase/migrations/20260823100000_unified_actions.sql
- supabase/migrations/20260823100001_unified_actions_validate.sql
- supabase/migrations/20260823120000_item_scores_keyed_by_id.sql
- supabase/migrations/20260823130000_service_category_family.sql
- supabase/migrations/20260823130001_service_category_family_validate.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#MIG-1** · P1 — Inline full-table DML scrubs bundled with DDL in `unified_actions.sql`, contrary to the project's own `#SCHEMA-2` guidance; the same anti-pattern recurs in the companion migration
    - **Where:** supabase/migrations/20260823100000_unified_actions.sql:28-47; supabase/migrations/20260823120000_item_scores_keyed_by_id.sql:16-17
    - **Affects:** `analytics.content_popularity_scores` (read on every public-sitepage pool-ordering resolution) and `analytics.action_events` (write-heavy analytics ingest path); deploy-time availability of the migration itself.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract the two `DELETE`s and the `site.sites` `UPDATE` out of the migration transaction into a post-deploy artisan command or chunked job, per `docs/migration-guidelines.md` §Full-table-scan data scrubs (#SCHEMA-2) — the guidelines doc's own worked "Avoid" example is `UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design';`, essentially identical to the statement shipped here.
        - If it must stay inline, batch each statement by primary key (`WHERE id = ANY(...)` over bounded chunks, one batch per transaction) so no single statement runs unbounded against `analytics.action_events` or `analytics.content_popularity_scores`.
        - Apply the same fix to the `DELETE FROM analytics.content_popularity_scores WHERE content_type IN ('shop_product', 'link_item')` in `20260823120000_item_scores_keyed_by_id.sql` — same root cause, same fix.
    - **Technical:** `20260823100000_unified_actions.sql` wraps `ALTER TABLE ... DROP/ADD CONSTRAINT` on `analytics.content_popularity_scores` together with two unbounded `DELETE`s and an unbounded `UPDATE` on `site.sites` inside one `BEGIN...COMMIT`, guarded only by `SET LOCAL statement_timeout = '10s'`. `analytics.content_popularity_scores` carries an index on `(site_id, content_type, rank)` — not leading on `content_type` alone — so `WHERE content_type = 'page' OR content_type = 'action'` is not efficiently servable and falls back to a scan; `analytics.action_events` has indexes only on `occurred_at` and `(site_id, occurred_at)`, so `WHERE action_id !~ '^(page|platform|item|category):'` (a negated regex, unindexable in principle) is a guaranteed sequential scan. Because these statements share the transaction that opened with the `ALTER TABLE` on `content_popularity_scores`, any lock that ALTER holds persists until `COMMIT` — i.e. for the full duration of the trailing `DELETE`/`UPDATE`, not just the ALTER's own (fast) execution. The 10s `statement_timeout` bounds the worst case to a clean abort-and-rollback rather than a half-applied state (no data-loss risk from a partial apply), but as `action_events`/`content_popularity_scores` row counts grow with analytics ingest volume, this statement is increasingly likely to hit that timeout and block the deploy outright, requiring a retry. This is exactly the pattern `docs/migration-guidelines.md` §SCHEMA-2 tells engineers to extract into a post-deploy job. Per the "Prod-is-behind" caveat, this migration (like all ~70 post-baseline migrations) is still unapplied on prod and will land as part of the gated re-baseline — elevated one tier accordingly.
    - **Plain English:** This update does three separate chores — reshaping a rulebook, throwing out old receipts, and clearing an old setting off every customer's file — all in one uninterruptible operation, and two of those chores search the entire pile one item at a time because there's no shortcut index. As the pile of receipts and customer files grows, this single operation takes longer and risks timing out and being rolled back entirely, stalling the deploy. The safer approach — which the team's own written guidelines already recommend — is to do the receipt/records cleanup afterward, in small batches, instead of holding everything up front.
    - **Evidence:**
        ```sql
        BEGIN;
        SET LOCAL lock_timeout      = '2s';
        SET LOCAL statement_timeout = '10s';

        ALTER TABLE analytics.content_popularity_scores
            DROP CONSTRAINT IF EXISTS content_popularity_scores_content_type_check;

        DELETE FROM analytics.content_popularity_scores
            WHERE content_type = 'page' OR content_type = 'action';

        ALTER TABLE analytics.content_popularity_scores
            ADD CONSTRAINT content_popularity_scores_content_type_check
            CHECK (content_type IN (
                'action', 'shop_product', 'menu_item', 'menu_category',
                'service', 'block', 'gallery_item', 'engine_item',
                'listen_item', 'watch_item', 'link_item'
            )) NOT VALID;

        DELETE FROM analytics.action_events
            WHERE action_id !~ '^(page|platform|item|category):';

        UPDATE site.sites
            SET settings = settings - 'smart_actions' - 'manual_actions' - 'manual_order_pools'
            WHERE settings ?| ARRAY['smart_actions', 'manual_actions', 'manual_order_pools'];

        COMMIT;
        ```
        ```sql
        DELETE FROM analytics.content_popularity_scores
            WHERE content_type IN ('shop_product', 'link_item');
        ```

## P2 — Should fix

- [ ] **#MIG-2** · P2 — Unbounded window-function backfill UPDATE on `site.platform_connections` has no `lock_timeout`/`statement_timeout` guard
    - **Where:** supabase/migrations/20260820110000_single_account_social_convergence.sql:23-40
    - **Affects:** `site.platform_connections` (read/written on every social-connect and sitepage-resolution flow); deploy-time lock-wait behaviour is unbounded rather than a clean, fast-failing abort.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the statement in `BEGIN; SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s'; ... COMMIT;`, matching the pattern already used correctly in the sibling `2026-08-23` migrations.
        - No batching is required at today's row counts (file's own comment: "exactly one affected user" on dev), but the timeout guard should still be present so a future stuck-lock-wait fails fast instead of queuing indefinitely.
    - **Technical:** `site.platform_connections` is not one of the four tables `docs/migration-guidelines.md` §SCALE-3 names as CI-enforced (`site.design_kits`, `site.sites`, `site.blocks`, `core.users`), so `guard:no-unsafe-migrations` does not fail this file — but the underlying risk (an `UPDATE ... FROM (SELECT ... ROW_NUMBER() OVER (...))` that scans every non-deleted `routing_class = 'social'` row) is the same class of unbounded DML the guideline exists to bound. The statement is otherwise well-constructed: it is idempotent (re-running produces no additional matches once losers are soft-deleted, since the CTE only considers `deleted_at IS NULL` rows) and the migration documents an explicit "ROLLBACK: NONE — roll forward" rationale. Adding the timeout pair costs nothing and brings it in line with the convention the rest of the `2026-08` migrations already follow.
    - **Plain English:** This step scans through every social-media connection to find and soft-delete duplicates, but unlike its sibling migrations that same week, it has no time limit on how long it's allowed to hold things up. Right now the affected list is tiny (one test account), so it's low risk today, but there's no safety cutoff if that ever changes — the fix is just to add the same two-line timeout guard the other migrations already use.
    - **Evidence:**
        ```sql
        with ranked as (
            select id,
                   row_number() over (
                       partition by user_id, surface_key
                       order by is_active desc, is_primary desc, created_at asc, id asc
                   ) as rn
            from site.platform_connections
            where routing_class = 'social'
              and deleted_at is null
        )
        update site.platform_connections pc
        set deleted_at = now(), is_active = false, updated_at = now()
        from ranked
        where ranked.id = pc.id
          and ranked.rn > 1;
        ```

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

- **#MIG-1 — Inline full-table DML scrubs bundled with DDL** · DB migration change touching live-traffic-adjacent analytics/`site.sites` tables — own plan + sign-off.
- **#MIG-2 — Missing lock/statement timeout guard on social-connection backfill** · DB migration change — own plan + sign-off.
