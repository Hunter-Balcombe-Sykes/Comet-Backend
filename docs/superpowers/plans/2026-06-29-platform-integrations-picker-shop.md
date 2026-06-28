# Platform Integrations — Picker & Multi-Brand Archetypes (Plan 4 of N)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the two remaining "store something structured" archetypes onto the registry's typed-payload boundary — a `SelectionPayload` (+ a verbatim `FreshaSelection`) for the five pickers (fresha, square, opentable, resdiary, nowbookit) and a `ShopPayload` for the multi-brand shop — migrating the `data_get`/`is_array` payload access in `FreshaController`, `SquareController`, and `ShopController` onto those DTOs, and collapsing the three keyless reservation read paths onto `GenericPlatformController` — with the API contract frozen byte-for-byte throughout.

**Architecture:** Plans 1–3 left a `PlatformRegistry` of descriptors that carry a `payloadClass()`, and a `GenericPlatformController` whose read methods hydrate a stored payload through that DTO before serializing via the descriptor's resource. This plan adds the two remaining payload DTOs and applies them two ways: (a) the **bespoke three** (Fresha, Shop — and Square) keep their hand-written controllers, but their scattered untyped `data_get($payload, …)` / `is_array()` reads become typed DTO reads (writes stay byte-identical); (b) the **keyless reservations** (OpenTable, ResDiary, NowBookit) re-point their `/selection` + `DELETE /` read routes to `GenericPlatformController` (their bespoke `connect()` — multi-tier validation + widget-embed building — stays on the thin controller, exactly as the embed/feed controllers kept `connect()` in Plan 3). No controller is deleted this plan.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4 + PHPUnit, SQLite in-memory for tests, Supabase/Postgres in prod.

**Design spec:** `docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md` (§5 descriptors, §6 ② FetchStrategy/④ Payload, §7 picker + multi-brand archetype rows, §8 typed payloads, §10 strangler migration, §11 testing).

**Builds on (all MERGED on `development`):**
- Plan 1 (spine): `app/Services/Platforms/Registry/{PlatformRegistry,PlatformDescriptor,PlatformCategory}.php`, the strategy contracts, `app/Providers/PlatformRegistryServiceProvider.php`, the golden-master net under `tests/Feature/Platforms/GoldenMaster/`.
- Plan 2 (link-only): `app/Http/Controllers/Api/Platforms/GenericPlatformController.php`, `app/Services/Platforms/Payloads/LinkPayload.php`, the descriptor `connect()` strategy.
- Plan 3a/3b (embed + feed): `app/Services/Platforms/Payloads/{EmbedPayload,FeedPayload}.php`; the descriptor's `payload()`/`payloadClass()` + `fetch()`/`fetchStrategy()`; the generalized `GenericPlatformController` read paths (`selection()` via `accountRows()->first()`, `accounts()`, `removeAccount()`, `forget()` via `forgetAllConnections()`, and the private `shape()` helper that does `new $resourceClass($payloadClass::fromArray($payload)->toArray())->resolve()`); and the `routes/api/integrations.php` `$singleSelection` + `$migratedReads` (per-entry `multi` flag) loops.

## Why this is ONE plan (not split into 4a/4b)

The author prompt allows splitting into 4a (pickers) / 4b (shop) **if the plan exceeds ~12 tasks**. It does not: this plan is **8 tasks** (Part A pickers = Tasks 1–5; Part B shop = Tasks 6–7; Task 8 = full-suite verification). The two halves are independent and each independently shippable, so they are organized into two parts within one document. If the implementer prefers, Tasks 1–5 and 6–7 can be shipped as two branches — but a single branch is fine at this size.

## Global Constraints

- **No Laravel migrations.** This plan touches NO schema. A composer guard (`guard:no-laravel-migrations`) rejects Laravel migrations regardless. Schema changes (none here; the `DROP CONSTRAINT` is Plan 6) go in `supabase/migrations/` as raw SQL.
- **API contract is FROZEN — byte-for-byte.** Every route URI, JSON response shape, and error string stays identical. `PlatformResourceContractTest` (esp. the fresha + shop cases) and `IntegrationContractGoldenMasterTest` must stay green after every task. No assertion in those two files may be loosened to make a change pass.
- **The golden-master net-completeness count must stay `52`.** `IntegrationContractGoldenMasterTest` (~line 413) asserts `expect($readRoutes->count())->toBe(52)`. Moving OpenTable/ResDiary/NowBookit between route loops preserves their `/selection` URIs and adds NO `/accounts` route, so this number does NOT change. If it ever does, a route URI changed — stop and reconcile; never edit the `52`.
- **Stored payload shape is FROZEN for write paths.** `PublicIntegrationConnectionResource` (the public CDN payload) allowlists `fresha => ['url','selection']` and passes the `selection` sub-blob **through verbatim** (it is NOT re-filtered per key). Therefore Fresha write-backs MUST NOT change the stored inner blob's key set. `FreshaSelection` preserves the blob verbatim for exactly this reason (Task 1). Shop brands carry internal keys (`sourceUrl`, `fetchMode`) the controller's product dispatch depends on — `ShopPayload` preserves them verbatim too.
- **Reads use the DTO; the resource is the allowlist.** Read paths follow **resource-output equivalence**: `Resource(Payload::fromArray($raw)->toArray()) === Resource($raw)`, because each resource allowlists its own key subset (the same guarantee `FeedPayload` relies on). This is what makes the read migrations provably byte-identical.
- **Bespoke flows stay intact.** Fresha's team/services picker (`/connect`, `/team`, `/url`, `/employee-services`, `/selection` save, `/service-visibility`) and Shop's brand/product CRUD keep their controllers and live-scrape logic. Only payload ACCESS (the `data_get`/`is_array` reads) is migrated.
- **Authorization via the trait chokepoint.** `ManagesIntegrationConnection` runs `authorizeForUser($user, …)` on every read/write/delete. Both the bespoke controllers and `GenericPlatformController` reuse the trait unchanged — never add inline 403 aborts.
- **`config/partna.php` `social_platforms` is a SEPARATE registry** (the link-block UI). Do NOT reference it from any spine/controller code.
- **Tests run on SQLite; prod is Postgres.** All new code is app-level and engine-agnostic. Use the existing `setupUsersTable()` / `setupSitesTable()` / `actingAsUser()` test helpers; new test users use `'account_type' => 'partna'` (the current standard value).
- **Pint clean.** Run `php artisan pint --dirty` before every commit; never reformat untouched files.
- **Commit prefixes:** `feat(integrations):` for new DTO classes, `refactor(integrations):` for a controller/route migration, `test(integrations):` for test-only additions.

---

## Prerequisite check (run before Task 1)

- [ ] **Confirm Plans 1–3 are merged and the building blocks exist**

Run:
```bash
git fetch && git pull && git log --oneline -10
ls app/Services/Platforms/Payloads/   # expect: LinkPayload.php EmbedPayload.php FeedPayload.php
php artisan tinker --execute="echo method_exists(App\Services\Platforms\Registry\PlatformDescriptor::class,'payloadClass') ? 'OK payloadClass' : 'MISSING'; echo PHP_EOL; echo method_exists(App\Http\Controllers\Api\Platforms\GenericPlatformController::class,'selection') ? 'OK selection' : 'MISSING';"
php artisan test tests/Feature/Platforms tests/Unit/Platforms
```
Expected: the `ls` lists `LinkPayload.php`, `EmbedPayload.php`, `FeedPayload.php`; tinker prints `OK payloadClass` and `OK selection`; the suite is GREEN. **If any is missing, STOP — Plan 3 must land first.**

---

## File Structure

**New:**
- `app/Services/Platforms/Payloads/SelectionPayload.php` — readonly union DTO for the picker archetype: the outer `{url, selection}` (Fresha) + the flat `{url, rid|microsite|accountId+venueId, name, embedUrl, source}` (reservations/square).
- `app/Services/Platforms/Payloads/FreshaSelection.php` — readonly DTO for Fresha's inner blob. **Verbatim-preserving** (typed read accessors over the raw array; `toArray()` returns it unchanged) because the public endpoint passes `selection` through verbatim.
- `app/Services/Platforms/Payloads/ShopPayload.php` — readonly DTO for the multi-brand `shop` map (provider default + array guard; all other brand keys verbatim).
- `tests/Unit/Platforms/Payloads/SelectionPayloadTest.php` — SelectionPayload + FreshaSelection unit tests (hydration, lenient, verbatim round-trip, resource-output equivalence per reservation resource).
- `tests/Unit/Platforms/Payloads/ShopPayloadTest.php` — ShopPayload unit tests (provider default, verbatim preservation, primary brand).
- `tests/Feature/Platforms/FreshaPayloadTest.php` — Fresha non-scraping read/write paths (`GET /url`, pending `/selection`, service-visibility verbatim preservation).
- `tests/Feature/Platforms/ShopPayloadFeatureTest.php` — Shop verbatim preservation through a CRUD cycle.

**Modified:**
- `app/Http/Controllers/Api/Platforms/FreshaController.php` — migrate the 6 `data_get`/`is_array` payload-read sites onto `SelectionPayload`/`FreshaSelection`; writes stay byte-identical.
- `app/Http/Controllers/Api/Platforms/SquareController.php` — migrate `selection()`'s `data_get(..., 'url')` onto `SelectionPayload`.
- `app/Http/Controllers/Api/Platforms/ShopController.php` — migrate `brandMap()` (the `is_array` + per-brand `provider ??=` logic) and `selection()`'s primary-brand pick onto `ShopPayload`.
- `app/Providers/PlatformRegistryServiceProvider.php` — set `->payload(...)` on the fresha, square, opentable, resdiary, nowbookit, shop descriptors.
- `routes/api/integrations.php` — move `opentable`, `resdiary`, `nowbookit` out of `$singleSelection` into `$migratedReads` (`multi => false`); their `/connect` stays on the thin controller, `/selection` + `DELETE /` go to `GenericPlatformController`.
- `tests/Feature/Platforms/ReservationProvidersTest.php` — append `/resdiary/selection` + `/nowbookit/selection` read-back guards (Task 5).

**Untouched (deferred — see "Deferred" at the end):** `InstagramController`, `EventsController`/`BookingController`/`ReservationsController`/`OnlineOrderingController`/`MenuController`/`CustomLinksController`/`GoogleBusinessController` (Plan 5 specials), `PlatformRefresher`, `ProviderDetector`, the DB `CHECK` constraint (Plan 6), `config('partna.social_platforms')`, wiring `PlatformInRegistry` into Form Requests, and Fresha's/Shop's live-scrape WRITE construction (kept literal-canonical).

---

# Part A — Picker archetype (fresha, square, opentable, resdiary, nowbookit)

## Task 1: `SelectionPayload` + `FreshaSelection` typed DTOs

**Files:**
- Create: `app/Services/Platforms/Payloads/SelectionPayload.php`
- Create: `app/Services/Platforms/Payloads/FreshaSelection.php`
- Test: `tests/Unit/Platforms/Payloads/SelectionPayloadTest.php`

**Interfaces:**
- Produces:
  - `final readonly class FreshaSelection` — constructor `(array $raw)`; `fromArray(array $raw): self`; read accessors `url(): ?string`, `storeName(): ?string`, `mode(): ?string`, `employee(): ?array`, `services(): array`, `hiddenServiceIds(): array`; `toArray(): array` returns `$raw` **verbatim**.
  - `final readonly class SelectionPayload` — public props `?string $url`, `?FreshaSelection $selection`, `?string $rid`, `?string $microsite`, `?string $accountId`, `?string $venueId`, `?string $name`, `?string $embedUrl`, `?string $source`; `fromArray(array $payload): self` (lenient: non-string scalars → null; an array `selection` → `FreshaSelection`, anything else → null); `toArray(): array` emits all 9 keys (with `selection => $this->selection?->toArray()`).

> **Why two classes, and why `FreshaSelection` is verbatim:** the picker archetype has two genuinely different storage shapes — Fresha's two-level `{url, selection:{…}}` and the flat reservation/square `{url, rid, …}`. `SelectionPayload` is their union: the flat fields are normalizing (fixed properties, like `FeedPayload`), which is safe for the reservation **read** path because each reservation resource allowlists its own subset (resource-output equivalence). But Fresha's inner blob is **written back** by `FreshaController` and then passed **verbatim** to the public CDN payload by `PublicIntegrationConnectionResource` (allowlist `['url','selection']`, no per-key filter of `selection`). A normalizing inner DTO would inject canonical-null keys (e.g. `mode:null`) into storage and leak them publicly. So `FreshaSelection` keeps the blob byte-for-byte and exposes typed read accessors over it — the typed boundary the spec wants, with zero stored-shape drift.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Platforms/Payloads/SelectionPayloadTest.php`:

```php
<?php

use App\Http\Resources\Platforms\NowBookitConnectionResource;
use App\Http\Resources\Platforms\OpenTableConnectionResource;
use App\Http\Resources\Platforms\ResDiaryConnectionResource;
use App\Services\Platforms\Payloads\FreshaSelection;
use App\Services\Platforms\Payloads\SelectionPayload;
use Tests\TestCase;

// The resource-output-equivalence cases below call JsonResource::resolve(), which
// injects a Request via the container — so this Unit file must boot the app.
// tests/Pest.php only binds TestCase ->in('Feature'); Unit files opt in here
// (mirrors tests/Unit/Platforms/Payloads/FeedPayloadTest.php).
uses(TestCase::class)->in(__FILE__);

// ── FreshaSelection (verbatim inner blob + typed accessors) ──────────────────

it('FreshaSelection preserves the inner blob verbatim (lossless toArray)', function () {
    $raw = [
        'url' => 'https://www.fresha.com/a/acme',
        'storeName' => 'Acme',
        'mode' => 'employee',
        'employee' => ['employeeId' => 'e1', 'displayName' => 'Jo'],
        'services' => [['serviceId' => 's:1', 'name' => 'Cut']],
        'hiddenServiceIds' => ['s:2'],
        '_legacyExtra' => 'kept-verbatim', // a stray key must survive (public passes selection verbatim)
    ];

    expect(FreshaSelection::fromArray($raw)->toArray())->toBe($raw);
});

it('FreshaSelection exposes typed accessors over the raw blob', function () {
    $sel = FreshaSelection::fromArray([
        'url' => 'https://www.fresha.com/a/acme',
        'storeName' => 'Acme',
        'mode' => 'storewide',
        'employee' => null,
        'services' => [['serviceId' => 's:1']],
        'hiddenServiceIds' => ['s:1'],
    ]);

    expect($sel->url())->toBe('https://www.fresha.com/a/acme');
    expect($sel->storeName())->toBe('Acme');
    expect($sel->mode())->toBe('storewide');
    expect($sel->employee())->toBeNull();
    expect($sel->services())->toBe([['serviceId' => 's:1']]);
    expect($sel->hiddenServiceIds())->toBe(['s:1']);
});

it('FreshaSelection accessors are lenient — missing keys return null / empty', function () {
    $sel = FreshaSelection::fromArray(['url' => 'https://www.fresha.com/a/acme']);

    expect($sel->storeName())->toBeNull();
    expect($sel->mode())->toBeNull();        // the resource defaults a null mode to 'employee'
    expect($sel->employee())->toBeNull();
    expect($sel->services())->toBe([]);
    expect($sel->hiddenServiceIds())->toBe([]);
});

// ── SelectionPayload: Fresha two-level envelope ──────────────────────────────

it('SelectionPayload hydrates the Fresha {url, selection} envelope', function () {
    $payload = SelectionPayload::fromArray([
        'url' => 'https://www.fresha.com/a/acme',
        'selection' => [
            'url' => 'https://www.fresha.com/a/acme',
            'storeName' => 'Acme',
            'mode' => 'employee',
            'employee' => ['employeeId' => 'e1'],
            'services' => [['serviceId' => 's:1']],
            'hiddenServiceIds' => [],
        ],
    ]);

    expect($payload->url)->toBe('https://www.fresha.com/a/acme');
    expect($payload->selection)->toBeInstanceOf(FreshaSelection::class);
    expect($payload->selection->storeName())->toBe('Acme');
    expect($payload->selection->mode())->toBe('employee');
});

it('SelectionPayload treats a pending Fresha row (selection null) as no inner blob', function () {
    $payload = SelectionPayload::fromArray([
        'url' => 'https://www.fresha.com/a/acme',
        'selection' => null,
    ]);

    expect($payload->url)->toBe('https://www.fresha.com/a/acme');
    expect($payload->selection)->toBeNull();
});

// ── SelectionPayload: flat reservation / square shapes ───────────────────────

it('SelectionPayload hydrates flat reservation fields', function () {
    $payload = SelectionPayload::fromArray([
        'url' => 'https://www.opentable.com.au/restaurant/profile/266537',
        'rid' => '266537',
        'name' => 'Ollies',
        'embedUrl' => 'https://www.opentable.com.au/widget/reservation/canvas?rid=266537',
        'source' => 'manual',
    ]);

    expect($payload->url)->toContain('266537');
    expect($payload->rid)->toBe('266537');
    expect($payload->name)->toBe('Ollies');
    expect($payload->embedUrl)->toContain('rid=266537');
    expect($payload->source)->toBe('manual');
    expect($payload->selection)->toBeNull();   // no Fresha inner blob
    expect($payload->microsite)->toBeNull();
});

it('SelectionPayload hydrates a bare {url} (Square)', function () {
    $payload = SelectionPayload::fromArray(['url' => 'https://book.squareup.com/appointments/x']);

    expect($payload->url)->toBe('https://book.squareup.com/appointments/x');
    expect($payload->rid)->toBeNull();
    expect($payload->selection)->toBeNull();
});

it('SelectionPayload coerces non-string scalars to null', function () {
    $payload = SelectionPayload::fromArray(['url' => 123, 'rid' => ['nested']]);

    expect($payload->url)->toBeNull();
    expect($payload->rid)->toBeNull();
});

// ── Resource-output equivalence (the reservation read-path contract guard) ───
// Tasks 4-5 re-point OT/RD/NB /selection to GenericPlatformController, which serves
// Resource(SelectionPayload::fromArray($payload)->toArray()). Prove that equals
// Resource($rawPayload) for each reservation resource, so the route flip is
// provably byte-identical.

it('OpenTable resource output is identical via the DTO round-trip', function () {
    $raw = [
        'url' => 'https://www.opentable.com.au/restaurant/profile/266537',
        'rid' => '266537',
        'name' => 'Ollies',
        'embedUrl' => 'https://www.opentable.com.au/widget/reservation/canvas?rid=266537&domain=comau&iframe=true',
        'source' => 'manual',
    ];

    $viaDto = (new OpenTableConnectionResource(SelectionPayload::fromArray($raw)->toArray()))->resolve();
    $direct = (new OpenTableConnectionResource($raw))->resolve();

    expect($viaDto)->toBe($direct);
    expect($viaDto)->toBe([
        'url' => 'https://www.opentable.com.au/restaurant/profile/266537',
        'rid' => '266537',
        'name' => 'Ollies',
        'embedUrl' => 'https://www.opentable.com.au/widget/reservation/canvas?rid=266537&domain=comau&iframe=true',
    ]);
});

it('ResDiary resource output is identical via the DTO round-trip', function () {
    $raw = [
        'url' => 'https://booking.resdiary.com/widget/Standard/Ollies',
        'microsite' => 'Ollies',
        'name' => 'Ollies',
        'embedUrl' => 'https://booking.resdiary.com/widget/Standard/Ollies',
        'source' => 'manual',
    ];

    expect((new ResDiaryConnectionResource(SelectionPayload::fromArray($raw)->toArray()))->resolve())
        ->toBe((new ResDiaryConnectionResource($raw))->resolve());
});

it('NowBookit resource output is identical via the DTO round-trip', function () {
    $raw = [
        'url' => 'https://booking.nowbookit.com/steps/sitting-details?accountid=12&venueid=34',
        'accountId' => '12',
        'venueId' => '34',
        'name' => 'Ollies',
        'embedUrl' => 'https://booking.nowbookit.com/widget?accountid=12&venueid=34',
        'source' => 'manual',
    ];

    expect((new NowBookitConnectionResource(SelectionPayload::fromArray($raw)->toArray()))->resolve())
        ->toBe((new NowBookitConnectionResource($raw))->resolve());
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Payloads/SelectionPayloadTest.php`
Expected: FAIL — `Class "App\Services\Platforms\Payloads\FreshaSelection" not found`.

- [ ] **Step 3: Write `FreshaSelection`**

Create `app/Services/Platforms/Payloads/FreshaSelection.php`:

```php
<?php

namespace App\Services\Platforms\Payloads;

// Fresha's inner selection blob — the {url, storeName, mode, employee, services,
// hiddenServiceIds} object stored under the outer {url, selection} envelope.
//
// Unlike the normalizing DTOs (Link/Embed/Feed, and SelectionPayload's flat
// reservation fields), this one PRESERVES the stored blob VERBATIM (toArray()
// returns it unchanged) and exposes typed READ accessors over it. The reason is
// contract-critical: PublicIntegrationConnectionResource allowlists
// fresha => ['url','selection'] and passes the `selection` value THROUGH VERBATIM
// to the public CDN payload (it is NOT re-filtered per key). A normalizing DTO
// would inject canonical-null keys (e.g. mode:null) into the stored blob on
// write-back and leak them to the public sitepage. Verbatim storage + typed
// accessors gives the typed read boundary the spec wants WITHOUT changing a single
// stored byte. `employee` and `services[]` are scraped objects passed through
// verbatim (their inner keys come from Fresha's __NEXT_DATA__ / booking GraphQL).
final readonly class FreshaSelection
{
    /** @param array<string,mixed> $raw the stored inner selection blob, preserved verbatim */
    public function __construct(public array $raw) {}

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self($raw);
    }

    public function url(): ?string
    {
        return is_string($this->raw['url'] ?? null) ? $this->raw['url'] : null;
    }

    public function storeName(): ?string
    {
        return is_string($this->raw['storeName'] ?? null) ? $this->raw['storeName'] : null;
    }

    /** 'employee' | 'storewide' (the resource defaults a missing value to 'employee'). */
    public function mode(): ?string
    {
        return is_string($this->raw['mode'] ?? null) ? $this->raw['mode'] : null;
    }

    /** @return array<string,mixed>|null the scraped team-member object, verbatim */
    public function employee(): ?array
    {
        return is_array($this->raw['employee'] ?? null) ? $this->raw['employee'] : null;
    }

    /** @return array<int,mixed> the scraped service menu, verbatim */
    public function services(): array
    {
        return is_array($this->raw['services'] ?? null) ? $this->raw['services'] : [];
    }

    /** @return array<int,mixed> the curated hidden-service id list */
    public function hiddenServiceIds(): array
    {
        return is_array($this->raw['hiddenServiceIds'] ?? null) ? $this->raw['hiddenServiceIds'] : [];
    }

    /** @return array<string,mixed> the inner blob, byte-for-byte as stored */
    public function toArray(): array
    {
        return $this->raw;
    }
}
```

- [ ] **Step 4: Write `SelectionPayload`**

Create `app/Services/Platforms/Payloads/SelectionPayload.php`:

```php
<?php

namespace App\Services\Platforms\Payloads;

// Typed boundary for the picker archetype — ONE DTO spanning the five picker
// platforms (fresha, square, opentable, resdiary, nowbookit). Two storage shapes
// live under this archetype:
//   • Fresha's TWO-LEVEL envelope {url, selection:{…}} — the inner blob is a
//     FreshaSelection (carried nested + verbatim; see that class for why).
//   • The FLAT reservation/square shape {url, rid|microsite|accountId+venueId,
//     name, embedUrl, source} — modelled as the top-level scalar fields here.
// Each platform stores a SUBSET; this is their union. The reservation resources
// allowlist their own key subset, so the canonical-null keys this DTO adds are
// dropped on serialization — Resource(fromArray(raw)->toArray()) === Resource(raw)
// (resource-output equivalence, the same contract guarantee FeedPayload uses).
// `source` is the reservation origin tag ('manual' / 'google-business'); the
// reservation resources omit it, but it is carried so the stored row round-trips.
final readonly class SelectionPayload
{
    public function __construct(
        public ?string $url,
        public ?FreshaSelection $selection,
        public ?string $rid,
        public ?string $microsite,
        public ?string $accountId,
        public ?string $venueId,
        public ?string $name,
        public ?string $embedUrl,
        public ?string $source,
    ) {}

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $inner = $payload['selection'] ?? null;

        return new self(
            url: self::stringOrNull($payload['url'] ?? null),
            selection: is_array($inner) ? FreshaSelection::fromArray($inner) : null,
            rid: self::stringOrNull($payload['rid'] ?? null),
            microsite: self::stringOrNull($payload['microsite'] ?? null),
            accountId: self::stringOrNull($payload['accountId'] ?? null),
            venueId: self::stringOrNull($payload['venueId'] ?? null),
            name: self::stringOrNull($payload['name'] ?? null),
            embedUrl: self::stringOrNull($payload['embedUrl'] ?? null),
            source: self::stringOrNull($payload['source'] ?? null),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'selection' => $this->selection?->toArray(),
            'rid' => $this->rid,
            'microsite' => $this->microsite,
            'accountId' => $this->accountId,
            'venueId' => $this->venueId,
            'name' => $this->name,
            'embedUrl' => $this->embedUrl,
            'source' => $this->source,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test tests/Unit/Platforms/Payloads/SelectionPayloadTest.php`
Expected: PASS (11 tests). If a resource-output assertion fails, the resource emits a key the DTO is not carrying — add it to `SelectionPayload` (never edit the resource).

- [ ] **Step 6: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Payloads/SelectionPayload.php app/Services/Platforms/Payloads/FreshaSelection.php tests/Unit/Platforms/Payloads/SelectionPayloadTest.php
git commit -m "feat(integrations): SelectionPayload + FreshaSelection typed DTOs"
```

---

## Task 2: Migrate `FreshaController` payload access onto the DTOs

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/FreshaController.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`
- Test: `tests/Feature/Platforms/FreshaPayloadTest.php` (new)

**Interfaces:**
- Consumes: `SelectionPayload`, `FreshaSelection` (Task 1).
- Produces: `FreshaController` reads its `{url, selection}` payload through `SelectionPayload`; writes stay byte-identical (the inner blob is rebuilt from `FreshaSelection::toArray()` verbatim or built literal-canonical). No method signature or route changes.

> **The six `data_get`/`is_array` read sites being migrated** (all reads; the writes already build the canonical shape and stay literal): `freshaUrl()` outer `url`; `connect()` individual-preserve `selection`; `saveSelection()` `selection.hiddenServiceIds`; `selection()` `selection` + `url`; `setServiceVisibility()` `selection` + `is_array` + `services` + `hiddenServiceIds` + `url`. The storewide `connect()` branch and `saveSelection()`'s freshly-built blob are NOT read sites — they build a canonical array literally and are left as-is. Parity is guarded by `PlatformResourceContractTest` (the 3 fresha cases) + the golden master + the new non-scraping feature test below.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Platforms/FreshaPayloadTest.php`:

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function freshaPayloadUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('fresha GET /url returns the stored connected url via the typed DTO', function () {
    $user = freshaPayloadUser('frp1');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/acme', 'selection' => null],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/url')
        ->assertOk()
        ->assertExactJson(['url' => 'https://www.fresha.com/a/acme']);
});

it('fresha GET /url returns a null url when nothing is connected', function () {
    actingAsUser(freshaPayloadUser('frp2'))->getJson('/api/platforms/fresha/url')
        ->assertOk()
        ->assertExactJson(['url' => null]);
});

it('fresha selection returns the stored url with a null selection for a pending row', function () {
    $user = freshaPayloadUser('frp3');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/acme', 'selection' => null],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/selection')
        ->assertOk()
        ->assertExactJson(['selection' => null, 'url' => 'https://www.fresha.com/a/acme']);
});

it('fresha service-visibility preserves the inner blob shape verbatim (no canonical-null leak)', function () {
    $user = freshaPayloadUser('frp4');
    // Seed an inner blob WITHOUT `mode` (a legacy-shaped row). The write-back must
    // NOT add a mode:null key — the public endpoint passes `selection` verbatim.
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/acme',
            'selection' => [
                'url' => 'https://www.fresha.com/a/acme',
                'storeName' => 'Acme',
                'employee' => ['employeeId' => 'e1'],
                'services' => [['serviceId' => 's:1', 'name' => 'Cut']],
                'hiddenServiceIds' => [],
            ],
        ],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/platforms/fresha/service-visibility', ['serviceId' => 's:1', 'hidden' => true])
        ->assertOk()
        ->assertJsonPath('hiddenServiceIds', ['s:1']);

    $stored = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail()->payload;
    // Inner blob keys unchanged (no `mode` injected) — only hiddenServiceIds mutated.
    expect(array_keys($stored['selection']))->toBe(['url', 'storeName', 'employee', 'services', 'hiddenServiceIds']);
    expect($stored['selection']['hiddenServiceIds'])->toBe(['s:1']);
});
```

- [ ] **Step 2: Run to confirm the new cases pass against current (pre-migration) code**

Run: `php artisan test tests/Feature/Platforms/FreshaPayloadTest.php`
Expected: PASS — the current `FreshaController` already produces this behavior (including the verbatim service-visibility write). This proves the tests capture real behavior before the refactor.

- [ ] **Step 3: Add the import to `FreshaController`**

In `app/Http/Controllers/Api/Platforms/FreshaController.php`, add below the existing `use App\Http\Resources\Platforms\FreshaSelectionResource;` (Pint will order imports):

```php
use App\Services\Platforms\Payloads\SelectionPayload;
```

- [ ] **Step 4: Migrate `freshaUrl()`**

Replace the body of `freshaUrl()`:

```php
    // The per-user Fresha connection payload is { url, selection } — the connected
    // store URL plus the saved { storeName, employee, services } blob (or null).
    private function freshaUrl(User $user): ?string
    {
        return SelectionPayload::fromArray($this->readConnection($user) ?? [])->url;
    }
```

- [ ] **Step 5: Migrate the `connect()` individual-preserve branch**

Replace the individual branch (the block after the `can_book_storewide` `if`, currently reading `$existing = $this->readConnection($user);` then writing `data_get($existing, 'selection')`):

```php
        // Individual: preserve any existing selection (re-connecting the same store
        // keeps the saved team member); the dashboard re-picks via saveSelection.
        // FreshaSelection::toArray() returns the stored inner blob verbatim, so a
        // canonical stored selection round-trips byte-identically; a pending row
        // (selection null) carries forward as null, exactly as before.
        $existing = SelectionPayload::fromArray($this->readConnection($user) ?? []);
        $this->writeConnection($user, [
            'url' => $url,
            'selection' => $existing->selection?->toArray(),
        ]);

        return $this->success(['url' => $url, 'mode' => 'team', ...$menu]);
```

(The storewide branch above it is unchanged — it builds a fresh canonical blob and writes it literally.)

- [ ] **Step 6: Migrate `saveSelection()`'s hidden-id preservation**

Replace the `$hidden = …` block (currently reading `data_get($this->readConnection($user), 'selection.hiddenServiceIds', [])`):

```php
        // Preserve previously hidden services, dropping ids that no longer exist
        // in the refreshed menu so the hidden list never drifts stale.
        $serviceIds = array_map(static fn (array $s): string => (string) $s['serviceId'], $services);
        $existing = SelectionPayload::fromArray($this->readConnection($user) ?? []);
        $hidden = array_values(array_filter(
            $existing->selection?->hiddenServiceIds() ?? [],
            static fn ($id): bool => in_array($id, $serviceIds, true),
        ));
```

(The literal `$selection = [...]` array built below it, and its `writeConnection`, are unchanged — they build the canonical blob.)

- [ ] **Step 7: Migrate `selection()`**

Replace the whole `selection()` method body:

```php
    // GET /api/platforms/fresha/selection — read the saved selection (partna-pages
    // reads this; the dashboard reads it to restore its "saved" state on load).
    public function selection(Request $request): JsonResponse
    {
        $payload = SelectionPayload::fromArray($this->readConnection($this->currentUser($request)) ?? []);

        return $this->success([
            'selection' => $payload->selection !== null
                ? (new FreshaSelectionResource($payload->selection->toArray()))->resolve()
                : null,
            // Pending (Google-seeded) connections have a url but no selection — the
            // dashboard uses it to show "Finish setup" and open the picker.
            'url' => $payload->url,
        ]);
    }
```

- [ ] **Step 8: Migrate `setServiceVisibility()`**

Replace the closure body inside `withConnectionLock(...)` (keep the method signature, the `$user`/`$validated` setup, and the `withConnectionLock` wrapper unchanged):

```php
        return $this->withConnectionLock($user, function () use ($user, $validated): JsonResponse {
            $payload = SelectionPayload::fromArray($this->readConnection($user) ?? []);
            $selection = $payload->selection;
            if ($selection === null) {
                return $this->error('No Fresha selection saved yet.', 404);
            }

            // Only toggle services that exist in the saved menu.
            $serviceIds = array_map(
                static fn ($s) => is_array($s) ? ($s['serviceId'] ?? null) : null,
                $selection->services(),
            );
            if (! in_array($validated['serviceId'], $serviceIds, true)) {
                return $this->error('That service is not part of the saved Fresha menu.', 404);
            }

            $hidden = array_values(array_filter(
                $selection->hiddenServiceIds(),
                static fn ($id): bool => is_string($id),
            ));

            if ($validated['hidden']) {
                $hidden = array_values(array_unique([...$hidden, $validated['serviceId']]));
            } else {
                $hidden = array_values(array_filter($hidden, static fn ($id): bool => $id !== $validated['serviceId']));
            }

            // Write back the inner blob VERBATIM with only hiddenServiceIds replaced —
            // FreshaSelection::toArray() returns the stored blob unchanged, so the
            // public (verbatim) selection payload never gains a canonical-null key.
            $inner = [...$selection->toArray(), 'hiddenServiceIds' => $hidden];
            $this->writeConnection($user, ['url' => $payload->url, 'selection' => $inner]);

            return $this->success((new FreshaSelectionResource($inner))->resolve());
        });
```

- [ ] **Step 9: Set the fresha descriptor's payload class (registry metadata)**

In `app/Providers/PlatformRegistryServiceProvider.php`, find the fresha registration:

```php
$r->register(PD::make('fresha')->label('Fresha')->category(Cat::Booking)->resource(FreshaSelectionResource::class));
```

Change it to:

```php
$r->register(PD::make('fresha')->label('Fresha')->category(Cat::Booking)->resource(FreshaSelectionResource::class)->payload(SelectionPayload::class));
```

Add the import at the top (Pint will order it): `use App\Services\Platforms\Payloads\SelectionPayload;`

> This is registry metadata only — `FreshaController` is bespoke and references the DTO directly (it does not resolve via the registry). The descriptor carries the archetype's payload class for registry completeness + spec-archetype alignment.

- [ ] **Step 10: Run the contract guards + the new feature test**

Run:
```bash
php artisan test tests/Feature/Platforms/FreshaPayloadTest.php \
  tests/Feature/Platforms/PlatformResourceContractTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php \
  tests/Feature/Platforms/Registry/RegistryCoverageTest.php
```
Expected: PASS — the fresha contract cases (`fresha selection wraps the nested selection blob …`, `… storewide mode …`, `fresha service-visibility …`) are byte-identical, the golden master holds (count still `52`), the new verbatim-preservation test passes, and the registry coverage test is unaffected (adding a payload class does not change keys/refreshable).

- [ ] **Step 11: Commit**

```bash
php artisan pint --dirty
git add app/Http/Controllers/Api/Platforms/FreshaController.php app/Providers/PlatformRegistryServiceProvider.php tests/Feature/Platforms/FreshaPayloadTest.php
git commit -m "refactor(integrations): migrate FreshaController payload access onto SelectionPayload"
```

---

## Task 3: Migrate `SquareController::selection()` onto `SelectionPayload`

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/SquareController.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`

**Interfaces:**
- Consumes: `SelectionPayload` (Task 1).
- Produces: `SquareController::selection()` reads its stored `{url}` via `SelectionPayload`. No route, response, or behavior change. Square keeps its thin controller (it returns `{url}` directly — no `selection` wrapper, no resource — and carries the XOR-with-Fresha guard on connect).

> Square is the simplest picker: it stores `{url}` and its `selection()` returns `{url}` (not the `{selection: …}` shape), so it CANNOT use `GenericPlatformController` (whose `selection()` returns `{selection: …}`). It keeps its thin controller; only the one `data_get(..., 'url')` read adopts the DTO. `SelectionPayload`'s Square case is already unit-tested in Task 1.

- [ ] **Step 1: Add the import to `SquareController`**

In `app/Http/Controllers/Api/Platforms/SquareController.php`, add below `use App\Http\Requests\Platforms\ConnectSquareRequest;` (Pint will order it):

```php
use App\Services\Platforms\Payloads\SelectionPayload;
```

- [ ] **Step 2: Migrate `selection()`**

Replace the `selection()` method body:

```php
    // GET /api/platforms/square/selection — read the saved booking URL.
    public function selection(Request $request): JsonResponse
    {
        return $this->success([
            'url' => SelectionPayload::fromArray($this->readConnection($this->currentUser($request)) ?? [])->url,
        ]);
    }
```

- [ ] **Step 3: Set the square descriptor's payload class (registry metadata)**

In `app/Providers/PlatformRegistryServiceProvider.php`, find the square registration:

```php
$r->register(PD::make('square')->label('Square')->category(Cat::Booking)->resource(TileConnectionResource::class));
```

Change it to:

```php
$r->register(PD::make('square')->label('Square')->category(Cat::Booking)->resource(TileConnectionResource::class)->payload(SelectionPayload::class));
```

(The `SelectionPayload` import was added in Task 2 Step 9.)

> Metadata only — `SquareController` references the DTO directly; the descriptor's `resource(TileConnectionResource::class)` from Plan 1 is unused by the thin controller and stays as-is (out of scope to change).

- [ ] **Step 4: Run the Square parity guard**

Run:
```bash
php artisan test tests/Feature/Platforms/SquareConnectionTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
```
Expected: PASS — `connects a Square booking URL and reads it back`, the XOR cases, and `forgets a Square connection` are byte-identical; golden master count still `52`.

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Http/Controllers/Api/Platforms/SquareController.php app/Providers/PlatformRegistryServiceProvider.php
git commit -m "refactor(integrations): migrate SquareController selection onto SelectionPayload"
```

---

## Task 4: Collapse OpenTable read paths onto `GenericPlatformController`

**Files:**
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`
- Modify: `routes/api/integrations.php`

**Interfaces:**
- Consumes: `SelectionPayload` (Task 1), the generalized `GenericPlatformController` read paths + the `$migratedReads` loop (Plan 3).
- Produces: `opentable` `/selection` + `DELETE /` served by `GenericPlatformController` (platform default `'opentable'`, payload `SelectionPayload`, resource `OpenTableConnectionResource`); `/connect` stays on `OpenTableController`; the separate `/suggestion` route is untouched.

> **Why connect stays bespoke:** `OpenTableController::connect()` returns three DIFFERENT 422 strings (not-OpenTable-url / missing-rid / invalid) and builds the widget `embedUrl` + the `source: 'manual'` un-tag — none of which `GenericPlatformController::connect()` (single normalizer + single error message) can express. So only the READ paths collapse, mirroring Plan 3 exactly (feed controllers kept `connect()`, moved `/selection`). `OpenTableController` keeps extending `SingleSelectionPlatformController` (its `connect()` uses the inherited `connected()` helper); the now-unused inherited `selection()`/`forget()` are harmless dead paths (routes point elsewhere), no deletion needed.

- [ ] **Step 1: Set the opentable descriptor's payload class**

In `app/Providers/PlatformRegistryServiceProvider.php`, find the opentable registration:

```php
$r->register(PD::make('opentable')->label('OpenTable')->category(Cat::Reservations)->resource(OpenTableConnectionResource::class));
```

Change it to:

```php
$r->register(PD::make('opentable')->label('OpenTable')->category(Cat::Reservations)->resource(OpenTableConnectionResource::class)->payload(SelectionPayload::class));
```

> Unlike fresha/square/shop, THIS one is functional: `GenericPlatformController::shape()` reads `$descriptor->payloadClass() ?? LinkPayload::class`. Without this line opentable would default to `LinkPayload`, whose `toArray()` is `{username, url}` — `OpenTableConnectionResource` would then read `rid`/`name`/`embedUrl` as null. The payload class MUST be set for the read to be byte-identical.

- [ ] **Step 2: Move opentable from `$singleSelection` to `$migratedReads`**

In `routes/api/integrations.php`:

1. In the `$singleSelection` array, DELETE the opentable entry and its two comment lines:

```php
        // OpenTable reservations — connect by restaurant link, render the
        // keyless reservation widget (rid read from the URL; no scraping).
        'opentable' => OpenTableController::class,
```

2. In the `$migratedReads` array, ADD the opentable entry (after the `pinterest` entry):

```php
        // Keyless reservation widgets — connect() (URL validation + widget-embed
        // building + the google-seeded `source` un-tag) stays bespoke on the thin
        // controller; /selection + DELETE / are served by the registry-driven
        // GenericPlatformController via SelectionPayload. Single-slot (multi=false):
        // no /accounts routes, so the net-completeness count stays 52.
        'opentable' => ['controller' => OpenTableController::class, 'multi' => false],
```

(The `OpenTableController` import at the top of the file stays — it is still used by `$migratedReads` and the explicit `/suggestion` route. The `/suggestion` route block near the bottom is unchanged.)

- [ ] **Step 3: Run the OpenTable parity guards**

Run:
```bash
php artisan test tests/Feature/Platforms/OpenTableConnectionTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
```
Expected: PASS — `connects an OpenTable profile link …` (connect, unchanged), `reads the OpenTable selection back` (now via `GenericPlatformController` → `SelectionPayload` → `OpenTableConnectionResource`; `selection.rid` + `selection.embedUrl` byte-identical), the `/suggestion` cases, and `exposes only the allowlisted OpenTable fields publicly` (public endpoint, unaffected) all green; golden master count still `52`. **If the count assertion fails, a route URI changed — revert the route edit and re-check; never edit the `52`.**

- [ ] **Step 4: Commit**

```bash
php artisan pint --dirty
git add app/Providers/PlatformRegistryServiceProvider.php routes/api/integrations.php
git commit -m "refactor(integrations): collapse opentable read paths onto GenericPlatformController"
```

---

## Task 5: Collapse ResDiary + NowBookit read paths onto `GenericPlatformController`

**Files:**
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`
- Modify: `routes/api/integrations.php`
- Test: `tests/Feature/Platforms/ReservationProvidersTest.php` (append `/selection` read-back cases)

**Interfaces:**
- Consumes: `SelectionPayload` (Task 1), the `$migratedReads` loop.
- Produces: `resdiary` + `nowbookit` `/selection` + `DELETE /` served by `GenericPlatformController` (payload `SelectionPayload`, resources `ResDiaryConnectionResource` / `NowBookitConnectionResource`); `/connect` stays on the thin controllers.

> ResDiary and NowBookit are pure single-selection reservations (no extra routes — ResDiary has only `/connect`; NowBookit has only `/connect`), so they collapse identically to OpenTable. Both keep extending `SingleSelectionPlatformController` for the `connected()` helper their `connect()` uses.

- [ ] **Step 1: Set the resdiary + nowbookit descriptor payload classes**

In `app/Providers/PlatformRegistryServiceProvider.php`, find the registrations:

```php
$r->register(PD::make('resdiary')->label('ResDiary')->category(Cat::Reservations)->resource(ResDiaryConnectionResource::class));
$r->register(PD::make('nowbookit')->label('NowBookit')->category(Cat::Reservations)->resource(NowBookitConnectionResource::class));
```

Change them to:

```php
$r->register(PD::make('resdiary')->label('ResDiary')->category(Cat::Reservations)->resource(ResDiaryConnectionResource::class)->payload(SelectionPayload::class));
$r->register(PD::make('nowbookit')->label('NowBookit')->category(Cat::Reservations)->resource(NowBookitConnectionResource::class)->payload(SelectionPayload::class));
```

(Functional, same reason as Task 4 Step 1.)

- [ ] **Step 2: Append `/selection` read-back guards for resdiary + nowbookit (run GREEN pre-flip)**

`ReservationProvidersTest` covers connect + `/reservations/status` + forget, but never hits `/resdiary/selection` or `/nowbookit/selection` — and the golden master only *counts* routes, it doesn't seed/hit them. Add a byte-identical read-back guard so the route flip in Step 3 is provably equivalent (mirrors `OpenTableConnectionTest`'s "reads the OpenTable selection back"). Append to `tests/Feature/Platforms/ReservationProvidersTest.php` (it already has the `resUser()` helper + the queue/cache `beforeEach`):

```php
it('reads the resdiary selection back through the read path', function () {
    $user = resUser('rsel1');

    actingAsUser($user)->postJson('/api/platforms/resdiary/connect', ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies'])->assertOk();

    actingAsUser($user)->getJson('/api/platforms/resdiary/selection')
        ->assertOk()
        ->assertJsonPath('selection.url', 'https://booking.resdiary.com/widget/Standard/Ollies')
        ->assertJsonPath('selection.embedUrl', 'https://booking.resdiary.com/widget/Standard/Ollies');
});

it('reads the nowbookit selection back through the read path', function () {
    $user = resUser('nsel1');

    actingAsUser($user)->postJson('/api/platforms/nowbookit/connect', ['url' => 'https://booking.nowbookit.com/steps/sitting-details?accountid=12&venueid=34'])->assertOk();

    actingAsUser($user)->getJson('/api/platforms/nowbookit/selection')
        ->assertOk()
        ->assertJsonPath('selection.accountId', '12')
        ->assertJsonPath('selection.venueId', '34')
        ->assertJsonPath('selection.embedUrl', fn ($u) => str_contains((string) $u, 'accountid=12'));
});
```

Run: `php artisan test tests/Feature/Platforms/ReservationProvidersTest.php --filter="reads the resdiary selection back|reads the nowbookit selection back"`
Expected: PASS — served today by `SingleSelectionPlatformController::selection()`. This locks the target shape (`{selection: {url, microsite|accountId+venueId, name, embedUrl}}`) BEFORE the flip, so Step 4 proves byte-identical equivalence through the generic controller.

- [ ] **Step 3: Move resdiary + nowbookit from `$singleSelection` to `$migratedReads`**

In `routes/api/integrations.php`:

1. In `$singleSelection`, DELETE the resdiary + nowbookit entries and their comment:

```php
        // ResDiary + NowBookit reservations — keyless booking widgets, same
        // connect-by-link flow as OpenTable (the embed is built from the URL).
        'resdiary' => ResDiaryController::class,
        'nowbookit' => NowBookitController::class,
```

(After Tasks 4–5, `$singleSelection` holds exactly `skool`, `strava`, `google-business`.)

2. In `$migratedReads`, ADD the two entries (after the opentable entry from Task 4):

```php
        'resdiary' => ['controller' => ResDiaryController::class, 'multi' => false],
        'nowbookit' => ['controller' => NowBookitController::class, 'multi' => false],
```

(`ResDiaryController` + `NowBookitController` imports stay — still used by `$migratedReads`.)

- [ ] **Step 4: Run the reservation parity guards (now through the generic controller)**

Run:
```bash
php artisan test tests/Feature/Platforms/ReservationProvidersTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php
```
Expected: PASS — the two new read-back cases (now served by `GenericPlatformController` → `SelectionPayload` → the reservation resource) are byte-identical; `connects resdiary and reports it as the reservation`, `connects nowbookit …`, the detect/forget cases, and the Google auto-sync cases all green (connect unchanged; the reservations `/status` + `/reservations` forget are served by `ReservationsController`, untouched). Golden master count still `52`.

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Providers/PlatformRegistryServiceProvider.php routes/api/integrations.php tests/Feature/Platforms/ReservationProvidersTest.php
git commit -m "refactor(integrations): collapse resdiary + nowbookit read paths onto GenericPlatformController"
```

---

# Part B — Multi-brand archetype (shop)

## Task 6: `ShopPayload` typed DTO

**Files:**
- Create: `app/Services/Platforms/Payloads/ShopPayload.php`
- Test: `tests/Unit/Platforms/Payloads/ShopPayloadTest.php`

**Interfaces:**
- Produces: `final readonly class ShopPayload { public array $brands; static fromArray(mixed $payload): self; toArray(): array; all(): array; primaryWithProducts(): ?array; }`. `fromArray()` guards non-array → `[]`, and applies `provider ??= 'shopify'` to each array-valued brand while preserving every other brand key VERBATIM. `toArray()` returns the brand-keyed map; `all()` returns the brands as an ordered list (id keys dropped); `primaryWithProducts()` returns the first brand with a non-empty `products`, or null.

> **Why verbatim, not normalizing:** the `shop` payload is a MAP keyed by brand id, and brand objects carry internal keys (`sourceUrl`, `fetchMode`) that `ShopController::providerProducts()` dispatches on, plus `products[]` that pass through verbatim (each is an upstream-shaped object with an absolute `url`). `brandMap()` is read AND written back by the CRUD methods, so a normalizing DTO that dropped `fetchMode`/`sourceUrl` would corrupt storage. `ShopPayload` therefore normalizes ONLY `provider` (the single default `brandMap()` applies today) and preserves everything else byte-for-byte. The public endpoint's per-brand allowlist (`SHOP_BRAND_ALLOWLIST`) drops non-public keys on read; storage stays whole.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Platforms/Payloads/ShopPayloadTest.php`:

```php
<?php

use App\Services\Platforms\Payloads\ShopPayload;

it('ShopPayload preserves the brand map verbatim apart from the provider default', function () {
    $map = [
        'brand-1' => [
            'id' => 'brand-1', 'url' => 'https://b', 'name' => 'B', 'currency' => 'AUD',
            'sourceUrl' => 'https://b/shop', 'fetchMode' => 'client',
            'discountCode' => 'SAVE', 'products' => [['productId' => 'p1', 'url' => 'https://b/p1']],
        ],
    ];

    $out = ShopPayload::fromArray($map)->toArray();

    // provider defaulted in; everything else (incl. sourceUrl, fetchMode, products) verbatim.
    expect($out['brand-1']['provider'])->toBe('shopify');
    expect($out['brand-1']['sourceUrl'])->toBe('https://b/shop');
    expect($out['brand-1']['fetchMode'])->toBe('client');
    expect($out['brand-1']['products'])->toBe([['productId' => 'p1', 'url' => 'https://b/p1']]);
});

it('ShopPayload keeps an explicit provider untouched', function () {
    $out = ShopPayload::fromArray(['b' => ['id' => 'b', 'provider' => 'woocommerce', 'products' => []]])->toArray();

    expect($out['b']['provider'])->toBe('woocommerce');
});

it('ShopPayload returns an empty map for a null / non-array payload', function () {
    expect(ShopPayload::fromArray(null)->toArray())->toBe([]);
    expect(ShopPayload::fromArray('garbage')->toArray())->toBe([]);
    expect(ShopPayload::fromArray([])->all())->toBe([]);
});

it('ShopPayload preserves brand order in all()', function () {
    $payload = ShopPayload::fromArray([
        'b1' => ['id' => 'b1', 'products' => []],
        'b2' => ['id' => 'b2', 'products' => []],
    ]);

    expect(array_column($payload->all(), 'id'))->toBe(['b1', 'b2']);
});

it('ShopPayload leaves a non-array brand entry untouched', function () {
    // Defensive: brandMap() preserves non-array entries as-is (no provider default).
    $out = ShopPayload::fromArray(['b' => ['id' => 'b', 'products' => []], 'junk' => 'not-a-brand'])->toArray();

    expect($out['junk'])->toBe('not-a-brand');
    expect($out['b']['provider'])->toBe('shopify');
});

it('ShopPayload primaryWithProducts returns the first brand with products', function () {
    $payload = ShopPayload::fromArray([
        'empty' => ['id' => 'empty', 'products' => []],
        'full' => ['id' => 'full', 'url' => 'https://f', 'provider' => 'shopify', 'discountCode' => 'X', 'products' => [['productId' => 'p1']]],
    ]);

    expect($payload->primaryWithProducts()['id'])->toBe('full');
});

it('ShopPayload primaryWithProducts is null when no brand has products', function () {
    $payload = ShopPayload::fromArray(['b' => ['id' => 'b', 'products' => []]]);

    expect($payload->primaryWithProducts())->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/Payloads/ShopPayloadTest.php`
Expected: FAIL — `Class "App\Services\Platforms\Payloads\ShopPayload" not found`.

- [ ] **Step 3: Write the DTO**

Create `app/Services/Platforms/Payloads/ShopPayload.php`:

```php
<?php

namespace App\Services\Platforms\Payloads;

// Typed boundary for the multi-brand `shop` archetype. Unlike the single-row
// archetypes, the stored payload is a MAP keyed by brand id
// ({ "<brandId>": {brand}, "individual": {brand}, … }). This DTO is the single
// home for the two pieces of tolerant logic that used to live inline in
// ShopController::brandMap(): the is-array guard on the stored map, and the
// `provider ??= 'shopify'` default for brands stored before the provider field
// existed.
//
// It PRESERVES every brand object VERBATIM apart from that provider default —
// products pass through untouched (each is an upstream-shaped object carrying an
// absolute url), and internal keys (`sourceUrl`, `fetchMode`) MUST survive because
// ShopController::providerProducts() dispatches on them and brandMap() is written
// back by the CRUD methods. (The public endpoint drops non-public keys via its own
// per-brand allowlist; storage stays whole.) That is why this normalizes only
// `provider` and never imposes a fixed brand key set.
final readonly class ShopPayload
{
    /** @param array<string,mixed> $brands brand-keyed map; provider defaulted, all else verbatim */
    public function __construct(public array $brands) {}

    /** Hydrate the stored connection payload (a brand-keyed map, or null/garbage). */
    public static function fromArray(mixed $payload): self
    {
        if (! is_array($payload)) {
            return new self([]);
        }

        $brands = [];
        foreach ($payload as $id => $brand) {
            if (is_array($brand)) {
                // Brands stored before the provider field existed are Shopify
                // (the only provider back then).
                $brand['provider'] ??= 'shopify';
            }
            $brands[$id] = $brand;
        }

        return new self($brands);
    }

    /** Back to the stored brand-keyed map (provider defaulted, else verbatim). */
    public function toArray(): array
    {
        return $this->brands;
    }

    /** Brands as a plain ordered list (drops the id keys). */
    public function all(): array
    {
        return array_values($this->brands);
    }

    /**
     * The COMPAT "primary" brand for the legacy single-brand /selection view: the
     * first brand that has at least one chosen product, or null. Mirrors
     * ShopController::selection()'s `first(fn ($b) => ! empty($b['products']))`.
     *
     * @return array<string,mixed>|null
     */
    public function primaryWithProducts(): ?array
    {
        foreach ($this->brands as $brand) {
            if (is_array($brand) && ! empty($brand['products'])) {
                return $brand;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Unit/Platforms/Payloads/ShopPayloadTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Services/Platforms/Payloads/ShopPayload.php tests/Unit/Platforms/Payloads/ShopPayloadTest.php
git commit -m "feat(integrations): ShopPayload typed DTO"
```

---

## Task 7: Migrate `ShopController` payload access onto `ShopPayload`

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/ShopController.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`
- Test: `tests/Feature/Platforms/ShopPayloadFeatureTest.php` (new)

**Interfaces:**
- Consumes: `ShopPayload` (Task 6).
- Produces: `ShopController::brandMap()` and `selection()` read the stored map through `ShopPayload`. All brand/product CRUD (`addBrand`, `updateBrand`, `removeBrand`, `setProducts`, `addProduct`, `removeProduct`, `catalog`, `brandProducts`) is unchanged — they call `brandMap()` then mutate + `writeConnection()` exactly as before. No route or response change.

> **The migrated sites:** `brandMap()` (the `is_array($map)` guard + per-brand `provider ??= 'shopify'`) → `ShopPayload::fromArray(...)->toArray()`; `selection()`'s primary-brand pick (`collect(brandMap())->first(fn ($b) => ! empty($b['products']))`) → `ShopPayload::primaryWithProducts()`. Because every CRUD method funnels through `brandMap()`, migrating it covers the whole controller's payload read. Parity is guarded by `PlatformResourceContractTest` (the shop cases) + the golden master shop pin + the new verbatim-preservation feature test.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Platforms/ShopPayloadFeatureTest.php`:

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function shopPayloadUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('shop updateBrand preserves other brands internal keys verbatim', function () {
    $user = shopPayloadUser('shp1');
    // Two brands; brand-1 carries internal keys (fetchMode, sourceUrl) the product
    // dispatch depends on. Updating brand-2's discount must not strip brand-1's keys.
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => [
            'brand-1' => [
                'id' => 'brand-1', 'provider' => 'woocommerce', 'url' => 'https://b1',
                'sourceUrl' => 'https://b1/shop', 'fetchMode' => 'client',
                'discountCode' => 'A', 'products' => [['productId' => 'p1', 'url' => 'https://b1/p1']],
            ],
            'brand-2' => [
                'id' => 'brand-2', 'provider' => 'shopify', 'url' => 'https://b2',
                'discountCode' => 'B', 'products' => [],
            ],
        ],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->patchJson('/api/platforms/shop/brands/brand-2', ['discountCode' => 'NEW'])
        ->assertOk()
        ->assertJsonPath('discountCode', 'NEW');

    $stored = IntegrationConnection::where('user_id', $user->id)->where('platform', 'shop')->firstOrFail()->payload;
    // brand-1 internal keys + products survive verbatim; brand-2 discount updated.
    expect($stored['brand-1']['fetchMode'])->toBe('client');
    expect($stored['brand-1']['sourceUrl'])->toBe('https://b1/shop');
    expect($stored['brand-1']['products'])->toBe([['productId' => 'p1', 'url' => 'https://b1/p1']]);
    expect($stored['brand-2']['discountCode'])->toBe('NEW');
});

it('shop selection returns the compat flat view of the first brand with products', function () {
    $user = shopPayloadUser('shp2');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => [
            'empty' => ['id' => 'empty', 'url' => 'https://e', 'discountCode' => '', 'products' => []],
            // No provider key — must default to 'shopify' in the compat view.
            'full' => ['id' => 'full', 'url' => 'https://f', 'discountCode' => 'SAVE', 'products' => [['productId' => 'p1', 'url' => 'https://f/p1']]],
        ],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/shop/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'url' => 'https://f',
            'provider' => 'shopify',
            'discountCode' => 'SAVE',
            'products' => [['productId' => 'p1', 'url' => 'https://f/p1']],
        ]]);
});

it('shop selection is null when no brand has products', function () {
    $user = shopPayloadUser('shp3');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['b' => ['id' => 'b', 'url' => 'https://b', 'products' => []]],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/shop/selection')
        ->assertOk()
        ->assertExactJson(['selection' => null]);
});
```

- [ ] **Step 2: Run to confirm the new cases pass against current (pre-migration) code**

Run: `php artisan test tests/Feature/Platforms/ShopPayloadFeatureTest.php`
Expected: PASS — current `ShopController` already produces this behavior. (Captures real behavior before the refactor.)

- [ ] **Step 3: Add the import to `ShopController`**

In `app/Http/Controllers/Api/Platforms/ShopController.php`, add below `use App\Services\Platforms\GenericShopScraper;` (Pint will order it):

```php
use App\Services\Platforms\Payloads\ShopPayload;
```

- [ ] **Step 4: Migrate `brandMap()`**

Replace the whole `brandMap()` method body:

```php
    /**
     * The stored brand map (id => brand), or empty. Brands stored before the
     * provider field existed are Shopify (the only provider back then) —
     * ShopPayload applies that default and preserves every other brand key verbatim.
     */
    private function brandMap(User $user): array
    {
        return ShopPayload::fromArray($this->readConnection($user))->toArray();
    }
```

- [ ] **Step 5: Migrate `selection()`'s primary-brand pick**

Replace the `$primary = …` line in `selection()`:

```php
    // GET /api/platforms/shop/selection — COMPAT flat view of the primary brand
    // (first brand that has products) so partna-pages' existing Shop card keeps
    // rendering. Returns null when no brand has products.
    public function selection(Request $request): JsonResponse
    {
        $primary = ShopPayload::fromArray($this->readConnection($this->currentUser($request)))->primaryWithProducts();

        $selection = $primary ? [
            'url' => $primary['url'],
            'provider' => $primary['provider'] ?? 'shopify',
            'discountCode' => $primary['discountCode'] ?? '',
            'products' => $primary['products'],
        ] : null;

        return $this->success(['selection' => $selection]);
    }
```

(`primaryWithProducts()` already returns a provider-defaulted brand, so `?? 'shopify'` is now belt-and-suspenders — kept to stay byte-identical to the prior code.)

- [ ] **Step 6: Set the shop descriptor's payload class (registry metadata)**

In `app/Providers/PlatformRegistryServiceProvider.php`, find the shop registration:

```php
$r->register(PD::make('shop')->label('Shop')->category(Cat::Shop)->resource(ShopBrandResource::class));
```

Change it to:

```php
$r->register(PD::make('shop')->label('Shop')->category(Cat::Shop)->resource(ShopBrandResource::class)->payload(ShopPayload::class));
```

Add the import at the top (Pint will order it): `use App\Services\Platforms\Payloads\ShopPayload;`

> Metadata only — `ShopController` is bespoke (multi-brand CRUD) and references `ShopPayload` directly; it does not resolve via the registry.

- [ ] **Step 7: Run the contract guards + the new feature test**

Run:
```bash
php artisan test tests/Feature/Platforms/ShopPayloadFeatureTest.php \
  tests/Feature/Platforms/PlatformResourceContractTest.php \
  tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php \
  tests/Feature/Platforms/Registry/RegistryCoverageTest.php
```
Expected: PASS — `shopify addBrand returns the canonical brand object shape`, `shopify brands list strips unknown per-brand keys`, the golden master `freezes the shop brands list contract` (provider defaulted to `shopify`, `_leak` stripped), the new verbatim-preservation + compat-view tests, and registry coverage all green; golden master count still `52`.

- [ ] **Step 8: Commit**

```bash
php artisan pint --dirty
git add app/Http/Controllers/Api/Platforms/ShopController.php app/Providers/PlatformRegistryServiceProvider.php tests/Feature/Platforms/ShopPayloadFeatureTest.php
git commit -m "refactor(integrations): migrate ShopController payload access onto ShopPayload"
```

---

## Task 8: Full-suite green + contract/golden-master smoke

**Files:** none (verification task).

- [ ] **Step 1: Run the whole integrations + registry surface**

Run: `php artisan test tests/Feature/Platforms tests/Unit/Platforms`
Expected: PASS — the golden master, `PlatformResourceContractTest`, the reservation/square/fresha feature tests, and all new DTO unit + feature tests green together.

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
# Square + Shop: every payload read migrated — no data_get remains in these two at all.
grep -rn "data_get" app/Http/Controllers/Api/Platforms/SquareController.php app/Http/Controllers/Api/Platforms/ShopController.php
# Fresha: no data_get on the STORED payload remains (reads go through SelectionPayload).
# (grep -E so the alternation works on macOS/BSD grep — `\|` in a basic regex is literal there.)
grep -rEn 'data_get\(\$payload|data_get\(\$existing|data_get\(\$selection|data_get\(\$this->readConnection' app/Http/Controllers/Api/Platforms/FreshaController.php
```
Expected: the `git diff --stat` lists ONLY the files in this plan's File Structure (the 3 controllers, the provider, the routes file, the 3 new DTOs, the test files). Both `grep`s return NOTHING — every stored-payload `data_get`/`is_array` read in the three controllers is now a typed DTO read. **Note:** `FreshaController` legitimately RETAINS `data_get` on external-scrape responses (`data_get($response->json(), …)`, `data_get($data, …)`, `data_get($location, …)`, `data_get($node, …)`, `data_get($item, …)` in `fetchEmployeeServices`/`fetchLocation`/`extractTeam`/`extractServices`) — those parse Fresha's HTML/GraphQL, not the stored payload, and are correctly left untouched (which is why the grep targets the payload-variable names specifically, not bare `data_get`).

---

## Deferred (explicitly OUT of scope — later plans)

- **Plan 5 (bespoke & specials):** Instagram (async Apify connect + `_folder` cleanup), the EventsController smart-detect facade + events-custom, custom links, GoogleBusiness auto-sync + `/synced`, the menu fetch, and the smart-detect category pseudo-platforms (booking / reservations / online-ordering) via `ProviderDetector`. The "no untyped payload access remains anywhere" exit criterion is Plan 5's, not this plan's.
- **Plan 6 (collapse & cutover):** the `PlatformRefresher` `match()` → registry-iteration rewrite, the `ProviderDetector` registry-driven rewrite, wiring `PlatformInRegistry` into the Form Requests, pointing `RefreshController` at the registry, and the single `DROP CONSTRAINT` migration (raw SQL in `supabase/migrations/`).
- **Not migrated here (by design):** Fresha's live-scrape WRITE construction (the storewide `connect()` blob and `saveSelection()`'s freshly-built blob stay literal-canonical — they are not `data_get` read sites); the reservation/Square `connect()` flows (multi-tier 422 + widget-embed building stay bespoke); attaching `fetch()` strategies to the picker/shop descriptors (these archetypes are `OnDemand`-refresh / non-refreshable — no scheduled fetch); `config('partna.social_platforms')`.

---

## Self-Review

**Spec coverage (Plan 4 portion of §7 picker + multi-brand):**
- §7 Picker row (fresha, square, opentable, resdiary, nowbookit) → `SelectionPayload` (Task 1) + Fresha access migration (Task 2) + Square access migration (Task 3) + OT/RD/NB read-path collapse (Tasks 4–5). ✓
- §7 Multi-brand row (shop) → `ShopPayload` (Task 6) + ShopController access migration (Task 7). ✓
- §8 typed payloads — `fromArray`/`toArray` + lenient hydration → SelectionPayload/FreshaSelection/ShopPayload, with the verbatim-preservation variant documented for the public-passthrough cases. ✓
- §10 strangler / contract frozen — every migration leans on the existing `PlatformResourceContractTest` (fresha + shop cases) + golden master as the byte-identical parity guard, and re-points only read routes (connect stays bespoke). ✓
- §11 archetype parity tests — `fromArray`/`toArray` round-trip + resource-output equivalence per reservation resource (Task 1) + verbatim-preservation (FreshaSelection, ShopPayload). ✓
- **Deferred (correctly out of scope):** Instagram/events/google-business/menu/custom (Plan 5); the refresher/detector rewrite, `PlatformInRegistry` wiring, and `DROP CONSTRAINT` (Plan 6) — listed in "Deferred".

**Placeholder scan:** No `TBD`/`TODO`/"similar to Task N"/"add error handling". Every code step shows complete code; every run step shows the exact command + expected output. The one-line descriptor edits and route-array edits show the exact before/after.

**Type consistency:** `SelectionPayload::fromArray()/toArray()` and its public props (`url, selection, rid, microsite, accountId, venueId, name, embedUrl, source`) are used consistently in Tasks 1–5. `FreshaSelection` accessors (`url(), storeName(), mode(), employee(), services(), hiddenServiceIds(), toArray()`) match between Task 1 (definition) and Task 2 (usage). `ShopPayload` (`fromArray/toArray/all/primaryWithProducts`) matches between Task 6 (definition) and Task 7 (usage). The descriptor `->payload(...)` setter + `payloadClass()` getter are Plan-3 APIs reused unchanged. `GenericPlatformController::shape()` (Plan 3) is consumed by Tasks 4–5 via the `$migratedReads` route default, not redefined.

**Scope discipline:** 8 tasks (≤12 → one plan, not split). Only the 3 controllers + provider + routes + 3 DTOs + tests are touched. No Plan 5/6 work; no `PlatformRefresher`/`ProviderDetector`/`CHECK`/`social_platforms` edits. The contract is frozen byte-for-byte; the golden-master count stays `52` (verified by a guard in Tasks 4–5 and Task 8).

**Stored-shape safety (the subtle one):** the Fresha + Shop write paths preserve stored bytes exactly (FreshaSelection verbatim `toArray()`; ShopPayload normalizes only `provider`), because `PublicIntegrationConnectionResource` passes the Fresha `selection` sub-blob through verbatim and Shop's internal brand keys (`fetchMode`/`sourceUrl`) drive the product dispatch. Tasks 2 + 7 each add a feature test that asserts the stored shape is unchanged after a write.
