# Production cutover — change checklist (post-DB-baseline → go-live → after)

**When to use:** the prod Supabase DB (`edplucmvkcnokyygxqsb`) has been **wiped and re-baselined**
(collapsed baseline applied via `psql`, ledger recorded). This is the list of everything to **change / set**
from that point through go-live and after. It distills `production-cutover.md` Phases 1(tail)–5 plus the
2026-07-22 pre-cutover-hardening findings (Task 3 Auth parity, Task 4 old-prod census) into a pure action
list. The narrative + rationale live in `production-cutover.md`; **this is the tick-list.**

> **Go-live has no DNS valve.** `api.partna.au` already CNAMEs to the prod Laravel env, so the moment you
> push `production` (§D) the domain serves prod live. Do §A–§C **before** the push.
>
> **Queue decision (2026-07-22):** workers are turned on **AFTER** cutover. Prod launches on
> `QUEUE_CONNECTION=sync` (jobs inline, same as dev today); the Redis/Horizon flip is §F, a separate calm step.

---

## A. Finish the prod DB (right after the baseline applies, as `postgres` admin)

- [ ] **Bootstrap the login role** — the baseline creates `app_backend` as `NOLOGIN`:
      `ALTER ROLE app_backend WITH LOGIN PASSWORD '<prod-secret>';` (this password = `DB_PASSWORD` in §C).
- [ ] **Assert both role attributes:** `SELECT rolcanlogin, rolbypassrls FROM pg_roles WHERE rolname='app_backend';`
      → must be **`t / t`**. BYPASSRLS is load-bearing (FORCE-RLS tables have no `app_backend` policy; without
      it the app is default-denied at runtime).
- [ ] **Verify grants** match dev: `audit` = SELECT/INSERT only, `moderation` grants present, functions have
      pinned `search_path`. (Collapse plan Task-8 `role_table_grants` + `pg_default_acl` diff.)
- [ ] **Seed reference/bootstrap data** the app needs on a fresh DB (platform/feature config, any bootstrap
      rows). Task-4 census note: old prod held only `billing.plans`(5) + `site.themes`(3) — both vestigial
      under the standalone schema; seed only what the *current* baseline actually requires.
- [ ] `migrate --force` stays **OFF** (schema is Supabase-side; the Laravel-migration guard forbids it anyway).

## B. Supabase dashboard — Auth hooks + config (NOT carried by the DB dump or env vars)

- [ ] **Register the Send Email Hook** → `https://api.partna.au/api/internal/email-hooks/supabase`,
      secret = prod `SUPABASE_EMAIL_HOOK_SECRET` (`v1,whsec_<base64>`). Without this, auth OTP/magic-link/invite
      emails silently fall back to Supabase's `*.supabase.co` sender (wrong branding → spam) and bypass the
      Resend/DKIM pipeline. **Highest-risk email trap of the cutover.**
- [ ] **Register the MFA Verification Hook** → `https://api.partna.au/api/webhooks/supabase/auth/mfa-verification`,
      secret = prod `SUPABASE_AUTH_HOOK_SECRET`. Secret must match the env value on both sides or the path 401s.
- [ ] **Apply the Auth-config parity checklist** (full detail in `production-cutover.md` Phase-1
      "Auth project-config parity"). The musts:
      - [ ] **Site URL** = `https://app.partna.au`
      - [ ] **Redirect URLs** = **TIGHT list: `https://app.partna.au/auth/callback` only** (+ `…/auth/confirm`
            if the confirm flow needs it — verify with frontend). **Do NOT** add `localhost:3000` or any
            `*.vercel.app` preview URL (open-redirect surface in prod).
      - [ ] **MFA → TOTP (App Authenticator) = Enabled** — else staff can never reach `aal2` → every staff
            endpoint 401s.
      - [ ] **Email OTP length = 6**, **expiration ≤ 3600 s**.
      - [ ] SMS/Phone MFA = Disabled; rate limits ≈ dev defaults (50 emails/h, 30/5min verifications).

## C. Laravel Cloud — prod env vars (wake the stopped env; set a COMPLETE, separate secret set)

Generate `APP_KEY` fresh for prod (`php artisan key:generate --show`). Set `APP_ENV=production`,
`APP_URL=https://api.partna.au`. Carry all `PARTNA_*` feature flags / tuning knobs from dev's env unchanged
unless you want different prod values.

### ⛔ Boot-critical — prod hard-fails to start without these (`AppServiceProvider::boot`)
- [ ] `APP_DEBUG=false`
- [ ] `PARTNA_PUBLIC_DOMAIN=partna.au`
- [ ] `PARTNA_THROTTLE_ENABLED=true` (must not be false)
- [ ] `SUPABASE_JWKS_FAIL_CLOSED=true`
- [ ] `SUPABASE_JWT_ISSUER=https://edplucmvkcnokyygxqsb.supabase.co/auth/v1`
- [ ] `SUPABASE_JWT_AUD=authenticated`
- [ ] `SUPABASE_EMAIL_HOOK_SECRET=<prod, matches §B hook>`
- [ ] `SUPABASE_AUTH_HOOK_SECRET=<prod, matches §B hook>`
- [ ] `FEEDBACK_IP_HASH_PEPPER=<new prod random secret>`
- [ ] `NIGHTWATCH_TOKEN=<prod project>` (required whenever `NIGHTWATCH_ENABLED=true`)

### Identity / Supabase (must be the PROD project's values)
- [ ] `SUPABASE_URL=https://edplucmvkcnokyygxqsb.supabase.co`
- [ ] `SUPABASE_ANON_KEY=<prod>` · `SUPABASE_SERVICE_ROLE_KEY=<prod>`
- [ ] `SUPABASE_JWKS_URL=<prod project JWKS endpoint>` (Supabase → Project Settings → API/JWT)
- [ ] `SUPABASE_ADMIN_BASE_URL` (defaults to `{SUPABASE_URL}/auth/v1/admin` — OK once `SUPABASE_URL` is set)
- [ ] `SUPABASE_REQUIRE_SESSION_ID=true` (default; keeps "sign out everywhere" working)

### Database → prod Supabase
- [ ] `DB_USERNAME=app_backend.edplucmvkcnokyygxqsb` (Supavisor tenant prefix)
- [ ] `DB_PASSWORD=<the password set in §A ALTER ROLE>`
- [ ] `DB_HOST=<prod Supavisor pooler host>` · `DB_PORT=5432` (session mode) · `DB_DATABASE=postgres`
- [ ] `DB_CONNECTION=pgsql` · `DB_SEARCH_PATH` + `DB_SSLMODE=require` (carry from dev)

### Queue / Redis (SYNC at go-live — see §F for the later Redis flip)
- [ ] `QUEUE_CONNECTION=sync` **at go-live** (do NOT set `redis` until §F, when a worker is provisioned)
- [ ] `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` = prod Redis instance (needed for cache/session/locks
      even while queue is sync); carry the `REDIS_*_DB` index split from dev.

### Cloudflare / KV — a SEPARATE prod namespace (critical)
- [ ] `CLOUDFLARE_KV_NAMESPACE_ID=<NEW prod KV namespace>` — if prod writes dev's KV, the two envs clobber
      each other's `<handle>.partna.au` routing.
- [ ] `CLOUDFLARE_ACCOUNT_ID` · `CLOUDFLARE_API_TOKEN` · `CLOUDFLARE_ZONE_ID` (prod zone) ·
      `CLOUDFLARE_CACHE_PURGE_TOKEN`

### Origins / URLs
- [ ] `PARTNA_FRONTEND_ORIGINS=https://partna.au,https://www.partna.au,https://app.partna.au`
- [ ] `FRONTEND_URL=https://app.partna.au` · `PARTNA_MARKETING_URL=https://partna.au`
- [ ] `SESSION_SECURE_COOKIE=true` · `SESSION_DOMAIN` as appropriate

### Mail / Resend
- [ ] `RESEND_API_KEY=<prod>` · `RESEND_WEBHOOK_SECRET=<prod>` (register the bounce/complaint webhook at the
      prod URL) · `MAIL_FROM_ADDRESS=hello@partna.au` · `MAIL_FROM_NAME` · `MAIL_MAILER`/host per dev.

### Media / storage (R2)
- [ ] `MEDIA_DISK_BUCKET` / `MEDIA_DISK_ENDPOINT` / `MEDIA_DISK_KEY` / `MEDIA_DISK_SECRET` /
      `MEDIA_DISK_REGION` / `MEDIA_DISK_URL` = prod bucket; `AWS_*` if used; `FILESYSTEM_DISK` / `PARTNA_MEDIA_DISK`.

### Monitoring + third-party API keys
- [ ] `NIGHTWATCH_TOKEN` = prod project (also in boot-critical above).
- [ ] Bot protection: `TURNSTILE_SECRET`/`TURNSTILE_SITE_KEY` (or `HCAPTCHA_*`) + `BOT_PROTECTION_*` = prod keys.
- [ ] **`GOOGLE_MAPS_API_KEY` / `GOOGLE_MAPS_SERVER_API_KEY`** — the only uncapped paid API (Places); confirm
      prod quota/billing before launch.
- [ ] Streaming/platform keys as used: `TWITCH_CLIENT_*`, `KICK_CLIENT_*`, Apify token, `FRESHA_*`.

## D. Supabase Pro + deploy (go-live)

- [ ] **Upgrade the prod Supabase project to Pro BEFORE go-live** (managed daily backups cover the riskiest
      first days — not a Phase-5 afterthought).
- [ ] **Pre-flight smoke on the raw `*.laravel.cloud` URL** (health, auth, create-site) — the last check
      before the domain goes live.
- [ ] **Fast-forward `development → production` and push** → triggers the prod build + wakes the env.
      **This push IS go-live** (api.partna.au flips 404 → live prod). Verify build: `cloud deployment:list production`.
- [ ] Confirm the prod deploy command is unchanged (`ffmpeg.sh` + `composer install --no-dev` + `optimize`,
      no npm, no auto `migrate --force`) and PHP version is intended (last prod build ran 8.4; project targets 8.2).
- [ ] **Deploy the prod Cloudflare Worker** bound to the prod `SUBDOMAIN_KV`, in lock-step with go-live.
- [ ] **Point the Vercel dashboard** (`app.partna.au`) production build's API base at `https://api.partna.au`
      and confirm its origin is in `PARTNA_FRONTEND_ORIGINS`. (Frontend/Vercel-env change, not DNS.)

## E. Verify (Phase 4)

- [ ] `api.partna.au` health responds.
- [ ] End-to-end: signup → create site → `SyncSubdomainToKvJob` wrote prod KV → `<handle>.partna.au` renders.
- [ ] **Auth email arrives `From: hello@partna.au`, DKIM-signed by `partna.au`** (NOT `*.supabase.co`) — proves
      the Send Email Hook is registered + secret matches.
- [ ] Scheduler actually fired (`GET /api/health/scheduler`; `handles:prune-expired-aliases`,
      `builds:prune-expired` are load-bearing).
- [ ] Nightwatch (prod) clean — no boot exceptions, no eager-scraper/connection errors.
- [ ] Custom-domain path resolves (if in pilot scope).

## F. Post-cutover

- [ ] **Turn workers on** (the deferred queue flip): provision + start a Horizon worker (`php artisan horizon`),
      set `QUEUE_CONNECTION=redis`, set `HORIZON_DASHBOARD_USERNAME`/`HORIZON_DASHBOARD_PASSWORD`
      (`APP_ENV=production` → Horizon resolves the `production` supervisor block). Then run the
      `queue-worker-cutover.md` soak (probe job drains through Redis; watch `analytics`/`images`/`videos` +
      `->delay()` staggers). Env-var-only without a running worker = silent unbounded backlog.
- [ ] **Re-point the weekly R2 backup** (`partna-db-backup` Action) from dev's `SUPABASE_DB_URL` to prod;
      rename the dump prefix; re-run the drill-04 restore rehearsal against the new target.
- [ ] Confirm Supabase Pro is on prod (moved to §D) and drop it from dev if not needed there.
- [ ] **Rewrite `CLAUDE.md`'s "Current reality" block** — after cutover, "dev serves both domains / prod
      inactive" is wrong; prod serves `api.partna.au` from prod Supabase.

---

### Not needed (verified 2026-07-22, re-check on the day)
- **Archive dump of old prod:** optional — old prod app schemas are empty of user data (only 8 seed rows),
  no PII. (Task-4 census.)
- **Purge stale `auth.users`:** verified no-op — `auth.users = 0` on old prod.
