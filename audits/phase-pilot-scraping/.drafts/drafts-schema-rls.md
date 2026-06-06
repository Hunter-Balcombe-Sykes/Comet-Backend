- [ ] **SCHEMA-1** · P2 — Missing RLS on `site.platform_connections` (tenant data in `site` schema)
    - **Where:** supabase/migrations/20260602150238_create_platform_connections.sql (entire file)
    - **Affects:** Defense-in-depth; if the shared `app_backend` role is used without application-level authorization, all rows are readable without tenant scoping.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - `ALTER TABLE site.platform_connections ENABLE ROW LEVEL SECURITY;`
        - Add RLS policies that use `current_setting('app.actor_id')` (or an app‑set claim) to filter by `user_id`, mirroring the owner‑check pattern already enforced in `IntegrationConnectionPolicy`.
    - **Technical:** Category (1). All tables in `site.*` containing tenant‑scoped data are expected to have RLS enabled. The migration creates the table but never enables RLS. The application authorizes every write/read through Laravel policies, so the risk is low today, but later raw‑query paths, infosec audits, or a future bug that bypasses the Policy layer could leak cross‑tenant data.
    - **Plain English:** Think of this as a second lock on a door that already has a keycode lock. The current keycode (the app’s permission system) works, but if the keycode ever fails or someone finds another way in, there is no deadbolt. Adding RLS is that deadbolt — it stops one user from accidentally seeing another user’s data no matter how the database is accessed.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.platform_connections (
            id                    uuid PRIMARY KEY,
            user_id               uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
            ...
        );
        -- No ALTER TABLE ... ENABLE ROW LEVEL SECURITY follows
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **SCHEMA-2** · P2 — Primary key column `id` defined without a DB‑side default (no `gen_random_uuid()`)
    - **Where:** supabase/migrations/20260602150238_create_platform_connections.sql:1
    - **Affects:** Raw INSERTs and potential future reconcile/backfill jobs that bypass Eloquent (e.g. admin scripts, data migrations). The application currently uses Eloquent `HasUuids` trait, so day‑to‑day writes are untouched.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Alter the column to add `DEFAULT gen_random_uuid()`: `ALTER TABLE site.platform_connections ALTER COLUMN id SET DEFAULT gen_random_uuid();`
        - Audit other UUID PKs in the schema for the same gap.
    - **Technical:** Category (8). The Partna convention is that every UUID primary key should have a database‑side default so that raw INSERTs (or Eloquent with unexpected configuration) cannot insert a NULL id. Relying solely on the application to generate UUIDs is fragile and breaks the principle that the schema should be self‑describing. The `HasUuids` trait generates the UUID in PHP, which works, but the DB default provides a safety net.
    - **Plain English:** It’s like a car that only starts with a specific key fob; if you ever need to use a manual key (a raw database insert), you’re out of luck because the ignition slot is missing. Adding the default is like installing a backup keyhole that Just Works for anyone who knows how to turn a key.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.platform_connections (
            id                    uuid PRIMARY KEY,
            ...
        );
        -- No DEFAULT clause on id
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCHEMA-3** · P2 — Indexes created without `CONCURRENTLY` in a migration that may be replayed on future environments
    - **Where:** supabase/migrations/20260602150238_create_platform_connections.sql (all three `CREATE INDEX` statements)
    - **Affects:** Deployments that run this migration against a database that already contains rows — the table lock taken by standard `CREATE INDEX` will block writes for the duration of the index build.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - For future migrations, always use `CREATE INDEX CONCURRENTLY IF NOT EXISTS` (and wrap in a transaction‑breaking pattern if the migration tool requires it).
        - Document the existing indexes as having been built on an empty table so the risk is known; no retroactive change needed unless a zero‑downtime re‑index is required later.
    - **Technical:** Category (6). The migration creates three indexes with plain `CREATE INDEX IF NOT EXISTS`. On a table that already has data, this would acquire a `ShareLock` and prevent concurrent inserts/updates until the index build completes. While the table is likely empty at first creation, the pattern violates the deployment‑safety standard. The canonical fix is `CONCURRENTLY`, which builds the index without locking the table (at the cost of a slightly longer overall runtime).
    - **Plain English:** Imagine you’re adding a new shelf to a busy library. The current method locks the entire library while you install it — no one can take out or return books. The safer method (CONCURRENTLY) lets you install the shelf while the library stays open, with only a brief pause near the end. Since the library was empty when the shelf went in, no real harm was done, but if the shelf ever needs to be rebuilt in a busy library, you’d want the safe method.
    - **Evidence:**
        ```sql
        CREATE INDEX IF NOT EXISTS idx_platform_connections_unique_active
            ON site.platform_connections (user_id, platform, resource_id)
            WHERE deleted_at IS NULL;

        CREATE INDEX IF NOT EXISTS idx_platform_connections_user_platform_sort
            ON site.platform_connections (user_id, platform, sort_order)
            WHERE deleted_at IS NULL;

        CREATE INDEX IF NOT EXISTS idx_platform_connections_last_refreshed
            ON site.platform_connections (last_refreshed_at)
            WHERE deleted_at IS NULL AND is_active;
        ```
    - `[DRAFT, confidence: 0.7]`
