# Platforms Uniformity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every platform connection in the dashboard becomes one row that carries its own name and disconnects independently, by giving all connectable catalog brands a real route contract instead of forcing them through family controllers.

**Architecture:** Two phases with a hard gate between them. **Phase A** (Tasks 1–2) is additive and lands on `development` now: one response key plus one comment. **Phase B** (Tasks 3–9) runs on its own branch after the slice-7 drops, and works by deriving `PlatformDescriptor`s from the compiled catalog at registry boot, so the existing registry-driven route loop emits per-brand endpoints without a route file edit per brand.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4. Compiled catalog artefact (`App\Catalog\CompiledCatalog`). No new packages, no migrations, no new tables.

**Spec:** `docs/superpowers/specs/2026-08-17-platforms-uniformity-design.md`

## Global Constraints

- **Backend only.** No `partna-monorepo` changes. This plan defines the wire the dashboard consumes.
- **Dev only.** Production is out of scope — prod is hundreds of commits behind and its schema has diverged from the 2026-07-26 baseline.
- **No new connectors.** Owner ruling 1: a brand is a source iff a `Connector` already exists. Every brand this plan makes connectable is link-only and sources nothing.
- **The family-wide `DELETE` endpoints stay.** Owner ruling 4: `DELETE /platforms/online-ordering`, `/booking`, `/reservations` keep working and keep meaning "remove all of this class". The new per-brand `DELETE` is an addition, never a replacement.
- **Never resolve a lazy strategy factory during derivation.** The route loop runs at boot; resolving `connectStrategy()` there bakes a real scraper into the descriptor before any test can mock it. Read declared flags only.
- **Never reflect `app/Catalog/Definitions/` at runtime.** Derivation reads the compiled artefact via `CompiledCatalog` only.
- **Branch on `multiAccount()`, never on `PlatformRouteShape`.** Shape names describe how routes are emitted; capability flags describe what a platform is.
- **After any catalog edit:** `php artisan catalog:compile` → `php artisan routing:corpus` → `./vendor/bin/pint`. Skipping pint makes the artefact diff thousands of formatting-only lines.
- **Tests run SQLite, prod is Postgres.** Verify constraint-bound writes against `supabase/migrations/` DDL, not just a green suite.
- **`composer test` cannot be combined with `--filter`** (known-broken in this repo). Run a single test by passing the file path to `./vendor/bin/pest`.

---

## File Structure

**Phase A — modified**
- `app/Http/Resources/Routing/RoutingConnectionResource.php` — add the `name` key.
- `tests/Feature/Routing/RoutingConnectionResourceTest.php` — **new**; no test file for this resource exists today.
- `app/Services/Platforms/Registry/PlatformRouteShape.php` — comment only.

**Phase B — created**
- `app/Services/Platforms/Registry/DerivedDescriptorFactory.php` — builds descriptors from compiled catalog surfaces. One responsibility: catalog row → `PlatformDescriptor`. No routing, no HTTP.
- `app/Http/Requests/Platforms/BrandConnectRequest.php` — validates that a submitted URL matches the addressed brand's detector.
- `tests/Feature/Platforms/Registry/DerivedDescriptorTest.php`
- `tests/Feature/Platforms/BrandRouteContractTest.php`
- `tests/Feature/Platforms/BrandConnectGuardTest.php`

**Phase B — modified**
- `app/Services/Platforms/Registry/PlatformRouteShape.php` — new `Brand` case.
- `app/Providers/PlatformRegistryServiceProvider.php` — register derived descriptors after the hand-written ones.
- `routes/api/platforms.php` — teach the loop the `Brand` shape.
- `app/Catalog/Definitions/*.php` — the `notConnectable()` flips (Task 7).
- `tests/Feature/Platforms/Registry/RegistryCoverageTest.php` — re-base the frozen-set assertion.
- `tests/Feature/Platforms/Registry/RegistryConnectCoverageTest.php` and `tests/Feature/Architecture/PlatformControllerConvergenceTest.php` — extend to the derived brands.

---

# PHASE A — land now on `development`

Additive only. Touches no slice-7 drop-list table. Safe to merge while other convergence work is in flight.

---

### Task 1: `RoutingConnectionResource` emits `name`

**Files:**
- Modify: `app/Http/Resources/Routing/RoutingConnectionResource.php:29-39`
- Test: `tests/Feature/Routing/RoutingConnectionResourceTest.php` (create)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: a `name` key of type `?string` on every `/routing/connections` row and on the set-primary response. Nothing later in this plan depends on it; the dashboard does.

**Context the implementer needs:** `payload` is a `jsonb` column on `site.platform_connections`. Nothing constrains its shape, so `payload['name']` may be absent, a string, or any other JSON type. The existing `url` key immediately above uses `is_string(...) ? ... : null` for exactly this reason — match it rather than inventing a cast.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Routing/RoutingConnectionResourceTest.php`:

```php
<?php

use App\Http\Resources\Routing\RoutingConnectionResource;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Http\Request;

function resolveRoutingConnection(array $payload): array
{
    $connection = new IntegrationConnection([
        'surface_key' => 'google_business.listing',
        'resource_id' => 'res-1',
        'is_primary' => false,
        'is_active' => true,
    ]);
    $connection->payload = $payload;
    $connection->id = '00000000-0000-0000-0000-000000000001';

    return (new RoutingConnectionResource($connection))->toArray(Request::create('/'));
}

it('emits payload.name when it is a string', function () {
    expect(resolveRoutingConnection(['name' => 'ST. ALi Coffee Roasters']))
        ->toHaveKey('name', 'ST. ALi Coffee Roasters');
});

it('emits null when payload carries no name', function () {
    expect(resolveRoutingConnection(['url' => 'https://maps.google.com/x']))
        ->toHaveKey('name', null);
});

it('emits null rather than casting a non-string name', function () {
    expect(resolveRoutingConnection(['name' => 10233455]))->toHaveKey('name', null);
    expect(resolveRoutingConnection(['name' => ['a' => 'b']]))->toHaveKey('name', null);
});
```

- [x] **Step 2: Run the test and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Routing/RoutingConnectionResourceTest.php`
Expected: three failures. The first two report a missing `name` key; the third also reports a missing key (not a wrong value) — that is correct at this stage.

- [x] **Step 3: Add the key**

In `app/Http/Resources/Routing/RoutingConnectionResource.php`, inside `toArray()`, add directly below the existing `'url' => ...` line:

```php
'name' => is_string($this->payload['name'] ?? null) ? $this->payload['name'] : null,
```

- [x] **Step 4: Run the test and confirm it passes**

Run: `./vendor/bin/pest tests/Feature/Routing/RoutingConnectionResourceTest.php`
Expected: 3 passed.

- [x] **Step 5: Run the routing suite for regressions**

Run: `./vendor/bin/pest tests/Feature/Routing/`
Expected: all pass. `PrimaryConnectionTest` exercises the same resource through `/routing/connections` and the set-primary response; a purely additive key must not disturb it. If it asserts an exact array shape, extend that assertion to include `name` rather than removing it.

- [x] **Step 6: Commit**

```bash
git add app/Http/Resources/Routing/RoutingConnectionResource.php tests/Feature/Routing/RoutingConnectionResourceTest.php
git commit -m "feat(routing): emit payload.name on connection rows

The dashboard's per-connection table falls back to a hostname without it
— maps.google.com for a Google Business row named ST. ALi Coffee
Roasters, and a bare numeric store id once Shopify rows go per-connection.

Nullable and guarded on is_string(): payload is jsonb and nothing
constrains its shape. Mirrors the url key directly above."
```

---

### Task 2: Document what `PlatformRouteShape::MultiAccount` actually means

**Files:**
- Modify: `app/Services/Platforms/Registry/PlatformRouteShape.php`

**Interfaces:**
- Consumes: nothing.
- Produces: no code change. The constraint it records is consumed by Task 5.

**Context the implementer needs:** the brief this plan descends from recommended reclassifying `nowbookit`, `opentable` and `resdiary` from `MultiAccount` to `SingleSelection`, because they declare `multiAccount() = false`. **That recommendation is wrong — do not follow it.** All three register as `->routes(PlatformRouteShape::MultiAccount, null, false)` at `app/Providers/PlatformRegistryServiceProvider.php:628-630`, where the middle argument is the connect controller and it is `null` (they are fully registry-driven since FOUND-24). `SingleSelection`'s branch at `routes/api/platforms.php:288-292` wires `selection` and `forget` to `$descriptor->connectController()` — reclassifying would route both to null.

The wiring is already correct. Only the enum case's name misdescribes it. This task records that so the next reader does not repeat the same wrong inference.

- [x] **Step 1: Amend the `MultiAccount` docblock**

In `app/Services/Platforms/Registry/PlatformRouteShape.php`, replace the comment above `case MultiAccount;` with:

```php
    // connect on the platform's own controller; selection + DELETE (and /accounts when
    // multiAccount()) on GenericPlatformController (the former $migratedReads group).
    //
    // The name describes the EMISSION PATTERN, not an account cardinality. A
    // descriptor may be MultiAccount with multiAccount() === false — nowbookit,
    // opentable and resdiary all are — and that combination is correct: it emits
    // connect + selection + forget on GenericPlatformController and no /accounts
    // routes, which is exactly what a single-account registry-driven provider
    // needs. Do NOT "fix" it by reclassifying to SingleSelection: all three
    // declare connectController = null, and SingleSelection wires selection +
    // forget to that controller. Branch on multiAccount(), never on this enum.
    case MultiAccount;
```

- [x] **Step 2: Confirm nothing broke**

Run: `./vendor/bin/pest tests/Feature/Platforms/Registry/`
Expected: all pass — this step changes no executable code, so a failure here means the working tree was already red. Investigate before continuing.

- [x] **Step 3: Commit**

```bash
git add app/Services/Platforms/Registry/PlatformRouteShape.php
git commit -m "docs(platforms): MultiAccount names an emission pattern, not a cardinality

nowbookit/opentable/resdiary are MultiAccount with multiAccount()=false
and that is correct. Reclassifying them to SingleSelection — as the
uniformity brief proposed — would wire selection + forget to a null
controller, because all three declare connectController = null (FOUND-24,
PlatformRegistryServiceProvider.php:628-630)."
```

---

## GATE — do not start Phase B until all of these hold

- [ ] The slice-7 drops have merged to `development`.
- [ ] The pool / convergence work the owner is running has landed.
- [ ] Task 7's keep/flip list (below) has been produced and **signed off by the owner**.
- [ ] A fresh branch exists off latest `origin/development`: `git switch -c feat/platform-brand-routes origin/development`.

Phase B does not touch the slice-7 drop-list tables — it competes for the same head, not the same tables.

---

# PHASE B — brand route contract

---

### Task 3: Re-base the registry freeze

**Do this first.** Every later task adds registry keys, and this test fails the moment the first one lands. Re-basing it deliberately, before any derivation exists, keeps the change reviewable on its own.

**Files:**
- Modify: `tests/Feature/Platforms/Registry/RegistryCoverageTest.php` (first `it(...)` block only)

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `PlatformRegistry::handWrittenFreeze(): list<string>` — the 78 frozen slugs, used by Tasks 4 and 8 to tell hand-written descriptors from derived ones.
  - `PlatformDescriptor::derived(): self` (builder) and `PlatformDescriptor::isDerived(): bool` — added here rather than in Task 4 so this task's shadow assertion can run green on delivery. Task 4 is what first sets the flag.

**Context the implementer needs:** `PlatformRegistry` is deliberately frozen at 78 slugs. `RegistryCoverageTest`'s first assertion demands `$registry->keys()` be byte-identical to `array_keys(LegacyPlatformMap::toSurfaceMap())`, and its comment gives the reason: the registry is what connect flows accept, the map is what the write-guard accepts, and drift lets one layer accept what the other rejects.

**That premise is already half-false.** `LegacyPlatformMap::isKnownSurface()` falls back to `CompiledCatalog::surface()`, so the write-guard already accepts catalog-only surfaces the registry has never heard of. The test pins a symmetry that stopped being true when the catalog fallback landed. Phase B makes the asymmetry explicit rather than introducing it.

**Do not touch `tests/Unit/Catalog/CatalogLegacyMapTest.php`.** It pins the `20260727110001` migration's backfill CASE, which is history and does not move.

- [ ] **Step 1: Capture the frozen set as a constant**

In `app/Services/Platforms/Registry/PlatformRegistry.php`, add below `FAMILY_DESCRIPTOR`:

```php
    /**
     * The 78 hand-written slugs, frozen to the 20260727110001 backfill CASE and
     * pinned by CatalogLegacyMapTest. Derived descriptors (DerivedDescriptorFactory)
     * are registered ON TOP of these and are deliberately NOT in this list — the
     * freeze applies to the hand-written half only. See the design doc §6.8.
     *
     * @return list<string>
     */
    public static function handWrittenFreeze(): array
    {
        return array_keys(LegacyPlatformMap::toSurfaceMap());
    }
```

Add `use App\Catalog\LegacyPlatformMap;` to the imports.

- [ ] **Step 2: Rewrite the freeze assertion**

Replace the body of the `it('registers exactly the platforms the app accepts today', ...)` block with:

```php
it('keeps the hand-written registry frozen to the legacy map', function () {
    $registry = app(PlatformRegistry::class);

    // The freeze applies to the HAND-WRITTEN half only. Derived descriptors
    // (DerivedDescriptorFactory) extend the registry to every connectable
    // URL-detected catalog brand; the write-guard already accepts them, because
    // LegacyPlatformMap::isKnownSurface() falls back to the compiled catalog.
    // Design doc §6.8.
    $frozen = PlatformRegistry::handWrittenFreeze();
    sort($frozen);

    $handWritten = array_values(array_intersect($registry->keys(), $frozen));
    sort($handWritten);

    expect($handWritten)->toBe($frozen);
});

it('never registers a derived descriptor that shadows a hand-written one', function () {
    $registry = app(PlatformRegistry::class);

    foreach (PlatformRegistry::handWrittenFreeze() as $slug) {
        expect($registry->get($slug)?->isDerived())->toBeFalse(
            "Derived descriptor shadowed the hand-written '{$slug}'."
        );
    }
});
```

- [ ] **Step 3: Add the `derived` flag to `PlatformDescriptor`**

The shadow assertion calls `isDerived()`, so it lands here — this task must deliver green. Task 4 is what first *sets* the flag.

In `app/Services/Platforms/Registry/PlatformDescriptor.php`, add the property beside the other declared flags and the pair of methods beside `routeShape()`:

```php
    /** Built by DerivedDescriptorFactory from a compiled catalog surface, not hand-registered. */
    private bool $derived = false;

    public function derived(): self
    {
        $this->derived = true;

        return $this;
    }

    public function isDerived(): bool
    {
        return $this->derived;
    }
```

- [ ] **Step 4: Run and confirm both tests pass**

Run: `./vendor/bin/pest tests/Feature/Platforms/Registry/RegistryCoverageTest.php`
Expected: both PASS. The freeze test passes because nothing derived exists yet, so the intersection is the whole set. The shadow test passes vacuously — every descriptor is hand-written today. Both become load-bearing in Task 5.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Platforms/Registry/PlatformRegistry.php app/Services/Platforms/Registry/PlatformDescriptor.php tests/Feature/Platforms/Registry/RegistryCoverageTest.php
git commit -m "test(platforms): re-base the registry freeze to the hand-written half

The freeze asserted registry keys == LegacyPlatformMap keys, on the
premise that the two accept-layers must not drift. That premise is
already half-false: isKnownSurface() falls back to the compiled catalog,
so the write-guard accepts catalog-only surfaces the registry never had.

Freezes the hand-written 78 by intersection instead, leaving room for
derived descriptors. CatalogLegacyMapTest is untouched — it pins a
migration backfill, which is history."
```

---

### Task 4: `DerivedDescriptorFactory` — catalog surface to descriptor

**Files:**
- Create: `app/Services/Platforms/Registry/DerivedDescriptorFactory.php`
- Modify: `app/Services/Platforms/Registry/PlatformDescriptor.php` (add `derived()` / `isDerived()`)
- Test: `tests/Feature/Platforms/Registry/DerivedDescriptorTest.php` (create)

**Interfaces:**
- Consumes: `PlatformRegistry::handWrittenFreeze(): list<string>` and `PlatformDescriptor::derived()` / `isDerived()` (all Task 3).
- Produces:
  - `DerivedDescriptorFactory::build(): array<string, PlatformDescriptor>` — keyed by brand key.
  - `PlatformRouteShape::Brand`.
  - `DerivedDescriptorFactory::CAPABILITY_BY_ROUTING_CLASS: array<string, string>`.

**Context the implementer needs:**

`CompiledCatalog::surfaces()` returns `array<string, array>` keyed by surface key (`booksy.book`), each row carrying at least `brand_key`, `display_name`, `routing_class`, `shelf`, `identifier_kind`, `is_connectable`, `max_accounts`, `lifecycle`. `CompiledCatalog::brands()` is keyed by brand key with `display_name`, `homepage`, `lifecycle`, `successor_key`. `CompiledCatalog::detectors()` returns the detector table.

**Why brand keys are the right slug:** `site.platform_connections.platform` is a **generated column** — the brand prefix of `surface_key`. `IntegrationConnection::setPlatformAttribute()` accepts either a legacy slug or a full surface key and derives `surface_key` from it, never writing `platform` directly. `GenericPlatformController::descriptor()` does `registry->get($this->platform())` and 404s on null. So a slug of `calendly` resolves a descriptor keyed `calendly`, whose connect writes `calendly.book`, whose generated `platform` column reads back `calendly`. The chain closes.

**The 1:1 rule:** derive one descriptor per brand with **exactly one** connectable URL-detected surface. Verified 2026-08-17: of 110 files in `app/Catalog/Definitions/`, only `Square.php` and `Bandcamp.php` declare more than one surface, and both already have hand-written coverage. A brand with two or more connectable surfaces is skipped and must carry a hand-written descriptor — Task 8 pins that so a third such brand cannot appear silently.

**Hard constraint:** `build()` runs at boot. Read the compiled artefact only, never reflect `Definitions/`, and never resolve a strategy factory.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Platforms/Registry/DerivedDescriptorTest.php`:

```php
<?php

use App\Catalog\CompiledCatalog;
use App\Services\Platforms\Registry\DerivedDescriptorFactory;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Registry\PlatformRouteShape;

it('derives a descriptor for a connectable url-detected brand', function () {
    $derived = app(DerivedDescriptorFactory::class)->build();

    expect($derived)->toHaveKey('booksy');
    expect($derived['booksy']->routeShape())->toBe(PlatformRouteShape::Brand);
    expect($derived['booksy']->isDerived())->toBeTrue();
    expect($derived['booksy']->multiAccount())->toBeFalse();
});

it('never derives a slug that a hand-written descriptor already owns', function () {
    $derived = array_keys(app(DerivedDescriptorFactory::class)->build());

    expect(array_intersect($derived, PlatformRegistry::handWrittenFreeze()))->toBe([]);
});

it('skips brands that declare more than one connectable surface', function () {
    $derived = app(DerivedDescriptorFactory::class)->build();

    $multi = [];
    foreach (CompiledCatalog::surfaces() as $key => $surface) {
        if (($surface['is_connectable'] ?? false) === true) {
            $multi[$surface['brand_key']] = ($multi[$surface['brand_key']] ?? 0) + 1;
        }
    }

    foreach ($multi as $brand => $count) {
        if ($count > 1) {
            expect($derived)->not->toHaveKey($brand);
        }
    }
});

it('skips surfaces that are not connectable', function () {
    $derived = app(DerivedDescriptorFactory::class)->build();

    expect($derived)->not->toHaveKey('partna');
    expect($derived)->not->toHaveKey('generic_store');
});

it('carries the routing-class capability predicate', function () {
    $derived = app(DerivedDescriptorFactory::class)->build();

    // A booking brand must not be connectable by an account without booking.
    $booksy = $derived['booksy'];
    expect($booksy->availableFor(userWithout('can_use_booking')))->toBeFalse();
    expect($booksy->availableFor(userWith('can_use_booking')))->toBeTrue();
});
```

**Before writing `userWith`/`userWithout`:** define them inline **in this file** — cross-file Pest helpers break the parallel runner in this repo. Build them on the existing `User` factory and whatever `AccountCapabilities` reads (check `AccountCapabilities::for()`); do not invent a capability setter.

- [ ] **Step 2: Run and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Platforms/Registry/DerivedDescriptorTest.php`
Expected: FAIL — `Target class [App\Services\Platforms\Registry\DerivedDescriptorFactory] does not exist.`

- [ ] **Step 3: Add the `Brand` route shape**

In `app/Services/Platforms/Registry/PlatformRouteShape.php`, add:

```php
    // connect (with a brand-detector guard) + selection + DELETE all on
    // GenericPlatformController, for descriptors DERIVED from the compiled
    // catalog. Distinct from LinkOnly, which is for hand-registered socials and
    // has no detector guard on connect. /accounts is emitted only when
    // multiAccount().
    case Brand;
```

- [ ] **Step 4: Write the factory**

Create `app/Services/Platforms/Registry/DerivedDescriptorFactory.php`:

```php
<?php

namespace App\Services\Platforms\Registry;

use App\Catalog\CompiledCatalog;
use App\Catalog\CatalogNotCompiled;
use App\Models\Core\User\User;
use App\Services\AccountCapabilities;

/**
 * Builds a PlatformDescriptor for every connectable, URL-detected catalog brand
 * that has no hand-written descriptor — so the registry-driven route loop emits
 * the four platform endpoints for it without a route-file edit per brand.
 *
 * Keyed by BRAND key, not surface key: site.platform_connections.platform is a
 * generated column equal to the brand prefix of surface_key, and that column is
 * what GenericPlatformController filters on. Design doc §6.8.1.
 *
 * Runs at boot. Reads the compiled artefact ONLY — never reflects Definitions/,
 * never resolves a strategy factory.
 */
class DerivedDescriptorFactory
{
    /** routing_class => the AccountCapabilities predicate a brand of that class needs. */
    public const CAPABILITY_BY_ROUTING_CLASS = [
        'ordering' => 'can_use_online_ordering',
        'booking' => 'can_use_booking',
        'reservations' => 'can_use_reservations',
    ];

    /** @return array<string, PlatformDescriptor> */
    public function build(): array
    {
        try {
            $surfaces = CompiledCatalog::surfaces();
            $brands = CompiledCatalog::brands();
        } catch (CatalogNotCompiled) {
            return [];
        }

        $frozen = array_flip(PlatformRegistry::handWrittenFreeze());
        $detected = $this->detectedSurfaceKeys();

        // Group connectable, URL-detected surfaces by brand so the 1:1 rule can
        // be applied. A brand with 2+ of them is SKIPPED — one slug cannot
        // address two surfaces, so it needs a hand-written descriptor.
        $byBrand = [];
        foreach ($surfaces as $key => $surface) {
            if (! in_array($surface['lifecycle'] ?? '', ['active', 'sunset'], true)) {
                continue;
            }
            if (($surface['is_connectable'] ?? false) !== true) {
                continue;
            }
            if (! isset($detected[$key])) {
                continue;
            }
            $byBrand[$surface['brand_key']][$key] = $surface;
        }

        $derived = [];
        foreach ($byBrand as $brandKey => $group) {
            if (count($group) !== 1 || isset($frozen[$brandKey])) {
                continue;
            }

            $surface = reset($group);
            $derived[$brandKey] = $this->descriptorFor($brandKey, $surface, $brands[$brandKey] ?? []);
        }

        return $derived;
    }

    /** @return array<string, true> surface keys that have at least one URL detector */
    private function detectedSurfaceKeys(): array
    {
        $keys = [];
        foreach (CompiledCatalog::detectors() as $detector) {
            $surfaceKey = $detector['surface_key'] ?? null;
            if (is_string($surfaceKey)) {
                $keys[$surfaceKey] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $surface
     * @param  array<string, mixed>  $brand
     */
    private function descriptorFor(string $brandKey, array $surface, array $brand): PlatformDescriptor
    {
        $descriptor = PlatformDescriptor::make($brandKey)
            ->label($brand['display_name'] ?? $surface['display_name'] ?? $brandKey)
            ->derived()
            ->routes(
                PlatformRouteShape::Brand,
                null,
                ((int) ($surface['max_accounts'] ?? 1)) > 1,
            );

        $capability = self::CAPABILITY_BY_ROUTING_CLASS[$surface['routing_class'] ?? ''] ?? null;
        if ($capability !== null) {
            $descriptor->requiresCapability(
                fn (User $user): bool => (bool) AccountCapabilities::for($user)->{$capability}
            );
        }

        return $descriptor;
    }
}
```

**Verify before running:** the detector row's surface-key field name is a guess from `CompiledCatalog::detectors()`'s shape. Dump it once — `php artisan tinker --execute="print_r(array_slice(App\Catalog\CompiledCatalog::detectors(), 0, 2, true));"` (tinker cannot reach the DB in this repo, but pure-PHP evaluation works) — and correct `detectedSurfaceKeys()` to the real key name. Likewise confirm how `AccountCapabilities::for()` exposes each capability (property, method, or array access) and match it.

- [ ] **Step 5: Run the test and iterate to green**

Run: `./vendor/bin/pest tests/Feature/Platforms/Registry/DerivedDescriptorTest.php`
Expected: all pass. If `booksy` is absent, check that `Booksy.php` is not `notConnectable()` — it is not, as of 2026-08-17, which is why it is the fixture brand.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Platforms/Registry/ tests/Feature/Platforms/Registry/DerivedDescriptorTest.php
git commit -m "feat(platforms): derive descriptors from the compiled catalog

Every connectable URL-detected brand with no hand-written descriptor now
gets one, keyed by brand key — which is what the generated platform
column and GenericPlatformController both key on.

A brand declaring 2+ connectable surfaces is skipped: one slug cannot
address two. Only Square and Bandcamp do today and both are hand-written."
```

---

### Task 5: Register derived descriptors and emit their routes

**Files:**
- Modify: `app/Providers/PlatformRegistryServiceProvider.php` (end of the registration closure)
- Modify: `routes/api/platforms.php:274-320`
- Test: `tests/Feature/Platforms/BrandRouteContractTest.php` (create)

**Interfaces:**
- Consumes: `DerivedDescriptorFactory::build()`, `PlatformRouteShape::Brand` (Task 4).
- Produces: for every derived slug — `POST /api/platforms/{slug}/connect`, `GET /api/platforms/{slug}/selection`, `DELETE /api/platforms/{slug}`, and `/accounts` + `DELETE /accounts/{id}` when `multiAccount()`.

**Context the implementer needs:** the loop at `routes/api/platforms.php:274` skips `Bespoke` and handles `SingleSelection` in a branch that `return`s early. `LinkOnly` and `MultiAccount` fall through to a shared tail that wires `selection` and `forget` to `GenericPlatformController` with `->defaults('platform', $slug)`. `Brand` wants that same tail — so the smallest correct change is to let `Brand` reach it, not to write a new branch.

Register derived descriptors **after** every hand-written one, so `PlatformRegistry::register()`'s last-write-wins cannot let a derived descriptor shadow a hand-written slug. Task 3's shadow test pins this.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Platforms/BrandRouteContractTest.php`:

```php
<?php

use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Registry\PlatformRouteShape;
use Illuminate\Support\Facades\Route;

function routeExists(string $method, string $uri): bool
{
    foreach (Route::getRoutes() as $route) {
        if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
            return true;
        }
    }

    return false;
}

it('emits the four platform routes for every derived brand', function () {
    $registry = app(PlatformRegistry::class);

    $derived = array_filter($registry->all(), fn ($d) => $d->routeShape() === PlatformRouteShape::Brand);
    expect($derived)->not->toBeEmpty();

    foreach ($derived as $slug => $descriptor) {
        expect(routeExists('POST', "api/platforms/{$slug}/connect"))->toBeTrue("missing connect for {$slug}");
        expect(routeExists('GET', "api/platforms/{$slug}/selection"))->toBeTrue("missing selection for {$slug}");
        expect(routeExists('DELETE', "api/platforms/{$slug}"))->toBeTrue("missing delete for {$slug}");
    }
});

it('keeps the family-wide disconnect endpoints', function () {
    // Owner ruling 4: per-brand DELETE is an addition, never a replacement.
    expect(routeExists('DELETE', 'api/platforms/online-ordering'))->toBeTrue();
    expect(routeExists('DELETE', 'api/platforms/booking'))->toBeTrue();
    expect(routeExists('DELETE', 'api/platforms/reservations'))->toBeTrue();
});
```

**Before running:** confirm the real URI prefix. The loop builds `Route::prefix("{$base}/{$slug}")`; read `$base` at the top of `routes/api/platforms.php` and correct the expected URIs if it is not `api/platforms`. The family-endpoint URIs likewise — read them off `Route::getRoutes()` rather than trusting this plan.

- [ ] **Step 2: Run and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Platforms/BrandRouteContractTest.php`
Expected: the first test fails — `expect($derived)->not->toBeEmpty()` — because nothing registers derived descriptors yet. The second should already pass; if it does not, the family endpoints moved and that is a finding to report before continuing.

- [ ] **Step 3: Register the derived descriptors**

In `app/Providers/PlatformRegistryServiceProvider.php`, as the **last** statement of the closure that registers descriptors:

```php
            // Derived last: register() is last-write-wins, and a derived
            // descriptor must never shadow a hand-written slug. Pinned by
            // RegistryCoverageTest's shadow assertion.
            foreach (app(DerivedDescriptorFactory::class)->build() as $slug => $descriptor) {
                if (! $r->has($slug)) {
                    $r->register($descriptor);
                }
            }
```

Add `use App\Services\Platforms\Registry\DerivedDescriptorFactory;` to the imports.

- [ ] **Step 4: Let `Brand` reach the shared route tail**

In `routes/api/platforms.php`, change the comment above the shared tail from

```php
                // LinkOnly + MultiAccount: reads served by the registry-driven
```

to

```php
                // LinkOnly + MultiAccount + Brand: reads served by the registry-driven
```

No conditional change is needed — the `SingleSelection` branch returns early and `Bespoke` is skipped at the top, so `Brand` already falls through. **Confirm this by reading the loop rather than assuming**; if a later edit added a shape check to the tail, add `Brand` to it.

- [ ] **Step 5: Run the test and confirm it passes**

Run: `./vendor/bin/pest tests/Feature/Platforms/BrandRouteContractTest.php`
Expected: 2 passed.

- [ ] **Step 6: Run the registry and architecture suites**

Run: `./vendor/bin/pest tests/Feature/Platforms/Registry/ tests/Feature/Architecture/`
Expected: all pass, including Task 3's shadow assertion. `PlatformControllerConvergenceTest` may now fail if it enumerates registry keys against a hand-maintained list — if so, that is Task 8's work; note it and continue.

- [ ] **Step 7: Measure the boot cost**

Run: `php artisan route:list --path=platforms | wc -l` before and after this task (use `git stash` to compare), and time a request with `php artisan route:cache && time php artisan route:list > /dev/null`.

Record both numbers in the commit body. The spec flags boot cost as a risk precisely because it must be measured rather than assumed — the route file already carries two loops and this adds a third pass over ~86 entries. If the added time exceeds ~50ms, stop and report before continuing.

- [ ] **Step 8: Commit**

```bash
git add app/Providers/PlatformRegistryServiceProvider.php routes/api/platforms.php tests/Feature/Platforms/BrandRouteContractTest.php
git commit -m "feat(platforms): emit the platform route contract for derived brands

Derived descriptors register after every hand-written one and only when
the slug is free, so they cannot shadow. PlatformRouteShape::Brand falls
through to the same GenericPlatformController tail LinkOnly uses.

Family-wide DELETE endpoints are unchanged and pinned by test (ruling 4).

Route count: <before> -> <after>. route:list: <before>ms -> <after>ms."
```

---

### Task 6: The detector-match guard on connect

**Files:**
- Create: `app/Http/Requests/Platforms/BrandConnectRequest.php`
- Modify: `routes/api/platforms.php` (the `Brand` connect route only)
- Test: `tests/Feature/Platforms/BrandConnectGuardTest.php` (create)

**Interfaces:**
- Consumes: `PlatformRouteShape::Brand` (Task 4), the registered derived descriptors (Task 5).
- Produces: a 422 on a brand/URL mismatch, before any write.

**Context the implementer needs:** this is the one genuinely new piece of logic in Phase B, and it is what separates per-brand routes from a differently-spelled family classifier. `POST /platforms/menulog/connect` carrying a DoorDash URL must be **rejected**, not silently routed to DoorDash.

`WebsiteLinkHarvester::classify(string $url): ?array{platform:string, category:string, label:string}` is the classifier `LinkRouter` uses. Since Phase 6 it returns a **per-brand** key in two shapes: a legacy slug for a registered brand (`'booksy'`) or a full surface key for a catalog-only one (`'calendly.book'`, `'doordash.order'`). Normalise with `LegacyPlatformMap::legacyFor()`, which reduces a surface key to its brand prefix and passes a legacy slug through — giving one comparable value in both shapes.

**Note for the reader:** `app/Catalog/Definitions/Booksy.php`'s docblock claims `WebsiteLinkHarvester` "always collapses it to the generic 'booking' pseudo-platform, never to this dedicated key". **That comment is stale** — it describes pre-Phase-6 behaviour, and `BOOKING_PLATFORM` now maps `'Booksy' => 'booksy'`. Task 7 corrects the comment.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Platforms/BrandConnectGuardTest.php`:

```php
<?php

use App\Models\Core\User\User;

it('rejects a url belonging to a different brand', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->postJson('/api/platforms/menulog/connect', ['url' => 'https://www.doordash.com/store/abc-123'])
        ->assertStatus(422)
        ->assertJsonPath('errors.url.0', fn ($m) => str_contains(strtolower($m), 'doordash'));
});

it('accepts a url belonging to the addressed brand', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->postJson('/api/platforms/menulog/connect', ['url' => 'https://www.menulog.com.au/restaurants/x'])
        ->assertSuccessful();
});

it('rejects a url the classifier does not recognise at all', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->postJson('/api/platforms/menulog/connect', ['url' => 'https://example.com/nothing'])
        ->assertStatus(422);
});
```

**Before running:** `actingAsUser` is a placeholder for this repo's auth helper — Supabase JWTs mean `Auth::user()` is always null and tests use a project-specific helper. Find it by reading an existing platform-connect feature test (`tests/Feature/Platforms/`) and copy that setup verbatim, including any capability grants `menulog` needs under `can_use_online_ordering`.

- [ ] **Step 2: Run and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Platforms/BrandConnectGuardTest.php`
Expected: the first test fails — the mismatched URL is accepted or 404s rather than 422ing.

- [ ] **Step 3: Write the request class**

Create `app/Http/Requests/Platforms/BrandConnectRequest.php`:

```php
<?php

namespace App\Http\Requests\Platforms;

use App\Catalog\LegacyPlatformMap;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Connect input for a DERIVED brand route (PlatformRouteShape::Brand).
 *
 * The whole point of per-brand routes is that /platforms/menulog/connect means
 * Menulog. Without this guard a DoorDash URL posted there would be classified
 * freely by LinkRouter and written as DoorDash — a family classifier wearing a
 * brand's URL, which is the thing Phase B exists to end.
 */
class BrandConnectRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['url' => ['required', 'string', 'max:2048']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $slug = (string) $this->route('platform');
            $classified = app(WebsiteLinkHarvester::class)->classify((string) $this->input('url'));

            if ($classified === null) {
                $validator->errors()->add('url', 'That link is not a recognised '.$slug.' link.');

                return;
            }

            // classify() returns a legacy slug for a registered brand and a full
            // surface key for a catalog-only one. legacyFor() reduces both to the
            // brand prefix, giving one comparable value.
            $actual = LegacyPlatformMap::legacyFor($classified['platform']);

            if ($actual !== $slug) {
                $validator->errors()->add(
                    'url',
                    'That looks like a '.$classified['label'].' link, not a '.$slug.' link.'
                );
            }
        });
    }
}
```

- [ ] **Step 4: Wire it to the `Brand` connect route**

In `routes/api/platforms.php`, the shared tail currently wires connect before the shape branch. Give `Brand` its own connect line so only derived brands get the guard:

```php
                if ($shape === PlatformRouteShape::Brand) {
                    Route::post('/connect', [GenericPlatformController::class, 'connect'])
                        ->defaults('platform', $slug)
                        ->middleware('platform.available');
                }
```

`GenericPlatformController::connect()` type-hints `PlatformConnectRequest`. **Read that class first**: if `BrandConnectRequest` can extend it and add the `after` hook, do that and leave the controller signature alone. If it cannot, add a sibling `connectBrand(BrandConnectRequest $request)` method to `GenericPlatformController` that delegates to the same private write path — do **not** widen the existing method's type hint, which every other shape depends on.

- [ ] **Step 5: Run the test and iterate to green**

Run: `./vendor/bin/pest tests/Feature/Platforms/BrandConnectGuardTest.php`
Expected: 3 passed.

- [ ] **Step 6: Confirm the family path still classifies freely**

Run: `./vendor/bin/pest tests/Feature/Platforms/`
Expected: all pass. `POST /online-ordering/entries` must still accept any ordering URL and classify it — the guard applies to per-brand routes only. If a family test fails, the guard leaked into the shared tail.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Platforms/BrandConnectRequest.php routes/api/platforms.php tests/Feature/Platforms/BrandConnectGuardTest.php
git commit -m "feat(platforms): reject cross-brand urls on per-brand connect

/platforms/menulog/connect now means Menulog. A DoorDash url posted there
422s with the brand it actually belongs to, instead of being classified
freely and written as DoorDash.

Normalises WebsiteLinkHarvester::classify()'s two shapes (legacy slug vs
full surface key) through LegacyPlatformMap::legacyFor(). Family routes
still classify freely — the guard is per-brand only."
```

---

### Task 7: The connectability flips

**Files:**
- Modify: ~22 files under `app/Catalog/Definitions/`
- Modify: `app/Catalog/Definitions/Booksy.php` (stale docblock, opportunistic)
- Regenerate: the compiled catalog artefact + routing corpus

**Interfaces:**
- Consumes: nothing.
- Produces: a larger set of derived slugs, consumed by Task 8's coverage assertions.

**Context the implementer needs:** 46 of the 110 definition files call `notConnectable()`. Most of the brands this plan makes connectable are among them. `notConnectable()` is currently **display metadata only** — read by `CatalogSurfacesController`, never by routing. Task 4 makes it load-bearing: `is_connectable === false` now means "no derived descriptor, therefore no routes". After this task, `isConnectable` and "has connect routes" mean the same thing, which is the equivalence the card roster depends on.

**This is the one part of Phase B where a wrong call is user-visible** — a brand made connectable whose connect does not work is a card the user taps into a dead end.

- [ ] **Step 1: Produce the keep/flip list**

```bash
grep -rl "notConnectable()" app/Catalog/Definitions/ | xargs -n1 basename | sort
```

For each file, record in a scratch table: brand, surface key, routing class, whether `WebsiteLinkHarvester` has a host pattern for it (grep the brand's host in `app/Services/Platforms/WebsiteLinkHarvester.php`), and FLIP or KEEP.

**KEEP (verified 2026-08-17, these are pseudo-surfaces, not brands):** `Partna.php`, `GenericStore.php`, `DirectBooking.php`, `OrderOnline.php`.
**KEEP:** `Square.php`'s `square.order` — the `*.square.site` misclassification is explicitly out of scope, and Square already declares two surfaces so Task 4 skips the brand anyway.
**FLIP candidates:** Resy, Tock, Sevenrooms, Tablecheck, Quandoo, Vagaro, Timely, Phorest, Mindbody, Zenoti, Ovatu, Shortcuts, Ticketek, Oztix, Trybooking, Ticketmaster, ResidentAdvisor, Patreon, Substack, Mixcloud, Tidal, Whatsapp.

**A brand with no `WebsiteLinkHarvester` host pattern cannot pass Task 6's guard** — its connect would 422 every URL. Mark those KEEP and list them separately; adding host patterns is follow-on work, not this task's.

- [ ] **Step 2: STOP — get owner sign-off**

Post the keep/flip table and wait. Do not edit any definition before it is approved. The spec makes this an explicit control.

- [ ] **Step 3: Apply the approved flips**

Remove the `->notConnectable()` call from each approved file. Nothing else in the definition changes.

- [ ] **Step 4: Fix the stale Booksy docblock (opportunistic)**

In `app/Catalog/Definitions/Booksy.php`, replace the sentence claiming `WebsiteLinkHarvester` "always collapses it to the generic 'booking' pseudo-platform, never to this dedicated key" with:

```php
 * Convergence Phase 6 retired the shared 'booking' pseudo-platform:
 * WebsiteLinkHarvester::BOOKING_PLATFORM now maps 'Booksy' => 'booksy', so the
 * harvester and this detector agree. (This comment previously said the opposite
 * and was stale.)
```

- [ ] **Step 5: Recompile and format**

```bash
php artisan catalog:compile
php artisan routing:corpus
./vendor/bin/pint
```

Skipping pint makes the artefact diff thousands of formatting-only lines.

- [ ] **Step 6: Run the catalog and platform suites**

Run: `./vendor/bin/pest tests/Unit/Catalog/ tests/Feature/Platforms/ tests/Feature/Architecture/`
Expected: all pass. `CatalogLegacyMapTest` must stay green — flipping a display flag does not touch the migration backfill it pins. If it goes red, a flip changed something structural; stop and investigate.

- [ ] **Step 7: Commit**

```bash
git add app/Catalog/
git commit -m "feat(catalog): make the link-only brands connectable

notConnectable() was display metadata only. Derived descriptors make it
load-bearing: is_connectable false now means no routes. Flips the brands
on the owner-approved list so isConnectable and 'has connect routes' mean
the same thing — the equivalence the connect-sheet card roster reads.

Pseudo-surfaces (partna, generic_store, direct_booking, order_online) and
square.order stay false. Corrects Booksy's stale pre-Phase-6 docblock."
```

---

### Task 8: Extend the coverage guards

**Files:**
- Modify: `tests/Feature/Platforms/Registry/RegistryConnectCoverageTest.php`
- Modify: `tests/Feature/Architecture/PlatformControllerConvergenceTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: the invariant that stops this regressing.

**Context the implementer needs:** the spec's §6.2 risk is that a third multi-surface brand appears, Task 4's factory silently skips it, and it loses its routes with nothing noticing. That is what these assertions exist to prevent — read them as the point of Phase B, not as paperwork.

- [ ] **Step 1: Write the failing assertions**

Add to `tests/Feature/Platforms/Registry/RegistryConnectCoverageTest.php`:

```php
it('routes every connectable url-detected brand, derived or hand-written', function () {
    $registry = app(PlatformRegistry::class);

    $detected = [];
    foreach (App\Catalog\CompiledCatalog::detectors() as $detector) {
        $key = $detector['surface_key'] ?? null;
        if (is_string($key)) {
            $detected[$key] = true;
        }
    }

    $unrouted = [];
    foreach (App\Catalog\CompiledCatalog::surfaces() as $key => $surface) {
        if (($surface['is_connectable'] ?? false) !== true || ! isset($detected[$key])) {
            continue;
        }
        if (! in_array($surface['lifecycle'] ?? '', ['active', 'sunset'], true)) {
            continue;
        }
        if ($registry->has($surface['brand_key'])) {
            continue;
        }
        $unrouted[] = $key;
    }

    // A brand with 2+ connectable surfaces is deliberately NOT derived (one slug
    // cannot address two) and must carry a hand-written descriptor. If this fails
    // with a new brand name, that brand needs one — do not widen the factory.
    expect($unrouted)->toBe([]);
});
```

Match the detector field name to whatever Task 4 established.

- [ ] **Step 2: Run and confirm it passes**

Run: `./vendor/bin/pest tests/Feature/Platforms/Registry/RegistryConnectCoverageTest.php`
Expected: PASS. If it fails, the named surfaces are genuinely unrouted — either their brand declares two connectable surfaces (give it a hand-written descriptor) or Task 7 flipped something that should have stayed `notConnectable()`.

- [ ] **Step 3: Reconcile `PlatformControllerConvergenceTest`**

Read it. If it enumerates registry keys against a hand-maintained list, that list is now stale. Replace the hard-coded list with a derived-aware assertion in the same style as Step 1 rather than appending ~86 names by hand — a hand list re-creates the freeze this phase just removed.

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: green. Do not pass `--filter` — it is broken in this repo. This is the first full-suite run of Phase B; budget time for unrelated flakes and confirm any failure is genuinely yours before fixing it.

- [ ] **Step 5: Commit**

```bash
git add tests/
git commit -m "test(platforms): pin brand route coverage against the catalog

Every connectable url-detected surface must resolve to a registry
descriptor. A third multi-surface brand would otherwise be skipped by
DerivedDescriptorFactory's 1:1 rule and lose its routes silently."
```

---

### Task 9: Capability audit and wire documentation

**Files:**
- Modify: `docs/api.md` (the platforms section)
- Modify: `docs/superpowers/specs/2026-08-17-platforms-uniformity-design.md` (status header)

**Interfaces:**
- Consumes: everything above.
- Produces: the wire contract the dashboard lane plans against.

- [ ] **Step 1: Run the capability audit**

Invoke the `account-capability-audit` skill over `app/Services/Platforms/Registry/DerivedDescriptorFactory.php`, `app/Http/Requests/Platforms/BrandConnectRequest.php` and `routes/api/platforms.php`.

This is **not optional**. A derived descriptor missing its routing-class capability mapping opens a booking brand to an account that cannot use booking, and this skill exists for exactly the "new endpoint, forgot the capability gate" case.

- [ ] **Step 2: Fix anything it finds, then re-run it**

Expected: clean.

- [ ] **Step 3: Document the wire**

In `docs/api.md`'s platforms section, add: the four per-brand endpoints and their slug rule (brand key, from the compiled catalog); that the family-wide `DELETE` endpoints remain and mean "remove all of this class"; the 422 shape `BrandConnectRequest` returns on a brand/URL mismatch; and that `GET /catalog/surfaces` is the roster feed, where `isConnectable` now means "has connect routes".

- [ ] **Step 4: Update the spec status header**

Change the spec's status line to record what shipped, with the date and the merge commit.

- [ ] **Step 5: Commit and open the PR**

```bash
git add docs/
git commit -m "docs(platforms): document the per-brand route contract"
git push -u origin feat/platform-brand-routes
```

Open the PR against `development`. In the body, state: the route-count and boot-time deltas from Task 5 Step 7, the approved keep/flip list from Task 7, and that `RegistryCoverageTest`'s freeze was deliberately re-based with the reason from Task 3.

---

## What this plan deliberately does not do

- **No new connectors.** Owner ruling 1.
- **No `DELETE /booking/{resourceId}` / `DELETE /reservations/{resourceId}`.** The brief's W3 existed only as a hedge against Phase B slipping past the dashboard's per-row table. The dashboard can bridge with a shape-mapped disconnect. Reinstate only if the dashboard lane says the bridge is unworkable.
- **No brand icons.** The card roster's real cost is ~86 logos, and `Brand` carries no icon field. Whether to add one is a dashboard-lane decision (spec §7).
- **No `*.square.site` fix.** Named in the Phase 6 checkpoint; separate work.
- **No production changes.**
