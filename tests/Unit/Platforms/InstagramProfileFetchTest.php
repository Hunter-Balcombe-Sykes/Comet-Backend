<?php

use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\ProfileFetchFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Http::fake needs the Laravel test framework bootstrapped (mirrors
// tests/Unit/Platforms/InstagramScraperTest.php).
uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    config([
        'services.apify.token' => 'test-token',
        'partna.instagram.actor' => 'apify~instagram-profile-scraper',
    ]);
});

// ── Actor input shape ────────────────────────────────────────────────────────
// Each actor defines its OWN input schema. figue takes `profiles` +
// `includeRecentPosts`; apify takes `usernames`. Sending one actor the other's
// body is a hard 400 ("Field input.usernames is required", verified live
// 2026-08-10), which the scraper would then report as a failed scrape — i.e.
// changing PARTNA_INSTAGRAM_ACTOR alone would break EVERY Instagram build.
// The adapter is what keeps the actor id and its input shape in lockstep.

it('sends the Apify actor the "usernames" input shape it requires', function () {
    Http::fake(['api.apify.com/*' => Http::response([['username' => 'docpizza', 'fullName' => 'Doc Pizza']], 201)]);

    (new InstagramScraper)->fetchProfileResult('docpizza');

    Http::assertSent(fn ($request) => $request['usernames'] === ['docpizza'] && ! isset($request['profiles']));
});

it('sends the figue actor its own "profiles" + includeRecentPosts input shape', function () {
    config(['partna.instagram.actor' => 'figue~instagram-profile-scraper']);
    Http::fake(['api.apify.com/*' => Http::response([['username' => 'docpizza', 'full_name' => 'Doc Pizza']], 201)]);

    (new InstagramScraper)->fetchProfileResult('docpizza');

    Http::assertSent(fn ($request) => $request['profiles'] === ['docpizza']
        && $request['includeRecentPosts'] === true
        && ! isset($request['usernames']));
});

it('fails closed without calling Apify when the configured actor has no adapter', function () {
    config(['partna.instagram.actor' => 'someone~unregistered-actor']);
    Http::fake();

    $result = (new InstagramScraper)->fetchProfileResult('docpizza');

    expect($result->failure)->toBe(ProfileFetchFailure::NotConfigured)
        ->and($result->profile)->toBeNull();
    // The whole point: refuse to guess an input shape rather than send a body
    // the actor will reject.
    Http::assertNothingSent();
});

// ── Failure taxonomy ─────────────────────────────────────────────────────────
// fetchProfile() previously collapsed every failure mode into a bare null, so
// callers could not tell "this handle does not exist" (don't retry, don't pay
// for another scrape) from "the upstream scrape broke" (retryable).

it('classifies the Apify actor\'s not_found error item as a genuinely missing profile', function () {
    // Verified live 2026-08-10 against apify~instagram-profile-scraper.
    Http::fake(['api.apify.com/*' => Http::response([[
        'url' => 'https://www.instagram.com/ghost',
        'username' => 'ghost',
        'error' => 'not_found',
        'errorDescription' => 'Post does not exist',
    ]], 201)]);

    $result = (new InstagramScraper)->fetchProfileResult('ghost');

    expect($result->failure)->toBe(ProfileFetchFailure::ProfileNotFound)
        ->and($result->profile)->toBeNull();
});

it('classifies a non-not_found error item as an upstream failure, not a missing profile', function () {
    // 2026-08-10: Meta deleted an internal schema asset
    // (ig_business_category_subvertical), 400-ing its own logged-out profile
    // endpoint for accounts that resolve it. The account exists and is public;
    // only the upstream read is broken. Calling that "not found" tells a real
    // prospect their own Instagram account doesn't exist.
    Http::fake(['api.apify.com/*' => Http::response([[
        'username' => 'crucibletattooco',
        'full_name' => null,
        'error' => 'Could not retrieve profile data — please try again later',
    ]], 201)]);

    $result = (new InstagramScraper)->fetchProfileResult('crucibletattooco');

    expect($result->failure)->toBe(ProfileFetchFailure::ProfileUnavailable);
});

it('classifies a non-2xx Apify response as an upstream error', function () {
    Http::fake(['api.apify.com/*' => Http::response(['error' => 'nope'], 400)]);

    $result = (new InstagramScraper)->fetchProfileResult('docpizza');

    expect($result->failure)->toBe(ProfileFetchFailure::UpstreamError);
});

it('classifies a missing Apify token as a configuration failure', function () {
    config(['services.apify.token' => null]);
    Http::fake();

    $result = (new InstagramScraper)->fetchProfileResult('docpizza');

    expect($result->failure)->toBe(ProfileFetchFailure::NotConfigured);
    Http::assertNothingSent();
});

it('classifies a thrown HTTP client error as a transport failure', function () {
    Exceptions::fake(); // the catch block also calls report($e)
    Http::fake(['api.apify.com/*' => fn () => throw new ConnectionException('timeout')]);

    $result = (new InstagramScraper)->fetchProfileResult('docpizza');

    expect($result->failure)->toBe(ProfileFetchFailure::Transport);
});

it('classifies an empty or non-list dataset as a malformed payload', function () {
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);

    $result = (new InstagramScraper)->fetchProfileResult('docpizza');

    expect($result->failure)->toBe(ProfileFetchFailure::MalformedPayload);
});

it('returns the raw profile item with no failure on success', function () {
    Http::fake(['api.apify.com/*' => Http::response([['username' => 'docpizza', 'fullName' => 'Doc Pizza']], 201)]);

    $result = (new InstagramScraper)->fetchProfileResult('docpizza');

    expect($result->failure)->toBeNull()
        ->and($result->profile['fullName'])->toBe('Doc Pizza');
});

it('keeps fetchProfile() returning the bare item so existing callers are unaffected', function () {
    Http::fake(['api.apify.com/*' => Http::response([['username' => 'docpizza', 'fullName' => 'Doc Pizza']], 201)]);

    $profile = (new InstagramScraper)->fetchProfile('docpizza');

    expect($profile['fullName'])->toBe('Doc Pizza');
});
