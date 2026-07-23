<?php

// tests/Feature/Platforms/RefreshObservabilityTest.php
//
// OBS-1 report()/fail() assertions across the 4 surviving silent-failure sites.
// All imports for the whole file live in this block; Tasks 8 & 10 append only bodies.

use App\Exceptions\Platforms\MissingPublicAllowlistException;
use App\Exceptions\Platforms\PlaceDetailsUnavailableException;
use App\Http\Resources\Platforms\PublicIntegrationConnectionResource;
use App\Jobs\Platforms\RefreshConnectionJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

it('reports a PlaceDetailsUnavailableException when the billed Place-Details call is non-OK', function () {
    config()->set('services.google_maps.server_api_key', 'test-key');
    Exceptions::fake();
    Http::fake(['places.googleapis.com/v1/places/*' => Http::response(['error' => 'quota'], 429)]);

    $result = app(GoogleBusinessService::class)->fetchPlaceDetails('ChIJfail');

    expect($result)->toBeNull(); // contract preserved
    Exceptions::assertReported(fn (PlaceDetailsUnavailableException $e) => $e->placeId === 'ChIJfail' && $e->status === 429);
});

it('does not report on a healthy Place-Details fetch', function () {
    config()->set('services.google_maps.server_api_key', 'test-key');
    Exceptions::fake();
    // Media pattern first (see Task 6 note); the details response carries no photos,
    // so resolvePhotoUrls is skipped and no media call is made regardless.
    Http::fake([
        'places.googleapis.com/*/media*' => Http::response(['photoUri' => 'x'], 200),
        'places.googleapis.com/v1/places/*' => Http::response(['id' => 'ChIJok'], 200),
    ]);

    app(GoogleBusinessService::class)->fetchPlaceDetails('ChIJok');

    Exceptions::assertNothingReported();
});

// ── Task 8: FetchShapeException observability ────────────────────────────────

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function obsUser(): User
{
    return User::create([
        'handle' => 'obs', 'handle_lc' => 'obs', 'display_name' => 'Obs',
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'obs@example.com',
    ]);
}

it('reports a FetchShapeException (data corruption) but records status=error', function () {
    Exceptions::fake();
    $user = obsUser();

    // YouTube payload MISSING the required `handle` → YoutubeFetch throws FetchShapeException.
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['name' => 'no handle here'],
    ]);

    app(PlatformRefresher::class)->refresh($conn->refresh());

    $conn->refresh();
    expect($conn->last_refresh_status)->toBe('error')
        ->and($conn->consecutive_failures)->toBe(1);
    Exceptions::assertReported(FetchShapeException::class);
});

it('does NOT report a transient unavailable miss', function () {
    Exceptions::fake();
    $user = obsUser();

    // Missing placeId → GoogleBusinessFetch throws FetchUnavailableException (transient).
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => ['url' => 'https://maps.google.com/x'],
    ]);

    app(PlatformRefresher::class)->refresh($conn->refresh());

    $conn->refresh();
    expect($conn->last_refresh_status)->toBe('unavailable');
    Exceptions::assertNothingReported();
});

// ── Task 10: PublicIntegrationConnectionResource fail-closed allowlist ──────────

it('reports (and fails closed to empty) when a platform has no public allowlist', function () {
    Exceptions::fake();

    // Build the model WITHOUT saving so the SEC-1 saving guard doesn't reject the
    // unknown platform — we only exercise the resource's read-time allowlist branch.
    $conn = new IntegrationConnection([
        'platform' => 'totally-unregistered',
        'resource_id' => 'x',
        'payload' => ['secret_internal_key' => 'leak-me'],
    ]);

    $out = (new PublicIntegrationConnectionResource($conn))->toArray(request());

    expect($out['payload'])->toBe([]); // fail-closed: nothing leaks
    Exceptions::assertReported(fn (MissingPublicAllowlistException $e) => str_contains($e->getMessage(), 'totally-unregistered'));
});

it('does not report for a normally-allowlisted platform', function () {
    Exceptions::fake();

    $conn = new IntegrationConnection([
        'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['handle' => 'c', 'name' => 'vid', '_internal' => 'hidden'],
    ]);

    $out = (new PublicIntegrationConnectionResource($conn))->toArray(request());

    expect($out['payload'])->toHaveKey('handle')->not->toHaveKey('_internal');
    Exceptions::assertNothingReported();
});

// ── Unit 2 (R3-OBS-6): representative terminal-failure() coverage ──────────
// One behavioural test stands in for all eight R3-OBS-6 jobs (near-identical
// report()+Log::error() shape) — RefreshConnectionJob chosen as the
// highest-priority of the eight (fans out from the hourly integrations:refresh
// cron; a silent permanent failure is invisible until the next sweep).

it('reports and logs a terminal RefreshConnectionJob failure', function () {
    Exceptions::fake();
    Log::spy();

    $job = new RefreshConnectionJob('conn-123', 'instagram');
    $job->failed(new RuntimeException('boom'));

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'boom');
    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'integrations.refresh.job_failed'
            && ($context['connection_id'] ?? null) === 'conn-123'
            && ($context['platform'] ?? null) === 'instagram');
});
