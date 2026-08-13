# Wire change — slice 3b, Fresha services (2026-08-13)

Backend-only execution. Spec: `docs/superpowers/specs/2026-08-13-slice-3b-services-fresha-design.md`.
The Fresha half of services — 59 scraped services and 16 categories on dev —
moves onto `content.*`, completing the cutover 3a started. `site.services`,
`site.service_categories` and `site.service_category_assignments` are **not**
dropped; that is slice 7.

Verified live on dev 2026-08-13 before merge (parent spec §19).

## Five changes a consumer can see

Most of this slice is a store move behind unchanged shapes. Five things are not.

1. **`FreshaSelectionResource.services[]` is now a fixed nine-key shape.**
2. **A connection with a populated selection blob but no `content.*` rows renders an EMPTY booking menu.**
3. **The public services payload's `category` is no longer the constant `'Services'`.**
4. **`ServiceCategoryResource.source` derives from `is_user_created`, not a model column.**
5. **Two `category_ids` on one write now 422** (decided 2026-08-14; this one is
   breaking — it previously returned 200).

Each is set out below.

---

## 1. `FreshaSelectionResource.services[]` — fixed nine keys

**Consuming repo: Partna-App** (`GET /api/platforms/fresha/selection`, and the
descriptor-driven `GET /api/platforms/{platform}/selection` via
`GenericPlatformController::shape()`).

**Before.** The stored selection blob was passed through verbatim. An entry
carried whatever keys Fresha happened to include on the day it was saved — two,
nine, twelve. The resource deliberately did not allowlist them.

**After.** Each entry is reproduced from `content.*` by
`FreshaServiceItems::selectionServices()` as exactly these nine keys, in this
order:

```
name, price, category, currency, duration, serviceId, priceValue,
description, hasVariants
```

**A consumer relying on a key outside the nine loses it.** The nine were chosen
because they are the keys the stored blobs on dev actually carry; nothing else
was observed. `hasVariants` is always `false` — the connector and projector do
not capture a variant count, so it renders its safe default rather than a
guess, matching the projector's never-fabricate rule.

Round-trip rules that did **not** change: `price` is still the vendor's own
display string reconstructed from `qualifier` + `amount_minor` (`"from $108"`,
`"$120"`, `"free"`), cents rendered only when non-zero (`4950` → `"$49.50"`,
`12000` → `"$120"`); the `$` stays bare and `currency` stays `null` rather than
guessing AUD.

`hiddenServiceIds` is unchanged and remains a **sibling key**. Hidden services
still appear in `services[]` — the dashboard needs them present to render the
un-hide affordance, and filtering them here would break it. Hiding is the
consumer's job, as it always was.

Ordering is now deterministic. One ingest batch stamps one `first_seen_at`
across every row, so the previous untied sort could shuffle the booking menu
between requests; `si.id` is the tiebreak.

## 2. An empty booking menu between deploy and the first connector run

**Intended, and the reason the slice exists.** The pool is now the source of
truth for this surface. A connection whose stored blob is populated but which
has no `content.*` service items yet renders `services: []`.

Serving the blob instead would serve stale prices, which is precisely the
defect this slice removes: spec §1.4 measured 22 of 23 prices understated on
one salon, and a $360 service published at $180 on another.

**The window is real.** Between deploying 3b to an environment and that
environment's connector completing its first successful run *per connection*,
an existing Fresha connection shows no services.

**Mitigation, and it is a deploy step, not an optimisation.**
`ingest.sources.selection_ref` is populated by `SourceProvisioner::sync()`.
The column ships NULL, and a source with `selection_ref = NULL` lands nothing
by design. On dev, all four Fresha sources were NULL after the migration and
the first run would have landed nothing everywhere. **Sync must be run for each
Fresha connection after deploy, before the first scheduled run matters.**

Do **not** reach for `ingest:backfill-sources` unqualified: its dry-run on dev
showed it would process **80 connections across every connector**, bumping
`next_attempt_at` on unrelated sources. Scope the sync to the Fresha
connections that need it.

Production carries no customer data (`core.users` = 0), so the window is a dev
and future-pilot concern, not a live outage.

## 3. The public services payload's `category`

**Consuming repo: partna-monorepo** (`@partnaau/design-system`), via
`GET /api/public/profiles/{handle}` → services section.

**Before.** `ManualServiceItems::publicList()` emitted the hardcoded string
`'Services'` for every row — honest only while `content.*` carried no
membership concept.

**After.** It emits the item's real collection label, falling back to
`'Services'` when the item belongs to no collection. The field's type and
presence are unchanged; its *values* now vary.

A consumer grouping or filtering on the literal `'Services'` will see the group
split.

## 4. `ServiceCategoryResource.source`

**Consuming repo: Partna-App.** Emitted by every `/service-categories/*` route
on both the owner and staff surfaces.

**Values are identical; the origin is not.** Before, `source` was read off the
legacy `site.service_categories.source` column. After, for a
`content.collections` row it is derived: `is_user_created === false → 'fresha'`,
`true → null`. Same vocabulary, same meaning — synced versus editable — so the
dashboard needs no change.

The identity comparison is load-bearing and the class says so: PDO_PGSQL hands
booleans back as `"t"`/`"f"` strings, and `"f"` is truthy. `ServiceCollections`
normalises on the way out of every row-returning method. A loose check here
would have called every Fresha category owner-made on real Postgres and never
on the SQLite test lane.

The staff routes still hand the resource a legacy Eloquent `ServiceCategory` in
one place; the resource emits the identical field list either way. Two backing
stores, one wire shape.

`created_at` / `updated_at` are now selected and populated. They were briefly
`null` on every category during the cutover — the same silent regression class
as `source`, caught and fixed before merge.

## 5. Two `category_ids` now 422 — **breaking, both surfaces**

**Consuming repos: Partna-App** (owner and staff service routes) **and
partna-monorepo** (anything writing service memberships).

**Decided 2026-08-14, after this slice shipped.** Slice 3b left this open and
pinned the existing behaviour; the decision has since been made to reject.

**Before.** `ServiceCollections::assign()` is single-collection per source by
design, so a payload carrying two `category_ids` stored the **first** and
returned **HTTP 200** — the second id discarded, with no error and no warning.

**After.** A write carrying more than one `category_id` returns **422** with a
`category_ids` validation error: *"A service belongs to one category. Send a
single category_id."* Nothing is written — it is a refusal, not a partial apply.

**A client sending two ids gets a 422 where it previously got a 200.** If any
dashboard path sends the full membership array back on save, it must send at
most one entry.

Unchanged: the legacy singular `category_id` spelling; an explicit `null`
(move to Uncategorized); and an **empty** `category_ids: []`, which is the array
spelling of the same thing. `max:1` admits zero and one.

Applies to all four service request classes, which are deliberately identical
and must be changed together:

| Surface | Request | Endpoint |
|---|---|---|
| Owner | `UpdateServiceCategoryAssignmentRequest` | `PATCH /api/services/{service}/category` |
| Owner | `StoreServiceRequest` | `POST /api/services` |
| Staff | `StaffStoreServiceRequest` | `POST /api/staff/professionals/{pro}/services` |
| Staff | `StaffUpdateServiceRequest` | `PATCH /api/staff/professionals/{pro}/services/{service}` |

**Menu items are NOT affected.** `Platforms\CreateMenuItemRequest` /
`UpdateMenuItemRequest` keep `max:50` — menu multi-category is real and
persisted, unlike service memberships.

---

## Endpoints

### Owner surface — `routes/api/user.php`, consuming repo Partna-App

Request and response shapes unchanged on all ten unless noted above.

| Endpoint | Before | After |
|---|---|---|
| `POST /api/services/resync` | clear `is_manual` on the user's Fresha `site.services` rows | delete `content.manual_overrides` rows for the user's Fresha service items |
| `POST /api/services/{service}/resync` | same, one row | same, one item |
| `PATCH /api/services/{service}/category` | write `site.service_category_assignments` | write `content.collection_items` |
| `GET /api/service-categories` | read `site.service_categories` | read `content.collections` |
| `POST /api/service-categories` | insert `site.service_categories` | insert `content.collections`, `is_user_created = true`, `external_ref = NULL` |
| `GET /api/service-categories/{category}` | read, `withTrashed` → `deleted_at` | read, `withTrashed` → `removed_at`, emitted as `deleted_at` |
| `PATCH /api/service-categories/{category}` | rename / reposition legacy row | rename / reposition the collection |
| `DELETE /api/service-categories/{category}` | soft-delete | set `content.collections.removed_at` |
| `POST /api/service-categories/reorder` | write `sort_order` | write `content.collections.position` |
| `POST /api/service-categories/{category}/restore` | clear `deleted_at` | clear `removed_at` |

Two behaviour notes inside those unchanged shapes:

- **`updateCategory`'s 3a gate comes off.** `ServicePolicy::updateCategory()`
  denied-as-404 anything not `source = 'fresha'`, because `content.*` had no
  membership concept. It has one now, so **owner-authored services became
  assignable to a category**. That is the gate's documented exit condition, not
  a regression — but it is a capability appearing where a 404 used to be.
- **`destroy` no longer tears down memberships, so `restore` brings the group
  back intact**, and restores the category in place rather than pushing it to
  `max + 1`. Safe only because `SectionCandidates` now filters
  `collections.removed_at IS NULL` — that filter is load-bearing for this
  behaviour, not tidiness. Without it, items would keep serving through a
  category the owner deleted.

`GET /api/services` and the other 3a-era service routes are unchanged by this
slice beyond the merged-list behaviour 3a already documented.

### Staff surface — `routes/api/staff.php`, consuming repo Partna-App

| Controller | Before | After |
|---|---|---|
| `StaffServiceManagementController` (9 methods: `index`, `store`, `show`, `update`, `destroy`, `reorder`, `reorderLayout`, `restore`, `forceDestroy`) | `site.services`, with **no `source` filtering anywhere** — post-3a an owner-authored service had no `site.services` row at all, so staff could not see, edit or delete it, and an edit to a row that did exist returned 200 while changing nothing public reads | the same collaborators the owner controller uses (`ManualServiceItems`, `ManualServiceWriter`, `ServiceCollections`). Response shapes unchanged |
| `StaffServiceCategoryManagementController` (7 routes across **two** staff groups: `index`/`show`/`restore` any role, `store`/`update`/`destroy`/`forceDestroy`/`reorder` under `staff.admin`) | `site.service_categories` | `content.collections`. Response shapes unchanged |

Three things worth stating about the staff category controller, because none of
them are visible from the wire:

- **It was cut over to close a regression this slice would otherwise have
  shipped.** Once the owner surface read `content.collections` while staff still
  wrote the legacy table, a staff-created category no longer appeared on the
  owner's list. Fixing `store()` alone was not viable — a collection id cannot
  route-model-bind against `site.service_categories` — so all seven routes moved.
- **`index()` deliberately merges both id spaces.** Fresha services still file
  into `site.service_categories` until slice 7, so reading only
  `content.collections` would have hidden the Fresha half from staff.
- **It previously invalidated nothing**, leaning on `ServiceCategoryObserver`,
  which `content.*` writes never fire. All three cache lanes now fire on all six
  write verbs.

Staff authorization rides the `staff.admin` middleware group, not
`ContentCollectionPolicy` — that policy's actor is type-hinted `User` and the
staff actor is `PartnaStaff`. No inline `abort_unless`/403 was added; CI would
fail the build.

`reorder` on the staff surface 422s a foreign category id rather than filtering
it silently, matching the pre-cutover contract.

## Public surface

| Surface | Consuming repo | Status |
|---|---|---|
| `GET /api/public/profiles/{handle}` — services section | partna-monorepo | Shape unchanged. `category` now carries a real label — see §3 above |
| Fresha booking menu (`FreshaSelectionResource`) | Partna-App | Nine-key `services[]`, sourced from `content.*` — see §1 and §2 |
| `BookingVisibility` | — | **Unchanged, deliberately.** Its gate stays "at least one active manual service AND a booking destination". Re-pointing it at Fresha items would reverse the documented product decision that a platform integration never satisfies booking on its own |

**A Fresha service still never appears in the public services section, and an
owner-authored service still never appears on the booking surface.** The
services section reads `content.sources.kind = 'manual'`; Fresha lands under a
`connection` source. Asserted, not assumed — `tests/Feature/Content/ServiceTwoSurfaceTest.php`
pins both directions with a positive control on each, so neither case can pass
against an empty list.

## Not dropped, not migrated

`site.services`, `site.service_categories` and
`site.service_category_assignments` all survive this slice. The legacy Fresha
rows are **landed, not migrated** — the connector writes `content.*` from the
vendor directly, so there is no backfill command and no coord continuity
exercise for this half. The 18 live / 3 deleted `source IS NULL` legacy rows and
the 59 live / 2 deleted `source = 'fresha'` rows were verified **unchanged** on
dev after this slice's runs.

Legacy `source IS NULL` rows are no longer addressable through staff routes.
That is correct: they are the shadow of the 18 owner services that now live
authoritatively in `content.*`, which staff reach through the pool. Nothing
public reads them and slice 7 drops the table.

`PurgeSoftDeleted.php` still purges `site.services` on its 30-day schedule, as
3a's manifest states. Do not repoint it.
