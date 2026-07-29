# Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline

Audit the **test suite** for coverage of the code paths that the other lenses identify as risky. A static-analysis audit finds *what could break*; a test-coverage audit finds *whether the safety net catches it*. The combination is what gives confidence at pilot.

This lens reads under `tests/`, `app/`, and `database/factories/`. It does not run the tests — that's CI's job. It looks for **missing tests, brittle tests, and tests that lie about coverage**.

## Use the lens prefix `TEST` for findings

Number them `TEST-1`, `TEST-2`, … sequentially.

## Partna testing conventions (current)

- **Pest 4 + PHPUnit, Mockery.** Feature tests under `tests/Feature/`, unit tests under `tests/Unit/`.
- **SQLite in-memory** — NOT a real Supabase Postgres. Tests use attached schema stand-ins; schema-specific helpers in `tests/Pest.php`: `attachTestSchemas()`, `setup*Table()` helpers (e.g. `setupUsersTable()`, `setupSitesTable()`, `setupMediaTables()`), `shimPgAdvisoryLockForSqlite()`. `SQLITE_MAX_ATTACHED=10` is already full — `information_schema` tests seed a stand-in (exemplar: `WriteDesignKitTest.php`).
- **Auth helpers in `tests/Pest.php`**: `actingAsUser($user)` and `actingAsStaff($user)`. No `actingAsBrand` / `actingAsAffiliate` helpers — those are removed.
- **Vendor mocks are fine** — `Http::fake()`, Mockery for vendor SDKs (Twitch, Kick, Cloudflare, Postmark/Resend). Do not mock Eloquent models; the DB layer must be real (factory-seeded SQLite).
- **Structural sweep tests** are the house pattern and the authoritative benchmark for what CI enforces: `tests/Feature/Security/` (PolicyCoverageTest, Aal2RouteCoverageTest, EmailVerifiedRouteCoverageTest, BotProtectionCoverageTest, PiiLogHygieneSweepTest, TenantIsolation/, FunctionSearchPathTest, DesignKitsRlsTest, AdminOnlyWritePoliciesTest, ModerationPolicyCoverageTest, StaffUserControllerFreshAal2Test…) and `tests/Feature/Queue/JobHygienePolicyTest.php`. New structural invariants should extend this pattern.
- **Larastan/PHPStan** is active (`composer analyse` with a baseline). Do not re-flag symbol-existence issues (undefined methods/properties/classes/config keys) — Larastan already enforces those.
- **SQLite PG-constraint gap**: SQLite cannot exercise Postgres CHECK / FK / UNIQUE constraints. Schema-invariant tests that must verify a constraint grep the migration SQL instead of inserting invalid rows. Verify how existing tests handle this before asserting a specific approach.

## Findings categories

### (1) Critical-path coverage

Each of the following code paths is foundational to the platform's operation. They MUST have feature tests covering happy path + at least one failure path. Flag any without.

- **Public sitepage resolution**: `IndividualProfileController` → `PublicSiteResolver` / `SiteCacheService` — cache hit path, cache miss path, unknown handle → 404.
- **Handle alias 301s**: a renamed handle's old subdomain must 301 to the canonical URL; expired alias must return 404 (not redirect). `site.site_subdomain_aliases` / `core.user_handle_aliases` `->active()` scope.
- **Handle rename lifecycle**: rename writes alias rows (`reclaim_until`, `expires_at`), old handle blocked during reclaim window, re-registration after expiry succeeds.
- **Account deletion / GDPR export**: `GdprPolicy` gates, deletion audit trail in `audit` schema, export job happy path + failure path, stale-export sweep (`gdpr:sweep-stale-exports`).
- **Analytics ingest**: `RecordAnalyticsEventJob` dedup logic — same event posted twice produces one row, not two.
- **Moderation state machine**: state transitions (open → triaged → under_review → resolved/escalated) — each allowed transition, each forbidden transition returning the correct HTTP status.
- **Uploads / media pipeline**: `ProcessImageVariantsJob` and `ProcessVideoVariantsJob` — happy path + variant-failure path; `DeleteMediaArtifactsJob` — confirmed artifact removal.
- **KV sync job**: `SyncSubdomainToKvJob` — is the ONLY writer to Cloudflare KV; confirm tests verify it dispatches on site create/rename and that no other code path writes KV directly.
- **AccountCapabilities gates**: confirm at least one test per capability-gated feature verifies the gate rejects the action when the capability is absent.

### (2) Webhook idempotency + signature tests

- Every webhook controller in `app/Http/Controllers/Api/Webhooks/` (currently `SupabaseAuthHookController`) and every inbound hook in `app/Http/Controllers/Api/Internal/` should have at least two tests: signature pass and signature fail. `tests/Feature/Webhooks/SupabaseAuthHookSignatureTest.php` exists — verify it covers both paths and a re-delivery (same payload twice → second is a no-op).
- Malformed payload test — handler doesn't crash, returns 400 / 422 cleanly.
- `VerifySupabaseHookSignature` HMAC verification (unified middleware, covering both the auth-hook and email-hook routes) — confirm the middleware tests cover an invalid signature and a missing signature header separately for each aliased route.

### (3) Policy ability coverage

- Every method on every Policy class (`app/Policies/`: BasePolicy, CasePolicy, CustomerPolicy, DecisionPolicy, EnquiryPolicy, FeatureFlagPolicy, FeedbackPolicy, GdprPolicy, IntegrationConnectionPolicy, NotificationPolicy, PartnaStaffPolicy, ServicePolicy, SitePolicy, UserSelfPolicy) should have at least one test asserting `allowed` and one asserting `denied` for the appropriate actor.
- Sweep `app/Policies/*.php` — for each `public function` (excluding `BasePolicy` inherited methods), confirm a corresponding `it()` test exists. **Policy tests live in FOUR places, not one**: `tests/Unit/Policies/` (12 files), `tests/Feature/Security/PolicyEnforcement/` (21), `tests/Feature/Security/TenantIsolation/` (10) and `tests/Feature/Policies/` (1). Searching only the last of those undercounts by ~43 files and manufactures a phantom gap — that is exactly how #TEST-15 was filed on 2026-07-28.
- `authorizeForUser` calls in controllers without a paired policy test of the gate they invoke.
- **404-on-not-yours assertion**: per CLAUDE.md, denied-because-not-yours must 404, not 403. Flag policy tests that assert 403 where 404 is the contract.
- `PolicyCoverageTest.php` sweeps model-to-policy registration; flag any new model in `app/Models/` that would fail this sweep (i.e. lacks a `Gate::policy()` registration or a justified `POLICY_EXEMPT` entry).

### (4) Mock-vs-integration discipline

- DB mocks (`Mockery::mock(Model::class)`, `$this->mock(...)` on an Eloquent class, `Eloquent::shouldReceive`) — flag every instance. The DB layer must be real (factory-seeded SQLite).
- Tests that mock observers / trigger-side-effects — defeats the observer-correctness check.
- Migration-dependent tests that don't call the relevant `setup*Table()` helper (or `attachTestSchemas()`) — will silently pass on stale schema.
- Vendor SDK call sites tested without mocking the vendor (real Twitch/Kick/Cloudflare/Resend HTTP calls in CI) — slow and flaky. `Http::fake()` is the correct pattern.

### (5) Race-condition / concurrency tests

- Cache-lock paths that depend on `CacheLockService::rememberLocked` should have a test asserting two concurrent paths produce a single correct outcome (dispatch-twice-job test or a lock-contention assertion).
- `SyncSubdomainToKvJob` concurrent dispatch — confirm two dispatches for the same site produce one KV write, not two stale overwrites.
- Handle rename during in-flight alias lookup — confirm the `->active()` scope correctly filters the concurrent state.

### (6) Failed-job + retry coverage

- Every job with a `failed()` handler should have a test asserting `failed()` is reachable and produces the expected side-effect (notification, alert, retry counter increment).
- `$backoff` is CI-enforced by `JobHygienePolicyTest.php` — do not re-flag absence of `$backoff`. Do flag incorrect values (e.g. `$backoff = [1]` on a job calling a vendor API that needs exponential back-off).
- Idempotency under retry — every job that mutates state should have a test that runs `handle()` twice and asserts identical end-state.

### (7) Migration / schema-invariant tests

- Every migration introducing a Postgres CHECK constraint should have an invariant test asserting the constraint's SQL is present in the migration file (grep pattern, not live DB insert — SQLite can't exercise Postgres constraints).
- Migrations adding UNIQUE / FK should have parallel grep-based invariant assertions.
- New `site.design_kits` columns must be NULLABLE with no DB-level DEFAULT (spec §8 hard rule) — confirm the existing `WriteDesignKitTest.php` exemplar pattern covers this assertion for new columns as they're added.
- `site.themes` table must be absent (dropped in the architecture-system cleanup) — add an invariant that grepping migrations for `CREATE TABLE site.themes` produces no result after the drop migration.

### (8) Resource class + Form Request coverage

- Every `Resource` class should have a snapshot test asserting the keys returned (catches accidental PII leaks on refactor). Priority: `IndividualProfileResource`, `UserPublicResource`, `UserStaffResource` — the public/staff split is the highest-risk audience-confusion surface.
- Every `FormRequest` should have a test asserting at least one valid + one invalid payload.
- Form Requests behind feature-flagged routes need both flagged-on and flagged-off tests.

### (9) Seed determinism + factory hygiene

- `database/factories/*` that call `faker->randomElement` on values that need to be deterministic for tests (e.g. status enums must produce all states across the test suite, not just one).
- Factories that don't relate to the model's required FK (creating a `SiteMedia` without a `site_id`) — silently inserts nulls or fails on FK.
- Factories used as fixtures in tests where a specific state matters but the factory's default is used — flag where the default is non-deterministic.

## Per-finding requirements

For every finding:
- Cite the category number (1–9).
- Name the canonical fix: `add it('happy path', ...)` + `it('failure path', ...)`, `assert dedup on re-delivery`, `replace Mockery::mock(Model) with real factory()`, `add denyAsNotFound assertion`, `add concurrent-dispatch test`.
- Quote the file path of the production code that lacks coverage, AND (if it exists) the path of the closest existing test file that should host the new test.
- A finding can be P0 if it's a critical-path (category 1) with no coverage at all — regression risk on a foundational path.

## Out of scope — do NOT re-flag

- Tests for Shopify / Stripe / Square / Fresha / commerce / booking / brand / affiliate (all removed).
- Code that's intentionally untested because it's a thin wrapper (Resource class straight-through, model getters without logic) — only flag when there's branching logic.
- Coverage percentage targets — meaningless without context.
- Symbol-existence issues (Larastan covers these).
- Absence of `$backoff` on ShouldQueue jobs (JobHygienePolicyTest.php enforces this in CI — already covered).

## Before filing ANY "has no tests" finding — mandatory check

This lens has a documented history of false positives in ONE direction: it
claims code is untested when the tests exist somewhere the run's `--scope` did
not reach. On the 2026-07-28 sweep this produced **six** wrong findings —
#TEST-16, #TEST-13, #TEST-1, #TEST-3, #TEST-17 and most of #TEST-9 — several
for classes whose tests were added in the *same commit* as the class itself.
It has never once over-reported. Assume the gap is yours, not the codebase's.

So, before writing "X has no test coverage":

1. **Search the whole repo, not the scope.** `git grep -Pn "ClassName" -- tests/`
   (`grep -E` silently ignores `\b` on macOS and returns a false 0-match — use `-P`).
2. **Check the mirrored unit path.** A production class is conventionally
   tested at the same sub-path under `tests/Unit/` that it occupies under
   `app/` — a Routing class under `tests/Unit/Routing`, a Policy under
   `tests/Unit/Policies`, and so on. The domain scope groups below pair each
   production directory with its `tests/Feature/` counterpart; where the
   matching `tests/Unit/` directory is not ALSO listed, it is invisible to that
   run even though the file exists. That single omission is what produced
   #TEST-13 (`PublicSuffixList` "has no unit tests" — its test had shipped in
   the same commit as the class).
3. **Check `git log --diff-filter=A -- <test path>`.** If the test landed in the
   same commit as the class, the coverage was never absent and the finding is
   false at adjudication time, not merely stale.
4. **State where you looked** in the finding. A coverage claim without a
   negative search is not evidence.

A false "no tests" finding is expensive twice over: it burns a unit of work to
disprove, and it trains the reader to distrust real findings.

## Suggested per-domain scope groups

### Group A — Critical-path + webhook tests
```
--scope tests/Feature/PublicSite
--scope tests/Feature/Subdomain
--scope tests/Feature/Webhooks
--scope tests/Feature/Account
--scope app/Http/Controllers/Api/PublicSite
--scope app/Http/Controllers/Api/Webhooks
--scope app/Jobs/Cloudflare
```

### Group B — Policy + auth coverage
```
--scope tests/Feature/Policies
--scope tests/Unit/Policies
--scope tests/Feature/Auth
--scope tests/Unit/Auth
--scope tests/Feature/Security
--scope tests/Unit/Security
--scope app/Policies
--scope app/Http/Middleware/Auth
```

### Group C — Resource / Form Request structure
```
--scope tests/Feature/Resources
--scope tests/Unit/Resources
--scope tests/Feature/Requests
--scope tests/Unit/Requests
--scope app/Http/Resources
--scope app/Http/Requests
```

### Group D — Jobs, analytics, media
```
--scope tests/Feature/Jobs
--scope tests/Unit/Jobs
--scope tests/Feature/Queue
--scope tests/Feature/Analytics
--scope tests/Feature/Media
--scope app/Jobs
```

### Group E — Migration invariants + factories
```
--scope tests/Feature/Database
--scope supabase/migrations
--scope database/factories
```

### Group F — Platform integration tests (largest surface, heaviest mocking)
```
--scope tests/Feature/Platforms
--scope tests/Unit/Platforms
--scope app/Services/Platforms
--scope app/Http/Controllers/Api/Platforms
```

### Group G — Unit suite (mock-vs-integration discipline)
```
--scope tests/Unit
```

### Group H — Domain feature tests (site / staff / moderation / notifications)
```
--scope tests/Feature/Site
--scope tests/Feature/Staff
--scope tests/Feature/Moderation
--scope tests/Feature/Notifications
--scope tests/Feature/User
```

### Group I — Catalog + Routing subsystem (new platform-catalog & link-router)
```
--scope tests/Feature/Catalog
--scope tests/Unit/Catalog
--scope tests/Feature/Routing
--scope tests/Unit/Routing
--scope tests/Feature/Ingest
--scope tests/Unit/Ingest
--scope tests/Unit/Content
--scope tests/Unit/Site
--scope tests/Feature/Site
--scope tests/fixtures/Routing
--scope app/Catalog
--scope app/Routing
--scope app/Ingest
--scope app/Content
--scope app/Site
--scope app/Http/Controllers/Api/Catalog
--scope app/Http/Controllers/Api/Routing
```

## Exhaustiveness directive

Walk every production file in scope and check for a corresponding test. Walk every test file and check it asserts what it claims. Emit a finding for every distinct quotable gap. **A coverage audit that under-reports gives false confidence — exactly the failure mode you're auditing against.**
