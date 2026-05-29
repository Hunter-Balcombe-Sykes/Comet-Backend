<?php

use App\Jobs\Moderation\PromoteCleanMediaJob;
use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use App\Services\Cloudflare\CloudflareCsamScanClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('r2_quarantine');
    Storage::fake('r2');

    // Bootstrap SQLite schema — mirrors site.site_media production columns
    // including bucket and scanned_at which are absent from the shared
    // setupMediaTables() helper (added in the CSAM scanning migration).
    attachTestSchemas();
    setupUsersTable();
    setupSitesTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.site_media (
        id TEXT PRIMARY KEY,
        site_id TEXT NULL,
        user_id TEXT NULL,
        pool TEXT NULL,
        bucket TEXT NULL,
        path TEXT NULL,
        original_path TEXT NULL,
        original_mime TEXT NULL,
        original_filename TEXT NULL,
        original_size_bytes INTEGER NULL,
        media_type TEXT NULL,
        processing_state TEXT NULL,
        processing_error TEXT NULL,
        scanned_at TEXT NULL,
        duration_ms INTEGER NULL,
        poster_path TEXT NULL,
        sort_order INTEGER NULL,
        is_active INTEGER NULL,
        alt_text TEXT NULL,
        caption TEXT NULL,
        purpose TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');
});

it('promotes scanning media when Cloudflare reports clean', function () {
    $user    = User::factory()->create();
    $site    = Site::factory()->for($user, 'user')->create();
    $mediaId = Str::uuid()->toString();

    DB::connection('pgsql')->insert(
        "INSERT INTO site.site_media (id, site_id, bucket, path, processing_state, scanned_at, created_at, updated_at)
         VALUES (?, ?, 'partna-media-quarantine', 'quarantine/x/y.jpg', 'scanning', NULL, '2026-01-01 00:00:00', '2026-01-01 00:00:00')",
        [$mediaId, $site->id]
    );
    Storage::disk('r2_quarantine')->put('quarantine/x/y.jpg', 'fake');

    // Mock Cloudflare scan-status to return 'clean'
    $this->instance(CloudflareCsamScanClient::class, new class extends CloudflareCsamScanClient {
        public function statusFor(string $key): string { return 'clean'; }
    });

    app(PromoteCleanMediaJob::class)->handle(
        app(CloudflareCsamScanClient::class),
        app(\App\Services\Moderation\R2QuarantineService::class)
    );

    $row = DB::connection('pgsql')->selectOne(
        "SELECT processing_state, scanned_at, bucket FROM site.site_media WHERE id = ?",
        [$mediaId]
    );
    expect($row->processing_state)->toBe('ready');
    expect($row->scanned_at)->not->toBeNull();
    expect($row->bucket)->toBe('public-assets');
})->group('postgres');

it('leaves scanning media in place when Cloudflare reports pending', function () {
    $user    = User::factory()->create();
    $site    = Site::factory()->for($user, 'user')->create();
    $mediaId = Str::uuid()->toString();

    DB::connection('pgsql')->insert(
        "INSERT INTO site.site_media (id, site_id, bucket, path, processing_state, scanned_at, created_at, updated_at)
         VALUES (?, ?, 'partna-media-quarantine', 'quarantine/x/p.jpg', 'scanning', NULL, '2026-01-01 00:00:00', '2026-01-01 00:00:00')",
        [$mediaId, $site->id]
    );

    $this->instance(CloudflareCsamScanClient::class, new class extends CloudflareCsamScanClient {
        public function statusFor(string $key): string { return 'pending'; }
    });

    app(PromoteCleanMediaJob::class)->handle(
        app(CloudflareCsamScanClient::class),
        app(\App\Services\Moderation\R2QuarantineService::class)
    );

    $row = DB::connection('pgsql')->selectOne(
        "SELECT processing_state FROM site.site_media WHERE id = ?",
        [$mediaId]
    );
    expect($row->processing_state)->toBe('scanning');
})->group('postgres');
