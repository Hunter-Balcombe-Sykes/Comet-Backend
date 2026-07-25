# PROMPT — Phase 4: activation (flip the lever, per platform)

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).
>
> **No code. No branch. No tests.** Activation is setting one environment variable,
> one platform at a time, and watching what happens. This is an operational
> sequence, not a fix session. If you find yourself editing a controller, you are in
> the wrong prompt.

---

## Where this sits

| Phase | Prompt | Ships |
|---|---|---|
| 0 | `2026-07-24-connect-phase0-commit-PROMPT.md` | design docs |
| 1 | `2026-07-24-connect-phase1-fetchbudget-PROMPT.md` | W1 — `FetchBudget` ×6 |
| 2 | `2026-07-24-connect-phase2-worker-prereqs-PROMPT.md` | RV-4 + RV-8 |
| 3 | `2026-07-24-connect-phase3-implementation-PROMPT.md` | W2–W8 (dark) |
| W9 | `2026-07-24-connect-w9-shop-PROMPT.md` | Shop (dark) |
| **4 — you are here** | this file | **activation — turns all of it on** |

**This prompt runs LAST**, after Phase 3 *and* W9 have merged and deployed. All seven
platforms are therefore already built and sitting dark; nothing here writes code,
it only switches them on in order.

---

## Preconditions — verify all four, report them, flip nothing until they hold

1. **Phase 3 merged and deployed.** W2–W8 live on the target environment.
2. **W9 merged and deployed.** If W9 chose the migration path, confirm the
   `site.shop_brands` status column is actually applied **on the target
   environment's database** — a deployed app against an un-migrated DB fails at the
   first pending Shop write. Check with the Supabase MCP against the dev ref
   (`glncumufgaqcmqhzwrxm`), not from the migration file's existence.
3. **The frontend ships the polling handling** for every slug you intend to flip —
   see `docs/frontend-contracts/2026-07-23-platform-connect-async.md`. The frontend
   is a separate, **read-only-from-here** repo: verify by observation, never by
   editing or cloning it. Confirm the dashboard reads a 202, polls `statusUrl`, and
   renders all three poll states (`pending` / `ready` / `failed`).
   **Shop is a distinct check** — its contract section was added by W9 and is newer
   than the other six; confirm the dashboard handles the Shop 202 *and* still calls
   `GET /brands/{id}/products` correctly during the pending window.
4. **Queue and worker are healthy.** RV-4 (Phase 2) resolved, Horizon running, and
   `platform_connect` draining. Activation is the first time these endpoints put
   real load on that queue.

If any precondition fails, **stop and report** — do not partially activate to "see
what happens."

---

## How the lever actually works — and the one asymmetry to know

`PARTNA_CONNECT_DEFERRED` (`config/partna.php:1513`) is a comma-separated list of
platform slugs. A platform behaves exactly as it does today until its slug appears.

**The gate is not identical for all platforms — do not assume it is:**

- **Registry platforms** (`spotify`, `bandcamp`, `twitch`, `pinterest`, `strava`,
  `vimeo`, `youtube`, `youtube-music`) go async only when the slug is listed **AND**
  the descriptor declares `supportsDeferredConnect()` — both conjuncts, checked at
  `ConnectResolver.php:70`.
- **The seven bespoke platforms in this rollout** (`skool`, `apple-music`,
  `apple-podcast`, `eventbrite`, `humanitix`, `fresha`, `shop`) go async on the
  **list alone**, via `shouldDeferConnect()` in the `DefersBespokeConnect` concern.
  They have no descriptor flag, and none is required.

So for everything you are flipping here, presence in the list is sufficient. If a
flip appears to do nothing, the cause is almost certainly the missing redeploy below
— not a descriptor.

> ⚠️ **Setting the env var is NOT enough — you must redeploy.** Laravel Cloud bakes
> `config:cache` at deploy time and does **not** auto-redeploy on a variable change.
> Verified 2026-07-24: after `environment:variables … --action=set`, the running app
> still read `config('partna.connect.deferred') === []` until `cloud deploy partna
> <env>` ran. A step that "didn't work" is almost always a missing redeploy, not a
> broken flag. Budget ~2 minutes per flip, and note the deploy restarts Horizon.

**Use the single-variable form. Never the bare command.**

```bash
cloud environment:variables <env> --action=set --key=PARTNA_CONNECT_DEFERRED --value='<slugs>' --force
cloud deploy partna <env>
```

`cloud environment:variables <env>` **without** `--action/--key/--value` *replaces every
variable on the environment from a file*, and the API returns values masked — running it
would destroy every secret. Snapshot the key count before and after each change and
confirm it only grew.

**Append, never overwrite.** The step values below are cumulative and assume the var
starts empty. **It does not** — `spotify` was activated on `development` on
2026-07-24 so the frontend had a live 202 to build against, and Phase 3/W9 may have
added others. **Read the current value first** and build each new value by appending
to what is actually there. Setting the var literally to `skool` would silently
deactivate Spotify with no error and no log line.

---

## The sequence — ascending blast radius

Flip **one group at a time**. Observe. Only then proceed. Order is from design §6
decision 2, with `shop` appended last.

| Step | Add to the list | Why here |
|---|---|---|
| 1 | `skool` | Smallest surface — single selection, one row per user, one endpoint, no cap. |
| 2 | `apple-music`, `apple-podcast` | Two slugs, one shape, no chaining between them. Free-text input means `failed` polls are *expected* here, not a bug. |
| 3 | `eventbrite`, `humanitix` | Multi-account, 5-account cap, and shares its poll endpoints with `events/add`. Exercise **both** entry points. |
| 4 | `fresha` | Largest of the original six: capability-gated, booking-adjacent, Square XOR, and the storewide/individual split. |
| 5 | `shop` | **Last** — biggest surface (15 routes, relational storage) *and* the least-soaked code, having just shipped in W9. |

Least-proven code activates last, deliberately. By the time Shop flips, the poll
mechanism has been exercised by six other platforms in production.

---

## Per-step procedure

For each step, in order:

1. **Read the current value.** `cloud environment:get <env> --json --fields=environmentVariables`
   (add `--show-sensitive` if masked). Record it verbatim before changing anything.
2. **Set the appended value** using the single-variable form above, then
   `cloud deploy partna <env>`. Wait for the deploy to complete.
3. **Confirm the flag is live** — the app must actually see it, not just the
   dashboard. `cloud tinker development --code='var_dump(config("partna.connect.deferred"));'`
   and confirm your slug is in the array.
4. **Exercise the real path.** Connect that platform from the dashboard. Confirm:
   the POST returns **202**, `statusUrl` is present, polling reaches **`ready`**, and
   the card renders fully. For step 3, do this via *both* the platform's own
   `connect` and `POST /platforms/events/add` with an organiser URL.
5. **Observe for a window before continuing:**
   - `cloud env:logs partna development --minutes 15` — look for
     `platform.connect_job.*`; a `lock_timeout` or a burst of `failed` is a stop signal.
   - `platform_connect` queue depth in Horizon — it should drain, not accumulate.
   - Spot-check a deliberate failure (a nonsense input) reaches `failed` with the
     platform's own message, not a 500.
6. **Only then** proceed to the next step.

**Deliberate `failed` results are not regressions.** For Apple especially, a mistyped
artist name is *designed* to write a pending row that resolves to `failed` (design §6
decision 1). Judge a step by whether the mechanism behaves, not by whether every
attempt succeeds.

---

## Rollback

Remove the slug from the list and redeploy. That is the entire procedure — no code
change, no revert, no migration to undo.

**But it is not instant.** The redeploy is ~2 minutes, same as the flip. Do not plan
an observation window that assumes you can pull a platform in seconds, and do not
flip a second group while still unsure about the first.

If Shop specifically misbehaves, rolling back the slug returns `addBrand()` to
synchronous immediately; the W9 status column can stay in place (it is nullable and
unread when the slug is absent).

---

## Completion

Report, per step: the environment, the previous value, the new value, the deploy
result, the tinker confirmation, the exercise result, and the observation window
outcome. Note any rollback and why.

Phase 4 has no branch, no tests and no commit. **Production flips are Josh's call
each time** — do not touch `production` without explicit go-ahead in this session,
and treat the prod Supabase/env state as separate from dev's throughout.

When all five steps are green, the roadmap #12 rollout is complete: seven bespoke
platforms plus the eight registry platforms all share one poll mechanism and one
kill switch.

## Reference

- Design: `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md` §6 (decisions), §7 (sequence)
- Contract: `docs/frontend-contracts/2026-07-23-platform-connect-async.md` (Rollout section)
- Lever: `config/partna.php:1513` · registry gate: `app/Services/Platforms/ConnectResolver.php:70` · bespoke gate: `DefersBespokeConnect::shouldDeferConnect()`
- Cloud CLI + log rules: `CLAUDE.md`
