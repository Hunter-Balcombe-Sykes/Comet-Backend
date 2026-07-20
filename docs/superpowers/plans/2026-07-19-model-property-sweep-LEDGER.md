# Model `@property` Sweep — Running Ledger

Branch: `chore/model-property-sweep` (off `origin/development`, level at `f25b5283`)
Started: 2026-07-20

## Baseline (measured before any change)

| Metric | Value |
|---|---|
| Total suppressed findings (`count:` sum) | **1682** |
| Baseline entries (`message:` blocks) | **1309** |
| `property.notFound` entries | 1104 |
| `property.notFound` findings attributed | 1441 |

## Where `property.notFound` actually lives — measured, not assumed

The orchestrator prompt assumed all 1,449 findings are fixed by annotating ~50 model files.
Measurement says otherwise:

| Owning class family | Findings | Fix |
|---|---|---|
| `App\Models\*` | **1042** (72%) | `@property` blocks on the model — the planned fix |
| `App\Http\Resources\*` | **351** (24%) | `@mixin <Model>` on the Resource — *different fix, not in the plan* |
| `Illuminate\Database\Eloquent\Model` + misc | 48 (3%) | generic `Model`-typed vars; likely stays baselined |

**Resource finding (new):** 63 Resource classes, **0** currently carry `@mixin`. Laravel's
`JsonResource::__get()` proxies to the wrapped model, so PHPStan reports `$this->handle` as
undefined *on the Resource*. A one-line `@mixin` per Resource resolves it — but only once the
wrapped model has a `@property` block. So this is a genuine phase 2 that depends on phase 1,
not an alternative to it.

## Heaviest owning models (drives batch order)

| Findings | Model |
|---|---|
| 234 | `Core\Site\IntegrationConnection` |
| 111 | `Core\Site\Site` |
| 95 | `Core\Site\SiteMedia` |
| 78 | `Core\Site\Block` |
| 55 | `Core\User\User` |
| 51 | `Core\Site\Workplace` |
| 47 | `Core\Notifications\EmailSubscription` |
| 43 | `Core\Site\MenuItem` |
| 32 | `Moderation\ModerationCase` |

Note: `User`, `Site`, `PreAccountBuild`, `MediaVariant`, `SupabaseEmailEvent` already have a
`@property` block — those blocks are **incomplete**, not absent (`User` still leaks 55 findings
across 23 properties).

## Units

| Unit | Models | Entries deleted | Suppressed before → after | Commit |
|---|---|---|---|---|
| M1 | `User`, `PartnaStaff` | 53 deleted / 4 added (net −49) | 1682 → **1618** (−64) | `f176260d` |
| M2–M4 | `IntegrationConnection`, `Site`, `PreAccountBuild`, `SiteMedia`, `Block`, `Workplace`, `EmailSubscription`, `MenuItem`, `ModerationCase` | 510 deleted / 31 added (net −479) | 1618 → **913** (−705) | _see commit_ |

**Running total: 1682 → 913 — a 46% reduction**, with the Resource `@mixin` pass still outstanding.

### M1 verification (all five completion criteria)

| # | Criterion | Result |
|---|---|---|
| 1 | `composer analyse` exits 0 | ✅ 884 files, 0 errors (confirmed on a cold cache) |
| 2 | `composer test` green | ✅ 4166 passed, 0 failed, 14503 assertions |
| 3 | `pint --test` clean on touched files | ✅ passed |
| 4 | Suppression materially reduced | ✅ 1682 → 1618 (−64 findings, −49 entries) |
| 5 | Every annotation verifiably true | ✅ independent reviewer verdict **PASS** |

Reviewer (separate Sonnet instance, adversarial brief) checked all 33 `User` columns and all 8
`PartnaStaff` columns individually against live DDL + `$casts` + relation methods, and grepped
every construction site to test the non-nullability claims rather than accept them. Found **no
nullability inversion** — the highest-severity error class for this task. One real defect found
and fixed: a dead `@see` migration path (typo'd filename, and the file had since been archived);
it was pre-existing at line 34 and got copied into the new block. Both now cite the live
`partna_staff_role_check` constraint in the consolidated baseline.

### M1 cost/benefit — drives the go/no-go on M2+

- **Benefit:** −64 suppressed findings for 2 models (~32/model, but `User` is the single
  highest-blast-radius model; the tail will be thinner).
- **Cost:** ~205k implementer tokens + ~134k reviewer tokens for 2 models.
- **Implication:** a naive 1-model-per-subagent fan-out across the remaining ~48 models would be
  very expensive for diminishing per-model return. Batch by **table family** (models sharing a
  DDL pull) rather than one-per-unit, and pull DDL once per batch instead of per model.

### Deliberate decisions honoured (not regressions)

The 4 **added** baseline entries are consistent with the previous run's standing policy
(`.superpowers/sdd/phpstan-investigation-findings.md` §R9): *"Default action is BASELINE with
justification, NOT deletion. Deleting runtime defensive guards on the strength of analyser
inference is precisely the change that bites when the inference rests on a bad annotation."*
All four carry provenance comments. They are narrowing consequences of the new annotations
(2× redundant `?->`/`??` on the now-non-null `account_type`, 1× `instanceof` on the now-typed
`Carbon|null` deletion timestamps), plus one genuine gap — see below.

### M2–M4 verification

Three independent adversarial reviewers, one per unit, each re-querying live DDL rather than
trusting the implementer. Verdicts: M2 **PASS**, M4 **PASS**, M3 **FAIL → fixed → clean**.

Reviews did exact column reconciliation to rule out hallucinated properties (22/22, 25/25, 14/14,
19/19, 25/25, 21/21, 17/17, 21/21, 16/16) and checked CHECK-constraint names against `pg_constraint`
and partial unique indexes against `pg_indexes`.

Confirmed real (not implementer error), each verified against DDL:
- `site.platform_connections.created_at/updated_at` are genuinely **nullable** (only `DEFAULT now()`);
  `site.sites` and `core.pre_account_builds` have them **NOT NULL**. The annotations differ per table
  because the schema does.
- `site.workplaces.created_at/updated_at` are nullable **with no default at all** — unique among the
  tables checked.
- `site.platform_connections.payload` is `jsonb NOT NULL DEFAULT '{}'` in Postgres. The SQLite test
  mirror's nullable version is the drifted one. The annotation correctly follows Postgres — this is
  the exact drift behind two prior Instagram production incidents.
- `PreAccountBuild::created_at/updated_at` carry no explicit `$casts` entry yet are still `Carbon`:
  Eloquent auto-casts timestamp columns regardless of `$casts` unless `$timestamps` is disabled.

### The `Workplace::$previous_website_analysis` correction — worth reading before annotating any jsonb

Caught by review, then corrected twice. Both errors are instructive:

1. **First error — trusted the migration comment.** The shape was transcribed from
   `20260701220001_workplace_previous_website_analysis.sql`, which documents the **v1** analyser.
   `WebsiteStyleAnalyzer` is now `VERSION = 2` and writes a different shape: no `tiers` (it is
   `signals`), no top-level `logoCandidates` (it is `logo.candidates`), plus `finalUrl`/`mode`/
   `failure`/`notes` absent from the annotation entirely.
   **Rule: for a jsonb column, the authoritative source is the code that writes it, not the migration
   comment.** Comments describe intent at migration time and drift silently as the writer evolves.

2. **Second error — conflated "what the writer produces" with "what the column can contain."** The
   corrected shape marked every key *required*, which is right for `WebsiteStyleAnalyzer::doc()`'s
   return type but wrong for the persisted column. No migration ever backfilled or cleared it, so
   v1-era blobs are still readable. Evidence it matters: `PreviousWebsiteFactor.php:58` gates on
   `($analysis['v'] ?? null) !== WebsiteStyleAnalyzer::VERSION` — explicit version-guarding that only
   makes sense if non-v2 rows exist. Required keys would have marked that guard dead and invited its
   removal; the 11 resulting `nullCoalesce.offset` errors would then have been baselined, suppressing
   a live guard.
   **Fix: every key optional**, with the reasoning recorded in the class comment. All 11 errors
   cleared naturally — nothing baselined.

**Generalisation for the remaining tail:** a jsonb `@property` describes *accumulated persisted state
across schema versions*, not the current writer's return type. Prefer optional keys unless a migration
proves the column was backfilled.

### Known inconsistency, accepted deliberately

`created_at`/`updated_at` are annotated `Carbon|null` on `User`, `PartnaStaff`, `EmailSubscription`
(by the convention in the orchestrator prompt) but non-null `Carbon` on `Site`, `Block`, `SiteMedia`,
`PreAccountBuild` (following DDL NOT NULL). Both are defensible: `Carbon|null` is strictly more
truthful, since an unsaved `new Model()` genuinely has null timestamps; non-null is more precise for
the read paths that dominate here and let ~4 more entries be deleted. **Not unified**, because moving
the non-null ones to nullable would only re-add baseline entries, and moving the nullable ones to
non-null would over-assert on unsaved instances. Erring toward `Carbon|null` is the safe direction if
this is ever revisited.

### Stale artifact worth fixing separately (not touched — applied migration)

`supabase/migrations/20260701220001_workplace_previous_website_analysis.sql`'s column comment
documents the dead v1 shape and actively misled an implementer here. The migration is applied and is a
historical record, so it was left alone — but anything reading that comment as current will be wrong.

## New findings surfaced (real signal, triaged)

1. **`PreAccountBuild::$expires_at` undefined** — *real gap, queued.* Annotating
   `User::$preAccountBuild` as `PreAccountBuild|null` (previously inferred as a generic
   `Eloquent\Model`) resolved this access to its true target class, exposing that
   `PreAccountBuild.php` documents only `$build_state`. Not a bug; a missing annotation.
   **Action: fold `PreAccountBuild` into a later unit and delete this entry then.**
2. **`account_type?->value` redundant nullsafe** ×2 (`StaffSegmentController`,
   `LoadCurrentUser.php:81`) — dead `?->` / dead `?? ''`. Harmless cleanup, baselined per §R9.
3. **`instanceof \DateTimeInterface` always true** ×2 (`AccountDeletionService.php:149,240`) —
   defensive guard against a raw un-cast string, now provably redundant. Baselined per §R9,
   which names these bounds/type guards explicitly as things NOT to delete.
