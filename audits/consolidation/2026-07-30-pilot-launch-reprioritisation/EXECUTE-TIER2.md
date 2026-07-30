# Execute prompt — P1-LAUNCH Tier 2 (real, but not urgent at zero users)

Paste the block below into a **fresh Claude Code session** at the repo root.

Tier 2 of the P1-LAUNCH remainder after the run that merged at `4e221ca7`. These are genuine problems
that do not bite yet. **Do Tier 1 (`EXECUTE-TIER1.md`) first** — it holds the legal obligation and the
index that gets more expensive the longer it waits.

**Tier 3 (`EXECUTE-TIER3.md`) is separate. Do not pull items forward from it.**

---

## The prompt

> Work the **Tier 2** slice below. Follow `scripts/audit/fix-flow.md` exactly — plan → implement →
> **independent** review per item, models from the `## Execution policy` header of
> `audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md` (Plan = Opus,
> Implement = Sonnet escalating to Opus for public wire / lock / DB write paths, Review = Sonnet as a
> **separate** instance). Do not invent a different flow.
>
> **Scope — 4 work items + 1 traceability task.**
>
> | # | Item | Gate | Effort |
> |---|---|---|---|
> | 1 | `CFG-8` — Places retry policy → config, **with a spend clamp** | — | S |
> | 2 | `CFG-9` + `CFG-16` — remaining incident tunables → config | — | M |
> | 3 | `271-PRIV-1` — retired slugs accumulate forever | **migration** | M |
> | 4 | `SCALE-11` — `SiteMedia` force-delete storage I/O | **standalone / GDPR** | M |
> | 5 | `#3` — resolve or close an untraceable finding id | — | XS |
>
> Item 1 first: it is the only one with a **money** consequence, and it is the smallest.
>
> 🔴 **Hard scope boundary.** `CFG-8`, `CFG-9` and `CFG-16` were promoted out of a **19-item** `CFG-*`
> group specifically because they are incident and paid-API knobs. **The other 15 are WONTFIX by
> decision.** Do not widen this into a general config-extraction pass. In particular leave
> `app/Ingest/Connectors/TwitchConnector.php:162` (`CFG-17`) alone, and leave `MenuApifyScraper`'s
> `ATTEMPT_TIMEOUT = 60` alone — that is a different knob with documented multi-attempt math.
>
> ---
>
> ### Step 0 — isolated worktree (REQUIRED)
>
> ```bash
> git fetch origin
> git worktree add -b audit-fix/tier2-2026-07-30 \
>   "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-tier2-2026-07-30" \
>   origin/development
> cd "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-tier2-2026-07-30"
> composer install
> cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env      # REAL copy, never a symlink
>
> git merge-base --is-ancestor 4e221ca7 HEAD && echo "BASE OK" || echo "STOP: base predates P1-LAUNCH"
>
> ls app/Services/Platforms/GoogleBusinessService.php app/Ingest/Landing/Lander.php
> ls app/Ingest/Runtime/EffectLedger.php app/Ingest/Runtime/SourceScheduler.php
> ls app/Models/Core/Site/SiteMedia.php config/partna.php
> ```
>
> Own `composer install` and a **real copied** `.env` — never symlink either; that is the known cause of
> phantom feature-test failures. **Never run `git stash`** — other sessions share this checkout's stash
> stack. Do not push, do not merge.
>
> ---
>
> ### Step 1 — concurrency pre-flight (before planning, and again before every commit)
>
> 🔴 **A branch-to-branch diff is NOT sufficient and will lie to you.** A previous session's whole
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
> ⚠️ **Item 4 (`SCALE-11`) touches the GDPR account-deletion path.** Check specifically whether any
> sibling session is working on deletion, DSAR, or PII — several have been. If one is, **stop and ask
> Josh** before planning item 4. You may read a sibling worktree; **never** run a mutating git command
> against it.
>
> ⚠️ **Two `php artisan test` runs in the SAME worktree interfere.** `Storage::fake('media')` resolves to
> a fixed path and Redis session sets are process-global, so concurrent runs produce phantom failures in
> unrelated tests (`ReconcileTrackedSessionsCommandTest`, `VideoVariantServicePurgeTest`). The driver
> pinning in `phpunit.xml` protects across worktrees, **not within one**.
>
> ---
>
> ### Step 2 — verify the premise before writing a line of code
>
> Non-negotiable. In the run this slice came from, **of 13 findings examined only 3 were implementable
> exactly as written** — 4 were already fixed and 5 carried prescriptions that would have shipped bugs.
> Verifying "is this still broken?" catches the stale ones; **only reading the proposed fix catches the
> wrong ones.** Treat every prescription below as a hypothesis to confirm against the code.
>
> If an item no longer reproduces, tick it DEAD with a one-line reason and move on. **Deleting work is a
> successful outcome.**
>
> ⚠️ **`CFG-16` needs re-scoping first.** A merged session (P1-PILOT, `a32e8cbe`) edited
> `app/Ingest/Runtime/SourceScheduler.php`, one of its three target files. As of 2026-07-30 all five
> constants were still present at their original values and only `release()` had changed — **re-confirm
> that yourself** and report what you find.
>
> ---
>
> ### Blocker gate — pause for Josh's sign-off before implementing
>
> - **Item 3 (`271-PRIV-1`)** — creates a migration and a scheduled command.
> - **Item 4 (`SCALE-11`)** — listed **standalone** in the 07-28 sweep because it touches the GDPR
>   account-deletion data path. **Isolate its review.**
> - Items 1, 2 and 5 proceed without asking.
>
> ---
>
> ## Item 1 — `CFG-8`: Places retry policy → config, with a spend clamp
>
> **Source:** `audits/sweeps/2026-07-28-content-platform-rebuild/CONSOLIDATED.md:4106`.
> **File:** `app/Services/Platforms/GoogleBusinessService.php` — `DETAILS_MAX_ATTEMPTS = 2` (~`:183`) and
> `usleep(200_000)` (~`:226`).
>
> 🔴 **The clamp is the point of this finding, not decoration.** `fetchPlaceDetails()` calls
> `$this->budget->claim('details', $userId)` **inside** the retry loop (~`:211`) — one billing claim per
> attempt. So `max_attempts` is a direct multiplier on billed requests to **Places, the only paid external
> API in this project with no vendor-side cap**. An on-call engineer setting it to 10 during a flaky-Google
> incident multiplies spend 5×. **Verify the claim-inside-the-loop yourself and report what you find.
> Ship the clamp or ship nothing.**
>
> **Do NOT create a `partna.google_places` root.** Places already lives at `partna.limits.places`
> (`config/partna.php`, documented as *"the ONLY paid API in the system with no ceiling until now"*) and
> already owns the `PARTNA_PLACES_*` env prefix. A third Places namespace is sprawl. Add **inside**
> `limits.places`:
>
> ```php
> // CFG-8: Place Details retry policy. NOTE — attempts MULTIPLY billed spend: every
> // attempt claims its own PlacesBudget slot (see fetchPlaceDetails), so raising this
> // raises per-fetch cost. Clamped 1..3 so an on-call knob can never become a spend
> // multiplier.
> 'details_max_attempts'             => max(1, min(3, (int) env('PARTNA_PLACES_DETAILS_MAX_ATTEMPTS', 2))),
> 'details_retry_delay_microseconds' => (int) env('PARTNA_PLACES_DETAILS_RETRY_DELAY_US', 200_000),
> ```
>
> **Keep the class constants as the `config()` fallback defaults** — that is the established idiom (see
> `SafeUrlFetcher`'s "Fallback defaults for `config('partna.http_fetch.*')`" block).
> ⚠️ Keep the `usleep()` **inside** the `if ($attempt < $maxAttempts)` guard — moving it costs 200ms on
> every terminal failure.
>
> **Existing tests (keep green, unmodified):** `tests/Feature/Platforms/GoogleBusinessDetailsTest.php`,
> `tests/Feature/Platforms/PlacesBudgetGateTest.php`, `tests/Feature/Architecture/PlacesBudgetGuardTest.php`.
> Nothing currently asserts the attempt count or the backoff, so this behaviour is untested today.
>
> **New test** in `GoogleBusinessDetailsTest.php`: honours the configured policy **and never exceeds the
> clamp**. Set the server key, set `details_retry_delay_microseconds` to `0` so the suite does not sleep,
> `Http::fake(fn () => throw new ConnectionException('boom'))`, then assert `Http::assertSentCount(1)` at
> `max_attempts=1` and `3` at `max_attempts=99` — the second half is what proves the clamp.
>
> ---
>
> ## Item 2 — `CFG-9` + `CFG-16`: remaining incident tunables → config
>
> ### `CFG-9` — Apify run-sync timeout (**all three sites**, per Josh's ruling)
> **Source:** `…07-28…:4121`. Three files hardcode the identical `->timeout(110)` against the same
> `run-sync-get-dataset-items` endpoint:
> - `app/Services/Platforms/GoogleBusinessApifyScraper.php:52`
> - `app/Services/Platforms/GoogleMenuImagesScraper.php:49`
> - `app/Services/Platforms/InstagramScraper.php:37`
>
> The finding names only the first. Converting one of three identical siblings leaves a knob that does not
> do what its name says, during exactly the Apify-latency incident it exists for. **Convert all three.**
>
> Add inside the existing `partna.limits.apify` — **do NOT create `partna.apify`**:
> ```php
> // CFG-9: HTTP client timeout for Apify run-sync-get-dataset-items calls, which block
> // until the actor finishes. Raise during an Apify latency incident without a deploy.
> // Must stay UNDER the calling job's own timeout or the job dies first.
> 'run_sync_timeout_seconds' => (int) env('PARTNA_APIFY_RUN_SYNC_TIMEOUT_SECONDS', 110),
> ```
>
> **New test** in `tests/Feature/Platforms/GoogleBusinessApifyTest.php`. Laravel's fake handler passes the
> merged Guzzle options as the callback's **second argument**, so
> `Http::fake(function ($request, $options) use (&$seen) { $seen = $options['timeout']; … })` yields the
> value. **No test in this repo currently uses `$options` — note the idiom in a comment** so the next
> reader does not think it is accidental. Assert `110` by default and the overridden value when set.
>
> ### `CFG-16` — five ingest constants
> **Source:** `…07-28…:4214`. Re-verify locations before editing (see Step 2).
>
> | Constant | File | Value |
> |---|---|---|
> | `TOMBSTONE_RUNS` | `app/Ingest/Landing/Lander.php` | `3` |
> | `GUARD_THRESHOLD` | `app/Ingest/Landing/Lander.php` | `0.4` |
> | `ABANDON_AFTER_SECONDS` | `app/Ingest/Runtime/EffectLedger.php` | `900` |
> | `ALPHA` | `app/Ingest/Runtime/SourceScheduler.php` | `0.3` |
> | `STRANDED_AFTER_SECONDS` | `app/Ingest/Runtime/SourceScheduler.php` | `7200` |
>
> Add to the existing `'ingest' => [ … ]` block in `config/partna.php`. **Each needs a WHY comment — the
> existing constant docblocks already have the right prose; move it, don't reinvent it.**
>
> ⚠️ **`(float)` not `(int)` on the guard threshold.** An `(int)` cast makes it `0`, and the delete-guard
> then trips on **every** run — a critical-anomaly storm. Warn about this in the config comment.
>
> 🔴 **The one non-mechanical detail — do not miss it.** `EffectLedger` interpolates the window into an
> operator-facing anomaly summary:
> ```php
> 'summary' => sprintf('Billed effect claim abandoned after %ds — vendor may have charged', self::ABANDON_AFTER_SECONDS),
> ```
> This **must** read the same source as the comparison that triggers it. If one reads config and the other
> the constant, the anomaly row tells the on-call engineer a window that was not the one applied — on a
> **money-adjacent critical alert**. Convert all three `ABANDON_AFTER_SECONDS` sites together via one
> `private function abandonAfterSeconds(): int`, or convert none.
>
> ⚠️ In `Lander`, read both values **once at the top of `foldAbsence()`'s transaction closure**, not inside
> the `array_chunk` loop, so every chunk of one fold uses the same threshold.
>
> **Existing tests (keep green, unmodified):** `tests/Feature/Ingest/LanderTest.php` (third-consecutive-
> absence, the 40% delete-guard, guard-stays-frozen), `tests/Feature/Ingest/EffectLedgerTest.php` (the
> `subSeconds(901)` abandonment case and the critical-anomaly case),
> `tests/Feature/Ingest/SourceSchedulerTest.php`, `tests/Feature/Console/IngestAnomaliesCommandTest.php`.
> **These assert today's literals and are your entire regression net — every default must be
> byte-identical.**
>
> **New tests (add to those files, do not create new ones):**
> - `tombstone_runs = 1` tombstones on the **first** dominated absence.
> - `delete_guard_threshold = 0.2` trips on a 20% vanish. ⚠️ The `count($dominatedAbsent) >= 5` floor is
>   **not** part of this finding and stays hardcoded — **seed ≥5 absent keys or the test asserts nothing.**
> - `abandon_after_seconds = 60` with a 61s-old claim → `status === 'abandoned'` **and** the anomaly
>   summary contains `after 60s`. This single test pins the sprintf/comparison consistency above.
> - `alpha = 1.0` drives `change_rate` to exactly `1.0` after one changed run (exact value, no float slop).
>
> ---
>
> ## Item 3 — `271-PRIV-1`: retired slugs accumulate forever  🔴 BLOCKER (migration)
>
> **Source:** `audits/sweeps/2026-07-24-pr270-pr271-actions-and-slugs/CONSOLIDATED.md:1195`.
> Retired item-url slugs in `site.item_slugs` accumulate with **no retention column and no purge job**.
>
> Add `retired_at` plus a scheduled prune command. **Model it on the handle/subdomain alias lifecycle that
> already exists** rather than inventing a new pattern: `core.user_handle_aliases` and
> `site.site_subdomain_aliases` carry `reclaim_until` / `expires_at`, resolvers use `->active()`, and
> `handles:prune-expired-aliases` does the hard delete. Read that first and follow it — including where
> its retention windows live in `config('partna.handle.*')`.
>
> ⚠️ **Never create a Laravel migration.** Raw SQL in `supabase/migrations/` only; a Composer guard
> rejects `database/migrations/*.php`.
> ⚠️ **Every migration you author must carry a `-- to revert:` comment** — that convention shipped
> repo-wide via `LC-ROLLBACK`.
> ⚠️ **One `CONCURRENTLY` statement per file, alone**, if you add an index — the Supabase CLI pipelines
> multi-statement files and `CONCURRENTLY` cannot run in a pipeline (`SQLSTATE 25001`).
> ⚠️ **Adding a column is not enough.** A retention column with no prune job is worse than nothing — it
> reads as solved. Register the command in `routes/console.php` and **test that the schedule entry
> exists**, not just that the command works when invoked.
> ⚠️ **Do NOT apply the migration to any database.** Author files only; Josh decides when to push.
> ⚠️ **Do NOT regenerate `scripts/launch-check/*baseline*.json`.** If a drift gate goes red, stop and ask.
>
> **Retention window is a product decision, not yours.** Pick a default, state it explicitly, and flag it
> for Josh rather than burying it. The handle aliases use +14d reclaim / +90d hard-delete — say whether
> you are matching that and why.
>
> ---
>
> ## Item 4 — `SCALE-11`: `SiteMedia` force-delete storage I/O  🔴 BLOCKER (standalone / GDPR)
>
> **Source:** `audits/sweeps/2026-07-28-content-platform-rebuild/CONSOLIDATED.md:1606`.
> `SiteMedia`'s force-delete hook serialises **per-file storage I/O** — one blocking remote call per file,
> inline.
>
> 🔴 **Listed standalone in its source audit because it sits on the GDPR account-deletion data path.
> Isolate its review — a reviewer must see this diff and nothing else.**
>
> **Read these before planning, they are the live constraints:**
> - `docs/superpowers/specs/2026-07-23-deletion-path-appendonly-fix-design.md` — the deletion path has an
>   append-only-audit interaction that has already bitten twice.
> - `reference_user_takedown_kv_paths` behaviour: a custom domain must be **captured before**
>   `forceDelete` cascades, or the KV cleanup loses its key.
>
> **The risk to weigh explicitly, and state your reasoning:** moving file deletion to a queued job makes
> deletion *appear* complete before the files are gone. For a GDPR erasure that is a compliance question,
> not just a performance one — "we deleted your data" must not become "we queued deleting your data" with
> no completion guarantee. If you queue it, say what proves completion and what happens on permanent job
> failure. **If that cannot be answered cleanly, recommend deferring rather than shipping a faster
> deletion with a weaker guarantee.**
>
> Do not change what gets deleted. Ordering, cascade behaviour and audit rows must stay identical.
>
> ---
>
> ## Item 5 — `#3`: resolve or close an untraceable finding id
>
> 🔴 **`#3` does not exist as a checkbox anywhere in `audits/`.** Verified 2026-07-30:
> `grep -rlnE "^\- \[[ x]\] \*\*#?3\*\*" audits/` returns nothing. The 07-11 sweep — which the
> consolidation cites as its source — uses **prefixed** ids (`#API-10`, `#DINT-3`, `#TEST-9`); bare
> numbers are not its scheme. The consolidation describes `#3` at `CONSOLIDATED.md:1098` as
> *"`analytics.item_views` has no DB-level dedup key, relying entirely on app-side Redis"*.
>
> **Do this:** search the source audits for a finding matching that description and identify its real id.
> - **If found** — correct the consolidation's reference to the real id and treat the finding on its merits
>   (it is a data-integrity concern about relying solely on Redis for dedup; assess whether it belongs in
>   Tier 2 at all).
> - **If not found** — tick `#3` closed as **untraceable**, recording that the id maps to no source
>   finding, and note the same for `#38` (Tier 3, same defect).
>
> Either way, **report this as a bookkeeping defect in the consolidation file**: an execution prompt that
> names ids which do not exist sends the next session hunting. Two of the 34 are phantoms.
>
> ---
>
> ### Step 3 — before every commit
>
> 1. Re-run the Step 1 pre-flight. If a sibling's file set now intersects yours, stop and reconcile.
> 2. `composer test` green. **Baseline on `4e221ca7` is `1 warning, 42 skipped, 6901 passed`, exit 0** —
>    including the migration safety lint. Any failure is yours.
> 3. `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse --memory-limit=1G` on changed files.
>    ⚠️ The default 128M limit crashes phpstan on this repo. The `(float)`/`(int)` casts on `config()`
>    returns are a shape phpstan has opinions about.
> 4. Tick the finding in **both** `CONSOLIDATED.md` and its source audit, and bump the source's nearest
>    preceding `## Progress` block.
>    ⚠️ **`CFG-8`, `CFG-9` and `CFG-16` are promoted findings — they have NO checkbox in the consolidation
>    file.** They tick only in the 07-28 sweep. The consolidation's P1-LAUNCH count tops out at 27, not 34.
> 5. Commit code + ticked files **together**: `fix(audit): tier2 — <ids>`.
>
> ⚠️ Unnamespaced Pest files share a **GLOBAL symbol table** — a redeclared helper aborts the whole suite.
> Prefix new helpers uniquely and grep `tests/` to confirm.
> ⚠️ **Tests run SQLite; production is Postgres.** `Tests\TestCase::setUp()` repoints the `pgsql`
> connection at in-memory SQLite **unconditionally** — a test using `DB::connection('pgsql')` is **not**
> running on Postgres. Never claim otherwise. Verify constraint-bound writes against
> `supabase/migrations/` DDL, not just a passing suite.
> ⚠️ Every cache key MUST carry a TTL — **never `Cache::forever()`** (guarded by
> `tests/Feature/Cache/CacheKeyspaceConstraintsTest.php`).
>
> ---
>
> ### Final report
>
> Items done, items blocked, items awaiting Josh, test status, branch name. **DEAD findings listed
> separately from fixed ones** so the count is honest. Then **Merge notes** stating:
> - every file you edited that also appears in a sibling worktree's file set
> - every migration you created, and confirmation each carries a `-- to revert:` comment
> - confirmation you applied no migration to any database
> - for `CFG-8`: confirmation the clamp is present, and what you found about the claim-inside-the-loop
> - for `CFG-16`: confirmation every default is byte-identical to the old literal, and that all three
>   `ABANDON_AFTER_SECONDS` sites read one source
> - for `SCALE-11`: your completion-guarantee reasoning, or your recommendation to defer
> - for `#3`: the real id, or confirmation it is untraceable
> - the retention window you chose for `271-PRIV-1` and whether it matches the handle-alias precedent
>
> 🔴 **Do not merge, and do not push to `development` or `production`.** Josh sequences the branches.
> Whichever merges second must run the **full suite on the merged result** — both branches passing in
> isolation proves nothing about their combination.
