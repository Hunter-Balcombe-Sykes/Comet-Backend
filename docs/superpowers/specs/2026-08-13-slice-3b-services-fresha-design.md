# Slice 3b — Fresha services onto `content.*`

Second half of `2026-08-11-content-pool-convergence-design.md` §7 "Slice 3". 3a
moved the 21 owner-authored services and is merged (`e12759d92`, now
`origin/development`). 3b does the Fresha half: repair the connector, land the
scraped menu natively, turn categories into collections, cut over the remaining
endpoints and the staff controller.

Kickoff: `docs/superpowers/plans/2026-08-13-slice-3b-services-fresha-KICKOFF-PROMPT.md`.

---

## 1. Verified state — dev (`glncumufgaqcmqhzwrxm`), 2026-08-13

Every figure re-derived from the database or from a live vendor probe, per
convergence invariant #1 and the kickoff's rule zero. Nothing here is cited from
another slice's checkpoint.

```
site.services          fresha  59 live / 2 deleted   ·  owner 18 live / 3 deleted
content.source_items kind='service'   21, ALL on a 'manual' source, removed_at = 0
content.items        kind='service'   18 live / 3 retired
site.service_categories               16 live (all source='fresha') + 2 deleted (both owner)
site.service_category_assignments     61
content.collections                    9   (slice 5a, kind='storefront')
content.collection_items              51   (slice 5a)
ingest fresha sources                  4   services stream 'unavailable' on all four,
                                           run_seq = 0 — never landed a record
```

3a's backfill **did** run on dev; the entry gate's stop condition does not fire.

### 1.1 Corrections to figures this slice inherited

| Source | Was | Is |
|---|---|---|
| Kickoff Unit 5, 3a §3.5 | 17 service routes; "the six `/service-categories/*`"; 9 remaining | **18 routes** (`UserServiceController` 11 + `UserServiceCategoryController` **7**). 3a cut over 8, so **10 remain**. The uncounted route is `POST /service-categories/{category}/restore`. |
| Kickoff Unit 1 | `FreshaConnector.php:239` | `:238` (`employeeId` is `:237`) |
| Kickoff Unit 1 probe | services `25/40/22` | `25/40/**25**` — edward's storewide menu moved. Category counts `5/12/7` match exactly. **Never pin a test to a vendor count.** |
| Kickoff Unit 2 | stored selections are `employee` or `storewide` | **2 `employee`, 0 `storewide`, 3 with no selection** — see §1.2 |
| Kickoff Unit 4 | `site.service_categories` (16) → migrate to `content.collections` | **not migrated** — see §2 and §3.4 |

### 1.2 The state the prompt says cannot exist — three live rows in it

Unit 2 asserts `FreshaAutoSelector` collapses every unmatched case into
`mode: 'storewide'` before storage, so "the connector never sees 'we could not
tell who this is'". Dev's five live `fresha` connections:

| connection | `selection` | mode | legacy services | categories |
|---|---|---|---|---|
| vision-hair-studio | object | `employee` 4508456 | 36 | 11 |
| brotherwolf | object | `employee` 4891132 | 23 (+2 deleted) | 5 |
| edward-scissorhands | JSON `null` | — | 0 | 0 |
| anseo-studio (`book-now/…?pId=`) | JSON `null` | — | 0 | 0 |
| some-salon-abc123 (fake slug) | key absent | — | 0 | 0 |

**Zero `storewide` rows. Three rows with no selection at all**, two of them
refreshing `ok`. All five belong to `active`, claimed, published individual
sites — not pre-account stubs. `connectMode` is null on all five, consistent
with the F7-closed note.

The 59 live legacy Fresha services are exactly 36 + 23, both `employee` rows.
**A connection with no selection publishes nothing today**, and the descriptor
agrees: `PlatformRegistryServiceProvider:368` completes fresha on
`is_array($c->payload['selection'])`, which `null` fails.

`anseo-studio` also has **no ingest source at all** (4 sources, 5 connections):
`SourceProvisioner::freshaSlug()` matches only `fresha.com/…/a/<slug>`, and that
row is the sole `book-now/…` URL. Recorded, not fixed here — see §7.

### 1.3 Unit 1 — the probe, confirmed live 2026-08-13

Nine requests, the same pinned persisted query `FreshaConnector::fetchBookingFlow()`
sends, varying only `options.employeeId` / `options.shouldShowAllEmployees`. All
HTTP 200, **no GraphQL `errors` on any of them**.

```
slug          variant                     screenServices              categories  services
brotherwolf   all=true  emp=null  CURRENT  {} empty                    NULL         0
brotherwolf   all=false emp=null           BookingFlowScreenServices    5          25
brotherwolf   all=false emp=4891132        BookingFlowScreenServices    5          23
vision        all=true  emp=null  CURRENT  {} empty                    NULL         0
vision        all=false emp=null           BookingFlowScreenServices   12          40
vision        all=false emp=4508456        BookingFlowScreenServices   11          36
edward        all=true  emp=null  CURRENT  {} empty                    NULL         0
edward        all=false emp=null           BookingFlowScreenServices    7          25
```

**The pinned hash is valid.** The connector's `Unavailable` message blames a
rotated persisted-query hash and points at a re-pin runbook; both halves are
wrong and send the next reader to fix a non-problem. Correcting it is part of
the fix, not a nicety.

The employee-variant counts equal the stored selections exactly (23, 36), which
independently confirms those selections were built from the per-employee menu.

`location` is absent under **every** variant, so the `profile` stream keeps
degrading to its `no_profile_fields` Note. Expected, out of scope.

### 1.4 Unit 2 — the price divergence, wider than recorded

```
BROTHERWOLF  employee 23 vs storewide 25 : identical 1, DIFFER 22, storewide-only 2
  Premium Haircut & Beard Trim   employee $120   storewide from $108
  Men's Cut and Beard Trim       employee $100   storewide from $90
  storewide-only: two $80 Barber Membership tiers belonging to another barber

VISION       employee 36 vs storewide 40 : identical 15, DIFFER 21, storewide-only 4
  ULTIMATE HAIR SMOOTHENING      employee from $360   storewide from $180
  1/4 HEAD FOILS+WELLAPLEX       employee $240        storewide from $165
  PARTIAL BALAYAGE+WELLAPLEX     employee $275        storewide from $180
```

Vision is a second salon the kickoff never measured: publishing storewide there
would advertise a $360 service at $180. The decision to follow `selection.mode`
stands on stronger evidence than was recorded.

**`from` is not a storewide marker.** An employee menu carries `from` qualifiers
too (`ULTIMATE HAIR SMOOTHENING`: `from $360`). The mapper cannot infer a
qualifier from which menu it fetched; it must read the string.

### 1.5 Unit 3 — the vendor's actual price and duration grammar

149 rows across all probes:

```
from $N   x73   ->  qualifier 'from'
$N        x70   ->  qualifier 'exact'
free       x6   ->  qualifier 'free'      (all consultations — free is REAL on the vendor)
null       x0

durations: N mins x80 | N hr, N mins x20 | N hr x17 | N hrs x9 | N hrs, N mins x8
           N mins - N mins x9 | N mins - N hr x2 | N hrs, N mins - N hrs, N mins x1
           N mins • N services x2 | N mins - N mins • N services x1
```

Decimals are real (`from $49.50`, `from $31.50`), so an amount must be parsed to
minor units, never to whole dollars.

**Why the zero-price trap cannot fire on this lane.** The stored selection blob
carries `priceValue: null` and `currency: null` with the honest display string
in `price`:

```json
{"name":"Premium Haircut & Beard Trim","price":"$120","priceValue":null,
 "currency":null,"duration":"1 hr, 15 mins","serviceId":"s:25020010","hasVariants":false}
```

`Services\Platforms\FreshaServiceProjector:374` reads
`is_numeric($priceValue) ? … : 0`, which is why all 61 legacy rows carry
`price_cents = 0`. The **ingest** projector
(`App\Ingest\Projection\FreshaServiceProjector`) never reads `price_cents` — it
parses the display string and already maps all three shapes correctly. The trap
only bites if someone routes a Fresha row through 3a's
`ManualServiceWriter::projectionFor()`, whose zero→`free` rule is right for
hand-entered data only. §5.2 pins a test that fails if they do.

### 1.6 NEW — the mapper silently drops bundle rows

`FreshaConnector::mapServiceItem()` requires `"catalogId":"s:\d+"` in an action
id. Three of edward's rows are multi-service packages whose `primaryAction.id`
carries `bookableId` but no `catalogId`; the real id is on `secondaryAction`:

```
'Father & Son' Haircuts (Standard)     from $87    25 mins - 30 mins • 2 services
Two Kids - Basic Haircut (Same Time)   from $78    25 mins • 2 services
Two Kids - Fade Haircut (Same Time)    from $104   30 mins • 2 services
```

Two independent defects, both required to lose the row: the regex is pinned to
`s:` where a package is `p:360081`, **and** `primaryAction.id ?? secondaryAction.id`
is a *null*-coalesce — `primaryAction.id` is a non-null string, so it never falls
through to the id that would have matched. 12% of that salon's menu, dropped with
no Note and no count.

### 1.7 NEW — `content.collections` has no natural key, and no soft delete

```
collections_pkey       UNIQUE (id)
idx_collections_user   (user_id, "position")     -- NOT unique
collection_items_pkey  UNIQUE (collection_id, item_id)
```

Nothing to `ON CONFLICT` on, and a Fresha category is re-derived on every run,
so idempotency is mandatory. `collections` also has **no `removed_at`/`deleted_at`**
while `site.service_categories` has both a `deleted_at` and a `restore` endpoint.

### 1.8 NEW — the legacy category lane keys on the LABEL

`Services\Platforms\FreshaServiceProjector::resolveCategoryIds()` does a
case-insensitive find-or-create on `title` and never stores Fresha's category id
(`site.service_categories` has no external-ref column). So **an owner who renames
a Fresha category gets a duplicate on the next sync** — the same defect slice 5a
fixed by moving its storefront key off the mutable label.

One rule in that method is deliberate and must survive: a **trashed** same-title
category is never resurrected; that label simply contributes nothing.

### 1.9 NEW — `ingest.sources` has no `config` column

`RunExecutor:76-82` builds `Pull.config` from two real columns, `scope` and
`scope_n`. Carrying a selection through that seam costs DDL.

---

## 2. Decisions taken, 2026-08-13

Owner decisions, recorded so they are not silently re-opened.

**The connector follows `selection.mode`** — inherited from the kickoff as
already-decided, and §1.4 strengthens it rather than reopening it.

**A connection with no selection lands nothing, and says so.** The connector
yields `Note('no_selection')` and returns *before* any HTTP call. Rejected:
fetching storewide for these three, which would attribute a whole salon's
catalogue — including another barber's membership tiers — to one individual at
understated prices, which is precisely the harm §1.4 exists to prevent, applied
to people who never chose. Also rejected: landing storewide as pool excludes,
which mints a fourth meaning for "excluded" and hides 60+ rows in the database
against a display decision nobody has made. This preserves today's behaviour
exactly, and it distinguishes "nobody has chosen yet" from "the connector is
broken", which the current `Unavailable` cannot. Prompting the owner to choose
is a dashboard job and is **not** in this slice.

**Bundles are landed.** They are real, priced, bookable menu items carrying a
stable vendor id. Dropping them is silent data loss on the exact surface this
slice rebuilds. The fix is a regex widening plus a match-based action-id
fallback; see §3.1.

**Collections become part of the projection contract** (§3.3), rather than a
second Fresha-only writer beside `ShopContentWriter`. Membership is genuinely
part of what a projection means; slice 5a writes collections outside the
pipeline because its data never entered the pipeline (a scraper, not a
connector). Slice 4 (menus, multi-category, same ingest lane) needs exactly this
capability next, so it is built once in the shared writer rather than a third
time per-connector. The cost is acknowledged: `ProjectionWriter` is the file
CLAUDE.md now mandates the Postgres lane for, *because* 5a broke it. §5.3
applies.

**The 16 categories are NOT migrated** — see §3.4.

**The external key lives on `content.collections`, not a sidecar** (§3.3). A
service category carries no behaviour fields, so 5a's `content.storefronts`
shape would be an empty table whose only job is to hold a key — and 5a's own
docblock records the incident that shape caused: an un-transacted pair orphaned
9 stores' collections, and because the lookup JOINs the sidecar it was blind to
the orphan and minted duplicates. A key on the row itself has no such window.

---

## 3. The change

Seven units. Order matters: 3.1 → 3.2 → 3.3 → 3.4 → 3.5 → 3.6 → 3.7. The lane
is made to work and verified before anything reads it.

### 3.1 `FreshaConnector` — four corrections

**a. The variable.** `:238` `shouldShowAllEmployees` becomes `false`; `:237`
`employeeId` takes the value from `Pull.config` (§3.2). Proven live in §1.3.

**b. The message.** The `Unavailable` at `:130-134` (and the transport one at
`:98-105`) must stop asserting a rotated hash as the cause. The rotation symptom
is real and worth keeping as *a* possibility, but "no `screenServices.categories`"
now has a known, more likely cause, and the message must name it first.

**c. The bundle drop.** `mapServiceItem()`:

```php
foreach ([data_get($item, 'primaryAction.id'), data_get($item, 'secondaryAction.id')] as $actionId) {
    if (is_string($actionId) && preg_match('/"catalogId":"((?:s|p):\d+)"/', $actionId, $m)) {
        $serviceId = $m[1];
        break;
    }
}
```

Both defects fixed together: the `p:` prefix and the fall-through. A row that
still matches nothing yields no record, as today — but see (d).

**d. The category id.** `mapServiceItem()` emits `categoryId` alongside
`category`. Fresha's category objects carry a stable numeric `id`
(`{"id":"3282965","name":"Haircuts","description":null,"items":[…]}`), verified
live. §3.3 and §3.4 depend on it.

The `services` stream also gains a `Note` counting rows that parsed to no
service id, so a future mapper gap is visible in run output instead of silent.

**Not changed:** `App\Ingest\Projection\FreshaServiceProjector`'s price and
duration parsing, which §1.5 verifies is already correct for all three price
shapes. Its duration parser takes the **lower bound** of a range
(`"25 mins - 50 mins"` → 1500s); that is pre-existing, defensible, and now
documented rather than left to be rediscovered.

### 3.2 The selection reaches the connector without the connector reading a user

Connectors take `Pull` + `Io` and read no user data. That stays true: the
**provisioner** writes the selection onto the source, and `RunExecutor` passes
it in `Pull.config` beside `scope`/`scope_n`. It is a config widening at that
seam, not a connector reading a connection.

New column, `ingest.sources.selection_ref TEXT NULL` — which sub-account's view
of the remote thing to fetch. Three states, and the token is unambiguous because
employee ids are numeric:

| stored selection | `selection_ref` | connector fetches |
|---|---|---|
| `mode: 'employee'` + id | the employee id | that employee's menu, at their prices |
| `mode: 'storewide'` | `'storewide'` | the location menu, at store prices |
| absent / `null` | `NULL` | nothing — `Note('no_selection')`, no HTTP call |

`SourceProvisioner::sync()` derives it from the connection payload alongside
`identifierFor()`. **A changed `selection_ref` must also set
`next_attempt_at = now()`**, exactly as a changed identifier does at `:102-106`
— without it, switching who you are takes up to the 7-day `max_interval_secs`
ceiling to show. The derivation is generic in shape (`selectionRefFor($sourceKey,
$connection)`, `match` on source key like its sibling) so slices 4–7 inherit a
seam, not a Fresha special case. **Tell them: `Pull.config` now carries a third
key.**

`sync()`'s "update ONLY identity + activation" rule is respected —
`selection_ref` is identity (which menu), not scheduling state.

### 3.3 `ProjectionWriter` learns collections

A projector may return a `collections` key. Inert for every projector that does
not:

```php
'collections' => [[
    'external_ref' => '3282965',
    'label'        => 'Haircuts',
    'kind'         => 'service_category',
    'position'     => 0,
]],
```

`ProjectionWriter` upserts the collection on the new natural key, then replaces
this item's memberships **for this source**:

```sql
DELETE FROM content.collection_items WHERE item_id = ? AND source_id = ?;
-- then insert the projection's rows
```

Replace-by-source, matching the existing rule at `:925` that collection facets
(media/offers/tags) replace rather than accumulate. `collection_items`'s PK is
`(collection_id, item_id)` with `source_id` outside it, so scoping the delete by
source is what keeps two sources from deleting each other's memberships.

Migration (prefix block `20260813090000`–`20260813099999`, unconsumed by 3a; no
`CONCURRENTLY`, so no pipeline hazard):

```sql
ALTER TABLE content.collections ADD COLUMN external_ref TEXT;
ALTER TABLE content.collections ADD COLUMN removed_at   TIMESTAMPTZ;
CREATE UNIQUE INDEX collections_user_kind_external_ref_uq
    ON content.collections (user_id, kind, external_ref);
ALTER TABLE ingest.sources ADD COLUMN selection_ref TEXT;
```

**The index is deliberately NOT partial.** `WHERE external_ref IS NOT NULL`
would read better, but Postgres requires a partial index's own predicate in the
`ON CONFLICT` target, and Laravel's `upsert()` emits only the column list — the
write would fail with *"no unique or exclusion constraint matching the ON
CONFLICT specification"*. A plain index is upsert-compatible and just as correct
here: Postgres treats NULLs as distinct by default, so the user-created rows
(`external_ref IS NULL`, including slice 5a's 9 storefronts) are unconstrained,
exactly as the partial form intended. §5.3 pins this on the real driver.

`removed_at` exists because `/service-categories/{id}` has both `destroy` and
`restore` (§3.5) and `collections` has no soft-delete at all. It carries exactly
one meaning — **an owner deleted this category** — and follows `items.removed_at`'s
one-way rule: only `destroy` sets it, only `restore` clears it, and **the
projection path never touches it**. That reproduces the legacy lane's deliberate
"a trashed same-title category is never resurrected" rule (§1.8) with a stable
key instead of a label.

**A category that vanishes from the vendor's menu is not a deletion.** Its
memberships are replaced away by the rule above, leaving an empty collection.
Reads filter empty machine-derived collections rather than stamping `removed_at`
— which keeps `removed_at` meaning one thing, and matches `deleted_origin`'s
`sync`-vs-`user` distinction without carrying the column (3a §3.7).

**Behaviour change, deliberate:** keying on the vendor's id means an owner's
rename of a Fresha category now **survives** the next sync instead of minting a
duplicate (§1.8). Strictly better, and the same correction 5a made.

### 3.4 Categories are landed, not migrated

The kickoff's Unit 4 says migrate `site.service_categories` (16) →
`content.collections` and `service_category_assignments` (61) →
`collection_items`. **This spec does not**, for the same reason 3a §2 gives for
not backfilling the 61 services: scraped data lands natively under its own
source, and stamping it through a migration destroys the provenance the
two-surface rule depends on.

Migrating would also actively harm. Legacy category rows carry no Fresha
category id (§1.8), so a migrated collection would have `external_ref = NULL`
and could not match anything the connector lands — **every category would exist
twice**, once migrated and once native.

It rescues nothing: all 16 live categories are `source='fresha'`, and the only
two owner-created ones are already deleted. **Zero live user-created categories
exist on dev.**

The legacy tables keep receiving legacy writes until slice 7 drops them,
unchanged, exactly as the 59 legacy service rows beside them do.

Match 5a's conventions where they apply: `is_user_created = false` for
machine-derived groups. `kind = 'service_category'` (free text, no CHECK —
verified). 5a's "per-collection behaviour goes in a sidecar" convention is
honoured by having no behaviour to store, not by minting an empty sidecar (§2).

### 3.5 The 10 remaining endpoints

All in `routes/api/user.php`. Request and response shapes do not change.

| Route | 3b |
|---|---|
| `POST /services/resync` (320) | drop `content.manual_overrides` for the user's Fresha service items |
| `POST /services/{service}/resync` (321) | same, one item |
| `PATCH /services/{service}/category` (345) | write `content.collection_items` |
| `GET /service-categories` (328) | read `content.collections` |
| `POST /service-categories` (329) | create, `is_user_created = true`, `external_ref = NULL` |
| `GET /service-categories/{category}` (330) | read, `withTrashed` → `removed_at` |
| `PATCH /service-categories/{category}` (333) | rename / reposition |
| `DELETE /service-categories/{category}` (335) | set `removed_at` |
| `POST /service-categories/reorder` (337) | write `collections.position` |
| `POST /service-categories/{category}/restore` (338) | clear `removed_at` |

**`resync` maps exactly.** The legacy verb reverts an owner-edited projected
service by clearing `is_manual` so the next scrape overwrites it. In `content.*`
an owner edit **is** a `content.manual_overrides` row (`item_id`, `facet`,
`column_name`, `value`), so reverting is deleting those rows. No new concept, and
it makes the `(source='fresha', is_manual=true)` state — 0 rows today, real in
code — expressible rather than lost.

**`updateCategory`'s gate comes off.** `ServicePolicy::updateCategory()` denies-as-404
for anything not `source='fresha'`; 3a added that deliberately because
`content.*` had no membership concept. It has one now, so owner-authored
services become assignable. That is the gate's documented exit condition, not a
regression.

Reads and writes go through the 3a collaborators — `ManualServiceItems`,
`ManualServiceWriter` — extended, not copied. **A fourth copy of their predicates
caused three of 3a's final-review blockers**; the two-surface predicate and the
exclude predicate each exist once.

### 3.6 `StaffServiceManagementController` — the whole controller

Nine methods (`index/store/show/update/destroy/reorder/reorderLayout/restore/forceDestroy`)
against `site.services` with no `source` filtering anywhere. Post-3a an
owner-authored service has **no `site.services` row at all**, so staff cannot
see, edit or delete it — not merely see it stale — and a staff edit to a row
that does exist writes a lane nothing public reads, returning 200 while changing
nothing.

Cut over onto the same collaborators the user controller uses. Not a parallel
implementation: a second independent copy of this logic is what let it rot
silently in the first place.

### 3.7 The booking surface

The Fresha menu does **not** render from `site.services`. It renders from the
connection's saved selection blob, passed through verbatim by
`FreshaSelectionResource` (`services[]`, `hiddenServiceIds`), gated by
`PlatformDescriptor::isComplete`. Slice 7 drops `site.services` but the blob is
independent of it, so this surface is not forced to move by teardown — it is
moved here because convergence invariant #2 requires something to read the kind,
and because the blob and `content.*` will otherwise disagree the moment the
connector runs.

`services[]` is re-sourced from `content.*` Fresha service items **with its
current shape preserved exactly** — the inner keys come from Fresha's own
payload and the resource deliberately does not allowlist them. The price string
must round-trip: `qualifier` + `amount_minor` + `currency` reconstruct
`"from $108"` / `"$120"` / `"free"`, with cents rendered only when non-zero
(`4950` → `"$49.50"`, `12000` → `"$120"`). A bare `$` is what the vendor emits
and what the wire has always carried; `currency` stays `null` rather than
guessing AUD, matching the projector's existing "never USD-defaults" rule.

**`BookingVisibility` is NOT changed.** Its gate is "at least one active service
AND a booking destination", and the class comment records that smart-booking was
dropped deliberately — *"a platform integration never satisfies booking on its
own"*. Re-pointing it at Fresha items would reverse a product decision this
slice has no mandate to reverse. It keeps 3a's re-expression against the manual
source, untouched.

**The two-surface rule holds by construction.** The services section reads
`content.sources.kind = 'manual'`; Fresha lands under a `connection` source, so
it cannot appear there. §5.2 asserts it rather than trusting it.

---

## 4. Cache invalidation — all three lanes

Per parent §9.2, and there is **no CI check** enforcing this despite
`BuildState`'s docblock claiming one.

| Lane | Action |
|---|---|
| `site.site_documents` build state | `BuildState::bump($siteId)` |
| 60s public-profile payload cache | touch `site.sites.updated_at` |
| Cloudflare edge | `CloudflareCachePurgeJob::dispatch($subdomain)` |

`ManualServiceWriter::invalidate()` is the reference and is reused, not copied.
**The connector run is itself a raw-write seam** — a scheduled run that changes a
rendered menu must fire all three, once per touched site, not per row.

3a's trap is carried forward: its three-lane test passed with the `BuildState`
lane **deleted**, because `writeManualItem()` bumps internally. Assert an exact
revision delta, never `content_revision > 0`.

---

## 5. Verification

### 5.1 Live dev assertions, output pasted into the checkpoint

```sql
-- the lane produces records at all — the §1 figure that must move off zero
SELECT s.identifier, st.stream_name, st.health, st.run_seq
FROM ingest.sources s JOIN ingest.streams st ON st.source_id = s.id
WHERE s.source_key='fresha' AND st.stream_name='services';

-- Fresha service items, on a CONNECTION source (never 'manual')
SELECT cs.kind, count(*) FROM content.source_items si
JOIN content.sources cs ON cs.id = si.source_id
WHERE si.kind='service' GROUP BY 1;      -- gate: manual stays 21, connection > 0

-- prices are honest: no zero-amount 'exact', and 'from' is preserved
SELECT o.qualifier, count(*), min(o.amount_minor), max(o.amount_minor)
FROM content.offers o JOIN content.items i ON i.id=o.item_id
JOIN content.sources cs ON cs.id=o.source_id
WHERE i.kind='service' AND cs.kind='connection' GROUP BY 1;
SELECT count(*) FROM content.offers o JOIN content.items i ON i.id=o.item_id
WHERE i.kind='service' AND o.qualifier='exact' AND o.amount_minor=0;   -- gate: 0

-- collections landed, keyed, and populated
SELECT kind, is_user_created, count(*), count(external_ref) AS keyed
FROM content.collections GROUP BY 1,2;   -- storefront 9 unchanged
SELECT count(*) FROM content.collection_items ci
JOIN content.collections c ON c.id=ci.collection_id
WHERE c.kind='service_category';

-- the load-bearing invariants, unchanged from 3a
SELECT count(*) FROM content.source_items WHERE kind='service' AND removed_at IS NOT NULL;  -- gate: 0
SELECT count(*) FROM content.items i WHERE i.removed_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM content.source_items si WHERE si.item_id=i.id);             -- gate: 0

-- the owner half is undisturbed
SELECT count(*) FILTER (WHERE removed_at IS NULL) AS live,
       count(*) FILTER (WHERE removed_at IS NOT NULL) AS retired
FROM content.items WHERE kind='service';  -- gate: owner 18/3 still present
```

Plus a **second identical connector run** asserting the first run's rows survive
it unchanged — parent §8.3's hard-delete hazard, run for real, not simulated.

And the resolver, which no SQL stands in for: the `services` pool for a Fresha
account returns that account's services with price, duration and category.

### 5.2 Pest

- the connector yields `Note('no_selection')` and makes **no HTTP call** when
  `selection_ref` is null; yields records when it is an employee id; yields the
  storewide menu when it is `'storewide'`
- a bundle row (`p:` catalogId on `secondaryAction` only) lands; a row matching
  neither action id yields no record and is counted in the Note
- `from $108` → `from`/10800, `$120` → `exact`/12000, `free` → `free`, and
  `from $49.50` → **4950** — the cents survive, rather than truncating to 49
- **no Fresha service is ever written through `ManualServiceWriter::projectionFor()`**
  — the §1.5 trap, asserted structurally, not by comment
- **a Fresha service never appears in the public services section** (two-surface)
- a second projection run after the first destroys nothing (parent §8.3)
- `collection_items` replace-by-source: a second source's memberships survive a
  Fresha run
- a renamed category is not duplicated by the next run; a `removed_at` category
  is **not** resurrected by a run; `restore` clears it and a subsequent run does
  not re-set it
- `selection_ref` changing sets `next_attempt_at = now()`
- each cut-over write path bumps build state, moves `sites.updated_at`, and
  dispatches a purge — with an **exact revision delta** (§4)
- `FreshaSelectionResource`'s `services[]` shape is byte-identical pre/post
  cutover for a fixture connection
- staff endpoints see, edit and delete an owner-authored service created through
  the user endpoints — the §3.6 defect, asserted from the outside

**Mutation-check anything load-bearing.** 3a found four tests that passed while
not biting, including a three-lane cache assertion that stayed green with the
lane deleted. Break it, watch it go red, restore.

### 5.3 Postgres, not SQLite

`composer test:pg` (`tests/Postgres/`, `phpunit.pg.xml`) is **mandatory** — this
slice edits `ProjectionWriter`, and CLAUDE.md requires the lane for exactly that.
5a shipped two bugs through a green SQLite suite that Postgres rejected: a bare
column in `ON CONFLICT DO UPDATE` (42702) and a timestamptz format assertion.
The new `collections` upsert is precisely that shape — **qualify every column in
its `DO UPDATE` set**.

That lane's stand-in DDL is hand-written and drifts: the new `external_ref`,
`removed_at`, `selection_ref` columns and the partial unique index must be added
to it, or the lane tests a schema that no longer exists.

`composer test` needs `COMPOSER_PROCESS_TIMEOUT=0` — the suite exceeds
composer's 300s default and the timeout presents as a hang.

Verify against the DDL in `supabase/migrations/`, not a green suite:
`items_kind_check` (14 kinds, `service` present), `offers_qualifier_check`
(7 values), `offers.qualifier` NOT NULL, `currency` nullable,
`collection_items` PK `(collection_id, item_id)`.

### 5.4 Post-deploy

`cloud env:logs partna development --minutes 10`, clean. Nightwatch checked —
slice 0 recorded a log scan performed and a Nightwatch scan skipped; do not
repeat that gap.

---

## 6. Definition of done

The Fresha menu is represented in `content.*` with honest per-staff prices,
categories in `content.collections` keyed on the vendor's id and memberships in
`collection_items`; the 10 remaining endpoints and
`StaffServiceManagementController` read and write `content.*`; the booking
surface renders from the pool with its wire shape unchanged; a connector run
after the first destroys nothing; the two surfaces stay distinct; a connection
with no selection lands nothing and says so.

`site.services`, `site.service_categories` and `site.service_category_assignments`
are **not** dropped — that is slice 7.

---

## 7. Out of scope — carried on

- **`anseo-studio` has no ingest source** (§1.2). `SourceProvisioner::freshaSlug()`
  matches only `/a/<slug>`; that connection is a `book-now/…?pId=` URL. Fixing
  the extractor is a routing/provisioning change with its own blast radius across
  every seeded Fresha row, not a services change. **Carried to slice 7's prompt.**
- **Prompting an owner with no selection to choose one** — a dashboard change
  (§2). Three dev connections sit in this state; nothing in this slice moves them.
- `ContentFreshness:89-98` still reads `site.services` for the Services-page
  freshness boost. Analytics ranking only; untouched here.
- The 3 retired owner services carry no `section_items` pin, so `mergeInto()`'s
  curation check does not protect them.
- `ProjectionWriter` resolves through bare unscoped `DB::table()` calls
  throughout.
- `CloudflarePurgeService::purgeHandle()`'s three raw un-deduped `report($e)`
  calls, which with the purge job's three self-dispatched follow-ups reach 12
  reports per site save. Recorded in the slice-4, 5b and 7 prompts.
- `FreshaAutoSelector` warns storewide is the common outcome for non-person
  handles, so a large salon can exceed `config('partna.limits.pagination.services_max')`
  (500), past which the dashboard truncates and the owner cannot reach the tail.
  The pool inherits this.
- The `profile` stream's `no_profile_fields` Note — `location` is absent under
  every probe variant (§1.3).

## 8. Owed on merge

`PoolRegistry`'s four const arrays collide with slice 5b's `shop` entry and both
edit the same docblock sentence. It is a union, not a design conflict. **Whoever
merges second re-runs `PoolRegistryTest` and the pool provisioning tests after
resolving** — a union merge that drops half a const array still passes every test
written by the branch that added the other half.

`Pull.config` gains a third key (§3.2). Slices 4–7 inherit it; their prompts are
updated in place, not left to read this spec.

The endpoint count correction (§1.1) is written back into the slice-7 prompt,
which inherits the same inventory.
