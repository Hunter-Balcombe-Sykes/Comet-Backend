# Production Cutover Runbook

**Purpose:** stand up a real, isolated `production` environment (branch + Laravel Cloud env + Supabase
project + edge) separate from `development`, for the pilot and beyond.

**Gate:** do NOT run this until (a) the product is pilot-ready and (b) all P0/P1 audit findings are
resolved. Promoting `production` ships the entire dev divergence + every migration at once — it is a
one-shot, mostly-irreversible event, not a routine deploy.

> **Status: NOT YET EXECUTED.** This is the forward plan. Re-verify every "current state" fact below on
> the day — this doc was last verified **2026-07-17** and the environment drifts.

---

## ⚠ 2026-07-21 findings — read before Phase 1 and the drift/collapse steps

Two things learned while resolving fresh-DB provisioning (memory: *Fresh-DB Provisioning*):

**1. `supabase db push` CANNOT apply the repo's migrations from an empty DB.** The CLI pipelines each
migration file's statements into one implicit transaction whenever the file has >1 statement of any kind,
and `CREATE/DROP INDEX CONCURRENTLY` cannot run in a pipeline (`SQLSTATE 25001`). ~9 index files bundle
multiple CONCURRENTLY, so a from-zero `db push` / `db reset` aborts. Verified on CLI v2.98.2 / v2.101.0 /
v2.109.1 — not fixed upstream. **Consequences for this runbook:**
- The **Fallback** below ("`db push --include-all` all the files") does **not** work on fresh prod. Apply
  via a **`psql` simple-query loop** instead (each statement is a top-level command, no pipeline) — pattern
  in `scripts/db/fresh-reset.sh` (local) and `CLAUDE.md` → "Push to Supabase / Fresh prod DB".
- The **collapse path is preferred** partly because a `pg_dump` baseline emits plain `CREATE INDEX` (no
  CONCURRENTLY), so `db push` of a *collapsed* baseline is unaffected.

**2. Dev migration drift is now characterised and BENIGN — but dev is MISSING 10 additive migrations the
repo has.** A per-migration catalog audit (all 73 repo-only versions vs the dev DB, 2026-07-21): 63 already
effected on dev (present or provably superseded — e.g. the skeleton_id→architecture_id→'staple' path), and
10 genuinely missing, **all additive**: `20260721010000` (workplaces/content_selection RLS — defense-in-depth
only; `site.*` tables carry no `anon`/`authenticated` grant, so RLS-off exposes nothing), `20260721020000`
(menu-platform `id` defaults), `20260721040000`+`040100..040700` (pg_trgm + 7 trigram search indexes,
perf-only). **Nothing destructive is missing; dev's schema is fundamentally correct.** Josh chose NOT to
apply the 10 to dev (none fix a live bug at pre-beta scale). **Consequences for this runbook:**
- Snapshotting **dev** for the baseline (the collapse method below) would carry those 10 gaps into prod.
  Prefer snapshotting a **repo-provisioned** scratch DB — `scripts/db/fresh-reset.sh` applies *all* repo
  migrations cleanly (= the complete intended schema) — or apply the 10 to dev first, then snapshot.
- The ledger-reconciliation dance below is therefore **optional for correctness**: the gaps are benign and
  dev isn't relied on for clean `db push`. It only matters if you specifically want dev's *ledger* aligned.

---

## Current state (verified 2026-07-17)

Everything production already *exists*; it is stale and asleep, not missing. Cutover = reactivate +
re-baseline + repoint, not build-from-scratch.

| Piece | State | Implication |
|-------|-------|-------------|
| `production` git branch | Exists, is the **default branch**. **0 commits ahead / 1,155 behind** `development`. | Fast-forward — no merge conflicts. But one push ships 1,155 commits + all migrations. |
| Laravel Cloud **production** env | Exists. Last deploy 2026-05-21 on a **pre-standalone** commit (`fa69c2b1`). Hibernating/stopped. | Would run the *old* app on the *old* schema if woken as-is. Needs redeploy + full secret set. |
| Supabase **Partna Development** (`glncumufgaqcmqhzwrxm`) | `ACTIVE_HEALTHY`, PG 17, ap-southeast-2. | The live DB for everything today (incl. prod sitepages). Source of truth for the prod baseline. |
| Supabase **Partna Production** (`edplucmvkcnokyygxqsb`) | `INACTIVE` (paused), PG 17, ap-southeast-2. | On old pre-standalone schema (~`20260512145025`); current migrations un-applied. Re-baseline target. |
| DNS | `api.partna.au` currently resolves to the **dev** env. | The go-live moment is repointing it to prod. |

The three genuinely risky steps: **(1)** the prod DB re-baseline (irreversible data event), **(2)** the prod
secret set (Redis/queue, KV namespace, JWT, mail, media), **(3)** the `api.partna.au` DNS repoint (the
visible go-live). Everything else is copy-config.

---

## Phase 0 — Pre-cutover prep (do these *before* cutover day, unhurried)

- [ ] **All P0/P1 audit findings resolved**, including any that carry Supabase migrations (they must land
      on dev first so the schema is final before we snapshot it).
- [ ] **Reconcile dev migration drift** — the **non-negotiable prerequisite** for a trustworthy prod DB.
      The dev DB has changes applied out-of-band that aren't in the repo (and possibly repo files not
      applied to dev). Reconcile so the schema we snapshot/replay is a known, reproducible state. **Full
      step-by-step below → see "Drift reconciliation (detailed steps)".**
- [ ] **Collapse the migration history into a fresh baseline** (see "Migration collapse" below). Snapshot
      the *verified dev schema*, archive the incrementals, verify parity with a schema diff.
- [ ] **Env-var parity audit.** Diff the dev Laravel Cloud env's keys against `.env.example` and build the
      complete prod secret set (see Phase 2). Every key dev has, prod needs — with prod values.
- [ ] **Decide reference/seed data** a fresh prod needs: platform config, feature flags, any bootstrap rows.
- [ ] **Confirm the prod Laravel deploy command** is current (the last prod build predates the standalone
      rework). It should match dev's build (ffmpeg script + `composer install --no-dev` + `php artisan
      optimize`), **without** an auto `migrate --force` — schema is Supabase-side.

---

## Phase 1 — Production database (`edplucmvkcnokyygxqsb`)

The one irreversible step. Pre-beta = no customer data, so prod starts clean.

- [ ] **Restore** the INACTIVE project (Supabase dashboard → Restore, or MCP `restore_project`). It returns
      on the *old* schema.
- [ ] **Reset to a clean slate.** Because it carries stale migration history, either reset its schema or
      (tidier) provision a brand-new prod project and retire this ref. Reusing `edplucmvkcnokyygxqsb`
      keeps the Laravel `DB_USERNAME` prefix stable; a new project is cleaner. Judgment call.
- [ ] **Apply the (collapsed) baseline, gated:**
      ```bash
      supabase link --project-ref edplucmvkcnokyygxqsb
      supabase db push --dry-run          # REVIEW every statement
      # confirm with Josh, then:
      supabase db push --include-all
      ```
      `--include-all` matters — prod is far behind. Always show the dry-run and confirm before the real push.
- [ ] **Bootstrap the login role.** The baseline creates `app_backend` as `NOLOGIN` (fail-closed). In the
      SQL editor: `ALTER ROLE app_backend WITH LOGIN PASSWORD '<from-secret>';` — the app cannot connect
      until this runs.
- [ ] **Verify grants**: `app_backend` has the intended per-schema grants (esp. `audit` = SELECT/INSERT
      only, `moderation` grants present). RLS policies applied. Functions have pinned `search_path`.
- [ ] **Seed** reference/bootstrap data from Phase 0.

---

## Phase 2 — Production Laravel Cloud env

Wake the stopped env and set a **complete, separate** secret set. The keys that silently break prod if
missed:

- [ ] **DB** → prod Supabase. `DB_USERNAME=app_backend.edplucmvkcnokyygxqsb` (Supavisor tenant prefix),
      port **5432** (session mode), prod password from the ALTER ROLE step.
- [ ] **`QUEUE_CONNECTION=redis` + real Horizon masters running.** Dev runs `queue=sync` with 0 Horizon
      masters (every job inline) — **prod must not inherit that.** Confirm Horizon supervisors are
      configured and running, else jobs like `SyncSubdomainToKvJob` run synchronously on the request.
- [ ] **Cloudflare `SUBDOMAIN_KV`: a separate prod namespace.** If prod's `SyncSubdomainToKvJob` writes the
      same KV as dev, the two environments clobber each other's `<handle>.partna.au` routing.
- [ ] **Supabase JWT secret** = the **prod** project's secret (auth verification fails otherwise).
- [ ] **Origins / URLs:** `PARTNA_FRONTEND_ORIGINS` (`https://partna.au,https://www.partna.au,https://app.partna.au`),
      `FRONTEND_URL=https://app.partna.au`, `PARTNA_MARKETING_URL=https://partna.au`,
      `PARTNA_PUBLIC_DOMAIN=partna.au`.
- [ ] **Redis** (prod instance / DB indices), **mail** (from-address, transport, Supabase email hook
      secret), **media S3 bucket** (`MEDIA_DISK_URL`), **Nightwatch** (prod project), **Horizon
      basic-auth** creds, **Cloudflare** API tokens (prod zone), any provider API keys.
- [ ] `migrate --force` stays **off** — schema is Supabase `db push`, never Laravel migrate (guard forbids
      Laravel migrations anyway).

---

## Phase 3 — Branch, edge, DNS (the cutover moment)

- [ ] **Update the branch:** fast-forward `development → production` and push. This triggers the prod build
      (the env deploys from `production`). Verify build succeeds (`cloud deployment:list production`).
- [ ] **Deploy the prod Cloudflare Worker** bound to the prod `SUBDOMAIN_KV`, so `<handle>.partna.au`
      serves from prod.
- [ ] **Repoint DNS** — the go-live instant:
      - `api.partna.au` → prod Laravel env (currently → dev).
      - `app.partna.au` → prod dashboard build (already targets `api.partna.au`, so it follows).
- [ ] Confirm `dev-api.partna.au` / `dev-app.partna.au` still point at dev (unchanged).

---

## Phase 4 — Verify

- [ ] Health endpoint on `api.partna.au` (prod) responds.
- [ ] End-to-end smoke: signup → create a site → confirm `SyncSubdomainToKvJob` wrote prod KV →
      `<handle>.partna.au` renders from prod.
- [ ] Horizon dashboard shows masters up and jobs draining (not sync).
- [ ] Nightwatch (prod project) clean — no boot exceptions, no eager-scraper / connection errors.
- [ ] Custom-domain path (if in pilot scope) resolves.

---

## Rollback

- **Fastest:** repoint `api.partna.au` DNS back to the dev env. The dashboard follows. Prod stays up but
      untrafficked.
- **DB:** the re-baseline is the irreversible part — there is no "undo" once prod carries real signups.
      This is why cutover happens only after P0/P1 are closed and the pilot is genuinely ready.

---

## Drift reconciliation (detailed steps)

**Goal:** make the repo's `supabase/migrations/` the single, reproducible source of truth for the dev
schema, so (a) the collapse snapshot is trustworthy and (b) the parity diff is meaningful. Known drift on
this project: migrations applied to dev out-of-band via MCP `apply_migration` (archetype:
`platform_connections`) and "deleted-but-applied" files (removed from the repo but still in dev's ledger).

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
supabase db diff --linked    # EMPTY — repo migrations reproduce dev exactly
```
An **empty `db diff` + aligned `migration list`** means dev is now a known, reproducible state. Only then
proceed to the collapse/snapshot.

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
  > **2026-07-21 update:** the audit found the repo is a *superset* of dev — the repo has 10 additive
  > migrations dev lacks, and nothing dev-only that the repo doesn't reproduce in schema terms. So the goal
  > is **prod == repo (the intended schema)**, not prod == dev. Snapshotting *dev* would **under-provision**
  > prod by those 10 (trgm indexes, RLS, defaults). Snapshot a **repo-provisioned** scratch DB instead
  > (`scripts/db/fresh-reset.sh` → `pg_dump`), or apply the 10 to dev before snapshotting. See findings up top.

**Method:**
1. Complete "Drift reconciliation" above so dev is a known, reproducible state.
2. `supabase db dump` the dev schema — **all** app schemas (`public`, `core`, `site`, `notifications`,
   `analytics`, `audit`, `moderation`), roles/grants (incl. `app_backend` NOLOGIN + audit append-only),
   RLS policies, functions (pinned search_path), triggers, views (`all_site_data`, `public_site_payload`,
   …), CHECK constraints. **Exclude** Supabase-managed schemas (`auth`, `storage`, …).
3. Make it the new baseline (e.g. `2026NNNN000000_baseline_pilot.sql`); move the incrementals to
   `supabase/migrations-archive/`.
4. **Verify parity:** apply the new baseline to a scratch DB (local stack or a Supabase branch), then
   `supabase db diff` against dev — expect **empty**. Don't trust it for prod until the diff is clean.
5. Update `CLAUDE.md`'s baseline-filename reference and any audit-pipeline path references.

**Timing:** do the collapse as a discrete, calm task *after* the P0/P1 migration-bearing fixes land and the
schema stabilizes — not under cutover-day pressure. Further migrations simply stack on the new baseline
(same pattern as the 2026-05-26 one); collapse again only if churn re-accumulates.

**Fallback (if not collapsing):** apply all the incremental files to fresh prod — but **NOT via
`supabase db push`**, which aborts on the multi-statement `CONCURRENTLY` index files (`SQLSTATE 25001`;
see the 2026-07-21 findings up top). Use a **`psql` simple-query loop** (pattern: `scripts/db/fresh-reset.sh`
/ `CLAUDE.md` → "Push to Supabase / Fresh prod DB"), applying `supabase/migrations/*.sql` in filename order
and recording each version in `supabase_migrations.schema_migrations`. Do it *after* a verifying schema diff.
Note this replays the **repo** (which is the complete intended schema — it includes the 10 additive
migrations dev is missing), so unlike snapshotting dev, the fallback does not propagate dev's gaps.
