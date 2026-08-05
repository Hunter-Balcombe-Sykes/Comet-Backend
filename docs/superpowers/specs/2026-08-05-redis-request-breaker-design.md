# Design — per-request Redis circuit breaker

**Status: SHIPPED 2026-08-05** (`audit-fix/redis-request-breaker-2026-08-05` → `development`).
Acceptance evidence: `docs/runbooks/drills/logs/2026-08-05-redis-breaker.md` (drill 03 Scenario C,
18.12 s → 3.11 s under a controlled A/B on one stack).
Motivating measurement: `docs/runbooks/drills/logs/2026-08-05-redis-down.md`.

Kept as a spec rather than deleted as a plan (repo convention: plans go on ship, specs are
permanent) because the *why* here outlives the change — in particular §2.4's scope decision, §2.7's
lock reasoning, §2.9's answer to the dispatch-connection question, and the §2.6 correction.

## 1. The mechanism, restated from the code

`read_timeout` bounds one operation. A public-profile request issues 41 Redis commands across
`cache` (DB 1), `cache_locks` (DB 4) and `app` (DB 0). Every layer fails open, so the request pays
~3 s per independent touch that times out.

Two things in the vendor code that the drill log did not name, and that shape the design:

1. **`Illuminate\Redis\Connections\Connection::command()` is the universal funnel.**
   `PhpRedisConnection::__call()` → `command()`; `RedisStore`, `RedisLock`, `RedisQueue`,
   `RateLimiter` and every `Redis::connection(...)->x()` in `app/` all land there. One override
   covers the whole request path.

2. **Laravel eagerly reconnects after a transport failure**
   (`PhpRedisConnection::command()`, vendor `…/Redis/Connections/PhpRedisConnection.php`):

   ```php
   } catch (RedisException $e) {
       if (Str::contains($e->getMessage(), ['went away', 'socket', 'Error while reading',
                                            'read error on connection', 'READONLY', 'Connection lost'])) {
           $this->client = $this->connector ? call_user_func($this->connector) : $this->client;
       }
       throw $e;
   }
   ```

   The reconnect runs `connect()` **and `select($db)`** against the still-hung server, so each
   subsequent op re-pays the bound from scratch. This is why the cost is strictly additive rather
   than "first op slow, rest fast", and it is why the breaker must short-circuit **before**
   `parent::command()` — throwing from inside would still trigger a reconnect.

   It also gives us Laravel's own transport-failure vocabulary to reuse.

## 2. Design

### 2.1 Classes (naming: nothing called `CircuitBreaker`)

`App\Services\BotProtection\CircuitBreaker` exists and is unrelated. Nothing here reuses that word.

| Class | Role |
|---|---|
| `App\Services\Redis\RedisRequestBreaker` | Container **singleton**. Holds `armed`, `open`, `reason`, `skipped` counters. Inert until armed. |
| `App\Services\Redis\Exceptions\RedisUnavailableException` | `extends \RedisException` (→ `RuntimeException` → `Exception`). Thrown in place of a real op when the breaker is open. |
| `App\Services\Redis\GuardedPhpRedisConnection` | `extends PhpRedisConnection`. Overrides `command()` only. |
| `App\Services\Redis\GuardedPhpRedisConnector` | `extends PhpRedisConnector`. Returns the guarded connection; guards **connect** too. |
| `App\Http\Middleware\Context\ArmRedisRequestBreaker` | Global middleware, `prepend`ed. Calls `arm()`. |
| `App\Providers\RedisBreakerServiceProvider` | Binds the singleton and installs the connector. |

### 2.2 The seam

```php
// GuardedPhpRedisConnection
public function command($method, array $parameters = [])
{
    if ($this->breaker->isOpen()) {
        $this->breaker->recordSkip();
        throw RedisUnavailableException::forSkippedCommand($this->getName(), $method, $this->breaker->reason());
    }

    try {
        return parent::command($method, $parameters);
    } catch (Throwable $e) {
        if (RedisRequestBreaker::isTransportFailure($e)) {
            $this->breaker->trip($e, (string) $this->getName(), $method);
        }
        throw $e;   // untouched — the caller sees exactly what it sees today
    }
}
```

Installation (`RedisBreakerServiceProvider::register()`):

```php
$this->app->singleton(RedisRequestBreaker::class);
$this->app->resolving('redis', fn (RedisManager $m) => $m->extend('phpredis', fn () => new GuardedPhpRedisConnector(...)));
```

`RedisManager::extend()` only affects connections resolved *after* the call; providers register
before any connection resolves, and the provider defensively extends an already-resolved manager
too.

### 2.3 Trip predicate — constraint 4

Trips **only** when the exception is a `RedisException` **and** its message matches a transport
fragment (case-insensitive):

```
went away · socket · error while reading · read error on connection · connection lost
connection refused · connection timed out · can't connect · connection closed · no connection
```

Laravel's own list minus `READONLY`. `READONLY` is a replica-topology error, not an unresponsive
server, and is excluded deliberately. `WRONGTYPE`, `NOSCRIPT`, `NOAUTH`, `OOM`, `MISCONF`, and any
non-`RedisException` `Throwable` never trip it. Unknown message → does **not** trip (fail-safe
direction: worst case is today's behaviour).

### 2.4 Scope and reset — constraints 2 and 3

**The breaker is inert unless explicitly armed, and only `ArmRedisRequestBreaker` arms it.**

- `arm()` sets `armed = true` **and clears `open`/`reason`/`skipped`.** Reset is explicit at the
  *start* of every request, never at the end, so it cannot leak between requests even in a
  long-lived process, and terminate-phase work (`RecordCacheMetrics`'s `terminating()` flush,
  `defer()`ed SWR recomputes) still sees the tripped state and skips rather than re-paying.
- **Console and queue contexts never arm it, so they never get it.** Chosen deliberately, for the
  same reason `DefersRecompute` gates on `runningInConsole()`: a worker has no user waiting, and
  skipping a Redis op inside a job converts a *slow* job into a *silently wrong* one — a
  cache-warm job would report success without warming. A worker is also long-lived, so a breaker
  that tripped once would need a per-job reset that has no natural, testable boundary. The queue
  transport is itself Redis: a breaker on the worker path would skip the very `BLPOP` the worker
  exists to run.
- Consequence for tests: a breaker that defaulted to `runningInConsole()` would be **disabled in
  the whole test suite**, which is exactly the false-PASS shape this repo has been bitten by. The
  arm-explicitly model means feature tests going through the HTTP kernel arm it for real, and unit
  tests arm it in one line.

One breaker for all connections is correct here: `config/database.php`'s seven connections and all
four `config/queue.php` redis connections point at **one** Valkey instance. A timeout on `cache`
is evidence about the *server*, not about DB 1. Noted in the class docblock as the assumption that
would need revisiting if Redis is ever split across instances.

### 2.5 Connect-time guard

Under a hung server the *connect* also blocks (`PhpRedisConnector::createClient()` issues a
blocking `SELECT <db>`), so five distinct connections can cost 5 × `timeout` before any command
runs. `GuardedPhpRedisConnector::connect()` therefore also short-circuits when open and trips on a
connect-time transport failure.

Redis-**down** must not regress: a refused socket fails in 34–52 ms, so connection 1 fails fast,
trips, and connections 2–5 are skipped — strictly faster than today, never slower. The drill
re-run proves this rather than the argument.

### 2.6 Fail-open semantics — constraint 1

The breaker throws a `RedisException` subclass **from the same call site** the real timeout throws
from. Every existing catch therefore makes the identical decision:

| Caller | Today (timeout, ~3 s) | Breaker open (~0 ms) |
|---|---|---|
| `CacheLockService::rememberLocked` read | `catch (Throwable)` → `computeWithoutCache` | same |
| `CacheLockService` lock acquire | `catch (Throwable)` → serve stale / compute uncached | same |
| `CacheLockService::releaseQuietly` | `catch (Throwable)` → counter | same |
| `ResilientRateLimiter::tooManyAttempts/hit` | `onStoreFault` → open or 503 per limiter | same |
| `QueuedIngestor::ingest` | `catch (Throwable)` → breadcrumb + 201 | same |
| `VerifySupabaseJwt` revocation check | `catch (Throwable)` → `$revoked = false` | same |
| `RecordCacheMetrics` flush | already swallowed | same |

**Correction after independent review (2026-08-05):** that table is true for a Redis that is
unreachable or hung — the sustained case this work exists for. It is NOT true for a *single
transient* failure. Today, one `read error on connection` costs op 1 its fail-open branch and
Laravel's eager reconnect lets ops 2..N succeed; with the breaker, ops 2..N are skipped for the
rest of the request. On an authenticated route that can turn a 200 into a 503, since
`VerifySupabaseJwt` is pinned ahead of `ThrottleRequests` and `FailOpenThrottleRequests` fails
CLOSED outside its five-entry allow-list. Accepted deliberately — the alternative is an 18 s
public sitepage read, and a single op breaching a bound ~10× the worst legitimate op ever measured
(314 ms) is a narrow band — but "nothing changes except the wait" was an overstatement and is
recorded as such in `GuardedPhpRedisConnection`'s docblock.

### 2.7 Lock correctness — constraint 5

`Illuminate\Cache\RedisLock::acquire()` performs a `SET NX`. With the breaker open that command
throws, so:

- `$lock->get()` **throws** — it never returns `true`. No caller can believe it holds a lock.
- `$lock->block($seconds, $callback)` propagates the throw and the callback **never runs**. The
  guarded write is skipped, not performed unlocked. That is the safe direction.
- We throw `RedisUnavailableException`, **not** `LockTimeoutException`. This matters: several
  callers (`CacheLockService`, `ManagesIntegrationConnection`) have a distinct
  `catch (LockTimeoutException)` arm meaning "someone else holds it, proceed differently". Using
  the wrong type would silently reroute a store outage into contention handling. The review will
  verify no caller conflates the two.

Request-path write-lock callers (`ManagesIntegrationConnection`, `InstagramController`,
`ShopBrandSeeder`, `EventsCatalog`, …) see a propagating `RedisException` → 500/503, exactly as
they do today against a dead Redis.

### 2.8 The `authed` / revocation trade — stated up front

If the breaker opens before `TokenRevocationService::isRevoked()` runs, the revocation check is
skipped. `VerifySupabaseJwt.php:137-146` (and `:242-251`) already catches any `Throwable` and sets
`$revoked = false`, so this is the **same decision** already taken today — but the band in which
it is taken widens from "this specific check timed out (>3 s)" to "any Redis op earlier in this
request timed out". The outcome is identical (fail-open), reached sooner. Accepting it is a
deliberate continuation of the trade recorded in drill 03 Finding 1. If fail-closed-on-revocation
ever becomes a requirement, the fix is an allow-list of commands that bypass the breaker and pay
their bound — not a change to this design.

### 2.9 The `pageview` / `default` question — answered

`POST /api/public/analytics/pageviews` measured 15.06 s ≈ **one 15 s op**: `QueuedIngestor`
dispatches `RecordAnalyticsEventJob` through `config('queue.connections.redis.connection')` =
`default`, whose `read_timeout` is 15.0 because queue workers `BLPOP` on it. `default` must not be
lowered (`RedisTimeoutBoundsTest` pins this, and lowering it is a live queue outage).

Two mechanisms, and we want **both**, because they cover different orderings:

- **The breaker covers it when the dispatch is not the first failing touch.** `throttle:analytics`
  runs before the controller on the `cache` connection, so in the normal ordering the breaker is
  already open by the time dispatch happens and the dispatch is skipped in ~0 ms.
- **A bounded dispatch connection covers the case where it *is* the first touch.** Add
  `config/queue.php` → `redis_request`: a byte-for-byte copy of the `redis` block with
  `'connection' => 'app'` and `'block_for' => null`, and have `QueuedIngestor` dispatch
  `->onConnection('redis_request')`.

  This is safe because it is the **same trick the `app` connection already uses**: `app` and
  `default` are two views of the same DB 0 with the same `laravel_database_` prefix, so
  `redis_request` pushes to *byte-identical* queue keys. Horizon and every worker keep consuming
  via `redis` unchanged; nothing consumes `redis_request` (hence `block_for => null`). A guard
  test pins DB + prefix + queue-name parity between the two so a future edit cannot silently split
  the keyspace.

  Without this, a beacon request whose only Redis touch is the dispatch stays bounded at 15 s.

This is the honest full answer to the prompt's question. It ships as its own commit so it is
independently revertable.

### 2.10 What the breaker does *not* cover

`PhpRedisConnection::scan()/hscan()/zscan()/sscan()`, `pipeline()`, `transaction()` and
`subscribe()` call `$this->client` directly, bypassing `command()`. None of them are on the
request path (`SCAN` is console/job-only in this repo). Recorded in the class docblock rather than
worked around, so the next person knows the boundary instead of assuming total coverage.

Cluster (`PhpRedisClusterConnection`) is not guarded — Redis Cluster is rejected at this scale
(CLAUDE.md) and `connectToCluster()` falls through to the parent unchanged.

## 3. Guards

Existing, must stay green: `tests/Feature/Architecture/RedisTimeoutBoundsTest.php`,
`tests/Feature/Architecture/RedisConnectionPinningTest.php`.

New:

1. `tests/Unit/Redis/RedisRequestBreakerTest.php` — inert until armed; `arm()` clears prior state;
   transport messages trip; `WRONGTYPE` / `NOSCRIPT` / `OOM` / `MISCONF` / non-`RedisException`
   do **not** trip.
2. `tests/Unit/Redis/GuardedPhpRedisConnectionTest.php` — the load-bearing one. A stub client that
   counts invocations and throws `RedisException('read error on connection')`. Assert: op 1 →
   throws, client called **once**; ops 2..6 → throw `RedisUnavailableException`, client call count
   still **1**. This is the "6 × 3 s → 1 × 3 s" property in unit form.
3. `tests/Feature/Architecture/RedisBreakerWiringTest.php` — every configured phpredis connection
   resolves to `GuardedPhpRedisConnection`; `ArmRedisRequestBreaker` is in the global middleware
   stack; `RedisUnavailableException` is a `RedisException`. This is the guard most likely to
   matter later: the classes can be perfect and simply not installed.
4. `tests/Feature/Architecture/QueueDispatchConnectionParityTest.php` (with §2.9) — `redis_request`
   and `redis` resolve to the same Redis DB, prefix and queue name.

**Non-vacuity:** each new test is deliberately broken once (invert the assertion / no-op the
breaker) and the failure output is captured in the commit body before restoring. Per the repo
trap, `not->toContain($a, $prose)` is never used — a second argument to `toContain` is another
needle. Messages only on `toBeLessThanOrEqual` / `toBeEmpty` / `toBeTrue`-style expectations that
actually take one.

## 4. Pipeline / bookkeeping

- `app/Services/Redis/` is a new directory under `app/Services/` → must be wired into
  `scripts/audit/audit.sh`'s `codebase_chunks()` and a lens scope-group, or
  `AuditPipelineIntegrityTest` fails CI.
- `config/database.php` comment updated: `read_timeout` bounds an operation; the request bound is
  the breaker.
- `docs/runbooks/drills/03-redis-down.md` expectation block updated to the new numbers.
- New drill log `docs/runbooks/drills/logs/2026-08-05-redis-breaker.md` with before/after.
- No migration, no schema change, no public wire change.

## 5. Verification

1. `./vendor/bin/pest` (direct — `composer test` hits Composer's 300 s timeout).
2. `./vendor/bin/phpstan analyse`. No caller is repointed between `Redis::` and
   `Redis::connection(...)`, so the `phpstan.neon` static-vs-instance `ignoreErrors` trap should
   not fire; if it does, the affected `message:` lines are rewritten by hand — the baseline is
   **not** regenerated.
3. Independent Opus review (fail-open semantics, reset scope, lock correctness), verifying
   **empirically** with `redis-cli monitor` and induced failures, not by reading.
4. Drill 03 Scenario C re-run, full preconditions: `APP_ENV=staging` with the bound-ingestor
   assertion, Horizon started under `local` then flipped, **parallel** probes, witness, injection
   verified before each probe, `redis.conf` backed up and restored byte-identical.
   **Pass condition:** profile ≈ 3 s (from 18.11 s) against a ~40 s hang, with Redis-down
   unregressed (all five probes < 100 ms, enquiry still atomic with zero rows, recovery hands-off).
5. Merge to `development`, push (pre-push runs PHPStan + Worker checks), record the drill result.

## 6. Open risks

- **The drill is the only real proof.** Unit tests prove the skip; only the drill proves the
  request-level number. If profile lands well above ~3 s, the residual is a Redis touch outside
  `command()` (§2.10) or a connect-time cost, and gets diagnosed with `redis-cli monitor` before
  anything is claimed.
- **Vendor coupling.** `GuardedPhpRedisConnector::connect()` reproduces eight lines of
  `PhpRedisConnector::connect()` because the parent builds the connection object inline. A Laravel
  upgrade that changes that constructor changes ours; the wiring test (§3.3) is what catches it.
