<?php

use App\Services\User\DataExport\DataExportPayloadBuilder;
use App\Services\User\DataExport\DataExportZipWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\User\DataExport\DataExportTestCase;

beforeEach(function () {
    DataExportTestCase::boot();
});

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/export-*') as $f) {
        @unlink($f);
    }
});

function seedProForWriter(string $id, string $email = 'jane@example.com'): void
{
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'jane',
        'handle_lc' => 'jane',
        'display_name' => 'Jane',
        'primary_email' => $email,
        'status' => 'active',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);
}

it('writeStreaming produces a zip containing data.json with builder-defined sections', function () {
    $proId = (string) Str::uuid();
    seedProForWriter($proId);

    DB::connection('pgsql')->table('site.customers')->insert([
        ['id' => (string) Str::uuid(), 'user_id' => $proId, 'email' => 'a@b.com', 'full_name' => 'A B', 'source' => 'manual', 'created_at' => '2026-01-02T00:00:00Z'],
        ['id' => (string) Str::uuid(), 'user_id' => $proId, 'email' => 'c@d.com', 'full_name' => 'C D', 'source' => 'manual', 'created_at' => '2026-01-03T00:00:00Z'],
    ]);

    $builder = app(DataExportPayloadBuilder::class);
    $writer = new DataExportZipWriter;

    $result = $writer->writeStreaming($builder, $proId);

    expect($result['path'])->toBeFile();
    expect($result['sha256'])->toBe(hash_file('sha256', $result['path']));
    expect($result['size'])->toBe(filesize($result['path']));

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $json = $zip->getFromName('data.json');
    expect($json)->not->toBeFalse();

    $decoded = json_decode($json, true);
    expect($decoded)->not->toBeNull();

    // Top-level keys parity with build() — verifies stream() and build()
    // emit the same section list (modulo grouping).
    expect($decoded)->toHaveKeys([
        'metadata', 'profile', 'site', 'waitlist',
        'media', 'integrations', 'customers', 'services', 'service_categories',
        'enquiries', 'lead_submissions', 'email_subscriptions',
        'notifications', 'ui_preferences', 'notification_preferences',
        'auth', 'audit',
    ]);

    // Nested groups have their declared sub-keys.
    expect($decoded['audit'])->toHaveKeys(['data_export_audit', 'handle_change_log', 'handle_aliases', 'subdomain_aliases', 'deletion_audit']);
    expect($decoded['notifications'])->toHaveKeys(['messages', 'receipts']);
    expect($decoded['auth'])->toHaveKey('factor_events');

    expect($decoded['customers'])->toHaveCount(2);

    // CSV emitted for the customers section (has csv_columns).
    expect($zip->locateName('customers.csv'))->not->toBeFalse();
    // No CSV for sections without csv_columns.
    expect($zip->locateName('waitlist.csv'))->toBeFalse();
    expect($zip->locateName('audit_handle_change_log.csv'))->toBeFalse();

    $zip->close();

    // record_counts keys are flat (last segment of dotted name).
    expect($result['record_counts'])->toHaveKey('customers');
    expect($result['record_counts']['customers'])->toBe(2);
    expect($result['record_counts'])->toHaveKey('handle_change_log');
    expect($result['record_counts'])->toHaveKey('messages');
});

it('streaming JSON keeps stable section ordering across runs (sha256 reproducibility)', function () {
    $proId = (string) Str::uuid();
    seedProForWriter($proId);

    $builder = app(DataExportPayloadBuilder::class);
    $writer = new DataExportZipWriter;

    $r1 = $writer->writeStreaming($builder, $proId);
    $r2 = $writer->writeStreaming($builder, $proId);

    $z1 = new ZipArchive;
    $z1->open($r1['path']);
    $j1 = $z1->getFromName('data.json');
    $z1->close();

    $z2 = new ZipArchive;
    $z2->open($r2['path']);
    $j2 = $z2->getFromName('data.json');
    $z2->close();

    // The metadata.exported_at timestamp differs per run; strip it before
    // comparing structural ordering of the rest.
    $j1Stripped = preg_replace('/"exported_at":"[^"]+"/', '"exported_at":""', $j1);
    $j2Stripped = preg_replace('/"exported_at":"[^"]+"/', '"exported_at":""', $j2);

    expect($j1Stripped)->toBe($j2Stripped);
});

it('streaming export with no rows still produces well-formed JSON for every section', function () {
    $proId = (string) Str::uuid();
    seedProForWriter($proId);

    $builder = app(DataExportPayloadBuilder::class);
    $writer = new DataExportZipWriter;

    $result = $writer->writeStreaming($builder, $proId);

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $json = $zip->getFromName('data.json');
    $zip->close();

    $decoded = json_decode($json, true);
    expect($decoded)->not->toBeNull();

    // Empty groups are still well-formed objects with the expected sub-keys.
    expect($decoded['audit']['handle_change_log'])->toBe([]);
    expect($decoded['notifications']['messages'])->toBe([]);
    expect($decoded['auth']['factor_events'])->toBe([]);

    // No CSV files for sections that produced no rows.
    $zipNew = new ZipArchive;
    $zipNew->open($result['path']);
    expect($zipNew->locateName('customers.csv'))->toBeFalse();
    expect($zipNew->locateName('enquiries.csv'))->toBeFalse();
    $zipNew->close();
});
