# Configuration Hygiene: env() outside config, missing .env.example keys, feature flags without defaults

Hunt **`env()` calls outside of config files**, **missing `.env.example` entries for active config keys**, **feature flags with no safe default**, and **config values used inconsistently** (hardcoded in some places, config-driven in others). Configuration bugs are invisible until deployment — a missing env var silently uses `null`, a forgotten `.env.example` key blocks onboarding a new environment.

Partna uses `config/partna.php` for all platform feature config and limits. All env vars must be declared in `.env.example`. Feature flags follow the pattern `SIDEST_<FEATURE>_ENABLED` (legacy prefix, still current — do not flag the naming itself) and must have a safe-off default (`false`) so new environments don't accidentally enable unfinished features.

## Use the lens prefix `CFG` for findings

Number them `CFG-1`, `CFG-2`, … sequentially. **P1 for `env()` in non-config files (bypasses config caching). P2 for missing `.env.example` keys or feature flags without a default. P3 for hardcoded values that should be config-driven.**

## Findings categories

### (1) `env()` calls outside config files

- `env('KEY')` called directly in `app/`, `routes/`, `database/`, `bootstrap/` — bypasses `php artisan config:cache` and causes inconsistent values between requests when the cache is active.
- `env('KEY')` in Service classes, Middleware, Controllers, Jobs — all must use `config('section.key')` instead.
- `env('KEY', $default)` in Eloquent models or Observers — model behaviour changes based on env, unpredictably after caching.
- `env('APP_ENV')` checks in business logic — use `app()->environment('production')` instead.
- `env()` in test files is acceptable for test-only env vars, but flag if it's reading a production key in a test.

### (2) Missing `.env.example` keys

- Any `env('KEY')` call (in config files) where `KEY` does not appear in `.env.example` — new environments silently get `null`.
- `config('section.key')` that maps to an `env('KEY')` call where `KEY` is absent from `.env.example`.
- Keys present in `.env.example` with real values (not placeholders like `your-key-here`) — leaking real credentials into the repo.
- Keys that exist in `.env.example` but are no longer referenced anywhere in `config/` — dead config that misleads new developers.
- Keys added to config files without a corresponding `.env.example` entry and inline comment explaining the purpose.

### (3) Feature flags without safe defaults

- `env('SIDEST_FEATURE_ENABLED')` with no second argument — returns `null`, which is truthy in some PHP comparisons (`if ($value)` where null is falsy, but `$value === true` is false — inconsistent).
- Feature flags defaulting to `true` — new environments enable the feature without opting in, which is wrong for unfinished or capability-gated features.
- Feature flags that are checked in some code paths but not others — partial enablement leads to inconsistent state.
- Boolean flags read via `config('partna.feature')` in some places and `env('SIDEST_FEATURE')` directly in others — split config access pattern.

### (4) Hardcoded values that should be config-driven

- Magic numbers in service classes (retry counts, timeouts, rate limits, page sizes) that are hardcoded as literals rather than referencing `config('partna.limits.*')`.
- Queue names (`'notifications'`, `'analytics'`, `'cloudflare'`) hardcoded as string literals across job files — a typo creates a silent routing failure.
- API version strings hardcoded in multiple service classes — should be a single config value so version bumps are one-line changes.
- Cloudflare KV namespace IDs or zone IDs hardcoded in service code instead of `config('services.cloudflare.*')`.
- Twitch/Kick client credentials hardcoded in `app/Services/Streaming/` classes instead of `config('services.twitch.*')` / `config('services.kick.*')`.
- Outbound fetch timeouts or per-host request limits hardcoded in `app/Services/Platforms/` scrapers or `app/Services/Http/SafeUrlFetcher` instead of `config('partna.limits.*')`.

### (5) Config file correctness

- `config/services.php` entries that reference vendor keys not in `.env.example`. Current keys to cross-reference: `postmark`, `google_maps`, `resend`, `supabase`, `ses`, `slack`, `cloudflare`, `twitch`, `kick` (all active), `fresha`, `apify` (legacy remnants — their keys should still appear in `.env.example` as commented-out stubs so they're not silently null if new code accidentally reads them).
- `config/partna.php` entries with no corresponding usage in the codebase — dead config.
- Nested config keys accessed with incorrect dot notation (`config('partna.limits_max')` instead of `config('partna.limits.max')`) — silently returns null.
- `config('app.url')` used to construct API callback URLs instead of `url('/')` — breaks when `APP_URL` is not set or is wrong in a given environment.
- `config('queue.default')` overridden in a job via `onQueue()` with a hardcoded string — the queue override bypasses environment-specific queue routing.
- New code that reads `config('services.fresha.*')` or `config('services.apify.*')` — these are legacy remnants; flag any NEW code that actively consumes them (the keys' existence is intentional and not a finding; reading them in new logic is).

### (6) Secret rotation readiness

- Secrets used in multiple config keys without a `_VERSION` or `_KID` discriminator — rotation requires touching multiple keys simultaneously.
- HMAC secrets (Supabase auth hook, Supabase email hook) with no documented rotation runbook in a comment or the docs. Both hooks are verified via `VerifySupabaseAuthHookSignature` / `VerifySupabaseEmailHookSignature` — confirm the config key and its `.env.example` placeholder are consistent.
- JWT signing keys stored as a single env var with no expiry concept — can't rotate without a brief window of auth failures.
- Cloudflare API token rotation: confirm the `config('services.cloudflare.token')` path is documented and the token scope (KV write + cache purge) is minimal-privilege.
- Twitch/Kick OAuth credentials (`config('services.twitch.*')`, `config('services.kick.*')`) — confirm both are in `.env.example` with placeholder values and there is a note on which scopes are required.

## Per-finding requirements

For every finding:
- Cite the category number (1–6).
- For category 1: quote the `env()` call and the file/line.
- For category 2: name the missing key and the config path that needs it.
- For category 3: quote the flag access and state what the current default resolves to.
- Name the canonical fix: `config('partna.key')`, add to `.env.example` with a placeholder + comment, `env('KEY', false)` for boolean flags, extract to `config/partna.php`.

## Suggested per-domain scope groups

### Group A — Config files (source of truth)
```
--scope config
```

### Group B — Services (highest env() misuse risk)
```
--scope app/Services
```

### Group C — Jobs and middleware
```
--scope app/Jobs
--scope app/Http/Middleware
```

### Group D — env.example (reference)
```
--scope .env.example
```

### Group E — App bootstrap + CI + deploy
```
--scope bootstrap/app.php
--scope bootstrap/providers.php
--scope .github/workflows
--scope deploy
```

## Exhaustiveness directive

Every `env()` call in any file outside `config/` is a finding. Grep the entire `app/` directory for `env(` before concluding — do not rely on spot-checks. Every config key in `config/services.php` and `config/partna.php` must be cross-referenced against `.env.example`.
