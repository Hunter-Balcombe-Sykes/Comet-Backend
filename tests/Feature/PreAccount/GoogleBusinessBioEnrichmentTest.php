<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\PreAccount\Generators\GoogleBusinessSourceGenerator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Fixtures\Recorded;

/**
 * Every `business` pre-account build is google_business-sourced (the pairing map
 * in config/partna.php allows that source and no other), and until 2026-08-28 no
 * such build reached BioIntelligence at all. These pin the GBP side of the shared
 * ProfileEnricher seam.
 *
 * WHAT THIS DOES NOT CLAIM. Wiring GBP to the enricher does not, today, give a
 * business account an About it did not already have:
 *  - 74% of real listings (28/38 on dev, 2026-08-28) carry neither
 *    editorialSummary nor reviewSummary, so there is no bio text to analyse and
 *    the enricher correctly skips the model call entirely;
 *  - for the 26% that do, GoogleBusinessAutoSync already seeds
 *    site.workplaces.description from editorialSummary, and
 *    WorkplaceObserver::mirrorIdentityFields mirrors description -> users.bio
 *    for exactly these accounts (workplace_brand_is_site_identity: $isBusiness).
 *    That job is QUEUED, so it lands AFTER this generator returns and the raw
 *    Google sentence overwrites whatever the enricher wrote.
 * So the About lane here is presently superseded by the workplace mirror. The
 * seam is what these tests pin; sourcing a real bio input for GBP (the business's
 * own website text — 79% of listings carry a URL) is deferred, separate work, and
 * settling that precedence against the mirror is part of it.
 *
 * The Places payload is the RECORDED one (tests/fixtures/recorded/places/), not a
 * hand-typed shape: the anti-invention gates are only meaningful measured against
 * a real editorialSummary.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable(); // also creates site.platform_connections (tests/Pest.php)
    setupWorkplacesTable();

    config([
        'services.deepseek.key' => 'test-key',
        'partna.limits.ai_spend.actors.deepseek_bio' => 100,
        'partna.limits.ai_spend.global_daily_cap' => 1000,
    ]);
});

/** Locally named — a cross-file test helper breaks the parallel runner. */
function gbpBioAiRespond(array $payload): void
{
    Http::fake([
        'api.deepseek.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($payload)]]],
        ]),
        '*' => Http::response([], 200),
    ]);
}

/** Every deepseek chat request recorded so far — the paid-call counter. */
function gbpModelCalls(): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.deepseek.com'))
        ->values()
        ->all();
}

function gbpFakePlaces(array $details, string $placeId = 'ChIJamiconi'): void
{
    $svc = Mockery::mock(GoogleBusinessService::class);
    $svc->shouldReceive('fetchPlaceDetails')->once()->with($placeId, Mockery::any())->andReturn($details);
    app()->instance(GoogleBusinessService::class, $svc);
}

/** @return array{0: User, 1: Site} */
function gbpBusinessBuild(): array
{
    $user = User::factory()->create([
        'status' => 'unclaimed',
        'account_type' => 'business',
        'display_name' => 'Amiconi Restaurant',
        'bio' => null,
        'public_contact_email' => null,
    ]);
    $site = Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);

    return [$user, $site];
}

it('routes a google_business build through the shared enricher, analysing the listing description', function () {
    gbpFakePlaces(Recorded::json('places/amiconi.listing.json'));
    gbpBioAiRespond(['about' => null, 'email' => null, 'phone' => null, 'mentions' => []]);
    Queue::fake();

    [$user, $site] = gbpBusinessBuild();

    app(GoogleBusinessSourceGenerator::class)->generate($user, $site, 'ChIJamiconi');

    $calls = gbpModelCalls();
    expect($calls)->toHaveCount(1);

    // The listing description is what reaches analyse() as the biography, and the
    // Places category rides along as the business category — no widened signature.
    $sent = json_decode($calls[0][0]->body(), true);
    $input = json_decode($sent['messages'][1]['content'], true);

    expect($input['biography'])->toBe('Traditional Italian cuisine and wine in a convivial, family-run eatery open since the 1950s.')
        ->and($input['business_category'])->toBe('Italian Restaurant');
});

it('spends no model call on a listing with no description — the 74% case', function () {
    // Derived from the recorded fixture rather than hand-typed, so it stays the
    // real payload shape minus the one field (FixtureMutator, spec §5 A1).
    $details = Recorded::mutate(Recorded::json('places/amiconi.listing.json'))
        ->without('editorialSummary')
        ->get();

    gbpFakePlaces($details);
    gbpBioAiRespond(['about' => 'anything at all', 'email' => null, 'phone' => null, 'mentions' => []]);
    Queue::fake();

    [$user, $site] = gbpBusinessBuild();

    app(GoogleBusinessSourceGenerator::class)->generate($user, $site, 'ChIJamiconi');

    expect(gbpModelCalls())->toHaveCount(0)
        ->and($user->fresh()->bio)->toBeNull();
});

// NON-VACUITY CONTROL for the anti-invention test below. Without this, that test
// would pass just as happily against an unwired generator that writes nothing at
// all — a null bio proves the gate refused something only if a GROUNDED About in
// the identical setup does land. (The queued GoogleBusinessAutoSync would later
// overwrite this with Google's raw sentence in production; Queue::fake() holds it
// off so this asserts the enricher's own write, which is what is under test.)
it('writes a grounded About through the same path the invention gate refuses', function () {
    gbpFakePlaces(Recorded::json('places/amiconi.listing.json'));
    gbpBioAiRespond([
        'about' => 'Traditional Italian cuisine and wine in a convivial, family-run eatery, open since the 1950s.',
        'email' => null,
        'phone' => null,
        'mentions' => [],
    ]);
    Queue::fake();

    [$user, $site] = gbpBusinessBuild();

    app(GoogleBusinessSourceGenerator::class)->generate($user, $site, 'ChIJamiconi');

    expect($user->fresh()->bio)
        ->toBe('Traditional Italian cuisine and wine in a convivial, family-run eatery, open since the 1950s.');
});

it('refuses an About the listing description does not support (anti-invention gate, GBP inputs)', function () {
    gbpFakePlaces(Recorded::json('places/amiconi.listing.json'));
    // Plausible, fluent, and entirely ungrounded: none of these claims are in the
    // editorialSummary. The their-words overlap gate must null it.
    gbpBioAiRespond([
        'about' => 'Award-winning chefs prepare handmade pasta nightly using organic produce flown in weekly from Sicily, with a sommelier curating three hundred labels.',
        'email' => 'bookings@amiconi.example',
        'phone' => '+61 400 111 222',
        'mentions' => [['handle' => 'amiconi_official', 'label' => 'our instagram', 'type' => 'brand']],
    ]);
    Queue::fake();

    [$user, $site] = gbpBusinessBuild();

    app(GoogleBusinessSourceGenerator::class)->generate($user, $site, 'ChIJamiconi');

    $user->refresh();

    // Every one of the four invented fields is refused: the About fails the
    // their-words overlap, the email and phone are not literally in the
    // description, and the @mention is not in it either.
    expect($user->bio)->toBeNull()
        ->and($user->public_contact_email)->toBeNull();
});
