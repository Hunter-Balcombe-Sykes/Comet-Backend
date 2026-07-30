import {defineConfig} from "vitest/config";

// Plain node environment — the Worker itself runs inside workerd via the
// Miniflare programmatic API (see test/helpers.mjs), so vitest only needs to be
// a Node test runner. Deliberately NOT @cloudflare/vitest-pool-workers: it pins
// a narrow vitest range and can resolve a SECOND miniflare, which means a second
// ~100MB workerd download and a second `sharp` (the source of this package's
// standing npm-audit advisories).
//
// .mjs config + .mjs tests so package.json's absent `"type"` field stays absent —
// changing it would alter how wrangler resolves src/index.js at deploy time.
export default defineConfig({
    test: {
        environment: "node",
        include: ["test/**/*.test.mjs"],
        // Each file boots its own workerd. Serialise so we never hold five of
        // them at once, and so cache-API state can't interleave across files.
        fileParallelism: false,
        testTimeout: 30_000,
        hookTimeout: 30_000,
    },
});
