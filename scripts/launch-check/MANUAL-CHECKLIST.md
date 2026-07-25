# Launch-Check — Manual Residue

Items no script can verify. Reviewed every run; each needs a human + a date.

## Pre-pilot
- [ ] **k6 load pass** — baseline (10 VU / 5 min, top-5 endpoints) + public `<handle>` spike (50–100 VU): watch edge cache-hit ratio, Supavisor `pg_stat_activity` headroom, p95.
- [ ] **Worker-kill drill** — run `docs/runbooks/drills/01-worker-kill.md` (LOCAL stack only); log to `docs/runbooks/drills/logs/`. Freshness auto-checked by group E.
- [ ] **Vendor-outage drill** — run `docs/runbooks/drills/02-vendor-outage.md` (local); log as above. Freshness auto-checked by group E.
- [ ] **Redis-down drill** — run `docs/runbooks/drills/03-redis-down.md` (local); log as above. Freshness auto-checked by group E.
- [ ] **Nightwatch fires** — throw a deliberate exception on dev; confirm the alert actually arrives.

## Pre-launch
- [ ] **Prod environment decision** — unpause prod Supabase + Laravel Cloud env, or formally commit to dev-serves-both; prod DB re-baseline plan (repo migrations vs pre-standalone prod schema) — gated, Josh decides.
- [ ] **PITR / backups** — confirm plan tier supports PITR; run the restore drill `docs/runbooks/drills/04-backup-restore.md` (to a scratch project, never a live one); log as above. Quarterly thereafter — freshness auto-checked by group E.
- [ ] **Cloudflare dashboard** — Cache Deception Armor ON; rate-limiting rules at the edge (not only Laravel); SSL mode Full (strict).
- [ ] **Supabase dashboard** (not fully API-readable) — SSL enforcement ON, network restrictions set, auth rate limits reviewed, custom SMTP.
- [ ] **DAST pass** — OWASP ZAP baseline or Nuclei against staging (headers, cache deception, injection at runtime).
- [ ] **Rollback plan per migration** — every migration since last deploy has a tested reverse path.
- [ ] **Runbooks exist** — DB pool exhausted; queue backed up; vendor API down; Redis down.

## Continuous
- [ ] Re-run launch-check after every migration push and before every promote.
- [ ] Frontend/monorepo has NO audit coverage — separate effort.
