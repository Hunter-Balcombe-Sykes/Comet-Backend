# EXECUTE PROMPT — DAST: stop coverage drifting, exercise the restricted role, automate the lane

**Why now:** `f13c0e350`/`bf4efe460` took the active lane's cross-tenant IDOR check from **2
ownership deciders to 24**, and proved it positively (all 24 controls returned a real 200, all 24
probes a real 404 — transcript at `audits/dast/2026-08-09-active/IDOR-INVERSION-EVIDENCE.md`).
`e97b123e6` brought the edge baseline current. Both lanes are green.

What is *not* solved: nothing stops that coverage silently rotting, the lane never exercises the
restricted DB role prod actually uses, and it only ever runs when a human remembers to run it.

Paste everything below the line into a fresh session. It is self-contained.

---

Read `CLAUDE.md`, `scripts/dast/README.md` (especially **"IDOR coverage — what 'IDOR assertions
passed' actually proves"** and the **Five things don't auto-update** list), and
`git show f13c0e350 bf4efe460` before touching anything. Those two commit messages are the primary
record of how the current design works and why.

Four units, in this order. **Unit 1 is authorization test infrastructure and Unit 2 changes how the
lane authenticates to the database — both hit `scripts/audit/fix-flow.md`'s blocker gate: write the
plan and get Josh's sign-off before implementing.** Units 3 and 4 do not.

| Unit | What | Size |
|---|---|---|
| **1 — Coverage-drift guard** | A test that fails when a new ownership decider is added and not probed | M. One test in `tests/Feature/Architecture/`. |
| **2 — `app_backend` in the lane** | Connect as the restricted role, not `postgres` | S–M. One env line + a role grant, but read §2 before believing "one line". |
| **3 — Automate the lane** | Weekly schedule + path-filtered non-blocking run | M. One workflow file. |
| **4 — Platform-route IDOR probes** | Authorization coverage for `api/platforms/*` without fuzzing it | M–L. Read the trap in §4 first — it will kill your first run. |
| **5 — DO NOT broaden the scan rules** | Explicitly out of scope. See §5. | — |

## Ground rules for all four

- **The active lane is manual-only today and takes ~12–18 min per run.** It needs Docker. It
  provisions its own throwaway Supabase stack on port offset 54421+ and tears it down via `trap`.
- **`composer test` does not cover `scripts/dast/`.** The tool's acceptance gate is
  `scripts/dast/tests/dast-selftest.sh` — **run it after any change under `scripts/dast/`**.
- **Never weaken an assertion to go green.** A real authorization failure goes through the blocker
  gate; it does not get its assertion relaxed.
- **Do not run `artisan pint` on `scripts/dast/active/seed-identities.php`.** Pint broke that file
  once (`6461cff5`) by moving a `use` below its call site, compiling `Kernel::class` to the literal
  `'Kernel'`. Scope Pint to the file you changed: `./vendor/bin/pint <path>`.
- **macOS ships bash 3.2.** No `${var,,}`, no `${var^^}`, no `declare -A`, no `mapfile`. This bites
  — it was caught in a dry run, not by `bash -n`.
- **This is a shared checkout with sibling sessions active.** Work in a git worktree based
  explicitly off `origin/development` (`origin/HEAD` is `production` — months stale). Expect 1–3
  fetch+rebase cycles before your push lands, and re-run the suite on the **rebased** tip.

## Unit 1 — a coverage-drift guard

### The problem

`IDOR_SURFACES` in `zap-active.sh` lists 24 ownership deciders. Nothing stops #25 being added to the
app and never probed. Coverage silently goes from *all* to *most* while the log keeps printing
`IDOR assertions passed` — which is the **exact failure shape** that motivated this whole programme
(the lane spent months reporting PASS while completely unauthenticated).

The lane proves the probes *pass*. Nothing proves the probe *list* is complete.

### What to build

A test asserting that every in-scope route carrying a tenant-owned id is either probed by
`IDOR_SURFACES` or listed in an explicit, justified exclusion set.

- **Home:** `tests/Feature/Architecture/`. **Closest precedent:
  `DastSelfDestructionExclusionGuardTest.php`** — read it first. It parses `zap-context.yaml` and
  `zap-active.sh` from a test, which is the mechanic you need, and it documents its own detector's
  limits, which is the discipline you need.
- **The collapse rule is per-DECIDER, not per-route or per-controller-method.** `README.md`'s
  coverage section states it: methods sharing a private lookup helper collapse to one row
  (`markRead`/`markReplied`/`archive`/`restore` all route through `UserEnquiryController::transition`);
  a method with its own decider gets its own row even in the same class (`markSpam` duplicates the
  lookup inline at `:56-64`). Your guard must model this or it will demand probes for things already
  covered.
- **Reproduce the route derivation** (2026-08-09, gave 36 in-scope URIs → 22 controllers; 24
  deciders after `markSpam` and `SectionItemController::upsert`):

```bash
jq -r '.[] | select(.uri|test("^(api/(staff|public|internal)/|horizon|storage|p/|api/platforms/|api/sessions/|api/account/mfa/|api/me/deletion/)")|not)
  | select(.uri|test("\\{(section|item|id|service|notification|intent|image|customer|category|upload|restyle|page|feedback|document|connection)\\}")) | .uri' \
  <(php artisan route:list --json) | sort -u
```

- **Known-legitimate exclusions the guard must tolerate** — all already documented in `README.md`,
  do not re-derive them:
  - Enum/string-keyed non-surfaces: `api/design-media/{purpose}`, `api/sections/{blockType}`,
    `api/content/pools/{pool}`.
  - Near-misses where the parent is the real target: `.../sections/{section}/groups/{groupKey}`,
    and `DELETE .../sections/{section}/items/{item}` (scoped by `section_id` alone, which is
    sufficient — a foreign item cannot be pinned in a section you own).
  - Verb variants of an already-probed decider (`PATCH api/gallery/{image}` vs its `DELETE`).

### Traps

1. **A guard that flags a covered decider is worse than no guard** — CLAUDE.md's "add signals
   high-confidence only; noisy guards get suppressed". Prefer under-reporting to noise.
2. **Prove it can fail.** Temporarily delete a row from `IDOR_SURFACES`, confirm the test goes red
   *and names the missing route*, then restore with `cp` from a backup — **never `git checkout --`**,
   which restores committed state and destroys uncommitted work.
3. **Pest `toContain` is variadic** — a second argument is another NEEDLE, not a message.
4. **A negated `toContain` on a compound condition is vacuous.** Assert the positive.
5. **Ask whether it needs its own CI job.** `outbound-http-guard` has one (`ci.yml:553`) because the
   Feature suite can abort before reaching it. `DastSelfDestructionExclusionGuardTest` deliberately
   does not, and records why in its docblock. Decide and state your reasoning either way.

### Done when

The test passes, you have **shown it failing** against a removed surface, `composer test` is green
(`COMPOSER_PROCESS_TIMEOUT=0`), and the README says a drifted probe list is now detected.

## Unit 2 — connect the lane as `app_backend`

### Establish this before you write anything

`bring-up.sh:176` sets `DB_USERNAME=postgres` — a superuser, which **bypasses RLS entirely**. Prod
runs as `app_backend`. So the lane has never exercised the role prod actually uses.

**But be precise about what this does and does not prove.** Verified 2026-08-10 against
`supabase/migrations/20260726000000_baseline_pilot.sql`:

- The role exists in the local stack — `CREATE ROLE app_backend NOLOGIN` at **line 27**, so the
  migrations create it and the lane already has it.
- There are **121** `GRANT ... TO app_backend` statements.
- Of the **27** RLS policies granted to `app_backend`, **25 are `USING (true)`** — permissive.

**Therefore: RLS is NOT your tenant-isolation mechanism for the app role. The application layer is,
and the IDOR pass already tests it on 24 deciders.** Do not write a commit message or a README line
claiming this change proves tenant isolation or "prod RLS". It does not.

What it *does* catch is **missing `GRANT`s and role misconfiguration** — a real prod-breakage class
in this repo's history (the `app_backend` NOLOGIN credential gap). That is the honest justification,
and it is enough.

### What to do

Point the served app at `app_backend` instead of `postgres`. The role is `NOLOGIN` by design, so it
needs a password and LOGIN in the **scratch stack only** — same test-only-override discipline as the
`jwt_expiry` and captcha branches in `bring-up.sh`, which never touch the committed
`supabase/config.toml`.

### Traps

- **Expect this to surface missing grants — that is the point, not a failure of the change.** If a
  DAST route 500s under `app_backend` and worked under `postgres`, you have found a real gap between
  what the app does and what prod's role is permitted to do. Treat it as a finding: write it up,
  take it through the blocker gate, do **not** silently `GRANT` your way to green.
- **`seed-identities.php` runs as the same user.** It does raw inserts across `core`, `site`,
  `content`, `notifications`, `routing`. If seeding breaks, decide deliberately whether the seeder
  should keep superuser access (it simulates a privileged setup step, not app traffic) while only
  the *served app* drops to `app_backend`. That split is defensible — say so explicitly if you take
  it.
- **Supavisor is not in the local stack.** Connection pooling behaviour is still unproven and stays
  a human-pentest item. Do not let this change quietly close that gap in the README.

### Done when

The lane passes end-to-end with the served app on `app_backend`; any grant gaps found are written up
rather than patched away; and the README's "Limitation — local ≠ prod authz fidelity" section is
updated to say precisely what is now exercised (grants/role) and what still is not (Supavisor
pooling, and the fact that permissive policies mean RLS was never the isolator).

## Unit 3 — automate the lane

### What to build

A `.github/workflows/dast-active.yml` that runs the active lane:

- **`schedule:`** — weekly, offset from the existing Sunday 16:00 UTC edge cron so they do not
  contend.
- **`workflow_dispatch:`** — always.
- **`pull_request` / `push` path filter, NON-BLOCKING** on: `app/Policies/**`,
  `app/Http/Middleware/Auth/**`, `routes/api/**`, `supabase/migrations/**`, `scripts/dast/**`.

`supabase/migrations/**` matters more than it looks: a column change breaks `seed-identities.php`,
and today that stays invisible until someone runs the lane by hand and misreads the failure.

### Traps

1. **THE DEFAULT-BRANCH TRAP — read `scripts/dast/README.md`'s edge-lane note first.** GitHub takes a
   scheduled workflow's `schedule:` trigger **and its entire file, including `env:`**, from the
   **default branch** — which here is `production`, hundreds of commits stale. Only the checked-out
   *content* comes from `ref:`. `dast-edge.yml` is living proof: its cron gates at `high` because
   production's copy says so, while development's says `medium`. **Your new workflow will not fire at
   all until it exists on `production`.** Decide how to handle that and say so — do not ship a
   scheduled workflow that silently never runs.
2. **Do not make it a required check.** `supabase start` is documented-flaky — `bring-up.sh` already
   retries it 3× and dies loudly on the third. A flaky required gate trains people to bypass, which
   is worse than no gate. `continue-on-error` or a non-required job.
3. **No secrets needed** — unlike the edge lane, the active lane is entirely local. If you find
   yourself adding a secret, something is wrong.
4. **Budget the minutes.** ~15–20 min per run on `ubuntu-latest` (bring-up + migrations + a ~10 min
   ZAP plan). This is a private repo, so Actions minutes are billed. Weekly is cheap; per-push on a
   broad path filter is not.
5. **Verify it actually works on a hosted runner before declaring done** — Docker-in-Actions plus the
   Supabase CLI is plausible, not proven. `workflow_dispatch` it and read the log. A workflow that
   has never completed once is not done.

### Done when

A `workflow_dispatch` run completes green on a hosted runner, the schedule/path triggers are in
place, the default-branch consequence is documented, and nothing is marked required.

## Unit 4 — targeted IDOR probes for `api/platforms/*`

### The shape of it

`api/platforms/*` is **200 routes** and is excluded from the scan entirely (`zap-context.yaml`
`excludePaths`) because its handlers make real third-party calls — Instagram/GBP/Fresha via Apify,
Google Places, OAuth — with real cost. That exclusion is correct **for fuzzing** and must stay.

But it currently blocks two different things: *fuzzing* (expensive) and *authorization probing*
(one request). The id-bearing routes collapse to a handful of controllers — verified 2026-08-10,
mostly `DELETE`:

```
DELETE api/platforms/{apple/music,apple/podcast,bandcamp,soundcloud}/accounts/{id}   GenericPlatformController
DELETE api/platforms/custom/links/{id}                                              CustomLinksController
DELETE api/platforms/eventbrite/{accounts,events}/{id}                              EventbriteController
DELETE api/platforms/humanitix/{accounts,events}/{id}                               HumanitixController
DELETE api/platforms/online-ordering/entries/{id}                                   OnlineOrderingController
DELETE api/platforms/shop/brands/{id}                                               ShopController
DELETE api/platforms/events/custom/{id}                                             EventsController
```

That is ~7 deciders, not 200. Group by the code that decides, as elsewhere.

### THE TRAP THAT WILL KILL YOUR FIRST RUN

`zap-active.sh:496` greps the ZAP run log and **dies the lane** if any excluded prefix appears:

```bash
grep -qE "api/(platforms/|staff/builds|...)" "$ZAP_WORK/zap-run.log"
```

The `requestor` job's URLs **do** appear in that log (`Job requestor requesting URL …`). So the
moment you add a platform probe, the exclusion check matches your own probe and kills the run — after
you have waited ~15 minutes for it.

Resolve it deliberately. The check's purpose is "this script never *fuzzed* an excluded path", and
`DastSelfDestructionExclusionGuardTest` pins the grep against `excludePaths` **both ways**, so
whatever you change must keep that test honest — update the test alongside, do not delete the
assertion. Note the grep can only ever see requestor URLs and spider seed/error lines anyway (the
spider reports discoveries as a bare `found N URLs`), which is already documented at the check.

### Other traps

- **Verify each control fires no outbound call.** A `DELETE` of a connection row is probably local,
  but `IntegrationConnectionObserver::saved` gates on
  `wasRecentlyCreated || wasChanged(payload|display_settings|is_active)` and folds through
  `IdentitySync` for `google-business`. Check `deleted`/`deleting` hooks too — this unit's whole
  premise is that authorization can be probed without paying for the third-party call. Prove it per
  controller; do not assume from the verb.
- **Seed fixtures with raw inserts**, per `seedProbeFixtures()`. `site.platform_connections.platform`
  is `GENERATED ALWAYS … STORED` — write `surface_key`, never `platform`.
- **Every destructive control needs its OWN fixture row** so requestor ordering stays non-load-bearing.
  See the FIXTURE → CONSUMER map in `seed-identities.php`.
- **Confirm the 404 convention per surface from source.** Do not assume.

### Done when

Each new probe has a paired control asserting a status established from source; a full
`scripts/dast/run.sh --only active --fail-on high` exits 0 with the count floor reporting
`2 × ${#IDOR_SURFACES[@]}` requests; the exclusion check and its guard test still agree; and the
README's coverage table and decider count are updated.

## Unit 5 — DO NOT broaden the curated scan rules

Explicitly out of scope, recorded so nobody "finds" it as an easy win.

`zap-active.sh` enables exactly 5 rule IDs — SQLi (40018), XSS reflected (40012) and persistent
(40014), path traversal (6), command injection (90020) — with `defaultThreshold: "OFF"` so only these
run. That is deliberate curation, not an oversight, and `"OFF"` is quoted because YAML 1.1 parses a
bare `OFF` as boolean `false`, which ZAP silently degrades into scanning with its **full** rule set.

The reason not to broaden: this app's realistic exposure is **authorization**, which is where the
effort has gone and where the coverage now is. Broadening trades a large amount of runtime for
classes that do not match the risk profile. If someone wants this later it should be a deliberate
decision with a runtime budget attached, not a drive-by.

## Reporting

Per unit: **plan → implement → independent review**, per `scripts/audit/fix-flow.md`. Report what you
ran, not what you expect to be true. If a unit turns out blocked, finish the others in full and say
plainly what you left and why.

Two habits from the previous session that earned their keep, both cheap:

- **Dry-render before running the lane.** Extract the surface table and generator, stub the ids, and
  assert the emitted YAML parses, pairs exactly, and every URL matches a real route with that verb.
  Catches in seconds what otherwise costs a 15-minute run — but **re-extract after every edit**, or
  you will validate a stale copy and get a green result on the wrong data.
- **Prove a green gate can go red.** Inverting all the `responseCode` expectations turns
  `IDOR assertions passed` from an absence-of-failure into a positive transcript of every real status
  code. That is what produced `IDOR-INVERSION-EVIDENCE.md`. Absence-shaped evidence is what this whole
  programme exists to eliminate — do not accept it for your own work either.
