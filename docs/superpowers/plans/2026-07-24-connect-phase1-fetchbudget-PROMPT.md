# PROMPT — Phase 1: W1 · FetchBudget for the six bespoke connect controllers

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).
> One unit. No architectural change, no contract change, no queue, no migration.

---

## Where this sits

| Phase | Prompt | Ships |
|---|---|---|
| 0 | `2026-07-24-connect-phase0-commit-PROMPT.md` | design docs, committed |
| **1 — you are here** | this file | **W1 — `FetchBudget` ×6** |
| 2 | `2026-07-24-connect-phase2-worker-prereqs-PROMPT.md` | RV-4 + RV-8 |
| 3 | `2026-07-24-connect-phase3-implementation-PROMPT.md` | W2–W8 (dark) |
| 4 | `2026-07-24-connect-phase4-activation-PROMPT.md` | activation (ops) |
| W9 | `2026-07-24-connect-w9-shop-PROMPT.md` | Shop — independent, any time |

**This phase depends on nothing.** It adds no queue load, so it does **not** wait on
Phase 2's RV-4 (worker memory). It can run before, during, or after the pilot tier.
It is the single highest value-per-risk item in the whole programme.

## Mission

W1 of `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md` §5.

The six bespoke connect controllers open **no `FetchBudget`**. The 20 s wall-clock
ceiling from the prior plan's Phase 1 reaches only `ConnectResolver`,
`HighlightsPicker` and `YoutubeThumbnailResolver` — never these. So their worst-case
inline latency is bounded only by `SafeUrlFetcher`'s per-hop math (8 s × 6 hops,
doubled by the 403 alternate-UA retry ≈ 96 s **per fetch**), and Shop chains up to
eight of them (~768 s). This unit caps every one of these paths at
`connect_budget_seconds` (20 s) with **zero behaviour change on the happy path**.

## The pattern to copy — verbatim

`ConnectResolver::resolve()` (`app/Services/Platforms/ConnectResolver.php:63,72,75`)
is the reference:

```php
$seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
return $this->budget->open($seconds, fn () => $strategy->resolve($input));
```

`FetchBudget` is bound `scoped` in `AppServiceProvider.php:112`. `SafeUrlFetcher`
already consults the *open* budget on every hop (`SafeUrlFetcher.php:136-148`), so
the only change needed is to **open** a budget around the fetch-bearing region of
each controller action. Inject `FetchBudget` into the constructor (as
`ConnectResolver` does at `:45`) and wrap the network-bearing call in
`$budget->open($seconds, fn () => …)`.

## Scope — the fetch-bearing call sites

| Controller | Wrap the fetch in | Notes |
|---|---|---|
| `ShopController` | `addBrand()` detection cascade + `brandProfileFor()`; the conditional fetches in `setProducts()`, `updateBrand()`, `brandProducts()`; `addProduct()` | The cascade is up to 8 sequential probes — the whole cascade goes under **one** budget, not one per probe, so the 20 s is a total ceiling. |
| `AppleController` | `connectMusic()`/`connectPodcast()` — the `AppleSearch` fetch region incl. the best-effort `withGenre()` | The two iTunes calls are sequential; one budget spans both. Genre fetch is already try/caught — keep it non-fatal. |
| `FreshaController` | `connect()`, `team()`, `employeeServices()`, `saveSelection()` — each scrape region | Independent budgets, one per action. |
| `EventsPlatformController` | `addAccount()`, `addStandaloneEvent()` — covers Eventbrite **and** Humanitix via the shared base | Humanitix's bare-event-URL host resolver is itself a fetch — it must be **inside** the budget. |
| `SkoolController` | `connect()` — the two sequential `fetchCommunity` calls | |
| `EventsController` | `add()` → `EventsCatalog::addByUrl` fetch region | The smart-detect facade; same scrapers, currently unbudgeted. |

## Non-negotiables

Read `§ Non-negotiable rules` in
`docs/superpowers/plans/2026-07-23-worker-async-pilot-PROMPT.md` and obey it
verbatim. The ones that bite here:

- Branch `audit-fix/connect-fetchbudget-2026-07-24` off `development`, dedicated
  worktree, own `composer install` + `.env`, **no symlinked `vendor`**. Run
  `composer dump-autoload -o` from the real root if edits seem not to take effect.
- **`FetchBudget` is `scoped`, not `singleton`.** In a queue worker Laravel calls
  `forgetScopedInstances()` per job; in the request cycle it is per-request. Do not
  convert it, and do not cache a deadline across actions.
- Wrapping must **not** change the happy-path response. A successful fetch inside a
  20 s budget returns exactly what it returns today. Prove this with the golden
  master (below).
- `COMPOSER_PROCESS_TIMEOUT=0 composer test`; `ConnectResolverYoutubeTest` is a
  known load flake, not a regression. Never run the suite while an implementer
  subagent is running. Forbid `git stash` in every subagent prompt.

## Execution policy

Per `fix-flow.md`: **Plan Opus 4.8 · Implement Sonnet 4.6 · Review a separate
Sonnet 4.6.** W1 is **M effort, no blocker gate** — combine plan+impl is
acceptable, but keep the reviewer independent. Specify the model on every dispatch.

## Tests to add

- One per controller: budget exhaustion surfaces as the platform's **existing**
  timeout/failure message, not a 500. Fake `SafeUrlFetcher` to exceed the deadline;
  assert the current error shape is preserved.
- Extend the integration golden master
  (`tests/Feature/Platforms/GoldenMaster/…`) to prove the happy-path bodies are
  byte-identical with the budget in place.

## Completion

1. `COMPOSER_PROCESS_TIMEOUT=0 composer test` — green.
2. `php artisan pint --dirty`.
3. Independent whole-branch review on **Opus 4.8**, diff handed over as a file.
4. Report: files changed, the six (seven, incl. `EventsController`) call sites
   wrapped, test status, branch name. Tick W1 in the design doc §5 table. **Do not
   merge or push to `development`.**

## Reference

- Design: `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md` §1 (timings), §5 (W1)
- Pattern: `app/Services/Platforms/ConnectResolver.php`, `app/Services/Http/FetchBudget.php`, `SafeUrlFetcher.php`
- Runbook: `scripts/audit/fix-flow.md`
