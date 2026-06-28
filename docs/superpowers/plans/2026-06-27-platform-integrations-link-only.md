# Platform Integrations — Migration Toolkit + Link-Only Archetype (Plan 2 of N)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the registry-driven migration toolkit (a generic controller, a typed `LinkPayload` DTO, and a live `ConnectStrategy` on the descriptor) and prove it by migrating the six link-only platforms (linkedin, x, threads, reddit, tiktok, facebook) off their hand-written controllers — with the API contract frozen byte-for-byte the whole way.

**Architecture:** Plan 1 left a `PlatformRegistry` of `PlatformDescriptor`s wired to nothing. This plan gives the link-only descriptors a live `UrlConnect` strategy (each platform's existing URL/handle normalizer) plus the platform's exact 422 message, adds a `LinkPayload` readonly DTO as the typed storage boundary, and adds one `GenericPlatformController` that resolves its descriptor from the route's `platform` default and serves connect/selection/forget for every link-only platform. Each platform is migrated one at a time (strangler): point its route at the generic controller, delete its old controller, prove the golden master + its feature tests stay green, commit. The frontend never sees a change.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4 + PHPUnit, SQLite in-memory for tests, Supabase/Postgres in prod.

**Design spec:** `docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md` (§5 descriptors, §6 ① ConnectStrategy, §7 link-only archetype, §8 typed payloads, §10 strangler migration).

**Builds on:** `docs/superpowers/plans/2026-06-27-platform-integrations-registry-spine.md` (Plan 1 — MERGED). The spine classes this plan extends already exist on `development`:
`app/Services/Platforms/Registry/{PlatformRegistry,PlatformDescriptor,PlatformCategory}.php`, `app/Services/Platforms/Strategies/Connect/UrlConnect.php`, `app/Services/Platforms/Strategies/Contracts/*`, `app/Providers/PlatformRegistryServiceProvider.php`, `app/Rules/PlatformInRegistry.php`, and the golden-master net under `tests/Feature/Platforms/GoldenMaster/`.

## Global Constraints

- **No Laravel migrations.** This plan touches NO schema (the `DROP CONSTRAINT` is a later plan). A composer guard rejects Laravel migrations regardless.
- **API contract is FROZEN — byte-for-byte.** Every route URI, every JSON response shape, every error message string stays identical. The golden master (`IntegrationContractGoldenMasterTest`) and `PlatformResourceContractTest` must stay green after every task. No test assertion in those two files may be loosened to make a change pass.
- **The net-completeness count must stay `52`.** `IntegrationContractGoldenMasterTest` line ~184 asserts `expect($readRoutes->count())->toBe(52)`. The route changes in this plan are designed to keep the `api/platforms/` GET-route URIs identical, so this number does NOT change. If it ever does, a route was added/removed — stop and reconcile before touching the number.
- **Old controller deleted ONLY after its generic replacement is proven green.** Within a single task: flip the route → run the golden master + that platform's feature tests → green → delete the old controller → re-run → green → commit. Never delete first.
- **Resource classes for all responses; never return raw models.** The generic controller serializes through the descriptor's `resourceClass()` (all six → `LinkConnectionResource`).
- **Authorization via the trait chokepoint.** `ManagesIntegrationConnection` already runs `authorizeForUser($user, ...)` on every read/write/delete. The generic controller reuses the trait unchanged — do not add inline 403 aborts.
- **Capability gate on the create path.** The connect (create) action calls `$descriptor->availableFor($user)` per spec §9 — it returns `true` for everyone today (behavior-neutral), establishing the capability checkpoint CLAUDE.md mandates for new endpoints. Do NOT gate selection/forget (reads/removals of already-owned data).
- **`config/partna.php` `social_platforms` is a SEPARATE registry** (the link-block UI). Do NOT reference it from any spine/controller code.
- **Tests run on SQLite; prod is Postgres.** All new code is app-level and engine-agnostic. Use the existing `setupUsersTable()` / `setupSitesTable()` / `actingAsUser()` test helpers.
- **Pint clean.** Run `php artisan pint --dirty` before every commit; never reformat untouched files.
- **Commit prefixes:** `feat(integrations):` for new toolkit classes, `refactor(integrations):` for a platform migration (route flip + controller delete), `test(integrations):` for test-only additions.

---

## Prerequisite check (run before Task 1)

- [ ] **Confirm Plan 1 is merged and the spine exists**

Run:
```bash
git fetch && git pull && git log --oneline -6
ls app/Services/Platforms/Registry/ app/Services/Platforms/Strategies/Connect/
php artisan test tests/Feature/Platforms tests/Unit/Platforms
```
Expected: the log shows the Plan-1 commits (`feat(integrations): PlatformRegistry`, `…PlatformDescriptor value object + presets`, `…PlatformInRegistry validation rule`); the `ls` shows `PlatformRegistry.php`, `PlatformDescriptor.php`, `PlatformCategory.php`, and `UrlConnect.php`; the test run is GREEN. **If any of these is missing, STOP — Plan 1 must land first.**

---

## File Structure

**New:**
- `app/Services/Platforms/Payloads/LinkPayload.php` — readonly DTO for the `{username, url}` link-only payload (the typed storage/read boundary).
- `app/Services/Platforms/Normalizers/XNormalizer.php` — X/Twitter handle/URL → `{username, url}|null` (ported verbatim from `XController`).
- `app/Services/Platforms/Normalizers/LinkedinNormalizer.php` — ported from `LinkedinController`.
- `app/Services/Platforms/Normalizers/ThreadsNormalizer.php` — ported from `ThreadsController`.
- `app/Services/Platforms/Normalizers/RedditNormalizer.php` — ported from `RedditController`.
- `app/Services/Platforms/Normalizers/TiktokNormalizer.php` — ported from `TiktokController` (empty handle → null).
- `app/Services/Platforms/Normalizers/FacebookNormalizer.php` — ported from `FacebookController` (encodes the empty-username validation as a null return).
- `app/Http/Controllers/Api/Platforms/GenericPlatformController.php` — registry-driven connect/selection/forget for link-only platforms.
- `tests/Unit/Platforms/Payloads/LinkPayloadTest.php`
- `tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php`
- `tests/Feature/Platforms/GenericLinkControllerTest.php` — per-platform connect/selection/forget + null-input 422 message, exercised through the generic controller.

**Modified:**
- `app/Services/Platforms/Registry/PlatformDescriptor.php` — ADD `connect(ConnectStrategy, string)` setter + `connectStrategy()` + `connectErrorMessage()` getters (additive; Plan 1's API is untouched).
- `app/Providers/PlatformRegistryServiceProvider.php` — attach a live `UrlConnect` strategy + error message to each of the six link-only descriptors (one line per platform, added as each migrates).
- `routes/api/integrations.php` — move the six slugs out of `$singleSelection` into a generic-controller loop with `->defaults('platform', $slug)`.
- `tests/Unit/Platforms/Registry/PlatformDescriptorTest.php` — ADD a case for the new connect setter/getters.

**Deleted (each in its platform's migration task, after green):**
- `app/Http/Controllers/Api/Platforms/{X,Linkedin,Threads,Reddit,Tiktok,Facebook}Controller.php`
- `app/Http/Requests/Platforms/ConnectTiktokRequest.php`, `app/Http/Requests/Platforms/ConnectFacebookRequest.php` (orphaned once their controllers are gone; `ConnectSocialLinkRequest` is kept and reused by the generic controller).

**Explicitly OUT of scope — deferred to later plans (see "Deferred" at the end):** `skool` and `custom` (neither fits the link-only shape — both scrape); the oEmbed / feed / picker / shop / bespoke archetypes; the `PlatformRefresher` `match()` rewrite; the `ProviderDetector` rewrite; the `DROP CONSTRAINT` migration; wiring `PlatformInRegistry` into Form Requests; adding a payload class or fetch/refresh strategy instances to descriptors.

---

## Task 1: `LinkPayload` typed DTO

**Files:**
- Create: `app/Services/Platforms/Payloads/LinkPayload.php`
- Test: `tests/Unit/Platforms/Payloads/LinkPayloadTest.php`

**Interfaces:**
- Produces: `final readonly class LinkPayload { public ?string $username; public ?string $url; public static function fromArray(array $payload): self; public function toArray(): array; }`. `toArray()` returns exactly `['username' => ?string, 'url' => ?string]`. `fromArray()` is lenient: missing keys → `null`, non-string values → `null`, an empty-string `username` is preserved (Facebook `/pages/` links store `username: ''`).

> **Why this exists:** every link social currently re-reads `$payload['username'] ?? null` in its controller and again in `LinkConnectionResource`. `LinkPayload` is the single home for that tolerant hydration (spec §8). It round-trips `{username, url}` losslessly, so the frozen `LinkConnectionResource` output is byte-identical whether it serializes a raw array or `LinkPayload::fromArray($p)->toArray()` — which is what lets the generic controller adopt it without touching the contract.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Platforms\Payloads\LinkPayload;

it('round-trips a full link payload', function () {
    $stored = ['username' => 'jane.doe', 'url' => 'https://www.facebook.com/jane.doe'];

    expect(LinkPayload::fromArray($stored)->toArray())->toBe($stored);
});

it('exposes typed properties', function () {
    $payload = LinkPayload::fromArray(['username' => 'janed', 'url' => 'https://x.com/janed']);

    expect($payload->username)->toBe('janed');
    expect($payload->url)->toBe('https://x.com/janed');
});

it('hydrates leniently — missing keys become null and unknown keys are dropped', function () {
    $payload = LinkPayload::fromArray(['url' => 'https://x.com/janed', '_leak' => 'must-not-survive']);

    expect($payload->username)->toBeNull();
    expect($payload->url)->toBe('https://x.com/janed');
    expect($payload->toArray())->toBe(['username' => null, 'url' => 'https://x.com/janed']);
});

it('preserves an empty-string username (Facebook /pages/ links)', function () {
    // Facebook page links store username:'' deliberately — it must NOT collapse to null.
    $payload = LinkPayload::fromArray(['username' => '', 'url' => 'https://www.facebook.com/pages/Some-Cafe/123']);

    expect($payload->username)->toBe('');
    expect($payload->toArray())->toBe(['username' => '', 'url' => 'https://www.facebook.com/pages/Some-Cafe/123']);
});

it('coerces non-string scalars to null', function () {
    $payload = LinkPayload::fromArray(['username' => 123, 'url' => ['nested']]);

    expect($payload->username)->toBeNull();
    expect($payload->url)->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Payloads/LinkPayloadTest.php`
Expected: FAIL — `Class "App\Services\Platforms\Payloads\LinkPayload" not found`.

- [ ] **Step 3: Write the DTO**

```php
<?php

namespace App\Services\Platforms\Payloads;

// Typed boundary for the link-only archetype's stored payload. The link socials
// (LinkedIn, X, Threads, Reddit, TikTok, Facebook) all store {username, url};
// this DTO is the single home for the tolerant hydration that used to be
// scattered as `$payload['username'] ?? null` across each controller and again
// in LinkConnectionResource (spec §8). It round-trips {username, url} losslessly,
// so the frozen LinkConnectionResource output is byte-identical whether it
// serializes a raw array or fromArray()->toArray().
final readonly class LinkPayload
{
    public function __construct(
        public ?string $username,
        public ?string $url,
    ) {}

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            username: self::stringOrNull($payload['username'] ?? null),
            url: self::stringOrNull($payload['url'] ?? null),
        );
    }

    /** @return array{username: string|null, url: string|null} */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'url' => $this->url,
        ];
    }

    // Empty string stays a string (Facebook page links store username:''); only
    // non-strings (missing key → null, arrays, ints) collapse to null.
    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Unit/Platforms/Payloads/LinkPayloadTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Payloads/LinkPayload.php tests/Unit/Platforms/Payloads/LinkPayloadTest.php
git commit -m "feat(integrations): LinkPayload typed DTO"
```

---

## Task 2: `PlatformDescriptor` carries a live connect strategy

**Files:**
- Modify: `app/Services/Platforms/Registry/PlatformDescriptor.php`
- Test: `tests/Unit/Platforms/Registry/PlatformDescriptorTest.php`

**Interfaces:**
- Consumes: `ConnectStrategy` (Plan 1 contract), `UrlConnect` (Plan 1).
- Produces (added to `PlatformDescriptor`):
  - `connect(ConnectStrategy $strategy, string $errorMessage): self` — fluent setter (stores both).
  - `connectStrategy(): ?ConnectStrategy` — getter (null until a platform migrates).
  - `connectErrorMessage(): ?string` — getter (the exact 422 message shown when the input can't be parsed).

> **Why the error message lives on the descriptor:** the six link platforms each return a DIFFERENT 422 string when normalization fails (e.g. "Enter your X handle or profile URL (x.com/yourname)." vs "Enter your Reddit username or community…"). Those strings are part of the frozen contract, so the descriptor carries them and the generic controller echoes the right one. This is additive — none of Plan 1's existing methods change.

- [ ] **Step 1: Write the failing test (append to the existing file)**

Append this `it(...)` block to `tests/Unit/Platforms/Registry/PlatformDescriptorTest.php`:

```php
it('carries a live connect strategy and its error message', function () {
    $d = \App\Services\Platforms\Registry\PlatformDescriptor::linkOnly(
        'x', 'X', \App\Http\Resources\Platforms\LinkConnectionResource::class,
    )->connect(
        new \App\Services\Platforms\Strategies\Connect\UrlConnect(
            fn (string $input): ?array => $input === 'good' ? ['username' => 'good', 'url' => 'https://x.com/good'] : null,
        ),
        'Enter your X handle or profile URL (x.com/yourname).',
    );

    expect($d->connectStrategy())->toBeInstanceOf(\App\Services\Platforms\Strategies\Contracts\ConnectStrategy::class);
    expect($d->connectStrategy()->normalize('good'))->toBe(['username' => 'good', 'url' => 'https://x.com/good']);
    expect($d->connectStrategy()->normalize('bad'))->toBeNull();
    expect($d->connectErrorMessage())->toBe('Enter your X handle or profile URL (x.com/yourname).');
});

it('returns null connect accessors before a strategy is attached', function () {
    $d = \App\Services\Platforms\Registry\PlatformDescriptor::make('linkedin');

    expect($d->connectStrategy())->toBeNull();
    expect($d->connectErrorMessage())->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Registry/PlatformDescriptorTest.php`
Expected: FAIL — `Call to undefined method …PlatformDescriptor::connect()`.

- [ ] **Step 3: Add the connect strategy to the descriptor**

In `app/Services/Platforms/Registry/PlatformDescriptor.php`, add the import below the existing `use App\Models\Core\User\User;`:

```php
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
```

Add these two properties next to the existing `private bool $refreshable = false;`:

```php
    private ?ConnectStrategy $connectStrategy = null;

    private ?string $connectErrorMessage = null;
```

Add these three methods immediately before the existing `public function availableFor(User $user): bool`:

```php
    /**
     * Attach the live connect strategy — the platform's URL/handle normalizer
     * wrapped in a ConnectStrategy (UrlConnect) — plus the 422 message shown when
     * the input can't be parsed. The generic controller reads both. The message
     * is part of the frozen API contract, so each platform keeps its exact wording.
     */
    public function connect(ConnectStrategy $strategy, string $errorMessage): self
    {
        $this->connectStrategy = $strategy;
        $this->connectErrorMessage = $errorMessage;

        return $this;
    }

    public function connectStrategy(): ?ConnectStrategy
    {
        return $this->connectStrategy;
    }

    public function connectErrorMessage(): ?string
    {
        return $this->connectErrorMessage;
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Unit/Platforms/Registry/PlatformDescriptorTest.php`
Expected: PASS (existing cases + the 2 new ones).

- [ ] **Step 5: Confirm the registry coverage test still passes (descriptor change is additive)**

Run: `php artisan test tests/Feature/Platforms/Registry/RegistryCoverageTest.php`
Expected: PASS — keys and refreshable set are unchanged by adding connect accessors.

- [ ] **Step 6: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Registry/PlatformDescriptor.php tests/Unit/Platforms/Registry/PlatformDescriptorTest.php
git commit -m "feat(integrations): PlatformDescriptor carries a live connect strategy"
```

---

## Task 3: `XNormalizer` + `GenericPlatformController` — migrate `x`

This task builds the generic controller and proves it end-to-end on the simplest platform (X: pure normalize, shared `ConnectSocialLinkRequest`). Later tasks reuse the controller and only add a normalizer + one provider line + a route flip.

**Files:**
- Create: `app/Services/Platforms/Normalizers/XNormalizer.php`
- Create: `app/Http/Controllers/Api/Platforms/GenericPlatformController.php`
- Create: `tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php`
- Create: `tests/Feature/Platforms/GenericLinkControllerTest.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`
- Modify: `routes/api/integrations.php`
- Delete: `app/Http/Controllers/Api/Platforms/XController.php`

**Interfaces:**
- `XNormalizer` — `public function __invoke(string $input): ?array` returning `array{username:string, url:string}|null`. Callable, so it satisfies `UrlConnect`'s `callable(string):(array|null)` constructor arg.
- `GenericPlatformController` — `connect(ConnectSocialLinkRequest): JsonResponse`, `selection(Request): JsonResponse`, `forget(Request): JsonResponse`; reads its platform from `request()->route('platform')`; resolves the descriptor from the injected `PlatformRegistry`.

- [ ] **Step 1: Write the `XNormalizer` unit test**

Create `tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php` (later tasks append their normalizer cases to this same file):

```php
<?php

use App\Services\Platforms\Normalizers\XNormalizer;

it('X normalizes a bare @handle to the canonical url', function () {
    expect((new XNormalizer)('@janed'))->toBe(['username' => 'janed', 'url' => 'https://x.com/janed']);
});

it('X normalizes a twitter.com profile url', function () {
    expect((new XNormalizer)('https://twitter.com/janed'))->toBe(['username' => 'janed', 'url' => 'https://x.com/janed']);
});

it('X rejects reserved first-segment paths', function () {
    expect((new XNormalizer)('https://x.com/home'))->toBeNull();
});

it('X rejects an over-long handle', function () {
    expect((new XNormalizer)('thishandleiswaytoolongforx'))->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php`
Expected: FAIL — `Class "App\Services\Platforms\Normalizers\XNormalizer" not found`.

- [ ] **Step 3: Write `XNormalizer` (ported verbatim from `XController::normalize` + its `RESERVED` const)**

```php
<?php

namespace App\Services\Platforms\Normalizers;

use App\Services\Platforms\PlatformInput;

// X/Twitter link-only normalizer — ported verbatim from the former XController.
// Accepts a bare handle, @handle, or an x.com / twitter.com profile URL (any
// scheme or none, extra path/query tolerated) → {username, url}; null otherwise.
class XNormalizer
{
    // Non-profile first path segments on x.com — pasting one of these means the
    // input wasn't a profile URL.
    private const RESERVED = [
        'i', 'home', 'explore', 'search', 'hashtag', 'intent', 'share',
        'settings', 'notifications', 'messages', 'compose', 'login', 'signup',
    ];

    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~(?:x|twitter)\.com/([^/?#]+)~i', $s, $m)) {
            $candidate = ltrim($m[1], '@');
            if (in_array(strtolower($candidate), self::RESERVED, true)) {
                return null;
            }
        } else {
            $candidate = PlatformInput::token($s);
        }

        if (! preg_match('~^[A-Za-z0-9_]{1,15}$~', $candidate)) {
            return null;
        }

        return ['username' => $candidate, 'url' => 'https://x.com/'.$candidate];
    }
}
```

- [ ] **Step 4: Run the normalizer test to green**

Run: `php artisan test tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Write the `GenericPlatformController`**

```php
<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ConnectSocialLinkRequest;
use App\Services\Platforms\Payloads\LinkPayload;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Registry-driven controller for the link-only archetype. The route group injects
// the platform slug as a route default ('platform' => '<slug>'); this controller
// resolves the matching PlatformDescriptor and serves the uniform
// connect / selection / forget shape that the per-platform controllers used to.
//
// Storage + authorization come from ManagesIntegrationConnection (unchanged); the
// platform's URL/handle parsing comes from the descriptor's ConnectStrategy; the
// response shape comes from the descriptor's resourceClass(). LinkPayload is the
// typed boundary between the stored jsonb and the (contract-frozen) resource.
class GenericPlatformController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    public function __construct(private readonly PlatformRegistry $registry) {}

    // The platform key for the ManagesIntegrationConnection trait. Read from the
    // route default the integrations group sets per migrated platform.
    protected function platform(): string
    {
        $platform = request()->route('platform');

        // Should never happen — every generic route sets the default — but fail
        // closed rather than write under a null platform.
        abort_if(! is_string($platform) || $platform === '', 404);

        return $platform;
    }

    // POST /api/platforms/{platform}/connect — parse the input via the descriptor's
    // connect strategy, store the canonical {username,url}, echo it.
    public function connect(ConnectSocialLinkRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $descriptor = $this->descriptor();

        // Capability checkpoint (spec §9) — true for everyone today; the gate
        // exists so future paid-tier/account-type rules are a per-descriptor flag.
        abort_unless($descriptor->availableFor($user), 403);

        $strategy = $descriptor->connectStrategy();
        abort_if($strategy === null, 404);

        $selection = $strategy->normalize($request->validated()['username']);
        if ($selection === null) {
            return $this->error($descriptor->connectErrorMessage() ?? 'Enter a valid link.', 422);
        }

        // Round-trip through the typed boundary, then store the canonical shape.
        $payload = LinkPayload::fromArray($selection)->toArray();
        $this->writeConnection($user, $payload);

        $resourceClass = $descriptor->resourceClass();

        return $this->success((new $resourceClass($payload))->resolve());
    }

    // GET /api/platforms/{platform}/selection — the saved link (or null).
    public function selection(Request $request): JsonResponse
    {
        $descriptor = $this->descriptor();
        $payload = $this->readConnection($this->currentUser($request));

        if ($payload === null) {
            return $this->success(['selection' => null]);
        }

        $resourceClass = $descriptor->resourceClass();
        $selection = (new $resourceClass(LinkPayload::fromArray($payload)->toArray()))->resolve();

        return $this->success(['selection' => $selection]);
    }

    // DELETE /api/platforms/{platform} — clear the connection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }

    // Resolve the descriptor for the current route's platform, or 404.
    private function descriptor(): PlatformDescriptor
    {
        $descriptor = $this->registry->get($this->platform());
        abort_if($descriptor === null, 404);

        return $descriptor;
    }
}
```

- [ ] **Step 6: Write the `x` feature test (passes against the OLD `XController` first — this is the equivalence guard)**

Create `tests/Feature/Platforms/GenericLinkControllerTest.php`:

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function genericLinkUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'account_type' => 'individual',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

it('x connect stores the canonical link and echoes {username,url}', function () {
    actingAsUser(genericLinkUser('gx1'))
        ->postJson('/api/platforms/x/connect', ['username' => '@janed'])
        ->assertOk()
        ->assertExactJson(['username' => 'janed', 'url' => 'https://x.com/janed']);
});

it('x connect returns the exact 422 message on unparseable input', function () {
    actingAsUser(genericLinkUser('gx2'))
        ->postJson('/api/platforms/x/connect', ['username' => 'https://x.com/home'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter your X handle or profile URL (x.com/yourname).');
});

it('x selection round-trips the stored payload and forget clears it', function () {
    $user = genericLinkUser('gx3');

    actingAsUser($user)->postJson('/api/platforms/x/connect', ['username' => 'janed'])->assertOk();

    actingAsUser($user)->getJson('/api/platforms/x/selection')
        ->assertOk()
        ->assertExactJson(['selection' => ['username' => 'janed', 'url' => 'https://x.com/janed']]);

    actingAsUser($user)->deleteJson('/api/platforms/x')
        ->assertOk()
        ->assertExactJson(['selection' => null]);

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'x')->whereNull('deleted_at')->exists())->toBeFalse();
});
```

- [ ] **Step 7: Run the x feature test against the current (old-controller) code**

Run: `php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php`
Expected: PASS — `XController` already produces this exact behavior. (This proves the test captures real behavior before the flip.)

- [ ] **Step 8: Attach the live connect strategy to the `x` descriptor**

In `app/Providers/PlatformRegistryServiceProvider.php`, add the imports (Pint will order them):

```php
use App\Services\Platforms\Normalizers\XNormalizer;
use App\Services\Platforms\Strategies\Connect\UrlConnect;
```

Immediately AFTER the link-only `foreach (...) { $r->register(PD::linkOnly(...)); }` block (the one registering tiktok/facebook/x/linkedin/threads/reddit), add:

```php
            // ── Link-only connect strategies (migrated to GenericPlatformController) ──
            // Each platform's existing URL/handle normalizer, wrapped in UrlConnect,
            // plus its exact 422 message. get() returns the live descriptor; ->connect()
            // mutates it in place. A line is added here as each platform migrates.
            $r->get('x')->connect(new UrlConnect(new XNormalizer), 'Enter your X handle or profile URL (x.com/yourname).');
```

- [ ] **Step 9: Flip the `x` route to the generic controller**

In `routes/api/integrations.php`:

1. Add the import (Pint will order it): `use App\Http\Controllers\Api\Platforms\GenericPlatformController;`
2. Remove `'x' => XController::class,` from the `$singleSelection` array AND remove the now-unused `use App\Http\Controllers\Api\Platforms\XController;` import (Step 11's grep depends on this).
3. Immediately AFTER the `foreach ($singleSelection as $slug => $controller) { ... }` loop, add the generic loop (this is where migrated link-only platforms accumulate):

```php
    // Link-only socials migrated to the registry-driven GenericPlatformController.
    // Each route carries its platform slug as a route DEFAULT (not a URI segment),
    // so the controller resolves its descriptor via request()->route('platform')
    // while the URIs stay per-platform (api/platforms/x/connect …). That keeps the
    // route table — and the golden-master net-completeness count — byte-identical
    // to the per-controller version these replace. Slugs are appended as they migrate.
    foreach (['x'] as $slug) {
        Route::prefix("{$base}/{$slug}")
            ->middleware($middleware)
            ->group(function () use ($slug) {
                Route::post('/connect', [GenericPlatformController::class, 'connect'])->defaults('platform', $slug);
                Route::get('/selection', [GenericPlatformController::class, 'selection'])->defaults('platform', $slug);
                Route::delete('/', [GenericPlatformController::class, 'forget'])->defaults('platform', $slug);
            });
    }
```

- [ ] **Step 10: Run the contract guards + x feature test against the NEW path**

Run:
```bash
php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php \
  tests/Feature/Platforms/PlatformResourceContractTest.php
```
Expected: PASS — golden master link-only `x` selection is byte-identical, the net-completeness count is still `52`, and the new x feature test passes through `GenericPlatformController`. **If the count assertion fails, a route URI changed — revert the route edit and re-check; do not edit the `52`.**

- [ ] **Step 11: Delete `XController` and confirm no references remain**

```bash
git rm app/Http/Controllers/Api/Platforms/XController.php
grep -rn "XController" app/ routes/ tests/ --include="*.php"
```
Expected: the `grep` returns NOTHING (the route import was removed in Step 9; nothing else referenced it).

- [ ] **Step 12: Re-run the guards after the delete**

Run:
```bash
php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
```
Expected: PASS.

- [ ] **Step 13: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Normalizers/XNormalizer.php \
  app/Http/Controllers/Api/Platforms/GenericPlatformController.php \
  app/Providers/PlatformRegistryServiceProvider.php routes/api/integrations.php \
  tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php \
  tests/Feature/Platforms/GenericLinkControllerTest.php
git add -A app/Http/Controllers/Api/Platforms/XController.php
git commit -m "refactor(integrations): migrate x to GenericPlatformController"
```

---

## Task 4: Migrate `linkedin`

**Files:**
- Create: `app/Services/Platforms/Normalizers/LinkedinNormalizer.php`
- Modify: `tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php`
- Modify: `tests/Feature/Platforms/GenericLinkControllerTest.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`
- Modify: `routes/api/integrations.php`
- Delete: `app/Http/Controllers/Api/Platforms/LinkedinController.php`

- [ ] **Step 1: Append the `LinkedinNormalizer` unit cases**

Append to `tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php`:

```php
it('LinkedIn normalizes an /in/ profile url', function () {
    expect((new \App\Services\Platforms\Normalizers\LinkedinNormalizer)('https://www.linkedin.com/in/jane-doe/'))
        ->toBe(['username' => 'jane-doe', 'url' => 'https://www.linkedin.com/in/jane-doe/']);
});

it('LinkedIn maps a bare slug to an /in/ profile', function () {
    expect((new \App\Services\Platforms\Normalizers\LinkedinNormalizer)('jane-doe'))
        ->toBe(['username' => 'jane-doe', 'url' => 'https://www.linkedin.com/in/jane-doe/']);
});

it('LinkedIn keeps a /company/ url under the company path', function () {
    expect((new \App\Services\Platforms\Normalizers\LinkedinNormalizer)('https://www.linkedin.com/company/acme/'))
        ->toBe(['username' => 'acme', 'url' => 'https://www.linkedin.com/company/acme/']);
});

it('LinkedIn rejects a non-profile linkedin.com url', function () {
    expect((new \App\Services\Platforms\Normalizers\LinkedinNormalizer)('https://www.linkedin.com/feed/'))->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php --filter=LinkedIn`
Expected: FAIL — `Class "App\Services\Platforms\Normalizers\LinkedinNormalizer" not found`.

- [ ] **Step 3: Write `LinkedinNormalizer` (ported verbatim from `LinkedinController::normalize`)**

```php
<?php

namespace App\Services\Platforms\Normalizers;

use App\Services\Platforms\PlatformInput;

// LinkedIn link-only normalizer — ported verbatim from the former LinkedinController.
// Accepts linkedin.com/in|company|school|pub/<slug> URLs (any scheme or none,
// locale subdomains, trailing junk tolerated) or a bare slug (assumed personal)
// → {username, url}. Legacy /pub/ profiles are stored as the modern /in/ form.
class LinkedinNormalizer
{
    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~linkedin\.com/(in|company|school|pub)/([^/?#]+)~i', $s, $m)) {
            // Legacy /pub/ profiles redirect to /in/ — store the modern form.
            $kind = strtolower($m[1]) === 'pub' ? 'in' : strtolower($m[1]);
            $slug = rawurldecode($m[2]);
        } elseif (str_contains($s, 'linkedin.com')) {
            // A linkedin.com URL without a recognised profile path — reject
            // rather than storing a feed/jobs link.
            return null;
        } else {
            $kind = 'in';
            $slug = PlatformInput::token($s);
        }

        if (! preg_match('~^[\p{L}\p{N}._\-]{2,100}$~u', $slug)) {
            return null;
        }

        return [
            'username' => $slug,
            'url' => 'https://www.linkedin.com/'.$kind.'/'.rawurlencode($slug).'/',
        ];
    }
}
```

- [ ] **Step 4: Run the normalizer test to green**

Run: `php artisan test tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php --filter=LinkedIn`
Expected: PASS (4 LinkedIn cases).

- [ ] **Step 5: Append the `linkedin` feature cases (pass against the OLD controller first)**

Append to `tests/Feature/Platforms/GenericLinkControllerTest.php`:

```php
it('linkedin connect stores an /in/ profile and echoes {username,url}', function () {
    actingAsUser(genericLinkUser('gli1'))
        ->postJson('/api/platforms/linkedin/connect', ['username' => 'https://www.linkedin.com/in/jane-doe/'])
        ->assertOk()
        ->assertExactJson(['username' => 'jane-doe', 'url' => 'https://www.linkedin.com/in/jane-doe/']);
});

it('linkedin connect returns the exact 422 message on a non-profile url', function () {
    actingAsUser(genericLinkUser('gli2'))
        ->postJson('/api/platforms/linkedin/connect', ['username' => 'https://www.linkedin.com/feed/'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter your LinkedIn profile URL (linkedin.com/in/yourname).');
});
```

- [ ] **Step 6: Run to confirm the new cases pass against current code**

Run: `php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php --filter=linkedin`
Expected: PASS (served by `LinkedinController` today).

- [ ] **Step 7: Attach the connect strategy + flip the route**

1. In `app/Providers/PlatformRegistryServiceProvider.php`, add `use App\Services\Platforms\Normalizers\LinkedinNormalizer;` and, in the "Link-only connect strategies" block (under the `x` line), add:

```php
            $r->get('linkedin')->connect(new UrlConnect(new LinkedinNormalizer), 'Enter your LinkedIn profile URL (linkedin.com/in/yourname).');
```

2. In `routes/api/integrations.php`, remove `'linkedin' => LinkedinController::class,` from `$singleSelection`, remove the `use ...\LinkedinController;` import, and add `'linkedin'` to the generic loop's slug list:

```php
    foreach (['x', 'linkedin'] as $slug) {
```

- [ ] **Step 8: Run the contract guards + feature test against the NEW path, then delete the controller**

```bash
php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
git rm app/Http/Controllers/Api/Platforms/LinkedinController.php
grep -rn "LinkedinController" app/ routes/ tests/ --include="*.php"
```
Expected: tests PASS (count still `52`); `grep` returns NOTHING.

- [ ] **Step 9: Re-run the guards after delete + commit**

```bash
php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
php artisan pint --dirty
git add app/Services/Platforms/Normalizers/LinkedinNormalizer.php \
  app/Providers/PlatformRegistryServiceProvider.php routes/api/integrations.php \
  tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php \
  tests/Feature/Platforms/GenericLinkControllerTest.php
git add -A app/Http/Controllers/Api/Platforms/LinkedinController.php
git commit -m "refactor(integrations): migrate linkedin to GenericPlatformController"
```
Expected: tests PASS.

---

## Task 5: Migrate `threads`

**Files:**
- Create: `app/Services/Platforms/Normalizers/ThreadsNormalizer.php`
- Modify: `tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php`
- Modify: `tests/Feature/Platforms/GenericLinkControllerTest.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`
- Modify: `routes/api/integrations.php`
- Delete: `app/Http/Controllers/Api/Platforms/ThreadsController.php`

- [ ] **Step 1: Append the `ThreadsNormalizer` unit cases**

Append to `tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php`:

```php
it('Threads normalizes a bare @handle', function () {
    expect((new \App\Services\Platforms\Normalizers\ThreadsNormalizer)('@janed'))
        ->toBe(['username' => 'janed', 'url' => 'https://www.threads.net/@janed']);
});

it('Threads normalizes a threads.com profile url', function () {
    expect((new \App\Services\Platforms\Normalizers\ThreadsNormalizer)('https://www.threads.com/@janed'))
        ->toBe(['username' => 'janed', 'url' => 'https://www.threads.net/@janed']);
});

it('Threads rejects an invalid handle', function () {
    expect((new \App\Services\Platforms\Normalizers\ThreadsNormalizer)('has spaces!'))->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php --filter=Threads`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `ThreadsNormalizer` (ported verbatim from `ThreadsController::normalize`)**

```php
<?php

namespace App\Services\Platforms\Normalizers;

use App\Services\Platforms\PlatformInput;

// Threads link-only normalizer — ported verbatim from the former ThreadsController.
// Threads handles mirror Instagram usernames; both threads.net and threads.com
// URLs are accepted (any scheme or none, trailing junk tolerated) → {username, url}.
class ThreadsNormalizer
{
    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~threads\.(?:net|com)/@?([^/?#]+)~i', $s, $m)) {
            $candidate = ltrim($m[1], '@');
        } else {
            $candidate = PlatformInput::token($s);
        }

        if (! preg_match('~^[A-Za-z0-9._]{1,30}$~', $candidate)) {
            return null;
        }

        return ['username' => $candidate, 'url' => 'https://www.threads.net/@'.$candidate];
    }
}
```

- [ ] **Step 4: Run the normalizer test to green**

Run: `php artisan test tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php --filter=Threads`
Expected: PASS (3 Threads cases).

- [ ] **Step 5: Append the `threads` feature cases**

Append to `tests/Feature/Platforms/GenericLinkControllerTest.php`:

```php
it('threads connect stores the canonical link and echoes {username,url}', function () {
    actingAsUser(genericLinkUser('gth1'))
        ->postJson('/api/platforms/threads/connect', ['username' => '@janed'])
        ->assertOk()
        ->assertExactJson(['username' => 'janed', 'url' => 'https://www.threads.net/@janed']);
});

it('threads connect returns the exact 422 message on invalid input', function () {
    actingAsUser(genericLinkUser('gth2'))
        ->postJson('/api/platforms/threads/connect', ['username' => 'has spaces!'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter your Threads handle or profile URL (threads.net/@yourname).');
});
```

- [ ] **Step 6: Run to confirm the new cases pass against current code**

Run: `php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php --filter=threads`
Expected: PASS (served by `ThreadsController` today).

- [ ] **Step 7: Attach the connect strategy + flip the route**

1. In the provider, add `use App\Services\Platforms\Normalizers\ThreadsNormalizer;` and, in the connect-strategies block, add:

```php
            $r->get('threads')->connect(new UrlConnect(new ThreadsNormalizer), 'Enter your Threads handle or profile URL (threads.net/@yourname).');
```

2. In `routes/api/integrations.php`, remove `'threads' => ThreadsController::class,` from `$singleSelection`, remove the `use ...\ThreadsController;` import, and add `'threads'` to the generic loop:

```php
    foreach (['x', 'linkedin', 'threads'] as $slug) {
```

- [ ] **Step 8: Guards + feature test against NEW path, then delete the controller**

```bash
php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
git rm app/Http/Controllers/Api/Platforms/ThreadsController.php
grep -rn "ThreadsController" app/ routes/ tests/ --include="*.php"
```
Expected: tests PASS (count `52`); `grep` returns NOTHING.

- [ ] **Step 9: Re-run guards + commit**

```bash
php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
php artisan pint --dirty
git add app/Services/Platforms/Normalizers/ThreadsNormalizer.php \
  app/Providers/PlatformRegistryServiceProvider.php routes/api/integrations.php \
  tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php \
  tests/Feature/Platforms/GenericLinkControllerTest.php
git add -A app/Http/Controllers/Api/Platforms/ThreadsController.php
git commit -m "refactor(integrations): migrate threads to GenericPlatformController"
```
Expected: tests PASS.

---

## Task 6: Migrate `reddit`

**Files:**
- Create: `app/Services/Platforms/Normalizers/RedditNormalizer.php`
- Modify: `tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php`
- Modify: `tests/Feature/Platforms/GenericLinkControllerTest.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`
- Modify: `routes/api/integrations.php`
- Delete: `app/Http/Controllers/Api/Platforms/RedditController.php`

- [ ] **Step 1: Append the `RedditNormalizer` unit cases**

Append to `tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php`:

```php
it('Reddit normalizes a u/ username to the user profile url', function () {
    expect((new \App\Services\Platforms\Normalizers\RedditNormalizer)('u/janed'))
        ->toBe(['username' => 'janed', 'url' => 'https://www.reddit.com/user/janed/']);
});

it('Reddit normalizes an r/ community', function () {
    expect((new \App\Services\Platforms\Normalizers\RedditNormalizer)('r/community'))
        ->toBe(['username' => 'community', 'url' => 'https://www.reddit.com/r/community/']);
});

it('Reddit maps a bare username to a user profile', function () {
    expect((new \App\Services\Platforms\Normalizers\RedditNormalizer)('janed'))
        ->toBe(['username' => 'janed', 'url' => 'https://www.reddit.com/user/janed/']);
});

it('Reddit rejects a reddit.com url without a profile/community path', function () {
    expect((new \App\Services\Platforms\Normalizers\RedditNormalizer)('https://www.reddit.com/about'))->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php --filter=Reddit`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `RedditNormalizer` (ported verbatim from `RedditController::normalize`)**

```php
<?php

namespace App\Services\Platforms\Normalizers;

use App\Services\Platforms\PlatformInput;

// Reddit link-only normalizer — ported verbatim from the former RedditController.
// Accepts user profiles (u/name, user/name, reddit.com/user/name) AND subreddits
// (r/name), any reddit subdomain or a bare username → {username, url}; null when a
// reddit.com URL carries no profile/community path or the name is invalid.
class RedditNormalizer
{
    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        // URL forms (any reddit subdomain) and bare "u/…" / "r/…" prefixes.
        if (preg_match('~(?:reddit\.com/|^)(u|user|r)/([^/?#]+)~i', $s, $m)) {
            $kind = strtolower($m[1]) === 'r' ? 'r' : 'user';
            $name = $m[2];
        } elseif (str_contains($s, 'reddit.com')) {
            // A reddit.com URL without a profile/community path.
            return null;
        } else {
            $kind = 'user';
            $name = PlatformInput::token($s);
        }

        if (! preg_match('~^[A-Za-z0-9_\-]{2,21}$~', $name)) {
            return null;
        }

        return [
            'username' => $name,
            'url' => "https://www.reddit.com/{$kind}/{$name}/",
        ];
    }
}
```

- [ ] **Step 4: Run the normalizer test to green**

Run: `php artisan test tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php --filter=Reddit`
Expected: PASS (4 Reddit cases).

- [ ] **Step 5: Append the `reddit` feature cases**

Append to `tests/Feature/Platforms/GenericLinkControllerTest.php`:

```php
it('reddit connect stores a u/ profile and echoes {username,url}', function () {
    actingAsUser(genericLinkUser('grd1'))
        ->postJson('/api/platforms/reddit/connect', ['username' => 'u/janed'])
        ->assertOk()
        ->assertExactJson(['username' => 'janed', 'url' => 'https://www.reddit.com/user/janed/']);
});

it('reddit connect returns the exact 422 message on a non-profile url', function () {
    actingAsUser(genericLinkUser('grd2'))
        ->postJson('/api/platforms/reddit/connect', ['username' => 'https://www.reddit.com/about'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter your Reddit username or community (u/yourname or r/yourcommunity).');
});
```

- [ ] **Step 6: Run to confirm the new cases pass against current code**

Run: `php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php --filter=reddit`
Expected: PASS (served by `RedditController` today).

- [ ] **Step 7: Attach the connect strategy + flip the route**

1. In the provider, add `use App\Services\Platforms\Normalizers\RedditNormalizer;` and, in the connect-strategies block, add:

```php
            $r->get('reddit')->connect(new UrlConnect(new RedditNormalizer), 'Enter your Reddit username or community (u/yourname or r/yourcommunity).');
```

2. In `routes/api/integrations.php`, remove `'reddit' => RedditController::class,` from `$singleSelection`, remove the `use ...\RedditController;` import, and add `'reddit'` to the generic loop:

```php
    foreach (['x', 'linkedin', 'threads', 'reddit'] as $slug) {
```

- [ ] **Step 8: Guards + feature test against NEW path, then delete the controller**

```bash
php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
git rm app/Http/Controllers/Api/Platforms/RedditController.php
grep -rn "RedditController" app/ routes/ tests/ --include="*.php"
```
Expected: tests PASS (count `52`); `grep` returns NOTHING.

- [ ] **Step 9: Re-run guards + commit**

```bash
php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
php artisan pint --dirty
git add app/Services/Platforms/Normalizers/RedditNormalizer.php \
  app/Providers/PlatformRegistryServiceProvider.php routes/api/integrations.php \
  tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php \
  tests/Feature/Platforms/GenericLinkControllerTest.php
git add -A app/Http/Controllers/Api/Platforms/RedditController.php
git commit -m "refactor(integrations): migrate reddit to GenericPlatformController"
```
Expected: tests PASS.

---

## Task 7: Migrate `tiktok` (and delete the orphaned `ConnectTiktokRequest`)

> TikTok's old controller used `ConnectTiktokRequest`, which is byte-identical to `ConnectSocialLinkRequest` (`username: required|string|max:200`). The generic controller uses `ConnectSocialLinkRequest`, so the validation-422 contract is unchanged and `ConnectTiktokRequest` becomes an orphan we remove.

**Files:**
- Create: `app/Services/Platforms/Normalizers/TiktokNormalizer.php`
- Modify: `tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php`
- Modify: `tests/Feature/Platforms/GenericLinkControllerTest.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`
- Modify: `routes/api/integrations.php`
- Delete: `app/Http/Controllers/Api/Platforms/TiktokController.php`, `app/Http/Requests/Platforms/ConnectTiktokRequest.php`

- [ ] **Step 1: Append the `TiktokNormalizer` unit cases**

Append to `tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php`:

```php
it('TikTok normalizes a bare @handle', function () {
    expect((new \App\Services\Platforms\Normalizers\TiktokNormalizer)('@dancer'))
        ->toBe(['username' => 'dancer', 'url' => 'https://www.tiktok.com/@dancer']);
});

it('TikTok normalizes a tiktok.com/@handle url', function () {
    expect((new \App\Services\Platforms\Normalizers\TiktokNormalizer)('https://www.tiktok.com/@dancer'))
        ->toBe(['username' => 'dancer', 'url' => 'https://www.tiktok.com/@dancer']);
});

it('TikTok rejects an @-only input (empty handle)', function () {
    expect((new \App\Services\Platforms\Normalizers\TiktokNormalizer)('@'))->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php --filter=TikTok`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `TiktokNormalizer` (ports `TiktokController::normalizeUsername`; empty handle → null)**

```php
<?php

namespace App\Services\Platforms\Normalizers;

// TikTok link-only normalizer — ports the former TiktokController. We do NOT
// scrape (TikTok anti-bot makes server-side profile fetching unreliable); accept
// a bare handle, @handle, or a tiktok.com/@handle URL → {username, url}. Returns
// null when no handle survives (e.g. "@" alone) so the controller emits its 422.
class TiktokNormalizer
{
    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $username = $this->normalizeUsername($input);
        if ($username === '') {
            return null;
        }

        return [
            'username' => $username,
            'url' => 'https://www.tiktok.com/@'.$username,
        ];
    }

    // Accept a bare handle, @handle, or a tiktok.com/@handle URL → bare handle.
    private function normalizeUsername(string $input): string
    {
        $s = trim($input);
        if (preg_match('~tiktok\.com/@([A-Za-z0-9._]+)~i', $s, $m)) {
            return $m[1];
        }

        return ltrim($s, '@');
    }
}
```

- [ ] **Step 4: Run the normalizer test to green**

Run: `php artisan test tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php --filter=TikTok`
Expected: PASS (3 TikTok cases).

- [ ] **Step 5: Append the `tiktok` feature cases**

Append to `tests/Feature/Platforms/GenericLinkControllerTest.php`:

```php
it('tiktok connect normalizes @handle and echoes {username,url}', function () {
    actingAsUser(genericLinkUser('gtt1'))
        ->postJson('/api/platforms/tiktok/connect', ['username' => '@dancer'])
        ->assertOk()
        ->assertExactJson(['username' => 'dancer', 'url' => 'https://www.tiktok.com/@dancer']);
});

it('tiktok connect returns the exact 422 message when no handle survives', function () {
    actingAsUser(genericLinkUser('gtt2'))
        ->postJson('/api/platforms/tiktok/connect', ['username' => '@'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter your TikTok username or profile URL.');
});
```

- [ ] **Step 6: Run the new cases + the existing TikTok suites against current code**

Run:
```bash
php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php --filter=tiktok \
  tests/Feature/Platforms/LinkPlatformsConnectionTest.php \
  tests/Feature/Platforms/PlatformLoopTest.php
```
Expected: PASS (served by `TiktokController` today; these existing suites exercise tiktok connect/delete/isolation and must keep passing after the flip).

- [ ] **Step 7: Attach the connect strategy + flip the route**

1. In the provider, add `use App\Services\Platforms\Normalizers\TiktokNormalizer;` and, in the connect-strategies block, add:

```php
            $r->get('tiktok')->connect(new UrlConnect(new TiktokNormalizer), 'Enter your TikTok username or profile URL.');
```

2. In `routes/api/integrations.php`, remove `'tiktok' => TiktokController::class,` from `$singleSelection`, remove the `use ...\TiktokController;` import, and add `'tiktok'` to the generic loop:

```php
    foreach (['x', 'linkedin', 'threads', 'reddit', 'tiktok'] as $slug) {
```

- [ ] **Step 8: Run the full TikTok contract surface against the NEW path**

Run:
```bash
php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php \
  tests/Feature/Platforms/PlatformResourceContractTest.php \
  tests/Feature/Platforms/LinkPlatformsConnectionTest.php \
  tests/Feature/Platforms/PlatformLoopTest.php \
  tests/Feature/Platforms/PlatformConnectionAuthorizationTest.php
```
Expected: PASS — including `PlatformResourceContractTest`'s "tiktok connect returns exactly {username,url}" / "tiktok selection…strips unknown keys", `LinkPlatformsConnectionTest`'s `@dancer` + isolation cases, and `PlatformConnectionAuthorizationTest`'s "422 when TikTok connect is missing the username field" (the shared `ConnectSocialLinkRequest` still enforces `required`). Net count still `52`.

- [ ] **Step 9: Delete the controller + the orphaned request, confirm no references**

```bash
git rm app/Http/Controllers/Api/Platforms/TiktokController.php app/Http/Requests/Platforms/ConnectTiktokRequest.php
grep -rn "TiktokController\|ConnectTiktokRequest" app/ routes/ tests/ --include="*.php"
```
Expected: `grep` returns NOTHING.

- [ ] **Step 10: Re-run the surface + commit**

```bash
php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php \
  tests/Feature/Platforms/PlatformResourceContractTest.php \
  tests/Feature/Platforms/LinkPlatformsConnectionTest.php
php artisan pint --dirty
git add app/Services/Platforms/Normalizers/TiktokNormalizer.php \
  app/Providers/PlatformRegistryServiceProvider.php routes/api/integrations.php \
  tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php \
  tests/Feature/Platforms/GenericLinkControllerTest.php
git add -A app/Http/Controllers/Api/Platforms/TiktokController.php app/Http/Requests/Platforms/ConnectTiktokRequest.php
git commit -m "refactor(integrations): migrate tiktok to GenericPlatformController"
```
Expected: tests PASS.

---

## Task 8: Migrate `facebook` (and delete the orphaned `ConnectFacebookRequest`)

> Facebook is the trickiest port: its old controller's `normalize()` ALWAYS returns an array, then the controller decides whether to 422 based on an empty username that is NOT a `profile.php` / `/pages/` link. `FacebookNormalizer` folds that decision in — it returns `null` exactly when the old controller would have 422'd — so the generic null-check reproduces the behavior. `ConnectFacebookRequest` is byte-identical to `ConnectSocialLinkRequest` and is removed as an orphan.

**Files:**
- Create: `app/Services/Platforms/Normalizers/FacebookNormalizer.php`
- Modify: `tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php`
- Modify: `tests/Feature/Platforms/GenericLinkControllerTest.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`
- Modify: `routes/api/integrations.php`
- Delete: `app/Http/Controllers/Api/Platforms/FacebookController.php`, `app/Http/Requests/Platforms/ConnectFacebookRequest.php`

- [ ] **Step 1: Append the `FacebookNormalizer` unit cases (cover every branch the controller had)**

Append to `tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php`:

```php
it('Facebook normalizes a vanity handle', function () {
    expect((new \App\Services\Platforms\Normalizers\FacebookNormalizer)('@nike'))
        ->toBe(['username' => 'nike', 'url' => 'https://www.facebook.com/nike']);
});

it('Facebook keeps a legacy /pages/Name/ID link with an empty username', function () {
    expect((new \App\Services\Platforms\Normalizers\FacebookNormalizer)('https://www.facebook.com/pages/Some-Cafe/123456789'))
        ->toBe(['username' => '', 'url' => 'https://www.facebook.com/pages/Some-Cafe/123456789']);
});

it('Facebook strips a query string from a /pages/ link', function () {
    expect((new \App\Services\Platforms\Normalizers\FacebookNormalizer)('https://www.facebook.com/pages/Some-Cafe/123456789?ref=bookmarks'))
        ->toBe(['username' => '', 'url' => 'https://www.facebook.com/pages/Some-Cafe/123456789']);
});

it('Facebook keeps a numeric profile.php link with an empty username', function () {
    expect((new \App\Services\Platforms\Normalizers\FacebookNormalizer)('https://www.facebook.com/profile.php?id=12345'))
        ->toBe(['username' => '', 'url' => 'https://www.facebook.com/profile.php?id=12345']);
});

it('Facebook rejects a bare facebook.com/ link with no handle', function () {
    expect((new \App\Services\Platforms\Normalizers\FacebookNormalizer)('https://www.facebook.com/'))->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php --filter=Facebook`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `FacebookNormalizer` (ports `FacebookController::normalize` + the controller's empty-username 422 decision as a null return)**

```php
<?php

namespace App\Services\Platforms\Normalizers;

// Facebook link-only normalizer — ports the former FacebookController. Accept a
// bare handle, a facebook.com/<handle> vanity URL, or a facebook.com/profile.php
// or legacy /pages/<Name>/<id> link → {username, url}. The old controller always
// built an array, then 422'd when the username was empty AND the URL was neither
// a profile.php nor a /pages/ link; that decision is folded in here as a null
// return, so the generic controller's null-check reproduces the exact behavior.
class FacebookNormalizer
{
    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $selection = $this->parse($input);

        // Empty username is only valid for numeric profile.php links and legacy
        // /pages/<Name>/<id> Page links; anything else with no handle is junk input.
        if ($selection['username'] === ''
            && ! str_contains($selection['url'], 'profile.php')
            && ! str_contains($selection['url'], '/pages/')) {
            return null;
        }

        return $selection;
    }

    /**
     * @return array{username:string, url:string}
     */
    private function parse(string $input): array
    {
        $s = trim($input);

        if (preg_match('~facebook\.com/(.+)$~i', $s, $m)) {
            $path = trim($m[1], '/');

            if (str_starts_with(strtolower($path), 'profile.php')) {
                // Numeric profile link — no vanity username; keep the full path.
                return ['username' => '', 'url' => 'https://www.facebook.com/'.$path];
            }

            if (str_starts_with(strtolower($path), 'pages/')) {
                // Legacy Page link /pages/<Name>/<id> — the username is NOT the
                // first segment ("pages"); there's no vanity handle. Keep the
                // path (minus any query) and leave username empty.
                $clean = explode('?', $path)[0];

                return ['username' => '', 'url' => 'https://www.facebook.com/'.$clean];
            }

            // Vanity URL — first path segment is the username; drop query/trailing path.
            $username = explode('/', explode('?', $path)[0])[0];

            return ['username' => $username, 'url' => 'https://www.facebook.com/'.$username];
        }

        $username = ltrim($s, '@');

        return ['username' => $username, 'url' => 'https://www.facebook.com/'.$username];
    }
}
```

- [ ] **Step 4: Run the normalizer test to green**

Run: `php artisan test tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php --filter=Facebook`
Expected: PASS (5 Facebook cases).

- [ ] **Step 5: Append the `facebook` feature cases**

Append to `tests/Feature/Platforms/GenericLinkControllerTest.php`:

```php
it('facebook connect stores a vanity handle and echoes {username,url}', function () {
    actingAsUser(genericLinkUser('gfb1'))
        ->postJson('/api/platforms/facebook/connect', ['username' => 'jane.doe'])
        ->assertOk()
        ->assertExactJson(['username' => 'jane.doe', 'url' => 'https://www.facebook.com/jane.doe']);
});

it('facebook connect keeps a legacy /pages/ link with an empty username', function () {
    actingAsUser(genericLinkUser('gfb2'))
        ->postJson('/api/platforms/facebook/connect', ['username' => 'https://www.facebook.com/pages/Some-Cafe/123456789'])
        ->assertOk()
        ->assertExactJson(['username' => '', 'url' => 'https://www.facebook.com/pages/Some-Cafe/123456789']);
});

it('facebook connect returns the exact 422 message on a handleless link', function () {
    actingAsUser(genericLinkUser('gfb3'))
        ->postJson('/api/platforms/facebook/connect', ['username' => 'https://www.facebook.com/'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter your Facebook username or profile URL.');
});
```

- [ ] **Step 6: Run the new cases + the existing Facebook suites against current code**

Run:
```bash
php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php --filter=facebook \
  tests/Feature/Platforms/PlatformFixesTest.php \
  tests/Feature/Platforms/PlatformResourceContractTest.php
```
Expected: PASS (served by `FacebookController` today; `PlatformFixesTest` covers the /pages/ + vanity branches, `PlatformResourceContractTest` covers the exact connect/selection shapes).

- [ ] **Step 7: Attach the connect strategy + flip the route**

1. In the provider, add `use App\Services\Platforms\Normalizers\FacebookNormalizer;` and, in the connect-strategies block, add:

```php
            $r->get('facebook')->connect(new UrlConnect(new FacebookNormalizer), 'Enter your Facebook username or profile URL.');
```

2. In `routes/api/integrations.php`, remove `'facebook' => FacebookController::class,` from `$singleSelection`, remove the `use ...\FacebookController;` import, and add `'facebook'` to the generic loop:

```php
    foreach (['x', 'linkedin', 'threads', 'reddit', 'tiktok', 'facebook'] as $slug) {
```

> After this edit `$singleSelection` no longer contains any of the six link-only slugs. Confirm the array now starts at `'spotify'` and ends at `'nowbookit'` (the music/streaming/reservation platforms), with no `x/linkedin/threads/reddit/tiktok/facebook` entries.

- [ ] **Step 8: Run the full Facebook contract surface against the NEW path**

Run:
```bash
php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php \
  tests/Feature/Platforms/PlatformResourceContractTest.php \
  tests/Feature/Platforms/PlatformFixesTest.php \
  tests/Feature/Platforms/LinkPlatformsConnectionTest.php \
  tests/Feature/Platforms/PlatformConnectionAuthorizationTest.php
```
Expected: PASS — including `PlatformResourceContractTest`'s facebook connect/selection exact-JSON, `PlatformFixesTest`'s /pages/ + query-strip + `@nike` cases, `LinkPlatformsConnectionTest`'s "Facebook legacy page link", and `PlatformConnectionAuthorizationTest`'s "422 when Facebook connect is missing the username field". Net count still `52`.

- [ ] **Step 9: Delete the controller + the orphaned request, confirm no references**

```bash
git rm app/Http/Controllers/Api/Platforms/FacebookController.php app/Http/Requests/Platforms/ConnectFacebookRequest.php
grep -rn "FacebookController\|ConnectFacebookRequest" app/ routes/ tests/ --include="*.php"
```
Expected: `grep` returns NOTHING.

- [ ] **Step 10: Re-run the surface + commit**

```bash
php artisan test tests/Feature/Platforms/GenericLinkControllerTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php \
  tests/Feature/Platforms/PlatformResourceContractTest.php \
  tests/Feature/Platforms/PlatformFixesTest.php
php artisan pint --dirty
git add app/Services/Platforms/Normalizers/FacebookNormalizer.php \
  app/Providers/PlatformRegistryServiceProvider.php routes/api/integrations.php \
  tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php \
  tests/Feature/Platforms/GenericLinkControllerTest.php
git add -A app/Http/Controllers/Api/Platforms/FacebookController.php app/Http/Requests/Platforms/ConnectFacebookRequest.php
git commit -m "refactor(integrations): migrate facebook to GenericPlatformController"
```
Expected: tests PASS.

---

## Task 9: Whole-suite green + scope verification

**Files:** none (verification + a tiny doc-comment cleanup if needed).

- [ ] **Step 1: Confirm all six controllers and the two orphan requests are gone**

```bash
ls app/Http/Controllers/Api/Platforms/ | grep -E "^(X|Linkedin|Threads|Reddit|Tiktok|Facebook)Controller.php$" || echo "all six deleted"
ls app/Http/Requests/Platforms/ | grep -E "^Connect(Tiktok|Facebook)Request.php$" || echo "both orphans deleted"
grep -rn -E "XController|LinkedinController|ThreadsController|RedditController|TiktokController|FacebookController|ConnectTiktokRequest|ConnectFacebookRequest" app/ routes/ tests/ --include="*.php" || echo "no dangling references"
```
Expected: "all six deleted", "both orphans deleted", "no dangling references".

- [ ] **Step 2: Confirm the registry now has a connect strategy for exactly the six migrated platforms**

Run:
```bash
php artisan tinker --execute="\$r = app(\App\Services\Platforms\Registry\PlatformRegistry::class); echo collect(\$r->all())->filter(fn(\$d) => \$d->connectStrategy() !== null)->keys()->sort()->values()->implode(',');"
```
Expected: `facebook,linkedin,reddit,threads,tiktok,x` (order may differ before sort; the sorted output is these six and ONLY these six — skool/custom and every other platform have no connect strategy yet).

- [ ] **Step 3: Run the full platforms + registry suite**

Run: `php artisan test tests/Feature/Platforms tests/Unit/Platforms`
Expected: PASS — golden master (count `52`), `PlatformResourceContractTest`, `LinkPlatformsConnectionTest`, `PlatformFixesTest`, `PlatformLoopTest`, `PlatformConnectionAuthorizationTest`, `IntegrationsDomainsV2Test` (incl. the `tiktok/refresh` → 422 case, which uses the untouched `RefreshController`), plus all new `LinkPayload` / normalizer / generic-controller tests.

- [ ] **Step 4: Run the whole suite to confirm no global regression**

Run: `composer test`
Expected: PASS (or the same pre-existing baseline failures unrelated to this plan — compare to a pre-plan run; no NEW failures).

- [ ] **Step 5: Confirm the frozen contract at the HTTP layer one final time**

Run: `php artisan test --filter="PlatformResourceContract|GoldenMaster"`
Expected: PASS — the contract held end-to-end through all six migrations.

- [ ] **Step 6: Sanity-check the route table is intact**

Run: `php artisan route:list --path=platforms/ | grep -E "/(x|linkedin|threads|reddit|tiktok|facebook)/(connect|selection)$|DELETE .*platforms/(x|linkedin|threads|reddit|tiktok|facebook)$" | grep -c GenericPlatformController`
Expected: a non-zero count — the six platforms' connect/selection/forget routes now resolve to `GenericPlatformController` under both the `platforms` and `integrations` bases.

- [ ] **Step 7: Final Pint pass (no-op expected) + done**

Run: `php artisan pint --dirty`
Expected: nothing to fix (all prior commits ran Pint). No commit needed if clean.

---

## Deferred — explicitly OUT of scope for this plan

These are named so a reviewer can confirm nothing leaked in from later plans, and so the next plan knows where to start:

- **`skool`** — NOT link-only. `SkoolController::connect` scrapes the community's about page (`SkoolScraper::normalizeUrl` + `fetchCommunity`) and returns **404** when the page can't be read. That is a fetch-on-connect flow the link-only `normalize → store` path does not model. Migrate it with the scraped/feed archetype in a later plan. (Its `/selection` read already renders via `SkoolConnectionResource`; the golden master's `skool` case stays green because this plan does not touch its route or controller.)
- **`custom`** — NOT link-only. `CustomLinksController` is a multi-row list (`GET/POST /links`, `DELETE /links/{id}`), uses `LinkCardScraper` to snapshot favicon/og-image on add, enforces `MAX_LINKS`, and returns a bespoke array shape (no Resource class). It needs its own archetype treatment in a later plan.
- **oEmbed / scraped-feed / picker / shop / bespoke archetypes** (spotify, youtube, fresha, shop, instagram, …) — later plans (spec §10 order).
- **`PlatformRefresher` `match()` → registry iteration** — later plan. This plan leaves the refresher and the `/{platform}/refresh` route untouched.
- **`ProviderDetector` → registry-driven** — later plan.
- **`DROP CONSTRAINT` migration + wiring `PlatformInRegistry` into Form Requests** — later plan. No schema change here.
- **A payload class or fetch/refresh strategy INSTANCES on descriptors** — this plan adds only the `ConnectStrategy`. `GenericPlatformController` hardcodes `LinkPayload` because all six migrated platforms share it; a payload-class-per-descriptor lands when a second archetype migrates.

---

## Self-Review

**1. Spec coverage**
- §6 ① ConnectStrategy (`UrlConnect`, "the only one built — covers all 30") → Tasks 3–8 wrap each platform's normalizer in `UrlConnect` and attach it to the descriptor. ✓
- §7 Link-only archetype (linkedin, x, threads, reddit, tiktok, facebook) → all six migrated (Tasks 3–8). `skool`/`custom`, listed under "Link-only" in the §7 table but actually scrape, are explicitly deferred with evidence. ✓
- §8 Typed payloads (`LinkPayload`, lenient `fromArray`, `toArray`, round-trip) → Task 1 + parity tests. ✓
- §10 Strangler, simplest-first, "delete old controller the moment its replacement goes green," golden master first → each migration task flips → proves green → deletes → re-proves (x first, the simplest). ✓
- §5 "generic registry-driven controller; the 17 thin per-platform controllers are deleted" → `GenericPlatformController` + deletion of six controllers (the link-only subset of the 17). ✓
- §9 capability gate baked into the connect flow (`availableFor`, true today) → generic controller's `connect()`. ✓
- §11 testing (golden master stays green, fetch-free link platforms, archetype parity test for the DTO, no live external calls) → Tasks 1 + 3–9. ✓

**2. Placeholder scan** — No `TBD`/`TODO`/"handle edge cases"/"similar to Task N". Every task carries the full normalizer source (ported verbatim and re-verified against the current controllers), the full controller/DTO source, exact route edits, exact commands, and expected output. The repeated route-flip pattern is shown in full per task (the slug list and imports differ each time), not referenced by number.

**3. Type consistency**
- `LinkPayload::fromArray(array): self`, `toArray(): array{username,url}`, props `?string $username/$url` — consistent across Task 1 and its uses in `GenericPlatformController` (Tasks 3, used unchanged 4–8). ✓
- `PlatformDescriptor::connect(ConnectStrategy, string): self` / `connectStrategy(): ?ConnectStrategy` / `connectErrorMessage(): ?string` — defined in Task 2, consumed identically in the provider edits (Tasks 3–8) and `GenericPlatformController` (Task 3). ✓
- Each normalizer is `__invoke(string): ?array{username:string,url:string}` — a callable matching `UrlConnect`'s `callable(string):(array|null)` ctor arg (Plan 1). ✓
- `GenericPlatformController::platform()` reads `request()->route('platform')`, set by `->defaults('platform', $slug)` on every generic route — the read/write sides match. ✓

**4. Scope discipline** — Nothing from Plans 3–6 (refresher/detector rewrite, DROP CONSTRAINT, other archetypes, Form-Request wiring, payload-per-descriptor) is implemented; all are in "Deferred". The only schema-adjacent surface (the CHECK constraint) is untouched. `config('partna.social_platforms')` is never referenced. The net-completeness count is preserved by design, not edited.
