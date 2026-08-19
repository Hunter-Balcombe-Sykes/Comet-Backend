# Fresha auto-selection for pre-account builds — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Fresha booking link discovered during a pre-account build auto-selects whose menu to publish — the account holder's own if the staff matcher identifies them, the storewide menu if it cannot — instead of landing selection-less and publishing nothing.

**Architecture:** **This is a scope restoration, not a new feature.** `FreshaAutoSelector::select()` already implements exactly this policy (match → employee menu, else storewide), already wired through `FreshaConnectFetch`'s `auto` mode, under the booking-XOR lock, with an install-wide daily scrape cap. Its design doc — `2026-08-10-fresha-auto-route-selection-design.md`, v3, "all decisions closed" — specifies `autoConnectBooking = **true**` for every **Instagram-origin** construction site, with `false` reserved for the dashboard paste. The code that shipped in the same commit narrowed it further, to staff/ManyChat builds only, via an upstream gate the spec's table does not mention. This plan restores the spec's scope, marks the resulting selection as machine-chosen so the owner can be *prompted* at claim (a step beyond the original design, which relied on them noticing), and closes the aggregator lane where the flag has since gone inert.

**Tech Stack:** PHP 8.4 / Laravel 12, Pest 4, Horizon (Redis), Supabase Postgres. Tests run SQLite in-memory.

**Spec:** `docs/superpowers/specs/2026-08-10-fresha-auto-route-selection-design.md` (v3 — the design of record; **this plan does not supersede it, it finishes it**). Implementation plan of record: `docs/superpowers/plans/2026-08-10-fresha-auto-route-selection.md`. Defect re-observation: `docs/reviews/2026-08-19-instagram-build-wave-RESULTS.md` §R14. Original observation: `docs/reviews/2026-08-10-instagram-build-wave-RESULTS.md` §F7. Owner ruling: under **Global Constraints** below.

## History — read this before deciding anything is new

The same defect has now been found twice, nine days apart, and the fix for it already exists.

| When | What |
|---|---|
| 2026-08-10 | **F7** of the first Instagram build wave: "an auto-routed Fresha connection **can never acquire services by any automatic path**… parked in 'waiting for a human' while no human has been, or will be, asked." |
| 2026-08-10 | Design doc written and revised to v3 — *"Auto-routed Fresha connections: name-match selection with storewide fallback"*. Its construction-site table (`:103-108`) marks **all three Instagram-origin sites `true`**, with only `CustomLinksController` (dashboard paste) `false`. Its Risks section explicitly accepts pre-claim exposure: *"the failure mode is another person's prices on a stranger's public page, pre-claim"*, bounded because *"the existing picker corrects us after claim."* |
| 2026-08-11 | Commit `5f7a04ca4` *"auto-match only where nobody can pick"* ships `FreshaAutoSelector` **and** the design doc **and** an upstream gate — `built_by_staff_id !== null` — that the design doc's own table does not describe. The spec was never updated to match. **A reader of the spec would reasonably conclude auto-selection covers pre-account signups. It does not.** |
| 2026-08-18 | The aggregator lane migrates to `LinkRoutingService`; `LinkInBioScanJob::$autoConnectBooking` becomes **vestigial**, silently removing the second of the spec's three `true` sites. |
| 2026-08-19 | **R14** of the fifth build wave: the identical defect, on a public site-first signup. |

**Implication for the implementer:** every objection to this plan of the form "isn't this risky / shouldn't a human pick?" was raised, answered and closed in the v3 design under D1 and Risks. Read those before re-litigating. What this plan adds beyond that design is the *prompt* at claim (Task 2) — the design assumed the owner would find the picker themselves.

## Global Constraints

- **Owner ruling (2026-08-19, this session):** name match wins; **no match falls back to storewide**; the owner is prompted to narrow it when they claim the site. This was chosen with the price risk stated and re-affirmed — see "Accepted risk" below.
- **Accepted risk, stated once:** a storewide Fresha menu prices every service as "from &lt;cheapest staff member&gt;". Measured on dev: **22 of 23 prices understated**. Under this ruling an unclaimed, publicly-rendered pre-account site may show those understated prices until the site is claimed (which may be never — builds expire at `pre_account_builds.expires_at`). This is the deliberate trade against publishing nothing. Do not "fix" it by reverting to selection-less.
- **Never branch on `account_type`** — gate on `AccountCapabilities` (CLAUDE.md). Nothing in this plan needs to.
- **`matchTier` and the new `autoSelected` marker are OWNER-FACING ONLY.** Neither may be added to `PublicIntegrationConnectionResource::ALLOWLIST`. `fresha` has no public allowlist entry today and must not gain one here.
- **Do not create Laravel migration files.** No schema change is required by this plan — every new field is a JSON payload key on `site.platform_connections.payload`.
- Tests: Pest, in `tests/Feature/PreAccount/` and `tests/Feature/Platforms/`. Run with `composer test`. **A green SQLite run says nothing about Postgres CHECK/NOT NULL behaviour** — this plan writes no new columns, so that hazard does not bite, but do not add one.
- Branch: `audit-fix/fresha-auto-selection-preaccount-2026-08-19` off `development`.

---

## Background an implementer needs

**What already exists (do not rebuild any of it):**

| Piece | Where | What it does |
|---|---|---|
| `FreshaAutoSelector::select()` | `app/Services/Platforms/FreshaAutoSelector.php` | Matches the user against the scraped team; on a hit fetches that employee's menu; on any miss returns a working **storewide** selection. Already logs `fresha.auto_selection` with `mode` + `match_tier`. |
| `FreshaStaffMatcher::matchWithTier()` | `app/Services/Platforms/FreshaStaffMatcher.php` | Tiers: `exact`, `both-tokens`, `first-exact`, `last-only`. Ambiguity (two Sarahs) returns null → storewide. |
| `auto` connect mode | `app/Services/Platforms/Strategies/Fetch/FreshaConnectFetch.php:98,227,282` | Storewide's scrape + locked projection, with the selection decided by `FreshaAutoSelector`. Single-flighted through `rememberLocked` so several people from one salon collapse to one scrape. |
| The dispatch | `BuildsAutoSyncFindings::dispatchAutoBookingConnect()` (`app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php:569`) | Claims the install-wide daily budget, stamps `payload.connectMode = 'auto'`, dispatches `ConnectFetchJob`. |
| The budget | `config/partna.php:1859-1868` | `auto_booking.enabled` (default true), `global_daily_cap` (default 500/day install-wide), menu cache TTL. |
| End-to-end proof | `tests/Feature/PreAccount/InstagramFreshaAutoSelectionTest.php` | Already proves match→employee AND degrade→storewide through `LinkRouter::route(..., new RouteContext(autoConnectBooking: true))`. |
| Identity ordering | `app/Services/PreAccount/Generators/InstagramSourceGenerator.php:89-106` | The IG name is folded onto the user row **before** `seed()` *specifically so the matcher has a name to match*. That comment is the strongest evidence the pre-account lane was always meant to auto-select. |

**The one thing that is off:** `app/Jobs/PreAccount/GeneratePreAccountSiteJob.php:98-100` passes `$build->built_by_staff_id !== null`. A staff/ManyChat build gets auto-selection; a public site-first signup does not — on the reasoning, stated in the code comment, that "a public site-first signup has the person on the other end of the request and the frontend asks them to pick."

That premise is false, and the v3 design already says so. An unclaimed pre-account site renders publicly the moment it is built (CLAUDE.md: the profiles route ignores `is_published` for `'unclaimed'`) and may sit unclaimed until `expires_at`. Nobody is asked anything in the meantime. The design's own Risks section names this exact situation — "another person's prices on a stranger's public page, **pre-claim**" — and accepts it, because the alternative it was weighed against is publishing nothing at all.

**Verified, 2026-08-19:** the gate is byte-identical on the working checkout and on `origin/development`. Nothing has already fixed this.

**Two lanes, one of which the flag does not reach:**

1. **Fresha link directly in the IG bio** → `InstagramAutoSync::seed()` → `LinkRouter::route()` → `seedBooking()` → `dispatchAutoBookingConnect()` **gated on `$ctx->autoConnectBooking`**. Task 1 fixes this. This is R14's actual case (build 1, `anseo-studio-v0v92jna`).
2. **Fresha link inside an unrolled aggregator page** (Linktree/Milkshake/Beacons/Stan Store) → `LinkInBioScanJob` → `LinkInBioImporter` → `LinkRoutingService` → `SourceReconciler`. On this lane `LinkInBioScanJob::$autoConnectBooking` is **explicitly VESTIGIAL** (`app/Jobs/Platforms/LinkInBioScanJob.php:52-56`) — no auto-connect is ever dispatched. Task 3 fixes this.

**Verification query** (dev, `glncumufgaqcmqhzwrxm`) — the correlation this plan must break:

```sql
select s.selection_ref, c.payload->>'source' as src, r.records_seen, r.detail->'notes'
from ingest.sources s
join site.platform_connections c on c.id = s.connection_id
left join lateral (
  select * from ingest.runs rr where rr.source_id = s.id order by rr.started_at desc limit 1
) r on true
where s.source_key = 'fresha'
order by s.created_at desc limit 10;
```

Today: every `selection_ref IS NULL` row has `records_seen = 0` and a `no_selection` note; every row with a `selection_ref` has records. After this plan, pre-account rows must land with a non-null `selection_ref`.

---

## File Structure

- **Modify** `app/Jobs/PreAccount/GeneratePreAccountSiteJob.php` — widen the auto-connect gate to every pre-account build (Task 1).
- **Modify** `app/Jobs/PreAccount/ApproveEarlyAccessBuildJob.php` — same gate, second call site (Task 1).
- **Modify** `app/Services/Platforms/Strategies/Fetch/FreshaConnectFetch.php` — stamp `autoSelected` alongside the existing `matchTier` (Task 2).
- **Modify** `app/Http/Controllers/Api/Platforms/FreshaController.php:615` (`selection()`) — surface `autoSelected` + `matchTier` as siblings of `selection` (Task 2). **Not** `FreshaSelectionResource`: that class receives the *inner selection array* only (`$this->resource['url']`, `['mode']`, …), so payload-level keys are structurally invisible to it.
- **Modify** `app/Routing/SourceReconciler.php` — dispatch auto-connect for an unclaimed user's newly-placed Fresha connection (Task 3).
- **Create** `tests/Feature/PreAccount/PreAccountFreshaAutoConnectTest.php` (Tasks 1 & 3).
- **Modify** `tests/Feature/Platforms/FreshaAutoConnectFetchTest.php` — pin the marker (Task 2).

---

### Task 1: Auto-select on every pre-account build

**Files:**
- Modify: `app/Jobs/PreAccount/GeneratePreAccountSiteJob.php:90-100`
- Modify: `app/Jobs/PreAccount/ApproveEarlyAccessBuildJob.php:94`
- Test: `tests/Feature/PreAccount/PreAccountFreshaAutoConnectTest.php` (create)

**Interfaces:**
- Consumes: `SiteSourceGenerator::generate(User $user, Site $site, string $sourceRef, bool $autoConnectBooking = false): void` — unchanged signature.
- Produces: nothing new. Downstream (`InstagramSourceGenerator` → `InstagramConnectionSeeder` → `InstagramAutoSync` → `LinkRouter`) is untouched.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PreAccount/PreAccountFreshaAutoConnectTest.php`:

```php
<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\Generators\SiteSourceGenerator;
use App\Services\PreAccount\SourceGeneratorRegistry;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIntegrationConnectionsTable();
});

it('passes autoConnectBooking TRUE for a self-serve site-first signup', function () {
    // R14: a public signup build has nobody sitting in front of a picker either —
    // the site is public from the moment it is built and may never be claimed.
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Simon Doyle']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'simondoylehair', 'is_published' => false]);

    $build = PreAccountBuild::factory()->make([
        'source_type' => 'instagram',
        'built_via' => PreAccountBuild::VIA_SIGNUP,
        'built_by_staff_id' => null, // the discriminator under test
    ]);
    $build->build_state = PreAccountBuild::STATE_PENDING;
    $build->user()->associate($user);
    $build->save();

    $seen = new stdClass;
    $seen->flag = null;

    $this->mock(SourceGeneratorRegistry::class, function ($mock) use ($seen) {
        $gen = new class($seen) implements SiteSourceGenerator
        {
            public function __construct(private stdClass $seen) {}

            public function normalizeRef(string $raw): string
            {
                return $raw;
            }

            public function dedupeKey(string $normalizedRef): string
            {
                return $normalizedRef;
            }

            public function handleSeed(string $normalizedRef, ?string $sourceName): string
            {
                return $normalizedRef;
            }

            public function generate(User $user, Site $site, string $sourceRef, bool $autoConnectBooking = false): void
            {
                $this->seen->flag = $autoConnectBooking;
            }
        };
        $mock->shouldReceive('for')->andReturn($gen);
    });

    (new GeneratePreAccountSiteJob($build->id, $build->source_type, publish: false))
        ->handle(app(SourceGeneratorRegistry::class));

    expect($seen->flag)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PreAccount/PreAccountFreshaAutoConnectTest.php`
Expected: FAIL — `expect(false)->toBeTrue()`, because the gate currently reads `built_by_staff_id !== null` and this build has none.

- [ ] **Step 3: Widen the gate**

In `app/Jobs/PreAccount/GeneratePreAccountSiteJob.php`, replace the block at lines 90-100 (the comment AND the call):

```php
        try {
            // Auto-connect a discovered booking menu for EVERY pre-account build.
            // This used to read $build->built_by_staff_id !== null, on the reasoning
            // that a public site-first signup has the person on the other end of the
            // request and the frontend asks them to pick. That premise is false: an
            // unclaimed pre-account site renders publicly from the moment it is built
            // (CLAUDE.md — the profiles route ignores is_published for 'unclaimed')
            // and may sit unclaimed until expires_at. Nobody is asked anything in the
            // meantime, so the Fresha connection landed selection-less and published
            // no services at all — R14, 2026-08-19 build wave.
            //
            // FreshaAutoSelector decides: the account holder's own menu when
            // FreshaStaffMatcher identifies them, the storewide menu when it cannot.
            // Storewide understates prices (22 of 23 measured on dev), which is the
            // accepted trade against publishing nothing — the owner confirms the
            // choice at claim (see payload.autoSelected).
            $registry->for($build->source_type)->generate(
                $user, $site, $build->source_ref, true,
            );
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/PreAccount/PreAccountFreshaAutoConnectTest.php`
Expected: PASS.

- [ ] **Step 5: Apply the same gate to the early-access approval path**

`app/Jobs/PreAccount/ApproveEarlyAccessBuildJob.php:94` currently calls `generate($user, $site, $build->source_ref)` and so takes the `false` default. An approved early-access build is a pre-account build by the same definition — nobody is present. Change it to:

```php
                // TRUE for the same reason as GeneratePreAccountSiteJob: an
                // approved early-access build creates a public unclaimed site with
                // no human to answer "whose menu is this?".
                $registry->for($build->source_type)->generate($user, $site, $build->source_ref, true);
```

- [ ] **Step 6: Run the neighbouring suites for regressions**

Run: `./vendor/bin/pest tests/Feature/PreAccount tests/Feature/Platforms`
Expected: PASS. Pay attention to `InstagramFreshaAutoSelectionTest`, `FreshaAutoDispatchTest`, `GeneratePreAccountSiteJobTest`, `ApproveEarlyAccessBuildJobTest`.

- [ ] **Step 7: Commit**

```bash
git add app/Jobs/PreAccount/GeneratePreAccountSiteJob.php \
        app/Jobs/PreAccount/ApproveEarlyAccessBuildJob.php \
        tests/Feature/PreAccount/PreAccountFreshaAutoConnectTest.php
git commit -m "fix(pre-account): auto-select a Fresha menu on every build, not just staff ones — R14"
```

---

### Task 2: Mark the selection as machine-chosen, so claim can ask them to confirm

**Files:**
- Modify: `app/Services/Platforms/Strategies/Fetch/FreshaConnectFetch.php:322-329`
- Modify: `app/Http/Controllers/Api/Platforms/FreshaController.php:615-628` (`selection()`)
- Test: `tests/Feature/Platforms/FreshaAutoConnectFetchTest.php`

**Interfaces:**
- Consumes: `FreshaAutoSelector::select()`'s `matchTier` (already returned; already persisted on the auto branch).
- Produces: `payload.autoSelected` (bool, present only while a machine-chosen selection stands) and `payload.matchTier` (string|null — `exact` / `both-tokens` / `first-exact` / `last-only` / null-for-storewide). Both returned by `GET /api/platforms/fresha/selection` as siblings of `selection`.

**Two things already work — verify them, do not build them:**
1. **The marker clears itself.** Both `saveSelection()` and `saveStorewide()` persist through `ManagesIntegrationConnection::writeConnection()`, which passes `mergePayload: false` — it **replaces** the payload rather than merging. So a human pick drops `autoSelected`/`matchTier` for free. This task adds a regression test pinning that, and **no clearing code**. If you find yourself writing an `unset()`, stop: you have misread `writeConnection`.
2. `matchTier` is already persisted on the auto branch (`FreshaConnectFetch.php:328`). Only `autoSelected` is new.

**Why this task exists:** under the owner ruling the services DO publish, so `last_refresh_status` settles at `ok`, not `action_needed`. The existing "Action needed" prompt therefore will not fire, and the "prompt them at claim" half of the ruling has nothing to hang on. `matchTier` is already persisted on the auto branch but is not exposed anywhere and does not distinguish "storewide because no match" from a dashboard storewide the owner chose deliberately. `autoSelected` is that discriminator.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Platforms/FreshaAutoConnectFetchTest.php`:

```php
it('marks an auto-chosen selection so the owner can be asked to confirm it', function () {
    $user = User::factory()->create([
        'status' => 'unclaimed',
        'display_name' => 'Simon Doyle',
        'first_name' => 'Simon',
        'last_name' => 'Doyle',
    ]);

    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/anseo-studio-v0v92jna', 'connectMode' => 'auto'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    $this->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('fetchMenu')->andReturn([
            'storeName' => 'Anseo Studio',
            'team' => [],                       // no team -> matcher cannot match -> storewide
            'services' => [[
                'serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null,
                'price' => 'A$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
            ]],
        ]);
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
    });

    app(ConnectFetchJob::class, ['connectionId' => (string) $connection->id, 'platform' => 'fresha'])->handle();

    $payload = $connection->fresh()->payload;

    expect($payload['selection']['mode'])->toBe('storewide')
        ->and($payload['autoSelected'])->toBeTrue()
        ->and($payload['matchTier'])->toBeNull();
});
```

> **Note for the implementer:** `FreshaAutoConnectFetchTest.php` already has a `beforeEach` with the right `setup*Table()` helpers and `Http::fake()`. Reuse it — do not add a second one. If `ConnectFetchJob`'s constructor signature in this repo differs from the `app(...)` form above, instantiate it the way the sibling tests in that same file already do; match the file, do not invent a call style.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Platforms/FreshaAutoConnectFetchTest.php`
Expected: FAIL — `Undefined array key "autoSelected"`.

- [ ] **Step 3: Stamp the marker**

In `app/Services/Platforms/Strategies/Fetch/FreshaConnectFetch.php`, the return at the end of `fetchStorewide()` currently reads:

```php
        return [
            ...$next,
            'url' => $url,
            'selection' => $selection,
            'raw' => ['services' => $rawServices],
            ...($auto ? ['matchTier' => $matchTier] : []),
        ];
```

Replace the last line with:

```php
            // autoSelected rides WITH matchTier and only on the auto branch: it is
            // the discriminator the dashboard needs at claim time. matchTier alone
            // cannot carry it — a null tier means "storewide because nothing
            // matched", which is indistinguishable from a storewide the owner chose
            // deliberately in the picker. Cleared by saveSelection() the moment a
            // human confirms, so it never outlives the guess it describes.
            ...($auto ? ['matchTier' => $matchTier, 'autoSelected' => true] : []),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Platforms/FreshaAutoConnectFetchTest.php`
Expected: PASS.

- [ ] **Step 5: Write the REGRESSION test for the marker clearing itself**

Append to the same file:

```php
it('drops the auto-selected marker once the owner picks for themselves', function () {
    $user = User::factory()->create(['status' => 'active']);
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/anseo-studio-v0v92jna',
            'autoSelected' => true,
            'matchTier' => null,
            'selection' => ['url' => 'https://www.fresha.com/a/anseo-studio-v0v92jna',
                'storeName' => 'Anseo Studio', 'mode' => 'storewide', 'employee' => null,
                'services' => [], 'hiddenServiceIds' => []],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $this->actingAsUser($user)
        ->postJson('/api/platforms/fresha/selection/storewide')
        ->assertSuccessful();

    $payload = $connection->fresh()->payload;

    // No production code makes this pass: writeConnection() passes
    // mergePayload: false and REPLACES the payload, so the marker cannot
    // survive a human pick. This test exists to keep that true — a future
    // change to merge-on-write would silently leave the dashboard prompting
    // forever to confirm a choice the owner already made.
    expect($payload)->not->toHaveKey('autoSelected');
    expect($payload)->not->toHaveKey('matchTier');
});
```

> **Note for the implementer:** `POST /api/platforms/fresha/selection/storewide` (`saveStorewide`) is used here rather than `POST /selection` (`saveSelection`) because the latter needs a live scrape mocked to resolve a real `employeeId`, which is orthogonal to what this test proves. `actingAsUser` is indicative — confirm the Supabase-JWT helper's real name in `tests/Pest.php` and use whatever the sibling Fresha controller tests use. Both actions go through `writeConnection`, so either proves the property.

- [ ] **Step 6: Run test to verify it PASSES immediately**

Run: `./vendor/bin/pest tests/Feature/Platforms/FreshaAutoConnectFetchTest.php`
Expected: **PASS on the first run.** This is a characterisation test, not a TDD red step — the behaviour already holds. If it FAILS, `writeConnection` is not doing what this plan claims: stop and re-read `ManagesIntegrationConnection::writeConnection()` before writing any clearing code.

- [ ] **Step 7: Surface both keys to the dashboard**

In `app/Http/Controllers/Api/Platforms/FreshaController.php`, extend the `selection()` response (line 620-627) — the keys are payload-level siblings of `selection`, NOT members of it:

```php
        $raw = $this->readConnection($user) ?? [];
        $payload = SelectionPayload::fromArray($raw);

        return $this->success([
            'selection' => $payload->selection !== null
                ? (new FreshaSelectionResource($payload->selection->toArray(), (string) $user->id))->resolve()
                : null,
            // Pending (Google-seeded) connections have a url but no selection — the
            // dashboard uses it to show "Finish setup" and open the picker.
            'url' => $payload->url,
            // A selection FreshaAutoSelector chose, not the owner. Siblings of
            // `selection` rather than members of it because FreshaSelectionResource
            // renders the inner selection array and never sees the payload — and
            // because these describe HOW the choice was made, not what it is.
            //
            // OWNER-FACING ONLY. Never add either key to
            // PublicIntegrationConnectionResource::ALLOWLIST — `fresha` has no
            // public allowlist entry at all today and must not gain one here.
            'autoSelected' => (bool) ($raw['autoSelected'] ?? false),
            'matchTier' => $raw['matchTier'] ?? null,
        ]);
```

- [ ] **Step 8: Add the endpoint test**

```php
it('reports an auto-chosen selection to the owner so the dashboard can prompt', function () {
    $user = User::factory()->create(['status' => 'active']);
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/anseo-studio-v0v92jna',
            'autoSelected' => true,
            'matchTier' => 'first-exact',
            'selection' => ['url' => 'https://www.fresha.com/a/anseo-studio-v0v92jna',
                'storeName' => 'Anseo Studio', 'mode' => 'employee',
                'employee' => ['employeeId' => 'e1', 'displayName' => 'Simon Doyle'],
                'services' => [], 'hiddenServiceIds' => []],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $this->actingAsUser($user)
        ->getJson('/api/platforms/fresha/selection')
        ->assertSuccessful()
        ->assertJsonPath('autoSelected', true)
        ->assertJsonPath('matchTier', 'first-exact');
});
```

> **Confirmed 2026-08-19:** `ApiController::success()` is `response()->json($data)` with **no** `data.` wrapper, so the assertions above are top-level. Do not add a `data.` prefix.

- [ ] **Step 9: Pin that these keys never reach the public wire**

Add to `tests/Feature/Platforms/FreshaAutoConnectFetchTest.php`:

```php
it('never exposes the auto-selection marker on the public wire', function () {
    $allowlist = (new ReflectionClass(PublicIntegrationConnectionResource::class))
        ->getConstant('ALLOWLIST');

    // fresha must not gain a public allowlist entry; if it ever does, neither of
    // these two keys may be in it.
    $fresha = $allowlist['fresha'] ?? [];

    expect($fresha)->not->toContain('autoSelected');
    expect($fresha)->not->toContain('matchTier');
});
```

> **Warning:** `expect(...)->not->toContain(...)` on an EMPTY array passes vacuously, and this repo has a recorded trap about exactly that (`reference_negated_tocontain_is_vacuous`). That is acceptable *here* and only here, because the assertion's whole purpose is "this must stay empty-or-without-these-keys" — but state that in a comment so a later reader does not mistake it for a positive proof. Keep the two expectations on separate lines: a chained `expect()` aborts at the first failure, so one run would otherwise prove only one of them.

- [ ] **Step 10: Run the full Fresha + resource suites**

Run: `./vendor/bin/pest tests/Feature/Platforms`
Expected: PASS, including `PublicAllowlistCoverageTest` if it lives elsewhere — if it fails, do NOT add a `fresha` allowlist entry to satisfy it; read why it changed.

- [ ] **Step 11: Commit**

```bash
git add app/Services/Platforms/Strategies/Fetch/FreshaConnectFetch.php \
        app/Http/Controllers/Api/Platforms/FreshaController.php \
        tests/Feature/Platforms/FreshaAutoConnectFetchTest.php
git commit -m "feat(fresha): mark machine-chosen selections so claim can ask the owner to confirm"
```

---

### Task 3: Close the aggregator-unroll lane

**Files:**
- Modify: `app/Routing/SourceReconciler.php` (after a Place intent is applied)
- Test: `tests/Feature/PreAccount/PreAccountFreshaAutoConnectTest.php` (extend)

**Interfaces:**
- Consumes: `BuildsAutoSyncFindings::dispatchAutoBookingConnect(string $userId): void` — protected on the trait, re-queries the Fresha row by `user_id`, claims the daily budget itself, and is a no-op when the budget is exhausted or no Fresha row exists.
- Produces: nothing new.

**Why this task exists:** Task 1 only reaches Fresha links found **directly in the Instagram bio**. A Fresha link found by unrolling a Linktree goes through `LinkInBioScanJob` → `LinkInBioImporter` → `SourceReconciler`, where `autoConnectBooking` is documented as **VESTIGIAL** (`LinkInBioScanJob:52-56`) and no auto-connect is dispatched at all. Without this task, a pre-account build whose salon link sits behind an aggregator still publishes nothing — the same R14 symptom, reached by a different road.

**This is the second of the design's three `true` sites.** The v3 design's construction-site table marks `LinkInBioScanJob` **true**; the 2026-08-18 migration to `LinkRoutingService` made that flag inert without replacing it. Restoring it on the new lane is finishing the migration, not widening the design.

**The discriminator here is `$user->isUnclaimed()`, not a caller flag.** That is deliberate and is the better gate: the flag has to be threaded correctly through every caller (and one already lost it), whereas "this site has no owner in front of it" is a fact the reconciler can read directly, and it stops being true the moment the site is claimed — which is exactly when a picker becomes the right answer again.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/PreAccount/PreAccountFreshaAutoConnectTest.php`:

```php
use App\Jobs\Platforms\ConnectFetchJob;
use Illuminate\Support\Facades\Queue;

it('auto-connects a Fresha link that arrives via an aggregator unroll for an unclaimed user', function () {
    Queue::fake();

    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Simon Doyle']);

    // Route the link the way the aggregator lane does — through the routing
    // service, NOT through LinkRouter (that is Task 1's lane).
    app(App\Routing\LinkRoutingService::class)->route(
        'https://www.fresha.com/a/anseo-studio-v0v92jna',
        new RoutingContext(user: $user, origin: 'link_in_bio'),
    );

    Queue::assertPushed(ConnectFetchJob::class);
});

it('does NOT auto-connect for a claimed user — they get the picker', function () {
    Queue::fake();

    $user = User::factory()->create(['status' => 'active', 'display_name' => 'Simon Doyle']);

    app(App\Routing\LinkRoutingService::class)->route(
        'https://www.fresha.com/a/anseo-studio-v0v92jna',
        new RoutingContext(user: $user, origin: 'link_in_bio'),
    );

    Queue::assertNotPushed(ConnectFetchJob::class);
});
```

> **Note for the implementer:** `LinkRoutingService::route(string $url, RoutingContext $context): array` is confirmed (`app/Routing/LinkRoutingService.php:43`). `RoutingContext`'s **constructor parameter names are not** — open `app/Routing/RoutingContext.php` and build it the way `LinkInBioImporter` does, then mirror that. The two tests' *intent* — unclaimed dispatches, claimed does not — is what matters and must not change. The negative test is the important half: it is what stops this task from taking the picker away from real users.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PreAccount/PreAccountFreshaAutoConnectTest.php`
Expected: FAIL on the first test — no `ConnectFetchJob` pushed.

- [ ] **Step 3: Dispatch from the reconciler**

In `app/Routing/SourceReconciler.php`, `use App\Services\Platforms\Concerns\BuildsAutoSyncFindings;` on the class, and after a `Verdict::Place` intent has been applied and its connection written (inside the same method that returns `connection_id`, AFTER the transaction commits — not inside it), add:

```php
        // An unclaimed pre-account site has nobody to answer "whose menu is this?",
        // so a Fresha connection placed here would otherwise sit selection-less and
        // publish nothing (R14). FreshaAutoSelector answers it: the account holder's
        // own menu when the staff matcher identifies them, storewide when it cannot.
        //
        // Gated on the USER's claim state rather than a caller flag: the aggregator
        // lane's $autoConnectBooking is vestigial (LinkInBioScanJob:52-56), and a
        // fact the reconciler can read cannot be lost in threading. A claimed owner
        // is present and keeps their picker.
        //
        // AFTER the transaction, deliberately: dispatchAutoBookingConnect re-queries
        // the row it just wrote and enqueues a job that must not run against a
        // rolled-back write.
        if ($verdict === Verdict::Place
            && $placement->surfaceKey === 'fresha.book'
            && $user->isUnclaimed()
            && (bool) config('partna.connect.auto_booking.enabled', true)
        ) {
            $this->dispatchAutoBookingConnect((string) $user->id);
        }
```

> **Implementer checks before writing this:**
> 1. **Surface key confirmed:** `'fresha.book'` — `app/Catalog/Definitions/Fresha.php:38` (`SurfaceBuilder::for('fresha.book')`). Prefer the catalog constant over the string literal if one is exposed; a wrong key makes this silently never fire, which is the same failure mode as the bug.
> 2. **Confirm the commit boundary.** `SourceReconciler::reconcile()` wraps the intent write + apply + settle in ONE transaction (LIFE-16, see its comment at `app/Routing/SourceReconciler.php:76-80`). This dispatch must land outside it. If the method structure makes that awkward, return a flag and dispatch at the caller rather than moving the transaction.
> 3. `dispatchAutoBookingConnect` is `protected` on the trait and claims the daily budget itself — do not add a second budget check.
> 4. `$user->isUnclaimed()` is the canonical predicate (`app/Models/Core/User/User.php:199-203`) — it lower-cases and trims `status`. Do not compare `status === 'unclaimed'` by hand.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/PreAccount/PreAccountFreshaAutoConnectTest.php`
Expected: PASS, both the unclaimed and the claimed case.

- [ ] **Step 5: Run the routing suite for regressions**

Run: `./vendor/bin/pest tests/Feature/Routing tests/Feature/Platforms`
Expected: PASS. `SourceReconciler` is the single connection writer for the routing lane — a break here is wide.

- [ ] **Step 6: Commit**

```bash
git add app/Routing/SourceReconciler.php tests/Feature/PreAccount/PreAccountFreshaAutoConnectTest.php
git commit -m "fix(routing): auto-connect a placed Fresha link for unclaimed users — closes the aggregator lane"
```

---

### Task 4: Verify on dev and close R14

**Files:**
- Modify: `docs/reviews/2026-08-19-instagram-build-wave-RESULTS.md` (§R14)

- [ ] **Step 1: Run the full suite**

Run: `composer test`
Expected: PASS. If `--filter` is needed, note that `composer test --filter` is broken in this repo — call `./vendor/bin/pest` directly.

- [ ] **Step 2: Lint**

Run: `php artisan pint --test`
Expected: PASS. **`pint` without `--test` FIXES and then reports "passed"** — the CI gate is `--test`.

- [ ] **Step 3: Merge to development and deploy**

Follow `docs/deploy/routine-deploy.md`. No migration accompanies this change.

- [ ] **Step 4: Prove it on a real build**

Run one pre-account build against a Fresha-linked Instagram account (`simondoylehair` / `anseo-studio-v0v92jna` is the known-good fixture), then re-run the verification query from the Background section.

Expected, and all four must hold:
- `ingest.sources.selection_ref` is **non-null** for the new Fresha row (an employee id, or the literal `storewide`).
- the latest run has `records_seen > 0` and **no** `no_selection` note in `detail`.
- `site.platform_connections.payload` carries `autoSelected: true` and a `matchTier`.
- `GET /api/public/profiles/simondoylehair` → `data.profile.pools.services.items` is non-empty.

Record the actual `match_tier` distribution from the `fresha.auto_selection` log lines — `FreshaStaffMatcher`'s tier comment says that distribution is the only evidence that would justify revisiting the "no tier restriction" decision.

- [ ] **Step 5: Update the finding**

Rewrite §R14 in `docs/reviews/2026-08-19-instagram-build-wave-RESULTS.md` to record: the root cause (`selection_ref` null → connector short-circuits before any HTTP call, `no_selection` note in `ingest.runs.detail`), the owner ruling, what shipped, and the dev evidence. **Do not tick a checkbox that is not true** — a ticked box means "resolved as an open question", and a partial tick blocks auto-archive.

- [ ] **Step 6: Reconcile the spec with the code — this is the root cause of the whole episode**

`docs/superpowers/specs/2026-08-10-fresha-auto-route-selection-design.md` says "**All decisions are closed. Nothing in this spec requires a judgement call at implementation time**", and its construction-site table (`:103-108`) marks all three Instagram-origin sites `true`. The code that shipped alongside it narrowed that to staff builds only, and the spec was never amended. That silent disagreement is why the same defect was re-found nine days later as R14, and why this plan initially recorded "there is no separate design doc."

Add a dated `[v4]` note to the design doc recording:
- the 2026-08-11 narrowing to `built_by_staff_id !== null`, that it went beyond the table, and that no doc recorded it;
- the 2026-08-18 migration that made `LinkInBioScanJob`'s flag vestigial;
- the 2026-08-19 owner ruling restoring the spec's scope, and the `autoSelected` prompt added on top of it.

Do **not** silently edit the table to match today's code — the point is that a reader can see the divergence happened and when.

- [ ] **Step 7: Commit**

```bash
git add docs/reviews/2026-08-19-instagram-build-wave-RESULTS.md \
        docs/superpowers/specs/2026-08-10-fresha-auto-route-selection-design.md
git commit -m "docs: close R14 with dev evidence; reconcile the auto-route spec with what shipped"
```

---

## Out of scope — stated so it is a decision, not an oversight

- **The dashboard/claim UI itself.** This plan makes `autoSelected` + `matchTier` available on the owner-facing API. The prompt that reads them ("we picked Simon Doyle's menu — is that right?") is frontend work in a separate repo and is **required for the owner ruling to be fully honoured**. Without it the backend guesses and never asks.
- **`services_max` overflow.** `FreshaAutoSelector` already logs `fresha.auto_selection.exceeds_listing_cap` when a storewide projection exceeds `partna.limits.pagination.services_max` (500). Under this ruling that now happens on public unclaimed pages routinely, and past the cap the dashboard truncates so the owner cannot reach the tail to delete it. Pre-existing; worth its own ticket.
- **R13** (`probe_unreachable` on real product pages) — unrelated, separate finding.
- **Prod.** Production lacks the `content`, `ingest`, `routing` and `catalog` schemas entirely. None of this exists there and none of it can be deployed there until the reconciliation in `docs/superpowers/plans/2026-08-17-prod-schema-reconciliation.md` lands.
