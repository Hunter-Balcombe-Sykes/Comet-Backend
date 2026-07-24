<?php

// REV1: remediates accounts claimed BEFORE ClaimSiteService started forcing a
// re-enrich on claim — same mechanism (clear detailsFetchedAt, dispatch the
// real RefreshConnectionJob), just swept across every already-claimed
// account whose payload is still missing reviews instead of one at a time.

use App\Jobs\Platforms\RefreshConnectionJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupIntegrationConnectionsTable();
});

it('dispatches the refresh only for claimed accounts missing reviews', function () {
    Queue::fake();

    $needsBackfill = User::factory()->create(['status' => 'active']);
    $needsConnection = IntegrationConnection::create([
        'user_id' => $needsBackfill->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'place_id' => 'p1', 'is_active' => true,
        'payload' => ['placeId' => 'p1', 'name' => 'A', 'detailsFetchedAt' => now()->subDay()->toIso8601String()],
    ]);

    $alreadyHasReviews = User::factory()->create(['status' => 'active']);
    IntegrationConnection::create([
        'user_id' => $alreadyHasReviews->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'place_id' => 'p2', 'is_active' => true,
        'payload' => ['placeId' => 'p2', 'reviews' => [['text' => 'already here']]],
    ]);

    $this->artisan('google-business:backfill-claimed-reviews')->assertSuccessful();

    Queue::assertPushed(RefreshConnectionJob::class, fn ($job) => $job->connectionId === $needsConnection->id);
    Queue::assertPushed(RefreshConnectionJob::class, 1);

    expect($needsConnection->fresh()->payload)->not->toHaveKey('detailsFetchedAt');
    expect($needsConnection->fresh()->last_refresh_status)->toBe('pending');
});

it('excludes an unclaimed (non-active) user even with a missing-reviews connection', function () {
    Queue::fake();

    $unclaimed = User::factory()->create(['status' => 'unclaimed']);
    IntegrationConnection::create([
        'user_id' => $unclaimed->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'place_id' => 'p3', 'is_active' => true,
        'payload' => ['placeId' => 'p3'],
    ]);

    $this->artisan('google-business:backfill-claimed-reviews')->assertSuccessful();

    Queue::assertNotPushed(RefreshConnectionJob::class);
});

it('excludes an inactive (disconnected) connection', function () {
    Queue::fake();

    $user = User::factory()->create(['status' => 'active']);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'place_id' => 'p4', 'is_active' => false,
        'payload' => ['placeId' => 'p4'],
    ]);

    $this->artisan('google-business:backfill-claimed-reviews')->assertSuccessful();

    Queue::assertNotPushed(RefreshConnectionJob::class);
});

it('dry-run reports the count without writing or dispatching anything', function () {
    Queue::fake();

    $user = User::factory()->create(['status' => 'active']);
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'place_id' => 'p5', 'is_active' => true,
        'payload' => ['placeId' => 'p5', 'detailsFetchedAt' => now()->toIso8601String()],
    ]);

    $this->artisan('google-business:backfill-claimed-reviews --dry-run')
        ->expectsOutputToContain('Found 1 claimed account(s)')
        ->assertSuccessful();

    Queue::assertNotPushed(RefreshConnectionJob::class);
    expect($connection->fresh()->payload)->toHaveKey('detailsFetchedAt');
});
