CFW-1 evidence verified verbatim at lines 148–164 and 267–269. CFW-2's premise is factually wrong: Cloudflare Workers' `caches.default` keys on request URL only, not on request headers — header fragmentation does not occur here. Dropping CFW-2.

`★ Insight ─────────────────────────────────────`
**Cloudflare Cache API vs. W3C Cache API:** The W3C spec (used in browser Service Workers) keys cache entries on the full request including headers; Cloudflare Workers' `caches.default` keys only on the URL. DeepSeek applied browser-spec semantics to a Cloudflare-specific implementation — a common error when the same method name (`cache.put(request, response)`) exists in both contexts but with different semantics.
`─────────────────────────────────────────────────`

---

# Cloudflare Worker Edge Audit — 2026-05-31

**Branch:** development
**Lens:** Cloudflare worker signature verification, R2 presigned URL leakage, public bucket scope, edge cache caches.default.put correctness, service-binding fallback safety
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `cloudflare-worker/src/index.js`
- `app/Services/Cloudflare/CloudflareKvService.php`
- `app/Services/Cloudflare/CloudflarePurgeService.php`
- `app/Services/Media/ImageVariantService.php`
- `app/Services/Media/VideoVariantService.php`
- `app/Services/Media/MediaUploadService.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **#CFW-1** · P1 — Service binding throw on cold-miss path surfaces as Cloudflare 1101 instead of 503
    - **Where:** `cloudflare-worker/src/index.js:268` (cold-miss branch); `cloudflare-worker/src/index.js:246` (non-GET passthrough)
    - **Affects:** Any visitor who hits a profile page when the primary cache is cold (first visit ever, or first visit after a purge) and the `PARTNA_PAGES` Astro Worker is transiently unavailable. Also affects any non-GET request to an individual profile during an Astro Worker outage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `fetchAndCache(env, ctx, request, cache)` call at the cold-miss path in a try/catch. On catch, return a `new Response("Service Unavailable", { status: 503, headers: { "Content-Type": "text/plain", "Cache-Control": "no-store" } })` with `applySecurityHeaders` applied. Do not re-throw.
        - Apply the same try/catch to the non-GET passthrough (`return env.PARTNA_PAGES.fetch(request)`) at line 246 — it has identical exposure.
        - The stale-shadow refresh path (`ctx.waitUntil(fetchAndCache(...))`) is already safe: `waitUntil` swallows uncaught promise rejections so a background refresh failure never crashes the Worker. Only the foreground awaits need guarding.
    - **Technical:** `fetchAndCache` calls `await env.PARTNA_PAGES.fetch(request)` with no surrounding error boundary. A service binding call throws when the target Worker is unreachable, times out, or its own boot throws. Because the cold-miss path is a direct `await` (not inside `waitUntil`), the rejected promise propagates up to the top-level `fetch` handler. Cloudflare converts an unhandled Worker exception into a generic 1101 error with no body and no user-readable message. The existing guard at line 234 (`if (!env.PARTNA_PAGES || typeof env.PARTNA_PAGES.fetch !== "function")`) covers only the case where the binding is entirely absent at deploy time — it does not catch runtime throws from a bound-but-failing Worker. A try/catch at the call site converts the error to a clean 503 and lets `console.error` surface it in Cloudflare's real-time logs.
    - **Plain English:** Your Cloudflare router sits between every visitor and their page. When a page is in the cache, visitors sail through. But the very first visitor after the cache is cleared has to go all the way to the origin — think of it like a café where the first customer of the day has to wait for the coffee machine to warm up. If the coffee machine breaks at exactly that moment, right now there's no "Sorry, back in 5 minutes" sign — instead the customer gets a confusing error code from Cloudflare with no explanation. A simple try/catch is the equivalent of posting that sign: the visitor sees "Service Unavailable" and knows to try again, and you see the error in your logs.
    - **Evidence:**
        ```javascript
        // Non-GET passthrough — no error boundary (line ~246)
        if (request.method !== "GET") {
          return env.PARTNA_PAGES.fetch(request);
        }

        // ... cache lookup paths ...

        // 3) Cold miss — fetch from origin and populate both caches.
        const fresh = await fetchAndCache(env, ctx, request, cache);
        return tagResponse(fresh, fresh.ok ? "origin" : "origin-error");
        ```
        ```javascript
        // fetchAndCache — the throw propagates uncaught (line ~149)
        async function fetchAndCache(env, ctx, request, cache) {
          const fresh = await env.PARTNA_PAGES.fetch(request);
          // ...
        }
        ```
