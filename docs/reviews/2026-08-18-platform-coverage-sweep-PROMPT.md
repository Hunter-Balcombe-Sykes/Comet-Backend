# EXECUTE PROMPT — platform coverage sweep, 2026-08-18

**Give this file to a fresh session. It is self-contained.**

## Why this exists

Five Instagram build waves (2026-08-10 → 08-18) answered "does the pipeline work end to end". They did
**not** answer "does it recognise every platform we claim to support", and they cannot: a real profile
only exercises the handful of hosts that profile happens to link.

The cost of that gap is on record. Finding **N1** was that being defined in `app/Catalog/Definitions`
did **not** make a link classify on the auto-route path — `WebsiteLinkHarvester::classify()` walked five
hand-maintained host constants and never consulted the compiled catalog, so **39 hosts with detectors
were invisible**, each burning one of a run's six commerce probes rediscovering a host the catalog could
already name. It took a paid multi-account scrape wave to find that, indirectly, via probe starvation.

A sweep over the catalog would have caught it instantly and for free. Current classification coverage is
about **7 hosts out of 108 definitions** (`tests/Feature/Platforms/CatalogBackedClassificationTest.php`).

**This task builds the sweep and reports what it finds. It does not fix anything.**

## Hard rules

1. **No app-code changes.** Nothing under `app/` may be edited. If a platform fails to classify, that is
   a **finding**, not a bug to patch. Fixing detectors is a separate decision (see "P8" below).
2. **Tests only.** New files under `tests/`, plus one generated markdown report. Nothing else.
3. **No Apify, no builds, no Places.** This task spends **zero** money and creates **zero** accounts.
   It touches no live data and is not subject to the per-IP build cap.
4. **A failing assertion is a result, not a blocker.** The sweep is expected to fail on real gaps. Record
   them and keep going — do not weaken assertions to get green, and do not add skips to hide failures.
5. **Double-check every claim.** Name the file, line or test output that proves it.

---

# PHASE 1 — Enumerate the ground truth

Build the inventory first; everything else keys off it.

- `App\Catalog\CompiledCatalog::surfaces()` — every compiled surface. Also available:
  `brands()`, `detectors()`, `hostAliases()`, `surface(string $key)`.
- ⚠️ **The catalog is a compiled artefact.** `CompiledCatalog::isCompiled()` tells you whether it is
  built. If it is not, compile it the supported way (see `tests/Feature/Console/CatalogArtefactWriteTest.php`
  and `CatalogArtefactTest`) — do **not** hand-edit the artefact.
- `app/Catalog/Definitions/` holds **108** definition classes (excluding `_manifest.php` and
  `_capabilities.php`). `_manifest.php` is the registration list.

Produce a table of every surface: `key`, brand, `RoutingClass`, whether it declares `canonicalUrl(...)`.

**46 of the 108 definitions declare a `canonicalUrl` template** (e.g. Fresha's
`https://www.fresha.com/a/{slug}`). Those give you a probe URL for free — substitute the placeholder.
The remaining ~62 need one hand-written sample URL each. Keep those in a single fixture map, one entry
per surface key, so the gap between "catalog-derived" and "hand-written" stays visible.

---

# PHASE 2 — The classification sweep

One parametrised test asserting every surface is reachable through the real classifier.

```php
app(\App\Services\Platforms\WebsiteLinkHarvester::class)->classify($url);
// → ['platform' => ..., 'category' => ..., 'label' => ...]  |  null
```

For each surface, assert `classify()` does **not** return `null` and resolves to the expected platform.

**Derive the cases from the catalog, not from a hand-written list.** The test must enumerate
`CompiledCatalog::surfaces()` at runtime so that **adding a new definition automatically adds its
coverage**. A hand-maintained case list rots the moment someone adds a platform and forgets the test —
which is the exact failure mode that produced N1.

### Understand the ordering before asserting on `category`

`classify()` (`app/Services/Platforms/WebsiteLinkHarvester.php:447`) tries sources **in order**, first
match wins:

1. `SOCIAL_HOSTS` → category `social` (also requires `looksLikeProfile()`)
2. `BOOKING_HOSTS` → `booking`
3. `RESERVATION_HOSTS` → `reservations`
4. `ORDERING_HOSTS` → `online-ordering`
5. events (organiser before single-event — they share hosts, `/host/` discriminates)
6. … then the **compiled catalog fallback** → category **`link`**

So a host present in both a hand table and the catalog gets the **hand-table** category. Do not assert a
single expected category from the catalog's `RoutingClass` alone — record **which source answered**.

⚠️ **Category `link` is deliberate and is NOT a bug.** Per `CatalogBackedClassificationTest`: *"recognised,
never auto-connected, spends no probe."* Promoting those to real connections means teaching `LinkRouter`
the catalog's routing classes — that is the **P8 migration**
(`docs/plans/2026-07-28-p8-deletion-readiness.md`), explicitly out of scope here. **Report the size of
that long tail; do not treat it as failure.**

### The three buckets to sort every surface into

| bucket | meaning |
|---|---|
| **connectable** | a hand table answers → can become a real `site.platform_connections` row |
| **link-only** | only the catalog answers → link card, never auto-connected (the P8 backlog) |
| **invisible** | `classify()` returns `null` → **this is the finding class N1 was made of** |

Any surface in **invisible** is a genuine gap. That is the headline number of this task.

---

# PHASE 3 — The gate matrix

Classification is only half of it: a correctly-classified link can still be denied by
`LinkRouter::gateAllows()` (`app/Services/Platforms/LinkRouter.php:144`).

Assert every **category × account shape** cell:

| category | `partna` | `business` non-food | `business` + food |
|---|---|---|---|
| `social` | seed | seed | seed |
| `booking` | seed | **seed** | **DENIED** |
| `event` / `event-organiser` | seed | seed | seed |
| `shop` | probe | probe | probe |
| `reservations` | **DENIED** | **DENIED** | seed |
| `online-ordering` | **DENIED** | **DENIED** | seed |

Two cells carry real product consequences, both found by earlier waves — pin them both:

- **Booking inverts on food.** `$isBusiness ? !$isFood : true` — a *food* business is **denied** booking.
- **A restaurant signing up via Instagram is `partna`.** `config('partna.pre_account.sources')` allows
  only `instagram → partna`, so its reservations and online-ordering links are demoted to custom links.

`isFood()` reads `SectorTaxonomy::FOOD_SECTORS` = `restaurant, cafe, bakery, bar, food-truck, caterer,
personal-chef`. **Note what is absent** — no ice-cream/gelato/dessert sector, so a gelateria is not
"food" to this gate. Assert that as current behaviour and flag it in the report; do not change it.

`gateAllows()` is private — drive it through `LinkRouter::route()` with an in-memory `User` (these tests
need no persisted rows), or through whatever seam the existing tests use. Check
`tests/Feature/Platforms/LinkRouterHostDedupeTest.php` for the established pattern and follow it.

---

# PHASE 4 — The report

Write `docs/reviews/2026-08-18-platform-coverage-sweep-RESULTS.md`:

1. **Headline numbers** — of 108 surfaces: how many connectable, link-only, invisible.
2. **The invisible list** — every surface `classify()` cannot see, with its probe URL. Ranked by how
   plausible it is that a real user links it (a booking platform matters more than a dev portfolio site).
3. **The link-only list** — the P8 backlog, sized. State plainly that this is by design today.
4. **Gate matrix results** — every cell, expected vs actual.
5. **Fixture debt** — which surfaces needed hand-written URLs because they declare no `canonicalUrl`,
   since those are the ones that will silently rot.
6. **Findings** — with evidence. No fixes proposed, no severity theatre.

## What "done" looks like

- A test that **derives its cases from the catalog at runtime** and fails when a new definition is added
  without a probe URL. That property is the whole point: it is what makes this a permanent guard rather
  than a one-off audit.
- The gate matrix pinned as tests.
- The report written.
- **Zero changes under `app/`.**

Run with `composer test` (or a targeted Pest filter — note `--filter` is known-broken in this repo, see
`reference_composer_filter_and_pint_broken`; use the path form instead). These tests are pure
classification and need no Postgres lane.
