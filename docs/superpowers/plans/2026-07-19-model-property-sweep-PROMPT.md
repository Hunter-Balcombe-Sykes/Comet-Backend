# Model `@property` Sweep — Orchestrator Prompt (2026-07-19)

> Paste this whole file as the opening prompt to an **Opus** session. Opus plans and
> orchestrates only — it does not edit code itself. **Sonnet** subagents implement and
> review (separate instances); **Haiku** subagents do read-only exploration. One task
> per subagent. Always set the model explicitly on dispatch — subagents inherit Opus
> otherwise, and an Opus fan-out has blown the session limit before.

---

## Mission

The PHPStan level 5 gate is live and green at 0 errors (shipped 2026-07-19, `f25b5283`).
But `phpstan-baseline.neon` still suppresses **1,682 findings across 1,309 entries**, and
**1,449 of those — 86% — are a single identifier: `property.notFound`.**

They are not 1,449 problems. They are **one** problem with **one** structural fix:

- **50 of 55 model classes** under `app/Models/` have **no `@property` block at all**.
- So PHPStan cannot type any property access on them, and reports
  `Access to an undefined property X::$y` in every file that touches a model.
- Those findings surface in **263 consumer files** (Resources, Controllers, Services, Jobs)
  — but the fix belongs in the **~50 model files**.

Add truthful `@property` blocks to the models, delete the baseline entries that go stale as
a result, and a large majority of the suppression list dissolves.

**Why bother:** every suppressed `property.notFound` is a place the analyser is blind. A
genuine typo (`$user->hadnle`) or a column removed by a migration reads exactly like the
1,449 entries we chose to ignore. Paying this down converts a blanket blind spot into real
coverage — this is the follow-up an independent review recommended at merge.

**Definition of done (all five):**
1. `composer analyse` still exits 0.
2. `composer test` fully green (run alone — never concurrently with a subagent that also
   runs tests; parallel Pest runs corrupt each other's SQLite state).
3. `vendor/bin/pint --test` clean on every file touched (run pint only on changed files,
   never repo-wide).
4. Total suppressed findings in `phpstan-baseline.neon` **materially reduced** — report the
   before/after numbers. Measure with:
   ```bash
   php -r '$t=0; foreach(file("phpstan-baseline.neon") as $l){ if(preg_match("/^\s*count:\s*(\d+)/",$l,$m)) $t+=(int)$m[1]; } echo "$t\n";'
   ```
   Current value: **1682**. Entry count: **1309**.
5. Every `@property` line added is **verifiably true** against real Postgres DDL *and* the
   model's `$casts`. A wrong annotation is worse than no annotation — see below.

---

## The one thing most likely to go wrong

**`$casts` overrides the DDL type. Generating from column types alone produces confidently
wrong annotations.**

The last run spent most of its effort undoing exactly that failure — four separate
annotations in this codebase were lying (`User::$auth_user_id`, `SafeUrlFetcher` `@throws`,
`ShopController` `@param`, `BaseTransactionalMail` `self`). Do not add 1,449 new opportunities
to repeat it.

For every property, the declared type must be what PHP **actually hands you at runtime**:

| DDL / cast | Correct `@property` |
|---|---|
| `uuid`, `text`, `varchar` NOT NULL | `string` |
| same, but `DROP NOT NULL` / nullable | `string\|null` |
| `timestamptz` + `$casts` datetime (or `created_at`/`updated_at`) | `\Illuminate\Support\Carbon\|null` |
| `jsonb` + `$casts => 'array'` | `array<string, mixed>\|null` (state the real shape if known) |
| `text` + `$casts` to an enum | that enum class, e.g. `AccountType\|null` |
| `boolean` + `$casts => 'bool'` | `bool` |
| `integer` / `bigint` | `int` (`\|null` if nullable) |
| `numeric` / `decimal` + cast | `float` or `string` — **check the cast**, Laravel returns string for uncast decimals |
| relations | `@property-read` with the concrete class / `Collection<int, X>` |

Rules:
- **Nullability comes from the real Postgres DDL in `supabase/migrations/`, NOT from the
  SQLite test schema** — they drift, and that drift has caused production 500s twice.
- Use `@property-read` for relations and accessors; plain `@property` for real columns.
- If a column's true type is genuinely ambiguous, **leave it out and say so** rather than
  guessing. A missing annotation costs one baseline entry; a wrong one costs a bug.

### Getting the column types

`php artisan ide-helper:models` **will not work** — `barryvdh/laravel-ide-helper` is not
installed, and this machine's local `.env` `DB_HOST` points at a dead Supabase ref, so local
`artisan`/`tinker` has no database.

Use one of these instead:
1. **Supabase MCP `execute_sql` against the dev ref `glncumufgaqcmqhzwrxm`** (preferred —
   fast, authoritative):
   ```sql
   select table_schema, table_name, column_name, data_type, is_nullable, column_default
   from information_schema.columns
   where table_schema in ('core','site','notifications','analytics','audit','public')
   order by table_schema, table_name, ordinal_position;
   ```
2. `supabase/migrations/` DDL directly (the repo source of truth — good for
   cross-checking a `DROP NOT NULL` or a CHECK constraint).
3. `cloud tinker development --code='...'` if runtime introspection is genuinely needed.

Cross-check every model's `$casts`, `$dates`, `$appends` and accessors in the class itself —
the DB is only half the answer.

---

## Hard rules

- **NEVER run `composer analyse:baseline`** (wholesale regeneration). Delete stale entries
  by hand.
- **`reportUnmatchedIgnoredErrors` is unset in `phpstan.neon`, so it defaults TRUE.** Every
  baseline entry asserts its error *still exists*. As your annotations take effect, entries
  go stale and PHPStan fails with *"Ignored error pattern ... was not matched"*.
  **That is the success signal of this entire task** — delete those entries. Never weaken an
  annotation to keep a stale entry matching.
- **Never create Laravel migration files.** Zero schema changes — this task is annotations
  only. A composer guard rejects them anyway.
- Commit per work unit. Before each commit run `git diff --cached --stat` and verify the file
  list is exactly your unit's files. Stage **explicit paths** — never `git add -A`; the shared
  checkout regularly holds other sessions' work.
- **Never push without permission.**
- If an annotation surfaces a *new* finding (not a stale-baseline error), that is real signal
  — a place code accesses something that genuinely isn't there. **Triage it honestly and
  report it; it may be a real bug.** Do not paper over it.
- If a unit balloons (a model needs code changes, not just annotations), STOP that unit and
  surface it rather than pressing on.

## Do NOT undo these — deliberate decisions from the previous run

Read `.superpowers/sdd/phpstan-investigation-findings.md` before starting. In particular:
- `EmailReuseGuard::isClaimedByAnotherAuthUser()` carries `@phpstan-impure`. Removing it makes
  PHPStan flag the post-`23505` race backstop in `ClaimSiteService` as dead code. **Do not
  remove the tag or the re-check.**
- 11 dead-condition entries were baselined **rather than deleted**, on purpose — cheap runtime
  guards whose narrowing depends on annotations that proved unreliable.
- 15 `argument.unresolvableType` on `Collection<int, Model>::map()` are a proven Larastan
  limitation; four fix theories were tested and rejected. Leave them baselined.
- `unclaimed` is a first-class `core.users` status. `auth_user_id` and `primary_email` are
  **NULL** for those rows — annotations must reflect that. See the "Pre-account (site-first)
  signup" section of `CLAUDE.md`.

---

## Suggested work units

Baseline the numbers first with your own measurement run, then batch **by model**, highest
suppression payoff first. Do not attempt all 50 models in one unit — the previous run showed
large units burn context and produce unverified claims.

The heaviest consumer files (these tell you which models matter most):

| Suppressed | Consumer file |
|---|---|
| 50 | `app/Services/PublicSite/SitepageDataResolverService.php` |
| 34 | `app/Services/Platforms/MenuPayloadComposer.php` |
| 31 | `app/Http/Resources/UserStaffResource.php` |
| 28 | `app/Services/Platforms/IdentitySync.php` |
| 27 | `app/Http/Resources/UserDashboardResource.php` |
| 25 | `app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php` |
| 22 | `app/Services/Site/ContentSelectionService.php` |
| 20 | `app/Http/Controllers/Api/PublicSite/PublicMenuController.php` |

Proposed batching (adjust once you have real data):
- **M1** — `core.users` + `core.partna_staff` (`User`, staff models). Highest blast radius;
  `User` alone feeds most Resources. Do this first and validate the whole approach on it
  before batching further.
- **M2** — `site.sites`, `site.design_kits`, custom-domain models.
- **M3** — menu domain (`Menu`, `MenuCategory`, `MenuItem`, `MenuItemPlatform`, `ShopBrand`,
  `ShopProduct`).
- **M4** — `site.integration_connections` + platform models.
- **M5** — media, enquiries, customers.
- **M6** — analytics + notifications + audit models.
- **M7** — the remaining tail; then a final sweep deleting every entry left stale.

After **M1**, stop and report: the actual reduction achieved, any new findings surfaced, and
whether the per-model cost matches the estimate. That result should drive whether the
remaining batches are worth the same treatment or whether the tail should stay baselined.

## Other identifiers, once `property.notFound` is done

Only if they turn out to be cheap — they are 233 findings combined, versus 1,449:
`nullCoalesce.offset` (56), `nullsafe.neverNull` (38), `argument.type` (24),
`arrayValues.list` (12), `function.alreadyNarrowedType` (11), `method.notFound` (8).
Several of these are *caused* by the missing `@property` (PHPStan cannot see nullability, so
`?->` looks redundant or `??` looks always-set). **Re-measure after M1 — some will clear for
free, and some will flip to newly-visible real findings.** Do not plan them in detail up front.

## Reporting

Maintain a running ledger (unit → models annotated → baseline entries deleted → suppressed
count before/after → commit SHA), and keep it in a file, not just in conversation — the
previous run hit a session token limit mid-flight and the ledger was what made recovery
clean. At the end: final `composer analyse` and `composer test` output, the before/after
suppression numbers, and every new finding surfaced with its triage.
