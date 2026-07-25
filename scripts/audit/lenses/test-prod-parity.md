# Test↔prod schema parity: application writes that pass SQLite CI but violate Postgres constraints

Hunt **writes that ship green and 500 on real Postgres.** The test suite runs against an in-memory **SQLite** schema; production is **PostgreSQL** (Supabase). The two schemas *drift* — the SQLite stand-in does not mirror every prod constraint, and SQLite silently ignores whole classes of constraint that Postgres enforces. So an `->create([...])` / `->fill()` / `insert()` / `updateOrCreate()` / `save()` that sets a value Postgres would reject can pass CI green and only 500 in production. This lens finds those writes **before** they ship.

This is the **runtime-write** direction, and it is the mirror image of two sibling lenses — emit under whichever is more specific and let the adjudicator dedupe:
- `data-integrity.md` (DINT) asks *"does the schema HAVE the right constraint?"* — schema completeness at rest.
- `migration-safety.md` (MIG) asks *"is the DDL rollout safe?"* — locks, backfills, reversibility.
- **This lens (PARITY) asks the opposite:** *given a constraint that already EXISTS in the prod DDL, does application code ever write a value that violates it — and would SQLite mask that violation in the test suite?* The finding is the **code write**, proven against the **real migration DDL**.

Partna uses **PostgreSQL** (Supabase-hosted). Schema changes live ONLY in `supabase/migrations/` as raw SQL — that DDL is the **single source of truth** for what prod enforces. The SQLite test schema is seeded in `tests/Pest.php` / `tests/TestCase.php` and is a hand-maintained approximation, not a mirror. Known, documented drift (from `CLAUDE.md`): the async Instagram connect shipped green **twice** — first a `payload => null` write against a `NOT NULL` column, then a `last_refresh_status => 'pending'` write outside that column's `CHECK` set. SQLite accepted both; real Postgres rejected both. That is the exact failure class this lens exists to catch.

## Grounding mandate (anti-hallucination — this overrides the urge to flag)

**Every finding MUST quote the offending column's DDL, read from a file under `supabase/migrations/`, plus the application write that violates it.** Never flag from a prior about "what the schema probably is." Two hard rules that follow:

- **If the constraint is not in the repo migrations, it is NOT a hard finding.** Some prod tables were applied directly to the dev DB and are *absent from `supabase/migrations/`* (e.g. `site.platform_connections`). Their live DDL is unverifiable from the repo — the most you may emit is a P3 "verify against live DB" note, never a P1.
- **SQLite masks a constraint ≠ the write is wrong.** A write is only a finding if it can actually produce a value Postgres rejects on a reachable path. Prove reachability; don't flag a write that a Form Request or enum cast already constrains upstream.

## Use the lens prefix `PARITY` for findings

Number them `PARITY-1`, `PARITY-2`, … sequentially. Tier by whether it ships and how common the path:
- **P1** — a write on a **common path** sets a value the prod DDL rejects (missing NOT-NULL column with no DB default, literal outside a CHECK/enum set, wrong type into a typed column) AND the SQLite schema masks it. This is a green-CI prod 500.
- **P2** — edge-path-only wrongness, or a violation currently masked by luck (a default that happens to satisfy the constraint today but isn't guaranteed).
- **P3** — precision/type narrowing harmless today, or an "unverifiable from repo" note on an applied-directly table.

## Findings categories

### (1) NOT NULL violations SQLite masks

- A write (`create` / `insert` / `fill` / `updateOrCreate` / `firstOrCreate`) that **omits, or explicitly sets `null` on, a column declared `NOT NULL` in the migration** where that column has **no DB-level `DEFAULT`**. Quote the column DDL from `supabase/migrations/`.
- Reliance on an Eloquent `$attributes` default or a model mutator to fill a NOT-NULL column — confirm it actually covers *every* write path (mass-assign, `->save()` after `new`, factory, job, observer), not just the happy one.
- A column that is `NOT NULL` in prod but `NULL`/absent in the SQLite seed (`tests/Pest.php`) — the test can never catch the omission. Naming the seed gap is part of the fix.

### (2) CHECK / enum-domain violations SQLite ignores entirely

SQLite does **not enforce `CHECK` constraints at all**, and has no `ENUM`/`DOMAIN` types — so a bad literal sails through every test.

- A write of a literal or variable to a column whose migration carries `CHECK (col IN (...))` (or a Postgres `ENUM`/`DOMAIN`) where the value isn't in the allowed set. Canonical in-repo examples: `site.sites.architecture_id CHECK (architecture_id = 'one')` (constraint `sites_architecture_id_check`, renamed in `supabase/migrations-archive/20260710230000_rename_skeleton_id_to_architecture_id.sql`; the live definition now sits in the collapsed baseline `supabase/migrations/20260726000000_baseline_pilot.sql`); `site.site_media.pool` CHECK.
- **App enum ↔ DB CHECK drift, both directions.** An `app/Enums/*` case that the DB CHECK doesn't allow (write 500s), OR a DB-allowed value the app enum can't represent (read/cast breaks). Cross-reference the enum cases against the CHECK set verbatim.
- A `status` / `type` / `kind` column written from a string built at runtime (concatenation, interpolation, external payload) rather than an enum — the value can drift outside the CHECK set with no compiler or SQLite guard.

### (3) FK violations SQLite has switched off

SQLite ships with `PRAGMA foreign_keys = OFF` by default — an insert referencing a non-existent parent **passes tests**, then hits `foreign_key_violation` on Postgres.

- A write setting a `*_id` to a value not guaranteed to reference a live row — placeholder/default ids, ids from an external system, or cross-schema references (`core.*` → `site.*`).
- An insert ordering hazard: child rows written before the parent commits, tolerated by SQLite, rejected by a real FK.
- Confirm against the migration's `REFERENCES` clause and `ON DELETE` rule; a `RESTRICT`/`NO ACTION` parent-delete path that tests never exercise is in scope.

### (4) Type / precision / representation drift

SQLite's dynamic typing accepts almost anything into any column; Postgres is strict.

- `TIMESTAMPTZ` columns written a timezone-naive or non-parseable string.
- `jsonb` columns written a raw PHP string (or double-encoded JSON) where the app elsewhere reads an array — Postgres `jsonb` rejects malformed JSON; SQLite `TEXT` stores the bytes.
- `uuid` columns written a non-UUID value; `numeric(p,s)` written a value exceeding precision/scale; `integer`/`bigint` overflow that SQLite's dynamic typing swallows.

### (5) DB-default divergence

- A write that **relies on a DB-level default** the SQLite seed doesn't replicate — `DEFAULT now()`, `gen_random_uuid()`, `'{}'::jsonb` — so tests pass with a value the app never sets but prod computes differently (or, inversely, tests pass because SQLite invented a default prod won't).
- A NOT-NULL column whose only value source is a DB default that a bulk `insert()` bypasses (e.g. `DB::table(...)->insert(...)` skips Eloquent, so mutators/`$attributes` don't fire).

### (6) Uniqueness / partial-index divergence

- A write path relying on the DB to reject a duplicate via a **partial unique index** (`... WHERE deleted_at IS NULL`) that the SQLite seed doesn't reproduce — tests never see the conflict; prod raises `unique_violation`.
- `updateOrCreate` / `firstOrCreate` whose match keys aren't backed by a real UNIQUE constraint in the migration — the race is unguarded in prod but invisible in single-threaded tests.

### (7) Trigger / append-only invariants absent in tests

- `audit.*` tables reject `UPDATE`/`DELETE` via Postgres triggers (`app_backend` has SELECT/INSERT only). SQLite has no such trigger, so a test exercising a mutate path passes while prod raises. Flag any app code path that could `update()`/`delete()` an `audit.*` row. (Overlaps DINT cat 10 — emit here when the *runtime write* is the finding.)
- Trigger-maintained inserts (e.g. `trg_create_empty_design_kit`) that app code duplicates directly — a second insert prod's `ON CONFLICT` handles but the SQLite seed models differently.

## Per-finding requirements

For every finding:
- Cite the category number (1–7).
- **Quote the column DDL** — file under `supabase/migrations/` + the exact `NOT NULL` / `CHECK (...)` / `REFERENCES ...` / type clause.
- **Show the application write** — the `->create([...])` / `insert()` / `fill()` call and the offending column + value, with its file path.
- State **why SQLite masks it** (CHECK ignored / FK off / NOT NULL absent in seed / dynamic typing).
- Name the canonical fix: `add <col> to the write payload`, `align app/Enums case with DB CHECK`, `route through Eloquent so $attributes/mutator fires`, `add the missing constraint to the SQLite seed in tests/Pest.php so CI catches it`, or `satisfy the prod default explicitly`.
- If the table/constraint is **not in `supabase/migrations/`**, mark it "unverifiable from repo — verify against live DB" and cap at P3.

## Out of scope — do NOT re-flag

- Constraint *completeness* ("this column SHOULD have a CHECK / FK / index") — that's `data-integrity.md` (DINT).
- Migration *rollout* safety (lock-on-deploy, backfill ordering, reversibility) — that's `migration-safety.md` (MIG).
- Larastan/PHPStan-covered symbol-existence issues.
- Tables/constraints absent from `supabase/migrations/` (applied-directly-to-dev drift such as `site.platform_connections`) — at most a P3 "verify against live DB" note, never a hard finding.
- The historical Instagram `payload` / `last_refresh_status` incident itself — already fixed, and that table's CHECK was later dropped in the platform-registry redesign. It is the *motivating example*, not a current finding.
- Test-only schema polish that maps to no real prod write risk.
- Removed schemas (`commerce.*`, `brand.*`, booking/Fresha) — they don't exist.

## Suggested per-domain scope groups

### Group A — Constraint source of truth (read first — everything grounds here)
```
--scope supabase/migrations
--scope app/Enums
```

### Group B — Model + factory + test-schema fidelity
```
--scope app/Models
--scope app/Observers
--scope database/factories
--scope tests/Pest.php
--scope tests/TestCase.php
```

### Group C — Service writes
```
--scope app/Services
```

### Group D — Job + controller writes
```
--scope app/Jobs
--scope app/Http/Controllers/Api/User
--scope app/Http/Controllers/Api/Platforms
--scope app/Http/Controllers/Api/Internal
--scope app/Http/Controllers/Api/Webhooks
```

## Exhaustiveness directive

Walk every `NOT NULL`-without-default, every `CHECK` / enum / domain, every `REFERENCES`, and every `TIMESTAMPTZ` / `uuid` / `jsonb` column in `supabase/migrations/`. For each, find the write paths that populate it. A write that never sets a NOT-NULL-no-default column, or writes a literal outside a CHECK set, or references an unguaranteed FK, is a finding — **but only if you can quote the DDL that proves it.** Prefer a four-line proof from real migration SQL over any speculation about "what the schema probably enforces." Under-reporting a green-CI prod 500 is the failure this lens exists to prevent; hallucinating a constraint that isn't in the repo is the failure that discredits it.
