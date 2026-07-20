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
| M1 | `User`, `PartnaStaff` | 53 deleted / 4 added (net −49) | 1682 → **1618** (−64) | _see commit_ |

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
