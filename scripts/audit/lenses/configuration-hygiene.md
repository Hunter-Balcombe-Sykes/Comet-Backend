# Configuration Hygiene: env() outside config, missing .env.example keys, feature flags without defaults

Hunt **`env()` calls outside of config files**, **missing `.env.example` entries for active config keys**, **feature flags with no safe default**, and **config values used inconsistently** (hardcoded in some places, config-driven in others). Configuration bugs are invisible until deployment — a missing env var silently uses `null`, a forgotten `.env.example` key blocks onboarding a new environment.

Partna uses `config/sidest.php` for all platform feature config and limits. All env vars must be declared in `.env.example`. Feature flags follow the pattern `SIDEST_<FEATURE>_ENABLED` and must have a safe-off default (`false`) so new environments don't accidentally enable unfinished features.

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
- Feature flags defaulting to `true` — new environments enable the feature without opting in, which is wrong for unfinished or billing-gated features.
- Feature flags that are checked in some code paths but not others — partial enablement leads to inconsistent state.
- Flags in `config/sidest.php` that don't follow the `SIDEST_<FEATURE>_ENABLED` naming convention — harder to grep and audit.
- Boolean flags read via `config('sidest.feature')` in some places and `env('SIDEST_FEATURE')` directly in others — split config access pattern.

### (4) Hardcoded values that should be config-driven

- Magic numbers in service classes (retry counts, timeouts, rate limits, page sizes) that are hardcoded as literals rather than referencing `config('sidest.limits.*')`.
- API version strings (`2026-04`) hardcoded in multiple service classes — should be a single config value so version bumps are one-line changes.
- Queue names (`'commerce'`, `'notifications'`) hardcoded as string literals across job files — a typo creates a silent routing failure.
- Stripe plan IDs, price IDs, or product IDs hardcoded in service classes instead of `config('services.stripe.*')`.
- Shopify API scopes hardcoded in the OAuth flow instead of a config array.

### (5) Config file correctness

- `config/services.php` entries that reference vendor keys not in `.env.example`.
- `config/sidest.php` entries with no corresponding usage in the codebase — dead config.
- Nested config keys accessed with incorrect dot notation (`config('sidest.limits_max')` instead of `config('sidest.limits.max')`) — silently returns null.
- `config('app.url')` used to construct API callback URLs instead of `url('/')` — breaks when `APP_URL` is not set or is wrong in a given environment.
- `config('queue.default')` overridden in a job via `onQueue()` with a hardcoded string — the queue override bypasses environment-specific queue routing.

### (6) Secret rotation readiness

- Secrets used in multiple config keys without a `_VERSION` or `_KID` discriminator — rotation requires touching multiple keys simultaneously.
- HMAC secrets (Shopify webhook, embedded session token) with no documented rotation runbook in a comment or README.
- JWT signing keys stored as a single env var with no expiry concept — can't rotate without a brief window of auth failures.
- `SHOPIFY_API_SECRET` rotation: confirm the invalidation pattern for JTI cache (`redis-cli --scan --pattern 'partna:shopify-jti:*'`) is documented (CLAUDE.md documents this; confirm the config side is consistent).

## Per-finding requirements

For every finding:
- Cite the category number (1–6).
- For category 1: quote the `env()` call and the file/line.
- For category 2: name the missing key and the config path that needs it.
- For category 3: quote the flag access and state what the current default resolves to.
- Name the canonical fix: `config('sidest.key')`, add to `.env.example` with a placeholder + comment, `env('KEY', false)` for boolean flags, extract to `config/sidest.php`.

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

## Exhaustiveness directive

Every `env()` call in any file outside `config/` is a finding. Grep the entire `app/` directory for `env(` before concluding — do not rely on spot-checks. Every config key in `config/services.php` and `config/sidest.php` must be cross-referenced against `.env.example`.
