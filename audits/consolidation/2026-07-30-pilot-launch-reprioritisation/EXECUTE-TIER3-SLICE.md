# Execute prompt — Tier 3 **triage slice** (the recommended subset of `EXECUTE-TIER3.md`)

> ✅ **EXECUTED 2026-07-31** on `audit-fix/tier3-slice-2026-07-31`. Kept as the record of what was asked
> for and where the ask was wrong. **Two corrections found in execution, both recorded in
> `CONSOLIDATED.md`:**
> 1. **Item 7's premise was stale.** `#38`/`#3` are *not* untraceable — Tier 2 had already resolved them
>    (commit `499c13ef`) as `#DINT-4` and `#SCHEMA-8` with their prefixes stripped. Both are real, both
>    are still open, and both need a migration. This file inherited the "verified phantom" claim from
>    `EXECUTE-TIER3.md` without re-checking it — exactly the ~40% stale rate the tier warns about, landing
>    on the file whose whole job was to warn about it.
> 2. **The id-collision defect is real and is a different, worse variant** than the stripped-prefix one:
>    `#TEST-9` and `#SEC-4` resolve to real-but-wrong findings in the 07-11 sweep, one already closed.
>    A stripped prefix resolves to nothing and stops you; a collision resolves to something plausible.
>
> Everything below is as originally written. Do not re-run it.

Paste the block below into a **fresh Claude Code session** at the repo root.

This is the disposition-shaped subset of [`EXECUTE-TIER3.md`](./EXECUTE-TIER3.md), which says of itself
that *"the recommended use of this file is triage, not execution."* This file **is** that triage: two
real fixes, five recorded closes, one deferral. It is not a backlog campaign, and `CLAUDE.md` forbids
turning it into one.

**Do not run `EXECUTE-TIER3.md` and this file both.** This one supersedes it. Tier 1 (`971adaf4` +
`8b1d78d4`) and Tier 2 (`499c13ef`) are merged; base is `development` @ `ce4ea4b5` or later.

## State verified 2026-07-31 @ `ce4ea4b5` — all seven items still reproduce

| # | Item | Verified | Disposition |
|---|---|---|---|
| 1 | `site.themes` guard + false `CLAUDE.md:228` claim | `git grep site\.themes -- tests/` → nothing | **FIX** |
| 2 | Worker CSP hardcoding | literal at `cloudflare-worker/src/index.js:156`, `:311`; `PARTNA_DOMAIN` const at `:46` | **FIX** |
| 3 | `#TEST-49` / `#TEST-50` / `#TEST-41` | no guards; 3 + 6 inline DDL statements respectively | **OPPORTUNISTIC close** |
| 4 | `#SEC-4` raw insert | reproduces at `ShopController.php:709`; `$rows` still a 7-key literal | **WONTFIX** |
| 5 | `#INH-6` `normalizeName` ×3 | all three still present | **leave `[ ]`**, record halves |
| 6 | 3 drills + `LC-K6` + `LC-RERUN` | only `04-backup-restore` has a log | **DEFER**, record |
| 7 | `#38` (and `#3`) | no such checkbox anywhere in `audits/` | **close untraceable** |

## 🔴 ID integrity — cite lines, not ids

Finding ids are **per-sweep and collide across sweeps**. `EXECUTE-TIER3.md`'s own references are wrong in
two places, on top of the two phantoms it already flags. Verified 2026-07-31:

| Item | ❌ Do NOT tick | ✅ The real box |
|---|---|---|
| themes guard | `#TEST-9` in the **07-11** sweep — that is *"no forget test for `nowbookit`"*, P3, unrelated. The 07-11 sweep has **no** themes finding (`grep site\.themes` → nothing). 07-28's `#TEST-9` is the Lander guard, already `[x]`. | **`271-TEST-1`** — `audits/sweeps/2026-07-24-pr270-pr271-actions-and-slugs/CONSOLIDATED.md:1745` |
| raw insert | `#SEC-4` in the **07-11** sweep — that is `StaffUserController::index()`, already `[x]` at `CONSOLIDATED.md:3641` | **`#SEC-4`** — `audits/sweeps/2026-07-28-content-platform-rebuild/CONSOLIDATED.md:206` + `audit-2026-07-28-security.md:112` |

Every other box, verified:

- `#TEST-49` — `…/2026-07-28-content-platform-rebuild/CONSOLIDATED.md:5460` + `audit-2026-07-28-test-coverage.md:681`
- `#TEST-50` — same files, `:5465` / `:686`
- `#TEST-41` — `…/2026-07-28-content-platform-rebuild/CONSOLIDATED.md:5386`
- `#INH-6` — `audits/consolidation/2026-07-25-backend-inheritance/CONSOLIDATED.md:59` — **leave unticked**
- `LC-DRILL-*`, `LC-K6`, `LC-RERUN` — this consolidation's `CONSOLIDATED.md:805-806`
- **Worker CSP has no checkbox at all.** `#10` is already `[x]` (`:806`); the CSP residual was split out and
  lives only as open follow-up **#3** in the prose at `CONSOLIDATED.md:902`. Close it there.

---

## The prompt

> Work the **Tier 3 triage slice** below. Follow `scripts/audit/fix-flow.md`; models from the
> `## Execution policy` header of
> `audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md`.
>
> **Two implementations, five recorded closes, one deferral.** That ratio is the point, not a shortfall.
> `CLAUDE.md` forbids clearing the P3 tail as a campaign: recall degrades past ~100K tokens and the tail
> carries a measured ~40% already-fixed rate. **A tick means "resolved as an open question", not "the code
> changed."** Closing with a stated reason is a successful outcome; leaving a box open forever is not —
> it blocks `archive-done.sh` and makes the audit system read permanently red.
>
> **Do not invent work to justify a ticket. Do not widen scope beyond the seven items.**
>
> ---
>
> ### Step 0 — isolated worktree (REQUIRED)
>
> ```bash
> git fetch origin
> git worktree add -b audit-fix/tier3-slice-2026-07-31 \
>   "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-tier3-slice-2026-07-31" \
>   origin/development
> cd "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-tier3-slice-2026-07-31"
> composer install
> cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env      # REAL copy, never a symlink
>
> git merge-base --is-ancestor 499c13ef HEAD && echo "BASE OK" || echo "STOP: base predates Tier 2"
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
> ### Step 2 — verify every premise before planning
>
> The state table in this file was verified at `ce4ea4b5`. **Re-verify at your base** — if an item no
> longer reproduces, close it **DEAD** with a one-line reason and move on. That is a win, not a miss.
>
> 🔴 **Cite boxes by the file:line table in this document, never by bare id.** Finding ids are per-sweep
> and collide: the 07-11 sweep's `#TEST-9` and `#SEC-4` are *different, unrelated findings* from the ones
> in scope here, and one of them is already closed. Ticking them would corrupt two audits.
>
> ---
>
> ## Item 1 — `site.themes` guard, and the false pinning claim  ← FIX
>
> **Box:** `271-TEST-1` · P2 — `audits/sweeps/2026-07-24-pr270-pr271-actions-and-slugs/CONSOLIDATED.md:1745`
>
> Half is already closed upstream: P0-LAUNCH moved `ArchitectureSystemConstraintsTest` out of
> `tests/Feature/Database/` into `tests/Schema/` and into the real applied-schema lane
> (`phpunit.schema.xml` / `composer test:schema`), against a container the real `supabase/migrations/` set
> has been applied to. Before that move the file **ran nowhere for its entire life** — it sat in
> `tests/Feature` gating every assertion on a Postgres check, while `Tests\TestCase::setUp()` repoints
> `pgsql` at in-memory SQLite unconditionally, so every assertion skipped silently in every lane.
>
> **Still open:** nothing asserts `site.themes` stays dropped. Confirm `git grep -n "site\.themes" -- tests/`
> is still empty, then add to `tests/Schema/ArchitectureSystemConstraintsTest.php` (no concurrent owner —
> P0-LAUNCH merged and its worktree is gone) assertions against `information_schema.tables` and `pg_proc`
> that **`site.themes` and `set_default_theme_for_site()` are absent**.
>
> ⚠️ **`composer test:schema` is NOT part of `composer test`.** A green `composer test` proves nothing
> here. Run the schema lane explicitly and quote its output.
>
> 🔴 **The documentation fix is the more valuable half.** `CLAUDE.md:228` claims:
> > *"Adding a second architecture is a **platform decision, not a task** … Pinned by
> > `ArchitectureSystemConstraintsTest`."*
>
> False as written — that test asserts the `architecture_id` CHECK, the `design_kits` CASCADE FK and the
> `trg_create_empty_design_kit` trigger, and **nothing about themes**. Correct the claim so it names what
> actually pins the rule **and the lane it runs in** (`composer test:schema`, wired at
> `.github/workflows/ci.yml:510`). Keep it to one line — `CLAUDE.md` is terse by design.
> Documentation claiming coverage it lacks is worse than silence: it is exactly what stopped the guard
> being written.
>
> ---
>
> ## Item 2 — Worker CSP hardcoding  ← FIX
>
> **No checkbox.** Recorded as open follow-up **#3** in the prose at this consolidation's
> `CONSOLIDATED.md:902`. Split out of `#10` deliberately during P1-LAUNCH (`#10` itself closed — its
> `unclaimedHtml()` half already derives every domain from `${PARTNA_DOMAIN}`).
>
> **File:** `cloudflare-worker/src/index.js`
> - `https://app.partna.au` hardcoded in two CSP `frame-ancestors` headers — `:156` (shared hardening) and
>   `:311` (sitepage response). On a non-prod Worker deploy a staging dashboard origin is refused framing.
> - `PARTNA_DOMAIN` is itself `const PARTNA_DOMAIN = "partna.au"` at `:46`, **not** read from `env` — so
>   "derived from environment" is not literally true anywhere in the file.
>
> ⚠️ **This is the public edge.** Every `<handle>.partna.au` request passes through this Worker. A CSP
> mistake either breaks framing for the real dashboard or loosens a real protection. Get the header byte-
> exact and state how you verified it.
> ⚠️ **`SyncSubdomainToKvJob` is the ONLY KV writer** — do not introduce another.
> ⚠️ Worker test harness traps: `outboundService` + `redirect: "manual"` are what stop tests hitting **real
> production**, Miniflare 4 dropped `res.waitUntil()`, Node 22 only. Read the harness before writing a test.
>
> Record the close in the `CONSOLIDATED.md:902` follow-up entry — it has no box to tick.
>
> ---
>
> ## Item 3 — `#TEST-49`, `#TEST-50`, `#TEST-41`  ← OPPORTUNISTIC close
>
> - `#TEST-49` — `…/2026-07-28-content-platform-rebuild/CONSOLIDATED.md:5460` + `audit-2026-07-28-test-coverage.md:681`
> - `#TEST-50` — same files, `:5465` / `:686`
> - `#TEST-41` — `…/2026-07-28-content-platform-rebuild/CONSOLIDATED.md:5386`
>
> All three are cheap greps and **none prevents a bug that can happen today**. Tick all three
> **OPPORTUNISTIC** per `BACKLOG-TRIAGE.md` — the standing `CLAUDE.md` rule ("when you have the file open
> for real work, fix it in the same commit") is what carries them forward, not the checkbox.
>
> **Exception — you are already in `tests/Schema/` for item 1.** If `#TEST-49`'s
> `detectors_surface_xor_signal` CHECK guard drops into that same file in under ~15 lines, write it and
> tick it **FIXED** instead. Do not go looking for a second file to justify it.
>
> ⚠️ `#TEST-50` asserts the **absence** of a unique index on `content.identity_keys(key_class, key_value)`.
> If you ever write it, it needs a comment explaining *why* the absence is correct, or the next developer
> deletes the guard instead of respecting it. Record that in the tick reason so the knowledge survives.
>
> ⚠️ `#TEST-41` (inline DDL in `tests/Postgres/BrandAssetPipelineTest.php` and
> `CatalogSyncIdempotenceTest.php`) — **do not attempt it in this session.** P0-LAUNCH widened the
> schema-drift gate to *see* inline DDL in test files and re-grandfathered the baseline; touching those
> files risks a red gate whose only quick fix is forbidden. **Do NOT regenerate
> `scripts/launch-check/schema-drift-baseline.json` or `no-local-canonical-ddl-baseline.json`.** Tick
> OPPORTUNISTIC and name `tests/Postgres/ItemTombstoneBackfillTest.php` in the reason as the pattern to
> copy when someone does have those files open.
>
> ---
>
> ## Item 4 — `#SEC-4` raw insert  ← WONTFIX
>
> **Box:** `audits/sweeps/2026-07-28-content-platform-rebuild/CONSOLIDATED.md:206` +
> `audit-2026-07-28-security.md:112`. **Not** the 07-11 `#SEC-4`.
>
> `app/Http/Controllers/Api/Platforms/ShopController.php:709` — `ShopProduct::query()->insert($rows)` has
> no `$fillable` gate.
>
> 🔴 **The finding's own Technical note concedes today's code is safe:** `$rows` is a hand-written 7-key
> literal (`id`, `brand_id`, `product_id`, `position`, `data`, `created_at`, `updated_at`), and
> `$productData` contributes only `$productData['productId']` plus `json_encode($productData)` into the
> `data` JSONB column — that column's intended contents. **No live over-post path exists.** The risk is
> structural: a *future* edit that spreads `$productData` would have no gate. The raw insert is a
> deliberate, documented performance trade-off (250 products × Supavisor round-trips inside a 10s lock).
>
> 🔴 **And the prescribed fix is a tautology.** "Validate the keys in each `$rows[]` entry against an
> explicit allowlist immediately before the insert" — the keys are written as literals three lines above.
> An `array_intersect_key` against a hand-copied allowlist can never remove anything, can never fail, and
> cannot be meaningfully tested. Worse, a silently-dropping filter is a debugging trap for exactly the
> future developer it is meant to protect: they spread `$productData`, columns vanish, no signal.
>
> **Verify both premises against the current file, then tick WONTFIX** with the argument recorded in
> `audit-2026-07-28-security.md` — that the finding is structural-only and its prescription untestable.
>
> **If and only if** re-verification shows `$rows` is no longer a hand-written literal, stop and escalate:
> that is a different, live finding and it does not belong in this slice.
>
> ⚠️ Change nothing in `ShopController` — these must stay green and untouched:
> `tests/Feature/Platforms/ShopSelectionLockTest.php`'s *"bulk-inserts the selection in a single INSERT
> statement"* and *"a 250-product selection round-trips correctly"*.
>
> ---
>
> ## Item 5 — `#INH-6`  ← record only, **do not tick, do not refactor**
>
> **Box:** `audits/consolidation/2026-07-25-backend-inheritance/CONSOLIDATED.md:59` — leave it `[ ]`.
>
> Three parts, one of which is safe and two of which are not:
>
> | Part | State |
> |---|---|
> | `normalizeName` / `norm` — 3 copies (`MenuContentController`, `MenuFetchJob`, `MenuMerger::norm`) | ✅ safe to consolidate, byte-identical as of 2026-07-30 |
> | `cleanString` — **6** files, not 4 | ❌ copies genuinely differ — a behaviour question, not a move |
> | `nextPosition` — 2 files | ❌ untouched |
>
> **Do not do the refactor in this slice.** It is effort M (~2–4h), it is the only item here that changes
> live menu-matching behaviour, and it carries three traps that make it a bad passenger on a triage
> commit. Instead, **append a note to the finding** recording, for whoever does pick it up:
>
> - 🔴 Re-confirm byte-identity first. **If the three have drifted, that is a live menu-matching bug** (a
>   suppressed dish reappearing at rebuild), not a refactor — escalate, do not merge them.
> - 🔴 **Do NOT use `NormalizesMenuData`**, despite what the finding says. It drags ~11 unused private
>   methods into the three classes; `phpstan-baseline.neon` has **zero** unused-private-method entries, so
>   `composer analyse` would likely go red. It would also **silently shadow**
>   `MenuContentController::cleanString(?string)`, which differs from the trait's `cleanString(mixed)` in
>   both signature and body — PHP resolves the class method over the trait's with no error and no
>   signature check. Use a new single-purpose trait, e.g.
>   `app/Services/Platforms/NormalizesMenuItemNames.php`.
> - ⚠️ `MenuMerger::norm` has a different name, so ~10 call expressions get renamed. **Anchor on
>   `$this->norm(`, never on `norm`** — several lines assign to a local `$norm`, and a broad replace
>   corrupts them into `$normalizeName`.
>
> 🔴 **Leave `#INH-6` unticked.** Ticking on one-third of the work would move the inheritance audit's
> `P1 High` to `1 of 2` dishonestly.
>
> ---
>
> ## Item 6 — drills, `LC-K6`, `LC-RERUN`  ← DEFER with a recorded trigger
>
> **Boxes:** this consolidation's `CONSOLIDATED.md:805-806`. **Leave them `[ ]`.**
>
> Not code. Hours of hands-on operational work, valuable before real traffic, premature at zero users.
> Verified: only `docs/runbooks/drills/logs/2026-07-26-backup-restore.md` exists; drills `01-worker-kill`,
> `02-vendor-outage`, `03-redis-down` have never been run.
>
> **Your only job here is to record the deferral and its trigger** in the consolidation: *"deferred until
> first real pilot traffic is scheduled."* Do not run drills or k6 in this session.
>
> If Josh later asks for them: local stack only for drills, logging to `docs/runbooks/drills/logs/`; k6
> against **dev only**; ⚠️ never in the same window as `LC-NIGHTWATCH` (the load run drowns the alert
> signal); ⚠️ check no other session is on dev first; ⚠️ if a k6 seed fails, check the three hard-coded
> invariants before debugging the harness (gallery capped at 6/site by `core.enforce_site_gallery_max6`; a
> gallery item needs a matching `site.media_variants` webp row or its URL resolves empty; analytics writes
> need an `Origin`/`Referer` matching the site's subdomain, SEC-1). `LC-RERUN` is a process change:
> document re-running launch-check after every migration push and before every promote.
>
> ---
>
> ## Item 7 — `#38` and `#3`: close untraceable, and report the defect  ← CLOSE
>
> 🔴 **Neither exists as a checkbox anywhere in `audits/`.** Verified 2026-07-31 —
> `grep -rnE '^\- \[[ x]\] \*\*#?38\*\*' audits/` and the same for `#3` both return nothing. The 07-11
> sweep, cited as their source, uses **prefixed** ids (`#API-10`, `#DINT-3`, `#TEST-9`); bare numbers are
> not its scheme.
>
> Search the source audits once for a migration-invariant finding that matches. If you find one, correct
> the consolidation's reference to the real id. Otherwise record both as **untraceable**.
>
> **Then report the wider bookkeeping defect**, which is worse than two phantoms: **finding ids are
> per-sweep and collide across sweeps**, and this consolidation mis-cites at least two —
> `#TEST-9` (the themes guard does not exist in the 07-11 sweep at all; that sweep's `#TEST-9` is an
> unrelated `nowbookit` test) and `#SEC-4` (07-11's is `StaffUserController`, already closed). Add a
> short note to `CONSOLIDATED.md` stating that cross-sweep ids must be cited as `<sweep>/<file>:<line>`,
> not bare. An execution prompt naming ids that do not exist — or that resolve to the *wrong* finding —
> sends the next session hunting, or silently corrupts a different audit.
>
> ---
>
> ### Step 3 — before every commit
>
> 1. Re-run the Step 1 pre-flight. If a sibling worktree's file set intersects yours, stop and reconcile.
> 2. `composer test` green. Compare against a baseline you measure on your own base commit — do not trust
>    a pasted count. Use `COMPOSER_PROCESS_TIMEOUT=0`.
> 3. **For item 1 also run `composer test:schema`** — that lane is not in `composer test`. Quote its output.
> 4. `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse --memory-limit=1G` on changed files
>    (⚠️ the default 128M crashes phpstan here).
> 5. Tick in **both** `CONSOLIDATED.md` and the source audit, using the file:line table above. Bump the
>    source's nearest preceding `## Progress` block. Tick format, per `BACKLOG-TRIAGE.md`:
>    `- [x] **#SEC-4** · P2 — … · **WONTFIX (tier3 triage 2026-07-31): <reason>**`
> 6. Commit code + ticked audit files **together**: `fix(audit): tier3-slice — <ids>`.
>
> ⚠️ Unnamespaced Pest files share a **GLOBAL symbol table** — prefix helpers uniquely, grep to confirm.
> ⚠️ **Tests run SQLite; production is Postgres.** `Tests\TestCase::setUp()` repoints `pgsql` at in-memory
> SQLite **unconditionally** — `DB::connection('pgsql')` is **not** Postgres in a `tests/Feature` test.
> The `tests/Schema/` lane is the exception: it runs against a real container. Never conflate them.
>
> ---
>
> ### Final report
>
> Split the outcome **FIXED / DEAD / WONTFIX / OPPORTUNISTIC / DEFERRED** so the count is honest. Then state:
>
> - whether `site.themes` was still unasserted when you checked, and the **`composer test:schema` result**
> - the exact corrected text of `CLAUDE.md:228`, and what now genuinely pins the rule
> - the CSP header before and after, and how you verified it does not break real dashboard framing
> - your `#SEC-4` ruling and whether both premises (7-key literal, untestable prescription) still hold
> - confirmation `#INH-6` is **still unticked**, with the two deferred halves named
> - whether `#38`/`#3` resolved to real ids or closed untraceable, and that the cross-sweep id-collision
>   note landed in `CONSOLIDATED.md`
> - anything you closed WONTFIX or OPPORTUNISTIC that you think Josh would disagree with — **flag it
>   rather than burying it**
>
> 🔴 **Do not merge, and do not push to `development` or `production`.** Josh sequences the branches.
