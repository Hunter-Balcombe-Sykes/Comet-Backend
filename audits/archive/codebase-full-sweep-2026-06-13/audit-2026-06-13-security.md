# Security Audit — 2026-06-13

**Branch:** development
**Lens:** Security: auth boundaries, tenant isolation, inbound callbacks, secrets, injection, SSRF, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `app/Http/Middleware/`
- `app/Policies/`
- `app/Providers/`
- `app/Http/Controllers/Concerns/`
- `app/Http/Controllers/Api/User/`
- `app/Http/Controllers/Api/PublicSite/`
- `app/Http/Controllers/Api/Staff/`
- `app/Http/Controllers/Api/Internal/`
- `app/Http/Controllers/Api/Webhooks/`
- `app/Http/Requests/`
- `app/Http/Resources/`
- `app/Services/SmartLinks/`
- `app/Services/BotProtection/`
- `config/`
- `cloudflare-worker/src/`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 10 complete
- P3 Low: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEC-1** · P1 — Analytics ingest IDOR: `site_id`-only requests bypass the subdomain cross-check, allowing cross-tenant data injection
    - **Where:** `app/Http/Controllers/Concerns/ResolvesSiteFromRequest.php:22–39`; `app/Http/Requests/Api/PublicSite/Analytics/PageviewRequest.php:22–23`; same pattern in `ClickRequest`, `SectionSeenRequest`, `PingRequest`
    - **Affects:** All four analytics ingest endpoints (`pageview`, `click`, `sectionSeen`, `ping`). An attacker can record fabricated events (fake page views, fake link clicks, fake section impressions, fake session pings) against any professional's site by knowing or guessing their `site_id` UUID. This poisons every metric on the victim's analytics dashboard.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Make `subdomain` unconditionally required on all four analytics Form Requests (not `required_without:site_id`), and have `site_id` remain optional as a supplementary cross-check field.
        - Alternatively, keep the dual-field contract but in `resolveSiteFromData` return `null` (failure) when `site_id` is provided without a validatable `subdomain` — never resolve by UUID alone on a public, unauthenticated path.
        - Update the client-side beacon to always send the subdomain (it already sends `X-Site-Subdomain`; make that header non-optional at the SDK level).
    - **Technical:** `ResolvesSiteFromRequest::resolveSiteFromData()` contains a guard comment that accurately describes the IDOR risk and implements a cross-check — but only when both `site_id` and `subdomain` are present in the payload. The Form Requests declare both fields as `required_without:` the other, meaning sending `site_id` alone is valid. When only `site_id` is present, `$data['subdomain']` is empty, so the cross-check block is skipped entirely and the method returns any site matching the UUID — from any tenant. The `prepareForValidation()` method attempts to merge the subdomain from the route segment or `X-Site-Subdomain` header, so legitimate browser beacons will include it; an attacker crafting a raw POST can simply omit both the route segment and the header, making `subdomain` absent throughout. Category (3) — tenant isolation IDOR.
    - **Plain English:** The analytics form has two ways to identify whose site you're talking about: a secret room number (UUID) or the door label (subdomain). If someone gives only the room number, the system fetches the right room without checking whether you're actually standing at that door. A competitor could discover a professional's room number and then flood their visitor counter with fake numbers, making their analytics useless. The fix is to always require the door label, so the system can confirm the room number actually matches the door you're at.
    - **Evidence:**
        ```php
        // ResolvesSiteFromRequest.php — the guard only fires when subdomain is ALSO present
        if (! empty($data['site_id'])) {
            $query = Site::query()->whereKey($data['site_id']);
            // When a subdomain is also present, cross-check to prevent IDOR:
            if (! empty($data['subdomain'])) {
                $query->whereRaw('lower(subdomain) = ?', [strtolower($data['subdomain'])]);
            }
            $site = $query->first();
            // site_id was given with a subdomain but nothing matched — invalid input.
            if (! $site && ! empty($data['subdomain'])) {
                return null;
            }
            return $site;  // ← returns any site by UUID when subdomain is absent
        }
        ```
        ```php
        // PageviewRequest.php (identical pattern in Click/SectionSeen/PingRequest)
        'site_id'   => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')],
        'subdomain' => ['required_without:site_id',   'string', 'max:63'],
        ```

---

## P2 — Should fix

- [ ] **#SEC-2** · P2 — CORS regex pattern excludes the apex domain `partna.au` (no subdomain label), silently denying CORS from the marketing site
    - **Where:** `config/cors.php:21`
    - **Affects:** Browser requests originating from `https://partna.au` (the apex marketing and landing surface). Preflight fails unless `https://partna.au` is present in the `PARTNA_FRONTEND_ORIGINS` explicit env list.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'#^https://partna\.au$#i'` as a second entry in `allowed_origins_patterns`, independent of the wildcard subdomain pattern.
        - Belt-and-suspenders: also ensure `https://partna.au` is always in `PARTNA_FRONTEND_ORIGINS` so it is covered by the explicit allowlist even if the regex is absent.
    - **Technical:** The regex `#^https://[a-z0-9-]+\.partna\.au$#i` uses `+` (one-or-more) for the label group before `.partna.au`. The apex domain `https://partna.au` has zero labels before the public suffix, so it never matches. `https://www.partna.au` matches because `www` satisfies `[a-z0-9-]+`. If the `PARTNA_FRONTEND_ORIGINS` env var correctly lists `https://partna.au`, the explicit allowlist catches it — but if that env var is absent or misconfigured (see SEC-3 below for the load-order hazard), the apex domain has no fallback. Category (8) — CORS.
    - **Plain English:** The guest list says "anyone from a named room in the partna.au building is allowed in." The main reception desk at `partna.au` itself isn't a named room — it's the building entrance. If someone forgot to add the entrance to the VIP list, the main website can't call the API.
    - **Evidence:**
        ```php
        // config/cors.php:21 — requires at least one subdomain label; apex domain never matches
        'allowed_origins_patterns' => [
            '#^https://[a-z0-9-]+\.partna\.au$#i',
        ],
        ```

- [ ] **#SEC-3** · P2 — `MetadataParser` loads third-party HTML without `LIBXML_NONET`, leaving the XML parser free to make outbound network requests during document parse
    - **Where:** `app/Services/SmartLinks/MetadataParser.php:17–21`
    - **Affects:** Every SmartLink fetch and platform scrape that passes HTML through the parser. A crafted HTML page could attempt to trigger outbound requests from the PHP process during parsing, a variant of server-side request forgery via XML entity resolution.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pass `LIBXML_NONET | LIBXML_NOENT` as the third argument to `$dom->loadHTML(...)`.
        - Grep for any other `DOMDocument::loadHTML`/`load`/`loadXML` calls in the codebase and apply the same flags.
    - **Technical:** PHP 8.0+ disables external entity loading by default via the `libxml_disable_entity_loader()` change, so the worst-case XXE risk (local file read) is already mitigated at the runtime level. However, `LIBXML_NONET` additionally prevents the parser from making network connections during entity resolution or DTD fetching — this is a PHP-version-independent network-access guard. Without it, a carefully crafted page with an external DTD or entity reference could trigger a DNS or HTTP lookup from the PHP process, bypassing `SafeUrlFetcher`'s allowlist checks. The project already sets these flags in other XML-parsing paths, making the omission here a consistency gap. Category (7) — SSRF / outbound fetch.
    - **Plain English:** When our server reads a stranger's webpage to extract its title and preview image, the reading tool has a "may I call a phone number I find inside?" permission that isn't explicitly turned off. Modern PHP usually refuses to dial, but turning off the permission explicitly is a one-line insurance policy that doesn't depend on PHP version or configuration.
    - **Evidence:**
        ```php
        // MetadataParser.php:17–21 — no LIBXML_NONET flag
        $dom = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        // Force UTF-8 so multibyte titles/og survive parsing.
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        ```

- [ ] **#SEC-4** · P2 — `config/cors.php` calls `config('partna.frontend_origins')` at require-time before `partna.php` is loaded, causing `allowed_origins` to resolve to `[]`
    - **Where:** `config/cors.php:14`; `config/partna.php` (loads after `cors.php` alphabetically)
    - **Affects:** Any browser origin in `PARTNA_FRONTEND_ORIGINS` that is not a `*.partna.au` subdomain (e.g. `localhost:3000`, `app.example.com` dev overrides). Those origins are silently denied at the CORS layer even though they are correctly configured in the env var.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the cross-config reference with a direct `env()` call in `cors.php` that reads the same `PARTNA_FRONTEND_ORIGINS` env var `partna.php` uses: `array_values(array_filter(array_map('trim', explode(',', (string) env('PARTNA_FRONTEND_ORIGINS', '')))))`.
        - Or in `AppServiceProvider::boot()`, call `config(['cors.allowed_origins' => config('partna.frontend_origins', [])])` after all config is guaranteed loaded, so the CORS middleware picks up the corrected value at request time.
    - **Technical:** Laravel's `LoadConfiguration` bootstrap evaluates config files alphabetically. `cors.php` (`c`) is loaded before `partna.php` (`p`). When `cors.php` is evaluated, `config('partna.frontend_origins', [])` queries the repository before `partna.php` has been set — the repository returns the default `[]`. This value is then stored as `cors.allowed_origins`. The `HandleCors` middleware reads this config at request time, by which point the repository has been fully populated, but the stored value is already `[]` from the earlier evaluation. The `allowed_origins_patterns` wildcard regex still functions, so `*.partna.au` callers are unaffected — only explicit non-subdomain origins in the env list are silently dropped. Category (8) — CORS.
    - **Plain English:** Imagine two safes: the CORS safe (A) and the Partna settings safe (B). Safe A is opened first and tries to copy the guest list out of Safe B — but Safe B hasn't been filled yet, so it copies an empty list. By the time Safe B is filled, no one updates Safe A. Guests on the list never make it in. The fix is to have Safe A read the guest list from the original source (the environment variable) rather than waiting on Safe B.
    - **Evidence:**
        ```php
        // config/cors.php:14 — evaluated before partna.php is loaded; returns []
        'allowed_origins' => config('partna.frontend_origins', []),
        ```
        ```php
        // config/partna.php — loaded after cors.php; its value is never seen by cors.php at require time
        'frontend_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PARTNA_FRONTEND_ORIGINS', ''))
        ))),
        ```

- [ ] **#SEC-5** · P2 — Cloudflare Worker 301 alias redirect uses the KV `redirect` value as-is with no URL validation, enabling an open redirect if a KV entry is ever poisoned
    - **Where:** `cloudflare-worker/src/index.js:306–321`
    - **Affects:** Visitors following old subdomain alias links. A poisoned KV entry could silently redirect them to an arbitrary external origin. The 301 is permanent and browser-cached.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Before building `target`, validate that `entry.redirect` starts with `https://` and the hostname ends with `.partna.au` or is exactly `partna.au`: `const u = new URL(entry.redirect); if (u.protocol !== 'https:' || (!u.hostname.endsWith('.partna.au') && u.hostname !== 'partna.au')) { /* 404 */ }`.
        - Ensure `SyncSubdomainToKvJob` already writes validated URLs (it does per the code comment) — the Worker validation is defence-in-depth against KV poisoning, not the primary control.
    - **Technical:** The Worker comment confirms that `entry.redirect` is "always a bare origin" written by the single trusted writer `SyncSubdomainToKvJob`. However, KV entries can be overwritten via the Cloudflare dashboard, Workers KV API with a write key, or a supply-chain compromise. Without Worker-level validation, any `entry.redirect` value is accepted, and the `Location` header is set to `<entry.redirect><path><query>`. If the value were `https://evil.com`, every visitor to the aliased subdomain would be permanently redirected to the attacker's site. Because 301s are cached by browsers, the effect persists after the KV entry is corrected. Validation at the Worker costs one `URL` parse per alias request, which is negligible. Category (7) — SSRF / open redirect.
    - **Plain English:** The router has instructions stored in a ledger (KV) that say "if someone visits this old address, send them here instead." The router trusts whatever the ledger says without checking that the destination is one of our own addresses. If someone managed to change a ledger entry to point to a scam site, every visitor to that old address would be permanently sent to the scam — and their browser would keep going there even after we fixed the ledger, because browsers remember permanent redirects.
    - **Evidence:**
        ```js
        // cloudflare-worker/src/index.js:306–321 — no URL validation on entry.redirect
        if (entry.type === "alias" && typeof entry.redirect === "string") {
          // Preserve the deep link …
          const target = `${entry.redirect.replace(/\/$/, "")}${url.pathname}${url.search}`;
          const h = new Headers({
            Location: target,
            "Cache-Control": "max-age=0, must-revalidate",
          });
          applySecurityHeaders(h);
          return new Response(null, {status: 301, headers: h});
        }
        ```

- [ ] **#SEC-6** · P2 — Waitlist signup and email-subscribe forms lack the bot-protection honeypot and timing fields present on other public mutation forms
    - **Where:** `app/Http/Requests/Api/PublicSite/PublicEmailSubscribeRequest.php:26–37`; `app/Http/Requests/Api/PublicSite/PublicWaitlistSignupRequest.php:29–40`
    - **Affects:** The waitlist endpoint and the email subscription endpoint. Both trigger an outbound email (confirmation / marketing list add), making them attractive for spam relay and list-bombing attacks.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'website' => ['nullable', 'string', 'max:255']` (honeypot, must be empty on genuine submissions) and `'form_started_at_ms' => ['required', 'integer', 'min:0']` (epoch-ms timing check) to both Form Requests, matching `PublicCustomerLeadRequest` and `PublicEnquiryRequest`.
        - In the respective controllers, enforce the honeypot (`website` must be null) and a minimum elapsed-time check (`form_started_at_ms` must be at least N ms ago) before processing.
    - **Technical:** `PublicCustomerLeadRequest` (line 39–40) and `PublicEnquiryRequest` (line 34) both declare `website` (honeypot) and `form_started_at_ms` (timing gate) to cheaply filter automated submissions before any DB write or outbound email dispatch. The two email-collection forms (`PublicEmailSubscribeRequest`, `PublicWaitlistSignupRequest`) have neither field. An automated script can submit these forms at any volume limited only by route-level throttle, which is a coarser check. Adding the in-Form-Request layer provides a first line of defence before the throttle is even reached. Category (9) — rate limiting / bot protection on public endpoints.
    - **Plain English:** Our contact form and customer enquiry form have a hidden trapdoor: a fake text box that only bots fill in, plus a timer that rejects submissions that happen too fast for a human to type. Two other email-collection forms are missing both traps. Bots can flood them without tripping any wire beyond the basic speed limit — and each successful submission fires an outbound email from our mail server.
    - **Evidence:**
        ```php
        // PublicEmailSubscribeRequest.php — no honeypot, no timing field
        public function rules(): array
        {
            return [
                'email'     => ['required', 'email:rfc', 'max:255'],
                'full_name' => ['nullable', 'string', 'max:200'],
                'list_key'  => ['required', 'string', 'max:50', Rule::in(...)],
            ];
        }
        ```
        ```php
        // PublicCustomerLeadRequest.php — the established pattern
        'website'             => ['nullable', 'string', 'max:255'],          // honeypot
        'form_started_at_ms'  => ['required', 'integer', 'min:0'],           // timing check (epoch ms)
        ```

- [ ] **#SEC-7** · P2 — `StaffUserController::index()` returns `primary_email` and `phone` for every professional in the paginated list, exposing PII to all staff roles including read-only support staff
    - **Where:** `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:62–70`
    - **Affects:** All staff members with access to the professional list view. Email and phone are returned in the list payload regardless of the caller's staff role. `StaffAccountDeletionController::show()` explicitly excludes PII for the same reason this endpoint should.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `primary_email` and `phone` from the list-view map. Server-side search already runs `ILIKE` against these fields without needing to return them in the response.
        - If admin roles need these fields in the list, gate the inclusion behind a staff-role check (`$staff->isAdmin()`) so the payload differs by role.
        - The detail view (`StaffUserController::show()` → `UserStaffResource`) can continue returning full fields for admin-initiated detail lookups.
    - **Technical:** The per-row map at line 62–70 unconditionally includes `primary_email` and `phone`. The comment on `StaffAccountDeletionController::show()` states "support staff don't need staff identity; admin investigations can hit the DB directly" and explicitly `SELECT`s only non-PII columns. The list controller should apply the same principle: search can use email/phone as filter criteria on the server side without reflecting them back to the caller. Every staff member with a support badge currently receives a full PII dump for up to 100 rows per page. Category (10) — PII exposure in responses.
    - **Plain English:** The staff dashboard directory shows every professional's email address and phone number on the main search results page — like a reception desk that prints everyone's personal contact details on the same sheet as their room number. Support staff who only need to confirm an account exists don't need those details. Search by email still works on the server without displaying the result. Hiding the fields in the list and reserving them for the detail view limits PII to staff who actually need it.
    - **Evidence:**
        ```php
        // StaffUserController::index, line 60–70 — PII in every list row
        $professionals = $page->getCollection()->map(function (User $p) {
            $site = $p->site;
            return [
                'id'            => $p->id,
                'handle'        => $p->handle,
                'display_name'  => $p->display_name,
                'status'        => $p->status,
                'primary_email' => $p->primary_email,
                'phone'         => $p->phone,
                // ...
            ];
        });
        ```

- [ ] **#SEC-8** · P2 — `StaffSectionManagementController` operates on professional sections with query-scoped ownership but no `authorizeForUser` Policy call, bypassing `denyIfPendingDeletion()`
    - **Where:** `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffSectionManagementController.php:23–29` (`index`); same pattern in `upsert`, `reorder`, `remove`
    - **Affects:** Staff operations on section blocks (gallery, services, bio, etc.) for professionals whose accounts are in the 30-day deletion grace period. Without a Policy gate, `denyIfPendingDeletion()` never fires and staff can mutate sections for accounts mid-deletion.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `$this->authorizeForUser($staff, 'staffManage', $professional)` at the top of each method (`index`, `upsert`, `reorder`, `remove`), where `$staff` is resolved from `$request->attributes->get('partna_staff')`.
        - Ensure `UserPolicy::staffManage()` (or the relevant ability) calls `$this->denyIfPendingDeletion($professional)` in the policy body.
    - **Technical:** The controller correctly scopes all queries with `->where('user_id', $professional->id)` and `->where('site_id', $site->id)`, enforcing tenant isolation at the query level. The doctrine requires a Policy gate in addition to query scoping so that `BasePolicy::denyIfPendingDeletion()` (returns 423) fires when the professional's account is mid-deletion. Without the gate, staff can toggle, reorder, or create section blocks on an account that should be locked during its final 30-day window. This is category (2) — authorization / policy completeness, specific bypass: pending-deletion guard.
    - **Plain English:** The staff section editor correctly limits what it looks at (only sections belonging to the right professional) but never checks in with the central policy desk before making changes. The policy desk knows whether an account is in the process of being deleted and would lock the door. Without that check, staff can rearrange the furniture in a room that's already scheduled for demolition — which could interfere with the cleanup process.
    - **Evidence:**
        ```php
        // StaffSectionManagementController::index — no authorizeForUser before querying
        public function index(User $professional): JsonResponse
        {
            // Return ALL section blocks (active + inactive) so staff can toggle
            $sections = Block::query()
                ->where('user_id', $professional->id)
                ->where('block_group', 'sections')
                ->orderBy('sort_order')
                ->get();
        ```

- [ ] **#SEC-9** · P2 — `StaffLinkBlockManagementController::update()` and `destroy()` use inline `abort_unless` ownership checks instead of `authorizeForUser`, bypassing `denyIfPendingDeletion()`
    - **Where:** `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffLinkBlockManagementController.php:70–75` (`update`); `:85–90` (`destroy`)
    - **Affects:** Staff editing or deleting custom link blocks on a professional whose account is pending deletion. The inline check correctly enforces ownership but skips the `denyIfPendingDeletion()` guard in `BasePolicy`, returning 200 where 423 is expected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the inline `abort_unless(...)` in `update()` and `destroy()` with `$this->authorizeForUser($staff, 'update', $linkBlock)` / `authorizeForUser($staff, 'delete', $linkBlock)` where `$staff = $request->attributes->get('partna_staff')`.
        - Confirm `BlockPolicy` (or `BasePolicy`) handles the `update`/`delete` abilities and calls `denyIfPendingDeletion()`.
        - The block-type validation (`block_group === 'links'` etc.) can remain as a guard clause after the Policy gate, since it is business-logic validation rather than authorization.
    - **Technical:** The comment "scoped binding guarantees ownership" is correct — the Laravel route model binding on the `{linkBlock}` wildcard is scoped to `$professional`. The inline `abort_unless` then adds block-type checking on top. Neither of these calls `BasePolicy::denyIfPendingDeletion()`, which returns 423 and is the mandatory gate for resources owned by accounts in the deletion grace period. The pattern is the same anti-pattern flagged by CI for inline 403 aborts, expressed here as a 404. Category (2) — authorization / policy completeness, specific bypass: pending-deletion guard.
    - **Plain English:** The staff link editor checks "does this link block belong to the right professional and is it the right kind of block?" directly in code, like a security guard checking a photo ID at the door. It's correct, but it bypasses the central booking system that would flag "this account is being closed — no changes allowed." The fix is to run the ID through the booking system first, then do the photo-ID check.
    - **Evidence:**
        ```php
        // StaffLinkBlockManagementController::update, line 70–75
        // scoped binding guarantees ownership, but still enforce correct kind of block
        abort_unless(
            $linkBlock->user_id === $professional->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );
        ```

- [ ] **#SEC-10** · P2 — `SiteVisibilityController` resolves the professional's site via a query scope instead of `authorizeForUser`, bypassing `denyIfPendingDeletion()` on a user-facing mutation endpoint
    - **Where:** `app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php:25–30`
    - **Affects:** Professionals in the 30-day deletion grace period. They can toggle their site published/unpublished after deletion has been initiated. The `denyIfPendingDeletion()` guard never fires; the existing status check only catches `status !== 'active'`, which may not be set during the grace period.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Resolve the site first, then call `$this->authorizeForUser($professional, 'update', $site)` before mutating it.
        - Confirm `SitePolicy::update()` calls `$this->denyIfPendingDeletion($resource)` (or that the user-level policy checks it).
    - **Technical:** The controller correctly resolves the actor from `$request->attributes->get('professional')` (doctrine-compliant) and scopes the site query to `->where('user_id', $professional->id)`. However, `firstOrFail()` on the scoped query returns the site and proceeds directly to mutation without a Policy gate. This is a user-facing (non-staff) PATCH route in the `user.api` middleware group — the always-drop exception for staff-protected routes does not apply. The specific bypass is `denyIfPendingDeletion()`. Category (2) — authorization / policy completeness.
    - **Plain English:** The toggle that publishes or hides a professional's mini-site looks up the correct site by matching the owner's ID in the database, then changes the setting. It never checks with the central policy desk, which would know the account is being deleted. A professional who has requested account deletion can still flip their site back to "published" during the 30-day cooling-off period. Routing through the Policy makes that one call enforce all the business rules in one place.
    - **Evidence:**
        ```php
        // SiteVisibilityController::update, line 25–30
        $site = Site::query()
            ->where('user_id', $professional->id)
            ->firstOrFail();

        $site->published = (bool) $request->validated('published');
        $site->save();
        ```

- [ ] **#SEC-11** · P2 — `BotProtectionServiceProvider` Guard 4 (mode=off in production) only logs a `Log::warning`, does not refuse boot, silently disabling all bot protection if `.env.example` is copied verbatim
    - **Where:** `app/Providers/BotProtectionServiceProvider.php:62–70`
    - **Affects:** Every public mutation endpoint gated by `bot.token` middleware (enquiry, lead, waitlist, subscribe, report). If `BOT_PROTECTION_MODE=off` is deployed to production (the default in `.env.example`), all CAPTCHA verification is silently bypassed — `VerifyBotToken::handle()` short-circuits on the `mode=off` early-return.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Promote Guard 4 from `Log::warning` to a `throw new CaptchaConfigurationException(...)` (matching Guards 1–3) so the application refuses to boot when `BOT_PROTECTION_MODE=off` in production.
        - If a legitimate "disable bot-protection in production" escape hatch is needed, require an explicit acknowledgement env var (e.g. `BOT_PROTECTION_DISABLE_ACK=true`) so the soft-disable is always intentional and visible in the deploy diff.
    - **Technical:** Guards 1–3 in `runBootGuards()` call `throw new CaptchaConfigurationException(...)` and prevent the app from starting. Guard 4, documented with the comment "Soft warn — log only, do not refuse boot," emits a `Log::warning` and then allows the app to boot normally. Nightwatch surfaces `Log::warning` as breadcrumbs only, not as actionable alerts — this misconfiguration would not trigger a Nightwatch alert. The only observable effect is a log line that may be buried in startup noise. The intent behind Guards 1–3 (fail closed on misconfiguration) should apply to Guard 4 as well, because the consequence is identical: no bot protection on any protected public form. Category (9) — bot protection on public endpoints; also category (1) — authentication boundary: the guard is effectively an inbound-request validation bypass.
    - **Plain English:** The alarm system runs four self-tests on startup. Three of them refuse to open the building if they detect a problem. The fourth one — which detects that the alarm is turned off entirely — just leaves a sticky note and opens the building anyway. If a new server is set up by copying the example settings file, every public form accepts submissions with zero bot checking. Making the fourth test refuse to open the building (like the first three do) ensures this setting can never slip into production silently.
    - **Evidence:**
        ```php
        // Guard 4: mode=off in production. Soft warn — log only, do not refuse boot.
        // If .env.example was copied verbatim and BOT_PROTECTION_MODE left as 'off',
        // every bot.token:* endpoint silently accepts unlimited submissions.
        if ($env === 'production' && $mode === 'off') {
            Log::warning('bot_protection.mode_off_in_production', [
                'note' => 'BOT_PROTECTION_MODE=off disables all bot verification on every protected endpoint; set MODE=shadow or MODE=enforce in production.',
            ]);
        }
        ```

---

## P3 — Nice to have

- [ ] **#SEC-12** · P3 — Inline `$request->validate()` in staff mutation methods instead of Form Request classes; deviates from the architecture mandate and bypasses the Form Request lifecycle
    - **Where:** `app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:25–43` (`store`); `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:125–127` (`updateStatus`)
    - **Affects:** Staff notification creation and professional status changes. Validation rules live inline, are harder to test in isolation, and are invisible to tooling that inspects Form Request classes.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract the `store()` validation in `StaffNotificationController` into a `StoreStaffNotificationRequest` Form Request (complex: 15 rules including a category allowlist and conditional date constraints).
        - Extract `updateStatus()` validation into an `UpdateStaffUserStatusRequest` Form Request.
        - Note: `bulkUpdateStatus()` already calls `authorizeForUser` correctly; only the inline `$request->validate()` call needs replacing with a Form Request.
    - **Technical:** The architecture requires every `POST`/`PATCH`/`PUT` route to resolve a `FormRequest` class. `$request->validate()` is functionally equivalent for the rules listed but skips the Form Request's `authorize()` method, any `prepareForValidation()` normalisation hooks, and the ability to reuse or extend the class in tests. Category (6) — input validation form request architecture.
    - **Plain English:** Three staff tools write their validation rules directly in the controller file instead of putting them in the standard "form specification" files the rest of the codebase uses. It works, but the rules can't be reused, discovered by documentation tools, or overridden in tests. It's the equivalent of writing the building regulations on a sticky note in each room rather than in the permit office.
    - **Evidence:**
        ```php
        // StaffNotificationController::store — inline validate with 15 rules
        $data = $request->validate([
            'user_id'                 => ['nullable', 'uuid'],
            'type'                    => ['required', 'string', 'max:50'],
            'title'                   => ['required', 'string', 'max:255'],
            'body'                    => ['required', 'string', 'max:5000'],
            // … 11 more inline rules
            'category' => ['nullable', 'string', Rule::in(['policy_update', 'incident', 'feature_announcement'])],
        ]);
        ```
        ```php
        // StaffUserController::updateStatus — inline validate
        $data = $request->validate([
            'status' => ['required', 'string', 'in:active,suspended'],
        ]);
        ```

- [ ] **#SEC-13** · P3 — `StaffFeatureFlagController` re-checks staff attribute presence with `abort_if(... === null, 401)` in every method, redundant with the upstream `staff`/`staff.admin` middleware guarantee
    - **Where:** `app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php:26, 37, 49, 62`
    - **Affects:** Code clarity and consistency. The inline check is harmless today but sets a precedent for duplicate auth assertions that drift into authorization territory.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the four `abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated')` calls. The `staff` middleware is the correct and sufficient enforcement point.
        - If feature-flag mutations should be restricted to admin staff (not all staff), replace the inline `abort_if` with a Policy ability or a middleware group change (`staff.admin`) rather than an inline check.
    - **Technical:** The `staff` middleware sets `partna_staff` on the request attributes before the controller is reached. If the middleware passes, the attribute is guaranteed non-null. If the middleware somehow fails to set it (a middleware-chain misconfiguration scenario), the inline `abort_if` is defence-in-depth — but the correct defence-in-depth for that scenario is fixing the middleware stack, not guarding each controller method individually. The `StaffFeatureFlagOverrideController` has the same pattern. Category (2) — authorization correctness.
    - **Plain English:** Each feature-flag desk inside the staff office checks the visitor's badge a second time right after they've already been checked by the front door guard. The front door check is sufficient and guaranteed. The second check is harmless but inconsistent — and if someone reads the code, it implies the front door check isn't trustworthy. Remove the desk checks to keep the pattern uniform with every other staff controller.
    - **Evidence:**
        ```php
        // StaffFeatureFlagController::index, line 26 — repeated in store/update/destroy
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');
        ```

- [ ] **#SEC-14** · P3 — `PerTargetReportThrottle` hashes client IPs with plain `hash('sha256', $ip.'|'.$key)` while the analytics path uses `hash_hmac('sha256', $ip, $key)`, producing different hashes for the same IP across systems
    - **Where:** `app/Http/Middleware/Moderation/PerTargetReportThrottle.php:28`
    - **Affects:** Abuse-pattern correlation across the rate-limit and analytics pipelines. The same client IP produces different identifiers in the moderation throttle, the report service, and the analytics ingest path. Cross-system correlation (e.g. "this rate-limited reporter was also generating fake pageviews") is impossible.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Standardise on one scheme across the codebase. The `hash_hmac` approach in `HashesClientData` is the named trait-based standard; extend `HashesClientData` in `PerTargetReportThrottle` and call `$this->hashIp($request->ip())`.
        - Apply the same consolidation to `ContentReportService::hashIp()` (`app/Services/Moderation/ContentReportService.php:54`) and `VerifyBotToken::hashClientIp()` (`:173`) which both also use the concatenation scheme.
    - **Technical:** The analytics controller uses `HashesClientData::hashIp()` → `hash_hmac('sha256', $ip, config('app.key'))`. `PerTargetReportThrottle`, `ContentReportService`, and `VerifyBotToken` all use `hash('sha256', $ip.'|'.config('app.key'))` (concatenation SHA-256). The two produce different hash values for the same IP address, making it impossible to join moderation events with analytics events on the client identifier. While plain SHA-256 with a key appended is not practically exploitable for IP pseudonymisation (the key-at-end arrangement is resistant to length-extension for this use case), the inconsistency is a correctness gap in the analytics/fraud pipeline. Category (5) — secrets and data handling hygiene.
    - **Plain English:** The analytics department and the moderation department both blur customer faces before storing records, but they use different blur algorithms. If security reviews a case where the same person triggered a rate limit AND faked their analytics, the blurred faces won't match across departments. Picking one blurring method everywhere lets you connect the dots.
    - **Evidence:**
        ```php
        // PerTargetReportThrottle.php:28 — concatenation hash
        $ipHash = hash('sha256', $request->ip().'|'.config('app.key'));
        ```
        ```php
        // HashesClientData.php:14 — HMAC (the analytics standard)
        return hash_hmac('sha256', $ip, config('app.key'));
        ```
