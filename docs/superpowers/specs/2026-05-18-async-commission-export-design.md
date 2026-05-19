# Async Commission Transactions Export — Design Spec (v2 — Foundational)

**Date:** 2026-05-18 (v2 revision: 2026-05-19)
**Status:** Awaiting user approval

## Why this is v2

v1 cloned the GDPR single-job pattern. That works today (~500 payouts, ~2–4 min) but has two latent failures at scale: (a) the Stripe fetcher accumulates all rows in PHP memory — OOM around 30K rows; (b) the 600 s job timeout caps practical export size at ~1 K payouts. With "no fundamental change in a year" as the bar, both must be designed out now, not later. v2 is the foundational version.

## Overview

The `transactions` commission export currently runs synchronously: the request loops local payouts and calls `paymentIntents->retrieve` (brand role) or `charges->retrieve` (affiliate role) **once per payout** to enrich each row with Stripe-side detail. At a few hundred payouts the request times out before the file is returned.

This spec replaces the sync endpoint with a **chunked async pipeline** that:
- returns `202 Accepted` immediately with an export id;
- processes payouts in fixed-size chunks via self-chaining queue jobs (each chunk dispatches the next);
- streams normalised rows through a **JSONL intermediate layer in R2** so worker memory is bounded regardless of dataset size;
- has a separate **finalizer job** that concatenates parts into the final CSV/XLSX, generates a signed URL, and emails the recipient;
- exposes progress via a status endpoint (`payouts_processed / payouts_total`).

The three other commission export types (`payouts`, `detailed-commissions`, `eofy`) stay synchronous — they are pure DB streams via `lazy()` chunking with no per-row external calls, so they don't have this problem.

**Foundational property:** the architecture works identically for 1, 500, 5 000, and 50 000 payouts. Bigger exports run more chunk jobs; the code path is the same.

---

## Foundational design decisions (the ones that lock in scale)

| Decision | Why it's foundational |
|---|---|
| **Chunked self-chaining jobs** (each job dispatches the next chunk, terminal chunk dispatches finalizer) | Without this, max export = one job timeout. With it, max export = infinite. Changing later means re-architecting the whole pipeline. |
| **Generator-based Stripe fetcher** (`yieldRowsForPayouts(iterable $payouts): Generator`) | Eliminates the `$rows = []` accumulation in `StripeTransactionFetcher`. PHP memory stays O(1) per row regardless of dataset size. |
| **JSONL intermediate parts in R2** (one file per chunk under `parts/`) | Decouples row-production from final-format serialization. CSV/XLSX/PDF/etc. become finalizer concerns, not pipeline concerns. Resumable: a failed chunk overwrites its own part file on retry. |
| **Cursor on audit row** (`last_processed_payout_id`, `payouts_processed`, `next_chunk_index`) | Resume after partial failure. Progress visible to UI. Foundation for cancellation later. |
| **`expires_at` column on audit row + daily retention sweep** | Storage cost is bounded from day 1; no panic migration when R2 bills grow. |
| **Stuck-processing sweeper** (cron flips `processing` rows older than 1 h to `failed`) | Horizon restart mid-job leaves audit stuck in `processing` forever; the `failed()` hook doesn't fire. Without this sweep, the UI lies about export state. |
| **R2 path is folder-per-audit** (`exports/commissions/{prof_id}/{audit_id}/data.csv` and `…/{audit_id}/parts/chunk-N.jsonl`) | Multi-part downloads (split very large files) become a layout change in the finalizer, not a path migration. |
| **Stripe API version pinned** on the client | Stripe ships breaking changes via API-version headers. Pinning means our code's contract with Stripe is stable independent of dashboard settings. |
| **Idempotent chunk jobs** (re-running chunk N overwrites part-N.jsonl; finalizer is keyed on `audit_id`) | Horizon retries don't double-write or double-email. |
| **Throttle + dedup + stuck-sweep are independent layers** | Defense in depth. Each catches a different failure mode (accidental hammering / double-click / dead worker). |

What's **explicitly NOT in v1** but the architecture supports as additions:

- Concurrent Stripe calls within a chunk (bump a config knob; no structural change).
- Multi-part download (already-folder path, finalizer writes multiple `data-part-N.csv` files).
- Webhook completion or in-app notification (add a side-effect to the finalizer's success path).
- Cancellation (set audit `status='cancelled'`, chunk job checks at top, exits cleanly).
- Stripe Data Pipeline / warehouse-backed exports for bulk customers (would be a separate architecture for a separate use case — not a replacement).

---

## Architecture

```
  POST /api/professional/                ┌─────────────────────────────────────────────────────────────┐
   stripe/exports/transactions           │   Queue: redis · "exports"  (Horizon supervisor 660 s cap)  │
            │                            └─────────────────────────────────────────────────────────────┘
            │                                       ▲ chain  ▲ chain  ▲ chain
            ▼                                       │        │        │
  CommissionExportController             ┌──────────┴──────┐ │ ┌──────┴──────────────┐
   ::store()                             │ ExportChunkJob  │ │ │ ExportFinalizerJob  │
            │                            │ chunk 0 (0-499) │ │ │ ─ list parts/       │
   audit row INSERT status='queued'      │ ─ fetch payouts │ │ │ ─ open final file   │
   ExportCommissionTransactionsJob (1st  │ ─ stripe loop   │ │ │ ─ stream parts → it │
    chunk) dispatched                    │ ─ JSONL → R2    │ │ │ ─ R2 PUT data.csv   │
   202 returned                          │   parts/N.jsonl │ │ │ ─ delete parts/     │
            │                            │ ─ audit cursor  │ │ │ ─ signed URL        │
            │                            │ ─ dispatch next │─┘ │ ─ Mail::to(...)     │
            ▼                            │   chunk or fin. │   │ ─ audit completed   │
   { export_id, status: "queued",        └─────────────────┘   └─────────────────────┘
     payouts_total: 542, ... }                                          │
                                                                        ▼
                                                       R2: exports/commissions/{prof}/{audit}/
                                                            data.csv         (final, signed)
                                                            parts/chunk-0.jsonl (deleted by finalizer)
                                                            parts/chunk-1.jsonl
                                                            ...
```

**Invariants:**
1. Audit row is written **before** dispatch with `status='queued'`. Queue failures are still recorded.
2. Each chunk job is idempotent: re-running chunk N overwrites `parts/chunk-N.jsonl`. Cursor is updated *after* successful part upload, so a crash before upload re-runs with same cursor.
3. The finalizer runs **once**: the chain only dispatches it from the terminal chunk job. The finalizer also early-returns if `audit.status === completed`.
4. Email is sent **only** by the finalizer. No partial-result emails.
5. Per-payout Stripe failures are logged + skipped (matches today's `StripeTransactionFetcher.php:46,87`); whole-chunk failure only on infrastructure errors.

---

## API Surface

### Create export

```
POST /api/professional/stripe/exports/transactions
```

**Body:**
```json
{
  "format":    "csv",          // "csv" | "xlsx"  (required)
  "date_from": "2025-07-01",   // optional ISO date, inclusive
  "date_to":   "2026-05-19",   // optional ISO date, inclusive
  "type":      "all"           // "all" | "charge" | "refund" | "transfer" | "reversal"
}
```

**Middleware:** `supabase.jwt`, `current.pro`, `throttle:5,60` (5/hour/pro).

**Authorization:** Skeleton `CommissionPolicy::viewOwnPayouts` check via `authorizeForUser($pro, ...)`. Role is **server-inferred** from professional context, never client-supplied.

**Response (202 Accepted):**
```json
{
  "export_id":      "01HF...",
  "status":         "queued",
  "payouts_total":  542,
  "chunk_size":     500,
  "recipient_email":"jane@example.com",
  "message":        "Your commission report is being prepared. We'll email jane@example.com when it's ready — the link will be valid for 3 days."
}
```

Includes `Location: /api/professional/stripe/exports/commission/{export_id}` header for HATEOAS discoverability.

**Errors:** 422 invalid input / missing recipient email · 409 in-flight export for same pro+role+format within dedup window · 429 throttle · 403 policy denies role.

### Poll status

```
GET /api/professional/stripe/exports/commission/{export_id}
```

Returns the audit row as a Resource:
```json
{
  "id":                "01HF...",
  "status":            "processing",
  "format":            "csv",
  "payouts_total":     542,
  "payouts_processed": 230,
  "chunks_total":      2,
  "chunks_completed":  1,
  "file_size_bytes":   null,
  "error_message":     null,
  "created_at":        "2026-05-19T03:14:02Z",
  "completed_at":      null,
  "expires_at":        "2026-06-18T03:14:02Z"
}
```

404 (not 403) on cross-tenant access (per CLAUDE.md 403-vs-404 rule).

### Routes removed

```
GET /api/professional/stripe/exports/transactions.{csv|xlsx}     ← removed
```

The route regex on `/stripe/exports/{type}.{format}` is narrowed to `(payouts|detailed-commissions|eofy)`.

---

## Data Model

### `commerce.commission_export_audit`

```sql
CREATE TABLE commerce.commission_export_audit (
    id                          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id             uuid NOT NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    role                        text NOT NULL CHECK (role IN ('brand','affiliate')),
    format                      text NOT NULL CHECK (format IN ('csv','xlsx')),
    filters                     jsonb NOT NULL DEFAULT '{}'::jsonb,
    status                      text NOT NULL DEFAULT 'queued'
                                CHECK (status IN ('queued','processing','completed','failed','cancelled')),
    recipient_email             text NOT NULL,

    -- progress tracking
    payouts_total               integer NOT NULL DEFAULT 0,
    payouts_processed           integer NOT NULL DEFAULT 0,
    chunk_size                  integer NOT NULL DEFAULT 500,
    chunks_total                integer NOT NULL DEFAULT 0,
    chunks_completed            integer NOT NULL DEFAULT 0,
    last_processed_payout_id    uuid,
    next_chunk_index            integer NOT NULL DEFAULT 0,

    -- final output
    file_path                   text,
    file_size_bytes             bigint,
    file_sha256                 text,

    error_message               text,

    created_at                  timestamptz NOT NULL DEFAULT now(),
    processing_at               timestamptz,
    completed_at                timestamptz,
    expires_at                  timestamptz   -- file retention deadline
);

CREATE INDEX idx_cea_dedup ON commerce.commission_export_audit
    (professional_id, role, format, status, created_at DESC);

CREATE INDEX idx_cea_pro_created ON commerce.commission_export_audit
    (professional_id, created_at DESC);

-- For the stuck-processing sweeper
CREATE INDEX idx_cea_status_processing_at ON commerce.commission_export_audit
    (status, processing_at)
    WHERE status = 'processing';

-- For the retention sweeper
CREATE INDEX idx_cea_expires_at ON commerce.commission_export_audit (expires_at)
    WHERE expires_at IS NOT NULL AND file_path IS NOT NULL;
```

Notes:
- `'cancelled'` status is added now (column constraint), even though cancellation isn't surfaced in v1. Adding it later means dropping/recreating the check constraint — cheap but avoidable.
- `ON DELETE SET NULL` matches `core.data_export_audit` — audit rows survive professional deletion.
- `last_processed_payout_id` is a UUID cursor — chunk N fetches payouts where `created_at <= last_processed.created_at AND id < last_processed.id` ordered descending. This is stable under inserts/deletes; offset-based pagination is not.

### Model: `App\Models\Commerce\CommissionExportAudit`

- Extends `BaseModel`. Table `commerce.commission_export_audit`.
- Hidden: `recipient_email`.
- Status constants: `STATUS_QUEUED|PROCESSING|COMPLETED|FAILED|CANCELLED`.
- Helpers: `markProcessing()`, `markChunkCompleted(int $payoutsInChunk, string $lastPayoutId, int $nextIndex)`, `markCompleted($filePath, $size, $sha256)`, `markFailed($error)`.
- `static findRecentInFlight($professionalId, $role, $format, $windowMinutes): ?self`.
- `static dueForRetention(): Builder` — for the prune command.
- `static stuckInProcessing(int $olderThanMinutes): Builder` — for the sweep.

---

## Components

### Controller: `CommissionExportController`

`store()` — validates via `RequestCommissionExportRequest`, infers role server-side, calls `CommissionExportService::dispatch()`, returns 202 with `Location` header.

`show()` — returns the audit row as `CommissionExportAuditResource`. 404 on cross-tenant.

### Form Request: `RequestCommissionExportRequest`

Validates: `format` ∈ {csv,xlsx}, optional ISO dates with `date_to >= date_from`, optional `type` ∈ {all,charge,refund,transfer,reversal}. **No `role` field accepted.** `onlyFilters()` helper returns the filter dict for persistence.

### Service: `CommissionExportService`

```php
public function dispatch(
    Professional $professional,
    string $role,
    string $format,
    array $filters,
): CommissionExportAudit
```

- Resolves recipient email: `public_contact_email ?? primary_email`. Throws `NoRecipientEmailException` (422) if neither.
- DB transaction with row lock on a per-professional advisory lock (or `SELECT FOR UPDATE` on the most recent audit for this pro).
- Dedup check: `findRecentInFlight()` in window from config. Throws `CommissionExportInProgressException` (409) if found.
- **Counts payouts up front** (single COUNT query scoped by role + filters) → `payouts_total`. Computes `chunks_total = ceil(payouts_total / chunk_size)`. If `payouts_total == 0`, immediately marks audit `completed` with `record_count=0` and dispatches a no-op mail (empty-export branch) — keeps API contract uniform.
- Sets `expires_at = now() + retention_days` (config).
- Inserts audit row.
- Dispatches `ExportChunkJob::dispatch($audit->id, chunkIndex: 0)` (or finalizer-only for empty exports).
- Returns audit.

### Job: `App\Jobs\Exports\ExportChunkJob`

```php
public function __construct(public string $auditId, public int $chunkIndex) {}

// tries=3, backoff=[60,300,900], timeout=600
public function handle(
    StripeRowGenerator $generator,   // generator-based fetcher (new)
    JsonlPartWriter $partWriter,
    FilesystemManager $files,
): void
```

Flow:
1. Load audit. Early-return if `status` ∈ {completed, failed, cancelled}.
2. If `chunkIndex == 0` and `status == queued`: `markProcessing()`.
3. Compute window: starting payout cursor from `last_processed_payout_id` (NULL on chunk 0). Fetch up-to `chunk_size` payouts via stable cursor pagination.
4. **Generator-streaming write:** open a temp JSONL file, iterate `$generator->forPayouts($payouts, $role)` which yields normalised rows one at a time. Each yielded row → `fwrite($tmp, json_encode($row) . "\n")`. No array accumulation.
5. SHA-256 the JSONL temp (for debugging; not exposed).
6. Stream-upload to R2 path `exports/commissions/{prof_id}/{audit_id}/parts/chunk-{chunkIndex}.jsonl`.
7. `markChunkCompleted(...)` updating cursor + counters.
8. If `chunks_completed < chunks_total`: `ExportChunkJob::dispatch($auditId, $chunkIndex + 1)`. Else: `ExportFinalizerJob::dispatch($auditId)`.
9. Delete temp JSONL.
10. Outer try/catch: throw → `markFailed` on terminal failure (via `failed()`), re-throw for retry.

### Job: `App\Jobs\Exports\ExportFinalizerJob`

```php
public function __construct(public string $auditId) {}

// tries=3, backoff=[60,300,900], timeout=600
```

Flow:
1. Load audit. Early-return if `status == completed`.
2. List part files from R2 under `…/{audit_id}/parts/`.
3. Open local temp final file (CSV or XLSX based on `audit.format`).
4. Write header row.
5. For each part (in chunk-index order): stream the part from R2 line-by-line (`Storage::disk(...)->readStream()`), `json_decode` each line, write to final file. **Bounded memory** — never holds more than one row.
6. Close the final writer. SHA-256 the file. Stream-upload to `exports/commissions/{prof_id}/{audit_id}/data.{format}`.
7. Generate signed URL with `temporaryUrl(now()->addDays($ttlDays))`.
8. `Mail::to($audit->recipient_email)->send(new CommissionExportReadyMail(...))`.
9. `$audit->markCompleted(...)`.
10. Delete part files from R2.
11. Delete local temp file.

`failed()` hook on both jobs writes `markFailed` if status is not already `completed`.

### Generator: `App\Services\Stripe\StripeRowGenerator`

Refactor of `StripeTransactionFetcher`. The two existing methods (`forBrand`, `forAffiliate`) each return an `array<row>` and accumulate everything in memory. New design:

```php
public function forPayouts(iterable $payouts, string $role): \Generator
{
    foreach ($payouts as $payout) {
        try {
            $stripeObj = $role === 'brand'
                ? $this->stripe->paymentIntents->retrieve($payout->payment_intent_id, ['expand' => ['latest_charge.refunds']])
                : $this->stripe->charges->retrieve($payout->charge_id, ['expand' => ['transfer.reversals']]);
        } catch (\Throwable $e) {
            Log::warning('commission_export.stripe_retrieve_failed', [...]);
            continue;
        }

        foreach ($this->normalise($stripeObj, $payout, $role) as $row) {
            yield $row;
        }
    }
}
```

The existing sync `transactions` endpoint is being removed, so the old `forBrand`/`forAffiliate` callers go away — only the generator survives. Normalisation helpers (`normalizeBrandCharge`, `normalizeBrandRefund`, etc.) move into the generator and stay test-compatible.

### Writer: `App\Services\Exports\CommissionExportFileWriter`

Two small static helpers behind an interface:
- `writeCsv($outputPath, iterable $headerRow, iterable $rowStream): array` — `fputcsv` loop, returns `{path, size, sha256}`.
- `writeXlsx($outputPath, iterable $headerRow, iterable $rowStream): array` — openspout streaming writer.

Both accept iterables so the finalizer can stream parts in without buffering.

### Mailable: `App\Mail\Exports\CommissionExportReadyMail`

Constructor: `(string $signedUrl, string $role, string $format, array $filters, int $recordCount, int $ttlDays, \DateTimeImmutable $expiresAt)`.

Template `resources/views/emails/exports/commission-ready.blade.php`. Renders: greeting, summary line with date window + record count, download button, expiry timestamp, support footer.

### Resource: `CommissionExportAuditResource`

Exposes all progress + final fields. Hides `recipient_email` and `file_path` (R2 key isn't useful or safe to leak; the signed URL is delivered via email only).

### Stripe client version pin

In `app/Providers/AppServiceProvider.php` (or wherever `StripeClient` is bound), set `'stripe_version' => '2025-02-24.acacia'` (or current value at implementation time) on the client config. This pins behaviour at the call site instead of relying on dashboard defaults. Without this, a dashboard upgrade could change response shapes mid-loop.

---

## Storage & Retention

- **Disk:** `config('partna.media_disk')` (R2). Same disk as GDPR.
- **Layout:**
  ```
  exports/commissions/{professional_id}/{audit_id}/
       data.csv  or  data.xlsx        ← final, signed URL
       parts/
            chunk-0.jsonl              ← deleted by finalizer
            chunk-1.jsonl
            ...
  ```
- **Signed URL TTL:** 3 days (config).
- **File retention:** `expires_at = completed_at + 30 days` (config). Daily cron `commission-exports:prune-expired` deletes the R2 object and clears `file_path` on rows past `expires_at`. Audit row stays; only the file goes.
- **Stuck-processing sweep:** hourly cron `commission-exports:sweep-stuck` finds audits where `status='processing' AND processing_at < now() - 1 hour` and marks them failed with `error_message='processing watchdog timeout'`.

---

## Queue & Retry Policy

- **Queue:** `exports` (new) on connection `redis` (default). Config-overridable.
- **Per-job timeout:** 600 s. With `chunk_size=500` and ~500 ms per Stripe call, a chunk takes ~4 min — comfortable headroom.
- **Retries:** 3 with backoff `[60, 300, 900]`. Per-job, not per-export.
- **Failed-callback** writes `markFailed` on both jobs.
- **Idempotency:** chunk N re-running overwrites its part file; finalizer re-running early-returns if audit `status=completed`.
- **Horizon config:** add `exports` queue to existing supervisor or a new one. Document worker count tuning in `config/horizon.php` (start at 2 workers).

---

## Configuration

`config/partna.php` adds:

```php
'exports' => [
    'commission' => [
        'queue'                  => env('COMMISSION_EXPORT_QUEUE', 'exports'),
        'connection'             => env('COMMISSION_EXPORT_CONNECTION', 'redis'),
        'chunk_size'             => env('COMMISSION_EXPORT_CHUNK_SIZE', 500),
        'signed_url_ttl_days'    => env('COMMISSION_EXPORT_TTL_DAYS', 3),
        'retention_days'         => env('COMMISSION_EXPORT_RETENTION_DAYS', 30),
        'dedup_window_minutes'   => env('COMMISSION_EXPORT_DEDUP_MINUTES', 5),
        'stuck_watchdog_minutes' => env('COMMISSION_EXPORT_STUCK_MINUTES', 60),
        'stripe_api_version'     => env('STRIPE_API_VERSION', '2025-02-24.acacia'),
    ],
],
```

`.env.example` gets the new keys with defaults. No prod env changes required at ship time.

---

## Failure Modes & Edge Cases

| Scenario | Behaviour |
|---|---|
| No payouts in range | `payouts_total=0` → audit immediately `completed`, empty-file branch sends mail with "0 transactions" note (still useful confirmation). |
| Professional has no email | `dispatch()` throws 422 before audit row inserted. |
| Two identical requests within dedup window | First → 202; second → 409 with existing `export_id`. |
| Stripe rate-limits 50 of 500 retrieves in a chunk | Each logged + skipped per existing fetcher behaviour. Chunk completes with fewer rows; cursor advances normally. |
| Stripe outage mid-chunk | Chunk job throws → retry 1 (60 s) → retry 2 (5 min) → retry 3 (15 min). Cursor untouched until success, so retries replay the same payouts. After 3 failures: audit `failed`, no email, no next chunk dispatched. |
| Worker killed mid-chunk after JSONL upload but before cursor write | Retry re-runs same chunk index. JSONL is overwritten. Cursor advances on this attempt. No data loss, no duplicate work. |
| Horizon restart mid-job, retries exhausted, `failed()` callback never fires | Hourly stuck-sweeper marks audit `failed` after 1 h. |
| Finalizer fails after final upload but before `markCompleted` | Retry early-returns on `status=completed` only — but status is still `processing`. Retry uploads file again (same path, overwrites), re-sends mail, marks completed. Acceptable (rare, idempotent). |
| Recipient email bounces | Out of scope for v1. Future: capture bounce webhook, surface in audit. |
| User clicks an expired link | R2 returns signed-URL-expired. Frontend can surface "request a new export". |
| Professional hard-deleted between dispatch and chunk run | Chunk job sees `professional_id=NULL` (FK SET NULL) → `markFailed('professional deleted')`, no retry. |
| Audit row reaches `completed` then a stale chunk job fires (e.g. duplicate dispatch) | Early-return at top. No double-email. |

---

## Frontend Contract Change

Old contract (`GET /stripe/exports/transactions.{csv,xlsx}` → streamed file) is removed. Frontend must:

1. `POST /api/professional/stripe/exports/transactions` with JSON body → 202 + `{export_id, status, payouts_total, recipient_email, message}`.
2. Show confirmation: "Preparing export — we'll email jane@example.com when it's ready."
3. **(Optional, recommended for UX)** poll `GET /stripe/exports/commission/{export_id}` every 5–10 s and render a progress bar from `payouts_processed / payouts_total`. Stop polling when `status` ∈ {completed, failed}.
4. Handle 409 by surfacing existing in-flight export id.

The frontend repo is separate; this work coordinates with a frontend PR. Pre-beta, no real users — clean break is acceptable.

---

## Observability

Structured log events at each stage. Naming convention `commission_export.<event>`:

| Event | Fields |
|---|---|
| `commission_export.dispatched` | audit_id, professional_id, role, format, payouts_total, chunks_total |
| `commission_export.chunk_started` | audit_id, chunk_index |
| `commission_export.chunk_completed` | audit_id, chunk_index, payouts_in_chunk, duration_ms |
| `commission_export.stripe_retrieve_failed` | audit_id, chunk_index, payout_id, error |
| `commission_export.finalizer_started` | audit_id, chunks_total |
| `commission_export.finalizer_completed` | audit_id, file_size_bytes, total_rows, duration_ms |
| `commission_export.failed` | audit_id, stage (chunk/finalizer), error |
| `commission_export.swept_stuck` | audit_id, age_minutes |
| `commission_export.pruned_expired` | audit_id |

Nightwatch will surface exceptions automatically. Add no manual instrumentation beyond these logs in v1 — Grafana dashboards can be built later from the log stream.

---

## Testing Strategy

**Feature tests** (`tests/Feature/Professional/Stripe/CommissionExportTest.php`):
- `returns 202 with full envelope including payouts_total and chunks_total`
- `stores filters in audit row`
- `422 when professional has no email`
- `409 when in-flight export exists for same role+format`
- `403 when policy rejects role`
- `dispatches ExportChunkJob with chunk_index=0`
- `empty-result branch: payouts_total=0 → immediate completion + mail`
- `show endpoint returns audit; 404s cross-tenant`

**Chunk job tests** (`tests/Feature/Exports/ExportChunkJobTest.php`):
- `marks audit processing on first chunk`
- `writes JSONL part to R2 at expected path`
- `advances cursor + counters after success`
- `dispatches next chunk when not terminal`
- `dispatches finalizer when terminal`
- `early-returns if audit completed`
- `early-returns if audit cancelled`
- `is idempotent: re-running overwrites same part`
- `marks audit failed via failed() after retries exhausted`

**Finalizer tests** (`tests/Feature/Exports/ExportFinalizerJobTest.php`):
- `concatenates JSONL parts into CSV with header`
- `concatenates JSONL parts into XLSX`
- `uploads final file to expected R2 path`
- `signs URL with configured TTL`
- `sends mail to recipient with signed URL`
- `marks audit completed with file size + sha256`
- `deletes part files after success`
- `early-returns if audit completed`

**Generator tests** (`tests/Unit/Services/Stripe/StripeRowGeneratorTest.php`):
- `yields rows lazily — memory stays bounded across 5K mock payouts` (uses `memory_get_peak_usage` assertion)
- `skips and logs payouts whose Stripe call throws`
- `produces same row shape as the legacy fetcher` (regression contract)

**Sweep command tests** (`tests/Feature/Console/CommissionExportsSweepCommandTest.php`):
- `flips processing rows older than threshold to failed`
- `does not touch rows still within window`
- `prunes expired files and clears file_path`
- `does not delete rows themselves`

**Smoke test on dev** after merge: trigger via curl with a dev pro's JWT → tail `cloud env:logs partna development --live` for the event stream → inbox receives email → signed link downloads file.

---

## Out of Scope / Future Additions

None of these require structural changes — they slot in.

- Concurrent Stripe calls within a chunk (Laravel `Concurrency::run` or guzzle pool; bumps a config knob).
- Multi-part download for >100 MB files (finalizer writes `data-part-N.csv`; resource returns array of signed URLs).
- Webhook completion / in-app push notification (add side-effect to finalizer success path; UI removes polling).
- Cancellation (`PATCH /stripe/exports/commission/{id}` with `{status: cancelled}`; chunk job checks at top).
- Bounce-handling integration (capture mail bounce webhook, surface in audit row).
- Stripe Data Pipeline migration for warehouse-scale customers (different architecture for a different problem).
- Replacing per-row retrieve with `payment_intents.list` paginated batch retrieval — wait until v2 `customer_account` filter stabilises (see [Stripe API ref](https://docs.stripe.com/api/payment_intents/list)).
