# EXECUTE PROMPT — Slice 1b: implement, review, merge

Unlike the other slice kickoffs, **1b's design work is done.** The spec and a
twelve-task implementation plan already exist. This prompt executes them through
review and merge; it does not re-open the design.

- Spec: `docs/superpowers/specs/2026-08-12-media-pool-slice-1b-design.md`
- Plan: `docs/superpowers/plans/2026-08-12-media-pool-slice-1b.md` (Tasks 0–11)
- Kickoff that produced them: `docs/superpowers/plans/2026-08-12-media-pool-slice-1b-KICKOFF-PROMPT.md`

**Prerequisite: slice 1a must be merged into `development`.** Plan Task 0 is the
precondition check — do not skip it, and do not "fix 1a as part of 1b" if it fails.

Runs concurrently with slices 3 and 5 (parent §4.3 rule 1 — distinct kinds).
**Must NOT run concurrently with slice 6** (rule 2 — both enable a
`GoogleBusinessConnector` stream and share one `places.details` billed effect).

Paste everything below the line into a fresh session. It is self-contained.

---

**First action: rename this session to `slice-1b-media`** so it is identifiable in
Remote Control instead of appearing as a machine name.

Read, in full, before touching anything:

1. `CLAUDE.md`
2. `docs/superpowers/specs/2026-08-12-media-pool-slice-1b-design.md` — the design of
   record. Its §6.3 carries the live constraint values.
3. `docs/superpowers/plans/2026-08-12-media-pool-slice-1b.md` — **Global Constraints
   first**, then Task 0. Twelve tasks, each with numbered steps and checkboxes.
4. `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` — parent
   programme. §3 **Invariants**, §4.3 concurrency, §8.3 the `mergeInto()` hazard,
   §9 integration surface, §10.
5. `docs/superpowers/specs/2026-08-12-media-pool-slice-1a-design.md` and
   `docs/wire-changes/2026-08-12-media-pool-slice-1a.md` — 1b's wire changes **append
   to that lineage, they do not restart it**.

## Rule zero — the plan is a plan, not a fact

Parent invariant #5: **no slice may cite another slice's checkpoint as evidence for
its own claims.** The plan was written before 1a merged. Task 0 exists precisely to
re-derive 1a's state from dev rather than trust its checkpoint.

**Task 0's code gate is the one that must not be waved through.** Confirm with your
own eyes that `ProjectionWriter::mediaFingerprint()` reads `$fingerprint = $ref ?? $url`
(ref preferred), not `$url ?? $ref`. If it still prefers `url`, **stop and report the
gate failure** — Task 2 will otherwise silently re-key existing Google assets under
`UNIQUE (user_id, fingerprint)`, mint duplicates via `insertOrIgnore`, and orphan
`content.item_media.asset_id`. That is the exact duplication 1a exists to prevent.

**Prod verification is deferred** (owner decision, 2026-08-12). This prompt
previously asked you to confirm the same fingerprint property on production. Do not:
prod database access could not be established on 2026-08-12 (`password
authentication failed`; the project itself is `ACTIVE_HEALTHY`, so it is a
credentials matter, not an outage), and `production` is 777 commits behind
`development`, so none of this code runs there yet.

**Record the gap rather than closing it.** Note in your checkpoint that 1a's
no-migration-needed argument remains unverified on prod, so whoever reconciles
production inherits a known open question instead of a silent assumption.

Where dev and the plan disagree, **dev wins**, and you record the correction in the
checkpoint rather than quietly adapting.

## What this slice touches that others do not

Flagging these because they raise the review bar, not to re-litigate them:

- **It spends money.** Billed Places effects and Apify actor runs. Any design drift that re-bills for data already stored is a cost regression — the plan's Task 5 resolves photo URLs *in the same fetch* for exactly this reason.
- **It mirrors third-party bytes** to R2. Paths must be content-addressed so a re-sync of changed bytes cannot overwrite a URL a user has already selected.
- **It takes a data-loss decision.** The 6 `ig-post` / `ig-reel` rows in `site.content_selection` carry neither `media_id` nor `external_ref` and resolve positionally against a live payload. If the spec's decision is to drop them, the checkpoint must name whose rows on whose sites — not bury it in a migration count.
- **Attribution has legal weight.** Google's Places terms require photo attribution display. Task 1 adds the column; if anything defers, say what that means for the pilot.

## Execution

Work the plan task by task. Each task's steps are ordered and most are TDD —
**write the failing test, run it, watch it fail, then implement.** Do not batch tasks
or skip the failure observation; that step is what proves the test is testing
anything.

Per parent §3 and `scripts/audit/fix-flow.md`:

- **Per task:** plan → implement → **independent review** → tick the checkbox.
- **Blocker gate** — money, migrations, or the public wire — applies to Tasks 1, 5, 7, 8, 9 and 10. Those get a plan reviewed *before* implementation, not after.
- A ticked checkbox means "resolved", not "code changed". If a step turns out unnecessary, say why and tick it; if it turns out wrong, correct the plan in place and note the correction.

Honour the plan's Global Constraints verbatim. Two worth repeating because they are
easy to violate under time pressure:

- **`git stash` is forbidden in this worktree** — a peer session shares the checkout.
- **Copy `MediaUploadBackfiller::invalidate()` for cache invalidation, not `PoolController::poolChanged()`** — the latter runs two lanes on purpose, and you need three.

## If reality diverges, update the downstream prompts — do not just note it

**A checkpoint is not a communication channel.** Parent invariant #5 forbids any
slice citing another's checkpoint as evidence, so writing a discovery only into your
own checkpoint guarantees the next session never acts on it. **Edit their prompt.**

You are the first slice of the concurrent wave and you own `GoogleBusinessConnector`,
so what you change lands on other people. Propagate it **before you merge**:

| You discover / change | Update |
|---|---|
| A parent-spec fact is now wrong (a count, a claim, a constraint) | The parent spec's §1 and its revision note, in place |
| You changed `GoogleBusinessConnector`, `PlacesDetailsDriver` or the billed-effect lane | **`slice-6-reviews` explicitly** — it inherits that connector and is sequenced after you precisely because of the shared `places.details` effect |
| You changed `ProjectionWriter`, `PoolResolver`, `PoolRegistry` or `MediaUrlResolver` | `slice-3-services`, `slice-5-shop`, `slice-4-menus`, `slice-7-teardown` |
| You reshaped `app/Services/Migration/` or its command conventions | The other backfill prompts — 3, 4, 5, 7 |
| The plan itself was wrong and you corrected it | The plan, in place, with the correction noted — a plan is a plan, not a record |
| The `gallery` / `designMedia` retirement boundary moved | `slice-7-teardown`'s gate 2, which depends on exactly that boundary |
| You consumed migration filename prefixes outside your block | Whichever slice owns the block you took from |

Two rules for the edit itself:

- **Edit in place; do not append a "correction" section.** A prompt read top to
  bottom must be true. A stale statement with a correction 80 lines later will be
  acted on before the correction is reached.
- **Say the fact, not the story.**

If you find something that invalidates another slice's *approach* rather than a
detail — stop and raise it rather than rewriting their scope unilaterally.

## Process — stop at every gate

1. **Task 0 precondition.** Confirm 1a merged, the code gate passes **on dev**, and 1a's commands have run. **STOP — sign-off on the gate report.** If it fails, that report is the deliverable. Prod is out of scope — see rule zero.
2. **Tasks 1–11**, in order, with per-task independent review. Blocker-gate tasks get plan sign-off first.
3. **Independent review of the whole diff.** **STOP — sign-off.**
4. **Live dev assertions** (plan Task 11), SQL and output pasted into a parent-spec checkpoint. Includes the two proofs the parent demands: **no duplicate assets after two consecutive Instagram syncs**, and the `mergeInto()` regression — a Google or Instagram run landing after migration leaves the upload items and migrated selections alive.
5. **Wire manifest** at `docs/wire-changes/2026-08-12-media-pool-slice-1b.md`, appending to 1a's lineage.
6. **Post-deploy:** `cloud env:logs partna development --minutes 10` **and** a Nightwatch scan. Slice 0's checkpoint recorded a log scan and skipped Nightwatch; do not repeat that.
7. **Merge + push.** Rebase `feat/media-pool-slice-1b` onto `development`, full suite + PHPStan + Pint green, **STOP — explicit sign-off**, then merge and push to `development`. **Never push to `production`.**

## Definition of done

All twelve tasks ticked; Google photos serve through the pool without re-billing;
Instagram media mirrored to content-addressed R2 paths with no duplicate assets
across two syncs; the 91 `site.content_selection` rows migrated or their loss
explicitly recorded; attribution carried or its deferral stated; the `mergeInto()`
regression proven absent; checkpoint and wire manifest committed; merged to
`development`.

**Out of scope, deliberately:** the `gallery` / `designMedia` wire keys stay — they
cannot retire until the frontends stop reading them. `site.site_media` demotion and
the `site.content_selection` drop are slice 7.
