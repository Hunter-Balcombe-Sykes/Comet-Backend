# Platform Integrations — Bespoke & Specials (Plan 5 of N)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the last un-migrated platforms — the **bespoke three's specials** (Instagram async connect, Google Business auto-sync, the events smart-detect facade) plus the **smart-detect category cards** (booking / reservations / online-ordering), **custom links**, and the **menu source** — onto the registry's typed-payload boundary, so that **no platform read path accesses its stored `payload` via untyped `data_get`/array-indexing** anywhere in the codebase (the one exit criterion), with the API contract frozen byte-for-byte throughout.

**Architecture:** Plans 1–4 left a `PlatformRegistry` whose descriptors already carry every platform's *identity* (key/label/category/resource) — including the bespoke/specials platforms — and four payload DTOs (`LinkPayload`, `EmbedPayload`, `FeedPayload`, `SelectionPayload`/`FreshaSelection`, `ShopPayload`). What is missing for the bespoke/specials platforms is the **typed payload + read-path migration**: `instagram`, `google-business`, the events platforms, `custom`, and the three category pseudo-platforms still read raw `jsonb` via `data_get($row->payload, …)` / `is_array($payload…)`. This plan adds the remaining DTOs (`InstagramPayload`, `GoogleBusinessPayload`, `EventsAccountPayload`, `StandaloneEventPayload`, `CardPayload`), points each bespoke/special descriptor at its DTO, and migrates every read site onto it — keeping each platform's **bespoke controller, job, observer, and live-scrape WRITE construction intact** (only payload *reads* change). Two DTO styles are reused from Plan 4: **normalizing** (fixed typed props, like `FeedPayload`) for Instagram's fixed-key resource, and **verbatim-preserving** (raw array + typed accessors, like `FreshaSelection`/`ShopPayload`) wherever the stored blob is passed through to a variable-key resource or written back to storage.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4 + PHPUnit, SQLite in-memory for tests, Supabase/Postgres in prod.

**Design spec:** `docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md` (§6 cross-cutting capabilities, §7 specials + bespoke-controllers-retained, §8 typed payloads incl. internal-only fields like Instagram's `_folder`, §10 strangler, §11 testing).

**Builds on (all MERGED on `development`):**
- Plan 1 (spine): `app/Services/Platforms/Registry/{PlatformRegistry,PlatformDescriptor,PlatformCategory}.php`; `app/Providers/PlatformRegistryServiceProvider.php` (which **already registers** descriptors for `instagram`, `eventbrite`, `humanitix`, `events-custom`, `custom`, `booking`, `reservations`, `online-ordering`, `google-business`, and the five pickers/shop); the golden-master net under `tests/Feature/Platforms/GoldenMaster/`.
- Plans 2–3 (link-only / embed / feed): `GenericPlatformController`; `LinkPayload`/`EmbedPayload`/`FeedPayload`; the descriptor `payload()`/`payloadClass()` API; `GenericPlatformController::shape()` = `new $resourceClass($payloadClass::fromArray($payload)->toArray())->resolve()` (the canonical hydration).
- Plan 4 (picker/shop): `SelectionPayload` + `FreshaSelection` + `ShopPayload`; the **verbatim-preserving DTO** idiom (raw array + typed accessors, `toArray()` returns the blob unchanged) used here for the variable-key / write-back payloads; the **resource-output-equivalence** read-migration contract.

## Why this is ONE plan

The author scope (spec §7) is a single cohesive sweep: every remaining bespoke/special read path, ending at a single exit criterion ("no untyped payload read survives"). It is larger than Plan 4 (13 tasks vs 8) but the parts are independent and each is independently shippable and green: **A** Instagram, **B** Google Business, **C** Events, **D** category cards + online-ordering + custom links + menu source, **E** the residual feed-controller `data_get` mop-up, **F** the exit-criterion test + full-suite verification. An implementer may ship A–E as separate branches; F must land last (it asserts the end state). A single branch is also fine.

## Global Constraints

- **No Laravel migrations.** This plan touches NO schema. A composer guard (`guard:no-laravel-migrations`) rejects Laravel migrations regardless. The lone schema change (`DROP CONSTRAINT`) is Plan 6.
- **API contract is FROZEN — byte-for-byte.** Every route URI, JSON response shape, and error string stays identical. The parity guards that must stay green after every task: `IntegrationContractGoldenMasterTest`, `InstagramAsyncConnectTest`, `InstagramR2CleanupTest`, `GoogleBusinessApifyTest`, `EventsCatalogTest`, `IntegrationCategoriesTest`, `ReservationProvidersTest`, `MenuTest`, `PlatformResourceContractTest`. **No assertion in those files may be loosened to make a change pass.**
- **The golden-master net-completeness count must stay `52`.** `IntegrationContractGoldenMasterTest` asserts `expect($readRoutes->count())->toBe(52)`. This plan adds/removes NO routes, so the number does not change. If it ever does, a route URI changed — stop and reconcile; never edit the `52`.
- **Bespoke flows stay intact.** Instagram's async `connect()` → 202 → `connectStatus()` poll contract, the R2 `_folder` mirror + disconnect cleanup, Google Business's cross-platform auto-seeding (`GoogleBusinessAutoSync::seed()` seeds opentable/resdiary/nowbookit/online-ordering/instagram/socials/booking rows), the events smart-detect facade, and the menu fetch pipeline all keep their controllers/jobs/services. **Only payload READS are migrated; live-scrape WRITE construction stays literal-canonical** (the same rule Plan 4 used for Fresha/Shop writes — a freshly-built selection blob is not a `data_get` read site).
- **Internal-only payload fields MUST stay out of responses.** Instagram's `_folder` (R2 prefix) and `source` (google-business origin tag) are carried by `InstagramPayload` but the resource omits them — exactly as today. The DTO is the honest, complete schema (spec §8); the resource is the allowlist. `_folder` must never appear in any `/instagram/*` response. `InstagramR2CleanupTest` already guards this.
- **Reads use the DTO; the resource is the allowlist.** Read paths follow **resource-output equivalence**: `Resource(Payload::fromArray($raw)->toArray()) === Resource($raw)`. For **fixed-key resources** (Instagram) a normalizing DTO is safe (the resource allowlists the canonical keys with `?? null`/`?? []`/`?? 0`). For **variable-key resources** (Google Business uses `array_intersect_key`) and **public-passthrough blobs** (Fresha, events `upcoming`), the DTO must be **verbatim-preserving** so it injects no canonical-null keys into storage or serialization.
- **Authorization via the trait chokepoint.** `ManagesIntegrationConnection` runs `authorizeForUser($user, …)` on every read/write/delete. Bespoke controllers reuse the trait unchanged — never add inline 403 aborts.
- **`config/partna.php` `social_platforms` is a SEPARATE registry** (the link-block UI). Do NOT reference it from any spine/controller code.
- **Tests run on SQLite; prod is Postgres.** All new code is app-level and engine-agnostic. Reuse the existing per-test seed helpers (`igAsyncUser`, `gbApifyUser`, `eventsUser`, `catUser`, `menuUser`, `resUser`, `gmUser`/`gmSeed`); new test users use `'account_type' => 'partna'` (the current standard value) unless a test needs `'business'` for a capability.
- **Pint clean.** Run `php artisan pint --dirty` before every commit; never reformat untouched files.
- **Commit prefixes:** `feat(integrations):` for new DTO classes, `refactor(integrations):` for a controller/job/observer/service read migration, `test(integrations):` for test-only additions.

---

## Prerequisite check (run before Task 1)

- [ ] **Confirm Plan 4 is merged and the building blocks exist**

Run:
```bash
git fetch && git pull && git log --oneline -10
ls app/Services/Platforms/Payloads/   # expect: LinkPayload EmbedPayload FeedPayload SelectionPayload FreshaSelection ShopPayload
php artisan tinker --execute="\$r = app(App\Services\Platforms\Registry\PlatformRegistry::class); foreach (['instagram','google-business','eventbrite','humanitix','events-custom','custom','booking','reservations','online-ordering'] as \$k) { echo \$k.': '.((\$r->get(\$k)?->payloadClass()) ?? 'NO-PAYLOAD').PHP_EOL; }"
php artisan test tests/Feature/Platforms tests/Unit/Platforms
```
Expected: the `ls` lists all six payload DTOs; tinker prints `instagram: NO-PAYLOAD`, `google-business: NO-PAYLOAD`, `eventbrite: NO-PAYLOAD`, … (these are precisely the descriptors this plan attaches a `payload()` to); the suite is GREEN. **If `SelectionPayload`/`ShopPayload` are missing, STOP — Plan 4 must land first** and report.

---

## File Structure

**New (DTOs):**
- `app/Services/Platforms/Payloads/InstagramPayload.php` — **normalizing** DTO for the fixed-key Instagram resource. Carries the public render fields **and** the internal `_folder` (R2 prefix) + `source` (google-business tag) the resource omits.
- `app/Services/Platforms/Payloads/GoogleBusinessPayload.php` — **verbatim-preserving** DTO (raw map + typed accessors `name()`, `placeId()`, `apifyStatus()`, `syncFindings()`). Verbatim because `GoogleBusinessConnectionResource` emits a **variable** key set via `array_intersect_key`.
- `app/Services/Platforms/Payloads/EventsAccountPayload.php` — **verbatim-preserving** DTO for events ACCOUNT rows (`{url, organiser, next, upcoming, hiddenEventIds}`); `upcoming` is a verbatim list of scraped event objects (public passthrough).
- `app/Services/Platforms/Payloads/StandaloneEventPayload.php` — **verbatim-preserving** DTO for STANDALONE event rows (`{kind:'event', …event}`); `event()` returns the payload minus the internal `kind` key.
- `app/Services/Platforms/Payloads/CardPayload.php` — **verbatim-preserving** DTO for the `LinkCardScraper`-family branded cards (custom links, booking/reservations custom fallback, online-ordering entries). Typed accessors over `{url, name, description, favicon, logo, provider, source, kind, id, data}`.

**New (tests):**
- `tests/Unit/Platforms/Payloads/InstagramPayloadTest.php`
- `tests/Unit/Platforms/Payloads/GoogleBusinessPayloadTest.php`
- `tests/Unit/Platforms/Payloads/EventsPayloadDtoTest.php` (covers `EventsAccountPayload` + `StandaloneEventPayload`)
- `tests/Unit/Platforms/Payloads/CardPayloadTest.php`
- `tests/Feature/Platforms/NoUntypedPayloadAccessTest.php` — the **exit-criterion** test (Task 12).

**Modified (read-path migrations — writes untouched):**
- `app/Http/Controllers/Api/Platforms/InstagramController.php` — `connectStatus()` + `selection()` hydrate via `InstagramPayload`.
- `app/Jobs/Platforms/InstagramConnectJob.php` — the `source` READ (line 164) via `InstagramPayload`; the scrape WRITE stays literal.
- `app/Observers/Core/IntegrationConnectionObserver.php` — the three `_folder` reads (lines 56–57, 76) via `InstagramPayload`.
- `app/Http/Controllers/Api/Platforms/GoogleBusinessController.php` — `synced()` + `applySync()` syncFindings reads via `GoogleBusinessPayload`.
- `app/Jobs/Platforms/GoogleBusinessEnrichJob.php` — `name`/`placeId`/payload reads (lines 86–87, 136, 153) via `GoogleBusinessPayload`.
- `app/Http/Controllers/Api/Platforms/ReservationsController.php` + `OpenTableController.php` — the `suggestionFromGoogleBusiness` harvest reads via `GoogleBusinessPayload`; reservation status reads via `SelectionPayload`/`CardPayload`.
- `app/Http/Controllers/Api/Platforms/EventsPlatformController.php` + `app/Services/Platforms/EventsCatalog.php` — account/standalone reads via `EventsAccountPayload`/`StandaloneEventPayload`.
- `app/Http/Controllers/Api/Platforms/BookingController.php` — status reads via `SelectionPayload` (fresha/square) + `CardPayload` (custom).
- `app/Http/Controllers/Api/Platforms/OnlineOrderingController.php` — entry consolidation reads via `CardPayload`.
- `app/Http/Controllers/Api/Platforms/CustomLinksController.php` — `linksData()` via `CardPayload`.
- `app/Services/Platforms/MenuSource.php` — the online-ordering payload read (line 213) via `CardPayload`.
- `app/Services/Platforms/GoogleBusinessAutoSync.php` — the ordering store-dedup read (line 448) via `CardPayload`. **The seeding logic itself is NOT touched.**
- `app/Http/Controllers/Api/Platforms/{Apple,Bandcamp,Vimeo,Youtube,YoutubeMusic}Controller.php` — the **six** residual `data_get($row?->payload, …)` re-fetch-input reads via `FeedPayload` (Apple has **two**: `input` at ~274 and `highlights` at ~336; the others one each).
- `app/Providers/PlatformRegistryServiceProvider.php` — set `->payload(…)` on the `instagram`, `google-business`, `eventbrite`, `humanitix`, `events-custom`, `custom`, `booking`, `reservations`, `online-ordering` descriptors.

**Untouched (deferred — see "Deferred" at the end):**
- `app/Services/Platforms/PlatformRefresher.php` — its `$payload['key']` reads are the **refresh/write path**; its `match()` dispatcher (and those reads) are rewritten onto registry iteration in **Plan 6**, where the reads die. Do NOT migrate it here.
- `app/Services/Platforms/Strategies/Fetch/*.php` — the fetch strategies read `$connection->payload['key']` as **re-fetch inputs**; `FeedPayload`/the new DTOs already carry every such key (the `FeedPayload` docblock says so: *"private re-fetch inputs … carried so the fetch strategies + Plan 6's refresher read them typed"*). Plan 6 wires them through the DTO when it collapses the refresher onto `$descriptor->fetchStrategy()->fetch($connection)`. Out of scope here.
- `app/Services/Platforms/ProviderDetector.php` — see the note in Task 8: it is left **as-is**. (Migrating it to read registry categories is *permitted* by the spec but overlaps Plan 6's detector rewrite; this plan keeps it untouched to avoid double-work, and the exit test does not require it — it holds no stored-payload reads.)
- `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php` — the trait's generic payload reads (`data_get($row->payload, $field)` with a caller-supplied `$field`; `$row->payload ?? []` passed to a shape closure; the account-dedup identity-field scan) are **platform-agnostic plumbing** that cannot resolve to one DTO. This is the single sanctioned exit-test allowlist entry.
- The DB `CHECK`, `config('partna.social_platforms')`, wiring `PlatformInRegistry` into Form Requests (Plan 6).

---

# Part A — Instagram (async Apify connect + R2 `_folder` cleanup)

## Task 1: `InstagramPayload` DTO

**Files:**
- Create: `app/Services/Platforms/Payloads/InstagramPayload.php`
- Test: `tests/Unit/Platforms/Payloads/InstagramPayloadTest.php`

**Interfaces:**
- Produces: `final readonly class InstagramPayload` — public props `?string $username, ?string $fullName, ?string $profilePicUrl, ?string $businessCategory, int|string|null $followersCount, int|string|null $postsCount, ?string $mode, array $images, ?string $videoUrl, ?string $videoPoster, int $imagesDropped, ?string $source, ?string $folder`; `static fromArray(mixed $payload): self` (lenient; reads `_folder` → `$folder`, `source` → `$source`); `toArray(): array` (emits all keys incl. `_folder` from `$folder` and `source`).
- Consumed by: Task 2 (`InstagramController`, `InstagramConnectJob`, `IntegrationConnectionObserver`).

> **Why normalizing (not verbatim) here, and why it still round-trips byte-identically:** Instagram's `InstagramConnectionResource` emits a **fixed** key set, each guarded `$this->resource['key'] ?? null|[]|0`. So a normalizing DTO (always emits the same canonical keys) feeds the resource exactly what it allowlists — `Resource(fromArray($raw)->toArray()) === Resource($raw)`. The DTO additionally carries `_folder` and `source`, which the resource's allowlist omits — making the DTO the *honest, complete* schema (spec §8) while keeping responses unchanged. The job's scrape WRITE stays literal (Constraint: live-scrape writes are not migrated), so storage shape is unchanged; the DTO is the **read** boundary at the controller, the job's `source` read, and the observer's `_folder` reads.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Platforms/Payloads/InstagramPayloadTest.php`:

```php
<?php

use App\Http\Resources\Platforms\InstagramConnectionResource;
use App\Services\Platforms\Payloads\InstagramPayload;
use Tests\TestCase;

// resolve() injects a Request via the container, so the resource-equivalence
// cases need the app booted (mirrors tests/Unit/Platforms/Payloads/FeedPayloadTest.php).
uses(TestCase::class)->in(__FILE__);

it('hydrates the stored instagram payload and round-trips the public + internal keys', function () {
    $raw = [
        'username' => 'acme', 'fullName' => 'Acme Co', 'profilePicUrl' => 'https://r2/p.jpg',
        'businessCategory' => 'Cafe', 'followersCount' => 1200, 'postsCount' => 88,
        'mode' => 'automatic', 'images' => ['https://r2/0.jpg'],
        'videoUrl' => 'https://r2/reel.mp4', 'videoPoster' => 'https://r2/cover.jpg',
        '_folder' => 'platforms/instagram/1700000000', 'source' => 'google-business',
    ];

    $dto = InstagramPayload::fromArray($raw);

    expect($dto->username)->toBe('acme');
    expect($dto->followersCount)->toBe(1200);
    expect($dto->images)->toBe(['https://r2/0.jpg']);
    expect($dto->folder)->toBe('platforms/instagram/1700000000');
    expect($dto->source)->toBe('google-business');
    // toArray carries the internal keys back (the honest schema).
    expect($dto->toArray()['_folder'])->toBe('platforms/instagram/1700000000');
    expect($dto->toArray()['source'])->toBe('google-business');
});

it('is lenient — missing keys become canonical defaults', function () {
    $dto = InstagramPayload::fromArray(['username' => 'acme']);

    expect($dto->images)->toBe([]);
    expect($dto->videoUrl)->toBeNull();
    expect($dto->imagesDropped)->toBe(0);
    expect($dto->folder)->toBeNull();
    expect($dto->source)->toBeNull();
});

it('tolerates a non-array payload (pending placeholder / garbage)', function () {
    expect(InstagramPayload::fromArray(null)->username)->toBeNull();
    expect(InstagramPayload::fromArray('nope')->folder)->toBeNull();
});

it('resource output is identical whether fed the raw payload or the DTO round-trip, and never leaks _folder/source', function () {
    $raw = [
        'username' => 'acme', 'fullName' => 'Acme Co', 'profilePicUrl' => 'https://r2/p.jpg',
        'businessCategory' => 'Cafe', 'followersCount' => 1200, 'postsCount' => 88,
        'mode' => 'automatic', 'images' => ['https://r2/0.jpg'],
        'videoUrl' => 'https://r2/reel.mp4', 'videoPoster' => 'https://r2/cover.jpg',
        '_folder' => 'platforms/instagram/1700000000', 'source' => 'google-business',
    ];

    $fromRaw = (new InstagramConnectionResource($raw))->resolve();
    $fromDto = (new InstagramConnectionResource(InstagramPayload::fromArray($raw)->toArray()))->resolve();

    expect($fromDto)->toBe($fromRaw);
    expect($fromDto)->not->toHaveKey('_folder');
    expect($fromDto)->not->toHaveKey('source');
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Unit/Platforms/Payloads/InstagramPayloadTest.php`
Expected: FAIL with `Class "App\Services\Platforms\Payloads\InstagramPayload" not found`.

- [ ] **Step 3: Write `InstagramPayload`**

Create `app/Services/Platforms/Payloads/InstagramPayload.php`:

```php
<?php

namespace App\Services\Platforms\Payloads;

// Typed boundary for the bespoke Instagram connection payload. Instagram is the
// async-scrape special: InstagramConnectJob mirrors the latest post + reel to R2
// and writes this shape; InstagramConnectionResource emits a FIXED key subset.
// This DTO is NORMALIZING (fixed props), which is safe because the resource
// allowlists its keys (resource-output equivalence). It additionally carries the
// two INTERNAL fields the resource omits — `_folder` (the R2 prefix the disconnect
// observer reclaims; spec §8 names it explicitly) and `source` (the google-business
// origin tag InstagramConnectJob preserves across a re-scrape) — so the DTO is the
// honest, complete schema while responses stay byte-identical. The job's scrape
// WRITE stays literal (live-scrape writes are not migrated); this DTO is the READ
// boundary (controller status/selection, the job's source read, the observer's
// _folder reads).
final readonly class InstagramPayload
{
    public function __construct(
        public ?string $username,
        public ?string $fullName,
        public ?string $profilePicUrl,
        public ?string $businessCategory,
        public int|string|null $followersCount,
        public int|string|null $postsCount,
        public ?string $mode,
        public array $images,
        public ?string $videoUrl,
        public ?string $videoPoster,
        public int $imagesDropped,
        public ?string $source,
        public ?string $folder,
    ) {}

    public static function fromArray(mixed $payload): self
    {
        $p = is_array($payload) ? $payload : [];

        return new self(
            username: self::stringOrNull($p['username'] ?? null),
            fullName: self::stringOrNull($p['fullName'] ?? null),
            profilePicUrl: self::stringOrNull($p['profilePicUrl'] ?? null),
            businessCategory: self::stringOrNull($p['businessCategory'] ?? null),
            followersCount: self::intStringOrNull($p['followersCount'] ?? null),
            postsCount: self::intStringOrNull($p['postsCount'] ?? null),
            mode: self::stringOrNull($p['mode'] ?? null),
            images: is_array($p['images'] ?? null) ? $p['images'] : [],
            videoUrl: self::stringOrNull($p['videoUrl'] ?? null),
            videoPoster: self::stringOrNull($p['videoPoster'] ?? null),
            imagesDropped: is_int($p['imagesDropped'] ?? null) ? $p['imagesDropped'] : 0,
            source: self::stringOrNull($p['source'] ?? null),
            folder: self::stringOrNull($p['_folder'] ?? null),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'fullName' => $this->fullName,
            'profilePicUrl' => $this->profilePicUrl,
            'businessCategory' => $this->businessCategory,
            'followersCount' => $this->followersCount,
            'postsCount' => $this->postsCount,
            'mode' => $this->mode,
            'images' => $this->images,
            'videoUrl' => $this->videoUrl,
            'videoPoster' => $this->videoPoster,
            'imagesDropped' => $this->imagesDropped,
            'source' => $this->source,
            '_folder' => $this->folder,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function intStringOrNull(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }
}
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `php artisan test tests/Unit/Platforms/Payloads/InstagramPayloadTest.php`
Expected: PASS — all four cases green (hydration, lenient defaults, non-array tolerance, resource-output equivalence with `_folder`/`source` stripped).

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Payloads/InstagramPayload.php tests/Unit/Platforms/Payloads/InstagramPayloadTest.php
git commit -m "feat(integrations): InstagramPayload typed DTO (carries internal _folder/source)"
```

---

## Task 2: Migrate the Instagram read path onto `InstagramPayload`

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/InstagramController.php` (`connectStatus()` line ~108, `selection()` line ~130)
- Modify: `app/Jobs/Platforms/InstagramConnectJob.php` (the `source` read, line ~164)
- Modify: `app/Observers/Core/IntegrationConnectionObserver.php` (`_folder` reads, lines ~56–57, ~76)
- Modify: `app/Providers/PlatformRegistryServiceProvider.php` (instagram descriptor)
- Test: `tests/Feature/Platforms/InstagramAsyncConnectTest.php` + `tests/Feature/Platforms/InstagramR2CleanupTest.php` (existing parity guards — no change needed; add one explicit `_folder`-absent assertion)

**Interfaces:**
- Consumes: `InstagramPayload::fromArray()/toArray()`, `->folder`, `->source` (Task 1).

> **What stays identical:** the async contract is unchanged — `connect()` still writes the `payload => []` pending placeholder, dispatches `InstagramConnectJob`, and returns **202** `{status:'pending', statusUrl}`. `connectStatus()` still maps `last_refresh_status` → `ready`/`pending`/`failed`. The ONLY change is that where the controller passed the raw `$connection->payload` array to `InstagramConnectionResource`, it now passes `InstagramPayload::fromArray($payload)->toArray()` — byte-identical output (resource-output equivalence). The observer's R2 cleanup behavior (dispatch `DeleteMirroredMediaJob` only when `_folder` exists and, on update, only when it changed) is unchanged — only the `_folder` *read* becomes typed.

- [ ] **Step 1: Confirm the existing async + cleanup tests pass against current code**

Run: `php artisan test tests/Feature/Platforms/InstagramAsyncConnectTest.php tests/Feature/Platforms/InstagramR2CleanupTest.php`
Expected: PASS — these characterize the 202→poll contract and the `_folder` R2 cleanup. They are the parity net for this task.

- [ ] **Step 2: Add an explicit "response never contains `_folder`" guard to the cleanup test**

In `tests/Feature/Platforms/InstagramR2CleanupTest.php`, append (after the existing "stores the R2 _folder in the payload" test) — this hard-codes the spec invariant at the HTTP layer so the migration can't regress it:

```php
it('never exposes the internal _folder on the instagram status or selection endpoints', function () {
    $user = r2CleanupUser('igfolder');
    makeIgConnection($user, [
        'username' => 'acme', 'images' => ['https://r2/0.jpg'], 'mode' => 'automatic',
        '_folder' => 'platforms/instagram/123', 'source' => 'google-business',
    ])->update(['is_active' => true, 'last_refresh_status' => 'ok']);

    $status = actingAsUser($user)->getJson('/api/integrations/instagram/connect/status')->assertOk()->json();
    $selection = actingAsUser($user)->getJson('/api/integrations/instagram/selection')->assertOk()->json();

    expect($status['data']['connection'] ?? $status['connection'] ?? [])->not->toHaveKey('_folder');
    expect($status['data']['connection'] ?? $status['connection'] ?? [])->not->toHaveKey('source');
    expect(data_get($selection, 'data.selection') ?? data_get($selection, 'selection') ?? [])->not->toHaveKey('_folder');
});
```

> **Note:** match the assertion's response-envelope path to whatever `ApiController::success()` produces (`data.*` vs top-level) — confirm against an existing `InstagramAsyncConnectTest` assertion and adjust the `data_get` paths if needed. The point is: neither `_folder` nor `source` appears in either response.

- [ ] **Step 3: Run it to confirm it passes against current (pre-migration) code**

Run: `php artisan test tests/Feature/Platforms/InstagramR2CleanupTest.php`
Expected: PASS — current `InstagramConnectionResource` already omits `_folder`/`source`. (Characterizes the invariant before the refactor.)

- [ ] **Step 4: Migrate `InstagramController`'s two read sites**

In `app/Http/Controllers/Api/Platforms/InstagramController.php`, add the import (Pint will order it):

```php
use App\Services\Platforms\Payloads\InstagramPayload;
```

In `connectStatus()`, replace the `ready` branch's resource construction:

```php
        if ($status === 'ok') {
            return $this->success([
                'status' => 'ready',
                'connection' => $connection->payload
                    ? (new InstagramConnectionResource(InstagramPayload::fromArray($connection->payload)->toArray()))->resolve()
                    : null,
            ]);
        }
```

In `selection()`, replace the resource construction:

```php
    public function selection(Request $request): JsonResponse
    {
        $payload = $this->readConnection($this->currentUser($request));

        return $this->success(['selection' => $payload
            ? (new InstagramConnectionResource(InstagramPayload::fromArray($payload)->toArray()))->resolve()
            : null]);
    }
```

- [ ] **Step 5: Migrate `InstagramConnectJob`'s `source` read**

In `app/Jobs/Platforms/InstagramConnectJob.php`, add the import:

```php
use App\Services\Platforms\Payloads\InstagramPayload;
```

Replace the `source` read (currently `if (($source = data_get($connection->payload, 'source')) !== null) {`):

```php
        // Preserve the google-business origin tag across a re-scrape (it drives the
        // /synced "Change to" flow). Read it typed; the scrape WRITE below stays literal.
        if (($source = InstagramPayload::fromArray($connection->payload)->source) !== null) {
```

(Leave the line(s) that write `$selection['source'] = $source;` and the literal `$selection = [ … '_folder' => $folder ]` write **exactly as-is** — that is the live-scrape WRITE construction, deliberately not migrated.)

- [ ] **Step 6: Migrate the observer's `_folder` reads**

In `app/Observers/Core/IntegrationConnectionObserver.php`, add the import:

```php
use App\Services\Platforms\Payloads\InstagramPayload;
```

Replace the `updated()` body's old/new read:

```php
    public function updated(IntegrationConnection $connection): void
    {
        if ($connection->platform !== 'instagram') {
            return;
        }

        $old = InstagramPayload::fromArray($connection->getOriginal('payload'))->folder;
        $new = InstagramPayload::fromArray($connection->payload)->folder;
        if ($old && $new && $old !== $new) {
            DeleteMirroredMediaJob::dispatch($old);
        }
    }
```

Replace the `cleanupMirroredMedia()` read:

```php
    private function cleanupMirroredMedia(IntegrationConnection $connection): void
    {
        $folder = InstagramPayload::fromArray($connection->payload)->folder;
        if ($connection->platform === 'instagram' && $folder) {
            DeleteMirroredMediaJob::dispatch($folder);
        }
    }
```

> `InstagramPayload::fromArray()` tolerates `null` (a fresh model's `getOriginal('payload')` or a non-array), so this preserves the `data_get`-on-null tolerance exactly. The pending→ready (`null → folder`) and ready→pending (`folder → null`) no-op behavior is unchanged because the `$old && $new` guard still requires both sides present.

- [ ] **Step 7: Attach the descriptor's payload class (registry metadata)**

In `app/Providers/PlatformRegistryServiceProvider.php`, find:

```php
$r->register(PD::make('instagram')->label('Instagram')->category(Cat::Social)->resource(InstagramConnectionResource::class)); // refresh = paid Apify, not in cron
```

Change it to (Pint will order the new import):

```php
$r->register(PD::make('instagram')->label('Instagram')->category(Cat::Social)->resource(InstagramConnectionResource::class)->payload(InstagramPayload::class)); // refresh = paid Apify, not in cron
```

Add at the top: `use App\Services\Platforms\Payloads\InstagramPayload;`

> Metadata only — `InstagramController` is bespoke (async connect/poll), so it references `InstagramPayload` directly; it does not resolve via the generic controller. The `payload()` makes the registry-coverage assertion (Task 12) see `instagram` as DTO-backed.

- [ ] **Step 8: Run the Instagram suite + golden master**

Run:
```bash
php artisan test tests/Feature/Platforms/InstagramAsyncConnectTest.php \
  tests/Feature/Platforms/InstagramR2CleanupTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php \
  tests/Unit/Platforms/Payloads/InstagramPayloadTest.php
```
Expected: PASS — the 202→poll contract, the R2 `_folder` dispatch-on-disconnect / dispatch-old-on-change / no-op-on-same, the new `_folder`-absent HTTP guard, and the golden master (count still `52`) all green.

- [ ] **Step 9: Commit**

```bash
php artisan pint --dirty
git add app/Http/Controllers/Api/Platforms/InstagramController.php app/Jobs/Platforms/InstagramConnectJob.php app/Observers/Core/IntegrationConnectionObserver.php app/Providers/PlatformRegistryServiceProvider.php tests/Feature/Platforms/InstagramR2CleanupTest.php
git commit -m "refactor(integrations): migrate Instagram read path + _folder cleanup onto InstagramPayload"
```

---

# Part B — Google Business (auto-sync + variable-key card)

## Task 3: `GoogleBusinessPayload` DTO

**Files:**
- Create: `app/Services/Platforms/Payloads/GoogleBusinessPayload.php`
- Test: `tests/Unit/Platforms/Payloads/GoogleBusinessPayloadTest.php`

**Interfaces:**
- Produces: `final readonly class GoogleBusinessPayload` — constructor `(array $raw)`; `static fromArray(mixed $payload): self` (is-array guard → `new self([])` for non-arrays); accessors `name(): ?string`, `placeId(): ?string`, `apifyStatus(): ?string`, `syncFindings(): array` (verbatim list, or `[]`); `toArray(): array` returns `$raw` **verbatim**.
- Consumed by: Task 4 (`GoogleBusinessController`, `GoogleBusinessEnrichJob`, `ReservationsController`, `OpenTableController`).

> **Why verbatim (like `FreshaSelection`/`ShopPayload`):** `GoogleBusinessConnectionResource::toArray()` does `...array_intersect_key($this->resource, array_flip(self::ENRICHMENT_KEYS))` — it emits **only the enrichment keys actually present** in the stored map (so a never-enriched legacy link-parse selection keeps its original 5-key shape). A *normalizing* DTO would inject canonical-null keys (`placeId => null`, `rating => null`, …) and the `array_intersect_key` would then emit them, breaking the variable-key contract. So this DTO preserves the stored map byte-for-byte and exposes typed read accessors over it — the typed boundary with zero serialization drift.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Platforms/Payloads/GoogleBusinessPayloadTest.php`:

```php
<?php

use App\Http\Resources\Platforms\GoogleBusinessConnectionResource;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('preserves the stored map verbatim (lossless toArray)', function () {
    $raw = [
        'url' => 'https://maps.google/x', 'name' => 'Fade Lab', 'lat' => -37.8, 'lng' => 144.9,
        'placeId' => 'ChIJ', 'rating' => 4.7, 'apifyStatus' => 'ok',
        'syncFindings' => [['platform' => 'opentable', 'outcome' => 'seeded']],
    ];

    expect(GoogleBusinessPayload::fromArray($raw)->toArray())->toBe($raw);
});

it('exposes typed accessors and tolerates absence', function () {
    $dto = GoogleBusinessPayload::fromArray(['name' => 'Fade Lab', 'placeId' => 'ChIJ', 'apifyStatus' => 'pending']);
    expect($dto->name())->toBe('Fade Lab');
    expect($dto->placeId())->toBe('ChIJ');
    expect($dto->apifyStatus())->toBe('pending');
    expect($dto->syncFindings())->toBe([]);

    $empty = GoogleBusinessPayload::fromArray(null);
    expect($empty->name())->toBeNull();
    expect($empty->toArray())->toBe([]);
});

it('syncFindings returns the verbatim findings list or [] for garbage', function () {
    $findings = [['platform' => 'facebook', 'outcome' => 'conflict']];
    expect(GoogleBusinessPayload::fromArray(['syncFindings' => $findings])->syncFindings())->toBe($findings);
    expect(GoogleBusinessPayload::fromArray(['syncFindings' => 'nope'])->syncFindings())->toBe([]);
});

it('resource output is identical whether fed the raw map or the DTO round-trip (variable keys preserved)', function () {
    // A legacy link-parse selection: only the 5 base keys, NO enrichment keys.
    $legacy = ['url' => 'https://maps.google/x', 'name' => 'Fade Lab', 'address' => '1 St', 'lat' => -37.8, 'lng' => 144.9];
    expect((new GoogleBusinessConnectionResource(GoogleBusinessPayload::fromArray($legacy)->toArray()))->resolve())
        ->toBe((new GoogleBusinessConnectionResource($legacy))->resolve());

    // An enriched selection: enrichment keys present must survive.
    $enriched = [...$legacy, 'placeId' => 'ChIJ', 'rating' => 4.7, 'apifyStatus' => 'ok'];
    $out = (new GoogleBusinessConnectionResource(GoogleBusinessPayload::fromArray($enriched)->toArray()))->resolve();
    expect($out)->toBe((new GoogleBusinessConnectionResource($enriched))->resolve());
    expect($out)->toHaveKey('rating');
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Unit/Platforms/Payloads/GoogleBusinessPayloadTest.php`
Expected: FAIL with `Class "App\Services\Platforms\Payloads\GoogleBusinessPayload" not found`.

- [ ] **Step 3: Write `GoogleBusinessPayload`**

Create `app/Services/Platforms/Payloads/GoogleBusinessPayload.php`:

```php
<?php

namespace App\Services\Platforms\Payloads;

// Typed boundary for the google-business connection payload. VERBATIM-preserving
// (raw map + typed accessors, toArray() returns it unchanged), like FreshaSelection
// / ShopPayload — because GoogleBusinessConnectionResource emits a VARIABLE key set
// via array_intersect_key over the stored keys (a never-enriched link-parse
// selection keeps its 5-key shape; an Apify-enriched one carries rating/hours/…).
// A normalizing DTO would inject canonical-null enrichment keys and the resource
// would then leak them. Accessors cover the fields the read paths actually read:
// name() + placeId() (the enrich job's reconnect guard + name adoption), apifyStatus(),
// and syncFindings() (the /synced + /synced/apply "Change to" flow). The enrich job's
// seeding WRITE-BACK and GoogleBusinessAutoSync are untouched.
final readonly class GoogleBusinessPayload
{
    /** @param array<string,mixed> $raw the stored selection map, preserved verbatim */
    public function __construct(public array $raw) {}

    public static function fromArray(mixed $payload): self
    {
        return new self(is_array($payload) ? $payload : []);
    }

    public function name(): ?string
    {
        return is_string($this->raw['name'] ?? null) ? $this->raw['name'] : null;
    }

    public function placeId(): ?string
    {
        return is_string($this->raw['placeId'] ?? null) ? $this->raw['placeId'] : null;
    }

    public function apifyStatus(): ?string
    {
        return is_string($this->raw['apifyStatus'] ?? null) ? $this->raw['apifyStatus'] : null;
    }

    /** @return list<mixed> the recorded auto-sync findings, verbatim, or [] */
    public function syncFindings(): array
    {
        return is_array($this->raw['syncFindings'] ?? null) ? array_values($this->raw['syncFindings']) : [];
    }

    /** @return array<string,mixed> the stored map, byte-for-byte */
    public function toArray(): array
    {
        return $this->raw;
    }
}
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `php artisan test tests/Unit/Platforms/Payloads/GoogleBusinessPayloadTest.php`
Expected: PASS — verbatim round-trip, accessors, and variable-key resource-output equivalence (legacy 5-key shape preserved; enrichment keys preserved).

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Payloads/GoogleBusinessPayload.php tests/Unit/Platforms/Payloads/GoogleBusinessPayloadTest.php
git commit -m "feat(integrations): GoogleBusinessPayload verbatim-preserving DTO"
```

---

## Task 4: Migrate the Google Business read path onto `GoogleBusinessPayload`

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/GoogleBusinessController.php` (`synced()` line ~122, `applySync()` lines ~143–144, 161)
- Modify: `app/Jobs/Platforms/GoogleBusinessEnrichJob.php` (lines ~86–87, ~136, ~153)
- Modify: `app/Http/Controllers/Api/Platforms/ReservationsController.php` (`suggestion()` line ~85)
- Modify: `app/Http/Controllers/Api/Platforms/OpenTableController.php` (the parallel suggestion harvest, line ~69)
- Modify: `app/Providers/PlatformRegistryServiceProvider.php` (google-business descriptor)
- Test: `tests/Feature/Platforms/GoogleBusinessApifyTest.php` (existing parity guard — no change needed)

**Interfaces:**
- Consumes: `GoogleBusinessPayload::fromArray()/toArray()`, `->name()`, `->placeId()`, `->syncFindings()` (Task 3).

> **What stays identical — the cross-platform seeding:** `GoogleBusinessAutoSync::seed()` (which creates opentable/resdiary/nowbookit/online-ordering/instagram/socials/booking rows from one Apify enrichment) and `applyFinding()` are **NOT touched** in this task except for the single ordering store-dedup read at line 448 (migrated in Task 9 with the other `CardPayload` sites). The enrich job still calls `seed()` with the same arguments; only how it *reads* `name`/`placeId`/payload from the google-business row becomes typed. `GoogleBusinessApifyTest`'s 19 cases (seeding opentable/instagram/facebook/booking, only-if-empty, conflict + Change-to, business-info-only payload, reconnect guard) are the parity net.

- [ ] **Step 1: Confirm the GB suite passes against current code**

Run: `php artisan test tests/Feature/Platforms/GoogleBusinessApifyTest.php tests/Feature/Platforms/ReservationProvidersTest.php`
Expected: PASS — these characterize the auto-sync seeding, the `/synced` + Change-to flow, and the OpenTable suggestion harvest.

- [ ] **Step 2: Migrate `GoogleBusinessController`'s syncFindings reads**

In `app/Http/Controllers/Api/Platforms/GoogleBusinessController.php`, add the import:

```php
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
```

In `synced()`, replace the findings read:

```php
        $gb = $user->integrationConnections()->where('platform', 'google-business')->first();
        $findings = GoogleBusinessPayload::fromArray($gb?->payload)->syncFindings();
```

In `applySync()`, replace the payload + findings reads (keep the write-back byte-identical — `toArray()` is verbatim):

```php
        $gb = $user->integrationConnections()->where('platform', 'google-business')->first();
        $gbp = GoogleBusinessPayload::fromArray($gb?->payload);
        $payload = $gbp->toArray();
        $findings = $gbp->syncFindings();
```

The later `$gb->forceFill(['payload' => [...$payload, 'syncFindings' => $findings]])->saveQuietly();` is unchanged — `$payload` is now the verbatim map, so the spread is byte-identical.

- [ ] **Step 3: Migrate `GoogleBusinessEnrichJob`'s reads**

In `app/Jobs/Platforms/GoogleBusinessEnrichJob.php`, add the import:

```php
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
```

In `handle()`, replace the `$autoSync->seed(...)` call's `name` + payload arguments. The current call (lines ~83–88) is:

```php
        $findings = $autoSync->seed(
            $this->userId,
            $enrichment,
            data_get($connection->payload, 'name'),
            is_array($connection->payload) ? $connection->payload : [],
        );
```

Replace it with (hydrate once, read typed — the seeding call signature `seed(string $userId, array $enrichment, ?string $businessName, ?array $gbPayload = null)` and the `$enrichment` argument are unchanged):

```php
        $gbp = GoogleBusinessPayload::fromArray($connection->payload);
        $findings = $autoSync->seed(
            $this->userId,
            $enrichment,
            $gbp->name(),
            $gbp->toArray(),
        );
```

In `connection()`, replace the reconnect guard (line ~136):

```php
        // before: return data_get($connection->payload, 'placeId') === $this->placeId ? $connection : null;
        return GoogleBusinessPayload::fromArray($connection->payload)->placeId() === $this->placeId ? $connection : null;
```

In `payloadOf()`, replace the helper body (line ~153):

```php
    /** @return array<string,mixed> */
    private function payloadOf(IntegrationConnection $connection): array
    {
        // before: return is_array($connection->payload) ? $connection->payload : [];
        return GoogleBusinessPayload::fromArray($connection->payload)->toArray();
    }
```

> `payloadOf()` (used by the `forceFill` write-backs at lines ~96 and ~143) returns the verbatim map, so those write-backs stay byte-identical. The `mark()`/`handle()` write-back construction itself (the `Arr::except(...)` + `apifyStatus`/`syncFindings` spread) is unchanged — it builds a fresh payload, not a `data_get` read.

- [ ] **Step 4: Migrate the OpenTable suggestion harvest (Reservations + OpenTable controllers)**

In `app/Http/Controllers/Api/Platforms/ReservationsController.php`, add the import and replace the `suggestion()` harvest:

```php
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
```
```php
        $gb = $user->integrationConnections()->where('platform', 'google-business')->first();
        $suggestion = $gb
            ? $this->openTable->suggestionFromGoogleBusiness(GoogleBusinessPayload::fromArray($gb->payload)->toArray())
            : null;
```

Apply the parallel change to the harvest in `app/Http/Controllers/Api/Platforms/OpenTableController.php` (`suggestion()`, line ~69) — note this controller injects the service as **`$this->service`** (not `$this->openTable`) and needs its own `use App\Services\Platforms\Payloads\GoogleBusinessPayload;` import:

```php
        $suggestion = $gb
            ? $this->service->suggestionFromGoogleBusiness(GoogleBusinessPayload::fromArray($gb->payload)->toArray())
            : null;
```

`GoogleBusinessPayload::fromArray()` tolerates a non-array payload (returns `[]`), so dropping the explicit `is_array($gb->payload)` guard is safe — `suggestionFromGoogleBusiness([])` yields `null`, the same as before.

- [ ] **Step 5: Attach the descriptor's payload class + drop the "Plan 5" TODO**

In `app/Providers/PlatformRegistryServiceProvider.php`, find:

```php
$r->register(PD::make('google-business')->label('Google Business')->category(Cat::Business)->resource(GoogleBusinessConnectionResource::class)->refreshable());
// Attach fetch strategy only (Plan 3b / Task 9). No ->payload(FeedPayload::class) —
// google-business emits a variable key set; payload DTO + read-path migration are Plan 5.
$r->get('google-business')->fetch(new GoogleBusinessFetch(
    $this->app->make(GoogleBusinessService::class),
));
```

Change the registration line to attach the payload, and update the stale comment:

```php
$r->register(PD::make('google-business')->label('Google Business')->category(Cat::Business)->resource(GoogleBusinessConnectionResource::class)->refreshable()->payload(GoogleBusinessPayload::class));
// Attach fetch strategy (Plan 3b). GoogleBusinessPayload is verbatim-preserving
// (variable key set via array_intersect_key) — read paths migrated in Plan 5.
$r->get('google-business')->fetch(new GoogleBusinessFetch(
    $this->app->make(GoogleBusinessService::class),
));
```

Add at the top: `use App\Services\Platforms\Payloads\GoogleBusinessPayload;`

> Metadata only — `GoogleBusinessController` extends `SingleSelectionPlatformController`, whose `selection()` feeds the raw payload straight to the resource (it does NOT consult `payloadClass()`). So attaching the DTO does not alter the `/google-business/selection` read; the verbatim DTO would be byte-identical anyway. The `payload()` makes the registry-coverage assertion (Task 12) see `google-business` as DTO-backed.

- [ ] **Step 6: Run the GB + reservations suites + golden master**

Run:
```bash
php artisan test tests/Feature/Platforms/GoogleBusinessApifyTest.php \
  tests/Feature/Platforms/ReservationProvidersTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php \
  tests/Unit/Platforms/Payloads/GoogleBusinessPayloadTest.php
```
Expected: PASS — all auto-sync seeding (opentable/online-ordering/instagram/facebook/booking), only-if-empty, conflict + Change-to, business-info-only payload, reconnect guard, the OpenTable suggestion harvest, and the golden master (count `52`) green.

- [ ] **Step 7: Commit**

```bash
php artisan pint --dirty
git add app/Http/Controllers/Api/Platforms/GoogleBusinessController.php app/Jobs/Platforms/GoogleBusinessEnrichJob.php app/Http/Controllers/Api/Platforms/ReservationsController.php app/Http/Controllers/Api/Platforms/OpenTableController.php app/Providers/PlatformRegistryServiceProvider.php
git commit -m "refactor(integrations): migrate Google Business read path onto GoogleBusinessPayload"
```

---

# Part C — Events (smart-detect facade + custom)

## Task 5: `EventsAccountPayload` + `StandaloneEventPayload` DTOs

**Files:**
- Create: `app/Services/Platforms/Payloads/EventsAccountPayload.php`
- Create: `app/Services/Platforms/Payloads/StandaloneEventPayload.php`
- Test: `tests/Unit/Platforms/Payloads/EventsPayloadDtoTest.php`

**Interfaces:**
- Produces:
  - `final readonly class EventsAccountPayload` — `(array $raw)`; `static fromArray(mixed): self`; accessors `url(): ?string`, `organiser(): ?string`, `upcoming(): array` (verbatim event list, or `[]`), `hiddenEventIds(): array` (verbatim, or `[]`); `toArray(): array` verbatim.
  - `final readonly class StandaloneEventPayload` — `(array $raw)`; `static fromArray(mixed): self`; accessors `id(): ?string`, `event(): array` (the raw payload **minus** the internal `kind` key); `toArray(): array` verbatim.
- Consumed by: Task 6 (`EventsPlatformController`, `EventsCatalog`).

> **Why verbatim:** events ACCOUNT rows store `upcoming` — a list of scraped event objects spread (`...$ev`) into the dashboard + public wire and passed through the public allowlist verbatim. A normalizing DTO would risk injecting canonical-null keys into the stored blob on any write-back. These DTOs preserve the stored array byte-for-byte and expose typed accessors (the `FreshaSelection` idiom). The existing static `App\Services\Platforms\EventsPayload` BUILDER (`accountPayload()`, `standalonePayload()`, `withIds()`, `id()`) is the WRITE side and stays untouched; these DTOs are the READ side.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Platforms/Payloads/EventsPayloadDtoTest.php`:

```php
<?php

use App\Services\Platforms\Payloads\EventsAccountPayload;
use App\Services\Platforms\Payloads\StandaloneEventPayload;

it('EventsAccountPayload exposes typed accessors over the account blob, verbatim toArray', function () {
    $raw = [
        'url' => 'https://eventbrite.com/o/acme',
        'organiser' => 'Acme Events',
        'next' => ['id' => 'e1'],
        'upcoming' => [['id' => 'e1', 'name' => 'Show'], ['id' => 'e2', 'name' => 'Gig']],
        'hiddenEventIds' => ['e3'],
    ];
    $dto = EventsAccountPayload::fromArray($raw);

    expect($dto->url())->toBe('https://eventbrite.com/o/acme');
    expect($dto->organiser())->toBe('Acme Events');
    expect($dto->upcoming())->toBe([['id' => 'e1', 'name' => 'Show'], ['id' => 'e2', 'name' => 'Gig']]);
    expect($dto->hiddenEventIds())->toBe(['e3']);
    expect($dto->toArray())->toBe($raw);            // lossless
});

it('EventsAccountPayload is lenient — missing keys become null / []', function () {
    $dto = EventsAccountPayload::fromArray(['url' => 'https://eventbrite.com/o/acme']);
    expect($dto->organiser())->toBeNull();
    expect($dto->upcoming())->toBe([]);
    expect($dto->hiddenEventIds())->toBe([]);
    // tolerant of garbage
    expect(EventsAccountPayload::fromArray(null)->upcoming())->toBe([]);
});

it('StandaloneEventPayload exposes id + the event minus the internal kind key', function () {
    $raw = ['kind' => 'event', 'id' => 'abc123', 'name' => 'Gig', 'startDate' => '2026-07-01T19:00:00+10:00'];
    $dto = StandaloneEventPayload::fromArray($raw);

    expect($dto->id())->toBe('abc123');
    expect($dto->event())->toBe(['id' => 'abc123', 'name' => 'Gig', 'startDate' => '2026-07-01T19:00:00+10:00']);
    expect($dto->event())->not->toHaveKey('kind');
    expect($dto->toArray())->toBe($raw);            // lossless (kind retained in storage)
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Unit/Platforms/Payloads/EventsPayloadDtoTest.php`
Expected: FAIL with `Class "App\Services\Platforms\Payloads\EventsAccountPayload" not found`.

- [ ] **Step 3: Write `EventsAccountPayload`**

Create `app/Services/Platforms/Payloads/EventsAccountPayload.php`:

```php
<?php

namespace App\Services\Platforms\Payloads;

// Typed READ boundary for an events ACCOUNT row (eventbrite / humanitix organiser
// pages). Stored shape: {url, organiser, next?, upcoming[], hiddenEventIds[]}.
// VERBATIM-preserving (raw array + typed accessors) because `upcoming` is a list of
// scraped event objects spread into the dashboard + public wire and passed through
// the public allowlist unchanged — a normalizing DTO would risk injecting null keys
// into the stored blob on write-back. The WRITE side is the static EventsPayload
// builder (accountPayload/withIds), unchanged; this is the read side.
final readonly class EventsAccountPayload
{
    /** @param array<string,mixed> $raw the stored account blob, preserved verbatim */
    public function __construct(public array $raw) {}

    public static function fromArray(mixed $payload): self
    {
        return new self(is_array($payload) ? $payload : []);
    }

    public function url(): ?string
    {
        return is_string($this->raw['url'] ?? null) ? $this->raw['url'] : null;
    }

    public function organiser(): ?string
    {
        return is_string($this->raw['organiser'] ?? null) ? $this->raw['organiser'] : null;
    }

    /** @return list<mixed> the upcoming-events list, verbatim, or [] */
    public function upcoming(): array
    {
        return is_array($this->raw['upcoming'] ?? null) ? $this->raw['upcoming'] : [];
    }

    /** @return list<mixed> the curated hidden-event id list, verbatim, or [] */
    public function hiddenEventIds(): array
    {
        return is_array($this->raw['hiddenEventIds'] ?? null) ? $this->raw['hiddenEventIds'] : [];
    }

    /** @return array<string,mixed> the stored blob, byte-for-byte */
    public function toArray(): array
    {
        return $this->raw;
    }
}
```

- [ ] **Step 4: Write `StandaloneEventPayload`**

Create `app/Services/Platforms/Payloads/StandaloneEventPayload.php`:

```php
<?php

namespace App\Services\Platforms\Payloads;

// Typed READ boundary for a STANDALONE event row ('event-<id>', under eventbrite /
// humanitix / events-custom). Stored shape: {kind:'event', id, name, …event fields}.
// VERBATIM-preserving; event() returns the payload MINUS the internal `kind`
// discriminator (which the readers strip before spreading the event into the wire).
final readonly class StandaloneEventPayload
{
    /** @param array<string,mixed> $raw the stored standalone-event blob, preserved verbatim */
    public function __construct(public array $raw) {}

    public static function fromArray(mixed $payload): self
    {
        return new self(is_array($payload) ? $payload : []);
    }

    public function id(): ?string
    {
        return isset($this->raw['id']) && is_string($this->raw['id']) ? $this->raw['id'] : null;
    }

    /** @return array<string,mixed> the event object, internal `kind` removed */
    public function event(): array
    {
        $event = $this->raw;
        unset($event['kind']);

        return $event;
    }

    /** @return array<string,mixed> the stored blob, byte-for-byte (kind retained) */
    public function toArray(): array
    {
        return $this->raw;
    }
}
```

- [ ] **Step 5: Run the test to confirm it passes**

Run: `php artisan test tests/Unit/Platforms/Payloads/EventsPayloadDtoTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Payloads/EventsAccountPayload.php app/Services/Platforms/Payloads/StandaloneEventPayload.php tests/Unit/Platforms/Payloads/EventsPayloadDtoTest.php
git commit -m "feat(integrations): EventsAccountPayload + StandaloneEventPayload DTOs"
```

---

## Task 6: Migrate the events read paths onto the events DTOs

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/EventsPlatformController.php` (lines ~73, ~148–159, ~222–228, ~245–257)
- Modify: `app/Services/Platforms/EventsCatalog.php` (lines ~101–124, ~128–142, ~186)
- Modify: `app/Providers/PlatformRegistryServiceProvider.php` (eventbrite / humanitix / events-custom descriptors)
- Test: `tests/Feature/Platforms/EventsCatalogTest.php` (existing parity guard — no change needed)

**Interfaces:**
- Consumes: `EventsAccountPayload::fromArray()`, `->url()/->organiser()/->upcoming()/->hiddenEventIds()`; `StandaloneEventPayload::fromArray()`, `->id()/->event()` (Task 5).

> **What stays identical:** every output shape (`accounts[]`, the merged `events[]`, the legacy top-level mirror, the `removePath`s), the per-event hide flow, and `dropElapsed`/`sortEvents` ordering. The static `EventsPayload` builder calls (`accountPayload`, `standalonePayload`, `withIds`) stay — they are the write side. Only the raw `$payload['upcoming']` / `$payload['hiddenEventIds']` / `data_get($existing, 'hiddenEventIds', [])` / `is_array($row->payload)` READS become typed.

- [ ] **Step 1: Confirm the events tests pass against current code**

Run: `php artisan test tests/Feature/Platforms/EventsCatalogTest.php`
Expected: PASS — multi-platform aggregation (eventbrite + humanitix + custom), event-first detect routing, hide/remove, sorted selection.

- [ ] **Step 2: Migrate `EventsPlatformController`**

Add the imports:

```php
use App\Services\Platforms\Payloads\EventsAccountPayload;
use App\Services\Platforms\Payloads\StandaloneEventPayload;
```

`addAccount()` — replace the re-add hidden-ids read (currently `$existing = $this->matchAccountRow($user, 'url', $url)?->payload;` then `$hidden = data_get($existing, 'hiddenEventIds', []);`):

```php
        // Re-adding an already-connected page keeps its per-event hides.
        $existingRow = $this->matchAccountRow($user, 'url', $url);
        $hidden = EventsAccountPayload::fromArray($existingRow?->payload)->hiddenEventIds();

        $payload = EventsPayload::accountPayload($url, $result['organiser'], $result['events'], $hidden);
```

`removeEvent()` — replace the account-loop body (lines ~148–160):

```php
            // Find the account row owning the event and hide it there.
            foreach ($this->accountRows($user) as $row) {
                $account = EventsAccountPayload::fromArray($row->payload);
                $upcoming = EventsPayload::withIds($account->upcoming());
                if (! collect($upcoming)->contains(fn (array $e) => ($e['id'] ?? null) === $id)) {
                    continue;
                }

                $next = EventsPayload::accountPayload(
                    (string) ($account->url() ?? ''),
                    $account->organiser(),
                    $upcoming,
                    [...$account->hiddenEventIds(), $id],
                );
                $this->writeConnection($user, $next, $row->resource_id);

                return $this->success(['selection' => $this->selectionData($user)]);
            }
```

`accountData()` — replace the `upcoming` read (lines ~222–224):

```php
    private function accountData(array $payload): array
    {
        $account = EventsAccountPayload::fromArray($payload);
        $upcoming = $this->dropElapsed(EventsPayload::withIds($account->upcoming()));

        return [
            'url' => $account->url(),
            'organiser' => $account->organiser(),
            'next' => $upcoming[0] ?? null,
            'upcoming' => $upcoming,
        ];
    }
```

`mergedEvents()` — replace both loops (lines ~244–258):

```php
        foreach ($this->accountRows($user) as $row) {
            foreach (EventsPayload::withIds(EventsAccountPayload::fromArray($row->payload)->upcoming()) as $event) {
                $events[] = [...$event, 'source' => 'account', 'accountId' => $row->resource_id];
            }
        }

        foreach ($this->eventRows($user) as $row) {
            $events[] = [...StandaloneEventPayload::fromArray($row->payload)->event(), 'source' => 'custom'];
        }
```

> `accountData(array $payload)` still takes an array (it's called via `accountsListData($user, fn (array $payload) => …)`), so it hydrates the DTO from that array — unchanged signature, typed read inside.

- [ ] **Step 3: Migrate `EventsCatalog`**

Add the imports:

```php
use App\Services\Platforms\Payloads\EventsAccountPayload;
use App\Services\Platforms\Payloads\StandaloneEventPayload;
```

`selection()` — replace the account loop's payload read (lines ~100–124):

```php
            foreach ($this->accountRows($user, $platform) as $row) {
                $account = EventsAccountPayload::fromArray($row->payload);
                $upcoming = $this->dropElapsed(EventsPayload::withIds($account->upcoming()));
                $accounts[] = [
                    'id' => $row->resource_id,
                    'platform' => $platform,
                    'url' => $account->url(),
                    'organiser' => $account->organiser(),
                    'next' => $upcoming[0] ?? null,
                    'upcoming' => $upcoming,
                    'removePath' => "/platforms/{$platform}/accounts/{$row->resource_id}",
                ];
                foreach ($upcoming as $ev) {
                    $events[] = [
                        ...$ev,
                        'platform' => $platform,
                        'source' => 'account',
                        'accountId' => $row->resource_id,
                        'removePath' => "/platforms/{$platform}/events/{$ev['id']}",
                    ];
                }
            }
```

`selection()` — replace the standalone loop (lines ~128–142):

```php
        foreach ([...self::EVENT_PLATFORMS, self::CUSTOM_PLATFORM] as $platform) {
            foreach ($this->eventRows($user, $platform) as $row) {
                $standalone = StandaloneEventPayload::fromArray($row->payload);
                $id = $standalone->id();
                $events[] = [
                    ...$standalone->event(),
                    'platform' => $platform,
                    'source' => $platform === self::CUSTOM_PLATFORM ? 'link' : 'standalone',
                    'removePath' => $platform === self::CUSTOM_PLATFORM
                        ? "/platforms/events/custom/{$id}"
                        : "/platforms/{$platform}/events/{$id}",
                ];
            }
        }
```

`storeAccount()` — replace the re-connect hidden read (line ~186, currently `$hidden = data_get($existing?->payload, 'hiddenEventIds', []);`):

```php
        // Re-connecting the same organiser keeps its per-event hides.
        $hidden = EventsAccountPayload::fromArray($existing?->payload)->hiddenEventIds();
        $payload = EventsPayload::accountPayload(
            $url,
            $result['organiser'] ?? null,
            is_array($result['events'] ?? null) ? $result['events'] : [],
            $hidden,
        );
```

- [ ] **Step 4: Attach the descriptor payload classes**

In `app/Providers/PlatformRegistryServiceProvider.php`, find the events block:

```php
$r->register(PD::make('eventbrite')->label('Eventbrite')->category(Cat::Events)->resource(EventbriteConnectionResource::class)->refreshable());
$r->register(PD::make('humanitix')->label('Humanitix')->category(Cat::Events)->resource(HumanitixConnectionResource::class)->refreshable());
$r->register(PD::make('events-custom')->label('Custom Event')->category(Cat::Events)->resource(TileConnectionResource::class));
```

Change to:

```php
$r->register(PD::make('eventbrite')->label('Eventbrite')->category(Cat::Events)->resource(EventbriteConnectionResource::class)->refreshable()->payload(EventsAccountPayload::class));
$r->register(PD::make('humanitix')->label('Humanitix')->category(Cat::Events)->resource(HumanitixConnectionResource::class)->refreshable()->payload(EventsAccountPayload::class));
$r->register(PD::make('events-custom')->label('Custom Event')->category(Cat::Events)->resource(TileConnectionResource::class)->payload(StandaloneEventPayload::class));
```

Add at the top: `use App\Services\Platforms\Payloads\EventsAccountPayload;` and `use App\Services\Platforms\Payloads\StandaloneEventPayload;`

> `eventbrite`/`humanitix` carry BOTH account rows (the refreshable shape — `EventsAccountPayload`, the descriptor's primary payload + Plan 6 refresher input) AND standalone `event-*` rows (read via `StandaloneEventPayload` directly in the controllers). The descriptor's single `payloadClass()` names the refreshable account shape; the standalone reads use their DTO at the call site. `events-custom` only ever holds standalone cards, so its `payloadClass()` is `StandaloneEventPayload`.

- [ ] **Step 5: Run the events suite + golden master**

Run:
```bash
php artisan test tests/Feature/Platforms/EventsCatalogTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php \
  tests/Unit/Platforms/Payloads/EventsPayloadDtoTest.php
```
Expected: PASS — aggregation, hide/remove, sorted merged selection, golden master count `52`.

- [ ] **Step 6: Commit**

```bash
php artisan pint --dirty
git add app/Http/Controllers/Api/Platforms/EventsPlatformController.php app/Services/Platforms/EventsCatalog.php app/Providers/PlatformRegistryServiceProvider.php
git commit -m "refactor(integrations): migrate events read paths onto EventsAccountPayload/StandaloneEventPayload"
```

---

# Part D — Category cards, online-ordering, custom links, menu source (`LinkCardScraper` family)

## Task 7: `CardPayload` DTO

**Files:**
- Create: `app/Services/Platforms/Payloads/CardPayload.php`
- Test: `tests/Unit/Platforms/Payloads/CardPayloadTest.php`

**Interfaces:**
- Produces: `final readonly class CardPayload` — `(array $raw)`; `static fromArray(mixed): self`; accessors `url(): ?string`, `name(): ?string`, `description(): ?string`, `favicon(): ?string`, `logo(): ?string`, `provider(): ?string`, `source(): ?string`, `kind(): ?string`, `id(): ?string`, `data(): array` (online-ordering's nested `{pickupUrl, deliveryUrl, type, …}`, verbatim, or `[]`); `toArray(): array` verbatim.
- Consumed by: Tasks 8–10 (`BookingController`, `ReservationsController`, `OnlineOrderingController`, `CustomLinksController`, `MenuSource`, `GoogleBusinessAutoSync`).

> **Why one DTO for four call sites:** custom links, the booking/reservations custom fallback, and online-ordering entries are all `LinkCardScraper::snapshotOrMinimal()` branded cards — `{url, name, description, favicon, logo}` plus a few discriminators (`provider`, `source`, `kind`, `id`) and online-ordering's `data` sub-map. Verbatim-preserving (the `FreshaSelection` idiom) because online-ordering writes the consolidated payload back (merge-on-add) and these cards render publicly (custom links / booking / reservations) — no canonical-null injection. `data()` returns the nested map verbatim (the same passthrough treatment `FreshaSelection::services()` gives its scraped sub-blob).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Platforms/Payloads/CardPayloadTest.php`:

```php
<?php

use App\Services\Platforms\Payloads\CardPayload;

it('exposes typed accessors over a branded-card payload, verbatim toArray', function () {
    $raw = [
        'id' => 'order-abc', 'provider' => 'custom', 'source' => 'google-business',
        'url' => 'https://ubereats.com/store/x', 'name' => 'Acme Eats',
        'description' => null, 'favicon' => 'https://f.ico', 'logo' => 'https://l.png',
        'data' => ['pickupUrl' => 'https://u/p', 'deliveryUrl' => 'https://u/d', 'type' => 'pickup'],
    ];
    $dto = CardPayload::fromArray($raw);

    expect($dto->id())->toBe('order-abc');
    expect($dto->provider())->toBe('custom');
    expect($dto->source())->toBe('google-business');
    expect($dto->url())->toBe('https://ubereats.com/store/x');
    expect($dto->name())->toBe('Acme Eats');
    expect($dto->favicon())->toBe('https://f.ico');
    expect($dto->logo())->toBe('https://l.png');
    expect($dto->data())->toBe(['pickupUrl' => 'https://u/p', 'deliveryUrl' => 'https://u/d', 'type' => 'pickup']);
    expect($dto->toArray())->toBe($raw);            // lossless
});

it('reads a custom-links card (kind:link) and is lenient about absent keys', function () {
    $dto = CardPayload::fromArray(['kind' => 'link', 'url' => 'https://acme.test', 'name' => 'Acme']);
    expect($dto->kind())->toBe('link');
    expect($dto->url())->toBe('https://acme.test');
    expect($dto->description())->toBeNull();
    expect($dto->data())->toBe([]);
    // garbage tolerance
    expect(CardPayload::fromArray(null)->url())->toBeNull();
    expect(CardPayload::fromArray('x')->data())->toBe([]);
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Unit/Platforms/Payloads/CardPayloadTest.php`
Expected: FAIL with `Class "App\Services\Platforms\Payloads\CardPayload" not found`.

- [ ] **Step 3: Write `CardPayload`**

Create `app/Services/Platforms/Payloads/CardPayload.php`:

```php
<?php

namespace App\Services\Platforms\Payloads;

// Typed READ boundary for the LinkCardScraper-family "branded card" payloads:
//   • custom links             {kind:'link', url, name, description, favicon, logo}
//   • booking/reservations     {provider:'custom', source, url, name, favicon, logo}
//     custom fallback
//   • online-ordering entries  {id, provider, source, url, name, favicon, logo,
//                               data:{pickupUrl?, deliveryUrl?, type?, time?, fees?, …}}
// VERBATIM-preserving (raw array + typed accessors): online-ordering writes the
// consolidated payload back on merge-on-add, and the booking/reservations/custom
// cards render publicly — so the DTO must inject no canonical-null keys. `data()`
// returns the nested ordering sub-map verbatim (same passthrough as
// FreshaSelection::services()). The WRITE construction (snapshotOrMinimal spreads,
// mergeStorePayload) stays literal; this is the read side.
final readonly class CardPayload
{
    /** @param array<string,mixed> $raw the stored card payload, preserved verbatim */
    public function __construct(public array $raw) {}

    public static function fromArray(mixed $payload): self
    {
        return new self(is_array($payload) ? $payload : []);
    }

    public function url(): ?string
    {
        return $this->str('url');
    }

    public function name(): ?string
    {
        return $this->str('name');
    }

    public function description(): ?string
    {
        return $this->str('description');
    }

    public function favicon(): ?string
    {
        return $this->str('favicon');
    }

    public function logo(): ?string
    {
        return $this->str('logo');
    }

    public function provider(): ?string
    {
        return $this->str('provider');
    }

    public function source(): ?string
    {
        return $this->str('source');
    }

    public function kind(): ?string
    {
        return $this->str('kind');
    }

    public function id(): ?string
    {
        return $this->str('id');
    }

    /** @return array<string,mixed> the nested ordering data map, verbatim, or [] */
    public function data(): array
    {
        return is_array($this->raw['data'] ?? null) ? $this->raw['data'] : [];
    }

    /** @return array<string,mixed> the stored card, byte-for-byte */
    public function toArray(): array
    {
        return $this->raw;
    }

    private function str(string $key): ?string
    {
        return is_string($this->raw[$key] ?? null) ? $this->raw[$key] : null;
    }
}
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `php artisan test tests/Unit/Platforms/Payloads/CardPayloadTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Payloads/CardPayload.php tests/Unit/Platforms/Payloads/CardPayloadTest.php
git commit -m "feat(integrations): CardPayload DTO for the LinkCardScraper card family"
```

---

## Task 8: Migrate Booking + Reservations status reads (`SelectionPayload` + `CardPayload`)

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/BookingController.php` (`statusFor()` lines ~104–105, ~115, ~120–126)
- Modify: `app/Http/Controllers/Api/Platforms/ReservationsController.php` (`statusFor()` lines ~114–116, ~122–129)
- Test: `tests/Feature/Platforms/IntegrationCategoriesTest.php` + `tests/Feature/Platforms/ReservationProvidersTest.php` (existing parity guards — no change)

**Interfaces:**
- Consumes: `SelectionPayload::fromArray()`, `->url`, `->name`, `->embedUrl`, `->selection?->storeName()` (Plan 4); `CardPayload::fromArray()`, `->provider()`, `->name()`, `->url()` (Task 7).

> **What stays identical:** the `{connected, provider, name, url[, embedUrl]}` status shapes, the single-slot priority (fresha > square > custom; keyless-provider > custom), and `ProviderDetector`-driven `/detect` routing (untouched — `ProviderDetector` is left for Plan 6 and holds no payload reads). Only the payload READS change: the fresha/square/keyless rows are `SelectionPayload` platforms (Plan 4 already gave those descriptors that DTO); the custom fallback row is a `CardPayload`.

- [ ] **Step 1: Confirm the category tests pass against current code**

Run: `php artisan test tests/Feature/Platforms/IntegrationCategoriesTest.php tests/Feature/Platforms/ReservationProvidersTest.php`
Expected: PASS — booking/reservation detection, status aggregation, custom fallback cards, single-slot forget.

- [ ] **Step 2: Migrate `BookingController::statusFor()`**

Add the imports:

```php
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Payloads\SelectionPayload;
```

Replace the three provider branches:

```php
        $fresha = $user->integrationConnections()->where('platform', 'fresha')->first();
        if ($fresha) {
            $sel = SelectionPayload::fromArray($fresha->payload);

            return [
                'connected' => true,
                'provider' => 'fresha',
                'name' => $sel->selection?->storeName(),
                'url' => $sel->url,
            ];
        }

        $square = $user->integrationConnections()->where('platform', 'square')->first();
        if ($square) {
            return [
                'connected' => true,
                'provider' => 'square',
                'name' => null,
                'url' => SelectionPayload::fromArray($square->payload)->url,
            ];
        }

        $custom = CardPayload::fromArray($this->readConnection($user));
        if ($custom->provider() === 'custom') {
            return [
                'connected' => true,
                'provider' => 'custom',
                'name' => $custom->name(),
                'url' => $custom->url(),
            ];
        }

        return ['connected' => false, 'provider' => null, 'name' => null, 'url' => null];
```

> `data_get($fresha->payload, 'selection.storeName')` becomes `SelectionPayload::fromArray(...)->selection?->storeName()` — identical: a missing/null `selection` makes the inner `FreshaSelection` null, and `?->storeName()` returns null, exactly as `data_get` did. `shapeCustom(array $payload)` (the `/detect` echo helper) reads a freshly-built `$payload` it just constructed in the same method, not a stored row — leave it as literal array reads (it is not a stored-payload read site; the exit test targets `->payload` access, which `shapeCustom` does not perform).

- [ ] **Step 3: Migrate `ReservationsController::statusFor()`**

Add the imports:

```php
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Payloads\SelectionPayload;
```

Replace the keyless-provider branch + the custom branch:

```php
        foreach (self::KEYLESS_PROVIDERS as $provider) {
            $row = $user->integrationConnections()->where('platform', $provider)->first();
            if ($row) {
                $sel = SelectionPayload::fromArray($row->payload);

                return [
                    'connected' => true,
                    'provider' => $provider,
                    'name' => $sel->name,
                    'url' => $sel->url,
                    'embedUrl' => $sel->embedUrl,
                ];
            }
        }

        $custom = CardPayload::fromArray($this->readConnection($user));
        if ($custom->provider() === 'custom') {
            return [
                'connected' => true,
                'provider' => 'custom',
                'name' => $custom->name(),
                'url' => $custom->url(),
                'embedUrl' => null,
            ];
        }

        return ['connected' => false, 'provider' => null, 'name' => null, 'url' => null, 'embedUrl' => null];
```

(The `suggestion()` `google-business` harvest was already migrated to `GoogleBusinessPayload` in Task 4. `shapeCustom()` here, as in BookingController, reads a freshly-built array — leave it.)

- [ ] **Step 4: Run the category suites + golden master**

Run:
```bash
php artisan test tests/Feature/Platforms/IntegrationCategoriesTest.php \
  tests/Feature/Platforms/ReservationProvidersTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
```
Expected: PASS — booking/reservations status aggregation across fresha/square/keyless/custom, the empty-state golden-master status contracts (`booking/status`, `reservations/status`), count `52`.

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Http/Controllers/Api/Platforms/BookingController.php app/Http/Controllers/Api/Platforms/ReservationsController.php
git commit -m "refactor(integrations): migrate booking/reservations status reads onto SelectionPayload + CardPayload"
```

---

## Task 9: Migrate Online-Ordering + MenuSource + the auto-sync ordering dedup onto `CardPayload`

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/OnlineOrderingController.php` (lines ~71, ~78, ~108, ~112, ~165, ~191–225, ~241)
- Modify: `app/Services/Platforms/MenuSource.php` (line ~213)
- Modify: `app/Services/Platforms/GoogleBusinessAutoSync.php` (line ~448 — the ordering store-dedup read ONLY)
- Modify: `app/Providers/PlatformRegistryServiceProvider.php` (booking / reservations / online-ordering descriptors)
- Test: `tests/Feature/Platforms/MenuTest.php` + `tests/Feature/Platforms/GoogleBusinessApifyTest.php` (existing parity guards — no change)

**Interfaces:**
- Consumes: `CardPayload::fromArray()`, `->url()`, `->data()`, `->provider()`, `->name()`, `->favicon()`, `->logo()`, `->source()`, `->toArray()` (Task 7).

> **What stays identical:** store-key grouping, pickup/delivery consolidation (one entry per store carrying both mode URLs), merge-on-add, remove-every-row-for-a-store, the `MenuFetchJob` re-derivation, and the menu resolution (`MenuSource::resolve/resolveAll/links`). Only the `data_get($row->payload, 'url')` / `is_array($row->payload)` / `$payload['data']` READS become typed. The `mergeStorePayload(array $payload, …)` WRITE helper keeps taking/returning an array (it mutates `data` and is written straight back) — but it now receives `CardPayload::fromArray($existing->payload)->toArray()` (verbatim) as input rather than `$existing->payload ?? []`.

- [ ] **Step 1: Confirm the menu + auto-sync ordering tests pass against current code**

Run: `php artisan test tests/Feature/Platforms/MenuTest.php tests/Feature/Platforms/GoogleBusinessApifyTest.php`
Expected: PASS — store consolidation, merge-on-add, scrape→merge→persist, the auto-sync ordering seeding + only-if-empty dedup.

- [ ] **Step 2: Migrate `OnlineOrderingController` store-key reads**

Add the import:

```php
use App\Services\Platforms\Payloads\CardPayload;
```

`addEntry()` — the merge-on-add lookup (line ~71) and the existing-row merge input (line ~78):

```php
            $existing = $storeKey === null ? null : $this->entryRows($user)
                ->first(fn (IntegrationConnection $row) => $this->storeKey(CardPayload::fromArray($row->payload)->url()) === $storeKey);

            if (! $existing && $this->entryRows($user)->count() >= self::MAX_ENTRIES) {
                return $this->error('You can add up to '.self::MAX_ENTRIES.' ordering links.', 422);
            }

            if ($existing) {
                $this->writeConnection($user, $this->mergeStorePayload(CardPayload::fromArray($existing->payload)->toArray(), $meta, $mode), $existing->resource_id);
            } else {
```

`removeEntry()` — the target + filter reads (lines ~108, ~112):

```php
        $storeKey = $this->storeKey(CardPayload::fromArray($target->payload)->url());
        $rids = $storeKey === null
            ? [$id]
            : $this->entryRows($user)
                ->filter(fn (IntegrationConnection $row) => $this->storeKey(CardPayload::fromArray($row->payload)->url()) === $storeKey)
                ->pluck('resource_id')
                ->all();
```

`entriesData()` — replace the per-row read (line ~165):

```php
        foreach ($this->entryRows($user) as $row) {
            $card = CardPayload::fromArray($row->payload);
            // Group by store; an unkeyable url (shouldn't happen) gets its own slot.
            $key = $this->storeKey($card->url()) ?? ('row:'.$row->resource_id);
            $groups[$key] ??= [];
            $groups[$key][] = ['rid' => $row->resource_id, 'payload' => $card->toArray()];
        }
```

`consolidateEntry()` — the rows handed in carry `payload` arrays (from `entriesData` above). Re-hydrate each through `CardPayload` so the `data.type` / `url` / display reads are typed (lines ~191–225):

```php
    private function consolidateEntry(array $rows): array
    {
        $primary = CardPayload::fromArray($rows[0]['payload']);
        $data = $primary->data();

        $pickupUrl = $data['pickupUrl'] ?? null;
        $deliveryUrl = $data['deliveryUrl'] ?? null;
        foreach ($rows as $row) {
            $card = CardPayload::fromArray($row['payload']);
            $url = $card->url();
            $type = $card->data()['type'] ?? null;
            $mode = ($type === 'pickup' || $type === 'delivery') ? $type : $this->modeOf($url);
            if ($mode === 'pickup') {
                $pickupUrl ??= $url;
            } elseif ($mode === 'delivery') {
                $deliveryUrl ??= $url;
            }
        }

        $data = array_filter([
            ...$data,
            'pickupUrl' => $pickupUrl,
            'deliveryUrl' => $deliveryUrl,
        ], fn ($v) => $v !== null);

        return [
            'id' => $rows[0]['rid'],
            'provider' => $primary->provider() ?? 'custom',
            'url' => $primary->url(),
            'name' => $primary->name(),
            'favicon' => $primary->favicon(),
            'logo' => $primary->logo(),
            'source' => $primary->source() ?? 'manual',
            'data' => $data === [] ? null : $data,
        ];
    }
```

`mergeStorePayload()` — this is a WRITE helper that mutates the `data` sub-map and returns the array for storage. It already receives a `CardPayload::fromArray(...)->toArray()` (verbatim) from `addEntry`. Keep its body's `$payload['data']` mutation literal (it is building the write, not reading a stored row), but it can read the incoming `data` via the DTO for consistency:

```php
    private function mergeStorePayload(array $payload, array $meta, ?string $mode): array
    {
        $data = CardPayload::fromArray($payload)->data();
        if ($mode === 'pickup') {
            $data['pickupUrl'] = $meta['url'];
        } elseif ($mode === 'delivery') {
            $data['deliveryUrl'] = $meta['url'];
        }

        $payload['data'] = $data === [] ? null : $data;

        return $payload;
    }
```

> This removes the line ~241 `is_array($payload['data'] ?? null)` raw read. `$payload['data'] = …` on line ~248 is a WRITE (array assignment building the stored shape), not a stored-`->payload` read — the exit test targets `->payload` reads, so the assignment is fine.

- [ ] **Step 3: Migrate `MenuSource`'s online-ordering read**

In `app/Services/Platforms/MenuSource.php`, add the import:

```php
use App\Services\Platforms\Payloads\CardPayload;
```

Replace the payload map (line ~213, currently `->map(fn (IntegrationConnection $r) => is_array($r->payload) ? $r->payload : [])`):

```php
            ->map(fn (IntegrationConnection $r) => CardPayload::fromArray($r->payload)->toArray())
```

> `toArray()` is verbatim, so the downstream code (which reads `url` / `data` from each mapped array) is byte-identical. If any downstream read in `MenuSource` indexes the mapped array with `['url']` / `['data']`, those are reads of a LOCAL array variable (not a `->payload` Eloquent attribute) and are out of the exit test's scope — but prefer hydrating a `CardPayload` there too if it reads `data.type`/`url` keys (keep changes minimal and contract-preserving; `MenuTest` is the guard).

- [ ] **Step 4: Migrate the auto-sync ordering store-dedup read**

In `app/Services/Platforms/GoogleBusinessAutoSync.php`, add the import:

```php
use App\Services\Platforms\Payloads\CardPayload;
```

Replace line ~448 (currently `->contains(fn (IntegrationConnection $row) => $this->storeKey(data_get($row->payload, 'url')) === $storeKey);`):

```php
            ->contains(fn (IntegrationConnection $row) => $this->storeKey(CardPayload::fromArray($row->payload)->url()) === $storeKey);
```

> This is the ONLY line of `GoogleBusinessAutoSync` this plan touches — the only-if-empty store-dedup READ. All seeding/write logic (which creates the cross-platform rows) is untouched.

- [ ] **Step 5: Attach the category descriptors' payload class**

In `app/Providers/PlatformRegistryServiceProvider.php`, find:

```php
$r->register(PD::make('custom')->label('Custom Link')->category(Cat::Content)->resource(LinkConnectionResource::class));
$r->register(PD::make('booking')->label('Booking')->category(Cat::Booking));
$r->register(PD::make('reservations')->label('Reservations')->category(Cat::Reservations));
$r->register(PD::make('online-ordering')->label('Online Ordering')->category(Cat::OnlineOrdering));
```

Change the three category pseudo-platforms (leave `custom` for Task 10):

```php
$r->register(PD::make('custom')->label('Custom Link')->category(Cat::Content)->resource(LinkConnectionResource::class));
$r->register(PD::make('booking')->label('Booking')->category(Cat::Booking)->payload(CardPayload::class));
$r->register(PD::make('reservations')->label('Reservations')->category(Cat::Reservations)->payload(CardPayload::class));
$r->register(PD::make('online-ordering')->label('Online Ordering')->category(Cat::OnlineOrdering)->payload(CardPayload::class));
```

Add at the top: `use App\Services\Platforms\Payloads\CardPayload;`

> `booking`/`reservations`/`online-ordering` are smart-detect pseudo-platforms whose stored row is the custom fallback card (`booking`/`reservations`) or the ordering entry (`online-ordering`) — all `CardPayload`. (The known providers store under their own keys: fresha/square → `SelectionPayload`; opentable/resdiary/nowbookit → `SelectionPayload`.)

- [ ] **Step 6: Run the menu + auto-sync + golden master**

Run:
```bash
php artisan test tests/Feature/Platforms/MenuTest.php \
  tests/Feature/Platforms/GoogleBusinessApifyTest.php \
  tests/Feature/Platforms/IntegrationCategoriesTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
```
Expected: PASS — store consolidation (pickup+delivery collapse), merge-on-add, the menu scrape→merge→persist, auto-sync ordering only-if-empty, the `menu/status` golden-master empty-state, count `52`.

- [ ] **Step 7: Commit**

```bash
php artisan pint --dirty
git add app/Http/Controllers/Api/Platforms/OnlineOrderingController.php app/Services/Platforms/MenuSource.php app/Services/Platforms/GoogleBusinessAutoSync.php app/Providers/PlatformRegistryServiceProvider.php
git commit -m "refactor(integrations): migrate online-ordering + menu source + ordering dedup onto CardPayload"
```

---

## Task 10: Migrate Custom Links onto `CardPayload`

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/CustomLinksController.php` (`linksData()` lines ~110–118)
- Modify: `app/Providers/PlatformRegistryServiceProvider.php` (custom descriptor)
- Test: add a focused read-back test to `tests/Feature/Platforms/` (custom links have no dedicated DTO guard yet)

**Interfaces:**
- Consumes: `CardPayload::fromArray()`, `->url()/->name()/->description()/->favicon()/->logo()` (Task 7).

> The `custom` descriptor was registered in Plan 1 with `LinkConnectionResource` but never given a `payload()` and its controller still reads raw — Plan 2 migrated the link-only *socials*, not custom links (per the Plan 4 "Deferred" note: "custom links … Plan 5"). This task closes it.

- [ ] **Step 1: Write a failing read-back guard**

Create `tests/Feature/Platforms/CustomLinksPayloadTest.php`:

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

function customLinksUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('lists a stored custom link with its full card shape', function () {
    $user = customLinksUser('clink');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'custom', 'resource_id' => 'link-abc',
        'payload' => ['kind' => 'link', 'url' => 'https://acme.test', 'name' => 'Acme',
            'description' => 'Best', 'favicon' => 'https://f.ico', 'logo' => 'https://l.png'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/integrations/custom/links')
        ->assertOk()
        ->assertJsonPath('data.links.0.id', 'link-abc')
        ->assertJsonPath('data.links.0.url', 'https://acme.test')
        ->assertJsonPath('data.links.0.name', 'Acme')
        ->assertJsonPath('data.links.0.description', 'Best')
        ->assertJsonPath('data.links.0.favicon', 'https://f.ico')
        ->assertJsonPath('data.links.0.logo', 'https://l.png');
});
```

> Confirm the response-envelope path (`data.links.*` vs `links.*`) against `ApiController::success()` and adjust the `assertJsonPath` prefixes to match the existing custom-links behavior (run it first against current code in Step 2).

- [ ] **Step 2: Run it to confirm it passes against current code**

Run: `php artisan test tests/Feature/Platforms/CustomLinksPayloadTest.php`
Expected: PASS against the current raw-read `linksData()` (characterizes the shape before the refactor). If the envelope path differs, fix the `assertJsonPath` prefixes now.

- [ ] **Step 3: Migrate `linksData()`**

Add the import:

```php
use App\Services\Platforms\Payloads\CardPayload;
```

Replace `linksData()`:

```php
    private function linksData(User $user): array
    {
        return $this->linkRows($user)->map(function (IntegrationConnection $row): array {
            $card = CardPayload::fromArray($row->payload);

            return [
                'id' => $row->resource_id,
                'url' => $card->url(),
                'name' => $card->name(),
                'description' => $card->description(),
                'favicon' => $card->favicon(),
                'logo' => $card->logo(),
            ];
        })->values()->all();
    }
```

- [ ] **Step 4: Attach the descriptor payload class**

In `app/Providers/PlatformRegistryServiceProvider.php`, change the `custom` registration:

```php
$r->register(PD::make('custom')->label('Custom Link')->category(Cat::Content)->resource(LinkConnectionResource::class)->payload(CardPayload::class));
```

(The `CardPayload` import was added in Task 9.)

- [ ] **Step 5: Run + commit**

Run: `php artisan test tests/Feature/Platforms/CustomLinksPayloadTest.php tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php`
Expected: PASS (count `52`).

```bash
php artisan pint --dirty
git add app/Http/Controllers/Api/Platforms/CustomLinksController.php app/Providers/PlatformRegistryServiceProvider.php tests/Feature/Platforms/CustomLinksPayloadTest.php
git commit -m "refactor(integrations): migrate custom links onto CardPayload"
```

---

# Part E — Residual feed-controller `data_get` mop-up

## Task 11: Migrate the residual feed-controller re-fetch reads onto `FeedPayload`

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/AppleController.php` (TWO sites — line ~274 `input`, line ~336 `highlights`), `BandcampController.php` (line ~87), `VimeoController.php` (line ~85), `YoutubeController.php` (line ~86), `YoutubeMusicController.php` (line ~91)
- Test: existing per-platform feature tests + golden master (no change)

**Interfaces:**
- Consumes: `FeedPayload::fromArray()`, `->input`, `->url`, `->apiPath`, `->handle`, `->channelId`, `->highlights` (Plan 3b — `FeedPayload` already carries every one of these "private re-fetch input" keys + the `highlights` nested list).

> These five controllers kept a bespoke `connect()`/`refresh()` action that extracts a stored re-fetch key (the input/url/apiPath/handle/channelId — and Apple also reads stored `highlights` when re-adding an account) to re-run the scraper — the last `data_get($row?->payload, …)` stored-payload reads outside the bespoke/specials set. `FeedPayload` was built to type exactly these (its docblock: *"private re-fetch inputs … carried so the fetch strategies + Plan 6's refresher read them typed"*). **Six** one-line swaps across five controllers (AppleController has two). Missing the `AppleController::keptHighlights()` read at ~336 would leave a `data_get`-on-payload that fails the exit test (Task 12, Assertion 1) — it is included here.

- [ ] **Step 1: Confirm the feed contract is green**

Run: `php artisan test tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php --filter="youtube|vimeo|bandcamp|apple"`
Expected: PASS — the feed read contracts are frozen by the golden master.

- [ ] **Step 2: Migrate each read**

In each controller, add `use App\Services\Platforms\Payloads\FeedPayload;` and replace the `data_get` read with the typed accessor:

- `AppleController.php:274` (`recentFor()`) — `$input = data_get($row?->payload, 'input');` → `$input = FeedPayload::fromArray($row?->payload ?? [])->input;`
- `AppleController.php:336` (`keptHighlights()`) — `return data_get($this->matchAccountRow($user, 'input', $input)?->payload, 'highlights', []);` → `return FeedPayload::fromArray($this->matchAccountRow($user, 'input', $input)?->payload ?? [])->highlights ?? [];`
- `BandcampController.php:87` — `$url = data_get($row?->payload, 'url');` → `$url = FeedPayload::fromArray($row?->payload ?? [])->url;`
- `VimeoController.php:85` — `$apiPath = data_get($row?->payload, 'apiPath');` → `$apiPath = FeedPayload::fromArray($row?->payload ?? [])->apiPath;`
- `YoutubeController.php:86` — `$handle = data_get($row?->payload, 'handle');` → `$handle = FeedPayload::fromArray($row?->payload ?? [])->handle;`
- `YoutubeMusicController.php:91` — `$channelId = data_get($row?->payload, 'channelId');` → `$channelId = FeedPayload::fromArray($row?->payload ?? [])->channelId;`

> `FeedPayload::fromArray(array)` requires an array, so pass `$row?->payload ?? []` (the prior `data_get(null, …)` returned null on a null row; `FeedPayload::fromArray([])->input` is likewise null — equivalent). The scalar accessors return `?string`, matching the prior `data_get` result type; `->highlights` is `?array`, and `keptHighlights()` returns `array`, so the `?? []` preserves both the `data_get(..., 'highlights', [])` default and the method's return type. `keptHighlights()` is a pure READ (preserve-on-re-add) with no write-back, so a normalizing `FeedPayload` round-trip is safe here.

- [ ] **Step 3: Run the affected platform suites + golden master**

Run:
```bash
php artisan test tests/Feature/Platforms --filter="Youtube|Vimeo|Bandcamp|Apple" \
  && php artisan test tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
```
Expected: PASS — each platform's connect/refresh re-fetch still resolves the same stored key; golden master count `52`.

- [ ] **Step 4: Commit**

```bash
php artisan pint --dirty
git add app/Http/Controllers/Api/Platforms/AppleController.php app/Http/Controllers/Api/Platforms/BandcampController.php app/Http/Controllers/Api/Platforms/VimeoController.php app/Http/Controllers/Api/Platforms/YoutubeController.php app/Http/Controllers/Api/Platforms/YoutubeMusicController.php
git commit -m "refactor(integrations): migrate residual feed-controller re-fetch reads onto FeedPayload"
```

---

# Part F — Exit criterion + full-suite verification

## Task 12: `NoUntypedPayloadAccessTest` — the exit-criterion guard

**Files:**
- Create: `tests/Feature/Platforms/NoUntypedPayloadAccessTest.php`

**Interfaces:**
- Consumes: nothing — a static source-scan test.

> **The exit criterion (this plan's reason to exist):** after this plan, **no platform reads its stored connection `payload` via untyped `data_get` anywhere in the platform surface, and every bespoke/special read path goes through a typed DTO.** This test makes that a PROOF. It has two assertions and two documented allowlist entries.
>
> **Scope of the guard (and what it deliberately excludes, with reasons):**
> - **Assertion 1 (global, literal — "no untyped `data_get` on a payload"):** scan `app/Http/Controllers/Api/Platforms`, `app/Jobs/Platforms`, `app/Observers/Core`, `app/Services/Platforms` for `data_get(` whose first argument references a `->payload` (or `getOriginal('payload')`). The only permitted match is `ManagesIntegrationConnection::matchAccountRow` — the generic `data_get($row->payload, $field)` field-filter where `$field` is a caller-supplied dynamic dot-path (platform-agnostic plumbing that cannot resolve to one DTO).
> - **Assertion 2 (read-path DTO coverage):** the bespoke/specials READ-PATH files (the controllers, jobs, observer, and services this plan migrated) contain no raw stored-payload access — no `->payload[`, no `is_array($x->payload`, no `$x->payload ?? []` — outside a `…Payload::fromArray(…)` call.
> - **Excluded — refresh/write path (Plan 6):** `app/Services/Platforms/PlatformRefresher.php` and `app/Services/Platforms/Strategies/Fetch/*.php` read `$connection->payload['key']` as **re-fetch inputs**. `PlatformRefresher`'s `match()` (and its reads) is rewritten onto registry iteration in Plan 6 — its reads die there, so typing them now is wasted. The fetch strategies' re-fetch keys already have a typed home in `FeedPayload`/the new DTOs (the `FeedPayload` docblock says so) and get wired through it when Plan 6 collapses the refresher onto `$descriptor->fetchStrategy()->fetch($connection)`. They use **array access, not `data_get`**, so Assertion 1 does not flag them; Assertion 2 excludes them by directory. This boundary is the prompt's explicit "do NOT rewrite PlatformRefresher yet" constraint.

- [ ] **Step 1: Write the exit-criterion test**

Create `tests/Feature/Platforms/NoUntypedPayloadAccessTest.php`:

```php
<?php

// EXIT CRITERION for the platform-integrations registry redesign (Plan 5):
// no platform reads its stored connection payload via untyped data_get, and every
// bespoke/special read path goes through a typed Payload DTO. Two allowlist entries
// are documented and justified inline.

// Files whose generic, platform-agnostic plumbing legitimately reads payloads
// dynamically (caller-supplied field / shape closure) — cannot resolve to one DTO.
const PAYLOAD_PLUMBING_ALLOWLIST = [
    'app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php',
];

// Refresh/WRITE-path files deferred to Plan 6 (their payload reads are re-fetch
// inputs that die in / move through the refresher collapse). Excluded by directory.
function isDeferredRefreshPath(string $path): bool
{
    return str_contains($path, 'app/Services/Platforms/Strategies/Fetch/')
        || str_ends_with($path, 'app/Services/Platforms/PlatformRefresher.php')
        // DTOs themselves legitimately index $payload['key'] in fromArray().
        || str_contains($path, 'app/Services/Platforms/Payloads/');
}

/** @return list<string> every *.php under the given app dirs */
function platformSurfaceFiles(): array
{
    $roots = [
        base_path('app/Http/Controllers/Api/Platforms'),
        base_path('app/Jobs/Platforms'),
        base_path('app/Observers/Core'),
        base_path('app/Services/Platforms'),
    ];
    $files = [];
    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

it('has no untyped data_get on a stored connection payload (outside generic plumbing)', function () {
    $offenders = [];

    foreach (platformSurfaceFiles() as $path) {
        $rel = str_replace(base_path().'/', '', $path);
        if (isDeferredRefreshPath($path)) {
            continue;
        }
        $lines = file($path);
        foreach ($lines as $n => $line) {
            // data_get( … payload … ) — the literal exit criterion.
            if (preg_match('/data_get\([^;]*payload/', $line)) {
                if (in_array($rel, PAYLOAD_PLUMBING_ALLOWLIST, true)) {
                    continue;
                }
                $offenders[] = "{$rel}:".($n + 1).'  '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([], "Untyped data_get on a payload survives — migrate onto a typed DTO:\n".implode("\n", $offenders));
});

it('has no raw stored-payload access in the migrated read-path files (DTO-mediated only)', function () {
    // The exact files Plan 5 migrated. Each must read its payload only via a
    // …Payload::fromArray(...) DTO — no `->payload[`, no is_array($x->payload),
    // no `$x->payload ?? []`.
    $readPathFiles = [
        'app/Http/Controllers/Api/Platforms/InstagramController.php',
        'app/Http/Controllers/Api/Platforms/GoogleBusinessController.php',
        'app/Http/Controllers/Api/Platforms/EventsPlatformController.php',
        'app/Http/Controllers/Api/Platforms/BookingController.php',
        'app/Http/Controllers/Api/Platforms/ReservationsController.php',
        'app/Http/Controllers/Api/Platforms/OnlineOrderingController.php',
        'app/Http/Controllers/Api/Platforms/CustomLinksController.php',
        'app/Http/Controllers/Api/Platforms/OpenTableController.php',
        'app/Jobs/Platforms/InstagramConnectJob.php',
        'app/Jobs/Platforms/GoogleBusinessEnrichJob.php',
        'app/Observers/Core/IntegrationConnectionObserver.php',
        'app/Services/Platforms/EventsCatalog.php',
        'app/Services/Platforms/MenuSource.php',
        'app/Services/Platforms/GoogleBusinessAutoSync.php',
    ];

    $offenders = [];
    foreach ($readPathFiles as $rel) {
        $path = base_path($rel);
        expect(is_file($path))->toBeTrue("Expected migrated file to exist: {$rel}");
        foreach (file($path) as $n => $line) {
            $isRawAccess = preg_match('/\$\w+(\?)?->payload\s*\[/', $line)            // ->payload['key']
                || preg_match('/is_array\(\s*\$\w+(\?)?->payload\s*\)/', $line)        // is_array($x->payload)
                || preg_match('/->payload\s*\?\?\s*\[\]/', $line)                      // $x->payload ?? []
                || preg_match('/data_get\([^;]*payload/', $line);                      // data_get(... payload ...)
            if ($isRawAccess) {
                $offenders[] = "{$rel}:".($n + 1).'  '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([], "Raw stored-payload access survives in a migrated read path:\n".implode("\n", $offenders));
});
```

- [ ] **Step 2: Run the exit test**

Run: `php artisan test tests/Feature/Platforms/NoUntypedPayloadAccessTest.php`
Expected: PASS — both assertions green. **If Assertion 1 lists an offender,** a `data_get` on a payload survives outside the allowlist — migrate it onto the relevant DTO (do NOT add it to the allowlist unless it is genuinely generic plumbing, and justify inline). **If Assertion 2 lists an offender,** a migrated read path still touches the raw payload — route it through the DTO.

> **Tuning note:** if the `mergeStorePayload`/`consolidateEntry` WRITE assignments (`$payload['data'] = …`) or a local mapped-array index trip the regex, confirm they are writes / local-variable reads (not `->payload` Eloquent reads) and tighten the regex to require the `->payload` token (it already does for the `[` and `is_array`/`?? []` cases). The intent is precise: only **stored `->payload` Eloquent-attribute reads** are forbidden.

- [ ] **Step 3: Commit**

```bash
php artisan pint --dirty
git add tests/Feature/Platforms/NoUntypedPayloadAccessTest.php
git commit -m "test(integrations): exit-criterion guard — no untyped payload access remains"
```

---

## Task 13: Full-suite green + contract/golden-master smoke + scope check

**Files:** none (verification task).

- [ ] **Step 1: Run the whole integrations + registry surface**

Run: `php artisan test tests/Feature/Platforms tests/Unit/Platforms`
Expected: PASS — the golden master, `PlatformResourceContractTest`, Instagram async + R2 cleanup, Google Business Apify, events catalog, integration categories, reservation providers, menu, the five new DTO unit tests, and the exit-criterion test all green together.

- [ ] **Step 2: Confirm the frozen contract is untouched at the HTTP layer**

Run: `php artisan test --filter="PlatformResourceContract|GoldenMaster"`
Expected: PASS — the net-completeness count is still `52`; every migrated read path is byte-identical.

- [ ] **Step 3: Run the full suite to confirm no global regressions**

Run: `composer test`
Expected: PASS (or the same baseline failures present before this plan — confirm none are new by comparing to a pre-plan run).

- [ ] **Step 4: Confirm no stray scope creep**

Run:
```bash
git diff --stat origin/development
# The exit criterion, expressed as a one-shot grep over the whole platform surface
# MINUS the documented Plan-6 refresh path and the DTOs themselves:
grep -rEn 'data_get\([^;]*->payload' app/Http/Controllers/Api/Platforms app/Jobs/Platforms app/Observers/Core app/Services/Platforms \
  | grep -v 'app/Services/Platforms/PlatformRefresher.php' \
  | grep -v 'app/Services/Platforms/Strategies/Fetch/' \
  | grep -v 'ManagesIntegrationConnection.php'
```
Expected: the `git diff --stat` lists ONLY the files in this plan's File Structure (the 5 new DTOs, their tests, the migrated controllers/jobs/observer/services, the provider, the exit test). The `grep` returns NOTHING — every `data_get` on a stored payload outside the Plan-6 refresh path and the generic trait is gone. (`PlatformRefresher` + `Strategies/Fetch/*` legitimately retain `$payload['key']` **array-access** re-fetch reads — they hold no `data_get`-on-payload, so they don't even appear; they are filtered defensively.)

---

## Deferred (explicitly OUT of scope — Plan 6)

- **`PlatformRefresher` `match()` → registry iteration.** Its per-platform `$payload['key']` reads (incl. the events `hiddenEventIds` reads at lines ~167/193) are the refresh/write path; the whole `match()` is replaced by `foreach ($registry->refreshable() as $d) { $d->fetchStrategy()->fetch($connection) }`, at which point those reads vanish. Migrating them in Plan 5 would be wasted work and violates the prompt's "do NOT rewrite PlatformRefresher yet".
- **`Strategies/Fetch/*` payload reads → through the DTO.** The fetch strategies extract re-fetch inputs (`$payload['handle']`, `$payload['placeId']`, …) that `FeedPayload`/`GoogleBusinessPayload` already carry typed. Plan 6 wires the strategies through the DTO as part of the refresher collapse.
- **`ProviderDetector` registry-driven rewrite.** Left untouched here (it holds no stored-payload reads). The spec permits migrating it to read registry categories, but that overlaps Plan 6's detector rewrite — kept out to avoid double-work. If Plan 6's author chooses to do it, the public contract (`detectFor(string $category, string $url): ?string`) must stay identical.
- **`DROP CONSTRAINT` migration + `PlatformInRegistry` Form Request wiring** (the lone schema change; raw SQL in `supabase/migrations/`).

## Not migrated here (by design)

- **Live-scrape WRITE construction stays literal-canonical** (the same rule Plan 4 applied to Fresha/Shop writes): `InstagramConnectJob`'s `$selection = [ … '_folder' => $folder ]` scrape write, `GoogleBusinessAutoSync::seed()`/`applyFinding()` row construction, the `EventsPayload` builder (`accountPayload`/`standalonePayload`/`withIds`), the booking/reservations `/detect` `['provider'=>'custom', …$meta]` writes, and `OnlineOrderingController::mergeStorePayload`'s `$payload['data'] = …` assignment. These build fresh payloads; they are not `data_get` READ sites.
- **`AppleController::highlightsFor()`'s read→mutate→write-back working copy** (`$selection = $row?->payload;` at ~296, then `$selection['highlights'] = …; $this->writeConnection(…, $selection, …)` at ~312–313) stays a **literal array**, NOT routed through `FeedPayload`. `FeedPayload` is *normalizing*, so hydrating the working copy through it (`FeedPayload::fromArray($row->payload)->toArray()`) would inject canonical-null keys (`handle`, `url`, `channelId`, …) that the write-back at ~313 would then persist — drifting the stored shape. Same rationale as `mergeStorePayload`. This site holds no `data_get`-on-`payload` (line ~300 is `data_get($selection, 'input')` on the local copy, no `payload` token), so it does not trip the exit test (Task 12). The genuine re-fetch READS in this controller (`recentFor()` line 274, `keptHighlights()` line 336 — both pure reads) ARE migrated in Task 11.
- **`SingleSelectionPlatformController::selection()`** keeps feeding the raw payload to the resource (it does not consult `payloadClass()`); this is the generic base shared by many platforms and is out of scope. The bespoke/specials platforms migrated here are the ones with their own read sites.
- **`config('partna.social_platforms')`** (the separate link-block UI registry).

---

## Self-Review

**Spec coverage (§7 specials + bespoke three):**
- **Instagram** (async Apify job + connect/status polling + R2 `_folder` cleanup observer; `_folder` stays out of responses) → `InstagramPayload` (Task 1) + read-path migration (Task 2). The 202→poll contract and the `_folder` dispatch-on-disconnect/dispatch-old-on-change/no-op-on-same behavior are preserved and guarded by `InstagramAsyncConnectTest` + `InstagramR2CleanupTest` + the new `_folder`-absent HTTP assertion. ✓
- **Google Business auto-sync** (seeds opentable/resdiary/nowbookit/online-ordering/instagram/socials/booking rows) → `GoogleBusinessPayload` (Task 3) + read migration (Task 4); `GoogleBusinessAutoSync::seed()`/`applyFinding()` untouched except the single ordering store-dedup read (Task 9). Cross-platform seeding preserved; `GoogleBusinessApifyTest`'s 19 cases are the net. ✓
- **Events smart-detect facade + events-custom** → `EventsAccountPayload` + `StandaloneEventPayload` (Task 5) + `EventsController`/`EventsPlatformController`/`EventsCatalog` read migration (Task 6). ✓
- **Custom links** (deferred from Plan 2) → `CardPayload` (Task 7) + migration (Task 10). ✓
- **Menu fetch (online-ordering)** → `MenuSource` read migration onto `CardPayload` (Task 9); the `MenuFetchJob`/`MenuApifyScraper`/`MenuMerger` relational pipeline is untouched (it reads `site.menus`/`menu_items`, not `platform_connections.payload`). ✓
- **Smart-detect category pseudo-platforms (booking / reservations / online-ordering) via `ProviderDetector`** → status reads migrated onto `SelectionPayload` (known providers) + `CardPayload` (custom fallback / ordering entries) (Tasks 8–9); `ProviderDetector` itself left untouched (no payload reads; Plan 6 overlap noted). ✓
- **Exit criterion** ("no platform reads its payload via untyped `data_get`; everything on a typed DTO and registered") → stated as the plan goal + `NoUntypedPayloadAccessTest` (Task 12), with the refresh/write path (`PlatformRefresher` + `Strategies/Fetch/*`) explicitly scoped to Plan 6 and the generic trait helper allowlisted, both justified. Every bespoke/special descriptor gains `->payload(…)` (Tasks 2, 4, 6, 9, 10). ✓

**Placeholder scan:** No `TBD`/`TODO`/"similar to Task N"/"add error handling". Every code step shows complete code; every run step shows the exact command + expected output; the descriptor and route edits show exact before/after. The two HTTP-envelope-path notes (Instagram `_folder` guard, custom-links read-back) explicitly instruct confirming `data.*` vs top-level against an existing assertion and adjusting — a verification step, not a placeholder.

**Type consistency:** `InstagramPayload` (props + `folder`/`source`/`imagesDropped`) is defined in Task 1 and consumed identically in Task 2. `GoogleBusinessPayload` (`name()/placeId()/apifyStatus()/syncFindings()/toArray()`) — Task 3 def, Task 4 use. `EventsAccountPayload` (`url()/organiser()/upcoming()/hiddenEventIds()/toArray()`) + `StandaloneEventPayload` (`id()/event()/toArray()`) — Task 5 def, Task 6 use. `CardPayload` (`url()/name()/description()/favicon()/logo()/provider()/source()/kind()/id()/data()/toArray()`) — Task 7 def, Tasks 8–10 use. `SelectionPayload` (props `url`, `name`, `embedUrl`, `selection`) + `FreshaSelection::storeName()` and `FeedPayload` (props `input`, `url`, `apiPath`, `handle`, `channelId`) are Plan-3/4 APIs reused unchanged (Tasks 8, 11). The descriptor `->payload(…)`/`payloadClass()` API is Plan-3 reused.

**Scope discipline:** 13 tasks across six independently-shippable parts. Touched: 5 new DTOs + their tests, the migrated bespoke controllers/jobs/observer/services, the provider, the exit test. NOT touched: `PlatformRefresher`, `Strategies/Fetch/*`, `ProviderDetector` internals, the DB `CHECK`, `PlatformInRegistry` wiring, `social_platforms`, and every live-scrape WRITE construction — each listed in "Deferred"/"Not migrated here" with a reason. The contract is frozen byte-for-byte; the golden-master count stays `52` (re-asserted in every part's test step and Task 13).

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-06-29-platform-integrations-bespoke-specials.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks, fast iteration. Parts A–E are independent (Instagram, Google Business, Events, category/card family, residual feed mop-up); Part F (Tasks 12–13) must run last.

**2. Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints for review.

**Which approach?**
