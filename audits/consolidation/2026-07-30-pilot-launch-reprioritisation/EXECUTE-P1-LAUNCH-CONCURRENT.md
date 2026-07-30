# Execute prompt — P1-LAUNCH, concurrent with a live P0-LAUNCH session

Paste the block below into a **fresh Claude Code session** at the repo root.

This is Prompt 4 of `CONSOLIDATED.md`, re-scoped to run **alongside** the P0-LAUNCH session on
`audit-fix/p0-launch-2026-07-30`, and updated for what P1-PILOT closed when it merged at `91f8064c`.

**Changes from the base prompt — read these before pasting:**

| Change | Why |
|---|---|
| `#9` **stays in scope** | An earlier prediction that `PRIV-3` would close it was **wrong**, and the P1-PILOT session corrected it in `CONSOLIDATED.md`. See the note in Step 2. |
| `#10` needs verification first | P1-PILOT logged it "partial". Likely closed as written; confirm, don't assume. |
| `#TEST-41` deferred | Collides head-on with P0-LAUNCH's `#TEST-1`/`#TEST-2`. |
| **`TEST-9` half-closed and now contested** | *(added 2026-07-30, second revision)* P0-LAUNCH **renamed** its target file into a directory this prompt forbids. See the new "TEST-9 is contested" section — this changes Unit 4's shape. |
| **Ownership table widened** | *(added 2026-07-30, second revision)* The original table was written before P0-LAUNCH explored. Six more paths are now theirs, including two **renames**. |
| 7 promoted findings added | From `BACKLOG-TRIAGE.md`, grouped into two new units. |
| Bucket **34 findings, 33 in scope** | `#TEST-41` deferred to after P0-LAUNCH merges. |

**Observed P0-LAUNCH state when this revision was written (2026-07-30):** branch at `4a8b1e66`, worktree
**clean** — everything committed, nothing loose on disk. Commits: `bcb823bb` (migration CHECK split),
`b326817c` (`#TEST-2`, `#TEST-1`, `271-PARITY-1`), `4a8b1e66` (merge of `origin/development`).
This is a snapshot, not a guarantee — re-run the Step 1 pre-flight yourself.

---

## The prompt

> Work the **P1-LAUNCH** bucket of
> `audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md`.
>
> Follow `scripts/audit/fix-flow.md` exactly — plan → implement → **independent** review per unit, models
> from that file's `## Execution policy` header. Do not invent a different flow.
>
> 🔴 **A second Claude session is running RIGHT NOW** on `audit-fix/p0-launch-2026-07-30`. The file
> ownership below is load-bearing. Read the whole prompt before touching anything.
>
> This is the largest bucket and the least urgent — **it is also the one most likely to contain findings
> that are already done.** Verify every premise before planning; expect casualties and treat them as a
> good outcome. Equally, do **not** assume a finding is dead because something adjacent shipped: one
> such prediction about `#9` was already made and proved wrong.
>
> **Scope — 33 findings in 8 units.** Work units sequentially.
>
> | Unit | IDs | Gate | Theme |
> |---|---|---|---|
> | 1 | `SCALE-13/14/17/19/20` + `CACHE-1/2/3` | — | unbounded `whereIn`, per-row INSERT loops |
> | 2 | `DINT-1` + `271-PRIV-1` + `#3` | **migration** | missing indexes + unbounded retention |
> | 3 | `SCALE-11` | **standalone / GDPR** | `SiteMedia` force-delete storage I/O |
> | 4 | `TEST-9`/`271-TEST-1` + `TEST-49` + `TEST-50` + `#38` | **migration** | migration invariant guards |
> | 5 | `#9` + `#10` + `SEC-4` + `INH-6` | — | residuals — **verify `#9` and `#10` first** |
> | 6 | `LC-DRILL-worker-kill` + `LC-DRILL-vendor-outage` + `LC-DRILL-redis-down` + `LC-K6` + `LC-RERUN` | — | operational drills — **not code** |
> | 7 | `CFG-16` + `CFG-8` + `CFG-9` + `JOB-6` | — | incident & paid-API tunables *(promoted)* |
> | 8 | `SLOP-21` + `TEST-30` + `TEST-44` | — | silent-failure guards *(promoted)* |
>
> Source files: `SCALE-*`, `CACHE-*`, `DINT-1`(07-24), `SEC-4`, `TEST-49/50`, `CFG-*`, `JOB-6`,
> `SLOP-21`, `TEST-30`, `TEST-44` → `audits/sweeps/2026-07-28-content-platform-rebuild/CONSOLIDATED.md`.
> `271-PRIV-1`, `271-TEST-1` → `audits/sweeps/2026-07-24-pr270-pr271-actions-and-slugs/CONSOLIDATED.md`.
> `#3`, `#38`, `TEST-9` → `audits/sweeps/2026-07-11-full-work-sweep/CONSOLIDATED.md`.
> `INH-6` → `audits/consolidation/2026-07-25-backend-inheritance/CONSOLIDATED.md`.
> `LC-*` → `audits/launch-check/2026-07-26/REPORT.md`.
>
> ---
>
> ### Step 0 — isolated worktree (REQUIRED)
>
> ```bash
> git fetch origin
> git worktree add -b audit-fix/p1-launch-2026-07-30 \
>   "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p1-launch-2026-07-30" \
>   origin/development
> cd "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p1-launch-2026-07-30"
> composer install
> cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env
>
> # Base gate — you MUST be at 91f8064c or later. STOP if this prints an older ref.
> git log --oneline -1
> git merge-base --is-ancestor 91f8064c HEAD && echo "BASE OK" || echo "STOP: base predates the P1-PILOT merge"
>
> # Sanity gate — all must exist. STOP if any fail.
> ls app/Ingest/Runtime/EffectLedger.php app/Ingest/Landing/Lander.php
> ls app/Routing/LinkProjector.php app/Ingest/Projection/ProjectionWriter.php
> ls app/Models/Core/Site/SiteMedia.php docs/runbooks/drills/
> ```
>
> ⚠️ **Basing off `e4b9f573` is wrong** — that predates both the audit-triage merge (`c0088c9f`) and the
> P1-PILOT merge (`91f8064c`). Several of your findings were closed in the latter; on a stale base you
> will "fix" work that already exists.
>
> Own `composer install` and a **real copied** `.env` — never symlink either; that is the known cause of
> phantom feature-test failures. All commits land on `audit-fix/p1-launch-2026-07-30`.
> **Never run `git stash`** — another session shares this checkout's stash stack.
>
> ---
>
> ### Step 1 — concurrency pre-flight (before planning, and again before every commit)
>
> 🔴 **A branch-to-branch diff is NOT sufficient and will lie to you.** The P1-PILOT session's entire
> changeset sat as uncommitted working-tree state for hours — a `git diff` against its branch returned
> *empty* while fifteen files were modified on disk. Check the sibling **worktree**:
>
> ```bash
> git fetch origin
> git worktree list
>
> SIB="/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p0-launch-2026-07-30"
>
> git diff --name-only origin/development...audit-fix/p0-launch-2026-07-30 2>/dev/null | sort   # committed
> git -C "$SIB" status --short                                                                   # uncommitted ← matters most
> ```
>
> The union is live enemy territory. Re-run **both** before each commit — their file set grows as they
> advance. You may read that worktree; **never** run a mutating git command against it.
>
> ---
>
> ### File ownership — P0-LAUNCH owns these. Do NOT edit them.
>
> Verified against `git diff --name-status origin/development...audit-fix/p0-launch-2026-07-30` on
> 2026-07-30. **The six ⊕ rows were missing from the first revision of this prompt.** Two of the entries
> are *renames* — a path grep on your own branch will not reveal those, because the old path still exists
> for you and is gone for them.
>
> | Path | Their finding | On their branch |
> |---|---|---|
> | `.github/workflows/ci.yml` | `#TEST-2` | — |
> | `tests/Pest.php` | `271-PARITY-1` | M |
> | `tests/Schema/**` | `#TEST-2` | M + 2 renames **in** + 1 add |
> | `tests/Feature/Database/**` | `#TEST-2`, `#TEST-1` | M + 2 renames **out** + 1 delete |
> | **`tests/Postgres/**`** | `#TEST-1` — **contested, see the exclusion below** | — |
> | `scripts/launch-check/schema-drift-baseline.json` + gate scripts | `271-PARITY-1`, `#TEST-1` | M |
> | ⊕ `scripts/launch-check/no-local-canonical-ddl-baseline.json` | `#TEST-1` | M |
> | ⊕ `tests/Feature/Architecture/SchemaDriftGuardTest.php` | `#TEST-1` | M |
> | ⊕ `tests/Feature/Architecture/NoLocalCanonicalTableDdlTest.php` | `#TEST-1` | M |
> | ⊕ `tests/Support/SchemaDrift/**` | `#TEST-2` | A — `PestSetupHelpers.php` |
> | ⊕ `tests/Feature/Analytics/QueryPlanTest.php` | `#TEST-2` | M |
> | ⊕ `supabase/migrations/20260730090000_*` + `…090001_*` | their CHECK split (`bcb823bb`) | M + A |
> | `supabase/migrations/CONVENTIONS.md` | `LC-ROLLBACK` | — |
> | `supabase/migrations/*.sql` — **existing files** | `LC-ROLLBACK` revert comments | — |
> | `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php` | `#API-1` | — |
> | `app/Models/Core/Site/ShopBrand.php` | `#API-1` | — |
> | `app/Http/Controllers/Api/PublicSite/PublicMenuController.php` | `#50` | — |
> | `app/Http/Controllers/Api/Platforms/MenuController.php` | `#51`, their opportunistic companion | — |
>
> **The renames, explicitly** — all three land in `tests/Schema/`, theirs on both ends:
>
> ```
> R079  tests/Feature/Database/ArchitectureSystemConstraintsTest.php → tests/Schema/ArchitectureSystemConstraintsTest.php
> R077  tests/Feature/Database/IndexCoverageTest.php                 → tests/Schema/IndexCoverageTest.php
> A/D   tests/Feature/Database/UpdatedAtTriggerCoverageTest.php      → tests/Schema/UpdatedAtTriggerCoverageTest.php
> ```
>
> **Adjacent but yours:** `SEC-4` edits `app/Http/Controllers/Api/Platforms/ShopController.php`. They own
> `ShopBrand.php` and the public shop Resource — different files, same domain. Declare it in your report.
>
> **Expected conflict, not an alarm:** they also modify
> `audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md` — the same file you tick
> findings in. A textual conflict there at merge time is normal; resolve by keeping **both** sides' ticks.
>
> **If a fix seems to require one of their files, stop and ask.**
>
> ---
>
> ### 🔴 Exclusion — `#TEST-41` is OUT of scope for this run
>
> Do not touch `tests/Postgres/BrandAssetPipelineTest.php` or
> `tests/Postgres/CatalogSyncIdempotenceTest.php`.
>
> `#TEST-41` wants inline migration DDL *removed* from those two files. P0-LAUNCH's `#TEST-1`/`#TEST-2`
> are simultaneously widening the schema-drift gate so it can *see* inline DDL in test files, and
> re-grandfathering the baseline accordingly. Working both ends at once produces a baseline that
> describes neither state. **Leave `#TEST-41` unticked** and note it in your report as deferred-for-
> concurrency; it is a ~1h follow-up once P0-LAUNCH merges.
>
> ---
>
> ### 🔴 Contested — `TEST-9` is already HALF DONE, and its file is now theirs
>
> *(Added 2026-07-30, second revision. This was not in the first prompt and it changes Unit 4's shape.)*
>
> `TEST-9`/`271-TEST-1` has two halves. **P0-LAUNCH closed one of them and took ownership of the file.**
>
> | Half | State |
> |---|---|
> | "`ArchitectureSystemConstraintsTest` does not run in CI" | ✅ **CLOSED by P0-LAUNCH.** They renamed it `tests/Feature/Database/` → `tests/Schema/` (`R079`, commit `b326817c`) and moved it into the applied-schema lane (`phpunit.schema.xml` / `composer test:schema` / `Tests\SchemaTestCase`), which runs against a container the real `supabase/migrations/` set has been applied to. Their own file header documents it: *"THIS FILE RAN NOWHERE FOR ITS ENTIRE LIFE."* |
> | "it does not assert `site.themes` stays dropped" | ❌ **STILL OPEN.** `git grep -n 'site\.themes' audit-fix/p0-launch-2026-07-30 -- tests/` returns **nothing**. Their rewrite asserts three things — the `architecture_id` CHECK, the `design_kits` CASCADE FK, the `trg_create_empty_design_kit` trigger — and no theme assertion. |
>
> **The problem:** the natural home for the missing assertion is
> `tests/Schema/ArchitectureSystemConstraintsTest.php` — a file P0-LAUNCH created this week, and which
> sits under `tests/Schema/**` on the do-not-edit list. Its source path `tests/Feature/Database/**` is
> *also* on that list. **Both ends are theirs. Do not edit either.**
>
> **Do this instead — new file, no shared lines:**
>
> Write the assertion into a **new** file, `tests/Schema/LegacyThemeSystemRemovedTest.php`, using
> `uses(SchemaTestCase::class)->in(__FILE__);` exactly as their file does. A new file in a shared
> directory produces **no textual git conflict**; only editing their file would. Assert against
> `information_schema.tables` / `pg_proc` that `site.themes` and `set_default_theme_for_site()` are absent.
>
> ✅ **The schema lane already exists on `origin/development`** — verified 2026-07-30 at `91f8064c`:
> `phpunit.schema.xml`, `tests/SchemaTestCase.php`, and the `composer test:schema` script are all
> present on your base. P0-LAUNCH did **not** create the lane; it only moved tests *into* it. So
> `TEST-9` is **not** blocked — write the new file and run it with `composer test:schema`.
>
> **Still fully yours, unblocked:** the `CLAUDE.md` correction. `CLAUDE.md` claims the no-`site.themes`
> rule is "pinned by `ArchitectureSystemConstraintsTest`". Until your new file lands that claim is
> **false**, and it was false in a second way until this week (the test ran nowhere at all). Fix the claim
> and cite the file that actually pins it. `CLAUDE.md` is not on the ownership list.
>
> ---
>
> ### ⚠️ Unit 2 — your new indexes touch two P0-LAUNCH-owned gates
>
> *(Added 2026-07-30, second revision.)*
>
> `DINT-1` creates indexes on `analytics.action_events(user_id)` and `analytics.item_views(user_id)`.
> Two of their files would ordinarily want updating alongside:
>
> - **`tests/Schema/IndexCoverageTest.php`** (theirs, `R077`) enumerates expected indexes by name and
>   asserts each is present **and `indisvalid`**. Registering your two new indexes there is the house
>   pattern — **but it is their file.** Use a new `tests/Schema/AnalyticsUserIndexCoverageTest.php`, or
>   leave the registration to the follow-up and say so.
> - **`scripts/launch-check/schema-drift-baseline.json`** (theirs) is a grandfather list of
>   `check_missing:` / drift entries. A correctly-authored migration should not add entries to it — but
>   **if the drift gate goes red after your migration, do not regenerate that baseline.** Stop and ask.
>   Regenerating it would silently overwrite their in-flight re-grandfathering.
>
> ---
>
> ### 🔴 Migration rule — anything you create needs a revert comment
>
> Units 2 and 4 create new migration files (analytics indexes, `item_slugs.retired_at`, the
> `dining_modes` CHECK). P0-LAUNCH is concurrently establishing `-- to revert:` as a repo-wide
> convention across the existing 51 files. **Every migration you author must carry that comment**, or it
> lands as an immediate exception to a convention that shipped the same week.
>
> ⚠️ **One `CONCURRENTLY` statement per migration file, alone in that file.** The Supabase CLI pipelines
> multi-statement files and `CREATE INDEX CONCURRENTLY` cannot run in a pipeline (`SQLSTATE 25001`).
> `DINT-1` needs two indexes — that is two files.
>
> ---
>
> ### Step 2 — verify the premise (per unit, before planning)
>
> `VERIFICATION-LOG.md` carries 2026-07-30 evidence, but **P1-PILOT merged after it was written**. These
> four were re-checked on 2026-07-30 after that merge — confirm each yourself, then proceed:
>
> - **`#9` — STILL OPEN. Do not skip it.** A prediction that `PRIV-3` would close it was made and is
>   **wrong**; the P1-PILOT session corrected it in `CONSOLIDATED.md` after implementing `PRIV-3`.
>   What actually happened: `PRIV-3` closed the **erasure**-coverage half only. It deliberately did *not*
>   add a `streamEvidence()` export section, because `moderation.evidence`'s PII sits in a `payload` JSON
>   column and exporting it wholesale would leak third-party moderation context to the reported party.
>   `#9` is a different and narrower ask — surface **the subject's own** frozen `handle`/`display_name`
>   from their evidence snapshot in their DSAR. That remains unstarted.
>   ⚠️ The rationale comment now in `DataExportPayloadBuilder` explains the *third-party* exclusion. It
>   reads like a closed question and is not one. Do not let it talk you out of the finding.
> - **`#10` — verify first; likely closed as written.** `unclaimedHtml()` now uses `${PARTNA_DOMAIN}`,
>   which is exactly what the finding asked for, but P1-PILOT logged its commit as "`#10` partial". Read
>   the finding, check `cloudflare-worker/src/index.js`, and decide. If the remaining hardcoding is only
>   `https://app.partna.au` in the CSP headers, that is a **different** concern — tick `#10` DEAD and
>   raise the CSP one separately rather than silently widening scope.
> - **`CFG-16` — re-scope before planning.** P1-PILOT edited `app/Ingest/Runtime/SourceScheduler.php`,
>   one of its three target files. Check which constants still exist and where.
> - **`INH-6` — re-verify the drift question first.** As of 2026-07-30 all three `normalizeName`/`norm`
>   bodies still hash identically (`d5b98d91…`) *after* P1-PILOT edited `MenuFetchJob.php`, so this is
>   consolidation into the `NormalizesMenuData` trait, **not** a bug fix. **If they have since drifted,
>   stop and escalate** — that would be a live menu-matching bug (a suppressed dish reappearing at
>   rebuild), not a refactor.
>
> If any other finding no longer reproduces, tick it DEAD with a one-line note and move on. Deleting work
> is a successful outcome here.
>
> ---
>
> ### Blocker gate — pause for Josh's sign-off before implementing
>
> - **Unit 2** — creates DB indexes/migrations.
> - **Unit 3 (`SCALE-11`)** — listed **standalone** in the 07-28 sweep because it touches the GDPR
>   account-deletion data path. Isolate its review.
> - **Unit 4** — changes `supabase/migrations` invariants.
> - Units 1, 5, 6, 7, 8 proceed without asking.
>
> ---
>
> ### Per-unit notes
>
> **Unit 1** — pure scale work; none of it bites at pilot volume. Chunk unbounded `whereIn` arrays
> (~500/batch) and collapse per-row INSERT loops into multi-row inserts. **Measure before and after on
> one representative path** rather than fanning out on assumption.
>
> **Unit 4** — cheap, high-value grep-based invariant tests.
> 🔴 **`TEST-9`/`271-TEST-1` has changed since the first revision of this prompt — read the "Contested"
> section above before planning this unit.** The "does not run in CI" half is already closed by
> P0-LAUNCH; the `site.themes` half is open but its file is theirs. Net effect: this unit is **smaller
> than it reads**, and one of its two deliverables (the `CLAUDE.md` correction) is the unblocked part.
> `TEST-49`, `TEST-50` and `#38` are unaffected and proceed normally.
>
> **Unit 6 is not code.** Run the three drills from `docs/runbooks/drills/` on the **LOCAL** stack only,
> logging to `docs/runbooks/drills/logs/`. Run k6 baseline (10 VU/5 min) + public-handle spike
> (50–100 VU) **against dev only**, watching edge cache-hit ratio, Supavisor headroom, p95.
> ⚠️ The k6 harness hard-codes three real invariants — gallery capped at 6/site, gallery items needing a
> matching `site.media_variants` row, analytics writes needing a matching `Origin`/`Referer`. If a seed
> fails, check those before debugging the harness. `LC-RERUN` is a process change, not code.
>
> **Unit 7 (promoted)** — `CFG-16`/`CFG-8`/`CFG-9` extract ~5 constants to `config/partna.php`. These
> three were promoted out of a 19-item `CFG-*` group **specifically because they are incident and
> paid-API knobs** (ingest deletion sensitivity, billed-effect abandonment, scheduler fairness; Places
> retry/backoff; Apify timeout) — Places is the only uncapped paid external API in the project. Do not
> widen this into a general config-extraction pass; the other 15 are WONTFIX by decision.
> `JOB-6` bundles here because it is the same file as `CFG-16`'s `ABANDON_AFTER_SECONDS`: make
> `EffectLedger::once()` check `$e->getCode()` for a unique-violation before treating an insert failure
> as a digest collision, and re-throw otherwise. **Today it can silently skip a billed effect** — an
> actor run or AI extraction — with no log and no exception.
>
> **Unit 8 (promoted)** — three defences that currently fail silently or aren't actually tested:
> - `SLOP-21`: remove the `@` suppression on three `preg_match` calls in `LinkProjector`. A typo'd
>   catalog regex currently fails closed with no signal and links stop routing.
> - `TEST-30`: `SafeUrlFetcher` — Partna's own **SSRF boundary** — is `Mockery::mock()`ed out of
>   `tests/Feature/Platforms/ShopUrlValidationTest.php`, so the allowlist, DNS resolution and redirect
>   limits are absent from every assertion. Replace with `Http::fake()` using an RFC 2606 domain.
> - `TEST-44`: add an XXE regression test to `YoutubeFeed::parse()` — feed an XML payload with an
>   `ENTITY` declaration and assert it is never resolved. The defence is correct today; the test exists
>   to stop a future "fix" reopening it.
>
> ---
>
> ### Step 3 — before every commit
>
> 1. Re-run the Step 1 pre-flight. If their file list now intersects yours, stop and reconcile.
> 2. ~~`composer test` green in your worktree.~~ 🔴 **AMENDED 2026-07-30 — `composer test` CANNOT go
>    green on this base, and the cause is not yours.** `composer test` chains four guards before
>    `@php artisan test`; the third, `guard:no-unsafe-migrations`, rejects
>    `supabase/migrations/20260730090000_content_selection_ig_photo.sql` — *already merged to
>    `development`* — for `ADD CONSTRAINT … CHECK` without `NOT VALID`. The chain aborts there and
>    **Pest never runs at all**, so exit-1 here does not mean "tests failed". P0-LAUNCH already fixes
>    this at `bcb823bb`, in a file on the ownership list above.
>
>    **Gate on this instead** (Josh's call, 2026-07-30):
>    ```bash
>    php artisan config:clear --ansi && php artisan test        # the actual suite
>    php scripts/guard-no-unsafe-migrations.php                 # then read the violation list
>    ```
>    The guard **collects and prints every violation** rather than aborting on the first, so you can
>    still verify your own Unit 2/4 migrations lint clean: the only acceptable output is the
>    pre-existing `20260730090000_content_selection_ig_photo.sql` line(s). **Any violation naming a file
>    you authored is a real failure — fix it.** Record this deviation in the final report.
> 3. Tick the finding in **both** `CONSOLIDATED.md` and its source audit file; bump the source's
>    `## Progress` counts.
> 4. Commit code + ticked files together: `fix(audit): p1-launch — <ids>`.
>
> Running the suite concurrently is safe — `phpunit.xml` pins `CACHE_STORE=array`,
> `DB_CONNECTION=sqlite`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`, so the worktrees share no
> Redis or Postgres state. It only costs CPU.
>
> ---
>
> ### Final report — must include a merge section
>
> Report units done, units blocked, units awaiting Josh, test status, branch name — and list **DEAD
> findings separately from fixed ones** so the count is honest. Then **Merge notes** stating:
> - every file you edited that also appears in the P0-LAUNCH pre-flight lists
> - `#TEST-41`, deferred for concurrency, with the follow-up it needs
> - **`TEST-9`: which half you closed, which you could not, and whether `Tests\SchemaTestCase` existed in
>   your worktree** — if it did not, `TEST-9` is blocked-on-P0-LAUNCH, not DEAD and not done
> - **whether the schema-drift gate went red after your Unit 2 migrations** — and confirmation you did
>   **not** regenerate `schema-drift-baseline.json`
> - every migration you created, and confirmation each carries a `-- to revert:` comment
> - whether `INH-6`'s three copies were still identical when you checked
>
> 🔴 **Do not merge, and do not push to `development` or `production`.** Josh sequences the branches.
> Whichever merges second must run the **full suite on the merged result** — both branches passing in
> isolation proves nothing about their combination.
