# PHPStan `composer analyse` Gate Repair — Orchestrator Prompt (2026-07-21)

> Paste this whole file as the opening prompt to an **Opus** session. Opus plans and
> orchestrates only — it does not edit code itself. **Sonnet** subagents implement and
> review (separate instances); **Haiku** subagents do read-only exploration. One work unit
> per subagent. Always set the model explicitly on dispatch — subagents inherit Opus
> otherwise, and an Opus fan-out has blown the session limit before.
>
> **This runs in an isolated git worktree, in parallel with other active work**
> (platform write-path locking, gate-A DISC items). Do the **Setup — isolated worktree**
> section below **first**, before anything else. Because `development` moves underneath you,
> the "98" figures in this doc are a **snapshot from 2026-07-21** — re-measure at the start
> and reconcile again at merge time (see **Merging back**). Branch:
> `chore/phpstan-analyse-gate-repair-2026-07-21`.

---

## Mission

`composer analyse` **fails on `development`** (verified 2026-07-21). It fails for two layered
reasons, and the second is far larger than it first appears:

1. **Stale baseline paths abort the whole run.** `phpstan-baseline.neon` has `ignoreErrors`
   entries whose `path:` points at **8 files that no longer exist** in the branch (a
   website-scan / design feature was partially reverted, its baseline entries outlived its
   files). Because `reportUnmatchedIgnoredErrors` defaults **TRUE**, PHPStan refuses to run
   at all — it prints `Path "…" is neither a directory, nor a file path…` and exits 1
   **before analysing anything**. So today you cannot even see reason 2.

2. **98 genuine un-baselined findings hide behind reason 1.** Recent merges
   (`signup-flows` / `early-access`, `UserService`/`Service`, `WebsiteScan`, the Fresha
   service projector, confirmation-preferences) landed while the gate was already red, so
   their PHPStan debt was never triaged or baselined. Once the stale paths are removed, the
   run completes and reports **98 errors**.

**This is bigger than "regenerate the baseline + annotate EarlyAccessSignup."** That was the
original (undercounted) framing. The real work is: unblock the run, then triage 98 findings —
most of which are one structural fix (models missing `@property` blocks).

### The 98, measured (2026-07-21)

By identifier:

| Count | Identifier | Nature |
|---|---|---|
| 55 | `property.notFound` | models with no `@property` block — **annotate the model** |
| 19 | `method.notFound` | 16× `DOMNode::getAttribute()` (library typing) + 3× `Model::categories()` |
| 8 | `nullCoalesce.offset` | redundant `?? default` on always-present array offsets |
| 7 | `function.alreadyNarrowedType` | `is_array()`/guards PHPStan proves always-true |
| 3 | `argument.type` | |
| 2 | `ignore.count` | stale inline `@phpstan-ignore` counts |
| 2 | `nullsafe.neverNull` | redundant `?->` |
| 1 | `return.type` | |
| 1 | `arrayValues.list` | |

The 55 `property.notFound`, by class (this is where the leverage is):

| Count | Class | Flagged properties | State |
|---|---|---|---|
| 34 | `App\Models\Core\User\Service` (`site.services`) | `$is_manual`, `$source`, `$deleted_origin`, `$external_id`, `$user_id`, `$currency_code`, `$price_cents`, `$is_active`, `$title`, `$duration_minutes`, `$description` | **no `@property` block** |
| 6 | `App\Models\Core\User\ServiceCategory` | `$title`, `$user_id` | **no `@property` block** |
| 4 | `App\Http\Resources\ServiceResource` | `$categories`, `$source`, `$is_manual`, `$external_id` | Resource — needs **`@mixin`**, not `@property` |
| 4 | `App\Models\Core\User\UserConfirmationPreference` | `$user_id`, `$skip_confirmation` | **no `@property` block** |
| 3 | `App\Models\Core\Site\MenuCategory` | `$source_platform`, `$name` | existing model, no block |
| 3 | `App\Models\Core\EarlyAccess\EarlyAccessSignup` | `$user_id` | block below; older props already baselined |
| 1 | `App\Models\Core\User\Customer` | `$user_id` | existing model, no block |

`method.notFound` → `Model::categories()` (3) is the `ServiceCategory` relation seen through a
base-`Model` type; it resolves once `Service`/`ServiceResource` are annotated.

**Definition of done (all six):**
1. `composer analyse` exits **0**.
2. `composer test` fully green — run it **alone**, never concurrently with a subagent that
   also runs tests (parallel Pest runs corrupt each other's SQLite state).
3. `vendor/bin/pint --test` clean on every file touched (run pint only on **changed files**,
   never repo-wide — it churns the baseline otherwise).
4. Every `@property` line added is **verifiably true** against real Postgres DDL *and* the
   model's `$casts`. A wrong annotation is worse than no annotation.
5. The 8 stale-path baseline blocks are **deleted by hand** (not regenerated away). No
   wholesale `composer analyse:baseline` was run (see Hard rules).
6. Every one of the 98 is accounted for in the final ledger: *annotated-away* /
   *hand-baselined as documented library-typing debt* / *real bug fixed*. Any finding that
   turns out to be a genuine bug (a property or method that truly does not exist at runtime)
   is triaged honestly and reported — **never papered over with a baseline entry.**

---

## Your diagnostic tool — use it to measure at every step

You cannot see the 98 while the stale paths abort the run, and after that you want to watch
the residual shrink. Use a **throwaway temp config** that includes the real baseline but flips
`reportUnmatchedIgnoredErrors` off — this shows **only genuine un-baselined errors**, without
editing `phpstan-baseline.neon`:

```bash
cat > phpstan-diag.neon <<'NEON'
includes:
    - vendor/larastan/larastan/extension.neon
    - phpstan-baseline.neon
parameters:
    level: 5
    paths:
        - app
    reportUnmatchedIgnoredErrors: false
NEON
vendor/bin/phpstan analyse -c phpstan-diag.neon --memory-limit=2G --no-progress --error-format=json 2>/dev/null > diag.json
rm -f phpstan-diag.neon
# Summarise:
python3 - <<'PY'
import json
from collections import Counter
d=json.load(open('diag.json')); ident=Counter()
for f in d['files'].values():
    for m in f['messages']: ident[m.get('identifier','?')]+=1
print('TOTAL', d['totals']['file_errors'])
for k,v in ident.most_common(): print(f'{v:4d}  {k}')
PY
```

Baseline: **98** total as measured 2026-07-21 (with the stale paths ignored by the `false`
flag). **Re-run this at the very start** — the count and the exact classes may have shifted if
another branch merged to `development` since then; trust your fresh number, not 98. Drive it
to 0 with annotations + deletions + documented hand-baselines. **Never leave `phpstan-diag.neon`
committed** — it is a scratch file.

---

## Getting column types (do NOT guess)

`php artisan ide-helper:models` will not work here (`barryvdh/laravel-ide-helper` is not
installed, and this machine's local `.env` `DB_HOST` points at a dead Supabase ref, so local
`artisan`/`tinker` has no database).

Use, in order of preference:
1. **Supabase MCP `execute_sql` against the dev ref `glncumufgaqcmqhzwrxm`** (authoritative):
   ```sql
   select table_schema, table_name, column_name, data_type, is_nullable, column_default
   from information_schema.columns
   where (table_schema, table_name) in
     (('site','services'), ('site','service_categories'),
      ('core','user_confirmation_preferences'), ('site','menu_categories'),
      ('site','customers'), ('core','early_access_signups'))
   order by table_schema, table_name, ordinal_position;
   ```
   (Confirm the real table names first — read each model's `$table`.)
2. `supabase/migrations/*.sql` DDL directly (repo source of truth; good for a `DROP NOT NULL`
   or CHECK).
3. `cloud tinker development --code='…'` if runtime introspection is genuinely needed.

**Cross-check every model's `$casts`, `$dates`, `$appends` and accessors in the class itself —
`$casts` overrides the DDL type.** Nullability comes from the **real Postgres DDL**, not the
SQLite test schema (they drift, and that drift has caused production 500s).

Type-mapping reference:

| DDL / cast | Correct `@property` |
|---|---|
| `uuid` / `text` / `varchar` NOT NULL | `string` |
| same, nullable | `string\|null` |
| `timestamptz` + `$casts` datetime (or `created_at`/`updated_at`) | `\Illuminate\Support\Carbon\|null` |
| `jsonb` + `$casts => 'array'`, nullable | `array<string, mixed>\|null` (state the real shape if known) |
| `jsonb` NOT NULL list + `$casts => 'array'` | `array<int, string>` |
| `text` + `$casts` to an enum | that enum class, e.g. `AccountType\|null` |
| `boolean` + `$casts => 'bool'` | `bool` |
| `integer` / `bigint` (+ `$casts => 'integer'`) | `int` (`\|null` if nullable) |
| `numeric` / `decimal` | `float` or `string` — **check the cast**; uncast decimals come back as `string` |
| relations | `@property-read` with the concrete class / `Collection<int, X>` |

---

## Hard rules

- **NEVER run `composer analyse:baseline`** (wholesale regeneration). It would suppress any
  real bug hiding among the 98, reformat the entire file so review is impossible, and defeat
  the point of the gate. Delete stale entries and add any new baseline entries **by hand**.
  This overrides the loose "regenerate the baseline" phrasing in the original ask — the
  established doctrine (`docs/superpowers/plans/2026-07-19-model-property-sweep-PROMPT.md`,
  memory `phpstan-baseline-unmatched`) is surgical edits only.
- **`reportUnmatchedIgnoredErrors` is unset in `phpstan.neon`, so it defaults TRUE.** Every
  baseline entry asserts its error *still exists*. The moment you add a truthful `@property`
  block to a model that already has baselined property accesses (e.g. `EarlyAccessSignup`),
  those entries go stale and the real `composer analyse` fails with *"Ignored error pattern …
  was not matched"*. **That is the success signal** — delete every stale entry **in the same
  unit** as the annotation that killed it. Never weaken an annotation to keep a stale entry
  matching.
- **Resources get `@mixin`, not `@property`.** `EarlyAccessSignupResource` and `ServiceResource`
  proxy attribute access to the underlying model via `__get`/`getAttribute`. The fix is
  `@mixin \App\Models\…\<Model>` on the resource class, which also resolves the
  `Resource::getAttribute()` `method.notFound`. Adding `@property` to a Resource is the wrong
  tool (this cost the 2026-07-20 sweep ~24% rework — memory `model-property-sweep-shipped`).
- **Never create Laravel migration files.** Zero schema changes — this task is annotations and
  baseline edits only. A composer guard rejects migrations anyway.
- **Commit per work unit.** Before each commit run `git diff --cached --stat` and verify the
  file list is exactly your unit's files. Stage **explicit paths** — never `git add -A`; the
  shared checkout regularly holds other sessions' work.
- **Never push without permission.**
- If an annotation surfaces a **new** finding (code accesses something that genuinely isn't
  there), that is real signal — **triage it honestly and report it; it may be a real bug.** Do
  not suppress it.
- If a unit balloons (a model needs code changes, not just annotations), **STOP** that unit and
  surface it rather than pressing on.

---

## Setup — isolated worktree (do this first, before U0)

Create a dedicated worktree **outside** the repo tree and give it its own `vendor` + `.env`.
Repo-specific gotchas are baked into the commands below — follow them rather than a generic
worktree helper:
- The worktree must **not** live under `.claude/worktrees/` — a worktree nested inside the repo
  poisons the main checkout's optimized-autoload classmap (edits stop "taking effect"). Put it
  at a **sibling** path.
- **Do not symlink** `vendor` or `.env` into the worktree. A real `composer install` + a real
  `.env` copy runs the full suite green; symlinked `vendor`/`.env` is the one thing that breaks
  feature tests in a worktree.

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
git fetch origin

# Sibling worktree, branched off the freshly-fetched development:
git worktree add -b chore/phpstan-analyse-gate-repair-2026-07-21 \
    "../partna-phpstan-gate" origin/development
cd "../partna-phpstan-gate"

composer install                                              # real install, not a symlink
cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env   # real copy
composer dump-autoload -o                                     # clean optimized autoloader

# Sanity — the gate aborts today on the 8 stale paths; confirm you inherited that state:
composer analyse 2>&1 | grep -c "is neither a directory"      # expect > 0 (U0 fixes it)
```

Notes:
- `.env`'s `DB_HOST` points at a dead ref — irrelevant here. `composer analyse` boots the
  framework but queries no DB, and the test suite uses in-memory SQLite. Neither needs a live DB.
- This machine runs PHP 8.4 while the project targets 8.2 — PCRE2 drift can make CI-green code
  fail locally (and vice-versa). If a failure looks regex/PCRE-shaped, confirm it reproduces
  under the project's PHP before treating it as real.
- All subagent dispatches and every command below run **inside** `../partna-phpstan-gate`.
- Commit each unit immediately (a concurrent merge elsewhere can silently revert uncommitted
  work — the worktree isolates the checkout, not your discipline).

---

## Work units — highest payoff first

Re-measure with the diagnostic tool after each unit. Order is by leverage; U0 must go first
(nothing else can be verified until the run stops aborting).

### U0 — Unblock: delete the 8 stale-path baseline blocks

These 8 files are referenced by `path:` in `phpstan-baseline.neon` but do not exist in the
branch. Delete **every `ignoreErrors` block** whose `path:` points at one of them (~15 blocks
total — some files have multiple):

```
app/Console/Commands/ScanWebsiteCommand.php
app/Console/Commands/BackfillWebsiteAnalysesCommand.php
app/Jobs/Design/AnalyzeConnectionWebsitesJob.php
app/Jobs/Design/AnalyzePreviousWebsiteJob.php
app/Services/Design/Presets/Factors/PreviousWebsiteFactor.php
app/Services/Design/Presets/Factors/OutsideWebsitesFactor.php
app/Services/Design/Scan/EvidenceConclusions.php
app/Services/Design/Scan/ScreenshotSampler.php
```

Verify none remain, and that the run now **executes** (it will still report the 98 — that is
expected and good; it is no longer *aborting*):

```bash
# should print nothing:
for p in ScanWebsiteCommand BackfillWebsiteAnalysesCommand AnalyzeConnectionWebsitesJob \
         AnalyzePreviousWebsiteJob PreviousWebsiteFactor OutsideWebsitesFactor \
         Scan/EvidenceConclusions Scan/ScreenshotSampler; do
  grep -n "$p" phpstan-baseline.neon
done
composer analyse 2>&1 | grep -c "is neither a directory"   # must be 0
```

**Caution:** several *surviving* Design/website-scan files (e.g.
`app/Services/Design/Presets/DesignPresetResolver.php`, `LogoAutoGrabber.php`,
`app/Services/Platforms/WebsiteLinkHarvester.php`, `MenuScanApplier.php`) still exist — their
baseline entries are **valid** and must be **kept**. Delete only blocks pointing at the 8
missing files above. Commit U0 alone.

### U1 — `Service` + `ServiceCategory` + `ServiceResource` (44 findings + the 3 `categories()`)

Biggest single win. All three are new and have **no `@property`/`@mixin` block**, so nothing is
baselined for them — annotating adds pure coverage and deletes nothing.

- `app/Models/Core/User/Service.php` (`site.services`): add a full truthful `@property` block.
  Known `$casts`: `is_active`→bool, `price_cents`→int, `sort_order`→int,
  `duration_minutes`→int, `is_manual`→bool, `deleted_at`→datetime. Derive nullability of
  `$source`, `$deleted_origin`, `$external_id`, `$user_id`, `$currency_code`, `$title`,
  `$description` from `site.services` DDL. Annotate the `categories()` relation as
  `@property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Core\User\ServiceCategory> $categories`
  (and give the relation method a real return type if it lacks one — this clears the 3
  `Model::categories()` `method.notFound`).
- `app/Models/Core/User/ServiceCategory.php`: full `@property` block from
  `site.service_categories` DDL (`$title`, `$user_id`, + the rest).
- `app/Http/Resources/ServiceResource.php`: add `@mixin \App\Models\Core\User\Service` (do
  **not** add `@property`). This resolves `$source`/`$is_manual`/`$external_id`/`$categories`
  on the resource.

Re-measure; commit.

### U2 — `UserConfirmationPreference` (4)

`app/Models/Core/User/UserConfirmationPreference.php` — new model, no block. Full `@property`
block from `core.user_confirmation_preferences` DDL (confirm the table name from `$table`);
at minimum `$user_id`, `$skip_confirmation`. This clears the 4 in `ConfirmationPreferenceService`.
Commit.

### U3 — `EarlyAccessSignup` + its Resource (the originally-named work)

Unlike U1/U2 this model **already has ~11 baselined property entries** plus a ~10-entry
`EarlyAccessSignupResource` cluster. Adding the block below will unmatch all of them — you
**must delete them in this same unit**.

Add exactly this block to `app/Models/Core/EarlyAccess/EarlyAccessSignup.php` (verified
against `20260711000300_early_access_signups.sql` + `20260721120000_signup_flows_early_access.sql`
+ the model's `$casts`):

```php
/**
 * @property string $id
 * @property string $email
 * @property string $email_lc
 * @property string $type
 * @property string|null $workplace_or_industry
 * @property array<int, string> $platforms
 * @property string $status
 * @property string $source
 * @property \Illuminate\Support\Carbon|null $invited_at
 * @property string|null $invite_token_hash
 * @property array<string, mixed>|null $invite_meta
 * @property string|null $invited_by
 * @property \Illuminate\Support\Carbon|null $signed_up_at
 * @property string|null $consent_ip_hash
 * @property string|null $consent_user_agent
 * @property string|null $source_type
 * @property string|null $source_ref
 * @property string|null $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
```

Then delete the now-stale baseline entries. Find them by class name (line numbers shift as you
edit — match on the message, not the line):

```bash
grep -n 'EarlyAccessSignup\\\\:\\\\:\\\$\|EarlyAccessSignupResource' phpstan-baseline.neon
```

Delete every `ignoreErrors` block whose message references
`App\Models\Core\EarlyAccess\EarlyAccessSignup::$…` (~11) and, after adding
`@mixin \App\Models\Core\EarlyAccess\EarlyAccessSignup` to
`app/Http/Resources/Staff/EarlyAccessSignupResource.php`, every block referencing
`EarlyAccessSignupResource::$…` and `EarlyAccessSignupResource::getAttribute()` (~10). Note the
partial fix the original ask mentioned (`@property $user_id`) is **not on `development`** — this
branch starts clean, so add the whole block, not just `$user_id`. Re-measure; commit.

### U4 — `MenuCategory` + `Customer` (4)

Existing models with no block. `MenuCategory` needs `$source_platform`, `$name`; `Customer`
needs `$user_id`. Prefer adding the **full** truthful block per DDL (durable), but at minimum
the flagged columns. These models may have **other** properties already baselined — if adding a
block unmatches any existing entry, delete that entry in this unit (run the diagnostic + a real
`composer analyse` to catch stale entries). Commit.

### U5 — `WebsiteScan` library-typing debt (`DOMNode::getAttribute()` ×16 + `nullCoalesce.offset` ×8)

`app/Services/WebsiteScan/WebsiteLogoCandidateExtractor.php` (14) and `FaviconFetcher.php` (2):
`DOMXPath`/`DOMNodeList` are typed as `DOMNode`, but `getAttribute()` lives on `DOMElement`.
This is **not a bug** — the nodes are elements at runtime. Two acceptable fixes:
- **Preferred if cheap:** narrow with `if ($node instanceof \DOMElement)` (or
  `assert($node instanceof \DOMElement)`) before `->getAttribute()`. Clears them as real
  coverage.
- **Otherwise:** hand-add baseline entries, each with a one-line comment noting it is
  DOM `DOMNode`-vs-`DOMElement` library-typing debt.

`nullCoalesce.offset` (8, in `FaviconFetcher` + `ScanPreviousWebsiteContentJob` +
`LinkInBioScanJob`): PHPStan proves the array offset always exists. Remove the redundant
`?? default` **only if** the offset is genuinely always present on every path (verify the
producing function's return shape); otherwise baseline. Do not change runtime behaviour to
satisfy the analyser. Commit.

### U6 — Residual tail, then green

`function.alreadyNarrowedType` (7 — `is_array()`/guards across `Platforms/*` + WebsiteScan),
`argument.type` (3), `ignore.count` (2), `nullsafe.neverNull` (2), `return.type` (1),
`arrayValues.list` (1).

Triage each honestly:
- `function.alreadyNarrowedType` on a **defensive runtime guard** → baseline it (precedent: the
  2026-07-19 run deliberately baselined 11 such guards rather than delete safe checks). If it is
  genuinely dead code, remove it.
- `ignore.count` → an inline `@phpstan-ignore` now covers fewer/more lines than stated; fix the
  count inline (not in the baseline).
- The rest → fix if the type is genuinely wrong (possible real bug — report it), else baseline
  with a reason comment.

Then confirm all six Definition-of-Done items. Final `composer analyse` must print **0 errors**
and exit 0. Commit.

---

## Reporting

Maintain a running ledger **in a file** (not just in conversation — the 2026-07-19 run hit a
session token limit mid-flight and the file ledger is what made recovery clean):

```
unit → files touched → annotations added → baseline entries deleted → baseline entries added
     → residual count (diagnostic) before/after → commit SHA
```

At the end, report: final `composer analyse` output (0 errors), `composer test` result, the
98→0 breakdown (annotated-away / hand-baselined-as-documented-debt / real-bugs-fixed), the 8
stale-path blocks removed, and **every genuine bug surfaced** with its triage. Confirm
`phpstan-diag.neon` and `diag.json` were removed and are not committed.

---

## Merging back — reconcile against a moving `development` (do NOT skip)

You ran in parallel, so `development` advanced while you worked. **A conflict-free textual merge
is not proof of a green gate here** — the analyser and the test suite are the real checks. Before
merging:

1. **Everything committed.** `git status --short` in the worktree is clean (per-unit commits
   already done). Stage explicit paths only — never `git add -A`.
2. **Integrate the latest `development` INTO the branch** (do this in the worktree):
   ```bash
   git fetch origin
   git rebase origin/development        # or: git merge origin/development
   ```
3. **Resolve conflicts.** Realistic surfaces, in order of likelihood:
   - `phpstan-baseline.neon` — the collision file. Platform write-path-locking does **not**
     touch it; the gate-A DISC work might. Take both sides' intent, then let step 4 prove it —
     do not hand-pick which entries survive by eyeball.
   - A model/Resource you annotated that another branch also edited — unlikely (your models
     aren't in the other streams' scope), but check.
4. **Re-run the gate on the MERGED result** — this is the real reconciliation:
   ```bash
   composer analyse            # MUST print 0 errors
   ```
   If it is red after the merge, `development` gained findings while you worked. Triage by
   origin:
   - **A file another branch deleted** that your baseline still references → new stale-path
     abort → delete that baseline block (same mechanical fix as U0).
   - **A new un-baselined finding from another branch's code** → it is *theirs*, not yours. If
     it is a cheap model `@property` you can add truthfully, do it and note it in the ledger. If
     it is substantial, **do not silently absorb it** — surface it to Josh: *"development gained
     N new analyse findings from &lt;branch&gt; after I finished; fold into this branch, or hand
     back?"* Getting *their* new debt to green is a scope decision, not a default.
5. **Re-run the full suite on the merged result** — a conflict-free merge has broken 15 tests
   here before:
   ```bash
   composer test               # green
   ```
6. **`pint --test` on your changed files only** — clean (never repo-wide).
7. **Merge to `development` (with permission), then tear down** — one atomic cleanup:
   ```bash
   # after the merge lands on development:
   cd "/Users/joshuahunter/Herd/Side Street/backend"
   git worktree remove "../partna-phpstan-gate"
   git branch -d chore/phpstan-analyse-gate-repair-2026-07-21   # add -r/push :branch if pushed
   ```
   Also run `composer dump-autoload -o` from the main checkout once the worktree is gone, in
   case its classmap picked anything up.
