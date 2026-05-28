/**
 * Partna subdomain router — Cloudflare Worker.
 *
 * Reads a per-subdomain routing entry from Workers KV and dispatches to
 * one of two render paths:
 *   - { type: "individual" }                       → Service Binding to partna-pages (Astro),
 *                                                    fronted by the edge Cache API with
 *                                                    stale-while-revalidate
 *   - { type: "alias", redirect: "https://…" }    → 301 to canonical subdomain URL
 *
 * Backend (Laravel) keeps the KV in sync via SyncSubdomainToKvJob — the
 * SINGLE writer (CLAUDE.md non-negotiable rule). The job writes the
 * `individual` entries since §28.6 (Comet-Backend PR #85).
 *
 * Reserved subdomains (api, www, admin, etc.) are passed through without
 * a KV lookup.
 *
 * Caching strategy (audit Phase F):
 *   - Primary cache: 24 h edge TTL (audit A1). Mutations on the backend
 *     dispatch CloudflareCachePurgeJob which clears the entry, so freshness
 *     is push-based rather than pull-based.
 *   - Stale-while-revalidate (audit R3): on a cache MISS we also probe a
 *     long-lived "stale shadow" copy. If it's still around (last write
 *     within ~7 d) we serve it immediately and refresh the primary in the
 *     background via ctx.waitUntil. Effect: the first visitor after a
 *     purge / cold edge sees the previous render instantly while the
 *     refresh happens out-of-band.
 *   - Tags (audit O3): each subrequest tags the response with `cf-cache-
 *     status` (`hit` / `stale` / `miss` / `origin`) so Cloudflare logs
 *     show the hit-rate distribution without us writing a custom tracker.
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

/** Primary cache TTL in seconds — 24 h, push-purged on mutation. */
const PRIMARY_CACHE_TTL_S = 86_400;

/** Stale-shadow TTL — 7 d. Wide window so even multi-day backend outages
 * serve the last good render. SWR refresh re-extends the shadow each
 * successful origin hit. */
const STALE_SHADOW_TTL_S = 7 * 86_400;

/** Build a URL identifying the stale shadow for a given request. The
 * shadow lives under a different path so cache.match doesn't get
 * confused; the visitor never reaches this URL directly. */
function staleShadowKey(request) {
  const u = new URL(request.url);
  u.pathname = `/_swr-shadow${u.pathname}`;
  return new Request(u.toString(), {method: "GET", headers: request.headers});
}

/** Clone a response and overlay a long s-maxage so the EDGE cache stores
 * it for the requested TTL while the BROWSER continues to honour the
 * original max-age + stale-while-revalidate directives Astro set. Edits
 * the Cache-Control in place — preserves every directive except
 * s-maxage, which it replaces. */
async function withCacheTtl(response, ttlSeconds) {
  const body = await response.clone().arrayBuffer();
  const headers = new Headers(response.headers);

  const original = headers.get("Cache-Control") ?? "";
  const directives = original
    .split(",")
    .map((s) => s.trim())
    .filter((s) => s.length > 0 && !s.toLowerCase().startsWith("s-maxage="));
  directives.push(`s-maxage=${ttlSeconds}`);
  // Force `public` so the edge cache stores it even if the upstream
  // omitted explicit public/private (CF defaults to private on missing).
  if (!directives.some((d) => d.toLowerCase() === "public")) {
    directives.unshift("public");
  }
  headers.set("Cache-Control", directives.join(", "));

  return new Response(body, {
    status: response.status,
    statusText: response.statusText,
    headers,
  });
}

/** Decorate the outgoing response with a CF cache-status hint header so
 * the dashboard surfaces hit/stale/miss/origin without bespoke metrics. */
function tagResponse(response, status) {
  const headers = new Headers(response.headers);
  headers.set("X-Partna-Cache", status);
  return new Response(response.body, {
    status: response.status,
    statusText: response.statusText,
    headers,
  });
}

async function fetchAndCache(env, ctx, request, cache) {
  const fresh = await env.PARTNA_PAGES.fetch(request);

  if (fresh.ok && request.method === "GET") {
    // Primary cache — short-ish, push-purged on edits.
    ctx.waitUntil(
      cache.put(request, await withCacheTtl(fresh, PRIMARY_CACHE_TTL_S)),
    );
    // Stale shadow — long-lived. Refreshed on every successful origin
    // hit so the window never lapses while the page is being visited.
    ctx.waitUntil(
      cache.put(staleShadowKey(request), await withCacheTtl(fresh, STALE_SHADOW_TTL_S)),
    );
  }

  return fresh;
}

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
      entry = await env.SUBDOMAIN_KV.get(subdomain, {type: "json"});
    } catch (err) {
      // KV transient failure — fail open to avoid blocking user traffic.
      console.error("KV lookup failed", {subdomain, err: String(err)});
      return fetch(request);
    }

    if (!entry) {
      return new Response("Not Found", {
        status: 404,
        headers: {"Content-Type": "text/plain", "Cache-Control": "no-store"},
      });
    }

    // Alias entries redirect old subdomains to the canonical URL.
    // Written by SyncSubdomainToKvJob when a professional renames their handle.
    if (entry.type === "alias" && typeof entry.redirect === "string") {
      return new Response(null, {
        status: 301,
        headers: {
          Location: entry.redirect,
          // Without this, browsers cache 301s indefinitely. A handle rename
          // would leave stale redirects in client caches until users manually clear.
          "Cache-Control": "max-age=0, must-revalidate",
        },
      });
    }

    // Individual sitepage — served by the `partna-pages` Astro Worker via
    // Service Binding. Caching strategy described at the top of file.
    if (entry.type === "individual") {
      // Fail-fast if the binding hasn't been deployed yet.
      if (!env.PARTNA_PAGES || typeof env.PARTNA_PAGES.fetch !== "function") {
        console.error("PARTNA_PAGES service binding missing", {subdomain});
        return new Response("Service Unavailable", {
          status: 503,
          headers: {"Content-Type": "text/plain", "Cache-Control": "no-store"},
        });
      }

      // Only GETs are cacheable. POST / PUT / DELETE flow through to the
      // Astro Worker untouched so any future form-action paths in
      // partna-pages can mutate state without hitting a stale cached body.
      if (request.method !== "GET") {
        return env.PARTNA_PAGES.fetch(request);
      }

      const cache = caches.default;

      // 1) Primary cache HIT — fastest path. Tag and return.
      const cached = await cache.match(request);
      if (cached) {
        return tagResponse(cached, "hit");
      }

      // 2) Primary MISS — check the stale shadow. If present, serve it
      //    immediately and refresh both copies in the background. The
      //    visitor sees a (possibly minutes-old) render instantly while
      //    the new render lands for the NEXT visitor.
      const shadow = await cache.match(staleShadowKey(request));
      if (shadow) {
        ctx.waitUntil(fetchAndCache(env, ctx, request, cache));
        return tagResponse(shadow, "stale");
      }

      // 3) Cold miss — fetch from origin and populate both caches.
      const fresh = await fetchAndCache(env, ctx, request, cache);
      return tagResponse(fresh, fresh.ok ? "origin" : "origin-error");
    }

    // Unknown type or unhandled entry — pass through to origin.
    return fetch(request);
  },
};
