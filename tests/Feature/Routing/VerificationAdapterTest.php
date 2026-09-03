<?php

use App\Routing\Verification\BooksyAdapter;
use App\Routing\Verification\LinkVerifier;
use App\Routing\Verification\PlainNotFoundAdapter;
use App\Routing\Verification\VerificationVerdict;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * B.6 — the adapters, pinned on the ONE thing that matters about them: which
 * HTTP outcomes are allowed to refuse a person's link.
 *
 * NotFound is the only verdict that refuses a save, so the whole safety
 * argument for this lane is that it is reachable from a definitive negative and
 * from nothing else. Every ambiguous outcome below must come back Blocked, and
 * Blocked means the link is saved and flagged.
 */
it('reads only a definitive negative as not-found', function (int $status, VerificationVerdict $expected) {
    Http::fake(['*' => Http::response('body', $status)]);

    expect(app(PlainNotFoundAdapter::class)->verify('https://github.com/someone'))->toBe($expected);
})->with([
    // The two the brand uses to say the page is gone.
    'a 404 is the page saying it is not there' => [404, VerificationVerdict::NotFound],
    'a 410 is the same, said more precisely' => [410, VerificationVerdict::NotFound],

    // Everything else is us failing to ask, not the brand answering.
    'a 200 is the page being there' => [200, VerificationVerdict::Found],
    'a 403 is a bot wall, not a missing page' => [403, VerificationVerdict::Blocked],
    'a 429 is a rate limit, not a missing page' => [429, VerificationVerdict::Blocked],
    'a 500 is their outage, not our answer' => [500, VerificationVerdict::Blocked],
    'a 301 that resolves nowhere useful is still not evidence' => [302, VerificationVerdict::Blocked],
]);

it('treats a transport failure as unable to check, never as absent', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(app(PlainNotFoundAdapter::class)->verify('https://github.com/someone'))
        ->toBe(VerificationVerdict::Blocked);
});

it('reads Booksy from where it landed, because its status line says nothing', function () {
    // Measured 2026-09-03: a deleted salon answers 200 after a redirect to the
    // search page, carrying its own marker in the query string.
    Http::fake([
        'booksy.com/en-us/99999901_*' => Http::response('', 302, [
            'Location' => 'https://booksy.com/en-us/s/hair-salon/134655_los-angeles?do=showBusinessDeletedModal',
        ]),
        '*' => Http::response('the search page', 200),
    ]);

    expect(app(BooksyAdapter::class)->verify('https://booksy.com/en-us/99999901_nope_hair-salon_134655_los-angeles'))
        ->toBe(VerificationVerdict::NotFound);
});

it('keeps a Booksy link that merely moved — a redirect without the marker is not a death certificate', function () {
    // Booksy also redirects for reasons we have NOT characterised. Reading a
    // bare redirect as "gone" would refuse the link of a salon still trading,
    // so the marker is required and its absence means keep.
    Http::fake([
        'booksy.com/en-us/904207_*' => Http::response('', 301, [
            'Location' => 'https://booksy.com/en-gb/904207_the-same-salon-elsewhere',
        ]),
        '*' => Http::response('a real salon page', 200),
    ]);

    expect(app(BooksyAdapter::class)->verify('https://booksy.com/en-us/904207_the-salon'))
        ->toBe(VerificationVerdict::Found);
});

it('registers an adapter only for brands whose behaviour was measured', function () {
    $verifier = app(LinkVerifier::class);

    // Verified live on 2026-09-03, real page and fabricated id side by side.
    foreach (['quandoo.reserve', 'github.profile', 'calendly.book', 'youtube.channel', 'x.profile', 'booksy.book'] as $key) {
        expect($verifier->canVerify($key))->toBeTrue($key);
    }

    // Tested the same day and REJECTED — these must NOT acquire an adapter
    // without new evidence, because a wrong adapter refuses real links:
    //   spotify   200 for a fabricated artist id, within 60 bytes of the real
    //             page. An earlier note filed it as definitive; it is not.
    //   resy      byte-identical 5,177-byte SPA shell for both. It does answer
    //             404 — but only to a crawler UA, which means claiming to be
    //             Googlebot. That is a decision about how we present ourselves
    //             to other people's servers, not an implementation detail.
    //   instagram/facebook/tiktok/opentable  200 on fabricated handles.
    foreach (['spotify.player', 'resy.reserve', 'instagram.profile', 'opentable.reserve'] as $key) {
        expect($verifier->canVerify($key))->toBeFalse($key);
    }
});

it('answers Blocked for a brand it has no adapter for, rather than guessing', function () {
    expect(app(LinkVerifier::class)->verify('doordash.order', 'https://www.doordash.com/store/x'))
        ->toBe(VerificationVerdict::Blocked);
});
