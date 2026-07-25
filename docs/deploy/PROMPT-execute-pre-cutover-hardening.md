# Pre-cutover hardening — execute prompt: the review fixes that are WORK, not words

Executes the four execution-level findings of the 2026-07-22 adversarial review of
`docs/deploy/production-cutover.md` (and the reconcile/collapse prompt that accompanied it, since deleted
on ship). The doc-text corrections from that review are already applied (BYPASSRLS, wipe SQL,
grant-matrix parity, the 11-bundle count); what remains is work that has to be *done* before cutover day,
independent of the reconcile/collapse task — which was **completed 2026-07-26** (see the Phase-0
checkboxes in `production-cutover.md`).

> Note: that review's `db diff --from/--to` fallback turned out to be unusable — the command silently
> returns empty output regardless of real differences on CLI 2.101.0. See the warning under the Phase-0
> collapse checkbox for the dump-diff + fingerprint method that replaced it.

**What this produces:** (1) dev rehearsing `QUEUE_CONNECTION=redis` + Horizon so go-live is not prod's
first-ever async boot; (2) a counted inventory + decision brief for the live dev-served sitepages that
prod's empty DB/KV would strand at go-live; (3) a captured Auth-config parity checklist (dev → prod
values); (4) a gated, read-only inventory of the old prod DB (row counts + `auth.users`) that sizes the
Phase-1 archive-dump and auth-purge steps. Outcomes recorded as annotations/ticks in
`production-cutover.md` Phase 0/1, committed on a branch, **unpushed**.

**What this does NOT do:** no schema changes, no migrations, no writes to ANY database, no prod
mutations of any kind, no pushes. The reconcile/collapse task and cutover day are separate runbooks.

---

## GATE

- [ ] Josh has read the 2026-07-22 review findings (this prompt implements M2-input/M3-input/M4/M5 and
      the Auth-parity gap).
- [ ] The dev environment is not mid-deploy / mid-incident (the queue rehearsal changes dev's runtime).

**How to use:** open a **fresh Claude Code session in this repo on model Opus**, then paste everything
from `=== PROMPT START ===` to the end as your first message.

---

```
=== PROMPT START ===

Execute docs/deploy/PROMPT-execute-pre-cutover-hardening.md — the four pre-cutover work items from the
2026-07-22 cutover-docs review. Read docs/deploy/production-cutover.md (Phase 0/1) and
docs/deploy/queue-worker-cutover.md IN FULL first.

## Standing decisions & discipline
- READ-ONLY against every database. Dev queries via MCP execute_sql SELECTs only. NO
  writes, NO migrations, NO `db push`/`repair`/`apply_migration` anywhere in this run.
- PROD IS GATED: Task 4 is the ONLY prod contact, it is SELECT-only, and you must present the exact
  queries and WAIT for Josh's explicit go before connecting. Everything else targets dev
  (`glncumufgaqcmqhzwrxm`) or the repo.
- Env changes (the dev queue flip) are made by Josh in the Laravel Cloud UI — you propose the exact
  values, he applies, you verify. Never edit .env directly.
- NEVER `git stash` / `git checkout <file>` / `git restore` / `git reset` (shared stash across live
  worktrees). Forbid `git stash` in every subagent prompt. Pin `model: sonnet` on subagents.
- Work on a branch `chore/pre-cutover-hardening` off origin/development (worktree
  ../backend-wt/pre-cutover-hardening; NOT under .claude/worktrees/). Commit doc annotations there.
  DO NOT push — Josh reviews and merges.
- Verify `git rev-parse --abbrev-ref HEAD` + `git diff --cached --stat` before every commit. Trailers:
  Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
  Claude-Session: <your session url>

## Task 1 — Rehearse the queue flip on dev (runbook Phase 0 checkbox "Rehearse the queue flip")
1. Read docs/deploy/queue-worker-cutover.md end to end; extract its open action items (the two
   config/doc items, two minor code fixes, four never-job-ified connect flows) and their current status
   (grep the code — some may have shipped since 2026-07-20).
2. Propose to Josh the exact dev env change: QUEUE_CONNECTION=redis + Horizon supervisor config
   (config/horizon.php already defines supervisors — confirm which environment key dev resolves).
   WAIT for him to apply it in Laravel Cloud.
3. Verify live: `cloud tinker development` → config('queue.default') === 'redis'; Horizon masters up
   (`cloud command:run development --cmd="php artisan horizon:status"`); dispatch a harmless probe job
   and watch it drain through Redis (NOT inline).
4. Soak: watch Nightwatch + `cloud env:logs partna development --minutes 15` for job failures, timeout
   escalations, or the sync-masked behaviors the doc predicts (e.g. ->delay() now real on the scraping
   rate-limiter). Triage anything that surfaces: report, don't hot-fix without sign-off.
5. Tick the runbook's Phase-0 rehearsal checkbox with a dated note of what surfaced.

## Task 2 — Live-sitepage inventory + decision brief (runbook Phase 0 checkbox "Decide the fate…")
1. Read-only dev queries (MCP execute_sql), e.g.:
   - published sites: SELECT count(*) FROM site.sites WHERE published_at IS NOT NULL (adapt to the real
     publish flag — check the model/migrations first);
   - live pre-account builds: SELECT count(*) FROM core.pre_account_builds WHERE claimed_at IS NULL;
   - invites in flight: SELECT count(*) FROM core.pre_account_builds WHERE invited_at IS NOT NULL
     AND claimed_at IS NULL;
   - claimed/real users with published sites (these break hardest at go-live).
   Verify column names against supabase/migrations DDL before running — do not guess.
2. Write a one-page decision brief: counts, what breaks at go-live (Worker → empty prod KV; api → empty
   prod DB), the three options (migrate users+builds to prod / accept breakage + pause outreach around
   cutover / stage: freeze invites now, let builds expire). Recommend one.
3. Present to Josh; record his decision verbatim in the runbook's Phase-0 checkbox. Do not implement
   a migration path in this run — if he picks "migrate", that becomes its own planned task.

## Task 3 — Auth-config parity capture (runbook Phase 1 checkbox "Auth project-config parity")
1. Capture the DEV project's Auth configuration as the reference: Site URL, redirect-URL allowlist,
   OTP expiry/length, email rate limits, MFA/TOTP settings. Use the Supabase dashboard values Josh
   pastes/screenshots, or the management API read-only if available — do NOT guess from code.
2. Cross-check against what the code expects: config('partna.*') frontend origins, the claim OTP flow,
   staff AAL2 (docs/auth/mfa-foundation.md).
3. Write the resulting prod checklist (setting → required prod value → why) into the runbook's Phase-1
   Auth-parity checkbox as an indented annotation, so cutover day is a transcription job, not research.

## Task 4 — Old-prod inventory (GATED; SELECT-only; sizes Phase 1's archive-dump + auth-purge steps)
1. Present the exact queries to Josh and WAIT for his explicit go before ANY prod connection:
   - row counts per table across the old app schemas (information_schema.tables + pg_class reltuples,
     or count(*) per table — it's tiny);
   - SELECT count(*) FROM auth.users; and min/max created_at.
2. Report: which old tables actually hold data (esp. anything waitlist/early-access/PII-shaped), and the
   auth.users count. Annotate the runbook's Phase-1 archive-dump and auth-purge checkboxes with the
   findings ("N rows in X as of <date>") so the cutover-day operator knows what they're deleting.
3. Nothing else touches prod. No writes, no dumps in this run (the dump itself is a cutover-day step).

## Stop and ask Josh if
- The queue flip surfaces failures beyond triage (jobs erroring persistently, Horizon not booting).
- The sitepage counts are materially non-zero and he hasn't decided a path — do not proceed to cutover
  prep on an undecided funnel.
- Prod access is needed beyond the Task-4 SELECTs for any reason.

## When done — report
- Task 1: dev queue state (redis + masters), what the soak surfaced, action items still open from
  queue-worker-cutover.md.
- Task 2: the counts, the brief, Josh's recorded decision.
- Task 3: the parity checklist location + any dev settings that look wrong TODAY (fix-on-dev candidates).
- Task 4: old-prod inventory + auth.users count (or "not run — Josh deferred").
- Branch + git log --oneline (UNPUSHED). Explicitly: no database writes anywhere; prod contacted only
  for Task-4 SELECTs (or not at all).

=== PROMPT END ===
```
