`★ Insight ─────────────────────────────────────`
All four draft findings reference a planning document as source, not implemented code. The adjudication task here is to verify which represent *real, confirmable gaps* versus *pre-implementation speculation*. For CFG-1 and CFG-2, grepping the actual `.env.example` and `config/` confirms the keys are genuinely absent — the finding is the absence itself. CFG-3 is dropped because its proposed fix (wiring a JS Worker's `Cache-Control` header TTLs through Laravel's `config/sidest.php`) crosses an architectural boundary that doesn't exist — a PHP config value cannot reach a Cloudflare Worker response header without a separate runtime mechanism, making the fix as described unsound.
`─────────────────────────────────────────────────`

# Configuration Hygiene Audit — 2026-05-19

**Branch:** development
**Lens:** Configuration Hygiene: env() outside config, missing .env.example keys, feature flags without defaults
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- PARTNA-STANDALONE-PAGES-NEW-DIRECTION-1.md (plan document)
- .env.example (verified via grep)
- config/sidest.php (verified via grep)
- config/services.php (verified via grep)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#CFG-1** · P2 — `SIDEST_INDIVIDUAL_WAITLIST_ENABLED` planned without `.env.example` or `config/sidest.php` entry
    - **Where:** PARTNA-STANDALONE-PAGES-NEW-DIRECTION-1.md §28.14
    - **Affects:** Any developer setting up a new environment after Phase 1 ships. The plan describes `BootstrapController` reading this flag at signup, but no `.env.example` entry and no `config/sidest.php` key are defined anywhere in the codebase (`grep` finds the name only in planning documents). A fresh environment silently gets `null`, which evaluates falsy in an `if` check but will cause subtle mismatch if the code ever uses strict comparison.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `SIDEST_INDIVIDUAL_WAITLIST_ENABLED=false` to `.env.example` with an inline comment: `# Set true to hold individual signups on a waitlist instead of creating a Professional row`.
        - Add `'individual_waitlist_enabled' => env('SIDEST_INDIVIDUAL_WAITLIST_ENABLED', false)` to `config/sidest.php` under the existing feature flags block.
        - In `BootstrapController`, read `config('sidest.individual_waitlist_enabled')` — never `env()` directly — so the value participates in `php artisan config:cache`.
    - **Technical:** Per Partna convention, all `SIDEST_*` feature flags live in `config/sidest.php` with an explicit `false` default so new environments fail-closed. `env()` called directly in application code (outside `config/`) bypasses `php artisan config:cache`; once the cache is warm Laravel reads a stale `null` rather than re-evaluating the environment on each request. Grep confirms the key is absent from both `.env.example` and `config/sidest.php` today; the only references are in plan markdown files.
    - **Plain English:** Every on/off switch the platform uses needs to be on a master checklist (`.env.example`) so the next person setting up a server knows which switches exist and what they default to. This new "hold individuals on a waitlist" switch is described in the plan but never added to the checklist. When a developer sets up a staging or production server and this feature goes live, the switch won't exist in their environment — and depending on how the code reads it, it'll silently do nothing or behave unpredictably. Thirty minutes of wiring it up correctly now prevents a confusing deploy day later.
    - **Evidence:**
        ```
        §28.14. Individual waitlist flag:
        - New env var `SIDEST_INDIVIDUAL_WAITLIST_ENABLED` (default off)
        - When on, BootstrapController diverts individual signups to a waitlist row instead of creating a Professional
        - Brand, partner-via-invite, **and partner-via-brand-signup-code** signups are unaffected. The waitlist only diverts genuine `'individual'` signups.
        - Pure defensive kill switch
        ```

- [ ] **#CFG-2** · P2 — `CloudflarePurgeService` planned without env var or config key for the API token or zone ID
    - **Where:** PARTNA-STANDALONE-PAGES-NEW-DIRECTION-1.md §18, §28.7
    - **Affects:** Every environment that runs `CloudflareCachePurgeJob`. Neither `CLOUDFLARE_CACHE_PURGE_TOKEN` nor a zone-ID env var appears anywhere in `.env.example` or `config/services.php`; grep finds zero matches across the codebase. When the job runs against a fresh environment it will fail with an auth error that provides no hint about the missing variable — the developer must trace back through the service class to discover what key was expected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add to `.env.example`:
          ```
          CLOUDFLARE_CACHE_PURGE_TOKEN=           # Cloudflare API token — Zone.Cache Purge permission on the partna.au zone
          CLOUDFLARE_ZONE_ID=                     # partna.au zone ID from Cloudflare dashboard
          ```
        - Add to `config/services.php`:
          ```php
          'cloudflare' => [
              'cache_purge_token' => env('CLOUDFLARE_CACHE_PURGE_TOKEN'),
              'zone_id'           => env('CLOUDFLARE_ZONE_ID'),
          ],
          ```
        - `CloudflarePurgeService` must read `config('services.cloudflare.cache_purge_token')` and `config('services.cloudflare.zone_id')` — never `env()` directly.
        - Note that `CloudflareKvService` (already exists) may have precedent for how Cloudflare credentials are currently threaded through config; match that pattern.
    - **Technical:** The plan specifies that `CloudflareCachePurgeJob` calls `POST https://api.cloudflare.com/client/v4/zones/{zone_id}/purge_cache` with a bearer token scoped to `Zone.Cache Purge` (§18). Both the token and zone ID are deployment-specific secrets. Without `.env.example` entries, new environment setup will fail silently or produce opaque HTTP 401 errors from Cloudflare. The existing `CloudflareKvService` already touches the `CLOUDFLARE_*` namespace — check its config bindings when wiring the purge service so the key names are consistent.
    - **Plain English:** The system needs a key to talk to Cloudflare and a room number (zone ID) to know which corner of Cloudflare to talk to. The plan describes using these, but forgets to add them to the master key-ring list (`.env.example`). When the next developer sets up a staging server, they'll get a mysterious "access denied" error from Cloudflare with no clue which key they're missing. Naming the keys now and giving them placeholder values in the checklist takes 15 minutes and prevents an hour of debugging later.
    - **Evidence:**
        ```
        §18 — Cache purge mechanics. On profile edit, the backend dispatches
        `CloudflareCachePurgeJob(handle)` which:
        1. Builds the full URLs to purge: `https://<handle>.partna.au/` and any sub-paths
        2. Calls Cloudflare's cache purge API: `POST https://api.cloudflare.com/client/v4/zones/{zone_id}/purge_cache` with body `{"files": [...urls]}`
        3. Uses an API token scoped to `Zone.Cache Purge` for the `partna.au` zone.
        4. Retries on transient failures using its own declared backoff policy (max 3 attempts, exponential).
        ```

## P3 — Nice to have

- [ ] **#CFG-3** · P3 — Rate-limit values specified as literals rather than `config/sidest.php` entries
    - **Where:** PARTNA-STANDALONE-PAGES-NEW-DIRECTION-1.md §28.8, §33
    - **Affects:** Operations. Two distinct rate-limit configurations — 60 req/min/IP on the public profile endpoint and a tiered 10/min + 100/hr + 5-failure slowdown on signup code resolution — are specified as literal integers across two separate plan sections. When implemented as hardcoded values, tuning during a traffic spike or abuse event requires finding the source files, changing literals, and redeploying rather than a one-line config change.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add to `config/sidest.php`:
          ```php
          'rate_limits' => [
              'public_profile'        => ['per_minute' => 60],
              'brand_signup_code'     => [
                  'per_minute'          => 10,
                  'per_hour'            => 100,
                  'slow_after_failures' => 5,
                  'slow_delay_seconds'  => 2,
              ],
          ],
          ```
        - Reference `config('sidest.rate_limits.public_profile.per_minute')` in the throttle middleware registration for `IndividualProfileController`.
        - Reference `config('sidest.rate_limits.brand_signup_code.*')` in `BrandSignupCodeService`.
    - **Technical:** Partna convention places all operational limits in `config/sidest.php` (the `limits` key already contains pagination and retention values). The plan describes two different rate-limiting surfaces — a per-IP Laravel `throttle` middleware on a public controller (§28.8) and a PHP-level tiered rate limiter in a service class (§33). Both would be implemented as integer literals without a config anchor. Tuning these independently at a future point requires two separate source-level changes; centralising them enables a one-line config change that takes effect on the next deploy with no code modification.
    - **Plain English:** The system has speed bumps to prevent abuse — limits on how fast someone can load a profile page or try brand signup codes. Right now those numbers are scattered across the plan as specific values. If the team ever needs to raise or lower a limit during a traffic event, they'd have to find the right file, change a magic number, and redeploy. Moving them to the central settings file (where all other limits like page sizes and retention windows already live) means they can be adjusted in one place alongside everything else.
    - **Evidence:**
        ```
        §28.8 — Rate limiting: 60/min/IP via Laravel `throttle` middleware. The rate-limiter
        key uses `request->header('CF-Connecting-IP') ?? request->ip()`.
        ```
        ```
        §33 — Rate limiting (REQUIRED — abuse vector mitigation): the signup endpoint
        applies tiered rate limiting on code-resolution attempts:
        - 10 attempts per IP per minute
        - 100 per IP per hour
        - After 5 failed attempts on the same IP, the response delays 2 seconds
        ```
