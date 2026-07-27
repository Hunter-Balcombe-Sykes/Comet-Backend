<?php

use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

// The write-guard's job is to accept exactly the surfaces the catalog knows —
// no more, no less. Validating against the legacy bridge alone made every
// brand added since P1 unconnectable, which is how the showcase seeder
// discovered this.

it('accepts a surface that exists only in the catalog, not in the legacy map', function () {
    $pro = createTenant('guard-newbrand');

    $connection = new IntegrationConnection([
        'user_id' => $pro->id,
        'surface_key' => 'uber_eats.order',
        'resource_id' => 'https://www.ubereats.com/au/store/some-store',
        'payload' => [],
    ]);
    $connection->save();

    expect($connection->fresh()->surface_key)->toBe('uber_eats.order')
        // routing_class is derived from the catalog when the legacy map has
        // nothing to say.
        ->and($connection->fresh()->routing_class)->toBe('ordering');
});

it('still accepts a legacy-mapped surface', function () {
    $pro = createTenant('guard-legacy');

    $connection = new IntegrationConnection([
        'user_id' => $pro->id,
        'surface_key' => 'instagram.profile',
        'resource_id' => 'someone',
        'payload' => [],
    ]);
    $connection->save();

    expect($connection->fresh()->routing_class)->toBe('social');
});

it('still accepts a legacy platform slug and translates it', function () {
    $pro = createTenant('guard-slug');

    $connection = new IntegrationConnection([
        'user_id' => $pro->id,
        'platform' => 'instagram',
        'resource_id' => 'someone',
        'payload' => [],
    ]);
    $connection->save();

    expect($connection->fresh()->surface_key)->toBe('instagram.profile')
        ->and($connection->fresh()->platform)->toBe('instagram');
});

it('refuses a surface no catalog and no map knows', function () {
    $pro = createTenant('guard-unknown');

    expect(fn () => (new IntegrationConnection([
        'user_id' => $pro->id,
        'surface_key' => 'notreal.surface',
        'resource_id' => 'x',
        'payload' => [],
    ]))->save())->toThrow(ValidationException::class);
});
