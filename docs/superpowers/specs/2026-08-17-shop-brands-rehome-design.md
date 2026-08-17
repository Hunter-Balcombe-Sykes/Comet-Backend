# `site.shop_brands` re-home — design

**Status:** specced, not started. Follow-up to slice 7 (legacy teardown).

**Scope corrected 2026-08-17 (second revision).** The first revision recorded the
owner ruling as *"nine tables, not ten"*. The drop-phase session then sized the
rest and the real number is **five**: the four menu tables and
`site.content_selection`. Three more deferred alongside `site.shop_brands`, and
all four land in this follow-up:

| Deferred table | Why it could not drop |
|---|---|
| `site.shop_brands` | The READ lane. `ShopConnections::brands()` is a live `ShopBrand::query()`; five `ShopController` endpoints resolve stores through it. |
| `site.shop_products` | Entangled with the above. `ShopBrand::toBrandArray()` materialises `$this->products` on the catalog re-warm endpoint and in `ShopCatalog::syncLatest()` — the same files this project owns. |
| `site.services` ×3 (`services`, `service_categories`, `service_category_assignments`) | The Fresha half was never cut over to `content.*`. ~30 live query sites across five files, and the code says so outright: *"TWO id spaces are live during the transition."* Dropping them `42P01`s the services list and the booking surface. |

**The services half needs its own spec and plan** — see §11. It is deferred to
the same window, not to the same document.

**Dev only. Production is out of scope.**

**Dev only. Production is out of scope**, for the same reasons slice 7 gave:
prod is hundreds of commits behind, the schemas have diverged from the
2026-07-26 baseline, and prod carries no customer data. The prod-reconciliation
write-up is slice 7's closing deliverable, not this one's.

---

## 1. Why this exists

Slice 7 set out to retire the legacy content lane by dropping ten tables. Five
went — the four menu tables and `site.content_selection`. `site.shop_brands` did
not, because it has a materially bigger tail than any other table on the list
and the blocker is **the read lane, not the writer**.

Task 24 of slice 7 succeeded: `ShopContentWriter` no longer touches
`site.shop_brands` at all. `upsertStore()`, `collectionIdFor()` and
`isCurated()` take `App\Services\Shop\StoreRecord` — a readonly DTO whose
identity is `(provider, externalRef)`, both `content.storefronts` columns.

Task 24 **Step 5 could not follow**. `ShopConnections::brands()` is still a live
`ShopBrand::query()`, and five `ShopController` endpoints resolve stores through
it. Remove the writes alone and every store minted after the deploy is invisible
to `connectStatus`, `updateBrand`, `removeBrand`, `setProducts` and
`removeProduct` — all five 404 on the miss. The writes and the reads are one
unit of work; slice 7 correctly refused to split them.

**This document is that unit.**

---

## 2. Entry gate — verify what slice 7 actually left

Slice 7 was in flight across fourteen worktrees when this was written. Every
figure below is 2026-08-17 and **is a snapshot, not a licence to skip
re-derivation**. Nothing here starts until §2.1 and §2.2 have been re-run and
their answers written down.

### 2.1 Has slice 7 already done some of this?

Task 24's blocked Step 5 may have been picked up inside slice 7 after this spec
was written. Re-derive, do not assume:

```bash
# The read lane — is it still ShopBrand::query()?
grep -n "ShopBrand::query()" app/Services/Shop/ShopConnections.php

# Every remaining touch in app/
grep -rln "ShopBrand" app/ | sort

# What slice 7 changed in the shop family
git log --oneline development..feat/slice-7-teardown -- \
  app/Http/Controllers/Api/Platforms/ShopController.php \
  app/Services/Shop/ app/Jobs/Platforms/Shop*.php
```

Anything already done is ticked with its evidence, not redone. Anything the
inventory in §4 names that the grep no longer finds is **struck from the
inventory with a note**, not silently dropped.

### 2.2 `site.shop_products` — in scope, not a residue

**Reversed twice on 2026-08-17. This is the settled position.** First it was
slice 7's (it was on their drop list). Then the drop-phase session took its call
sites (§4d of their kickoff). Then that session sized the whole thing and handed
the table back, because the entanglement runs the wrong way:

`ShopBrand::toBrandArray()` materialises `$this->products` on the catalog
re-warm endpoint and in `ShopCatalog::syncLatest()`. Those are the same files
this project rewrites. Dropping `shop_products` on its own means editing
`ShopBrand` — the deferred table's own model — which is not a drop, it is the
first half of this re-home done by someone else.

**So `site.shop_products` drops here, with `site.shop_brands`.** Both models die
together in the final task.

Live touches, verified 2026-08-17 — re-derive before acting:

| Touch | Location |
|---|---|
| `ShopProduct::...->delete()` ×4 | `ShopController:772, 985, 1113, 1141` |
| `->with('products')` ×3 | `ShopController:881, 895, 1291` |
| `?? …toBrandArray()` fallback ×3 | `ShopController:468, 580, 664` |
| `products()` HasMany, `toBrandArray()` reading `$this->products` | `ShopBrand:114-117, 171-175` |

`ShopCatalog::syncLatest()`'s `loadMissing('products')` is the fifth site.
`ShopProductSeeder` was already repointed by slice 5a and says so in its own
comment — not part of this.

**The drop-phase session's §4d resolution is still the right shape and this plan
adopts it**: delete the three `?? …toBrandArray()` fallbacks first (`brandMap()`
is authoritative and the controller's own comment at `:465-466` calls the legacy
arm *"a theoretical read-your-own-write miss"*), which leaves `products()` and
`toBrandArray()` callerless, and only then delete the members and the model.

---

## 3. What the re-home replaces, and what already exists

Most of the destination is built. This is mechanical work, not a design problem.

**`StoreRecord::fromStorefrontRow()` already exists** (`app/Services/Shop/StoreRecord.php`)
and is documented as *"the post-DROP read direction"* — it rebuilds a store from
a `content.storefronts` row joined to its `content.collections` parent, applying
the same `label === external_ref → null` rule the reader uses.

**`ShopContentReader::brandMap(User $user, ?array $productRanks = null): array`**
already reconstructs the exact `toBrandArray()` shape from `content.*`, ordered
by `c.position` then `s.external_ref` to match `IntegrationConnection::shopBrands()`.
`ShopController::addBrand` already builds its response from it, with
`$brandRow->fresh('products')->toBrandArray()` as a fallback — **that fallback is
one of the things that dies here.**

**`content.storefronts` already carries every column that matters**:
`provider`, `url`, `source_url`, `currency`, `discount_code`, `referral_query`,
`is_individual`, `fetch_mode`, `connect_status`, `connect_error`,
`products_curated_at`, `logo_url`, `favicon_url`, `logo_mark_url`,
`logo_mark_svg_url`, `external_ref`. `name` and `position` live on the parent
`content.collections` row as `label` and `position`.

**Three legacy columns are already dead** — `style_analysis`, `selection_mode`
and `link_mode`. Nothing in `app/` reads them. They need no home.

---

## 4. Inventory — 17 production files

Verified 2026-08-17 against `feat/slice-7-teardown`. Re-derive per §2.1.

| File | Touches | What it needs |
|---|---|---|
| `ShopConnections::brands()`, `::brand()` | 2 | The whole read lane. Returns `Builder<ShopBrand>` today; must return `StoreRecord`s built from `content.*`. |
| `ShopController` | 7 | Five endpoints resolve through `brands()`/`brand()`; `addBrand` writes `new ShopBrand`, `addProduct` `firstOrCreate`s the individual bucket, `removeBrand`/`forget`/`removeProduct` delete. |
| `ShopBrandConnectJob` | 4 | Keys the deferred-connect settle on the `site.shop_brands` uuid PK. |
| `ProcessShopBrandLogoJob` | 2 | Same uuid key, plus the R2 prefix — see §5. |
| `ShopCatalog` | 2 | `ShopBrand` type hint on `syncLatest()`, `loadMissing('products')`. |
| `ShopBrandProfiler::forRow()` | 1 | `ShopBrand` type hint; reads `provider`, `url`, `source_url` — all on `StoreRecord`. |
| `ShopProductSeeder` | 1 | Type hint only; already writes `content.*`. |
| `StoreBrandSeeder` | 5 | Pre-account seeding lane. |
| `StoreBrandProfiler` | 2 | Pre-account seeding lane. |
| `BrandAssetPipeline` | 1 | Pre-account seeding lane. |
| `IntegrationConnection::shopBrands()`, `ShopProduct::brand()` | 2 | Relations; die with the models. |
| `ShopBrand`, `ShopProduct` models | — | Deleted last. |

Plus `ShopBackfiller` and `PseudoPlatformRetirer`, which read the legacy tables
by design and are deleted by slice 7's Task 27 Step 5 regardless.

---

## 5. The two jobs are the only real design question

`ShopBrandConnectJob` and `ProcessShopBrandLogoJob` are dispatched with
`(string) $brandRow->id` — the `site.shop_brands` uuid primary key. **That uuid
has no `content.*` twin.** `content.storefronts` is keyed by `collection_id`
(itself the `content.collections` PK), and identity is
`(user_id, provider, external_ref)`.

**Decision: the collection id replaces the brand-row uuid.** It is the natural
substitute — one per store, stable, already the storefront's PK.

`StoreRecord` gains a nullable `collectionId`, populated by
`fromStorefrontRow()` and **never** passed into `upsertStore()`, where the
collection id is the output rather than the input.

### 5.1 The R2 prefix moves, and old objects stay put

`ProcessShopBrandLogoJob` writes under `"shop-brands/{$brand->id}"`. Keying on
the collection id changes that prefix for every new write.

**Existing objects are not orphaned in any way a user can see**: the processed
URLs are stored absolute in `logo_mark_url` / `logo_mark_svg_url` and keep
resolving. What changes is that prefix-scan tooling keyed on collection ids will
not find pre-migration objects. Accepted deliberately; recorded here so a later
bucket-audit does not read it as loss. Do **not** migrate the objects — a
re-process on next connect rewrites them under the new prefix for free.

---

## 6. `upsertStore()`'s TOCTOU race — folded in here

Slice 5b §8 deferred this to slice 7; slice 7's spec and plan never picked it
up. It belongs here, because it is the same file family and the fix is a schema
change on `content.storefronts`.

`20260813100001_content_storefronts_external_ref.sql` states the position
exactly: true identity is `(content.collections.user_id, provider,
external_ref)`; `user_id` lives one table over; Postgres has no cross-table
unique index; enforcing it means denormalising `user_id` onto
`content.storefronts`. That was out of scope for a fix about key *correctness*.
It is in scope for a project that removes the last legacy fallback.

**Decision: denormalise `user_id` onto `content.storefronts` behind a unique
index on `(user_id, provider, external_ref)`.** With the legacy table gone,
`upsertStore()` is the sole writer and an application-level lookup is the only
thing standing between a concurrent scheduled sync and a duplicated store —
the same fault that minted 18 collections for 9 stores during slice 5a.

---

## 7. The test tail

- **42 test files / 317 references** to `ShopBrand`.
- **26 of those create rows.**
- **`tests/Pest.php` is one of them.** A shared helper — changing it touches
  every lane at once, and cross-file test helper names collide at load time and
  fatal a `--parallel` run. Change it first, alone, and run the parallel suite
  before touching anything else.

---

## 8. Verification

- `composer test` (serial) **and** `pest --parallel --processes=4`.
- `composer test:pg` — `ShopStorefrontUpsertConflictTest` and
  `ShopUpsertStoreAtomicityTest` both live in that lane and both mint
  `ShopBrand` rows.
- `composer test:schema` — the unique index in §6 is applied-schema work.
- PHPStan at `php -d memory_limit=1G ./vendor/bin/phpstan analyse` (the default
  invocation OOMs and reports it as "severe errors"), then `php artisan pint`.
- Live on dev: connect a store, rename it, curate products, remove it. Assert
  `content.*` moved and that no query names `shop_brands` (`DB::listen()`).
- Post-deploy: `cloud env:logs partna development --minutes 10` **and** a
  Nightwatch scan.

---

## 9. Out of scope

- **Production.** As slice 7.
- **The five tables the drop phase drops** — the four menu tables and `site.content_selection`. Not this project's, in either direction.
- **`site.services` ×3.** Same window, different project. Spec §11.
- **`ShopBrandIdentity`.** It derives a brand id from a URL and never touches
  the table. Untouched.
- **The public shop wire.** Already `content.*` end to end since slice 5b.
  Nothing here changes `profile.pools.shop`.

---

## 10. Slice-7 residual register

Added 2026-08-17 by owner instruction, from the drop-phase sweep. These were
deferred alongside `site.shop_brands` and had no other home.

**Scope note, stated once and not laboured:** the event slug lane below is a
different subsystem from the shop re-home. It is folded in because it is a
deferred residual with no owner, not because it belongs with shop work. If it
grows past its two tasks, split it into its own plan rather than letting this
one become the residual bucket.

### 10.1 `site.item_slugs`, event lane — the drop phase owns this

**Ownership moved 2026-08-17, after this section was first written.** The
drop-phase session is deleting the 6 legacy `item_type='event'` rows in today's
work, having absorbed the prerequisite: `EventSlugSync` must be retired first or
the next Eventbrite refresh re-mints them.

The finding stands exactly as first recorded, and is worth keeping because it is
the reason the deletion is not a one-liner:

`EventSlugSync` writes the legacy table through `ItemSlugAllocator`, called from
`IntegrationConnectionObserver` on every connect and refresh — `:200-201`
(`syncEvents`), `:267` (`retireEvents`), `:293-298` (the sibling reconcile) — for
`PLATFORMS = ['eventbrite', 'humanitix', 'events-custom']`.

The content side was already done: `ContentItemSlugAllocator::SLUGGED_KINDS` is
`['event', 'menu_item']` (`:55`) and `ProjectionWriter:1640-1652` is the ongoing
minter. Its comment names the situation outright —

> *"THIS is the ongoing minter for `content.item_slugs` … The **retired lane**
> had the same continuous duty."*

— while the observer was still calling that "retired" lane. Only the writer was
never switched off.

**State when last checked (10:20, 2026-08-17):** `EventSlugSync.php` still
exists and the observer still holds 13 references to it; the drop-phase kickoff
still says *"delete the 11 legacy `item_type='event'` rows"* with no mention of
the writer. **Verify before assuming it shipped** — if the rows are gone and
`EventSlugSync` is still wired, they will come back.

**No tasks in this plan.** Phase 5 is verification only.

### 10.2 `site.services` ×3 — a sibling project, not a section here

Deferred to the same window as the shop tables and owned by the same follow-up
effort, but **it needs its own spec and plan**. Recorded here so it is not lost;
scoped in §11 so nobody folds it in by accident.

### 10.3 Verified clean — checked, not assumed

| Table | Finding |
|---|---|
| `site.content_selection` | Fully retired, and **dropped by the drop phase today**. Every remaining mention is a comment or the migrator their step 7 deletes. |
| Menu tables ×4 | Write lane genuinely cut over; **dropped by the drop phase today**. The leftover `->save()`s hit `site.menus` and `site.menu_platform_links`, which survive by design. |

---

## 11. The services half — scoped, not planned

**Do not fold this into the shop plan.** It is a third subsystem, and larger
than the shop half.

**Why it could not drop:** the Fresha half was never cut over to `content.*`.
The code states the condition plainly — *"TWO id spaces are live during the
transition."* Dropping the three tables `42P01`s the services list and the
booking surface.

**Surface, verified 2026-08-17** — ~30 live query sites (77 raw references
including docblocks) across five files:

| File | Note |
|---|---|
| `app/Services/Cache/UserCacheService.php` | `Service::query()` at `:230` — the PUBLIC "active services" read, `WHERE source IS NOT NULL` (Fresha). Not the dashboard list. |
| `UserServiceController.php` | 43 references. Legacy ids stay addressable by design (§C2, slice 3a). |
| `StaffServiceManagementController.php` | 18. Its header documents the dual-id-space rule. |
| `StaffServiceCategoryManagementController.php` | 9, incl. `destroyLegacy()`'s raw detach. |
| `FreshaController.php` | 3. |

**Its first step is already written and sitting unapplied.**
`supabase/migrations/20260817000000_public_site_payload_services_from_content.sql`
(19KB, authored 2026-08-17 00:35) recomposes the `site.public_site_payload`
VIEW's `services` key off `content.*` — the Task 26a blocker. It exists ONLY on
`feat/slice-7-teardown`, not on `development`, and is deliberately **unapplied
on dev** because it matters only for the services drop.

> ⚠️ **Hazard: a future `supabase db push` picks it up.** It is a real file in
> `supabase/migrations/`, so any unrelated push against the dev ref applies it.
> Treat it as the services follow-up's step 1, and until that project starts,
> know that it is armed.

**Kickoff prompt written 2026-08-17:**
`docs/superpowers/plans/2026-08-17-services-cutover-KICKOFF-PROMPT.md`. It
produces the spec and plan; it writes no code.

**Also carried:** slice 7's Task 11 (Fresha `payload.selection` composed from
`content.*` via `FreshaServiceItems::selectionServices()`) is written in detail
in `2026-08-12-slice-7-teardown.md` with seven steps and one already-corrected
class name. That plan text is reusable — lift it rather than re-deriving it.

## 12. Open — needs an answer before the DROP, not before starting

1. **Does anything outside `app/` reference the `shop-brands/<uuid>` R2 prefix?**
   §5.1 assumes not. Check the Worker and any bucket-lifecycle rules.
2. **`connectStatus()`'s stale-pending clock reads `updated_at` on the legacy
   row** (`addBrand` calls `->touch()` explicitly to force it). `content.storefronts`
   has its own `updated_at`, but `upsertStore()` writing byte-identical values
   will not bump it either — the same defect the legacy path already patched
   once. Decide whether the clock moves to the storefront row or to
   `StrandedPendingWindow`.
