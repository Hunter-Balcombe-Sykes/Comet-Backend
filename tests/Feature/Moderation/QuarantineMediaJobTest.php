<?php

use App\Jobs\Moderation\QuarantineMediaJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.site_media (
        id TEXT PRIMARY KEY,
        site_id TEXT NULL,
        user_id TEXT NULL,
        pool TEXT NULL,
        path TEXT NULL,
        original_path TEXT NULL,
        original_mime TEXT NULL,
        original_filename TEXT NULL,
        original_size_bytes INTEGER NULL,
        media_type TEXT NULL,
        processing_state TEXT NULL,
        processing_error TEXT NULL,
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
    setupAllModerationTables();
});

it('sets site_media.processing_state to quarantined', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $mediaId = Str::uuid()->toString();
    DB::insert(
        "INSERT INTO site.site_media (id, site_id, pool, path, processing_state) VALUES (?, ?, 'public-assets', 'p.jpg', 'scanning')",
        [$mediaId, $site->id]
    );

    $case = ModerationCase::factory()->csamMatch()->create([
        'reportable_type' => 'SiteMedia',
        'reportable_id' => $mediaId,
    ]);
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create([
        'action_type' => 'quarantine_media',
        'action_target' => ['site_media_id' => $mediaId],
    ]);

    (new QuarantineMediaJob($entry->id, $case->id))->handle();

    $row = DB::selectOne('SELECT processing_state FROM site.site_media WHERE id = ?', [$mediaId]);
    expect($row->processing_state)->toBe('quarantined');
    expect($entry->fresh()->status)->toBe('completed');
})->group('postgres');

it('falls back to reportable_id when action_target has no site_media_id', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $mediaId = Str::uuid()->toString();
    DB::insert(
        "INSERT INTO site.site_media (id, site_id, pool, path, processing_state) VALUES (?, ?, 'public-assets', 'p.jpg', 'scanning')",
        [$mediaId, $site->id]
    );

    $case = ModerationCase::factory()->csamMatch()->create([
        'reportable_type' => 'SiteMedia',
        'reportable_id' => $mediaId,
    ]);
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create();
    // Empty action_target — no site_media_id key present, so job falls back to reportable_id
    $entry = ActionLogEntry::factory()->forDecision($decision)->create([
        'action_type' => 'quarantine_media',
        'action_target' => [],
    ]);

    (new QuarantineMediaJob($entry->id, $case->id))->handle();

    $row = DB::selectOne('SELECT processing_state FROM site.site_media WHERE id = ?', [$mediaId]);
    expect($row->processing_state)->toBe('quarantined');
})->group('postgres');

it('is idempotent (running twice does not error, state stays quarantined)', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $mediaId = Str::uuid()->toString();
    DB::insert(
        "INSERT INTO site.site_media (id, site_id, pool, path, processing_state) VALUES (?, ?, 'public-assets', 'p.jpg', 'scanning')",
        [$mediaId, $site->id]
    );

    $case = ModerationCase::factory()->csamMatch()->create([
        'reportable_type' => 'SiteMedia',
        'reportable_id' => $mediaId,
    ]);
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create([
        'action_type' => 'quarantine_media',
        'action_target' => ['site_media_id' => $mediaId],
    ]);

    (new QuarantineMediaJob($entry->id, $case->id))->handle();
    (new QuarantineMediaJob($entry->id, $case->id))->handle(); // second run must not throw

    $row = DB::selectOne('SELECT processing_state FROM site.site_media WHERE id = ?', [$mediaId]);
    expect($row->processing_state)->toBe('quarantined');
})->group('postgres');
