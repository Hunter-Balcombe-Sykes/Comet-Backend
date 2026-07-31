# Configuration Hygiene Audit — 2026-07-12

**Branch:** HEAD
**Lens:** Configuration Hygiene — env() outside config, missing .env.example keys, feature flags without defaults
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- config/partna.php
- config/services.php
- config/supabase.php
- config/cache.php
- .env.example
- bootstrap/app.php
- routes/api.php, routes/api/{platforms,staff,user}.php, routes/console.php
- app/Console/Commands/*.php
- app/Http/Middleware/**/*.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Design/AnalyzeConnectionWebsitesJob.php
- app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Platforms/{GoogleBusinessEnrichJob,InstagramConnectJob,MenuFetchJob}.php
- app/Jobs/ProcessLogoVariantsJob.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Diagnostics/EnvCheckService.php
- app/Services/Media/{ImagePaletteExtractor,ImageVariantService}.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 5 complete

---

## P2 — Should fix

- [ ] **#CFG-1** · P2 — Auth-adjacent secrets have no rotation runbook or dual-secret window
    - **Where:** config/services.php:44,48,74,79 (`supabase.email_hook_secret`, `supabase.auth_hook_secret`, `cloudflare.api_token`, `cloudflare.cache_purge_token`); config/partna.php:1102 (`logo_removal.token`)
    - **Affects:** Ops — rotating `SUPABASE_AUTH_HOOK_SECRET`, `SUPABASE_EMAIL_HOOK_SECRET`, `CLOUDFLARE_API_TOKEN`, or `CLOUDFLARE_CACHE_PURGE_TOKEN` requires an atomic env+dashboard swap with no grace window; a deploy race between the two 503s every hook delivery until they line up.
    - **Effort:** M (~2–4h) — docs + optional dual-secret support in `VerifySupabaseHookSignature`
    - **What to do:**
        - Add a rotation runbook (comment block in `config/services.php` or a `docs/` note): generate new secret → update the Supabase/Cloudflare dashboard → update env var → deploy → verify → deactivate old secret.
        - For the two Supabase hook secrets specifically, `VerifySupabaseHookSignature` verifies Standard-Webhooks-format signatures — the standard natively supports **space-separated multi-secret verification** during rotation (already referenced in the `.env.example` comment for `SUPABASE_EMAIL_HOOK_SECRET`: "Standard Webhooks supports space-separated signatures for zero-downtime rotation"). Confirm the middleware actually splits and tries each secret, or wire that support in if it doesn't yet.
        - For `CLOUDFLARE_API_TOKEN` / `CLOUDFLARE_CACHE_PURGE_TOKEN`, document that these are single-token (no rotation overlap) and that a rotation requires a brief deploy-synchronized swap; this is lower urgency since Cloudflare token failures degrade (cache purge/DNS ops) rather than block user-facing auth.
    - **Technical:** `config/services.php`'s `supabase.auth_hook_secret` / `supabase.email_hook_secret` are each a single env var consumed by `VerifySupabaseHookSignature`, which gates `POST /internal/*-hooks/supabase`. Rotating either requires the backend and Supabase's dashboard to agree on the secret at the exact same instant — any window where they disagree is a 503 on every real hook delivery (auth emails / auth-hook enforcement stop firing). The `.env.example` comment for the email hook already flags that Standard Webhooks supports space-separated multi-secret verification for exactly this reason; verify `VerifySupabaseHookSignature` implements that (or add it) rather than inventing a new `_VERSION` key scheme, since the wire format already has a native answer. The Cloudflare tokens carry the same single-secret rotation gap but a lower blast radius (KV write / cache purge degrade, not user-facing auth failure).
    - **Plain English:** Imagine your front door's smart lock and your phone's unlock app both need to agree on today's passcode. If you update the passcode on the lock but haven't finished updating the phone app, you're locked out until both catch up. Right now several of Partna's server-to-server "passcodes" (used to prove that a Supabase or Cloudflare message is genuinely from them) work exactly that way — there's no way to have the old and new passcode both valid for a few minutes while you switch over. Adding that overlap window means rotating these secrets stops requiring a nerve-wracking flip-the-switch-and-hope moment.
    - **Evidence:**
        ```php
        'supabase' => [
            'email_hook_secret' => env('SUPABASE_EMAIL_HOOK_SECRET'),
            'auth_hook_secret' => env('SUPABASE_AUTH_HOOK_SECRET'),
        ```
        ```php
        'cloudflare' => [
            'api_token' => env('CLOUDFLARE_API_TOKEN'),
            'cache_purge_token' => env('CLOUDFLARE_CACHE_PURGE_TOKEN'),
        ```

## P3 — Nice to have

- [ ] **#CFG-2** · P3 — Analytics endpoint default resolves `config('app.url')` at config-load time
    - **Where:** config/partna.php:946-949 (`public_profile.analytics_endpoint`)
    - **Affects:** Staging/QA environments where `APP_URL` is left unset — the client analytics-beacon endpoint bakes in `http://localhost/api/analytics` at `php artisan config:cache` time.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either accept the current behaviour (document that `PARTNA_PUBLIC_ANALYTICS_ENDPOINT` should be explicit in any env where `APP_URL` isn't also correct), or switch the default to a request-time closure using `url('/')` instead of a config-load-time `config('app.url')` string.
    - **Technical:** Laravel's `LoadConfiguration` bootstrap loads `config/*.php` files in filename order and `set()`s each into the repository sequentially, so `app.php`'s `url` key is already populated by the time `partna.php` evaluates this line — the value is correct in every environment that actually sets `APP_URL`. The only real footgun is an environment that forgets `APP_URL` entirely, in which case this silently resolves to `http://localhost/api/analytics` and gets baked into `config:cache`. Since `.env.example` ships `APP_URL=http://localhost:8000` and every deployed env (Laravel Cloud) sets `APP_URL` explicitly, the practical exposure is narrow — but it's a one-line change to make the fallback path safer.
    - **Plain English:** This is a "what if we forget to fill in this field" scenario. The setting that tells the analytics beacon where to send data normally copies the site's own address automatically. If nobody ever tells it the site's real address (a setup mistake), it quietly defaults to a placeholder that only works on a developer's own laptop. It's a minor safety-net gap, not a live problem today.
    - **Evidence:**
        ```php
        'analytics_endpoint' => env(
            'PARTNA_PUBLIC_ANALYTICS_ENDPOINT',
            rtrim(config('app.url'), '/').'/api/analytics'
        ),
        ```

- [ ] **#CFG-3** · P3 — `brand_scan.enabled` defaults `true`, inconsistent with the rest of the `*_ENABLED` fleet
    - **Where:** config/partna.php:1135 (`brand_scan.enabled`); .env.example:249
    - **Affects:** New environment deploys — the brand-scan flag reads "on" without an explicit opt-in, unlike every other `*_ENABLED` flag in this file.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Leave as-is if consistency isn't worth the churn — but if changed, flip to `env('PARTNA_BRAND_SCAN_ENABLED', false)` and update the matching `.env.example` line to `false`, since the client-side fail-closed behaviour doesn't depend on the flag's default.
    - **Technical:** Unlike most `*_ENABLED` flags in this file, `brand_scan.enabled` defaults `true` and is documented as intentionally safe: the accompanying comment ("Enabled defaults true but the client fails closed... until URL + token are set, so this is safe to ship unconfigured") and the matching `.env.example` entry (`PARTNA_BRAND_SCAN_ENABLED=true` with an explanatory comment) show this was a considered decision, not an oversight — `WebsiteStyleAnalyzer`'s client returns `ok:false` and design presets abstain whenever `PARTNA_BRAND_SCAN_URL`/`TOKEN` are empty, so a fresh, unconfigured deploy behaves identically whether this flag is `true` or `false`. The residual risk is purely stylistic: it breaks the fleet-wide "every `*_ENABLED` flag defaults off" convention, so a future refactor that removes the client-side fail-closed check would silently activate this feature everywhere. Downgraded from the draft's P2 given the documented, working safety net.
    - **Plain English:** Most feature switches in this codebase ship "off" until someone deliberately turns them on. This one ships "on," but there's a second safety check further down the line that keeps it harmless until the required web address and access key are filled in — so in practice nothing bad happens today. It's still worth eventually making this switch consistent with all the others, so a future change to that safety check doesn't quietly flip this feature on everywhere.
    - **Evidence:**
        ```php
        'brand_scan' => [
            'enabled' => (bool) env('PARTNA_BRAND_SCAN_ENABLED', true),
        ```

- [ ] **#CFG-4** · P3 — `refresh.conditional.enabled` defaults `true`, same fleet inconsistency as CFG-3
    - **Where:** config/partna.php:1344 (`refresh.conditional.enabled`); .env.example:314
    - **Affects:** New environments — conditional HTTP (ETag/If-None-Match) requests to upstream platforms are active without explicit opt-in.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same call as CFG-3: leave as documented-intentional, or flip to `false` + update `.env.example` for fleet consistency.
    - **Technical:** `ConditionalContext::for()` reads this flag as a master kill-switch; when `false` it returns `null` and every wired strategy fetches unconditionally exactly as before — the flag exists specifically as an emergency "force full fetches" lever, not a feature gate. `ConditionalContext::handle()` only short-circuits on an actual HTTP 304; any other status (including a 200 from an upstream that "mis-answers") is processed as a normal full fetch with fresh validators captured for next time — there is no path where a misbehaving upstream causes silently-wrong data, contradicting the draft's stated failure mode. `.env.example` documents the default as deliberate ("Global kill-switch: set false to force full fetches everywhere if an upstream starts mis-answering conditional requests"). Same fleet-consistency argument as CFG-3 applies; downgraded from the draft's P3-adjacent P2/P3 split to a flat P3 to match CFG-3 (same root cause).
    - **Plain English:** This is the same pattern as the brand-scan switch above — a feature flag that ships "on" instead of "off," but with a built-in safety net (if a supplier's API doesn't play along, the system just does the normal slower thing instead of getting confused). Low priority polish, not a live risk.
    - **Evidence:**
        ```php
        'conditional' => [
            'enabled' => (bool) env('PARTNA_REFRESH_CONDITIONAL_ENABLED', true),
        ],
        ```

- [ ] **#CFG-5** · P3 — Hardcoded queue name bypasses `config('partna.queues.*')` routing convention
    - **Where:** app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php:36
    - **Affects:** Notification delivery — a future queue rename or per-environment override would silently miss this job.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$this->queue = 'notifications';` with `$this->onQueue(config('partna.queues.notifications', 'notifications'));` in the constructor — `onQueue()` sets the same untyped `Queueable::$queue` property, so it doesn't reintroduce the PHP 8.4 trait-conflict this job's comment is guarding against.
        - Update/remove the stale comment accordingly.
    - **Technical:** Most jobs audited in this scope (`ProcessLogoVariantsJob`, `RecordAnalyticsEventJob`, `CloudflareCachePurgeJob`, `GoogleBusinessEnrichJob`, `MenuFetchJob`, `InstagramConnectJob`, `SendTransactionalNotificationEmailJob`) route their queue through `config('partna.queues.*')` with a literal fallback. This job instead assigns `$this->queue` directly in the constructor. Note: this is not unique to this file codebase-wide (several out-of-scope Moderation jobs follow the same direct-assignment pattern) but within this audit's scope it's the one inconsistent case, and the fix is a one-line, safe change — `onQueue()` is a plain method call on the same untyped property the constructor comment is protecting, so switching doesn't reintroduce the trait conflict. Retiered from the draft's P2 to P3 per this lens's own guidance ("P3 for hardcoded values that should be config-driven" — this is squarely category 4, not a P2 missing-`.env.example`/flag-default issue).
    - **Plain English:** Almost every background job in this area of the code looks up which delivery lane to use from a shared settings sheet, so the lane name can be changed in one place. This one job has its lane name written directly into the code instead. It's low risk today since nobody's renaming that lane, but it's an easy fix to bring in line with its neighbors.
    - **Evidence:**
        ```php
        public function __construct(public readonly string $enquiryId)
        {
            // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
            $this->queue = 'notifications';
        }
        ```

- [ ] **#CFG-6** · P3 — Hardcoded timeout and byte-limit constants in Instagram media mirroring
    - **Where:** app/Jobs/Platforms/InstagramConnectJob.php:70,73,78,84
    - **Affects:** Instagram auto-connect pipeline — tuning these during a CDN slowdown or storage-cost incident currently requires a code deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `IMAGE_TIMEOUT_SECONDS`, `VIDEO_TIMEOUT_SECONDS`, `MAX_VIDEO_BYTES`, and `MAX_IMAGE_BYTES` to `config/partna.php` under a new `partna.instagram.*` key, each with an `env()` default matching the current constant.
        - Add the new env keys to `.env.example` (commented, with the current values as documented defaults) alongside the existing `PARTNA_INSTAGRAM_APIFY_*` entries.
    - **Technical:** These four constants control outbound network timeouts and R2 storage byte caps for Instagram media mirroring. Sibling timeouts in the same general area of the codebase — `partna.brand_scan.timeout` and `partna.logo_removal.timeout` — are already env-overridable, so this is a genuine, low-risk inconsistency (unlike `AnalyzeConnectionWebsitesJob::MAX_ANALYSES_PER_RUN`, these four values have no cross-constant coupling documented in-file that would make blind config-driving dangerous).
    - **Plain English:** The Instagram photo/video mirroring job has four dials — how long to wait for a photo, how long to wait for a video, and the biggest file size it'll accept for each. Right now those dials are welded in place in the code. If a Instagram's servers get slow one day, or storage costs need tightening, fixing it currently means a full code deploy instead of an environment-variable change.
    - **Evidence:**
        ```php
        private const IMAGE_TIMEOUT_SECONDS = 10;
        private const VIDEO_TIMEOUT_SECONDS = 30;
        private const MAX_VIDEO_BYTES = 52428800;
        private const MAX_IMAGE_BYTES = 15728640;
        ```

## Suggested Bundled Sessions

- **Bundle A — Feature-flag default consistency:** #CFG-3, #CFG-4
    - **Why grouped:** Same root cause — a `*_ENABLED` flag in `config/partna.php` deliberately defaulting `true` with a documented, verified safety net elsewhere. Same file, same decision to make (fix vs. leave documented-as-is).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (trivial change if approved).

- **Bundle B — Config-drive hardcoded operational constants:** #CFG-2, #CFG-5, #CFG-6
    - **Why grouped:** Same pattern (a hardcoded value that should route through `config()`), different files, no cross-dependency between them — safe to implement and review together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#CFG-1 — Auth-adjacent secret rotation runbook** · touches Supabase Auth Hook / Cloudflare API-token infrastructure (auth-adjacent); needs its own plan + sign-off before implementation, especially if `VerifySupabaseHookSignature` needs a code change to support multi-secret verification during rotation.
