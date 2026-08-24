# Security Audit — 2026-08-24

**Branch:** development
**Lens:** Security: auth boundaries, tenant isolation, mass assignment, inbound callbacks, secrets, injection, SSRF, upload safety, PII exposure — bundle covering config/models, user API surface, public sitepage + analytics, platforms, content pools, routing/catalog, and outbound ingest connectors
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Models/Analytics/ActionEvent.php
- app/Models/Core/Site/Site.php
- app/Models/Core/Site/Workplace.php
- config/checkpoint.php
- config/partna.php
- app/Http/Controllers/Api/User/Analytics/DevInsightsController.php
- app/Http/Controllers/Api/User/SiteManagement/UserSiteActionsController.php
- app/Http/Requests/Api/PublicSite/Analytics/ActionSeenRequest.php
- app/Http/Requests/Api/PublicSite/Analytics/ActionTapRequest.php
- app/Http/Requests/Api/PublicSite/Analytics/ItemSeenRequest.php
- app/Http/Requests/Api/Staff/UserSite/Services/StaffReorderServiceLayoutRequest.php
- app/Http/Requests/Api/User/Services/ReorderServiceLayoutRequest.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Controllers/Api/Platforms/ShopController.php
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- app/Http/Controllers/Api/Content/PoolController.php
- app/Http/Controllers/Api/Routing/RoutingController.php
- app/Http/Controllers/Api/Routing/SuggestionsController.php
- app/Services/Platforms/ConnectionDisplayName.php
- app/Services/Platforms/GoogleBusinessService.php
- app/Services/Platforms/Payloads/GoogleBusinessPayload.php
- app/Services/Platforms/ThirdPartyPii.php
- app/Routing/Importers/LinkInBioImporter.php
- app/Routing/IriCanonicalizer.php
- app/Routing/ShortLinkExpander.php
- app/Ingest/Connectors/FreshaConnector.php
- app/Ingest/Projection/FreshaServiceProjector.php
- app/Ingest/Runtime/HttpIo.php
- app/Site/Actions/ActionCandidates.php
- app/Site/Actions/ConnectionProfileUrl.php
- app/Site/Pools/PoolResolver.php
- app/Support/UrlSafety.php
- app/Catalog/Definitions/Opentable.php
- app/Policies/ContentItemPolicy.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 15 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEC-1** · P1 — `PoolController::reorder` pins items without the `pin` Policy check, bypassing the borrowed-media rule
    - **Where:** app/Http/Controllers/Api/Content/PoolController.php:145-209
    - **Affects:** Any owner with a "borrowed" (Google-sourced) media item in their pool — the reorder/drag endpoint lets them pin an item the dedicated pin button explicitly refuses.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `reorder()`, after the owner+pool-scoped `$owned` lookup, call `$this->authorizeForUser($user, 'pin', $item)` for each `Item` being written as `STATE_PINNED`, mirroring `select()`.
        - Add a regression test: a borrowed item dragged into `PUT /pools/{pool}/order` must 403, matching `select()`'s existing behaviour.
    - **Technical:** `ContentItemPolicy::pin()` denies pinning when `BorrowedMedia::isBorrowed($resource)` is true ("This photo comes from Google and cannot be pinned"), and `select()` correctly calls `$this->authorizeForUser($user, 'pin', $item)` before writing `STATE_PINNED`. `reorder()` is documented in its own comment as "the THIRD pin path" but only checks `where('user_id', $user->id)` and pool `kind` — it never calls the `pin` Policy ability, so a borrowed item dragged into the order list is written as pinned exactly the same as a hand-picked one, silently defeating D5. `deselect()` is correctly NOT gated (confirmed intentional per its own comment: exclusion carries no such rule), so this is scoped to `reorder()` only.
    - **Plain English:** There's a rule that a certain kind of photo (borrowed from Google, not really yours) can be shown but never "pinned" as a favourite. The dedicated pin button obeys that rule. The drag-and-drop reordering screen does the exact same thing — pinning — but forgot to check the rule, so dragging that photo into your order pins it anyway.
    - **Evidence:**
        ```php
        // select():
        $this->authorizeForUser($user, 'pin', $item);
        ```
        ```php
        // reorder(): no authorizeForUser call before this insert
        $rows[] = [
            'id' => (string) Str::uuid(),
            'section_id' => (string) $section->id,
            'item_id' => $itemId,
            'state' => SectionItem::STATE_PINNED,
            'sort_key' => (float) ($index + 1),
            'created_at' => now(),
        ];
        ```
        ```php
        // app/Policies/ContentItemPolicy.php
        return BorrowedMedia::isBorrowed($resource)
            ? Response::deny('This photo comes from Google and cannot be pinned.')
            : true;
        ```

- [ ] **#SEC-2** · P1 — Public pool wire emits stored URLs without `UrlSafety::safeHref`, unlike every sibling emit path
    - **Where:** app/Site/Pools/PoolResolver.php:862-874 (`sourcePlatforms`), 1544-1579 (`linkSet`), 1347 (`imageUrl`), 1107 (`f_link.url`)
    - **Affects:** Every public sitepage visitor viewing pool links, source-platform buttons, or item images sourced from stored owner/third-party URLs.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Route every URL destined for the public pool wire (`linkSet()`, `sourcePlatforms()`, the `f_link.url` outbound field, `imageUrl`, store `url`) through `UrlSafety::safeHref()` before emission, exactly as `ActionCandidates::itemCandidate()` and `ConnectionProfileUrl::for()` already do.
        - Add a regression test asserting public pool JSON never contains a `javascript:`/`data:`/`vbscript:` link target, mirroring the coverage `ActionCandidates` already has.
    - **Technical:** `PoolResolver` is the only emit path in this subsystem that builds public-wire URLs (`item_links.url`, `f_link.url`, connection `payload.url`, `image_url`, storefront `url`) without the shared `UrlSafety::safeHref()` scheme gate. `sourcePlatforms()` only checks `preg_match('~^https?://~i', $url)`, which passes a URL like `https://evil.example/` but does nothing to stop a non-http(s) scheme stored under a key this shallow check doesn't reach; `linkSet()` and the `imageUrl`/`f_link.url` assignments apply no gate at all. `UrlSafety` exists specifically so "the write-path allowlist... and the emit-path gate... cannot drift apart" — `ActionCandidates` and `ConnectionProfileUrl` both call it; `PoolResolver`, the highest-traffic public emit path, does not. Given pool items are sourced from diverse ingest connectors (platform scrapes, manual entry, source reconciler), this is the one place a write-path validation gap anywhere upstream would surface directly as an unsafe href on a live public page.
    - **Plain English:** Before publishing a clickable link on someone's public page, the system is supposed to double-check it's a normal web address and not a hidden command in disguise (like a booby-trapped link that runs code instead of opening a page). Most parts of the code do that check. This one — the part that assembles the links, store cards, and photos for the public page — skips it.
    - **Evidence:**
        ```php
        $links[] = ['platform' => $platform, 'url' => $url, 'source' => $isOwn ? 'own' : 'synced'];
        ```
        ```php
        return (object) ['platform' => self::wirePlatform($row->platform), 'url' => is_string($url) && preg_match('~^https?://~i', $url) ? $url : null];
        ```
        ```php
        'url' => $ov('f_link.url', $outboundUrl),
        ```
        ```php
        'imageUrl' => $row->image_url === null ? null : (string) $row->image_url,
        ```

- [ ] **#SEC-3** · P1 — Referer-header fallback lets a scripted (non-browser) caller forge analytics events for another site
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php:474-504 (`parseOriginHost`)
    - **Affects:** Every public site's analytics counts — a non-browser caller who knows or scrapes a site's `subdomain` can inject fabricated pageviews/clicks/impressions attributed to that site.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the Referer fallback and fail closed (`return null`) unless a valid `Origin` header is present. `Origin: null` (sandboxed iframe) should continue to be treated as absent.
        - Implement the shared-secret / signed-request path the code's own docblock already calls for on genuine server-to-server callers, rather than leaving Referer as the only fallback.
    - **Technical:** `originAllowed()`'s own docblock states the design rationale precisely: "Browsers cannot forge the Origin header from JS... A genuine server-to-server caller must be gated by a shared secret or signed request, never by public identifiers." That reasoning holds for a browser-JS attacker, but `parseOriginHost()` still falls back to the `Referer` header when `Origin` is absent — and unlike `Origin`, a direct HTTP client (curl, Python `requests`, any scripted caller) can set **either** header to an arbitrary value; neither is enforced by anything server-side for a non-browser caller. Since a site's `subdomain` is public (part of its own URL), an attacker can trivially construct `curl -H "Referer: https://victim.partna.au/" ...` against this public, unauthenticated endpoint and pass `originAllowed()`. This reopens the same class of forgery the 2026-07-24 SEC-1 fix (referenced in this file's own comments) closed for the `site_id`-based vector — the Referer fallback is a second, still-open door of the same shape.
    - **Plain English:** The system checks a header that says "I'm calling from this website" before it trusts an analytics event — like checking a return address on an envelope. A real web browser can't lie about that address. But a script running outside a browser (like a bot with a command-line tool) can write whatever return address it wants, including a competitor's or a victim's site name, and the system currently accepts that as proof.
    - **Evidence:**
        ```php
        // Fall back to Referer host (less reliable but acceptable as a secondary signal).
        $referer = $request->headers->get('Referer');
        if ($referer !== null && $referer !== '') {
            $host = parse_url($referer, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        }
        ```

## P2 — Should fix

- [ ] **#SEC-4** · P2 — Fresha ingest stores third-party free-text with no length cap
    - **Where:** app/Ingest/Connectors/FreshaConnector.php (`mapServiceItem`), app/Ingest/Projection/FreshaServiceProjector.php:61
    - **Affects:** `content.items` rows and sitepage rendering for owners with a Fresha connection.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add explicit max-length validation/truncation for `name` and `description` before yielding a Record.
        - Strip control characters and cap length in `FreshaServiceProjector::project()`'s `f_text` mapping as a second belt-and-suspenders check.
    - **Technical:** `mapServiceItem()` copies `name`/`description` from the vendor payload with only a non-empty check on `name`; no `max:`-style bound exists on either field before it is written into `Record`s and then `content.f_text` via `FreshaServiceProjector`. `HttpIo` (verified: manifest allowlist + `SafeUrlFetcher`, both gates enforced) already treats Fresha as untrusted for the transport layer, but the *content* of a successful response is trusted verbatim for length. A compromised or malicious Fresha venue listing can push an oversized description into the catalog store.
    - **Plain English:** We copy a vendor's business description into our own catalog without checking how long it is. If that description were absurdly long, we'd store all of it, bloating the database and potentially slowing the page down — like accepting a package without checking its size first.
    - **Evidence:**
        ```php
        $name = is_string($item['name'] ?? null) ? trim($item['name']) : '';
        if ($name === '') {
            return null;
        }
        ...
        'description' => is_string($item['description'] ?? null) ? $item['description'] : null,
        ```
        ```php
        'f_text' => $view->string('description') === null ? null : ['body' => $view->string('description')],
        ```

- [ ] **#SEC-5** · P2 — Dashboard duplicate-candidate query in `PoolResolver` doesn't scope `identity_candidates` to the current owner
    - **Where:** app/Site/Pools/PoolResolver.php:880-892
    - **Affects:** Dashboard-only "possible duplicate" view — hardening against a future write path, not currently reachable.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->where(fn ($w) => $w->where('li.user_id', $site->user_id)->orWhere(...))`-style scoping (or join through the already user-scoped `$ids` set on both sides) to make the query structurally same-tenant, not just same-tenant-by-convention.
        - Add a regression test with a cross-tenant `identity_candidates` fixture asserting it never surfaces.
    - **Technical:** The query joins `content.identity_candidates` to `content.items` on both sides but filters only on `dismissed_at`/`removed_at` and an OR-membership check against `$ids` (itself scoped to the current owner's items). Verified: the sole writer, `ProjectionWriter::recordCandidates()`, always inserts `left_item_id`/`right_item_id` from the same `$itemByCoord` map for one `$userId`'s ingest run, and `ItemMerger` only dismisses candidate rows on merge rather than reassigning them — so no current write path can produce a cross-tenant candidate row. This is real defense-in-depth: the query itself carries no structural guarantee, only a convention upheld by a single writer today.
    - **Plain English:** This is a dashboard feature that shows "these two items might be duplicates." Right now the only code that creates that pairing always keeps both items with the same owner, so nothing leaks today — but the database query itself doesn't enforce that, so a future change to how those pairings are written could accidentally let one owner's dashboard show another owner's content.
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

- [ ] **#SEC-6** · P2 — `ShortLinkExpander` caches the expanded destination URL without secret redaction
    - **Where:** app/Routing/ShortLinkExpander.php:74-103
    - **Affects:** Redis cache entries for expanded short links, up to 24h — a secret-bearing destination URL (e.g. `?token=...`) persists in plaintext for that window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply `SecretParams::minimiseUrl($candidate)` (or `redactUrl()`) before `Cache::put()` and before returning `$final`.
    - **Technical:** `expandIfShort()` follows the short-link redirect chain via `SafeUrlFetcher` and caches `finalUrl` verbatim. `SecretParams` exists precisely to strip secret-bearing query/fragment params from user-controlled URLs before they persist — `LinkInBioImporter` applies it in two places in the same subsystem — but `ShortLinkExpander` never calls it, so a secret embedded in a shortened link's destination survives in Redis for up to `SUCCESS_TTL_SECONDS` (86400s).
    - **Plain English:** We follow a shortened link to its real destination and save that full address — including any secret codes it might carry — for a whole day. Anyone with read access to that cache would see those secret codes. We already have a tool that blanks out secrets like this; it just isn't used here.
    - **Evidence:**
        ```php
        $result = $this->fetcher->tryFetch($url);
        $candidate = $result['finalUrl'] ?? null;

        if (is_string($candidate)
            && $candidate !== ''
            && ! $this->isShort($candidate)
            && preg_match('~^https?://~i', $candidate) === 1
        ) {
            $final = $candidate;
        }

        Cache::put($key, $final ?? '', $final === null ? self::FAILURE_TTL_SECONDS : self::SUCCESS_TTL_SECONDS);
        ```

- [ ] **#SEC-7** · P2 — `LinkInBioImporter` persists/dispatches unredacted URLs in two spots, redacts in two others in the same file
    - **Where:** app/Routing/Importers/LinkInBioImporter.php:107 (`ImportRun::start`), 530 (`CommerceProbeJob::dispatch`)
    - **Affects:** `routing.import_runs.source_url` (durable DB column) and `CommerceProbeJob`'s queued payload — both may carry secret-bearing query/fragment params from a pasted bio page or a link it contained.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply `SecretParams::minimiseUrl($pages[0])` before `ImportRun::start(...)`.
        - Apply `SecretParams::minimiseUrl($url)` before `CommerceProbeJob::dispatch(...)`.
    - **Technical:** The same file already redacts via `SecretParams::minimiseUrl()` twice — once for the `detail.pages` array (line ~202) and once in the drop-reason log (line ~576) — with the second carrying an explicit comment: "minimiseUrl, not the raw URL: a bio link is Scope B PII and this line goes to the shared log stream." The two remaining raw uses (`ImportRun::start`'s `source_url` column, and `CommerceProbeJob`'s queued `$url`) are the same class of user-supplied URL, persisted/enqueued without the same treatment — an inconsistency within one file, not a designed exception.
    - **Plain English:** This file has a "black out secret codes" step for links before saving or logging them — and uses it correctly in two places. In two other places, doing almost the same thing, it forgot to use it, so a secret code in a pasted link could end up saved in a database column or handed to a background worker unredacted.
    - **Evidence:**
        ```php
        $runId = ImportRun::start((string) $user->id, $kind, $pages[0]);
        ```
        ```php
        CommerceProbeJob::dispatch((string) $context->user->id, $url);
        ```

- [ ] **#SEC-8** · P2 — `IriCanonicalizer::trimPastedJunk()`'s URL-extraction regex runs before the length cap, and is quadratic on adversarial input
    - **Where:** app/Routing/IriCanonicalizer.php:302-321 (`trimPastedJunk`), :82-85 (`canonicalize`)
    - **Affects:** Every internal `canonicalize()` caller that doesn't sit behind a Form Request `max:` rule (e.g. `ShopProductSeeder`, `MediaParentSuggester`, `GoogleBusinessAutoSync`, `StoreBrandSeeder` — all process third-party/scraped URLs).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move (or duplicate) the `strlen($input) > 2048` check to run *before* `trimPastedJunk()`'s regex scan, not after, so every caller gets the same bound regardless of whether it sits behind `RouteLinkRequest`.
        - Anchor/bound the regex where practical to reduce worst-case work independent of the length gate.
    - **Technical:** `canonicalize()` calls `trimPastedJunk($input)` first, then checks `strlen($raw) > 2048` — so the unanchored regex `~[a-z][a-z0-9+.-]*://\S+~i` runs against the full, unbounded `$input` before any size gate applies. This regex is not classically catastrophic (the literal `://` anchor prevents nested-quantifier exponential blowup), but it is quadratic (O(n²)) on a long non-matching string via repeated start-position retries. The direct user-facing entry point (`RoutingController` via `RouteLinkRequest`) already validates `'url' => ['required','string','min:3','max:2048']` before the controller runs, which fully bounds that path — but several other callers (`ShopProductSeeder`, `MediaParentSuggester`, `GoogleBusinessAutoSync`, `StoreBrandSeeder`) pass third-party/scraped URL strings into `canonicalize()` with no confirmed upstream length bound.
    - **Plain English:** Before checking whether a pasted address is too long, the system first runs it through a pattern-matching scan that gets slower the longer the text is. For the main "paste a link" screen this doesn't matter — the length is already checked earlier. But a few other, less-visible code paths that process links scraped from other websites don't have that same early check, so an unusually long value there could make the scan take noticeably longer than it should.
    - **Evidence:**
        ```php
        public function canonicalize(string $input): Iri
        {
            $raw = $this->trimPastedJunk($input);
            if ($raw === '' || strlen($raw) > 2048) {
                return Iri::reject($input, $raw === '' ? 'malformed' : 'too_long');
            }
        ```
        ```php
        } elseif (preg_match('~[a-z][a-z0-9+.-]*://\S+~i', $s, $m)) {
        ```

- [ ] **#SEC-9** · P2 — `SuggestionsController::acceptGoogleListing` gates on an inline `AuthorizationException` instead of a Policy
    - **Where:** app/Http/Controllers/Api/Routing/SuggestionsController.php:369-372
    - **Affects:** Reservation-connection creation via the "use this OpenTable link" suggestion; falls outside `PolicyCoverageTest`'s structural sweep.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `reservations`-capability denial into a Policy `create` ability on an `IntegrationConnection` skeleton (`new IntegrationConnection(['user_id' => $user->id, ...])`).
        - Call `$this->authorizeForUser($user, 'create', $skeleton)` and remove the manual `throw new AuthorizationException`.
    - **Technical:** This correctly fails closed (an explicit throw, not `authorize()`'s silent-pass-under-null-user bug), so it is not an active bypass — but it lives entirely outside the Policy framework the doctrine mandates, meaning it is not covered by `PolicyCoverageTest`/`InlineAuthBypassGuardTest`-style structural sweeps and can silently drift out of sync with how every other capability gate in the codebase is enforced.
    - **Plain English:** There's a "can this owner use bookings?" check being done by hand instead of through the standard permissions system everyone else uses. It works correctly today, but because it's a one-off, none of the automated tests that make sure every permission check is present and correct know to look at it.
    - **Evidence:**
        ```php
        $denied = RoutingCapabilityGate::denialFor($user, 'reservations');
        if ($denied !== null) {
            throw new AuthorizationException($denied);
        }
        ```

- [ ] **#SEC-10** · P2 — Shop brand mutation endpoints resolve by user-scoped lookup but never call `authorizeForUser`
    - **Where:** app/Http/Controllers/Api/Platforms/ShopController.php (`updateBrand`, `catalog`, `setProducts`, `addProduct`, `removeProduct`)
    - **Affects:** Authenticated shop integration endpoints; `content.storefronts` rows and integration-connection lifecycle.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a Policy for the shop store/connection resource and call `authorizeForUser($user, 'update'|'delete', $resource)` before each mutation, mirroring the pattern `removeBrand()` already uses for its anchor-connection delete (`$this->authorizeForUser($user, 'delete', $anchor)`).
    - **Technical:** `updateBrand()`, `catalog()`, `setProducts()`, `addProduct()`, and `removeProduct()` resolve the target via `$this->shop->store($user, $id)` or `$this->brandMap($this->currentUser($request))`, both scoped to the current user — this structurally prevents cross-tenant access today, the same pattern already accepted elsewhere in the codebase as needing defense-in-depth (see the `SitePolicy` comment on `UpsertWorkplaceRequest`: "ownership is structurally guaranteed... [call authorizeForUser] anyway"). `removeBrand()` in the same controller already calls `authorizeForUser` for its connection delete, showing the pattern is known and partially applied — just not consistently.
    - **Plain English:** Several "edit my shop" actions check that a store belongs to you by only fetching stores you own — which works today — but they skip the extra permission check the rest of the system uses as a backup. It's like a shop that only has one lock on the door instead of the standard two; today that's enough, but there's no second check if the first one is ever loosened.
    - **Evidence:**
        ```php
        $store = $this->shop->store($user, $id);
        if (! $store) {
            return $this->error('Brand not found.', 404);
        }
        ...
        $collectionId = $this->content->upsertStore($store, (string) $user->id);
        ```
        ```php
        // removeBrand(), same file — the pattern this controller already knows:
        $anchor = $this->shop->anchorFor($user, $store->externalRef);
        if ($anchor !== null && $anchor->surface_key !== ShopConnections::LEGACY_SURFACE) {
            $this->authorizeForUser($user, 'delete', $anchor);
            $anchor->delete();
        }
        ```

- [ ] **#SEC-11** · P2 — `AnalyticsController::pageview` has no bot filter and no dedup, unlike every other event type
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php:45-74
    - **Affects:** Public analytics ingest queue and job volume for every site.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply the same `isBotUserAgent()` drop used by every other event type, or add a bounded Redis dedup window matching `click()`'s pattern.
    - **Technical:** `click()`, `sectionSeen()`, `sectionDwell()`, `itemSeen()`, `actionSeen()`, `actionTap()`, and `ping()` all call `isBotUserAgent()` before ingesting; `pageview()` explicitly does not, per its own inline comment ("pageview intentionally has NO bot filter and NO dedup (preserved)"). This is a deliberate carry-forward rather than an oversight, but it remains the one unauthenticated, un-throttled-at-the-code-level amplification point on the highest-traffic analytics path.
    - **Plain English:** Every other type of visitor-tracking event has a filter that ignores obvious bots. The basic "someone loaded this page" event skips that filter, so a script hitting this endpoint repeatedly gets recorded every single time with no slowdown.
    - **Evidence:**
        ```php
        // NOTE: pageview intentionally has NO bot filter and NO dedup (preserved). A bot
        // UA still records a pageview today; changing that is a separate metrics decision.
        $event = $this->buildEvent(
            type: AnalyticsEvent::TYPE_PAGEVIEW,
        ```

- [ ] **#SEC-12** · P2 — Public analytics returns 422 for an invalid `site_id` but 404 for a valid-but-unpublished one, enabling a validity oracle
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php:403-423
    - **Affects:** Public anti-enumeration posture for unpublished-site IDs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Make an unpublished-site response indistinguishable in status code from a nonexistent-`site_id` response (both 404), including at the Form Request validation layer.
    - **Technical:** A `site_id` that fails the Form Request's `Rule::exists('pgsql.site.sites', 'id')` check is rejected by Laravel's own validation-failure response (422) before the controller runs at all. A `site_id` that exists but is unpublished reaches `resolvePublishedSite()` and gets an explicit 404. An attacker holding a candidate UUID can therefore distinguish "not a real site" (422) from "a real, hidden site" (404) — a status-code oracle for site-ID validity, contradicting the doctrine that public endpoints must never differ between missing and not-public.
    - **Plain English:** If you try a made-up ID you get one kind of error; if you try a real ID for a page that's hidden, you get a different kind of error. That difference lets someone test whether a given ID belongs to a real (if hidden) site — a small crack in the "you can't tell what's real" rule public pages are supposed to follow.
    - **Evidence:**
        ```php
        private function resolvePublishedSite(array $data, ?JsonResponse &$error): ?Site
        {
            $site = $this->resolveSiteFromData($data);

            if (! $site) {
                $status = ! empty($data['site_id']) ? 422 : 404;
                $error = $this->error('Site not found', $status);

                return null;
            }

            if (! $site->is_published) {
                $error = $this->error('Site not found', 404);

                return null;
            }
        ```

- [ ] **#SEC-13** · P2 — Service-layout reorder requests accept unbounded collection sizes
    - **Where:** app/Http/Requests/Api/User/Services/ReorderServiceLayoutRequest.php:12-36, app/Http/Requests/Api/Staff/UserSite/Services/StaffReorderServiceLayoutRequest.php:12-36
    - **Affects:** Authenticated professional and staff service-layout reorder endpoints.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add explicit caps, e.g. `'categories' => ['required', 'array', 'max:64']` and `'categories.*.service_ids' => ['present', 'array', 'max:512']`, tuned against `partna.limits.pagination.services_max` (500).
    - **Technical:** Both the owner and staff Form Requests validate `categories` and `categories.*.service_ids` as arrays with per-element `uuid` rules but no `max` count on either dimension. `services_max` elsewhere in config caps a user's total services at 500, but nothing stops a caller submitting a reorder payload with a far larger array of syntactically valid but non-owned UUIDs, which the controller must still iterate and validate.
    - **Plain English:** There's no limit on how many items someone can list in one "reorder my services" request. The system will still check every single one, so an oversized request can tie up server time processing something much bigger than any real layout would ever need.
    - **Evidence:**
        ```php
        'categories' => ['required', 'array'],
        'categories.*.id' => ['nullable', 'uuid'],
        'categories.*.service_ids' => ['present', 'array'],
        'categories.*.service_ids.*' => ['required', 'uuid'],
        ```

- [ ] **#SEC-14** · P2 — `DevInsightsController::index` resolves and reads a site's analytics with no `authorizeForUser` call
    - **Where:** app/Http/Controllers/Api/User/Analytics/DevInsightsController.php:62-82
    - **Affects:** The authenticated professional's own dev-insights analytics endpoint.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Call `$this->authorizeForUser($professional, 'view', $site)` immediately after resolving `$site`, mirroring the sibling `UserSiteActionsController`.
    - **Technical:** `$site = $this->currentSite($this->currentUser($request))` resolves structurally to the authenticated user's own site (no cross-tenant path exists today), but the doctrine — and the codebase's own established pattern in `UserSiteActionsController::show` (`$this->authorizeForUser($professional, 'view', $site)`) — calls for the Policy check even when ownership is structurally guaranteed, as defense-in-depth against a future change to how `currentSite()` resolves.
    - **Plain English:** This analytics page trusts the hallway routing to prove you're allowed in, instead of also checking your badge at the door the way the neighbouring page does. Today the hallway only ever leads to your own room, so nothing is exposed yet — but there's no independent check if that ever changes.
    - **Evidence:**
        ```php
        $site = $this->currentSite($this->currentUser($request));
        ```
        ```php
        // app/Http/Controllers/Api/User/SiteManagement/UserSiteActionsController.php
        $site = $this->currentSite($professional);
        $this->authorizeForUser($professional, 'view', $site);
        ```

- [ ] **#SEC-15** · P2 — `SafeUrlFetcher`'s redirect-hop config default (8) exceeds its own documented/coded bound (5)
    - **Where:** config/partna.php (`http_fetch.max_redirects`), app/Services/Http/SafeUrlFetcher.php:27
    - **Affects:** All user- or third-party-supplied URL fetches through `SafeUrlFetcher`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the config default to 5 to match `SafeUrlFetcher::MAX_REDIRECTS`, or update the class docblock/const together with a test pinning the two in agreement.
    - **Technical:** `SafeUrlFetcher::MAX_REDIRECTS` is `5`, and the class docblock says "redirects are followed manually (max 5)." The live config default overrides this to 8 via `config('partna.http_fetch.max_redirects', self::MAX_REDIRECTS)`. Each hop is still SSRF-re-validated, so this is not a bypass, but it is verified drift between the documented security bound and the actual runtime default — deeper redirect chains before the guard stops following, at zero enforced upper bound test.
    - **Plain English:** The safety limit on how many times a pasted link can redirect before we give up is written as "5" in the code's own explanation, but the actual setting in use allows 8. Each hop is still checked for safety, so this isn't a hole — it's just more rope than the design says it should have.
    - **Evidence:**
        ```php
        // config/partna.php
        'max_redirects' => (int) env('PARTNA_HTTP_FETCH_MAX_REDIRECTS', 8),
        ```
        ```php
        // app/Services/Http/SafeUrlFetcher.php
        private const MAX_REDIRECTS = 5;
        ```

- [ ] **#SEC-16** · P2 — Bot protection ships disabled (`mode=off`, `driver=null`) by default
    - **Where:** config/partna.php (`bot_protection`)
    - **Affects:** Public mutation endpoints (enquiries, leads, early-access, reports, subscriptions) on any deploy that doesn't explicitly set the bot-protection env vars.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm production sets `BOT_PROTECTION_MODE=enforce` with a real driver, and consider a boot-time guard (mirroring the throttle fail-closed pattern already in `AppServiceProvider`) that hard-fails if production runs with `mode=off`.
    - **Technical:** The config defaults `driver` to `'null'` and `mode` to `'off'` — with these unset, no bot verification runs at all on the public unattended-input endpoints this lens explicitly calls out (category 9). This mirrors the codebase's own precedent for `PARTNA_THROTTLE_ENABLED` (which does hard-throw at boot if misconfigured in production) but has no equivalent guard.
    - **Plain English:** The bot-blocking system exists but is switched off unless someone explicitly turns it on for a given environment. If a deploy forgets to set that switch, public forms are open to automated spam with nothing checking whether a submission came from a real person.
    - **Evidence:**
        ```php
        'bot_protection' => [
            'driver' => env('BOT_PROTECTION_DRIVER', 'null'),       // null | turnstile | hcaptcha | fake
            'mode' => env('BOT_PROTECTION_MODE', 'off'),          // off | shadow | enforce
            'fail_open' => (bool) env('BOT_PROTECTION_FAIL_OPEN', false),
        ```

- [ ] **#SEC-17** · P2 — `Workplace::$fillable` includes the tenancy PK/FK `site_id`
    - **Where:** app/Models/Core/Site/Workplace.php:47-51
    - **Affects:** Every workplace-card write path — currently unreachable, but a mass-assignment allowlist gap on the tenancy key.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `'site_id'` from `$fillable`; keep `firstOrNew(['site_id' => ...])`/`updateOrCreate(['site_id' => ...], ...)` (which set it via the query condition, not mass assignment) as the only write paths.
    - **Technical:** Verified: no Form Request in `app/Http/Requests` validates a `site_id` field for Workplace, and every current write path (`UserWorkplaceController::upsert`, `previousWebsite`) builds an explicit `$attributes` allowlist or uses `firstOrNew`/`updateOrCreate` keyed on `site_id` from the resolved current site — never from request input. So this is not exploitable today. It remains a genuine allowlist gap: the inline comment asserts safety by convention ("All write paths take site_id from the auth-resolved current site") rather than the model enforcing it, and any future controller that calls `Workplace::create($request->validated())` directly would silently reopen a tenant-boundary hole.
    - **Plain English:** The field that says "which professional's page does this card belong to" is technically allowed to be set directly from user input on this model, even though nothing actually does that today. It's like leaving a door unlocked in a house where every current resident happens to always use a different entrance — safe for now, but only because everyone remembers not to use that door.
    - **Evidence:**
        ```php
        protected $fillable = [
            // PK is site_id — must be fillable so updateOrCreate/firstOrNew can set it
            // when creating a new row. All write paths take site_id from the auth-resolved
            // current site, so mass-assignment of this column is safe.
            'site_id',
            'name',
        ```

- [ ] **#SEC-18** · P2 — `Site::$fillable` includes the moderation-enforcement fields `moderation_state` and `unpublished_at`
    - **Where:** app/Models/Core/Site/Site.php:104-124
    - **Affects:** Moderation workflow integrity — currently unreachable, but a mass-assignment allowlist gap on staff-only enforcement columns.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `'moderation_state'` and `'unpublished_at'` from `$fillable`; set them explicitly (`$site->moderation_state = ...`) only from staff/moderation services after an `authorizeForUser` check.
    - **Technical:** Verified: no user-facing Form Request in `app/Http/Requests` accepts `moderation_state` or `unpublished_at`, and the only writers found (`Jobs/Moderation/SuspendSiteJob.php` and system code) never route through user-supplied request data. `moderation_state` gates `active|warned|hidden` enforcement and `unpublished_at` is a compliance visibility timestamp — neither is a normal user-editable field, so their presence in `$fillable` is a latent gap rather than an active one: a future controller hydrating `Site` from `$request->validated()` with an over-broad rule set (or `$request->all()`) could let a professional lift a staff-issued hide.
    - **Plain English:** The "trust and safety team has hidden this page" switch is technically writable from user input on this model, even though no current screen lets a user touch it. It's the same kind of unlocked-but-unused door as the workplace card above — safe today by convention, not by design.
    - **Evidence:**
        ```php
        protected $fillable = [
            'subdomain',
            'is_published',
            'unpublished_at',
            'settings',
            'moderation_state',
            'show_branding',
            'charlie_enabled',
            'services_auto_sync_enabled',
            'booking_mode',
            'manual_booking_url',
            'shop_link_mode',
        ];
        ```

## P3 — Nice to have

- [ ] **#SEC-19** · P3 — OpenTable query-based detectors (`rid`, `restRef`) capture unvalidated values despite being declared `NumericId`
    - **Where:** app/Catalog/Definitions/Opentable.php:57-89
    - **Affects:** Catalog link ingestion — stored `rid`/`restRef` identifiers for OpenTable connections.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Constrain the `->query('rid')`/`->query('restRef')` captures to a numeric format, matching the path detector's `(?<rid>\d+)` grammar.
    - **Technical:** The path detector enforces `#^/restaurant/profile/(?<rid>\d+)#`, but the two query-based detectors capture the raw query value with no format check. `IdentifierKind::NumericId` is declared on the surface but (verified via repo-wide search) is not consumed anywhere as a runtime validation gate — it is descriptive metadata only. Impact is limited: the captured value is interpolated into a fixed-host canonical URL template (`https://www.opentable.com/restaurant/profile/{rid}`), so it cannot redirect to a different origin, and it is stored via parameterized queries.
    - **Plain English:** One way of recognising an OpenTable link checks that the restaurant's ID number is actually a number; a second way, using a slightly different link shape, doesn't check that at all. It should use the same rule everywhere.
    - **Evidence:**
        ```php
        array_map(
            fn (string $tld) => Detector::url("opentable.{$tld}")
                ->query('rid')
                ->captures('rid')
                ->from(IdentifierSource::Query)
                ->strength(EvidenceStrength::DeepLinkWithSlug),
            self::TLDS,
        ),
        ```

- [ ] **#SEC-20** · P3 — `PoolController::reorder` validates inline rather than via a Form Request
    - **Where:** app/Http/Controllers/Api/Content/PoolController.php:162-165
    - **Affects:** Maintainability/consistency of a state-mutating route; not currently a bypass (rules are already explicit and bounded).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract into a dedicated Form Request under `app/Http/Requests`, keeping the existing `array`/`max:200`/`uuid` rules.
    - **Technical:** `reorder()` uses `$request->validate([...])` inline instead of resolving a Form Request class. The inline rules are already explicit and bounded (`max:200`), so this is a convention deviation, not an active gap.
    - **Plain English:** This endpoint checks its input with a rule list written directly in the controller instead of in the project's standard "validation file" location. It works fine today, but keeping it in the standard place makes it easier for the next person to find and update.
    - **Evidence:**
        ```php
        $data = $request->validate([
            'itemIds' => ['required', 'array', 'max:200'],
            'itemIds.*' => ['uuid'],
        ]);
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Pool controller & resolver hygiene:** #SEC-2, #SEC-5, #SEC-20
    - **Why grouped:** Same two files (`PoolController.php`, `PoolResolver.php`); none touch an authorization mechanism — URL sanitisation, query scoping, and a Form Request extraction.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Analytics ingest hygiene:** #SEC-11, #SEC-12
    - **Why grouped:** Same file (`AnalyticsController.php`), same functions area, both are status-code/bot-filter tweaks rather than trust-boundary rewrites.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Routing/SafeUrlFetcher hygiene:** #SEC-6, #SEC-7, #SEC-8
    - **Why grouped:** All three live in `app/Routing`, all are secret-redaction-consistency or regex-hardening fixes reusing the existing `SecretParams` helper.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Config drift & defaults:** #SEC-15, #SEC-16
    - **Why grouped:** Both are `config/partna.php` default-value corrections, reviewed together in one pass.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Ingest/catalog validation gaps:** #SEC-4, #SEC-19
    - **Why grouped:** Same root-cause pattern — missing format/length constraints on values captured from a third-party ingest source before they reach storage.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 6 — Service layout request caps:** #SEC-13
    - **Why grouped:** Single-item session — mirrored fix across the owner/staff twin Form Requests, low risk, no dependency on other bundles.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#SEC-1 — `PoolController::reorder` bypasses the `pin` Policy ability** · touches authorization (Policy enforcement).
- **#SEC-3 — Referer-fallback analytics origin-bind forgery** · touches the core trust-boundary/authentication mechanism for the entire public analytics surface.
- **#SEC-9 — Inline `AuthorizationException` instead of a Policy** · touches authorization.
- **#SEC-10 — Shop brand mutation endpoints missing `authorizeForUser`** · touches authorization.
- **#SEC-14 — `DevInsightsController` missing `authorizeForUser`** · touches authorization.
- **#SEC-17 — `Workplace::$fillable` exposes tenancy FK `site_id`** · mass-assignment/privilege-escalation category (lens category 2, authorization-adjacent).
- **#SEC-18 — `Site::$fillable` exposes moderation-enforcement fields** · mass-assignment/privilege-escalation category (lens category 2, authorization-adjacent).
