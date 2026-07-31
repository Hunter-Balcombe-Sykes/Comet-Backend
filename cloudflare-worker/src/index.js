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
 *     background via ctx.waitUntil.
 *   - Tags (audit O3): each subrequest tags the response with `cf-cache-
 *     status` (`hit` / `stale` / `miss` / `origin`).
 *
 * Security hardening (audit B2 — EDGE-1/7/8/9/12/13, SEC-5):
 *   - EVERY response path applies the baseline security headers (see
 *     applySecurityHeaders) so no return path lags the API.
 *   - Cached responses have Set-Cookie stripped (withCacheTtl) — an origin
 *     cookie must NEVER be edge-cached and replayed to other visitors.
 *   - The edge cache key drops the query string (cacheKeyFor) so tracking
 *     params (utm_*, fbclid, …) can't mint unbounded distinct cache entries.
 *   - Alias redirect targets are validated against partna.au before a 301
 *     (no open redirect even if a KV entry is poisoned).
 *   - A Content-Security-Policy is attached to sitepage responses in
 *     Report-Only mode — flip to enforcing once a real render is validated.
 */

// @sync config/partna.php `public_domain` (env PARTNA_PUBLIC_DOMAIN → SIDEST_PUBLIC_DOMAIN →
// APP_URL-host fallback chain, config/partna.php:61-66). The Worker has no env()-equivalent
// read of Laravel config, so this is a flat literal — a change to the backend's public
// domain (e.g. adopting a non-prod TLD) must be mirrored here by hand (EDGE-3).
const PARTNA_DOMAIN = "partna.au";

// The dashboard origin allowed to frame a sitepage — the /account/design
// preview iframe, and nothing else. Derived rather than repeated: the literal
// was written out twice (SITEPAGE_CSP and the enforced frame-ancestors header),
// which is one more place to miss than the rest of this file, whose domain
// references all read PARTNA_DOMAIN. Note this does NOT make the origin
// environment-driven — PARTNA_DOMAIN is itself a compile-time const (see EDGE-3
// above), and there is deliberately no non-prod Worker environment to be driven
// by (EDGE-102 removed [env.staging] from wrangler.toml). One const, one edit.
const DASHBOARD_ORIGIN = `https://app.${PARTNA_DOMAIN}`;

// Mirrors `reserved_subdomains` in config/partna.php (EDGE-6/EDGE-11). KEEP IN
// SYNC: a subdomain missing here is sent to KV and 404s instead of passing
// through to the apex origin. This is a manual mirror — when config changes,
// update this set (or wire a build step that generates it from the PHP config).
// @sync tests/Feature/Subdomain/ReservedSubdomainWorkerSyncTest.php (EDGE-2)
// parses THIS literal array out of this file and diffs it against
// config('partna.reserved_subdomains') on every test run — still the only
// automated guard against these two lists drifting: cloudflare-worker/test/ now
// has a Miniflare suite, but it runs in workerd with no access to Laravel config,
// so it can never see this mirror. A change to either side goes red until mirrored.
const RESERVED = new Set([
  // --- Platform infrastructure / DNS ---
  "www", "api", "admin", "app", "apps", "staff", "dashboard",
  "support", "help", "helpdesk", "billing", "static", "cdn", "assets",
  "auth", "docs", "status", "comet", "sidest", "partna",
  "mail", "email", "smtp", "imap", "pop", "pop3", "webmail",
  "ns", "ns1", "ns2", "ns3", "mx", "dns", "ftp", "sftp", "ssh", "vpn",
  "proxy", "gateway", "server", "host", "cloud", "edge", "worker", "workers",
  "kv", "db", "database", "redis", "cache", "queue", "jobs", "cron",
  "webhook", "webhooks", "callback", "callbacks", "localhost", "internal",
  "public", "private", "secure", "security", "ssl", "tls",
  // --- Environments / build stages ---
  "dev", "development", "prod", "production", "staging", "stage",
  "test", "tests", "testing", "qa", "uat", "sandbox", "preview",
  "beta", "alpha", "demo", "local",
  // --- Auth / account routes ---
  "login", "logout", "signin", "signup", "signout", "register",
  "account", "accounts", "settings", "profile", "profiles",
  "user", "users", "member", "members", "me", "my", "mine",
  "password", "reset", "forgot", "verify", "verification",
  "confirm", "activate", "activation", "oauth", "sso", "saml", "jwt",
  "token", "tokens", "key", "keys", "secret", "secrets",
  "onboarding", "install", "setup", "start",
  // --- Marketing / company pages ---
  "home", "about", "team", "company", "contact", "careers",
  "hiring", "press", "media", "news", "blog", "newsroom",
  "investors", "enterprise", "pricing", "plans", "features",
  "partner", "partners", "affiliate", "affiliates",
  "referral", "referrals", "brand", "brands", "community",
  // --- Commerce / store ---
  "shop", "store", "stores", "marketplace", "cart", "checkout",
  "order", "orders", "invoice", "invoices", "payment", "payments",
  "refund", "refunds", "subscription", "subscriptions",
  // --- Discovery / catalog ---
  "search", "explore", "discover", "trending", "popular", "top",
  "new", "latest", "featured", "browse", "category", "categories",
  "tag", "tags", "topic", "topics", "sitemap", "robots", "feed", "rss",
  // --- Legal / trust ---
  "terms", "tos", "privacy", "legal", "dmca", "copyright",
  "trademark", "abuse", "report", "compliance", "gdpr",
  // --- Developer / system ---
  "developer", "developers", "doc", "documentation",
  "api-docs", "graphql", "rest", "rpc", "sdk", "cli",
  "system", "service", "services", "root", "null", "undefined",
  "true", "false", "nil", "none", "error", "errors", "config",
  // --- AU government / regulators / common impersonation targets ---
  "ato", "asic", "accc", "acma", "austrac", "apra", "rba",
  "medicare", "mygov", "centrelink", "ndis", "ahpra", "fairwork",
  "servicesaustralia", "gov", "government", "police", "afp",
  "aec", "abs", "tga", "dva", "auspost",
  // --- Brand impersonation (high-risk lookalikes) ---
  "google", "apple", "microsoft", "amazon", "meta", "facebook",
  "instagram", "tiktok", "twitter", "youtube", "linkedin",
  "paypal", "stripe", "square", "shopify", "cloudflare",
  "anthropic", "claude", "openai", "chatgpt",
  // --- Profanity / slurs (exact-match only) ---
  "fuck", "fucker", "fucking", "motherfucker", "shit", "bullshit",
  "cunt", "bitch", "bastard", "asshole", "arsehole", "dick",
  "cock", "pussy", "slut", "whore", "twat", "wanker",
  "faggot", "fag", "nigger", "nigga", "retard", "tranny",
  "kike", "spic", "chink", "gook", "wetback", "raghead",
  "towelhead", "dyke", "shemale", "porn", "porno", "xxx", "nsfw",
]);

/** Primary cache TTL in seconds — 24 h, push-purged on mutation. CFG-1:
 *  the live value comes from wrangler.toml `[vars] PRIMARY_CACHE_TTL_S` —
 *  this is only the fallback default if that var is absent/unparseable.
 *  @sync app/Services/Cloudflare/CloudflarePurgeService.php `purgeHandle()` docblock,
 *  which cites this same "24 h" figure (EDGE-3) — bump both together. */
const PRIMARY_CACHE_TTL_S_DEFAULT = 86_400;

/** Stale-shadow TTL — 7 d. Wide window so even multi-day backend outages
 * serve the last good render. SWR refresh re-extends the shadow each
 * successful origin hit. CFG-1: the live value comes from wrangler.toml
 * `[vars] STALE_SHADOW_TTL_S` — this is only the fallback default if that
 * var is absent/unparseable.
 * @sync app/Services/Cloudflare/CloudflarePurgeService.php `purgeHandle()` docblock,
 * which cites this same "7-day TTL" (EDGE-3) — bump both together. */
const STALE_SHADOW_TTL_S_DEFAULT = 7 * 86_400;

/**
 * Content-Security-Policy for sitepage responses (EDGE-8). Shipped in
 * Report-Only mode: it does NOT block anything (and is currently INERT —
 * violations only surface in the browser console; `frame-ancestors` and other
 * navigation directives are ignored until the header is enforcing, so today's
 * clickjacking protection comes from X-Frame-Options, not this). It exists so a
 * real page can be validated against it before flipping the header name to
 * `Content-Security-Policy`. To collect violations centrally, add a `report-to`
 * directive + endpoint. The loose `script-src 'unsafe-inline' https:` is a
 * non-constraining baseline — tighten before enforcing.
 */
const SITEPAGE_CSP =
  "default-src 'self'; " +
  "img-src 'self' https: data:; " +
  "style-src 'self' 'unsafe-inline' https:; " +
  "font-src 'self' https: data:; " +
  "script-src 'self' 'unsafe-inline' https:; " +
  "connect-src 'self' https:; " +
  `frame-ancestors 'self' ${DASHBOARD_ORIGIN}; ` +
  "base-uri 'self'; " +
  "object-src 'none'";

/** Cache key for a request: the same URL with the query string and fragment
 * dropped (EDGE-9). Sitepage output depends on host + path, never on query
 * params, so collapsing every `?utm_*` / `?fbclid` variant to one key prevents
 * a shared/marketing link from minting unbounded distinct edge-cache entries.
 * The full original request (query intact) is still what we forward to origin. */
function cacheKeyFor(request) {
  const u = new URL(request.url);
  u.search = "";
  u.hash = "";
  return new Request(u.toString(), {method: "GET"});
}

/** Build a URL identifying the stale shadow for a given cache key. The
 * shadow lives under a different path so cache.match doesn't get confused;
 * the visitor never reaches this URL directly. Operates on the (already
 * query-stripped) cache key so the primary and shadow share normalisation. */
function staleShadowKey(cacheKey) {
  const u = new URL(cacheKey.url);
  u.pathname = `/_swr-shadow${u.pathname}`;
  return new Request(u.toString(), {method: "GET"});
}

/** Clone a response for the EDGE cache: overlay a long s-maxage so the edge
 * stores it for the requested TTL while the BROWSER keeps the original
 * max-age + stale-while-revalidate directives. EDGE-1: strip Set-Cookie so an
 * origin session cookie can never be cached and replayed to other visitors. */

// ── Branded 404 for unclaimed subdomains ─────────────────────────────────────
// Mirrors the pages app's notFoundHtml visual (same card, light/dark) but
// lives here so KV misses stay a pure edge response — no service-binding or
// backend hop for enumeration traffic. With a plausible subdomain the copy
// offers the address (growth surface); without one it's the generic miss.
// Domain references read PARTNA_DOMAIN rather than repeating the literal — one
// place to change. That const is still a flat literal (EDGE-3, see its comment):
// this is de-duplication, NOT environment-awareness.
function unclaimedHtml(subdomain) {
  const safe =
    typeof subdomain === "string" && /^[a-z0-9-]{1,63}$/.test(subdomain) ? subdomain : null;
  const headline = safe
    ? `${safe}.${PARTNA_DOMAIN} isn&#8217;t claimed yet`
    : "No Partna profile here";
  const subline = safe
    ? "This address is still available. Partna gives you a professional website that keeps itself current from the platforms you already use."
    : "The address you tried to visit doesn&#8217;t match a Partna account.";
  const cta = safe ? "Claim this address" : `Go to ${PARTNA_DOMAIN}`;
  return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex" />
  <title>Not found \u2014 Partna</title>
  <style>
:root { color-scheme: light dark; }
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, system-ui, sans-serif; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #fafafa; color: #1a1a1a; padding: 24px; }
@media (prefers-color-scheme: dark) { body { background: #0d0d0d; color: #f5f5f5; } .card { background: #1a1a1a; border-color: #2a2a2a; } a { color: #fafafa; } }
.card { max-width: 480px; width: 100%; background: #ffffff; border: 1px solid #ececec; border-radius: 12px; padding: 32px; text-align: center; }
.eyebrow { font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; opacity: 0.6; margin: 0 0 8px; }
h1 { font-size: 28px; font-weight: 600; margin: 0 0 12px; line-height: 1.2; overflow-wrap: anywhere; }
p { font-size: 15px; line-height: 1.6; margin: 0 0 16px; opacity: 0.8; }
a { display: inline-block; margin-top: 8px; color: #1a1a1a; text-decoration: underline; text-underline-offset: 3px; font-size: 14px; }
  </style>
</head>
<body>
  <main class="card">
    <p class="eyebrow">Partna</p>
    <h1>${headline}</h1>
    <p>${subline}</p>
    <a href="https://${PARTNA_DOMAIN}">${cta}</a>
  </main>
</body>
</html>`;
}

async function withCacheTtl(response, ttlSeconds) {
  const body = await response.clone().arrayBuffer();
  const headers = new Headers(response.headers);

  // EDGE-1: a cached copy must never carry a per-visitor cookie.
  headers.delete("Set-Cookie");
  // EDGE-1: a stale/varying origin Vary can't poison the shared edge cache —
  // every visitor gets the same cached representation regardless of their
  // own request headers.
  headers.delete("Vary");

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

/** Apply the standard set of security headers in place on a Headers
 * instance. Mirrors the backend SecureHeaders middleware. Uses `has()`
 * guards so we never clobber a header the origin already set.
 *
 * - HSTS: 1 year + includeSubDomains.
 * - X-Content-Type-Options: nosniff — blocks MIME sniffing.
 * - Referrer-Policy: strict-origin-when-cross-origin.
 * - X-Frame-Options: SAMEORIGIN — clickjacking defence for older browsers. */
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
}

/**
 * Finalise any response before returning it to the visitor (EDGE-7): clone it,
 * apply the baseline security headers, and (for sitepage responses) attach the
 * Report-Only CSP. Every return path in this Worker goes through here so none
 * lags on hardening.
 *
 * @param {Response} response
 * @param {{cacheStatus?: string, sitepage?: boolean, noStore?: boolean}} opts
 */
function finalize(response, opts = {}) {
  const headers = new Headers(response.headers);
  applySecurityHeaders(headers);

  if (opts.cacheStatus) {
    headers.set("X-Partna-Cache", opts.cacheStatus);
  }
  if (opts.sitepage) {
    // Enforce ONLY frame-ancestors — it replaces X-Frame-Options (which can't
    // allow-list a cross-origin embedder) so the /account/design preview iframe
    // on the dashboard origin can embed the sitepage, while every other origin
    // stays refused. The rest of the policy remains Report-Only until validated.
    headers.delete("X-Frame-Options");
    headers.set("Content-Security-Policy", `frame-ancestors 'self' ${DASHBOARD_ORIGIN}`);
    headers.set("Content-Security-Policy-Report-Only", SITEPAGE_CSP);
  }
  // EDGE-12: don't let a misconfigured origin error page get cached by browsers.
  if (opts.noStore) {
    headers.set("Cache-Control", "no-store");
  }

  return new Response(response.body, {
    status: response.status,
    statusText: response.statusText,
    headers,
  });
}

/** Pass a request straight through to its origin (apex, reserved, custom, or
 * unknown host) but still stamp the baseline security headers (EDGE-7). A
 * WebSocket upgrade (101) is returned RAW — re-wrapping via new Response() would
 * drop response.webSocket and break the connection. */
async function passThrough(request) {
  const response = await fetch(request);
  if (response.status === 101 || response.webSocket) {
    return response;
  }
  return finalize(response);
}

async function fetchAndCache(env, ctx, cacheKey, cache, originRequest) {
  // `cacheKey` is the normalised (query-stripped) cache key; `originRequest`
  // carries the full URL + the sanitized x-partna-handle header upstream.
  const fresh = await env.PARTNA_PAGES.fetch(originRequest);

  if (fresh.ok && originRequest.method === "GET") {
    // CFG-1: wrangler.toml `[vars]` is the configured source; fall back to the
    // module default if the var is missing or not a valid number.
    const primaryTtl = Number(env.PRIMARY_CACHE_TTL_S) || PRIMARY_CACHE_TTL_S_DEFAULT;
    const shadowTtl = Number(env.STALE_SHADOW_TTL_S) || STALE_SHADOW_TTL_S_DEFAULT;
    // EDGE-13: surface cache.put failures instead of letting a rejected
    // waitUntil promise vanish silently.
    ctx.waitUntil(
      withCacheTtl(fresh, primaryTtl)
        .then((r) => cache.put(cacheKey, r))
        // PRIV-1: don't put the raw cache-key URL in the structured field.
        .catch((err) => console.error("primary cache.put failed", {err: String(err)})),
    );
    ctx.waitUntil(
      withCacheTtl(fresh, shadowTtl)
        .then((r) => cache.put(staleShadowKey(cacheKey), r))
        // PRIV-1: don't put the raw cache-key URL in the structured field.
        .catch((err) => console.error("shadow cache.put failed", {err: String(err)})),
    );
  }

  return fresh;
}

/**
 * Build the request forwarded to partna-pages. ALWAYS strips any
 * visitor-supplied `x-partna-handle` — a router-only signal a spoofed value
 * must never reach partna-pages (it would let a visitor render someone else's
 * page). Sets our own value only for custom-domain requests.
 *
 * EDGE-2: also strips Cookie and Authorization — sitepages are public/static
 * and must never receive visitor credentials on the forwarded request.
 */
function withHandleHeader(request, handle) {
  const headers = new Headers(request.headers);
  headers.delete("x-partna-handle");
  headers.delete("Cookie");
  headers.delete("Authorization");
  if (handle) {
    headers.set("x-partna-handle", handle);
  }
  return new Request(request, {headers});
}

/**
 * Serve an individual sitepage from partna-pages with the edge cache + SWR
 * strategy. `handleOverride` is the resolved handle for custom-domain requests;
 * null for <handle>.partna.au, where partna-pages parses the handle from Host.
 */
async function serveIndividual(env, ctx, request, handleOverride) {
  // Fail-fast if the binding hasn't been deployed yet.
  if (!env.PARTNA_PAGES || typeof env.PARTNA_PAGES.fetch !== "function") {
    console.error("PARTNA_PAGES service binding missing");
    return finalize(
      new Response("Service Unavailable", {status: 503, headers: {"Content-Type": "text/plain"}}),
      {noStore: true},
    );
  }

  const originRequest = withHandleHeader(request, handleOverride);

  // Bypass the edge entirely for preview-shaped requests: ?preview= (the
  // dashboard live preview), ?architecture= (transient alternate architecture),
  // or legacy ?skeleton=. No cache read, no cache write — cacheKeyFor() strips
  // the query string, so a cached preview would pin under the plain URL's key
  // for the full 24h TTL. Always fetch fresh. EDGE-7: still finalise so the
  // preview carries security headers.
  //
  // Known trade-off (accepted, see the 2026-07-25 cache-freshness design): any
  // of these params is a cache-busting lever for anonymous traffic. Not new —
  // ?architecture= and ?skeleton= already were — but "preview" is more
  // guessable. Cloudflare bot protection sits in front; origin rate-limiting
  // for bypass params is separate, out-of-scope work.
  const previewParams = new URL(request.url).searchParams;
  if (previewParams.has("preview") || previewParams.has("skeleton") || previewParams.has("architecture")) {
    return finalize(await env.PARTNA_PAGES.fetch(originRequest), {sitepage: true, noStore: true});
  }

  // Only GETs are cacheable. POST / PUT / DELETE flow through untouched so any
  // future form-action paths can mutate state without hitting a stale body.
  if (request.method !== "GET") {
    return finalize(await env.PARTNA_PAGES.fetch(originRequest), {sitepage: true});
  }

  const cache = caches.default;
  const cacheKey = cacheKeyFor(request);

  // 1) Primary cache HIT — fastest path.
  const cached = await cache.match(cacheKey);
  if (cached) {
    return finalize(cached, {cacheStatus: "hit", sitepage: true});
  }

  // 2) Primary MISS — serve the stale shadow if present, refresh in background.
  const shadow = await cache.match(staleShadowKey(cacheKey));
  if (shadow) {
    ctx.waitUntil(fetchAndCache(env, ctx, cacheKey, cache, originRequest));
    return finalize(shadow, {cacheStatus: "stale", sitepage: true});
  }

  // 3) Cold miss — fetch from origin and populate both caches.
  const fresh = await fetchAndCache(env, ctx, cacheKey, cache, originRequest);
  return finalize(fresh, {
    cacheStatus: fresh.ok ? "origin" : "origin-error",
    sitepage: true,
    // EDGE-12: never let an origin error response get cached by the browser.
    noStore: !fresh.ok,
  });
}

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    const hostname = url.hostname.toLowerCase();

    // Force HTTPS for every request the router sees. 301 (permanent upgrade);
    // HSTS (applied below) handles the repeat-visit case.
    if (url.protocol === "http:") {
      const httpsUrl = new URL(request.url);
      httpsUrl.protocol = "https:";
      return finalize(new Response(null, {status: 301, headers: {Location: httpsUrl.toString()}}));
    }

    // Apex partna.au passes through untouched (but still hardened — EDGE-7).
    if (hostname === PARTNA_DOMAIN) {
      return passThrough(request);
    }

    // Custom domains (Cloudflare for SaaS): a host NOT under partna.au may be a
    // user-connected domain. Resolve `domain:<host>` in KV → handle, then serve
    // partna-pages with that handle injected. Unknown hosts pass through.
    if (!hostname.endsWith("." + PARTNA_DOMAIN)) {
      let custom = null;
      try {
        custom = await env.SUBDOMAIN_KV.get(`domain:${hostname}`, {type: "json"});
      } catch (err) {
        // KV transient failure — fail open to avoid blocking traffic.
        // PRIV-1: keep the raw hostname out of structured logs — message + err
        // is enough to act on, and hostname (a visitor-controlled value) is
        // not something we want persisted verbatim in log storage.
        console.error("KV custom-domain lookup failed", {err: String(err)});
        return passThrough(request);
      }
      if (custom && custom.type === "individual" && typeof custom.handle === "string") {
        return serveIndividual(env, ctx, request, custom.handle);
      }
      return passThrough(request);
    }

    const subdomain = hostname.slice(0, -1 * (PARTNA_DOMAIN.length + 1));

    // Multi-level subdomains and reserved labels pass through.
    if (subdomain === "" || subdomain.includes(".") || RESERVED.has(subdomain)) {
      return passThrough(request);
    }

    let entry = null;
    let kvErrored = false;
    try {
      entry = await env.SUBDOMAIN_KV.get(subdomain, {type: "json"});
    } catch (err) {
      // KV transient failure (EDGE-4). Previously this fell through to
      // passThrough(request) — a DIFFERENT, worse UX than a genuine miss (which
      // serves the branded unclaimedHtml 404 below): passThrough hits the apex
      // origin with a subdomain Host it doesn't expect, typically surfacing a raw
      // origin error. Serve the SAME branded page a real miss would, so an outage
      // degrades gracefully instead of visibly differently — but tag the response
      // `X-Partna-Cache: kv-error` (vs a miss's own tag below) so ops can tell a
      // true KV outage apart from routine unclaimed-subdomain traffic in logs.
      // PRIV-1: don't put the raw subdomain in the structured field.
      console.error("KV lookup failed", {err: String(err)});
      kvErrored = true;
    }

    if (kvErrored) {
      return finalize(
        new Response(unclaimedHtml(subdomain), {
          status: 404,
          headers: {"Content-Type": "text/html; charset=utf-8"},
        }),
        {cacheStatus: "kv-error", noStore: true},
      );
    }

    if (!entry) {
      // Branded 404 for unclaimed subdomains — same visual language as the
      // pages app's notFoundHtml, inlined here so the edge keeps absorbing
      // enumeration spam without a service-binding + backend hop. Unclaimed
      // handles are a growth surface: the CTA offers the address.
      return finalize(
        new Response(unclaimedHtml(subdomain), {
          status: 404,
          headers: {"Content-Type": "text/html; charset=utf-8"},
        }),
        {noStore: true},
      );
    }

    // Alias entries 301 old subdomains to the canonical URL (written by
    // SyncSubdomainToKvJob on rename). SEC-5: validate the redirect target is a
    // partna.au URL before trusting it — a poisoned KV entry must NOT become an
    // open redirect to an attacker-controlled host.
    if (entry.type === "alias" && typeof entry.redirect === "string") {
      let redirectBase = null;
      try {
        const candidate = new URL(entry.redirect);
        const okHost =
          candidate.protocol === "https:" &&
          (candidate.hostname === PARTNA_DOMAIN || candidate.hostname.endsWith("." + PARTNA_DOMAIN));
        if (okHost) {
          redirectBase = candidate;
        }
      } catch (err) {
        // Malformed redirect value — treat as untrusted (redirectBase stays null).
        // PRIV-1: don't put the raw subdomain in the structured field.
        console.error("alias redirect parse failed", {err: String(err)});
      }

      if (redirectBase) {
        // Preserve the deep link: `/gallery?x=1` on the old handle → same path
        // on the canonical handle. entry.redirect is a bare origin, so build the
        // target from its origin only (ignore any path it may carry).
        const target = `${redirectBase.origin}${url.pathname}${url.search}`;
        return finalize(
          new Response(null, {
            status: 301,
            headers: {Location: target, "Cache-Control": "max-age=0, must-revalidate"},
          }),
        );
      }

      // Untrusted/invalid alias target — fail closed to 404 rather than redirect.
      return finalize(
        new Response(unclaimedHtml(null), {
          status: 404,
          headers: {"Content-Type": "text/html; charset=utf-8"},
        }),
        {noStore: true},
      );
    }

    // Individual sitepage — partna-pages derives the handle from Host, so no
    // override here.
    if (entry.type === "individual") {
      return serveIndividual(env, ctx, request, null);
    }

    // Unknown type or unhandled entry — pass through to origin.
    return passThrough(request);
  },
};
