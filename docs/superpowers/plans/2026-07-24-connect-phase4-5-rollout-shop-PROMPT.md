# PROMPT — Phase 4 (activation) + Phase 5 (W9 Shop)

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).
> Two distinct workstreams in one prompt: an **ops rollout** (Phase 4, no code) and
> a **gated implementation** (Phase 5, Shop). Run Phase 4 first; Phase 5 is
> independent and can run any time after Phase 1.

---

## Where this sits

| Phase | Prompt | Ships |
|---|---|---|
| 0 | `…phase0-commit…` | design docs |
| 1 | `…phase1-fetchbudget…` | W1 |
| 2 | `…phase2-worker-prereqs…` | RV-4 + RV-8 |
| 3 | `…phase3-implementation…` | W2–W8 (dark) |
| **4/5 — you are here** | this file | **activation + W9 Shop** |

---

# Phase 4 — Activation (flip the lever, per platform)

**No code in the backend.** Activation is setting the `PARTNA_CONNECT_DEFERRED` env
var, one platform at a time, after the frontend can handle a 202. This is an
operational sequence, not a fix session.

## Preconditions — all must hold before flipping anything

1. **Phase 3 merged to `development`** and deployed — W2–W8 live but dark.
2. **The frontend has shipped the polling handling** described in
   `docs/frontend-contracts/2026-07-23-platform-connect-async.md`. The frontend is a
   separate, read-only-from-here repo — **verify by observation, never by editing
   it**: confirm the dashboard reads a 202, polls `statusUrl`, and renders the three
   poll states. Until this is true, **flip nothing** — a 202 to an unprepared client
   closes the modal on a stub card that never fills in.
3. **No slug is activated ahead of the frontend.** Unlike the eight registry
   platforms (where Spotify's pending card is fully functional), none of these six
   renders a useful pending card, so early activation is pure UX regression.

## The sequence — activation order (design §6 decision 2)

Flip **one group at a time**, observe, then proceed. Blast radius ascends:

| Step | `PARTNA_CONNECT_DEFERRED` becomes | Why here |
|---|---|---|
| 1 | `skool` | Smallest surface; single selection, one row, one endpoint. |
| 2 | `skool,apple-music,apple-podcast` | Two slugs, one shape. |
| 3 | `skool,apple-music,apple-podcast,eventbrite,humanitix` | Multi-account; shares poll endpoints with `events/add`. |
| 4 | `…,fresha` | Largest surface, capability-gated, booking-adjacent. |

`config/partna.php:1513` parses this as a comma-separated slug list, read by
`ConnectResolver.php:70` (async only when the slug is listed **and** the descriptor
declares `supportsDeferredConnect()`). Set per environment via the env var — **no code
change**, and the same var is the kill switch (remove a slug → that platform reverts to
synchronous).

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

**Append, never overwrite.** The step values below assume the var starts empty. It does
not: `spotify` was activated on `development` on 2026-07-24 so the frontend had a live
202 to build against. Read the current value first and prepend what is already there —
setting the var literally to `skool` would silently deactivate Spotify with no error.

## How to run each step

- Set the env var on the target environment (`cloud environment:get partna
  development` to read current; set via the Cloud dashboard/CLI — this is Josh's
  action if it touches production).
- **Observe before the next step:** `cloud env:logs partna development --minutes 15`
  for `platform.connect_job.*` entries; watch the `platform_connect` queue depth;
  spot-check that a real connect for that platform returns 202 and its
  `/connect/status` reaches `ready`.
- Only after a clean observation window, add the next group.
- **Roll back by removing the slug** if anything misbehaves — no code change, but it
  still needs a `cloud deploy` to take effect (see the warning above). Rollback is
  ~2 minutes, not instant; do not plan an observation window that assumes otherwise.

## Phase 4 completion

Report, per step: the env value set, the environment, the observation window result,
and any rollback. Phase 4 has no branch and no tests — it is configuration and
verification. **Production flips are Josh's call each time.**

---

# Phase 5 — W9 · Shop (async connect)

**Independent of Phase 4.** Deferred by design §6 decision 6 because it is the only
unit needing a schema change. It can run any time after Phase 1 (W1 already removed
Shop's acute ~768 s risk, so this is no longer urgent).

## Why Shop is XL and gated

Two blockers, both structural (design §3.5):

1. **The identity is the probe result.** `brand_id` is what detection computes —
   Shopify's numeric shop id, `bigcartel-{account}`, or a host slug for
   Woo/Squarespace/generic. There is no cheap `identify()`; a correctly-keyed
   placeholder cannot be written before the fetch.
2. **The status has nowhere to live.** `site.shop_brands`
   (`20260704160000_shop_brands_products.sql:15-38`) has **no status column**, and
   the connection row's payload is the frozen marker `{"storage":"relational"}`, so
   `last_refresh_status` cannot express "brand A pending, brand B ready."

## The two design paths — pick one at plan time, present to Josh

- **(a) A nullable `status` column on `site.shop_brands`** — a real raw-SQL
  migration in `supabase/migrations/` (NULLABLE, no DB default; the composer guard
  rejects Laravel migrations). Per-brand pending state, cleanest model.
- **(b) A provisional host-slug key** written pre-fetch, reconciled by the job once
  the true `brand_id` resolves — no migration, but a rename/merge step no other
  platform needs.

Recommend one. **This is a blocker-gate unit** (DB migration + XL): produce the plan
with both options costed and **wait for Josh's go-ahead**.

## Shop-specific constraints (design §3.5)

- `ShopController.php:53-55` documents that the frontend calls
  `GET /brands/{id}/products` **immediately** after `addBrand()` returns — the
  placeholder must be real and queryable at 202 time, not a deferred stub.
- The cache-purge observer **never fires on Shop writes** (payload is the frozen
  marker) — a job writing `ShopBrand` rows must call
  `IntegrationConnectionCacheRefresher::refresh()` explicitly.
- `setProducts()` runs its vendor fetch **inside** the 10 s Redis lock
  (`:365,376-377`), which can expire mid-closure while the DB transaction at
  `:389-399` is open — reason through the lock semantics before deferring.
- **Postgres vs SQLite:** `shop_brands`/`shop_products` FK cascades are not exercised
  by the SQLite suite; verify constraint-bound writes against the DDL, not a green run.

## Non-negotiables & execution policy

Read `§ Non-negotiable rules` in the pilot prompt. Branch
`audit-fix/connect-shop-async-2026-07-24` off `development`, dedicated worktree, own
`composer install` + `.env`. **Plan Opus 4.8 · Implement Sonnet 4.6 · Review a
separate Sonnet 4.6 · final review Opus 4.8**; plan and implement stay separate (XL +
gated). Forbid `git stash` in every subagent prompt. If path (a) is chosen, the
migration must be **NULLABLE with no DB default**, verified against a from-zero apply
per `CLAUDE.md`'s fresh-DB rules.

## Phase 5 completion

1. `COMPOSER_PROCESS_TIMEOUT=0 composer test` — green.
2. `php artisan pint --dirty`.
3. Independent whole-branch review on **Opus 4.8**.
4. Dark-merge proof: with `shop` absent from `PARTNA_CONNECT_DEFERRED`, `addBrand`
   returns its current shape and pushes nothing to the queue.
5. Tick W9 in the design §5 table. Add `shop` to the Phase 4 activation sequence as a
   final step once the frontend handles the Shop 202. **Do not merge or push.**

## Reference

- Design: `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md` §3.5, §5 (W9), §6
- Contract: `docs/frontend-contracts/2026-07-23-platform-connect-async.md` (Shop "unchanged for now" note)
- Schema: `supabase/migrations/20260704160000_shop_brands_products.sql`
- Runbook: `scripts/audit/fix-flow.md`; fresh-DB rules: `CLAUDE.md`
