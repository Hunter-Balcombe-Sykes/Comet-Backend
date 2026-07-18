# Segment Filter Criteria Expansion — Design

**Date:** 2026-07-18
**Status:** Approved design, pending implementation plan
**Owner:** Josh

## Purpose

Extend the staff user-segment filter engine so dynamic segments (consumed by the
kill-switch, staff notifications, and any future staff tooling via
`SegmentResolver::userIds()`) can target users by four new criteria:

1. **Location** — country / state / city
2. **Time-on-Partna (tenure)** — relative days since signup
3. **Instagram follower count** — from the synced connection payload
4. **Analytics** — visit/click volume over a lookback window

All four ship together in one implementation plan, sequenced smallest-first.

## Non-goals

- No change to segment storage: definitions stay in the `filters` JSONB column
  on `core.user_segments`. (Considered and rejected: a relational
  `segment_criteria` table — the value column ends up polymorphic JSONB anyway,
  there is exactly one consumer that always reads the whole definition, and
  integrity is enforced at the write boundary by staff-gated Form Requests.)
- No precomputed rollup table/job for followers or view totals (YAGNI at
  pre-beta scale; revisit if resolver latency ever matters).
- No "distinct values" preview endpoint for free-text state/city; staff types
  values blind and validates via the existing segment `count()` preview.
- No per-day average comparator for analytics (derivable: `min = X × window_days`).

## Architecture

**Unchanged contract:** `SegmentResolver::dynamicQuery()` compiles active
filter keys into **one query on `core.users`**, AND-combining criteria; manual
members UNION in afterwards. Criteria whose data lives outside `core.users`
reach it via correlated subqueries (`whereHas` / `EXISTS`), so the resolver
still returns a single `Builder` and no downstream consumer changes.

**New structure — criterion registry** (the mini-`PlatformRegistry` play:
"add a criterion = one class + one registry line"). The existing six keys
migrate into this structure as step 0, in their own commit, before any new
criterion lands:

```
app/Services/Segments/Criteria/
  SegmentCriterion.php        — interface: key(), rules(), isActive($value), apply(Builder, $value)
  AccountTypeCriterion.php    ┐
  SectorCriterion.php         │
  CreatedRangeCriterion.php   │  existing 6 keys, behavior-identical migration
  HasIntegrationCriterion.php │  (created_from/created_to pair in one class)
  EarlyAccessCriterion.php    ┘
  CountryCodeCriterion.php    ┐
  LocationStateCriterion.php  │
  LocationCityCriterion.php   │  new criteria, one class each
  TenureCriterion.php         │  (tenure min/max pair in one class)
  IgFollowersCriterion.php    │
  AnalyticsCriterion.php      ┘
  SegmentCriteria.php         — the registry: explicit ordered list of criterion
                                instances (no auto-discovery; greppable)
```

- `SegmentResolver::dynamicQuery()` iterates the registry: for each criterion
  whose `isActive($filters[key])` passes, call `apply($query, $value)`. The
  `DYNAMIC_KEYS` constant and if-chain disappear; "zero active criteria → null"
  semantics preserved.
- `StoreSegmentRequest::rules()` merges each criterion's `rules()` — validation
  and query semantics live in **one file per criterion** and cannot drift
  apart. Structural rules (`name`, `description`, `include_manual_members`)
  stay in the request.
- `isActive()` owns the per-shape activation check: scalars/arrays use the
  existing non-null/non-`''` rule; object criteria are active only if
  non-empty after stripping nulls.

House rules every new key inherits:

- missing/null key → unconstrained
- keys AND-combine
- zero dynamic keys → dynamic set is EMPTY (manual-members-only)
- soft-deleted users always excluded

**Key-shape principle:** independent knobs → flat keys (tenure, location);
interdependent config → one object key (`ig_followers`, `analytics`). Object
keys make activation atomic (key present or not — no half-configured states),
keep cross-field validation structural, and let staff clear a criterion with a
single `null`.

## Filter keys

### Location (3 flat keys)

```jsonc
"country_code":   ["AU", "NZ"],        // ISO alpha-2, uppercase
"location_state": ["NSW", "Victoria"], // free-text column, case-insensitive exact match
"location_city":  ["Sydney"]           // same
```

- `country_code` → plain `whereIn` on `core.users.country_code` (column is
  regex-validated ISO alpha-2 at profile write time).
- `location_state` / `location_city` → `whereIn(LOWER(col), [lowercased values])`
  on the free-text profile columns. Best-effort by design; users who left the
  field blank never match (fail-closed).

### Tenure (2 flat keys)

```jsonc
"tenure_days_min": 30,  // on Partna ≥ 30 days → created_at <= now() - 30d
"tenure_days_max": 90   // on Partna ≤ 90 days → created_at >= now() - 90d
```

Relative convenience over the same `created_at` column as the existing absolute
`created_from`/`created_to`; all four compose by AND. Evaluated at resolve time,
so tenure segments stay current with zero maintenance. All date math in PHP
(Carbon), bound as parameters — no SQL date arithmetic.

### Instagram followers (1 object key)

```jsonc
"ig_followers": { "min": 1000, "max": 50000, "synced_within_days": 30 }
```

- Matches **active** (`is_active = true`) `instagram` rows in
  `site.platform_connections` via `whereHas('integrationConnections', …)` —
  same pattern as `has_integration`.
- Value read from `payload->>'followersCount'` (payload is `jsonb NOT NULL`;
  the value is `int|string|null` per `InstagramPayload::intStringOrNull`).
- **Cast guard (load-bearing):** Postgres `::int` on non-numeric text throws.
  The predicate must be `payload->>'followersCount' ~ '^\d+$'` **before**
  `(payload->>'followersCount')::bigint` (bigint so garbage-huge values can't
  overflow). Missing / non-numeric → user excluded.
- `synced_within_days` (optional): `COALESCE(last_refreshed_at, created_at) >=
  :cutoff` — never-refreshed rows fall back to `created_at` because a fresh
  connect is fresh data. Omitted → no freshness constraint.
- At least one of `min`/`max` required for the key to be active
  (`synced_within_days` alone is a freshness check on nothing → rejected).

### Analytics (1 object key)

```jsonc
"analytics": { "metric": "visits", "window_days": 30, "min": 100, "max": null }
```

- Source: `analytics.site_metrics_daily` (per-user daily rows keyed
  `professional_id + day`). `metric` ∈ `visits | unique_visitors | clicks |
  unique_clickers`, resolved through a **hardcoded metric→column map** — the
  allowlist is the SQL-injection boundary; input is never interpolated.
- `window_days` (1–365): lookback from today, cutoff computed in PHP and bound.
- `min`/`max`: thresholds on the **summed total** over the window. At least one
  required.
- Compile shapes:
  - `min` set (± `max`):
    `EXISTS (SELECT 1 FROM analytics.site_metrics_daily m
     WHERE m.professional_id = core.users.id AND m.day >= :cutoff
     HAVING SUM(m.<col>) >= :min [AND SUM(m.<col>) <= :max])`
    (the `<= :max` clause is appended only when `max` is present; no
    `GROUP BY` needed — an ungrouped aggregate returns one row, and over an
    empty set `SUM` is NULL, so the HAVING comparison is false and the user
    is excluded, which is exactly the min-set zero-row semantics)
  - `max` only:
    `NOT EXISTS (… HAVING SUM(m.<col>) > :max)`
- **Zero-row semantics (deliberate):** a user with no analytics rows in the
  window is **excluded** when `min` is set, and **included** when only `max` is
  set (no rows = 0 views ≤ max) — the NOT EXISTS shape produces this naturally
  and enables "target low-traffic users" without special-casing.
- Existing index `site_metrics_daily_professional_day_idx (professional_id,
  day DESC)` serves the correlated lookup; no new indexes.

## Resolver changes

- `SegmentResolver` keeps its public surface (`userIds()`, `count()`,
  `dynamicQuery()`) but delegates criterion compilation to the registry (see
  Architecture). Manual-member union and soft-delete exclusion stay in the
  resolver — they are set semantics, not criteria.
- Object-key activation: `"ig_followers": {}` and `{"min": null}` are inert
  (active only if non-empty after stripping nulls), owned by each criterion's
  `isActive()`.
- The JSON extract+cast is the only driver-specific SQL, isolated inside
  `IgFollowersCriterion`:
  - **pgsql:** `payload->>'followersCount' ~ '^\d+$' AND
    (payload->>'followersCount')::bigint …`
  - **sqlite (test mirror):** digit `GLOB` guard +
    `CAST(json_extract(payload, '$.followersCount') AS INTEGER)`

## Validation

Each criterion class declares its own `rules()`; `StoreSegmentRequest::rules()`
merges them from the registry (`UpdateSegmentRequest` shares rules as today).
The per-criterion rules are:

```php
'filters.country_code'      => ['sometimes', 'nullable', 'array', 'max:50'],
'filters.country_code.*'    => ['string', 'size:2', 'regex:/^[A-Z]{2}$/'],
'filters.location_state'    => ['sometimes', 'nullable', 'array', 'max:50'],
'filters.location_state.*'  => ['string', 'max:255'],
'filters.location_city'     => ['sometimes', 'nullable', 'array', 'max:50'],
'filters.location_city.*'   => ['string', 'max:255'],

'filters.tenure_days_min'   => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
'filters.tenure_days_max'   => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650', 'gte:filters.tenure_days_min'],

'filters.ig_followers'                    => ['sometimes', 'nullable', 'array'],
'filters.ig_followers.min'                => ['sometimes', 'nullable', 'integer', 'min:0'],
'filters.ig_followers.max'                => ['sometimes', 'nullable', 'integer', 'min:0', 'gte:filters.ig_followers.min'],
'filters.ig_followers.synced_within_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],

'filters.analytics'             => ['sometimes', 'nullable', 'array'],
'filters.analytics.metric'      => ['required_with:filters.analytics', Rule::in(['visits', 'unique_visitors', 'clicks', 'unique_clickers'])],
'filters.analytics.window_days' => ['required_with:filters.analytics', 'integer', 'min:1', 'max:365'],
'filters.analytics.min'         => ['sometimes', 'nullable', 'integer', 'min:0'],
'filters.analytics.max'         => ['sometimes', 'nullable', 'integer', 'min:0', 'gte:filters.analytics.min'],
```

Plus two closure rules structure can't express:

1. `ig_followers` requires at least one of `min`/`max`.
2. `analytics` requires at least one of `min`/`max`.

Unknown sub-keys inside the two objects are stripped in `prepareForValidation`
(consistent with the engine ignoring unknown top-level keys). No API shape
changes otherwise — same endpoints, same `SegmentResource`, consumers untouched.

## Testing

0. **Step-0 migration is behavior-locked by the existing suite**: the current
   `SegmentResolverTest` (6 tests) and segment request tests must pass
   unchanged against the registry-based resolver before any new criterion
   lands — that green run *is* the proof the refactor was mechanical.
1. **Per-criterion resolver tests** extending
   `tests/Feature/Staff/SegmentResolverTest.php`: matches / excludes /
   inert-when-null per key, plus AND-composition with an existing key. IG tests
   cover the non-numeric-string guard (excludes, doesn't error) and the
   `COALESCE` freshness fallback. Analytics tests cover both compile shapes —
   especially **zero-row users included under max-only**, the semantic most
   likely to regress.
2. **New Pest.php mirror helper** for `analytics.site_metrics_daily`, columns
   matching the real DDL verbatim (the `analytics` schema is already ATTACHed).
3. **Postgres SQL pinning** — unit test asserting the pgsql output of the
   driver-aware helper contains the `~ '^\d+$'` guard before the `::bigint`
   cast (grammar compilation, no live connection). Guards against the
   SQLite-green/Postgres-500 drift trap.
4. **Validation tests** — closure rules, `gte` cross-fields, ISO regex,
   unknown-subkey stripping.
5. **Real-Postgres verification** (schema-drift discipline): run each criterion
   once against dev Supabase (tinker / `database-query`) at implementation end;
   confirm no cast errors and sane counts. Plan checklist item, not CI.

## Rollout

- **No migrations** — all criteria read existing columns/tables. No config, no
  feature flag.
- Purely additive: new keys are inert on every existing segment.
- Implementation sequence, separate commits on one branch off `development`,
  suite green at each commit:
  0. **Registry migration** — existing 6 keys move into criterion classes,
     behavior-identical (existing tests pass unchanged).
  1. Location + tenure criteria (S).
  2. IG followers criterion (M).
  3. Analytics criterion (L).
- `docs/api.md`: document the new `filters` keys on the segment endpoints.
