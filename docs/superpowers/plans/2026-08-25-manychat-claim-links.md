# ManyChat Claim Links Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let ManyChat create a pre-account build over a secret-gated webhook and DM the lead a claim link that proves invitation, so a DM'd person can claim without an email address and without staff intervention.

**Architecture:** Pull, not Push — ManyChat calls us, we return a one-time claim URL, ManyChat sends the DM itself. A random token is minted for a NEW build, or for a retry carrying the same `idempotency_key`; we store SHA-256, never plaintext. A valid token satisfies the **invite-gate only** — it is narrow, and does not override an email-gated build. The hash is cleared inside the claim transaction, folded into the final `claimed_at` write.

**Tech Stack:** Laravel 12, PHP 8.4, Pest 4, Supabase Postgres (raw SQL migrations in `supabase/migrations/`).

**Spec:** `docs/superpowers/specs/2026-08-25-manychat-claim-link-design.md`

**Review:** all Critical/Important findings from the 2026-08-25 review are folded in. See "What the review changed" at the foot.

## Global Constraints

- **Never create a Laravel migration file.** Raw SQL in `supabase/migrations/`; the composer guard rejects Laravel migrations.
- ⚠️ **THE SUITE DOES NOT RUN MIGRATIONS. Every schema change is a TWO-file change.** `RefreshDatabase` is disabled (`tests/Pest.php:49`); each suite hand-builds SQLite tables via `setupPreAccountBuildsTable()` (`tests/Pest.php:561`), which carries its own defensive `ALTER TABLE ... ADD COLUMN` list mirroring each migration. Add the column in **both** places, or `matches()` reads `null` for every input and the "gate still throws" tests pass while the gate is simply broken.
- **Every new test file must bootstrap its own tables and fake the queue.** The pattern for this surface is `setupUsersTable(); setupSitesTable(); setupPreAccountBuildsTable(); shimPgAdvisoryLockForSqlite(); Queue::fake();`. Without `Queue::fake()`, `QUEUE_CONNECTION=sync` runs `GeneratePreAccountSiteJob` **inline and attempts a real Apify scrape**.
- **A Unit test needs `uses(TestCase::class)->in(__FILE__);`** — `tests/Pest.php:49` binds `TestCase` to `Feature` only, so without it `app()` and `now()` have no booted container.
- **Responses are FLAT — there is no `data` envelope.** `success((new Resource(...))->resolve(), $status)` returns the unwrapped array; `$wrap = 'data'` only applies to a Resource returned directly. Assert `build_id`, never `data.build_id`.
- **Never return raw Eloquent.** Use a Resource. **Never write an inline `abort_unless(..., 403)`** — CI fails on it.
- Helpers are **per test file**; cross-file Pest helpers break under `--parallel`, which is how the suite runs.
- 4-space indent, LF. Comments explain **why**. `pint --test` is the gate, not `pint`.
- Token plaintext is returned **exactly once**. Never stored, never logged, never returned by any GET.
- A token is minted only for a **new** build, or a **retry with a matching `idempotency_key`** (spec §5.4).
- "Single-use" = **used, not opened** — the hash clears on a successful claim only (spec §4).
- The token is **narrow**: invitation only. It does **not** override `CLAIM_EMAIL_MISMATCH` (spec §6.2).

---

### Task 1: Schema + model

**Files:**
- Create: `supabase/migrations/20260825120000_pre_account_builds_claim_token.sql`
- Modify: `app/Models/Core/User/PreAccountBuild.php` (docblock ~`:28`, `$casts` `:82-88`, `isOutreach()` docblock `:122-127`)
- Modify: `tests/Pest.php:583-589` (the defensive ALTER list)
- Test: `tests/Unit/Models/PreAccountBuildTest.php`

**Interfaces:**
- Produces: `$claim_token_hash` (`?string`), `$claim_token_issued_at` (`?Carbon`), `$claim_idempotency_key` (`?string`). All **not fillable**.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Models/PreAccountBuildTest.php` (which already has `uses(TestCase::class)` at `:8`):

```php
it('casts claim_token_issued_at to a date', function () {
    $build = new PreAccountBuild;
    $build->forceFill(['claim_token_issued_at' => '2026-08-25 04:24:13']);

    expect($build->claim_token_issued_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

// Regression guard, not a red-then-green test: $fillable already drops unknown
// keys, so this passes before the change too. It exists to fail loudly if
// someone later adds these columns to $fillable.
it('does not mass-assign the claim token columns', function () {
    $build = new PreAccountBuild([
        'claim_token_hash' => 'attacker-supplied',
        'claim_idempotency_key' => 'attacker-supplied',
    ]);

    expect($build->claim_token_hash)->toBeNull()
        ->and($build->claim_idempotency_key)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/pest tests/Unit/Models/PreAccountBuildTest.php`
Expected: the **cast** test FAILS (`claim_token_issued_at` is a plain string). The mass-assignment test passes already — expected, and noted above.

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
-- claim_idempotency_key lets a retried webhook call re-mint safely: the key
-- proves the caller is the one that created this build, which the dedupe path
-- cannot otherwise establish. Without it a lost HTTP response strands the
-- build as permanently unclaimable (spec §5.4).
--
-- No index on either: the token is never a lookup key (the claim path finds the
-- build by subdomain, then compares hashes) and the idempotency key is only
-- ever read on a row already in hand.
--
-- Nullable, no default, no backfill: existing builds keep NULL and continue to
-- claim through the email path exactly as before.
--
-- ROLLBACK: ALTER TABLE core.pre_account_builds
--             DROP COLUMN IF EXISTS claim_token_hash,
--             DROP COLUMN IF EXISTS claim_token_issued_at,
--             DROP COLUMN IF EXISTS claim_idempotency_key;

BEGIN;

ALTER TABLE core.pre_account_builds
    ADD COLUMN IF NOT EXISTS claim_token_hash text,
    ADD COLUMN IF NOT EXISTS claim_token_issued_at timestamptz,
    ADD COLUMN IF NOT EXISTS claim_idempotency_key text;

COMMENT ON COLUMN core.pre_account_builds.claim_token_hash IS
    'SHA-256 of the claim token. Plaintext never stored. Cleared on successful claim (single-use).';
COMMENT ON COLUMN core.pre_account_builds.claim_token_issued_at IS
    'When the current claim token was minted. Observability + rotation only.';
COMMENT ON COLUMN core.pre_account_builds.claim_idempotency_key IS
    'Caller-supplied key from the ManyChat webhook. A retry matching this re-mints instead of stranding the build.';

COMMIT;
```

- [ ] **Step 4: Apply to dev and verify**

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
supabase db push
```

Verify:

```sql
select column_name, is_nullable from information_schema.columns
where table_schema='core' and table_name='pre_account_builds'
  and column_name like 'claim_%';
```

Expected: three rows, all `is_nullable = YES`.

- [ ] **Step 5: Mirror the columns into the SQLite stand-in**

⚠️ **Not optional — see Global Constraints.** In `tests/Pest.php`, add to the `foreach` list at `:583-589`:

```php
        // Mirrors migration 20260825120000 (ManyChat claim links).
        'claim_token_hash TEXT NULL',
        'claim_token_issued_at TEXT NULL',
        'claim_idempotency_key TEXT NULL',
```

- [ ] **Step 6: Update the model**

In `app/Models/Core/User/PreAccountBuild.php`, add to the class docblock after the `@property Carbon|null $invited_at` line:

```php
 * @property string|null $claim_token_hash SHA-256 of the claim token (spec §4). Plaintext NEVER stored. NULL = no live token. Not fillable — minted via ClaimTokenIssuer.
 * @property Carbon|null $claim_token_issued_at When the current token was minted. Not fillable.
 * @property string|null $claim_idempotency_key Caller key from the ManyChat webhook; a retry matching it re-mints (spec §5.4). Not fillable.
```

Add to `$casts`:

```php
        'claim_token_issued_at' => 'datetime',
```

Leave `$fillable` **unchanged** — the omission is the guard.

- [ ] **Step 7: Correct the `isOutreach()` docblock**

`PreAccountBuild.php:122-127` claims `built_via` comes only from `$staff ? VIA_STAFF : VIA_SIGNUP`. Task 4 makes that false. Append inside that docblock:

```php
     * UPDATE 2026-08-25: VIA_STAFF now ALSO originates from the ManyChat
     * webhook (a static shared secret, not staff auth) — see
     * ManyChatBuildController. The classification still fails SAFE (more
     * outreach, never less), so #SEM-2's conclusion holds, but its premise
     * "can only originate from an actual staff-authenticated write" no longer
     * does. Do not reason from that sentence.
```

- [ ] **Step 8: Run tests**

Run: `php vendor/bin/pest tests/Unit/Models/PreAccountBuildTest.php tests/Feature/PreAccount/`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add supabase/migrations/20260825120000_pre_account_builds_claim_token.sql app/Models/Core/User/PreAccountBuild.php tests/Pest.php tests/Unit/Models/PreAccountBuildTest.php
git commit -m "feat(pre-account): claim token + idempotency key columns

Stores SHA-256, never plaintext. Not fillable. Mirrored into the SQLite
stand-in in tests/Pest.php — the suite does not run migrations, and a
missing column would make the token gate vacuously pass."
```

---

### Task 2: `ClaimTokenIssuer`

**Files:**
- Create: `app/Services/PreAccount/ClaimTokenIssuer.php`
- Test: `tests/Unit/Services/PreAccount/ClaimTokenIssuerTest.php`

**Interfaces:**
- Produces:
  - `issue(PreAccountBuild $build): string` — mints, persists the hash, returns plaintext once.
  - `matches(PreAccountBuild $build, ?string $presented): bool` — constant-time; **false** on no token, no presented value, or an expired build.
  - `burn(): array` — the attribute fragment a caller folds into its own write (see Task 5).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/PreAccount/ClaimTokenIssuerTest.php`:

```php
<?php

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimTokenIssuer;
use Tests\TestCase;

// tests/Pest.php binds TestCase to Feature ONLY — without this the container
// is never booted and app()/now()/factories all fail.
uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupPreAccountBuildsTable();
});

// Local by design: cross-file Pest helpers break under --parallel.
// PreAccountBuildFactory creates NO user, but user_id is NOT NULL and is
// deliberately not fillable — it must be attached via associate().
function issuerBuild(array $attrs = []): PreAccountBuild
{
    $user = User::factory()->create([
        'status' => 'unclaimed',
        'auth_user_id' => null,
        'primary_email' => null,
    ]);

    $build = PreAccountBuild::factory()->make($attrs);
    $build->user()->associate($user);
    $build->save();

    return $build->fresh();
}

it('stores only the hash, never the plaintext', function () {
    $build = issuerBuild();

    $plain = app(ClaimTokenIssuer::class)->issue($build);

    expect($build->fresh()->claim_token_hash)->toBe(hash('sha256', $plain))
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

it('rejects a null presented token', function () {
    $build = issuerBuild();
    app(ClaimTokenIssuer::class)->issue($build);

    expect(app(ClaimTokenIssuer::class)->matches($build->fresh(), null))->toBeFalse();
});

it('rejects an empty presented token', function () {
    $build = issuerBuild();
    app(ClaimTokenIssuer::class)->issue($build);

    expect(app(ClaimTokenIssuer::class)->matches($build->fresh(), ''))->toBeFalse();
});

it('rejects any token on a build that has none', function () {
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

it('mints a different token every time', function () {
    $build = issuerBuild();
    $issuer = app(ClaimTokenIssuer::class);

    expect($issuer->issue($build))->not->toBe($issuer->issue($build));
});

it('burn() yields the attribute fragment that clears the hash', function () {
    expect(app(ClaimTokenIssuer::class)->burn())->toBe(['claim_token_hash' => null]);
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

    /**
     * The attribute fragment that spends the token, for the caller to FOLD
     * into its own write rather than issuing a second UPDATE.
     *
     * Returning a fragment instead of saving is the point: ClaimSiteService
     * merges this into the final claimed_at write, so the burn lands strictly
     * after every throw in the claim path. That makes "a failed claim does not
     * consume the lead's link" structural, not dependent on rollback.
     *
     * @return array{claim_token_hash: null}
     */
    public function burn(): array
    {
        return ['claim_token_hash' => null];
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php vendor/bin/pest tests/Unit/Services/PreAccount/ClaimTokenIssuerTest.php`
Expected: PASS, 10 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/PreAccount/ClaimTokenIssuer.php tests/Unit/Services/PreAccount/ClaimTokenIssuerTest.php
git commit -m "feat(pre-account): ClaimTokenIssuer — mint, verify, burn-fragment

Stores SHA-256 only. Expiry enforced inside matches() so no call site can
forget it. burn() returns a fragment for the caller to fold into its own
write, which is what makes the burn strictly-after-every-throw."
```

---

### Task 3: Webhook gate, config, rate limiters

**Files:**
- Create: `app/Http/Middleware/Auth/VerifyManyChatWebhook.php`
- Modify: `config/services.php` (after the `supabase` block, `:55`)
- Modify: `config/partna.php` (`throttle` array)
- Modify: `bootstrap/app.php:182` (alias list) + imports
- Modify: `app/Providers/AppServiceProvider.php` (after the `pre-account-build` limiter, `:646`)
- Modify: `.env.example`
- Test: `tests/Feature/Api/Internal/ManyChatWebhookAuthTest.php`

**Interfaces:**
- Produces: alias `manychat.webhook`; limiter `manychat-build`; config `services.manychat.webhook_secret`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/Internal/ManyChatWebhookAuthTest.php`:

```php
<?php

// No table bootstrap needed here, uniquely: the middleware short-circuits
// before any DB access. Every OTHER new test file in this plan needs the full
// setup*/Queue::fake() block — see Global Constraints.

it('returns 503 when the webhook secret is not configured', function () {
    config(['services.manychat.webhook_secret' => '']);

    $this->postJson('/api/internal/webhooks/manychat/builds', [])
        ->assertStatus(503)
        ->assertJsonPath('error', 'hook_not_configured');
});

it('returns 401 when the secret header is absent', function () {
    config(['services.manychat.webhook_secret' => 'a-test-secret-value']);

    $this->postJson('/api/internal/webhooks/manychat/builds', [])->assertStatus(401);
});

it('returns 401 when the secret header is wrong', function () {
    config(['services.manychat.webhook_secret' => 'a-test-secret-value']);

    $this->withHeader('X-Partna-Webhook-Secret', 'not-the-secret')
        ->postJson('/api/internal/webhooks/manychat/builds', [])
        ->assertStatus(401);
});

it('passes the gate when the correct secret is presented', function () {
    config(['services.manychat.webhook_secret' => 'a-test-secret-value']);

    $response = $this->withHeader('X-Partna-Webhook-Secret', 'a-test-secret-value')
        ->postJson('/api/internal/webhooks/manychat/builds', []);

    // 422 (validation) is the expected answer for an empty body past the gate.
    expect($response->status())->not->toBe(401)->not->toBe(503);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/pest tests/Feature/Api/Internal/ManyChatWebhookAuthTest.php`
Expected: FAIL — 404, route lands in Task 4.

- [ ] **Step 3: Add the config key**

In `config/services.php`, after the `'supabase' => [...]` block:

```php
    // ManyChat marketing automation → POST /api/internal/webhooks/manychat/builds.
    //
    // A STATIC shared secret, not an HMAC signature (spec §5.1): ManyChat's
    // External Request action can set headers but cannot sign a request body,
    // so the Standard Webhooks scheme used for Supabase/Resend is unavailable.
    // Weaker by construction — the control that bounds it is that a claim token
    // is only ever minted for a NEW build, or a retry carrying the same
    // idempotency_key (spec §5.4).
    //
    // Rotate by changing this env var and the ManyChat flow's header together.
    'manychat' => [
        'webhook_secret' => env('MANYCHAT_WEBHOOK_SECRET'),
    ],
```

- [ ] **Step 4: Add throttle config + env placeholder**

In `config/partna.php`, in the `throttle` array:

```php
        'manychat_build_per_minute' => (int) env('PARTNA_THROTTLE_MANYCHAT_BUILD_PER_MINUTE', 10),
        'manychat_build_per_hour' => (int) env('PARTNA_THROTTLE_MANYCHAT_BUILD_PER_HOUR', 120),
```

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

In `bootstrap/app.php`, after the `'resend.webhook'` line:

```php
            'manychat.webhook' => VerifyManyChatWebhook::class,
```

and add `use App\Http\Middleware\Auth\VerifyManyChatWebhook;` with the other middleware imports.

- [ ] **Step 7: Add the rate limiters**

In `app/Providers/AppServiceProvider.php`, after the `pre-account-build` limiter's closing `});`:

```php
        // ManyChat build webhook. Each call can trigger an Apify-billed scrape,
        // so this is a spend guard as much as an abuse guard.
        //
        // TWO buckets on purpose. The shared 'manychat:h' key is a global
        // quota, and throttle middleware runs BEFORE the secret check — so a
        // constant key alone is a DoS handle: any stranger who knows the URL
        // could burn the quota and lock the real flow out with 429s. The
        // per-IP bucket is the narrower one an anonymous caller hits first.
        RateLimiter::for('manychat-build', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return [Limit::none()];
            }

            $ip = $request->header('CF-Connecting-IP') ?: $request->ip();

            return [
                Limit::perMinute((int) config('partna.throttle.manychat_build_per_minute', 10))
                    ->by('manychat:ip:'.$ip)
                    ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)),

                Limit::perHour((int) config('partna.throttle.manychat_build_per_hour', 120))
                    ->by('manychat:h')
                    ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)),
            ];
        });
```

- [ ] **Step 8: Commit**

The route arrives in Task 4, so the auth test is still red. Commit the gate anyway — it is self-contained.

```bash
git add app/Http/Middleware/Auth/VerifyManyChatWebhook.php config/services.php config/partna.php bootstrap/app.php .env.example app/Providers/AppServiceProvider.php tests/Feature/Api/Internal/ManyChatWebhookAuthTest.php
git commit -m "feat(internal): ManyChat webhook gate — static secret, fail-closed

Static secret rather than Standard Webhooks HMAC: ManyChat cannot sign a
body (spec §5.1). Two throttle buckets — a constant key alone would be a
DoS handle, since throttling runs ahead of the secret check."
```

---

### Task 4: The webhook endpoint

**Files:**
- Create: `app/Http/Controllers/Api/Internal/ManyChatBuildController.php`
- Create: `app/Http/Requests/Api/Internal/ManyChatBuildRequest.php`
- Create: `app/Http/Resources/ManyChatBuildResource.php`
- Modify: `routes/api.php` — **inside the existing `Route::middleware('throttle:webhooks')->group(...)` at `:37-58`**, after the Resend route. The effective stack is therefore `throttle:webhooks` + `manychat.webhook` + `throttle:manychat-build`.
- Test: `tests/Feature/Api/Internal/ManyChatBuildTest.php`

**Interfaces:**
- Consumes: `ClaimTokenIssuer::issue()` (Task 2); alias + limiter (Task 3); `PreAccountBuildService::requestBuild(...): array{build: PreAccountBuild, reused: bool}`.
- Produces: `POST /api/internal/webhooks/manychat/builds` → **flat** `build_id`, `subdomain`, `build_state`, `reused`, and `claim_url` when a token was minted.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/Internal/ManyChatBuildTest.php`:

```php
<?php

use App\Models\Core\User\PreAccountBuild;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();   // else GeneratePreAccountSiteJob runs inline and really scrapes
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
            'idempotency_key' => 'manychat-sub-4471',
        ]);
}

it('creates an outreach build and returns a claim url carrying a token', function () {
    $response = manychatPost()->assertStatus(202);

    $build = PreAccountBuild::findOrFail($response->json('build_id'));

    expect($build->built_via)->toBe(PreAccountBuild::VIA_STAFF)
        ->and($build->isOutreach())->toBeTrue()
        ->and($build->claim_token_hash)->not->toBeNull();

    $claimUrl = $response->json('claim_url');
    expect($claimUrl)->toContain('/claim/')->toContain('?t=');

    parse_str((string) parse_url($claimUrl, PHP_URL_QUERY), $query);
    expect(hash('sha256', $query['t']))->toBe($build->claim_token_hash)
        ->and($query['t'])->not->toBe($build->claim_token_hash);
});

it('does NOT return a claim url when deduped by a DIFFERENT caller', function () {
    manychatPost()->assertStatus(202);

    $second = manychatPost(['idempotency_key' => 'someone-elses-key'])->assertStatus(200);

    expect($second->json('reused'))->toBeTrue()
        ->and($second->json('claim_url'))->toBeNull();
});

it('does not re-mint for a different caller on the deduped path', function () {
    $first = manychatPost()->assertStatus(202);
    $build = PreAccountBuild::findOrFail($first->json('build_id'));
    $hashAfterFirst = $build->claim_token_hash;

    manychatPost(['idempotency_key' => 'someone-elses-key'])->assertStatus(200);

    expect($build->fresh()->claim_token_hash)->toBe($hashAfterFirst);
});

it('re-mints for a retry carrying the SAME idempotency key', function () {
    $first = manychatPost()->assertStatus(202);
    $build = PreAccountBuild::findOrFail($first->json('build_id'));
    $hashAfterFirst = $build->claim_token_hash;

    $retry = manychatPost()->assertStatus(200);

    expect($retry->json('claim_url'))->not->toBeNull()
        ->and($build->fresh()->claim_token_hash)->not->toBe($hashAfterFirst);
});

it('requires account_type, source_type, source_ref and idempotency_key', function () {
    test()->withHeader('X-Partna-Webhook-Secret', 'a-test-secret-value')
        ->postJson('/api/internal/webhooks/manychat/builds', [])
        ->assertStatus(422);
});

it('requires source_name when the source is google_business', function () {
    manychatPost(['source_type' => 'google_business', 'source_ref' => 'ChIJabc'])
        ->assertStatus(422);
});

it('returns 422 with a code — not a 500 — when requestBuild rejects the input', function () {
    // Guards the EXCEPTION CONTRACT. PHP does not error on an unresolvable
    // `use`, so a wrong import for PreAccountBuildException would make the
    // catch never match and this would 500 instead. Pick any input the
    // pairing map rejects; confirm the pairing in config/partna.php first.
    manychatPost(['source_ref' => ''])->assertStatus(422);
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
use Illuminate\Validation\Rule;

// ManyChat marketing build. Source/account pairing rules are read from config,
// NOT hardcoded — CLAUDE.md's contract is "adding a source = one generator +
// config entry + CHECK migration", and a hardcoded in: list adds a fourth
// place to edit. Mirrors StaffCreatePreAccountBuildRequest.
class ManyChatBuildRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'account_type' => ['required', 'string', Rule::in(array_keys((array) config('partna.pre_account.sources', [])))],
            'source_type' => ['required', 'string', Rule::in(array_keys((array) config('partna.pre_account.generators', [])))],
            'source_ref' => ['required', 'string', 'max:300'],
            // A GBP place_id is opaque — the picker-known business name seeds
            // the subdomain/handle/display name. Same rule and same reason as
            // both sibling requests; dropping it seeds a handle from a raw
            // place ID.
            'source_name' => ['nullable', 'string', 'max:120', 'required_if:source_type,google_business'],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            // Required: without it a lost response strands the build (spec §5.4).
            'idempotency_key' => ['required', 'string', 'max:191'],
        ];
    }
}
```

> ⚠️ **Before implementing:** open `config/partna.php` and copy the exact config paths that `CreatePreAccountBuildRequest:17-18` and `StaffCreatePreAccountBuildRequest:18-19` read. The two keys above are the shape, not a verified path — use whatever the siblings use.

- [ ] **Step 4: Write the resource**

Create `app/Http/Resources/ManyChatBuildResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Models\Core\User\PreAccountBuild;
use Illuminate\Http\Request;

// Webhook response for ManyChat. Carries the claim URL — the ONE time the
// plaintext token is ever visible — so it must never be reused for a GET. The
// poll shape is PreAccountBuildStatusResource, which has no token.
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
            // Absent unless a token was minted — spec §5.4.
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

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Internal\ManyChatBuildRequest;
use App\Http\Resources\ManyChatBuildResource;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\ClaimTokenIssuer;
use App\Services\PreAccount\PreAccountBuildException;
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
        $idempotencyKey = (string) $data['idempotency_key'];

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
                // VIA_STAFF with no staff row: an outreach build made FOR a
                // business, so isOutreach() must be true, but no human made it.
                builtVia: PreAccountBuild::VIA_STAFF,
                autoInvite: false,
            );
        } catch (PreAccountBuildException $e) {
            return $this->error($e->getMessage(), 422, [], ['code' => $e->errorCode]);
        }

        $build = $result['build'];
        $build->loadMissing('user.site');

        $subdomain = $build->user?->site?->subdomain;
        if ($subdomain === null) {
            return $this->error('Build has no site.', 409, [], ['code' => 'BUILD_NOT_READY']);
        }

        // Mint for a NEW build, or for a RETRY proving it is the same caller
        // (spec §5.4). On any other deduped call we mint nothing: otherwise a
        // leaked webhook secret could fetch a working capability for a build
        // someone else created, which is the takeover this rule exists to stop.
        $claimUrl = null;
        $isRetryOfOurOwn = $result['reused']
            && $build->claim_idempotency_key !== null
            && hash_equals((string) $build->claim_idempotency_key, $idempotencyKey);

        if (! $result['reused'] || $isRetryOfOurOwn) {
            $build->forceFill(['claim_idempotency_key' => $idempotencyKey])->save();
            $token = $this->tokens->issue($build);
            $claimUrl = rtrim((string) config('app.frontend_url'), '/')
                .'/claim/'.$subdomain
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

In `routes/api.php`, **inside the existing `throttle:webhooks` group**, after the Resend route:

```php
    // ManyChat marketing builds. Static-secret gated (ManyChat cannot sign a
    // body — spec §5.1); throttled again on its own bucket because each call
    // can trigger an Apify-billed scrape.
    Route::post('/internal/webhooks/manychat/builds', ManyChatBuildController::class)
        ->middleware(['manychat.webhook', 'throttle:manychat-build'])
        ->name('webhooks.manychat.builds');
```

plus `use App\Http\Controllers\Api\Internal\ManyChatBuildController;`.

- [ ] **Step 7: Run tests**

Run: `php vendor/bin/pest tests/Feature/Api/Internal/`
Expected: PASS. Both files green now the route exists.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/Internal/ManyChatBuildController.php app/Http/Requests/Api/Internal/ManyChatBuildRequest.php app/Http/Resources/ManyChatBuildResource.php routes/api.php tests/Feature/Api/Internal/ManyChatBuildTest.php
git commit -m "feat(internal): ManyChat build webhook returns a one-time claim url

Mints only for a new build, or a retry proving the same idempotency_key.
Any other deduped call gets no claim_url — the control bounding the
static-secret auth (spec §5.4), pinned by three tests."
```

---

### Task 5: The claim path accepts a token

**Files:**
- Modify: `app/Http/Requests/Api/PublicSite/ClaimSiteRequest.php:26-32`
- Modify: `app/Services/PreAccount/ClaimSiteService.php:30-35` (constructor), `:42` (signature), `:85-97` (gates), `:135` (the `claimed_at` write)
- Modify: `app/Http/Controllers/Api/PublicSite/ClaimController.php:54`
- Test: `tests/Feature/PreAccount/ClaimWithTokenTest.php`

**Interfaces:**
- Consumes: `ClaimTokenIssuer::matches()`, `::burn()` (Task 2).
- Produces: `claim(string $uid, string $verifiedEmail, string $subdomain, bool $marketingOptIn = false, ?string $claimToken = null): array` — new **trailing optional** parameter, so all existing callers are unaffected.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PreAccount/ClaimWithTokenTest.php`:

```php
<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimSiteService;
use App\Services\PreAccount\ClaimTokenIssuer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

// Local by design (cross-file helpers break --parallel). The factory creates no
// user, and user_id is NOT NULL and not fillable — attach via associate().
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
    $build->user()->associate($user);
    $build->save();

    return $build->fresh();
}

/** @return array{0: PreAccountBuild, 1: string} */
function outreachBuildWithToken(array $attrs = []): array
{
    $build = claimTokenBuild($attrs);

    return [$build, app(ClaimTokenIssuer::class)->issue($build)];
}

it('claims an outreach build with no contact_email when a valid token is presented', function () {
    [$build, $token] = outreachBuildWithToken();

    $result = app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'someone@example.com', $build->user->site->subdomain, false, $token,
    );

    expect($result['professional']->status)->toBe('active');
});

it('still throws CLAIM_NOT_INVITED with no token', function () {
    [$build] = outreachBuildWithToken();

    expect(fn () => app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'someone@example.com', $build->user->site->subdomain,
    ))->toThrow(RuntimeException::class, 'CLAIM_NOT_INVITED');
});

it('still throws CLAIM_NOT_INVITED with a wrong token', function () {
    [$build, $token] = outreachBuildWithToken();

    expect(fn () => app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'someone@example.com', $build->user->site->subdomain, false, $token.'x',
    ))->toThrow(RuntimeException::class, 'CLAIM_NOT_INVITED');
});

it('refuses a valid token on an expired build', function () {
    [$build, $token] = outreachBuildWithToken(['expires_at' => now()->subMinute()]);

    expect(fn () => app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'someone@example.com', $build->user->site->subdomain, false, $token,
    ))->toThrow(RuntimeException::class, 'CLAIM_NOT_INVITED');
});

// ── The token is NARROW (spec §6.2) ──────────────────────────────────────────

it('does NOT let a token override an email-gated build with a mismatched address', function () {
    [$build, $token] = outreachBuildWithToken(['contact_email' => 'owner@example.com']);

    expect(fn () => app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'attacker@example.com', $build->user->site->subdomain, false, $token,
    ))->toThrow(RuntimeException::class, 'CLAIM_EMAIL_MISMATCH');
});

it('lets a token claim an email-gated build when the address DOES match', function () {
    [$build, $token] = outreachBuildWithToken(['contact_email' => 'owner@example.com']);

    $result = app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'owner@example.com', $build->user->site->subdomain, false, $token,
    );

    expect($result['professional']->status)->toBe('active');
});

// ── Single-use = used, not opened (spec §4) ──────────────────────────────────

it('burns the token on a successful claim so a replay is refused', function () {
    [$build, $token] = outreachBuildWithToken();

    app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'first@example.com', $build->user->site->subdomain, false, $token,
    );

    expect($build->fresh()->claim_token_hash)->toBeNull();
});

it('leaves the token intact when the claim throws', function () {
    // REGRESSION GUARD, not a proof of rollback. After this task the burn is
    // folded into the final claimed_at write, so EVERY throw is structurally
    // before it. This test fails if someone moves the burn earlier.
    [$build, $token] = outreachBuildWithToken();
    $service = app(ClaimSiteService::class);

    $uid = (string) Str::uuid();
    [$other, $otherToken] = outreachBuildWithToken();
    $service->claim($uid, 'taken@example.com', $other->user->site->subdomain, false, $otherToken);

    // Same uid already owns a site → ACCOUNT_EXISTS on this one.
    expect(fn () => $service->claim($uid, 'taken@example.com', $build->user->site->subdomain, false, $token))
        ->toThrow(RuntimeException::class, 'ACCOUNT_EXISTS');

    expect($build->fresh()->claim_token_hash)->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/pest tests/Feature/PreAccount/ClaimWithTokenTest.php`
Expected: FAIL — `claim()` has no `claimToken` parameter.

- [ ] **Step 3: Add the request field**

In `ClaimSiteRequest::rules()`:

```php
            // The frontend reads ?t= off the claim page URL and forwards it in
            // the BODY, so the token never reaches OUR access logs or Referer.
            // (It is still in the frontend's URL — the contract requires the
            // claim page to strip it with history.replaceState. Spec §6.3.)
            'claim_token' => ['nullable', 'string', 'max:128'],
```

- [ ] **Step 4: Thread it through the controller**

In `ClaimController::store()`:

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

Add to the constructor (`:30-35`):

```php
        private readonly ClaimTokenIssuer $tokens,
```

No import needed — `ClaimTokenIssuer` is the same `App\Services\PreAccount` namespace.

Change the signature at `:42`:

```php
    public function claim(string $uid, string $verifiedEmail, string $subdomain, bool $marketingOptIn = false, ?string $claimToken = null): array
```

and add `$claimToken` to the transaction closure's `use (...)` list at `:44`.

Replace the gates at `:85-97`:

```php
            // A valid claim token IS proof of invitation (spec §6.2). It stands
            // in for contact_email on the outreach lane, which is what lets a
            // DM'd lead claim with no email ever entering the flow.
            $tokenOk = $this->tokens->matches($build, $claimToken);

            $contactEmail = trim((string) $build->contact_email);
            if ($build->isOutreach() && $contactEmail === '' && ! $tokenOk) {
                throw new RuntimeException('CLAIM_NOT_INVITED');
            }

            // NOTE the absence of $tokenOk here — deliberate (owner, 2026-08-25).
            // The token is NARROW: it proves INVITATION, not identity. A build
            // carrying a contact_email still requires that address, so a token
            // holder cannot claim an email-gated build with some other inbox.
            if ($contactEmail !== ''
                && strtolower(trim($verifiedEmail)) !== strtolower($contactEmail)) {
                throw new RuntimeException('CLAIM_EMAIL_MISMATCH');
            }
```

Then fold the burn into the existing `claimed_at` write at `:135`:

```php
            // Single-use = USED, not opened (spec §4). Folded into the
            // claimed_at write rather than issued separately: this is the last
            // write in the claim, so the burn lands strictly AFTER every throw
            // above. That makes "a failed claim does not consume the lead's
            // link" structural, not dependent on transaction rollback.
            $build->forceFill(['claimed_at' => now()] + ($tokenOk ? $this->tokens->burn() : []))->save();
```

- [ ] **Step 6: Run tests**

Run: `php vendor/bin/pest tests/Feature/PreAccount/ClaimWithTokenTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 7: Regression sweep**

```bash
php vendor/bin/pest tests/Feature/PreAccount/ tests/Feature/Api/PublicSite/ tests/Unit/Services/PreAccount/
```

Expected: PASS. `$tokenOk` is `false` whenever no token is presented, so both original gates behave exactly as before.

- [ ] **Step 8: Commit**

```bash
git add app/Services/PreAccount/ClaimSiteService.php app/Http/Requests/Api/PublicSite/ClaimSiteRequest.php app/Http/Controllers/Api/PublicSite/ClaimController.php tests/Feature/PreAccount/ClaimWithTokenTest.php
git commit -m "feat(claim): a valid claim token satisfies the invite-gate

A DM'd lead can now claim without an email. The token is NARROW — it does
NOT override CLAIM_EMAIL_MISMATCH (owner decision). Burn is folded into
the final claimed_at write so every throw is structurally before it."
```

---

### Task 6: Staff token re-issue

**Files:**
- Modify: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php` (constructor + new method after `invite()`)
- Modify: `routes/api/staff.php:80` (beside the `invite` route)
- Test: `tests/Feature/Api/Staff/StaffReissueClaimTokenTest.php`

**Interfaces:**
- Consumes: `ClaimTokenIssuer::issue()` (Task 2).
- Produces: `POST /api/staff/builds/{build}/claim-token` → flat `build_id`, `claim_url`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/Staff/StaffReissueClaimTokenTest.php`:

```php
<?php

use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimTokenIssuer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupPartnaStaffTable();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();

    // The staff.audit middleware writes here AFTER the response — without the
    // table the request 500s once the controller has already succeeded.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY, staff_id TEXT NULL, action TEXT NULL, created_at TEXT NULL
    )');
});

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
    $build->user()->associate($user);
    $build->save();

    return $build->fresh();
}

function adminStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

it('mints a fresh token and invalidates the previous one', function () {
    $build = staffTokenBuild();
    $old = app(ClaimTokenIssuer::class)->issue($build);

    $response = actingAsStaff(adminStaff())
        ->postJson("/api/staff/builds/{$build->id}/claim-token")
        ->assertStatus(200);

    expect($response->json('claim_url'))->toContain('?t=')
        ->and(app(ClaimTokenIssuer::class)->matches($build->fresh(), $old))->toBeFalse();
});

it('refuses to re-issue for an already-claimed build', function () {
    $build = staffTokenBuild();
    $build->forceFill(['claimed_at' => now()])->save();

    actingAsStaff(adminStaff())
        ->postJson("/api/staff/builds/{$build->id}/claim-token")
        ->assertStatus(409)
        ->assertJsonPath('code', 'ALREADY_CLAIMED');
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

Add `use App\Services\PreAccount\ClaimTokenIssuer;` — this controller is a **different namespace**, so the import IS required (unlike `ClaimSiteService`). Inject `private readonly ClaimTokenIssuer $tokens` into the constructor, and add after `invite()`:

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
        $subdomain = $build->user?->site?->subdomain;
        if ($subdomain === null) {
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
                .'/claim/'.$subdomain
                .'?t='.$token,
        ]);
    }
```

- [ ] **Step 4: Register the route**

In `routes/api/staff.php`, beside the `invite` route — **with `whereUuid`**, which the sibling carries. Without it a non-UUID segment produces a Postgres `22P02` → 500 rather than 404, which is exactly the enumeration-oracle shape the doctrine forbids:

```php
        Route::post('/builds/{build}/claim-token', [StaffPreAccountBuildController::class, 'reissueClaimToken'])
            ->whereUuid('build');
```

- [ ] **Step 5: Run tests**

Run: `php vendor/bin/pest tests/Feature/Api/Staff/StaffReissueClaimTokenTest.php`
Expected: PASS, 3 tests.

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
- Modify: `docs/api.md` (§3)
- Modify: `CLAUDE.md` (Pre-account hard rules)
- Modify: `audits/sweeps/2026-08-24-claim-gate-security/CONSOLIDATED.md` (the `#SEM-2` entry)

- [ ] **Step 1: Document in `docs/api.md`**

```markdown
#### `POST /api/internal/webhooks/manychat/builds`

Machine surface for ManyChat marketing builds. Static shared secret in the
`X-Partna-Webhook-Secret` header (`MANYCHAT_WEBHOOK_SECRET`); 503 when unset,
401 on mismatch. Exists because `POST /api/staff/builds` requires `require.aal2`,
which an automation platform cannot satisfy.

Body: `account_type`, `source_type`, `source_ref`, `idempotency_key` (all
required), optional `source_name` (**required** for `google_business`), optional
`expires_days` (1–90).

`202` on a new build, `200` when deduped. Response is **flat — no `data`
envelope**. `claim_url` is present only when a token was minted: for a new
build, or a retry carrying the same `idempotency_key`. Any other deduped call
returns no `claim_url`.

Send timing is ManyChat's choice: the claim URL is valid immediately (claiming
does not wait for `ready`), but polling `GET /api/public/signup/builds/{id}`
until `site_url` appears avoids DMing someone into a half-built page.

#### `POST /api/staff/builds/{build}/claim-token`

Staff + AAL2. Mints a fresh claim link, invalidating the previous one. For a
lost DM or a suspected leak.

#### Claim tokens

`POST /api/claim` accepts an optional `claim_token` in the **body** (the
frontend reads `?t=` from the claim page URL and forwards it, then strips it
with `history.replaceState`). A valid token satisfies the invite-gate in place
of `contact_email`.

The token is **narrow**: it proves invitation, not identity. A build carrying a
`contact_email` still requires that address — a token does not override
`CLAIM_EMAIL_MISMATCH`.

Single-use means **used, not opened**: the token is consumed only by a
successful claim, so an abandoned sign-in or a failed claim leaves the link
working for the rest of the build's 30-day life.
```

- [ ] **Step 2: Update `CLAUDE.md`**

Add to the Pre-account hard rules:

```markdown
- **ManyChat builds arrive at `POST /api/internal/webhooks/manychat/builds`, NOT `/api/staff/builds`** — the staff group carries `require.aal2` and no robot can satisfy it. A `claim_token` (SHA-256 stored, plaintext returned once) proves invitation in place of `contact_email`, which is what lets a DM'd lead claim with no email in the flow. ⚠️ **A token is minted ONLY for a NEW build, or a retry carrying the same `idempotency_key`** — minting on any other deduped call would let a leaked webhook secret take over any build whose `source_ref` is guessable. The token is **narrow**: it satisfies the invite-gate only and does NOT override `CLAIM_EMAIL_MISMATCH`. Single-use means **used, not opened** — the hash clears on a successful claim only, folded into the `claimed_at` write. ⚠️ `built_via = VIA_STAFF` now also originates from this webhook, so `isOutreach()`'s "only from a staff-authenticated write" premise is dead (it fails safe). Spec: `docs/superpowers/specs/2026-08-25-manychat-claim-link-design.md`.
```

- [ ] **Step 3: Annotate `#SEM-2`**

Add to that finding: `built_via === 'staff'` no longer implies a staff-authenticated write — the ManyChat webhook also sets it. The conclusion still holds (misclassification fails safe, toward more gating), but the stated premise does not.

- [ ] **Step 4: Static gates**

```bash
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
```

Expected: pint `"result":"passed"`, phpstan `[OK] No errors`.

- [ ] **Step 5: Full suite**

Run: `php vendor/bin/pest --parallel`
Expected: PASS. Baseline before this work was 9105 passed / 3 skipped; this plan adds ~32 tests, so expect ~9137 passed with the same 3 skips.

- [ ] **Step 6: Commit**

```bash
git add docs/api.md CLAUDE.md audits/sweeps/2026-08-24-claim-gate-security/CONSOLIDATED.md
git commit -m "docs: ManyChat claim links — endpoints, token semantics, the mint rule"
```

---

## What the review changed

A 2026-08-25 review found four Critical defects and ten Important ones. All are folded in above. The four that would have stopped execution dead:

1. **No `data` envelope.** `resolve()` returns the unwrapped array; nine assertions asserted `data.*` and would all have read `null`. The spec's response example was wrong too, which would have mis-configured the ManyChat flow.
2. **The SQLite stand-in was never updated.** The suite does not run migrations. Worse than a red run: `matches()` would read a missing column as `null → false`, so *"a wrong token still throws"* would have **passed while the gate was broken**.
3. **No test bootstrap in any new file**, and no `Queue::fake()` — which under `QUEUE_CONNECTION=sync` would have run `GeneratePreAccountSiteJob` inline and attempted a **real Apify scrape**. Plus a Unit test needs `uses(TestCase::class)`.
4. **`PreAccountBuildException` is `App\Services\PreAccount\`, not `App\Exceptions\`.** PHP does not error on an unresolvable `use`, so the catch would silently never match and a documented 422 would ship as a 500.

Also folded in: config-driven validation rules with `required_if` for `google_business` (I5), the `throttle:webhooks` group note and a per-IP throttle bucket (I6), the `isOutreach()` docblock correction (I7), `history.replaceState` in the frontend contract (I8), an honest §5.1 blast radius (I9), `whereUuid` and the missing import on the staff route (I10), and migration conventions — `ROLLBACK` header, `BEGIN`/`COMMIT`, `IF NOT EXISTS` (M1).

Two design gaps were settled by owner decision rather than patched: the token is **narrow** (does not override the email-gate), and an **`idempotency_key` is required** so a lost webhook response cannot strand a build as permanently unclaimable.

## Self-Review

**Spec coverage**

| Spec section | Task |
|---|---|
| §4 token (hash, generation, lifetime, single-use, not fillable) | 1, 2 |
| §5 endpoint placement | 4 |
| §5.1 static-secret auth, fail-closed | 3 |
| §5.2 request/response contract (flat) | 4 |
| §5.3 send timing | 7 (docs only — correctly a ManyChat flow setting) |
| §5.4 mint rule + idempotency | 1 (column), 4 (three tests) |
| §6.1 `ClaimNotifier` NOT changed | no task, deliberate |
| §6.2 claim-gate + narrow token + burn | 5 |
| §6.3 request field + `replaceState` contract | 5, 7 |
| §6.5 `isOutreach()` docblock | 1 |
| §7 migration | 1 |
| §8 staff re-issue | 6 |
| §10 testing matrix | every row maps to a test |

**Type consistency:** `ClaimTokenIssuer::issue/matches/burn` used with those exact signatures in 4, 5, 6. `requestBuild(...)` named arguments verified against `PreAccountBuildService.php:31-43`. `$result['reused']` verified against `:73`, `:182`, `:192`.

**One unverified path remains, flagged inline:** the two `config('partna.pre_account.*')` keys in Task 4's form request are the shape, not a checked path. Task 4 Step 3 tells the implementer to copy whatever the two sibling requests actually read.

**Deliberate gap:** self-serve (`built_via='signup'`) is untouched. `#SEC-3` is about that arm and **stays open** — do not tick it on the strength of this work.

## Execution Handoff

1. **Subagent-Driven (recommended)** — fresh subagent per task, review between tasks.
2. **Inline Execution** — tasks executed in this session with checkpoints.
