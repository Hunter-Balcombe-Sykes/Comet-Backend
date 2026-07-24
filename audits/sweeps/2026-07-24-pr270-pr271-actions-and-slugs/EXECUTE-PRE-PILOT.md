# Execute prompt — pre-pilot slice (PR #270 + #271 audits)

Paste the block below into a **fresh Claude Code session** at the repo root.
It works **only the 7 pre-pilot findings** from the 49 in `CONSOLIDATED.md` — everything
else in that file is deliberately deferred until after pilot.

---

## The prompt

> Work the pre-pilot slice of `audits/sweeps/2026-07-24-pr270-pr271-actions-and-slugs/CONSOLIDATED.md`.
>
> Follow `scripts/audit/fix-flow.md` exactly — plan → implement → **independent** review per unit,
> models read from that file's `## Execution policy` header. Do not invent a different flow.
>
> **Scope — these 7 findings ONLY.** Do not touch any other finding in the file, even if you
> notice it while editing. The other 42 are deferred by an explicit decision.
>
> | Unit | IDs | Eff | Note |
> |------|-----|-----|------|
> | 1 | `SEC-1` | S | **Gate: auth** — origin/IDOR guard |
> | 2 | `SEC-2` | S | **Gate: auth/account-recovery** |
> | 3 | `SEC-3` | S | public-wire allowlist |
> | 4 | `TEST-3` | M | live bug, not a test gap |
> | 5 | `271-DINT-1` + `271-DINT-4` | M+M | **bundle — same root cause, same files** |
> | 6 | `271-PRIV-2` | M | **product decision required first** |
>
> **Work them in that order** (security first, then the live bug, then slugs, then PII).
> Units run sequentially — never in parallel; several touch overlapping files.
>
> ### Step 0 — isolated worktree (REQUIRED)
> Other work is running in this repo concurrently, so do **not** work in the main tree:
> ```bash
> git fetch origin
> git worktree add -b audit-fix/pre-pilot-2026-07-24 \
>   "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-pre-pilot-2026-07-24" \
>   origin/development
> cd "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-pre-pilot-2026-07-24"
> composer install
> cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env
>
> # Sanity gate — all three must be present, or you are on the wrong base. STOP if any fail.
> ls audits/sweeps/2026-07-24-pr270-pr271-actions-and-slugs/CONSOLIDATED.md
> ls app/Services/Site/ItemSlugAllocator.php app/Services/Platforms/EventSlugSync.php
> ls app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
> ```
> The worktree needs its **own** `composer install` and `.env` — do not symlink either
> (symlinked `vendor/`/`.env` is the known cause of phantom feature-test failures).
> All commits land on `audit-fix/pre-pilot-2026-07-24`. Never commit to `development`.
>
> ⚠️ **Base off `origin/development`, not the main working tree.** The slug files —
> `ItemSlugAllocator.php`, `EventSlugSync.php`, `MenuItemObserver.php`, `BackfillItemSlugs.php` —
> arrived with PR#271 and the sweep folder itself was committed separately. The sanity gate above
> catches a wrong base before you waste a unit on it.
>
> ### Concurrent work — files another session owns right now
> Two other worktrees are live on the platforms domain. Their file sets and this slice's do
> **not** intersect, and it must stay that way:
>
> | Worktree | Owns (do not edit) |
> |---|---|
> | `backend-wt/connect-async-impl-2026-07-24` | `Jobs/Platforms/ConnectFetchJob.php`, `RefreshConnectionJob.php`, `Providers/PlatformRegistryServiceProvider.php`, `routes/api/platforms.php`, `Api/Platforms/{AppleController,SkoolController}.php`, `Concerns/{DefersBespokeConnect,ManagesIntegrationConnection}.php`, `Models/Core/Site/IntegrationConnection.php`, `CheckPlatformRefreshBacklogCommand.php`, `Strategies/Fetch/SkoolFetch.php` |
> | `backend-wt/connect-shop-async-2026-07-24` | `Concerns/DefersBespokeConnect.php` |
>
> 🔴 **Never edit `tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php`.**
> The impl branch edits it on every phase (`17e06369` bumped it to 75 most recently, and more
> connect phases are queued). It carries a hardcoded route-count assertion
> (`expect($readRoutes->count())->toBe(75)`) plus a full sorted route-list array — the single
> worst merge-conflict surface in the repo. Nothing in this slice changes routes, so you have no
> legitimate reason to open it. `TEST-3`'s guard test goes in a **new** file.
>
> If a fix seems to require one of the files above, **stop and ask** rather than editing it.
>
> Running the suite concurrently with the other sessions is safe — `phpunit.xml` pins
> `CACHE_STORE=array`, `DB_CONNECTION=sqlite`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`,
> so there is no shared Redis or Postgres state between worktrees. It only costs CPU.
>
> ### Blocker gate — pause before implementing
> Per `fix-flow.md`, a unit pauses for explicit sign-off if it touches auth/authorization,
> money, a DB migration, is L/XL, or is listed standalone.
> - **Unit 1 (`SEC-1`)** and **Unit 2 (`SEC-2`)** touch access-control / account-recovery →
>   produce the plan, present blast radius, and **wait for Josh's go-ahead** before coding.
> - **Unit 6 (`271-PRIV-2`)** is a **product decision, not just a fix**: Google Business
>   reviewer PII (name, photo, review text) is currently republished on CDN-cached public
>   sitepages. Ask Josh which end state he wants — drop reviewer identity entirely, redact to
>   first-name/initial, or keep and accept — **then** implement. Do not pick for him.
> - Units 3, 4, 5 proceed without asking.
>
> ### Per-finding starting points (verify before trusting)
> Each finding's `Where:` / `Technical:` / `Evidence:` blocks in `CONSOLIDATED.md` are the
> spec. **Verify the premise against current code first** — some findings were written against
> a slightly older tree and a cited line may have moved.
> - `SEC-1` — `app/Http/Controllers/Api/PublicSite/AnalyticsController.php` `originAllowed()`,
>   the `$originHost === null` branch. Fix = drop the public `site_id`+`subdomain` fallback and
>   return false when both `Origin` and `Referer` are absent.
> - `SEC-2` — `app/Services/User/AccountDeletionService.php` `restoreEmailFromAuditSnapshot()`;
>   widen `where('event', EVENT_CONFIRMED)` to include `EVENT_ADMIN_INITIATED`. Add a test
>   covering `adminInitiate()` → `adminCancel()` → email restored.
> - `SEC-3` — `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php`; non-array
>   `payload` values bypass the per-platform allowlist.
> - `TEST-3` — **premise already verified against `origin/development` (2026-07-24): confirmed
>   real.** `PublicIntegrationConnectionResource::ALLOWLIST` has 34 platform keys; the registry
>   exposes `selection` routes for `discord`, `kick`, `medium`, `snapchat`, `telegram` and none of
>   the five has an entry, so their payloads filter to empty on every public sitepage. Add the five
>   entries **and** a guard test that fails when a registered link-only platform lacks one.
>   Put the guard in a **new** test file — not in the golden master (see the concurrency table).
>   ⚠️ That guard becomes a repo-wide invariant, and the two live connect branches are actively
>   adding platforms. That is intended, but say so in your final report: whichever branch merges
>   second may need one extra `ALLOWLIST` line to go green. (Skool already has an entry, so
>   nothing breaks today.)
> - `271-DINT-1` + `271-DINT-4` — the slug system is observer-driven, so bulk scrape-rebuild
>   writes never mint (`MenuFetchJob`) and event slugs are never retired (`ItemSlugAllocator::forget()`
>   is called from exactly one place). Fix both ends together; `slugs:backfill` already exists
>   and is the natural place to enforce the invariant.
>   Call-site map (verified): `EventSlugSync` is invoked from `Observers/Core/IntegrationConnectionObserver.php:129`
>   — **not** from `ConnectFetchJob`/`RefreshConnectionJob` directly. Those jobs are owned by
>   another branch; you reach the same behaviour through the observer without touching them.
>   Because the live work moves connect writes into a queued job, add a test that slug
>   sync/retirement still fires when the connection is saved from a job context, not just a
>   controller.
>
> ### Repo hazards — do not trip these
> - **Never run `git stash`** (including `--autostash` on pull/rebase). A real shared entry is
>   present — `stash@{0}: On audit-fix/middleware-2026-07-06: foreign-wip-during-middleware-audit`
>   — and popping the wrong one destroys another session's work. This applies to every subagent
>   you spawn: put it in their prompt explicitly.
> - **Never edit the main working tree** at `Herd/Side Street/backend`. It carries other sessions'
>   uncommitted plan edits under `docs/superpowers/plans/`. Work only inside your worktree.
> - Tests run **SQLite**, prod is **Postgres**. Any constraint-bound write must be checked
>   against the real DDL in `supabase/migrations/`, not just a green suite.
> - `composer test` needs `COMPOSER_PROCESS_TIMEOUT=0` (the 300s default kills it).
> - Never run `composer test` in the main session while a subagent is also running tests.
> - No Laravel migrations — schema changes are raw SQL in `supabase/migrations/`.
> - Run `php artisan pint` only on files you actually changed; don't churn the baseline.
>
> ### Definition of done
> A box goes `- [ ]` → `- [x]` **only** after tests pass AND the independent reviewer returns PASS.
> Tick the finding in `CONSOLIDATED.md` and bump its `## Progress` counts, then commit code +
> ticked file together as `fix(audit): <unit> — <ids>`.
>
> When all 7 are done: run the full suite once for the branch, then report units done, units
> blocked (with reason), test status, and the branch name. **Do not** run `archive-done.sh` —
> 42 findings remain deliberately open, so the folder must stay put. **Do not** push or merge;
> Josh reviews and merges.

---

## Why these 7 and not the other 42

| Deferred | Reason |
|---|---|
| `TEST-1`, `TEST-2` (both P1, both L) | CI-topology work — prevents *future* regressions, can't cause a pilot incident. **Accepted risk:** migrations ship during pilot with CHECK/FK/index guards inert in CI. |
| `MIG-1`, `271-MIG-1` | Lock guards on table creation; against a fresh/empty prod cutover DB there are no rows to lock. Non-event. |
| `PRIV-1`, `PRIV-2`, `PRIV-4` | Privacy hygiene that matters at volume, not pilot scale. Revisit `PRIV-4` (no retention on `content_popularity_scores`) before real traffic. |
| remaining P2 test-coverage + all 22 P3 | Coverage debt and micro-optimisations; nothing user-visible. |
