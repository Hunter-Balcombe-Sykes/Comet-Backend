# Pre-pilot fix — staff `{category}` 500 + bootstrap handle race

Pulls the two **pre-pilot-relevant** items out of the deferred Gate A backlog into their own small,
independently-mergeable fix. Both were *discovered during* the Gate A sweep (not by any lens) and both
live **outside** the `audit-fix/gate-a-2026-07-20` branch's scope:

- **DISC-5 (P2)** — the entire staff service-category management surface (show/update/destroy/restore/
  forceDestroy) currently **500s in production**. A live prod bug, hence pre-pilot.
- **DISC-6 (P2)** — the authenticated signup path (`/api/bootstrap`) 500s on a concurrent handle
  collision — the exact TOCTOU B9 fixed on the *pre-account* path, still unpatched on the *bootstrap*
  path.

Both are recorded in `audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md` under
`## Discovered during execution`. This prompt is standalone — it does NOT depend on the Gate A P2/P3
sessions and can be worked and merged before them.

**Branch (recommendation):** a fresh branch off `development` —
`fix/staff-category-and-bootstrap-race-2026-07-21` — so it reviews and merges ahead of the deferred
Gate A remainder. The B9 reference (`f8cd9462`) is in the object store and reachable via `git show`
from any branch, so branching off `development` costs you nothing. (If Josh would rather these ride the
gate-a branch, say so and adjust — but the default is standalone.)

**How to use:**
1. Open a **fresh Claude Code session in this repo** on model **Opus**.
2. Paste everything from `=== PROMPT START ===` to the end as your first message.
3. Neither unit trips the blocker gate (no migration, no money, no auth-model change). Both proceed
   without sign-off — but both are real bugs on hot paths, so full plan → implement → independent
   review, not the combined mechanical cadence.

---

```
=== PROMPT START ===

Fix two pre-pilot bugs, each its own unit (own plan, own commit, own independent review). Follow
scripts/audit/fix-flow.md. They are unrelated code paths — do them in order, commit separately, do NOT
fold them together.

## First: branch + orient
- `git fetch && git checkout development && git pull` — confirm you're current.
- `git checkout -b fix/staff-category-and-bootstrap-race-2026-07-21`
- Read `audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md` → the `## Discovered during execution` section,
  entries **DISC-5** and **DISC-6**. That is the source of truth for both findings' rationale and
  reproduction. Line numbers in it may have drifted — locate code by reading, not by cited line.

## Verify every premise before touching a file
Both bugs were confirmed present on `development` as of 2026-07-21, but re-confirm — a second developer
is active on this repo:
- DISC-5: confirm `User` still has NO `categories()` relation (only `serviceCategories()`), and the
  `{category}` routes still `->scopeBindings()`. If someone already renamed the param or added an
  alias, mark `no_change_needed` with evidence.
- DISC-6: confirm `UserBootstrapService`'s catch still only rescues email reuse and re-throws handle
  collisions, and `HandleAllocator::allocate()` is still a lockless `while(...->exists())` loop.

## Standing decisions (carried from the Gate A sessions — they apply here too)
- **Pin subagent models** (`model: sonnet` for implement + review). Inheritance defaults to the
  main-loop model and an Opus fan-out exhausts the budget.
- **Never `git stash` / `git checkout <file>` / `git restore` / `git reset`** — a second active
  developer + a prior stash on this machine. Read-only git only; `git show <ref>:<path>` to read old
  content. Forbid `git stash` explicitly in every spawned subagent prompt.
- **SQLite ≠ Postgres.** Tests run on SQLite; prod is Postgres. A `UniqueConstraintViolationException`
  surfaces from both, but message text differs — never string-match the DB message; catch the typed
  exception. Verify any column you touch against `supabase/migrations/` DDL, not `tests/Pest.php`.
- **Before every commit:** `git diff --cached --stat`, confirm the file list is exactly what you
  intended, surgical diff, no `php artisan pint` sweep. One commit per unit. Trailers:
  Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
  Claude-Session: <your session url>
- **Do not run `composer test` while a subagent is running tests.** Gate on it once per unit after
  implement+review.

## Unit 1 — DISC-5: staff `{category}` routes 500 in production (P2) — full cadence

**Defect.** `routes/api/staff.php` binds `{category}` on the staff service-category routes under a group
with `->scopeBindings()`. Laravel scopes the `ServiceCategory` child to the parent `User $professional`
via `Str::plural(Str::camel('category'))` = `categories()` — but `User` defines the relation as
`serviceCategories()`. `User::categories()` doesn't exist, so every such route throws
"Call to undefined method" → 500. Affects **show, update, destroy, restore, forceDestroy** across
**both** route groups (~5 routes). `index()` and `store()` don't scope-bind a `{category}`, which is why
they work — and why `StaffOwnedRecordActorGateTest` tests these via `Gate` directly, not HTTP.

**Fix — two options, pick with rationale in the plan:**
- **(a) Rename the route param `{category}` → `{serviceCategory}` (recommended).** Scoped binding then
  resolves `serviceCategories()`, which exists. This is a **name-only** change: the public URL path is
  unchanged (the param name isn't in the path). You MUST rename in lockstep:
  - every `{category}` in `routes/api/staff.php` (both groups),
  - the matching controller method args in
    `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceCategoryManagementController.php`
    (`ServiceCategory $category` → `ServiceCategory $serviceCategory` — Laravel matches the binding to
    the param by name, so a mismatch silently breaks binding),
  - any `$request->route('category')` reads and any `StaffUpdateServiceCategoryRequest` /
    policy `authorize()` that reads the `category` route param (grep first — none found on
    2026-07-21, but re-verify).
  Cleaner: no duplicate relation on the model.
- **(b) Add a `categories()` alias relation on `User`** returning the same as `serviceCategories()`.
  Smaller diff, but pollutes the model with a relation that exists only to satisfy a binding
  convention. Only choose this if (a) turns out to have a hidden coupling to the param name.

**Test (must fail before, pass after).** Add an HTTP-level feature test that hits at least
`GET .../service-categories/{category}` and one write route (e.g. `PATCH`) for a category **owned by the
target professional**, asserting a 2xx and the right payload — NOT just "the route resolves". A test
that only checks status is worth little here; assert the returned category id matches. Confirm the
scoped binding still 404s a category that belongs to a *different* professional (the whole point of
`->scopeBindings()` is tenant isolation — don't regress it). Put it beside the existing staff
service-category tests.

## Unit 2 — DISC-6: bootstrap handle-collision TOCTOU → unhandled 500 (P2) — full cadence

**Defect.** `app/Services/User/UserBootstrapService::bootstrap()` catches
`UniqueConstraintViolationException` but only rescues the **email**-reuse case
(`emailReuseGuard->isClaimedByAnotherAuthUser`); its own comment says any other unique violation —
including `core_users_handle_lc_unique` — **re-throws unchanged**. `HandleAllocator::allocate()`
(shared, extracted verbatim from the old `BootstrapRequest::generateHandleFromDisplayName`) is a
lockless `while (User::where('handle_lc', …)->exists())` loop — a genuine TOCTOU: two concurrent
authenticated signups that derive the same handle both pass the EXISTS check, then one 500s on the
unique index.

**Fix — mirror B9's pattern (commit `f8cd9462`).** B9 fixed the identical race on the *pre-account*
path. Read the reference:
- `git show f8cd9462:app/Services/PreAccount/PreAccountBuildService.php` — study
  `createProvisionalUserWithRetry()` + `tryCreateProvisionalUser()`: each INSERT attempt runs in its
  own `DB::connection('pgsql')->transaction(...)` (a **savepoint** when nested in the outer
  transaction), so a `core_users_handle_lc_unique` violation rolls back only to the savepoint — leaving
  the outer transaction healthy — and returns null; the caller's bounded `for` loop (5 tries)
  re-`allocate()`s (now seeing the just-committed colliding row) and retries. Exhaustion is `report()`ed
  to Nightwatch, then surfaced as a friendly error, not a raw 500.
- Apply the same shape to the bootstrap path. Keep the existing **email**-reuse rescue exactly as-is —
  you're *adding* handle-collision handling, not replacing the email branch. Distinguish the two
  violations (email vs handle) so each surfaces its correct friendly error and neither swallows the
  other. Pin the connection to `pgsql` (`DB::connection('pgsql')`), not the default — see the SQLite≠
  Postgres note above.
- Decide in the plan whether the retry wraps `HandleAllocator::allocate()` in the *caller*
  (`UserBootstrapService`) or hardens `allocate()` itself. `allocate()` is shared by more than one
  signup path, so a change there has wider blast radius — trace its callers (`git grep -n
  'allocate('`) before deciding, and prefer the smaller-blast-radius option unless hardening the shared
  method is clearly correct for all callers.

**Test.** B9 shipped `tests/Feature/PreAccount/PreAccountBuildHandleRaceTest.php` — read it for the
technique to simulate the concurrent collision (it forces the unique violation deterministically rather
than racing threads). Write the equivalent for the bootstrap path: assert that a handle collision
retries and succeeds with a fresh handle (or surfaces the friendly error on exhaustion), and that email
reuse still throws `EMAIL_ALREADY_REGISTERED` unchanged (no regression to the existing branch).

## When both units are done
1. `composer test` once on the branch — must be green.
2. Tick the `[ ]` boxes for **DISC-5** and **DISC-6** in
   `audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md` (`## Discovered during execution`), with a one-line
   note pointing at the two commit SHAs. Include that file in each unit's commit or a final bookkeeping
   commit — your call, but the record must not claim these are still open. (Low conflict risk: the
   deferred Gate A P3 session is explicitly told these are out of its scope and won't touch them.)
3. Report: both units' status, test result, the branch name, and the two commit SHAs. Note for Josh
   that the Gate A P3 prompt's step-5 "still open" list can now drop DISC-5 and DISC-6.
4. **Do not push to `development` or `production`.** Josh reviews and merges.

## Stop and ask if
- DISC-5 option (a) turns out to have a hidden coupling to the `category` param name (a `route('category')`
  read, a named-route dependency, a frontend contract) that makes the rename non-trivial — surface it
  with the blast radius before proceeding.
- Two review rounds fail on the same unit — mark it blocked and surface it.
- Either premise turns out already-fixed by the second developer — mark `no_change_needed` with the
  commit evidence and move on; don't invent work.

=== PROMPT END ===
```
