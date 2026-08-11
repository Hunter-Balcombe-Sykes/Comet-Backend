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
| `startsAt` | ISO 8601 UTC \| null | no `f_occurrence` |
| `startsAtLocal` | string \| null | no `f_occurrence` |
| `endsAtLocal` | string \| null | no end date |
| `timezone` | IANA string \| null | zone unknowable from the offset (usual for scraped events) |
| `venue` | string \| null | no `f_place` |
| `locality` | string \| null | no `f_place` |
| `price` | `{amountMinor, amountMaxMinor, currency, qualifier}` \| null | no offer |
| `availability` | string \| null | no offer |

Where several sources describe one event, `startsAt` is the **soonest** and
`price` the **cheapest** — matching the section's ordering.

`price.qualifier` ∈ `exact｜from｜upto｜range｜free｜variable｜on_request`.
Scraped events land `from`, or `free` at zero: the scrape sees the lowest
tier of a multi-tier offer set and `from` is the only honest reading. On dev
today the live spread is one `from` at 661 AUD and the rest `free` at 0.

### Page presence

`page_order` may now include `events` for a user with a non-empty events pool
selection and no active ticketing connection — e.g. hand-added events. Pools
add presence and never remove it, so no user loses the Events page: an
Eventbrite-connected owner with nothing upcoming keeps the page they have
today.

## Not changed in this slice

The legacy events lane on `GET /api/public/integrations/{handle}`
(`eventbrite` / `humanitix` payload keys, `hiddenEventIds` curation) is
untouched. Until it is retired, an Eventbrite-connected user's events appear
in **both** surfaces — consumers should pick one. See slice 2 Task 9.
