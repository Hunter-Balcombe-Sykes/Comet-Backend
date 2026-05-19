<?php

use App\Models\Commerce\CommissionExportAudit;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

beforeEach(function () {
    setupProfessionalsTable();
    setupCommissionExportAuditTable();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create and persist a minimal Professional in the SQLite test DB.
 */
function makeExportPro(): Professional
{
    $handle = 'exportpro'.Str::random(6);

    return Professional::create([
        'id' => (string) Str::uuid(),
        'auth_user_id' => (string) Str::uuid(),
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'Export Pro',
        'primary_email' => $handle.'@example.com',
        'professional_type' => 'brand',
        'status' => 'active',
    ]);
}

/**
 * Build a minimal attribute array for a new export audit row.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function makeExportAuditAttrs(Professional $pro, array $overrides = []): array
{
    // created_at must be explicit: $timestamps=false means Eloquent won't set it,
    // and SQLite has no DEFAULT now() to fall back on.
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
        'created_at' => now()->toDateTimeString(),
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('status helpers advance state correctly', function () {
    $pro = makeExportPro();
    $audit = CommissionExportAudit::create(makeExportAuditAttrs($pro));

    $audit->markProcessing();
    expect($audit->fresh()->status)->toBe(CommissionExportAudit::STATUS_PROCESSING)
        ->and($audit->fresh()->processing_at)->not->toBeNull();

    $audit->fresh()->markChunkCompleted(payoutsInChunk: 250, lastPayoutId: 'abc', nextIndex: 1);
    $fresh = $audit->fresh();
    expect($fresh->payouts_processed)->toBe(250)
        ->and($fresh->last_processed_payout_id)->toBe('abc')
        ->and($fresh->next_chunk_index)->toBe(1)
        ->and($fresh->chunks_completed)->toBe(1);

    $audit->fresh()->markCompleted(filePath: 'exports/commissions/x/y/data.csv', size: 1234, sha256: 'deadbeef');
    $fresh = $audit->fresh();
    expect($fresh->status)->toBe(CommissionExportAudit::STATUS_COMPLETED)
        ->and($fresh->file_size_bytes)->toBe(1234)
        ->and($fresh->file_sha256)->toBe('deadbeef')
        ->and($fresh->completed_at)->not->toBeNull();
});

it('markProcessing is idempotent — processing_at is preserved on second call', function () {
    $pro = makeExportPro();
    $audit = CommissionExportAudit::create(makeExportAuditAttrs($pro));

    $audit->markProcessing();
    $firstTs = $audit->fresh()->processing_at;

    usleep(10000); // ensure now() would produce a different value

    $audit->markProcessing();
    expect($audit->fresh()->processing_at->equalTo($firstTs))->toBeTrue();
});

it('recipient_email is hidden from array serialisation', function () {
    $pro = makeExportPro();
    $audit = CommissionExportAudit::create(makeExportAuditAttrs($pro, ['recipient_email' => 'jane@example.com']));

    expect($audit->toArray())->not->toHaveKey('recipient_email');
});

it('findRecentInFlight respects window and ignores terminal rows', function () {
    $pro = makeExportPro();

    // Fresh queued row within the window — should be found
    $audit = CommissionExportAudit::create(makeExportAuditAttrs($pro, ['status' => 'queued']));
    expect(CommissionExportAudit::findRecentInFlight($pro->id, 'brand', 'csv', 5))->not->toBeNull();

    // Mark completed (terminal) — should no longer be found
    $audit->update(['status' => 'completed']);
    expect(CommissionExportAudit::findRecentInFlight($pro->id, 'brand', 'csv', 5))->toBeNull();
});

it('findRecentInFlight excludes rows outside the time window', function () {
    $pro = makeExportPro();

    // Row backdated past the 5-minute window — should be excluded even though status is queued
    CommissionExportAudit::create(makeExportAuditAttrs($pro, [
        'status' => 'queued',
        'created_at' => now()->subMinutes(10)->toDateTimeString(),
    ]));

    expect(CommissionExportAudit::findRecentInFlight($pro->id, 'brand', 'csv', 5))->toBeNull();
});

it('markFailed sets failed status, completed_at, and truncates error to 2000 chars', function () {
    $pro = makeExportPro();
    $audit = CommissionExportAudit::create(makeExportAuditAttrs($pro));

    $longError = str_repeat('x', 2500);
    $audit->markFailed($longError);

    $fresh = $audit->fresh();
    expect($fresh->status)->toBe(CommissionExportAudit::STATUS_FAILED)
        ->and(mb_strlen($fresh->error_message))->toBe(2000)
        ->and($fresh->completed_at)->not->toBeNull();
});

it('stuckInProcessing returns only rows older than the threshold', function () {
    $pro = makeExportPro();

    // Row A: stuck — processing_at well beyond the threshold
    $stuck = CommissionExportAudit::create(makeExportAuditAttrs($pro, [
        'status' => 'processing',
        'processing_at' => now()->subMinutes(90)->toDateTimeString(),
    ]));

    // Row B: recent — should not be surfaced
    CommissionExportAudit::create(makeExportAuditAttrs($pro, [
        'status' => 'processing',
        'processing_at' => now()->subMinutes(10)->toDateTimeString(),
    ]));

    $results = CommissionExportAudit::stuckInProcessing(60)->get();
    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($stuck->id);
});

it('dueForRetention returns only rows with a past expires_at and a file_path', function () {
    $pro = makeExportPro();

    // Row A: expired and has a file_path — should be returned
    $expired = CommissionExportAudit::create(makeExportAuditAttrs($pro, [
        'file_path' => 'exports/old.csv',
        'expires_at' => now()->subDay()->toDateTimeString(),
    ]));

    // Row B: not yet expired — should be excluded
    CommissionExportAudit::create(makeExportAuditAttrs($pro, [
        'file_path' => 'exports/current.csv',
        'expires_at' => now()->addDay()->toDateTimeString(),
    ]));

    $results = CommissionExportAudit::dueForRetention()->get();
    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($expired->id);
});
