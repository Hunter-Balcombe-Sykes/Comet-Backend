/**
 * Miniflare harness for the Partna subdomain router.
 *
 * Runs the REAL src/index.js inside workerd. No network, no Cloudflare account,
 * and — critically — no writes to any real KV namespace: `kvNamespaces` here is
 * a local, in-memory Miniflare namespace. SyncSubdomainToKvJob remains the only
 * writer to the production SUBDOMAIN_KV.
 *
 * `miniflare` is imported from wrangler's own hoisted copy rather than being
 * declared as a direct devDependency. Deliberate: wrangler pins miniflare
 * exactly, so declaring it separately risks npm resolving a second version and
 * installing a nested workerd + sharp (sharp is the source of this package's
 * standing high-severity advisories). If the import ever fails, every test in
 * the suite errors immediately with a module-not-found — a loud failure, not a
 * silent skip.
 */
import {Miniflare} from "miniflare";
import {fileURLToPath} from "node:url";

const scriptPath = fileURLToPath(new URL("../src/index.js", import.meta.url));

/** Body returned by the outbound (origin) stub. Tests assert on this EXACT
 *  string. That is the point: passThrough() calls plain `fetch(request)`, so
 *  without `outboundService` wired below, the reserved-subdomain / apex / unknown-
 *  host tests would make real requests to https://api.partna.au from a developer
 *  machine and from CI. Asserting a literal only the stub can produce makes a
 *  missing stub fail loudly instead of passing against the live internet. */
export const APEX_ORIGIN_BODY = "APEX-ORIGIN";

/** Default response from the PARTNA_PAGES service binding. */
function defaultPagesResponse() {
    return new Response("<html>PAGES-OK</html>", {
        status: 200,
        headers: {"Content-Type": "text/html; charset=utf-8"},
    });
}

/**
 * Boot one Miniflare instance. Call once per test file in `beforeAll` and
 * `dispose()` in `afterAll` — Miniflare has no `caches.default.deleteAll()`, so
 * tests isolate themselves by using a UNIQUE subdomain each (t1., t2., …) rather
 * than by restarting workerd per test.
 */
export async function createHarness() {
    /** Recorded PARTNA_PAGES subrequests: {url, method, headers}. */
    const pagesCalls = [];
    /** Recorded outbound (passThrough) subrequests: {url}. */
    const outboundCalls = [];

    // Mutable so a single long-lived Miniflare can still give each test its own
    // origin behaviour (see setPagesHandler).
    const state = {pagesHandler: defaultPagesResponse};

    const mf = new Miniflare({
        modules: true,
        scriptPath,
        compatibilityDate: "2025-01-01",
        kvNamespaces: ["SUBDOMAIN_KV"],
        // Mirrors wrangler.toml [vars]. Strings — workerd env vars always are.
        bindings: {
            PRIMARY_CACHE_TTL_S: "86400",
            STALE_SHADOW_TTL_S: "604800",
        },
        // A FUNCTION service binding (not a second Worker): the closure records
        // what the router forwarded, which is the only reason the cache-key and
        // header-hygiene assertions are possible at all.
        serviceBindings: {
            PARTNA_PAGES: async (request) => {
                pagesCalls.push({
                    url: request.url,
                    method: request.method,
                    headers: [...request.headers],
                });
                return state.pagesHandler(request, pagesCalls.length);
            },
        },
        // NON-NEGOTIABLE — see APEX_ORIGIN_BODY above.
        outboundService: async (request) => {
            outboundCalls.push({url: request.url});
            return new Response(APEX_ORIGIN_BODY, {
                status: 200,
                headers: {"Content-Type": "text/plain"},
            });
        },
    });

    const kv = await mf.getKVNamespace("SUBDOMAIN_KV");

    return {
        mf,

        /** Seed one KV entry. Objects are JSON-encoded; strings are stored raw
         *  (so a test can seed deliberately malformed JSON). */
        async seedKv(key, value) {
            await kv.put(key, typeof value === "string" ? value : JSON.stringify(value));
        },

        /** Swap the PARTNA_PAGES origin behaviour for the next request(s).
         *  `handler(request, callNumber)` → Response. */
        setPagesHandler(handler) {
            state.pagesHandler = handler ?? defaultPagesResponse;
        },

        pagesCalls,
        outboundCalls,

        /** Clear both call logs — call in `beforeEach` so per-test counts are
         *  absolute, not cumulative. */
        resetCalls() {
            pagesCalls.length = 0;
            outboundCalls.length = 0;
        },

        /** The edge Cache API as workerd sees it, for direct inspection of what
         *  the router actually stored (primary and /_swr-shadow copies). */
        async edgeCache() {
            return (await mf.getCaches()).default;
        },

        /** Fetch through the Worker.
         *
         *  `redirect: "manual"` is forced by default and must stay that way.
         *  dispatchFetch's undici default is `follow`, and the router's own 301
         *  targets are absolute PUBLIC urls (`https://<handle>.partna.au/…`) —
         *  following one leaves Miniflare entirely and hits the real production
         *  Worker over the real internet. Observed during harness development:
         *  an http→https assertion silently returned production's 404 page.
         *  Second line of defence after outboundService.
         *
         *  Spread order is deliberate: `redirect` goes AFTER ...init so a caller
         *  cannot override it. It used to go before, which made the guarantee
         *  this docblock claims silently opt-out-able. A caller that asks for a
         *  different redirect mode is rejected rather than quietly downgraded —
         *  if a future test genuinely needs to follow one, it must add an
         *  outboundService-backed fixture, not reach the real internet. */
        fetch(url, init = {}) {
            if ("redirect" in init && init.redirect !== "manual") {
                throw new Error(
                    `h.fetch() refuses redirect: "${init.redirect}" — following a router 301 leaves ` +
                        "Miniflare and hits the real production Worker. Assert on the 301 itself instead.",
                );
            }

            return mf.dispatchFetch(url, {...init, redirect: "manual"});
        },

        /** Wait for the router's ctx.waitUntil cache writes to land.
         *
         *  Miniflare 4's dispatchFetch resolves to a plain undici Response with
         *  NO waitUntil() (unlike Miniflare 3), so there is no handle to await —
         *  poll the cache instead. `absent: true` waits for the key to STAY
         *  absent, i.e. burn the same budget then confirm nothing appeared. */
        async waitForCache(url, {absent = false, timeoutMs = 5_000} = {}) {
            const cache = (await mf.getCaches()).default;
            const deadline = Date.now() + timeoutMs;
            let hit = await cache.match(url);
            while (!hit && Date.now() < deadline) {
                await new Promise((r) => setTimeout(r, 25));
                hit = await cache.match(url);
            }
            if (absent) {
                // Give a stray write the full budget to show up before asserting.
                await new Promise((r) => setTimeout(r, 250));
                return (await cache.match(url)) ?? null;
            }
            return hit ?? null;
        },

        dispose() {
            return mf.dispose();
        },
    };
}

/** The router's cache key for a URL: query and fragment stripped (cacheKeyFor).
 *  Returned as a STRING — Miniflare's cache proxy serialises its argument with
 *  devalue and throws `Cannot stringify arbitrary non-POJOs` on a Request. */
export function cacheKeyUrl(url) {
    const u = new URL(url);
    u.search = "";
    u.hash = "";
    return u.toString();
}

/** The /_swr-shadow twin of a page URL. Mirrors staleShadowKey() in src/index.js
 *  — and the INDEPENDENT copy of this prefix in
 *  app/Services/Cloudflare/CloudflarePurgeService.php::purgeHandle(). */
export function shadowKeyUrl(url) {
    const u = new URL(cacheKeyUrl(url));
    u.pathname = `/_swr-shadow${u.pathname}`;
    return u.toString();
}
