# Authorization Matrix (Tier 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gate every push with a test that fires every param-bearing API route cross-tenant, cross-privilege, and as an unclaimed user, and fails when authorization does not reject the request.

**Architecture:** A new Pest suite at `tests/Authz/` running under its own `phpunit.authz.xml`, executed as an extra step in the existing `schema-tests` CI job against a real Postgres with `supabase/migrations/` applied. Route cases are enumerated from the live router at runtime (never a checked-in list); a checked-in `expectations.yaml` supplies fixture mappings and exemptions, each requiring a written reason. A coverage guard fails when any route is neither covered nor excused.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, PostgreSQL 16, `symfony/yaml` (already a direct dependency).

**Spec:** `docs/superpowers/specs/2026-07-30-authz-test-harness-design.md`

## Global Constraints

- **Never create Laravel migration files.** The composer guard rejects them. This plan adds no schema.
- **PHP 8.4**, 4-space indent, LF. Run `php artisan pint` before each commit; keep bugfix commits surgical.
- **The default is inclusion.** A route is never auto-exempted. Exclusion requires an `expect: exempt` entry with a non-empty `reason:`.
- **`422` is a failure, not a pass.** It means validation rejected the request before authorization ran, so the question was never asked.
- **`403` is a failure** on non-owned resources. Project rule: 404 when a resource does not exist or does not belong to the user; 403 only for role/type restrictions.
- **Tier 1 gates from day one.** All triage happens on this branch. `development` never sees a red gate.
- **Do not modify** `tests/Pest.php`, `phpunit.xml`, `tests/TestCase.php`, or the existing `schema-tests` job steps other than appending one step.
- **Branch:** `audit-fix/authz-matrix-tier1-2026-07-30`, cut from `origin/development`.
- **Never `git stash`.** Another session may be working in this checkout; check `git worktree list` and the sibling worktree's status before assuming a file is free.
- **Shared helpers go in a class, never a global function.** Unnamespaced Pest files share one global symbol table, so two test files declaring the same function name is a fatal that aborts the whole run. `authzCase()` and `authzStaffUri()` in this plan are deliberately uniquely named; if you find yourself wanting to share one, move it to a static method on a class under `Tests\Authz\`.

## Concurrency with other sessions

As of 2026-07-30 three sibling worktrees are active: `audit-fix/p0-launch`,
`audit-fix/p1-launch`, and `feat/outbound-http-guard`. None touches this plan's
file set, and `composer.json` / `.github/workflows/ci.yml` are untouched by all
three. Two hazards remain, neither of which git will surface:

1. **`p0-launch` is editing `supabase/migrations/`, including the baseline.**
   Task 1's `fresh-reset.sh` and Task 4's fixture columns both depend on that
   set. If the baseline changes mid-branch, fixtures break for reasons unrelated
   to this work. **Mitigation:** after `p0-launch` merges, rebase and re-run
   Task 4 before trusting any Task 5 result. A fixture failure that appears
   without a local edit is a migration change, not a bug in the harness.

2. **A worktree isolates files, not the database.** `fresh-reset.sh` provisions
   the local stack on the shared 54321-54327 ports (the Comet sibling-stack
   collision). Two sessions resetting concurrently destroy each other's schema.
   **Mitigation:** this lane uses its own database name, never the shared
   `partna_test`:

   ```bash
   createdb partna_authz
   DB_DATABASE=partna_authz scripts/db/apply-migrations.sh
   DB_DATABASE=partna_authz composer test:authz
   ```

   CI is unaffected — the `schema-tests` job owns a private service container
   and shares it with nothing.

**Sequencing:** Tasks 1-9 create only new files and can run now. Task 10's
`ci.yml` edit is a magnet file for guard-style branches; do it last and rebase
onto `origin/development` immediately before committing it.

## File Structure

| File | Responsibility |
|---|---|
| `phpunit.authz.xml` | Lane config. Real Postgres from the environment, same `<php>` env block as `phpunit.schema.xml`. |
| `composer.json` | Adds the `test:authz` script. |
| `.github/workflows/ci.yml` | One step appended to `schema-tests`. |
| `tests/Authz/AuthzTestCase.php` | Base case: pgsql guard, migrations-applied guard, per-test transaction rollback. |
| `tests/Authz/Pest.php` | Binds `AuthzTestCase` to this directory only. |
| `tests/Authz/RouteCase.php` | Value object: one method+URI, its params, and each param's resolved model (or null). |
| `tests/Authz/RouteInventory.php` | Enumerates `api/` routes from the live router and resolves params by reflection. |
| `tests/Authz/Expectations.php` | Loads and validates `expectations.yaml`. |
| `tests/Authz/expectations.yaml` | Fixture mappings and exemptions. The only hand-maintained data file. |
| `tests/Authz/Fixtures.php` | Seeds identities A, B, and unclaimed C once per process; exposes B's row IDs by model. |
| `tests/Authz/CrossTenantTest.php` | A's token against B's IDs → 404. |
| `tests/Authz/StaffBoundaryTest.php` | Staff routes with a plain-user token and with `aal1` staff claims → rejected. |
| `tests/Authz/PublicSurfaceTest.php` | Public routes with an unknown ID → 404, never 403. |
| `tests/Authz/UnclaimedSweepTest.php` | Unclaimed user's token against the authenticated surface → rejected. |
| `tests/Authz/CoverageGuardTest.php` | Every route covered or excused; every exemption carries a reason. |
| `tests/Authz/README.md` | How to run locally, and what to do when it fails. |

---

### Task 1: Lane scaffolding and the `actingAsUser()` spike

The spec names this the first risk: `actingAsUser()` is a Pest global defined in `tests/Pest.php`, and `pest()->extend(TestCase::class)->in('Feature')` binds `Tests\TestCase` to the Feature directory only. Whether the helper is reachable from a suite under a different phpunit config is unverified. Find out before building anything on top of it.

**Files:**
- Create: `phpunit.authz.xml`, `tests/Authz/Pest.php`, `tests/Authz/AuthzTestCase.php`, `tests/Authz/SmokeTest.php`
- Modify: `composer.json`

**Interfaces:**
- Consumes: nothing
- Produces: `Tests\Authz\AuthzTestCase` (abstract base, no public API beyond PHPUnit's); `composer test:authz`

- [ ] **Step 1: Provision a lane-private Postgres with migrations applied**

Use a dedicated database, never the shared `partna_test` — see "Concurrency with other sessions" above. A sibling session resetting `partna_test` mid-run would destroy this lane's schema, and vice versa.

```bash
createdb partna_authz
DB_DATABASE=partna_authz scripts/db/apply-migrations.sh
```

Expected: exits 0. Every subsequent step in this plan needs this database. If it fails, stop — the lane cannot be developed without it.

- [ ] **Step 2: Create the lane config**

Create `phpunit.authz.xml`. Copy the `<php>` block verbatim from `phpunit.schema.xml` — it is pinned deliberately (notably `APP_DEBUG=false`, so 5xx bodies stay generic).

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!--
    Dedicated PHPUnit config for the AUTHORIZATION lane (tests/Authz/, driven by
    tests/Authz/AuthzTestCase.php).

    Shares the applied-migrations database with phpunit.schema.xml but is a
    separate config because the two lanes ask different questions of it: the
    schema lane interrogates DDL via pg_constraint, this lane exercises HTTP.
    Sharing a config would blur two distinct base cases.

    Deliberately does NOT set DB_CONNECTION / DB_HOST / etc — those come from
    the caller's environment, same as phpunit.schema.xml. See its header.
-->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
>
    <testsuites>
        <testsuite name="Authz">
            <directory>tests/Authz</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <ini name="memory_limit" value="512M"/>
        <env name="APIFY_TOKEN" value=""/>
        <env name="APP_ENV" value="testing"/>
        <env name="APP_DEBUG" value="false"/>
        <env name="APP_KEY" value="base64:QEdDiuqdtYmnzFhIZQIIvBjGq6q0H8rMPBn2BEQ7Qss="/>
        <env name="APP_MAINTENANCE_DRIVER" value="file"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="BROADCAST_CONNECTION" value="null"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="MAIL_FROM_ADDRESS" value="hello@partna.au"/>
        <env name="MAIL_FROM_NAME" value="Partna"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="PULSE_ENABLED" value="false"/>
        <env name="TELESCOPE_ENABLED" value="false"/>
        <env name="NIGHTWATCH_ENABLED" value="false"/>
        <env name="MEDIA_DISK_URL" value="https://test-media.partna.local"/>
        <env name="AWS_URL" value="https://test-s3.partna.local"/>
    </php>
</phpunit>
```

- [ ] **Step 3: Create the base case**

Create `tests/Authz/AuthzTestCase.php`. The `unavailable()` pattern is copied from `SchemaTestCase` for the reason its docblock gives: a lane that skips when its database is missing is the same false safety net one level up.

```php
<?php

namespace Tests\Authz;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Base case for the AUTHORIZATION lane (phpunit.authz.xml / `composer
 * test:authz`; see tests/Authz/README.md).
 *
 * Extends the framework TestCase directly, like SchemaTestCase and
 * PostgresTestCase: inheriting Tests\TestCase would reintroduce the
 * unconditional SQLite redirect in its setUp(), and this lane's entire premise
 * is a real Postgres carrying the real schema.
 *
 * Each test runs inside a transaction that is rolled back in tearDown. Cases in
 * this lane deliberately send DELETE and PATCH cross-tenant; when authorization
 * is broken those requests SUCCEED, and without the rollback one real finding
 * would destroy the fixtures every later assertion depends on and cascade into
 * dozens of false ones. Fixtures themselves are seeded and COMMITTED once per
 * process (see Fixtures::ensureSeeded) so they survive that rollback.
 */
abstract class AuthzTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection('pgsql')->getDriverName() !== 'pgsql') {
            $this->unavailable(
                'Authz lane requires DB_CONNECTION=pgsql (see phpunit.authz.xml / composer test:authz).'
            );
        }

        try {
            DB::connection('pgsql')->getPdo();
        } catch (Throwable $e) {
            $this->unavailable('Authz lane requires a reachable Postgres server: '.$e->getMessage());
        }

        $applied = DB::connection('pgsql')->selectOne(
            "SELECT to_regclass('core.users') IS NOT NULL AS ok"
        );

        if (! $applied || ! $applied->ok) {
            $this->unavailable(
                'Authz lane requires the supabase/migrations set to be applied first '
                .'(scripts/db/fresh-reset.sh locally, scripts/db/apply-migrations.sh in CI).'
            );
        }

        DB::connection('pgsql')->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::connection('pgsql')->transactionLevel() > 0) {
            DB::connection('pgsql')->rollBack();
        }

        parent::tearDown();
    }

    /**
     * Preconditions missing: skip locally, FAIL where the lane is declared
     * required (AUTHZ_LANE_REQUIRED=1, set by the CI job). Same reasoning as
     * SchemaTestCase::unavailable — a lane that stands down quietly when its
     * database is not set up reports green while testing nothing.
     */
    private function unavailable(string $why): void
    {
        if (getenv('AUTHZ_LANE_REQUIRED') === '1') {
            $this->fail($why.' (AUTHZ_LANE_REQUIRED=1 — refusing to pass by skipping.)');
        }

        $this->markTestSkipped($why);
    }
}
```

- [ ] **Step 4: Bind the base case to this directory only**

Create `tests/Authz/Pest.php`:

```php
<?php

// Binds AuthzTestCase to this directory only. tests/Pest.php binds
// Tests\TestCase to 'Feature' alone, so nothing here inherits the SQLite
// redirect — but the binding must still be declared explicitly or these files
// run against PHPUnit\Framework\TestCase and never boot the app.
uses(Tests\Authz\AuthzTestCase::class)->in(__DIR__);
```

- [ ] **Step 5: Write the spike test**

Create `tests/Authz/SmokeTest.php`. This is the risk-1 spike: it proves the lane boots, the database is real, and `actingAsUser()` is reachable.

```php
<?php

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;

it('runs against a real Postgres with migrations applied', function () {
    expect(DB::connection('pgsql')->getDriverName())->toBe('pgsql');

    $ok = DB::connection('pgsql')->selectOne(
        "SELECT to_regclass('core.users') IS NOT NULL AS ok"
    );

    expect($ok->ok)->toBeTrue();
});

it('can authenticate a request via actingAsUser (risk 1 spike)', function () {
    $user = User::factory()->create();

    $response = actingAsUser($user)->getJson('/api/me');

    // The assertion is about REACHABILITY, not the payload: any status other
    // than 401 proves the middleware stubs were applied and the route ran as
    // this user. A 401 means actingAsUser() did not take effect in this lane.
    expect($response->status())->not->toBe(401);
});
```

- [ ] **Step 6: Add the composer script**

In `composer.json`, add after the `test:schema` entry:

```json
        "test:authz": [
            "@php artisan config:clear --ansi",
            "@php artisan test -c phpunit.authz.xml"
        ],
```

- [ ] **Step 7: Run the spike**

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=partna_authz \
DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable \
composer test:authz
```

Expected: both tests PASS.

Every later `composer test:authz` in this plan assumes the same environment. Export it once per shell rather than repeating it:

```bash
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=partna_authz \
       DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable
```

**If the second test fails with a 401 or "Call to undefined function actingAsUser":** the helper is not reachable from this lane. Do not work around it by importing `tests/Pest.php`. Instead add a local `tests/Authz/ActsAsIdentity.php` containing a trait with an `actingAs(User $user, array $claims = []): TestCase` method that performs the same two `app()->bind()` calls as `tests/Pest.php:136-168` (binding `VerifySupabaseJwt` and `LoadCurrentUser` to anonymous stubs that set the `supabase_uid`, `supabase_claims`, `supabase_aal`, `supabase_amr`, `supabase_session_id`, and `professional` request attributes), then use that trait from `AuthzTestCase`. Every later task calls `$this->actingAs(...)` either way — record which of the two it resolved to in `tests/Authz/README.md` in Task 10.

- [ ] **Step 8: Commit**

```bash
php artisan pint
git add phpunit.authz.xml composer.json tests/Authz/
git commit -m "test(authz): scaffold the authorization lane and prove actingAsUser reachability"
```

---

### Task 2: Route inventory and reflection

**Files:**
- Create: `tests/Authz/RouteCase.php`, `tests/Authz/RouteInventory.php`, `tests/Authz/RouteInventoryTest.php`

**Interfaces:**
- Consumes: `Tests\Authz\AuthzTestCase`
- Produces:
  - `Tests\Authz\RouteCase` with public readonly `string $method`, `string $uri`, `string $action`, `array $params` (param name → FQCN model or `null`); methods `key(): string` (`"GET api/foo/{id}"`), `pattern(): string` (the URI alone — how `expectations.yaml` addresses a route), `group(): string` (one of `staff`, `public`, `platforms`, `user`), `hasParams(): bool`, `unresolvedParams(): array<int, string>`
  - `Tests\Authz\RouteInventory::all(): array<int, RouteCase>`

- [ ] **Step 1: Write the failing test**

Create `tests/Authz/RouteInventoryTest.php`:

```php
<?php

use Tests\Authz\RouteInventory;

it('enumerates only api routes and skips HEAD/OPTIONS', function () {
    $cases = RouteInventory::all();

    expect($cases)->not->toBeEmpty();

    foreach ($cases as $case) {
        expect($case->uri)->toStartWith('api/');
        expect($case->method)->not->toBeIn(['HEAD', 'OPTIONS']);
    }
});

it('resolves a model-bound param to its FQCN', function () {
    $cases = collect(RouteInventory::all())
        ->filter(fn ($c) => $c->params !== [])
        ->filter(fn ($c) => collect($c->params)->contains(fn ($m) => $m !== null));

    // Measured 2026-07-30: 12 distinct models are reachable via reflection.
    expect($cases)->not->toBeEmpty();

    $models = collect($cases)->flatMap(fn ($c) => array_values($c->params))->filter()->unique();

    expect($models)->each(fn ($m) => expect(is_subclass_of($m->value, Illuminate\Database\Eloquent\Model::class))->toBeTrue());
});

it('reports unresolved params rather than dropping them', function () {
    // api/enquiries/{id} fetches by hand — the param is typed string, so
    // reflection cannot resolve it. It must surface as UNRESOLVED, never as
    // "no params", or the route silently leaves the matrix. This is the exact
    // regression the 2026-07-30 spike caught in the original design.
    $case = collect(RouteInventory::all())
        ->first(fn ($c) => $c->uri === 'api/enquiries/{id}' && $c->method === 'GET');

    expect($case)->not->toBeNull();
    expect($case->unresolvedParams())->toContain('id');
});

it('groups routes by surface', function () {
    $groups = collect(RouteInventory::all())->map(fn ($c) => $c->group())->unique()->values()->all();

    sort($groups);

    expect($groups)->toBe(['platforms', 'public', 'staff', 'user']);
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
composer test:authz -- --filter=RouteInventory
```

Expected: FAIL — `Class "Tests\Authz\RouteInventory" not found`.

- [ ] **Step 3: Write RouteCase**

Create `tests/Authz/RouteCase.php`:

```php
<?php

namespace Tests\Authz;

/**
 * One executable case: a single HTTP method against a single URI pattern,
 * with each {param} resolved to a model FQCN where reflection could manage it.
 */
final class RouteCase
{
    /**
     * @param  array<string, string|null>  $params  param name => model FQCN, or null when unresolved
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly string $action,
        public readonly array $params,
    ) {}

    public function key(): string
    {
        return $this->method.' '.$this->uri;
    }

    /** Pattern key without the method — how expectations.yaml addresses a route. */
    public function pattern(): string
    {
        return $this->uri;
    }

    public function group(): string
    {
        return match (true) {
            str_starts_with($this->uri, 'api/staff') => 'staff',
            str_starts_with($this->uri, 'api/public') => 'public',
            str_starts_with($this->uri, 'api/platforms') => 'platforms',
            default => 'user',
        };
    }

    public function hasParams(): bool
    {
        return $this->params !== [];
    }

    /** @return array<int, string> */
    public function unresolvedParams(): array
    {
        return array_keys(array_filter($this->params, fn ($model) => $model === null));
    }
}
```

- [ ] **Step 4: Write RouteInventory**

Create `tests/Authz/RouteInventory.php`:

```php
<?php

namespace Tests\Authz;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as LaravelRoute;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Derives the route surface from the LIVE router on every run.
 *
 * Deliberately not a checked-in list: a route added to routes/api/*.php is in
 * the matrix on the next run with no registration step, which is the property
 * that stops this harness decaying. Reflection resolves which of identity B's
 * rows to substitute; it does NOT decide whether a route is tested. Everything
 * with a {param} is tested — see the spec's "Why the default is inclusion".
 */
final class RouteInventory
{
    /** @return array<int, RouteCase> */
    public static function all(): array
    {
        $cases = [];

        foreach (app('router')->getRoutes() as $route) {
            /** @var LaravelRoute $route */
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $params = self::resolveParams($route);

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $cases[] = new RouteCase(
                    method: $method,
                    uri: $uri,
                    action: $route->getActionName(),
                    params: $params,
                );
            }
        }

        return $cases;
    }

    /** @return array<string, string|null> */
    private static function resolveParams(LaravelRoute $route): array
    {
        $names = $route->parameterNames();

        if ($names === []) {
            return [];
        }

        $byName = self::modelsInSignature($route->getActionName());

        $params = [];
        foreach ($names as $name) {
            $params[$name] = $byName[$name] ?? null;
        }

        return $params;
    }

    /** @return array<string, string> param name => model FQCN */
    private static function modelsInSignature(string $action): array
    {
        if ($action === 'Closure' || ! str_contains($action, '@')) {
            return [];
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class)) {
            return [];
        }

        try {
            $ref = new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return [];
        }

        $models = [];
        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            if (is_subclass_of($type->getName(), Model::class)) {
                $models[$param->getName()] = $type->getName();
            }
        }

        return $models;
    }
}
```

- [ ] **Step 5: Run the tests**

```bash
composer test:authz -- --filter=RouteInventory
```

Expected: all four PASS.

- [ ] **Step 6: Commit**

```bash
php artisan pint
git add tests/Authz/RouteCase.php tests/Authz/RouteInventory.php tests/Authz/RouteInventoryTest.php
git commit -m "test(authz): enumerate api routes from the live router and resolve params by reflection"
```

---

### Task 3: Expectations file and loader

**Files:**
- Create: `tests/Authz/Expectations.php`, `tests/Authz/expectations.yaml`, `tests/Authz/ExpectationsTest.php`

**Interfaces:**
- Consumes: `RouteCase`
- Produces:
  - `Tests\Authz\Expectations::load(): self`
  - `->entryFor(string $pattern): ?array` returning `['expect' => int|'exempt', 'reason' => ?string, 'fixture' => array<string,string>, 'body' => array]`
  - `->isExempt(string $pattern): bool`
  - `->fixtureFor(string $pattern, string $param): ?string`
  - `->bodyFor(string $pattern): array`
  - Throws `RuntimeException` on a malformed file (unknown key, `exempt` without a reason, empty reason)

- [ ] **Step 1: Write the failing test**

Create `tests/Authz/ExpectationsTest.php`:

```php
<?php

use Tests\Authz\Expectations;

it('loads the checked-in file without error', function () {
    $e = Expectations::load();

    expect($e)->toBeInstanceOf(Expectations::class);
});

it('rejects an exemption with no reason', function () {
    $yaml = <<<'YAML'
    - route: "api/foo/{id}"
      expect: exempt
    YAML;

    expect(fn () => Expectations::fromString($yaml))
        ->toThrow(RuntimeException::class, 'reason');
});

it('rejects an exemption with an empty reason', function () {
    $yaml = <<<'YAML'
    - route: "api/foo/{id}"
      expect: exempt
      reason: "   "
    YAML;

    expect(fn () => Expectations::fromString($yaml))
        ->toThrow(RuntimeException::class, 'reason');
});

it('rejects an unknown key so typos cannot silently do nothing', function () {
    $yaml = <<<'YAML'
    - route: "api/foo/{id}"
      expects: 404
    YAML;

    expect(fn () => Expectations::fromString($yaml))
        ->toThrow(RuntimeException::class, 'expects');
});

it('exposes fixture mappings and bodies', function () {
    $yaml = <<<'YAML'
    - route: "api/enquiries/{id}"
      fixture: { id: "App\\Models\\Core\\Site\\Enquiry" }
    - route: "api/routing/connections/{connection}/primary"
      fixture: { connection: "App\\Models\\Core\\User\\Service" }
      body: { primary: true }
    YAML;

    $e = Expectations::fromString($yaml);

    expect($e->fixtureFor('api/enquiries/{id}', 'id'))
        ->toBe('App\Models\Core\Site\Enquiry');
    expect($e->bodyFor('api/routing/connections/{connection}/primary'))
        ->toBe(['primary' => true]);
    expect($e->bodyFor('api/enquiries/{id}'))->toBe([]);
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
composer test:authz -- --filter=Expectations
```

Expected: FAIL — `Class "Tests\Authz\Expectations" not found`.

- [ ] **Step 3: Create the (initially near-empty) data file**

Create `tests/Authz/expectations.yaml`:

```yaml
# Fixture mappings and exemptions for the authorization matrix.
#
# THE DEFAULT IS INCLUSION. Every api/ route carrying a {param} is fired
# cross-tenant. This file exists for two things only:
#
#   fixture:  which of identity B's rows to substitute, when reflection could
#             not work it out from the controller signature.
#   expect:   exempt  — this route is genuinely not tenant-scoped.
#             A `reason:` is MANDATORY and must say why. "Not applicable" is
#             not a reason; name the thing the param actually identifies.
#
# Optional `body:` supplies a minimal request body for write routes whose
# validation would otherwise reject the request with 422 before authorization
# ran. A 422 is scored as a FAILURE, not a pass — see the spec.
#
# Patterns are literal. Globs are deliberately not supported: a glob that
# quietly captures a route it should not is the exact failure mode this
# harness exists to prevent.

- route: "api/health"
  expect: exempt
  reason: "Liveness probe, unauthenticated by design — no tenant resource."
```

- [ ] **Step 4: Write the loader**

Create `tests/Authz/Expectations.php`:

```php
<?php

namespace Tests\Authz;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads tests/Authz/expectations.yaml — the only hand-maintained data in this
 * lane.
 *
 * Validation is strict and fails the run rather than warning: an unknown key is
 * a typo that would otherwise silently do nothing, and an exemption without a
 * reason is how a suppression file becomes unreviewable six months later.
 */
final class Expectations
{
    private const KNOWN_KEYS = ['route', 'expect', 'reason', 'fixture', 'body'];

    /** @param array<string, array<string, mixed>> $entries pattern => entry */
    private function __construct(private readonly array $entries) {}

    public static function load(): self
    {
        $path = __DIR__.'/expectations.yaml';

        if (! is_file($path)) {
            throw new RuntimeException("Missing expectations file: {$path}");
        }

        return self::fromString((string) file_get_contents($path));
    }

    public static function fromString(string $yaml): self
    {
        $parsed = Yaml::parse($yaml) ?? [];

        if (! is_array($parsed)) {
            throw new RuntimeException('expectations.yaml must be a list of entries.');
        }

        $entries = [];

        foreach ($parsed as $i => $entry) {
            if (! is_array($entry) || ! isset($entry['route'])) {
                throw new RuntimeException("expectations.yaml entry #{$i} has no `route:` key.");
            }

            $route = (string) $entry['route'];

            foreach (array_keys($entry) as $key) {
                if (! in_array($key, self::KNOWN_KEYS, true)) {
                    throw new RuntimeException(
                        "expectations.yaml entry `{$route}` has unknown key `{$key}`. "
                        .'Known keys: '.implode(', ', self::KNOWN_KEYS).'.'
                    );
                }
            }

            if (($entry['expect'] ?? null) === 'exempt') {
                $reason = trim((string) ($entry['reason'] ?? ''));

                if ($reason === '') {
                    throw new RuntimeException(
                        "expectations.yaml entry `{$route}` is exempt but has no `reason:`. "
                        .'Say what the param actually identifies and why it is not tenant-scoped.'
                    );
                }
            }

            if (isset($entries[$route])) {
                throw new RuntimeException("expectations.yaml has duplicate entries for `{$route}`.");
            }

            $entries[$route] = $entry;
        }

        return new self($entries);
    }

    /** @return array<string, mixed>|null */
    public function entryFor(string $pattern): ?array
    {
        return $this->entries[$pattern] ?? null;
    }

    public function isExempt(string $pattern): bool
    {
        return ($this->entries[$pattern]['expect'] ?? null) === 'exempt';
    }

    public function fixtureFor(string $pattern, string $param): ?string
    {
        $fixture = $this->entries[$pattern]['fixture'] ?? [];

        return isset($fixture[$param]) ? (string) $fixture[$param] : null;
    }

    /** @return array<string, mixed> */
    public function bodyFor(string $pattern): array
    {
        return (array) ($this->entries[$pattern]['body'] ?? []);
    }

    /** @return array<int, string> every pattern named in the file */
    public function patterns(): array
    {
        return array_keys($this->entries);
    }
}
```

- [ ] **Step 5: Run the tests**

```bash
composer test:authz -- --filter=Expectations
```

Expected: all five PASS.

- [ ] **Step 6: Commit**

```bash
php artisan pint
git add tests/Authz/Expectations.php tests/Authz/expectations.yaml tests/Authz/ExpectationsTest.php
git commit -m "test(authz): add the expectations loader with mandatory exemption reasons"
```

---

### Task 4: Identity fixtures

Three identities: **A** (the attacker's token), **B** (the victim, owning one row of every substitutable model), and **C** (an unclaimed provisional user, `status='unclaimed'`, null email).

Seeded once per process and **committed**, so they survive the per-test transaction rollback in `AuthzTestCase`.

**Files:**
- Create: `tests/Authz/Fixtures.php`, `tests/Authz/FixturesTest.php`

**Interfaces:**
- Consumes: `AuthzTestCase`
- Produces:
  - `Tests\Authz\Fixtures::ensureSeeded(): void` — idempotent, safe to call from every test
  - `Fixtures::identityA(): User`, `Fixtures::identityB(): User`, `Fixtures::unclaimed(): User`
  - `Fixtures::idFor(string $modelFqcn): ?string` — B's row id for that model, or null if unseeded
  - `Fixtures::seededModels(): array<int, string>` — every model FQCN with a seeded B row

- [ ] **Step 1: Write the failing test**

Create `tests/Authz/FixturesTest.php`:

```php
<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\Customer;
use App\Models\Core\User\User;
use Tests\Authz\Fixtures;

it('seeds three distinct identities', function () {
    Fixtures::ensureSeeded();

    $a = Fixtures::identityA();
    $b = Fixtures::identityB();
    $c = Fixtures::unclaimed();

    expect($a->id)->not->toBe($b->id);
    expect($c->status)->toBe('unclaimed');
    expect($c->primary_email)->toBeNull();
});

it('gives identity B a row for each seeded model', function () {
    Fixtures::ensureSeeded();

    expect(Fixtures::idFor(Site::class))->not->toBeNull();
    expect(Fixtures::idFor(Customer::class))->not->toBeNull();
});

it('is idempotent', function () {
    Fixtures::ensureSeeded();
    $first = Fixtures::identityB()->id;

    Fixtures::ensureSeeded();

    expect(Fixtures::identityB()->id)->toBe($first);
});

it('survives a rolled-back transaction', function () {
    Fixtures::ensureSeeded();
    $id = Fixtures::identityB()->id;

    // AuthzTestCase already opened a transaction for this test; the fixtures
    // were committed before it, so they are visible and will still exist after
    // this test's rollback. If this fails, ensureSeeded is running INSIDE the
    // per-test transaction and every case after the first will see no fixtures.
    expect(User::query()->find($id))->not->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
composer test:authz -- --filter=Fixtures
```

Expected: FAIL — `Class "Tests\Authz\Fixtures" not found`.

- [ ] **Step 3: Write the fixtures**

Create `tests/Authz/Fixtures.php`. Seed the twelve reflection-reachable models plus `Site` and `Enquiry` (named by hand-written mappings in Task 9). Only `User`, `Site`, `PartnaStaff` and `PreAccountBuild` have factories under `database/factories/`; the rest are built explicitly.

```php
<?php

namespace Tests\Authz;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\Customer;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;

/**
 * Identity fixtures for the authorization matrix.
 *
 * Seeded ONCE per process and committed OUTSIDE the per-test transaction that
 * AuthzTestCase opens. That ordering is load-bearing: seeding inside the
 * transaction would make the fixtures vanish on the first rollback, and every
 * subsequent case would 404 for the wrong reason — a green matrix that proved
 * nothing.
 *
 * Rows are left behind after a run. That is intentional and safe: CI uses a
 * throwaway service container, and locally the lane's README tells you to
 * re-run scripts/db/fresh-reset.sh.
 */
final class Fixtures
{
    private static ?User $a = null;

    private static ?User $b = null;

    private static ?User $c = null;

    /** @var array<string, string> model FQCN => B's row id */
    private static array $ids = [];

    public static function ensureSeeded(): void
    {
        if (self::$b !== null) {
            return;
        }

        // Commit any transaction AuthzTestCase opened, seed, then reopen — so
        // the fixtures are durable and the test still runs inside a rollback.
        $connection = DB::connection('pgsql');
        $reopen = $connection->transactionLevel() > 0;

        if ($reopen) {
            $connection->commit();
        }

        self::$a = self::makeUser('authz-a');
        self::$b = self::makeUser('authz-b');
        self::$c = self::makeUnclaimed('authz-c');

        self::seedOwnedBy(self::$b);

        if ($reopen) {
            $connection->beginTransaction();
        }
    }

    public static function identityA(): User
    {
        self::ensureSeeded();

        return self::$a;
    }

    public static function identityB(): User
    {
        self::ensureSeeded();

        return self::$b;
    }

    public static function unclaimed(): User
    {
        self::ensureSeeded();

        return self::$c;
    }

    public static function idFor(string $modelFqcn): ?string
    {
        self::ensureSeeded();

        return self::$ids[ltrim($modelFqcn, '\\')] ?? null;
    }

    /** @return array<int, string> */
    public static function seededModels(): array
    {
        self::ensureSeeded();

        return array_keys(self::$ids);
    }

    private static function makeUser(string $handle): User
    {
        return User::factory()->create([
            'handle' => $handle,
            'primary_email' => $handle.'@authz.test',
            'status' => 'active',
        ]);
    }

    private static function makeUnclaimed(string $handle): User
    {
        return User::factory()->create([
            'handle' => $handle,
            'primary_email' => null,
            'status' => 'unclaimed',
            'auth_user_id' => null,
        ]);
    }

    private static function seedOwnedBy(User $user): void
    {
        $site = Site::factory()->create(['user_id' => $user->id]);
        self::remember(Site::class, $site->id);

        $customer = new Customer(['display_name' => 'Authz Victim']);
        $customer->user()->associate($user);
        $customer->save();
        self::remember(Customer::class, $customer->id);

        $enquiry = new Enquiry(['message' => 'authz fixture']);
        $enquiry->user()->associate($user);
        $enquiry->site()->associate($site);
        $enquiry->save();
        self::remember(Enquiry::class, $enquiry->id);

        $category = new ServiceCategory(['name' => 'Authz Category']);
        $category->user()->associate($user);
        $category->save();
        self::remember(ServiceCategory::class, $category->id);

        $service = new Service(['name' => 'Authz Service']);
        $service->user()->associate($user);
        $service->save();
        self::remember(Service::class, $service->id);

        $block = new Block(['type' => 'text']);
        $block->site()->associate($site);
        $block->save();
        self::remember(Block::class, $block->id);

        $media = new SiteMedia(['pool' => 'gallery']);
        $media->user()->associate($user);
        $media->site()->associate($site);
        $media->save();
        self::remember(SiteMedia::class, $media->id);

        // Identity B is itself the substitutable row for {user} params.
        self::remember(User::class, $user->id);
    }

    private static function remember(string $modelFqcn, string $id): void
    {
        self::$ids[ltrim($modelFqcn, '\\')] = $id;
    }
}
```

- [ ] **Step 4: Run the tests and fix column drift**

```bash
composer test:authz -- --filter=Fixtures
```

Expected: PASS. Real Postgres enforces NOT NULL and CHECK constraints that SQLite would not, so failures here are almost certainly missing required columns. Read the actual DDL in `supabase/migrations/20260726000000_baseline_pilot.sql` for the failing table and add the columns — do **not** guess from the model's `$fillable`.

Never assign a tenancy FK through mass assignment; `user_id` and `site_id` are not fillable by project rule. Use `->relation()->associate()` as above.

- [ ] **Step 5: Commit**

```bash
php artisan pint
git add tests/Authz/Fixtures.php tests/Authz/FixturesTest.php
git commit -m "test(authz): seed identity A, victim B, and an unclaimed C outside the per-test transaction"
```

---

### Task 5: The cross-tenant matrix

The core deliverable. Fires every param-bearing user/platforms route with A's token and B's IDs.

**Files:**
- Create: `tests/Authz/Verdict.php`, `tests/Authz/CrossTenantTest.php`

**Interfaces:**
- Consumes: `RouteInventory`, `RouteCase`, `Expectations`, `Fixtures`
- Produces: `Tests\Authz\Verdict::describe(int $status, RouteCase $case): ?string` — returns null when the status is acceptable, or a full failure message naming the route, observed status, expected status, and the file to edit

- [ ] **Step 1: Write the failing test for the verdict logic**

Create `tests/Authz/VerdictTest.php`:

```php
<?php

use Tests\Authz\RouteCase;
use Tests\Authz\Verdict;

function authzCase(): RouteCase
{
    return new RouteCase('PATCH', 'api/site/sections/{section}', 'X@update', ['section' => null]);
}

it('accepts a 404', function () {
    expect(Verdict::describe(404, authzCase()))->toBeNull();
});

it('rejects a 200 as cross-tenant access', function () {
    expect(Verdict::describe(200, authzCase()))->toContain('cross-tenant');
});

it('rejects a 403 as an enumeration leak', function () {
    expect(Verdict::describe(403, authzCase()))->toContain('403');
});

it('rejects a 422 as inconclusive', function () {
    expect(Verdict::describe(422, authzCase()))->toContain('inconclusive');
});

it('rejects a 500', function () {
    expect(Verdict::describe(500, authzCase()))->toContain('500');
});

it('names the route and the file to edit in every failure', function () {
    foreach ([200, 403, 422, 500] as $status) {
        $message = Verdict::describe($status, authzCase());

        expect($message)->toContain('PATCH api/site/sections/{section}');
        expect($message)->toContain('tests/Authz/expectations.yaml');
    }
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
composer test:authz -- --filter=Verdict
```

Expected: FAIL — `Class "Tests\Authz\Verdict" not found`.

- [ ] **Step 3: Write Verdict**

Create `tests/Authz/Verdict.php`:

```php
<?php

namespace Tests\Authz;

/**
 * Scores one cross-tenant response.
 *
 * Failure messages are part of the design, not polish. A developer meets this
 * lane for the first time as a red build on an unrelated PR; a message that
 * says only "route not covered" gets resolved by adding a blanket exemption.
 * Every message names the route, the observed status, what was expected, and
 * the file to edit.
 */
final class Verdict
{
    public static function describe(int $status, RouteCase $case): ?string
    {
        if ($status === 404) {
            return null;
        }

        $why = match (true) {
            $status >= 200 && $status < 300 => 'cross-tenant access — identity A read or modified identity B\'s row',
            $status === 403 => '403 leaks existence; project rule is 404 for resources that do not belong to the caller '
                .'(403 is reserved for role/type restrictions)',
            $status === 422 => 'inconclusive — validation rejected the request before authorization ran, so the '
                .'authorization question was never asked. Add a `body:` to the expectations entry so the request '
                .'reaches the policy',
            $status === 401 => 'unexpected 401 — the test identity was not authenticated; this is a harness fault, '
                .'not a finding',
            $status >= 500 => $status.' — application or harness defect; check the exception before triaging',
            default => 'unexpected status '.$status,
        };

        return sprintf(
            "AUTHZ %s\n  expected: 404\n  observed: %d\n  reason:   %s\n  fix:      tests/Authz/expectations.yaml",
            $case->key(),
            $status,
            $why,
        );
    }
}
```

- [ ] **Step 4: Run the verdict tests**

```bash
composer test:authz -- --filter=Verdict
```

Expected: all six PASS.

- [ ] **Step 5: Write the matrix test**

Create `tests/Authz/CrossTenantTest.php`:

```php
<?php

use Tests\Authz\Expectations;
use Tests\Authz\Fixtures;
use Tests\Authz\RouteCase;
use Tests\Authz\RouteInventory;
use Tests\Authz\Verdict;

/**
 * Every param-bearing user/platforms route, fired with identity A's token and
 * identity B's row ids. A response that is not 404 is a finding.
 */
dataset('crossTenantCases', function () {
    $expectations = Expectations::load();

    return collect(RouteInventory::all())
        ->filter(fn (RouteCase $c) => in_array($c->group(), ['user', 'platforms'], true))
        ->filter(fn (RouteCase $c) => $c->hasParams())
        ->reject(fn (RouteCase $c) => $expectations->isExempt($c->pattern()))
        ->mapWithKeys(fn (RouteCase $c) => [$c->key() => [$c]])
        ->all();
});

it('refuses identity A access to identity B resources', function (RouteCase $case) {
    Fixtures::ensureSeeded();
    $expectations = Expectations::load();

    $uri = $case->uri;

    foreach ($case->params as $param => $model) {
        $model ??= $expectations->fixtureFor($case->pattern(), $param);

        expect($model)->not->toBeNull(
            "AUTHZ {$case->key()}\n"
            ."  param `{$param}` could not be resolved to a model by reflection and has no\n"
            ."  `fixture:` mapping. Read the controller to see which model it fetches, then add:\n"
            ."    - route: \"{$case->pattern()}\"\n"
            ."      fixture: {{$param}: <Model FQCN>}\n"
            ."  ...or exempt it with a reason. fix: tests/Authz/expectations.yaml"
        );

        $id = Fixtures::idFor($model);

        expect($id)->not->toBeNull(
            "AUTHZ {$case->key()}\n"
            ."  no seeded fixture row for {$model}. Add one to tests/Authz/Fixtures::seedOwnedBy()."
        );

        $uri = str_replace(['{'.$param.'}', '{'.$param.'?}'], $id, $uri);
    }

    $response = actingAsUser(Fixtures::identityA())->json(
        $case->method,
        '/'.$uri,
        $expectations->bodyFor($case->pattern()),
    );

    $failure = Verdict::describe($response->status(), $case);

    expect($failure)->toBeNull($failure ?? '');
})->with('crossTenantCases');
```

- [ ] **Step 6: Run it — expect a large number of failures**

```bash
composer test:authz -- --filter=CrossTenant
```

Expected: FAIL, loudly. The measured shape as of 2026-07-30 is ~98 cases, of which the 78 unresolved patterns will fail on the "could not be resolved" assertion. **This is the correct first-run state.** Do not weaken any assertion to make it green. Triage happens in Task 9.

- [ ] **Step 7: Commit the harness with its failures unresolved**

```bash
php artisan pint
git add tests/Authz/Verdict.php tests/Authz/VerdictTest.php tests/Authz/CrossTenantTest.php
git commit -m "test(authz): add the cross-tenant matrix (red pending fixture mappings)"
```

---

### Task 6: Coverage guard

**Files:**
- Create: `tests/Authz/CoverageGuardTest.php`

**Interfaces:**
- Consumes: `RouteInventory`, `Expectations`, `Fixtures`
- Produces: nothing consumed by later tasks

- [ ] **Step 1: Write the test**

Create `tests/Authz/CoverageGuardTest.php`:

```php
<?php

use Tests\Authz\Expectations;
use Tests\Authz\Fixtures;
use Tests\Authz\RouteCase;
use Tests\Authz\RouteInventory;

/**
 * Completeness, not correctness. The matrix proves routes behave; this proves
 * no route escaped the matrix. A new route added to routes/api/*.php lands here
 * first.
 */
it('has a fixture mapping or a written exemption for every param-bearing route', function () {
    $expectations = Expectations::load();

    $unclassified = collect(RouteInventory::all())
        ->filter(fn (RouteCase $c) => $c->hasParams())
        ->reject(fn (RouteCase $c) => $expectations->isExempt($c->pattern()))
        ->filter(function (RouteCase $c) use ($expectations) {
            foreach ($c->unresolvedParams() as $param) {
                if ($expectations->fixtureFor($c->pattern(), $param) === null) {
                    return true;
                }
            }

            return false;
        })
        ->map(fn (RouteCase $c) => $c->key())
        ->unique()
        ->values()
        ->all();

    expect($unclassified)->toBe([], sprintf(
        "%d route(s) are neither mapped nor exempted.\n\n%s\n\n"
        ."Each needs ONE of:\n"
        ."  - route: \"<pattern>\"\n"
        ."    fixture: {<param>: <Model FQCN>}      # which of identity B's rows to substitute\n"
        ."  - route: \"<pattern>\"\n"
        ."    expect: exempt\n"
        ."    reason: \"<why this param is not a tenant-owned resource>\"\n\n"
        .'fix: tests/Authz/expectations.yaml',
        count($unclassified),
        implode("\n", array_map(fn ($k) => '  '.$k, $unclassified)),
    ));
});

it('has no stale entries naming routes that no longer exist', function () {
    $patterns = collect(RouteInventory::all())->map(fn (RouteCase $c) => $c->pattern())->unique();

    $stale = collect(Expectations::load()->patterns())
        ->reject(fn (string $p) => $patterns->contains($p))
        ->values()
        ->all();

    expect($stale)->toBe([], sprintf(
        "expectations.yaml names %d route(s) that no longer exist:\n%s\n\n"
        .'Delete them — a stale exemption silently excuses nothing and hides that the route moved. '
        .'fix: tests/Authz/expectations.yaml',
        count($stale),
        implode("\n", array_map(fn ($p) => '  '.$p, $stale)),
    ));
});

it('has a seeded fixture row for every model the matrix substitutes', function () {
    $expectations = Expectations::load();
    $seeded = Fixtures::seededModels();

    $needed = collect(RouteInventory::all())
        ->filter(fn (RouteCase $c) => $c->hasParams())
        ->reject(fn (RouteCase $c) => $expectations->isExempt($c->pattern()))
        ->flatMap(function (RouteCase $c) use ($expectations) {
            $models = [];
            foreach ($c->params as $param => $model) {
                $model ??= $expectations->fixtureFor($c->pattern(), $param);
                if ($model !== null) {
                    $models[] = ltrim($model, '\\');
                }
            }

            return $models;
        })
        ->unique()
        ->values();

    $missing = $needed->reject(fn (string $m) => in_array($m, $seeded, true))->values()->all();

    expect($missing)->toBe([], sprintf(
        "No seeded identity-B row for %d model(s):\n%s\n\n"
        .'fix: add them to tests/Authz/Fixtures::seedOwnedBy()',
        count($missing),
        implode("\n", array_map(fn ($m) => '  '.$m, $missing)),
    ));
});
```

- [ ] **Step 2: Run it**

```bash
composer test:authz -- --filter=CoverageGuard
```

Expected: FAIL on the first assertion, listing the 78 unmapped patterns. That list **is the Task 9 worklist** — save it:

```bash
composer test:authz -- --filter=CoverageGuard > /tmp/authz-worklist.txt 2>&1 || true
```

- [ ] **Step 3: Commit**

```bash
php artisan pint
git add tests/Authz/CoverageGuardTest.php
git commit -m "test(authz): add the coverage guard so a new route cannot land uncovered"
```

---

### Task 7: Staff privilege boundary

**Files:**
- Create: `tests/Authz/StaffBoundaryTest.php`

**Interfaces:**
- Consumes: `RouteInventory`, `Fixtures`, `Expectations`
- Produces: nothing consumed by later tasks

- [ ] **Step 1: Write the test**

Create `tests/Authz/StaffBoundaryTest.php`:

```php
<?php

use Tests\Authz\Expectations;
use Tests\Authz\Fixtures;
use Tests\Authz\RouteCase;
use Tests\Authz\RouteInventory;

/**
 * Staff routes fired with (a) a plain user's token and (b) staff-shaped claims
 * at aal1. Both must be rejected. Unlike the cross-tenant matrix this covers
 * routes with NO params too — a staff route that leaks to a normal user leaks
 * whether or not it takes an id.
 *
 * Measured 2026-07-30: 104 staff routes, 81 of them param-bearing.
 */
dataset('staffRoutes', function () {
    $expectations = Expectations::load();

    return collect(RouteInventory::all())
        ->filter(fn (RouteCase $c) => $c->group() === 'staff')
        ->reject(fn (RouteCase $c) => $expectations->isExempt($c->pattern()))
        ->mapWithKeys(fn (RouteCase $c) => [$c->key() => [$c]])
        ->all();
});

/** Substitute identity B's ids where we can; an unknown uuid elsewhere. */
function authzStaffUri(RouteCase $case, Expectations $expectations): string
{
    $uri = $case->uri;

    foreach ($case->params as $param => $model) {
        $model ??= $expectations->fixtureFor($case->pattern(), $param);
        $id = $model !== null ? Fixtures::idFor($model) : null;

        $uri = str_replace(
            ['{'.$param.'}', '{'.$param.'?}'],
            $id ?? '00000000-0000-4000-8000-000000000000',
            $uri,
        );
    }

    return '/'.$uri;
}

it('refuses a plain user token on staff routes', function (RouteCase $case) {
    Fixtures::ensureSeeded();

    $response = actingAsUser(Fixtures::identityA())
        ->json($case->method, authzStaffUri($case, Expectations::load()));

    expect($response->status())->toBeIn([401, 403, 404], sprintf(
        "AUTHZ %s\n  expected: 401/403/404 for a non-staff caller\n  observed: %d\n"
        .'  fix:      tests/Authz/expectations.yaml',
        $case->key(),
        $response->status(),
    ));
})->with('staffRoutes');

it('refuses staff claims at aal1 on staff routes', function (RouteCase $case) {
    Fixtures::ensureSeeded();

    // Staff identity is a core.partna_staff row, but AAL is a JWT claim — a
    // staff member who has not completed MFA carries aal1. require.aal2 must
    // reject regardless of the staff row.
    $response = actingAsUser(Fixtures::identityA(), ['aal' => 'aal1', 'amr' => []])
        ->json($case->method, authzStaffUri($case, Expectations::load()));

    expect($response->status())->toBeIn([401, 403, 404], sprintf(
        "AUTHZ %s\n  expected: 401/403/404 for an aal1 caller\n  observed: %d\n"
        .'  fix:      tests/Authz/expectations.yaml',
        $case->key(),
        $response->status(),
    ));
})->with('staffRoutes');
```

- [ ] **Step 2: Run it**

```bash
composer test:authz -- --filter=StaffBoundary
```

Expected: mostly PASS. Any failure is a staff route reachable by a normal user — treat as a **P0 finding**, not a triage entry. Stop and report it before continuing.

- [ ] **Step 3: Commit**

```bash
php artisan pint
git add tests/Authz/StaffBoundaryTest.php
git commit -m "test(authz): assert staff routes reject plain-user and aal1 callers"
```

---

### Task 8: Public surface and unclaimed sweep

**Files:**
- Create: `tests/Authz/PublicSurfaceTest.php`, `tests/Authz/UnclaimedSweepTest.php`

**Interfaces:**
- Consumes: `RouteInventory`, `Fixtures`, `Expectations`
- Produces: nothing consumed by later tasks

- [ ] **Step 1: Write the public surface test**

Create `tests/Authz/PublicSurfaceTest.php`:

```php
<?php

use Tests\Authz\Expectations;
use Tests\Authz\RouteCase;
use Tests\Authz\RouteInventory;

/**
 * Public endpoints must answer 404 for an unknown id, never 403. A 403 on a
 * public surface confirms the resource exists, which turns the endpoint into an
 * enumeration oracle — the project rule in CLAUDE.md ("Public endpoints: always
 * 404").
 */
dataset('publicParamRoutes', function () {
    $expectations = Expectations::load();

    return collect(RouteInventory::all())
        ->filter(fn (RouteCase $c) => $c->group() === 'public')
        ->filter(fn (RouteCase $c) => $c->hasParams())
        ->reject(fn (RouteCase $c) => $expectations->isExempt($c->pattern()))
        ->mapWithKeys(fn (RouteCase $c) => [$c->key() => [$c]])
        ->all();
});

it('answers 404 and never 403 for an unknown public id', function (RouteCase $case) {
    $uri = $case->uri;

    foreach (array_keys($case->params) as $param) {
        $uri = str_replace(
            ['{'.$param.'}', '{'.$param.'?}'],
            'authz-does-not-exist',
            $uri,
        );
    }

    $response = $this->json($case->method, '/'.$uri, Expectations::load()->bodyFor($case->pattern()));

    expect($response->status())->not->toBe(403, sprintf(
        "AUTHZ %s\n  observed 403 on a PUBLIC route — this confirms the resource exists and\n"
        ."  enables enumeration. Return 404 instead.\n  fix: the controller, not the expectations file",
        $case->key(),
    ));
})->with('publicParamRoutes');
```

- [ ] **Step 2: Write the unclaimed sweep**

Create `tests/Authz/UnclaimedSweepTest.php`:

```php
<?php

use Tests\Authz\Expectations;
use Tests\Authz\Fixtures;
use Tests\Authz\RouteCase;
use Tests\Authz\RouteInventory;

/**
 * A provisional (status='unclaimed') user has no auth identity and no email.
 * AccountCapabilities is supposed to fail closed for them on every capability-
 * gated route. That is enforced by convention across ~380 authenticated routes
 * and by nothing mechanical — this sweep is the mechanical part.
 *
 * Scope note: this asserts the request does not SUCCEED. It deliberately does
 * not assert a specific status, because the correct rejection differs by route
 * (403 for a capability gate, 422 for a feature-availability gate, 404 for a
 * missing resource). A 2xx is the finding.
 */
dataset('authenticatedRoutes', function () {
    $expectations = Expectations::load();

    return collect(RouteInventory::all())
        ->reject(fn (RouteCase $c) => $c->group() === 'public')
        ->reject(fn (RouteCase $c) => $c->group() === 'staff')
        ->reject(fn (RouteCase $c) => $expectations->isExempt($c->pattern()))
        ->mapWithKeys(fn (RouteCase $c) => [$c->key() => [$c]])
        ->all();
});

it('does not let an unclaimed user succeed on the authenticated surface', function (RouteCase $case) {
    Fixtures::ensureSeeded();
    $expectations = Expectations::load();

    $uri = $case->uri;

    foreach ($case->params as $param => $model) {
        $model ??= $expectations->fixtureFor($case->pattern(), $param);
        $id = $model !== null ? Fixtures::idFor($model) : null;

        $uri = str_replace(
            ['{'.$param.'}', '{'.$param.'?}'],
            $id ?? '00000000-0000-4000-8000-000000000000',
            $uri,
        );
    }

    $response = actingAsUser(Fixtures::unclaimed())
        ->json($case->method, '/'.$uri, $expectations->bodyFor($case->pattern()));

    expect($response->status())->toBeGreaterThanOrEqual(400, sprintf(
        "AUTHZ %s\n  an UNCLAIMED (provisional) user got %d.\n"
        ."  AccountCapabilities is expected to fail closed here.\n"
        .'  fix: add the capability gate to the controller, or exempt with a reason in tests/Authz/expectations.yaml',
        $case->key(),
        $response->status(),
    ));
})->with('authenticatedRoutes');
```

- [ ] **Step 3: Run both**

```bash
composer test:authz -- --filter='PublicSurface|UnclaimedSweep'
```

Expected: the public surface should be clean. The unclaimed sweep is the one the spec predicts will find something. Any 2xx is a real finding — record it, do not exempt it.

- [ ] **Step 4: Commit**

```bash
php artisan pint
git add tests/Authz/PublicSurfaceTest.php tests/Authz/UnclaimedSweepTest.php
git commit -m "test(authz): add the public 404-not-403 check and the unclaimed capability sweep"
```

---

### Task 9: Triage — the 78 mappings

The branch's main cost. Work the list saved in Task 6.

**Files:**
- Modify: `tests/Authz/expectations.yaml`, `tests/Authz/Fixtures.php` (as new models are needed)

**Interfaces:**
- Consumes: everything above
- Produces: a green `composer test:authz`

- [ ] **Step 1: Work the list, one route at a time**

For each entry in `/tmp/authz-worklist.txt`, open the controller named by the route's action and read what the param actually identifies. Then add exactly one of:

```yaml
# The param names a tenant-owned row the controller fetches by hand:
- route: "api/enquiries/{id}"
  fixture: { id: "App\\Models\\Core\\Site\\Enquiry" }

# The param names something that is not a tenant resource:
- route: "api/platforms/menu/categories/{category}"
  expect: exempt
  reason: "Menu category slug, resolved within the caller's own site — not an id."
```

Rules while triaging:

- **Read the controller. Do not infer from the URI.** `{id}` on `api/platforms/eventbrite/accounts/{id}` may be an integration-connection row, an external Eventbrite id, or a local mirror row. These are different fixtures and one of them is not tenant-scoped at all.
- **Never exempt to make a test green.** An exemption is a claim that the route is not tenant-scoped. If it *is* tenant-scoped and returns 200, that is a finding — record it in the branch's notes and fix it.
- **A `reason:` names what the param identifies.** "Not applicable" and "global" are rejected in review; "Menu category slug, resolved within the caller's own site" is a reason.
- **New model needed?** Add it to `Fixtures::seedOwnedBy()` and check the real DDL in `supabase/migrations/20260726000000_baseline_pilot.sql` for NOT NULL columns before assuming a two-field insert works.

- [ ] **Step 2: Re-run the coverage guard after every ~10 entries**

```bash
composer test:authz -- --filter=CoverageGuard
```

Expected: the unclassified list shrinks. Commit in batches so a bad mapping is easy to bisect:

```bash
git add tests/Authz/expectations.yaml tests/Authz/Fixtures.php
git commit -m "test(authz): map the api/platforms fixture surface"
```

- [ ] **Step 3: Run the full lane**

```bash
composer test:authz
```

Expected: green, or a short list of genuine findings.

- [ ] **Step 4: Handle genuine findings**

A route that returns 2xx cross-tenant, or lets an unclaimed user through, is a bug in `app/`, not in the harness.

**Blocker gate applies** (CLAUDE.md, `scripts/audit/fix-flow.md`): anything touching auth, money, a migration, or the public wire needs a written plan and sign-off before the fix. Report findings and wait; do not fix them inline in this branch unless told to.

- [ ] **Step 5: Commit the triage**

```bash
php artisan pint
git add tests/Authz/
git commit -m "test(authz): complete the fixture mapping triage"
```

---

### Task 10: CI wiring, docs, and the gate

**Files:**
- Modify: `.github/workflows/ci.yml`
- Create: `tests/Authz/README.md`

- [ ] **Step 1: Append the CI step**

In `.github/workflows/ci.yml`, in the `schema-tests` job, immediately after the `Run applied-schema lane` step:

```yaml
      # Authorization matrix (tests/Authz/). Runs against the same migrated
      # database the step above provisioned — the expensive apply is already
      # paid for. AUTHZ_LANE_REQUIRED=1 makes a missing precondition a RED
      # build rather than a silent skip, same contract as SCHEMA_LANE_REQUIRED.
      - name: Run authorization lane
        env:
          AUTHZ_LANE_REQUIRED: '1'
        run: composer test:authz
```

- [ ] **Step 2: Write the lane README**

Create `tests/Authz/README.md`:

```markdown
# tests/Authz/ — authorization matrix

Fires every param-bearing `api/` route cross-tenant, cross-privilege, and as an
unclaimed user. A response that is not a rejection is a finding.

Design: `docs/superpowers/specs/2026-07-30-authz-test-harness-design.md`

## `composer test` does NOT run this

The default suite is the SQLite lane. This lane needs a real Postgres with
`supabase/migrations/` applied:

```bash
scripts/db/fresh-reset.sh          # provision + apply, once
composer test:authz
```

In CI it runs inside the `schema-tests` job with `AUTHZ_LANE_REQUIRED=1`, which
turns a missing database into a red build instead of a silent skip.

## I added a route and the build went red

| Failure | Fix |
|---|---|
| "could not be resolved to a model … no `fixture:` mapping" | Read your controller, add `fixture: {<param>: <Model FQCN>}` to `expectations.yaml` |
| "no seeded fixture row for `<Model>`" | Add it to `Fixtures::seedOwnedBy()` |
| "inconclusive — validation rejected the request" (422) | Add a minimal `body:` to the expectations entry |
| "cross-tenant access" (2xx) | **This is a bug in your controller**, not in the test |
| "403 leaks existence" | Return 404 for resources that do not belong to the caller |

An `expect: exempt` requires a `reason:` naming what the param actually
identifies. Exempting a route to make the build green defeats the entire lane.

## Fixtures persist after a run

Identities are committed outside the per-test transaction so they survive each
test's rollback. Rows are left behind; re-run `scripts/db/fresh-reset.sh` for a
clean slate.
```

- [ ] **Step 3: Verify the full lane is green**

```bash
composer test:authz
```

Expected: PASS, with a case count in the several hundreds.

- [ ] **Step 4: Verify the rest of the suite is unaffected**

```bash
COMPOSER_PROCESS_TIMEOUT=0 composer test
```

Expected: no new failures relative to `origin/development`. `development` carries known-red gates (PHPStan regressed to 21 errors, 5 standing red gates since `c3bca835`) — run the same command on `origin/development` first and compare, rather than assuming any red is yours.

- [ ] **Step 5: Commit and push**

```bash
php artisan pint
git add .github/workflows/ci.yml tests/Authz/README.md
git commit -m "ci(authz): gate the schema-tests job on the authorization lane"
git push -u origin audit-fix/authz-matrix-tier1-2026-07-30
```

- [ ] **Step 6: Open the PR**

Body must list: the number of cases by suite, every genuine finding with its route, and every exemption added with its reason. A reviewer's main job is checking that no exemption is a disguised bug.

---

## Self-Review Notes

**Spec coverage:** Every spec section maps to a task — components table → Tasks 1-8; route classification and the inclusion default → Task 2 + Task 6; expectations file → Task 3; fixtures → Task 4; verdict table incl. 422-as-failure → Task 5; new-route lifecycle → Task 6 + Task 10 README; "when this runs" → Task 10; rollout (gate day one, triage on branch) → Task 9 + Task 10; risk 1 (`actingAsUser`) → Task 1 Step 7 with a named fallback.

**Not covered here, by design:** Tier 2 (claim race, real `aal1` token, JWT tampering) and the `scripts/audit/lenses/authz-invariants.md` discovery lens are Phase 2 and get their own plan.

**Known unknown:** the number of genuine findings Task 9 surfaces. The plan routes them to the blocker gate rather than fixing them inline, so a large count changes the branch's timeline, not its shape.
