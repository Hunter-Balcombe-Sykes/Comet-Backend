<?php

use App\Ingest\Projection\BandcampReleaseProjector;
use App\Ingest\Projection\RecordView;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Tier P: vendor JSON in, structure out. No network, no database, no clock —
// if one of these ever needs setup, the projector stopped being pure.

it('projects a landed release into the typed release shape', function () {
    $view = new RecordView([
        'title' => 'Second Record',
        'url' => 'https://someartist.bandcamp.com/album/second-record',
        'artist' => 'Some Artist',
        'release_date' => '2025-02-02',
        'art_url' => 'https://f4.bcbits.com/img/a222_10.jpg',
        'type' => 'album',
    ]);

    $projected = (new BandcampReleaseProjector)->project($view);

    expect($projected['kind'])->toBe('release')
        ->and($projected['headline'])->toBe('Second Record')
        ->and($projected['facets']['f_link']['url'])->toBe('https://someartist.bandcamp.com/album/second-record')
        ->and($projected['facets']['f_published']['at'])->toBe('2025-02-02')
        ->and($projected['facets']['f_authored']['creator'])->toBe('Some Artist')
        ->and($projected['media'])->toHaveCount(1)
        ->and($projected['media'][0]['role'])->toBe('cover');
});

it('projects nothing rather than a nameless item', function () {
    // Reaching this means the record changed shape after landing; producing a
    // blank card would be worse than producing nothing.
    expect((new BandcampReleaseProjector)->project(new RecordView(['url' => 'https://x.bandcamp.com/album/y'])))->toBeNull()
        ->and((new BandcampReleaseProjector)->project(new RecordView(['title' => 'Untitled'])))->toBeNull();
});

it('omits media rather than emitting an empty cover', function () {
    $projected = (new BandcampReleaseProjector)->project(new RecordView([
        'title' => 'No Art', 'url' => 'https://x.bandcamp.com/album/no-art',
    ]));

    expect($projected['media'])->toBe([]);
});

it('records every path a projector consulted', function () {
    // This is what makes the volatility audit possible: a path that is both
    // declared volatile AND read here is a silent correctness hole.
    $view = new RecordView(['title' => 'A', 'url' => 'https://x.bandcamp.com/album/a']);
    (new BandcampReleaseProjector)->project($view);

    expect($view->reads())->toContain('title', 'url', 'release_date', 'artist', 'art_url', 'type');
});

it('reads missing and wrongly-typed paths as absent rather than throwing', function () {
    $view = new RecordView(['count' => 'not-a-number', 'nested' => ['deep' => 'value']]);

    expect($view->string('missing'))->toBeNull()
        ->and($view->int('count'))->toBeNull()
        ->and($view->string('nested.deep'))->toBe('value')
        ->and($view->string('nested.missing'))->toBeNull()
        ->and($view->list('nested'))->toBe(['value'])
        ->and($view->has('nested.deep'))->toBeTrue()
        ->and($view->has('nope'))->toBeFalse();
});

it('is versioned so a changed projector can trigger a rebuild', function () {
    expect(BandcampReleaseProjector::version())->toBeInt()
        ->and(BandcampReleaseProjector::kind())->toBe('release');
});
