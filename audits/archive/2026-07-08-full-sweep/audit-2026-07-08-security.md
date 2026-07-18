# Security Audit — 2026-07-08

**Branch:** audit-fix/middleware-2026-07-06
**Lens:** Security — auth boundaries, tenant isolation, mass assignment, inbound callbacks, secrets, injection, SSRF, upload safety, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Http/Controllers/Concerns/ResolvesSiteFromRequest.php
- app/Http/Controllers/Concerns/ResolvesSubdomainFromHost.php
- app/Http/Requests/Api/PublicSite/Analytics/{ClickRequest,PageviewRequest}.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Controllers/Api/PublicSite/{PublicDocumentDownloadController,BootstrapController,PublicConfigController}.php
- app/Http/Requests/Api/User/Content/UploadContentImageRequest.php
- app/Http/Requests/Concerns/SniffsFileMimeType.php
- app/Http/Requests/Api/User/Uploads/{UploadDesignMediaRequest,UploadImageRequest}.php
- app/Http/Controllers/Api/User/Account/UserSelfController.php
- app/Http/Controllers/Api/User/SiteManagement/{UserSectionBlockController,UserWorkplaceController,CustomDomainController,UserSiteController}.php
- app/Http/Controllers/Api/User/Analytics/UserAnalyticsController.php
- app/Http/Controllers/Api/User/Notifications/UserEmailSubscriptionController.php
- app/Http/Controllers/Api/Staff/StaffSite/{StaffAccountDeletionController,StaffNotificationController}.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/{StaffSiteManagementController,StaffDataExportController}.php
- app/Policies/{UserSelfPolicy,SitePolicy}.php
- app/Providers/AppServiceProvider.php
- routes/api/{staff,user}.php
- app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php
- app/Services/Streaming/{StreamingTokenManager,TwitchApiClient,KickApiClient}.php
- app/Services/Media/{LogoProcessorClient,MediaDiskResolver}.php
- app/Services/Design/LogoAutoGrabber.php
- app/Services/Design/Scan/BrandScanClient.php
- app/Services/Platforms/{GoogleBusinessService,MenuSource}.php
- cloudflare-worker/src/index.js
- config/partna.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 6 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEC-1** · P1 — Content-library image upload skips the finfo byte-sniff every sibling upload path applies
    - **Where:** app/Http/Requests/Api/User/Content/UploadContentImageRequest.php:16-27
    - **Affects:** Any professional uploading to the content library; a disguised non-image file (renamed extension, forged `Content-Type`) can pass validation and enter the media/R2 storage pipeline, later served back to site visitors.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `use SniffsFileMimeType;` to `UploadContentImageRequest`.
        - Implement `withValidator(Validator $v)` calling `$this->assertImageMimeBytes($this->file('image'), $v, 'image')`, matching `UploadImageRequest` and `UploadDesignMediaRequest`.
    - **Technical:** `mimes:jpeg,png,webp` trusts the client-declared type/extension only. `SniffsFileMimeType::assertImageMimeBytes()` (`app/Http/Requests/Concerns/SniffsFileMimeType.php`) is the house pattern — a `finfo(FILEINFO_MIME_TYPE)` check against a strict allowlist, called from `withValidator()`. A repo-wide sweep of every Form Request using `mimes:jpeg,png,webp` turns up exactly three files; `UploadDesignMediaRequest` and `UploadImageRequest` both use the trait, `UploadContentImageRequest` does not — a clean, isolated gap rather than a systemic one.
    - **Plain English:** Two of the three "upload a picture" forms on the site check the actual bytes of the file to make sure it's really a photo, not something pretending to be one. The content-library upload form skips that check — it only looks at the filename and the label the browser sends, both of which a bad actor can fake. The fix is a few lines: add the same real check the other two forms already have.
    - **Evidence:**
        ```php
        // UploadContentImageRequest — no SniffsFileMimeType, no finfo
        'image' => [
            'required',
            'file',
            'image',
            'mimes:jpeg,png,webp',
            "max:{$imageMaxKb}",
        ],
        ```

## P2 — Should fix

- [ ] **#SEC-2** · P2 — `resolveSiteFromData()` returns a site by `site_id` alone with no subdomain cross-check when `subdomain` is absent from the payload
    - **Where:** app/Http/Controllers/Concerns/ResolvesSiteFromRequest.php:20-39
    - **Affects:** Any future caller of the shared `ResolvesSiteFromRequest` trait that omits `subdomain` — not exploitable today because the two current callers (analytics `pageview`/`click`/`sectionSeen`/`ping`) always populate `subdomain` from `X-Site-Subdomain` and are additionally protected by `AnalyticsController::originAllowed()` (Origin/Referer host check against the resolved site).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - When `site_id` is present without `subdomain`, require the caller to supply both, or document in the trait's docblock that bare-`site_id` resolution is only safe for server-internal callers that have already tenant-scoped the request.
    - **Technical:** The cross-check (`whereRaw('lower(subdomain) = ?', ...)`) only runs `if (! empty($data['subdomain']))`. Today this is fully closed off in practice: `AnalyticsController::originAllowed()` independently validates the browser's unforgeable `Origin` (or `Referer`) header against the resolved site's canonical host set, and when no Origin/Referer is present at all, it requires *both* `site_id` and `subdomain` to have survived the resolver's cross-check. So the specific attack DeepSeek described (an attacker-supplied bare `site_id` writing cross-tenant analytics) is already blocked at the controller layer, not just the trait. This finding is scoped narrowly to the trait's own contract for a *hypothetical future caller* that doesn't replicate `originAllowed()`.
    - **Plain English:** A shared helper function can look up a website by its internal ID number alone, without double-checking it matches the website the request claims to be from. Today, every place that uses this helper also has a second independent check (an unforgeable browser header) that closes the gap — so nothing is exploitable right now. But the helper itself doesn't enforce that safety net, so a future feature built on it could reopen the hole without anyone noticing.
    - **Evidence:**
        ```php
        if (! empty($data['site_id'])) {
            $query = Site::query()->whereKey($data['site_id']);

            // When a subdomain is also present, cross-check to prevent IDOR:
            // an attacker submitting a victim's site_id under a different subdomain.
            if (! empty($data['subdomain'])) {
                $query->whereRaw('lower(subdomain) = ?', [strtolower($data['subdomain'])]);
            }

            $site = $query->first();

            if (! $site && ! empty($data['subdomain'])) {
                return null;
            }

            return $site;
        }
        ```

- [ ] **#SEC-3** · P2 — RUM beacon endpoint has no Origin/Referer validation, unlike every other analytics endpoint on the same controller
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php:364-392
    - **Affects:** Real-user-monitoring metrics integrity — any caller, from any origin, can post fabricated performance data tagged to any handle.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Resolve the handle to a published site and apply the same `originAllowed()` check used by `pageview`/`click`/`sectionSeen`/`ping` before logging.
    - **Technical:** `pageview`, `click`, `sectionSeen`, and `ping` all call `resolvePublishedSite()` + `originAllowed()` before recording an event. `rum()` validates only that `handle` is a DNS-safe-looking string via regex, then logs directly — no site resolution, no origin check. Impact is metrics-pipeline pollution (fake performance numbers), not data exposure or a write to `analytics.*` tables (this is log-only), so it doesn't rise to a tenant-boundary failure, but it is a real, easily-fixed inconsistency in an otherwise-hardened controller.
    - **Plain English:** Every other "visitor activity" endpoint on this controller checks the return address before accepting data, to stop one site's visitors from being used to spam another site's numbers. The page-speed reporting endpoint forgot that check — anyone can send fake "this page loaded fast/slow" reports for any professional's handle from anywhere.
    - **Evidence:**
        ```php
        public function rum(Request $request): JsonResponse
        {
            if ($this->isBotUserAgent($request->userAgent())) {
                return $this->success(['message' => 'ok'], 200);
            }

            $payload = $request->json()->all();
            $handle = isset($payload['handle']) ? (string) $payload['handle'] : null;
            if (! $handle || ! preg_match('/^[a-z0-9-]{1,63}$/i', $handle)) {
                return $this->success(['message' => 'ok'], 200);
            }
            // No originAllowed() check — no site resolution at all
            try {
                Log::info('rum', [
                    'handle' => strtolower($handle),
        ```

- [ ] **#SEC-4** · P2 — Cloudflare Worker's reserved-subdomain list is a hand-maintained mirror of `config/partna.php`, with no drift check
    - **Where:** cloudflare-worker/src/index.js:44-48 (RESERVED set), config/partna.php:70 (`reserved_subdomains`)
    - **Affects:** Subdomain squatting — a label present in the PHP reserved list but missing from the Worker's hardcoded `Set` becomes claimable as a user handle on `*.partna.au`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a CI check (GitHub Action or pre-commit) that diffs `config/partna.php`'s `reserved_subdomains` against the Worker's `RESERVED` set and fails the build on drift.
        - Alternatively, have `SyncSubdomainToKvJob` write the reserved list to KV so the Worker reads it at runtime instead of a bundled literal.
    - **Technical:** The Worker's own comment acknowledges this is a manual, unenforced mirror: "KEEP IN SYNC: a subdomain missing here is sent to KV and 404s instead of passing through to the apex origin... This is a manual mirror." A future reserved label added to `config/partna.php` (e.g. a new internal tool subdomain) that isn't hand-copied into the Worker bundle becomes registrable by any user, whose sitepage would then render at that address.
    - **Plain English:** There's a list of forbidden website names (like "admin" or "billing") kept in two places — one in the backend code, one pasted by hand into the Cloudflare edge script. If someone adds a new forbidden name to the backend list and forgets to paste it into the edge script, a user could register that name and their page would show up at what looks like an official platform address. Nothing currently checks that the two lists match.
    - **Evidence:**
        ```javascript
        // Mirrors `reserved_subdomains` in config/partna.php (EDGE-6/EDGE-11). KEEP IN
        // SYNC: a subdomain missing here is sent to KV and 404s instead of passing
        // through to the apex origin. This is a manual mirror — when config changes,
        // update this set (or wire a build step that generates it from the PHP config).
        const RESERVED = new Set([
        ```

- [ ] **#SEC-5** · P2 — Several self-service site-management mutation controllers skip `authorizeForUser`, deviating from the sibling `UserSiteController` pattern
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php:112-218 (upsert/remove), app/Http/Controllers/Api/User/SiteManagement/UserWorkplaceController.php:42-181 (upsert/destroy), app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php:37-175 (store/verify/setPrimary/destroy)
    - **Affects:** Consistency of the authorization doctrine — not currently exploitable (see Technical), but a structural gap versus the codebase's own established pattern for the same underlying models.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - `UserSectionBlockController::upsert/remove`: call `$this->authorizeForUser($pro, 'update', $block ?? new Block(['user_id' => $pro->id, 'site_id' => $site->id]))`.
        - `UserWorkplaceController::upsert/destroy`: call `$this->authorizeForUser($professional, 'update', $site)` (Workplace has no direct `user_id`; gate on the owning Site, consistent with how `SitePolicy` resolves ownership for `SiteMedia`/`SiteSubdomainAlias`).
        - `CustomDomainController::store/verify/setPrimary/destroy`: call `$this->authorizeForUser($this->currentUser($request), 'update', $site)` — mirroring `UserSiteController::update()` at lines 47/168/189, which already does exactly this for the same `Site` model.
    - **Technical:** `Block::class`, `Workplace::class`, and `Site::class` are all registered to `SitePolicy` in `AppServiceProvider::boot()`, and `SitePolicy::update()` enforces both ownership *and* `denyIfPendingDeletion($actor)`. However, none of these three controllers actually call `authorizeForUser` — they rely entirely on implicit scoping (`$this->currentSite($pro)`, `$this->currentUser($request)->site`) which is safe today (a caller can never reach another tenant's resource) and on the route-group-wide `EnforcePendingDeletionReadOnly` middleware (`routes/api/user.php:39`), which already blocks every non-GET/HEAD/OPTIONS request for a `pending_deletion` account with a 423. So the specific "pending-deletion bypass" risk these three controllers were flagged for doesn't currently exist — it's covered at the middleware layer. What remains is a genuine doctrine-consistency gap: `UserSiteController` calls `authorizeForUser` on the identical `Site` model for the identical class of mutation (subdomain, force-publish, custom-domain-adjacent fields); these three controllers don't, so a future change to `EnforcePendingDeletionReadOnly`'s exemption list or route group membership would silently reopen a real gap with no policy-layer backstop.
    - **Plain English:** Three settings screens (site sections, workplace card, custom domain) let a user change their own data. Right now a site-wide safety net (a rule that freezes all edits while an account is scheduled for deletion) covers all three, so nothing is currently broken. But one sibling screen — the main site-settings screen — has its OWN individual lock in addition to the site-wide net, and these three don't. If the site-wide net is ever changed or these screens are ever moved to a different route group, these three would have no backup lock at all. The fix is adding the same individual lock the sibling screen already has.
    - **Evidence:**
        ```php
        // UserSectionBlockController::upsert() — no authorizeForUser before firstOrNew/save
        $block = DB::transaction(function () use ($pro, $site, $data, $blockType, $nextIsLive) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$site->id}"]);
            $block = Block::query()->firstOrNew([...]);
        ```
        ```php
        // UserWorkplaceController::upsert() — no authorizeForUser
        $workplace = Workplace::firstOrNew(['site_id' => (string) $site->id]);
        $workplace->fill($attributes);
        $workplace->save();
        ```
        ```php
        // CustomDomainController::store() — no authorizeForUser; contrast with
        // UserSiteController.php:47 which does: $this->authorizeForUser($professional, 'update', $site);
        public function store(SetCustomDomainRequest $request): JsonResponse
        {
            $site = $this->siteOrFail($request);
            $domain = $request->validated()['domain'];
        ```

- [ ] **#SEC-6** · P2 — `BrandScanClient`'s SSRF guard is explicitly advisory (TOCTOU); the authoritative check lives in a separate Worker repo and hasn't been built yet
    - **Where:** app/Services/Design/Scan/BrandScanClient.php:37-53
    - **Affects:** The brand-scan pipeline (used by `WebsiteStyleAnalyzer` to auto-grab design/logo cues from a professional's previous website) — a successful DNS-rebinding attack between the PHP-side check and the Worker's actual Browser Run fetch could cause the Worker to load an internal address.
    - **Effort:** L (~1–2d, cross-repo)
    - **What to do:**
        - Implement IP-boundary enforcement inside the `partna-brand-scan` Worker itself (validate the resolved IP before the Browser Run fetch, and again on every redirect hop).
        - Keep `SafeUrlFetcher::assertPublicUrl()` here as a cost-saving pre-filter, but add a breadcrumb log when it passes so any Worker-side rejection is traceable back to this call.
    - **Technical:** The code's own comment is explicit: "This is NOT the authoritative SSRF boundary — it's TOCTOU/DNS-rebind-advisory only and never sees the Worker's own redirect chain. The authoritative guard belongs in the partna-brand-scan Worker itself (separate cross-repo follow-up)." `assertPublicUrl($url)` resolves the hostname and rejects private/reserved ranges at call time, but the real outbound fetch happens later, inside Cloudflare's Browser Run infrastructure, in a different repository this audit doesn't cover. The attacker here would need to be an authenticated professional supplying their own "previous website" URL, and the fetch happens through Cloudflare's infra rather than directly against Partna's network — narrowing but not eliminating the exposure. This is a real, currently-open gap (not yet fixed), tracked in-code but worth carrying as a standing finding until the cross-repo work lands.
    - **Plain English:** Before asking a cloud browser to open a professional's old website (to copy their logo and colors), the backend does a quick check: "is this a normal public web address, not something pointing at internal infrastructure?" But there's a small timing window between that check and when the cloud browser actually visits the page, where a malicious DNS trick could swap the answer — a bait-and-switch. The code honestly documents that this check is a helpful filter, not a real lock, and that the real lock needs to be built into the cloud-browser service itself. That work hasn't happened yet.
    - **Evidence:**
        ```php
        // Cost + junk-URL pre-check, enforced regardless of caller: today's only
        // caller (WebsiteStyleAnalyzer::analyze) already validates the URL first,
        // but a future direct caller (CLI command, staff endpoint) must not be
        // able to ship an unvalidated URL to the Worker, and there's no point
        // spending a paid Browser Run on a private/invalid address. This is NOT
        // the authoritative SSRF boundary — it's TOCTOU/DNS-rebind-advisory only
        // and never sees the Worker's own redirect chain. The authoritative guard
        // belongs in the partna-brand-scan Worker itself (separate cross-repo
        // follow-up).
        $this->urlFetcher->assertPublicUrl($url);
        ```

## P3 — Nice to have

- [ ] **#SEC-7** · P3 — `MenuSource::entries()` accepts a raw string as a user identifier with no validation
    - **Where:** app/Services/Platforms/MenuSource.php:235-255
    - **Affects:** Future callers only — every current caller resolves an authenticated `User` model first.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Tighten the signature to accept only `User` (drop the `|string` union), or add an `@internal` docblock requiring callers to authorize before calling.
    - **Technical:** `entries(User|string $user)` uses whatever string it receives directly as `user_id` in a query with no ownership check. Today every caller (e.g. `MenuController`) passes a resolved, authenticated `User`, so there's no live path to exploit this. A future controller that passes a raw request parameter here would create an IDOR with no warning from the type system.
    - **Plain English:** A lookup function can take either a verified user record or a bare ID string typed in from anywhere. Today everyone hands it the verified record, so it's fine. But it doesn't check — it trusts whatever it's given, so a future shortcut could accidentally let one user see another's online-ordering links.
    - **Evidence:**
        ```php
        private function entries(User|string $user): Collection
        {
            $userId = (string) ($user instanceof User ? $user->id : $user);

            if (! isset($this->entriesCache[$userId])) {
                $this->entriesCache[$userId] = IntegrationConnection::query()
                    ->where('user_id', $userId)
        ```

- [ ] **#SEC-8** · P3 — `GoogleBusinessService` uses the raw `Http::` client instead of `SafeUrlFetcher` for three Google API calls
    - **Where:** app/Services/Platforms/GoogleBusinessService.php:138, 377, 417
    - **Affects:** Maintainability/defense-in-depth only — no current SSRF vector, since all three hosts (`places.googleapis.com`, `maps.googleapis.com`) are hardcoded string literals; only path/query parameters are interpolated from stored data.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a class-level comment documenting the intentional bypass (hardcoded Google hosts, Google-specific auth headers), so a future refactor doesn't assume this is an oversight.
    - **Technical:** Every other outbound call in `app/Services/Platforms/` routes through `SafeUrlFetcher`; these three are the sole exception. Since the base URL can't currently be influenced by user input, there's no live SSRF path — the risk is purely that a future change making the host configurable would inherit no SSRF guard and no CI signal to catch it.
    - **Plain English:** Nearly every outbound web request in this part of the code goes through a central safety checkpoint — except three calls to Google's map/places APIs, which go straight out. That's fine today because the destination is hardcoded to Google's own servers. It's just inconsistent, and if someone later makes that destination configurable, there'd be no safety net and no automatic warning.
    - **Evidence:**
        ```php
        ->get('https://places.googleapis.com/v1/places/'.rawurlencode($placeId));
        ```
        ```php
        ->get('https://places.googleapis.com/v1/'.($photo['ref'] ?? '').'/media', [
            'maxWidthPx' => 1200,
        ```
        ```php
        $res = Http::timeout(5)->get('https://maps.googleapis.com/maps/api/streetview/metadata', [
        ```

- [ ] **#SEC-9** · P3 — Inline-SVG logo screening relies on regex pattern-matching, not a structural XML parser
    - **Where:** app/Services/Design/LogoAutoGrabber.php:274-283 (svgIsSafe + SVG_FORBIDDEN, at the method definition near line 314)
    - **Affects:** Auto-grabbed inline-SVG logo candidates scraped from a professional's previous website — if the regex misses a vector, a crafted SVG could reach the logo-processor pipeline.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - After the regex passes, parse with `DOMDocument::loadXML(..., LIBXML_NONET | LIBXML_NOENT | LIBXML_NSCLEAN)` and reject on parse failure or a non-`<svg>` root.
        - Add a max element-depth check to guard against entity/node-explosion patterns.
    - **Technical:** `svgIsSafe()` uses two regexes — `SVG_FORBIDDEN` (blocks `<script`, `on*=` handlers, `javascript:`, `<image`, `data:`) and an external-`href` check permitting only fragment references. These catch common SVG-XSS vectors but can't reason about nested depth, entity expansion, or XML-level tricks the way a real parser can. The SVG is ultimately shipped as raw bytes (MIME `image/svg+xml`) to the self-hosted logo processor, which should be the authoritative sanitizer — but this is the last PHP-controlled checkpoint before the bytes leave the backend.
    - **Plain English:** When Partna auto-discovers a logo from a professional's old website, it runs a quick pattern-match "sniff test" on inline SVG images to catch obvious script-injection tricks. Pattern matching is fast but not exhaustive. A proper structural reader that understands the file's actual document layout would catch more of the exotic tricks a pattern can miss.
    - **Evidence:**
        ```php
        private function svgIsSafe(string $svg): bool
        {
            if (stripos($svg, '<svg') === false || preg_match(self::SVG_FORBIDDEN, $svg)) {
                return false;
            }
            return ! preg_match('/(?:xlink:)?href\s*=\s*["\'](?!#)/i', $svg);
        }
        ```

- [ ] **#SEC-10** · P3 — `LogoProcessorClient` embeds up to 300 bytes of raw response body in its exception message
    - **Where:** app/Services/Media/LogoProcessorClient.php:47-51
    - **Affects:** Error responses from the self-hosted logo-processor container — if a misconfiguration ever causes that container to echo back unexpected content, up to 300 bytes of it lands in an exception message that may be captured by Nightwatch.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Drop the response-body substring from the exception message; keep only the HTTP status code. Log the body separately at `debug` level with a cap if needed for troubleshooting.
    - **Technical:** `LogoProcessorException` is constructed with `mb_substr((string) $response->body(), 0, 300)` baked into the message. The processor is a trusted, self-hosted service, so this is defense-in-depth rather than an active leak — but any `report()`/error-tracking hook that persists exception messages will retain that fragment indefinitely.
    - **Plain English:** When the logo-processing service returns an error, the client copies up to 300 characters of its response straight into the error message, which can end up in permanent logs. The HTTP status code alone ("it returned a 500") is enough to start debugging without carrying along whatever that response body happened to contain.
    - **Evidence:**
        ```php
        if (! $response->successful()) {
            throw new LogoProcessorException(
                "Logo processor returned HTTP {$response->status()}: ".mb_substr((string) $response->body(), 0, 300)
            );
        }
        ```

- [ ] **#SEC-11** · P3 — Streaming clients' generic `catch (\Throwable)` blocks log raw exception messages, inconsistent with their own hardened per-request error paths
    - **Where:** app/Services/Streaming/StreamingTokenManager.php:97-104, app/Services/Streaming/TwitchApiClient.php:82-89, app/Services/Streaming/KickApiClient.php:100-107
    - **Affects:** Log-retention hygiene for streaming integrations; low actual credential exposure (see Technical).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'message' => $e->getMessage()` in each generic catch block with the exception class name only, matching the status-only pattern already used in the adjacent `! $response->successful()` branches in the same files.
    - **Technical:** `TwitchApiClient::getLiveHandles()` and `KickApiClient::getLiveHandles()` already handle non-2xx responses with a privacy-conscious, status-only log (explicitly commented: "don't persist body content into Nightwatch retention"). Their outer `catch (\Throwable $e)` blocks — reached only for transport-level failures (DNS/connect errors, JSON decode failures), since HTTP error responses are handled above and return early — still log `$e->getMessage()`. Note the actual credential-leak risk here is lower than it first appears: `StreamingTokenManager::refreshToken()` sends `client_secret` via `Http::asForm()->post(...)` (POST body), not the URL/query string, so a ConnectException-style message embedding the request URI would not include the secret. The finding is a genuine but low-severity hygiene inconsistency, not a confirmed credential leak.
    - **Plain English:** When these streaming integrations hit an unexpected network error, they write the raw technical error text into permanent log storage — even though the same files already know better and log only "it failed with status X" for ordinary API errors a few lines away. The secret key itself isn't in that text (it's sent a safer way), but tidying up the leftover raw-message logging keeps the whole file consistent and avoids surprises if the request shape ever changes.
    - **Evidence:**
        ```php
        // StreamingTokenManager.php
        } catch (\Throwable $e) {
            report($e);
            Log::error('streaming.auth_failure', [
                'platform' => $platform,
                'message' => $e->getMessage(),
            ]);
        ```
        ```php
        // TwitchApiClient.php
        } catch (\Throwable $e) {
            report($e);
            Log::error('streaming.api_error', [
                'platform' => 'twitch',
                'message' => $e->getMessage(),
            ]);
        ```

- [ ] **#SEC-12** · P3 — Public document downloads skip the subdomain isolation check entirely when `X-Site-Subdomain` is absent
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicDocumentDownloadController.php:31-40
    - **Affects:** Document downloads for professionals who've uploaded public documents (price lists, resumes, etc.) to their sitepage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Require `X-Site-Subdomain` on all requests, or resolve the subdomain from the `Host` header as a fallback rather than skipping the check silently.
    - **Technical:** When the header is absent, the isolation check is skipped and the document is served on `pool`/`is_active`/`is_published` alone. In practice this is low-impact: documents in `SiteMedia::POOL_DOCUMENTS` are, by design, publicly downloadable content on a *published* site — there's no distinct "authorized vs unauthorized" audience being bypassed, since the endpoint's entire purpose is unauthenticated public access once the site is live. The subdomain check adds cross-tenant consistency (ensuring a request "from" one site can't casually pull another's document ID) rather than gating access to genuinely private data. Still, the gap is real and the comment's rationale ("used only by internal/test callers") doesn't hold for a public, unauthenticated route reachable by anyone.
    - **Plain English:** A public file-download link is supposed to double-check that the request came from the right website before handing over the file. If that extra check header is simply missing, the download proceeds anyway. Since these files are meant to be publicly downloadable once a professional's page is live, this isn't a private-data leak — but it's a real inconsistency worth tightening up.
    - **Evidence:**
        ```php
        $requestedSubdomain = trim((string) $request->header('X-Site-Subdomain', ''));
        if ($requestedSubdomain !== '') {
            abort_unless(
                strtolower($site->subdomain) === strtolower($requestedSubdomain),
                404
            );
        }
        // When header is absent, subdomain check is silently skipped
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Upload MIME-sniff parity:** #SEC-1
    - **Why grouped:** single isolated file/trait fix, no natural pairing.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Outbound-platform HTTP hygiene:** #SEC-7, #SEC-8
    - **Why grouped:** both in `app/Services/Platforms/`, both are "tighten an unused-today permissiveness for future-caller safety."
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Error-message log hygiene:** #SEC-10, #SEC-11
    - **Why grouped:** same root cause (raw exception/response text embedded in log/exception messages) across Media + Streaming service clients.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Public-site subdomain/tenant trust-boundary hardening:** #SEC-2, #SEC-3, #SEC-12
    - **Why grouped:** all three are "an origin/subdomain cross-check exists elsewhere in the same subsystem but doesn't cover this specific path" — public-site controllers/concerns layer.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Cloudflare Worker reserved-subdomain drift guard:** #SEC-4
    - **Why grouped:** standalone fix, cross-repo (CI tooling) but not auth/money/migration/L-XL.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 6 — LogoAutoGrabber SVG hardened parsing:** #SEC-9
    - **Why grouped:** standalone fix, isolated to one method in Design services.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#SEC-5 — Site-management controllers skip authorizeForUser** · touches authorization directly (Policy-gate doctrine); spans three controllers and needs its own sign-off given the doctrine explicitly mandates this pattern.
- **#SEC-6 — BrandScanClient SSRF TOCTOU gap** · L-effort, cross-repo (partna-brand-scan Worker), and SSRF-adjacent — needs its own plan and sign-off rather than folding into a bundle.
