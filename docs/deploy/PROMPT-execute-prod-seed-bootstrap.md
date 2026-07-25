# Production seed / bootstrap rows — execute prompt

Resolves the `production-cutover.md` Phase 0 checkbox **"Decide reference/seed data a fresh prod needs"**
and then *performs* the seeding on cutover day.

**The decision (Josh, 2026-07-26), already made — this prompt executes it, it does not re-open it:**

| What | Verdict |
|---|---|
| `core.partna_staff` | **Seed 2 rows** — Josh + Tobias, both `admin`. (Dev's third row, `Staff Test` / `staff-test@partna.tech`, is a test account — **not** carried to prod.) |
| `core.feature_availability` | **Seed 2 rows** — `integration.strava` + `integration.skool`, both global `disabled`. Dev's third row (`integration.fresha` enabled @ a QA segment) is **not** carried — Fresha is in pilot scope and absence-of-row already means available. |
| `core.user_segments` | **Nothing.** Dev's single row is a QA artifact with 0 members. |
| `core.feature_flags` / `core.feature_flag_overrides` | **Nothing.** Both empty on dev, `config('partna.features') === []`, and the `feature:` middleware (registered `bootstrap/app.php:112`) has **zero usages in `routes/`**. The flag system is wired but dormant. |
| Everything else | **Nothing.** Verified 2026-07-26 against dev `pg_stat_user_tables`: every other populated table in `core`/`site`/`notifications`/`analytics`/`audit` holds user-generated data. **The schema contains no lookup/reference tables at all** — reference data (reserved subdomains, the platform integrations registry, pre-account sources) lives in `config/partna.php` and ships with the deploy. There is no `database/seeders/` and there is not meant to be one. |

**Why this is a runbook step and not a migration:** the values are prod-specific (`auth_user_id` is a
**prod** `auth.users` UUID and is meaningless anywhere else) and it is data, not schema. A migration file
would re-run against dev and local and insert garbage. Do not "helpfully" convert this into
`supabase/migrations/`.

**Where this sits in the cutover — it straddles two phases:**

- **Tasks 0-4 → Phase 1**, after the baseline is applied, after `ALTER ROLE app_backend … LOGIN`, and
  after the **prod Auth config is live** (Send Email Hook → Resend, redirect allowlist, TOTP enabled —
  the Phase-1 Auth-parity checkboxes). The Auth prerequisite is the real gate, not the baseline: Task 1
  requires two humans to receive a confirmation email and enrol TOTP on prod. Do that days earlier if
  you can. Tasks 0-4 touch the database only and need no running app.
- **Task 5 → Phase 4.** It calls `api.partna.au`, which does not resolve to the prod environment until
  Phase 3 go-live. Attempting it during Phase 1 will fail for reasons that have nothing to do with the
  seed.

Task 2 must be green before Phase 4, because every staff-facing Phase-4 assertion is unreachable
without it.

**What this does NOT do:** no schema changes, no migrations, no `db push`, no user/site/build data
migration (Phase 0 already decided: accept the breakage), no env-var changes, no code changes.

---

## GATE

- [ ] The collapsed baseline has been applied to prod `edplucmvkcnokyygxqsb` and recorded in
      `supabase_migrations.schema_migrations`.
- [ ] `ALTER ROLE app_backend WITH LOGIN PASSWORD '<secret>'` has been run on prod.
- [ ] **Prod Supabase Auth is configured and delivering email** — Send Email Hook → Resend registered,
      redirect allowlist set, TOTP enabled. Without this Task 1 cannot complete (no confirmation email,
      no TOTP factor) and Tasks 2-3 have no `auth_user_id` to reference.
- [ ] Josh has confirmed the two-staff / two-availability-row decision above is still current.
- [ ] Josh is present for Task 1 (he and Tobias must each complete a real signup + TOTP enrolment).

**How to use:** open a **fresh Claude Code session in this repo on model Opus**, then paste everything
from `=== PROMPT START ===` to the end as your first message.

---

```
=== PROMPT START ===

Execute docs/deploy/PROMPT-execute-prod-seed-bootstrap.md — seed the bootstrap rows a fresh production
database needs. Read that file IN FULL first, plus docs/deploy/production-cutover.md Phase 1 and Phase 4.

## Standing decisions & discipline
- The WHAT is already decided (the table at the top of the prompt file). Your job is to execute it
  safely and verify it, NOT to re-litigate which rows to seed. If you believe a row is wrong, say so
  and WAIT — do not deviate unilaterally.
- THIS RUN WRITES TO PRODUCTION. Every prod-touching statement must be shown to Josh in full and
  explicitly approved BEFORE execution. No batching approvals: staff INSERT and availability INSERT are
  separate approvals.
- Dev (glncumufgaqcmqhzwrxm) is READ-ONLY in this run — you may SELECT from it to compare, never write.
- NEVER `git stash` / `git checkout <file>` / `git restore` / `git reset` (shared stash across live
  worktrees). Forbid `git stash` in every subagent prompt.
- Env changes are made by Josh in the Supabase / Laravel Cloud UI — you propose exact values, he
  applies, you verify. Never edit .env directly.
- Work on branch `chore/prod-seed-bootstrap` off origin/development. Commit only doc annotations.
  DO NOT push — Josh reviews and merges.
- Verify `git rev-parse --abbrev-ref HEAD` + `git diff --cached --stat` before every commit. Trailers:
  Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
  Claude-Session: <your session url>

## Task 0 — Preconditions (STOP if any fail)

Against prod `edplucmvkcnokyygxqsb`, assert and report:

1. The tables exist and are EMPTY:
   select 'partna_staff' t, count(*) from core.partna_staff
   union all select 'feature_availability', count(*) from core.feature_availability
   union all select 'user_segments', count(*) from core.user_segments
   union all select 'feature_flags', count(*) from core.feature_flags;
   Expect 0/0/0/0. ANY non-zero row = STOP and report — the baseline apply was not clean, or someone
   has already seeded.

2. **Role posture survived the collapse.** This is the one that silently breaks the whole task:
   select rolname, rolbypassrls, rolcanlogin from pg_roles
   where rolname in ('postgres','app_backend','service_role');
   REQUIRED: postgres.rolbypassrls = true, app_backend.rolbypassrls = true,
   app_backend.rolcanlogin = true. If postgres has LOST bypassrls, Task 2 CANNOT proceed —
   core.partna_staff is FORCE ROW LEVEL SECURITY and its only INSERT policy
   (partna_staff_insert_admin) requires an already-existing admin, i.e. an unbreakable chicken-and-egg.
   Do NOT work around this by disabling RLS or adding a policy. STOP and report; the fix belongs in the
   baseline's role/grant stitch.

3. RLS is on and forced where the baseline says it should be:
   select relname, relrowsecurity, relforcerowsecurity from pg_class
   where oid in ('core.partna_staff'::regclass, 'core.feature_availability'::regclass);
   Expect partna_staff = true/true. Report feature_availability as found (baseline enables RLS; it is
   not FORCEd) — a deviation is a finding, not a blocker for this task.

## Task 1 — Prod Auth prerequisites (Josh + Tobias, human-driven)

core.partna_staff.auth_user_id is `NOT NULL` + `UNIQUE` + FK to `auth.users(id) ON DELETE CASCADE`.
The prod auth pool is SEPARATE from dev — dev's UUIDs (367aeb35…, 00084601…, 95de58a5…) are worthless
on prod. Do NOT copy them.

1. In the prod Supabase project's Auth settings, confirm with Josh (he applies, you verify):
   - **MFA / TOTP is ENABLED.** Without it Task 5 cannot pass — every staff route is behind
     `require.aal2` (see docs/auth/mfa-foundation.md), so a staff auth user with no TOTP factor is
     locked out of the entire staff API even with a valid partna_staff row.
   - SMTP / Resend sender + verified domain, email templates, Site URL + redirect allowlist are set
     (these are the Phase-0 Auth-parity items; confirm, don't re-do).
2. Josh and Tobias each sign up on PROD (not dev) with their real addresses:
   - Josh Hunter — jhunter7333@gmail.com
   - Tobias — ceo@dolustech.net
   Each confirms their email, then enrols a TOTP factor and completes one AAL2 login.
3. Capture the two PROD auth UUIDs and prove the factors exist:
   select u.id, u.email, u.email_confirmed_at,
          (select count(*) from auth.mfa_factors f
            where f.user_id = u.id and f.status = 'verified') verified_factors
   from auth.users u
   where lower(u.email) in ('jhunter7333@gmail.com','ceo@dolustech.net');
   REQUIRED: exactly 2 rows, both email_confirmed_at NOT NULL, both verified_factors >= 1.
   Anything else = STOP.
4. Record the two UUIDs in your working notes. They are the ONLY parameters of Task 2.

## Task 2 — Seed core.partna_staff (2 rows)

Present this to Josh with the real UUIDs substituted, get explicit approval, then run it as `postgres`
against prod. Single statement, wrapped, idempotent on re-run:

  insert into core.partna_staff (auth_user_id, role, primary_email, name)
  values
    ('<PROD_AUTH_UUID_JOSH>',   'admin', 'jhunter7333@gmail.com', 'Josh Hunter'),
    ('<PROD_AUTH_UUID_TOBIAS>', 'admin', 'ceo@dolustech.net',     'Tobias')
  on conflict (auth_user_id) do nothing
  returning id, role, name, primary_email;

Notes you must respect:
- `role` is DELIBERATELY not $fillable on the model (SEC-1) — that is why this is raw SQL and not
  tinker `PartnaStaff::create()`. A tinker create() would silently produce role='support'.
- Both rows are `admin` — support-tier is not used at launch.
- primary_email carries a UNIQUE constraint ("partna_staff_Primary Email_key"); the on-conflict clause
  above only covers auth_user_id, so a duplicate email raises. That is correct — it should raise.
- The `prevent_staff_escalation` trigger is BEFORE UPDATE only; INSERT is unaffected.

Verify: 2 rows, both role='admin', both auth_user_id resolving to a real auth.users row:
  select s.name, s.role, s.primary_email, (select count(*) from auth.users u where u.id=s.auth_user_id) auth_row
  from core.partna_staff s order by s.name;

## Task 3 — Seed core.feature_availability (2 rows)

Runs AFTER Task 2 — created_by FKs to core.partna_staff(id).

  insert into core.feature_availability (feature_key, mode, segment_id, created_by)
  select k, 'disabled', null, (select id from core.partna_staff where primary_email = 'jhunter7333@gmail.com')
  from (values ('integration.strava'), ('integration.skool')) as v(k)
  on conflict do nothing
  returning feature_key, mode, segment_id, created_by;

Why these two and only these two — state this back to Josh before running so the semantics are on the
record: `App\Services\FeatureAvailability\FeatureAvailability` is **fail-OPEN**. Absence of a row means
the feature is AVAILABLE. So omitting these rows does not produce an error — it silently ships Strava
and Skool live on prod. That inversion is the entire reason this seed step exists. Conversely, seeding
NO fresha row is what makes Fresha available, which is the intent.

Verify: exactly 2 rows, both mode='disabled', both segment_id IS NULL, created_by NOT NULL.

## Task 4 — Assert the negatives (cheap, catches a wrong-schema apply)

Against prod, confirm the deliberately-empty set is still empty and report the counts verbatim:
core.feature_flags, core.feature_flag_overrides, core.user_segments, core.users, site.sites,
storage.buckets. All expected 0. A non-zero here means something other than this prompt wrote to prod.

## Task 5 — End-to-end verify (RUNS LATER, at Phase 4 — do not block on it)

SPLIT PHASE. Tasks 0-4 are Phase 1 (DB only, no app required). Task 5 hits api.partna.au, which does
NOT resolve to the prod environment until Phase 3 go-live. So on the Phase-1 run: attempt Task 5 only
if prod is already serving; otherwise mark it DEFERRED TO PHASE 4, report the seed as
"rows inserted, end-to-end unverified", and re-run this task as part of the Phase-4 launch-check gate.
Do NOT report the seed step as complete on the strength of the INSERTs alone.

1. Josh performs a real AAL2 login on prod and calls `GET /api/staff/me` against api.partna.au with the
   resulting token. REQUIRED: 200 with his staff identity. A 401 means the JWT/aal wiring; a 403 means
   the staff row or role. Diagnose from docs/auth/mfa-foundation-runbook.md — do not patch middleware.
2. `GET /api/staff/feature-availability` (staff, AAL2) returns the 2 disabled rows.
3. The user-facing integrations meta endpoint (`GET .../platforms/meta`, IntegrationsMetaController)
   reports strava + skool as unavailable and fresha as available, for a real prod user token.
   If there is no prod user yet, note this as deferred to the Phase-4 smoke rather than creating one.
4. Pull `cloud env:logs partna production --minutes 10` and confirm nothing threw during the above.

## Task 6 — Record and commit

1. Tick the Phase-0 "Decide reference/seed data" checkbox in docs/deploy/production-cutover.md with a
   dated one-paragraph note: what was seeded, what was deliberately not, and a pointer to this prompt.
2. Add a Phase-1 sub-step recording that the seed ran, with the two prod staff UUIDs REDACTED (do not
   commit auth UUIDs — they are credentials-adjacent identifiers; keep them in your session notes only).
3. Commit on `chore/prod-seed-bootstrap`. DO NOT push.

## Reporting

Finish with: preconditions result, rows actually inserted (count + keys, no UUIDs), Task 5 results
pass/fail per item, and anything you STOPPED on. If any task did not complete, say so plainly — do not
report the seed as done because the inserts succeeded but Task 5 was skipped.

=== PROMPT END ===
```
