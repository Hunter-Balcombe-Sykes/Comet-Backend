# Actions Rebuild — Fixed Vocabulary + Demand-Rate Scoring — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the pool-derived page|item|button action system with a fixed 26-action vocabulary scored by session-level demand rate (smoothed CTR + fading intent prior), rendered as the actionable lander's real buttons, with a dashboard smart-toggle + manual drag-reorder dialog.

**Architecture:** Three sequential per-repo phases (backend → apps/pages → Partna-Frontend). Backend gains an action catalog (eligibility + hrefs), a new `analytics.action_events` raw table fed by two new beacons, and a rewritten in-place `RankedActionsComputer` implementing `rate = (T + k·prior)/(E + k)` over session-distinct decayed exposure/tap counts. Storage, blend, hysteresis, 15-min cadence, `smart_actions`/`manual_actions` settings keys, and the payload emission points all stay. Page/item popularity scoring is untouched and fully decoupled from actions.

**Tech Stack:** Laravel 12 + Supabase raw-SQL migrations + Pest (backend); Astro on Cloudflare Workers + vitest (pages); Next.js 16 + shadcn/Geist + @dnd-kit (dashboard).

## Locked decisions

| # | Decision |
|---|---|
| D1 | `booking-services` is present when the services page is present **or** a booking link/provider exists; when the services page is absent the action's href is the **external booking URL** (kind `external`), never /services |
| D2 | Custom-link actions come from **both** storages — links-group blocks (category custom) and `custom` platform-connection link cards — deduped by URL, block wins |
| D3 | Online-ordering actions come from **online-ordering connection entries only** (no link-block fallback) |
| D4 | A social with only a platform-tagged link block still gets its action; when a connection also exists, the connection URL wins |
| D5 | Priors table + k = 25 as specified in `config/partna.php` below; tune post-launch |
| D6 | Manual mode is strict curation — refs not in the manual list stay hidden; nothing auto-appended |

## Global constraints

- No Laravel migration files — schema changes are raw SQL in `supabase/migrations/`, applied with `supabase db push` on the dev ref (`glncumufgaqcmqhzwrxm`).
- Every new/changed endpoint and the catalog consult `AccountCapabilities` (never `account_type`).
- Analytics file/route naming must avoid the substring "events" in client-visible paths and bundle filenames (ad-blocker filter lists — see `apps/pages/src/analytics/signals.ts:9-13`). Table names server-side are fine.
- apps/pages: pure `.astro`, no React; interaction JS in `.client.ts`/analytics modules; tokens-only CSS; deploy via `npm run deploy` (default worker) only.
- Partna-Frontend: shadcn/Geist only, lucide-react icons, no new `*.module.css`; `npm run typecheck && npm run lint` before commit.
- Page/item scoring (`content_type='page'` + item types) must be byte-identical before/after — actions no longer read page/item scores at all.
- Deploy order backend → pages → FE. Backend-first is safe: the live lander renders placeholder buttons (no visible consumer of the wire yet) and the dashboard only reads `ordering` toggles.
- `content_popularity_scores` reuse: `content_type='action'`, `content_key=<action id>`. Old `<kind>:<ref>` keys are swept by the existing stale-key deletion on first run.

## The 26-action vocabulary (LOCKSTEP — pinned by tests in backend AND apps/pages)

Static ids (24):

```
reservations, shop, shop-tracks, events, booking-services, menu, contact,
spotify, soundcloud, apple-music, apple-podcasts, twitch,
instagram, facebook, linkedin, youtube, tiktok, x,
snapchat, pinterest, threads, discord, reddit, telegram
```

Dynamic families (2): `ordering:<resource_id>`, `custom:<key>`.

Per-action eligibility + href (all presence-gated per site):

| id | kind | label | href source | eligible when |
|---|---|---|---|---|
| reservations | external | Reservations | first active opentable/resdiary/nowbookit connection `payload.url` (sort_order asc) | such a connection exists |
| shop | page | Shop | /shop | 'shop' in presentPageIds |
| shop-tracks | page | Shop tracks | /shop-tracks | active bandcamp connection |
| events | page | Events | /events | 'events' in presentPageIds |
| booking-services | page OR external | Booking and services | /services when 'services' present, else booking link URL | 'services' present OR booking live (D1) |
| menu | page | Menu | /menu | 'menu' in presentPageIds (Business cap via shared gate) |
| contact | page | Contact | /contact | 'contact' in presentPageIds |
| spotify / soundcloud | external | Spotify / SoundCloud | connection `payload.url ?? payload.link` | active connection |
| apple-music / apple-podcasts | external | Apple Music / Apple Podcasts | connection `payload.link` | active connection |
| twitch | external | Twitch | connection `payload.url` | active connection |
| instagram | external | Instagram | `https://www.instagram.com/{payload.username}`; block fallback | connection or tagged block (D4) |
| youtube | external | YouTube | `https://www.youtube.com/@{payload.handle}`; block fallback | connection or tagged block |
| facebook / tiktok / x / linkedin / threads / reddit | external | platform name | connection `payload.url`; block fallback | connection or tagged block |
| snapchat / pinterest / discord / telegram | external | platform name | pinterest connection `payload.url` else platform-tagged link block `url` (others: block only) | source exists |
| ordering:<resource_id> | external | entry name (payload.name, fallback 'Order online') | entry `payload.url` | active online-ordering entry AND `can_use_online_ordering` (D3) |
| custom:<key> | external | link title | link URL | the link exists (D2; key = block uuid or connection resource_id; URL-dedupe, block wins) |

All external hrefs pass the existing `safeHref()` http(s) gate at emit.

## Scoring model (replaces the 0.60·norm + 0.25·prior + 0.15·recency core)

```
per action, per day d:  w_d = 2^(-age_d / 90)          (reuse dayWeight)
  exposures_d = COUNT(DISTINCT session_id) of 'seen' rows
  taps_d      = COUNT(DISTINCT session_id) of 'tap'  rows
E = Σ w_d·exposures_d          T = Σ w_d·taps_d
rate    = (T + k·prior) / (E + k)                       k = config('partna.actions.prior_k') = 25
blended = 0.7·rate + 0.3·previous stored                (unchanged BLEND_NEW/BLEND_PREV)
rank    = existing rankWithHysteresis, >10% swap gate   (unchanged, verbatim)
deletes = stored action keys no longer in the pool      (unchanged semantics)
```

No recency term, no within-kind normalisation, no button click sums. Rate is already in [0,1] and cross-comparable.

`config/partna.php` addition:

```php
'actions' => [
    'prior_k' => (int) env('PARTNA_ACTIONS_PRIOR_K', 25),
    'default_prior' => 0.05,
    'priors' => [
        'reservations' => 0.30, 'booking-services' => 0.28, 'menu' => 0.28, 'ordering' => 0.25,
        'shop' => 0.15, 'events' => 0.14, 'contact' => 0.12,
        'spotify' => 0.08, 'soundcloud' => 0.08, 'apple-music' => 0.08,
        'apple-podcasts' => 0.08, 'twitch' => 0.08, 'shop-tracks' => 0.08,
        'instagram' => 0.05, 'facebook' => 0.05, 'linkedin' => 0.05, 'youtube' => 0.05,
        'tiktok' => 0.05, 'x' => 0.05, 'snapchat' => 0.05, 'pinterest' => 0.05,
        'threads' => 0.05, 'discord' => 0.05, 'reddit' => 0.05, 'telegram' => 0.05,
        'custom' => 0.05,
    ],
],
```

`priorFor(actionId)`: exact id → family prefix before `:` (`ordering`, `custom`) → `default_prior`.

---

# PHASE 1 — Comet-Backend (feature branch `feature/actions-demand-rate` off `development`)

### Task 1.1: Action vocabulary + catalog (rewrite `SiteActionsService` pool internals)

**Files:**
- Create: `app/Services/PublicSite/Actions/ActionVocabulary.php`
- Modify: `app/Services/PublicSite/SiteActionsService.php` (pool() + entry shape; DELETE `buttonActions()`, `itemActions()`, `itemLabels()`, `ITEM_TYPE_TO_PAGE`, `ITEMS_PER_TYPE`)
- Modify: `config/partna.php` (actions block above)
- Test: `tests/Feature/PublicSite/ActionCatalogTest.php` (new), `tests/Unit/ActionVocabularyTest.php` (new)

**Interfaces:**
- Produces `ActionVocabulary::STATIC_IDS: list<string>` (the 24, canonical order = the table order), `ActionVocabulary::FAMILIES = ['ordering', 'custom']`, `ActionVocabulary::isValidId(string): bool` (static id, or `family:key` with key `[A-Za-z0-9_.:\/-]{1,160}`), `ActionVocabulary::priorFor(string $id): float`, `ActionVocabulary::labelFor(string $staticId): string`.
- Produces new pool-entry shape consumed by every later task: `{id: string, kind: 'page'|'external', label: string, pageId: ?string, url: ?string, platform: ?string, sourceCreatedAt: ?string}` (platform = design-assets icon slug, equal to the action id for platform/social actions, null for pages/custom).
- `SiteActionsService::pool(User $pro, ?Site $site, ?Collection $sections = null, ?array $booking = null): array` — note `$ranks` param REMOVED (grep the 3 callers: `IndividualProfilePayloadBuilder`, `UserSiteActionsController`, `ComputeContentPopularityScores`).

- [ ] **Step 1:** Write `ActionVocabularyTest` pinning the exact 24-id list + family prefixes + `isValidId` accept/reject cases (`'menu'` ok, `'ordering:order-abc'` ok, `'custom:'` reject, `'burger'` reject, 161-char key reject) + `priorFor` (exact, family, default). Run `composer test -- --filter=ActionVocabularyTest` — FAIL (class missing).
- [ ] **Step 2:** Implement `ActionVocabulary` (const arrays + the three statics; priors read `config('partna.actions.*')`). Add the config block. Run — PASS.
- [ ] **Step 3:** Write `ActionCatalogTest` — factory site fixtures asserting the eligibility matrix: (a) bare site → empty pool; (b) site with menu+contact present → those two page entries only, correct hrefs; (c) opentable connection → reservations external entry w/ payload url; (d) services absent + booking live → booking-services external w/ booking URL (D1); (e) services present → booking-services page kind `/services`; (f) spotify connection → spotify entry `payload.url`; (g) instagram connection beats instagram-tagged block (D4); (h) telegram tagged block alone → telegram entry; (i) online-ordering entries → one `ordering:<resource_id>` each, gated off when `can_use_online_ordering` false (D3); (j) custom block + custom connection same URL → ONE `custom:<block-id>` entry (D2 dedupe, block wins); (k) bandcamp → shop-tracks. Run — FAIL.
- [ ] **Step 4:** Rewrite `pool()` to enumerate the vocabulary per the eligibility table (reads: `presentPageIds` via resolver + caps [existing pattern at SiteActionsService.php:94-95], `IntegrationConnection` rows for the user by platform, links via `$this->resolver->getLinks()`, booking via `getBooking`). Delete the three private builders + constants. `sourceCreatedAt` = connection/block `created_at` ISO (nullable; unused by scoring v1, kept for the FE "new" hint + future exploration). Run — PASS.
- [ ] **Step 5:** `php artisan pint` → commit `feat(actions): fixed 26-action vocabulary + catalog`.

### Task 1.2: `analytics.action_events` table

**Files:**
- Create: `supabase/migrations/20260723090000_create_action_events.sql`
- Modify: the analytics retention/purge command that covers `item_views` (grep `item_views` under `app/Console/Commands` — add the new table with identical retention)
- Test: extend that command's existing test with the new table case

- [ ] **Step 1:** Write the migration (mirror `20260709042911_create_item_views.sql` exactly in posture — RLS on, `app_backend` grants, comment, down note):

```sql
CREATE TABLE IF NOT EXISTS analytics.action_events (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id      uuid,
    site_id      uuid NOT NULL,
    action_id    text NOT NULL,
    event        text NOT NULL CHECK (event IN ('seen','tap')),
    occurred_at  timestamptz NOT NULL DEFAULT now(),
    session_id   uuid,
    visitor_id   uuid,
    ip_hash      text,
    user_agent   text,
    referrer     text,
    country_code text,
    device_type  text,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS action_events_site_occurred_idx ON analytics.action_events (site_id, occurred_at);
CREATE INDEX IF NOT EXISTS action_events_site_action_occurred_idx ON analytics.action_events (site_id, action_id, occurred_at);
ALTER TABLE analytics.action_events ENABLE ROW LEVEL SECURITY;
GRANT SELECT, INSERT, UPDATE, DELETE ON analytics.action_events TO app_backend;
```

- [ ] **Step 2:** Add the SQLite test-schema twin wherever `item_views` is declared for tests (grep `item_views` in `tests/` bootstrap/schema helpers).
- [ ] **Step 3:** Wire into the purge command + FK/site-delete teardown paths that cover `item_views` (grep both `analytics.item_views` usages outside scoring). Extend the purge test. Run `composer test -- --filter=Purge` — PASS.
- [ ] **Step 4:** `supabase db push --dry-run` then `db push` on dev ref. Commit `feat(analytics): action_events raw table`.

### Task 1.3: Beacon endpoints `/public/analytics/action-seen` + `action-tap`

**Files:**
- Create: `app/Http/Requests/Api/PublicSite/Analytics/ActionSeenRequest.php`, `ActionTapRequest.php` (identical rules; two classes so future divergence is cheap)
- Modify: `app/Http/Controllers/Api/PublicSite/AnalyticsController.php` (add `actionSeen()`, `actionTap()`), `app/Services/Analytics/AnalyticsEvent.php` (TYPE_ACTION_SEEN/TYPE_ACTION_TAP + `actionId`, `actionEvent` fields), the Postgres event writer (grep class writing `analytics.item_views` — add the `action_events` insert case), `app/Services/Cache/CacheKeyGenerator.php` (`analyticsActionDedup(siteId, actionId, identifier)`), `routes/api.php` (two POST routes beside `item-seen`, same middleware/throttle group)
- Test: `tests/Feature/Analytics/ActionBeaconsTest.php`

**Interfaces:**
- Request rules: `action_id` required string matching `ActionVocabulary::isValidId`; `session_id`/`visitor_id` nullable uuid; `subdomain`/`site_id`/referrer/utm fields copied verbatim from `ItemSeenRequest`.
- Produces rows in `analytics.action_events` with `event='seen'|'tap'`.

- [ ] **Step 1:** Write `ActionBeaconsTest` mirroring the item-seen suite's security cases: publication gate 404, origin-bind reject, bot fake-success, 300s dedup returns original id, invalid `action_id` 422, happy path writes a row with correct event kind. Run — FAIL.
- [ ] **Step 2:** Implement requests + controller methods (copy `itemSeen()` shape 1:1 — resolve, origin, bot, dedup key `analyticsActionDedup`, buildEvent with the two new fields) + writer insert + routes. Run — PASS.
- [ ] **Step 3:** Pint → commit `feat(analytics): action-seen/action-tap ingest`.

### Task 1.4: Demand-rate computer (rewrite `RankedActionsComputer` in place)

**Files:**
- Modify: `app/Services/Analytics/RankedActionsComputer.php` (keep class name + `CONTENT_TYPE='action'`; delete REF_PRIORS/ITEM_TYPE_PRIORS/KIND_PRIORS/W_*/CLICK_HALF_LIFE/RECENCY_*/`buttonClickSums`/`nativeScore`/`recencyScore`/`allClickPlatforms`; keep `previousRows`, `rankWithHysteresis`, BLEND_* consts)
- Modify: `app/Console/Commands/ComputeContentPopularityScores.php` (`computeActions()` no longer threads `$rows`/ranks — calls `$this->actions->pool($pro, $site)` then `computeForSite($site, $pool)`; docblock update)
- Test: rewrite `tests/Feature/Analytics/RankedActionsComputeTest.php`

**Interfaces:**
- `computeForSite(Site $site, array $pool): array{rows: list<row>, deletes: list<string>}` — row shape unchanged (id/site_id/content_type/content_key/score/rank/computed_at).
- `RankedActionsComputer::priorFor(array $entry): float` retained for the cold-path append sort in `resolveRankedActions` — now delegates to `ActionVocabulary::priorFor($entry['id'])`.

- [ ] **Step 1:** Rewrite the test file: (a) zero events → scores exactly equal priors (worked example: menu 0.28, instagram 0.05), rank order = prior order; (b) instagram 10 tap-sessions / 40 seen-sessions vs menu 20/200 → assert instagram rate (10+25·0.05)/(40+25) ≈ 0.1731 beats menu (20+25·0.28)/(200+25) ≈ 0.1200 and outranks it after hysteresis; (c) session-dedupe — 15 taps same session_id count once; (d) day decay — 180-day-old sessions weigh 2^(-2)=0.25; (e) blend — second run with prev stored 0.5, new rate 0.1 → 0.22; (f) stale key deletion when pool shrinks; (g) empty pool deletes all. Aggregation must run on the SQLite test driver: two grouped queries (`event='seen'`, `event='tap'`) with `COUNT(DISTINCT session_id)` + the existing `dayBucketExpr()` pattern — no `FILTER` clauses. Run — FAIL.
- [ ] **Step 2:** Implement: `aggregate(Site): array<actionId, array{E: float, T: float}>` (two grouped queries over `analytics.action_events`, null session_id rows fall back to `COUNT(DISTINCT id)` semantics by grouping on `COALESCE(session_id::text, id::text)`), then rate/blend/hysteresis/deletes per the model box above. Run — PASS.
- [ ] **Step 3:** Update `ComputeContentPopularityScores::computeActions()` (+ its docblock chunk about the action layer). Full `composer test` — PASS (page/item suites must be untouched-green).
- [ ] **Step 4:** Pint → commit `feat(actions): demand-rate scoring (smoothed session CTR + fading prior)`.

### Task 1.5: Wire + ordering settings + dashboard endpoint

**Files:**
- Modify: `app/Services/PublicSite/SiteActionsService.php` (`toWire()` → `{id, kind, label, pageId, url, platform, score}`; `resolveRankedActions()` keys by `id` not `kind:ref`; manual resolution matches `ref` against entry `id`; legacy stored-entry normalisation in `orderingSettings()`: `['kind'=>'page','ref'=>X]` → `X` if in vocabulary, `['kind'=>'button','ref'=>'booking']` → `booking-services`, button:<social-in-vocab> → that id, everything else dropped)
- Modify: `app/Http/Requests/Concerns/SiteOrderingValidationRules.php` (`manual_actions.*` → `{kind:'action', ref: <isValidId>}`, max:26 entries, distinct refs; `normalizeOrderingSettings()` maps legacy shapes on write per the same table)
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserSiteActionsController.php` (score lookup key `$entry['id']`)
- Note: `IndividualProfilePayloadBuilder` lines ~105-137 need only the `$ranks` param removal from the `pool()` call — wire key names `ranked_actions`/`ordering` unchanged.
- Test: rewrite `tests/Feature/PublicSite/ProfileRankedActionsTest.php`, `tests/Feature/Api/User/SiteManagement/UserSiteActionsEndpointTest.php`, `ActionSettingsValidationTest.php`

- [ ] **Step 1:** Rewrite the three test files: payload emits new shape ordered by stored rank with cold-append by prior (deterministic id tiebreak); smart off + manual `[menu, instagram]` → exactly those two in order, unknown ref dropped, nothing appended (D6); validation accepts `{kind:'action', ref:'menu'}`, rejects `{kind:'page'...}` post-normalisation only when unmappable, rejects dup refs + >26; legacy stored `manual_actions` with `page:menu` + `item:*` entries reads back as `['menu']`. Run — FAIL.
- [ ] **Step 2:** Implement. Run — PASS. Full `composer test` green.
- [ ] **Step 3:** Pint → commit `feat(actions): action-id wire + ordering settings v2`.

### Task 1.6: Online-ordering public exposure (D3)

**Files:**
- Modify: `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php` (`'online-ordering' => ['kind', 'url', 'name', 'logo', 'favicon']`), `app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php` (remove online-ordering from the excluded-platforms query filter — grep the "belt-and-suspenders" exclusion)
- Test: extend the public-integrations contract test (grep tests referencing `PublicIntegrationConnectionResource`)

- [ ] **Step 1:** Test: online-ordering entries appear in the public integrations payload with ONLY the five keys; site without entries unchanged. FAIL → implement → PASS.
- [ ] **Step 2:** Confirm `ActionCatalogTest` case (i) passes end-to-end via the payload builder. Commit `feat(platforms): expose online-ordering entries publicly`.

**Phase 1 gate:** full `composer test` green; `php artisan analytics:compute-popularity --site=<test-site> --dry-run` shows `action` rows with plain-id keys + deletions of every legacy `kind:ref` key. Push branch, PR to `development`, merge before Phase 2.

---

# PHASE 2 — partna-monorepo / apps/pages

### Task 2.1: Wire type + resolution rewrite

**Files:**
- Modify: `src/lib/fetch-profile.ts` (`RankedActionWire` → `{id: string; kind: 'page'|'external'; label: string; pageId: string|null; url: string|null; platform: string|null; score: number|null}`)
- Modify: `src/content/actions.ts` (delete item-index machinery; resolution below), `src/content/types.ts` (`ResolvedAction` → `{id, kind, label, href, pageId, platform, score}`; drop itemType/itemKey/item/ref)
- Modify: `src/content/resolve-site-content.ts` (call-site: `resolveRankedActions(wire, {pages})` — items arg gone)
- Test: rewrite `test/actions.test.ts` (or create if the suite lives elsewhere — mirror existing test layout in `test/`)

```ts
export function resolveRankedActions(
  wire: readonly RankedActionWire[],
  {pages}: {pages: readonly SitePage[]},
): ResolvedAction[] {
  const presentIds = new Set(pages.map((p) => p.id));
  const out: ResolvedAction[] = [];
  for (const a of wire) {
    if (a.kind === 'page') {
      if (!isSitepageId(a.pageId) || !presentIds.has(a.pageId)) continue;
      out.push({id: a.id, kind: 'page', label: a.label, href: pagePath(a.pageId), pageId: a.pageId, platform: a.platform, score: a.score});
    } else if (a.kind === 'external') {
      if (!a.url || !/^https?:\/\//i.test(a.url)) continue;
      out.push({id: a.id, kind: 'external', label: a.label, href: a.url, pageId: null, platform: a.platform, score: a.score});
    }
  }
  return out;
}
```

`topActions()` (top 6) unchanged.

- [ ] Steps: failing tests (page resolution + present-gate drop, external scheme gate, server order preserved, top-6 slice) → implement → `npm run test` PASS → commit `feat(actions): action-id wire resolution`.

### Task 2.2: Vocabulary lockstep pin

**Files:**
- Create: `test/actions-vocabulary.test.ts` — a literal copy of the 24 static ids + 2 family prefixes with a comment pointing at `Comet-Backend app/Services/PublicSite/Actions/ActionVocabulary.php`, asserted against a new exported `ACTION_IDS` const in `src/content/actions.ts`. Same LOCKSTEP pattern as `test/scoring-id.test.ts`.
- [ ] Write test → export const → PASS → commit.

### Task 2.3: Beacons — signals, tracker, behaviors, proxy

**Files:**
- Modify: `src/analytics/signals.ts` (add `'partna:action-seen': {actionId: string}` and `'partna:action-tap': {actionId: string}` to `PartnaEventMap`)
- Modify: `src/analytics/tracker.ts` (two `onPartna` handlers → `sendAnalytics('action-seen', {action_id})` / `sendAnalytics('action-tap', {action_id})`; per-page dedupe Set for seen, same as `seenItems`; taps NOT deduped client-side)
- Modify: `src/analytics/behaviors.ts` (new `initActionSurfaces(root)`: IntersectionObserver over `[data-action]` dispatching action-seen at ≥50% visibility once per action id; capture-phase click listener on `[data-action]` dispatching action-tap before navigation)
- Modify: `src/analytics/dom-contract.ts` (add `actionAttrs(action: {id: string; href: string}): {'data-action': string; 'data-href': string}`)
- Modify: `src/middleware.ts:25` add `'action-seen', 'action-tap'` to `ANALYTICS_ACTIONS` AND the regex at `src/middleware.ts:44`; backend path map → `/public/analytics/action-seen|action-tap`
- Test: `test/` additions for `actionAttrs` shape; behaviors are DOM-coupled (covered by live verification)

- [ ] Steps: implement (no reasonable unit seam for IO — keep behaviors thin), `npm run typecheck` + `npm run test` PASS → commit `feat(analytics): action exposure/tap beacons`.

### Task 2.4: Instrument surfaces + render the real lander buttons

**Files:**
- Modify: `src/architectures/staple/staple.astro` — (a) replace the `PLACEHOLDER_ACTIONS` block (~line 204-207 + the render at ~line 721) with `topActions(content)` mapped to the existing Button primitive: label, `href`, `{...actionAttrs(action)}`, platform icon via `resolvePlatformIcon(action.platform)` when non-null; (b) stamp `{...actionAttrs(...)}` on nav-drawer page entries whose page id has a matching page action in `content.rankedActions`, and on socials-row anchors whose platform matches a social action id. Build a frontmatter lookup `actionByHint: Map<string, ResolvedAction>` (page id → action, platform → action) for both.
- Modify: `src/architectures/staple/staple.client.ts` — call `initActionSurfaces(document)` alongside the existing behavior inits.
- DOM-parity rule: attributes are ADDITIVE only; no class/structure changes to nav/socials markup.

- [ ] Steps: implement → `npm run typecheck`, `npm run test`, tokens-only audit (no CSS should change; run anyway), `grep -r "from 'react'" src/architectures` empty → commit `feat(staple): live ranked action buttons + action surface instrumentation`.

### Task 2.5: Deploy + live verify

- [ ] `npm run deploy` (default worker). Purge edge cache for the test handle (`cloud tinker development --code='app(\App\Services\Cloudflare\CloudflarePurgeService::class)->purgeHandle("ollies")'`).
- [ ] Live checks: rendered HTML contains `data-action` attrs + top-6 buttons match the payload order; POST round-trip visible as `analytics.action_events` rows (query dev Supabase); no console errors.

---

# PHASE 3 — Partna-Frontend

### Task 3.1: Types + hook update

**Files:**
- Modify: `app/(app)/account/(dashboard)/design/use-site-actions.ts` — `SiteAction` → `{id: string; kind: 'page'|'external'; label: string; pageId: string|null; url: string|null; platform: string|null; score: number|null}`; `ManualActionEntry` → `{kind: 'action'; ref: string}`; delete `actionLabel`/`actionTypeLabel` slug-humanising fallbacks (labels are always server-resolved now — keep a trivial `action.label` passthrough).
- [ ] `npm run typecheck` drives the consumer updates → commit.

### Task 3.2: Dynamic Features — rename + conditional manual row

**Files:**
- Modify: `app/(app)/account/(dashboard)/design/sitepage-dynamic-features-section.tsx` — row title "Smart action buttons" (InfoPopover copy pass under `ui-doctrine`); when `smartActions === false`, render an additional `SettingsRow` "Action button order" with an Edit button opening the Task 3.3 dialog.

### Task 3.3: Reorder dialog

**Files:**
- Create: `app/(app)/account/(dashboard)/design/action-order-dialog.tsx` — shadcn `Dialog` (`rounded-xl`), `@dnd-kit/sortable` vertical list of the pool: `GripVertical` (lucide) handle + label + kind hint (page path or hostname), keyboard sensors, `prefers-reduced-motion` respected (dnd-kit default transforms off when reduced). Seed order: `ordering.manualActions` when non-empty else current `rankedActions` order (freeze-what-you-have default). Save → `useOrderingMutation("design:action-order", "Order saved.")` with `{manual_actions: items.map(a => ({kind: 'action', ref: a.id}))}`; optimistic close + cache seed via existing `seedSiteActionsOrdering`. Structural reference: retired editor in commit `ed6926a6`.
- Modify: dynamic-features section to mount it.
- Test: `action-order-dialog.test.tsx` colocated (existing test pattern: `header-action-buttons.test.tsx`) — renders pool order, save payload shape, seed-from-smart when manual empty.

- [ ] Steps: failing test → implement → `npm run typecheck && npm run lint` + test PASS → doctrine self-check on the diff → live-verify in browser (toggle off → row appears → drag → save → GET /site/actions reflects) → commit `feat(design): manual action-order dialog behind smart toggle`.

**Phase 3 gate:** push `main` → Vercel; flip smart off on the test account, reorder, confirm ollies payload `ranked_actions` obeys manual order end-to-end.

---

# PHASE 4 — Cutover verification (no code)

- [ ] `analytics:compute-popularity --site=<ollies-site-id>` live run; confirm new-key rows + zero legacy keys remain.
- [ ] Nightwatch: no `analytics.ranked_actions_failed`; watch the 15-min tick once.
- [ ] Lander shows ranked buttons on a social-referred visit; nav/socials taps land as `tap` rows; ranks move on the next tick.
- [ ] Delete this plan file (repo convention) once shipped.

## Out of scope (explicitly deferred)

Exploration/trial boost for new actions, per-slot position-bias correction, deep-link arrival credit, per-sector prior tables, Kick, any change to page/item scoring or the smart page-order feature.

## Self-review notes

- Spec coverage: vocabulary ✓ (1.1), scoring ✓ (1.2-1.4), pages parsing/render ✓ (2.1-2.4), dashboard toggle + reorder ✓ (3.2-3.3), D1-D6 all baked into tasks ✓.
- Sequencing safety: backend-first verified safe (lander placeholders; dashboard reads only `ordering`).
- Type consistency: pool-entry `{id, kind, label, pageId, url, platform}` used identically in 1.1/1.5/2.1/3.1; `ManualActionEntry {kind:'action', ref}` in 1.5/3.3.
