<?php

use App\Models\Core\Gdpr\DataExportAudit;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * PRIV-2: gdpr:prune-completed-exports command.
 *
 * These tests run on SQLite (the project standard). The DB GRANT restored by
 * migration 20260624000000 is not enforced here — SQLite has no role system.
 * The tests exercise the LOGIC: correct rows selected, R2 file deleted,
 * columns nulled, audit row retained, dry-run is a no-op.
 */

beforeEach(function () {
    setupDataExportAuditTable();

    config([
        'partna.media_disk' => 'media',
        'partna.gdpr.export_retention_days' => 30,
    ]);

    Storage::fake('media');
});

it('deletes the R2 artifact and nulls file columns when a row is older than the retention window', function () {
    $path = 'exports/'.Str::uuid().'/export.zip';
    Storage::disk('media')->put($path, 'zip-bytes');

    $audit = createDataExportAudit([
        'status' => DataExportAudit::STATUS_COMPLETED,
        'file_path' => $path,
        'file_size_bytes' => 1024,
        'file_sha256' => hash('sha256', 'zip-bytes'),
        'created_at' => now()->subDays(31)->toDateTimeString(),
    ]);

    $this->artisan('gdpr:prune-completed-exports')->assertSuccessful();

    // R2 file is gone.
    Storage::disk('media')->assertMissing($path);

    // File columns are nulled — audit ROW still exists.
    $audit->refresh();
    expect($audit->file_path)->toBeNull()
        ->and($audit->file_size_bytes)->toBeNull()
        ->and($audit->file_sha256)->toBeNull()
        // Status and other non-file columns are untouched.
        ->and($audit->status)->toBe(DataExportAudit::STATUS_COMPLETED);
});

it('leaves rows newer than the retention window untouched', function () {
    $path = 'exports/'.Str::uuid().'/export.zip';
    Storage::disk('media')->put($path, 'zip-bytes');

    $audit = createDataExportAudit([
        'file_path' => $path,
        'file_size_bytes' => 512,
        'file_sha256' => 'abc123',
        'created_at' => now()->subDays(10)->toDateTimeString(),
    ]);

    $this->artisan('gdpr:prune-completed-exports')->assertSuccessful();

    // File and columns untouched.
    Storage::disk('media')->assertExists($path);

    $audit->refresh();
    expect($audit->file_path)->toBe($path)
        ->and($audit->file_size_bytes)->toBe(512);
});

it('does not delete or null anything in --dry-run mode', function () {
    $path = 'exports/'.Str::uuid().'/export.zip';
    Storage::disk('media')->put($path, 'zip-bytes');

    $audit = createDataExportAudit([
        'file_path' => $path,
        'file_size_bytes' => 256,
        'created_at' => now()->subDays(40)->toDateTimeString(),
    ]);

    $this->artisan('gdpr:prune-completed-exports', ['--dry-run' => true])->assertSuccessful();

    // Nothing deleted or nulled.
    Storage::disk('media')->assertExists($path);

    $audit->refresh();
    expect($audit->file_path)->toBe($path)
        ->and($audit->file_size_bytes)->toBe(256);
});

it('nulls file columns even when the R2 file is already gone', function () {
    // Simulate a row whose R2 file was deleted externally (R2 outage recovery, etc.)
    $audit = createDataExportAudit([
        'file_path' => 'exports/ghost/export.zip', // does NOT exist on disk
        'file_size_bytes' => 999,
        'file_sha256' => 'deadbeef',
        'created_at' => now()->subDays(35)->toDateTimeString(),
    ]);

    // Must not throw even though Storage::disk()->exists() returns false.
    $this->artisan('gdpr:prune-completed-exports')->assertSuccessful();

    $audit->refresh();
    expect($audit->file_path)->toBeNull()
        ->and($audit->file_size_bytes)->toBeNull();
});

it('skips rows where file_path is already null (already pruned)', function () {
    // A row that was previously pruned should not be re-processed.
    $audit = createDataExportAudit([
        'file_path' => null,
        'file_size_bytes' => null,
        'created_at' => now()->subDays(60)->toDateTimeString(),
    ]);

    // No assertion beyond assertSuccessful — mainly verifying no crash.
    $this->artisan('gdpr:prune-completed-exports')->assertSuccessful();

    // Row still exists.
    expect(DataExportAudit::query()->find($audit->id))->not->toBeNull();
});

it('respects a custom --days override', function () {
    // Row is 16 days old — older than a 15-day override, younger than 30-day default.
    $path = 'exports/'.Str::uuid().'/export.zip';
    Storage::disk('media')->put($path, 'zip-bytes');

    $audit = createDataExportAudit([
        'file_path' => $path,
        'created_at' => now()->subDays(16)->toDateTimeString(),
    ]);

    // 30-day default → untouched.
    $this->artisan('gdpr:prune-completed-exports')->assertSuccessful();
    $audit->refresh();
    expect($audit->file_path)->toBe($path);

    // 15-day override → pruned.
    $this->artisan('gdpr:prune-completed-exports', ['--days' => 15])->assertSuccessful();
    $audit->refresh();
    expect($audit->file_path)->toBeNull();
});
