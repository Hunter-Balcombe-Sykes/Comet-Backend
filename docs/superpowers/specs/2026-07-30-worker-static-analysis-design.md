# Cloudflare Worker Static Analysis

**Date:** 2026-07-30
**Status:** Design approved, implementation pending
**Origin:** Coverage gap raised 2026-07-30 — every static-analysis tool in this repo (PHPStan, Checkpoint, Vigil, `OutboundHttpGuardTest`) is PHP-only, and `cloudflare-worker/` has none.
**Scope:** `cloudflare-worker/src/**` only. The repo-root Vite surface (`resources/js/`, 2 files) and `cloudflare-worker/test/**` are explicitly **out of scope** — see §8.

---

## 1. Problem

`cloudflare-worker/src/index.js` is 593 lines of plain JavaScript with no `tsconfig`, no linter, and
no formatter. It routes 100% of `<handle>.partna.au` traffic, reads `SUBDOMAIN_KV`, and drives the
edge cache. It is the most externally-exposed code in the project.

The existing coverage is narrower than it looks:

| Guard | What it covers | What it misses |
|---|---|---|
| `worker-tests` CI job (20 cases, real workerd) | the response paths that exist **today** | the shape of paths added tomorrow |
| `npm audit --omit=dev` (`supply-chain` job) | the Worker's dependency tree | the Worker's own code — always |
| `dast-edge.yml` (ZAP/Nuclei) | the **deployed** Worker from outside | anything not reachable by an unauthenticated HTTP probe |

None of these read the source. `env.SUBDOMAN_KV` (typo) parses fine, evaluates to `undefined`, and
throws at the edge on live traffic. `caches.default`, `ctx.waitUntil`, and `response.webSocket` are
untyped guesses — nothing declares that the Workers runtime provides them.

### 1.1 Measured baseline

Spiked 2026-07-30 against a scratch copy of `src/index.js`. `tsc --noEmit` with
`strict` + `checkJs` + `noUncheckedIndexedAccess`:

| Stage | Errors | Kind |
|---|---|---|
| As-is | **21** | every one `TS7006` — implicit `any` parameter. Zero logic errors. |
| After ~11 JSDoc `@param` lines | **7** | every one `TS2339: Property 'x' does not exist on type '{}'` |
| `typescript-eslint` `recommendedTypeChecked`, annotated | **2** | `no-unsafe-argument` |

**The file is already type-correct.** Under `strict`, with no annotations added beyond parameter
types, TypeScript finds no logic defect. That is a materially better starting position than
"zero static analysis" implies, and it means the work below is about *preventing* drift, not
repairing damage.

### 1.2 All nine surviving findings share one root cause

`env.SUBDOMAIN_KV.get(key, {type: "json"})` returns an untyped blob. The Worker then reads
`.type`, `.handle`, and `.redirect` off it at five sites — `index.js:486`, `:487`, `:545`, `:548`,
`:586`.

KV is written by `SyncSubdomainToKvJob` (the single-writer rule), but the Worker cannot *know*
that. A poisoned or stale entry is externally-shaped input, and the file already treats it as such
with three ad-hoc guards: `typeof custom.handle === "string"` (`:486`), the `try { new URL(...) }`
around the redirect (`:547-559`), and the https + `partna.au` host check (`:549-551`).

Those three guards are correct today. Nothing makes the fourth one correct tomorrow. **Static
analysis's real payoff here is not finding a bug — it is that it mechanically locates the one
undeclared trust boundary in the file and refuses to let it stay implicit.**

### 1.3 What generic tooling structurally cannot catch

`index.js:439` calls `ctx.waitUntil(fetchAndCache(...))` with no `.catch()`. The two other
`waitUntil` calls in the file (`:350`, `:356`) both attach one, deliberately — the comment reads
"EDGE-13: surface cache.put failures instead of letting a rejected waitUntil promise vanish
silently." Line 439 is the SWR background-refresh path and is the lone exception; if
`env.PARTNA_PAGES.fetch` rejects there, the failure becomes an unhandled rejection instead of a
structured log line.

`@typescript-eslint/no-floating-promises` does **not** flag this — verified by running it. The
promise is passed as an *argument* to `waitUntil`, not left as an expression statement, so it is
not "floating" by that rule's definition. Only a project-specific invariant check catches it. This
is the same reasoning that produced `OutboundHttpGuardTest` on the PHP side.

---

## 2. Approach

Four layers, because the four gaps are genuinely different and no single tool spans them:

| Layer | Tool | Catches |
|---|---|---|
| Types | `wrangler types` + `tsc --noEmit` | binding typos, Workers-API misuse, the KV trust boundary |
| Lint | `typescript-eslint` (type-aware) | unsafe `any` flow, misused promises, unused code |
| Invariants | vitest + TypeScript compiler API | project rules generic tools cannot express (§6) |
| Format | Biome | cosmetic drift |

---

## 3. Dependencies

Four additions to `cloudflare-worker/package.json`, all `devDependencies`:

```
typescript@~5.9      # PINNED — see below
eslint@^10
typescript-eslint@^8
@biomejs/biome@^2
```

**The TypeScript pin is deliberate and load-bearing.** TypeScript 7.0.2 is the current release, but
`typescript-eslint@8.65.0` peer-declares `typescript: ">=4.8.4 <6.1.0"`. Installing latest produces
an `ERESOLVE` failure. Verified working combination: `typescript@5.9.3` + `eslint@10.8.0` +
`typescript-eslint@8.65.0`. Revisit the pin when typescript-eslint ships TS 7 support; until then a
`~5.9` pin costs nothing, because 5.9 type-checks this file identically.

**`@cloudflare/workers-types` is deliberately NOT a dependency.** `wrangler types --include-runtime`
generates runtime types from the `compatibility_date` in `wrangler.toml` (`2025-01-01`), so the
types track the runtime actually deployed rather than a separately-versioned package that can drift
from it.

All four are dev-only, so `npm audit --omit=dev --audit-level=high` in the `supply-chain` job is
unaffected by construction. That flag's existing rationale (`ci.yml:311-315`) — audit what actually
ships — extends to these unchanged.

---

## 4. Generated types and the drift gate

Command: `wrangler types --include-runtime --strict-vars false`

It emits `worker-configuration.d.ts` — **14,683 lines**, of which the first ~15 are the project's own
bindings and the rest are the workerd runtime surface:

```ts
interface __BaseEnv_Env {
	SUBDOMAIN_KV: KVNamespace;
	PRIMARY_CACHE_TTL_S: number;
	STALE_SHADOW_TTL_S: number;
	PARTNA_PAGES: Fetcher /* partna-pages */;
}
```

The file is **committed** — `cloudflare-worker/.gitignore` lists only `node_modules/`, `.wrangler/`,
`.dev.vars`, `*.log`, so no exclusion is needed. Verified portable: generated at the default path it
contains `mainModule: typeof import("./src/index")` and zero absolute paths, so it is identical on
every machine.

### 4.1 `--strict-vars false` is required

The default (`--strict-vars true`) emits **literal** types from `wrangler.toml` `[vars]`:
`PRIMARY_CACHE_TTL_S: 86400` rather than `number`. Two problems. Every TTL change rewrites the
generated type, and more importantly the literal makes the documented CFG-1 fallback
(`Number(env.PRIMARY_CACHE_TTL_S) || PRIMARY_CACHE_TTL_S_DEFAULT`, `index.js:346-347`) statically
unreachable — the type asserts the var is always exactly `86400`, which is precisely the assumption
that comment exists to *not* make. `--strict-vars false` yields `number` and keeps the defensive
branch honest.

### 4.2 The gate is regenerate-and-diff, NOT `--check`

**`wrangler types --check` compares only the config-hash comment on line 2 of the generated file. It
does not read the content.** Verified 2026-07-30 against wrangler 4.112.0: deleting
`SUBDOMAIN_KV: KVNamespace;` from the file, and separately truncating the file to two lines, both
still report "Types are up to date" with exit 0.

So `--check` catches "`wrangler.toml` changed and nobody regenerated" and nothing else — not a
hand-edit, not a truncation, not a file generated with different flags. A CI check that passes when
its subject has been deleted is worse than no check, because it reads green and stops anyone
looking.

The gate is therefore:

```bash
npx wrangler types --include-runtime --strict-vars false
git diff --exit-code -- worker-configuration.d.ts
```

Verified to detect the same corruption `--check` missed. This mechanically closes part of what
`test/config.test.mjs` currently asserts by hand — that file stays, since it also pins route and
`[assets]` facts that types cannot express.

### 4.3 Regeneration is deterministic, but wrangler-version-bound

Line 3 of the generated file records the runtime source:
`// Runtime types generated with workerd@1.20260714.1 2025-01-01`. `package-lock.json` is committed
and both CI jobs use `npm ci`, so every run resolves the identical wrangler and the diff is stable.
A wrangler bump (currently `^4.112.0`; 4.115.0 is available) **will** change the generated file and
turn the gate red until it is regenerated and committed. That is correct behaviour, not a bug —
document it in the Worker README so the next person does not treat it as a broken gate.

---

## 5. Type checking

`cloudflare-worker/tsconfig.json`:

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
    "noUncheckedIndexedAccess": true
  },
  "include": ["src/**/*", "worker-configuration.d.ts"]
}
```

`module: "es2022"` is explicit because `package.json` has no `"type"` field, and that absence is
itself deliberate (`vitest.config.mjs:9-11` — adding it would change how wrangler resolves
`src/index.js` at deploy time). The explicit setting means tsc treats `src/index.js` as ESM without
touching `package.json`.

Reaching zero requires the ~11 JSDoc `@param` blocks (§1.1) plus §6.

---

## 6. `parseKvEntry()` — the one code change

Silencing the seven `TS2339` errors with a cast would be a *claim*, not a *check*. Instead,
consolidate the three scattered guards (§1.2) into one validator returning a discriminated union:

```js
/**
 * @typedef {{kind: "individual", handle: string | null}
 *         | {kind: "alias", redirect: URL}
 *         | {kind: "unknown"}} KvEntry
 */
```

`parseKvEntry(raw)` performs the shape check once and returns a narrowed value. TypeScript then
narrows correctly at all five access sites for free, the redirect-host validation becomes
independently unit-testable, and the trust boundary is named in one place rather than implied in
three.

### 6.1 The two `individual` call sites are NOT symmetric

This is the one place the refactor can silently change behaviour, so it is spelled out:

| Site | Current code | Required `handle` |
|---|---|---|
| Custom domain (`:486`) | `custom.type === "individual" && typeof custom.handle === "string"` | **must be a string** — otherwise falls through to `passThrough()` |
| Subdomain (`:586`) | `entry.type === "individual"` | **must be absent/ignored** — `serveIndividual(..., null)`; partna-pages derives the handle from `Host` |

A `{type:"individual"}` entry with no `handle` is *valid* under a subdomain key and *invalid* under
a `domain:<host>` key. `parseKvEntry()` must therefore return `handle: string | null` and leave the
non-null requirement to the custom-domain call site:

```js
if (e.kind === "individual" && e.handle !== null) { /* custom-domain path */ }
```

Collapsing this into the parser — e.g. by rejecting handle-less individual entries outright — would
break subdomain routing for every site. Add one test alongside the refactor: a `domain:<host>` entry
of `{type:"individual"}` with no `handle` must pass through to origin, not render. `T11b` covers an
*unknown* host; it does not cover a *known host with a malformed entry*.

**This is a refactor with no behaviour change.** The existing suite already pins exactly this logic
— `T3a` (off-domain target → 404, never 301), `T3b` (non-https on our own domain → 404), `T3c`
(unparseable target → 404), `T11` (custom-domain handle injection), `T15`/`T15b` (unknown or absent
`type` falls through to origin). Those six tests must pass **unmodified**. That is the proof the
refactor is faithful; if any needs editing, the refactor is wrong.

---

## 7. Invariant tests

`cloudflare-worker/test/invariants.test.mjs`, parsing `src/index.js` via the TypeScript compiler API
(`ts.createSourceFile`) — already a dependency from §3, so no `acorn`/`espree` is added. Vitest runs
`environment: "node"` and `config.test.mjs` already reads files from disk, so the lane exists.

| ID | Invariant | Status today |
|---|---|---|
| INV-1 | every `ctx.waitUntil(expr)` argument chain terminates in `.catch()` | **FAILS** — `index.js:439` |
| INV-2 | no visitor-facing `new Response` outside `finalize()` | passes |
| INV-3 | the `RESERVED` array literal contains no duplicates | passes — 298/298 unique |
| INV-4 | no bare `fetch(` outside `passThrough()` | passes — sole site is `index.js:331` |

**Three of the four are ratchets, not repairs.** They pin behaviour that is already correct, so that
a future edit cannot quietly regress it. Only INV-1 finds something live, and the fix is a
`.catch()` matching the two at `:350` and `:356`.

INV-2 needs exactly two exemptions, both functions that construct a `Response` for a non-visitor
purpose: `finalize()` itself (`:319`) and `withCacheTtl()` (`:259` — builds the cache copy, never
returned to a visitor). The remaining six `new Response` sites (`:397`, `:463`, `:519`, `:533`,
`:567`, `:576`) are all already arguments to a `finalize(...)` call and pass without exemption.

`passThrough()`'s raw 101 return (`:332-334`) is **not** an INV-2 exemption — it returns an existing
`Response` rather than constructing one, so the rule never sees it. It is called out here only
because re-wrapping it would drop `response.webSocket` and break the connection, which is exactly
the "fix" a future reader might attempt when tightening this invariant.

INV-3 matters because `RESERVED` is a hand-maintained 298-entry `Set` literal. A `Set` silently
absorbs a duplicate, so a copy-paste error is invisible on inspection and in behaviour.

### 7.1 INV-1 fails closed

The rule recognises exactly one safe shape: `ctx.waitUntil(<expr>)` where `<expr>` is a call chain
whose outermost member call is `.catch`. Anything else — a bare identifier
(`ctx.waitUntil(somePromise)`), a conditional, an `await`ed value — **fails the test**. That is
deliberate: an indirection the checker cannot follow is exactly the case where a missing `.catch()`
would go unnoticed, so the burden is on the author to keep the shape inspectable or to justify an
explicit exemption in the test file. This mirrors how `OutboundHttpGuardTest` handles categories it
cannot classify.

---

## 8. Out of scope

- **`cloudflare-worker/test/**`** — worth type-checking eventually, since a bug in
  `test/helpers.mjs` (192 lines of Miniflare stubbing, including the `outboundService` stub that
  stops CI hitting real `api.partna.au`) yields false-green tests. It is a separate ~550-line job
  and does not block §1's gap. Revisit after this ships.
- **Repo-root `resources/js/`** — two files, Vite bootstrap only, not externally exposed.
- **Runtime validation of KV *content*** beyond shape. `parseKvEntry()` validates structure and the
  redirect host; it does not verify that a handle corresponds to a real user. That remains
  `SyncSubdomainToKvJob`'s responsibility as single writer.

---

## 9. CI

A new `worker-static` job in `.github/workflows/ci.yml`, sibling to `worker-tests`:

```
if: github.event_name != 'schedule'
runs-on: ubuntu-latest
node-version: '22'          # miniflare + wrangler both require >= 22
working-directory: cloudflare-worker
  npm ci
  npx wrangler types --include-runtime --strict-vars false
  git diff --exit-code -- worker-configuration.d.ts     # §4.2 — NOT `types --check`
  npx tsc --noEmit
  npx eslint src
  npx biome ci src test
```

Two constraints inherited from the reasoning already written into `ci.yml:323-334`:

- **Its own job, not steps appended to `worker-tests`.** A type error and a failing behavioural test
  are different signals and should be separately visible; appending also means a test failure masks
  the static result.
- **No `paths:` filter.** A path-filtered required check does not run when the filter misses, and
  GitHub renders a skipped required check as green — the guard would silently stop guarding.

`worker-static` runs the four checks in fail-fast order: types must be current before `tsc` is
meaningful, and `tsc` must pass before type-aware `eslint` can resolve.

---

## 10. Formatting

Biome at `indentWidth: 4`, matching the `CLAUDE.md` house convention and the existing test files.
Measured churn:

| Setting | `src/index.js` | `test/*.mjs` |
|---|---|---|
| 2-space (matches `src` today) | 359 lines | 298 lines |
| **4-space (chosen)** | **983 lines** | 4 lines |

`src/index.js` is currently 2-space while the tests and the stated convention are 4-space; any
formatter rewrites one side or the other.

**`biome.json` must ignore `worker-configuration.d.ts`.** It is 14,683 generated lines; formatting
it would produce a permanent fight with `wrangler types` — every regeneration reverts Biome's
output, and the §4.2 `git diff --exit-code` gate would then fail on a purely cosmetic difference.
ESLint needs no such exclusion: wrangler emits `/* eslint-disable */` on line 1.

**The reformat ships as a single commit containing nothing else**, and its SHA goes into a
`.git-blame-ignore-revs` at the repo root so `git blame` on the Worker stays usable. 983 lines of
reformatting is precisely where a one-character security change would hide, so it must never ride
along with the type work.

---

## 11. Commit sequence

| # | Commit | Existing suite |
|---|---|---|
| 1 | tooling + `tsconfig` + `eslint.config.mjs` + `biome.json` + generated types; **no source changes** | green |
| 2 | **format-only** (§10) — nothing else in this commit | green |
| 3 | JSDoc `@param` annotations (§1.1) | green |
| 4 | `parseKvEntry()` refactor (§6) + the §6.1 malformed-custom-domain test | green, six existing tests **unmodified** |
| 5 | `invariants.test.mjs` + the INV-1 `.catch()` fix (§7) | green |
| 6 | `worker-static` CI job (§9) | green |

Each step leaves the 20-case suite passing.

**Format is commit 2, not last.** Biome is configured in commit 1, so if the reformat trailed the
semantic work, `biome ci` would fail locally throughout commits 3–5 — the gate would be unusable
during exactly the work it exists to guard, and every line written in commits 3–5 would be
immediately rewritten by the formatter. Reformatting first means all later diffs are small and
already in final form.

**The CI job is last.** Commit 1 adds config that does not yet pass `tsc` (the KV boundary is not
resolved until commit 4), so the gate goes live only once the code is clean and `development` is
never knowingly red.

---

## 12. Rejected alternatives

**Biome alone.** One Rust binary, one dependency, near-zero install cost — but no type-aware rules.
It cannot see `env.SUBDOMAIN_KV`, cannot type the KV boundary, and its `noFloatingPromises` is a
nursery heuristic without type information. Hygiene only; would have found none of §1.2.

**`tsc` alone, no lint or format.** Roughly 70% of the value for 30% of the work, and a reasonable
fallback. Rejected only because the marginal cost of the other three layers is small once
`tsconfig` exists.

**Invariant tests alone, no new dependencies.** Catches INV-1 and future structural drift but
nothing about types — it would have missed all nine findings in §1.2.

**Porting `src/index.js` to TypeScript.** Rewrites the entire security-critical file and changes the
wrangler build path. `checkJs` + JSDoc achieves the same checking with a ~11-line diff. Not worth
the blast radius.
