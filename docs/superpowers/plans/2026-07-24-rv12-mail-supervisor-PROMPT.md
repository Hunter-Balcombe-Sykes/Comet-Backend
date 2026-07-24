> ## ✅ DONE — 2026-07-24, branch `audit-fix/rv12-mail-supervisor-2026-07-24` (NOT merged)
>
> **Precondition cleared during this session, not before it.** RV-4 was ticked as a decision but the
> box was still `flex-1gb`; resized live to `flex-2gb` via
> `cloud instance:update inst-a251ae1e-7c2c-4525-83a3-7867646e119c --size=flex-2gb` (+$14/mo).
> Verified by re-reading `cloud instance:list development`. A resize creates no deployment row.
>
> **Commits:** `0ec7acaf` tests · `f9146270` supervisor-mail · `91baf28a` docblock ·
> `dce12ee7` suite tick · `20b13c90` review fixes 1-3 · `502b3015` Mailable routing ·
> `c0fc6dc4` routing tests · `ff283682` plan record. Suite: **5065 passed**, 0 failures.
>
> **Part B was NOT implemented as specified — deliberately.** This prompt claimed the
> `balance => false` justification comment was factually wrong. Verified against the installed
> `laravel/horizon v5.48.1`: `Supervisor::createProcessPools()` (`src/Supervisor.php:88-93`) builds
> one pool per comma-separated queue under a balancing strategy, and `scale()` (`:135-139`) does
> `max(maxProcesses, $processes, count($processPools))` — the existing comment is **correct**. This
> prompt's counter-claim that `scale()` is reachable only via manual `horizon:scale` is also false
> (`Console/SupervisorCommand::start():100` calls it on ordinary boot). The comment was kept and
> given source citations instead. Rewriting it as instructed would have replaced an accurate comment
> with an inaccurate one.
>
> **Scope grew by owner sign-off:** review found every `Mail::queue()` call inherits the `redis`
> connection's default queue (`default`), which stayed on `supervisor-1` — so Supabase auth emails
> (magic link/OTP/recovery) and the GDPR deletion scheduled/cancelled mails were outside the new
> lane, contradicting this prompt's own Goal. Fixed once in `app/Mail/BaseTransactionalMail.php`
> (the only class extending `Illuminate\Mail\Mailable`; all 23 mailables extend it).
>
> **Plan of record:** `docs/superpowers/plans/2026-07-24-rv12-mail-supervisor.md` (on the branch).

# PROMPT — RV-12: split transactional mail into its own Horizon supervisor

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).
> This is a **single gated unit**: plan first, present it, wait for Josh's
> go-ahead, then implement under `scripts/audit/fix-flow.md`.

---

## ⛔ Hard precondition — do NOT start until this is TRUE

**`RV-12` is strictly gated on `RV-4` (the `flex-1gb` → `flex-2gb` Worker resize) being LIVE.**
Josh decided Option A (raise the instance) and ticked `RV-4` as an intent, but ticking it recorded
the *decision*, not the deployed state. A fourth supervisor adds a middleman process **plus** a
worker (~180 MiB) to the box. On the still-1024 MiB instance that re-creates the 2026-07-22 OOM,
which is the exact sequencing the review forbids.

**First action of the session: confirm the resize actually shipped.** The Cloud management API does
not expose worker RSS directly, so verify via more than one signal:

```bash
~/.composer/vendor/bin/cloud environment:get development --json | grep -iE 'instance|flex|size'
~/.composer/vendor/bin/cloud deployment:list development         # a resize is a deployment
# and read the instance memory graph in the Laravel Cloud dashboard — the ~810 MiB idle
# floor should now sit against 2048, not 1024.
```

If you cannot positively confirm the box is `flex-2gb` (2048 MiB), **STOP and tell Josh** — do not
implement on an unverified instance size. This one line is the whole reason the unit was deferred.

---

## Context you must read first

| File | Why |
|---|---|
| `config/horizon.php` (esp. `supervisor-1`, lines ~90-135, and the "Worker Lanes" docblock) | The code you are changing and the comment you must correct. |
| `.superpowers/sdd/worker-async-pilot/reports/plan-unit-18-memory-overcommit.md` | The verified heap arithmetic behind `RV-4`. Reuse its numbers; do not re-derive from scratch. |
| `audits/workers/2026-07-23-worker-async-review-TRIAGE.md` → the `RV-12` entry in the "Review-only addendum" | The unit spec, verbatim. |
| `docs/reviews/2026-07-23-worker-async-layer-review.md` §8 item 15 | The roadmap framing and the explicit "after #4" ordering. |
| `../CLAUDE.md` + repo `CLAUDE.md` | House rules: Horizon `defaults` UNIONS into every env, `balance=>false`, the Redis DB map. |

**Memory budget, post-resize (from plan-unit-18, re-verify against live `config/horizon.php`):**
permitted worker heap is `2×256 (supervisor-1) + 256 (supervisor-long) + 512 (supervisor-videos) =
1280 MiB`. A 4th `supervisor-mail` at 1 process × ~180 MiB (middleman + worker) takes permitted to
~1460 and the measured idle floor to ~990, against **2048** on `flex-2gb`. That headroom is what the
`RV-4` resize buys and what makes this unit safe — **confirm the arithmetic still holds against the
supervisor blocks as they actually are today** (unit 11 appended `cloudflare_bulk` as a queue name;
other work may have shifted things since).

---

## The unit — two parts, both required

### Part A — split transactional mail into its own supervisor

`supervisor-1` drains **twelve** queues with **two** processes under `balance => false` (strict
listed priority). `mail` and `notifications` sit 2nd/3rd — correct ordering — but a single long job
elsewhere in that supervisor (a ~180 s Cloudflare purge, a ~300 s logo job) occupies one of only two
processes, and **two** concurrent long jobs stall *every* transactional email until one finishes.

Give transactional mail its own lane so a bulk/long job on the shared supervisor can never park a
confirmation or a GDPR deletion email.

Design decisions the plan must make explicit (do not assume):
- **Which queues move.** At minimum `mail`; decide `notifications` with reasoning (they share the
  latency-sensitivity but also carry the moderation-adjacent `notifications` traffic — check what
  actually dispatches there).
- **The new supervisor's `connection`, `queue`, `balance`, `maxProcesses`, `memory`, `timeout`,
  `tries`.** It is a new entry in `horizon.defaults`, so per `CLAUDE.md` it runs in **every**
  environment — size `memory` for the smallest deployed box, and state the per-env `maxProcesses`
  overrides.
- **What the two removed queues leave behind** on `supervisor-1`: re-state its remaining queue list
  and confirm the priority order still reads correctly without them.
- **retry_after coherence:** the mail connection's `retry_after` must still exceed the longest
  `$timeout` any job on that lane declares (the JOB-103 invariant the docblock describes). Verify
  against the actual mail jobs, including the `SendStaffBroadcastEmailToSubscriberJob` retry shape
  unit 11 changed (`$tries = 0` + `retryUntil(2h)`).
- **`HorizonQueueCoverageTest`** asserts every queue any app code dispatches to is drained by some
  supervisor, and pins per-env coverage. Adding a supervisor and moving queues will move those
  assertions — update them so the guard still means something; do not weaken it.

### Part B — correct the wrong justification comment (while you are in the file)

The "Worker Lanes" docblock currently claims `balance => false` is *"the only strategy that respects
`maxProcesses` — 'simple'/'auto' floor at one worker PER QUEUE (Supervisor::scale raises maxProcesses
to the pool count)."* **The conclusion (keep `balance => false`) is right; the justification is
wrong**, and the next person reasoning from it will reason from a false premise:

- `Supervisor::scale()` is invoked **only** from `SupervisorCommands\Scale` — the manual
  `horizon:scale` / dashboard path — not from the automatic provisioning path. Verify this in the
  installed `laravel/horizon` source before rewriting the comment (this branch is on Horizon
  ≥5.48.1; confirm the version and the call sites).
- Real behaviour with `'simple'`/`'auto'` and `maxProcesses` (2) **below** the queue count (now 10+
  after the split) is **starvation, not a floor of one**: the first pools to claim workers exhaust
  the budget and the remaining queues get **zero**. State the corrected behaviour precisely; keep
  the "`balance` MUST stay false" conclusion.

Do not expand Part B into a refactor — it is a comment correction grounded in a verified reading of
the vendor source.

---

## Execution policy (from the worker-async TRIAGE header)

- **Plan:** Opus 4.8 · **Implement:** Sonnet 4.6 · **Review:** a *separate* Sonnet 4.6, never the
  implementer. Specify the model explicitly on every dispatch.
- **This is a blocker-gated unit** (L/XL-adjacent infra, memory-sensitive, 2026-07-22 OOM history):
  produce the plan, present it with the post-resize heap arithmetic and your recommendation, and
  **wait for Josh's explicit go-ahead** before writing code.
- Branch `audit-fix/rv12-mail-supervisor-2026-07-24` off `development` in a dedicated worktree, own
  `composer install`, own `.env` (copied, not symlinked). Never commit to `development`/`production`.
- No `git stash`. No Laravel migration files (none needed). `pint --dirty` only. Logs via
  `cloud env:logs partna development` only — never `mcp__laravel-boost__read-log-entries`.

## Verifying the change without a live Horizon

The suite runs SQLite and there is no real Horizon/Redis here, so you **cannot** prove clean
supervisor startup or memory behaviour locally — say so plainly rather than claiming it.

- What you *can* prove: `HorizonQueueCoverageTest` and any `config/horizon.php` shape tests pass;
  every queue is still covered; retry_after > timeout holds; the priority lists are correct; the
  union-into-every-env math is right for the smallest box.
- What must be **deploy-time verified** and stated as such in the report: actual process count and
  RSS on the resized box after this ships (the memory graph), and that transactional mail latency
  under a concurrent long job is now isolated.

## When done

1. `COMPOSER_PROCESS_TIMEOUT=0 composer test` — green (the 300s default kills it; the env var is
   mandatory; read the `Tests:` summary line, never a piped exit code).
2. Independent Sonnet review of the diff, then a final read on Opus given the memory arithmetic.
3. **The pilot TRIAGE was archived on 2026-07-24** — `RV-12` was marked `[~]` (relocated) there and
   this prompt is now its live tracking. Record completion HERE (add a "DONE" note at the top of this
   file) plus the commit SHA; do not go looking for the old checkbox. The archived record is at
   `audits/archive/workers/2026-07-23-worker-async-review-TRIAGE.md` for context only — do not edit it.
4. No `archive-done` step — that folder is already archived.
5. Report: what shipped, the deploy-time checks Josh must run to confirm the split works, test
   status, branch name. **Do not merge or push** — Josh reviews and merges.

## Reference

- Deferred-decision record + heap math: `.superpowers/sdd/worker-async-pilot/reports/plan-unit-18-memory-overcommit.md`
- Runbook: `scripts/audit/fix-flow.md`
- The rest of the pilot run (shipped): branch history on `development` behind commit `6e667616`.
