# Drill 04 — Supabase backup / restore

**Question:** can we actually get the database back from a backup — how fresh is the
freshest backup (RPO), how long does a restore take (RTO), and what manual steps stand
between "restored" and "app connects"? This is launch-checklist **TECH-3 (P0)**, then
quarterly (**TECH-S3-7 / OPS-S4-4**).

This drill tests the *platform*, not our code — it never goes stale from merges, and it's
the only drill runnable at any time regardless of in-flight work.

## Ground rules

- **Source:** **prod** Supabase `edplucmvkcnokyygxqsb` — the live DB since the 2026-07-26
  cutover. **Read-only against it. Never restore INTO it.** (Before that date this runbook
  named dev `glncumufgaqcmqhzwrxm`; that premise is dead.)
- **Destination:** a throwaway scratch project, deleted at the end.
- **Time every step.** RTO is the deliverable, not a vibe.
- Scratch projects on a paid org may bill for their lifetime (hours) — check/confirm cost
  before creating; note it in the log.

> **Fastest way to run this drill (2026-07-26):**
> `gh workflow run restore-drill.yml --repo Hunter-Balcombe-Sykes/partna-db-backup`
>
> That workflow performs the whole fallback path — pull the newest R2 object, decrypt,
> restore into a throwaway `postgres:17` service container, assert per-schema table counts
> against the restored database, and print row counts. It runs where the four secrets
> already live, so neither the encrypted dump nor its passphrase ever reaches a laptop.
> Phases 1–2 below remain the manual reference and the record of what the drill checks.

> **The preferred "Restore to a new project" path is UNAVAILABLE.** It is a paid-tier
> feature and the Supabase org is on the **Free** plan, which also means no PITR and no
> managed daily backups. The fallback logical-dump path below is currently the only path,
> and the weekly R2 dump is the only backup that exists — worst-case RPO ≈ 7 days.

> **Prime the destination before restoring, or RLS comes back silently missing.** Roles are
> cluster-global and `pg_dump` does not carry them; extensions live outside the dumped
> schemas. Restoring into any **non-Supabase** Postgres without these first drops every RLS
> policy and all 14 trigram indexes while still reporting a mostly-successful restore:
> ```sql
> create role anon nologin; create role authenticated nologin;
> create role service_role nologin; create role app_backend nologin;
> create role authenticator nologin; create role supabase_auth_admin nologin;
> create role supabase_admin nologin; create role supabase_storage_admin nologin;
> create role dashboard_user nologin;
> create extension if not exists pg_trgm with schema public;  -- indexes name public.gin_trgm_ops
> ```
> Restoring into a real Supabase project needs none of this — it already has them.

## Phase 1 — Reconnaissance (read-only, ~10 min)

In the Supabase dashboard for `glncumufgaqcmqhzwrxm` → Database → Backups:

- [ ] Plan tier, and whether **PITR** is enabled. If PITR is available, note the retention
      window; if not, note the daily-backup cadence.
- [ ] Timestamp of the **latest** backup → **measured RPO** = now − that timestamp.
      Record it. (Daily backups ⇒ worst-case RPO ≈ 24h. Decide in the log whether that's
      acceptable for launch or PITR is a pre-launch requirement.)
- [ ] Backup size (informs restore-time expectations).
- [ ] Where media/object storage lives and whether it's covered by ANY backup story —
      **a DB backup does not include object storage**. If sitepage media would not survive
      a project loss, that's a standalone finding.

## Phase 2 — Restore (start the clock ⏱)

Preferred path — dashboard **"Restore to a new project"** (available on paid tiers): pick
the latest backup (or a PITR point), target = new scratch project `partna-restore-drill`.

Fallback path — manual, if the dashboard option isn't available on our tier:

```bash
# 1. scratch project (dashboard or CLI), region ap-southeast-2 to match
# 2. dump from source via the POOLER connection string (session mode, port 5432)
pg_dump "$SOURCE_DB_URL" --format=custom --no-owner --no-privileges -f partna-drill.dump
# 3. restore into scratch
pg_restore --dbname "$SCRATCH_DB_URL" --no-owner --no-privileges partna-drill.dump
```

(The manual path tests a *worse* disaster — total project loss with only a logical dump —
which is arguably the more valuable rehearsal. Note which path you ran.)

⏱ **Stop the clock when the scratch DB accepts queries.** That's raw restore RTO.

## Phase 3 — The gap between "restored" and "working"

These are the steps a real 3am recovery would trip over — rehearse them deliberately:

- [ ] **`app_backend` role.** Roles are cluster-level: a fresh project + logical restore
      leaves `app_backend` either missing or `NOLOGIN` (the v2 baseline creates it
      fail-closed). In the scratch SQL editor:
      `ALTER ROLE app_backend WITH LOGIN PASSWORD '<drill password>';`
      — and record that the real secret lives wherever it lives (that lookup is part of RTO).
- [ ] **Connection string shape.** An app pointed at the scratch project needs
      `DB_USERNAME=app_backend.<scratch_ref>` (Supavisor tenant prefix), port 5432.
      Don't actually repoint any deployed env — just verify a psql login as `app_backend`
      works: `psql "postgresql://app_backend.<ref>:<pw>@<pooler-host>:5432/postgres" -c 'select 1'`
- [ ] **Migration state.** `select version from supabase_migrations.schema_migrations
      order by version desc limit 5;` on both source and scratch — must match.

## Phase 4 — Integrity verification

Row-count diff, source vs scratch (same query both sides; source via MCP/psql read-only):

```sql
select schemaname, relname, n_live_tup
from pg_stat_user_tables
where schemaname in ('core','site','notifications','analytics','audit')
order by schemaname, relname;
```

(`n_live_tup` is an estimate — for the handful of tables that matter most, do exact counts:)

```sql
select
  (select count(*) from core.users)                as users,
  (select count(*) from site.sites)                as sites,
  (select count(*) from site.design_kits)          as design_kits,
  (select count(*) from site.platform_connections) as connections,
  (select count(*) from core.user_handle_aliases)  as aliases;
```

- [ ] Counts match (modulo rows written on source after the backup point — that's the RPO
      made visible; note any delta).
- [ ] Spot-check depth, not just counts: pick one real user on scratch and walk the graph —
      user → site → design_kit → connections → media rows all joined and sane.
- [ ] RLS/grants survived: `select relname, relrowsecurity from pg_class
      join pg_namespace n on n.oid = relnamespace where n.nspname = 'site';` matches source.
- [ ] Triggers survived (logical restores can drop/disable them):
      `select tgname from pg_trigger where not tgisinternal;` — compare to source; check
      `trg_create_empty_design_kit` and `trg_recompute_partna_url` by name.

## Phase 5 — Teardown + verdict

- [ ] Delete the scratch project (dashboard). Confirm it's gone from the org's billing.
- [ ] Delete any local dump file containing real data: `rm -P partna-drill.dump`.

**Pass:** restore completed by runbook alone (no undocumented steps needed); RTO and RPO
measured and written down; integrity checks clean; the role/connection gap documented with
the exact commands that closed it.

**Any step that required improvisation is a finding** — the runbook (or a proper
disaster-recovery doc) gets updated so the 3am version of this needs zero thinking.

## Record

`logs/<YYYY-MM-DD>-backup-restore.md` — must capture: backup cadence + PITR status,
measured RPO, restore path taken (dashboard vs manual), **RTO broken into restore /
role-fix / verify**, integrity results, cost incurred, and the quarterly re-run date.
