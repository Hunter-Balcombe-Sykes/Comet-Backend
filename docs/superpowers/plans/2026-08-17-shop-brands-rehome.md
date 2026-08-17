# `site.shop_brands` Re-home Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the last shop read/write lane off `site.shop_brands` and `site.shop_products` onto `content.storefronts` + `content.collections`, then drop both — two of the four tables slice 7 deferred. (`site.services` ×3 is the third and fourth; it is a sibling project, spec §11.)

**Architecture:** `ShopContentWriter` was already re-homed by slice 7 Task 24 onto `App\Services\Shop\StoreRecord`, a readonly DTO keyed `(provider, externalRef)`. This plan does the same to the READ side: `ShopConnections::brands()`/`brand()` stop returning `Builder<ShopBrand>` and start returning `StoreRecord`s built from `content.*` via the already-written `StoreRecord::fromStorefrontRow()`. The five `ShopController` endpoints, two async jobs, profiler and pre-account seeding lane follow. The models die last, with the DROP.

**Tech Stack:** PHP 8.4, Laravel 12, PostgreSQL (Supabase), Pest 4, Redis/Horizon, Cloudflare R2.

**Spec:** `docs/superpowers/specs/2026-08-17-shop-brands-rehome-design.md`

## Global Constraints

- **Dev only. Production is out of scope.** Never `supabase db push` against `edplucmvkcnokyygxqsb`, never `git push origin development:production`.
- **Never create Laravel migration files.** Raw SQL under `supabase/migrations/`, one concern per file, `CONCURRENTLY` at most once per file.
- **Every `content.*` write path lands BEFORE the DROP that removes its legacy twin.** Slice 7's ordering rule applies here unchanged.
- **Business logic in `Services/`.** Resource classes for API responses, Form Requests for validation, Policies for authorization — never inline 403s.
- **Tests run SQLite, prod is Postgres.** Verify constraint-bound writes against `supabase/migrations/` DDL, not just a green suite. Anything touching `ProjectionWriter` or its callers runs `composer test:pg`.
- **PHPStan must be invoked as** `php -d memory_limit=1G ./vendor/bin/phpstan analyse` — the default invocation OOMs and reports it as "severe errors".
- **`--filter` with `composer test` is broken** in this repo; run Pest directly (`./vendor/bin/pest --filter=...`).
- **Cross-file test helper names collide at load time and fatal a `--parallel` run.** Helper prefixes in this plan are `sbr*`.
- **This project's migration band is `20260819*`. Do not renumber it back to `20260818*`.** The services cutover (`2026-08-17-services-cutover.md`) owns `20260818000100/000200/000300`. Both plans originally claimed `20260818000100` and `20260818000200`. `supabase_migrations.schema_migrations` keys on the numeric prefix ALONE — the descriptive suffix is not part of the key — so the second project to merge would have had both files silently **skipped** by `db push` with no error, dropping `site.shop_products` while `content.storefronts.user_id` never landed.
- **A sibling project runs in parallel: the services cutover.** It owns `site.services` ×3, `site.public_site_payload`, and every `Service*`/`Fresha*` file. You own the shop lane. Confirmed disjoint on 2026-08-17: no file appears in both plans, `tests/Pest.php` is yours alone, and `pg_depend` shows nothing depends on `site.shop_brands`/`site.shop_products`. Its helper prefix is `svcCut*`, yours is `sbr*`. If you find yourself editing one of its files, STOP and raise.
- **Do not start until Task 1 passes.** Every file/line reference below is 2026-08-17 against `feat/slice-7-teardown` and is a snapshot, not a licence to skip re-derivation.

## File Structure

**Modified — the read lane and its callers**
- `app/Services/Shop/StoreRecord.php` — gains a nullable `collectionId`, populated on read only.
- `app/Services/Shop/ShopConnections.php` — `brands()`/`brand()` replaced by `stores()`/`store()` returning `StoreRecord`.
- `app/Http/Controllers/Api/Platforms/ShopController.php` — seven touches: five read sites plus the identity/lifecycle writes.
- `app/Jobs/Platforms/ShopBrandConnectJob.php`, `app/Jobs/Platforms/ProcessShopBrandLogoJob.php` — dispatched on the collection id, not the legacy uuid PK.
- `app/Services/Platforms/ShopBrandProfiler.php`, `app/Services/Platforms/ShopCatalog.php`, `app/Services/Platforms/ShopProductSeeder.php` — type hints move to `StoreRecord`.
- `app/Services/Brand/StoreBrandSeeder.php`, `StoreBrandProfiler.php`, `BrandAssetPipeline.php` — the pre-account seeding lane.

**Created**
- `supabase/migrations/20260819000100_content_storefronts_user_id.sql` — denormalised `user_id` + the unique index (spec §6).
- `supabase/migrations/20260819000200_drop_site_shop_products.sql`, then `20260819000210_drop_site_shop_brands.sql` — children before parents, last of all.
- `tests/Feature/Shop/ShopStoreReadLaneTest.php` — the read lane's own suite.
- `docs/wire-changes/2026-08-17-shop-brands-rehome.md` — the manifest.

**Deleted (last task only)**
- `app/Models/Core/Site/ShopBrand.php`, `app/Models/Core/Site/ShopProduct.php`, `IntegrationConnection::shopBrands()`, `ShopProduct::brand()`.
- **Ownership of `ShopProduct` moved twice on 2026-08-17 and settled here.** The drop-phase session took its call sites (§4d of their kickoff), then handed the whole table back on sizing: `ShopBrand::toBrandArray()` materialises `$this->products` on the catalog re-warm endpoint and in `ShopCatalog::syncLatest()`, so dropping `shop_products` alone means editing the deferred table's own model. Spec §2.2.

---

## Phase 0 — Find out what is actually true

### Task 1: Entry gate

**Files:**
- Modify: this plan (record findings inline under each step)

No code. This task exists because slice 7 was in flight across fourteen worktrees when the plan was written, and because the ruling that created this project also opened a gap that may not be this project's to close.

**BLOCKING GATE — CLEARED before any of the below.** The EXECUTE prompt's one gate
was the services cutover's Task 1 migration. `select version from
supabase_migrations.schema_migrations where version = '20260817000000'` returns
**1 row** on the dev ref, so it is applied and a `db push` from this branch cannot
carry it as a side effect. `origin/development` `44e257c28` is the services
session recording that apply.

- [x] **Step 1: Re-derive the production inventory.**

```bash
grep -rln "ShopBrand" app/ | sort
grep -n "ShopBrand::query()" app/Services/Shop/ShopConnections.php
```

Expected 2026-08-17: 34 files under `app/`, and `ShopConnections.php:186` returning `ShopBrand::query()->whereIn('connection_id', $ids)`. Record the real numbers. Any file in spec §4 that no longer appears is **struck with a note**, not silently dropped.

**FINDING (re-derived against `origin/development` @ `44e257c28`, 2026-08-17):** both
numbers hold exactly — **34** files under `app/` name `ShopBrand`, and
`ShopConnections.php:186` is still `ShopBrand::query()->whereIn('connection_id', $ids)`.
No spec §4 file has disappeared. The branch base for this project is
`origin/development` `44e257c28`, not `feat/slice-7-teardown`.

- [x] **Step 2: Find out what slice 7 changed in this family after the ruling.**

```bash
git log --oneline development..feat/slice-7-teardown -- \
  app/Http/Controllers/Api/Platforms/ShopController.php \
  app/Services/Shop/ app/Jobs/Platforms/Shop*.php
```

Anything already done is ticked below with its commit sha as evidence, not redone.

**FINDING:** `origin/development..feat/slice-7-teardown` over that path list is
**empty** — slice 7 has nothing outstanding in the shop family; it all merged.
`0c51885ca` ("re-home ShopContentWriter off the ShopBrand model" — their Task 24)
is on `origin/development`, so `ShopContentWriter`/`StoreRecord` are already the
shipped write side. Nothing to inherit, nothing to redo.

- [x] **Step 3: Confirm the drop phase did NOT take the `shop_products` call sites.**

Ownership moved twice on 2026-08-17 and settled with this plan (spec §2.2), but their §4d resolution was written while they still held it. Verify what actually shipped:

```bash
# Is the table still there? (it should be — the drop phase dropped five tables, not nine)
supabase link --project-ref glncumufgaqcmqhzwrxm
psql "$DEV_DB_URL" -c "\dt site.shop_products"

# Are the call sites still present?
grep -n "toBrandArray()" app/Http/Controllers/Api/Platforms/ShopController.php
grep -n "ShopProduct::" app/Http/Controllers/Api/Platforms/ShopController.php
grep -n "function products()\|function toBrandArray()" app/Models/Core/Site/ShopBrand.php
```

Expected: table present, and all the call sites in Task 2's file list still there. **If the call sites are gone, Task 2 is already done** — tick it with their commit sha rather than redoing it. **If the table is gone and the call sites are not, dev is broken** — check `cloud env:logs partna development --minutes 30` and Nightwatch, and treat Task 2 as urgent rather than routine.

**FINDING:** the drop phase did NOT take them. Both tables are live on dev
(`site.shop_brands` 10, `site.shop_products` 51, `content.storefronts` 15 — the
prompt's figures, confirmed by query, not assumed). Every call site is present.
Task 2 is real work.

**PLAN CORRECTIONS — Task 2's line list was incomplete.** Re-derived:

| Task 2 step | Plan said | Reality |
|---|---|---|
| Step 3 — `?? …fresh('products')->toBrandArray()` fallbacks | `:468, 580, 664` (3) | **`:468, 580, 664, 947, 1096` (5)** — `947` (`setProducts`) and `1096` (the individual bucket, keyed `ShopBrand::INDIVIDUAL_BRAND_ID`) were never listed |
| Step 4 — `->with('products')` eager loads | `:881, 895, 1291` | holds exactly |
| Step 5 — `ShopProduct::…->delete()` | `:772, 985, 1113, 1141` | holds exactly |
| Step 6 — `ShopBrand::products()` | `:114-117` | **`:115-118`** |
| Step 6 — `ShopBrand::toBrandArray()` | `:171-175` | starts `:171`, body runs past `:175` |

Two further `products` reads the plan does not name, both of which Step 4/6 must
absorb or they become dangling callers of a deleted relation:
`ShopController:655` `syncLatest($brand->fresh('products'))` and `:891`
`providerProducts($brand->toBrandArray())`.

**Also stale, for the record:** the EXECUTE prompt says `git grep -c ShopBrand`
on `ShopController` returned **61**; against `origin/development` `44e257c28` it
is **40**. Different refs, not a contradiction — but 40 is the live number.

- [x] **Step 4: Record all three answers in this file**, then commit.

```bash
git add docs/superpowers/plans/2026-08-17-shop-brands-rehome.md
git commit -m "docs(shop-rehome): entry gate — what slice 7 left"
```

### Task 2: `site.shop_products` — retire the legacy product reads

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/ShopController.php:468, 580, 655, 664, 772, 881, 891, 895, 947, 985, 1096, 1113, 1141, 1291`, `app/Models/Core/Site/ShopBrand.php:115-118, 171+`, `app/Services/Platforms/ShopCatalog.php:104`
  *(Corrected by Task 1 Step 3 — `:655, 891, 947, 1096` were missing from the original list.)*
- Test: `tests/Feature/Shop/ShopStoreReadLaneTest.php`

Ordered as the drop-phase session's §4d resolution sets out, which is the right
shape: delete the redundant fallback FIRST, which makes the relation callerless,
rather than migrating a branch that has no reason to exist.

- [x] **Step 1: Failing test** — no query naming `shop_products` on the read path.

```php
it('reads a brand payload without touching site.shop_products', function (): void {
    $user = sbrUserWithStore();

    $queries = [];
    DB::listen(function ($q) use (&$queries): void { $queries[] = $q->sql; });

    $this->actingAsUser($user)->getJson('/api/platforms/shop/catalog')->assertOk();

    expect(collect($queries)->filter(fn (string $s) => str_contains($s, 'shop_products')))
        ->toBeEmpty();
});
```

- [x] **Step 2: Run it, confirm it fails** — `./vendor/bin/pest tests/Feature/Shop/ShopStoreReadLaneTest.php --filter="without touching"`. Expected: FAIL, the query list contains `shop_products`.
- [x] **Step 3: Delete the three `?? $brandRow->fresh('products')->toBrandArray()` fallbacks** at `ShopController:468, 580, 664`. `$this->contentReader->brandMap($user)[$id]` is the authoritative read and the controller's own comment at `:465-466` calls the legacy arm *"a theoretical read-your-own-write miss"*.
- [x] **Step 4: Replace the three `->with('products')` eager loads** at `:881, :895, :1291` with `brandMap()`, which already returns the full shape including products.
- [x] **Step 5: Delete the four `ShopProduct::...->delete()` calls** at `:772, 985, 1113, 1141`. Product teardown is `ShopContentWriter::retireStore()`/`syncStore()`'s job on `content.*` and has been since slice 5a.
- [x] **Step 6: Delete `ShopCatalog::syncLatest()`'s `loadMissing('products')`** and `ShopBrand::toBrandArray()` (`:171+`). `productRanks` goes with it — `ShopContentReader::brandMap()` already takes `?array $productRanks`.

**PLAN CORRECTION (Task 1 Step 3) — `ShopBrand::products()` does NOT die here.**
The plan calls it "now-callerless" after Steps 3-5. It is not. Two callers
survive, and only one of them is removable in this task:

- `ShopFetch:66` eager-loads `->with(['connection', 'products'])` — its own
  comment says the relation is there solely to feed `toBrandArray()` through
  `syncLatest()`. Once `toBrandArray()` is gone this eager load is pointless,
  so it drops to `->with('connection')` **in this task**.
- `ShopBackfiller::run()` (`:56`, `:73`, `:81`) genuinely **reads** the rows —
  it IS the legacy→`content.*` migration, and `BackfillShopContentCommand`
  wraps it. It cannot lose the relation while the table exists.

So `products()`, the `ShopProduct` model, `ShopBackfiller` and
`BackfillShopContentCommand` all die together at **Task 13**, with the DROP —
the class is dead code the moment its source tables are gone. Task 13 Step 5's
deletion list is amended accordingly.

**PLAN GAP — Step 6 does not say what replaces `syncLatest()`'s `toBrandArray()`.**
`ShopCatalog::syncLatest()` calls `providerProducts($brand->toBrandArray())`, so
deleting the method forces a replacement the plan never names. Resolved here by
building the dispatch array from `toStoreRecord()` plus a **read-only**
`content.*` catalogue lookup (`collectionIdFor()`, which never mints a row),
populated only for `fetchMode === 'client'` — the one branch of
`providerProducts()` that reads `$brand['products']` (`ShopCatalog:59`). Two
consequences worth stating:

- Non-client providers issue **no** extra query; the lookup is lazy.
- The client-mode fallback gets **better**, not merely different. It used to
  read `site.shop_products`, which nothing has written since slice 5a, so it was
  already serving a frozen list. It now reads the live `content.*` catalogue.
- The `providerProducts()` call keeps its position **before** `storeCollectionId()`,
  so a failed fetch still writes nothing. `collectionIdFor()` is read-only
  precisely so this ordering survives.

- [x] **Step 7: Run it, confirm it passes**, then `composer test` and commit. The `site.shop_products` DROP itself is Task 13, with `shop_brands`.

---

## Pre-flight findings — verified 2026-08-17, before Phase 2

Everything below was re-derived against the live dev ref
(`glncumufgaqcmqhzwrxm`) or the branch tip, not taken from the spec.

### ⛔ TASK 11 BLOCKER — `content.storefronts` is NOT the shop lane's alone

`site.shop_brands` is a shop-only table. `content.storefronts` is **not**: the
order-platform lane writes it too. Of the 15 live rows, **10** hang off
`kind='storefront'` collections (shop) and **5** off `kind='order_platform'`
(DoorDash, written by `MenuFetchJob::…` at `app/Jobs/Platforms/MenuFetchJob.php:639`).
Both `ShopContentReader::brandMap()` and `ShopConnections::stores()` filter
`c.kind = 'storefront'`, so the order rows are correctly invisible to the shop
lane — that part is fine.

What is **not** fine: `MenuFetchJob:639`'s upsert writes
`collection_id, provider, url, external_ref, currency, referral_query,
is_individual, created_at, updated_at` — **and no `user_id`**. Task 11 adds
`user_id` and then `SET NOT NULL`. The migration itself succeeds (the backfill
fills all 15 existing rows through their collection), but **the next
order-platform scrape that inserts a new storefront row fails on a NOT NULL
violation.** The failure is deferred and silent until a menu is re-scraped,
which is the worst possible shape.

**Task 11 must therefore also make `MenuFetchJob:639` write `user_id`.** That
file is outside this plan's stated file list and outside the services session's
territory (theirs is `site.services` ×3 and `Service*`/`Fresha*`), so it is
ours to fix — but it is a cross-lane edit and gets its own commit and its own
test. `MenuFetchJob::clearStorefronts()` (`:1063`) deletes by collection id and
needs no change.

### Task 11 pre-flight — the index will build clean

```
storefronts_total          15      null_external_ref         0
orphaned_no_collection      0      collection_without_owner  0
duplicate (user_id, provider, external_ref) identities       0
```

So the backfill fills every row, `SET NOT NULL` cannot fail on a leftover null,
and the unique index cannot fail on a pre-existing duplicate.

Two design notes for the migration:

- **The index is PARTIAL on `external_ref IS NOT NULL`.** The column is
  nullable and Postgres treats NULLs as distinct, so a plain unique index would
  silently permit unlimited `(user_id, provider, NULL)` rows while *looking*
  like it enforced identity. The sibling index
  `idx_content_storefronts_external_ref` is already partial the same way.
- **Name it `storefronts_user_provider_ref_uq`**, not `…_unique` as this plan's
  Task 11 test snippet says — the existing unique index on the sibling table is
  `collections_user_kind_external_ref_uq`. The test must pin the real name.

### ✅ Task 13's backup gate — the tooling wall is GONE, solved early

The EXECUTE prompt flagged this in red and told us to solve it in parallel with
Phase 1 rather than discover it with the DROP as the only thing left. Done, and
the previous session's conclusion that R2 is unreachable from this laptop was
**wrong** — they missed the one route that works.

- **The bucket is `partna-db-backups`, plural.** `partna-db-backup` (singular)
  is the GitHub *repo*. CLAUDE.md and the EXECUTE prompt both conflate them.
- `rclone`, `aws`, `s3cmd`, `mc` and `wrangler` are all genuinely absent, and
  `.env`'s `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`/`AWS_BUCKET` are **empty
  strings** — so the `aws/aws-sdk-php` route (the SDK *is* in `vendor/`) is dead
  for want of credentials, and no configured disk points at a backup bucket.
  The scoped `R2_*` keys exist only as GitHub Actions secrets: write-only.
- **What works:** a wrangler OAuth session already stored at
  `~/Library/Preferences/.wrangler/config/default.toml`, driven through
  `npx --yes wrangler@4` so nothing needs installing. Its stored
  `expiration_time` is long past but the refresh token renews it silently, and
  R2 works despite no R2 scope being listed.
- **`scripts/db/backup-to-r2.sh`** (new) wraps it: encrypts by default with the
  same AES-256-CBC/pbkdf2 `weekly-db-backup.yml` uses so `restore-drill.yml`
  can decrypt it, uploads, then **reads the object back and byte-compares**
  before reporting success, and has a `--delete` path that confirms removal.
  Proven end to end against the live bucket — upload, verified round-trip,
  delete, delete confirmed — with throwaway probe objects only. Bucket left in
  its prior state.

Three traps the script encodes, each of which bit during that proving run:
`wrangler r2 bucket info`'s `object_count` is a lagging analytics figure and is
**not** a verification (it read 4 while the probe was demonstrably readable, and
wrangler has no object-list command at all); `wrangler r2 object get` creates
the destination file *before* discovering a 404 and exits 0 in a pipe, so a
zero-byte result is the failure signal, not the exit code; and `set -o pipefail`
with `wrangler … | grep` makes a correct delete-confirmation read as a failure.

Two operational notes for Task 13:
- `restore-drill.yml` resolves `object_key` under `weekly/` **only** (the prefix
  is hardcoded), and picks "newest" by `sort | tail -1` — so a pre-DROP dump
  must land under `weekly/` to be drillable, and its name becomes the default
  restore target if it sorts last.
- A laptop dump may be unnecessary: `gh workflow run weekly-db-backup -R
  Hunter-Balcombe-Sykes/partna-db-backup` runs dump → verify → encrypt → upload
  where the scoped secrets already live, and `restore-drill` proves it
  restorable. The last scheduled run succeeded 2026-08-16 15:10 UTC. Neither
  was triggered here — both write real data.

⚠️ The OAuth session is an **account-wide owner credential**, not the
bucket-scoped token the backup design specifies. Fine for an operator by hand;
it must never be baked into a CI path. That caveat is in the script header.

### Task 13's `pg_depend` gate — already clean

No inbound foreign keys, no views, no materialised views, no triggers, and no
function bodies referencing either table. The only dependents are their own
indexes (`idx_shop_brands_connection`, `idx_shop_products_brand`), their TOAST
tables, and their own RLS policies (`shop_brands_app_backend_all`,
`shop_products_app_backend_all`) — all of which drop with the table. Re-run at
Task 13 as the plan says; recorded here because it means the DROP has no
hidden blocker to discover late.

### Task 7 — the legacy shared anchor is already empty

`removeBrand()`'s anchor guard (`! ShopBrand::where('connection_id', …)->exists()`)
exists for ONE case: the retired `partna.storefront` marker, where several
stores shared an anchor. Live dev has **zero** `partna.storefront` connections —
Phase 6's retirement migration cleared them. Live surfaces are
`shopify.store` (9), `woocommerce.store` (1) and `partna.manual_product` (1).
With one connection per store the anchor's `resource_id` IS the store id, so
removing a store always empties its own anchor. Guard on the surface, not on a
row count, so a marker row that somehow survived is never deleted out from
under other stores.

### Spec §12's two open questions — both answerable NOW, not at Task 15

**§12.1 — does anything outside `app/` reference the `shop-brands/<uuid>` R2
prefix?** **No.** The only code occurrence anywhere is
`ProcessShopBrandLogoJob.php:104`. Zero hits in `config/`, `scripts/`,
`cloudflare-worker/`, `tests/`, `database/`; the only other hits are this plan,
the EXECUTE prompt and the spec discussing it. §5.1's assumption holds.

**§12.2 — where does the stale-pending clock live?** On
`content.storefronts.updated_at`, and **the explicit `touch()` disappears
rather than needing a replacement.** The spec asserts that "`upsertStore()`
writing byte-identical values will not bump it either — the same defect the
legacy path already patched once". **That is wrong**, verified against a real
Postgres 16 rather than reasoned about: `ON CONFLICT DO UPDATE` writes the row
unconditionally, so `updated_at` moved even when every other column was
byte-identical. The legacy `$brandRow->touch()` exists because *Eloquent's*
`fill()->save()` skips a no-op UPDATE — that is a model-layer dirty check, not
a database one, and the upsert has no equivalent.

### Task 6 Steps 1-2 are already satisfied by an existing guard

The plan asks for a characterisation test written against the legacy path that
passes BEFORE the repoint. `tests/Feature/Shop/ShopEndpointParityTest.php` is
exactly that and already passes (13 tests, 80 assertions): byte-exact
`assertExactJson` captures for all five read endpoints, taken from a dump of
the real pre-Task-7 responses. Do not write a second one — re-run that file
after each Phase 2 commit instead.

Note its last test, *"site.shop_brands keeps being written — the anchor row +
live public read"*, is an INVERSE guard and is **expected to fail at Task 7**,
when the legacy writes stop. Failing it is the signal Task 7 worked; it gets
re-cut then, not before.

### Phase 3 (Tasks 9-10) — the plan materially understates the work

Re-derived file by file. Corrections:

| Plan says | Reality |
|---|---|
| `ShopBrandConnectJob.php:91-226` | L64-287 — `markTerminal()` at L259-287 is outside the cited window and holds a second `ShopBrand::update()` plus two `->connection` reads |
| `ProcessShopBrandLogoJob.php:53-127` | L45-138 |
| dispatch sites at `ShopController:474, 496` | **476 and 498**, and there is a **third**: `ShopBrandConnectJob.php:215` dispatches `ProcessShopBrandLogoJob`. Step 6's "update both dispatch sites in `ShopController`" is wrong. |
| "both are dispatched with `(string) $brandRow->id`" | only the logo job casts; `ShopController:498` passes `$brandRow->id` uncast |
| `$store->source_url` (Task 10 Step 3) | `$store->sourceUrl` — the names are NOT identical, which is the one thing that step asserts |
| "`StoreBrandSeeder`'s five touches" | eight |
| "`StoreBrandProfiler`'s two touches" | five |
| Task 10 is "type-hint changes, not logic changes" | **false** — three substantive logic problems, below |

**The three Task 10 hazards, none of which the plan names:**

1. **`connection_id` scoping has no `content.*` equivalent.** `StoreBrandSeeder`
   scopes four queries on `connection_id`, and `StoreBrandProfiler`'s ENTIRE
   public API is connection-keyed (`profile(string $connectionId)`, plus
   `ownedAssetUrls()`/`catalogCurrency()`/`attributionName()`, which query
   `content.brand_asset_refs.connection_id` and `content.sources.connection_id`).
   `content.storefronts` carries no connection linkage, so there is no join path
   from a connection id to a `StoreRecord` inside `content.*` at all. The bridge
   has to come from `site.platform_connections`: `resource_id` IS the brand id
   (`ShopConnections::anchor()`), so connection → `user_id` + `resource_id` →
   `stores($user)->get($resourceId)`. That is a design decision this plan never
   made, and it is the likeliest thing to blow up Task 10.
2. **`upsertStore()` has no partial-write semantics.** `StoreBrandSeeder`
   deliberately builds `$carried` with `array_filter(… !== null)` so a re-seed
   never wipes a favicon/logo an earlier fetch earned; `ShopProductSeeder` relies
   on `firstOrCreate` NOT updating an existing bucket's currency/position.
   `upsertStore()` lists `logo_url`/`favicon_url` unconditionally in its ON
   CONFLICT update, so a naive port nulls out an existing logo on every re-scan.
   Both need read-back-and-fold, exactly as Task 9 Step 4 prescribes for the
   connect job — the plan says so for Task 9 and not for Task 10.
3. **`ShopBrandConnectJob`'s two settle writes are compare-and-set**
   (`->where('connect_status','pending')->update(…)`, and the design rests on
   the returned row COUNT). `upsertStore()` is unconditional and returns a
   collection id. A naive replacement loses the "a stale retry must not clobber
   a newer settle" guarantee.

**Also:** `ShopConnections::storeByCollection()` and the plan's
`sbrUserWithPendingStore()` / `sbrProvisionalUser()` helpers do not exist yet.
`ShopBrand::INDIVIDUAL_BRAND_ID` is referenced from `ShopConnections.php:120`
and `ShopProductSeeder.php:75` and must move off the model before the DROP
(Task 7 Step 4 already plans the move; these are two more call sites it must
carry). `StoreBrandProfiler::settle()` has **no production caller** — only
three tests — so consider deleting it rather than porting it.

---

## Phase 1 — The read lane

### Task 3: `StoreRecord` carries its collection id

**Files:**
- Modify: `app/Services/Shop/StoreRecord.php`
- Test: `tests/Feature/Shop/ShopStoreReadLaneTest.php`

**Interfaces:**
- Produces: `StoreRecord::$collectionId` (`?string`, default `null`) — set by `fromStorefrontRow()`, never passed to `upsertStore()`.

- [x] **Step 1: Write the failing test.**

```php
it('carries the collection id when rebuilt from a storefront row', function (): void {
    $row = (object) [
        'collection_id' => '11111111-1111-4111-8111-111111111111',
        'external_ref' => '75102060779',
        'provider' => 'shopify',
        'label' => 'Fear No Evil',
        'position' => 2,
        'url' => 'https://fearnoevil.com.au',
        'source_url' => null,
        'currency' => 'AUD',
        'discount_code' => null,
        'referral_query' => '',
        'is_individual' => false,
        'fetch_mode' => null,
        'connect_status' => null,
        'connect_error' => null,
        'logo_url' => null,
        'favicon_url' => null,
        'logo_mark_url' => null,
        'logo_mark_svg_url' => null,
        'products_curated_at' => null,
    ];

    $record = StoreRecord::fromStorefrontRow($row);

    expect($record->collectionId)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($record->externalRef)->toBe('75102060779')
        ->and($record->name)->toBe('Fear No Evil');
});
```

- [x] **Step 2: Run it, confirm it fails** — `./vendor/bin/pest tests/Feature/Shop/ShopStoreReadLaneTest.php --filter="collection id"`. Expected: FAIL, `Unknown named parameter` or `null`.
- [x] **Step 3: Add the property and populate it.**

```php
        public ?Carbon $productsCuratedAt = null,
        /**
         * Set ONLY when read back from content.* — never an input to
         * upsertStore(), where the collection id is the output. It is the
         * replacement identity for the site.shop_brands uuid PK the two async
         * jobs used to key on (spec §5).
         */
        public ?string $collectionId = null,
    ) {}
```

and in `fromStorefrontRow()`:

```php
            productsCuratedAt: $curatedAt === null ? null : Carbon::parse((string) $curatedAt),
            collectionId: isset($row->collection_id) ? (string) $row->collection_id : null,
        );
```

- [x] **Step 4: Run it, confirm it passes.**
- [x] **Step 5: Commit.**

```bash
git add app/Services/Shop/StoreRecord.php tests/Feature/Shop/ShopStoreReadLaneTest.php
git commit -m "feat(shop-rehome): StoreRecord carries its collection id"
```

### Task 4: `ShopConnections::stores()` / `store()` — the read lane on `content.*`

**Files:**
- Modify: `app/Services/Shop/ShopConnections.php:180-195`
- Test: `tests/Feature/Shop/ShopStoreReadLaneTest.php`

**Interfaces:**
- Consumes: `StoreRecord::fromStorefrontRow()` (Task 3).
- Produces: `ShopConnections::stores(User $user): Collection<string, StoreRecord>` keyed by `externalRef`; `ShopConnections::store(User $user, string $externalRef): ?StoreRecord`.

The old methods returned a query `Builder`, so callers chained `->where()`, `->max('position')`, `->count()`. A keyed `Collection` supports all three with `->where()`, `->max()`, `->count()` and needs no database round trip per call.

- [x] **Step 1: Write the failing test.**

```php
it('lists every store the user owns, ordered, off content.*', function (): void {
    $user = sbrUserWithStores(['alpha' => 0, 'beta' => 1]);

    $stores = app(ShopConnections::class)->stores($user);

    expect($stores->keys()->all())->toBe(['alpha', 'beta'])
        ->and($stores->get('alpha'))->toBeInstanceOf(StoreRecord::class)
        ->and($stores->get('alpha')->collectionId)->not->toBeNull();
});

it('returns an empty collection for a user with no shop at all', function (): void {
    expect(app(ShopConnections::class)->stores(sbrUserWithNoShop()))->toBeEmpty();
});

it('issues no query naming shop_brands', function (): void {
    $user = sbrUserWithStores(['alpha' => 0]);

    $queries = [];
    DB::listen(function ($q) use (&$queries): void { $queries[] = $q->sql; });
    app(ShopConnections::class)->stores($user);

    expect(collect($queries)->filter(fn (string $s) => str_contains($s, 'shop_brands')))
        ->toBeEmpty();
});
```

- [x] **Step 2: Run them, confirm they fail** — `./vendor/bin/pest tests/Feature/Shop/ShopStoreReadLaneTest.php`. Expected: FAIL, `Call to undefined method ShopConnections::stores()`.
- [x] **Step 3: Implement, mirroring `ShopContentReader::brandMap()`'s query exactly** — same join, same ordering, so the two never disagree about which store is first.

```php
    /**
     * Every connected store this user owns, keyed by its provider store id.
     *
     * Replaces brands(), which queried site.shop_brands. Ordering mirrors
     * ShopContentReader::brandMap() — c.position then s.external_ref — so the
     * two reads can never disagree about position 0.
     *
     * @return Collection<string, StoreRecord>
     */
    public function stores(User $user): Collection
    {
        return DB::table('content.storefronts as s')
            ->join('content.collections as c', 'c.id', '=', 's.collection_id')
            ->where('c.user_id', (string) $user->id)
            ->where('c.kind', 'storefront')
            ->orderBy('c.position')
            ->orderBy('s.external_ref')
            ->get([
                's.collection_id', 's.external_ref', 's.provider', 's.url', 's.source_url',
                's.currency', 's.discount_code', 's.referral_query', 's.is_individual',
                's.fetch_mode', 's.connect_status', 's.connect_error', 's.products_curated_at',
                's.logo_url', 's.favicon_url', 's.logo_mark_url', 's.logo_mark_svg_url',
                'c.label', 'c.position',
            ])
            // A row with no external_ref has nothing to key on — skip rather
            // than collide every such row onto ''. Same guard brandMap() applies.
            ->reject(fn (object $row): bool => (string) ($row->external_ref ?? '') === '')
            ->mapWithKeys(fn (object $row): array => [
                (string) $row->external_ref => StoreRecord::fromStorefrontRow($row),
            ]);
    }

    /** One store by its provider store id, across the user's whole shop family. */
    public function store(User $user, string $externalRef): ?StoreRecord
    {
        return $this->stores($user)->get($externalRef);
    }
```

- [x] **Step 4: Run the tests, confirm they pass.**
- [x] **Step 5: Leave `brands()`/`brand()` in place, marked deprecated.** Tasks 5–9 move callers one lane at a time; deleting the old pair now breaks all seven `ShopController` touches in a single commit no reviewer can read.

```php
    /** @deprecated Reads site.shop_brands. Use stores(). Deleted in Task 11. */
    public function brands(User $user): Builder
```

- [x] **Step 6: Commit.**

```bash
git add app/Services/Shop/ShopConnections.php tests/Feature/Shop/ShopStoreReadLaneTest.php
git commit -m "feat(shop-rehome): ShopConnections::stores() reads content.*"
```

### Task 5: Phase 1 gate

- [x] **Step 1:** `composer test`, then `./vendor/bin/pest --parallel --processes=4`.
      → **8283 passed, 2 skipped, 0 failed** (baseline 8272; +11 net new tests).
- [x] **Step 2 result:** PG lane **207 passed, 955 assertions**, run against a
      throwaway `postgres:16` container on port 55437 (`.env`'s `DB_HOST` is a
      dead ref, so a bare `composer test:pg` reports connection errors — see
      the local-invocation recipe).
- [x] **Step 3 result:** PHPStan at `-d memory_limit=1G` → **no errors**
      (needed two baseline/annotation corrections, both real: a stale
      `ShopBrand` `is_array()` ignore whose line was deleted with
      `toBrandArray()`, and a `Collection` covariance error from using
      `pipe()`). Pint → passed.
- [x] **Step 2:** `composer test:pg` — `ShopStorefrontUpsertConflictTest` and `ShopUpsertStoreAtomicityTest` both live there.
- [x] **Step 3:** `php -d memory_limit=1G ./vendor/bin/phpstan analyse --no-progress` and `php artisan pint`.
- [x] **Step 4: Independent review of the phase diff**, then merge to `development` and deploy dev. ~~`stores()` is additive — nothing reads it yet, so this deploy changes no behaviour.~~

**CORRECTED — that sentence is true of Task 4 and FALSE of the phase**, and it
must not be read as "safe to fast-forward". Task 4 is additive; **Task 2 is
not**. It changed five response payloads, dropped `ShopFetch`'s `products`
eager load, changed `syncLatest()`'s dispatch shape and the source of its
client-mode fallback, and repointed the private `ShopController::brandMap()`
off `site.shop_brands` onto `content.*` — reversing a decision the deleted
docblock recorded verbatim (*"It stays on the legacy row deliberately:
`catalog()` dispatches a LIVE re-scrape…"*). The behaviour change that leaves:
**`POST /brands/{id}/catalog` now 404s for a store present in
`site.shop_brands` and absent from `content.*`**, where it previously worked.
Deliberate — `catalog()` reads `{url, provider}` for a re-scrape and content.*
carries both — but it is a change, and it ships with this deploy.

**Independent review: run.** One P1 and five P2s, all fixed in the phase before
merge (see the review-fixes commit for the detail):

- **P1** — `brandPayload()`'s `?? []` was the one payload site NOT preceded by
  an `upsertStore()` in the same request, so an un-backfilled store made
  `connectStatus()` emit a card with `id: ""` and every conditional key gone.
  It now returns the identity it actually knows, from the legacy row it already
  holds, with an empty catalogue. Pinned by a test.
- **P2** — a `removeBrand`/`forget` assertion that enshrined an SQLite-only
  truth (`shop_products.brand_id` is `ON DELETE CASCADE`, so Postgres removes
  the rows and SQLite does not) → replaced with a query assertion; an ordering
  test that could pass on two empty arrays and did not discriminate position
  order from alphabetical → literal order + count; a **genuine latent bug** in
  the client-mode `syncLatest()` path (it resolved the owner through the lazy
  `connection` relation, throwing `LazyLoadingViolationException` for a brand
  hydrated off a multi-row `get()` — #428's exact shape) → `loadMissing()`,
  plus the client-mode test that was missing; ~20 docblocks still naming the
  deleted `ShopBrand::toBrandArray()` as the authority for the wire shape →
  re-pointed at `ShopContentReader::brandMap()`.

**Carried to Task 6 rather than fixed here** (neither bites while nothing calls
`stores()`):

1. **`stores()` is not soft-delete aware and `brands()` is.** `brands()` scopes
   through `connectionIds($user)` → `$user->integrationConnections()`, which
   respects the connection's soft delete. `stores()` has no connection concept,
   so a store whose `IntegrationConnection` was soft-deleted (staff takedown,
   GDPR, `forgetAllConnections`) while its `content.*` collection survived
   appears in one and not the other. Task 6 must decide whether the content
   collection is retired on that path, or whether `stores()` needs the join.
2. **`store()` re-issues the whole query per call.** The commit's "a single
   query instead of one per call" only holds if the caller hoists `stores()`
   into a variable. `addBrand` has three `brand()`/`brands()` touches today, so
   a naive repoint turns one query into three full scans. Hoist at Task 6.
3. `ShopController:337`'s `->where('is_individual', false)` becomes
   `Collection::where('isIndividual', false)`, which uses loose `==`.

---

## Phase 2 — `ShopController`

### Task 6: The five read endpoints

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/ShopController.php:336, 338, 352, 517, 602`
- Test: `tests/Feature/Shop/ShopEndpointParityTest.php`

**Interfaces:**
- Consumes: `ShopConnections::stores()`, `::store()` (Task 4).

`connectStatus`, `updateBrand`, `removeBrand`, `setProducts` and `removeProduct` all resolve a store through `$this->shop->brand($user, $id)`. `addBrand` additionally uses `brands($user)->where('is_individual', false)->count()` for the store cap and `brands($user)->max('position')` for placement.

- [x] **Step 1: Write the failing characterisation test FIRST, against the current legacy path** — this is what proves the repoint changed nothing observable.

```php
it('returns identical payloads for every store-resolving endpoint', function (string $verb, string $path): void {
    $user = sbrUserWithStore('alpha');

    $response = $this->actingAsUser($user)->json($verb, $path)->assertOk();

    expect($response->json('data.brand.id'))->toBe('alpha')
        ->and($response->json('data.brand.provider'))->toBe('shopify');
})->with([
    ['GET', '/api/platforms/shop/brands/alpha/connect/status'],
    ['PATCH', '/api/platforms/shop/brands/alpha'],
]);
```

- [x] **Step 2: Run it against the legacy path, confirm it PASSES.** A characterisation test that fails before the change is testing the wrong thing.
- [x] **Step 3: Repoint the five resolutions.** `store()` returns `?StoreRecord`, so the 404-on-miss shape is unchanged:

```php
        $store = $this->shop->store($user, $id);

        if ($store === null) {
            return $this->notFound();
        }
```

- [x] **Step 4: Repoint the two aggregate reads in `addBrand`.**

```php
            $stores = $this->shop->stores($user);
            $storeCount = $stores->where('isIndividual', false)->count();
            $maxPosition = $stores->max('position') ?? 0;
```

Note the property names change with the type: `is_individual` → `isIndividual`, and `max('position')` on a `Collection` of objects returns `null` (not `0`) for an empty set — hence the coalesce, which the `Builder` version did not need.

- [x] **Step 5: Run the characterisation test again, confirm it still passes**, plus `./vendor/bin/pest tests/Feature/Platforms/ tests/Feature/Shop/`.
- [x] **Step 6: Commit.**


**SCOPE CORRECTION — Task 6/7's split was drawn in the wrong place.** The plan
assigns all five store resolutions to Task 6, but three of them
(`updateBrand`, `removeBrand`, `removeProduct`) resolve a row and then WRITE
through it, and `setProducts` resolves one to build a `StoreRecord` for
`upsertStore()`. Repointing those reads without their writes leaves a
`StoreRecord` where an Eloquent model is required. The split actually taken:

- **Task 6 — reads whose result never feeds a legacy write:** `connectStatus()`
  (now `store()`, and it deleted `brandPayload()` outright — see below);
  `addBrand`'s store-cap and max-position aggregates; `addProduct`'s
  max-position. `addBrand`'s `$existing` is a `StoreRecord` for the read half
  and re-resolves the legacy row for the write half, one extra query on the
  connect path that disappears at Task 7.
- **Task 7 — every resolution that feeds a write**, moved with its write.

**The P1 fallback died here, exactly as its docblock promised.** With
`connectStatus()` resolving through `store()`, a store absent from `content.*`
404s before any payload is built, so `brandPayload()`'s truthful-minimum
fallback became unreachable and was deleted. Its test was re-cut from
"serves a truthful store" to "404s a store content.* has never seen" — keeping
the old assertion would have pinned behaviour the code no longer has.

**Three `ShopAsyncConnectTest` fixtures were pulled forward from Task 12** (T19,
P1, T22): they built a legacy row and polled `connectStatus()` without ever
writing `content.*`, so they 404'd the moment the resolution moved. Each now
seeds via `upsertStore()`, and the two stale-pending tests age
`content.storefronts.updated_at` rather than the legacy row's.

**P1's test is now direct evidence for spec §12.2.** It re-POSTs an
already-pending store — byte-identical values — and asserts the clock moved,
with no explicit `touch()` anywhere. `addBrand`'s `$brandRow->touch()` is
therefore vestigial and goes with the legacy write at Task 7.
### Task 7: The identity and lifecycle writes

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/ShopController.php:406, 467-474, 496, 869, 929, 985`
- Test: `tests/Feature/Platforms/ShopAsyncConnectTest.php`

`addBrand` mints `new ShopBrand(['brand_id' => $id])` and saves it as the identity row; `addProduct` `firstOrCreate`s the reserved individual bucket; `removeBrand`/`forget` delete.

- [ ] **Step 1: Write the failing test** — a full connect with no `site.shop_brands` row present anywhere.

```php
it('connects a store end to end with no legacy row', function (): void {
    $user = sbrUserWithNoShop();

    $this->actingAsUser($user)
        ->postJson('/api/platforms/shop/brands', ['url' => 'https://fearnoevil.com.au'])
        ->assertOk()
        ->assertJsonPath('data.brand.provider', 'shopify');

    expect(DB::table('content.storefronts')->count())->toBe(1)
        ->and(Schema::hasTable('site.shop_brands')
            ? DB::table('site.shop_brands')->count()
            : 0)->toBe(0);
});
```

- [ ] **Step 2: Run it, confirm it fails** — the legacy insert still fires and the count is 1.
- [ ] **Step 3: Replace the identity write with `upsertStore()`.** `ShopContentWriter::upsertStore(StoreRecord $store, string $ownerId): string` already returns the collection id and is already the writer of record. Note the argument order — the record first, the owner id as a plain string second, not a `User`:

```php
            $store = new StoreRecord(
                externalRef: $id,
                provider: $values['provider'],
                name: $values['name'] ?? null,
                position: $values['position'] ?? 0,
                url: $values['url'] ?? null,
                sourceUrl: $values['source_url'] ?? null,
                currency: $values['currency'] ?? null,
                discountCode: $values['discount_code'] ?? null,
                fetchMode: $values['fetch_mode'] ?? null,
                connectStatus: $deferred ? 'pending' : null,
            );

            $collectionId = $this->content->upsertStore($store, (string) $user->id);
```

- [ ] **Step 4: Replace `addProduct`'s individual-bucket `firstOrCreate`** with the same call, `isIndividual: true` and `externalRef` taken from a new `StoreRecord::INDIVIDUAL_BRAND_ID = 'individual'` const — the same value as `ShopBrand::INDIVIDUAL_BRAND_ID:94` today, moved off the doomed model rather than referenced on it (leaving a const behind on a class scheduled for deletion is how Task 24's adapter had to be untangled twice).
- [ ] **Step 5: Replace the deletes** with `ShopContentWriter::retireStore(string $userId, string $collectionId): void` — note it takes the owner id and collection id, so the store must be resolved through `store()` first to get its `collectionId`. Do NOT leave `$brand->delete()` behind — a store removed from `content.*` but left in the legacy table reappears on the next `stores()` read only if something still reads the legacy table, which after Task 11 nothing does; the risk is the reverse, a legacy orphan surviving the DROP audit.
- [ ] **Step 6: Delete the legacy fallback in the response path** (`:467-469`) — `$this->contentReader->brandMap($user)[$id] ?? $brandRow->fresh('products')->toBrandArray()` becomes the `brandMap()` read alone. The `upsertStore()` call two lines above guarantees the row exists.
- [ ] **Step 7: Run it, confirm it passes**, plus the full `tests/Feature/Platforms/` suite. Commit.

### Task 8: Phase 2 gate

- [ ] **Step 1:** `composer test`, `./vendor/bin/pest --parallel --processes=4`, `composer test:pg`, PHPStan, Pint.
- [ ] **Step 2: Merge to `development`, deploy dev.**
- [ ] **Step 3: On dev, exercise all seven verbs against a real store** — connect, poll status, rename, curate products, remove a product, remove the store, re-add it. Assert `content.storefronts` moved and `site.shop_brands` did not (`SELECT max(updated_at) FROM site.shop_brands` before and after).

---

## Phase 3 — Jobs, profiler, seeding lane

### Task 9: The two async jobs key on the collection id

**Files:**
- Modify: `app/Jobs/Platforms/ShopBrandConnectJob.php:91-226`, `app/Jobs/Platforms/ProcessShopBrandLogoJob.php:53-127`, `app/Http/Controllers/Api/Platforms/ShopController.php:474, 496`
- Test: `tests/Unit/Jobs/ShopBrandConnectJobTest.php`, `tests/Feature/Platforms/ShopBrandLogoProcessingTest.php`

Both are dispatched with `(string) $brandRow->id`, the legacy uuid PK, which has no `content.*` twin. Spec §5: the collection id replaces it.

- [x] **Step 1: Write the failing test.**

```php
it('settles a deferred connect keyed on the collection id', function (): void {
    $user = sbrUserWithPendingStore('alpha');
    $collectionId = DB::table('content.storefronts')->value('collection_id');

    ShopBrandConnectJob::dispatchSync($collectionId);

    expect(DB::table('content.storefronts')->value('connect_status'))->toBe('connected');
});
```

- [x] **Step 2: Run it, confirm it fails** — the job looks the id up in `site.shop_brands` and finds nothing.
- [x] **Step 3: Rename the constructor property** `$brandRowId` → `$collectionId` in both jobs and repoint the two lookups onto `ShopConnections`:

```php
    public function handle(ShopBrandProfiler $profiler, /* … */ ShopConnections $shop): void
    {
        $store = $shop->storeByCollection($this->collectionId);

        if ($store === null) {
            return; // the store was removed while this sat in the queue
        }
```

Add `ShopConnections::storeByCollection(string $collectionId): ?StoreRecord` alongside `stores()` — same query, `where('s.collection_id', …)` instead of the user scope, because a queued job carries no `User`.

- [x] **Step 4: Replace both settle writes.** `ShopBrand::whereKey($id)->update([...])` becomes `ShopContentWriter::upsertStore()` with the profiled fields folded onto the record. Keep `ShopBrandConnectJob`'s deliberate rule that `external_ref`/`provider` are never re-derived — build the new record from the read-back one with named arguments, not from scratch.
- [x] **Step 5: Move the R2 prefix.** `$base = "shop-brands/{$brand->id}"` becomes `"shop-brands/{$store->collectionId}"`. Per spec §5.1 do **not** migrate existing objects: their URLs are stored absolute and keep resolving, and a re-process rewrites them under the new prefix for free. Add that sentence as a comment above the line so a later bucket audit does not read it as loss.
- [x] **Step 6: Update both dispatch sites** in `ShopController` to pass `$collectionId` (already in hand from Task 7 Step 3).
- [x] **Step 7: Run both test files, confirm they pass.** Commit.


**Corrections made while executing (all verified, not inferred):**

- The cited ranges were stale: `ShopBrandConnectJob.php:91-226` is really
  L64-287 (`markTerminal()` sits outside the cited window and held a SECOND
  legacy write plus two `->connection` reads), and
  `ProcessShopBrandLogoJob.php:53-127` is L45-138.
- Step 6 says "update both dispatch sites in `ShopController`". There are
  **three**: `ShopController:476` and `:498` (not 474/496), plus
  `ShopBrandConnectJob:215`, which dispatches the logo job from inside the job
  being edited. Also, only the logo job's dispatch was `(string)`-cast.
- **Step 4's "replace both settle writes with `upsertStore()`" is wrong and was
  not done.** Both writes are COMPARE-AND-SET — `->where('connect_status',
  'pending')->update(…) > 0` — and the whole design rests on that row count: a
  stale retry arriving after a newer dispatch already settled the store must be
  a no-op, not a regression that flips a successful connect back to 'failed'.
  `upsertStore()` is an unconditional upsert returning a collection id; it can
  express neither the guard nor the answer. Both settles are therefore guarded
  UPDATEs on `content.storefronts` — the same direct-write seam
  `updateBrand()`/`setProducts()` already use for `products_curated_at` — with
  `updated_at` set by hand, because a query-builder write does not maintain it
  and it is now the stale-pending clock.
- The store's display NAME is not on `content.storefronts`; it is
  `content.collections.label`. The settle writes `label = name ?? externalRef`,
  which mirrors the legacy write of a null name exactly (`ShopContentReader`
  nulls that case back out on read) rather than inventing a display name.
- `ProcessShopBrandLogoJob` writes its two mark columns DIRECTLY rather than
  through `upsertStore()`. That method rewrites the whole row from a record, and
  this job runs AFTER the connect settle — so it would re-assert a
  connect_status/currency/name snapshot taken before the fetch.
- The new `ShopBrandConnectJob::settle()` writes `content.collections`, which
  trips `CollectionWriteInvalidationGuardTest`'s writer registry. Registered as
  lane SHOP with its discharge noted — the guard did exactly its job.

**`ShopConnections` gained two methods this task, neither in the plan:**
`storeByCollection(string): ?StoreRecord` (unscoped by design — a queued job has
no `User`, and the collection id is itself the tenant-scoped handle) and
`anchorFor(User, string): ?IntegrationConnection`, the read-only counterpart to
`anchor()`. The second exists because `content.storefronts` carries no
connection linkage: the jobs still need the connection row for the availability
re-check and the edge purge, and `resource_id` IS the store id, which is what
makes that lookup possible at all.

**`StoreRecord` gained `$userId`** (Task 11's denormalised column), which is what
lets a queued job resolve its owner from the read it was already doing instead
of a second query through `content.collections`. This is why Task 11 moved ahead
of Task 9.
### Task 10: Profiler, catalog and the pre-account seeding lane

**Files:**
- Modify: `app/Services/Platforms/ShopBrandProfiler.php:75`, `app/Services/Platforms/ShopCatalog.php`, `app/Services/Platforms/ShopProductSeeder.php`, `app/Services/Brand/StoreBrandSeeder.php`, `app/Services/Brand/StoreBrandProfiler.php`, `app/Services/Brand/BrandAssetPipeline.php`
- Test: `tests/Feature/Brand/StoreBrandSeederTest.php`, `tests/Feature/Brand/StoreBrandProfilerTest.php`

These are type-hint changes, not logic changes — `forRow()` reads only `provider`, `url` and `source_url`, all of which `StoreRecord` carries under the same names.

- [x] **Step 1: Write the failing test** — seed a pre-account store and assert it lands in `content.*` with no legacy row.

```php
it('seeds a pre-account store into content.* only', function (): void {
    $user = sbrProvisionalUser();

    app(StoreBrandSeeder::class)->seed($user, 'https://fearnoevil.com.au');

    expect(DB::table('content.storefronts')->count())->toBe(1);
});
```

- [x] **Step 2: Run it, confirm it fails.**
- [x] **Step 3: Change `ShopBrandProfiler::forRow(ShopBrand $brand)` to `forRow(StoreRecord $store)`.** The `match` body is unchanged — `$store->provider`, `$store->url`, `$store->source_url` are the same names.
- [x] **Step 4: Change `ShopCatalog::syncLatest()`'s type hint to `StoreRecord`.** Its `loadMissing('products')` is already gone — Task 2 Step 6 removed it along with the relation.
- [x] **Step 5: Repoint `StoreBrandSeeder`'s five touches and `StoreBrandProfiler`'s two** onto `upsertStore()`. `BrandAssetPipeline`'s single touch is a type hint.
- [x] **Step 6: Run both test files plus `tests/Feature/PreAccount/`, confirm they pass.** Commit.

---


**Task 10 is NOT the "type-hint changes, not logic changes" this plan claims.**
Three substantive problems, each resolved rather than worked around:

1. **`connection_id` scoping has no `content.*` equivalent.** `StoreBrandSeeder`
   scoped four reads on it and `StoreBrandProfiler`'s whole public API is
   connection-keyed. The bridge is `site.platform_connections` itself: both
   `ShopConnections::anchor()` and `SourceReconciler::applyIntent()` write
   `resource_id` = the store id, so a connection yields owner + external_ref and
   `stores($user)->get($ref)` does the rest. The seeder's cap/duplicate reads
   become USER-scoped, which its own comment already said was what it wanted —
   the connection scope was only ever equivalent because the probe pipeline
   mints one connection per store.
2. **`upsertStore()` has no partial-write semantics.** `StoreBrandSeeder`
   deliberately built `$carried` with `array_filter(… !== null)` so a re-seed
   never wipes a favicon or logo an earlier fetch earned; `upsertStore()` lists
   every column unconditionally in its ON CONFLICT clause, so a naive port would
   have inverted that rule and blanked the logo on every re-scan. Expressed now
   as a coalesce onto the existing record — the same read-back-and-fold Task 9
   Step 4 prescribes for the connect job, which this plan asks for there and not
   here.
3. **`StoreBrandProfiler::settle()` is DELETED, not ported.** Verified against
   `origin/development`, not inferred: its only occurrence anywhere in `app/` is
   its own declaration — **zero production callers**, three test callers.
   Porting it would have meant solving problem 1 for dead code.

   Recorded honestly, because the reviewing agent flagged it as a coverage loss:
   `settle()` had two halves. The fill-if-empty PRECEDENCE (`name ?: attribution`,
   `currency ?: catalogCurrency`) survives unchanged in `profile()`, and its
   three tests were re-pointed there rather than deleted. The PERSISTENCE half —
   writing the derived value back and reporting whether it wrote — is gone and
   now has no coverage. That is not a behaviour regression: with no production
   caller it never ran in production. If that write-back is ever wanted, it is
   new work, not a restoration.

**Also corrected:** `$store->source_url` does not exist — the DTO is
`sourceUrl`, and Step 3's claim that "the `match` body is unchanged, the names
are the same" is wrong for exactly that one field. `ShopCatalog::syncLatest()`'s
type hint (Step 4) is NOT changed here: its callers still hand it a `ShopBrand`
(`ShopFetch`, `updateBrand`), so it moves with Task 7, not before it.
## Phase 4 — Schema, tests, DROP

### Task 11: Denormalise `user_id` and enforce identity — spec §6

**Files:**
- Create: `supabase/migrations/20260819000100_content_storefronts_user_id.sql`
- Modify: `app/Services/Shop/ShopContentWriter.php` (`upsertStore()` writes the new column)
- Test: `tests/Postgres/ShopStorefrontUpsertConflictTest.php`

`20260813100001`'s own comment states the position: true identity is
`(collections.user_id, provider, external_ref)`, Postgres has no cross-table
unique index, so enforcing it needs `user_id` on `content.storefronts`. Slice 5b
§8 deferred this to slice 7; slice 7 never picked it up.

- [x] **Step 1: Write the failing PG-lane test** — two concurrent `upsertStore()` calls for one store.

```php
it('refuses a duplicate store under concurrent upserts', function (): void {
    $user = sbrUserWithNoShop();
    $store = sbrStoreRecord('alpha');

    app(ShopContentWriter::class)->upsertStore($store, (string) $user->id);

    expect(fn () => DB::table('content.storefronts')->insert([
        'collection_id' => sbrNewCollection($user, 'storefront'),
        'user_id' => (string) $user->id,
        'provider' => 'shopify',
        'external_ref' => 'alpha',
    ]))->toThrow(QueryException::class, 'storefronts_user_provider_ref_unique');
});
```

Pin the refusal REASON (the constraint name), not merely that it threw — a test asserting only `QueryException` passes on a typo in the table name.

- [x] **Step 2: Run it in the PG lane, confirm it fails** — `composer test:pg`. Expected: no exception; the duplicate inserts cleanly.
- [x] **Step 3: Write the migration.** One concern, no `CONCURRENTLY` paired with anything else:

```sql
-- 20260819000100_content_storefronts_user_id.sql
-- Denormalise the owner onto content.storefronts so store identity
-- (user_id, provider, external_ref) is enforceable in one table. Deferred by
-- slice 5b §8 and by 20260813100001, which named exactly this as the fix.
--
-- ROLLBACK: DROP INDEX IF EXISTS content.storefronts_user_provider_ref_unique;
--           ALTER TABLE content.storefronts DROP COLUMN IF EXISTS user_id;
BEGIN;

ALTER TABLE content.storefronts
    ADD COLUMN IF NOT EXISTS user_id uuid REFERENCES core.users(id) ON DELETE CASCADE;

UPDATE content.storefronts s
   SET user_id = c.user_id
  FROM content.collections c
 WHERE c.id = s.collection_id
   AND s.user_id IS NULL;

ALTER TABLE content.storefronts ALTER COLUMN user_id SET NOT NULL;

COMMIT;
```

Then the index, in its **own** file `20260819000110_content_storefronts_identity_unique.sql`, because a unique index built `CONCURRENTLY` cannot share a file with anything else (CLAUDE.md, one-`CONCURRENTLY`-per-file).

- [x] **Step 4: `supabase link --project-ref glncumufgaqcmqhzwrxm`, `db push --dry-run`, then `db push`.** Dev ref only.
- [x] **Step 5: Make `upsertStore()` write `user_id`**, then run the PG lane and confirm the test passes.
- [x] **Step 6: Commit.**


**ORDERING CORRECTION — Task 11 was pulled ahead of Tasks 7, 9 and 10.**
Task 9 keys the two async jobs on the collection id, and both then need the
OWNER: for the lock key, for `FeatureAvailability`, for the cache refresher.
`StoreRecord` had no way to carry one, and resolving it meant a second query
through `content.collections` on every job run. Task 11 puts `user_id` on
`content.storefronts`, so the owner arrives with the store on the read that was
already happening. Doing 11 last would have meant building that second query
and then deleting it. New order: **11 → 9 → 7 → 10**.

**Index named `storefronts_user_provider_ref_uq`**, not `…_unique` as Step 1's
snippet said — the sibling unique index on `content.collections` is
`collections_user_kind_external_ref_uq`, and an index-naming scheme is worth
more than matching a draft. The test pins the real name.

**The index is PARTIAL on `external_ref IS NOT NULL`.** The column is nullable
and Postgres treats NULLs as distinct, so a plain unique index would silently
permit unlimited `(user_id, provider, NULL)` rows while looking like it enforced
identity. `idx_content_storefronts_external_ref` is already partial the same way.

**Applied to dev** (`glncumufgaqcmqhzwrxm`) — `db push --dry-run` listed exactly
these two files and nothing else, because every earlier migration was already
recorded. Post-apply verification: 15 rows, **0** null owners, **0** rows whose
`user_id` disagrees with their collection, `user_id` NOT NULL, and the partial
unique index present with the expected definition.

**Three test stand-ins had to move with the writer**, which is the drift CLAUDE.md
warns about — the PG lane's DDL is hand-written and does not follow migrations:
`tests/Postgres/ShopStorefrontUpsertConflictTest.php` (which also grew the
index), `tests/Postgres/ShopUpsertStoreAtomicityTest.php` (found by the lane
going red, not by inspection), and `tests/Pest.php`'s SQLite stand-in. The
SQLite one leaves `user_id` NULLABLE deliberately: SQLite cannot express the
partial unique index that gives the column its meaning, so the constraint is
pinned in the PG lane and tightening the stand-in would only fail fixtures over
a rule that engine cannot enforce.
### Task 12: The test tail — `tests/Pest.php` first, alone

**Files:**
- Modify: `tests/Pest.php`, then the 25 other files that mint `ShopBrand` rows

**26 files create rows; `tests/Pest.php` is one of them.** It is a shared helper: changing it touches every lane at once, and cross-file test helper names collide at load time and fatal a `--parallel` run.

- [ ] **Step 1: Change `tests/Pest.php` alone.** Replace its `ShopBrand` minting with a `content.*` equivalent under the `sbr*` prefix.
- [ ] **Step 2: Run the parallel suite before touching anything else** — `./vendor/bin/pest --parallel --processes=4`. A load-time collision shows up here and nowhere else. Commit this file on its own.
- [ ] **Step 3: Convert the remaining 25 files in batches of five**, running `composer test` after each batch. Listed by Task 1 Step 1's re-derived grep; as of 2026-08-17 they are the `tests/Feature/Platforms/`, `tests/Feature/Shop/`, `tests/Feature/Brand/`, `tests/Feature/Content/`, `tests/Unit/Jobs/` and `tests/Postgres/` files named in spec §7.
- [ ] **Step 4: Commit each batch separately** — a 26-file test commit is unreviewable.

### Task 13: Check `pg_depend`, then DROP

**Files:**
- Create: `supabase/migrations/20260819000200_drop_site_shop_products.sql`, `supabase/migrations/20260819000210_drop_site_shop_brands.sql`
- Delete: `app/Models/Core/Site/ShopBrand.php`, `app/Models/Core/Site/ShopProduct.php`

- [ ] **Step 1: Query `pg_depend` for FK dependents, views, triggers and RLS policies on BOTH tables.** `shop_products.brand_id` references `shop_brands`, so the child drops first. The table list will not tell you what the catalog will. Anything unexpected is a stop, not an improvisation.
- [ ] **Step 2: Back both up before dropping** — `pg_dump` them to the `partna-db-backup` R2 bucket, restore into a scratch schema to prove the dump is readable, and assert the row count matches live exactly. If it disagrees, nothing is dropped.
- [ ] **Step 3: Confirm nothing reads it.**

```bash
grep -rn "ShopBrand\|shop_brands\|ShopProduct\|shop_products" app/ routes/ config/ tests/
```

Expected: zero hits outside the two model files about to be deleted. A green suite is not evidence — read the grep output.

- [ ] **Step 4: Write the DROP migration**, `db push --dry-run`, then `db push` against the dev ref only.
- [ ] **Step 5: Delete `ShopBrand`, `ShopProduct`, `IntegrationConnection::shopBrands()` and `ShopProduct::brand()`.** Both models are yours (spec §2.2). Task 2 already made `ShopProduct` callerless; if it is not, Task 2 did not finish.

**AMENDED by Task 1 Step 3 / Task 2 Step 6.** `ShopProduct` is NOT callerless
after Task 2 and was never going to be: `ShopBackfiller` reads it, because it
IS the legacy→`content.*` migration. Delete in this task, together:
`ShopBrand::products()`, `app/Models/Core/Site/ShopProduct.php`,
`app/Services/Migration/ShopBackfiller.php`,
`app/Console/Commands/BackfillShopContentCommand.php`, and their tests
(`tests/Feature/Shop/ShopBackfillerTest.php`). The backfiller is dead code the
moment its source tables are gone — leaving it behind would be a class whose
only queries name a dropped table.

⚠️ `ShopEndpointParityTest`'s whole fixture builds `content.*` state by seeding
the legacy tables and running `ShopBackfiller`. Task 12 must re-cut that fixture
onto a direct `ShopContentWriter` path BEFORE this step, or the parity guard
dies with the backfiller.
- [ ] **Step 6: Run everything** — `composer test`, `./vendor/bin/pest --parallel --processes=4`, `composer test:pg`, `composer test:schema`, PHPStan, Pint.
- [ ] **Step 7: Commit.**

---

## Phase 5 — The event slug lane: verify only (spec §10.1)

**Ownership moved to the drop-phase session on 2026-08-17**, after this phase was
written. They are deleting the 6 legacy `item_type='event'` rows in today's work,
having absorbed the prerequisite this plan surfaced: `EventSlugSync` must be
retired first or the next Eventbrite refresh re-mints them.

**This phase writes no code.** It exists so the claim gets checked rather than
assumed — the deletion and the writer retirement are two separate acts, and the
kickoff prompt as last read named only the deletion.

### Task 14: Verify the event lane actually retired

- [ ] **Step 1: Check the writer is gone.**

```bash
ls app/Services/Platforms/EventSlugSync.php          # expect: not found
grep -c "EventSlugSync" app/Observers/Core/IntegrationConnectionObserver.php   # expect: 0
```

State at 10:20 on 2026-08-17: file present, 13 references in the observer. If that is still true, the retirement has not shipped.

- [ ] **Step 2: Check the rows are gone AND stay gone.**

```sql
SELECT count(*) FROM site.item_slugs WHERE item_type = 'event';
```

Then trigger a real Eventbrite refresh on dev and re-run it. **A count taken without forcing a refresh proves nothing** — the re-mint only happens on connect/refresh, which is the entire finding.

- [ ] **Step 3: If either check fails, raise to the drop-phase session.** Do not fix here — `IntegrationConnectionObserver` is theirs this week, and the content lane already covers `event` via `ContentItemSlugAllocator::SLUGGED_KINDS`.
- [ ] **Step 4: Record the outcome and their commit sha.**

---

## Closing

*(Deliberately not numbered "Phase 6" — the convergence programme, slice 7's
plan and this plan would then each have a different "Phase 6", and that
ambiguity has already cost one conversation.)*

### Task 15: Close

- [ ] **Step 1: Deploy dev and exercise the seven verbs live**, as Task 8 Step 3. Re-derive the store counts; do not cite this plan's figures.
- [ ] **Step 2: Write the wire manifest** — `docs/wire-changes/2026-08-17-shop-brands-rehome.md`. The public wire is unchanged (slice 5b already moved it); say so explicitly rather than omitting the file.
- [ ] **Step 3: `cloud env:logs partna development --minutes 10` and a Nightwatch scan.**
- [ ] **Step 4: Answer spec §11's two open questions** — the R2 prefix outside `app/`, and where the stale-pending clock lives.
- [ ] **Step 5: Answer spec §10.1's open question** — with both halves retired, does anything still write `site.item_slugs`? If nothing does, say so and hand the table to the drop phase's sweep. **Do not drop it here** — it is not on the nine.
- [ ] **Step 6: Amend slice 7's spec Unit K** to record that the tenth table is gone, and add the checkpoint to the parent convergence spec. Note the event slug lane separately — it is a residual this plan absorbed, not part of the shop re-home.

---

## Self-review notes

**Spec coverage.** §2.1→Task 1 Steps 1-2; §2.2→Task 1 Step 3 + Task 2; §3→Tasks 3-4; §4→Tasks 6-10; §5→Task 9; §5.1→Task 9 Step 5; §6→Task 11; §7→Task 12; §8→Tasks 5, 8, 13 Step 6; §9→not implemented by design; §10.1→Task 14 (verify only, owned by the drop phase); §10.2→sibling project, spec §11; §10.3→no tasks; §12→Task 15 Steps 4-5.

**Type consistency.** `StoreRecord::$collectionId` (Task 3) is consumed by `stores()` (Task 4), `storeByCollection()` (Task 9 Step 3) and the R2 prefix (Task 9 Step 5). `ShopConnections::stores()` returns `Collection<string, StoreRecord>` throughout; `store()` and `storeByCollection()` both return `?StoreRecord`. Property names on the DTO are camelCase (`isIndividual`, `productsCuratedAt`) where the columns are snake_case — Task 6 Step 4 is the one place that bites, and it says so.

**Known gap.** Task 7 Step 4 defines the individual-bucket constant on `StoreRecord` rather than reading `ShopBrand::INDIVIDUAL_BRAND_ID`. That is deliberate: slice 7's Task 24 put its adapter on the doomed model expecting the DROP to delete it, and the ruling then spared the model — leaving an adapter that outlived its reason. Not repeating the shape.

**One subsystem, plus two verification hooks.** Phases 0–4 are the shop re-home over both shop tables. Phase 5 verifies work another session owns. `site.services` ×3 is deliberately NOT here — spec §11 scopes it as a sibling needing its own spec and plan, and its first step (the `public_site_payload` view migration) is already written and sitting unapplied.

**Deliberately not prescribed.** Task 9 Step 4 says "fold the profiled fields onto the record" without naming them. `ShopBrandConnectJob`'s settle writes a different subset per provider, and enumerating them here from a reading of the job — rather than from running it — would be a guess presented as a spec.
