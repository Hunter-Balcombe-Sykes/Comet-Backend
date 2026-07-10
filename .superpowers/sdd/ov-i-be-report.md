# OV-I-BE — Unified actions-scoring backend (plan + report)

Branch `tobias/ov-i-actions` off `origin/development`. Scope: backend only (Comet). Consumers: OV-I-PAGES (monorepo lander/resolver) + OV-I-FE (dashboard design controls) — see **Consumer contracts** below.

## Design

An **action** = `{kind: page|item|button}` + a kind-local `ref`. The layer sits ON TOP of the proven scoring system — page/item score math (`analytics:compute-popularity`) is untouched; buttons get their own native signal aggregate; the three families are made comparable by **within-kind normalization** then a blend with **kind/ref priors + recency**.

### Action pool (per site) — `App\Services\PublicSite\ActionPoolBuilder`

- **pages** — `SitepageDataResolverService::presentPageIds()` (the existing presence + Business gate) minus `home` (the lander IS home). ref = page-id.
- **buttons** — derived from the payload's existing platform-links source `SitepageDataResolverService::getLinks()` (+ synthesized booking row): rows with `category === 'booking'` OR (`platform !== null` AND `category !== 'custom'`). ref = platform slug (`'booking'` for the synthesized booking row). Custom-category links are excluded — they are already scored as `link_item` items. Dedupe by ref (first by sort_order wins). Generic by platform: instagram/youtube/socials/booking/uber-eats/doordash all flow through with zero per-platform code.
- **items** — top 2 per item-type from the existing `content_popularity_scores` item ranks, only for types with a lander-actionable hosting page (`ITEM_TYPE_TO_PAGE`: shop_product→shop, service→book, engine_item→events, listen_item→listen, watch_item→watch, link_item→links, gallery_item→gallery, menu_item/menu_category→menu) and only when that page is present. Labels: backend fills what it knows (service titles, link titles by url/id); other item labels are `null` on the wire — the sitepage app resolves them from its own resolved content (it already resolves popularity keys per item, e.g. shop keys by product handle).

### Scoring — `App\Services\Analytics\RankedActionsComputer`

Native signals (existing analytics only, no new tracking):
- page action native = this run's blended **page score** (from the popularity job).
- item action native = this run's blended **item score**.
- button action native = day-bucketed `analytics.link_clicks` where `platform = ref`, each day weighted `2^(-age_days/90)` — the same true-half-life form the page/item job uses. The synthesized booking button also matches its underlying platform slug (e.g. fresha) plus `'booking'`.

Comparable score:
```
norm      = kind_max > 0 ? native / kind_max : 0.0        # min-max within kind, floor 0
prior     = REF_PRIORS[kind:ref] ?? ITEM_TYPE_PRIORS[itemType] ?? KIND_PRIORS[kind]
recency   = 2^(-age_days/14)   # buttons: link-block created_at; pages/link_items: ContentFreshness boosts ÷ their weights; other items 0
raw       = 0.60*norm + 0.25*prior + 0.15*recency         # ∈ [0, ~1]
blended   = 0.7*raw + 0.3*prev_stored                      # anti-thrash, same constants as the popularity job
rank      = previous-rank seed + bubble swap only when beating the incumbent by >10% (same hysteresis pattern)
```
Cold start: zero events everywhere → `norm = 0` for all → ordering = priors + recency, so a brand-new site with connections still yields a full ranked list (6+ whenever the pool has 6+ actions). Key priors: `button:booking .95`, `button:uber-eats/.doordash .90`, `page:book .85`, `page:shop|menu .80`, `page:events .75`, `button:instagram .70`, `page:listen|watch .65`, `button:youtube .60`, … defaults `button .50 / page .50 / item .45`, item-type overrides `service|shop_product .55`, `engine_item .50`.

### Storage — the codebase's scoring-storage pattern, reused verbatim

Rows live in **`analytics.content_popularity_scores`** with `content_type = 'action'`, `content_key = '<kind>:<ref>'` (`page:book`, `button:instagram`, `item:service:<id>`), `score` = blended, `rank`, `computed_at`. Upserted by the SAME job/cadence (`analytics:compute-popularity`, every 15 min), stale action keys deleted each run. **No migration needed** — the table, unique key, index, RLS and grants already exist.

`ComputeContentPopularityScores` changes are additive only: `'action'` is excluded from the generic stored-type union (so the fade-out loop never touches it), and after the existing page/item computation the command calls the computer inside a per-site try/catch — an action-layer fault degrades to "no rankedActions refresh", never to broken page/item scores.

Read side: `ContentPopularityReader::rankedActionsForSite()` (new method, same fail-open posture) returns `[{key, score, rank}]` ordered by rank.

### Payload (public profile `GET /api/public/profiles/{handle}`)

Built in `IndividualProfilePayloadBuilder` / emitted by `IndividualProfileResource` — two new TOP-LEVEL keys (`rankedActions`, `ordering`), plus the smart/manual pageOrder behavior. Shapes under **Consumer contracts**.

- `rankedActions` smart path: stored `action` rows resolved against the live pool (dropped when no longer in pool); pool entries the job hasn't scored yet (e.g. connected < 15 min ago) are appended ordered by prior, `score: null`. Stored-empty (job never ran) → the whole pool prior-ordered.
- `rankedActions` manual path (`settings.smart_actions === false`): the user's `settings.manual_actions` resolved in order — known refs resolved against the pool (unknown refs dropped — curation semantics, missing actions are NOT appended), `custom` entries passed through as `{kind:'custom', label, url}`.
- `pageOrder` manual path (`settings.smart_page_order === false`): `settings.manual_page_order ∩ present` in manual order, then present pages missing from the manual list appended in canonical order (drop unknown, append missing — validated against live pages at read time). Direct-link hoist stays a pages-platform behavior; backend ships data only.

### Settings (site.sites.settings JSONB — no schema change)

Validated strictly in `UpdateSiteRequest` (shape below). `UpdateSiteAction` gets an atomic-replace guard for the two list-valued keys (`array_replace_recursive` would positionally merge lists — new shorter list would inherit old tail entries).

### Dashboard support endpoint

`GET /api/site/actions` (authed, same group as `GET /site`) → `{pool, rankedActions, ordering}` so the design-page controls can render the reorder lists without parsing the public payload.

## Files

| File | Change |
|---|---|
| `app/Services/PublicSite/ActionPoolBuilder.php` | NEW — pool derivation (pages/buttons/items) |
| `app/Services/Analytics/RankedActionsComputer.php` | NEW — normalize/prior/recency blend + hysteresis + row shaping |
| `app/Console/Commands/ComputeContentPopularityScores.php` | additive: exclude `action` type from generic loop; invoke computer per site (fail-open) |
| `app/Services/Analytics/ContentPopularityReader.php` | new `rankedActionsForSite()` |
| `app/Services/PublicSite/IndividualProfilePayloadBuilder.php` | rankedActions + ordering + manual pageOrder |
| `app/Http/Resources/PublicSite/IndividualProfileResource.php` | emit `rankedActions` + `ordering` |
| `app/Http/Requests/Api/User/Site/UpdateSiteRequest.php` | 4 settings rules + manual_actions deep validation |
| `app/Services/Site/UpdateSiteAction.php` | list-settings atomic replace |
| `app/Http/Controllers/Api/User/SiteManagement/UserSiteActionsController.php` | NEW — GET /api/site/actions |
| `routes/api/user.php` | 1 route |
| `docs/api.md` | payload keys + endpoint |
| `tests/Feature/Analytics/RankedActionsComputeTest.php` | NEW |
| `tests/Feature/PublicSite/ProfileRankedActionsTest.php` | NEW |
| `tests/Feature/Api/User/SiteManagement/ActionSettingsValidationTest.php` | NEW |

## Consumer contracts (OV-I-PAGES + OV-I-FE — verbatim shapes)

### 1. Public profile payload — new top-level keys

```jsonc
// GET /api/public/profiles/{handle} → data.rankedActions  (ALWAYS an array, ordered best-first;
// the lander renders the top 6. Already override-applied: when the owner turned smart actions
// off this IS their manual list — consumers never re-derive.)
"rankedActions": [
  {
    "kind": "page",            // "page" | "item" | "button" | "custom"
    "ref": "book",             // kind-local ref: page-id | "<itemType>:<itemKey>" | platform slug ("booking" = general booking) | null for custom
    "label": "Book",           // string | null — null only for kind=item the backend can't label (shop_product/listen_item/watch_item/engine_item/gallery_item): resolve from your own resolved content by itemType+itemKey (same keys as the popularity map)
    "url": null,               // string | null — outbound href (buttons + customs always have it)
    "pageId": "book",          // string | null — sitepage to deep-link when url is null (pages + items)
    "itemType": null,          // string | null — only for kind=item (service|shop_product|listen_item|watch_item|engine_item|link_item|gallery_item|menu_item|menu_category)
    "itemKey": null,           // string | null — only for kind=item; equals the popularity-map content_key for that type
    "score": 0.81              // number | null — blended comparable score; null = not yet scored (fresh pool entry) or manual/custom entry
  }
]

// data.ordering (ALWAYS an object; defaults applied server-side)
"ordering": {
  "smartPageOrder": true,      // bool — false ⇒ pageOrder already reflects manualPageOrder
  "manualPageOrder": [],       // list<page-id> — the stored preference, verbatim
  "smartActions": true,        // bool — false ⇒ rankedActions already reflects manualActions
  "manualActions": []          // stored preference, verbatim (entries as in §2)
}
```
`pageOrder` (existing top-level key) needs NO consumer change: manual order is baked in server-side.

### 2. Settings write — `PATCH /api/site`

```jsonc
{ "settings": {
  "smart_page_order": true,               // boolean (default true when absent)
  "manual_page_order": ["book", "shop"],  // list of taxonomy page-ids, distinct, max 16; unknown ids 422
  "smart_actions": true,                  // boolean (default true when absent)
  "manual_actions": [                     // max 12 entries, ordered; each ONE of:
    { "kind": "page",   "ref": "book" },                    // ref = taxonomy page-id
    { "kind": "item",   "ref": "service:<uuid>" },          // ref = "<itemType>:<itemKey>"
    { "kind": "button", "ref": "instagram" },               // ref = platform slug; "booking" = general booking link
    { "kind": "custom", "label": "Gift cards", "url": "https://…" }  // label 1–80, url http(s) ≤2048
  ]
}}
```
Strict: non-custom entries must NOT carry `label`/`url`; custom entries must NOT carry `ref`; refs are format-validated per kind (422 with per-field errors). Lists REPLACE atomically on write (no positional merge).

### 3. Dashboard picker data — `GET /api/site/actions` (authed)

```jsonc
// body is the object directly — no data envelope on this endpoint
{
  "pool":          [ /* every action available right now, same entry shape as rankedActions; score = stored score or null */ ],
  "rankedActions": [ /* what the sitepage currently shows (override-applied) */ ],
  "ordering":      { /* same object as payload */ }
}
```

## Status

- [x] Plan written
- [x] Implementation
- [x] Tests green (`composer test`) + pint
- [x] Commit + push + PR (no merge)

## Implementation report

**Status: COMPLETE.** Full suite green: **3433 passed, 119 skipped** (2 deprecated / 1 warning / 1 risky — pre-existing suite noise, exit 0), pint clean. **24 new Pest tests** across four files (6 compute + 5 payload + 11 settings-validation + 2 endpoint) + 1 existing compute test updated for the new layer (zero-signal sites now legitimately write cold-start action rows; the page/item assertion was scoped to non-action rows).

Decisions locked during implementation (all reflected above + in code comments):
- Storage reuses `analytics.content_popularity_scores` with `content_type='action'` — **zero migrations shipped**.
- The command's generic fade-out loop explicitly excludes `'action'`; the action layer owns its own delete set (stale keys removed every run).
- Per-site action computation is wrapped in try/catch (fail-open) so an action-layer bug can never break page/item score writes.
- Freshness boosts are SUBTRACTED from the native page/link_item term (they're represented by the blend's own recency term; leaving them in native would norm a brand-new zero-engagement page to 1.0 and outrank real engagement).
- Button recency anchors on the link block's `created_at` (booking: the booking section block's `created_at`).
- `rankedActions` in smart mode = stored ranks resolved against the live pool + unscored pool entries appended by prior (`score: null`) — a just-connected platform appears immediately, before the next 15-min compute tick.
- Manual actions: unknown refs dropped, nothing appended (curation semantics). Manual page order: unknown dropped, missing present pages appended canonically (reachability semantics).
- `ContentPopularityReader::forSite()` now excludes `'action'` rows so the payload's `popularity` map stays a pure content surface (regression-tested).

Concerns / follow-ups (also in PR body):
- Every published site now writes `action` rows each 15-min run (previously zero-signal sites skipped writes). Fine at current scale; fold into the existing "scope compute-popularity to recently-active sites" revisit noted in routes/console.php.
- Item labels for platform-sourced items (shop/listen/watch/events/gallery) are `null` on the wire by design — OV-I-PAGES must resolve them from resolved content by (itemType, itemKey) and skip entries it cannot resolve (contract §1).
- link_item keys: theme beacons key them by block id or destination url; label matching handles both. If OV-I-PAGES emits a third key shape for link items, the label falls back to null (harmless, but worth aligning).
- `StaffUpdateSiteRequest` is a SEPARATE allowlist (does not extend UpdateSiteRequest) — the four new settings keys are validated on the user endpoint only tonight. The staff endpoint's generic `settings => array` passthrough behavior is unchanged (pre-existing pattern). Follow-up if staff should manage ordering settings.
- `composer analyse` (PHPStan) is broken on origin/development independent of this branch — the baseline references deleted `app/Services/SmartLinks/*` files and errors at config load. My new files, checked standalone at level 5 + larastan, show only 4 findings, all the Eloquent magic-property class the baseline already carries 1055 of (same accesses as sibling services). Not introduced tech debt beyond the codebase's existing pattern; flagged for whoever repairs the baseline.
- Worktree note: the provisioned `vendor/` symlink made Pest resolve the project root into the main repo (tests couldn't run); replaced with a real `composer install` in this worktree (vendor/ is gitignored — no repo impact).
