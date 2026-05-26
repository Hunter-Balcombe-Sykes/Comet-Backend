<?php

namespace Tests\Feature\User\DataExport;

use App\Models\Core\Gdpr\DataExportAudit;
use Illuminate\Support\Str;

beforeEach(function () {
    DataExportTestCase::boot();
});

it('persists with auto-generated uuid and default status queued', function () {
    $audit = DataExportAudit::create([
        'user_id' => (string) Str::uuid(),
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
    ]);

    expect($audit->id)->toBeString()->not->toBeEmpty();
    expect($audit->status)->toBe('queued');
});

it('markCompleted updates status, completed_at, file metadata', function () {
    $audit = DataExportAudit::create([
        'user_id' => (string) Str::uuid(),
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
    ]);

    $audit->markCompleted(
        filePath: 'exports/abc/def.zip',
        fileSizeBytes: 12345,
        fileSha256: str_repeat('a', 64),
        recordCounts: ['customers' => 10, 'bookings' => 5],
    );

    $audit->refresh();
    expect($audit->status)->toBe('completed');
    expect($audit->completed_at)->not->toBeNull();
    expect($audit->file_path)->toBe('exports/abc/def.zip');
    expect($audit->file_size_bytes)->toBe(12345);
    expect($audit->file_sha256)->toBe(str_repeat('a', 64));
    expect($audit->record_counts)->toBe(['customers' => 10, 'bookings' => 5]);
});

it('markFailed records the error and truncates very long messages', function () {
    $audit = DataExportAudit::create([
        'user_id' => (string) Str::uuid(),
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
    ]);

    $longError = str_repeat('x', 3000);
    $audit->markFailed($longError);

    $audit->refresh();
    expect($audit->status)->toBe('failed');
    expect(mb_strlen($audit->error_message))->toBe(2000);
});
