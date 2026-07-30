# Authorization invariant test harness

**Date:** 2026-07-30
**Status:** Tier 1 shipped 2026-07-30 — `tests/Authz/` gates the `schema-tests`
CI job with `AUTHZ_LANE_REQUIRED=1`; every param-bearing route is asserted or
exempted with a written reason, and the nine write routes that once answered an
inconclusive 422 now carry a minimal `body:` (`b86c16a2`). **Tier 2 not started**
— the claim race, the real `aal1` token and JWT tampering are unbuilt, so the
last success criterion below is unmet. Needs its own plan.
**Origin:** Pre-pilot security-tooling coverage review (SAST/DAST/IAST/API/pentest/infra)
**Effort:** ~1 day Tier 1, ~half day Tier 2, plus triage

## Problem

The active DAST lane's cross-identity IDOR pass is four hardcoded `GET`
requests (`scripts/dast/active/zap-active.sh:186-196`):

```
GET /api/customers/{CUST_B}
GET /api/sites/{SITE_B}
GET /api/media/{MEDIA_B}
GET /api/enquiries/{ENQUIRY_B}
```

`php artisan route:list --json` reports **520 registered `api/` routes**, of
which 188 carry a `{param}`, across **142 distinct URI patterns**. (Counting
`Route::` declarations in `routes/api/*.php` gives 404 — lower, because one
declaration can register several methods. The `route:list` figure is
authoritative and is what the harness enumerates.)

Two consequences:

1. **Coverage is a sample, not a sweep.** Four of 175 param-bearing routes are
   cross-tested, chosen by hand in July and never regenerated.
2. **Write methods are untested.** All four cases are `GET`. `DELETE
   /api/site/pages/{page}` and `PUT /api/site/sections/{section}/items/{item}`
   pose the same authorization question with a destructive failure mode, and
   nothing sends them cross-tenant.

Nothing else in the assurance suite closes this. PHPStan checks types;
Checkpoint and Vigil check known Laravel misconfigurations; the audit pipeline
produces prose. Authorization correctness is enforced by convention
(`authorizeForUser()` in Policies, `AccountCapabilities` gates) and guarded only
by whichever cases someone wrote a Pest test for.

Related open gap, deliberately out of scope here: there is no taint analysis
(Semgrep and Enlightn were both rejected on measured evidence 2026-07-30).

## Non-goals

- No new SAST tool. That question is settled; do not re-litigate.
- No IAST. Enterprise-priced, and the value overlaps what Tier 1 provides.
- No Caido/Burp dependency. A proxy is a fine exploratory tool but cannot be a
  gate; nothing in this design requires one.
- No second Postgres container, and no changes to the existing edge lane.
- `Origin`/`Referer` forgery (SEC-1) is **already covered** by 11 assertions in
  `tests/Feature/Security/TenantIsolation/PublicAnalyticsIdorTest.php`. Not
  re-implemented here.
- **The unclaimed capability sweep is withdrawn.** It was specified on a false
  premise, discovered during implementation on 2026-07-30 and recorded here so
  nobody re-proposes it: `LoadCurrentUser` resolves the caller via
  `getByAuthId($uid)`, i.e. by `core.users.auth_user_id`. Provisional users have
  `auth_user_id = null`, so **no JWT can ever resolve to an unclaimed user** —
  the middleware rejects before any controller runs. `actingAsUser()` injects
  the user object directly and bypasses that middleware, which is the only
  reason the sweep executed at all. Run against 384 authenticated routes it
  reported 129 "failures" including `GET /api/ping` and `GET /api/me`,
  describing a state production cannot reach. Exempting 129 routes to make it
  green would have been precisely the anti-pattern this design forbids.

  What would be worth testing instead — a *claimed* user whose account is
  suspended or disabled, or capability gating across account types — is a
  different premise needing its own design. It is not a variant of this one.

## Architecture

Two tiers with no overlap, each testing what the other structurally cannot.

```
TIER 1 — CI, every push                    TIER 2 — manual, pre-release
┌────────────────────────────────┐         ┌──────────────────────────────┐
│ tests/Authz/                   │         │ scripts/dast/active/         │
│ phpunit.authz.xml              │         │ (existing lane, extended)    │
│ extra step in schema-tests job │         │                              │
│                                │         │ real GoTrue tokens           │
│ real Postgres, real schema     │         │ real VerifySupabaseJwt       │
│ auth stubbed at middleware     │         │ real process concurrency     │
└────────────────────────────────┘         └──────────────────────────────┘
   Does the POLICY layer                      Does the AUTH layer
   enforce ownership?                         enforce the token?
```

### Why Tier 1 lives in the applied-schema lane

The default Pest suite cannot host it. `Tests\TestCase::setUp()` repoints the
`pgsql` connection at in-memory SQLite unconditionally, and tables exist only
where a `setupXTable()` helper hand-built them. Firing 175 routes there produces
"no such table" 500s, not 404s — a matrix that is green because it tested
nothing.

The `schema-tests` CI job already applies every migration from zero
(`scripts/db/apply-migrations.sh`) into a real `postgres:16` service container,
and currently spends that cost on one test file. Tier 1 runs as an additional
step in that job against the same migrated database.

`phpunit.authz.xml` is separate from `phpunit.schema.xml` because the two suites
ask different questions of the same database — the schema lane interrogates DDL
via `pg_constraint`, this lane exercises HTTP. Sharing a config would blur two
distinct base cases; sharing the job avoids paying for migrations twice.

### Tier 1 components

| Component | Purpose |
|---|---|
| `tests/Authz/AuthzTestCase.php` | Base case. pgsql-driver guard as in `SchemaTestCase`, plus HTTP kernel and fixture seeding. Extends the framework `TestCase` directly — inheriting `Tests\TestCase` would reintroduce the SQLite redirect. |
| `tests/Authz/RouteInventory.php` | Derives the route surface from `route:list --json` at runtime. Not checked in, so it cannot go stale. |
| `tests/Authz/expectations.yaml` | Route pattern → expected status, optional request body, mandatory `reason:` on every exemption. |
| `tests/Authz/CrossTenantTest.php` | `user.php` + `platforms.php`, all methods: identity A's token against identity B's UUID → 404. |
| `tests/Authz/StaffBoundaryTest.php` | `staff.php`: plain-user token rejected; `aal1` staff claims rejected. |
| `tests/Authz/PublicSurfaceTest.php` | `publicSite.php` + `api.php`: unknown ID → 404, never 403. |
| `tests/Authz/UnclaimedSweepTest.php` | Provisional user (`status='unclaimed'`, null email) against the authenticated surface; every capability-gated route rejects. |
| `tests/Authz/CoverageGuardTest.php` | Every route is covered by a rule or exempted with a non-empty reason. |
| `tests/Authz/CollectionLeakageTest.php` | Added 2026-07-30, not in the original design. The 132 param-free `GET` routes carry no id to substitute, so `CrossTenantTest` cannot reach them; this sweeps them for identity B's ids. Weak by construction (128 of 132 bodies contain no ids — identity A owns only a bare site) and paired with a non-vacuity guard so it cannot pass by every endpoint returning nothing. A tripwire, not proof of scoping. |
| `tests/Authz/README.md` | How to run the lane locally (it needs a provisioned Postgres and is **not** part of `composer test`), and what to do when it fails — the new-route table below, restated where a developer will look. |

### Tier 2 additions

Added to `scripts/dast/active/`, reusing `bring-up.sh`, `seed-identities.php`,
and `mint-jwt.php`:

- **Claim race** — N parallel `POST /api/claim` against one build; assert exactly
  one `2xx`. Requires a real email-OTP JWT (only GoTrue mints one) and genuine
  process concurrency for `FOR UPDATE SKIP LOCKED` to be meaningful. PHPUnit
  provides neither.
- **Real `aal1` staff token** against `require.aal2` middleware — the Tier 1
  version proves the policy checks AAL; this proves the middleware does.
- **JWT tampering** — flipped signature, altered `alg`, expired `exp`.

## Route classification

**Every route carrying a `{param}` is in the matrix by default.** Reflection
does not decide inclusion; it only resolves *which* of identity B's rows to
substitute, where it can. Exclusion always requires a human writing a reason.

1. **Enumerate.** `php artisan route:list --json` yields URI, methods, and
   controller action for every route.
2. **Resolve the fixture, where possible.** For each `{param}`, reflect on the
   controller method signature. A parameter type-hinted as an Eloquent model
   names the fixture directly.
3. **Otherwise require a mapping.** Where reflection cannot resolve the param,
   `expectations.yaml` must supply either a `fixture:` mapping or an `exempt`
   with a written reason. `CoverageGuardTest` fails until one exists.
4. **Substitute and fire.** The generator substitutes B's UUID, sends the
   request with A's token, and evaluates the status.

### Why the default is inclusion

The inverse rule — "scalar param means not tenant-scoped, auto-exempt" — was
measured against the real route table on 2026-07-30 and rejected. Of 142
distinct param-bearing URI patterns, reflection resolves only 64. The
remaining 78 fetch their row by hand rather than via route-model binding:

```
api/enquiries/{id}                      typed string — tenant-owned
api/site/sections/{section}             not in the method signature at all
api/platforms/custom/links/{id}
api/platforms/eventbrite/accounts/{id}
```

These are disproportionately the routes worth testing. A route using implicit
model binding inherits Laravel's scoping and the project's `authorizeForUser()`
pattern almost by default; a route doing a manual lookup is where an ownership
clause gets forgotten. An auto-exempt rule would have excluded exactly those 78
patterns and reported green.

Measured distribution of the 78 requiring a mapping:

| Prefix | Patterns |
|---|---|
| `api/platforms` | 35 |
| `api/staff` | 12 |
| `api/site` | 8 |
| `api/public` | 7 |
| `api/enquiries` | 6 |
| `api/content` | 4 |
| `api/routing` | 3 |
| `api/account`, `api/sections`, `api/sessions` | 1 each |

Twelve distinct models are reachable via reflection-resolved bindings, and they
bound the fixture registry: `User` (56 bindings), `Service` (11), `Customer` (9),
`ServiceCategory` (9), `UserSegment` (6), `SiteMedia` (6), `Block` (4),
`Notification` (4), `EarlyAccessSignup` (3), `Feedback` (3), `PreAccountBuild`
(2), `FeatureAvailabilityRule` (1).

Mappings are written as literal route patterns, not globs. A glob covering
`api/platforms/*/accounts/{id}` would be fewer lines but could silently capture
a route it should not — the precise failure mode this harness exists to prevent.

## New route lifecycle

**Nothing is registered by hand.** `RouteInventory` re-derives the surface from
`route:list --json` on every run, and classification is read from the controller
signature by reflection. A route added to any `routes/api/*.php` is therefore in
the matrix on the next CI run whether or not anyone remembered it. Omission is
not a failure mode this harness has.

What a developer adding a route must actually do:

| Route added | What happens automatically | Action required |
|---|---|---|
| `GET`/`DELETE` with a model-bound param | Enters `CrossTenantTest` | **None.** Passes if the Policy is correct; fails if not. |
| `PATCH`/`PUT`/`POST` with a model-bound param | Enters `CrossTenantTest`; returns `422` if the endpoint requires a body | Add a `body:` fixture to `expectations.yaml` so the request reaches the authorization check |
| Param reflection cannot resolve (typed `string`/`int`, or absent from the method signature) | `CoverageGuardTest` fails — the route is **not** auto-exempted | Add a `fixture:` mapping naming which model to substitute, or `expect: exempt` with a reason |
| Authenticated route with no param | Enters `StaffBoundaryTest` or `UnclaimedSweepTest` by route file | None |
| Model-bound param whose model has no seeded fixture | `CoverageGuardTest` fails | Add a fixture for that model, or exempt it with a written reason |
| Route deliberately shared / not tenant-scoped | Fails as a `2xx` | Add an `expect: exempt` entry with a `reason:` |

So the only routine manual step is supplying a request body, and only for write
routes whose validation would otherwise block the path before authorization runs.

**Failure messages are part of the design, not polish.** A developer meets this
harness for the first time as a red CI job on an unrelated PR. Every failure
must name the route, the observed status, the expected status, the file to edit
(`tests/Authz/expectations.yaml`), and which of the rows above applies. A
coverage failure that says only "route not covered" will be resolved by
guessing, and the guess will be a blanket exemption.

## When this runs

| Lane | Trigger | Command |
|---|---|---|
| **Tier 1** | Every `pull_request` targeting `main` / `development-v2` / `development` / `production`, and every `push` to `main` / `development` / `production`. Excluded from the weekly cron, matching the enclosing job's `if: github.event_name != 'schedule'`. | `composer test:authz`, as a step in the existing `schema-tests` job |
| **Tier 2** | Manual only. Never CI, never cron — same policy as the existing active DAST lane, for the same reasons (Docker, local Supabase stack, runtime). | `scripts/dast/run.sh --only active` |

**`composer test` does not run Tier 1.** The default suite is the SQLite lane;
this harness needs a real Postgres with `supabase/migrations/` applied. Running
it locally means provisioning that database first — `scripts/db/fresh-reset.sh`,
then `composer test:authz`. This is the same arrangement `composer test:pg` and
`composer test:schema` already have, but it must be stated in
`tests/Authz/README.md`: a developer who assumes a green `composer test` covers
authorization will be wrong, and will find out in CI.

## Verdicts

| Observed | Verdict | Reasoning |
|---|---|---|
| `404` | pass | Correct — the resource does not exist *for this user* |
| `2xx` | fail | Cross-tenant access |
| `403` | fail | Leaks existence; the project rule is 404 for non-owned resources, 403 only for role/type restrictions |
| `422` | **inconclusive → fail** | Validation rejected the body before authorization ran; the question was never reached |
| `5xx` | fail | Application or harness defect |

The `422` row is load-bearing. Every `PATCH`/`PUT`/`POST` route needs a body
that clears validation, or the request dies before the policy is consulted.
Scored as a pass, it produces a suite that is green because it tested nothing —
the false-PASS class already observed seven times in the launch-check suite.
Scored as a failure, it forces either a body fixture or an explicit exemption.

## Expectations file

```yaml
# tests/Authz/expectations.yaml
- route: "PATCH api/routing/connections/{connection}/primary"
  expect: 404
  body: { primary: true }          # minimal body to clear validation

- route: "GET api/catalog/surfaces"
  expect: exempt
  reason: "Global catalog, not tenant-scoped — no model binding"
```

`body:` is supplied only where validation blocks the path. `reason:` is
mandatory on every `exempt` and the coverage guard rejects an empty one.

An explicit expectations file is used rather than a recorded baseline
(`lib/diff-baseline.sh`) because a baseline entry carries no reason: a later
reader cannot distinguish "shared by design" from "bug we accepted". The DAST
README's own rule — *baselines: triage, don't pre-seed* — applies with more
force here, where the first run is expected to be loud.

## Isolation

`DELETE` cases genuinely destroy identity B's rows when authorization is broken.
Each case runs inside its own transaction and rolls back, so case ordering is
irrelevant and one real failure cannot cascade into false ones.

## Discovery loop

```
scripts/audit/lenses/authz-invariants.md
        │   "what does this handler assume a hostile client won't send?"
        ▼
   CONSOLIDATED.md finding
        │   promoted by hand — a finding is a hypothesis, not a test
        ▼
   assertion in tests/Authz/  ──────►  guarded on every push, forever
        │
        ▼
   finding ticked (resolved as an open question)
```

Two mechanisms, two jobs. The **lens** supplies novelty: it reads code and finds
invariant classes nobody enumerated. The **coverage guard** supplies
completeness: it cannot invent a class, but it makes a new route unable to land
uncovered.

`AuditPipelineIntegrityTest` gates scope groups, bundle reachability, and
applicable-lens signals. A new lens file that is not wired into a bundle and a
scope group fails CI — this is an implementation step, not a footnote.

## Rollout

**Two phases, two branches.** Phase 1 is Tier 1 alone — it is the gating work
and stands on its own. Phase 2 adds Tier 2 (claim race, real `aal1` token, JWT
tampering), which is manual-lane work that gates nothing and should not hold up
Phase 1's merge. Each phase gets its own implementation plan.

Tier 1 gates from day one. All triage happens on the feature branch: run it,
classify every non-404 into the expectations file with a written reason, fix or
file anything that is a real defect, merge green. `development` never sees a red
gate.

"Land it advisory and flip it later" is rejected explicitly: it is how five
standing red gates accumulated on `development` since `c3bca835`, and the flip
does not happen.

## Risks

1. **`actingAsUser()` reachability.** The helper lives in `tests/Pest.php` and
   binds middleware stubs into the container. Whether it is reachable from a
   suite running under a different phpunit config must be spiked before anything
   else is built. If it is not, Tier 1 needs its own thin stub — a small file,
   not a redesign. **Spike this first.**
2. **Fixture breadth.** Identity B must own one instance of every model the
   matrix substitutes. Twelve are reachable by reflection; the 78 hand-mapped
   patterns may name others. Some have deep prerequisites — an `Enquiry` needs a
   `Site` and a `Customer`. Only `User`, `Site`, `PartnaStaff` and
   `PreAccountBuild` have existing factories under `database/factories/`; the
   rest need seeding written. Models that cannot be seeded are exempted with a
   written reason, never skipped silently.

5. **The 78 mappings are the branch's main cost**, and they are concentrated in
   `api/platforms` (35). Writing them is mechanical but requires reading each
   controller to learn which model the manual lookup fetches.
3. **First-run volume.** Assertion count is roughly:

   Measured route counts from `route:list` (520 `api/` routes total):

   | Group | With `{param}` | Without | Total |
   |---|---|---|---|
   | `api/staff` | 81 | 23 | 104 |
   | `api/platforms` | 40 | 186 | 226 |
   | user (everything else authenticated) | 58 | 100 | 158 |
   | `api/public` | 9 | 23 | 32 |

   | Suite | Approx. assertions |
   |---|---|
   | `CrossTenantTest` | 98 (param routes in the user + platforms groups) |
   | `StaffBoundaryTest` | 208 (104 staff routes × plain-user and `aal1` tokens) |
   | `UnclaimedSweepTest` | ~384 (the whole authenticated surface) |
   | `PublicSurfaceTest` | ~32 |

   Order 700 HTTP requests against real Postgres, each in its own transaction.
   Expected CI runtime is single-digit minutes; if it exceeds that, the staff
   and unclaimed sweeps are the candidates for narrowing, not the cross-tenant
   matrix.

   Triage cost scales with *failures*, not assertions — most will pass on first
   run. The unknown is what fraction do not, and that is the branch's main cost.
4. **Tier 1 fidelity ceiling.** With `VerifySupabaseJwt` stubbed, Tier 1 proves
   "the Policy said no", never "the token was rejected". It also does not
   reproduce prod's `app_backend` restricted role or RLS via Supavisor — the
   same limitation the active lane's README already records.

## Success criteria

- Every param-bearing route is covered by an assertion or an exemption carrying
  a written reason.
- A new route added to any `routes/api/*.php` is exercised on the next CI run
  with no registration step, and cannot be silently omitted — it either passes,
  fails with an actionable message, or is exempted with a written reason.
- Every failure message names the route, observed status, expected status, and
  the file to edit.
- `composer test:authz` gates the `schema-tests` job and is green on merge.
- The claim race asserts exactly one winner under N concurrent claimants.
