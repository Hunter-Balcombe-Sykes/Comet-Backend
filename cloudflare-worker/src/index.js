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
// The dashboard's dev server may frame a sitepage too (the Design page's live
// preview on localhost, owner 2026-08-19) — a bare origin, no path, so this
// allow-lists nothing a visitor could reach.
const DASHBOARD_DEV_ORIGIN = "http://localhost:3000";
const FRAME_ANCESTORS = `'self' ${DASHBOARD_ORIGIN} ${DASHBOARD_DEV_ORIGIN}`;

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
    "www",
    "api",
    "admin",
    "app",
    "apps",
    "staff",
    "dashboard",
    "support",
    "help",
    "helpdesk",
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
    "mail",
    "email",
    "smtp",
    "imap",
    "pop",
    "pop3",
    "webmail",
    "ns",
    "ns1",
    "ns2",
    "ns3",
    "mx",
    "dns",
    "ftp",
    "sftp",
    "ssh",
    "vpn",
    "proxy",
    "gateway",
    "server",
    "host",
    "cloud",
    "edge",
    "worker",
    "workers",
    "kv",
    "db",
    "database",
    "redis",
    "cache",
    "queue",
    "jobs",
    "cron",
    "webhook",
    "webhooks",
    "callback",
    "callbacks",
    "localhost",
    "internal",
    "public",
    "private",
    "secure",
    "security",
    "ssl",
    "tls",
    // --- Environments / build stages ---
    "dev",
    "development",
    "prod",
    "production",
    "staging",
    "stage",
    "test",
    "tests",
    "testing",
    "qa",
    "uat",
    "sandbox",
    "preview",
    "beta",
    "alpha",
    "demo",
    "local",
    // --- Auth / account routes ---
    "login",
    "logout",
    "signin",
    "signup",
    "signout",
    "register",
    "account",
    "accounts",
    "settings",
    "profile",
    "profiles",
    "user",
    "users",
    "member",
    "members",
    "me",
    "my",
    "mine",
    "password",
    "reset",
    "forgot",
    "verify",
    "verification",
    "confirm",
    "activate",
    "activation",
    "oauth",
    "sso",
    "saml",
    "jwt",
    "token",
    "tokens",
    "key",
    "keys",
    "secret",
    "secrets",
    "onboarding",
    "install",
    "setup",
    "start",
    // --- Marketing / company pages ---
    "home",
    "about",
    "team",
    "company",
    "contact",
    "careers",
    "hiring",
    "press",
    "media",
    "news",
    "blog",
    "newsroom",
    "investors",
    "enterprise",
    "pricing",
    "plans",
    "features",
    "partner",
    "partners",
    "affiliate",
    "affiliates",
    "referral",
    "referrals",
    "brand",
    "brands",
    "community",
    // --- Commerce / store ---
    "shop",
    "store",
    "stores",
    "marketplace",
    "cart",
    "checkout",
    "order",
    "orders",
    "invoice",
    "invoices",
    "payment",
    "payments",
    "refund",
    "refunds",
    "subscription",
    "subscriptions",
    // --- Discovery / catalog ---
    "search",
    "explore",
    "discover",
    "trending",
    "popular",
    "top",
    "new",
    "latest",
    "featured",
    "browse",
    "category",
    "categories",
    "tag",
    "tags",
    "topic",
    "topics",
    "sitemap",
    "robots",
    "feed",
    "rss",
    // --- Legal / trust ---
    "terms",
    "tos",
    "privacy",
    "legal",
    "dmca",
    "copyright",
    "trademark",
    "abuse",
    "report",
    "compliance",
    "gdpr",
    // --- Developer / system ---
    "developer",
    "developers",
    "doc",
    "documentation",
    "api-docs",
    "graphql",
    "rest",
    "rpc",
    "sdk",
    "cli",
    "system",
    "service",
    "services",
    "root",
    "null",
    "undefined",
    "true",
    "false",
    "nil",
    "none",
    "error",
    "errors",
    "config",
    // --- AU government / regulators / common impersonation targets ---
    "ato",
    "asic",
    "accc",
    "acma",
    "austrac",
    "apra",
    "rba",
    "medicare",
    "mygov",
    "centrelink",
    "ndis",
    "ahpra",
    "fairwork",
    "servicesaustralia",
    "gov",
    "government",
    "police",
    "afp",
    "aec",
    "abs",
    "tga",
    "dva",
    "auspost",
    // --- Brand impersonation (high-risk lookalikes) ---
    "google",
    "apple",
    "microsoft",
    "amazon",
    "meta",
    "facebook",
    "instagram",
    "tiktok",
    "twitter",
    "youtube",
    "linkedin",
    "paypal",
    "stripe",
    "square",
    "shopify",
    "cloudflare",
    "anthropic",
    "claude",
    "openai",
    "chatgpt",
    // --- Profanity / slurs (exact-match only) ---
    "fuck",
    "fucker",
    "fucking",
    "motherfucker",
    "shit",
    "bullshit",
    "cunt",
    "bitch",
    "bastard",
    "asshole",
    "arsehole",
    "dick",
    "cock",
    "pussy",
    "slut",
    "whore",
    "twat",
    "wanker",
    "faggot",
    "fag",
    "nigger",
    "nigga",
    "retard",
    "tranny",
    "kike",
    "spic",
    "chink",
    "gook",
    "wetback",
    "raghead",
    "towelhead",
    "dyke",
    "shemale",
    "porn",
    "porno",
    "xxx",
    "nsfw",
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
    `frame-ancestors ${FRAME_ANCESTORS}; ` +
    "base-uri 'self'; " +
    "object-src 'none'";

/** Cache key for a request: the same URL with the query string and fragment
 * dropped (EDGE-9). Sitepage output depends on host + path, never on query
 * params, so collapsing every `?utm_*` / `?fbclid` variant to one key prevents
 * a shared/marketing link from minting unbounded distinct edge-cache entries.
 * The full original request (query intact) is still what we forward to origin.
 * @param {Request} request
 * @returns {Request} */
function cacheKeyFor(request) {
    const u = new URL(request.url);
    u.search = "";
    u.hash = "";
    return new Request(u.toString(), {method: "GET"});
}

/** Build a URL identifying the stale shadow for a given cache key. The
 * shadow lives under a different path so cache.match doesn't get confused;
 * the visitor never reaches this URL directly. Operates on the (already
 * query-stripped) cache key so the primary and shadow share normalisation.
 * @param {Request} cacheKey
 * @returns {Request} */
function staleShadowKey(cacheKey) {
    const u = new URL(cacheKey.url);
    u.pathname = `/_swr-shadow${u.pathname}`;
    return new Request(u.toString(), {method: "GET"});
}

/** Clone a response for the EDGE cache: overlay a long s-maxage so the edge
 * stores it for the requested TTL while the BROWSER keeps the original
 * max-age + stale-while-revalidate directives. EDGE-1: strip Set-Cookie so an
 * origin session cookie can never be cached and replayed to other visitors. */

// ── Branded 404 for unclaimed subdomains ─────────────────────────
// Partna's own surface, wearing Partna's wordmark and the design kit's
// vocabulary — never a broken-looking version of somebody's sitepage. It lives
// HERE rather than behind the service binding so a KV miss stays a pure edge
// response: enumeration traffic never costs a binding or backend hop.
//
// A miss on a plausible handle is the platform's best acquisition moment — the
// visitor has just typed an address they expected to exist — so the address is
// the hero and the primary action carries it into signup.
// app.partna.au/claim/<handle> 307s to /sign-up?claim=<handle>, so the handle
// survives the whole funnel.
//
// The kit cannot be imported across the repo boundary, so its values are
// restated as literals below. They are the shipped --dk-* defaults
// (packages/design-system/src/design-kit/vars.css) and are the ONLY reason
// hardcoded colours appear in this file.
/** The Partna wordmark, `currentColor` throughout so it inherits ink. Copied
 *  verbatim from the pages app's not-found surface — same mark, same file. */
const PARTNA_LOGO_SVG = `<svg class="pn-logo" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 455.36 89.7" role="img" aria-label="Partna"><polygon fill="currentColor" points="87.81 66.71 66.73 87.96 30.03 51.31 29.88 88.14 0 88.16 0 .02 88.03 0 88.1 29.92 51.24 30.03 87.81 66.71"/><path fill="currentColor" d="M165.71,51.58c-5.24,2.44-10.57,3.56-16.28,3.57l-21.09.05v32.96l-13.58-.02V0l34.64.05c5.54,0,10.67,1.07,15.61,3.21,7.52,3.38,12.81,9.78,14.26,17.91,2.23,12.46-1.74,24.57-13.57,30.45v-.03ZM165.26,22.95c-.92-4.03-3.62-7.03-7.4-8.69-2.92-1.14-5.98-1.77-9.21-1.77l-20.3-.03v30.45l20.79-.06c3.27,0,6.28-.82,9.16-2.04,6.77-3.22,8.57-10.84,6.96-17.85Z"/><path fill="currentColor" d="M293.2,33.99l-9.03.13h0s-7.93.31-7.93.31c-2.05.16-4.13,1.05-5.96,1.71-5.48,2.01-7.43,7.12-7.6,12.87v40.08h-13.35V23.01h12.25l.44,11.46c2-6.39,6.68-11.26,13.37-11.33l17.82-.15V7.51l13.35-.02v15.5h17.56v10.99h-17.56l.09,37.93c0,3.5,2.42,5.62,5.62,6.12l11.86.08v10.98h-13.38c-3.01-.06-5.83-.51-8.61-1.54-3.83-1.56-6.66-4.57-7.8-8.58-.61-2.12-1.07-4.32-1.07-6.63v-38.32l-.05-.02Z"/><path fill="currentColor" d="M437.35,78.28c-6.22,11.89-24.95,14.19-36.14,8.36-6.1-3.16-8.99-9.04-8.81-15.78.18-6.74,3.03-12.2,8.93-15.35,3.39-1.82,7.01-3.16,10.92-3.92l23.98-4.6c0-6.51-2.17-12.55-8.72-14.34-4.19-1.15-8.55-.97-12.66.48-4.07,1.77-6.6,5.33-7.6,9.84l-13.72-.86c1.59-9.92,8.51-17.55,18.15-20.2,6.77-1.74,13.82-1.83,20.61-.02,7.28,1.94,12.85,7.07,15.35,14.13,1.18,3.33,1.86,6.72,1.88,10.33l.08,28.36c0,1.68,1.26,2.83,2.77,2.86l3,.06v10.58c-5.66.76-12.6.62-15.85-3.95-1.24-1.74-1.8-3.77-2.18-5.97l.02-.02ZM412.26,78.38c3.86,1.07,7.9.85,11.69-.23,6.93-1.97,11.66-7.96,12.19-15.1l.11-5.97-20,3.85c-5.6,1.07-10.07,3.69-9.99,9.63.05,3.69,2.21,6.75,6.01,7.81Z"/><path fill="currentColor" d="M226.51,78.44c-6.45,11.73-24.74,13.9-35.82,8.33-6.03-3.03-9.28-8.81-9.05-15.49.12-3.71.76-7.34,2.97-10.49,3.68-5.25,10.87-8.05,17.2-9.28l23.54-4.51c.29-6.5-2.24-12.69-8.75-14.4-4.21-1.11-8.57-.94-12.64.58-4.09,1.76-6.5,5.42-7.51,9.78l-13.69-.88c1.57-9.77,8.31-17.31,17.77-20.09,6.83-1.83,13.99-1.95,20.85-.15,11.64,3.07,17.34,13.22,17.35,24.97l.05,27.8c0,1.8,1.36,2.94,2.92,2.97l2.94.03v10.57c-6.31.92-14.08.53-16.73-5.36l-1.41-4.35.02-.02ZM201.8,78.47c3.83.94,7.75.73,11.48-.35,7.12-2.06,12.07-8.48,12.1-15.87v-5.15l-20.33,3.92c-5.34,1.03-9.43,3.63-9.57,9.25-.09,3.94,2.26,7.21,6.33,8.21v-.02Z"/><path fill="currentColor" d="M360.63,31.78c-7.42-.32-13.7,3.92-15.47,11.11-.61,2.42-.83,4.86-.83,7.49v37.79l-13.34-.02V22.09l12.26-.02.3,10.86c2.42-5.72,7.13-9.87,13.23-11.49,10.72-2.53,20.88.29,26.07,10.34,1.98,3.83,2.73,7.78,3.09,12.14v44.24h-13.34v-40.46c-.45-8.42-2.79-15.55-11.96-15.93h-.02Z"/></svg>`;

/**
 * The unclaimed / not-found document.
 *
 * THEMING: light and dark are expressed purely as custom-property values on
 * `:root`, and every rule below reads them through `var()`. This is deliberate
 * and worth keeping. The previous version put `.card { background: #fff }`
 * AFTER its own `@media (prefers-color-scheme: dark)` block; media queries add
 * no specificity, so the later base rule won and the card stayed white while
 * the text inherited the dark scheme's near-white ink — the whole page was
 * white-on-white for every visitor in dark mode, with only the link legible
 * (it happened to be overridden the same way). Redefining variables cannot
 * lose that fight, whatever order the blocks end up in.
 *
 * `subdomain` is only echoed back when it matches the handle grammar, which is
 * also what makes it safe to interpolate unescaped.
 * @param {string|null} subdomain
 * @returns {string}
 */
function unclaimedHtml(subdomain) {
    const safe =
        typeof subdomain === "string" && /^[a-z0-9-]{1,63}$/.test(subdomain) ? subdomain : null;
    const address = safe ? `${safe}.${PARTNA_DOMAIN}` : null;
    const appUrl = `https://app.${PARTNA_DOMAIN}`;

    const title = address ? `${address} — Partna` : `Not found — Partna`;
    const eyebrow = safe ? "Unclaimed address" : "Not found";
    const headline = address || "No site at this address";
    const lead = safe
        ? "No one is here yet. This address is free — it could be your site."
        : `The address you followed doesn’t belong to a Partna site. It may have moved, or the link may be mistyped.`;
    // Ordinary signup, NOT /claim/<handle>. Claiming is for a site that already
    // EXISTS but has no owner yet — those serve normally (they carry the claim
    // ribbon), so they never reach this page. Getting here means the KV lookup
    // missed, i.e. there is no site and nothing to claim: sign-up-flow.tsx's
    // claim mode assumes "the site already exists", and its final claimSite()
    // would throw — after the visitor had already handed over an email and a
    // code. A dead end at the worst possible moment. Ordinary signup is the
    // honest destination, so the label says create, not claim.
    const primaryHref = `${appUrl}/sign-up`;
    const primaryLabel = "Create your site";
    const secondaryHref = `https://${PARTNA_DOMAIN}`;
    const secondaryLabel = "What is Partna?";

    return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="robots" content="noindex" />
  <meta name="color-scheme" content="light dark" />
  <meta name="theme-color" content="#f5f6f7" media="(prefers-color-scheme: light)" />
  <meta name="theme-color" content="#0c0c0d" media="(prefers-color-scheme: dark)" />
  <title>${title}</title>
  <style>
/* The shipped --dk-* defaults, restated as literals (see the note above). */
:root {
  color-scheme: light dark;
  --pn-bg: rgb(245, 246, 247);
  --pn-surface: rgb(255, 255, 255);
  --pn-line: rgb(236, 237, 239);
  --pn-ink: rgb(0, 0, 0);
  /* gray-700 / gray-600, not gray-600 / gray-500: the lighter pair measured
     3.28:1 and 3.03:1 against these surfaces, under the 4.5:1 AA floor for
     text this small. These clear it at 4.9 and 4.6. */
  --pn-ink-soft: rgb(77, 77, 77);
  --pn-ink-faint: rgb(112, 112, 112);
  --pn-solid-bg: rgb(0, 0, 0);
  --pn-solid-ink: rgb(255, 255, 255);
  --pn-accent: #1367fb;
}

@media (prefers-color-scheme: dark) {
  :root {
    --pn-bg: rgb(12, 12, 13);
    --pn-surface: rgb(23, 23, 25);
    --pn-line: rgb(43, 43, 47);
    --pn-ink: rgb(250, 250, 250);
    --pn-ink-soft: rgb(163, 163, 168);
    --pn-ink-faint: rgb(138, 138, 143);
    --pn-solid-bg: rgb(250, 250, 250);
    --pn-solid-ink: rgb(0, 0, 0);
    --pn-accent: #5b9bff;
  }
}

*, *::before, *::after { box-sizing: border-box; }

html, body { margin: 0; padding: 0; }

body {
  min-height: 100dvh;
  background: var(--pn-bg);
  color: var(--pn-ink);
  /* The kit's family first; this surface never fetches a webfont, so the
     system fallback is what most visitors actually read. */
  font-family: "Helvetica Neue", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  -webkit-font-smoothing: antialiased;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1.75rem;
  padding: 1.75rem 1.125rem calc(1.75rem + env(safe-area-inset-bottom));
}

.pn-card {
  width: 100%;
  max-width: 26rem;
  background: var(--pn-surface);
  border: 1px solid var(--pn-line);
  border-radius: 0.35rem;
  padding: 2.4rem 1.75rem;
  text-align: center;
}

.pn-logo {
  display: block;
  width: 5.5rem;
  height: auto;
  margin: 0 auto 2.4rem;
  color: var(--pn-ink);
}

.pn-eyebrow {
  margin: 0 0 0.6rem;
  font-size: 0.75rem;
  font-weight: 400;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--pn-ink-faint);
}

.pn-address {
  margin: 0 0 0.6rem;
  /* The address is what the visitor typed, so it leads — and a 63-character
     handle must not push the card sideways. */
  font-size: clamp(1.5rem, 7vw, 2rem);
  font-weight: 500;
  line-height: 1.15;
  letter-spacing: -0.01em;
  overflow-wrap: anywhere;
  color: var(--pn-ink);
}

.pn-lead {
  margin: 0 auto 2.4rem;
  max-width: 22rem;
  font-size: 0.9rem;
  line-height: 1.6;
  color: var(--pn-ink-soft);
}

.pn-actions {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.pn-btn {
  display: block;
  padding: 0.85rem 1.125rem;
  border: 1px solid transparent;
  border-radius: 0.35rem;
  font-size: 0.85rem;
  font-weight: 500;
  text-decoration: none;
  transition: opacity 0.25s cubic-bezier(0.2, 0, 0, 1);
}

.pn-btn-primary {
  background: var(--pn-solid-bg);
  color: var(--pn-solid-ink);
}

.pn-btn-secondary {
  background: transparent;
  border-color: var(--pn-line);
  color: var(--pn-ink);
}

.pn-btn:focus-visible {
  outline: 2px solid var(--pn-accent);
  outline-offset: 2px;
}

@media (hover: hover) and (pointer: fine) {
  .pn-btn-primary:hover { opacity: 0.82; }
  .pn-btn-secondary:hover { border-color: var(--pn-ink-faint); }
}

@media (prefers-reduced-motion: reduce) {
  .pn-btn { transition: none; }
}

.pn-foot {
  font-size: 0.75rem;
  color: var(--pn-ink-faint);
}

.pn-foot a {
  color: inherit;
  text-decoration: none;
}

.pn-foot a:focus-visible {
  outline: 2px solid var(--pn-accent);
  outline-offset: 2px;
}

@media (hover: hover) and (pointer: fine) {
  .pn-foot a:hover { color: var(--pn-ink-soft); }
}
  </style>
</head>
<body>
  <main class="pn-card">
    ${PARTNA_LOGO_SVG}
    <p class="pn-eyebrow">${eyebrow}</p>
    <h1 class="pn-address">${headline}</h1>
    <p class="pn-lead">${lead}</p>
    <div class="pn-actions">
      <a class="pn-btn pn-btn-primary" href="${primaryHref}">${primaryLabel}</a>
      <a class="pn-btn pn-btn-secondary" href="${secondaryHref}">${secondaryLabel}</a>
    </div>
  </main>
  <footer class="pn-foot"><a href="https://${PARTNA_DOMAIN}">${PARTNA_DOMAIN}</a></footer>
</body>
</html>`;
}

/**
 * @param {Response} response
 * @param {number} ttlSeconds
 * @returns {Promise<Response>}
 */
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
 * - X-Frame-Options: SAMEORIGIN — clickjacking defence for older browsers.
 * @param {Headers} headers
 * @returns {void} */
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
        headers.set("Content-Security-Policy", `frame-ancestors ${FRAME_ANCESTORS}`);
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
 * drop response.webSocket and break the connection.
 * @param {Request} request
 * @returns {Promise<Response>} */
async function passThrough(request) {
    const response = await fetch(request);
    if (response.status === 101 || response.webSocket) {
        return response;
    }
    return finalize(response);
}

/**
 * @param {Env} env
 * @param {ExecutionContext} ctx
 * @param {Request} cacheKey
 * @param {Cache} cache
 * @param {Request} originRequest
 * @returns {Promise<Response>}
 */
/** The origin's s-maxage in seconds, or null when it sends none. */
function originSMaxAge(cacheControl) {
    const m = /(?:^|,)\s*s-maxage=(\d+)/i.exec(cacheControl ?? "");
    if (!m) return null;
    const n = Number(m[1]);
    return Number.isFinite(n) && n > 0 ? n : null;
}

async function fetchAndCache(env, ctx, cacheKey, cache, originRequest) {
    // `cacheKey` is the normalised (query-stripped) cache key; `originRequest`
    // carries the full URL + the sanitized x-partna-handle header upstream.
    const fresh = await env.PARTNA_PAGES.fetch(originRequest);

    if (fresh.ok && originRequest.method === "GET") {
        // CFG-1: wrangler.toml `[vars]` is the configured source; fall back to the
        // module default if the var is missing or not a valid number.
        // The origin's own s-maxage caps the edge TTL when it is SHORTER
        // (2026-09-02): an unclaimed build renders with s-maxage=10 while
        // its mirrors are still landing, and holding that first render for
        // a day left the gallery on its seed reel until someone purged.
        // Claimed sites still say 30–300s and take the configured TTL.
        const originTtl = originSMaxAge(fresh.headers.get("Cache-Control"));
        const configuredTtl = Number(env.PRIMARY_CACHE_TTL_S) || PRIMARY_CACHE_TTL_S_DEFAULT;
        const primaryTtl = originTtl !== null && originTtl < configuredTtl ? originTtl : configuredTtl;
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
 *
 * @param {Request} request
 * @param {string|null} handle
 * @returns {Request}
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
 *
 * @param {Env} env
 * @param {ExecutionContext} ctx
 * @param {Request} request
 * @param {string|null} handleOverride
 * @returns {Promise<Response>}
 */
async function serveIndividual(env, ctx, request, handleOverride) {
    // Fail-fast if the binding hasn't been deployed yet.
    if (!env.PARTNA_PAGES || typeof env.PARTNA_PAGES.fetch !== "function") {
        console.error("PARTNA_PAGES service binding missing");
        return finalize(
            new Response("Service Unavailable", {
                status: 503,
                headers: {"Content-Type": "text/plain"},
            }),
            {noStore: true},
        );
    }

    const originRequest = withHandleHeader(request, handleOverride);

    // Bypass the edge entirely for preview-shaped requests: ?preview= (the
    // dashboard live preview) or ?architecture= (transient alternate
    // architecture). No cache read, no cache write — cacheKeyFor() strips
    // the query string, so a cached preview would pin under the plain URL's key
    // for the full 24h TTL. Always fetch fresh. EDGE-7: still finalise so the
    // preview carries security headers.
    //
    // Known trade-off (accepted, see the 2026-07-25 cache-freshness design): any
    // of these params is a cache-busting lever for anonymous traffic. Not new —
    // ?architecture= already was — but "preview" is more
    // guessable. Cloudflare bot protection sits in front; origin rate-limiting
    // for bypass params is separate, out-of-scope work.
    const previewParams = new URL(request.url).searchParams;
    if (previewParams.has("preview") || previewParams.has("architecture")) {
        return finalize(await env.PARTNA_PAGES.fetch(originRequest), {
            sitepage: true,
            noStore: true,
        });
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
        // EDGE-13: same as the two cache.put chains in fetchAndCache — a rejected
        // waitUntil promise resolves after the response has already gone out, so
        // without this the failure surfaces as an unhandled rejection instead of a
        // log line anyone can act on.
        ctx.waitUntil(
            fetchAndCache(env, ctx, cacheKey, cache, originRequest).catch((err) =>
                console.error("swr background refresh failed", {err: String(err)}),
            ),
        );
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

/**
 * A validated `SUBDOMAIN_KV` payload.
 *
 * `alias-invalid` is distinct from `unknown` on purpose: an entry that declares
 * itself an alias but carries an untrusted target must fail CLOSED to a 404,
 * whereas an entry we simply don't recognise passes through to origin. Merging
 * them would turn SEC-5's fail-closed 404 into an origin hit.
 *
 * @typedef {{kind: "individual", handle: string | null}
 *         | {kind: "alias", redirect: URL}
 *         | {kind: "alias-invalid"}
 *         | {kind: "unknown"}} KvEntry
 */

/**
 * Validate an untrusted KV payload into a narrowed entry.
 *
 * SyncSubdomainToKvJob is the single writer, but the Worker cannot verify that —
 * a poisoned or stale value is externally-shaped input. This is the ONE place
 * that decides what a KV value means; callers consume the union rather than
 * reading raw properties.
 *
 * @param {unknown} raw
 * @returns {KvEntry}
 */
function parseKvEntry(raw) {
    if (typeof raw !== "object" || raw === null) {
        return {kind: "unknown"};
    }
    const entry = /** @type {Record<string, unknown>} */ (raw);

    if (entry.type === "individual") {
        // handle may legitimately be absent: on the <handle>.partna.au path
        // partna-pages derives it from Host. The custom-domain caller, which has
        // no such Host, requires non-null itself.
        return {
            kind: "individual",
            handle: typeof entry.handle === "string" ? entry.handle : null,
        };
    }

    // A non-string redirect is not an alias at all — pass through, matching the
    // pre-refactor fall-through.
    if (entry.type === "alias" && typeof entry.redirect === "string") {
        let candidate = null;
        try {
            candidate = new URL(entry.redirect);
        } catch (err) {
            // PRIV-1: don't put the raw subdomain in the structured field.
            console.error("alias redirect parse failed", {err: String(err)});
            return {kind: "alias-invalid"};
        }
        // SEC-5: only https on partna.au (apex or subdomain) is a trusted target.
        const okHost =
            candidate.protocol === "https:" &&
            (candidate.hostname === PARTNA_DOMAIN ||
                candidate.hostname.endsWith("." + PARTNA_DOMAIN));
        return okHost ? {kind: "alias", redirect: candidate} : {kind: "alias-invalid"};
    }

    return {kind: "unknown"};
}

export default {
    /**
     * @param {Request} request
     * @param {Env} env
     * @param {ExecutionContext} ctx
     * @returns {Promise<Response>}
     */
    async fetch(request, env, ctx) {
        const url = new URL(request.url);
        const hostname = url.hostname.toLowerCase();

        // Force HTTPS for every request the router sees. 301 (permanent upgrade);
        // HSTS (applied below) handles the repeat-visit case.
        if (url.protocol === "http:") {
            const httpsUrl = new URL(request.url);
            httpsUrl.protocol = "https:";
            return finalize(
                new Response(null, {status: 301, headers: {Location: httpsUrl.toString()}}),
            );
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
            const customEntry = parseKvEntry(custom);
            // Non-null handle required: there is no <handle>.partna.au Host here
            // for partna-pages to fall back on.
            if (customEntry.kind === "individual" && customEntry.handle !== null) {
                return serveIndividual(env, ctx, request, customEntry.handle);
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

        const parsed = parseKvEntry(entry);

        // Alias entries 301 old subdomains to the canonical URL (written by
        // SyncSubdomainToKvJob on rename). Preserve the deep link: `/gallery?x=1`
        // on the old handle → the same path on the canonical handle. The stored
        // value is a bare origin, so build from `.origin` only and ignore any path
        // it carries.
        if (parsed.kind === "alias") {
            const target = `${parsed.redirect.origin}${url.pathname}${url.search}`;
            return finalize(
                new Response(null, {
                    status: 301,
                    headers: {Location: target, "Cache-Control": "max-age=0, must-revalidate"},
                }),
            );
        }

        // SEC-5: an alias whose target failed validation fails CLOSED to 404 rather
        // than redirecting or hitting origin.
        if (parsed.kind === "alias-invalid") {
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
        if (parsed.kind === "individual") {
            return serveIndividual(env, ctx, request, null);
        }

        // Unknown type or unhandled entry — pass through to origin.
        return passThrough(request);
    },
};
