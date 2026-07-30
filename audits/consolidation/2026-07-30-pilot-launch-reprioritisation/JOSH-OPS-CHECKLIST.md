# P0-PILOT — the three units only Josh can execute

Produced 2026-07-30 on `audit-fix/p0-pilot-2026-07-30`, covering `LC-PROD-ENV`, `LC-BACKUP` and
`LC-EDGE-HARDENING`. These three ticked in `CONSOLIDATED.md` **on handoff, not on verified state** —
nothing below has been actioned. Starting, stopping, deploying or promoting an environment, and every
dashboard write, was out of bounds for the agent run that produced this.

Everything marked `UNVERIFIED` is a genuine gap with the exact check named. None of it is a guess
dressed up as a fact.

---

## Unit 3 — `LC-PROD-ENV` · production environment is stopped

**Verified live 2026-07-30 (read-only):** `status = stopped`, `usesPushToDeploy = true`, vanity URL
`https://partna-production-uovh3z.laravel.cloud`. Last successful deployment `depl-a259ea57…`,
commit `265f9aa4`, finished `2026-07-26T12:58:55Z`.

This is consistent with the deliberate 2026-07-26 decision to stop it — it is a pre-pilot gate, not a
regression.

- [ ] Re-confirm state immediately before acting — do not trust this snapshot:
      `~/.composer/vendor/bin/cloud environment:get production --json`
- [ ] `~/.composer/vendor/bin/cloud deployment:list production --json` — confirm the last **succeeded**
      deployment's commit is the one you expect.
- [ ] Decide deliberately whether the stop was intentional (it was) before restarting.
- [ ] Start it: `cloud environment:start production` — **mutates state, yours to run.**
- [ ] Re-run `environment:get` + `deployment:list`. Confirm `status=running` and that starting did not
      silently trigger a new deployment. Starting a stopped env resumes the last successful deploy
      (`265f9aa4`); only `git push origin development:production` deploys new code.
- [ ] **Readiness probe — `GET https://api.partna.au/api/ready`**, not `/api/health`.
      `routes/api.php:206` → `HealthController::check()`: unauthenticated, `throttle:health-check`,
      calls `DB::connection()->getPdo()` and does a real Redis round-trip. Returns 200 with
      `{"database":{"status":"healthy",…}}` only if both succeed, else 503.
      `/api/health` is a liveness stub that stays green on a fully broken prod.

> **The trap, already recorded in this project:** a bare 404 and a moved git ref *both* lie about a
> deploy. Confirm via `cloud deployment:list`, never by curling a URL.

---

## Unit 4 — `LC-BACKUP` · backup posture before real data lands

**Verified live 2026-07-30:** Supabase org `Partna` (`ligsuetayyrxzojoxxbt`) is on plan **`free`**.
Prod project `edplucmvkcnokyygxqsb` is `ACTIVE_HEALTHY` (not currently auto-paused).

Free means **no PITR, no managed backups**, and projects can auto-pause. The `partna-db-backup` R2
dump is the only copy.

**The backup mechanism, located:** a GitHub Actions workflow in a separate private repo,
`Hunter-Balcombe-Sykes/partna-db-backup`. Cron **Sunday 15:00 UTC — weekly only**. `pg_dump` via the
Supavisor pooler, AES-256 encrypted, uploaded to `s3://partna-db-backups/weekly/`, 90-day R2 lifecycle
prune. Design: `docs/superpowers/specs/2026-07-17-weekly-db-backup-design.md`.

Restore was verified once (2026-07-26): backup/restore/integrity all PASS, overall verdict **PARTIAL** —
`docs/runbooks/drills/logs/2026-07-26-backup-restore.md`.

**The decision — yours, not the agent's:**

- [ ] **Branch A — move off Free before customer #1.** Supabase Dashboard → Organization `Partna` →
      Billing → change plan to Pro. Owner-only; not possible via CLI or MCP.
      Cost and what it buys (PITR window, retention days): **UNVERIFIED — check supabase.com/pricing.**
      Three repo docs reference the Pro upgrade (`docs/checklists/launch-readiness-checklist.md`,
      `docs/deploy/production-cutover.md`, the backup design spec) and **none records a figure.**
      After upgrading: Project Settings → Database → Backups; confirm PITR on, note the retention
      window. Keep the weekly R2 dump either way — complementary, not redundant.
- [ ] **Branch B — accept Free in writing.** Record the acceptance in
      `docs/checklists/launch-readiness-checklist.md` beside TECH-3, then re-run the drill against a
      database that actually contains rows:
      `gh workflow run restore-drill.yml --repo Hunter-Balcombe-Sykes/partna-db-backup`
      Log the result to `docs/runbooks/drills/logs/<date>-backup-restore.md` per the template.

**Close these regardless** — both flagged open by the 2026-07-26 drill:
- [ ] **F4** — R2 object/media storage has **no backup at all**. Moot only while prod media is empty.
- [ ] **F5** — the measured 47s RTO is against a near-empty DB and is not representative once real
      data exists.

> With `core.users = 0` this whole unit is a non-issue. The first pilot customer's data changes that
> completely: an untested single-copy backup is the difference between an incident and an extinction
> event.

---

## Unit 6 — `LC-EDGE-HARDENING` · Cloudflare + Supabase dashboard settings

Covers both launch-check report rows. All manual dashboard toggles, none API-readable — which is
precisely why this was never confirmed. `get_advisors(security)` on prod returns RLS/extension/leaked-
password lints only and surfaces **none** of the four Supabase settings below, so dashboard
verification is the only option.

### Cloudflare — zone `partna.au`

- [ ] **Cache Deception Armor → ON.** Caching → Configuration → "Cache Deception Armor".
      Matters most here because public sitepages are aggressively CDN-cached.
      Plan-tier gating **UNVERIFIED — check the zone's plan at Overview**; Cloudflare has moved this
      feature between tiers.
- [ ] **Edge rate-limiting rules.** Security → WAF → Rate limiting rules → Create rule. Target the
      public unauthenticated surface: `/api/public/*`, `/api/claim`, `/api/bootstrap`.
      Laravel's own throttle is not a substitute — it only engages after the request reaches origin.
      Tier support **UNVERIFIED**; the rule-creation screen refuses to save if the plan lacks it.
- [ ] **SSL/TLS mode → Full (strict).** SSL/TLS → Overview. Available on all plans.

Checked `cloudflare-worker/wrangler.toml` and `src/`: nothing there duplicates any of the three — the
Worker only configures `SUBDOMAIN_KV`, cache TTL vars and the `PARTNA_PAGES` service binding. Nothing
to remove first.

> The zone is a single route (`*/*` on `partna.au`) shared by prod and dev subdomains — the same shape
> as the already-known-open shared `SUBDOMAIN_KV` gap. **All three settings apply to both environments
> at once**; there is no separate dev zone.

### Supabase — prod project `edplucmvkcnokyygxqsb`

- [ ] **SSL enforcement → ON.** Project Settings → Database → SSL Enforcement.
      Risk if off: clients can connect without TLS.
- [ ] **Network restrictions — ⚠️ STOP AND VERIFY FIRST.** Project Settings → Database → Network
      Restrictions.
      **Laravel Cloud's egress IPs for the `partna` app are UNVERIFIED — no static IP list exists
      anywhere in this repo.** Adding a CIDR allowlist without them locks prod out of its own
      database. Before touching this toggle, confirm from the Laravel Cloud dashboard/docs whether the
      app has static egress IPs or a NAT gateway at all — many PaaS egress ranges are dynamic, which
      would make this restriction unsafe to enable in any form.
- [ ] **Auth rate limits — review.** Authentication → Rate Limits. Check sign-in / OTP / token-refresh
      limits against expected pilot volume.
- [ ] **Custom SMTP.** Authentication → Emails → SMTP Settings.
      Risk if left default: Supabase's shared mailer has low volume caps and worse deliverability than
      the app's own Resend pipeline. Consider routing Supabase Auth mail through the same provider.
