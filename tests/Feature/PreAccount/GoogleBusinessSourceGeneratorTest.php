<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\PreAccount\Generators\GoogleBusinessSourceGenerator;
use App\Services\PreAccount\SourceGenerationException;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable(); // also creates site.platform_connections (tests/Pest.php)
    setupWorkplacesTable();
});

it('fetches place details, seeds a connection, and folds identity into the workplace', function () {
    // SEC-1: bind the GoogleBusinessService mock BEFORE any IntegrationConnection
    // save — the saving-guard resolves PlatformRegistry eagerly on first save.
    $svc = Mockery::mock(GoogleBusinessService::class);
    $svc->shouldReceive('fetchPlaceDetails')->once()->with('ChIJtest123')
        ->andReturn(['name' => 'Jane Cafe', 'address' => '1 Main St', 'phone' => '+61 400 000 000', 'website' => 'https://janecafe.au']);
    app()->instance(GoogleBusinessService::class, $svc);

    $user = User::factory()->create(['status' => 'unclaimed', 'account_type' => 'business', 'display_name' => 'Jane Cafe', 'first_name' => 'Jane Cafe']);
    $site = Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);

    app(GoogleBusinessSourceGenerator::class)->generate($user, $site, 'ChIJtest123');

    expect(Workplace::where('site_id', $site->id)->value('name'))->toBe('Jane Cafe')
        ->and(IntegrationConnection::where('user_id', $user->id)->where('platform', 'google-business')->exists())->toBeTrue();
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
