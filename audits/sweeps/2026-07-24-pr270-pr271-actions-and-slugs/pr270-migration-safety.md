# Migration Safety Audit — 2026-07-24

**Branch:** HEAD
**Lens:** Migration safety: lock-on-deploy risk, backfill ordering, online DDL hygiene, reversibility
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- supabase/migrations/20260723090000_create_action_events.sql
- supabase/migrations/20260720100500_analytics_site_fks.sql (precedent cross-check)
- scripts/guard-no-unsafe-migrations.php (CI guard cross-check)
- supabase/migrations/CONVENTIONS.md, docs/migration-guidelines.md (convention reference)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 1 complete

---

## P3 — Nice to have

- [ ] **MIG-1** · P3 — New `analytics.action_events` table's FK to `site.sites` isn't wrapped in a lock/statement timeout guard
    - **Where:** `supabase/migrations/20260723090000_create_action_events.sql:29-46`
    - **Affects:** Deploy operator only — the `CREATE TABLE` + inline FK constraint against `site.sites(id)` momentarily requires a lock on `site.sites` to register the constraint. On a brand-new, empty child table this completes in single-digit milliseconds, so there's no realistic write-stall risk today. The only downside of the missing guard is that if some other session happens to be holding a conflicting lock on `site.sites` at the moment this migration runs, the statement queues indefinitely instead of failing fast with a clear `lock_timeout` error — which would stall the rest of the sequential `db push`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the file's DDL in `BEGIN; SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s'; ... COMMIT;` per `docs/migration-guidelines.md` §Lock and statement timeouts / `CONVENTIONS.md` §8.
        - No other change needed — the table is created empty in this same transaction, so the existing inline FK/CHECK (no `NOT VALID` split) is correct as-is per the file's own header comment.
    - **Technical:** `scripts/guard-no-unsafe-migrations.php` Check 5 only regex-matches `ALTER TABLE <hot_table>` / `UPDATE <hot_table>` against `HOT_TABLES` (`site.design_kits`, `site.sites`, `site.blocks`, `core.users`) — it does not match a `CREATE TABLE ... REFERENCES site.sites(id)` clause on a *new, unrelated* table, so this file passes CI clean despite touching a hot table via FK. That's a real blind spot in the guard's pattern-matching, not evidence the file is safe by convention: the sibling migration `20260720100500_analytics_site_fks.sql` (adding `item_views_site_fk` / `content_popularity_scores_site_fk` via `ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY REFERENCES site.sites(id) ... NOT VALID`) voluntarily added the `BEGIN; SET LOCAL lock_timeout...; COMMIT;` guard even though its own `ALTER TABLE` targets weren't `site.sites` itself — establishing that the team's actual practice is to guard *any* FK reference into a hot table, not just direct `ALTER TABLE`/`UPDATE` on it. `20260723090000_create_action_events.sql` is the first post-`TIMEOUT_GUARD_CUTOFF` (`20260711999999`) migration referencing `site.sites` via FK that omits this guard, breaking that precedent. Given the child table is empty and created in the same transaction, real-world risk is negligible — this is a hygiene/consistency gap, not a lock-contention risk today.
    - **Plain English:** This new database table has a rule pointing back to the main "sites" table, and setting that rule up briefly needs to grab a lock on that busy table. Right now if something else happened to be holding onto that lock at the exact same moment, this update would just wait forever instead of giving up after two seconds with a clear error. Adding the two-second timeout is a small safety net — cheap insurance for a step that's virtually always instant, matching how the team already guards similar cases.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS analytics.action_events (
            ...
            CONSTRAINT action_events_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE,
            CONSTRAINT action_events_event_check CHECK (event IN ('seen', 'tap'))
        );
        ```

## Suggested Bundled Sessions

None — a single low-effort finding doesn't warrant bundling overhead.

## Standalone — do NOT bundle

- **MIG-1 — Missing lock/statement timeout guard** · S-effort, single-file, no dependencies — safe to fix inline without a separate plan, but listed standalone since there's nothing to bundle it with in this scope.
