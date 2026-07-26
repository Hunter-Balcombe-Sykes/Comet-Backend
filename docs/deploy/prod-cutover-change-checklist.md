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
- [ ] **Verify grants** match dev (Collapse plan Task-8 `role_table_grants` + `pg_default_acl` diff):
      - `audit`: SELECT/INSERT on tables **plus** UPDATE on `audit.data_export_audit` (export lifecycle +
        GDPR keep-row retention) **plus** EXECUTE on the 3 SECURITY-DEFINER prune fns
        (`audit.prune_handle_change_log`, `audit.prune_user_deletion_audit`, `audit.prune_data_export_audit`).
        "SELECT/INSERT only" is **stale** — without these, the scheduled `audit:prune-pii-snapshots` (03:55),
        handle-audit-log prune, and completed-export cleanup jobs are all permission-denied on prod.
      - `moderation`: append-only — SELECT/INSERT on `decisions`, SELECT/INSERT/UPDATE on `action_log`.
      - Functions have pinned `search_path`.
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

`NEW` Generate `APP_KEY` fresh for prod (`php artisan key:generate --show`). `NEW` `APP_ENV=production` ·
`NEW` `APP_URL=https://api.partna.au`. `SAME` Carry all `PARTNA_*` feature flags / tuning knobs from dev unchanged
unless you want different prod values. **Caveat:** the "carry all `PARTNA_*`" rule only covers `PARTNA_`-prefixed
keys — several live vars are **not** prefixed (see "App / notifications / internal" below) and are missed by it.

**Same-vs-different legend** — every var below is tagged, answering "can I paste dev's value?":
- `SAME` — copy dev's value verbatim (non-secret config, or a value identical across envs).
- `SPLIT` — the same third-party **account** works, so copy-paste launches fine, but **mint a separate prod
  token/key** (independent rotation + per-env cost attribution). Hygiene call, not a blocker.
- `NEW` — **must differ**: a distinct prod resource/URL, a prod project ref, or a fresh secret. Pasting dev's
  value is wrong or unsafe.

> A few boot-critical vars aren't set in dev at all (dev relies on framework defaults) — prod must set them
> **explicitly** because the boot guard requires the literal. Tags are relative to dev's *effective* value.

### ⛔ Boot-critical — prod hard-fails to start without these (`AppServiceProvider::boot`)
- [ ] `SAME` `APP_DEBUG=false`
- [ ] `SAME` `PARTNA_PUBLIC_DOMAIN=partna.au`
- [ ] `SAME` `PARTNA_THROTTLE_ENABLED=true` (must not be false)
- [ ] `SAME` `SUPABASE_JWKS_FAIL_CLOSED=true`
- [ ] `NEW` `SUPABASE_JWT_ISSUER=https://edplucmvkcnokyygxqsb.supabase.co/auth/v1` — **prod** ref; dev's
      `glncumufgaqcmqhzwrxm` issuer here 401s every request.
- [ ] `SAME` `SUPABASE_JWT_AUD=authenticated`
- [ ] `NEW` `SUPABASE_EMAIL_HOOK_SECRET=<prod, matches §B hook>`
- [ ] `NEW` `SUPABASE_AUTH_HOOK_SECRET=<prod, matches §B hook>`
- [ ] `NEW` `FEEDBACK_IP_HASH_PEPPER=<new prod random secret>`
- [ ] `NEW` `NIGHTWATCH_TOKEN=<prod project>` (required whenever `NIGHTWATCH_ENABLED=true`)

### Identity / Supabase (must be the PROD project's values)
- [ ] `NEW` `SUPABASE_URL=https://edplucmvkcnokyygxqsb.supabase.co`
- [ ] `NEW` `SUPABASE_ANON_KEY=<prod>` · `NEW` `SUPABASE_SERVICE_ROLE_KEY=<prod>`
- [ ] `NEW` `SUPABASE_JWKS_URL=<prod project JWKS endpoint>` (Supabase → Project Settings → API/JWT)
- [ ] `SAME` `SUPABASE_ADMIN_BASE_URL` (leave unset — derives from `{SUPABASE_URL}/auth/v1/admin`)
- [ ] `SAME` `SUPABASE_REQUIRE_SESSION_ID=true` (default; keeps "sign out everywhere" working)

### Database → prod Supabase
- [ ] `NEW` `DB_USERNAME=app_backend.edplucmvkcnokyygxqsb` (Supavisor tenant prefix — prod ref)
- [ ] `NEW` `DB_PASSWORD=<the password set in §A ALTER ROLE>`
- [ ] `NEW` `DB_HOST=<prod Supavisor pooler host>` · `SAME` `DB_PORT=5432` (session mode) · `SAME` `DB_DATABASE=postgres`
- [ ] `SAME` `DB_CONNECTION=pgsql` · `DB_SEARCH_PATH` + `DB_SSLMODE=require` (all carry from dev)

### Queue / Redis (SYNC at go-live — see §F for the later Redis flip)
- [ ] `SAME` `QUEUE_CONNECTION=sync` **at go-live** (do NOT set `redis` until §F, when a worker is provisioned)
- [ ] `NEW` `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` = prod Redis instance (Laravel Cloud usually
      auto-injects these when you attach prod Redis).
- [ ] `NEW` **`REDIS_CACHE_DB=1` set EXPLICITLY — do NOT carry dev's effective values.** Verified
      2026-07-22: deployed dev resolves cache to **DB 0** (Cloud-injected `REDIS_CACHE_DB=0`), putting
      cache on the same DB as queue+Horizon — `Cache::flush()` is a raw `FLUSHDB` and would wipe job
      state once workers exist. Cloud Redis supports `SELECT`, so the split works; set `REDIS_DB=0`,
      `REDIS_CACHE_DB=1`, leave `REDIS_CACHE_LOCKS_DB`/`REDIS_SESSION_DB` on code defaults (4 / 2).

### Cloudflare / KV — a SEPARATE prod namespace (critical)
- [ ] `NEW` `CLOUDFLARE_KV_NAMESPACE_ID=<NEW prod KV namespace>` — if prod writes dev's KV, the two envs clobber
      each other's `<handle>.partna.au` routing.
- [ ] `SAME` `CLOUDFLARE_ACCOUNT_ID` · `SAME` `CLOUDFLARE_ZONE_ID` (same `partna.au` zone) ·
      `SPLIT` `CLOUDFLARE_API_TOKEN` · `SPLIT` `CLOUDFLARE_CACHE_PURGE_TOKEN`
- [ ] `SPLIT` `CLOUDFLARE_SAAS_API_TOKEN` — Cloudflare-for-SaaS custom hostnames (`CloudflareCustomHostnameService`);
      required for the custom-domain path verified in §E. Optional `CLOUDFLARE_SAAS_CNAME_TARGET` (defaults `cname.partna.au`).

### Origins / URLs
- [ ] `NEW` `PARTNA_FRONTEND_ORIGINS=https://partna.au,https://www.partna.au,https://app.partna.au` — **drop**
      dev's wider value (it also lists `dev-app.partna.au` + `localhost:*`); do NOT paste dev's origins into prod.
- [ ] `SAME` `FRONTEND_URL=https://app.partna.au` · `SAME` `PARTNA_MARKETING_URL=https://partna.au`
- [ ] `SAME` `SESSION_SECURE_COOKIE=true` · `SESSION_DOMAIN` as appropriate

### Mail / Resend
- [ ] `SPLIT` `RESEND_API_KEY` · `NEW` `RESEND_WEBHOOK_SECRET` (its own secret — register the bounce/complaint
      webhook at the prod URL) · `SAME` `MAIL_FROM_ADDRESS=hello@partna.au` · `MAIL_FROM_NAME` · `MAIL_MAILER`/host.

### Media / storage (R2)
- [ ] `NEW` a **separate prod R2 bucket + its own keys**, set via the **`AWS_*`** vars the disk actually reads
      (`AWS_BUCKET` / `AWS_ENDPOINT` / `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_DEFAULT_REGION` /
      `AWS_URL` / `AWS_USE_PATH_STYLE_ENDPOINT`) — Laravel Cloud auto-injects these when you attach the bucket,
      and dev + prod already run on them. `MEDIA_DISK_*` are **optional overrides** that fall back to `AWS_*`
      (`config/filesystems.php`); set only `MEDIA_DISK_URL` if the public media-CDN domain differs. `SAME`
      `FILESYSTEM_DISK` / `PARTNA_MEDIA_DISK` (disk selector, default `media`).

### Monitoring + third-party API keys
- [ ] `NEW` `NIGHTWATCH_TOKEN` = prod project (also in boot-critical above).
- [ ] `NEW` Bot protection: `TURNSTILE_SECRET`/`TURNSTILE_SITE_KEY` (or `HCAPTCHA_*`) — widgets are domain-bound,
      so prod needs its own pair; `SAME` `BOT_PROTECTION_*` flags.
- [ ] `SPLIT` **`GOOGLE_MAPS_API_KEY` / `GOOGLE_MAPS_SERVER_API_KEY`** — the only uncapped paid API (Places).
      Use a **separate prod key** so it carries its own quota cap + billing alert (a key shared with dev can't be
      capped per-env — a dev runaway would burn prod budget). Confirm prod quota/billing before launch.
- [ ] `SPLIT` **`APIFY_TOKEN`** — pre-account signup scrapers: Instagram, Google-Business enrich, Menu
      (`MenuApifyScraper`). Same Apify account works; mint a **separate prod token** (rotation + per-env cost
      attribution). **Core to pilot signup** — unset and those paths silently no-op.
- [ ] `SPLIT` **AI menu extraction:** `MISTRAL_API_KEY` **and** `DEEPSEEK_API_KEY` (`MenuAiExtractor` needs the
      pair; with either blank the extractor is disabled).
- [ ] `SPLIT` Other streaming/platform keys **only if that platform is live in prod**: `TWITCH_CLIENT_*`,
      `KICK_CLIENT_*`, `FRESHA_*` — none are currently set in dev, so confirm the feature is on before adding.

### App / notifications / internal (non-`PARTNA_`-prefixed — the "carry all `PARTNA_*`" rule does NOT reach these)
- [ ] `NEW` `INTERNAL_ENV_CHECK_TOKEN=<new prod secret>` — gates the internal env-check endpoint (`EnvCheckController`);
      set a fresh prod value, do not reuse dev's.
- [ ] `SAME` **`NOTIFICATIONS_EMAIL_ENABLED=true`** — master email switch; **defaults FALSE**, so if unset every email
      notification goes silently dark in prod. (Or set the `PARTNA_NOTIFICATIONS_EMAIL_ENABLED` override.)
- [ ] `SAME` `FEEDBACK_NOTIFY_EMAILS=<recipients>` — defaults empty → feedback emails reach no one. Optional tuning
      (code defaults exist): `FEEDBACK_RATE_LIMIT_HOUR` / `FEEDBACK_RATE_LIMIT_DAY` / `FEEDBACK_DUPLICATE_WINDOW`.

## D. Supabase Pro + deploy (go-live)

- [ ] **Upgrade the prod Supabase project to Pro BEFORE go-live** (managed daily backups cover the riskiest
      first days — not a Phase-5 afterthought).
- [ ] **Pre-flight smoke on the raw `*.laravel.cloud` URL** (health, auth, create-site) — the last check
      before the domain goes live.
- [ ] **Fast-forward `development → production` and push** → triggers the prod build + wakes the env.
      **This push IS go-live** (api.partna.au flips 404 → live prod). Verify build: `cloud deployment:list production`.
- [x] Confirm the prod deploy command is unchanged (`ffmpeg.sh` + `composer install --no-dev` + `optimize`,
      no npm, no auto `migrate --force`) and PHP version is intended. **Verified 2026-07-26** via
      `cloud environment:get production --json`: build command is exactly those three lines, `deployCommand`
      is the single commented-out `# php artisan migrate --force`, `phpMajorVersion: 8.4`, `nodeVersion: 24`,
      `usesOctane: false`. Development is byte-identical on all five, so go-live introduces no build delta.
      8.4 **is** the intended version (`composer.json` requires `^8.4`; CI runs 8.4) — the older "project
      targets 8.2" note in these docs was stale and has been corrected.
- [ ] **Deploy the prod Cloudflare Worker** bound to the prod `SUBDOMAIN_KV`, in lock-step with go-live.
- [ ] **Point the Vercel dashboard** (`app.partna.au`) production build's API base at `https://api.partna.au`
      and confirm its origin is in `PARTNA_FRONTEND_ORIGINS`. (Frontend/Vercel-env change, not DNS.)

## E. Verify (Phase 4)

- [ ] `api.partna.au` health responds (`GET /up` liveness; `GET /api/internal/env-check` with
      `INTERNAL_ENV_CHECK_TOKEN` for the full resolved-config assertion).
- [ ] End-to-end: signup → create site → `SyncSubdomainToKvJob` wrote prod KV → `<handle>.partna.au` renders.
- [ ] **Auth email arrives `From: hello@partna.au`, DKIM-signed by `partna.au`** (NOT `*.supabase.co`) — proves
      the Send Email Hook is registered + secret matches.
- [ ] Scheduler actually fired (`GET /api/health/scheduler`; `handles:prune-expired-aliases`,
      `builds:prune-expired` are load-bearing).
- [ ] Nightwatch (prod) clean — no boot exceptions, no eager-scraper/connection errors.
- [ ] Custom-domain path resolves (if in pilot scope).

## F. Post-cutover

- [ ] **Turn workers on** (the deferred queue flip): provision + start a Horizon worker (`php artisan horizon`),
      set `QUEUE_CONNECTION=redis`, set `HORIZON_DASHBOARD_USERNAME`/`HORIZON_DASHBOARD_PASSWORD` — since
      2026-07-22 the auth gate requires these on **every deployed env**, not just prod (`APP_ENV=production`
      → Horizon resolves the `production` supervisor block). Pre-flip asserts: `REDIS_CACHE_DB=1` is live
      (§C — cache off the queue DB), scheduler enabled (`GET /api/health/scheduler` now audits the real
      schedule over HTTP), hibernation off/compatible with a worker. Then run the `queue-worker-cutover.md`
      soak (probe job drains through Redis; watch `analytics`/`images`/`videos` + `->delay()` staggers).
      Env-var-only without a running worker = silent unbounded backlog.
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
