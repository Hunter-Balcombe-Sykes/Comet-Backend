<?php

use App\Models\Core\Site\Site;
use App\Services\Content\ServiceCollections;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\Queue;

// Owner ruling 2026-08-17: service categories (slice 3b's
// kind='service_category' collections) reach the PUBLIC wire, exactly as
// menu categories did in slice 4. Before this, the collections read was
// gated on product/menu_item, so the services pool shipped its items flat —
// no collectionIds, no collections map — and Astro could not group them.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupItemSlugsTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    Queue::fake();
});

it('publishes service categories in the collections map, not as dangling ids', function () {
    [$userId, $siteId] = seedUserWithSite();
    $itemId = ownerServiceItem($userId, ['title' => 'Cut & Style']);

    $collections = app(ServiceCollections::class);
    $categoryId = $collections->create($userId, 'Hair');
    $collections->assign($userId, $itemId, $categoryId, null);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'services');
    $item = collect($resolved['selection'])->firstWhere('id', $itemId);

    expect($item['collectionIds'])->toBe([$categoryId])
        ->and(array_keys($resolved['collections']))->toBe([$categoryId])
        ->and($resolved['collections'][$categoryId]['name'])->toBe('Hair');
});

it('carries the same key set on a service category as on a store card', function () {
    [$userId, $siteId] = seedUserWithSite();
    $itemId = ownerServiceItem($userId, ['title' => 'Cut & Style']);

    $collections = app(ServiceCollections::class);
    $collections->assign($userId, $itemId, $collections->create($userId, 'Hair'), null);

    $entry = collect(app(PoolResolver::class)
        ->resolve(Site::query()->findOrFail($siteId), 'services')['collections'])->first();

    expect(array_keys($entry))->toEqualCanonicalizing(PoolResolver::STORE_KEYS)
        ->and($entry['provider'])->toBeNull()
        ->and($entry['url'])->toBeNull();
});

it('ships an uncategorised service with empty collectionIds, not a phantom group', function () {
    [$userId, $siteId] = seedUserWithSite();
    $itemId = ownerServiceItem($userId, ['title' => 'Consultation']);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'services');
    $item = collect($resolved['selection'])->firstWhere('id', $itemId);

    expect($item['collectionIds'])->toBe([])
        ->and($resolved['collections'])->toBe([]);
});

it('drops a removed category from the wire while the service stays', function () {
    [$userId, $siteId] = seedUserWithSite();
    $itemId = ownerServiceItem($userId, ['title' => 'Cut & Style']);

    $collections = app(ServiceCollections::class);
    $categoryId = $collections->create($userId, 'Hair');
    $collections->assign($userId, $itemId, $categoryId, null);
    $collections->remove($userId, $categoryId);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'services');
    $item = collect($resolved['selection'])->firstWhere('id', $itemId);

    expect($item)->not->toBeNull()
        ->and($item['collectionIds'])->toBe([])
        ->and($resolved['collections'])->toBe([]);
});
