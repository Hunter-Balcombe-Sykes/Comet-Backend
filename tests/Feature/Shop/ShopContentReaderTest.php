<?php

use App\Services\Shop\ShopContentReader;
use App\Services\Shop\ShopContentWriter;

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
    [$user, $brand] = makeShopBrand(['name' => null, 'brand_id' => 'nameless-co']);
    app(ShopContentWriter::class)->upsertStore($brand, (string) $user->id);

    $map = app(ShopContentReader::class)->brandMap($user);

    expect($map['nameless-co']['name'])->toBeNull();
});

it('reports a real brand name unchanged when it differs from the brand id', function () {
    [$user, $brand] = makeShopBrand(['name' => 'Rel Store', 'brand_id' => 'rel-store-au']);
    app(ShopContentWriter::class)->upsertStore($brand, (string) $user->id);

    $map = app(ShopContentReader::class)->brandMap($user);

    expect($map['rel-store-au']['name'])->toBe('Rel Store');
});

it('accepts the narrow false positive: a real name identical to the brand id also reads back null', function () {
    // Documented trade-off, not a bug — see ShopContentReader's own comment.
    [$user, $brand] = makeShopBrand(['name' => 'same-as-id', 'brand_id' => 'same-as-id']);
    app(ShopContentWriter::class)->upsertStore($brand, (string) $user->id);

    $map = app(ShopContentReader::class)->brandMap($user);

    expect($map['same-as-id']['name'])->toBeNull();
});
