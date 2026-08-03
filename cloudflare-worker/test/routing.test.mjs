/**
 * Dispatch-branch coverage for src/index.js — the nine ordered branches in
 * `export default { fetch }` plus serveIndividual()'s forwarded-request hygiene.
 *
 * DELIBERATELY NOT COVERED (whole suite, all files):
 *  - The KV-throw branch (`X-Partna-Cache: kv-error`). Miniflare exposes no clean
 *    way to make a KV `get` reject, and faking it would mean stubbing the binding
 *    with a non-KV object, which stops testing the real code path. The branch is
 *    two lines and shares its response body with the genuine-miss branch, which
 *    IS covered (T4).
 *  - The 101/WebSocket raw-return inside passThrough(). Reaching it needs an
 *    origin that completes a real WebSocket upgrade through outboundService.
 *  - Real TTL expiry. workerd's clock is not controllable from Miniflare, so
 *    cache.test.mjs T9 simulates the production event (primary gone, shadow
 *    still present) by deleting the primary key — the faithful shape of both a
 *    purge and a TTL lapse.
 *
 * Every test uses its own subdomain (t1., t2., …). Miniflare has no
 * `caches.default.deleteAll()`, so disjoint keys are the isolation mechanism.
 */
import {afterAll, beforeAll, beforeEach, describe, expect, it} from "vitest";
import {APEX_ORIGIN_BODY, createHarness} from "./helpers.mjs";

let h;

beforeAll(async () => {
    h = await createHarness();
});

afterAll(async () => {
    await h?.dispose();
});

beforeEach(() => {
    h.resetCalls();
    h.setPagesHandler(null);
});

describe("individual sitepages", () => {
    it("T1: a {type:'individual'} entry renders through the PARTNA_PAGES binding", async () => {
        await h.seedKv("t1", {type: "individual"});
        h.setPagesHandler(
            () =>
                new Response("<html>T1-RENDER</html>", {
                    status: 200,
                    headers: {"Content-Type": "text/html; charset=utf-8"},
                }),
        );

        const res = await h.fetch("https://t1.partna.au/");

        expect(res.status).toBe(200);
        await expect(res.text()).resolves.toContain("T1-RENDER");
        expect(h.pagesCalls).toHaveLength(1);
        // partna-pages derives the handle from Host on a *.partna.au request, so
        // the router must NOT inject an override here.
        expect(h.pagesCalls[0].headers.map(([k]) => k)).not.toContain("x-partna-handle");
    });
});

describe("https upgrade", () => {
    // Branch 1, and the FIRST branch of the nine — so a regression here changes
    // every other branch's entry conditions. headers.test.mjs uses this path as one
    // of its five header fixtures, but nothing asserted the redirect's own mechanics.
    it("T14: an http request 301s to the same URL over https, path and query intact", async () => {
        await h.seedKv("t14", {type: "individual"});

        const res = await h.fetch("http://t14.partna.au/menu?section=drinks&x=2");

        expect(res.status).toBe(301);
        expect(res.headers.get("location")).toBe("https://t14.partna.au/menu?section=drinks&x=2");
        // Upgrades before any KV read or origin call — the handle is never resolved.
        expect(h.pagesCalls).toHaveLength(0);
        expect(h.outboundCalls).toHaveLength(0);
    });

    it("T14b: upgrades a reserved label too, rather than passing it through as http", async () => {
        const res = await h.fetch("http://api.partna.au/api/health");

        expect(res.status).toBe(301);
        expect(res.headers.get("location")).toBe("https://api.partna.au/api/health");
        expect(h.outboundCalls).toHaveLength(0);
    });
});

describe("unknown KV entry types", () => {
    // Branch 9 — the fall-through for an entry whose `type` is neither "alias" nor
    // "individual". SyncSubdomainToKvJob's own docblock records that the retired
    // `{target: <handle>}` shape lands here and self-loops into a visitor-facing 522,
    // so this branch is a real historical hazard, not a theoretical one.
    it("T15: an entry with an unrecognised type falls through to origin, not a 500", async () => {
        await h.seedKv("t15", {type: "affiliate", redirect: "https://brand.partna.au/jane"});

        const res = await h.fetch("https://t15.partna.au/");

        expect(res.status).toBe(200);
        expect(await res.text()).toBe(APEX_ORIGIN_BODY);
        // Specifically NOT a 301 — the obsolete "affiliate" shape must not redirect.
        expect(res.headers.get("location")).toBeNull();
        expect(h.pagesCalls).toHaveLength(0);
    });

    it("T15b: an entry missing `type` entirely also falls through rather than throwing", async () => {
        await h.seedKv("t15b", {handle: "jane"});

        const res = await h.fetch("https://t15b.partna.au/");

        expect(res.status).toBe(200);
        expect(await res.text()).toBe(APEX_ORIGIN_BODY);
    });
});

describe("alias redirects", () => {
    it("T2: 301s to the canonical host, preserving path and query", async () => {
        await h.seedKv("t2", {type: "alias", redirect: "https://new.partna.au"});

        const res = await h.fetch("https://t2.partna.au/gallery?x=1");

        expect(res.status).toBe(301);
        expect(res.headers.get("location")).toBe("https://new.partna.au/gallery?x=1");
        expect(res.headers.get("cache-control")).toBe("max-age=0, must-revalidate");
        expect(h.pagesCalls).toHaveLength(0);
    });

    // T3 is two tests, not one: okHost is a conjunction (https AND partna.au host)
    // and a single case can only ever exercise one conjunct.
    it("T3a: rejects an off-domain target — 404, never a 301 (open redirect)", async () => {
        await h.seedKv("t3a", {type: "alias", redirect: "https://evil.example/"});

        const res = await h.fetch("https://t3a.partna.au/");

        expect(res.status).toBe(404);
        expect(res.headers.get("location")).toBeNull();
        expect(res.headers.get("cache-control")).toBe("no-store");
    });

    it("T3b: rejects a non-https target on our own domain — 404, never a 301", async () => {
        await h.seedKv("t3b", {type: "alias", redirect: "http://jane.partna.au"});

        const res = await h.fetch("https://t3b.partna.au/");

        expect(res.status).toBe(404);
        expect(res.headers.get("location")).toBeNull();
    });

    it("T3c: an unparseable target fails closed to 404", async () => {
        await h.seedKv("t3c", {type: "alias", redirect: "not-a-url"});

        const res = await h.fetch("https://t3c.partna.au/");

        expect(res.status).toBe(404);
        expect(res.headers.get("location")).toBeNull();
    });
});

describe("unclaimed subdomains", () => {
    it("T4: a KV miss serves the branded 404 with no-store and no kv-error tag", async () => {
        const res = await h.fetch("https://t4-unclaimed.partna.au/");
        const body = await res.text();

        expect(res.status).toBe(404);
        expect(res.headers.get("content-type")).toContain("text/html");
        expect(res.headers.get("cache-control")).toBe("no-store");
        expect(body).toContain("t4-unclaimed");
        // A genuine miss carries NO X-Partna-Cache. `kv-error` here would mean a
        // KV outage is being misreported as routine enumeration traffic.
        expect(res.headers.get("x-partna-cache")).not.toBe("kv-error");
        expect(h.pagesCalls).toHaveLength(0);
    });
});

describe("reserved subdomains", () => {
    it("T5: a reserved label short-circuits to origin BEFORE any KV read", async () => {
        // Seed `api` as a live sitepage. The RESERVED check runs first, so this
        // entry must never be consulted — if it ever is, api.partna.au (the whole
        // backend API) starts rendering a sitepage instead of serving the API.
        await h.seedKv("api", {type: "individual"});

        const res = await h.fetch("https://api.partna.au/");

        // APEX_ORIGIN_BODY is produced ONLY by the outboundService stub. If the
        // stub were missing, passThrough()'s plain fetch() would reach the real
        // https://api.partna.au and this assertion would fail loudly.
        await expect(res.text()).resolves.toBe(APEX_ORIGIN_BODY);
        expect(res.status).toBe(200);
        expect(h.pagesCalls).toHaveLength(0);
        expect(h.outboundCalls).toHaveLength(1);
    });

    it("T5b: the apex and multi-level subdomains also pass through", async () => {
        const apex = await h.fetch("https://partna.au/");
        await expect(apex.text()).resolves.toBe(APEX_ORIGIN_BODY);

        const nested = await h.fetch("https://a.b.partna.au/");
        await expect(nested.text()).resolves.toBe(APEX_ORIGIN_BODY);

        expect(h.pagesCalls).toHaveLength(0);
    });
});

describe("forwarded-request hygiene", () => {
    it("T10: strips x-partna-handle, Cookie and Authorization before origin", async () => {
        await h.seedKv("t10", {type: "individual"});

        const res = await h.fetch("https://t10.partna.au/", {
            headers: {
                "x-partna-handle": "someone-else",
                Cookie: "session=secret",
                Authorization: "Bearer leaked-token",
            },
        });

        expect(res.status).toBe(200);
        expect(h.pagesCalls).toHaveLength(1);
        const forwarded = h.pagesCalls[0].headers.map(([k]) => k.toLowerCase());
        expect(forwarded).not.toContain("x-partna-handle");
        expect(forwarded).not.toContain("cookie");
        expect(forwarded).not.toContain("authorization");
    });
});

describe("custom domains", () => {
    it("T11: a domain:<host> entry renders with the resolved handle injected", async () => {
        await h.seedKv("domain:shop.t11.example", {type: "individual", handle: "jane"});

        const res = await h.fetch("https://shop.t11.example/");

        expect(res.status).toBe(200);
        expect(h.pagesCalls).toHaveLength(1);
        const injected = h.pagesCalls[0].headers.find(
            ([k]) => k.toLowerCase() === "x-partna-handle",
        );
        expect(injected?.[1]).toBe("jane");
    });

    it("T11b: an unknown non-partna.au host falls through to origin", async () => {
        const res = await h.fetch("https://unknown.t11.example/");

        await expect(res.text()).resolves.toBe(APEX_ORIGIN_BODY);
        expect(h.pagesCalls).toHaveLength(0);
    });

    // The subdomain path accepts {type:"individual"} with no handle (partna-pages
    // reads it from Host); the custom-domain path must NOT — there is no Host to
    // derive it from, so a handle-less entry is malformed and falls through.
    it("T11c: a domain:<host> entry with no handle falls through to origin", async () => {
        await h.seedKv("domain:shop.t11c.example", {type: "individual"});

        const res = await h.fetch("https://shop.t11c.example/");

        await expect(res.text()).resolves.toBe(APEX_ORIGIN_BODY);
        expect(h.pagesCalls).toHaveLength(0);
    });
});
