/**
 * T8 — baseline security headers across every return path, and the framing split.
 *
 * finalize() is the single exit point in src/index.js and applySecurityHeaders()
 * runs unconditionally inside it. The value of a table across five *different*
 * branches is that a new return path added without going through finalize() shows
 * up here rather than in production.
 *
 * See routing.test.mjs's header for what the suite deliberately does not cover.
 */
import {afterAll, beforeAll, beforeEach, describe, expect, it} from "vitest";
import {createHarness} from "./helpers.mjs";

let h;

beforeAll(async () => {
    h = await createHarness();
    await h.seedKv("t8a", {type: "individual"});
    await h.seedKv("t8b", {type: "alias", redirect: "https://t8a.partna.au"});
});

afterAll(async () => {
    await h?.dispose();
});

beforeEach(() => {
    h.resetCalls();
    h.setPagesHandler(null);
});

/** [label, request, isSitepage] */
const paths = [
    ["individual sitepage", () => h.fetch("https://t8a.partna.au/"), true],
    ["alias 301", () => h.fetch("https://t8b.partna.au/"), false],
    ["unclaimed 404", () => h.fetch("https://t8c-nope.partna.au/"), false],
    ["reserved passThrough", () => h.fetch("https://www.partna.au/"), false],
    ["http→https 301", () => h.fetch("http://t8a.partna.au/"), false],
];

describe.each(paths)("%s", (label, send, isSitepage) => {
    it("carries the baseline security headers", async () => {
        const res = await send();

        expect(res.headers.get("strict-transport-security")).toBe(
            "max-age=31536000; includeSubDomains",
        );
        expect(res.headers.get("x-content-type-options")).toBe("nosniff");
        expect(res.headers.get("referrer-policy")).toBe("strict-origin-when-cross-origin");
    });

    it(isSitepage ? "swaps X-Frame-Options for enforcing frame-ancestors" : "keeps X-Frame-Options: SAMEORIGIN", async () => {
        const res = await send();

        if (isSitepage) {
            // X-Frame-Options cannot allow-list a cross-origin embedder, so
            // sitepages drop it in favour of an ENFORCING frame-ancestors — that
            // is what lets the /account/design preview iframe on app.partna.au
            // embed the page while every other origin stays refused.
            expect(res.headers.get("x-frame-options")).toBeNull();
            expect(res.headers.get("content-security-policy")).toBe(
                "frame-ancestors 'self' https://app.partna.au",
            );
            // The full policy ships Report-Only and is INERT — it blocks nothing.
            // Present so a real render can be validated before it is enforced.
            expect(res.headers.get("content-security-policy-report-only")).toContain("default-src 'self'");
        } else {
            expect(res.headers.get("x-frame-options")).toBe("SAMEORIGIN");
            expect(res.headers.get("content-security-policy")).toBeNull();
        }
    });
});

describe("origin-set headers win", () => {
    it("does not clobber a stricter header the origin already set", async () => {
        await h.seedKv("t8d", {type: "individual"});
        h.setPagesHandler(
            () =>
                new Response("<html>T8D</html>", {
                    status: 200,
                    headers: {
                        "Content-Type": "text/html; charset=utf-8",
                        "Referrer-Policy": "no-referrer",
                    },
                }),
        );

        const res = await h.fetch("https://t8d.partna.au/");

        // applySecurityHeaders() uses has() guards on purpose: the origin knows
        // more about its own response than the router does, and clobbering a
        // deliberately-stricter origin value would be a downgrade.
        expect(res.headers.get("referrer-policy")).toBe("no-referrer");
    });
});
