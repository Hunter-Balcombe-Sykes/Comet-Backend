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

### ACCOUNT rows on `eventbrite` / `humanitix` / `events-custom` publish nothing

An **account row** is an organiser feed — `{url, organiser, next,
upcoming[]}`, with `slug`/`aliases` stamped onto every nested event.

After: **`payload` is `{}`** for account rows.

    "platforms": {
      "eventbrite": [ { "resourceId": "acct-abc", "payload": {}, "lastRefreshedAt": null } ]
    }

The connection **row and its envelope remain** (`resourceId`, `payload`,
`lastRefreshedAt`), so a consumer iterating `platforms` sees no shape change —
only an empty payload. The platforms stay registered because the dashboard
connect/refresh lane still uses them.

### STANDALONE event rows are UNCHANGED — read this before migrating

A row with `resource_kind: "event"` (one event added by URL from the Tickets
& Events card) **still publishes its event fields** exactly as before:

    kind, id, name, venue, location, startDate, endDate, description,
    startsAt, endsAt, price, priceMin, currency, availability, soldOut,
    image, link

The two keys it **loses** are `slug` and `aliases`.

Why the exception: standalone rows have no ingest connector, so they land no
`content.items` and have **no pool representation at all**. Retiring them
would have made the add-an-event-by-URL feature publicly inert rather than
migrating it. They move to the pool when the manual write lane lands.

**So: an Events page may need BOTH sources** until then — `pools.events` for
the organiser feed, and standalone `resource_kind: "event"` rows from this
endpoint for one-off events.

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

## Deploy order — these are migration steps, not optional

Run in this order, `--dry-run` first:

1. `php artisan content:backfill-item-slugs` — seeds `content.item_slugs`.
   Nothing serves a slug until this runs. **Ongoing** minting is automatic
   from here (the projector mints on every headline change), so this is a
   one-off.
2. `php artisan content:migrate-hidden-events` — carries `hiddenEventIds`
   into pool excludes. Without it, previously-hidden events become visible.

## Known regressions

**Hides made in the dashboard AFTER the migration do not reach the pool.**
`EventsPlatformController::removeEvent()` still writes only `hiddenEventIds`,
which the pool does not read. The migration is a one-shot; the dashboard verb
was left pointing at the legacy lane pending an owner decision on where the
Events dashboard should live (Task 9 Step 6, unmade). Until that lands, an
owner hiding an event from the dashboard will still see it on their page.

**Three legacy slugs do not carry over** (measured on dev 2026-08-12: 12
current event slugs, 9 mapped, 3 unmapped). `grand-organ-recital-…` and
`nerve-melbourne-2026` and `hobart-mens-hair-workshop-…` have no content item
— two were never landed by ingest, one belongs to a standalone connection
that lands no records. Their permalinks stop resolving.

Note the 9 that do map keep their exact slug only because `headline_cache`
and the scraped event `name` agree today. The pool mints from the headline;
the legacy lane minted from the payload name and pinned it. They are not
guaranteed to agree in future.

## Not changed

`site.item_slugs` is untouched — its rows still exist and the menu lane still
uses it. Dropping it is slice 7's teardown.

`hiddenEventIds` is still written by the dashboard and still never public.
