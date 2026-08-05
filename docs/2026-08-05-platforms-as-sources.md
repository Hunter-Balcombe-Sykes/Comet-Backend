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

## Phase 1 design (settled inline 2026-08-05, giant run — Option B: LIVE)

- **Scope**: watch/listen/media pools move onto the content library now.
  Events/Sell/Services/Menu keep their existing live lanes (already
  sources→pool shaped: hiddenEventIds, shop selections, hiddenServiceIds);
  unifying them onto content.* is future work. Bandcamp releases = Listen.
- **Pool→kinds**: watch→[video] · listen→[track,release,episode] ·
  media→[media]. Kinds are the schema's closed 14.
- **Selection = one section per pool** (kind collection, key `pool:{key}`,
  mode mixed, order_by recency, rule
  `{all:[{op:kind_is,…},{op:latest_per_auto_source,…}]}`), provisioned
  on demand (find-or-create, idempotent). Pins = hand-picks (sort_key =
  drag order); excludes = removals; rule = the auto half. C4 holds: no
  engine ever writes pins — rolling latest is READ-TIME.
- **New rule operator `latest_per_auto_source`** (8th): item is the newest
  (f_published.published_from, fallback first_seen_at) non-removed item of
  its connection-source among the rule kinds, AND that connection's
  `display_settings.auto_sync_latest` ≠ false. Registered in
  EXECUTED_OPERATORS + validator + DocumentBuilderRuleOpsTest. Owner
  semantics fall out: excludes apply AFTER candidates, so excluding the
  current latest leaves nothing until a newer item lands.
- **PoolResolver service** (live, shared by dashboard + public payload):
  render-ready items — headline (manual_overrides-aware), link (f_link),
  platform (source→connection), creator (f_authored/f_channel),
  publishedAt, durationSeconds, thumbnail (item_media cover→media_assets),
  price (offers, sell-adjacent kinds), origin (pin=manual, rule=auto),
  popularityRank, platformLinks, latest flag (selection-wide, newest
  released; watch/listen/media only).
- **Endpoints** (PoolController + routes): GET /content/pools/{pool}
  (selection + library + latestItemId) · POST/DELETE
  /content/pools/{pool}/selection/{item} (pin / unpin-or-exclude: removing
  an auto item writes an exclude) · PUT /content/pools/{pool}/order
  (rewrites pin sort keys; dragging an auto item pins it) ·
  DELETE /content/items/{id} → removed_at. All bump BuildState.
- **item_links**: NEW table content.item_links (item_id, platform, url,
  UNIQUE(item,platform)) + PUT/DELETE /content/items/{id}/links/{platform}
  + ItemMerger repoint + payload join. Server enforces alternates-only +
  platform-domain URL validation.
- **Toggles**: descriptor auto_sync_latest for youtube, vimeo, twitch,
  youtube-music, apple-music, apple-podcast, instagram (eventbrite/
  humanitix/bandcamp already have it). Site columns shop_auto_latest +
  content_instagram_auto_enabled migrate into connection display_settings,
  readers swapped, columns dropped.
- **Public payload**: `pools.{watch,listen,media}` arrays resolved LIVE by
  PoolResolver inside IndividualProfilePayloadBuilder (owner chose Option
  B — site follows edits instantly; site_documents stays unused by us).

## CHECKPOINT (giant run, 2026-08-05 evening — branch feature/platforms-as-sources)

DONE (committed, all local suites green):
- `565d693d` SectionCandidates extraction (ONE rule executor; fixed the
  tracer's operator drift as found) + `latest_per_auto_source` operator +
  content.item_links migration (20260805090000).
- `35ec0634` Pool lane: PoolRegistry/Provisioner/Resolver (live),
  PoolController (GET/select/deselect/reorder), ItemController (removed_at),
  ItemLinkController (+ItemLinkRules), routes, authz expectations, SQLite
  mirror, 12-test PoolLaneTest (owner semantics pinned).
- `da9367ed` Toggle unification: 8 registry declarations, AutoSyncSetting,
  site columns dropped (migration 20260805100000), readers swapped
  (ShopFetch/ShopController/ContentSelectionService/PublicIntegration/
  ContentController/observer), siteColumn bridge deleted, suites updated.
- `b167cc6f` profile.pools on the public payload, live via PoolResolver.

PHASE 1 IS LIVE ON DEV (2026-08-05 ~21:20 AEST):
- Merged to development (`a4e83d29`), deployed; migrations 20260805090000
  + 20260805100000 applied to the live dev DB via Supabase MCP with
  matching schema_migrations rows (CLI history consistent). Instagram→
  media extraction needed NO new build — InstagramConnector's 'media'
  stream already projects posts as media-kind items.
- LIVE SMOKE (ollies, real ingested content): listen library 110 items;
  selection = one rolling auto per source with the Latest tag on the true
  newest; pin→verify→unpin write cycle green on real Postgres; public
  /public/profiles payload carries profile.pools (data envelope!) and
  reflected a fix INSTANTLY (Option B proven).
- Smoke FOUND + FIXED (`2fc8776d`): bulk first-ingest ties (one
  first_seen_at across a catalogue, no published facet) made EVERY item
  "newest" — 15 undated releases all auto. Ties now break on id; pinned
  by a 3-way-tie test. Post-fix live: listen = 4 autos (one per source),
  watch = 2.
- CI green-up (`7f2ca6ca`): lock/statement timeouts in the unify
  migration (Master Pattern 20) + authz fixtures use the 'unknown'
  literal for slot params. Earlier CI failures were exactly these two.
  The full local --parallel run: 7057 passed; 19 non-mine failures are
  the known parallel-flake families (all pass serially), 2 were mine and
  fixed (dropped-column refs in RefreshFetchBudget/ReconcileTakedown).

NEXT: P2 (pages renders profile.pools — note the `data` envelope on the
public route; Latest badge watch/listen/media; per-item platform buttons;
kill the YT-Music unread-items asymmetry by construction), P3 (dashboard
pools live on /content/pools/*, Posts pool folds into Media, add-a-link
sheet UI, Auto/Latest chips), P4 (Featured teardown per the Phase 4
inventory — only after P2/P3 verified live).

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

## Phase 2 design (settled inline, 2026-08-05 late)

Chain: resolve-site-content.ts builds SectionItem[] per page →
staple.astro mediaItems() maps to ItemGridItem tiles (title/image/href/
external/trailingIcon/attrs) → ItemsGrid → ui/Card.

1. resolve-site-content.ts: profile.pools?.[pool] (the payload's profile
   object; public route wraps everything in `data`). New
   poolSectionItems(profile, pool, pop, platformFilter?) adapting pool
   items to the engine media shape: {kind:'media', platform, name:
   headline, link: url, thumbnail, date: publishedAt, durationSeconds,
   latest: id===latestItemId, links}. listen = pools.listen minus
   bandcamp; shop-tracks = the bandcamp subset (preserves the 2026-07-17
   commerce split); watch = pools.watch. LEGACY FALLBACK when the pools
   key is absent/empty — an account with no pool content keeps the old
   connection-derived rendering until its pools fill (removal of the
   legacy path is Phase 4). Twitch embed rail untouched. scoringId stays
   URL-keyed so accrued listen_item/watch_item ranks carry.
2. staple mediaItems(): Latest chip via item.data.latest; platform
   buttons via item.data.links. CONSTRAINT: Card renders as one anchor —
   nested <a> is invalid, so buttons must live OUTSIDE the card's anchor
   (Card grows an actions row outside its link wrapper, or the buttons
   ride the row/subtitle strip as the card's sibling). Tokens-only CSS.
3. Media pool's public rendering stays the gallery lane for now — the
   Latest tag renders on watch/listen/shop-tracks in P2; media joins when
   its pool surface exists publicly.
4. Gates: monorepo tokens-only audit + astro build; deploy npm run
   deploy:pages; live verify on ollies (pools render, badge, buttons,
   Latest matches the dashboard's tag).

## P2 CHECKPOINT (2026-08-05 ~21:40 AEST) — LIVE-VERIFIED

- partna-monorepo `128d4de` (amended): resolve-site-content pool adapter
  (listen minus bandcamp / shop-tracks bandcamp subset / watch; legacy
  fallback until P4), ui/Card `flag` chip, ItemsGrid per-item `actions`
  strip OUTSIDE the card anchor, staple maps latest→flag + alternate
  links→actions. astro build + tokens audit clean; worker deployed
  (96a933f0, service-binding consumer — "no targets" is normal).
- Backend `e8684f6c` (deployed): pool mutations now dispatch
  CloudflareCachePurgeJob — FOUND VERIFYING P2: Option B was live on the
  payload but the CDN held the rendered page, so a pool edit never
  reached visitors until TTL. The full loop is now proven ON THE LIVE
  SITE: pool write → purge → ollies.partna.au/watch re-rendered with the
  pool's two videos and the Latest chip on the newer one.
- NOTED (pre-existing, not a regression): 'listen' is absent from Maha's
  pageOrder — the OLD presence machinery (connections/blocks) gates it,
  while the listen POOL serves 19→4 items on the wire. Presence-via-pools
  ("pool selection non-empty ⇒ page present") belongs in P4 when the
  legacy path dies. cell-actions render but no item carries alternates
  yet (none saved) — the dashboard add-a-link UI lands in P3.
- CI: two runs pending verdict at checkpoint time (7f2ca6ca watched,
  e8684f6c queued); local full suite was green modulo known flake
  families.

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

## Per-item platform links (owner, 2026-08-05)

Embed-only platforms (Spotify, SoundCloud, Mixcloud, Tidal) can never be
item sources — so the item itself carries the cross-platform links:

- Every Watch and Listen item's sheet gets an **add-a-link** control: pick
  a platform from **that pool's full roster** (not a fixed pair — future
  platforms need no schema change), paste the item's URL on that platform.
- **Alternates only**: a platform that IS the item's synced source never
  appears in the picker — the synced link is not manually editable, so it
  can't drift from the sync. Hand-added items offer the whole roster.
- **Public rendering: platform buttons on the item's card** — "Spotify ·
  SoundCloud" style links beside the item's source link, each going to
  that item on that platform.
- Storage: per-item platform→url map in the content library (candidates:
  `content.item_refs` or a manual-links row keyed (item, platform) —
  decide in Phase 1 design; must survive re-sync and identity merges).
- Phases: storage + wire in Phase 1, public buttons in Phase 2, the sheet
  UI in Phase 3. Validation: URL must belong to the platform's domain(s),
  same normalisers the connect flows already use.

## Resolved (owner confirmed 2026-08-05)

1. **Events + Shop auto semantics** — confirmed: under the unified toggle
   name, events keep "sync upcoming from each organiser" and shop keeps
   "selection follows newest products"; the toggle gates whichever
   behaviour the content type honestly has.
2. **Removing the current auto pick** — confirmed: removal writes an
   exclude for that item; auto selects nothing until a NEWER item arrives.
3. **Latest tag in Media** — confirmed yes.

## Open items

1. Whether `site.site_documents` freshness (5-min builds + on-change
   `BuildState` bumps) is acceptable end-to-end latency for "I changed my
   pool, my site follows". If not, resolve pools directly in the payload.
