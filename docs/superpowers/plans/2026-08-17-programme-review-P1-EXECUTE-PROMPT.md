# Execute prompt — the six P1s from the programme review (2026-08-17)

Paste everything between the fences into a fresh session.

The audit file is `audits/consolidation/2026-08-17-programme-review/CONSOLIDATED.md`
(35 findings; this prompt covers the six P1s only). Grouping below is deliberate:
four of the six are the same defect theme and two are the same command, and
`fix-flow.md` runs units **sequentially**, so the order changes how much work
each unit is.

---

```
Rename this session to audit-fix-p1.

Execute the P1 findings in audits/consolidation/2026-08-17-programme-review/CONSOLIDATED.md,
following scripts/audit/fix-flow.md. Branch audit-fix/programme-review-p1-2026-08-17
off development. Six findings: PGR-1 … PGR-6.

Everything in that file was produced by the audit pipeline and adjudicated; the
review gate then independently re-verified PGR-3 and PGR-6 in the code. Trust
neither blindly — fix-flow's rule zero applies: verify the premise before you
fix it. This review disproved one adjudicated P1 and materially restated
another, both by reading the code the finding cited.

SETUP.
Work in your OWN git worktree: `git worktree add`, then `cp -a <main>/vendor ./vendor`
(a SYMLINKED vendor makes Pest's ->in('Feature') binding resolve to the main
checkout, the app never boots, and you get ~1100 fake failures) and symlink .env.
Check `git worktree list` first — a peer session was cleaning worktrees up.

⚠️ BASELINE IS RED, AND NOT BY YOUR DOING. `development` currently fails six shop
contract/golden-master tests from `feature/store-rows-auto-latest`:
PlatformResourceContractTest ×2, ShopAsyncConnectTest T1/T5, ShopSelectionLockTest
T16c, IntegrationContractGoldenMasterTest. Every one is `assertExactJson`/golden
master failing on a single added line, `+ "autoLatest": false` — ShopBrandResource:42
gained a field without its contracts being updated. Take your baseline, RECORD
those six, and do not chase them. If your post-fix run shows exactly those six and
nothing else, you are green. Do not "fix" them by regenerating a golden master:
updating a contract test asserts the new shape is INTENDED, that field has no wire
manifest, and it is not yours.

IDS. The six source runs used overlapping id spaces — #TEST-1 named four different
findings, #CFG-1 four more. Ids were renumbered to #PGR-n and each finding records
"Source: <run> — was #<original>". Match on finding TEXT when you tick. Never
sed/script a tick keyed on id.

UNITS — run in this order, sequentially.

UNIT A — the three-lane cache contract: PGR-6 + PGR-1  (do these together)
  PGR-6 is the defect; PGR-1 is the CI guard that stops it recurring. Fixing
  either alone leaves the other half of the same hole.
  - BLOCKER GATE: plan first, present the blast radius, WAIT for sign-off. It
    changes cache behaviour on the public payload path and an owner ruling rides
    on it. Do not combine plan+implement (P1).
  - The ruling, already made 2026-08-17, is the spec for this unit: 60s
    TTL-bounded staleness is NOT acceptable for owner-initiated pool mutations.
    All three lanes required.
  - Reference implementation is PoolController::poolChanged() (:226-233):
    BuildState::bump() + DB::table('site.sites')->update(['updated_at' => now()])
    + conditional CloudflareCachePurgeJob. Lane 2 is the one everyone omits — the
    60s payload cache key composes from site.sites.updated_at, so skipping it
    serves stale from the ORIGIN while the CDN is correctly purged.
  - Missing lane 2: PoolItemCreateController::pin(), ItemController::destroy(),
    ItemLinkController::upsert()/destroy(), and ProjectionWriter::bumpSite()
    (:1672-1679, which calls BuildState::bump() and nothing else).
  - ONE REAL SUBTLETY, do not flatten it: spec §12.6 explicitly ACCEPTED lane-2
    staleness for the manual write lane ("a content write does not move
    site.sites.updated_at … TTL-bounded staleness only"). So store() →
    writeManualItem() → bumpSite() was a recorded decision, not a bug. The owner
    ruling supersedes it for owner-initiated mutations, which is why bumpSite()
    is in scope — but say so in the change rather than silently contradicting a
    checkpoint. If bumpSite() also fires on the CONNECTOR path, work out whether
    a connector run should bust lane 2 too, and decide it explicitly.
  - Extend tests/Feature/Content/PoolCacheLanesTest.php, which already pins the
    three-lane contract for PUT /pools/{pool}/order. Assert site.sites.updated_at
    actually CHANGES. Every lane assertion must be proven to fail with its lane
    removed — §17.2 records a three-lane test that passed with a lane deleted,
    because writeManualItem() bumps internally. Assert an exact revision delta,
    never "> 0".

UNIT B — ContentRepairEventItemsCommand: PGR-4 + PGR-2  (same command)
  PGR-4 is the ordering defect, PGR-2 is its missing test. One unit.
  - The command commits the destructive retirement (:80, removed_at => now())
    BEFORE resolving sites and running the three invalidations (:91-98), with no
    transaction spanning them.
  - This is the SHAPE of the §14.3 incident, whose SYMPTOM was already fixed. A
    42703 on a bogus deleted_at filter killed the run after the retirement had
    committed, leaving the invalidations undone. The command's own comment block
    (:82-90) narrates it. The bogus column was fixed; the ordering was not.
  - Blast radius is cache-only (BuildState, sites.updated_at, CF purge) — stale
    retired events until TTL, not data loss. Retirement is one-way, which is what
    stops it self-healing. Size the fix to that; do not gold-plate.
  - PGR-2: the existing retirement test asserts removed_at only. Assert the
    invalidation itself.

UNIT C — PGR-5, the auth finding  (STANDALONE — do not bundle)
  - HARD BLOCKER GATE: auth + PII on an unauthenticated public endpoint. Plan,
    present, WAIT for explicit go-ahead. Do not implement on your own judgement.
  - POST /api/public/auth/resolve-identifier takes a handle and returns
    core.users.primary_email — the private Supabase login address, a DIFFERENT
    column from the intentionally public public_contact_email. Handles are public
    subdomains and enumerable, so it is a systematic harvesting vector.
  - Verify this before planning, because it changes the severity and the audit
    did not check it: the route's bot.token middleware is INERT on both
    environments. VerifyBotToken short-circuits when partna.bot_protection.mode
    is 'off'; BOT_PROTECTION_MODE is unset on development AND production; the
    config default is 'off'. So the only live control is throttle:login-identifier
    at 20 req/min per IP plus 40-120ms jitter.
  - The preferred fix changes the login flow (resolve handle → user server-side,
    drive the Supabase OTP/password call from the backend, stop returning the
    email to the browser). That is a FRONTEND-VISIBLE contract change — flag it
    as such and get the ruling before writing code. Turning bot protection on is
    a mitigation, not the fix, and is its own decision.
  - Production has ZERO users, so live exposure there is nil today; dev holds
    real handles and addresses. Pre-existing, not from the convergence programme.

UNIT D — PGR-3, the dispatch stagger  (standalone, small)
  - ⚠️ THE DRAFT'S REASONING WAS WRONG AND THE ADJUDICATED TEXT IS THE CORRECT
    ONE. The scan claimed the stagger is a no-op because $i / BATCH_SIZE is
    always < 1 so floor() yields 0. That is false — floor() wraps the whole
    expression, floor($i / 200 * 300), which ramps 0 → 298s correctly WITHIN a
    chunk (i=50 → 75s, i=199 → 298s). Do NOT "fix" the arithmetic.
  - The real defect is one level up: values() re-indexes every chunk to 0..199
    and ->delay() only sets available_at with no sleep between chunks, so N rows
    produce ceil(N/200) OVERLAPPING copies of the same ramp — bursts of N/200 at
    each point rather than a spread. On a large install base that still hits the
    billed Mistral OCR / MenuAiExtractor path in waves.
  - Fix: make the delay cumulative across the whole run, not per-chunk. Consider
    a --limit so a first real run can be capped, and verify the delay curve in
    --dry-run output.
  - This is billed work on an unrun fleet-wide backfill. Do not trigger a real
    run to test it.

LANES — run all of them, and know their traps.
  COMPOSER_PROCESS_TIMEOUT=0 composer test        (serial; the suite exceeds
                                                   composer's 300s default and
                                                   the timeout presents as a hang)
  ./vendor/bin/pest --parallel --processes=4      (paratest takes at most ONE
                                                   path argument)
  composer test:pg                                (throwaway postgres:16; the
                                                   local .env DB_HOST is a dead
                                                   ref. DB_DATABASE=partna_test,
                                                   DB_SSLMODE=disable,
                                                   PG_LANE_REQUIRED=1)
  composer test:schema                            (needs a FRESH db with every
                                                   migration applied;
                                                   SCHEMA_LANE_REQUIRED=1 or it
                                                   skips and reads green.
                                                   QueryPlanTest on
                                                   analytics.site_visits is a
                                                   known AUTOANALYZE flake —
                                                   check pg_stat_all_tables
                                                   .last_autoanalyze before
                                                   believing it)
  composer test:authz                             (AUTHZ_LANE_REQUIRED=1 — without
                                                   it, "31 skipped" reads green
                                                   and tests nothing)
  php -d memory_limit=1G ./vendor/bin/phpstan analyse app --no-progress
  ./vendor/bin/pint

  Unit A touches ProjectionWriter, so tests/Postgres/ is MANDATORY, not optional —
  that lane's stand-in DDL is hand-written and drifts silently from writer changes.

RULES.
  - Units sequential. Plan → implement → INDEPENDENT review (a separate instance,
    never the implementer) per unit. Models come from the audit file's
    ## Execution policy — read them from the file, do not substitute.
  - A box goes to [x] only after tests pass AND the independent review says PASS.
  - Never auto-merge or push to a shared branch; work stays on the audit-fix branch.
  - Tests run SQLite, production is Postgres. Verify constraint-bound writes
    against supabase/migrations/ DDL, not just a passing suite.
  - If a finding's premise does not survive verification, say so and close it
    WONTFIX with the reason. That is a legitimate outcome; leaving it open is not.

WHEN DONE. Report which boxes are ticked, which are WONTFIX and why, and hand the
remaining 14 P2 / 15 P3 back untouched — they are not in this session's scope.
```
