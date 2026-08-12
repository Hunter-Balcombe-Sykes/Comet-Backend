# Slice 3a — owner-authored services onto `content.*`

Sub-slice of `2026-08-11-content-pool-convergence-design.md` §7 "Slice 3 —
Services → `content.*` · L". Slice 3 is cut in two; this is the first half.

- **3a (this spec)** — the 21 owner-authored services, the `services` pool, the
  write cutover and the public read switch. No connector, no Fresha.
- **3b (later spec)** — the Fresha half: the connector fix, the storewide menu
  reduced to the saved selection by pool excludes, categories → collections, and
  the booking surface.

**Why it splits here.** Slice 3's kickoff assumed the two halves were one job.
The database says otherwise: every one of the 61 category assignments and 16 of
the 18 categories belong to Fresha, and the two owner-authored categories are
both already soft-deleted. The owner half therefore carries **no** collections
work, and the Fresha half carries all of it. Splitting on that seam gives two
independently shippable slices with no split-brain inside either, matching the
1a/1b precedent (parent §4).

Owner decision 2026-08-12: the public surfaces move onto `content.*` in this
programme rather than at teardown, and the dashboard endpoints cut over fully
rather than dual-writing. The reasoning is recorded in §2.

---

## 1. Verified state — dev (`glncumufgaqcmqhzwrxm`), 2026-08-12

Every figure read from the database while writing this spec, per convergence
invariant #1. Where it contradicts the parent spec or slice 3's kickoff prompt,
the correction is stated.

```
site.services                     : 82  (fresha 61, owner-authored 21)
  owner-authored                  : 21  — 18 live, 3 soft-deleted, 3 users
  owner-authored priced           : 21  — every one; $5.00 – $125.00, AUD only
  owner-authored zero-price       : 0
  owner-authored null duration    : 4
  owner-authored without description: 5
  owner-authored is_active = false: 0
site.service_categories           : 18  — fresha 16 (all live), owner 2 (BOTH deleted)
site.service_category_assignments : 61  — ALL on fresha services; owner has 0
content.items kind='service'      : 0
content.source_items kind='service': 0
content.collections / _items      : 0 / 0
content.sources                   : 29 connection @100, 6 manual @200
content.offers                    : 14
```

### 1.1 Corrections to figures this slice inherited

| Source | Was | Is |
|---|---|---|
| Parent §1.5 | `content.offers` 10 | **14** |
| Parent §1.7 | `content.sources` 27 connection / 0 manual | **29 / 6** — the 0b lane is live and exercised |
| Kickoff prompt, two-surface rule | `whereNull('source')` is one call site | **five** — `SitepageDataResolverService:930` and `:289`, `ServicesVisibility:27`, `BookingVisibility:30`, `PurgeSoftDeleted:107` |

### 1.2 The zero-price rule is inverted for Fresha, and must not reach 3b unexamined

Slice 3's kickoff states: *"a zero price is legal and means free, which maps to
`qualifier='free'`, not `'exact'` with 0."*

That is correct as a rule and wrong for this data. **All 61 Fresha rows carry
`price_cents = 0`, and none of them is free.** `App\Services\Platforms\FreshaServiceProjector:374`
reads `is_numeric($priceValue) ? (int) round($priceValue * 100) : 0` — zero is
the *unparsed* fallback, not a price. Applying the rule as written would publish
**Free** on 61 real salon services.

**The rule is right for hand-entered data and wrong for scraped data, and that
is the whole distinction.** An owner who types 0 into a price field means free. A
scraper that failed to parse `"from $108"` and stored 0 means *unknown*. Same
integer, opposite meanings, and the discriminator is the source.

So 3a's mapper **does** map `price_cents = 0` → `qualifier='free'`, correctly, for
the owner-authored rows it owns — there happen to be none today, but a service
created tomorrow through the cut-over endpoints may well be free. 3b must **not**
carry that mapper over to Fresha rows; its honest price comes from the connector's
own display string (`"from $108"`, `"free"`), not from the legacy integer.

### 1.3 The third source/is_manual state has no rows

The kickoff calls `(source='fresha', is_manual=true)` — projected then
owner-edited, which a re-scrape must never overwrite — "the trap in this slice".
Dev holds **0** such rows. The state is real in code (`/services/{id}/resync`
reverts it) and must be handled by 3b's write paths, but there is nothing to
migrate and it is not a 3a concern.

---

## 2. Decisions taken, 2026-08-12

Recorded so they are not silently re-opened. Each was an owner decision.

**The 61 Fresha rows are NOT backfilled.** Once 3b fixes the connector, Fresha's
services land in `content.*` natively, under the Fresha connection source, with
Fresha's own prices. Backfilling them through the manual lane would stamp
owner-authorship on scraped data — destroying the very discriminator the
two-surface rule depends on — and would produce the ordering parent §1.7
measures as permanently non-convergent (backfilled rows first, connector after).
3a therefore migrates the 21 owner-authored rows only.

**And the 2 soft-deleted Fresha rows need no carrying at all** — measured
2026-08-12, both carry `deleted_origin = 'sync'`, meaning the scrape stopped
listing them, not that an owner deleted them. The legacy projector restores
exactly such a row if it reappears (`FreshaServiceProjector:180-195`), so a
connector that re-lands them is reproducing current behaviour, not regressing it.
Zero Fresha rows carry `deleted_origin = 'user'`, which is the state that must
never be resurrected.

**The public surfaces switch to `content.*` in this programme, not at teardown.**
Slice 7 drops `site.services`, and the Fresha booking blob is *composed from*
those rows (`FreshaServiceProjector:396`), so both surfaces break at teardown
whether or not anyone plans for it. Convergence invariant #2 — a kind is not
adopted until something reads it — points the same way.

**The dashboard endpoints cut over to write `content.*`**, rather than
dual-writing or syncing on write. Of the 17, **3a cuts over 8** — the ones that
touch owner-authored services — and 3b takes the remaining 9 (the two `resync`
verbs and the seven category routes), because both are Fresha-shaped. The exact
split is in §3.5. If the public reads `content.*` while the
dashboard writes `site.services`, an owner edits a service and nothing changes on
their site. **Slice 2 shipped exactly this bug**: `removeEvent()` wrote only
`hiddenEventIds`, which the pool does not read, so every hide silently failed
until it was caught (parent §14.5).

**Ordering is preserved with pins, not a new ordering operator.** See §3.3.

---

## 3. The change

Six units. Order matters: 3.1 → 3.2 → 3.3 → 3.4 → 3.5 → 3.6. Data lands and is
verified before anything reads it, and reads switch before writes do.

### 3.1 `ServiceBackfiller`

`app/Services/Migration/ServiceBackfiller.php` plus an artisan command with
`--dry-run`, per convergence invariant #4 — production code, tested, idempotent,
re-runnable, counts reported. Writes through the slice-0b manual lane
(`ProjectionWriter::writeManualItem()`), never raw.

- Scope: `site.services WHERE source IS NULL` — 21 rows, soft-deleted included.
- Coord: `manual:{legacy_uuid}`, preserving the legacy identifier after the table
  is dropped (parent §8.1).
- `user_id` is read directly off `site.services.user_id`; unlike menus, services
  are not scoped through a site. Fail loudly on a user with no site.
- Idempotent on the coord, so a re-run upserts rather than duplicating.

Mapping:

| Legacy column | Destination |
|---|---|
| `title` | `items.headline_cache` + `f_text.headline` |
| `description` | `f_text.body` — omitted when null/empty (5 rows) |
| `price_cents`, `currency_code` | `offers.amount_minor`, `offers.currency`, `qualifier='exact'` |
| `duration_minutes` | `f_duration.seconds` = `× 60` — no row when null (4 rows) |
| `deleted_at` | `items.removed_at` |
| `deleted_origin` | not carried — see §3.7 |
| `is_active = false` | a pool exclude, not `removed_at` (0 rows today) |
| `sort_order` | `section_items.sort_key` with `state='pinned'` — see §3.3 |

**`content.source_items.removed_at` is never written for a user deletion.** It is
cleared on reappearance, so a later run would resurrect a service its owner
deleted. `content.items.removed_at` is one-way (`ProjectionWriter:272-275` never
clears it) and is the correct home. Asserted by test, not by comment.

### 3.2 `PoolRegistry` gains `services`

```php
'services' => ['service'],                       // POOLS
'services' => 'services',                        // PAGE_KEYS
'services' => 'Services',                        // PAGE_LABELS
'services' => ['rule' => [['op' => 'kind_is']], 'order_by' => 'recency'],  // SECTION_SHAPE
```

**Not** in `LATEST_TAG_POOLS` — a "latest service" is meaningless.

`PoolRegistry`'s class docblock currently reads *"Sell / Services / Menu are NOT
here: they keep their existing live lanes (shop selections, `hiddenServiceIds`)"*.
That becomes false with this change and is corrected in the same commit. A
docblock asserting a design that no longer holds is how §9.1's phantom CI check
propagated.

`PoolRegistryTest` pins that a kind belongs to at most one pool; `service`
belongs to no other, so the invariant is undisturbed. The rule must be asserted
against the resolver as well as the constant — a section whose rule the executor
does not recognise fails at runtime, not in the registry.

### 3.3 Ordering — pins, not a new operator

Owner-authored services carry a hand-chosen `sort_order`. The auto half's
ordering vocabulary is `recency`, `alphabetical` and `occurrence`
(`SectionCandidates:105`), none of which preserve it.

**The backfill pins each item into the section at its `sort_order` position**,
using the existing curation half (`site.section_items`), exactly as slice 2's
`EventExcludeSync` writes excludes. `SectionCandidates:119` excludes already-pinned
ids from the auto half, so there is no duplication.

A pin buys a second thing worth naming: `mergeInto()`'s `hasCuration` check reads
`site.section_items`, so a pinned item cannot be hard-deleted by a merge (parent
§8.3). Every backfilled service is pinned, so every one is protected — but the
§5.2 regression test still runs, because "protected by a side effect" is a
property that should be pinned by a test rather than inferred.

`order_by` is the fallback for anything unpinned, and is set to **`recency`** to
match the convention slice 5a establishes for priced, undated items. Alphabetical
would arguably read better for a services list, but it governs only the case where
a service has no pin — which the backfill and the cut-over create endpoint both
prevent — and one convention across the commerce kinds is worth more than a
marginal improvement to a state that should not occur. Reconciled with the
slice-5-shop session, 2026-08-12.

The rejected alternative was a `position` ordering operator. The section rule DSL
spans four registries — the operator enum, `phrase()`, `EXECUTED_OPERATORS` and
`ORDER_BY` — and missing one yields a 500 rather than a failing test. Adding an
operator to preserve an ordering the curation half already expresses is cost
without benefit. **Do not add one in 3b either.**

### 3.4 The public read switch

`SitepageDataResolverService::buildServicesData()` (`:930`) reads
`site.services` filtered `whereNull('source')`. It moves to reading live
`content.items` of kind `service` whose source is the user's manual source,
through the pool.

All five `whereNull('source')` call sites are re-expressed, not just the
renderer: `SitepageDataResolverService:930` and `:289`, `ServicesVisibility:27`,
`BookingVisibility:30`, `PurgeSoftDeleted:107`. The two visibility rules decide
whether a section publishes at all, so a missed one hides or reveals a whole
surface.

**`BookingVisibility` keeps its current meaning in 3a.** Its gate is "at least one
active *manual* service", and it stays that — re-expressed against `content.*`,
same semantics. 3b owns the booking surface itself.

Wire changes recorded in `docs/wire-changes/2026-08-12-slice-3a-services.md` with
before and after shapes and the consuming repos named, per parent §10.

### 3.5 The write cutover — 8 of the 17 endpoints

`routes/api/user.php:309-345` carries 17 service routes across
`UserServiceController` (11) and `UserServiceCategoryController` (6). **3a cuts
over 8**; the other 9 are Fresha-shaped and go with 3b. Every one is a live wire
contract; request and response shapes do not change, only what they read and
write.

| Route (line) | 3a |
|---|---|
| `GET /services` (309), `GET /services/{service}` (311) | read `content.*` |
| `POST /services` (310), `PATCH /services/{service}` (313) | write through the manual lane; pin on create |
| `DELETE /services/{service}` (315) | `items.removed_at` |
| `POST /services/reorder` (317), `/services/reorder-layout` (341) | `section_items.sort_key` |
| `POST /services/{service}/restore` (323) | clears `removed_at` — see below |

Deferred to 3b, unchanged in 3a: `POST /services/resync` (320),
`POST /services/{service}/resync` (321), `PATCH /services/{service}/category`
(345), and the six `/service-categories/*` routes (328–338). The first two are
Fresha-only verbs; the rest are categories, which 3a does not touch because every
live category belongs to Fresha.

**Both controllers keep writing `site.services` for the Fresha half until 3b.**
3a's cutover is scoped to the owner-authored path, so the legacy projector and
its rows are untouched — which is what lets 3b land independently.

**`restore` clears `removed_at`, and that does not weaken the one-way rule.**

The rule exists to stop a *connector* resurrecting an item its owner deleted:
`ProjectionWriter:272-275` never clears `removed_at` on reappearance, because a
service re-appearing in a scrape is not consent. An owner pressing Restore is the
opposite — a deliberate human act on their own row.

So: `restore` clears `content.items.removed_at` **only** from the explicit
endpoint, and `ProjectionWriter`'s projection path is unchanged. The one-way
property that slices 4–7 depend on is a property of the *sync* path, and it stays
exactly as it is. Re-minting a fresh coord was rejected — the coord is
`manual:{legacy_uuid}` and the legacy id does not change on restore, so there is
no fresh coord to mint without inventing an identifier and losing analytics
continuity with it.

A test must pin both halves: restore clears it, and a subsequent projection run
does not.

`ServicePolicy` continues to authorise; `ContentItemPolicy` is kind-agnostic and
covers the new items. Neither is orphaned until slice 7.

### 3.6 DSAR

`DataExportPayloadBuilder` streams `site.services` as a named export section and
pins `services` / `service_categories` in its declared return shape. Once 3.5
lands, that table stops receiving writes, so the export begins serving stale
data — this must move in the same slice as the cutover, not at teardown.

Re-source from `content.*`. **Keep the existing `services` /
`service_categories` section keys**, per the 2026-08-05 precedent that DSAR
allowlists deliberately retain legacy keys so previously-stored payloads stay
disclosable.

#### 3.7 `deleted_origin` — not carried, but it is not vestigial

`deleted_origin` distinguishes "the owner deleted this" (`user`) from "the scrape
stopped listing it" (`sync`), and it **is** load-bearing:
`FreshaServiceProjector:180-195` reads it to decide whether a returning service is
restored or stays suppressed. Calling it unread would be the same class of error
this programme keeps correcting.

It is nonetheless not carried into `content.*`, because its semantics are already
expressed there:

| Legacy state | `content.*` equivalent |
|---|---|
| `deleted_origin = 'user'` | `items.removed_at` set, and never cleared by a projection run |
| `deleted_origin = 'sync'` | no live `source_item` — the connector simply stops landing it, and lands it again if it returns |

All 3 owner-authored deletions carry `deleted_origin = NULL` (measured
2026-08-12) and map to `removed_at`. The column stays in `site.services` until
slice 7 drops the table.

### 3.8 `offers.availability` stays NULL

Slice 5a establishes `in_stock` / `out_of_stock` for products (schema.org
`ItemAvailability` shorthand) on a column that is NULL on all 14 existing rows and
carries no CHECK. A service is not stocked, so 3a writes NULL rather than minting
a third spelling. An unbookable service is expressed as a pool exclude, the same
as `is_active = false`.

---

## 4. Cache invalidation — all three lanes

The backfiller and every cut-over write path are raw-write seams. Per parent
§9.2, copying `PoolController::poolChanged()`:

| Lane | Action | Why bumping alone is not enough |
|---|---|---|
| `site.site_documents` build state | `BuildState::bump($siteId)` | this is what it is for |
| 60s public-profile payload cache | touch `site.sites.updated_at` | `IndividualProfilePayloadBuilder::cacheKey()` composes the key from `updated_at`; `bump()` writes a different table, so the stale payload serves for the full TTL |
| Cloudflare edge | `CloudflareCachePurgeJob::dispatch($subdomain)` | the CDN outlives the origin write |

**There is no CI check enforcing this**, despite `BuildState`'s docblock claiming
one (parent §9.1). The tests below assert it directly. Slice 2's
`content:repair-event-items` shipped a retirement that skipped all three and
served retired events from cache until it was repaired by hand — that is the
failure this section exists to prevent.

---

## 5. Verification

### 5.1 Live dev assertions, output pasted into the checkpoint

```sql
-- 18 live service items, 3 retired
SELECT count(*) FILTER (WHERE removed_at IS NULL) AS live,
       count(*) FILTER (WHERE removed_at IS NOT NULL) AS retired
FROM content.items WHERE kind = 'service';

-- every one traceable to a legacy row, on the manual source
SELECT count(*) FROM content.source_items si
JOIN content.sources s ON s.id = si.source_id
WHERE si.kind = 'service' AND s.kind = 'manual' AND si.coord LIKE 'manual:%';

-- 21 offers, all exact, none zero
SELECT qualifier, count(*), min(amount_minor), max(amount_minor)
FROM content.offers o JOIN content.items i ON i.id = o.item_id
WHERE i.kind = 'service' GROUP BY 1;

-- 17 durations (21 minus the 4 nulls)
SELECT count(*) FROM content.f_duration d
JOIN content.items i ON i.id = d.item_id WHERE i.kind = 'service';

-- NEVER set: a user deletion must not touch the source item
SELECT count(*) FROM content.source_items WHERE kind='service' AND removed_at IS NOT NULL;  -- gate: 0

-- orphan gate, unchanged from the 0b baseline
SELECT count(*) FROM content.items i WHERE i.removed_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM content.source_items si WHERE si.item_id = i.id);  -- gate: 0
```

Plus the resolver itself, which no SQL stands in for: call `PoolResolver` for a
dev account's `services` pool and assert it returns that account's services **in
the owner's `sort_order`**, with price and duration on the payload.

### 5.2 Pest

- the backfiller is idempotent across two runs; a soft-deleted row lands
  `items.removed_at` and **not** `source_items.removed_at`
- a null duration lands no `f_duration` row; an empty description lands no
  `f_text.body`
- the 21 offers carry `qualifier='exact'`; a zero price entered through the
  cut-over endpoints maps to `qualifier='free'` (§1.2), and no offer is written
  with `qualifier='exact'` and `amount_minor = 0`
- `restore` clears `items.removed_at`, and a projection run afterwards does not
- pins reproduce `sort_order`; reordering through `/services/reorder` moves the
  pin, and the public payload order follows
- **the two-surface test the kickoff mandates**: a Fresha-sourced service never
  appears in the services section
- each cut-over write path bumps build state, moves `sites.updated_at`, and
  dispatches a purge
- **§8.3 regression**: run a connector projection *after* the backfill and assert
  all 21 owner items survive. `mergeInto()` hard-deletes a discarded item
  carrying neither a pin nor an override; 0b's `preferOwnerAnchored()` should
  make the owner row win, but it has never been exercised against `service`
- DSAR export returns the same section keys and equivalent content post-cutover

### 5.3 Postgres, not SQLite

Tests run SQLite; production is Postgres. Verify against the DDL in
`supabase/migrations/`:

- `items_kind_check` — `service` is present (confirmed 2026-08-12, 14 kinds)
- `offers_qualifier_check` — `exact|from|upto|range|free|variable|on_request`
- `offers.qualifier` is NOT NULL; `currency` is nullable
- `services_price_cents_check CHECK (price_cents >= 0)`

**No schema migration is expected.** Every destination table and constraint
already exists. The prefix block `20260813090000`–`20260813099999` stays
unconsumed unless the §3.5 `restore` decision requires DDL; if 3a consumes none,
say so in the checkpoint so 3b knows the block is free.

### 5.4 Post-deploy

`cloud env:logs partna development --minutes 10`, clean. Nightwatch checked —
slice 0's checkpoint records a log scan performed and a Nightwatch scan skipped;
do not repeat that gap.

---

## 6. Definition of done

The 21 owner-authored services are represented in `content.*` with prices as
offers, durations as `f_duration`, and the 3 deletions as `items.removed_at`
only; the `services` pool returns them in the owner's order and the public
services section renders from it; the 8 owner-authored endpoints read and write `content.*`
with unchanged wire shapes; DSAR exports from `content.*` under its existing
keys; a connector projection after the backfill destroys nothing; the coverage
gate (parent §8.4 — coord coverage, not row-count equality) is green on dev;
checkpoint and wire manifest committed.

`site.services` is **not** dropped — that is slice 7.

---

## 7. Out of scope — carried to 3b

- The connector fix: `FreshaConnector.php:239` sends `shouldShowAllEmployees: true`
  and gets the employee-picker screen with an empty `screenServices`. Diagnosed
  and proven live 2026-08-12; see the kickoff prompt's unit 1.
- **The connector follows `selection.mode`** — `employee` fetches that person's
  menu at their prices, `storewide` fetches the location menu at store prices.
  This REVERSES the 2026-08-12 storewide-plus-excludes decision, which live data
  disproved on 2026-08-13: Fresha quotes `from <cheapest staff member>` on the
  storewide menu, and 22 of one barber's 23 services priced differently there
  ($120 vs "from $108", $70 vs "from $63"). Excludes govern which items render
  and cannot change what a rendered item costs. Full evidence and the plumbing
  (provisioner writes the employee id onto the source; `Pull.config` carries it;
  the connector still reads no user data) are in the slice 3 kickoff prompt's
  unit 1.
- Pool excludes keep their original job: `hiddenServiceIds`, plus the small
  membership divergence (2 of 25 storewide rows on the measured salon belong to
  another barber's tier). The 2 soft-deleted Fresha rows are **not** an input —
  see §2, they are `deleted_origin='sync'` departures that current behaviour
  restores on return.
- **The coord for Fresha services is not `manual:{legacy_uuid}`** — they are not
  backfilled at all. Note for 3b's own check: the legacy projector updates rows
  in place keyed on `external_id` (`FreshaServiceProjector:150-208`), it does not
  delete-and-reinsert, so `site.services.id` is stable. Slice 5a found the
  opposite for `shop_products` (`ShopCatalog::syncLatest()` deletes then
  re-creates, so uuids churn every sync) — confirm the writer's behaviour before
  keying a coord on a legacy uuid.
- `service_categories` (16 live, all Fresha) → `content.collections`;
  `service_category_assignments` (61, all Fresha) → `collection_items`.

  **3b is probably NOT their first user.** Both tables held 0 rows on
  2026-08-12, but slice 5a populates them for real (9 storefront rows, 51
  links), so if 5a lands first there is prior art to follow rather than a blank
  table. Two conventions 5a establishes, confirmed 2026-08-12 — match them or
  reject them deliberately, do not arrive at a third by accident:

  - `collections.kind` is **free text with no CHECK constraint** (verified on
    dev). 5a uses `kind = 'storefront'` and `is_user_created = false` for
    machine-derived groups. Fresha categories are machine-derived too, so
    `is_user_created = false` and a `kind` of `'service_category'` follows the
    same shape.
  - **Per-collection behaviour goes in a 1:1 sidecar table, not new columns on
    `collections`.** 5a puts its 15 storefront fields in `content.storefronts`
    rather than widening the shared table, precisely so service and menu
    categories do not carry them empty. If 3b needs category-specific
    behaviour, add a sibling sidecar.
- The `(source='fresha', is_manual=true)` state — 0 rows today, but the write
  paths must survive a subsequent Fresha run.
- `/services/resync`, `/services/{service}/resync`, `/service-categories/*`,
  `/services/{service}/category`.
- The booking surface: it renders from the connection's saved selection blob via
  `PlatformDescriptor::isComplete`, not from `site.services`, and switching it is
  gated on the connector having actually run on dev.
- The zero-price correction in §1.2 — 3b must not inherit the kickoff's rule
  unexamined.

## 8. Out of scope — carried to slice 7

`site.services`, `site.service_categories` and
`site.service_category_assignments` are dropped there, with `ServiceObserver`,
`ServiceCategoryObserver` and `ServicePolicy` re-homed or retired
(parent §9.4, §9.6).
