# Pilot Manual Queue — items the audit orchestrator can't run unattended

Curated extract of the 10 unchecked items remaining across `pilot-stage-1.md` and `pilot-stage-2.md`, in recommended execution order. Each entry is verbatim from its source pilot file — those remain the audits-of-record; this file is a working queue for human-driven sessions.

**Why these aren't enqueued in the orchestrator:** every one of them breaks at least one of the orchestrator's contract assumptions — single small commit, mechanical fix, no cross-codebase coordination, no architectural ambiguity. The auditor flagged most of them under "Standalone — do NOT bundle" at line 47 of `pilot-stage-1.md`.

**Decision cluster (do these together):** `#V5-070` → `#PR-002` → `#PR-006` are the same architectural family — auth-boundary trust for embedded / Hydrogen / affiliate API keys. Decide the trust model once on `#V5-070`, then apply to the other two. Sequencing them out of order will cause rework.

**Effort total (rough):** ~70–110 hours, but most P1s can ship to staging within a single focused day each.

---

## P1 — Fix before pilot launch

- [ ] **#V5-070** · P1 — EmbeddedSetupController trusts middleware-resolved professional_id with no in-controller verification
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php (every method); depends on app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php
    - **Affects:** Brand profile, brand store settings, Hydrogen install confirmation, Cloudflare DNS provisioning, Stripe onboarding link generation.
    - **Effort:** M (docs) or L (per-shop session tokens) (~0.5h docs / 6-10h per-shop token)
    - **What to do:**
        - Read VerifyEmbeddedApiKey thoroughly. Confirm whether the API key is platform-wide or per-shop.
        - If platform-wide: document the trust model in CLAUDE.md OR add a per-shop signing token (similar to Shopify's session token).
        - Add a feature test that asserts a request with a valid API key but a shop header NOT belonging to any installed brand returns 401, not 200 with a rebound professional.
        - Cross-reference with #PR-002 / #PR-006 (Hydrogen IDOR) — same family of decision.
    - **Technical:** Wizard endpoints rely entirely on middleware to gate authorization, with no in-controller re-verification. If the middleware uses only platform-wide API key + shop header, the trust boundary is loose.
    - **Plain English:** The wizard endpoints don't double-check that the caller owns the brand they're editing — they trust whatever the middleware says. If the API key is one shared platform-wide value, anyone with the key can pretend to be any brand by sending a different shop header. Same kind of issue as the Hydrogen API key one.
    - **Evidence:**
        ```php
        // EmbeddedSetupController.php (representative snippet)
        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $professional = Professional::findOrFail($professionalId);
        $professional->update($proUpdates);  // No ownership re-check
        ```
    - **Source:** v5 audit (discovery_lens: tobias-commit-review; in_scope_v4: no).
    - **Why deferred:** Architectural decision (platform-wide vs per-shop trust model) — orchestrator can't pick. Start by reading VerifyEmbeddedApiKey to determine which path applies; the docs path is 30 minutes, the per-shop path is 6-10h.

- [ ] **#V5-005** · P1 — Shopify webhook HMAC uses platform-wide secret, not per-shop
    - **Where:** app/Http/Controllers/Concerns/ValidatesShopifyWebhookHmac.php:14-23
    - **Affects:** Multi-tenant webhook signing isolation across all Shopify webhooks.
    - **Effort:** M (~3-4h)
    - **What to do:**
        - Verify Shopify's per-shop secret availability for the app type. If yes, store per-integration and validate against shop's own secret.
    - **Technical:** Single `config('services.shopify.webhook_secret')`. Shopify partner apps expose per-shop secrets; using one platform secret means a single leak compromises every brand's webhook channel.
    - **Source:** v5 audit (discovery_lens: domain-subagent-4-pass2; in_scope_v4: no).
    - **Why deferred:** Requires Shopify-side configuration per shop + DB schema change for per-integration secret + key rotation strategy + migration of existing shops. Operational sequencing across multiple systems, not a localized code fix.

- [ ] **#2-05** · P1 — Tenant models lack global scopes — every query is "remember to add WHERE professional_id"
    - **Where:** app/Models/Core/** (every tenant-bearing model)
    - **Affects:** Any future feature on Professional/Site/Block/Customer/Service/etc.
    - **Effort:** L (~8–16h)
    - **What to do:**
        - Define a `TenantScoped` trait that resolves the current professional from a request-scoped service and adds `addGlobalScope` filtering by tenant FK.
        - Apply to ~15 tenant-bearing models in app/Models/Core/.
        - Refactor existing explicit `where('professional_id')` calls to rely on the scope (or keep them — they're harmless duplicates).
        - Document the few admin/cross-tenant call sites that need `withoutGlobalScope`.
        - Pest coverage: seed two tenants and assert each cannot read the other.
    - **Technical:** Laravel's global scopes are the canonical primitive for this. Implementation requires resolving the "current tenant" — for this app that is `LoadCurrentProfessional`'s output. Staff and Internal contexts must explicitly opt out.
    - **Plain English:** Right now, the rule "always filter by who owns the data" is a discipline question — every developer has to remember it on every query. The fix turns it into a default that's enforced by the database layer, so forgetting is impossible.
    - **Evidence:** `Site.php` has no `addGlobalScope`; only one model (ServiceCategory) has one, and it's for ordering not tenancy.
    - **Why deferred:** XL refactor across ~15 models with eager-load and admin-context interactions. Each model is independent enough to phase, but a botched scope on a parent silently breaks every join — needs per-domain manual smoke testing, not a single unattended commit.

- [ ] **#8-03** · P1 — HydrogenDeploymentController returns decrypted oxygen_deployment_token in JSON response
    - **Where:** app/Http/Controllers/Api/Internal/HydrogenDeploymentController.php:24-45
    - **Affects:** Every brand with an Oxygen deployment.
    - **Effort:** L (~8–12h)
    - **What to do:**
        - Replace with a per-request token-exchange flow: CI presents a JWT signed with a CI-only secret + brand id; backend validates and returns a short-lived deployment token.
        - Add IP allowlist for GitHub Actions runner ranges.
        - Implement token rotation + an audit log of token issuances.
    - **Technical:** Static-token-distribution pattern is the wrong architecture for high-value secrets. A short-lived, audience-bound credential issued per request is the standard fix. Combined with #PR-001 above, a single misconfigured env var → all deployment tokens exfiltrated → an attacker can redeploy any brand's storefront with malicious code.
    - **Plain English:** Right now the deployment system asks "give me everyone's deployment tokens" and gets them all in one JSON. If that single API key leaks, every brand's storefront can be hijacked.
    - **Evidence:**
        ```php
        // line 39 — token returned in JSON, decrypted by encrypted cast on the model
        'oxygen_deployment_token' => $row->oxygen_deployment_token,
        ```
    - **Why deferred:** Two-codebase change — Hydrogen frontend (CI workflow) must change in lockstep with backend's new exchange endpoint. Block until Hydrogen is in scope; do `#PR-001` first if Hydrogen tokens get redesigned (executive-summary compound-risk callout).

---

## P2 — Fix during pilot if seen

- [ ] **#1-04 / #1-05** · P2 — JWKS cache failure observability + missing kid claim observability
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php (JWKS rememberLocked, kid extraction)
    - **Affects:** Auth observability for all tenants during a Supabase outage.
    - **Effort:** S (~1h)
    - **What to do:**
        - Bump log level to error on JWKS fetch failures.
        - Add a metric / Nightwatch event "supabase.jwks.fetch_failed".
        - Add a `code` field to 401 responses (`'JWKS_UNAVAILABLE'`, `'TOKEN_INVALID'`, etc.).
    - **Technical:** Observability hardening on the auth fallback path.
    - **Plain English:** When the auth server is having trouble, our logs say "warning" instead of "error" and don't include enough detail. Promote the level and add specifics.
    - **Why deferred:** Compound ID format (`#1-04 / #1-05`) — orchestrator's enqueue regex matches single `#X-XX` items. Could be split, but it's also an observability/alerting design question (which Nightwatch event names? what alert thresholds?).

- [ ] **#2-02 / #2-03** · P2 — SiteCache fill lock + brand-partner enrichment cache lack tenant-aware audit
    - **Where:** app/Services/Cache/SiteCacheService.php:81 (fill lock); 369-410 (enrichment in-memory cache)
    - **Affects:** Cross-tenant cache observability — needed for #2-01 follow-through.
    - **Effort:** M (~3h)
    - **What to do:**
        - Add per-affiliate / per-brand log lines on enrichment cache misses.
        - Monitor P99 fill-lock contention as sites grow.
    - **Technical:** Combined with the #2-01 fix in Stage 1, this gives the audit trail to detect impersonation attempts.
    - **Plain English:** When the system serves brand design assets to an affiliate, no record exists of who asked for what. Add logging so misuse can be detected. **See also #2-01 in Stage 1.**
    - **Why deferred:** Same compound-ID issue + observability design call (what to log, what's PII-safe in cache keys, retention policy).

- [ ] **#7-06** · P2 — R2 visibility=public on the media disk; per-tenant URL is unsigned
    - **Where:** config/filesystems.php:85
    - **Affects:** All uploaded media — gallery photos may warrant signed URLs.
    - **Effort:** L (~6–8h)
    - **What to do:**
        - Verify R2 bucket policy: world-read GETs OK, no LIST access.
        - For sensitive pools (gallery, content_videos) consider signed URLs.
    - **Technical:** Audit + decide which pools should be public. Files are keyed by `images/{proId}/{mediaId}/...` — proId is publicly known, mediaId is a UUID.
    - **Plain English:** All uploaded media lives in a public bucket. Mostly intentional (logos, gallery), but gallery photos might warrant a signed URL.
    - **Why deferred:** Cascades across every Resource that emits media URLs (must sign at request time), expiry policy, frontend caching strategy (signed URLs invalidate browser caches), CDN config. Cross-codebase coordination beyond the orchestrator's single-commit scope.

- [ ] **#V5-061** · P2 — 74% of API endpoints don't use Resource classes — CLAUDE.md mandate violation
    - **Where:** ~74% of API endpoints
    - **Effort:** L (~16+h)
    - **What to do:**
        - Incrementally — prioritize Resources for the next consumer; document trust-boundary exceptions.
    - **Technical:** Violates CLAUDE.md's "Resource classes for all API responses" rule. Most endpoints return raw Eloquent models.
    - **Source:** v5 audit (discovery_lens: lens-J-resource-shape; in_scope_v4: no).
    - **Why deferred:** Hundreds of endpoints; each Resource needs a schema design (which fields to expose, which to hide) and a frontend contract check. Phased rollout per controller domain — orchestrator can't decide what to expose.

---

## P3 — Nice to have

- [ ] **#PR-002** · P3 — HydrogenAffiliateProductsController accepts affiliate_id without per-brand-API-key scope (data publishable, but enumeration possible)
    - **Where:** app/Http/Controllers/Api/Internal/HydrogenAffiliateProductsController.php:33-89
    - **Effort:** M (~3h)
    - **What to do:** Tie API key to a `brand_id`, not a global key. (Related to #PR-006 deferred to Stage 2.)
    - **Why deferred:** Architectural sibling of `#V5-070` and `#PR-006` — the per-brand-key vs single-key call. Decide on `#V5-070`, apply here, then sweep `#PR-006` with the same pattern.

- [ ] **#PR-006** · P3 — Single global API key for all brands across Hydrogen internal controllers — no per-brand scope
    - **Where:** All Hydrogen internal controllers
    - **Effort:** L (~8h)
    - **What to do:** Move to per-brand API keys + IP allowlist. (Stage 2 makes the multi-brand exposure concrete.)
    - **Why deferred:** Same architectural family as `#V5-070` and `#PR-002`. Touches every Hydrogen internal controller and the frontend's auth client. Apply after the trust-boundary decision is settled.

---

## Notes

- These items remain unchecked in the source pilot files (`pilot-stage-1.md`, `pilot-stage-2.md`) by design. Tick the source boxes when an item is completed; this file is a working extract, not a status mirror.
- The orchestrator's enqueue logic correctly skips them. Don't try to force-enqueue without first addressing the underlying constraint (architectural ambiguity, XL scope, cross-codebase coordination, or compound-ID format).
- If the orchestrator grows a `mode: plan-only` track (per the dual-worker plan), items like `#2-05` and `#V5-061` could ride that track to produce a phased rollout plan without committing changes — useful for breaking down the XL refactors before a human session executes them.
