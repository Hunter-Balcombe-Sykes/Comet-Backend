# Execute prompt — signup-flow live-test findings (2026-08-05)

Paste the block below into a **fresh Claude Code session** at the repo root.

Source of truth for the findings and their checkboxes:
**`docs/reviews/2026-08-05-signup-flow-live-test.md`**. Tick boxes there, not here.

## Execution policy

| Step | Model |
|---|---|
| Plan | Opus |
| Implement | Sonnet — **Opus for `SIGNUP-1`** (the backfill + KV reasoning is the hard part, not the one-line fix) |
| Review | Sonnet — **Opus for `SIGNUP-1`** |

## ✅ No blocker gate — this run is pre-approved

Both gates were resolved by Josh on 2026-08-05 (decisions recorded in the findings doc and restated
below). `fix-flow.md`'s blocker gate is about **approach and blast radius**; both are now settled, the
backfill target is dev-only test data, and no schema migration is in scope. **Work all six units
straight through without pausing for sign-off.**

Branch `audit-fix/signup-flow-live-test-<date>` off `development`.

## Decisions already made — settled, do not relitigate

- **`SIGNUP-1` → `sites.subdomain` is to be RETIRED**, with `users.handle` as the single identifier.
  It cannot be collapsed while rows disagree with their handle, so this run ships **stage 1 only**:
  make the two provably equal. The retirement itself is `SIGNUP-7`, a separate branch — **explicitly
  out of scope here.**
- **`SIGNUP-1` backfill → straight rename, no alias rows.** Prod is empty (`core.users` = 0,
  `site.sites` = 0, verified 2026-08-05) and all 12 diverged rows are pre-beta dev test data with no
  traffic. No 301s, no `site_subdomain_aliases` entries.
- **`SIGNUP-3` → accept and document.** Pre-account sites are public by design so a visitor can see
  their site before claiming. `is_published` is **not** a public-visibility control. **Do not add a
  gate** to the profiles route or the KV write. Documentation + reconciliation only.

---

## The prompt

> Six units from a manual end-to-end signup test against dev on 2026-08-05. They are **measured, not
> theorised** — every one ships with the command that produced it. Read
> `docs/reviews/2026-08-05-signup-flow-live-test.md` end to end first, including its
> **"What worked — do not fix these"** section and the two **DECIDED** callouts.
>
> **Verify each premise against current code before fixing it.** `development` moves; a finding that
> is already fixed gets ticked with "already fixed at `<sha>`", not re-implemented. Re-run the cited
> commands — do not trust the numbers in the doc.
>
> Work units in order. Nothing here is gated; do not stop for sign-off.
>
> ---
>
> ### Unit 1 — `SIGNUP-1` · make `handle` and `subdomain` provably equal
>
> **This is stage 1 of retiring `sites.subdomain`, not an alternative to it.** Do not attempt the
> retirement (`SIGNUP-7`) here — no column drop, no view changes, no alias-table work, no wire-contract
> change. Convergence is the prerequisite; that is all this unit does.
>
> **The fix.** `PreAccountBuildService.php:117` passes `$seed` into
> `createSiteWithRetry($this->siteProvisioning->subdomainBaseFromHandle($seed))`. It must pass the
> handle the user was actually allocated (`$user->handle`, returned by `createProvisionalUserWithRetry`
> on the preceding line). That alone stops new divergence.
>
> **Do not invert it.** `users.handle` is the canonical routing key — KV, the profiles route,
> `compute_user_url()`, custom domains and the alias/301 machinery all key on it. Never make KV or the
> profiles route key on the subdomain.
>
> **Three things the one-line fix does not cover — handle each:**
>
> 1. **The suffix formats still differ.** `HandleAllocator` appends `2`; `SiteProvisioningService::buildCandidate`
>    appends `-2`. Even fed the same base they diverge on collision, because each re-checks uniqueness
>    against its *own* table. Passing the allocated handle mostly sidesteps this (the handle is already
>    unique, so `createSiteWithRetry`'s first candidate should succeed) — but **not always**: a
>    subdomain can be taken by a soft-deleted user's site or an alias row while the handle is free.
>    Decide what happens then. Silently re-diverging is the current bug; failing loudly is defensible.
> 2. **The invariant needs a guard, not just a corrected call site.** `UserBootstrapService` uses the
>    same `subdomainBaseFromHandle` helper — check the claim/bootstrap and staff-build paths before
>    scoping to one call site. Pick a guard shape that does not fight the existing writers
>    (`PreAccountBuildService` `forceFill`s handle/handle_lc/status together; `RenameSubdomainAction`
>    owns the rename path; `HandleAllocator` allocates against `handle_lc`). A guard that fights those
>    is worse than no guard.
> 3. **Backfill the 12 diverged dev rows** to `subdomain = handle`, no alias rows (decided above).
>    ⚠️ **The Cloudflare KV namespace is SHARED between prod and dev** — one namespace, both
>    environments, a known open issue. Any backfill that dispatches `SyncSubdomainToKvJob` writes into
>    the namespace prod serves from. **Ship the backfill with a dry-run mode that prints intended
>    puts/deletes and run that first.** Note the rename changes `sites.subdomain` only — KV is keyed on
>    `handle`, which is *not* changing, so in principle no KV write is needed at all. Confirm that
>    before dispatching anything.
>
> **The regression test is the deliverable.** Assert that a build whose handle collides still yields a
> `site_url` that resolves — i.e. `handle == subdomain` after `requestBuild`. That invariant was never
> asserted, which is why this shipped. Cover the collision path specifically; a test that only exercises
> the first, non-colliding build proves nothing.
>
> ### Unit 2 — `SIGNUP-3` · document that unpublished sites are public by design
>
> **Decided: accept and document. Do not add a gate.** The work is making the codebase and docs agree
> with the behaviour that already exists.
>
> - State plainly in `docs/api.md` (and the `Site` model docblock, which currently implies otherwise)
>   that `is_published` is a **dashboard-level flag, not a public-visibility control**, and that
>   pre-account sites render publicly at `<handle>.partna.au` pre-claim by design.
> - `docs/api.md`'s Site data-model row says "if false, public site endpoint returns 404 or 403" —
>   that is true of `PublicSiteResolver:24`, `PublicDocumentDownloadController:29`,
>   `AnalyticsController:414` and `QrCodeController:34`, but **not** of the profiles route or the KV
>   write. Document which paths gate and which do not, rather than implying one rule.
> - **Leave the four existing gates alone.** They are not bugs; document them.
> - Flag for Josh in the final report whether this changes anything for the **LEGAL-2 reviewer-PII item
>   due before pilot** — do not action LEGAL-2 here, just say whether it interacts.
>
> ### Unit 3 — `SIGNUP-2` · Instagram identity fields are always null
>
> **Diagnose before fixing. Do not guess the key name.** `followersCount` and `postsCount` populate from
> the same `$profile` node, so the node resolves — the specific keys do not. Two candidate causes: the
> actor emits snake_case (`full_name`), or it does not emit the field at all for these accounts.
> `InstagramConnector.php:140` already tolerates both spellings via
> `Fields::firstString($profile, ['fullName', 'full_name'])`; `InstagramConnectionSeeder.php:152` does not.
>
> Settling this needs **one raw Apify response captured and inspected** — a billed run, so do it once,
> paste the raw profile node's key set into the findings doc, and fix against what you actually see.
> A defensive `firstString` over both spellings is cheap and probably right regardless, but ship it as
> *"matches the connector's existing tolerance"*, not as a fix you have proven.
>
> Separately: `biography` is never requested by the seeder at all, so IG-built sites have no bio by
> construction. Decide whether that is in scope; if yes, check what `InstagramIdentitySync` does with it
> before adding it to the payload.
>
> ### Unit 4 — `SIGNUP-4` · Google address not folded into `location_*`
>
> `sector` / `sector_source` folded correctly from the same payload, so `IdentitySync` ran — the address
> leg specifically did not. Find out whether the fields are absent from the Places field mask, dropped by
> `GoogleBusinessPayload::stripThirdPartyPii`, or simply not mapped by `applyFromGooglePayload`. Fold via
> the observer (`IntegrationConnectionObserver::saved`) — **never call `IdentitySync` directly**, or the
> fold runs twice. Respect `AccountCapabilities`: business accounts get full overwrite, `partna` accounts
> do not.
>
> ### Unit 5 — `SIGNUP-5` · docs drift
>
> Documentation only. The **code is right and the doc is wrong** — do not "fix" the resource to match the
> doc. Update `docs/api.md` §3 so it says `subdomain` is present from `pending` (with the reason already
> written in `PreAccountBuildStatusResource`'s comment) while `site_url` stays ready-gated, and reconcile
> the claim error list with whether `409 BUILD_NOT_READY` can still fire. Fold this into Unit 2's docs
> commit if both land together.
>
> ### Unit 6 — `SIGNUP-6` · log noise
>
> `PARTNA_MEDIA_DISK not set` ~20× per build on dev. Per CLAUDE.md's opportunistic-fix rule, **absorb it
> into whichever commit already has that file open** — no separate plan, unit or review. Prefer fixing
> the dev env var over silencing the warning; confirm which disk is actually correct before changing
> either. ⚠️ `cloud environment:variables` **replaces all** — never push a partial set.
>
> ---
>
> ### Explicitly out of scope
>
> - **`SIGNUP-7`** — retiring `sites.subdomain` (308 refs, 2 DB views, the alias table, 2 frontend wire
>   fields). Separate branch, separate spec. Do not start it, and do not "prepare" for it by deprecating
>   things here.
> - **LEGAL-2** — mention the interaction, do not action it.
> - Anything in the findings doc's **"What worked — do not fix these"** list.
>
> ### Ground rules
>
> - **Plan → implement → independent review** per `scripts/audit/fix-flow.md`. The reviewer is always a
>   separate instance from the implementer. No sign-off pauses — the gates are pre-cleared.
> - `composer test` before done. ⚠️ Tests run **SQLite**, prod is **Postgres** — verify constraint-bound
>   behaviour against `supabase/migrations/` DDL, not just a green suite.
> - **No schema migration is in scope.** If you conclude one is needed, stop and say so rather than
>   writing it — that reopens the gate. Never create Laravel migration files.
> - Do **not** modify `.env` directly. Do **not** push or merge to `development` / `production` without
>   Josh saying so. Do **not** run `git stash` — other sessions share this checkout.
> - Debugging starts with `cloud env:logs partna development --minutes 10`, not with code.
> - The two test builds from the run are still live on dev and unclaimed
>   (`019fd066-6260-…` / `simondoylehair2`, `019fd066-89fe-…` / `inspire-me-hair-artistry`). Use them as
>   fixtures; they auto-prune 2026-09-04. New builds cost real Apify + Places spend — reuse before you rebuild.
>
> ### Closing out
>
> Tick each finding's box in `docs/reviews/2026-08-05-signup-flow-live-test.md` and update its
> `## Progress` counts. `SIGNUP-7` stays unticked — it is tracked, not executed. **A finding closed
> WONTFIX with a stated reason is a legitimate outcome**; leaving one open forever is not. Commit code +
> the ticked doc together: `fix(review): <unit> — <ids>`.
>
> ### Final report
>
> - `SIGNUP-1`: the guard shape and **why it does not fight the existing writers**, what happened to the
>   12 diverged rows, **what the KV dry-run showed before anything was written**, and the exact invariant
>   the new test asserts
> - `SIGNUP-2`: the actual key set the Apify profile node returned — paste it
> - `SIGNUP-3`: whether it interacts with LEGAL-2
> - anything you did not fix — flagged, not buried
