<?php

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Task 2 (spec 5a §3.2a): content.item_variants held 0 rows because nothing
// wrote it — replaceCollections() assembled item_media, offers and item_tags
// only. This is the writer's first exercise.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

it('writes item_variants from a projection, in array order', function () {
    $userId = createTenant('variants-'.Str::lower(Str::random(6)))->id;
    $writer = app(ProjectionWriter::class);

    $itemId = $writer->writeManualItem($userId, 'manual:'.sha1('https://x.test/p'), [
        'kind' => 'product',
        'headline' => 'Tee',
        'variants' => [
            ['label' => 'Small', 'sku' => 'TEE-S'],
            ['label' => 'Large', 'sku' => 'TEE-L'],
        ],
    ]);

    $rows = DB::table('content.item_variants')->where('item_id', $itemId)
        ->orderBy('position')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->label)->toBe('Small')
        ->and($rows[0]->position)->toBe(0)
        ->and($rows[1]->sku)->toBe('TEE-L');
});

// Fix round 2, D1 (migration 20260813100003): image_url is written the same
// additive way as sku — a projection that omits the key writes null, so no
// existing projector changes behaviour. Shared hot-path code: every connector
// runs through this builder, not just shop.
it('writes a variant image_url when the projection carries one, and null when it does not', function () {
    $userId = createTenant('variants-'.Str::lower(Str::random(6)))->id;

    $itemId = app(ProjectionWriter::class)->writeManualItem($userId, 'manual:'.sha1('https://x.test/img'), [
        'kind' => 'product',
        'headline' => 'Tee',
        'variants' => [
            ['label' => 'Grey', 'sku' => 'TEE-GY', 'image_url' => 'https://cdn.test/grey.jpg'],
            ['label' => 'Navy', 'sku' => 'TEE-NV', 'image_url' => null],
            // No key at all — the shape every non-shop projector still emits.
            ['label' => 'Plain', 'sku' => 'TEE-PL'],
        ],
    ]);

    $rows = DB::table('content.item_variants')->where('item_id', $itemId)
        ->orderBy('position')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->image_url)->toBe('https://cdn.test/grey.jpg')
        ->and($rows[1]->image_url)->toBeNull()
        ->and($rows[2]->image_url)->toBeNull();
});

it('writes no variants when the projection carries none', function () {
    $userId = createTenant('variants-'.Str::lower(Str::random(6)))->id;
    $itemId = app(ProjectionWriter::class)->writeManualItem(
        $userId, 'manual:'.sha1('https://x.test/q'),
        ['kind' => 'product', 'headline' => 'Plain'],
    );

    expect(DB::table('content.item_variants')->where('item_id', $itemId)->count())->toBe(0);
});

it('replaces a source\'s variants on re-projection rather than appending', function () {
    $userId = createTenant('variants-'.Str::lower(Str::random(6)))->id;
    $writer = app(ProjectionWriter::class);
    $coord = 'manual:'.sha1('https://x.test/r');

    $writer->writeManualItem($userId, $coord, [
        'kind' => 'product', 'headline' => 'Mug',
        'variants' => [['label' => 'Red'], ['label' => 'Blue']],
    ]);
    $itemId = $writer->writeManualItem($userId, $coord, [
        'kind' => 'product', 'headline' => 'Mug',
        'variants' => [['label' => 'Green']],
    ]);

    $labels = DB::table('content.item_variants')->where('item_id', $itemId)->pluck('label');
    expect($labels)->toHaveCount(1)->and($labels[0])->toBe('Green');
});
