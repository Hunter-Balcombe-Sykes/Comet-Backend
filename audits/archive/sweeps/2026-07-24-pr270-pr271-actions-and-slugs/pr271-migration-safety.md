# Migration Safety Audit — 2026-07-24

**Branch:** HEAD
**Lens:** Migration safety — lock-on-deploy risk, backfill ordering, online DDL hygiene, reversibility
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- supabase/migrations/20260724120000_create_item_slugs.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#MIG-1** · P2 — `CREATE TABLE ... REFERENCES core.users(id)` runs with no lock/statement timeout guard
    - **Where:** supabase/migrations/20260724120000_create_item_slugs.sql:31-41
    - **Affects:** Deploy of this migration via `supabase db push` against the dev Supabase project — which, per current environment reality, is the live database serving both `dev-api.partna.au` and `api.partna.au` traffic against `core.users` right now, not a traffic-free bootstrap DB.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `CREATE TABLE` + three `CREATE INDEX` statements in an explicit transaction with the standard guard pair, per `docs/migration-guidelines.md` §Lock and statement timeouts / `supabase/migrations/CONVENTIONS.md` §8:
          ```sql
          BEGIN;
          SET LOCAL lock_timeout      = '2s';
          SET LOCAL statement_timeout = '10s';
          -- existing CREATE TABLE / COMMENT ON / CREATE INDEX statements
          COMMIT;
          ```
        - No other change needed — the three indexes are plain (non-`CONCURRENTLY`) and safe inside a transaction since the table is born empty in the same migration.
    - **Technical:** `item_slugs_user_fk FOREIGN KEY (user_id) REFERENCES core.users(id)` is added inline as part of `CREATE TABLE`, but Postgres processes table-level FK constraints declared in `CREATE TABLE` the same way it processes an explicit `ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY`: it must acquire a `SHARE ROW EXCLUSIVE` lock on the *referenced* table (`core.users`) to register the dependency and guarantee the referenced row can't be concurrently deleted. `SHARE ROW EXCLUSIVE` conflicts with `ROW EXCLUSIVE` — the lock every `INSERT`/`UPDATE`/`DELETE` on `core.users` takes — so this statement can queue behind a live write, and it in turn blocks new writes that queue behind it, with no bound on how long it waits. `core.users` is one of exactly four tables `supabase/migrations/CONVENTIONS.md` §8 names as requiring the `BEGIN; SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s'; ... COMMIT;` wrapper for "every migration that runs DDL/DML against a live-traffic table," and that guard is meant to be enforced going forward by `scripts/guard-no-unsafe-migrations.php` Check 5 for any migration timestamped after `20260711999999` (this file, `20260724120000`, is well past that cutoff). However, Check 5's regex (`/\b(?:ALTER\s+TABLE|UPDATE)\s+(?:ONLY\s+)?core\.users\b/i`) only matches statements that directly `ALTER`/`UPDATE` `core.users` — it does not match a `CREATE TABLE` elsewhere that merely `REFERENCES core.users(id)`, so this file passed CI without tripping the guard it should logically fall under. Per CLAUDE.md's current environment state, `development` is presently the live database backing both domains, so this isn't a theoretical future-prod concern — the next `supabase db push` applies this against a table taking real concurrent writes today.
    - **Plain English:** Creating this new table quietly needs to "check in" with the existing users table to set up the link between them, and that check-in briefly reserves the users table in a way that blocks other people from editing it — and blocks the migration itself if someone else is mid-edit when it tries. Without a time limit on that reservation, a bad-timing collision could leave the deploy hanging indefinitely and pile up blocked user updates behind it. Adding a short timeout means the migration gives up and reports failure within a couple of seconds instead of stalling the whole deploy.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.item_slugs (
            id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            user_id    uuid NOT NULL,
            item_type  text NOT NULL,
            item_key   text NOT NULL,   -- menu item UUID, or event SHA1 hex (EventsPayload::id)
            slug       text NOT NULL,
            is_current boolean NOT NULL DEFAULT true,
            created_at timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT item_slugs_user_fk FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE CASCADE,
            CONSTRAINT item_slugs_type_check CHECK (item_type IN ('event', 'menu_item'))
        );
        ```

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

- **#MIG-1 — Missing lock/statement timeout guard on `core.users`-referencing `CREATE TABLE`** · DB migration/schema change — must run alone with its own plan + sign-off per the always-standalone rule for migration-touching findings.
