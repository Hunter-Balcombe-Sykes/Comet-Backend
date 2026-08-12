<?php

// Slice 1b. The Google media projector was dropping two things the pool needs:
// the servable url (D2 — resolved in the same billed fetch, because a ref and a
// url are only consistent within one fetch) and the photographer credit (D6 —
// required on display by the Places terms).

use App\Ingest\Projection\GoogleBusinessMediaProjector;
use App\Ingest\Projection\RecordView;

it('projects url and attribution onto the media entry', function () {
    $projected = (new GoogleBusinessMediaProjector)->project(new RecordView([
        'ref' => 'places/ChIJtest/photos/AWCwydtoken',
        'url' => 'https://lh3.googleusercontent.com/place-photos/AG9NLjtest=s4800-w1200',
        'width_px' => 4032,
        'height_px' => 3024,
        'attribution' => ['authors' => [['name' => 'Jo Rivera', 'uri' => null]]],
    ]));

    expect($projected['media'][0]['url'])->toBe('https://lh3.googleusercontent.com/place-photos/AG9NLjtest=s4800-w1200')
        ->and($projected['media'][0]['ref'])->toBe('places/ChIJtest/photos/AWCwydtoken')
        ->and($projected['media'][0]['attribution']['authors'][0]['name'])->toBe('Jo Rivera')
        ->and($projected['media'][0]['role'])->toBe('gallery');
});

it('leaves the headline null by contract', function () {
    // D7: a photo does not need a headline. Asserted so a later "fix" cannot
    // quietly reintroduce a synthetic one.
    $projected = (new GoogleBusinessMediaProjector)->project(new RecordView([
        'ref' => 'places/ChIJtest/photos/AWCwydtoken',
    ]));

    expect($projected['headline'])->toBeNull();
});

it('projects without url or attribution when Google supplied neither', function () {
    $projected = (new GoogleBusinessMediaProjector)->project(new RecordView([
        'ref' => 'places/ChIJtest/photos/AWCwydtoken',
    ]));

    expect($projected['media'][0])->not->toHaveKey('url')
        ->and($projected['media'][0])->not->toHaveKey('attribution');
});

it('still emits no keyed url — the resolved lh3 link carries no api key', function () {
    // The guard the pre-1b projector existed to provide, restated now that a
    // url IS emitted: resolvePhotoUrls() follows the redirect and stores the
    // unkeyed lh3 location, so a Places api key must never reach a content row.
    $projected = (new GoogleBusinessMediaProjector)->project(new RecordView([
        'ref' => 'places/ChIJtest/photos/AWCwydtoken',
        'url' => 'https://lh3.googleusercontent.com/place-photos/AG9NLjtest=s4800-w1200',
    ]));

    expect(json_encode($projected))->not->toContain('key=');
});

it('bumps its version so rows written before and after are distinguishable', function () {
    // content.source_items.projector_version records which shape produced a
    // row. Leaving this at 1 would make pre- and post-1b media rows identical
    // on the wire and un-diagnosable when a url is missing.
    expect(GoogleBusinessMediaProjector::version())->toBe(2);
});
