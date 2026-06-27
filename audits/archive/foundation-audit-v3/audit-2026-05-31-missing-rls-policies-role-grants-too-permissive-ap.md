Good. The timestamp collision is confirmed in the glob output — both `20260530000000_drop_workplace_hours.sql` and `20260530000000_grant_moderation_schema_to_app_backend.sql` exist alongside `20260530000050_grant_moderation_schema_to_app_backend.sql`. And `core.customers` was moved to `site.customers` in the reorganization, so the overpermissive staff policy correctly targets `site.customers` post-move.

I have all the evidence I need. Here is the final adjudicated audit:

---

`★ Insight ─────────────────────────────────────`
**Key patterns observed in this codebase worth flagging:**
1. **RLS and schema exposure are independent.** Listing `site` in `api.schemas` grants PostgREST visibility; RLS controls who can see which rows. A new table in an API-exposed schema with no `ENABLE ROW LEVEL SECURITY` is fully visible to any role that has table-level SELECT — design_kits was created without RLS in the same migration stroke that dropped the old themes table, a classic "create new, forget the security annotation" miss.
2. **Migration timestamp uniqueness is a hard constraint.** Supabase's CLI tracks applied migrations by the numeric version prefix. Two files with `20260530000000_*` will resolve to the same version key — one silently wins, the other may never be tracked as applied.
3. **Dropping a table doesn't automatically prune later migrations that reference it.** The csam_quarantine drop and the index migration were authored in the opposite order they should run; `IF NOT EXISTS` on `CREATE INDEX` only suppresses "index already exists" errors, not "table does not exist" errors.
`─────────────────────────────────────────────────`

# Schema Authorization & RLS Audit — 2026-05-31

**Branch:** development
**Lens:** Missing RLS policies, role grants too permissive, app_backend privileges, audit schema SELECT/INSERT only, schema-level authz gaps, unsafe seed data, search_path correctness, Supabase project config
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- supabase/migrations/20260526000000_baseline_standalone_user.sql
- supabase/migrations/20260527010000_reorganize_schemas.sql
- supabase/migrations/20260527030000_rename_professional_to_user.sql
- supabase/migrations/20260527070000_skeleton_system_cleanup.sql
- supabase/migrations/20260527080000–20260530140000 (design_kit column series)
- supabase/migrations/20260528000000_create_moderation_schema.sql
- supabase/migrations/20260529200000_remove_csam_pipeline_tables.sql
- supabase/migrations/20260530000000_grant_moderation_schema_to_app_backend.sql
- supabase/migrations/20260530000050_grant_moderation_schema_to_app_backend.sql
- supabase/migrations/20260530000400_add_csam_quarantine_case_id_idx.sql
- supabase/config.toml
- config/database.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#SCHEMA-1** · P1 — Orphaned index migration targets already-dropped table, breaks clean `db push`
    - **Where:** supabase/migrations/20260530000400_add_csam_quarantine_case_id_idx.sql:7–8
    - **Affects:** Any fresh database push — dev re-provisioning, prod promotion, CI reset — will fail at this migration with `ERROR: relation "moderation.csam_quarantine" does not exist`. The CSAM pipeline was decommissioned in `20260529200000` but this follow-up index file was never removed, leaving the migration history in a state that cannot cleanly apply on a new database.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete `supabase/migrations/20260530000400_add_csam_quarantine_case_id_idx.sql` — the table it targets no longer exists.
        - If the csam_quarantine table is ever reintroduced, add the index in the same migration that recreates the table.
    - **Technical:** `CREATE INDEX CONCURRENTLY IF NOT EXISTS` suppresses "index already exists" errors only; it does not suppress "relation does not exist." The table `moderation.csam_quarantine` is definitively dropped in `20260529200000_remove_csam_pipeline_tables.sql`, which runs before this file. The memory file confirms two known fresh-DB ordering bugs are fixed but uncommitted — this is one of them. The fix is deletion, not an `IF EXISTS` guard (there is no such syntax for `CREATE INDEX`).
    - **Plain English:** We removed a feature's database tables, but we left behind a note saying "now add an index to those tables." When we try to set up a brand new database (for a new developer, a staging environment, or production), the system follows all the notes in order and then crashes on that one because the table it's trying to index was already deleted earlier in the same set of notes. The fix is to throw away the index note.
    - **Evidence:**
        ```sql
        -- 20260529200000_remove_csam_pipeline_tables.sql (runs first):
        DROP TABLE IF EXISTS moderation.csam_quarantine;

        -- 20260530000400_add_csam_quarantine_case_id_idx.sql (runs after):
        CREATE INDEX CONCURRENTLY IF NOT EXISTS csam_quarantine_case_id_idx
            ON moderation.csam_quarantine (case_id);  -- table no longer exists; IF NOT EXISTS does not suppress this error
        ```

- [ ] **#RLS-1** · P1 — `site.design_kits` has no Row-Level Security; any authenticated user can read all design tokens via Supabase API
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql (table created without RLS; confirmed absent across all subsequent migrations via Grep)
    - **Affects:** Any user with a valid Supabase JWT — including any account created during the pilot — can query `GET /rest/v1/design_kits` and receive every other user's design configuration: color palettes, typography choices, border radii, spacing scales, button colors, icon sizes. The `site` schema is listed in `api.schemas` in `config.toml`, making the table reachable via PostgREST. `app_backend` (the Laravel user) is unaffected because it bypasses RLS, but direct Supabase API access is wide open.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a new migration that enables RLS and creates owner + staff + public-read policies mirroring the pattern used on `site.sites`:
            ```sql
            ALTER TABLE site.design_kits ENABLE ROW LEVEL SECURITY;
            -- Owner full access
            CREATE POLICY design_kits_owner_all ON site.design_kits FOR ALL TO authenticated
                USING (EXISTS (
                    SELECT 1 FROM site.sites s JOIN core.users u ON u.id = s.user_id
                    WHERE s.id = design_kits.site_id AND u.auth_user_id = auth.uid() AND u.deleted_at IS NULL
                ))
                WITH CHECK (...same...);
            -- Staff full access
            CREATE POLICY design_kits_staff_all ON site.design_kits FOR ALL TO authenticated
                USING (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid()))
                WITH CHECK (...);
            -- Anon read for published sites (required if public site rendering ever bypasses app_backend)
            CREATE POLICY design_kits_public_read ON site.design_kits FOR SELECT TO anon
                USING (EXISTS (SELECT 1 FROM site.sites s WHERE s.id = design_kits.site_id AND s.is_published = true));
            ```
        - Consider adding `FORCE ROW LEVEL SECURITY` as done for `core.feedback` in `20260526210002_feedback_hardening.sql`.
    - **Technical:** The table was created in `20260527070000_skeleton_system_cleanup.sql` as part of the skeleton system rollout. The existing `ALTER DEFAULT PRIVILEGES IN SCHEMA site GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend` (baseline section 12) automatically covers app_backend for future site-schema tables, so no additional backend grant is needed. The gap is in the `authenticated`/`anon` roles' row-level isolation. Every other tenant-bound table in the `site` schema (`site.sites`, `site.blocks`, `site.site_media`, `site.services`, etc.) has RLS enabled and an owner policy — this table is the sole exception in the schema.
    - **Plain English:** Every user on the platform customises their public page's look — colors, fonts, spacing, button styles. All of that is stored in one table. Right now that table has no lock on it. Anyone who creates an account can use Supabase's public API to read everyone else's design settings at once — like someone being able to walk through every other client's branding folder just because they're in the same building. We need a lock that only lets each person see their own folder.
    - **Evidence:**
        ```sql
        -- 20260527070000_skeleton_system_cleanup.sql:
        CREATE TABLE site.design_kits (
          site_id UUID PRIMARY KEY REFERENCES site.sites(id) ON DELETE CASCADE
        );
        -- No ALTER TABLE site.design_kits ENABLE ROW LEVEL SECURITY follows
        -- Grep across all 53 migration files confirms zero RLS statements targeting design_kits
        ```

---

## P2 — Should fix

- [ ] **#RLS-3** · P2 — `app_backend` holds `BYPASSRLS` with full schema-wide CRUD; no least-privilege scoping beyond the append-only audit tables
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql (section 12, role permissions)
    - **Affects:** If Laravel's database credentials (`DB_PASSWORD` / Supavisor connection string in Laravel Cloud) are compromised, an attacker gains complete, unfiltered read-write access to every row in every schema. The only exception is the three append-only audit tables where UPDATE/DELETE were explicitly revoked in the baseline, and auth_factor_events — these provide partial defense-in-depth. All other tables (core.users, site.sites, analytics.*, notifications.*) are fully exposed.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Long-term: revoke `BYPASSRLS`, then add explicit `TO app_backend` policies on each table where cross-tenant access is genuinely needed (background jobs, webhooks). Tables where app_backend only ever touches the requesting user's rows can use the same owner-scoped policies as `authenticated`.
        - Short-term: revoke `DELETE` from analytics append tables (`analytics.site_visits`, `analytics.link_clicks`, `analytics.section_views`, `analytics.lead_submissions`) and from `notifications.broadcast_email_receipts` — these are effectively append-only and app_backend has no delete code paths today.
        - Ensure `DB_PASSWORD` is rotated periodically and alert on unexpected direct-postgres-connection events in Supabase.
    - **Technical:** This is documented as an intentional architectural decision ("decision 16" in the baseline comment) because several tables have no explicit app_backend policy — revoking BYPASSRLS would silently default-deny those tables, breaking background jobs. The schema reorganization migration (`20260527010000`) correctly set `ALTER DEFAULT PRIVILEGES IN SCHEMA audit GRANT SELECT, INSERT ON TABLES TO app_backend`, which constrains future audit tables. Extending this posture incrementally (revoke delete from append-only tables, then tackle BYPASSRLS) is the pragmatic path. The moderation grant migration (`20260530000000`) gave app_backend full CRUD on moderation schema — decisions table at minimum should be append-only via `REVOKE UPDATE, DELETE`.
    - **Plain English:** The main server has a master key that opens every door in the building with no security cameras. If someone steals that key, there is no fallback — they can read, change, or delete anything. We knew this when we designed the system and accepted the risk to keep background jobs simple. A first step is to revoke the "delete" permission from tables where we never delete rows anyway, which costs nothing to implement and limits damage if the key is stolen.
    - **Evidence:**
        ```sql
        -- 20260526000000_baseline_standalone_user.sql (section 12):
        EXECUTE 'ALTER ROLE app_backend BYPASSRLS';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA core TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA site TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA notifications TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA analytics TO app_backend';
        -- Only three tables get explicit REVOKE after this broad grant:
        REVOKE UPDATE, DELETE ON core.staff_audit_log FROM app_backend;
        REVOKE UPDATE, DELETE ON core.handle_change_log FROM app_backend;
        REVOKE UPDATE, DELETE ON core.auth_factor_events FROM app_backend;
        ```

- [ ] **#RLS-2** · P2 — Any staff role (including `support`) can INSERT/UPDATE/DELETE on `core.users` and `site.customers` via direct Supabase API
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql — `users_all_authenticated` policy; `customers_all_authenticated` policy (now on `site.customers` after `20260527010000_reorganize_schemas.sql`)
    - **Affects:** A support staff Supabase JWT (role = `'support'`, not `'admin'`) used directly against the PostgREST API can UPDATE any user's profile fields, INSERT new customer records under any professional's account, or DELETE customer rows — operations the application layer reserves for admins. The `core.partna_staff_role_check` enforces `role IN ('admin', 'support')` and the `prevent_staff_escalation` trigger blocks role promotion, but there is no restriction in the RLS `WITH CHECK` clause to prevent support staff from modifying arbitrary `core.users` or `site.customers` rows.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Split `users_all_authenticated` into separate FOR SELECT / FOR INSERT/UPDATE/DELETE policies. Grant write operations only when `cs.role = 'admin'`:
            ```sql
            -- SELECT: any staff can read
            CREATE POLICY users_staff_select ON core.users FOR SELECT TO authenticated
                USING (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid()));
            -- INSERT/UPDATE/DELETE: admin-only
            CREATE POLICY users_admin_write ON core.users FOR ALL TO authenticated
                USING (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin'))
                WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin'));
            ```
        - Apply the same split to `customers_all_authenticated` on `site.customers`.
    - **Technical:** The `core.partna_staff` table has well-scoped policies that check `cs.role = 'admin'` for write operations (`partna_staff_insert_admin`, `partna_staff_delete_admin`). The `users_all_authenticated` and `customers_all_authenticated` policies on the other hand use a bare staff existence check with no role filter in the `WITH CHECK` expression. Since `app_backend` bypasses RLS, this doesn't affect the Laravel app — but a compromised or rogue support account with a direct Supabase JWT is not blocked at the database layer. The reorganization migration (`20260527010000`) moved customers from `core` to `site` schema; the policy moved with the table but the role-filter gap is unchanged.
    - **Plain English:** Think of support staff as having a read-only visitor badge to the filing room, with admins holding the master key. Right now the database's lock on `users` and `customers` checks that you're staff, but doesn't check whether you're a visitor or the keyholder — any staff badge opens the cabinet for editing. A rogue or compromised support account could edit any user's profile or customer records directly. We should upgrade the lock so editing requires the admin key.
    - **Evidence:**
        ```sql
        -- 20260526000000_baseline_standalone_user.sql:
        CREATE POLICY users_all_authenticated ON core.users TO authenticated
            USING ((auth_user_id = auth.uid())
                OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())))
            WITH CHECK ((auth_user_id = auth.uid())
                OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())));
        -- No cs.role filter — support and admin staff are both permitted to write
        ```

---

## P3 — Nice to have

- [ ] **#SCHEMA-2** · P3 — Two migrations share timestamp `20260530000000`; duplicate grant migration also present
    - **Where:** supabase/migrations/20260530000000_drop_workplace_hours.sql and supabase/migrations/20260530000000_grant_moderation_schema_to_app_backend.sql (timestamp collision); supabase/migrations/20260530000050_grant_moderation_schema_to_app_backend.sql (duplicate content)
    - **Affects:** The Supabase CLI extracts the version key from the numeric prefix of each filename. Two files with prefix `20260530000000` resolve to the same version — Supabase tracks which migrations have been applied by this key. On `db push` or `db reset`, one of the two `20260530000000_*` files may be silently skipped or the CLI may error. Separately, the 000050 file is a content-identical duplicate of the 000000 grant file; running both is harmless (DO block is idempotent) but adds noise to migration history.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Rename `20260530000000_grant_moderation_schema_to_app_backend.sql` to `20260530000010_grant_moderation_schema_to_app_backend.sql` (or any unused suffix) to resolve the timestamp collision with `drop_workplace_hours`.
        - Delete `20260530000050_grant_moderation_schema_to_app_backend.sql` — it is a duplicate of the grant migration and redundant once the collision is resolved.
        - If the dev database has already applied these under their current names, use `supabase migration repair` to reconcile the history.
    - **Technical:** Supabase's migration versioning model records each applied migration by its numeric timestamp prefix in the `supabase_migrations` schema. The alphabetical tie-break (`drop_workplace_hours` < `grant_moderation_schema`) means the grant file may be version-colliding with the drop file at the same key, depending on CLI version behavior. The 000050 duplicate is safe to delete because the DO block guarded by `IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend')` makes re-running a no-op, and Supabase migration history would simply have one fewer entry.
    - **Plain English:** Think of migration files like numbered steps in an instruction manual. Two steps accidentally got the same number (`20260530000000`), and one of them is also repeated a few steps later with a slightly different number. The manual could skip one step or get confused about what's been done. Renaming one of the duplicate-numbered steps and removing the repeated one makes the manual unambiguous.
    - **Evidence:**
        ```
        supabase/migrations/20260530000000_drop_workplace_hours.sql          ← timestamp collision
        supabase/migrations/20260530000000_grant_moderation_schema_to_app_backend.sql  ← same prefix
        supabase/migrations/20260530000050_grant_moderation_schema_to_app_backend.sql  ← duplicate content
        ```
        Both grant files contain identical statements:
        ```sql
        EXECUTE 'GRANT USAGE ON SCHEMA moderation TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA moderation TO app_backend';
        GRANT USAGE ON SCHEMA moderation TO service_role;
        ```

- [ ] **#RLS-4** · P3 — `moderation` schema tables have no Row-Level Security (defence-in-depth gap)
    - **Where:** supabase/migrations/20260528000000_create_moderation_schema.sql — all five CREATE TABLE statements; no subsequent migration adds RLS
    - **Affects:** No direct user exposure today: `moderation` is not listed in `api.schemas` in `config.toml`, and `anon`/`authenticated` roles have no USAGE grant on the schema. However, if `moderation` is ever added to the API schemas (or USAGE is granted) without also enabling RLS, all moderation case data — including reporter PII, case types, and decision rationale — would be immediately readable by any authenticated user.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ALTER TABLE moderation.<table> ENABLE ROW LEVEL SECURITY;` for all five tables: `cases`, `case_signals`, `evidence`, `decisions`, `action_log`.
        - Add staff-only SELECT policies and app_backend-only INSERT/UPDATE policies, mirroring the pattern on `audit.staff_audit_log`.
        - The `audit.moderation_events` table (created in the same migration) is in the `audit` schema, which already has correct DEFAULT PRIVILEGES for app_backend — no additional action needed there.
    - **Technical:** The `moderation` schema is correctly excluded from `api.schemas` and the grant migration (`20260530000000`) adds USAGE only to `app_backend` and `service_role`, not to `anon`/`authenticated`. The risk is purely forward-looking. The pattern on `audit.staff_audit_log` — split INSERT + SELECT policies for app_backend, SELECT-only for staff — is the right template for the append-friendly tables (`case_signals`, `evidence`, `action_log`). The mutable tables (`cases`, `decisions`) need full CRUD for app_backend.
    - **Plain English:** The moderation data (case reports, decisions, reporter information) lives in a room that regular users can't currently get into. But the room itself has no filing-cabinet locks — the door is the only protection. If anyone ever unlocks the door (by expanding the API schema), everything inside is immediately readable. Adding RLS is putting locks on each cabinet so that even someone who enters the room can only open what they're permitted to see.
    - **Evidence:**
        ```sql
        -- 20260528000000_create_moderation_schema.sql:
        CREATE TABLE IF NOT EXISTS moderation.cases (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            case_type VARCHAR(32) NOT NULL,
            reportable_type VARCHAR(64) NOT NULL,
            ...
        );
        -- No ALTER TABLE moderation.cases ENABLE ROW LEVEL SECURITY; follows
        -- Same omission for case_signals, evidence, decisions, action_log
        ```

`★ Insight ─────────────────────────────────────`
**What this audit teaches about incremental schema evolution:**
1. **RLS is not inherited** — when a table is created via a new migration (design_kits) in a schema whose older tables all have RLS, it doesn't automatically get RLS. Every new table needs an explicit `ENABLE ROW LEVEL SECURITY` in its creation migration.
2. **Table drops don't cascade to downstream migrations** — the csam_quarantine finding is a perfect example: the "remove tables" and "add index" migrations were authored in separate sessions. A simple naming or labeling convention (e.g., a `-- CSAM_PIPELINE` tag on both files) would have made the relationship visible and prevented the orphan.
3. **The audit and moderation schemas now diverge in posture** — audit correctly has DEFAULT PRIVILEGES locked to SELECT/INSERT; moderation has full CRUD for app_backend and no RLS at all. As trust-and-safety matures, closing this gap should be part of each T&S sprint, not a one-time catch-up.
`─────────────────────────────────────────────────`
