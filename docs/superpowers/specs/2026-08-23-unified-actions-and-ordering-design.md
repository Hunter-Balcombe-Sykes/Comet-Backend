# Unified actions + content ordering — design (approved 2026-08-23)

One ranked list of **actions** — every destination platform the owner has
connected, every item currently on their sitepage, and the pages those things
grant — ordered one of three ways, rendered as the top 10 on the actionable
lander and managed from a dedicated dashboard page. The same three ordering
modes apply per content pool and change the order items are emitted to the
Astro sitepage.

Supersedes the 2026-07-23 button-level `rankedActions` system and the
unmerged item-feed spec (`2026-08-19-item-feed-design.md`, PR #296). Both are
removed outright — no compatibility shims. Every existing account is a test
account; stored preferences under the old keys are dropped by migration.

## 1. Decisions (owner, 2026-08-23)

| # | Decision |
|---|---|
| D1 | **One list.** Platforms and items rank together. The lander renders the top N of one list, not a buttons row plus an items row. |
| D2 | **Fold rule.** A connection that *powers* a page (Fresha, Square, Booksy, Calendly, Shopify, Eventbrite, online-ordering…) is represented only by that page's action. Only *destination* platforms (a public profile/channel/artist page) get their own action. A platform that is both source and destination (YouTube, Spotify, SoundCloud, Apple Music, Twitch) gets a platform action. |
| D3 | **Three modes** for actions and for pools: `newest`, `smart`, `manual`. Default `newest`. |
| D4 | **Locks in smart/newest.** The owner can lock any action to a slot position; ranking fills the unlocked slots in order. |
| D5 | **Manual = owner places every slot.** Nothing auto-fills. Fewer than 10 placed → the lander shows fewer. Seeded from the current resolved list when the owner switches to manual. |
| D6 | **Pools are mode-only.** No locks in pools — "pin" there already means hand-added membership. Events ignore the mode (always soonest-first). |
| D7 | **N = 10**, table and lander, one config constant. Owner cannot change it. |
| D8 | **Smart score** is one composite per action (demand rate + reach + freshness + kind prior), computed in the existing 15-minute popularity job. The separate page-score family is removed; page order reads the page actions' scores. |
| D9 | **No `custom` action kind.** An owner-authored URL is a Links-pool item; they add it there and lock it. |
| D10 | **Legacy removed completely**: `ActionVocabulary`, `SiteActionsService`, `rankedActions`/`ordering` wire keys, `smart_actions`/`manual_actions`/`manual_order_pools` settings, the legacy-ref normalizers, PR #296 (its pure resolver and score reader are lifted in). |
| D11 | **Dashboard**: a dedicated `/actions` page (mode `ChoiceRows` + 10-row table with lock/swap/drag/remove); the design page keeps a summary card linking through; each pool page gets a `PoolOrderFieldset` (the `shop-link-mode-fieldset` pattern) at its foot. |

## 2. The action model

### 2.1 Kinds

| kind | id | exists when | url | `connectedAt` (newest key) |
|---|---|---|---|---|
| `page` | `page:<sitepageId>` — `services`, `reservations`, `menu`, `shop`, `events`, `contact` only (pages that are a destination of intent; listen/watch/gallery/links/documents/strava/skool get no page action — their content ranks as items, their platforms as platform actions) | the page is present (presence-via-pools / presence probes, unchanged) | the page path | `created_at` of the newest live connection that grants the page; native-only (manual services, contact card) → the granting row's `created_at` |
| `platform` | `platform:<platform_key>` | a live connection whose platform registry entry has `destination: true` and yields a profile URL | the profile URL | connection `created_at` |
| `item` | `item:<content.items.id>` | the item is currently served on the sitepage (pinned or auto-selected; `PoolResolver` output) | outbound URL for tracks / products / videos / links (existing `ShopOutboundUrl` etc.); page anchor for services and dishes | `publishedAt ?? firstSeenAt` |
| `category` | `category:<collection id>` | a menu or services category with ≥1 served member — one entry, members ordered inside by the pool's mode | page anchor | newest member's key |

Ids are the analytics keys; they must survive a disconnect/reconnect. `platform:<platform_key>` (not the connection uuid) does — destination platforms are single-account (FI-1). `reviews` never produces an action.

### 2.2 `destination` flag

A boolean on each platform registry entry (next to class and capability data).
`true` for every platform whose connection carries a public profile/channel URL
(socials, music/video/podcast profiles, Substack, Patreon, Twitch, Vimeo, Strava…).
`false` for pure sources (booking, store, ticketing, menu, review, Google
Business); `true` for online-ordering platforms (each ordering link is a destination).

**Source fallback:** a source platform whose granted page is *absent* (a booking
connection whose services page is off or incomplete, a store with no shop page)
still has a public URL that is the right place to send people. It yields a
`platform:<key>` action with that URL *only while the page is absent*; the moment
the page is present the fold rule applies and the platform entry disappears. A new platform with a profile URL becomes an action by registry entry
alone. The platform's label is the registry label; the url is the connection's
profile URL resolved by the existing platform-display code.

### 2.3 Candidate set

`ActionCandidates::forSite(Site): list<Candidate>` — built from the same
`publicPools()` hydration the public payload uses, plus live connections and page
presence. `Candidate = {id, kind, label, url, thumb|null, connectedAt|null,
ref: {pool, itemId}|null, meta: {platformKey|pageId|pool}}`. Fail-open on the
content lane (a `QueryException` on `content.*` yields no item candidates, never a
500).

## 3. Public wire

`GET /api/public/profiles/{handle}` → `data.profile.actions` (always present):

```json
{
  "mode": "newest" | "smart" | "manual",
  "entries": [
    { "position": 0, "id": "page:services", "kind": "page", "label": "Book",
      "url": "/services", "thumb": null, "locked": true, "ref": null },
    { "position": 1, "id": "item:…", "kind": "item", "label": "Midnight (single)",
      "url": "https://open.spotify.com/track/…", "thumb": "https://…",
      "locked": false, "ref": { "pool": "listen", "itemId": "…" } }
  ]
}
```

- `entries` ≤ `config('partna.actions.slots')` (10), positions contiguous from 0.
- Every `ref` resolves against the served `pools` map (structural — candidates
  are derived from the same hydration).
- Self-describing: no id vocabulary is shared with the frontend. `url` passes the
  existing `UrlSafety::safeHref` gate; an entry whose url fails it is dropped
  before slot resolution.
- `rankedActions` and `ordering` are removed from the wire (see §9 for the
  one-deploy overlap). `pageOrder` keeps its shape.

`IndividualProfileResource` is a manual key whitelist — `actions` is registered
there (the feed PR shipped as a no-op until this was done; pinned by test).

## 4. Settings and write path

All in `site.sites.settings`, validated by the shared `SiteOrderingValidationRules`
trait (user and staff endpoints), written through `UpdateSiteAction` with the
list keys in `LIST_SETTINGS_KEYS` (atomic replace).

```
settings.actions = {
  mode:  'newest' | 'smart' | 'manual',            // absent → newest
  slots: [ { position: 0..9, id: '<action id>' } ] // ≤ 10, positions distinct, ids distinct
}
settings.pool_order = { '<pool>': 'newest' | 'smart' | 'manual' }   // sparse; absent → newest
```

- `slots` semantics by mode: in `smart`/`newest` they are **locks** (sparse
  positions); in `manual` they are **the list** (positions 0..n−1, contiguous —
  422 otherwise).
- `slots[].id` must match `^(page|platform|item|category):[A-Za-z0-9_.:/-]{1,160}$`.
  Existence is *not* validated at write time — an id can stop existing later and
  the read path handles it (§5.3).
- `pool_order` keys: `links, services, menu, watch, listen, media, sell, posts`.
  `events` and `reviews` are rejected (422).
- Removed keys: `smart_actions`, `manual_actions`, `manual_order_pools`. A
  migration strips them from every existing `settings` row. Unknown keys in
  `settings` were never accepted by the request rules; nothing else changes.
- Kept: `smart_page_order`, `manual_page_order` (page order is its own surface).
- A write to either key busts the payload cache (rides `updated_at`; pinned by test).

## 5. Resolution

### 5.1 Ranking inputs

- **newest**: `connectedAt` desc, nulls last, tiebreak id asc.
- **smart**: stored action score desc (§6), unscored candidates after scored ones
  ordered by `connectedAt` desc, tiebreak id asc.

### 5.2 `ActionSlots::resolve(candidates, scores, settings): list<Entry>` — pure

```
N = slots constant
ranked = order(candidates, mode)                      // §5.1
if mode is manual:
    out = [ candidate(id) for slot in settings.slots sorted by position if id ∈ candidates ]
    renumber positions 0..; return out                // nothing auto-fills
locked = { position → candidate(id) } for slots whose id ∈ candidates
fill   = ranked minus locked ids
for p in 0..N-1: out[p] = locked[p] ?? fill.shift() ?? stop
return out
```

No DB access; fixtures are plain arrays. `entries[].locked` is true for a
placed lock; in manual every entry is `locked: true`.

### 5.3 Unavailable locks

A slot whose id is not in the candidate set (item removed from the site,
platform disconnected, page lost presence) is **skipped** at resolution —
smart/newest fill that position from the ranking; manual shortens. The slot is
**not** deleted from settings: the dashboard read (§7) reports it as
`unavailable: true` so the owner sees why and can remove or replace it. If the
candidate returns (reconnect), the lock applies again.

### 5.4 Pools

`PoolResolver` reads `settings.pool_order[pool]` (absent → `newest`) and orders
the **whole** pool — pinned and auto together:

| mode | order |
|---|---|
| `newest` | `publishedAt ?? firstSeenAt` desc, dated before undated, id desc |
| `smart` | item score desc (`content_popularity_scores`, item families); unscored after scored by the newest order |
| `manual` | today's behaviour: pins by `sort_key`, then auto by the pool's `order_by` |

Events: always occurrence (soonest first), the mode is ignored and not accepted
(§4). Reviews: out. Category blocks (menu, services) are ordered among
themselves by the mode using their newest/top member; members inside a block
follow the same mode.

The wire pools map already carries `publishedAt`/`firstSeenAt` on every item and
`popularityRank` on products; smart order extends the rank to every pool item.

## 6. Scoring (`ActionScorer`, inside `analytics:compute-popularity`)

Per site, per candidate (the job calls `ActionCandidates::forSite` — candidates
with no traffic still score, so cold start ranks by prior + freshness):

```
demandRate = (T + k·prior) / (E + k)        E,T = 90-day true-half-life, day-bucketed,
                                             session-distinct seen/tap from analytics.action_events
                                             keyed by action id; k = partna.actions.prior_k
reach      = decayedTaps / max(decayedTaps over the site)           0..1
             decayedTaps = action_events taps + item click/view signal already aggregated
             for the item (link_clicks.product_id, item_views) for item/category kinds
freshness  = ContentFreshness boost from connectedAt (14-day half-life)  0..1
prior      = partna.actions.priors[id] ?? priors[kind] ?? default_prior

score      = w_demand·demandRate + w_reach·reach + w_fresh·freshness + prior
blended    = 0.7·score + 0.3·previous                  (anti-thrash)
rank       = previous-rank seed, overtake only when > 10% above the incumbent
```

- Weights `partna.actions.weights = {demand, reach, fresh}` and priors (per kind,
  per page id) live in `config/partna.php`; defaults are set so a brand-new site
  ranks `page:services` > `page:reservations` > `page:shop` > platforms > items, and any
  candidate connected/published in the last ~14 days rises into the top few.
- Stored in `analytics.content_popularity_scores`, `content_type='action'`,
  `content_key=<action id>`; stale keys deleted per run; this class owns the
  `action` lifecycle (excluded from the generic fade-out, as today).
- The page-score family (`content_type` page ids) is **removed**; `smart_page_order`
  resolution reads `action` rows with a `page:` prefix. The item families stay —
  they drive pool smart order (§5.4).
- Beacons: the lander fires `seen` and `tap` on `analytics.action_events` for each
  rendered slot, keyed by the entry's `id`. `ItemSeenRequest` / the action-event
  request accept the four id prefixes; the `<kind>:<ref>` legacy shapes are gone.

## 7. Dashboard read

`GET /api/site/actions`:

```json
{
  "mode": "smart",
  "slots": [ { "position": 0, "id": "page:services", "unavailable": false } ],
  "entries": [ …exactly the public resolution for the current state… ],
  "candidates": [ { "id", "kind", "label", "url", "thumb", "connectedAt",
                    "score": 0.42 | null, "scoreShare": 0.18 | null, "ref" } ]
}
```

`entries` come from the same `ActionSlots::resolve` over the same candidates as
the public wire — preview and lander cannot drift (pinned by a test that runs
both paths on one site state). `candidates` is the full searchable set for the
swap popover, in smart order, with the score share (score ÷ site max) the table
shows in smart mode. This endpoint does a full pool hydration per call; it is
owner-only and uncached — acceptable, measured before the page ships.

## 8. Frontend

### 8.1 Dashboard (`partna-monorepo/apps/dashboard`)

- **`/actions` page** (sidebar entry "Actions"). Top: `SettingFieldset` with
  `ChoiceRows` — Newest / Smart / Manual — each row with an info tooltip:
  newest "Most recently connected or published first"; smart "Ranked by what
  visitors tap, how much traffic it drives, and how new it is"; manual "Exactly
  the ten you choose, in your order". Below: the slot table (built on
  `pool-table` / `ranked-table` primitives), 10 rows:
  position · kind glyph · label (middle-truncate) · source (pool or platform
  name) · right column = score share (smart) or date (newest) or nothing
  (manual) · lock toggle (smart/newest only) · drag handle (manual; locked rows
  in smart/newest drag to a new position) · swap (search popover over
  `candidates`, grouped by kind, replaces the row and locks it) · remove
  (manual only). Manual shows empty slots as an "Add" row. An `unavailable`
  lock renders muted with "No longer on your site — replace or remove".
  Switching to manual seeds `slots` from the current `entries`. Edits stage in a
  draft (`useDraft`) and save through `PATCH /api/site`; after save, refetch
  `GET /api/site/actions` so the table shows the live resolution.
- **Design page**: the "Pick action buttons from performance" card becomes a
  summary card — "Actions · Smart · 10 live · 2 locked" with a "Manage" link to
  `/actions`. `smartActions` leaves the design kit / `me.ts` / `user.ts`.
- **Pool pages** (links, services, menu, watch, listen, media, sell, posts):
  `PoolOrderFieldset` at the foot — `SettingFieldset` + `ChoiceRows` with the
  same three rows and tooltips, saving `settings.pool_order[pool]`. Events has
  none. `manualOrderPools` leaves `me.ts` / `user.ts` / the design page.

### 8.2 Sitepages (`partna-monorepo/apps/pages`)

- `src/content/actions.ts` consumes `profile.actions.entries`: server order is
  authoritative; it resolves nothing but the http(s) gate and drops nothing the
  server sent except a non-http url. `ACTION_IDS`, `ACTION_FAMILIES`,
  `LANDER_ACTION_COUNT` and the lockstep test are deleted.
- The lander renders every entry (server caps at 10). `item`/`category` entries
  render as the same action row with the `thumb` leading and the pool name as the
  secondary line; pages/platforms render as today. Tokens-only CSS, no new
  component fork (a variant = a prop on the existing action row).
- Beacons: existing seen/tap beacons send the entry `id`.
- Pool order needs no pages change — the wire arrives ordered.

## 9. Removal list and deploy order

**Comet-Backend, removed:** `App\Services\PublicSite\Actions\ActionVocabulary`,
`App\Services\PublicSite\SiteActionsService`, `rankedActions` + `ordering` on the
public wire and `IndividualProfileResource`, `UserSiteActionsController`'s old
shape, `SiteOrderingValidationRules::POOL_KEYS` / `LEGACY_BUTTON_REF_TO_ACTION_ID`
/ action-ref rules, `NormalizesSiteUpdateInput`'s legacy ordering normalization,
the page-score family in `ComputeContentPopularityScores`, `partna.actions.priors`
keyed by vocabulary id (replaced by kind/page priors), every test of the above.
`RankedActionsComputer` is replaced by `ActionScorer` (same storage row, new
formula). PR #296 is closed with a comment pointing here; `ItemFeedService`'s
category-block homing and its test fixtures are lifted into `ActionCandidates` /
`ActionSlots`.

**Monorepo, removed:** `ACTION_IDS`/`ACTION_FAMILIES`/`LANDER_ACTION_COUNT` and
the lockstep test in `apps/pages`; `smartActions` and `manualOrderPools` in the
dashboard (`design-page.tsx`, `lib/queries/me.ts`, `lib/data/user.ts`, dev
showcase pages that reference them).

**Deploy order** (commit order ≠ deploy order):

1. Backend ships `profile.actions` **beside** `rankedActions`/`ordering` (one
   deploy), with scoring, settings, pools, the dashboard endpoint, and the
   settings-strip migration (`supabase db push`).
2. Pages switches to `profile.actions`; deploy.
3. Backend removal commit (drops `rankedActions`/`ordering`, `SiteActionsService`,
   `ActionVocabulary`); deploy.
4. Dashboard: `/actions` page, pool fieldsets, design-page card; deploy on push.

Step 3 is a separate commit on the same branch so the overlap is one deploy
window, not a lingering shim.

## 10. Error handling and degradation

- Content lane down → no item/category candidates, pages and platforms still
  rank; never a 500 on the public payload.
- Scores missing (job not yet run) → smart falls back to newest order for
  unscored candidates — the site still renders.
- A url failing `safeHref` → candidate dropped before resolution.
- Manual list empty → `entries: []`; the lander renders no action row.
- Settings `actions.slots` referencing ids that no longer exist → §5.3.

## 11. Testing

- **Pure units** (array fixtures, no DB): `ActionSlots` — every mode, locks at
  every position, lock + manual seeding, unavailable skip, N cap, tiebreaks;
  `ActionCandidates` fold rule (a Fresha connection yields `page:services` and no
  platform entry; YouTube yields `page`-less `platform:youtube` plus its items);
  `ActionScorer` cold start, hysteresis, normalization.
- **Feature**: public wire shape pin (`actions` always present, `ref` resolves
  against served pools, ≤ 10, no legacy keys); validation (mode enum, slots
  shape by mode, pool_order keys, events rejected, atomic replace, cap); cache
  bust on write; `GET /api/site/actions` ≡ public resolution on one state;
  pool order per mode including events exemption and category blocks.
- **Job**: `compute-popularity` writes `action` rows for a seeded site, deletes
  stale keys, page family absent.
- **Pages**: `actions.ts` resolution + http gate; lander renders 10.
- **Dashboard**: page and fieldsets follow the repo's existing component test
  pattern; typecheck + lint gates.

## 12. Out of scope

- Owner-chosen slot count.
- Locks in pools.
- A per-action owner "boost" weight.
- Item-level demand-rate priors beyond one prior per kind.
- Any change to page presence rules or `manual_page_order` shape.
- Rebuilding the lander's visual design (the row variant only).
