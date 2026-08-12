# Billed-Effect Driver Seam (Slice 0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `$io->effect(...)` actually execute a billed vendor call — ledgered, budgeted and retryable — so `GoogleBusinessConnector` and `InstagramConnector` can land records instead of hitting the unconditional throw at `app/Ingest/Runtime/HttpIo.php:116`.

**Architecture:** A `BilledEffectDriver` registry replaces the throw. Each driver adapts one `(kind, name)` pair onto an existing service and returns a `BilledEffectResult` carrying an explicit `Answered` / `NoAnswer` outcome, so a vendor outage is never cached as "this place has no data" for the 7-day freshness window. Two new exception types give the `EffectLedger` the two extra endings it needs: `EffectNotAttempted` (no request left the process → the claim is removed, the digest stays retryable) and `EffectNoAnswer` (a request went out and we did not get an answer → settle `failed`, return rather than rethrow, so the stream reads `unavailable` rather than `error`).

**The single axis every ending turns on: did a request leave the process?** Not "did we get data", not "whose fault was it". If nothing was sent, nothing can have been charged, so the claim is released and the digest is instantly retryable. If something was sent and we did not get a clean answer, we cannot know whether the vendor billed us — and refusing to guess is the entire reason this ledger exists.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4 (SQLite in-memory), Postgres via Supabase.

**Source spec:** `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` §5.

---

## Global Constraints

- **Never create Laravel migration files.** This slice needs **no migration at all** — `ingest.effects.status` already permits every value used here (`supabase/migrations/20260727130000_ingest_schema.sql:181`) and `effects_kind_check` already permits `'actor'` and `'api'` (`20260729150002_effects_kind_check_not_valid.sql`).
- **The `Http` facade is the only permitted outbound transport.** Both drivers delegate to services that already own their transport (`GoogleBusinessService` uses `Http::` with a hardcoded host; `InstagramScraper` uses `Http::` against `api.apify.com`). **Add no new outbound call in this slice** — `tests/Feature/Architecture/OutboundHttpGuardTest.php` runs as its own CI job.
- **Business logic in `Services/`, not controllers.** Drivers live under `app/Ingest/Runtime/Effects/` — they are runtime adapters, not services.
- 4-space indent, LF. Comments explain WHY, not what. No banners, no restatements.
- **Tests run SQLite, prod is Postgres.** Every constraint-bound write must be checked against `supabase/migrations/` DDL, not just a green suite.
- `composer test` before any task is called done. `php artisan pint` on touched files.
- Branch: `feature/billed-effect-driver-seam` off `development`.
- **Do not** provision streams, project `kind='media'`, or change any pool or wire shape. Spec §5.5 — slice 0 ends when a billed effect can execute and is ledgered.

---

## Deviations from the spec, and why

Read this before Task 1. Each is a correction found by reading the code the spec describes.

### D1 — Google's driver must return the RAW Places response, not `fetchPlaceDetails()`

Spec §5.2 says `PlacesDetailsDriver` delegates to `GoogleBusinessService::fetchPlaceDetails()`. That method returns `mapDetails()` output. `GoogleBusinessConnector::pull()` reads the **raw** Places (New) shape:

| Connector reads | `fetchPlaceDetails()` returns | Result if wired directly |
|---|---|---|
| `displayName.text` (`GoogleBusinessConnector.php:144`) | `name` | `profile` stream yields `Note('no_profile_fields')`, lands **nothing** |
| `photos[].name` (`:261`) | `photos[].ref` | every photo dropped → `Note('no_media')`, lands **nothing** |
| `reviews[].text.text`, `reviews[].authorAttribution.*` (`:246-249`) | `reviews[].text` (flat string), `reviews[].author` | reviews land with a hashed fallback id and **null author/text** |

The third row is the dangerous one: the `when_unclaimed` reviewer-PII redaction declared in the manifest (`:103-108`) would become vacuous — it would strip keys that are already null — so the lane would *look* compliant while silently dropping attribution for claimed accounts too.

**Therefore:** Task 4 extracts `fetchPlaceDetailsRaw()` returning the undecorated response; `fetchPlaceDetails()` keeps its exact public contract by calling it and mapping. The driver uses the raw variant. This also avoids firing `resolvePhotoUrls()`' up-to-15 extra billed media calls per run, which belong to slice 1.

### D2 — `InstagramScraper` does NOT enforce the Apify cap

Spec §5.1 says `InstagramScraper` "carries its own per-user cooldown (`partna.instagram.apify_cooldown_seconds`) and daily cap". It does not. It claims `ApifyBudget` in exactly one place — the thin-profile retry (`InstagramScraper.php:62`) — and its own comment says so: *"This class does not otherwise claim Apify budget — the controllers do."* The cap lives in `InstagramController::guardApifyBudget()` (`:379-387`), which also documents that there is **intentionally no per-user cooldown**. `partna.instagram.apify_cooldown_seconds` is config with no live reader.

The ingest lane has no controller in front of it. **`InstagramActorDriver` must claim `ApifyBudget::tryClaim('instagram')` itself**, or every scheduled run spends outside the daily cap. `InstagramConnector`'s own docblock (`:26-30`) already assumes exactly this — though it points at `config partna.limits.apify / .platforms.instagram` rather than naming the cooldown key directly. The substance holds: the cooldown it describes has no reader anywhere.

### D3 — `EffectNotAttempted` replaces the spec's `BudgetRefused`, and removes the claim rather than settling `refused`

Two changes to spec §5.3, one structural and one about naming.

**Structural.** The spec settles a budget refusal as `status='refused'` and teaches `verdictFor()` that such a row does not block a fresh claim. But `verdictFor()` returns early on `settled_at !== null` (`EffectLedger.php:137`), and `digest` is the PRIMARY KEY — so "does not block" needs either a delete or a conditional reclaim-UPDATE that overwrites the very audit trail the design was protecting. This plan deletes instead, on the path where deletion is provably safe.

**Naming.** The spec's `BudgetRefused` names the *reason*; what the ledger needs to know is the *fact* — **no request left the process**. A missing Places API key and a missing Apify token are exactly as pre-vendor-call as a budget denial, and settling either as `failed` would lock every affected digest for the rest of the freshness window over a config typo, then keep it locked after the typo was fixed. One type, named for the fact it asserts:

```
EffectRefused              (existing — claim RETAINED, may follow a vendor call)
└── EffectNotAttempted     (new      — claim RELEASED, provably no request sent)
```

Thrown by a driver only, for a denied budget claim or an unconfigured credential. `once()` deletes the row it just inserted and rethrows. Consequences:

- The rethrow is an `EffectRefused` subclass, so `RunExecutor:87` already handles it → `budget_skipped`, health `degraded`, existing backoff. **Zero `RunExecutor` changes.**
- A plain `EffectRefused` raised from inside the closure (the spec's hypothetical future "Apify bills on start, then polls an off-manifest host") falls through to the generic catch and keeps today's behaviour: settled `failed`, claim retained. The spec's objection to reusing `EffectRefused` is answered by the subclass, not by a second unrelated type.
- No `verdictFor()` change, no reclaim logic, no new status value in play.
- The DELETE is guarded `->where('status', 'claimed')->whereNull('settled_at')`, matching `markAbandoned()`'s pattern (`EffectLedger.php:198-202`). The current concurrency window is benign — only the inserting worker reaches the closure — but an unguarded DELETE on a money ledger makes the "provably no charge" contract documentation rather than enforcement.

`EffectLedger`'s class docblock must be amended to name this as the one deletion, so the `reconcileAbandoned()` "never deletes" contract stays honest.

### D4 — The kill switch does not need to gate `SourceProvisioner`

Spec §5.3 says the switch "must also gate `SourceProvisioner`'s stream provisioning, or streams get provisioned and dispatched". Verified false on two counts: `SourceProvisioner` never touches `ingest.streams` at all (`RunExecutor::ensureStream()` does, at run time), and `SourceProvisioner::schedulable()` (`:122-125`) already returns `$manifest->cost === CostClass::Free`, so both billed sources are created `auto_sync = false` and `SourceScheduler::claimDue()` (`:87`) never claims them. No provisioner change is in scope.

### D5 — A `NoAnswer` returns a `failed` verdict instead of rethrowing

If `runBilledEffect()` simply threw on `NoAnswer`, `once()`'s generic catch would settle `failed` **and rethrow**, and `RunExecutor`'s catch-all would mark the stream `error` + `report()` it. `error` means "our bug"; a Google 429 is `unavailable`. Adding a typed `catch (EffectNoAnswer)` that settles and **returns** `['status' => 'failed', ...]` lets the connector's existing non-ok fold produce `Unavailable` — the honest outcome, with the existing suppression backoff. Nightwatch coverage is unaffected: `GoogleBusinessService` already `report()`s both the transport failure and the non-2xx, and `InstagramScraper` reports 5xx.

The same reasoning applies to `PlacesBudgetExhaustedException` escaping `fetchPlaceDetailsRaw()` mid-loop: `PlacesDetailsDriver` catches it and returns `NoAnswer` rather than letting it reach `RunExecutor`'s catch-all. A spend ceiling paging on-call as `error` is the exact misclassification D5 exists to prevent, and the settled `failed` row still retains the claim, which is what "a request already went out" requires.

### D6 — A `NoAnswer` is NOT retryable inside the freshness window, and spec §5.3 is wrong to say it is

Spec §5.3 ends "`NoAnswer` settles `failed` and is retryable." It is not. `verdictFor()` (`EffectLedger.php:153-154`) returns any settled non-`ok` row as `['status' => $row->status, 'cached' => true]` and never re-runs the effect. A settled `failed` digest is inert until the freshness bucket rolls — up to seven days, averaging ~3.5, since `digestFor()` buckets on `intdiv(now, 604800)` rather than counting from the failure.

**This plan keeps that behaviour and corrects the spec instead**, because it is the ledger's designed answer to an unknown charge, stated in its own class docblock: *"we cannot know whether the vendor charged us, and guessing wrong costs real money either way."* A `Transport` failure is exactly that case — a client-side timeout does not mean Google did not serve and bill. Deleting the row to make it retryable would trade a stale source for a silent double-charge, which inverts the one guarantee this table exists to provide.

What makes the cost bounded rather than open-ended:

- **The no-charge causes are already carved out.** A missing credential and a denied budget claim throw `EffectNotAttempted` (D3), so the two systematic, environment-wide failure modes release the claim and retry immediately. What stays blocked is a genuine vendor outage on one specific request.
- **There is a documented operator escape hatch.** `ingest:effects --settle` / `--resolve` already exists for precisely this, and `EffectLedger`'s docblock names resolving a stuck digest as a deliberate act.
- **The window is a config knob.** `PARTNA_INGEST_EFFECT_FRESHNESS_SECONDS` bounds it, and a slice that finds seven days too coarse for billed sources can shorten it without touching this code.

**Flagging for the owner:** if a multi-day block on a transient Places 429 is unacceptable, the alternative is to treat 429 and 5xx (as distinct from `Transport`) as no-charge and release their claims — Google does not bill rejected-quota or server-error responses. That is a defensible position; it is a spend-policy call, not an engineering one, so this plan takes the conservative default and names the alternative rather than choosing it silently.

---

## File Structure

**Create**

| Path | Responsibility |
|---|---|
| `app/Ingest/Runtime/Effects/BilledEffectDriver.php` | The two-method contract: `supports()`, `run()` |
| `app/Ingest/Runtime/Effects/BilledEffectContext.php` | Readonly VO: kind, name, input, runId, sourceId, userId |
| `app/Ingest/Runtime/Effects/BilledEffectOutcome.php` | Enum: `Answered` \| `NoAnswer` |
| `app/Ingest/Runtime/Effects/BilledEffectResult.php` | Readonly VO: outcome + data + reason |
| `app/Ingest/Runtime/Effects/BilledEffectDriverRegistry.php` | `(kind, name)` → driver, or null |
| `app/Ingest/Runtime/Effects/PlacesDetailsDriver.php` | `('api', 'places.details')` → `GoogleBusinessService` |
| `app/Ingest/Runtime/Effects/InstagramActorDriver.php` | `('actor', 'instagram')` → `ApifyBudget` + `InstagramScraper` |
| `app/Ingest/Runtime/EffectNotAttempted.php` | `extends EffectRefused`; no request left the process → claim released |
| `app/Ingest/Runtime/EffectNoAnswer.php` | A request went out, no answer came back → claim retained |
| `app/Services/Platforms/PlaceDetailsResult.php` | Raw Places fetch outcome (mirrors `ProfileFetchResult`) |
| `app/Services/Platforms/PlaceDetailsFailure.php` | Why a raw Places fetch produced no place |
| `tests/Unit/Ingest/BilledEffectDriverRegistryTest.php` | |
| `tests/Feature/Ingest/BilledEffectLedgerOutcomesTest.php` | |
| `tests/Feature/Ingest/BilledEffectDispatchTest.php` | |
| `tests/Feature/Platforms/PlaceDetailsRawFetchTest.php` | |
| `tests/Feature/Ingest/PlacesDetailsDriverTest.php` | |
| `tests/Feature/Ingest/InstagramActorDriverTest.php` | |
| `tests/Feature/Ingest/BilledEffectRunOutcomesTest.php` | Drives the real `RunExecutor` — pins D3's and D5's folds |

**Modify**

| Path | Change |
|---|---|
| `app/Ingest/Runtime/HttpIo.php:23-29, 81-123` | `+drivers`, `+userId` ctor params; kill switch; registry dispatch |
| `app/Ingest/Runtime/EffectLedger.php:11-24, 100-131` | Two typed catches; docblock amendment |
| `app/Ingest/Runtime/RunExecutor.php:83, 400-409` | Thread `$source['user_id']` into `ioFor()` |
| `app/Services/Platforms/GoogleBusinessService.php:209-282` | Extract `fetchPlaceDetailsRaw()`; `fetchPlaceDetails()` delegates |
| `app/Services/Platforms/InstagramScraper.php` | Add `isConfigured()` (token + resolvable actor adapter) |
| `app/Providers/AppServiceProvider.php:102+` | Bind the registry singleton |
| `config/partna.php` (`'ingest' =>`, ~line 988) | `billed_effects_enabled` |
| `.env.example` | `PARTNA_INGEST_BILLED_EFFECTS_ENABLED=false` |
| `tests/Feature/Ingest/HttpIoPostSsrfTest.php:29` | New required ctor arg |
| `tests/Feature/Ingest/RunExecutorClaimGateTest.php:16-21` | Stale comment |
| `tests/Feature/Ingest/SourceProvisionerTest.php:336-339` | Stale comment |
| `app/Ingest/SourceProvisioner.php:115-126` | Stale docblock |
| `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` | Slice 0 checkpoint |

---

### Task 1: The driver contract and registry

Pure value objects and a lookup. No wiring, no vendor calls.

**Files:**
- Create: `app/Ingest/Runtime/Effects/BilledEffectDriver.php`
- Create: `app/Ingest/Runtime/Effects/BilledEffectContext.php`
- Create: `app/Ingest/Runtime/Effects/BilledEffectOutcome.php`
- Create: `app/Ingest/Runtime/Effects/BilledEffectResult.php`
- Create: `app/Ingest/Runtime/Effects/BilledEffectDriverRegistry.php`
- Test: `tests/Unit/Ingest/BilledEffectDriverRegistryTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `BilledEffectDriver::supports(string $kind, string $name): bool`
  - `BilledEffectDriver::run(BilledEffectContext $ctx): BilledEffectResult`
  - `new BilledEffectContext(string $kind, string $name, array $input, ?string $runId, ?string $sourceId, ?string $userId)` — all public readonly
  - `BilledEffectResult::answered(?array $data): self`, `BilledEffectResult::noAnswer(string $reason): self`; public readonly `$outcome`, `$data`, `$reason`
  - `BilledEffectOutcome::Answered`, `BilledEffectOutcome::NoAnswer`
  - `BilledEffectDriverRegistry::for(string $kind, string $name): ?BilledEffectDriver`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Ingest/BilledEffectDriverRegistryTest.php`:

```php
<?php

use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectDriver;
use App\Ingest\Runtime\Effects\BilledEffectDriverRegistry;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\BilledEffectResult;

function fakeDriver(string $kind, string $name, string $marker): BilledEffectDriver
{
    return new class($kind, $name, $marker) implements BilledEffectDriver
    {
        public function __construct(
            private string $kind,
            private string $name,
            private string $marker,
        ) {}

        public function supports(string $kind, string $name): bool
        {
            return $kind === $this->kind && $name === $this->name;
        }

        public function run(BilledEffectContext $ctx): BilledEffectResult
        {
            return BilledEffectResult::answered(['marker' => $this->marker]);
        }
    };
}

it('dispatches on the (kind, name) pair, not on kind alone', function () {
    $registry = new BilledEffectDriverRegistry([
        fakeDriver('actor', 'instagram', 'ig'),
        fakeDriver('actor', 'menu', 'menu'),
        fakeDriver('api', 'places.details', 'places'),
    ]);

    $ctx = new BilledEffectContext('actor', 'menu', [], null, null, null);

    expect($registry->for('actor', 'menu')?->run($ctx)->data)->toBe(['marker' => 'menu'])
        ->and($registry->for('actor', 'instagram')?->run($ctx)->data)->toBe(['marker' => 'ig'])
        ->and($registry->for('api', 'places.details')?->run($ctx)->data)->toBe(['marker' => 'places']);
});

it('returns null for an unmatched pair so the caller can throw', function () {
    $registry = new BilledEffectDriverRegistry([fakeDriver('actor', 'instagram', 'ig')]);

    expect($registry->for('actor', 'menu'))->toBeNull()
        ->and($registry->for('ai', 'instagram'))->toBeNull()
        ->and($registry->for('actor', 'Instagram'))->toBeNull();
});

it('returns the first driver that claims a pair', function () {
    $registry = new BilledEffectDriverRegistry([
        fakeDriver('api', 'places.details', 'first'),
        fakeDriver('api', 'places.details', 'second'),
    ]);

    $ctx = new BilledEffectContext('api', 'places.details', [], null, null, null);

    expect($registry->for('api', 'places.details')?->run($ctx)->data)->toBe(['marker' => 'first']);
});

it('carries an outcome distinct from its data', function () {
    expect(BilledEffectResult::answered(null)->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and(BilledEffectResult::answered(null)->data)->toBeNull()
        ->and(BilledEffectResult::noAnswer('google timed out')->outcome)->toBe(BilledEffectOutcome::NoAnswer)
        ->and(BilledEffectResult::noAnswer('google timed out')->reason)->toBe('google timed out');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Ingest/BilledEffectDriverRegistryTest.php`
Expected: FAIL — `Class "App\Ingest\Runtime\Effects\BilledEffectDriver" not found`.

- [ ] **Step 3: Write the implementation**

`app/Ingest/Runtime/Effects/BilledEffectOutcome.php`:

```php
<?php

namespace App\Ingest\Runtime\Effects;

/**
 * Whether the vendor actually responded — NOT whether we got data.
 *
 * The distinction is load-bearing because `partna.ingest.effect_freshness_seconds`
 * defaults to seven days: settling a Places 429 or an Apify timeout as ok-with-null
 * would cache "this place has no data" for a week, and a misconfigured API key would
 * cache it for every place at once.
 */
enum BilledEffectOutcome
{
    /** The vendor answered. `data === null` means it answered "there is nothing here". */
    case Answered;

    /** We never got a response — outage, timeout, missing credential. Retryable. */
    case NoAnswer;
}
```

`app/Ingest/Runtime/Effects/BilledEffectResult.php`:

```php
<?php

namespace App\Ingest\Runtime\Effects;

/**
 * What a driver returns. Deliberately not a bare array: the caller must be able
 * to tell an empty answer from an absent one (see BilledEffectOutcome).
 */
final readonly class BilledEffectResult
{
    /** @param array<int|string, mixed>|null $data */
    private function __construct(
        public BilledEffectOutcome $outcome,
        public ?array $data,
        public ?string $reason = null,
    ) {}

    /** @param array<int|string, mixed>|null $data */
    public static function answered(?array $data): self
    {
        return new self(BilledEffectOutcome::Answered, $data);
    }

    /** $reason is operator-facing: it lands in ingest.effects.meta on the failed row. */
    public static function noAnswer(string $reason): self
    {
        return new self(BilledEffectOutcome::NoAnswer, null, $reason);
    }
}
```

`app/Ingest/Runtime/Effects/BilledEffectContext.php`:

```php
<?php

namespace App\Ingest\Runtime\Effects;

/**
 * Everything a driver may know about the effect it is performing. `userId` is
 * here because both drivers spend per-user budget: PlacesBudget has a per-user
 * daily cap, and Instagram's scraper threads it for log correlation.
 */
final readonly class BilledEffectContext
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public string $kind,
        public string $name,
        public array $input,
        public ?string $runId,
        public ?string $sourceId,
        public ?string $userId,
    ) {}
}
```

`app/Ingest/Runtime/Effects/BilledEffectDriver.php`:

```php
<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;

/**
 * Performs ONE billed effect. Invoked from inside EffectLedger::once(), exactly
 * where HttpIo used to throw — so claim-first and charge-once hold by
 * construction and a driver need not know the ledger exists.
 *
 * Two rules a driver MUST honour, because the ledger's money guarantees rest on
 * them and nothing else can check them:
 *
 *   1. Throw EffectNotAttempted ONLY before the first vendor call. once() removes the
 *      claim on that exception; raising it after a request has left the process
 *      would let the same request be re-billed.
 *   2. Return NoAnswer whenever the vendor did not respond. Returning
 *      Answered(null) for an outage caches that outage as truth for the whole
 *      freshness window.
 */
interface BilledEffectDriver
{
    public function supports(string $kind, string $name): bool;

    /** @throws EffectNotAttempted before the first vendor call, and only then */
    public function run(BilledEffectContext $ctx): BilledEffectResult;
}
```

`app/Ingest/Runtime/Effects/BilledEffectDriverRegistry.php`:

```php
<?php

namespace App\Ingest\Runtime\Effects;

/**
 * (kind, name) -> driver. Matching on BOTH halves is deliberate: `actor` is
 * shared by Instagram and the three menu connectors, which have different
 * vendors, different budgets and different result shapes. A kind-only registry
 * would hand a menu scrape to the Instagram driver.
 *
 * Null for an unmatched pair is not an error here — HttpIo turns it into the
 * same throw it has always raised, which is what keeps an undeclared billed
 * effect loud instead of silently free.
 */
final class BilledEffectDriverRegistry
{
    /** @param iterable<BilledEffectDriver> $drivers */
    public function __construct(private readonly iterable $drivers = []) {}

    public function for(string $kind, string $name): ?BilledEffectDriver
    {
        foreach ($this->drivers as $driver) {
            if ($driver->supports($kind, $name)) {
                return $driver;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Ingest/BilledEffectDriverRegistryTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Format and commit**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
./vendor/bin/pint app/Ingest/Runtime/Effects tests/Unit/Ingest/BilledEffectDriverRegistryTest.php
git add app/Ingest/Runtime/Effects tests/Unit/Ingest/BilledEffectDriverRegistryTest.php
git commit -m "feat(ingest): add the billed-effect driver contract and registry

Slice 0 of the content-pool convergence programme. No behaviour change yet —
HttpIo still throws. BilledEffectResult carries an explicit Answered/NoAnswer
outcome so a vendor outage can never be cached as 'no data' for the seven-day
effect freshness window."
```

---

### Task 2: The ledger learns two more endings

`EffectLedger::once()` currently has exactly two: settle `ok`, or settle `failed` and rethrow. Slice 0 needs a pre-vendor-call refusal (claim removed, digest still retryable) and a no-answer (settled `failed`, returned rather than rethrown).

**Files:**
- Create: `app/Ingest/Runtime/EffectNotAttempted.php`
- Create: `app/Ingest/Runtime/EffectNoAnswer.php`
- Modify: `app/Ingest/Runtime/EffectLedger.php:11-24` (docblock), `:100-131` (catches)
- Test: `tests/Feature/Ingest/BilledEffectLedgerOutcomesTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces:
  - `App\Ingest\Runtime\EffectNotAttempted extends App\Ingest\Runtime\EffectRefused`
  - `App\Ingest\Runtime\EffectNoAnswer extends \RuntimeException`
  - `EffectLedger::once()` returns `['status' => 'failed', 'result' => null, 'cached' => false]` for `EffectNoAnswer`, and rethrows `EffectNotAttempted` after deleting the row.

- [ ] **Step 1: Write the failing test**

`setupIngestTables()` is an existing helper (used by `EffectLedgerTest.php`); reuse it.

Create `tests/Feature/Ingest/BilledEffectLedgerOutcomesTest.php`:

```php
<?php

use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\EffectLedger;
use App\Ingest\Runtime\EffectNoAnswer;
use App\Ingest\Runtime\EffectRefused;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupIngestTables();
});

it('removes the claim and rethrows when a driver refuses on budget', function () {
    $ledger = new EffectLedger;

    expect(fn () => $ledger->once(
        digest: 'budget-refused-digest',
        kind: 'api',
        effect: fn () => throw new EffectNotAttempted('places daily cap reached'),
        costTag: 'places.details',
    ))->toThrow(EffectNotAttempted::class);

    // No lingering row: the digest is stable for the whole freshness window, so a
    // settled row here would lock this place out for seven days over one capped day.
    expect(DB::table('ingest.effects')->where('digest', 'budget-refused-digest')->count())->toBe(0);
});

it('lets the same digest be claimed again immediately after a budget refusal', function () {
    $ledger = new EffectLedger;

    try {
        $ledger->once('retryable-digest', 'api', fn () => throw new EffectNotAttempted('capped'));
    } catch (EffectNotAttempted) {
        // expected
    }

    $second = $ledger->once('retryable-digest', 'api', fn () => ['place' => 'ok']);

    expect($second)->toBe(['status' => 'ok', 'result' => ['place' => 'ok'], 'cached' => false])
        ->and(DB::table('ingest.effects')->where('digest', 'retryable-digest')->value('status'))->toBe('ok');
});

it('keeps the claim for a plain EffectRefused raised inside the effect', function () {
    // EffectNotAttempted's no-charge guarantee is specific to it. A bare EffectRefused
    // from inside the closure could follow a vendor call (an admission failure
    // partway through a paid poll), so it must settle failed and keep the row.
    $ledger = new EffectLedger;

    expect(fn () => $ledger->once(
        digest: 'plain-refused-digest',
        kind: 'actor',
        effect: fn () => throw new EffectRefused('off-manifest host'),
    ))->toThrow(EffectRefused::class);

    $row = DB::table('ingest.effects')->where('digest', 'plain-refused-digest')->first();

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('failed')
        ->and($row->settled_at)->not->toBeNull();
});

it('settles a no-answer as failed and returns instead of rethrowing', function () {
    $ledger = new EffectLedger;

    $outcome = $ledger->once(
        digest: 'no-answer-digest',
        kind: 'api',
        effect: fn () => throw new EffectNoAnswer('places returned 503'),
    );

    expect($outcome)->toBe(['status' => 'failed', 'result' => null, 'cached' => false]);

    $row = DB::table('ingest.effects')->where('digest', 'no-answer-digest')->first();

    expect($row->status)->toBe('failed')
        ->and($row->settled_at)->not->toBeNull()
        ->and(json_decode((string) $row->meta, true)['message'])->toBe('places returned 503');
});

it('holds a settled no-answer for the freshness window rather than re-running it', function () {
    // DELIBERATE, and the counterpart to the two tests above: a request went out
    // and we did not get an answer, so we cannot know whether the vendor billed us.
    // The ledger refuses to guess. The no-CHARGE causes — a denied budget claim,
    // an unconfigured credential — throw EffectNotAttempted instead and ARE
    // retryable; see the plan's D6 for why the line is drawn there and for the
    // ingest:effects --settle escape hatch.
    $ledger = new EffectLedger;
    $ledger->once('replayed-digest', 'api', fn () => throw new EffectNoAnswer('503'));

    $ran = false;
    $second = $ledger->once('replayed-digest', 'api', function () use (&$ran) {
        $ran = true;

        return ['place' => 'never'];
    });

    expect($ran)->toBeFalse()
        ->and($second)->toBe(['status' => 'failed', 'result' => null, 'cached' => true]);
});

it('never deletes a row that is already settled', function () {
    // The DELETE is guarded on (status=claimed, settled_at IS NULL). Without the
    // predicate, any future re-entrant path that raised EffectNotAttempted after a
    // settle would destroy a money row with no trace.
    $ledger = new EffectLedger;
    $ledger->once('settled-then-refused', 'api', fn () => ['place' => 'paid for']);

    DB::table('ingest.effects')->where('digest', 'settled-then-refused')->update(['status' => 'claimed']);

    expect(DB::table('ingest.effects')->where('digest', 'settled-then-refused')->count())->toBe(1);

    // status='claimed' but settled_at is set — the guard must still refuse to delete.
    try {
        $ledger->once('settled-then-refused', 'api', fn () => throw new EffectNotAttempted('cap'));
    } catch (EffectNotAttempted) {
        // The row is settled, so once() replays its verdict and never reaches the
        // closure — which is itself the point: no delete is even attempted.
    }

    expect(DB::table('ingest.effects')->where('digest', 'settled-then-refused')->count())->toBe(1);
});

it('settles an answered-but-empty result as ok with a null result', function () {
    // A 404 from Places is an ANSWER. Settling it ok stops us re-billing a dead
    // place id on every run inside the freshness window.
    $ledger = new EffectLedger;

    $outcome = $ledger->once('empty-answer-digest', 'api', fn () => null);

    expect($outcome)->toBe(['status' => 'ok', 'result' => null, 'cached' => false])
        ->and(DB::table('ingest.effects')->where('digest', 'empty-answer-digest')->value('status'))->toBe('ok');

    expect($ledger->once('empty-answer-digest', 'api', fn () => ['unreachable']))
        ->toBe(['status' => 'ok', 'result' => null, 'cached' => true]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Ingest/BilledEffectLedgerOutcomesTest.php`
Expected: FAIL — `Class "App\Ingest\Runtime\EffectNotAttempted" not found`.

- [ ] **Step 3: Create the two exception types**

`app/Ingest/Runtime/EffectNotAttempted.php`:

```php
<?php

namespace App\Ingest\Runtime;

/**
 * A billed-effect DRIVER did not send a request at all: a denied budget claim,
 * or a credential that was never configured.
 *
 * "No request left the process" is the entire contract, and it is why
 * EffectLedger::once() may remove the claim it just took — nothing can have been
 * charged, so there is nothing to protect against a re-run. Raise this after a
 * request has gone out and that request becomes re-billable.
 *
 * Named for the fact rather than the reason (the design spec called this
 * BudgetRefused): a missing API key is exactly as pre-vendor-call as an exhausted
 * budget, and settling it 'failed' would lock every affected digest for the rest
 * of the freshness window over a config typo — and keep it locked after the typo
 * was fixed.
 *
 * Extends EffectRefused so RunExecutor's existing catch folds it to
 * 'budget_skipped'. The plain EffectRefused it inherits from keeps its own
 * broader meaning (off-manifest host, ledger refusal) and its own
 * claim-RETAINING handling — that difference is the whole point of the subclass.
 */
class EffectNotAttempted extends EffectRefused {}
```

`app/Ingest/Runtime/EffectNoAnswer.php`:

```php
<?php

namespace App\Ingest\Runtime;

/**
 * The vendor did not answer: an outage, a timeout, a credential that was never
 * configured. Distinct from "answered, and the answer is nothing".
 *
 * Raised by HttpIo when a driver returns BilledEffectOutcome::NoAnswer, and
 * settled by the ledger as 'failed' rather than 'ok' — with a seven-day
 * freshness window, an outage settled 'ok' serves "no data" as truth for a week,
 * and a missing API key would do it for every request at once.
 */
class EffectNoAnswer extends \RuntimeException {}
```

- [ ] **Step 4: Add the two typed catches to `EffectLedger::once()`**

In `app/Ingest/Runtime/EffectLedger.php`, replace the single `catch (\Throwable $e)` block at `:121-131` with three catches, in this order (`EffectNotAttempted` must precede any `EffectRefused` handling, and both must precede `\Throwable`):

```php
        } catch (EffectNotAttempted $e) {
            // The ONE deletion in this class. Safe only because EffectNotAttempted's
            // contract is "no request left the process" (see that class): nothing
            // was charged, so nothing needs protecting from a re-run. A settled row
            // would instead block this digest for the whole freshness window —
            // seven days locked out by one capped day or one config typo.
            //
            // Guarded exactly like markAbandoned()'s conditional UPDATE: a money
            // ledger should not carry an unqualified DELETE, and the predicate turns
            // "provably unsettled" from a comment into an enforced precondition.
            DB::table('ingest.effects')
                ->where('digest', $digest)
                ->where('status', 'claimed')
                ->whereNull('settled_at')
                ->delete();

            throw $e;
        } catch (EffectNoAnswer $e) {
            // A request went out and we did not get an answer, so we cannot know
            // whether the vendor billed us: the row STAYS, settled, and this digest
            // is inert until the freshness bucket rolls. That is the class contract
            // for an unknown charge, not an oversight — see the plan's D6.
            //
            // RETURNED rather than rethrown, though. A rethrow reaches RunExecutor's
            // catch-all and marks the stream 'error', which reads as our bug;
            // letting the connector see a non-ok verdict folds it to Unavailable,
            // which is what a vendor outage actually is.
            DB::table('ingest.effects')->where('digest', $digest)->update([
                'status' => 'failed',
                'settled_at' => now(),
                'meta' => json_encode(['error' => 'EffectNoAnswer', 'message' => mb_substr($e->getMessage(), 0, 500)]),
            ]);

            return ['status' => 'failed', 'result' => null, 'cached' => false];
        } catch (\Throwable $e) {
            // A failure IS settled: we know it happened and what it cost. It
            // is the UNKNOWN (process death) that must never auto-retry.
            DB::table('ingest.effects')->where('digest', $digest)->update([
                'status' => 'failed',
                'settled_at' => now(),
                'meta' => json_encode(['error' => class_basename($e), 'message' => mb_substr($e->getMessage(), 0, 500)]),
            ]);

            throw $e;
        }
```

- [ ] **Step 5: Amend the class docblock so the "never deletes" contract stays honest**

`reconcileAbandoned()`'s docblock says the ledger "NEVER sets settled_at, never deletes". That is a statement about that sweep, but the class docblock now needs the exception named. Append this paragraph to the `EffectLedger` class docblock (after the existing "This generalises GoogleBusinessEnrichJob's…" line):

```php
 * ONE path removes a row: a driver raising EffectNotAttempted from inside once(),
 * which by that class's contract happens before its first vendor call. No
 * request left the process, so there is no charge to protect and no reason to
 * hold the digest for the rest of the freshness window. Every other ending —
 * ok, failed, abandoned — settles in place, and reconcileAbandoned() still
 * never deletes anything at all.
```

`EffectNotAttempted`, `EffectNoAnswer` and `EffectLedger` all live in `App\Ingest\Runtime`, so **no `use` statements are needed** — do not add any.

- [ ] **Step 6: Run the new tests**

Run: `./vendor/bin/pest tests/Feature/Ingest/BilledEffectLedgerOutcomesTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 7: Run the existing ledger suite for regressions**

Run: `./vendor/bin/pest tests/Feature/Ingest/EffectLedgerTest.php`
Expected: PASS, unchanged count. The generic `\Throwable` arm is byte-identical to before, so nothing that previously settled `failed` may change.

- [ ] **Step 8: Commit**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
./vendor/bin/pint app/Ingest/Runtime tests/Feature/Ingest/BilledEffectLedgerOutcomesTest.php
git add app/Ingest/Runtime tests/Feature/Ingest/BilledEffectLedgerOutcomesTest.php
git commit -m "feat(ingest): teach the effect ledger budget-refusal and no-answer endings

EffectNotAttempted is thrown only before a driver's first vendor call, so once()
removes the claim rather than settling it — a settled row would lock the digest
for the full seven-day freshness window over a single capped day. It extends
EffectRefused, so RunExecutor's existing catch already folds it to
budget_skipped.

EffectNoAnswer settles failed and RETURNS, so a vendor outage surfaces as the
connector's Unavailable rather than a stream 'error' implying our own bug."
```

---

### Task 3: Wire `HttpIo` to the registry, behind a kill switch

**Files:**
- Modify: `app/Ingest/Runtime/HttpIo.php:23-29` (ctor), `:81-123` (`effect`, `runBilledEffect`)
- Modify: `app/Ingest/Runtime/RunExecutor.php:83`, `:400-409`
- Modify: `app/Providers/AppServiceProvider.php` (`register()`)
- Modify: `config/partna.php` — `'ingest' =>` block, after `effect_freshness_seconds` (~line 996)
- Modify: `.env.example`
- Modify: `tests/Feature/Ingest/HttpIoPostSsrfTest.php:29`
- Test: `tests/Feature/Ingest/BilledEffectDispatchTest.php`

**Interfaces:**
- Consumes: `BilledEffectDriverRegistry::for()`, `BilledEffectContext`, `BilledEffectResult`, `BilledEffectOutcome` (Task 1); `EffectNoAnswer`, `EffectNotAttempted` (Task 2).
- Produces:
  - `new HttpIo(Manifest $manifest, SafeUrlFetcher $fetcher, EffectLedger $ledger, BilledEffectDriverRegistry $drivers, ?string $runId = null, ?string $sourceId = null, ?string $userId = null)` — `$drivers` is the **4th, required** parameter.
  - `config('partna.ingest.billed_effects_enabled')` — bool, default false.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Ingest/BilledEffectDispatchTest.php`:

```php
<?php

use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\EffectLedger;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectDriver;
use App\Ingest\Runtime\Effects\BilledEffectDriverRegistry;
use App\Ingest\Runtime\Effects\BilledEffectResult;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\HttpIo;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupIngestTables();
    config()->set('partna.ingest.billed_effects_enabled', true);
    config()->set('partna.ingest.effect_freshness_seconds', 604800);
});

/** A driver that records what it was handed and returns whatever it was told to. */
function recordingDriver(string $kind, string $name, \Closure $behaviour): BilledEffectDriver
{
    return new class($kind, $name, $behaviour) implements BilledEffectDriver
    {
        public ?BilledEffectContext $seen = null;

        public function __construct(
            private string $kind,
            private string $name,
            private \Closure $behaviour,
        ) {}

        public function supports(string $kind, string $name): bool
        {
            return $kind === $this->kind && $name === $this->name;
        }

        public function run(BilledEffectContext $ctx): BilledEffectResult
        {
            $this->seen = $ctx;

            return ($this->behaviour)($ctx);
        }
    };
}

function ioWith(BilledEffectDriverRegistry $registry, ?string $userId = 'user-1'): HttpIo
{
    return new HttpIo(
        manifest: new Manifest(source: SourceKey::of('dispatch_test'), identifierKind: 'test', hosts: [], streams: [], cost: CostClass::Metered),
        fetcher: app(SafeUrlFetcher::class),
        ledger: new EffectLedger,
        drivers: $registry,
        runId: 'run-1',
        sourceId: 'source-1',
        userId: $userId,
    );
}

it('routes an effect to the driver that claims its (kind, name) and hands it the run context', function () {
    $driver = recordingDriver('api', 'places.details', fn () => BilledEffectResult::answered(['displayName' => ['text' => 'Maha']]));
    $io = ioWith(new BilledEffectDriverRegistry([$driver]));

    $outcome = $io->effect('api', 'places.details', ['place_id' => 'ChIJabc']);

    expect($outcome['status'])->toBe('ok')
        ->and($outcome['data'])->toBe(['displayName' => ['text' => 'Maha']])
        ->and($driver->seen->input)->toBe(['place_id' => 'ChIJabc'])
        ->and($driver->seen->runId)->toBe('run-1')
        ->and($driver->seen->sourceId)->toBe('source-1')
        ->and($driver->seen->userId)->toBe('user-1');
});

it('still throws for a (kind, name) no driver claims', function () {
    $io = ioWith(new BilledEffectDriverRegistry([
        recordingDriver('api', 'places.details', fn () => BilledEffectResult::answered([])),
    ]));

    expect(fn () => $io->effect('actor', 'menu', ['url' => 'https://example.test']))
        ->toThrow(RuntimeException::class, "No billed-effect driver is wired for kind 'actor'");
});

it('refuses every billed effect when the kill switch is off, without touching the ledger', function () {
    config()->set('partna.ingest.billed_effects_enabled', false);

    $driver = recordingDriver('api', 'places.details', fn () => BilledEffectResult::answered(['unreachable']));
    $io = ioWith(new BilledEffectDriverRegistry([$driver]));

    expect(fn () => $io->effect('api', 'places.details', ['place_id' => 'ChIJabc']))
        ->toThrow(EffectRefused::class);

    expect($driver->seen)->toBeNull()
        ->and(DB::table('ingest.effects')->count())->toBe(0);
});

it('leaves no ledger row when a driver refuses on budget', function () {
    $io = ioWith(new BilledEffectDriverRegistry([
        recordingDriver('api', 'places.details', fn () => throw new EffectNotAttempted('places cap reached')),
    ]));

    expect(fn () => $io->effect('api', 'places.details', ['place_id' => 'ChIJabc']))
        ->toThrow(EffectNotAttempted::class);

    expect(DB::table('ingest.effects')->count())->toBe(0);
});

it('turns a driver no-answer into a failed verdict rather than an ok-with-null', function () {
    $io = ioWith(new BilledEffectDriverRegistry([
        recordingDriver('api', 'places.details', fn () => BilledEffectResult::noAnswer('places returned 503')),
    ]));

    $outcome = $io->effect('api', 'places.details', ['place_id' => 'ChIJabc']);

    expect($outcome)->toBe(['status' => 'failed', 'cached' => false, 'data' => null]);

    $row = DB::table('ingest.effects')->where('kind', 'api')->first();
    expect($row->status)->toBe('failed')
        ->and(json_decode((string) $row->meta, true)['message'])->toBe('places returned 503');
});

it('settles an answered-with-null as ok so a dead identifier is not re-billed', function () {
    $io = ioWith(new BilledEffectDriverRegistry([
        recordingDriver('api', 'places.details', fn () => BilledEffectResult::answered(null)),
    ]));

    expect($io->effect('api', 'places.details', ['place_id' => 'ChIJgone']))
        ->toBe(['status' => 'ok', 'cached' => false, 'data' => null]);

    expect(DB::table('ingest.effects')->where('kind', 'api')->value('status'))->toBe('ok');
});

it('replays the second stream of a run from the ledger instead of billing twice', function () {
    // InstagramConnector calls effect() once per stream with identical input, so
    // the profile and media streams of one run share a digest by design.
    $calls = 0;
    $driver = recordingDriver('actor', 'instagram', function () use (&$calls) {
        $calls++;

        return BilledEffectResult::answered([['username' => 'maha']]);
    });
    $io = ioWith(new BilledEffectDriverRegistry([$driver]));

    $first = $io->effect('actor', 'instagram', ['username' => 'maha', 'include_posts' => true]);
    $second = $io->effect('actor', 'instagram', ['username' => 'maha', 'include_posts' => true]);

    expect($calls)->toBe(1)
        ->and($first['cached'])->toBeFalse()
        ->and($second['cached'])->toBeTrue()
        ->and($second['data'])->toBe([['username' => 'maha']]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Ingest/BilledEffectDispatchTest.php`
Expected: FAIL — `HttpIo::__construct()` has no `drivers` argument.

- [ ] **Step 3: Add the config key**

In `config/partna.php`, inside the `'ingest' => [` block, immediately after the `effect_freshness_seconds` entry (~line 996), insert:

```php
        // Whether HttpIo may execute a billed-effect driver at all (slice 0).
        // Default FALSE, per environment.
        //
        // This is ACTIVATION gating, not budget safety — PlacesBudget and
        // ApifyBudget already cap spend, and SourceProvisioner::schedulable()
        // leaves every non-Free connector auto_sync=false so the dispatcher
        // never claims one. It exists because `production` deploys on push, so
        // this seam reaches prod weeks before slice 1 intends paid fetching to
        // be live there. Off, a billed effect raises EffectRefused before the
        // ledger is touched: the stream reads budget_skipped and no row is
        // written.
        'billed_effects_enabled' => (bool) env('PARTNA_INGEST_BILLED_EFFECTS_ENABLED', false),
```

In `.env.example`, next to the other `PARTNA_INGEST_*` keys:

```
PARTNA_INGEST_BILLED_EFFECTS_ENABLED=false
```

- [ ] **Step 4: Rewrite `HttpIo`'s constructor and effect path**

Replace the constructor at `app/Ingest/Runtime/HttpIo.php:23-29`:

```php
    public function __construct(
        private readonly Manifest $manifest,
        private readonly SafeUrlFetcher $fetcher,
        private readonly EffectLedger $ledger,
        private readonly BilledEffectDriverRegistry $drivers,
        private readonly ?string $runId = null,
        private readonly ?string $sourceId = null,
        // Both drivers spend per-user budget: PlacesBudget carries a per-user
        // daily cap, and Instagram threads it for log correlation.
        private readonly ?string $userId = null,
    ) {}
```

Add the imports at the top of the file:

```php
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectDriverRegistry;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
```

Insert the kill switch as the first statement of `effect()` (before the digest is computed, `:81`):

```php
    public function effect(string $kind, string $name, array $input): array
    {
        if (! config('partna.ingest.billed_effects_enabled')) {
            // Refused BEFORE the ledger, so no row is written and the digest stays
            // claimable the moment the switch is flipped. EffectRefused (not
            // EffectNotAttempted) because nothing was budgeted — RunExecutor folds it to
            // budget_skipped either way.
            throw new EffectRefused(
                "Billed effects are disabled in this environment (effect '{$kind}/{$name}') — ".
                'set PARTNA_INGEST_BILLED_EFFECTS_ENABLED=true to activate.'
            );
        }

        // ... existing digest + $this->ledger->once(...) body, unchanged ...
```

Replace `runBilledEffect()` at `:112-123` in full:

```php
    /**
     * Perform the effect. Called from INSIDE EffectLedger::once(), which is why
     * claim-first and charge-once hold however a driver behaves.
     *
     * @param  array<string, mixed>  $input
     * @return array<int|string, mixed>|null null is an ANSWER ("nothing here"),
     *                                       never an outage — see EffectNoAnswer.
     */
    private function runBilledEffect(string $kind, string $name, array $input): ?array
    {
        $driver = $this->drivers->for($kind, $name);

        if ($driver === null) {
            // Unchanged in spirit from the pre-driver throw: a connector must not
            // declare a billed effect nothing can perform, and staying loud is what
            // stops such a declaration reading as free.
            throw new \RuntimeException(
                "No billed-effect driver is wired for kind '{$kind}' (effect '{$name}'). ".
                'A connector must not declare a billed effect it cannot perform.'
            );
        }

        $result = $driver->run(new BilledEffectContext(
            kind: $kind,
            name: $name,
            input: $input,
            runId: $this->runId,
            sourceId: $this->sourceId,
            userId: $this->userId,
        ));

        if ($result->outcome === BilledEffectOutcome::NoAnswer) {
            throw new EffectNoAnswer($result->reason ?? "billed effect '{$kind}/{$name}' returned no answer");
        }

        return $result->data;
    }
```

- [ ] **Step 5: Bind the registry**

The two concrete drivers arrive in Tasks 5 and 6, so bind an **empty** registry now — this task must be green on its own, and importing a class that does not exist yet would fatal on boot.

In `app/Providers/AppServiceProvider.php::register()`, after the existing `Rulepack` singleton (~line 110), add:

```php
        // Ordered, explicit driver list rather than a discovery scan: this decides
        // which (kind, name) pairs may spend money, so it should be a list someone
        // has to edit deliberately. `actor` alone is ambiguous — the three menu
        // connectors declare it too and have no driver, which is why they keep
        // hitting HttpIo's throw.
        $this->app->singleton(BilledEffectDriverRegistry::class, fn () => new BilledEffectDriverRegistry([]));
```

with one import:

```php
use App\Ingest\Runtime\Effects\BilledEffectDriverRegistry;
```

Tasks 5 and 6 each add their own driver to this array and their own import.

- [ ] **Step 6: Thread `user_id` through `RunExecutor`**

At `app/Ingest/Runtime/RunExecutor.php:83`, change:

```php
            $io = $this->ioFor($manifest, $runId, (string) $source['id']);
```

to — reading the column inline rather than adding a second reader, since `isClaimed()` at `:306` already reads it and two readers of the same nullable column are exactly the drift this file's own CFG-16 docblocks warn about:

```php
            $userId = $source['user_id'] ?? null;
            $io = $this->ioFor($manifest, $runId, (string) $source['id'], $userId === null ? null : (string) $userId);
```

Replace `ioFor()` at `:400-409`:

```php
    /**
     * $userId is nullable on purpose and never fabricated: isClaimed() already
     * treats an ownerless source as a real state, and a driver that spends
     * per-user budget must be able to see the absence rather than be handed an id.
     */
    private function ioFor(Manifest $manifest, string $runId, string $sourceId, ?string $userId): Io
    {
        return new HttpIo(
            manifest: $manifest,
            fetcher: app(SafeUrlFetcher::class),
            ledger: app(EffectLedger::class),
            drivers: app(BilledEffectDriverRegistry::class),
            runId: $runId,
            sourceId: $sourceId,
            userId: $userId,
        );
    }
```

with the import:

```php
use App\Ingest\Runtime\Effects\BilledEffectDriverRegistry;
```

- [ ] **Step 7: Fix the one existing `HttpIo` construction site in tests**

`tests/Feature/Ingest/HttpIoPostSsrfTest.php:29` — change:

```php
    return new HttpIo($manifest, app(SafeUrlFetcher::class), new EffectLedger);
```

to:

```php
    return new HttpIo($manifest, app(SafeUrlFetcher::class), new EffectLedger, new BilledEffectDriverRegistry);
```

and add `use App\Ingest\Runtime\Effects\BilledEffectDriverRegistry;` to that file's imports.

- [ ] **Step 8: Run the new and adjacent tests**

Run: `./vendor/bin/pest tests/Feature/Ingest/BilledEffectDispatchTest.php tests/Feature/Ingest/HttpIoPostSsrfTest.php tests/Feature/Ingest/RunExecutorProjectionTest.php tests/Feature/Ingest/RunExecutorClaimGateTest.php`
Expected: PASS. The dispatch file is 7 tests.

- [ ] **Step 9: Run the whole ingest suite**

Run: `./vendor/bin/pest tests/Feature/Ingest tests/Unit/Ingest`
Expected: PASS. If `GoogleBusinessConnectorTest` or `InstagramConnectorTest` fail, read them before changing them — they use test-fake `Io` implementations and should be untouched by this task.

- [ ] **Step 10: Commit**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
./vendor/bin/pint app/Ingest app/Providers/AppServiceProvider.php config/partna.php tests/Feature/Ingest
git add -A
git commit -m "feat(ingest): dispatch billed effects through the driver registry

HttpIo::runBilledEffect() becomes a registry lookup instead of an unconditional
throw; an unmatched (kind, name) keeps throwing, which is what the three menu
connectors still rely on. RunExecutor threads ingest.sources.user_id through so
drivers can spend per-user budget.

config('partna.ingest.billed_effects_enabled') defaults to false and refuses
before the ledger is touched: production deploys on push, so this seam lands
there before slice 1 wants paid fetching live."
```

---

### Task 4: A raw Places fetch seam on `GoogleBusinessService`

`GoogleBusinessConnector` reads the **raw** Places (New) response — see deviation D1. This task extracts a raw fetch that reports *why* it produced no place, without changing `fetchPlaceDetails()`'s public contract.

**Files:**
- Create: `app/Services/Platforms/PlaceDetailsFailure.php`
- Create: `app/Services/Platforms/PlaceDetailsResult.php`
- Modify: `app/Services/Platforms/GoogleBusinessService.php:209-282`
- Test: `tests/Feature/Platforms/PlaceDetailsRawFetchTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `GoogleBusinessService::fetchPlaceDetailsRaw(string $placeId, string $userId): PlaceDetailsResult`
  - `PlaceDetailsResult` — public readonly `?array $place`, `?PlaceDetailsFailure $failure`, `?PlacesClaim $deniedBy`; static `ok(array $place)`, `failed(PlaceDetailsFailure $f)`, `budgetDenied(PlacesClaim $reason)`
  - `PlaceDetailsFailure` — `NotConfigured`, `BudgetDenied`, `Transport`, `UpstreamError`, `NotFound`
  - `fetchPlaceDetails(string $placeId, string $userId, array $priorPhotos = []): ?array` — **contract unchanged**, still throws `PlacesBudgetExhaustedException`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Platforms/PlaceDetailsRawFetchTest.php`:

```php
<?php

use App\Exceptions\Platforms\PlacesBudgetExhaustedException;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\PlaceDetailsFailure;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.google_maps.server_api_key', 'test-key');
    config()->set('partna.limits.places.details_max_attempts', 1);
    config()->set('partna.limits.places.global_daily_cap', 100);
    config()->set('partna.limits.places.skus.details', 100);
    config()->set('partna.limits.places.skus.photos', 100);
    config()->set('partna.limits.places.per_user_daily_cap', 100);
});

function places(): GoogleBusinessService
{
    return app(GoogleBusinessService::class);
}

it('returns the raw Places response untouched, not the mapped payload', function () {
    // The connector reads displayName.text / photos[].name / reviews[].authorAttribution.
    // Mapping here would null every one of them — including the three reviewer-PII
    // keys the manifest's when_unclaimed redaction is declared over.
    Http::fake(['places.googleapis.com/*' => Http::response([
        'displayName' => ['text' => 'Maha'],
        'formattedAddress' => '21 Bond St',
        'photos' => [['name' => 'places/abc/photos/xyz', 'widthPx' => 400]],
        'reviews' => [['rating' => 5, 'text' => ['text' => 'great'], 'authorAttribution' => ['displayName' => 'Sam']]],
    ], 200)]);

    $result = places()->fetchPlaceDetailsRaw('ChIJabc', 'user-1');

    expect($result->failure)->toBeNull()
        ->and($result->place['displayName']['text'])->toBe('Maha')
        ->and($result->place['photos'][0]['name'])->toBe('places/abc/photos/xyz')
        ->and($result->place['reviews'][0]['authorAttribution']['displayName'])->toBe('Sam');
});

it('reports a missing server key without touching the network', function () {
    config()->set('services.google_maps.server_api_key', '');
    Http::fake();

    expect(places()->fetchPlaceDetailsRaw('ChIJabc', 'user-1')->failure)
        ->toBe(PlaceDetailsFailure::NotConfigured);

    Http::assertNothingSent();
});

it('reports a first-attempt budget denial as BudgetDenied, before any request', function () {
    config()->set('partna.limits.places.per_user_daily_cap', 0);
    Http::fake();

    $result = places()->fetchPlaceDetailsRaw('ChIJabc', 'user-1');

    expect($result->failure)->toBe(PlaceDetailsFailure::BudgetDenied)
        ->and($result->deniedBy)->not->toBeNull();

    Http::assertNothingSent();
});

it('reports a transport failure on every attempt as Transport', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

    expect(places()->fetchPlaceDetailsRaw('ChIJabc', 'user-1')->failure)
        ->toBe(PlaceDetailsFailure::Transport);
});

// ⚠️ ONE STATUS PER TEST — DO NOT COLLAPSE THESE INTO A LOOP OF Http::fake() CALLS.
// Illuminate\Http\Client\Factory::fake() MERGES stub callbacks rather than
// replacing them, and PendingRequest resolves with ->filter()->first(). Four
// sequential fakes against the same pattern all resolve to the FIRST one, so a
// collapsed version would fail three assertions and, worse, could pass vacuously
// if every arm happened to expect the same outcome.
it('treats a 404 as Google answering: there is no such place', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([], 404)]);

    expect(places()->fetchPlaceDetailsRaw('ChIJgone', 'user-1')->failure)
        ->toBe(PlaceDetailsFailure::NotFound);
});

it('treats a 429 as Google not answering', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([], 429)]);

    expect(places()->fetchPlaceDetailsRaw('ChIJabc', 'user-1')->failure)
        ->toBe(PlaceDetailsFailure::UpstreamError);
});

it('treats a 503 as Google not answering', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([], 503)]);

    expect(places()->fetchPlaceDetailsRaw('ChIJabc', 'user-1')->failure)
        ->toBe(PlaceDetailsFailure::UpstreamError);
});

it('treats a 403 as our credential problem, never as "no such place"', function () {
    // Settling a 403 as an answer would cache a broken key as "this place has no
    // data" — for every place at once, for the whole freshness window.
    Http::fake(['places.googleapis.com/*' => Http::response([], 403)]);

    expect(places()->fetchPlaceDetailsRaw('ChIJabc', 'user-1')->failure)
        ->toBe(PlaceDetailsFailure::UpstreamError);
});

it('keeps fetchPlaceDetails mapping and its budget exception unchanged', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([
        'displayName' => ['text' => 'Maha'],
        'formattedAddress' => '21 Bond St',
        'nationalPhoneNumber' => '03 9000 0000',
    ], 200)]);

    $mapped = places()->fetchPlaceDetails('ChIJabc', 'user-1');

    expect($mapped['name'])->toBe('Maha')
        ->and($mapped['address'])->toBe('21 Bond St')
        ->and($mapped['phone'])->toBe('03 9000 0000')
        ->and($mapped)->toHaveKey('detailsFetchedAt');

    config()->set('partna.limits.places.per_user_daily_cap', 0);
    expect(fn () => places()->fetchPlaceDetails('ChIJabc', 'user-2'))
        ->toThrow(PlacesBudgetExhaustedException::class);
});

it('returns null from fetchPlaceDetails when the vendor did not answer', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([], 503)]);

    expect(places()->fetchPlaceDetails('ChIJabc', 'user-1'))->toBeNull();
});

it('returns null from fetchPlaceDetails when the key is unset', function () {
    config()->set('services.google_maps.server_api_key', '');
    Http::fake();

    expect(places()->fetchPlaceDetails('ChIJabc', 'user-1'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Platforms/PlaceDetailsRawFetchTest.php`
Expected: FAIL — `Call to undefined method ...::fetchPlaceDetailsRaw()`.

- [ ] **Step 3: Create the two result types**

`app/Services/Platforms/PlaceDetailsFailure.php`:

```php
<?php

namespace App\Services\Platforms;

/**
 * Why a raw Place Details fetch produced no place. fetchPlaceDetails() collapsed
 * all of these into a bare null, which was fine for a best-effort card and wrong
 * for a ledgered billed effect: three of them involve no charge, and only ONE of
 * them is Google actually answering.
 */
enum PlaceDetailsFailure: string
{
    /** No server key configured. Never reached the network. */
    case NotConfigured = 'not_configured';

    /** The FIRST budget claim was denied, so no request left the process. */
    case BudgetDenied = 'budget_denied';

    /** Every attempt threw (timeout, DNS, reset). A request may still have been billed. */
    case Transport = 'transport';

    /** 429, 5xx, or an auth/argument 4xx — Google did not answer about this place. */
    case UpstreamError = 'upstream_error';

    /**
     * 404 only. Google answered: there is no such place. Terminal, and the one
     * failure a caller may treat as an ANSWER rather than an outage.
     */
    case NotFound = 'not_found';
}
```

`app/Services/Platforms/PlaceDetailsResult.php`:

```php
<?php

namespace App\Services\Platforms;

use App\Services\Cache\PlacesClaim;

/**
 * Outcome of GoogleBusinessService::fetchPlaceDetailsRaw(): the RAW Places (New)
 * response, or the reason there isn't one. Exactly one of $place / $failure is
 * non-null. Mirrors ProfileFetchResult, which solved the same problem for
 * Instagram.
 *
 * $deniedBy is set only for BudgetDenied, and only so fetchPlaceDetails() can
 * rebuild the PlacesBudgetExhaustedException its callers still branch on —
 * GoogleBusinessController turns UserCapReached into a 429 and everything else
 * into a quiet degrade.
 */
final readonly class PlaceDetailsResult
{
    /** @param array<string, mixed>|null $place */
    private function __construct(
        public ?array $place,
        public ?PlaceDetailsFailure $failure,
        public ?PlacesClaim $deniedBy = null,
    ) {}

    /** @param array<string, mixed> $place */
    public static function ok(array $place): self
    {
        return new self($place, null);
    }

    public static function failed(PlaceDetailsFailure $failure): self
    {
        return new self(null, $failure);
    }

    public static function budgetDenied(PlacesClaim $reason): self
    {
        return new self(null, PlaceDetailsFailure::BudgetDenied, $reason);
    }
}
```

- [ ] **Step 4: Split `fetchPlaceDetails()` into raw fetch plus mapping**

In `app/Services/Platforms/GoogleBusinessService.php`, replace the whole of `fetchPlaceDetails()` (`:192-282`, docblock included) with:

```php
    /**
     * The RAW Place Details (New) response for a place id, or why there isn't one.
     *
     * Split out from fetchPlaceDetails() because two callers want different
     * things from the same billed request. The card wants mapped payload keys and
     * resolved photo URLs; GoogleBusinessConnector reads the vendor shape directly
     * (displayName.text, photos[].name, reviews[].authorAttribution) and its
     * when_unclaimed reviewer-PII redaction is declared over those exact keys — so
     * handing it the mapped payload would land nothing and silently make that
     * redaction vacuous.
     *
     * RV-6 is unchanged: every attempt claims its own PlacesBudget slot immediately
     * before it fires. What is new is that the FIRST denial is reported rather than
     * thrown, because only that one provably precedes a request — a later attempt
     * happens only after a transport failure, which means something already reached
     * places.googleapis.com and may have been billed.
     */
    public function fetchPlaceDetailsRaw(string $placeId, string $userId): PlaceDetailsResult
    {
        $key = config('services.google_maps.server_api_key');
        if (! is_string($key) || $key === '') {
            return PlaceDetailsResult::failed(PlaceDetailsFailure::NotConfigured);
        }

        // Re-clamped here (not just in config/partna.php) so a runtime
        // config()->set() — an on-call knob, a test — can never push the
        // per-fetch billed-attempt count past 3.
        $maxAttempts = max(1, min(3, (int) config('partna.limits.places.details_max_attempts', self::DETAILS_MAX_ATTEMPTS)));
        $retryDelayUs = (int) config('partna.limits.places.details_retry_delay_microseconds', self::DETAILS_RETRY_DELAY_US);

        $res = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $claim = $this->budget->claim('details', $userId);
            if ($claim !== PlacesClaim::Granted) {
                if ($attempt === 1) {
                    return PlaceDetailsResult::budgetDenied($claim);
                }

                // Denied mid-loop: a request already went out on the attempt
                // before this one. Not a clean refusal — report it as a failure so
                // no caller can treat it as "nothing was spent".
                throw new PlacesBudgetExhaustedException('details', $claim, $placeId);
            }

            try {
                $res = Http::timeout(5)
                    ->withHeaders([
                        'X-Goog-Api-Key' => $key,
                        'X-Goog-FieldMask' => self::DETAILS_FIELD_MASK,
                    ])
                    ->get('https://places.googleapis.com/v1/places/'.rawurlencode($placeId));
                break;
            } catch (\Throwable $e) {
                if ($attempt < $maxAttempts) {
                    usleep($retryDelayUs);

                    continue;
                }

                report($e); // OBS-1: billed-call network failure must reach Nightwatch, not just the log.
                Log::warning('google_business.details_fetch_failed', [
                    'placeId' => $placeId,
                    'message' => $e->getMessage(),
                ]);

                return PlaceDetailsResult::failed(PlaceDetailsFailure::Transport);
            }
        }

        if (! $res->ok()) {
            report(new PlaceDetailsUnavailableException($placeId, $res->status())); // OBS-1: an outage (429/5xx) pages on-call.
            Log::warning('google_business.details_fetch_failed', [
                'placeId' => $placeId,
                'status' => $res->status(),
            ]);

            // 404 ONLY. A 403 is our own credential problem and a 400 our own
            // argument problem; calling either "no such place" would let a broken
            // key settle as a permanent, cached answer for every place at once.
            return PlaceDetailsResult::failed(
                $res->status() === 404 ? PlaceDetailsFailure::NotFound : PlaceDetailsFailure::UpstreamError,
            );
        }

        return PlaceDetailsResult::ok((array) $res->json());
    }

    /**
     * Fetch Place Details (New) for a place ID and map the response onto
     * payload keys (rating, reviewCount, reviews, hours, phone, website,
     * photos, amenities, …). Null when the server key is unset or the fetch
     * fails — callers keep their existing payload untouched.
     *
     * Contract unchanged: still throws PlacesBudgetExhaustedException on a denied
     * claim, which GoogleBusinessController turns into a 429 for UserCapReached
     * and a quiet degrade otherwise.
     *
     * @return array<string,mixed>|null
     *
     * @throws PlacesBudgetExhaustedException
     */
    public function fetchPlaceDetails(string $placeId, string $userId, array $priorPhotos = []): ?array
    {
        $result = $this->fetchPlaceDetailsRaw($placeId, $userId);

        if ($result->failure === PlaceDetailsFailure::BudgetDenied) {
            throw new PlacesBudgetExhaustedException('details', $result->deniedBy ?? PlacesClaim::Unavailable, $placeId);
        }

        if ($result->place === null) {
            return null;
        }

        $mapped = $this->mapDetails($result->place);

        // Re-read rather than threaded out of fetchPlaceDetailsRaw(): a credential
        // has no business crossing a return boundary, and reaching here at all means
        // that method already proved the key is present.
        $key = (string) config('services.google_maps.server_api_key');

        // Photo refs → servable image URLs (one billed media call per photo,
        // pooled). Street View availability is a free metadata probe.
        if (isset($mapped['photos']) && is_array($mapped['photos'])) {
            // SCALE-3: pre-populate servable urls from the prior payload for unchanged
            // refs so resolvePhotoUrls skips them (no billed re-call). Best-effort —
            // a rotated ref just resolves fresh below. Connect callers pass no prior
            // photos, so this is a no-op there.
            $mapped['photos'] = $this->carryForwardPhotoUrls($mapped['photos'], $priorPhotos);
            $mapped['photos'] = $this->resolvePhotoUrls($key, $placeId, $mapped['photos'], $userId);
        }
        if (isset($mapped['lat'], $mapped['lng'])
            && ($pano = $this->streetViewPano($key, (float) $mapped['lat'], (float) $mapped['lng'])) !== null) {
            $mapped['streetView'] = $pano;
        }

        return $mapped;
    }
```

The file already imports `PlacesClaim` (`:8`). Add the two new types to its imports:

```php
use App\Services\Platforms\PlaceDetailsFailure;
use App\Services\Platforms\PlaceDetailsResult;
```

> Both live in `App\Services\Platforms`, the same namespace as `GoogleBusinessService` — so if Pint strips these as redundant, that is correct and expected. The class references resolve either way.

- [ ] **Step 5: Run the new tests**

Run: `./vendor/bin/pest tests/Feature/Platforms/PlaceDetailsRawFetchTest.php`
Expected: PASS, 11 tests.

- [ ] **Step 6: Run every existing consumer's tests**

`fetchPlaceDetails()` has three callers: `GoogleBusinessController:112`, `GoogleBusinessFetch:49`, `GoogleBusinessSourceGenerator:57`.

Run: `./vendor/bin/pest --filter=GoogleBusiness`
Expected: PASS, no count change. Any failure here means the public contract moved — fix `fetchPlaceDetails()`, not the test.

- [ ] **Step 7: Commit**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
./vendor/bin/pint app/Services/Platforms tests/Feature/Platforms/PlaceDetailsRawFetchTest.php
git add app/Services/Platforms tests/Feature/Platforms/PlaceDetailsRawFetchTest.php
git commit -m "feat(platforms): expose a raw Place Details fetch with a typed outcome

GoogleBusinessConnector reads the vendor shape (displayName.text, photos[].name,
reviews[].authorAttribution) and declares its when_unclaimed reviewer-PII
redaction over those exact keys — handing it mapDetails() output would land no
profile, no photos, and make that redaction vacuous.

PlaceDetailsResult also separates the four causes of today's bare null: only a
404 is Google answering. fetchPlaceDetails()'s public contract, including its
PlacesBudgetExhaustedException, is unchanged."
```

---

### Task 5: `PlacesDetailsDriver`

**Files:**
- Create: `app/Ingest/Runtime/Effects/PlacesDetailsDriver.php`
- Modify: `app/Providers/AppServiceProvider.php` (add to the registry list)
- Test: `tests/Feature/Ingest/PlacesDetailsDriverTest.php`

**Interfaces:**
- Consumes: `BilledEffectDriver`, `BilledEffectContext`, `BilledEffectResult` (Task 1); `EffectNotAttempted` (Task 2); `fetchPlaceDetailsRaw()`, `PlaceDetailsResult`, `PlaceDetailsFailure` (Task 4).
- Produces: a driver claiming `('api', 'places.details')`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Ingest/PlacesDetailsDriverTest.php`:

```php
<?php

use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\PlacesDetailsDriver;
use Illuminate\Support\Facades\Http;

// NOTE: no `use App\Ingest\Runtime\EffectRefused` — EffectNotAttempted extends it,
// so asserting on the parent would pass for either and prove nothing about which
// claim-handling path ran. Always assert the exact subclass here.

beforeEach(function () {
    config()->set('services.google_maps.server_api_key', 'test-key');
    config()->set('partna.limits.places.details_max_attempts', 1);
    config()->set('partna.limits.places.global_daily_cap', 100);
    config()->set('partna.limits.places.skus.details', 100);
    config()->set('partna.limits.places.per_user_daily_cap', 100);
});

function placesCtx(array $input = ['place_id' => 'ChIJabc'], ?string $userId = 'user-1'): BilledEffectContext
{
    return new BilledEffectContext('api', 'places.details', $input, 'run-1', 'source-1', $userId);
}

it('claims only its own (kind, name)', function () {
    $driver = app(PlacesDetailsDriver::class);

    expect($driver->supports('api', 'places.details'))->toBeTrue()
        ->and($driver->supports('actor', 'places.details'))->toBeFalse()
        ->and($driver->supports('api', 'places.photos'))->toBeFalse();
});

it('returns the raw Places response so the connector can read the vendor shape', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([
        'displayName' => ['text' => 'Maha'],
        'photos' => [['name' => 'places/abc/photos/xyz']],
    ], 200)]);

    $result = app(PlacesDetailsDriver::class)->run(placesCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data['displayName']['text'])->toBe('Maha')
        ->and($result->data['photos'][0]['name'])->toBe('places/abc/photos/xyz');
});

it('refuses on budget before any request, so the ledger claim can be released', function () {
    config()->set('partna.limits.places.per_user_daily_cap', 0);
    Http::fake();

    expect(fn () => app(PlacesDetailsDriver::class)->run(placesCtx()))
        ->toThrow(EffectNotAttempted::class);

    Http::assertNothingSent();
});

it('treats a 404 as an answer with no data, not an outage', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([], 404)]);

    $result = app(PlacesDetailsDriver::class)->run(placesCtx(['place_id' => 'ChIJgone']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toBeNull();
});

// ⚠️ ONE CAUSE PER TEST. Http::fake() MERGES stubs and the FIRST match wins, so a
// combined version would exercise one status four times while reading as if it
// covered four. Spec §5.4 requires each of the four null causes proven separately.
it('reports a 503 as NoAnswer', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([], 503)]);

    expect(app(PlacesDetailsDriver::class)->run(placesCtx())->outcome)->toBe(BilledEffectOutcome::NoAnswer);
});

it('reports a 429 as NoAnswer', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([], 429)]);

    expect(app(PlacesDetailsDriver::class)->run(placesCtx())->outcome)->toBe(BilledEffectOutcome::NoAnswer);
});

it('reports a transport failure as NoAnswer', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

    expect(app(PlacesDetailsDriver::class)->run(placesCtx())->outcome)->toBe(BilledEffectOutcome::NoAnswer);
});

it('refuses without attempting anything when the server key is unset', function () {
    // NOT NoAnswer: nothing was sent, so the ledger claim must be released and the
    // digest stay retryable — otherwise one missing env var locks every place for
    // the freshness window, and stays locked after the var is set.
    config()->set('services.google_maps.server_api_key', '');
    Http::fake();

    expect(fn () => app(PlacesDetailsDriver::class)->run(placesCtx()))
        ->toThrow(EffectNotAttempted::class);

    Http::assertNothingSent();
});

it('refuses rather than spending when the effect carries no place id or no user', function () {
    Http::fake();

    expect(app(PlacesDetailsDriver::class)->run(placesCtx([]))->outcome)->toBe(BilledEffectOutcome::NoAnswer);
    expect(app(PlacesDetailsDriver::class)->run(placesCtx(userId: null))->outcome)->toBe(BilledEffectOutcome::NoAnswer);

    Http::assertNothingSent();
});

it('treats a mid-loop budget denial as NoAnswer, never as a clean refusal', function () {
    // The second attempt only happens after a transport failure, which means a
    // request already reached Google. That is not "nothing was sent", so it must
    // NOT surface as EffectNotAttempted — the ledger would release the claim.
    //
    // It must also not ESCAPE: an uncaught PlacesBudgetExhaustedException reaches
    // RunExecutor's catch-all, marking the stream 'error' and paging on-call for a
    // spend ceiling. NoAnswer settles the row failed (claim retained, which is what
    // "a request already went out" requires) and folds to Unavailable.
    config()->set('partna.limits.places.details_max_attempts', 2);
    config()->set('partna.limits.places.per_user_daily_cap', 1);
    config()->set('partna.limits.places.details_retry_delay_microseconds', 0);
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

    $result = app(PlacesDetailsDriver::class)->run(placesCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer)
        ->and($result->reason)->toContain('budget');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Ingest/PlacesDetailsDriverTest.php`
Expected: FAIL — `Class "App\Ingest\Runtime\Effects\PlacesDetailsDriver" not found`.

- [ ] **Step 3: Write the driver**

`app/Ingest/Runtime/Effects/PlacesDetailsDriver.php`:

```php
<?php

namespace App\Ingest\Runtime\Effects;

use App\Exceptions\Platforms\PlacesBudgetExhaustedException;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\PlaceDetailsFailure;

/**
 * ('api', 'places.details') — the one path permitted to issue a keyed Places
 * request from the ingest lane, mirroring the rule GoogleBusinessConnector's
 * empty `hosts` list encodes.
 *
 * Returns the RAW Places response, not the mapped card payload: the connector
 * reads displayName.text, photos[].name and reviews[].authorAttribution, and its
 * when_unclaimed reviewer-PII redaction is declared over those exact keys.
 *
 * Deliberately does NOT resolve photo refs to servable URLs. That is up to 15
 * further billed media calls per run and it belongs to slice 1, where something
 * actually renders them.
 */
final class PlacesDetailsDriver implements BilledEffectDriver
{
    public function __construct(private readonly GoogleBusinessService $places) {}

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'api' && $name === 'places.details';
    }

    public function run(BilledEffectContext $ctx): BilledEffectResult
    {
        $placeId = trim((string) ($ctx->input['place_id'] ?? ''));

        // No place id and no owner are both our own bugs, not vendor conditions —
        // NoAnswer keeps the ledger row settled and visible instead of throwing
        // into RunExecutor's catch-all, and neither spends anything.
        if ($placeId === '') {
            return BilledEffectResult::noAnswer('places.details effect carried no place_id');
        }
        if ($ctx->userId === null) {
            return BilledEffectResult::noAnswer("places.details effect for {$placeId} has no owning user to budget against");
        }

        try {
            $result = $this->places->fetchPlaceDetailsRaw($placeId, $ctx->userId);
        } catch (PlacesBudgetExhaustedException $e) {
            // Only reachable from a MID-LOOP denial: fetchPlaceDetailsRaw() reports a
            // first-attempt denial rather than throwing, and a later attempt only
            // follows a transport failure — so a request already reached Google and
            // may have been billed. NoAnswer (not EffectNotAttempted) keeps the
            // settled row and its claim, which is the only safe reading of "we might
            // have been charged".
            //
            // Caught rather than left to propagate: an escaping RuntimeException hits
            // RunExecutor's catch-all, marking the stream 'error' and paging on-call.
            // A spend ceiling is not our bug.
            return BilledEffectResult::noAnswer(
                "places.details budget denied mid-retry for {$placeId} ({$e->reason->value})"
            );
        }

        if ($result->place !== null) {
            return BilledEffectResult::answered($result->place);
        }

        return match ($result->failure) {
            // Nothing was sent. once() releases the claim, so the digest is retryable
            // the moment the cap resets or the key is set, instead of being locked
            // out for the rest of the freshness window.
            PlaceDetailsFailure::BudgetDenied => throw new EffectNotAttempted(
                "Places details budget denied for {$placeId} ({$result->deniedBy?->value})"
            ),
            PlaceDetailsFailure::NotConfigured => throw new EffectNotAttempted(
                'services.google_maps.server_api_key is not configured'
            ),

            // Google answered: there is no such place. Settling this ok stops us
            // re-billing a dead place id on every run inside the window.
            PlaceDetailsFailure::NotFound => BilledEffectResult::answered(null),

            default => BilledEffectResult::noAnswer(
                "places.details did not answer for {$placeId} ({$result->failure?->value})"
            ),
        };
    }
}
```

- [ ] **Step 4: Register it**

In `app/Providers/AppServiceProvider.php`, change the registry binding added in Task 3 to:

```php
        $this->app->singleton(BilledEffectDriverRegistry::class, fn ($app) => new BilledEffectDriverRegistry([
            $app->make(PlacesDetailsDriver::class),
        ]));
```

and add `use App\Ingest\Runtime\Effects\PlacesDetailsDriver;`.

- [ ] **Step 5: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Ingest/PlacesDetailsDriverTest.php`
Expected: PASS, 10 tests.

- [ ] **Step 6: Prove the connector now lands records end to end**

Append to `tests/Feature/Ingest/PlacesDetailsDriverTest.php`:

```php
it('lets GoogleBusinessConnector produce profile, review and media records', function () {
    // The whole point of slice 0: this connector could not complete a run at all
    // before, because HttpIo::runBilledEffect() threw unconditionally.
    config()->set('partna.ingest.billed_effects_enabled', true);
    setupIngestTables();

    Http::fake(['places.googleapis.com/*' => Http::response([
        'displayName' => ['text' => 'Maha'],
        'formattedAddress' => '21 Bond St, Melbourne',
        'nationalPhoneNumber' => '03 9000 0000',
        'websiteUri' => 'https://maha.test',
        'reviews' => [[
            'name' => 'places/abc/reviews/r1',
            'rating' => 5,
            'text' => ['text' => 'Excellent'],
            'authorAttribution' => ['displayName' => 'Sam', 'uri' => 'https://maps.test/sam'],
            'publishTime' => '2026-08-01T00:00:00Z',
        ]],
        'photos' => [['name' => 'places/abc/photos/p1', 'widthPx' => 1200, 'heightPx' => 800]],
    ], 200)]);

    $io = new \App\Ingest\Runtime\HttpIo(
        manifest: \App\Ingest\Connectors\GoogleBusinessConnector::manifest(),
        fetcher: app(\App\Services\Http\SafeUrlFetcher::class),
        ledger: new \App\Ingest\Runtime\EffectLedger,
        drivers: new \App\Ingest\Runtime\Effects\BilledEffectDriverRegistry([app(PlacesDetailsDriver::class)]),
        runId: 'run-1',
        sourceId: 'source-1',
        userId: 'user-1',
    );

    $manifest = \App\Ingest\Connectors\GoogleBusinessConnector::manifest();
    $connector = new \App\Ingest\Connectors\GoogleBusinessConnector;

    $collect = function (string $stream) use ($connector, $io, $manifest): array {
        $pull = new \App\Ingest\Runtime\Pull(
            identifier: 'ChIJabc',
            stream: $manifest->streams[$stream],
            cursor: [],
            config: ['scope' => 'all', 'scope_n' => null],
            isClaimed: true,
        );

        $records = [];
        foreach ($connector->pull($pull, $io) as $message) {
            if ($message instanceof \App\Ingest\Message\Record) {
                $records[] = $message;
            }
        }

        return $records;
    };

    $profile = $collect('profile');
    expect($profile)->toHaveCount(1)
        ->and($profile[0]->doc['display_name'])->toBe('Maha')
        ->and($profile[0]->doc['website'])->toBe('https://maha.test');

    // The reviewer-PII keys the manifest's when_unclaimed redaction is declared
    // over must be PRESENT here — the Lander strips them, not the connector, and a
    // mapped payload would have made that redaction vacuous.
    $reviews = $collect('reviews');
    expect($reviews)->toHaveCount(1)
        ->and($reviews[0]->key)->toBe('places/abc/reviews/r1')
        ->and($reviews[0]->doc['author'])->toBe('Sam')
        ->and($reviews[0]->doc['text'])->toBe('Excellent');

    $media = $collect('media');
    expect($media)->toHaveCount(1)
        ->and($media[0]->doc['ref'])->toBe('places/abc/photos/p1')
        ->and($media[0]->doc['width_px'])->toBe(1200);

    // One billed request for all three streams — the other two replayed the digest.
    Http::assertSentCount(1);
});
```

Run: `./vendor/bin/pest tests/Feature/Ingest/PlacesDetailsDriverTest.php`
Expected: PASS, 11 tests.

- [ ] **Step 7: Commit**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
./vendor/bin/pint app/Ingest app/Providers/AppServiceProvider.php tests/Feature/Ingest/PlacesDetailsDriverTest.php
git add -A
git commit -m "feat(ingest): wire the Places details billed-effect driver

GoogleBusinessConnector can complete a run for the first time: profile, reviews
and media all land, on ONE billed request, with the two later streams replaying
the digest from the ledger.

A 404 settles ok-with-null so a dead place id is not re-billed every run; a
first-attempt budget denial raises EffectNotAttempted so the claim is released and
the digest stays retryable; everything else is NoAnswer and settles failed."
```

---

### Task 6: `InstagramActorDriver`

**Files:**
- Create: `app/Ingest/Runtime/Effects/InstagramActorDriver.php`
- Modify: `app/Services/Platforms/InstagramScraper.php` (add `isConfigured()`)
- Modify: `app/Providers/AppServiceProvider.php` (add to the registry list)
- Modify: `app/Ingest/Connectors/InstagramConnector.php:26-30` (docblock — the cooldown it names does not exist)
- Test: `tests/Feature/Ingest/InstagramActorDriverTest.php`

**Interfaces:**
- Consumes: `BilledEffectDriver`, `BilledEffectContext`, `BilledEffectResult` (Task 1); `EffectNotAttempted` (Task 2).
- Produces:
  - a driver claiming `('actor', 'instagram')`, returning a **one-item list** (Apify's dataset shape, which `InstagramConnector::profileItem()` reads via `$data[0] ?? $data`)
  - `InstagramScraper::isConfigured(): bool` — token present **and** the configured actor has a resolvable adapter

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Ingest/InstagramActorDriverTest.php`:

```php
<?php

use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\InstagramActorDriver;
use App\Services\Cache\ApifyBudget;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.instagram.actor', 'apify~instagram-profile-scraper');
    config()->set('partna.instagram.actor_adapters', [
        'apify~instagram-profile-scraper' => \App\Services\Platforms\Actors\ApifyProfileScraperAdapter::class,
    ]);
    config()->set('partna.limits.apify.global_daily_cap', 100);
    config()->set('partna.limits.apify.actors.instagram', 100);
});

function igCtx(array $input = ['username' => 'maha', 'include_posts' => true], ?string $userId = 'user-1'): BilledEffectContext
{
    return new BilledEffectContext('actor', 'instagram', $input, 'run-1', 'source-1', $userId);
}

it('claims only its own (kind, name), leaving the menu actors unclaimed', function () {
    $driver = app(InstagramActorDriver::class);

    expect($driver->supports('actor', 'instagram'))->toBeTrue()
        ->and($driver->supports('actor', 'menu'))->toBeFalse()
        ->and($driver->supports('api', 'instagram'))->toBeFalse();
});

it('claims an Apify budget slot before spending', function () {
    // InstagramScraper claims only for its thin-profile retry; the real cap lives
    // in InstagramController, which the ingest lane never passes through. Without
    // this claim every scheduled run would spend outside the daily cap.
    config()->set('partna.limits.apify.actors.instagram', 0);
    Http::fake();

    expect(fn () => app(InstagramActorDriver::class)->run(igCtx()))
        ->toThrow(EffectNotAttempted::class);

    Http::assertNothingSent();
});

it('spends one budget slot for a healthy profile', function () {
    Http::fake(['api.apify.com/*' => Http::response([['username' => 'maha', 'postsCount' => 3, 'latestPosts' => [['shortCode' => 'A']]]], 201)]);

    $before = app(ApifyBudget::class)->remaining('instagram');
    app(InstagramActorDriver::class)->run(igCtx());

    expect(app(ApifyBudget::class)->remaining('instagram'))->toBe($before - 1);
});

it('returns the actor item as a one-item list, the shape the connector reads', function () {
    Http::fake(['api.apify.com/*' => Http::response([[
        'username' => 'maha',
        'fullName' => 'Maha',
        'postsCount' => 2,
        'latestPosts' => [['shortCode' => 'A'], ['shortCode' => 'B']],
    ]], 201)]);

    $result = app(InstagramActorDriver::class)->run(igCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toHaveCount(1)
        ->and($result->data[0]['username'])->toBe('maha')
        ->and($result->data[0]['latestPosts'])->toHaveCount(2);
});

it('normalises the username the same way the connector does', function () {
    Http::fake(['api.apify.com/*' => Http::response([['username' => 'maha', 'postsCount' => 1, 'latestPosts' => [['shortCode' => 'A']]]], 201)]);

    app(InstagramActorDriver::class)->run(igCtx(['username' => '  @MAHA ']));

    Http::assertSent(fn ($request) => $request['usernames'] === ['maha']);
});

it('treats a positively-reported missing handle as an answer with no data', function () {
    Http::fake(['api.apify.com/*' => Http::response([['username' => 'nope', 'error' => 'not_found']], 201)]);

    $result = app(InstagramActorDriver::class)->run(igCtx(['username' => 'nope']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toBeNull();
});

// ⚠️ ONE CAUSE PER TEST — Http::fake() merges stubs and the first match wins.
it('reports a 5xx from Apify as NoAnswer, not as an empty account', function () {
    Http::fake(['api.apify.com/*' => Http::response([], 503)]);

    expect(app(InstagramActorDriver::class)->run(igCtx())->outcome)->toBe(BilledEffectOutcome::NoAnswer);
});

it('reports a transport failure as NoAnswer', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

    expect(app(InstagramActorDriver::class)->run(igCtx())->outcome)->toBe(BilledEffectOutcome::NoAnswer);
});

it('refuses without claiming budget when the token is missing', function () {
    config()->set('services.apify.token', null);
    Http::fake();

    $before = app(ApifyBudget::class)->remaining('instagram');

    expect(fn () => app(InstagramActorDriver::class)->run(igCtx()))
        ->toThrow(EffectNotAttempted::class);

    expect(app(ApifyBudget::class)->remaining('instagram'))->toBe($before);
    Http::assertNothingSent();
});

it('refuses without claiming budget when the configured actor has no adapter', function () {
    // The adapter is resolved deep inside InstagramScraper::attemptFetch(), AFTER
    // this driver would otherwise have claimed. A wrong actor id would then drain
    // the daily Apify cap doing nothing — checking only the token misses this.
    config()->set('partna.instagram.actor', 'someone~a-scraper-we-never-adapted');
    Http::fake();

    $before = app(ApifyBudget::class)->remaining('instagram');

    expect(fn () => app(InstagramActorDriver::class)->run(igCtx()))
        ->toThrow(EffectNotAttempted::class);

    expect(app(ApifyBudget::class)->remaining('instagram'))->toBe($before);
    Http::assertNothingSent();
});

it('spends a second slot when the profile comes back thin', function () {
    // fetchProfileResult() takes its own ApifyBudget claim for the thin retry, so a
    // thin profile costs TWO runs, not one. Correct — it is a second paid run — but
    // it must be visible rather than discovered from a cap that empties twice as
    // fast as expected.
    Http::fake(['api.apify.com/*' => Http::response([[
        'username' => 'maha',
        'postsCount' => 40,   // claims posts...
        'latestPosts' => [],  // ...and shipped none: thin, per isThinProfile()
    ]], 201)]);

    $before = app(ApifyBudget::class)->remaining('instagram');
    $result = app(InstagramActorDriver::class)->run(igCtx());

    expect(app(ApifyBudget::class)->remaining('instagram'))->toBe($before - 2)
        // Still an ANSWER: the profile stream lands real identity data, and the
        // media stream's post-less branch emits a Note with no Coverage, so nothing
        // is tombstoned. See the driver docblock and slice 1's open question.
        ->and($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data[0]['username'])->toBe('maha');
});

it('reports a missing username as NoAnswer without spending', function () {
    Http::fake();

    expect(app(InstagramActorDriver::class)->run(igCtx([]))->outcome)->toBe(BilledEffectOutcome::NoAnswer);

    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Ingest/InstagramActorDriverTest.php`
Expected: FAIL — `Class "App\Ingest\Runtime\Effects\InstagramActorDriver" not found`.

- [ ] **Step 3: Add `isConfigured()` to `InstagramScraper`**

The knowledge of what "configured" means — token *and* a resolvable adapter for the currently-selected actor — already lives in this class, split across `attemptFetch()`'s first two guards (`:87-102`) and the private `adapterFor()` (`:238-249`). Expose it rather than duplicating it in the driver.

Add to `app/Services/Platforms/InstagramScraper.php`, next to `adapterFor()`:

```php
    /**
     * Whether a scrape could even be attempted: a token AND an adapter for the
     * currently configured actor. Both are checked inside attemptFetch(), but by
     * then a caller that claims budget first has already spent a slot — and the
     * adapter half is the easy one to miss, because a wrong `partna.instagram.actor`
     * looks like a working config until the run returns NotConfigured.
     *
     * Exists for InstagramActorDriver, which must decide whether to claim BEFORE
     * calling in; a config fault has to release the ledger claim, not settle it.
     */
    public function isConfigured(): bool
    {
        return (bool) config('services.apify.token')
            && $this->adapterFor((string) config('partna.instagram.actor')) !== null;
    }
```

- [ ] **Step 4: Write the driver**

`app/Ingest/Runtime/Effects/InstagramActorDriver.php`:

```php
<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Cache\ApifyBudget;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\ProfileFetchFailure;

/**
 * ('actor', 'instagram') — the paid Apify profile scrape.
 *
 * The Apify daily caps are claimed HERE, not in InstagramScraper. That class
 * claims only for its own thin-profile retry and says so; the real cap sits in
 * InstagramController::guardApifyBudget(), which the ingest lane never passes
 * through. Without this claim every scheduled run would spend outside the cap.
 *
 * ORDERING IS LOAD-BEARING: isConfigured() covers BOTH the token and the actor
 * adapter, and runs before the claim. The adapter is resolved deep inside
 * InstagramScraper::attemptFetch(), so checking only the token would let a wrong
 * `partna.instagram.actor` drain the daily Apify cap doing nothing at all.
 *
 * A run costs ONE slot normally and TWO when the profile comes back thin —
 * fetchProfileResult() takes its own claim for the retry. That second slot is
 * correct (it is a second paid run) and is why this driver claims rather than
 * pre-checking remaining().
 */
final class InstagramActorDriver implements BilledEffectDriver
{
    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly ApifyBudget $budget,
    ) {}

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'actor' && $name === 'instagram';
    }

    public function run(BilledEffectContext $ctx): BilledEffectResult
    {
        // Same normalisation InstagramConnector::pull() applies, so the digest and
        // the actor input agree on what was fetched.
        $username = strtolower(ltrim(trim((string) ($ctx->input['username'] ?? '')), '@'));

        if ($username === '') {
            return BilledEffectResult::noAnswer('instagram actor effect carried no username');
        }

        // Both of the next two send nothing, so both release the ledger claim: a
        // config fault must not lock every handle for the freshness window, and must
        // not stay locked once the config is fixed.
        if (! $this->scraper->isConfigured()) {
            throw new EffectNotAttempted('the Apify token or the configured Instagram actor adapter is missing');
        }

        if (! $this->budget->tryClaim('instagram')) {
            throw new EffectNotAttempted("Apify daily cap reached for actor 'instagram'");
        }

        $result = $this->scraper->fetchProfileResult($username, $ctx->userId);

        if ($result->profile !== null) {
            // Apify's dataset shape is a list; InstagramConnector::profileItem()
            // reads $data[0] ?? $data, so returning the vendor's own shape keeps
            // this driver honest about what came back.
            //
            // A THIN profile (2xx, identity present, post timeline missing) is
            // returned as an answer on purpose: the profile stream lands real
            // identity data, and the media stream's post-less branch emits a Note
            // with no Coverage, so nothing is tombstoned. Revisit in slice 1, where
            // a thin result cached for the freshness window would cost real media.
            return BilledEffectResult::answered([$result->profile]);
        }

        return match ($result->failure) {
            // The actor positively reported the handle does not exist. Settling
            // this ok stops us re-billing a dead handle on every run in the window.
            ProfileFetchFailure::ProfileNotFound => BilledEffectResult::answered(null),

            default => BilledEffectResult::noAnswer(
                "instagram actor did not answer for {$username} ({$result->failure?->value})"
            ),
        };
    }
}
```

- [ ] **Step 5: Register it**

In `app/Providers/AppServiceProvider.php`, extend the registry binding:

```php
        $this->app->singleton(BilledEffectDriverRegistry::class, fn ($app) => new BilledEffectDriverRegistry([
            $app->make(PlacesDetailsDriver::class),
            $app->make(InstagramActorDriver::class),
        ]));
```

and add `use App\Ingest\Runtime\Effects\InstagramActorDriver;`.

- [ ] **Step 6: Correct the connector docblock**

`app/Ingest/Connectors/InstagramConnector.php:26-30` claims a per-user cooldown that has no reader. Replace those lines:

```php
 * The hard cap lives where the money moves: ApifyBudget's per-actor + global
 * daily caps (config partna.limits.apify), claimed by InstagramActorDriver
 * immediately before the run. There is deliberately NO per-user cooldown —
 * `partna.instagram.apify_cooldown_seconds` is vestigial config with no reader,
 * and InstagramController documents the same decision for the connect path.
 * This connector only DESCRIBES the effect, and a refused/failed effect verdict
 * folds into Unavailable exactly like an unreachable vendor.
```

- [ ] **Step 7: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Ingest/InstagramActorDriverTest.php tests/Feature/Ingest/InstagramConnectorTest.php tests/Feature/Platforms`
Expected: PASS, 12 new tests plus the existing Instagram connector and scraper suites unchanged. `isConfigured()` is additive, so nothing in `InstagramScraper`'s existing coverage may move.

- [ ] **Step 8: Run the full suite**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
COMPOSER_PROCESS_TIMEOUT=0 composer test
```

Expected: PASS, no new failures. Also run the lanes `composer test` does not cover:

```bash
./vendor/bin/pest tests/Feature/Architecture/OutboundHttpGuardTest.php
./vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: both green. `OutboundHttpGuardTest` matters here — no new outbound call was added, so a failure means a driver reached the network directly instead of delegating.

- [ ] **Step 9: Commit**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
./vendor/bin/pint app/Ingest app/Services/Platforms app/Providers/AppServiceProvider.php tests/Feature/Ingest
git add -A
git commit -m "feat(ingest): wire the Instagram actor billed-effect driver

The driver claims ApifyBudget itself. InstagramScraper claims only for its own
thin-profile retry, and the real cap lives in InstagramController, which the
ingest lane never passes through — so without this every scheduled run would
have spent outside the daily cap. A run costs one slot, or two when the profile
comes back thin and the scraper takes its own retry claim.

InstagramScraper::isConfigured() covers the token AND the actor adapter, checked
before the claim: the adapter is resolved deep inside attemptFetch(), so a wrong
partna.instagram.actor would otherwise drain the cap doing nothing.

A positively-reported missing handle settles ok-with-null rather than re-billing
a dead handle every run; an upstream break is NoAnswer. Corrects the connector
docblock's claim of a per-user cooldown that has no reader."
```

---

### Task 7: Prove the two folds at the `RunExecutor` level

D3 and D5 both rest on "the existing catch already does the right thing" — `EffectNotAttempted` folding to `budget_skipped` via `RunExecutor:87`, and a `NoAnswer` folding to `unavailable` rather than `error`. Every test so far stops at `HttpIo::effect()`'s return array. These two are the reason the deviations are safe, so they get asserted where they actually happen.

**Files:**
- Test: `tests/Feature/Ingest/BilledEffectRunOutcomesTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–6.
- Produces: nothing.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Ingest/BilledEffectRunOutcomesTest.php`:

```php
<?php

use App\Ingest\Connectors\GoogleBusinessConnector;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectDriver;
use App\Ingest\Runtime\Effects\BilledEffectDriverRegistry;
use App\Ingest\Runtime\Effects\BilledEffectResult;
use App\Ingest\Runtime\RunExecutor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupIngestTables();
    config()->set('partna.ingest.billed_effects_enabled', true);
});

/** A driver for ('api','places.details') whose run() does whatever you pass. */
function stubPlacesDriver(\Closure $behaviour): BilledEffectDriver
{
    return new class($behaviour) implements BilledEffectDriver
    {
        public function __construct(private \Closure $behaviour) {}

        public function supports(string $kind, string $name): bool
        {
            return $kind === 'api' && $name === 'places.details';
        }

        public function run(BilledEffectContext $ctx): BilledEffectResult
        {
            return ($this->behaviour)($ctx);
        }
    };
}

/** Seed one google_business source and run it through the REAL RunExecutor. */
function runGoogleSourceWith(BilledEffectDriver $driver): array
{
    app()->singleton(BilledEffectDriverRegistry::class, fn () => new BilledEffectDriverRegistry([$driver]));

    $sourceId = (string) Str::uuid();
    DB::table('ingest.sources')->insert([
        'id' => $sourceId,
        'source_key' => 'google_business',
        'identifier' => 'ChIJabc',
        'auto_sync' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $source = (array) DB::table('ingest.sources')->where('id', $sourceId)->first();

    return app(RunExecutor::class)->execute(
        $source,
        new GoogleBusinessConnector,
        GoogleBusinessConnector::manifest(),
        'manual',
    );
}

it('folds a not-attempted refusal to budget_skipped and leaves no ledger row', function () {
    // D3's whole claim: EffectNotAttempted extends EffectRefused, so RunExecutor:87
    // already handles it and needs no change.
    $result = runGoogleSourceWith(stubPlacesDriver(
        fn () => throw new EffectNotAttempted('places daily cap reached'),
    ));

    expect($result['outcome'])->toBe('budget_skipped')
        ->and(array_unique(array_values($result['streams'])))->toBe(['budget_skipped'])
        // Released, not settled — the digest must be claimable the moment the cap resets.
        ->and(DB::table('ingest.effects')->count())->toBe(0);

    // 'budget' maps to health 'degraded', not 'unavailable' (RunExecutor::recordStreamFailure).
    expect(DB::table('ingest.streams')->pluck('health')->unique()->all())->toBe(['degraded']);
});

it('folds a no-answer to unavailable, not to error', function () {
    // D5's whole claim: returning a failed verdict rather than rethrowing keeps a
    // vendor outage out of the 'error' bucket, which reads as our own bug and
    // report()s to Nightwatch.
    $result = runGoogleSourceWith(stubPlacesDriver(
        fn () => BilledEffectResult::noAnswer('places returned 503'),
    ));

    expect($result['outcome'])->toBe('unavailable')
        ->and(array_unique(array_values($result['streams'])))->toBe(['unavailable']);

    // Settled failed and RETAINED — a request went out, so the charge is unknown.
    $effects = DB::table('ingest.effects')->get();
    expect($effects)->toHaveCount(1)
        ->and($effects[0]->status)->toBe('failed')
        ->and($effects[0]->settled_at)->not->toBeNull();

    // No anomaly: an outage is not a delete-guard or a shape violation.
    expect(DB::table('ingest.anomalies')->where('severity', 'critical')->count())->toBe(0);
});

it('folds the kill switch to budget_skipped without writing a ledger row', function () {
    config()->set('partna.ingest.billed_effects_enabled', false);

    $result = runGoogleSourceWith(stubPlacesDriver(
        fn () => BilledEffectResult::answered(['displayName' => ['text' => 'unreachable']]),
    ));

    expect($result['outcome'])->toBe('budget_skipped')
        ->and(DB::table('ingest.effects')->count())->toBe(0);
});

it('bills once for a whole run and lands records on every stream', function () {
    $calls = 0;
    $result = runGoogleSourceWith(stubPlacesDriver(function () use (&$calls) {
        $calls++;

        return BilledEffectResult::answered([
            'displayName' => ['text' => 'Maha'],
            'formattedAddress' => '21 Bond St',
            'reviews' => [['name' => 'places/abc/reviews/r1', 'rating' => 5, 'text' => ['text' => 'Great']]],
            'photos' => [['name' => 'places/abc/photos/p1']],
        ]);
    }));

    expect($result['outcome'])->toBe('ok')
        ->and($calls)->toBe(1)
        ->and($result['streams'])->toBe(['profile' => 'ok', 'reviews' => 'ok', 'media' => 'ok'])
        ->and(DB::table('ingest.effects')->where('status', 'ok')->count())->toBe(1);

    expect(DB::table('ingest.record_state')->whereNull('tombstoned_at')->count())->toBe(3);
});
```

- [ ] **Step 2: Run test to verify it fails, then passes**

Run: `./vendor/bin/pest tests/Feature/Ingest/BilledEffectRunOutcomesTest.php`

If it fails, read the failure before touching anything: these tests assert behaviour Tasks 1–6 were supposed to have delivered. A failure here means a deviation's justification was wrong, not that the test is.

Expected once green: PASS, 4 tests.

> **If `runGoogleSourceWith()` errors on a missing `ingest.sources` column,** compare the insert against `setupIngestTables()` in `tests/Pest.php` and add whatever non-nullable columns it declares. Do not stub `RunExecutor` to route around it — driving the real one is the entire point of this task.

- [ ] **Step 3: Commit**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
./vendor/bin/pint tests/Feature/Ingest/BilledEffectRunOutcomesTest.php
git add tests/Feature/Ingest/BilledEffectRunOutcomesTest.php
git commit -m "test(ingest): pin the two billed-effect folds at the RunExecutor level

EffectNotAttempted -> budget_skipped with no ledger row; a NoAnswer -> unavailable
with a settled, retained row. Both were justified by 'the existing catch already
does the right thing' and neither was exercised anywhere above HttpIo's return
array."
```

---

### Task 8: Stale-comment cleanup, dev verification, and the checkpoint

Spec §3 invariant 1: no slice is done without a live database assertion, output pasted into the checkpoint.

**Files:**
- Modify: `app/Ingest/SourceProvisioner.php:115-126`
- Modify: `tests/Feature/Ingest/RunExecutorClaimGateTest.php:16-21`
- Modify: `tests/Feature/Ingest/SourceProvisionerTest.php:336-339`
- Modify: `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` (append the checkpoint)

- [ ] **Step 1: Correct the three stale "the drivers land at P7" comments**

`app/Ingest/SourceProvisioner.php:115-126` — replace the `schedulable()` docblock:

```php
    /**
     * Whether the scheduler may run this source TODAY. Billed connectors stay
     * OFF the dispatcher even now that their drivers exist (slice 0): enabling
     * paid auto-sync is a spend decision that belongs to the slice which uses the
     * data, not to the seam that makes it possible. Flipping this predicate is a
     * one-line change plus a deliberate look at PlacesBudget/ApifyBudget headroom.
     */
```

`tests/Feature/Ingest/RunExecutorClaimGateTest.php:16-21` — the "not writable today" note is now false. Replace:

```php
// An end-to-end test through a REAL connector became possible in slice 0, when
// the billed-effect drivers landed (see PlacesDetailsDriverTest's end-to-end
// case). This file keeps its test-local fake connector on purpose: it is about
// isClaimed()'s core.users.status -> bool mapping, and a fake keeps that
// isolated from any vendor's payload shape.
```

`tests/Feature/Ingest/SourceProvisionerTest.php:336-339` — replace the comment inside `it('creates billed-connector sources unscheduled…')`:

```php
    // The drivers exist as of slice 0, but auto_sync stays false: turning on paid
    // auto-sync is a spend decision for the slice that consumes the data. The row
    // must exist (the seam is complete); it just must not auto-run yet.
```

Also rename that test to match what it now asserts:

```php
it('creates billed-connector sources unscheduled even once their effect drivers exist', function () {
```

- [ ] **Step 2: Run the two touched test files**

Run: `./vendor/bin/pest tests/Feature/Ingest/RunExecutorClaimGateTest.php tests/Feature/Ingest/SourceProvisionerTest.php`
Expected: PASS.

- [ ] **Step 3: Capture the pre-deploy dev baseline**

Spec §5.4 asserts `ingest.effects` has held zero rows since creation. Capture that BEFORE anything runs, so the post-run assertion means something. Via the Supabase MCP against `glncumufgaqcmqhzwrxm`:

```sql
SELECT kind, cost_tag, status, count(*) FROM ingest.effects GROUP BY 1,2,3;
```

Expected: 0 rows. Paste the output into the checkpoint.

- [ ] **Step 4: Merge and deploy to development**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
git checkout development && git pull
git merge --no-ff feature/billed-effect-driver-seam
COMPOSER_PROCESS_TIMEOUT=0 composer test   # run the suite ON the merge, not just the branch
git push origin development
```

No migration to apply — this slice adds none.

- [ ] **Step 5: Turn the switch on for development only**

```bash
~/.composer/vendor/bin/cloud environment:get development --json --fields=environmentVariables
```

`environment:variables` **REPLACES ALL** — read the full set first, then write it back with `PARTNA_INGEST_BILLED_EFFECTS_ENABLED=true` added. **Leave production at the default false.**

Confirm it took:

```bash
~/.composer/vendor/bin/cloud command:run development "tinker --execute=\"echo var_export(config('partna.ingest.billed_effects_enabled'), true);\""
```

Expected: `true`.

- [ ] **Step 6: Execute one real billed effect on dev**

> ⚠️ **This step spends real money.** It issues a live, billed Places Details request against the dev Google key — one Enterprise+Atmosphere-tier call. That is the point (nothing else proves the seam works end to end), but it must be a deliberate act, not a side effect of running a checklist.

There is no `ingest:run <source>` command, and `SourceScheduler::claimDue()` filters `auto_sync = true`, which billed sources are not. `RunSourceJob::handle()` does **not** gate on `auto_sync`, so dispatching it directly is the trigger.

Pre-flight — all three must hold before you run anything:

```bash
# 1. The key exists, or the run returns NotConfigured and proves nothing.
~/.composer/vendor/bin/cloud environment:get development --json --fields=environmentVariables \
  | grep -c GOOGLE_MAPS_SERVER_API_KEY

# 2. There is budget headroom, or the run refuses and proves nothing.
~/.composer/vendor/bin/cloud command:run development \
  "tinker --execute=\"echo app(App\Services\Cache\PlacesBudget::class)->remaining('details');\""
```

```sql
-- 3. Pick a source. Prefer one whose user_id is a dev test account.
SELECT id, source_key, identifier, user_id, auto_sync
FROM ingest.sources WHERE source_key = 'google_business' LIMIT 3;
```

Then run it. Use a **heredoc**, not nested escaped quotes — the earlier `\\\\`-style form passes through bash → `cloud command:run` → tinker's own parser and mangles namespaces silently:

```bash
SOURCE_ID='<paste-the-uuid>'
read -r -d '' SNIPPET <<'PHP'
$id = getenv('SOURCE_ID');
$job = new App\Jobs\Ingest\RunSourceJob($id);
$job->handle(app(App\Ingest\Runtime\SourceScheduler::class), app(App\Ingest\Runtime\RunExecutor::class));
echo 'done';
PHP
~/.composer/vendor/bin/cloud command:run development "tinker --execute=\"${SNIPPET}\""
```

> **Side effect to expect, not to be alarmed by:** `RunSourceJob::handle()`'s `finally` calls `SourceScheduler::release()` on a source that was never claimed, so `next_attempt_at`, `consecutive_failures`, `health` and the `change_rate` EWMA all move on that row. Harmless while `auto_sync = false` — nothing reads them — but note it in the checkpoint so a later reader does not mistake it for the scheduler having picked the source up.

- [ ] **Step 7: Assert the ledger recorded it**

```sql
-- The headline: this table has been empty since it was created.
SELECT kind, cost_tag, status, count(*) FROM ingest.effects GROUP BY 1,2,3;

-- Every row must be settled — a lingering 'claimed' means a process died mid-effect.
SELECT digest, kind, cost_tag, status, cost_units, claimed_at, settled_at
FROM ingest.effects ORDER BY claimed_at DESC LIMIT 10;

-- The run itself, and what each stream did.
SELECT r.outcome, r.records_seen, r.records_changed, r.detail
FROM ingest.runs r JOIN ingest.sources s ON s.id = r.source_id
WHERE s.source_key = 'google_business' ORDER BY r.started_at DESC LIMIT 3;

-- Nothing money-adjacent should have fired.
SELECT kind, severity, summary, detected_at
FROM ingest.anomalies WHERE detected_at > now() - interval '1 hour' ORDER BY detected_at DESC;

-- The whole point: records landed.
SELECT st.stream_name, count(*)
FROM ingest.record_state rs JOIN ingest.streams st ON st.id = rs.stream_id
JOIN ingest.sources s ON s.id = st.source_id
WHERE s.source_key = 'google_business' AND rs.tombstoned_at IS NULL
GROUP BY 1;
```

Pass conditions: `ingest.effects` holds exactly one `api` / `places.details` row with `status='ok'` and a non-null `settled_at`; the run's `detail.streams` shows `profile`, `reviews` and `media`; `record_state` is non-zero for at least `profile`; no `critical` anomaly.

- [ ] **Step 8: Scan the logs**

```bash
~/.composer/vendor/bin/cloud env:logs partna development --minutes 10
```

Expected: clean. Specifically no `ingest.effect.replay_unavailable` (which would mean the response exceeded the ledger's 1 MB inline ceiling and the sibling streams got nothing), and no `google_business.details_fetch_failed`.

- [ ] **Step 9: Check Nightwatch**

Via the Nightwatch MCP, list issues for `partna` / `development` from the last hour. Expected: nothing new. `PlaceDetailsUnavailableException` or `AbandonedEffectException` here means step 7's pass conditions were read too generously.

- [ ] **Step 10: Append the slice 0 checkpoint to the spec**

Add a `## 12. Slice 0 checkpoint — <date>` section to `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` containing:

1. Each SQL block from steps 3 and 7 with its **actual pasted output**.
2. The Pest test names proving the chain: `BilledEffectDriverRegistryTest`, `BilledEffectLedgerOutcomesTest`, `BilledEffectDispatchTest`, `PlaceDetailsRawFetchTest`, `PlacesDetailsDriverTest` (including the end-to-end connector case), `InstagramActorDriverTest`, `BilledEffectRunOutcomesTest`.
3. The step 8 log scan result.
4. The **six** spec corrections D1–D6 from this plan, so §5 stops describing a design that was not built. D6 in particular **amends §5.3's closing claim that a `NoAnswer` is retryable** — it is not, deliberately, and the spec's own sentence is the thing that is wrong.
5. An explicit statement that `auto_sync` remains `false` for both billed connectors and that `PARTNA_INGEST_BILLED_EFFECTS_ENABLED` is **true on development, false on production**.
6. The cost of step 6: one billed Places Details call, and the `SourceScheduler::release()` side effect on the source row it triggered.

No `docs/wire-changes/` entry is needed — slice 0 changes no endpoint shape.

- [ ] **Step 11: Commit the docs**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
git add docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md app/Ingest/SourceProvisioner.php tests/Feature/Ingest
git commit -m "docs(spec): record the slice 0 checkpoint and its five corrections

Corrects §5.1 (InstagramScraper enforces no cap), §5.2 (the Google driver must
return the raw Places response, not the mapped payload), §5.3 (budget refusal
removes the claim rather than settling 'refused'; the kill switch needs no
SourceProvisioner change), and adds the NoAnswer-returns-rather-than-rethrows
decision. Clears the three 'drivers land at P7' comments now falsified."
git push origin development
```

- [ ] **Step 12: Clean up**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
git branch -d feature/billed-effect-driver-seam
```

---

## What slice 1 inherits

Recorded here so the next spec starts from facts rather than rediscovering them:

- **No *scheduled* trigger exists for a billed source, and the manual one is a tinker call.** `SourceScheduler::claimDue()` filters `auto_sync = true` and `SourceProvisioner::schedulable()` returns false for anything not `CostClass::Free`, so nothing dispatches these. `RunSourceJob::handle()` does not itself gate on `auto_sync`, so dispatching the job by hand works — but it calls `SourceScheduler::release()` in a `finally` on a source that was never claimed, mutating `next_attempt_at` / `consecutive_failures` / `health` / the change-rate EWMA. Slice 1 needs a real trigger: widen `schedulable()`, add an `ingest:run <source>` command, or dispatch from the connect path.
- **A thin Instagram profile settles `ok` for the freshness window** (7 days). Fine for slice 0, where nothing consumes media; slice 1 should decide whether a post-less answer deserves its own outcome.
- **Photo refs are not resolved to servable URLs.** `PlacesDetailsDriver` deliberately skips `resolvePhotoUrls()` — up to 15 further billed media calls per run. Slice 1 owns that, alongside §2.4's decision to read the 82 existing `google-photo` URLs from the legacy `site.platform_connections` payload rather than re-billing.
- **The ledger's 1 MB inline result ceiling** (`EffectLedger::RESULT_INLINE_MAX_BYTES`) is what lets the second and third streams of a run replay a paid result. The Apify profile actor returns up to 12 posts, so today's payloads clear it comfortably — but any actor change that raises the post count moves toward `ingest.effect.replay_unavailable`, where the first stream lands and its siblings get nothing.
- **The three menu connectors still hit the throw.** `Doordash`, `UberEats` and `Square` all declare `('actor', 'menu')` and no driver claims it. That is deliberate — slice 4 owns menus.
