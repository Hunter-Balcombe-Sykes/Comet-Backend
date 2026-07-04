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
    // Attach schema namespaces for SQLite test isolation
    $conn = DB::connection('pgsql');
    foreach (['core', 'site', 'audit', 'moderation', 'commerce', 'notifications', 'analytics', 'billing', 'retail', 'brand'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (Throwable $e) {
            // already attached
        }
    }
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.users (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT NULL,
        handle TEXT NULL,
        handle_lc TEXT NULL,
        display_name TEXT NULL,
        first_name TEXT NULL,
        last_name TEXT NULL,
        primary_email TEXT NULL,
        phone TEXT NULL,
        account_type TEXT NULL,
        status TEXT NULL,
        bio TEXT NULL,
        country_code TEXT NULL,
        timezone TEXT NULL,
        onboarding_step INTEGER NULL,
        public_contact_number TEXT NULL,
        public_contact_email TEXT NULL,
        icon_bucket TEXT NULL,
        icon_path TEXT NULL,
        headshot_bucket TEXT NULL,
        headshot_path TEXT NULL,
        location_street_address TEXT NULL,
        location_postcode TEXT NULL,
        location_city TEXT NULL,
        location_state TEXT NULL,
        location_country TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.sites (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        subdomain TEXT NULL,
        skeleton_id TEXT NULL,
        subdomain_changed_at TEXT NULL,
        is_published INTEGER NULL,
        unpublished_at TEXT NULL,
        settings TEXT NULL,
        moderation_state TEXT NOT NULL DEFAULT \'active\',
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
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
        product_gid TEXT NULL,
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
