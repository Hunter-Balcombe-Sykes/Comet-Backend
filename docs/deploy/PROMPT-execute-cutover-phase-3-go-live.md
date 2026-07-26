# Cutover Phase 3 — Branch + go-live (paste-in execute prompt)

Operationalises `production-cutover.md` Phase 3 into exact, confirm-before-run commands. This is the
GO-LIVE moment: the `development → production` fast-forward push has no DNS valve behind it —
`api.partna.au` already CNAMEs to the prod env, so the push itself flips the public domain from the old
pre-standalone build to the current one, instantly.

**Rewritten 2026-07-26** to match the corrected `production-cutover.md`. The previous version of this
file asserted three things the doc has since disproved: that the raw `*.laravel.cloud` vanity was a
private rehearsal surface (it is the *same origin* as api.partna.au), that a separate prod
`SUBDOMAIN_KV` namespace exists and the Worker must be redeployed (there is one shared namespace and no
deploy is needed), and that the fast-forward is ~1,518 commits (it is 1,964). If this file and
`production-cutover.md` disagree again, the doc wins — say so and stop.

**How to use:** open a **fresh Claude Code session in this repo on model Opus**, then paste everything
from `=== PROMPT START ===` to the end as your first message.

---

## GATE — do not start until every box is true

- [ ] **Phase 1 is done**: prod DB (`edplucmvkcnokyygxqsb`) re-baselined via the `psql` procedure, ledger
      recorded in `supabase_migrations.schema_migrations`; `app_backend` has `rolcanlogin = t` AND
      `rolbypassrls = t`; Auth hooks (Send Email Hook + MFA Verification Hook) registered on the prod
      Supabase project; Auth project-config parity (Site URL, redirect allowlist, TOTP enabled) applied.
- [ ] **Bootstrap seed — Josh has made an explicit call on the two open items.** As of 2026-07-26 three of
      four rows are in (`core.partna_staff` ×1 = Josh, `core.feature_availability` ×2). Outstanding:
      **(a)** Tobias's staff row is *physically un-seedable* — `core.partna_staff.auth_user_id` is NOT NULL
      + FK → `auth.users` and no prod auth user exists for `ceo@dolustech.net` until he signs up on prod;
      **(b)** Task 5 (see `PROMPT-execute-prod-seed-bootstrap.md`). Neither blocks the push on its own, but
      going live with a single staff account is a **decision**, not an oversight. Get it stated.
- [ ] **Phase 2 is done**: prod Laravel Cloud env has the complete separate secret set (DB, JWT secret,
      origins, Redis, mail, media, Nightwatch, Horizon basic-auth, Cloudflare tokens). `QUEUE_CONNECTION=sync`
      is the DELIBERATE go-live setting (workers flip to `redis` post-cutover per `queue-worker-cutover.md`
      §10 — do NOT treat `sync` as a gap here).
- [ ] **Supabase Pro is on the org, OR Josh has explicitly accepted going live without backups.** As of
      2026-07-26 org `Partna` (`ligsuetayyrxzojoxxbt`) is on `"plan": "free"` — **no managed backups**, and
      the weekly R2 backup Action still points at dev. Prod would carry real signups with no backup of any
      kind. This is the single highest-consequence open box on the gate. Do not let it pass silently.

STOP and report if any box is false or undecided. Do not proceed to the push.

---

```
=== PROMPT START ===

Execute Phase 3 of docs/deploy/production-cutover.md — the go-live cutover moment. Read
docs/deploy/production-cutover.md Phase 3 ("Branch, edge, go-live") and the "Rollback" section IN FULL
before doing anything else. Read docs/deploy/PROMPT-execute-cutover-phase-3-go-live.md (this file) in
full too. **If the doc and this prompt disagree on any fact, the doc wins** — report the discrepancy to
Josh and stop rather than acting on this file.

State this back to Josh before Step 1, plainly, in your own words: **once the Phase-3 push happens,
api.partna.au serves the new production build, instantly, with no DNS staging valve to hold traffic
back — and there is no rehearsal surface, because the raw vanity host and api.partna.au are the same
origin.** The only rollback is stopping the env, which returns api.partna.au to 404.

## Cutover context (read first)
- Prod Supabase project ref: `edplucmvkcnokyygxqsb`.
- `production` is the git **default branch**, currently **0 ahead / 1,964 behind** `development` (head
  `8a3cac7c`, 2026-05-26) — a clean fast-forward, no merge conflicts, but one push ships all 1,964 commits
  at once. Re-verify the count live; do not quote this number without checking.
- **There is no private smoke URL.** `api.partna.au` CNAMEs **directly** to
  `partna-production-uovh3z.laravel.cloud`, and `domain:list production` shows it verified on
  hostname/SSL/origin. Both hosts return the same response from the same origin. Smoking the vanity IS
  smoking the public domain.
- **The prod env is already awake, serving the OLD pre-standalone build.** Waking it did not deploy
  anything new — it rebuilt the `production` branch head (`8a3cac7c`), which predates the standalone
  strip-down (`settings.design.*`, the theme picker, `core.professionals`) and is running against a DB
  re-baselined to the CURRENT schema. So api.partna.au is not 404 right now; it is *wrong*.
- ⚠️ **`GET /up` is a false green.** It is a static liveness route that touches nothing and returned 200
  throughout the mismatch above, while `GET /api/health/scheduler` and `GET /api/public/profiles/{h}`
  both returned **500**. Never accept `/up` as evidence of anything but "PHP booted". Real routes:
  `/api/health` (flat JSON, also shallow), `/api/ready` and `/api/health/scheduler` (HealthController —
  these actually touch dependencies), `/api/ping`.
- **Cloudflare Worker: no deploy needed.** `cloudflare-worker/wrangler.toml` binds `SUBDOMAIN_KV` to the
  single namespace `ce726607804d41a296d6da150b0c537f` on a zone-wide route (`pattern = "*/*"`,
  `zone_name = "partna.au"`), and prod's env var already equals that ID. Dev shares it — a deliberate
  pilot decision (Josh, 2026-07-26), with the known consequence that dev and prod no longer coordinate
  handle uniqueness (same handle on both → last writer wins in KV). Redeploy ONLY if the Worker source
  itself changed.
- `app.partna.au` is a **Vercel** deploy (the dashboard frontend), not a DNS record. Its production API
  base is frontend/Vercel config, not something this backend repo controls.
- `dev-api.partna.au` / `dev-app.partna.au` are separate vanity domains pointing at the dev env/dev
  Vercel deploy — untouched by anything in this run.

## Standing decisions & discipline
- **Documentation/ops-execution split**: this run performs a real infrastructure action (an actual git
  push), not just docs. Every prod-mutating action is prepared and verified by you and EXECUTED by Josh on
  his explicit confirmation, action-by-action. No batching approvals.
- **Never push git without explicit per-action confirmation, immediately before the push runs.** State
  the exact command, wait for "go", then either run it yourself if Josh says to, or watch him run it —
  match whatever he asks per-step.
- Read-only git otherwise. **NEVER `git stash` / `git checkout <file>` / `git restore` / `git reset`**
  (shared stash across live worktrees — forbid this explicitly in any subagent prompt you launch).
- Do not invent steps beyond what `production-cutover.md` Phase 3 and Rollback specify. If something
  seems missing, say so and wait — do not improvise a fix mid-cutover.
- Pin `model: sonnet` on any subagents you launch for this run.

## Step 1 — Record the CURRENT (wrong) serving state, as a before-picture

There is no rehearsal. This step is not a gate you can fail into a "don't push" decision — the old build
is already public and already broken, so the push is the fix, not the risk. What you are doing here is
capturing a before-picture precise enough that you can tell "the push fixed it" from "the push broke
something else" afterwards.

1. Record status codes on the live domain, all of them, verbatim:
   `for p in /up /api/ping /api/health /api/ready /api/health/scheduler; do echo -n "$p "; curl -s -o /dev/null -w '%{http_code}\n' --max-time 15 "https://api.partna.au$p"; done`
   Expect `/up` 200 (meaningless) and at least `/api/health/scheduler` 500 on the old build. Note anything
   that surprises you.
2. Record a public read path: `curl -s -o /dev/null -w '%{http_code}' https://api.partna.au/api/public/profiles/somehandle` — expect 500 on the old build (it would be 404 on a correct one).
3. Run `scripts/launch-check/smoke.sh --base-url https://api.partna.au` and capture every PASS/FAIL/WARN line verbatim. This is the SAME script Phase 4's launch-check gate uses — you are establishing the before-baseline so the after-run is comparable line-for-line.
4. Confirm the fast-forward is still clean: `git fetch origin`, then `git rev-list --count origin/production..origin/development` (report the exact number) and `git rev-list --count origin/development..origin/production` (**must be 0** — anything else means production has diverged and this is no longer a fast-forward; STOP and report).
5. Pull `cloud env:logs partna production --minutes 10` and capture what the old build is throwing, so post-push errors are distinguishable from pre-existing ones.

**Gate: the only STOP condition here is step 4 returning non-zero** (production diverged), or the env
being unreachable entirely (`000` on every path). Old-build 500s are the expected, documented state —
they are the reason for the push, not a reason to withhold it.

## Step 2 — Fast-forward development → production and push (THE GO-LIVE INSTANT)

This is the step Josh runs deliberately, on confirmation, after Step 1's before-picture is captured.
Prepare it fully, then stop and wait.

1. State plainly to Josh: "This push makes api.partna.au serve the current production build, immediately, once the build succeeds. There is no staging step after this, and the only rollback is stopping the env, which returns the domain to 404." Show him the exact command you propose:
   ```bash
   git fetch origin
   git push origin origin/development:refs/heads/production
   ```
   Prefer this ref-push form over a local checkout of `production` — it avoids touching whatever branch is
   currently checked out in this working tree. **Confirm the exact mechanics with Josh before proposing
   the literal command** — do not guess between a direct ref-push and a local-branch approach.
2. **WAIT for Josh's explicit go.** Do not run the push yourself unless he explicitly tells you to; if he wants to run it himself, hand him the exact command and wait for him to confirm it's done.
3. Immediately after the push, watch the deploy build: `cloud deployment:list production` (poll it — do not sleep-loop; use Monitor-style polling or ask Josh to tell you when it's landed). Report build status (queued → building → success/fail) as it changes. Note: the outer status field is not the exit code — read `exitCode`.
4. On build success, re-run **every probe from Step 1** against `api.partna.au` and diff against the before-picture: the health paths, the public profile path, and `scripts/launch-check/smoke.sh --base-url https://api.partna.au`. The `/api/health/scheduler` 500 → 200 flip and the profile 500 → 404 flip are the two signals that actually prove the new build is serving. `/up` staying 200 proves nothing.
5. Sanity-check the deployed *version*, not just liveness — the diagnostic that caught the mismatch last time was a config key resolving to `null` instead of `[]` (`config('partna.connect.deferred')` via `cloud tinker production`). A null where the code can only produce an array means the key does not exist in the deployed build: that is a **version** signal, not a value signal. Use an equivalent post-standalone-only config key to confirm the new code is live.
6. **If the build fails:** do NOT retry blindly. Report the failure output, and invoke the Rollback section below (stop/hibernate the prod env) — do not attempt fixes mid-incident without Josh's sign-off, per the Blocker gate discipline used elsewhere in this codebase for P0-shaped issues.

## Step 3 — Cloudflare Worker: VERIFY, do not deploy

The doc's Phase 3 Worker step is "no change needed at go-live". Your job is to confirm that premise still
holds, not to act on it.

1. Read `cloudflare-worker/wrangler.toml` and confirm the `SUBDOMAIN_KV` binding is still the single namespace `ce726607804d41a296d6da150b0c537f` on the zone-wide route. Read it — do not assume from this prompt.
2. Confirm prod's `SUBDOMAIN_KV` env var (via `cloud environment:get production --json --fields=environmentVariables`) equals that namespace ID.
3. Confirm the deployed Worker source matches `cloudflare-worker/` HEAD. **If and only if it has drifted**, present the exact deploy command and the namespace ID it binds, and CONFIRM with Josh before running — a zone-wide Worker deploy is a live routing change for every `<handle>.partna.au` request.
4. Functional check: hit a handle Josh nominates at `https://<handle>.partna.au` and confirm it resolves through the Worker (cache miss → origin fetch) against prod. Remember prod's DB starts empty — a handle that exists only in dev will still resolve from the SHARED KV, which is expected under the shared-namespace decision and is not evidence prod is serving it.
5. **If anything about the binding or namespace ID is uncertain, stop and ask. Do not deploy against a guess.**

## Step 4 — Point the dashboard at prod (Vercel check, no DNS change)

`app.partna.au` is a Vercel deployment; there is no DNS action here, only a config confirmation.

1. Confirm (with Josh, who has Vercel access — this backend repo does not control the frontend deploy): the frontend's production build has its API base set to `https://api.partna.au`.
2. Confirm `https://app.partna.au` is present in the backend's `PARTNA_FRONTEND_ORIGINS` env var on the **prod** Laravel Cloud env (set in Phase 2) — a CORS/origin check on the backend side, cross-referenced against the frontend's actual origin.
3. This is a verification step, not an action you take — you do not have frontend-repo or Vercel access (per this repo's standing rule: never clone/pull/commit/push the frontend repo from here). Report what Josh confirms.

## Step 5 — Confirm dev domains are untouched

1. `curl -s -o /dev/null -w '%{http_code}' https://dev-api.partna.au/api/health` — expect 200, unchanged by anything above (separate vanity domain, separate env).
2. Confirm with Josh that `dev-app.partna.au` still resolves to the dev Vercel deployment.
3. This step is a sanity check, not a fix — if something IS broken here, STOP and report; do not attempt to repair dev mid-cutover without understanding why it moved.

## Rollback (know it BEFORE you push — read this before Step 2, not after)

- **Fastest path:** stop/hibernate the prod Laravel Cloud env. `api.partna.au` CNAMEs directly to prod, so
  hibernating it returns the domain to 404 — its exact pre-cutover state. There is **no automatic "point
  back to dev"**; dev lives on its own separate `dev-api` vanity and nothing repoints to it by itself.
- **If the domain must keep serving traffic during rollback:** re-point the `api.partna.au` CNAME at the
  dev env's vanity (`partna-development-fsh3vz.laravel.cloud`) as a **deliberate, separate DNS action** —
  not automatic, not part of the normal rollback path. Only if Josh decides the domain cannot sit at 404.
- **Reverting the branch is NOT a rollback.** Force-pushing `production` back to `8a3cac7c` restores the
  pre-standalone code against a current-schema DB — the exact broken combination Step 1 documents. Hibernate
  instead.
- **The DB re-baseline (Phase 1) is the irreversible part of the whole cutover** — there is no "undo" once
  prod starts carrying real signups. Nothing in Phase 3 rolls that back; Phase 3 rollback only un-does the
  *serving* of traffic, not the database state. Note also that as of 2026-07-26 there are **no backups** on
  the free-tier org, so "restore the DB" is not an available move at all.

## PROD-SAFETY RULES (non-negotiable)

- The Step-2 push is the go-live: Josh runs it (or explicitly authorizes you to run it). You prepare and
  verify; you do not decide to push.
- Never push git without explicit per-action confirmation, immediately before it runs — no advance
  blanket approval covers the push itself.
- Read-only git for everything else in this run. **Never `git stash`, `git checkout <file>`, `git
  restore`, or `git reset`.** Forbid `git stash` explicitly in any subagent prompt you launch.
- Never accept `GET /up` as evidence the deploy worked. Use `/api/health/scheduler`, `/api/ready`, and a
  real public read path.
- Do not deploy the Cloudflare Worker unless Step 3 proves its source has drifted AND Josh confirms.

## Stop and ask Josh if

- `git rev-list --count origin/development..origin/production` is non-zero — production has diverged and
  this is no longer a fast-forward.
- The build fails after the Step-2 push — invoke Rollback (hibernate the prod env), report the failure
  output, and wait; do not retry or patch blind.
- Post-push probes do not flip (`/api/health/scheduler` still 500, or the public profile path still 500) —
  the new build may not actually be serving; check the deployed-version signal in Step 2.5 before assuming
  a code bug.
- The Cloudflare Worker's KV binding or namespace ID is uncertain at Step 3 — do not deploy against a guess.
- Anything in `production-cutover.md` Phase 3 or Rollback appears to have changed since this prompt was
  written (re-read the live file — it drifts, and it has drifted from this prompt before).

## When done — report

- Step 1: the before-picture — all five health-path codes, public-profile code, full `smoke.sh`
  PASS/FAIL/WARN output, exact commit counts both directions, and what the old build was throwing in logs.
- Step 2: exact commands run, commit count fast-forwarded, build result (paste the head of
  `cloud deployment:list production` including `exitCode`), and the after-picture diffed against Step 1 —
  explicitly call out the `/api/health/scheduler` and public-profile flips, plus the deployed-version
  config signal.
- Step 3: Worker binding + namespace verification (and whether a deploy was needed at all — the expected
  answer is no).
- Step 4: Josh's confirmation that the Vercel prod API base is `https://api.partna.au` and the origin is
  present in `PARTNA_FRONTEND_ORIGINS`.
- Step 5: dev domain health checks (both still 200/unchanged).
- Explicitly state: this run does NOT constitute the launch-check gate — hand off to
  **`docs/superpowers/plans/2026-07-24-launch-check-3-cutover-PROMPT.md`** (Phase 4) as the formal
  go/no-go verification. Do not report cutover as "done" on the strength of a successful push alone.

=== PROMPT END ===
```
