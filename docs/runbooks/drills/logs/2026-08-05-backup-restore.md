# Drill log — 04 Supabase backup / restore

- **Date:** 2026-08-05 (AEST; all times below UTC)
- **Runbook:** [../04-backup-restore.md](../04-backup-restore.md) (at commit `d6caef96`; repo HEAD `d6caef96`)
- **Operator(s):** Claude (Opus 5), driven by Josh
- **Environment:** GitHub Actions runner (`ubuntu-24.04`) in
  `Hunter-Balcombe-Sykes/partna-db-backup`; scratch destination = a throwaway `postgres:17`
  service container. **No Supabase project was created and nothing was written to prod.**
- **Mode/variants run:** Fallback logical-dump path (`restore-drill.yml`), the only path
  available on the Free plan. Source object = the weekly R2 dump of **prod**
  (`edplucmvkcnokyygxqsb`).

Run: [`30923175957`](https://github.com/Hunter-Balcombe-Sykes/partna-db-backup/actions/runs/30923175957)
— conclusion **success**, 35 s wall.

## Timeline

| Time (UTC) | Phase | Action / observation |
|------------|-------|----------------------|
| 15:15:03 | ARRANGE | `gh workflow run restore-drill.yml` dispatched. |
| 15:15:19–15:15:37 | ARRANGE | Runner up; `postgres:17` pulled and healthy (18 s — image pull, not restore). |
| 15:15:37–15:15:49 | ARRANGE | `postgresql-client-17` installed. |
| 15:15:49 | RECON | Newest object under `weekly/` selected: `partna-prod-2026-08-02.dump.enc`. |
| 15:15:50–15:15:52 | RESTORE ⏱ | Downloaded from R2 (460 KiB, ~2 s) and decrypted (470 967 B). |
| 15:15:52 | RESTORE | Scratch primed: 9 roles + `pg_trgm`(public) + `pgcrypto`/`uuid-ossp`(extensions). |
| 15:15:52–15:15:53 | RESTORE ⏱ | `pg_restore` completed — **~1 s**. Exit 1, 5 stderr lines. |
| 15:15:53 | OBSERVE | Error triage: 4 actionable lines, all confined to `auth`/extensions. Business schemas clean. |
| 15:15:53 | OBSERVE | Integrity assertions passed on every business schema. |
| 15:15:53 | RECOVER | Scratch container torn down by the runner. Nothing to delete by hand. |

## Evidence

Source selection and size:

```
Newest object under weekly/: partna-prod-2026-08-02.dump.enc
download: s3://partna-db-backups/weekly/partna-prod-2026-08-02.dump.enc
-rw-r--r-- 1 runner runner 470992 Aug  2 15:52 partna-prod-2026-08-02.dump.enc
Decrypted size: 470967 bytes
TOC entries: 1057
```

Schemas carried in the dump:

```
analytics  audit  auth  core  moderation  notifications  public  site  supabase_migrations
```

Restore + triage:

```
pg_restore exit: 1
error lines: 5
--- full restore stderr ---
pg_restore: error: could not execute query: ERROR:  schema "public" already exists
Command was: CREATE SCHEMA public;
pg_restore: warning: errors ignored on restore: 1
---------------------------
actionable error lines: 4
Errors confined to auth/extensions — expected against a vanilla postgres:17.
```

Integrity assertions on the **restored** database:

```
--- tables per schema (restored) ---
analytics=10   audit=6   core=14   moderation=5   notifications=6   site=23
NOTICE:  schema core ok: 14 tables (>= 14)
NOTICE:  schema site ok: 23 tables (>= 23)
NOTICE:  schema analytics ok: 10 tables (>= 10)
NOTICE:  schema audit ok: 6 tables (>= 6)
NOTICE:  schema moderation ok: 5 tables (>= 5)
NOTICE:  schema notifications ok: 6 tables (>= 6)
--- row counts (identifies WHICH database this dump came from) ---
core.users=0 site.sites=0 core.partna_staff=1
```

`core.users=0` / `site.sites=0` / `core.partna_staff=1` is the expected prod fingerprint —
prod still carries no customer data, one staff row. It also positively identifies the dump as
**prod's**, not dev's.

## RPO / RTO

| Metric | Measured | Notes |
|--------|----------|-------|
| **Backup cadence** | Weekly (`weekly-db-backup`, Sundays ~15:51 UTC) | Last two: 2026-08-02, 2026-07-26, both `success`. |
| **PITR** | **Not available** | Supabase org on the **Free** plan — no PITR, no managed daily backups. |
| **Measured RPO** | **1 d 23 h 23 min** (backup 2026-08-02 15:52 UTC → drill 2026-08-04 15:15 UTC) | **Worst-case RPO ≈ 7 days** — that is the number that matters, not today's lucky 2 days. |
| **RTO — object fetch** | ~2 s | 460 KiB from R2. |
| **RTO — decrypt** | <1 s | |
| **RTO — prime destination** | <1 s | roles + extensions. |
| **RTO — `pg_restore`** | **~1 s** | Scratch DB accepting queries. |
| **RTO — integrity verify** | <1 s | |
| **RTO — total (measured)** | **~4 s of actual restore work**; 35 s including runner + image pull | On a 460 KiB dump. This number will grow with the data, and today's is a floor, not a forecast. |

## Verdict

| Criterion (from runbook) | Result | Notes |
|--------------------------|--------|-------|
| Restore completed by runbook alone, no improvisation | **PASS** | The workflow ran end to end with zero manual steps. |
| RTO and RPO measured and written down | **PASS** | Above. RPO is a policy problem, not a mechanism problem — see Finding 1. |
| Integrity checks clean | **PASS** | Every business schema met its table-count floor; row counts fingerprint prod. |
| Role/connection gap documented with the exact commands that closed it | **PARTIAL** | The workflow primes roles, but **Phase 3 was not rehearsed** — see Finding 2. |
| Scratch destination deleted, no cost incurred | **PASS** | Ephemeral service container; no Supabase project created. **$0.** |

**Overall: PASS** on the mechanism — the backup is real, restorable, and complete. **PARTIAL**
on the drill's full scope: two runbook phases the workflow does not cover were not exercised.

## Findings

1. **Worst-case RPO is ~7 days and that is a policy decision nobody has recorded.** Weekly is
   the only cadence that exists (Free plan ⇒ no PITR, no managed daily backups; the R2 dump is
   the whole backup story). Today's measured 1 d 23 h is an artefact of *when* the drill ran.
   Prod holds no customer data yet, so the exposure is currently zero — but the moment the
   pilot puts real users in, "we can lose up to a week of customer data" needs to be either
   accepted in writing or fixed (daily dump ≈ same cost; the workflow already exists and would
   need only a cron change). **Not fixed here — it is Josh's call, not a code bug.**
2. **Phase 3 ("the gap between restored and working") is not covered by the workflow.** The
   `app_backend` LOGIN grant, the `app_backend.<ref>` Supavisor connection-string shape, and
   the `supabase_migrations.schema_migrations` version match against source are all listed in
   the runbook and none of them are asserted by `restore-drill.yml`. The workflow proves *the
   data comes back*; it does not prove *the app can connect to it*. That is precisely the
   3 a.m. step most likely to be improvised.
3. **Object storage is still outside the drill.** Runbook Phase 1 asks whether media survives a
   project loss; the workflow only touches Postgres. `docs/runbooks/media-backup-setup.md`
   exists, but this drill produced no evidence that the media mirror ran or that
   `rclone check` passed. A mirror nobody has read back is not a proven backup — the same
   objection the runbook itself raises about the DB dump.
4. **`moderation` is a real schema and CLAUDE.md does not list it.** The prod dump carries
   `moderation` with 5 tables, and the workflow treats it as a business schema. CLAUDE.md's
   Architecture Rules enumerate `public, core, site, notifications, analytics, audit` and
   explicitly say which schemas do *not* exist. Documentation drift, not a fault — but the
   schema list is load-bearing for restore triage. **Fixed in this branch.**

## Runbook corrections

Applied to `../04-backup-restore.md` in the same commit as this log:

1. **Phase 1 still names dev (`glncumufgaqcmqhzwrxm`) as the dashboard to inspect** while the
   Ground rules (correctly) name prod as the source. Corrected to prod, and reframed as
   "check the R2 object list" since the dashboard has no backups to show on the Free plan.
2. **The RPO/RTO expectations are stale.** The runbook talks about daily backups and a 24 h
   worst-case RPO; the real cadence is weekly. Corrected.
3. **Phases 3 and 4 read as though the workflow covers them.** Marked explicitly as
   *not* covered by `restore-drill.yml` — they are manual steps a full quarterly run must do
   by hand, or the drill silently degrades into a data-only check (Finding 2).

## Next run due

**Quarterly** — next by **2026-11-05**. Also re-run early if the backup workflow, the R2
bucket, or the encryption passphrase changes.
