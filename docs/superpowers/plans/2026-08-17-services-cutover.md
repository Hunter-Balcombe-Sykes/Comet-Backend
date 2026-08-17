# Services Cutover Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** End the dual-id-space era for services — cut the Fresha management surface over to `content.*`, repoint every remaining reader/writer of the three legacy tables, then drop `site.services`, `site.service_categories` and `site.service_category_assignments` on dev.

**Architecture:** The public wire is already single-source (`payload.selection` composes from `content.*`; the armed view migration finishes the KV side). This plan extends the resync verbs' existing content-side resolution pattern (`FreshaServiceItems`) to every management verb, re-homes deleted-state and ordering semantics per spec §3.3/§3.4, then retires the tables behind the backup gate.

**Tech Stack:** Laravel 12, PostgreSQL (Supabase), Pest 4, raw-SQL migrations in `supabase/migrations/`.

**Spec:** `docs/superpowers/specs/2026-08-17-services-cutover-design.md` — read it first; every design decision below cites it.

## Global Constraints

- **Dev only.** Never link, push, or apply anything against the prod ref `edplucmvkcnokyygxqsb`. Never `git push origin development:production`.
- **Ordering law (spec §3):** every `content.*` write path lands and is live-verified on dev BEFORE the DROP unit (Task 12). The DROPs are one unit, last.
- **No Laravel migrations.** Schema changes are raw SQL in `supabase/migrations/`, one concern per file, `CONCURRENTLY` at most once per file.
- **Test helpers are prefixed `svcCut`** (file-local Pest helper functions collide at load time across files and fatal a `--parallel` run).
- **`composer test:pg` is mandatory** for any task touching `ProjectionWriter` callers or constraint-bound `content.*` writes (Tasks 2–9). SQLite has passed what Postgres rejects twice in this programme. `tests/Postgres/` stand-in DDL is hand-written — update it when schema assumptions change.
- **PHPStan in a worktree:** `php -d memory_limit=1G ./vendor/bin/phpstan analyse app tests --no-progress --debug` (default invocation OOMs and reports it as "severe errors").
- **Cache invalidation is caller-owned.** Every new/changed write path fires all three lanes via `ManualServiceWriter::invalidate([$siteId])` + `$site->touch()` where the existing method does; never re-roll the lanes locally. Assert exact revision deltas in tests, never `> 0`.
- **Wire manifest:** `docs/wire-changes/2026-08-17-services-cutover.md` — every key/id-domain/behaviour change lands there in the same commit that ships it.
- **Branch:** `feat/services-cutover` off current `origin/development`, in a worktree (superpowers:using-git-worktrees).
- **Two-surface rule is inviolable:** `tests/Feature/Content/ServiceTwoSurfaceTest.php` must stay green UNMODIFIED through every task.

## File Structure

```
app/Services/Content/FreshaServiceItems.php        — grows the management read (find/rows/models), override folding, hidden-list read
app/Services/Platforms/FreshaServiceProjector.php  — gains setHidden(); loses revert()
app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php   — §C2 legacy branches replaced
app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceManagementController.php — same, staff twin
app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceCategoryManagementController.php — legacy by-id branches deleted
app/Http/Controllers/Api/Platforms/FreshaController.php — forget() stops writing site.services
app/Services/Cache/UserCacheService.php            — both merge reads repointed
app/Services/Analytics/ContentFreshness.php        — freshness read repointed
app/Services/Site/LegacyServiceSortOrder.php       — DELETED (function re-homed onto section_items.sort_key)
app/Observers/Core/ServiceObserver.php, ServiceCategoryObserver.php — DELETED
app/Services/Migration/ServiceBackfiller.php, app/Console/Commands/BackfillOwnerServices.php — DELETED
app/Models/Core/User/Service.php, ServiceCategory.php — slimmed to in-memory DTOs
supabase/migrations/20260818000100..000300_drop_*.sql — the three DROPs
tests/Feature/Services/ServicesCutoverFreshaManagementTest.php — new (Tasks 3–4, 6–7)
tests/Feature/Services/ServicesCutoverOrderingTest.php — new (Task 5)
tests/Feature/Architecture/LegacyServiceQuerySurfaceTest.php — new guard (Task 10)
```

---

### Task 1: Apply the armed view migration to dev (spec Unit 1)

No code. The migration `supabase/migrations/20260817000000_public_site_payload_services_from_content.sql` is already committed on `development` and deliberately unapplied. Applying it is this project's first step (spec ruling 3): it removes the standing accidental-`db push` hazard and clears the view dependency the DROP is blocked on.

**Files:** none modified. `docs/wire-changes/2026-08-17-services-cutover.md` created.

- [ ] **Step 1: Confirm the pre-state**

Run (Supabase MCP `execute_sql` against `glncumufgaqcmqhzwrxm`, or psql):

```sql
select version from supabase_migrations.schema_migrations where version = '20260817000000';
-- expect: 0 rows (unapplied)
```

- [ ] **Step 2: Dry-run, then apply**

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run    # MUST list exactly one migration: 20260817000000
supabase db push
```

If the dry-run lists anything else, STOP — the migration set has drifted since 2026-08-17 and the owner decides.

- [ ] **Step 3: Verify the view moved off the three tables**

```sql
SELECT DISTINCT dep.relname, src_ns.nspname||'.'||src.relname AS on_table
FROM pg_depend d
JOIN pg_rewrite r ON r.oid = d.objid
JOIN pg_class dep ON dep.oid = r.ev_class
JOIN pg_class src ON src.oid = d.refobjid
JOIN pg_namespace src_ns ON src_ns.oid = src.relnamespace
WHERE d.refclassid = 'pg_class'::regclass AND d.classid = 'pg_rewrite'::regclass
AND src_ns.nspname='site' AND src.relname IN ('services','service_categories','service_category_assignments')
AND dep.relname <> src.relname;
-- expect: 0 rows. Any row = the migration missed a reference; STOP.
```

- [ ] **Step 4: Re-warm KV and spot-check one sitepage**

```bash
cloud command:run development "php artisan tinker --execute=\"\App\Models\Core\Site\Site::query()->where('is_published', true)->each(fn (\$s) => \App\Jobs\Cloudflare\SyncSubdomainToKvJob::dispatch(\$s->id));\""
```

Then fetch one published site's public profile (`GET https://dev-api.partna.au/api/public/profiles/{handle}`) and its rendered sitepage; the `services` arrays must agree (they carry `content.items` ids on both now — the migration header's own verification, re-run).

- [ ] **Step 5: Record the wire change**

Create `docs/wire-changes/2026-08-17-services-cutover.md`:

```markdown
# Wire changes — services cutover

## 2026-08-17 · KV render payload: `services[].id` domain change (dev)

`site.public_site_payload`'s `services` key now composes from `content.*`
(migration `20260817000000`). Each entry's `id` is the `content.items` id,
where the pre-image emitted the `site.services` id. The public API
(`GET /api/public/profiles/{handle}`) has emitted content ids since slice 3a,
so KV and API now agree. Verified equal element-for-element across all 22
published dev sites at apply time (re-run, not cited).
```

- [ ] **Step 6: Check dev logs are clean, then commit the manifest**

```bash
cloud env:logs partna development --minutes 10
git add docs/wire-changes/2026-08-17-services-cutover.md
git commit -m "docs(services-cutover): apply the armed payload view migration; record the KV id-domain change"
```

---

### Task 2: `FreshaServiceItems` — the management read

The resync verbs already resolve Fresha content items via three private helpers in `UserServiceController` (`freshaContentQuery`/`freshaContentRow`/`freshaServiceModel`, `UserServiceController.php:1283-1344`). This task promotes that pattern onto `FreshaServiceItems` so every verb (user + staff) shares ONE copy, and adds the three capabilities the management surface needs: curation columns (`sort_key` via the services section), the hidden-list read, and manual-override folding (spec §3.2 — D3a promised title/description/duration overrides keep working; today nothing folds them on the Fresha surfaces).

**Files:**
- Modify: `app/Services/Content/FreshaServiceItems.php`
- Test: `tests/Feature/Services/ServicesCutoverFreshaItemsTest.php` (new)

**Interfaces (later tasks consume these — exact signatures):**
- Produces: `FreshaServiceItems::managementRows(string $userId, ?string $sectionId, bool $includeRemoved = false): Collection` — rows with columns `id, headline_cache, removed_at, created_at, updated_at, source_id, record_key, sort_key, state`
- Produces: `FreshaServiceItems::findRow(string $userId, string $itemId, ?string $sectionId, bool $includeRemoved = false, bool $liveOnly = true): ?object` (same columns; `$liveOnly=false` also matches items whose `source_items.removed_at` is set — resync's 422 case)
- Produces: `FreshaServiceItems::toServiceModel(string $userId, object $row, ?array $hidden = null): Service` and `toServiceModels(string $userId, Collection $rows, ?array $hidden = null): Collection` — legacy-shaped unsaved `Service` models: `source='fresha'`, `external_id=record_key`, `is_manual` = has override, `is_active` = `$hidden === null ? true : !in_array(record_key, $hidden)`
- Produces: `FreshaServiceItems::hiddenServiceIds(string $userId): array` — the stored blob's `hiddenServiceIds` (list of vendor serviceIds), `[]` when no connection/selection
- Consumes: `ManualServiceItems::facets()`, `ManualServiceItems::toServiceModel()` (existing, unchanged)

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Services/ServicesCutoverFreshaItemsTest.php`:

```php
<?php

use App\Models\Core\User\User;
use App\Services\Content\FreshaServiceItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupIngestTables();
    setupContentTables();
    Queue::fake();
});

/** One Fresha-landed service item: connection source + item + source_item. Returns [itemId, sourceId]. */
function svcCutItemsFresha(string $userId, string $title, string $recordKey): array
{
    $sourceId = (string) Str::uuid();
    $itemId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $userId, 'kind' => 'service',
        'headline_cache' => $title, 'facets_cache' => '{}', 'eligible_cache' => '{}',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'coord' => 'fresha:'.$recordKey, 'record_key' => $recordKey,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$itemId, $sourceId];
}

function svcCutItemsUser(): User
{
    return createTenant('svccutitems-'.Str::lower(Str::random(8)));
}

it('findRow resolves a connection-sourced service item and managementRows lists it', function () {
    $pro = svcCutItemsUser();
    [$itemId] = svcCutItemsFresha($pro->id, 'Fade', 's:1');

    $items = app(FreshaServiceItems::class);
    $row = $items->findRow($pro->id, $itemId, null);

    expect($row)->not->toBeNull()
        ->and((string) $row->record_key)->toBe('s:1');
    expect($items->managementRows($pro->id, null)->pluck('id')->map(fn ($id) => (string) $id)->all())
        ->toBe([$itemId]);
});

it('toServiceModel stamps source, external_id, is_manual from overrides, and is_active from the hidden list', function () {
    $pro = svcCutItemsUser();
    [$itemId] = svcCutItemsFresha($pro->id, 'Fade', 's:1');
    DB::table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId,
        'facet' => 'f_text', 'column_name' => 'body', 'value' => json_encode('Edited'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $items = app(FreshaServiceItems::class);
    $model = $items->toServiceModel($pro->id, $items->findRow($pro->id, $itemId, null), hidden: ['s:1']);

    expect($model->source)->toBe('fresha')
        ->and((string) $model->external_id)->toBe('s:1')
        ->and($model->is_manual)->toBeTrue()
        ->and($model->is_active)->toBeFalse()
        ->and($model->description)->toBe('Edited');   // the override folds over the raw facet
});

it('selectionServices folds title, description and duration overrides over the vendor values', function () {
    $pro = svcCutItemsUser();
    [$itemId, $sourceId] = svcCutItemsFresha($pro->id, 'Vendor Name', 's:1');
    DB::table('content.f_text')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'headline' => 'Vendor Name', 'body' => 'Vendor description',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.f_duration')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'seconds' => 1800, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([['f_text', 'headline', 'Owner Name'], ['f_text', 'body', 'Owner description'], ['f_duration', 'seconds', 3600]] as [$facet, $column, $value]) {
        DB::table('content.manual_overrides')->insert([
            'id' => (string) Str::uuid(), 'item_id' => $itemId,
            'facet' => $facet, 'column_name' => $column, 'value' => json_encode($value),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $entry = app(FreshaServiceItems::class)->selectionServices($pro->id)[0];

    expect($entry['name'])->toBe('Owner Name')
        ->and($entry['description'])->toBe('Owner description')
        ->and($entry['duration'])->toBe('1h');
});

it('findRow with liveOnly=false still matches an item whose source_item is removed', function () {
    $pro = svcCutItemsUser();
    [$itemId] = svcCutItemsFresha($pro->id, 'Departed', 's:9');
    DB::table('content.source_items')->where('item_id', $itemId)->update(['removed_at' => now()]);

    $items = app(FreshaServiceItems::class);
    expect($items->findRow($pro->id, $itemId, null))->toBeNull()
        ->and($items->findRow($pro->id, $itemId, null, liveOnly: false))->not->toBeNull();
});
```

- [ ] **Step 2: Run; confirm each fails on a missing method** (`findRow` undefined), not on setup.

```bash
./vendor/bin/pest tests/Feature/Services/ServicesCutoverFreshaItemsTest.php
```

- [ ] **Step 3: Implement on `FreshaServiceItems`**

Add to the class (keeping `selectionServices()`, `rows()`, `categories()` and the display helpers):

```php
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\Service;
use App\Services\Platforms\Registry\Platform;

    /**
     * The management-surface query: same joins as the booking read, plus the
     * services section's curation row (sort_key carries the shared ordering
     * scale, spec §3.4). Section-scoped exactly as ManualServiceItems::
     * baseQuery() scopes its join — an item pinned into a second section must
     * not fan out.
     */
    private function managementQuery(string $userId, ?string $sectionId, bool $liveOnly = true): \Illuminate\Database\Query\Builder
    {
        return DB::connection('pgsql')->table('content.items as i')
            ->join('content.source_items as si', 'si.item_id', '=', 'i.id')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->leftJoin('site.section_items as sec', function ($join) use ($sectionId) {
                $join->on('sec.item_id', '=', 'i.id');
                if ($sectionId !== null) {
                    $join->where('sec.section_id', '=', $sectionId);
                } else {
                    $join->whereRaw('1 = 0');
                }
            })
            ->where('i.user_id', $userId)
            ->where('i.kind', 'service')
            ->where('cs.kind', 'connection')
            ->when($liveOnly, fn ($q) => $q->whereNull('si.removed_at'));
    }

    /** @return list<string> */
    private function managementColumns(): array
    {
        return ['i.id', 'i.headline_cache', 'i.removed_at', 'i.created_at', 'i.updated_at',
            'cs.id as source_id', 'si.record_key', 'sec.sort_key', 'sec.state'];
    }

    /** @return Collection<int, \stdClass> */
    public function managementRows(string $userId, ?string $sectionId, bool $includeRemoved = false): Collection
    {
        $query = $this->managementQuery($userId, $sectionId);
        if (! $includeRemoved) {
            $query->whereNull('i.removed_at');
        }

        return $query
            ->orderByRaw('sec.sort_key ASC NULLS LAST')
            ->orderBy('i.first_seen_at')
            ->orderBy('si.id')
            ->distinct()
            ->get($this->managementColumns());
    }

    /** Single item, owner-scoped. $liveOnly=false also matches a departed (source_items.removed_at) item — resync's 422 case. */
    public function findRow(string $userId, string $itemId, ?string $sectionId, bool $includeRemoved = false, bool $liveOnly = true): ?object
    {
        $query = $this->managementQuery($userId, $sectionId, $liveOnly)->where('i.id', $itemId);
        if (! $includeRemoved) {
            $query->whereNull('i.removed_at');
        }

        return $query->distinct()->first($this->managementColumns());
    }

    /** The stored selection blob's hiddenServiceIds — the hidden state rides the blob (D3a). @return list<string> */
    public function hiddenServiceIds(string $userId): array
    {
        $payload = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('platform', Platform::Fresha->value)
            ->value('payload');
        $hidden = is_array($payload) ? ($payload['selection']['hiddenServiceIds'] ?? null) : null;

        return is_array($hidden) ? array_values(array_filter($hidden, 'is_string')) : [];
    }

    /**
     * A Fresha content item as the legacy-shaped Service model. Wraps
     * ManualServiceItems' hydration (which hardcodes the owner-authored
     * answers) and restates the connection-lane truths: provenance, the
     * vendor id, is_manual (= carries an override — the C2-compliant lock),
     * is_active (= not on the blob's hidden list), and the three
     * override-foldable fields (ValueResolver precedence 1: the user's typed
     * value outranks every source).
     *
     * @param  list<string>|null  $hidden  pass hiddenServiceIds() once per
     *                                     list; null = "not consulting
     *                                     hidden state" and reads active.
     */
    public function toServiceModel(string $userId, object $row, ?array $hidden = null): Service
    {
        return $this->toServiceModels($userId, collect([$row]), $hidden)->first();
    }

    /** @param  Collection<int, \stdClass>  $rows  @return Collection<int, Service> */
    public function toServiceModels(string $userId, Collection $rows, ?array $hidden = null): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $itemIds = $rows->pluck('id')->map(fn ($id) => (string) $id)->all();
        $overrides = $this->overridesFor($itemIds);
        $categories = $this->managementCategories($userId, $itemIds, (string) $rows->first()->source_id);

        return $rows->map(function ($row) use ($userId, $hidden, $overrides, $categories) {
            $model = $this->manualServiceItems->toServiceModel($userId, $row);
            $model->source = 'fresha';
            $model->external_id = (string) $row->record_key;
            $itemOverrides = $overrides[(string) $row->id] ?? [];
            $model->is_manual = $itemOverrides !== [];
            if (array_key_exists('f_text.headline', $itemOverrides)) {
                $model->title = (string) $itemOverrides['f_text.headline'];
            }
            if (array_key_exists('f_text.body', $itemOverrides)) {
                $model->description = $itemOverrides['f_text.body'] === null ? null : (string) $itemOverrides['f_text.body'];
            }
            if (array_key_exists('f_duration.seconds', $itemOverrides)) {
                $seconds = $itemOverrides['f_duration.seconds'];
                $model->duration_minutes = $seconds === null ? null : (int) ((int) $seconds / 60);
            }
            if ($hidden !== null) {
                $model->is_active = ! in_array((string) $row->record_key, $hidden, true);
            }
            $model->setRelation('categories', collect($categories[(string) $row->id] ?? [])->map(function ($collection) {
                $category = new \App\Models\Core\User\ServiceCategory;
                $category->exists = false;
                $category->id = (string) $collection->id;
                $category->title = (string) $collection->label;

                return $category;
            })->values());

            return $model;
        })->values();
    }

    /**
     * Override values for the three D3a-supported fields, keyed
     * "facet.column" per item. A stored SQL-null value IS an entry (explicit
     * clear) — array_key_exists, never isset, on the result.
     *
     * @return array<string, array<string, mixed>>
     */
    private function overridesFor(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $out = [];
        $rows = DB::connection('pgsql')->table('content.manual_overrides')
            ->whereIn('item_id', $itemIds)
            ->whereIn(DB::raw("facet || '.' || column_name"), ['f_text.headline', 'f_text.body', 'f_duration.seconds'])
            ->get(['item_id', 'facet', 'column_name', 'value']);
        foreach ($rows as $row) {
            $out[(string) $row->item_id][$row->facet.'.'.$row->column_name] = json_decode((string) $row->value, true);
        }

        return $out;
    }

    /**
     * Management-surface memberships: the owner-lane row (source_id NULL,
     * written by updateCategory's Fresha branch) WINS over the connection
     * lane (written by the projector, replaced every run) — the same
     * owner-outranks-source precedence ValueResolver applies to values.
     *
     * @return array<string, list<\stdClass>>
     */
    private function managementCategories(string $userId, array $itemIds, string $sourceId): array
    {
        $live = app(ServiceCollections::class)->list($userId)->keyBy(fn ($row) => (string) $row->id);
        if ($live->isEmpty()) {
            return [];
        }

        $memberships = DB::connection('pgsql')->table('content.collection_items')
            ->whereIn('item_id', $itemIds)
            ->where(fn ($q) => $q->whereNull('source_id')->orWhere('source_id', $sourceId))
            ->orderBy('position')
            ->get(['item_id', 'collection_id', 'source_id']);

        $byItem = [];
        foreach ($memberships as $membership) {
            $collection = $live->get((string) $membership->collection_id);
            if ($collection === null) {
                continue;
            }
            $key = (string) $membership->item_id;
            // Owner lane replaces whatever the connection lane contributed.
            if ($membership->source_id === null) {
                $byItem[$key] = ['owner' => true, 'rows' => [...(($byItem[$key]['owner'] ?? false) ? $byItem[$key]['rows'] : []), $collection]];
            } elseif (! ($byItem[$key]['owner'] ?? false)) {
                $byItem[$key] = ['owner' => false, 'rows' => [...($byItem[$key]['rows'] ?? []), $collection]];
            }
        }

        return array_map(fn ($entry) => $entry['rows'], $byItem);
    }
```

Then fold the same `overridesFor()` values into the booking shape — in `selectionServices()`, after `$facets['categories'] = ...`, add:

```php
        $overrides = $this->overridesFor($itemIds);
```

pass `$overrides` into `toWireShape($row, $facets, $overrides)` and, inside `toWireShape()`, apply before building the array:

```php
        $itemOverrides = $overrides[(string) $row->id] ?? [];
        $name = array_key_exists('f_text.headline', $itemOverrides)
            ? (string) $itemOverrides['f_text.headline']
            : (string) ($row->headline_cache ?? '');
        $description = array_key_exists('f_text.body', $itemOverrides)
            ? ($itemOverrides['f_text.body'] === null ? null : (string) $itemOverrides['f_text.body'])
            : ($facets['descriptions'][$row->id] ?? null);
        if (array_key_exists('f_duration.seconds', $itemOverrides)) {
            $seconds = $itemOverrides['f_duration.seconds'] === null ? null : (int) $itemOverrides['f_duration.seconds'];
        }
```

and use `$name`/`$description`/`$seconds` in the returned array (price/priceValue/currency stay vendor-only — D3a: no offers override lane, do not add one).

Also make `categories()` prefer the owner lane, mirroring `managementCategories()`: widen its membership query's filter from `->where('ci.source_id', $sourceId)` to `->where(fn ($q) => $q->whereNull('ci.source_id')->orWhere('ci.source_id', $sourceId))`, order owner-lane rows first (`->orderByRaw('ci.source_id IS NOT NULL')` before `->orderBy('ci.position')`) and keep first-wins.

- [ ] **Step 4: Run the new file, the booking-surface pins, and the two-surface pins**

```bash
./vendor/bin/pest tests/Feature/Services/ServicesCutoverFreshaItemsTest.php tests/Feature/Platforms/FreshaBookingSurfaceTest.php tests/Feature/Content/ServiceTwoSurfaceTest.php
```

`FreshaBookingSurfaceTest`'s "reproduces the stored blob's service shape exactly" must stay green — override folding with no override rows present is a no-op.

- [ ] **Step 5: `composer test:pg`** (content.* read shapes changed) — expect green.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Content/FreshaServiceItems.php tests/Feature/Services/ServicesCutoverFreshaItemsTest.php
git commit -m "feat(services-cutover): FreshaServiceItems management read — find/rows/models, hidden list, override folding"
```

---

### Task 3: `UserServiceController` — show/update/destroy/restore off the legacy table

Replace the four §C2 legacy branches with resolution through `FreshaServiceItems`. Ids: `content.items` ids only (spec ruling 1 — legacy uuids 404, pinned by test).

**Files:**
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php` (`show:309-321`, `update:331-366`, `destroy:772-799`, `restore:1167-1209`)
- Modify: `app/Services/Platforms/FreshaServiceProjector.php` (add `setHidden()`)
- Test: `tests/Feature/Services/ServicesCutoverFreshaManagementTest.php` (new)

**Interfaces:**
- Consumes: everything Task 2 produced.
- Produces: `FreshaServiceProjector::setHidden(User $user, string $serviceId, bool $hidden): void` — updates the blob's `hiddenServiceIds` and recomposes (Task 6 reuses it for the staff twin).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Services/ServicesCutoverFreshaManagementTest.php` (helpers prefixed `svcCutMgmt`; reuse the fixture shape from Task 2's `svcCutItemsFresha` under the new prefix — file-local helpers may not be shared across Pest files):

```php
<?php

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

function svcCutMgmtUser(): User
{
    return createTenant('svccutmgmt-'.Str::lower(Str::random(8)));
}

/** Fresha content item + a stored connection blob naming it. Returns itemId. */
function svcCutMgmtFresha(User $pro, string $title, string $recordKey): string
{
    $sourceId = (string) Str::uuid();
    $itemId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $pro->id, 'kind' => 'connection',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'service',
        'headline_cache' => $title, 'facets_cache' => '{}', 'eligible_cache' => '{}',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'coord' => 'fresha:'.$recordKey, 'record_key' => $recordKey,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $pro->id, 'platform' => 'fresha',
        'resource_kind' => 'booking', 'is_active' => true,
        'payload' => json_encode([
            'url' => 'https://www.fresha.com/a/test',
            'selection' => ['mode' => 'employee', 'services' => [['serviceId' => $recordKey, 'name' => $title]], 'hiddenServiceIds' => []],
            'raw' => ['services' => [['serviceId' => $recordKey, 'name' => $title]]],
        ]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $itemId;
}

it('shows a Fresha service by its content id and 404s an unknown uuid', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');

    actingAsUser($pro)->getJson("/api/services/{$itemId}")
        ->assertOk()
        ->assertJsonPath('service.source', 'fresha')
        ->assertJsonPath('service.id', $itemId);

    actingAsUser($pro)->getJson('/api/services/'.(string) Str::uuid())->assertNotFound();
});

it('an owner edit to a Fresha title lands as a manual override, not a facet write', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Vendor Name', 's:1');

    actingAsUser($pro)->patchJson("/api/services/{$itemId}", ['title' => 'Owner Name'])
        ->assertOk()
        ->assertJsonPath('service.title', 'Owner Name')
        ->assertJsonPath('service.is_manual', true);

    expect(DB::table('content.manual_overrides')->where('item_id', $itemId)
        ->where('facet', 'f_text')->where('column_name', 'headline')->count())->toBe(1);
});

it('rejects an edited price on a Fresha service and accepts an echo of the current one', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');

    actingAsUser($pro)->patchJson("/api/services/{$itemId}", ['price_cents' => 9900])
        ->assertStatus(422);
    // No offer row exists, so the current price is 0 — echoing it passes.
    actingAsUser($pro)->patchJson("/api/services/{$itemId}", ['price_cents' => 0, 'title' => 'Fade 2'])
        ->assertOk();
});

it('is_active=false on a Fresha service rides the blob hidden list, not a column', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');

    actingAsUser($pro)->patchJson("/api/services/{$itemId}", ['is_active' => false])->assertOk();

    $payload = json_decode((string) DB::table('site.platform_connections')
        ->where('user_id', $pro->id)->value('payload'), true);
    expect($payload['selection']['hiddenServiceIds'])->toBe(['s:1']);
});

it('deleting a Fresha service sets items.removed_at and drops it from the booking blob; restore brings it back', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');

    actingAsUser($pro)->deleteJson("/api/services/{$itemId}")->assertOk();

    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->not->toBeNull();
    $payload = json_decode((string) DB::table('site.platform_connections')
        ->where('user_id', $pro->id)->value('payload'), true);
    expect($payload['selection']['services'])->toBe([]);

    actingAsUser($pro)->postJson("/api/services/{$itemId}/restore")->assertOk();
    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->toBeNull();
});

it('never resurrects an owner-deleted Fresha service: removed_at survives a projection-style source_item touch', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');
    actingAsUser($pro)->deleteJson("/api/services/{$itemId}")->assertOk();

    // What a reappearing scrape does: clears source_items.removed_at. It must NOT touch items.removed_at.
    DB::table('content.source_items')->where('item_id', $itemId)->update(['removed_at' => null]);
    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->not->toBeNull();
});
```

- [ ] **Step 2: Run; confirm the failures are the legacy branches 404ing content-shaped expectations**, not fixture errors.

- [ ] **Step 3: Add `FreshaServiceProjector::setHidden()`**

In `app/Services/Platforms/FreshaServiceProjector.php`, after `refreshBlob()`:

```php
    /**
     * Toggle one service's hidden state on the stored blob. The hidden list
     * IS the record (D3a — content.* has no is_active); compose() prunes it
     * to live ids. The dashboard's is_active toggle maps here.
     */
    public function setHidden(User $user, string $serviceId, bool $hidden): void
    {
        $connection = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', Platform::Fresha->value)
            ->first();
        $payload = is_array($connection?->payload) ? $connection->payload : [];
        $selection = $payload['selection'] ?? null;
        if ($connection === null || ! is_array($selection)) {
            return;
        }

        $list = array_values(array_filter($selection['hiddenServiceIds'] ?? [], 'is_string'));
        $list = $hidden
            ? array_values(array_unique([...$list, $serviceId]))
            : array_values(array_filter($list, fn ($id) => $id !== $serviceId));

        $rawServices = is_array($payload['raw']['services'] ?? null) ? $payload['raw']['services'] : [];
        $composed = $this->compose($user, $rawServices, $list);
        $connection->payload = [...$payload, 'selection' => [
            ...$selection,
            'services' => $composed['services'],
            'hiddenServiceIds' => $composed['hiddenServiceIds'],
        ]];
        $connection->save();
    }
```

- [ ] **Step 4: Replace the four legacy branches**

In `UserServiceController`, replace `show()`'s legacy branch (`:309-321`) with:

```php
        // Fresha half: a connection-sourced content item (spec §3.2). Legacy
        // site.services uuids are gone — ruling 1: they 404 by being
        // unaddressable, and the wire manifest records the break.
        $fresha = app(FreshaServiceItems::class);
        $row = $fresha->findRow($pro->id, $service, $manual->sectionId($pro->site));
        if ($row === null) {
            abort(404, 'Service not found.');
        }

        $model = $fresha->toServiceModel($pro->id, $row, $fresha->hiddenServiceIds($pro->id));
        $this->authorizeForUser($pro, 'view', $model);

        return $this->success(['service' => new ServiceResource($model)]);
```

Replace `update()`'s legacy branch (`:331-366`) with a call to a new private method:

```php
        if ($row === null) {
            return $this->updateFresha($request, $pro, $service);
        }
```

and add the method (near `assignOwnerServiceCategory()`):

```php
    /**
     * update()'s Fresha branch (spec §3.2). An owner edit IS a
     * content.manual_overrides row per field (the C2-compliant lock);
     * is_active rides the blob's hiddenServiceIds; price is vendor-owned
     * (D3a) and an edit 422s explicitly rather than silently reverting on
     * the public booking wire. Categories go through the owner membership
     * lane exactly as updateCategory()'s branch does.
     */
    private function updateFresha(UpdateServiceRequest $request, User $pro, string $service): JsonResponse
    {
        $manual = app(ManualServiceItems::class);
        $fresha = app(FreshaServiceItems::class);
        $sectionId = $manual->sectionId($pro->site);

        $row = $fresha->findRow($pro->id, $service, $sectionId);
        if ($row === null) {
            abort(404, 'Service not found.');
        }

        $current = $fresha->toServiceModel($pro->id, $row, $fresha->hiddenServiceIds($pro->id));
        $this->authorizeForUser($pro, 'update', $current);

        $data = $request->validated();

        // D3a (owner ruling 2026-08-16): an edited PRICE has no content.*
        // home — offers are a set-union collection and FacetRegistry excludes
        // collections from manual_overrides by design. An echo of the current
        // price passes; a change is an explicit 422, never a silent revert.
        if (array_key_exists('price_cents', $data) && (int) $data['price_cents'] !== (int) $current->price_cents) {
            return $this->error('Fresha prices come from Fresha and cannot be edited here.', 422);
        }

        if (array_key_exists('category_id', $data) || array_key_exists('category_ids', $data)) {
            $collections = app(ServiceCollections::class);
            $categoryIds = $this->assignmentCategoryIds($data);
            foreach ($categoryIds as $categoryId) {
                if ($collections->find($pro->id, $categoryId) === null) {
                    abort(422, 'Category is invalid.');
                }
            }
            // Owner lane (null source): survives every projector run, and the
            // reads prefer it over the connection lane's memberships.
            $collections->assign($pro->id, (string) $row->id, $categoryIds[0] ?? null, null);
        }

        foreach ([
            'title' => ['f_text', 'headline', fn ($v) => (string) $v],
            'description' => ['f_text', 'body', fn ($v) => $v],
            'duration_minutes' => ['f_duration', 'seconds', fn ($v) => $v === null ? null : ((int) $v) * 60],
        ] as $field => [$facet, $column, $transform]) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $override = ManualOverride::query()
                ->where('item_id', (string) $row->id)
                ->where('facet', $facet)
                ->where('column_name', $column)
                ->first() ?? new ManualOverride;
            $override->item_id = (string) $row->id;
            $override->facet = $facet;
            $override->column_name = $column;
            $override->value = $transform($data[$field]);
            $override->save();
        }

        if (array_key_exists('is_active', $data)) {
            app(FreshaServiceProjector::class)->setHidden($pro, (string) $row->record_key, ! (bool) $data['is_active']);
        }

        app(FreshaServiceProjector::class)->refreshBlob($pro);
        $this->invalidateAfterResync($pro);

        $freshRow = $fresha->findRow($pro->id, (string) $row->id, $sectionId);

        return $this->success(['service' => new ServiceResource(
            $fresha->toServiceModel($pro->id, $freshRow ?? $row, $fresha->hiddenServiceIds($pro->id)),
        )]);
    }
```

Replace `destroy()`'s legacy branch (`:772-799`) with:

```php
        if ($row === null) {
            // Fresha half: owner delete = items.removed_at (one-way — the
            // projection path never touches it, so a reappearing scrape
            // cannot resurrect it; spec §3.3). NEVER source_items.removed_at.
            $fresha = app(FreshaServiceItems::class);
            $freshaRow = $fresha->findRow($pro->id, $service, $manual->sectionId($pro->site));
            if ($freshaRow === null) {
                abort(404, 'Service not found.');
            }

            $this->authorizeForUser($pro, 'delete', $fresha->toServiceModel($pro->id, $freshaRow));

            $site = $this->currentSite($pro);
            app(ManualServiceWriter::class)->markRemoved((string) $freshaRow->id);
            app(FreshaServiceProjector::class)->refreshBlob($pro);
            app(ManualServiceWriter::class)->invalidate([(string) $site->id]);
            app(UserCacheService::class)->invalidateServices($pro->id);
            $this->reevaluateVisibility($pro->id, (string) $site->id);

            return $this->success(['deleted' => true]);
        }
```

Replace `restore()`'s legacy branch (`:1167-1209`) with:

```php
        if ($row === null) {
            $fresha = app(FreshaServiceItems::class);
            $freshaRow = $fresha->findRow($pro->id, $service, $sectionId, includeRemoved: true, liveOnly: false);
            if ($freshaRow === null) {
                abort(404, 'Service not found.');
            }

            $model = $fresha->toServiceModel($pro->id, $freshaRow);
            $this->authorizeForUser($pro, 'update', $model);

            if ($freshaRow->removed_at === null) {
                return $this->success(['restored' => true, 'service' => new ServiceResource($model)]);
            }

            $site = $this->currentSite($pro);
            $writer = app(ManualServiceWriter::class);
            $writer->clearRemoved((string) $freshaRow->id);
            app(FreshaServiceProjector::class)->refreshBlob($pro);
            $writer->invalidate([(string) $site->id]);
            app(UserCacheService::class)->invalidateServices($pro->id);
            $this->reevaluateVisibility($pro->id, (string) $site->id);

            $freshRow = $fresha->findRow($pro->id, (string) $freshaRow->id, $sectionId, includeRemoved: true, liveOnly: false);

            return $this->success(['restored' => true, 'service' => new ServiceResource(
                $fresha->toServiceModel($pro->id, $freshRow ?? $freshaRow, $fresha->hiddenServiceIds($pro->id)),
            )]);
        }
```

Add the imports the new code needs (`App\Services\Content\FreshaServiceItems` — `ManualOverride`, `FreshaServiceProjector`, `ServiceCollections` are already imported).

- [ ] **Step 5: Run**

```bash
./vendor/bin/pest tests/Feature/Services/ServicesCutoverFreshaManagementTest.php tests/Feature/Api/User/ tests/Feature/Content/ServiceTwoSurfaceTest.php
```

Pre-existing user-service tests that seeded `site.services` Fresha rows and asserted the legacy branches will fail — update each to seed the content-side fixture instead (the `svcCutMgmtFresha` shape) and address by content id. Do NOT delete a case without a content-side replacement covering the same behaviour; list every renamed/re-seeded case in the commit body.

- [ ] **Step 6: `composer test:pg`; commit**

```bash
git add -A && git commit -m "feat(services-cutover): user service verbs resolve Fresha via content.* — legacy site.services branches retired"
```

---

### Task 4: `UserServiceController` — updateCategory + resync/resyncBulk shed their legacy halves

**Files:**
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php` (`updateCategory:618-702`, `resync:509-531`, `resyncBulk:589-606`, delete `freshaContentQuery/freshaContentRow/freshaServiceModel:1283-1344`)
- Test: extend `tests/Feature/Services/ServicesCutoverFreshaManagementTest.php`

**Interfaces:**
- Consumes: `FreshaServiceItems::findRow/toServiceModel/hiddenServiceIds` (Task 2), `ServiceCollections::assign(userId, itemId, ?collectionId, null)` (existing).

- [ ] **Step 1: Failing tests** (append to the Task 3 file):

```php
it('files a Fresha service under an owner category via the owner membership lane', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');
    $collectionId = app(\App\Services\Content\ServiceCollections::class)->create($pro->id, 'Cuts');

    actingAsUser($pro)->patchJson("/api/services/{$itemId}/category", ['category_id' => $collectionId])
        ->assertOk()
        ->assertJsonPath('service.categories.0.id', $collectionId);

    expect(DB::table('content.collection_items')->where('item_id', $itemId)
        ->whereNull('source_id')->where('collection_id', $collectionId)->count())->toBe(1);
});

it('resync on a Fresha content item deletes its overrides and no legacy fallback remains', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Vendor Name', 's:1');
    actingAsUser($pro)->patchJson("/api/services/{$itemId}", ['title' => 'Owner Name'])->assertOk();

    actingAsUser($pro)->postJson("/api/services/{$itemId}/resync")
        ->assertOk()
        ->assertJsonPath('service.is_manual', false);

    expect(DB::table('content.manual_overrides')->where('item_id', $itemId)->count())->toBe(0);
    // An id that resolves in neither store is a plain 404 — no legacy branch left to fall into.
    actingAsUser($pro)->postJson('/api/services/'.(string) Str::uuid().'/resync')->assertNotFound();
});
```

- [ ] **Step 2: Run; confirm the category case fails** (legacy branch resolves nothing → but current code 404s only after site.services miss — the assertion on `collection_items` is the teeth).

- [ ] **Step 3: Implement**

`updateCategory()` (`:618-702`): delete the entire legacy branch (the `$legacy = Service::query()...` resolve, the advisory-lock transaction with `categories()->sync()` and the `sort_order` append). The method becomes:

```php
    public function updateCategory(UpdateServiceCategoryAssignmentRequest $request, string $service): JsonResponse
    {
        $pro = $this->currentUser($request);
        $manual = app(ManualServiceItems::class);

        $row = $manual->find($pro->id, $service, $manual->sectionId($pro->site));
        if ($row !== null) {
            return $this->assignOwnerServiceCategory($request, $pro, $manual, $row);
        }

        // Fresha half (spec §3.2): same collections space, same owner
        // membership lane — a Fresha service may be filed under any of the
        // owner's service-category collections now that both halves share one
        // id space. The projector can never delete this row (its
        // replace-by-source delete is scoped to the connection source), and
        // the reads prefer it, so the choice survives every sync.
        $fresha = app(FreshaServiceItems::class);
        $freshaRow = $fresha->findRow($pro->id, $service, $manual->sectionId($pro->site));
        if ($freshaRow === null) {
            abort(404, 'Service not found.');
        }

        $this->authorizeForUser($pro, 'updateCategory', $fresha->toServiceModel($pro->id, $freshaRow));

        $collections = app(ServiceCollections::class);
        $categoryIds = $this->assignmentCategoryIds($request->validated());
        foreach ($categoryIds as $categoryId) {
            if ($collections->find($pro->id, $categoryId) === null) {
                abort(422, 'Category is invalid.');
            }
        }

        $site = $this->currentSite($pro);
        $collections->assign($pro->id, (string) $freshaRow->id, $categoryIds[0] ?? null, null);

        app(ManualServiceWriter::class)->invalidate([(string) $site->id]);
        app(UserCacheService::class)->invalidateServices($pro->id);

        $freshRow = $fresha->findRow($pro->id, (string) $freshaRow->id, $manual->sectionId($site));

        return $this->success(['service' => new ServiceResource(
            $fresha->toServiceModel($pro->id, $freshRow ?? $freshaRow, $fresha->hiddenServiceIds($pro->id)),
        )]);
    }
```

`resync()` (`:509-531`): delete the legacy fall-through (`Service::query()...find`, the `source !== 'fresha'` check, the `revert()` call and `refreshBlob`) — after the content-item branch, the method ends with `abort(404, 'Service not found.');`. Repoint its two content-half helpers at Task 2's methods: `$this->freshaContentRow($pro->id, $service, liveOnly: false)` becomes `app(FreshaServiceItems::class)->findRow($pro->id, $service, null, liveOnly: false)`, `$this->freshaServiceModel($pro->id, $manual, $row)` becomes `app(FreshaServiceItems::class)->toServiceModel($pro->id, $row)`.

`resyncBulk()` (`:589-606`): delete the entire legacy half (the `Service::query()->where('is_manual', true)` loop, the `$projector->revert()` calls and the trailing `refreshBlob`). Replace `$this->freshaContentQuery($pro->id, ...)` with a small private wrapper is NOT needed — inline `app(FreshaServiceItems::class)` calls: the candidates query moves onto `FreshaServiceItems` as-is, so add one more method there:

```php
    /** Item ids of this user's Fresha service items carrying at least one override. @return list<string> */
    public function overriddenItemIds(string $userId, array $onlyIds = []): array
    {
        return $this->managementQuery($userId, null, liveOnly: false)
            ->when($onlyIds !== [], fn ($q) => $q->whereIn('i.id', $onlyIds))
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('content.manual_overrides as mo')
                ->whereColumn('mo.item_id', 'i.id'))
            ->distinct()->pluck('i.id')->map(fn ($id) => (string) $id)->all();
    }

    /** The live (source_item not removed) subset of the given item ids. @return list<string> */
    public function liveItemIds(string $userId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->managementQuery($userId, null)
            ->whereIn('i.id', $ids)
            ->distinct()->pluck('i.id')->map(fn ($id) => (string) $id)->all();
    }
```

and `resyncBulk()`'s body becomes:

```php
        $fresha = app(FreshaServiceItems::class);
        $candidates = $fresha->overriddenItemIds($pro->id, $ids);
        $live = array_flip($fresha->liveItemIds($pro->id, $candidates));

        $revertable = array_values(array_filter($candidates, fn ($id) => isset($live[$id])));
        $skipped = count($candidates) - count($revertable);
        $resynced = 0;

        if ($revertable !== []) {
            ManualOverride::query()->whereIn('item_id', $revertable)->delete();
            $resynced = count($revertable);
            app(FreshaServiceProjector::class)->refreshBlob($pro);
            $this->invalidateAfterResync($pro);
        }

        return $this->success(['resynced' => $resynced, 'skipped' => $skipped]);
```

(Note the added `refreshBlob()` — the single-item `resync()` gets the same line after its override delete: the blob folds overrides now (Task 2), so reverting must recompose it.)

Delete the three private helpers `freshaContentQuery()`, `freshaContentRow()`, `freshaServiceModel()` (`:1283-1344`) once nothing references them.

- [ ] **Step 4: Run the file + `tests/Feature/Api/User/`; fix pre-existing cases as in Task 3 Step 5.**
- [ ] **Step 5: `composer test:pg`; commit**

```bash
git add -A && git commit -m "feat(services-cutover): updateCategory and resync verbs single-store — legacy branches and revert() call sites removed"
```

---

### Task 5: Ordering — both halves pin `section_items.sort_key`; `LegacyServiceSortOrder` calls removed

Spec §3.4. `ManualPoolWriter::pin()` is item-kind-agnostic; Fresha items now get pin rows in the same services section, so the merged list sorts ONE column. The public services read is structurally blind to them (`cs.kind='manual'` join) and the booking surface orders by `first_seen_at` — but the documents/pool lane's `services` rule is `kind_is` only (`PoolRegistry.php:126`), so a pinned Fresha item WOULD count as curated there if that lane ever goes public. That lane is unread today (slice 6's finding); the guard test below pins the public read, and the code comment names the landmine for the day the documents lane ships.

**Files:**
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php` (`reorder:830-907`, `reorderLayout:940-1156`)
- Modify: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceManagementController.php` (`reorder:398-446`, `reorderLayout:466-621`, `liveFreshaIds:856-866`)
- Test: `tests/Feature/Services/ServicesCutoverOrderingTest.php` (new)

**Interfaces:**
- Consumes: `FreshaServiceItems::managementRows()` (Task 2), `ManualPoolWriter::pin(Site $site, string $itemId, float $sortKey)` (existing).

- [ ] **Step 1: Failing tests**

```php
<?php

use App\Models\Core\User\User;
use App\Services\Content\ManualServiceItems;
use App\Services\Content\ManualServiceWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

function svcCutOrdUser(): User
{
    return createTenant('svccutord-'.Str::lower(Str::random(8)));
}

// Same fixture shape as svcCutMgmtFresha, local to this file.
function svcCutOrdFresha(User $pro, string $title, string $recordKey): string
{
    /* identical body to svcCutMgmtFresha() — copy it here under this name */
}

function svcCutOrdManual(User $pro, string $title): string
{
    $writer = app(ManualServiceWriter::class);

    return $writer->write($pro->id, 'manual:'.(string) Str::uuid(), $writer->projectionFor((object) [
        'title' => $title, 'description' => null, 'price_cents' => 1000,
        'currency_code' => 'AUD', 'duration_minutes' => null,
    ]));
}

it('reorder interleaves manual and Fresha ids onto one section_items scale and writes no site.services row', function () {
    $pro = svcCutOrdUser();
    $site = $pro->site;
    $manualId = svcCutOrdManual($pro, 'Manual One');
    app(ManualServiceWriter::class)->pin($site, $manualId, 1.0);
    $freshaId = svcCutOrdFresha($pro, 'Fresha One', 's:1');

    actingAsUser($pro)->postJson('/api/services/reorder', ['ids' => [$freshaId, $manualId]])->assertOk();

    $sectionId = app(ManualServiceItems::class)->sectionId($site->fresh());
    $keys = DB::table('site.section_items')->where('section_id', $sectionId)
        ->whereIn('item_id', [$freshaId, $manualId])
        ->pluck('sort_key', 'item_id');
    expect((float) $keys[$freshaId])->toBe(0.0)
        ->and((float) $keys[$manualId])->toBe(1.0);
});

it('a pinned Fresha item still never appears in the public services section', function () {
    $pro = svcCutOrdUser();
    $freshaId = svcCutOrdFresha($pro, 'Fresha One', 's:1');
    $manualId = svcCutOrdManual($pro, 'Manual One');

    actingAsUser($pro)->postJson('/api/services/reorder', ['ids' => [$freshaId, $manualId]])->assertOk();

    $public = app(ManualServiceItems::class)->publicList($pro->id, $pro->site->fresh());
    expect(collect($public)->pluck('id')->all())->toBe([$manualId]);
});

it('reorder-layout accepts one category space and orders Fresha items without touching legacy tables', function () {
    $pro = svcCutOrdUser();
    $collectionId = app(\App\Services\Content\ServiceCollections::class)->create($pro->id, 'Cuts');
    $freshaId = svcCutOrdFresha($pro, 'Fresha One', 's:1');
    $manualId = svcCutOrdManual($pro, 'Manual One');

    actingAsUser($pro)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => $collectionId, 'service_ids' => [$freshaId]],
            ['id' => null, 'service_ids' => [$manualId]],
        ],
    ])->assertOk();

    $sectionId = app(ManualServiceItems::class)->sectionId($pro->site->fresh());
    expect((float) DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('item_id', $freshaId)->value('sort_key'))->toBe(0.0);
});
```

- [ ] **Step 2: Run; confirm failures** (Fresha id 422s as invalid — the legacy id-set lookup doesn't know content ids).

- [ ] **Step 3: Implement `reorder()` (user)** — replace `:843-905` with:

```php
        $manual = app(ManualServiceItems::class);
        $fresha = app(FreshaServiceItems::class);
        $writer = app(ManualServiceWriter::class);
        $ids = $request->input('ids', []);

        $sectionId = $manual->sectionId($site);
        $manualRowsById = $manual->rows($pro->id, $sectionId, includeRemoved: false)
            ->keyBy(fn ($r) => (string) $r->id);
        $freshaRowsById = $fresha->managementRows($pro->id, $sectionId)
            ->keyBy(fn ($r) => (string) $r->id);

        foreach ($ids as $id) {
            if (! $manualRowsById->has($id) && ! $freshaRowsById->has($id)) {
                abort(422, 'One or more items are invalid.');
            }
        }

        // The submitted ids first, then every other live id in its current
        // relative order — one authority, one numbering scale (spec §3.4).
        $remainder = array_values(array_diff(
            [...$manualRowsById->keys()->all(), ...$freshaRowsById->keys()->all()],
            $ids,
        ));
        $fullOrder = array_merge($ids, $remainder);

        try {
            DB::connection('pgsql')->transaction(function () use ($pro, $site, $writer, $manualRowsById, $freshaRowsById, $fullOrder) {
                AdvisoryLock::acquire("services:{$pro->id}", AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS);

                // ONE loop, both halves: sort_key on the services section is
                // the shared scale. Excluded (hidden) MANUAL items carry no
                // sort_key by design; Fresha items are always pinnable —
                // their hidden state rides the blob, not section state.
                //
                // Landmine, named on purpose: the pool DSL reads 'pinned' as
                // curated-in and the services pool rule is kind_is only
                // (PoolRegistry). The PUBLIC read is source-kind-filtered
                // (ManualServiceItems) so nothing leaks today — but if the
                // site.site_documents lane ever becomes publicly readable,
                // the services candidates rule must gain a source-kind guard
                // FIRST (slice 6 spec §4.3's standing rule).
                foreach ($fullOrder as $rank => $id) {
                    $manualRow = $manualRowsById->get($id);
                    if ($manualRow !== null && ($manualRow->state ?? null) !== 'excluded') {
                        $writer->pin($site, $id, (float) $rank);
                    } elseif ($freshaRowsById->has($id)) {
                        $writer->pin($site, $id, (float) $rank);
                    }
                }
            });
        } catch (AdvisoryLockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        $writer->invalidate([(string) $site->id]);
        $site->touch();
        app(UserCacheService::class)->invalidateServices($pro->id);

        return $this->success(['ok' => true]);
```

(The `app(LegacyServiceSortOrder::class)->renumber(...)` call and the `Service::query()` id-set read are gone; the `use App\Services\Site\LegacyServiceSortOrder;` import goes when the last reference does, Task 9.)

- [ ] **Step 4: Implement `reorderLayout()` (user)** — the legacy category space dissolves (ONE space: collections). Replace the transaction body (`:958-1140`) with:

```php
                $collectionIds = $collections->list($pro->id)->map(fn ($row) => (string) $row->id)->all();
                $collectionSet = array_flip($collectionIds);

                $sectionId = $manual->sectionId($site);
                $manualRowsById = $manual->rows($pro->id, $sectionId, includeRemoved: false)
                    ->keyBy(fn ($r) => (string) $r->id);
                $freshaRowsById = app(FreshaServiceItems::class)->managementRows($pro->id, $sectionId)
                    ->keyBy(fn ($r) => (string) $r->id);

                $orderedCollectionIds = [];
                $seenPerBlock = [];
                $idsSeen = [];

                foreach ($payload['categories'] as $bi => $catBlock) {
                    $catId = $catBlock['id'] ?? null;
                    if ($catId !== null) {
                        if (! isset($collectionSet[$catId])) {
                            abort(422, 'One or more category IDs are invalid.');
                        }
                        $orderedCollectionIds[] = $catId;
                    }

                    foreach ($catBlock['service_ids'] as $sid) {
                        if (! $manualRowsById->has($sid) && ! $freshaRowsById->has($sid)) {
                            abort(422, 'One or more service IDs are invalid.');
                        }
                        if (isset($seenPerBlock[$bi][$sid])) {
                            abort(422, 'Duplicate service IDs detected within a category block.');
                        }
                        $seenPerBlock[$bi][$sid] = true;
                        $idsSeen[$sid] = true;
                    }
                }

                // Every live service — either half — must appear somewhere.
                if (count($idsSeen) !== $manualRowsById->count() + $freshaRowsById->count()) {
                    abort(422, 'Layout payload must include all service IDs for this professional.');
                }
                $this->assertCoversEveryCategory($orderedCollectionIds, $collectionIds);

                if ($orderedCollectionIds !== []) {
                    $collections->reposition($pro->id, array_values(array_unique($orderedCollectionIds)));
                }

                // NO MEMBERSHIPS ARE WRITTEN HERE, only ORDER — unchanged
                // stance (slice 7 Task 12): PATCH /services/{id}/category is
                // the membership writer.
                $orderedAllServiceIds = [];
                foreach ($payload['categories'] as $catBlock) {
                    foreach ($catBlock['service_ids'] as $serviceId) {
                        if (! in_array($serviceId, $orderedAllServiceIds, true)) {
                            $orderedAllServiceIds[] = $serviceId;
                        }
                    }
                }

                foreach ($orderedAllServiceIds as $rank => $sid) {
                    $manualRow = $manualRowsById->get($sid);
                    if ($manualRow !== null && ($manualRow->state ?? null) !== 'excluded') {
                        $writer->pin($site, $sid, (float) $rank);
                    } elseif ($freshaRowsById->has($sid)) {
                        $writer->pin($site, $sid, (float) $rank);
                    }
                }
```

Delete from the method: the `ServiceCategory::query()` reads, `$activeFreshaServiceIds` (`Service::query()`), the cross-space 422s ("cannot be filed under..."), `$membershipsByService`/`$uncategorisedIds` machinery, the `ServiceCategory ... ->update(['sort_order' => ...])` loop, and the `LegacyServiceSortOrder::renumber()` call. Keep the method docblock but rewrite it: one category id space, one service id space, order-only, blob-independent.

- [ ] **Step 5: Implement the staff twins** — `StaffServiceManagementController::reorder()` and `reorderLayout()` take the identical change (they are deliberate mirrors — keep them mirrors): same one-loop pin over `$manualRowsById` + `$freshaRowsById` from `FreshaServiceItems::managementRows($professional->id, $sectionId)`, same single-space category validation, `pinManualOrder()` generalised to:

```php
    /**
     * Both halves pinned from one traversal — sort_key on the services
     * section is the shared ordering scale (spec §3.4). Hidden MANUAL items
     * (excluded) stay unpositioned; Fresha hidden state rides the blob and
     * never blocks a pin.
     *
     * @param  Collection<string, \stdClass>  $manualRowsById
     * @param  Collection<string, \stdClass>  $freshaRowsById
     * @param  list<string>  $order
     */
    private function pinServiceOrder(Site $site, ManualServiceWriter $writer, Collection $manualRowsById, Collection $freshaRowsById, array $order): void
    {
        foreach ($order as $rank => $id) {
            $manualRow = $manualRowsById->get($id);
            if ($manualRow !== null && ($manualRow->state ?? null) !== 'excluded') {
                $writer->pin($site, $id, (float) $rank);
            } elseif ($freshaRowsById->has($id)) {
                $writer->pin($site, $id, (float) $rank);
            }
        }
    }
```

Delete `liveFreshaIds()` (`:856-866`) and both `LegacyServiceSortOrder` calls.

- [ ] **Step 6: Run**

```bash
./vendor/bin/pest tests/Feature/Services/ tests/Feature/Api/ --parallel
```

`ServiceCategoryAssignmentRetirementTest` seeds legacy Fresha rows and asserts through the OLD id space — its reorder-layout cases now 422 (legacy ids unknown). Re-seed them content-side; the assertions ("writes no legacy assignment row") keep their meaning until Task 12 drops the table, then Task 12 retires the file.

- [ ] **Step 7: `composer test:pg`; commit**

```bash
git add -A && git commit -m "feat(services-cutover): one ordering scale — both halves pin section_items.sort_key, LegacyServiceSortOrder calls removed"
```

---

### Task 6: The list reads — index(), staff index, UserCacheService, ContentFreshness

**Files:**
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php` (`index():76-195`)
- Modify: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceManagementController.php` (`index():69-134`, `mergedServices():782-806`, `legacyCategories():831-842` deleted)
- Modify: `app/Services/Cache/UserCacheService.php` (`getActiveServices():213-246`, `getDashboardServices():264-295`)
- Modify: `app/Services/Analytics/ContentFreshness.php` (`:89-97`)
- Test: extend `tests/Feature/Services/ServicesCutoverFreshaManagementTest.php`

- [ ] **Step 1: Failing tests**

```php
it('the dashboard index lists Fresha services from content.* with content ids', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');

    actingAsUser($pro)->getJson('/api/services')
        ->assertOk()
        ->assertJsonPath('services.0.id', $itemId)
        ->assertJsonPath('services.0.source', 'fresha');
});

it('only_archived surfaces an owner-deleted Fresha service', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');
    actingAsUser($pro)->deleteJson("/api/services/{$itemId}")->assertOk();

    actingAsUser($pro)->getJson('/api/services?only_archived=1')
        ->assertOk()
        ->assertJsonPath('services.0.id', $itemId);
});

it('a hidden Fresha service is excluded from the active list but present on the dashboard list as inactive', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');
    actingAsUser($pro)->patchJson("/api/services/{$itemId}", ['is_active' => false])->assertOk();

    $active = app(\App\Services\Cache\UserCacheService::class)->getActiveServices($pro->id);
    expect(collect($active)->pluck('id')->all())->not->toContain($itemId);

    actingAsUser($pro)->getJson('/api/services')
        ->assertJsonPath('services.0.is_active', false);
});
```

- [ ] **Step 2: Run; confirm failures** (legacy merge returns nothing content-side for Fresha).

- [ ] **Step 3: Implement the merge swap** — the same replacement in all four places. In `UserServiceController::index()` replace `:91-99` with:

```php
        $fresha = app(FreshaServiceItems::class);
        $freshaRows = $fresha->managementRows($pro->id, $sectionId, includeRemoved: $includeArchived || $onlyArchived);
        if ($onlyArchived) {
            $freshaRows = $freshaRows->filter(fn ($row) => $row->removed_at !== null)->values();
        }
        $freshaServices = $fresha->toServiceModels($pro->id, $freshaRows, $fresha->hiddenServiceIds($pro->id));
```

(the `->sortBy('sort_order')` merge below is unchanged — both halves now carry `sort_order` hydrated from `sort_key`, unpinned items tail via the `PHP_INT_MAX` sentinel). In the grouped branch, delete the `ServiceCategory::query()` concat (`:149-159`) — `$categories` is `$collectionRows` alone.

`StaffServiceManagementController::mergedServices()`: same swap for `:791-799`; `index()` `:99-103` drops `->concat($this->legacyCategories(...))`; delete `legacyCategories()`.

`UserCacheService::getActiveServices()` (`:230-238`): replace the `Service::query()` block with:

```php
                $fresha = app(\App\Services\Content\FreshaServiceItems::class);
                $freshaServices = $fresha->toServiceModels(
                    $userId,
                    $fresha->managementRows($userId, $manual->sectionId($site)),
                    $fresha->hiddenServiceIds($userId),
                )->filter(fn (Service $s) => $s->is_active)->values();
```

`getDashboardServices()` (`:279-286`): same, without the `is_active` filter.

`ContentFreshness` (`:89-97`): replace the `Service::query()` read with:

```php
        // Any live service item (either source kind) freshens the Book page —
        // the same signal the legacy row's created_at carried.
        $newestService = DB::connection('pgsql')->table('content.items')
            ->where('user_id', $site->user_id)
            ->where('kind', 'service')
            ->whereNull('removed_at')
            ->max('created_at');
```

(add the `DB` facade import; the `Service` model import goes with the last reference).

- [ ] **Step 4: Run** the test file plus `tests/Feature/Api/`, `tests/Feature/Cache/`, `tests/Feature/Analytics/`; fix pre-existing legacy-seeded cases as before.
- [ ] **Step 5: `composer test:pg`; commit**

```bash
git add -A && git commit -m "feat(services-cutover): every merged list read serves the Fresha half from content.*"
```

---

### Task 7: `StaffServiceManagementController` — show/update/destroy/forceDestroy/restore off the legacy table

The staff twin of Task 3. Same resolution, same override/hidden/removed_at semantics, staff authorization untouched.

**Files:**
- Modify: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceManagementController.php` (`show:244-252`, `update:265-267` + delete `updateLegacy:731-770`, `destroy:361-372`, `forceDestroy:648-657`, `restore:689-700`, delete `legacyService:845-853`)
- Test: extend `tests/Feature/Services/ServicesCutoverFreshaManagementTest.php` (staff cases, `svcCutMgmt` helpers reused)

- [ ] **Step 1: Failing tests** (mirror Task 3's cases through the staff routes — `actingAsStaff(svcCutMgmtStaffAdmin())` following `ServiceCategoryAssignmentRetirementTest`'s staff idiom; add a `svcCutMgmtStaffAdmin(): PartnaStaff` helper copying `svcAsgnRetireAdmin()`'s shape):

```php
it('staff show resolves a Fresha service by content id', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');

    actingAsStaff(svcCutMgmtStaffAdmin())->getJson("/api/staff/professionals/{$pro->id}/services/{$itemId}")
        ->assertOk()->assertJsonPath('service.source', 'fresha');
});

it('a staff edit to a Fresha title lands as an override; price edits 422', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Vendor Name', 's:1');
    $staff = svcCutMgmtStaffAdmin();

    actingAsStaff($staff)->patchJson("/api/staff/professionals/{$pro->id}/services/{$itemId}", ['title' => 'Staff Name'])
        ->assertOk()->assertJsonPath('service.is_manual', true);
    actingAsStaff($staff)->patchJson("/api/staff/professionals/{$pro->id}/services/{$itemId}", ['price_cents' => 500])
        ->assertStatus(422);
});

it('staff forceDestroy on a Fresha content item hard-deletes the item row', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');

    actingAsStaff(svcCutMgmtStaffAdmin())->deleteJson("/api/staff/professionals/{$pro->id}/services/{$itemId}/hard")
        ->assertOk();
    expect(DB::table('content.items')->where('id', $itemId)->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run; confirm failures.**
- [ ] **Step 3: Implement.** Each legacy branch swaps `$this->legacyService(...)` for `app(FreshaServiceItems::class)->findRow($professional->id, $service, $manual->sectionId($professional->site), ...)`:
  - `show()`: `includeRemoved: true, liveOnly: false`, then the same `include_archived` gate on `$row->removed_at`; respond via `toServiceModel(..., hiddenServiceIds())`.
  - `update()`: replace `return $this->updateLegacy(...)` with `return $this->updateFresha($request, $professional, $service);` — add a private `updateFresha()` mirroring Task 3's user version exactly (staff request class, `$professional` in place of `$pro`, `$this->invalidate($professional, $site, $writer)` as the invalidation call, membership via `$collections->assign($professional->id, ..., null)`). Delete `updateLegacy()`.
  - `destroy()`: Fresha branch = `markRemoved((string) $row->id)` + `app(FreshaServiceProjector::class)->refreshBlob($professional)` + `$this->invalidate(...)`; 404 when `findRow` misses.
  - `forceDestroy()`: the content-item hard-delete block already exists (`:662-669`) — the legacy branch simply routes into it: resolve via `findRow(..., includeRemoved: true, liveOnly: false)`; on miss 404. (One shared path — delete the `$legacy->forceDelete()` branch.)
  - `restore()`: mirror Task 3's restore branch (`clearRemoved` + `refreshBlob` + `invalidate`).
  - Delete `legacyService()`.
- [ ] **Step 4: Run** the file + `tests/Feature/Api/Staff/` (fix pre-existing legacy-seeded staff cases). Then `composer test:pg`.
- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(services-cutover): staff service verbs resolve Fresha via content.* — updateLegacy and legacyService removed"
```

---

### Task 8: `StaffServiceCategoryManagementController` — the legacy by-id branches go

Since 3b, Fresha categories are `content.collections` rows; the legacy `site.service_categories` rows (18 on dev) serve only these by-id fall-backs. Post-cutover they are unreachable data — the branches delete outright (spec §3.2; ids break by ruling 1).

**Files:**
- Modify: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceCategoryManagementController.php` (`show:156-164`, `update:175-176` + `updateLegacy:413-426`, `destroy:213-218` + `destroyLegacy:429-447`, `reorder:262-274,297-305`, `forceDestroy:326-338`, `restore:374-394`, `legacyCategory:477-485`, `legacyCategoryIds:488-496`)
- Test: extend `tests/Feature/Services/ServicesCutoverFreshaManagementTest.php`

- [ ] **Step 1: Failing test**

```php
it('a legacy service-category uuid 404s on every staff by-id verb', function () {
    $pro = svcCutMgmtUser();
    $staff = svcCutMgmtStaffAdmin();
    $legacyId = (string) Str::uuid();   // no collection row — the dead id space

    actingAsStaff($staff)->getJson("/api/staff/professionals/{$pro->id}/service-categories/{$legacyId}")->assertNotFound();
    actingAsStaff($staff)->patchJson("/api/staff/professionals/{$pro->id}/service-categories/{$legacyId}", ['title' => 'X'])->assertNotFound();
    actingAsStaff($staff)->deleteJson("/api/staff/professionals/{$pro->id}/service-categories/{$legacyId}")->assertNotFound();
    actingAsStaff($staff)->deleteJson("/api/staff/professionals/{$pro->id}/service-categories/{$legacyId}/hard")->assertNotFound();
    actingAsStaff($staff)->postJson("/api/staff/professionals/{$pro->id}/service-categories/{$legacyId}/restore")->assertNotFound();
    actingAsStaff($staff)->postJson("/api/staff/professionals/{$pro->id}/service-categories/reorder", ['ids' => [$legacyId]])->assertStatus(422);
});
```

(These already pass for a random uuid — the teeth are Step 3's deletions plus the existing collection-branch tests staying green: the change is pure branch removal, so the RED here comes from `StaffServiceCategoryCutoverTest`-style pre-existing cases that seed legacy rows and exercise the branches. Invert those cases: a legacy-seeded id now 404s.)

- [ ] **Step 2: Run the directory** to inventory which pre-existing cases exercise the legacy branches:

```bash
./vendor/bin/pest tests/Feature/Api/Staff/ --filter=Categor
```

- [ ] **Step 3: Implement.** In each of `show`/`update`/`destroy`/`forceDestroy`/`restore`, replace the `if ($row === null) { ...legacy... }` block with `abort(404);` (or for `update`, `if ($collections->find(...) === null) { abort(404); }`). In `reorder()`: delete `$legacyIdSet`/`$orderedLegacyIds` and the `ReorderService::renumberLocked()` branch — an id not in `$collectionIdSet` 422s. Delete `updateLegacy()`, `destroyLegacy()`, `legacyCategory()`, `legacyCategoryIds()`, and the now-unused `ReorderService`/`ServiceCategory` imports. The 8 routes and their 3/5 middleware-group split are untouched (spec §3.2).
- [ ] **Step 4: Run + invert/re-seed the pre-existing legacy-branch cases; list each in the commit body. `composer test:pg`.**
- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(services-cutover): staff category by-id verbs single-store — legacy site.service_categories branches deleted"
```

---

### Task 9: `FreshaController::forget()`, `revert()` retirement, backfiller/sorter deletion

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/FreshaController.php` (`forget():660-694`)
- Modify: `app/Services/Platforms/FreshaServiceProjector.php` (delete `revert():250-263`, `rawAttributes():293-309` if unreferenced)
- Delete: `app/Services/Site/LegacyServiceSortOrder.php`, `app/Services/Migration/ServiceBackfiller.php`, `app/Console/Commands/BackfillOwnerServices.php` (+ their test files)
- Test: extend `tests/Feature/Services/ServicesCutoverFreshaManagementTest.php`

- [ ] **Step 1: Failing test**

```php
it('disconnecting Fresha hides synced items via source_items.removed_at and spares overridden ones', function () {
    $pro = svcCutMgmtUser();
    $syncedId = svcCutMgmtFresha($pro, 'Synced', 's:1');
    $editedId = svcCutMgmtFresha($pro, 'Edited', 's:2');   // NOTE: creates a 2nd connection row — delete it, keep its content rows
    DB::table('site.platform_connections')->where('user_id', $pro->id)->orderByDesc('created_at')->limit(1)->delete();
    DB::table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $editedId,
        'facet' => 'f_text', 'column_name' => 'headline', 'value' => json_encode('Mine'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAsUser($pro)->deleteJson('/api/platforms/fresha')->assertOk();

    expect(DB::table('content.source_items')->where('item_id', $syncedId)->value('removed_at'))->not->toBeNull()
        ->and(DB::table('content.source_items')->where('item_id', $editedId)->value('removed_at'))->toBeNull()
        ->and(DB::table('content.items')->whereIn('id', [$syncedId, $editedId])->whereNotNull('removed_at')->count())->toBe(0);
});
```

- [ ] **Step 2: Run; confirm it fails** (current forget() touches only `site.services`).
- [ ] **Step 3: Implement `forget()`** — replace the `Service::query()` loop with:

```php
        return $this->withCrossPlatformLock(CacheKeyGenerator::bookingXorLock((string) $user->id), function () use ($user) {
            $this->forgetConnection($user);

            // Content twin of the legacy 'sync' soft-delete (spec §3.3):
            // source_items.removed_at hides the synced items from every
            // management read, and ProjectionWriter CLEARS it when a
            // reconnect's next run re-lands them — restore-on-return, native.
            // An item carrying a manual override is the owner's detached
            // content (the old is_manual rule) and survives live; an
            // owner-DELETED item already has items.removed_at, which nothing
            // here touches.
            DB::connection('pgsql')->table('content.source_items')
                ->whereIn('source_id', fn ($q) => $q->select('id')->from('content.sources')
                    ->where('user_id', $user->id)->where('kind', 'connection'))
                ->whereIn('item_id', fn ($q) => $q->select('id')->from('content.items')
                    ->where('user_id', $user->id)->where('kind', 'service')
                    ->whereNotExists(fn ($e) => $e->selectRaw('1')
                        ->from('content.manual_overrides')
                        ->whereColumn('content.manual_overrides.item_id', 'content.items.id')))
                ->whereNull('removed_at')
                ->update(['removed_at' => now(), 'updated_at' => now()]);

            app(UserCacheService::class)->invalidateServices((string) $user->id);

            return $this->success(['url' => null, 'selection' => null]);
        });
```

Note the trailing `, 30` TTL argument is REMOVED (back to the 10s default): the per-row `ServiceObserver` storm the 30s bound was sized for (its own comment) no longer exists — one raw UPDATE replaces it. Update the comment above the lock accordingly, keeping the booking-XOR-key reasoning. Add the `DB` and `UserCacheService` imports if missing.

- [ ] **Step 4: Delete the orphans.** `FreshaServiceProjector::revert()` + `rawEntryFor()` + `rawAttributes()` + `parseDuration()` — check callers first:

```bash
grep -rn "revert(\|rawEntryFor\|rawAttributes\|parseDuration" app/ tests/ --include='*.php' | grep -v FreshaServiceProjector.php
```

Delete what has no remaining caller (Task 4 removed the resync call sites; `parseDuration()` may have test-only callers — delete those cases with it). Delete `LegacyServiceSortOrder.php`, `ServiceBackfiller.php`, `BackfillOwnerServices.php`, their imports, their scheduled/console registrations (grep `content:backfill-owner-services` and the class names in `routes/console.php` + `app/Console/`), and their test files (grep `tests/` for each class name).

- [ ] **Step 5: Run the full suite chunk + `composer test:pg`; commit**

```bash
./vendor/bin/pest tests/Feature/Platforms/ tests/Feature/Services/ tests/Feature/Content/ --parallel
git add -A && git commit -m "feat(services-cutover): forget() maps disconnect onto source_items.removed_at; revert(), backfillers and LegacyServiceSortOrder deleted"
```

---

### Task 10: Models to DTOs, observers retired, the query-surface guard

**Files:**
- Modify: `app/Models/Core/User/Service.php`, `app/Models/Core/User/ServiceCategory.php`
- Modify: `app/Providers/EventServiceProvider.php` (`:17-18`, `:37-38`)
- Delete: `app/Observers/Core/ServiceObserver.php`, `app/Observers/Core/ServiceCategoryObserver.php`
- Create: `tests/Feature/Architecture/LegacyServiceQuerySurfaceTest.php`

- [ ] **Step 1: Write the failing guard test**

```php
<?php

use Symfony\Component\Finder\Finder;

// Services cutover: Service and ServiceCategory are in-memory DTOs — their
// tables are dropped. Any query through them is a guaranteed 42P01 in
// production shape that SQLite tests cannot catch. This guard turns the
// grep the cutover ran by hand into CI.
it('no code queries the Service or ServiceCategory models', function () {
    $offenders = [];
    $patterns = [
        'Service::query(', 'Service::where', 'Service::find', 'Service::withTrashed',
        'ServiceCategory::query(', 'ServiceCategory::where', 'ServiceCategory::find', 'ServiceCategory::withTrashed',
    ];

    foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
        $contents = $file->getContents();
        foreach ($patterns as $pattern) {
            if (str_contains($contents, $pattern)) {
                $offenders[] = $file->getRelativePathname().' contains '.$pattern;
            }
        }
    }

    expect($offenders)->toBe([]);
});
```

- [ ] **Step 2: Run it** — after Tasks 3–9 it should already pass; if it lists offenders, those are cutover stragglers: fix each (they are bugs in the earlier tasks, not exceptions to grant).

- [ ] **Step 3: Slim the models.** In `Service.php`: replace the class docblock's table description with:

```php
// Services cutover (2026-08): site.services is DROPPED. This class survives
// ONLY as the in-memory wire shape ServiceResource maps — every instance is
// unsaved (exists = false), hydrated by ManualServiceItems/FreshaServiceItems.
// NEVER query it: LegacyServiceQuerySurfaceTest pins that no code does.
```

Delete the `categories()` belongsToMany relation (its pivot is dropped; every consumer reads the pre-set relation from `hydrate()`), any query scopes, and the `SoftDeletes` trait if nothing reads `trashed()` on a DTO instance (grep first: `grep -rn "->trashed()" app/ | grep -i service`). Same treatment for `ServiceCategory.php` (its `services()` relation, scopes). Keep `$fillable`/`$casts` — `fill()` is still used on DTO construction paths.

- [ ] **Step 4: Retire the observers.** Delete the two `::observe` lines and imports from `EventServiceProvider`, then the two observer files. Their duties are already carried caller-side (both controllers' `invalidate()`/`reevaluateVisibility()` — the mirrors the observers' own docblocks name); no model-layer write path remains after Tasks 3–9. Grep `tests/` for `ServiceObserver|ServiceCategoryObserver` and delete/adjust direct-reference cases.

- [ ] **Step 5: Docblock sweep.** Update the stale `site.services` references left in: `ServicePolicy.php` (the two-store resolution notes — now one store), `ServiceResource.php`, `ManualServiceItems.php` (exportRows'/§C2 notes stay historical — annotate "dropped 2026-08" where they say "until slice 7 drops"), `ManualServiceWriter.php`, `ManualPoolWriter.php`, `AdvisoryLock.php`, `FreshaFetch.php`, `UserServiceCategoryController.php`, `Service.php`/`ServiceCategory.php` `@property` blocks. Comment-only — no behaviour.

- [ ] **Step 6: Full suite + statics**

```bash
COMPOSER_PROCESS_TIMEOUT=0 ./vendor/bin/pest --parallel
composer test:pg
php -d memory_limit=1G ./vendor/bin/phpstan analyse app tests --no-progress --debug
./vendor/bin/pint --dirty
```

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat(services-cutover): Service/ServiceCategory become DTOs, observers retired, query-surface guard added"
```

---

### Task 11: Phase gate — merge, deploy to dev, live-verify

The ordering law's checkpoint: every content.* path live-verified BEFORE any DROP is written.

- [ ] **Step 1: Merge `feat/services-cutover` → `development`, push.** (CI runs its nine jobs on `development` — wait for green.)
- [ ] **Step 2: Deploy happens on push (dev). Tail logs:**

```bash
cloud env:logs partna development --minutes 10
```

- [ ] **Step 3: Live-verify on dev, output pasted into the checkpoint draft:**
  - `GET /api/services` for `brotherwolf` and `vision` (the two live Fresha salons) — Fresha entries carry content ids, categories, prices.
  - Edit → resync round-trip on one Fresha service (title override on, then off).
  - Hide → unhide one service; confirm the blob's `hiddenServiceIds` moves and the booking surface follows.
  - Reorder; confirm `site.section_items` rows appear for Fresha items.
  - Staff index/show for the same salon.
  - The counts (SQL):

```sql
SELECT count(*) FROM site.section_items si JOIN content.items i ON i.id = si.item_id
JOIN content.source_items s ON s.item_id = i.id
JOIN content.sources cs ON cs.id = s.source_id
WHERE i.kind='service' AND cs.kind='connection';   -- > 0 after the reorder test
```

- [ ] **Step 4: Nightwatch scan** (`list_issues`, dev environment, since the deploy). Any new exception traced before proceeding.
- [ ] **Step 5: STOP.** Report the verification to the owner before Task 12 — the next task is irreversible.

---

### Task 12: The backup gate and the DROPs (spec Unit 6)

Only after Task 11's sign-off.

**Files:**
- Create: `supabase/migrations/20260818000100_drop_site_service_category_assignments.sql`
- Create: `supabase/migrations/20260818000200_drop_site_services.sql`
- Create: `supabase/migrations/20260818000300_drop_site_service_categories.sql`
- Modify: `tests/Schema/UpdatedAtTriggerCoverageTest.php`, `tests/Postgres/StaffCategoryReorderAtomicityTest.php`, `tests/Postgres/` stand-in DDL naming the three tables, `tests/Feature/Services/ServiceCategoryAssignmentRetirementTest.php` (retire — its subject table is gone; keep the staff-index case, moved into the cutover test file)

- [ ] **Step 1: The backup gate.** Get the dev DB URL the way `scripts/db/` scripts do (Cloud CLI env vars — never echoed, never written to disk):

```bash
pg_dump "$DEV_DB_URL" --no-owner --no-privileges -Fc \
  -t site.services -t site.service_categories -t site.service_category_assignments \
  -f services-teardown-$(date +%Y%m%d%H%M).dump
```

Restore into a scratch schema on LOCAL postgres (`scripts/db/fresh-reset.sh` provisions the base) and assert per-table counts match live EXACTLY:

```bash
createdb svc_teardown_scratch && pg_restore -d svc_teardown_scratch --no-owner services-teardown-*.dump
psql svc_teardown_scratch -c "select 'services', count(*) from site.services union all select 'cats', count(*) from site.service_categories union all select 'asgn', count(*) from site.service_category_assignments;"
```

versus the same SELECT on dev. **One table disagreeing = nothing is dropped.** Upload the dump to the `partna-db-backup` R2 bucket per `docs/superpowers/plans/2026-07-17-weekly-db-backup.md`'s mechanism; record location + counts in the checkpoint.

- [ ] **Step 2: Re-check the catalog** (the Task 1 Step 3 `pg_depend` query, plus):

```sql
SELECT polname, polrelid::regclass FROM pg_policy
WHERE polrelid IN ('site.services'::regclass,'site.service_categories'::regclass,'site.service_category_assignments'::regclass);
-- expect the seven known policies and nothing else
```

- [ ] **Step 3: Write the three migrations** (children before parents; convention from `20260817000400_drop_site_menu_categories.sql` — no `IF EXISTS`, fail loudly):

`20260818000100_drop_site_service_category_assignments.sql`:

```sql
-- Services cutover Phase — legacy teardown, 1 of 3. Child first (FKs to both
-- siblings, ON DELETE CASCADE).
-- ROLLBACK: NONE. A DROP TABLE has no reverse. Pre-image: the pg_dump in the
-- services-cutover checkpoint (partna-db-backup R2, counts matched exactly).
-- content.* twin: content.collection_items (owner lane source_id NULL wins,
-- connection lane replaced per run — spec §3.2).
-- Dies with the table: service_category_assignments_app_backend_all (RLS),
-- both FKs (services, service_categories).

BEGIN;

DROP TABLE site.service_category_assignments;

COMMIT;
```

`20260818000200_drop_site_services.sql`:

```sql
-- Services cutover Phase — legacy teardown, 2 of 3.
-- ROLLBACK: NONE. Pre-image: the pg_dump in the services-cutover checkpoint.
-- content.* twin: content.items kind='service' (owner half via the manual
-- source since 3a; Fresha half via the connection source since 3b), ordering
-- on site.section_items.sort_key, hidden state on the connection blob,
-- owner-delete on items.removed_at, vendor-departure on
-- source_items.removed_at.
-- Dies with the table: services_anon_select / services_pro_all /
-- services_staff_all (RLS), set_timestamp_services (trigger),
-- services_user_fk, services_user_sort_order_uq.
-- ACCEPTED LOSS (owner ruling 2026-08-17, spec §2.1): the 82 rows' legacy
-- uuids cease to resolve anywhere; 3 pre-deleted_origin soft-deleted rows
-- and 25 'sync'-deleted rows are not carried (spec §3.3).

BEGIN;

DROP TABLE site.services;

COMMIT;
```

`20260818000300_drop_site_service_categories.sql`:

```sql
-- Services cutover Phase — legacy teardown, 3 of 3. Parent last.
-- ROLLBACK: NONE. Pre-image: the pg_dump in the services-cutover checkpoint.
-- content.* twin: content.collections kind='service_category', keyed
-- (user_id, kind, external_ref) on the vendor's category id (3b §3.3).
-- Dies with the table: service_categories_pro_all / _public_read /
-- _staff_all (RLS), trg_service_categories_updated_at (trigger),
-- service_categories_user_fk.

BEGIN;

DROP TABLE site.service_categories;

COMMIT;
```

- [ ] **Step 4: Update the test lanes BEFORE applying.** `tests/Schema/UpdatedAtTriggerCoverageTest.php`: remove the two tables from its trigger expectations (or its exclusion list — read the test's own convention). `tests/Postgres/StaffCategoryReorderAtomicityTest.php`: the legacy half it exercises died in Task 8 — rewrite its scenario collections-only or delete it if fully superseded (state which in the commit). Grep `tests/Postgres/` stand-in DDL for the three table names and remove those CREATE TABLE stand-ins. Retire `ServiceCategoryAssignmentRetirementTest` (its table is gone); keep its staff-index case by moving the assertion into `ServicesCutoverFreshaManagementTest`.

- [ ] **Step 5: Apply to dev**

```bash
supabase db push --dry-run    # exactly the three 20260818* files
supabase db push
```

- [ ] **Step 6: Verify**

```sql
select to_regclass('site.services'), to_regclass('site.service_categories'), to_regclass('site.service_category_assignments');
-- expect: three NULLs
```

Then: one authenticated dashboard list, one staff list, one public profile, one DSAR export (`user:export` path) against dev — all 200, logs clean (`cloud env:logs partna development --minutes 10`), Nightwatch clean.

- [ ] **Step 7: Full local suites** — `composer test` (parallel), `composer test:pg`, `composer test:schema` (the applied-schema lane must be green against the post-drop dev schema).

- [ ] **Step 8: Commit + push**

```bash
git add supabase/migrations/2026081800*.sql tests/
git commit -m "feat(services-cutover)!: drop site.services, site.service_categories, site.service_category_assignments"
```

---

### Task 13: Close out

- [ ] **Step 1: Wire manifest completed** — add to `docs/wire-changes/2026-08-17-services-cutover.md`: the management-surface id break (legacy `site.services`/`site.service_categories` uuids 404 on every verb), the price-edit 422 on Fresha services, the one-category-space change (a Fresha service may now be filed under any owner collection; the two cross-space 422s are gone), the reorder payloads' id domain.
- [ ] **Step 2: Checkpoint** — append a "Services cutover checkpoint" section to the parent spec (`2026-08-11-content-pool-convergence-design.md`), pasting: the backup location + matched counts, the Task 11 live-verification outputs, the `to_regclass` nulls, `pg_depend` clean, suite/lane results. Every assertion re-run, not cited.
- [ ] **Step 3: Update the A2 legacy-zero sweep list** — `phase-8-review-and-docs` should now name zero remaining legacy tables from this programme's drop list (the kickoff's closing note).
- [ ] **Step 4: Commit docs; report to the owner.**

---

## Self-review record

- **Spec coverage:** §3.1→Task 1; §3.2→Tasks 2,3,4,7,8; §3.3→Tasks 3,9 (delete/restore/disconnect semantics + tests); §3.4→Task 5; §3.5→Tasks 6,9,10 (readers, `revert`, DTO/guard); §3.6→Task 12; §4 invalidation→woven into every write task; §5 verification→Tasks 11,12,13; §7 deferrals (anseo-studio, no-selection prompt) are out of scope by ruling and appear in no task — correct.
- **Known judgement calls made here, flagged for the executor:** (1) Task 5 records the documents-lane `pinned` landmine in code — if the reviewer prefers, the comment may be promoted to a `PoolRegistry` note, but the public read stays the enforced boundary; (2) Task 3's price-echo tolerance (422 only on a CHANGED price) is deliberate so dashboard round-trips don't break; (3) users holding both halves see Fresha items tail the list until their first reorder (no sort_key yet) — one-time, self-healing, noted for the manifest.
- **Type consistency:** `findRow`/`managementRows`/`toServiceModel(s)`/`hiddenServiceIds`/`overriddenItemIds`/`liveItemIds` signatures are quoted identically in Tasks 2–9; `pinServiceOrder` appears only in the staff controller (the user controller inlines the same loop).
