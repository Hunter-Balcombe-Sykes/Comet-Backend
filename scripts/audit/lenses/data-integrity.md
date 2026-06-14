# Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, JSONB drift, PII inventory, retention

Hunt **constraints that only exist in code** (not the DB schema), **soft-delete inconsistencies**, **orphan-row risk after cascades**, **JSONB fields without structure enforcement**, and **PII / retention exposure** at the schema and model layer. This lens is **foundational** — data-integrity bugs are silent until they compound, and the project status memory captures Josh's "speed ≠ cutting corners on systems that'll be built on" stance.

This lens is a **sibling** to `security.md` (auth-surface PII exposure — the **request** boundary) and `migration-safety.md` (lock-on-deploy, backfill ordering, online DDL hygiene). Where `security.md` looks at the request boundary, this lens looks at the **data-at-rest boundary**. Migration *safety* lives in `migration-safety.md` — overlapping findings should be emitted under whichever lens is more specific; the adjudicator dedupes.

Partna uses **PostgreSQL** (Supabase-hosted) with schemas: `public` (Laravel infra), `core` (users, staff, feature flags, `user_handle_aliases`, platform config), `site` (sites, blocks, services, design_kits, media, customers, enquiries, `site_subdomain_aliases`), `notifications`, `analytics`, `audit` (append-only; `app_backend` has SELECT/INSERT only), `moderation`. NO `brand`, `commerce`, `billing`, or `retail` schemas. Schema changes go in `supabase/migrations/` as raw SQL — never Laravel migrations. Soft deletes use a 30-day retention policy (`deleted_at` column, configurable via `SOFT_DELETE_RETENTION_DAYS`). UUID primary keys on all tables.

## Use the lens prefix `DINT` for findings

Number them `DINT-1`, `DINT-2`, … sequentially. **P1 for confirmed data corruption / loss risk OR confirmed PII leakage. P2 for constraint gaps that allow invalid state. P3 for hygiene (missing index on a FK, JSONB without validation, missing retention rule on non-PII).**

## Findings categories

### (1) Foreign-key constraints & constraint-in-code-only

- Tables with a `*_id` column that isn't backed by a `REFERENCES` clause — orphan-row risk on parent delete.
- Foreign-key relationships modelled in Eloquent (`belongsTo`, `hasMany`) but no `FOREIGN KEY` constraint in the DB — orphans accumulate silently.
- FK constraints without an explicit `ON DELETE` rule — Postgres defaults to `NO ACTION`; `CASCADE` / `SET NULL` / `RESTRICT` should be the deliberate choice, not the silent default. Canonical examples: `site.design_kits` uses `ON DELETE CASCADE` (1:1; removing a site cleans up its kit); `site.site_media` uses `ON DELETE CASCADE` to `site.sites`. Verify every FK that touches soft-deletable parents.
- FK columns missing an index where the column is used in a `WHERE` or `JOIN` — Postgres does not auto-index FK columns.
- Multi-column FKs without a matching composite index.
- Uniqueness enforced only via `->unique()` in a Form Request but no `UNIQUE` constraint in the migration — concurrent requests can create duplicates.
- NOT NULL enforced only in application code — null rows can arrive via direct DB writes, jobs, or migrations.

### (2) Soft-delete coherence

- Models that use the `SoftDeletes` trait without a corresponding `deleted_at` column in the migration.
- Models without `SoftDeletes` whose parent uses `SoftDeletes` — child rows become orphans on soft-delete.
- Queries that join soft-deletable tables without `whereNull('deleted_at')` — deleted records appear in results.
- Unique constraints that don't account for soft deletes — a "deleted" record blocks re-creation of the same handle/email.
- Cascade deletes (DB-level `ON DELETE CASCADE`) that skip the `deleted_at` column — records disappear hard without the 30-day retention window.
- Restored records (`restore()`) that don't re-trigger side-effects (cache invalidation, observer events) — stale state after un-delete.
- Soft-delete retention: the `partna:purge-soft-deletes` command is scheduled in `routes/console.php` — confirm it covers every model that uses `SoftDeletes`. New soft-deletable models must be added to the purge command's model list or a separate purge job.

### (3) Orphan-row risk

- Polymorphic associations (`*_type` + `*_id`) without a CHECK constraint or application-layer guarantee that the type+id combo points to a real row.
- Application-layer "soft FKs" (a UUID column that conceptually references another table, but Postgres doesn't know).
- Background jobs that delete a parent without considering child rows — flag any `Model::query()->delete()` on a parent table.

**Specific surfaces to verify:**
- **`site.design_kits`** — 1:1 with `site.sites` (PK = `site_id`, `ON DELETE CASCADE`). The `trg_create_empty_design_kit` trigger auto-inserts an empty row on site creation. Verify: (a) the trigger fires on INSERT to `site.sites` and nowhere else; (b) no code path creates a site without triggering the kit row; (c) the CASCADE actually removes the kit row when the site is deleted.
- **Handle / subdomain alias lifecycle** — `core.user_handle_aliases` and `site.site_subdomain_aliases` rows carry `reclaim_until` (+14d) and `expires_at` (+90d). The `handles:prune-expired-aliases` command (`app/Console/Commands/PruneExpiredHandleAliases.php`) hard-deletes rows where `expires_at IS NOT NULL AND expires_at <= now()`. Expiry coherence risk: Cloudflare KV alias entries carry an `expirationTtl` set by `SyncSubdomainToKvJob`; if the KV TTL and the DB `expires_at` drift (clock skew, failed KV write), a 301 redirect can persist after the DB row is pruned. Verify the TTL calculation uses the same source of truth as `expires_at`.
- **`site.site_media`** — cascades `ON DELETE CASCADE` from `site.sites`. Risk: a media record orphaned by a soft-delete (not a hard-delete) of the owning site or by direct `site_media` deletion without cleaning up storage artifacts. `DeleteMediaArtifactsJob` (`app/Jobs/`) handles storage cleanup — verify it fires from the `SiteMedia` observer on all delete paths, including soft-delete.
- **Supabase Auth user deleted but `core.users` row not removed** (or vice versa) — broken login state. Verify `AccountDeletionService` (`app/Services/User/AccountDeletionService.php`) coordinates both sides; the `audit.professional_deletion_audit` (now `audit` schema) should receive a row for every deletion.

### (4) Enum / CHECK constraint coverage

- Status / type columns backed by `TEXT` without a `CHECK` constraint enumerating allowed values. The canonical pattern: `site.sites.skeleton_id CHECK (skeleton_id IN ('skeleton-1','skeleton-2','skeleton-3','skeleton-4'))` from `20260527070000_skeleton_system_cleanup.sql`.
- Application-side enums (`app/Enums/*`) without a matching DB CHECK — schema accepts values the app rejects.
- Postgres ENUM types added without a corresponding application-side enum (drift risk — DB allows values the app can't handle).
- Boolean-like columns stored as integer / varchar — flag any `is_*` or `has_*` column not typed as `BOOLEAN`.
- `site.site_media.pool` CHECK (`'gallery', 'content', 'design', 'product', 'brand_gallery', 'documents'`) — confirm application code never writes a pool value outside this set. Note `'brand_gallery'` was a pre-standalone value; if no app code writes it, it's dead vocabulary in the constraint and may cause confusion.

### (5) Timestamp & timezone hygiene

- `TIMESTAMP` columns where `TIMESTAMPTZ` is needed — `TIMESTAMP` is timezone-naive; audit / PII / event rows must be tz-aware.
- `updated_at` columns that aren't auto-updated by a trigger or `BaseModel` — silent drift between row state and timestamp.
- `created_at` populated by application code instead of `DEFAULT now()` — race between insert time and clock skew across instances.
- Date-only columns (`DATE`) used for timestamps that should be an instant.

### (6) JSONB schema drift

- JSONB columns with documented shapes that don't match what the app code writes.
- JSONB columns queried with `->>` / `@>` without a GIN index.
- JSONB columns used as a substitute for a relation (one-to-many embedded as array) where a child table would scale better.
- **`site.sites.settings` post-skeleton-cleanup:** the `design` key was stripped in `20260527070000_skeleton_system_cleanup.sql`. Any code path that writes `settings.design.*` back is a P1 finding (architectural regression). Check `app/Services/Site/`, `app/Http/Controllers/Api/User/SiteManagement/`, and any observer touching `site.settings` for forbidden key writes.
- Nullable JSONB fields that the application treats as always-present — `->get('key')` on null crashes at read time.
- JSONB fields that should be promoted to columns (queried often, joined to, or indexed) — flag any `WHERE settings->>'foo' = ...` on a hot path.

### (7) Race conditions & double-write gaps

- Upserts (`updateOrCreate`, `firstOrCreate`) without a DB-level unique constraint — concurrent calls create duplicates before the SELECT sees the existing row.
- Counter/balance columns updated with `increment()`/`decrement()` inside a transaction where the surrounding transaction is too broad — partial update on rollback leaves stale state.
- `site.design_kits` UPSERT — `20260602010000_design_kit_trigger_on_conflict.sql` updated the `trg_create_empty_design_kit` trigger to use `ON CONFLICT DO NOTHING`. Verify no application path bypasses the trigger and directly INSERTs a duplicate kit row.
- Analytics session UPSERT (`analytics.site_sessions`) — `analytics.site_sessions` rows are UPSERTed by session ID. Confirm the UPSERT key is unique-constrained and the `ON CONFLICT` target is correct in the application job.

### (8) Composite-uniqueness coverage

- "Idempotency key" columns without a UNIQUE constraint backing them — the application's idempotency check is best-effort, not enforced.
- Natural keys like `(user_id, handle)` / `(user_id, kind)` / `(site_id, event_id)` without a UNIQUE constraint — duplicate rows on race.
- UNIQUE constraints on a single column where a composite would be correct (e.g. `UNIQUE(site_id)` instead of `UNIQUE(site_id, deleted_at)` on a soft-deletable table that allows re-creation).

### (9) PII inventory & retention

**GDPR framing (current):** Shopify GDPR webhooks are removed. GDPR work is now first-party — account deletion (`AccountDeletionService`, `app/Services/User/AccountDeletionService.php`) and data export (`ExportUserDataJob`, `app/Jobs/Gdpr/ExportUserDataJob.php`; `DataExportService`, `DataExportPayloadBuilder`, `DataExportZipWriter` in `app/Services/User/DataExport/`).

- PII columns (email, phone, display name, address, DOB, IP address, financial identifier) without a wiring into the data export payload builder AND the account deletion service.
- PII written to JSONB blobs without retention controls — invisible from a normal column audit. Check `site.sites.settings`, `core.users.*` JSONB fields.
- **New PII columns** added since the standalone strip (2026-05-22) that aren't yet wired into `DataExportPayloadBuilder` or `AccountDeletionService` — flag each one as P1 unless its absence is justified.
- `core.data_export_audit`, `core.professional_deletion_audit` (now in `audit` schema after `20260527010000_reorganize_schemas.sql`) — confirm every deletion and export operation writes a row to the appropriate audit table.
- Enquiries / customers PII (`site.enquiries`, `core.users` customer-related columns) — verify the export and redact paths cover enquiry contact fields.
- Logging code paths that emit PII into Nightwatch / log aggregator — also covered by `security.md` category 10; emit under whichever lens is more specific.
- Analytics event tables (`analytics.link_clicks`, `analytics.site_visits`, `analytics.site_sessions`) — confirm IP address / user-agent handling does not persist raw PII beyond the retention window. The `partna:analytics:purge-raw-events` command is scheduled in `routes/console.php`; verify its retention window matches the privacy policy.

### (10) Backup / restore correctness boundaries

- Tables whose correct restore depends on FK ordering or trigger replay — flag any table where a partial restore would leave an inconsistent state.
- Trigger-maintained relationships (e.g. `trg_create_empty_design_kit` auto-inserts `site.design_kits` rows on site creation) — confirm a full rebuild path exists: if `site.design_kits` is restored from a backup, are all rows present? If the trigger didn't fire during a bulk import, orphaned sites with no design kit are possible.
- Append-only tables (`audit.handle_change_log`, `audit.staff_audit_log`, `audit.professional_deletion_audit`, `audit.data_export_audit`, `audit.auth_factor_events`) — confirm there is no UPDATE / DELETE path in app code; restore must produce the same hashable state. The DB-layer enforcement is a trigger on `audit.handle_change_log` (`core.trg_handle_change_log_append_only`) and `audit.staff_audit_log` (`core.reject_staff_audit_log_mutation`); `app_backend` has only SELECT/INSERT on all `audit.*` tables (enforced in `20260527010000_reorganize_schemas.sql`). Verify this invariant holds for every table moved into the `audit` schema.

## Per-finding requirements

For every finding:
- Cite the category number (1–10).
- Quote the Eloquent model relationship OR the migration SQL that proves the gap.
- Name the canonical fix: `ADD FOREIGN KEY ... REFERENCES ...`, `ADD CHECK ... IN (...)`, `CREATE INDEX CONCURRENTLY ...`, `ALTER COLUMN ... TYPE TIMESTAMPTZ`, `UNIQUE(col1, col2)`, `wire PII column into DataExportPayloadBuilder + AccountDeletionService`, `promote JSONB key to column`, `FOR EACH ROW EXECUTE` trigger covering all DML, etc.
- For soft-delete gaps: name the specific query or scope missing the `whereNull('deleted_at')` guard.

## Out of scope — do NOT re-flag

- Removed schemas (`commerce.orders`, `order_events`, `order_items`, `brand_affiliate_rollup`, `commission_movements`, `brand_status_history`, `cart_events`, `core.professionals`) — these don't exist; don't flag their absence.
- Booking / Fresha / Square schema (dropped entirely).
- Shopify GDPR webhook paths (`/Api/Webhooks/Shopify*`) — removed; the GDPR work is now first-party.
- Findings about adding columns for product features that don't exist yet.
- Migration *safety* (lock-on-deploy, backfill ordering) — that's `migration-safety.md` (MIG prefix).
- `app_backend` role NOLOGIN (intentional fail-closed).
- The legacy `'professional'` request-attribute key (deliberate rename deferral — not a data integrity issue).
- Larastan/PHPStan-covered symbol-existence issues.

## Suggested per-domain scope groups

### Group A — Migrations (the source of truth)
```
--scope supabase/migrations
```

### Group B — Models + factories
```
--scope app/Models
--scope database/factories
```

### Group C — GDPR / retention paths
```
--scope app/Jobs/Gdpr
--scope app/Services/User
```

### Group D — Enums (DB / app drift)
```
--scope app/Enums
--scope supabase/migrations
```

### Group E — Observers and triggers
```
--scope app/Observers
--scope supabase/migrations
```

## Exhaustiveness directive

Walk every migration and every model in scope. Every `belongsTo` is a candidate for a missing FK finding. Every `updateOrCreate` / `firstOrCreate` is a candidate for a missing unique constraint. Every soft-deletable model's eager-loaded relationships must be checked for the `whereNull` guard. Every new PII column since 2026-05-22 must be traced to its export and deletion wiring. **The data layer is where silent corruption lives; under-reporting compounds.**
