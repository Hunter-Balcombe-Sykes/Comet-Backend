<?php

use App\Exceptions\Platforms\PlacesBudgetExhaustedException;
use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Cache\PlacesClaim;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\IdentitySync;
use App\Services\PreAccount\Generators\GoogleBusinessSourceGenerator;
use App\Services\PreAccount\SourceGenerationException;
use App\Support\BusinessName;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable(); // also creates site.platform_connections (tests/Pest.php)
    setupWorkplacesTable();
});

it('fetches place details, seeds a connection, and folds identity into the workplace exactly once', function () {
    // SEC-1: bind the GoogleBusinessService mock BEFORE any IntegrationConnection
    // save — the saving-guard resolves PlatformRegistry eagerly on first save.
    $svc = Mockery::mock(GoogleBusinessService::class);
    $svc->shouldReceive('fetchPlaceDetails')->once()->with('ChIJtest123', Mockery::any())
        ->andReturn(['name' => 'Jane Cafe', 'address' => '1 Main St', 'phone' => '+61 400 000 000', 'website' => 'https://janecafe.au']);
    app()->instance(GoogleBusinessService::class, $svc);

    // Spy on IdentitySync (passthru — real fold still runs) to prove the
    // generator no longer double-folds: IntegrationConnectionObserver::saved()
    // is the ONLY caller of applyFromGooglePayload now, so it fires exactly
    // once even though this test's generate() call also triggers a save.
    $identitySyncSpy = Mockery::mock(IdentitySync::class)->makePartial();
    $identitySyncSpy->shouldReceive('applyFromGooglePayload')->once()->passthru();
    app()->instance(IdentitySync::class, $identitySyncSpy);

    $user = User::factory()->create(['status' => 'unclaimed', 'account_type' => 'business', 'display_name' => 'Jane Cafe', 'first_name' => 'Jane Cafe']);
    $site = Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);

    app(GoogleBusinessSourceGenerator::class)->generate($user, $site, 'ChIJtest123');

    expect(Workplace::where('site_id', $site->id)->value('name'))->toBe('Jane Cafe')
        ->and(IntegrationConnection::where('user_id', $user->id)->where('platform', 'google-business')->exists())->toBeTrue();
});

it('word-trims an over-cap Google name onto display_name, mirroring GoogleBusinessController::maybeAdoptGoogleName', function () {
    $longName = 'Jane Cafe Emporium of Extremely Long Names';

    $svc = Mockery::mock(GoogleBusinessService::class);
    $svc->shouldReceive('fetchPlaceDetails')->once()->with('ChIJlongname', Mockery::any())
        ->andReturn(['name' => $longName]);
    app()->instance(GoogleBusinessService::class, $svc);

    $user = User::factory()->create(['status' => 'unclaimed', 'account_type' => 'business', 'display_name' => 'Old Name', 'first_name' => 'Old']);
    $site = Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);

    app(GoogleBusinessSourceGenerator::class)->generate($user, $site, 'ChIJlongname');

    $user->refresh();
    $expected = BusinessName::wordTrim($longName);

    expect($user->display_name)->toBe($expected)
        ->and(mb_strlen($expected))->toBeLessThanOrEqual(15)
        // The controller's maybeAdoptGoogleName never touches first_name — mirror
        // that exactly, so the generator must leave it alone too.
        ->and($user->first_name)->toBe('Old');
});

it('strips reviewer PII from the persisted payload while keeping public fields (PRIV-1)', function () {
    $svc = Mockery::mock(GoogleBusinessService::class);
    $svc->shouldReceive('fetchPlaceDetails')->once()->with('ChIJprivacy', Mockery::any())
        ->andReturn([
            'name' => 'Jane Cafe',
            'address' => '1 Main St',
            'rating' => 4.7,
            'reviewCount' => 42,
            'reviews' => [['author' => 'A Reviewer', 'text' => 'Great!']],
            'photos' => [['ref' => 'places/X/photos/1', 'widthPx' => 100, 'heightPx' => 100, 'authors' => ['A Reviewer']]],
        ]);
    app()->instance(GoogleBusinessService::class, $svc);

    $user = User::factory()->create(['status' => 'unclaimed', 'account_type' => 'business']);
    $site = Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);

    app(GoogleBusinessSourceGenerator::class)->generate($user, $site, 'ChIJprivacy');

    $payload = IntegrationConnection::where('user_id', $user->id)->where('platform', 'google-business')->value('payload');

    expect($payload)->not->toHaveKey('reviews')
        ->and($payload['photos'][0])->not->toHaveKey('authors')
        ->and($payload['photos'][0]['ref'])->toBe('places/X/photos/1')
        ->and($payload['name'])->toBe('Jane Cafe')
        ->and($payload['address'])->toBe('1 Main St')
        ->and($payload['rating'])->toBe(4.7)
        ->and($payload['reviewCount'])->toBe(42);
});

it('dispatches GoogleBusinessEnrichJob even with no Apify token configured, so the free website-harvest fallback still runs', function () {
    config(['services.apify.token' => null]);

    $svc = Mockery::mock(GoogleBusinessService::class);
    $svc->shouldReceive('fetchPlaceDetails')->once()->with('ChIJnotoken', Mockery::any())
        ->andReturn(['name' => 'Jane Cafe']);
    app()->instance(GoogleBusinessService::class, $svc);

    Queue::fake();

    $user = User::factory()->create(['status' => 'unclaimed', 'account_type' => 'business', 'display_name' => 'Jane Cafe', 'first_name' => 'Jane Cafe']);
    $site = Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);

    app(GoogleBusinessSourceGenerator::class)->generate($user, $site, 'ChIJnotoken');

    Queue::assertPushed(GoogleBusinessEnrichJob::class, fn ($job) => $job->userId === (string) $user->id
        && $job->placeId === 'ChIJnotoken');

    // Starts 'pending' regardless of token — the job (not the generator) is
    // what settles it to ok/unavailable via its own harvest-only fallback.
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'google-business')->value('apify_status'))
        ->toBe('pending');
});

it('maps a Places budget exhaustion to scrape_failed (resettable), not source_not_found (RV-6)', function () {
    $svc = Mockery::mock(GoogleBusinessService::class);
    $svc->shouldReceive('fetchPlaceDetails')->once()
        ->andThrow(new PlacesBudgetExhaustedException('details', PlacesClaim::UserCapReached, 'ChIJbudgetgone'));
    app()->instance(GoogleBusinessService::class, $svc);

    $user = User::factory()->create(['status' => 'unclaimed', 'account_type' => 'business']);
    $site = Site::factory()->create(['user_id' => $user->id]);

    try {
        app(GoogleBusinessSourceGenerator::class)->generate($user, $site, 'ChIJbudgetgone');
        $this->fail('expected SourceGenerationException');
    } catch (SourceGenerationException $e) {
        // scrape_failed is RESETTABLE (build re-runs) — a budget stop is transient,
        // never source_not_found, which reads as permanently bad data.
        expect($e->failureCode)->toBe(PreAccountBuild::FAILURE_SCRAPE_FAILED);
    }
});

it('maps a null details response to source_not_found', function () {
    $svc = Mockery::mock(GoogleBusinessService::class);
    $svc->shouldReceive('fetchPlaceDetails')->once()->andReturnNull();
    app()->instance(GoogleBusinessService::class, $svc);

    $user = User::factory()->create(['status' => 'unclaimed', 'account_type' => 'business']);
    $site = Site::factory()->create(['user_id' => $user->id]);

    try {
        app(GoogleBusinessSourceGenerator::class)->generate($user, $site, 'ChIJgone');
        $this->fail('expected SourceGenerationException');
    } catch (SourceGenerationException $e) {
        expect($e->failureCode)->toBe(PreAccountBuild::FAILURE_SOURCE_NOT_FOUND);
    }
});
