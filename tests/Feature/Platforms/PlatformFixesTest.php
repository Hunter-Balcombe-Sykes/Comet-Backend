<?php

use Illuminate\Support\Facades\Cache;

// Facebook link normalisation (connect does no external fetch — pure parsing).

it('stores a legacy /pages/Name/ID Facebook link without mangling the username', function () {
    $res = $this->postJson('/api/platforms/facebook/connect', [
        'username' => 'https://www.facebook.com/pages/Some-Cafe/123456789',
    ]);

    $res->assertOk();
    expect($res->json('username'))->toBe('');
    expect($res->json('url'))->toBe('https://www.facebook.com/pages/Some-Cafe/123456789');
});

it('strips a query string from a /pages/ Facebook link', function () {
    $res = $this->postJson('/api/platforms/facebook/connect', [
        'username' => 'https://www.facebook.com/pages/Some-Cafe/123456789?ref=bookmarks',
    ]);

    $res->assertOk();
    expect($res->json('url'))->toBe('https://www.facebook.com/pages/Some-Cafe/123456789');
});

it('still stores a vanity Facebook handle', function () {
    $res = $this->postJson('/api/platforms/facebook/connect', ['username' => '@nike']);

    $res->assertOk();
    expect($res->json('username'))->toBe('nike');
    expect($res->json('url'))->toBe('https://www.facebook.com/nike');
});

// Eventbrite read-time past-event filtering (seed the cache, read it back).

it('drops elapsed events from the Eventbrite selection at read time', function () {
    $past = now()->subDays(2)->toIso8601String();
    $future = now()->addDays(5)->toIso8601String();

    Cache::put('platforms.eventbrite.selection', [
        'url' => 'https://www.eventbrite.com/o/acme-1',
        'organiser' => 'Acme',
        'next' => ['name' => 'Old gig', 'startDate' => $past, 'endDate' => $past],
        'upcoming' => [
            ['name' => 'Old gig', 'startDate' => $past, 'endDate' => $past],
            ['name' => 'Future gig', 'startDate' => $future, 'endDate' => $future],
        ],
    ], now()->addDay());

    $res = $this->getJson('/api/platforms/eventbrite/selection');

    $res->assertOk();
    expect($res->json('selection.upcoming'))->toHaveCount(1);
    expect($res->json('selection.upcoming.0.name'))->toBe('Future gig');
    expect($res->json('selection.next.name'))->toBe('Future gig');
});

it('keeps an in-progress event (started, not yet ended) in the Eventbrite selection', function () {
    $started = now()->subHour()->toIso8601String();
    $endsLater = now()->addHours(3)->toIso8601String();

    Cache::put('platforms.eventbrite.selection', [
        'url' => 'https://www.eventbrite.com/o/acme-1',
        'organiser' => 'Acme',
        'next' => null,
        'upcoming' => [
            ['name' => 'Live now', 'startDate' => $started, 'endDate' => $endsLater],
        ],
    ], now()->addDay());

    $res = $this->getJson('/api/platforms/eventbrite/selection');

    $res->assertOk();
    expect($res->json('selection.upcoming'))->toHaveCount(1);
    expect($res->json('selection.next.name'))->toBe('Live now');
});
