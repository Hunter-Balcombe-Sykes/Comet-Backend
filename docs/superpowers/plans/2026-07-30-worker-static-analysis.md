# Cloudflare Worker Static Analysis Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `cloudflare-worker/src/index.js` four layers of static analysis — generated types, type checking, type-aware linting, and formatting — plus project-specific invariant tests, gated in CI.

**Architecture:** No TypeScript port. `wrangler types` generates the `Env` and workerd runtime surface from `wrangler.toml`; `tsc --noEmit` with `allowJs`/`checkJs` type-checks the existing `.js` via JSDoc annotations. The one code change consolidates three ad-hoc KV validation guards into a single `parseKvEntry()` returning a discriminated union. A new `worker-static` CI job runs the four checks.

**Tech Stack:** Node 22, wrangler 4, TypeScript 5.9 (pinned), ESLint 10, typescript-eslint 8, Biome 2, Vitest 4 + Miniflare.

**Spec:** `docs/superpowers/specs/2026-07-30-worker-static-analysis-design.md`

## Global Constraints

- All work happens in `cloudflare-worker/` unless a step says otherwise. Run every command from `cloudflare-worker/`, not the repo root.
- **`typescript` MUST be pinned to `~5.9`.** `typescript-eslint@8` peer-declares `typescript: ">=4.8.4 <6.1.0"`; TypeScript 7.0.2 is the current release and installing it produces an `ERESOLVE` failure. Verified working: `typescript@5.9.3` + `eslint@10.8.0` + `typescript-eslint@8.65.0`.
- **Do NOT add `@cloudflare/workers-types`.** `wrangler types --include-runtime` generates runtime types from `compatibility_date = "2025-01-01"` in `wrangler.toml`.
- **Do NOT add `"type": "module"` to `cloudflare-worker/package.json`.** Its absence is deliberate (`vitest.config.mjs:9-11`) — adding it changes how wrangler resolves `src/index.js` at deploy time.
- **Do NOT add a direct `miniflare` devDependency.** It is imported from wrangler's hoisted copy on purpose (`test/helpers.mjs:9-15`) — a second copy pulls a second workerd and a second `sharp`, the source of this package's standing audit advisories.
- **Do NOT create Laravel migration files** (repo-wide rule, `CLAUDE.md`). Nothing in this plan needs the database.
- The types regeneration command is always exactly: `npx wrangler types --include-runtime --strict-vars false`
- **Never use `wrangler types --check` as a gate.** It compares only the config-hash comment on line 2 and passes even when the file has been truncated to two lines. The gate is regenerate + `git diff --exit-code`.
- `npm test` (the existing 20-case Miniflare suite) MUST pass at the end of every task.
- Tests in `test/*.mjs` use **4-space** indent; `src/index.js` currently uses 2-space. Task 2 resolves this.
- All new dependencies go in `devDependencies` so `npm audit --omit=dev` in the `supply-chain` CI job is unaffected.

---

## File Structure

| Path | Status | Responsibility |
|---|---|---|
| `cloudflare-worker/package.json` | modify | 4 new devDeps + 4 new scripts |
| `cloudflare-worker/tsconfig.json` | create | type-check config for `src/**` |
| `cloudflare-worker/eslint.config.mjs` | create | type-aware lint config |
| `cloudflare-worker/biome.json` | create | formatter config (linter disabled — ESLint owns linting) |
| `cloudflare-worker/worker-configuration.d.ts` | create (generated, committed) | `Env` + workerd runtime types |
| `cloudflare-worker/src/index.js` | modify | JSDoc annotations, `parseKvEntry()`, INV-1 `.catch()` |
| `cloudflare-worker/test/invariants.test.mjs` | create | INV-1..INV-4 AST assertions |
| `cloudflare-worker/test/routing.test.mjs` | modify | one new test (T11c) |
| `cloudflare-worker/test/helpers.mjs` | modify | Task 7 only — var binding type fidelity |
| `cloudflare-worker/README.md` | modify | regeneration + pin rationale |
| `.git-blame-ignore-revs` | create (repo root) | hide the Task 2 reformat from blame |
| `.github/workflows/ci.yml` | modify | new `worker-static` job |

---

## Task 0: Branch

- [ ] **Step 1: Confirm you are on `development` and it is clean**

Run from the repo root:
```bash
git status --short
git branch --show-current
```
Expected: `development`. If `git status` shows unrelated modified/untracked files (the repo had in-flight audit work on 2026-07-30), do NOT stash them — `git stash` in a shared checkout is a known hazard in this repo. Leave them alone; every path this plan touches is disjoint from them.

- [ ] **Step 2: Create the feature branch**

```bash
git checkout -b feat/worker-static-analysis-2026-07-30
```

---

## Task 1: Tooling scaffold

Installs the four tools and their configs. **No changes to `src/index.js`.** At the end of this task `tsc` reports exactly 21 errors — that is the expected, asserted state, not a failure.

**Files:**
- Modify: `cloudflare-worker/package.json`
- Create: `cloudflare-worker/tsconfig.json`
- Create: `cloudflare-worker/eslint.config.mjs`
- Create: `cloudflare-worker/biome.json`
- Create: `cloudflare-worker/worker-configuration.d.ts` (generated)

**Interfaces:**
- Consumes: nothing.
- Produces: npm scripts `typecheck`, `lint`, `format`, `types` used by every later task and by Task 6's CI job. The global `Env` interface (from `worker-configuration.d.ts`) consumed by Task 3's JSDoc.

- [ ] **Step 1: Install the four devDependencies**

```bash
cd cloudflare-worker
npm i -D typescript@~5.9 eslint@^10 typescript-eslint@^8 @biomejs/biome@^2
```

- [ ] **Step 2: Verify the versions resolved as expected**

```bash
npx tsc --version && npx eslint --version && npx biome --version
```
Expected: `Version 5.9.x`, `v10.x`, `Version: 2.x`. If `tsc` reports 6.x or 7.x the pin failed — fix `package.json` to `"typescript": "~5.9"` and re-run `npm i`.

- [ ] **Step 3: Generate the Workers types**

```bash
npx wrangler types --include-runtime --strict-vars false
```

- [ ] **Step 4: Verify the generated file is correct and portable**

```bash
sed -n '4,9p' worker-configuration.d.ts
grep -c "$HOME" worker-configuration.d.ts
```
Expected bindings block:
```ts
interface __BaseEnv_Env {
	SUBDOMAIN_KV: KVNamespace;
	PRIMARY_CACHE_TTL_S: number;
	STALE_SHADOW_TTL_S: number;
	PARTNA_PAGES: Fetcher /* partna-pages */;
}
```
Expected absolute-path count: `0`. If it is non-zero you ran `wrangler types` with an explicit output path — delete the file and re-run the bare command from `cloudflare-worker/`.

If `PRIMARY_CACHE_TTL_S` reads `86400` rather than `number`, you omitted `--strict-vars false`. Re-run with it.

- [ ] **Step 5: Create `cloudflare-worker/tsconfig.json`**

```json
{
    "compilerOptions": {
        "target": "es2022",
        "lib": ["es2022"],
        "module": "es2022",
        "moduleResolution": "bundler",
        "allowJs": true,
        "checkJs": true,
        "noEmit": true,
        "strict": true,
        "noUncheckedIndexedAccess": true,
        "skipLibCheck": true
    },
    "include": ["src/**/*", "worker-configuration.d.ts"]
}
```

`module: "es2022"` is explicit because `package.json` has no `"type"` field and must not gain one. `skipLibCheck` keeps the 14,683-line generated file from being re-verified on every run.

- [ ] **Step 6: Create `cloudflare-worker/eslint.config.mjs`**

```javascript
// Type-aware linting for the Worker. `projectService` makes typescript-eslint
// read tsconfig.json, which is what enables the rules that need type
// information (no-unsafe-*, no-misused-promises, await-thenable).
//
// worker-configuration.d.ts needs no ignore entry: wrangler emits
// `/* eslint-disable */` on its line 1.
import tseslint from "typescript-eslint";

export default tseslint.config(
    {ignores: ["node_modules/**", ".wrangler/**", "test/**", "*.config.mjs"]},
    ...tseslint.configs.recommendedTypeChecked,
    {
        languageOptions: {
            parserOptions: {projectService: true, tsconfigRootDir: import.meta.dirname},
        },
    },
);
```

- [ ] **Step 7: Create `cloudflare-worker/biome.json`**

```json
{
    "$schema": "./node_modules/@biomejs/biome/configuration_schema.json",
    "files": {
        "includes": ["src/**/*.js", "test/**/*.mjs", "*.mjs", "!worker-configuration.d.ts"]
    },
    "formatter": {
        "enabled": true,
        "indentStyle": "space",
        "indentWidth": 4,
        "lineWidth": 100
    },
    "javascript": {
        "formatter": {
            "quoteStyle": "double",
            "bracketSpacing": false,
            "trailingCommas": "all"
        }
    },
    "linter": {"enabled": false}
}
```

`linter.enabled: false` is deliberate — ESLint owns linting. Running both invites two tools disagreeing about the same line. `bracketSpacing: false` matches the existing house style (`{method: "GET"}`, not `{ method: "GET" }`). The `!worker-configuration.d.ts` negation is verified to work on Biome 2.5.

- [ ] **Step 8: Add npm scripts to `cloudflare-worker/package.json`**

Add to the existing `"scripts"` block, leaving `dev`/`deploy`/`tail`/`test`/`test:watch` untouched:

```json
"types": "wrangler types --include-runtime --strict-vars false",
"typecheck": "tsc --noEmit",
"lint": "eslint src",
"format": "biome format --write .",
"format:check": "biome ci ."
```

- [ ] **Step 9: Verify the toolchain runs and reports the expected baseline**

```bash
npx tsc --noEmit 2>&1 | grep -c "error TS"
npx tsc --noEmit 2>&1 | grep -v "TS7006" | grep -c "error TS"
```
Expected: `21`, then `0`. Every error must be `TS7006` (implicit `any` parameter). **If the second number is not 0, stop** — a non-`TS7006` error means the generated types or tsconfig differ from what this plan validated. Report it rather than working around it.

- [ ] **Step 10: Verify the existing suite still passes**

```bash
npm test
```
Expected: all tests pass. This task changed no runtime code, so a failure means a config file is being picked up by vitest — check `biome.json`/`tsconfig.json` are not matched by `vitest.config.mjs`'s `include: ["test/**/*.test.mjs"]`.

- [ ] **Step 11: Commit**

```bash
git add cloudflare-worker/package.json cloudflare-worker/package-lock.json \
        cloudflare-worker/tsconfig.json cloudflare-worker/eslint.config.mjs \
        cloudflare-worker/biome.json cloudflare-worker/worker-configuration.d.ts
git commit -m "build(worker): add typescript, eslint, biome and generated Workers types

Four dev-only tools for cloudflare-worker/, which had no static analysis at
all. typescript is pinned to ~5.9 because typescript-eslint@8 peer-caps at
<6.1.0 and TS 7 is current.

Types come from \`wrangler types --include-runtime --strict-vars false\` rather
than @cloudflare/workers-types, so they track the compatibility_date actually
deployed. --strict-vars false yields \`number\` instead of the literal \`86400\`,
which keeps the CFG-1 fallback in fetchAndCache() from typing as dead code.

No source changes: tsc reports 21 errors, all TS7006 (missing @param). Cleared
by the next two commits."
```

---

## Task 2: Format-only commit

**Files:**
- Modify: `cloudflare-worker/src/index.js` (~983 lines reformatted)
- Modify: `cloudflare-worker/test/*.mjs` (~4 lines)
- Modify: `cloudflare-worker/biome.json`, `cloudflare-worker/vitest.config.mjs`, `cloudflare-worker/eslint.config.mjs` — Biome also reaches these. Verified on Biome 2.5: it processes its own config plus everything matching `*.mjs`, but **not** `tsconfig.json`.
- Create: `.git-blame-ignore-revs` (repo root)

**Interfaces:**
- Consumes: `biome.json` and the `format` script from Task 1.
- Produces: a 4-space `src/index.js`. Every later task writes 4-space code.

**Why this is commit 2 and not commit 5:** Biome is configured in Task 1, so if the reformat trailed the semantic work, `npm run format:check` would fail throughout Tasks 3–5 — the gate would be unusable during exactly the work it guards, and every line written in those tasks would be immediately rewritten here.

- [ ] **Step 1: Confirm the working tree is clean before reformatting**

```bash
cd cloudflare-worker && git status --short .
```
Expected: no output. A format-only commit must contain nothing else; if anything is pending, commit or set it aside first.

- [ ] **Step 2: Run the formatter**

```bash
npm run format
```

- [ ] **Step 3: Verify the diff is formatting-only**

```bash
git diff --stat
git diff -w --stat -- src/index.js
```
Expected: the first shows ~983 changed lines in `src/index.js`. The second — the same diff ignoring **all** whitespace — should show `src/index.js` either absent or with a very small change count from re-wrapped long lines.

**If `git diff -w` shows substantive non-whitespace changes to `src/index.js`, stop.** A formatter must not alter tokens. Report what changed.

- [ ] **Step 4: Verify behaviour is unchanged**

```bash
npm test
```
Expected: all tests pass, unchanged count.

- [ ] **Step 5: Verify the formatter is now satisfied**

```bash
npm run format:check
```
Expected: exit 0, no diagnostics.

- [ ] **Step 6: Commit the reformat alone**

Stage the whole directory rather than a file list. Step 1 verified the tree was clean, so everything
staged here is formatter output — and Biome's exact file set (`biome.json`, `src/`, `test/`, and
every root `*.mjs`) is easy to under-enumerate by hand.

```bash
git add cloudflare-worker/
git commit -m "style(worker): reformat to 4-space via biome

Format-only. No token changes — verified with \`git diff -w\`.

src/index.js was 2-space while the test files and the CLAUDE.md house
convention are 4-space; any formatter had to rewrite one side. Chose the
stated convention, so the 983-line churn lands here in src/ rather than
across the test suite.

Isolated deliberately: 983 lines of reformatting is where a one-character
change to the edge router would hide. Added to .git-blame-ignore-revs."
```

- [ ] **Step 7: Record the SHA in `.git-blame-ignore-revs`**

From the repo root, create the file (it does not exist yet):

```bash
cd "$(git rev-parse --show-toplevel)"
{
  echo "# Revisions to skip in \`git blame\` — formatting-only, no token changes."
  echo "# GitHub honours this file automatically. Locally:"
  echo "#   git config blame.ignoreRevsFile .git-blame-ignore-revs"
  echo ""
  echo "# style(worker): reformat to 4-space via biome"
  git rev-parse HEAD
} > .git-blame-ignore-revs
```

- [ ] **Step 8: Verify blame skips the reformat**

```bash
git config blame.ignoreRevsFile .git-blame-ignore-revs
git blame -L 46,46 -- cloudflare-worker/src/index.js
```
Expected: the `const PARTNA_DOMAIN = "partna.au";` line is attributed to its original authoring commit, not to the reformat.

- [ ] **Step 9: Commit the ignore file**

```bash
git add .git-blame-ignore-revs
git commit -m "chore: add .git-blame-ignore-revs for the worker reformat"
```

---

## Task 3: JSDoc parameter annotations

Clears the 21 `TS7006` errors. No behaviour change, no logic touched.

**Files:**
- Modify: `cloudflare-worker/src/index.js`

**Interfaces:**
- Consumes: the global `Env` interface from `worker-configuration.d.ts` (Task 1). It is a global `declare`, so JSDoc references it as bare `Env` — no import needed.
- Produces: annotated function signatures. Task 4 adds `@returns {KvEntry}` alongside these.

- [ ] **Step 1: Confirm the exact error list you are clearing**

```bash
cd cloudflare-worker && npx tsc --noEmit 2>&1 | grep "TS7006"
```
Expected: 21 lines across 10 functions — `cacheKeyFor`, `staleShadowKey`, `unclaimedHtml`, `withCacheTtl`, `applySecurityHeaders`, `passThrough`, `fetchAndCache`, `withHandleHeader`, `serveIndividual`, and the default export's `fetch`.

- [ ] **Step 2: Add `@param` tags to each function**

Add to the **existing** docblock where one is present; add a new one where it is not. Do not delete or reword any existing comment prose — those comments carry audit references (EDGE-*, PRIV-*, SEC-5) that other files cite.

`cacheKeyFor` — append to the existing docblock, before the closing `*/`:
```javascript
 * @param {Request} request
 * @returns {Request}
```

`staleShadowKey` — append to the existing docblock:
```javascript
 * @param {Request} cacheKey
 * @returns {Request}
```

`unclaimedHtml` — it has line comments, not a docblock. Add one directly above `function unclaimedHtml(subdomain) {`:
```javascript
/**
 * @param {string|null} subdomain
 * @returns {string}
 */
```

`withCacheTtl` — the docblock above it ends at line ~185 and is separated from the function by the unclaimedHtml block. Add a fresh docblock directly above `async function withCacheTtl(...)`:
```javascript
/**
 * @param {Response} response
 * @param {number} ttlSeconds
 * @returns {Promise<Response>}
 */
```

`applySecurityHeaders` — append to the existing docblock:
```javascript
 * @param {Headers} headers
 * @returns {void}
```

`finalize` — already has `@param` tags. Leave it alone.

`passThrough` — append to the existing docblock:
```javascript
 * @param {Request} request
 * @returns {Promise<Response>}
```

`fetchAndCache` — no docblock. Add one directly above:
```javascript
/**
 * @param {Env} env
 * @param {ExecutionContext} ctx
 * @param {Request} cacheKey
 * @param {Cache} cache
 * @param {Request} originRequest
 * @returns {Promise<Response>}
 */
```

`withHandleHeader` — append to the existing docblock:
```javascript
 * @param {Request} request
 * @param {string|null} handle
 * @returns {Request}
```

`serveIndividual` — append to the existing docblock:
```javascript
 * @param {Env} env
 * @param {ExecutionContext} ctx
 * @param {Request} request
 * @param {string|null} handleOverride
 * @returns {Promise<Response>}
```

The default export's `fetch` — add a docblock directly above `async fetch(request, env, ctx) {`, indented to match:
```javascript
    /**
     * @param {Request} request
     * @param {Env} env
     * @param {ExecutionContext} ctx
     * @returns {Promise<Response>}
     */
```

- [ ] **Step 3: Verify the error count dropped to exactly 7, all TS2339**

```bash
npx tsc --noEmit 2>&1 | grep -c "error TS"
npx tsc --noEmit 2>&1 | grep -v "TS2339" | grep -c "error TS"
```
Expected: `7`, then `0`. The seven are the KV trust boundary, cleared in Task 4:
```
src/index.js(NNN,28): error TS2339: Property 'type' does not exist on type '{}'.
src/index.js(NNN,67): error TS2339: Property 'handle' does not exist on type '{}'.
src/index.js(NNN,58): error TS2339: Property 'handle' does not exist on type '{}'.
src/index.js(NNN,15): error TS2339: Property 'type' does not exist on type '{}'.
src/index.js(NNN,48): error TS2339: Property 'redirect' does not exist on type '{}'.
src/index.js(NNN,41): error TS2339: Property 'redirect' does not exist on type '{}'.
src/index.js(NNN,15): error TS2339: Property 'type' does not exist on type '{}'.
```
Line numbers shift with the annotations — match on the message, not the line.

**If any `TS7006` remains, a function was missed.** If a NEW error kind appears, an annotation is wrong — for example typing `handleOverride` as `string` rather than `string|null` will produce a `TS2345` at the `serveIndividual(env, ctx, request, null)` call site.

- [ ] **Step 4: Verify format and tests**

```bash
npm run format:check && npm test
```
Expected: both pass. If `format:check` fails, run `npm run format` and re-check — docblock indentation is easy to get slightly wrong by hand.

- [ ] **Step 5: Commit**

```bash
git add cloudflare-worker/src/index.js
git commit -m "docs(worker): annotate function parameters for checkJs

JSDoc @param/@returns on the ten unannotated functions. Clears all 21 TS7006
errors; no logic touched.

Remaining 7 errors are all TS2339 on the KV payload — the one undeclared trust
boundary in the file. Addressed next."
```

---

## Task 4: `parseKvEntry()` — consolidate the KV trust boundary

The only behaviour-relevant change in this plan. Replaces three scattered ad-hoc guards with one validator returning a discriminated union.

**Files:**
- Modify: `cloudflare-worker/src/index.js`
- Modify: `cloudflare-worker/test/routing.test.mjs` (add T11c)

**Interfaces:**
- Consumes: annotations from Task 3.
- Produces: `parseKvEntry(raw) -> KvEntry` where
  ```
  KvEntry = {kind: "individual", handle: string|null}
          | {kind: "alias", redirect: URL}
          | {kind: "alias-invalid"}
          | {kind: "unknown"}
  ```

**The four-member union is not optional — a three-member one is a live bug.** The current code treats two "bad alias" cases *differently*:

| KV value | Current behaviour | Required `kind` |
|---|---|---|
| `{type:"alias", redirect:"https://jane.partna.au"}` | 301 | `alias` |
| `{type:"alias", redirect:"https://evil.example/"}` | **404 no-store** (fail closed) | `alias-invalid` |
| `{type:"alias", redirect:"not-a-url"}` | **404 no-store** (fail closed) | `alias-invalid` |
| `{type:"alias", redirect: 42}` — not a string | **passThrough to origin** | `unknown` |
| `{type:"individual"}` | serve, handle from Host | `individual`, handle `null` |
| `{type:"whatever"}` / `"a string"` / `[]` | passThrough | `unknown` |

Collapsing rows 2–3 into `unknown` turns a fail-closed 404 into an origin pass-through — tests `T3a`/`T3b`/`T3c` will catch it, which is the point.

**And the two `individual` call sites are NOT symmetric.** A `{type:"individual"}` entry with no `handle` is **valid** under a subdomain key (partna-pages derives the handle from `Host`) and **invalid** under a `domain:<host>` key (must pass through). So `parseKvEntry` returns `handle: string|null` and the custom-domain site keeps the non-null requirement itself. Rejecting handle-less individual entries inside the parser breaks routing for every site on the platform.

- [ ] **Step 1: Write the failing test (T11c)**

Add to `test/routing.test.mjs`, inside the existing `describe("custom domains", ...)` block, after `T11b`:

```javascript
    // The subdomain path accepts {type:"individual"} with no handle (partna-pages
    // reads it from Host); the custom-domain path must NOT — there is no Host to
    // derive it from, so a handle-less entry is malformed and falls through.
    it("T11c: a domain:<host> entry with no handle falls through to origin", async () => {
        await h.seedKv("domain:shop.t11c.example", {type: "individual"});

        const res = await h.fetch("https://shop.t11c.example/");

        await expect(res.text()).resolves.toBe(APEX_ORIGIN_BODY);
        expect(h.pagesCalls).toHaveLength(0);
    });
```

- [ ] **Step 2: Run it and confirm it already passes**

```bash
cd cloudflare-worker && npx vitest run test/routing.test.mjs -t "T11c"
```
Expected: **PASS.** This is a characterisation test, not a red-green test — it pins behaviour that is already correct so the refactor cannot silently change it. `T11b` covers an *unknown* host; nothing covered a *known host with a malformed entry* until now.

If it FAILS, the current behaviour is not what this plan assumes. Stop and report.

- [ ] **Step 3: Add the typedef and `parseKvEntry()`**

Insert directly above `export default {` in `src/index.js`:

```javascript
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
```

- [ ] **Step 4: Rewrite the custom-domain call site**

Replace:
```javascript
      if (custom && custom.type === "individual" && typeof custom.handle === "string") {
        return serveIndividual(env, ctx, request, custom.handle);
      }
      return passThrough(request);
```
with:
```javascript
      const customEntry = parseKvEntry(custom);
      // Non-null handle required: there is no <handle>.partna.au Host here for
      // partna-pages to fall back on.
      if (customEntry.kind === "individual" && customEntry.handle !== null) {
        return serveIndividual(env, ctx, request, customEntry.handle);
      }
      return passThrough(request);
```

- [ ] **Step 5: Rewrite the subdomain alias + individual call sites**

Replace the whole block from `if (entry.type === "alias" && typeof entry.redirect === "string") {` through the final `return passThrough(request);` with:

```javascript
    const parsed = parseKvEntry(entry);

    // Alias entries 301 old subdomains to the canonical URL (written by
    // SyncSubdomainToKvJob on rename). Preserve the deep link: `/gallery?x=1` on
    // the old handle → the same path on the canonical handle. The stored value is
    // a bare origin, so build from `.origin` only and ignore any path it carries.
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
```

**Leave the `if (!entry)` branded-404 guard above this untouched.** `parseKvEntry(null)` returns `unknown` → passThrough, which is the wrong answer for a KV miss; the existing guard must keep catching it first.

- [ ] **Step 6: Verify typecheck is clean**

```bash
npx tsc --noEmit
```
Expected: **no output, exit 0.** All 7 `TS2339` errors gone.

- [ ] **Step 7: Verify lint is clean**

```bash
npm run lint
```
Expected: **no output, exit 0.** The 2 `no-unsafe-argument` errors were downstream of the same untyped payload.

- [ ] **Step 8: Verify the full suite, with the six characterisation tests unmodified**

```bash
npm test
git diff --stat -- test/routing.test.mjs
```
Expected: all tests pass. The `routing.test.mjs` diff must show **only the T11c addition** — if `T3a`, `T3b`, `T3c`, `T11`, `T15`, or `T15b` needed editing, the refactor changed behaviour and is wrong. Revert and re-read Step 3's table.

- [ ] **Step 9: Format and commit**

```bash
npm run format && npm run format:check && npm test
git add cloudflare-worker/src/index.js cloudflare-worker/test/routing.test.mjs
git commit -m "refactor(worker): consolidate KV validation into parseKvEntry()

The Worker read .type/.handle/.redirect off an untyped KV payload at five
sites, guarded by three ad-hoc checks. KV is written only by
SyncSubdomainToKvJob, but the Worker can't verify that — a poisoned or stale
entry is externally-shaped input.

One validator now returns a discriminated union. 'alias-invalid' is separate
from 'unknown' deliberately: a declared alias with an untrusted target must
fail closed to 404 (SEC-5), while an unrecognised entry passes through.
Merging them would turn the 404 into an origin hit.

No behaviour change — T3a/T3b/T3c/T11/T15/T15b pass unmodified. Adds T11c:
a domain:<host> entry with no handle must fall through, since there is no
Host for partna-pages to derive one from. The two individual call sites are
asymmetric and the parser preserves that.

Clears the last 7 tsc errors and both eslint errors."
```

---

## Task 5: Invariant tests

Four project-specific rules that generic tooling cannot express. Three are ratchets; INV-1 finds a live inconsistency.

**Files:**
- Create: `cloudflare-worker/test/invariants.test.mjs`
- Modify: `cloudflare-worker/src/index.js` (one `.catch()`)

**Interfaces:**
- Consumes: `typescript` (Task 1) for `ts.createSourceFile`. No new dependency — the TypeScript compiler API parses `.js` directly.
- Produces: nothing consumed later.

- [ ] **Step 1: Write the invariant test file**

Create `cloudflare-worker/test/invariants.test.mjs`:

```javascript
/**
 * Structural invariants for src/index.js.
 *
 * The Miniflare suite pins the response paths that exist TODAY. These pin the
 * SHAPE of paths added tomorrow — the JS analogue of the PHP side's
 * OutboundHttpGuardTest.
 *
 * Parses with the TypeScript compiler API rather than a regex: `new Response`
 * inside a comment or a template literal must not count, and only a real parse
 * knows the difference. typescript is already a devDependency (tsconfig.json),
 * so this adds nothing to the tree.
 */
import {readFileSync} from "node:fs";
import {fileURLToPath} from "node:url";
import ts from "typescript";
import {describe, expect, it} from "vitest";

const srcPath = fileURLToPath(new URL("../src/index.js", import.meta.url));
const source = readFileSync(srcPath, "utf8");
// setParentNodes: true — enclosingFunctionName() and isInsideCallTo() walk up.
const sourceFile = ts.createSourceFile(srcPath, source, ts.ScriptTarget.ES2022, true, ts.ScriptKind.JS);

function walk(node, visit) {
    visit(node);
    ts.forEachChild(node, (child) => walk(child, visit));
}

/** 1-indexed line number, for failure messages a human can act on. */
function lineOf(node) {
    return sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile)).line + 1;
}

/** Name of the nearest enclosing function or method declaration. */
function enclosingFunctionName(node) {
    for (let n = node.parent; n; n = n.parent) {
        if (ts.isFunctionDeclaration(n) && n.name) return n.name.text;
        if (ts.isMethodDeclaration(n) && ts.isIdentifier(n.name)) return n.name.text;
    }
    return "<module>";
}

/** True when `node` sits anywhere inside a call to the named free function. */
function isInsideCallTo(node, fnName) {
    for (let n = node.parent; n; n = n.parent) {
        if (ts.isCallExpression(n) && ts.isIdentifier(n.expression) && n.expression.text === fnName) {
            return true;
        }
    }
    return false;
}

describe("INV-1: every ctx.waitUntil() argument ends in .catch()", () => {
    // A rejected promise handed to waitUntil after the response has returned
    // becomes an unhandled rejection instead of a structured log line (EDGE-13).
    // @typescript-eslint/no-floating-promises does NOT catch this: the promise
    // is an argument, not an expression statement.
    it("holds", () => {
        const offenders = [];
        walk(sourceFile, (node) => {
            if (!ts.isCallExpression(node)) return;
            const callee = node.expression;
            if (!ts.isPropertyAccessExpression(callee) || callee.name.text !== "waitUntil") return;

            // Fails closed: the ONLY accepted shape is a call chain whose
            // outermost member call is `.catch`. A bare identifier, a
            // conditional, or anything else this cannot follow is an offender —
            // an indirection the checker can't see is exactly where a missing
            // .catch() would hide.
            const arg = node.arguments[0];
            const ok =
                arg !== undefined &&
                ts.isCallExpression(arg) &&
                ts.isPropertyAccessExpression(arg.expression) &&
                arg.expression.name.text === "catch";

            if (!ok) offenders.push(`line ${lineOf(node)}`);
        });
        expect(offenders).toEqual([]);
    });
});

describe("INV-2: no visitor-facing `new Response` outside finalize()", () => {
    // Every return path must carry the baseline security headers. finalize() is
    // where they are applied; a `new Response` that never reaches it ships bare.
    //
    // Two exemptions, both constructing a Response for a non-visitor purpose:
    //   finalize()     — the wrapper itself
    //   withCacheTtl() — builds the cache copy, never returned to a visitor
    //
    // passThrough()'s raw 101 return is NOT an exemption: it returns an existing
    // Response rather than constructing one, so this rule never sees it. Do not
    // "fix" it by wrapping — that drops response.webSocket and breaks the
    // connection.
    const EXEMPT = new Set(["finalize", "withCacheTtl"]);

    it("holds", () => {
        const offenders = [];
        walk(sourceFile, (node) => {
            if (!ts.isNewExpression(node)) return;
            if (!ts.isIdentifier(node.expression) || node.expression.text !== "Response") return;
            if (isInsideCallTo(node, "finalize")) return;

            const fn = enclosingFunctionName(node);
            if (EXEMPT.has(fn)) return;

            offenders.push(`line ${lineOf(node)} in ${fn}()`);
        });
        expect(offenders).toEqual([]);
    });
});

describe("INV-3: the RESERVED set has no duplicate entries", () => {
    // A Set silently absorbs duplicates, so a copy-paste slip in a
    // hand-maintained 298-entry literal is invisible both on inspection and in
    // behaviour. Only a test can see it.
    it("holds", () => {
        let literals = null;
        walk(sourceFile, (node) => {
            if (!ts.isVariableDeclaration(node)) return;
            if (!ts.isIdentifier(node.name) || node.name.text !== "RESERVED") return;
            const init = node.initializer;
            if (!init || !ts.isNewExpression(init)) return;
            const arg = init.arguments?.[0];
            if (!arg || !ts.isArrayLiteralExpression(arg)) return;
            literals = arg.elements
                .filter((el) => ts.isStringLiteral(el))
                .map((el) => el.text);
        });

        // Guards against the test silently passing if RESERVED is restructured.
        expect(literals, "RESERVED array literal not found — has it been refactored?").not.toBeNull();
        expect(literals.length).toBeGreaterThan(200);

        const seen = new Set();
        const dupes = [];
        for (const value of literals) {
            if (seen.has(value)) dupes.push(value);
            seen.add(value);
        }
        expect(dupes).toEqual([]);
    });
});

describe("INV-4: no bare fetch() outside passThrough()", () => {
    // Every outbound hop should be either the PARTNA_PAGES service binding or
    // the single deliberate origin pass-through. A bare fetch() anywhere else is
    // an unreviewed egress path from the edge.
    it("holds", () => {
        const offenders = [];
        walk(sourceFile, (node) => {
            if (!ts.isCallExpression(node)) return;
            // Bare `fetch(...)` only — `env.PARTNA_PAGES.fetch(...)` is a
            // PropertyAccessExpression and is not matched here.
            if (!ts.isIdentifier(node.expression) || node.expression.text !== "fetch") return;

            const fn = enclosingFunctionName(node);
            if (fn === "passThrough") return;

            offenders.push(`line ${lineOf(node)} in ${fn}()`);
        });
        expect(offenders).toEqual([]);
    });
});
```

- [ ] **Step 2: Run it and confirm exactly one invariant fails**

```bash
cd cloudflare-worker && npx vitest run test/invariants.test.mjs
```
Expected: **INV-1 FAILS**, INV-2/3/4 pass. The INV-1 failure names the `ctx.waitUntil(fetchAndCache(...))` line in `serveIndividual` — the stale-shadow background refresh.

If INV-2, INV-3, or INV-4 also fails, the AST helpers are wrong (or Task 4 introduced something) — investigate before proceeding. Those three are ratchets and were verified green against this file.

- [ ] **Step 3: Fix INV-1**

In `serveIndividual`, find:
```javascript
      ctx.waitUntil(fetchAndCache(env, ctx, cacheKey, cache, originRequest));
```
Replace with:
```javascript
      // EDGE-13: same as the two cache.put chains in fetchAndCache — a rejected
      // waitUntil promise resolves after the response has already gone out, so
      // without this the failure surfaces as an unhandled rejection instead of a
      // log line anyone can act on.
      ctx.waitUntil(
        fetchAndCache(env, ctx, cacheKey, cache, originRequest).catch((err) =>
          console.error("swr background refresh failed", {err: String(err)}),
        ),
      );
```

- [ ] **Step 4: Verify all four invariants pass**

```bash
npx vitest run test/invariants.test.mjs
```
Expected: 4 passed.

- [ ] **Step 5: Verify nothing else regressed**

```bash
npx tsc --noEmit && npm run lint && npm run format && npm run format:check && npm test
```
Expected: all clean. `npm test` now reports the original cases plus T11c plus the 4 invariants.

- [ ] **Step 6: Commit**

```bash
git add cloudflare-worker/test/invariants.test.mjs cloudflare-worker/src/index.js
git commit -m "test(worker): pin four structural invariants; catch the SWR refresh

The Miniflare suite pins the response paths that exist today; these pin the
shape of paths added tomorrow. Parsed with the TypeScript compiler API so a
\`new Response\` in a comment or template literal doesn't count.

INV-1 found a live one: the stale-shadow background refresh handed a bare
promise to ctx.waitUntil while both cache.put chains in fetchAndCache attach
a .catch() (EDGE-13). A rejection there resolved after the response had gone
out, so it surfaced as an unhandled rejection rather than a log line. Now
matches its neighbours.

INV-2/3/4 are ratchets — green on today's code:
  INV-2  no visitor-facing \`new Response\` outside finalize()
  INV-3  RESERVED has no duplicates (a Set hides them; 298/298 unique)
  INV-4  no bare fetch() outside passThrough()

no-floating-promises does not catch INV-1: the promise is an argument, not an
expression statement. Verified before writing this."
```

---

## Task 6: CI job

**Files:**
- Modify: `.github/workflows/ci.yml`
- Modify: `cloudflare-worker/README.md`

**Interfaces:**
- Consumes: the npm scripts from Task 1.
- Produces: the `worker-static` required check.

- [ ] **Step 1: Add the job to `.github/workflows/ci.yml`**

Insert directly after the `worker-tests` job (which ends with its `npm test` step, around line 362) and before the `postgres-tests` comment block:

```yaml
  # Cloudflare Worker static analysis (types, typecheck, lint, format). The
  # Worker is the public wire for 100% of <handle>.partna.au traffic and had no
  # static analysis of any kind before 2026-07-30 — npm audit checks its
  # dependencies, DAST probes the deployed edge, neither reads the source.
  #
  # Its own job, NOT steps appended to worker-tests: a type error and a failing
  # behavioural test are different signals, and appending would let a test
  # failure mask the static result.
  #
  # No `paths:` filter, deliberately — same reasoning as worker-tests: GitHub
  # renders a skipped required check as green, so a filtered guard silently
  # stops guarding.
  worker-static:
    if: github.event_name != 'schedule'
    runs-on: ubuntu-latest
    permissions:
      contents: read
    steps:
      - uses: actions/checkout@v4

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          # 22 to match worker-tests: wrangler declares engines node >= 22.
          node-version: '22'
          cache: npm
          cache-dependency-path: cloudflare-worker/package-lock.json

      - name: Install dependencies
        working-directory: cloudflare-worker
        run: npm ci

      - name: Workers types are in sync with wrangler.toml
        working-directory: cloudflare-worker
        # Regenerate-and-diff, NOT `wrangler types --check`: that flag compares
        # only the config-hash comment on line 2 and passes even when the file
        # has been truncated to two lines (verified against wrangler 4.112.0).
        #
        # Deterministic because npm ci pins wrangler via the committed lockfile.
        # A wrangler bump changes the generated runtime types and turns this red
        # until regenerated and committed — expected, not a broken gate.
        run: |
          npm run types
          git diff --exit-code -- worker-configuration.d.ts

      - name: Typecheck (tsc --noEmit, checkJs + strict)
        working-directory: cloudflare-worker
        run: npm run typecheck

      - name: Lint (typescript-eslint, type-aware)
        working-directory: cloudflare-worker
        run: npm run lint

      - name: Format check (biome)
        working-directory: cloudflare-worker
        run: npm run format:check
```

The step order is fail-fast: types must be current before `tsc` means anything, and `tsc` must resolve before type-aware `eslint` can run.

- [ ] **Step 2: Validate the workflow YAML parses**

```bash
cd "$(git rev-parse --show-toplevel)"
python3 -c "import yaml,sys; d=yaml.safe_load(open('.github/workflows/ci.yml')); print('jobs:', list(d['jobs'].keys()))"
```
Expected: a job list including `worker-static`. A parse error means indentation drifted — job keys sit at 2 spaces, `steps:` at 4.

- [ ] **Step 3: Run the exact CI sequence locally**

```bash
cd cloudflare-worker
npm ci
npm run types && git diff --exit-code -- worker-configuration.d.ts
npm run typecheck
npm run lint
npm run format:check
npm test
```
Expected: every command exits 0. **Do not commit until this passes end-to-end** — the whole point of landing CI last is that the gate goes green on its first run.

- [ ] **Step 4: Document the maintenance contract in the Worker README**

Append a section to `cloudflare-worker/README.md`:

```markdown
## Static analysis

`worker-static` in CI runs four checks. All four have local equivalents:

| Check | Command |
|---|---|
| Types match `wrangler.toml` | `npm run types` then `git diff --exit-code -- worker-configuration.d.ts` |
| Typecheck (`checkJs` + `strict`) | `npm run typecheck` |
| Lint (type-aware) | `npm run lint` |
| Format | `npm run format` to fix, `npm run format:check` to verify |

**`worker-configuration.d.ts` is generated and committed.** Regenerate with
`npm run types` — never hand-edit. The flags matter: `--strict-vars false`
yields `PRIMARY_CACHE_TTL_S: number` instead of the literal `86400`, which keeps
the CFG-1 fallback in `fetchAndCache()` from typing as unreachable.

**Do not use `wrangler types --check` as a gate.** It compares only the
config-hash comment on line 2 and reports "up to date" even for a file truncated
to two lines. The CI gate regenerates and diffs instead.

**Bumping wrangler will turn `worker-static` red.** The generated file records
the workerd version it came from (line 3). Run `npm run types` and commit the
result as part of the bump. This is the gate working, not failing.

**`typescript` is pinned to `~5.9`.** `typescript-eslint@8` peer-caps at
`typescript <6.1.0` while TypeScript 7 is current; unpinning breaks `npm ci`
with `ERESOLVE`. Revisit when typescript-eslint ships TS 7 support.

`test/invariants.test.mjs` pins four structural rules (INV-1..INV-4) that types
and lint cannot express — see the comments in that file for what each protects.
```

- [ ] **Step 5: Commit**

```bash
cd "$(git rev-parse --show-toplevel)"
git add .github/workflows/ci.yml cloudflare-worker/README.md
git commit -m "ci(worker): add worker-static job (types, typecheck, lint, format)

Own job rather than steps on worker-tests — a type error and a failing
behavioural test are different signals, and appending lets one mask the other.
No paths: filter, same reasoning as worker-tests: a skipped required check
renders green.

The types step regenerates and diffs rather than using \`wrangler types
--check\`, which compares only the config-hash comment and passes on a file
truncated to two lines (verified, wrangler 4.112.0).

Lands last so the gate is green on its first run."
```

---

## Task 7: Harness var-type fidelity

Discovered while typing the Worker, not in the original spec. Small, isolated, and safe to defer — but it is a real divergence between the test harness and production.

**Files:**
- Modify: `cloudflare-worker/test/helpers.mjs`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing.

`wrangler.toml:22-23` declares the TTLs as TOML **integers** (`PRIMARY_CACHE_TTL_S = 86_400`), so workerd delivers them to the Worker as **numbers** — which is what `wrangler types` independently reports (`PRIMARY_CACHE_TTL_S: number`). But `test/helpers.mjs:60-63` binds them as **strings** with a comment asserting `// Strings — workerd env vars always are.` That is false for non-string TOML values, so the suite exercises a type production never sends.

Not a live bug — `Number()` accepts both — but the harness should mirror reality, and the comment actively misleads.

- [ ] **Step 1: Confirm the divergence**

```bash
cd cloudflare-worker
grep -n -A3 "^\[vars\]" wrangler.toml
grep -n -B2 -A4 "PRIMARY_CACHE_TTL_S" test/helpers.mjs
```
Expected: TOML shows unquoted integers; `helpers.mjs` shows quoted strings.

- [ ] **Step 2: Correct the bindings and the comment**

In `test/helpers.mjs`, replace:
```javascript
        // Mirrors wrangler.toml [vars]. Strings — workerd env vars always are.
        bindings: {
            PRIMARY_CACHE_TTL_S: "86400",
            STALE_SHADOW_TTL_S: "604800",
        },
```
with:
```javascript
        // Mirrors wrangler.toml [vars], which declares these as TOML integers —
        // so workerd delivers NUMBERS here, not strings. (`wrangler types`
        // independently reports `number`.) The Worker's Number() coercion
        // accepts either, but the harness should send what production sends.
        bindings: {
            PRIMARY_CACHE_TTL_S: 86_400,
            STALE_SHADOW_TTL_S: 604_800,
        },
```

- [ ] **Step 3: Verify the suite still passes**

```bash
npm test
```
Expected: all tests pass, unchanged count. The cache tests (`T6`, `T7`, `T9`, `T12`) exercise the TTL path; if any now fails, `Number()` was doing more work than assumed — report rather than reverting the comment.

- [ ] **Step 4: Verify format and commit**

```bash
npm run format:check
git add cloudflare-worker/test/helpers.mjs
git commit -m "test(worker): bind cache TTL vars as numbers, matching production

wrangler.toml declares PRIMARY_CACHE_TTL_S / STALE_SHADOW_TTL_S as TOML
integers, so workerd delivers numbers — \`wrangler types\` reports \`number\`
independently. The harness bound strings under a comment claiming workerd env
vars are always strings, which is only true for string-valued TOML.

No behaviour change (Number() takes both); the harness now sends what
production sends. Surfaced by the typing work in this branch."
```

---

## Final verification

- [ ] **Step 1: Full local gate**

```bash
cd cloudflare-worker
npm ci
npm run types && git diff --exit-code -- worker-configuration.d.ts
npm run typecheck
npm run lint
npm run format:check
npm test
```
All six exit 0.

- [ ] **Step 2: Confirm the six characterisation tests were never edited**

```bash
cd "$(git rev-parse --show-toplevel)"
git diff origin/development...HEAD -- cloudflare-worker/test/routing.test.mjs
```
Expected: additions only (T11c). No modification to `T3a`, `T3b`, `T3c`, `T11`, `T15`, `T15b`. If any of the six changed, Task 4 altered behaviour.

- [ ] **Step 3: Confirm the reformat is isolated**

```bash
git log --oneline origin/development...HEAD
git show --stat "$(tail -1 .git-blame-ignore-revs)"
```
Expected: the reformat commit touches only `src/index.js`, `test/`, `biome.json`, `vitest.config.mjs`, `eslint.config.mjs` — no `.github/`, no `README.md`, no `worker-configuration.d.ts`.

- [ ] **Step 4: Confirm no runtime dependency was added**

```bash
cd cloudflare-worker
python3 -c "import json; p=json.load(open('package.json')); print('dependencies:', p.get('dependencies', {}))"
npm audit --omit=dev --audit-level=high
```
Expected: `dependencies: {}` and the audit result unchanged from before this branch. All four tools are dev-only by design.

- [ ] **Step 5: Push and open the PR**

```bash
git push -u origin feat/worker-static-analysis-2026-07-30
```
Then open a PR into `development` (not `production`). Confirm `worker-static` appears and passes before merging.
