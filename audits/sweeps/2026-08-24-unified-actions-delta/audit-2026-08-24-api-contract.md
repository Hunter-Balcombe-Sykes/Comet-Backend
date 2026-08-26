# API Contract & Resource Leakage Audit — 2026-08-24

**Branch:** development
**Lens:** API Contract & Resource Leakage: raw model fields bleeding through, over-fetching, inconsistent pagination
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Http/Controllers/Api/User/Analytics/DevInsightsController.php
- app/Http/Controllers/Api/User/SiteManagement/UserSiteActionsController.php
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Controllers/Api/Platforms/ShopController.php
- app/Http/Controllers/Api/Content/PoolController.php
- app/Http/Controllers/Api/Routing/RoutingController.php
- app/Http/Controllers/Api/Routing/SuggestionsController.php
- app/Site/Actions/ActionCandidates.php
- app/Site/Actions/ConnectionProfileUrl.php
- app/Site/Pools/PoolResolver.php
- app/Site/Pools/PoolWire.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/Site/UpdateSiteAction.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 1 of 8 complete

---

## P3 — Nice to have

- [ ] **#API-1** · P3 — `ActionCandidates::forSite()` selects four `IntegrationConnection` columns it never reads
    - **Where:** app/Site/Actions/ActionCandidates.php:96
    - **Affects:** The unified actions candidate build (dashboard `/site/actions` and the public payload's action list) — minor column over-transfer per connection row.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Trim the `get([...])` column list to `platform`, `routing_class`, `payload`, `created_at`.
        - Confirm no other reader of the returned `Collection` depends on `id`/`user_id`/`surface_key`/`resource_id` before trimming.
    - **Technical:** The loop in `forSite()` only reads `$conn->platform`, `$conn->routing_class`, `$conn->created_at`, and `$conn->payload` (via `PlatformRegistry::forConnection()` and `ConnectionProfileUrl::for()`, both verified to use only `platform`/`routing_class`/`payload`). `id`, `user_id`, `surface_key`, and `resource_id` are fetched but never referenced in this method.
    - **Plain English:** The code orders eight columns of data per connection but only ever looks at four of them. It's a small waste — like ordering a combo meal and throwing half of it away — worth trimming but not urgent.
    - **Evidence:**
        ```php
        ->get(['id', 'user_id', 'platform', 'surface_key', 'routing_class', 'resource_id', 'payload', 'created_at']);
        ```

- [ ] **#API-2** · P3 — `itemPayloads()` selects every `content.items` column on the shared public/dashboard hydration path
    - **Where:** app/Site/Pools/PoolResolver.php:562-567
    - **Affects:** Every public sitepage pool render and every dashboard pool read — the hottest content-read path per the platform's scale profile.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an explicit column list to the `content.items` query covering only what `itemPayloads()` actually reads downstream (`id`, `kind`, `user_id`, `removed_at`, `headline_cache`, and any other fields the assembly step touches).
        - Verify against `itemPayloads()`'s full body before trimming — several downstream branches read different item columns, so under-selecting will silently null a field instead of erroring.
    - **Technical:** `DB::table('content.items')->whereIn('id', $ids)->where('user_id', $site->user_id)->whereNull('removed_at')->get()` has no explicit column list, so every column on `content.items` is materialised for every item on every pool build. This function is the shared batch hydrate that the 2026-08-24 "plan → one shared hydrate → assemble" refactor (`f263a284a`, `a0739ffd6`) introduced to cut the pool-render query count from 244 to ~60 — an explicit select list is the natural next reduction on the same path and should be scoped to fit that batching model, not reopen it.
    - **Plain English:** Every time this reads an item's basic info, it grabs the entire record instead of just the few fields it actually uses — like photocopying someone's whole file instead of the one page you need.
    - **Evidence:**
        ```php
        $items = DB::connection('pgsql')->table('content.items')
            ->whereIn('id', $ids)
            ->where('user_id', $site->user_id)
            ->whereNull('removed_at')
            ->get()
            ->keyBy('id');
        ```

- [ ] **#API-3** · P3 — Routing-suggestions inbox hard-caps at 100 rows with no pagination or "more exist" signal
    - **Where:** app/Http/Controllers/Api/Routing/SuggestionsController.php:64-73, 130
    - **Affects:** Authenticated owners with more than 100 open routing suggestions — the oldest entries become permanently invisible with no client-visible indication they exist.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the fixed `->limit(100)` with `paginate()` (or an explicit `has_more` flag alongside the current `['suggestions' => ...]` shape, since this endpoint also folds in non-DB rows from `SyncFindingsBridge` and the synthetic Google-listing suggestion that a plain `paginate()` call can't merge automatically).
        - If a full paginator is too invasive for the folded/synthetic rows, at minimum surface whether the 100-row DB query was truncated so the client can render "showing your 100 most recent" rather than silently dropping the rest.
    - **Technical:** `index()` queries `routing.source_intents` with `->orderByDesc('first_seen_at')->limit(100)->get()`, then folds legacy `SyncFindingsBridge` findings and a synthetic Google-listing suggestion on top, and returns the whole set as `['suggestions' => $suggestions]` with no pagination metadata. A user who reconnects/rescans enough platforms to exceed 100 pending suggestions loses visibility into the oldest ones with no signal that anything was cut.
    - **Plain English:** The suggestions inbox only ever shows the newest 100 items and never tells you if there are more waiting behind them — like a notification list that quietly deletes anything past the 100th entry instead of showing a "see more" button.
    - **Evidence:**
        ```php
        $intents = DB::table('routing.source_intents')
            ->where('user_id', $user->id)
            ->whereIn('state', ['proposed', 'blocked'])
            ->orderByDesc('first_seen_at')
            ->limit(100)
            ->get();
        ```

- [ ] **#API-4** · P3 — Public profile payload exposes the internal `site_id` UUID and `accountType` to unauthenticated visitors
    - **Where:** app/Http/Resources/PublicSite/IndividualProfileResource.php:97-98
    - **Affects:** Every unauthenticated visitor to `GET /api/public/profiles/{handle}` — increases the correlation/enumeration surface of an otherwise-anonymous public page.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm no current frontend consumer keys off `profile.site_id` or `profile.accountType` (grep `partna-pages` / design-system before removing).
        - If unused by the renderer, drop both keys from the public payload; the class's own docblock already lists the PII fields it deliberately excludes, and these two internal fields were never added to that exclusion list.
        - If a client does need `site_id` (e.g. to scope a client-side action call), consider whether the public handle can serve the same purpose instead.
    - **Technical:** `IndividualProfileResource::toArray()` emits `'accountType' => $this->account_type?->value` and `'site_id' => $this->sections['site_id'] ?? null` unconditionally on the public surface. Neither is PII, but `site_id` is an internal Postgres UUID with no visitor-facing use documented anywhere in `IndividualProfilePayloadBuilder`, and `account_type` (`'partna'`/`'business'`) is an internal classification field the resource's own docblock explicitly excludes analogous internal fields from ("PII... Anything brand- or commerce-related"). This is hardening, not a live security defect — the risk is object/schema-detail leakage, not identity leakage.
    - **Plain English:** The public profile page currently ships two backend-only details to every visitor: the site's internal database ID and an internal account-type label. Neither reveals anything sensitive on its own, but it's like printing a warehouse shelf number on a customer receipt — visitors have no use for it, and every field that leaves the building is one more thing to think about if the shape ever needs to change.
    - **Evidence:**
        ```php
        'accountType' => $this->account_type?->value,
        'site_id' => $this->sections['site_id'] ?? null,
        ```

- [ ] **#API-5** · P3 — Public pool hydration fetches the full `site.platform_connections.payload` JSONB to extract one fallback URL
    - **Where:** app/Site/Pools/PoolResolver.php:763-786, 862-874
    - **Affects:** Public sitepage pool rendering — the hottest read path per the platform's scale profile; DB bandwidth/memory per connection payload blob.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - The same `$sourceRows` query result feeds two different audiences: `sourcesByItem` (dashboard-only "Sources" list, needs the full payload for `ConnectionDisplayName::for()`) and `sourcePlatforms` (the public fallback-URL derivation, which only reads `payload['url']`/`payload['selection']['url']`).
        - Do not blanket-truncate the SQL `payload` selection — that would break the dashboard-only `sourcesByItem` branch. Instead, split the read: keep the full-payload query for the dashboard path, and add a narrower `payload->>'url'`-only extraction (or reuse the already-hydrated row without re-selecting the JSONB) for the public-only `sourcePlatforms` derivation, gated the same way `DASHBOARD_ONLY_ITEM_KEYS` already gates the `sources` output field.
    - **Technical:** `sourceRows` selects `site.platform_connections.payload as payload` (full JSONB) for every relevant connection on every pool build, on both the dashboard and public paths, as part of the shared hydration the 2026-08-24 batching refactor (`f263a284a`) introduced. On the public path this payload is only ever used to pull a single fallback URL string (`$payload['url'] ?? $payload['selection']['url']`); the richer `sourcesByItem` structure it also feeds is dashboard-only and stripped by `PoolWire::forSite()` before the public response ships. A fix must account for both consumers of the same query, not just the public one.
    - **Plain English:** To find one link buried in a connection's stored data, the backend currently pulls the entire stored blob for every connection, on every public page load, then throws away everything except that one link. It's like photocopying an entire contact card just to read the phone number off it.
    - **Evidence:**
        ```php
        ->get([
            'content.source_items.item_id',
            'content.source_items.last_seen_at',
            'content.sources.kind as source_kind',
            'site.platform_connections.id as connection_id',
            'site.platform_connections.platform as platform',
            'site.platform_connections.surface_key as surface_key',
            'site.platform_connections.payload as payload',
            'site.platform_connections.is_active as is_active',
        ])
        ```
        ```php
        $url = $payload['url'] ?? ($payload['selection']['url'] ?? null);
        ```

- [ ] **#API-6** · P3 — Public pool hydration builds every pool's `library` selection (up to 500 items each) even though the public wire only ships `selection`
    - **Where:** app/Site/Pools/PoolWire.php:111-117, 184-195
    - **Affects:** Public sitepage pool rendering — the hottest read path per the platform's scale profile.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `PoolWire::forSite()`, restrict the id set batched into `hydrateItems()` to `selectionIds` for the public-response path; keep the current full `selectionIds + libraryIds` batch only for callers that actually consume `resolved['library']` (the dashboard pool-editor read).
        - Coordinate with the 2026-08-24 batched-hydrate refactor (`f263a284a`, `a0739ffd6`) rather than reverting it — the fix should narrow which ids enter that shared batch per caller, not reintroduce the pre-refactor per-pool query pattern it replaced.
    - **Technical:** `forSite()` pushes both `$plan['selectionIds']` and `$plan['libraryIds']` into the shared `$allIds` batch passed to `hydrateItems()`, so `PoolResolver::assemble()` builds a full `library` array (per its own docblock, up to 500 items per pool) for every pool on every call — including the public sitepage request, which this file's own docblock states plainly: "The library never ships publicly." The output map built here (`$out[$pool]['items']`) only ever reads `$resolved['selection']`; `$resolved['library']` is computed and discarded.
    - **Plain English:** To show a short public playlist, the backend currently fetches and prepares hundreds of items sitting in the private "unused" pile as well — then throws all of that away and keeps only the playlist. It should only fetch what's actually going to be shown.
    - **Evidence:**
        ```php
        foreach ($plans as $plan) {
            array_push($allIds, ...$plan['selectionIds'], ...$plan['libraryIds']);
        }
        // ...
        [$payloads, $stores] = $this->pools->hydrateItems($site, array_values(array_unique($allIds)));
        ```
        ```php
        * library never ships publicly; a pool with nothing selected is simply
        * absent.
        ```

- [x] **#API-7** · P3 — Public pool hydration runs a dashboard-only duplicate-detection query on every request
    - **Resolved 2026-08-26** — closed together with the remainder sweep's `SCALE-9`; they are ONE defect (same file, same lines, same remedy), and the pairing was only settled by `ad8922d15`, which is why this finding was an orphan assigned to no unit. `itemPayloads()` and `hydrateItems()` gained `bool $withDuplicateCandidates = true`, extending the `$withLibrary` audience-flag idiom already in this class; `PoolWire::forSite()` passes false, so the public build skips the `content.identity_candidates` join entirely rather than running it and discarding the result. Default true keeps `resolve()` and every dashboard caller byte-identical. The `duplicateCandidates` KEY is retained (valued `[]`) so the per-item array shape never varies — `PoolWire` strips it either way, so the public wire cannot change. Measured on a 20-item batch with 10 real candidate rows: public path 82 -> 81 queries, `identity_candidates` 1 -> 0; dashboard 25 -> 25 unchanged. This is one query per public render, not per item — the join was already batched by `whereIn($ids)` — so the honest claim is a removed join, not a collapsed N+1. Mutation-proved both ways. Trap found in passing: an anonymous `PoolResolver` subclass in `PoolDegradedBuildTest` overrides `hydrateItems()`, and a signature mismatch there crashes Pest with exit 2 and NO output rather than a normal failure.
    - **Where:** app/Site/Pools/PoolResolver.php:876-892
    - **Affects:** Public sitepage pool rendering — the hottest read path per the platform's scale profile.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add an audience flag to `itemPayloads()` (or split it into a public and dashboard variant) so the `content.identity_candidates` join only runs when the dashboard's Possible-duplicate UI is the actual caller.
        - Coordinate with the 2026-08-24 batched-hydrate refactor (`f263a284a`) — this query sits inside the same shared `itemPayloads()` call the refactor consolidated, so the audience split should extend that structure rather than fork it.
    - **Technical:** `itemPayloads()` unconditionally runs a two-way join against `content.identity_candidates`/`content.items` to build `duplicateCandidates` for every item in the batch, on both the dashboard and public paths — the surrounding comment even says "Dashboard-only; stripped from the public wire." `PoolWire::forSite()` does strip the `duplicateCandidates` key via `PoolResolver::DASHBOARD_ONLY_ITEM_KEYS` before the public response ships, but the query itself, and the array construction, still run on every public request.
    - **Plain English:** Every time someone views a public profile, the backend also runs an internal "are these two items secretly duplicates?" check meant only for the dashboard's editing screen, then deletes the answer before responding. It's prepared work that a visitor's page load pays for but never sees.
    - **Evidence:**
        ```php
        $candidateRows = DB::connection('pgsql')->table('content.identity_candidates as ic')
            ->join('content.items as li', 'li.id', '=', 'ic.left_item_id')
            ->join('content.items as ri', 'ri.id', '=', 'ic.right_item_id')
            ->whereNull('ic.dismissed_at')
            ->whereNull('li.removed_at')->whereNull('ri.removed_at')
            ->where(fn ($w) => $w->whereIn('ic.left_item_id', $ids)->orWhereIn('ic.right_item_id', $ids))
            ->get(['ic.left_item_id', 'ic.right_item_id', 'ic.evidence', 'li.headline_cache as left_headline', 'ri.headline_cache as right_headline']);
        ```
        ```php
        'duplicateCandidates' => $candidatesByItem[(string) $itemId] ?? [],
        ```

- [ ] **#API-8** · P3 — Shop catalog endpoint returns the connected store's entire product list with no pagination
    - **Where:** app/Http/Controllers/Api/Platforms/ShopController.php:829-863
    - **Affects:** Owners of large connected storefronts (Shopify/WooCommerce stores with hundreds to thousands of products); the picker dashboard and any client polling this endpoint.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `paginate()` (or a hard product cap + `has_more` flag) to `brandProducts()`'s response, and confirm whether the dashboard picker actually needs the full set client-side for local filtering — if so, document that explicitly as the reason pagination is intentionally deferred, rather than leaving it silently unbounded.
        - Verify the upstream scrapers (`providerProducts()`) themselves don't already impose a cap before assuming the full catalog reaches this endpoint uncapped.
    - **Technical:** `brandProducts()` caches and returns the complete result of `providerProducts()` for the connected store with no `paginate()` call and no product-count limit: `return $this->success(['products' => $products]);`. A large Shopify/WooCommerce store can carry thousands of SKUs, all of which are cached and shipped in one JSON response to the dashboard's product picker.
    - **Plain English:** If an owner connects a store with 5,000 products, this endpoint tries to hand back all 5,000 in a single response. It's like a waiter bringing out every dish in the kitchen at once instead of letting the customer order a page at a time — slow, heavy, and only getting worse as the store grows.
    - **Evidence:**
        ```php
        $products = Cache::remember(
            $this->catalogKey($id),
            self::applyJitter(self::CATALOG_TTL_MINUTES * 60),
            fn () => $this->budget->open($seconds, fn () => $this->providerProducts($map[$id])),
        );
        // ...
        return $this->success(['products' => $products]);
        ```

## Suggested Bundled Sessions

- **Bundle 1 — PoolResolver/PoolWire public-path over-fetching:** #API-2, #API-5, #API-6, #API-7, #API-1
    - **Why grouped:** Same file pair (`PoolResolver.php`/`PoolWire.php`, plus the adjacent `ActionCandidates.php` read) and the same root cause — the 2026-08-24 shared-hydrate batching refactor consolidated dashboard and public reads into one call without splitting them by audience, so several dashboard-only facets (library, duplicate candidates, full connection payloads) still get computed on every public request.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet). Escalate implement → Opus for #API-6/#API-7 — both touch the shared batch-hydrate path the recent refactor introduced, and an audience split done carelessly could reopen the N+1 regression that refactor fixed.

- **Bundle 2 — Small standalone hardening items:** #API-3, #API-4
    - **Why grouped:** Both are isolated, single-file, low-risk P3 items unrelated to each other or to Bundle 1 — suited to being picked up together in one short session rather than scheduled separately.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

- **#API-8 — Shop catalog pagination** · standalone because it's a distinct third-party-facing behavior change (cache key semantics, dashboard picker UX) with no natural pairing among the other findings, and warrants its own plan to confirm the picker's client-side-filtering assumption before changing the response shape.
