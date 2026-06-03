# Async Analytics Ingest — Foundational Design

**Date:** 2026-05-30 (rev. 2 — idempotency, failure modes, and contract-preservation hardening)
**Status:** Approved design (pre-implementation)
**Scope:** Write-path only. Read-path scaling hatches (rollup tables, partitioning, HLL) are explicitly out of scope.

---

## 1. Problem

The public analytics ingest endpoints (`AnalyticsController::pageview/click/sectionSeen`) write events **synchronously inside the visitor's HTTP request**:

1. **Resilience coupling (present-tense).** Every public beacon holds a DB connection and performs a write before responding. If Postgres is slow or locked, every visitor's beacon hangs — public ingest availability is coupled to DB write availability. True at 10 users, not just 10,000.
2. **Read-before-write dedup.** `click`/`sectionSeen` run a Postgres `SELECT … WHERE occurred_at >= now()-Ns` to dedup, then `INSERT`. That is two DB round-trips per event and is racy (two fast events both miss the window). Meanwhile `LogLeadRateLimits` already dedups correctly via Redis `Cache::add` (atomic `SETNX`) — an in-house pattern this code should adopt.

We want a **foundational** fix: decouple ingest from the request path such that future scaling is **purely additive** (add a class behind an interface), never a rewrite of controllers or endpoints.

## 2. Goals / Non-Goals

**Goals**
- No Postgres **writes** and no **read-for-write** on the visitor request hot path. (Site resolution remains a cache-backed read — unchanged from today; it can miss to the DB but is shared, cached infrastructure, not new work this design introduces. See "Residual coupling" below for the honest limit of this.)
- Unify dedup on the Redis `SETNX` pattern.
- Preserve the public API contract **exactly** — same JSON, same `*_id` fields, same status codes. This explicitly includes the three non-obvious branches today's controller returns: the **422 IDOR** signal (`site_id` supplied but fails the subdomain cross-check), the **bot path** (`200` with a message and **no** `*_id` field), and the per-endpoint differences in §4.1.
- Two independent, swappable seams so transport and storage scale separately.
- `local`/`testing` behaviour unchanged (events visible inline in tests).

**Residual coupling (honest framing).** This design removes Postgres *writes* and *read-for-write* from the hot path. It does **not** make ingest fully DB-independent, and it is not pure decoupling — it is partly a *dependency swap*:
- **Site resolution still reads Postgres on a cold cache.** For a published, trafficked site the resolution is cached and the beacon never touches Postgres. But on cache-cold paths (cache flush, Redis eviction, a brand-new site spiking) resolution misses to a DB read, so beacon availability for cache-cold sites remains coupled to Postgres *read* availability. This is pre-existing shared infrastructure, not new work, but the "ingest no longer depends on the DB" claim only holds for warm-cache sites.
- **Ingest now depends on Redis** for both dedup (`Cache::add`) and enqueue (queue dispatch). We have traded a Postgres-write dependency for a Redis dependency. Redis being down is independently catastrophic for the app, and Redis is the higher-availability store for this workload, so this is the right trade — but the failure mode is defined explicitly (§3.2, fail-open) rather than assumed away.

**Non-Goals (separate future efforts, do not build now)**
- Rollup-table population (`site_metrics_daily/hourly`).
- Raw-table partitioning / Timescale.
- HyperLogLog unique-count precomputation.
- ClickHouse/columnar writer (designed-for, not built).
- `rum()` endpoint — already logs-only, unchanged.
- `lead_submissions` ingest — already async via `terminate()` middleware, unchanged.

## 3. Architecture — two seams

The two future scaling moves are independent, so they get separate interfaces. Collapsing them is the mistake that forces a rewrite.

```
Controller
  → AnalyticsIngestor::ingest(AnalyticsEvent)      // SEAM 1: gets event OFF the request path
       → RecordAnalyticsEventJob (queue: analytics)
            → AnalyticsEventWriter::write(AnalyticsEvent)   // SEAM 2: decides WHERE it lands
```

### 3.1 `AnalyticsEvent` (immutable DTO)
The stable contract flowing through both seams. Fields:
- `id` (UUID, minted at controller before dispatch). **This UUID becomes the persisted row's primary key** — it is not a separate correlation id. This is the linchpin of at-least-once idempotency (§3.4): a retried job re-inserts with the same PK and the writer no-ops it. It is also the id echoed in the 201 response, so the id the visitor sees is the id of the row that lands.
- `type` (`pageview` | `click` | `section_view`)
- `occurred_at` (stamped at **request time** in the controller — never worker time)
- `user_id`, `site_id`
- `session_id`, `visitor_id`, `ip_hash`, `user_agent`, `referrer` (referrer is **already sanitized** — `filter_var(FILTER_VALIDATE_URL)` rejection happens in the controller while building the DTO, preserving today's SEC behaviour; the writer never re-sanitizes)
- `utm_source`, `utm_medium`, `utm_campaign`
- `country_code`, `device_type` (both detected at **request time** from CF headers / user-agent — the worker has no request object and cannot recover them later)
- `block_id` (clicks/sections), `section_key` (sections)

Serializable to/from array for queue transport. No Eloquent model inside.

> **Why request-time capture matters:** `occurred_at`, `country_code`, `device_type`, `ip_hash`, `user_agent`, and `referrer` all derive from the live HTTP request (CF geo headers, UA string, client IP). A naive implementation that detects them in the worker would silently lose them — the DTO front-loads every request-derived field by contract.

### 3.2 Seam 1 — `AnalyticsIngestor` (transport)
```php
interface AnalyticsIngestor {
    public function ingest(AnalyticsEvent $event): void;
}
```
- **`QueuedIngestor` (ships now):** dispatches `RecordAnalyticsEventJob($event->toArray())` onto the `analytics` queue/connection.
- **`SyncIngestor` (ships now, for local/testing):** calls the writer inline so tests/dev see rows immediately — mirrors the image-pipeline's sync-in-test pattern.
- **`BufferedIngestor` (future drop-in):** `LPUSH` to a Redis list; a scheduled worker drains and calls `writer->writeMany()`. Controller unchanged.

Binding chosen in a service provider by env/queue connection (same logic shape as `MediaUploadService::dispatchImageJob`: inline when `local`/`testing` or `queue.default === sync`).

**Dispatch-failure handling (decided): lossy fail-open, no synchronous fallback.** `MediaUploadService::dispatchImageJob` falls back to running the job inline when the queue dispatch throws, because losing a user's uploaded image is unacceptable. Analytics is the opposite: a single lost beacon is acceptable (§3.4 already accepts permanent job-failure drops), and the entire point of this design is to keep Postgres writes off the request path — a synchronous fallback would reintroduce exactly the coupling we are removing. So `QueuedIngestor` catches a dispatch exception, logs it (breadcrumb + the same drop metric as §3.4), and returns normally. **It does not write inline.** This is a deliberate divergence from the cited image pattern; the rationale is the differing loss tolerance.

**Redis-fault policy (decided): fail-open.** Both the dedup claim (§5) and the enqueue depend on Redis. If Redis throws on either:
- **Dedup fault → enqueue anyway without dedup.** Accept a possible duplicate event (idempotency via PK still bounds the blast radius to genuine distinct requests, not retries) rather than dropping a real beacon. The dedup guard swallows its own faults and reports `novel = true` so the caller proceeds.
- **Enqueue fault → drop + log** (per the dispatch-failure rule above).
Never 500 the visitor for a Redis fault. The beacon is fire-and-forget; a degraded-but-up ingest beats a hard failure that breaks the visitor's page.

### 3.3 Seam 2 — `AnalyticsEventWriter` (storage)
```php
interface AnalyticsEventWriter {
    public function write(AnalyticsEvent $event): void;
    public function writeMany(array $events): void;   // batch path for future BufferedIngestor
}
```
- **`PostgresEventWriter` (ships now):** maps the DTO to the correct model (`SiteVisit` / `LinkClick` / `SectionView`) and inserts. **Owns authoritative block validation** (see §4).
  - **Idempotent insert.** Uses the DTO's minted UUID as the explicit primary key and inserts via `insertOrIgnore` (Postgres `INSERT … ON CONFLICT (id) DO NOTHING`). A retried job (§3.4) therefore re-inserts the same PK and silently no-ops — at-least-once delivery yields effectively-once persistence. **Do not use `->save()` with a DB-generated id**, which would make every retry a fresh row and double-count.
  - **DTO → model column mapping.** `block_id` maps to `link_block_id` on `LinkClick` and to `block_id` on `SectionView`; `pageview` carries no block. The writer is the single place this mapping lives.
  - **`writeMany` (chunked bulk insert).** Batch path for the future `BufferedIngestor`. Block validation must **batch-load** the referenced blocks in one query and filter in memory — not one `SELECT` per event — otherwise batching the inserts but validating serially defeats the purpose. Bulk insert also uses `insertOrIgnore` for the same idempotency reason. (Shipping now for interface completeness even though its only caller is future; it is small and directly tested in §9.)
- **`ClickHouseEventWriter` / `TimescaleEventWriter` (future drop-in):** same interface, columnar destination. Job unchanged.

### 3.4 `RecordAnalyticsEventJob`
- Queue: `analytics` (new dedicated named queue on the `redis_analytics` connection — mirrors `videos`/`gdpr`; see §7).
- `handle(AnalyticsEventWriter $writer)`: rebuilds the DTO from payload, calls `$writer->write($event)`, then bumps the analytics cache version (debounced, §6) **after** the write returns.
- **Retries: 3 attempts, backoff — at-least-once.** Two distinct outcomes, both bounded:
  - **Permanent failure → one event dropped.** Acceptable for analytics. Counted via the drop metric below.
  - **Retry after a partial success → no duplicate.** The dangerous at-least-once case is: the writer's `INSERT` commits, then the worker dies / the cache bump throws *before the job acks* → Horizon retries → the same event is written again. This is neutralised by idempotency: the minted UUID is the row PK and the writer uses `insertOrIgnore` (§3.3), so the retry's insert no-ops. Without that, every worker restart silently inflates pageview/click counts. The post-write cache bump is wrapped (§6) so it can never be the thing that fails a job whose write already succeeded.
- **Invalid/non-trackable events are dropped inside the writer** with a `Log::info` + a counter `analytics.ingest.dropped` (tagged by `reason`: `block_missing` | `block_not_trackable` | `block_inactive`), **not** a job failure.
  - **Drops must alert on rate, not just be counted.** A counter nobody watches is invisible, and two real signals hide in the drop rate: (a) a frontend regression sending wrong/stale `block_id`s, and (b) someone spraying random block_ids to enumerate. Add a Nightwatch alert on a sustained spike in `analytics.ingest.dropped` (rate, not absolute) so a drop surge pages instead of vanishing. Note this *relocates* the block-validation `SELECT` from the request path into the worker — it is not eliminated; it is moved off the hot path.

## 4. Request-path flow (full decouple — APPROVED)

```
beacon → FormRequest validation (in-memory)
       → resolve site (cache-backed)                                // sync, no DB write
            → not found, subdomain-only        → 404
            → not found, site_id cross-check fails → 422  (IDOR signal — preserved)
            → unpublished                       → 404  (never 403: no existence leak)
       → bot filter (in-memory)                                     // click + section only; see §4.1
            → bot → 200 { message }, NO *_id field  (preserved)
       → mint UUID + stamp occurred_at = now()                      // MUST precede the claim
       → dedup-claim (Redis): claim(key, mintedUuid, ttl)           // sync, no Postgres
            → duplicate → 201 { *_id: <original uuid> }  (no enqueue)
       → ingestor.ingest(event)                                    // off the hot path
       → return 201 { visit_id|click_id|view_id: <minted uuid> }
```

> **Ordering is load-bearing.** The UUID is minted **before** the dedup claim because `claim()` stores the minted UUID as the Redis value so a later duplicate can echo it back (§5). Minting after the claim would break the "return the original id on duplicate" contract. (Earlier drafts listed these in the wrong order.)

### 4.1 Per-endpoint contract (the differences that "preserve exactly" must keep)

The three endpoints are **not** uniform today. The unified flow above is the superset; each endpoint keeps its current shape:

| Step | `pageview` | `click` | `sectionSeen` |
|---|---|---|---|
| 422 IDOR / 404 unpublished | yes | yes | yes |
| Bot filter | **no today** — see decision below | yes → `200`, no id | yes → `200`, no id |
| Dedup | **none** (no key) | `click` key, TTL 3s | `section` key, TTL 300s |
| Block validation | n/a | worker (§4 decouple) | worker; `block_id` is **optional** |
| Success id field | `visit_id` | `click_id` | `view_id` |

**Pageview bot-filtering — decision: do NOT add it in this change.** Today `pageview()` has no bot filter, and this is a write-path refactor, not a metrics-semantics change. Adding bot filtering to pageviews would drop pageview counts and break historical comparability of the dashboard's headline number — a separate, product-visible decision that must not ride in on a "make it async" PR. Pageview keeps its current flow (no bot filter, no dedup). If bot-filtering pageviews is wanted later, it is a deliberate follow-up with its own before/after callout. The §4 flow's bot-filter step therefore applies to `click`/`sectionSeen` only.

**Block validation moves to the worker (full decouple, approved).** Today `click` does a synchronous DB lookup verifying the block exists, belongs to the site, is trackable (`block_group`/`block_type` allowlist), and is active. This moves into `PostgresEventWriter`: the controller optimistically accepts (201) and the writer validates + drops invalid events (logged + counted).

- **Behaviour change:** a click to a missing/non-trackable/inactive block no longer returns a synchronous 404/422 — it returns 201 and is dropped server-side. Standard, correct trade for a fire-and-forget beacon.
- **Still synchronous (cheap, no Postgres write):** payload validation, cache-backed site resolution, unpublished-site 404, bot filtering. These gate whether we enqueue at all.

## 5. Dedup — `AnalyticsDedupGuard`

Backed by `Cache::add($key, $mintedUuid, $ttlSeconds)` (atomic `SETNX`, Redis store), same family as `LogLeadRateLimits`.

```php
// returns the id to echo in the response:
//   - novel  → SETNX wins → enqueue → return minted uuid
//   - dup    → SETNX loses → Cache::get($key) → return original uuid (preserves today's "return existing id")
public function claim(string $key, string $mintedUuid, int $ttl): array; // ['novel' => bool, 'id' => string]
```

Keys:
- click:   `analytics:dedup:click:{block_id}:{visitorOrSession}`  TTL 3s
- section: `analytics:dedup:section:{site_id}:{section_key}:{visitorOrSession}`  TTL 300s

**Identifier semantics (approved):** dedup on the strongest available identifier (`visitor_id`, else `session_id`) rather than SQL's "match either". Race-free and arguably more correct. If neither identifier is present, no dedup (same as today's `$hasIdentifier` guard).

**Window semantics — a second, intentional change.** The SQL dedup (`occurred_at >= now()-Ns`) is a **sliding** window: under sustained sub-TTL cadence it can suppress events indefinitely. `SETNX`+TTL is a **fixed** window: it resets every TTL, so a steady stream re-registers as novel once per TTL. The fixed window is arguably more correct for analytics (you do not want to suppress a genuine repeat click forever), but it **is** a behaviour change beyond the identifier change above, called out here so it is not a surprise in review.

**Boundary fallback (`Cache::get` may return null).** On a duplicate, the key can expire in the TOCTOU gap between the failed `SETNX` and the `Cache::get` (TTL is only 3s for clicks). When `get` returns null, **echo the freshly-minted UUID** rather than null — never put a null `*_id` in the 201 body. The dup is still suppressed (we did not enqueue); only the echoed id falls back.

**Redis-fault behaviour (fail-open, per §3.2).** Any Redis exception inside `claim()` is swallowed and reported as `['novel' => true, 'id' => $mintedUuid]` — i.e. proceed to enqueue without dedup rather than dropping the beacon or 500-ing the visitor. PK idempotency (§3.3) still prevents *retry* duplicates; only genuine distinct requests within the lost dedup window could double-count, which is the acceptable degraded mode.

> **Test note:** the array cache driver exercises the logical branches (novel / dup / fallback / fail-open) but not real `SETNX` atomicity — the "race-free" guarantee holds only against the Redis store. The unit tests validate branch behaviour; atomicity is a property of the production driver.

## 6. Cache invalidation

`debounceInvalidateAnalytics` moves from the controller into `RecordAnalyticsEventJob` (fires **after** the row lands, so the version bump reflects persisted data). Still debounced 30s/user via `Cache::add`, still wrapped so a cache fault never fails the job.

Two consequences of moving it into the job, both acceptable:
- **The version bump now lags the event by the queue latency.** Dashboards reflect new data after the job drains, not at request time. This is consistent with the 30s debounce already in place — analytics is eventually-consistent by design.
- **The wrap is now load-bearing for idempotency, not just hygiene.** If the cache bump threw *after* a successful write and were not caught, the job would fail and retry, re-running `handle()`. The PK `insertOrIgnore` (§3.3) already neutralises the duplicate insert, but wrapping the bump avoids the needless retry entirely — the write succeeded, so the job must ack.

## 7. Queue configuration

Add to `config/queue.php` a dedicated **`redis_analytics` connection** mirroring the *full* `redis_video` / `redis_gdpr` block shape (not a bare two-key snippet):
```php
// Dedicated connection for analytics ingest. Jobs are tiny (single insert + debounced
// cache bump), so retry_after is short — unlike video/gdpr which size it to long job timeouts.
// Run workers with: php artisan queue:work redis_analytics --queue=analytics --timeout=30
'redis_analytics' => [
    'driver' => 'redis',
    'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
    'queue' => env('PARTNA_ANALYTICS_QUEUE', 'analytics'),
    'retry_after' => (int) env('PARTNA_ANALYTICS_QUEUE_RETRY_AFTER', 60),
    'block_for' => null,
    'after_commit' => false,
],
```
`QueuedIngestor` dispatches with `->onConnection('redis_analytics')->onQueue('analytics')`. Horizon: add the `analytics` queue (on the `redis_analytics` connection) to the worker supervisor config. Keeps analytics ingest isolated from notifications/video processing.

> **Deploy ordering:** the Horizon supervisor change must ship with (or before) the code that dispatches to the `analytics` queue. If code dispatches before a consumer exists, jobs accumulate in Redis with no worker — harmless (they drain once the supervisor is up, per §11) but worth sequencing deliberately.

## 8. What ships now vs future drop-in

| Ships now | Future drop-in (additive, no caller change) | Trigger signal |
|---|---|---|
| `QueuedIngestor` (Horizon, at-least-once) | `BufferedIngestor` (Redis batch) behind `AnalyticsIngestor` | queue lag / Redis memory at multi-M events/day |
| `PostgresEventWriter` (row per event) | `ClickHouseEventWriter` / `writeMany` behind `AnalyticsEventWriter` | analytics queries dominating DB CPU, ~10M+/day |
| Raw tables unchanged | Rollup population, partitioning, HLL (read-path) | dashboard cache-miss p95 > ~300ms; ~30–50M+ rows |

At ~100k views/day the now-shipping stack runs at ~1% of comfortable capacity; no drop-in is warranted. Triggers are metrics, not dates — none are pre-built.

## 9. Testing

- `AnalyticsEvent`: round-trip array (de)serialization; minted `id` survives the round trip (it is the PK).
- `AnalyticsDedupGuard`:
  - novel vs duplicate; identifier fallback (`visitor_id` then `session_id`; neither → no dedup).
  - returns original id on collision (array/Redis cache fake).
  - **boundary fallback:** key expired between SETNX and get → returns the minted uuid, not null.
  - **fail-open:** a throwing cache store → `['novel' => true, 'id' => mintedUuid]`, no exception bubbles.
- `QueuedIngestor`:
  - `Queue::fake` → asserts `RecordAnalyticsEventJob` pushed with correct payload **on the `analytics` queue / `redis_analytics` connection**.
  - **dispatch failure → no inline write, no throw**, increments the drop metric (assert no row persisted).
- `SyncIngestor`: writes a row inline.
- `PostgresEventWriter`:
  - persists correct model per type; correct `block_id → link_block_id` (click) vs `block_id` (section) mapping.
  - **idempotency:** writing the same DTO twice (same minted PK) yields exactly **one** row (`insertOrIgnore`).
  - drops invalid / non-trackable / inactive block events without throwing; asserts the `analytics.ingest.dropped` counter with the right `reason` tag.
  - `writeMany` bulk path persists all valid events, batch-loads blocks (one query, not N), and is idempotent on PK.
- `RecordAnalyticsEventJob`:
  - persists + bumps cache version (debounced).
  - **retry idempotency:** running `handle()` twice for the same payload (simulating an at-least-once retry) leaves exactly one row.
  - a throwing cache bump after a successful write does **not** re-insert (PK no-op) — assert single row.
- Endpoint feature tests (contract preservation — see §4.1):
  - 201 + minted id echoed; `Queue::assertPushed`; dedup returns original id.
  - **422 IDOR** preserved (`site_id` given, subdomain cross-check fails); **404** for subdomain-only-not-found and for unpublished.
  - **bot path:** `click`/`sectionSeen` → `200` with message and **no `*_id` field**, synchronously (assert `Queue::assertNothingPushed`).
  - **pageview unchanged:** no bot filter (a bot UA still records a pageview today — assert it still does), no dedup.
  - click to a bad/non-trackable/inactive block now returns **201** + asserts the writer persisted **no** row and counted a drop.
- Existing `AnalyticsQueryService`/dashboard tests must remain green (read path untouched).

## 10. File inventory

**New**
- `app/Services/Analytics/AnalyticsEvent.php` (DTO)
- `app/Services/Analytics/Contracts/AnalyticsIngestor.php`
- `app/Services/Analytics/Contracts/AnalyticsEventWriter.php`
- `app/Services/Analytics/Ingestors/QueuedIngestor.php`
- `app/Services/Analytics/Ingestors/SyncIngestor.php`
- `app/Services/Analytics/Writers/PostgresEventWriter.php`
- `app/Services/Analytics/AnalyticsDedupGuard.php`
- `app/Jobs/Analytics/RecordAnalyticsEventJob.php`
- Tests under `tests/Unit/Analytics/` and `tests/Feature/Analytics/`

**Modified**
- `app/Http/Controllers/Api/PublicSite/AnalyticsController.php` (build DTO incl. **referrer sanitization** and request-time geo/device capture, mint UUID → dedup-claim → ingest; remove sync inserts + block DB lookup + sync cache bump). **Must preserve:** the 422 IDOR branch, the bot `200`-no-id branch, pageview's no-bot-filter/no-dedup flow (§4.1).
- `config/queue.php` (+ `redis_analytics` connection, §7), Horizon supervisor config (+ `analytics` queue)
- `AppServiceProvider` (bind `AnalyticsIngestor` + `AnalyticsEventWriter`; ingestor binding switches on env/`queue.default` like `MediaUploadService`)
- `config/partna.php` if a queue name / feature constant is needed

**Unchanged**
- `AnalyticsQueryService`, `AnalyticsCacheService`, dashboards, models, migrations, `rum()`, lead-submission ingest.

## 11. Rollout

No schema change (raw tables unchanged). Deploy = code + queue/Horizon config. Roll forward safely; if the `analytics` worker is down, jobs buffer in Redis and drain on recovery (at-least-once). Because writes are PK-idempotent (§3.3), the drain — including any retries — cannot double-count. Sequence the Horizon supervisor change with/before the dispatching code (§7). `SyncIngestor` keeps CI/local identical to today.

**Rollback:** code-only revert restores the synchronous path. Any jobs still in the `analytics` queue at revert time drain into raw tables via the reverted-out writer only if the worker class still exists — so on rollback, let the queue drain first (or accept the bounded loss of in-flight events). No data migration either direction.

---

## 12. Revision history

**rev. 2 (2026-05-30)** — hardening pass after design review:
- **Idempotency (was unaddressed):** minted UUID is now the row PK; writer uses `insertOrIgnore` so at-least-once retries no-op instead of double-counting (§3.1, §3.3, §3.4).
- **Flow ordering fixed:** mint UUID *before* the dedup claim, since `claim()` stores the uuid (§4 vs §5).
- **Dedup boundary + fault handling:** `Cache::get` null → echo minted uuid; Redis fault → fail-open enqueue (§5); sliding→fixed window change called out.
- **Contract preservation made explicit:** 422 IDOR, bot `200`-no-id, and pageview's no-bot-filter/no-dedup flow documented as must-keep (§2, §4.1). Pageview bot-filtering explicitly deferred.
- **Failure modes decided:** dispatch failure → lossy fail-open, no inline fallback (deliberate divergence from the image pattern) (§3.2).
- **Observability:** drop metric named + Nightwatch rate alert (§3.4).
- **Config corrected:** full `redis_analytics` connection block, not a two-key snippet (§7).
