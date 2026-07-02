# SmartLinks Removal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the SmartLinks feature entirely (backend code + database) — superseded by Platform Integrations — while preserving the shared HTTP primitives that the Integrations scrapers depend on.

**Architecture:** Backend-first **tolerant window**. The shared primitives (`SafeUrlFetcher` et al.) relocate to a neutral `App\Services\Http` namespace; all SmartLinks feature code is deleted; `site.smart_links` is dropped. The 7 dashboard endpoints and the public-payload `smart_links` key survive Phase 1 as empty-response shims so the live frontend never breaks regardless of deploy order. Phase 2 (separate, after the frontend ships) deletes the shims.

**Tech Stack:** PHP 8.2 / Laravel 12, Pest 4 (SQLite in-memory), Supabase Postgres migrations (raw SQL).

## Global Constraints

- **Branch:** base off **current `development`** (which now includes the merged Plan 6 platform-integrations work). The design spec already lives on `development` at commit `531fc011`. **Branch setup is BLOCKED until Josh confirms the concurrent platform-integrations session is idle** — do not create/checkout branches before then.
- **No Laravel migrations** — DB changes go in `supabase/migrations/` as raw SQL (`composer guard:no-laravel-migrations` enforces this).
- **Run the full suite in the main checkout, not a worktree** — Pest feature tests fail spuriously in `.claude/worktrees/`.
- **Style:** run `php artisan pint` on changed files before each commit; keep commits surgical (don't churn the pint baseline of unrelated files).
- **Verify before done:** `composer test` green; `php artisan pint`; guard + coverage sweeps pass.
- **Supabase apply is gated:** `supabase db push` to dev requires an interactive `supabase link` (Josh runs it with the `!` prefix); always show `--dry-run` first.

---

## File Structure

**Relocate (move + renamespace to `App\Services\Http`):**
- `app/Services/Http/SafeUrlFetcher.php` (from `Services/SmartLinks/`)
- `app/Services/Http/SafeUrlException.php`
- `app/Services/Http/MetadataParser.php`
- `app/Services/Http/ParsedUrl.php`
- `app/Services/Http/ParsedMetadata.php`
- `tests/Unit/Http/SafeUrlFetcherTest.php` (from `tests/Unit/SmartLinks/`)

**Modify:**
- `app/Services/PublicSite/IndividualProfilePayloadBuilder.php` — `smart_links` → `[]`
- `app/Http/Controllers/Api/User/SiteManagement/UserSmartLinkController.php` — reduce to tolerant shim
- `app/Providers/EventServiceProvider.php` — drop observer registration
- `routes/console.php` — drop the `smartlinks:refresh` schedule
- `tests/Pest.php` — drop the `site.smart_links` SQLite seed
- ~20 `app/Services/Platforms/*` + `FreshaController.php` + Platforms tests — import-path swap (mechanical, grep-driven)

**Delete:**
- `app/Services/SmartLinks/` (entire directory after relocation: Resolver, Refresher, Validator, TypeRegistry, ImageService, VisitorUrl, ResolvedSmartLink, ResolvedSmartLinkData, UrlNormalizer, `Extractors/`)
- `app/Models/Core/Site/SmartLink.php`, `app/Observers/Core/SmartLinkObserver.php`
- `app/Http/Resources/SmartLinkResource.php`
- `app/Http/Requests/Api/User/Site/{Preview,Store,Update}SmartLinkRequest.php`, `ReorderSmartLinksRequest.php`
- `app/Console/Commands/RefreshSmartLinksCommand.php`
- `tests/Unit/SmartLinks/{SmartLinkTypeRegistryTest,UrlNormalizerTest,SmartLinkVisitorUrlTest,SmartLinkValidatorTest}.php`
- `tests/Feature/SmartLinks/SmartLinkResolverTest.php`, `tests/Feature/Observers/SmartLinkObserverBustTest.php`

**Create:**
- `supabase/migrations/20260629000000_drop_smart_links.sql`
- `tests/Feature/SmartLinks/SmartLinkTolerantShimTest.php` (Phase-1 shim behavior)

---

## Task 1: Relocate shared primitives to `App\Services\Http`

Behaviour-preserving move of the 5 shared classes out of `SmartLinks/`. Verified: they reference no SmartLinks feature class, and there are no container/config bindings — pure path swap.

**Files:**
- Move: the 5 files above + the test
- Modify: every importer (grep-driven)

**Interfaces:**
- Produces: `App\Services\Http\SafeUrlFetcher`, `App\Services\Http\SafeUrlException`, `App\Services\Http\MetadataParser`, `App\Services\Http\ParsedUrl`, `App\Services\Http\ParsedMetadata` (same public API as before; only the namespace changes).

- [ ] **Step 1: Move the 5 classes + test (git mv preserves history)**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
mkdir -p app/Services/Http tests/Unit/Http
for f in SafeUrlFetcher SafeUrlException MetadataParser ParsedUrl ParsedMetadata; do
  git mv "app/Services/SmartLinks/$f.php" "app/Services/Http/$f.php"
done
git mv tests/Unit/SmartLinks/SafeUrlFetcherTest.php tests/Unit/Http/SafeUrlFetcherTest.php
```

- [ ] **Step 2: Renamespace the 5 moved files**

```bash
perl -pi -e 's/^namespace App\\Services\\SmartLinks;/namespace App\\Services\\Http;/' \
  app/Services/Http/SafeUrlFetcher.php app/Services/Http/SafeUrlException.php \
  app/Services/Http/MetadataParser.php app/Services/Http/ParsedUrl.php app/Services/Http/ParsedMetadata.php
```

- [ ] **Step 3: Rewrite every importer's `use` path (app + tests)**

```bash
grep -rlE 'App\\Services\\SmartLinks\\(SafeUrlFetcher|SafeUrlException|MetadataParser|ParsedUrl|ParsedMetadata)' app tests \
  | xargs perl -pi -e 's/App\\Services\\SmartLinks\\(SafeUrlFetcher|SafeUrlException|MetadataParser|ParsedUrl|ParsedMetadata)/App\\Services\\Http\\$1/g'
```

- [ ] **Step 4: Verify no stale references to the moved classes remain**

Run:
```bash
grep -rE 'App\\Services\\SmartLinks\\(SafeUrlFetcher|SafeUrlException|MetadataParser|ParsedUrl|ParsedMetadata)' app tests
```
Expected: **no output** (all importers updated).

- [ ] **Step 5: Run the relocated test + the Platforms suite**

Run: `composer test -- --filter='SafeUrlFetcher|Scraper|MediaApis|Platforms'`
Expected: PASS (the relocated primitives behave identically under the Platforms scrapers).

- [ ] **Step 6: Pint + commit**

```bash
php artisan pint app/Services/Http tests/Unit/Http
git add -A
git commit -m "refactor(http): relocate shared URL primitives out of SmartLinks into App\\Services\\Http"
```

---

## Task 2: Neutralize the public-payload `smart_links` key

The public profile payload keeps the `smart_links` key (so partna-pages doesn't break on a missing field) but emits a constant empty array — decoupling it from the `SmartLink` model ahead of deletion.

**Files:**
- Modify: `app/Services/PublicSite/IndividualProfilePayloadBuilder.php`
- Test: `tests/Feature/PublicSite/` (add/extend the profile-payload test)

**Interfaces:**
- Consumes: nothing new.
- Produces: public profile payload key `smart_links` is always `[]`.

- [ ] **Step 1: Write the failing test**

Find the existing public-profile payload test (e.g. `grep -rl "profiles/{handle}\|IndividualProfilePayloadBuilder" tests/Feature`). Add:

```php
it('emits an empty smart_links array on the public profile payload', function () {
    // Arrange: a published site for a user (reuse the file's existing site factory/helper).
    $payload = app(\App\Services\PublicSite\IndividualProfilePayloadBuilder::class)->build($site);

    expect($payload)->toHaveKey('smart_links');
    expect($payload['smart_links'])->toBe([]);
});
```

- [ ] **Step 2: Run it — expect FAIL only if the builder still references the model**

Run: `composer test -- --filter='empty smart_links array'`
Expected: the test compiles; it may pass already (no live rows) — that's fine. Its purpose is to lock the contract before the model is deleted in Task 5.

- [ ] **Step 3: Replace `buildSmartLinks` with a constant**

In `IndividualProfilePayloadBuilder.php`:
- Change the payload line to `'smart_links' => [],`.
- Delete the `buildSmartLinks()`, `shapeSmartLink()`, and `smartLinkPrice()` methods.
- Delete the imports `use App\Models\Core\Site\SmartLink;` and `use App\Services\SmartLinks\SmartLinkVisitorUrl;`.

- [ ] **Step 4: Run the test + the public-site suite**

Run: `composer test -- --filter='PublicSite|Profile|smart_links'`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
php artisan pint app/Services/PublicSite/IndividualProfilePayloadBuilder.php
git add -A
git commit -m "refactor(public-site): emit empty smart_links payload; decouple from SmartLink model"
```

---

## Task 3: Convert the dashboard endpoints to a tolerant shim

The 7 `/smart-links` endpoints stay registered but return empty success shapes matching the frontend's expected types, with zero dependency on deleted code or the table.

**Files:**
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserSmartLinkController.php`
- Test: `tests/Feature/SmartLinks/SmartLinkTolerantShimTest.php` (create)

**Interfaces:**
- Produces: `GET /smart-links` → `200 {"smart_links": []}`; `preview`/`store`/`update`/`reorder`/`refresh` → `200 {"smart_link": null}`; `destroy` → `204`.

- [ ] **Step 1: Write the failing test**

First read an existing authenticated user-route feature test to copy the auth/actingAs bootstrap (e.g. `grep -rl "professional\|actingAs\|Sanctum\|withToken" tests/Feature | head`). Then create `tests/Feature/SmartLinks/SmartLinkTolerantShimTest.php`:

```php
<?php

use App\Models\Core\User\User;

// Reuse the file-local helper that authenticates a user for /api/professional routes.
beforeEach(function () {
    $this->user = User::factory()->create();          // mirror the existing user-route tests
    // authenticate $this->user exactly as the existing user-route feature tests do
});

it('GET /smart-links returns an empty list', function () {
    $this->getJson('/api/professional/smart-links')   // confirm the exact prefix in routes/api/user.php
        ->assertOk()
        ->assertExactJson(['smart_links' => []]);
});

it('POST /smart-links is a tolerant no-op', function () {
    $this->postJson('/api/professional/smart-links', ['url' => 'https://example.com'])
        ->assertOk()
        ->assertJson(['smart_link' => null]);
});

it('DELETE /smart-links/{id} returns 204', function () {
    $this->deleteJson('/api/professional/smart-links/'.\Illuminate\Support\Str::uuid())
        ->assertNoContent();
});
```

- [ ] **Step 2: Run it — expect FAIL**

Run: `composer test -- tests/Feature/SmartLinks/SmartLinkTolerantShimTest.php`
Expected: FAIL (current controller hits the deleted-soon services / the `SmartLink` model binding).

- [ ] **Step 3: Replace the controller with the shim**

Rewrite `UserSmartLinkController.php` to:
- Remove all `use App\Services\SmartLinks\…`, `use App\Http\Requests\Api\User\Site\…SmartLink…`, `use App\Http\Resources\SmartLinkResource`, `use App\Models\Core\Site\SmartLink` imports.
- Remove the constructor (drop the injected `SmartLinkResolver`/`SmartLinkImageService`/`SmartLinkRefresher`).
- Type method params as `Illuminate\Http\Request` (not the deleted Form Requests) and string `$smartLink` (not the deleted model).

```php
<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;

// Tolerant shim: SmartLinks is removed; these endpoints stay temporarily so the
// dashboard never 404/500s during the frontend cutover. Phase 2 deletes them.
class UserSmartLinkController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->success(['smart_links' => []]);
    }

    public function preview(): JsonResponse
    {
        return $this->success(['smart_link' => null]);
    }

    public function store(): JsonResponse
    {
        return $this->success(['smart_link' => null]);
    }

    public function update(string $smartLink): JsonResponse
    {
        return $this->success(['smart_link' => null]);
    }

    public function reorder(): JsonResponse
    {
        return $this->success(['smart_link' => null]);
    }

    public function refresh(string $smartLink): JsonResponse
    {
        return $this->success(['smart_link' => null]);
    }

    public function destroy(string $smartLink): \Illuminate\Http\Response
    {
        return response()->noContent();
    }
}
```

> Note: confirm `ApiController::success()` wraps the array as the top-level JSON body (matching `assertExactJson`). If `success()` nests under a `data` key, adjust the test assertions to match the existing convention.

- [ ] **Step 4: Run the test — expect PASS**

Run: `composer test -- tests/Feature/SmartLinks/SmartLinkTolerantShimTest.php`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
php artisan pint app/Http/Controllers/Api/User/SiteManagement/UserSmartLinkController.php tests/Feature/SmartLinks/SmartLinkTolerantShimTest.php
git add -A
git commit -m "refactor(smart-links): reduce dashboard endpoints to tolerant empty-response shim"
```

---

## Task 4: Remove the refresh cron + command

Nothing depends on the SmartLinks refresh once the payload and endpoints are neutralized.

**Files:**
- Modify: `routes/console.php`
- Delete: `app/Console/Commands/RefreshSmartLinksCommand.php`

- [ ] **Step 1: Delete the command + its schedule**

In `routes/console.php`, delete the `Schedule::command('smartlinks:refresh')…` block **and its preceding comment** (the `// Smart-link snapshot refresh …` lines). Then:

```bash
git rm app/Console/Commands/RefreshSmartLinksCommand.php
```

- [ ] **Step 2: Verify the command is gone**

Run: `php artisan list | grep -i smartlink || echo "no smartlink commands"`
Expected: `no smartlink commands`.

- [ ] **Step 3: Run the suite**

Run: `composer test`
Expected: PASS (no test references the command; if one does, it is deleted in Task 5).

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore(smart-links): remove refresh command + scheduled task"
```

---

## Task 5: Delete all remaining SmartLinks feature code

At this point nothing references the feature services, model, observer, Form Requests, or resource. Delete them and their tests, drop the test-schema seed, and remove the now-empty directory.

**Files:** see the **Delete** list in File Structure; plus `app/Providers/EventServiceProvider.php` and `tests/Pest.php` modifications.

- [ ] **Step 1: Deregister the observer**

In `app/Providers/EventServiceProvider.php` delete the three lines: `use App\Models\Core\Site\SmartLink;`, `use App\Observers\Core\SmartLinkObserver;`, and `SmartLink::observe(SmartLinkObserver::class);`.

- [ ] **Step 2: Remove the `smart_links` SQLite seed from the test harness**

In `tests/Pest.php`, delete the `CREATE TABLE IF NOT EXISTS site.smart_links (…)` block (and its leading comment near line 414).

- [ ] **Step 3: Delete the feature code, model, observer, requests, resource, command-orphan tests**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
git rm -r app/Services/SmartLinks
git rm app/Models/Core/Site/SmartLink.php app/Observers/Core/SmartLinkObserver.php
git rm app/Http/Resources/SmartLinkResource.php
git rm app/Http/Requests/Api/User/Site/PreviewSmartLinkRequest.php \
       app/Http/Requests/Api/User/Site/StoreSmartLinkRequest.php \
       app/Http/Requests/Api/User/Site/UpdateSmartLinkRequest.php \
       app/Http/Requests/Api/User/Site/ReorderSmartLinksRequest.php
git rm tests/Unit/SmartLinks/SmartLinkTypeRegistryTest.php \
       tests/Unit/SmartLinks/UrlNormalizerTest.php \
       tests/Unit/SmartLinks/SmartLinkVisitorUrlTest.php \
       tests/Unit/SmartLinks/SmartLinkValidatorTest.php \
       tests/Feature/SmartLinks/SmartLinkResolverTest.php \
       tests/Feature/Observers/SmartLinkObserverBustTest.php
```

- [ ] **Step 4: Verify no dangling references**

Run:
```bash
grep -rn 'App\\Services\\SmartLinks\|App\\Models\\Core\\Site\\SmartLink\|SmartLinkObserver\|SmartLinkResource\|SmartLinkRequest' app tests routes
grep -rn 'SmartLink' app/Models app/Observers app/Services
```
Expected: **no output** (the only surviving `SmartLink` token is the `UserSmartLinkController` shim class name + its route registration).

- [ ] **Step 5: Run the full suite**

Run: `composer test`
Expected: PASS. Confirm `PolicyCoverageTest` passes (the `SmartLink` model is gone — ensure no `POLICY_EXEMPT` allowlist entry or `Gate::policy` registration still names it; remove any such entry if present).

- [ ] **Step 6: Pint + commit**

```bash
php artisan pint app/Providers/EventServiceProvider.php tests/Pest.php
git add -A
git commit -m "feat(smart-links): delete SmartLinks feature code, model, observer, requests, resource + tests"
```

---

## Task 6: Drop the `site.smart_links` table

**Files:**
- Create: `supabase/migrations/20260629000000_drop_smart_links.sql`

- [ ] **Step 1: Write the migration**

Create `supabase/migrations/20260629000000_drop_smart_links.sql` (verify the timestamp is later than the newest existing migration; bump if needed):

```sql
-- 20260629000000_drop_smart_links.sql
--
-- Remove the SmartLinks feature (superseded by Platform Integrations /
-- site.platform_connections). CASCADE drops the dedicated trigger
-- (set_timestamp_smart_links), RLS policy (smart_links_app_backend_all),
-- indexes, and inline FK/CHECK constraints. The shared public.set_updated_at()
-- function is intentionally NOT dropped — site.platform_connections (and others)
-- reuse it. IF EXISTS makes this a safe no-op on prod (which lacks the table).
DROP TABLE IF EXISTS site.smart_links CASCADE;
```

- [ ] **Step 2: Confirm the guard accepts it (no Laravel migration created)**

Run: `composer test -- --filter='guard\|Migration'` (or `composer guard:no-laravel-migrations`)
Expected: PASS.

- [ ] **Step 3: Dry-run against dev (Josh runs the interactive link)**

Josh: `! supabase link --project-ref glncumufgaqcmqhzwrxm`
Then:
```bash
supabase db push --dry-run
```
Expected dry-run output: the new `20260629000000_drop_smart_links.sql` listed as pending.

- [ ] **Step 4: Apply to dev**

Run: `supabase db push`
Verify:
```bash
# via Supabase MCP execute_sql against glncumufgaqcmqhzwrxm:
select to_regclass('site.smart_links');   -- expect NULL (table gone)
select 1 from pg_proc where proname = 'set_updated_at';  -- expect a row (shared fn preserved)
```

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations/20260629000000_drop_smart_links.sql
git commit -m "feat(db): drop site.smart_links — SmartLinks superseded by Platform Integrations"
```

---

## Phase 2 (DOCUMENTED — do NOT execute until the frontend has shipped)

After the frontend removes the Links page and stops calling `/smart-links` / reading the `smart_links` payload key:
- Delete the `UserSmartLinkController` shim + its import + the 7 routes in `routes/api/user.php` (lines ~152–162).
- Remove the `smart_links` key from `IndividualProfilePayloadBuilder` entirely.
- Delete `tests/Feature/SmartLinks/SmartLinkTolerantShimTest.php`.

## Frontend (DOCUMENTED — separate repo `partna-frontend`, not executed here)

Remove: `app/(app)/account/(dashboard)/links/` (page + `smart-link-card`, `smart-link-editor-modal`, `smart-links-section`, `use-smart-links`, `smart-links.module.css`); `lib/smart-links/` (`api.ts`, `types.ts`); the `lib/routes.ts` Links entry + nav item; any `smart_links` rendering in public/profile components. Optional: drop the "distinct from Smart Links" comment in `integrations/custom-links-section.tsx`.

**Deploy order:** BE Phase 1 (this plan) → FE removal → BE Phase 2.

---

## Self-Review

- **Spec coverage:** relocate (T1) ✓; public payload (T2) ✓; tolerant shim (T3) ✓; cron+command (T4) ✓; feature/model/observer/requests/resource/tests + Pest seed (T5) ✓; DB drop (T6) ✓; Phase 2 + FE documented ✓. SitePolicy/config — confirmed no changes needed (no smart-link methods/keys). ✓
- **Placeholder scan:** the only "look at the existing test" pointers are in T2/T3 for the auth bootstrap, which the executor cannot get wrong by reading one named file; all code-bearing steps include real code. ✓
- **Type consistency:** shim returns `{smart_links: []}` / `{smart_link: null}` consistently across T3 test and controller; payload key `smart_links` consistent across T2/T5/Phase 2. ✓
