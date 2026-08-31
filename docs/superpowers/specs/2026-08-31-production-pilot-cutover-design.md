# Production pilot cutover — design

**Written:** 2026-08-31
**Status:** design, not executed
**Supersedes for this event:** `docs/superpowers/plans/2026-08-17-prod-schema-reconciliation.md` (that
plan's §3 order of work is adopted here; its "prod DB access unconfirmed" blocker is **closed** — see §3.4)
**Companion tick-list:** `docs/deploy/prod-cutover-change-checklist.md` — still largely valid, with four
corrections recorded in §8.

---

## 1. What this is

Bring production (`edplucmvkcnokyygxqsb` + the `production` Laravel Cloud env) from its current
2026-08-03 state to parity with `development`, so the pilot can run on it.

Two rails, deployed separately and in this order: **schema first, then code.** Nothing about the git
push touches the database — the prod env's `deployCommand` is `# php artisan migrate --force`,
commented out deliberately.

**Not in scope**, tracked separately in §7: the shared `SUBDOMAIN_KV` namespace, the shared R2 media
bucket, and the pilot's queue-throughput sizing.

---

## 2. Verified state, 2026-08-31

Every figure below was read from the live systems on 2026-08-31. **Timestamp any re-reading**; the
convergence programme watched coverage figures go stale mid-verification four times.

### 2.1 Database

| | Dev `glncumufgaqcmqhzwrxm` | Prod `edplucmvkcnokyygxqsb` |
|---|---|---|
| Ledger rows | 155 (max `20260831120000`) | **4** (max `20260803100001`) |
| Schemas present | all 11 | **7** — no `catalog`, `content`, `ingest`, `routing` |
| Base tables | 126 | 66 |
| `core.users` | 217 | **0** |
| `site.sites` | — | **0** |
| `site.platform_connections` | — | 0 |
| `core.pre_account_builds` | — | 0 |
| `core.partna_staff` | — | **1** |
| `core.feature_availability` | — | **2** |
| `auth.users` | — | **1** |

`supabase/migrations/` holds exactly 155 files, matching dev's ledger 1:1. **Dev is clean** — files and
ledger agree, which is what makes the file set a trustworthy artefact.

### 2.2 The gap is not a clean prefix

Prod's four ledger rows:

```
20260726000000  baseline_pilot
20260726101212  pgrst_empty_exposed_schema     <-- MIS-STAMPED
20260803100000  users_handle_lc_matches_handle_not_valid
20260803100001  users_handle_lc_matches_handle_validate
```

Two defects, and together they rule out the cheap fix:

1. **Mis-stamp.** The file — and dev — call that second migration `20260726200000`. Prod recorded
   `20260726101212`. Same content, different version. A `supabase db push` would see `20260726200000`
   as unapplied and re-run it.
2. **Out of order.** Prod holds `20260803100000`/`100001` but is missing the ~62 migrations sorting
   *before* them — the whole `catalog` / `routing` / `ingest` / `content` schema-creation block from
   2026-07-27 to 2026-07-31.

### 2.3 Application / infrastructure

- **The prod Laravel Cloud env is `stopped`.** Not hibernating — stopped. `usesOctane: false`,
  `usesHibernation: false`, two instances configured.
- `usesPushToDeploy: true`, `branch: production`. The push **is** the deploy; no promote, no approval,
  and no CI gate on that branch.
- `git rev-list --left-right --count origin/production...origin/development` → **`0  2243`**. The
  invariant holds: nothing on prod that never went through CI, and a fast-forward is clean.
- Prod runtime env: `APP_ENV=production`, `APP_URL=https://api.partna.au`,
  **`QUEUE_CONNECTION=redis`**, `SESSION_DRIVER=redis`, `REDIS_CLIENT=phpredis`,
  `PARTNA_PUBLIC_DOMAIN=partna.au`.
- **`SUPABASE_DB_URL` is set on the prod env** (111 chars). This closes the reconciliation plan's
  stop-the-world blocker; the connection string is recoverable via
  `cloud environment:get production --json --show-sensitive`.
- Env-var parity is good: **6** keys on dev but not prod
  (`PARTNA_CACHE_PUBLIC_SWR`, `PARTNA_CACHE_SLO_MIN_HIT_RATE`, `PARTNA_CACHE_SLO_MIN_SAMPLE`,
  `PARTNA_INGEST_BILLED_EFFECTS_ENABLED`, `PARTNA_PRE_ACCOUNT_MAX_UNCLAIMED_PER_IP`,
  `YOUTUBE_DATA_API_KEY`). Five are tuning knobs with config defaults; only `YOUTUBE_DATA_API_KEY` is
  functional.
- `bootstrap/catalog/compiled.php` (503 KB) is **tracked in git**, so the compiled catalog artefact
  ships with the code push. Prod needs `catalog:sync` only — never `catalog:compile`. `catalog:sync`
  is also scheduled hourly in `routes/console.php`, so it self-heals within the hour.
- Supabase org is on the **Pro** plan since 2026-08-14 — daily managed backups exist.

### 2.4 The `CONCURRENTLY` pipeline hazard is clear

26 of the 155 files contain an executable `CREATE/DROP INDEX CONCURRENTLY`. **All 26 are
single-statement**, so the one-`CONCURRENTLY`-per-file convention held (`CONVENTIONS.md` §1). Files
that mention `CONCURRENTLY` in multi-statement bodies mention it only in comments — verified by
stripping `--` comments before counting. Both `psql -f` and `supabase db push` are mechanically viable;
this is not what decides the strategy.

---

## 3. Decision: wipe and replay the existing 155 files

### 3.1 The decision

Drop prod's application schemas, then apply `supabase/migrations/*.sql` from zero in filename order via
`psql -f`, stamping each into `supabase_migrations.schema_migrations`.

**No new baseline file. No repo churn. The 155 files stay exactly as they are.**

### 3.2 Why

- **Prod holds nothing to protect.** Zero users, zero sites, three seed rows. Every strategy that would
  be reckless against real data is nearly free today — and stops being free the day the pilot starts.
  This window closes.
- **The 155 files are a proven artefact.** Dev's ledger is living proof they apply in order.
- **It ends the divergence permanently.** Prod's ledger becomes 155 rows identical to dev's, so
  `supabase db push` works normally on prod from that day forward. The mis-stamp and the out-of-order
  block both cease to exist rather than being papered over.
- It is what `2026-08-17-prod-schema-reconciliation.md` §3 step 4 already concluded, and what
  CLAUDE.md documents under "Fresh prod cutover".

### 3.3 Alternatives rejected

**Incremental `supabase db push --include-all`.** Mechanically viable (§2.4), but requires hand-repairing
the mis-stamped row first, then replaying 151 files onto an out-of-order base. The backfills and
`NOT VALID`→`VALIDATE` pairs are all no-ops on empty tables, so the *execution* risk is lower than it
looks — but the *verification* story is bad. After 151 steps you cannot cheaply prove prod == dev, and
schema rollback does not exist.

**Collapse to a new baseline (repeat of 2026-07-26).** Rejected. It would swap a proven artefact for an
unverified `pg_dump`, force a re-stamp of **dev's** ledger too (or leave the two permanently
disagreeing), and re-open the three traps in `reference_prod_rebaseline_gotchas`. The only thing it buys
— a one-file apply — is worthless when there is no data to preserve.

### 3.4 The traps this strategy must respect

From the 2026-07-26 run against this same project:

1. **Role attributes lie.** Roles are cluster-level, so `app_backend` survives `DROP SCHEMA … CASCADE`.
   The baseline's `IF NOT EXISTS … CREATE ROLE app_backend NOLOGIN` branch is therefore **skipped**, and
   the role keeps its old password and LOGIN flag. `rolcanlogin/rolbypassrls = t/t` is **not** proof the
   bootstrap ran. The only proof is connecting **as** `app_backend`.
2. **`DROP SCHEMA public CASCADE` loses `supabase_admin`-owned default ACLs permanently** — and
   restoring them fails with `42501`. Prod already paid this cost in July (32 rows vs dev's 35); no
   *new* loss occurs. `app_backend` appears in none of the affected rows. **Accept and move on; do not
   chase it.**
3. **Apply over the session-mode pooler (5432), never transaction mode (6543).** The baseline sets
   `search_path = ''` once at the top and every later identifier relies on it; transaction mode
   multiplexes across backends so the `SET` does not stick. `psql` is keg-only at
   `/opt/homebrew/opt/libpq/bin/psql`.

Additionally: **never `supabase migration repair --status reverted`.** Ledger drift is fixed by
`UPDATE`-ing the version to the filename's, or by writing the missing file. Not applicable to the
wipe path, but recorded so a mid-run improvisation does not reach for it.

---

## 4. The gate that must pass before anything touches prod

**Rehearse the from-zero apply locally.** `scripts/db/fresh-reset.sh` provisions the base and applies
every migration through a `psql` simple-query loop — the same mechanism and the same ordering the prod
run will use.

This rehearsal is **not optional**. It is the only thing standing between "155 files that applied
incrementally on dev over five weeks" and "155 files that apply from zero in one pass". Those are
different claims, and only the second one matters here.

**Exit criteria:** clean run to completion, 155 ledger rows locally, no invalid indexes.

An irreversible teardown must not be the operation that discovers a migration gap.

---

## 5. Runbook

Ordered. Each phase has an explicit verification; a failed verification stops the run.

### Phase 0 — Pre-flight

- [ ] `git fetch origin && git rev-list --left-right --count origin/production...origin/development`
      → left **must** be `0`.
- [ ] CI green on `development`'s tip SHA. At time of writing the tip `a7dfdfeba` was **in progress**;
      the `production` branch is protected with 9 required checks bound to the SHA, so the
      fast-forward is refused until they pass. Wait, do not force.
- [ ] The §4 rehearsal gate has passed — local from-zero apply is green. (Not to be confused with
      Phase 4 below, which is the code deploy.)
- [ ] Recover the prod connection string from `SUPABASE_DB_URL`; confirm it is the **session** pooler
      on port 5432.

### Phase 1 — Backup and prove access

- [ ] Take a backup even though prod holds 3 rows: `scripts/db/backup-to-r2.sh` (reaches
      `partna-db-backups` via the existing wrangler OAuth session, driven through `npx --yes wrangler@4`).
      Pro-plan daily managed backups are the second line.
- [ ] Prove admin access with a read: `SELECT count(*) FROM core.users;` → expect `0`.
- [ ] Record the pre-wipe seed rows verbatim so they can be restored byte-for-byte:
      `core.partna_staff` (1 row) and `core.feature_availability` (2 rows).

### Phase 2 — Wipe and apply from zero

- [ ] Drop the application schemas **only**: `public`, `core`, `site`, `notifications`, `analytics`,
      `audit`, `moderation`. Leave every Supabase-managed schema alone — `auth`, `storage`,
      `extensions`, `vault`, `graphql`, `realtime`. Extensions are not at risk: Supabase installs them
      into `extensions`/`vault`, not `public`.
- [ ] **`auth.users` must survive.** `core.partna_staff.auth_user_id` is `NOT NULL` with an FK to it;
      Josh's prod auth user is what makes the staff row re-seedable. Dropping `core` does not touch it.
- [ ] Clear the ledger: `DELETE FROM supabase_migrations.schema_migrations;` — delete the rows, do not
      drop the schema.
- [ ] Apply `supabase/migrations/*.sql` in filename order via `psql -f`, **simple protocol, and NOT
      `--single-transaction`** (the `CONCURRENTLY` statements cannot run inside a transaction).
- [ ] Record each applied file in `supabase_migrations.schema_migrations` with the version taken from
      the **filename**.

**Verify:** ledger = 155 rows, max `20260831120000`; 11 schemas; 126 base tables; 0 invalid indexes.
Compare table and index counts against dev, per schema.

### Phase 3 — Finish the database

- [ ] `ALTER ROLE app_backend WITH LOGIN PASSWORD '<prod-secret>';` — matching `DB_PASSWORD` on the
      prod env.
- [ ] Assert `rolcanlogin`/`rolbypassrls` = `t`/`t`, **then prove it properly** by connecting as
      `app_backend` and running `SELECT current_user, count(*) FROM core.users;` (trap 1, §3.4).
      `BYPASSRLS` is load-bearing — FORCE-RLS tables have no `app_backend` policy, so without it the
      app is default-denied at runtime.
- [ ] Verify the grant matrix against dev. Technique: md5 over `schema.table=privs` from
      `role_table_grants`. Specifically confirm `audit` carries SELECT/INSERT **plus** UPDATE on
      `audit.data_export_audit` **plus** EXECUTE on the three SECURITY DEFINER prune functions — the
      "SELECT/INSERT only" phrasing in older docs is stale and would leave three scheduled jobs
      permission-denied.
- [ ] Re-seed the 3 rows recorded in Phase 1.
- [ ] Tobias's staff row stays outstanding — physically un-seedable until he has a prod auth user.

### Phase 4 — Deploy the code

- [ ] `git push origin development:production` — 2243 commits, clean fast-forward.
- [ ] Watch the build. Note the env is `stopped`; confirm whether push-to-deploy builds a stopped env
      or defers until start (**open question, §9**).

### Phase 5 — Post-schema application steps

- [ ] `php artisan catalog:sync` via `cloud command:run`. Prod has never had a `catalog` schema; until
      this runs, `LinkProjector` resolves against an empty rulepack and every pasted link goes unrouted.
      The hourly schedule would converge on its own, but do not launch a pilot waiting on it.
- [ ] Set `YOUTUBE_DATA_API_KEY` on prod, or accept that YouTube ingest is dark. Decide the other five
      dev-only keys explicitly rather than by omission.

### Phase 6 — Supabase dashboard (carried by neither SQL nor env vars)

These may already be set from 2026-07-26. **Verify each; do not assume.**

- [ ] Send Email Hook → `https://api.partna.au/api/internal/email-hooks/supabase`, secret =
      `SUPABASE_EMAIL_HOOK_SECRET`. Highest-risk email trap of the cutover: without it, auth
      OTP/magic-link/invite mail silently falls back to Supabase's `*.supabase.co` sender, wrong
      branding, straight to spam, bypassing the Resend/DKIM pipeline.
- [ ] MFA Verification Hook → `https://api.partna.au/api/webhooks/supabase/auth/mfa-verification`,
      secret = `SUPABASE_AUTH_HOOK_SECRET`.
- [ ] Site URL = `https://app.partna.au`.
- [ ] Redirect URLs — tight list, `https://app.partna.au/auth/callback` only. No `localhost`, no
      `*.vercel.app` (open-redirect surface).
- [ ] TOTP enabled — without it staff can never reach `aal2` and every staff endpoint 401s.
- [ ] Email OTP length 6, expiry ≤ 3600 s. SMS/phone MFA disabled.

### Phase 7 — Go live

**Starting the stopped env is the go-live moment.** See §6.

- [ ] Start the prod env.
- [ ] Verify: `/api/health` (liveness, not readiness — it does not prove the DB is reachable, so do not
      read a 200 as "everything works").
- [ ] Exercise a real read path end to end.
- [ ] `POST /api/claim` — currently raises `42703` on prod because `published_by_claim` is missing.
      Applying the migrations fixes it; confirm rather than assume.
- [ ] Nightwatch prod: watch for exceptions in the first minutes.

---

## 6. The stopped env is your valve

`docs/deploy/prod-cutover-change-checklist.md` opens with "**Go-live has no DNS valve** — `api.partna.au`
already CNAMEs to the prod Laravel env, so the moment you push `production` the domain serves prod live."

**That is no longer the whole picture.** The env is `stopped`. Schema and code can both be landed and
verified while it stays stopped, and **starting it** becomes the atomic go-live step — a valve the
July cutover did not have.

This is worth protecting deliberately: do not start the env early for convenience, because doing so
forfeits the only rollback-shaped control in the sequence.

---

## 7. Deliberately out of scope — tracked, not solved here

Three items are real pilot risks that this cutover does **not** address. Each deserves its own decision.

1. **`SUBDOMAIN_KV` is shared between prod and dev.** `cloudflare-worker/wrangler.toml` declares a
   single namespace (`ce726607804d41a296d6da150b0c537f`) with **no environment override** — verified,
   not assumed. `SyncSubdomainToKvJob` is the only KV writer, and it runs in both environments. With
   real pilot users on prod, a dev write can clobber prod routing for a live handle. **This is the item
   I would resolve before real traffic.** The file's own EDGE-10 comment already states the principle
   ("staging must never share the production `SUBDOMAIN_KV`"); the same argument applies to dev.
2. **The R2 media bucket is shared prod/dev.** Same class of problem, lower blast radius.
3. **Queue sizing.** `QUEUE_CONNECTION` on prod is already `redis` — the checklist's 2026-07-22
   "launch on `sync`, flip later" decision has been superseded in the env and the checklist is stale
   here (§8). What is *unverified* is whether Horizon workers are actually processing on prod. Confirm
   before the pilot; a Redis queue with no worker is strictly worse than `sync`, because jobs
   accumulate silently instead of running.

---

## 8. Corrections to existing docs

Four statements in the current docs are stale and would mislead whoever executes this:

1. `docs/deploy/routine-deploy.md` — "Supabase org is on the **Free** plan: no PITR, no managed
   backups… RPO is ~7 days." **Wrong since 2026-08-14**; the org is on Pro with daily managed backups.
   That line is presented as the fact driving "every judgement call below", so it matters.
2. `prod-cutover-change-checklist.md` §F — "prod launches on `QUEUE_CONNECTION=sync`". Prod is on
   `redis` today.
3. `2026-08-17-prod-schema-reconciliation.md` §1 — "prod DB access was unconfirmed… the real password's
   location is not documented anywhere". `SUPABASE_DB_URL` is set on the prod Cloud env. **Blocker
   closed.**
4. The same plan's §3 step 5 sequences a prod *teardown* of the legacy menu/services/shop tables after
   re-homing. Under wipe-and-replay that step **disappears entirely** — prod never acquires those
   tables in a state needing teardown, because the drop migrations replay in their historical position.
   The plan's §5 "what prod must NOT inherit" concern is satisfied structurally rather than
   procedurally.

---

## 9. Open questions

1. **Does push-to-deploy build a stopped Laravel Cloud env, or defer until start?** Determines whether
   Phase 4 and Phase 7 are separable as written. If it defers, the ordering still holds but the
   verification in Phase 4 moves to Phase 7.
2. **Are the Phase 6 Supabase dashboard settings still in place from 2026-07-26?** They survive a DB
   wipe (they are project config, not schema), but they have not been verified since. Cheap to check,
   expensive to get wrong — the email hook especially.
3. **Are Horizon workers running on prod?** Two instances are configured; their roles were not
   resolvable from `cloud environment:get`. See §7.3.
4. **Do the five non-functional dev-only env keys want prod values?** Defaulting is defensible; doing
   it by omission is not.
