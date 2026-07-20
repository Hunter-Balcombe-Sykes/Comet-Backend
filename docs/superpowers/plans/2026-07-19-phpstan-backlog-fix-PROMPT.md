# PHPStan Backlog Fix — Orchestrator Prompt (2026-07-19)

> Paste this whole file as the opening prompt to an **Opus** session. Opus plans and
> orchestrates only — it does not edit code itself. **Sonnet** subagents implement and
> review (separate instances); **Haiku** subagents do read-only exploration. One task
> per subagent.

---

## Mission

Branch `chore/security-tooling` added a "Static analysis (PHPStan level 5)" step to CI.
`composer analyse` currently exits 1 with **~121 findings** (exact list from running it —
trust the live output over this doc). Your job: drive `composer analyse` to **exit 0** by
fixing real issues and baselining ONLY verified false positives — then the branch merges
with the gate live.

**Definition of done (all four):**
1. `composer analyse` exits 0.
2. `composer test` fully green (run it alone — never concurrently with a subagent that
   also runs tests; parallel Pest runs corrupt each other's SQLite state).
3. `vendor/bin/pint --test` clean on every file you touched (run pint only on changed
   files, never repo-wide).
4. Every finding in the fix scope has a recorded outcome: **FIXED** (code change) or
   **BASELINED** (verified false positive, one-line justification). No finding may be
   baselined without a subagent having read the code and explained *why* it is safe.

**Hard rules:**
- NEVER run `composer analyse:baseline` (wholesale regeneration). To baseline a verified
  false positive, hand-add a single `ignoreErrors` entry (message + identifier + count +
  path) to `phpstan-baseline.neon`.
- Never create Laravel migration files; schema changes are out of scope entirely.
- Commit per work unit on `chore/security-tooling`. Before each commit run
  `git diff --cached --stat` and verify the file list is exactly your unit's files.
  Never push.
- Tests run on SQLite; prod is Postgres. A passing suite does not prove
  constraint-bound writes — for anything touching DB writes, check
  `supabase/migrations/` DDL.
- If an implementer claims a test failure is "pre-existing", verify by stashing and
  running the same test on the prior commit. Do not accept the claim on trust.
- If a fix balloons (touches >3 files or changes behavior beyond the finding), STOP
  that unit and surface it to Josh instead of pressing on.

## Context you need

- The PHPStan gate was silently broken 2026-06-30 → 2026-07-19 (stale baseline paths
  from the SmartLinks deletion aborted every run), so ~7 weeks of feature work landed
  unanalyzed. The backlog was triaged 2026-07-19: ~847 mechanical-noise findings are
  already baselined (identifiers: property.notFound, nullCoalesce.offset,
  nullsafe.neverNull, function.alreadyNarrowedType, arrayValues.list, method.notFound,
  varTag.differentVariable). What remains is the fix scope.
- Two findings are **pre-verified — implement exactly these fixes first** (details below):
  R1 (a real production 500) and R2 (a stale docblock causing a false-positive cascade).
- `config/checkpoint.php` and `config/vigil.php` on this branch belong to the security-
  scanner work — do not touch them.

## Work units (P0 → P3; each = investigate → implement → independent review → test → commit)

### R1 — P0 — RefreshController wrong ApifyBudget import (REAL BUG, pre-verified)
`app/Http/Controllers/Api/Platforms/RefreshController.php:11` imports
`App\Services\Platforms\ApifyBudget`, which does not exist. The real class is
`App\Services\Cache\ApifyBudget` (every other caller imports it correctly:
InstagramController, GoogleBusinessAutoSync, MenuApifyScraper, etc.). At runtime,
`app(ApifyBudget::class)` at line 111 throws `BindingResolutionException` → the manual
Instagram refresh endpoint 500s on its happy path (active IG connection, past cooldown).
**Fix:** correct the `use` statement. **Test-first:** add a Pest feature test that hits the
refresh endpoint with an active Instagram connection past cooldown and asserts non-500
(mock the scraper per the pattern in existing Instagram tests — bind mocks BEFORE
`IntegrationConnection::create()`, or the real scraper is captured). Confirm the test
fails on the unfixed code, then fix.

### R2 — P0 — `User::$auth_user_id` docblock lies about nullability (pre-verified)
`app/Models/Core/User/User.php:26` declares `@property string $auth_user_id` but it is
genuinely nullable (unclaimed pre-account users have NULL until claim — see CLAUDE.md
"Pre-account signup"). PHPStan therefore proves `ClaimSiteService`'s guard always throws
and marks the entire claim happy path unreachable — cascading 4+ false positives
(booleanOr.alwaysTrue + deadCode.unreachable + notIdentical.alwaysTrue in
ClaimSiteService, property.onlyWritten on `$emailGuard`).
**Fix:** change to `@property string|null $auth_user_id`. Then re-run `composer analyse`:
this may clear other `auth_user_id`-adjacent findings AND may surface new nullability
findings where code assumed non-null — triage any new ones honestly (they are real
signal, not noise; the claim flow's own tests are the reference for intended behavior).

### R3 — P1 — `match.unhandled`, AppleController:383
An unhandled match arm is a fatal `UnhandledMatchError` if that value occurs. Determine
whether the input set is genuinely closed (then baseline with justification) or add the
missing arm / default.

### R4 — P1 — ShopController dead branches (`isset.offset` :638, `offsetAccess.notFound` :645)
PHPStan proves `clientBrand` / `store` keys absent from the array shape. Either the shape
type is wrong (fix the type) or the guarded branch genuinely never runs (dead code — likely
a leftover; decide remove vs fix the producer). `offsetAccess.notFound` on :645 is the
higher-risk one: unguarded access to a proven-absent key.

### R5 — P1 — `catch.neverThrown`, SafeUrlFetcher:160
Security-adjacent (this is the SSRF-mitigation fetcher). A `catch (ConnectionException)`
that can never fire means that failure mode is not handled the way the code implies.
Find what the wrapped call actually throws and catch that — or prove the catch is
genuinely redundant and remove it. Do not weaken any SSRF behavior.

### R6 — P1 — Redis stub arity cluster (`arguments.count`, 5 findings)
`Redis::eval()`/`Redis::set()` called with more args than the facade stub declares:
PerTargetReportThrottle:37, VerifyBotToken:227, TokenRevocationService:108+144,
GuardsMediaProcessing:28. Working theory: stale stub, real phpredis `eval` is variadic.
**Verify against the actual runtime client** (`config/database.php` redis client —
phpredis vs predis) and the installed stubs. If calls are correct → baseline all 5 with
one shared justification. If any call is genuinely mis-ordered (eval's `$numkeys`
position is a classic footgun) → that is a P0 bug in rate-limit/token-revocation code;
fix with a test.

### R7 — P2 — Trait return-type root cause (`return.type` ×20 + `argument.unresolvableType` ×18)
All 38 findings trace to `ManagesIntegrationConnection::connectionFor()` (re-analyzed per
consuming class). Read the trait's actual return statement; fix the signature/generics
ONCE in the trait. Expect all 38 to clear. If the trait genuinely can return a wrong type
on some path, that is a real bug — surface it.

### R8 — P2 — `argument.type` cluster (×39, User vs Eloquent\Model)
Repeating pattern: methods typed to expect `User` receive an untyped Eloquent result.
Investigate the 2–3 producer sites (relation/query under-typing) rather than 39 call
sites — a `@return User` / generics annotation at the producers should collapse most of
the cluster. Anything that survives after producer fixes: triage individually. Do not
blanket-baseline this identifier without reading a sample of at least 5.

### R9 — P2 — Dead-condition set (identical/notIdentical/smaller/booleanOr alwaysX, ~12 findings)
RecipeSignals:266, EvidenceConclusions:603, InstagramConnectionSeeder:221,
ImagePaletteExtractor:110+199 (compound bounds check entirely dead), ScreenshotSampler,
EventbriteScraper, ShopifyScraper, UberEatsMenuDriver. Per file: is the condition dead
because a type narrowed (baseline with justification, or delete the dead guard) or
because the logic is wrong (fix)? These need individual reads — no batching.

### R10 — P3 — Small singles
- `method.unused` ×2 — `GoogleBusinessAutoSync::hasStoreKey()` / `::count()`: check git
  history for a dropped caller before deleting; an accidentally-dropped call site in an
  AutoSync service is a real bug.
- `phpDoc.parseError` ×2 — MenuMerger:63/683 malformed `@param` shapes: fix the docblocks
  (a parse error silently disables checking of that parameter).
- `class.notFound` ×7 (non-R1) — DevInsightsController:159 `@return array{...}` shape
  written so field names parse as classes: rewrite the docblock.
- `closure.unusedUse` — MenuMerger:336 `use (&$find)` never used: classic broken recursive
  closure; read whether `$find` was meant to recurse.
- `argument.byRef` — ParsedUrl:38 readonly property passed by-ref: confirm the callee
  never writes (else it fatals); restructure if it does.

## Reporting

Maintain a running checklist (unit → findings → outcome FIXED/BASELINED → commit SHA).
At the end: reconcile — every starting finding must appear exactly once; final
`composer analyse` and `composer test` output; list of anything you stopped and
escalated. Reconcile counts before declaring done: findings resolved + baselined must
equal the starting count from your own first `composer analyse` run.
