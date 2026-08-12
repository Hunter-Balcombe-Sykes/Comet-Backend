<?php

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Slice 5a Task 7 fix round 1, Finding 2: content.offers.availability has
// existed since the schema's own migration (20260727140000) but nothing
// wrote it — the SAME class of gap ProjectionWriterVariantsTest pins for
// item_variants (Task 2), just discovered a slice later. Additive fix in
// replaceCollections()'s $offerRows builder: a projection that sets
// 'availability' persists it, one that omits it writes null — no existing
// projector's behaviour changes, since every projector but Shop's omits the
// key today.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

it('persists an offer\'s availability when the projection carries it', function () {
    $userId = createTenant('offer-avail-'.Str::lower(Str::random(6)))->id;
    $writer = app(ProjectionWriter::class);

    $itemId = $writer->writeManualItem($userId, 'manual:'.sha1('https://x.test/p'), [
        'kind' => 'product',
        'headline' => 'Tee',
        'offers' => [
            ['variant_label' => null, 'amount_minor' => 1800, 'currency' => 'AUD', 'availability' => 'out_of_stock'],
        ],
    ]);

    $offer = DB::table('content.offers')->where('item_id', $itemId)->whereNull('variant_label')->first();

    expect($offer->availability)->toBe('out_of_stock');
});

it('writes a null availability when the projection omits it', function () {
    $userId = createTenant('offer-avail-'.Str::lower(Str::random(6)))->id;
    $writer = app(ProjectionWriter::class);

    $itemId = $writer->writeManualItem($userId, 'manual:'.sha1('https://x.test/q'), [
        'kind' => 'product',
        'headline' => 'Mug',
        'offers' => [
            ['variant_label' => null, 'amount_minor' => 1200, 'currency' => 'AUD'],
        ],
    ]);

    $offer = DB::table('content.offers')->where('item_id', $itemId)->whereNull('variant_label')->first();

    expect($offer->availability)->toBeNull();
});

it('persists a variant offer\'s availability independently of the base offer\'s', function () {
    $userId = createTenant('offer-avail-'.Str::lower(Str::random(6)))->id;
    $writer = app(ProjectionWriter::class);

    $itemId = $writer->writeManualItem($userId, 'manual:'.sha1('https://x.test/r'), [
        'kind' => 'product',
        'headline' => 'Beanie',
        'offers' => [
            ['variant_label' => null, 'amount_minor' => 1000, 'currency' => 'AUD', 'availability' => 'in_stock'],
            ['variant_label' => 'Large', 'amount_minor' => 1200, 'currency' => 'AUD', 'availability' => 'out_of_stock'],
        ],
    ]);

    $base = DB::table('content.offers')->where('item_id', $itemId)->whereNull('variant_label')->first();
    $variant = DB::table('content.offers')->where('item_id', $itemId)->where('variant_label', 'Large')->first();

    expect($base->availability)->toBe('in_stock')
        ->and($variant->availability)->toBe('out_of_stock');
});
