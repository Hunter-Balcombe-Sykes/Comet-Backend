`★ Insight ─────────────────────────────────────`
- The `config('partna.moderation.enabled')` key has **zero readers** in the entire app directory — the CSAM pipeline that would check it was removed on 2026-05-29. The broken cast is a latent correctness issue that must be fixed before the pipeline re-activates, but has no current impact.
- `IndividualProfilePayloadBuilder::buildPublicConfig()` at line 460 is the **only confirmed consumer** of `analytics_endpoint`, embedding it verbatim into every public profile API response — confirming the cross-environment default issue is real and currently shipping.
- `account_type_defaults.individual.default_contact` has no PHP callers anywhere in `app/` — the placeholder "Charlie" data is dead config, not writing to any user profile.
`─────────────────────────────────────────────────`

# Config & Secret Hygiene Audit — 2026-05-31

**Branch:** development
**Lens:** Env reads outside the config layer, hardcoded secrets, dangerous config defaults, feature-flag determinism, diagnostic-info leakage, plaintext credentials/PII in logs and exception messages
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `config/partna.php`
- `config/app.php`
- `config/auth.php`
- `config/cache.php`
- `config/cors.php`
- `config/database.php`
- `config/filesystems.php`
- `config/horizon.php`
- `config/logging.php`
- `config/mail.php`
- `config/nightwatch.php`
- `config/queue.php`
- `config/services.php`
- `config/session.php`
- `config/supabase.php`
- `app/Services/Diagnostics/EnvCheckService.php`
- `app/Services/Media/MediaDiskResolver.php`
- `app/Services/Media/VideoVariantService.php`
- `app/Services/PublicSite/IndividualProfilePayloadBuilder.php`
- `app/Services/Auth/SupabaseAdminService.php`
- `app/Services/Auth/TokenRevocationService.php`
- `app/Services/FeatureFlags/FeatureFlagService.php`
- `app/Services/Streaming/StreamingTokenManager.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#CONF-1** · P1 — `moderation.enabled` kill-switch missing `(bool)` cast — silently non-functional when set via OS environment
    - **Where:** config/partna.php — `moderation.enabled` line
    - **Affects:** Operators attempting to disable automated moderation enforcement via env var during a CSAM incident or content-safety emergency. The kill-switch will silently remain active no matter what the env var says.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `env('PARTNA_MODERATION_ENABLED', true)` to `(bool) env('PARTNA_MODERATION_ENABLED', true)` in `config/partna.php`
        - Add a test asserting `config('partna.moderation.enabled')` evaluates to `false` when the env var is set to `'false'`
    - **Technical:** When `PARTNA_MODERATION_ENABLED=false` is injected as an OS-level environment variable (as Laravel Cloud and most container runtimes do), `env()` returns the raw string `'false'`, which is truthy in PHP. The `(bool)` cast pattern is applied to every other boolean in the same config block — `auto_actions_enabled`, `throttle.enabled`, `video_uploads_enabled`, `individual_waitlist_enabled`, `features.*` — making `moderation.enabled` the sole outlier. The CSAM pipeline that reads this key was removed on 2026-05-29 (deferred, not abandoned), so there is currently no live reader in `app/`; however the kill-switch must work correctly the moment the pipeline re-activates. Shipping with a broken emergency stop is a pre-launch safety gap.
    - **Plain English:** There's an emergency off-switch for the moderation system — the kind you'd reach for during a content-safety crisis. Due to a small coding inconsistency, setting the switch to "off" in the server config doesn't actually turn it off. It's like an emergency stop button that's been wired in reverse: pushing it does nothing. Every other on/off switch in the same settings file is wired correctly; this one was missed. The fix is a one-word code change.
    - **Evidence:**
        ```php
        // config/partna.php — moderation block (missing (bool) cast)
        'moderation' => [
            'enabled' => env('PARTNA_MODERATION_ENABLED', true),
            // Emergency kill-switch for automated enforcement…
            'auto_actions_enabled' => (bool) env('PARTNA_MODERATION_AUTO_ACTIONS_ENABLED', true),
        ```
        ```php
        // config/partna.php — every other boolean in the same file uses the cast:
        'enabled' => (bool) env('PARTNA_THROTTLE_ENABLED', env('SIDEST_THROTTLE_ENABLED', true)),
        'video_uploads_enabled' => (bool) env('PARTNA_VIDEO_UPLOADS_ENABLED', env('SIDEST_VIDEO_UPLOADS_ENABLED', false)),
        'individual_waitlist_enabled' => (bool) env('SIDEST_INDIVIDUAL_WAITLIST_ENABLED', false),
        ```

---

## P2 — Should fix

- [ ] **#CONF-2** · P2 — `analytics_endpoint` defaults to dev API URL — production profile pages silently report analytics to wrong environment
    - **Where:** config/partna.php — `public_profile.analytics_endpoint`; consumed at `app/Services/PublicSite/IndividualProfilePayloadBuilder.php:460`
    - **Affects:** Every public profile page served from a production environment where `PARTNA_PUBLIC_ANALYTICS_ENDPOINT` is not explicitly set. The `analyticsEndpoint` key is embedded in every `/api/public/profiles/{handle}` response; if the env var is absent, client-side analytics beacons from real visitors on production profiles fire against `dev-api.partna.au` instead of the production API. Production analytics are lost; dev analytics are polluted with production traffic. `PARTNA_PUBLIC_ANALYTICS_ENDPOINT` is absent from both `EnvCheckService::REQUIRED` and `EnvCheckService::RECOMMENDED`, so no deploy-time warning surfaces the misconfiguration.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the default from `'https://dev-api.partna.au/api/analytics'` to derive from `config('app.url')` (e.g., `rtrim(config('app.url'), '/').'/api/analytics'`) so a freshly provisioned environment routes to itself, not to dev
        - Add `'partna.public_profile.analytics_endpoint' => 'PARTNA_PUBLIC_ANALYTICS_ENDPOINT'` to `EnvCheckService::RECOMMENDED` so the deploy checklist surfaces a missing explicit override
        - Optionally: add a boot-time `Log::warning` in `AppServiceProvider` when `APP_ENV === 'production'` and the endpoint contains `dev-api`
    - **Technical:** `IndividualProfilePayloadBuilder::buildPublicConfig()` calls `config('partna.public_profile.analytics_endpoint')` and includes the result verbatim in all public-profile API responses. The config key falls through to `env('PARTNA_PUBLIC_ANALYTICS_ENDPOINT', 'https://dev-api.partna.au/api/analytics')`. Because the env var is undocumented in `EnvCheckService`, a production deploy without it is silent. Deriving from `APP_URL` is always correct in a single-env deploy; teams that use a CDN-fronted analytics URL still set the env var explicitly and override the default.
    - **Plain English:** Every public profile page tells visitors' browsers where to send usage analytics — page views, link clicks, etc. The system has a "default" analytics address baked in for when no one explicitly configures it, and that default is the test/development server instead of the production server. So in a production deployment that doesn't explicitly set this address, all real visitor data goes to the wrong place and the production analytics dashboard stays empty. The fix is to make the default point to the server the app is actually running on, so a missing configuration becomes correct instead of silently wrong.
    - **Evidence:**
        ```php
        // config/partna.php — defaults to dev URL
        'analytics_endpoint' => env(
            'PARTNA_PUBLIC_ANALYTICS_ENDPOINT',
            'https://dev-api.partna.au/api/analytics'
        ),
        ```
        ```php
        // app/Services/PublicSite/IndividualProfilePayloadBuilder.php:457-462
        private function buildPublicConfig(): array
        {
            return [
                'analyticsEndpoint' => config('partna.public_profile.analytics_endpoint'),
            ];
        }
        ```
        ```php
        // app/Services/Diagnostics/EnvCheckService.php — PARTNA_PUBLIC_ANALYTICS_ENDPOINT
        // is absent from both REQUIRED and RECOMMENDED; no deploy-time warning.
        public const RECOMMENDED = [
            'Observability' => [...],
            'Mail' => [...],
            'Bot Protection (Turnstile)' => [...],
            'Google Maps (address autocomplete)' => [...],
        ];
        ```

---

## P3 — Nice to have

- [ ] **#CONF-3** · P3 — `VideoVariantService::extractPoster()` logs raw ffmpeg stdout+stderr including server temp-file paths
    - **Where:** app/Services/Media/VideoVariantService.php — `extractPoster()` method
    - **Affects:** Nightwatch log retention — ffmpeg combined output contains server temp-directory paths (e.g. `/tmp/sidest_poster_XXXX.jpg`) and may contain input file metadata. Visible to anyone with log access; reveals the server's temp-file naming convention and directory layout.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'error' => $outputStr` in the `Log::warning` call with `'exit_code' => $exitCode, 'error_summary' => substr($outputStr, 0, 200)`
        - The exit code and a truncated first line of stderr are sufficient to diagnose the failure type; the full combined output adds infrastructure surface with no diagnostic benefit
    - **Technical:** `runCommand()` returns `$process->getOutput() . $process->getErrorOutput()` as a combined string. When both the 1-second-mark extraction and the 0-second fallback fail, `$outputStr` from the first attempt is logged verbatim as `'error' => $outputStr`. A typical ffmpeg error includes the full invocation line (containing the temp-file path), codec negotiation output, and I/O error details — none of which are needed in a structured log entry. Truncating to 200 characters and logging the exit code separately keeps the entry actionable without leaking internal path conventions.
    - **Plain English:** When video thumbnail extraction fails, the system writes the complete raw technical error from the video-processing tool into the monitoring logs. This output includes internal server file paths like `/tmp/sidest_poster_XXXX.jpg`, which give anyone reading the logs a map of how the server names its temporary work files internally. These aren't customer records, but they're unnecessary detail. The fix is to log a short excerpt (the first few characters plus the error number) rather than the full dump — still enough to diagnose the problem, but not enough to sketch a server floor plan.
    - **Evidence:**
        ```php
        // app/Services/Media/VideoVariantService.php — extractPoster()
        $cmd = [$ffmpeg, '-y', '-i', $input, '-ss', '00:00:01', '-frames:v', '1', '-q:v', '3', $output];
        $outputStr = $this->runCommand($cmd, $exitCode, $timeout);

        if ($exitCode !== 0 || ! file_exists($output)) {
            // Non-fatal: try frame at 0s as fallback
            $cmd2 = [$ffmpeg, '-y', '-i', $input, '-frames:v', '1', '-q:v', '3', $output];
            $this->runCommand($cmd2, $exitCode2, $timeout);

            if ($exitCode2 !== 0 || ! file_exists($output)) {
                Log::warning('VideoVariantService: could not extract poster frame; writing placeholder JPEG.', [
                    'error' => $outputStr,  // ← raw combined stdout+stderr, includes /tmp/sidest_poster_* paths
                ]);
        ```
        ```php
        // app/Services/Media/VideoVariantService.php — runCommand() return value
        private function runCommand(array $cmd, ?int &$exitCode = null, int $timeout = 30): string
        {
            $process = new Process($cmd);
            $process->setTimeout($timeout);
            $process->run();
            $exitCode = $process->getExitCode() ?? 0;
            return $process->getOutput().$process->getErrorOutput();
        }
        ```
