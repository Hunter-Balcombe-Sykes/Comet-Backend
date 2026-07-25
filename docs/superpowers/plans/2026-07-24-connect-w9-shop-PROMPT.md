# PROMPT — W9 · Shop async connect (the deferred XL unit)

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).
> You are the **orchestrator**. You dispatch subagents; you do not write production
> code yourself.
>
> **This is a blocker-gate unit — DB migration + XL.** Produce the plan, present it
> with blast radius and a recommendation, and **wait for Josh's explicit go-ahead**
> before any implementer touches code.

---

## Where this sits

| Phase | Prompt | Ships |
|---|---|---|
| 0 | `2026-07-24-connect-phase0-commit-PROMPT.md` | design docs |
| 1 | `2026-07-24-connect-phase1-fetchbudget-PROMPT.md` | W1 — `FetchBudget` ×6 |
| 2 | `2026-07-24-connect-phase2-worker-prereqs-PROMPT.md` | RV-4 + RV-8 |
| 3 | `2026-07-24-connect-phase3-implementation-PROMPT.md` | W2–W8 (dark) |
| 4 | `2026-07-24-connect-phase4-activation-PROMPT.md` | activation (ops) |
| **W9 — you are here** | this file | **Shop async connect** |

## This unit is self-contained — it does not wait on the other phases

**Nothing blocks this run.** W9 shares one piece of code with Phase 3 — the small
`DefersBespokeConnect` concern (flag check, 202 builder, poll action with the
staleness check). **Whichever of W9 or W2 runs first builds it; the other reuses
it.** Check once at the start and branch accordingly:

```bash
grep -rn "DefersBespokeConnect" app/Http/Controllers/Api/Platforms/Concerns/ 2>/dev/null
```

- **Found** → reuse it as-is. Do not fork or modify it for Shop's convenience; if
  Shop genuinely needs a change, make it generic and note it for W3–W8.
- **Not found** → **build the minimal version here**, per design §2 ("What route (c)
  requires that does not exist yet"): `shouldDeferConnect(string $slug): bool`,
  `deferredConnectResponse(...)`, and a poll action whose staleness check is **ported
  from `GenericPlatformController.php:236-253`**, not from `InstagramController`
  (Instagram has no staleness check — copying it strands rows in `pending` forever).
  Keep it platform-agnostic so Phase 3 inherits it unchanged.

Two things worth knowing but **not** blocking:

- **W1 (Phase 1) already capped Shop's ~768 s tail** at the 20 s `FetchBudget` if it
  has merged. If it hasn't, Shop is still slow in the meantime — that is an argument
  for running W1 too, not for delaying this. W9 is a UX improvement either way, not
  an incident fix; do not let anyone re-argue it as a P0.
- **Activation is separate** (Phase 4) and gated on the frontend. This unit merges
  dark regardless.

**The one real prerequisite:** Josh's go-ahead on the design path chosen below.

## Mission

W9 of `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md` §5 —
convert `ShopController::addBrand()` (and `setProducts()`'s cache-miss fetch) to the
202 + poll shape, using **route (c)**: reuse `ConnectFetchJob`, the `pending: true`
write on the shared `ManagesIntegrationConnection` trait, and the
`DefersBespokeConnect` concern (reused or built here — see above). **Do not migrate
Shop onto the registry router** — its 15 bespoke routes and relational storage are
the reason route (a) was rejected.

---

## ⚠ Step 0 — verify the anchors before planning anything

**The design doc's Shop line numbers have already drifted once** (verified
2026-07-24). Re-derive them; do not trust any `file:line` below or in the design doc
without grepping first. If a premise is false, mark it `PREMISE-STALE`, record why,
and escalate — do not invent a fix.

Confirmed current as of 2026-07-24 (`app/Http/Controllers/Api/Platforms/ShopController.php`):

| Symbol | Line | Design doc said |
|---|---|---|
| `MAX_BRANDS = 5` | `:66` | — |
| `MARKER = ['storage' => 'relational']` | `:81` | `:80` |
| `brands()` (pure DB read, **not** in scope) | `:100` | `:98` |
| `addBrand()` — **the connect action** | `:110` | `:108` |
| `updateBrand()` | `:228` | `:215` |
| `catalog()` | `:340` | — |
| `brandProducts()` | `:361` | `:344` |
| `setProducts()` | `:380` | `:360` |
| `selection()` (pure DB read, **not** in scope) | `:441` | `:420` |
| `addProduct()` | `:484` | `:460` |
| cap check | `:171-172` | — |
| "selection is decoupled from connect" comment | `:54` | `:53-55` |

---

## Why Shop is the hardest unit — **three** blockers, not two

The design doc §3.5 names two. Verification on 2026-07-24 found a third, and it is
the most constraining.

**1. The identity is the probe result.** `brand_id` is what detection computes —
Shopify's numeric shop id from `meta.json`, `bigcartel-{account}`, or a host slug
for Woo/Squarespace/generic. `ShopProviderDetector`'s own header says the user never
picks the provider; provider-agnosticism is the feature. There is no cheap
`identify()`.

**2. The status has nowhere to live.** The `IntegrationConnection` row is
one-per-user with its payload frozen to `MARKER` (`:81`), so `last_refresh_status`
cannot express "brand A pending, brand B ready." Real content lives in
`site.shop_brands`, which has **no status column**.

**3. ⭐ NEW — `provider` is `NOT NULL`, and it is also a probe output.**
`site.shop_brands.provider text NOT NULL`
(`20260704160000_shop_brands_products.sql`). So the blocker is not merely "no status
column" — **two** required columns (`brand_id`, `provider`) are both outputs of the
network call you are trying to defer. A placeholder row cannot be written truthfully
before the probe under *either* design path. Whatever you choose must state
explicitly what goes in `provider` at 202 time and how it is corrected.

### The full `site.shop_brands` shape — plan against this, not against memory

```
id             uuid PK
connection_id  uuid NOT NULL → site.platform_connections(id) ON DELETE CASCADE
brand_id       text NOT NULL          ← probe output
provider       text NOT NULL          ← probe output  (blocker 3)
url, source_url, name, currency, favicon, logo, discount_code, fetch_mode   (nullable)
is_individual  boolean NOT NULL DEFAULT false
position       integer NOT NULL DEFAULT 0
style_analysis jsonb
selection_mode text NOT NULL DEFAULT 'manual'   CHECK IN ('manual','latest')
link_mode      text NOT NULL DEFAULT 'product'  CHECK IN ('product','checkout')
referral_query text NOT NULL DEFAULT ''
created_at, updated_at
UNIQUE (connection_id, brand_id)
```

Sources: `20260704160000_shop_brands_products.sql`,
`20260707030001_shop_brand_modes.sql`, `20260720100200_shop_brands_mode_checks.sql`.
**A placeholder row must satisfy every one of these**, including the two CHECKs.
SQLite does not enforce CHECK, so a green suite proves nothing here.

---

## The two design paths — cost both, recommend one, present to Josh

### (a) A nullable `status` column on `site.shop_brands`

- Raw SQL in `supabase/migrations/` — **never** a Laravel migration (the composer
  guard rejects it).
- **NULLABLE, no DB default**, per the house rule for new columns.
- If you add a CHECK on it, the repo's migration guard **requires**
  `ADD CONSTRAINT … CHECK (…) NOT VALID` in one window and
  `VALIDATE CONSTRAINT` in a **separate transaction** — copy the exact two-window
  shape from `20260720100200_shop_brands_mode_checks.sql`, which exists precisely as
  the worked example.
- Does **not** solve blocker 3 on its own — you still owe an answer for `provider`
  and `brand_id` at 202 time.
- Cleanest long-term model: per-brand pending state, no reconciliation step.

### (b) A provisional key, reconciled by the job

- Write the placeholder keyed on the host slug every scraper already falls back to
  (`preg_replace('/[^A-Za-z0-9]+/','-', strtolower(parse_url($origin, PHP_URL_HOST)))`
  — the same expression in `WooCommerceScraper`, `GenericShopScraper`,
  `SquarespaceScraper`), with a sentinel `provider` (e.g. `'detecting'`).
- The job renames/merges to the true `brand_id` + `provider` once the probe resolves.
- No migration — but a reconciliation step **no other platform needs**, and
  `UNIQUE (connection_id, brand_id)` makes the rename a real collision risk if the
  user pastes the same store twice. Reason through that case explicitly.
- A sentinel `provider` leaks into `ShopBrandResource` and therefore to the frontend
  during the pending window. Say what the dashboard renders.

**Recommend one with reasoning.** A hybrid is legitimate — e.g. (b)'s provisional
key plus a small `status` column from (a) — if you argue it.

---

## Shop-specific constraints — all verified, all must be honoured

- **The frontend calls `GET /brands/{id}/products` immediately after `addBrand()`
  returns** (`:54` comment: *"Product selection is decoupled from connect: adding a
  brand stores it with zero products; the picker … runs any time"*). The placeholder
  must be **real and queryable at 202 time**, not a deferred stub, or that follow-on
  call 404s during the pending window.
- **The cache-purge observer never fires on Shop writes.** `IntegrationConnectionObserver`
  gates on `wasChanged('payload')`, and Shop's payload is the frozen `MARKER`, so
  every mutator calls `IntegrationConnectionCacheRefresher::refresh()` explicitly. A
  job writing `ShopBrand` rows **must do the same** — the observer watches
  `IntegrationConnection`, and will not see a `ShopBrand` write at all.
- **`setProducts()` fetches inside the 10 s Redis lock** (`:380` onward), which can
  expire mid-closure while the `DB::connection('pgsql')->transaction()` delete+reinsert
  is still open. Deferring must not widen that window — move the fetch outside the
  lock, following `ConnectFetchJob`'s fetch-outside / write-inside discipline.
- **The 5-brand cap** (`:66`, checked `:171-172`) is gated behind `! $existing`,
  and `$existing` is looked up by an id only known **after** detection. Decide
  whether the cap can be hoisted ahead of the 202 (it can, if keyed on the
  provisional id) and say so.
- **`removeBrand()`/`forget()`/`addProduct()` delete child rows explicitly** rather
  than relying on `ON DELETE CASCADE`, "to stay deterministic on SQLite in tests."
  Postgres-only cascade behaviour is **not** exercised by the suite — verify against
  DDL, not a green run.
- **Re-check `assertPlatformAvailable()` at write time** inside the job: staff can
  disable `integration.shop` between the 202 and the job running.
- **A pending row is publicly active** (`is_active => true` on the pending write) —
  verify the public sitepage render tolerates a Shop brand with no products and a
  possibly-sentinel provider.

---

## Non-negotiables

Read `§ Non-negotiable rules` in
`docs/superpowers/plans/2026-07-23-worker-async-pilot-PROMPT.md` verbatim. The ones
that bite here:

- Branch `audit-fix/connect-shop-async-2026-07-24` off `development`, dedicated
  worktree, own `composer install` + `.env`, **no symlinked `vendor`**. Run
  `composer dump-autoload -o` from the real root if edits seem not to take effect.
- **No Laravel migration files** — raw SQL in `supabase/migrations/` only.
- **Tests run SQLite; production is Postgres.** `NOT NULL` and `CHECK` are not
  enforced under test. Assert row *content* as the enforceable proxy and verify the
  write against the DDL above.
- If the migration adds an index, remember `CREATE INDEX CONCURRENTLY` **cannot**
  share a file with other statements (see `CLAUDE.md` and
  `supabase/migrations/CONVENTIONS.md` §1) — one `CONCURRENTLY` statement per file.
- Units sequential; never two implementers at once. Forbid `git stash` in every
  subagent prompt.
- `COMPOSER_PROCESS_TIMEOUT=0 composer test`; never alongside a running implementer.
  `ConnectResolverYoutubeTest` is a known load flake.

## Execution policy

Per `fix-flow.md`: **Plan Opus 4.8 · Implement Sonnet 4.6 · Review a separate
Sonnet 4.6 · final whole-branch review Opus 4.8.** Plan and implement stay
**separate** (XL + gated). Specify the model on every dispatch. Hand artifacts over
as files, never pasted diffs. Ledger:
`.superpowers/sdd/progress-2026-07-24-connect-shop.md`.

## Tests

- **Dark-merge proof (do not skip):** with `shop` absent from
  `PARTNA_CONNECT_DEFERRED`, `addBrand()` returns its **current** status and body and
  pushes **nothing** to the queue.
- Placeholder row satisfies every `NOT NULL`/`CHECK` in the schema above — assert
  content, since SQLite will not.
- The follow-on `GET /brands/{id}/products` succeeds during the pending window.
- Reconciliation (path b) or status transition (path a) reaches a terminal state.
- The explicit `IntegrationConnectionCacheRefresher::refresh()` fires on the job's
  content write.
- Duplicate-paste collision against `UNIQUE (connection_id, brand_id)`.

## Completion

1. `COMPOSER_PROCESS_TIMEOUT=0 composer test` — green.
2. `php artisan pint --dirty`.
3. Migration applied to **dev Supabase** (`glncumufgaqcmqhzwrxm`) and the ledger
   realigned — see `CLAUDE.md`; `db push` to dev is unsafe due to drift, so apply
   the single migration surgically.
4. Independent whole-branch review on **Opus 4.8**; one fix subagent for the
   complete findings list.
5. Tick W9 in the design §5 table.
6. **Update the frontend contract.** `docs/frontend-contracts/2026-07-23-platform-connect-async.md`
   currently lists all Shop endpoints under "What did NOT change" with the note
   *"unchanged for now."* Replace that with a real Shop section (202 body, poll
   endpoint, states) matching the house style of the other six.
7. **Activation is a separate step and needs a redeploy.** Adding `shop` to
   `PARTNA_CONNECT_DEFERRED` does nothing until `cloud deploy partna <env>` runs —
   Laravel Cloud bakes `config:cache` at deploy time. Use the single-variable form
   (`cloud environment:variables <env> --action=set --key=… --value=… --force`);
   the bare `environment:variables <env>` command **replaces every variable** and
   would destroy secrets. **Append to the existing value — never overwrite**; the
   var is already non-empty on `development`.
8. Report: path chosen and why, migration applied (dev), test status, the
   dark-merge proof, contract updated, branch name. **Do not merge or push.**

## Reference

- Design: `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md` §3.5 (Shop), §5 (W9), §6
- Contract: `docs/frontend-contracts/2026-07-23-platform-connect-async.md`
- Mechanism: `app/Jobs/Platforms/ConnectFetchJob.php`; the `DefersBespokeConnect` concern from W2
- Schema: `supabase/migrations/20260704160000_shop_brands_products.sql`, `20260707030001_shop_brand_modes.sql`, `20260720100200_shop_brands_mode_checks.sql` (the CHECK two-window worked example)
- Runbook: `scripts/audit/fix-flow.md` · Migration + fresh-DB rules: `CLAUDE.md`
