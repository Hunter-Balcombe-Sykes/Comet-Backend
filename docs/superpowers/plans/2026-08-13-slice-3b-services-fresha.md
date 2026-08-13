# Slice 3b — Fresha services onto `content.*` — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Repair the Fresha connector so the scraped menu lands natively in
`content.*` with honest per-staff prices, turn Fresha's categories into
`content.collections`, and cut the remaining 10 endpoints, the staff controller
and the booking surface over to read it.

**Architecture:** The connector is a pure function of (identifier, config, Io).
The *provisioner* writes which staff member's menu to fetch onto
`ingest.sources.selection_ref`; `RunExecutor` passes it through `Pull.config`.
`ProjectionWriter` gains one additive `collections` key so category membership
becomes part of a projection rather than a second per-connector writer. The 61
legacy Fresha rows are **not** backfilled and the 16 legacy categories are
**not** migrated — scraped data lands natively under its own source, and the
legacy tables keep serving the legacy lane until slice 7.

**Tech Stack:** PHP 8.4, Laravel 12, Postgres (Supabase), Pest 4. Tests run
SQLite; the Postgres lane (`composer test:pg`) is mandatory in this slice.

**Spec:** `docs/superpowers/specs/2026-08-13-slice-3b-services-fresha-design.md`

## Global Constraints

- **Never create Laravel migration files.** Schema changes are raw SQL in
  `supabase/migrations/`. Prefix block for this slice: `20260813090000`–`20260813099999`.
- **One `CONCURRENTLY` statement per migration file.** This slice uses none.
- **`composer test` needs `COMPOSER_PROCESS_TIMEOUT=0`** — the suite exceeds
  composer's 300s default and the timeout presents as a hang.
- **`composer test:pg` is mandatory** — this slice edits `ProjectionWriter`.
  Its stand-in DDL is hand-written and drifts; new columns must be added to it.
- **In `ON CONFLICT DO UPDATE`, qualify every column.** A bare column is
  ambiguous on Postgres (SQLSTATE 42702) and accepted by SQLite — slice 5a
  shipped exactly this through a green suite.
- **Cache invalidation is three independent lanes** on every write path:
  `BuildState::bump($siteId)`, `UPDATE site.sites SET updated_at`,
  `CloudflareCachePurgeJob::dispatch($subdomain)`. Use
  `ManualServiceWriter::invalidate()`; do not re-implement it. **There is no CI
  check enforcing this**, despite `BuildState`'s docblock claiming one.
- **Never write `content.source_items.removed_at` for a user deletion.** It is
  cleared on reappearance and would resurrect a deleted row.
  `content.items.removed_at` is one-way and is the correct home.
- **Never route a Fresha row through `ManualServiceWriter::projectionFor()`.**
  Its `price_cents === 0 → qualifier 'free'` rule is correct for hand-entered
  data and a lie on scraped data (all 61 legacy Fresha rows carry `price_cents = 0`
  because the stored blob's `priceValue` is null).
- **Never pin a test to a live vendor count.** Menus change between runs;
  edward's storewide menu moved 22 → 25 between the kickoff and the entry gate.
- **Assert exact cache-revision deltas, never `content_revision > 0`.** 3a's
  three-lane test passed with the `BuildState` lane deleted.
- **`services_user_sort_order_uq` is `UNIQUE (user_id, sort_order) WHERE deleted_at IS NULL`**
  — global per user, NOT scoped by `source`. Renumbering one half collides with
  the other.
- Use the 3a collaborators (`ManualServiceItems`, `ManualServiceWriter`).
  **Do not write a fourth copy of their predicates** — three of 3a's
  final-review blockers were exactly that.

---

## File Structure

**Created**

| File | Responsibility |
|---|---|
| `supabase/migrations/20260813090000_slice3b_collections_keys_and_selection_ref.sql` | The three columns + the unique index |
| `app/Services/Content/FreshaServiceItems.php` | The ONE read of Fresha-sourced service items (mirrors `ManualServiceItems` for the connection source) |
| `app/Services/Content/ServiceCollections.php` | The ONE read/write of `kind='service_category'` collections + memberships |
| `tests/Postgres/CollectionsUpsertConflictTest.php` | Pins the collections upsert against real Postgres |

**Modified**

| File | Change |
|---|---|
| `app/Ingest/Connectors/FreshaConnector.php` | selection-driven fetch, `no_selection` Note, bundle ids, `categoryId`, corrected messages |
| `app/Ingest/Projection/FreshaServiceProjector.php` | emit `collections` from `categoryId`/`category` |
| `app/Ingest/Projection/ProjectionWriter.php` | the additive `collections` key |
| `app/Ingest/SourceProvisioner.php` | derive `selection_ref`; refresh on change |
| `app/Ingest/Runtime/RunExecutor.php` | pass `selection_ref` in `Pull.config` |
| `app/Ingest/Runtime/Pull.php` | docblock: the third config key |
| `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php` | `resync`, `resyncBulk`, `updateCategory` |
| `app/Http/Controllers/Api/User/SiteManagement/UserServiceCategoryController.php` | all 7 routes onto collections |
| `app/Http/Controllers/Api/Staff/StaffServiceManagementController.php` | all 9 methods onto the shared collaborators |
| `app/Policies/ServicePolicy.php` | remove the `updateCategory` Fresha-only gate |
| `app/Services/Content/ManualServiceItems.php` | `publicList()` real category instead of the `'Services'` constant |
| `app/Http/Resources/Platforms/FreshaSelectionResource.php` | `services[]` from `content.*` |
| `tests/…` PG stand-in DDL | the three new columns + index |

---

## Task 1: Migration — collection keys and `selection_ref`

**Files:**
- Create: `supabase/migrations/20260813090000_slice3b_collections_keys_and_selection_ref.sql`
- Modify: every `content.collections` stand-in — `tests/Pest.php:2976`
  (`setupContentTables()`, the SQLite lane), plus
  `tests/Postgres/ShopStorefrontUpsertConflictTest.php:67` and
  `tests/Postgres/ShopUpsertStoreAtomicityTest.php:70` (each self-provisions
  its own copy; see Step 2)
- Test: `tests/Postgres/CollectionsUpsertConflictTest.php` (create)

**Interfaces:**
- Produces: `content.collections.external_ref TEXT NULL`,
  `content.collections.removed_at TIMESTAMPTZ NULL`,
  `collections_user_kind_external_ref_uq` on `(user_id, kind, external_ref)`,
  `ingest.sources.selection_ref TEXT NULL`.

- [ ] **Step 1: Write the migration**

```sql
-- Slice 3b: a natural key for machine-derived collections, a soft-delete for
-- owner-deleted ones, and the per-source selection the Fresha connector needs.

ALTER TABLE content.collections ADD COLUMN IF NOT EXISTS external_ref TEXT;
ALTER TABLE content.collections ADD COLUMN IF NOT EXISTS removed_at TIMESTAMPTZ;

-- Deliberately NOT partial. `WHERE external_ref IS NOT NULL` reads better, but
-- Postgres requires a partial index's predicate inside ON CONFLICT, and
-- Laravel's upsert() emits only the column list -- the write would fail with
-- "no unique or exclusion constraint matching the ON CONFLICT specification".
-- NULLs are distinct by default, so user-created rows stay unconstrained.
CREATE UNIQUE INDEX IF NOT EXISTS collections_user_kind_external_ref_uq
    ON content.collections (user_id, kind, external_ref);

-- Which sub-account's view of the remote thing to fetch. Three states:
-- an employee id, the literal 'storewide', or NULL (nothing chosen).
ALTER TABLE ingest.sources ADD COLUMN IF NOT EXISTS selection_ref TEXT;

COMMENT ON COLUMN content.collections.external_ref IS
    'Provider-side stable id for a machine-derived collection; NULL when user-created.';
COMMENT ON COLUMN content.collections.removed_at IS
    'Owner deleted this collection. One-way: never set or cleared by a projection run.';
COMMENT ON COLUMN ingest.sources.selection_ref IS
    'Connector-specific sub-account selector, passed through Pull.config. NULL = nothing chosen.';
```

- [ ] **Step 2: Add the columns everywhere `content.collections` is stood in**

There is **no single central Postgres-lane DDL file** — confirmed by reading
every hit of `grep -rn "content.collections" tests/ | grep -i "create table"`.
There are four, two different lanes:

- `tests/Pest.php:2976`, inside `setupContentTables()` — the **SQLite**
  stand-in used by every `tests/Feature/*` test (`composer test`), including
  `ProjectionWriterTest.php` (Task 5). Add `external_ref TEXT`,
  `removed_at TEXT` here or Task 5's SQLite-side tests fail with "no such
  column".
- `tests/Postgres/ShopStorefrontUpsertConflictTest.php:67` and
  `tests/Postgres/ShopUpsertStoreAtomicityTest.php:70` — each self-provisions
  `content.collections` inside its own `beginTransaction()`/`rollBack()` in
  `beforeEach`, per this lane's own documented convention (that file's own
  "LANE HYGIENE" comment: `content.*` tables are shared fixtures across the
  Postgres lane and whichever file runs first decides the shape for the rest
  of the run). Neither currently declares `external_ref`/`removed_at`; add
  both, matching the real migration's types (`text`, `timestamptz`), so an
  ordering fluke never leaves a narrower table behind for
  `CollectionsUpsertConflictTest.php` to inherit.
- `tests/Feature/PublicSite/PresenceProbeEscalationTest.php:287` — also the
  SQLite lane (`DB::connection('pgsql')` there is the SQLite-backed
  `pgsql`-named connection tests use, per
  `reference_pgsql_driver_sqlite_in_tests.md`), unrelated to collections'
  natural key; leave it unless it fails.

The **new** `tests/Postgres/CollectionsUpsertConflictTest.php` (Step 3) does
not "add to" any of these — it self-provisions its own `content.collections`
+ `core.users`, exactly like `ShopStorefrontUpsertConflictTest.php` does, with
the two new columns and the unique index from day one.

**If any Postgres-lane file's stand-in lacks the index, the upsert in Task 5's
Postgres test will silently insert duplicates there and pass.**

- [ ] **Step 3: Write the failing Postgres test**

`createPostgresUser()` does not exist anywhere in the tree (confirmed:
`grep -rn "createPostgresUser" tests/ app/` is empty). Follow
`ShopStorefrontUpsertConflictTest.php`'s real, self-contained pattern exactly:
extend `Tests\PostgresTestCase`, `beginTransaction()`/`rollBack()` around a
per-file `content.collections` (+ `core.users`) provision, and a
file-local user-insert helper named for this file, e.g.
`collectionsUpsertConflictTestUser()`. `DB::table(...)` alone is fine —
`phpunit.pg.xml` inherits `DB_CONNECTION=pgsql` — but this lane's siblings
write `DB::connection('pgsql')->table(...)` explicitly; match that style.

```php
<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

// The 42702 shape slice 5a shipped: a bare column in ON CONFLICT DO UPDATE is
// ambiguous on Postgres and fine on SQLite. This test only means anything on
// the real driver.

beforeEach(function () {
    $pg = DB::connection('pgsql');
    $pg->beginTransaction();

    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS content');
    $pg->statement('DROP TABLE IF EXISTS content.collections CASCADE');
    $pg->statement('DROP TABLE IF EXISTS core.users CASCADE');

    $pg->statement('CREATE TABLE core.users (id uuid PRIMARY KEY DEFAULT gen_random_uuid())');

    // Faithful to supabase/migrations/20260727140000_content_schema.sql's
    // content.collections plus this slice's external_ref/removed_at.
    $pg->statement('CREATE TABLE content.collections (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        parent_id uuid REFERENCES content.collections(id) ON DELETE CASCADE,
        label text NOT NULL,
        kind text,
        external_ref text,
        removed_at timestamptz,
        position integer NOT NULL DEFAULT 0,
        is_user_created boolean NOT NULL DEFAULT false,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE UNIQUE INDEX collections_user_kind_external_ref_uq
        ON content.collections (user_id, kind, external_ref)');
});

afterEach(function () {
    DB::connection('pgsql')->rollBack();
});

function collectionsUpsertConflictTestUser(): string
{
    $userId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert(['id' => $userId]);

    return $userId;
}

it('upserts a collection twice on its natural key without duplicating', function () {
    $userId = collectionsUpsertConflictTestUser();
    $row = fn (string $label) => [
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'parent_id' => null,
        'label' => $label,
        'kind' => 'service_category',
        'external_ref' => '3282965',
        'position' => 0,
        'is_user_created' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::connection('pgsql')->table('content.collections')->upsert(
        [$row('Haircuts')],
        ['user_id', 'kind', 'external_ref'],
        ['label', 'position', 'updated_at'],
    );
    DB::connection('pgsql')->table('content.collections')->upsert(
        [$row('Haircuts & Styling')],
        ['user_id', 'kind', 'external_ref'],
        ['label', 'position', 'updated_at'],
    );

    $rows = DB::connection('pgsql')->table('content.collections')
        ->where('user_id', $userId)->where('kind', 'service_category')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->label)->toBe('Haircuts & Styling');
});

it('allows many user-created collections with a null external_ref', function () {
    $userId = collectionsUpsertConflictTestUser();
    foreach (['Cuts', 'Colour'] as $i => $label) {
        DB::connection('pgsql')->table('content.collections')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $userId, 'parent_id' => null,
            'label' => $label, 'kind' => 'service_category', 'external_ref' => null,
            'position' => $i, 'is_user_created' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(DB::connection('pgsql')->table('content.collections')->where('user_id', $userId)->count())->toBe(2);
});
```

- [ ] **Step 4: Run it and watch it fail**

Run: `composer test:pg -- --filter=CollectionsUpsertConflict`
Expected: FAIL — `column "external_ref" does not exist`.

- [ ] **Step 5: Apply the migration to dev**

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
supabase db push
```

- [ ] **Step 6: Run the Postgres lane green**

Run: `composer test:pg -- --filter=CollectionsUpsertConflict`
Expected: PASS, both cases.

- [ ] **Step 7: Verify on dev directly**

```sql
SELECT column_name FROM information_schema.columns
WHERE table_schema='content' AND table_name='collections' AND column_name IN ('external_ref','removed_at');
SELECT indexdef FROM pg_indexes WHERE indexname='collections_user_kind_external_ref_uq';
SELECT column_name FROM information_schema.columns
WHERE table_schema='ingest' AND table_name='sources' AND column_name='selection_ref';
```

Expected: 2 columns, 1 index, 1 column. **Paste the output into the task's
review note** — invariant #1.

- [ ] **Step 8: Commit**

```bash
git add supabase/migrations tests/Postgres tests/
git commit -m "feat(db): collection natural key, collection soft-delete, ingest selection_ref"
```

---

## Task 2: The selection reaches `Pull.config`

**Files:**
- Modify: `app/Ingest/SourceProvisioner.php`
- Modify: `app/Ingest/Runtime/RunExecutor.php:76-82`
- Modify: `app/Ingest/Runtime/Pull.php:11-14` (docblock only)
- Test: `tests/Feature/Ingest/SourceProvisionerTest.php` (extend; find it first)

**Interfaces:**
- Consumes: `ingest.sources.selection_ref` (Task 1).
- Produces: `Pull.config['selection_ref']` — `string|null`. An employee id, the
  literal `'storewide'`, or `null`.

- [ ] **Step 1: Write the failing tests**

```php
it('writes the chosen employee id onto the ingest source', function () {
    $connection = freshaConnection(['selection' => ['mode' => 'employee', 'employee' => ['employeeId' => '4891132']]]);

    app(SourceProvisioner::class)->sync($connection);

    expect(DB::table('ingest.sources')->where('connection_id', $connection->id)->value('selection_ref'))
        ->toBe('4891132');
});

it('writes the storewide token when the owner chose the whole store', function () {
    $connection = freshaConnection(['selection' => ['mode' => 'storewide']]);

    app(SourceProvisioner::class)->sync($connection);

    expect(DB::table('ingest.sources')->where('connection_id', $connection->id)->value('selection_ref'))
        ->toBe('storewide');
});

it('leaves selection_ref null when nothing has been chosen', function () {
    $connection = freshaConnection(['selection' => null]);

    app(SourceProvisioner::class)->sync($connection);

    expect(DB::table('ingest.sources')->where('connection_id', $connection->id)->value('selection_ref'))
        ->toBeNull();
});

// The one that matters operationally: without this, switching who you are
// takes up to max_interval_secs (7 days) to show on the site.
it('refetches soon when the selection changes', function () {
    $connection = freshaConnection(['selection' => ['mode' => 'employee', 'employee' => ['employeeId' => '111']]]);
    app(SourceProvisioner::class)->sync($connection);
    DB::table('ingest.sources')->where('connection_id', $connection->id)
        ->update(['next_attempt_at' => now()->addDays(7)]);

    $connection->payload = ['url' => $connection->payload['url'], 'selection' => ['mode' => 'employee', 'employee' => ['employeeId' => '222']]];
    app(SourceProvisioner::class)->sync($connection);

    $row = DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
    expect($row->selection_ref)->toBe('222')
        ->and(strtotime((string) $row->next_attempt_at))->toBeLessThanOrEqual(time() + 60);
});
```

`SourceProvisionerTest.php` already defines everything this needs:
`provisionerUser(): string` (a fresh tenant id via `createTenant()`),
`makeConnection(string $userId, array $attributes): IntegrationConnection`
(wraps `IntegrationConnection::create()`), and
`ingestSourceFor(IntegrationConnection $connection): ?object` (reads the
resulting `ingest.sources` row). Write `freshaConnection(array $payloadExtras)`
as a thin wrapper over `makeConnection()`. Use `'platform' => 'fresha'`, not
`surface_key` directly — the model's `setPlatformAttribute` mutator resolves
the legacy `platform` write through `App\Catalog\LegacyPlatformMap`, and that
map sends `'fresha'` to `surface_key` `fresha.book`, **not** `fresha.booking`
(verified: `app/Catalog/LegacyPlatformMap.php:38`).

```php
function freshaConnection(array $payloadExtras): IntegrationConnection
{
    return makeConnection(provisionerUser(), [
        'platform' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/some-salon-abc123'] + $payloadExtras,
    ]);
}
```

- [ ] **Step 2: Run and watch them fail**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=SourceProvisioner`
Expected: FAIL — `selection_ref` is null / column not in the insert.

- [ ] **Step 3: Add the derivation to `SourceProvisioner`**

Add beside `identifierFor()`, deliberately shaped as a per-source `match` so
slices 4–7 extend it rather than special-case Fresha:

```php
    /**
     * Which sub-account's view of the remote thing to fetch. Only Fresha has
     * one today; the match arm exists so a later connector adds a line, not a
     * mechanism. Returns null when nothing has been chosen -- the connector
     * treats that as "land nothing", never as "fetch everything" (spec §2).
     */
    private function selectionRefFor(string $sourceKey, IntegrationConnection $connection): ?string
    {
        return match ($sourceKey) {
            'fresha' => $this->freshaSelectionRef($connection->payload['selection'] ?? null),
            default => null,
        };
    }

    /** 'employee' -> the employee id; 'storewide' -> the reserved token; else null. */
    private function freshaSelectionRef(mixed $selection): ?string
    {
        if (! is_array($selection)) {
            return null;
        }

        $mode = $selection['mode'] ?? null;
        if ($mode === 'storewide') {
            return 'storewide';
        }

        // Employee ids are numeric, so they can never collide with the token.
        $employeeId = $selection['employee']['employeeId'] ?? null;

        return is_scalar($employeeId) && trim((string) $employeeId) !== ''
            ? trim((string) $employeeId)
            : null;
    }
```

- [ ] **Step 4: Write it on insert and on change**

In `sync()`'s INSERT array (`:79-93`) add `'selection_ref' => $selectionRef,`
after `'identifier' => $identifier,`, having computed
`$selectionRef = $this->selectionRefFor($sourceKey, $connection);` next to
`$identifier`. Add `'selection_ref'` to the `first([...])` column list at `:76`.

Then extend the UPDATE branch, immediately after the identifier block at
`:102-106`:

```php
        if ((string) ($existing->selection_ref ?? '') !== (string) ($selectionRef ?? '')) {
            $update['selection_ref'] = $selectionRef;
            // A different selection is a different menu at different prices:
            // without this the change waits out max_interval_secs (7 days).
            $update['next_attempt_at'] = now();
        }
```

- [ ] **Step 5: Pass it through `RunExecutor`**

`app/Ingest/Runtime/RunExecutor.php:80` — add the third key:

```php
                config: [
                    'scope' => $source['scope'] ?? 'all',
                    'scope_n' => $source['scope_n'] ?? null,
                    'selection_ref' => $source['selection_ref'] ?? null,
                ],
```

Confirm the query that loads `$source` selects the column (`SELECT *` or an
explicit list — check and widen if explicit).

- [ ] **Step 6: Update `Pull`'s docblock**

`app/Ingest/Runtime/Pull.php:11` — the `$config` line becomes:

```php
     * @param  array<string, mixed>  $config  per-source options (scope, scope_n,
     *                                        selection_ref). selection_ref is the
     *                                        sub-account selector: an id, the
     *                                        literal 'storewide', or null for
     *                                        "nothing chosen" -- which a connector
     *                                        must treat as land-nothing, never as
     *                                        fetch-everything.
```

- [ ] **Step 7: Run the tests green**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=SourceProvisioner`
Expected: PASS, all four.

- [ ] **Step 8: Mutation-check the refetch case**

Delete the `$update['next_attempt_at'] = now();` line, re-run, and confirm the
fourth test goes RED. Restore it. A test that stays green without the line is
not testing it.

- [ ] **Step 9: Commit**

```bash
git add app/Ingest tests/
git commit -m "feat(ingest): carry the sub-account selection through Pull.config"
```

---

## Task 3: `FreshaConnector` follows the selection

**Files:**
- Modify: `app/Ingest/Connectors/FreshaConnector.php:92-119, 223-256`
- Test: `tests/Feature/Ingest/Connectors/FreshaConnectorTest.php` (find the
  existing one first: `grep -rln "FreshaConnector" tests/`)

**Interfaces:**
- Consumes: `Pull.config['selection_ref']` (Task 2).
- Produces: unchanged `Record('services', <serviceId>, [...])` stream; a new
  `Note('no_selection', …)`.

- [ ] **Step 1: Write the failing tests**

```php
it('lands nothing and makes no HTTP call when nothing has been chosen', function () {
    $io = freshaIo(freshaResponseWith([]));   // recorded posts must stay empty
    $pull = freshaPull('services', 'some-salon-abc123', config: ['selection_ref' => null]);

    $messages = iterator_to_array((new FreshaConnector)->pull($pull, $io));

    expect($io->posts)->toBeEmpty()
        ->and($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and($messages[0]->code)->toBe('no_selection');
});

it('asks for one employee menu when an employee is chosen', function () {
    $io = freshaIo(freshaResponseWith([['id' => '1', 'name' => 'Cuts', 'items' => [normalItem('s:1')]]]));
    $pull = freshaPull('services', 'some-salon-abc123', config: ['selection_ref' => '4891132']);

    iterator_to_array((new FreshaConnector)->pull($pull, $io));

    $options = $io->posts[0]['body']['variables']['input']['options'];
    expect($options['employeeId'])->toBe('4891132')
        ->and($options['shouldShowAllEmployees'])->toBeFalse();
});

it('asks for the store menu when storewide is chosen', function () {
    $io = freshaIo(freshaResponseWith([['id' => '1', 'name' => 'Cuts', 'items' => [normalItem('s:1')]]]));
    $pull = freshaPull('services', 'some-salon-abc123', config: ['selection_ref' => 'storewide']);

    iterator_to_array((new FreshaConnector)->pull($pull, $io));

    $options = $io->posts[0]['body']['variables']['input']['options'];
    expect($options['employeeId'])->toBeNull()
        ->and($options['shouldShowAllEmployees'])->toBeFalse();
});

// The profile stream does not depend on whose menu it is.
it('still fetches profile when nothing has been chosen', function () {
    $io = freshaIo(freshaResponseWith([['id' => '1', 'name' => 'Cuts', 'items' => [normalItem('s:1')]]]));
    $pull = freshaPull('profile', 'some-salon-abc123', config: ['selection_ref' => null]);

    iterator_to_array((new FreshaConnector)->pull($pull, $io));

    expect($io->posts)->toHaveCount(1);
});
```

None of `fakeIo()`, `servicesSpec()`, `profileSpec()`, `freshaServicesFixture()`,
`freshaResponseWith()`, `normalItem()` exist anywhere in the tree. The real
equivalents, already in `FreshaConnectorTest.php`:

- **Pull builder**: `freshaPull(string $streamName, string $slug = 'invented-salon'): Pull`
  — builds `new Pull(identifier: $slug, stream: FreshaConnector::manifest()->stream($streamName))`.
  It does **not** take a `config` argument today. Widen it to
  `freshaPull(string $streamName, string $slug = 'invented-salon', array $config = []): Pull`
  and pass `config: $config` through — this is additive, every existing call
  keeps working.
- **Io fake**: `freshaIo(array $response): Io` — an anonymous class whose
  `post()` checks the host against `FreshaConnector::manifest()->mayContact()`
  and returns the fixed `$response` array (`['status' => …, 'body' => …,
  'headers' => …]`). **It does not record calls.** There is no `->posts`
  anywhere in the current file — Tasks 3 and 4 need
  `$io->posts[0]['body']['variables']['input']['options']`, which is
  unsupported today. Widen the anonymous class: add
  `public array $posts = [];` and, as the first line of `post()`, append
  `$this->posts[] = ['url' => $url, 'body' => $body, 'headers' => $headers];`
  before the manifest check. Purely additive — no existing assertion reads
  `->posts`.
- **Response body builder**: `freshaBookingFlowBody(array $categories, ?array $location = null): string`
  — already returns the encoded `data.bookingFlowInitialize.screenServices.categories`
  JSON string. Build the two new local helpers on top of it rather than
  inventing a parallel envelope:
  ```php
  /** @param list<array<string,mixed>> $categories */
  function freshaResponseWith(array $categories): array
  {
      return ['status' => 200, 'body' => freshaBookingFlowBody($categories), 'headers' => []];
  }

  function normalItem(string $catalogId): array
  {
      return [
          'name' => 'Standard Haircut',
          'caption' => '30min',
          'price' => ['formatted' => 'from $48'],
          'primaryAction' => ['id' => '[{"catalogId":"'.$catalogId.'"}]'],
      ];
  }
  ```
  Task 3's storewide/employee cases (below) call `freshaResponseWith([['id' => '1',
  'name' => 'Cuts', 'items' => [normalItem('s:1')]]])` directly at each call
  site rather than through a named `freshaServicesFixture()` — there is no real
  captured fixture to reuse (see the `RefreshFetchBudgetTest.php` finding
  below; it holds none, only synthetic minimal bodies of its own).
- Every `fakeIo(response: X)` in the test bodies below is `freshaIo(X)`, and
  every `servicesSpec()`/`profileSpec()` is `freshaPull('services', …)`/
  `freshaPull('profile', …)` — already applied in Step 1's code above.

- [ ] **Step 2: Run and watch them fail**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=FreshaConnector`
Expected: FAIL — `shouldShowAllEmployees` is still `true`; no `no_selection`.

- [ ] **Step 3: Gate the services stream on the selection**

`pull()` becomes — note the gate runs **before** `fetchBookingFlow()`, so a
connection with no selection costs no HTTP call:

```php
    public function pull(Pull $pull, Io $io): iterable
    {
        $slug = trim($pull->identifier, '/');
        $selectionRef = $pull->config['selection_ref'] ?? null;
        $selectionRef = is_string($selectionRef) && trim($selectionRef) !== '' ? trim($selectionRef) : null;

        if ($pull->stream->name === 'services' && $selectionRef === null) {
            // Nobody has chosen whose menu this is. Fetching the STORE menu
            // here would publish a whole salon's catalogue, at "from
            // <cheapest staff member>" prices, on one individual's page --
            // measured on dev, 22 of 23 prices understated. Landing nothing
            // is what happens today; the Note is so that "nobody chose yet"
            // stops looking identical to "the connector is broken".
            yield new Note('no_selection', 'No Fresha team member or storewide menu has been chosen for this connection');

            return;
        }

        $decoded = $this->fetchBookingFlow($slug, $io, $selectionRef);

        if ($decoded === null) {
            yield new Unavailable(
                'Fresha booking-flow GraphQL rejected the pinned persisted query — '.
                'the hash/client version has likely rotated on a Fresha frontend '.
                'deploy; re-pin by capturing a fresh persisted-query hash from the '.
                'current Fresha build and updating FRESHA_BOOKING_INIT_HASH / '.
                'FRESHA_CLIENT_VERSION (config/services.php "fresha") — see the '.
                'Fresha re-pin runbook.',
            );

            return;
        }

        if ($pull->stream->name === 'services') {
            yield from $this->servicesMessages($decoded);

            return;
        }

        if ($pull->stream->name === 'profile') {
            yield from $this->profileMessages($decoded, $slug);
        }
    }
```

- [ ] **Step 4: Make the request follow the selection**

`fetchBookingFlow()` takes the selector and stops asking for the picker screen:

```php
    /** @return array<string, mixed>|null null when the pinned query is rejected */
    private function fetchBookingFlow(string $slug, Io $io, ?string $selectionRef): ?array
    {
        $clientVersion = (string) config('services.fresha.client_version');
        $hash = (string) config('services.fresha.booking_init_hash');

        // 'storewide' is the reserved token for "the location's own menu";
        // anything else is an employee id. shouldShowAllEmployees:true returns
        // the employee-PICKER screen, whose screenServices is {} -- which is
        // why this stream landed zero records from 2026-07-28 (spec §1.3).
        $employeeId = ($selectionRef === null || $selectionRef === 'storewide') ? null : $selectionRef;

        $body = [
            'operationName' => 'BookingFlow_Initialize_Mutation',
            'variables' => [
                'fullUpfrontPaymentEnabled' => true,
                'discountsAndBenefitsEnabled' => false,
                'input' => [
                    'locationSlug' => $slug,
                    'referer' => '',
                    'options' => [
                        'employeeId' => $employeeId,
                        'shouldShowAllEmployees' => false,
                        'isGroupBooking' => false,
                        'isRebook' => false,
                        'isFromLinkBuilder' => false,
                        'clientChannelType' => 'MARKETPLACE',
                        'cartId' => null,
                        'offerItemId' => null,
                        'offerItems' => null,
                    ],
                    'shouldAutoContinue' => true,
                    'capabilities' => ['SERVICE_ADDONS', 'CONFIRMATION', 'FULL_UPFRONT_PAYMENT', 'MARKETPLACE_REFRESH'],
                ],
            ],
            'extensions' => [
                'persistedQuery' => ['version' => 1, 'sha256Hash' => $hash],
                'platform' => 'web',
                'version' => $clientVersion,
            ],
        ];

        $response = $io->post(self::GRAPHQL_URL, $body, [
            'content-type' => 'application/json',
            'x-client-platform' => 'web',
            'x-client-version' => $clientVersion,
            'x-graphql-operation-name' => 'mutation BookingFlow_Initialize_Mutation',
            'origin' => 'https://www.fresha.com',
        ]);

        if ($response['status'] !== 200) {
            return null;
        }

        $decoded = json_decode($response['body'], true);
        if (! is_array($decoded) || isset($decoded['errors'])) {
            return null;
        }

        return $decoded;
    }
```

- [ ] **Step 5: Correct the class docblock**

The class comment says the connector is *"generalised from one employee's
filtered menu to the storewide menu (`employeeId: null`,
`shouldShowAllEmployees: true`)"*. That generalisation is the defect. Replace
that sentence with:

```
 * generalised from one employee's filtered menu to whichever menu the owner
 * chose -- `Pull.config['selection_ref']` carries an employee id or the
 * literal 'storewide'. `shouldShowAllEmployees: true` returns the employee
 * PICKER screen with an empty `screenServices` and must never be sent here.
```

- [ ] **Step 6: Run the tests green**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=FreshaConnector`
Expected: PASS, all four.

- [ ] **Step 7: Commit**

```bash
git add app/Ingest/Connectors/FreshaConnector.php tests/
git commit -m "fix(fresha): fetch the chosen menu, not the employee picker"
```

---

## Task 4: `FreshaConnector` — bundles, category ids, honest messages

**Files:**
- Modify: `app/Ingest/Connectors/FreshaConnector.php:122-220`
- Test: same file as Task 3

**Interfaces:**
- Produces: each `Record` payload gains `categoryId` (string, optional);
  `serviceId` may now be `p:<digits>` as well as `s:<digits>`; a new
  `Note('unmapped_rows', …)`.

- [ ] **Step 1: Write the failing tests**

```php
it('lands a package row whose catalog id is only on the secondary action', function () {
    // primaryAction carries bookableId and NO catalogId; secondaryAction has
    // "catalogId":"p:360081". Two defects lost this row: the regex was pinned
    // to `s:`, and `primaryAction.id ?? secondaryAction.id` is a NULL-coalesce
    // on a non-null string, so it never fell through.
    $item = [
        'name' => "'Father & Son' Haircuts (Standard)",
        'caption' => '25 mins - 30 mins  •  2 services',
        'price' => ['formatted' => 'from $87'],
        'primaryAction' => ['id' => '[{"type":"onScreenServicesModalPackageOpen","bookableId":"p:360081"}]'],
        'secondaryAction' => ['id' => '[{"type":"onScreenServicesPackageAdd","catalogId":"p:360081"}]'],
    ];
    $io = freshaIo(freshaResponseWith([['id' => '2590968', 'name' => 'Kids', 'items' => [$item]]]));
    $pull = freshaPull('services', 'edward', config: ['selection_ref' => 'storewide']);

    $records = array_values(array_filter(
        iterator_to_array((new FreshaConnector)->pull($pull, $io)),
        fn ($m) => $m instanceof Record,
    ));

    expect($records)->toHaveCount(1)
        ->and($records[0]->key)->toBe('p:360081')
        ->and($records[0]->data['price'])->toBe('from $87');
});

it('carries the vendor category id alongside its name', function () {
    $io = freshaIo(freshaResponseWith([[
        'id' => '3282965', 'name' => 'Haircuts', 'items' => [normalItem('s:12107058')],
    ]]));
    $pull = freshaPull('services', 'edward', config: ['selection_ref' => 'storewide']);

    $records = array_values(array_filter(
        iterator_to_array((new FreshaConnector)->pull($pull, $io)),
        fn ($m) => $m instanceof Record,
    ));

    expect($records[0]->data['categoryId'])->toBe('3282965')
        ->and($records[0]->data['category'])->toBe('Haircuts');
});

it('counts rows it could not map instead of dropping them silently', function () {
    $unmappable = ['name' => 'Mystery', 'primaryAction' => ['id' => '[{"type":"whatever"}]']];
    $io = freshaIo(freshaResponseWith([[
        'id' => '1', 'name' => 'Cuts', 'items' => [normalItem('s:1'), $unmappable],
    ]]));
    $pull = freshaPull('services', 'edward', config: ['selection_ref' => 'storewide']);

    $notes = array_values(array_filter(
        iterator_to_array((new FreshaConnector)->pull($pull, $io)),
        fn ($m) => $m instanceof Note,
    ));

    expect($notes)->toHaveCount(1)
        ->and($notes[0]->code)->toBe('unmapped_rows');
});
```

`freshaResponseWith(array $categories)` and `normalItem(string $catalogId)`
are the same two local helpers Task 3 adds — defined once, reused here.

- [ ] **Step 2: Run and watch them fail**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=FreshaConnector`
Expected: FAIL — package row produces 0 records; no `categoryId`; no Note.

- [ ] **Step 3: Fix the mapper**

```php
    /** @return array<string, mixed>|null */
    private function mapServiceItem(mixed $item, ?string $categoryName, ?string $categoryId): ?array
    {
        if (! is_array($item)) {
            return null;
        }
        $name = is_string($item['name'] ?? null) ? trim($item['name']) : '';
        if ($name === '') {
            return null;
        }

        // The real id only ever surfaces embedded as a JSON string inside an
        // action id. A single service is `s:123`; a multi-service PACKAGE is
        // `p:360081` and carries it on secondaryAction only -- primaryAction
        // has bookableId instead. This must be a MATCH-based fallback, not
        // `?? `: primaryAction.id is a non-null string on a package, so a
        // null-coalesce never reaches the id that would have matched.
        $serviceId = null;
        foreach ([data_get($item, 'primaryAction.id'), data_get($item, 'secondaryAction.id')] as $actionId) {
            if (is_string($actionId) && preg_match('/"catalogId":"((?:s|p):\d+)"/', $actionId, $m)) {
                $serviceId = $m[1];
                break;
            }
        }
        if ($serviceId === null) {
            return null;
        }

        return array_filter([
            'serviceId' => $serviceId,
            'name' => $name,
            'duration' => is_string($item['caption'] ?? null) ? $item['caption'] : null,
            'description' => is_string($item['description'] ?? null) ? $item['description'] : null,
            'price' => is_string(data_get($item, 'price.formatted')) ? data_get($item, 'price.formatted') : null,
            'category' => $categoryName,
            'categoryId' => $categoryId,
        ], static fn ($v) => $v !== null);
    }
```

- [ ] **Step 4: Pass the category id in, and count what fell through**

In `servicesMessages()`:

```php
        $items = [];
        $unmapped = 0;
        foreach ($categories as $category) {
            $categoryName = is_string($category['name'] ?? null) ? $category['name'] : null;
            $categoryId = isset($category['id']) && is_scalar($category['id'])
                ? (string) $category['id']
                : null;
            foreach ((array) ($category['items'] ?? []) as $item) {
                $mapped = $this->mapServiceItem($item, $categoryName, $categoryId);
                $mapped === null ? $unmapped++ : $items[] = $mapped;
            }
        }
```

and after the `Covered` yield:

```php
        if ($unmapped > 0) {
            // A mapper gap used to be invisible: three of one salon's rows --
            // 12% of its menu -- vanished with no record and no signal.
            yield new Note('unmapped_rows', $unmapped.' Fresha row(s) carried no recognisable catalog id and were not landed');
        }
```

- [ ] **Step 5: Correct the `Unavailable` message**

The current text asserts a rotated hash. The entry-gate probe returned HTTP 200
with a well-formed `bookingFlowInitialize` and no `errors` on all nine requests,
so that assertion sent readers to re-pin a valid hash. Replace the message in
`servicesMessages()`:

```php
            yield new Unavailable(
                'Fresha booking-flow response carried no screenServices.categories. '.
                'Most likely the request asked for a screen that has no menu on it — '.
                'check that shouldShowAllEmployees is false and that selection_ref '.
                'names a real employee (verified live 2026-08-13: allEmployees=true '.
                'returns the picker screen with screenServices {}). A rotated '.
                'persisted-query hash produces the same symptom and is the second '.
                'thing to check — re-pin FRESHA_BOOKING_INIT_HASH / '.
                'FRESHA_CLIENT_VERSION only after ruling the first out.',
            );
```

- [ ] **Step 6: Run green**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=FreshaConnector`
Expected: PASS, all seven cases from Tasks 3 and 4.

- [ ] **Step 7: Mutation-check the fallback**

Change the loop back to `data_get($item,'primaryAction.id') ?? data_get($item,'secondaryAction.id')`,
re-run, confirm the package test goes RED, restore. Then narrow the regex back
to `(s:\d+)`, re-run, confirm RED again, restore. **Two defects, two mutations —
a fix for only one of them still loses the row.**

- [ ] **Step 8: Commit**

```bash
git add app/Ingest/Connectors/FreshaConnector.php tests/
git commit -m "fix(fresha): land package rows, carry category ids, stop blaming the hash"
```

---

## Task 5: `ProjectionWriter` learns collections

**Files:**
- Modify: `app/Ingest/Projection/ProjectionWriter.php:938-1160`
- Test: `tests/Feature/Ingest/ProjectionWriterTest.php` (find the existing one)
- Test: `tests/Postgres/CollectionsUpsertConflictTest.php` (extend)

**Interfaces:**
- Consumes: the Task 1 schema.
- Produces: projections may carry
  `'collections' => [['external_ref' => string|null, 'label' => string, 'kind' => string, 'position' => int]]`.
  Inert when absent.

- [ ] **Step 1: Write the failing tests**

```php
it('creates a collection and links the item to it', function () {
    [$sourceId, $userId] = manualSourceFor();

    projectOne($sourceId, $userId, coord: 'fresha:x:s:1', projection: [
        'kind' => 'service', 'headline' => 'Standard Haircut',
        'collections' => [['external_ref' => '3282965', 'label' => 'Haircuts', 'kind' => 'service_category', 'position' => 0]],
    ]);

    $collection = DB::table('content.collections')
        ->where('user_id', $userId)->where('external_ref', '3282965')->first();

    expect($collection)->not->toBeNull()
        ->and($collection->label)->toBe('Haircuts')
        ->and($collection->is_user_created)->toBeFalse()
        ->and(DB::table('content.collection_items')->where('collection_id', $collection->id)->count())->toBe(1);
});

it('reuses the collection on a second run and does not duplicate it', function () {
    [$sourceId, $userId] = manualSourceFor();
    $projection = [
        'kind' => 'service', 'headline' => 'Standard Haircut',
        'collections' => [['external_ref' => '3282965', 'label' => 'Haircuts', 'kind' => 'service_category', 'position' => 0]],
    ];

    projectOne($sourceId, $userId, 'fresha:x:s:1', $projection);
    projectOne($sourceId, $userId, 'fresha:x:s:1', $projection);

    expect(DB::table('content.collections')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('content.collection_items')->count())->toBe(1);
});

it('follows a vendor-side rename instead of minting a second collection', function () {
    [$sourceId, $userId] = manualSourceFor();
    $with = fn (string $label) => [
        'kind' => 'service', 'headline' => 'Standard Haircut',
        'collections' => [['external_ref' => '3282965', 'label' => $label, 'kind' => 'service_category', 'position' => 0]],
    ];

    projectOne($sourceId, $userId, 'fresha:x:s:1', $with('Haircuts'));
    projectOne($sourceId, $userId, 'fresha:x:s:1', $with('Haircuts & Styling'));

    $rows = DB::table('content.collections')->where('user_id', $userId)->get();
    expect($rows)->toHaveCount(1)->and($rows->first()->label)->toBe('Haircuts & Styling');
});

it('replaces memberships for its own source only', function () {
    [$sourceId, $userId] = manualSourceFor();
    $otherSourceId = otherSourceFor($userId);
    $itemId = projectOne($sourceId, $userId, 'fresha:x:s:1', [
        'kind' => 'service', 'headline' => 'Cut',
        'collections' => [['external_ref' => 'A', 'label' => 'A', 'kind' => 'service_category', 'position' => 0]],
    ]);
    $foreign = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $foreign, 'user_id' => $userId, 'parent_id' => null,
        'label' => 'Foreign', 'kind' => 'service_category', 'external_ref' => 'F',
        'position' => 0, 'is_user_created' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.collection_items')->insert([
        'collection_id' => $foreign, 'item_id' => $itemId, 'source_id' => $otherSourceId, 'position' => 0,
    ]);

    projectOne($sourceId, $userId, 'fresha:x:s:1', [
        'kind' => 'service', 'headline' => 'Cut',
        'collections' => [['external_ref' => 'B', 'label' => 'B', 'kind' => 'service_category', 'position' => 0]],
    ]);

    expect(DB::table('content.collection_items')->where('source_id', $otherSourceId)->count())->toBe(1)
        ->and(DB::table('content.collection_items')->where('source_id', $sourceId)->count())->toBe(1);
});

it('never touches removed_at on a projection run', function () {
    [$sourceId, $userId] = manualSourceFor();
    $projection = [
        'kind' => 'service', 'headline' => 'Cut',
        'collections' => [['external_ref' => 'A', 'label' => 'A', 'kind' => 'service_category', 'position' => 0]],
    ];
    projectOne($sourceId, $userId, 'fresha:x:s:1', $projection);
    DB::table('content.collections')->where('external_ref', 'A')->update(['removed_at' => now()]);

    projectOne($sourceId, $userId, 'fresha:x:s:1', $projection);

    expect(DB::table('content.collections')->where('external_ref', 'A')->value('removed_at'))->not->toBeNull();
});

it('is inert for a projection that carries no collections key', function () {
    [$sourceId, $userId] = manualSourceFor();

    projectOne($sourceId, $userId, 'fresha:x:s:1', ['kind' => 'service', 'headline' => 'Cut']);

    expect(DB::table('content.collections')->count())->toBe(0);
});
```

**None of `manualSourceFor()`, `projectOne()`, `otherSourceFor()` exist.**
`ProjectionWriterTest.php`'s only helpers are `projectableBandcamp()` /
`projectableEventbrite()` / `landCurrentRecord()` — they land raw docs into
`ingest.record_versions` and drive everything through the public
`projectStream(array $source, string $streamId, string $streamName)`, which
re-derives each projection itself via
`ProjectorRegistry::for($sourceKey, $streamName)`. That path only produces a
`collections` key once `FreshaServiceProjector` emits one — Task 6, not yet
landed at this point in the plan — so it is the wrong entry point for these
tests.

The right one already exists and needs no other task's work first:
`ProjectionWriter::writeManualItem(string $userId, string $coord, array $projection): string`
(public, `app/Ingest/Projection/ProjectionWriter.php:343`) takes a projection
array **directly** — no projector involved — and internally calls
`ensureManualSource($userId)` (public, line 260) for its content source, then
`writeFacets()`, which calls `replaceCollections($contentSourceId, $userId, $byItem)`
(the exact method Steps 3–5 below edit) before touching any singleton facet.
This is already how `ManualServiceWriter`/`ServiceEndpointCutoverTest.php`
exercise `ProjectionWriter` today — same seam, no dependency on the Fresha
connector or projector existing yet. Add these three helpers to
`ProjectionWriterTest.php`:

```php
/** A fresh user plus its (idempotent) manual content source id. */
function manualSourceFor(): array
{
    $userId = createTenant('proj-'.Str::lower(Str::random(6)))->id;
    $sourceId = app(ProjectionWriter::class)->ensureManualSource($userId);

    return [$sourceId, $userId];
}

/** Writes one projection through the real writeManualItem() seam; returns the item id. */
function projectOne(string $sourceId, string $userId, string $coord, array $projection): string
{
    return app(ProjectionWriter::class)->writeManualItem($userId, $coord, $projection);
}

/** A second, distinct content.sources row (kind='connection') to prove per-source scoping — same insert shape ServiceEndpointCutoverTest.php's merge test uses. */
function otherSourceFor(string $userId): string
{
    $id = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'connection',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
```

`$sourceId` in `projectOne()` is accepted for call-site readability but not
otherwise needed — `writeManualItem()` re-derives the same id via
`ensureManualSource()`, which is idempotent per user.

The fourth test's foreign-collection insert (below) builds its own uuid and
`insert()`s rather than `insertGetId()` — SQLite in this suite has no serial
key to hand back — using the same column set `upsertCollections()` (Step 4)
writes.

- [ ] **Step 2: Run and watch them fail**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=ProjectionWriter`
Expected: FAIL — no collections written.

- [ ] **Step 3: Gather collections per item in `replaceCollections()`**

Alongside the existing `$mediaByItem` / `$offersByItem` / `$tagsByItem` /
`$variantsByItem` accumulation, add:

```php
            $collections = [];
            foreach ($projections as $projection) {
                ...
                $collections = array_merge($collections, array_values((array) ($projection['collections'] ?? [])));
            }
            ...
            $collectionsByItem[(string) $itemId] = $collections;
```

- [ ] **Step 4: Upsert the collections themselves, once for the batch**

Add a private method and call it from `replaceCollections()` **before** the
chunk loop, so every membership row has a collection to point at:

```php
    /**
     * The content.collections rows a batch's projections name, upserted on
     * their natural key and returned as external_ref => id.
     *
     * removed_at is deliberately absent from BOTH the insert and the update
     * list: it means "the owner deleted this collection" and is one-way, the
     * same rule content.items.removed_at follows. A scrape re-listing a
     * category is not consent to resurrect it.
     *
     * @param  array<string, list<array<string, mixed>>>  $byItem
     * @return array<string, string>  "kind\0external_ref" => collection id
     */
    private function upsertCollections(string $userId, array $byItem): array
    {
        $wanted = [];
        foreach ($byItem as $entries) {
            foreach ($entries as $entry) {
                $entry = (array) $entry;
                $externalRef = isset($entry['external_ref']) && is_scalar($entry['external_ref'])
                    ? (string) $entry['external_ref']
                    : null;
                $label = trim((string) ($entry['label'] ?? ''));
                // The natural key IS the external ref; a machine-derived
                // collection without one cannot be reconciled across runs and
                // would insert a fresh row every time. Labels are mutable on
                // the vendor's side and are never a key (slice 5a's incident).
                if ($externalRef === null || $label === '') {
                    continue;
                }
                $kind = (string) ($entry['kind'] ?? 'collection');
                $wanted[$kind."\0".$externalRef] = [
                    'kind' => $kind,
                    'external_ref' => $externalRef,
                    'label' => $label,
                    'position' => (int) ($entry['position'] ?? 0),
                ];
            }
        }

        if ($wanted === []) {
            return [];
        }

        $rows = [];
        foreach ($wanted as $entry) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'parent_id' => null,
                'label' => $entry['label'],
                'kind' => $entry['kind'],
                'external_ref' => $entry['external_ref'],
                'position' => $entry['position'],
                'is_user_created' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Plain column names in the update list -- Laravel renders these as
        // `set "label" = "excluded"."label"`, which is unambiguous. A DB::raw
        // with a BARE column here is SQLSTATE 42702 on Postgres and silently
        // fine on SQLite (slice 5a, 2026-08-12).
        DB::table('content.collections')->upsert(
            $rows,
            ['user_id', 'kind', 'external_ref'],
            ['label', 'position', 'updated_at'],
        );

        $ids = [];
        foreach (array_chunk(array_values($wanted), $this->writeChunk()) as $chunk) {
            $refs = array_column($chunk, 'external_ref');
            $found = DB::table('content.collections')
                ->where('user_id', $userId)
                ->whereIn('external_ref', $refs)
                ->get(['id', 'kind', 'external_ref']);
            foreach ($found as $row) {
                $ids[$row->kind."\0".$row->external_ref] = (string) $row->id;
            }
        }

        return $ids;
    }
```

- [ ] **Step 5: Write the membership rows in the existing chunk transaction**

Build the rows beside `$tagRows`:

```php
        $collectionIds = $this->upsertCollections($userId, $collectionsByItem);

        $collectionItemRows = [];
        foreach ($collectionsByItem as $itemId => $entries) {
            $position = 0;
            foreach ($entries as $entry) {
                $entry = (array) $entry;
                $key = ((string) ($entry['kind'] ?? 'collection'))."\0".((string) ($entry['external_ref'] ?? ''));
                $collectionId = $collectionIds[$key] ?? null;
                if ($collectionId === null) {
                    continue;
                }
                $collectionItemRows[$itemId][] = [
                    'collection_id' => $collectionId,
                    'item_id' => $itemId,
                    'source_id' => $contentSourceId,
                    'position' => $position++,
                ];
            }
        }
```

and add one line to the `$tables` map inside the chunk loop:

```php
                'collection_items' => $this->rowsFor($collectionItemRows, $itemIds),
```

The existing delete is `whereIn('item_id', $itemIds)->where('source_id', $contentSourceId)`,
which is exactly the replace-by-source semantics required — `collection_items`'s
PK is `(collection_id, item_id)` with `source_id` outside it, so scoping by
source is what stops two sources deleting each other's memberships.

- [ ] **Step 6: Update the facet-presence bookkeeping**

`:1432` iterates `['item_media', 'offers', 'item_tags', 'f_action', 'item_variants']`
for its existence probe. Add `'collection_items'` **only if** that list drives
correctness rather than metrics — read the method first and follow what it does.
If it is a metrics/telemetry probe, leave it and note why in the commit body.

- [ ] **Step 7: Run green on SQLite, then on Postgres**

```bash
COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=ProjectionWriter
composer test:pg
```

Expected: PASS on both. **The Postgres run is the one that counts** — the
upsert's conflict target only exists there.

- [ ] **Step 8: Commit**

```bash
git add app/Ingest/Projection/ProjectionWriter.php tests/
git commit -m "feat(ingest): collections are part of a projection"
```

---

## Task 6: `FreshaServiceProjector` emits collections

**Files:**
- Modify: `app/Ingest/Projection/FreshaServiceProjector.php:26-47`
- Test: `tests/Feature/Ingest/Projection/FreshaServiceProjectorTest.php`

**Interfaces:**
- Consumes: `categoryId` on the record (Task 4), the `collections` key (Task 5).

- [ ] **Step 1: Write the failing tests**

```php
it('turns the vendor category into a collection keyed on its id', function () {
    $projection = (new FreshaServiceProjector)->project(recordView([
        'name' => 'Standard Haircut', 'price' => 'from $48',
        'category' => 'Haircuts', 'categoryId' => '3282965',
    ]));

    expect($projection['collections'])->toBe([[
        'external_ref' => '3282965', 'label' => 'Haircuts',
        'kind' => 'service_category', 'position' => 0,
    ]]);
});

it('emits no collection when the category carries no id', function () {
    $projection = (new FreshaServiceProjector)->project(recordView([
        'name' => 'Standard Haircut', 'price' => 'from $48', 'category' => 'Haircuts',
    ]));

    expect($projection['collections'] ?? [])->toBe([]);
});

// Regression guard for the whole slice's premise: these three shapes are the
// only ones the vendor emits (149 live rows sampled 2026-08-13).
it('maps the three real price shapes', function (string $price, string $qualifier, ?int $minor) {
    $projection = (new FreshaServiceProjector)->project(recordView(['name' => 'X', 'price' => $price]));

    expect($projection['offers'][0]['qualifier'])->toBe($qualifier)
        ->and($projection['offers'][0]['amount_minor'])->toBe($minor);
})->with([
    ['from $108', 'from', 10800],
    ['$120', 'exact', 12000],
    ['free', 'free', 0],
    // Cents must survive: a whole-dollar parse would bill $49.50 as $49.
    ['from $49.50', 'from', 4950],
]);
```

- [ ] **Step 2: Run and watch the first two fail**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=FreshaServiceProjector`
Expected: the two collection cases FAIL; the four price cases already PASS —
the existing parser is correct and this pins it against regression.

- [ ] **Step 3: Emit the collection**

In `project()`, after `$category = $view->string('category');`:

```php
        $categoryId = $view->string('categoryId');
        // Keyed on the vendor's stable numeric category id, never the label:
        // the legacy lane matched on title and so minted a duplicate whenever
        // an owner renamed a category. A category with no id cannot be
        // reconciled across runs, so it stays a tag only.
        $collections = $category === null || $categoryId === null ? [] : [[
            'external_ref' => $categoryId,
            'label' => $category,
            'kind' => 'service_category',
            'position' => 0,
        ]];
```

and add `'collections' => $collections,` to the returned array. Keep the
existing `tags` entry — it is what `SectionCandidates` reads today and nothing
in this slice retires it.

- [ ] **Step 4: Run green**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=FreshaServiceProjector`
Expected: PASS, all six.

- [ ] **Step 5: Commit**

```bash
git add app/Ingest/Projection/FreshaServiceProjector.php tests/
git commit -m "feat(fresha): project categories as collections keyed on the vendor id"
```

---

## Task 7: Prove the lane on dev — registration is not execution

**Files:** none changed. This task produces evidence.

Convergence invariant #6. Tasks 1–6 make the lane *capable*; only a real run
makes it *true*. Do this before building read paths on top of it.

- [ ] **Step 1: Deploy the branch to dev**

Push the branch and let Laravel Cloud deploy, or run the command against dev
from local with dev credentials — whichever the repo's runbook prefers.

- [ ] **Step 2: Force a run on the two connections that have a selection**

```bash
php artisan ingest:dispatch --source-key=fresha --force
```

Check the real flag names first: `php artisan ingest:dispatch --help`.
`next_attempt_at` is 2026-08-15 on all four sources (7-day backoff after 3
failures), so an unforced run does nothing.

- [ ] **Step 3: Assert the lane landed records**

```sql
SELECT s.identifier, st.stream_name, st.health, st.consecutive_failures, st.run_seq
FROM ingest.sources s JOIN ingest.streams st ON st.source_id = s.id
WHERE s.source_key='fresha' AND st.stream_name='services' ORDER BY 1;
```

Expected: the two `employee` sources move to `health='ok'` with `run_seq > 0`.
**The three with no selection must stay at zero records** — that is the
`no_selection` decision working, not a failure.

```sql
SELECT cs.kind, count(*) FROM content.source_items si
JOIN content.sources cs ON cs.id = si.source_id
WHERE si.kind='service' GROUP BY 1;
```

Expected: `manual` still **21** (3a's rows, untouched), `connection` > 0.

```sql
SELECT o.qualifier, count(*), min(o.amount_minor), max(o.amount_minor)
FROM content.offers o
JOIN content.items i ON i.id=o.item_id
JOIN content.sources cs ON cs.id=o.source_id
WHERE i.kind='service' AND cs.kind='connection' GROUP BY 1;

SELECT count(*) FROM content.offers o JOIN content.items i ON i.id=o.item_id
WHERE i.kind='service' AND o.qualifier='exact' AND o.amount_minor=0;   -- gate: 0

SELECT c.label, c.external_ref, count(ci.item_id) AS items
FROM content.collections c
LEFT JOIN content.collection_items ci ON ci.collection_id=c.id
WHERE c.kind='service_category' GROUP BY 1,2 ORDER BY 3 DESC;
```

Expected: a mix of `from` and `exact`, zero zero-amount `exact` rows, and
collections matching the live menu's categories.

- [ ] **Step 4: Run it a SECOND time and assert nothing is destroyed**

Parent §8.3: `mergeInto()` hard-deletes a discarded item carrying neither a pin
nor an override. Re-run the same command, then re-run every query in Step 3.

Expected: identical counts. **Also re-assert the owner half is intact** —
`content.items WHERE kind='service'` must still show 18 live / 3 retired for the
manual source.

- [ ] **Step 5: Check the logs and Nightwatch**

```bash
cloud env:logs partna development --minutes 15
```

Expected: no `SQLSTATE`, no `42703`, no `42702`, no `23505`. Then check
Nightwatch — slice 0 recorded a log scan performed and a Nightwatch scan
skipped; do not repeat that gap.

- [ ] **Step 6: Paste every result into a checkpoint note on the branch**

This is the evidence §5.1 of the spec requires. If any gate fails, **stop** and
fix before Task 8 — every later task assumes this lane works.

---

## Task 8: `ServiceCollections` — the one read/write for categories

**Files:**
- Create: `app/Services/Content/ServiceCollections.php`
- Test: `tests/Feature/Content/ServiceCollectionsTest.php`

**Interfaces:**
- Produces:
  - `list(string $userId, bool $includeRemoved = false): Collection` — `stdClass` rows: `id`, `label`, `position`, `external_ref`, `is_user_created`, `removed_at`, `item_count`
  - `find(string $userId, string $id, bool $includeRemoved = false): ?object`
  - `create(string $userId, string $label): string` — returns the new id
  - `rename(string $userId, string $id, string $label): void`
  - `reposition(string $userId, array $orderedIds): void`
  - `remove(string $userId, string $id): void` — sets `removed_at`
  - `restore(string $userId, string $id): void` — clears `removed_at`
  - `assign(string $userId, string $itemId, ?string $collectionId, ?string $sourceId): void`

- [ ] **Step 1: Write the failing tests**

```php
it('hides machine-derived collections that have no items left', function () {
    $userId = userWithCollections();
    $empty = collectionFor($userId, external: '999', label: 'Departed');   // no memberships

    expect(app(ServiceCollections::class)->list($userId)->pluck('id'))->not->toContain($empty);
});

it('keeps a user-created collection with no items', function () {
    $userId = userWithCollections();
    $id = app(ServiceCollections::class)->create($userId, 'Empty But Mine');

    expect(app(ServiceCollections::class)->list($userId)->pluck('id'))->toContain($id);
});

it('excludes removed collections unless asked for them', function () {
    $userId = userWithCollections();
    $id = app(ServiceCollections::class)->create($userId, 'Gone');
    app(ServiceCollections::class)->remove($userId, $id);

    expect(app(ServiceCollections::class)->list($userId)->pluck('id'))->not->toContain($id)
        ->and(app(ServiceCollections::class)->list($userId, includeRemoved: true)->pluck('id'))->toContain($id);
});

it('restores a removed collection', function () {
    $userId = userWithCollections();
    $id = app(ServiceCollections::class)->create($userId, 'Back');
    app(ServiceCollections::class)->remove($userId, $id);
    app(ServiceCollections::class)->restore($userId, $id);

    expect(app(ServiceCollections::class)->find($userId, $id)->removed_at)->toBeNull();
});

it('never returns another user\'s collection', function () {
    $mine = userWithCollections();
    $theirs = userWithCollections();
    $id = app(ServiceCollections::class)->create($theirs, 'Theirs');

    expect(app(ServiceCollections::class)->find($mine, $id))->toBeNull();
});

it('moves an item between collections', function () {
    $userId = userWithCollections();
    $itemId = serviceItemFor($userId);
    $a = app(ServiceCollections::class)->create($userId, 'A');
    $b = app(ServiceCollections::class)->create($userId, 'B');

    app(ServiceCollections::class)->assign($userId, $itemId, $a, null);
    app(ServiceCollections::class)->assign($userId, $itemId, $b, null);

    expect(DB::table('content.collection_items')->where('item_id', $itemId)->pluck('collection_id')->all())
        ->toBe([$b]);
});

it('clears an item\'s category when passed null', function () {
    $userId = userWithCollections();
    $itemId = serviceItemFor($userId);
    $a = app(ServiceCollections::class)->create($userId, 'A');
    app(ServiceCollections::class)->assign($userId, $itemId, $a, null);

    app(ServiceCollections::class)->assign($userId, $itemId, null, null);

    expect(DB::table('content.collection_items')->where('item_id', $itemId)->count())->toBe(0);
});
```

- [ ] **Step 2: Run and watch them fail**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=ServiceCollections`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `ServiceCollections`**

Key rules the tests above encode, and which must be comments in the class:

- `list()` filters **empty machine-derived** collections (`is_user_created = false`
  AND no live memberships). A category that vanishes from the vendor's menu has
  its memberships replaced away by `ProjectionWriter`; it is not a deletion, so
  it must not get `removed_at`, and it must not render as an empty group.
- `remove()`/`restore()` are the **only** writers of `removed_at`. The
  projection path never touches it (Task 5 enforces this on its side).
- `create()` sets `is_user_created = true`, `external_ref = null`,
  `position = max(position) + 1`.
- `assign()` replaces the item's memberships for **this** source
  (`source_id = ?`, null for owner-authored), matching Task 5's semantics.
- Every method is scoped by `user_id`. Cross-tenant reads return null, never
  another user's row.

- [ ] **Step 4: Run green**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=ServiceCollections`
Expected: PASS, all seven.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Content/ServiceCollections.php tests/
git commit -m "feat(content): the one read/write for service-category collections"
```

---

## Task 9: The seven `/service-categories/*` routes

**Files:**
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserServiceCategoryController.php`
- Test: `tests/Feature/Api/User/ServiceCategoryEndpointCutoverTest.php` (create)

**Interfaces:**
- Consumes: `ServiceCollections` (Task 8).

**Wire shapes do not change.** `ServiceCategoryResource` keeps its field list;
only the backing store moves.

- [ ] **Step 1: Write the failing tests — one per route, shape-pinned**

```php
it('lists categories with the unchanged response shape', function () {
    $user = userWithServiceCollections();

    $response = $this->withToken(tokenFor($user))->getJson('/api/service-categories');

    $response->assertOk()->assertJsonStructure(['data' => ['categories' => [['id', 'title', 'sort_order', 'source']]]]);
});

it('creates a category owned by the user', function () {
    $user = userWithServiceCollections();

    $response = $this->withToken(tokenFor($user))->postJson('/api/service-categories', ['title' => 'Colour']);

    $response->assertCreated();
    expect(DB::table('content.collections')
        ->where('user_id', $user->id)->where('label', 'Colour')->value('is_user_created'))->toBeTrue();
});

it('renames a category', function () { /* PATCH, assert label moved */ });
it('reorders categories', function () { /* POST reorder, assert positions */ });
it('soft-deletes a category and hides it from the list', function () { /* DELETE, assert removed_at set */ });
it('restores a soft-deleted category', function () { /* POST restore, assert removed_at null */ });
it('404s another user\'s category on every route', function () { /* the tenancy sweep */ });
```

Fill in each body properly — the three one-liners above are shorthand for **you
to expand**, not to leave as-is. Copy the assertion style from
`tests/Feature/Api/User/ServiceEndpointCutoverTest.php`, which 3a wrote for
exactly this purpose.

- [ ] **Step 2: Run and watch them fail**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=ServiceCategoryEndpointCutover`
Expected: FAIL — the controller still reads `site.service_categories`.

- [ ] **Step 3: Cut the controller over**

Replace each method's `ServiceCategory` model access with the corresponding
`ServiceCollections` call. `show`/`restore` are declared `->withTrashed()` in
the route, so they pass `includeRemoved: true`.

**Route-model binding must go.** `ServiceCategory $category` binds an Eloquent
model against `site.service_categories`; a collection id will not resolve. Change
the signatures to take the raw id and resolve through `ServiceCollections::find()`,
returning 404 when it is null — 404, not 403, per the repo's enumeration rule.

**Authorization:** `ServiceCategoryPolicy` authorises against the legacy model.
Route the new path through `ContentCollectionPolicy`, which already exists for
`content.collections`. Do **not** add inline `abort_unless` checks — CI fails the
build on inline 403 aborts.

**`ServiceCategoryResource` must be adapted, not merely re-fed.** It computes
`'source'` as `$this->resource instanceof ServiceCategory ? $this->resource->source : null`.
Handed a `content.collections` row it returns **null for every category**,
including Fresha-derived ones -- a silent wire regression on the one field whose
own comment says the dashboard needs it to tell a synced category from an
editable one. Map it: `is_user_created === false` -> `'fresha'`,
`true` -> `null`. Same shape, same treatment: `title` <- `label`,
`sort_order` <- `position`, `deleted_at` <- `removed_at`.

- [ ] **Step 4: Run green**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=ServiceCategoryEndpointCutover`
Expected: PASS.

- [ ] **Step 5: Add the three cache lanes**

Every write route (`store`, `update`, `destroy`, `reorder`, `restore`) calls
`ManualServiceWriter::invalidate([$siteId])`. Add a test asserting an **exact**
build-state revision delta of 1 plus a dispatched purge — not `> 0`.

- [ ] **Step 6: Run green and commit**

```bash
COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=ServiceCategory
git add app/Http/Controllers tests/
git commit -m "feat(services): the seven category routes read and write content.collections"
```

---

## Task 10: `resync`, `resyncBulk`, `updateCategory`

**Files:**
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php:403-470`
- Modify: `app/Policies/ServicePolicy.php:55-77`
- Modify: `app/Services/Content/ManualServiceItems.php:225-231`
- Test: `tests/Feature/Api/User/ServiceResyncCutoverTest.php` (create)

**Interfaces:**
- Consumes: `ServiceCollections::assign()` (Task 8).

- [ ] **Step 1: Write the failing tests**

```php
it('reverts an owner edit by dropping the override', function () {
    [$user, $itemId] = freshaServiceItem();
    DB::table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId,
        'facet' => 'f_text', 'column_name' => 'headline',
        'value' => json_encode('My Edited Name'), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->withToken(tokenFor($user))->postJson("/api/services/{$itemId}/resync")->assertOk();

    expect(DB::table('content.manual_overrides')->where('item_id', $itemId)->count())->toBe(0);
});

it('422s a service that is no longer offered on Fresha', function () {
    [$user, $itemId] = freshaServiceItem();
    // No LIVE source item = nothing to revert to.
    DB::table('content.source_items')->where('item_id', $itemId)->update(['removed_at' => now()]);

    $this->withToken(tokenFor($user))->postJson("/api/services/{$itemId}/resync")->assertStatus(422);
});

it('reverts every edited service in bulk and reports the counts', function () {
    // assert the {resynced, skipped} shape is unchanged
});

it('lets an owner-authored service be assigned to a category', function () {
    // 3a gated this to source='fresha' because content.* had no membership
    // concept. It has one now, so the gate comes off.
    [$user, $itemId] = ownerAuthoredServiceItem();
    $categoryId = app(ServiceCollections::class)->create($user->id, 'Colour');

    $this->withToken(tokenFor($user))
        ->patchJson("/api/services/{$itemId}/category", ['category_id' => $categoryId])
        ->assertOk();

    expect(DB::table('content.collection_items')->where('item_id', $itemId)->value('collection_id'))
        ->toBe($categoryId);
});

it('renders the real category on the public payload, not the Services constant', function () {
    [$user, $itemId] = ownerAuthoredServiceItem();
    $categoryId = app(ServiceCollections::class)->create($user->id, 'Colour');
    app(ServiceCollections::class)->assign($user->id, $itemId, $categoryId, null);

    $row = app(ManualServiceItems::class)->publicList($user->id, $user->site);

    expect($row[0]['category'])->toBe('Colour');
});
```

- [ ] **Step 2: Run and watch them fail**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=ServiceResyncCutover`
Expected: FAIL.

- [ ] **Step 3: Reimplement `resync` / `resyncBulk`**

Legacy `revert()` restored the row from the stored raw scrape and `refreshBlob()`
rewrote the blob. In `content.*` an owner edit **is** a `content.manual_overrides`
row, so reverting is deleting those rows — the synced per-source facet values are
already there and become visible again immediately. The 422 case maps to "the
item has no live `content.source_items` row on a connection source", which is
exactly "no longer offered on Fresha".

Keep both response shapes byte-identical: `{service: …}` and
`{resynced: int, skipped: int}`. Keep the `ids` validation
(`sometimes|array|max:500`, `ids.* uuid`).

- [ ] **Step 4: Remove the policy gate**

Delete the `source !== 'fresha'` branch in `ServicePolicy::updateCategory()` and
rewrite the docblock to record that 3b landed the destination. **The docblock
currently states the coupling explicitly** — that
`SitepageDataResolverService`/`ManualServiceItems::publicList()` hardcodes
`'category' => 'Services'` and is *"only honest while this restriction holds, so
if one of the two moves the other must move with it."* Both move here; neither
may move alone.

- [ ] **Step 5: Fix `publicList()`'s hardcoded category**

`ManualServiceItems::publicList()` returns `'category' => 'Services'` for every
row. Replace with the item's real collection label, falling back to `'Services'`
when it has none — preserving today's output for the unassigned case.

- [ ] **Step 6: Run green**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter="ServiceResyncCutover|ManualServiceItems|ServicePolicy"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers app/Policies app/Services/Content tests/
git commit -m "feat(services): resync drops overrides; category assignment opens to owner services"
```

---

## Task 11: `StaffServiceManagementController`

**Files:**
- Modify: `app/Http/Controllers/Api/Staff/StaffServiceManagementController.php` (all 9 methods)
- Test: `tests/Feature/Api/Staff/StaffServiceManagementCutoverTest.php` (create)

**Interfaces:**
- Consumes: `ManualServiceItems`, `ManualServiceWriter`, `FreshaServiceItems`.

**The defect, stated plainly:** post-3a an owner-authored service has **no
`site.services` row at all**, so staff cannot see, edit or delete it — not
merely see it stale. A staff edit to a row that does exist returns 200 and
changes nothing public.

- [ ] **Step 1: Write the failing test that proves the defect from the outside**

```php
it('shows staff a service created through the user endpoints', function () {
    $user = userWithSite();
    $this->withToken(tokenFor($user))->postJson('/api/services', [
        'title' => 'Created via dashboard', 'price_cents' => 5000, 'currency_code' => 'AUD',
    ])->assertCreated();

    $response = $this->withToken(staffToken())->getJson("/api/staff/users/{$user->id}/services");

    expect(collect($response->json('data.services'))->pluck('title'))
        ->toContain('Created via dashboard');
});

it('lets staff edit that service and the change reaches the public payload', function () {
    // The silent-200 defect: assert the PUBLIC read changes, not just the response.
});

it('lets staff delete that service', function () { /* assert items.removed_at set */ });
```

Check the real staff route paths in `routes/api/staff.php` before writing the
URLs — **staff routes live in three separate groups** with different middleware;
put nothing in the wrong one.

**Do not assert on `ManualServiceItems::publicList()`'s `category` field in this
task's tests.** Task 10 is editing that exact method in the same wave. Assert
the staff list on `title` presence instead.

- [ ] **Step 2: Run and watch them fail**

Expected: FAIL — the created service is absent from the staff list entirely.

- [ ] **Step 3: Cut all nine methods over**

`index`, `store`, `show`, `update`, `destroy`, `reorder`, `reorderLayout`,
`restore`, `forceDestroy` — each onto the same collaborators the user controller
uses. **Do not write a parallel implementation**: a second independent copy is
what let this rot silently. Where the user controller merges the manual and
Fresha halves for its list, the staff controller does the same, through the same
methods.

Mind `services_user_sort_order_uq` on `reorder`/`reorderLayout`: it is
`UNIQUE (user_id, sort_order) WHERE deleted_at IS NULL`, **global per user, not
scoped by source**, so renumbering one half collides with the other.

- [ ] **Step 4: Run green**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=StaffServiceManagement`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Staff tests/
git commit -m "fix(staff): the service controller can see owner-authored services again"
```

---

## Task 12: The booking surface reads the pool

**Files:**
- Create: `app/Services/Content/FreshaServiceItems.php`
- Modify: `app/Http/Resources/Platforms/FreshaSelectionResource.php`
- Test: `tests/Feature/Platforms/FreshaBookingSurfaceTest.php` (create)

**Interfaces:**
- Produces: `FreshaServiceItems::selectionServices(string $userId): array` —
  the `services[]` wire shape: `name`, `price`, `category`, `currency`,
  `duration`, `serviceId`, `priceValue`, `description`, `hasVariants`.

- [ ] **Step 1: Write the failing tests — the shape is the contract**

```php
it('reproduces the stored blob\'s service shape exactly', function () {
    [$user] = freshaConnectionWithLandedItems();

    $services = app(FreshaServiceItems::class)->selectionServices($user->id);

    expect(array_keys($services[0]))->toEqualCanonicalizing([
        'name', 'price', 'category', 'currency', 'duration', 'serviceId',
        'priceValue', 'description', 'hasVariants',
    ]);
});

it('round-trips every price qualifier back to its display string', function (string $qualifier, ?int $minor, string $display) {
    $user = userWithFreshaOffer($qualifier, $minor);

    expect(app(FreshaServiceItems::class)->selectionServices($user->id)[0]['price'])->toBe($display);
})->with([
    ['from', 10800, 'from $108'],
    ['exact', 12000, '$120'],
    ['free', 0, 'free'],
    // Cents render only when there are cents -- "$120.00" would be a wire change.
    ['from', 4950, 'from $49.50'],
    ['exact', 3150, '$31.50'],
]);

// RULING (controller, 2026-08-13): services[] does NOT filter hidden rows.
// FreshaSelectionResource passes `services` through verbatim and carries
// `hiddenServiceIds` as a separate sibling key; filtering here would be the
// wire change spec 3.7 forbids, and would break the dashboard's un-hide
// affordance, which needs the hidden rows present in order to render them.
it('keeps a hidden service in services[] and leaves the hiding to hiddenServiceIds', function () {
    [$user] = freshaConnectionWithLandedItems();   // one of whose items is hidden

    $payload = /* the FreshaSelectionResource payload for $user */;

    expect(collect($payload['services'])->pluck('serviceId'))->toContain($hiddenId)
        ->and($payload['hiddenServiceIds'])->toContain($hiddenId);
});
```

- [ ] **Step 2: Run and watch them fail**

Expected: FAIL — class not found.

- [ ] **Step 3: Implement `FreshaServiceItems`**

Mirror `ManualServiceItems`' structure but scoped to the user's **connection**
source rather than the manual one. That scoping is what keeps the two-surface
rule true by construction: the services section reads
`content.sources.kind = 'manual'`, so a Fresha item cannot reach it.

The price formatter is the load-bearing part:

```php
    /**
     * qualifier + amount_minor -> the vendor's own display string. The wire
     * has always carried a bare '$' (Fresha emits it and `currency` is null in
     * the stored blob), so this does not invent 'A$' or 'AUD'. Cents render
     * only when non-zero: '$120.00' would be a wire change, '$49.50' would be
     * a data loss if truncated.
     */
    private function displayPrice(?string $qualifier, ?int $amountMinor): ?string
    {
        if ($qualifier === 'free') {
            return 'free';
        }
        if ($amountMinor === null) {
            return null;
        }

        $amount = $amountMinor % 100 === 0
            ? (string) intdiv($amountMinor, 100)
            : number_format($amountMinor / 100, 2, '.', '');

        return ($qualifier === 'from' ? 'from $' : '$').$amount;
    }
```

- [ ] **Step 4: Point the Resource at it**

`FreshaSelectionResource::toArray()` keeps every key. Only `services` changes
source. `url`, `storeName`, `mode`, `employee` and `hiddenServiceIds` still come
from the stored selection — they are the owner's choices, not scraped content,
and this slice does not move them.

- [ ] **Step 5: Run green**

Run: `COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --filter=FreshaBookingSurface`
Expected: PASS, all seven cases.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Content/FreshaServiceItems.php app/Http/Resources tests/
git commit -m "feat(booking): the Fresha menu renders from content.*"
```

---

## Task 13: The guard tests

**Files:**
- Test: `tests/Feature/Content/ServiceTwoSurfaceTest.php` (create)
- Test: `tests/Feature/Architecture/FreshaMapperGuardTest.php` (create)

These exist to fail if a later change quietly reverses a decision this slice
made. Each must be mutation-checked: break the thing, watch it go red, restore.

- [ ] **Step 1: The two-surface test**

```php
it('never renders a Fresha service in the public services section', function () {
    [$user] = freshaConnectionWithLandedItems();

    $services = app(ManualServiceItems::class)->publicList($user->id, $user->site);

    expect(collect($services)->pluck('title'))->not->toContain('Standard Haircut');
});

it('never renders an owner-authored service on the booking surface', function () {
    $user = userWithOwnerAuthoredService('My Own Service');

    $services = app(FreshaServiceItems::class)->selectionServices($user->id);

    expect(collect($services)->pluck('name'))->not->toContain('My Own Service');
});
```

- [ ] **Step 2: The zero-price trap guard**

```php
// spec §1.5: ManualServiceWriter::projectionFor() maps price_cents 0 -> 'free',
// which is right for hand-entered data and a lie on scraped data. All 61 legacy
// Fresha rows carry price_cents = 0 because the stored blob's priceValue is null.
it('routes no Fresha service through the owner-authored price mapper', function () {
    $callers = collect(glob(app_path('**/*.php'), GLOB_BRACE))
        ->filter(fn ($f) => str_contains(file_get_contents($f), 'projectionFor('));

    expect($callers->map(fn ($f) => basename($f))->values()->all())
        ->toEqualCanonicalizing(['ServiceBackfiller.php', 'UserServiceController.php', 'ManualServiceWriter.php']);
});
```

Adjust the expected list to whatever the real callers are **after** Task 11 — the
point is that the list is pinned and a new caller fails the build, forcing a
deliberate look. Use a recursive iterator rather than `glob('**')`, which does
not recurse in PHP.

- [ ] **Step 3: Run green, then mutation-check both**

For the two-surface test: change `FreshaServiceItems` to scope on `kind` `manual`
and confirm RED. For the trap guard: add a `projectionFor(` call in a scratch
file and confirm RED. Restore both.

- [ ] **Step 4: Commit**

```bash
git add tests/
git commit -m "test: pin the two-surface rule and the scraped-price trap"
```

---

## Task 14: Wire manifest, checkpoint, and the downstream prompts

**Files:**
- Create: `docs/wire-changes/2026-08-13-slice-3b-services-fresha.md`
- Modify: `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` (append §18)
- Modify: the slice 4, 5b, 6 and 7 kickoff prompts

- [ ] **Step 1: Write the wire manifest**

Endpoint, before shape, after shape, consuming repo — parent §10. Cover: the 10
endpoints, the staff controller, `FreshaSelectionResource.services[]`, and the
public services payload's `category` field, which stops being the constant
`'Services'`. Name **Partna-App** and **partna-monorepo** as consumers.

- [ ] **Step 2: Append the checkpoint to the parent spec**

`## 18. Slice 3b checkpoint — Fresha services`. Include the Task 7 SQL with its
real pasted output, the Pest names proving connector → projector → item → pool →
wire, and the log/Nightwatch scan results. **No figure may be copied from this
plan — re-run it.**

- [ ] **Step 3: Edit the downstream prompts in place**

A checkpoint is not a communication channel; parent invariant #5 forbids citing
one as evidence, so a discovery written only into a checkpoint never reaches the
next session. Say the fact, not the story:

- **Slices 4–7:** `Pull.config` now carries a third key, `selection_ref`.
- **Slices 4, 6:** `ProjectionWriter` accepts a `collections` key; use it rather
  than writing a per-connector collections writer.
- **Slice 7:** there are **18** service routes, not 17 —
  `UserServiceCategoryController` has seven, not six. Its inventory inherits the
  same undercount.
- **Slice 7:** `anseo-studio`'s connection has no ingest source because
  `SourceProvisioner::freshaSlug()` matches only `fresha.com/…/a/<slug>` and that
  row is a `book-now/…?pId=` URL.

- [ ] **Step 4: Commit**

```bash
git add docs/
git commit -m "docs: slice 3b wire manifest, checkpoint, and downstream corrections"
```

---

## Task 15: Merge

- [ ] **Step 1: Full local gates**

```bash
COMPOSER_PROCESS_TIMEOUT=0 composer test
composer test:pg
composer test:schema
./vendor/bin/phpstan analyse
php artisan pint
```

All green. `composer test:pg` is **not optional** in this slice.

- [ ] **Step 2: Rebase onto `development`**

```bash
git fetch origin
git rebase origin/development
```

Expect 1–3 fetch+rebase cycles — sibling sessions push between your fetch and
your push.

- [ ] **Step 3: Resolve the `PoolRegistry` collision deliberately**

Slice 5b adds a `shop` entry to the same four const arrays and edits the same
docblock sentence. It is a union, not a design conflict. After resolving,
**re-run `PoolRegistryTest` and the pool provisioning tests** — a union merge
that drops half a const array still passes every test written by the branch that
added the other half.

- [ ] **Step 4: Re-run the full suite ON THE REBASED TREE**

Not on the pre-rebase tree. A semantic conflict passes both branches
individually and fails their merge.

- [ ] **Step 5: STOP — explicit sign-off before merging**

- [ ] **Step 6: Merge and push**

```bash
git push origin feat/slice-3b-fresha:development
```

**Never push to `production`.**

- [ ] **Step 7: Verify the deploy**

```bash
cloud env:logs partna development --minutes 10
```

Re-run Task 7's assertions against dev post-deploy. Check Nightwatch.

---

## Self-Review

**Spec coverage.** §3.1 → Tasks 3–4. §3.2 → Task 2. §3.3 → Tasks 1, 5. §3.4 →
Tasks 5, 6, 8 (landed, not migrated — no migration task exists, deliberately).
§3.5 → Tasks 9, 10. §3.6 → Task 11. §3.7 → Task 12. §4 → Tasks 9, 10 steps.
§5.1 → Task 7. §5.2 → Tasks 3–6, 13. §5.3 → Tasks 1, 5, 15. §5.4 → Tasks 7, 15.
§8 → Tasks 14, 15.

**Known soft spots, flagged rather than hidden.** Three test files are named
from `grep` rather than read (`SourceProvisionerTest`, the connector test, and
`ProjectionWriterTest`); their helper names in Tasks 2, 3 and 5 are placeholders
for whatever those files already call them, and each step says so. Task 9's
three one-line test bodies are explicitly marked for expansion. Task 13's caller
list must be filled from the real tree. Do not treat any of these as finished
code.

**Type consistency.** `selection_ref` is `string|null` everywhere —
`ingest.sources` column, `Pull.config` key, `selectionRefFor()` return,
`fetchBookingFlow()` parameter. The collections entry shape
(`external_ref`/`label`/`kind`/`position`) is identical in Tasks 5 and 6.
`ServiceCollections`' method names in Task 8's Interfaces block are the ones
called in Tasks 9 and 10.
