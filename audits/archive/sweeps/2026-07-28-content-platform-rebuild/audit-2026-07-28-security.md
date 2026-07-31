# Security Audit — 2026-07-28

**Branch:** development
**Lens:** Security: auth boundaries, tenant isolation, mass assignment, inbound callbacks, secrets, injection, SSRF, upload safety, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Models/Core/Site/{IntegrationConnection,SiteMedia,DesignKitRestyle}.php
- app/Http/Controllers/Api/Platforms/{InstagramController,ShopController,Concerns/ManagesIntegrationConnection}.php
- app/Http/Controllers/Api/Content/IdentityCandidateController.php
- app/Http/Controllers/Api/Routing/SuggestionsController.php
- app/Http/Controllers/Api/Site/RestyleController.php
- app/Policies/DesignKitRestylePolicy.php
- app/Providers/{AppServiceProvider,PlatformRegistryServiceProvider}.php
- app/Http/Requests/Api/User/{ContentLibrary/UpsertManualOverrideRequest,Design/ApplyRestyleRequest}.php
- app/Routing/{LinkObserver,IriCanonicalizer,SourceReconciler}.php
- app/Routing/Probes/{ProbeBudget,ShopifyStorefrontProbe,WooCommerceStorefrontProbe,GenericStorefrontProbe}.php
- app/Ingest/Runtime/HttpIo.php, app/Ingest/Landing/DocHasher.php, app/Ingest/Connectors/{FreshaConnector,TwitchConnector}.php
- app/Services/Platforms/{GoogleBusinessService,GoogleBusinessApifyScraper,CustomLinkSeeder,ShopifyScraper,WooCommerceScraper,GenericShopScraper,BigCartelScraper,SquarespaceScraper}.php
- app/Site/Documents/DocumentBuilder.php
- app/Catalog/** (definitions, enums, builders — reviewed, no findings)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 1 of 1 complete
- P2 Medium: 2 of 4 complete  (+#SEC-4 WONTFIX, 2026-07-31)
- P3 Low: 0 of 6 complete

---

## P1 — Fix before pilot launch

- [x] **#SEC-1** · P1 — Router persists raw and canonical URLs verbatim, including any secret-bearing query parameters
    - **Where:** app/Routing/LinkObserver.php:36-37, app/Routing/IriCanonicalizer.php:27-40
    - **Affects:** Any user who pastes a URL containing an access token, session id, API key, or signed-URL secret in a query parameter. The value is persisted verbatim in `routing.link_observations.raw_url`/`canonical_url`, `routing.source_intents.canonical_url`, and `integration_connections.payload->url` — three JSONB/text stores that also surface in exports and Nightwatch-adjacent tooling.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a denylist of common secret-bearing query parameter names (`token`, `access_token`, `api_key`, `key`, `secret`, `auth`, `session`, `sid`, `jwt`, `signature`, `sig`, `hash`, `password`) to `IriCanonicalizer`'s existing `TRACKING_PARAMS`/`PRESENTATION_PARAMS` denylist so they're stripped before the canonical URL is ever computed.
        - Separately strip the query string (or apply the same denylist) to `raw_url` in `LinkObserver::record()` — today it stores `Str::limit($iri->raw, 2000, '')` with no filtering at all.
    - **Technical:** `IriCanonicalizer::filterQuery()` deliberately keeps everything not on `TRACKING_PARAMS`/`PRESENTATION_PARAMS` — correct for identity params (`rid`, `v`, `owner`) but it means `?token=eyJ...` sails through unfiltered into three persisted locations. This is a genuine category-(5) secrets-handling gap: pasting a link is an ordinary, expected user action (custom links, website importer, link-in-bio importer all funnel through this path), so a secret landing in storage is a "known scenario," not a hypothetical — matching the P1 bar (ships bad behavior in known scenarios) rather than P2 hardening.
    - **Plain English:** When someone pastes a link into Partna, the system writes down exactly what they pasted — including any secret keys or one-time login tokens that happened to be part of that URL. It's like a security camera that records credit card numbers because it can't tell them apart from street names. The fix is to teach the system to recognize common secret-shaped parameters in URLs and scrub them before saving, the same way it already scrubs harmless tracking parameters like `utm_source`.
    - **Evidence:**
        ```php
        // LinkObserver.php — raw_url stored verbatim (length-limited only)
        DB::table('routing.link_observations')->insert([
            'id' => $id,
            'user_id' => $context->user?->id,
            'raw_url' => Str::limit($iri->raw, 2000, ''),
            'canonical_url' => $iri->canonical,
            ...
        ]);
        ```
        ```php
        // IriCanonicalizer.php — only tracking+presentation params are stripped;
        // token/api_key/secret-shaped params survive into the canonical URL
        private const PRESENTATION_PARAMS = [
            'hl', 'gl', 'lang', 'locale', 'language', 'ui', 'theme', 'variant',
            'app', 'app_id', 'platform', 'device', 'client', 'output', 'format',
            'src', 'source', 'ref', 'ref_', 'referrer', 'share', 'shared',
            'fromview', 'view', 'mode', 'sa', 'ved', 'usp', 'sc', 'nd',
        ];
        ```

## P2 — Should fix

- [ ] **#SEC-2** · P2 — `IntegrationConnection` keeps system-managed refresh/provenance columns fillable with no live over-post path
    - **Where:** app/Models/Core/Site/IntegrationConnection.php:82-104
    - **Affects:** Defense-in-depth only today — a future controller that naively does `$connection->update($request->validated())` would let a client forge `created_by_detector`, reset `consecutive_failures`, or poison `refresh_etag`/`apify_status`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `created_by_detector`, `created_by_catalog_digest`, `last_refresh_status`, `last_refresh_error`, `consecutive_failures`, `apify_status`, `place_id`, `refresh_etag`, `refresh_last_modified` from `$fillable`; assign them via direct attribute writes inside the refresh/detector services that own them.
    - **Technical:** Grepped every controller and Form Request in scope — no route validates or forwards any of these keys, and every actual write path (`ManagesIntegrationConnection::upsertConnection`, `InstagramController::applySync`, `SourceReconciler::applyIntent`) builds an explicit server-constructed `$values` array rather than piping `$request->all()`/`validated()` through. There is no currently-reachable over-post, so this does not meet the P0 bar (which requires a real, reachable attacker path) — it is a should-fix hardening gap against a future careless controller.
    - **Plain English:** Some fields behind your connections are like a car's service-history stamps — the system fills them in automatically during check-ups. Right now nothing lets a user edit that history directly, but leaving those fields on the "any change allowed" list is a trap for a future developer who wires up a new edit form carelessly.
    - **Evidence:**
        ```php
        protected $fillable = [
            'user_id', 'platform', 'surface_key', 'routing_class', 'is_primary',
            'resource_id', 'canonical_key', 'resource_kind', 'payload', 'sort_order',
            'is_active', 'last_visited_at', 'last_refreshed_at',
            'last_refresh_status', 'last_refresh_error', 'consecutive_failures',
            'apify_status', 'place_id', 'refresh_etag', 'refresh_last_modified',
            'display_settings',
        ];
        ```

- [ ] **#SEC-3** · P2 — `SiteMedia` keeps the storage `path` fillable with no live over-post path, but the force-delete hook trusts it unconditionally
    - **Where:** app/Models/Core/Site/SiteMedia.php:162-181, 202-241
    - **Affects:** Defense-in-depth — every current upload path (`MediaUploadService`) sets `path` server-side from the storage service's own return value; no Form Request or controller in scope passes a client-supplied `path` into create/update.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `'path'` from `$fillable`; have `MediaUploadService` set it via a dedicated setter or `forceFill()` at the one call site that legitimately needs to (mirroring the `site_id`/`->associate()` pattern already used for tenancy on this same model).
    - **Technical:** The `forceDeleting` hook unconditionally calls `Storage::disk(...)->delete($media->path)`, so if `path` were ever set from client input the blast radius (arbitrary file deletion on the configured media disk) would be real. Today it isn't reachable: grepping every controller/Form Request touching `SiteMedia`, `path` is only ever written by `MediaUploadService` from its own `storeOriginal()` return value. This is the same shape as SEC-2 — a real hardening gap, not a currently-exploitable one — so P0 doesn't apply, but the blast radius if the guard is ever removed is high enough to keep this above P3.
    - **Plain English:** When you permanently delete a photo, the system also deletes the actual file on disk, trusting whatever path is stored on that record. Right now only the server itself ever writes that path — never a web form — so there's no live risk today. But the field is technically still on the "can be edited from a request" list, which is a landmine for whoever touches this code next.
    - **Evidence:**
        ```php
        protected $fillable = [
            'pool', 'bucket', 'path', 'alt_text', 'caption', 'purpose',
            ...
        ];
        ```
        ```php
        if ($media->path) {
            try {
                $mediaDisk = Storage::disk((string) config('partna.media_disk'));
                if ($mediaDisk->exists($media->path)) {
                    $mediaDisk->delete($media->path);
                }
            } catch (\Throwable $e) { ... }
        }
        ```

- [x] **#SEC-4** · P2 — Raw `DB::insert()` in `ShopController::setProducts()` bypasses `$fillable` for bulk product rows · **WONTFIX (tier3 triage 2026-07-31):** both premises re-verified at `ShopController.php:699-709` and both hold — `$rows` is a hand-written 7-key literal and `$productData` is never spread (only `productId` + `json_encode()` into the `data` JSONB), so there is no live over-post path; and the prescribed allowlist is a tautology against literals three lines above — untestable, and a silently-dropping filter would be a debugging trap for the future developer it claims to protect. Reopen only if `$rows` stops being a literal. Full argument in `CONSOLIDATED.md`. No code changed.
    - **Where:** app/Http/Controllers/Api/Platforms/ShopController.php:682-697
    - **Affects:** Shop product-selection persistence — any future column added to `site.shop_products` (especially a tenant-scoping FK) would be silently writable through this raw insert path if the scraper's catalog output ever grows a colliding key.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Validate the keys present in each `$rows[]` entry against an explicit allowlist immediately before `ShopProduct::query()->insert($rows)`, or switch to a `ShopProduct::insert()` wrapper that whitelists columns explicitly rather than trusting the shape of `$productData`.
    - **Technical:** `$rows` is hand-built from `$productData` sourced from `$catalog` (scraper output, cached or freshly fetched) plus a handful of server-set columns (`id`, `brand_id`, `position`, timestamps) — `$productData` itself is only used for `productId`, not spread wholesale, so today's actual insert is safe. The comment's own admission that this bypasses `HasUuids` and the `data` cast to avoid 250 sequential round-trips under a 10s lock is a legitimate performance trade-off; the residual risk is purely structural (raw `insert()` has no `$fillable` gate at all) and would only bite if a future edit changes `$rows`'s construction to spread `$productData` directly.
    - **Plain English:** Normally the framework has a bouncer that checks "are you allowed to write to this column?" before data reaches the database. The bulk-insert path used here for performance skips that bouncer entirely. Nothing dangerous gets through today because the code carefully picks only a few safe fields — but there's no safety net if a future change is less careful.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->transaction(function () use ($brand, $selected) {
            ShopProduct::where('brand_id', $brand->id)->delete();
            if ($selected->isNotEmpty()) {
                $now = now();
                $rows = $selected->map(fn (array $productData, int $index) => [
                    'id' => (string) Str::uuid7(),
                    'brand_id' => $brand->id,
                    'product_id' => (string) ($productData['productId'] ?? ''),
                    'position' => $index,
                    'data' => json_encode($productData),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();
                ShopProduct::query()->insert($rows);
            }
        });
        ```

- [x] **#SEC-5** · P2 — `HttpIo::post()` follows redirects without the per-hop SSRF re-validation `get()`/`getMany()` perform
    - **Where:** app/Ingest/Runtime/HttpIo.php:48-62
    - **Affects:** Ingest connectors calling `$io->post()` — currently `FreshaConnector` (hardcoded `self::GRAPHQL_URL`) and `TwitchConnector` (config-sourced `services.twitch.token_url`). Both current callers use fixed, non-user-supplied URLs, so exploitation today requires the fixed upstream host itself to issue a malicious redirect — not a client-reachable input.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route `post()` through `SafeUrlFetcher`'s manual, per-hop-revalidating redirect logic instead of delegating to Laravel's `Http::post()` (which uses Guzzle's default redirect-following), or add `->withoutRedirecting()` and re-validate each hop explicitly, matching `tryFetch()`'s pattern.
    - **Technical:** `get()`/`getMany()` route through `SafeUrlFetcher::tryFetch()`/`fetchMany()`, which resolve the host once, pin the connection, and re-validate every redirect hop. `post()` calls `admit()` (manifest host-glob check) and `assertPublicUrl()` (public-IP check) on the *initial* URL only, then hands off to `Http::post()`, whose Guzzle client follows redirects by default with no re-validation. A `307`/`308` from the upstream to an internal address would bypass the guard. Today's two callers pass hardcoded/config URLs rather than user- or vendor-response-derived ones, so this is the same "attacker-controlled input doesn't currently exist" pattern that keeps it at P2 rather than P1/P0 — it becomes a live SSRF vector the moment any connector's POST target is derived from an untrusted response.
    - **Plain English:** The front gate checks the ID of whoever knocks first, but if that first visitor points somewhere else once inside, nobody checks again. The `get()` path already re-checks IDs at every doorway; the `post()` path only checks once at the front door. Nothing bad happens today because both current visitors have fixed, known addresses — but this needs the same treatment as `get()` so it stays safe if that ever changes.
    - **Evidence:**
        ```php
        public function post(string $url, array $body = [], array $headers = []): array
        {
            $this->admit($url);
            // POST is not part of SafeUrlFetcher's surface; assert the same
            // address policy explicitly before using the raw client.
            $this->fetcher->assertPublicUrl($url);
            $response = Http::withHeaders($headers)
                ->timeout((int) config('partna.http_fetch.timeout_seconds', 8))
                ->connectTimeout((int) config('partna.http_fetch.connect_timeout_seconds', 3))
                ->post($url, $body);
            return ['status' => $response->status(), 'body' => $response->body(), 'headers' => ['content-type' => $response->header('Content-Type')]];
        }
        ```

## P3 — Nice to have

- [ ] **#SEC-6** · P3 — SHA-1 (truncated) used for custom-link resource-ID derivation
    - **Where:** app/Services/Platforms/CustomLinkSeeder.php:127
    - **Affects:** Custom-link deduplication within one user's account only. A collision would let two different URLs merge into one stored row.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace `sha1(...)` with `hash('sha256', ...)`.
    - **Technical:** `$rid = 'link-'.substr(sha1(strtolower($normalized)), 0, 16)` truncates to 64 bits within a single-tenant scope (`user_id` + `platform` + `resource_id`). SHA-1's known collision weakness doesn't materially change the practical risk here — a collision needs ~2^32 inputs by birthday bound and would only affect one user's own links — but SHA-256 is a zero-cost swap.
    - **Plain English:** This uses an older key-cutting method to generate unique IDs for each link a user saves. The odds of two different links getting the same ID are astronomically small, and even then it would only affect that one user. A newer, equally-cheap method exists right next to it — swapping is a one-line change.
    - **Evidence:**
        ```php
        $rid = 'link-'.substr(sha1(strtolower($normalized)), 0, 16);
        ```

- [ ] **#SEC-7** · P3 — `Log::info` persists user/place identifiers past its stated "temporary" purpose
    - **Where:** app/Services/Platforms/GoogleBusinessApifyScraper.php:88-96
    - **Affects:** Log hygiene — `user_id` and `place_id` land in Nightwatch at `info` level; the comment marks this transitional but nothing enforces removal.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Drop the log level to `debug`, or remove the call and rely on the existing `not_ok`/`bad_items` warning paths for visibility. If kept temporarily, add a dated TODO.
    - **Technical:** `Log::info` persists at the default production log level, unlike `debug`. The values are pseudonymous UUIDs, not raw PII, but they're linkable to user records, and the code's own comment ("Drop to debug once settled") anticipates this being temporary — a hygiene gap rather than an active leak.
    - **Plain English:** This is like leaving debugging sticky-notes on customer files after testing is done — not secret information, but it doesn't belong in the permanent record. The developer already flagged it for cleanup; this just makes sure it happens before real users arrive.
    - **Evidence:**
        ```php
        Log::info('google_business.apify.keys', [
            'place_id' => $placeId,
            'user_id' => $userId,
            'present' => array_values(array_filter([...])),
        ]);
        ```

- [ ] **#SEC-8** · P3 — ReDoS-prone (but length-bounded) regex in the Square platform connect-URL validator
    - **Where:** app/Providers/PlatformRegistryServiceProvider.php:513
    - **Affects:** Authenticated users connecting a Square booking link. A crafted ~1000-char URL could make the regex engine backtrack more than necessary before rejecting.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `([a-z0-9-]+\.)*` with an atomic group `(?>[a-z0-9-]+\.)*` or possessive quantifier to remove the backtracking surface.
    - **Technical:** The pattern `#^https?://([a-z0-9-]+\.)*(squareup\.com|square\.site)(/[^\s]*)?$#i` contains a quantified group with an inner `+` — the classic shape for polynomial (not exponential, since a literal `.` anchors each repetition) backtracking. The `max:1000` rule bounds practical damage to a small polynomial blowup on at most 1000 characters — negligible today, but the atomic-group fix is free.
    - **Plain English:** Checking this URL is a little like re-reading the same short phrase several different ways before giving up. It's bounded — the input can't be longer than 1000 characters — so real slowdown is unlikely, but a one-line tweak removes the possibility entirely.
    - **Evidence:**
        ```php
        $r->get('square')->connectInput('url', ['required', 'string', 'max:1000', 'regex:#^https?://([a-z0-9-]+\.)*(squareup\.com|square\.site)(/[^\s]*)?$#i'], ['url.regex' => 'Enter a valid Square booking link (a squareup.com or square.site URL).'], true);
        ```

- [ ] **#SEC-9** · P3 — Manual-override `value` field has no size or type bound
    - **Where:** app/Http/Requests/Api/User/ContentLibrary/UpsertManualOverrideRequest.php:29
    - **Affects:** Authenticated users writing manual overrides to their own content library; database storage bloat.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `max:` and `string` constraints to the `value` rule (e.g. `['present', 'nullable', 'string', 'max:10000']`), sized to the actual override use case.
    - **Technical:** `'value' => ['present']` requires the key exist but places no bound on size, type, or shape. The class docblock states an override is "honoured absolutely and forever" — a stored row persists indefinitely — so a multi-megabyte string is a durable storage cost with no application-level guard.
    - **Plain English:** The "manual override" form accepts a value with no size limit — like a text box that says "enter your custom title" but lets someone paste in an entire novel. A reasonable character limit stops that without affecting real use.
    - **Evidence:**
        ```php
        'value' => ['present'],
        ```

- [ ] **#SEC-10** · P3 — Raw DB update in `IdentityCandidateController::settle()` omits `user_id` re-verification
    - **Where:** app/Http/Controllers/Api/Content/IdentityCandidateController.php:114-122
    - **Affects:** Identity-candidate dismissal path; defense-in-depth only — the only caller, `findCandidate()`, already scopes by `user_id` before `settle()` is ever reached.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->where('user_id', $candidate->user_id)` to the `settle()` update so the query is self-contained rather than relying solely on the upstream scope.
    - **Technical:** `settle()` updates `content.identity_candidates` by `id` alone. Both call sites (`rule()`, `dismiss()`) resolve `$candidate` via `findCandidate()`, which scopes `->where('user_id', $userId)` — so today's window is closed. The gap is only realized if a future refactor calls `settle()` from a less-guarded context.
    - **Plain English:** Imagine a doorman checks your ID, then walks you to a filing cabinet. The clerk there updates by file number alone, trusting the doorman already checked. It's safe today because the doorman is thorough, but adding the name-check at the cabinet itself costs almost nothing and keeps things safe even if a future process skips the doorman.
    - **Evidence:**
        ```php
        private function settle(IdentityCandidate $candidate): void
        {
            DB::table('content.identity_candidates')
                ->where('id', $candidate->id)
                ->whereNull('dismissed_at')
                ->update(['dismissed_at' => now()]);
        }
        ```

- [ ] **#SEC-11** · P3 — Raw DB update in `SuggestionsController::dismiss()` omits `user_id` re-verification
    - **Where:** app/Http/Controllers/Api/Routing/SuggestionsController.php:112-116
    - **Affects:** Suggestion-dismissal path; same defense-in-depth gap as SEC-10 — `findIntent()` already scopes by `user_id` upstream.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->where('user_id', $intent->user_id)` to the `source_intents` update, matching the discipline already used on the adjacent `item_tombstones` insert in the same method.
    - **Technical:** `dismiss()` updates `routing.source_intents` by `id` alone, while `$intent` is resolved moments earlier by `findIntent()` scoped to `->where('user_id', $userId)`. The sibling `item_tombstones` insert two lines later correctly stamps `user_id` — this update should match.
    - **Plain English:** Same filing-cabinet story as the identity-candidate finding. One cabinet in this same method (the tombstones table) already double-checks the name on every write; this cabinet should match it.
    - **Evidence:**
        ```php
        DB::table('routing.source_intents')->where('id', $intent->id)->update([
            'state' => 'dismissed',
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('routing.item_tombstones')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            ...
        ]);
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Mass-assignment hardening on tenant-adjacent models:** #SEC-2, #SEC-3, #SEC-4
    - **Why grouped:** Same root cause (over-broad `$fillable`/raw-insert bypass with no currently-reachable exploit path) across `IntegrationConnection`, `SiteMedia`, and `ShopController`'s bulk insert — all fixed by tightening the write surface.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Raw-DB tenant-scope defense-in-depth:** #SEC-10, #SEC-11
    - **Why grouped:** Identical pattern (mutating query scoped only by primary key, upstream lookup already tenant-scoped) in two sibling controllers — same one-line fix.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Platform-service hygiene:** #SEC-6, #SEC-7
    - **Why grouped:** Both in `app/Services/Platforms/`, both low-effort hygiene fixes (hash algorithm swap, log-level drop) with no live exploit.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Input-validation hardening:** #SEC-8, #SEC-9
    - **Why grouped:** Both category-6 validation-rule tightening on existing Form/route validators — no shared file but same fix shape and effort tier.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#SEC-1 — Router persists secret-bearing URL query params** · P1, and touches persisted data across three separate tables (`routing.link_observations`, `routing.source_intents`, `integration_connections.payload`) — the denylist design needs its own review to avoid also stripping legitimate identity params.
- **#SEC-5 — `HttpIo::post()` SSRF redirect gap** · touches a shared trust-boundary primitive (`SafeUrlFetcher`/`HttpIo`) used by the whole ingest connector fleet — a change here needs isolated verification against every current and future POST-using connector.
