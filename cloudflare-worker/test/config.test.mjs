/**
 * T13 — wrangler.toml ↔ src/index.js binding contract.
 *
 * This closes the harness's one real gap: helpers.mjs *declares* the bindings it
 * gives the Worker, so every other test would keep passing if wrangler.toml lost
 * a binding, renamed one, or dropped a `[vars]` entry. Nothing in the JS suite
 * reads the real config — except this file.
 *
 * Deliberately regex-based rather than pulling in a TOML parser: adding a
 * dependency to this package means another entry in `npm audit`, and the shapes
 * asserted here are single-line key/value pairs.
 */
import {readFileSync} from "node:fs";
import {fileURLToPath} from "node:url";
import {describe, expect, it} from "vitest";

const toml = readFileSync(fileURLToPath(new URL("../wrangler.toml", import.meta.url)), "utf8");
const source = readFileSync(fileURLToPath(new URL("../src/index.js", import.meta.url)), "utf8");

describe("wrangler.toml", () => {
    it("points at the router entrypoint", () => {
        expect(toml).toMatch(/^main\s*=\s*"src\/index\.js"$/m);
    });

    it("declares the SUBDOMAIN_KV namespace with a real id", () => {
        expect(toml).toMatch(/\[\[kv_namespaces\]\]/);
        expect(toml).toMatch(/^binding\s*=\s*"SUBDOMAIN_KV"$/m);
        // A placeholder id would deploy a Worker whose KV reads all miss.
        expect(toml).toMatch(/^id\s*=\s*"[0-9a-f]{32}"$/m);
        expect(source).toContain("env.SUBDOMAIN_KV");
    });

    it("declares the PARTNA_PAGES service binding under that exact name", () => {
        // The name is load-bearing: serveIndividual() reads env.PARTNA_PAGES and
        // fails fast to 503 for EVERY sitepage request if it resolves to nothing.
        expect(toml).toMatch(/^binding\s*=\s*"PARTNA_PAGES"$/m);
        expect(toml).toMatch(/^service\s*=\s*"partna-pages"$/m);
        expect(source).toContain("env.PARTNA_PAGES");
    });

    it("declares both cache TTL vars the Worker reads", () => {
        expect(toml).toMatch(/^\[vars\]$/m);
        expect(toml).toMatch(/^PRIMARY_CACHE_TTL_S\s*=\s*[0-9_]+$/m);
        expect(toml).toMatch(/^STALE_SHADOW_TTL_S\s*=\s*[0-9_]+$/m);
        expect(source).toContain("env.PRIMARY_CACHE_TTL_S");
        expect(source).toContain("env.STALE_SHADOW_TTL_S");
    });

    it("keeps the zone-wide route required by Cloudflare for SaaS", () => {
        // `*.partna.au/*` would never see custom hostnames — Worker Routes
        // present that traffic as the CUSTOMER's hostname, not the fallback
        // origin. Narrowing this silently breaks every connected custom domain.
        expect(toml).toMatch(/^pattern\s*=\s*"\*\/\*"$/m);
        expect(toml).toMatch(/^zone_name\s*=\s*"partna\.au"$/m);
    });

    it("ships no [assets]/[site] block, so test/ can never reach the edge", () => {
        expect(toml).not.toMatch(/^\[assets\]$/m);
        expect(toml).not.toMatch(/^\[site\]$/m);
    });

    it("has no staging environment (EDGE-102)", () => {
        expect(toml).not.toMatch(/^\[env\.staging\]$/m);
    });
});
