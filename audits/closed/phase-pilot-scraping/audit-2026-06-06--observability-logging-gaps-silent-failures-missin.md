All four findings are confirmed against source. Now writing the final audit.

`★ Insight ─────────────────────────────────────`
A key architectural pattern here: Laravel Nightwatch only surfaces **exception events** (via `report()` or unhandled throws), not `Log::warning()` lines. Catch blocks that swallow and log-only are locally correct (they prevent the parent operation from failing) but create blind spots — Nightwatch's alert model distinguishes "operator noticed this in logs" from "system alerted you." The fix pattern `report($e)` sends the exception to Nightwatch's exception tracker without re-throwing, combining both.
`─────────────────────────────────────────────────`

# Observability Audit — 2026-06-06

**Branch:** development
**Lens:** Logging gaps, silent failures, missing Nightwatch instrumentation
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Services/Platforms/PlatformRefresher.php
- app/Console/Commands/RefreshIntegrationConnectionsCommand.php
- app/Http/Controllers/Api/Platforms/InstagramController.php
- app/Jobs/Cache/AggregateCacheMetricsJob.php
- app/Jobs/Cache/WarmPublicSiteCacheJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#OBS-1** · P2 — IntegrationConnectionObserver swallows Throwable without Nightwatch visibility
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:51–55
    - **Affects:** Every platform-connection write (dashboard edits, daily refresh cron). If the user→site lookup or `CloudflareCachePurgeJob::dispatch()` fails persistently, the sitepage edge cache is never purged and the public page serves stale content indefinitely — with zero Nightwatch alert and only a log warning for evidence.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` as the first statement inside the `catch (\Throwable $e)` block.
        - Keep the existing `Log::warning` — it provides the structured context Nightwatch needs to correlate exception events.
        - No change to the catch-and-continue pattern itself; observers must not crash the parent write.
    - **Technical:** `report()` forwards the exception to the Laravel exception handler (and therefore to Nightwatch's exception tracker) without re-throwing. The current code only calls `Log::warning`, which produces a log line but never generates a Nightwatch exception event, never increments the exception counter, and never triggers an alert. A broken `core.users → site.sites` join (e.g., orphaned connection row after a failed delete) or a Redis outage during `dispatch()` would produce a steady stream of suppressed warnings while the sitepage silently goes stale. Category 4 (log call that obscures) + Category 3 (missing Nightwatch instrumentation): P2.
    - **Plain English:** Imagine a smoke alarm wired to write a sticky note on your kitchen counter instead of sounding a siren. The note is there if you happen to check, but nobody's paged. If platform updates keep quietly failing to clear your public page's cache, visitors see out-of-date content and you'll never know unless you manually grep log files. Adding one line makes it ring the alarm.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::warning('IntegrationConnectionObserver purge failed', [
                'platform_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'message' => $e->getMessage(),
            ]);
        }
        ```

- [ ] **#OBS-2** · P2 — RefreshIntegrationConnectionsCommand per-connection catch swallows Throwable without Nightwatch visibility
    - **Where:** app/Console/Commands/RefreshIntegrationConnectionsCommand.php:39–45
    - **Affects:** The daily `integrations:refresh` cron. A systemic failure (broken scraper, network partition, schema mismatch in `PlatformRefresher`) increments the `$failed` counter and writes a warning log, but produces no Nightwatch exception event. Operators reading the dashboard see a green "integrations:refresh ran" command with no indication that 300 of 300 connections silently errored.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` inside the catch block, before the `$failed++` increment.
        - Keep the `Log::warning` with its existing structured context — it's useful for per-connection correlation.
        - The continue-on-error strategy is correct; only `report()` is missing.
    - **Technical:** Because the catch wraps individual connection refreshes inside a `foreach`, a systemic exception (every connection throwing the same error) produces N suppressed warnings and a final `artisan` summary line — zero Nightwatch exception events. This is structurally identical to OBS-1: the log is there, the alert isn't. The same `report($e)` fix applies. In the unlikely case where hundreds of connections all throw (causing noisy exception volume), that noise is the correct signal — it means the whole refresh pipeline is broken. Category 4 (log-only catch) + Category 3 (missing Nightwatch coverage on a scheduled command): P2.
    - **Plain English:** The daily job that keeps YouTube, Eventbrite, and Apple content fresh on user pages quietly counts failures in a tally but never rings an alarm. If the whole refresh breaks — say the YouTube scraper stops working — the dashboard shows the cron "completed successfully," the error count is buried in log files, and user sitepages start going stale. One extra line makes the alarm go off instead.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            $failed++;
            Log::warning('integrations:refresh failed for a connection', [
                'platform_connection_id' => $connection->id,
                'platform' => $connection->platform,
                'message' => $e->getMessage(),
            ]);
        }
        ```

- [ ] **#OBS-3** · P2 — PlatformRefresher leaves `last_refresh_error` untouched on the null/unavailable path
    - **Where:** app/Services/Platforms/PlatformRefresher.php:42–46
    - **Affects:** Any user whose YouTube, Eventbrite, or Apple connection stops auto-refreshing. The `last_refresh_error` column exists precisely for this diagnostic purpose (migration `20260602150238`, `last_refresh_error text` column) but is never written on the failure path, so an operator querying `site.platform_connections` to investigate stale sitepages sees `last_refresh_status = 'unavailable'` and no reason why.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'last_refresh_error' => '<reason>'` to the `forceFill` call on the null path. A static string like `'Scraper returned null or empty'` is better than nothing; the `default` arm of the `match` (unsupported platform) should use `'Platform not refreshable'`.
        - Optionally (M effort): have each `*Payload` private method return `[?array $payload, ?string $error]` so the reason string distinguishes HTTP failure, parse failure, and missing input — keeping the diagnostic value high without a large refactor.
        - The success path already correctly sets `'last_refresh_error' => null`; this fix only affects the failure branch.
    - **Technical:** The `match` expression calls `youtubePayload`, `eventbritePayload`, `appleMusicPayload`, `applePodcastPayload` — each of which returns `null` for any scraper failure (HTTP error, empty response, missing `handle`/`url`/`input` in payload). All those failure modes map to the same `forceFill` call, which updates `last_refresh_status` and `consecutive_failures` but leaves `last_refresh_error` at whatever it was from the previous run. An operator diagnosing "why is this user's YouTube tile stale?" must grep rotated command logs rather than querying the row. The column schema and model fillable list already support this — it's a one-line fix. Category 4 (log call that obscures / missing context on critical state transition): P2.
    - **Plain English:** The database has a dedicated "why did the last update fail?" field for each platform connection, but the refresh job never fills it in. When investigating why a user's YouTube section stopped updating, an operator has to dig through old log files with timestamps to find the reason — instead of just looking at the user's row and reading "Channel not found" or "iTunes API timeout." The field exists; it just needs to be written to.
    - **Evidence:**
        ```php
        if ($next === null) {
            $connection->forceFill([
                'last_refresh_status' => 'unavailable',
                'consecutive_failures' => (int) $connection->consecutive_failures + 1,
            ])->saveQuietly();
        ```

---

## P3 — Nice to have

- [ ] **#OBS-4** · P3 — Instagram daily Apify cap uses non-atomic read-modify-write
    - **Where:** app/Http/Controllers/Api/Platforms/InstagramController.php:269–276
    - **Affects:** Apify cost control. Under concurrent Instagram connects (two users hitting `/connect` simultaneously within the same Redis round-trip window), both requests can read the same `$count`, both pass the cap check, and both increment — producing an actual daily run count one or two over the configured `APIFY_DAILY_CAP`. At pilot scale this is advisory overspend only, not a correctness bug.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `Cache::get` + `Cache::put` pair with an atomic `Cache::add` + `Cache::increment` pattern: use `Cache::add($dayKey, 0, now()->addDay())` to initialise the key (no-op if it exists), then `Cache::increment($dayKey)` and compare the returned value against `APIFY_DAILY_CAP`. Redis `INCR` is atomic; the returned integer is the post-increment value, eliminating the TOCTOU window.
        - The existing per-user cooldown (`Cache::add($cooldownKey, 1, ...)`) is already correctly atomic — only the global daily counter needs this fix.
    - **Technical:** The current pattern (`$count = Cache::get(...); if ($count >= $cap) { ... } Cache::put(..., $count + 1, ...)`) has a classic time-of-check/time-of-use window. Two requests that both read `$count = 199` before either writes will both pass the cap check and increment to 200 independently, producing 201 actual Apify runs that day. The code comment acknowledges this: "good enough for a pilot — backend dev to harden." The fix is a single atomic Redis `INCR` (exposed via `Cache::increment`), which returns the post-increment value so the cap check reads the updated count. No lock required. Category 5 (marginal queue/cost-control hygiene): P3.
    - **Plain English:** The daily budget counter works like a hotel using an honour system at check-in: two guests who both look at the ledger at the exact same moment, both see "199/200 rooms taken," and both book a room — ending up with 201 guests in a 200-room hotel. It's a minor overage that costs a few dollars, not a critical failure. The fix is a counter that increments atomically — more like a real-time booking system that can't double-sell the last room.
    - **Evidence:**
        ```php
        $dayKey = 'platforms:instagram:apify-daily:'.now()->format('Y-m-d');
        $count = (int) Cache::get($dayKey, 0);
        if ($count >= self::APIFY_DAILY_CAP) {
            return $this->error('Instagram is busy right now — please try again later.', 429);
        }
        Cache::put($dayKey, $count + 1, now()->addDay());
        ```

`★ Insight ─────────────────────────────────────`
Two findings (OBS-1 and OBS-2) share identical structure: `catch (\Throwable $e) { Log::warning(...) }` with no `report($e)`. This is the canonical Partna fix pattern for observers and commands that must swallow exceptions: the catch is architecturally correct, but `report()` is the missing half that connects the log to Nightwatch's alert system. These are deliberately the same tier (P2) because the root cause — log-only catch without Nightwatch visibility — is identical regardless of the enclosing class type.
`─────────────────────────────────────────────────`
