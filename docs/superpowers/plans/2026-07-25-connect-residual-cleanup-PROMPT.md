# PROMPT — Connect-async residual cleanup (the "recorded, not fixed" list)

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).

---

## Where this sits

Phase 3 (`2026-07-24-connect-phase3-implementation-PROMPT.md`) shipped W2–W7 plus two
added units — `CA-SM` (three live shared-machinery defects) and `CA-DM` (the
consolidated dark-merge proof) — and merged to `development` on 2026-07-25 as
`2c88919c`. Its whole-branch review returned **no Critical**, and both Important
findings were fixed before merge.

This prompt collects the items that review **deliberately recorded rather than
fixed**, because each was consistency or latent-risk work rather than a defect, and
fixing them mid-run would have churned a branch that was already large.

**None of these is a live bug today.** Do not treat this as an incident. Two of them
are traps that only bite on a *future* change, which is precisely why they are worth
closing while the context is fresh.

## Non-negotiables

- Read `CLAUDE.md` first. `scripts/audit/fix-flow.md` is the runbook.
- Branch `audit-fix/connect-residual-2026-07-25` off `development`, in a **dedicated
  worktree** with its own `composer install` and `.env` — do **not** symlink `vendor`
  or `.env`, that breaks feature tests.
- **Verify every line number below before acting.** They were correct at `2c88919c`;
  `development` moves. Grep the symbol, do not trust the citation.
- **Run tests in the FOREGROUND.** Do not background a test run and wait for a
  notification — four agents did that during Phase 3 and lost over an hour between them.
- Full suite: `COMPOSER_PROCESS_TIMEOUT=0 composer test`. Never run it beside a
  running implementer subagent.
- **Forbid `git stash` explicitly in every subagent prompt.** A foreign stash entry
  from another branch lives in this repo.
- `vendor/bin/pint --dirty` only (`php artisan pint` does not exist here).
- **No Laravel migration files.** Nothing here needs a schema change. If a unit seems
  to, stop and escalate.
- Pest loads every test file into one PHP process, so a bare `function foo()` in a
  test file is a **global** symbol. A helper collision broke the build once during
  Phase 3. Run `git grep -n "^function " -- tests/` before committing.

## Execution policy

Plan **Opus 4.8** · Implement **Sonnet 4.6** · Review a **separate** Sonnet 4.6 ·
final whole-branch review **Opus 4.8**. Combine plan+impl for the S units (R1, R3,
R5); keep them separate for R2 and R4.

---

## The units

| # | Unit | Effort | Gate |
|---|---|---|---|
| **R1** | One idiom for "is this row pending" | **S/M** | none |
| **R2** | Single home for the 5-minute staleness window | **S** | none |
| **R3** | Stop retaining private Fresha payload keys forever | **M** | none |
| **R4** | Skool's `selection()` latent trap | **S** | none |
| **R5** | Stale comments: dev no longer runs `queue.default=sync` | **XS** | none |
| **R6** | Dark-merge proof covers `events/add` for Eventbrite only | **S** | none |
| **R7** | Instagram's poll still has no staleness check | **S/M** | none |
| **R8** | Fresha's booking GraphQL is budget-blind | **M** | none |

### R1 — three different idioms now express the same gate

Phase 3 was implemented by seven agents, and the same question — *"is this row mid-flight?"* —
acquired three spellings:

- `whereNull(...)->orWhere(..., '!=', 'pending')` — `IntegrationConnection::scopeDueForRefresh`
  (~`:203`) and `RefreshController::refresh()` (~`:91`)
- `in_array($status, ['pending','unavailable','error'], true)` — `SkoolController::selection()` (~`:130`)
- `=== 'ok'` plus a `payload.connectPendingAt` marker — `FreshaController::connectStatus()` (~`:236`)

Each is correct where it stands, and the third is deliberately different (it detects a
row whose payload was replaced by a refresh, which a status check alone cannot see).

**The work:** decide which of these is the canonical expression, express it once, and
have the call sites use it — *without* flattening the genuine semantic difference the
`connectPendingAt` marker exists to capture. If the honest answer is "these are two
distinct questions, not three spellings of one", then say so, name them clearly, and
document why — that is an acceptable outcome and better than a false unification.

**Load-bearing context you must preserve:** `last_refresh_status = 'pending'` means
**two different things** depending on who wrote it — a deferred connect's "a job owns
this row", versus the poll marker `RefreshController` stamps *itself* at ~`:105`
immediately before dispatching. Conflating them has already caused two separate
defects in this codebase: a job-level guard that broke the manual refresh button
100% of the time, and a selection-time gap that stranded rows unrecoverably. Read the
comments at `RefreshConnectionJob::handle()` before touching anything here.

**NULL-safety is mandatory.** `last_refresh_status` is `text CHECK (...)` with **no
NOT NULL and no DEFAULT**, and legacy NULL rows exist in the live dev database. In
SQL, `status != 'pending'` is **not true** for NULL. Any unified idiom must keep NULL
rows visible wherever they are visible today.

### R2 — the 5-minute staleness window lives in four places

`DefersBespokeConnect` (~`:139`), `RefreshController` (~`:42`),
`GenericPlatformController` (~`:247`), `CheckPlatformRefreshBacklogCommand` (~`:26`).

The duplication is documented as deliberate, and the value is part of the frontend
contract ("a `pending` row untouched for more than 5 minutes reports `failed`"). But
four copies means the next unit adds a fifth.

**The work:** give the constant one home and have all four reference it. Do not change
the value; do not change any behaviour. Note the precedent set during Phase 3 — a
naive de-duplication left a **Job reading a constant off a Controller**, because PHP
cannot reference a trait constant externally. The fix was to put shared wording on a
neutral class (`FetchUnavailableException`). Pick a neutral home, not a convenient one.

### R3 — completed Fresha rows keep private keys forever

`FreshaConnectFetch` writes `connectMode`, `teamMenu` and `connectPendingAt` into
`payload`. `connectPendingAt` is cleared on success, but `connectMode` and `teamMenu`
persist on the completed row indefinitely. Nothing else in the platform layer stores
private bookkeeping in the public payload this way.

They are currently invisible externally **only** because
`PublicIntegrationConnectionResource` filters through an allowlist of `['url','selection']`.
That is one filter away from a leak, and it is asymmetric with every other platform.

**The work:** either strip the private keys once the row is complete, or move them out
of `payload` entirely. **Check both consumers first** — `connectStatus()`'s mode
discriminator reads `connectMode`, and the `ready` body is built from `teamMenu`. A
future `team()` fix is also expected to want that snapshot, so do not delete it
without saying where it should live instead.

`tests/Feature/Platforms/PublicIntegrationAllowlistTest.php` and
`tests/Feature/Platforms/Registry/PublicAllowlistCoverageTest.php` both guard this
area — neither may go red.

### R4 — Skool's `selection()` is inert today and a trap tomorrow

`SkoolController::selection()` (~`:130`) withholds rows whose status is
`pending`/`unavailable`/`error`. That is correct for a pending row, whose payload has
no `name`.

It is safe **only** because Skool is non-refreshable, so its rows never acquire
`unavailable`/`error` from a failed refresh. But CA-W4 gave Skool a fetch strategy
(`SkoolFetch`), and `PlatformDescriptor::refreshStrategy()` returns a real refresher
as soon as a descriptor has **both** a fetch factory and `->refreshable()`. Skool now
has the first. **The day anyone adds the second, a single transient scrape failure
silently blanks a connected card from the dashboard.**

**The work:** make the trap impossible rather than documented — either withhold only
the genuinely-incomplete state, or add a test that fails loudly if Skool ever becomes
refreshable while `selection()` still hides failed rows. A comment alone is not enough;
this exact class of stale assumption produced three false comments during Phase 3.

### R5 — comments assert an environment that no longer exists

`ConnectFetchJob` (~`:38-39`, ~`:232-233`) and `GenericPlatformController`
(~`:151-152`) state that the deployed dev environment runs `queue.default=sync`.
**Dev cut over to Redis.**

The defensive code those comments justify is still correct — tests do run `sync`, and
`release()` genuinely is a silent no-op there, which is why the code must always reach
a terminal state. Only the stated premise is stale, and it is now load-bearing for
anyone reasoning about stranded rows.

**The work:** correct the comments to say *tests* run sync, not the deployed
environment. Change no code. This is the fourth stale premise found in this area — see
`docs/superpowers/specs/2026-07-23-platform-connect-async-design.md` for the other three.

### R6 — the dark-merge proof covers `events/add` for one platform only

`tests/Feature/Platforms/DarkMergeProofTest.php` pins the `POST /api/platforms/events/add`
organiser branch for **Eventbrite** but not **Humanitix**. Humanitix is the platform
that differs — its URL normalisation is itself a live network fetch — so it is the one
more worth pinning.

**The work:** add the Humanitix case, matching the file's existing standard exactly:
exact status, `assertExactJson` on the **full** body, `Queue::assertNothingPushed()`,
and an `array_keys($row->payload)` assertion. Follow the `DELIBERATELY VACUOUS — …`
naming convention; it exists so reviewers do not delete inertness tests as no-ops.

That file is mutation-tested — three deliberate breakages were shown to make it fail.
**Hold your addition to the same standard: prove it can fail before you call it done.**

### R7 — Instagram's poll still strands rows forever

`InstagramController::connectStatus()` has **no 5-minute staleness escape hatch**. A row
stranded by a dead worker polls `pending` forever, with no terminal state.

This is not a new discovery — it is the design's own central argument. Design §2 rejected
"hand-roll six copies of Instagram" precisely *because* `GenericPlatformController` has the
check and Instagram does not: *"Copying Instagram six times copies that defect six times."*
Phase 3 built the correct mechanism for six platforms and never went back for the seventh.
Instagram has been async since 2026-06-09, so this is live.

**The work:** give Instagram the same staleness behaviour the other platforms now have. The
canonical implementation is `DefersBespokeConnect::bespokeConnectStatus()`; the port source is
`GenericPlatformController::connectStatus()`. Prefer reusing the shared concern over a third
copy of the logic — a third copy is how this defect survived in the first place.

**Also note a contract divergence while you are there:** Instagram returns
`404 "No Instagram connection found."`, whereas the async-connect contract specifies
`404 {"message":"Account not found."}` for every other platform. Decide deliberately whether to
align it — it is a frontend-visible string, so if you change it, say so explicitly in the report
rather than folding it in silently.

### R8 — Fresha's booking GraphQL is budget-blind

`FreshaScraper::fetchEmployeeServices()` calls the booking GraphQL through a raw
`Http::withHeaders(...)->timeout(12)->post()` (~`:205-212`) rather than `SafeUrlFetcher`, so the
`FetchBudget` cannot see it. `saveSelection()`'s worst case is therefore the 20 s budget **plus**
that 12 s timeout, ≈32 s.

This was recorded as a known residual when W1 shipped the fetch budgets and has never been
addressed; it was explicitly outside W1's remit because it is a scraper change.

**The work:** route the GraphQL leg through `SafeUrlFetcher`, or give it its own deadline derived
from the open budget. `FetchBudget::remaining()` returns `null` when no budget is open — treat
that as "unbounded", not as zero. **Nesting `open()` is unsupported and fails OPEN** (an inner
`finally` clears the outer deadline), so do not wrap this in a second budget.

---

## Completion

1. `COMPOSER_PROCESS_TIMEOUT=0 composer test` — green (5346 passed at `2c88919c`).
2. `vendor/bin/pint --dirty`; `vendor/bin/phpstan analyse` — clean (it was `[OK] No errors` at `2c88919c`).
3. Independent whole-branch review on **Opus 4.8**, diff handed over as a file.
4. **The dark-merge property must still hold.** `DarkMergeProofTest` is the merge-safety
   guarantee for the whole connect programme — if any unit here makes it fail, you have
   changed behaviour, not tidied it.
5. Report: units done / deferred with reason, test status, branch name. **Do not merge
   or push without Josh's say-so.**

## Reference

- Phase 3's findings and corrections are recorded in the design doc's **"Phase 3 shipped"** note
  (seven design corrections + three live shared-machinery defects) and in the 16 commit messages
  between `904d51c7` and `4f040dc9`. *(The run ledger was worktree scratch and was not committed —
  do not go looking for it.)*
- Design + its seven implementation-proved corrections: `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md`
- Contract: `docs/frontend-contracts/2026-07-23-platform-connect-async.md`
- Runbook: `scripts/audit/fix-flow.md`
