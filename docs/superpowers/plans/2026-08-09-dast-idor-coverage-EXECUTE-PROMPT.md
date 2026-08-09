# EXECUTE PROMPT — DAST: widen IDOR coverage, guard the self-destruction class, settle the cron floor

**Why now:** `71f027b11` (2026-08-07) repaired five stacked bugs that had left the DAST active lane
**completely unauthenticated since it shipped** — every ZAP request went out with no `Authorization`
header while the lane reported PASS. The machinery is now trustworthy and verified (self-test 7/7,
active + edge both exit 0). What it is *not* is broad: cross-tenant isolation is proven on **2 of
18** ownership groups. This is the follow-up work that converts a correct harness into a
useful one.

Paste everything below the line into a fresh session. It is self-contained.

---

Read `CLAUDE.md`, `scripts/dast/README.md` (especially **"ZAP auth injection — the mechanics that
make it real"** and the **Five things don't auto-update** list), and `git show 71f027b11` before
touching anything. The commit message is the primary record of what was broken and why.

Three units, in this order. **Unit 1 is auth-adjacent authorization test infrastructure, so it hits
`scripts/audit/fix-flow.md`'s blocker gate: write the plan and get Josh's sign-off before
implementing.** Units 2 and 3 do not need a gate.

| Unit | What | Size |
|---|---|---|
| **1 — IDOR coverage** | 2 of 18 ownership groups → all 18 (16 additions) | L. 16 additions: 4 need no fixture, 8 are one insert each, 2 nested, 2 need care. |
| **2 — Self-destruction guard test** | Make bug 5's class impossible to reintroduce silently | M. One test in `tests/Feature/Architecture/`. |
| **3 — Cron floor** | Decide + document; no code | S. Docs only. |

## Ground rules that apply to all three

- **The active lane is manual-only and takes ~12 min per run.** It needs Docker. It provisions its
  own throwaway Supabase stack on a port offset (54421+) and tears it down via `trap`.
- **`composer test` does not cover `scripts/dast/` at all** — nothing under `tests/` references it.
  The tool's acceptance gate is `scripts/dast/tests/dast-selftest.sh`. **Run it after any change
  under `scripts/dast/`**; it plants known-vulnerable canaries and asserts they are both *flagged*
  and *fail the build*. Unit 2 adds the first real `composer test` coverage of this tool.
- **Never weaken an assertion to go green.** If a probe fails, that is the finding. A real
  authorization failure goes through the blocker gate, it does not get its assertion relaxed.
- **This is a shared checkout.** Another session has been working in it (`config/horizon.php`,
  queue-priority spec). `git status` before you commit and stage only your own paths. Do not
  `git stash`.
- **Do not run `artisan pint`** on `scripts/dast/active/seed-identities.php`. Pint broke that file
  once already (`6461cff5`) by moving a `use` import below its call site, which compiled
  `Kernel::class` to the literal string `'Kernel'` and killed the whole lane. The bootstrap sits
  below the `use` block deliberately. Also note `artisan pint` is broken repo-wide anyway.

## Unit 1 — widen IDOR coverage from 2 ownership groups to all 18

### What exists now

`zap-active.sh`'s `requestor` job runs four requests — two probes and two controls:

| Request | Expect | Why |
|---|---|---|
| `GET /api/customers/{A.customer_id}` | 200 | control |
| `GET /api/customers/{B.customer_id}` | 404 | probe |
| `POST /api/enquiries/{A.enquiry_id}/read` | 200 | control |
| `POST /api/enquiries/{B.enquiry_id}/read` | 404 | probe |

`seed-identities.php` seeds per identity: `User` → `Site` → `SiteMedia` → `Customer` → `Enquiry`,
and writes `{id, auth_user_id, email, password, handle, site_id, media_id, customer_id, enquiry_id}`
to `identities.json`. Anything you probe needs a fixture and a new key there.

### The full universe — 18 ownership groups, 2 covered, 16 to add. Target ALL of them.

Derived mechanically 2026-08-09: **36 in-scope routes carry a tenant-owned id**, collapsing to **18
distinct ownership-check groups**. Reproduce the derivation with:

```bash
jq -r '.[] | select(.uri|test("^(api/(staff|public|internal)/|horizon|storage|p/|api/platforms/|api/sessions/|api/account/mfa/|api/me/deletion/)")|not)
  | select(.uri|test("\\{(section|item|id|service|notification|intent|image|customer|category|upload|restyle|page|feedback|document|connection)\\}")) | .uri' \
  <(php artisan route:list --json) | sort -u
```

**The unit is the controller method, not the resource.** `site/sections/*` is served by
`SectionController`, `SectionGroupController` AND `SectionItemController`, each hand-rolling its own
ownership scoping — proving one proves nothing about the others. Likewise `Content\Item` is reachable
through both `ContentController` and `PoolController`. Authorization is per-code-path, which is
exactly why `UserEnquiryController::transition` hand-rolls `->where('user_id', ...)->find()` while
`CustomerPolicy::view` uses `denyAsNotFound()`. Group by the code that decides, not by the table.

**NOT surfaces — do not "add" these three.** They are **enum/string-keyed**, so there is no other
tenant's value to substitute:

- `api/design-media/{purpose}`
- `api/sections/{blockType}`
- `api/content/pools/{pool}` — `PoolController::show(Request, string $pool)` calls
  `assertPool($pool)`. Looks like an id in `route:list`; is not one.

Ordering below is by **fixture cost ascending**, deliberately — NOT by risk. Note that risk runs the
other way: group 1 and 2 include the hand-rolled authorization paths, which are likelier to be wrong
than a plain `denyAsNotFound()` policy. Do not stop after group 1 because it "felt like enough".

**Group 1 — four surfaces, ZERO new fixtures.** All four bind `SiteMedia`, which
`seed-identities.php` already seeds as `media_id`:

| Route | Verb | Binds |
|---|---|---|
| `api/gallery/{image}` | `DELETE` | `SiteMedia $image` |
| `api/images/{image}` | `DELETE` | `SiteMedia $image` |
| `api/documents/{document}` | `DELETE` | `SiteMedia $document` |
| `api/content/uploads/{upload}` | `DELETE` | `SiteMedia $upload` |

⚠️ **Pool-mismatch trap.** The seeded fixture is `pool='gallery'`. If `UserDocumentController` (or
the uploads path) also filters on its own pool, then probing it with a *gallery* media id 404s for
the **wrong reason** — wrong pool, not wrong owner — which is a vacuous pass of exactly the kind this
whole exercise exists to eliminate. **The control detects this**: if A's own gallery media returns
200 on `/api/documents/{id}`, pool is not filtered and one fixture serves all four. If it 404s, that
surface needs its own pool-specific fixture. Read the controller, then let the control confirm it.

**Group 2 — eight surfaces, one model-backed fixture each** (all models exist):

| Route | Cheapest bodyless verb | Model |
|---|---|---|
| `api/services/{service}` | `GET` | `Core\User\Service` |
| `api/service-categories/{category}` | `GET` | `Core\User\ServiceCategory` |
| `api/site/sections/{section}` | `GET` | `Core\Site\Section` |
| `api/me/feedback/{feedback}` | `GET` | `Core\Feedback` |
| `api/site/pages/{page}` | `DELETE` | `Core\Site\Page` |
| `api/site/restyle/{restyle}` | `POST .../undo` | `Core\Site\DesignKitRestyle` |
| `api/content/items/{item}` | `DELETE` | `Content\Item` |
| `api/me/notifications/{notification}` | `POST .../read` | `Core\Notifications\Notification` |

**Group 2b — two nested surfaces with their OWN id and their OWN controller.** These are the ones a
resource-level count misses; both need a fixture and both hand-roll their scoping:

| Route | Verb | Why separate |
|---|---|---|
| `api/site/sections/{section}/items/{item}` | `DELETE` | `SectionItemController`, distinct from `SectionController`. Needs a `Core\Site\SectionItem` under B's section. |
| `api/content/pools/{pool}/selection/{item}` | `DELETE` | `PoolController::deselect(Request, string $pool, string $itemId)` — `{pool}` is an enum but `{item}` is a real `Content\Item` id, reached through a *different* controller than `api/content/items/{item}`. |

Also worth a probe if cheap once `{section}` is seeded: `PUT/DELETE
/api/site/sections/{section}/groups/{groupKey}` (`SectionGroupController`). `{groupKey}` is a
section-scoped string key (`->where('group_key', $groupKey)`), **not** an id — so the IDOR target is
the parent `{section}`, reached via a third controller.

**Group 3 — two that need care:**

- `api/routing/connections/{connection}` — `POST .../primary`, hand-rolls
  `IntegrationConnection::query()->find($id)`. The model exists, but **`IntegrationConnectionObserver::saved`
  folds through `IdentitySync`**, so naively seeding one can fire real side effects — which is the
  same reason `api/platforms/*` is excluded from the scan entirely. Seed it in a way that does not
  trigger an outbound fetch, and verify nothing external was called, or skip it and say so.
- `api/routing/suggestions/{intent}` — `POST .../accept`. **No Eloquent model**; needs a raw insert
  into `routing.source_intents`. Check the table's CHECK/NOT NULL constraints against
  `supabase/migrations/` DDL, not against a passing SQLite suite — the lane runs real Postgres.

### Why all 18, not a subset

Because a subset gets read as total. "IDOR assertions passed" in a green log is the only artefact
anyone sees six months from now, and the entire reason this unit exists is that the harness spent
months reporting PASS for something it was not testing. Partial coverage recreates that in a
narrower form.

The costs are also lower than they look. Of the 16 additions: 4 need no fixture at all (group 1), 8
are a single model insert apiece (group 2), 2 are nested fixtures (group 2b), and only 2 need real
care (group 3). You do **not** need a 12-minute lane run per surface — add a group, run once, and
bisect only if something fails.

**Escape hatch, so this cannot become open-ended:** if a fixture would require reproducing a
*pipeline* rather than performing an insert (group 3 is where this is plausible), stop on that one
surface, write down exactly what blocked it, and move on. Do not half-add a surface — a probe
without its control is worse than no probe. **Report which of the 18 are covered and which are not.**

### Five traps, every one of which has already produced a false result here

1. **Verify the route exists before asserting 404 on it.** The original plan probed
   `/api/sites/{id}`, `/api/media/{id}` and `/api/enquiries/{id}` — **none of which exist**. All
   three returned 404 for "no such route" and would have satisfied a 404 assertion while testing
   nothing. Check `php artisan route:list --json` per surface. Note that `sites` and `site-media`
   have **no** by-id user route, so they carry no IDOR surface at all; their absence is correct.
2. **Read the policy; do not assume 404.** The 404 convention comes from
   `BasePolicy::denyAsNotFound()` (e.g. `CustomerPolicy::view`) and from handlers that scope the
   query themselves (`UserEnquiryController::transition` does
   `->where('user_id', $user->id)->find($id)` then returns 404). A policy that plain-denies yields
   **403**. Establish the expected code from source per surface, and assert the real one.
3. **Every probe needs a paired control on identity A's own resource expecting 200.** This is not
   redundancy. It is the only thing that distinguishes "authorization works" from "the app is dead",
   "the token is stale", or "the route does not exist" — all of which 404 or 401 and would pass a
   naive not-200 check. Bug 3 in `71f027b11` was found *only* because a control demanded a positive
   result. **Never drop a control to quieten a failure.**
4. **`PATCH`/`PUT`/`POST`-with-body routes can 422 before authorization runs.** Laravel validates a
   `FormRequest` before the controller method, so a bodyless `PATCH /api/gallery/{image}` returns
   422 and tells you nothing about authorization. If you probe a write route, send a valid minimal
   body — or pick a `GET`. (`GET /api/customers/{customer}` and the enquiry `POST` both take plain
   `Request`, which is why they were safe.)
5. **A late-plan 401 cascade has two different causes.** Decode the JWT (`exp - iat`) first. If the
   lifetime is 3600 it is **not** expiry — it is the scanner having logged itself out by hitting a
   credential-revoking route, i.e. you added a route to scope that belongs in the
   self-destruction exclusions. Both causes look identical in the log.

### Done when

- Each new probe has a control, and both assert a status established from source.
- A full `scripts/dast/run.sh --only active --fail-on high` exits 0 with
  `IDOR assertions passed` in the log — and you have confirmed in `zap/zap-run.log` that each new
  control genuinely returned its expected 2xx rather than being skipped.
- `scripts/dast/tests/dast-selftest.sh` is ALL PASS.
- `scripts/dast/README.md`'s maintenance item 3 lists the covered surfaces **and any of the 18 left
  uncovered, with the reason**, so a reader can never mistake "IDOR assertions passed" for "the app
  is proven". If all 18 land, say so explicitly — that is a meaningfully different claim and worth
  stating. Coverage that is not written down gets read as total.
- The three enum-keyed non-surfaces are recorded as deliberately-not-surfaces, so nobody "fixes" the
  gap by adding a probe that cannot fail.

## Unit 2 — a guard test for the self-destruction class

### The problem

Bug 5 was: once the scan became genuinely authenticated, ZAP hit `POST /api/sessions/logout`,
`POST /api/sessions/logout-others` and `DELETE /api/sessions/{sessionId}` and **revoked its own
session** mid-pass, so every later request 401'd. The fix added them to `excludePaths`. But
`zap-context.yaml` currently admits, in a comment, that *"nothing detects that class
automatically"* — which is a comment where a test belongs.

That class only became reachable when auth started working, so it will keep growing as the app does,
and the symptom (a late-plan 401 cascade) reads like a broken token rather than self-inflicted
logout.

### What to build

A guard test asserting that every route matching credential-revoking / account-deleting /
auth-state-mutating shapes is covered by `zap-context.yaml`'s `excludePaths`.

- **Home:** `tests/Feature/Architecture/` — 21 guard tests already live there.
  `AuditPipelineIntegrityTest.php` is the closest precedent (it guards tooling, not app code).
- **Also assert the two lists agree.** `zap-context.yaml`'s `excludePaths` and the alternation in
  `zap-active.sh`'s exclusion-verification `grep` are the same contract stated twice, deliberately
  (the YAML needs per-context regexes, the grep needs one pattern). A drift between them is a silent
  hole — pin it.
- **Currently excluded self-destruction set:** `.*api/sessions/.*`, `.*api/me/deletion/.*`,
  `.*api/account/mfa/.*`. Note `api/sessions/` carries a trailing slash on purpose so the harmless
  `GET /api/sessions` collection read stays in scope.
- **Deliberately NOT excluded:** `PATCH /api/me` (its `UpdateUserRequest` keeps `handle` out, so no
  Cloudflare KV write, and it is a high-value fuzz target) and `POST /api/me/data-export` (runs
  inline under `QUEUE_CONNECTION=sync` but `MAIL_MAILER=log` contains it). Your patterns must not
  flag these, or the guard gets suppressed.

### Traps

- **`CLAUDE.md`: "Add signals high-confidence only; noisy guards get suppressed."** A guard that
  flags `PATCH /api/me` is worse than no guard. Prefer a narrow, explicit shape list over a broad
  `delete|revoke` regex.
- **Pest `toContain` is variadic — the second argument is another NEEDLE, not a failure message.**
  Passing a message silently adds a second thing to search for.
- **A negated `toContain` on a compound condition is vacuous.** See
  `reference_negated_tocontain_is_vacuous` in the audit notes: `not BOTH` always passes. Assert the
  positive.
- **Prove the test can fail.** Temporarily remove `.*api/sessions/.*` from `excludePaths`, confirm
  the test goes red, then restore. A guard you have not seen fail is not a guard — that is the whole
  lesson of `71f027b11`.
- Consider whether it needs **its own CI job**. `outbound-http-guard` has one precisely because the
  Feature suite can abort before reaching it (`ci.yml:553`). Decide and say why.

### Done when

- The test passes, and you have **demonstrated it failing** against a deliberately removed
  exclusion.
- `composer test` is green (mind `COMPOSER_PROCESS_TIMEOUT=0`).
- `zap-context.yaml`'s "nothing detects that class automatically" comment is replaced with a pointer
  to the test.

## Unit 3 — settle the edge-lane cron floor

### The facts

- `.github/workflows/dast-edge.yml` on **development** sets `DAST_FAIL_ON: medium`; the copy on
  **production** still sets `high`.
- GitHub registers a `schedule:` trigger from — and **executes the workflow file of** — the default
  branch, which here is `production`. The scheduled job *does* `checkout` with
  `ref: development`, so it runs development's scripts. So: **development's code, production's
  `env:` block.** That asymmetry is the whole bug.
- Therefore the Sunday cron runs at `high`, not the `medium` that was set on development 2026-08-03.
- **There is no cheap fix.** `development` is **557 commits and 77 migrations** ahead of
  `production`, and prod has `usesPushToDeploy: true` — the push *is* the deploy, with no CI gate.
  Pushing production to correct one `env:` line would deploy everything.
- Scheduled runs had never succeeded (07-26 and 08-02 both failed, pre-secrets). Secrets
  `DAST_EDGE_TARGET` / `DAST_EDGE_SITEPAGE_TARGET` landed 2026-08-03, so the 2026-08-09 16:00 UTC
  run was the first that could pass. **Check whether it did** (`gh run list --workflow
  dast-edge.yml`) and record the outcome.

### What to do — documentation only, no code

1. Record in `scripts/dast/README.md` that **scheduled edge runs gate at `high`**, that the
   authoritative `medium`-floor run is a manual `workflow_dispatch`, and that a green Sunday
   therefore does **not** mean "clean at medium". Someone will otherwise read the cron as the
   stricter gate it was intended to be.
2. Add the one-line `DAST_FAIL_ON: high` → `medium` edit to the prod-deploy checklist
   (`docs/deploy/routine-deploy.md`) so it rides along whenever production next ships, rather than
   justifying a deploy of its own.
3. Note the general trap alongside it: **a workflow's `schedule:` trigger and `env:` come from the
   default branch even when it checks out another ref.** This will bite any future scheduled
   workflow in this repo the same way.

**Do not push `production` to fix this.** If you think it is worth a deploy, say so and stop —
that is Josh's call, and CLAUDE.md requires confirming before a prod push.

### Done when

The README says what a green cron does and does not prove, the deploy checklist carries the edit,
and the 2026-08-09 scheduled-run outcome is recorded.

## Reporting

Per unit: **plan → implement → independent review**, per `scripts/audit/fix-flow.md`. Report what
you ran, not what you expect to be true — and if a unit turns out blocked, finish the other two in
full and say plainly what you left and why. Do not commit or push unless Josh asks.
