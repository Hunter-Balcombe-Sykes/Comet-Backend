# Security: auth boundaries, tenant isolation, inbound callbacks, secrets, injection, SSRF, PII exposure

Hunt **tenant-boundary failures**, **secret leakage**, **unverified inbound-callback entry points**, **injection / SSRF**, and **PII exposure** across the API surface. This is the **highest-priority pre-pilot lens** — every finding is potentially user-visible and irreversible.

Partna runs on **Supabase JWT auth** (`Auth::user()` always returns null; resolved actor lives at `$request->attributes->get('professional')` via the `ResolveCurrentUser` trait's `$this->currentUser($request)`). The inbound-callback surface is deliberately small post-standalone-strip: `SupabaseAuthHookController`, `SupabaseEmailHookController`, and `bot.token`-gated internal endpoints. All Shopify/Stripe/commerce webhook paths have been removed.

## Use the lens prefix `SEC` for findings

Number them `SEC-1`, `SEC-2`, … sequentially across the whole audit. **P0 is the default tier for any confirmed tenant-boundary failure.**

## Partna Authorization Doctrine (deviations are findings)

1. **Supabase JWT auth.** `Auth::user()` ALWAYS returns null. Resolved actor lives at `$request->attributes->get('professional')` (legacy attribute key, kept deliberately) or via `$this->currentUser($request)` in the `ResolveCurrentUser` trait.
2. **Authorization through Policies, never inline.** No `abort_unless($x->user_id === $user->id, 403)`. Always `$this->authorizeForUser($user, 'verb', $resource)`. CI rejects inline 403 aborts in controllers.
3. **`authorizeForUser`, not `authorize`.** The standard `authorize()` calls `Gate::forUser(null)` which silently passes — only `authorizeForUser($user, ...)` works under Supabase JWT.
4. **Policies extend `BasePolicy`.** Not-owned → 404 (`denyAsNotFound()`). Pending-deletion → 423 (`denyIfPendingDeletion()`). MFA-gated abilities → `requiresAal2()` / `requiresFreshAal2()`.
5. **Policy registration in `AppServiceProvider::boot()`.** Every tenant-owned model needs `Gate::policy(Model::class, ModelPolicy::class)` or a justified `POLICY_EXEMPT` entry — CI-enforced by `PolicyCoverageTest`.
6. **403 vs 404.** 404 when a resource is missing or not owned by the actor (public endpoints especially — anti-enumeration). 403 only for role/type restrictions (staff-only) and explicit gate failures.

## Findings categories

### (1) Authentication boundary correctness

- `VerifySupabaseJwt` (`app/Http/Middleware/Auth/VerifySupabaseJwt.php`): JWKS key cache must be keyed by `kid` (not by URL alone); cached keys must be invalidated on key-set rotation; clock skew tolerance must be bounded; `aal` and `amr` claims extracted as request attributes for MFA gating downstream.
- **No bypass-on-empty-secret** is the cardinal rule. Any middleware that reads a secret from config and falls through to `$next($request)` when the value is empty is a P0. Apply this rule to: `VerifyBotToken` (`app/Http/Middleware/VerifyBotToken.php`), `VerifySupabaseAuthHookSignature` (`app/Http/Middleware/Auth/VerifySupabaseAuthHookSignature.php`), `VerifySupabaseEmailHookSignature` (`app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php`). Every one must use **`hash_equals` only** — never `===` / `==` — and must hard-fail (500 or 401) when the configured secret is absent.
- `RequireAal2` (`app/Http/Middleware/Auth/RequireAal2.php`): confirm it reads the `aal` request attribute set by `VerifySupabaseJwt`, not a separate DB lookup or re-decode of the JWT.
- `IdempotencyKey` middleware: confirm it scopes replay-detection by tenant (`user_id` + idempotency key), not key alone — a flat key namespace across tenants is an IDOR.
- Any path where tenant resolution diverges from the JWT claims (e.g., a query param `?user_id=X` that shadows the JWT-derived `user_id`) is a P0.

### (2) Authorization / policy completeness

- Tenant-owned models without a registered Policy (sweep `app/Models/**/*.php` against `Gate::policy` registrations in `AppServiceProvider::boot()`) — `PolicyCoverageTest` enforces this structurally; the finding here is any model that bypasses it via an unjustified `POLICY_EXEMPT` entry.
- Controllers using `authorize(...)` instead of `authorizeForUser($user, ...)` — silent pass under Supabase JWT.
- Inline `abort_unless($x->user_id === $user->id, 403)` in controllers — replace with a Policy gate.
- **Skeleton pattern missing on pre-create checks.** Before a resource row exists, build a skeleton instance (e.g., `new SiteMedia(['user_id' => $user->id, 'pool' => 'gallery'])`) and call `authorizeForUser($user, 'create', $skeleton)` rather than skipping authz entirely.
- Bulk endpoints that accept an array of IDs from the request body without re-authorizing each ID against the resolved actor's tenant set — a single Policy gate on the collection is not sufficient.
- Staff endpoints using `staff` / `staff.admin` middleware but missing an `authorizeForUser` call on individual resource operations (the middleware proves staff identity; a Policy proves they can act on this specific resource).

### (3) Tenant isolation / IDOR

- Queries of the form `Model::find($request->id)` or `Model::findOrFail($request->id)` without a `->where('user_id', $user->id)` scope or a Policy gate on the result.
- Cache keys that omit a tenant segment — `Cache::get('site:data')` instead of `Cache::get("site:{$userId}:data")` — on any path that returns user-specific data.
- **Media URLs** that are guessable (sequential IDs, predictable paths without signing) — media served from public storage must use opaque UUIDs or signed URLs.
- **Public endpoints** (`app/Http/Controllers/Api/PublicSite/`) that return different status codes for "this resource doesn't exist" vs "this resource exists but isn't public" — 404 in both cases; never 403 on unauthenticated endpoints (enumeration risk).
- Handle/subdomain resolution paths that serve content under an alias URL rather than 301-redirecting to the canonical — a stale alias must never serve profile data directly.
- Analytics ingest endpoints that accept a `site_id` or `user_id` from the request body without validating it resolves to the subdomain the request arrived on.

### (4) Inbound-callback trust boundary

The inbound-callback surface post-strip is small but critical. **Signature verification must happen before any body parse, log emit, or database write.**

- `SupabaseAuthHookController` (`app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php`) + `VerifySupabaseAuthHookSignature` middleware: confirm the HMAC is checked with `hash_equals` before any payload data is touched; confirm no bypass when the hook secret env var is empty.
- `SupabaseEmailHookController` (`app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php`) + `VerifySupabaseEmailHookSignature` middleware: same rules — signature before body, `hash_equals`, no empty-secret bypass.
- `VerifyBotToken` on internal endpoints: confirm the token comparison is `hash_equals`; confirm the token is a sufficiently random value read from config (not a hard-coded string); confirm missing-config is a hard failure, not a pass-through.
- Any webhook/hook controller that reads `$request->all()` or `$request->json()` before the signature middleware has run (middleware order matters — check `bootstrap/app.php` route middleware ordering).
- Payload size caps: hook controllers accepting unbounded request bodies without a size limit are a DoS vector.

### (5) Secrets handling & log hygiene

- Hardcoded credentials in source (`config/*.php`, `app/Services/**/*.php`, migrations). Sweep for: `Bearer `, `sk_`, `pk_`, `AKIA`, `AIza`, `xoxb-`, JWT-shaped strings, hex secrets longer than 32 chars.
- API tokens and signing secrets passed through `Log::*` calls — Nightwatch / log aggregator persistence risk. `PiiLogHygieneSweepTest` is the house pattern; new leak patterns should extend it rather than being flagged individually only here.
- `.env.example` containing real non-placeholder values (a value that isn't `your-key-here` / `null` / a clearly fake example).
- `env()` calls outside `config/` files — values won't be cached and can expose secrets in stack traces.
- `dd()` / `dump()` / `Log::debug` calls in production-path code touching auth-sensitive fields (`supabase_uid`, tokens, signing secrets).
- Exception renderers or `report()` hooks that include `$request->all()` in the payload — secrets in POST bodies end up in Nightwatch.

### (6) Input validation & injection

- Raw SQL fragments built from user-supplied data: `DB::raw($input)`, `DB::statement($input)`, `whereRaw($input)`, `orderByRaw($input)` without a strict allowlist — SQL injection risk.
- `->orderBy($request->input('sort'))` without an explicit column allowlist — SQL injection via column name injection.
- Shell-process invocations (`Symfony\Component\Process`, `Illuminate\Process`, backtick operator, `passthru`, `system`, `popen`, `proc_open`) with user-supplied arguments — command injection risk. Media processing pipelines (`app/Services/Media`) are the hot zone.
- File-path operations using user-supplied values without `realpath` / `basename` sanitisation — path-traversal risk in upload handlers (`app/Http/Controllers/Api/User/Uploads`).
- Form Request classes absent from endpoints that mutate state — validation bypass. Every `POST`/`PATCH`/`PUT` route must resolve a `FormRequest` class in `app/Http/Requests/`.
- Validation rules that accept unbounded free-text for fields stored in JSONB (`site.sites.settings`, block content) without length or format constraints — DB bloat + injection staging.
- JSONB path parameters passed through to raw queries (`->where(DB::raw("settings->>'$key'"), $value)` where `$key` is user-controlled) — SQL injection via JSONB path.

### (7) SSRF / URL fetching

**All outbound fetches of user-supplied or third-party-supplied URLs must route through `SafeUrlFetcher` (`app/Services/SmartLinks/SafeUrlFetcher.php`).** `SafeUrlFetcher` enforces: scheme ∈ {http, https}; host resolves to public IPs only (rejects RFC1918, loopback, link-local, cloud-metadata 169.254.169.254); redirects followed manually with per-hop re-validation (max 5 hops).

- Any `Http::get($url)` / `Http::post($url)` / `Guzzle->get($url)` / `file_get_contents($url)` where `$url` is sourced from user input, a stored user record, or an unsanitised third-party API response — and does NOT go through `SafeUrlFetcher` — is a P1.
- Platform connectors (`app/Services/Platforms/`): each scraper that accepts a URL from user-controlled data (stored platform connection URL, a URL submitted at integration-creation time). Confirm every such fetch goes through `SafeUrlFetcher`. The Instagram SSRF fixed 2026-06-03 is the cautionary tale — re-check `InstagramScraper` to confirm the fix held and no similar pattern exists in `BandcampScraper`, `EventbriteScraper`, `GenericShopScraper`, `HumanitixScraper`, `ShopifyScraper`, `SkoolScraper`, `WooCommerceScraper`.
- SmartLinks extractors (`app/Services/SmartLinks/Extractors/`): each extractor that makes a secondary HTTP request based on a parsed URL or an API response field (e.g., `OEmbedExtractor` following an oEmbed endpoint URL).
- Streaming API clients (`TwitchApiClient`, `KickApiClient` in `app/Services/Streaming/`): confirm they use hardcoded API base URLs (not user-supplied), and that any user-controlled channel/username is sent as a path segment or query param — not interpolated into a base URL that could be overridden.
- Cloudflare Worker (`cloudflare-worker/src/index.js`): confirm subdomain/handle values from KV are used only as routing keys, not as URL prefixes for backend fetches — a poisoned KV entry must not redirect requests to an arbitrary origin.
- Open-redirect risk: any endpoint that returns a `Location` header or `redirect_to` value derived from user input without an allow-list of target domains.

### (8) CORS / cookies / response headers

- `config/cors.php` `allowed_origins` set to `*` (or a too-broad wildcard) on routes that set cookies or return credentials — check both the main CORS config and any per-route overrides.
- `SecureHeaders` middleware (`app/Http/Middleware/SecureHeaders.php`) — confirm it applies to all routes including public-site and webhook routes; confirm `Strict-Transport-Security`, `X-Content-Type-Options`, `X-Frame-Options`, and `Content-Security-Policy` are present on authenticated responses.
- `Cookie::queue(...)` calls without `secure: true`, `httpOnly: true`, `sameSite: 'lax'|'strict'` — particularly in session or token-storage paths.
- Routes that mutate state via `GET` — CSRF bypass.
- Analytics ingest endpoints that accept cross-origin POST from any origin without validating the `Origin` or `Referer` maps to a known `*.partna.au` subdomain.

### (9) Rate limiting & bot protection on public endpoints

- Public endpoints that accept untrusted input without `throttle` middleware or bot-protection (`BotProtectionCoverageTest` is the house sweep pattern):
  - `PublicEnquiryController` — enquiry spam / lead harvesting.
  - `PublicCustomerLeadController` / `PublicEmailSubscriptionController` — list-bombing risk.
  - `PublicWaitlistController` — waitlist spam.
  - `PublicReportController` — abuse report flooding.
  - `PublicSite/AnalyticsController` — analytics ingest flooding (fire-and-forget job queue exhaustion).
- Any public endpoint that triggers an outbound email (enquiry confirmation, subscription confirmation) without rate limiting — email relay abuse.
- `PerTargetReportThrottle` middleware (`app/Http/Middleware/Moderation/PerTargetReportThrottle.php`): confirm it is applied to the report submission route and scoped tightly enough (per-IP + per-target, not just per-target).
- Internal bot-token-gated endpoints (`app/Http/Controllers/Api/Internal/`) are explicitly excluded from bot-protection requirements — they are protected by `VerifyBotToken`.

### (10) PII exposure in responses & public-site payloads

- Resource classes (`app/Http/Resources/`) returning fields not appropriate for the calling audience: `CustomerResource`, `EnquiryResource`, `EnquiryDetailResource` — confirm email/phone are not present in public-facing payloads.
- `IndividualProfileController` (`app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`) — the `GET /api/public/profiles/{handle}` response must contain only display-safe fields; confirm no `email`, internal `user_id`-adjacent identifiers, or customer/enquiry counts are included.
- `PublicCustomerLeadController` and `PublicEnquiryController` response bodies — a 200 confirmation must not echo back the submitted email or phone in a way that a scraper could use to confirm an address is live.
- Staff endpoints sharing Resource classes with user endpoints where the staff class carries more fields — confirm the correct resource class is used per audience; audit `UserStaffResource` vs `UserResource` vs `UserPublicResource` usage.
- `Log::*` calls containing email addresses, phone numbers, or IP addresses in non-breadcrumb positions — use the `PiiLogHygieneSweepTest` pattern to assert they are scrubbed.
- GDPR export paths (`GdprPolicy`, related controllers): confirm the export payload is scoped strictly to the requesting user's own data and that cross-user data cannot be triggered by manipulating the export request.

### (11) MFA / AAL2 gating correctness

- Staff routes protected by `require.aal2` middleware: confirm `RequireAal2` reads the `aal` request attribute set by `VerifySupabaseJwt` and rejects requests with `aal1` — `Aal2RouteCoverageTest` sweeps this structurally; findings here are deviations from the sweep (routes that bypass it via direct inclusion in a non-standard middleware stack, or policy methods using `requiresFreshAal2()` on abilities that are never actually called with the right middleware in place).
- Sensitive user-facing policy abilities annotated with `requiresFreshAal2()` in the Policy class: confirm those abilities are only invokable from routes that sit behind `require.aal2` or a documented equivalent — a policy method can declare AAL2 needed without any route actually enforcing it.
- `StaffUserControllerFreshAal2Test` is the reference for how fresh-AAL2 enforcement is tested — flag any staff mutation endpoint not covered by an equivalent test.

## Per-finding requirements

For every finding:
- Cite the category number (1–11).
- Default tier is **P0 for confirmed tenant-boundary failures** (categories 1–4), **P1 for confirmed secret leakage / injection / SSRF** (categories 5–7), **P2 for hygiene gaps and defense-in-depth**.
- Name the canonical fix: `authorizeForUser + Policy`, `hash_equals`, `SafeUrlFetcher`, `no-bypass-on-empty-secret`, `throttle:X,Y middleware`, `BotProtection gate`, `signed URL`, `Form Request with explicit rules`, `Resource class audience split`, `404-not-403 on public endpoints`.
- Quote verbatim evidence.

## Out of scope — do NOT re-flag

- Shopify / Stripe / Square / Fresha / commerce webhook paths — removed entirely from the codebase.
- Dependency / CVE scanning — Composer audit lives elsewhere; only flag in-source CVE indicators.
- Laravel-Cloud-vs-K8s deployment hardening.
- Dormant CSAM / moderation vocabulary — kept intentionally (pipeline deferred, see project notes). Do not flag the vocabulary as a leak or as dead code to remove.
- The resolved Instagram SSRF (`InstagramScraper` / `InstagramController` mirror fix, 2026-06-03) — confirm it is fixed and skip; do not re-open as a new finding.
- Larastan-covered symbol-existence issues (undefined methods, missing properties, wrong config key types) — they are caught by `composer analyse`.
- Style violations (pint baseline is not clean repo-wide) — out of scope for a security lens.
- `fresha` / `apify` keys in `config/services.php` — confirmed legacy config remnants, harmless.

## Suggested per-domain scope groups

### Group A — Auth middleware + policies (run first, highest priority)
```
--scope app/Http/Middleware/Auth
--scope app/Policies
--scope app/Providers/AppServiceProvider.php
--scope app/Http/Controllers/Concerns
```

### Group B — Inbound callbacks + internal endpoints
```
--scope app/Http/Controllers/Api/Webhooks
--scope app/Http/Controllers/Api/Internal
```

### Group C — User API surface + Form Requests
```
--scope app/Http/Controllers/Api/User
--scope app/Http/Requests
```

### Group D — Public site surface (enumeration / PII / bot protection)
```
--scope app/Http/Controllers/Api/PublicSite
--scope app/Http/Resources
```

### Group E — Vendor I/O (SSRF / outbound URL fetches)
```
--scope app/Services/SmartLinks
--scope app/Services/Platforms
--scope app/Services/Streaming
```

### Group F — Cloudflare Worker (origin trust + KV poisoning)
```
--scope cloudflare-worker
```

### Group G — Configuration (secret leakage, CORS)
```
--scope config
```

## Exhaustiveness directive

Walk every file in scope. Emit a finding for every distinct quotable instance. If four platform scrapers make raw `Http::get` calls on user-supplied URLs, that is four findings (or one grouped finding with four evidence blocks — choose whichever preserves quotable per-file evidence). If one file contains both an injection risk and a PII exposure, emit two findings. **Under-reporting on a security audit is the worst failure mode.**
