# Effect ledger — #MONEY-1 and #MONEY-2 · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop a successful, paid-for billed effect being recorded as `failed` and its result discarded when the settle write fails (#MONEY-1, P1), and stop claiming a ledger row for an effect that was never going to be attempted (#MONEY-2, P3).

**Architecture:** `EffectLedger::once()` currently wraps the vendor call AND the settle write in one `try`, so its catch-all cannot tell "the paid call failed" from "the paid call succeeded and the bookkeeping failed". Task 1 narrows the `try` to the vendor call alone and moves the success settle into a method that can never convert a paid answer into a failure. Task 3 adds an optional pre-claim check so a driver that knows it cannot attempt the effect refuses before a row is written.

**Tech Stack:** PHP 8.4, Laravel 12, PostgreSQL (Supabase), Pest 4. No new dependencies. **No schema migration** — see the anomaly decision in Task 1.

**Source:** audit findings #MONEY-1 (P1) and #MONEY-2 (P3), raised against `app/Ingest/Runtime/EffectLedger.php` after slice 0 made `runBilledEffect()` reachable.

---

## Why this is its own branch

CLAUDE.md's blocker gate: **money** changes plan first and wait for sign-off, and are **Standalone — do NOT bundle**. This touches the charge-once ledger, which is the only thing standing between a retry storm and a duplicated vendor bill. It does not ride along with slice 1.

## The defect, precisely

`EffectLedger::once()` (`app/Ingest/Runtime/EffectLedger.php:107-172`):

```php
try {
    $result = $effect();                              // ← money spent, answer in hand
    $meta = ['summary' => $this->summarise($result)];
    // … inline-size guard …
    DB::table('ingest.effects')->where('digest', $digest)->update([
        'status' => 'ok', 'settled_at' => now(), 'meta' => json_encode($meta),
    ]);                                               // ← if THIS throws …
    return ['status' => 'ok', 'result' => $result, 'cached' => false];
} catch (EffectNotAttempted $e) { … }
  catch (EffectNoAnswer $e)     { … }
  catch (\Throwable $e) {                             // ← … we land here
    DB::table('ingest.effects')->where('digest', $digest)->update([
        'status' => 'failed', 'settled_at' => now(),
        'meta' => json_encode(['error' => class_basename($e), …]),
    ]);
    throw $e;                                         // $result discarded
}
```

The catch-all's own comment states the false premise: *"A failure IS settled: we know it happened and what it cost."* Reaching that block means **something after the vendor call threw** — not that the vendor call failed.

Three consequences, worst last:

1. The paid-for `$result` is dropped on the floor.
2. The row is stamped `failed` with a misleading `error` naming the DB exception, so anyone reconciling spend reads "the vendor call failed" when it succeeded.
3. `verdictFor()` (`:193`) returns `['status' => 'failed', 'result' => null, 'cached' => true]` for that digest for the **whole freshness window** (`partna.ingest.effect_freshness_seconds`, 7 days). The retry cannot recover it, and neither can a sibling stream in the same run.

**Reachability, and why it is ours.** The code is unchanged, but before slice 0 `HttpIo::runBilledEffect()` threw unconditionally, so `$effect()` could never return and the settle write was dead code. Slice 0 made it reachable. Ownership follows reachability.

**Not in scope:** the `EffectNoAnswer` and `EffectNotAttempted` endings are correct as written and Task 1 must not alter their behaviour. Their tests are the regression gate.

---

## Global Constraints

- **Branch:** `audit-fix/effect-ledger-money-2026-08-12`, cut from `development`. PR → merge to `development`. **Never push to `production`.**
- **`development`'s PHPStan gate is currently RED** — 19 pre-existing errors in the email-overhaul code (`BaseTransactionalMail`, `MailPreviewController`, `CategoryNotificationMail`, `NotifyReportedUserJob`, five stale baseline entries), confirmed present on `origin/development` with none of this work applied. Pushes need `git push --no-verify`. **Do not "fix" them in this branch** — a money branch stays scoped. Verify your own files are clean with `./vendor/bin/phpstan analyse app/Ingest/Runtime` instead.
- **Never create Laravel migration files.** Schema changes are raw SQL in `supabase/migrations/`. This plan needs none — see Task 1 Step 4.
- **Tests run SQLite in-memory; production is Postgres.** `tests/Pest.php`'s `setupIngestTables()` provides `ingest.effects` (`:2682`) and `ingest.anomalies` (`:2699`).
- **SQLite trigger DDL puts the schema qualifier on the TRIGGER name, not the table** — `CREATE TRIGGER ingest.tg_foo BEFORE UPDATE ON effects …` — the same quirk the index stand-ins have (`tests/Pest.php:838-845`). Getting it wrong throws for the whole test file.
- **`./vendor/bin/pint`, not `php artisan pint`** — the artisan command does not exist in this repo and reports "Command not defined".
- **Do not use `expect(...)->toThrow(Throwable::class)`.** Pest branches on `class_exists()`, and `Throwable` is an interface, so it silently compares the string as an expected exception *message* and fails a correct throw. Name a concrete class.
- 4-space indent, LF. Comments explain WHY. `composer test` green before any task is done. Long runs need `COMPOSER_PROCESS_TIMEOUT=0`.

---

## File Structure

| File | Change | Responsibility |
|---|---|---|
| `app/Ingest/Runtime/EffectLedger.php` | Modify | Narrow the `try`; add `settleOk()`; add optional `precheck` param |
| `app/Ingest/Runtime/Effects/PrecheckableBilledEffect.php` | Create | Opt-in interface: a driver that can refuse before the claim |
| `app/Ingest/Runtime/Effects/PlacesDetailsDriver.php` | Modify | Implement `precheck()` — budget + configuration |
| `app/Ingest/Runtime/HttpIo.php` | Modify | Pass a `precheck` closure into `once()` |
| `tests/Feature/Ingest/BilledEffectSettleFailureTest.php` | Create | #MONEY-1: the paid result survives a settle failure |
| `tests/Feature/Ingest/BilledEffectLedgerOutcomesTest.php` | Modify | #MONEY-2: no row is claimed for a pre-refused effect |

---

### Task 1: A settle-write failure must not destroy a paid result

**Files:**
- Modify: `app/Ingest/Runtime/EffectLedger.php` (the `try` at `:107`, the catch-all at `:162`)
- Test: `tests/Feature/Ingest/BilledEffectSettleFailureTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: `EffectLedger::settleOk(string $digest, mixed $result): void` (private, never throws). `once()`'s public signature and return shape are unchanged.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Ingest/BilledEffectSettleFailureTest.php`:

```php
<?php

use App\Ingest\Runtime\EffectLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// #MONEY-1. The vendor call SUCCEEDS and the settle UPDATE then fails. Before
// this fix the catch-all assumed "we are in here, so the call failed": it
// stamped the row 'failed', threw the paid result away, and locked the digest
// out of verdictFor() for the whole seven-day freshness window.

beforeEach(function () {
    setupIngestTables();

    // A real DB failure on the settle write, not a mock. The schema qualifier
    // goes on the TRIGGER name, not the table — same SQLite quirk as the index
    // stand-ins in tests/Pest.php.
    DB::connection('pgsql')->statement(
        "CREATE TRIGGER ingest.tg_effects_settle_boom BEFORE UPDATE ON effects
         WHEN NEW.digest = 'settle-boom'
         BEGIN SELECT RAISE(ABORT, 'settle write failed'); END"
    );
});

afterEach(function () {
    DB::connection('pgsql')->statement('DROP TRIGGER IF EXISTS ingest.tg_effects_settle_boom');
});

it('returns the paid-for result even when the settle write fails', function () {
    $ledger = new EffectLedger;

    $outcome = $ledger->once(
        digest: 'settle-boom',
        kind: 'api',
        effect: fn () => ['place' => 'answered', 'rating' => 4.5],
        costTag: 'places.details',
    );

    // The whole point: we paid, we got an answer, the caller receives it.
    expect($outcome['status'])->toBe('ok')
        ->and($outcome['result'])->toBe(['place' => 'answered', 'rating' => 4.5])
        ->and($outcome['cached'])->toBeFalse();
});

it('never stamps a successful paid call as failed', function () {
    $ledger = new EffectLedger;

    $ledger->once(
        digest: 'settle-boom',
        kind: 'api',
        effect: fn () => ['place' => 'answered'],
        costTag: 'places.details',
    );

    $row = DB::table('ingest.effects')->where('digest', 'settle-boom')->first();

    // 'failed' would be a lie about a call that succeeded, and it would make
    // verdictFor() serve that lie for the rest of the freshness window.
    expect($row->status)->not->toBe('failed')
        // Left CLAIMED and unsettled: the honest "we paid, the books do not
        // know it" state, and the one markAbandoned() already owns.
        ->and($row->status)->toBe('claimed')
        ->and($row->settled_at)->toBeNull();
});

it('logs the unrecorded charge loudly, without touching the database', function () {
    // We are already in a path entered BECAUSE a DB write failed. The alarm
    // must not itself be a DB write — see the anomaly decision in the plan.
    Log::spy();
    $ledger = new EffectLedger;

    $ledger->once(
        digest: 'settle-boom',
        kind: 'api',
        effect: fn () => ['place' => 'answered'],
        costTag: 'places.details',
    );

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context = []) => $message === 'ingest.effect.settle_unrecorded'
            && ($context['digest'] ?? null) === 'settle-boom'
            && ($context['cost_tag'] ?? null) === 'places.details')
        ->once();

    expect(DB::table('ingest.anomalies')->count())->toBe(0);
});

it('still settles and rethrows when the EFFECT itself fails', function () {
    // The regression gate for the narrowing: a genuine vendor failure must
    // keep its old behaviour exactly — settled 'failed', exception rethrown.
    $ledger = new EffectLedger;

    expect(fn () => $ledger->once(
        digest: 'effect-really-failed',
        kind: 'api',
        effect: fn () => throw new RuntimeException('vendor 500'),
        costTag: 'places.details',
    ))->toThrow(RuntimeException::class, 'vendor 500');

    $row = DB::table('ingest.effects')->where('digest', 'effect-really-failed')->first();
    expect($row->status)->toBe('failed')
        ->and($row->settled_at)->not->toBeNull()
        ->and(json_decode((string) $row->meta, true)['error'])->toBe('RuntimeException');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Ingest/BilledEffectSettleFailureTest.php`

Expected: the first three FAIL. Specifically the first fails because the `RuntimeException` from the trigger propagates out of `once()` instead of returning `ok`; the second fails on `status` being `failed`. The fourth should already PASS — it pins behaviour this task must not change.

If the fourth FAILS, stop: the trigger is firing on the wrong rows and every assertion in the file is suspect.

- [ ] **Step 3: Narrow the `try` to the vendor call**

In `app/Ingest/Runtime/EffectLedger.php`, replace the whole block from `try {` at `:107` through the end of the catch-all at `:172` with:

```php
        try {
            $result = $effect();
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
            // for an unknown charge, not an oversight.
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
            // Reaching here now means THE VENDOR CALL ITSELF threw — the settle
            // write is no longer inside this try (#MONEY-1). That distinction is
            // the whole fix: the old catch-all also caught a failure of the
            // bookkeeping below and stamped 'failed' on a call that had
            // succeeded, discarding the result we had already paid for.
            //
            // A failure IS settled: we know it happened and what it cost. It
            // is the UNKNOWN (process death) that must never auto-retry.
            DB::table('ingest.effects')->where('digest', $digest)->update([
                'status' => 'failed',
                'settled_at' => now(),
                'meta' => json_encode(['error' => class_basename($e), 'message' => mb_substr($e->getMessage(), 0, 500)]),
            ]);

            throw $e;
        }

        // PAID AND ANSWERED. Past this line nothing may turn $result into a
        // failure — settleOk() is bookkeeping, and bookkeeping that fails does
        // not un-spend the money or un-answer the question.
        $this->settleOk($digest, $result, $kind, $costTag);

        return ['status' => 'ok', 'result' => $result, 'cached' => false];
    }
```

- [ ] **Step 4: Add `settleOk()`**

Add immediately after `once()`:

```php
    /**
     * Record a successful, already-paid effect. NEVER THROWS.
     *
     * Split out of once()'s try block for #MONEY-1: while the settle write sat
     * inside the same try as $effect(), a DB hiccup here landed in the
     * catch-all, which stamped the row 'failed' and discarded the result. The
     * caller had paid Google, Google had answered, and the answer went in the
     * bin — with the digest then serving that false 'failed' out of
     * verdictFor() for the whole seven-day freshness window.
     *
     * ON FAILURE THE ROW IS LEFT CLAIMED AND UNSETTLED, deliberately. The three
     * candidate states:
     *
     *   'ok'      — cannot: we just failed to write it, and writing it again is
     *               the thing that failed.
     *   'failed'  — a lie. The vendor call succeeded and we were charged.
     *   claimed   — honest: "money left, the books do not know". It is also the
     *               state markAbandoned() already owns — after the abandon
     *               window it flips to 'abandoned' and files a CRITICAL
     *               anomaly for spend reconciliation, which is exactly the
     *               review this situation deserves.
     *
     * Accepted cost of that choice: the digest is refused (not replayed) until
     * the abandon window passes, so a sibling stream in the same run re-reads
     * it as unavailable rather than getting the cached answer. Strictly better
     * than the alternative — the CURRENT run still receives the paid result.
     *
     * THE ALARM IS A LOG LINE, NOT AN ANOMALY ROW. We are here because a
     * database write failed; filing ingest.anomalies is another database write
     * and would very likely fail in the same breath. report() reaches
     * Nightwatch out of band. (A dedicated anomaly kind would also need a new
     * value in the closed anomalies_kind_check domain — a two-file NOT VALID +
     * VALIDATE migration — for an alarm that cannot be trusted to land.)
     */
    private function settleOk(string $digest, mixed $result, string $kind, ?string $costTag): void
    {
        try {
            // Persist the result WITH the settlement: a replay (same-run
            // sibling stream, or a retry) must return the data that was paid
            // for, or charge-once quietly turns "ok" into "no data".
            $meta = ['summary' => $this->summarise($result)];
            $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded !== false && strlen($encoded) <= self::RESULT_INLINE_MAX_BYTES) {
                $meta['result'] = $result;
            } else {
                $meta['result_omitted'] = true;
            }

            DB::table('ingest.effects')->where('digest', $digest)->update([
                'status' => 'ok',
                'settled_at' => now(),
                'meta' => json_encode($meta),
            ]);
        } catch (\Throwable $e) {
            Log::error('ingest.effect.settle_unrecorded', [
                'digest' => $digest,
                'kind' => $kind,
                'cost_tag' => $costTag,
                'error' => class_basename($e),
                'message' => mb_substr($e->getMessage(), 0, 500),
            ]);

            // Out-of-band, because the database is the thing that just failed.
            report($e);
        }
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Ingest/BilledEffectSettleFailureTest.php`
Expected: PASS (4 tests)

- [ ] **Step 6: Run the existing ledger suites for regressions**

The `EffectNotAttempted` / `EffectNoAnswer` / abandon behaviours are untouched by design, and these are the files that prove it.

Run: `./vendor/bin/pest tests/Feature/Ingest/BilledEffectLedgerOutcomesTest.php tests/Feature/Ingest/EffectLedgerTest.php tests/Feature/Ingest/BilledEffectDispatchTest.php tests/Feature/Ingest/BilledEffectRunOutcomesTest.php`
Expected: PASS, no count change.

- [ ] **Step 7: Commit**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
./vendor/bin/pint app/Ingest/Runtime/EffectLedger.php tests/Feature/Ingest/BilledEffectSettleFailureTest.php
git add app/Ingest/Runtime/EffectLedger.php tests/Feature/Ingest/BilledEffectSettleFailureTest.php
git commit -m "fix(ingest): a settle-write failure no longer discards a paid result

#MONEY-1. once() wrapped the vendor call and the settle UPDATE in one
try, so the catch-all could not tell 'the paid call failed' from 'the
paid call succeeded and the bookkeeping failed'. A DB hiccup on the
settle write therefore stamped the row 'failed', threw away the result
we had already been charged for, and — because verdictFor() serves a
settled row for the whole freshness window — locked that digest out for
seven days behind a misleading error naming the DB exception.

The try now covers \$effect() alone. Past it the call has succeeded, and
settleOk() is bookkeeping that cannot throw: on failure the row is left
CLAIMED and unsettled, which is the honest 'money left, the books do not
know' state and the one markAbandoned() already owns, while the caller
still receives the answer it paid for.

The alarm is a log line plus report(), not an ingest.anomalies row: we
are here because a database write failed, so the alarm must not be
another one.

Not new code — the settle write was unreachable until slice 0 wired
runBilledEffect() to real drivers. Enabling the path is what made it
real."
```

---

### Task 2: Prove the fix against the real ledger, end to end

Task 1's test drives `EffectLedger` directly. This one goes through `HttpIo`, which is the only production `Io`, so the fix is proven at the seam a connector actually uses.

**Files:**
- Test: `tests/Feature/Ingest/BilledEffectSettleFailureTest.php` (append)

**Interfaces:**
- Consumes: `EffectLedger::settleOk()` behaviour from Task 1.
- Produces: nothing.

- [ ] **Step 1: Write the test**

Append to `tests/Feature/Ingest/BilledEffectSettleFailureTest.php`. Read `tests/Feature/Ingest/BilledEffectDispatchTest.php` first and copy its `HttpIo` construction verbatim — it already builds the `Manifest`, the fake driver registry and the `SafeUrlFetcher`, and duplicating that setup wrongly is the most likely way to waste an hour here.

```php
it('hands the connector its paid data even when the ledger cannot record it', function () {
    // Same failure, one layer up: HttpIo is the only production Io, so this is
    // the seam a real connector sees. `data` must carry the answer, not null.
    config()->set('partna.ingest.billed_effects_enabled', true);

    // Build $io exactly as BilledEffectDispatchTest does, with a driver whose
    // run() returns BilledEffectResult::answered(['place' => 'answered']).
    // Then make the settle write fail for whatever digest that produces:
    //   1. call $io->effect(...) once with the trigger DROPPED to learn the digest,
    //      reading it from ingest.effects; OR
    //   2. simpler — widen the trigger to fire on ANY update whose NEW.status = 'ok':
    //
    //      CREATE TRIGGER ingest.tg_effects_ok_boom BEFORE UPDATE ON effects
    //      WHEN NEW.status = 'ok'
    //      BEGIN SELECT RAISE(ABORT, 'settle write failed'); END
    //
    // Use (2) — it needs no digest arithmetic and it targets exactly the write
    // this task is about.

    $outcome = $io->effect('api', 'places.details', ['place_id' => 'ChIJtest']);

    expect($outcome['status'])->toBe('ok')
        ->and($outcome['data'])->toBe(['place' => 'answered'])
        ->and($outcome['cached'])->toBeFalse();
});
```

- [ ] **Step 2: Run it**

Run: `./vendor/bin/pest tests/Feature/Ingest/BilledEffectSettleFailureTest.php`
Expected: PASS (5 tests).

If `status` is `'refused'` rather than `'ok'`, the trigger is firing on the CLAIM insert rather than the settle update — check it is `BEFORE UPDATE`, not `BEFORE INSERT OR UPDATE`.

- [ ] **Step 3: Commit**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
./vendor/bin/pint tests/Feature/Ingest/BilledEffectSettleFailureTest.php
git add tests/Feature/Ingest/BilledEffectSettleFailureTest.php
git commit -m "test(ingest): pin #MONEY-1 at the HttpIo seam a connector uses

EffectLedger is exercised directly by the other cases; this drives the
only production Io, so the guarantee 'the connector receives the data it
paid for' is asserted where a connector would actually observe it."
```

---

### Task 3: Do not claim a row for an effect that will not be attempted

#MONEY-2, P3. **Separable — drop this task if you want the branch smaller.** No correctness impact; it removes an INSERT and a DELETE per refused attempt.

**Why it happens.** `once()` writes the claim row (`:77`) before running the closure, and the budget/configuration check lives inside the driver (`PlacesDetailsDriver:72,80`), which throws `EffectNotAttempted` — whose handler then DELETEs the row it just wrote. On a capped day every attempt pays for an insert and a delete to learn something the driver knew up front.

**Ordering that must not be broken.** The precheck runs AFTER the existing-row read and BEFORE the insert. A settled `ok` row must still replay its cached result even when the budget is now exhausted — the money for that answer was already spent, and refusing it would re-bill later for data we hold.

**Files:**
- Create: `app/Ingest/Runtime/Effects/PrecheckableBilledEffect.php`
- Modify: `app/Ingest/Runtime/EffectLedger.php` (`once()` signature + one guard)
- Modify: `app/Ingest/Runtime/Effects/PlacesDetailsDriver.php`
- Modify: `app/Ingest/Runtime/HttpIo.php` (`effect()` at `:110`)
- Test: `tests/Feature/Ingest/BilledEffectLedgerOutcomesTest.php` (append)

**Interfaces:**
- Consumes: nothing from Tasks 1–2.
- Produces:
  - `interface PrecheckableBilledEffect { public function precheck(BilledEffectContext $context): void; }`
  - `EffectLedger::once(..., ?callable $precheck = null)` — new optional trailing named param; every existing call site is unaffected.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Ingest/BilledEffectLedgerOutcomesTest.php`:

```php
it('writes no claim row at all when the precheck refuses', function () {
    // #MONEY-2. The driver already knows the budget is spent; making it say so
    // before the claim saves an INSERT and the DELETE that undoes it.
    $ledger = new EffectLedger;

    expect(fn () => $ledger->once(
        digest: 'precheck-refused-digest',
        kind: 'api',
        effect: fn () => throw new RuntimeException('the effect must never run'),
        costTag: 'places.details',
        precheck: fn () => throw new EffectNotAttempted('places daily cap reached'),
    ))->toThrow(EffectNotAttempted::class);

    expect(DB::table('ingest.effects')->where('digest', 'precheck-refused-digest')->count())->toBe(0);
});

it('still replays a settled ok result when the precheck would refuse', function () {
    // The ordering that matters: the money for THIS answer is already spent.
    // Refusing a cached replay because today's budget is gone would force a
    // re-bill later for data we are holding.
    $ledger = new EffectLedger;

    DB::table('ingest.effects')->insert([
        'digest' => 'already-paid-digest', 'kind' => 'api', 'cost_tag' => 'places.details',
        'cost_units' => 10, 'claimed_at' => now(), 'settled_at' => now(), 'status' => 'ok',
        'meta' => json_encode(['result' => ['place' => 'cached answer']]),
    ]);

    $outcome = $ledger->once(
        digest: 'already-paid-digest',
        kind: 'api',
        effect: fn () => throw new RuntimeException('must not run'),
        costTag: 'places.details',
        precheck: fn () => throw new EffectNotAttempted('places daily cap reached'),
    );

    expect($outcome['status'])->toBe('ok')
        ->and($outcome['result'])->toBe(['place' => 'cached answer'])
        ->and($outcome['cached'])->toBeTrue();
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Ingest/BilledEffectLedgerOutcomesTest.php --filter=precheck`
Expected: FAIL — `once()` has no `precheck` argument (`Unknown named parameter $precheck`).

- [ ] **Step 3: Add the opt-in interface**

Create `app/Ingest/Runtime/Effects/PrecheckableBilledEffect.php`:

```php
<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;

/**
 * A billed-effect driver that can tell, without calling the vendor, that it
 * will not attempt this effect at all.
 *
 * Deliberately a SEPARATE interface rather than a method on
 * BilledEffectDriver: a driver with no cheap up-front check should not be
 * forced to implement an empty one, and adding to the base contract would
 * change every implementer for the benefit of the one that has a budget.
 *
 * The reward for implementing it is narrow and purely about waste (#MONEY-2):
 * EffectLedger claims a row before running the effect, so a driver that only
 * discovers its refusal inside run() costs an INSERT plus the DELETE that
 * EffectNotAttempted triggers. Refusing here costs neither.
 *
 * MUST behave exactly like EffectNotAttempted's contract: throw ONLY when no
 * request has left the process. A precheck that throws after a vendor call
 * would delete a claim for an effect that may have been billed.
 */
interface PrecheckableBilledEffect
{
    /** @throws EffectNotAttempted when the effect will not be attempted. */
    public function precheck(BilledEffectContext $context): void;
}
```

- [ ] **Step 4: Add the `precheck` hook to `once()`**

In `app/Ingest/Runtime/EffectLedger.php`, add the parameter to `once()`'s signature (last, so every existing call site is unaffected):

```php
        int $costUnits = 0,
        ?callable $precheck = null,
    ): array {
```

Then, between the `$existing` guard and the claim insert — after the `if ($existing !== null) { return $this->verdictFor($existing); }` block and before the `try {` that inserts:

```php
        // #MONEY-2. AFTER the existing-row read, BEFORE the claim: a driver
        // that already knows it will not attempt this effect refuses here and
        // costs no row, instead of an INSERT plus the DELETE that
        // EffectNotAttempted performs.
        //
        // The order is load-bearing. A settled 'ok' row must still replay
        // above even when today's budget is gone — that answer is already paid
        // for, and refusing it would re-bill later for data we hold.
        if ($precheck !== null) {
            $precheck();
        }
```

Add to the docblock's `@param` list:

```php
     * @param  (callable(): void)|null  $precheck  runs before the claim; throwing
     *                                             EffectNotAttempted refuses without writing a row
```

- [ ] **Step 5: Run to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Ingest/BilledEffectLedgerOutcomesTest.php`
Expected: PASS (9 tests — the 7 that were there plus the 2 added).

- [ ] **Step 6: Implement `precheck()` on the Places driver**

In `app/Ingest/Runtime/Effects/PlacesDetailsDriver.php`, add `PrecheckableBilledEffect` to the `implements` list and add:

```php
    /**
     * The two refusals PlacesDetailsDriver can make without calling Google:
     * an exhausted budget and a missing key. Both already throw
     * EffectNotAttempted from run() (see the match arms below) — doing it here
     * as well simply moves them ahead of the ledger claim (#MONEY-2).
     *
     * Deliberately NOT a full duplicate of run()'s logic: this is a cheap
     * pre-filter, and anything it misses is still caught by the same
     * EffectNotAttempted arms it mirrors. It must never be the only check.
     */
    public function precheck(BilledEffectContext $context): void
    {
        if (! $this->places->isConfigured()) {
            throw new EffectNotAttempted('Places details is not configured (no server API key).');
        }

        if ($context->userId !== null && $this->budget->remaining('details') <= 0) {
            throw new EffectNotAttempted('Places details budget exhausted before claim.');
        }
    }
```

**Before writing this, read `PlacesDetailsDriver`'s constructor and confirm the property names and the budget/service API.** `remaining('details')` is the method used in slice 0's own pre-flight; `isConfigured()` is the shape `InstagramScraper` gained in slice 0 — verify the Places equivalent exists and add it if it does not, rather than inventing a call.

- [ ] **Step 7: Pass the precheck from `HttpIo`**

In `app/Ingest/Runtime/HttpIo.php`, in `effect()` (`:110`), add the argument to the `once()` call:

```php
            costUnits: $this->manifest->cost->budgetWeight(),
            precheck: function () use ($kind, $name, $input): void {
                $driver = $this->drivers->for($kind, $name);
                if ($driver instanceof PrecheckableBilledEffect) {
                    $driver->precheck($this->contextFor($kind, $name, $input));
                }
            },
```

Use whatever `HttpIo` already calls to build a `BilledEffectContext` inside `runBilledEffect()` — extract it to a private `contextFor()` if it is currently inline, so the precheck and the run cannot disagree about the context they judge.

Add the import: `use App\Ingest\Runtime\Effects\PrecheckableBilledEffect;`

- [ ] **Step 8: Run the dispatch and driver suites**

Run: `./vendor/bin/pest tests/Feature/Ingest/BilledEffectDispatchTest.php tests/Feature/Ingest/PlacesDetailsDriverTest.php tests/Feature/Ingest/InstagramActorDriverTest.php tests/Unit/Ingest/BilledEffectDriverRegistryTest.php`
Expected: PASS. `InstagramActorDriver` does not implement the interface, so its dispatch must be unchanged — that is the `instanceof` guard doing its job.

- [ ] **Step 9: Commit**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
./vendor/bin/pint app/Ingest/Runtime tests/Feature/Ingest
git add app/Ingest/Runtime tests/Feature/Ingest/BilledEffectLedgerOutcomesTest.php
git commit -m "perf(ingest): refuse a billed effect before claiming its ledger row

#MONEY-2. once() claims before it acts, and the Places budget check lives
inside the driver, so a capped day paid for an INSERT plus the DELETE
that EffectNotAttempted performs — per attempt, to learn something the
driver knew up front.

PrecheckableBilledEffect is opt-in rather than a method on
BilledEffectDriver: only a driver with a cheap up-front check gains
anything, and the base contract should not change for the one
implementer that has a budget.

The hook runs AFTER the existing-row read and BEFORE the claim. That
order is load-bearing — a settled 'ok' row must still replay when the
budget is gone, or we re-bill later for an answer we already hold."
```

---

### Task 4: Verify on dev and record the outcome

**Files:**
- Modify: `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` (§13.5)

- [ ] **Step 1: Full suite**

Run: `COMPOSER_PROCESS_TIMEOUT=0 composer test`
Expected: PASS. If a handful of Redis/session/`RunExecutorProjectionTest` cases fail, re-run once — this repo has documented parallel-flake families that pass serially (`docs/2026-08-05-platforms-as-sources.md:163`). A failure is a regression only if it repeats deterministically.

- [ ] **Step 2: PHPStan on the touched namespace only**

Run: `./vendor/bin/phpstan analyse app/Ingest/Runtime --memory-limit=2G`
Expected: 0 errors. Do NOT run it repo-wide and do not fix `development`'s 19 pre-existing email-overhaul errors here.

- [ ] **Step 3: Merge, deploy, and re-run one billed effect**

Merge to `development` and push (`--no-verify`, per the Global Constraints). After the deploy succeeds, repeat slice 0's live run — the digest is freshness-bucketed, so pick a source whose bucket has rolled, or a different `place_id`, or the ledger will simply replay the row from 2026-08-12 and prove nothing:

```bash
~/.composer/vendor/bin/cloud command:run development --cmd='php artisan tinker --execute="(new App\Jobs\Ingest\RunSourceJob(\"<source-uuid>\"))->handle(app(App\Ingest\Runtime\SourceScheduler::class), app(App\Ingest\Runtime\RunExecutor::class)); echo \"RUN-COMPLETE\";"'
```

> ⚠️ **This spends real money** — one Places Details call. Pre-flight first: `app(App\Services\Cache\PlacesBudget::class)->remaining('details')` must be > 0.

Then assert the happy path is untouched:

```sql
SELECT digest, kind, cost_tag, cost_units, status, claimed_at, settled_at
FROM ingest.effects ORDER BY claimed_at DESC LIMIT 5;
```

Expected: the new row settles `ok`, exactly as the 2026-08-12 row did. **This proves the refactor did not break the success path** — it cannot prove the failure path, which is what Tasks 1–2 are for.

- [ ] **Step 4: Log scan**

Run: `~/.composer/vendor/bin/cloud environment:logs development --minutes 10`
Expected: no `ingest.effect.settle_unrecorded` (it would mean the settle write is failing in production, which is the alarm working and a separate incident), and no new `ingest.effect.abandoned`.

- [ ] **Step 5: Record it**

Append to §13.5 of the convergence spec, under the existing "Still outstanding" list, a short note that #MONEY-1 and #MONEY-2 were found by audit after slice 0 made `runBilledEffect()` reachable, and were fixed on `audit-fix/effect-ledger-money-2026-08-12` — with the pasted `ingest.effects` row from Step 3.

- [ ] **Step 6: Commit**

```bash
git add docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md
git commit -m "docs(spec): record the #MONEY-1/#MONEY-2 fixes against slice 0"
```

---

## What this plan deliberately does not do

- **No retry of the settle write.** A bounded retry sounds free but runs inside a user-facing job on a connection that has just failed; it would add latency to the failure path for a case whose correct answer (leave it claimed, alarm, hand the caller its data) does not need it. If settle failures ever show up in the logs at volume, revisit with evidence.
- **No new `ingest.anomalies` kind.** Argued in `settleOk()`'s docblock: the alarm must not be another database write, and a new kind needs a two-file NOT VALID + VALIDATE migration against a closed CHECK domain.
- **No change to `EffectNoAnswer` or `EffectNotAttempted`.** Both are correct; Task 1 Step 6 exists to prove they still are.
- **No `body_ref` / off-row storage for oversized results.** Still summarised-only and still refused on replay, exactly as `RESULT_INLINE_MAX_BYTES`'s docblock says. Unrelated to these findings.
- **No fix for `development`'s 19 PHPStan errors.** Not this branch's scope; named in the Global Constraints so the implementer is not surprised by `--no-verify`.
- **No `precheck()` on `InstagramActorDriver`.** `ApifyBudget` may well support one, but Instagram's refusal path has not been measured and #MONEY-2 was raised against Places. The `instanceof` guard means adding it later is a one-file change.

## Self-review

- **Finding coverage.** #MONEY-1 → Tasks 1 and 2 (unit at the ledger, integration at `HttpIo`). #MONEY-2 → Task 3. Both → Task 4's live verification.
- **Type consistency.** `settleOk(string, mixed, string, ?string): void` is called with exactly `($digest, $result, $kind, $costTag)`, all of which are in `once()`'s scope. `precheck` is `?callable` in `once()` and is passed a closure from `HttpIo`; the driver-side contract is `precheck(BilledEffectContext): void`, matching `BilledEffectDriver::run()`'s existing context type.
- **The regression gate is explicit.** Task 1's fourth test and Step 6's four suites exist because the narrowing moves three `catch` blocks; the risk in this plan is not the new path but a silent change to the old ones.
- **Two steps require reading before writing** — Task 2 Step 1 (copy `HttpIo` construction from `BilledEffectDispatchTest`) and Task 3 Step 6 (confirm the Places budget/config API). Both are called out inline rather than guessed at, because inventing either would produce a plausible-looking test that proves nothing.
