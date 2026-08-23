# Unified actions + content ordering — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task, inline (dashboard doctrine forbids subagent implementation). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the button-level `rankedActions` system with one ranked list of actions (pages + destination platforms + served items + categories) in three modes with slot locks, add per-pool ordering modes that reorder the emitted pools, and ship the dashboard page + lander stack that use them.

**Architecture:** Backend: a new `App\Site\Actions` module — `ActionCandidates` (derive), `ActionSettings` (read settings), `ActionSlots` (pure resolve) — plus `ActionScorer` in the 15-minute popularity job, `PoolWire` (the shared pool hydration), `PoolOrdering` inside `PoolResolver`. Pages: consume `actions` and render the stack. Dashboard: `/actions` page, `PoolOrderFieldset`, summary card. Legacy removed in the final backend task after pages has switched.

**Tech Stack:** Laravel 12 / Pest (SQLite lane for feature tests, `pgsql` connection stand-in), Supabase SQL migrations, Next 16 + TanStack Query v5 + Radix, Astro 5 + vitest.

Spec: `docs/superpowers/specs/2026-08-23-unified-actions-and-ordering-design.md`.

## Global Constraints

- Backend branch `feat/unified-actions-2026-08-23` on Comet-Backend; monorepo works on `main`, commit per unit of work, never force-push.
- Backend gates before every commit: `vendor/bin/pint --test` (changed files), `vendor/bin/phpstan analyse --memory-limit=1G` (level 5), and the targeted Pest files; full `COMPOSER_PROCESS_TIMEOUT=0 composer test` before the removal commit and at lane end.
- Dashboard gates: `npx tsc --noEmit` (0 errors), `npx eslint .` (0 errors), routes 200, no sideways scroll at 375px. No test runner exists there.
- Pages gates: `npm run typecheck` + `npm run test` in `apps/pages`; tokens-only CSS (`var(--dk-*)`), pure `.astro`, no component forks.
- Action id grammar everywhere: `^(page|platform|item|category):[A-Za-z0-9_.:/-]{1,160}$`.
- Slot count: `config('partna.actions.slots')` = 10. Modes: `newest` | `smart` | `manual`, default `newest`.
- Backend pool keys (the only valid `pool_order` keys): `watch, listen, media, services, shop, custom_links, menus`. `events`/`reviews` rejected.
- Page-action ids: `page:services`, `page:reservations`, `page:menu`, `page:shop`, `page:events`, `page:contact`.
- Deploy order: backend (with overlap) → pages → backend removal → dashboard.
- Migrations: Supabase SQL only, `BEGIN`/`COMMIT`, no `CONCURRENTLY`, ROLLBACK comment at top, `supabase db push` on the dev ref (done by the owner on `/ship`; never from this plan).

---

## Lane A — Comet-Backend

### Task A1: Action id grammar + config + `ActionSettings`

**Files:**
- Create: `app/Site/Actions/ActionId.php`
- Create: `app/Site/Actions/ActionSettings.php`
- Modify: `config/partna.php` (replace the `actions` block at L984–1022)
- Test: `tests/Unit/Site/Actions/ActionIdTest.php`, `tests/Unit/Site/Actions/ActionSettingsTest.php`

**Interfaces:**
- Produces: `ActionId::isValid(string $id): bool`, `ActionId::kind(string $id): ?string` (the prefix), `ActionId::PATTERN` (regex string without delimiters), `ActionId::KINDS = ['page','platform','item','category']`.
- Produces: `ActionSettings` value object: `public static function fromSite(?Site $site): self`; `public readonly string $mode`; `public readonly array $slots` (list of `['position'=>int,'id'=>string]` sorted by position); `public function poolMode(string $pool): string`; `public static function poolModes(?Site $site): array`; consts `MODES = ['newest','smart','manual']`, `DEFAULT_MODE = 'newest'`, `POOL_ORDER_KEYS = ['watch','listen','media','services','shop','custom_links','menus']`.
- Produces config: `partna.actions.slots` (10), `partna.actions.prior_k` (25), `partna.actions.default_prior` (0.03), `partna.actions.priors` keyed by kind and by page id (`page:services 0.28, page:reservations 0.30, page:menu 0.28, page:shop 0.15, page:events 0.14, page:contact 0.12, platform 0.05, item 0.03, category 0.04`), `partna.actions.weights` (`demand 0.45, reach 0.30, fresh 0.25`), `partna.actions.freshness_half_life_days` (14).

- [ ] **Step 1: Write failing tests**

```php
// tests/Unit/Site/Actions/ActionIdTest.php
<?php
use App\Site\Actions\ActionId;

it('accepts the four kinds and rejects legacy shapes', function () {
    expect(ActionId::isValid('page:services'))->toBeTrue()
        ->and(ActionId::isValid('platform:instagram'))->toBeTrue()
        ->and(ActionId::isValid('item:0b1e6b2e-2f6f-4c0e-9e4a-1d3a2c7e9f10'))->toBeTrue()
        ->and(ActionId::isValid('category:menu-cat-1'))->toBeTrue()
        ->and(ActionId::isValid('instagram'))->toBeFalse()
        ->and(ActionId::isValid('ordering:abc'))->toBeFalse()
        ->and(ActionId::isValid('custom:https://x'))->toBeFalse()
        ->and(ActionId::isValid('page:'))->toBeFalse()
        ->and(ActionId::isValid('page:'.str_repeat('a', 161)))->toBeFalse();
});

it('reports the kind prefix', function () {
    expect(ActionId::kind('platform:tiktok'))->toBe('platform')
        ->and(ActionId::kind('nope'))->toBeNull();
});
```

```php
// tests/Unit/Site/Actions/ActionSettingsTest.php
<?php
use App\Models\Core\Site\Site;
use App\Site\Actions\ActionSettings;

it('defaults to newest with no slots', function () {
    $s = ActionSettings::fromSite(null);
    expect($s->mode)->toBe('newest')->and($s->slots)->toBe([]);
    $s = ActionSettings::fromSite(new Site(['settings' => ['actions' => ['mode' => 'bogus']]]));
    expect($s->mode)->toBe('newest');
});

it('reads mode and sorts slots by position, dropping malformed rows', function () {
    $s = ActionSettings::fromSite(new Site(['settings' => ['actions' => [
        'mode' => 'smart',
        'slots' => [['position' => 3, 'id' => 'item:b'], ['position' => 0, 'id' => 'page:services'], ['id' => 'x'], 'junk'],
    ]]]));
    expect($s->mode)->toBe('smart')
        ->and($s->slots)->toBe([['position' => 0, 'id' => 'page:services'], ['position' => 3, 'id' => 'item:b']]);
});

it('reads pool modes sparsely with newest default and events always newest-ignored', function () {
    $site = new Site(['settings' => ['pool_order' => ['watch' => 'smart', 'events' => 'smart', 'bogus' => 'manual']]]);
    expect(ActionSettings::poolModes($site))->toBe(['watch' => 'smart'])
        ->and(ActionSettings::fromSite($site)->poolMode('watch'))->toBe('smart')
        ->and(ActionSettings::fromSite($site)->poolMode('listen'))->toBe('newest');
});
```

- [ ] **Step 2: Run** `vendor/bin/pest tests/Unit/Site/Actions` → FAIL (class not found).

- [ ] **Step 3: Implement**

```php
// app/Site/Actions/ActionId.php
<?php
namespace App\Site\Actions;

/** The action id grammar — the analytics key and the settings ref. */
final class ActionId
{
    public const KINDS = ['page', 'platform', 'item', 'category'];
    public const PATTERN = '^(page|platform|item|category):[A-Za-z0-9_.:\/-]{1,160}$';

    public static function isValid(string $id): bool
    {
        return preg_match('/'.self::PATTERN.'/', $id) === 1;
    }

    public static function kind(string $id): ?string
    {
        return self::isValid($id) ? substr($id, 0, (int) strpos($id, ':')) : null;
    }
}
```

```php
// app/Site/Actions/ActionSettings.php
<?php
namespace App\Site\Actions;

use App\Models\Core\Site\Site;

final class ActionSettings
{
    public const MODES = ['newest', 'smart', 'manual'];
    public const DEFAULT_MODE = 'newest';
    public const POOL_ORDER_KEYS = ['watch', 'listen', 'media', 'services', 'shop', 'custom_links', 'menus'];

    /** @param list<array{position:int,id:string}> $slots */
    private function __construct(
        public readonly string $mode,
        public readonly array $slots,
        private readonly array $poolModes,
    ) {}

    public static function fromSite(?Site $site): self
    {
        $settings = is_array($site?->settings) ? $site->settings : [];
        $actions = is_array($settings['actions'] ?? null) ? $settings['actions'] : [];
        $mode = in_array($actions['mode'] ?? null, self::MODES, true) ? $actions['mode'] : self::DEFAULT_MODE;
        $slots = [];
        foreach (is_array($actions['slots'] ?? null) ? $actions['slots'] : [] as $slot) {
            if (! is_array($slot) || ! is_int($slot['position'] ?? null) || ! is_string($slot['id'] ?? null) || ! ActionId::isValid($slot['id'])) {
                continue;
            }
            $slots[] = ['position' => $slot['position'], 'id' => $slot['id']];
        }
        usort($slots, fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return new self($mode, $slots, self::poolModes($site));
    }

    /** @return array<string,string> sparse pool => mode */
    public static function poolModes(?Site $site): array
    {
        $settings = is_array($site?->settings) ? $site->settings : [];
        $raw = is_array($settings['pool_order'] ?? null) ? $settings['pool_order'] : [];
        $out = [];
        foreach ($raw as $pool => $mode) {
            if (in_array($pool, self::POOL_ORDER_KEYS, true) && in_array($mode, self::MODES, true)) {
                $out[$pool] = $mode;
            }
        }
        return $out;
    }

    public function poolMode(string $pool): string
    {
        return $this->poolModes[$pool] ?? self::DEFAULT_MODE;
    }
}
```

Config block (replace the whole `'actions' => [...]` in `config/partna.php`):

```php
'actions' => [
    'slots' => (int) env('PARTNA_ACTIONS_SLOTS', 10),
    'prior_k' => (int) env('PARTNA_ACTIONS_PRIOR_K', 25),
    'default_prior' => 0.03,
    'priors' => [
        'page:services' => 0.28, 'page:reservations' => 0.30, 'page:menu' => 0.28,
        'page:shop' => 0.15, 'page:events' => 0.14, 'page:contact' => 0.12,
        'platform' => 0.05, 'item' => 0.03, 'category' => 0.04,
    ],
    'weights' => ['demand' => 0.45, 'reach' => 0.30, 'fresh' => 0.25],
    'freshness_half_life_days' => 14.0,
],
```

- [ ] **Step 4: Run** the two test files → PASS. `vendor/bin/pint app/Site/Actions config/partna.php tests/Unit/Site/Actions`.
- [ ] **Step 5: Commit** `feat(actions): action id grammar, settings reader, config (A1)`.

### Task A2: `PoolWire` — extract the shared pool hydration

**Files:**
- Create: `app/Site/Pools/PoolWire.php`
- Modify: `app/Services/PublicSite/IndividualProfilePayloadBuilder.php` (`buildPools` L249 → delegate)
- Test: `tests/Feature/Api/PublicSite/PoolDegradedBuildTest.php` (existing; must still pass unchanged)

**Interfaces:**
- Produces: `PoolWire::forSite(?Site $site): array` — `array<poolKey, array{selection: list<item>, collections: array, ...}>`, identical output to today's `buildPools`, same per-pool `QueryException` degradation (`markDegraded`). Constructor: `__construct(PoolResolver $pools, SitepageDataResolverService $resolver)`.

- [ ] **Step 1:** Move the body of `IndividualProfilePayloadBuilder::buildPools` into `PoolWire::forSite` verbatim (including the degraded marking) and make `buildPools` a one-line delegate `return $this->poolWire->forSite($site);` with `PoolWire` injected. Keep `'pools' => $this->buildPools($site)` at L139.
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Api/PublicSite tests/Feature/Resources` → PASS.
- [ ] **Step 3: Commit** `refactor(pools): PoolWire — one pool hydration for payload, actions and scoring (A2)`.

### Task A3: `ActionCandidates` — derive the candidate set

**Files:**
- Create: `app/Site/Actions/ActionCandidates.php`, `app/Site/Actions/ConnectionProfileUrl.php`
- Modify: `app/Services/Platforms/Registry/PlatformDescriptor.php` (add `destination(bool $on = true): static`, `isDestination(): bool`)
- Modify: `app/Providers/PlatformRegistryServiceProvider.php` (add `->destination()` to every social / music / video / podcast / creator / community / ordering platform; NOT booking, store, ticketing, menu, review, google-business)
- Test: `tests/Unit/Site/Actions/ActionCandidatesPoolsTest.php` (pure: `fromPools`), `tests/Feature/Site/Actions/ActionCandidatesTest.php` (feature: fold rule, destination, source fallback)

**Interfaces:**
- Produces: `ActionCandidates::forSite(User $pro, Site $site, ?Collection $sections = null, ?array $pools = null): list<array>` where each candidate is
  `['id'=>string,'kind'=>string,'label'=>string,'url'=>string,'thumb'=>?string,'connectedAt'=>?string(ISO),'ref'=>?array{pool:string,itemId:string},'meta'=>array]`. `meta` carries `pageId` | `platformKey` | `pool` (+ `collectionId`, `itemIds` for category).
- Produces: `ActionCandidates::fromPools(array $pools): list<array>` — pure item/category derivation from the `PoolWire` map (lifted from `ItemFeedService::candidates` + category homing; `reviews` excluded; `events` included).
- Produces: `ConnectionProfileUrl::for(IntegrationConnection $conn): ?string` (the `platformConnectionUrl` match lifted from `SiteActionsService`, through `UrlSafety::safeHref`).
- Labels: page labels map inside `ActionCandidates::PAGE_LABELS = ['services'=>'Book','reservations'=>'Reserve','menu'=>'Menu','shop'=>'Shop','events'=>'Events','contact'=>'Contact']`; platform label = `PlatformDescriptor::label()`; item label = wire `headline`; category label = collection `name`.
- Item url: wire item `url` (outbound) when present, else `'/'.$pageForPool.'#'.$itemId` where pageForPool = `SitepageId::SECTION_KEY_TO_PAGE[$pool]` (`custom_links`→`links`, `menus`→`menu`, `shop`→`shop`…). Item thumb: wire `image` (the first frame / cover). Read `PoolResolver::ITEM_KEYS` to take the exact key names when implementing.

- [ ] **Step 1: Write the pure test** (`fromPools`): fixture pools map with `watch` (2 videos, `publishedAt` set), `menus` (3 dishes: two in a provider-null category, one only in a provider-bearing collection), `reviews` (must be ignored), `services` (one uncategorised). Assert: ids are `item:<id>` / `category:<cid>`, the provider-null category homes its two dishes (`meta.itemIds` in pool order), the provider-only dish floats as an item, reviews yield nothing, `connectedAt` = `publishedAt ?? firstSeenAt`, `ref` = `{pool,itemId}` for items and null for categories.
- [ ] **Step 2: Write the feature test** using `createTenant()`: seed an active `fresha` connection + present services page → candidates contain `page:services` and **no** `platform:fresha`; seed `instagram` connection with payload `{username:'x'}` → `platform:instagram` with url `https://www.instagram.com/x`; seed `youtube` → `platform:youtube` present; seed `fresha` with services page absent (use the `IncompleteBookingPagePresenceTest` setup as the pattern) → `platform:fresha` with the booking url; assert every candidate id passes `ActionId::isValid` and every url passes `UrlSafety::safeHref`.
- [ ] **Step 3: Run** both → FAIL.
- [ ] **Step 4: Implement.** `forSite`: `$sections ??= $resolver->loadSections($site)`; `$caps = AccountCapabilities::for($pro)`; `$present = array_flip($resolver->presentPageIds($site,$caps,$sections))`; page arm for the six ids with `connectedAt` = newest active connection whose descriptor category maps to that page (use `SitepageId::SECTION_KEY_TO_PAGE` via the connection's `surface_key`/platform; fall back to the site's `created_at`); platform arm: iterate active connections, `$d = $registry->forConnection($conn)`; if `$d->isDestination()` → `platform:<key>` with `ConnectionProfileUrl::for($conn)`; else if its page is absent and `ConnectionProfileUrl::for($conn)` is non-null → source fallback `platform:<key>`; one entry per platform key (earliest `created_at` wins, as today's `earliestConnection`); item arm: `$pools ??= $poolWire->forSite($site)` wrapped in try/catch `QueryException` → `[]`, then `fromPools`. Every url through `UrlSafety::safeHref`; drop on null.
- [ ] **Step 5: Run** → PASS. pint. Commit `feat(actions): ActionCandidates — pages, destination platforms, served items, categories (A3)`.

### Task A4: `ActionSlots` — pure resolver

**Files:**
- Create: `app/Site/Actions/ActionSlots.php`
- Test: `tests/Unit/Site/Actions/ActionSlotsTest.php`

**Interfaces:**
- Produces: `ActionSlots::resolve(array $candidates, array $scores, ActionSettings $settings, ?int $limit = null): array{entries: list<Entry>, unavailable: list<string>}` where `Entry = ['position'=>int,'id','kind','label','url','thumb','locked'=>bool,'ref']` and `$scores` is `array<id, float>`. `$limit` defaults to `config('partna.actions.slots')`.
- Ordering: newest = `connectedAt` desc nulls last, id asc; smart = score desc (scored first), then newest order.

- [ ] **Step 1: Tests** (fixtures: 12 candidates `c0..c11` with varied `connectedAt`, scores for half):
  - newest orders by connectedAt desc, nulls last, caps at 10, positions 0..9 contiguous;
  - smart orders scored-desc then newest, unscored trail;
  - a lock at position 2 holds there in both modes and is not duplicated from the ranking;
  - a lock at position 9 with only 5 candidates: positions 0..4 filled, lock lands at 5 (positions renumber contiguously — the spec's "positions contiguous from 0");
  - an unavailable lock id is skipped and reported in `unavailable`;
  - manual: only slots, in position order, nothing auto-filled, missing ids shorten the list, every entry `locked: true`;
  - manual with zero slots → `entries: []`;
  - limit respected after locks.
- [ ] **Step 2:** FAIL. **Step 3: Implement** per spec §5.2; renumber `position` after assembly. **Step 4:** PASS, pint. Commit `feat(actions): ActionSlots — pure slot resolver with locks (A4)`.

### Task A5: Score reader + `ActionScorer` + job wiring + migration + lockstep

**Files:**
- Create: `app/Services/Analytics/ActionScorer.php`
- Modify: `app/Services/Analytics/ContentPopularityReader.php` (add `actionScoresForSite(?string $siteId): array<id,float>`, `itemScoresForSite(?string $siteId): array<key,float>` lifted from the feed branch, `pageRanksFromActions(?string $siteId): array<pageId,int>`)
- Modify: `app/Services/Analytics/ContentFreshness.php` (add `public function factor(Carbon $at, Carbon $now): float` = `2 ** (-ageDays / config half-life)`; remove the `page` family from `boostsForSite`)
- Modify: `app/Console/Commands/ComputeContentPopularityScores.php` (`computeActions` uses `ActionCandidates::forSite` + `ActionScorer`; delete `aggregatePages` and the page family; docblock)
- Delete: `app/Services/Analytics/RankedActionsComputer.php`
- Create: `supabase/migrations/20260823100000_unified_actions.sql`
- Modify: `tests/Feature/Database/ConstraintVocabularyLockstepTest.php` (expected set without `page`; read the new migration file; app list `= [...ItemSeenRequest::ITEM_TYPES, ActionScorer::CONTENT_TYPE]`)
- Test: `tests/Feature/Analytics/ActionScorerTest.php` (replaces `RankedActionsComputeTest.php`), update `ComputePopularityScoresTest.php` (page family gone)

**Interfaces:**
- Produces: `ActionScorer::computeForSite(Site $site, array $candidates, array $itemScores): array{rows: list<row>, deletes: list<string>}` — rows `{id, site_id, content_type:'action', content_key, score, rank, computed_at}`; formula per spec §6; `CONTENT_TYPE = 'action'`.
- Migration: drop + recreate `content_popularity_scores_content_type_check` without `page`; `DELETE … WHERE content_type IN ('page','action')`; `DELETE FROM analytics.action_events` (old vocabulary keys); `UPDATE site.sites SET settings = settings - 'smart_actions' - 'manual_actions' - 'manual_order_pools'`.

- [ ] **Step 1: Tests.** `ActionScorerTest`: (a) cold start — no events → score = prior + freshness, `page:services` outranks `platform:instagram` outranks an old item, a 2-day-old item outranks a 60-day-old platform with equal prior weight (freshness term); (b) taps raise demand — seed `action_events` seen/tap for an item id → it overtakes; (c) stale keys deleted; (d) hysteresis — a 5% better newcomer does not overtake an incumbent rank. Lockstep test updated. `ComputePopularityScoresTest`: page rows no longer written; action rows written for a seeded site.
- [ ] **Step 2:** FAIL. **Step 3: Implement** — lift `aggregate/dayWeight/dayBucketExpr/previousRows/rankWithHysteresis` from `RankedActionsComputer` into `ActionScorer`; compute `demandRate`, `reach` (decayed taps + `itemScores[itemId]` for item/category kinds, normalized by site max, 0 when max is 0), `freshness` (`ContentFreshness::factor(connectedAt)`), `prior` (`priors[id] ?? priors[kind] ?? default_prior`); weights from config; blend + hysteresis. Write the migration with the ROLLBACK comment. Delete `RankedActionsComputer` and its test.
- [ ] **Step 4:** PASS, pint, phpstan. Commit `feat(analytics): ActionScorer composite score; page family removed; unified-actions migration (A5)`.

### Task A6: Settings validation + write path + cache bust

**Files:**
- Modify: `app/Http/Requests/Concerns/SiteOrderingValidationRules.php` (remove `POOL_KEYS`, `LEGACY_BUTTON_REF_TO_ACTION_ID`, `manual_actions`/`smart_actions`/`manual_order_pools` rules and the action normalizers; add `settings.actions` + `settings.pool_order` rules; keep `smart_page_order`/`manual_page_order` + `normalizeOrderingPageIds` for page ids only)
- Modify: `app/Services/Site/UpdateSiteAction.php` (`LIST_SETTINGS_KEYS = ['manual_page_order', 'actions', 'pool_order']`)
- Modify: `app/Models/Core/Site/Site.php` docblock (known keys: `privacy, manual_page_order, actions, pool_order`)
- Test: `tests/Feature/Api/User/SiteManagement/ActionSettingsValidationTest.php` (rewrite), `tests/Feature/Api/Staff/UserSiteManagement/StaffUpdateSiteValidationTest.php` (update), `tests/Feature/Cache/DesignKitCacheInvalidationTest.php` (actions write busts)

**Rules to add:**

```php
'settings.actions' => ['sometimes', 'array:mode,slots'],
'settings.actions.mode' => ['required_with:settings.actions', Rule::in(ActionSettings::MODES)],
'settings.actions.slots' => ['sometimes', 'array', 'max:'.config('partna.actions.slots'), $this->slotsShapeRule()],
'settings.actions.slots.*' => ['array:position,id'],
'settings.actions.slots.*.position' => ['required', 'integer', 'min:0', 'max:'.(config('partna.actions.slots') - 1), 'distinct'],
'settings.actions.slots.*.id' => ['required', 'string', 'regex:/'.ActionId::PATTERN.'/', 'distinct'],
'settings.pool_order' => ['sometimes', 'array', $this->poolOrderKeysRule()],
'settings.pool_order.*' => [Rule::in(ActionSettings::MODES)],
```
`slotsShapeRule()`: when `settings.actions.mode === 'manual'`, positions must be exactly `0..n-1` (fail: "Manual slots must be contiguous from 0."). `poolOrderKeysRule()`: every key ∈ `ActionSettings::POOL_ORDER_KEYS` (fail names the key).

- [ ] **Step 1: Tests** — accepts + persists `actions` and `pool_order`; atomic replace (a second PATCH with fewer slots replaces, `[]` clears); 422 on bad mode, duplicate positions, duplicate ids, non-grammar id, position 10, manual non-contiguous, `pool_order.events`, `pool_order.watch = 'bogus'`; legacy keys `smart_actions`/`manual_actions`/`manual_order_pools` are now **rejected as unknown** (422 — assert the response names the key, matching how the request handles unknown settings keys today; if unknown keys are silently dropped rather than rejected, assert they are absent after write); staff endpoint mirrors; a write to `settings.actions` bumps `site.sites.updated_at`.
- [ ] **Step 2:** FAIL. **Step 3: Implement.** **Step 4:** PASS, pint, phpstan. Commit `feat(settings): actions + pool_order validation, atomic replace; legacy ordering keys gone (A6)`.

### Task A7: Public wire `actions` beside the legacy keys + dashboard endpoint

**Files:**
- Modify: `app/Services/PublicSite/IndividualProfilePayloadBuilder.php` (compute `actions` via `ActionCandidates` → `ContentPopularityReader::actionScoresForSite` → `ActionSlots`; pass `'actions' => ['mode'=>…,'entries'=>…]`; `pageOrder` smart path takes `pageRanksFromActions`)
- Modify: `app/Http/Resources/PublicSite/IndividualProfileResource.php` (emit top-level `actions` after `pageOrder`; `rankedActions`/`ordering` stay for this task)
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserSiteActionsController.php` (return `{mode, slots (with unavailable), entries, candidates (with score, scoreShare, connectedAt)}`; drop `pool`/`rankedActions`/`ordering` keys)
- Modify: `app/Http/Requests/Api/PublicSite/Analytics/ActionSeenRequest.php`, `ActionTapRequest.php` (`action_id` rule → `regex:/ActionId::PATTERN/`)
- Test: `tests/Feature/Resources/IndividualProfileResourceTest.php` (key set `profile, pageOrder, actions, rankedActions, ordering, …`), `tests/Feature/PublicSite/ProfileActionsTest.php` (new: wire shape, ≤10, refs resolve, locks honoured, manual, unavailable skip, `actions` present with `entries: []` on an empty site), `tests/Feature/Api/User/SiteManagement/UserSiteActionsEndpointTest.php` (rewrite: shape + the no-drift pin: public `entries` ≡ endpoint `entries` for one seeded state), `tests/Feature/Analytics/ActionBeaconsTest.php` (new grammar accepted, legacy ids 422)

- [ ] **Step 1: Tests.** **Step 2:** FAIL. **Step 3: Implement.** **Step 4:** PASS; run `tests/Feature/PublicSite tests/Feature/Api tests/Feature/Resources tests/Feature/Analytics`; pint; phpstan. Commit `feat(api): profile.actions on the public wire (beside rankedActions for one deploy) + GET /api/site/actions (A7)`.

### Task A8: Pool ordering modes in `PoolResolver`

**Files:**
- Create: `app/Site/Pools/PoolOrdering.php` (pure: `order(string $mode, list<item> $items, array $itemScores): list<item>`; `orderCollections(string $mode, array $collections, list<item> $items): array` — rewrites `position`)
- Modify: `app/Site/Pools/PoolResolver.php::resolve` (after payloads: `$mode = $pool === 'events' ? 'manual' : ActionSettings::fromSite($site)->poolMode($pool)`; apply `PoolOrdering`; `popularityRank` now emitted for every item from `itemScoresForSite` rank, not only products)
- Test: `tests/Unit/Site/Pools/PoolOrderingTest.php` (pure), `tests/Feature/Site/PoolOrderModesTest.php` (seeded pool: newest reorders pins, smart uses scores, manual = pins-then-rule, events ignores mode, menu categories reorder by newest member)

- [ ] **Step 1: Tests. Step 2: FAIL. Step 3: Implement. Step 4:** PASS + `tests/Feature/Site tests/Feature/Api/PublicSite`; pint; phpstan. Commit `feat(pools): per-pool newest/smart/manual order on the wire; events exempt (A8)`.

### Task A9: Docs + full suite + push (deploy 1)

- [ ] Update `docs/api.md`: `GET /api/public/profiles/{handle}` → `actions`; `GET /api/site/actions` new shape; `PATCH /api/site` settings `actions`/`pool_order`; mark `rankedActions`/`ordering` "removed after pages switch (A11)".
- [ ] `COMPOSER_PROCESS_TIMEOUT=0 composer test` → 0 failures; `vendor/bin/pint --test`; phpstan.
- [ ] Commit `docs(api): unified actions wire`, push branch, open PR against `development` titled `feat(actions): unified actions + content ordering (deploy 1 of 2)`. Close PR #296 with a comment linking the spec.

### Task A11 (after Lane B ships): Legacy removal (deploy 2)

**Files:** Delete `app/Services/PublicSite/Actions/ActionVocabulary.php`, `app/Services/PublicSite/SiteActionsService.php`, `tests/Unit/Actions/ActionVocabularyTest.php`, `tests/Feature/PublicSite/ActionCatalogTest.php`, `tests/Feature/PublicSite/ProfileRankedActionsTest.php`. Remove `rankedActions`/`ordering` from the builder and resource; remove `orderingWire`-era helpers; `NormalizesSiteUpdateInput` keeps only page-id normalization; `config/checkpoint.php` + any remaining grep hit for `ActionVocabulary|SiteActionsService|RankedActionsComputer|rankedActions|smart_actions|manual_actions|manual_order_pools` must be zero in `app config routes tests`.

- [ ] `grep -rn "ActionVocabulary\|SiteActionsService\|RankedActionsComputer\|rankedActions\|smart_actions\|manual_actions\|manual_order_pools" app config routes tests database` → empty.
- [ ] Full suite green; pint; phpstan. Commit `refactor(actions)!: remove rankedActions, SiteActionsService, ActionVocabulary — the unified list is the only actions surface (A11)`. Push.

---

## Lane B — apps/pages (monorepo)

### Task B1: Wire types + resolution

**Files:**
- Modify: `apps/pages/src/lib/fetch-profile.ts` (replace `RankedActionWire`/`SiteOrdering`/`DEFAULT_ORDERING`/`parseRankedActions`/`parseOrdering` with `ActionEntryWire = {position:number; id:string; kind:'page'|'platform'|'item'|'category'; label:string; url:string; thumb:string|null; locked:boolean; ref:{pool:string;itemId:string}|null}` and `SiteActionsWire = {mode:'newest'|'smart'|'manual'; entries: ActionEntryWire[]}`; `parseActions(raw): SiteActionsWire` defaulting to `{mode:'newest', entries:[]}`; `ArchitectureProps.actions?: SiteActionsWire`; drop `rankedActions`/`ordering`)
- Modify: `apps/pages/src/content/types.ts` (`ResolvedAction = {id:string; kind:'page'|'platform'|'item'|'category'; label:string; href:string; thumb:string|null; pageId:SitepageId|null; pool:string|null; locked:boolean}`; `SiteContent.actions: ResolvedAction[]`, `SiteContent.actionsMode`; drop `rankedActions`, `ordering`)
- Modify: `apps/pages/src/content/actions.ts` (delete `ACTION_IDS`, `ACTION_FAMILIES`, `LANDER_ACTION_COUNT`, `topActions`; `resolveActions(wire: readonly ActionEntryWire[], {pages}): ResolvedAction[]` — server order authoritative; page kind → href from `pagePath(pageId)` where `pageId = id.slice('page:'.length)` and the page must be present, else drop; other kinds → `url` through the http(s) gate, else drop)
- Modify: `apps/pages/src/content/resolve-site-content.ts` (`actions` in, `resolveActions` at L332, `actions`/`actionsMode` onto `SiteContent` at L426)
- Modify: `apps/pages/src/analytics/dom-contract.ts` (`actionAttrs` takes `{id, href}` — id is always non-blank now)
- Modify: `apps/pages/src/pages/[...path].astro` L284–287: header nav derives from `pageOrder` ∩ present pages (not from actions) — pages like Listen/Watch must stay in the nav.
- Test: `apps/pages/test/actions.test.ts` (rewrite: resolution per kind, http gate, page-absent drop, order preserved; delete the lockstep suite)

- [ ] Steps: write tests → `npm run test -w apps/pages` FAIL → implement → PASS → `npm run typecheck -w apps/pages` → commit `feat(pages): consume profile.actions; vocabulary lockstep deleted (B1)`.

### Task B2: The action stack on the lander

**Files:**
- Create: `apps/pages/src/components/blocks/ActionStack.astro` (props `{actions: ResolvedAction[]; class?: string}`; one `ActionRow` per entry: leading `thumb` (`img`, 3:2 → square crop via `--dk-*` radius tokens) when present, label, secondary line = pool/page name, trailing arrow; anchors carry `actionAttrs(action)` so seen/tap beacons fire with the new ids; tokens-only CSS)
- Modify: `apps/pages/src/pages/[...path].astro`: on the home page render `<ActionStack actions={content.actions} />` beneath the header/identity block; remove the Fresha-only `keyAction` CTA (`:418`, `:689–695`) — `page:services` in the stack replaces it.
- Modify: `apps/pages/src/pages/dev/showcase.astro` — add an `ActionStack` fixture with 10 mixed entries.

- [ ] Implement; `npm run typecheck`; `bash scripts/tokens-only-audit.sh`; verify in the Browser pane at desktop and 375px (`preview_start` name for pages), read computed styles for row height/gap; screenshot; commit `feat(pages): ActionStack — the Linktree-style lander list (B2)`. Deploy via `npm run deploy` on `/ship` only.

---

## Lane C — apps/dashboard (monorepo)

### Task C1: Wire types + queries

**Files:**
- Create: `apps/dashboard/lib/queries/site-actions.ts` — `fetchSiteActions(): Promise<SiteActionsRead>`, `patchSiteActions(settings: {actions?: ActionSettingsWire; pool_order?: Record<PoolKey, PoolMode>}): Promise<void>` (PATCH `/site` with `{settings}`), types `ActionMode`, `ActionEntry`, `ActionCandidate`, `ActionSlot`, `SiteActionsRead = {mode; slots: {position; id; unavailable}[]; entries; candidates}`, `useSiteActions()` (`queryKey: ["site-actions"]`).
- Modify: `apps/dashboard/lib/queries/me.ts` (remove `manual_order_pools` L74/L146; add `pool_order?: Record<string,'newest'|'smart'|'manual'>` → `poolOrder`), `apps/dashboard/lib/data/user.ts` (`manualOrderPools` → `poolOrder: Record<string, PoolMode>` L69/L237), `apps/dashboard/lib/data/design.ts` (delete `smartActions` at L23–25 comment, L53, L65, L118, L180).
- Modify: `apps/dashboard/components/design/design-page.tsx` (L451–472 card → summary card, Task C4), `app/dev/proposal/pg-design/page.tsx` (drop its `smartActions` local state references).

- [ ] `npx tsc --noEmit` → 0; `npx eslint .` → 0; commit `feat(dashboard): site-actions queries; legacy ordering fields removed (C1)`.

### Task C2: `PoolOrderFieldset`

**Files:**
- Create: `apps/dashboard/components/blocks/pools/pool-order-fieldset.tsx` — `export function PoolOrderFieldset({pool}: {pool: 'watch'|'listen'|'media'|'services'|'shop'|'custom_links'|'menus'})`; reads `useMe()`'s `site.poolOrder[pool] ?? 'newest'` into `useDraft({mode})`; `SettingFieldset` title "How this pool is ordered" with an `IconTooltip` (`tapToOpen`) on the title carrying the long explanation; `ChoiceRows` options: Newest ("Most recently added or published first"), Smart ("Ranked by what visitors open, then by how new it is"), Manual ("Your pinned order first, then the rest"); `onSave` → `patchSiteActions({pool_order: {[pool]: mode}})`, invalidate `["me"]`, `notify.saved("…")`.
- Modify: the seven pool pages (`custom-links-page.tsx`, `services-page.tsx`, `menus-page.tsx`, `watch-page.tsx`, `listen-page.tsx`, `media-page.tsx`, `shop-page.tsx`) — pass `trail={<PoolOrderFieldset pool="…" />}` (Sell stacks it above `ShopLinkModeFieldset` in a fragment). Events/reviews untouched.

- [ ] tsc + eslint; Browser pane: `/watch` at desktop + 375px, save round-trip visible in `read_network_requests`; commit `feat(dashboard): PoolOrderFieldset on every ranked pool (C2)`.

### Task C3: `/actions` page

**Files:**
- Create: `apps/dashboard/app/(app)/actions/page.tsx` (7-line shim → `ActionsPage`, metadata "Actions · Partna")
- Create: `apps/dashboard/components/actions/actions-page.tsx` (`Page` + `PageTop`; section 1 `SettingFieldset` mode `ChoiceRows` with `IconTooltip` title; section 2 the slot table)
- Create: `apps/dashboard/components/actions/slot-table.tsx` — 10 rows from `entries` (manual: from `slots` + "Add" rows to 10); columns: position, kind glyph (`lib/icons`), label (`MiddleTruncate`), source (pool/platform name from `candidate.meta`), right column: `scoreShare` as `formatShare` in smart, `connectedAt` relative date in newest, nothing in manual; lock toggle (smart/newest); drag handle via `components/ui/sortable.tsx` (manual: all rows; smart/newest: locked rows only, drop sets the lock position); swap → `SwapPopover`; remove (manual). Unavailable locks: muted row + "No longer on your site — replace or remove".
- Create: `apps/dashboard/components/actions/swap-popover.tsx` — `Combobox` over `candidates` grouped by kind (Pages / Platforms / Items), search by label; pick → replace the row's id and lock it.
- State: `useDraft({mode, slots})`; switching to manual seeds `slots` from current `entries`; Save → `patchSiteActions({actions:{mode, slots}})` → invalidate `["site-actions"]` (the table re-renders from the live resolution); `notify.saved`.
- Modify (LAST): `apps/dashboard/lib/nav.ts` — add `{ title: "Actions", href: "/actions", icon: <pick from lib/icons>, capability: "canEditDesign" }` to the second group beside Design.

- [ ] tsc + eslint; Browser pane at desktop + 375px: mode switch, lock, swap, drag, save, refetch; no sideways scroll; screenshot; commit `feat(dashboard): /actions — mode, ten slots, locks, swap, drag (C3)`.

### Task C4: Design page summary card

- [ ] Replace the "Pick action buttons from performance" `StagedFieldset` (L451–472) with a `SettingFieldset` (no save) titled "Actions", subtitle from `useSiteActions()`: "`{Mode}` · `{entries.length}` live · `{locked}` locked", `actions={<Button asChild variant="outline" size="sm"><Link href="/actions">Manage</Link></Button>}`. Keep the page-order card.
- [ ] tsc + eslint; commit `feat(dashboard): design page links to /actions (C4)`.

---

## Self-review

- Spec coverage: §2 → A1/A3; §3 → A7/A11; §4 → A1/A6; §5 → A4/A8; §6 → A5; §7 → A7; §8.1 → C1–C4; §8.2 → B1–B2; §9 → A9/A11 + deploy order; §10 → A3 (fail-open), A4 (unavailable), A7; §11 → every task's tests.
- Types: `ActionSettings::fromSite`, `ActionCandidates::forSite/fromPools`, `ActionSlots::resolve`, `ActionScorer::computeForSite`, `PoolWire::forSite`, `PoolOrdering::order` are named identically across tasks.
- Open question resolved in-plan: header nav on pages moves to `pageOrder` (B1) because page actions no longer cover every page.
