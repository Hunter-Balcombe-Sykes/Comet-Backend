# Production Cutover Runbook

**Purpose:** stand up a real, isolated `production` environment (branch + Laravel Cloud env + Supabase
project + edge) separate from `development`, for the pilot and beyond.

**Gate:** do NOT run this until (a) the product is pilot-ready and (b) all P0/P1 audit findings are
resolved. Promoting `production` ships the entire dev divergence + every migration at once — it is a
one-shot, mostly-irreversible event, not a routine deploy.

> **Status: NOT YET EXECUTED.** This is the forward plan. Re-verify every "current state" fact below on
> the day — the environment drifts. The facts below were **re-verified live 2026-07-21** (Supabase project
> status, DNS/CNAMEs, prod deploy state, git divergence). Note the DB is applied with a **`psql` loop, not
> `supabase db push`** — see Phase 1 for why.

---

## Current state (verified 2026-07-21)

Everything production already *exists*; it is stale and asleep, not missing. Cutover = wake the stopped
Laravel env + re-baseline the DB + re-key secrets — **not** build-from-scratch, and **not** a DNS repoint
(api.partna.au already points at the prod env; see the DNS row).

| Piece | State | Implication |
|-------|-------|-------------|
| `production` git branch | Exists, is the **default branch**. **0 ahead / 1,518 behind** `development` (2026-07-21). | Fast-forward — no merge conflicts. But one push ships 1,518 commits + all migrations. |
| Laravel Cloud **production** env | Exists. Last deploy 2026-05-21 on a **pre-standalone** commit (`fa69c2b1`), hibernating (returns 404). Build command already = `ffmpeg.sh + composer --no-dev + optimize` (no npm), **PHP 8.4**. | Would run the *old* app if woken as-is. The Phase 3 branch push redeploys it — and because api.partna.au already CNAMEs here, **that deploy is the public go-live** (no DNS valve). |
| Supabase **Partna Development** (`glncumufgaqcmqhzwrxm`) | `ACTIVE_HEALTHY`, PG 17.6.1.104, ap-southeast-2. | The live DB for everything today (incl. prod sitepages). Source of truth for the prod baseline. |
| Supabase **Partna Production** (`edplucmvkcnokyygxqsb`) | **`ACTIVE_HEALTHY`** (not paused), PG 17.6.1.121, ap-southeast-2 (2026-07-21). | Active but **on the old pre-standalone schema** (~`20260512145025`); current migrations un-applied. **No "restore" needed** — but must be wiped and re-baselined (reuse this ref, reset in place). |
| DNS | `api.partna.au` → **prod** env vanity (`partna-production-uovh3z.laravel.cloud`), currently 404 (prod stopped). `dev-api.partna.au` → dev vanity (200). `app.partna.au` → **Vercel** (the dashboard). | **No DNS repoint at go-live** — api.partna.au already targets prod. Go-live = waking/deploying prod (Phase 3). The dashboard is a Vercel deploy whose API base is frontend config, not DNS. |

The three genuinely risky steps: **(1)** the prod DB re-baseline (irreversible data event), **(2)** the prod
secret set (Redis/queue, KV namespace, JWT, mail, media), **(3)** the prod **deploy/wake** — the visible
go-live, because api.partna.au already routes to the prod env, so the moment prod deploys it serves the live
domain (there is **no** separate DNS valve to stage behind). Everything else is copy-config.

---

## Phase 0 — Pre-cutover prep (do these *before* cutover day, unhurried)

- [ ] **All P0/P1 audit findings resolved**, including any that carry Supabase migrations (they must land
      on dev first so the schema is final before we snapshot it).
- [x] **Land the deferred Gate-A P2 schema on dev** — P2 findings that carry migrations, so they must be on
      the live dev DB before the snapshot (the P0/P1 line above does **not** cover them). **DONE 2026-07-22
      — both applied + ledger-aligned.** Status:
      - **B8 (`models-data/PRIV-2`+`PRIV-3`, audit-retention prune) — ✅ APPLIED.**
        `audit.prune_user_deletion_audit()` + `audit.prune_data_export_audit()` applied to dev via MCP
        `apply_migration` (repo file `20260722010000_audit_pii_snapshot_retention_prune.sql`); verified live
        (SECURITY DEFINER, pinned `search_path`, `app_backend` EXECUTE only). Already in the schema to be
        snapshotted — nothing to do at cutover.
      - **B20 (11 migrations `20260721010000…040700`) — ✅ APPLIED 2026-07-22.** RLS enable+force+policies
        on `site.workplaces`/`content_selection` (verified `true/true` + 3 policies each; `app_backend` is
        `BYPASSRLS` so reads are unaffected — same live pattern as `site.design_kit_contributions`),
        menu-platform UUID defaults, `design_kits` backfill (0 rows — already complete), `pg_trgm` + 7
        trigram GIN indexes. Applied surgically to dev via `execute_sql` (indexes as plain `CREATE INDEX` —
        identical objects, sidesteps the `CONCURRENTLY`-in-transaction 25001), recorded in the ledger under
        the **exact repo versions** (aligned — no cutover reconciliation needed). Already in the schema to
        be snapshotted — nothing to do at cutover.
- [ ] **Reconcile dev migration drift** — the **non-negotiable prerequisite** for a trustworthy prod DB
      (you chose to **snapshot dev into a fresh baseline**, so dev must be a known, reproducible state).
      The dev DB has changes applied out-of-band that aren't in the repo (and repo files not applied to
      dev). **A 2026-07-21 audit already catalogued this drift**: 73 repo-only + 55 dev-only versions, of
      which only 10 are genuinely missing and all additive (RLS defense-in-depth, pg_trgm perf indexes,
      likely-unused defaults) — reviewed and judged skip-safe. Start from that catalog rather than
      rediscovering it. ✅ **The deferred Gate-A P2 schema (B8 + B20's 11) is now applied to dev and
      recorded under its exact repo versions** (2026-07-22, checkbox above) — so it needs no reconciliation
      here; it's already part of the verified dev schema. **Full step-by-step below → see "Drift
      reconciliation (detailed steps)".**
- [ ] **Collapse the migration history into a fresh baseline** (see "Migration collapse" below). Snapshot
      the *verified dev schema*, archive the incrementals, verify parity with a schema diff **plus the
      grant/role-posture assertions** (a schema diff cannot see grants or role attributes) — **and prove
      the baseline applies from an empty DB via `scripts/db/fresh-reset.sh`** (the CONCURRENTLY-safe psql
      applier; see Phase 1 for why `db push` can't do a from-zero apply). **Execute via the plan**
      `docs/superpowers/plans/2026-07-22-reconcile-and-collapse-baseline.md` (Tasks 0–10; bootstrap:
      `PROMPT-execute-reconcile-and-collapse-baseline.md`) — it carries the 2026-07-22 review corrections
      (BYPASSRLS stitch, `db diff --from/--to` fallback, grant-matrix parity, `pg_trgm` stitch).
- [ ] **Merge the collapse branch to `development`** after Josh's review — Phase 1 applies the baseline
      from the deployed branch's `supabase/migrations/`, so the unpushed prep branch must land first.
- [ ] **Env-var parity audit.** Diff the dev Laravel Cloud env's keys against `.env.example` and build the
      complete prod secret set (see Phase 2). Every key dev has, prod needs — with prod values.
- [ ] **Decide reference/seed data** a fresh prod needs: platform config, feature flags, any bootstrap rows.
- [x] **Decide the fate of currently-live dev-served sitepages.** Prod starts EMPTY: at go-live the Worker
      flips to the prod KV and api.partna.au to the prod DB, so every `<handle>.partna.au` page published
      from dev — including pre-account builds with claim invites in flight — 404s unless migrated. Count
      them first (published sites + live builds + `invited_at IS NOT NULL` on dev), then decide: migrate
      those users/builds to prod, or accept the breakage and pause outreach around cutover. Record the
      decision here.
      - **Inventory (dev `glncumufgaqcmqhzwrxm`, read-only, 2026-07-22):** 19 published sites (`is_published=true`)
        — **all owned by `active` users, 0 by `unclaimed`**; **1 has a custom domain (`tuesdae.co`)**. 4 live
        pre-account builds (`claimed_at IS NULL`), all `build_state=ready`. **Invites in flight
        (`invited_at IS NOT NULL AND claimed_at IS NULL`) = 0.** 25 users total (21 active + 4 unclaimed).
      - **Decision (Josh, 2026-07-22): _accept the breakage + pause outreach around cutover._** The 19 are
        founder/test accounts (pre-beta, no customers) and **0 invites are in flight**, so cutover strands
        nobody mid-claim; migrating 19+4 rows is not worth a planned migration task. **Carve-out:** eyeball
        `tuesdae.co` before cutover — if it is a real user, hand-recreate that one page on prod post-go-live.
        The 4 `ready` builds are Apify-billed scrape work that would need re-scraping if ever rebuilt on prod.
- [~] **Rehearse the queue flip on dev first.** Prod has never run async workers; go-live must not be the
      first-ever `QUEUE_CONNECTION=redis` + Horizon boot. Flip dev to redis + Horizon and soak before
      cutover day — `docs/deploy/queue-worker-cutover.md` inventories exactly what changes behavior under
      workers (e.g. `->delay()` becomes real, sync-masked races shift).
      - **Decision (Josh, 2026-07-22): NOT NEEDED before cutover — workers get turned on AFTER cutover, not
        at go-live.** Prod therefore launches on `QUEUE_CONNECTION=sync` (the same known-good mode dev runs
        today, jobs inline), and the worker flip becomes a **separate post-cutover step** that no longer
        coincides with go-live — so a pre-cutover dev rehearsal is unnecessary. The `queue-worker-cutover.md`
        rehearsal/soak (verify `config=redis`, Horizon masters up, probe job drains through Redis; watch the
        `analytics`/`images`/`videos` day-one queues and the `->delay()` staggers becoming real) moves to
        **that later flip**, along with its open blockers **B1** (provision + run a Horizon worker — env var
        alone without a running worker is strictly worse: silent backlog) and **B2** (lock down `/horizon`
        with dashboard creds; it exposes live job payloads). See the Phase-2 queue checkbox note below.
- [ ] **Confirm the prod Laravel deploy command** is current. The last prod build (`fa69c2b1`) **already**
      uses `ffmpeg.sh` + `composer install --no-dev` + `php artisan optimize` (no npm) — just confirm it's
      unchanged and still **without** an auto `migrate --force` (schema is Supabase-side). Check the PHP
      version matches intended (the last prod build ran 8.4; the project targets 8.2).

---

## Phase 1 — Production database (`edplucmvkcnokyygxqsb`)

The one irreversible step. Pre-beta = no customer data, so prod starts clean. **Decision: reuse this ref,
reset in place** (keeps `DB_USERNAME=app_backend.edplucmvkcnokyygxqsb` and the existing connection strings).

- [ ] **No restore needed** — the project is already `ACTIVE_HEALTHY` (verified 2026-07-21). It just holds
      the stale pre-standalone schema.
- [ ] **Final archive dump of the old prod DB — BEFORE any wipe.** Prod was live until 2026-05-21 and may
      hold real signup/waitlist PII (verify with row counts first). One `pg_dump` (schema + data, all
      non-managed schemas) to the R2 backup bucket is free insurance for an irreversible step.
      - **Row census (prod `edplucmvkcnokyygxqsb`, read-only `count(*)` per table, 2026-07-22):** the old
        pre-standalone app schemas are **empty of user data**. Only non-zero tables are **`billing.plans` = 5**
        and **`site.themes` = 3** — both reference/seed rows, both dropped by the wipe anyway. **0 rows** in
        every PII-shaped table: `core.professionals`, `core.customers`, `core.waitlist_signups`,
        `core.gdpr_requests`, `site.enquiries`, all `brand.*`/`commerce.*`/`analytics.*`, `notifications.*`.
        → **No real signup/waitlist PII exists.** The archive dump is optional (a snapshot of 8 seed rows),
        not the "free insurance for real PII" the step assumed. Re-verify on the day — the env can drift.
- [ ] **Purge stale Supabase Auth users.** The wipe below leaves the Supabase-managed `auth` schema, so
      pre-standalone accounts in `auth.users` survive into the new prod — colliding with fresh signups and
      the claim/OTP flow. Count them, then delete (Dashboard or admin API). If keeping any, write down why.
      - **`SELECT count(*) FROM auth.users` = 0 (0 with email; no earliest/latest) as of 2026-07-22.**
        → **Verified no-op — nothing to purge.** No pre-standalone accounts to collide with fresh signups
        or the claim/OTP flow. Re-verify on the day before relying on this.
- [ ] **Wipe to a clean slate** — run as the `postgres` admin connection, NOT `app_backend`; leave
      Supabase-managed `auth`/`storage` alone:
      ```sql
      DROP SCHEMA IF EXISTS core, site, notifications, analytics, audit, moderation CASCADE;
      -- public: drop AND recreate — never leave it dropped (default grants + some extension
      -- objects live here; check `\dx` / objects in public first and recreate what the platform needs).
      DROP SCHEMA IF EXISTS public CASCADE;
      CREATE SCHEMA public;
      GRANT USAGE ON SCHEMA public TO postgres, anon, authenticated, service_role;
      -- Stale ledger rows do NOT go away with the schemas:
      TRUNCATE supabase_migrations.schema_migrations;
      ```
      Then confirm `supabase_migrations.schema_migrations` is empty before applying the baseline.
- [ ] **Apply the collapsed baseline via `psql`, gated — NOT `db push`.** `supabase db reset`/`db push`
      pipeline any multi-statement file and **abort on `CREATE/DROP INDEX CONCURRENTLY`** (`SQLSTATE
      25001`); a from-zero apply of the raw migrations hits the 11 grandfathered CONCURRENTLY bundles and
      fails. (A single `db dump` baseline is CONCURRENTLY-free so it *might* push, but the sanctioned prod
      mechanism — CLAUDE.md → "Push to Supabase / Fresh prod DB"; `CONVENTIONS.md §1` — is psql either way.)
      ```bash
      # Review first: read the baseline in full, or apply to a scratch DB via fresh-reset.sh + `db diff`.
      # Then, against the prod DB URL (simple protocol — NOT --single-transaction), in filename order:
      psql "$PROD_DB_URL" -f supabase/migrations/<baseline>.sql
      # Record each applied file's version in supabase_migrations.schema_migrations so a future
      # incremental `db push` treats it as applied (see the fresh-prod procedure for the INSERT shape).
      ```
      Always dry-review and confirm before running.
- [ ] **Bootstrap the login role.** The baseline creates `app_backend` as `NOLOGIN` (fail-closed). In the
      SQL editor: `ALTER ROLE app_backend WITH LOGIN PASSWORD '<from-secret>';` — the app cannot connect
      until this runs. Then assert BOTH attributes: `SELECT rolcanlogin, rolbypassrls FROM pg_roles WHERE
      rolname = 'app_backend';` → `t / t`. **BYPASSRLS is load-bearing** — several FORCE-RLS tables have
      no `app_backend` policy, so without it the app is default-denied at runtime; it is cluster-level
      state the baseline's stitched bootstrap must set (a `db dump` never emits role attributes).
- [ ] **Verify grants**: `app_backend` has the intended per-schema grants (esp. `audit` = SELECT/INSERT
      only, `moderation` grants present). RLS policies applied. Functions have pinned `search_path`.
      Diff the full grant matrix against dev using the collapse plan's Task-8 queries
      (`role_table_grants` + `pg_default_acl`) — the parity schema diff does NOT cover grants.
- [ ] **Seed** reference/bootstrap data from Phase 0.
- [ ] **Re-register the Supabase Auth hooks on the prod project** (Dashboard → Authentication → Hooks). A
      fresh / re-baselined prod Supabase project has **no hooks configured**, so it silently falls back to
      Supabase's *built-in* email sender — auth OTPs / magic-links / invites would then arrive from a
      `*.supabase.co` domain (wrong branding, cold/unwarmed reputation → spam) and bypass the WHK-5
      stable-Message-ID dedup in `BaseTransactionalMail`. This is the highest-risk email trap of the whole
      cutover: the funnel keeps "working" in tests while OTPs quietly land in spam. Register **both**:
      - **Send Email Hook** → `https://api.partna.au/api/internal/email-hooks/supabase`, secret =
        the prod `SUPABASE_EMAIL_HOOK_SECRET` (format `v1,whsec_<base64>`). This is what keeps every auth
        email on the Resend / `partna.au` DKIM pipeline instead of Supabase's sender.
      - **MFA Verification Hook** → `https://api.partna.au/api/webhooks/supabase/auth/mfa-verification`,
        secret = the prod auth-hook secret.
      The secret on each side (Supabase Dashboard ↔ prod Laravel env) **must match**, or the signature
      middleware returns 401/503 and the path fails closed. Verify with a real send in Phase 4.
- [ ] **Auth project-config parity.** The hooks above are only part of the Auth surface: audit the prod
      project's Auth settings against dev — Site URL, redirect-URL allowlist, OTP expiry/length, email
      rate limits, MFA/TOTP settings (staff AAL2 depends on them). None of this lives in the DB dump or
      the env-var set; prod currently holds stale pre-standalone values.

---

## Phase 2 — Production Laravel Cloud env

Wake the stopped env and set a **complete, separate** secret set. The keys that silently break prod if
missed:

- [ ] **DB** → prod Supabase. `DB_USERNAME=app_backend.edplucmvkcnokyygxqsb` (Supavisor tenant prefix),
      port **5432** (session mode), prod password from the ALTER ROLE step.
- [ ] **`QUEUE_CONNECTION=redis` + real Horizon masters running.** Dev runs `queue=sync` with 0 Horizon
      masters (every job inline) — **prod must not inherit that.** Confirm Horizon supervisors are
      configured and running, else jobs like `SyncSubdomainToKvJob` run synchronously on the request.
      This flip must already be rehearsed on dev (Phase 0) — `docs/deploy/queue-worker-cutover.md`
      inventories what changes behavior under workers.
      - **SEQUENCING CHANGE (Josh, 2026-07-22): workers are turned on AFTER cutover, not at go-live.** So at
        go-live prod deliberately launches on **`QUEUE_CONNECTION=sync`** (jobs inline, same as dev today) —
        acceptable because jobs like `SyncSubdomainToKvJob` still run, just synchronously on the request.
        This checkbox becomes a **post-cutover** step: provision the Horizon worker (B1), set
        `QUEUE_CONNECTION=redis` + `HORIZON_DASHBOARD_USERNAME`/`PASSWORD` (B2), then run the
        `queue-worker-cutover.md` soak once prod is stable. The Phase-0 rehearsal is skipped for the same
        reason (see that checkbox's note).
- [ ] **Cloudflare `SUBDOMAIN_KV`: a separate prod namespace.** If prod's `SyncSubdomainToKvJob` writes the
      same KV as dev, the two environments clobber each other's `<handle>.partna.au` routing.
- [ ] **Supabase JWT secret** = the **prod** project's secret (auth verification fails otherwise).
- [ ] **Origins / URLs:** `PARTNA_FRONTEND_ORIGINS` (`https://partna.au,https://www.partna.au,https://app.partna.au`),
      `FRONTEND_URL=https://app.partna.au`, `PARTNA_MARKETING_URL=https://partna.au`,
      `PARTNA_PUBLIC_DOMAIN=partna.au`.
- [ ] **Redis** (prod instance / DB indices), **mail** (from-address, transport, `RESEND_API_KEY`,
      `RESEND_WEBHOOK_SECRET`, and the Supabase email-hook secret — **the secret alone is not enough; the
      hook must also be registered on the prod Supabase project, see Phase 1**), **media S3 bucket**
      (`MEDIA_DISK_URL`), **Nightwatch** (prod project), **Horizon
      basic-auth** creds, **Cloudflare** API tokens (prod zone), any provider API keys.
- [ ] **Supabase Pro on the prod project BEFORE go-live** — managed daily backups + paid-tier limits must
      cover the riskiest window (the first live days), not arrive in Phase 5.
- [ ] `migrate --force` stays **off** — schema is applied Supabase-side (the Phase 1 `psql` baseline), never
      Laravel migrate (guard forbids Laravel migrations anyway).

---

## Phase 3 — Branch, edge, go-live (the cutover moment)

**No DNS repoint here** — api.partna.au already CNAMEs to the prod env's vanity domain, so waking/deploying
prod IS the public go-live. Sequence accordingly:

- [ ] **Pre-flight (before the push): smoke-test prod on its raw `*.laravel.cloud` URL** — health, auth, a
      create-site path — because once you push, api.partna.au serves it live with no DNS valve to hold
      traffic back.
- [ ] **Update the branch:** fast-forward `development → production` and push. This triggers the prod build
      and wakes the env (the env deploys from `production`). Verify build succeeds
      (`cloud deployment:list production`). **This deploy is the go-live instant** — api.partna.au flips
      from 404 to live-prod automatically.
- [ ] **Deploy the prod Cloudflare Worker** bound to the prod `SUBDOMAIN_KV`, so `<handle>.partna.au`
      serves from prod. Do this in lock-step with go-live so sitepage routing matches the API cutover.
- [ ] **Point the dashboard at prod** — `app.partna.au` is a **Vercel** deploy, not DNS. Confirm its
      production build's API base is `https://api.partna.au` (now prod) and that its origin is in the
      backend `PARTNA_FRONTEND_ORIGINS`. No DNS change; this is a frontend-repo / Vercel-env check.
- [ ] Confirm `dev-api.partna.au` / `dev-app.partna.au` still point at dev (unchanged) — they use separate
      vanity domains, so go-live doesn't touch them.

---

## Phase 4 — Verify

- [ ] Health endpoint on `api.partna.au` (prod) responds.
- [ ] End-to-end smoke: signup → create a site → confirm `SyncSubdomainToKvJob` wrote prod KV →
      `<handle>.partna.au` renders from prod.
- [ ] Horizon dashboard shows masters up and jobs draining (not sync).
- [ ] **Scheduler runs.** The prod Cloud env's scheduler is enabled and a scheduled task actually fired
      (`handles:prune-expired-aliases`, `builds:prune-expired` are load-bearing) — check the schedule
      output / Nightwatch task monitoring, don't assume.
- [ ] Nightwatch (prod project) clean — no boot exceptions, no eager-scraper / connection errors.
- [ ] **Auth email arrives from Resend, not Supabase.** Trigger a real OTP / magic-link on prod and confirm
      the message is `From: hello@partna.au` and DKIM-signed by `partna.au` (not a `*.supabase.co` sender) —
      this proves the Send Email Hook is registered and the signature secret matches (Phase 1).
- [ ] **Deliverability DNS is prod-ready** (see `docs/superpowers/plans/2026-07-21-email-deliverability-hardening-PROMPT.md`):
      `partna.au` has an MX + a reachable `hello@partna.au` inbox, DMARC `rua` points at a report parser you
      read, and the Resend bounce/complaint webhook is registered against the prod URL.
- [ ] Custom-domain path (if in pilot scope) resolves.

---

## Phase 5 — Post-cutover (customer data now lives in prod)

- [ ] **Re-point the off-platform backup.** The weekly R2 backup (`partna-db-backup` GitHub Action) targets
      **dev**'s `SUPABASE_DB_URL` today — move it to the prod ref (the `--schema` flags stay valid because
      prod is re-baselined with the same standalone migrations). Rename the dump prefix (`partna-dev-` →
      live). Then **re-run the drill-04 restore rehearsal** against the new target.
- [ ] **Confirm the Supabase Pro upgrade is on the prod project** (moved at Phase 2, before go-live —
      not here) and drop it from dev if it isn't needed there — the paid tier follows the live data.
- [ ] **Rewrite CLAUDE.md's "Current reality" block** (+ the env table if needed): after cutover,
      "dev serves both domains / prod inactive" is wrong — prod serves api.partna.au from the prod
      Supabase. Stale env docs misdirect every future session.

---

## Rollback

- **Fastest:** **stop/hibernate the prod Laravel env** so api.partna.au returns to 404 (it CNAMEs to prod,
      so there is no "point back to dev" — dev lives on its own `dev-api` vanity). If you need the domain to
      *serve* during rollback, re-point the api.partna.au CNAME at the dev env's vanity
      (`partna-development-fsh3vz.laravel.cloud`) as a deliberate, separate step.
- **DB:** the re-baseline is the irreversible part — there is no "undo" once prod carries real signups.
      This is why cutover happens only after P0/P1 are closed and the pilot is genuinely ready.

---

## Drift reconciliation (detailed steps)

**Goal:** make the repo's `supabase/migrations/` the single, reproducible source of truth for the dev
schema, so (a) the collapse snapshot is trustworthy and (b) the parity diff is meaningful. Known drift on
this project: migrations applied to dev out-of-band via MCP `apply_migration` (archetype:
`platform_connections`); "deleted-but-applied" files (removed from the repo but still in dev's ledger); and
**version-renumbered** files — identical DDL applied on dev under a *different version* than its repo
filename (confirmed 2026-07-22: menu/services dev `…080945/081007/081023/081111` vs repo
`…090000/150000/150001/180000`; the Gate-A CHECK/FK batch dev `…052546→052646` vs repo `…100000→100600`;
handle-prune dev `20260718020855` vs repo `20260718010000`). **This last class is why a blind `supabase db
push` to dev is unsafe** — it sees the repo version as un-applied and re-runs DDL that already exists;
resolve with `migration repair --status applied <version>` (step 4), and apply any genuinely-new single
migration surgically (`apply_migration` / `psql` of that one file), never a bulk `db push`.

> **Dev-only.** Every command here targets the **dev** project (`glncumufgaqcmqhzwrxm`). Never run
> `repair` / `push` against prod during reconciliation — prod gets the clean baseline afterward.

**1. Link to dev and enumerate the ledger.**
```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase migration list
```
Read the three columns (`Local | Remote | Time`) and classify every mismatched row:
- **Remote-only** (Remote version, no Local file) = applied to dev out-of-band, no migration file.
- **Local-only** (Local file, no Remote version) = file never applied to dev.
- **Aligned** (both present) = fine, leave it.

**2. Make the schema drift concrete.**
```bash
supabase db diff --linked \
  --schema public,core,site,notifications,analytics,audit,moderation > /tmp/dev-drift.sql
```
Anything in `dev-drift.sql` is schema that exists on dev but the local migrations don't reproduce — the
out-of-band drift, made concrete. **Empty output** = the repo already reproduces dev and only ledger
bookkeeping (steps 3–4) remains.

**Expect this exact command to FAIL pre-collapse** (`SQLSTATE 25001`): `--linked` builds a shadow DB by
applying all of `supabase/migrations/` through the same pipelined applier that `db reset`/`db push` use,
which aborts on the 11 CONCURRENTLY-bundle files — the very defect `fresh-reset.sh` exists to bypass.
Fallback (no shadow build; diffs two live DBs directly):
```bash
supabase start && scripts/db/fresh-reset.sh   # realize the repo migrations into the local DB (psql loop)
supabase db diff --from local --to linked \
  --schema public,core,site,notifications,analytics,audit,moderation > /tmp/dev-drift.sql
```
Post-collapse, plain `--linked` works again (the baseline is CONCURRENTLY-free).

**3. Resolve remote-only rows (applied-but-no-file).** For each, pick one:
- **Adopt into the repo** (preferred when it's real schema you want to keep): create a migration file that
  reproduces it — hand-author from the `dev-drift.sql` output, or `supabase db pull` to generate a capture
  file — so the repo now contains it. Then, if the new file's version differs from the ledger's,
  `supabase migration repair --status applied <version>` so the ledger and file agree.
- **Discard from the ledger** (only if the change was superseded/reverted and is genuinely NOT in the
  current schema): `supabase migration repair --status reverted <version>` drops the phantom ledger row.

**4. Resolve local-only rows (file-not-applied).**
- If the DDL is genuinely not on dev yet: `supabase db push` applies it.
- If the DDL is already on dev under a different/no version (applied out-of-band):
  `supabase migration repair --status applied <version>` marks it applied without re-running.

**5. Converge and verify — repeat 3–4 until both are clean:**
```bash
supabase migration list      # Local and Remote columns aligned, no orphans
supabase db diff --linked    # EMPTY (pre-collapse: use the --from local --to linked form from step 2)
```
An **empty `db diff` + aligned `migration list`** means dev is now a known, reproducible state — for
schema objects; grants and role attributes are invisible to the diff and get asserted separately (collapse
plan, Task 8). Only then proceed to the collapse/snapshot.

**6. Commit the reconciliation** (adopted migration files + any notes on repaired ledger rows) so the repo
records the resolved state *before* the baseline is regenerated. This is the checkpoint the collapse builds
on.

---

## Migration collapse (rationale + method)

**Recommendation: yes — collapse into a fresh baseline before cutover.** Not for tidiness; for correctness
and reliability on a fresh prod DB.

**Why (concrete, not cosmetic):**
- **Hundreds of incrementals of self-cancelling churn.** The stack creates-then-drops `smart_links`,
  sheet/thread/atlas skeletons, `design_kit_bg_image`, `workplace_hours`, etc., and renames
  `skeleton_id → architecture_id` (then `one → staple`). On a fresh DB that's pure waste — sequential DDL
  to reach a schema a single baseline expresses directly, with a failure point at every statement.
- **Empty-DB backfills are meaningless/fragile.** `backfill_subdomain_alias_lifecycle`,
  `backfill_gb_apify_status_placeid`, `migrate_retired_font_slugs`, `backfill_reporter_email_normalisation`
  operate on rows that won't exist. No-ops at best, replay hazards at worst.
- **Known drift = replay ≠ dev.** The repo migrations don't perfectly match the *actual* dev schema.
  Replaying repo history onto fresh prod reproduces the *repo*, which may silently differ from dev.
  **Snapshotting the verified dev schema is the only way to guarantee prod == dev** — which is exactly why
  the drift reconciliation above must come first.

**Method** (executable version: `docs/superpowers/plans/2026-07-22-reconcile-and-collapse-baseline.md`
Tasks 0–10 + its bootstrap `PROMPT-execute-reconcile-and-collapse-baseline.md`; the plan carries the
2026-07-22 review corrections and wins on conflict):
1. Complete "Drift reconciliation" above so dev is a known, reproducible state.
2. `supabase db dump` the dev schema — **all** app schemas (`public`, `core`, `site`, `notifications`,
   `analytics`, `audit`, `moderation`), RLS policies, functions (pinned search_path), triggers, views
   (`all_site_data`, `public_site_payload`, …), CHECK constraints, object grants + per-schema default
   privileges. **Exclude** Supabase-managed schemas (`auth`, `storage`, …). A dump can NOT emit
   cluster-level state — the `app_backend` role, its `NOLOGIN`/**`BYPASSRLS`** attributes, or
   `CREATE EXTENSION` (`pg_trgm`) — the stitch step adds those (collapse plan, Task 5).
3. Make it the new baseline (e.g. `2026NNNN000000_baseline_pilot.sql`); move the incrementals to
   `supabase/migrations-archive/`.
4. **Verify parity:** apply the new baseline to a scratch DB **via `scripts/db/fresh-reset.sh`** (the psql
   simple-query loop — a from-zero `db reset`/`db push` can't, per the CONCURRENTLY note in Phase 1), then
   `supabase db diff --from local --to linked` against dev — expect **empty**. A clean diff proves schema
   objects only: also run the collapse plan's Task-8 grant-matrix + `pg_roles` assertions (NOLOGIN,
   BYPASSRLS). Don't trust it for prod until both are clean.
5. Update `CLAUDE.md`'s baseline-filename reference and any audit-pipeline path references.

**Timing:** do the collapse as a discrete, calm task *after* the P0/P1 migration-bearing fixes land and the
schema stabilizes — not under cutover-day pressure. Further migrations simply stack on the new baseline
(same pattern as the 2026-05-26 one); collapse again only if churn re-accumulates.

**Fallback (if not collapsing):** apply all the migration files to fresh prod **via `psql -f` in filename
order** (the same simple-protocol procedure as Phase 1) — **not** `supabase db push`, which aborts from zero
on the 11 CONCURRENTLY-bundle files (`SQLSTATE 25001`; the regression guard
`scripts/guard-no-unsafe-migrations.php` Check 6 + `CONVENTIONS.md §1` document this). Still requires the
drift reconciliation above and a verifying schema diff — mandatory either way.
