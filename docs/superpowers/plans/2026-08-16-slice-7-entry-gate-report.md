# Slice 7 — entry gate report (2026-08-16)

Kickoff: `2026-08-12-slice-7-teardown-KICKOFF-PROMPT.md`, process step 1.
Parent spec: `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md`.
Run against **dev** (`glncumufgaqcmqhzwrxm`) on 2026-08-16. Production not touched.

**VERDICT: gate 1 (coverage) PASSES. The teardown is BLOCKED anyway.**

Two live write lanes into tables on the drop list were never repointed by their
owning slices. Per the kickoff's divergence table — *"Something still reads a
table you are about to drop → **STOP.** The owning slice repoints it"* — this is
a hard stop, not an edit and not a scope extension for slice 7 to absorb.

No `DROP` has been written, no migration authored, no backup taken. Nothing in
this slice has been executed beyond read-only verification.

---

## 1. Gate 1 — coverage. GREEN, re-derived here, not cited

Rule zero: every assertion below was re-run against dev by this session. No
slice's checkpoint is cited as evidence.

### 1.1 Legacy row counts, live

| Table | Live rows | §1.4 says | Delta |
|---|---|---|---|
| `site.menu_items` | 318 | 318 | — |
| `site.menu_item_categories` | 402 | 402 | — |
| `site.menu_item_platforms` | 318 | 318 | — |
| `site.menu_categories` | 44 | 44 | — |
| `site.services` | 82 | 82 | — |
| `site.service_category_assignments` | 61 | 61 | — |
| `site.service_categories` | 18 | 18 | — |
| `site.shop_products` | 51 | 51 | — |
| `site.shop_brands` | 9 | 9 | — |
| `site.content_selection` | **95** | 91 | **+4** |

`site.content_selection` is the only mover. Slice 1b's checkpoint (§15.2)
recorded 94 rows immediately after its additive migration; it is now 95.
Composition: 85 `google-photo` + 4 `ig-post` + 3 `ig-reel` + 3 `upload`. Against
1b's 85 + 6 `ig-*` + 3 uploads, the growth is **one new `ig-*` row**, written
2026-08-13 13:55:05 UTC on `ollies` — part of an 8-row re-save of that site's
selection *after* the migration ran.

That is not a coverage failure — the 3 uploads are still 3/3 covered and pinned.
It is evidence that **`site.content_selection` is a live write target**, not an
inert legacy table. See §2.3. The parent spec's §1.4 figure of 91 is stale and is
corrected in place.

### 1.2 The content side is populated

```
content.items (removed_at IS NULL), by kind
  menu_item 348 | release 224 | video 135 | episode 115 | service 77
  media 77 | track 75 | product 51 | link 28 | review 20 | event 13

content.offers            1453
content.collections         80   (menu_category 50, service_category 16,
                                  storefront 9, order_platform 5)
content.collection_items   860   (menu_category 432, order_platform 318,
                                  service_category 59, storefront 51)
content.item_slugs         365   >= site.item_slugs menu_item 318  ✓
content.storefronts         14
content.source_stats         1
content.item_merges          0
```

### 1.3 Coverage per legacy type — every legacy LIVE row has a live coord

**Menus.** Coord `manual:menu:{menu_id}:{sha1(normalised name)}`, re-derived in
SQL with `pgcrypto` mirroring the app's normalisation:

```
legacy dishes            318
expected coords          318
landed coords            348
expected NOT landed        0   ← the gate, green
landed NOT expected       30
```

The 30 extras are Phase 5's DoorDash connector items (`coord` scheme `doordash`,
source kind `connection`), not orphans. Item-level excess is expected under
§8.4 — the gate is coverage, not equality.

**Menu memberships.** Every one of the 402 `site.menu_item_categories` pairs
resolves to a `content.collection_items` row on a `menu_category` collection with
the matching label: **402 / 402.**

**Menu categories.** 44 / 44 covered by (user, label) on `kind='menu_category'`.

**Menu platforms.** 318 legacy `menu_item_platforms` ↔ 318 `order_platform`
collection_items. Exact.

**Services — owner half.** Coord `manual:{legacy service uuid}`:
**21 / 21** covered (18 live + 3 retired).

**Services — Fresha half.** Coord `fresha:{acct}:{external_id}`, scoped to the
same `user_id`:

```
legacy fresha rows       61
covered                  59   ← all 59 LIVE rows
uncovered                 2
```

Both uncovered rows are soft-deleted (`deleted_at = 2026-08-04 08:28:08+00`):
`$80 Barber Membership Mens Cut` and `$80 Barber Membership Fade` on `ollies`.
Deleted rows are outside the gate. **Live coverage is 59/59.**

**Service categories.** 16 live legacy rows, **16 / 16** covered. The other 2 are
owner-authored rows soft-deleted 2026-07-17 (`Haircuts`, `Haircuts-raw-test`).
Worth stating plainly because the raw counts invert and read alarming:
`site.service_categories` = 18 vs `content.collections kind='service_category'`
= 16. The 2-row gap is *entirely* the soft-deleted pair, not a migration hole.

**Service category assignments.** 61 legacy, 59 `collection_items`. The 2
missing assignments both point at the two soft-deleted Fresha services above.
Consistent, not a hole.

**Products.** Coord `manual:{sha1(url)}` (`ShopProductProjection::coordFor`).
All 51 legacy rows carry a non-empty `data->>'url'`, and **51 / 51** hash to a
landed coord. No urlless products on dev, so `coordForProductId()` is
**unexercised by real data** — stated rather than assumed to work.

**Brands.** 9 legacy ↔ 9 `content.collections kind='storefront'` ↔ 9
`content.storefronts`. Exact.

**Content selection.** 3 `upload` rows → 3 covered items → 3 live pins. The 92
non-upload rows are the deliberately-dropped set recorded in checkpoint §15
(then 91, now 92 — see §1.1).

### 1.4 The slug 301 lane survived slice 4

```
site.item_slugs     menu_item 318 (retired 0) | event 11 (retired 0)
content.item_slugs  365
```

365 ≥ 318. The 11 legacy `event` rows are slice 7's to delete, as §9.3 says.

---

## 2. Gate 3 — "nothing still reads the legacy tables". **FAILS on two lanes.**

The kickoff's entry gate item 3 is *"Nothing still reads the legacy tables —
grep the codebase; a green suite is not evidence."* It does not hold. Neither
finding is a stale comment or a dead code path; both were confirmed against
`routes/` and `php artisan route:list`.

### 2.1 STOP — the entire legacy menu lane was never repointed. Owner: slice 4.

Slice 4 migrated menu **data** into `content.*` and stood the pool up beside the
legacy wire. Its own spec §14 says so explicitly: *"Dropping any legacy table.
Slice 7 owns that; the menu tables stay."* What it did **not** do — and did not
claim to do — is move any **write or read path** off the four menu tables.

**14 live routes read or write the legacy menu tables today:**

```
GET    api/public/profiles/{handle}/menu        PublicMenuController@show      ← PUBLIC WIRE
GET    api/platforms/menu                       MenuController@show
GET    api/platforms/menu/status                MenuController@status
POST   api/platforms/menu/refresh               MenuController@refresh
POST   api/platforms/menu/scan/apply            MenuController@applyScan
POST   api/platforms/menu/categories            MenuContentController@createCategory
POST   api/platforms/menu/categories/reorder    MenuContentController@reorderCategories
PATCH  api/platforms/menu/categories/{category} MenuContentController@updateCategory
DELETE api/platforms/menu/categories/{category} MenuContentController@deleteCategory
POST   api/platforms/menu/items                 MenuContentController@createItem
POST   api/platforms/menu/items/reorder         MenuContentController@reorderItems
POST   api/platforms/menu/items/bulk-delete     MenuContentController@bulkDeleteItems
PATCH  api/platforms/menu/items/{item}          MenuContentController@updateItem
DELETE api/platforms/menu/items/{item}          MenuContentController@deleteItem
```

Plus two background writers: `MenuFetchJob` (the UberEats/DoorDash scrape lane,
~15 separate statements against `site.menu_items` / `menu_item_categories`) and
`MenuScanApplier` (the photo/PDF scan apply lane).

**There is no dual-write.** Grepping `MenuContentController`, `MenuController`,
`MenuScanApplier`, `MenuPayloadComposer` and `MenuFetchJob` for `content.`,
`ProjectionWriter`, `ManualServiceWriter` or `ContentItemSlugAllocator` returns
**zero** hits — one comment in `MenuFetchJob:109` containing the word "content",
and nothing else.

The consequence is worse than 14 broken routes. The **only** ongoing writer of
the 318 migrated `menu_item` coords is `content:backfill-menus`, a one-shot
command that reads `site.menu_items`. Drop the table and 288 of the 318 dishes
(all but Phase 5's 30 DoorDash items) have **no writer at all** — `pools.menus`
freezes permanently at its 2026-08-15 snapshot while the owner's dashboard 500s.

Repointing this is a slice, not a unit: a public wire change with its own
manifest, an owner-authoring surface, a scrape lane and a scan lane. It is
slice 4's to do, and slice 4 declared it out of scope.

### 2.2 STOP — `site.services` is still the live Fresha write target. Owner: slice 3b.

`App\Services\Platforms\FreshaServiceProjector` (the **legacy** projector, not
`App\Ingest\Projection\FreshaServiceProjector`) writes `site.services` rows with
`source='fresha'`, and composes `payload.selection` back out of them. It is
wired into:

- `FreshaController` — `connect()` (storewide branch, `:147`) and the
  selection-save path (`:484`)
- `UserServiceController` — `:362` (`refreshBlob`), `:524`
- `PlatformRegistryServiceProvider:338`
- `ManagesIntegrationConnection` — its advisory-lock timeouts are sized around
  this projector's runtime

The blob it composes is **on the public wire**:
`PublicIntegrationConnectionResource:111` allowlists `'fresha' => ['url',
'selection']`.

Slice 3b cut the service **read** surfaces (18 owner routes, 2 staff
controllers) onto `content.*`. It did not retire this writer, and its carry-over
list to slice 7 does not name it. Dropping `site.services` breaks Fresha
connect, refresh, selection-save, and the public booking blob.

### 2.3 In scope, but larger than the kickoff states

Not stops — recording them so the scope is not rediscovered mid-teardown.

- **`site.content_selection` is a live write target.** `PUT /api/content/selection`,
  `PUT /api/content/instagram-auto`, `PUT /api/content/google-photos` and
  `GET /api/content/selection` all run through `ContentSelectionService`, and
  the public profile payload reads it via `SitepageDataResolverService` →
  `IndividualProfilePayloadBuilder`. Unit 6 covers deleting the wire keys under
  the 2026-08-14 owner override; it does not mention that **four owner routes**
  must be retired or repointed onto `pool:media` pins as well.
- **Legacy assignment sync on the reorder verbs.** `UserServiceController:1121-1134`
  and `StaffServiceManagementController:612-625` both maintain
  `site.service_category_assignments` by hand for the Fresha subset, alongside
  the `content.*` `collections->reposition()` call. Same shape as the staff
  category `index` merge that 3b explicitly handed over — small, and slice 7's.
- **The standalone-events four-step is entirely undone.** Dev carries **2 live
  standalone `event` rows** (`eventbrite`, `resource_kind='event'`,
  `deleted_at IS NULL`) — the same 2 slice 2 measured. None of §7's four steps
  has been started. The kickoff assigns them to this slice, so this is scope,
  not a stop, but it is slice-sized work sitting inside a slice sized "M".

### 2.4 What is genuinely clear

- **The five §9.4 observers are all registered** — `ServiceObserver`,
  `ServiceCategoryObserver`, `SiteMediaObserver` and `MenuObserver` in
  `EventServiceProvider:39-45`, `MenuItemObserver` via `#[ObservedBy]` on the
  model. No "moved but never re-registered" case was found. `ServiceObserver`'s
  `reevaluateBooking()` duty is mirrored on the `content.*` paths
  (`UserServiceController:1444`, `StaffServiceManagementController:927`).
- **`site.shop_products` is inert** for reads — the public shop render goes
  through `PoolResolver` / `ShopContentReader` on `content.*`.
- **`site.shop_brands` is live**, exactly as the kickoff's 2026-08-13 correction
  says, and re-homing `ShopContentWriter` off the `ShopBrand` model is already
  named as this unit's work.

---

## 3. What this slice did NOT do

No spec, no plan, no worktree, no migration, no backup, no code change. Step 1
of the kickoff's process is a stop, and it stopped. The `pg_dump` gate is not
worth spending against a teardown that cannot proceed.

## 4. What unblocks it

Two slices reopen. Neither is slice 7's to write:

1. **Slice 4 (menus)** — move the 14 routes, `MenuFetchJob` and
   `MenuScanApplier` onto `content.*`, with a wire manifest for the public
   `/menu` endpoint.
2. **Slice 3b (services)** — retire `App\Services\Platforms\FreshaServiceProjector`,
   composing `payload.selection` from `content.*` instead, or retire the blob.

Then slice 7 re-runs this entry gate from scratch — including §1, because these
figures will be stale by then — and proceeds to the backup and the DROPs.

Reference figures above are 2026-08-16 dev. They are a snapshot, not a licence
to skip re-derivation. Invariant #5 applies to this document too.
