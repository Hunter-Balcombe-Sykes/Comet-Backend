# Execute prompt — PHPStan standing-gate triage (17 errors)

Paste the block below into a **fresh Claude Code session** at the repo root.

Triage was completed 2026-07-30 at tip `6e0f8dae` — **this prompt carries the results, so the session
does not repeat it.** The 17 errors are NOT one problem: they are four, with different risk profiles
and one that is not a lint task at all.

| Unit | # | Nature | Gate |
|---|---|---|---|
| 1 | 6 | Dead null-guards left behind by the DINT-8 `NOT NULL` tightening | — |
| 2 | 3 | PHPStan false positive — baseline with reasoning | — |
| 3 | 1 | **Possible DSAR privacy gap** — investigate, do NOT "fix" | 🔴 **privacy — STOP** |
| 4 | 7 | Annotation-precision noise; some MUST NOT be "fixed" | — |

**Why this is not one bulk baseline:** unit 3 is a `classConstant.unused` — the single most
ignorable-looking error in the set — and it may be pointing at third-party PII that is not being
stripped from DSAR exports. A blanket baseline buries it. See `feedback_baseline_after_triage`.

---

## The prompt

> Clear the standing **PHPStan** gate on `development` (17 errors at tip `6e0f8dae`).
>
> Follow `scripts/audit/fix-flow.md` — plan → implement → **independent** review per unit. Work units in
> the order below: unit 1 is a third of the errors at near-zero risk, unit 3 is the one that actually
> matters, unit 4 is the least valuable and the easiest to get wrong.
>
> 🔴 **Other Claude sessions are running.** Before editing ANY file, run `git worktree list` and check
> each sibling worktree's `git status` **and** its branch diff vs `origin/development`. Unit 1 touches
> six files under `app/Http/Controllers/Api/Platforms/`, which overlaps the territory of the
> `audit-fix/p1-launch-*` bucket. If a file is owned, skip it, say so, and move on — do not negotiate
> by editing around them.
>
> ### Step 0 — isolated worktree (REQUIRED)
>
> ```bash
> git fetch origin
> git worktree add -b fix/phpstan-triage-2026-07-30 \
>   "/Users/joshuahunter/Herd/Side Street/backend-wt/phpstan-triage-2026-07-30" \
>   origin/development
> cd "/Users/joshuahunter/Herd/Side Street/backend-wt/phpstan-triage-2026-07-30"
> cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env
> COMPOSER_PROCESS_TIMEOUT=0 composer install --no-interaction --prefer-dist --optimize-autoloader
> ```
>
> Base off `origin/development` **explicitly**. `origin/HEAD` is `production` on this repo, so any
> tooling that defaults to the default branch bases you on the wrong, older ref.
>
> Do **not** symlink `vendor/` or `.env` from the main checkout — a real `composer install` is required
> or Feature tests break in ways that look like your change.
>
> ### Baseline the CURRENT state before touching anything
>
> ```bash
> composer analyse 2>&1 | grep -E "Found [0-9]+ error"     # expect: 17
> ```
>
> If it is not 17, `development` has moved. Re-triage the delta before proceeding — do not assume this
> prompt's classification still holds.
>
> ---
>
> ### Unit 1 — dead null-guards (6 errors) — DO FIX
>
> ```
> app/Console/Commands/GcOrphanedPlatformMediaCommand.php:104   notIdentical.alwaysTrue
> app/Http/Controllers/Api/Platforms/Concerns/DefersBespokeConnect.php:160  notIdentical.alwaysTrue
> app/Http/Controllers/Api/Platforms/GenericPlatformController.php:249      notIdentical.alwaysTrue
> app/Http/Controllers/Api/Platforms/RefreshController.php:143             identical.alwaysFalse
> app/Services/Analytics/ContentFreshness.php:70                           identical.alwaysFalse
> app/Http/Controllers/Api/Platforms/BookingController.php:209             nullsafe.neverNull
> ```
>
> **Why these are real.** `IntegrationConnection`'s own annotation records the cause:
>
> ```php
> @property Carbon $created_at NOT NULL in Postgres since
> chk_platform_connections_timestamps_not_null
> (supabase/migrations/20260729150016-18, DINT-8) — was nullable
> (DEFAULT now() with no NOT NULL) before that.
> ```
>
> The column became `NOT NULL` on 2026-07-29. Every `if ($x->created_at === null) return;` guard was
> correct before that migration and is unreachable after it. The DB and the annotation were updated;
> the now-dead guards were not. Delete them (and for `BookingController:209`, `?->` → `->`).
>
> **Verify per site, do not pattern-match.** Confirm the property in question really is the
> DINT-8-tightened column on a model whose annotation says `NOT NULL`. If a site is guarding a
> *different* nullable field, or the model's annotation is the thing that is wrong, that is a
> different (and more serious) finding — stop and report it rather than deleting a live guard.
>
> ### Unit 2 — `Redis::eval()` false positive (3 errors) — BASELINE, DO NOT "FIX"
>
> ```
> app/Services/Cache/DailyCounterClaim.php:95   arguments.count
> app/Services/Cache/DailyCounterClaim.php:96   argument.type  (x2)
> ```
>
> **The code is correct.** `$store->connection()` returns
> `Illuminate\Redis\Connections\PhpRedisConnection`, whose signature is
> `eval($script, $numberOfKeys, ...$arguments)` — exactly how it is called. PHPStan resolves
> `connection()` to raw `\Redis`, whose signature is `eval(script, args, num_keys)`. Changing this call
> to satisfy the analyser would BREAK a working atomic claim path.
>
> Add a **narrowly-scoped** ignore with the reasoning in a comment (path + identifier + count). Do not
> widen it beyond these three. This project's `phpstan.neon` prints a "fix the cause, don't suppress"
> policy — this is the documented exception to it, so state why in the comment.
>
> ### Unit 3 — 🔴 `DsarPayloadFilter::THIRD_PARTY_KEYS` (1 error) — INVESTIGATE ONLY, THEN STOP
>
> ```
> app/Services/Platforms/DsarPayloadFilter.php:41   classConstant.unused
> ```
>
> **Do not delete this constant. Do not baseline this error. Do not "fix" it.**
>
> `THIRD_PARTY_KEYS = ['reviews', 'reviewSummary', 'organiser', 'venue']` has exactly two mentions in
> the repo: its declaration and a docblock describing it. Nothing reads it. The class docblock states
> the intended behaviour:
>
> > *"THIRD_PARTY_KEYS removed wherever they appear (reviewer/organiser/venue) — personal data about a
> > third party (a reviewer, an event organiser, a venue), not about the account holder."*
>
> Two possibilities:
> 1. The stripping is already baked into each platform's `ALLOWLIST`, so the constant is documentation
>    residue → safe to delete with a note.
> 2. **The stripping is not happening**, and third-party personal data is reaching DSAR exports.
>
> Determine which **by reading `filter()` and every per-platform `ALLOWLIST`**, and by checking whether
> `reviews` / `reviewSummary` / `organiser` / `venue` can appear in an export today. Write a test that
> proves it either way — an export fixture containing those keys, asserting they are absent.
>
> Cross-reference the existing open finding `271-PRIV-2` (Google reviewer PII in DSAR) before
> concluding: this may be the same gap, already known.
>
> **Then STOP and report.** If the answer is (2), it is a privacy finding, not a lint fix — it goes
> down the audit path with sign-off, not into this branch. Under `fix-flow.md`'s blocker gate,
> anything touching privacy/PII pauses for Josh regardless of how small the diff looks.
>
> ### Unit 4 — annotation precision (7 errors) — LOWEST VALUE, EASIEST TO GET WRONG
>
> ```
> app/Http/Controllers/Api/Platforms/EventsController.php:32  ignore.count (x2)
> app/Http/Controllers/Api/Platforms/EventsController.php:79  nullCoalesce.offset
> app/Http/Controllers/Api/Platforms/EventsController.php:80  nullCoalesce.offset (x2)
> app/Services/Platforms/EventsCatalog.php:239  function.alreadyNarrowedType  (is_string)
> app/Services/Platforms/EventsCatalog.php:247  function.alreadyNarrowedType  (is_array)
> ```
>
> The `EventsController` errors say `$result['ok'] ?? false` is redundant because
> `EventsCatalog::reorder()`'s return-type annotation guarantees the key, and the `*NEVER*` offsets say
> the annotation's shape makes the failure branch unreachable. The likely correct fix is **tightening
> `reorder()`'s return annotation** so the shape matches reality — not deleting the `??` defences.
>
> ⚠️ **`EventsCatalog:239/247` may be deliberate and must not be blindly deleted.** If those values
> originate in an external payload (an API response, a scraped body, a webhook), the runtime
> `is_string()` / `is_array()` check is CORRECT defence and the PHPDoc is optimistic. Removing a real
> guard to satisfy an analyser that is wrong about the world is the failure mode here — the same shape
> as unit 2. **Trace the value to its source before touching either line.** If it is externally
> sourced, fix the *annotation* (widen it to `mixed`), keep the check, and note it.
>
> If a unit-4 item cannot be resolved without guessing, baseline it with the reasoning written down.
> That is an acceptable outcome here; it is not for units 1–3.
>
> ---
>
> ### The baseline trap — read before editing `phpstan-baseline.neon`
>
> Adding a `@property` annotation or removing dead code **obsoletes the baseline entries that covered
> it**, and an unmatched baseline pattern is itself a build failure. This has now fired twice
> (`ShopBrand` on 07-25, `ContentSelection` on 07-30).
>
> So: after every unit, run `composer analyse` and prune entries that no longer match. Do not assume
> removing code lowers the count — it can raise it.
>
> Also watch `count:` drift: an entry saying `count: 4` fails when the pattern now hits 5 times. Two of
> unit 4's errors are exactly this.
>
> ### Verification (all of it, whole-repo, before you claim done)
>
> ```bash
> composer analyse                       # target: 0 errors
> vendor/bin/pint --test                 # WHOLE REPO, not just changed files
> php artisan checkpoint:scan            # must stay 0 failed (WARN is fine)
> php artisan test --filter=OutboundHttpGuardTest
> php artisan test tests/Feature/Platforms tests/Feature/Analytics
> ```
>
> Two known traps:
> - **`composer test` currently dies before running any tests.** It runs
>   `guard:no-unsafe-migrations` *before* Pest, and that guard is red on `development` for a reason
>   that is not yours (`20260730090000` — already fixed on `audit-fix/p0-launch-2026-07-30`, unmerged).
>   Use `php artisan test <paths>` directly. A red `Run tests` step is NOT evidence about your change.
> - **Adding annotations often trips `pint` (`fully_qualified_strict_types`).** Run whole-repo
>   `pint --test` and fix, then re-run `composer analyse` — pint rewrites docblocks, so re-verify after.
>
> ### Definition of done
>
> - Units 1, 2, 4 complete; `composer analyse` reports **0 errors**; pint clean whole-repo.
> - Unit 3 **investigated and reported, not fixed** — with a test proving which of the two cases holds.
> - Commit message states, per unit, what was fixed vs baselined **and why**. A baselined error with no
>   stated reason is not done.
> - Report which of the 17 turned out to be real bugs vs analyser artifacts — that ratio is the useful
>   output, and it tells us whether the next standing-gate cleanup deserves this much care.
>
> Do not merge or push. Report back with the diff and the unit-3 finding for sign-off.

---

## Notes for whoever pastes this

- **Do not bundle unit 3 into the merge.** If it turns out to be a live DSAR gap it needs its own
  branch, its own review, and probably an audit entry — see `feedback_production_push_care_and_audit_files`.
- Expect the error count to have drifted. Four sessions merged into `development` on 2026-07-30 alone;
  the count was 21 that morning and 17 by evening, and the composition changed as well as the number.
- The ratio this produces is worth recording. Today's evidence: of 17 errors, **6 were real dead code,
  3 were provable false positives, 1 was a possible privacy gap, and 7 were noise.** A blanket baseline
  would have discarded all four categories as if they were the last one.
