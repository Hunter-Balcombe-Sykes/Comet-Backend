# Setup Payload Pool Batching Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the setup dialog resolve every content pool in ONE shared hydrate instead of seven independent ones, and make `POST /api/site/setup/accept` build only the pass it actually returns.

**Architecture:** `SetupPayload::for()` composes 15 passes. Seven of them call `PoolResolver::resolve()`, and `resolve()` is documented as costing ~20 facet queries per pool — the exact N+1 that `PoolWire::forSite` already fixed for the public lane in 2026-08-24 by splitting `resolve()` into `plan → hydrate → assemble` and sharing the hydrate. This plan applies that existing seam to `SetupPayload` (Task 1), then adds a single-pass entry point so the accept response stops rebuilding all 15 passes to return one (Task 2), then drops a dashboard-only join the setup lane never reads (Task 3).

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, PostgreSQL (Supabase). Tests run SQLite in-memory.

**Spec:** No separate spec document. This plan is the record; it derives from a Nightwatch trace of `POST /api/site/setup/accept` at 3.17s on 2026-09-04 08:04:15 UTC and the measurements in `PoolWire::forSite`'s and `PoolResolver::assemble`'s docblocks.

## Global Constraints

- **No Laravel migration files.** This plan adds no schema changes at all. If you think you need one, stop — you have misread a task.
- **Comment for WHY, not what.** 4-space indent, LF. Brief docblocks on public methods.
- **Run `php artisan pint` before committing.** The gate is `pint --test`, not `pint`.
- **`composer test` before declaring a task done.** Note `--filter` is broken in this repo — run the whole file by path.
- **Branch off `development`**, never commit to it directly. Branch name: `perf/setup-payload-pool-batching-2026-09-04`.
- **Byte-identical wire output is the acceptance bar for Tasks 1 and 2.** Both are pure performance changes. If any task changes what `GET /api/site/setup` returns, you have made a mistake, not an improvement.
- **Every commit message ends with:**
  ```
  Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01FSZo1gax3gCURhQzpFVBDV
  ```

## Background you need before touching anything

`PoolResolver::resolve(Site $site, string $pool): array` (`app/Site/Pools/PoolResolver.php:208`) is three steps glued together:

```php
$plan = $this->plan($site, $pool);                        // which item ids — cheap
[$payloads, $stores] = $this->itemPayloads($site, $ids, true);  // ~20 facet queries — EXPENSIVE
return $this->assemble($site, $pool, $plan, $payloads, $stores);
```

All three are already exposed for batching, because `PoolWire` needed exactly this:

| Method | Signature | Note |
|---|---|---|
| `preloadSections` | `(Site $site, array $pools): array` | keyed by pool; wraps `provisioner->ensureMany` |
| `preloadCuration` | `(array $sections): array` | keyed by **section id**, one `whereIn` |
| `plan` | `(Site $site, string $pool, ?object $section = null, ?Collection $curation = null): array` | pass the preloads in to skip its own two round trips |
| `hydrateItems` | `(Site $site, array $ids, bool $withDuplicateCandidates = false): array` | returns `[$payloads, $stores]` |
| `assemble` | `(Site $site, string $pool, array $plan, array $payloads, Collection $stores, bool $withLibrary = true): array` | only reads `$payloads` entries for its own plan's ids, so a superset map is fine |

**Two differences from `PoolWire`, and getting either wrong silently empties the dialog:**

1. `PoolWire` hydrates `selectionIds` only and passes `withLibrary: false`. **`SetupPayload` reads `$resolved['library']`**, so it MUST union `selectionIds` **and** `libraryIds` into the hydrate, and MUST pass `withLibrary: true`.
2. `resolve()` passes `withDuplicateCandidates: true`; `hydrateItems` defaults to `false`. Task 1 passes `true` to stay byte-identical. Task 3 flips it deliberately, with its own test.

**The side effect that makes Task 2 risky:** `plan()` calls `$this->provisioner->ensure($site, $pool)` when no section is passed (`:249`), and `hasSelection()` does the same at `:122`. Building all 15 passes therefore **provisions a section row for every pool**. Building one pass would stop provisioning the other six. Task 2 preserves this by calling `preloadSections` over the full pool set even when composing a single pass — that is one batched query, and it is not optional.

## The seven pools

`SetupPassRegistry::keysFor($user)` returns 15 keys. The pool-resolving ones, per account branch:

| Pass key | Pool | Call site today |
|---|---|---|
| `items.watch` | `watch` | `SetupPayload.php:110` |
| `items.listen` | `listen` | `SetupPayload.php:110` |
| `items.events` | `events` | `SetupPayload.php:110` |
| `items.shop` | `shop` | `SetupPayload.php:110` |
| `media` | `media` | `SetupPayload.php:127` |
| `links` | `custom_links` | `SetupPayload.php:127` |
| `services` | `services` | `SetupPayload.php:585` (inside `servicesPass`) |

A business account with `can_use_menu` swaps `services` for `menu`; `menuPass()` uses `MenuPayloadComposer`, **not** `PoolResolver`, so that branch resolves six pools, not seven.

---

## File Structure

- `app/Services/Setup/SetupPayload.php` — gains a private `poolsFor()` + `resolveAllPools()` batching prelude, a public `forPass()`, and loses its three `$this->pools->resolve()` call sites. This file is 646 lines and already single-responsibility (it composes one wire payload); no split.
- `app/Http/Controllers/Api/User/Setup/SetupController.php` — `accept()` swaps a full `for()` + `firstWhere` for one `forPass()` call.
- `tests/Feature/Setup/SetupPoolBatchingTest.php` — new; the query-shape and equivalence tests. Kept out of `SetupControllerTest.php` (315 lines, already covers wire semantics) because these tests assert *cost*, not *content*, and want their own `beforeEach`.

---

### Task 1: Batch `SetupPayload::for()` through the shared hydrate

**Files:**
- Modify: `app/Services/Setup/SetupPayload.php:47-145` (`for`, `composePass`), `:577-600` (`servicesPass`)
- Test: `tests/Feature/Setup/SetupPoolBatchingTest.php` (create)

**Interfaces:**
- Consumes: `PoolResolver::preloadSections`, `preloadCuration`, `plan`, `hydrateItems`, `assemble` — signatures in the table above.
- Produces:
  - `SetupPayload::poolsFor(User $user): list<string>` (private) — the pool names the user's pass list will need.
  - `SetupPayload::resolveAllPools(Site $site, array $pools): array<string, array>` (private) — pool name → the same array shape `resolve()` returns. Task 2 calls this with a one-element `$pools`.
  - `composePass()` and `servicesPass()` gain a `array $resolvedPools` parameter and stop calling `$this->pools->resolve()`.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Setup/SetupPoolBatchingTest.php`:

```php
<?php

use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// The setup dialog resolves seven content pools. Resolving them one at a
// time paid PoolResolver's per-pool pre-reads seven times over; PoolWire
// solved the same N+1 for the public lane in 2026-08-24. These tests pin
// the batched shape by COUNTING the pre-reads, because the wire output is
// identical either way and so proves nothing on its own.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
    setupIngestTables();
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
    AccountCapabilities::flushCache();
    Queue::fake();
});

/** Every SELECT this request issued against site.section_items. */
function setupCurationSelects(callable $run): array
{
    $seen = [];
    DB::listen(function ($query) use (&$seen) {
        $sql = $query->sql;
        if (str_contains($sql, 'section_items') && str_starts_with(trim(strtolower($sql)), 'select')) {
            $seen[] = $sql;
        }
    });

    $run();

    return $seen;
}

it('reads every pool\'s curation in one query, not one per pool', function () {
    $pro = createTenant('setup-batch');

    $selects = setupCurationSelects(function () use ($pro) {
        actingAsUser($pro)->getJson('/api/site/setup')->assertOk();
    });

    // One whereIn over every section, via PoolResolver::preloadCuration.
    // Before batching this was seven separate section-scoped selects.
    expect($selects)->toHaveCount(1);
});
```

- [x] **Step 2: Run the test and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Setup/SetupPoolBatchingTest.php`

Expected: FAIL — `expect(7)->toHaveCount(1)` (six or seven selects, depending on account branch). If it reports `1` before you have changed anything, stop and work out why: the most likely cause is that the tenant has no site, so no pass resolved a pool and the test is vacuous. Add an item with `seedContentItem($pro->id, ['kind' => 'video'])` and re-check.

- [x] **Step 3: Add the batching prelude to `SetupPayload`**

Add these two private methods to `app/Services/Setup/SetupPayload.php`:

```php
/**
 * The content pools this user's pass list will resolve. Derived from the
 * pass keys rather than hardcoded so a capability difference (menu instead
 * of services) never resolves a pool the dialog will not render.
 *
 * @return list<string>
 */
private function poolsFor(User $user): array
{
    $pools = [];
    foreach (SetupPassRegistry::keysFor($user) as $key) {
        if (($itemPool = SetupPassRegistry::itemPool($key)) !== null) {
            $pools[] = $itemPool;
        } elseif ($key === 'media') {
            $pools[] = 'media';
        } elseif ($key === 'links') {
            $pools[] = 'custom_links';
        } elseif ($key === 'services') {
            $pools[] = 'services';
        }
    }

    return array_values(array_unique($pools));
}

/**
 * plan → ONE shared hydrate → assemble, the seam PoolWire::forSite uses.
 * Resolving each pool independently ran itemPayloads' ~20 facet queries
 * once per pool; the ids are planned per pool (cheap), hydrated once as a
 * union, and each pool assembles from the shared map.
 *
 * Unlike PoolWire this unions libraryIds too and keeps withLibrary — the
 * setup dialog renders the LIBRARY, not the selection.
 *
 * @param  list<string>  $pools
 * @return array<string, array<string, mixed>> pool => resolve()-shaped array
 */
private function resolveAllPools(Site $site, array $pools): array
{
    if ($pools === []) {
        return [];
    }

    $sections = $this->pools->preloadSections($site, $pools);
    $curationBySection = $this->pools->preloadCuration($sections);

    $plans = [];
    $ids = [];
    foreach ($pools as $pool) {
        $section = $sections[$pool];
        $plans[$pool] = $this->pools->plan(
            $site,
            $pool,
            $section,
            $curationBySection[(string) $section->id] ?? collect(),
        );
        array_push($ids, ...$plans[$pool]['selectionIds'], ...$plans[$pool]['libraryIds']);
    }

    // withDuplicateCandidates: true keeps this byte-identical to the
    // resolve() calls it replaces.
    [$payloads, $stores] = $this->pools->hydrateItems(
        $site,
        array_values(array_unique($ids)),
        withDuplicateCandidates: true,
    );

    $resolved = [];
    foreach ($pools as $pool) {
        $resolved[$pool] = $this->pools->assemble($site, $pool, $plans[$pool], $payloads, $stores);
    }

    return $resolved;
}
```

- [x] **Step 4: Thread the resolved map through `for()` and `composePass()`**

In `for()` (`:47`), after `$site = $user->site;` and before the pass loop:

```php
$resolvedPools = $site === null ? [] : $this->resolveAllPools($site, $this->poolsFor($user));
```

Change the loop call to pass it:

```php
$pass = $this->composePass($user, $site, $key, $suggestions, $onboarding, $openStages, $resolvedPools);
```

Change `composePass`'s signature (`:79`) to accept it as a final parameter, and update its docblock with:

```php
 * @param  array<string, array<string, mixed>>  $resolvedPools  pool => resolve() shape, hydrated once by resolveAllPools()
```

Replace the two `resolve()` call sites inside `composePass`:

```php
// was: $resolved = $this->pools->resolve($site, $itemPool);
$resolved = $resolvedPools[$itemPool] ?? null;
if ($resolved === null) {
    return null;
}
$items = $resolved['library'];
```

and

```php
// was: $resolved = $this->pools->resolve($site, $pool);
$pool = $key === 'media' ? 'media' : 'custom_links';
$resolved = $resolvedPools[$pool] ?? null;
if ($resolved === null) {
    return null;
}

return $base + ['items' => $resolved['library']];
```

Pass it into the services arm:

```php
if ($key === 'services') {
    return $site === null ? null : $base + $this->servicesPass($user, $site, $resolvedPools);
}
```

- [x] **Step 5: Update `servicesPass()`**

Change its signature (`:577`) to `servicesPass(User $user, Site $site, array $resolvedPools): array` and replace `:585`:

```php
// was: $resolved = $this->pools->resolve($site, 'services');
$items = $resolvedPools['services']['library'] ?? [];
```

Add the same `@param` docblock line as `composePass`.

- [x] **Step 6: Run the new test and confirm it passes**

Run: `./vendor/bin/pest tests/Feature/Setup/SetupPoolBatchingTest.php`
Expected: PASS.

- [x] **Step 7: Run the existing setup suite — this is the real gate**

Run: `./vendor/bin/pest tests/Feature/Setup/`
Expected: PASS, with no changes to any assertion. These tests assert the wire content; Task 1 must not move it. **If you find yourself editing an assertion in `SetupControllerTest.php` or `SetupMenuWireTest.php`, stop — you have changed behaviour, and the fix is in your code, not the test.**

- [x] **Step 8: Run the wider pool suite**

Run: `./vendor/bin/pest tests/Feature/Content/ tests/Feature/PublicSite/`
Expected: PASS. `PoolResolver` is shared with the public lane; this proves you did not disturb it.

- [x] **Step 9: Commit**

```bash
php artisan pint
git add app/Services/Setup/SetupPayload.php tests/Feature/Setup/SetupPoolBatchingTest.php
git commit -m "$(cat <<'EOF'
Resolve the setup dialog's pools in one hydrate, not seven

SetupPayload::for() composes 15 passes, seven of which called
PoolResolver::resolve(). resolve()'s own docblock records the cost: ~20
facet queries per pool, the N+1 PoolWire::forSite fixed for the public
lane in 2026-08-24 (measured there: 244 queries -> ~60).

Same seam, applied to the dialog: preloadSections + preloadCuration, plan
per pool, ONE hydrate over the union, assemble per pool. Two deliberate
differences from PoolWire — the union includes libraryIds and assemble
keeps withLibrary, because the setup dialog renders the library rather
than the selection.

Wire output is unchanged; SetupControllerTest and SetupMenuWireTest pass
untouched. The new test counts section_items selects rather than
asserting content, because content is identical either way.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FSZo1gax3gCURhQzpFVBDV
EOF
)"
```

---

### Task 2: Build one pass for the accept response

**Files:**
- Modify: `app/Services/Setup/SetupPayload.php` (add `forPass`), `app/Http/Controllers/Api/User/Setup/SetupController.php:132-141`
- Test: `tests/Feature/Setup/SetupPoolBatchingTest.php` (extend)

**Interfaces:**
- Consumes: `SetupPayload::poolsFor()`, `resolveAllPools()`, `composePass()` from Task 1.
- Produces: `SetupPayload::forPass(User $user, string $key): ?array` — the same array a single element of `for()['passes']` holds, or `null` when the pass is omitted (empty item pass, wire §2) or the key is not in the user's list.

- [x] **Step 1: Write the failing tests**

Append to `tests/Feature/Setup/SetupPoolBatchingTest.php`:

```php
it('returns the same pass from forPass as from the full compose', function () {
    $pro = createTenant('setup-onepass');
    seedContentItem($pro->id, ['kind' => 'video']);

    $payload = app(App\Services\Setup\SetupPayload::class);

    $fromAll = collect($payload->for($pro)['passes'])->firstWhere('key', 'items.watch');
    $fromOne = $payload->forPass($pro, 'items.watch');

    expect($fromOne)->toEqual($fromAll);
});

it('provisions every pool\'s section even when composing a single pass', function () {
    $pro = createTenant('setup-provision');
    $expected = DB::connection('pgsql')->table('site.sections')->count();

    app(App\Services\Setup\SetupPayload::class)->for($pro);
    $afterAll = DB::connection('pgsql')->table('site.sections')->count();

    // A second tenant taking the single-pass path must end up with the
    // same number of sections — provisioning is a side effect of
    // preloadSections and must not narrow with the pass being built.
    $other = createTenant('setup-provision-2');
    app(App\Services\Setup\SetupPayload::class)->forPass($other, 'items.watch');
    $afterOne = DB::connection('pgsql')->table('site.sections')->count();

    expect($afterOne - $afterAll)->toBe($afterAll - $expected);
});

it('returns null for a pass the user does not have', function () {
    $pro = createTenant('setup-nopass');

    expect(app(App\Services\Setup\SetupPayload::class)->forPass($pro, 'platforms.ordering'))->toBeNull();
});
```

- [x] **Step 2: Run them and confirm they fail**

Run: `./vendor/bin/pest tests/Feature/Setup/SetupPoolBatchingTest.php`
Expected: FAIL with `Call to undefined method App\Services\Setup\SetupPayload::forPass()`.

- [x] **Step 3: Implement `forPass()`**

Add to `app/Services/Setup/SetupPayload.php`, directly below `for()`:

```php
/**
 * One pass, for the accept response (A.9, wire §4). Continue needs only
 * the pass it just wrote back, and composing all fifteen to return one
 * hydrated six pools nobody read.
 *
 * Sections are still preloaded for EVERY pool, not just this one:
 * preloadSections provisions a missing section row as a side effect, and
 * narrowing that to the pass being built would silently stop provisioning
 * the rest. It is one batched query.
 *
 * @return array<string, mixed>|null null when the key is not this user's,
 *                                   or the pass is one the wire omits
 */
public function forPass(User $user, string $key): ?array
{
    if (! in_array($key, SetupPassRegistry::keysFor($user), true)) {
        return null;
    }

    $site = $user->site;
    $build = PreAccountBuild::query()->where('user_id', $user->id)->latest('created_at')->first();
    $openStages = $build === null ? [] : $this->openStages($build);

    // Only the platforms.* passes read these two, and they are the
    // expensive half of the prelude.
    $needsSuggestions = str_starts_with($key, 'platforms.');
    $suggestions = $needsSuggestions ? $this->suggestionRows($user) : [];
    $onboarding = $needsSuggestions ? $this->onboarding->for($user) : [];

    $pools = $this->poolsFor($user);
    $needed = array_values(array_intersect($pools, [
        SetupPassRegistry::itemPool($key)
            ?? match ($key) {
                'media' => 'media',
                'links' => 'custom_links',
                'services' => 'services',
                default => '',
            },
    ]));

    $resolvedPools = [];
    if ($site !== null) {
        // Provision every pool's section (side effect), hydrate only the
        // one this pass renders.
        $this->pools->preloadSections($site, $pools);
        $resolvedPools = $this->resolveAllPools($site, $needed);
    }

    return $this->composePass($user, $site, $key, $suggestions, $onboarding, $openStages, $resolvedPools);
}
```

- [x] **Step 4: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/Setup/SetupPoolBatchingTest.php`
Expected: PASS.

- [x] **Step 5: Point the controller at it**

In `app/Http/Controllers/Api/User/Setup/SetupController.php`, replace lines 134-135:

```php
// was:
// $refreshed = collect($payload->for($user)['passes'])->firstWhere('key', $data['pass']);
$refreshed = $payload->forPass($user, $data['pass']);
```

The comment above it stays — it still explains why the response carries a refreshed pass at all.

- [x] **Step 6: Run the setup suite**

Run: `./vendor/bin/pest tests/Feature/Setup/`
Expected: PASS, assertions untouched. `firstWhere` returned `null` for an omitted pass and so does `forPass`, so the response shape is unchanged.

- [x] **Step 7: Commit**

```bash
php artisan pint
git add app/Services/Setup/SetupPayload.php app/Http/Controllers/Api/User/Setup/SetupController.php tests/Feature/Setup/SetupPoolBatchingTest.php
git commit -m "$(cat <<'EOF'
Build one setup pass for the accept response, not fifteen

POST /site/setup/accept recomposed every pass and kept one. On the
2026-09-04 08:04:15 trace that was ~750ms of a 3.17s request — the same
work GET /site/setup costs (716-840ms in the adjacent log lines).

forPass() runs the same prelude and calls composePass once. Sections are
still preloaded for every pool because preloadSections provisions missing
rows as a side effect; only the pass's own pool is hydrated. suggestionRows
and OnboardingSuggestions are skipped unless the pass is a platforms.* one,
which is the only reader.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FSZo1gax3gCURhQzpFVBDV
EOF
)"
```

---

### Task 3: Drop the dashboard-only duplicate-candidates join

**Files:**
- Modify: `app/Services/Setup/SetupPayload.php` (`resolveAllPools`, one argument)
- Test: `tests/Feature/Setup/SetupPoolBatchingTest.php` (extend)

**Interfaces:**
- Consumes: `resolveAllPools()` from Task 1.
- Produces: nothing new.

**Why this is safe:** `hydrateItems`'s `$withDuplicateCandidates` flag runs a `content.identity_candidates` read. `SetupPayload` renders items through `setupItem()` (`:548`), which reads exactly `id`, `name`/`headline`/`title`, `price`/`basePrice`, `durationMinutes`, `image`/`thumbnail`/`imageUrl`, and `category`. It never reads a duplicate-candidates key. Task 1 passed `true` only to keep that task's diff behaviour-free.

**REVERTED 2026-09-04.** The premise above is false for 6 of 7 pools: `composePass()`'s `items.*`/`media`/`links` branches put `resolvedPools['library']` rows on the wire VERBATIM — no `setupItem()` transform — so `duplicateCandidates` (emitted unconditionally by `PoolResolver::itemPayloads`, populated only when the flag is `true`) is a live setup-wire field. A decisive test (seed two items + a `content.identity_candidates` row, assert a non-empty `duplicateCandidates` on the setup wire) passes on unmodified code and FAILS the instant the flag flips to `false` — proof the flip breaks the wire, not just a query-count regression. The flag is kept `true`; Steps 3/4/6 were not executed. Only Steps 1/2 below are ticked, standing in for the equivalent decisive test actually written (`tests/Feature/Setup/SetupPoolBatchingTest.php`, "the setup wire carries a populated duplicateCandidates today").

- [x] **Step 1: Write the failing test**

Append to `tests/Feature/Setup/SetupPoolBatchingTest.php`:

```php
it('does not run the dashboard-only identity_candidates read', function () {
    $pro = createTenant('setup-nodupes');
    seedContentItem($pro->id, ['kind' => 'video']);

    $seen = [];
    DB::listen(function ($query) use (&$seen) {
        if (str_contains($query->sql, 'identity_candidates')) {
            $seen[] = $query->sql;
        }
    });

    actingAsUser($pro)->getJson('/api/site/setup')->assertOk();

    expect($seen)->toBe([]);
});
```

- [x] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Setup/SetupPoolBatchingTest.php`
Expected: FAIL — one or more `identity_candidates` selects captured.

If it passes already, the seeded item produced no candidates read on this account branch. Do not delete the test; instead confirm the flag's effect directly by temporarily setting `withDuplicateCandidates: false` and checking `tests/Feature/Setup/` still passes, then restore and continue.

- [ ] **Step 3: Flip the flag**

In `resolveAllPools()`, change the hydrate call and replace the comment above it:

```php
// The setup dialog renders items through setupItem(), which reads none of
// the duplicate-candidates keys — so the content.identity_candidates read
// hydrateItems would run for them is pure cost here. #API-7's dashboard
// chip is served by PoolController::show -> resolve(), which is untouched.
[$payloads, $stores] = $this->pools->hydrateItems(
    $site,
    array_values(array_unique($ids)),
    withDuplicateCandidates: false,
);
```

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Setup/SetupPoolBatchingTest.php`
Expected: PASS.

- [ ] **Step 5: Prove the wire is unchanged**

Run: `./vendor/bin/pest tests/Feature/Setup/`
Expected: PASS, assertions untouched. This is the check that matters — if a setup assertion moves, the flag was load-bearing after all and you must revert this task.

- [ ] **Step 6: Commit**

```bash
php artisan pint
git add app/Services/Setup/SetupPayload.php tests/Feature/Setup/SetupPoolBatchingTest.php
git commit -m "$(cat <<'EOF'
Stop the setup lane running the duplicate-candidates read

hydrateItems' withDuplicateCandidates flag runs a content.identity_candidates
read for a dashboard chip. SetupPayload renders items through setupItem(),
which reads id, name, price, durationMinutes, photo and category — none of
the candidate keys. resolve() passed true, so the setup dialog inherited it.

PoolController::show -> resolve() still passes true, so the chip that flag
exists for is unaffected.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FSZo1gax3gCURhQzpFVBDV
EOF
)"
```

---

### Task 4: Verify on dev and record the numbers

**Files:**
- Modify: none (verification only, plus one doc line)

- [ ] **Step 1: Run the full suite**

Run: `composer test`
Expected: PASS. Note this takes ~20 minutes in CI; locally it is faster with `--parallel`.

- [ ] **Step 2: Run the Postgres lane**

Run: `composer test:pg`

Expected: PASS. `SetupPayload` does not touch `ProjectionWriter`, so this is a precaution rather than a likely failure — but the pool tables it reads are provisioned by stand-ins in that lane, and `PostgresLaneReadCoverageTest` will flag any column this change newly reads.

- [ ] **Step 3: Open the PR and let CI run all nine checks**

```bash
git push -u origin perf/setup-payload-pool-batching-2026-09-04
gh pr create --base development --title "Batch the setup dialog's pool resolution" --body "$(cat <<'EOF'
## What

`SetupPayload::for()` resolved seven content pools independently, paying
`PoolResolver`'s per-pool pre-reads and hydrate each time — the N+1
`PoolWire::forSite` fixed for the public lane in 2026-08-24. This routes the
dialog through the same plan → shared hydrate → assemble seam, adds a
single-pass entry point for the accept response, and drops a dashboard-only
join the setup lane never reads.

Found while tracing `POST /api/site/setup/accept` at 3.17s (Nightwatch,
2026-09-04 08:04:15 UTC).

## Wire impact

None. `SetupControllerTest` and `SetupMenuWireTest` pass with every
assertion untouched — that is the acceptance bar for the first two commits.

## Not in this PR

The other half of that 3.17s is a synchronous Google Places fan-out in
`FreshaWorkplaceLinker::connect()` — a Details call plus one billed media
call per photo (10 on the traced request) plus a Street View probe, all
blocking the click. Separate change.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01FSZo1gax3gCURhQzpFVBDV
EOF
)"
```

- [ ] **Step 4: After merge, measure on dev**

The merge auto-deploys to `dev-api.partna.au`. Wait for `deployment.succeeded`:

```bash
~/.composer/vendor/bin/cloud deployment:list development --json | head -c 400
```

Then exercise the dialog in the dashboard and read the real timings:

```bash
PATH="$HOME/.composer/vendor/bin:$PATH" python3 scripts/logs/window.py "<start>" "<end>" \
  | grep -E '"path": "/api/site/setup'
```

Expected: `GET /api/site/setup` well under its 716-840ms baseline, and `POST /api/site/setup/accept` down by roughly that same margin. **Record the actual before/after numbers in the PR thread** — the baselines above are from the 2026-09-04 08:04 window and are what the next person will compare against.

- [ ] **Step 5: Note the outcome in CLAUDE.md if the numbers warrant it**

If the measured saving is material, add one line to the Content pools section of `CLAUDE.md` recording that `SetupPayload` composes through the batched seam and that a new pass-composing path must not reintroduce a per-pool `resolve()`. Keep it to one sentence; that file is already long.

---

## Self-Review

**Spec coverage.** There is no separate spec; the plan's own Background section is the spec, and each of its claims maps to a task: the seven `resolve()` calls → Task 1; the accept path's 15-pass rebuild → Task 2; the inherited `withDuplicateCandidates: true` → Task 3; the measurement → Task 4. The provisioning side effect identified as the main risk is handled explicitly in Task 2 Step 3 and pinned by its own test.

**Placeholder scan.** No TBDs. Every code step carries the actual code. The one deliberately open value is the log window in Task 4 Step 4, which cannot be known before the deploy happens.

**Type consistency.** `poolsFor(User): list<string>` and `resolveAllPools(Site, array): array<string, array>` are introduced in Task 1 and consumed under those exact names in Task 2. `composePass()` gains `array $resolvedPools` as its seventh parameter in Task 1 Step 4 and is called with seven arguments in Task 2 Step 3. `servicesPass()` gains it as a third parameter in Task 1 Step 5 and is called with three in Task 1 Step 4. `forPass(User, string): ?array` is defined in Task 2 Step 3 and called in Step 5.

**One gap I am flagging rather than fixing.** Task 2's `$needed` computation uses a `match` with a `''` default, which yields an empty-string pool name for `platforms.*`, `listing`, `logo`, `menu` and `done`. `array_intersect` against the real pool list drops it, so the result is correct — but it is correct by accident of the intersect rather than by intent. If the implementer finds this obscure, replacing it with an explicit `poolForPassKey(string $key): ?string` helper is an improvement, not a deviation.
