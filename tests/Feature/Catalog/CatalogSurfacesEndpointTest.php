<?php

use App\Catalog\CompiledCatalog;

it('serves the compiled catalog with a digest ETag', function () {
    $pro = createTenant('catalog-surfaces');

    $response = actingAsUser($pro)->getJson('/api/catalog/surfaces');

    $response->assertOk()
        ->assertJsonStructure(['digest', 'brands', 'surfaces'])
        ->assertHeader('ETag');

    expect($response->json('digest'))->toBe(CompiledCatalog::digest());
});

it('returns 304 when the client already holds the current digest', function () {
    $pro = createTenant('catalog-surfaces-304');

    $first = actingAsUser($pro)->getJson('/api/catalog/surfaces');
    $etag = $first->headers->get('ETag');

    $second = actingAsUser($pro)->withHeaders(['If-None-Match' => $etag])
        ->getJson('/api/catalog/surfaces');

    $second->assertStatus(304);
});

it('rejects unauthenticated access', function () {
    $this->getJson('/api/catalog/surfaces')->assertStatus(401);
});
