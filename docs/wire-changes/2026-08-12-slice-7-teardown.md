# Wire changes — slice 7, the legacy teardown

Programme spec: `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md`.
Plan: `docs/superpowers/plans/2026-08-12-slice-7-teardown.md`.

One file per slice elsewhere in this directory; slice 7 spans six phases, so this
one is **appended to, phase by phase**. Each section states its own status.

---

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
