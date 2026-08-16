# Slice 7 — Legacy Teardown Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the last six live write lanes off ten legacy `site.*` tables onto `content.*`, then drop those tables from dev.

**Architecture:** Every legacy row already has a live `content.*` coord (coverage gate green, 2026-08-16). What remains is write paths. Each unit repoints one lane onto the existing `content.*` writers, verifies live on dev, and only then does a final phase drop the tables. Slice 3a's `ManualServiceWriter` / `ManualServiceItems` pair is the template — it turns out to be kind-agnostic except for a single pool-key literal, so menus reuse it rather than growing a parallel copy.

**Tech Stack:** PHP 8.4, Laravel 12, PostgreSQL (Supabase), Pest 4, raw SQL migrations under `supabase/migrations/`.

## Global Constraints

- **Dev only.** Never apply a migration to the prod ref (`edplucmvkcnokyygxqsb`); never `git push origin development:production`. Prod is this slice's closing write-up, not its work.
- **The ordering law.** Every `content.*` write path lands and is live-verified BEFORE the DROP that removes its legacy twin. Phase 6 is the only phase that drops anything.
- **Never create Laravel migration files.** Raw SQL in `supabase/migrations/`, one concern per file, at most one `CONCURRENTLY` statement per file.
- **Coord scheme is fixed.** `MenuProjectionMapper::coordFor($menuId, $name)` = `manual:menu:{menu_id}:{sha1(normalizeName($name))}`. Never re-derive it; a second derivation mints duplicate items for all 318 existing dishes.
- **`ContentItemSlugAllocator::SLUGGED_KINDS` must keep `'menu_item'`.** That const IS slice 4's re-homing of `MenuItemObserver`'s slug duty. Removing it silently stops every dish getting a permalink.
- **Authorization via Policies only.** `$this->authorizeForUser($user, ...)` — never `abort_unless(...403)`. CI fails the build on inline 403s.
- **Every cache key carries a TTL.** Never `Cache::forever()`.
- **Cache invalidation is three independent lanes** per touched site: `BuildState::bump($siteId)`, touch `site.sites.updated_at`, `CloudflareCachePurgeJob::dispatch($subdomain)`. `SiteCacheLanes::bust($siteIds)` fires all three. A `BuildState` bump alone does NOT bust the 60s payload cache.
- **Tests run SQLite; prod is Postgres.** Verify constraint-bound writes against `supabase/migrations/` DDL. Changes touching `ProjectionWriter` require `composer test:pg`, not just `composer test`.
- **Test helper functions must be file-unique.** Cross-file helpers with the same name fatal under `--parallel`. Prefix per file (existing convention: `mmc*` in `ManualMenuContentTest.php`).
- **PHPStan in a worktree:** `php -d memory_limit=1G ./vendor/bin/phpstan analyse <path> --no-progress --debug`. The default invocation OOMs and misreports it as "severe errors".
- **Branch:** `feat/slice-7-teardown` off latest `origin/development`, in a dedicated worktree.

---

## File Structure

**Phase 1 — the shared writer, generalised**
- Modify: `app/Services/Content/ManualServiceWriter.php:142` — the single `'services'` literal becomes a constructor-injected pool key. This is the whole of the "build a menu writer" problem; every other method is already kind-agnostic.
- Create: `app/Services/Content/ManualMenuWriter.php` — thin subclass binding the pool key `'menus'` and the menu projection mapping.
- Create: `app/Services/Content/ManualMenuItems.php` — the read side, mirroring `ManualServiceItems`: `rows()`, `find()`, `dashboardRows()`, `toMenuItemModel()`.

**Phase 2 — the menu write lane**
- Modify: `app/Http/Controllers/Api/Platforms/MenuContentController.php` — 10 verbs off Eloquent legacy models onto `ManualMenuWriter`/`ManualMenuItems`.
- Modify: `app/Services/Platforms/MenuPayloadComposer.php` — read side, one `compose()` seam.
- Modify: `app/Jobs/Platforms/MenuFetchJob.php` — `persist()` only. Scrape, `MenuMerger`, rate limits and cost controls untouched (spec D1).
- Modify: `app/Services/Platforms/MenuScanApplier.php`, `app/Jobs/Platforms/WebsiteMenuHtmlScanJob.php`, `app/Jobs/Platforms/WebsiteMenuPdfScanJob.php`.

**Phase 3 — public menu wire + Fresha + services residuals**
- Delete: `app/Http/Controllers/Api/PublicSite/PublicMenuController.php`, its route, `app/Services/Platforms/MenuItemDeepLinks.php`.
- Modify: `app/Services/Platforms/FreshaServiceProjector.php` — compose from `content.*`.
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php:1121-1134`, `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceManagementController.php:612-625`, `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceCategoryManagementController.php` (the `index()` two-id-space merge).

**Phase 4 — standalone events + content selection**
- Create: `app/Services/Migration/StandaloneEventBackfiller.php`.
- Modify: `app/Services/Platforms/EventsCatalog.php`, `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php`.
- Delete: `app/Services/Site/ContentSelectionService.php`, `app/Models/Core/Site/ContentSelection.php`, four routes in `routes/api/user.php:412-417`.

**Phase 5 — observers, policies, DSAR, analytics**
- Delete: `app/Observers/MenuItemObserver.php`, `app/Observers/Core/{Menu,Service,ServiceCategory,SiteMedia}Observer.php` (per-observer, only after its duties are confirmed re-homed), their registrations in `app/Providers/EventServiceProvider.php:39-45`.
- Delete: `app/Policies/ServicePolicy.php`, `app/Policies/ContentSelectionPolicy.php`.
- Modify: `app/Services/User/DataExport/DataExportPayloadBuilder.php:269,822,841`.

**Phase 6 — the DROPs**
- Create: `supabase/migrations/20260817*_drop_*.sql` — one table concern per file, children before parents.
- Modify: `app/Services/Shop/ShopContentWriter.php` (off the `ShopBrand` model), `app/Services/Cloudflare/CloudflarePurgeService.php`.
- Delete: `app/Console/Commands/BackfillClaimedGoogleBusinessReviewsCommand.php`, the ten legacy models, `app/Services/Migration/{MenuBackfiller,ShopBackfiller,ServiceBackfiller,ContentSelectionMigrator}.php` and their commands.

---

## Phase 1 — Generalise the manual writer

Foundation for everything else. No behaviour change; a pure refactor with the existing service suite as the regression net.

### Task 1: Parameterise the pool key on `ManualServiceWriter`

**Files:**
- Modify: `app/Services/Content/ManualServiceWriter.php:26-32` (constructor), `:140-156` (`curationRow`)
- Test: `tests/Feature/Content/ManualWriterPoolKeyTest.php`

**Interfaces:**
- Consumes: `PoolSectionProvisioner::ensure(Site $site, string $pool): object` — already takes a pool key.
- Produces: `ManualServiceWriter::__construct(ProjectionWriter, PoolSectionProvisioner, ContentItemSlugAllocator, string $pool = 'services')`. Task 2 subclasses this with `$pool = 'menus'`.

**Why this is safe:** `:142` is the *only* pool-specific line in the class. `write()`, `pin()`, `exclude()`, `markRemoved()`, `clearRemoved()`, `coordFor()` and `invalidate()` are kind-agnostic, and `freeSlug()`/`remintSlug()` are already guarded by `SLUGGED_KINDS` rather than by kind literals.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Content\ManualServiceWriter;
use App\Site\Pools\PoolSectionProvisioner;

it('provisions the curation section for the pool it was constructed with', function () {
    $provisioner = Mockery::mock(PoolSectionProvisioner::class);
    $provisioner->shouldReceive('ensure')
        ->once()
        ->withArgs(fn ($site, $pool) => $pool === 'menus')
        ->andReturn((object) ['id' => 'section-uuid']);

    $writer = new ManualServiceWriter(
        app(App\Ingest\Projection\ProjectionWriter::class),
        $provisioner,
        app(App\Services\Content\ContentItemSlugAllocator::class),
        'menus',
    );

    $writer->pin(mwpkSite(), 'item-uuid', 1.0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Content/ManualWriterPoolKeyTest.php`
Expected: FAIL — `ManualServiceWriter::__construct()` takes 3 arguments, 4 given.

- [ ] **Step 3: Add the constructor parameter and use it**

```php
public function __construct(
    private readonly ProjectionWriter $writer,
    private readonly PoolSectionProvisioner $sections,
    private readonly ContentItemSlugAllocator $slugs,
    // Slice 7: the ONE pool-specific value in this class. Everything else —
    // projection write, pin/exclude, removal, slug bookkeeping — is
    // kind-agnostic, which is why menus subclass this rather than copy it.
    private readonly string $pool = 'services',
) {}
```

And at `:142`:

```php
$section = $this->sections->ensure($site, $this->pool);
```

- [ ] **Step 4: Run the new test and the full service suite**

Run: `./vendor/bin/pest tests/Feature/Content/ tests/Feature/Api/User/SiteManagement/`
Expected: PASS, including every pre-existing service test — the default `'services'` keeps all current callers behaviourally identical.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Content/ManualServiceWriter.php tests/Feature/Content/ManualWriterPoolKeyTest.php
git commit -m "refactor(slice-7): parameterise ManualServiceWriter's pool key

The class is already kind-agnostic apart from one literal at :142. Threading
the pool key is what lets menus reuse it in Task 2 instead of growing a
parallel 150-line copy that would drift."
```

### Task 2: `ManualMenuWriter`

**Files:**
- Create: `app/Services/Content/ManualMenuWriter.php`
- Test: `tests/Feature/Content/ManualMenuWriterTest.php`

**Interfaces:**
- Consumes: Task 1's `ManualServiceWriter` constructor; `MenuProjectionMapper::coordFor()`, `::project()`.
- Produces: `ManualMenuWriter::projectionFor(object $dish, array $categories, array $platformRows, object $menu): array` and `::coordFor(string $menuId, string $name): string`. Phase 2 consumes both.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Content\ManualMenuWriter;

it('derives the same coord the slice-4 backfill used', function () {
    expect(app(ManualMenuWriter::class)->coordFor('menu-1', 'Pizza  Margherita'))
        ->toBe(App\Services\Platforms\MenuProjectionMapper::coordFor('menu-1', 'Pizza  Margherita'));
});

it('projects a dish through the slice-4 mapper', function () {
    $dish = (object) ['name' => 'Iced Latte', 'base_price' => 5.50, 'currency' => 'AUD'];
    $menu = (object) ['currency' => 'AUD'];

    $projection = app(ManualMenuWriter::class)->projectionFor($dish, [], [], $menu);

    expect($projection['kind'])->toBe('menu_item')
        ->and($projection['headline'])->toBe('Iced Latte')
        ->and($projection['offers'][0]['amount_minor'])->toBe(550);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Content/ManualMenuWriterTest.php`
Expected: FAIL — class `App\Services\Content\ManualMenuWriter` not found.

- [ ] **Step 3: Write the class**

```php
<?php

namespace App\Services\Content;

use App\Ingest\Projection\ProjectionWriter;
use App\Services\Platforms\MenuProjectionMapper;
use App\Site\Pools\PoolSectionProvisioner;

/**
 * Slice 7 unit A: the menus twin of the services writer, and deliberately a
 * SUBCLASS rather than a copy — every curation and removal method on the
 * parent is kind-agnostic, so the only differences are the pool key and the
 * projection mapping.
 *
 * The mapping is MenuProjectionMapper, unchanged, because slice 4's backfill
 * used it for all 318 existing dishes. A second derivation of either the
 * projection or the coord would mint duplicate items for every one of them.
 */
class ManualMenuWriter extends ManualServiceWriter
{
    public function __construct(
        ProjectionWriter $writer,
        PoolSectionProvisioner $sections,
        ContentItemSlugAllocator $slugs,
        private readonly MenuProjectionMapper $mapper,
    ) {
        parent::__construct($writer, $sections, $slugs, 'menus');
    }

    public function coordFor(string $menuId, string $name): string
    {
        return MenuProjectionMapper::coordFor($menuId, $name);
    }

    /**
     * @param  list<array{id: string, name: string, position: int}>  $categories
     * @param  list<object>  $platformRows
     * @return array<string, mixed>
     */
    public function projectionFor(object $dish, array $categories = [], array $platformRows = [], ?object $menu = null): array
    {
        return $this->mapper->project($dish, $categories, $platformRows, $menu ?? (object) []);
    }
}
```

Note: `coordFor()` and `projectionFor()` deliberately shadow the parent's signatures. Add `@phpstan-ignore` only if PHPStan objects on the LSP violation; prefer renaming the parent's `coordFor(string $userId, string $itemId)` to `coordForItem()` if it does, updating its five call sites.

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/pest tests/Feature/Content/ManualMenuWriterTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Content/ManualMenuWriter.php tests/Feature/Content/ManualMenuWriterTest.php
git commit -m "feat(slice-7): ManualMenuWriter, reusing slice 4's projection mapper"
```

### Task 3: `ManualMenuItems` — the read side

**Files:**
- Create: `app/Services/Content/ManualMenuItems.php`
- Test: `tests/Feature/Content/ManualMenuItemsTest.php`

**Interfaces:**
- Consumes: `content.items`, `content.source_items`, `content.offers`, `content.collections`, `content.collection_items`, `content.item_media`.
- Produces:
  - `rows(string $userId, bool $includeRemoved = false): Collection` — one row per live `menu_item`, with `id`, `coord`, `headline`, `description`, `base_price`, `pickup_price`, `delivery_price`, `currency`, `image_url`, `badges`, `category_ids`, `platforms`.
  - `find(string $userId, string $itemId): ?object`
  - `categories(string $userId): Collection` — `content.collections` kind `menu_category`, ordered by `position` then `label`.
  - `toMenuItemModel(object $row): MenuItem` — an **unsaved** legacy-shaped model so `MenuPayloadComposer` and the dashboard resources keep emitting today's payload.

Phase 2 Tasks 5–8 consume all four.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Content\ManualMenuItems;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupItemSlugsTable();
});

it('returns one row per live menu_item with its offers folded to legacy columns', function () {
    $user = mmiSeedDishWithOffers(base: 5.50, pickup: 5.00, delivery: 6.50);

    $rows = app(ManualMenuItems::class)->rows((string) $user->id);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->base_price)->toBe(5.50)
        ->and($rows[0]->pickup_price)->toBe(5.00)
        ->and($rows[0]->delivery_price)->toBe(6.50);
});

it('omits items whose removed_at is set', function () {
    $user = mmiSeedDishWithOffers(base: 5.50);
    mmiMarkRemoved();

    expect(app(ManualMenuItems::class)->rows((string) $user->id))->toHaveCount(0);
});

it('includes removed items when asked', function () {
    $user = mmiSeedDishWithOffers(base: 5.50);
    mmiMarkRemoved();

    expect(app(ManualMenuItems::class)->rows((string) $user->id, includeRemoved: true))->toHaveCount(1);
});
```

Helper names prefixed `mmi` — cross-file helper collisions are fatal under `--parallel`.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Content/ManualMenuItemsTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement, mirroring `ManualServiceItems`**

Read `app/Services/Content/ManualServiceItems.php` first and follow its query shape: a single builder joining `content.source_items` → `content.sources` filtered to this user, left-joining facets, with offers aggregated per channel. The service version aggregates one offer; menus aggregate three channels (`base`, `pickup`, `delivery`) plus the per-platform offers carrying URLs.

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/pest tests/Feature/Content/ManualMenuItemsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Verify against real dev data**

Run a tinker one-liner against dev (credentials from the Cloud CLI into process env; never write `.env`) asserting `rows()` returns 318 for the five menu-owning users and that a spot-checked dish's prices match `site.menu_items`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Content/ManualMenuItems.php tests/Feature/Content/ManualMenuItemsTest.php
git commit -m "feat(slice-7): ManualMenuItems — the content.* read side for menus"
```

### Task 4: Phase 1 gate

- [ ] **Step 1: Full suite**

Run: `composer test`
Expected: PASS, no regression against the pre-phase baseline count.

- [ ] **Step 2: Postgres lane** — Task 1 changed a `ProjectionWriter` caller.

Run: `composer test:pg`
Expected: PASS.

- [ ] **Step 3: Static analysis**

Run: `php -d memory_limit=1G ./vendor/bin/phpstan analyse app/Services/Content --no-progress --debug`
Expected: no errors.

- [ ] **Step 4: Independent review of the phase diff, then merge to `development` and deploy dev.**

---

## Phase 2 — The menu write lane onto `content.*`

The largest phase. Every task keeps the dashboard wire shape identical (spec unit A); the public wire is Phase 3's business.

### Task 5: `MenuPayloadComposer` reads `content.*`

**Files:**
- Modify: `app/Services/Platforms/MenuPayloadComposer.php:88-198`
- Test: `tests/Feature/Platforms/MenuPayloadComposerContentTest.php`

**Interfaces:**
- Consumes: `ManualMenuItems::rows()`, `::categories()`, `::toMenuItemModel()` (Task 3).
- Produces: `compose(User $user, ?Menu $menu): array` — **byte-identical output** to today for the same data. That equality is the task's whole test.

- [ ] **Step 1: Write the characterisation test FIRST, against the legacy path**

Before changing anything, seed a menu through the legacy tables, call `compose()`, and snapshot the exact array. This is the oracle for the cutover.

```php
it('composes the same payload from content.* as it did from the legacy tables', function () {
    $user = mpcSeedLegacyMenu();           // legacy tables
    $legacy = app(MenuPayloadComposer::class)->compose($user, mpcMenu($user));

    mpcBackfillToContent($user);           // same data, content.* side
    mpcTruncateLegacyDishes();             // prove it is not reading them

    expect(app(MenuPayloadComposer::class)->compose($user, mpcMenu($user)))->toEqual($legacy);
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Platforms/MenuPayloadComposerContentTest.php`
Expected: FAIL — the composer still reads the (now truncated) legacy tables, returning empty categories.

- [ ] **Step 3: Repoint `categories()` and `platforms()` at `ManualMenuItems`**

Keep `load()` and `hasOwnerContent()` on `site.menus`, which survives the teardown.

- [ ] **Step 4: Run the test plus the whole existing menu suite**

Run: `./vendor/bin/pest tests/Feature/Platforms/`
Expected: PASS — 13 pre-existing menu test files included.

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(slice-7): MenuPayloadComposer reads content.*, payload byte-identical"
```

### Task 6: `MenuContentController` — the 10 owner verbs

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/MenuContentController.php`
- Test: `tests/Feature/Platforms/ManualMenuContentTest.php` (extend — 10 verbs already covered)

**Interfaces:**
- Consumes: `ManualMenuWriter` (Task 2), `ManualMenuItems` (Task 3), `MenuPayloadComposer` (Task 5).
- Produces: no signature change. Routes, request classes and response shape are all unchanged.

**Behaviour that must survive** — each needs an explicit assertion in this task, not an assumption:

| Behaviour | Where it lives now | Where it goes |
|---|---|---|
| `is_manual` — owner edit detaches a scraped dish from sync | `menu_items.is_manual` | a facet or `site.menus` bookkeeping; decide in Step 1 and record it |
| `menus.suppressed_items` — a deleted scraped dish stays dead | `site.menus` (survives) | unchanged; the `content.*` path must honour it |
| `source_platform='scan'` categories survive a rebuild | `menu_categories.source_platform` | `content.collections` — needs a facet or ref convention |
| `EDITABLE_SOURCES` — scraper categories are off-limits to hand edits | controller const | unchanged const, applied against the new category source |

- [ ] **Step 1: Decide and document the four homes above** in a comment block at the top of the controller, before writing code. A wrong choice here is the one that silently loses owner intent on the next scrape.

- [ ] **Step 2: Write failing tests for each of the four behaviours** in `ManualMenuContentTest.php`, asserting through the API, not the model.

- [ ] **Step 3: Run them to confirm they fail**

Run: `./vendor/bin/pest tests/Feature/Platforms/ManualMenuContentTest.php`

- [ ] **Step 4: Cut `createItem`, `updateItem`, `deleteItem`, `bulkDeleteItems` onto the writer**

`deleteItem` calls `markRemoved()`, which frees the slug via `SLUGGED_KINDS`. `createItem`/`updateItem` call `write($userId, $coord, $projection)` where `$coord = $writer->coordFor($menuId, $name)`.

- [ ] **Step 5: Cut `createCategory`, `updateCategory`, `deleteCategory`, `reorderCategories`, `reorderItems`**

Categories become `content.collections` kind `menu_category`, keyed on `MenuProjectionMapper::categoryRef($label)`. **`position` on a collection is a seed** — written on insert, never updated by a scheduled run, because an owner can reorder (parent §19 / 3b). Owner reorder is a pin, not a position rewrite: use `pin()` with a sort key, exactly as `UserServiceController` does.

- [ ] **Step 6: Run the full menu suite**

Run: `./vendor/bin/pest tests/Feature/Platforms/`
Expected: PASS.

- [ ] **Step 7: Invalidate all three cache lanes once per touched site**

`touchAndRespond()` already exists as the single exit point — route `SiteCacheLanes::bust([$siteId])` through it. Assert an **exact** `content_revision` delta, not `> 0`: `writeManualItem()` bumps internally, so a `> 0` assertion passes with the lane deleted (3a shipped that trap).

- [ ] **Step 8: Commit**

```bash
git commit -am "feat(slice-7): MenuContentController's 10 verbs write content.*"
```

### Task 7: `MenuFetchJob::persist()`

**Files:**
- Modify: `app/Jobs/Platforms/MenuFetchJob.php:360-660,820-860`
- Test: `tests/Feature/Platforms/MenuTest.php`, `MenuFetchJobWebsiteScanProtectionTest.php`

**Interfaces:**
- Consumes: `ManualMenuWriter`, `MenuProjectionMapper`.
- Produces: no signature change. `MenuMerger`, `MenuApifyScraper`, the rate limiter, `$tries`/`$backoff`/`uniqueFor` and the cost controls are **untouched** (spec D1 — the ingest lane covers 0 of the 5 real menus and is `auto_sync=false`).

- [ ] **Step 1: Write a failing test** asserting a scrape lands `content.items` and leaves `site.menu_items` untouched.
- [ ] **Step 2: Run it; confirm it fails.**
- [ ] **Step 3: Replace the delete-and-reinsert block with per-dish `write()` calls.**

The legacy writer deletes and re-inserts every dish per scrape, reusing uuids by normalised name. The coord scheme already absorbs that (its docblock explains why it is name-derived, not uuid-derived), so an upsert-by-coord is a straight simplification: no `takeReusedId()`, no orphan cleanup.

Dishes absent from this scrape get `markRemoved()`, **not** a hard delete — and never `source_items.removed_at`, which is cleared on reappearance and would resurrect an owner-deleted dish.

- [ ] **Step 4: Preserve `rebuildableCategoryIds()`'s exclusion of `scan` / `website-scan` / `manual` categories.**
- [ ] **Step 5: Run the menu suite + the Postgres lane.**

Run: `./vendor/bin/pest tests/Feature/Platforms/ && composer test:pg`

- [ ] **Step 6: Commit.**

### Task 8: `MenuScanApplier` and the two website-scan jobs

**Files:**
- Modify: `app/Services/Platforms/MenuScanApplier.php`, `app/Jobs/Platforms/WebsiteMenuHtmlScanJob.php`, `app/Jobs/Platforms/WebsiteMenuPdfScanJob.php`
- Test: `tests/Feature/Platforms/MenuScanApplierSourceTest.php`, `WebsiteMenuHtmlScanJobTest.php`, `WebsiteMenuPdfScanJobTest.php`

- [ ] **Step 1–5:** same cycle. The match-on-normalised-name rule becomes match-on-coord, which is what the normalised name already hashes to — so "one normalized name = one dish, menu-wide" is now enforced by the coord's uniqueness rather than by a lookup. Assert that a scan listing one dish under two categories still produces ONE item with two collection memberships.
- [ ] **Step 6: Commit.**

### Task 9: Phase 2 gate — live verification on dev

- [ ] **Step 1:** `composer test`, `composer test:pg`, PHPStan, `php artisan pint`.
- [ ] **Step 2:** Merge to `development`, deploy dev.
- [ ] **Step 3:** On dev, exercise each of the 10 verbs against a real menu and assert `content.items` moved and `site.menu_items` did not.
- [ ] **Step 4:** Trigger one real scrape (`POST /api/platforms/menu/refresh`) and assert the same.
- [ ] **Step 5:** `cloud env:logs partna development --minutes 10` + a Nightwatch scan. Zero new error classes.
- [ ] **Step 6:** Paste the assertions into the parent-spec checkpoint.

---

## Phase 3 — Public menu wire, Fresha, services residuals

### Task 10: Delete the public `/menu` endpoint

**Files:**
- Delete: `app/Http/Controllers/Api/PublicSite/PublicMenuController.php`, `app/Services/Platforms/MenuItemDeepLinks.php`, `tests/Feature/Api/PublicSite/PublicMenuControllerTest.php`
- Modify: `routes/api.php:201`
- Create: `docs/wire-changes/2026-08-12-slice-7-teardown.md`

Spec D2: `pools.menus` is a complete replacement and the sitepage is being rebuilt, so this is a deletion, not a repoint.

- [ ] **Step 1: Write a failing test** asserting `GET /api/public/profiles/{handle}/menu` returns 404.
- [ ] **Step 2: Run it; confirm it fails** (currently 200).
- [ ] **Step 3: Delete the controller, the route, `MenuItemDeepLinks` and the old test file.**
- [ ] **Step 4: Run it; confirm 404.**
- [ ] **Step 5: Open the wire manifest** with this as its first entry — every key that died, named.
- [ ] **Step 6: Commit.**

### Task 11: Fresha `payload.selection` from `content.*`

**Files:**
- Modify: `app/Services/Platforms/FreshaServiceProjector.php`
- Test: `tests/Feature/Platforms/FreshaSelectionFromContentTest.php`

Spec D3: keep the blob, change its source. §19.2 already proved the round-trip back into the vendor's display grammar (9 keys, real category label).

- [ ] **Step 1: Characterisation test** — snapshot `compose()`'s output from legacy rows, then assert equality when composed from `content.*` with the legacy rows truncated.
- [ ] **Step 2: Run it; confirm it fails.**
- [ ] **Step 3: Repoint `compose()` at `App\Services\Content\FreshaServiceItems::selectionServices()`.**

  **Corrected 2026-08-16 — the plan originally named `ManualServiceItems::publicList()` and that was wrong on three counts**, any one fatal: it returns the seven legacy *dashboard* keys, not the nine *vendor* keys the blob ships; it filters `content.sources.kind = 'manual'` while Fresha lands under `kind = 'connection'`, so it would publish the wrong list entirely; and routing the write through `ManualServiceWriter::write()` would put Fresha services on the manual source, which `ServiceTwoSurfaceTest` pins shut in both directions. `FreshaServiceItems::selectionServices()` shipped with 3b, already produces the blob shape from `content.*`, and is what spec D3 actually describes. `markRemoved()` IS still safe to reuse — it is kind-agnostic and touches only `content.items`; it is `write()` that leaks.
- [ ] **Step 4: Repoint `sync()`** — it stops writing `site.services` and writes `content.*` via `ManualServiceWriter`. Preserve `is_manual` (owner edit detaches from sync), `deleted_origin='user'` suppression (a deleted service never returns) and `deleted_origin='sync'` restore-on-return. The first two map to `items.removed_at` + curation state; the third to `source_items.removed_at`.
- [ ] **Step 5: Re-check `ManagesIntegrationConnection`'s advisory-lock timeouts** (`:396-450`). Narrow rather than delete — each docblock says what it guards.
- [ ] **Step 6: Run** `./vendor/bin/pest tests/Feature/Platforms/ tests/Feature/Api/` **and** `composer test:pg`.
- [ ] **Step 7: Commit.**

### Task 12: Services residuals

**Files:**
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php:1121-1134`, `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceManagementController.php:612-625`, `.../StaffServiceCategoryManagementController.php` (`index()`)

- [x] **Step 1: Failing test** — 5 cases in `tests/Feature/Services/ServiceCategoryAssignmentRetirementTest.php` (`svcAsgnRetire*` helpers): owner and staff reorder-layout each write no pivot row for a newly-filed Fresha service AND leave a pre-existing row untouched when re-filing, plus the staff category index returns collection ids only.
- [x] **Step 2: Run; confirm it fails.** 5 failed on the right causes — an inserted pivot row, a re-filed pivot row, and the legacy id present in the index.
- [x] **Step 3: Delete both hand-maintained assignment-sync blocks.** Owner: the loop plus its now-dead `$orderedFreshaServiceIds` accumulator. Staff: the loop. `$membershipsByService` stays on both sides — it is validation input (the categorised-vs-uncategorised and coverage checks), not a write buffer. Both docblocks corrected: NO memberships are written by either twin now.
- [x] **Step 4: Remove `StaffServiceCategoryManagementController::index()`'s two-id-space merge.** The `->concat($this->legacyCategories(...))` and the now-unreferenced `legacyCategories()` helper are gone. The by-id legacy branches (`show`/`update`/`destroy`/`restore`/`reorder`) and the seven routes' split across the two staff middleware groups are untouched.
- [x] **Step 5: Run.** Full `./vendor/bin/pest --parallel` 8371 passed / 2 skipped (baseline 8366 + the 5 new). `composer test:pg` 207 passed / 959 assertions against a throwaway `postgres:16`. PHPStan `[OK] No errors`, no baseline entry made unmatched. Pint clean. Two existing cases inverted rather than deleted: `ServiceLayoutReorderTest`'s "moves a service to a different category" and `StaffServiceCategoryCutoverTest`'s "lists BOTH … halves". Wire manifest updated.
- [x] **Step 6: Commit.**

**Residual pivot writes left standing, deliberately.** `destroyLegacy()`'s raw detach in `StaffServiceCategoryManagementController` and `->categories()->sync()` in both controllers' legacy `category_id` branches still write `site.service_category_assignments`. They hang off the `Service`/`ServiceCategory` models that Task 27 Step 5 deletes with the tables; removing them here would change the legacy branches' behaviour ahead of their retirement. Same for the `belongsToMany` READS both grouped `index()` methods make through those relations.

### Task 13: Phase 3 gate

Same shape as Task 9: suites, PHPStan, Pint, merge, deploy, live-verify on dev, logs, Nightwatch, checkpoint.

---

## Phase 4 — Standalone events, content selection

### Task 14: Standalone events, steps 1–2

**Files:**
- Create: `app/Services/Migration/StandaloneEventBackfiller.php`, `app/Console/Commands/BackfillStandaloneEvents.php`
- Test: `tests/Feature/Content/StandaloneEventBackfillTest.php`

Parent §7's four-step. Dev carries **2 live** `resource_kind='event'` connections. Coord `manual:{sha1(url)}` per §1.7's one-coord-per-URL rule.

- [x] **Step 1: Failing test** — a standalone `event` connection lands one `content.items` row of kind `event`, idempotent across two runs. (`tests/Feature/Content/StandaloneEventBackfillTest.php`, 16 cases, `sev*` helpers.)
- [x] **Step 2: Run; confirm it fails.** Failed on the missing class, after a first run that failed on the fixture (`platform` is a GENERATED column).
- [x] **Step 3: Implement** on `ProjectionWriter::writeManualItem()`. `StandaloneEventBackfiller` + `content:backfill-standalone-events`. Coord `manual:{sha1(strtolower(trim(link)))}` = `ManualEventWriter::coordFor()`. Active rows PINNED (the legacy wire published a standalone event regardless of its dates; `upcoming_occurrence` alone would drop dev's 2024-start row), inactive EXCLUDED, first write wins.
- [x] **Step 4: Repoint EVERY add-an-event verb**, not just the card's. Three live paths wrote `resource_kind='event'`, not one: `EventsCatalog::storeStandalone()` (repointed), `EventsPlatformController::addStandaloneEvent()` (repointed — it called `writeConnection()` directly and never flowed through the first), and `EventsSeeder::seedStandalone()` (DUAL WRITE — the connection is kept because the synced-modal finding lane resolves against it in two controllers). All three share one projection mapper with the backfiller. Both selection readers skip a connection row whose URL already has a pool card. The per-platform cap is preserved on `ManualEventWriter::MAX_STANDALONE_EVENTS`, counting the owner's own manual-source event items.
- [x] **Step 5: Run; dry-run against dev; counts pasted.** 2 would-backfill, 0 duplicate url, 0 skipped (no url), 0 skipped (no site), 0 already curated, 0 failed. **The real backfill has NOT been run.**
- [x] **Step 6: Commit.**

### Task 15: Standalone events, steps 3–4

- [x] **Step 1: Failing test** — `LegacyEventsLaneRetiredTest` inverted: the two "keeps publishing a standalone…" cases become "publishes nothing…".
- [x] **Step 2: Run; confirm it fails.** Failed on the full 5-key payload, as expected.
- [x] **Step 3: Empty the standalone payload.** `PublicIntegrationConnectionResource::EVENT_PLATFORMS` + `::STANDALONE_EVENT_KEYS` deleted with the `filterPayload()` branch. All 17 keys named in `docs/wire-changes/2026-08-12-slice-7-teardown.md`.
- [x] **Step 4: Standalone-event permalinks — DECIDED: `content.item_slugs` RE-MINTS them; nothing is adopted or copied, and Phase 6 deletes all 11 legacy rows as planned.** `event` is already in `ContentItemSlugAllocator::SLUGGED_KINDS`, both allocators derive their base identically (`Str::slug`, 80-char word-boundary truncate), and the pool slugs from the same `name`. Verified against dev: `nerve-melbourne-2026` and `hobart-mens-hair-workshop-at-simondoylehair-at-development-au` both reproduce byte-for-byte and neither is squatted in `content.item_slugs`. This also closes two of slice 2's three "permalinks stop resolving" regressions. Reasoning in full in the manifest.
- [x] **Step 5: Run the suite; commit.** Full suite green (8360 passed / 2 skipped), `composer test:pg` green (205 passed / 2 skipped), PHPStan clean, Pint clean.

### Task 16: Retire `site.content_selection`

**Files:**
- Delete: `app/Services/Site/ContentSelectionService.php`, `app/Models/Core/Site/ContentSelection.php`, `app/Policies/ContentSelectionPolicy.php`, routes `routes/api/user.php:412-417`
- Modify: `app/Services/PublicSite/SitepageDataResolverService.php:478-499`, `app/Services/PublicSite/IndividualProfilePayloadBuilder.php:257-285`

Under the 2026-08-14 override the `gallery` / `designMedia` / `siteImages` keys are **deleted outright, not dual-served**.

- [x] **Step 1: Failing test** — the public profile payload carries no `designMedia`, `gallery` or `siteImages` key. (`tests/Feature/PublicSite/ContentSelectionRetirementTest.php`)
- [x] **Step 2: Run; confirm it fails.** 4 of 5 failed on the right causes.
- [x] **Step 3: Delete the four owner routes and the service.** `pool:media` pins are the replacement curation lane. Model, policy and three form requests went with it; `ContentController` keeps library + uploads, authorising on a `SiteMedia` skeleton via `SitePolicy`.
- [x] **Step 4: Strip the keys from the payload builder.** `designMedia`, `siteImages`, `profile.gallery` and — necessarily — `profile.curatedGallery`, which was the service's second consumer.
- [x] **Step 5: Record in the manifest** which keys died and that `apps/pages` reads break by design. (`docs/wire-changes/2026-08-12-slice-7-teardown.md`)
- [x] **Step 6: Run** `./vendor/bin/pest --parallel` — 8308 passed / 2 skipped (baseline 8344; −36 accounted for in the commit body). PHPStan green.
- [x] **Step 7: Commit.**

### Task 17: Phase 4 gate — as Task 9.

---

## Phase 5 — Observers, policies, DSAR, analytics

### Task 18: DSAR re-sourced from `content.*`

**Files:**
- Modify: `app/Services/User/DataExport/DataExportPayloadBuilder.php:269,822,841`
- Test: `tests/Feature/User/DataExport/DataExportPayloadBuilderTest.php` (the existing services-export block, not a new file — `seedProForPayload()` and the lane's stand-in DDL live there, and `DuplicateStandInDdlGuardTest` forbids a second copy)

- [x] **Step 1: Failing test** — the export's `services` and `service_categories` sections populate from `content.*` with the legacy tables empty.
- [x] **Step 2: Run; confirm it fails.**
- [x] **Step 3: Repoint at `ManualServiceItems::exportRows()`** — it exists for exactly this.
- [x] **Step 4: RETAIN the legacy section keys.** The 2026-08-05 precedent is that DSAR allowlists keep them so previously-stored payloads stay disclosable. Deleting a key is a disclosure regression, not a cleanup.
- [x] **Step 5: Confirm `DsarPayloadFilter::WITHHELD_DISCLOSURE` still names both halves** of slice 6's `content.source_stats` rule (`summary_text` withheld, `rating_avg`/`rating_count` included).
- [x] **Step 6: Run; commit.**

**What the task turned out to be wider than.** The plan named only `exportRows()`, which covers the OWNER half. `streamServices()` had a SECOND half reading `site.services WHERE source IS NOT NULL` (the Fresha projection) plus a per-row `site.service_category_assignments` lookup, and `streamServiceCategories()` read `site.service_categories` outright. All three tables drop, so all three were re-sourced:

| Section | Before | After |
|---|---|---|
| `services` (owner) | `ManualServiceItems::exportRows()` | unchanged |
| `services` (connector) | `site.services` + `site.service_category_assignments` | `content.items` × `source_items` × `sources` (kind=`connection`) ⟕ `site.platform_connections`, facets via `ManualServiceItems::facets()`, categories via `content.collection_items` scoped to the source |
| `service_categories` | `site.service_categories` | `content.collections` kind=`service_category`, mapped label→title / position→sort_order / removed_at→deleted_at / is_user_created→source (the mapping `ServiceCategoryResource::fromCollectionRow()` already ships) |

Section keys are byte-identical before and after: `services`, `service_categories`.

**Three legacy columns have no `content.*` equivalent** and are emitted as their honest default rather than a guess: `sort_order` (null), `deleted_origin` (null), `is_active` (re-derived as "still held" — the old hidden-toggle bit lives in the connection payload's `selection`, disclosed in full in the `integrations` section).

**Open, for Phase 6 — `ManualServiceItems::legacyIdsFor()` still reads `site.services`.** It cross-checks each `manual:<uuid>` coord against a real `site.services` row before trusting it as a legacy id (that cross-check is load-bearing: `UserServiceController::store()` mints a syntactically identical coord with no backing row). Neither the plan nor spec §Unit I accounts for it, and after the DROP the query raises `42P01` and takes the WHOLE DSAR export down. It must be resolved in the same window as the DROP — not left to be discovered by a 500.

### Task 19: Analytics — verify, then document

Unit J is documentation **unless** the one verification fails.

- [x] **Step 1: Grep every analytics query for an inner join to `content.items`.**

```bash
grep -rn "item_views\|content_popularity" app/ --include=*.php | grep -i "join"
```

- [x] **Step 2: If any inner-joins**, write a failing test proving an orphaned analytics row drops or errors, then make the path null-safe. **That is code, not documentation.**
- [x] **Step 3: If none**, record the row count that will be orphaned and move on. Owner decision 2026-08-12: accepted as lost, no FK, orphans inert.
- [x] **Step 4: Commit** the count into the checkpoint draft.

**Verdict: no inner join exists. Unit J stays documentation.** The grep returns nothing, and following the readers confirms it rather than resting on the grep:

- `app/Services/Analytics/` contains **zero** references to any `content.*` table (`grep -rn "content\." app/Services/Analytics/` is empty). Same for `ComputeContentPopularityScores`, `PurgeRawAnalyticsEvents`, `DevInsightsController` and `AnalyticsController`.
- Of the 47 `->table('analytics.…')` calls in `app/`, none is followed by a join of any kind.
- Every rank consumer (`PoolResolver`, `IndividualProfilePayloadBuilder`, `ShopController`, `CustomLinksController`, `PublicMenuController`, `UserSiteActionsController`) goes through `ContentPopularityReader::forSite()`, which returns a `content_key → rank` **array** that call sites read as `$ranks[$key] ?? null`. An unmatched key is a miss, not a dropped row — and the reader is already fail-open on `QueryException`.
- No Postgres view or matview references either table (`information_schema.views` on dev: 0 rows).

**Rows orphaned by the DROPs, measured on dev `glncumufgaqcmqhzwrxm` 2026-08-16:**

| Table | Total | Orphaned by this slice | Breakdown |
|---|---|---|---|
| `analytics.item_views` | 560 | **197** | 182 `menu_item` → `site.menu_items`, 15 `service` → `site.services` |
| `analytics.content_popularity_scores` | 276 | **96** | 80 `menu_item`, 16 `service` |
| **Combined** | 836 | **293** | |

Two facts worth not rediscovering:

1. **Zero rows in either table reference a `content.items` id today** — every uuid-shaped `item_id` / `content_key` resolves to a legacy table, and the non-uuid remainder (359 `item_views`, e.g. every `shop_product`, `link_item`, `listen_item`, `watch_item`, `engine_item`) is keyed by handle/slug, not by id at all. So the "merged-away item" half of the owner's 2026-08-12 decision is not yet exercised on dev; what the DROP actually orphans is the legacy-id half.
2. `content_popularity_scores`' `block` (8) and `gallery_item` (6) uuid keys point at `site.blocks` / `site.site_media`, which this slice does **not** drop. They are not in the 96.

Beyond the id link, `analytics.item_views.item_title` denormalises each item's title at write time, so the orphaned rows stay human-readable in the DSAR export and in `DevInsightsController` regardless.

### Task 20: Retire the five observers

**Files:**
- Delete: `app/Observers/MenuItemObserver.php`, `app/Observers/Core/{Menu,Service,ServiceCategory,SiteMedia}Observer.php`
- Modify: `app/Providers/EventServiceProvider.php:39-45`, `app/Models/Core/Site/MenuItem.php:62`

**Event discovery is disabled in this codebase.** A replacement listener that is not explicitly registered is silently dead — this is the single highest-risk step in the phase.

- [ ] **Step 1: For each observer, enumerate its side-effects in a comment** — slug bookkeeping, cache invalidation, `BuildState` bumps — and name where each now lives.
- [ ] **Step 2: For any duty with no home, STOP and raise.** Do not invent one here.
- [ ] **Step 3: Write a failing test per re-homed duty**, asserting through the new path.
- [ ] **Step 4: Run; confirm they fail; implement; confirm they pass.**
- [ ] **Step 5: Delete the observers and their registrations.**
- [ ] **Step 6: Confirm `MirrorMediaAssetJob` still dispatches** — it fires off `ProjectionWriter`, not an observer, so it is not in the §9.4 list but must survive.
- [ ] **Step 7: Run the full suite; commit.**

### Task 21: Policies

- [ ] **Step 1: Run** `./vendor/bin/pest tests/Feature/Architecture/PolicyCoverageTest.php` — expect RED once the models go in Phase 6.
- [ ] **Step 2: Delete `ServicePolicy` and `ContentSelectionPolicy`** and their `Gate::policy()` registrations.
- [ ] **Step 3: Assert `ContentItemPolicy` is kind-agnostic for every kind now in play** — a test enumerating `PoolRegistry::POOLS`' kinds, not an assumption.
- [ ] **Step 4: Run; commit.**

### Task 22: Phase 5 gate — as Task 9.

---

## Phase 6 — The backup and the DROPs

Nothing here starts until Phases 1–5 are merged, deployed and live-verified on dev.

### Task 23: The backup gate

- [ ] **Step 1: `pg_dump` the ten tables** to the `partna-db-backup` R2 bucket.
- [ ] **Step 2: Verify the dump is readable** — restore it into a scratch schema, not just `ls` the object.
- [ ] **Step 3: Assert dumped row counts EXACTLY match live counts, per table.**
- [ ] **Step 4: If ONE table disagrees, NOTHING is dropped.** Stop and raise.
- [ ] **Step 5: Record the bucket path and the per-table counts** in the checkpoint.

### Task 24: Re-home `ShopContentWriter` off the `ShopBrand` model

**Files:**
- Modify: `app/Services/Shop/ShopContentWriter.php:33-96,244,408`, `app/Http/Controllers/Api/Platforms/ShopController.php:317,869,929`

`site.shop_brands` is a live write target, not inert. `upsertStore()` takes the model as its identity anchor, so dropping the table under it breaks every subsequent shop write.

- [ ] **Step 1: Failing test** — `upsertStore()` writes with no `site.shop_brands` row present.
- [ ] **Step 2–4:** run, implement (identity anchor becomes `content.storefronts.brand_id` + the provider store id), run.
- [ ] **Step 5: Remove `ShopController`'s three direct writes.**
- [ ] **Step 6: Commit.**

### Task 25: `CloudflarePurgeService::purgeHandle()`

- [ ] **Step 1: Delete the legacy third of the dish lookup.** The menu third already reads both lanes and is wrapped in `EscalatesRepeatedFaults`, so the DROP does not trip it.
- [ ] **Step 2: Repoint the product and event lookups.**
- [ ] **Step 3: Wrap their two remaining raw `report($e)` calls in `EscalatesRepeatedFaults`.** Un-deduped, one site save is up to 8 Nightwatch reports and a save-storm reads as an outage.
- [ ] **Step 4: Take the docblock's URL-volume derivation from 900 back to 600 per host** (§23.7).
- [ ] **Step 5: Test, commit.**

### Task 26a: BLOCKER — `site.public_site_payload` is a VIEW over three drop-list tables

**Found 2026-08-17 by running the `pg_depend` check early. Named by neither the
spec nor this plan — it is exactly what the kickoff meant by "pg_depend will
tell you what the table list will not."**

`site.public_site_payload` is a live VIEW (`pg_class.relkind = 'v'`) whose
`services` key selects from **`site.services`, `site.service_category_assignments`
and `site.service_categories`** — all three on the drop list:

```sql
'services', COALESCE((SELECT jsonb_agg(jsonb_build_object(
     'id', sv.id, 'title', sv.title, …, 'category', COALESCE(sc.title,'Services')))
   FROM site.services sv
     LEFT JOIN LATERAL (SELECT c.title, c.sort_order
        FROM site.service_category_assignments a
        JOIN site.service_categories c ON c.id = a.service_category_id AND c.deleted_at IS NULL
        WHERE a.service_id = sv.id ORDER BY c.sort_order, lower(c.title) LIMIT 1) sc ON true
   WHERE sv.user_id = p.id AND sv.source IS NULL AND sv.is_active = true
     AND sv.deleted_at IS NULL), '[]'::jsonb)
```

`sv.source IS NULL` — so it publishes the **owner-authored** services only (18
live on dev); the Fresha half is already excluded.

**Why this is not a footnote.** The view is backed by
`app/Models/Views/PublicSitePayload.php` and read by `SyncSubdomainToKvJob`,
which CLAUDE.md names as the **ONLY writer of `SUBDOMAIN_KV`** — the payload the
Cloudflare Worker serves for every `<handle>.partna.au` sitepage. So this is not
an API key; it is the live render payload.

A bare `DROP TABLE site.services` will **fail** on the view dependency. A
`DROP … CASCADE` will **silently drop the view**, taking `PublicSitePayload` and
every KV write with it.

- [ ] **Step 1: Recreate the view with `services` sourced from `content.*`**, as
      its own migration, BEFORE any DROP. Keep the emitted JSON shape identical —
      the owner services live authoritatively in `content.items` kind `service` on
      the manual source (slice 3a), with categories as `content.collections` kind
      `service_category`, so the data is all present and the coverage gate is green
      for it.
- [ ] **Step 2: Do NOT simply retire the `services` key.** That would drop
      services off the rendered sitepage, not merely off an API response, and the
      2026-08-14 frontend-rebuild override covers the API wire — it was not a
      ruling about the KV render payload.
- [ ] **Step 3: Re-check the same view for the OTHER drop-list tables** before
      dropping them. It also builds `gallery` from `site.site_media` pool
      `gallery`; that table survives the teardown, but unit 6 retires the pool
      conceptually, so state whether the key stays.
- [ ] **Step 4: Verify no OTHER view, matview, rule or trigger depends on the ten**
      — the FK check came back clean (zero inbound foreign keys from outside the
      drop set), so this view was the only surprise, but re-run both queries at
      DROP time rather than trusting this record.

### Task 26: Check `pg_depend` before writing a single DROP

- [ ] **Step 1:** For each of the ten tables, query `pg_depend` for FK dependents, views, triggers and RLS policies. The table list will not tell you what the catalog will.
- [ ] **Step 2: Record the findings.** Anything unexpected is a stop, not an improvisation.

### Task 27: The DROP migrations

Children before parents, one concern per file:

```
20260817000100_drop_site_menu_item_categories.sql
20260817000200_drop_site_menu_item_platforms.sql
20260817000300_drop_site_menu_items.sql
20260817000400_drop_site_menu_categories.sql
20260817000500_drop_site_service_category_assignments.sql
20260817000600_drop_site_services.sql
20260817000700_drop_site_service_categories.sql
20260817000800_drop_site_shop_products.sql
20260817000900_drop_site_shop_brands.sql
20260817001000_drop_site_content_selection.sql
20260817001100_delete_legacy_event_item_slugs.sql
```

- [ ] **Step 1: Write them.**
- [ ] **Step 2: `supabase link --project-ref glncumufgaqcmqhzwrxm` then `db push --dry-run`.**
- [ ] **Step 3: Apply to dev only.** Never the prod ref.
- [ ] **Step 4: Fix `ManualServiceItems::legacyIdsFor()` — found by Track E 2026-08-16, named by neither the spec nor this plan.**

  `app/Services/Content/ManualServiceItems.php:444` queries `site.services`
  directly, to confirm that a `manual:{uuid}` coord's uuid is a REAL legacy id
  before trusting it — load-bearing, because `UserServiceController::store()`
  mints a syntactically identical coord with no backing row. After the DROP that
  query raises `42P01` and takes the **entire DSAR export** down, not just the
  services section. Must be resolved in this same window. Options: drop the
  cross-check and accept coord-shape as sufficient, or record a
  `legacy_id` facet at backfill time. Decide deliberately — the cross-check
  exists to stop a fabricated legacy id reaching a disclosure payload.

- [ ] **Step 5: Delete the ten models, the four migration services and their commands, and `BackfillClaimedGoogleBusinessReviewsCommand`** (owner ruling 2026-08-14).
- [ ] **Step 5: Run the full suite, `composer test:pg`, `composer test:schema`, PHPStan, Pint.**
- [ ] **Step 6: Commit.**

### Task 28: Close the slice

- [ ] **Step 1: Re-run every coverage assertion** against post-DROP dev and paste into the parent-spec checkpoint. Do NOT cite the 2026-08-16 gate report — invariant #5 applies to it too.
- [ ] **Step 2: Finish the wire manifest** — every key that died across all six phases.
- [ ] **Step 3: `cloud env:logs partna development --minutes 10` + Nightwatch scan.**
- [ ] **Step 4: Write the prod-reconciliation follow-up** under `docs/superpowers/plans/` — the migration gap, the access question, the ordering. This is the slice's final deliverable.
- [ ] **Step 5: Amend `docs/2026-08-05-platforms-as-sources.md`**, whose closing line still reads *"The program is complete"* (parent §11). That sentence has already mis-scoped downstream work once.
- [ ] **Step 6: Write the programme's closing checkpoint** — what shipped, what was dropped, what was deliberately left behind, **and that production still carries the legacy schema.**

---

## Self-review notes

**Spec coverage.** Units A→Tasks 5–9; B→10; C→11; D→14–15; E→16; F→12; G→20; H→21; I→18; J→19; K→23–27. Spec §5 backup→23; §6 cache→Task 6 Step 7 and each phase gate; §7 verification→every gate task; §9 open questions carried below.

**Open questions from spec §9, unresolved and non-blocking.** Task 6 Step 1 is where question 1 (does the rebuild override cover the dashboard?) actually bites — if the answer is "yes, dashboard too", Tasks 5 and 6 shed their compatibility shim and shrink. Questions 2 (`anseo-studio`'s unmatched Fresha URL) and 3 (the fourth pin path on exclusion-only pools) do not gate any task here; carry them into the closing checkpoint.

**Known gap.** Task 6 Step 1 asks the implementer to *decide* four behaviour homes rather than prescribing them. That is deliberate — `is_manual` and `suppressed_items` interact with `MenuFetchJob`'s rebuild in ways that depend on Task 7's final shape, and prescribing them here would be a guess presented as a spec. It is the one place in this plan where a step names a decision instead of an edit.
