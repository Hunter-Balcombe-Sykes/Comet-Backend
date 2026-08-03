/**
 * Edge-cache + stale-while-revalidate coverage for serveIndividual().
 *
 * See routing.test.mjs's header for what the whole suite deliberately does not
 * cover. Relevant here: real TTL expiry is not testable (no clock control), so
 * T9 simulates it by deleting the primary key — which is exactly the state a
 * purge or a TTL lapse leaves behind.
 *
 * Miniflare 4's dispatchFetch returns a plain undici Response with no
 * waitUntil() (Miniflare 3 had one), so `h.waitForCache(url)` polls the Cache
 * API instead of awaiting a handle. fetchAndCache() writes inside
 * ctx.waitUntil, so asserting cache state without that wait is a race.
 */
import {afterAll, beforeAll, beforeEach, describe, expect, it} from "vitest";
import {cacheKeyUrl, createHarness, shadowKeyUrl} from "./helpers.mjs";

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

describe("cache keying", () => {
    it("T6: tracking params collapse to one entry, and origin still sees the full query", async () => {
        await h.seedKv("t6", {type: "individual"});

        const first = await h.fetch("https://t6.partna.au/?utm_source=a");
        expect(first.status).toBe(200);
        expect(first.headers.get("x-partna-cache")).toBe("origin");
        expect(
            await h.waitForCache(cacheKeyUrl("https://t6.partna.au/?utm_source=a")),
        ).not.toBeNull();

        const second = await h.fetch("https://t6.partna.au/?utm_source=b");

        // Two failure modes in one assertion block:
        //  1. If cacheKeyFor() stopped stripping the query, this is `origin` and
        //     every tracking param mints its own unbounded cache entry.
        //  2. If the cache.put calls were dropped, this is ALSO `origin` — bodies
        //     stay perfectly correct while 100% of traffic silently becomes an
        //     origin hit. Nothing else in the system detects that.
        expect(second.headers.get("x-partna-cache")).toBe("hit");
        expect(h.pagesCalls).toHaveLength(1);
        // The KEY is normalised; the forwarded request is not.
        expect(h.pagesCalls[0].url).toBe("https://t6.partna.au/?utm_source=a");
    });
});

describe("shared-cache hygiene", () => {
    it("T7: an origin Set-Cookie is never cached and never replayed", async () => {
        await h.seedKv("t7", {type: "individual"});
        h.setPagesHandler(
            () =>
                new Response("<html>T7</html>", {
                    status: 200,
                    headers: {
                        "Content-Type": "text/html; charset=utf-8",
                        "Set-Cookie": "sid=abc; Path=/",
                    },
                }),
        );

        const first = await h.fetch("https://t7.partna.au/");
        expect(first.headers.get("x-partna-cache")).toBe("origin");
        // The FIRST visitor legitimately receives their own cookie — it is the
        // stored copy and every later visitor that must not.
        expect(first.headers.get("set-cookie")).toContain("sid=abc");

        const stored = await h.waitForCache("https://t7.partna.au/");
        expect(stored).not.toBeNull();
        expect(stored.headers.get("set-cookie")).toBeNull();

        const second = await h.fetch("https://t7.partna.au/");
        expect(second.headers.get("x-partna-cache")).toBe("hit");
        // Highest-severity regression in this suite: one visitor's session cookie
        // replayed to every other visitor out of the shared edge cache.
        expect(second.headers.get("set-cookie")).toBeNull();
    });
});

describe("stale-while-revalidate", () => {
    it("T9: the /_swr-shadow copy survives primary loss and serves `stale`", async () => {
        await h.seedKv("t9", {type: "individual"});

        const cold = await h.fetch("https://t9.partna.au/gallery");
        expect(cold.headers.get("x-partna-cache")).toBe("origin");

        // The shadow path is hardcoded a SECOND time, independently, in
        // app/Services/Cloudflare/CloudflarePurgeService.php::purgeHandle().
        // Rename it here and every purge misses the shadow, so stale pages
        // survive a purge for up to 7 days — with no guard on either side but
        // this assertion.
        const shadowUrl = shadowKeyUrl("https://t9.partna.au/gallery");
        expect(shadowUrl).toBe("https://t9.partna.au/_swr-shadow/gallery");
        expect(await h.waitForCache(shadowUrl)).not.toBeNull();

        const cache = await h.edgeCache();
        expect(await h.waitForCache("https://t9.partna.au/gallery")).not.toBeNull();
        // Simulate the production event: primary purged/expired, shadow intact.
        expect(await cache.delete("https://t9.partna.au/gallery")).toBe(true);

        const stale = await h.fetch("https://t9.partna.au/gallery");
        expect(stale.status).toBe(200);
        expect(stale.headers.get("x-partna-cache")).toBe("stale");

        // The stale path refreshes the primary in the background.
        expect(await h.waitForCache("https://t9.partna.au/gallery")).not.toBeNull();
        const refreshed = await h.fetch("https://t9.partna.au/gallery");
        expect(refreshed.headers.get("x-partna-cache")).toBe("hit");
    });
});

describe("preview bypass", () => {
    it("T12: ?preview=1 skips the cache in both directions", async () => {
        await h.seedKv("t12", {type: "individual"});

        const preview = await h.fetch("https://t12.partna.au/?preview=1");

        expect(preview.status).toBe(200);
        expect(preview.headers.get("cache-control")).toBe("no-store");
        // No cache read happened, so no cache status is reported.
        expect(preview.headers.get("x-partna-cache")).toBeNull();
        expect(h.pagesCalls).toHaveLength(1);

        // And no write: cacheKeyFor() strips the query, so a cached preview would
        // pin itself under the PLAIN url's key for the full primary TTL.
        expect(await h.waitForCache("https://t12.partna.au/", {absent: true})).toBeNull();
    });

    it("T12b: ?architecture= and ?skeleton= bypass the same way", async () => {
        await h.seedKv("t12b", {type: "individual"});

        for (const param of ["architecture=other", "skeleton=legacy"]) {
            const res = await h.fetch(`https://t12b.partna.au/?${param}`);
            expect(res.headers.get("cache-control")).toBe("no-store");
            expect(res.headers.get("x-partna-cache")).toBeNull();
        }

        expect(await h.waitForCache("https://t12b.partna.au/", {absent: true})).toBeNull();
    });
});
