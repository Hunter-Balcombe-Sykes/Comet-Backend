# Secret Rotation — Operator Runbook

Partially addresses launch-readiness checklist item **TECH-5**
(`docs/checklists/launch-readiness-checklist.md`). This runbook documents an
**accepted-downtime** rotation procedure for the Supabase hook secrets
(Procedure A) and the Supabase service role key (Procedure D) — it does not
close TECH-5 outright. Zero-downtime rotation (dual-secret support in
`VerifySupabaseHookSignature`) remains outstanding for the hook secrets; see
the "Why not zero-downtime" note under Procedure A. The service role key has
no zero-downtime option to defer — Supabase itself invalidates the old key
immediately on regeneration (see Procedure D).

## Scope

Every shared-secret credential the backend holds, what breaks when it's rotated
badly, and the exact procedure to rotate it safely. "Rotated badly" here means:
the value is changed on one side (Laravel Cloud env var, or the external
dashboard/service) but not the other, so the two sides disagree. The table
below is grouped by credential shape, because "rotate it safely" means
something different for a webhook HMAC secret than for a vendor API key —
each group links to the procedure that actually applies. Every config key
below was verified against the codebase on 2026-07-20 (`grep` cited per
group); every "overlap window" claim was verified against the vendor's own
docs where available — rows that couldn't be verified say **UNKNOWN** rather
than guess.

### Group 1 — Bidirectional / auth-adjacent (Supabase Auth Hooks)

Two-sided HMAC secrets: Supabase signs with one copy, `VerifySupabaseHookSignature`
verifies with the other. See **Procedure A**.

| Secret | Env var | Config key | Consumer | Blast radius if mismatched |
|---|---|---|---|---|
| Supabase Auth Hook secret | `SUPABASE_AUTH_HOOK_SECRET` | `services.supabase.auth_hook_secret` | `VerifySupabaseHookSignature` via the `supabase.auth-hook` middleware alias, gating `POST /api/webhooks/supabase/auth/mfa-verification` | **Hard fail, user-facing.** Every MFA verify attempt (TOTP/phone/WebAuthn) gets rejected by Supabase because our hook returns `401`. |
| Supabase Send Email Hook secret | `SUPABASE_EMAIL_HOOK_SECRET` | `services.supabase.email_hook_secret` | `VerifySupabaseHookSignature` via the `supabase.email-hook` middleware alias, gating `POST /api/internal/email-hooks/supabase` | **Hard fail, user-facing.** All auth emails (password reset, magic link, signup confirm, invite) stop being delivered — see `SupabaseEmailHookController::resolveMailable()`, which sends every one of these through the Resend pipeline (Group 4 below) once the hook itself verifies. |

### Group 2 — Supabase-issued backend credential

A credential Supabase issues that *we* present outbound as a Bearer token —
not a two-sided HMAC secret, but not a vendor API key either (it grants admin
authority over the same Supabase project our JWTs come from). See **Procedure D**.

| Secret | Env var | Config key | Consumer | Blast radius if mismatched |
|---|---|---|---|---|
| Supabase service role key | `SUPABASE_SERVICE_ROLE_KEY` | `supabase.service_role_key` (`config/supabase.php:38`) | `App\Services\Auth\SupabaseAdminService` (GoTrue Admin API — `findUserByEmail` during signup-availability checks, `createUser` for server-side user creation, `unenrollMfaFactor` for MFA factor removal) and `App\Services\User\AccountDeletionService::deleteSupabaseAuthUser` (`AccountDeletionService.php:941` — step 1 of the hard-delete purge, deletes the Supabase Auth identity before any DB row is touched) | **Account-takeover-capable — the highest blast radius in this table.** Presented as a Bearer token to Supabase's GoTrue Admin API, this key alone can create arbitrary confirmed auth users, look up any user by email, and force-delete MFA factors or auth identities for any user — it bypasses all normal auth. A **leak** is an active-compromise incident (see Emergency rotation below), not a routine rotation. A **mismatch** (env var stale after a Dashboard regen) makes every Admin API call `401`: `MfaController::destroy` can't unenroll a factor (surfaces as `502` to the user), and `AccountDeletionService::purge()` step 1 fails closed by design — it logs `EVENT_PURGE_FAILED`/`supabase_deletion_failed`, reports to Nightwatch, and does **not** hard-delete the DB row, so scheduled GDPR purges silently stall (retried daily) until the key is fixed. `EnvCheckService::REQUIRED` treats this key as required — the app is meant to refuse to boot healthy without it. |

### Group 3 — Infrastructure tokens (Cloudflare, incl. R2 storage)

Cloudflare allows multiple live tokens for the same account/zone at once — no
downtime needed to rotate. See **Procedure B**.

| Secret | Env var | Config key | Consumer | Blast radius if mismatched |
|---|---|---|---|---|
| Cloudflare API token (general) | `CLOUDFLARE_API_TOKEN` | `services.cloudflare.api_token` | `CloudflareKvService` (subdomain routing table) and the fallback path in `CloudflareCustomHostnameService` | **Degrades.** KV writes go through `SyncSubdomainToKvJob`, which has a retry policy — a bad token means those retries fail and eventually the job gives up, so subdomain routing entries go stale rather than 500ing a request. |
| Cloudflare cache-purge token | `CLOUDFLARE_CACHE_PURGE_TOKEN` | `services.cloudflare.cache_purge_token` | `CloudflarePurgeService`, called only by `CloudflareCachePurgeJob` | **Degrades.** Purges silently fail; the edge keeps serving stale content until Cloudflare's own TTL expires it. No user-facing error. |
| Cloudflare SaaS API token | `CLOUDFLARE_SAAS_API_TOKEN` | `services.cloudflare.saas_api_token` | `CloudflareCustomHostnameService` (custom-hostname / DV-cert provisioning for user-connected domains) | **Degrades.** The service falls back to `api_token` when `saas_api_token` is unset or fails, so a bad SaaS token alone doesn't hard-fail — it just means custom-hostname calls run under the general token's (probably insufficient) scopes until fixed. |
| Media storage (R2) access key pair | `MEDIA_DISK_KEY` / `MEDIA_DISK_SECRET` (falls back to `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` when unset) | `filesystems.disks.media.key` / `filesystems.disks.media.secret` (`config/filesystems.php:73-74`) | Laravel's S3-compatible filesystem driver via the `media` disk — every user-media write (profile photos, gallery, logos, menu images) | **Degrades new writes hard, doesn't touch already-served media.** The `media` disk sets `'throw' => true`, so new uploads/deletes fail immediately (surfaced to the user) on a bad key. Already-uploaded media keeps rendering fine — it's served from the public CDN URL (`MEDIA_DISK_URL`), not an authenticated S3 call. |

### Group 4 — Outbound vendor API keys

Single-sided: we present a credential the vendor issued, no HMAC verification
on our end. See **Procedure E** — overlap capability is confirmed per-vendor
there; several are genuinely **UNKNOWN**, marked as such rather than guessed.

| Secret | Env var | Config key | Consumer | Blast radius if mismatched |
|---|---|---|---|---|
| Postmark API key | `POSTMARK_API_KEY` | `services.postmark.key` | Laravel's Postmark mail transport (`mail.mailers.postmark`) — active only when `MAIL_MAILER=postmark` or as a fallback inside the `roundrobin`/`failover` mailer stacks | **Degrades.** Outbound mail via this transport fails; `MAIL_MAILER=resend` is the deployed default (`.env.example`), so this is presently a fallback path, not the primary one. |
| Resend API key | `RESEND_API_KEY` | `services.resend.key` | Laravel's Resend mail transport (`mail.mailers.resend`) — **the active default mailer**; also the pipeline every Supabase auth email rides once `SupabaseEmailHookController` accepts the hook (Group 1 above) | **Hard fail, user-facing.** All outbound mail stops, including auth email delivered via the Send Email Hook — same user-facing symptom as a bad `SUPABASE_EMAIL_HOOK_SECRET`, different root cause. |
| Twitch client secret | `TWITCH_CLIENT_SECRET` | `services.twitch.client_secret` | `StreamingTokenManager` (OAuth client-credentials token exchange) → `TwitchApiClient` (Helix streams endpoint, live-status check for connected Twitch links) | **Degrades.** Token refresh fails (`streaming.auth_failure` logged), live-status checks for Twitch-connected users go stale/unavailable; no hard user-facing error. |
| Kick client secret | `KICK_CLIENT_SECRET` | `services.kick.client_secret` | `StreamingTokenManager` → `KickApiClient` (same shape as Twitch) | **Degrades**, same as Twitch. |
| Apify token | `APIFY_TOKEN` | `services.apify.token` | `InstagramScraper`, `GoogleBusinessApifyScraper`, `GoogleMenuImagesScraper`, `MenuApifyScraper` (Instagram profile connect, Google Business enrichment, Google-photo menu scraping) | **Degrades, scoped to the action.** Each caller returns `null`/catches the failure — the specific connect/refresh/scrape attempt fails for that user; the rest of the app is unaffected. |
| Mistral API key | `MISTRAL_API_KEY` | `services.mistral.key` | `MenuAiExtractor` (hosted OCR stage of the automatic Google-photo menu scan) | **Degrades, silent.** `MenuAiExtractor::configured()` gates on both this and the DeepSeek key being non-empty; a present-but-wrong key still fails the OCR call, and the job exits without extracting a menu — same "not configured" contract as a missing key. |
| DeepSeek API key | `DEEPSEEK_API_KEY` | `services.deepseek.key` | `MenuAiExtractor` (structuring stage — OCR text → menu items) | **Degrades, silent** — same failure shape as Mistral above. |
| Google Maps server API key | `GOOGLE_MAPS_SERVER_API_KEY` | `services.google_maps.server_api_key` | `GoogleBusinessService` (Places Details enrichment) | **Degrades.** Details fetches fail and log a warning (`google_business.details_fetch_failed`); Google Business enrichment runs without that data. **Not the same credential as `GOOGLE_MAPS_API_KEY`** (the public, referrer-restricted client key) — that one is intentionally handled by the TECH-5 checklist note below, not this table, because its safety model is a GCP referrer restriction, not a rotation procedure. |
| Slack bot user OAuth token | `SLACK_BOT_USER_OAUTH_TOKEN` | `services.slack.notifications.bot_user_oauth_token` | **No consumer today.** Defined in `config/services.php:63` alongside a default channel, but nothing in `app/` reads it (verified 2026-07-20 — `grep -rn bot_user_oauth_token app/` returns only the config file itself). Horizon's own Slack notifications use an unrelated, already-live mechanism (`HORIZON_NOTIFICATION_SLACK_WEBHOOK`, `config/horizon.php:49`). | **None via this codebase today** — nothing calls it, so a mismatch here breaks nothing in-app. The raw token is still a live Slack credential at Slack's end with whatever scopes were granted at install; if it leaks, revoke it in the Slack app's OAuth & Permissions page regardless of our lack of usage. |
| Nightwatch token | `NIGHTWATCH_TOKEN` | `nightwatch.token` | The Laravel Nightwatch package itself — ships every request/exception/slow-query/job event to the local ingest agent | **Degrades, not user-facing** — the app keeps serving requests fine, but Nightwatch stops receiving telemetry, so exception/slow-route/slow-job visibility goes dark. This project treats Nightwatch as a primary debugging surface (see this repo's `CLAUDE.md`), so a silent gap here is an operational risk, not cosmetic. |

### Group 5 — Inbound-verification secrets (CAPTCHA)

The opposite direction from Group 4: we send the vendor a visitor's token +
our secret, and the vendor tells us pass/fail. A wrong secret makes the
*verification call itself* fail — what happens next depends on
`partna.bot_protection.mode` and `fail_open`. See **Procedure F**.

| Secret | Env var | Config key | Consumer | Blast radius if mismatched |
|---|---|---|---|---|
| Turnstile secret | `TURNSTILE_SECRET` | `partna.bot_protection.drivers.turnstile.secret` | `TurnstileProvider` via `CaptchaManager`/`VerifyBotToken` middleware (public mutation endpoints — see `PublicReportController`, `ContentReportService`) | A wrong secret makes Cloudflare's `siteverify` call itself error, which `VerifyBotToken` treats as a verification failure. In `shadow` mode (deployed default per `.env.example`) that's logged only (`bot_protection.shadow_reject`) and the request proceeds. In `enforce` mode it depends on `BOT_PROTECTION_FAIL_OPEN` (default `true` pre-pilot): fail-open lets the request through — bot protection silently goes dark, not user-facing; fail-closed would `503`/reject every gated public-mutation request. Only one driver is active at a time (`partna.bot_protection.driver`). |
| hCaptcha secret | `HCAPTCHA_SECRET` | `partna.bot_protection.drivers.hcaptcha.secret` | `HCaptchaProvider` via the same `CaptchaManager`/`VerifyBotToken` path (alternate driver, mutually exclusive with Turnstile) | Same failure shape as Turnstile above — dormant unless `partna.bot_protection.driver=hcaptcha`. |

### Group 6 — Internal peer tokens

One peer, no vendor dashboard, no HMAC machinery — see **Procedure C**.
Extended 2026-07-20 with the brand-scan token (same shape as the logo
processor token — both are Partna-owned Cloudflare Workers) and the Horizon
dashboard password (peer = the human operators who need dashboard access).

| Secret | Env var | Config key | Consumer | Blast radius if mismatched |
|---|---|---|---|---|
| Logo processor token | `PARTNA_LOGO_PROCESSOR_TOKEN` | `partna.logo_removal.token` | `LogoProcessorClient`, called from `ProcessLogoVariantsJob` | **Degrades.** `LogoProcessorClient` throws on any transport/auth failure, but the job catches processor failures and falls back to the standard WebP pipeline — a logo never fails to appear, it just skips background removal. |
| Brand scan token | `PARTNA_BRAND_SCAN_TOKEN` | `partna.brand_scan.token` | `BrandScanClient`, called from `AnalyzeConnectionWebsitesJob` — presents this token to Partna's own `partna-brand-scan` Cloudflare Worker | **Fails closed, feature-scoped.** A bad token makes the Worker call return `401`, which `BrandScanClient` turns into a `BrandScanException('fetch', ...)`; the job logs it and the design-preset pipeline "abstains" (same safe-unconfigured contract as an empty token). No user-facing error, no crash. |
| Internal env-check token | `INTERNAL_ENV_CHECK_TOKEN` | `partna.internal_env_check_token` | `EnvCheckController` (`GET /api/internal/env-check`) | **Endpoint-local only.** A mismatch just 403s the diagnostic endpoint itself (empty token 503s it instead). Nothing else in the app reads this value. |
| Horizon dashboard password | `HORIZON_DASHBOARD_PASSWORD` (paired with `HORIZON_DASHBOARD_USERNAME`, not a secret) | `horizon.dashboard.password` | `AppServiceProvider::authorizeHorizonRequest()` — HTTP Basic auth gate on the `/horizon` dashboard in production | **Optional, off by default** (`# HORIZON_DASHBOARD_PASSWORD=` commented out in `.env.example`) — production Horizon stays sealed (`403`) until both vars are set. If set and then changed on only one side, whoever needs dashboard access gets `401` until told the new value; there's no other blast radius, since nothing in this app reads the password besides the gate itself. |

**Not in this table on purpose:**

- **Supabase JWT verification.** User-facing auth (`VerifySupabaseJwt`)
  validates JWTs against Supabase's JWKS endpoint (`SUPABASE_JWKS_URL`, cached
  per `SUPABASE_JWKS_CACHE_SECONDS`, fail-closed per `SUPABASE_JWKS_FAIL_CLOSED`
  — see `config/supabase.php`). That's asymmetric-key verification against keys
  Supabase rotates on its own; we don't hold a shared secret for it and there
  is no rotation step for us to run here. If you're looking for "how do we
  rotate the JWT signing secret," stop — that's Supabase's problem, not ours.
- **`GOOGLE_MAPS_API_KEY`** (the public, client-exposed Maps key — distinct
  from `GOOGLE_MAPS_SERVER_API_KEY` in Group 4 above). Its safety model is a
  GCP HTTP-referrer restriction, not a rotation procedure with two sides to
  keep in sync — it's already covered by its own dedicated note on the TECH-5
  checklist item below. Documenting it here would duplicate, not extend, that
  note.
- **`DB_PASSWORD` and `REDIS_PASSWORD`.** Both are live (`database.php`), but
  they're connection credentials for managed infrastructure (Supabase
  Postgres via the `app_backend` role; the Cloud-managed Redis instance), not
  the webhook/API-token "two independently-updated sides" pattern this
  runbook is built around. Rotating `DB_PASSWORD` in particular means running
  `ALTER ROLE app_backend WITH LOGIN PASSWORD '<new>'` against a **live**
  Supabase Postgres instance behind Supavisor connection pooling — a
  meaningfully different, higher-stakes operation than swapping an env var,
  and one this doc doesn't have a verified-safe procedure for. Out of scope
  here; revisit as its own runbook if this becomes an operational need.
- **`APP_KEY`.** Laravel's own encryption key (session/cookie encryption,
  encrypted casts). Framework-internal, not a secret shared with any external
  peer — rotating it is a distinct, disruptive operation (invalidates every
  existing encrypted value) that doesn't fit this doc's model either.

## Universal rules

1. **Never edit `.env` directly on a deployed environment.** Laravel Cloud env
   vars are the source of truth for `development` and `production`. `.env` only
   matters for local dev.
2. **Every credential name gets a placeholder entry in `.env.example`.** If
   you're introducing a new secret, add it there (empty value) before anyone
   sets it on Cloud.
3. **A config change needs a redeploy to take effect.** Laravel Cloud caches
   config (`config:cache`) as part of the build; setting an env var alone does
   not hot-reload `config('services.supabase.auth_hook_secret')` on running
   workers. Deploy after changing any var in this doc.
4. **`development` serves both hostnames.** Per this repo's `CLAUDE.md`, the
   `development` Laravel Cloud environment currently answers for **both**
   `dev-api.partna.au` and `api.partna.au`, backed by the dev Supabase project
   `glncumufgaqcmqhzwrxm`. Rotating a secret tied to that project (both
   Supabase hook secrets, and the Supabase service role key) affects
   production traffic too, not just dev traffic — there is no isolated prod
   rotation right now. Plan the downtime window accordingly.

## Procedure A — Supabase hook secrets (accepted brief downtime)

Applies to `SUPABASE_AUTH_HOOK_SECRET` and `SUPABASE_EMAIL_HOOK_SECRET`.

**Accepted tradeoff:** rotating either of these means a deliberate window
(roughly 30–60 seconds — the time between saving the new secret in the
Supabase Dashboard and the Laravel Cloud deploy finishing) where that hook
returns `401` to every real Supabase call. For the auth hook that's MFA verify
attempts; for the email hook that's every outbound auth email. This is **not**
zero-downtime and the runbook is not pretending otherwise. Schedule it for a
low-traffic window and accept the blip — see the note at the end of this
section for why we didn't engineer around it.

1. Schedule a rotation window. This app is pre-beta with no customers, so
   there's no measured traffic floor to plan around — in practice
   "low-traffic" means "outside any active user session (yours or a
   teammate's) exercising an auth flow: MFA enroll/verify, password reset,
   magic link, signup confirm, invite." None of the scheduled jobs in
   `routes/console.php` call either hook route — the daily/weekly cron work
   there (soft-delete purges, notification sweeps, KV backfills, etc.,
   clustered 03:00–04:40 UTC) is unrelated — so there's no scheduled-job
   window to dodge either. If you're not actively testing an auth flow right
   now, the window is open.
2. In the Supabase Dashboard for the target project → **Authentication →
   Hooks**, open the relevant hook (**MFA Verification Hook** for the auth
   secret, **Send Email Hook** for the email secret) and generate a new
   secret.
3. Save it in the Dashboard. From this moment, Supabase signs with the new
   secret and our still-old env var will reject every call — the downtime
   window starts here.
4. Immediately set the matching env var (`SUPABASE_AUTH_HOOK_SECRET` or
   `SUPABASE_EMAIL_HOOK_SECRET`) on the Laravel Cloud environment to the same
   value.
5. Deploy. `cloud deploy partna development` (or `production` — see rollout
   order below). The downtime window ends when the new build is live.
6. Verify (see §7 below): trigger a real hook, confirm `2xx`, confirm
   `signature_failed` log events have stopped.

**Rollback:** if verification fails, revert both sides together — set the
Dashboard secret back to the old value AND the env var back to the old value,
then redeploy. Reverting only one side re-creates the same downtime window.

**Rollout order:** dev (`glncumufgaqcmqhzwrxm`) first, soak, then prod
(`edplucmvkcnokyygxqsb`) — same pattern as `docs/auth/mfa-foundation-runbook.md`
step 7–8. Given the environment reality in rule 4 above, rotating the dev
project's secret already affects both public hostnames, so "soak on dev" here
really means "soak, then repeat the same steps against the prod Supabase
project once it's live and taking traffic independently."

**Why not zero-downtime:** true zero-downtime rotation would need the
middleware to accept two valid secrets simultaneously (a "current" and a
"previous," relaxing `VerifySupabaseHookSignature`'s single-secret check
during a transition window). That was considered and deliberately deferred —
this doc is scoped to the runbook only, not to shipping dual-secret support.
The accepted downtime is cheap pre-beta, and an unbounded previous-secret
window is a real risk of its own (a leaked "previous" secret stays valid until
someone remembers to clear it). Revisit if uptime requirements tighten.

## Procedure B — Cloudflare tokens (degrade, not fail)

Applies to `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_CACHE_PURGE_TOKEN`,
`CLOUDFLARE_SAAS_API_TOKEN`, and the R2 storage pair `MEDIA_DISK_KEY` /
`MEDIA_DISK_SECRET`.

Cloudflare allows multiple live tokens for the same account/zone at once. That
gives a real overlap window with **no code change and no downtime** — do not
use the Supabase pattern here.

1. In the Cloudflare dashboard, create a **new** scoped API token alongside
   the existing one. Required scopes, verified against
   `app/Services/Cloudflare/*` as of this writing:
   - **Cache-purge token:** `Zone.Cache Purge` on the `partna.au` zone (see
     comment above `cache_purge_token` in `config/services.php`).
   - **SaaS token:** `Zone:SSL`, `Certificates:Edit`, plus `Zone:Read` on the
     `partna.au` zone (see comment above `saas_api_token` in
     `config/services.php`).
   - **General API token:** used for Workers KV writes
     (`CloudflareKvService`) and as the SaaS-token fallback
     (`CloudflareCustomHostnameService`). Its exact scope set is **not**
     pinned anywhere in code or config comments — when rotating it, read the
     existing token's permission list in the Cloudflare dashboard (Manage
     Account → API Tokens) and replicate it on the new token rather than
     guessing. Don't narrow or widen scope as part of a routine rotation.
   - **R2 access key pair:** created under R2 → **Manage API Tokens**, a
     separate token list from the general Account API Tokens above. R2
     tokens are independent, individually-revocable objects — the same shape
     as the other three rows here — so the same create-alongside/revoke-last
     pattern is expected to apply. Cloudflare's R2 docs don't explicitly
     state an overlap guarantee the way the general Account API Token docs
     do, so **verify by creating the second token and confirming both
     `Key`/`Secret` pairs authenticate** before revoking the first, rather
     than assuming.
2. Set the matching env var (`CLOUDFLARE_API_TOKEN` /
   `CLOUDFLARE_CACHE_PURGE_TOKEN` / `CLOUDFLARE_SAAS_API_TOKEN` /
   `MEDIA_DISK_KEY` + `MEDIA_DISK_SECRET`) on Laravel Cloud to the new
   token/pair.
3. Deploy.
4. Verify a write succeeds under the new token (see §7) — for the R2 pair,
   upload a test media asset and confirm it lands in the bucket.
5. **Only after verification passes**, revoke the old token in the Cloudflare
   dashboard.

**Ordering matters: revoke last, never first.** Revoking the old token before
the new one is confirmed working turns this into the same hard-fail-or-degrade
scenario as Procedure A, for no reason — the whole point of Cloudflare
allowing multiple tokens is to avoid that.

## Procedure C — plain shared-secret tokens

Applies to `PARTNA_LOGO_PROCESSOR_TOKEN`, `PARTNA_BRAND_SCAN_TOKEN`,
`INTERNAL_ENV_CHECK_TOKEN`, and `HORIZON_DASHBOARD_PASSWORD`. All are simple
bearer/header/Basic-auth values with exactly one peer that must agree on the
value — there's no dashboard-managed secret and no multi-token support on the
peer side, so treat this like Procedure A's "both sides must match" logic but
without the hook-signature machinery.

1. **Update the peer first:**
   - `PARTNA_LOGO_PROCESSOR_TOKEN`'s peer is the `partna-logo-processor`
     Cloudflare Worker + Container — update its configured expected token to
     the new value.
   - `PARTNA_BRAND_SCAN_TOKEN`'s peer is the `partna-brand-scan` Cloudflare
     Worker (Browser Run) — update its configured expected token the same
     way.
   - `INTERNAL_ENV_CHECK_TOKEN`'s peer is whoever calls
     `GET /api/internal/env-check` with the `X-Internal-Token` header
     (a human operator or an external monitor) — tell them the new value.
   - `HORIZON_DASHBOARD_PASSWORD`'s peer is every human who currently has
     `/horizon` dashboard access — tell them the new value (and the paired
     `HORIZON_DASHBOARD_USERNAME`, if that's changing too).
2. Set the env var(s) on Laravel Cloud to the new value.
3. Deploy.
4. Verify:
   - Logo token: trigger a logo upload with `PARTNA_LOGO_REMOVAL_ENABLED=true`
     and confirm the processor call succeeds (check Cloud logs for
     `ProcessLogoVariantsJob` — no fallback-to-WebP-pipeline log entry).
   - Brand scan token: trigger a connection website analysis (see
     `AnalyzeConnectionWebsitesJob`) and confirm the Worker call succeeds —
     no `BrandScanException('fetch', ...)` in Cloud logs.
   - Env-check token: `curl -i -H "X-Internal-Token: <new-value>" https://dev-api.partna.au/api/internal/env-check` and confirm `200`.
   - Horizon password: `curl -i -u "<user>:<new-password>" https://dev-api.partna.au/horizon` and confirm it's not `401`.
5. Remove/rotate the old value at the peer once the new one is confirmed
   working (there's no "old value still accepted" grace period on either
   side, so this step is really just housekeeping, not a safety step).

## Procedure D — Supabase service role key (accepted brief downtime, no overlap)

Applies to `SUPABASE_SERVICE_ROLE_KEY`.

Mechanically different from Procedure A (this isn't a webhook HMAC secret we
verify inbound — it's a Supabase-issued credential we present outbound as a
Bearer token to the GoTrue Admin API), but the same accepted-downtime shape
applies, and for the same underlying reason: **verified against Supabase's
own troubleshooting docs, regenerating this key has no overlap window.**
Rotating the legacy service role key means rotating the project's JWT secret
as a whole, and Supabase's docs are explicit: *"Once the JWT secret is
regenerated, all current API secrets will be immediately invalidated, and all
connections using them will be severed."* There is no "old key still works
for N minutes" grace period on Supabase's side for this credential, unlike
the Cloudflare tokens in Procedure B.

(Supabase's *newer* API key system — `sb_secret_...` publishable/secret keys
— does support an overlap window, creating new keys alongside old ones that
stay valid until manually disabled. This app is not on that system yet: the
env var name, the `apikey`/`Bearer` header pair in `SupabaseAdminService`,
and the direct `config('supabase.service_role_key')` wiring all match the
legacy JWT-derived `service_role` key shape. Migrating to the new key system
would change this procedure — that's a separate decision, not covered here.)

1. Schedule a rotation window using the same low-traffic guidance as
   Procedure A step 1 — this app is pre-beta with no measured traffic floor,
   so "low-traffic" means "not actively testing MFA enroll/unenroll, an
   account deletion, or the signup-availability check right now."
2. In the Supabase Dashboard for the target project → **Project Settings →
   API**, regenerate the JWT secret (this rotates `anon_key` and
   `service_role_key` together — there's no way to rotate `service_role_key`
   alone on the legacy key system). **The moment you save this, the
   downtime window starts** — the old key stops working immediately, per the
   Supabase behavior quoted above.
3. Immediately set `SUPABASE_SERVICE_ROLE_KEY` on the Laravel Cloud
   environment to the new value. (If `SUPABASE_ANON_KEY` is also consumed
   elsewhere in this app, update it in the same pass — regenerating the JWT
   secret rotates both.)
4. Deploy. `cloud deploy partna development` (or `production`). The downtime
   window ends when the new build is live.
5. Verify (see §7 below): trigger an MFA factor unenroll and confirm it
   succeeds (no `502` from `MfaController`); trigger the signup-availability
   check and confirm it responds normally; check Cloud logs for the absence
   of `Supabase admin:` / `Supabase auth user deletion failed` error entries.

**Rollback:** if verification fails, you cannot "revert" a JWT secret
regeneration — the old key is already invalid per Supabase's behavior above.
Regenerate again in the Dashboard (this produces a *new* new key, not the old
one back) and repeat steps 3–5.

**Rollout order:** dev (`glncumufgaqcmqhzwrxm`) first, soak, then prod
(`edplucmvkcnokyygxqsb`) — same environment-reality caveat as Procedure A
rule 4 applies here too.

## Procedure E — Outbound vendor API keys (regenerate → swap → redeploy)

Applies to `POSTMARK_API_KEY`, `RESEND_API_KEY`, `TWITCH_CLIENT_SECRET`,
`KICK_CLIENT_SECRET`, `APIFY_TOKEN`, `MISTRAL_API_KEY`, `DEEPSEEK_API_KEY`,
`GOOGLE_MAPS_SERVER_API_KEY`, `SLACK_BOT_USER_OAUTH_TOKEN`, and
`NIGHTWATCH_TOKEN`. The generic shape is simple — regenerate at the vendor,
update the env var, redeploy — but whether you get a safe overlap window
varies by vendor. Confirmed per-vendor (2026-07-20, against each vendor's own
docs where available):

- **Postmark — overlap YES.** A server can hold up to 3 API tokens
  simultaneously; generating a new one doesn't touch existing ones. Delete
  the old token explicitly once the new one is confirmed working.
- **Resend — overlap YES.** Keys are independent named objects; the old key
  stays valid until you explicitly delete it.
- **Twitch — overlap NO.** Twitch's own developer docs: generating a new
  client secret invalidates the previous one immediately. Treat like
  Procedure A — regenerate and update the env var back-to-back, accept a
  brief downtime window for streaming-status refreshes.
- **Kick — overlap UNKNOWN.** Could not confirm from Kick's public developer
  documentation whether regenerating a client secret allows an overlap
  window or invalidates immediately. Check the Kick Developer Portal at
  rotation time; until confirmed, rotate as if it's NO-overlap (Twitch's
  pattern), for safety.
- **Apify — overlap YES (24h).** Apify's documented token-rotation flow
  keeps the old token active for 24 hours after generating the new one,
  specifically to give you time to update integrations.
- **Mistral — overlap YES.** La Plateforme supports multiple named keys;
  creating a new one and deleting the old one are separate, independent
  actions.
- **DeepSeek — overlap YES.** Same shape as Mistral — multiple named keys,
  deletion is a separate explicit action.
- **Google Maps server key — overlap YES.** Google Cloud's documented API
  key rotation flow explicitly supports both old and new keys being
  accepted during a transition window, plus a 30-day soft-delete grace
  period after that.
- **Slack bot token — overlap UNKNOWN**, and moot today (Group 4 above: zero
  code consumers). If this is ever wired up, note that Slack's documented
  token-rotation feature applies to the newer short-lived-token flow, not
  necessarily the classic long-lived `xoxb-` token this env var name implies
  — re-verify before assuming either way.
- **Nightwatch token — overlap UNKNOWN.** Could not confirm from Nightwatch's
  docs whether generating a new project token invalidates the old one.
  Check the "Environments" tab in the Nightwatch dashboard at rotation time.

Steps:

1. Generate a new key/secret/token in the vendor's dashboard.
2. Where overlap is confirmed **YES**: update the env var on Laravel Cloud,
   deploy, verify the new key works (see §7), *then* delete/revoke the old
   key at the vendor.
3. Where overlap is **NO** or **UNKNOWN**: treat as Procedure A's downtime
   shape — regenerate and update the env var as close together as possible,
   deploy immediately, and accept the brief window where calls to that
   vendor fail. Schedule for low-traffic per Procedure A step 1's guidance.
4. Deploy.
5. Verify per the blast-radius column in Group 4 above (e.g. trigger an
   Instagram connect for Apify, a menu photo scan for Mistral/DeepSeek, a
   test email send for Postmark/Resend).

## Procedure F — Inbound-verification secrets (CAPTCHA)

Applies to `TURNSTILE_SECRET` and `HCAPTCHA_SECRET`.

These aren't compared byte-for-byte against anything we store — we send the
secret to the vendor's `siteverify` endpoint alongside the visitor's token on
every request, and the vendor tells us pass/fail. There's no "our copy vs.
their copy disagree" mismatch state the way there is for Procedures A/B/C —
only "our copy is wrong, so every verification call fails," which is exactly
what a leaked-and-revoked secret looks like too. That makes emergency and
routine rotation the same shape here.

- **Turnstile — overlap YES (2 hours).** Cloudflare's dashboard "Rotate
  Secret Key" defaults to a 2-hour grace period where both the old and new
  secret validate successfully, specifically to cover exactly this
  env-var-update gap.
- **hCaptcha — overlap NO** (standard/free/pro tiers). hCaptcha's own docs:
  generating a new secret immediately invalidates the old one. (Enterprise
  accounts can provision multiple secrets per site key — not applicable
  unless this app is ever upgraded to that tier.)

1. Rotate the secret in the relevant vendor dashboard (Cloudflare Turnstile
   → widget → Settings → Rotate Secret Key; hCaptcha → account Settings →
   Generate New Secret).
2. Set the matching env var (`TURNSTILE_SECRET` / `HCAPTCHA_SECRET`) on
   Laravel Cloud.
3. Deploy — for Turnstile, inside the 2-hour overlap window; for hCaptcha,
   as fast as possible since there's no grace period.
4. Verify: submit a real form through the gated endpoint (e.g.
   `PublicReportController`) and confirm it succeeds, then grep Cloud logs
   for `bot_protection.shadow_reject` / `bot_protection.fail_open` /
   rejection events and confirm they've stopped for this driver.
5. For Turnstile, no separate revoke step is needed — the old secret expires
   on its own at the end of the 2-hour window. For hCaptcha, the old secret
   is already dead the moment you generated the new one.

## Emergency rotation (suspected compromise)

If a secret in this doc may have leaked (committed to a public repo, exposed
in a log, shared with someone who shouldn't have it), do not wait for a
low-traffic window and do not follow the overlap-friendly ordering above.

1. **Revoke the old credential at the source first** — this is the opposite
   order from any procedure above that relies on an overlap window (B, C, and
   the overlap-YES rows in E/F), and deliberately so. For a leaked credential
   the priority is cutting off the leaked value immediately, not avoiding
   downtime. Revoke it in the Supabase Dashboard / Cloudflare dashboard / at
   the vendor / at the peer service right away.
2. Generate and set the new value everywhere it's needed (Dashboard/peer +
   Laravel Cloud env var).
3. Deploy immediately.
4. Verify per §7, then do a full incident review per
   `docs/runbooks/drills/README.md` / the incident-response runbook
   (`TECH-4` in the launch-readiness checklist) — was the leaked credential
   used before revocation, and by whom.

Accept the downtime. A compromised secret live for an extra 30 minutes to
protect a low-traffic window is a worse trade than a short outage.

## Verification commands

**Unsigned-request check** — confirms the hook route is live and
signature-gated, independent of whether your new secret is correct yet.
Both routes are POST-only with no GET route (see `routes/api.php`), so the
request MUST use `-X POST` — a bare `curl -i` sends GET and gets `405`
before it ever reaches the signature check:

```bash
curl -i -X POST https://dev-api.partna.au/api/webhooks/supabase/auth/mfa-verification
curl -i -X POST https://dev-api.partna.au/api/internal/email-hooks/supabase
```

No headers or body are required to reach the check — `VerifySupabaseHookSignature`
reads the `webhook-id` / `webhook-timestamp` / `webhook-signature` headers as
empty strings when absent, which fails verification the same as a wrong
signature would. Expected: `401 {"error":"invalid_signature", ...}` (NOT
`404` — the route exists, it's just unsigned; NOT `405` — that means you
forgot `-X POST`). If the secret env var itself is empty, expect
`503 {"error":"hook_not_configured", ...}` instead.

**Real-hook verification** — trigger an actual Supabase-signed call:

- Auth hook: enroll or verify a TOTP factor against a test user (see
  `docs/auth/mfa-foundation-runbook.md` for the exact steps) and confirm the
  attempt completes normally.
- Email hook: trigger a password-reset email for a test user and confirm it
  arrives.

**Log verification** — confirm signature failures have stopped, using the
Cloud CLI form from this repo's `CLAUDE.md` (never `laravel-boost` log tools —
they read stale local test output, not real server logs):

```bash
cloud env:logs partna development --minutes 15
```

Grep the output (or use `--json | jq` for structured filtering) for these
event names, defined in `App\Http\Middleware\Auth\VerifySupabaseHookSignature`:

- `supabase.auth_hook.signature_failed` / `supabase.auth_hook.misconfigured`
- `supabase.email_hook.signature_failed` / `supabase.email_hook.misconfigured`

A clean rotation shows these events stop appearing after the deploy in step 5
of Procedure A; any that keep appearing means the two sides still disagree.

## What's deliberately NOT here

- **No automated rotation.** Every procedure above is manual, run by a human
  on a schedule they choose. No cron job rotates these.
- **No secrets manager.** Laravel Cloud env vars are the only store. Adopting
  something like AWS Secrets Manager / Vault is a platform decision for a
  later stage, not a task folded into this runbook.
- **No cadence-based rotation schedule.** This doc says how, not when.
  Pre-beta, with no customers, there's no compliance-driven rotation cadence
  to meet — rotate on suspicion of compromise (§ Emergency rotation) or when
  operationally convenient, not on a timer.
- **No dual-secret support in `VerifySupabaseHookSignature`.** Covered in
  Procedure A: considered, deliberately deferred. Revisit if the accepted
  30–60s downtime window stops being acceptable.
