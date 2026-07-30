# Harden the tests/Postgres lane-opt-in walker — execution prompt

Fixes three gaps in `fileHasRealUsesPostgresTestCaseCall()` (added `f2f4e788`, live on
`origin/development`), the token walker that holds the `tests/Postgres/` path exclusion in
`NoLocalCanonicalTableDdlTest` honest. All three were found by review; **none is failing CI today** —
this is pre-emptive, and the whole change is ~10 lines in one function plus in-file fixtures.

Size: S. Model: Sonnet. Solo. Nothing to deploy, no migration, no prod involvement.

Two of the three gaps are false-**positive** risks (guard goes red on a file that is correctly on the
lane); one is a false-**negative** (guard goes green on a file that is not). Reachability analysis
(`if (false) { uses(...) }`) is explicitly **out of scope** — see the paste block.

---

Harden the token walker that guards the `tests/Postgres/` path exclusion. Small, self-contained
change to one function.

**PRECONDITION — pull first.** Local `development` is ~22 commits behind `origin/development` and the
function you are editing does **not exist** in the local tree. Start with
`git fetch origin && git checkout -b guard/postgres-lane-walker origin/development`. If
`grep -c fileHasRealUsesPostgresTestCaseCall tests/Feature/Architecture/NoLocalCanonicalTableDdlTest.php`
returns 0, you are on the wrong base — stop and re-check.

**FILE:** `tests/Feature/Architecture/NoLocalCanonicalTableDdlTest.php`, the top-level function
`fileHasRealUsesPostgresTestCaseCall(string $contents): bool`. Do not touch the other assertion in
that file (`no test file builds local DDL...`) or the baseline JSON.

Read the function and its docblock first. It exists because the previous check was
`str_contains($contents, 'PostgresTestCase::class')`, which passed on prose — there are 8 comment
lines under `tests/Postgres/` that mention the class. The token walker fixed that. These three gaps
remain.

## FIX 1 — accept qualified and fully-qualified name tokens (false positive)

The walker matches only `T_STRING` whose value is exactly `'PostgresTestCase'`. PHP 8.0+ emits a
namespaced name as ONE atomic token, so both of these are invisible to it and the file is wrongly
reported as not opting into the lane:

```php
uses(\Tests\PostgresTestCase::class);   // T_NAME_FULLY_QUALIFIED('\Tests\PostgresTestCase')
uses(Tests\PostgresTestCase::class);    // T_NAME_QUALIFIED('Tests\PostgresTestCase')
```

Verified with `token_get_all()` on PHP 8.4.19. The second form is the one Pest's own docs and most
Laravel `tests/Pest.php` files use (`uses(Tests\TestCase::class)->in('Feature')`), so it is the likely
shape of a hand-written new file that wasn't copied from a sibling.

Widen the name predicate to accept `T_STRING`, `T_NAME_QUALIFIED`, `T_NAME_FULLY_QUALIFIED` and
`T_NAME_RELATIVE`, matching when the **last `\`-delimited segment** equals `PostgresTestCase`. A closure
like this is fine — do not overthink it:

```php
$isPostgresTestCaseName = fn ($t) => is_array($t)
    && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
    && substr(strrchr('\\'.$t[1], '\\'), 1) === 'PostgresTestCase';
```

Why this one is worth fixing despite low probability: the failure is a **false red**, and its message
(`"do not opt into the real-Postgres lane via uses(PostgresTestCase::class)"`) points at a file that
visibly does exactly that. The cheapest-looking escapes from that red are "move the file out of
`tests/Postgres/`" or "add it to the DDL baseline" — both are the precise drift the guard exists to
catch.

## FIX 2 — reject `uses` that is a method or static call (false positive/negative, cheap)

The walker never inspects the token BEFORE `uses`, so `Decoy::uses(PostgresTestCase::class)` and
`$builder->uses(PostgresTestCase::class)` both satisfy it. Reject when the preceding non-trivia token
is `T_DOUBLE_COLON`, `T_OBJECT_OPERATOR`, `T_NULLSAFE_OBJECT_OPERATOR`, or `T_FUNCTION`. Reuse the
existing `$skippable` array when scanning backwards.

## FIX 3 — require the binding to target this file (false NEGATIVE — the real hole)

The walker slices only the tokens INSIDE `uses(...)`, so everything chained after the closing paren is
invisible to it. This passes the guard while binding nothing to the file it sits in:

```php
uses(PostgresTestCase::class)->in('Feature');
```

That file then sits in `tests/Postgres/`, is excluded by path from the canonical-DDL scan, and still
executes on the SQLite stand-in — the exact failure the assertion was written to prevent. Unlike the
`if (false)` case below this needs no bad intent: copy the `uses()->in()` line from `tests/Pest.php`
instead of from a sibling and you are there.

Rule to implement: after the matching `)` of the `uses(` call, if the next non-trivia token is
`->`/`?->` followed by `T_STRING('in')` and `(`, then that `in(...)` argument list MUST contain a
`T_FILE` (`__FILE__`) token; otherwise this `uses()` call does not count. No chained `->in()` at all
still counts (a bare `uses(PostgresTestCase::class);` binds the enclosing file).

The current convention in all 33 files is `uses(PostgresTestCase::class)->in(__FILE__);` — that must
still pass. Verify that explicitly, it is the whole point.

## EXPLICITLY OUT OF SCOPE — do not implement

`if (false) { uses(PostgresTestCase::class); }` passes the walker. **Leave it.** Doing it properly
needs real reachability analysis and you would still lose to a `return;` above the call. The guard
defends the path exclusion against accidental drift and lazy dodges, not against an adversary: anyone
willing to write `if (false)` around a `uses()` call could more cheaply delete the assertion,
regenerate the DDL baseline, or just not put the file in `tests/Postgres/`. A bypass that is harder
than deleting the guard is not worth engineering against. Do not add partial/heuristic reachability
checks either — a half-check here reads as protection that isn't there.

## TRAPS

- **No fixture files.** Do NOT create `.php` fixture files under `tests/` to exercise the walker. The
  other assertion in this same file scans `tests/` recursively for canonical DDL, the assertion you
  are fixing scans `tests/Postgres/` recursively, and Pest will try to load stray `.php` files as
  tests. Assert against **inline heredoc strings** passed straight to
  `fileHasRealUsesPostgresTestCaseCall()`.
- **The function is a global symbol.** The file has no `namespace` declaration, and unnamespaced Pest
  files share one global symbol table — a second top-level `function` with a generic name will fatal
  the whole suite via redeclaration when another Pest file declares the same name. Keep the verbose
  existing name; if you add a helper, give it an equally specific name or inline it as a closure.
- **Never set `NO_LOCAL_DDL_BASELINE=1`** while verifying. That env var makes the test REWRITE
  `scripts/launch-check/no-local-canonical-ddl-baseline.json` instead of asserting. If that file shows
  up modified in `git status`, you ran it wrong — revert the file.
- **Pint on changed files only.** `vendor/bin/pint tests/Feature/Architecture/NoLocalCanonicalTableDdlTest.php`
  — do not run repo-wide Pint, it churns the baseline into an unreviewable diff.
- **Do not run the full suite** — it OOMs locally at 512M and is irrelevant here. Filter to the two
  test files named below.
- Update the function's docblock AND the file-header docblock (~line 30, "must opt into that lane via
  `uses(PostgresTestCase::class)`") to say which name forms and which `->in()` shapes now count.
  The header docblock is the load-bearing explanation of why the path exclusion is safe.

## PASS CRITERIA — all four, with output pasted into your report

1. `php artisan test --filter=NoLocalCanonicalTableDdlTest` — green. Both assertions in the file, not
   just the one you touched.
2. All 33 existing files still pass: the lane assertion reports an empty offender list. Confirm the
   count is 33 (`ls tests/Postgres/*.php | wc -l`) and that the walker returns true for a real one,
   e.g. `tests/Postgres/SourceIntentDomainTest.php`.
3. In-file fixture assertions covering, at minimum: bare form → true; `\Tests\` FQN → true;
   `Tests\` qualified → true; `->in(__FILE__)` chained → true; no `->in()` → true; comment-only
   mention → false; string-literal-only mention → false; `Decoy::uses(...)` → false;
   `->in('Feature')` chained → false.
4. `vendor/bin/pint --test tests/Feature/Architecture/NoLocalCanonicalTableDdlTest.php` — passed.
5. `git status --short` shows exactly ONE modified file. If the baseline JSON is modified, you tripped
   the trap above.

**DO NOT commit, push, or merge.** Leave the change in the working tree on the
`guard/postgres-lane-walker` branch and report the diff — Josh commits.
