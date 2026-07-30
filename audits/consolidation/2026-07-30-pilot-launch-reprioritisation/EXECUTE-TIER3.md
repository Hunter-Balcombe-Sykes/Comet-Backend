# Execute prompt — P1-LAUNCH Tier 3 (low value; read the disposition advice first)

Paste the block below into a **fresh Claude Code session** at the repo root.

Tier 3 of the P1-LAUNCH remainder after the run that merged at `4e221ca7`. **Do Tier 1 and Tier 2
first.**

🔴 **Read this before running it at all.** `CLAUDE.md` says plainly:

> **Never run a "clear the backlog" campaign.** Recall degrades past ~100K tokens, the P3 tail carries a
> measured ~40% already-fixed rate, and under `fix-flow.md` the verify→plan→implement→review overhead
> exceeds a sub-hour fix. **Disposition beats execution.**

This slice **is** that P3 tail. Running it as a campaign is explicitly against policy. It is written up
so the work is *available* and its reasoning is captured — not because it should all be done.

**The recommended use of this file is triage, not execution:** work items 1 and 2 (which contain a real
documentation lie and a live-config gap), then close the rest with recorded reasons. Two items here are
also flagged as candidates for **WONTFIX**, with the argument already made.

The bounded exception, from the same `CLAUDE.md` section: **when you have one of these files open for
real work, fix its `SLOP-*`/`CFG-*`/cosmetic items in the same commit.** That is where this tail is
meant to go — absorbed in passing.

---

## The prompt

> Work the **Tier 3** slice below. Follow `scripts/audit/fix-flow.md`, models from the
> `## Execution policy` header of
> `audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md`.
>
> 🔴 **Read the "Disposition first" section below before planning anything.** Your default for most of
> these is a recorded close, not an implementation. **Closing a finding WONTFIX with a stated reason is a
> legitimate, successful outcome. Leaving it open forever is not** — it blocks `archive-done.sh` and makes
> the audit system read permanently red.
>
> **Scope — 3 work items, 2 WONTFIX candidates, 1 operational block, 1 traceability task.**
>
> | # | Item | Recommendation |
> |---|---|---|
> | 1 | `TEST-9` / `271-TEST-1` — `site.themes` guard + a **false claim in `CLAUDE.md`** | **DO IT** — small, and fixes a documentation lie |
> | 2 | CSP hardcoding in the Cloudflare Worker | **DO IT** — real, cheap, config-shaped |
> | 3 | `TEST-49` + `TEST-50` + `#TEST-41` — invariant guards & inline DDL | **JUDGE** — cheap greps, low value |
> | 4 | `SEC-4` — raw insert bypasses `$fillable` | **WONTFIX candidate** — argument below |
> | 5 | `INH-6` — duplicate `normalizeName` | **PARTIAL ONLY, do not tick** — argument below |
> | 6 | Unit 6 — 3 drills + `LC-K6` + `LC-RERUN` | **DEFER** — hours, operational, premature at 0 users |
> | 7 | `#38` — untraceable finding id | **CLOSE** — verified phantom |
>
> ---
>
> ### Step 0 — isolated worktree (REQUIRED)
>
> ```bash
> git fetch origin
> git worktree add -b audit-fix/tier3-2026-07-30 \
>   "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-tier3-2026-07-30" \
>   origin/development
> cd "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-tier3-2026-07-30"
> composer install
> cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env      # REAL copy, never a symlink
>
> git merge-base --is-ancestor 4e221ca7 HEAD && echo "BASE OK" || echo "STOP: base predates P1-LAUNCH"
> ```
>
> **Never run `git stash`** — other sessions share this checkout's stash stack. Do not push, do not merge.
>
> ### Step 1 — concurrency pre-flight (before planning, and again before every commit)
>
> ```bash
> git fetch origin && git worktree list
> for wt in /Users/joshuahunter/Herd/Side\ Street/backend-wt/*/; do
>   echo "── $wt"; git -C "$wt" status --short; done
> ```
> 🔴 **A branch diff is NOT sufficient** — a previous session's whole changeset sat uncommitted for hours
> while `git diff` against its branch returned empty. Check working trees, not just branches.
> ⚠️ **Two `php artisan test` runs in the SAME worktree interfere** (`Storage::fake('media')` fixed path,
> process-global Redis session sets). Driver pinning protects across worktrees, not within one.
>
> ---
>
> ## Disposition first — read this before planning
>
> In the run this slice came from, **of 13 findings examined only 3 were implementable exactly as
> written.** Four were already fixed, one premise was false, and **five carried prescriptions that would
> have shipped bugs.** The P3 tail additionally carries a measured **~40% already-fixed rate**.
>
> So: **verify every premise before planning, and read every proposed fix before trusting it.** Expect
> casualties and treat them as the goal. For each item, your output is one of:
> - **FIXED** — with a test,
> - **DEAD** — no longer reproduces, one-line reason,
> - **WONTFIX** — reproduces, deliberately not fixed, with the argument recorded in the audit file.
>
> All three are successful outcomes. Inventing work to justify a ticket is not.
>
> ---
>
> ## Item 1 — `TEST-9` / `271-TEST-1`: the `site.themes` guard, and a false claim in `CLAUDE.md`  ← DO IT
>
> **Sources:** `…07-11…` (`#TEST-9`) and `…07-24…:1733` (`271-TEST-1`) — the same ask from two lenses.
>
> **Half of this is already closed upstream.** P0-LAUNCH moved `ArchitectureSystemConstraintsTest` from
> `tests/Feature/Database/` into `tests/Schema/` and into the real applied-schema lane
> (`phpunit.schema.xml` / `composer test:schema`), against a container the real `supabase/migrations/` set
> has been applied to. Its own header records that **the file ran nowhere for its entire life** — it lived
> in `tests/Feature` and gated every assertion on a Postgres check, while `Tests\TestCase::setUp()`
> repoints `pgsql` at SQLite unconditionally, so all its assertions skipped silently in every lane.
>
> **What is still open:** nothing asserts that `site.themes` stays dropped.
> `git grep -n "site\.themes" -- tests/` returned **nothing** as of 2026-07-30. Verify that still holds.
>
> **Fix:** add the assertion to `tests/Schema/ArchitectureSystemConstraintsTest.php` (that file has no
> concurrent owner now — P0-LAUNCH merged and its worktree is gone). Assert against
> `information_schema.tables` / `pg_proc` that `site.themes` and `set_default_theme_for_site()` are absent.
> Run it with `composer test:schema` — ⚠️ **that lane is NOT part of `composer test`**, so a green
> `composer test` proves nothing here.
>
> 🔴 **The most valuable part of this item is the documentation fix.** `CLAUDE.md:228` claims:
> > *"Adding a second architecture is a platform decision, not a task … **Pinned by
> > `ArchitectureSystemConstraintsTest`**."*
>
> That claim is **false** — the test asserts the `architecture_id` CHECK, the `design_kits` CASCADE FK and
> the `trg_create_empty_design_kit` trigger, and **nothing about themes**. It was false in a second way
> until this week, when the test ran nowhere at all. **Correct the claim and cite what actually pins it.**
> Documentation that claims coverage it does not have is worse than silence — it is exactly what stops the
> next developer from adding the guard.
>
> ---
>
> ## Item 2 — CSP hardcoding in the Cloudflare Worker  ← DO IT
>
> Split out of `#10` deliberately during the P1-LAUNCH run (`#10` itself closed DEAD — `unclaimedHtml()`
> already derives every domain from `${PARTNA_DOMAIN}`).
>
> **File:** `cloudflare-worker/src/index.js`.
> - `https://app.partna.au` is hardcoded in two CSP `frame-ancestors` headers — `:156` (shared hardening)
>   and `:311` (sitepage response). On a non-prod Worker deploy, a staging dashboard origin would be
>   refused framing.
> - `PARTNA_DOMAIN` is itself a compile-time `const PARTNA_DOMAIN = "partna.au"` (`:46`), **not** read from
>   `env` — so "derive from environment" is not literally true anywhere in the file.
>
> ⚠️ **This is the public edge.** Every `<handle>.partna.au` request passes through this Worker. A CSP
> mistake either breaks framing for the real dashboard or loosens a real protection. Get the header exactly
> right and say how you verified it.
> ⚠️ **`SyncSubdomainToKvJob` is the ONLY KV writer** — do not introduce another.
> ⚠️ The Worker has its own test harness with real traps: `outboundService` + `redirect: "manual"` are what
> stop tests hitting **real production**, Miniflare 4 dropped `res.waitUntil()`, and it is Node 22 only.
> Read `reference_cloudflare_worker_test_harness` behaviour before writing a test.
>
> ---
>
> ## Item 3 — `TEST-49`, `TEST-50`, `#TEST-41`: invariant guards and inline DDL  ← JUDGE
>
> - **`TEST-49`** (`…07-28…:5455`) — the `detectors_surface_xor_signal` DB CHECK has no grep-based
>   invariant test.
> - **`TEST-50`** (`…07-28…:5460`) — `content.identity_keys` **deliberately has no** unique index on
>   `(key_class, key_value)`; that is a *"must not exist"* invariant with no guard against a well-meaning
>   future addition. ⚠️ This one is more interesting than it looks: a guard that asserts the **absence** of
>   an index is unusual, and needs a comment explaining *why* the absence is correct, or the next developer
>   will delete the guard instead of respecting it.
> - **`#TEST-41`** — remove inline migration DDL from `tests/Postgres/BrandAssetPipelineTest.php` and
>   `tests/Postgres/CatalogSyncIdempotenceTest.php`. Its deferral condition ("once P0-LAUNCH merges") is
>   now **met**, and those files have no owner.
>   ⚠️ Re-verify the premise first: P0-LAUNCH widened the schema-drift gate so it can *see* inline DDL in
>   test files and re-grandfathered the baseline accordingly. `tests/Postgres/ItemTombstoneBackfillTest.php`
>   already reads real migration SQL off disk — copy that pattern rather than inventing one.
>   ⚠️ **Do NOT regenerate `scripts/launch-check/schema-drift-baseline.json` or
>   `no-local-canonical-ddl-baseline.json`.** If a gate goes red, stop and ask.
>
> All three are cheap greps. **None of them prevents a bug that can happen today.** Do them if you have the
> files open; otherwise closing them with that reasoning is defensible.
>
> ---
>
> ## Item 4 — `SEC-4`: WONTFIX candidate, argument below
>
> **File:** `app/Http/Controllers/Api/Platforms/ShopController.php` — `setProducts()`'s raw
> `ShopProduct::query()->insert($rows)` has no `$fillable` gate.
>
> 🔴 **The finding's own Technical note concedes today's code is safe:** `$rows` is a hand-written 7-key
> literal (`id`, `brand_id`, `product_id`, `position`, `data`, `created_at`, `updated_at`), and
> `$productData` contributes only `$productData['productId']` plus `json_encode($productData)` into the
> `data` JSONB column — which is that column's intended contents. **There is no live over-post path.** The
> risk is purely structural: a *future* edit that spreads `$productData` would have no gate.
>
> 🔴 **And the prescribed fix is a tautology.** "Validate the keys in each `$rows[]` entry against an
> explicit allowlist immediately before the insert" — the keys are written as literals three lines above.
> An `array_intersect_key` against a hand-copied allowlist can never remove anything, can never fail, and
> cannot be meaningfully tested. Worse, a silently-dropping filter is a debugging trap for exactly the
> future developer it protects: they spread `$productData`, columns vanish, no signal.
>
> **Two acceptable outcomes. Pick one and argue it:**
> - **WONTFIX** — record that the finding is structural-only and its prescription untestable.
> - **A better-shaped 12-line fix**, if you judge it earns its keep: a static
>   `ShopProduct::assertRawInsertColumns(array $rows): void` on the **model** (which owns `$fillable`, so
>   the knowledge sits next to its source). Allowed set = `array_merge(['id','created_at','updated_at'],
>   (new self)->getFillable())` — **derived, not hand-copied**, so a `$fillable` change flows through. Throw
>   `LogicException` naming the offending keys. **Fail on over-post only, never on under-post** — a column
>   added to `$fillable` but not written here must not turn a green deploy red.
>
> ⚠️ Whatever you do, these must stay green and unchanged:
> `tests/Feature/Platforms/ShopSelectionLockTest.php`'s *"bulk-inserts the selection in a single INSERT
> statement"* and *"a 250-product selection round-trips correctly"* — the second proves the real hot path
> at max size still passes.
>
> ---
>
> ## Item 5 — `INH-6`: partial only, and **do not tick it**
>
> **Source:** `audits/consolidation/2026-07-25-backend-inheritance/CONSOLIDATED.md` (effort M, ~2–4h).
> The finding has **three** parts:
>
> | Part | State |
> |---|---|
> | `normalizeName` / `norm` — 3 copies | ✅ **Safe to consolidate.** Re-verified byte-identical 2026-07-30 |
> | `cleanString` — **6** files, not 4 | ❌ **NOT a safe refactor** |
> | `nextPosition` — 2 files | ❌ untouched |
>
> The three `normalizeName` bodies (`MenuContentController`, `MenuFetchJob`, and `MenuMerger::norm`) were
> confirmed byte-identical apart from the method name, so that third **is** a mechanical move.
> ⚠️ **Re-confirm byte-identity before touching it. If they have drifted, STOP and escalate** — that would
> be a live menu-matching bug (a suppressed dish reappearing at rebuild), not a refactor.
>
> 🔴 **Do NOT put it in `NormalizesMenuData`,** despite what the finding says. Adding that trait to the
> three classes drags in ~11 **unused** private methods (all three drivers currently use all four of its
> methods; these classes use none), and `phpstan-baseline.neon` contains **zero** unused-private-method
> entries — so `composer analyse` would likely go red on new code. It would also **silently shadow**
> `MenuContentController::cleanString(?string)`, which has a different signature *and* body from the
> trait's `cleanString(mixed)`; PHP resolves the class method over the trait's with no error and no
> signature check. Use a new single-purpose trait instead, e.g.
> `app/Services/Platforms/NormalizesMenuItemNames.php`.
>
> ⚠️ **`MenuMerger::norm` has a different name**, so consolidating means renaming ~10 call expressions.
> **Anchor the search on `$this->norm(`, never on `norm`** — several lines assign to a local `$norm`
> variable, and a broad replace would corrupt them into `$normalizeName`.
>
> 🔴 **Do not tick `#INH-6`.** Ticking it on one-third of the work would move the inheritance audit's
> `P1 High` to `1 of 2` dishonestly. Leave it unticked and record the two remaining halves — noting that
> the `cleanString` half is a **behaviour question, not a move**, since its copies genuinely differ.
>
> ---
>
> ## Item 6 — Unit 6: drills, k6, `LC-RERUN`  ← DEFER, or do it deliberately
>
> Source: `audits/launch-check/2026-07-26/REPORT.md`. **Not code.** Hours of hands-on operational work.
> Valuable before real traffic; premature at zero users. **Recommend deferring until closer to pilot** —
> but if you do it:
>
> - Run the three drills from `docs/runbooks/drills/` on the **LOCAL** stack only, logging to
>   `docs/runbooks/drills/logs/`.
> - Run k6 baseline (10 VU / 5 min) + public-handle spike (50–100 VU) **against dev ONLY**, watching edge
>   cache-hit ratio, Supavisor headroom and p95.
> - ⚠️ **Never run `LC-K6` in the same window as `LC-NIGHTWATCH`** — both target dev and the load run
>   drowns the alert signal.
> - ⚠️ **Check no other session is using dev first.** Several have been active daily.
> - ⚠️ The k6 harness hard-codes **three real invariants** that have silently broken it before: gallery
>   capped at 6/site (`core.enforce_site_gallery_max6`), a gallery item needs a matching
>   `site.media_variants` (webp) row or its URL resolves empty, and analytics writes need an
>   `Origin`/`Referer` matching the site's subdomain (SEC-1). **If a seed fails, check those three before
>   debugging the harness.**
> - `LC-RERUN` is a **process change, not code**: document re-running launch-check after every migration
>   push and before every promote.
>
> ---
>
> ## Item 7 — `#38`: close as untraceable
>
> 🔴 **`#38` does not exist as a checkbox anywhere in `audits/`.** Verified 2026-07-30:
> `grep -rlnE "^\- \[[ x]\] \*\*#?38\*\*" audits/` returns nothing. The 07-11 sweep — cited as its source —
> uses **prefixed** ids (`#API-10`, `#DINT-3`, `#TEST-9`); bare numbers are not its scheme.
>
> Search the source audits for a migration-invariant finding that matches, and either correct the
> consolidation's reference to the real id, or **tick `#38` closed as untraceable**. `#3` (Tier 2, item 5)
> has the same defect. **Report both as a bookkeeping defect in the consolidation** — an execution prompt
> naming ids that do not exist sends the next session hunting. Two of the 34 are phantoms.
>
> ---
>
> ### Step 3 — before every commit
>
> 1. Re-run the Step 1 pre-flight. If a sibling's file set intersects yours, stop and reconcile.
> 2. `composer test` green. **Baseline on `4e221ca7` is `1 warning, 42 skipped, 6901 passed`, exit 0.**
> 3. For item 1 **also** run `composer test:schema` — that lane is not in `composer test`.
>    For `#TEST-41` **also** run `composer test:pg`.
> 4. `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse --memory-limit=1G` on changed files
>    (⚠️ default 128M crashes phpstan here). Run `analyse` specifically after any trait change.
> 5. Tick in **both** `CONSOLIDATED.md` and the source audit; bump the source's nearest preceding
>    `## Progress` block. **For a WONTFIX, tick it and record the argument in the source file** — that is
>    the whole point of this tier.
> 6. Commit code + ticked files **together**: `fix(audit): tier3 — <ids>`.
>
> ⚠️ Unnamespaced Pest files share a **GLOBAL symbol table** — prefix helpers uniquely, grep to confirm.
> ⚠️ **Tests run SQLite; production is Postgres.** `Tests\TestCase::setUp()` repoints `pgsql` at in-memory
> SQLite **unconditionally** — `DB::connection('pgsql')` is **not** Postgres in a test. Never claim it is.
>
> ---
>
> ### Final report
>
> Split the outcome three ways — **FIXED / DEAD / WONTFIX** — so the count is honest, and give the argument
> for every WONTFIX. Then state:
> - whether `CLAUDE.md:228`'s pinning claim was corrected, and what actually pins the rule now
> - whether `site.themes` was still unasserted when you checked, and the `composer test:schema` result
> - your ruling on `SEC-4` and why
> - confirmation `#INH-6` is **still unticked**, with the two deferred halves named
> - whether `#38` resolved to a real id or closed untraceable
> - anything you closed WONTFIX that you think Josh would disagree with — **flag it rather than burying it**
>
> 🔴 **Do not merge, and do not push to `development` or `production`.** Josh sequences the branches.
> Whichever merges second must run the **full suite on the merged result**.
