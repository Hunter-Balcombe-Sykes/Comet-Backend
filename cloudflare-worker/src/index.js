/**
 * Partna subdomain router — Cloudflare Worker.
 *
 * Reads a per-subdomain routing entry from Workers KV and dispatches to
 * one of three render paths:
 *   - { type: "brand" }                            → pass-through to origin (Hydrogen on Oxygen)
 *   - { type: "affiliate", redirect: "https://…" } → 301 to brand.partna.au/handle (Hydrogen)
 *   - { type: "individual" }                       → Service Binding to partna-pages (Astro),
 *                                                    fronted by the edge Cache API
 *
 * Backend (Laravel) keeps the KV in sync via SyncSubdomainToKvJob — the
 * SINGLE writer (CLAUDE.md non-negotiable rule). The job writes the
 * `individual` entries since §28.6 (Comet-Backend PR #85).
 *
 * Reserved subdomains (api, www, admin, etc.) are passed through without
 * a KV lookup.
 */

const PARTNA_DOMAIN = "partna.au";

// Mirrors `reserved_subdomains` in config/partna.php — these never go to KV.
const RESERVED = new Set([
  "www",
  "api",
  "admin",
  "app",
  "staff",
  "dashboard",
  "support",
  "help",
  "billing",
  "static",
  "cdn",
  "assets",
  "auth",
  "docs",
  "status",
  "comet",
  "sidest",
  "partna",
]);

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    const hostname = url.hostname.toLowerCase();

    // Apex and non-partna.au requests pass through untouched.
    if (
      hostname === PARTNA_DOMAIN ||
      !hostname.endsWith("." + PARTNA_DOMAIN)
    ) {
      return fetch(request);
    }

    const subdomain = hostname.slice(0, -1 * (PARTNA_DOMAIN.length + 1));

    // Multi-level subdomains and reserved labels pass through.
    if (subdomain === "" || subdomain.includes(".") || RESERVED.has(subdomain)) {
      return fetch(request);
    }

    let entry = null;
    try {
      entry = await env.SUBDOMAIN_KV.get(subdomain, { type: "json" });
    } catch (err) {
      // KV transient failure — fail open so brand traffic keeps working.
      console.error("KV lookup failed", { subdomain, err: String(err) });
      return fetch(request);
    }

    if (!entry) {
      return new Response("Not Found", {
        status: 404,
        headers: { "Content-Type": "text/plain", "Cache-Control": "no-store" },
      });
    }

    if (entry.type === "affiliate" && typeof entry.redirect === "string") {
      // Drop incoming path/query — Hydrogen only has $affiliateSlug.tsx (no nested
      // affiliate routes), so preserving paths produces 404s. Redirect cleanly to
      // the affiliate's brand-side page.
      return new Response(null, {
        status: 301,
        headers: {
          Location: entry.redirect,
          // Without this, browsers cache 301s indefinitely. A primary-brand swap
          // would leave stale redirects in client caches until users manually clear.
          "Cache-Control": "max-age=0, must-revalidate",
        },
      });
    }

    // Individual sitepage — served by the `partna-pages` Astro Worker via
    // Service Binding (plan §16, §29.1). The Cache API fronts the binding
    // so repeat hits don't re-invoke the Astro Worker; CloudflareCachePurgeJob
    // (Comet-Backend §28.7 / PR #86) drops the cache on profile mutation.
    if (entry.type === "individual") {
      // Fail-fast if the binding hasn't been deployed yet (operator action
      // item — `[[services]]` entry in wrangler.toml below). Without this
      // guard a missing binding would NPE on env.PARTNA_PAGES.fetch.
      if (!env.PARTNA_PAGES || typeof env.PARTNA_PAGES.fetch !== "function") {
        console.error("PARTNA_PAGES service binding missing", { subdomain });
        return new Response("Service Unavailable", {
          status: 503,
          headers: { "Content-Type": "text/plain", "Cache-Control": "no-store" },
        });
      }

      const cache = caches.default;
      // Only GETs are cacheable. POST / PUT / DELETE flow through to the
      // Astro Worker untouched so any future form-action paths in
      // partna-pages can mutate state without hitting a stale cached body.
      if (request.method === "GET") {
        const cached = await cache.match(request);
        if (cached) return cached;
      }

      const response = await env.PARTNA_PAGES.fetch(request);

      if (response.ok && request.method === "GET") {
        // Clone before returning — the body is a one-shot stream and the
        // cache.put copy reads it once asynchronously. ctx.waitUntil
        // keeps the put alive past the response return so the next hit
        // sees a populated cache.
        ctx.waitUntil(cache.put(request, response.clone()));
      }
      return response;
    }

    // type === "brand" or anything else: pass through to the origin defined by DNS.
    return fetch(request);
  },
};
