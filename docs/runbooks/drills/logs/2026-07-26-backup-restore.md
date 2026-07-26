# Drill log — 04 backup/restore

- **Date:** 2026-07-26
- **Runbook:** [../04-backup-restore.md](../04-backup-restore.md) (at commit `8472168f`)
- **Operator(s):** Josh + Claude (production-cutover Phase 5 Step 2)
- **Environment:** source = **prod** Supabase `edplucmvkcnokyygxqsb`; destination = throwaway
  `postgres:17` service container inside GitHub Actions
- **Mode/variants run:** fallback path (logical dump → restore). The runbook's preferred
  "Restore to a new project" path is **not available** — see Findings F1.

## Timeline

| Time (UTC) | Phase | Action / observation |
|------|-------|----------------------|
| 08:22 | ARRANGE | `SUPABASE_DB_URL` re-pointed from dev to prod (secret set by Josh) |
| 08:23 | ARRANGE | Backup run #1 failed — secret value included the `SUPABASE_DB_URL=` prefix; `pg_dump` parsed it as a libpq conninfo option name |
| 08:31 | ARRANGE | Backup run #2 failed — unencoded `@` in the DB password; libpq split userinfo at the wrong `@` and a password fragment was parsed as part of the hostname |
| 08:37 | ARRANGE | Prod DB password rotated to an alphanumeric value; secret re-set |
| 08:38 | ARRANGE | Backup run #3 **succeeded** — `partna-prod-2026-07-26.dump.enc`, 470,192 bytes, uploaded to `s3://partna-db-backups/weekly/` |
| 08:47 | INJECT | Restore drill run #1 — died at the restore step (GitHub's default `bash -e {0}` aborted before triage) |
| 08:50 | INJECT | Run #2 — triage reached, flagged 5 business schemas; on inspection all 320 errors were environmental (missing roles/extensions) |
| 08:53 | INJECT | Run #3 — scratch DB primed with roles + `pg_trgm`; restore clean, integrity step hit a `GROUP BY` bug of my own making |
| 08:55 | OBSERVE | Run #4 — **full pass**, 47s end to end |
| 08:55 | RECOVER | Service container destroyed with the job. Nothing to tear down; source never written to |

## Evidence

Backup job (run `30194981600`):
```
Dump size: 470168 bytes (floor 10000)
upload: ./partna-prod-2026-07-26.dump.enc to s3://partna-db-backups/weekly/partna-prod-2026-07-26.dump.enc
2026-07-26 08:39:16     470192 partna-prod-2026-07-26.dump.enc
```

Restore drill (run `30195484796`):
```
Newest object under weekly/: partna-prod-2026-07-26.dump.enc
Decrypted size: 470168 bytes          # byte-identical to what the backup job wrote
TOC entries: 1057
pg_restore exit: 1
actionable error lines: 4
Errors confined to auth/extensions — expected against a vanilla postgres:17.

--- tables per schema (restored) ---
analytics=10   audit=6   core=14   moderation=5   notifications=6   site=23

NOTICE:  schema core ok: 14 tables (>= 14)
NOTICE:  schema site ok: 23 tables (>= 23)
NOTICE:  schema analytics ok: 10 tables (>= 10)
NOTICE:  schema audit ok: 6 tables (>= 6)
NOTICE:  schema moderation ok: 5 tables (>= 5)
NOTICE:  schema notifications ok: 6 tables (>= 6)

core.users=0 site.sites=0 core.partna_staff=1
```

The four residual "actionable" lines are the `Command was:` continuation lines belonging to
the two benign `schema "public" already exists` errors; the filter drops the error line but
not its continuation. Harmless, and none mention a business schema.

**`core.users=0 site.sites=0 core.partna_staff=1` is the load-bearing line.** It matches
prod's live counts exactly; dev would have restored as `36/35/n`. Because `SUPABASE_DB_URL`
is write-only once set, the restored contents are the only *direct* proof of which database
a dump came from — everything else (size, host in an error message) is inference.

## Verdict

| Criterion (from runbook) | Result | Notes |
|--------------------------|--------|-------|
| Backup exists and is retrievable | **PASS** | Downloaded from R2 and decrypted; 470,168 bytes, byte-identical to the source |
| Dump is structurally intact | **PASS** | `pg_restore --list` → 1057 TOC entries across 9 schemas |
| Restores into a working database | **PASS** | All 6 business schemas at full table count |
| Restored data is the right data | **PASS** | Row counts match prod exactly |
| Measured RPO | **FAIL** | See F1 — worst case ≈ 7 days |
| Measured RTO | **PARTIAL** | 47s wall-clock, but see F5 — not representative |
| Object storage covered | **FAIL** | See F4 |

**Overall: PARTIAL** — the backup and restore mechanism is proven end to end; the
*recovery posture* around it is not yet acceptable for real customer data.

## Findings

- **F1 — No PITR, no managed backups, weekly cadence.** The Supabase org
  (`ligsuetayyrxzojoxxbt`, holding both projects) is on the **Free** plan. There are no
  managed daily backups and no PITR, and Free projects can auto-pause. The only backup is
  this weekly R2 dump (cron Sunday 15:00 UTC), so **worst-case RPO is ~7 days**. Acceptable
  only while prod holds no customer data. Josh's 2026-07-26 decision was to proceed on Free
  and upgrade later — recorded in `docs/deploy/production-cutover.md` Phase 5.
- **F2 — Restoring into non-Supabase Postgres silently loses RLS and trigram indexes.**
  A bare `postgres:17` rejected 304 statements for missing roles (`authenticated` ×202,
  `app_backend` ×54, `service_role` ×24, `anon` ×24) and 14 GIN indexes for a missing
  `public.gin_trgm_ops`. Roles are cluster-global and `pg_dump` never carries them;
  extensions live outside the dumped schemas. **A restore that "succeeds" this way comes
  back with no RLS policies.** The drill now pre-creates the nine roles and `pg_trgm`;
  anyone restoring by hand must do the same. Restoring into a real Supabase project is
  unaffected.
- **F3 — The dump was missing the `moderation` schema until today.** `--schema=moderation`
  was absent from the flag list, so any dump taken before `221c1d64` would have silently
  dropped all five `moderation.*` tables. The cutover checklist asserted the `--schema`
  flags "stay valid unchanged"; that was wrong. Fixed in `partna-db-backup` PR #1.
- **F4 — Object storage is not backed up at all.** Sitepage media lives in Cloudflare R2 and
  no backup covers it. A DB restore returns rows pointing at objects that may be gone. Moot
  today (no media in prod), a real gap before customers upload anything.
- **F5 — RTO is not meaningfully measured.** 47s covers a ~0-row database restored into a
  local container. It says nothing about restoring into a fresh Supabase project with real
  data, which is the actual disaster. Re-measure once prod carries data.
- **F6 — The backup had never run once before today.** Its cron was commented out from the
  repo's creation on 2026-07-17 through 2026-07-26.

## Runbook corrections

Applied to `04-backup-restore.md` in this commit:

- **Source is now prod** `edplucmvkcnokyygxqsb`, not dev `glncumufgaqcmqhzwrxm`. The
  "dev is the live DB for everything right now" premise died at the 2026-07-26 cutover.
- **The preferred path is unavailable.** "Restore to a new project" is a paid-tier feature
  and the org is on Free, so the fallback logical-dump path is the *only* path today.
- **The fallback path needs a priming step** before `pg_restore` — the roles and extensions
  in F2 — otherwise the restore appears to succeed with RLS silently absent.

## Next run due

**2026-10-24** (quarterly per `check_calendar_drill backup-restore … 92`), or immediately
after any of: prod gaining real customer data (to re-measure RTO honestly), a Supabase plan
change, or a change to the `--schema` list in `weekly-db-backup.yml`.

Re-run any time with:
`gh workflow run restore-drill.yml --repo Hunter-Balcombe-Sykes/partna-db-backup`
