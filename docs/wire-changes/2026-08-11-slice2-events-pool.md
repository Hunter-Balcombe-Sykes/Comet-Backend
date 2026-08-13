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

After: **`payload` is `[]`** for account rows.

    "platforms": {
      "eventbrite": [ { "resourceId": "acct-abc", "payload": [], "lastRefreshedAt": null } ]
    }

**An array, not `{}`** — corrected 2026-08-14; this section said `{}` from
2026-08-11 to 2026-08-14 and the wire never matched it. All three events
platforms carry an empty `ALLOWLIST` entry, so `filterPayload()` returns
`array_intersect_key($payload, array_flip([]))`, which is an empty PHP array,
and PHP has no distinct empty-map literal to encode it as. A consumer doing
`Object.keys(payload)` on the strength of the old wording should be doing
`Array.isArray(payload)` — same correction as the shop lane in
`docs/wire-changes/2026-08-12-slice-5b-shop-render.md`.

The connection **row and its envelope remain** (`resourceId`, `payload`,
`lastRefreshedAt`), so a consumer iterating `platforms` still finds every key
where it was — only the payload is empty. The platforms stay registered because
the dashboard connect/refresh lane still uses them.

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

> **STATUS: all three have now RUN on development (2026-08-12).** The figures
> throughout this manifest are post-migration and measured, not projected.
> Production has not run them.

Run in this order, `--dry-run` first:

1. `php artisan content:backfill-item-slugs` — seeds `content.item_slugs`.
   Nothing serves a slug until this runs. **Ongoing** minting is automatic
   from here (the projector mints on every headline change), so this is a
   one-off.
   **Dev result: minted 14, skipped 0.**
2. `php artisan content:migrate-hidden-events` — carries `hiddenEventIds`
   into pool excludes. Without it, previously-hidden events become visible.
   **Dev result: "none to migrate" — dev holds zero `hiddenEventIds`, so this
   lane is a genuine no-op there and remains unverified against live data.**
3. `php artisan content:repair-event-items --retire` — retires event items
   whose every source item is gone. **One-way.** **Dev result: 3 retired.**

### What dev holds after those three ran

| Measure | Value |
|---|---|
| `content.item_slugs` rows | **14** (all `is_current`) |
| …on a LIVE item | 11 |
| …on a RETIRED item | 3 — the items retired by step 3; the rows survive as 301 history |
| Live `content.items` of kind `event` | 11 |
| `site.sections` with `key = 'pool:events'` | 2 |
| `site.pages` with `key = 'events'` | 2 |
| Legacy `site.item_slugs` of `item_type = 'event'` | 12, all current (untouched) |

Sections and pages provision on demand at first read, so 2/2 is the count
after the pool was read — not a backfill.

## Known regressions

**~~Hides made in the dashboard AFTER the migration do not reach the pool.~~
FIXED 2026-08-12 (`1197052f8`).** `removeEvent()` used to write only
`hiddenEventIds`, which the pool does not read, so every hide made after the
one-shot migration silently did nothing. It now also writes a `section_items`
exclude via `EventExcludeSync`, using the same `EventsPayload::id()` hash rule
as the migration command so the one-shot and the ongoing path cannot drift.

Best-effort by design: a standalone event lands no content item, so "no pool
item matched" is an ordinary outcome and the legacy hide still stands on its
own. Nothing about the dashboard's request or response shape changed.

Still open, and a product decision rather than a defect: the Events dashboard
screen itself continues to read the legacy lane (Task 9 Step 6, unmade). Hiding
works correctly in both lanes now; where that screen should ultimately live is
the open question.

**Three legacy slugs do not carry over.** RE-MEASURED after the backfill
actually ran (2026-08-12): of the 12 current legacy event slugs, **9 now exist
in `content.item_slugs` and all 9 sit on a LIVE item**; 3 do not carry over.
The pre-migration prediction of 9/3 held exactly.

`grand-organ-recital-…`, `nerve-melbourne-2026` and `hobart-mens-hair-workshop-…`
have no content item — two were never landed by ingest, one belongs to a
standalone connection that lands no records. Their permalinks stop resolving.
Accepted by the owner 2026-08-12 (dev only, no customers).

**Separately, 3 of the 14 minted slugs point at RETIRED items.** Those are not
the same three. They belong to events whose every source item had been removed
upstream, retired by `content:repair-event-items --retire`. The slug rows
survive deliberately — a retired item's URL should 301 rather than 404 — but
the items no longer render in the pool, so live event items (11) is three fewer
than slug rows (14).

Note the 9 that do map keep their exact slug only because `headline_cache`
and the scraped event `name` agree today. The pool mints from the headline;
the legacy lane minted from the payload name and pinned it. They are not
guaranteed to agree in future.

## Not changed

`site.item_slugs` is untouched — its rows still exist and the menu lane still
uses it. Dropping it is slice 7's teardown.

`hiddenEventIds` is still written by the dashboard and still never public.
