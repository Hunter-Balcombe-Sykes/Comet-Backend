<?php

use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\InstagramActorDriver;
use App\Services\Cache\ApifyBudget;
use App\Services\Platforms\Actors\ApifyProfileScraperAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.instagram.actor', 'apify~instagram-profile-scraper');
    config()->set('partna.instagram.actor_adapters', [
        'apify~instagram-profile-scraper' => ApifyProfileScraperAdapter::class,
    ]);
    config()->set('partna.limits.apify.global_daily_cap', 100);
    config()->set('partna.limits.apify.actors.instagram', 100);
});

function igCtx(array $input = ['username' => 'maha', 'include_posts' => true], ?string $userId = 'user-1'): BilledEffectContext
{
    return new BilledEffectContext('actor', 'instagram', $input, 'run-1', 'source-1', $userId);
}

it('claims only its own (kind, name), leaving the menu actors unclaimed', function () {
    $driver = app(InstagramActorDriver::class);

    expect($driver->supports('actor', 'instagram'))->toBeTrue()
        ->and($driver->supports('actor', 'menu'))->toBeFalse()
        ->and($driver->supports('api', 'instagram'))->toBeFalse();
});

it('claims an Apify budget slot before spending', function () {
    // InstagramScraper claims only for its thin-profile retry; the real cap lives
    // in InstagramController, which the ingest lane never passes through. Without
    // this claim every scheduled run would spend outside the daily cap.
    config()->set('partna.limits.apify.actors.instagram', 0);
    Http::fake();

    expect(fn () => app(InstagramActorDriver::class)->run(igCtx()))
        ->toThrow(EffectNotAttempted::class);

    Http::assertNothingSent();
});

it('spends one budget slot for a healthy profile', function () {
    Http::fake(['api.apify.com/*' => Http::response([['username' => 'maha', 'postsCount' => 3, 'latestPosts' => [['shortCode' => 'A']]]], 201)]);

    $before = app(ApifyBudget::class)->remaining('instagram');
    app(InstagramActorDriver::class)->run(igCtx());

    expect(app(ApifyBudget::class)->remaining('instagram'))->toBe($before - 1);
});

it('returns the actor item as a one-item list, the shape the connector reads', function () {
    Http::fake(['api.apify.com/*' => Http::response([[
        'username' => 'maha',
        'fullName' => 'Maha',
        'postsCount' => 2,
        'latestPosts' => [['shortCode' => 'A'], ['shortCode' => 'B']],
    ]], 201)]);

    $result = app(InstagramActorDriver::class)->run(igCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toHaveCount(1)
        ->and($result->data[0]['username'])->toBe('maha')
        ->and($result->data[0]['latestPosts'])->toHaveCount(2);
});

it('normalises the username the same way the connector does', function () {
    Http::fake(['api.apify.com/*' => Http::response([['username' => 'maha', 'postsCount' => 1, 'latestPosts' => [['shortCode' => 'A']]]], 201)]);

    app(InstagramActorDriver::class)->run(igCtx(['username' => '  @MAHA ']));

    Http::assertSent(fn ($request) => $request['usernames'] === ['maha']);
});

it('treats a positively-reported missing handle as an answer with no data', function () {
    Http::fake(['api.apify.com/*' => Http::response([['username' => 'nope', 'error' => 'not_found']], 201)]);

    $result = app(InstagramActorDriver::class)->run(igCtx(['username' => 'nope']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toBeNull();
});

// ⚠️ ONE CAUSE PER TEST — Http::fake() merges stubs and the first match wins.
it('reports a 5xx from Apify as NoAnswer, not as an empty account', function () {
    Http::fake(['api.apify.com/*' => Http::response([], 503)]);

    expect(app(InstagramActorDriver::class)->run(igCtx())->outcome)->toBe(BilledEffectOutcome::NoAnswer);
});

it('reports a transport failure as NoAnswer', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect(app(InstagramActorDriver::class)->run(igCtx())->outcome)->toBe(BilledEffectOutcome::NoAnswer);
});

it('refuses without claiming budget when the token is missing', function () {
    config()->set('services.apify.token', null);
    Http::fake();

    $before = app(ApifyBudget::class)->remaining('instagram');

    expect(fn () => app(InstagramActorDriver::class)->run(igCtx()))
        ->toThrow(EffectNotAttempted::class);

    expect(app(ApifyBudget::class)->remaining('instagram'))->toBe($before);
    Http::assertNothingSent();
});

it('refuses without claiming budget when the configured actor has no adapter', function () {
    // The adapter is resolved deep inside InstagramScraper::attemptFetch(), AFTER
    // this driver would otherwise have claimed. A wrong actor id would then drain
    // the daily Apify cap doing nothing — checking only the token misses this.
    config()->set('partna.instagram.actor', 'someone~a-scraper-we-never-adapted');
    Http::fake();

    $before = app(ApifyBudget::class)->remaining('instagram');

    expect(fn () => app(InstagramActorDriver::class)->run(igCtx()))
        ->toThrow(EffectNotAttempted::class);

    expect(app(ApifyBudget::class)->remaining('instagram'))->toBe($before);
    Http::assertNothingSent();
});

it('spends a second slot when the profile comes back thin', function () {
    // fetchProfileResult() takes its own ApifyBudget claim for the thin retry, so a
    // thin profile costs TWO runs, not one. Correct — it is a second paid run — but
    // it must be visible rather than discovered from a cap that empties twice as
    // fast as expected.
    Http::fake(['api.apify.com/*' => Http::response([[
        'username' => 'maha',
        'postsCount' => 40,   // claims posts...
        'latestPosts' => [],  // ...and shipped none: thin, per isThinProfile()
    ]], 201)]);

    $before = app(ApifyBudget::class)->remaining('instagram');
    $result = app(InstagramActorDriver::class)->run(igCtx());

    expect(app(ApifyBudget::class)->remaining('instagram'))->toBe($before - 2)
        // Still an ANSWER: the profile stream lands real identity data, and the
        // media stream's post-less branch emits a Note with no Coverage, so nothing
        // is tombstoned. See the driver docblock and slice 1's open question.
        ->and($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data[0]['username'])->toBe('maha');
});

it('reports a missing username as NoAnswer without spending', function () {
    Http::fake();

    expect(app(InstagramActorDriver::class)->run(igCtx([]))->outcome)->toBe(BilledEffectOutcome::NoAnswer);

    Http::assertNothingSent();
});
