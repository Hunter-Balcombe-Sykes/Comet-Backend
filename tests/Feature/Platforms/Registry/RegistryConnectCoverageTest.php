<?php

use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Strategies\Contracts\DeferredConnect;

it('pins the descriptor-driven connect contract for every reducible platform', function () {
    $registry = app(PlatformRegistry::class);

    $expected = [
        'bandcamp' => ['url', ['required', 'string', 'max:500'], [], false],
        'eventbrite' => ['url', ['required', 'string', 'max:500'], [], false],
        'fresha' => ['url', ['required', 'string', 'max:500', 'regex:#^https?://(www\.)?fresha\.com/(?:[a-z]{2,3}(-[a-z]{2})?/)?a/[a-z0-9-]+/?$#i'], [], true],
        'humanitix' => ['url', ['required', 'string', 'max:500'], [], false],
        'nowbookit' => ['url', ['required', 'string', 'max:2048'], [], false],
        'opentable' => ['url', ['required', 'string', 'max:2048'], [], false],
        'resdiary' => ['url', ['required', 'string', 'max:2048'], [], false],
        // Link-only since Phase 1.2, but still url-shaped on the wire — see the
        // provider's note: the field name and the stored payload shape are
        // independent, and renaming it would break the dashboard's connect body.
        'skool' => ['url', ['required', 'string', 'max:500'], [], false],
        'soundcloud' => ['url', ['required', 'string', 'max:500'], [], false],
        'spotify' => ['url', ['required', 'string', 'max:500'], [], false],
        'square' => ['url', ['required', 'string', 'max:1000', 'regex:#^https?://([a-z0-9-]+\.)*(squareup\.com|square\.site)(/[^\s]*)?$#i'], ['url.regex' => 'Enter a valid Square booking link (a squareup.com or square.site URL).'], true],
        'strava' => ['url', ['required', 'string', 'max:300'], [], false],
        'twitch' => ['url', ['required', 'string', 'max:120'], [], false],
        'vimeo' => ['url', ['required', 'string', 'max:300'], [], false],
        'youtube-music' => ['url', ['required', 'string', 'max:300'], [], false],
        'apple-music' => ['artist', ['required', 'string', 'max:200'], [], false],
        'apple-podcast' => ['show', ['required', 'string', 'max:200'], [], false],
        'youtube' => ['channel', ['required', 'string', 'max:200'], [], false],
        'x' => ['username', ['required', 'string', 'max:200'], [], false],
        'linkedin' => ['username', ['required', 'string', 'max:200'], [], false],
        'threads' => ['username', ['required', 'string', 'max:200'], [], false],
        'reddit' => ['username', ['required', 'string', 'max:200'], [], false],
        'tiktok' => ['username', ['required', 'string', 'max:200'], [], false],
        'facebook' => ['username', ['required', 'string', 'max:200'], [], false],
    ];

    foreach ($expected as $key => [$field, $rules, $messages, $urlish]) {
        $d = $registry->get($key);
        expect($d)->not->toBeNull("missing descriptor: {$key}");
        expect($d->connectField())->toBe($field, "field drift: {$key}");
        expect($d->connectRules())->toBe($rules, "rules drift: {$key}");
        expect($d->connectMessages())->toBe($messages, "messages drift: {$key}");
        expect($d->connectNormalizesUrlish())->toBe($urlish, "urlish drift: {$key}");
    }

    // GoogleBusiness is irreducible — not shared-request driven.
    expect($registry->get('google-business')->connectField())->toBeNull();
});

it('pins deferredConnect() flag <=> DeferredConnect interface for every descriptor (Unit 11 W4)', function () {
    // Guards the boot-safety trap PlatformDescriptor::deferredConnect() is
    // built to avoid: supportsDeferredConnect() must be a DECLARED flag, never
    // an instanceof on the resolved strategy (a route loop calling
    // connectStrategy() at boot would bake a real scraper into the descriptor
    // before any test can mock it). This test is the one place `instanceof`
    // is safe to check — it runs long after boot, inside a test.
    //
    // Fails unfixed two ways: (1) before deferredConnect()/connectFetchError()
    // existed, this file wouldn't even compile; (2) if a descriptor declared
    // deferredConnect() without its strategy implementing DeferredConnect (or
    // vice versa), the flag<=>instanceof equality breaks.
    $registry = app(PlatformRegistry::class);

    // twitch + strava dropped (Phase 1.2): demoted to link-only, so their
    // connect is UrlConnect — a pure normalizer with no upstream fetch and
    // therefore nothing to defer. The negative loop below now pins that.
    $deferredKeys = ['spotify', 'bandcamp', 'vimeo', 'youtube-music', 'youtube'];

    foreach ($registry->all() as $key => $descriptor) {
        $strategy = $descriptor->connectStrategy();
        expect($descriptor->supportsDeferredConnect())
            ->toBe($strategy instanceof DeferredConnect, "flag<=>instanceof drift: {$key}");
    }

    foreach ($deferredKeys as $key) {
        $d = $registry->get($key);
        expect($d->supportsDeferredConnect())->toBeTrue("expected {$key} to be deferred");
        expect($d->connectFetchErrorMessage())->not->toBeNull("missing connectFetchErrorMessage: {$key}");
    }

    // The non-deferred connect implementers + every other descriptor
    // must NOT have declared the flag.
    foreach (array_diff($registry->keys(), $deferredKeys) as $key) {
        expect($registry->get($key)->supportsDeferredConnect())->toBeFalse("unexpected deferred flag: {$key}");
    }
});
