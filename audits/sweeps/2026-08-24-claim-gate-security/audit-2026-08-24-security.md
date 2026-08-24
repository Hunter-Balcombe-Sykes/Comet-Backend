# Security Audit — 2026-08-24

**Branch:** development
**Lens:** Security: auth boundaries, tenant isolation, mass assignment, inbound callbacks, secrets, injection, SSRF, upload safety, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Http/Middleware/Auth/RequireStrongAuth.php
- app/Models/Core/User/PreAccountBuild.php
- config/partna.php
- app/Http/Requests/Api/Staff/UserSite/StaffAttachContactEmailRequest.php
- app/Http/Controllers/Api/PublicSite/ClaimController.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php
- app/Services/PreAccount/ClaimSiteService.php
- app/Services/PreAccount/PreAccountBuildException.php
- app/Services/PreAccount/PreAccountBuildService.php
- routes/api.php (route middleware verification)
- app/Http/Middleware/Auth/RequireEmailVerified.php (cross-check)
- app/Policies/PreAccountBuildPolicy.php (cross-check)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEC-1** · P1 — GDPR PII-export strong-auth gate ships shadow-only and stays fail-open even once enforced
    - **Where:** app/Http/Middleware/Auth/RequireStrongAuth.php:69-82
    - **Affects:** Whatever route this middleware guards (the GDPR full-PII-export path per its own docblock) — any authenticated Supabase session, including one created purely by clicking a magic/invite link, can pull the complete personal-data bundle.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Review the `auth.strong_auth.would_deny` shadow logs to confirm no legitimate cohort is being flagged, then flip `partna.auth.strong_auth_enforce` to `true` in production.
        - Close the compounding gap: once enforcement is on, an empty `amr` (`$methods === []`) must not still fall through — deny with 401 or require a documented re-auth challenge, rather than treating "claim absent" the same as "flag disabled."
        - Add a test asserting a JWT with missing/empty `amr` is rejected once `strong_auth_enforce=true`.
    - **Technical:** The middleware's own docblock documents this as a deliberate staged rollout — "SHADOW BY DEFAULT... enforcing that blind is how a security fix becomes an outage" — and `partna.auth.strong_auth_enforce` defaults to `false`, so the denial branch is currently unreachable in any environment that hasn't explicitly flipped it. That staging is reasonable engineering practice, but it leaves a real, current gap: today, nothing stops a session established only via `/auth/confirm` (a magic link/invite, aal1, no password) from reading the full export. Separately, and independent of the config flag, line 80's `if (! $enforce || $methods === [])` means that even after enforcement is turned on, a token with no `amr` claim at all (a pre-`amr` legacy token, or any client that omits it) still passes — this second bypass isn't a staging artifact, it's a permanent design gap in the enforced branch. Recent commit `45a87669a` ("Close the claim invite-gate, the early-access squat, and the PII export door") suggests this control is mid-rollout; this finding is the tracking item for actually finishing it before pilot users have real exportable data.
    - **Plain English:** This is the extra lock on the cabinet that holds a user's entire personal-data file. Right now the alarm is installed but switched off by default, and even once it's switched on there's a rule that says "if the ID badge is blank, let them through anyway." That means someone who only proved they could click a link in an email — not that they know a password or a second-factor code — can still walk out with the whole file today, and a blank ID badge will keep working even after the alarm is armed.
    - **Evidence:**
        ```php
        $enforce = (bool) config('partna.auth.strong_auth_enforce', false);

        Log::warning('auth.strong_auth.would_deny', [
            'path' => $request->path(),
            'uid' => $request->attributes->get('supabase_uid'),
            'aal' => $request->attributes->get('supabase_aal'),
            'methods' => $methods,
            'amr_empty' => $methods === [],
            'enforced' => $enforce,
        ]);

        if (! $enforce || $methods === []) {
            return $next($request);
        }
        ```

- [ ] **#SEC-2** · P1 — `/api/claim` treats the JWT's `email` field as proof of ownership without checking `email_verified`
    - **Where:** app/Http/Controllers/Api/PublicSite/ClaimController.php:28-37; routes/api.php:66
    - **Affects:** The site-first-signup claim flow — a self-serve unclaimed business site can be bound to an email the caller typed but never proved control of.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Check `($claims['email_verified'] ?? $claims['user_metadata']['email_verified'] ?? false) === true` in `ClaimController::store` before treating `$claims['email']` as verified — mirror `RequireEmailVerified`'s logic exactly.
        - Alternatively, add the `RequireEmailVerified` middleware to the `/claim` route registration in `routes/api.php:66` (currently `['supabase.jwt', 'throttle:claim']` only).
        - Add a regression test: a Supabase JWT with `email` set but `email_verified=false` must be rejected.
    - **Technical:** `routes/api.php:66` applies only `supabase.jwt` and `throttle:claim` to `/claim` — no `RequireEmailVerified`/equivalent — despite the adjacent route comment claiming "Email is read exclusively from the verified JWT claim." `ClaimController::store` names its local variable `$verifiedEmail`, but the only check performed is `trim((string) ($claims['email'] ?? '')) !== ''`; it never inspects `email_verified`. The codebase already has the exact check needed — `RequireEmailVerified` (`app/Http/Middleware/Auth/RequireEmailVerified.php:44`) reads both `claims['email_verified']` and `claims['user_metadata']['email_verified']` for precisely this reason, noting "which one is populated depends on project age + provider." `RequireStrongAuth`'s own docblock confirms this codebase supports `signUp({password})` sessions distinct from OTP-only sessions, so an unverified-email session reaching this controller is a real, not theoretical, path.
    - **Plain English:** This is like accepting a claim form because an email address is written in the box, without ever checking that the person actually clicked the confirmation link sent to that inbox. The code even names its variable "verified email," but nothing here verifies it — it only checks the box isn't empty. Someone could type in an email they don't control and walk away owning a real business's website.
    - **Evidence:**
        ```php
        $claims = $request->attributes->get('supabase_claims');
        $verifiedEmail = is_array($claims) ? trim((string) ($claims['email'] ?? '')) : '';
        if ($verifiedEmail === '') {
            return $this->error(
                'A verified email is required to claim your site.',
                422,
                [],
                ['code' => 'EMAIL_VERIFICATION_REQUIRED']
            );
        }
        ```

- [ ] **#SEC-3** · P1 — Self-serve pre-account builds are claimed first-come with nothing tying the claimer to the original builder
    - **Where:** app/Services/PreAccount/ClaimSiteService.php:85-97
    - **Affects:** Unclaimed self-serve (`built_via=signup`) pre-account sites — publicly reachable pre-claim at `<handle>.partna.au` by design (per the pre-account doctrine) — including their scraped business name, photos, and hours.
    - **Effort:** L (~1–2d) — requires a claim-continuity primitive (token/nonce), not a config tweak
    - **What to do:**
        - Issue an opaque claim token/nonce at `POST /api/public/signup/build` time, return it to the caller, and require it on `/claim` for any build where `contact_email` is empty.
        - Alternatively, gate the self-serve first-come arm on the originating session/device rather than subdomain string match alone.
        - Add a regression test proving a second Supabase-authenticated user cannot claim a build they didn't create while `contact_email` is empty.
    - **Technical:** On 2026-08-24 (`45a87669a`, "Close the claim invite-gate, the early-access squat, and the PII export door") this exact class of gap was closed for OUTREACH builds — `isOutreach()` builds now throw `CLAIM_NOT_INVITED` unless staff have attached a `contact_email` (lines 85-88). The accompanying comment states the surviving "absent = first-come" arm is intentionally kept "for self-serve builds, where the person claiming IS the person who just built it" — but nothing in `claim()` enforces that assumption; the only check is a subdomain string match (`Site::query()->whereRaw('lower(subdomain) = ?', ...)`). Per the platform's own pre-account doctrine, "a pre-account site is public pre-claim by design" and renders immediately at its subdomain regardless of publish state — so the handle is not a meaningful secret. Any Supabase-authenticated user who discovers a self-serve build's handle (by browsing, guessing from a business name, or scanning) before the rightful builder returns to claim it wins outright, taking over the business identity attached to that build.
    - **Plain English:** Think of a coat check that hands out coats to whoever calls out the right ticket number — except the ticket number is just the shop's own name, printed on the window for everyone to see. If a business's starter website is sitting unclaimed with no email on file, anyone who spots it and signs up first can walk off with it before the real owner gets back to claim their own site.
    - **Evidence:**
        ```php
        $contactEmail = trim((string) $build->contact_email);
        if ($build->isOutreach() && $contactEmail === '') {
            throw new RuntimeException('CLAIM_NOT_INVITED');
        }

        // Email-gate (spec §3.2): a build carrying a contact_email may only be
        // claimed by someone who verified control of THAT inbox via Supabase OTP.
        // Absent contact_email = first-come (self-serve only, per the gate above).
        // Case-insensitive.
        if ($contactEmail !== ''
            && strtolower(trim($verifiedEmail)) !== strtolower($contactEmail)) {
            throw new RuntimeException('CLAIM_EMAIL_MISMATCH');
        }
        ```

## P2 — Should fix

- [ ] **#SEC-4** · P2 — Bot protection defaults to fully off (`driver=null`, `mode=off`)
    - **Where:** config/partna.php:2557-2560
    - **Affects:** Public mutation endpoints depending on bot protection (enquiry, lead capture, early access, report) whenever an environment doesn't explicitly set the driver/mode env vars.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Set a production-safe default (e.g. `turnstile` + `enforce`), or add a boot-time guard in `AppServiceProvider` that hard-fails when `driver`/`mode` resolve to off in a production environment.
        - Keep `fail_open` false regardless.
    - **Technical:** `env('BOT_PROTECTION_DRIVER', 'null')` and `env('BOT_PROTECTION_MODE', 'off')` mean any deployment that omits these vars runs with zero bot protection rather than failing loud or defaulting safe. This mirrors the `PARTNA_THROTTLE_ENABLED` fail-closed pattern the platform already applies elsewhere — the same discipline should apply here so a missed env var doesn't silently strip protection instead of blocking boot.
    - **Plain English:** The spam filter on public forms like "contact me" or "early access" is off unless someone remembers to turn it on in the server settings. If that step gets missed on a new deployment, those forms sit wide open to automated spam. The safe setting should be the default, not something to opt into.
    - **Evidence:**
        ```php
        'bot_protection' => [
            'driver' => env('BOT_PROTECTION_DRIVER', 'null'),       // null | turnstile | hcaptcha | fake
            'mode' => env('BOT_PROTECTION_MODE', 'off'),          // off | shadow | enforce
            'fail_open' => (bool) env('BOT_PROTECTION_FAIL_OPEN', false),
        ```

- [ ] **#SEC-5** · P2 — Fresh-AAL2 requirement for profile updates defaults to off
    - **Where:** config/partna.php:2316-2318
    - **Affects:** Authenticated self-service profile updates (`ProfessionalSelfPolicy::update`) — a hijacked session can change account-critical fields without a fresh second-factor challenge.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Flip `SIDEST_MFA_REQUIRE_FRESH_AAL2_FOR_PROFILE_UPDATE` to `true` once TOTP enrolment is confirmed live, per the code comment's own stated condition.
        - Track this as a launch-readiness checklist item rather than a silent default, since the comment already identifies the exact trigger for flipping it.
    - **Technical:** The config comment explicitly states the intended trigger — "Flip to true after TOTP enrolment is live in the UI and tested in production" — confirming this is a deliberate staged rollout rather than an oversight. Flagged here because the pre-pilot lens exists specifically to confirm staged security controls get finished before real users are onboarded; this is the tracking item for that step, not a code defect.
    - **Plain English:** When someone changes important account details, the system can optionally demand a fresh second-factor check right before allowing it. That check is currently off by default while the second-factor feature itself is still being rolled out. This just needs a checklist item so it isn't forgotten when TOTP goes live.
    - **Evidence:**
        ```php
        // When true, ProfessionalSelfPolicy::update requires a fresh AAL2 check.
        // Flip to true after TOTP enrolment is live in the UI and tested in production.
        'require_fresh_aal2_for_profile_update' => (bool) env('SIDEST_MFA_REQUIRE_FRESH_AAL2_FOR_PROFILE_UPDATE', false),
        ```

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

- **#SEC-1 — Strong-auth gate fail-open on PII export** · touches auth boundary enforcement (RequireStrongAuth) on a GDPR export path.
- **#SEC-2 — Claim endpoint skips email_verified check** · touches auth/claim authorization boundary.
- **#SEC-3 — First-come claim with no ownership proof** · touches auth/claim authorization boundary; L-effort design change (claim token/nonce).
- **#SEC-4 — Bot protection off by default** · single small config change, but the production-guard variant would touch `AppServiceProvider` boot behavior — plan first if going that route.
- **#SEC-5 — Fresh-AAL2 profile-update default off** · touches MFA/authorization config gating `ProfessionalSelfPolicy::update`.
