# Execute prompt — P1-LAUNCH Tier 1 (the four items that actually matter)

> ## ✅ EXECUTED 2026-07-30 — merged and pushed to `development` @ `971adaf4`. **Do not re-run.**
>
> All 4 work items shipped and independently reviewed (4 × PASS); `#CACHE-3` produced its brief and was
> then dispositioned separately (see below). **No finding turned out DEAD** — all four premises
> reproduced — but `DINT-1`'s prescription was the wrong *shape*: it asked for a single-column
> `(user_id)` index, and `(user_id, occurred_at)` shipped instead, because the DSAR export also does
> `ORDER BY occurred_at` and every other raw analytics table already carried that composite.
>
> Verification: `composer test` on the merged result `1 warning, 42 skipped, 6909 passed`, exit 0;
> `composer test:pg` 185 passed; pint clean; phpstan 0 errors. The two migrations were **authored, not
> applied** — pushing them to dev/prod is still Josh's call.
>
> **Two of this prompt's own premises were wrong, and are worth carrying into Tier 2/3:**
> 1. **`DataExportSchemaParityTest` does NOT run in CI.** This file cites it as covering the new DSAR
>    section on Postgres. `phpunit.pg.xml` scopes the pg lane to `tests/Postgres/` only, and that test
>    lives in `tests/Feature/Database/` — so it self-skips in *both* lanes. The Postgres form of
>    `where('payload->user_id', …)` rests on production precedent, not on any gate.
> 2. The `DataExportTestCase` trap is **three** dependent test files, not five; the other five
>    `Queue::fake()` and never drain the builder.
>
> `#CACHE-3` was dispositioned **2026-07-31**: deferred, `Bus::chain` rejected outright, with the two
> incidental fixes its brief surfaced shipped separately. See `CACHE-3-DECISION-BRIEF.md`.

Paste the block below into a **fresh Claude Code session** at the repo root.

Distilled from the P1-LAUNCH run that merged at `4e221ca7` (12 of 34 findings dispositioned, then
stopped deliberately per `CLAUDE.md`'s "disposition beats execution" policy). These are the four
remaining items judged genuinely worth doing at zero live users, plus one decision brief.

**Everything else in the bucket is Tier 2/3 and stays open with its reasoning already recorded in
`CONSOLIDATED.md`. Do not widen into it.**

---

## The prompt

> Work the **Tier 1** slice below. Follow `scripts/audit/fix-flow.md` exactly — plan → implement →
> **independent** review per item, models from the `## Execution policy` header of
> `audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md` (Plan = Opus,
> Implement = Sonnet escalating to Opus for public wire / lock / DB write paths, Review = Sonnet as a
> **separate** instance). Do not invent a different flow.
>
> **Scope — 4 work items + 1 decision brief. Work them in this order.**
>
> | # | Item | Gate | Why it's Tier 1 |
> |---|---|---|---|
> | 1 | `art_url` Postgres blindness | — | A test that reports coverage it does not have |
> | 2 | `YoutubeFeed::mapEntry()` null deref | — | Malformed third-party input becomes a 500 |
> | 3 | `DINT-1` — two analytics indexes | **migration** | Cheap now, expensive later |
> | 4 | `#9` — DSAR evidence export | **privacy/GDPR** | Legal obligation. ⚠️ **collision risk, see Step 1** |
> | 5 | `#CACHE-3` — decision brief only | **do NOT implement** | Needs Josh's architectural ruling |
>
> Items 1 and 2 are small and independent — do them first to get a green branch early. Item 4 is the
> highest-value and the most delicate; it goes last so nothing else is blocked behind it.
>
> ---
>
> ### Step 0 — isolated worktree (REQUIRED)
>
> ```bash
> git fetch origin
> git worktree add -b audit-fix/tier1-2026-07-30 \
>   "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-tier1-2026-07-30" \
>   origin/development
> cd "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-tier1-2026-07-30"
> composer install
> cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env      # REAL copy, never a symlink
>
> # Base gate — must be at 4e221ca7 or later (the P1-LAUNCH merge). STOP if not.
> git merge-base --is-ancestor 4e221ca7 HEAD && echo "BASE OK" || echo "STOP: base predates P1-LAUNCH"
>
> # Sanity gate — all must exist.
> ls app/Ingest/Support/YoutubeFeed.php app/Ingest/Projection/ProjectionWriter.php
> ls app/Services/User/DataExport/DataExportPayloadBuilder.php
> ls tests/Postgres/ProjectionWriterBatchingTest.php tests/Feature/User/DataExport/DataExportTestCase.php
> ```
>
> Own `composer install` and a **real copied** `.env` — never symlink either; that is the known cause of
> phantom feature-test failures. **Never run `git stash`** — other sessions share this checkout's stash
> stack. All commits land on `audit-fix/tier1-2026-07-30`. Do not push, do not merge.
>
> ---
>
> ### Step 1 — concurrency pre-flight (before planning, and again before every commit)
>
> 🔴 **A branch-to-branch diff is NOT sufficient and will lie to you.** A previous session's entire
> changeset sat as uncommitted working-tree state for hours while `git diff` against its branch returned
> *empty*. Check the sibling **worktrees**:
>
> ```bash
> git fetch origin
> git worktree list
> for wt in /Users/joshuahunter/Herd/Side\ Street/backend-wt/*/; do
>   echo "── $wt"; git -C "$wt" status --short; done
> ```
>
> **As of 2026-07-30 two sessions were live.** Re-derive this yourself; it will have changed.
>
> 🔴 **`fix/nested-third-party-pii-2026-07-30` is a HARD collision risk for item 4.** Its name says
> nested third-party PII, and `#9` is precisely about the third-party moderation context nested in
> `moderation.evidence.payload`. Before planning item 4, check whether that session touches
> `app/Services/User/DataExport/DataExportPayloadBuilder.php`, `app/Services/Platforms/DsarPayloadFilter.php`,
> `tests/Feature/Security/DataExportCoverageTest.php`, or `tests/Feature/Platforms/Registry/DsarAllowlistCoverageTest.php`.
> **If it does — or if you cannot tell — stop and ask Josh whether to sequence item 4 after it merges.**
> Two sessions independently editing DSAR PII filtering is how a privacy regression gets shipped with
> both suites green. Items 1–3 are unaffected either way; do those regardless.
>
> ⚠️ **Two `php artisan test` runs in the SAME worktree interfere.** `Storage::fake('media')` resolves to
> a fixed path and Redis session sets are process-global, so concurrent runs produce phantom failures in
> unrelated tests (`ReconcileTrackedSessionsCommandTest`, `VideoVariantServicePurgeTest`). The driver
> pinning in `phpunit.xml` protects across worktrees, **not within one**. Use `--filter` when anything
> else is running; run the full suite only when you have the worktree to yourself.
>
> ---
>
> ### Step 2 — verify the premise before writing a line of code
>
> Non-negotiable. In the run this slice came from, **of 13 findings examined only 3 were implementable
> exactly as written** — 4 were already fixed and 5 carried prescriptions that would have shipped bugs.
> Verifying "is this still broken?" catches the stale ones; **only reading the proposed fix catches the
> wrong ones.** Treat every prescription below as a hypothesis you must confirm against the code.
>
> If an item no longer reproduces, tick it DEAD with a one-line reason and move on. **Deleting work is a
> successful outcome here.**
>
> ---
>
> ### Blocker gate — pause for Josh's sign-off before implementing
>
> - **Item 3 (`DINT-1`)** — creates DB migrations.
> - **Item 4 (`#9`)** — GDPR export path.
>
> Produce the plan, present it with the blast radius and your recommendation, and **wait**. Items 1, 2
> and 5 proceed without asking.
>
> ---
>
> ## Item 1 — the `art_url` Postgres blindness
>
> **File:** `tests/Postgres/ProjectionWriterBatchingTest.php` (helper `pwbtDoc`).
>
> It hardcodes `'art_url' => null` on all four of its tests. No image URL means **no `content.item_media`
> and no `content.media_assets` rows are ever written** — so at N=200 the real-Postgres lane exercises
> *zero* of the media path that `#SCALE-17` rewrote (merged in `3f24fdf7`). Everything Postgres-specific
> about that change is tested nowhere:
> - `insertOrIgnore` compiles to `ON CONFLICT DO NOTHING` on Postgres vs `INSERT OR IGNORE` on SQLite
> - the ~4,000-bind `item_media` multi-row insert and the 501-bind `item_id IN (...)` DELETE
> - lock behaviour of the widened `DELETE … WHERE item_id IN (chunk) AND source_id = ?` inside `DB::transaction`
>
> **Fix:** populate `art_url` in `pwbtDoc` so media rows are actually produced, and assert the media path
> at scale — exact `content.item_media` row counts, `MAX(position)` per item, and non-null `asset_id`.
>
> ⚠️ **This lane is NOT part of `composer test`.** `tests/Postgres/` runs under `composer test:pg`
> (`phpunit.pg.xml`) against a real migrated Postgres container. Run it and paste the output — a green
> `composer test` proves nothing about this item.
>
> ⚠️ Do not weaken the file's existing assertions to accommodate the new rows. If a count assertion
> shifts because media now exists, **re-measure and re-pin it; never loosen it to make it pass.**
>
> ---
>
> ## Item 2 — `YoutubeFeed::mapEntry()` null dereference
>
> **File:** `app/Ingest/Support/YoutubeFeed.php:79-81`.
>
> When a feed declares **no `xmlns:media`**, `$entry->children($mediaNs)->group` returns a **non-null but
> empty** `SimpleXMLElement`, so the `!== null` guard passes. Then `$mediaGroup->children($mediaNs)`
> returns `null`, and reading `->thumbnail` throws — Laravel's handler upgrades the warning to a fatal
> `ErrorException`. Reproduced in the real harness.
>
> Real YouTube feeds always declare the namespace, so it is unreachable today. It still turns "third
> party sent us a malformed body" into a **500** instead of the intended `thumbnail => null`, which is
> exactly the hostile-input class the XXE work covered.
>
> **Fix:** guard properly — `if ($mediaGroup !== null && $mediaGroup->children($mediaNs) !== null)`, or
> check `count($entry->children($mediaNs)->group)`. Verify which is actually correct against SimpleXML's
> behaviour rather than picking one.
>
> **Test:** add a case to `tests/Unit/Ingest/YoutubeFeedTest.php` feeding a valid feed with **no**
> `xmlns:media` declaration, asserting the record parses with `thumbnail => null` and **nothing throws**.
> ⚠️ That file's existing fixtures declare `xmlns:media` deliberately — **that declaration is load-bearing**
> precisely to avoid tripping this bug. Do not remove it from the existing cases.
>
> ---
>
> ## Item 3 — `DINT-1`: two missing analytics indexes  🔴 BLOCKER
>
> Source: `audits/archive/sweeps/2026-07-24-pr270-pr271-actions-and-slugs/CONSOLIDATED.md`.
> Queries filter `analytics.action_events` and `analytics.item_views` by `user_id` with no index, so
> Postgres sequential-scans. Cheap to add now while the tables are near-empty; adding an index to a large
> table later is slow and can block writes. **This gets more expensive the longer it waits.**
>
> ⚠️ **One `CREATE INDEX CONCURRENTLY` statement per migration file, alone in that file.** The Supabase
> CLI sends a multi-statement file as one libpq **pipeline**, and `CONCURRENTLY` cannot run in a pipeline
> — `SQLSTATE 25001`. Two indexes = **two files**. See `supabase/migrations/CONVENTIONS.md` §1.
>
> ⚠️ **Every migration you author must carry a `-- to revert:` comment.** That convention shipped
> repo-wide via `LC-ROLLBACK`; a new file without one lands as an immediate exception to it.
>
> ⚠️ **Never create a Laravel migration.** Raw SQL in `supabase/migrations/` only — a Composer guard
> rejects `database/migrations/*.php`.
>
> ⚠️ **Do NOT apply these to any database.** Author the files only. Josh decides when to push to dev/prod.
>
> ⚠️ **Do NOT regenerate `scripts/launch-check/schema-drift-baseline.json` or
> `no-local-canonical-ddl-baseline.json`.** A correctly-authored migration should not perturb them. If a
> drift gate goes red, **stop and ask** — regenerating would silently overwrite grandfathering.
>
> **Consider registering the new indexes** in `tests/Schema/IndexCoverageTest.php`, which asserts named
> indexes exist and are `indisvalid` (catching an INVALID stub from a cancelled `CONCURRENTLY` build).
> That file now runs in the real applied-schema lane, so it is a genuine guard rather than a dead test.
>
> ---
>
> ## Item 4 — `#9`: DSAR must export the subject's own frozen identity  🔴 BLOCKER (privacy)
>
> Source: `audits/archive/sweeps/2026-07-11-full-work-sweep/CONSOLIDATED.md` (canonical id `PRIV-13`).
>
> **What's wrong.** `EvidenceSnapshotService::snapshotSite()` freezes the user's `handle` and
> `display_name` into `moderation.evidence.payload` when they're reported. `DataExportPayloadBuilder` has
> **no evidence section at all**, and `moderation.evidence` is not in `COVERED_PII_TABLES`. So a user's
> DSAR omits their own captured identity data.
>
> 🔴 **A prediction that `PRIV-3` closed this was made and was WRONG.** `PRIV-3` closed the *erasure*-
> coverage half only. It deliberately did **not** add an export section, because the `payload` JSON also
> holds **third-party moderation context** and exporting it wholesale would leak the reporter's data to
> the reported party. **⚠️ The rationale comment now in `DataExportPayloadBuilder` explains that
> third-party exclusion and reads like a closed question. It is not one.** `#9` is the narrower ask:
> surface **the subject's own** frozen fields. Do not let that comment talk you out of the finding — but
> **do** rewrite it, or the file will contradict itself.
>
> **The hard rule for the implementation:** build each exported row **positively from an explicit
> allowlist**. Never assign the raw `payload` onto the output and never "filter it down" — filtering only
> removes what you remembered to remove, so a payload key added next year leaks. A positive build makes
> that structurally impossible.
>
> **Export:** the subject's own frozen `handle`, `display_name`, `site_subdomain`, plus the row's `id`,
> `case_id`, `evidence_type`, `captured_at`.
> **Never export:** the `payload` column itself; **`signal_id`** — it is the FK to
> `moderation.case_signals`, which holds `reporter_user_id`, `reporter_email`, `reporter_ip_hash`, so
> exporting it hands the reported party a pointer into the reporter's record; `content_hash`; and any row
> whose `evidence_type` is not `content_snapshot` (the CHECK also permits `csam_hash_match`,
> `upload_metadata`, `staff_attachment` — none written today, but `csam_hash_match` disclosure is a
> law-enforcement tipping-off risk and `staff_attachment` is internal).
>
> **Scope the query on `payload->user_id` alone, with no join to `moderation.cases`.** That is the
> identical predicate `AccountDeletionService::purgeReportedUserEvidencePii()` uses, so export and
> erasure provably cover the same row set — which is the compliance property worth having.
>
> 🔴 **The trap that will cost you an hour if you miss it:**
> `tests/Feature/User/DataExport/DataExportTestCase.php` creates `moderation.cases` and
> `moderation.case_signals` but **not `moderation.evidence`**. Five test files boot through it and drain
> the whole builder, so without a table stub they all fail with `no such table: moderation.evidence`. Copy
> the DDL from `tests/Feature/User/AccountDeletion/AccountDeletionTestCase.php`.
>
> **Test the exclusion, not just the inclusion.** Seed a payload carrying sentinel values for keys that
> must never be emitted (`reporter_handle => 'REPORTER-SENTINEL'`, etc.) plus a non-null `signal_id`, then
> assert the exported row's keys are **exactly** the allowlist and that the serialised output contains
> neither sentinel.
>
> ⚠️ **`json_encode` escapes `/` to `\/` by default.** An assertion like
> `expect(json_encode($rows))->not->toContain('some/value')` is **unfalsifiable**. Use
> `JSON_UNESCAPED_SLASHES`, and add a control asserting the sentinel *is* findable in that serialisation
> so the check cannot silently revert to vacuous. This exact mistake was made and caught in the previous
> run.
>
> ⚠️ **`where('payload->user_id', …)` compiles differently per driver** — `json_extract(...)` on SQLite,
> `"payload"->>'user_id'` on Postgres. A green SQLite test does not prove the Postgres form parses. The
> same predicate already runs in production in `purgeReportedUserEvidencePii()`, which is strong
> precedent — say so in your report rather than claiming SQLite proved it. `tests/Feature/Database/DataExportSchemaParityTest.php`
> reflects over every `stream*` method and will cover the new section on the Postgres lane.
>
> ---
>
> ## Item 5 — `#CACHE-3`: decision brief only, **write no code**
>
> Produce a short brief for Josh, then stop. The finding wants projection moved out of the ingest job into
> a chained job. Two shipped invariants block it:
> 1. **`JOB-4`** (`a32e8cbe`) made `RunExecutor.php:176-186` downgrade a run's outcome to `degraded` when
>    projection fails. Chaining finalises `ingest.runs.outcome` as `ok` *before* projection runs, silently
>    regressing that.
> 2. `RunSourceJob`'s `finally` calls `SourceScheduler::release()`, so chaining drops the source claim
>    while projection is still running — `claimDue()` could start a second land+project pass concurrently,
>    and `ProjectionWriter`'s delete-then-insert is not concurrency-safe against itself on one stream.
>
> **The brief must answer:** what replaces the degraded-outcome signal, and what holds the claim across a
> chained job? Include the throughput evidence for whether this is needed at all (`core.users` is 0 in
> production; `RunSourceJob` already has `$timeout = 120`, and a real overrun surfaces as a timeout that
> Nightwatch already alerts on). **Recommend a course; do not implement one.**
>
> ---
>
> ### Step 3 — before every commit
>
> 1. Re-run the Step 1 pre-flight. If a sibling's file set now intersects yours, stop and reconcile.
> 2. `composer test` green. **Baseline on `4e221ca7` is `1 warning, 42 skipped, 6901 passed`, exit 0** —
>    including the migration safety lint. Any failure is yours; investigate, don't hand-wave.
> 3. For item 1 **also** run `composer test:pg` and paste the output.
> 4. `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse --memory-limit=1G` on changed files.
>    ⚠️ The default 128M limit crashes phpstan on this repo.
> 5. Tick the finding in **both** `CONSOLIDATED.md` and its source audit file, and bump the source's
>    nearest preceding `## Progress` block.
> 6. Commit code + ticked files **together**: `fix(audit): tier1 — <ids>`.
>
> ⚠️ Unnamespaced Pest files share a **GLOBAL symbol table** — a redeclared helper aborts the whole
> suite. Prefix new helpers uniquely and grep `tests/` to confirm.
> ⚠️ **Tests run SQLite; production is Postgres.** `Tests\TestCase::setUp()` repoints the `pgsql`
> connection at in-memory SQLite **unconditionally** — so a test using `DB::connection('pgsql')` is
> **not** running on Postgres. Never claim otherwise. Verify constraint-bound writes against
> `supabase/migrations/` DDL, not just a passing suite.
>
> ---
>
> ### Final report
>
> Items done, items blocked, items awaiting Josh, test status (both lanes), branch name. List **DEAD
> findings separately from fixed ones** so the count is honest. Then **Merge notes** stating:
> - every file you edited that also appears in a sibling worktree's file set
> - every migration you created, and confirmation each carries a `-- to revert:` comment
> - confirmation you applied no migration to any database
> - whether `#9` collided with `fix/nested-third-party-pii-2026-07-30`, and how you resolved it
> - for item 1, the `composer test:pg` result — a green `composer test` does not cover it
>
> 🔴 **Do not merge, and do not push to `development` or `production`.** Josh sequences the branches.
> Whichever merges second must run the **full suite on the merged result** — both branches passing in
> isolation proves nothing about their combination.
