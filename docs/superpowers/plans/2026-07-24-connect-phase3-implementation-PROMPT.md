# PROMPT — Phase 3: async connect implementation (W2–W8, merges dark)

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).
> You are the **orchestrator**. You dispatch subagents; you do not write production
> code yourself.

---

## Where this sits

| Phase | Prompt | Ships |
|---|---|---|
| 0 | `…phase0-commit…` | design docs |
| 1 | `…phase1-fetchbudget…` | W1 |
| 2 | `…phase2-worker-prereqs…` | RV-4 + RV-8 |
| **3 — you are here** | this file | **W2–W8** |
| 4/5 | `…phase4-5-rollout-shop…` | activation + Shop |

**Prerequisites, verify before starting:**
1. **Phase 0 merged** — the design doc is on `development`. This prompt is a thin
   pointer at it; it is not self-contained.
2. **Josh has confirmed the Fresha reading** (design §6 decision 3). This prompt
   assumes *W6/W7 are in the slice*. If Josh deferred them, drop W6–W8 from the
   sequence below and stop after W5.
3. **RV-4 (Phase 2) resolved.** W2–W8 add `platform_connect` queue load to the
   worker box; do not merge them onto a box still over-committed. If Phase 2 has not
   run, say so and get Josh's call before proceeding.

## Mission

Implement W2–W8 of
`docs/superpowers/specs/2026-07-23-platform-connect-async-design.md` — **route (c)**:
reuse the shipped deferred-connect mechanism (`ConnectFetchJob`, the `pending: true`
write, `connectFetchErrorMessage()`) inside the bespoke controllers, behind the
existing `PARTNA_CONNECT_DEFERRED` lever. **Do not migrate the controllers onto the
registry. Do not hand-roll six copies of Instagram.**

**Everything merges dark.** Every unit lands with the flag unset, so every response
stays byte-identical until Phase 4 activates a slug. There is no frontend dependency
at merge time — the frontend gates activation, not merging. **W1 (Phase 1) and W9
Shop are not in this run.**

## The unit list — design §5, sequenced per §7

Merge order is the row order. It differs from the *activation* order (Phase 4) by
design. W6→W7 and W6→W8 are hard dependencies.

| Unit | Scope | Effort | Gate |
|---|---|---|---|
| **W2** | `DefersBespokeConnect` concern — flag check, 202 builder, poll action **with the 5-minute staleness check ported from `GenericPlatformController.php:236-253`** (not from Instagram). No platform wired. | M | none |
| **W3** | Apple (both slugs) — `connectFetchError()` on `apple-music`/`apple-podcast`; pending write `{input}`; dispatch `ConnectFetchJob`; two status routes. `FetchStrategy` already exists. Free-text inputs write a pending row that resolves to `failed` (design §6 decision 1 — no inline regex is possible). | S/M | none (discharged) |
| **W4** | Skool — pending write `{url}`; **take the lock in the job** (the write is unlocked today, PWL-16); status route; reconcile `selection()`'s pending-vs-absent ambiguity. | S | none |
| **W5** | Eventbrite + Humanitix accounts **+ `EventsCatalog`** — hoist the 5-account cap ahead of the 202; pending write; status route ×2; convert `EventsCatalog::addByUrl`'s **organiser branch only** (design §6.1) to share those two poll endpoints. `addEvent()` and the event/custom branches stay synchronous. | M/L | none |
| **W6** | Fresha individual — write the URL-only row synchronously, 202, defer the menu fetch. | M | **🔒 capability/XOR** |
| **W7** | Fresha storewide — real pending row + job; **re-assert the Square XOR inside the job's locked write** (TOCTOU); handle the `pg_advisory_xact_lock('services:{user_id}')` overlap with manual edits. Depends on W6. | L | **🔒 capability/booking/L** |
| **W8** | Fresha `team()` — independent conversion; it is its own ~96 s synchronous GET. Depends on W6. | M | none |

## Cross-cutting constraints — design §4, every unit honours these

1. Guards (cap, capability, XOR) run in the **controller, before dispatch** — synchronous rejection.
2. **Job dispatch stays outside the row lock** — under `sync`, `dispatch()` runs `handle()` inline.
3. **Always resolve to a terminal state; never `release()`** — `release()` is a silent no-op under `SyncQueue`.
4. Port the **staleness check** from `GenericPlatformController`, not Instagram.
5. A pending row is **publicly active** (`is_active => true` on the pending write) — verify each public render path against a partial payload.
6. Re-check `assertPlatformAvailable()` at write time inside the job.
7. The **first content fill must not use `saveQuietly()`** — the edge-cache purge fires on `wasChanged('payload')`.
8. Tests must not use the sync driver to prove async behaviour — `Queue::fake()` for dispatch; call `handle()` directly for behaviour.
9. `payload` is `NOT NULL DEFAULT '{}'` — a null placeholder passes SQLite and 500s in production (`SQLSTATE 23502`). Assert payload *content*.

## Non-negotiables

Read `§ Non-negotiable rules` in
`docs/superpowers/plans/2026-07-23-worker-async-pilot-PROMPT.md` verbatim.
Specifically:

- Branch `audit-fix/connect-async-impl-2026-07-24` off `development`, dedicated
  worktree, own `composer install` + `.env`, no symlinked `vendor`.
- **Units run sequentially.** Never two implementers at once; W6/W7/W8 chain.
- **No Laravel migration files.** W2–W8 need **none** — the `pending` state already
  exists (`last_refresh_status` CHECK, `20260616000000_allow_pending_refresh_status.sql`).
  If a unit seems to need a migration, stop and escalate — that likely means it drifted toward W9's problem.
- `COMPOSER_PROCESS_TIMEOUT=0 composer test`; never alongside a running implementer;
  `ConnectResolverYoutubeTest` is a load flake. Forbid `git stash` in every subagent prompt.
- **Verify each premise against current code before implementing.** The design cites
  many `file:line`s from 2026-07-23; grep them first.
- Track progress in `.superpowers/sdd/progress-2026-07-24-connect-async.md` and
  trust the ledger + `git log` over recollection after any compaction.

## Execution policy

Per `fix-flow.md`: **Plan Opus 4.8 · Implement Sonnet 4.6 · Review a separate
Sonnet 4.6 · final whole-branch review Opus 4.8.** Combine plan+impl for S/XS
(W4); keep them separate for W5/W7 (M/L) and both gated Fresha units. **Front-load
the gated plans** — author W6 and W7 plans and present them to Josh as one batched
sign-off before dispatching implementers. Specify the model on every dispatch. Hand
artifacts over as files, never pasted diffs.

## Completion

1. `COMPOSER_PROCESS_TIMEOUT=0 composer test` — green.
2. `php artisan pint --dirty`.
3. Independent whole-branch review on **Opus 4.8**, diff as a file; one fix subagent
   for the complete findings list.
4. **Prove the dark-merge property:** with `PARTNA_CONNECT_DEFERRED` unset, a test
   asserts each of the six connect endpoints returns its **current** status and body
   and pushes **nothing** to the queue. This is the merge-safety guarantee — do not
   skip it.
5. Tick W2–W8 in the design §5 table.
6. Report: units done / gated / blocked with reason, test status, the dark-merge
   proof, branch name. **Do not merge or push.** Activation is Phase 4, gated on the
   frontend.

## Reference

- Design (the real spec): `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md`
- Contract: `docs/frontend-contracts/2026-07-23-platform-connect-async.md`
- Mechanism to reuse: `app/Jobs/Platforms/ConnectFetchJob.php`, `app/Http/Controllers/Api/Platforms/GenericPlatformController.php` (`connectDeferred`, `connectStatus`), `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php` (`writeConnection`/`writeAccountConnection` `pending:`)
- Exemplar: `app/Http/Controllers/Api/Platforms/InstagramController.php`
- Runbook: `scripts/audit/fix-flow.md`
