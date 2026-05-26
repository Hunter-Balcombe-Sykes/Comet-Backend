# Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, JSONB drift, PII inventory, retention

Hunt **constraints that only exist in code** (not the DB schema), **soft-delete inconsistencies**, **orphan-row risk after cascades**, **JSONB fields without structure enforcement**, and **PII / retention exposure** at the schema and model layer. This lens is **foundational** — data-integrity bugs are silent until they compound, and the project status memory captures Josh's "speed ≠ cutting corners on systems that'll be built on" stance.

This lens is a **sibling** to `security.md` (auth-surface PII exposure — the **request** boundary) and `lifecycle-correctness.md` (idempotency + race-safety). Where `security.md` looks at the request boundary, this lens looks at the **data-at-rest boundary**. Migration *safety* (lock-on-deploy, backfill ordering, online DDL hygiene) lives in `migration-safety.md` — overlapping findings should be emitted under whichever lens is more specific; the adjudicator dedupes.

Partna uses **PostgreSQL** (Supabase-hosted) with multiple schemas (`public`, `core`, `site`, `brand`, `commerce`, `notifications`, `analytics`, `billing`). Schema changes go in `supabase/migrations/` as raw SQL — never Laravel migrations. Soft deletes use a 30-day retention policy (`deleted_at` column, configurable via `SOFT_DELETE_RETENTION_DAYS`). UUID primary keys on all tables.

## Use the lens prefix `DINT` for findings

Number them `DINT-1`, `DINT-2`, … sequentially. **P1 for confirmed data corruption / loss risk OR confirmed PII leakage. P2 for constraint gaps that allow invalid state. P3 for hygiene (missing index on a FK, JSONB without validation, missing retention rule on non-PII).**

## Findings categories

### (1) Foreign-key constraints & constraint-in-code-only

- Tables with a `*_id` column that isn't backed by a `REFERENCES` clause — orphan-row risk on parent delete.
- Foreign-key relationships modelled in Eloquent (`belongsTo`, `hasMany`) but no `FOREIGN KEY` constraint in the DB — orphans accumulate silently.
- FK constraints without an explicit `ON DELETE` rule — Postgres defaults to `NO ACTION` which is correct in most cases, but `CASCADE` / `SET NULL` / `RESTRICT` should be the deliberate choice, not the silent default.
- `ON DELETE CASCADE` on tables that should preserve audit history (e.g. `commission_movements`, `order_events`, `brand_status_history`) — financial / audit rows must not vanish when a parent is deleted.
- FK columns missing an index where the column is used in a `WHERE` or `JOIN` — Postgres does not auto-index FK columns.
- Multi-column FKs that don't have a matching composite index.
- Uniqueness enforced only via `->unique()` in a Form Request but no `UNIQUE` constraint in the migration — concurrent requests can create duplicates.
- NOT NULL enforced only in application code — null rows can arrive via direct DB writes, jobs, or migrations.
- Decimal precision: monetary amounts without `NUMERIC(12,2)` or equivalent — floating-point rounding errors in finance calculations. (Cross-check vs the `cents-as-INTEGER` convention — flag drift, not the chosen convention.)

### (2) Soft-delete coherence

- Models that use the `SoftDeletes` trait without a corresponding `deleted_at` column in the migration — silent failure.
- Models without `SoftDeletes` whose parent uses `SoftDeletes` — child rows become orphans on soft-delete.
- Financial models with `SoftDeletes` (the `29b7eb1` test asserts none do — flag any survivor).
- Queries that join soft-deletable tables without `whereNull('deleted_at')` — deleted records appear in results.
- Unique constraints that don't account for soft deletes — a "deleted" record blocks re-creation of the same slug/email/handle.
- Cascade deletes (DB-level `ON DELETE CASCADE`) that skip the `deleted_at` column — records disappear hard without the 30-day retention window.
- Restored records (`restore()`) that don't re-trigger side-effects (cache invalidation, observer events) — stale state after un-delete.
- Aggregations or rollups that don't filter soft-deleted rows — commission totals, payout amounts inflated by deleted entities.
- Forced-delete paths that bypass FK cascade rules — orphan creation.
- Soft-delete retention: the codebase has a 30-day retention default per CLAUDE.md — confirm a scheduled job actually purges trashed rows past retention, with audit logging.

### (3) Orphan-row risk

- Polymorphic associations (`*_type` + `*_id`) without a CHECK constraint or application-layer guarantee that the type+id combo points to a real row.
- Application-layer "soft FKs" (a UUID column that conceptually references another table, but Postgres doesn't know).
- Background jobs that delete a parent without considering child rows — flag any `Model::query()->delete()` on a parent table.
- Pivot/junction tables (`brand_affiliates`, store memberships) not cleaned when either side of the relationship is removed.
- `commerce.order_items` or `brand_affiliate_rollup` rows surviving after their parent `order` is deleted — rollup figures become wrong.
- Supabase Auth user deleted but `core.professionals` row not removed (or vice versa) — broken login state.
- Media records (`site_media`) orphaned when their owning entity is deleted — storage leak + broken URLs.
- Cleanup jobs missing for tables that should be GCed (orphaned `site_media` after design changes, orphaned `cart_events` after a cart expires, orphaned `commission_movements` for cancelled payouts).

### (4) Enum / CHECK constraint coverage

- Status / type columns backed by VARCHAR / TEXT without a CHECK constraint enumerating allowed values (the `64db1f2` pattern for `orders.rate_source`).
- Postgres ENUM types added without a corresponding application-side enum (drift risk — DB allows values the app can't handle).
- Application-side enums (`app/Enums/*`) without a matching DB CHECK — schema accepts garbage that the app rejects.
- Boolean-like columns stored as integer / varchar — flag any `is_*` or `has_*` column not typed as `BOOLEAN`.

### (5) Timestamp & timezone hygiene

- `TIMESTAMP` columns where `TIMESTAMPTZ` is needed (Postgres `TIMESTAMP` is timezone-naive — financial / audit rows must be tz-aware).
- `updated_at` columns that aren't auto-updated by a trigger or `BaseModel` — silent drift between row state and timestamp.
- `created_at` populated by application code instead of `DEFAULT now()` — race between insert time and clock skew across instances.
- Date-only columns (`DATE`) used for timestamps that should be instant.

### (6) JSONB schema drift

- JSONB columns used as a source of truth without an application-side schema or validator — silent drift between writes and reads. A typo in a key name silently creates a parallel key that is never read.
- Missing `CHECK` constraint or trigger to validate required top-level keys in JSONB config fields.
- JSONB queries (`->jsonContains`, `->where('settings->foo', ...)`) without a GIN index — at-scale linear scan.
- JSONB columns growing unbounded (e.g. an append-only log inside a JSONB array, unbounded `line_items`) — row bloat + TOAST churn.
- JSONB fields that should be promoted to columns (queried often, joined to, or indexed) — flag any `WHERE settings->>'foo' = ...` that hits a hot path.
- Nullable JSONB fields that the application treats as always-present — `->get('key')` on null crashes at read time.

### (7) Race conditions & double-write gaps

- Upserts (`updateOrCreate`, `firstOrCreate`) without a DB-level unique constraint — concurrent calls create duplicates before the SELECT sees the existing row.
- Counter/balance columns updated with `increment()`/`decrement()` inside a transaction but the surrounding transaction is too broad — partial update on rollback leaves stale balances.
- `commerce.brand_affiliate_rollup` trigger-maintenance: confirm the trigger is `FOR EACH ROW` and handles `DELETE` + `UPDATE` + `INSERT` — missing the `DELETE` case means rollup doesn't decrease when an order is cancelled.
- Ledger / movement rows inserted outside a transaction with the parent record — the parent exists but the money-movement is missing on crash.

### (8) Composite-uniqueness coverage

- "Idempotency key" columns without a UNIQUE constraint backing them — the application's idempotency check is best-effort, not enforced.
- `(brand_id, code)` / `(professional_id, kind)` / `(shop_id, event_id)` natural keys without a UNIQUE constraint — duplicate rows on race.
- `UNIQUE` constraints on a single column where a composite would be correct (e.g. `UNIQUE(shop_id)` instead of `UNIQUE(shop_id, deleted_at)` on a table that allows re-installs).

### (9) PII inventory & retention

- Columns storing email / phone / address / DOB / financial identifier without a row in the GDPR PII inventory (memory: `project_shopify_gdpr_webhooks_todo.md` — GDPR webhooks are complete and the PII inventory is preserved; reconcile findings against it).
- PII columns lacking a retention rule — long-tail accumulation.
- PII written to JSONB blobs without retention controls — invisible from a normal column audit.
- `customer_data_request` / `customer_redact` / `shop_redact` paths in `app/Http/Controllers/Api/Webhooks/` and `app/Jobs/Gdpr/`: confirm every PII column is touched by the redact path. Flag any NEW PII columns added since 2026-04-21 that aren't yet wired into the redact jobs.
- Logging code paths that emit PII into Nightwatch / log aggregator (also covered by `security.md` category 10 — emit under whichever lens is more specific).

### (10) Backup / restore correctness boundaries

- Tables whose correct restore depends on FK ordering or trigger replay — flag any table where a partial restore would leave an inconsistent state.
- Trigger-maintained projections (`brand_affiliate_rollup` — the canonical example) — confirm a full rebuild path exists for disaster recovery.
- Append-only tables (`order_events`, `commission_movements`, `brand_status_history`) — confirm there is no UPDATE / DELETE path in code; restore must produce the same hashable state.

## Per-finding requirements

For every finding:
- Cite the category number (1–10).
- Quote the Eloquent model relationship OR the migration SQL that proves the gap.
- Name the canonical fix: `ADD FOREIGN KEY ... REFERENCES ...`, `ADD CHECK ... IN (...)`, `CREATE INDEX CONCURRENTLY ...`, `ALTER COLUMN ... TYPE TIMESTAMPTZ`, `UNIQUE(col1, col2)`, `wire PII column into customer_redact job`, `promote JSONB key to column`, `FOR EACH ROW EXECUTE` trigger covering all DML, etc.
- For soft-delete gaps: name the specific query or scope missing the `whereNull('deleted_at')` guard.

## Out of scope — do NOT re-flag

- The Stripe payout lifecycle audit's findings (closed).
- Commerce schema (`orders`, `order_events`, `order_items`, `brand_affiliate_rollup`, `commission_movements`) — shipped + audited.
- Booking / Fresha / Square schema (dropped).
- Findings about adding columns for product features that don't exist yet.
- Migration *safety* (lock-on-deploy, backfill ordering) — that's `migration-safety.md` (MIG prefix).

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
--scope app/Http/Controllers/Api/Webhooks
--scope app/Jobs/Gdpr
--scope app/Services
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

Walk every migration and every model in scope. Every `belongsTo` is a candidate for a missing FK finding. Every `updateOrCreate` / `firstOrCreate` is a candidate for a missing unique constraint. Every soft-deletable model's eager-loaded relationships must be checked for the `whereNull` guard. **The data layer is where silent corruption lives; under-reporting compounds.**
