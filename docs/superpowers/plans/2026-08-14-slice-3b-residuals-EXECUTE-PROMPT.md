# EXECUTE PROMPT — slice 3b residuals

Nine units closing what slice 3b (`ca81466b0`) shipped open, plus one product
decision the owner has since made. Paste everything below the line into a fresh
session. It is self-contained.

---

**First: rename this session to `3b-residuals`** so it is identifiable in Remote
Control rather than appearing as a machine name.

You are closing a defect list, not building a feature. There is no spec to argue
from — the authority is the code, the tests you write, and the reasoning
recorded here. Where a unit says *decide*, decide and state the evidence.

## Read before starting

1. `CLAUDE.md` — the architecture rules bind every unit.
2. `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` **§19**
   — slice 3b's checkpoint. §19.7 is the debt list most of these come from.
3. `docs/wire-changes/2026-08-13-slice-3b-services-fresha.md` — Unit 9 changes
   this wire; the manifest must be updated with it.

## Set up an isolated worktree

```bash
git fetch origin
git worktree add .worktrees/3b-residuals -b fix/slice-3b-residuals origin/development
cd .worktrees/3b-residuals
composer install
cp ../../.env .env        # then see the DB note under "Gates"
```

Base off `origin/development`, **not** off local `development` — that branch has
unpushed docs commits and is diverged.

Sibling worktrees (`slice-3-services`, `slice-6-reviews`) belong to other
sessions. Never read or write them, and **never `git stash`** — the stash stack
is shared across every worktree on this machine.

## Explicitly OUT of scope

**The 59 legacy Fresha and 18 legacy owner rows in `site.services` stay.** They
are not a defect. `site.services`, `site.service_categories` and
`site.service_category_assignments` are dropped by **slice 7**, with its own
spec and migration ordering. Removing them here is doing slice 7's teardown
without slice 7's plan. Do not touch them, and do not "tidy" the legacy write
paths that still feed them.

---

## Unit 1 — `freshaSlug()` does not recognise the `book-now` URL form

`SourceProvisioner::freshaSlug()` (`app/Ingest/SourceProvisioner.php:480`)
matches only `fresha.com/[locale/]a/<slug>`. One live dev connection is a
different shape and therefore has **no ingest source at all** — 5 connections,
4 sources:

```
https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?share=true&pId=2835260
```

**Keep the anchor.** The existing comment states why, and it is a security
constraint, not style: *"an unanchored match would pull a slug out of a hostile
host's query string (§17)"*. Add a second **anchored** alternative for the
`book-now/<slug>/…` path; do not relax the host or locale groups, and do not
switch to an unanchored search.

**Investigate `pId` before you decide what to do with it.** `pId=2835260` looks
like it may be the professional/employee id — i.e. this URL form may carry the
selection that `selection_ref` otherwise gets from the stored payload. Find out.
If it is the employee id, say so in your report and **stop before wiring it into
`selectionRefFor()`** — that changes which menu a live connection publishes and
belongs in its own change with its own verification, not bundled into a slug fix.

**Blast radius, which is why slice 3b deferred this.** `identifierFor()` feeds
every seeded Fresha row. A slug extracted differently means a different
`ingest.sources.identifier`, and the identifier is what `sync()` compares to
decide a source is "a different remote thing" (it resets `next_attempt_at`).
Prove you have not changed the identifier for the four connections that already
resolve — pin all five URL shapes in a test, including the three existing
`/a/<slug>` ones and the locale-prefixed legacy form.

Deliverable: the fifth connection provisions a source; the other four keep
byte-identical identifiers.

## Unit 2 — `renumberLegacySortOrder()` exists twice

A near-twin lives in `UserServiceController` (private) and
`StaffServiceManagementController`
(`app/Http/Controllers/Api/Staff/UserSiteManagement/`). Slice 3b could not
extract it because no single task owned both files.

Extract it once, into whichever collaborator the two controllers already share.
**Do not create a new namespace for it** — the repo has no `Actions/`, and
business logic lives in `Services/`.

The constraint it encodes must survive the move, and it is easy to lose:
`services_user_sort_order_uq` is `UNIQUE (user_id, sort_order) WHERE deleted_at
IS NULL` — **global per user, not scoped by source** — so it never renumbers a
subset, it starts from every live row, and it uses a two-pass parking renumber
inside the lock. `ReorderService`'s dense `0..N-1` is wrong here and was
rejected deliberately; do not "simplify" onto it.

Mutation-check: break the shared helper once and confirm **both** controllers'
reorder tests go red. If only one does, the extraction is incomplete.

## Unit 3 — `ManualServiceItems::facets()` has a misleading parameter name

Its parameter is `$manualSourceId`, but slice 3b made `FreshaServiceItems` call
it with a **connection** source id. Rename to something source-kind-neutral and
update the docblock to say it takes any source id.

Pure rename. If you find yourself changing behaviour, stop — that is a different
unit.

## Unit 4 — `reposition()`'s omitted-id ordering is under-tested

`ServiceCollections::reposition()` treats a partial id list as authoritative for
the ids it names, appending owned-but-omitted collections afterwards **in their
existing relative order**. `tests/Feature/Content/ServiceCollectionsTest.php`
exercises that with a single omitted id, which cannot distinguish "preserved
relative order" from "appended in any order".

Add a case with **at least three** omitted collections in a known, non-alphabetical,
non-insertion order, and assert the exact resulting sequence. Mutation-check by
sorting the omitted set and confirming it goes red.

## Unit 5 — tenant-isolation tests bypass routing

`tests/Feature/Security/TenantIsolation/ServiceCategoriesIsolationTest.php` and
its `ServicesIsolationTest` sibling invoke controllers **directly**
(`app(SomeController::class)->show(...)`), so they certify the controller's
posture rather than the request path's. A route-level middleware regression
would not show up. The limitation is recorded in the file's own docblock.

Convert both to real HTTP calls through the routes. Preserve everything slice 3b
built into the category file and do not weaken it:

- the **effect** assertion (after B's destroy attempt, A's category still exists
  and still belongs to A)
- the **refusal reason** pin — it must refuse *because the row is not visible to
  B*, so a widened lookup with a bolted-on check still fails
- the **positive control on every case** — "B is refused" passes trivially on a
  controller that refuses everyone, so each case also asserts **A succeeds**

Mutation-check afterwards by stripping `ServiceCollections::baseQuery()`'s
`user_id` scoping and confirming every isolation case goes red. Note that
mutating `find()` alone is **not** sufficient — `index` reads through `list()`;
the shared seam is `baseQuery()`.

Related, and worth doing while you are here: the same direct-invocation pattern
exists in other `tests/Feature/Security/` files. **Report what you find; convert
only these two.** A broad sweep is its own task.

## Unit 6 — staff `reorder` runs two non-atomic lock scopes

`StaffServiceManagementController::reorder()` orders the content half (via
`ServiceCollections::reposition()`) and the legacy half (via `ReorderService`)
under **two independent lock scopes**. Worst observed case is a stale order
within one block; a collision is prevented by the global unique index, not by
the locking.

Unify them under one scope. Unifying needs `ReorderService`, which was outside
slice 3b's grant — it is inside yours.

Prove it with a concurrency test, not by inspection. The repo's convention is
`tests/Postgres/` for real-lock behaviour, and there is a standing rule that
matters here: **pin the refusal REASON, not just the outcome** — a lock test
that asserts only "no collision" survives deleting the lock, because the unique
index also prevents collisions.

## Unit 7 — `ProjectionWriter::bumpSite()` fires one lane, the spec claims three

`app/Ingest/Projection/ProjectionWriter.php:1661`:

```php
private function bumpSite(string $userId): void
{
    $siteId = DB::table('site.sites')->where('user_id', $userId)->value('id');
    if ($siteId !== null) {
        BuildState::bump((string) $siteId);
    }
}
```

The convergence spec's §4 says *"the connector run is itself a raw-write seam —
a scheduled run that changes a rendered menu must fire all three lanes"*.
It fires one.

**Decide which is wrong, and justify it with evidence — do not assume the spec.**
The case for one lane being correct: Fresha items never reach the public
profile payload (the services section reads `content.sources.kind = 'manual'`),
and the booking routes are authenticated and uncached, so there may be nothing
for a `sites.updated_at` touch or a Cloudflare purge to invalidate. The case for
three: other projectors share this method, and *they* may write things the
public payload does render.

**Enumerate every projector that reaches `bumpSite()` and what each one's output
feeds** before choosing. Then either implement the missing lanes with an exact
revision-delta test, or correct §4 and leave a comment at `bumpSite()` recording
why one lane is right. Either outcome is acceptable; an unexamined one is not.

## Unit 8 — nothing enforces cache invalidation on `content.collections` writes

There is no observer on `content.collections` and no CI check, so all three
invalidation lanes are a hand-maintained caller obligation on every write path.
Slice 3b verified all 21 write paths by hand — which is exactly the thing that
does not survive the next contributor.

Two candidate mechanisms; pick with reasoning:

- **An observer**, matching `SiteObserver`/`ServiceCategoryObserver`. Note the
  standing caveat: observers fire only on Eloquent writes. `ServiceCollections`
  writes through the query builder and `ProjectionWriter` through raw
  `DB::table()`, so an observer would cover **neither** without changing those
  write paths. Establish that before choosing it.
- **An architecture test**, matching the guards in `tests/Feature/Architecture/`
  — assert every method that writes `content.collections` or
  `content.collection_items` also reaches `ManualServiceWriter::invalidate()`.
  Static, covers raw writes, and fails the build on a new one.

Whichever you choose, mutation-check it: delete one existing `invalidate()` call
and confirm the new guard fails. If it does not, the guard does not guard.

## Unit 9 — multiple `category_ids` must 422, on both surfaces

**Owner decision, 2026-08-14.** Today a payload carrying two `category_ids`
stores **one**, silently, on both the owner and staff surfaces, because
`ServiceCollections::assign()` is single-collection per source. Slice 3b pinned
that behaviour in a test rather than changing it.

The decision: **reject it.** A write that carries more than one `category_id`
returns 422 rather than silently discarding data the caller sent.

Apply to both surfaces — the owner requests under
`app/Http/Requests/Api/User/Services/` and the staff pair under
`app/Http/Requests/Api/Staff/UserSite/Services/`. Slice 3b left those two
request families deliberately identical; keep them so, and keep the comment
that says so.

Two things to get right:

- The existing tests **pin the collapse** (`->toBe([$catA])`). They are now
  wrong. Replace them with the 422 expectation — this is a premise the decision
  retires, not an assertion being weakened.
- Update `docs/wire-changes/2026-08-13-slice-3b-services-fresha.md`. Its item 5
  documents the silent collapse as known behaviour with the 422 called an open
  product decision. That decision is now made; the manifest must say so, because
  **Partna-App and partna-monorepo are named consumers** and a client sending two
  ids starts getting a 422 where it previously got a 200.

---

## Gates — all must pass before merge

```bash
COMPOSER_PROCESS_TIMEOUT=0 composer test
php -d memory_limit=1G ./vendor/bin/phpstan analyse --no-progress --debug
./vendor/bin/pint --test
```

**The Postgres lane** — `phpunit.pg.xml` deliberately does not set the DB vars,
so a bare `composer test:pg` inherits `.env` (which points at an unrelated
project) and reports "9 failed / 195 skipped" — a *misdirected* lane that reads
as a broken one:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=postgres \
DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable \
PG_LANE_REQUIRED=1 PG_LANE_DISPOSABLE=1 ./vendor/bin/pest -c phpunit.pg.xml
```

`PG_LANE_REQUIRED=1` turns a silent skip into a failure. `PG_LANE_DISPOSABLE=1`
is required because the lane refuses to provision `core.*`/`site.*` on a database
named `postgres` — a guard that presents as an error. Container `partna-pgtest`
on **55432**.

**The authorization lane — run it. It broke `development` twice.**

```bash
export PATH="/opt/homebrew/opt/libpq/bin:$PATH"
export PGHOST=127.0.0.1 PGUSER=postgres PGPASSWORD=postgres PGSSLMODE=disable PGPORT=55432
dropdb --if-exists partna_test && createdb partna_test
PGDATABASE=partna_test scripts/db/apply-migrations.sh    # reads PG*, not DB_*
AUTHZ_LANE_REQUIRED=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 \
  DB_DATABASE=partna_test DB_USERNAME=postgres DB_PASSWORD=postgres \
  DB_SSLMODE=disable ./vendor/bin/pest -c phpunit.authz.xml
```

**It skips silently without Postgres**, which is why no amount of local
verification catches it otherwise. It broke `development` in slice 3a and again
in 3b, both times because a controller param stopped being model-bound and
reflection could no longer resolve it. **Units 5 and 9 touch controller and
request signatures — assume you are in its blast radius.** Never green it with
`fixture: { p: unknown }` or an exemption; both delete the cross-tenant probe for
that route.

`composer test:schema` needs a fully-migrated DB plus the `auth.users` shim and
will not run against the bare test container. CI runs it; confirm it there.

## Merge

1. `git fetch origin && git rebase origin/development` — expect one to three
   cycles; sibling sessions push between your fetch and your push.
2. **Re-run the full suite ON THE REBASED TREE.** A semantic conflict passes both
   branches individually and fails only their combination.
3. `git push origin fix/slice-3b-residuals:development`. **Never push to
   `production`.**
4. Watch CI to completion — all **nine** jobs. `gh run list --branch development
   --limit 1`. A green push is not a green CI.
5. Confirm the Laravel Cloud deploy succeeded and scan
   `cloud env:logs partna development --minutes 10` for `SQLSTATE`, `42702`,
   `42703`, `23505`.

## Standing rules

- **Never create Laravel migration files** — raw SQL in `supabase/migrations/`.
- **Never weaken or delete an assertion to make a test pass.** If a test cannot
  go green without changing what it asserts, that is a finding to report, not a
  chore. Retiring a premise a unit deliberately changed is different from
  removing an assertion that still constrains something — Unit 9 is the former.
- **Assert exact cache-revision deltas, never `> 0`.** Slice 3a shipped a
  three-lane cache test that stayed green with an entire lane deleted.
- **Tests run SQLite, production runs Postgres.** Verify constraint-bound writes
  against the DDL in `supabase/migrations/`, not a passing suite. Known
  divergences: booleans arrive as `"t"`/`"f"` on PDO_PGSQL and INTEGER `0` on
  SQLite (and `"f"` is truthy); integers arrive as numeric strings; a bare `null`
  in `SELECT DISTINCT` is type `unknown`; `DISTINCT` fights a new `ORDER BY`
  column.
- **Run directories, not just name filters.** `--filter=ServiceCategory` does not
  match `ServiceCategor**ies**IsolationTest`, and that blind spot hid a failing
  file for a whole wave.
- **Mutation-check anything load-bearing.** Break it, watch it go red, restore —
  and make sure the red is for the *right reason*. A mutation that crashes proves
  your test detects crashes.
