<?php

use App\Services\Platforms\FreshaPage;

function freshaRecordedPage(string $name): string
{
    return file_get_contents(dirname(__DIR__, 2).'/fixtures/recorded/fresha/'.$name);
}

/** The venue-page location blob, hand-built: the recorded fixtures are book-now pages and carry none (see Task 1 Step 1 note). */
function freshaLocationBlob(): array
{
    return [
        'name' => 'Anseo Studio',
        'contactNumber' => '+61 3 9999 0000',
        'countryCode' => 'AU',
        'address' => [
            'streetAddress' => '140a Chapel Street',
            'cityName' => 'Windsor',
            'postalCode' => '3181',
            'region1' => 'VIC',
            'countryCode' => 'AU',
            'latitude' => -37.8551,
            'longitude' => 144.9928,
            'mapsUrl' => 'https://maps.google.com/?q=-37.8551,144.9928',
        ],
        'employeeProfiles' => ['edges' => [
            ['node' => [
                'employeeId' => 'emp-1',
                'displayName' => 'Simon',
                'jobTitle' => 'Stylist',
                'avatar' => ['url' => 'https://cdn.fresha.com/simon.jpg'],
                'rating' => 4.8,
            ]],
        ]],
    ];
}

it('strips the locale segment from a canonical url', function () {
    expect(FreshaPage::stripLocale('https://www.fresha.com/en-GB/a/anseo-studio-v0v92jna'))
        ->toBe('https://www.fresha.com/a/anseo-studio-v0v92jna');
    expect(FreshaPage::stripLocale('https://www.fresha.com/a/anseo-studio-v0v92jna'))
        ->toBe('https://www.fresha.com/a/anseo-studio-v0v92jna');
});

it('canonicalises a share url and leaves a canonical one alone', function (string $in, string $out) {
    expect(FreshaPage::canonicalUrl($in))->toBe($out);
})->with([
    ['https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?share=true&pId=2835260', 'https://www.fresha.com/a/anseo-studio-v0v92jna'],
    ['https://fresha.com/book-now/anseo-studio-v0v92jna/all-offer', 'https://www.fresha.com/a/anseo-studio-v0v92jna'],
    ['https://www.fresha.com/a/anseo-studio-v0v92jna', 'https://www.fresha.com/a/anseo-studio-v0v92jna'],
]);

it('reads the slug from both url shapes, and refuses a foreign host', function (string $url, ?string $slug) {
    expect(FreshaPage::slugFromUrl($url))->toBe($slug);
})->with([
    ['https://www.fresha.com/a/anseo-studio-v0v92jna', 'anseo-studio-v0v92jna'],
    ['https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?pId=2835260', 'anseo-studio-v0v92jna'],
    ['https://www.fresha.com/en-GB/a/anseo-studio-melbourne-w8ajp04r/booking?menu=true', 'anseo-studio-melbourne-w8ajp04r'],
    ['https://example.com/?next=https://www.fresha.com/a/anseo-studio-v0v92jna', null],
    ['https://www.fresha.com/', null],
]);

it('builds the share probe url', function () {
    expect(FreshaPage::shareProbeUrl('anseo-studio-v0v92jna'))
        ->toBe('https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer');
});

it('pulls __NEXT_DATA__ out of a real recorded fresha page', function () {
    $data = FreshaPage::parseNextData(freshaRecordedPage('venue.book-now.html'));

    expect($data)->toBeArray()
        ->and($data['buildId'] ?? null)->toBeString()
        ->and(FreshaPage::nextDataJson(freshaRecordedPage('venue.book-now.html')))->toBeString();
});

// This is WHY canonicalUrl() exists: the share URL is a different Next.js
// route whose __NEXT_DATA__ carries no location blob, so scraping a stored
// share URL verbatim yields an empty menu (-> fresha_no_services).
it('proves the book-now route carries no location blob', function (string $file) {
    $data = FreshaPage::parseNextData(freshaRecordedPage($file));

    expect($data)->toBeArray()
        ->and(FreshaPage::locationFrom($data))->toBeNull();
})->with(['venue.book-now.html', 'venue.book-now-hair.html']);

it('returns null for a page with no __NEXT_DATA__ and for undecodable json', function () {
    expect(FreshaPage::nextDataJson('<html><body>nope</body></html>'))->toBeNull()
        ->and(FreshaPage::parseNextData('<html><body>nope</body></html>'))->toBeNull()
        ->and(FreshaPage::parseNextData('<script id="__NEXT_DATA__">{not json</script>'))->toBeNull();
});

it('reads the location blob when one is present', function () {
    $body = '<script id="__NEXT_DATA__" type="application/json">'
        .json_encode(['props' => ['pageProps' => ['data' => ['location' => ['name' => 'Anseo Studio']]]]])
        .'</script>';

    expect(FreshaPage::locationFrom(FreshaPage::parseNextData($body)))->toBe(['name' => 'Anseo Studio']);
});

it('extracts the store name, or null when absent or empty', function () {
    expect(FreshaPage::extractStoreName(freshaLocationBlob()))->toBe('Anseo Studio')
        ->and(FreshaPage::extractStoreName(['name' => '']))->toBeNull()
        ->and(FreshaPage::extractStoreName([]))->toBeNull();
});

it('extracts the venue identity, and null when there is no address', function () {
    expect(FreshaPage::extractVenue(freshaLocationBlob()))->toBe([
        'name' => 'Anseo Studio',
        'street' => '140a Chapel Street',
        'city' => 'Windsor',
        'postcode' => '3181',
        'region' => 'VIC',
        'country' => 'AU',
        'lat' => -37.8551,
        'lng' => 144.9928,
        'phone' => '+61 3 9999 0000',
        'mapsUrl' => 'https://maps.google.com/?q=-37.8551,144.9928',
    ]);

    expect(FreshaPage::extractVenue(['name' => 'Anseo Studio']))->toBeNull();
});

it('extracts the team, and an empty list when the edges are missing', function () {
    expect(FreshaPage::extractTeam(freshaLocationBlob()))->toBe([[
        'employeeId' => 'emp-1',
        'displayName' => 'Simon',
        'jobTitle' => 'Stylist',
        'avatarUrl' => 'https://cdn.fresha.com/simon.jpg',
        'rating' => 4.8,
    ]]);

    expect(FreshaPage::extractTeam([]))->toBe([])
        ->and(FreshaPage::extractTeam(['employeeProfiles' => ['edges' => 'nope']]))->toBe([]);
});

// The shape both lanes fire at Fresha. Pinned key-for-key because the two
// used to be hand-synced from a docblock instruction.
it('builds the booking-flow persisted-query payload both lanes send', function () {
    $payload = FreshaPage::bookingFlowPayload('anseo-studio-v0v92jna', 'emp-1', 'abc123', '1.2.3');

    expect($payload['operationName'])->toBe('BookingFlow_Initialize_Mutation')
        ->and($payload['variables']['input']['locationSlug'])->toBe('anseo-studio-v0v92jna')
        ->and($payload['variables']['input']['options']['employeeId'])->toBe('emp-1')
        // The picker screen has an empty screenServices — never send true.
        ->and($payload['variables']['input']['options']['shouldShowAllEmployees'])->toBeFalse()
        ->and($payload['variables']['input']['capabilities'])
        ->toBe(['SERVICE_ADDONS', 'CONFIRMATION', 'FULL_UPFRONT_PAYMENT', 'MARKETPLACE_REFRESH'])
        ->and($payload['extensions']['persistedQuery'])->toBe(['version' => 1, 'sha256Hash' => 'abc123'])
        ->and($payload['extensions']['version'])->toBe('1.2.3');
});

it('sends a null employeeId for the storewide menu', function () {
    expect(FreshaPage::bookingFlowPayload('slug', null, 'h', 'v')['variables']['input']['options']['employeeId'])
        ->toBeNull();
});
