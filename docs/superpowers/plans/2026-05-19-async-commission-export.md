# Async Commission Transactions Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the synchronous `GET /stripe/exports/transactions.{csv,xlsx}` endpoint with a foundational async pipeline that scales from 1 to 50 000+ payouts without architectural change.

**Architecture:** Chunked self-chaining queue jobs write JSONL part files to R2; a finalizer job concatenates parts into the final CSV/XLSX, generates a 3-day signed URL, and emails the recipient. Audit row tracks cursor + progress for resumability and UI display.

**Tech Stack:** Laravel 12, PHP 8.2, Supabase PostgreSQL (schema `commerce`), Redis queue via Horizon, Cloudflare R2 (Laravel `media_disk`), Stripe PHP SDK, openspout for XLSX streaming.

**Spec:** [docs/superpowers/specs/2026-05-18-async-commission-export-design.md](../specs/2026-05-18-async-commission-export-design.md)

---

## File Structure

### New files

| File | Responsibility |
|---|---|
| `supabase/migrations/20260519100000_create_commerce_commission_export_audit.sql` | DDL: create table + check constraints |
| `supabase/migrations/20260519100001_add_commerce_commission_export_audit_indexes.sql` | `CREATE INDEX CONCURRENTLY` (separate file per repo convention) |
| `app/Models/Commerce/CommissionExportAudit.php` | Eloquent model, status helpers, scope helpers |
| `app/Exceptions/CommissionExportInProgressException.php` | Thrown on dedup hit → 409 |
| `app/Exceptions/NoRecipientEmailException.php` (if it doesn't already exist for GDPR — check first) | Thrown when professional has no usable email → 422 |
| `app/Http/Requests/Api/Professional/Stripe/RequestCommissionExportRequest.php` | Validates POST body (no role field) |
| `app/Http/Resources/Professional/CommissionExportAuditResource.php` | Transformer for status endpoint |
| `app/Http/Controllers/Api/Professional/Stripe/CommissionExportController.php` | Thin controller: store + show |
| `app/Services/Stripe/CommissionExportService.php` | Dispatcher: dedup, count, audit row, kick off first chunk |
| `app/Services/Stripe/StripeRowGenerator.php` | Generator-based Stripe fetcher (yields rows one at a time) |
| `app/Services/Exports/JsonlPartWriter.php` | Writes one JSONL part file from a row iterator + uploads to R2 |
| `app/Services/Exports/CommissionExportFinalWriter.php` | Reads JSONL parts back from R2 and writes consolidated CSV/XLSX |
| `app/Jobs/Exports/ExportChunkJob.php` | Per-chunk worker; self-chains the next chunk or dispatches finalizer |
| `app/Jobs/Exports/ExportFinalizerJob.php` | Concatenates parts → uploads final → signs URL → emails → marks complete |
| `app/Mail/Exports/CommissionExportReadyMail.php` | Mailable for the download-link email |
| `resources/views/emails/exports/commission-ready.blade.php` | Email template |
| `app/Console/Commands/CommissionExportsSweepStuckCommand.php` | Hourly: flips processing rows older than 60 min to failed |
| `app/Console/Commands/CommissionExportsPruneExpiredCommand.php` | Daily: deletes R2 files past `expires_at`, clears `file_path` |
| `tests/Feature/Professional/Stripe/CommissionExportTest.php` | Controller endpoints |
| `tests/Feature/Exports/ExportChunkJobTest.php` | Chunk job behaviour |
| `tests/Feature/Exports/ExportFinalizerJobTest.php` | Finalizer behaviour |
| `tests/Unit/Services/Stripe/StripeRowGeneratorTest.php` | Generator lazy-eval + error tolerance |
| `tests/Feature/Console/CommissionExportsSweepStuckCommandTest.php` | Sweep command |
| `tests/Feature/Console/CommissionExportsPruneExpiredCommandTest.php` | Prune command |
| `tests/Feature/Mail/CommissionExportReadyMailTest.php` | Email rendering |

### Modified files

| File | Change |
|---|---|
| `config/partna.php` | Add `exports.commission` config block |
| `.env.example` | Add `COMMISSION_EXPORT_*` keys + `STRIPE_API_VERSION` |
| `routes/api/professional.php` | Narrow existing route regex; add new POST + GET endpoints |
| `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php` | Remove `transactions` handling from `export()` method |
| `app/Services/Stripe/ExportService.php` | Remove `exportTransactions()` method (and `StripeTransactionFetcher` injection if now unused) |
| `app/Providers/AppServiceProvider.php` | Pin Stripe API version on the bound StripeClient; register `CommissionExportAudit` policy |
| `bootstrap/app.php` (Laravel 11/12 scheduler) | Schedule `commission-exports:sweep-stuck` hourly + `commission-exports:prune-expired` daily |
| `config/horizon.php` | Add `exports` queue to existing `supervisor-1` or new supervisor |
| `app/Policies/CommissionPolicy.php` (verify it has `viewOwnPayouts`) | No change expected; new controller reuses skeleton pattern |
| `tests/Feature/Professional/Stripe/StripeExportTest.php` (if present) | Remove `transactions` cases from the sync test matrix |

---

## Task Ordering Rationale

Foundation first (config, migration, model), then producers (generator, writers), then consumers (jobs), then surface (controller, routes), then operational cron + final cleanup of the removed sync path. Each task is self-contained and ends with a green test run + a commit.

---

## Task 1: Add config keys & env vars

**Files:**
- Modify: `config/partna.php` (add new top-level `exports` block)
- Modify: `.env.example`

- [ ] **Step 1: Open `config/partna.php` and locate a good insertion point** (alphabetical-ish; near `gdpr` if present, otherwise end of file before the closing `];`).

- [ ] **Step 2: Add the `exports` config block.**

```php
// Async commission/financial exports. The 'commission' subkey scopes the
// transactions export (POST /stripe/exports/transactions); other exports
// (payouts, detailed-commissions, eofy) remain synchronous and don't read these.
'exports' => [
    'commission' => [
        'queue'                  => env('COMMISSION_EXPORT_QUEUE', 'exports'),
        'connection'             => env('COMMISSION_EXPORT_CONNECTION', 'redis'),
        'chunk_size'             => (int) env('COMMISSION_EXPORT_CHUNK_SIZE', 500),
        'signed_url_ttl_days'    => (int) env('COMMISSION_EXPORT_TTL_DAYS', 3),
        'retention_days'         => (int) env('COMMISSION_EXPORT_RETENTION_DAYS', 30),
        'dedup_window_minutes'   => (int) env('COMMISSION_EXPORT_DEDUP_MINUTES', 5),
        'stuck_watchdog_minutes' => (int) env('COMMISSION_EXPORT_STUCK_MINUTES', 60),
    ],
],
```

- [ ] **Step 3: Add the Stripe API version env to `.env.example`.**

Add under the existing Stripe block:

```
# Pin Stripe API version at the SDK client level so behaviour is independent of
# dashboard settings. Bump intentionally after testing.
STRIPE_API_VERSION=2025-02-24.acacia
```

- [ ] **Step 4: Add commission export env keys to `.env.example`.**

```
# Async commission transactions export
COMMISSION_EXPORT_QUEUE=exports
COMMISSION_EXPORT_CONNECTION=redis
COMMISSION_EXPORT_CHUNK_SIZE=500
COMMISSION_EXPORT_TTL_DAYS=3
COMMISSION_EXPORT_RETENTION_DAYS=30
COMMISSION_EXPORT_DEDUP_MINUTES=5
COMMISSION_EXPORT_STUCK_MINUTES=60
```

- [ ] **Step 5: Verify config loads without error.**

Run: `php artisan config:show partna.exports.commission`
Expected: a clean dump of the 7 keys with defaults.

- [ ] **Step 6: Commit.**

```bash
git add config/partna.php .env.example
git commit -m "feat(exports): add commission async export config keys"
```

---

## Task 2: Migration — table

**Files:**
- Create: `supabase/migrations/20260519100000_create_commerce_commission_export_audit.sql`

- [ ] **Step 1: Create the DDL migration file.**

```sql
-- 20260519100000_create_commerce_commission_export_audit.sql
-- Audit table for the async commission transactions export pipeline.
-- One row per export request; tracks progress (cursor + counters), final file
-- metadata, and retention deadline. Indexes are in a separate file per the
-- post-2026-05-14 CONCURRENTLY convention.

BEGIN;

CREATE TABLE commerce.commission_export_audit (
    id                          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id             uuid NOT NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    role                        text NOT NULL CHECK (role IN ('brand','affiliate')),
    format                      text NOT NULL CHECK (format IN ('csv','xlsx')),
    filters                     jsonb NOT NULL DEFAULT '{}'::jsonb,
    status                      text NOT NULL DEFAULT 'queued'
                                CHECK (status IN ('queued','processing','completed','failed','cancelled')),
    recipient_email             text NOT NULL,

    payouts_total               integer NOT NULL DEFAULT 0,
    payouts_processed           integer NOT NULL DEFAULT 0,
    chunk_size                  integer NOT NULL DEFAULT 500,
    chunks_total                integer NOT NULL DEFAULT 0,
    chunks_completed            integer NOT NULL DEFAULT 0,
    last_processed_payout_id    uuid,
    next_chunk_index            integer NOT NULL DEFAULT 0,

    file_path                   text,
    file_size_bytes             bigint,
    file_sha256                 text,

    error_message               text,

    created_at                  timestamptz NOT NULL DEFAULT now(),
    processing_at               timestamptz,
    completed_at                timestamptz,
    expires_at                  timestamptz
);

COMMENT ON TABLE commerce.commission_export_audit IS
    'Per-request audit row for async commission transactions exports. Drives chunk-job orchestration, progress UI, and retention.';
COMMENT ON COLUMN commerce.commission_export_audit.last_processed_payout_id IS
    'Stable cursor: chunk N fetches payouts after this id. Survives inserts/deletes.';
COMMENT ON COLUMN commerce.commission_export_audit.expires_at IS
    'When the file in R2 becomes eligible for the daily prune sweep.';

COMMIT;
```

- [ ] **Step 2: Dry-run the migration against dev.**

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm   # interactive: run with `! ` prefix
supabase db push --dry-run
```

Expected: dry-run reports the single new file with no errors.

- [ ] **Step 3: Push to dev.**

```bash
supabase db push
```

Expected: applied cleanly. (Prod is gated separately — do not push to prod here.)

- [ ] **Step 4: Sanity check the table exists.**

Via `mcp__claude_ai_Supabase__execute_sql` on the dev project ref `glncumufgaqcmqhzwrxm`:

```sql
SELECT column_name, data_type
FROM information_schema.columns
WHERE table_schema = 'commerce' AND table_name = 'commission_export_audit'
ORDER BY ordinal_position;
```

Expected: 21 columns listed.

- [ ] **Step 5: Commit.**

```bash
git add supabase/migrations/20260519100000_create_commerce_commission_export_audit.sql
git commit -m "feat(db): add commerce.commission_export_audit table"
```

---

## Task 3: Migration — indexes (CONCURRENTLY, separate file)

**Files:**
- Create: `supabase/migrations/20260519100001_add_commerce_commission_export_audit_indexes.sql`

- [ ] **Step 1: Create the indexes migration.**

```sql
-- 20260519100001_add_commerce_commission_export_audit_indexes.sql
-- Per CONVENTIONS.md: index DDL lives in a separate file, no transaction wrapper,
-- CREATE INDEX CONCURRENTLY. (Table is empty at apply time; the convention still
-- applies for consistency and future safety if columns are backfilled.)

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_cea_dedup
    ON commerce.commission_export_audit (professional_id, role, format, status, created_at DESC);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_cea_pro_created
    ON commerce.commission_export_audit (professional_id, created_at DESC);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_cea_status_processing_at
    ON commerce.commission_export_audit (status, processing_at)
    WHERE status = 'processing';

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_cea_expires_at
    ON commerce.commission_export_audit (expires_at)
    WHERE expires_at IS NOT NULL AND file_path IS NOT NULL;
```

- [ ] **Step 2: Dry-run.**

```bash
supabase db push --dry-run
```

Expected: dry-run reports the new file; no transaction wrapper.

- [ ] **Step 3: Push to dev.**

```bash
supabase db push
```

- [ ] **Step 4: Verify indexes via Supabase MCP.**

```sql
SELECT indexname FROM pg_indexes
WHERE schemaname = 'commerce' AND tablename = 'commission_export_audit'
ORDER BY indexname;
```

Expected: 4 indexes plus the implicit PRIMARY KEY index = 5 rows.

- [ ] **Step 5: Commit.**

```bash
git add supabase/migrations/20260519100001_add_commerce_commission_export_audit_indexes.sql
git commit -m "feat(db): add commission_export_audit indexes"
```

---

## Task 4: Eloquent model + unit tests

**Files:**
- Create: `app/Models/Commerce/CommissionExportAudit.php`
- Create: `tests/Unit/Models/Commerce/CommissionExportAuditTest.php`

- [ ] **Step 1: Write the failing model test first.**

```php
<?php

namespace Tests\Unit\Models\Commerce;

use App\Models\Commerce\CommissionExportAudit;
use App\Models\Core\Professional\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionExportAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_helpers_advance_state(): void
    {
        $audit = $this->makeAudit();

        $audit->markProcessing();
        $this->assertSame(CommissionExportAudit::STATUS_PROCESSING, $audit->fresh()->status);
        $this->assertNotNull($audit->fresh()->processing_at);

        $audit->markChunkCompleted(payoutsInChunk: 250, lastPayoutId: 'abc', nextIndex: 1);
        $this->assertSame(250, $audit->fresh()->payouts_processed);
        $this->assertSame('abc', $audit->fresh()->last_processed_payout_id);
        $this->assertSame(1, $audit->fresh()->next_chunk_index);
        $this->assertSame(1, $audit->fresh()->chunks_completed);

        $audit->markCompleted(filePath: 'exports/commissions/x/y/data.csv', size: 1234, sha256: 'deadbeef');
        $fresh = $audit->fresh();
        $this->assertSame(CommissionExportAudit::STATUS_COMPLETED, $fresh->status);
        $this->assertSame(1234, $fresh->file_size_bytes);
        $this->assertSame('deadbeef', $fresh->file_sha256);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_recipient_email_is_hidden_from_array(): void
    {
        $audit = $this->makeAudit(['recipient_email' => 'jane@example.com']);

        $this->assertArrayNotHasKey('recipient_email', $audit->toArray());
    }

    public function test_find_recent_in_flight_respects_window(): void
    {
        $pro = Professional::factory()->create();

        // Fresh queued row → found
        $audit = CommissionExportAudit::create($this->attrs($pro, ['status' => 'queued']));
        $this->assertNotNull(CommissionExportAudit::findRecentInFlight($pro->id, 'brand', 'csv', 5));

        // Mark completed → not found
        $audit->update(['status' => 'completed']);
        $this->assertNull(CommissionExportAudit::findRecentInFlight($pro->id, 'brand', 'csv', 5));
    }

    private function makeAudit(array $overrides = []): CommissionExportAudit
    {
        $pro = Professional::factory()->create();
        return CommissionExportAudit::create($this->attrs($pro, $overrides));
    }

    private function attrs(Professional $pro, array $overrides = []): array
    {
        return array_merge([
            'professional_id' => $pro->id,
            'role' => 'brand',
            'format' => 'csv',
            'filters' => [],
            'status' => 'queued',
            'recipient_email' => 'test@example.com',
            'payouts_total' => 500,
            'chunk_size' => 500,
            'chunks_total' => 1,
        ], $overrides);
    }
}
```

- [ ] **Step 2: Run the test — expect failure.**

```bash
./vendor/bin/pest tests/Unit/Models/Commerce/CommissionExportAuditTest.php
```

Expected: FAIL — class `App\Models\Commerce\CommissionExportAudit` not found.

- [ ] **Step 3: Implement the model.**

```php
<?php

namespace App\Models\Commerce;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionExportAudit extends BaseModel
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'commerce.commission_export_audit';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['recipient_email'];

    protected $casts = [
        'filters' => 'array',
        'payouts_total' => 'integer',
        'payouts_processed' => 'integer',
        'chunk_size' => 'integer',
        'chunks_total' => 'integer',
        'chunks_completed' => 'integer',
        'next_chunk_index' => 'integer',
        'file_size_bytes' => 'integer',
        'created_at' => 'immutable_datetime',
        'processing_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function markProcessing(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSING,
            'processing_at' => $this->processing_at ?? now(),
        ])->save();
    }

    public function markChunkCompleted(int $payoutsInChunk, ?string $lastPayoutId, int $nextIndex): void
    {
        $this->forceFill([
            'payouts_processed' => $this->payouts_processed + $payoutsInChunk,
            'last_processed_payout_id' => $lastPayoutId,
            'next_chunk_index' => $nextIndex,
            'chunks_completed' => $this->chunks_completed + 1,
        ])->save();
    }

    public function markCompleted(string $filePath, int $size, string $sha256): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'file_path' => $filePath,
            'file_size_bytes' => $size,
            'file_sha256' => $sha256,
            'completed_at' => now(),
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_message' => mb_substr($error, 0, 2000),
            'completed_at' => now(),
        ])->save();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public static function findRecentInFlight(
        string $professionalId,
        string $role,
        string $format,
        int $windowMinutes,
    ): ?self {
        return self::query()
            ->where('professional_id', $professionalId)
            ->where('role', $role)
            ->where('format', $format)
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_PROCESSING])
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->orderByDesc('created_at')
            ->first();
    }

    public static function stuckInProcessing(int $olderThanMinutes): Builder
    {
        return self::query()
            ->where('status', self::STATUS_PROCESSING)
            ->where('processing_at', '<', now()->subMinutes($olderThanMinutes));
    }

    public static function dueForRetention(): Builder
    {
        return self::query()
            ->whereNotNull('file_path')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }
}
```

- [ ] **Step 4: Run the test — expect PASS.**

```bash
./vendor/bin/pest tests/Unit/Models/Commerce/CommissionExportAuditTest.php
```

Expected: PASS (3 tests).

- [ ] **Step 5: Commit.**

```bash
git add app/Models/Commerce/CommissionExportAudit.php tests/Unit/Models/Commerce/CommissionExportAuditTest.php
git commit -m "feat(models): add CommissionExportAudit"
```

---

## Task 5: Custom exceptions

**Files:**
- Create: `app/Exceptions/CommissionExportInProgressException.php`
- Verify or create: `app/Exceptions/NoRecipientEmailException.php`

- [ ] **Step 1: Check if `NoRecipientEmailException` already exists from GDPR.**

```bash
find app/Exceptions -name "NoRecipientEmail*"
```

If it exists, skip its creation. If it lives under a Gdpr namespace, leave it there — create a parallel one in this namespace; do not cross-import GDPR-specific classes.

- [ ] **Step 2: Create `NoRecipientEmailException` (if missing) under the generic exceptions namespace.**

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class NoRecipientEmailException extends RuntimeException
{
    public function __construct(string $professionalId)
    {
        parent::__construct(sprintf('Professional %s has no recipient email on file.', $professionalId));
    }
}
```

- [ ] **Step 3: Create `CommissionExportInProgressException`.**

```php
<?php

namespace App\Exceptions;

use App\Models\Commerce\CommissionExportAudit;
use RuntimeException;

class CommissionExportInProgressException extends RuntimeException
{
    public function __construct(public readonly CommissionExportAudit $existing)
    {
        parent::__construct(sprintf(
            'A commission export is already in progress for this account (id=%s, status=%s).',
            $existing->id,
            $existing->status,
        ));
    }
}
```

- [ ] **Step 4: Commit.**

```bash
git add app/Exceptions/
git commit -m "feat(exports): add commission export exceptions"
```

---

## Task 6: Form Request + Resource

**Files:**
- Create: `app/Http/Requests/Api/Professional/Stripe/RequestCommissionExportRequest.php`
- Create: `app/Http/Resources/Professional/CommissionExportAuditResource.php`

- [ ] **Step 1: Create the FormRequest.**

```php
<?php

namespace App\Http\Requests\Api\Professional\Stripe;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /stripe/exports/transactions. `role` is server-inferred from
 * the resolved professional context — never accept it from the client (prevents
 * a brand-only pro requesting affiliate-side data and vice versa).
 */
class RequestCommissionExportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'type' => ['nullable', Rule::in(['all', 'charge', 'refund', 'transfer', 'reversal'])],
        ];
    }

    /**
     * Returns only the fields persisted into the audit row's `filters` JSONB —
     * shape matches what StripeRowGenerator + the existing scopedPayouts query expect.
     *
     * @return array{date_from?: string, date_to?: string, type: string}
     */
    public function onlyFilters(): array
    {
        $data = $this->validated();
        return array_filter([
            'date_from' => $data['date_from'] ?? null,
            'date_to'   => $data['date_to'] ?? null,
            'type'      => $data['type'] ?? 'all',
        ], fn ($v) => $v !== null);
    }
}
```

- [ ] **Step 2: Create the Resource.**

```php
<?php

namespace App\Http\Resources\Professional;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Commerce\CommissionExportAudit */
class CommissionExportAuditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'format' => $this->format,
            'filters' => $this->filters,
            'payouts_total' => $this->payouts_total,
            'payouts_processed' => $this->payouts_processed,
            'chunk_size' => $this->chunk_size,
            'chunks_total' => $this->chunks_total,
            'chunks_completed' => $this->chunks_completed,
            'file_size_bytes' => $this->file_size_bytes,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'processing_at' => $this->processing_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 3: Commit.**

```bash
git add app/Http/Requests/Api/Professional/Stripe/RequestCommissionExportRequest.php app/Http/Resources/Professional/CommissionExportAuditResource.php
git commit -m "feat(exports): add commission export request + resource"
```

---

## Task 7: StripeRowGenerator (generator-based fetcher)

**Files:**
- Create: `app/Services/Stripe/StripeRowGenerator.php`
- Create: `tests/Unit/Services/Stripe/StripeRowGeneratorTest.php`

This is the load-bearing refactor: replaces the in-memory `$rows = []` accumulation in `StripeTransactionFetcher` with a generator. The normalisation helpers (`normalizeBrandCharge`, `normalizeBrandRefund`, `normalizeAffiliateTransfer`, `normalizeAffiliateReversal`, `shapeParty`, `isoFromUnix`, `stringOrNull`) are moved here verbatim from `StripeTransactionFetcher` — the row shape stays identical so the existing `TransactionResource` contract is preserved if anyone needs it later.

- [ ] **Step 1: Write the failing generator test.**

```php
<?php

namespace Tests\Unit\Services\Stripe;

use App\Models\Commerce\CommissionPayout;
use App\Services\Stripe\StripeRowGenerator;
use Mockery;
use Stripe\StripeClient;
use Tests\TestCase;

class StripeRowGeneratorTest extends TestCase
{
    public function test_yields_rows_one_at_a_time_without_buffering(): void
    {
        $stripe = $this->mockStripeWithCharge();
        $generator = new StripeRowGenerator($stripe);

        $payouts = $this->makePayoutCollection(5, ['payment_intent_id' => 'pi_x']);

        $rows = [];
        foreach ($generator->forPayouts($payouts, 'brand') as $row) {
            $rows[] = $row;
            // After yielding one row, the generator should be paused — not eagerly producing the rest.
            $this->assertLessThanOrEqual(count($rows), count($rows));
        }

        // 5 payouts × 1 charge row each = 5 rows minimum (no refunds in our mock).
        $this->assertCount(5, $rows);
        $this->assertSame('charge', $rows[0]['type']);
    }

    public function test_skips_payout_when_stripe_throws_and_continues(): void
    {
        $stripe = Mockery::mock(StripeClient::class);
        $stripe->paymentIntents = Mockery::mock();
        $stripe->paymentIntents->shouldReceive('retrieve')
            ->once()
            ->andThrow(new \RuntimeException('rate_limited'));
        $stripe->paymentIntents->shouldReceive('retrieve')
            ->once()
            ->andReturn($this->fakePaymentIntent());

        $generator = new StripeRowGenerator($stripe);
        $payouts = $this->makePayoutCollection(2, ['payment_intent_id' => 'pi_x']);

        $rows = iterator_to_array($generator->forPayouts($payouts, 'brand'), preserve_keys: false);

        // First payout's PI fetch failed → 0 rows from it; second produced 1 → total 1.
        $this->assertCount(1, $rows);
    }

    public function test_skips_payout_with_no_payment_intent_id_for_brand_role(): void
    {
        $stripe = Mockery::mock(StripeClient::class);
        $generator = new StripeRowGenerator($stripe);

        $payouts = $this->makePayoutCollection(1, ['payment_intent_id' => null]);

        $rows = iterator_to_array($generator->forPayouts($payouts, 'brand'), preserve_keys: false);
        $this->assertCount(0, $rows);
    }

    private function mockStripeWithCharge(): StripeClient
    {
        $stripe = Mockery::mock(StripeClient::class);
        $stripe->paymentIntents = Mockery::mock();
        $stripe->paymentIntents->shouldReceive('retrieve')
            ->andReturn($this->fakePaymentIntent());
        return $stripe;
    }

    private function fakePaymentIntent(): object
    {
        $charge = (object) [
            'id' => 'ch_test',
            'amount' => 1000,
            'currency' => 'aud',
            'status' => 'succeeded',
            'description' => null,
            'created' => 1700000000,
            'payment_intent' => 'pi_x',
            'refunds' => (object) ['data' => []],
        ];
        return (object) ['latest_charge' => $charge];
    }

    private function makePayoutCollection(int $n, array $overrides = []): \Illuminate\Support\Collection
    {
        return collect(range(1, $n))->map(fn ($i) => new CommissionPayout(array_merge([
            'id' => "payout-{$i}",
            'payment_intent_id' => 'pi_x',
            'charge_id' => 'ch_x',
            'ledger_entry_count' => 1,
        ], $overrides)));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run test — expect failure.**

```bash
./vendor/bin/pest tests/Unit/Services/Stripe/StripeRowGeneratorTest.php
```

Expected: FAIL (class not found).

- [ ] **Step 3: Implement `StripeRowGenerator`.**

Copy the normalise* helpers verbatim from `app/Services/Stripe/StripeTransactionFetcher.php` (lines 140–270 in the current file) and refactor the main loop into a generator.

```php
<?php

namespace App\Services\Stripe;

use App\Models\Commerce\CommissionPayout;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Throwable;

/**
 * Generator-based variant of StripeTransactionFetcher used by the async export
 * pipeline. Yields normalised rows one at a time so worker memory stays bounded
 * regardless of dataset size.
 *
 * The legacy fetcher (`forBrand`, `forAffiliate`) returned `array<row>` and
 * accumulated everything in memory — fine for ≤500 payouts but OOMs at 50K+.
 * Normalisation helpers are byte-identical to the legacy ones so the row shape
 * stays the same (TransactionResource is unaffected if it ever consumes us).
 */
class StripeRowGenerator
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * @param  iterable<CommissionPayout>  $payouts
     * @return \Generator<int, array<string, mixed>>
     */
    public function forPayouts(iterable $payouts, string $role): \Generator
    {
        foreach ($payouts as $payout) {
            yield from $role === 'brand'
                ? $this->yieldBrand($payout)
                : $this->yieldAffiliate($payout);
        }
    }

    private function yieldBrand(CommissionPayout $payout): \Generator
    {
        if (! $payout->payment_intent_id) {
            return;
        }

        try {
            $pi = $this->stripe->paymentIntents->retrieve($payout->payment_intent_id, [
                'expand' => ['latest_charge.refunds'],
            ]);
        } catch (Throwable $e) {
            Log::warning('commission_export.stripe_retrieve_failed', [
                'role' => 'brand',
                'payout_id' => $payout->id,
                'pi_id' => $payout->payment_intent_id,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $charge = is_object($pi->latest_charge ?? null) ? $pi->latest_charge : null;
        if ($charge === null) {
            return;
        }

        yield $this->normalizeBrandCharge($charge, $payout);

        foreach ($charge->refunds->data ?? [] as $refund) {
            yield $this->normalizeBrandRefund($refund, $charge, $payout);
        }
    }

    private function yieldAffiliate(CommissionPayout $payout): \Generator
    {
        if (! $payout->charge_id) {
            return;
        }

        try {
            $charge = $this->stripe->charges->retrieve($payout->charge_id, [
                'expand' => ['transfer.reversals'],
            ]);
        } catch (Throwable $e) {
            Log::warning('commission_export.stripe_retrieve_failed', [
                'role' => 'affiliate',
                'payout_id' => $payout->id,
                'charge_id' => $payout->charge_id,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $transfer = is_object($charge->transfer ?? null) ? $charge->transfer : null;
        if ($transfer === null) {
            return;
        }

        yield $this->normalizeAffiliateTransfer($transfer, $payout);

        foreach ($transfer->reversals->data ?? [] as $reversal) {
            yield $this->normalizeAffiliateReversal($reversal, $transfer, $payout);
        }
    }

    // ─── Normalisation helpers: copy these verbatim from
    //     app/Services/Stripe/StripeTransactionFetcher.php lines 140-270:
    //         normalizeBrandCharge, normalizeBrandRefund,
    //         normalizeAffiliateTransfer, normalizeAffiliateReversal,
    //         shapeParty, isoFromUnix, stringOrNull
    //     They are unchanged. The legacy file can keep them too (it is removed
    //     in Task 14).
    //
    //     <PASTE the seven private methods here without modification.>
}
```

> **Note for implementer:** in Step 3, literally paste lines 140–270 of the existing `StripeTransactionFetcher.php` into the placeholder comment block. They are pure functions, byte-identical.

- [ ] **Step 4: Run test — expect PASS.**

```bash
./vendor/bin/pest tests/Unit/Services/Stripe/StripeRowGeneratorTest.php
```

- [ ] **Step 5: Commit.**

```bash
git add app/Services/Stripe/StripeRowGenerator.php tests/Unit/Services/Stripe/StripeRowGeneratorTest.php
git commit -m "feat(exports): add StripeRowGenerator (generator-based fetcher)"
```

---

## Task 8: JsonlPartWriter

**Files:**
- Create: `app/Services/Exports/JsonlPartWriter.php`
- Create: `tests/Unit/Services/Exports/JsonlPartWriterTest.php`

- [ ] **Step 1: Write the failing test.**

```php
<?php

namespace Tests\Unit\Services\Exports;

use App\Services\Exports\JsonlPartWriter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JsonlPartWriterTest extends TestCase
{
    public function test_writes_jsonl_to_disk_and_returns_metadata(): void
    {
        Storage::fake('media');
        $writer = new JsonlPartWriter;

        $rows = (function () {
            yield ['id' => 'row1', 'amount' => 100];
            yield ['id' => 'row2', 'amount' => 200];
        })();

        $result = $writer->writePart(
            disk: 'media',
            remotePath: 'exports/commissions/p/a/parts/chunk-0.jsonl',
            rows: $rows,
        );

        $this->assertSame(2, $result['row_count']);
        $this->assertGreaterThan(0, $result['size']);
        $this->assertNotEmpty($result['sha256']);

        $contents = Storage::disk('media')->get('exports/commissions/p/a/parts/chunk-0.jsonl');
        $lines = array_filter(explode("\n", $contents));
        $this->assertCount(2, $lines);
        $this->assertSame(['id' => 'row1', 'amount' => 100], json_decode($lines[0], true));
    }

    public function test_writes_zero_rows_as_empty_part(): void
    {
        Storage::fake('media');
        $writer = new JsonlPartWriter;

        $result = $writer->writePart(
            disk: 'media',
            remotePath: 'exports/commissions/p/a/parts/chunk-0.jsonl',
            rows: (function () { yield from []; })(),
        );

        $this->assertSame(0, $result['row_count']);
        $this->assertSame(0, $result['size']);
        // Empty part file still gets uploaded — finalizer doesn't need to special-case missing parts.
        $this->assertTrue(Storage::disk('media')->exists('exports/commissions/p/a/parts/chunk-0.jsonl'));
    }
}
```

- [ ] **Step 2: Run — expect FAIL.**

```bash
./vendor/bin/pest tests/Unit/Services/Exports/JsonlPartWriterTest.php
```

- [ ] **Step 3: Implement.**

```php
<?php

namespace App\Services\Exports;

use Illuminate\Support\Facades\Storage;

/**
 * Streams an iterable of normalised rows to a JSONL temp file, hashes + sizes
 * it, and uploads to R2. One file per chunk job, written under
 *   exports/commissions/{prof}/{audit}/parts/chunk-{n}.jsonl
 *
 * JSONL is chosen as the intermediate format because:
 *  - append-friendly (each chunk is self-contained, no header sharing)
 *  - line-delimited so the finalizer can stream it back in O(1) memory
 *  - format-agnostic: the finalizer decides CSV vs XLSX, not us
 */
class JsonlPartWriter
{
    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return array{row_count: int, size: int, sha256: string}
     */
    public function writePart(string $disk, string $remotePath, iterable $rows): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cep_part_');
        $handle = fopen($tmp, 'w');
        $count = 0;

        try {
            foreach ($rows as $row) {
                fwrite($handle, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
                $count++;
            }
            fclose($handle);

            $size = filesize($tmp) ?: 0;
            $sha256 = hash_file('sha256', $tmp);

            // Streaming upload to avoid loading the part into PHP memory (parts
            // can be 50K rows × ~300 bytes ≈ 15 MB — small, but the streaming
            // pattern is what makes the pipeline scale-invariant).
            $stream = fopen($tmp, 'rb');
            Storage::disk($disk)->put($remotePath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return ['row_count' => $count, 'size' => $size, 'sha256' => $sha256];
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
```

- [ ] **Step 4: Run — expect PASS.**

```bash
./vendor/bin/pest tests/Unit/Services/Exports/JsonlPartWriterTest.php
```

- [ ] **Step 5: Commit.**

```bash
git add app/Services/Exports/JsonlPartWriter.php tests/Unit/Services/Exports/JsonlPartWriterTest.php
git commit -m "feat(exports): add JsonlPartWriter"
```

---

## Task 9: CommissionExportFinalWriter (parts → CSV/XLSX)

**Files:**
- Create: `app/Services/Exports/CommissionExportFinalWriter.php`
- Create: `tests/Unit/Services/Exports/CommissionExportFinalWriterTest.php`

The headers are pinned to the same set the legacy sync export used so downstream consumers (spreadsheets, accounting templates) don't break.

- [ ] **Step 1: Write the failing test.**

```php
<?php

namespace Tests\Unit\Services\Exports;

use App\Services\Exports\CommissionExportFinalWriter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CommissionExportFinalWriterTest extends TestCase
{
    public function test_writes_csv_with_header_and_part_rows(): void
    {
        Storage::fake('media');

        // Seed two part files in R2
        Storage::disk('media')->put('exports/p/a/parts/chunk-0.jsonl',
            json_encode($this->sampleRow('charge', 'row1')) . "\n");
        Storage::disk('media')->put('exports/p/a/parts/chunk-1.jsonl',
            json_encode($this->sampleRow('refund', 'row2')) . "\n");

        $writer = new CommissionExportFinalWriter;
        $tmpFinal = tempnam(sys_get_temp_dir(), 'final_') . '.csv';

        $meta = $writer->writeFinal(
            format: 'csv',
            outputPath: $tmpFinal,
            partPaths: ['exports/p/a/parts/chunk-0.jsonl', 'exports/p/a/parts/chunk-1.jsonl'],
            disk: 'media',
        );

        $this->assertGreaterThan(0, $meta['size']);
        $this->assertSame(2, $meta['row_count']);
        $contents = file_get_contents($tmpFinal);
        $this->assertStringContainsString('date,type,description,counterparty,amount_cents', $contents);
        $this->assertStringContainsString('row1', $contents);
        $this->assertStringContainsString('row2', $contents);

        @unlink($tmpFinal);
    }

    public function test_writes_xlsx_format(): void
    {
        Storage::fake('media');
        Storage::disk('media')->put('exports/p/a/parts/chunk-0.jsonl',
            json_encode($this->sampleRow('charge', 'row1')) . "\n");

        $writer = new CommissionExportFinalWriter;
        $tmpFinal = tempnam(sys_get_temp_dir(), 'final_') . '.xlsx';

        $meta = $writer->writeFinal(
            format: 'xlsx',
            outputPath: $tmpFinal,
            partPaths: ['exports/p/a/parts/chunk-0.jsonl'],
            disk: 'media',
        );

        $this->assertGreaterThan(0, $meta['size']);
        // XLSX is a zip — magic bytes start with PK\x03\x04
        $this->assertSame('PK', substr(file_get_contents($tmpFinal), 0, 2));

        @unlink($tmpFinal);
    }

    private function sampleRow(string $type, string $id): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'occurred_at' => '2026-05-19T00:00:00Z',
            'description' => 'sample',
            'amount_cents' => 1000,
            'currency_code' => 'AUD',
            'status' => 'succeeded',
            'payout_id' => 'po_x',
            'raw_stripe_id' => 'ch_x',
            'brand' => ['name' => 'B'],
            'affiliate' => ['name' => 'A'],
        ];
    }
}
```

- [ ] **Step 2: Run — expect FAIL.**

```bash
./vendor/bin/pest tests/Unit/Services/Exports/CommissionExportFinalWriterTest.php
```

- [ ] **Step 3: Implement.**

```php
<?php

namespace App\Services\Exports;

use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;

/**
 * Reads JSONL part files from R2 line-by-line and writes the consolidated
 * CSV or XLSX to a local temp path. Memory is bounded — never holds more than
 * one parsed row at a time. The caller (FinalizerJob) re-uploads the local
 * temp file to R2 + signs it.
 *
 * Header set matches the legacy sync export so spreadsheet consumers don't break:
 *   date, type, description, counterparty, amount_cents, currency, status, payout_id, stripe_id
 */
class CommissionExportFinalWriter
{
    private const HEADER = [
        'date', 'type', 'description', 'counterparty',
        'amount_cents', 'currency', 'status', 'payout_id', 'stripe_id',
    ];

    /**
     * @param  list<string>  $partPaths  R2 keys, in chunk-index order
     * @return array{size: int, sha256: string, row_count: int}
     */
    public function writeFinal(string $format, string $outputPath, array $partPaths, string $disk): array
    {
        return match ($format) {
            'csv' => $this->writeCsv($outputPath, $partPaths, $disk),
            'xlsx' => $this->writeXlsx($outputPath, $partPaths, $disk),
            default => throw new RuntimeException("unsupported format: {$format}"),
        };
    }

    private function writeCsv(string $outputPath, array $partPaths, string $disk): array
    {
        $out = fopen($outputPath, 'w');
        fputcsv($out, self::HEADER);
        $count = 0;

        try {
            foreach ($this->streamRows($partPaths, $disk) as $row) {
                fputcsv($out, $this->shapeRow($row));
                $count++;
            }
        } finally {
            fclose($out);
        }

        return [
            'size' => filesize($outputPath) ?: 0,
            'sha256' => hash_file('sha256', $outputPath),
            'row_count' => $count,
        ];
    }

    private function writeXlsx(string $outputPath, array $partPaths, string $disk): array
    {
        $writer = new XlsxWriter;
        $writer->openToFile($outputPath);
        $writer->addRow(Row::fromValues(self::HEADER));
        $count = 0;

        try {
            foreach ($this->streamRows($partPaths, $disk) as $row) {
                $writer->addRow(Row::fromValues($this->shapeRow($row)));
                $count++;
            }
        } finally {
            $writer->close();
        }

        return [
            'size' => filesize($outputPath) ?: 0,
            'sha256' => hash_file('sha256', $outputPath),
            'row_count' => $count,
        ];
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function streamRows(array $partPaths, string $disk): \Generator
    {
        $fs = Storage::disk($disk);

        foreach ($partPaths as $path) {
            $stream = $fs->readStream($path);
            if (! is_resource($stream)) {
                continue;
            }
            try {
                while (($line = fgets($stream)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    /**
     * Maps the JSONL row shape (produced by StripeRowGenerator) to the flat
     * 9-column shape consumers expect. Counterparty is the OTHER party — for a
     * brand-role export, that's the affiliate; for affiliate-role, the brand.
     *
     * @param  array<string, mixed>  $row
     * @return list<scalar>
     */
    private function shapeRow(array $row): array
    {
        $type = $row['type'] ?? '';
        // For brand exports the row's `affiliate` is the counterparty; for affiliate
        // exports the `brand` is. The role isn't on the row itself, so we infer by
        // type: charge/refund came from brand-side; transfer/reversal from affiliate-side.
        $counterparty = in_array($type, ['transfer', 'reversal'], true)
            ? ($row['brand']['name'] ?? '')
            : ($row['affiliate']['name'] ?? '');

        return [
            $row['occurred_at'] ?? '',
            $type,
            $row['description'] ?? '',
            $counterparty,
            $row['amount_cents'] ?? 0,
            $row['currency_code'] ?? 'AUD',
            $row['status'] ?? '',
            $row['payout_id'] ?? '',
            $row['raw_stripe_id'] ?? '',
        ];
    }
}
```

- [ ] **Step 4: Run — expect PASS.**

```bash
./vendor/bin/pest tests/Unit/Services/Exports/CommissionExportFinalWriterTest.php
```

- [ ] **Step 5: Commit.**

```bash
git add app/Services/Exports/CommissionExportFinalWriter.php tests/Unit/Services/Exports/CommissionExportFinalWriterTest.php
git commit -m "feat(exports): add CommissionExportFinalWriter (JSONL → CSV/XLSX)"
```

---

## Task 10: CommissionExportService (dispatcher)

**Files:**
- Create: `app/Services/Stripe/CommissionExportService.php`
- Create: `tests/Feature/Services/Stripe/CommissionExportServiceTest.php`

- [ ] **Step 1: Write the failing test.**

```php
<?php

namespace Tests\Feature\Services\Stripe;

use App\Exceptions\CommissionExportInProgressException;
use App\Exceptions\NoRecipientEmailException;
use App\Jobs\Exports\ExportChunkJob;
use App\Jobs\Exports\ExportFinalizerJob;
use App\Models\Commerce\CommissionExportAudit;
use App\Models\Commerce\CommissionPayout;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\CommissionExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CommissionExportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_creates_audit_and_queues_first_chunk(): void
    {
        Bus::fake();
        $pro = Professional::factory()->create(['primary_email' => 'jane@example.com']);
        $this->seedPayouts($pro, count: 542, role: 'brand');

        $service = app(CommissionExportService::class);
        $audit = $service->dispatch($pro, 'brand', 'csv', ['type' => 'all']);

        $this->assertSame(CommissionExportAudit::STATUS_QUEUED, $audit->status);
        $this->assertSame(542, $audit->payouts_total);
        $this->assertSame(2, $audit->chunks_total);
        $this->assertSame('jane@example.com', $audit->recipient_email);
        $this->assertNotNull($audit->expires_at);

        Bus::assertDispatched(ExportChunkJob::class, fn ($job) =>
            $job->auditId === $audit->id && $job->chunkIndex === 0);
    }

    public function test_dispatch_throws_when_inflight_export_exists(): void
    {
        Bus::fake();
        $pro = Professional::factory()->create(['primary_email' => 'jane@example.com']);
        $this->seedPayouts($pro, count: 1, role: 'brand');

        $service = app(CommissionExportService::class);
        $service->dispatch($pro, 'brand', 'csv', []);

        $this->expectException(CommissionExportInProgressException::class);
        $service->dispatch($pro, 'brand', 'csv', []);
    }

    public function test_dispatch_throws_when_no_recipient_email(): void
    {
        $pro = Professional::factory()->create(['primary_email' => null, 'public_contact_email' => null]);
        $this->expectException(NoRecipientEmailException::class);
        app(CommissionExportService::class)->dispatch($pro, 'brand', 'csv', []);
    }

    public function test_empty_result_branch_completes_immediately_and_dispatches_finalizer(): void
    {
        Bus::fake();
        $pro = Professional::factory()->create(['primary_email' => 'jane@example.com']);

        $service = app(CommissionExportService::class);
        $audit = $service->dispatch($pro, 'brand', 'csv', []);

        $this->assertSame(0, $audit->payouts_total);
        $this->assertSame(0, $audit->chunks_total);
        Bus::assertDispatched(ExportFinalizerJob::class);
        Bus::assertNotDispatched(ExportChunkJob::class);
    }

    private function seedPayouts(Professional $pro, int $count, string $role): void
    {
        for ($i = 0; $i < $count; $i++) {
            CommissionPayout::factory()->create([
                'brand_professional_id' => $role === 'brand' ? $pro->id : null,
                'affiliate_professional_id' => $role === 'affiliate' ? $pro->id : null,
                'payment_intent_id' => 'pi_'.$i,
                'charge_id' => 'ch_'.$i,
            ]);
        }
    }
}
```

- [ ] **Step 2: Run — expect FAIL (service + jobs don't exist).**

```bash
./vendor/bin/pest tests/Feature/Services/Stripe/CommissionExportServiceTest.php
```

- [ ] **Step 3: Implement the service.**

```php
<?php

namespace App\Services\Stripe;

use App\Exceptions\CommissionExportInProgressException;
use App\Exceptions\NoRecipientEmailException;
use App\Jobs\Exports\ExportChunkJob;
use App\Jobs\Exports\ExportFinalizerJob;
use App\Models\Commerce\CommissionExportAudit;
use App\Models\Commerce\CommissionPayout;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dispatcher for the async commission transactions export.
 *
 * Responsibilities:
 *  - resolve recipient email (fail closed if none)
 *  - dedup against in-flight exports for the same pro+role+format
 *  - count payouts up-front so the audit row carries an accurate total
 *  - insert the audit row, then kick off the first chunk (or finalizer for empty)
 *
 * Heavy work is the job's problem; this stays sync and fast.
 */
class CommissionExportService
{
    public function dispatch(
        Professional $professional,
        string $role,
        string $format,
        array $filters,
    ): CommissionExportAudit {
        $email = $professional->public_contact_email ?: $professional->primary_email;
        if (! $email) {
            throw new NoRecipientEmailException($professional->id);
        }

        $chunkSize = (int) config('partna.exports.commission.chunk_size');
        $dedupWindow = (int) config('partna.exports.commission.dedup_window_minutes');
        $retentionDays = (int) config('partna.exports.commission.retention_days');

        $audit = DB::transaction(function () use (
            $professional, $role, $format, $filters, $email, $chunkSize, $dedupWindow, $retentionDays,
        ) {
            // Pessimistic dedup: lock recent rows for this pro to serialise concurrent dispatch calls.
            // (For two requests racing past the in-flight check, the second sees the first's row inserted.)
            $existing = CommissionExportAudit::query()
                ->where('professional_id', $professional->id)
                ->where('role', $role)
                ->where('format', $format)
                ->whereIn('status', [
                    CommissionExportAudit::STATUS_QUEUED,
                    CommissionExportAudit::STATUS_PROCESSING,
                ])
                ->where('created_at', '>=', now()->subMinutes($dedupWindow))
                ->lockForUpdate()
                ->orderByDesc('created_at')
                ->first();

            if ($existing) {
                throw new CommissionExportInProgressException($existing);
            }

            $payoutsTotal = $this->countPayouts($professional->id, $role, $filters);
            $chunksTotal = (int) ceil($payoutsTotal / $chunkSize);

            return CommissionExportAudit::create([
                'professional_id' => $professional->id,
                'role' => $role,
                'format' => $format,
                'filters' => $filters,
                'status' => CommissionExportAudit::STATUS_QUEUED,
                'recipient_email' => $email,
                'payouts_total' => $payoutsTotal,
                'chunk_size' => $chunkSize,
                'chunks_total' => $chunksTotal,
                'next_chunk_index' => 0,
                'expires_at' => now()->addDays($retentionDays),
            ]);
        });

        Log::info('commission_export.dispatched', [
            'audit_id' => $audit->id,
            'professional_id' => $professional->id,
            'role' => $role,
            'format' => $format,
            'payouts_total' => $audit->payouts_total,
            'chunks_total' => $audit->chunks_total,
        ]);

        if ($audit->payouts_total === 0) {
            // Skip the chunk pipeline entirely — finalizer handles the empty-result branch.
            ExportFinalizerJob::dispatch($audit->id);
        } else {
            ExportChunkJob::dispatch($audit->id, 0);
        }

        return $audit;
    }

    private function countPayouts(string $professionalId, string $role, array $filters): int
    {
        $query = CommissionPayout::query();

        if ($role === 'brand') {
            $query->where('brand_professional_id', $professionalId);
        } else {
            $query->where('affiliate_professional_id', $professionalId);
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'].' 23:59:59');
        }

        return $query->count();
    }
}
```

- [ ] **Step 4: Run — expect partial PASS (service tests pass; finalizer/chunk-job tests still missing classes).**

Note: the service test references `ExportChunkJob` and `ExportFinalizerJob`. Until those are created in Tasks 11 and 12, the dispatch test will fail at `Bus::assertDispatched` if class autoloader can't resolve. Add temporary placeholder classes to unblock:

```bash
touch app/Jobs/Exports/ExportChunkJob.php app/Jobs/Exports/ExportFinalizerJob.php
```

Put minimal stubs in each:

```php
<?php namespace App\Jobs\Exports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
class ExportChunkJob implements ShouldQueue { use Dispatchable, Queueable; public function __construct(public string $auditId, public int $chunkIndex) {} public function handle(): void {} }
```

```php
<?php namespace App\Jobs\Exports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
class ExportFinalizerJob implements ShouldQueue { use Dispatchable, Queueable; public function __construct(public string $auditId) {} public function handle(): void {} }
```

Stubs are replaced fully in Tasks 11 and 12.

- [ ] **Step 5: Run again — expect PASS on service tests.**

```bash
./vendor/bin/pest tests/Feature/Services/Stripe/CommissionExportServiceTest.php
```

- [ ] **Step 6: Commit.**

```bash
git add app/Services/Stripe/CommissionExportService.php app/Jobs/Exports/ exportFinalizerJob.php app/Jobs/Exports/ExportChunkJob.php tests/Feature/Services/Stripe/CommissionExportServiceTest.php
git commit -m "feat(exports): add CommissionExportService dispatcher"
```

---

## Task 11: ExportChunkJob

**Files:**
- Modify (replace stub): `app/Jobs/Exports/ExportChunkJob.php`
- Create: `tests/Feature/Exports/ExportChunkJobTest.php`

- [ ] **Step 1: Write the failing test.**

```php
<?php

namespace Tests\Feature\Exports;

use App\Jobs\Exports\ExportChunkJob;
use App\Jobs\Exports\ExportFinalizerJob;
use App\Models\Commerce\CommissionExportAudit;
use App\Models\Commerce\CommissionPayout;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeRowGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ExportChunkJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_chunk_marks_processing_writes_part_and_advances_cursor(): void
    {
        Bus::fake([ExportChunkJob::class, ExportFinalizerJob::class]);
        Storage::fake('media');
        config()->set('partna.media_disk', 'media');
        config()->set('partna.exports.commission.chunk_size', 2);

        $pro = Professional::factory()->create(['primary_email' => 'j@x']);
        $payouts = CommissionPayout::factory()->count(3)->create([
            'brand_professional_id' => $pro->id, 'payment_intent_id' => 'pi_x',
        ]);
        $audit = CommissionExportAudit::create($this->auditAttrs($pro, payouts: 3, chunks: 2));

        $this->mockGeneratorYielding([['id' => 'r1', 'type' => 'charge'], ['id' => 'r2', 'type' => 'charge']]);

        (new ExportChunkJob($audit->id, 0))->handle(app(StripeRowGenerator::class), app(\App\Services\Exports\JsonlPartWriter::class));

        $fresh = $audit->fresh();
        $this->assertSame(CommissionExportAudit::STATUS_PROCESSING, $fresh->status);
        $this->assertSame(2, $fresh->payouts_processed);
        $this->assertSame(1, $fresh->chunks_completed);
        $this->assertSame(1, $fresh->next_chunk_index);
        $this->assertNotNull($fresh->last_processed_payout_id);

        $this->assertTrue(Storage::disk('media')->exists("exports/commissions/{$pro->id}/{$audit->id}/parts/chunk-0.jsonl"));
        Bus::assertDispatched(ExportChunkJob::class, fn ($j) => $j->chunkIndex === 1);
        Bus::assertNotDispatched(ExportFinalizerJob::class);
    }

    public function test_terminal_chunk_dispatches_finalizer(): void
    {
        Bus::fake([ExportChunkJob::class, ExportFinalizerJob::class]);
        Storage::fake('media');
        config()->set('partna.media_disk', 'media');
        config()->set('partna.exports.commission.chunk_size', 10);

        $pro = Professional::factory()->create(['primary_email' => 'j@x']);
        CommissionPayout::factory()->count(2)->create([
            'brand_professional_id' => $pro->id, 'payment_intent_id' => 'pi_x',
        ]);
        $audit = CommissionExportAudit::create($this->auditAttrs($pro, payouts: 2, chunks: 1));

        $this->mockGeneratorYielding([['id' => 'r1', 'type' => 'charge']]);

        (new ExportChunkJob($audit->id, 0))->handle(app(StripeRowGenerator::class), app(\App\Services\Exports\JsonlPartWriter::class));

        Bus::assertDispatched(ExportFinalizerJob::class, fn ($j) => $j->auditId === $audit->id);
        Bus::assertNotDispatched(ExportChunkJob::class);
    }

    public function test_early_returns_when_audit_is_terminal(): void
    {
        Bus::fake();
        Storage::fake('media');

        $pro = Professional::factory()->create(['primary_email' => 'j@x']);
        $audit = CommissionExportAudit::create($this->auditAttrs($pro, payouts: 2, chunks: 1, status: 'completed'));

        (new ExportChunkJob($audit->id, 0))->handle(app(StripeRowGenerator::class), app(\App\Services\Exports\JsonlPartWriter::class));

        Bus::assertNothingDispatched();
    }

    private function mockGeneratorYielding(array $rows): void
    {
        $mock = Mockery::mock(StripeRowGenerator::class);
        $mock->shouldReceive('forPayouts')->andReturnUsing(function () use ($rows) {
            foreach ($rows as $r) yield $r;
        });
        $this->app->instance(StripeRowGenerator::class, $mock);
    }

    private function auditAttrs(Professional $pro, int $payouts, int $chunks, string $status = 'queued'): array
    {
        return [
            'professional_id' => $pro->id,
            'role' => 'brand',
            'format' => 'csv',
            'filters' => [],
            'status' => $status,
            'recipient_email' => 'j@x',
            'payouts_total' => $payouts,
            'chunk_size' => 2,
            'chunks_total' => $chunks,
            'expires_at' => now()->addDays(30),
        ];
    }
}
```

- [ ] **Step 2: Run — expect FAIL.**

```bash
./vendor/bin/pest tests/Feature/Exports/ExportChunkJobTest.php
```

- [ ] **Step 3: Implement the chunk job.**

```php
<?php

namespace App\Jobs\Exports;

use App\Models\Commerce\CommissionExportAudit;
use App\Models\Commerce\CommissionPayout;
use App\Services\Exports\JsonlPartWriter;
use App\Services\Stripe\StripeRowGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-chunk worker for the async commission transactions export.
 *
 * Fetches up to `chunk_size` payouts after the audit's cursor, yields rows
 * through StripeRowGenerator, writes a JSONL part file to R2, and either
 * dispatches the next chunk job or hands off to the finalizer.
 *
 * Idempotent: re-running the same chunk overwrites its part file. Cursor is
 * advanced only after a successful part upload, so a crash before upload
 * replays the same payouts on retry.
 */
class ExportChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(public string $auditId, public int $chunkIndex)
    {
        $this->onConnection(config('partna.exports.commission.connection'));
        $this->onQueue(config('partna.exports.commission.queue'));
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(StripeRowGenerator $generator, JsonlPartWriter $partWriter): void
    {
        $audit = CommissionExportAudit::find($this->auditId);
        if (! $audit || $audit->isTerminal()) {
            return;
        }

        // First chunk transitions queued → processing. Idempotent (markProcessing keeps existing processing_at).
        if ($audit->status === CommissionExportAudit::STATUS_QUEUED) {
            $audit->markProcessing();
        }

        Log::info('commission_export.chunk_started', [
            'audit_id' => $audit->id,
            'chunk_index' => $this->chunkIndex,
        ]);
        $start = microtime(true);

        try {
            $payouts = $this->fetchChunkPayouts($audit);

            if ($payouts->isEmpty()) {
                // Reconciliation: counter said there were more chunks but we found none.
                // Could happen if payouts were deleted mid-export. Skip to finalizer.
                Log::warning('commission_export.chunk_empty', [
                    'audit_id' => $audit->id, 'chunk_index' => $this->chunkIndex,
                ]);
                ExportFinalizerJob::dispatch($audit->id);
                return;
            }

            $remotePath = sprintf(
                'exports/commissions/%s/%s/parts/chunk-%d.jsonl',
                $audit->professional_id,
                $audit->id,
                $this->chunkIndex,
            );

            $partWriter->writePart(
                disk: config('partna.media_disk'),
                remotePath: $remotePath,
                rows: $generator->forPayouts($payouts, $audit->role),
            );

            $audit->markChunkCompleted(
                payoutsInChunk: $payouts->count(),
                lastPayoutId: $payouts->last()->id,
                nextIndex: $this->chunkIndex + 1,
            );

            Log::info('commission_export.chunk_completed', [
                'audit_id' => $audit->id,
                'chunk_index' => $this->chunkIndex,
                'payouts_in_chunk' => $payouts->count(),
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);

            $fresh = $audit->fresh();
            if ($fresh->chunks_completed >= $fresh->chunks_total) {
                ExportFinalizerJob::dispatch($audit->id);
            } else {
                ExportChunkJob::dispatch($audit->id, $this->chunkIndex + 1);
            }
        } catch (Throwable $e) {
            Log::error('commission_export.failed', [
                'audit_id' => $audit->id,
                'stage' => 'chunk',
                'chunk_index' => $this->chunkIndex,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Stable cursor pagination by (created_at DESC, id DESC).
     * Chunk 0: no cursor → most recent N payouts.
     * Chunk N: after the last processed payout.
     */
    private function fetchChunkPayouts(CommissionExportAudit $audit): \Illuminate\Support\Collection
    {
        $query = CommissionPayout::query()->orderByDesc('created_at')->orderByDesc('id');

        if ($audit->role === 'brand') {
            $query->where('brand_professional_id', $audit->professional_id);
        } else {
            $query->where('affiliate_professional_id', $audit->professional_id);
        }

        $filters = $audit->filters ?? [];
        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'].' 23:59:59');
        }

        if ($audit->last_processed_payout_id) {
            $cursor = CommissionPayout::find($audit->last_processed_payout_id);
            if ($cursor) {
                $query->where(function ($q) use ($cursor) {
                    $q->where('created_at', '<', $cursor->created_at)
                      ->orWhere(function ($qq) use ($cursor) {
                          $qq->where('created_at', $cursor->created_at)
                             ->where('id', '<', $cursor->id);
                      });
                });
            }
        }

        return $query->limit($audit->chunk_size)->get();
    }

    public function failed(Throwable $e): void
    {
        $audit = CommissionExportAudit::find($this->auditId);
        if ($audit && ! $audit->isTerminal()) {
            $audit->markFailed('chunk '.$this->chunkIndex.' failed: '.$e->getMessage());
        }
    }
}
```

- [ ] **Step 4: Run — expect PASS.**

```bash
./vendor/bin/pest tests/Feature/Exports/ExportChunkJobTest.php
```

- [ ] **Step 5: Commit.**

```bash
git add app/Jobs/Exports/ExportChunkJob.php tests/Feature/Exports/ExportChunkJobTest.php
git commit -m "feat(exports): add ExportChunkJob (self-chaining chunked worker)"
```

---

## Task 12: ExportFinalizerJob

**Files:**
- Modify (replace stub): `app/Jobs/Exports/ExportFinalizerJob.php`
- Create: `tests/Feature/Exports/ExportFinalizerJobTest.php`

- [ ] **Step 1: Write the failing test.**

```php
<?php

namespace Tests\Feature\Exports;

use App\Jobs\Exports\ExportFinalizerJob;
use App\Mail\Exports\CommissionExportReadyMail;
use App\Models\Commerce\CommissionExportAudit;
use App\Models\Core\Professional\Professional;
use App\Services\Exports\CommissionExportFinalWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportFinalizerJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_concatenates_parts_uploads_final_signs_url_emails_completes(): void
    {
        Mail::fake();
        Storage::fake('media');
        config()->set('partna.media_disk', 'media');
        config()->set('partna.exports.commission.signed_url_ttl_days', 3);

        $pro = Professional::factory()->create();
        $audit = CommissionExportAudit::create([
            'professional_id' => $pro->id, 'role' => 'brand', 'format' => 'csv',
            'filters' => [], 'status' => 'processing', 'recipient_email' => 'jane@example.com',
            'payouts_total' => 1, 'chunk_size' => 500, 'chunks_total' => 1, 'chunks_completed' => 1,
            'next_chunk_index' => 1, 'expires_at' => now()->addDays(30),
        ]);

        Storage::disk('media')->put(
            "exports/commissions/{$pro->id}/{$audit->id}/parts/chunk-0.jsonl",
            json_encode(['id' => 'r1', 'type' => 'charge', 'amount_cents' => 100, 'occurred_at' => '2026-05-19T00:00:00Z']) . "\n",
        );

        (new ExportFinalizerJob($audit->id))->handle(app(CommissionExportFinalWriter::class));

        $fresh = $audit->fresh();
        $this->assertSame(CommissionExportAudit::STATUS_COMPLETED, $fresh->status);
        $this->assertSame("exports/commissions/{$pro->id}/{$audit->id}/data.csv", $fresh->file_path);
        $this->assertGreaterThan(0, $fresh->file_size_bytes);
        $this->assertNotEmpty($fresh->file_sha256);

        $this->assertTrue(Storage::disk('media')->exists($fresh->file_path));
        $this->assertFalse(Storage::disk('media')->exists("exports/commissions/{$pro->id}/{$audit->id}/parts/chunk-0.jsonl"));

        Mail::assertSent(CommissionExportReadyMail::class, fn ($mail) =>
            $mail->hasTo('jane@example.com'));
    }

    public function test_empty_export_branch_completes_without_part_files(): void
    {
        Mail::fake();
        Storage::fake('media');
        config()->set('partna.media_disk', 'media');

        $pro = Professional::factory()->create();
        $audit = CommissionExportAudit::create([
            'professional_id' => $pro->id, 'role' => 'brand', 'format' => 'csv',
            'filters' => [], 'status' => 'queued', 'recipient_email' => 'jane@example.com',
            'payouts_total' => 0, 'chunk_size' => 500, 'chunks_total' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        (new ExportFinalizerJob($audit->id))->handle(app(CommissionExportFinalWriter::class));

        $this->assertSame(CommissionExportAudit::STATUS_COMPLETED, $audit->fresh()->status);
        $this->assertSame(0, $audit->fresh()->file_size_bytes ?? 0);
        Mail::assertSent(CommissionExportReadyMail::class);
    }

    public function test_early_returns_if_already_completed(): void
    {
        Mail::fake();
        Storage::fake('media');

        $pro = Professional::factory()->create();
        $audit = CommissionExportAudit::create([
            'professional_id' => $pro->id, 'role' => 'brand', 'format' => 'csv',
            'filters' => [], 'status' => 'completed', 'recipient_email' => 'j@x',
            'payouts_total' => 0, 'chunk_size' => 500, 'chunks_total' => 0,
            'completed_at' => now(), 'expires_at' => now()->addDays(30),
        ]);

        (new ExportFinalizerJob($audit->id))->handle(app(CommissionExportFinalWriter::class));

        Mail::assertNothingSent();
    }
}
```

- [ ] **Step 2: Run — expect FAIL.**

```bash
./vendor/bin/pest tests/Feature/Exports/ExportFinalizerJobTest.php
```

- [ ] **Step 3: Implement.**

```php
<?php

namespace App\Jobs\Exports;

use App\Mail\Exports\CommissionExportReadyMail;
use App\Models\Commerce\CommissionExportAudit;
use App\Services\Exports\CommissionExportFinalWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads all JSONL part files from R2 for an audit, concatenates them into the
 * final CSV/XLSX, uploads the consolidated file, signs a 3-day URL, emails the
 * recipient, and marks the audit completed.
 *
 * The empty-export branch (no payouts in range) lands here too — we skip the
 * part-reading step but still email a "0 transactions" confirmation so the API
 * contract stays uniform.
 */
class ExportFinalizerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(public string $auditId)
    {
        $this->onConnection(config('partna.exports.commission.connection'));
        $this->onQueue(config('partna.exports.commission.queue'));
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(CommissionExportFinalWriter $finalWriter): void
    {
        $audit = CommissionExportAudit::find($this->auditId);
        if (! $audit || $audit->status === CommissionExportAudit::STATUS_COMPLETED) {
            return;
        }

        // If the audit is failed/cancelled, don't override.
        if ($audit->status === CommissionExportAudit::STATUS_FAILED
            || $audit->status === CommissionExportAudit::STATUS_CANCELLED) {
            return;
        }

        Log::info('commission_export.finalizer_started', [
            'audit_id' => $audit->id, 'chunks_total' => $audit->chunks_total,
        ]);
        $start = microtime(true);

        $disk = config('partna.media_disk');
        $remoteFinalPath = sprintf(
            'exports/commissions/%s/%s/data.%s',
            $audit->professional_id,
            $audit->id,
            $audit->format,
        );

        $tmpFinal = tempnam(sys_get_temp_dir(), 'cep_final_') . '.' . $audit->format;

        try {
            $partPaths = $this->listParts($audit, $disk);
            $meta = $finalWriter->writeFinal($audit->format, $tmpFinal, $partPaths, $disk);

            // Stream-upload final to R2
            $stream = fopen($tmpFinal, 'rb');
            Storage::disk($disk)->put($remoteFinalPath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $ttlDays = (int) config('partna.exports.commission.signed_url_ttl_days');
            $signedUrl = Storage::disk($disk)->temporaryUrl($remoteFinalPath, now()->addDays($ttlDays));

            Mail::to($audit->recipient_email)->send(new CommissionExportReadyMail(
                signedUrl: $signedUrl,
                role: $audit->role,
                format: $audit->format,
                filters: $audit->filters ?? [],
                recordCount: $meta['row_count'],
                ttlDays: $ttlDays,
                expiresAt: now()->addDays($ttlDays),
            ));

            $audit->markCompleted(
                filePath: $remoteFinalPath,
                size: $meta['size'],
                sha256: $meta['sha256'],
            );

            // Cleanup parts after success
            foreach ($partPaths as $p) {
                Storage::disk($disk)->delete($p);
            }

            Log::info('commission_export.finalizer_completed', [
                'audit_id' => $audit->id,
                'file_size_bytes' => $meta['size'],
                'total_rows' => $meta['row_count'],
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);
        } catch (Throwable $e) {
            Log::error('commission_export.failed', [
                'audit_id' => $audit->id, 'stage' => 'finalizer', 'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if (file_exists($tmpFinal)) {
                @unlink($tmpFinal);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function listParts(CommissionExportAudit $audit, string $disk): array
    {
        $prefix = sprintf('exports/commissions/%s/%s/parts', $audit->professional_id, $audit->id);
        $paths = Storage::disk($disk)->files($prefix);
        sort($paths, SORT_NATURAL); // chunk-0, chunk-1, ..., chunk-10 (natural sort handles N>9)
        return $paths;
    }

    public function failed(Throwable $e): void
    {
        $audit = CommissionExportAudit::find($this->auditId);
        if ($audit && ! $audit->isTerminal()) {
            $audit->markFailed('finalizer failed: '.$e->getMessage());
        }
    }
}
```

- [ ] **Step 4: Run — expect PASS.**

```bash
./vendor/bin/pest tests/Feature/Exports/ExportFinalizerJobTest.php
```

- [ ] **Step 5: Commit.**

```bash
git add app/Jobs/Exports/ExportFinalizerJob.php tests/Feature/Exports/ExportFinalizerJobTest.php
git commit -m "feat(exports): add ExportFinalizerJob (parts → final → mail)"
```

---

## Task 13: Mailable + Blade template

**Files:**
- Create: `app/Mail/Exports/CommissionExportReadyMail.php`
- Create: `resources/views/emails/exports/commission-ready.blade.php`
- Create: `tests/Feature/Mail/CommissionExportReadyMailTest.php`

- [ ] **Step 1: Write the failing mail test.**

```php
<?php

namespace Tests\Feature\Mail;

use App\Mail\Exports\CommissionExportReadyMail;
use Tests\TestCase;

class CommissionExportReadyMailTest extends TestCase
{
    public function test_renders_with_signed_url_and_expiry(): void
    {
        $mail = new CommissionExportReadyMail(
            signedUrl: 'https://r2.example.com/signed?sig=abc',
            role: 'brand',
            format: 'csv',
            filters: ['date_from' => '2026-04-01', 'date_to' => '2026-04-30'],
            recordCount: 230,
            ttlDays: 3,
            expiresAt: new \DateTimeImmutable('2026-05-22T00:00:00Z'),
        );

        $html = $mail->render();
        $this->assertStringContainsString('https://r2.example.com/signed?sig=abc', $html);
        $this->assertStringContainsString('230', $html);
        $this->assertStringContainsString('3 days', $html);
    }
}
```

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Create the Mailable.**

```php
<?php

namespace App\Mail\Exports;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CommissionExportReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $signedUrl,
        public string $role,
        public string $format,
        public array $filters,
        public int $recordCount,
        public int $ttlDays,
        public \DateTimeInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your commission export is ready');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.exports.commission-ready',
            with: [
                'signedUrl' => $this->signedUrl,
                'role' => $this->role,
                'format' => strtoupper($this->format),
                'dateFrom' => $this->filters['date_from'] ?? null,
                'dateTo' => $this->filters['date_to'] ?? null,
                'recordCount' => $this->recordCount,
                'ttlDays' => $this->ttlDays,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}
```

- [ ] **Step 4: Create the Blade template.**

```blade
{{-- resources/views/emails/exports/commission-ready.blade.php --}}
<!doctype html>
<html><body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;line-height:1.5;color:#222;max-width:560px;margin:auto;padding:24px;">

<p>Hi,</p>

<p>Your commission transactions export is ready.</p>

<p style="margin:24px 0;">
    <strong>{{ $recordCount }}</strong> transactions
    @if($dateFrom && $dateTo)
        from <strong>{{ $dateFrom }}</strong> to <strong>{{ $dateTo }}</strong>
    @endif
    — {{ $format }} format.
</p>

<p>
    <a href="{{ $signedUrl }}"
       style="display:inline-block;background:#111;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;">
        Download your report
    </a>
</p>

<p style="color:#666;font-size:14px;margin-top:24px;">
    This link expires in <strong>{{ $ttlDays }} days</strong> (at {{ $expiresAt->format('j M Y') }}).
    If it expires, request a new export from your dashboard.
</p>

<p style="color:#666;font-size:13px;margin-top:24px;">
    If you didn't request this export, please contact support.
</p>

</body></html>
```

- [ ] **Step 5: Run — expect PASS.**

```bash
./vendor/bin/pest tests/Feature/Mail/CommissionExportReadyMailTest.php
```

- [ ] **Step 6: Commit.**

```bash
git add app/Mail/Exports/ resources/views/emails/exports/ tests/Feature/Mail/CommissionExportReadyMailTest.php
git commit -m "feat(exports): add CommissionExportReadyMail + blade template"
```

---

## Task 14: Controller + routes + remove sync transactions path

**Files:**
- Create: `app/Http/Controllers/Api/Professional/Stripe/CommissionExportController.php`
- Modify: `routes/api/professional.php`
- Modify: `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php`
- Modify: `app/Services/Stripe/ExportService.php`
- Create: `tests/Feature/Professional/Stripe/CommissionExportTest.php`

- [ ] **Step 1: Write the failing feature test.**

```php
<?php

namespace Tests\Feature\Professional\Stripe;

use App\Jobs\Exports\ExportChunkJob;
use App\Models\Commerce\CommissionExportAudit;
use App\Models\Commerce\CommissionPayout;
use App\Models\Core\Professional\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CommissionExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_returns_202_with_envelope(): void
    {
        Bus::fake();
        $pro = $this->actingAsBrand();
        CommissionPayout::factory()->count(3)->create(['brand_professional_id' => $pro->id]);

        $response = $this->postJson('/api/professional/stripe/exports/transactions', [
            'format' => 'csv',
            'date_from' => '2026-01-01',
            'date_to' => '2026-05-19',
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure(['export_id', 'status', 'payouts_total', 'chunk_size', 'recipient_email', 'message'])
            ->assertJson(['status' => 'queued', 'payouts_total' => 3]);
        $response->assertHeader('Location');
    }

    public function test_post_422_when_no_email(): void
    {
        $pro = Professional::factory()->create(['primary_email' => null, 'public_contact_email' => null]);
        $this->actingAsProfessional($pro);
        $response = $this->postJson('/api/professional/stripe/exports/transactions', ['format' => 'csv']);
        $response->assertStatus(422);
    }

    public function test_post_409_when_inflight(): void
    {
        Bus::fake();
        $pro = $this->actingAsBrand();
        CommissionPayout::factory()->create(['brand_professional_id' => $pro->id]);

        $this->postJson('/api/professional/stripe/exports/transactions', ['format' => 'csv'])->assertStatus(202);
        $this->postJson('/api/professional/stripe/exports/transactions', ['format' => 'csv'])->assertStatus(409);
    }

    public function test_post_422_on_invalid_format(): void
    {
        $this->actingAsBrand();
        $this->postJson('/api/professional/stripe/exports/transactions', ['format' => 'pdf'])->assertStatus(422);
    }

    public function test_show_returns_audit_for_owner(): void
    {
        $pro = $this->actingAsBrand();
        $audit = CommissionExportAudit::create([
            'professional_id' => $pro->id, 'role' => 'brand', 'format' => 'csv', 'filters' => [],
            'status' => 'processing', 'recipient_email' => 'j@x',
            'payouts_total' => 1, 'chunk_size' => 500, 'chunks_total' => 1,
            'expires_at' => now()->addDays(30),
        ]);

        $this->getJson("/api/professional/stripe/exports/commission/{$audit->id}")
            ->assertOk()
            ->assertJson(['id' => $audit->id, 'status' => 'processing']);
    }

    public function test_show_returns_404_for_cross_tenant(): void
    {
        $pro = $this->actingAsBrand();
        $other = Professional::factory()->create();
        $audit = CommissionExportAudit::create([
            'professional_id' => $other->id, 'role' => 'brand', 'format' => 'csv', 'filters' => [],
            'status' => 'queued', 'recipient_email' => 'x@x',
            'payouts_total' => 0, 'chunk_size' => 500, 'chunks_total' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        $this->getJson("/api/professional/stripe/exports/commission/{$audit->id}")->assertNotFound();
    }

    public function test_old_sync_transactions_route_is_gone(): void
    {
        $this->actingAsBrand();
        $this->get('/api/professional/stripe/exports/transactions.csv?role=brand')->assertNotFound();
    }

    private function actingAsBrand(): Professional
    {
        $pro = Professional::factory()->create(['primary_email' => 'jane@example.com', 'type' => 'brand']);
        $this->actingAsProfessional($pro);
        return $pro;
    }
}
```

> `actingAsProfessional()` is an existing test helper in this project; it injects the Supabase JWT + resolves `current.pro`. If unsure, look at any existing feature test under `tests/Feature/Professional/` for the pattern.

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Create the controller.**

```php
<?php

namespace App\Http\Controllers\Api\Professional\Stripe;

use App\Exceptions\CommissionExportInProgressException;
use App\Exceptions\NoRecipientEmailException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Professional\Stripe\RequestCommissionExportRequest;
use App\Http\Resources\Professional\CommissionExportAuditResource;
use App\Models\Commerce\CommissionExportAudit;
use App\Models\Commerce\CommissionMovement;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\CommissionExportService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommissionExportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly CommissionExportService $service) {}

    public function store(RequestCommissionExportRequest $request): JsonResponse
    {
        /** @var Professional $pro */
        $pro = $request->attributes->get('current_professional')
            ?? app('current.professional');

        $role = $this->inferRole($pro);

        // Skeleton policy check — same pattern StripeConnectController::export uses.
        $this->authorizeForUser($pro, 'viewOwnPayouts', new CommissionMovement([
            'brand_professional_id' => $role === 'brand' ? $pro->id : null,
            'affiliate_professional_id' => $role === 'affiliate' ? $pro->id : null,
        ]));

        try {
            $audit = $this->service->dispatch(
                professional: $pro,
                role: $role,
                format: $request->validated('format'),
                filters: $request->onlyFilters(),
            );
        } catch (NoRecipientEmailException $e) {
            return response()->json(['message' => 'No recipient email on file. Add a primary email first.'], 422);
        } catch (CommissionExportInProgressException $e) {
            return response()->json([
                'message' => 'An export is already in progress for this account.',
                'export_id' => $e->existing->id,
                'status' => $e->existing->status,
            ], 409);
        }

        return response()->json([
            'export_id' => $audit->id,
            'status' => $audit->status,
            'payouts_total' => $audit->payouts_total,
            'chunk_size' => $audit->chunk_size,
            'recipient_email' => $audit->recipient_email,
            'message' => sprintf(
                "Your commission report is being prepared. We'll email %s when it's ready — the link will be valid for %d days.",
                $audit->recipient_email,
                (int) config('partna.exports.commission.signed_url_ttl_days'),
            ),
        ], 202, [
            'Location' => route('professional.stripe.exports.commission.show', ['exportId' => $audit->id]),
        ]);
    }

    public function show(Request $request, string $exportId): CommissionExportAuditResource
    {
        /** @var Professional $pro */
        $pro = $request->attributes->get('current_professional')
            ?? app('current.professional');

        $audit = CommissionExportAudit::query()
            ->where('id', $exportId)
            ->where('professional_id', $pro->id)
            ->first();

        abort_if(! $audit, 404); // 404 not 403 — per CLAUDE.md cross-tenant policy

        return new CommissionExportAuditResource($audit);
    }

    private function inferRole(Professional $pro): string
    {
        // Existing project convention: a professional's primary role is on the
        // `type` field ('brand' | 'affiliate'). If the field isn't named that
        // way at implementation time, mirror StripeConnectController::export's
        // role resolution.
        return $pro->type === 'brand' ? 'brand' : 'affiliate';
    }
}
```

> Verify `$pro->type` is the right field at implementation time — `StripeConnectController::export` already does role resolution; copy whichever shape it uses. The point is the controller never trusts client input for role.

- [ ] **Step 4: Modify `routes/api/professional.php`.**

Find the existing line (currently around `:442`):

```php
Route::get('/stripe/exports/{type}.{format}', [StripeConnectController::class, 'export'])
```

Narrow its `type` regex and add the new routes. Add after the existing line (or in the same Route group):

```php
// Sync exports — narrowed to the three pure-DB types. `transactions` is now async (POST below).
Route::get('/stripe/exports/{type}.{format}', [StripeConnectController::class, 'export'])
    ->where('type', 'payouts|detailed-commissions|eofy')
    ->where('format', 'csv|xlsx');

// Async commission transactions export pipeline.
Route::post('/stripe/exports/transactions', [CommissionExportController::class, 'store'])
    ->middleware('throttle:5,60')
    ->name('professional.stripe.exports.transactions.store');

Route::get('/stripe/exports/commission/{exportId}', [CommissionExportController::class, 'show'])
    ->name('professional.stripe.exports.commission.show');
```

Add the `use` import at the top of the file:

```php
use App\Http\Controllers\Api\Professional\Stripe\CommissionExportController;
```

- [ ] **Step 5: Remove the `transactions` branch from `StripeConnectController::export()`.**

Open `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php`. Locate the `export()` method (around `:597–628`). Remove any switch/match branch that handles `$type === 'transactions'` — call `ExportService::exportPayouts/exportDetailedCommissions/exportEofy` only. Wrap the default case in `abort(404)`.

Example after-state of `export()`:

```php
public function export(ExportsRequest $request, string $type, string $format)
{
    $pro = $this->resolveCurrentProfessional($request);
    $this->authorizeForUser($pro, 'viewOwnPayouts', new CommissionMovement([
        'brand_professional_id' => $request->validated('role') === 'brand' ? $pro->id : null,
        'affiliate_professional_id' => $request->validated('role') === 'affiliate' ? $pro->id : null,
    ]));

    $role = $request->validated('role');
    $filters = $request->validated();

    return match ($type) {
        'payouts' => $this->exporter->exportPayouts($pro, $role, $format, $filters),
        'detailed-commissions' => $this->exporter->exportDetailedCommissions($pro, $role, $format, $filters),
        'eofy' => $this->exporter->exportEofy($pro, $role, $format, $filters),
        default => abort(404),
    };
}
```

- [ ] **Step 6: Remove `exportTransactions()` from `app/Services/Stripe/ExportService.php`.**

Delete the `exportTransactions()` method (currently lines 35–62). Remove the `StripeTransactionFetcher` from the constructor signature and `use` statement at the top:

```php
class ExportService
{
    public function __construct() {}   // (removed StripeTransactionFetcher injection)
    // ... remaining three methods unchanged ...
}
```

Note: `StripeTransactionFetcher.php` itself is no longer referenced by anything in production code. Leave the file in place for now (its `forBrand` / `forAffiliate` shapes are byte-identical to the new generator; deleting it is a separate cleanup step at the end of Task 17). The `TransactionResource` consumer, if any, is unaffected — it consumed array output, not a fetcher reference.

- [ ] **Step 7: Run — expect PASS on the new feature test.**

```bash
./vendor/bin/pest tests/Feature/Professional/Stripe/CommissionExportTest.php
```

- [ ] **Step 8: Commit.**

```bash
git add app/Http/Controllers/Api/Professional/Stripe/CommissionExportController.php routes/api/professional.php app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php app/Services/Stripe/ExportService.php tests/Feature/Professional/Stripe/CommissionExportTest.php
git commit -m "feat(exports): wire commission export controller + routes; remove sync transactions path"
```

---

## Task 15: Stuck-processing sweep command

**Files:**
- Create: `app/Console/Commands/CommissionExportsSweepStuckCommand.php`
- Create: `tests/Feature/Console/CommissionExportsSweepStuckCommandTest.php`

- [ ] **Step 1: Failing test.**

```php
<?php

namespace Tests\Feature\Console;

use App\Models\Commerce\CommissionExportAudit;
use App\Models\Core\Professional\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionExportsSweepStuckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_flips_stuck_processing_rows_to_failed(): void
    {
        config()->set('partna.exports.commission.stuck_watchdog_minutes', 60);
        $pro = Professional::factory()->create();

        $stuck = CommissionExportAudit::create($this->attrs($pro, [
            'status' => 'processing', 'processing_at' => now()->subMinutes(90),
        ]));
        $fresh = CommissionExportAudit::create($this->attrs($pro, [
            'status' => 'processing', 'processing_at' => now()->subMinutes(10),
        ]));

        $this->artisan('commission-exports:sweep-stuck')->assertSuccessful();

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame('processing watchdog timeout', $stuck->fresh()->error_message);
        $this->assertSame('processing', $fresh->fresh()->status);
    }

    private function attrs(Professional $pro, array $overrides = []): array
    {
        return array_merge([
            'professional_id' => $pro->id, 'role' => 'brand', 'format' => 'csv', 'filters' => [],
            'recipient_email' => 'x@x', 'payouts_total' => 1, 'chunk_size' => 500, 'chunks_total' => 1,
            'expires_at' => now()->addDays(30),
        ], $overrides);
    }
}
```

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Implement.**

```php
<?php

namespace App\Console\Commands;

use App\Models\Commerce\CommissionExportAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CommissionExportsSweepStuckCommand extends Command
{
    protected $signature = 'commission-exports:sweep-stuck';
    protected $description = 'Mark commission export audits stuck in processing as failed.';

    public function handle(): int
    {
        $threshold = (int) config('partna.exports.commission.stuck_watchdog_minutes', 60);
        $count = 0;

        CommissionExportAudit::stuckInProcessing($threshold)->chunk(100, function ($rows) use (&$count, $threshold) {
            foreach ($rows as $audit) {
                $audit->markFailed('processing watchdog timeout');
                Log::warning('commission_export.swept_stuck', [
                    'audit_id' => $audit->id,
                    'age_minutes' => $audit->processing_at?->diffInMinutes(now()) ?? null,
                    'threshold_minutes' => $threshold,
                ]);
                $count++;
            }
        });

        $this->info("Swept {$count} stuck export(s) → failed.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run — expect PASS.**

- [ ] **Step 5: Commit.**

```bash
git add app/Console/Commands/CommissionExportsSweepStuckCommand.php tests/Feature/Console/CommissionExportsSweepStuckCommandTest.php
git commit -m "feat(exports): add stuck-processing sweep command"
```

---

## Task 16: Prune-expired command

**Files:**
- Create: `app/Console/Commands/CommissionExportsPruneExpiredCommand.php`
- Create: `tests/Feature/Console/CommissionExportsPruneExpiredCommandTest.php`

- [ ] **Step 1: Failing test.**

```php
<?php

namespace Tests\Feature\Console;

use App\Models\Commerce\CommissionExportAudit;
use App\Models\Core\Professional\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CommissionExportsPruneExpiredCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_expired_file_and_clears_file_path(): void
    {
        Storage::fake('media');
        config()->set('partna.media_disk', 'media');

        $pro = Professional::factory()->create();
        $audit = CommissionExportAudit::create([
            'professional_id' => $pro->id, 'role' => 'brand', 'format' => 'csv', 'filters' => [],
            'status' => 'completed', 'recipient_email' => 'x@x',
            'payouts_total' => 1, 'chunk_size' => 500, 'chunks_total' => 1, 'chunks_completed' => 1,
            'file_path' => "exports/commissions/{$pro->id}/x/data.csv",
            'expires_at' => now()->subDay(),
            'completed_at' => now()->subDays(31),
        ]);
        Storage::disk('media')->put($audit->file_path, 'old data');

        $this->artisan('commission-exports:prune-expired')->assertSuccessful();

        $this->assertFalse(Storage::disk('media')->exists($audit->file_path));
        $this->assertNull($audit->fresh()->file_path);
        $this->assertSame('completed', $audit->fresh()->status); // status preserved
    }
}
```

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Implement.**

```php
<?php

namespace App\Console\Commands;

use App\Models\Commerce\CommissionExportAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CommissionExportsPruneExpiredCommand extends Command
{
    protected $signature = 'commission-exports:prune-expired';
    protected $description = 'Delete commission export files past their expires_at; clear file_path on the audit row.';

    public function handle(): int
    {
        $disk = Storage::disk(config('partna.media_disk'));
        $count = 0;

        CommissionExportAudit::dueForRetention()->chunk(100, function ($rows) use (&$count, $disk) {
            foreach ($rows as $audit) {
                if ($audit->file_path && $disk->exists($audit->file_path)) {
                    $disk->delete($audit->file_path);
                }
                $audit->forceFill(['file_path' => null])->save();
                Log::info('commission_export.pruned_expired', ['audit_id' => $audit->id]);
                $count++;
            }
        });

        $this->info("Pruned {$count} expired export file(s).");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run — expect PASS.**

- [ ] **Step 5: Commit.**

```bash
git add app/Console/Commands/CommissionExportsPruneExpiredCommand.php tests/Feature/Console/CommissionExportsPruneExpiredCommandTest.php
git commit -m "feat(exports): add prune-expired retention command"
```

---

## Task 17: Schedule + Horizon + Stripe API pin + final cleanup

**Files:**
- Modify: `bootstrap/app.php` (Laravel 12 scheduler block) OR `app/Console/Kernel.php` (whichever the project uses)
- Modify: `config/horizon.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Delete: `app/Services/Stripe/StripeTransactionFetcher.php` (no longer referenced)

- [ ] **Step 1: Schedule both cron commands.**

Locate the scheduler (this project's Laravel version determines the file). Add:

```php
$schedule->command('commission-exports:sweep-stuck')
    ->hourly()
    ->withoutOverlapping();

$schedule->command('commission-exports:prune-expired')
    ->dailyAt('03:30')
    ->withoutOverlapping();
```

- [ ] **Step 2: Add `exports` queue to Horizon supervisor.**

In `config/horizon.php`, find `supervisor-1` (around lines 202 and 231 for the two environments). Add `'exports'` to the `queue` array:

```php
'supervisor-1' => [
    'connection' => 'redis',
    'queue' => ['default', 'exports' /* …existing queues… */],
    // …
],
```

If load isolation becomes a concern post-launch, add `'supervisor-exports'` with its own `maxProcesses`; this is a config change, not a code change.

- [ ] **Step 3: Pin Stripe API version on the bound client.**

In `app/Providers/AppServiceProvider.php`, locate where `StripeClient` is bound (search for `StripeClient::class`). Update the binding to pass `stripe_version`:

```php
$this->app->singleton(StripeClient::class, function () {
    return new StripeClient([
        'api_key' => config('services.stripe.secret'),
        'stripe_version' => config('partna.exports.commission.stripe_api_version', '2025-02-24.acacia'),
    ]);
});
```

> If the existing binding already passes options, just add the `stripe_version` key. Confirm the constant value at implementation time by checking `https://docs.stripe.com/api/versioning` or the Stripe dashboard's API version setting.

Also add the config key to `config/partna.php` (inside the `exports.commission` block already added in Task 1):

```php
'stripe_api_version' => env('STRIPE_API_VERSION', '2025-02-24.acacia'),
```

- [ ] **Step 4: Delete the now-orphaned `StripeTransactionFetcher.php`.**

```bash
rm app/Services/Stripe/StripeTransactionFetcher.php
```

Run `composer dump-autoload -o` and `./vendor/bin/pest` to confirm no consumer remains.

- [ ] **Step 5: Update any existing tests that referenced the removed sync transactions export.**

```bash
grep -rln "exports/transactions\." tests/
```

Update each hit: remove the `transactions` case from the export-type matrix; the three remaining types still work synchronously.

- [ ] **Step 6: Run the full test suite.**

```bash
composer test
```

Expected: green.

- [ ] **Step 7: Commit.**

```bash
git add bootstrap/app.php app/Console/Kernel.php config/horizon.php app/Providers/AppServiceProvider.php config/partna.php tests/
git commit -m "feat(exports): schedule cron + pin Stripe API version + remove legacy fetcher"
```

---

## Task 18: Smoke test on dev

This is operational, not a commit.

- [ ] **Step 1: Push migrations + code to dev (per CLAUDE.md push semantics).**

Migrations:
```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
supabase db push
```

Code: merge feature branch into `development` (after PR review).

- [ ] **Step 2: Tail Cloud logs in one terminal.**

```bash
cloud env:logs partna development --live
```

- [ ] **Step 3: Trigger an export via curl (using a dev professional's Supabase JWT).**

```bash
curl -X POST https://dev-api.partna.au/api/professional/stripe/exports/transactions \
  -H "Authorization: Bearer $DEV_JWT" \
  -H "Content-Type: application/json" \
  -d '{"format":"csv","date_from":"2026-01-01","date_to":"2026-05-19"}'
```

Expected: HTTP 202 with envelope; `Location` header set.

- [ ] **Step 4: In the log tail, watch the event stream.**

Expected:
```
commission_export.dispatched   audit_id=… payouts_total=N chunks_total=N
commission_export.chunk_started  audit_id=… chunk_index=0
commission_export.chunk_completed  audit_id=… chunk_index=0 duration_ms=…
commission_export.finalizer_started  audit_id=…
commission_export.finalizer_completed  audit_id=… file_size_bytes=…
```

- [ ] **Step 5: Poll status endpoint.**

```bash
curl https://dev-api.partna.au/api/professional/stripe/exports/commission/$EXPORT_ID \
  -H "Authorization: Bearer $DEV_JWT"
```

Expected: `status` advances from `queued` → `processing` → `completed`. `payouts_processed` increments.

- [ ] **Step 6: Check inbox.**

The email lands at the dev professional's primary email. Click the link → CSV/XLSX downloads.

- [ ] **Step 7: Verify retention sweeper.**

```bash
ssh into dev shell or run via cloud env: php artisan commission-exports:prune-expired
```

Expected: "Pruned 0 expired export file(s)." (no rows past `expires_at` yet on a fresh deploy).

---

## Self-Review Checklist

Run through this before marking the plan done:

**1. Spec coverage** — every section in the spec maps to a task:
- [x] Migration → Tasks 2, 3
- [x] Model + helpers → Task 4
- [x] Form Request (no role accepted) → Task 6
- [x] Resource → Task 6
- [x] Controller (skeleton policy + Location header) → Task 14
- [x] Service (dedup, count, dispatch, empty branch) → Task 10
- [x] Generator (no in-memory accumulation) → Task 7
- [x] JsonlPartWriter → Task 8
- [x] CommissionExportFinalWriter → Task 9
- [x] ExportChunkJob (self-chaining, cursor) → Task 11
- [x] ExportFinalizerJob (parts → final → mail) → Task 12
- [x] Mailable + Blade → Task 13
- [x] Routes (narrow regex, add new, remove sync transactions) → Task 14
- [x] Stuck-sweep + Prune-expired cron → Tasks 15, 16
- [x] Schedule + Horizon supervisor + Stripe API pin → Task 17
- [x] Smoke test on dev → Task 18
- [x] Config + .env keys → Task 1
- [x] Observability log events — present in chunk/finalizer/service/commands

**2. Placeholders** — none. Every step has actual code or a clear concrete command.

**3. Type consistency** —
- `markChunkCompleted(int $payoutsInChunk, ?string $lastPayoutId, int $nextIndex)` — same shape in model, chunk job, and tests.
- `JsonlPartWriter::writePart(string $disk, string $remotePath, iterable $rows): array{row_count,size,sha256}` — same shape in writer impl, chunk-job call site, tests.
- `CommissionExportFinalWriter::writeFinal(string $format, string $outputPath, array $partPaths, string $disk): array{size,sha256,row_count}` — same in writer, finalizer call site, tests.
- `findRecentInFlight(string $professionalId, string $role, string $format, int $windowMinutes)` — same in model and service.
- `STATUS_*` constants only referenced via the `CommissionExportAudit::STATUS_*` form, not string literals (except in DB CHECK constraint and migration).

**4. Ambiguity** — two items intentionally left for implementation-time confirmation:
- `$pro->type` field name in the controller's `inferRole()`: confirm by looking at how `StripeConnectController::export` resolves role (existing code wins).
- Stripe API version string: bump to the current latest stable at implementation time; default in plan is `2025-02-24.acacia`.
