# Routine production deploy

**Purpose:** the steady-state, repeatable way to ship backend code to `api.partna.au`. This is the
counterpart to `production-cutover.md`, which describes the one-shot 2026-07-26 cutover event and is
**not** a deploy guide ("a one-shot, mostly-irreversible event, not a routine deploy" — that doc, line 8).

Audience: whoever is pushing. Assumes the cutover is done and prod stands on its own.

---

## The mechanics you are operating

Read these before the first deploy; the checklist below only makes sense against them.

| Fact | Consequence |
|---|---|
| The prod Laravel Cloud env has `usesPushToDeploy: true` and `branch: production`. | **`git push origin …:production` IS the deploy.** No promote step, no approval, no valve. |
| `api.partna.au` CNAMEs directly at the prod env vanity (`partna-production-uovh3z.laravel.cloud`). | The build going green **is** the public change. There is no DNS staging step. |
| The env's `deployCommand` is `# php artisan migrate --force` — commented out, deliberately. | **Code and schema deploy on two separate rails.** Nothing about the git push touches the database. Laravel migrations are banned repo-wide (Composer guard); schema ships only via `supabase db push` against `edplucmvkcnokyygxqsb`, run by hand. |
| CI (`.github/workflows/ci.yml`) runs its real gate on `development`. Status checks attach to the **commit SHA**, not the branch. | A fast-forward carries `development`'s 9 green checks to `production` with it, which is what lets the protection rule below admit the push. The `production` entry in `on.push.branches` is still post-hoc telemetry — Cloud does not wait on Actions, so a red run *there* means *roll back*, not *don't ship*; it already shipped. |
| The `production` branch is the repo's **default branch** and **is protected** as of 2026-08-05: the same 9 required status checks as `development`, `enforce_admins: true`, force-push and deletion blocked. | **You cannot push a commit to prod that CI has not already passed — not even as admin.** The fast-forward discipline below is now mechanically enforced, not merely conventional. Emergency path is unchanged in shape but not in cost: land the fix on `development`, wait for CI (~16 min), then fast-forward. Verify the gate with `gh api repos/:owner/:repo/branches/production/protection`. |
| Supabase org is on the **Free** plan: no PITR, no managed backups. The weekly R2 dump is the only backup, so RPO is ~7 days. | Code rollback is cheap. **Schema rollback does not exist.** That asymmetry drives every judgement call below. |

### The one invariant

`production` must always be an ancestor of `development`. Verify, always:

```bash
git fetch origin
git rev-list --left-right --count origin/production...origin/development
#            ^ left = commits on prod only — MUST be 0
```

A non-zero left number means something reached prod that never went through CI. Stop and reconcile
before deploying anything else on top of it.

---

## The flow

```
feature branch  →  PR into development     ← the only real gate (4 CI jobs)
                →  merge; dev auto-deploys to dev-api.partna.au
                →  exercise it on dev; check Nightwatch dev
                →  apply migrations to PROD Supabase   (separate, manual, FIRST)
                →  git push origin development:production   ← the deploy
                →  watch the build, then verify
```

**Migrations go first, and separately.** New code against an old schema throws 500s; an additive
migration against old code is simply ignored. This is why additive / backwards-compatible migrations
are strongly preferred — they keep the two rails decoupled so their ordering can never bite.

---

## Should I deploy at all?

Work down this list. Any "no" is a stop.

- [ ] **Does the diff touch shipped behaviour?** `git diff --stat origin/production origin/development`.
      If it is only `docs/`, `audits/`, `scripts/audit/`, or `k6/`, there is nothing to ship. Fast-forwarding
      those to prod is harmless (Laravel serves `/public` only) but it is noise, not a deploy.
- [ ] **Is `development` green?** The four CI jobs on the merge commit: `test` (PHPStan L5, Pint,
      Checkpoint, Vigil, `composer audit`, the inline-403 / raw-`Cache::` / unsafe-migration-locking greps,
      launch-check parser regressions, Pest on SQLite), `postgres-tests` (the real-Postgres lane),
      `schema-drift`, `supply-chain`. Green CI is necessary, not sufficient.
- [ ] **Has it actually run?** Exercise the change on `dev-api.partna.au` and confirm Nightwatch dev is
      clean. Tests run on SQLite; prod is Postgres. CHECK/NOT NULL drift is invisible to a green suite.
- [ ] **Does it need a prod migration?** If yes, that is a two-step deploy with a `db push --dry-run` in
      between — give it its own session, not a drive-by at the end of another task. For the **current
      pending set** it also requires a manual `pg_dump` of prod first (see the pre-flight under Rollback);
      20 of those migrations have no usable reverse path.
- [ ] **Is `production` 0 ahead?** The invariant above.
- [ ] **Is now a sane time?** Prod carries no customer data yet, so blast radius is small — but this will
      stop being true. Once it does, avoid deploying with nobody watching.

### Frequency

Small and frequent beats large and rare. Cutover is the cautionary tale: `production` sat 1,518 commits
behind `development`, and closing that gap meant one push shipping everything at once with no way to
bisect a failure. Every week of drift makes the next deploy harder to reason about. While prod is empty,
push whenever `development` is green and the diff touches real code.

---

## Deploy

```bash
# 0. The env must be RUNNING. A stopped env accepts the push and silently
#    never deploys — see the warning below. Non-negotiable pre-flight.
~/.composer/vendor/bin/cloud environment:get production --json --fields=name,status
#   status MUST be "running"

# 1. Confirm the fast-forward is clean and see exactly what ships
git fetch origin
git rev-list --left-right --count origin/production...origin/development   # left MUST be 0
git log --oneline origin/production..origin/development
git diff --stat origin/production origin/development

# 2. Migrations FIRST, if any (separate, deliberate, prod-confirmed)
git diff --name-only origin/production origin/development -- supabase/migrations/
#   if non-empty:  ← REQUIRED for the current pending set: take the manual
#                    pg_dump first. See "Pre-flight: dump prod before the first
#                    push of this pending set" under Rollback below.
#                  supabase link --project-ref edplucmvkcnokyygxqsb
#                  supabase db push --dry-run     ← read every line
#                  supabase db push
#
# 2a. AFTER EVERY MIGRATION PUSH — re-run launch-check's schema group. Standing
#     step, not a judgement call: the schema snapshot is the only thing that
#     catches a migration that applied but left the DB shaped differently from
#     what the code expects. Schema rollback does not exist here, so this is the
#     last point where a mistake is still cheap.
scripts/launch-check/launch-check.sh --only schema
#     Non-zero exit ⇒ STOP. Do not proceed to step 3. Reconcile the drift first.

# 2b. Catalog changes, if any (bootstrap/catalog/compiled.php or app/Catalog/**)
git diff --name-only origin/production origin/development -- bootstrap/catalog/ app/Catalog/
#   if non-empty:  php artisan catalog:compile --check   ← artefact must match definitions (CI also guards this)
#                  and AFTER the code lands (step 4 succeeded):
#                  cloud command:run production 'php artisan catalog:sync'
#   The hourly scheduled catalog:sync is the convergence net if this step is
#   forgotten — but run it explicitly so the catalog schema is current the
#   moment the deploy finishes, not up to an hour later.

# 2c. BEFORE EVERY PROMOTE TO PRODUCTION — full local launch-check gate.
#     Standing step. `git push …:production` IS the deploy and is NOT gated by
#     CI (see "The mechanics" above), so this run is the only gate that exists
#     between your machine and the public site.
scripts/launch-check/launch-check.sh
#     Non-zero exit ⇒ do not push. There is no valve downstream of step 3.

# 3. Ship
git push origin development:production

# 4. Watch it land
~/.composer/vendor/bin/cloud deployment:list production

# 4a. ONE-TIME, until it lands: confirm the DAST edge cron's failure floor.
#     .github/workflows/dast-edge.yml on production still sets DAST_FAIL_ON: high;
#     development sets medium (lowered 2026-08-03 once a real baseline existed). A
#     scheduled workflow runs ENTIRELY from the DEFAULT branch's copy of the file —
#     steps, `run:`, `env:`, even the `ref:` expression; only the CHECKED-OUT repo
#     content comes from `development`. So the Sunday cron runs development's
#     SCRIPTS under production's ENV, and the weekly gate is `high`, not the
#     `medium` that was set. Not worth a deploy of its own, so it rides along here.
#     Nothing to DO — the fast-forward carries `medium` automatically. This is a
#     post-push confirmation, which is why it sits here and not before step 3.
git fetch origin && git show origin/production:.github/workflows/dast-edge.yml | grep DAST_FAIL_ON
#     Once this reads `medium`, delete this step. Until then, a green Sunday cron
#     does not mean "clean at medium" (scripts/dast/README.md says so too).

# 4b. ONE-TIME, until it lands: confirm the DAST ACTIVE lane's cron can fire.
#     .github/workflows/dast-active.yml was added on development 2026-08-10. Same
#     default-branch mechanic as 4a, but a step worse: the file does not exist on
#     production at all, so GitHub has no `schedule:` to register and the weekly
#     Sunday 04:00 UTC run NEVER FIRES — silently, with no failed run to notice.
#     WORSE, verified 2026-08-10: `workflow_dispatch` is ALSO dead until then.
#     `gh workflow run dast-active.yml --ref development` returns HTTP 404
#     ("workflow not found on the default branch") and the Actions UI offers no Run
#     button — `--ref` picks which branch to RUN, not where the workflow may LIVE.
#     Only the pull_request path filter works from development today.
#     Nothing to DO — the fast-forward carries the file automatically.
git fetch origin && git show origin/production:.github/workflows/dast-active.yml >/dev/null 2>&1 \
    && echo "dast-active.yml is on production — cron + workflow_dispatch now work; delete this step" \
    || echo "not on production yet — active-lane cron AND workflow_dispatch are both dead"
#     Confirm a scheduled run actually appears after the next Sunday:
#         gh run list --workflow dast-active.yml
```

Note step 3 pushes `development`'s tip to `production` without checking out `production` locally — that
is intentional. It makes a non-fast-forward impossible to do by accident (git refuses it) and removes any
chance of committing onto a local `production` branch.

> **A stopped environment swallows the push — verified live 2026-07-26.** `usesPushToDeploy: true` only
> deploys when the environment is `running`. Push to a `stopped` env and every signal looks benign:
> git reports the ref updated, `refs/heads/production` genuinely moves to the new commit, no error is
> raised anywhere — and no deployment is created. `deployment:list production` keeps showing the
> *previous* deploy as `deployment.succeeded`, which reads as "we're fine" rather than "we never shipped".
>
> Two things make this easy to miss. Prod has `usesHibernation: false`, so `stopped` is a deliberate
> state someone set, not something it recovers from on traffic. And the branch really did advance, so
> `git log origin/production..development` comes back empty afterwards — by every git-side check the
> deploy looks done. The only proof is a deployment whose `commitHash` matches what you pushed:
>
> ```bash
> ~/.composer/vendor/bin/cloud deployment:list production --json \
>   | python3 -c 'import json,sys; d=json.load(sys.stdin)[0]; print(d["commitHash"][:8], d["status"])'
> git rev-parse --short development    # must equal the hash above
> ```

---

## Verify — after every deploy

**`/api/health` is liveness-only.** It returned green for an hour on a fully broken prod during cutover.
It proves PHP booted; it proves nothing about the database. Never verify a deploy with it alone.

Minimum, every time:

```bash
# DB-touching smoke (404-not-403, debug leakage, .env exposure, and a document-download
# probe that requires a real DB lookup)
scripts/launch-check/smoke.sh --base-url https://api.partna.au

# Deployed-env groups against the thing you just shipped. Opt-in groups (they need
# the `cloud` CLI + a deployed env), which is exactly what you have at this point.
scripts/launch-check/launch-check.sh --only env,runtime --base-url https://api.partna.au
```

Then Nightwatch (prod project) for new exceptions in the deploy window, and `cloud env:logs partna
production --minutes 10` if anything looks off.

> **Launch-check is a standing deploy step, not an occasional audit.** Three fixed points, all of
> them above: `--only schema` after **every** migration push (2a), the full run before **every**
> promote (2c), and `--only env,runtime` after the deploy lands (here). The reasoning is in
> "The mechanics you are operating": the push *is* the deploy, CI does not gate `production`, and
> schema rollback does not exist. Nothing else in this flow will catch a drifted schema or a
> misconfigured deployed env before customers do.

> **smoke.sh false-FAILs if you run it too often — verified live 2026-07-26.** Its DB-touching probe
> hits `/api/public/documents/<fixed-uuid>/download`, and that route carries **two** stacked throttles:
> `throttle:public-site` (60/min) *and* `throttle:document-download`, which is
> `Limit::perHour(10)` keyed by `IP + ':doc:' + <document uuid>`
> (`AppServiceProvider::configureRateLimiting`, `partna.throttle.document_download_per_hour`).
> Because smoke.sh always uses the **same** UUID from the same IP, roughly the 11th run within an hour
> starts returning 429 and smoke.sh reports
> `FAIL missing public resource returned 429 — must be 404, never 403`.
>
> That FAIL means "you are rate-limited", not "prod is broken" — the 429 is itself proof the app is up
> and serving. Because the limiter key includes the UUID, requesting a **different** random UUID escapes
> the bucket.
>
> **A bare 404 is not sufficient proof of health.** A stopped Laravel Cloud environment serves 404 with
> an empty body for *every* path, `/api/health` included — so "404 on a random UUID" is equally
> consistent with "app healthy, document absent" and "nothing is running." Distinguish them by the
> response headers: Laravel attaches its middleware headers, a stopped env's edge 404 has none.
>
> ```bash
> # A Laravel-served 404 carries the app's security headers; an edge 404 is bare.
> curl -sI "https://api.partna.au/api/public/documents/$(uuidgen | tr 'A-Z' 'a-z')/download" \
>   | grep -iE '^(HTTP/|content-security-policy|permissions-policy|referrer-policy)'
> # healthy  -> 404 AND the three policy headers present
> # stopped  -> 404 with content-length: 0 and no policy headers  => the env is not running
> ```
>
> Cross-check the env itself when the headers are bare — `cloud environment:get production --json
> --fields=name,status` must say `running`. Verified live 2026-07-26: a `stopped` prod returned bare
> 404s on every route while `deployment:list` still showed the previous deploy as `succeeded`.
>
> Practical impact: this only bites when deploying more than ~10 times an hour, or after any load or
> probe traffic against that route. Do not "fix" it by relaxing the limiter — the 10/hour cap is
> deliberate anti-enumeration protection on a route that 302s to presigned R2 URLs.

> **A deploy that leaves zero workers on a low-traffic queue stalls anything reserved at that
> instant — indefinitely, not for `retry_after`.** On queues like `cloudflare` and `cache-warm`,
> a worker restart during deploy can land between reserve and completion; recovery is
> worker-triggered, not time-triggered (`migrateExpiredJobs()` runs inside the worker's `pop()`
> loop — there is no background reaper). Drill 01 (2026-08-05, `docs/runbooks/queue-backed-up.md`)
> measured a job stuck reserved for 145s against a 90s `retry_after` with zero workers, converging
> in 1s once a worker came back. If a deploy verification finds a reserved job that isn't draining,
> check for a live worker before assuming a stuck job.

For a deploy that touched env vars, queues, caching, jobs, or the edge path, add the deployed-env groups.
Both are gated and refuse to run against prod without an explicit opt-in:

```bash
export LAUNCH_CHECK_CONFIRM_PROD=1              # required; the gate refuses production otherwise
export LAUNCH_CHECK_HANDLE=<a published prod handle>

scripts/launch-check/env-check.sh     --env production --target launch
scripts/launch-check/runtime-health.sh --env production --target launch
```

- `env-check.sh` (group G) asserts required vars are present and `APP_DEBUG` / `APP_ENV` /
  `QUEUE_CONNECTION` / `CACHE_STORE` are correct on the *running* env.
- `runtime-health.sh` (group H) probes Horizon masters, Redis, media disk, scheduler, failed jobs, and
  edge delivery of a real sitepage.
- **Caveat while prod is empty:** `site.sites = 0`, so there is no published prod handle. Group H's edge
  probe **FAILs** on an unset `LAUNCH_CHECK_HANDLE` by design (a probe that ran nothing must never read as
  a pass). Until a real prod sitepage exists, expect that FAIL and run the runtime-liveness half knowing
  the edge half is unverified — do not "fix" it by softening the check.
- `launch-check.sh` has no `--env` flag; the two scripts above must be called directly for prod.

---

## Rollback

**Code** — cheap, three options in order of preference:

1. **Re-push the previous good SHA.** `git push --force-with-lease origin <last-good-sha>:production`.
   Then immediately reconcile `development` so the invariant (`production` is an ancestor of
   `development`) is restored — otherwise the next deploy silently re-ships the bad commit.
   `production`'s protection deliberately leaves `allow_force_pushes: true` so this path survives; the
   required status checks are what guard it. A last-good SHA was green when it shipped, so the rollback
   is admitted — while a force-push to a SHA CI never saw is refused. **Do not "harden" this to
   `false`**: it would cost you the fastest rollback and buy protection the checks already provide.
2. **Revert forward.** Revert on `development`, let CI run, fast-forward again. Slower but keeps the
   invariant intact and leaves an honest history. Prefer this when the bad commit is not the tip.
3. **Stop the prod env.** `api.partna.au` returns 404 (it CNAMEs at prod, so there is no "point back to
   dev"). Use only when serving nothing beats serving broken.

**Schema** — there is no rollback. No PITR, no managed backups, weekly R2 dump only. A bad migration is
repaired by writing a forward migration, not by restoring. This is the whole reason schema changes are a
separate, slower, confirm-first path.

Each file in `supabase/migrations/` carries a `-- ROLLBACK:` header line stating its inverse
statement, or `NONE` and why (`CONVENTIONS.md` §10). Read the note before writing the forward
repair — several migrations in the current pending set are genuinely one-way and the note says
which.

### Pre-flight: dump prod before the first push of this pending set

**Required, once, immediately before the first `supabase db push` of the current pending set.** Not a
standing rule for every deploy — a rule for *this* set, and it stays required until the pilot is stable.

**What:** a manual `pg_dump` of the prod database, taken with nothing else in flight, held on hand (not
deleted when the push goes green) until the pilot has run without incident.

**Why:** 71 migrations are pending against prod, and 20 of them state no usable reverse path — nineteen
`-- ROLLBACK: NONE` and `20260728100000_retire_pinterest`, whose note reads
"ONE-WAY IN PRACTICE, two different ways." Two are unrecoverable *in kind*, not merely inconvenient:

> **Re-derive these two numbers before every deploy — they only ever grow.** Verified 2026-08-06; they
> were 53 and 13 on 2026-07-30, so the pending set grew by 18 migrations in one week. It moved by two
> *within the hour* this figure was last written, when a branch merged — treat any number here as
> stale on sight and re-run the commands.
> ```bash
> comm -23 <(git ls-tree -r --name-only origin/development -- supabase/migrations/ | grep '\.sql$' | sort) \
>          <(git ls-tree -r --name-only origin/production  -- supabase/migrations/ | grep '\.sql$' | sort) \
>   | tee /tmp/pending.txt | wc -l
> xargs grep -l 'ROLLBACK: NONE' < /tmp/pending.txt | wc -l  # + retire_pinterest, worded differently
> ```
> Compare **filenames**, not `git diff`. The baseline is already applied to prod, but its file still
> differs between the branches (a `ROLLBACK:` comment was added to it after the cutover), so a
> content diff lists it as pending and its own `ROLLBACK: NONE` inflates the second number — the
> earlier command over-reported both counts by exactly one.

| Migration | What a bad apply destroys |
|---|---|
| `20260727130000_ingest_schema` | Creates `ingest.effects`, the **charge-once money ledger** (`cost_tag` / `cost_units` / `claimed_at` / `settled_at`). Its own header says it: "dropping it destroys the" record. If a failed apply forces a schema drop, the record of vendor spend already incurred is gone — and the ledger is what stops the same work being paid for twice. |
| `20260728100000_retire_pinterest` | Collapses `routing.source_intents.state`: `'proposed'` and `'blocked'` both become `'superseded'`, and nothing records which was which. The column's own CHECK lists five distinct states; two of them stop being distinguishable. Not recoverable at all, per the file's ROLLBACK note (b). |

**Context — the dump is the only net.** The Supabase org is on the **Free** plan: no PITR, no managed
daily backups, and the preferred "restore to a new project" path is a paid-tier feature
(`docs/runbooks/drills/04-backup-restore.md`). The weekly encrypted dump in
`Hunter-Balcombe-Sykes/partna-db-backup` is the only backup that exists, so **schema RPO is ~7 days**.
Between the last weekly run and your push there is nothing, which is exactly the gap this step closes.

**How to take it.** Same invocation and same connection form as the restore drill and the weekly
workflow — do not invent flags for this:

```bash
# Connection: the prod project's SESSION pooler string (port 5432), NOT
# db.<ref>.supabase.co (IPv6-only) and NOT transaction mode — pg_dump needs
# session-level features (docs/runbooks/db-pool-exhausted.md). Take it from
# Supabase → project edplucmvkcnokyygxqsb → Connect → Session pooler.
# Use the project OWNER role (postgres.<ref>), not the backend's
# app_backend.<ref> — that role holds restricted grants (audit is
# SELECT/INSERT only) and would dump an incomplete database.
export PROD_DB_URL='postgresql://postgres.edplucmvkcnokyygxqsb:<pw>@<pooler-host>:5432/postgres'

pg_dump "$PROD_DB_URL" --format=custom --no-owner --no-privileges \
  -f "partna-prod-preflight-$(date +%Y%m%d-%H%M).dump"
```

Three things this dump does **not** capture, all of them verified during the 2026-07-26 drill:

- **Roles are cluster-global** — `pg_dump` never carries them. A restore still needs
  `ALTER ROLE app_backend WITH LOGIN PASSWORD …` (it is `NOLOGIN` by default).
- **Extensions live outside the dumped schemas**, so they are re-created, not restored.
- **`pg_dump` omits invalid indexes** (`indisvalid = false`). A `CREATE INDEX CONCURRENTLY` that failed
  half-way is silently absent from the dump — see `production-cutover.md`.

Use the Postgres **17** client (`pg_dump --version`); an older client fails with a server-version
mismatch. And when passing the URL via a secret or env file, the value must be the bare URL — the
first weekly backup run failed because the secret included the `SUPABASE_DB_URL=` prefix and libpq
parsed it as a conninfo option name.

---

## Related

- `production-cutover.md` — the historical one-shot cutover. Phases 4 and 5 record what was and was not
  verified at go-live; read Phase 5's caveats before assuming any of it still holds.
- `scripts/launch-check/README.md` — full group reference; read the "nothing checked is never a pass"
  section before interpreting any WARN.
- `queue-worker-cutover.md` — the queue/Horizon flip, if a deploy touches worker config.
- `docs/runbooks/drills/` — failure-mode drills, including the backup/restore rehearsal.
