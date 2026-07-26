# Cutover Phase 2 — Production Laravel Cloud env + secrets (paste-in execute prompt)

Operationalises Phase 2 of `docs/deploy/production-cutover.md` (§C of
`docs/deploy/prod-cutover-change-checklist.md` is the exact tick-list this prompt executes): wake the
stopped production Laravel Cloud env and set a **complete, separate** secret set — nothing carried
blindly from dev, nothing left on framework defaults.

**How to use:** open a **fresh Claude Code session in this repo on model Opus**, then paste everything
from `=== PROMPT START ===` to the end as your first message.

---

## GATE — do not start until every box is true

- [ ] **Phase 1 is done**: the prod DB (`edplucmvkcnokyygxqsb`) has been wiped and the collapsed baseline
      applied via `psql`, AND `ALTER ROLE app_backend WITH LOGIN PASSWORD '<secret>'` has already been run
      — the prod DB password this prompt needs (`DB_PASSWORD`) does not exist before that.
- [ ] **The prod deploy command is verified unchanged** (Phase 0 checkbox, re-verified 2026-07-26 via
      `cloud environment:get production --json`): build = `ffmpeg.sh` + `composer install --no-dev
      --no-interaction --prefer-dist --optimize-autoloader` + `php artisan optimize` (no npm), single
      commented-out `deployCommand` (`# php artisan migrate --force` — no auto-migrate), `phpMajorVersion:
      8.4`. If this has drifted since 2026-07-26, STOP and re-verify before setting secrets against it.

STOP and report if either box is false — do not improvise around a missing prod DB password or an
unverified deploy command.

---

```
=== PROMPT START ===

Execute docs/deploy/PROMPT-execute-cutover-phase-2-prod-env.md — Phase 2 of the production cutover:
prepare and verify the production Laravel Cloud env's secret set. Read
docs/deploy/production-cutover.md Phase 2 IN FULL first, then docs/deploy/prod-cutover-change-checklist.md
§C IN FULL — §C is the authoritative key-by-key list; production-cutover.md Phase 2 is the narrative.
Do not invent env vars beyond what those two files name.

These are keys that SILENTLY break prod if missed — there is no error at set-time for most of them, only
a runtime failure mode days or weeks later (wrong OTP sender, cache wiping queued jobs, KV clobbering
dev's routing, a paid API with no quota cap). Treat every key as if omitting it is the default failure,
not setting it wrong.

## Cutover context (read first)

- Production Supabase project ref: `edplucmvkcnokyygxqsb`. Development ref: `glncumufgaqcmqhzwrxm` —
  **opposite** of habit; double-check every ref/URL you paste, dev's value is very often wrong-by-default
  here because dev and prod are visually similar strings.
- **This task sets prod SECRETS, not code.** You touch zero application files. You may read/write
  `docs/deploy/production-cutover.md` and `prod-cutover-change-checklist.md` to tick boxes and annotate
  findings; nothing else in the repo changes.
- **You cannot click the Laravel Cloud dashboard.** For every key: (1) determine the exact value or the
  exact procedure to obtain it, (2) show Josh the key + value (redact true secrets in transcript/commits,
  but state them in chat so he can paste them), (3) Josh applies it in the dashboard, (4) you re-verify
  with a read-only command that it landed. Never claim a key is "set" without a step-4 verification.
- You run **zero mutating `cloud` or `supabase` commands** in this task. `cloud environment:get` (read)
  is fine; `cloud environment:set` / any write verb is Josh's action, not yours.

## Standing decisions & discipline

- **Read-only against every system.** `cloud environment:get production --json` and `--json` reads of dev
  are fine. No `cloud environment:set`, no `supabase` migration/push commands, no writes to any database.
  This prompt's ENTIRE job is preparing values and verifying what Josh has applied.
- **Git is read-only.** You may read files to quote exact current values back to Josh. Do **not** commit,
  push, or touch git state as part of this run unless Josh explicitly asks you to annotate the runbook
  checkboxes afterward — and even then, follow the repo's commit discipline (new commit, never amend,
  never `--no-verify`). **NEVER run `git stash`** in this task or in any subagent prompt you write.
- Every value you hand Josh must be traceable to `prod-cutover-change-checklist.md` §C or
  `production-cutover.md` Phase 2 — if a key you think prod needs isn't named in either, say so and ask,
  don't add it unilaterally.
- Pin `model: sonnet` on any subagent you spawn for this task.

## Env-var parity first (read-only)

Before proposing any value, establish ground truth:

1. `cloud environment:get production --json` — capture production's CURRENT key set (it may be near-empty
   or stale from the 2026-05-21 pre-standalone deploy; either is expected, don't treat it as an error).
2. `cloud environment:get development --json` — capture dev's full key set + which are actually resolved
   (Cloud-injected vars like `AWS_*`/`REDIS_*` may not appear as literal env rows — note that distinction).
3. Diff against `.env.example` for anything neither env shows.
4. `scripts/env/compare-env.sh` already builds a presence matrix across local `.env` / dev / prod / the
   checklist doc — run it and use its output as a cross-check, not a replacement for step 1-3 (it's a
   heuristic keyword scan of the checklist prose, not exact).
5. Produce a **written key-by-key checklist** before setting anything: every key from §C, tagged
   `SAME` / `SPLIT` / `NEW` (the checklist's own tags — carry them, don't re-derive), current status in
   prod (`unset` / `set-wrong` / `set-correct`), and the exact value or procedure to fix it. Show this to
   Josh before Step 1 of the Steps section below. This is the artifact Josh reviews before touching the
   dashboard — do not skip to setting keys without it.

## Steps

Work through §C's own grouping. For each key, state the exact name, the exact value (or exact derivation
procedure for secrets you cannot know, e.g. "generate via `php artisan key:generate --show`"), the SAME
/ SPLIT / NEW tag, and get Josh's go-ahead before he applies it. Batch by group, not by individual key —
but do not batch across groups without checking in.

### DB → prod Supabase
- `DB_USERNAME=app_backend.edplucmvkcnokyygxqsb` — Supavisor tenant prefix, **prod** ref (not dev's
  `glncumufgaqcmqhzwrxm`).
- `DB_PASSWORD=<the password set in the GATE's `ALTER ROLE app_backend … LOGIN` step>` — you cannot know
  this value; ask Josh for it or confirm it's already in his secrets manager. Do not invent or reuse dev's.
- `DB_HOST=<prod Supavisor pooler host>`, `DB_PORT=5432` (session mode — same as dev), `DB_DATABASE=postgres`,
  `DB_CONNECTION=pgsql`, `DB_SEARCH_PATH`, `DB_SSLMODE=require` (these last four carry from dev unchanged).

### Queue / Redis — CRITICAL: stays `sync` at go-live
- `QUEUE_CONNECTION=sync` — **NOT** `redis`. Workers are a deliberate **post-cutover** step (Phase 5 /
  §F of the checklist), not part of go-live. If you find yourself about to recommend `redis` here, stop —
  that is the wrong phase. Flag loudly to Josh if any other doc or memory suggests otherwise; this
  decision is dated 2026-07-22 and is final for this prompt's scope.
- `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` — prod Redis instance; Laravel Cloud usually auto-injects
  these when Josh attaches the prod Redis resource, so verify presence via `environment:get` rather than
  hand-deriving values.
- `REDIS_CACHE_DB=1` — set **explicitly**, do not carry dev's effective value. Dev currently resolves
  cache to DB 0 (same DB as queue/Horizon) — `Cache::flush()` is a raw `FLUSHDB` and would wipe queued job
  state once workers exist post-cutover. Also confirm `REDIS_DB=0` and leave `REDIS_CACHE_LOCKS_DB` /
  `REDIS_SESSION_DB` on code defaults (4 / 2) unless Josh says otherwise.

### Cloudflare `SUBDOMAIN_KV` — a SEPARATE prod namespace
- `CLOUDFLARE_KV_NAMESPACE_ID=<NEW prod KV namespace, not dev's>` — if prod's `SyncSubdomainToKvJob` writes
  dev's namespace, the two environments clobber each other's `<handle>.partna.au` routing. Confirm with
  Josh that a distinct prod KV namespace has been created in Cloudflare before accepting an ID here.
- `CLOUDFLARE_ACCOUNT_ID` / `CLOUDFLARE_ZONE_ID` — SAME as dev (same `partna.au` zone).
- `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_CACHE_PURGE_TOKEN` — SPLIT: same Cloudflare account works, mint
  separate prod tokens for rotation + attribution.
- `CLOUDFLARE_SAAS_API_TOKEN` — SPLIT, required for the custom-domain path; optional
  `CLOUDFLARE_SAAS_CNAME_TARGET` (defaults `cname.partna.au`).

### Supabase identity — prod project's values, not dev's
- `SUPABASE_URL=https://edplucmvkcnokyygxqsb.supabase.co`
- `SUPABASE_ANON_KEY=<prod>`, `SUPABASE_SERVICE_ROLE_KEY=<prod>` — from the prod project's API settings.
- `SUPABASE_JWKS_URL=<prod project JWKS endpoint>` (Supabase → Project Settings → API/JWT).
- `SUPABASE_JWT_ISSUER=https://edplucmvkcnokyygxqsb.supabase.co/auth/v1` — **prod** ref. Pasting dev's
  `glncumufgaqcmqhzwrxm` issuer here 401s every authenticated request; this is the single easiest copy-paste
  mistake in this whole prompt because the two refs are both plausible-looking UUIDs.
- `SUPABASE_JWT_AUD=authenticated` — SAME.
- `SUPABASE_JWKS_FAIL_CLOSED=true` — SAME, boot-critical.
- `SUPABASE_REQUIRE_SESSION_ID=true` — SAME (default; keeps "sign out everywhere" working).
- `SUPABASE_EMAIL_HOOK_SECRET=<prod, format v1,whsec_...>` and `SUPABASE_AUTH_HOOK_SECRET=<prod>` — both
  boot-critical, both must **match** the secret registered on the prod Supabase project's Send Email Hook
  and MFA Verification Hook (that registration is a Phase-1 / §B step, not this one — confirm it has
  happened or will happen with the same secret value before treating this as done).

### Origins / URLs
- `PARTNA_FRONTEND_ORIGINS=https://partna.au,https://www.partna.au,https://app.partna.au` — **drop** dev's
  wider value (dev also lists `dev-app.partna.au` + `localhost:*`); do not paste dev's origins into prod.
- `FRONTEND_URL=https://app.partna.au` — SAME.
- `PARTNA_MARKETING_URL=https://partna.au` — SAME.
- `PARTNA_PUBLIC_DOMAIN=partna.au` — SAME, boot-critical.
- `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN` as appropriate — SAME.
- `APP_ENV=production` (NEW), `APP_URL=https://api.partna.au` (NEW), `APP_KEY=<generate fresh via
  php artisan key:generate --show — never reuse dev's>` (NEW), `APP_DEBUG=false` (SAME, boot-critical).

### Mail
- `RESEND_API_KEY` — SPLIT (same Resend account, separate key for attribution/rotation).
- `RESEND_WEBHOOK_SECRET` — NEW, its own secret; the bounce/complaint webhook must be registered at the
  prod URL with this secret.
- `SUPABASE_EMAIL_HOOK_SECRET` — see Supabase identity above. **Restate to Josh explicitly:** the secret
  alone is not enough — the Send Email Hook must ALSO be registered on the prod Supabase project (Phase 1
  / §B), pointing at `https://api.partna.au/api/internal/email-hooks/supabase`. If that registration
  hasn't happened yet, this key being set does nothing — flag it as a dependency, not a completed step.
- `MAIL_FROM_ADDRESS=hello@partna.au`, `MAIL_FROM_NAME`, `MAIL_MAILER`/host — SAME.

### Media
- A **separate prod R2/S3 bucket + its own keys**: `AWS_BUCKET` / `AWS_ENDPOINT` / `AWS_ACCESS_KEY_ID` /
  `AWS_SECRET_ACCESS_KEY` / `AWS_DEFAULT_REGION` / `AWS_URL` / `AWS_USE_PATH_STYLE_ENDPOINT` — Laravel Cloud
  usually auto-injects these when Josh attaches the prod bucket resource; verify via `environment:get`
  rather than hand-deriving values.
- `MEDIA_DISK_URL` — optional override, set only if the public media-CDN domain differs from the bucket's
  own URL (`config/filesystems.php` falls back to `AWS_*` otherwise).
- `FILESYSTEM_DISK` / `PARTNA_MEDIA_DISK` — SAME (disk selector, default `media`).

### Monitoring + third-party keys
- `NIGHTWATCH_TOKEN` — **do not hand-paste.** Connect the Laravel Cloud ↔ Nightwatch integration on the
  **prod** env (Cloud console → `partna` → `production` → Nightwatch → connect to the Partna Nightwatch
  app's Production environment). Verify with `cloud tinker production --code='echo strlen((string)
  config("nightwatch.token"));'` returning non-zero — **never** with `env("NIGHTWATCH_TOKEN")`, which
  reads empty even when working (the value is baked into cached config at build time by `optimize`).
  Because it's baked at build time, this connection must happen BEFORE the Phase 3 / §D push, or a
  redeploy is needed afterward for the boot guard to see it.
- `TURNSTILE_SECRET` / `TURNSTILE_SITE_KEY` (or `HCAPTCHA_*`) — NEW pair, domain-bound widgets need their
  own prod pair. `BOT_PROTECTION_*` flags — SAME.
- `HORIZON_DASHBOARD_USERNAME` / `HORIZON_DASHBOARD_PASSWORD` — **not a Phase-2 key.** The checklist scopes
  these to §F / Phase 5 (the post-cutover worker flip), and the `/horizon` gate
  (`AppServiceProvider::authorizeHorizonRequest`, pinned by `HorizonDashboardAuthTest`) **fails CLOSED — 403
  on any deployed env missing either credential** — so leaving them unset at go-live exposes nothing, it just
  makes the (empty, sync-mode) dashboard unreachable. Defer to `PROMPT-execute-cutover-phase-5-post-cutover.md`
  Step 1.3. Set early here only if Josh wants the dashboard usable the moment workers turn on; otherwise skip.
- `GOOGLE_MAPS_API_KEY` / `GOOGLE_MAPS_SERVER_API_KEY` — SPLIT, separate prod key with its own quota cap
  and billing alert. This is the only currently-uncapped paid API in the stack — confirm quota/billing is
  actually configured on the new key before treating this as done, not just that the key exists.
- `APIFY_TOKEN` — SPLIT, separate prod token. Core to pre-account signup scraping (Instagram, Google
  Business enrich, Menu) — unset and those paths silently no-op with no error.
- `CLOUDFLARE_API_TOKEN` / `CLOUDFLARE_CACHE_PURGE_TOKEN` (prod zone tokens) — see Cloudflare group above.

### Boot-critical flags (prod hard-fails to start without these — `AppServiceProvider::boot`)
- `APP_DEBUG=false` — SAME.
- `PARTNA_THROTTLE_ENABLED=true` — SAME, must not be false.
- `NOTIFICATIONS_EMAIL_ENABLED=true` — SAME; defaults FALSE, so unset means every email notification goes
  silently dark in prod.
- `INTERNAL_ENV_CHECK_TOKEN=<new prod secret>` — NEW, gates `EnvCheckController`; do not reuse dev's.
- `FEEDBACK_IP_HASH_PEPPER=<new prod random secret>` — NEW.
- `SUPABASE_JWKS_FAIL_CLOSED=true`, `SUPABASE_JWT_AUD=authenticated` — restated from the Supabase group
  above; both are boot-critical, not just identity hygiene.

### Deploy safety
- `migrate --force` stays **OFF** — the schema is applied Supabase-side (Phase 1's `psql` baseline), and
  the repo's Laravel-migration guard forbids Laravel migrations anyway. Confirm the `deployCommand` field
  in `cloud environment:get production --json` still shows only the commented-out
  `# php artisan migrate --force` line — if it shows anything live, STOP, this is a different problem than
  Phase 2 and must not be silently "fixed" by this prompt.

**Note on verification depth:** this prompt's job is to set these completely and verify each landed via
read-only checks — it is NOT the final proof they're correct. That's the Phase-4 launch-check gate
(`launch-check:env --target=launch`, `scripts/launch-check/`), which FAILS the build on `APP_DEBUG=on`,
`QUEUE_CONNECTION` wrong for the current phase, or any missing secret. Phase 2 sets; Phase 4 proves. Say
this explicitly in your final report so Josh doesn't treat this prompt's pass as the launch gate.

**Also required before Phase 3 (§D), not a Phase-2 env var but adjacent — confirm status, don't skip:**
Supabase Pro must be upgraded on the **prod** project BEFORE go-live (managed daily backups covering the
riskiest first days). This isn't a Laravel Cloud env key so it won't show in `environment:get` — ask Josh
directly whether it's done and record the answer in your report.

## PROD-SAFETY RULES (non-negotiable)

- Prod ref is `edplucmvkcnokyygxqsb`; dev ref is `glncumufgaqcmqhzwrxm` — verify every ref/URL you quote
  against this before handing it to Josh. Getting this backwards is the single most damaging mistake
  available in this task.
- You **prepare and verify** — Josh **applies** every secret in the Laravel Cloud dashboard. You never
  have dashboard access; do not describe yourself as having set anything.
- **Never push git.** This task's git footprint, if any, is read-only plus optional doc-checkbox
  annotations that Josh explicitly asks for — never a push, never a merge.
- **Never run `git stash`**, under any circumstance, in this session or in any subagent prompt you write.
- Do **not** set `QUEUE_CONNECTION=redis` at go-live under any framing — that is Phase 5 / §F, a distinct,
  later, deliberately-separate step with its own Horizon-provisioning precondition.
- Run zero mutating `cloud` or `supabase` commands. If you find yourself about to run one, stop and ask
  Josh to run it or explicitly confirm he wants you to.

## Stop and ask Josh if

- The env-parity diff surfaces a dev key with no obvious prod equivalent (a key not named in §C or
  Phase 2 at all) — do not guess a value or silently classify it as "not needed."
- Any secret's value is unavailable to you (you have no way to know a real secret — API keys, the DB
  password, hook secrets) and Josh hasn't supplied it yet.
- You're unsure whether a key is `SAME`, `SPLIT`, or `NEW` and the checklist's tag doesn't resolve it.
- The prod deploy command (`cloud environment:get production --json`) has drifted from the GATE's
  verified state — treat this as a blocker, not something to route around.
- Anything suggests the Send Email Hook / MFA Verification Hook registration (Phase 1 / §B) hasn't
  happened — several mail/identity keys are inert without it and you should say so rather than mark them
  done.

## When done — report

Produce the full key-by-key checklist (grouped as above) with, for each key: `SAME`/`SPLIT`/`NEW`, and
set/unset/verified status in prod. Plus:
- Confirmation (or lack of) that Supabase Pro is upgraded on the prod project.
- Explicit confirmation `QUEUE_CONNECTION=sync` is what's set (not `redis`).
- Explicit confirmation `REDIS_CACHE_DB=1` is set and distinct from `REDIS_DB=0`.
- Explicit confirmation the Nightwatch integration (not a hand-pasted token) is connected, verified via
  `config()` not `env()`.
- Any key you could not resolve, with a plain statement of what's blocking it.
- A go/no-go recommendation for Phase 3 (branch push / go-live) — no-go if any boot-critical key is
  missing, the DB password is unset, or the deploy-command drift check failed.

=== PROMPT END ===
```
