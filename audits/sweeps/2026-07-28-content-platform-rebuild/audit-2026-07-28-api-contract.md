# API Contract & Resource Leakage Audit — 2026-07-28

**Branch:** development
**Lens:** API Contract & Resource Leakage — raw model fields bleeding through, over-fetching, inconsistent pagination, response shape inconsistencies across Partna's five API surfaces (User, PublicSite, Staff, Internal, Platforms)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- `app/Catalog/Contracts`
- `app/Catalog/Enums`
- `app/Content`
- `app/Http/Controllers/Api/ApiController.php`
- `app/Http/Controllers/Api/Catalog`
- `app/Http/Controllers/Api/Content`
- `app/Http/Controllers/Api/Internal`
- `app/Http/Controllers/Api/Platforms`
- `app/Http/Controllers/Api/PublicSite`
- `app/Http/Controllers/Api/Routing`
- `app/Http/Controllers/Api/Site`
- `app/Http/Controllers/Api/Staff`
- `app/Http/Controllers/Api/User`
- `app/Http/Resources`
- `app/Services/Analytics`
- `app/Services/PublicSite`
- `app/Services/Site`
- `app/Site`

## Progress

- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 6 complete

---

## P2 — Should fix

- [ ] **#API-1** · P2 — Public sitepage resolver emits three user-controlled URLs with no scheme gate; the sibling actions service gates every URL it emits
    - **Where:** `app/Services/PublicSite/SitepageDataResolverService.php:667` (`getLinks()`), `:904` (`getBooking()`), `:870` (`buildServicesData()`)
    - **Affects:** All public sitepage visitors — `resolved_url` (booking), link-block `url`, and `manual_booking_url` all land in the public JSON with no scheme check in this resolver, unlike the sibling `SiteActionsService`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract `SiteActionsService::safeHref()`'s scheme-parsing logic into a shared helper (e.g. `App\Support\UrlSafety::safeHref()`) and apply it to `resolved_url` in `getBooking()`, `url` in `getLinks()`, and `manual_booking_url` in `buildServicesData()`.
        - Do not rely solely on write-time validation as the backstop: `StoreLinkBlockRequest`/`UpdateLinkBlockRequest` do gate custom-mode `url` to http/https (`LinkBlockRequestHelpers::isAllowedScheme()`), but other writers of `site.blocks` (auto-sync seeders, routing reconciler) are not confirmed to run the same gate, and `UpdateBookingSettingsRequest` validates `manual_booking_url` with Laravel's generic `url` rule only — which does not reliably reject non-http(s) schemes (PHP's `FILTER_VALIDATE_URL`, which the rule wraps, accepts opaque `scheme:opaque` URIs like `javascript:...`).
    - **Technical:** `SiteActionsService` routes every external URL it emits (bookings, link blocks, integration connections) through a private `safeHref()` that parses the scheme and returns `null` for anything other than `http`/`https`. `SitepageDataResolverService` — a second resolver reading the same underlying data for the same public payload surface — emits `resolved_url`, link-block `url`, and `manual_booking_url` with no equivalent gate. Custom link-block URLs happen to be validated at one known write path (`StoreLinkBlockRequest`/`UpdateLinkBlockRequest`), but `manual_booking_url`'s only validation is Laravel's generic `url` rule (not an http/https allowlist), and `settings.booking_url` read by `getBooking()` has no Form Request validating it in this codebase at all — its only two references in `app/` are both reads. Any writer that lands a non-http(s) URI in these fields (a future import path, a booking-provider write, a validation gap) is served verbatim to every visitor of that site.
    - **Plain English:** Think of the public sitepage as a printed brochure. One department (the actions service) has a proofreader who checks every web address before printing — if it doesn't start with `http://` or `https://`, they refuse to print it. A second department building booking and link addresses for the same brochure doesn't have that proofreader. If a bad address ever gets stored — say, one that runs code instead of opening a page — it could go straight onto the brochure that every visitor sees. The fix is giving the second department the same proofreader the first one already has.
    - **Evidence:**
        ```php
        // SitepageDataResolverService.php — getBooking(): no scheme gate
        return [
            'platform' => $platform !== '' ? $platform : null,
            'path' => $bookingUrl,
            'resolved_url' => $bookingUrl,
            'title' => $title !== '' ? $title : 'Book now',
        ];
        ```
        ```php
        // SitepageDataResolverService.php — getLinks(): raw $block->url, no gate
        return [
            'id' => (string) $block->id,
            'title' => $title,
            'url' => $url,
            'category' => $category !== '' ? $category : 'custom',
            'platform' => $platform,
        ];
        ```
        ```php
        // SitepageDataResolverService.php — buildServicesData(): no gate
        return [
            'booking_mode' => $bookingMode,
            'manual_booking_url' => $manualBookingUrl !== '' ? $manualBookingUrl : null,
            'services' => $services,
        ];
        ```
        ```php
        // SiteActionsService.php — every emitted URL passes through this gate
        private function safeHref(mixed $url): ?string
        {
            if (! is_string($url)) {
                return null;
            }
            $url = trim($url);
            if ($url === '') {
                return null;
            }
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

            return ($scheme === 'http' || $scheme === 'https') ? $url : null;
        }
        ```

## P3 — Nice to have

- [ ] **#API-2** · P3 — Public site document payload includes internal build-diagnostic warnings
    - **Where:** `app/Site/Documents/DocumentBuilder.php:137-146` (`compose()`)
    - **Affects:** Nobody today — no controller in `app/` reads `site.site_documents` yet, so this artefact isn't currently served to any endpoint. It becomes a public-payload leak the moment a `PublicSite` controller is wired to read it, which the surrounding `Documents`/catalog work (recent connector-fleet commits) is actively building toward.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Drop the `'warnings'` key from the array `compose()` returns (the document that gets stored and, eventually, served).
        - Keep the existing separate `warnings` column write on `site.site_documents` for internal observability — `build()` already persists it there independently of the document body.
    - **Technical:** `compose()` builds the JSON that `build()` stores as `site.site_documents.document` — the artefact this subsystem exists to eventually serve publicly. It embeds a `warnings` array of internal diagnostic codes (e.g. `'empty_page'`) directly in that document, duplicating what's already written to the dedicated `warnings` column. No consumer reads `site.site_documents` yet, so there is no live leak today — but shipping the field now means it ships silently the day a public reader is added, since nobody will think to re-audit an already-"working" builder.
    - **Plain English:** Imagine a printed menu with a footnote at the bottom reading "the printer noticed page 3 was blank so we hid it." That's a note for the kitchen, not the diner. This build system isn't handed to customers yet, but when it is, that footnote goes out with it unless it's removed now — and removing it costs nothing, since the same information is already saved separately for staff to see.
    - **Evidence:**
        ```php
        return [
            'navigation' => array_values(array_map(
                fn (array $p) => ['key' => $p['key'], 'label' => $p['label']],
                array_filter($composedPages, fn (array $p) => ! $p['hidden'] && $p['sections'] !== []),
            )),
            'pages' => $composedPages,
            'warnings' => $warnings,
            'builderRevision' => self::BUILDER_REVISION,
        ];
        ```

- [ ] **#API-3** · P3 — `ShopController::selection()` loads every connected brand's full product set to return only the primary brand
    - **Where:** `app/Http/Controllers/Api/Platforms/ShopController.php:718-730` (`selection()`), `:978-989` (`brandMap()`)
    - **Affects:** `GET /api/platforms/shop/selection` for multi-brand users — up to 5 connected stores' full product sets are fetched from the DB and immediately discarded to return one brand's products.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a query that loads only the first brand with products (e.g. a `connection->shopBrands()->whereHas('products')->with('products')->orderBy('position')->first()` variant), used only by `selection()`.
        - Leave `brandMap()` unchanged for `brands()`, which legitimately needs every brand.
    - **Technical:** `selection()` calls `brandMap()`, which runs `$connection->shopBrands()->with('products')->get()` — every brand and its full product list — then `ShopPayload::primaryWithProducts()` iterates and returns the first brand with a non-empty `products` array, discarding the rest. For a user with 5 stores this fetches every product row across all 5 to serve one. `brandMap()` is shared with `brands()` (which needs everything), so the fix belongs at `selection()`'s call site, not the shared helper.
    - **Plain English:** Imagine asking a warehouse clerk "what's in the first box?" and they bring out every box in the warehouse, open the first one, and put the rest back. For someone with five boxes of stock, that's a lot of unnecessary carrying for a question that only needed one box answered.
    - **Evidence:**
        ```php
        // selection() calls brandMap() which loads everything…
        $primary = ShopPayload::fromArray($this->brandMap($this->currentUser($request)))->primaryWithProducts();
        ```
        ```php
        // …but brandMap() fetches ALL brands + ALL products
        private function brandMap(User $user): array
        {
            $connection = $this->connectionFor($user);
            if (! $connection) {
                return [];
            }

            return $connection->shopBrands()->with('products')->get()
                ->keyBy('brand_id')
                ->map(fn (ShopBrand $b) => $b->toBrandArray())
                ->all();
        }
        ```

- [ ] **#API-4** · P3 — `getLinks()` fetches every `Block` column with no explicit `select()`
    - **Where:** `app/Services/PublicSite/SitepageDataResolverService.php:625-671`
    - **Affects:** Every public sitepage request that has link blocks — the query loads full `site.blocks` rows (including the `settings` JSONB column, already genuinely needed) plus every unused column (timestamps, `block_group`, `is_active`, etc.), on every request.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an explicit `->get(['id', 'title', 'url', 'sort_order', 'settings', 'platform', 'category', 'deleted_at'])` (adjust to the actual fields the closure reads) to the `Block` query in `getLinks()`.
        - `SiteActionsService::linkBlockCreatedAt()` already does this in the same codebase (`->get(['id', 'created_at'])`) — mirror that pattern.
    - **Technical:** `getLinks()` runs `Block::query()->where(...)->orderBy('sort_order')->get()` with no `select()`, so Postgres returns every column of `site.blocks`. The subsequent `map()` closure reads only `id`, `title`, `url`, `sort_order` (via ordering), `settings`, `platform`, `category`, and `deleted_at`. Not a correctness bug — pure wasted I/O on the platform's hottest read path (public sitepage resolution).
    - **Plain English:** Imagine ordering a full restaurant menu when you only want the soup — the kitchen still prepares every dish and carries it to the table before you wave the rest away. This query asks the database for every column on every link row, then uses only a handful. Asking for just the columns needed saves work on every single page load.
    - **Evidence:**
        ```php
        $rows = Block::query()
            ->where('site_id', $site->id)
            ->where('block_group', 'links')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->get()
            ->map(function (Block $block): array {
                $settings = is_array($block->settings) ? $block->settings : [];
                $platform = is_string($block->platform)
                    ? strtolower(trim($block->platform))
                    : null;
                // ...
            });
        ```

- [ ] **#API-5** · P3 — `SuggestionsController` hand-builds response arrays from raw DB rows with no Resource class
    - **Where:** `app/Http/Controllers/Api/Routing/SuggestionsController.php:40-68` (`index()`), `:74-97` (`accept()`)
    - **Affects:** Clients of the suggestions inbox (`GET /api/routing/suggestions`, `POST .../accept`) — no single source of truth defines this response shape.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Introduce a `SuggestionResource` wrapping a `routing.source_intents` row plus its resolved `CompiledCatalog::surface()` data; replace the manual `$intents->map(...)` closure with `SuggestionResource::collection($intents)`.
        - Give `accept()`'s three-key array the same treatment (or a small dedicated Resource).
    - **Technical:** `index()` queries `DB::table('routing.source_intents')->get()` and maps each row to a hand-built array, cross-referencing `CompiledCatalog::surface()`. Every field is explicitly enumerated — nothing leaks by accident — but there is no Resource class acting as the single source of truth for the shape. A new column on `source_intents` doesn't force a decision about whether it belongs on the wire. Same root cause and same fix as `RoutingController` below (#API-6): array-shaped controller output with no Resource layer.
    - **Plain English:** The suggestions inbox builds its reply by hand, copying fields one at a time — thorough, and nothing leaks by accident today. But if the database gains a new column next month, nothing forces a decision about whether it belongs in the API response. A Resource class is that decision point, made once instead of never.
    - **Evidence:**
        ```php
        $suggestions = $intents->map(function (object $intent): array {
            $surface = CompiledCatalog::surface($intent->surface_key);

            return [
                'id' => $intent->id,
                'state' => $intent->state,
                'blockReason' => $intent->block_reason,
                'surfaceKey' => $intent->surface_key,
                'displayName' => $surface['display_name'] ?? $intent->surface_key,
                'brandKey' => $surface['brand_key'] ?? null,
                'routingClass' => $intent->routing_class,
                'identifier' => $intent->identifier,
                'url' => $intent->canonical_url,
                'origin' => $intent->origin,
                'firstSeenAt' => $intent->first_seen_at,
                'conflictingConnectionId' => $intent->conflicting_connection_id,
                'question' => $this->questionFor($intent, $surface),
                'actions' => $this->actionsFor($intent),
            ];
        })->all();
        ```

- [ ] **#API-6** · P3 — `RoutingController` passes the routing service's full result array straight through with no Resource class
    - **Where:** `app/Http/Controllers/Api/Routing/RoutingController.php:33-42` (`preview()`), `:44-93` (`store()`)
    - **Affects:** Clients of `POST /api/routing/preview` and `POST /api/routing/links` — any field `LinkRoutingService` adds in the future ships automatically, with no allowlist deciding whether it should.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Introduce dedicated `RoutingPreviewResource`/`RoutedLinkResource` classes that allowlist the fields clients need, replacing `$this->success($result)` / `$this->success([...] + $result, 202)`.
        - Same root cause and fix as `SuggestionsController` (#API-5) — bundle together.
    - **Technical:** `preview()` feeds the entire `LinkRoutingService::preview()` return value into `$this->success($result)`; `store()` spreads it with `+ $result`. Every field currently returned (`verdict`, `canonicalUrl`, `routedTo`, `confidence`, `blockReason`, `explanation`, `conflictingConnectionId`) is benign and non-sensitive — `describe()` in `LinkRoutingService` is a tightly-scoped, fully-enumerated array, not an Eloquent model — so there is no current leak. The gap is structural: nothing stops the next field the routing service returns from becoming part of the public contract by accident.
    - **Plain English:** The routing engine hands back a fixed, well-defined set of facts, and the controller forwards them as-is. Today that's fine — nothing sensitive is in there. But there's no gatekeeper checking new fields as this actively-developed feature grows, so a future addition to the engine's internal notes could become part of the public contract without anyone deciding it should.
    - **Evidence:**
        ```php
        // preview — entire service result passed through
        $result = $this->routing->preview(
            $request->validated()['url'],
            RoutingContext::forUser($user, 'paste'),
        );

        return $this->success($result);
        ```
        ```php
        // store — result array spread into response
        return $this->success(['status' => $status, 'outcome' => $outcome] + $result, 202);
        ```

- [ ] **#API-7** · P3 — `connect()` and `connectStatus()` return the same platform-connection data at different nesting depths
    - **Where:** `app/Http/Controllers/Api/Platforms/GenericPlatformController.php:122-128` (`connect()`), `:256-262` (`connectStatus()`)
    - **Affects:** Dashboard clients of every deferred-connect platform (Shopify/WooCommerce/Squarespace-class + config-driven additions) — the same connection Resource lands at the top level on the sync path and nested under `connection` on the poll path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pick one shape: either wrap `connect()`'s sync-200 Resource output under a `connection` key to match `connectStatus()`'s `ready` shape, or unwrap `connectStatus()`'s `connection` key to match `connect()`.
        - Extend `DeferredConnectParityTest` (already exists and already asserts 202-body key parity between the sync and deferred paths) to also assert the chosen top-level-vs-nested contract, so new deferred platforms inherit the fixed shape rather than the drift.
    - **Technical:** `connect()`'s synchronous 200 path spreads `(new $resourceClass($selection))->resolve()` directly into the envelope (`['id' => ..., ...resource fields]`). `connectStatus()`'s `ready` branch nests the identical Resource output under `'connection' => $this->shape(...)`. A client built against the sync response (`data.name`, `data.url`) must special-case the poll response (`data.connection.name`). `shouldDeferConnect()`-gated platforms are actively expanding (recent commits: `connect_budget_seconds`/deferred-connect infra, ten-connector fleet landed), so every future deferred platform inherits this drift by construction rather than by choice.
    - **Plain English:** Think of ordering at a counter versus ordering delivery. At the counter, you're handed your food directly. For delivery, the same food arrives inside a bag labeled "connection." The app shouldn't need different code depending on whether the connection was instant or took a few seconds to arrive — the food should come in the same container either way.
    - **Evidence:**
        ```php
        // connect() — Resource keys at top level (sync 200)
        return $this->success(['id' => $row->resource_id, ...(new $resourceClass($selection))->resolve()]);
        ```
        ```php
        // connectStatus() — same Resource keys nested under 'connection' (poll 200)
        if ($row->last_refresh_status === 'ok') {
            return $this->success([
                'status' => 'ready',
                'id' => $row->resource_id,
                'connection' => $row->payload ? $this->shape($descriptor, $row->payload) : null,
            ]);
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Public sitepage resolver hardening:** #API-1, #API-4
    - **Why grouped:** Same file (`SitepageDataResolverService.php`), overlapping methods (`getLinks()` touched by both) — one session to add the URL gate and the `select()` tightening together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Routing/Suggestions Resource-layer hygiene:** #API-5, #API-6
    - **Why grouped:** Identical root cause (array-shaped controller output, no dedicated Resource class) across the two `Routing` controllers.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Deferred-connect status shape parity:** #API-7
    - **Why grouped:** Single-file fix with an existing parity test to extend; keeping it isolated avoids coupling to the Bundle 2 routing work.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Document builder public-payload cleanup:** #API-2
    - **Why grouped:** Isolated to `DocumentBuilder.php`, unrelated subsystem to the others.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Shop selection over-fetch:** #API-3
    - **Why grouped:** Isolated to `ShopController.php`, unrelated subsystem to the others.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None — no P0, auth/authorization-bypass, money, DB migration/schema, or L/XL-effort findings survived adjudication.
