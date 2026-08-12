<?php

use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\PlacesDetailsDriver;
use Illuminate\Support\Facades\Http;

// Slice 1b D2. A Places photo ref is reissued on EVERY Details fetch, so a ref
// and a url are only consistent within one fetch — there is no join key between
// a ref stored yesterday and a url resolved today. That is why resolution has
// to happen here, in the same billed call that mints the refs, and why the
// parent spec's "read urls from the legacy payload" is not implementable.

beforeEach(function () {
    config()->set('services.google_maps.server_api_key', 'test-key');
    config()->set('partna.limits.places.details_max_attempts', 1);
    config()->set('partna.limits.places.global_daily_cap', 100);
    config()->set('partna.limits.places.skus.details', 100);
    config()->set('partna.limits.places.skus.photos', 100);
    config()->set('partna.limits.places.per_user_daily_cap', 100);
});

/** Details on one pattern, photo media on another; the media url embeds the ref. */
function fakePlaces(array $details, bool $resolvePhotos = true): void
{
    Http::fake(function ($request) use ($details, $resolvePhotos) {
        if (str_contains($request->url(), '/media')) {
            if (! $resolvePhotos) {
                return Http::response([], 500);
            }
            preg_match('#/photos/([A-Za-z0-9_-]+)/media#', $request->url(), $m);

            return Http::response(['photoUri' => 'https://lh3.googleusercontent.com/place-photos/'.($m[1] ?? 'x')], 200);
        }

        return Http::response($details, 200);
    });
}

function runDetails(string $placeId = 'ChIJtest'): mixed
{
    return app(PlacesDetailsDriver::class)->run(new BilledEffectContext(
        kind: 'api',
        name: 'places.details',
        input: ['place_id' => $placeId],
        runId: null,
        sourceId: null,
        userId: 'user-1',
    ));
}

it('returns photos carrying resolved urls alongside their refs', function () {
    fakePlaces([
        'displayName' => ['text' => 'Test Cafe'],
        'photos' => [
            ['name' => 'places/ChIJtest/photos/AWCwydone', 'widthPx' => 4032, 'heightPx' => 3024],
            ['name' => 'places/ChIJtest/photos/AWCwydtwo', 'widthPx' => 800, 'heightPx' => 600],
        ],
    ]);

    $result = runDetails();

    expect($result->data['photos'][0]['url'])->toBe('https://lh3.googleusercontent.com/place-photos/AWCwydone')
        ->and($result->data['photos'][0]['name'])->toBe('places/ChIJtest/photos/AWCwydone')
        ->and($result->data['photos'][1]['url'])->toBe('https://lh3.googleusercontent.com/place-photos/AWCwydtwo');
});

it('returns the photo without a url when resolution fails, and does not fail the effect', function () {
    // A photo without a servable url is an already-supported state — the frame
    // is omitted downstream. Failing the whole Details effect over one dead
    // photo would throw away the profile and reviews streams too.
    fakePlaces([
        'displayName' => ['text' => 'Test Cafe'],
        'photos' => [['name' => 'places/ChIJtest/photos/AWCwydone']],
    ], resolvePhotos: false);

    $result = runDetails();

    expect($result->data['photos'][0])->not->toHaveKey('url')
        ->and($result->data['displayName']['text'])->toBe('Test Cafe');
});

it('rides the existing effect — exactly one Details call, photos on the media endpoint', function () {
    // D2: photo resolution must NOT become a second billed Details fetch. A
    // separate effect kind would change the digest and split profile/reviews
    // from media into two Details calls at $25/1000 each, to isolate photo
    // calls that cost $7/1000.
    fakePlaces([
        'displayName' => ['text' => 'Test Cafe'],
        'photos' => [['name' => 'places/ChIJtest/photos/AWCwydone']],
    ]);

    runDetails();

    $details = 0;
    $media = 0;
    foreach (Http::recorded() as [$request, $response]) {
        str_contains($request->url(), '/media') ? $media++ : $details++;
    }

    expect($details)->toBe(1)
        ->and($media)->toBe(1);
});

it('leaves photos unresolved when the photos budget is exhausted, and still answers', function () {
    // The photos SKU is claimed per photo BEFORE each request fires. A capped
    // day must degrade to ref-only photos, never to a failed effect — the
    // profile and reviews streams ride the same call.
    config()->set('partna.limits.places.skus.photos', 0);
    fakePlaces([
        'displayName' => ['text' => 'Test Cafe'],
        'photos' => [['name' => 'places/ChIJtest/photos/AWCwydone']],
    ]);

    $result = runDetails();

    expect($result->data['photos'][0])->not->toHaveKey('url')
        ->and($result->data['displayName']['text'])->toBe('Test Cafe');

    foreach (Http::recorded() as [$request, $response]) {
        expect($request->url())->not->toContain('/media');
    }
});
