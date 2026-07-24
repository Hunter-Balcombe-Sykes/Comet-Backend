# PROMPT — Cache: invalidation crash on provisional users + hourly SLO violation

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).

---

## Where this came from

Both units are **live Nightwatch issues on `development`**, not audit findings. They were
surfaced during a runtime health check on 2026-07-24. Facts below were verified against the
deployed environment and the current code on `development` at that date — but **re-verify each
premise before acting**, the branch moves.

| Unit | Nightwatch | Rate | Severity |
|---|---|---|---|
| `CACHE-1` | #276 | 6 / 24h, 17 / 30d | **Real bug** — cache invalidation silently aborts |
| `CACHE-2` | #285 | 36 / 24h, 66 / 7d | **Investigate first** — may be a real cache problem OR a mis-calibrated SLO |

Do `CACHE-1` first. It is small, unambiguous, and independent.

---

## CACHE-1 · `invalidateUser()` throws on any user with a null `auth_user_id`

- **Where:** `app/Services/Cache/UserCacheService.php:307` (inside `invalidateUser()`),
  crashing in `app/Services/Cache/CacheKeyGenerator.php:150`.
- **Trigger path:** `PruneExpiredPreAccountBuilds.php:73` (the `builds:prune-expired` command,
  daily 03:40) → `DB::transaction` → `UserObserver::deleted()` → `UserCacheService->invalidateUser()`.

**The fact.** `invalidateUser()` builds a `$keys` array that includes:

```php
CacheKeyGenerator::professionalPayloadByAuthId($professional->auth_user_id),
...
CacheKeyGenerator::userIdByAuthId($professional->auth_user_id),
```

Both key generators declare `string $authUserId` — non-nullable. **Pre-account / unclaimed users
have no auth record, so `auth_user_id` is null** (this is first-class per `CLAUDE.md`'s pre-account
rules, not an anomaly). Passing null is a `TypeError`.

**Why this is worse than a log line.** The `TypeError` fires while the `$keys` array is still being
*constructed*, so **not one key is invalidated** — not the by-id payload, not the by-handle payload,
not the hydrated model, not the services caches. Something upstream catches it (the run logs
`[warning] Professional cache invalidation failed on delete` and continues), so it fails open and
nobody notices. The consequence is that a **hard-deleted user's cached payload survives to TTL**.
On a takedown or GDPR path that is a data-exposure window, not just noise.

**What to do.**
- Make the null case explicit rather than incidental. Two shapes are defensible — pick one and say
  why in the commit: (a) filter the auth-keyed entries out when `auth_user_id` is null, or
  (b) widen the two generators to `?string` and have them return null, filtering nulls before the
  forget loop. Do **not** simply cast to `(string)` — that produces the key `pro:payload:auth:`
  which is a real key shared by every authless user, and forgetting it is meaningless.
- **Find the sibling instances.** Grep every `CacheKeyGenerator::` call that is passed an
  `auth_user_id` and every `->auth_user_id` reaching a non-nullable `string` parameter anywhere in
  `app/`. `invalidateUser()` is where it was *observed*; assume it is not the only site.
- **Identify the swallowing catch.** Find what turns this `TypeError` into
  `Professional cache invalidation failed on delete`. Decide deliberately whether it should stay
  fail-open (probably yes — a cache failure must not abort a deletion) and make sure that decision
  is written down next to it. A fail-open that hides a total invalidation failure is worse than one
  that hides a partial one.
- **Test with a real provisional user.** A `core.users` row with `auth_user_id = null`, deleted, and
  an assertion that the non-auth keys were still forgotten. A test that only asserts "no exception"
  would have passed before this bug too.

**Verify the premise first:** confirm `auth_user_id` is still nullable in
`supabase/migrations/` DDL (not just the model), and confirm the line numbers — the file moves.

**Related but explicitly OUT OF SCOPE:** the same `builds:prune-expired` run also fails with
`audit.staff_audit_log is append-only (OPS-2)` (Nightwatch #308) when the deletion cascade tries
`UPDATE audit.staff_audit_log SET user_id = NULL`. That is a **different root cause** with its own
design already written at `docs/superpowers/specs/2026-07-23-deletion-path-appendonly-fix-design.md`.
Do not fix it here. Do note in your report whether CACHE-1 is reachable independently of it, or only
ever co-occurs.

---

## CACHE-2 · Cache SLO violation fires every hour — 🔒 investigate before changing anything

- **Where:** `app/Jobs/Cache/AggregateCacheMetricsJob.php:79`, thresholds on `RecordCacheMetrics`
  (`SLO_PREFIXES`, `SLO_MIN_HIT_RATE`).

**The fact.** The job is hourly. For each prefix in `SLO_PREFIXES`, when the bucket has
`total >= 10` requests and `hitRate < 0.90`, it calls `report(new RuntimeException(...))`. Note it
**reports, it does not throw** — the job does not fail and nothing is retried. This is a deliberate
Nightwatch escalation channel, and it is working as designed.

Observed on `development`: prefix `site` at **68.3%** and prefix `pro` at **76.2%** in the
2026-07-24-05 bucket; an earlier bucket showed `site` at **14.5%**. It has fired 66 times in 7 days.

**This is a fork, and you must decide which branch you are on before writing code.**

1. **The cache is genuinely ineffective.** Something is writing with a TTL shorter than the read
   interval, invalidating too aggressively, or missing a warm path. Then the fix is in the cache
   behaviour and the SLO is correctly telling you so.
2. **The SLO is mis-calibrated for a pre-beta environment.** `development` has near-zero real
   traffic. A `total >= 10` noise floor is very low: eleven requests where four are first-of-kind
   misses reads as 63% and pages, even though nothing is wrong. Then the fix is the threshold or the
   floor, and "fixing the cache" would be chasing a number that means nothing.

**How to tell them apart — do this before proposing anything:**
- Pull the raw `cache.metrics` log lines (`cloud env:logs partna development --minutes 240`, they are
  `[info] cache.metrics` with `hits`/`misses`/`writes`/`total` per prefix per bucket). Get the actual
  `total` per bucket. If totals are in the low tens, you are probably in case 2. If they are large
  and the rate is still low, case 1.
- Identify precisely **who reads and writes the `site` and `pro` prefixes**, and what TTL each write
  uses. Compare TTL against real read cadence. A TTL shorter than the gap between reads guarantees a
  miss every time and is invisible in a hit-rate number alone.
- Check whether invalidation is over-broad — e.g. an observer firing a purge on a write that did not
  actually change rendered output. `IntegrationConnectionObserver` and the design-kit write paths are
  worth a look; `updateQuietly()` exists in this codebase precisely to avoid that class of problem.
- **Check whether CACHE-1 interacts.** It leaves keys behind rather than removing them, which would
  *raise* a hit rate, not lower it — so it probably does not explain this. Confirm rather than assume.

**Then stop and present.** Say which case it is, with the numbers. If the answer is "raise the noise
floor" or "lower the SLO for non-production", that is **Josh's call, not the implementer's** — an SLO
is a product decision about what counts as broken. Present the recommendation and wait. If the answer
is a genuine cache fix, present that plan and wait too, because it touches the public read path.

**Do not** silence the alert, add the prefix to an ignore list, or widen the threshold as a first
move. The alert is the only reason anyone knows about this.

---

## Non-negotiables

- Branch `audit-fix/cache-slo-invalidation-2026-07-24` off `development`, dedicated worktree, own
  `composer install`, own `.env` (**copied, never symlinked**).
  ⚠ `EnterWorktree` bases off `origin/production` (this repo's default branch) and renames the
  branch. Fix both in one step:
  `git fetch origin development && git checkout -B audit-fix/cache-slo-invalidation-2026-07-24 origin/development`
  then delete the `worktree-*` branch it created. Verify `HEAD == origin/development` before working.
- **`CACHE-2` is gated.** Investigate → present → wait for Josh. `CACHE-1` may proceed without a gate.
- No Laravel migration files. Neither unit needs one.
- `COMPOSER_PROCESS_TIMEOUT=0 composer test`; read the `Tests:` summary line, never a piped exit
  code. Never run it alongside a running implementer subagent.
- **Forbid `git stash` explicitly in every subagent prompt** — the stash is shared across worktrees
  and sibling sessions.
- `./vendor/bin/pint --dirty` (`php artisan pint` is not registered in this repo).
- Logs via `cloud env:logs partna development` only. Never `mcp__laravel-boost__read-log-entries`.
- Note `cloud tinker` / `cloud command:run` execute on the **App** instance, not the Worker cluster,
  and take no `--instance` flag. Anything needing worker-side state must come from Redis or the
  dashboard.

## Execution policy

Per `scripts/audit/fix-flow.md`: **Plan Opus 4.8 · Implement Sonnet 4.6 · Review a separate
Sonnet 4.6 · final whole-branch review Opus 4.8.** Specify the model on every dispatch. Keep plan
and implement separate for `CACHE-2`.

## Completion

1. `COMPOSER_PROCESS_TIMEOUT=0 composer test` — green.
2. `./vendor/bin/pint --dirty`.
3. Independent whole-branch review on Opus 4.8.
4. Report: what shipped, which fork `CACHE-2` landed on **with the numbers that decided it**, what
   Josh must verify on the deployed env (both issues are only truly confirmed fixed by watching
   Nightwatch #276 and #285 stop recurring), test status, branch name.
5. **Do not merge or push.**

## Reference

- Nightwatch app `Partna` = `a1698025-90b3-426d-94ae-4b85ae5bb4c2`; issues **#276** and **#285**.
- Out-of-scope sibling: #308, spec at `docs/superpowers/specs/2026-07-23-deletion-path-appendonly-fix-design.md`
- Pre-account rules (why `auth_user_id` is legitimately null): `CLAUDE.md` § Pre-account signup
- Runbook: `scripts/audit/fix-flow.md`
