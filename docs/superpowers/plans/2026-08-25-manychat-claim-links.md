# ManyChat Claim Links Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let ManyChat create a pre-account build over a signed webhook and DM the lead a claim link that proves invitation, so a DM'd person can claim without an email address and without staff intervention.

**Architecture:** Pull, not Push — ManyChat calls us, we return a one-time claim URL, ManyChat sends the DM itself. A random token is minted per NEW build only; we store SHA-256 of it, never the plaintext. Presenting a valid token satisfies the existing invite-gate in `ClaimSiteService`, and the hash is cleared inside the claim transaction on success.

**Tech Stack:** Laravel 12, PHP 8.4, Pest 4, Supabase Postgres (raw SQL migrations in `supabase/migrations/`).

**Spec:** `docs/superpowers/specs/2026-08-25-manychat-claim-link-design.md`

## Global Constraints

- **Never create a Laravel migration file.** Schema changes are raw SQL in `supabase/migrations/`. The composer guard rejects Laravel migrations.
- **Never return raw Eloquent from an endpoint.** Use a Resource class.
- **Never write an inline `abort_unless(..., 403)`.** CI fails the build on inline 403s.
- Tests run **SQLite**, production is **Postgres**. The columns added here are nullable `text`/`timestamptz` with no CHECK, so the SQLite lane is representative — no `tests/Postgres/` addition needed.
- 4-space indent, LF. Comments explain **why**, not what. No banners.
- `pint --test` is the CI gate, **not** `pint` (which silently fixes then reports "passed"). Run `--test`.
- Token plaintext is returned **exactly once**, in the create response. It is never stored, never logged, never re-returned by any GET.
- A claim token is minted **only when `requestBuild()` returns `reused === false`**. This is the control that bounds the webhook's static-secret auth (spec §5.4).
- "Single-use" means **used, not opened** — the hash clears on a *successful* claim only (spec §4).

---

### Task 1: Schema + model for the claim token

**Files:**
- Create: `supabase/migrations/20260825120000_pre_account_builds_claim_token.sql`
- Modify: `app/Models/Core/User/PreAccountBuild.php` (docblock ~line 27, `$casts` ~line 82)
- Test: `tests/Unit/Models/PreAccountBuildTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `PreAccountBuild::$claim_token_hash` (`?string`), `PreAccountBuild::$claim_token_issued_at` (`?Carbon`). Both **not fillable**.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Models/PreAccountBuildTest.php`:

```php
it('does not mass-assign the claim token columns', function () {
    $build = new PreAccountBuild([
        'claim_token_hash' => 'attacker-supplied',
        'claim_token_issued_at' => now(),
    ]);

    expect($build->claim_token_hash)->toBeNull()
        ->and($build->claim_token_issued_at)->toBeNull();
});

it('casts claim_token_issued_at to a date', function () {
    $build = new PreAccountBuild;
    $build->forceFill(['claim_token_issued_at' => '2026-08-25 04:24:13']);

    expect($build->claim_token_issued_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/pest tests/Unit/Models/PreAccountBuildTest.php`
Expected: FAIL — the cast test errors because `claim_token_issued_at` comes back as a plain string.

- [ ] **Step 3: Write the migration**

Create `supabase/migrations/20260825120000_pre_account_builds_claim_token.sql`:

```sql
-- ManyChat claim links: a per-build capability that proves invitation.
--
-- Spec: docs/superpowers/specs/2026-08-25-manychat-claim-link-design.md
--
-- Stores the SHA-256 of the token, never the token. A DB read (backup, support
-- query, log) must not yield a working takeover capability for every live
-- build; the plaintext is returned exactly once, at mint time, and never again.
--
-- No index: the token is never a lookup key. The claim path finds the build by
-- subdomain and then compares hashes, so an index would buy nothing and would
-- make the column's distribution visible in pg_stats.
--
-- Nullable with no default and no backfill: every existing build keeps a NULL
-- token and continues to claim through the email path exactly as before.
ALTER TABLE core.pre_account_builds
    ADD COLUMN claim_token_hash text,
    ADD COLUMN claim_token_issued_at timestamptz;

COMMENT ON COLUMN core.pre_account_builds.claim_token_hash IS
    'SHA-256 of the claim token. Plaintext is never stored. Cleared on successful claim (single-use).';
COMMENT ON COLUMN core.pre_account_builds.claim_token_issued_at IS
    'When the current claim token was minted. Observability + rotation only.';
```

- [ ] **Step 4: Apply to dev and verify**

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
supabase db push
```

Verify:

```sql
select column_name, data_type, is_nullable
from information_schema.columns
where table_schema='core' and table_name='pre_account_builds'
  and column_name like 'claim_token%';
```

Expected: two rows, both `is_nullable = YES`.

- [ ] **Step 5: Update the model**

In `app/Models/Core/User/PreAccountBuild.php`, add to the class docblock after the `@property Carbon|null $invited_at` line:

```php
 * @property string|null $claim_token_hash SHA-256 of the claim token (spec §4). Plaintext is NEVER stored. NULL = no live token. Not fillable — minted via ClaimTokenIssuer.
 * @property Carbon|null $claim_token_issued_at When the current token was minted. Not fillable.
```

Add to `$casts`:

```php
        'claim_token_issued_at' => 'datetime',
```

Leave `$fillable` **unchanged** — the omission is the guard, matching the existing `user_id` / `build_state` precedent documented above that array.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php vendor/bin/pest tests/Unit/Models/PreAccountBuildTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add supabase/migrations/20260825120000_pre_account_builds_claim_token.sql app/Models/Core/User/PreAccountBuild.php tests/Unit/Models/PreAccountBuildTest.php
git commit -m "feat(pre-account): claim_token_hash + claim_token_issued_at columns

Stores SHA-256, never plaintext. Not fillable. No index — the token is
never a lookup key; the claim path finds the build by subdomain first."
```

---

### Task 2: `ClaimTokenIssuer`

**Files:**
- Create: `app/Services/PreAccount/ClaimTokenIssuer.php`
- Test: `tests/Unit/Services/PreAccount/ClaimTokenIssuerTest.php`

**Interfaces:**
- Consumes: `PreAccountBuild::$claim_token_hash`, `$claim_token_issued_at` (Task 1).
- Produces:
  - `ClaimTokenIssuer::issue(PreAccountBuild $build): string` — mints, persists the hash, returns plaintext once.
  - `ClaimTokenIssuer::matches(PreAccountBuild $build, ?string $presented): bool` — constant-time compare; **false** when no token, no presented value, or the build has expired.
  - `ClaimTokenIssuer::burn(PreAccountBuild $build): void` — clears the hash.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/PreAccount/ClaimTokenIssuerTest.php`:

> **Why the local helper:** `PreAccountBuildFactory` creates NO user, but
> `pre_account_builds.user_id` is NOT NULL **and not fillable** — it must be set
> via `->user()->associate()` (see the `$fillable` comment in the model). And the
> helper is defined per-file on purpose: cross-file Pest helpers break under
> `--parallel`.

```php
<?php

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimTokenIssuer;

function issuerBuild(array $attrs = []): PreAccountBuild
{
    $user = User::factory()->create([
        'status' => 'unclaimed',
        'auth_user_id' => null,
        'primary_email' => null,
    ]);

    $build = PreAccountBuild::factory()->make($attrs);
    $build->user()->associate($user);   // user_id is NOT fillable
    $build->save();

    return $build->fresh();
}

it('mints a token, stores only its hash, and never persists the plaintext', function () {
    $build = issuerBuild();

    $plain = app(ClaimTokenIssuer::class)->issue($build);

    expect($plain)->toBeString()->not->toBe('')
        ->and($build->fresh()->claim_token_hash)->toBe(hash('sha256', $plain))
        ->and($build->fresh()->claim_token_hash)->not->toBe($plain)
        ->and($build->fresh()->claim_token_issued_at)->not->toBeNull();
});

it('matches the token it minted', function () {
    $build = issuerBuild();
    $issuer = app(ClaimTokenIssuer::class);
    $plain = $issuer->issue($build);

    expect($issuer->matches($build->fresh(), $plain))->toBeTrue();
});

it('rejects a wrong token', function () {
    $build = issuerBuild();
    $issuer = app(ClaimTokenIssuer::class);
    $plain = $issuer->issue($build);

    expect($issuer->matches($build->fresh(), $plain.'x'))->toBeFalse();
});

it('rejects an absent presented token', function () {
    $build = issuerBuild();
    app(ClaimTokenIssuer::class)->issue($build);

    expect(app(ClaimTokenIssuer::class)->matches($build->fresh(), null))->toBeFalse();
});

it('rejects an empty presented token', function () {
    $build = issuerBuild();
    app(ClaimTokenIssuer::class)->issue($build);

    expect(app(ClaimTokenIssuer::class)->matches($build->fresh(), ''))->toBeFalse();
});

it('rejects any token on a build with no live token', function () {
    $build = issuerBuild(['expires_at' => now()->addDays(30)]);

    expect(app(ClaimTokenIssuer::class)->matches($build, 'anything'))->toBeFalse();
});

it('refuses a valid token on an expired build', function () {
    $build = issuerBuild(['expires_at' => now()->subMinute()]);
    $issuer = app(ClaimTokenIssuer::class);
    $plain = $issuer->issue($build);

    expect($issuer->matches($build->fresh(), $plain))->toBeFalse();
});

it('accepts a valid token on a never-expiring build', function () {
    $build = issuerBuild(['expires_at' => null]);
    $issuer = app(ClaimTokenIssuer::class);
    $plain = $issuer->issue($build);

    expect($issuer->matches($build->fresh(), $plain))->toBeTrue();
});

it('burns the token so a replay fails', function () {
    $build = issuerBuild();
    $issuer = app(ClaimTokenIssuer::class);
    $plain = $issuer->issue($build);

    $issuer->burn($build);

    expect($build->fresh()->claim_token_hash)->toBeNull()
        ->and($issuer->matches($build->fresh(), $plain))->toBeFalse();
});

it('mints a different token every time', function () {
    $build = issuerBuild();
    $issuer = app(ClaimTokenIssuer::class);

    expect($issuer->issue($build))->not->toBe($issuer->issue($build));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/pest tests/Unit/Services/PreAccount/ClaimTokenIssuerTest.php`
Expected: FAIL — `Target class [App\Services\PreAccount\ClaimTokenIssuer] does not exist.`

- [ ] **Step 3: Write the implementation**

Create `app/Services/PreAccount/ClaimTokenIssuer.php`:

```php
<?php

namespace App\Services\PreAccount;

use App\Models\Core\User\PreAccountBuild;

// Mints and verifies the per-build claim capability that proves invitation
// (spec §4). The plaintext exists only in the request that minted it — we
// persist SHA-256 so a DB read yields no working capability.
class ClaimTokenIssuer
{
    // 32 bytes, base64url. Deliberately NOT a UUIDv7: the build's own id is
    // UUIDv7 and leaks creation time in its prefix. A capability should not.
    public function issue(PreAccountBuild $build): string
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $build->forceFill([
            'claim_token_hash' => hash('sha256', $plain),
            'claim_token_issued_at' => now(),
        ])->save();

        return $plain;
    }

    // Expiry lives here, not at the call sites, so every caller inherits it —
    // builds:prune-expired deletes the row eventually, but a not-yet-pruned
    // expired build must not be claimable in the meantime.
    public function matches(PreAccountBuild $build, ?string $presented): bool
    {
        $hash = (string) $build->claim_token_hash;
        $presented = (string) $presented;

        if ($hash === '' || $presented === '') {
            return false;
        }

        if ($build->expires_at !== null && now()->gte($build->expires_at)) {
            return false;
        }

        return hash_equals($hash, hash('sha256', $presented));
    }

    // Single-use = USED, not opened (spec §4). Call ONLY after ownership has
    // actually transferred, inside the claim transaction, so a claim that
    // throws rolls this back with it.
    public function burn(PreAccountBuild $build): void
    {
        $build->forceFill(['claim_token_hash' => null])->save();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/pest tests/Unit/Services/PreAccount/ClaimTokenIssuerTest.php`
Expected: PASS, 10 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/PreAccount/ClaimTokenIssuer.php tests/Unit/Services/PreAccount/ClaimTokenIssuerTest.php
git commit -m "feat(pre-account): ClaimTokenIssuer — mint, verify, burn

Stores SHA-256 only. Expiry is enforced inside matches() so every call
site inherits it. burn() is success-only, per spec §4."
```

---

### Task 3: Webhook auth middleware, config, and rate limiter

**Files:**
- Create: `app/Http/Middleware/Auth/VerifyManyChatWebhook.php`
- Modify: `config/services.php` (after the `supabase` block, ~line 55)
- Modify: `bootstrap/app.php:182` (middleware alias list)
- Modify: `app/Providers/AppServiceProvider.php` (after the `pre-account-build` limiter, ~line 648)
- Modify: `.env.example`
- Test: `tests/Feature/Api/Internal/ManyChatWebhookAuthTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: middleware alias `manychat.webhook`; rate limiter `manychat-build`; config key `services.manychat.webhook_secret`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/Internal/ManyChatWebhookAuthTest.php`:

```php
<?php

// The route itself lands in Task 4; these assertions are about the GATE, so
// they hit the same path and only ever assert on 401/503 — never on 2xx.

it('returns 503 when the webhook secret is not configured', function () {
    config(['services.manychat.webhook_secret' => '']);

    $this->postJson('/api/internal/webhooks/manychat/builds', [])
        ->assertStatus(503)
        ->assertJsonPath('error', 'hook_not_configured');
});

it('returns 401 when the secret header is absent', function () {
    config(['services.manychat.webhook_secret' => 'a-test-secret-value']);

    $this->postJson('/api/internal/webhooks/manychat/builds', [])
        ->assertStatus(401);
});

it('returns 401 when the secret header is wrong', function () {
    config(['services.manychat.webhook_secret' => 'a-test-secret-value']);

    $this->withHeader('X-Partna-Webhook-Secret', 'not-the-secret')
        ->postJson('/api/internal/webhooks/manychat/builds', [])
        ->assertStatus(401);
});

it('does not answer 401 or 503 once the correct secret is presented', function () {
    config(['services.manychat.webhook_secret' => 'a-test-secret-value']);

    $response = $this->withHeader('X-Partna-Webhook-Secret', 'a-test-secret-value')
        ->postJson('/api/internal/webhooks/manychat/builds', []);

    // 422 (validation) is the expected answer for an empty body past the gate.
    expect($response->status())->not->toBe(401)->not->toBe(503);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/pest tests/Feature/Api/Internal/ManyChatWebhookAuthTest.php`
Expected: FAIL — 404, the route does not exist yet.

- [ ] **Step 3: Add the config key**

In `config/services.php`, after the closing `],` of the `'supabase'` block:

```php
    // ManyChat marketing automation → POST /api/internal/webhooks/manychat/builds.
    //
    // A STATIC shared secret, not an HMAC signature (spec §5.1): ManyChat's
    // External Request action can set headers but cannot sign a request body,
    // so the Standard Webhooks scheme used for Supabase/Resend is unavailable.
    // Weaker by construction — the control that bounds it is that a claim token
    // is only ever minted for a NEW build (spec §5.4), never a deduped one.
    //
    // Rotate by changing this env var and the ManyChat flow's header together.
    'manychat' => [
        'webhook_secret' => env('MANYCHAT_WEBHOOK_SECRET'),
    ],
```

- [ ] **Step 4: Add the env var placeholder**

Append to `.env.example`:

```
# ManyChat webhook shared secret (>=32 bytes). Unset = the endpoint 503s.
MANYCHAT_WEBHOOK_SECRET=
```

- [ ] **Step 5: Write the middleware**

Create `app/Http/Middleware/Auth/VerifyManyChatWebhook.php`:

```php
<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Static shared-secret gate for the ManyChat build webhook.
 *
 * Deliberately NOT VerifySupabaseHookSignature: that verifies a Standard
 * Webhooks HMAC over the body, and ManyChat's External Request action cannot
 * produce one (spec §5.1). This is the weaker scheme, chosen knowingly.
 *
 * 503 when the secret is unset — a deploy bug, fail-closed, matching
 * VerifySupabaseHookSignature's contract. 401 on mismatch.
 */
class VerifyManyChatWebhook
{
    public const HEADER = 'X-Partna-Webhook-Secret';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.manychat.webhook_secret', '');
        if ($secret === '') {
            Log::warning('manychat.webhook.misconfigured', ['reason' => 'secret_missing']);

            return response()->json([
                'error' => 'hook_not_configured',
                'message' => 'ManyChat hook is not configured.',
            ], 503);
        }

        $presented = (string) $request->header(self::HEADER, '');

        // hash_equals on the raw values: both are our own secrets, not
        // attacker-length-controlled digests, so no pre-hash is needed — but
        // the comparison still must not short-circuit on first byte.
        if ($presented === '' || ! hash_equals($secret, $presented)) {
            Log::warning('manychat.webhook.signature_failed');

            return response()->json([
                'error' => 'unauthorized',
                'message' => 'Invalid webhook credentials.',
            ], 401);
        }

        return $next($request);
    }
}
```

- [ ] **Step 6: Register the alias**

In `bootstrap/app.php`, in the middleware alias array immediately after the `'resend.webhook'` line:

```php
            'manychat.webhook' => VerifyManyChatWebhook::class,
```

Add the import at the top of the file alongside the other middleware imports:

```php
use App\Http\Middleware\Auth\VerifyManyChatWebhook;
```

- [ ] **Step 7: Add the rate limiter**

In `app/Providers/AppServiceProvider.php`, immediately after the closing `});` of the `pre-account-build` limiter:

```php
        // ManyChat build webhook. Each call can trigger an Apify-billed scrape,
        // so this is a spend guard as much as an abuse guard. Keyed on the
        // route (one shared caller), not the IP — ManyChat's egress IPs are not
        // published, so an IP key would silently split the bucket.
        RateLimiter::for('manychat-build', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return [Limit::none()];
            }

            return [
                Limit::perMinute((int) config('partna.throttle.manychat_build_per_minute', 10))
                    ->by('manychat:m')
                    ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)),

                Limit::perHour((int) config('partna.throttle.manychat_build_per_hour', 120))
                    ->by('manychat:h')
                    ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)),
            ];
        });
```

In `config/partna.php`, in the `throttle` array, add:

```php
        'manychat_build_per_minute' => (int) env('PARTNA_THROTTLE_MANYCHAT_BUILD_PER_MINUTE', 10),
        'manychat_build_per_hour' => (int) env('PARTNA_THROTTLE_MANYCHAT_BUILD_PER_HOUR', 120),
```

- [ ] **Step 8: Commit**

The route arrives in Task 4, so the auth test still fails here. Commit the gate anyway — it is self-contained and reviewable.

```bash
git add app/Http/Middleware/Auth/VerifyManyChatWebhook.php config/services.php config/partna.php bootstrap/app.php .env.example app/Providers/AppServiceProvider.php tests/Feature/Api/Internal/ManyChatWebhookAuthTest.php
git commit -m "feat(internal): ManyChat webhook gate — static secret, fail-closed

Static shared secret rather than Standard Webhooks HMAC: ManyChat cannot
sign a body (spec §5.1). 503 when unset, 401 on mismatch. Route lands next."
```

---

### Task 4: The webhook endpoint

**Files:**
- Create: `app/Http/Controllers/Api/Internal/ManyChatBuildController.php`
- Create: `app/Http/Requests/Api/Internal/ManyChatBuildRequest.php`
- Create: `app/Http/Resources/ManyChatBuildResource.php`
- Modify: `routes/api.php` (inside the same group as the other `/internal/webhooks/*` routes, ~line 56)
- Test: `tests/Feature/Api/Internal/ManyChatBuildTest.php`

**Interfaces:**
- Consumes: `ClaimTokenIssuer::issue()` (Task 2); `manychat.webhook` alias and `manychat-build` limiter (Task 3); `PreAccountBuildService::requestBuild(...): array{build: PreAccountBuild, reused: bool}`.
- Produces: `POST /api/internal/webhooks/manychat/builds` returning `data.build_id`, `data.subdomain`, `data.build_state`, `data.reused`, and `data.claim_url` **only when `reused === false`**.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/Internal/ManyChatBuildTest.php`:

```php
<?php

use App\Models\Core\User\PreAccountBuild;

beforeEach(function () {
    config(['services.manychat.webhook_secret' => 'a-test-secret-value']);
});

function manychatPost(array $body = []): \Illuminate\Testing\TestResponse
{
    return test()
        ->withHeader('X-Partna-Webhook-Secret', 'a-test-secret-value')
        ->postJson('/api/internal/webhooks/manychat/builds', $body + [
            'account_type' => 'partna',
            'source_type' => 'instagram',
            'source_ref' => 'amiconirestaurant',
        ]);
}

it('creates an outreach build and returns a claim url carrying a token', function () {
    $response = manychatPost()->assertStatus(202);

    $buildId = $response->json('data.build_id');
    $build = PreAccountBuild::findOrFail($buildId);

    expect($build->built_via)->toBe(PreAccountBuild::VIA_STAFF)
        ->and($build->isOutreach())->toBeTrue()
        ->and($build->claim_token_hash)->not->toBeNull();

    $claimUrl = $response->json('data.claim_url');
    expect($claimUrl)->toContain('/claim/')->toContain('?t=');

    // The plaintext in the URL must hash to what we stored, and must not BE it.
    parse_str((string) parse_url($claimUrl, PHP_URL_QUERY), $query);
    expect(hash('sha256', $query['t']))->toBe($build->claim_token_hash)
        ->and($query['t'])->not->toBe($build->claim_token_hash);
});

it('does NOT return a claim url when the build was deduped', function () {
    manychatPost()->assertStatus(202);

    // Same source_ref — requestBuild dedupes to the existing live build.
    $second = manychatPost()->assertStatus(200);

    expect($second->json('data.reused'))->toBeTrue()
        ->and($second->json('data.claim_url'))->toBeNull();
});

it('does not re-mint a token on the deduped path', function () {
    $first = manychatPost()->assertStatus(202);
    $build = PreAccountBuild::findOrFail($first->json('data.build_id'));
    $hashAfterFirst = $build->claim_token_hash;

    manychatPost()->assertStatus(200);

    expect($build->fresh()->claim_token_hash)->toBe($hashAfterFirst);
});

it('rejects an unknown source_type', function () {
    manychatPost(['source_type' => 'myspace'])->assertStatus(422);
});

it('requires account_type, source_type and source_ref', function () {
    test()->withHeader('X-Partna-Webhook-Secret', 'a-test-secret-value')
        ->postJson('/api/internal/webhooks/manychat/builds', [])
        ->assertStatus(422);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/pest tests/Feature/Api/Internal/ManyChatBuildTest.php`
Expected: FAIL — 404, route missing.

- [ ] **Step 3: Write the form request**

Create `app/Http/Requests/Api/Internal/ManyChatBuildRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Internal;

use App\Http\Requests\BaseFormRequest;

// ManyChat marketing build. Mirrors StaffCreatePreAccountBuildRequest's
// source/account_type pairing, minus the staff-only knobs — ManyChat does not
// choose publish state or attach a contact email.
class ManyChatBuildRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'account_type' => ['required', 'string', 'in:partna,business'],
            'source_type' => ['required', 'string', 'in:instagram,google_business'],
            'source_ref' => ['required', 'string', 'max:255'],
            'source_name' => ['nullable', 'string', 'max:255'],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }
}
```

- [ ] **Step 4: Write the resource**

Create `app/Http/Resources/ManyChatBuildResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Models\Core\User\PreAccountBuild;
use Illuminate\Http\Request;

// Webhook response for ManyChat. Carries the claim URL — including the ONE
// time the plaintext token is ever visible — so it must never be reused for a
// GET. The poll shape is PreAccountBuildStatusResource, which has no token.
/**
 * @mixin PreAccountBuild
 */
class ManyChatBuildResource extends ApiResource
{
    public function __construct(
        PreAccountBuild $resource,
        private readonly bool $reused,
        private readonly ?string $claimUrl,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return array_filter([
            'build_id' => $this->id,
            'build_state' => $this->build_state,
            'subdomain' => $this->user?->site?->subdomain,
            'reused' => $this->reused,
            // Absent on the deduped path — spec §5.4. A token is minted only
            // for a NEW build, so a leaked webhook secret cannot be used to
            // fetch a capability for a build someone else created.
            'claim_url' => $this->claimUrl,
        ], fn ($v) => $v !== null);
    }
}
```

- [ ] **Step 5: Write the controller**

Create `app/Http/Controllers/Api/Internal/ManyChatBuildController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Internal;

use App\Exceptions\PreAccountBuildException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Internal\ManyChatBuildRequest;
use App\Http\Resources\ManyChatBuildResource;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\ClaimTokenIssuer;
use App\Services\PreAccount\PreAccountBuildService;
use Illuminate\Http\JsonResponse;

// POST /api/internal/webhooks/manychat/builds — the ManyChat marketing surface.
//
// Exists because ManyChat cannot call POST /api/staff/builds: that group
// carries require.aal2, and an automation platform cannot do staff MFA.
//
// Pull, not Push (spec §3): we hand back a claim URL and ManyChat sends the DM
// itself from the flow where it already holds the Instagram subscriber.
class ManyChatBuildController extends ApiController
{
    public function __construct(
        private readonly PreAccountBuildService $builds,
        private readonly ClaimTokenIssuer $tokens,
    ) {}

    public function __invoke(ManyChatBuildRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $result = $this->builds->requestBuild(
                accountType: $data['account_type'],
                sourceType: $data['source_type'],
                rawSourceRef: $data['source_ref'],
                sourceName: $data['source_name'] ?? null,
                ipHash: null,
                staff: null,
                publish: true,
                expiresDays: isset($data['expires_days']) ? (int) $data['expires_days'] : null,
                contactEmail: null,
                // VIA_STAFF with no staff row: this is an outreach build made
                // FOR a business, so isOutreach() must be true, but no human
                // staff member created it.
                builtVia: PreAccountBuild::VIA_STAFF,
                autoInvite: false,
            );
        } catch (PreAccountBuildException $e) {
            return $this->error($e->getMessage(), 422, [], ['code' => $e->errorCode]);
        }

        $build = $result['build'];
        $build->loadMissing('user.site');

        // Mint ONLY for a genuinely new build (spec §5.4). On the deduped path
        // the caller would otherwise receive a working claim capability for a
        // build someone else created — which is what turns a leaked webhook
        // secret from "can create spam builds" into "can take over any build".
        $claimUrl = null;
        if (! $result['reused']) {
            $token = $this->tokens->issue($build);
            $claimUrl = rtrim((string) config('app.frontend_url'), '/')
                .'/claim/'.$build->user->site->subdomain
                .'?t='.$token;
        }

        return $this->success(
            (new ManyChatBuildResource($build, (bool) $result['reused'], $claimUrl))->resolve(),
            $result['reused'] ? 200 : 202,
        );
    }
}
```

- [ ] **Step 6: Register the route**

In `routes/api.php`, immediately after the Resend webhook route:

```php
    // ManyChat marketing builds. Static-secret gated (ManyChat cannot sign a
    // body — spec §5.1); throttled separately because each call can trigger an
    // Apify-billed scrape.
    Route::post('/internal/webhooks/manychat/builds', ManyChatBuildController::class)
        ->middleware(['manychat.webhook', 'throttle:manychat-build'])
        ->name('webhooks.manychat.builds');
```

Add the import:

```php
use App\Http\Controllers\Api\Internal\ManyChatBuildController;
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php vendor/bin/pest tests/Feature/Api/Internal/ManyChatBuildTest.php tests/Feature/Api/Internal/ManyChatWebhookAuthTest.php`
Expected: PASS — 5 + 4 tests. Both files green now that the route exists.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/Internal/ManyChatBuildController.php app/Http/Requests/Api/Internal/ManyChatBuildRequest.php app/Http/Resources/ManyChatBuildResource.php routes/api.php tests/Feature/Api/Internal/ManyChatBuildTest.php
git commit -m "feat(internal): ManyChat build webhook returns a one-time claim url

Mints a claim token ONLY when requestBuild reports reused=false. On the
deduped path the response carries no claim_url — the control that bounds
the static-secret auth (spec §5.4), pinned by two tests."
```

---

### Task 5: The claim path accepts a token

**Files:**
- Modify: `app/Http/Requests/Api/PublicSite/ClaimSiteRequest.php:26-32`
- Modify: `app/Services/PreAccount/ClaimSiteService.php:42` (signature) and `:85-97` (the two gates)
- Modify: `app/Http/Controllers/Api/PublicSite/ClaimController.php:54`
- Test: `tests/Feature/PreAccount/ClaimWithTokenTest.php`

**Interfaces:**
- Consumes: `ClaimTokenIssuer::matches()`, `::burn()` (Task 2).
- Produces: `ClaimSiteService::claim(string $uid, string $verifiedEmail, string $subdomain, bool $marketingOptIn = false, ?string $claimToken = null): array` — **new trailing optional parameter**, so every existing caller keeps working unchanged.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PreAccount/ClaimWithTokenTest.php`:

```php
<?php

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use App\Services\PreAccount\ClaimTokenIssuer;

// Mirrors the arrangement in tests/Feature/PreAccount/UnclaimedGatingTest.php:
// an unclaimed provisional user + site + an OUTREACH build with no
// contact_email — the exact combination that throws CLAIM_NOT_INVITED today.
function claimTokenBuild(array $attrs = []): PreAccountBuild
{
    $user = User::factory()->create([
        'status' => 'unclaimed',
        'auth_user_id' => null,
        'primary_email' => null,
    ]);
    Site::factory()->create(['user_id' => $user->id, 'is_published' => true]);

    $build = PreAccountBuild::factory()->make(array_merge([
        'built_via' => PreAccountBuild::VIA_STAFF,
        'contact_email' => null,
        'build_state' => PreAccountBuild::STATE_READY,
        'expires_at' => now()->addDays(30),
    ], $attrs));
    $build->user()->associate($user);   // user_id is NOT fillable
    $build->save();

    return $build->fresh();
}

// Returns [build, plaintext token].
function outreachBuildWithToken(array $attrs = []): array
{
    $build = claimTokenBuild($attrs);

    return [$build, app(ClaimTokenIssuer::class)->issue($build)];
}

it('claims an outreach build with no contact_email when a valid token is presented', function () {
    [$build, $token] = outreachBuildWithToken();

    $result = app(\App\Services\PreAccount\ClaimSiteService::class)->claim(
        uid: (string) \Illuminate\Support\Str::uuid(),
        verifiedEmail: 'someone@example.com',
        subdomain: $build->user->site->subdomain,
        marketingOptIn: false,
        claimToken: $token,
    );

    expect($result['professional']->status)->toBe('active');
});

it('still throws CLAIM_NOT_INVITED with no token', function () {
    [$build] = outreachBuildWithToken();

    expect(fn () => app(\App\Services\PreAccount\ClaimSiteService::class)->claim(
        (string) \Illuminate\Support\Str::uuid(),
        'someone@example.com',
        $build->user->site->subdomain,
    ))->toThrow(RuntimeException::class, 'CLAIM_NOT_INVITED');
});

it('still throws CLAIM_NOT_INVITED with a wrong token', function () {
    [$build, $token] = outreachBuildWithToken();

    expect(fn () => app(\App\Services\PreAccount\ClaimSiteService::class)->claim(
        (string) \Illuminate\Support\Str::uuid(),
        'someone@example.com',
        $build->user->site->subdomain,
        false,
        $token.'x',
    ))->toThrow(RuntimeException::class, 'CLAIM_NOT_INVITED');
});

it('burns the token so a replay is refused', function () {
    [$build, $token] = outreachBuildWithToken();
    $service = app(\App\Services\PreAccount\ClaimSiteService::class);

    $service->claim((string) \Illuminate\Support\Str::uuid(), 'first@example.com', $build->user->site->subdomain, false, $token);

    expect($build->fresh()->claim_token_hash)->toBeNull();
});

it('leaves the token intact when the claim throws', function () {
    [$build, $token] = outreachBuildWithToken();
    $service = app(\App\Services\PreAccount\ClaimSiteService::class);

    // Claim once so the site is taken, then replay with a fresh uid: the second
    // call throws ALREADY_CLAIMED. Prove a THROW does not consume a token by
    // using a second, independent build.
    [$other, $otherToken] = outreachBuildWithToken();
    $uid = (string) \Illuminate\Support\Str::uuid();
    $service->claim($uid, 'taken@example.com', $build->user->site->subdomain, false, $token);

    // Same uid now owns a site → ACCOUNT_EXISTS on the other build.
    expect(fn () => $service->claim($uid, 'taken@example.com', $other->user->site->subdomain, false, $otherToken))
        ->toThrow(RuntimeException::class, 'ACCOUNT_EXISTS');

    expect($other->fresh()->claim_token_hash)->not->toBeNull();
});

it('refuses a valid token on an expired build', function () {
    [$build, $token] = outreachBuildWithToken(['expires_at' => now()->subMinute()]);

    expect(fn () => app(\App\Services\PreAccount\ClaimSiteService::class)->claim(
        (string) \Illuminate\Support\Str::uuid(),
        'someone@example.com',
        $build->user->site->subdomain,
        false,
        $token,
    ))->toThrow(RuntimeException::class, 'CLAIM_NOT_INVITED');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/pest tests/Feature/PreAccount/ClaimWithTokenTest.php`
Expected: FAIL — `claim()` has no `claimToken` parameter.

- [ ] **Step 3: Add the request field**

In `app/Http/Requests/Api/PublicSite/ClaimSiteRequest.php`, add to `rules()`:

```php
            // The frontend reads ?t= off the claim page URL and forwards it in
            // the BODY, never the query string — a token in a query string
            // lands in access logs and Referer headers.
            'claim_token' => ['nullable', 'string', 'max:128'],
```

- [ ] **Step 4: Thread it through the controller**

In `app/Http/Controllers/Api/PublicSite/ClaimController.php`, change the `claim(...)` call to:

```php
            $result = $this->claims->claim(
                $uid,
                $verifiedEmail,
                $validated['subdomain'],
                (bool) $validated['marketing_opt_in'],
                $validated['claim_token'] ?? null,
            );
```

- [ ] **Step 5: Change the service**

In `app/Services/PreAccount/ClaimSiteService.php`, inject the issuer into the constructor alongside the existing dependencies:

```php
        private readonly ClaimTokenIssuer $tokens,
```

with `use App\Services\PreAccount\ClaimTokenIssuer;` — same namespace, so no import is needed; add it only if the class moves.

Change the signature:

```php
    public function claim(string $uid, string $verifiedEmail, string $subdomain, bool $marketingOptIn = false, ?string $claimToken = null): array
```

and add `$claimToken` to the transaction closure's `use (...)` list.

Replace the two gates with:

```php
            // A valid claim token IS proof of invitation (spec §6.2). It stands
            // in for contact_email on the outreach lane, which is what lets a
            // DM'd lead claim without an email address ever entering the flow.
            // It satisfies INVITATION only — every other invariant below still
            // applies.
            $tokenOk = $this->tokens->matches($build, $claimToken);

            $contactEmail = trim((string) $build->contact_email);
            if ($build->isOutreach() && $contactEmail === '' && ! $tokenOk) {
                throw new RuntimeException('CLAIM_NOT_INVITED');
            }

            if ($contactEmail !== '' && ! $tokenOk
                && strtolower(trim($verifiedEmail)) !== strtolower($contactEmail)) {
                throw new RuntimeException('CLAIM_EMAIL_MISMATCH');
            }
```

Then, immediately after `$professional->status = 'active';` and before the save, burn the token:

```php
            // Single-use = USED, not opened (spec §4). Inside this transaction,
            // so a claim that throws below rolls the burn back with it and the
            // lead can retry with the same DM link.
            if ($tokenOk) {
                $this->tokens->burn($build);
            }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php vendor/bin/pest tests/Feature/PreAccount/ClaimWithTokenTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 7: Run the surrounding suites for regressions**

Run:

```bash
php vendor/bin/pest tests/Feature/PreAccount/ tests/Feature/Api/PublicSite/ tests/Unit/Services/PreAccount/
```

Expected: PASS. The existing email-only and first-come paths must be unchanged — `$tokenOk` is `false` whenever no token is presented, so both original gates behave exactly as before.

- [ ] **Step 8: Commit**

```bash
git add app/Services/PreAccount/ClaimSiteService.php app/Http/Requests/Api/PublicSite/ClaimSiteRequest.php app/Http/Controllers/Api/PublicSite/ClaimController.php tests/Feature/PreAccount/ClaimWithTokenTest.php
git commit -m "feat(claim): a valid claim token satisfies the invite-gate

Closes the outreach half of #SEC-3: a DM'd lead can claim without an
email. Token is burned inside the claim transaction on SUCCESS only, so
an abandoned or failed claim leaves the DM link usable (spec §4)."
```

---

### Task 6: Staff token re-issue

**Files:**
- Modify: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php` (new method after `invite()`)
- Modify: `routes/api/staff.php` (alongside `/builds/{build}/invite`, ~line 80)
- Test: `tests/Feature/Api/Staff/StaffReissueClaimTokenTest.php`

**Interfaces:**
- Consumes: `ClaimTokenIssuer::issue()` (Task 2).
- Produces: `POST /api/staff/builds/{build}/claim-token` returning `data.claim_url`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/Staff/StaffReissueClaimTokenTest.php`:

```php
<?php

use App\Models\Core\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use App\Services\PreAccount\ClaimTokenIssuer;
use Illuminate\Support\Str;

// Local by design — cross-file Pest helpers break under --parallel.
function staffTokenBuild(): PreAccountBuild
{
    $user = User::factory()->create([
        'status' => 'unclaimed',
        'auth_user_id' => null,
        'primary_email' => null,
    ]);
    Site::factory()->create(['user_id' => $user->id, 'is_published' => true]);

    $build = PreAccountBuild::factory()->make([
        'built_via' => PreAccountBuild::VIA_STAFF,
        'build_state' => PreAccountBuild::STATE_READY,
        'expires_at' => now()->addDays(30),
    ]);
    $build->user()->associate($user);   // user_id is NOT fillable
    $build->save();

    return $build->fresh();
}

it('mints a fresh token and invalidates the previous one', function () {
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    $build = staffTokenBuild();
    $old = app(ClaimTokenIssuer::class)->issue($build);

    $response = actingAsStaff($staff)
        ->postJson("/api/staff/builds/{$build->id}/claim-token")
        ->assertStatus(200);

    expect($response->json('data.claim_url'))->toContain('?t=')
        ->and(app(ClaimTokenIssuer::class)->matches($build->fresh(), $old))->toBeFalse();
});

it('refuses an unauthenticated caller', function () {
    $build = staffTokenBuild();

    $this->postJson("/api/staff/builds/{$build->id}/claim-token")->assertStatus(401);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/pest tests/Feature/Api/Staff/StaffReissueClaimTokenTest.php`
Expected: FAIL — 404, route missing.

- [ ] **Step 3: Add the controller method**

In `StaffPreAccountBuildController`, inject `ClaimTokenIssuer` into the constructor and add after `invite()`:

```php
    // POST /api/staff/builds/{build}/claim-token — mint a fresh claim link.
    //
    // For "the lead lost the DM" and for rotation after a suspected leak.
    // Deliberately NOT on the ManyChat webhook (spec §5.4): re-issuing against
    // an EXISTING build is exactly the capability a leaked webhook secret must
    // not confer, so it lives behind staff auth + AAL2 instead.
    public function reissueClaimToken(PreAccountBuild $build): JsonResponse
    {
        $staff = request()->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffCreate', PreAccountBuild::class);

        $build->loadMissing('user.site');
        if ($build->user?->site === null) {
            return $this->error('Build has no site.', 409, [], ['code' => 'BUILD_NOT_READY']);
        }

        if ($build->claimed_at !== null) {
            return $this->error('This build has already been claimed.', 409, [], ['code' => 'ALREADY_CLAIMED']);
        }

        $token = $this->tokens->issue($build);

        Log::info('pre_account.claim_token.reissued', [
            'build_id' => $build->id,
            'staff_id' => $staff?->id,
        ]);

        return $this->success([
            'build_id' => $build->id,
            'claim_url' => rtrim((string) config('app.frontend_url'), '/')
                .'/claim/'.$build->user->site->subdomain
                .'?t='.$token,
        ]);
    }
```

- [ ] **Step 4: Register the route**

In `routes/api/staff.php`, next to the `invite` route:

```php
        Route::post('/builds/{build}/claim-token', [StaffPreAccountBuildController::class, 'reissueClaimToken']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php vendor/bin/pest tests/Feature/Api/Staff/StaffReissueClaimTokenTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php routes/api/staff.php tests/Feature/Api/Staff/StaffReissueClaimTokenTest.php
git commit -m "feat(staff): re-issue a claim token for an existing build

Behind staff auth + AAL2 by design — re-issuing against an EXISTING build
is precisely what a leaked webhook secret must not be able to do."
```

---

### Task 7: Documentation and full verification

**Files:**
- Modify: `docs/api.md` (§3, the pre-account endpoint section)
- Modify: `CLAUDE.md` (the Pre-account hard-rules block)

- [ ] **Step 1: Document the endpoints in `docs/api.md`**

In §3, after the existing pre-account endpoints, add:

```markdown
#### `POST /api/internal/webhooks/manychat/builds`

Machine surface for ManyChat marketing builds. Static shared secret in the
`X-Partna-Webhook-Secret` header (`MANYCHAT_WEBHOOK_SECRET`); 503 when unset,
401 on mismatch. Exists because `POST /api/staff/builds` requires `require.aal2`,
which an automation platform cannot satisfy.

Body: `account_type`, `source_type`, `source_ref`, optional `source_name`,
optional `expires_days` (1–90).

`202` on a new build, `200` when deduped. **`claim_url` is present ONLY on the
`202`** — a token is never minted for a build that already existed.

Send timing is ManyChat's choice: the claim URL is valid immediately (claiming
does not wait for `ready`), but polling `GET /api/public/signup/builds/{id}`
until `site_url` appears avoids DMing someone into a half-built page.

#### `POST /api/staff/builds/{build}/claim-token`

Staff + AAL2. Mints a fresh claim link, invalidating the previous one. For a
lost DM or a suspected leak.

#### Claim tokens

`POST /api/claim` accepts an optional `claim_token` in the **body** (the
frontend reads `?t=` from the claim page URL and forwards it). A valid token
satisfies the invite-gate in place of `contact_email`. It is single-use in the
sense of **used, not opened** — the token is consumed only by a successful
claim, so an abandoned sign-in or a failed claim leaves the link working for
the rest of the build's 30-day life.
```

- [ ] **Step 2: Update `CLAUDE.md`**

Add to the Pre-account hard-rules list:

```markdown
- **ManyChat builds arrive at `POST /api/internal/webhooks/manychat/builds`, NOT `/api/staff/builds`** — the staff group carries `require.aal2` and no robot can satisfy it. A `claim_token` (SHA-256 stored, plaintext returned once) proves invitation in place of `contact_email`, which is what lets a DM'd lead claim with no email in the flow. ⚠️ **A token is minted ONLY when `requestBuild()` returns `reused === false`** — minting on the deduped path would let a leaked webhook secret take over any build whose `source_ref` is guessable. Single-use means **used, not opened**: the hash clears on a successful claim only. Spec: `docs/superpowers/specs/2026-08-25-manychat-claim-link-design.md`.
```

- [ ] **Step 3: Run the static gates**

```bash
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
```

Expected: pint `"result":"passed"`, phpstan `[OK] No errors`.

- [ ] **Step 4: Run the full suite**

Run: `php vendor/bin/pest --parallel`
Expected: PASS. Baseline before this work was 9105 passed / 3 skipped; this plan adds 27 tests, so expect ~9132 passed with the same 3 skips.

- [ ] **Step 5: Commit**

```bash
git add docs/api.md CLAUDE.md
git commit -m "docs: ManyChat claim links — endpoints, token semantics, the mint rule"
```

---

## Self-Review

**Spec coverage**

| Spec section | Task |
|---|---|
| §4 claim token (hash, generation, lifetime, single-use, not fillable) | 1, 2 |
| §5 endpoint placement | 4 |
| §5.1 static-secret auth, fail-closed | 3 |
| §5.2 request/response contract | 4 |
| §5.3 send timing | 7 (documented; no code — correct, it is a ManyChat flow setting) |
| §5.4 mint only on new build | 4 (two tests) |
| §6.1 `ClaimNotifier` NOT changed | — no task, deliberately |
| §6.2 claim-gate change + burn | 5 |
| §6.3 `ClaimSiteRequest` field | 5 |
| §6.4 send-once | 4 — discharged by the §5.4 mint rule, no separate code |
| §7 migration | 1 |
| §8 staff re-issue | 6 |
| §10 testing matrix | every row maps to a test in 1, 2, 4, 5 |

**Type consistency:** `ClaimTokenIssuer::issue/matches/burn` are used with those exact names and signatures in Tasks 4, 5 and 6. `requestBuild(...)` named arguments match the real signature (verified against `PreAccountBuildService.php:32-43`). `$result['reused']` matches the real return shape (`:73`, `:182`, `:192`).

**Corrected during self-review (both would have failed on first run):**
1. Every test originally used `PreAccountBuild::factory()->create()`, but the
   factory creates NO user while `pre_account_builds.user_id` is NOT NULL **and
   deliberately not fillable**. All three test files now build the user first and
   attach it with `->user()->associate()`, matching the model's own `$fillable`
   comment and `makeUnclaimedWithSite()` in `UnclaimedGatingTest`.
2. `Site` is `App\Models\Core\Site\Site`, not `App\Models\Site\Site`.

Helpers are defined per test file rather than shared: cross-file Pest helpers
break under `--parallel`, which is how the suite runs.

**One deliberate gap:** self-serve (`built_via='signup'`) is untouched and `#SEC-3` stays open for that lane — spec §9. Do not tick the audit finding on the strength of this plan.

## Execution Handoff

Plan complete. Two execution options:

1. **Subagent-Driven (recommended)** — a fresh subagent per task, review between tasks.
2. **Inline Execution** — tasks executed in this session with checkpoints.
