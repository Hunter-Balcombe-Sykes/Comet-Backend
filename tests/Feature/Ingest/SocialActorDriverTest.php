<?php

// T27c (2026-08-28): the shared TikTok/Facebook Apify driver — spend-shape
// guarantees: refusals before the claim, proof-key filtering, empty ⇒ noAnswer.

use App\Exceptions\Platforms\VendorAccountFaultException;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\SocialActorDriver;
use App\Services\Cache\ApifyBudget;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

function socialCtx(string $name, array $input): BilledEffectContext
{
    return new BilledEffectContext('actor', $name, $input, 'run-1', 'source-1', 'user-1');
}

function socialDriver(): SocialActorDriver
{
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.limits.apify.global_daily_cap', 100);
    config()->set('partna.limits.apify.actors.tiktok', 10);
    config()->set('partna.limits.apify.actors.facebook', 10);

    return app(SocialActorDriver::class);
}

it('supports exactly the two social actor names', function () {
    $driver = socialDriver();

    expect($driver->supports('actor', 'tiktok'))->toBeTrue()
        ->and($driver->supports('actor', 'facebook'))->toBeTrue()
        ->and($driver->supports('actor', 'instagram'))->toBeFalse()
        ->and($driver->supports('effect', 'tiktok'))->toBeFalse();
});

it('refuses before claiming when the token is missing', function () {
    $driver = socialDriver();
    config()->set('services.apify.token', '');
    Http::fake();

    expect(fn () => $driver->run(socialCtx('tiktok', ['username' => 'x'])))
        ->toThrow(EffectNotAttempted::class);
    Http::assertNothingSent();
    expect(app(ApifyBudget::class)->remaining('tiktok'))->toBe(10);
});

it('answers only proof-bearing rows and posts the bounded input envelope', function () {
    $driver = socialDriver();
    config()->set('partna.social_actors.tiktok.results_limit', 5);
    Http::fake(['api.apify.com/*' => Http::response([
        ['id' => '71', 'text' => 'a'],
        ['error' => 'notice row'],
        ['id' => '72', 'text' => 'b'],
    ], 201)]);

    $result = $driver->run(socialCtx('tiktok', ['username' => '@Someone']));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and(array_column($result->data, 'id'))->toBe(['71', '72']);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'clockworks~tiktok-profile-scraper/run-sync-get-dataset-items')
        && $request['profiles'] === ['someone']
        && $request['resultsPerPage'] === 5);
});

it('treats an empty dataset as noAnswer, never as an empty account', function () {
    $driver = socialDriver();
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);

    $result = $driver->run(socialCtx('facebook', ['page_url' => 'https://www.facebook.com/nasa']));

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
});

it('reads a 404 as an account/actor fault, not a verdict on the profile', function () {
    Exceptions::fake();
    $driver = socialDriver();
    Http::fake(['api.apify.com/*' => Http::response(['error' => 'actor not rented'], 404)]);

    $result = $driver->run(socialCtx('facebook', ['page_url' => 'https://www.facebook.com/nasa']));

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
    Exceptions::assertReported(fn (VendorAccountFaultException $e) => $e->status === 404
        && $e->reason === 'actor:facebook');
});

it('reports an account fault (throttled) for each of 401/402/403/404, without changing the noAnswer outcome', function (int $status) {
    Exceptions::fake();
    $driver = socialDriver();
    Http::fake(['api.apify.com/*' => Http::response(['error' => 'nope'], $status)]);

    $result = $driver->run(socialCtx('tiktok', ['username' => 'someone']));

    // Pinning that the report did not change the effect outcome.
    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
    Exceptions::assertReported(fn (VendorAccountFaultException $e) => $e->vendor === 'apify' && $e->status === $status);
})->with([401, 402, 403, 404]);

it('throttles repeated account-fault reports to one per actor+status across two runs', function () {
    Exceptions::fake();
    $driver = socialDriver();
    Http::fake(['api.apify.com/*' => Http::response(['error' => 'nope'], 401)]);

    $driver->run(socialCtx('tiktok', ['username' => 'someone']));
    $driver->run(socialCtx('tiktok', ['username' => 'someone-else']));

    Exceptions::assertReportedCount(1);
});

it('refuses a facebook effect whose page_url is not facebook.com', function () {
    $driver = socialDriver();
    Http::fake();

    $result = $driver->run(socialCtx('facebook', ['page_url' => 'https://evil.example/nasa']));

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
    Http::assertNothingSent();
});
