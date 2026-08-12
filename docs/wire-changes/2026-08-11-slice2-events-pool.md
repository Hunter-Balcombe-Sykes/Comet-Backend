# Wire change — slice 2, events pool (2026-08-11)

Backend-only execution; the frontends are told, not designed around
(spec `2026-08-11-content-pool-convergence-design.md`, owner decision).

## `GET /api/public/profiles/{handle}`

**Consuming repo:** partna-monorepo (`@partnaau/design-system`), Partna-App.

### New: `profile.pools.events`

Before — key absent. After:

    "pools": {
      "events": { "items": [ { ...poolItem } ], "latestItemId": null }
    }

Same envelope as `pools.watch` / `pools.listen`. `latestItemId` is always
null for events: a dated list is already ordered by when it happens, so a
Latest badge on the soonest event would read as "new".

Ordering: **pins first, in the owner's drag order, then upcoming events
soonest-first.** Pins are not date-ordered — `PoolResolver::resolve()` builds
`[...$pinned, ...$ruleIds]`. A hand-added event (which `PoolItemCreateController`
pins and which carries no `f_occurrence`) therefore sits first with
`startsAt: null`. Render accordingly.

An event with no start date is never auto-selected but can be pinned.

### Changed: every `poolItem` gains eight keys

Additive and nullable on every pool, not just events — same contract
`durationSeconds` already has. No existing key changed or removed.

| Key | Type | Null when |
|---|---|---|
| `startsAt` | timestamptz text \| null | no `f_occurrence` |
| `startsAtLocal` | string \| null | no `f_occurrence` |
| `endsAtLocal` | string \| null | no end date |
| `timezone` | IANA string \| null | zone unknowable from the offset (usual for scraped events) |
| `venue` | string \| null | no `f_place` |
| `locality` | string \| null | no `f_place` |
| `price` | `{amountMinor, amountMaxMinor, currency, qualifier}` \| null | no offer |
| `availability` | string \| null | no offer |

**`startsAt` is NOT ISO 8601.** It is Postgres's timestamptz rendering,
`"2026-08-29 01:00:00+00"` — the same shape `publishedAt` has already been
emitting, so whatever parses that parses this. `new Date()` accepts it in
Chrome but returns `Invalid Date` in Safari and Firefox; parse it explicitly
or replace the space with `T`.

Where several sources describe one event, `startsAt` is the **soonest** and
`price` the **cheapest** — matching the section's ordering. `venue`/`locality`
come from the most recently updated `f_place`.

`price.amountMinor` and `price.amountMaxMinor` are each independently
nullable **inside** a non-null `price` object: an offer can exist with a
qualifier (`on_request`, `variable`) and no amount.

`availability` qualifies the **quoted (cheapest) price**, not the event — a
cheapest tier that sold out reads `sold_out` while dearer tiers are still on
sale. If the render needs event-level availability, say so and it becomes a
rollup; today it deliberately agrees with the price beside it.

`price.qualifier` ∈ `exact｜from｜upto｜range｜free｜variable｜on_request`.
Scraped events land `from`, or `free` at zero: the scrape sees the lowest
tier of a multi-tier offer set and `from` is the only honest reading. On dev
today the live spread is one `from` at 661 AUD and the rest `free` at 0.

### New: `slug` and `aliases` on every `poolItem`

| Key | Type | Meaning |
|---|---|---|
| `slug` | string \| null | The item's current public URL slug. `null` when none has been minted. |
| `aliases` | string[] | Every other value that should **301 to `slug`** — retired slugs first, then the raw item id. Never empty: the raw id is always present. |

This replaces the identically-shaped `slug`/`aliases` the legacy events lane
stamped onto the integrations payload, and degrades the same way (`slug:
null`, `aliases: [id]`) so a permalink resolves rather than 404s.

Served from `content.item_slugs`. A slug retires rather than being deleted,
and keeps redirecting for `partna.item_slugs.retirement_days` (90) before the
prune sweep removes it.

### Page presence

`page_order` may now include `events` for a user with a non-empty events pool
selection and no active ticketing connection — e.g. hand-added events.

**Changed:** it may also now EXCLUDE `events` for a user who has a ticketing
connection but nothing upcoming. See "Removed" below.

## Removed — the legacy events lane

`GET /api/public/profiles/{handle}/integrations` (and its `/platforms` alias).

### `eventbrite`, `humanitix` and `events-custom` publish nothing

Before, each carried a filtered payload — account rows `{url, organiser,
next, upcoming[]}`, standalone rows the flat event fields — with `slug` and
`aliases` stamped onto every event object.

After: **`payload` is `{}`** for all three.

    "platforms": {
      "eventbrite": [ { "resourceId": "acct-abc", "payload": {}, "lastRefreshedAt": null } ]
    }

The connection **row and its envelope remain** (`resourceId`, `payload`,
`lastRefreshedAt`), so a consumer iterating `platforms` sees no shape change —
only an empty payload. The platforms stay registered because the dashboard
connect/refresh lane still uses them; they are simply dashboard-only now.

**Migrate to `profile.pools.events`.** It carries the same events with more:
occurrence, place and price facets the legacy shape could not express, plus
owner curation (pins, excludes) the legacy lane expressed only as a private
`hiddenEventIds` list.

`hiddenEventIds` was never on the public wire and still is not.

### Events-page presence is now pool-derived

`eventbrite` / `humanitix` / `events-custom` were removed from the
backend's platform→page presence map. A ticketing connection **no longer
grants the Events page on its own**; a non-empty events pool selection does.

Net effect: an organiser whose events have all finished no longer advertises
an empty Events page. Previously the page appeared as long as the connection
existed. This also removes the `events` entry from the site actions catalog
for such a user.

### Owner curation was migrated, not dropped

Events an owner had hidden via `hiddenEventIds` were carried into the pool as
section excludes (`content:migrate-hidden-events`), so they stay hidden. The
legacy lane hid at write time, pruning hidden events out of the stored
payload; the pool reads `content.items` directly, so without the migration
they would have reappeared.

## Not changed

`site.item_slugs` is untouched — its rows still exist and the menu lane still
uses it. Dropping it is slice 7's teardown.

Four of the 13 legacy event slugs had no corresponding content item and were
therefore not carried over (two whose events the ingest pipeline never landed,
two belonging to standalone connections that land no ingest records). Their
permalinks stop resolving with this change.
