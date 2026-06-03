<?php

use App\Models\Core\Gdpr\DataExportAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\User\DataExport\DataExportTestCase;

// Seed an audit row with an explicit status and created_at so we can age it.
function seedStaleAudit(string $status, string $createdAt): DataExportAudit
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('audit.data_export_audit')->insert([
        'id' => $id,
        'user_id' => (string) Str::uuid(),
        'professional_handle_snapshot' => 'handle',
        'triggered_by' => 'self',
        'recipient_email' => 'test@example.com',
        'status' => $status,
        'created_at' => $createdAt,
    ]);

    return DataExportAudit::find($id);
}

beforeEach(function () {
    DataExportTestCase::boot();
});

it('marks a stale processing row (over 1 hour old) as failed', function () {
    // 2 hours old — well past the 1-hour threshold that safely exceeds the 600s job timeout.
    $stale = seedStaleAudit(DataExportAudit::STATUS_PROCESSING, now()->subHours(2)->toDateTimeString());

    $this->artisan('gdpr:sweep-stale-exports')->assertExitCode(0);

    $stale->refresh();
    expect($stale->status)->toBe(DataExportAudit::STATUS_FAILED);
    expect($stale->error_message)->toContain('stale processing');
    expect($stale->completed_at)->not->toBeNull();
});

it('leaves a recently-created processing row (under 1 hour old) untouched', function () {
    // 5 minutes old — the job is still legitimately in flight.
    $fresh = seedStaleAudit(DataExportAudit::STATUS_PROCESSING, now()->subMinutes(5)->toDateTimeString());

    $this->artisan('gdpr:sweep-stale-exports')->assertExitCode(0);

    $fresh->refresh();
    expect($fresh->status)->toBe(DataExportAudit::STATUS_PROCESSING);
});

it('does not touch rows that are already completed or failed', function () {
    $completed = seedStaleAudit(DataExportAudit::STATUS_COMPLETED, now()->subHours(3)->toDateTimeString());
    $failed = seedStaleAudit(DataExportAudit::STATUS_FAILED, now()->subHours(3)->toDateTimeString());

    $this->artisan('gdpr:sweep-stale-exports')->assertExitCode(0);

    // These should remain unchanged.
    expect(DataExportAudit::find($completed->id)->status)->toBe(DataExportAudit::STATUS_COMPLETED);
    expect(DataExportAudit::find($failed->id)->status)->toBe(DataExportAudit::STATUS_FAILED);
});

it('is a clean no-op when there are no stale rows', function () {
    // No rows at all — command must exit 0 without error.
    $this->artisan('gdpr:sweep-stale-exports')->assertExitCode(0);
});

it('only sweeps old rows and leaves the recent one processing when both exist', function () {
    $stale = seedStaleAudit(DataExportAudit::STATUS_PROCESSING, now()->subHours(2)->toDateTimeString());
    $fresh = seedStaleAudit(DataExportAudit::STATUS_PROCESSING, now()->subMinutes(5)->toDateTimeString());

    $this->artisan('gdpr:sweep-stale-exports')->assertExitCode(0);

    expect(DataExportAudit::find($stale->id)->status)->toBe(DataExportAudit::STATUS_FAILED);
    expect(DataExportAudit::find($fresh->id)->status)->toBe(DataExportAudit::STATUS_PROCESSING);
});
