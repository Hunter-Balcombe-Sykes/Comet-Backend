<?php

use App\Http\Resources\Routing\RoutingConnectionResource;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Http\Request;

// Defined and used in this file only — a cross-file Pest helper breaks the
// parallel runner in this repo.
function resolveRoutingConnectionRow(array $payload): array
{
    $connection = new IntegrationConnection([
        'surface_key' => 'google_business.listing',
        'resource_id' => 'res-1',
        'is_primary' => false,
        'is_active' => true,
        'payload' => $payload,
    ]);
    $connection->id = '00000000-0000-0000-0000-000000000001';

    return (new RoutingConnectionResource($connection))->toArray(Request::create('/'));
}

it('emits payload.name when it is a string', function () {
    expect(resolveRoutingConnectionRow(['name' => 'ST. ALi Coffee Roasters']))
        ->toHaveKey('name', 'ST. ALi Coffee Roasters');
});

it('emits null when payload carries no name', function () {
    expect(resolveRoutingConnectionRow(['url' => 'https://maps.google.com/x']))
        ->toHaveKey('name', null);
});

it('emits null rather than casting a non-string name', function () {
    // payload is jsonb — nothing constrains its shape, so a numeric Shopify
    // store id must not arrive at the dashboard as a name.
    expect(resolveRoutingConnectionRow(['name' => 10233455]))->toHaveKey('name', null);
    expect(resolveRoutingConnectionRow(['name' => ['a' => 'b']]))->toHaveKey('name', null);
});
