# Smart ordering v2 — handoff plan (approved 2026-08-23)

> **For the next session:** this is a fresh-chat handoff. Read §0 (state of the
> world) and §1 (decisions) before touching anything; then execute §3 lane by
> lane, one tested commit per task. Doctrine: `~/Developer/CLAUDE.md` (hub),
> `Comet-Backend/CLAUDE.md`, `partna-monorepo/apps/dashboard/CLAUDE.md`
> (inline work, no subagent implementation), `apps/pages/CLAUDE.md`
> (tokens-only CSS, pure .astro).

## 0. State of the world (what already shipped today)

Two systems shipped to **dev** today (backend `development` → `dev-api.partna.au`,
pages worker `partna-pages`, dashboard `app.partna.au`). Production was
deliberately NOT fast-forwarded.

**Unified actions** — spec `docs/superpowers/specs/2026-08-23-unified-actions-and-ordering-design.md`,
PRs #305 + #306 (merged). One ranked list of actions (pages + destination
platforms + served items + categories), three modes (`newest` default, `smart`,
`manual`), positional locks, top-level `actions` on the public payload,
`GET /api/site/actions`, dashboard `/actions` page, lander `ActionStack`.

- Backend module: `app/Site/Actions/{ActionId,ActionSettings,ActionCandidates,ActionSlots}.php`,
  `app/Services/Analytics/ActionScorer.php` (composite score, runs inside
  `analytics:compute-popularity`), `app/Site/Pools/{PoolWire,PoolOrdering}.php`.
- Settings keys (all in `site.sites.settings`, validated by
  `app/Http/Requests/Concerns/SiteOrderingValidationRules.php`, atomic-replace
  via `UpdateSiteAction::LIST_SETTINGS_KEYS`): `actions {mode, slots}`,
  `pool_order {pool: mode}`, `pool_locks {pool: [{position,id}]}`,
  `smart_page_order`, `manual_page_order`. Retired keys are stripped.
- Legacy removed: `SiteActionsService`, `ActionVocabulary`,
  `RankedActionsComputer`, `rankedActions`/`ordering` wire keys, the page-score
  family (`content_type='page'`), `smart_actions`/`manual_actions`/`manual_order_pools`.
- Migrations applied on the dev ref `glncumufgaqcmqhzwrxm`:
  `20260823100000_unified_actions.sql` + `…100001_validate.sql`.

**Pool ordering** — per-pool mode reorders the WHOLE selection on the wire
(pins + auto): newest = dated first by `publishedAt` then undated by
`firstSeenAt` (X5; a link-pool item counts first-seen as its date), smart =
`popularityRank` asc, manual = pins by `sort_key` then rule order. Events
always occurrence, reviews never rank. `pool_locks` hold an item at a position
under newest/smart. Dashboard: `DataTable` has a `rowLock` capability,
`PoolTable`/`MediaGrid` drag only in manual, ⋯ menu Lock/Unlock, `OrderModeChip`
in the toolbar, `PoolOrderFieldset` at the foot, smart shows a `#` rank column.
Hook: `apps/dashboard/hooks/use-pool-ordering.ts`.

**Action smart score (done, live):**
```
demandRate = (taps + 25·prior) / (seen + 25)   session-distinct seen/tap on analytics.action_events
                                               keyed by action id, 90-day day-bucketed decay
reach      = decayed taps ÷ site max           (+ the item's pool score for item/category kinds)
freshness  = 2^(−ageDays/14)                   connectedAt (items: publishedAt ?? firstSeenAt)
prior      = config partna.actions.priors      page:reservations .30, services .28, menu .28,
                                               shop .15, events .14, contact .12, platform .05,
                                               category .04, item .03
score      = 0.45·demand + 0.30·reach + 0.25·fresh + prior; blend 0.7/0.3; >10% overtake
```
Beacons: `ActionStack` rows + the Key Action fire `action-seen`/`action-tap`
with the `<kind>:<ref>` id.

**Pool smart score (computed, but signal-starved — the gap this plan closes):**
item families in `analytics.content_popularity_scores`
(`shop_product|menu_item|menu_category|service|block|gallery_item|engine_item|listen_item|watch_item|link_item`)
scored by `ComputeContentPopularityScores::aggregateItems` + `scoreAndRank`:
`Σ_days (3·clicks + 1·views)·2^(−age/90) (+ freshness for links only)`, fed by
`analytics.item_views` (`item-seen` beacons) and `analytics.link_clicks`
(`product_id` + `section_key` → family via `CLICK_SECTION_TO_ITEM_TYPE`).
**Facts:** (a) no rebuilt sitepage surface emits `data-item-type/id`, so
`item_views` is empty; (b) media has no family in the section map; (c) products
are keyed by catalog handle and links by url, everything else by item id —
`PoolResolver::rankFor` is family-aware to cope, and `ActionScorer`'s reach fold
misses products/links because of it.

## 1. Decisions (owner, 2026-08-23 — do not re-litigate)

| # | Decision |
|---|---|
| D1 | **Media never produces an action.** `ActionCandidates::fromPools` skips the `media` pool entirely (no `item:` and no category/gallery entry). Media keeps its pool smart order. |
| D2 | **Category score = SUM of member item scores** (breadth beats one hit). Stored as `menu_category` / `service_category` rows keyed by collection id. |
| D3 | **One item formula, per-kind weights** (§2 table), every family keyed by `content.items.id`. |
| D4 | **Menu + Services display grouped by category**, items inside ordered by the mode; the dashboard's category facet filters to one group; item locks hold a position *within* the category; category order follows the mode (manual = the Categories sheet's drag). No category-level locks. |
| D5 | Sitepage: **Book** renders grouped by category now; the **Menu page** is out of scope (render it the same way when it's built). |
| D6 | Job scope grows to sites whose pool content changed since the last run, not only sites with traffic. |
| D7 | Lander taps on an `item:` action also count as an item click in that item's family. |

## 2. The per-kind weight table (config: `partna.pools.smart.<kind>`)

```
score(item) = Σ_days (w_click·clicks_d + w_view·views_d + w_dwell·dwell_s_d) · 2^(−age_d/90)
            + w_fresh · 2^(−ageSince(publishedAt ?? firstSeenAt) / halfLife)
```

| kind (content.items.kind → family) | w_click | w_view | w_dwell/s | w_fresh | half-life |
|---|---|---|---|---|---|
| product → shop_product | 3 | 1 | 0 | 2 | 14d |
| video → watch_item | 2 | 1 | 0 | 3 | 14d |
| track / release / episode → listen_item | 2 | 1 | 0 | 4 | 21d |
| service → service | 3 | 1 | 0 | 1 | 30d |
| menu_item → menu_item | 1 | 1 | 0 | 0.5 | 60d |
| media → gallery_item | 0.5 | 1 | 0.05 | 5 | 7d |
| link → link_item | 3 | 1 | 0 | 3 | 14d |
| event → (never scored; occurrence order) | — | — | — | — | — |

Blend 0.7 new / 0.3 previous and the >10% overtake hysteresis stay as they are.
Category rows: `score = Σ member scores`, ranked among the pool's categories.

## 3. Tasks

### Lane A — Comet-Backend (branch `feat/smart-ordering-v2-2026-08-23` off `development`)

**A1. Key every item family by `content.items.id`.**
- `app/Console/Commands/ComputeContentPopularityScores.php`: `aggregateItems` —
  `link_clicks.product_id` already carries the item id for most families; for
  shop products and links resolve the stored handle/url to the item id via
  `content.f_catalog.handle` / `content.f_link.url` (one query per site, keyed map).
  `item_views.item_id` is the item id already.
- `app/Services/Analytics/ContentFreshness.php`: link boosts keyed by item id (join gives `i.id`).
- `app/Site/Pools/PoolResolver.php`: `rankFor()` becomes `$ranks[$family][$itemId] ?? null`
  (drop the handle/url arms); `popularityRanks()` unchanged.
- `app/Services/Analytics/ActionScorer.php::itemSignal` now matches by item id for every kind.
- Migration `supabase/migrations/20260823120000_item_scores_keyed_by_id.sql`
  (`BEGIN` + lock timeouts): `DELETE FROM analytics.content_popularity_scores WHERE content_type IN ('shop_product','link_item')` — they recompute within 15 min; ROLLBACK comment "none needed".
- Tests: `tests/Feature/Content/ShopPoolPayloadTest.php` (the handle-keyed case → id-keyed; keep the cross-family-leak guard), `tests/Feature/Analytics/ComputePopularityScoresTest.php` (link_item keyed by id), `tests/Feature/Analytics/ActionScorerTest.php` (reach fold hits a product).

**A2. Per-kind weights + dwell.**
- `config/partna.php`: new `pools.smart` block with the §2 table (`default` row = today's 3/1/0/0).
- `ComputeContentPopularityScores::scoreAndRank` takes the kind's weights; `aggregateItems` adds a dwell signal from `analytics.section_views.duration_ms` folded to the item when the view carries `item_id` (only `item_views` has item grain today — ADD `duration_ms` to the `item-seen` beacon? No: keep dwell = 0 for every kind except media, where dwell comes from the gallery page's `section_views` dwell divided equally across the served media items. Document this as the media approximation.)
- Freshness generalised: `ContentFreshness::boostsForSite` returns `family => itemId => boost` for EVERY family using the kind's `w_fresh`/`half-life` from `publishedAt ?? firstSeenAt` (read `content.f_published` + `content.items.first_seen_at`), seeded into the aggregate so zero-signal items rank by freshness (cold start).
- Tests: per-kind weight applied (two kinds, same events, different scores); media freshness dominates at day 0 and fades by day 21; event items never get a row.

**A3. `gallery_item` family + category sums.**
- `CLICK_SECTION_TO_ITEM_TYPE`: add `gallery`/`media` → `gallery_item`.
- New in the job after item families: for `menus` and `services`, sum member
  scores per served collection (members = `content.collection_items` for the
  site's provider-null collections) → `menu_category` / `service_category`
  rows keyed by collection id, ranked desc. `service_category` must be added to
  the `content_type` CHECK (migration + `ConstraintVocabularyLockstepTest` +
  `ItemSeenRequest::ITEM_TYPES`) — same NOT VALID/validate two-file pattern as
  `20260823100000`.
- `PoolResolver`: `collections[cid]['popularityRank']` from the category family;
  `STORE_KEYS`/collection projection + `tests/Feature/Content/PoolWireShapeTest.php` updated.
- `PoolOrdering::orderCollections` in smart: by category rank (unranked after, by position); items within a category by their own rank. Newest unchanged. Manual unchanged.
- `ActionCandidates::fromPools`: **skip the `media` pool** (D1); category candidates carry `meta.itemIds` as now; `ActionScorer::itemSignal` for a category = SUM of members (was max).
- Tests: category sum + ranking, media excluded from candidates (unit), collections rank on the wire.

**A4. Lander taps → item families (D7).**
- `aggregateItems`: read `analytics.action_events` taps where `action_id LIKE 'item:%'`, session-distinct, day-bucketed, and add them as clicks to the item's family (kind from `content.items.kind`). Test: a lander tap on `item:<uuid>` raises that item's `watch_item` score.

**A5. Job scope (D6).**
- `siteIdsWithRecentEvents` ∪ sites with `content.items` created/updated or `content.f_published` updated since `now − RECENT_EVENTS_WINDOW_MINUTES`. Test: a site with a new item and no traffic gets scored in a sweep.

**A6. Docs + ship.** `docs/api.md` (collections `popularityRank`, families keyed by id, media never an action), spec addendum in `docs/superpowers/specs/2026-08-23-unified-actions-and-ordering-design.md` (§5.4, §6, D1). Gates: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G` (0 errors as of today), `COMPOSER_PROCESS_TIMEOUT=0 composer test` (8758 green baseline). PR → merge → `npx --no-install supabase db push --dry-run` then push (dev ref only).

### Lane B — apps/pages (monorepo `main`)

**B1. Item beacons on every rendered pool card.** `src/analytics/dom-contract.ts` has `itemAttrs`-style helpers; wire `data-item-type`/`data-item-id`/`data-href` onto `ItemCard` (and `SingleActionCard` when it renders an item) for Book, Watch, Events now — and the pattern for Listen/Menu/Shop/Gallery when built. `behaviors.ts` already dispatches `partna:item` on scroll-into-view; `tracker.ts` sends `item-seen` and clicks. Test in `test/` per the existing analytics tests.

**B2. Book grouped by category.** `src/pages/[...path].astro` services branch: group `content.pools.services.items` by `collectionIds` against `collections` (first provider-null collection = home, as `ActionCandidates::fromPools` does), render groups in `collections[].position` order with a category heading, items in wire order. Uncategorised services last under no heading. Tokens-only CSS; verify 1280 + 375 by computed rects.

**B3. Delete dead ranking.** `src/content/ranking.ts` `sortByRank` + `sortGroupsByScoreSum` (unused since the rebuild) and their tests.

**B4. Ship:** `npm run test -w apps/pages`, `npm run typecheck -w apps/pages`, `bash apps/pages/scripts/tokens-only-audit.sh`, then `npm run deploy` (DEFAULT worker only). Purge a test handle's HTML via the backend's own service if you need to see it immediately:
`cloud command:run development --no-monitor --cmd="php artisan tinker --execute=\"app(\\App\\Services\\Cloudflare\\CloudflarePurgeService::class)->purgeHandle('natalieannehair');\""` then `cloud command:get <id> --fields=status,output`.

### Lane C — apps/dashboard (monorepo `main`)

**C1. `DataTable` `groupBy` mode (base primitive).** New prop
`groupBy?: { key: (row) => string | null; header: (key, rows) => ReactNode; order: string[] }`:
renders a sticky group header row per key in `order` (ungrouped rows last under no header), keeps sorting/search/facets as projections (a projection disables drag as today), drag in manual reorders rows **within** their group only (dnd-kit `Sortable` per group), `rowLock` lead column per row unchanged. Showcase card in `app/dev/components/blocks/data-table/page.tsx`.

**C2. Menu + Book tables grouped.** `menus-page.tsx` / `services-page.tsx` pass `groupBy` from the wire's `collections` (provider-null only; `position` is the order; header = name · `#rank` in smart · `N items`). Category facet (`components/blocks/pools/category-column.tsx`) now filters to one group. Lock tooltip: "Locked at #n in {category}". `usePoolOrdering.lockAt` position = index **within the category**; the backend applies locks after ordering within the category (A3 must apply `pool_locks` per category for `menus`/`services` — add that to A3: `PoolOrdering::applyLocks` per collection bucket, position = index in bucket).

**C3. Gates + ship:** `npx tsc --noEmit` (0), `npx eslint .` (0 errors; 38 pre-existing warnings), routes 200, no sideways scroll at 375px; push `main` (Vercel deploys `app.partna.au`).

### Deploy order
pages (B) → backend (A, incl. `supabase db push`) → dashboard (C). The pages
resolver tolerates the old wire, so pages can go first; C2 needs A3's
collection ranks + per-category locks, so the dashboard goes last.

## 4. Known issues you will meet (already understood — don't re-investigate)

- CI `postgres-tests` is red on `development`'s tip with
  `SourceIntentUpsertRaceTest` ("column resource_id does not exist") — routing
  lane, predates all of this. Both of today's PRs were merged with
  `gh pr merge --admin` noting it. Fix belongs to that lane.
- Dashboard prettier drift (16 files) and 38 eslint warnings are pre-existing;
  the gates are `tsc` + `eslint` errors only.
- Pest loads every test file into one process: a `function foo()` helper in a
  test file collides with the same name anywhere else under `tests/`
  (`seedConnection`, `poolItem` bit me). Prefix helpers per file.
- `vendor/bin/pint` without paths reformats 7 unrelated routing/platform
  files — as of PR #305 they are formatted; keep it that way, but always pass
  paths.
- The shell's locale chokes on unicode labels in `python3 -c` one-liners
  ("character not in range"); use `PYTHONIOENCODING=utf-8` or a heredoc.
- `git stash` in the monorepo: another session keeps a retained stash there
  ("contact-band form-field-styling"). `git stash pop` with a clean tree pops
  THEIRS. Never stash-probe in a shared tree.
- Browser pane: screenshots can render black/blank; measure with
  `javascript_tool` rects and computed styles instead. Dashboard app routes are
  login-gated — verify primitives on `/dev/components/...` showcase pages.
- The pages dev server on :4321 may belong to another chat; navigate to it
  rather than starting a second.

## 5. Verification checklist (end of run)

- Backend full suite green, pint/phpstan clean, both migrations dry-run clean then pushed.
- `GET /api/public/profiles/natalieannehair` on dev: `actions.entries` contains no `item:` from the media pool; `profile.pools.services.collections[*].popularityRank` present.
- `analytics:compute-popularity --dry-run --site=01a01a74-6ec2-7102-bae7-d865dd39ddd8` (the test site) prints `service_category`/`gallery_item` rows.
- Sitepage Book page renders category headings; a Book card carries `data-item-type="service"`.
- Dashboard `/menu` and `/services` render group headers; facet filters to one group; smart shows `#` on headers; drag only in manual and only within a group.
