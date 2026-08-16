# Wire change — slice 7, legacy teardown (2026-08-12)

Backend-only execution. Spec: `docs/superpowers/specs/2026-08-12-slice-7-teardown-design.md`.
Plan: `docs/superpowers/plans/2026-08-12-slice-7-teardown.md`.

One manifest for the slice; each unit appends its own section as it lands.

---

## Fresha `payload.selection` — composed from `content.*` (Task 11, unit C)

**Consuming repos: partna-monorepo** (`@partnaau/design-system`, the public
booking card on `GET /api/public/profiles/{handle}` →
`integrations.fresha.selection`) **and Partna-App** (unchanged — it already
reads this lane).

`FreshaServiceProjector` no longer writes `site.services` rows and no longer
composes the blob out of them. `services[]` is now read live from `content.*`
through `FreshaServiceItems` — the same `kind = 'connection'` lane, the same
nine-key reproduction, that `FreshaSelectionResource` has served the dashboard
since slice 3b. The blob itself stays on the public wire (spec D3); only its
source moved.

### 1. Owner-edited Fresha prices are no longer honoured — **deliberate removal**

**Owner ruling, 2026-08-16 (spec D3a).** Editing a Fresha-synced service used to
"detach" it from the sync (`site.services.is_manual`), and the public blob then
served the owner's own `price` / `priceValue` / `name` / `duration` /
`description` instead of the vendor's. **The public blob now always serves the
vendor's numbers.**

Why it could not be carried across: an owner's edited PRICE has no
representation in `content.*`. `content.offers` is a set-union COLLECTION, and
`FacetRegistry` excludes collections from `content.manual_overrides` by design
("no single value to override"). Building an offers override lane was
considered and rejected — see D3a.

Why it is safe: measured on dev before the ruling, all 61 live
`site.services WHERE source = 'fresha'` rows carry `is_manual = false`, and none
carries a non-null non-zero `price_cents`. The feature was never used.

**This also CLOSES a live divergence rather than opening one.** Since 3b the
dashboard (`FreshaSelectionResource`) has read `content.*` and so already
ignored these edits, while the public blob honoured them — an owner who edited a
price saw the old price on their dashboard and the new one on their public page.
The two surfaces now agree.

**Unaffected:** `title` / `description` / `duration` overrides. Those are
singleton facets and keep working through `content.manual_overrides`
(`PUT /api/content/items/{item}/overrides`), and `POST /api/services/resync`'s
content half still reverts them.

### 2. `services[]` is the fixed nine-key shape on the public wire too

**Before.** A synced service contributed its raw scrape entry **verbatim** —
whatever keys Fresha emitted the day it was scraped, in Fresha's own key order,
with the vendor's own display strings (`"A$65"`, `currency: "AUD"`).

**After.** Each entry is `FreshaServiceItems`' reproduction: exactly these nine
keys, in this order —

```
name, price, category, currency, duration, serviceId, priceValue,
description, hasVariants
```

This is the identical change slice 3b already made to the dashboard resource
(see that manifest, §1), now reaching the public blob. The same round-trip rules
apply: `price` is reconstructed from `qualifier` + `amount_minor` (`"from $108"`,
`"$120"`, `"free"`), cents render only when non-zero (`4950` → `"$49.50"`), the
`$` stays **bare**, and `currency` is **null** rather than a guessed `"AUD"`.

**A consumer reading a key outside the nine loses it**, and one parsing `price`
must accept a bare `$`.

### 3. An empty booking menu when `content.*` has no rows for the connection

Same intended behaviour 3b documented for the dashboard, now on the public blob:
a connection whose stored blob is populated but which has no `content.*` service
items renders `services: []`. Serving the stored blob instead would serve the
stale prices this whole cutover exists to remove (spec §1.4 measured 22 of 23
understated on one salon, `$360` published as `$180` on another).

**Deploy consequence, and it is a step not an optimisation:**
`ingest.sources.selection_ref` must be synced per Fresha connection before the
first scheduled run matters, exactly as 3b's manifest states. Do not reach for
`ingest:backfill-sources` unqualified.

Spec open question 2 is now load-bearing here: **`anseo-studio`'s Fresha
connection has no ingest source** (`SourceProvisioner::freshaSlug()` matches only
`fresha.com/…/a/<slug>` and that row is a `book-now/…?pId=` URL). Until that is
widened or the row written off, that connection's public booking menu composes
empty.

### 4. `hiddenServiceIds` moved onto the blob

Unchanged on the wire — still a sibling key of `services[]`, still carrying ids
that also appear in `services[]` (the dashboard needs them present to render the
un-hide affordance).

What changed is where it is **stored**. It used to be derived from
`site.services.is_active`, which `compose()` read back out. `content.*` has no
`is_active` — a pool EXCLUDE is the owner-authored equivalent and does not apply
to the connection lane — so the list now lives on the stored blob itself, which
is where `FreshaSelectionResource` already read it from.
`POST /api/platforms/fresha/service-visibility` writes it there and nowhere
else; `compose()` only PRUNES it to ids still live in `content.*`, so hiding a
service and then deleting it leaves no dangling id.

### 5. Menu ORDER still comes from the stored scrape

`payload.raw.services` stays private and stays stored. Its remaining jobs are
the vendor's own menu order — an entry listed there keeps that position — and
`revert()`'s source for the legacy rows. A service live in `content.*` but not
yet in the stored scrape is **appended** rather than hidden until the next
refresh.

### Not changed

- `url`, `storeName`, `mode`, `employee` — owner/vendor facts, untouched.
- `PublicIntegrationConnectionResource`'s `'fresha' => ['url', 'selection']`
  allowlist.
- Suppression and departure semantics. An owner deletion is
  `content.items.removed_at` (which `ProjectionWriter` never clears, so a later
  scrape cannot resurrect it); a service leaving Fresha is
  `content.source_items.removed_at` (which IS cleared on reappearance, so it
  restores when it returns). Never the reverse — recording an owner deletion on
  `source_items` would resurrect it.
- First-occurrence dedup for a `serviceId` Fresha lists under several
  categories.
- The two-surface rule: a Fresha service still never reaches the public services
  section, and an owner-authored service still never reaches the booking
  surface. Pinned by `tests/Feature/Content/ServiceTwoSurfaceTest.php` and again
  by this task's own `FreshaSelectionFromContentTest`.

### Advisory-lock TTLs narrowed (not deleted)

`FreshaController::connect()` (both locks), `saveSelection()` (both locks) and
`FreshaConnectFetch::fetchStorewide()`'s raw `Cache::lock` were each raised to
**30s** because they held a lock across `sync()`'s Postgres transaction and its
inner `pg_advisory_xact_lock('services:{user_id}')`. Those writes are gone —
`sync()` is a `content.*` read — so all five are back on the **10s** default
every other platform caller uses. Every lock itself is unchanged and still
mandatory: they are what re-assert the Square booking XOR and keep
`ConnectFetchJob` / `ScheduledRefresh` / `saveSelection` /
`setServiceVisibility` from interleaving (PWL-5).

`forget()`'s 30s is **untouched** — it is justified by its own per-row
`Service::delete()` teardown loop, not by the projection.

### Not dropped

`site.services` survives this task. `FreshaServiceProjector::revert()` is the
one method here that still reads it, serving
`UserServiceController::resync()`/`resyncBulk()`'s §C2 legacy branch; both call
sites short-circuit before reaching it for every live row. It goes with the
table in phase 6.


## Unit E — `site.content_selection` retired (phase 4, task 16)

**Owner ruling 2026-08-14 (binding): these keys are DELETED OUTRIGHT, not
dual-served.** `apps/pages` reads of them break **by design**. The pages app is
being rebuilt, not repaired, so there is no compatibility shim, no empty-array
placeholder and no transition alias. The replacement curation lane is
`pool:media` pins, which already exists and already ships in this same payload
under `profile.pools.media`.

### Keys that died

`GET /api/public/profiles/{handle}` — response `data`:

| Key | Was | Now |
|-----|-----|-----|
| `designMedia` (top level) | `DesignMediaItem[]` — the owner's resolved Content Selection (uploads / Google photos / Instagram reel+post), ordered by position | **absent** |
| `siteImages` (top level) | `{ logoFull?, logoSquare?, placeholder? }`, each `{url, urlHd, urlSvg, urlIcon}`; `{}` when nothing set | **absent** |
| `profile.gallery` | `GalleryImage[]` from the `site.site_media` gallery pool | **absent** |
| `profile.curatedGallery` | the same Content Selection projection as `designMedia`, in the resolver's row shape | **absent** |

`curatedGallery` is not named in the spec's key list but could not survive it:
it was the second consumer of `ContentSelectionService::resolve()`, which is
deleted with the table's other code.

Everything else on the payload is unchanged. `profile.pools` (including
`pools.media`), `designKit`, `architectureId`, `publicConfig`, `pageOrder`,
`popularity`, `rankedActions`, `ordering` and `policies` all keep their shapes.

### Owner routes that died

All four are gone from `routes/api/user.php`; the URLs now 404.

- `GET /api/content/selection`
- `PUT /api/content/selection`
- `PUT /api/content/instagram-auto`
- `PUT /api/content/google-photos`

**Kept:** `GET /api/content/library`, `POST /api/content/uploads`,
`DELETE /api/content/uploads/{upload}`. The library is still the browse surface
(content-pool uploads + referenced Google Business photos + referenced Instagram
post images); only the ordered *selection* on top of it retired.

The `content_photos` flag on the Google Business connection's `display_settings`
lost its **write** verb with `PUT /api/content/google-photos`. The library still
**honours** an already-stored `content_photos: false` (google photos stay out of
`GET /api/content/library`), so no owner's existing opt-out silently reverses.

### Code deleted

- `app/Services/Site/ContentSelectionService.php`
- `app/Models/Core/Site/ContentSelection.php`
- `app/Policies/ContentSelectionPolicy.php` (+ its `Gate::policy` registration)
- `app/Http/Requests/Api/User/Content/{ReplaceContentSelection,SetContentInstagramAuto,SetContentGooglePhotos}Request.php`
- `ContentController::{selection,replaceSelection,setInstagramAuto,setGooglePhotos}`
- `IntegrationConnectionObserver::{seedContentFromGoogle,reconcileContentInstagramSlots}`
  — a Google Business connect no longer seeds picks, and an Instagram connect no
  longer reserves reel/post slots. `enableContentInstagramAuto` (the pool
  auto-sync flag) is untouched and still fires.
- `SitepageDataResolverService::buildCuratedGalleryData` and its
  `curated_gallery_resolve` presence probe.
- `IndividualProfilePayloadBuilder::{buildGallery,buildDesignMedia,buildSiteImages}`.

### Data

Not dropped here. `site.content_selection` (95 rows on dev) still exists —
phase 6 owns the DROP migration. Slice 1b already carried the 3 `upload` picks
into `pool:media` pins; the other 92 (85 `google-photo`, 7 `ig-*`) are
deliberately **not** carried — checkpoint §15, not re-litigated.

### Residuals a later unit should know about

- **`pageOrder` can still advertise a `gallery` page.** `presentPageIds()` still
  sets `gallery` presence from ready `site.site_media` gallery-pool rows, but the
  payload no longer carries any gallery data. The presence lane was out of this
  task's scope; the rebuilt pages app either drops the page or the presence probe
  moves to `pool:media`.
- **`SitepageDataResolverService::{getGallery,getDesignSingletons}` are kept**
  with no production consumer. Both read tables that survive slice 7, both stay
  test-covered, and the rebuilt pages app will want a projection to attach to.
- **The k6 harness's gallery invariant lost its wire-level guard.** The
  `gallery-item-needs-a-webp-variant` rule was pinned by the two gallery-engine
  tests in `IndividualProfileControllerTest`, which went with the key. The
  resolver-level `getGallery()` filter is unchanged; nothing asserts it end-to-end
  any more.


## Phase 4 — standalone events leave the integrations wire (2026-08-16)

**Parent spec §7 "Carried from slice 2", all four steps.** Plan tasks 14 and 15.

> **STATUS: code landed, dev backfill NOT yet run.** `--dry-run` against dev
> reports **2 would-be-backfilled, 0 duplicate url, 0 skipped (no url), 0
> skipped (no site), 0 already curated, 0 failed** — the two live
> `resource_kind='event'` rows the spec named. Prod: out of scope.

### Why this could not happen in slice 2

Slice 2 retired the ACCOUNT half of the legacy events lane and deliberately left
STANDALONE rows publishing their full payload. A standalone row — one event
added by URL from the Tickets & Events card — has no ingest connector
(`ConnectorRegistry::MAP` holds organiser-level `eventbrite`/`humanitix` only,
and `SourceProvisioner` provisions from an ORGANISER url), so it landed no
`content.items` and the events pool could not represent it. Emptying its payload
then would have made add-an-event-by-URL publicly **inert** rather than
migrating it.

Slice 0b's manual write lane is what changed: `ProjectionWriter::writeManualItem()`
lands an owner-authored item with no connector, which is exactly what a
standalone event is.

### `GET /api/public/profiles/{handle}/integrations` (and its `/platforms` alias)

**Consuming repo:** partna-monorepo (`@partnaau/design-system`), public render.

#### BREAKING — a standalone event row now publishes `payload: []`

Before, a `resource_kind = 'event'` row under `eventbrite` / `humanitix` /
`events-custom` published the whole event as a flat object. **Every one of these
seventeen keys is now gone from that surface:**

    kind   id     name       venue     location    startDate   endDate
    description   startsAt   endsAt    price       priceMin    currency
    availability  soldOut    image     link

After:

    "platforms": {
      "eventbrite": [ { "resourceId": "event-6e96e3d5f9e3484f", "payload": [], "lastRefreshedAt": "…" } ]
    }

**An array, not `{}`** — the same shape account rows have published since
2026-08-14. All three events platforms carry an empty `ALLOWLIST` entry, so
`filterPayload()` returns `[]`.

The connection **envelope** is unchanged: `resourceId` and `lastRefreshedAt`
still emit, and the platforms stay REGISTERED (the dashboard connect/refresh
lane uses them, and `PublicAllowlistCoverageTest` requires every registered
platform to carry an entry — deleting them would report a
`MissingPublicAllowlistException` to Nightwatch on every public request).

**Where the events went:** `profile.pools.events`, which serves them from
`content.items` with `occurrence`, `place` and `price` facets the flat payload
could not carry, plus `slug`/`aliases` from `content.item_slugs`. Shape:
`docs/wire-changes/2026-08-11-slice2-events-pool.md`.

**With this, no row kind on `/integrations` publishes an event.** The legacy
events wire has no readers left.

Implementation: `PublicIntegrationConnectionResource::EVENT_PLATFORMS` and
`::STANDALONE_EVENT_KEYS` deleted with the `filterPayload()` branch that used
them. Guard: `tests/Feature/PublicSite/LegacyEventsLaneRetiredTest.php`.

### `POST /api/platforms/events/add` — the Tickets & Events card

**Consuming repo:** Partna-App, authenticated dashboard.

The EVENT arm (a pasted single-event URL) now writes a `content.items` row of
kind `event` in the `events` pool instead of a `resource_kind='event'`
connection. The ORGANISER arm and the CUSTOM-link arm are untouched.

Response shape is unchanged — still `{ selection: { accounts, events } }` — but
three things about a standalone event's entry change:

| | Before | After |
|---|---|---|
| `id` | `event-<hex16>` | a `content.items` uuid |
| `platform` | `eventbrite` / `humanitix` | `events-custom` |
| `source` | `standalone` | `link` |
| `removePath` | `/platforms/{platform}/events/{id}` | `/platforms/events/custom/{id}` |

The dashboard round-trips `id` and `removePath` opaquely, so this is invisible
to it. `platform` and `source` collapse because **a pool item has no platform** —
the same collapse convergence Phase 6 already made for hand-added events.

`name`, `link`, `description`, `venue`, `location`, `startDate`, `endDate`,
`startsAt`, `endsAt`, `priceMin` and `currency` all survive: the scrape is
projected onto `f_occurrence` / `f_place` / `f_text` / `offers` and read back by
`ManualEventWriter::cards()`, which was widened for exactly this. `price` (the
human "AUD 16.97 – 398.03" string), `availability`, `soldOut` and `image` are
now always `null`/`false` — see "Not carried" below.

**The cap SURVIVES**, moved rather than retired. It now lives on
`ManualEventWriter::MAX_STANDALONE_EVENTS` (still 10) and counts live
`event`-kind items on the owner's own MANUAL source. Idempotency on a
deterministic coord prevents duplicates of the *same* event; it does nothing
about an unbounded number of *different* ones, which is what the cap is for.
Two collapses were forced by the destination, and both TIGHTEN:

| | Before | After |
|---|---|---|
| scope | per platform (10 eventbrite + 10 humanitix = 20 reachable) | per owner (10) |
| counts | standalone connection rows only | every owner-authored pool event, hand-added cards included |

Neither can be avoided: a pool item carries no platform, and the pool keeps no
marker for which lane wrote an item — that indistinguishability is the point of
converging them. Message is unchanged bar the platform qualifier: **"You can add
up to 10 individual events."** Re-adding an event the owner already holds is
still an UPDATE and still never 422s, at the ceiling or below.

Deliberately NOT applied to the scan seeder, which keeps its own per-platform
cap on connection rows — see "The scan seeder" below.

**Retired 423 on this arm:** the standalone write no longer takes
`CacheKeyGenerator::platformConnectionLock`. It writes no connection, and an
idempotent upsert on a deterministic coord has no read-then-write span to
serialise. The ORGANISER arm still locks and still 423s.

One consequence, named rather than hidden: the cap check is now an unserialised
read-then-write, so two exactly-simultaneous adds can both pass it and land an
eleventh event. A soft ceiling on owner-authored cards is not worth taking a
cross-store lock for, and the same window has existed on the hand-add arm since
convergence Phase 6.

### `GET /api/platforms/events/selection`

A migrated standalone event is listed **once**. `EventsCatalog::selection()`
skips a standalone connection row whose canonical `link` already has a pool
card, keyed on `strtolower(trim(url))`. Deliberately a skip rather than deleting
the connection: the legacy rows stay live until Phase 6 drops the table (the
parent's ordering law), and the skip keeps an event added through the
per-platform verb visible here until the backfill next runs.

### The backfill

`php artisan content:backfill-standalone-events [--dry-run] [--user=]`
→ `App\Services\Migration\StandaloneEventBackfiller`.

**Coord: `manual:{sha1(strtolower(trim(link)))}`** — the event URL, not the
connection uuid, and byte-identical to `ManualEventWriter::coordFor()`. §1.7's
one-coord-per-canonical-URL rule: an owner re-adding the same event by hand
updates the item they already have. Two manual coords carrying one URL do not
merge — `Resolver::poisonedKeys()` drops a value a single source contributes
twice — hence the per-user dedupe in the runner.

The connection's own `event-<hex16>` resource_id is the first 16 hex of the same
`sha1`, so a migrated item joins back to the row that produced it by string
prefix, with no lookup table. Verified against dev — the two coords the run
would mint, derived independently in Postgres (`encode(digest(…,'sha1'),'hex')`)
and matching each row's `resource_id` prefix:

| owner | event | coord | already landed? |
|---|---|---|---|
| `019e5c37…` | Nerve Melbourne 2026 | `manual:6e96e3d5f9e3484f14cd54b0a399cc187b87d16d` | no |
| `019f8f5d…` | HOBART MENS HAIR WORKSHOP / … | `manual:ba7bc4f70f5055718acbbecf7d193d34b1dfadd3` | no |

**Curation.** An active row is **pinned**; an `is_active = false` row is
**excluded**. First write wins, so a re-run never restates its opinion over the
owner's.

Pinned rather than left to the events pool's own `kind_is` + `upcoming_occurrence`
rule, deliberately: the legacy wire published a standalone event's payload
REGARDLESS of its dates, and the rule alone would silently drop one that has
already started. Dev's `nerve-melbourne-2026` starts in **2024** and runs to
December 2026 — under the rule alone it would vanish, turning this retirement
into a partial blackout instead of a migration.

**Not carried:** the payload's `image`. Phase 3 declined to mint
`content.media_assets` for third-party image URLs and the hand-added arm
inherited that ruling; an `img.evbuc.com` URL is a hotlink with its own expiry,
not an asset this platform owns. Also not carried: `price` (the display string —
`priceMin` + `currency` are the structured form and both survive),
`availability` and `soldOut`, none of which the pool's item shape has a home for.

**Re-runnable by design** (parent invariant #4). Its remaining job after this
phase is the pre-cutover rows and anything the scan seeder wrote before the dual
write landed; every live add path now lands the item itself.

### Deploy order — this matters

1. Deploy the code.
2. Run `content:backfill-standalone-events` against dev.

Between (1) and (2) the two existing standalone events publish nothing on
`/integrations` and have no pool item either — they are dark on the public
sitepage for that window. There is no migration to apply, so the window is the
length of one artisan command.

### Permalinks — the §7 step-4 decision

**Decision: `content.item_slugs` re-mints them; nothing is copied across, and
the 11 legacy `site.item_slugs` `item_type='event'` rows are deleted in Phase 6
as planned.**

Reasoning, in the order it was checked:

1. `event` is already in `ContentItemSlugAllocator::SLUGGED_KINDS`, and
   `ProjectionWriter::refreshItemCaches()` mints from `headline_cache` for every
   kind in that list. So a backfilled standalone event gets a
   `content.item_slugs` row **automatically** — no extra step, no new code.
2. The two allocators derive their base **identically**: `Str::slug($name)`,
   80-char truncate on a word boundary. `ItemSlugAllocator::base()` and
   `ContentItemSlugAllocator::base()` are the same function over different
   tables.
3. The headline the pool slugs from IS the payload `name` the legacy lane
   slugged from, because the projection sets `headline` from `name`.

Checked against the real dev rows rather than assumed:

| legacy `site.item_slugs.slug` | `Str::slug(payload.name)` |
|---|---|
| `nerve-melbourne-2026` | `nerve-melbourne-2026` |
| `hobart-mens-hair-workshop-at-simondoylehair-at-development-au` | identical (61 chars, under the 80 cap) |

Both reproduce byte-for-byte, so **the permalinks these two events already
publish keep resolving** — served from `content.item_slugs` instead of
`site.item_slugs`, at the same URL.

4. Collision checked, not assumed: neither `nerve-melbourne-2026` nor
   `hobart-mens-hair-workshop-…` is currently held in `content.item_slugs` by
   any other item of either owner (`019e5c37…` holds 72 content slugs, none
   matching; `019f8f5d…` holds none at all). So the allocator lands the bare
   base, not a `-2` suffix.

This also **closes a slice-2 known regression**. That manifest recorded
`nerve-melbourne-2026` and `hobart-mens-hair-workshop-…` as two of the three
legacy slugs that "do not carry over… their permalinks stop resolving. Accepted
by the owner 2026-08-12". Both are standalone rows, and both resolve again once
the backfill runs. The third, `grand-organ-recital-…`, is an account event that
ingest never landed and is unaffected by this phase.

Copying the legacy rows across was rejected: `site.item_slugs` is keyed
`(item_type, item_key)` where `item_key` is a payload hex id, `content.item_slugs`
is keyed `item_id` — a real FK to `content.items`. A copy would have to invent
the mapping the re-mint gets for free, and would then be a second, hand-written
derivation of a slug the allocator already owns.

Guards: the re-mint is pinned by
`tests/Feature/Content/StandaloneEventBackfillTest.php` ("re-mints the legacy
permalink…") and, for the live add path, by
`tests/Feature/Platforms/EventsCatalogTest.php` ("mints a content.item_slugs
permalink for a pasted single event"), which also asserts nothing lands in
`site.item_slugs` any more.

### `POST /api/platforms/{eventbrite|humanitix}/events` — repointed too

**Consuming repo:** Partna-App, the per-platform Eventbrite / Humanitix cards.

`EventsPlatformController::addStandaloneEvent()` calls `writeConnection()`
directly and never flowed through `EventsCatalog::storeStandalone()`, so
repointing the card path did not cover it. Both routes are live. It now writes
the same pool item through the same `ManualEventWriter` lane, on the same coord.

The response shape is `selectionData()`, unchanged in structure. Three
consequences:

- **`DELETE /api/platforms/{platform}/events/{id}`** accepts a content-item uuid
  and removes the pool item. The legacy arms — a pre-repoint standalone
  connection, and the account-event hide — are tried after it, in that order.
  The two id shapes cannot collide (uuid vs hex behind an `event-` prefix).
- **`GET /api/platforms/{platform}/selection`** reads the standalone half from
  the pool. Because a pool item has no platform, this per-platform endpoint now
  lists the owner's hand-added and other-platform standalone events too. It must
  list them: the add verb's own response IS this shape, so an event missing from
  it reads as an add that did nothing.
- The **423** on this route is gone, for the same reason as on the card path.
  `SessionA2LockTest`'s case is inverted rather than deleted, so the lock's
  disappearance stays witnessed.

`DELETE /api/platforms/{platform}` (forget) still clears connections only. It
does not clear pool events, deliberately: those are cross-platform and include
hand-added cards, so a per-platform forget must not reach them.

### The scan seeder — a DUAL WRITE, not a repoint

`EventsSeeder::seedStandalone()`, reached from `LinkRouter` and
`CommerceProbeJob`, was a **third** live writer of `resource_kind='event'` rows.
It now writes the connection **and** the pool item.

The connection is kept rather than repointed because it is what the synced-modal
finding lane resolves against: `shapeFinding()` in BOTH `InstagramController`
and `GoogleBusinessController` looks a seeded finding up by
`platform|resourceId` over connection rows and derives its status from
`last_refresh_status`, dropping anything it cannot match. Repointing here would
silently remove every scanned event from that modal. Teaching that lane to
resolve a pool item is its own piece of work in the scan surface.

This is not two lanes that can disagree: the connection publishes `[]` either
way, so it is dashboard-and-scan bookkeeping now — exactly what an events
ACCOUNT row became in slice 2. Both selection readers skip a connection row
whose canonical URL already has a pool card, so the pair is never listed twice.

The pool half is **uncapped**, governed by the seeder's own unchanged
per-platform connection cap. Capping the item while still writing the connection
would reintroduce the invisible-event bug this phase exists to fix.

**No live path writes a standalone event that fails to publish.** All three are
covered: `tests/Feature/Platforms/StandaloneEventPoolLaneTest.php`.

### Not changed

`site.item_slugs` is untouched; its 11 `item_type='event'` rows still exist and
the menu lane still uses the table. Dropping it is Phase 6.

`hiddenEventIds` is still written by the dashboard and still never public.

The Eventbrite/Humanitix scrapers, `EventsPayload`, `ProviderDetector`, the
daily refresh, and the whole ORGANISER lane are untouched.

---

## `GET /api/public/profiles/{handle}/menu` — DELETED (Task 10, unit B)

**Consuming repos: partna-monorepo** (`@partnaau/design-system` — the sitepage
menu page and its per-dish detail pages, which fetched this endpoint as an Astro
subrequest). **Partna-App is unaffected** — the dashboard reads
`/api/platforms/menu`, a different controller, untouched here.

**BREAKING, by design.** The endpoint is gone from the router. It does not 410,
it does not serve an empty payload, and it is not aliased anywhere: a request to
it now falls through to Laravel's catch-all 404 exactly as an unrouted path
does. `PublicMenuController` and its route are deleted.

### What replaces it

`pools.menus` on `GET /api/public/profiles/{handle}` — already live, already
composed by `MenuPayloadComposer` off the same `site.menus` rows. It is a
superset, not a port:

| Old `/menu` | `pools.menus` |
|---|---|
| `data.storeName`, `data.currency` | on the pool |
| `data.categories[]` (categories only) | `collections[]` — categories **and** ordering-platform store cards |
| `categories[].items[]` | `items[]`, referenced by collection |
| `items[].links` (`{doordash?}`) | same shape, same builder (`MenuItemDeepLinks`) |
| `items[].slug` / `aliases` | permalinks with **301 aliases** the legacy lane never served |
| — | `diningModes` |

`MenuItemDeepLinks` is **not** deleted despite the spec listing it under this
unit: `MenuPayloadComposer:175` calls it to build the pool's own per-item
`links`, so it is shared, not legacy-only. The spec's assumption that it was
single-use predates the pool composer.

### Keys that died

Every key below existed **only** on this endpoint's envelope. Each one is
either reproduced by `pools.menus` under a different path (above) or was
internal bookkeeping the pool never needed:

`data.storeName`, `data.currency`, `data.categories[].name`,
`data.categories[].id`, `data.categories[].popularityRank`,
`data.categories[].items[].{id, slug, aliases, name, description, imageUrl,
images, price, pickupPrice, deliveryPrice, currency, rating, ratingCount,
badges, platforms[], links, popularityRank}`.

### Sitepage reads break — and that is the ruling, not an oversight

Under the **2026-08-14 owner ruling** the sitepage frontend is REBUILT, not
repaired. There is no compatibility window and no deprecation period on this
endpoint, because the consumer that would need one is itself being replaced.
Spec decision D2 rejected repointing for exactly this reason: a repoint would
have stood up a second read path with no consumer.

### Cloudflare purge set shrank by one URL

`CloudflarePurgeService::purgeHandle()` no longer emits
`{api}/api/public/profiles/{handle}/menu`. The per-dish **page** URLs on the
site host (`{handle}.partna.au/menu/<slug>`) are unchanged — those are sitepage
routes, not this API endpoint, and they now render from `pools.menus` on the
profile URL, which was already the first entry in the purge set. Max-volume
purge estimate moves 2,682 → 2,681 URLs; the per-handle set moves from 4 API
URLs to 3.

### Not changed

`site.menus`, `site.menu_categories`, `site.menu_items`,
`site.menu_platform_links` and `site.item_slugs` are all untouched — the
dashboard lane and `pools.menus` both still read them.

`/api/platforms/menu` (the authenticated dashboard surface) is untouched, as is
the Google Business `menu` display toggle: the pool runs the same gate the
deleted controller did, via `SitepageDataResolverService`.

`ItemSlugAllocator::lookupCurrent()` survives the deletion but now has **zero
`app/` callers** — the pool lane uses `ContentItemSlugAllocator::lookupCurrent()`
instead. Only `tests/Unit/Services/Site/ItemSlugAllocatorTest.php` still
exercises it. Retiring it belongs to the unit that retires the legacy menu
writer, not here.

**Guard:** `tests/Feature/PublicSite/PublicMenuRouteRetiredTest.php` — pins the
404, pins that no `api/public/**/menu` route is registered at all, and pins that
`site.menus` survived.

## Phase 3 Task 12 — the services residuals (2026-08-16)

Three code sites, one theme: `site.service_category_assignments` and
`site.service_categories` both DROP in Phase 6, so the last writes to the first
and the last *listing* read of the second are gone. The Fresha lane was cut over
first — `FreshaServiceProjector` no longer writes `site.services`, Fresha
services are `content.*` items under a `content.sources.kind='connection'`
source, and their categories are `content.collections` of kind
`service_category` — so both were maintaining a lane nothing reads back.

### `POST /api/services/reorder-layout` and its staff twin

`UserServiceController::reorderLayout()` and
`StaffServiceManagementController::reorderLayout()` each carried a per-Fresha-
service replace-set (pluck current → detach the diff → insert the diff) against
`site.service_category_assignments`, sitting immediately beside the
`ServiceCollections::reposition()` call that does the real `content.*` work.
Both are deleted.

**Behaviour change, deliberate:** a payload that re-files a Fresha service into a
different `site.service_categories` block still returns 200 and still applies
**order** (`site.services.sort_order`, `site.section_items.sort_key`,
`content.collections.position`), but the legacy membership row does **not**
move. Nothing reads that pivot for rendering any more. Validation is unchanged —
the two 422 id-space guards, the categorised-vs-uncategorised check and the
per-space coverage checks all still run, so `$membershipsByService` survives as
validation input only.

Pinned by `tests/Feature/Services/ServiceCategoryAssignmentRetirementTest.php`;
`tests/Feature/User/ServiceLayoutReorderTest.php`'s "moves a service to a
different category" case is inverted rather than deleted — it now asserts the
membership stays put and the order still applies.

### `GET /api/staff/professionals/{id}/service-categories`

**BREAKING for the staff dashboard.** `index()` merged two id spaces —
`content.collections` **and** `site.service_categories`. Slice 3b handed that
merge over explicitly ("removing it is not optional cleanup: left in place it
queries a dropped table"). The legacy half is gone: the list now returns
`content.collections` rows only, so a professional's Fresha-era categories
vanish from that staff list.

Everything else in that controller is untouched. `show`, `update`, `destroy`,
`restore` and `reorder` still resolve a legacy row **by id** — the by-id
branches, and the seven routes' split across the two staff middleware groups
(`index`/`show`/`restore` any role, the five write verbs under `staff.admin`),
are exactly as they were. Only the listing stopped reading the table.

### Residuals for Phase 6, deliberately left

`site.service_category_assignments` is still written by
`StaffServiceCategoryManagementController::destroyLegacy()` (a raw detach before
soft-deleting a legacy category) and by `->categories()->sync()` in both
controllers' legacy `category_id` branches. Both hang off the `Service` /
`ServiceCategory` models, which Task 27 Step 5 deletes with the tables; removing
them here would change the legacy branches' behaviour ahead of their retirement.

---

## Phase 2 Task 6 — the ten owner menu verbs write `content.*`

**Consuming repo: Partna-App** (the dashboard menu editor — `GET/POST/PATCH/DELETE
/api/platforms/menu/*`). The public sitepage is unaffected: it has read
`pools.menus` since Task 10 deleted the standalone endpoint.

Endpoint set, request shapes, response shape and status codes are all unchanged.
Four behavioural notes and one regression.

### 1. `items[].isManual` is now always `false`

`ManualMenuItems::toMenuItemModel()` reports `is_manual` as a flat `false` for
every content-lane dish (its own documented, deliberate null — the projection
never carried the column). Before this task an owner-authored dish reached the
payload through the LEGACY fallback and so still reported `true`.

The underlying signal is not lost — it moved to `content.manual_overrides`
(below) — but nothing joins that table into the dashboard payload yet. The
dashboard's only reader is the "this will no longer stay synced" warning, which
now shows for a dish that is already detached: it warns too often, never too
little. Re-teaching the read side is a `ManualMenuItems` change and is not owned
by this task.

### 2. `categories[].sourcePlatform` is `'manual'` or `null` — the three
owner-side strings collapsed

`'scan'` and `'website-scan'` no longer appear. `content.collections` has no
source column, so the owner-side half of `site.menu_categories.source_platform`
is carried by `is_user_created` and reports as `'manual'`; scraper-owned
categories report `null`, as before.

This RESTORES the key rather than removing it: Task 5 shipped a flat `null` for
every content-lane category, which told the dashboard that a category the owner
had just created was off-limits to edit.

### 3. A dish's id no longer changes when it is renamed

The write re-uses the dish's stored coord, so a rename updates the item in
place. The legacy lane churned the uuid whenever the scrape re-inserted it.
Dish-detail deep links built from this id are now stable across a rename.

### 4. Creating a dish whose name matches an existing one UPDATES that dish

The coord is `manual:menu:{menu_id}:{sha1(normalizeName(name))}` — one
normalised name is one dish, menu-wide. Two dishes that differ only in
punctuation or case can no longer coexist. Re-adding a dish the owner previously
deleted restores it (id, slug and all) rather than minting a second row.

### 5. `items[].links` and `pickupSource` / `deliverySource` stay null

Unchanged from Task 5's manifest entry — the mapper never carried
`dd_external_id`, so no per-dish deep link is derivable.

### Not changed

`id`, `name`, `description`, `image`, `images`, `rating`, `ratingCount`,
`badges`, `basePrice`, `pickupPrice`, `deliveryPrice`, `currency`,
`categoryIds`, `platforms[]`, and every category's `id` / `name`. Dish order is
still the owner's `pool:menus` pin order.
