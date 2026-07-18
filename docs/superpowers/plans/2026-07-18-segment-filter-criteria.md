# Segment Filter Criteria Expansion — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the staff user-segment filter engine with four new targeting criteria — location, tenure, Instagram followers, analytics — restructured so that adding a criterion is one class plus one registry line.

**Architecture:** `SegmentResolver::dynamicQuery()` keeps its exact public contract (one query on `core.users`, criteria AND-combined, manual members UNIONed afterwards by `userIds()`). The `DYNAMIC_KEYS` constant and its if-chain are replaced by a **criterion registry**: an explicit ordered list of small classes, each owning one filter key (or one key-pair), each declaring both its validation rules and its query compilation. `StoreSegmentRequest::rules()` merges rules from the same registry, so validation and query semantics for a criterion live in one file and cannot drift apart. Criteria whose data lives outside `core.users` reach it via correlated subqueries (`whereHas` / raw `EXISTS`), so the resolver still returns a single `Builder` and no downstream consumer changes.

**Tech Stack:** PHP 8.2, Laravel 12, Eloquent query builder, Pest 4. Tests run on in-memory SQLite (schemas ATTACHed, see `tests/Pest.php`); production is PostgreSQL on Supabase.

---

## Global Constraints

- **No Laravel migrations, ever.** A composer guard (`guard:no-laravel-migrations`) rejects them.
- **This feature needs no SQL migrations at all.** Every criterion reads columns and tables that already exist. Do not create anything under `supabase/migrations/`.
- **No config keys, no feature flags.** The feature is purely additive — new keys are inert on every existing segment.
- Branch off `origin/development` (NOT `production`).
- **The executor never pushes and never merges.** Josh owns all commits and pushes. Stop after the final task and report.
- 4-space indentation, LF line endings. Run `php artisan pint --dirty` before each commit.
- Business logic lives in `app/Services/`, validation in Form Requests. No inline `abort(403)`.
- Comment the non-obvious WHY (constraints, ordering requirements, driver quirks). Do not narrate what the next line does.
- Tests: `tests/Feature/{domain}/` for integration, `tests/Unit/` for isolated logic.

---

## Verified Spec Deltas

The spec at `docs/superpowers/specs/2026-07-18-segment-filter-criteria-design.md` is the source of truth for **design** (registry structure, key shapes, zero-row semantics — all settled, do not re-litigate). Five of its **factual premises** were checked against the repo and the live dev database on 2026-07-18 and found stale. This plan implements the corrected versions. Do not "fix" these back toward the spec text.

| # | Spec says | Verified reality | This plan does |
|---|---|---|---|
| D1 | Analytics reads `analytics.site_metrics_daily` | Table has **0 rows** on dev and **no writer anywhere in `app/`** — two code comments call it vestigial (`ComputeContentPopularityScores.php:22`, `PurgeRawAnalyticsEventsCommandTest.php:5`). Meanwhile `analytics.site_visits` has 3,747 rows and `analytics.link_clicks` 173, still receiving writes today. | Analytics criterion reads the **raw event tables**. Approved by Josh 2026-07-18. |
| D2 | Analytics keyed on `professional_id` | Migration `20260527030000` renamed `professional_id` → **`user_id`** across all six analytics tables. The baseline migration (`20260526000000`, one day earlier) still shows the old name — do not trust it. | All analytics SQL uses `user_id`. |
| D3 | `window_days` 1–365 | Raw events are purged at 90 days (`partna.analytics_raw_event_retention_days`, default 90). A 365-day window would silently read a 90-day truth. | `window_days` validated **1–90**. |
| D4 | `EXISTS (SELECT 1 … HAVING SUM(…) >= :min)` with no `GROUP BY` | Postgres accepts a bare `HAVING`; **SQLite rejects it** — `HAVING clause on a non-aggregate query`. Postgres-green / SQLite-broken. | Adds `GROUP BY m.user_id`. Verified to produce identical results on both drivers for all five shapes, including the zero-row case. |
| D5 | IG guard is `payload->>'…' ~ '^\d+$' AND (payload->>'…')::bigint …` | Postgres does **not** guarantee `AND` operand evaluation order, so a bare conjunction can still evaluate the cast on non-numeric text and throw. | Uses a `CASE WHEN <regex> THEN <cast> ELSE NULL END` expression — Postgres documents `CASE` as short-circuiting, and the regex still appears textually before the cast, so the spec's pinning assertion holds unchanged. |

One further correction, to the task brief rather than the spec: because of D1, **no new `tests/Pest.php` mirror helper is needed.** `setupSiteVisitsTable()` and `setupLinkClicksTable()` already exist and already carry `user_id`, `visitor_id`, and `occurred_at`. Do not add a `site_metrics_daily` helper.

And one bug found in the spec's validation rules, corrected in Task 4:

> `gte:filters.<x>_min` **fails when the min field is absent or null** — Laravel compares against nothing and rejects. This would make `tenure_days_max` alone, `ig_followers.max` alone, and `analytics.max` alone impossible to save through the API, even though the resolver supports all three. Max-only analytics is the spec's own headline "target low-traffic users" case. Task 4 introduces `App\Rules\MaxNotBelowMin` for this. Note the existing `created_to => after_or_equal:filters.created_from` does **not** have this bug (verified) — leave it exactly as it is.

---

## File Structure

**Created:**

| Path | Responsibility |
|---|---|
| `app/Services/Segments/Criteria/SegmentCriterion.php` | Interface every criterion implements |
| `app/Services/Segments/Criteria/ResolvesFilterValues.php` | Trait: shared "is this key set" / "strip nulls" helpers |
| `app/Services/Segments/Criteria/MatchesFreeTextLocation.php` | Trait: case-insensitive match on a free-text profile column |
| `app/Services/Segments/Criteria/SegmentCriteria.php` | The registry — explicit ordered list, no auto-discovery |
| `app/Services/Segments/Criteria/AccountTypeCriterion.php` | `account_type` |
| `app/Services/Segments/Criteria/SectorCriterion.php` | `sector` |
| `app/Services/Segments/Criteria/CreatedRangeCriterion.php` | `created_from` + `created_to` pair |
| `app/Services/Segments/Criteria/HasIntegrationCriterion.php` | `has_integration` |
| `app/Services/Segments/Criteria/EarlyAccessCriterion.php` | `early_access` |
| `app/Services/Segments/Criteria/CountryCodeCriterion.php` | `country_code` |
| `app/Services/Segments/Criteria/LocationStateCriterion.php` | `location_state` |
| `app/Services/Segments/Criteria/LocationCityCriterion.php` | `location_city` |
| `app/Services/Segments/Criteria/TenureCriterion.php` | `tenure_days_min` + `tenure_days_max` pair |
| `app/Services/Segments/Criteria/IgFollowersCriterion.php` | `ig_followers` object key + driver-aware JSON expression |
| `app/Services/Segments/Criteria/AnalyticsCriterion.php` | `analytics` object key + metric allowlist |
| `app/Rules/MaxNotBelowMin.php` | Data-aware "max ≥ min, but only when min is present" rule |
| `tests/Unit/Segments/IgFollowersExpressionTest.php` | Postgres SQL pinning (no live connection) |
| `tests/Feature/Staff/SegmentFilterValidationTest.php` | Form Request rules for the new keys |

**Modified:**

| Path | Change |
|---|---|
| `app/Services/Segments/SegmentResolver.php` | `dynamicQuery()` iterates the registry; `DYNAMIC_KEYS` and the if-chain deleted |
| `app/Http/Requests/Api/Staff/Segments/StoreSegmentRequest.php` | Merges criterion rules; keeps structural rules; strips unknown object sub-keys |
| `app/Models/Core/Segments/UserSegment.php` | Docblock listing the filters shape |
| `tests/Feature/Staff/SegmentResolverTest.php` | Extends the inline `site.platform_connections` mirror; adds per-criterion tests |
| `docs/api.md` | Documents segment endpoints + the full `filters` key set |

**Untouched (verify you have not changed them):** `app/Http/Requests/Api/Staff/Segments/UpdateSegmentRequest.php` inherits the merged rules automatically. `app/Http/Controllers/Api/Staff/Segments/StaffSegmentController.php`, `app/Http/Resources/Staff/UserSegmentResource.php`, and all consumers of `SegmentResolver` need no changes.

---

## Task 1: Criterion interface, trait, and registry scaffold

Pure structure — no behavior yet. Nothing calls this until Task 2, so no test of its own; Task 2's green run against the *unchanged* existing suite is the proof.

**Files:**
- Create: `app/Services/Segments/Criteria/SegmentCriterion.php`
- Create: `app/Services/Segments/Criteria/ResolvesFilterValues.php`
- Create: `app/Services/Segments/Criteria/SegmentCriteria.php`

**Interfaces:**
- Produces: `SegmentCriterion` with `keys(): array`, `rules(): array`, `isActive(array $filters): bool`, `apply(Builder $query, array $filters): void`. `SegmentCriteria::all(): array<SegmentCriterion>`. Trait `ResolvesFilterValues` with `hasValue(array, string): bool` and `objectConfig(array, string): array`.

> **Why `apply(Builder, array $filters)` and not `apply(Builder, $value)`:** the spec's own structure has two criteria that own a *pair* of keys (`created_from`/`created_to`, `tenure_days_min`/`tenure_days_max`). A single `$value` cannot express a pair. Passing the whole filters array handles both flat and paired keys with one signature.

- [ ] **Step 1: Create the interface**

```php
<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;

/**
 * One targeting criterion in a segment definition.
 *
 * Each implementation owns BOTH its validation rules and its query
 * compilation, so the two can never drift apart. Register in SegmentCriteria.
 */
interface SegmentCriterion
{
    /**
     * Every `filters` key this criterion owns. Most own one; range criteria
     * own a min/max pair. Used by the registry-coverage test, not the resolver.
     *
     * @return list<string>
     */
    public function keys(): array;

    /**
     * Validation rules merged into StoreSegmentRequest::rules().
     * Keys are dot-paths including the `filters.` prefix.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Is this criterion engaged for the given filter definition? A segment
     * where NO criterion is active resolves to the empty dynamic set.
     */
    public function isActive(array $filters): bool;

    /** Constrain the core.users query. Only ever called when isActive() is true. */
    public function apply(Builder $query, array $filters): void;
}
```

- [ ] **Step 2: Create the shared helper trait**

```php
<?php

namespace App\Services\Segments\Criteria;

/** Shared activation helpers — the house rules for "is this key set". */
trait ResolvesFilterValues
{
    /**
     * A scalar/array key counts as set when present and neither null nor ''.
     * Note `false` IS a value (early_access => false is a real constraint).
     */
    protected function hasValue(array $filters, string $key): bool
    {
        return array_key_exists($key, $filters)
            && $filters[$key] !== null
            && $filters[$key] !== '';
    }

    /**
     * Object-key config with nulls stripped, so `{}` and `{"min": null}` are
     * both inert. Non-array values yield [].
     *
     * @return array<string, mixed>
     */
    protected function objectConfig(array $filters, string $key): array
    {
        $value = $filters[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        return array_filter($value, fn ($v) => $v !== null && $v !== '');
    }
}
```

- [ ] **Step 3: Create the registry with an empty list**

```php
<?php

namespace App\Services\Segments\Criteria;

/**
 * The segment criterion registry.
 *
 * Explicit and ordered on purpose — no auto-discovery, so `grep` finds every
 * criterion the engine will ever apply. Adding a criterion is one class plus
 * one line here. Order affects only SQL clause order, never results.
 */
final class SegmentCriteria
{
    /** @return list<SegmentCriterion> */
    public static function all(): array
    {
        return [
            // Task 2 fills this in.
        ];
    }
}
```

- [ ] **Step 4: Verify the files parse**

Run: `php -l app/Services/Segments/Criteria/SegmentCriterion.php && php -l app/Services/Segments/Criteria/ResolvesFilterValues.php && php -l app/Services/Segments/Criteria/SegmentCriteria.php`

Expected: `No syntax errors detected` three times.

**Do not commit yet** — Task 2 completes this commit.

---

## Task 2: Migrate the existing six keys into criterion classes

**This is the highest-risk task in the plan and it has a precise acceptance proof: `tests/Feature/Staff/SegmentResolverTest.php` must pass with ZERO edits to that file.** If you find yourself wanting to change a test, you have changed behavior — stop and re-read the original `SegmentResolver::dynamicQuery()`.

**Files:**
- Create: `app/Services/Segments/Criteria/{AccountType,Sector,CreatedRange,HasIntegration,EarlyAccess}Criterion.php`
- Modify: `app/Services/Segments/Criteria/SegmentCriteria.php`
- Modify: `app/Services/Segments/SegmentResolver.php`
- Modify: `app/Http/Requests/Api/Staff/Segments/StoreSegmentRequest.php`
- Modify: `app/Models/Core/Segments/UserSegment.php` (docblock only)
- Test: `tests/Feature/Staff/SegmentResolverTest.php` (**read-only — must not be edited**)

**Interfaces:**
- Consumes: `SegmentCriterion`, `ResolvesFilterValues`, `SegmentCriteria` from Task 1.
- Produces: a registry containing five criteria; `SegmentResolver::dynamicQuery()` compiled from it.

- [ ] **Step 1: Run the existing suite and record the baseline**

Run: `php artisan test tests/Feature/Staff/SegmentResolverTest.php`

Expected: **6 passed.** Write the number down — it must be identical at Step 9.

- [ ] **Step 2: Create AccountTypeCriterion**

```php
<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/** Exact match on core.users.account_type. */
final class AccountTypeCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['account_type'];
    }

    public function rules(): array
    {
        return [
            'filters.account_type' => ['sometimes', 'nullable', 'string', Rule::in(['partna', 'business'])],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'account_type');
    }

    public function apply(Builder $query, array $filters): void
    {
        $query->where('account_type', (string) $filters['account_type']);
    }
}
```

- [ ] **Step 3: Create SectorCriterion**

```php
<?php

namespace App\Services\Segments\Criteria;

use App\Services\Profile\SectorTaxonomy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Match any of the given sector slugs. Accepts a bare string as well as an
 * array; a list that trims down to nothing applies no constraint at all
 * (preserved from the original resolver).
 */
final class SectorCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['sector'];
    }

    public function rules(): array
    {
        return [
            'filters.sector' => ['sometimes', 'nullable', 'array', 'max:50'],
            'filters.sector.*' => ['string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! SectorTaxonomy::isValid($value)) {
                    $fail("Unknown sector slug: {$value}");
                }
            }],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'sector');
    }

    public function apply(Builder $query, array $filters): void
    {
        $sectors = array_values(array_filter(array_map(
            fn ($s) => is_string($s) ? trim($s) : null,
            is_array($filters['sector']) ? $filters['sector'] : [$filters['sector']]
        )));

        if ($sectors !== []) {
            $query->whereIn('sector', $sectors);
        }
    }
}
```

- [ ] **Step 4: Create CreatedRangeCriterion**

```php
<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Absolute signup-date window. Either bound may be set alone; both AND
 * together, and both compose with the relative TenureCriterion.
 */
final class CreatedRangeCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['created_from', 'created_to'];
    }

    public function rules(): array
    {
        return [
            'filters.created_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'filters.created_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:filters.created_from'],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'created_from') || $this->hasValue($filters, 'created_to');
    }

    public function apply(Builder $query, array $filters): void
    {
        if ($this->hasValue($filters, 'created_from')) {
            $query->where('created_at', '>=', Carbon::parse((string) $filters['created_from'])->startOfDay());
        }

        if ($this->hasValue($filters, 'created_to')) {
            $query->where('created_at', '<=', Carbon::parse((string) $filters['created_to'])->endOfDay());
        }
    }
}
```

- [ ] **Step 5: Create HasIntegrationCriterion**

```php
<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;

/** `true` → any active platform connection; a string → that platform only. */
final class HasIntegrationCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['has_integration'];
    }

    public function rules(): array
    {
        return [
            'filters.has_integration' => ['sometimes', 'nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_bool($value) && ! (is_string($value) && preg_match('/^[a-z][a-z0-9_-]*$/', $value))) {
                    $fail('has_integration must be true or a platform key.');
                }
            }],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'has_integration');
    }

    public function apply(Builder $query, array $filters): void
    {
        $platform = is_string($filters['has_integration']) ? $filters['has_integration'] : null;

        $query->whereHas('integrationConnections', function ($q) use ($platform): void {
            $q->where('is_active', true);
            if ($platform !== null) {
                $q->where('platform', $platform);
            }
        });
    }
}
```

- [ ] **Step 6: Create EarlyAccessCriterion**

```php
<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;

/** Membership in the early-access programme, keyed by primary email. */
final class EarlyAccessCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['early_access'];
    }

    public function rules(): array
    {
        return [
            'filters.early_access' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'early_access');
    }

    public function apply(Builder $query, array $filters): void
    {
        $exists = 'EXISTS (SELECT 1 FROM core.early_access_signups eas WHERE eas.email_lc = LOWER(core.users.primary_email))';

        (bool) $filters['early_access']
            ? $query->whereRaw($exists)
            : $query->whereRaw("NOT {$exists}");
    }
}
```

- [ ] **Step 7: Fill in the registry**

Replace the `all()` body in `app/Services/Segments/Criteria/SegmentCriteria.php`:

```php
    /** @return list<SegmentCriterion> */
    public static function all(): array
    {
        return [
            new AccountTypeCriterion(),
            new SectorCriterion(),
            new CreatedRangeCriterion(),
            new HasIntegrationCriterion(),
            new EarlyAccessCriterion(),
        ];
    }
```

- [ ] **Step 8: Rewrite `dynamicQuery()` to iterate the registry**

In `app/Services/Segments/SegmentResolver.php`: delete the `DYNAMIC_KEYS` constant and replace the whole `dynamicQuery()` method body. Add `use App\Services\Segments\Criteria\SegmentCriteria;` and `use App\Services\Segments\Criteria\SegmentCriterion;`; remove the now-unused `use Illuminate\Support\Carbon;`.

```php
    /**
     * Query for the DYNAMIC part of the segment (null when no dynamic filter is
     * set). Callers wanting the full set must still union manual members —
     * use userIds() unless you specifically need a Builder.
     */
    public function dynamicQuery(UserSegment $segment): ?Builder
    {
        $filters = is_array($segment->filters) ? $segment->filters : [];

        $active = array_values(array_filter(
            SegmentCriteria::all(),
            fn (SegmentCriterion $criterion) => $criterion->isActive($filters)
        ));

        if ($active === []) {
            return null;
        }

        $query = User::query()->select('id'); // SoftDeletes global scope excludes deleted rows

        foreach ($active as $criterion) {
            $criterion->apply($query, $filters);
        }

        return $query;
    }
```

Also update the class docblock's filter-semantics comment to point at the registry:

```php
/**
 * Turns a UserSegment into a live user-id set: dynamic filters evaluated as one
 * query on core.users, UNIONed with the segment's manual members.
 *
 * The available filter keys and their query semantics live in
 * App\Services\Segments\Criteria\SegmentCriteria — one class per criterion.
 *
 * Filter semantics (filters JSONB — see UserSegment):
 *   - missing/null key           → unconstrained
 *   - keys AND-combine
 *   - ZERO dynamic keys set      → dynamic set is EMPTY (manual-members-only
 *     segment). Deliberate: prevents `{}` accidentally meaning "all users".
 *   - soft-deleted users are always excluded (manual members included).
 */
```

- [ ] **Step 9: Run the existing resolver suite — the acceptance proof**

Run: `php artisan test tests/Feature/Staff/SegmentResolverTest.php`

Expected: **6 passed**, same count as Step 1, with **zero edits to the test file**. Confirm with `git diff --stat tests/Feature/Staff/SegmentResolverTest.php` → must print nothing.

- [ ] **Step 10: Point StoreSegmentRequest at the registry**

Replace the whole of `app/Http/Requests/Api/Staff/Segments/StoreSegmentRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Staff\Segments;

use App\Http\Requests\BaseFormRequest;
use App\Services\Segments\Criteria\SegmentCriteria;
use App\Services\Segments\Criteria\SegmentCriterion;

// OV-A: validates segment create (name + filter definition). Update shares
// these rules via UpdateSegmentRequest.
//
// Per-criterion filter rules come from SegmentCriteria so that a criterion's
// validation and its query compilation live in the same file and cannot drift.
// Only structural keys are declared here.
class StoreSegmentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'filters' => ['sometimes', 'array'],
            'filters.include_manual_members' => ['sometimes', 'boolean'],
        ];

        foreach (SegmentCriteria::all() as $criterion) {
            /** @var SegmentCriterion $criterion */
            $rules = array_merge($rules, $criterion->rules());
        }

        return $rules;
    }
}
```

Note `Illuminate\Validation\Rule` and `SectorTaxonomy` are no longer imported here — they moved to the criteria.

- [ ] **Step 11: Refresh the UserSegment docblock**

In `app/Models/Core/Segments/UserSegment.php`, replace the filter-shape sentence in the class comment:

```php
// OV-A: staff-defined user segment — dynamic JSONB filter definition plus a
// manual member list. Resolved to a live user-id set by SegmentResolver.
// The full set of filter keys and their semantics is defined one-class-per-key
// in App\Services\Segments\Criteria\SegmentCriteria; `include_manual_members`
// (default true) is structural and handled by the resolver itself.
```

- [ ] **Step 12: Run every suite that touches segments**

Run: `php artisan test tests/Feature/Staff/`

Expected: all pass. `SegmentTakedownTriggerTest`, `FeatureAvailabilityReadSideTest`, and `StaffAggregateAnalyticsScopeTest` all consume segments and are the regression net for the request-layer change.

- [ ] **Step 13: Run the full suite**

Run: `composer test`

Expected: green. If you see a pre-existing unrelated failure, prove it is pre-existing by stashing your change and re-running that test on the clean tree — never assert "pre-existing" without that evidence.

- [ ] **Step 14: Commit (COMMIT 1 of 5 — spec sequence step 0)**

```bash
php artisan pint --dirty
git add app/Services/Segments app/Http/Requests/Api/Staff/Segments/StoreSegmentRequest.php app/Models/Core/Segments/UserSegment.php
git diff --cached --stat
git commit -m "refactor(segments): move filter keys into a criterion registry

Behavior-identical migration of the six existing dynamic filter keys out of
SegmentResolver's if-chain into one class per criterion. Each criterion now
owns both its validation rules and its query compilation, so the two cannot
drift apart. Existing SegmentResolverTest passes unchanged — that is the proof
the refactor was mechanical."
```

Check the `git diff --cached --stat` output lists **8 files** and does not include `tests/Feature/Staff/SegmentResolverTest.php`.

---

## Task 3: Location and tenure criteria

**Files:**
- Create: `app/Rules/MaxNotBelowMin.php`
- Create: `app/Services/Segments/Criteria/MatchesFreeTextLocation.php`
- Create: `app/Services/Segments/Criteria/{CountryCode,LocationState,LocationCity,Tenure}Criterion.php`
- Modify: `app/Services/Segments/Criteria/SegmentCriteria.php`
- Test: `tests/Feature/Staff/SegmentResolverTest.php`

**Interfaces:**
- Consumes: `SegmentCriterion`, `ResolvesFilterValues`, `SegmentCriteria`.
- Produces: filter keys `country_code`, `location_state`, `location_city`, `tenure_days_min`, `tenure_days_max`; `App\Rules\MaxNotBelowMin` (constructor takes the dot-path of the min field, e.g. `new MaxNotBelowMin('filters.tenure_days_min')`), reused by Tasks 5 and 6.

- [ ] **Step 0: Create the MaxNotBelowMin rule**

`TenureCriterion` (Step 7) is its first consumer, so it has to exist before this task's suite can go green.

> **Why not Laravel's `gte:other`:** verified 2026-07-18 — `gte` rejects the field outright when `other` is absent or null, because it compares against nothing. That would make `tenure_days_max` alone, `ig_followers.max` alone, and `analytics.max` alone all unsaveable, even though the resolver supports every one of them. Max-only analytics is the spec's own "target low-traffic users" case. Note the existing `created_to => after_or_equal:filters.created_from` does **not** have this bug — `after_or_equal` handles the absent case correctly. Leave it exactly as it is.

Create `app/Rules/MaxNotBelowMin.php`:

```php
<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * "max must be >= min" — but only when a min was actually supplied.
 *
 * Laravel's built-in `gte:other` rejects the field outright when `other` is
 * absent or null (it compares against nothing), which would make every
 * max-only filter impossible to save — including the deliberate max-only
 * analytics shape used to target low-traffic users. This rule is a no-op
 * unless both bounds are present and numeric.
 */
final class MaxNotBelowMin implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $minPath) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $min = data_get($this->data, $this->minPath);

        if ($min === null || ! is_numeric($value) || ! is_numeric($min)) {
            return;
        }

        if ($value + 0 < $min + 0) {
            $fail("The {$attribute} field must be greater than or equal to {$this->minPath}.");
        }
    }
}
```

> **Why state/city are matched case-insensitively but country is not:** `core.users.country_code` is regex-validated ISO alpha-2 at profile write time, so it is already normalized. `location_state` and `location_city` are free text the user typed. Matching lowercases both sides. Users who left the field blank match nothing — `LOWER(NULL)` is `NULL`, which is never `IN` a list. That fail-closed behavior is intentional.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Staff/SegmentResolverTest.php`:

```php
it('resolves country_code, location_state and location_city', function () {
    $sydney = ovaSeedUser(['country_code' => 'AU', 'location_state' => 'NSW', 'location_city' => 'Sydney']);
    $melb = ovaSeedUser(['country_code' => 'AU', 'location_state' => 'Victoria', 'location_city' => 'Melbourne']);
    $auckland = ovaSeedUser(['country_code' => 'NZ', 'location_state' => 'Auckland', 'location_city' => 'Auckland']);
    ovaSeedUser(['country_code' => null, 'location_state' => null, 'location_city' => null]);

    $resolver = app(SegmentResolver::class);

    expect($resolver->userIds(ovaSegment(['country_code' => ['AU']])))
        ->toContain($sydney)->toContain($melb)->toHaveCount(2)
        ->and($resolver->userIds(ovaSegment(['country_code' => ['AU', 'NZ']])))->toHaveCount(3)
        ->and($resolver->userIds(ovaSegment(['location_state' => ['Victoria']])))->toBe([$melb])
        ->and($resolver->userIds(ovaSegment(['location_city' => ['Auckland']])))->toBe([$auckland]);
});

it('matches location_state and location_city case-insensitively', function () {
    $melb = ovaSeedUser(['location_state' => 'Victoria', 'location_city' => 'Melbourne']);

    $resolver = app(SegmentResolver::class);

    expect($resolver->userIds(ovaSegment(['location_state' => ['VICTORIA']])))->toBe([$melb])
        ->and($resolver->userIds(ovaSegment(['location_city' => ['melbourne']])))->toBe([$melb]);
});

it('excludes users with a blank location from location filters', function () {
    ovaSeedUser(['location_city' => null]);
    ovaSeedUser(['location_city' => '']);
    $sydney = ovaSeedUser(['location_city' => 'Sydney']);

    expect(app(SegmentResolver::class)->userIds(ovaSegment(['location_city' => ['Sydney']])))->toBe([$sydney]);
});

it('resolves tenure_days_min and tenure_days_max against signup date', function () {
    $veteran = ovaSeedUser(['created_at' => now()->subDays(200)->toDateTimeString()]);
    $midterm = ovaSeedUser(['created_at' => now()->subDays(60)->toDateTimeString()]);
    $rookie = ovaSeedUser(['created_at' => now()->subDays(3)->toDateTimeString()]);

    $resolver = app(SegmentResolver::class);

    // on Partna >= 30 days
    expect($resolver->userIds(ovaSegment(['tenure_days_min' => 30])))
        ->toContain($veteran)->toContain($midterm)->toHaveCount(2)
        // on Partna <= 90 days
        ->and($resolver->userIds(ovaSegment(['tenure_days_max' => 90])))
        ->toContain($midterm)->toContain($rookie)->toHaveCount(2)
        // both bounds → the 30-90 day band
        ->and($resolver->userIds(ovaSegment(['tenure_days_min' => 30, 'tenure_days_max' => 90])))->toBe([$midterm]);
});

it('AND-combines tenure with an existing criterion', function () {
    $djMid = ovaSeedUser(['sector' => 'dj', 'created_at' => now()->subDays(60)->toDateTimeString()]);
    ovaSeedUser(['sector' => 'dj', 'created_at' => now()->subDays(3)->toDateTimeString()]);
    ovaSeedUser(['sector' => 'hairdresser', 'created_at' => now()->subDays(60)->toDateTimeString()]);

    expect(app(SegmentResolver::class)->userIds(ovaSegment(['sector' => ['dj'], 'tenure_days_min' => 30])))
        ->toBe([$djMid]);
});

it('treats null location and tenure keys as inert', function () {
    ovaSeedUser(['location_city' => 'Sydney']);
    $manual = ovaSeedUser();

    $segment = ovaSegment(['country_code' => null, 'location_city' => null, 'tenure_days_min' => null]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $manual]);

    // No criterion active → dynamic set empty → manual members only.
    expect(app(SegmentResolver::class)->userIds($segment))->toBe([$manual]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Staff/SegmentResolverTest.php`

Expected: the 6 new tests FAIL. The location/tenure tests fail by returning too many users (unknown keys are ignored, so every seeded user matches); the inert test may pass already. That is fine — it is a guard, not a driver.

- [ ] **Step 3: Create CountryCodeCriterion**

```php
<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;

/**
 * ISO alpha-2 country match. The column is regex-validated at profile write
 * time, so no case folding is needed here.
 */
final class CountryCodeCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['country_code'];
    }

    public function rules(): array
    {
        return [
            'filters.country_code' => ['sometimes', 'nullable', 'array', 'max:50'],
            'filters.country_code.*' => ['string', 'size:2', 'regex:/^[A-Z]{2}$/'],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'country_code');
    }

    public function apply(Builder $query, array $filters): void
    {
        $codes = $this->stringList($filters['country_code']);

        if ($codes !== []) {
            $query->whereIn('country_code', $codes);
        }
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? trim($v) : null,
            is_array($value) ? $value : [$value]
        ), fn (?string $v) => $v !== null && $v !== ''));
    }
}
```

- [ ] **Step 4: Create the shared free-text location trait**

Create `app/Services/Segments/Criteria/MatchesFreeTextLocation.php`:

```php
<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Case-insensitive exact match against a free-text profile column.
 * Column name is supplied by the criterion, never by user input.
 */
trait MatchesFreeTextLocation
{
    protected function whereLowerIn(Builder $query, string $column, mixed $value): void
    {
        $needles = array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? mb_strtolower(trim($v)) : null,
            is_array($value) ? $value : [$value]
        ), fn (?string $v) => $v !== null && $v !== ''));

        if ($needles === []) {
            return;
        }

        $query->whereIn(DB::raw("LOWER({$column})"), $needles);
    }
}
```

- [ ] **Step 5: Create LocationStateCriterion**

```php
<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;

/**
 * Free-text state/region match, case-insensitive on both sides.
 * Best-effort by design: users who left the field blank never match, because
 * LOWER(NULL) is NULL and NULL is never IN a list.
 */
final class LocationStateCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;
    use MatchesFreeTextLocation;

    public function keys(): array
    {
        return ['location_state'];
    }

    public function rules(): array
    {
        return [
            'filters.location_state' => ['sometimes', 'nullable', 'array', 'max:50'],
            'filters.location_state.*' => ['string', 'max:255'],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'location_state');
    }

    public function apply(Builder $query, array $filters): void
    {
        $this->whereLowerIn($query, 'location_state', $filters['location_state']);
    }
}
```

- [ ] **Step 6: Create LocationCityCriterion**

```php
<?php

namespace App\Services\Segments\Criteria;

use Illuminate\Database\Eloquent\Builder;

/** Free-text city match — same semantics as LocationStateCriterion. */
final class LocationCityCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;
    use MatchesFreeTextLocation;

    public function keys(): array
    {
        return ['location_city'];
    }

    public function rules(): array
    {
        return [
            'filters.location_city' => ['sometimes', 'nullable', 'array', 'max:50'],
            'filters.location_city.*' => ['string', 'max:255'],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'location_city');
    }

    public function apply(Builder $query, array $filters): void
    {
        $this->whereLowerIn($query, 'location_city', $filters['location_city']);
    }
}
```

- [ ] **Step 7: Create TenureCriterion**

```php
<?php

namespace App\Services\Segments\Criteria;

use App\Rules\MaxNotBelowMin;
use Illuminate\Database\Eloquent\Builder;

/**
 * Relative time-on-Partna, over the same created_at column as the absolute
 * CreatedRangeCriterion — all four bounds compose by AND.
 *
 * Evaluated at resolve time, so a tenure segment stays current with zero
 * maintenance. All date math is done in PHP and bound as a parameter; no SQL
 * date arithmetic, which keeps it identical on Postgres and SQLite.
 */
final class TenureCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['tenure_days_min', 'tenure_days_max'];
    }

    public function rules(): array
    {
        return [
            'filters.tenure_days_min' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'filters.tenure_days_max' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650', new MaxNotBelowMin('filters.tenure_days_min')],
        ];
    }

    public function isActive(array $filters): bool
    {
        return $this->hasValue($filters, 'tenure_days_min') || $this->hasValue($filters, 'tenure_days_max');
    }

    public function apply(Builder $query, array $filters): void
    {
        // "on Partna >= N days" means they signed up at or before N days ago.
        if ($this->hasValue($filters, 'tenure_days_min')) {
            $query->where('created_at', '<=', now()->subDays((int) $filters['tenure_days_min']));
        }

        if ($this->hasValue($filters, 'tenure_days_max')) {
            $query->where('created_at', '>=', now()->subDays((int) $filters['tenure_days_max']));
        }
    }
}
```

- [ ] **Step 8: Register the four new criteria**

In `SegmentCriteria::all()`, append after `new EarlyAccessCriterion(),`:

```php
            new CountryCodeCriterion(),
            new LocationStateCriterion(),
            new LocationCityCriterion(),
            new TenureCriterion(),
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Staff/SegmentResolverTest.php`

Expected: **12 passed** (6 original + 6 new).

- [ ] **Step 10: Run the full suite and commit (COMMIT 2 of 5 — spec sequence step 1)**

```bash
composer test
php artisan pint --dirty
git add app/Services/Segments/Criteria app/Rules/MaxNotBelowMin.php tests/Feature/Staff/SegmentResolverTest.php
git diff --cached --stat
git commit -m "feat(segments): location and tenure filter criteria

country_code (ISO alpha-2, exact) plus location_state/location_city
(free-text, case-insensitive, fail-closed on blanks), and relative
tenure_days_min/max over created_at evaluated at resolve time.

Adds App\Rules\MaxNotBelowMin: Laravel's gte:other rejects the field when
`other` is absent, which would make every max-only bound unsaveable."
```

Expected in the `--stat`: 7 files (4 criteria, 1 trait, the registry, the rule) plus the resolver test.

---

## Task 4: Validation test harness for the new filter keys

Writes the full validation spec up front, deliberately ahead of two of its criteria. This task ends **partially red on purpose** and is not committed — Task 6 turns it green and commits it. Written now so the ig/analytics rules in Tasks 5 and 6 are implemented against a test that already exists, rather than one written to fit whatever they happened to do.

**Files:**
- Create: `tests/Feature/Staff/SegmentFilterValidationTest.php`

**Interfaces:**
- Consumes: `StoreSegmentRequest::rules()`, `StoreSegmentRequest::stripUnknownSubKeys()` (Task 5), `App\Rules\MaxNotBelowMin` (Task 3 Step 0).

- [ ] **Step 1: Write the validation tests**

Create `tests/Feature/Staff/SegmentFilterValidationTest.php`:

```php
<?php

/**
 * Segment filter validation — the rules merged from SegmentCriteria, with
 * particular attention to max-only shapes, which a plain `gte` would reject.
 */

use App\Http\Requests\Api\Staff\Segments\StoreSegmentRequest;
use Illuminate\Support\Facades\Validator;

function ovaValidate(array $filters): \Illuminate\Validation\Validator
{
    return Validator::make(
        ['name' => 'Test segment', 'filters' => $filters],
        (new StoreSegmentRequest)->rules()
    );
}

it('accepts a max-only bound on every ranged criterion', function () {
    expect(ovaValidate(['tenure_days_max' => 90])->passes())->toBeTrue()
        ->and(ovaValidate(['ig_followers' => ['max' => 5000]])->passes())->toBeTrue()
        ->and(ovaValidate(['analytics' => ['metric' => 'visits', 'window_days' => 30, 'max' => 10]])->passes())->toBeTrue();
});

it('rejects a max below an explicit min', function () {
    expect(ovaValidate(['tenure_days_min' => 90, 'tenure_days_max' => 30])->passes())->toBeFalse()
        ->and(ovaValidate(['ig_followers' => ['min' => 5000, 'max' => 100]])->passes())->toBeFalse()
        ->and(ovaValidate(['analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 100, 'max' => 10]])->passes())->toBeFalse();
});

it('enforces the ISO alpha-2 shape on country_code', function () {
    expect(ovaValidate(['country_code' => ['AU', 'NZ']])->passes())->toBeTrue()
        ->and(ovaValidate(['country_code' => ['aus']])->passes())->toBeFalse()
        ->and(ovaValidate(['country_code' => ['au']])->passes())->toBeFalse();
});

it('requires at least one bound on the object criteria', function () {
    expect(ovaValidate(['ig_followers' => ['synced_within_days' => 30]])->passes())->toBeFalse()
        ->and(ovaValidate(['analytics' => ['metric' => 'visits', 'window_days' => 30]])->passes())->toBeFalse();
});

it('requires metric and window_days whenever analytics is present', function () {
    expect(ovaValidate(['analytics' => ['min' => 10]])->passes())->toBeFalse()
        ->and(ovaValidate(['analytics' => ['metric' => 'not_a_metric', 'window_days' => 30, 'min' => 10]])->passes())->toBeFalse()
        ->and(ovaValidate(['analytics' => ['metric' => 'visits', 'window_days' => 400, 'min' => 10]])->passes())->toBeFalse();
});

it('leaves the existing created-range pair accepting an open lower bound', function () {
    expect(ovaValidate(['created_to' => '2026-07-01'])->passes())->toBeTrue()
        ->and(ovaValidate(['created_from' => '2026-08-01', 'created_to' => '2026-07-01'])->passes())->toBeFalse();
});

it('strips unknown sub-keys from object criteria', function () {
    $cleaned = StoreSegmentRequest::stripUnknownSubKeys([
        'ig_followers' => ['min' => 100, 'nonsense' => 'x'],
        'analytics' => ['metric' => 'visits', 'window_days' => 7, 'min' => 1, 'bogus' => true],
        'country_code' => ['AU'],
    ]);

    expect($cleaned['ig_followers'])->toBe(['min' => 100])
        ->and($cleaned['analytics'])->toBe(['metric' => 'visits', 'window_days' => 7, 'min' => 1])
        ->and($cleaned['country_code'])->toBe(['AU']);
});
```

- [ ] **Step 2: Run the tests — a partial red is the expected outcome**

Run: `php artisan test tests/Feature/Staff/SegmentFilterValidationTest.php`

Expected: **2 passed, 5 failed.** Everything touching `ig_followers`, `analytics`, or `stripUnknownSubKeys` fails, because those criteria arrive in Tasks 5 and 6 — with no rules declared for a key, the validator accepts anything, so the "rejects…" assertions cannot yet fail as intended. **Do not implement ahead to make them green.**

- [ ] **Step 3: Confirm the two that must already pass**

Run: `php artisan test tests/Feature/Staff/SegmentFilterValidationTest.php --filter="ISO alpha-2|open lower bound"`

Expected: **2 passed.** These prove Task 3's country rules work and that the existing created-range pair still accepts an open lower bound — i.e. that `MaxNotBelowMin` did not get applied there by mistake.

**No commit in this task.** The file is committed in Task 6 Step 9 once every criterion it tests exists.

---

## Task 5: Instagram followers criterion

**Files:**
- Create: `app/Services/Segments/Criteria/IgFollowersCriterion.php`
- Create: `tests/Unit/Segments/IgFollowersExpressionTest.php`
- Modify: `app/Services/Segments/Criteria/SegmentCriteria.php`
- Modify: `app/Http/Requests/Api/Staff/Segments/StoreSegmentRequest.php`
- Test: `tests/Feature/Staff/SegmentResolverTest.php`

**Interfaces:**
- Produces: filter key `ig_followers` (object: `min`, `max`, `synced_within_days`); public static `IgFollowersCriterion::followersExpression(string $driver): string`; public static `StoreSegmentRequest::stripUnknownSubKeys(array $filters): array`.

> **The cast guard is the load-bearing part of this task.** `payload->>'followersCount'` is text; `::bigint` on non-numeric text throws in Postgres, and real payloads carry `int|string|null` (see `InstagramPayload::intStringOrNull`). The guard must be a `CASE WHEN <regex> THEN <cast> ELSE NULL END`, **not** `<regex> AND <cast>` — Postgres does not guarantee `AND` operand evaluation order, so a bare conjunction can still reach the cast. `CASE` is documented to short-circuit. `bigint` rather than `int` so an absurd scraped value cannot overflow. Missing / non-numeric → NULL → every comparison false → user excluded.

- [ ] **Step 1: Extend the platform_connections test mirror**

In `tests/Feature/Staff/SegmentResolverTest.php`, replace the inline `CREATE TABLE` in `beforeEach` with:

```php
    // has_integration and ig_followers read site.platform_connections via the
    // model relation. payload mirrors the real jsonb column (TEXT here).
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.platform_connections (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        platform TEXT NULL,
        payload TEXT NULL,
        is_active INTEGER NULL,
        last_refreshed_at TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
```

- [ ] **Step 2: Write the failing resolver tests**

Append to `tests/Feature/Staff/SegmentResolverTest.php`:

```php
function ovaSeedInstagram(string $userId, mixed $followers, array $overrides = []): void
{
    DB::connection('pgsql')->table('site.platform_connections')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'platform' => 'instagram',
        'payload' => json_encode(['followersCount' => $followers]),
        'is_active' => 1,
        'last_refreshed_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));
}

it('resolves ig_followers min and max bounds', function () {
    $small = ovaSeedUser();
    $mid = ovaSeedUser();
    $huge = ovaSeedUser();
    ovaSeedUser(); // no instagram connection at all

    ovaSeedInstagram($small, 500);
    ovaSeedInstagram($mid, 10000);
    ovaSeedInstagram($huge, 900000);

    $resolver = app(SegmentResolver::class);

    expect($resolver->userIds(ovaSegment(['ig_followers' => ['min' => 1000]])))
        ->toContain($mid)->toContain($huge)->toHaveCount(2)
        ->and($resolver->userIds(ovaSegment(['ig_followers' => ['max' => 1000]])))->toBe([$small])
        ->and($resolver->userIds(ovaSegment(['ig_followers' => ['min' => 1000, 'max' => 50000]])))->toBe([$mid]);
});

it('reads ig follower counts stored as numeric strings', function () {
    $stringy = ovaSeedUser();
    ovaSeedInstagram($stringy, '2500');

    expect(app(SegmentResolver::class)->userIds(ovaSegment(['ig_followers' => ['min' => 1000]])))->toBe([$stringy]);
});

it('excludes non-numeric and missing ig follower counts without erroring', function () {
    $garbage = ovaSeedUser();
    $nulled = ovaSeedUser();
    $absent = ovaSeedUser();
    $good = ovaSeedUser();

    ovaSeedInstagram($garbage, '1.2M');
    ovaSeedInstagram($nulled, null);
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $absent,
        'platform' => 'instagram',
        'payload' => json_encode(['username' => 'nofollowers']),
        'is_active' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    ovaSeedInstagram($good, 5000);

    expect(app(SegmentResolver::class)->userIds(ovaSegment(['ig_followers' => ['min' => 1]])))->toBe([$good]);
});

it('ignores inactive instagram connections for ig_followers', function () {
    $inactive = ovaSeedUser();
    ovaSeedInstagram($inactive, 9999, ['is_active' => 0]);

    expect(app(SegmentResolver::class)->userIds(ovaSegment(['ig_followers' => ['min' => 100]])))->toBeEmpty();
});

it('applies synced_within_days, falling back to created_at when never refreshed', function () {
    $freshConnect = ovaSeedUser();   // never refreshed, but connected today
    $staleConnect = ovaSeedUser();   // never refreshed, connected long ago
    $refreshed = ovaSeedUser();      // connected long ago, refreshed today

    ovaSeedInstagram($freshConnect, 5000);
    ovaSeedInstagram($staleConnect, 5000, [
        'created_at' => now()->subDays(200)->toDateTimeString(),
    ]);
    ovaSeedInstagram($refreshed, 5000, [
        'created_at' => now()->subDays(200)->toDateTimeString(),
        'last_refreshed_at' => now()->toDateTimeString(),
    ]);

    $ids = app(SegmentResolver::class)->userIds(ovaSegment([
        'ig_followers' => ['min' => 1000, 'synced_within_days' => 30],
    ]));

    expect($ids)->toContain($freshConnect)->toContain($refreshed)->toHaveCount(2);
});

it('treats an empty or all-null ig_followers object as inert', function () {
    ovaSeedInstagram(ovaSeedUser(), 5000);
    $manual = ovaSeedUser();

    $segment = ovaSegment(['ig_followers' => ['min' => null, 'max' => null]]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $manual]);

    expect(app(SegmentResolver::class)->userIds($segment))->toBe([$manual])
        ->and(app(SegmentResolver::class)->userIds(ovaSegment(['ig_followers' => []])))->toBeEmpty();
});

it('AND-combines ig_followers with an existing criterion', function () {
    $djBig = ovaSeedUser(['sector' => 'dj']);
    $hairBig = ovaSeedUser(['sector' => 'hairdresser']);
    ovaSeedInstagram($djBig, 20000);
    ovaSeedInstagram($hairBig, 20000);

    expect(app(SegmentResolver::class)->userIds(ovaSegment(['sector' => ['dj'], 'ig_followers' => ['min' => 1000]])))
        ->toBe([$djBig]);
});
```

- [ ] **Step 3: Write the Postgres SQL pinning test**

Create `tests/Unit/Segments/IgFollowersExpressionTest.php`:

```php
<?php

/**
 * Pins the Postgres shape of the follower-count expression. Tests run on
 * SQLite, so nothing else in the suite would catch a regression here until it
 * 500s on real Postgres.
 */

use App\Services\Segments\Criteria\IgFollowersCriterion;

it('guards the numeric cast with a digit regex on postgres', function () {
    $sql = IgFollowersCriterion::followersExpression('pgsql');

    expect($sql)->toContain("~ '^\\d+$'")
        ->and($sql)->toContain('::bigint')
        ->and($sql)->not->toContain('json_extract');

    // The guard MUST precede the cast — Postgres throws on ::bigint over
    // non-numeric text, and CASE is what makes the short-circuit guaranteed.
    expect(strpos($sql, "~ '^\\d+$'"))->toBeLessThan(strpos($sql, '::bigint'))
        ->and($sql)->toStartWith('CASE WHEN ');
});

it('uses json_extract with a digit GLOB guard on sqlite', function () {
    $sql = IgFollowersCriterion::followersExpression('sqlite');

    expect($sql)->toContain('json_extract')
        ->and($sql)->toContain("GLOB '[0-9]*'")
        ->and($sql)->toContain("NOT GLOB '*[^0-9]*'")
        ->and($sql)->not->toContain('::bigint');
});
```

- [ ] **Step 4: Run both test files to verify they fail**

Run: `php artisan test tests/Unit/Segments/IgFollowersExpressionTest.php tests/Feature/Staff/SegmentResolverTest.php`

Expected: unit tests FAIL with "Class IgFollowersCriterion not found"; the new resolver tests FAIL.

- [ ] **Step 5: Create IgFollowersCriterion**

```php
<?php

namespace App\Services\Segments\Criteria;

use App\Rules\MaxNotBelowMin;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Follower-count band over the synced Instagram connection payload.
 *
 * Matches only ACTIVE instagram rows in site.platform_connections, same
 * correlated-subquery pattern as HasIntegrationCriterion.
 */
final class IgFollowersCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['ig_followers'];
    }

    /**
     * SQL expression yielding the follower count as an integer, or NULL when
     * the payload value is missing or non-numeric.
     *
     * The digit guard MUST short-circuit the cast: Postgres throws on
     * ::bigint over non-numeric text, and payloads legitimately carry
     * int|string|null (InstagramPayload::intStringOrNull). A bare
     * `guard AND cast` is NOT safe — Postgres does not guarantee AND operand
     * evaluation order — so this uses CASE, which is documented to
     * short-circuit. bigint, not int, so a garbage-huge value cannot overflow.
     *
     * Pinned by tests/Unit/Segments/IgFollowersExpressionTest.php.
     */
    public static function followersExpression(string $driver): string
    {
        if ($driver === 'sqlite') {
            $json = "json_extract(payload, '\$.followersCount')";

            return "CASE WHEN {$json} GLOB '[0-9]*' AND {$json} NOT GLOB '*[^0-9]*' "
                ."THEN CAST({$json} AS INTEGER) ELSE NULL END";
        }

        return "CASE WHEN payload->>'followersCount' ~ '^\\d+\$' "
            ."THEN (payload->>'followersCount')::bigint ELSE NULL END";
    }

    public function rules(): array
    {
        return [
            'filters.ig_followers' => ['sometimes', 'nullable', 'array', $this->requiresABound()],
            'filters.ig_followers.min' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'filters.ig_followers.max' => ['sometimes', 'nullable', 'integer', 'min:0', new MaxNotBelowMin('filters.ig_followers.min')],
            'filters.ig_followers.synced_within_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    public function isActive(array $filters): bool
    {
        $config = $this->objectConfig($filters, 'ig_followers');

        // synced_within_days alone is a freshness check on nothing.
        return isset($config['min']) || isset($config['max']);
    }

    public function apply(Builder $query, array $filters): void
    {
        $config = $this->objectConfig($filters, 'ig_followers');
        $min = $config['min'] ?? null;
        $max = $config['max'] ?? null;
        $syncedWithin = $config['synced_within_days'] ?? null;

        $query->whereHas('integrationConnections', function ($q) use ($min, $max, $syncedWithin): void {
            $q->where('platform', 'instagram')->where('is_active', true);

            $followers = self::followersExpression($q->getConnection()->getDriverName());

            if ($min !== null) {
                $q->whereRaw("{$followers} >= ?", [(int) $min]);
            }

            if ($max !== null) {
                $q->whereRaw("{$followers} <= ?", [(int) $max]);
            }

            if ($syncedWithin !== null) {
                // A never-refreshed row falls back to created_at — a fresh
                // connect is fresh data.
                $q->whereRaw('COALESCE(last_refreshed_at, created_at) >= ?', [
                    now()->subDays((int) $syncedWithin)->toDateTimeString(),
                ]);
            }
        });
    }

    /** At least one of min/max, which no structural rule can express. */
    private function requiresABound(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            if (($value['min'] ?? null) === null && ($value['max'] ?? null) === null) {
                $fail('ig_followers requires at least one of min or max.');
            }
        };
    }
}
```

- [ ] **Step 6: Register the criterion**

In `SegmentCriteria::all()`, append after `new TenureCriterion(),`:

```php
            new IgFollowersCriterion(),
```

- [ ] **Step 7: Add unknown-sub-key stripping to the request**

In `app/Http/Requests/Api/Staff/Segments/StoreSegmentRequest.php`, add these two methods to the class:

```php
    /**
     * Object criteria accept a fixed set of sub-keys; anything else is dropped
     * rather than rejected, mirroring how the engine ignores unknown top-level
     * filter keys.
     */
    private const OBJECT_SUB_KEYS = [
        'ig_followers' => ['min', 'max', 'synced_within_days'],
        'analytics' => ['metric', 'window_days', 'min', 'max'],
    ];

    /** @return array<string, mixed> */
    public static function stripUnknownSubKeys(array $filters): array
    {
        foreach (self::OBJECT_SUB_KEYS as $key => $allowed) {
            if (isset($filters[$key]) && is_array($filters[$key])) {
                $filters[$key] = array_intersect_key($filters[$key], array_flip($allowed));
            }
        }

        return $filters;
    }

    protected function prepareForValidation(): void
    {
        $filters = $this->input('filters');

        if (is_array($filters)) {
            $this->merge(['filters' => self::stripUnknownSubKeys($filters)]);
        }
    }
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/Segments/IgFollowersExpressionTest.php tests/Feature/Staff/SegmentResolverTest.php`

Expected: 2 unit passed; **19 passed** in the resolver file (6 original + 6 location/tenure + 7 IG).

- [ ] **Step 9: Run the full suite and commit (COMMIT 3 of 5 — spec sequence step 2)**

```bash
composer test
php artisan pint --dirty
git add app/Services/Segments/Criteria/IgFollowersCriterion.php app/Services/Segments/Criteria/SegmentCriteria.php app/Http/Requests/Api/Staff/Segments/StoreSegmentRequest.php tests/Unit/Segments/IgFollowersExpressionTest.php tests/Feature/Staff/SegmentResolverTest.php
git commit -m "feat(segments): instagram follower-count criterion

Bands on payload->>'followersCount' over active instagram connections, with an
optional synced_within_days freshness window that falls back to created_at.

The digit guard uses CASE rather than a bare AND: Postgres does not guarantee
AND operand evaluation order, so a conjunction can still reach ::bigint on
non-numeric text and throw. Pinned by a unit test because the suite runs on
SQLite and would not otherwise catch a regression."
```

---

## Task 6: Analytics criterion

**Files:**
- Create: `app/Services/Segments/Criteria/AnalyticsCriterion.php`
- Modify: `app/Services/Segments/Criteria/SegmentCriteria.php`
- Test: `tests/Feature/Staff/SegmentResolverTest.php`, `tests/Feature/Staff/SegmentFilterValidationTest.php`

**Interfaces:**
- Produces: filter key `analytics` (object: `metric`, `window_days`, `min`, `max`).

> **Read Verified Spec Deltas D1–D4 before starting.** This criterion reads `analytics.site_visits` and `analytics.link_clicks` — NOT `site_metrics_daily`, which has no writer and zero rows. The correlating column is `user_id`, not `professional_id`. The subquery needs `GROUP BY` or SQLite rejects the `HAVING`.

> **Zero-row semantics are the point of the two compile shapes, and the max-only case is the one most likely to regress.** A user with no events in the window is *excluded* when `min` is set (no group → `EXISTS` false) and *included* when only `max` is set (no group → `NOT EXISTS` true). That is what makes "target low-traffic users" work without special-casing. Both shapes were verified to behave identically on Postgres and SQLite.

> **`metric` is never interpolated from input.** It is a key into a hardcoded map; the map's values supply the table and aggregate SQL. That allowlist is the injection boundary.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Staff/SegmentResolverTest.php`:

```php
function ovaSeedVisit(string $userId, string $visitorId, int $daysAgo): void
{
    DB::connection('pgsql')->table('analytics.site_visits')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'visitor_id' => $visitorId,
        'occurred_at' => now()->subDays($daysAgo)->toDateTimeString(),
        'created_at' => now()->subDays($daysAgo)->toDateTimeString(),
    ]);
}

function ovaSeedClick(string $userId, string $visitorId, int $daysAgo): void
{
    DB::connection('pgsql')->table('analytics.link_clicks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'visitor_id' => $visitorId,
        'occurred_at' => now()->subDays($daysAgo)->toDateTimeString(),
        'created_at' => now()->subDays($daysAgo)->toDateTimeString(),
    ]);
}

it('resolves an analytics visits minimum over the window', function () {
    $busy = ovaSeedUser();
    $quiet = ovaSeedUser();
    ovaSeedUser(); // zero rows

    foreach (range(1, 5) as $i) {
        ovaSeedVisit($busy, "v{$i}", 3);
    }
    ovaSeedVisit($quiet, 'v1', 3);

    expect(app(SegmentResolver::class)->userIds(ovaSegment([
        'analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 3],
    ])))->toBe([$busy]);
});

it('ignores analytics events outside the window', function () {
    $lapsed = ovaSeedUser();

    foreach (range(1, 5) as $i) {
        ovaSeedVisit($lapsed, "v{$i}", 60); // older than the 30-day window
    }

    expect(app(SegmentResolver::class)->userIds(ovaSegment([
        'analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 1],
    ])))->toBeEmpty();
});

it('counts unique_visitors distinctly from raw visits', function () {
    $repeat = ovaSeedUser();

    // 4 visits, but only 2 distinct visitors.
    ovaSeedVisit($repeat, 'v1', 2);
    ovaSeedVisit($repeat, 'v1', 3);
    ovaSeedVisit($repeat, 'v2', 4);
    ovaSeedVisit($repeat, 'v2', 5);

    $resolver = app(SegmentResolver::class);

    expect($resolver->userIds(ovaSegment(['analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 4]])))->toBe([$repeat])
        ->and($resolver->userIds(ovaSegment(['analytics' => ['metric' => 'unique_visitors', 'window_days' => 30, 'min' => 4]])))->toBeEmpty()
        ->and($resolver->userIds(ovaSegment(['analytics' => ['metric' => 'unique_visitors', 'window_days' => 30, 'min' => 2]])))->toBe([$repeat]);
});

it('resolves click metrics from the link_clicks table', function () {
    $clicker = ovaSeedUser();
    $visitorOnly = ovaSeedUser();

    ovaSeedClick($clicker, 'v1', 2);
    ovaSeedClick($clicker, 'v2', 3);
    ovaSeedVisit($visitorOnly, 'v9', 2);

    $resolver = app(SegmentResolver::class);

    expect($resolver->userIds(ovaSegment(['analytics' => ['metric' => 'clicks', 'window_days' => 30, 'min' => 2]])))->toBe([$clicker])
        ->and($resolver->userIds(ovaSegment(['analytics' => ['metric' => 'unique_clickers', 'window_days' => 30, 'min' => 2]])))->toBe([$clicker]);
});

it('INCLUDES zero-row users under a max-only analytics filter', function () {
    $busy = ovaSeedUser();
    $quiet = ovaSeedUser();
    $silent = ovaSeedUser(); // no analytics rows whatsoever

    foreach (range(1, 10) as $i) {
        ovaSeedVisit($busy, "v{$i}", 3);
    }
    ovaSeedVisit($quiet, 'v1', 3);

    // "low traffic" must mean quiet AND silent — a user with no rows has 0
    // visits, which is <= the max. This is the semantic most likely to regress.
    $ids = app(SegmentResolver::class)->userIds(ovaSegment([
        'analytics' => ['metric' => 'visits', 'window_days' => 30, 'max' => 5],
    ]));

    expect($ids)->toContain($quiet)->toContain($silent)->not->toContain($busy)->toHaveCount(2);
});

it('EXCLUDES zero-row users when a min is set', function () {
    $silent = ovaSeedUser();
    $busy = ovaSeedUser();
    ovaSeedVisit($busy, 'v1', 3);

    expect(app(SegmentResolver::class)->userIds(ovaSegment([
        'analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 1],
    ])))->toBe([$busy])->not->toContain($silent);
});

it('applies both analytics bounds as a band', function () {
    $low = ovaSeedUser();
    $mid = ovaSeedUser();
    $high = ovaSeedUser();

    ovaSeedVisit($low, 'v1', 2);
    foreach (range(1, 5) as $i) {
        ovaSeedVisit($mid, "v{$i}", 2);
    }
    foreach (range(1, 20) as $i) {
        ovaSeedVisit($high, "v{$i}", 2);
    }

    expect(app(SegmentResolver::class)->userIds(ovaSegment([
        'analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 3, 'max' => 10],
    ])))->toBe([$mid]);
});

it('treats an all-null analytics object as inert', function () {
    $busy = ovaSeedUser();
    ovaSeedVisit($busy, 'v1', 2);
    $manual = ovaSeedUser();

    $segment = ovaSegment(['analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => null, 'max' => null]]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $manual]);

    expect(app(SegmentResolver::class)->userIds($segment))->toBe([$manual]);
});

it('AND-combines analytics with an existing criterion', function () {
    $djBusy = ovaSeedUser(['sector' => 'dj']);
    $hairBusy = ovaSeedUser(['sector' => 'hairdresser']);

    foreach (range(1, 5) as $i) {
        ovaSeedVisit($djBusy, "v{$i}", 2);
        ovaSeedVisit($hairBusy, "v{$i}", 2);
    }

    expect(app(SegmentResolver::class)->userIds(ovaSegment([
        'sector' => ['dj'],
        'analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 3],
    ])))->toBe([$djBusy]);
});
```

Also add the two analytics table mirrors to the `beforeEach` in that file — both helpers already exist in `tests/Pest.php`:

```php
    setupSiteVisitsTable();
    setupLinkClicksTable();
```

and add these to the existing `DELETE FROM` block:

```php
    DB::connection('pgsql')->statement('DELETE FROM analytics.site_visits');
    DB::connection('pgsql')->statement('DELETE FROM analytics.link_clicks');
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Staff/SegmentResolverTest.php`

Expected: the 9 new tests FAIL (the `analytics` key is unknown, so it is ignored and every seeded user matches).

- [ ] **Step 3: Create AnalyticsCriterion**

```php
<?php

namespace App\Services\Segments\Criteria;

use App\Rules\MaxNotBelowMin;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Visit/click volume over a lookback window, as a correlated subquery against
 * the raw analytics event tables.
 *
 * Source note: analytics.site_metrics_daily would be the natural home for this
 * but is vestigial — it has no writer and zero rows. The raw event tables are
 * where the data actually lands, and both carry a (user_id, occurred_at DESC)
 * index that serves this lookup. Raw events are purged at 90 days, which is
 * why window_days is capped there rather than at a year.
 */
final class AnalyticsCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    /**
     * metric → table + aggregate. This allowlist is the SQL-injection
     * boundary: the incoming `metric` string is only ever used as a key into
     * this map, never interpolated into SQL.
     */
    private const METRICS = [
        'visits' => ['table' => 'analytics.site_visits', 'aggregate' => 'COUNT(*)'],
        'unique_visitors' => ['table' => 'analytics.site_visits', 'aggregate' => 'COUNT(DISTINCT m.visitor_id)'],
        'clicks' => ['table' => 'analytics.link_clicks', 'aggregate' => 'COUNT(*)'],
        'unique_clickers' => ['table' => 'analytics.link_clicks', 'aggregate' => 'COUNT(DISTINCT m.visitor_id)'],
    ];

    public function keys(): array
    {
        return ['analytics'];
    }

    public function rules(): array
    {
        return [
            'filters.analytics' => ['sometimes', 'nullable', 'array', $this->requiresABound()],
            'filters.analytics.metric' => ['required_with:filters.analytics', Rule::in(array_keys(self::METRICS))],
            'filters.analytics.window_days' => ['required_with:filters.analytics', 'integer', 'min:1', 'max:90'],
            'filters.analytics.min' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'filters.analytics.max' => ['sometimes', 'nullable', 'integer', 'min:0', new MaxNotBelowMin('filters.analytics.min')],
        ];
    }

    public function isActive(array $filters): bool
    {
        $config = $this->objectConfig($filters, 'analytics');

        return isset(self::METRICS[$config['metric'] ?? ''])
            && isset($config['window_days'])
            && (isset($config['min']) || isset($config['max']));
    }

    public function apply(Builder $query, array $filters): void
    {
        $config = $this->objectConfig($filters, 'analytics');

        $metric = self::METRICS[$config['metric']];
        $table = $metric['table'];
        $aggregate = $metric['aggregate'];

        $min = $config['min'] ?? null;
        $max = $config['max'] ?? null;

        // Cutoff computed in PHP and bound — no SQL date arithmetic, so the
        // predicate is identical on Postgres and SQLite.
        $cutoff = now()->subDays((int) $config['window_days'])->startOfDay()->toDateTimeString();

        // GROUP BY is required: Postgres tolerates a bare HAVING, SQLite
        // rejects it ("HAVING clause on a non-aggregate query"). Grouping by
        // the correlating column yields exactly one group, or none at all when
        // the user has no rows in the window — which is what produces the
        // zero-row semantics below.
        $inner = "SELECT 1 FROM {$table} m"
            .' WHERE m.user_id = core.users.id AND m.occurred_at >= ?'
            .' GROUP BY m.user_id HAVING ';

        if ($min !== null) {
            // No rows → no group → EXISTS false → excluded.
            $having = "{$aggregate} >= ?";
            $bindings = [$cutoff, (int) $min];

            if ($max !== null) {
                $having .= " AND {$aggregate} <= ?";
                $bindings[] = (int) $max;
            }

            $query->whereRaw("EXISTS ({$inner}{$having})", $bindings);

            return;
        }

        // max-only: no rows → no group → NOT EXISTS true → INCLUDED. A user
        // with no events has 0, which is <= max. Deliberate — this is what
        // makes "target low-traffic users" work.
        $query->whereRaw("NOT EXISTS ({$inner}{$aggregate} > ?)", [$cutoff, (int) $max]);
    }

    /** At least one of min/max, which no structural rule can express. */
    private function requiresABound(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            if (($value['min'] ?? null) === null && ($value['max'] ?? null) === null) {
                $fail('analytics requires at least one of min or max.');
            }
        };
    }
}
```

- [ ] **Step 4: Register the criterion**

In `SegmentCriteria::all()`, append after `new IgFollowersCriterion(),`:

```php
            new AnalyticsCriterion(),
```

- [ ] **Step 5: Run the resolver tests to verify they pass**

Run: `php artisan test tests/Feature/Staff/SegmentResolverTest.php`

Expected: **28 passed** (6 original + 6 location/tenure + 7 IG + 9 analytics).

- [ ] **Step 6: Run the validation suite — now fully green**

Run: `php artisan test tests/Feature/Staff/SegmentFilterValidationTest.php`

Expected: **7 passed.** Every test written in Task 4 now has its criterion.

- [ ] **Step 7: Add the registry coverage guard**

Append to `tests/Feature/Staff/SegmentFilterValidationTest.php`:

```php
it('exposes validation rules for every key the registry claims to own', function () {
    $rules = (new StoreSegmentRequest)->rules();

    foreach (\App\Services\Segments\Criteria\SegmentCriteria::all() as $criterion) {
        foreach ($criterion->keys() as $key) {
            expect($rules)->toHaveKey("filters.{$key}",
                sprintf('%s claims key "%s" but declares no rule for it.', $criterion::class, $key));
        }
    }
});

it('registers every criterion class that exists on disk', function () {
    $onDisk = collect(glob(app_path('Services/Segments/Criteria/*Criterion.php')))
        ->map(fn (string $path) => 'App\\Services\\Segments\\Criteria\\'.basename($path, '.php'))
        ->reject(fn (string $class) => $class === \App\Services\Segments\Criteria\SegmentCriterion::class)
        ->sort()->values()->all();

    $registered = collect(\App\Services\Segments\Criteria\SegmentCriteria::all())
        ->map(fn ($c) => $c::class)->sort()->values()->all();

    // A criterion added to the folder but forgotten in the registry is silently
    // inert — every segment using its key would match everyone.
    expect($registered)->toBe($onDisk);
});
```

- [ ] **Step 8: Run the validation suite again**

Run: `php artisan test tests/Feature/Staff/SegmentFilterValidationTest.php`

Expected: **9 passed.**

- [ ] **Step 9: Run the full suite and commit (COMMIT 4 of 5 — spec sequence step 3)**

```bash
composer test
php artisan pint --dirty
git add app/Services/Segments/Criteria tests/Feature/Staff/SegmentResolverTest.php tests/Feature/Staff/SegmentFilterValidationTest.php
git commit -m "feat(segments): analytics volume criterion

Visit/click thresholds over a lookback window, via correlated subqueries on
analytics.site_visits and analytics.link_clicks. Reads the raw event tables
rather than site_metrics_daily, which is vestigial (no writer, zero rows);
window_days is capped at 90 to match raw-event retention.

Zero-row users are excluded under a min and included under a max-only filter,
which is what makes low-traffic targeting work. GROUP BY is load-bearing:
SQLite rejects a bare HAVING that Postgres accepts."
```

---

## Task 7: Document the segment filter keys

The staff segment endpoints are currently absent from `docs/api.md` entirely — this adds them along with the full filter reference.

**Files:**
- Modify: `docs/api.md`

- [ ] **Step 1: Add the segments subsection**

In `docs/api.md`, immediately after the line `- POST /api/staff/notifications` (end of the staff-admin route list, before `## 9) Media uploads & processing`), insert:

````markdown
### Segments (OV-A)

Staff-defined user segments — a dynamic filter definition plus an optional manual member list. Consumed by the feature kill-switch, staff notifications, and any staff tooling that resolves a user set.

Read side (staff):
- GET /api/staff/segments
- GET /api/staff/segments/{segment}
- GET /api/staff/segments/{segment}/users

Write side (staff-admin):
- POST /api/staff/segments
- PATCH /api/staff/segments/{segment}
- DELETE /api/staff/segments/{segment}
- POST /api/staff/segments/{segment}/members
- DELETE /api/staff/segments/{segment}/members

#### `filters` definition

All keys are optional and AND-combine. A missing or null key is unconstrained. **A definition with zero active criteria resolves to an EMPTY dynamic set** (manual members only) — `{}` never means "all users". Soft-deleted users are always excluded from dynamic results.

| Key | Type | Meaning |
|---|---|---|
| `account_type` | `"partna" \| "business"` | Exact account type match |
| `sector` | `string[]` | Any of the given sector slugs |
| `created_from` / `created_to` | `YYYY-MM-DD` | Absolute signup-date window |
| `has_integration` | `true \| "<platform>"` | Any active connection, or one platform |
| `early_access` | `boolean` | In (or, when `false`, not in) the early-access programme |
| `country_code` | `string[]` | ISO alpha-2, uppercase — e.g. `["AU","NZ"]` |
| `location_state` | `string[]` | Free-text state, case-insensitive exact match |
| `location_city` | `string[]` | Free-text city, case-insensitive exact match |
| `tenure_days_min` | `int` 0–3650 | On Partna at least N days |
| `tenure_days_max` | `int` 0–3650 | On Partna at most N days |
| `ig_followers` | object | Instagram follower band — see below |
| `analytics` | object | Visit/click volume — see below |
| `include_manual_members` | `boolean` (default `true`) | Structural, not a filter |

```jsonc
"ig_followers": {
  "min": 1000,               // optional; at least one of min/max required
  "max": 50000,              // optional
  "synced_within_days": 30   // optional freshness window on the connection
}
```

Reads `followersCount` from the synced Instagram connection payload, matching **active** `instagram` connections only. A missing or non-numeric follower count excludes the user (it never errors). `synced_within_days` measures from `last_refreshed_at`, falling back to `created_at` when the connection has never been refreshed.

```jsonc
"analytics": {
  "metric": "visits",        // visits | unique_visitors | clicks | unique_clickers
  "window_days": 30,         // 1-90 (raw analytics events are purged at 90 days)
  "min": 100,                // optional
  "max": null                // optional; at least one of min/max required
}
```

Thresholds on the total over the lookback window. **Zero-activity users are excluded when `min` is set and included when only `max` is set** — a user with no events has 0, which is at or below any max. That is what makes `max`-only a usable "low-traffic users" filter.

Free-text `location_state` / `location_city` matching is best-effort: users who left the field blank never match.
````

- [ ] **Step 2: Verify the document still renders**

Run: `grep -n "### Segments (OV-A)" docs/api.md`

Expected: one hit, positioned before the `## 9) Media uploads & processing` heading. Sanity-check the fenced blocks are balanced:

Run: `awk '/^```/{n++} END{print n" fence markers - must be even"}' docs/api.md`

- [ ] **Step 3: Commit (COMMIT 5 of 5 — docs)**

```bash
git add docs/api.md
git commit -m "docs(api): document staff segment endpoints and filter keys"
```

---

## Task 8: Real-Postgres verification

**No commit.** Tests run on SQLite; production is Postgres, and the two schemas are known to drift. This task proves each criterion compiles and executes against the real database before the work is handed over.

Target: **dev Supabase, project ref `glncumufgaqcmqhzwrxm`** — which per `CLAUDE.md` currently backs both `dev-api.partna.au` and `api.partna.au`. Read-only queries only; create no segments.

> Expect small numbers. Dev holds ~21 live users, 10 of whom have any analytics rows. A criterion returning 0 is only a failure if the criterion should obviously have matched — the signal you are hunting is **cast errors, syntax errors, and unknown-column errors**, not row counts.

- [ ] **Step 1: Verify the location and tenure criteria**

Run each and record the count:

```bash
php artisan tinker --execute='
use App\Models\Core\Segments\UserSegment;
use App\Services\Segments\SegmentResolver;
$r = app(SegmentResolver::class);
foreach ([
  "country_code"     => ["country_code" => ["AU"]],
  "location_state"   => ["location_state" => ["NSW", "Victoria"]],
  "location_city"    => ["location_city" => ["Sydney"]],
  "tenure_min"       => ["tenure_days_min" => 30],
  "tenure_max"       => ["tenure_days_max" => 90],
  "tenure_band"      => ["tenure_days_min" => 30, "tenure_days_max" => 90],
] as $label => $filters) {
    $s = new UserSegment(["name" => "probe", "filters" => $filters]);
    printf("%-16s => %d users\n", $label, $r->count($s));
}
'
```

Expected: six counts, no exceptions. **This must run against the real Postgres connection, not the test SQLite** — run it from the project root with the normal `.env`, and confirm by checking the first line of output is not a connection error.

- [ ] **Step 2: Verify the IG followers criterion — the cast-error probe**

```bash
php artisan tinker --execute='
use App\Models\Core\Segments\UserSegment;
use App\Services\Segments\SegmentResolver;
$r = app(SegmentResolver::class);
foreach ([
  "min only"    => ["ig_followers" => ["min" => 1]],
  "max only"    => ["ig_followers" => ["max" => 1000000]],
  "band"        => ["ig_followers" => ["min" => 1000, "max" => 50000]],
  "with fresh"  => ["ig_followers" => ["min" => 1, "synced_within_days" => 365]],
] as $label => $filters) {
    $s = new UserSegment(["name" => "probe", "filters" => $filters]);
    printf("%-12s => %d users\n", $label, $r->count($s));
}
'
```

Expected: four counts, and specifically **no `invalid input syntax for type bigint`**. That error means the CASE guard is not short-circuiting — stop and fix before proceeding.

- [ ] **Step 3: Verify all four analytics metrics**

```bash
php artisan tinker --execute='
use App\Models\Core\Segments\UserSegment;
use App\Services\Segments\SegmentResolver;
$r = app(SegmentResolver::class);
foreach (["visits", "unique_visitors", "clicks", "unique_clickers"] as $metric) {
    foreach ([["min" => 1], ["max" => 5]] as $bound) {
        $filters = ["analytics" => array_merge(["metric" => $metric, "window_days" => 30], $bound)];
        $s = new UserSegment(["name" => "probe", "filters" => $filters]);
        printf("%-16s %-9s => %d users\n", $metric, json_encode($bound), $r->count($s));
    }
}
'
```

Expected: eight counts, no exceptions. Sanity checks on the numbers:
- `visits` / `min: 1` should be **around 10** (the number of dev users with any visit rows) — if it is 0, the `user_id` correlation is wrong.
- `max: 5` counts should be **larger** than the matching `min: 1` counts, because every zero-activity user is included. If a max-only count is 0 or equals the min-only count, the `NOT EXISTS` zero-row semantic is broken.

- [ ] **Step 4: Verify a composed multi-criterion segment**

```bash
php artisan tinker --execute='
use App\Models\Core\Segments\UserSegment;
use App\Services\Segments\SegmentResolver;
$s = new UserSegment(["name" => "probe", "filters" => [
    "country_code" => ["AU"],
    "tenure_days_min" => 7,
    "analytics" => ["metric" => "visits", "window_days" => 30, "min" => 1],
]]);
$r = app(SegmentResolver::class);
echo $r->dynamicQuery($s)->toSql(), PHP_EOL, PHP_EOL;
echo "count: ", $r->count($s), PHP_EOL;
'
```

Expected: one SQL string containing all three predicates AND-combined, then a count with no exception. Read the SQL — confirm it says `m.user_id`, not `m.professional_id`, and that the analytics subquery contains `GROUP BY`.

- [ ] **Step 5: Final full-suite run and handover**

```bash
composer test
git log --oneline origin/development..HEAD
git status
```

Expected: green suite, clean working tree, and exactly **5 commits** ahead of `origin/development`:

1. `refactor(segments): move filter keys into a criterion registry`
2. `feat(segments): location and tenure filter criteria`
3. `feat(segments): instagram follower-count criterion`
4. `feat(segments): analytics volume criterion`
5. `docs(api): document staff segment endpoints and filter keys`

If you have a different count, something was committed twice or left uncommitted — reconcile before reporting.

**Stop here.** Report to Josh: the commit list, the per-criterion Postgres counts from Steps 1–3, and anything surprising. Do not push, do not open a PR, do not merge.

---

## Appendix: Verification Command Reference

| Purpose | Command |
|---|---|
| Resolver tests only | `php artisan test tests/Feature/Staff/SegmentResolverTest.php` |
| Validation tests only | `php artisan test tests/Feature/Staff/SegmentFilterValidationTest.php` |
| SQL pinning unit test | `php artisan test tests/Unit/Segments/IgFollowersExpressionTest.php` |
| All segment-adjacent suites | `php artisan test tests/Feature/Staff/` |
| Full suite | `composer test` |
| Style | `php artisan pint --dirty` |
| Confirm a test file is unedited | `git diff --stat <path>` (must print nothing) |

**Expected test counts by task:**

| After task | `SegmentResolverTest.php` | `SegmentFilterValidationTest.php` | `IgFollowersExpressionTest.php` |
|---|---|---|---|
| 2 (registry) | 6 — **unchanged file** | — | — |
| 3 (location/tenure + rule) | 12 | — | — |
| 4 (validation harness) | 12 | 2 passed, 5 failed — **expected red, uncommitted** | — |
| 5 (IG followers) | 19 | still partially red | 2 |
| 6 (analytics) | 28 | 9 | 2 |

If a count comes in *higher* than the table says, you added a test the plan did not ask for — fine, but re-read the task to be sure you did not also change behavior. If a count comes in *lower*, a test was silently skipped: check for a `->skip()` or a `--filter` left on the command.
