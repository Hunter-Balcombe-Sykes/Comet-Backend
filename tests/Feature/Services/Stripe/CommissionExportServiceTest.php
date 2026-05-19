<?php

use App\Exceptions\CommissionExportInProgressException;
use App\Exceptions\NoRecipientEmailException;
use App\Jobs\Exports\ExportChunkJob;
use App\Jobs\Exports\ExportFinalizerJob;
use App\Models\Commerce\CommissionExportAudit;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\CommissionExportService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupCommissionPayoutsTable();
    setupCommissionExportAuditTable();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create and persist a minimal Professional with the given attribute overrides.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeExportServicePro(array $overrides = []): Professional
{
    $handle = 'svcpro'.Str::random(6);

    return Professional::create(array_merge([
        'id' => (string) Str::uuid(),
        'auth_user_id' => (string) Str::uuid(),
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'Service Pro',
        'primary_email' => $handle.'@example.com',
        'professional_type' => 'brand',
        'status' => 'active',
    ], $overrides));
}

/**
 * Insert $count payout rows for $professionalId in the given $role column.
 */
function seedPayoutsFor(string $professionalId, int $count, string $role = 'brand'): void
{
    $column = $role === 'brand' ? 'brand_professional_id' : 'affiliate_professional_id';
    $now = now()->toDateTimeString();

    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            $column => $professionalId,
            'status' => 'completed',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    // Batch in chunks to avoid SQLite parameter limits on large seeds
    foreach (array_chunk($rows, 100) as $batch) {
        DB::connection('pgsql')->table('commerce.commission_payouts')->insert($batch);
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('creates audit row with correct totals and dispatches first chunk job', function () {
    Bus::fake();

    $pro = makeExportServicePro(['primary_email' => 'jane@example.com']);
    seedPayoutsFor($pro->id, count: 542, role: 'brand');

    $service = app(CommissionExportService::class);
    $audit = $service->dispatch($pro, 'brand', 'csv', ['type' => 'all']);

    expect($audit->status)->toBe(CommissionExportAudit::STATUS_QUEUED)
        ->and($audit->payouts_total)->toBe(542)
        ->and($audit->chunks_total)->toBe(2)  // ceil(542/500) = 2
        ->and($audit->recipient_email)->toBe('jane@example.com')
        ->and($audit->expires_at)->not->toBeNull();

    Bus::assertDispatched(ExportChunkJob::class, fn ($job) =>
        $job->auditId === $audit->id && $job->chunkIndex === 0
    );
});

it('throws CommissionExportInProgressException when an in-flight export exists', function () {
    Bus::fake();

    $pro = makeExportServicePro(['primary_email' => 'jane@example.com']);
    seedPayoutsFor($pro->id, count: 1, role: 'brand');

    $service = app(CommissionExportService::class);
    $service->dispatch($pro, 'brand', 'csv', []);

    expect(fn () => $service->dispatch($pro, 'brand', 'csv', []))
        ->toThrow(CommissionExportInProgressException::class);
});

it('throws NoRecipientEmailException when professional has no email', function () {
    $pro = makeExportServicePro(['primary_email' => null, 'public_contact_email' => null]);

    expect(fn () => app(CommissionExportService::class)->dispatch($pro, 'brand', 'csv', []))
        ->toThrow(NoRecipientEmailException::class);
});

it('dispatches finalizer (not chunk) when there are no matching payouts', function () {
    Bus::fake();

    $pro = makeExportServicePro(['primary_email' => 'jane@example.com']);
    // No payouts seeded

    $service = app(CommissionExportService::class);
    $audit = $service->dispatch($pro, 'brand', 'csv', []);

    expect($audit->payouts_total)->toBe(0)
        ->and($audit->chunks_total)->toBe(0);

    Bus::assertDispatched(ExportFinalizerJob::class);
    Bus::assertNotDispatched(ExportChunkJob::class);
});

it('prefers public_contact_email over primary_email for recipient', function () {
    Bus::fake();

    $pro = makeExportServicePro([
        'primary_email' => 'primary@example.com',
        'public_contact_email' => 'public@example.com',
    ]);

    $audit = app(CommissionExportService::class)->dispatch($pro, 'brand', 'csv', []);

    expect($audit->recipient_email)->toBe('public@example.com');
});
