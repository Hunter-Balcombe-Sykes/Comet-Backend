# Wire change — custom links pool (2026-08-15)

Convergence Phase 3 / W5. The custom links a user attaches to their page become
`link`-kind content items and get a pool of their own, `custom_links`. Backend-only
execution; the frontends are told, not designed around (programme spec
`docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md`, scope
`docs/2026-08-14-platform-and-pool-convergence-scope.md` §W5).

> **STATUS: pending live verification on dev.** Replaced with measured figures at
> the checkpoint.

## `GET /api/public/profiles/{handle}` and `GET /api/content/pools/{pool}`

**Consuming repos:**
- `/api/public/profiles/{handle}` — partna-monorepo (`@partnaau/design-system`), public render
- `/api/public/profiles/{handle}/integrations` — same, the legacy platform payload
- `/api/content/pools/{pool}` — Partna-App, authenticated dashboard

### Added: `pools.custom_links`

An eighth pool, alongside `watch`, `listen`, `media`, `events`, `services`, `shop`
and `reviews`. Same envelope as every other pool — `{items, latestItemId}`. No
`stats`, no `collections`.

- **`latestItemId` is always `null`.** `custom_links` is deliberately absent from
  `LATEST_TAG_POOLS`: a link list is an arrangement, not a feed, and nothing about
  a link is "new" — a Latest badge would label whichever link was typed last.
- **The pool is absent when the selection is empty**, exactly like every other
  pool. An absent `pools.custom_links` is not an error state.
- **The key is `custom_links`, snake_case** — the only one in `pools`, and
  deliberate. A pool's registry key IS its wire key and its URL segment
  (`GET /api/content/pools/custom_links`); the payload ships the map verbatim
  with no case transform. Reading it as `customLinks` will miss it.
- **Ordering is the owner's.** Every migrated link is PINNED, in the connection's
  `sort_order` and then `created_at`; the section's `recency` `order_by` governs
  only an unpinned tail. Render `items` in the order given.
- `origin` is `"manual"` on migrated links (they are pins), and `"auto"` on
  anything the section rule picks up unpinned.

### Item shape — which keys a `link` actually carries

No new keys. `PoolResolver::ITEM_KEYS` is unchanged, and `PoolWireShapeTest`
still pins it. What a `link` item populates:

| Key | Value |
|---|---|
| `kind` | `"link"` |
| `headline` | the page title snapshotted when the link was added; **the URL host** when the payload carried no name |
| `url` | the link itself, off `f_link` |
| `description` | the scraped page description, or `null` |
| `platform` | **`null`** — see below |
| `links` | one entry, `{platform: null, url, source: "synced"}` |
| `thumbnail`, `frames` | `null` / `[]` — see "not carried" below |

Everything else (`publishedAt`, `durationSeconds`, `startsAt`, `venue`, `price`,
`variants`, `collectionIds`, `review`, `popularityRank`) is `null`/`[]` on a link,
the same contract every pool item already keeps: the wire shape does not vary
with kind.

- **`platform` is null, and that is not a gap.** It is read from the connection
  behind the item's source, and these items sit on the user's MANUAL source,
  which has no connection. A custom link is by definition a URL that belongs to
  no platform — that is why it was a custom link. Do not render a platform chip
  from a fallback; render the host if you need one.
- **`slug` / `aliases` behave as on every other pool** — `null` slug and the raw
  id as the sole alias for an item that has not been allocated one.
- **`url` is param-minimised, and the legacy lane's is not.** Every `f_link.url`
  in the content lane passes through `SecretParams::minimiseUrl()` (#PRIV-5), so
  a tracking or secret-bearing query param is stored as `key=[redacted]`. Of the
  23 dev links exactly one is affected — a YouTube share link's `?si=`, which
  the destination ignores. The same link on `/integrations` still carries the
  raw query. If the two lanes disagree about a URL, this is why, and the pool's
  copy is the intended one.

### Not carried, deliberately: `favicon` and `logo`

The legacy `/integrations` `custom` entry publishes `favicon` and `logo` (the
scraped `og:image`). The pool does **not**, and `thumbnail` is `null` on a link
item. Minting `content.media_assets` rows for third-party image URLs pulls in
slice 1a's borrowed-asset lane — the pruner, the ref-only degradation rules, the
storage question — for decoration. A frontend that wants brand marks on link
cards should say so; it is a decision with a storage cost, not an oversight.

Until then, the two lanes are not at parity on imagery: `/integrations` has the
favicon, `pools.custom_links` does not.

### Unchanged: the legacy `custom` platform entry on `/integrations`

**Nothing is removed by this change.** `GET /api/public/profiles/{handle}/integrations`
still publishes the `custom` platform with `kind`, `url`, `name`, `description`,
`favicon` and `logo` exactly as before, and `CustomLinksController` still owns
every write. The two lanes run side by side, the same way events (slice 2) ran
alongside `hiddenEventIds`.

This is on purpose. Retiring the pseudo-platform surfaces is **Phase 6**'s scope
(`partna.custom_link` stops being connectable there), and the legacy read dies
with the rest of the compatibility surface in slice 7.

**Consequence for consumers, stated plainly:** for now the same link is served
twice, once per lane. Do not render both. New work should read
`pools.custom_links`; `/integrations`' `custom` entry is the retiring copy.

### Consequence: the pool is a SNAPSHOT until Phase 6

The backfill lands the links that exist when it runs. A link added afterwards
through `POST /api/platforms/custom/links` writes a connection row and does
**not** mint a content item — that write path moves in Phase 6, when custom links
stop being connections at all.

Two things follow, and both are deliberate:

- `content:backfill-custom-links` is idempotent on the URL-derived coord, so
  re-running it is how newly-added links land in the meantime.
- `POST /api/content/pools/custom_links/items` — the generic hand-add — already
  works and mints exactly the same coord, so a link added *through the pool*
  appears immediately and folds onto the migrated item if the URL matches.

### Behaviour: the pool pins, reorders and accepts hand-authored links

The mirror image of reviews. `custom_links` is the owner's own content, so every
curation verb stays open:

| Verb | Endpoint | Result |
|---|---|---|
| Pin | `POST /api/content/pools/custom_links/selection/{item}` | **200** |
| Reorder | `PUT /api/content/pools/custom_links/order` | **200** |
| Hand-author | `POST /api/content/pools/custom_links/items` | **201** |
| Exclude | `PUT /api/site/sections/{id}/items/{item}` with `state: excluded` | **200** |

One refusal: **per-item platform links are rejected.** `ItemLinkRules` has no
roster entry for the pool, so `PUT /api/content/items/{item}/links/{platform}`
answers **422** "That platform cannot carry a link for this item." for every
platform (owner ruling 2026-08-14 — a `link` item IS a URL; an alternate-platform
link on it would be a second URL competing with the only field the item has).
The refusal is not new behaviour: before this slice `poolForKind('link')` was
null and the same endpoint 422'd on that. Same answer, better-stated reason.

### The `links` page

`custom_links` provisions its section on the `links` page (`site.pages.key =
'links'`), which is the page these connections already advertise —
`SitepageId::PAGES` maps the legacy `custom` platform to it. The pool joins that
page rather than opening a second one beside it.

Page PRESENCE is unchanged by this slice: `SitepageDataResolverService` still
derives the Links page from live `site.blocks` rows in the `links` group, and the
pool-presence loop it runs (`watch`, `listen`, `events`) is untouched. A site
whose only links are pool items does not yet advertise a Links page.
