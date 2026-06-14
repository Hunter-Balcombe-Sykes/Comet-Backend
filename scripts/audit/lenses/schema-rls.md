# Schema / RLS / search_path: database-side correctness, constraint coverage, migration safety

Find database-side correctness gaps: missing constraints, RLS gaps, schema-qualification mistakes, migration patterns that break under load, index hygiene problems, trigger correctness, function-definition pitfalls. Partna's schema design is documented in `CLAUDE.md` (multi-schema with `search_path`) and `AI_CONTEXT.md`.

This lens is a **sibling** to `migration-safety.md` (lock-on-deploy, backfill ordering, online DDL hygiene) and `data-integrity.md` (FK hygiene, soft-delete coherence, orphan-row risk, PII/retention). Schema-side constraint gaps go here; application-side race-safety goes in data-integrity; migration operational safety goes in migration-safety. The adjudicator dedupes overlaps.

## Use the lens prefix `SCHEMA` for findings

Number them `SCHEMA-1`, `SCHEMA-2`, … sequentially across the whole audit.

## Findings categories

### (1) Row-level security (RLS)

Partna's tenant data lives in `site.*`, `core.*`, `analytics.*`, and `notifications.*`. The `audit` schema is append-only (special rules below). The `moderation` schema holds staff-only data.

- Tables in `site.*`, `core.*`, `analytics.*`, `notifications.*` containing tenant data without RLS enabled — the `app_backend` role carries `BYPASSRLS` for the application path, but RLS is defence-in-depth against PostgREST / Supabase client leakage.
- RLS policies that use `current_user` / `session_user` instead of an app-set claim (e.g. `current_setting('app.actor_id')`) — RLS bypassed when the app connects as a shared role like `app_backend`.
- RLS policies that allow read where the row doesn't constrain by tenant — the policy exists but is permissive.
- Tables with RLS enabled but no `FORCE ROW LEVEL SECURITY` set — the owner role bypasses policies silently.
- Tables intended to be public (e.g. lookup / platform-config tables) where RLS is enabled but no policy exists — legitimate reads silently fail.

**House exemplars — use these as the pattern to extend:**
- `tests/Feature/Security/DesignKitsRlsTest.php` — asserts `site.design_kits` has RLS enabled, forced, and the correct owner/staff/anon policy set (added in `20260602000000_design_kits_rls.sql`).
- `tests/Feature/Security/FunctionSearchPathTest.php` — introspects `pg_proc.proconfig` to assert every trigger/helper function has a pinned `search_path`.
- `tests/Feature/Security/ModerationSchemaRlsTest.php` — asserts all five `moderation.*` tables have RLS enabled + forced + a staff-only SELECT policy + an `app_backend` FOR ALL policy.

New tenant-data or staff-data tables must replicate this pattern: RLS + FORCE + per-role policies + a regression-guard test in `tests/Feature/Security/`.

### (2) `search_path` / multi-schema correctness

Partna's schemas: `public` (Laravel infra), `core` (users, staff, feature flags, `user_handle_aliases`, platform config), `site` (sites, blocks, services, design_kits, media, customers, enquiries, `site_subdomain_aliases`), `notifications`, `analytics`, `audit` (append-only), `moderation`. NO `brand`, `commerce`, `billing`, or `retail` schemas.

- Raw SQL queries (`DB::statement`, `whereRaw`, migration SQL) referencing a table without schema qualification when the same name could collide across schemas.
- Functions / triggers defined in one schema referencing tables in another without qualification — silent breakage if `search_path` changes.
- Migrations that change `search_path` for the session without restoring it — leaks into subsequent migration sessions.
- Models without an explicit `$table` property where the model is in a schema that isn't first on `search_path` — Laravel resolves to the wrong table.
- `SECURITY DEFINER` functions without a pinned `SET search_path` — the canonical fix applied in `20260606040000_pin_function_search_paths.sql` sets `search_path = ''` on all 12 trigger/helper functions; any new function must follow this pattern.

**Current pinned functions (as of `20260606040000_pin_function_search_paths.sql`):** `public.set_updated_at`, `core.set_updated_at`, `core.set_media_variants_updated_at`, `core.set_user_confirmation_preferences_updated_at`, `core.reject_staff_audit_log_mutation`, `core.trg_handle_change_log_append_only`, `core.trg_user_handle_alias_check`, `core.trg_user_handle_change`, `site.compute_user_url`, `site.trg_recompute_partna_url`, `site.create_empty_design_kit`, `site.trg_sites_url_sync`. Any function added after this migration that lacks a `SET search_path` pinning is a finding.

### (3) Constraint coverage

- Status / enum columns backed by `VARCHAR` / `TEXT` without a `CHECK` constraint — the canonical pattern is the `site.sites.skeleton_id CHECK (skeleton_id IN ('skeleton-1','skeleton-2','skeleton-3','skeleton-4'))` constraint added in `20260527070000_skeleton_system_cleanup.sql` and validated in `20260603000002_validate_skeleton_id_check.sql`.
- Idempotency-key columns without a `UNIQUE` constraint backing them — INSERT retry on event re-delivery produces duplicates.
- Columns the app code treats as `NOT NULL` (no null-handling on the read path) but the schema allows null — runtime crash class.
- Foreign keys without an explicit `ON DELETE` / `ON UPDATE` behavior — defaults to `NO ACTION`; `CASCADE` / `SET NULL` / `RESTRICT` should be the deliberate choice. Canonical example: `site.design_kits` uses `ON DELETE CASCADE` (1:1 with `site.sites`; removing a site cleans up its kit).
- Composite `UNIQUE` constraints missing where the app's read pattern implies "one row per (tenant, key)".
- Check constraints added without `NOT VALID` + later `VALIDATE` split on large tables — see `docs/migration-guidelines.md` for the canonical two-step pattern.

### (4) Index hygiene

- Hot-path queries (public sitepage resolution, analytics ingest, cache-miss rebuilds) without a composite index for the `WHERE` + `ORDER BY` + `LIMIT` shape. Hottest tables: `site.sites`, `site.blocks`, `site.site_media`, `core.users`, `analytics.link_clicks`, `analytics.site_visits`, `analytics.site_sessions`, `notifications.*` tables.
- Partial indexes missing where a status filter dominates (e.g. `WHERE is_active = true`, `WHERE processing_state = 'pending'`).
- GIN indexes missing on JSONB columns that are queried with `->>` or `@>` operators.
- Duplicate indexes — index bloat under high-write volume on analytics ingest tables.
- Indexes created without `CONCURRENTLY` against tables that are hot at the scale target — `CREATE INDEX` (without `CONCURRENTLY`) takes `ACCESS EXCLUSIVE`. The companion migration pattern (split index creation into a separate file, run `CONCURRENTLY`) is established in `20260610000001_analytics_v2_click_indexes.sql`.
- Indexes on columns that are never queried — write amplification with no read benefit.

### (5) Trigger correctness

- Triggers on `site.sites` that fire on every row of a multi-row UPDATE without statement-level batching where it would suffice — the `trg_create_empty_design_kit` trigger (auto-inserts an empty `site.design_kits` row on site creation) and URL-sync triggers are per-row `AFTER INSERT` triggers; flag any new trigger that performs unbounded work.
- Triggers that call functions in a different schema without schema-qualified function names.
- Triggers marked `BEFORE` that should be `AFTER` (or vice versa) — affects whether `NEW.id` is available, whether constraints are checked, etc.
- Triggers that disable themselves inside a migration (`ALTER TABLE DISABLE TRIGGER`) without a guaranteed re-enable before commit — writes during the window bypass invariants.
- `CREATE OR REPLACE FUNCTION` changing a trigger's behaviour without a backfill for rows that should have been processed under the new logic.
- New trigger added with `FOR EACH STATEMENT` where `FOR EACH ROW` is intended — silent semantic change.
- Append-only enforcement triggers (e.g. `core.reject_staff_audit_log_mutation`, `core.trg_handle_change_log_append_only`) — confirm every append-only table in the `audit` schema has a trigger or privilege revocation enforcing the invariant at the DB layer, not only at the app layer.

### (6) Migration safety under load

For detail on lock classes and the canonical safe patterns, see `docs/migration-guidelines.md`. Summary of what to flag here:

- `ALTER TABLE ADD COLUMN ... NOT NULL` without a constant `DEFAULT` (Postgres 11+ avoids full-table rewrite only for constant/immutable defaults).
- `CREATE INDEX` without `CONCURRENTLY` on tables that will be hot at the scale target.
- New `CHECK` constraints without `NOT VALID` + later `VALIDATE` — full-table scan blocks writes.
- Inline data backfills (`UPDATE` over the whole table) inside a migration — holds locks for the full scan. The canonical pattern: extract to a post-deploy artisan command or chunked background job (documented in `docs/migration-guidelines.md`). Current exemplar for an idempotent data backfill: `20260608000000_backfill_subdomain_alias_lifecycle.sql` — pure SQL UPDATE with a `WHERE` guard making it idempotent.
- Missing `SET LOCAL lock_timeout` / `statement_timeout` on DDL against live-traffic tables.
- `DROP COLUMN` without a rename-to-`_deprecated` deploy cycle.

### (7) Soft delete / retention pattern

- Tables with a `deleted_at` column but no `SoftDeletes` trait on the model.
- Models with `SoftDeletes` but the underlying table missing the `deleted_at` column.
- 30-day retention (`SOFT_DELETE_RETENTION_DAYS`) not wired for new soft-deletable models — the `partna:purge-soft-deletes` command (scheduled in `routes/console.php`) must cover new models.
- Foreign keys to soft-deletable parents without an explicit retention policy (cascade-soft-delete, null-out, or block).

### (8) UUID + primary key consistency

- Tables with `BIGSERIAL` / `BIGINT` primary keys where the convention is UUID.
- Tables with UUID PKs but no DB-side default (`gen_random_uuid()`) — relies on app-side generation, breaks raw INSERT and reconcile jobs.
- Tables with composite PKs where a single UUID would suffice — `site.design_kits` is the canonical exception (PK = `site_id` UUID, 1:1 with `site.sites`).

### (9) Function definitions

- `SECURITY DEFINER` functions that don't `SET search_path` explicitly — privilege escalation via mutable `search_path` is the canonical Postgres CVE shape. The fix is `SET search_path = ''` (empty string forces schema-qualified builtins), as applied in `20260606040000_pin_function_search_paths.sql`.
- `SECURITY DEFINER` functions owned by a role with more privilege than the function needs.
- Functions labeled `VOLATILE` that are actually `STABLE` / `IMMUTABLE` — query planner can't cache results.
- Functions called from triggers that aren't `IMMUTABLE` — re-evaluated on every row.

### (10) JSONB design

- JSONB columns with documented shapes (in PHPDoc or migration comment) that don't match what the app code writes.
- JSONB columns queried with `->>` / `@>` without a GIN index.
- JSONB columns used as a substitute for a relation (one-to-many embedded as array) where a child table would scale better.
- Lack of versioning on JSONB shapes that have changed — old rows in the old shape with no migration path.
- `site.sites.settings` post-skeleton-cleanup: the `design` key was stripped via `20260527070000_skeleton_system_cleanup.sql`. Any code path that writes `settings.design.*` back into the column is a finding. Flag JSONB shapes that could reintroduce stripped keys.

### (11) Append-only vs mutable

(Focus here is on schema-side discipline.)

- Tables intended as audit logs (`audit.handle_change_log`, `audit.staff_audit_log`, `audit.professional_deletion_audit`, `audit.data_export_audit`, `audit.auth_factor_events`) with UPDATE paths that aren't blocked by a trigger or privilege revocation. The `audit` schema's `app_backend` role has SELECT/INSERT only (enforced in `20260527010000_reorganize_schemas.sql` and the baseline). An UPDATE or DELETE grant on any `audit.*` table for `app_backend` is a P0 finding.
- Analytics event tables (`analytics.link_clicks`, `analytics.site_visits`, `analytics.site_sessions`) — these are write-heavy ingest tables; confirm `app_backend` has appropriate grants (INSERT/UPDATE for `site_sessions` UPSERT; INSERT-only where appropriate) and that no app path issues unguarded DELETEs except the scheduled `partna:analytics:purge-raw-events` command.
- Tables intended as projections that are append-only — should support UPDATE.
- Append-only tables without a dedup key — event re-delivery on crash can duplicate rows.

## Per-finding requirements

For every finding:
- Cite the category number (1–11).
- Name the canonical replacement pattern: `RLS policy with FORCE ROW LEVEL SECURITY`, `CHECK constraint (NOT VALID + VALIDATE split)`, `UNIQUE constraint`, `CREATE INDEX CONCURRENTLY`, `NOT VALID + VALIDATE`, `SECURITY DEFINER with SET search_path = ''`, `GIN on JSONB`, `gen_random_uuid() default`, etc.
- Quote verbatim SQL evidence from the migration files.
- Reference the house exemplar pattern where applicable: `site.design_kits` (1:1 CASCADE + auto-create trigger), `site.sites.skeleton_id` (CHECK enum), `20260606040000_pin_function_search_paths.sql` (search_path pinning), `20260608000000_backfill_subdomain_alias_lifecycle.sql` (idempotent data backfill).

## Out of scope — do NOT re-flag

- `app_backend` role NOLOGIN (fail-closed by design — see CLAUDE.md and ground truth).
- The legacy `'professional'` request-attribute key and `current.pro` alias (deliberate rename deferral).
- Removed schemas: `brand`, `commerce`, `billing`, `retail` — these don't exist; don't flag their absence.
- Larastan/PHPStan-covered symbol-existence issues.
- Pint style findings.

## Suggested per-domain scope groups

### Group A — schema migrations (broadest)
```
--scope supabase/migrations
```

### Group B — models + their tables (alignment between code and schema)
```
--scope app/Models
--scope supabase/migrations
```

### Group C — RLS-sensitive tenant tables
```
--scope supabase/migrations
--scope app/Models/Core
--scope app/Models/Analytics
```

### Group D — triggers + functions
```
--scope supabase/migrations
```
(filter manually for files containing `CREATE TRIGGER` or `CREATE FUNCTION` or `CREATE OR REPLACE FUNCTION`)

## Exhaustiveness directive

Walk every migration file and every model in scope. Emit a finding for every distinct quotable instance. If three migrations each create an index without `CONCURRENTLY`, that is three findings (`SCHEMA-1`, `SCHEMA-2`, `SCHEMA-3`). The adjudicator dedupes. **Under-reporting is the failure mode to avoid.**
