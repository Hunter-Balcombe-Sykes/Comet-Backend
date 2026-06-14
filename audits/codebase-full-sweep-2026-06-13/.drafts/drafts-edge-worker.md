
<!-- ═══ LENS: edge-worker | CHUNK: worker ═══ -->

- [ ] **#EDGE-1** · P0 — `Set-Cookie` from origin is cached and served to all visitors
    - **Where:** cloudflare-worker/src/index.js:86-100 (`withCacheTtl` function) and 107-118 (`fetchAndCache` function)
    - **Affects:** Every visitor to any cached sitepage — one user's session cookie can be served to all subsequent visitors.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `fetchAndCache`, strip `Set-Cookie` headers from the cloned response before calling `cache.put` on both primary and stale-shadow copies.
        - Add a `Cache-Control: no-store` guard on any cached response that carries `Authorization` or `Cookie` request headers, so the Worker never caches a personalized origin response.
    - **Technical:** `withCacheTtl` clones the origin response's `Headers` object (preserving every response header including `Set-Cookie`) and only modifies `Cache-Control`. The resulting response is then stored verbatim in the Cache API via `caches.default.put()`. If the Astro origin ever emits a `Set-Cookie` header — even accidentally during a deploy window or feature flag change — that cookie is serialized into the cached response and delivered to every subsequent visitor whose request matches the same cache key. The Worker has no defense against this; it trusts the origin completely.
    - **Plain English:** Think of the edge cache like a photocopier that stores copies of your website and hands them out. Right now, if the origin server accidentally puts a "sticky note with private info" (a cookie) on one copy, the photocopier stores it and hands that same sticky note to everyone else who asks for that page. One visitor's private session data leaks to the next visitor — and you'd never know because there's no alert for it.
    - **Evidence:**
        ```js
        async function withCacheTtl(response, ttlSeconds) {
          const body = await response.clone().arrayBuffer();
          const headers = new Headers(response.headers);
          // … modifies only Cache-Control, preserves everything else …
          return new Response(body, {
            status: response.status,
            statusText: response.statusText,
            headers,
          });
        }
        ```
        ```js
        if (fresh.ok && request.method === "GET") {
          ctx.waitUntil(
            cache.put(request, await withCacheTtl(fresh, PRIMARY_CACHE_TTL_S)),
          );
          ctx.waitUntil(
            cache.put(staleShadowKey(request), await withCacheTtl(fresh, STALE_SHADOW_TTL_S)),
          );
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#EDGE-2** · P0 — Purge only clears root URL; all deep-linked pages and their stale shadows survive
    - **Where:** app/Services/Cloudflare/CloudflarePurgeService.php:94-125 (`purgeHandle`) vs cloudflare-worker/src/index.js:151-156 (primary `cache.put`) and 158-160 (shadow `cache.put`)
    - **Affects:** Every visitor to a deep-linked page (`/gallery`, `/services`, `/about`, etc.) after a content edit — they see pre-mutation content. On moderation takedowns, cached deep links serve the taken-down content.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Switch `purgeHandle` from Cloudflare's exact-URL `{"files":[...]}` to prefix-based `{"prefixes":["https://{handle}.{domain}/"]}` so a single API call clears every cached URL under the subdomain (primary + `/_swr-shadow/*`).
        - Alternatively, add the `/_swr-shadow` prefix as a second `prefixes` entry if prefix-based purging doesn't span both URL spaces.
    - **Technical:** The Worker caches every distinct URL path (and query string) via `caches.default.put(request, …)` where `request` is the full visitor URL. For a single subdomain there can be dozens of cached entries (`/`, `/gallery`, `/services`, `/contact`, etc.), each with a primary copy and a `/_swr-shadow`-prefixed shadow copy. `CloudflarePurgeService::purgeHandle` sends `{"files": ["https://handle.partna.au/", "https://handle.partna.au", "https://handle.partna.au/_swr-shadow/"]}` — Cloudflare's `files` parameter does EXACT URL matching only. No deep-link URL (e.g. `https://handle.partna.au/gallery`) and no deep-link shadow (`https://handle.partna.au/_swr-shadow/gallery`) is purged. The purge service's own docblock says "Purge the full cache chain" but the implementation only touches the homepage.
    - **Plain English:** Imagine you update the "Services" page on your website. The system clears the cached copy of your homepage — but the photocopier still has the old "Services" page in its tray. Anyone who visits `/services` directly (from a Google search, a shared link, or a bookmark) sees the old version for up to 24 hours. Worse, there's a 7-day backup copy that also isn't cleared. If a page gets taken down for abuse, someone with the direct link can still access it for a week.
    - **Evidence:**
        ```php
        // CloudflarePurgeService.php — only root URLs are listed:
        $urls = [
            "https://{$h}.{$baseDomain}/",
            "https://{$h}.{$baseDomain}",
            "https://{$h}.{$baseDomain}/_swr-shadow/",
        ];
        // … dispatches as {"files": $urls} — exact match, no prefix/wildcard
        ```
        ```js
        // Worker caches per-URL — every distinct path gets its own entry:
        ctx.waitUntil(
            cache.put(request, await withCacheTtl(fresh, PRIMARY_CACHE_TTL_S)),
        );
        ctx.waitUntil(
            cache.put(staleShadowKey(request), await withCacheTtl(fresh, STALE_SHADOW_TTL_S)),
        );
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#EDGE-3** · P1 — RESERVED subdomain set drift: Worker has 19 entries, config has ~250+
    - **Where:** cloudflare-worker/src/index.js:42-60 (`RESERVED` Set) vs config/partna.php `reserved_subdomains` array
    - **Affects:** Any subdomain reserved in config but missing from the Worker hits KV lookup and 404s (e.g. `login.partna.au`, `shop.partna.au` fail instead of passing through). Any label reserved in the Worker but missing from config could potentially be claimed as a handle.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Expand the Worker's `RESERVED` Set to match every entry in `config('partna.reserved_subdomains')`.
        - Add a bidirectional sync comment on both sides (`RESERVED` in Worker, `reserved_subdomains` in config) naming the other as the mirror — so future additions happen in both places.
        - Consider a build-time generation step (or a KV-backed reserved list) so the two cannot drift again.
    - **Technical:** The Worker's `RESERVED` Set contains only 19 labels, roughly the platform-infrastructure subset of the full reserved list. Config's `reserved_subdomains` contains ~250+ entries spanning auth routes, marketing pages, commerce, legal, government names, brand impersonation targets, and profanity. A label missing from the Worker's set (e.g., `login`, `shop`, `google`, `settings`) goes through KV lookup → 404, meaning those subdomains are dead rather than passing through to their intended origins. Conversely, labels in the Worker but not in config could be registered as user handles on the backend and then shadowed at the edge (the Worker would pass them through instead of routing them to the sitepage).
    - **Plain English:** The Worker has a guest list of ~19 names it knows to let through without checking the directory. The backend has a much longer list of ~250 names that should never be given out as usernames. Because the two lists aren't the same, subdomains like `login.partna.au` or `shop.partna.au` hit the directory, find nothing, and show a "Not Found" error — they're broken. And names that are ONLY on the Worker's list could be claimed by a real user, creating confusing conflicts.
    - **Evidence:**
        ```js
        // Worker RESERVED — 19 entries:
        const RESERVED = new Set([
          "www", "api", "admin", "app", "staff", "dashboard",
          "support", "help", "billing", "static", "cdn", "assets",
          "auth", "docs", "status", "comet", "sidest", "partna",
        ]);
        ```
        ```php
        // config/partna.php reserved_subdomains — ~250 entries across categories:
        'reserved_subdomains' => [
            'www', 'api', 'admin', 'app', 'apps', 'staff', 'dashboard',
            'support', 'help', 'helpdesk', 'billing', 'static', 'cdn', 'assets',
            'auth', 'docs', 'status', 'comet', 'sidest', 'partna',
            'mail', 'email', /* … ~230 more entries … */ 'nsfw',
        ],
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#EDGE-4** · P1 — Security headers absent on pass-through, preview, non-GET, and 503 response paths
    - **Where:** cloudflare-worker/src/index.js — multiple return paths spanning lines 178-250
    - **Affects:** Visitors to the apex domain, reserved subdomains, `?skeleton=` preview pages, non-GET requests, and anyone hitting the 503 Service Unavailable page — all miss HSTS, XFO, nosniff, and Referrer-Policy headers.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap every `return fetch(request)` pass-through in a helper that applies security headers to the origin response (or at minimum to the 503 path).
        - Apply `applySecurityHeaders` to the 503 missing-binding response.
        - Apply `applySecurityHeaders` to the `?skeleton=` preview path and non-GET method path.
        - Audit that the `tagResponse` helper (which includes `applySecurityHeaders`) is called on every visitor-facing return path.
    - **Technical:** Eight distinct return paths in the Worker skip `applySecurityHeaders`: (1) apex `partna.au` pass-through, (2) reserved-subdomain pass-through, (3) multi-level-subdomain pass-through, (4) KV-failure pass-through, (5) unknown-custom-domain-host pass-through, (6) unknown-entry-type pass-through, (7) the `?skeleton=` preview path, and (8) non-GET method pass-through. The 503 missing-binding path constructs a raw `new Response` without security headers. Only the 404-not-found path, the alias-301 path, and the three `serveIndividual` cache paths (hit/stale/origin) apply them. The missing `Strict-Transport-Security` on pass-through responses means browsers hitting those paths over HTTP won't pin HSTS; missing `X-Frame-Options` allows those pages to be framed.
    - **Plain English:** Security headers are like the warning labels and safety seals on your website. Right now, most pages get them — but several side doors don't. If someone visits the main `partna.au` website or a reserved subdomain, the response comes back without the "don't frame me" and "always use HTTPS" instructions. A few paths (like the "skeleton preview" mode or non-GET API calls) also skip them. Most of these are edge cases, but the pass-through paths include the main marketing site.
    - **Evidence:**
        ```js
        // Apex pass-through — no applySecurityHeaders:
        if (hostname === PARTNA_DOMAIN) {
          return fetch(request);
        }
        // Reserved/multi-level pass-through — no applySecurityHeaders:
        if (subdomain === "" || subdomain.includes(".") || RESERVED.has(subdomain)) {
          return fetch(request);
        }
        // 503 — no applySecurityHeaders:
        return new Response("Service Unavailable", {
          status: 503,
          headers: {"Content-Type": "text/plain", "Cache-Control": "no-store"},
        });
        // ?skeleton= preview — raw origin response:
        if (new URL(request.url).searchParams.has("skeleton")) {
          return env.PARTNA_PAGES.fetch(originRequest);
        }
        // non-GET — raw origin response:
        if (request.method !== "GET") {
          return env.PARTNA_PAGES.fetch(originRequest);
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#EDGE-5** · P2 — Query-string cache fragmentation: visitors can mint unlimited cache entries
    - **Where:** cloudflare-worker/src/index.js:151 (`cache.put(request, …)`) and 136 (`caches.default.match(request)`)
    - **Affects:** Cache efficiency and cost — a single visitor or bot can exhaust the zone's cache quota by varying query parameters (`/?x=1`, `/?x=2`, …).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Strip or normalize ignored query parameters before building the cache key (e.g., drop `utm_*`, `fbclid`, `gclid`, and any parameter the Astro app doesn't use for rendering).
        - Sort query parameters alphabetically so `?a=1&b=2` and `?b=2&a=1` collapse to one cache entry.
    - **Technical:** The Cache API keys on the full request URL including the raw query string. Any visitor can append arbitrary query parameters (`?cachebuster=1`, `?cachebuster=2`, …) and each creates a distinct cache entry. Cloudflare Workers have per-zone cache limits; a motivated attacker or aggressive crawler could evict legitimate content by minting thousands of query-string variants. There is no allowlist or normalization in the Worker, and the cache-key construction happens before any origin interaction.
    - **Plain English:** The caching system treats `yoursite.partna.au/` and `yoursite.partna.au/?random=123` as two completely different pages, even though they show the same thing. Anyone can create unlimited "unique" copies by adding random junk to the URL, which wastes cache space and could push real pages out. It's like a library that files every copy of the same book on a different shelf because someone doodled a different number on the cover.
    - **Evidence:**
        ```js
        const cache = caches.default;
        const cached = await cache.match(request);  // request includes full query string
        // …
        ctx.waitUntil(
            cache.put(request, await withCacheTtl(fresh, PRIMARY_CACHE_TTL_S)),
        );
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#EDGE-6** · P2 — No CSP header; Worker lags behind backend security posture
    - **Where:** cloudflare-worker/src/index.js:131-144 (`applySecurityHeaders`)
    - **Affects:** All sitepage visitors — no Content-Security-Policy means no defense against XSS or clickjacking on modern browsers (X-Frame-Options is present but is the legacy mechanism).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a minimal CSP: `frame-ancestors 'none'` (stronger modern equivalent of X-Frame-Options) and `default-src https:` as a starting baseline.
        - Coordinate with the Astro team so scripts/styles loaded by the sitepage are reflected in appropriate `script-src` / `style-src` directives.
    - **Technical:** `applySecurityHeaders` ships `X-Frame-Options: SAMEORIGIN` (legacy clickjacking defense) but no `Content-Security-Policy` header. CSP's `frame-ancestors` directive is the modern, universally-supported replacement and also enables script/style source restrictions. The Worker's own comment in `applySecurityHeaders` acknowledges the gap: "CSP frame-ancestors is the modern equivalent; the sitepage doesn't ship a CSP yet." Since the Worker is the single front-door for all sitepage traffic, it's the ideal place to add this header in one shot.
    - **Plain English:** The security headers include an older "don't embed this page in other sites" instruction that works on older browsers. The modern version of that instruction — which also lets you say "only run scripts from these trusted sources" — isn't included yet. Adding it would be like upgrading from a basic door lock to a deadbolt. The Worker's own notes mention this is missing.
    - **Evidence:**
        ```js
        function applySecurityHeaders(headers) {
          if (!headers.has("Strict-Transport-Security")) {
            headers.set("Strict-Transport-Security", "max-age=31536000; includeSubDomains");
          }
          if (!headers.has("X-Content-Type-Options")) {
            headers.set("X-Content-Type-Options", "nosniff");
          }
          if (!headers.has("Referrer-Policy")) {
            headers.set("Referrer-Policy", "strict-origin-when-cross-origin");
          }
          if (!headers.has("X-Frame-Options")) {
            headers.set("X-Frame-Options", "SAMEORIGIN");
          }
          // No CSP header set — comment above acknowledges this gap
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#EDGE-7** · P2 — Hardcoded TTLs and domain lack sync comments pointing to backend config mirrors
    - **Where:** cloudflare-worker/src/index.js:34-38 vs config/partna.php and app/Services/Cloudflare/CloudflarePurgeService.php
    - **Affects:** Any engineer changing a TTL or domain in one place without realizing the other exists — silent staleness or broken routing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a comment on `PRIMARY_CACHE_TTL_S` naming `CloudflarePurgeService::purgeHandle` as the consumer that assumes 24h.
        - Add a comment on `STALE_SHADOW_TTL_S` naming the 7d shadow contract and the purge coverage that must clear `/_swr-shadow/*`.
        - Add a comment on `PARTNA_DOMAIN` naming `config('partna.public_domain')` as the backend mirror.
        - Add mirror comments in `CloudflarePurgeService::purgeHandle` and `config/partna.php` pointing back to the Worker constants.
    - **Technical:** `PRIMARY_CACHE_TTL_S = 86_400` (24h) and `STALE_SHADOW_TTL_S = 7 * 86_400` (7d) are JavaScript constants in the Worker with no reference to the backend services that depend on these values for purge timing and freshness guarantees. `PARTNA_DOMAIN = "partna.au"` is hardcoded while the backend derives the public domain from `config('partna.public_domain')`. A change to the public domain in config (e.g., for a staging environment) would break the Worker's hostname matching without any indication that the Worker needs updating. The Worker file has good internal documentation, but no cross-reference to the backend code that must stay in lockstep.
    - **Plain English:** The Worker has numbers baked in — "cache for 24 hours," "keep a backup for 7 days," "the domain is partna.au." The backend also has code that assumes these exact numbers. But neither side has a note saying "hey, if you change this here, you also need to change it over there." It's like two people sharing a calendar where one has the meeting at 2pm and the other at 3pm, and neither knows the other's time is different. A few comment lines would prevent a future engineer from changing the TTL in one place and silently breaking the system.
    - **Evidence:**
        ```js
        // Worker — hardcoded with no backend mirror comment:
        const PARTNA_DOMAIN = "partna.au";
        const PRIMARY_CACHE_TTL_S = 86_400;
        const STALE_SHADOW_TTL_S = 7 * 86_400;
        ```
        ```php
        // Backend — derives domain from config, no Worker mirror comment:
        $baseDomain = config('partna.public_domain');
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#EDGE-8** · P2 — Staging Worker shares production KV namespace
    - **Where:** cloudflare-worker/wrangler.toml:26-35
    - **Affects:** Staging environment — any `SyncSubdomainToKvJob` run in staging writes to the same KV namespace that production reads, potentially poisoning live routing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create a separate KV namespace for staging (`wrangler kv:namespace create SUBDOMAIN_KV --preview --env staging`).
        - Add a `[env.staging.kv_namespaces]` block in `wrangler.toml` binding `SUBDOMAIN_KV` to the staging namespace.
    - **Technical:** `wrangler.toml` defines a staging environment override for the `PARTNA_PAGES` service binding (pointing to `partna-pages-staging`) but has no corresponding override for `SUBDOMAIN_KV`. The KV namespace binding is defined only at the top level, which applies to ALL environments including staging. If staging's backend dispatches `SyncSubdomainToKvJob` (e.g., during testing or staging-environment usage), it writes to the production KV namespace. This could route production traffic to staging pages or create alias entries that redirect production visitors incorrectly.
    - **Plain English:** The staging and production environments share the same "address book" (KV store) that tells the Worker whose website lives at which subdomain. If someone tests a handle rename in staging, it updates the production address book — potentially redirecting real visitors to the wrong place. Staging has its own copy of the website renderer, but not its own copy of the routing table.
    - **Evidence:**
        ```toml
        [[kv_namespaces]]
        binding = "SUBDOMAIN_KV"
        id = "ce726607804d41a296d6da150b0c537f"
        preview_id = "e6a8eecd305148f9a75b879aa6faf790"

        # … later, staging only overrides the service binding, not KV:
        [env.staging]
        [[env.staging.services]]
        binding = "PARTNA_PAGES"
        service = "partna-pages-staging"
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#EDGE-9** · P3 — Non-OK origin responses may be browser-cached; Worker doesn't add `no-store`
    - **Where:** cloudflare-worker/src/index.js:107-118 (`fetchAndCache`) and 170 (`tagResponse` call)
    - **Affects:** Visitors who hit an origin error (5xx) — their browser may cache the error page based on whatever Cache-Control the origin sent.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `serveIndividual`, when `fresh.ok` is false, wrap the response with `Cache-Control: no-store` before returning it, so browsers don't cache error pages.
    - **Technical:** When the origin returns a non-2xx response, `fetchAndCache` skips both `cache.put` calls (correct). But the raw origin response — including whatever `Cache-Control` header the Astro app sent — is passed through to the visitor. If the origin sends `Cache-Control: public, max-age=300` on a 500 error (unlikely but possible in a misconfigured error handler), the browser caches the error for 5 minutes. The Worker tags it `origin-error` for observability but doesn't sanitize the caching directives.
    - **Plain English:** When the website's server has a hiccup and returns an error page, that error page might come with instructions that tell your browser "it's OK to save this and show it again for a while." The Worker correctly avoids storing the error in its own cache, but it passes the error straight to the visitor without adding a "don't save this" note. A browser that caches the error would show a broken page even after the server recovers.
    - **Evidence:**
        ```js
        async function fetchAndCache(env, ctx, request, cache, originRequest) {
          const fresh = await env.PARTNA_PAGES.fetch(originRequest ?? request);
          if (fresh.ok && request.method === "GET") {
            // … cache.put both copies …
          }
          return fresh;  // non-OK passes through with whatever headers origin sent
        }
        // In serveIndividual:
        return tagResponse(fresh, fresh.ok ? "origin" : "origin-error");
        // tagResponse does NOT inspect fresh.ok to add no-store
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#EDGE-10** · P3 — `ctx.waitUntil` background refresh can silently leave primary/shadow caches inconsistent
    - **Where:** cloudflare-worker/src/index.js:154-161
    - **Affects:** Visitors on a cache miss — if one `cache.put` succeeds and the other fails, the two cache layers diverge with no signal.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap both `cache.put` calls in a single `ctx.waitUntil` with sequential try/catch so a failure on either one is logged via `console.error`.
        - Consider a structured log line tagging the subdomain and whether primary, shadow, or both succeeded.
    - **Technical:** `fetchAndCache` issues two independent `ctx.waitUntil(cache.put(...))` calls — one for the primary cache, one for the stale shadow. If the first succeeds and the second fails (e.g., due to a transient Cache API error), the primary is updated with fresh content but the shadow retains whatever it held before (potentially very stale). The next cold-miss visitor would be served from the stale shadow instead of the fresh primary. Both `ctx.waitUntil` calls run in the background and neither failure is caught or logged. The Worker has no way to detect this state.
    - **Plain English:** When the Worker fetches a fresh copy of a page, it tries to save two copies: a short-term one and a 7-day backup. If saving the backup silently fails (like a brief glitch), the short-term copy gets saved fine — but the backup stays as whatever ancient version was there before. The next person who visits after the short-term copy expires would see the old backup instead of the fresh page. There's no alert when this happens.
    - **Evidence:**
        ```js
        if (fresh.ok && request.method === "GET") {
          ctx.waitUntil(
            cache.put(request, await withCacheTtl(fresh, PRIMARY_CACHE_TTL_S)),
          );
          ctx.waitUntil(
            cache.put(staleShadowKey(request), await withCacheTtl(fresh, STALE_SHADOW_TTL_S)),
          );
        }
        ```
    - `[DRAFT, confidence: 0.7]`
