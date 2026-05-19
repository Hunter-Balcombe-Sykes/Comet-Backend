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
