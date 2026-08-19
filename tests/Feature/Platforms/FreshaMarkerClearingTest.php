<?php

use App\Http\Resources\Platforms\PublicIntegrationConnectionResource;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaScraper;
use Mockery\MockInterface;

/**
 * Characterisation, not TDD: no production code makes these pass.
 *
 * saveSelection()/saveStorewide() persist through
 * ManagesIntegrationConnection::writeConnection(), which passes
 * mergePayload: false and so REPLACES the payload rather than merging it.
 * The auto-selection marker therefore cannot survive a human pick, and
 * FreshaConnectFetch deliberately has no unset() for it.
 *
 * These tests exist to keep that true. A future change to merge-on-write would
 * silently leave the dashboard prompting the owner forever to confirm a choice
 * they already made — and nothing else in the suite would notice.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupServiceCategoriesTable();
    setupContentTables();
    setupIntegrationConnectionsTable();
    shimPgAdvisoryLockForSqlite();
});

function autoSelectedConnection(User $user): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/acme',
            'autoSelected' => true,
            'matchTier' => 'first-exact',
            'selection' => [
                'url' => 'https://www.fresha.com/a/acme',
                'storeName' => 'Acme',
                'mode' => 'storewide',
                'employee' => null,
                'services' => [],
                'hiddenServiceIds' => [],
            ],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

it('drops the auto-selection marker once the owner picks a team member', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);
    $connection = autoSelectedConnection($user);

    $this->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('fetchLocation')->andReturn(['name' => 'Acme']);
        $m->shouldReceive('extractTeam')->andReturn([
            ['employeeId' => 'e1', 'displayName' => 'Simon Doyle', 'jobTitle' => null, 'avatarUrl' => null, 'rating' => null],
        ]);
        $m->shouldReceive('slugFromUrl')->andReturn('acme');
        $m->shouldReceive('fetchEmployeeServices')->andReturn([
            ['serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null,
                'price' => 'A$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Cuts', 'hasVariants' => false],
        ]);
        $m->shouldReceive('extractServices')->andReturn([]);
        $m->shouldReceive('extractStoreName')->andReturn('Acme');
    });

    actingAsUser($user)->postJson('/api/platforms/fresha/selection', ['employeeId' => 'e1'])
        ->assertSuccessful();

    $payload = $connection->fresh()->payload;

    // Separate expectations, not chained: a chained expect() aborts at the first
    // failure, so one run would otherwise prove only one of the two keys.
    expect($payload['selection']['mode'])->toBe('employee');
    expect($payload)->not->toHaveKey('autoSelected');
    expect($payload)->not->toHaveKey('matchTier');
});

it('reports an auto-chosen selection to the owner so the dashboard can prompt', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);
    autoSelectedConnection($user);

    // ApiController::success() is a bare response()->json($data) — no `data.`
    // wrapper, so these paths are top-level.
    actingAsUser($user)->getJson('/api/platforms/fresha/selection')
        ->assertSuccessful()
        ->assertJsonPath('autoSelected', true)
        ->assertJsonPath('matchTier', 'first-exact');
});

it('omits the marker entirely for an owner-picked selection', function () {
    // The discriminator only earns its place if it distinguishes. A dashboard
    // storewide carries no marker, so the keys are ABSENT rather than false —
    // that is what keeps this endpoint's pinned exact shape unchanged for every
    // connection the owner chose themselves.
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/acme',
            'selection' => [
                'url' => 'https://www.fresha.com/a/acme',
                'storeName' => 'Acme',
                'mode' => 'storewide',
                'employee' => null,
                'services' => [],
                'hiddenServiceIds' => [],
            ],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/selection')
        ->assertSuccessful()
        ->assertJsonMissingPath('autoSelected')
        ->assertJsonMissingPath('matchTier');
});

it('never exposes the auto-selection marker on the public wire', function () {
    $allowlist = (new ReflectionClass(PublicIntegrationConnectionResource::class))
        ->getConstant('ALLOWLIST');

    // `fresha` has no public allowlist entry today, so these two assertions pass
    // VACUOUSLY on an empty array. That is deliberate and is the whole point: the
    // property being pinned is "if fresha ever gains a public entry, these keys
    // are not in it". Do not mistake a green run here for positive proof that the
    // keys were filtered out of something.
    $fresha = $allowlist['fresha'] ?? [];

    expect($fresha)->not->toContain('autoSelected');
    expect($fresha)->not->toContain('matchTier');
});
