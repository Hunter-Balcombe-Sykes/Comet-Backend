`★ Insight ─────────────────────────────────────`
- **RATE-1 is auto-dropped** per the always-drop rule: "Rate limiting / DoS findings on internal endpoints (`/internal/*`)" — the env-check lives at `/internal/env-check` and is not user-reachable.
- **RATE-2 is a false positive** — the controller performs HMAC signature verification *inline* as the very first thing (`if (! $this->hookService->verifySignature(...))`) before any DB access. DeepSeek assumed no inline verification because no middleware was present, but the code directly contradicts this.
- **RATE-3 is a false positive** — all 11 named limiters (`health-check`, `public-profile`, `public-site`, `analytics`, `analytics-click`, `leads`, `waitlist`, `authenticated`, `staff`, `webhooks`, `bootstrap`) are registered in `AppServiceProvider`. Only `env-check` is missing, but that's an internal/dropped finding.
`─────────────────────────────────────────────────`

All four DeepSeek findings are either auto-dropped (RATE-1 = internal endpoint), false positives verified by code (RATE-2, RATE-3), or P3 polish on a dev placeholder (RATE-4). RATE-4 at P3 with confidence 0.85 is valid but the recommended fix (remove or throttle) is a minor code-hygiene item. I'll keep RATE-4 as P3 with updated evidence.

# Rate Limiting, Throttle Coverage & CORS Audit — 2026-05-24

**Branch:** development
**Lens:** rate limiting coverage on public + auth routes, throttle bypass, CORS misconfig
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- routes/api.php
- routes/api/professional.php
- routes/api/publicSite.php
- routes/api/staff.php
- routes/web.php
- app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php
- app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php
- app/Http/Middleware/SecureHeaders.php
- app/Providers/AppServiceProvider.php (rate limiter registrations verified)
- config/cors.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 1 complete

---

## P3 — Nice to have

- [ ] **#RATE-1** · P3 — Dev placeholder root route has no rate limiting and no purpose in production
    - **Where:** routes/web.php:3–5
    - **Affects:** Anyone who discovers `GET /` — the route burns a PHP-FPM worker slot to return a string; a flood saturates the worker pool at no cost to the attacker. Low real-world risk given it returns in microseconds, but the route has no production purpose and should be removed.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Remove `Route::get('/', fn () => 'joshua hunter is awesome')` entirely — it is a dev artifact; there is no frontend that calls it.
        - If a health-check root route is ever needed, replace it with the `HealthController@check` pattern used by `/health` and `/ready`, so it is consistent and rate-gated.
    - **Technical:** The `routes/web.php` root route carries no middleware and is not covered by the `api/*` CORS path pattern in `config/cors.php`. While `SecureHeaders` adds `Access-Control-Allow-Origin: *` globally, there is no rate gate. The only other web route in the file (`/p/{professionalId}.svg`) correctly carries `->middleware('throttle:public-site')`. All 11 named rate limiters in `AppServiceProvider` are registered and functioning; this route simply falls outside all of them.
    - **Plain English:** There's a door to the building that responds "joshua hunter is awesome" to anyone who knocks. It's harmless for one person, but it doesn't have a queue limit, so a crowd pressing the buzzer repeatedly occupies every staff member simultaneously. The door serves no real business purpose — just remove it.
    - **Evidence:**
        ```php
        Route::get('/', function () {
            return 'joshua hunter is awesome';
        });
        ```
