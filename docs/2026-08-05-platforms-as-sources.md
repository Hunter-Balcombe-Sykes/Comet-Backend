# Platforms are sources; pools are the curation (2026-08-05)

Owner decisions, this date. Spans three lanes in dependency order:
**Comet-Backend → partna-monorepo (pages) → Partna-App (dashboard)**.
Delete this file when shipped.

## The model (owner)

- A platform connection is a **source** (plus platform-specific surfaces:
  embeds, links, booking, reviews, hours). It never curates content.
- The **pools** on the dashboard's Site page are the only curation surface.
  Pool = the library of everything synced from that pool's sources, plus
  hand-adds. Selection = what's on the public site.
- **`auto_sync_latest`** is a per-connection toggle for every source with a
  time-ordered item stream. ON ⇒ the **single latest** item from that source
  is auto-selected in its pool, **rolling**: when a newer item is detected it
  replaces the previous auto pick. Hand-picked items are never touched.
- **"Latest" tag**: within Watch, Listen (and Media, which absorbs posts —
  see below), exactly one item across the whole pool wears a Latest tag: the
  most recently released item currently in the pool. Not for other pools.
- **The Posts pool is removed.** Post sources (Instagram today) feed the
  **Media** pool instead: we extract as many photos/videos from the user's
  posts (historical included) as the platform allows and offer them all as
  selectable Media options. The user never picks "which posts to fetch" —
  we fetch everything we can. `auto_sync_latest` ON ⇒ the latest post's
  media is the rolling auto-selection, same as YouTube's latest video.
- **Featured/highlights is removed end to end** — but only AFTER the pools
  feed the public site (sequencing decision: public sections must never
  collapse to one item per account, which is what an early removal causes).
- **One toggle grammar**: the site-level `sites.shop_auto_latest` and
  `sites.content_instagram_auto_enabled` migrate into the same
  per-connection `display_settings.auto_sync_latest` pattern.

## Current state (recon 2026-08-05, all verified with citations)

Write half is LIVE, read half is dormant:

- `content.*` schema (migration `20260727140000_content_schema.sql`) is
  applied and actively populated: `content.sources` (kind='connection',
  auto-provisioned per connection via `IntegrationConnectionObserver` →
  `SourceProvisioner`), `content.items` (14 kinds, `removed_at`,
  headline/facets/eligible caches), `content.source_items`, 15 facet tables
  (`f_published` carries release time), `content.manual_overrides` (API
  complete: GET/PUT `/content/items/{item}/overrides`,
  DELETE `/overrides/{facet}/{column}`).
- Ingest: 22 connectors (`app/Ingest/ConnectorRegistry.php`), scheduled
  `ingest:dispatch` every 15 min, projection inline per run.
- Curation layer already migrated but UNUSED: `site.pages`, `site.sections`
  (`mode hand_picked|automatic|mixed`, rule DSL, `order_by`, `limit_n`),
  `site.section_items` (pin/exclude), and `DocumentBuilder` resolving
  sections → `site.site_documents` every 5 min — **nothing reads the
  documents** (only the version pruner touches the table).
- Missing HTTP: no `GET /content/items` (list), no `DELETE
  /content/items/{id}` (nothing writes `removed_at` from the API), no pool
  reorder/selection endpoints (though the sections item API exists:
  `GET/PUT/DELETE /site/sections/{section}/items`).
- Public wire today: `SitepageDataResolverService` computes page PRESENCE
  from connections; the pages app renders Watch/Listen/Bandcamp item lists
  straight off connection payloads (`latest` + `highlights`) via
  `packages/design-system/src/engines/platform-sections.ts`. Removing
  `highlights` today shrinks YouTube to 1 tile, Apple/YT-Music accounts to
  1 headliner, Bandcamp to 1 release. YT Music's `items` array is on the
  wire but unread (engine asymmetry) — dies with the switch.
- `analytics.content_popularity_scores` CHECK already allows
  `listen_item` / `watch_item` / `engine_item`; nothing computes them yet.

Source roster (platforms with selectable item streams → pool):

| Pool | Sources (item lists) | auto_sync_latest today |
|---|---|---|
| Watch | youtube, vimeo, twitch (twitch never had a picker — parity now) | none |
| Listen | youtube-music, apple-music, apple-podcast | none |
| Media | instagram (posts → photos/videos; absorbs the Posts pool) | site column `content_instagram_auto_enabled` |
| Events | eventbrite, humanitix | display toggle (exists, default ON) |
| Sell | shop (relational brands/products), bandcamp (releases) | site column `shop_auto_latest`; bandcamp display toggle |
| Services | fresha (services[] + hiddenServiceIds — not "latest"-shaped) | n/a |

Embed-only (spotify, soundcloud, mixcloud, tidal) and the ~40 link/card
platforms have no item stream: they stay pure links/embeds, untouched.

## Phase 1 — Backend: the pool serve/curate lane

1. **Pool selection store**: one `site.sections` row per pool (watch,
   listen, media, events, sell …), `mode: mixed`. Hand-picks = pins;
   removals = excludes; the automatic half is the auto-latest rule. Reuse
   `section_items`; do NOT invent a parallel selection table.
2. **Endpoints** (dashboard contract):
   - `GET /content/items?kind=…` — the pool library (selected + selectable),
     with per-item: facets the cards need, source platform, `origin`
     (auto|manual), popularityRank, Latest flag.
   - `DELETE /content/items/{id}` → `removed_at` (the FE TODO's target).
   - Pool selection: add/remove/reorder riding the sections item API.
3. **Auto-latest engine**: after projection, for each source whose
   connection has `display_settings.auto_sync_latest` ON (default ON),
   maintain exactly one auto pin per source: newest item by `f_published`;
   a newer arrival replaces the previous auto pin (never a hand pin).
   Auto pins carry an origin marker so the FE's Auto chip and the rolling
   replacement are honest.
4. **Latest tag**: computed at read time (newest `f_published` across the
   pool), served on both the dashboard wire and the public payload, for
   watch/listen/media only. Not stored.
5. **Toggle generalisation**: descriptor `auto_sync_latest` for youtube,
   vimeo, twitch, youtube-music, apple-music, apple-podcast, instagram —
   joining eventbrite/humanitix/bandcamp. Migrate `sites.shop_auto_latest`
   and `sites.content_instagram_auto_enabled` into connection
   `display_settings` (data migration + read-path moves in ShopController /
   instagram ingest gate); drop the site columns.
6. **Instagram → media extraction**: connector lands post photos AND videos
   as media-kind content items, as far back as the API allows.
7. **Public payload**: serve pool item arrays (watch/listen/media/events/
   sell) resolved from sections/content-items. Preferred: read
   `site.site_documents` (already built every 5 min, currently unread);
   fall back to direct resolution only if document freshness is unacceptable
   for the dashboard-to-public loop.
8. Popularity: start computing `watch_item`/`listen_item` once the public
   sections emit item beacons keyed to pool items.

## Phase 2 — Pages app: render pools

- `platform-sections.ts` engines stop reading connection payloads for item
  lists; sections render the payload's pool arrays. Latest tag renders as a
  badge on watch/listen/media. Embed platforms unchanged. This kills the
  YT-Music unread-`items` asymmetry by construction.

## Phase 3 — Dashboard: pools go live

- Live wrappers for Watch/Listen on the new endpoints; Media gains the
  posts-derived options; **Posts pool removed** (site-page entry, picker
  option, fixtures, post-grid page component; keep the shared tile grammar).
- Auto chip = origin auto; Latest chip from the wire; Select-from-pool =
  library items; Remove = exclude (selection) vs `removed_at` (library),
  wired to the real endpoints; reorder via the sections API.
- Platform sheets: the generic display-toggles card already renders
  descriptor toggles, so the new auto_sync_latest switches appear with no
  bespoke FE; copy per platform ("Your latest video features
  automatically").

## Phase 4 — Remove Featured + old plumbing (after 1–3 are live)

Backend: `HighlightsStrategy` contract + Youtube/YoutubeMusic/Vimeo/
Bandcamp strategies, `HighlightsPicker` service + private `recent` snapshot
key, Apple bespoke recent/highlights actions + routes, registry
`/recent`+`/highlights` route loop + `hasHighlights`, 
`PlatformHighlightsRequest`, `highlights` keys in `FeedPayload` and every
resource allowlist (`PublicIntegrationConnectionResource`: youtube,
apple-music, apple-podcast, bandcamp, vimeo, youtube-music;
`BandcampConnectionResource` etc.), the preserved-highlights carry in
`ManagesIntegrationConnection`, bandcamp `show_all_releases` toggle
(selection governs now), all highlight tests.

Dashboard: `HighlightsPicker` section in platform-panel, `HIGHLIGHT_FIELDS`
/ `fetchRecentItems` / `fetchHighlightIds` / `saveHighlights` in
lib/queries/platforms.ts, `rules.highlights` in lib/data/platforms.ts,
facts copy mentioning Featured.

Pages: delete `dedupedHighlights` + highlight reads (already dead after
Phase 2), stale type fields on the fetch-*-selection files.

## Open items (owner to confirm — flagged, not blocking Phase 1 design)

1. **Events + Shop auto semantics**: single-rolling-latest fits watch/
   listen/media but not events (all upcoming should show) or shop (newest
   products). Assumption: under the unified toggle name, events keep
   "sync upcoming from each organiser" and shop keeps "selection follows
   newest products" — the toggle gates the behaviour each content type
   honestly has. Confirm.
2. **Removing the current auto pick**: if the user removes the item that is
   the rolling latest, does it come back next sync? Proposal: removal
   writes an exclude for THAT item; the auto rule then selects nothing
   until a NEWER item arrives (no re-add of the excluded one). Confirm.
3. **Latest tag in Media**: assumed yes (inherited from the posts decision).
4. Whether `site.site_documents` freshness (5-min builds + on-change
   `BuildState` bumps) is acceptable end-to-end latency for "I changed my
   pool, my site follows". If not, resolve pools directly in the payload.
