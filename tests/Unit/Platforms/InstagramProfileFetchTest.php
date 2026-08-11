<?php

use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\ProfileFetchFailure;
use App\Services\Platforms\ProfileFetchResult;
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

// ── Thin-profile predicate ───────────────────────────────────────────────────
// A 2xx profile can carry a name, a follower count and a picture while its post
// timeline is simply absent (@crucibletattooco, 2026-08-10 10:22 UTC — the same
// account returned 4,164 posts at 10:01 and again the next day). postsCount and
// latestPosts are the count and the contents of ONE upstream container, so they
// fail together: one signal, never two independent checks.

it('flags the observed fault: followers present, postsCount absent, no posts', function () {
    $thin = (new InstagramScraper)->isThinProfile([
        'username' => 'crucibletattooco',
        'fullName' => 'Crucible Tattoo Co.',
        'followersCount' => 30042,
        'businessCategoryName' => 'None',
        'private' => false,
        // postsCount and latestPosts both absent — the container never arrived.
    ]);

    expect($thin)->toBeTrue();
});

it('flags a self-contradicting profile that claims posts but ships none', function () {
    $thin = (new InstagramScraper)->isThinProfile([
        'username' => 'crucibletattooco',
        'followersCount' => 30042,
        'postsCount' => 4164,
        'latestPosts' => [],
        'private' => false,
    ]);

    expect($thin)->toBeTrue();
});

// The conservative half. A false positive tells a real prospect their build is
// broken; a false negative costs one thin site.
it('does NOT flag a genuinely empty account (postsCount 0 with no posts is self-consistent)', function () {
    $thin = (new InstagramScraper)->isThinProfile([
        'username' => 'brandnew',
        'followersCount' => 0,
        'postsCount' => 0,
        'latestPosts' => [],
        'private' => false,
    ]);

    expect($thin)->toBeFalse();
});

it('does NOT flag a sparse but real account', function () {
    // roberthuntercuts, live on dev: 3 followers, 1 post.
    $thin = (new InstagramScraper)->isThinProfile([
        'username' => 'roberthuntercuts',
        'followersCount' => 3,
        'postsCount' => 1,
        'latestPosts' => [['shortCode' => 'abc', 'displayUrl' => 'https://x/1.jpg']],
        'private' => false,
    ]);

    expect($thin)->toBeFalse();
});

it('does NOT flag a private account, which legitimately exposes no posts', function () {
    $thin = (new InstagramScraper)->isThinProfile([
        'username' => 'locked',
        'followersCount' => 500,
        'private' => true,
    ]);

    expect($thin)->toBeFalse();
});

it('does NOT flag a healthy profile', function () {
    $thin = (new InstagramScraper)->isThinProfile([
        'username' => 'simondoylehair',
        'followersCount' => 11065,
        'postsCount' => 365,
        'latestPosts' => array_fill(0, 12, ['shortCode' => 'x']),
        'private' => false,
    ]);

    expect($thin)->toBeFalse();
});

// businessCategoryName "None" is the NORMAL value for an account with no
// subvertical — the successful re-probe of crucibletattooco returns it, as do
// natgeo and hungryjacksau with complete data. It must never influence this.
it('ignores businessCategoryName entirely', function () {
    $thin = (new InstagramScraper)->isThinProfile([
        'username' => 'natgeo',
        'followersCount' => 268999742,
        'postsCount' => 31813,
        'latestPosts' => array_fill(0, 12, ['shortCode' => 'x']),
        'businessCategoryName' => 'None',
        'private' => false,
    ]);

    expect($thin)->toBeFalse();
});

it('treats an explicit error item as classify()\'s business, not thinness', function () {
    $thin = (new InstagramScraper)->isThinProfile([
        'username' => 'ghost',
        'error' => 'not_found',
    ]);

    expect($thin)->toBeFalse();
});

it('carries a thin flag on the result object, defaulting to false', function () {
    expect(ProfileFetchResult::ok(['username' => 'x'])->thin)->toBeFalse()
        ->and(ProfileFetchResult::ok(['username' => 'x'], thin: true)->thin)->toBeTrue()
        ->and(ProfileFetchResult::failed(ProfileFetchFailure::Transport)->thin)->toBeFalse();
});

// ── Thin retry ───────────────────────────────────────────────────────────────
// The fault is transient, so one retry is worth a paid actor run. Exactly one:
// a retry without a bound is an outage amplifier.

/** A complete profile the predicate accepts. */
function thinScrapeHealthyItem(): array
{
    return [
        'username' => 'crucibletattooco',
        'fullName' => 'Crucible Tattoo Co.',
        'followersCount' => 30041,
        'postsCount' => 4164,
        'private' => false,
        'latestPosts' => array_fill(0, 12, ['shortCode' => 'x', 'displayUrl' => 'https://x/1.jpg']),
    ];
}

/** The 2026-08-10 fault: header fields present, timeline absent. */
function thinScrapeThinItem(): array
{
    return [
        'username' => 'crucibletattooco',
        'fullName' => 'Crucible Tattoo Co.',
        'followersCount' => 30042,
        'businessCategoryName' => 'None',
        'private' => false,
    ];
}

it('retries once when the first scrape comes back thin, and reports the recovery', function () {
    Http::fake(['api.apify.com/*' => Http::sequence()
        ->push([thinScrapeThinItem()], 201)
        ->push([thinScrapeHealthyItem()], 201)]);

    $result = (new InstagramScraper)->fetchProfileResult('crucibletattooco');

    expect($result->thin)->toBeFalse()
        ->and($result->profile['postsCount'])->toBe(4164);
    Http::assertSentCount(2);
});

it('gives up after exactly one retry and reports the profile as thin', function () {
    Http::fake(['api.apify.com/*' => Http::sequence()
        ->push([thinScrapeThinItem()], 201)
        ->push([thinScrapeThinItem()], 201)
        ->push([thinScrapeHealthyItem()], 201)]);

    $result = (new InstagramScraper)->fetchProfileResult('crucibletattooco');

    expect($result->thin)->toBeTrue()
        ->and($result->profile['followersCount'])->toBe(30042);
    // Never a third call — the third fake response must go unused.
    Http::assertSentCount(2);
});

it('does not retry a healthy first scrape', function () {
    Http::fake(['api.apify.com/*' => Http::response([thinScrapeHealthyItem()], 201)]);

    $result = (new InstagramScraper)->fetchProfileResult('crucibletattooco');

    expect($result->thin)->toBeFalse();
    Http::assertSentCount(1);
});

// This class does not otherwise claim Apify budget — the controllers do
// (InstagramController:381, RefreshController:183). Without this gate the retry
// would spend paid runs the daily cap never sees.
it('skips the retry when the Apify daily cap is exhausted', function () {
    config(['partna.limits.apify.actors.instagram' => 0]);
    Http::fake(['api.apify.com/*' => Http::sequence()
        ->push([thinScrapeThinItem()], 201)
        ->push([thinScrapeHealthyItem()], 201)]);

    $result = (new InstagramScraper)->fetchProfileResult('crucibletattooco');

    expect($result->thin)->toBeTrue();
    Http::assertSentCount(1);
});

it('does not retry a hard failure — only thinness earns a second paid run', function () {
    Http::fake(['api.apify.com/*' => Http::response('', 500)]);

    $result = (new InstagramScraper)->fetchProfileResult('crucibletattooco');

    expect($result->failure)->toBe(ProfileFetchFailure::UpstreamError);
    Http::assertSentCount(1);
});
