# Drill 02 — Vendor outage during platform refresh

**Question:** when a platform's API 500s (or is unreachable), does `RefreshConnectionJob`
fail *quietly and boundedly* — or does it retry-storm Horizon? And do the circuit breaker
and user-facing health notification actually fire?

## How the code SAYS it behaves (the hypothesis to verify)

`app/Services/Platforms/PlatformRefresher.php` — an upstream failure inside a fetch
strategy is *translated, not thrown*:

- `FetchUnavailableException` → `recordFailure(status: 'unavailable')` → **the job
  completes successfully**. No retry. `consecutive_failures` increments atomically;
  `last_refresh_status`/`last_refresh_error` are written.
- `FetchShapeException` → same bookkeeping with `status: 'error'` + a loud `report()`
  (Nightwatch pages — data corruption, not vendor outage).
- Only an **unexpected** exception escapes to the job, where `RefreshConnectionJob` caps it:
  `$maxExceptions` 3, `$backoff` [30, 120, 300], then `failed()`. (`$tries` 0 is
  deliberate — attempts are unbounded only for `RateLimited('platform-refresh')` releases,
  wall-clock-capped by `retryUntil()` at 2h.)
- Circuit breaker: the `integrations:refresh` dispatcher skips connections with
  `consecutive_failures >= config('partna.refresh.max_consecutive_failures')` (10).
- `PlatformHealthNotifier::connectionRefreshFailing()` warns the user once
  (dedupe-keyed) when a failure trips that threshold.

**The drill's core judgment:** which path does a *real* outage take? A vendor hard-down
(connection refused) might surface as a raw `ConnectionException` rather than
`FetchUnavailableException` — bounded (≤3 attempts) is acceptable; unbounded or
rate-limit-amplified retries are the FAIL condition.

## Preconditions

- [ ] Local stack: Herd + Redis + Horizon (`php artisan horizon` — the local `supervisor-1`
      covers the `platform_refresh` queue).
- [ ] At least one active, refreshable connection in the local DB. In tinker:

```php
\App\Models\Core\Site\IntegrationConnection::where('is_active', true)
    ->get(['id', 'platform', 'last_refresh_status', 'consecutive_failures', 'last_refreshed_at']);
```

If none exist, connect one through the normal dashboard flow first (or seed one — but a
real connection makes the drill honest). Prefer a connection on a **drill user**, not a
real one — the health notifier emails the connection's owner.

- [ ] Note the baseline row values — you'll diff against them.

## INJECT — two variants, run both if time allows

**Variant 1 — hard outage (unreachable vendor).**

✅ **Preferred: poison the connection's URL to a non-resolving `.invalid` host.** Strategies
that read their URL from the payload (`OEmbedFetch` → `payload['link'] ?? payload['url']`,
and others per `DeferredConnect`) can be made to fail at DNS with no `sudo` and no
machine-global state:

```php
// tinker — save the original first, you restore it in RECOVERY
$orig = $conn->payload;
file_put_contents('/tmp/drill02_orig_payload.json', json_encode($orig));
$conn->payload = ['link' => 'https://vendor-outage-drill.invalid/x'];  // RFC 2606, never resolves
$conn->save();
```

Scoped to one connection, nothing to forget to clean up, and no other caller on the machine is
affected. This is what the 2026-07-31 run used.

**Fallback (only when the host is hardcoded in the fetch strategy, not read from the payload):**
point the vendor's API host at localhost. Find the host the strategy actually calls (check the
platform's fetch strategy / `PlatformRegistry` descriptor), then:

```bash
sudo sh -c 'echo "127.0.0.1 <vendor-api-host>" >> /etc/hosts'
sudo dscacheutil -flushcache && sudo killall -HUP mDNSResponder
```

⚠️ Machine-global, needs `sudo`, and must be reverted in RESTORE or every later run is poisoned.

**Variant 2 — auth failure (vendor up, token dead).** Poison the stored credential via
tinker (shape depends on the platform's payload — inspect `$conn->payload` first and note
the original value for restore).

⚠️ **Pick a credentialed platform for this one.** An unauthenticated fetch strategy (e.g.
`OEmbedFetch` for spotify/soundcloud) has no credential to poison, so it can only ever
demonstrate the *translated* `FetchUnavailableException` path. Exercising the **raw-exception**
path (`$maxExceptions` 3, backoff [30,120,300]) needs a platform that can throw something the
refresher does not translate — use e.g. `google-business`. The 2026-07-31 run skipped Variant 2
for exactly this reason, so the raw path is still unexercised.

## ACT + OBSERVE

Dispatch one targeted refresh (tinker):

```php
$conn = \App\Models\Core\Site\IntegrationConnection::find('<id>');
\App\Jobs\Platforms\RefreshConnectionJob::dispatch($conn->id, $conn->platform);
```

Watch, in order:

1. **Horizon dashboard** (`/horizon` on the local site) → Recent Jobs: how many attempts
   did this job make? Expected: **1** (translated failure) or ≤3 with [30,120,300] backoff
   (raw exception). FAIL: attempts climbing past 3.
2. **Connection row** (tinker, fresh query): `last_refresh_status` now `unavailable` (or
   `error`), `last_refresh_error` populated, `consecutive_failures` +1.
3. **`php artisan queue:failed`** → an entry ONLY if the raw-exception path exhausted
   `maxExceptions`. Translated failures never appear here.
4. **Log** (`tail -f storage/logs/laravel.log`): `integrations.refresh.bad_shape` should
   appear ONLY for shape errors — a vendor outage must stay quieter than that.
5. **No rate-limit amplification:** dispatch refreshes for 2–3 *other* platforms'
   connections mid-outage — they must proceed normally (the `platform-refresh` RateLimiter
   is keyed per-platform, so one dead vendor must not starve the others).

## Circuit breaker + notifier

Fast-forward instead of looping 10 real failures:

```php
$conn->update(['consecutive_failures' => 9]);   // one failure away from the breaker
\App\Jobs\Platforms\RefreshConnectionJob::dispatch($conn->id, $conn->platform);
```

Then verify:

- `consecutive_failures` = 10 and the **health notification fired once** — check
  the notifications tables / mail log for the connection-failing notice to the drill user.
- Dispatch one more failing refresh → notifier must NOT fire again (dedupe key).
- Run the dispatcher: `php artisan integrations:refresh` → this connection must be
  **skipped** (breaker open). Confirm no new `RefreshConnectionJob` for it in Horizon.

  🔴 **`selected 0` is NOT breaker evidence.** The dispatcher selects on TTL-dueness first, so a
  connection you refreshed minutes ago is skipped for being *not due*, with or without a
  breaker. Force every candidate due first, then the skip is attributable to
  `consecutive_failures` alone:

  ```php
  \App\Models\Core\Site\IntegrationConnection::query()
      ->update(['last_refreshed_at' => now()->subDays(30)]);
  ```

  Expect the dispatcher to select the healthy connection(s) **and not the tripped one** — a
  non-zero count that excludes your victim, not a zero count.

## RECOVERY

Remove the `/etc/hosts` line (+ flush DNS) / restore the real token. Then:

```php
\App\Jobs\Platforms\RefreshConnectionJob::dispatch($conn->id, $conn->platform);
```

- If the breaker skips it via the dispatcher, note whether a *manual/targeted* refresh
  still executes (it should — the breaker gates the cron fan-out, not the job).
- After one healthy refresh: `last_refresh_status` = `ok`, `consecutive_failures` = 0,
  `last_refresh_error` null. If the counter does NOT reset on success, that's a finding —
  the breaker would hold connections hostage after a vendor recovers.

## Pass criteria

- [ ] Vendor failure → bounded attempts (1 translated, ≤3 raw), no unbounded retries
- [ ] Failure bookkeeping written (`status`, `error`, counter) — user-visible state is honest
- [ ] Other platforms unaffected mid-outage
- [ ] Breaker opens at 10; dispatcher skips; notifier fires exactly once
- [ ] Recovery: one healthy refresh fully resets the connection

## RESTORE

`/etc/hosts` clean, token restored, drill connection's counters back to baseline (or the
drill user deleted entirely), Horizon back to normal.

## Record

`logs/<YYYY-MM-DD>-vendor-outage.md` — must capture: which variant(s), which exception
path the outage actually took (translated vs raw — name the exception class from Horizon's
failed-job payload if raw), attempt counts, breaker/notifier evidence, recovery evidence.
