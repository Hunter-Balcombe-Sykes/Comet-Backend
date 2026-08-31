<?php

use App\Exceptions\Moderation\ModerationTargetMissingException;
use App\Jobs\Moderation\QuarantineMediaJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
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

// #W2-JOB-3 / #W2-LIFE-10: an at-least-once queue redelivery of a job whose
// action log entry already completed must not re-quarantine media staff have
// since cleared. Without the completed-status guard, this fails — the
// redelivery flips the cleared media straight back to 'quarantined'.
it('does not re-quarantine media cleared after this action log entry already completed', function () {
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

    QuarantineMediaJob::dispatch($entry->id, $case->id);

    $row = DB::selectOne('SELECT processing_state FROM site.site_media WHERE id = ?', [$mediaId]);
    expect($row->processing_state)->toBe('quarantined');
    expect($entry->fresh()->status)->toBe('completed');
    $attemptsAfterFirstRun = $entry->fresh()->attempts;

    // Staff clear the media in between the first delivery and the redelivery.
    // site_media_processing_state_check allows pending|processing|scanning|ready|
    // failed|quarantined only — 'ready' (SiteMedia::PROCESSING_STATE_READY) is
    // what every other clear/available write site in app/ uses.
    DB::update(
        'UPDATE site.site_media SET processing_state = ? WHERE id = ?',
        [SiteMedia::PROCESSING_STATE_READY, $mediaId]
    );

    QuarantineMediaJob::dispatch($entry->id, $case->id);

    $row = DB::selectOne('SELECT processing_state FROM site.site_media WHERE id = ?', [$mediaId]);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_READY);
    expect($entry->fresh()->attempts)->toBe($attemptsAfterFirstRun);
})->group('postgres');

// #W2-OBS-2: a missing media row used to be marked 'completed', asserting an
// enforcement action that never happened. It must NOT throw — this job is the
// first link of the csam_auto_suspend Bus::chain, and a throw would strand the
// suspension and KV-retirement links behind it.
it('marks the entry failed and reports when the media row does not exist', function () {
    Exceptions::fake();
    $missingId = Str::uuid()->toString();

    $case = ModerationCase::factory()->csamMatch()->create([
        'reportable_type' => 'SiteMedia',
        'reportable_id' => $missingId,
    ]);
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create([
        'action_type' => 'quarantine_media',
        'action_target' => ['site_media_id' => $missingId],
    ]);

    (new QuarantineMediaJob($entry->id, $case->id))->handle();

    $fresh = $entry->fresh();
    expect($fresh->status)->toBe('failed');
    expect((string) $fresh->failure_reason)->toContain($missingId);
    Exceptions::assertReported(ModerationTargetMissingException::class);
})->group('postgres');
