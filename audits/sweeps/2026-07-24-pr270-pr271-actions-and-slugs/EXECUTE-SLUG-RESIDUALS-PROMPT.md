# Execute — slug-lifecycle residuals (two follow-ups from the 2026-07-24 pre-pilot slice)

Two issues found during the pre-pilot slice's independent reviews, both left as follow-ups.
Neither is in `CONSOLIDATED.md` — they were surfaced by the reviewers, not the scan. Work them
as a two-unit `execute audit`-style run following `scripts/audit/fix-flow.md`
(plan → implement → **independent** review per unit).

## Scope — these two ONLY

| Unit | ID | Eff | Note |
|------|-----|-----|------|
| 1 | `SLUG-RESIDUAL-1` | S–M | `deleteCategory`'s mass delete leaks orphaned menu-item slugs (same class as 271-DINT-1) |
| 2 | `SLUG-RESIDUAL-2` | M | Postgres-gated sentinel for the 25P02 savepoint class — this bug class has shipped 3× |

Do not touch anything else. Do not reopen or re-tick `CONSOLIDATED.md` — its 42 deferred findings
stay deferred and the folder must not be archived.

## Execution policy (models)

- **Plan+Implement:** Sonnet 4.6 — combined pass, both units are S/M.
- **Review:** Sonnet 4.6 — a *separate, independent* instance (never the implementer), per unit.
- **Per-item override:** escalate `SLUG-RESIDUAL-2`'s implement or review to Opus **only if** the
  Postgres-semantics verification proves gnarly. Default to Sonnet.

## Blocker gate

Neither unit is a blocker under `fix-flow.md`: no auth/authorization change, no money, no DB
migration, no schema change, neither is L/XL, neither is standalone-flagged. **Both proceed
without sign-off.** (Unit 1 edits a deletion path but only *adds* cleanup — it does not change
what rows get deleted.) Run the two units **sequentially**, Unit 1 then Unit 2.

---

## Step 0 — isolated worktree (REQUIRED)

Base off `origin/development` (it has moved well past the pre-pilot slice — the two connect
branches merged 2026-07-25). Do **not** work in the main tree.

```bash
git fetch origin
git worktree add -b audit-fix/slug-residuals-2026-07-25 \
  "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-slug-residuals-2026-07-25" \
  origin/development
cd "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-slug-residuals-2026-07-25"
composer install
cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env

# Sanity gate — all four must resolve, or you are on the wrong base. STOP if any fail.
grep -n "function deleteCategory" app/Http/Controllers/Api/Platforms/MenuContentController.php
grep -n "function forgetMany" app/Services/Site/ItemSlugAllocator.php
ls tests/Feature/Bootstrap/SiteProvisioningSavepointTest.php
grep -n "function deleted" app/Observers/MenuItemObserver.php
```

Its own `composer install` + copied `.env` — **do not symlink** either (symlinked `vendor/`/`.env`
is the known cause of phantom feature-test failures). All commits land on
`audit-fix/slug-residuals-2026-07-25`. Never commit to `development`.

### Concurrent work
No other worktrees are live as of this writing — confirm with `git worktree list` before starting.
If any appear, treat their file sets as off-limits and re-check that neither unit intersects them.
**Never edit the main working tree.** **Never edit
`tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php`** — neither unit
changes routes, so you have no reason to open it.

---

## Unit 1 — `SLUG-RESIDUAL-1` — `deleteCategory` leaks orphaned menu-item slugs

**Verify the premise first against current code.**

**Where:** `app/Http/Controllers/Api/Platforms/MenuContentController.php`, `deleteCategory()` (~line
107). Inside the `DB::connection('pgsql')->transaction()` at ~line 123, orphaned non-manual dishes
are hard-deleted at ~line 144 via `MenuItem::query()->whereIn('id', $orphans->pluck('id'))->delete()`.

**Why it's a bug:** that is a query-**builder** mass delete — it hydrates no models, so it fires
no Eloquent events. `MenuItemObserver::deleted()` (which is the only caller of
`ItemSlugAllocator::forget()` for menu items, at `app/Observers/MenuItemObserver.php:42`) never
runs. The orphaned dishes' `site.item_slugs` rows survive. Because `item_slugs_unique_slug
(user_id, slug)` is **non-partial** (no `WHERE is_current`), a dead `is_current = true` row squats
that `(user_id, slug)` pair permanently — the same defect class 271-DINT-1 fixed for
`MenuFetchJob`. Confirmed present on dev: today's `--prune` run found 0 such orphans, so it hasn't
bitten yet, but every category delete of a scraped dish creates one.

**Scope check before editing:** grep this controller for every `MenuItem` delete. Confirm the
single-item paths (`destroyItem()` ~line 397 → `$model->delete()`, reached by `deleteItem` and
`bulkDeleteItems`) go through `$model->delete()`, which **does** fire the observer — leave those
alone. Only `deleteCategory`'s builder-level mass delete bypasses it.

**Fix:** inside the same transaction closure, capture the orphan ids and call
`app(ItemSlugAllocator::class)->forgetMany((string) $menu->user_id, ItemSlugAllocator::TYPE_MENU_ITEM, <orphan-ids-as-strings>)`
alongside the mass delete. `$menu` is already in the closure's `use (...)`, and `$menu->user_id`
is the tenant key (`item_slugs.user_key`/`user_id` scope). Cast ids to string to match how the
allocator keys rows.

- **Placement — this is the one subtlety.** `forgetMany()` is a plain chunked `whereIn(...)->delete()`
  with **no** insert-and-catch, so it **cannot** raise a unique violation and **cannot** trigger the
  Postgres 25P02 transaction-abort. It is therefore SAFE inside the open transaction — and belongs
  there, so the slug-forget rolls back with the row delete if the transaction aborts. This is the
  **opposite** of `MenuFetchJob::reconcileItemSlugs()`, which had to stay post-commit *because* it
  calls the insert-and-catch `ensureCurrent()` path. Do not blindly copy MenuFetchJob's post-commit
  placement here.
- **Do NOT add an `ensureCurrent()` call.** The orphans are being deleted; there is nothing to
  re-mint. Only `forgetMany()`.

**Test:** Feature test under `tests/Feature/Platforms/` (check whether `ManualMenuContentTest.php`
already covers `deleteCategory` and extend it; else a new file — do NOT define new global helper
functions, Pest shares a global namespace). The `item_slugs` table needs `setupItemSlugsTable()`
in `beforeEach` — without it the assertion passes against a missing table. Cover:
1. A non-manual dish that is a member of **only** the deleted category, holding an `is_current`
   `item_slugs` row → after `deleteCategory`, that slug row is **gone**.
2. Control: a dish that is **also** a member of another category (not orphaned, not deleted) →
   its slug row **survives** unchanged.
3. (If cheap) a manual dish's slug survives, mirroring the existing suppression logic.
**Prove test 1 fails without the fix** (hand-revert the `forgetMany` call, run, restore by hand;
never `git stash`, never `git checkout` a modified file).

Tests run **SQLite**, prod is **Postgres** — use a **real** `core.users` row in the test (the
SQLite mirror lacks the `item_slugs → core.users` FK, so a fake user id passes locally and would
violate the FK on Postgres).

---

## Unit 2 — `SLUG-RESIDUAL-2` — Postgres-gated sentinel for the 25P02 savepoint class

**Context:** commit `3cd4ff63` made `ItemSlugAllocator::insertUnique()` safe to call inside a
caller's transaction (it now uses `insertOrIgnore` + a nested `transaction()` savepoint), because
the old insert-and-catch shape aborted the whole enclosing transaction on Postgres (SQLSTATE 25P02)
while passing on SQLite. **SQLite cannot reproduce that failure**, so the entire existing suite is
blind to a regression here. This bug class has now shipped **three times** (site-provisioning
signup, the pre-pilot slug slice, and the `MenuScanApplier` exposure). Add the sentinel the repo
already has a proven pattern for.

**Exemplar to mirror exactly:** `tests/Feature/Bootstrap/SiteProvisioningSavepointTest.php`. Copy
its shape:
- a `savepointSuiteIsPostgres()`-style guard returning `DB::connection()->getDriverName() === 'pgsql'`,
  wrapped in `if (! function_exists(...))` to avoid a global-namespace collision;
- every test body opens with `if (! <gate>()) { $this->markTestSkipped('PostgreSQL
  transaction-abort semantics required; SQLite cannot reproduce this.'); }`;
- a **mechanism sentinel** using a `CREATE TEMPORARY TABLE` with a UNIQUE column — **no** FK, **no**
  `auth.users`, **no** `core.users`, so it runs against any real Postgres as `app_backend` without
  special grants;
- a file-header docblock explaining the mechanism and the run command (mirror the exemplar's).

**What the new sentinel must prove** (mechanism level, temp-table, independent of the `item_slugs`
FK chain):
1. An `INSERT ... ON CONFLICT DO NOTHING` (or a plain insert wrapped in a nested `DB::transaction()`)
   that hits a unique violation **inside** an outer `DB::transaction()` leaves the outer transaction
   **healthy** — a subsequent insert in the same outer transaction succeeds and the whole thing
   commits. This is the post-fix behaviour.
2. The negative control: a bare `insert()` + `catch(UniqueConstraintViolationException)` inside the
   outer transaction **poisons** it — the next statement raises 25P02. This proves the sentinel can
   actually distinguish fixed from broken (the exemplar ships this negative control; match it).

**Location:** `tests/Feature/Site/ItemSlugAllocatorSavepointTest.php` (or
`tests/Feature/Platforms/MenuScanApplierSavepointTest.php` if you prefer the integration framing —
but the temp-table mechanism sentinel is the durable, dependency-free guard; prefer it). Match the
naming of whichever directory you choose.

**Verification — read this honestly.** By design the sentinel **skips on SQLite**, so
`composer test` (the default) will report it skipped, not passed — confirm it skips cleanly and does
not error, and that it does not slow or break the default suite. The *value* is only realised
against real Postgres, so also run it against **dev Supabase** and confirm it passes there:
- Preferred: point a local `pgsql` connection at dev Supavisor and run
  `DB_CONNECTION=pgsql DB_HOST=<dev-supavisor-host> DB_PORT=5432 DB_DATABASE=postgres
  DB_USERNAME=app_backend.glncumufgaqcmqhzwrxm DB_PASSWORD=<secret> php artisan test --filter
  ItemSlugAllocatorSavepointTest` (the temp-table mechanism sentinel needs only `app_backend`'s own
  `public`-schema rights, so it works without auth.users grants). Local `.env` `DB_HOST` is dead —
  use the dev Supavisor host/secret, not the local one.
- If a real Postgres connection genuinely cannot be reached from the run environment, author the
  sentinel to match the proven exemplar, confirm the SQLite skip path, and **state plainly in the
  report that the Postgres-green run was not executed** — do not claim a pass you did not observe.
  The exemplar already proves this exact mechanism on Postgres, so a faithful copy is high-confidence
  even unrun.

---

## Repo hazards — do not trip these

- **Never run `git stash`** (including `--autostash` on pull/rebase) — a real shared stash entry
  exists in this repo; popping the wrong one destroys another session's work. Put this in every
  subagent prompt.
- **Never edit the main working tree** at `Herd/Side Street/backend`. Work only in your worktree.
- Tests run **SQLite**, prod is **Postgres** — check any constraint-bound write against the real DDL
  in `supabase/migrations/`, not just a green suite. `item_slugs` has an FK to `core.users` and an
  `item_type` CHECK that the SQLite mirror lacks.
- `composer test` needs `COMPOSER_PROCESS_TIMEOUT=0` (300s default kills it). The Bash tool has a
  **120s default timeout that silently backgrounds longer commands** — pass an explicit
  `timeout: 600000` for any suite run. `tests/Feature/Platforms/` alone is ~235s.
- Never run `composer test` in the main session while a subagent is also running tests.
- No Laravel migrations — neither unit needs a schema change. If you conclude one is needed, STOP.
- Run `php artisan pint` only on files you changed; don't churn the baseline.
- If verifying Unit 2 via the Cloud CLI: `cloud command:run development --cmd="php artisan …"` needs
  the **full `php artisan`** prefix (a bare command name is "command not found"), and its outer
  `"status":"command.success"` means only that the shell ran — the truth is the nested `exitCode`.
  (Note: the deployed env is built `--no-dev`, so `php artisan test` may be unavailable there — the
  local-pgsql-against-dev path above is the reliable route.)

## Definition of done

A unit is done only after its tests behave as specified (Unit 1 green incl. the pre-fix-fails proof;
Unit 2 skips cleanly on SQLite and, ideally, passes on dev Postgres) **and** the independent
reviewer returns PASS. Commit code + tests per unit as `fix(slugs): <unit> — <id>`. When both are
done, run the full suite once for the branch (`COMPOSER_PROCESS_TIMEOUT=0 composer test`), then
report: units done, units blocked (with reason), test status (incl. whether the Unit 2 Postgres run
was actually executed or only the SQLite skip), and the branch name. **Do not** push or merge —
Josh reviews and merges. **Do not** touch `CONSOLIDATED.md` or run `archive-done.sh`.
