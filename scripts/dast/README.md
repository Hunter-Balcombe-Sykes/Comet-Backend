# scripts/dast/ — DAST security scanning

Sends hostile HTTP requests at a running target and inspects the responses —
the *dynamic × code* cell of the assurance suite (sibling to
`scripts/audit/` static review and `scripts/launch-check/` runtime checks).

Full design: `docs/superpowers/plans/2026-07-17-dast-security-implementation.md`
(source of truth) and `docs/superpowers/specs/2026-07-17-dast-security-design.md`
(background/rationale).

## Two lanes

- **active** — ZAP fuzzing an isolated, runner-owned local Supabase stack
  (port-offset, torn down via `trap` on every exit). Two seeded identities +
  an unauth pass drive authenticated scans and a cross-identity IDOR check.
  Manual only — never in cron or CI.
- **edge** — Nuclei (curated tags + custom assert templates) + wcvs
  (cache-deception) + a weekly OWASP ZAP *passive* baseline scan, all
  non-destructive, against `EDGE_TARGET`. Weekly cron; not in CI.

Neither lane runs in `ci.yml` — DAST is slow and needs a running
target/stack. This is a self-contained shell tool: zero `config/partna.php`,
app-code, or Supabase migration changes.

## One-time setup

```bash
cp scripts/dast/.env.example scripts/dast/.env   # fill in secrets, never commit
```

## Running each lane

**Edge lane** — non-destructive, safe to run against dev or prod anytime. Also runs automatically every Sunday via `.github/workflows/dast-edge.yml` once `DAST_EDGE_TARGET`/`DAST_EDGE_SITEPAGE_TARGET`/`DAST_EDGE_RATE_LIMIT` are set as GitHub repo secrets.

```bash
scripts/dast/run.sh --only edge
```

> **A green Sunday cron does NOT mean "clean at medium".** The scheduled run gates at
> **`high`**, not the `medium` floor set on `development` 2026-08-03. The authoritative
> medium-floor run is a manual `workflow_dispatch`.
>
> Why: GitHub registers a `schedule:` trigger from — and **executes the workflow file of** — the
> **default branch**, which here is `production`. The scheduled job does `checkout` with
> `ref: development`, so it runs development's *scripts* under production's *`env:` block*.
> Development's `dast-edge.yml` sets `DAST_FAIL_ON: medium`; production's still says `high`.
> Development's code, production's environment.
>
> **This is the general trap, not a quirk of this workflow:** a workflow's `schedule:` trigger and
> its `env:` come from the default branch even when it checks out another ref. Any future scheduled
> workflow in this repo will behave the same way.
>
> There is no cheap fix. `development` is several hundred commits and 77 migrations ahead of
> `production` (560 as of 2026-08-09 — check with
> `git rev-list --count origin/production..origin/development` rather than trusting this number), and
> prod has `usesPushToDeploy: true` — the push *is* the deploy, with no CI gate — so correcting one
> `env:` line would deploy everything. The edit rides along on the next prod ship instead:
> `docs/deploy/routine-deploy.md` carries it as a checklist item.
>
> **Scheduled-run history (as of 2026-08-09):** no scheduled run has ever succeeded. 2026-07-26 and
> 2026-08-02 both failed pre-secrets; the secrets landed 2026-08-03, and no cron has fired since.
> The 2026-08-09 16:00 UTC run is the first that could pass. Check with
> `gh run list --workflow dast-edge.yml`.

**Active lane** — manual only. Needs Docker running, mutates the runner's own throwaway local Supabase stack, takes several minutes (isolated bring-up + a curated ZAP scan against ~250 routes across two identities plus an unauth pass). Run this before a release, or after any change to auth/authorization/policy code — that's exactly the class of bug the cross-identity IDOR pass is built to catch.

```bash
scripts/dast/run.sh --only active
```

**After reviewing a run's findings**, accept the ones you're keeping into the triaged baseline (never automatic — always a human decision after reading `new-findings.txt`/`REPORT.md`):

```bash
scripts/dast/run.sh --only edge --update-baseline
scripts/dast/run.sh --only active --update-baseline
```

**Self-test (the tool's own acceptance gate)** — run this after changing anything under `scripts/dast/` itself, not as a routine security check. It proves the scanner isn't silently broken: plants known-vulnerable canaries in both lanes, asserts they're flagged AND fail the build, then asserts a clean target passes and a baselined finding is suppressed.

```bash
scripts/dast/tests/dast-selftest.sh
```

### Tier 2 — auth-layer probes (`active/tier2.sh`)

Runs inside the active lane, immediately after seeding and **before** ZAP, so a
broken auth layer fails the lane fast rather than after a full spider. Tier 1
(`tests/Authz/`) stubs `VerifySupabaseJwt` and runs in-process, so it can only
ever prove "the Policy said no"; these prove the **token itself** is rejected
and that a real race has exactly one winner.

| Probe | Asserts |
|---|---|
| JWT tampering | flipped signature, `alg=none`, HS256 downgrade → 401; expired `exp` → 401 |
| Real `aal1` staff token | `require.aal2` on `/api/staff/me` → 401 with `error=mfa_required` |
| Claim race | N distinct claimants, one build → exactly one 2xx, rest 409, zero 429 |

Every group carries a **control** (an untampered token must work; the staff
token must be valid on a non-AAL2 route; losers must report `ALREADY_CLAIMED`)
so a pass cannot come from a dead app or an uncontended race.

Three mechanics are load-bearing and easy to break by accident:

- **Tokens are always mutated from a real sign-in, never minted.** GoTrue
  validates `session_id` against `auth.sessions` and rejects forgeries with 403
  `session_not_found` — the wrong reason (see `active/mint-jwt.php`).
- **The claim race uses N *distinct* auth users**, because `throttle:claim` is
  keyed on `supabase_uid`. One token fired N times returns 429s and proves
  nothing; the probe asserts a 429 count of zero for exactly that reason.
  Claimants deliberately have **no `core.users` row** — `ClaimSiteService::claim()`
  throws `ACCOUNT_EXISTS` if one exists.
- **The expired-`exp` probe SKIPs on a default run, by design.** `jwt_expiry` is
  a server-wide GoTrue setting, so one value has to serve both this probe and
  ZAP — and they want opposite things. ZAP mints its tokens once, before a
  ~10-minute plan; at the old 8s default they were dead after ~68s (8s + the
  app's 60s leeway) and every later request got 401, which is how the active
  lane spent months "authenticating" against 401 responses. `bring-up.sh` now
  defaults `jwt_expiry` to 3600 so ZAP's tokens outlive the plan, and this probe
  reports a loud SKIP with the reason in `tier2.md`. To exercise it:
  `DAST_JWT_EXPIRY=8 scripts/dast/run.sh --only active` — that run's ZAP pass is
  unauthenticated, so treat it as an auth-probe run, not a replacement for the
  default one. **A SKIP here is expected; a SKIP is not a pass.**
- **The expired-`exp` case cannot be forged.** Changing `exp` breaks the
  signature, making it indistinguishable from the flipped-signature probe. The
  only honest route is a genuinely short-lived real token, so `bring-up.sh`
  writes a low `jwt_expiry` into the **scratch** config only (`DAST_JWT_EXPIRY`,
  default 8s — same test-only-override pattern as the captcha branch; the
  Supabase CLI 2.101.0 accepts 8 without complaint, verified 2026-07-30). The
  probe then waits `exp + jwt_leeway_seconds + 3`, **not** `exp`:
  `VerifySupabaseJwt` sets `JWT::$leeway` from
  `config('supabase.jwt_leeway_seconds')` (default 60) to tolerate Supabase
  clock skew, so an 8s token is legitimately accepted for 68s. Sleeping only
  past `exp` returns 200 and reads like a critical auth bug when it is
  documented, deliberate behaviour. If the total wait would exceed 120s the
  probe **skips loudly** with the reason written into `tier2.md`; it never
  silently omits itself.

Two traps worth knowing before editing `tier2.sh`:

- **Do not "flip the last character" of a signature.** A 32-byte HMAC signature
  is 43 base64url characters, but 43 × 6 = 258 bits for a 256-bit value — the
  final character's two low bits are discarded on decode. Mutating the last
  character between values differing only in those bits (`A`→`B`) yields
  byte-identical signature bytes, so the token stays **valid** and the probe
  reports 200 while claiming to have tampered with it. The probe mutates the
  **first** character, where all six bits are significant.
- **The active lane is manual-only, so nothing catches it when a formatter
  breaks it.** `6461cff5` (a Pint-only commit) rewrote
  `Illuminate\Contracts\Console\Kernel::class` in `active/seed-identities.php`
  into a short reference plus a `use` import placed *below* the call site. PHP
  registers imports linearly during compilation, so `Kernel::class` compiled to
  the literal `'Kernel'` and the whole lane died with "Target class [Kernel]
  does not exist". The bootstrap now sits below the `use` block, which is both
  correct and Pint-stable.

Output lands in `<outdir>/tier2.md`. Any FAIL die()s the whole active lane —
deliberate: a token the auth layer accepts when it should not is a finding, not
a warning. **Never weaken an assertion to go green** — a real rejection failure
goes through `scripts/audit/fix-flow.md`'s blocker gate (auth code needs a
written plan + sign-off).

Note that `tier2.md` is **not committable** — `audits/dast/.gitignore` whitelists
only `*/REPORT.md`, and `REPORT.md` is built purely from the baseline diff, so it
has no Tier 2 section. A passing Tier 2 therefore leaves no committed trace by
design; absence of evidence here is not evidence it never ran. Since Tier 2
runs before ZAP and die()s the lane, a run that produced a ZAP report is itself
indirect evidence Tier 2 passed.

### ZAP auth injection — the mechanics that make it real

Diagnosed 2026-08-06 and repaired 2026-08-07, after a run showed the active
lane had **never** been authenticated.

> **Every active-lane report dated before 2026-08-07 records an unauthenticated
> run.** `audits/dast/2026-07-30/REPORT.md` and `2026-07-31/REPORT.md` say
> "Exit: 0", and that exit code was real — but every request behind them went
> out with no `Authorization` header, so they are evidence about the anonymous
> surface only. They are kept as history, not as authorization coverage. The
> first genuinely authenticated run is `2026-08-07-active`.

Three ZAP-automation facts, each verified against a header-echoing HTTP server
rather than inferred from a clean-looking run:

- The replacer rule field is **`replacementString`**, not `replacement`.
- There is **no per-rule `enabled` field**. Identity switching is one replacer
  **job per pass**, carrying only that pass's rule, with `deleteAllRules: true`
  to evict the previous one. `deleteAllRules: true` plus no rules list is how
  the unauth pass gets a genuinely bare request.
- `matchType: req_header` **adds** the header when absent, which is what makes
  injection work at all against a scanner that sends no `Authorization` of its
  own.

Ordering is load-bearing: a replacer job only affects jobs **after** it.

The original implementation got all three wrong, and also put the replacer in a
top-level `replacer:` key of `zap-context.yaml` — a plan has only `env:` and
`jobs:`, so that block was dropped whole. ZAP reports every one of these as a
*warning*, then runs the plan and writes a clean report. Two guards at the end
of `zap-active.sh` now make that class of failure fatal:

| Guard | Catches |
|---|---|
| `Unrecognised parameter` in the run log | any plan field ZAP silently ignored — in a self-generated plan that is always a bug |
| `Difference in response code values` | a failed IDOR probe **or** a failed control |

**The IDOR probes are only as good as their controls.** Each probe on identity
B's resource (expect 404) is paired with a control on identity A's own
equivalent (expect 200). A `control-*` mismatch means the pass was vacuous —
bad token, dead app, or a route that does not exist — and fails the lane just
as hard as an `idor-*` mismatch. That third case is not hypothetical: the
original plan probed `/api/sites/{id}`, `/api/media/{id}` and
`/api/enquiries/{id}`, **none of which exist in `route:list`**, so all three
returned 404 for "no such route" and would have satisfied a 404 assertion while
testing nothing.

**Before adding an IDOR probe:** confirm the route exists in `route:list`,
confirm `seed-identities.php` seeds the fixture, and add the paired control.
Sites and site-media have no by-id user route, so they carry no IDOR surface —
their absence here is correct, not a gap to fill.

### IDOR coverage — what "IDOR assertions passed" actually proves

**24 distinct ownership-deciding code paths are probed.** Until 2026-08-09 only 2
were, and a green log said exactly the same thing then as it does now — which is why
the covered set is enumerated here rather than left to the log line. Read the two
"does not prove" paragraphs at the end before treating this as exhaustive.

**The unit is the ownership-deciding code path — a private lookup helper or a policy
ability — not the resource, and not strictly the controller method.** `site/sections/*`
is served by `SectionController`, `SectionGroupController`, `SectionItemController`
*and* `SectionTraceController`; `content.items` is reachable through `ItemController`,
`PoolController`, `ItemLinkController` *and* `ManualOverrideController`. Each
hand-rolls its own scoping, so proving one proves nothing about the others.

The collapse rule, stated precisely because an imprecise one invites a false
completeness claim: **methods sharing a decider collapse to one row; a method with
its own decider gets its own row even inside the same class.** So
`markRead`/`markReplied`/`archive`/`restore` collapse (all route through
`UserEnquiryController::transition`), and `SuggestionsController::accept`/`::dismiss`
collapse (both use `findIntent`) — but `markSpam` does **not**, because it duplicates
the lookup inline at `UserEnquiryController:56-64` rather than delegating.

Derived from `php artisan route:list --json` on 2026-08-09. Note the route count
depends on which path parameters you treat as tenant-owned ids: the narrow reading
used here (`{section|item|id|service|notification|intent|image|customer|category|
upload|restyle|page|feedback|document|connection}`) gives **36** in-scope routes;
counting every parameterised in-scope route minus the enum-keyed ones gives ~51. The
figure that matters is the decider count, not the route count.

| # | Controller | Probe | Expect |
|---|---|---|---|
| 1 | `UserCustomerController::show` | `GET api/customers/{customer}` | 404 |
| 2 | `UserEnquiryController::transition` | `POST api/enquiries/{id}/read` | 404 |
| 2b | `UserEnquiryController::markSpam` | `POST api/enquiries/{id}/spam` | 404 |
| 3 | `UserGalleryController::destroy` | `DELETE api/gallery/{image}` | 404 |
| 4 | `UserUploadController::destroy` | `DELETE api/images/{image}` | 404 |
| 5 | `UserDocumentController::destroy` | `DELETE api/documents/{document}` | 404 |
| 6 | `ContentController::destroyUpload` | `DELETE api/content/uploads/{upload}` | 404 |
| 7 | `UserServiceController::show` | `GET api/services/{service}` | 404 |
| 8 | `UserServiceCategoryController::show` | `GET api/service-categories/{category}` | 404 |
| 9 | `SectionController::show` | `GET api/site/sections/{section}` | 404 |
| 10 | `SectionItemController::index` | `GET api/site/sections/{section}/items` | 404 |
| 11 | `SectionGroupController::index` | `GET api/site/sections/{section}/groups` | 404 |
| 12 | `SectionTraceController::show` | `GET api/site/sections/{section}/trace` | 404 |
| 13 | `PageController::destroy` | `DELETE api/site/pages/{page}` | 404 |
| 14 | `FeedbackController::show` | `GET api/me/feedback/{feedback}` | 404 |
| 15 | `RestyleController::undo` | `POST api/site/restyle/{restyle}/undo` | 404 |
| 16 | `NotificationController::markRead` | `POST api/me/notifications/{notification}/read` | 404 |
| 17 | `ItemController::destroy` | `DELETE api/content/items/{item}` | 404 |
| 18 | `PoolController::deselect` | `DELETE api/content/pools/watch/selection/{item}` | 404 |
| 19 | `ItemLinkController::destroy` | `DELETE api/content/items/{item}/links/{platform}` | 404 |
| 20 | `ManualOverrideController::destroy` | `DELETE api/content/items/{item}/overrides/{facet}/{column}` | 404 |
| 21 | `ConnectionsController::setPrimary` | `POST api/routing/connections/{connection}/primary` | 404 |
| 22 | `SuggestionsController::dismiss` | `POST api/routing/suggestions/{intent}/dismiss` | 404 |
| 23 | `SectionItemController::upsert` (`{item}`) | `PUT api/site/sections/{section}/items/{item}` | 404 |

404 (not 403) is verified per surface from source, not assumed: the policy path is
`BasePolicy::denyAsNotFound()` (`SitePolicy`, `SectionPolicy`, `ServicePolicy`,
`CustomerPolicy`, `FeedbackPolicy`, `NotificationPolicy`, `ContentItemPolicy`,
`DesignKitRestylePolicy`, `IntegrationConnectionPolicy`) and the hand-rolled paths
`abort(404)` / `->error(…, 404)` explicitly. A policy that plain-denies would yield
403 — none in this set does.

**Three routes are deliberately NOT surfaces.** They are enum/string-keyed, so there
is no other tenant's value to substitute and a probe could not fail. Do not "close
the gap" by adding one:

- `api/design-media/{purpose}`
- `api/sections/{blockType}`
- `api/content/pools/{pool}` — `PoolController::show(Request, string $pool)` calls
  `assertPool($pool)`. Looks like an id in `route:list`; is not one.

**Two near-misses carry a second tenant-owned id but are deliberately not probed**,
because in both the effective IDOR target is the parent, which *is* probed:

- `api/site/sections/{section}/groups/{groupKey}` — `{groupKey}` is a section-scoped
  string key (`->where('group_key', $groupKey)`). Every `SectionGroupController`
  method routes through one private `findSection()` (`:96`), so `{section}` is the
  decider and #11 covers it.
- `api/site/sections/{section}/items/{item}` — **for `DELETE` only.**
  `SectionItemController::destroy` scopes `{item}` by `section_id` alone, never by
  owner. That is sufficient: a foreign item cannot be pinned in a section you own,
  so the parent `{section}` is the real target and #10 covers it. The `PUT` on the
  same URI is a different matter — it owner-scopes `{item}` independently, and #23
  probes it.

**What this still does NOT prove.** Two things, stated plainly so a green log is not
read as more than it is:

1. **Verbs, not just deciders.** #3 probes `DELETE api/gallery/{image}` but not
   `PATCH`, because a bodyless `PATCH` 422s on its `FormRequest` first. Both share
   the same `SitePolicy` call, so the *decision* is covered; a divergence introduced
   into one verb's handler alone would not be.
2. **Scope.** Only the two seeded identities' own ownership checks, through the app's
   code, on a local stack. Nothing about prod's `app_backend` restricted role or RLS
   (see "Limitation" at the bottom), and nothing about staff-side authorization —
   `api/staff/*` is out of the scanned population.

### Probing a surface that needs a request body

`section-item-upsert` (#23) is the pattern to copy. `SectionItemController::upsert`
checks **two** ids independently — `{section}` via `findSection`, `{item}` via
`findItem($user->id, ...)` + `authorize 'view'` — so probing `{section}` (which #10
does) says nothing about `{item}`. Two mechanics make the second one probeable:

- **The parent id is pinned to identity A** with an `@SECTION_ID@` token, while `%s`
  carries the varying target. The probe therefore sends *A's own section* with *B's
  item*. Substituting B's section too would fail at the parent check and never reach
  the child one — a vacuous pass wearing a green tick.
- **A body is required.** Laravel validates a `FormRequest` before the controller
  runs, so a bodyless `PUT` returns 422 without ever reaching `findItem`. That is
  precisely why this decider went unprobed until 2026-08-09. Declare the body as a
  fifth `|`-delimited field; `idor_emit` then adds `data:` and a
  `Content-Type: application/json` header. `data` is ZAP's real field name —
  confirmed against `zap.sh -cmd -autogenmax`, not assumed, because ZAP downgrades an
  unknown field to a warning and drops it silently.

Keep the body minimal and side-effect-light: `{"state":"excluded"}` rather than
`pinned`, because a pin also calls `nextSortKey()`.

**How to prove the assertions are live — invert them.** `IDOR assertions passed` is
the *absence* of a `Difference in response code` line, and absence is exactly the
shape of failure that let this lane report PASS while unauthenticated for months. To
get positive evidence, swap the two `responseCode` values in `zap-active.sh`'s
`idor_requests()` (controls expect 404, probes expect 200) and run the lane. ZAP's
mismatch message carries `Received : <code>`, so every request then prints its
**real** status — a full transcript rather than a silence. Done 2026-08-09:
**22/22 controls `Received : 200`, 22/22 probes `Received : 404`, 0 unclassified**
(before `enquiry-spam` was added, so 22 surfaces, not 23). The transcript and its
method are kept at `audits/dast/2026-08-09-active/IDOR-INVERSION-EVIDENCE.md` —
evidence cited but not retained is the same absence-shaped claim this section exists
to reject, so `audits/dast/.gitignore` whitelists it alongside `REPORT.md`. Swap the
two values back afterwards, with `cp` from a backup — never `git checkout --`, which
would restore the committed state and destroy any uncommitted work alongside it.

The lane also carries a **count floor** in front of the assertion gate: it fails if
the run log does not show exactly `2 × ${#IDOR_SURFACES[@]}` requestor requests. The
gate below it keys off the *absence* of a mismatch line, so an empty or truncated
requestor job would otherwise print "IDOR assertions passed" having proved nothing.

**Pool is not cosmetic.** `UserDocumentController::destroy` runs its
`pool === POOL_DOCUMENTS` check *before* the ownership check, so probing it with a
gallery-pool id 404s for the wrong reason on **both** identities — a vacuous pass.
`ContentController::destroyUpload` has the opposite order, so its probe would be
honest but its control would 404. `seed-identities.php` therefore seeds a
documents-pool and a content-pool row on each identity, not one shared gallery row.

## What updates automatically vs what you maintain by hand

The **route surface is fully automatic** — `seed-endpoints.sh` re-derives the OpenAPI seed from `php artisan route:list --json` on every active-lane run, so new/removed/changed API endpoints are picked up with zero action needed. Same for the two seeded identities (freshly created each run) and the baseline diff itself (a finding at a new key just shows as "new"; one that stops occurring just stops appearing — no config change needed either way).

**Five things don't auto-update and need a human to keep them current:**

1. **The active lane's exclusion list** (`active/zap-context.yaml`'s `excludePaths`) — now **two** categories, and the second one is easy to forget:
   - *External side effects* — handlers that reach past the local box (vendor API calls, real email/notification sends, Cloudflare KV writes). Grep for `SyncSubdomainToKvJob::dispatch`, `Mail::`/`Notification::send`, and new entries under `routes/api/platforms.php`.
   - *Self-destruction* — handlers that revoke the scanner's own credentials, delete its account, or change its auth state (`api/sessions/*`, `api/me/deletion/*`, `api/account/mfa/*`). This category only matters now that the scan is genuinely authenticated, and it bites late: an authenticated fuzzer logs itself out mid-pass and every later request 401s, which reads like a broken token rather than a self-inflicted logout. Any new authenticated route that logs out, deletes the account, or rotates credentials belongs here.

   The alternation in `zap-active.sh`'s exclusion-verification `grep` restates the same list and must be updated alongside it.
2. **The 5 custom Nuclei templates** (`edge/templates/*.yaml`) — each asserts against a specific hardcoded path (e.g. `/api/customers/{id}`, `/api/public/unsubscribe/...`). If those specific routes get renamed or restructured, the template should be reviewed so it's still testing something real.
3. **The IDOR probe surfaces** (`zap-active.sh`'s `IDOR_SURFACES` table) — each probe hardcodes a route and its expected status. If one of those routes is renamed or removed, the probe keeps "passing" because a missing route also 404s — the paired `control-*` request is what catches it, so never drop a control to quieten a failure. See the coverage table below for what is and is not proven.
4. **`active/seed-identities.php`** — hardcodes the exact fields needed to build a full identity (User → Site → SiteMedia → Customer → Enquiry), plus the Tier 2 fixtures (a `core.partna_staff` identity, a pool of bare claimant auth users, and one first-come `core.pre_account_builds` row). A schema change (new required column, renamed relation, a tightened CHECK on `build_state`/`built_via`/`source_type`) will break this script until it's updated to match. Note that `auth_user_id` and `status` are **not** in `User::$fillable` — they are assigned directly, and mass-assigning them instead would silently produce an `active` user and make the claim race vacuous.
5. **The curated active-scan rule set** (5 rule IDs in `zap-active.sh`: SQLi, XSS reflected/persistent, path traversal, command injection) — static by design (never "run everything"); only touch it if you deliberately want to broaden or narrow what vulnerability classes get tested.

## Baselines — triage, don't pre-seed

`baseline/*` starts empty and is populated **only** by reviewed triage
(`--update-baseline`, run by a human after reading the findings) — never
pre-seeded, which would bury real bugs. See Phase 10 of the implementation
plan for the first-run triage process.

`nuclei-baseline.txt` is plain text and carries its rationale inline as
comments. The two ZAP baselines are **pure JSON arrays with nowhere to write a
reason**, so every accepted key there must be justified in the table below —
an unexplained key is indistinguishable from a buried bug.

#### Accepted ZAP findings — why

| Key | Accepted | Reason |
|---|---|---|
| `10021@…/robots.txt` | 2026-07-31 | X-Content-Type-Options missing on a static `robots.txt`. No user data, no injection surface. |
| `10096@…/api/sessions` | 2026-08-07 | Timestamp Disclosure (Unix). `created_at`/`last_seen_at` are deliberately `(int)` epochs (`TokenRevocationService::listSessionsForUser`), rendered by the dashboard as "This device" / "Active …". They are the caller's **own** session times behind auth; ZAP 10096 fires on any epoch-shaped integer. Emitting ISO-8601 instead would be a breaking frontend change for no security gain. **Accepted, not fixed** — revisit only if the endpoint starts exposing other users' timestamps. |

The 16 keys in `zap-passive-baseline.json` are the edge lane's header/cookie
nitpicks on `/`, `/robots.txt` and `/sitemap.xml` (accepted 2026-08-03 when the
first real baseline was taken, which is what let the weekly cron's floor drop
from `high` to `medium`).

## Limitation — local ≠ prod authz fidelity

The active lane's local stack does not reproduce prod's `app_backend`
restricted role + RLS via Supavisor. A green active lane means "no
injection/authz class found against app logic," not "prod RLS proven."
Stays a post-launch human-pentest gap — see `REPORT.md` on each run.
