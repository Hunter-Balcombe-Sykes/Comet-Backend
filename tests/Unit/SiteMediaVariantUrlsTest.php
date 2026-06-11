<?php

use App\Models\Core\MediaVariant;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    // Fast-path URL resolution — avoids Storage::disk() client construction
    config(['filesystems.disks.test_disk.url' => 'https://cdn.example.com']);
});

it('returns webp variant URLs keyed by variant_key', function () {
    $media = new SiteMedia;
    $media->setRawAttributes(['id' => 'media-1', 'media_type' => 'image', 'status' => 'ready']);

    $webp = new MediaVariant;
    $webp->setRawAttributes([
        'id' => 'v1', 'media_id' => 'media-1', 'variant_key' => 'optimized',
        'artifact_type' => 'webp', 'disk' => 'test_disk', 'path' => 'img/opt.webp',
    ]);
    $jpeg = new MediaVariant;
    $jpeg->setRawAttributes([
        'id' => 'v2', 'media_id' => 'media-1', 'variant_key' => 'original',
        'artifact_type' => 'jpeg', 'disk' => 'test_disk', 'path' => 'img/orig.jpg',
    ]);

    $media->setRelation('mediaVariants', new Collection([$webp, $jpeg]));

    $urls = $media->variantUrls();

    expect($urls)
        ->toHaveKey('optimized', 'https://cdn.example.com/img/opt.webp')
        ->not->toHaveKey('original'); // jpeg excluded from webp filter
});

it('issues no DB query when mediaVariants is already loaded', function () {
    $media = new SiteMedia;
    $media->setRawAttributes(['id' => 'media-1', 'media_type' => 'image', 'status' => 'ready']);
    $media->setRelation('mediaVariants', new Collection([]));

    // SiteMedia extends BaseModel which forces the pgsql connection — log on that
    // connection so a lazy-load firing there would actually be caught.
    DB::connection('pgsql')->enableQueryLog();
    $media->variantUrls();
    $log = DB::connection('pgsql')->getQueryLog();
    DB::connection('pgsql')->disableQueryLog();

    expect($log)->toHaveCount(0);
});
