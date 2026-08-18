<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\FreshaScraper;
use Tests\TestCase;

// tests/Unit is NOT bound to TestCase in Pest.php — resolving FreshaScraper
// needs the container, so opt in per-file as the other Unit/Platforms tests do.
uses(TestCase::class)->in(__FILE__);

// The three write paths that pre-date link routing all canonicalise before they
// store (RouteContextOriginTest pins two of them). The routing lane does not:
// SourceReconciler and SuggestionApplier write `intent.canonical_url` verbatim,
// and that is whatever the owner's link-in-bio actually held — for Fresha, the
// share URL. So the READ side has to tolerate a stored `/book-now/…` too, or a
// booking that now connects cannot be scraped once connected.

function nextDataPage(string $storeName): string
{
    $payload = json_encode(['props' => ['pageProps' => ['data' => ['location' => ['name' => $storeName]]]]]);

    return '<html><body><script id="__NEXT_DATA__" type="application/json">'.$payload.'</script></body></html>';
}

it('reads the salon slug out of a stored share url', function () {
    expect(app(FreshaScraper::class)->slugFromUrl(
        'https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?pId=2835260'
    ))->toBe('anseo-studio-v0v92jna');
});

it('still reads the slug out of a canonical url', function () {
    expect(app(FreshaScraper::class)->slugFromUrl(
        'https://www.fresha.com/a/anseo-studio-v0v92jna'
    ))->toBe('anseo-studio-v0v92jna');
});

it('fetches the canonical page when handed a stored share url', function () {
    // The share URL is a different Next.js route: its __NEXT_DATA__ carries no
    // `props.pageProps.data.location`, so scraping it verbatim yields an empty
    // menu and FreshaFetch raises fresha_no_services.
    $fetched = null;
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('fetch')
        ->once()
        ->andReturnUsing(function (string $url) use (&$fetched) {
            $fetched = $url;

            return ['status' => 200, 'body' => nextDataPage('Anseo Studio')];
        });
    app()->instance(SafeUrlFetcher::class, $fetcher);

    $location = app(FreshaScraper::class)->fetchLocation(
        'https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?pId=2835260'
    );

    expect($fetched)->toBe('https://www.fresha.com/a/anseo-studio-v0v92jna')
        ->and($location['name'])->toBe('Anseo Studio');
});

it('leaves a canonical url alone when fetching', function () {
    $fetched = null;
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('fetch')
        ->once()
        ->andReturnUsing(function (string $url) use (&$fetched) {
            $fetched = $url;

            return ['status' => 200, 'body' => nextDataPage('Anseo Studio')];
        });
    app()->instance(SafeUrlFetcher::class, $fetcher);

    app(FreshaScraper::class)->fetchLocation('https://www.fresha.com/a/anseo-studio-v0v92jna');

    expect($fetched)->toBe('https://www.fresha.com/a/anseo-studio-v0v92jna');
});
