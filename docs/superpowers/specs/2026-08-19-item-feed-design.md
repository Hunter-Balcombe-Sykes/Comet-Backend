# Item Feed — item-level ranked list for the lander

**Date:** 2026-08-19
**Status:** Implemented 2026-08-19, branch `feature/item-feed-2026-08-19`
**Owner decisions:** all sections approved in-session 2026-08-19

## 1. What this is

A new public-wire surface: one mixed, ordered list of **individual content items**
(videos, songs, dishes, services, products, events, links) drawn from the site's
content pools, rendered by the existing lander alongside — not replacing — the
button-level `rankedActions` system.

Three ordering modes, one site-level setting (**default: `newest`**):

- **manual** — the owner curates the exact list and order.
- **newest** — recency order (a new video outranks the song released before it).
  The default mode (owner decision 2026-08-19; rationale in §5).
- **score** — most-interacted-first, from existing item popularity scores.

Menu and service items never appear out of their category: a category is a single
**block** occupying one position in the list, with its items ordered inside it.

## 2. Relationship to what exists

| Existing piece | Role here |
|---|---|
| `content.*` pools via `PoolResolver` (`app/Site/Pools/PoolResolver.php`) | The sole item source. The feed references pool items; it stores no items. |
| `analytics.content_popularity_scores` + `analytics:compute-popularity` (15-min) | The sole score source for score mode. The feed computes nothing. |
| `item-seen` / click beacons | Unchanged. Lander items keep firing them; no new analytics lane. |
| `SiteActionsService` / `rankedActions` | Untouched. The feed is a sibling surface. |
| `IndividualProfilePayloadBuilder` | Calls the new resolver once per payload build. |

Deliberately rejected alternatives (recorded so they aren't re-litigated):

- **Materialized feed table** — pools already resolve in-request behind the 60s
  payload cache + edge cache; a table adds storage and staleness for no read-path
  win at current scale. It remains a *later optimization behind the same wire* if
  profiling ever demands it.
- **Item-level demand-rate scoring** (per-slot seen/tap beacons, Bayesian priors
  per kind, a new computer — the `RankedActionsComputer` pattern at item
  granularity) — a whole new analytics lane whose tuning is guesswork pre-traffic.
  It can replace the score *source* later without touching the wire shape.

The wire contract is the durable artifact; every internal is swappable behind it.

## 3. Wire shape

New top-level key on `GET /api/public/profiles/{handle}`, beside `rankedActions`:

```jsonc
"feed": {
  "mode": "score",            // "manual" | "newest" | "score"
  "entries": [
    { "kind": "item",     "pool": "watch",  "itemId": "<content item id>", "score": 0.41 },
    { "kind": "category", "pool": "menus",  "collectionId": "<id>",
      "itemIds": ["<id>", "<id>"],          "score": 0.29 },
    { "kind": "item",     "pool": "shop",   "itemId": "<id>",              "score": null }
  ]
}
```

- **References only.** The lander joins `itemId` against
  `profile.pools.<pool>.items[].id` it already receives; `collectionId` against
  the pool's `collections` map. Item bodies are never duplicated.
- `kind` is a discriminator: `item` ⇒ `itemId`, no `itemIds`/`collectionId`;
  `category` ⇒ `collectionId` + `itemIds`. Illegal combinations are
  unrepresentable, mirroring `manual_actions` strictness.
- **Full list, ordered; the lander truncates.** No server cap (owner decision).
- `score`: blended popularity score in score mode; `null` when unscored or in
  manual/newest mode.
- The `feed` key is **always present** (empty `entries` when nothing resolves) —
  the lander never branches on key existence.

### Pool coverage

All pools except `reviews`: `watch`, `listen`, `media`, `events`, `services`,
`shop`, `custom_links`, `menus`.

- `reviews` is excluded: a review has no destination (no `url`, no page anchor)
  and the pool is exclusion-only by contract — it cannot be an *actionable* entry.
- **Category entries come from `menus` and `services` only.** Shop has
  `collections` too, but shop products float individually (owner decision
  2026-08-19).
- A menu/service item with no `collectionIds` floats as a plain `item` entry —
  uncategorised items must not vanish.

### Forward-compat rule

An entry may only reference an item present in the served pool payload. If pools
ever truncate or paginate, the feed resolver filters to what is on the wire —
refs never dangle. This is pinned by a test (§8.2), not just documented.

## 4. Resolution

New service `App\Services\PublicSite\ItemFeedService`, same division of labour as
`SiteActionsService`: candidate enumeration + wire resolution only — **scores are
never computed here**.

**Candidate enumeration** (shared by all modes):

1. Take the eight pools as `PoolResolver` serves them for this site (absent pool
   → contributes nothing).
2. `menus` + `services`: group items by `collectionIds` into category candidates;
   uncategorised items become plain item candidates.
3. Every other pool: one item candidate per pool item.

**Mode: newest** — order by `publishedAt` when the platform supplied one, else
`firstSeenAt`. A category block's timestamp is its newest item's; items inside a
block order newest-first. Tiebreak: item id (deterministic).

**Mode: score** — order by blended score from
`analytics.content_popularity_scores` (the same rows that power
`popularityRank`). A category block scores as its **best item's score**. Unscored
entries sort after scored ones, by recency. Items inside a block order by score,
unscored-last-by-recency.

**Mode: manual** — strict curation, mirroring `manual_actions`:

- The stored list resolves in order; refs no longer in the served pools are
  dropped; nothing is auto-appended (a new video does not appear until the owner
  adds it).
- Inside a manually placed category block, the stored `items` order applies and
  pool items missing from it are **dropped, not appended** — the same strictness
  one level down.

**Where it runs:** `IndividualProfilePayloadBuilder` calls it once per payload
build, next to `resolveRankedActions()` — behind the 60s in-process payload cache
(keyed on `site.sites.updated_at`) and the edge cache, inheriting all three cache
invalidation lanes.

## 5. Settings & write path

Two new keys inside `site.sites.settings` (JSON — no migration):

```jsonc
"feed_mode": "score",        // "manual" | "newest" | "score"; absent = default
"manual_feed": [             // consulted only when feed_mode = "manual"
  { "kind": "item",     "pool": "watch", "ref": "<item id>" },
  { "kind": "category", "pool": "menus", "ref": "<collection id>",
    "items": ["<item id>", "<item id>"] }
]
```

**Default mode: `newest`.** Score mode with zero traffic degenerates to recency
anyway (everything unscored), so newest is the honest default with identical
behaviour until real interactions exist.

**Validation** (`PATCH /api/site`, same home as `smart_actions`/`manual_actions`):

- `feed_mode` ∈ {`manual`, `newest`, `score`}; anything else 422.
- `manual_feed` entries are strict discriminated unions: `item` requires
  `pool` + `ref` and **rejects** `items`; `category` requires `pool` + `ref` +
  `items` and its `pool` must be `menus` or `services`; `pool` must be one of the
  eight covered pools. Duplicate `(kind, pool, ref)` pairs 422. The list
  **replaces atomically** — send the whole list every write.
- Cap: `config('partna.feed.manual_max')`, default 100 (feeds run longer than the
  12-entry button list).
- **Refs are not existence-checked at write time.** Pool membership changes out
  from under the settings blob (re-scans exclude items, platforms disconnect), so
  a write-time check would be a false guarantee that rots immediately. The
  resolver's drop-unknown rule is the sole enforcement point — the only one that
  cannot go stale. Same stance `manual_actions` takes.

## 6. Dashboard read

Extend `GET /api/site/actions` (no new endpoint — the design page stays one fetch):

```jsonc
{ "pool": [...], "rankedActions": [...], "ordering": {...},
  "feed": { "mode": "newest", "entries": [...], "manual": [...] } }
```

- `entries` = the resolved list **exactly as the public wire would serve it** —
  dashboard preview and lander cannot drift.
- `manual` = the stored raw `manual_feed` list for the editor (empty list when
  unset).

## 7. Error handling & degradation

1. **No pools resolve** (partial test env, DB blip): `feed: {mode, entries: []}`.
   Never a 500; the key is always present.
2. **Score lookup fails** in score mode: degrade to newest ordering for that
   build — fail-open, matching the analytics posture platform-wide. A ranking is
   cosmetic; an error page is not.
3. **Stale manual refs** drop silently at resolve time. A fully stale manual list
   yields an empty feed — strict curation working as specified; the dashboard
   preview shows the owner exactly that.
4. **No new href surface.** Entries are refs; every URL a rendered entry uses
   comes off the pool item, which the pool wire already gates. No new
   SSRF/scheme concerns, no `safeHref` in the feed.
5. **Unclaimed (pre-account) sites** serve the feed normally — public-by-design,
   like every other payload engine.
6. **Shop products score via the catalog handle (fixed 2026-08-19, follow-up).**
   `content_popularity_scores` rows for `shop_product` key on `f_catalog.handle`,
   but no pool item wire key originally carried that value — `slug` is a
   `content.item_slugs` URL slug, allocated only for `event`/`menu_item` kinds,
   so a `product` item's `slug` is always null, and `ItemFeedService::scoreFor()`'s
   original id-then-slug lookup never resolved for a shop item. Fixed by
   emitting the value pool-side: `PoolResolver` now carries the `f_catalog`
   handle (already fetched for `popularityRank`) as a new item key, `handle`
   — additive on `PoolResolver::ITEM_KEYS`, null for every item without a
   catalog handle, same contract `vendor`/`popularityRank` already follow.
   `scoreFor()` tries id, then `handle`, then `slug`. `ContentPopularityReader::
   itemScoresForSite()`'s max-on-collision reconciliation, previously moot, now
   does real work: a handle string can collide with another family's row keyed
   on that same string as an item id. Wire change: `docs/wire-changes/
   2026-08-19-item-feed.md`.

## 8. Testing

Pest, `tests/Feature/`:

1. **Resolution** — per mode: newest incl. `publishedAt` → `firstSeenAt`
   fallback; score with unscored-after-scored; manual strictness (unknown refs
   dropped, no auto-append, within-category order applied, missing items
   dropped); category grouping for menus/services incl. uncategorised items
   floating; `reviews` never emitted; deterministic tiebreaks.
2. **Wire shape pin** — every entry carries the full key set for its kind; the
   `feed` key is always present; every ref resolves against the served pools
   (the forward-compat rule as an executable assertion).
3. **Validation** — `feed_mode` enum 422s; `manual_feed` discriminated
   strictness (item rejects `items`, category pool restricted, unknown pool 422,
   duplicates 422, cap enforced, atomic replace).
4. **Dashboard** — `GET /api/site/actions` gains `feed`; preview `entries`
   identical to the public resolution for the same state.
5. **Cache lanes** — a `feed_mode`/`manual_feed` write through `PATCH /api/site`
   busts the payload (rides `UpdateSiteAction` → `site.sites.updated_at`; pinned
   so a refactor cannot drop it).

No analytics tests are added: the feed consumes existing scores and never writes.

## 9. Context: known gaps in the button vocabulary (documented, not fixed here)

Surveyed 2026-08-19 while designing this spec. The button-level `rankedActions`
vocabulary (`ActionVocabulary`: 23 static ids + `ordering:`/`custom:` families)
is a deliberately closed list, and it does not cover everything the platform
knows about. Recorded here as context and backlog; **changing the vocabulary is
out of scope for this spec** (and is LOCKSTEP with the frontend's `ACTION_IDS`
export — both sides must change together).

**Platforms with no dedicated button:**

- Content/creator: `vimeo`, `substack`, `patreon`, `gumroad`, `medium`, `kick`,
  `strava`
- Music: `youtube-music`, `tidal`, `mixcloud` (they grant the Listen page; only
  spotify/soundcloud/apple-music/apple-podcasts got buttons)
- Education: `stan`, `skool`, `kajabi`, `circle`
- Booking: `booksy`, `timely`, `calendly` (only Fresha/Square/generic `booking`
  feed `booking-services`)
- Ticketing: `eventbrite`, `humanitix`, `luma`, `partiful`, `ticketmaster` —
  covered *indirectly*: their events reach the pool, the pool grants the Events
  page, the `events` button appears. The platform itself never gets a button.

**The connection-only gap:** a non-vocabulary platform surfaces as a `custom:`
button when it exists as a *link block*, but a *connection-only* platform (e.g.
an active `vimeo` connection with no link block) produces **no button at all** —
`SiteActionsService`'s custom path reads link blocks and `custom`-platform
connections only.

**Pages with no page-kind button:** `watch`, `listen`, `gallery`, `reviews`,
`documents`, `links`. Page buttons exist only for shop, events, services, menu,
contact, reservations (+ `shop-tracks`). A visitor cannot be sent *to the Watch
page* by a button, only out to the platform itself.

**Why this spec still helps:** the item feed keys on **pools, not the button
vocabulary** — a Vimeo video or Substack post that reaches the `watch`/`media`
pools appears in the feed as an item even though its platform has no button. The
feed partially routes around these gaps without widening the closed list.

## 10. Out of scope

- Any change to `rankedActions`, `SiteActionsService`, or `RankedActionsComputer`.
- New analytics beacons, tables, or scoring jobs.
- A server-side cap on `entries` (lander truncates; revisit only if pool
  payloads themselves ever get capped — see the forward-compat rule).
- Shop collection grouping (products float; owner decision, reversible later by
  widening the category-pool set).
