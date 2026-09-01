<?php

/**
 * GET /public/sites/{handle}/progress — the sitepage overlay's read
 * (2026-09-02). Answers {done, stage} by handle and nothing else; a handle
 * with no live unclaimed build is simply done.
 */

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Services\PreAccount\BuildProgress;
use App\Services\PreAccount\PreAccountBuildService;
use App\Services\PreAccount\SourcePrefetch;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
    setupSiteMediaTable();
    setupWorkplacesTable();
    setupSectionsTables();
    setupContentTables();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

it('is done for an unknown or malformed handle — nothing to wait for', function () {
    $this->getJson('/api/public/sites/nobody-here/progress')->assertOk()->assertExactJson(['done' => true, 'stage' => null]);
    $this->getJson('/api/public/sites/'.rawurlencode('bad handle!').'/progress')->assertOk()->assertJsonPath('done', true);
});

it('tracks a live build by handle and says only done and stage', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'overlayjane']);
    $build = PreAccountBuild::firstOrFail();
    app(PreAccountBuildService::class)->materializeIdentity($build, new SourcePrefetch(payload: []));
    $build->refresh();
    $handle = (string) $build->user->handle_lc;

    $this->getJson("/api/public/sites/{$handle}/progress")
        ->assertOk()
        ->assertExactJson(['done' => false, 'stage' => 'identity']);

    BuildProgress::note((string) $build->id, PreAccountBuildEvent::STAGE_MEDIA, PreAccountBuildEvent::STATUS_LANDED, 'Grabbing your latest photos', ['thumbnails' => ['https://cdn.example/t.jpg']]);
    $res = $this->getJson("/api/public/sites/{$handle}/progress")->assertOk()->assertJsonPath('stage', 'media');
    // No labels, no thumbnails on this wire.
    expect($res->json())->toHaveKeys(['done', 'stage'])->and($res->json())->not->toHaveKey('events');

    // Past the ceiling: done regardless of what landed.
    $build->forceFill(['created_at' => now()->subMinutes(11)])->save();
    $this->getJson("/api/public/sites/{$handle}/progress")->assertJsonPath('done', true);
});

it('is done once the build is claimed', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'claimedjane']);
    $build = PreAccountBuild::firstOrFail();
    app(PreAccountBuildService::class)->materializeIdentity($build, new SourcePrefetch(payload: []));
    $build->refresh();
    $handle = (string) $build->user->handle_lc;

    $build->forceFill(['claimed_at' => now()])->save();
    $this->getJson("/api/public/sites/{$handle}/progress")->assertExactJson(['done' => true, 'stage' => null]);
});
