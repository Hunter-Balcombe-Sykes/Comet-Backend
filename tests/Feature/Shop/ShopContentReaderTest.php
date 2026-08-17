<?php

use App\Services\Shop\ShopContentReader;

// Slice 5a Task 7 fix round 3: focused ShopContentReader::brandMap() unit
// coverage that doesn't need the full ShopEndpointParityTest fixture.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

it('reports a nameless brand as name: null, not the brand id (Finding 5)', function () {
    // upsertStore() writes content.collections.label = name ?? brand_id —
    // there is no separate "unnamed" state that NOT NULL column can hold,
    // so the reader must recognise the fallback and null it back out.
    [$user] = makeShopStore(['name' => null, 'externalRef' => 'nameless-co']);

    $map = app(ShopContentReader::class)->brandMap($user);

    expect($map['nameless-co']['name'])->toBeNull();
});

it('reports a real brand name unchanged when it differs from the brand id', function () {
    [$user] = makeShopStore(['name' => 'Rel Store', 'externalRef' => 'rel-store-au']);

    $map = app(ShopContentReader::class)->brandMap($user);

    expect($map['rel-store-au']['name'])->toBe('Rel Store');
});

it('accepts the narrow false positive: a real name identical to the brand id also reads back null', function () {
    // Documented trade-off, not a bug — see ShopContentReader's own comment.
    [$user] = makeShopStore(['name' => 'same-as-id', 'externalRef' => 'same-as-id']);

    $map = app(ShopContentReader::class)->brandMap($user);

    expect($map['same-as-id']['name'])->toBeNull();
});
