# DEFERRED — PART 2 unit 14g · `#PRIV-2` (moderation reporter PII on never-resolving cases)

**Finding:** `#PRIV-2` · P2, `audits/sweeps/2026-08-24-claim-gate-security/CONSOLIDATED.md:336`
**DEFER trigger:** §1.2 **trigger 7** — "the fix would delete or hard-expire user or third-party PII
on a new schedule."
**Status:** PRE-DEFERRED BY DECISION in `EXECUTE-PART-2.md` §4 unit 14g. No code was written. The
source checkbox is deliberately left **unticked**.
**Written:** 2026-08-26, against branch `audit-fix/pre-launch-hardening-2026-08-25`.
**Cross-reference:** memory `project_priv2_syncfindings_remediation_open`.

---

## 1. Why this is deferred and not implemented

The EXECUTE file's reasoning, which I verified and agree with after reading the code:

> Getting the window wrong destroys evidence in an open moderation case, and the window is a
> legal/product call, not an engineering one.

Two things sharpen that. First, on an **open** case the columns in question — `reason_details` and
`signal_data` — are not incidental metadata, they **are the report**. Nulling them does not redact a
case, it empties it. The existing command can be relaxed about this precisely because it only ever
runs against cases that are already closed, where the signal row survives as a stub for audit and
nothing live depends on the text. A new open-case pass does not inherit that safety.

Second, the harm is genuinely slow. At pilot volume this does not bite for roughly 12 months, and
production currently has `core.users = 0` and no traffic, so there is no accumulating exposure to
race today.

**This is not a defect in the prune job.** The job does exactly what it says. The finding is that
nothing covers the adjacent case, and the finding's own text offers documenting the exemption as an
equally valid resolution — "silence is the finding, not the existence of a longer window."

---

## 2. Current state — verified, do not re-derive

### 2.1 The prune predicate as it stands

`app/Console/Commands/PruneResolvedCaseSignalsPiiCommand.php`, signature
`moderation:prune-resolved-signal-pii`, scheduled `routes/console.php:427-432` weekly Sunday 04:40
UTC, `onOneServer()`, `withoutOverlapping(60)`, with `onFailure` reporting.

Step 1 — select the closed cases outside the window:

```php
$caseIds = DB::connection('pgsql')
    ->table('moderation.cases')
    ->whereIn('status', ['resolved', 'auto_actioned'])
    ->where('resolved_at', '<', $cutoff->toDateTimeString())   // now() - 90d
    ->pluck('id');
```

Step 2 — erase PII on their signals, filtered so re-runs are cheap no-ops:

```php
DB::connection('pgsql')->table('moderation.case_signals')
    ->whereIn('case_id', $caseIds)
    ->whereNotNull('reporter_email')
    ->update([
        'reporter_email' => null,
        'reason_details' => null,
        'signal_data'    => '{}',
    ]);
```

Window: `config('partna.moderation.signal_pii_retention_days')`, env
`PARTNA_MODERATION_SIGNAL_PII_RETENTION_DAYS`, **default 90**. Overridable per-run with `--days`.
`--dry-run` counts without mutating.

**Erased:** `reporter_email`, `reason_details`, `signal_data` (reset to `{}`).
**Kept, deliberately:** `reporter_ip_hash` (one-way, retained for T&S dedup analytics), `reason_code`,
`signal_source`, `dedup_hash`, `case_id`. The signal ROW itself is preserved — it is moderation
evidence.

### 2.2 The other purge path, and exactly what it cannot reach

`AccountDeletionService::purgeCaseSignalPii()` erases the same column set at account-deletion time.
Per that command's own docblock (which corrects an earlier wrong version of itself): because
`ContentReportService::submit()` never writes `reporter_user_id` — the `/v1/public/report` route is
unauthenticated — **every** signal is anonymous by that column. Since `#PRIV-3` the deletion purge
also matches on `lower(trim(reporter_email))` against the deletion audit's email snapshot, so it does
reach a reporter who happens to hold a Partna account being deleted.

What it structurally cannot reach: **a reporter with no account to delete.** There is no deletion
event to hang the erasure off.

### 2.3 The gap, stated precisely

`moderation.cases.status` is constrained (`cases_status_check`, baseline `:1263`) to exactly:

```
open · triaged · under_review · resolved · auto_actioned
```

The prune covers the last two. For the first three there is **no time-based purge path at all**, and
the account-deletion path only fires if the reporter happens to be a deleting Partna user. A
non-account reporter on a case that stays `open` / `triaged` / `under_review` keeps their email,
free-text report and verbatim payload **indefinitely**.

### 2.4 Nothing closes a stuck case

`moderation:sla-scan` (`routes/console.php:466-470`,
`app/Console/Commands/Moderation/ModerationSlaScanCommand.php`, 46 lines) is **alert-only** — I
grepped it for `update` / `save` / `DB::` and it performs no writes whatsoever. It reads
`sla_due_at < cutoff` and reports. So an overdue case stays open forever until a human touches it,
and there is no auto-close today that would make this problem dissolve on its own.

### 2.5 The blocker for any naive widening

**`resolved_at` is NULL on every open case.** The existing predicate keys off it. A second pass
therefore cannot reuse that anchor and must choose a different one — which is a design decision, not
a config edit. See §4.

---

## 3. Which rows would newly become eligible

Exactly the signals matching all of:

```sql
SELECT s.*
FROM   moderation.case_signals s
JOIN   moderation.cases c ON c.id = s.case_id
WHERE  c.status IN ('open', 'triaged', 'under_review')
  AND  s.reporter_email IS NOT NULL
  AND  <the chosen age anchor> < now() - <the chosen window>;
```

Nothing else changes. A `resolved` / `auto_actioned` case is already covered and must not be
double-handled.

**Run this before implementing** (dev = `glncumufgaqcmqhzwrxm`; prod is not worth querying — it has
no users) to size the population, so the window is chosen against real data rather than a guess:

```sql
SELECT c.status,
       count(*)                                              AS signals_with_pii,
       min(s.created_at)                                     AS oldest_signal,
       count(*) FILTER (WHERE s.created_at < now() - interval '12 months') AS over_12m,
       count(*) FILTER (WHERE s.created_at < now() - interval '18 months') AS over_18m,
       count(*) FILTER (WHERE s.created_at < now() - interval '24 months') AS over_24m
FROM   moderation.case_signals s
JOIN   moderation.cases c ON c.id = s.case_id
WHERE  c.status IN ('open','triaged','under_review')
  AND  s.reporter_email IS NOT NULL
GROUP BY c.status;
```

Expectation at the time of writing: **zero or near-zero**. That is the point — this should be built
while the table is empty and the blast radius is nil, not after the rows exist.

---

## 4. The design decision — pick an anchor, then a window

### 4.1 Anchor (decide this FIRST; it matters more than the number)

| Anchor | Behaviour | Verdict |
|---|---|---|
| **`case_signals.created_at`** (column exists, baseline `:1263` block, `NOT NULL DEFAULT now()`) | Ages each REPORT from when it was filed. A case reopened by a fresh report keeps the fresh signal and ages out the old one. | **Recommended.** Per-signal granularity, it is the reporter's own data, and the retention clock starts when the reporter handed it over — which is the shape a privacy regulator expects. |
| `cases.created_at` | Ages the whole case as a unit. | Coarser; a long-running case with staggered reports loses recent evidence along with old. |
| `cases.updated_at` | Staff activity keeps PII alive. | Superficially attractive ("active investigation"), but it means a case someone touches monthly retains reporter PII forever — which is the exact unbounded-retention failure the finding is about, just harder to see. **Reject.** |

### 4.2 What to erase — this is where an open case differs from a closed one

Do **not** blindly mirror the closed-case column set. Two options, in order of preference:

**Option A — erase contact only (recommended).** Null `reporter_email`; **keep** `reason_details` and
`signal_data`. Rationale: `reporter_email` is a pure contact detail whose value decays (after 12+
months nobody is following up), whereas `reason_details` / `signal_data` are the substance of an
unresolved case. This is the smallest change that closes the actual finding — the finding's title is
about the reporter's *identity* persisting — while leaving the case investigable.
Residual to state honestly: `reason_details` is free text and **may** contain self-identifying
content ("I'm the owner of the salon next door, call me on…"). Option A therefore reduces exposure
rather than eliminating it, and that limitation must be written into the config comment rather than
glossed.

**Option B — full mirror of the closed-case set.** Also null `reason_details` and `signal_data`.
Complete, but it empties an open case. **Only defensible if paired with an auto-close** so that
nothing that old is genuinely still under investigation.

Either way, `reporter_ip_hash` stays (one-way, T&S dedup), the signal row stays (evidence), and
`--dry-run` must be supported on the new pass exactly as on the existing one.

### 4.3 Window

| Window | Argument for | Argument against |
|---|---|---|
| **12 months** | Matches the finding's lower bound; strongest data-minimisation posture; aligns with the fact that a case untouched for a year is not under active investigation. | Tightest — an intellectual-property or harassment case can legitimately run long, and 12 months is inside the plausible tail of a legal dispute. |
| **18 months** | Splits the difference; comfortably past any realistic T&S turnaround while still bounded. | No external standard anchors it — it is a compromise number, and compromise numbers are hard to defend to a regulator. |
| **24 months** | Matches the existing `early_access.retention_days` precedent (730d, `config/partna.php`), so the codebase already contains a defended 2-year window. Safest against evidence destruction. | Weakest minimisation. It is also 8× the 90-day resolved-case window, and the sibling `#PRIV` finding directly above this one in the same audit criticises exactly that kind of unexplained multiple. |

**Recommended: 18 months with Option A**, expressed as its own config key —
`partna.moderation.open_case_signal_pii_retention_days`, env
`PARTNA_MODERATION_OPEN_CASE_SIGNAL_PII_RETENTION_DAYS`, default `548`.

Why a separate key rather than reusing `signal_pii_retention_days`: the two windows answer different
questions (a closed case's residue vs. an open case's contact detail) and will diverge. Sharing one
key guarantees somebody eventually retunes one and silently moves the other.

Why 18 and not 24: it is bounded, it is 6× rather than 8× the resolved window, and combined with
Option A it destroys no case evidence at all — so the usual "but what if it's still live" objection
does not apply. The evidence stays; only the reporter's contact address goes.

**But the window itself is Josh's call, not mine.** All three are defensible and the choice is a
legal/product judgement about how long Partna wants to be able to contact a reporter.

---

## 5. Concrete next step for a human

1. **Decide** anchor (§4.1), erase-set (§4.2) and window (§4.3). That is the whole blocking decision;
   everything after it is mechanical.
2. **Run the sizing query** in §3 against dev before writing code. If it returns 0 rows — as expected
   — say so in the commit message: this ships as a guard, not a remediation.
3. **Consider the alternative fix first.** If `moderation:sla-scan` gained an auto-close (or even an
   auto-`under_review` → `resolved` after N months with a `resolution_reason` of `stale`), the
   existing 90-day prune would cover these rows with **no new retention policy at all**. That is
   strictly less machinery and one fewer window to defend. It is a bigger product decision and it is
   why this is worth thinking about rather than just coding — but it may well be the right answer.
4. **If implementing the prune:** extend `PruneResolvedCaseSignalsPiiCommand` with a second pass
   rather than adding a second command — one scheduled entry, one log line family, one place to read.
   Keep the existing pass byte-identical; the new pass is additive. Preserve `--dry-run` and
   `--days`, and give the new pass its own `--open-days` override.
5. **Tests:** a signal on an `open` case inside the window is untouched; the same signal outside the
   window is erased per the chosen erase-set; a `resolved` case is handled by the existing pass only
   and is not double-processed; `--dry-run` mutates nothing. Mutation-prove by removing the status
   filter and confirming a test goes RED.
6. **Update the config comment and `docs/`** to state the window, its justification, and — if Option A
   — the honest residual that `reason_details` free text may still self-identify. Per the finding,
   documenting the reasoning is itself half the fix.

---

## 6. What I did NOT do

- No code written, no config key added, no schedule touched.
- Source checkbox at `claim-gate-security/CONSOLIDATED.md:336` left **`- [ ]` unticked** on purpose,
  so this keeps showing as open work.
- No query run against production (it has no users and the population would be 0 for the wrong
  reason).
