<?php

use App\Site\Actions\ActionVerbs;

it('gives content shelves their own verb', function (string $shelf, string $verb) {
    expect(ActionVerbs::for($shelf, 'content'))->toBe($verb);
})->with([
    ['video', 'Watch'],
    ['music', 'Listen'],
    ['podcast', 'Listen'],
    ['social', 'Follow'],
    ['community', 'Join'],
    ['education', 'Learn'],
    ['media', 'Read'],
    ['commerce', 'Shop'],
    ['events', 'Tickets'],
    ['booking', 'Book'],
]);

it('lets the routing class settle the shelves that mix two intents', function (string $routingClass, string $verb) {
    // Shelf `food` holds Uber Eats (ordering) beside OpenTable (reservations) —
    // the shelf alone cannot say Order from Reserve.
    expect(ActionVerbs::for('food', $routingClass))->toBe($verb);
})->with([
    ['ordering', 'Order'],
    ['reservations', 'Reserve'],
    ['booking', 'Book'],
]);

it('prefers the routing class over the shelf', function () {
    // A commerce-shelf surface routing as booking books; it does not shop.
    expect(ActionVerbs::for('commerce', 'booking'))->toBe('Book');
});

it('has no verb for shelves whose intent is not an action', function (?string $shelf) {
    expect(ActionVerbs::for($shelf, 'content'))->toBeNull();
})->with([['business'], ['unknown-shelf'], [null], ['']]);

it('has no verb when nothing is known', function () {
    expect(ActionVerbs::for(null, null))->toBeNull();
});

it('composes the verb onto the brand label', function () {
    expect(ActionVerbs::label('YouTube', 'Watch'))->toBe('Watch on YouTube');
});

it('leaves the brand label alone when there is no verb', function () {
    expect(ActionVerbs::label('Yelp', null))->toBe('Yelp');
});

it('does not compose onto an empty brand label', function () {
    expect(ActionVerbs::label('', 'Watch'))->toBe('');
});

it('lets a surface override a shelf that mis-describes it', function () {
    // flickr shelves under `media` beside Medium and Substack, so the shelf
    // map alone would render "Read on Flickr" for a photo stream.
    expect(ActionVerbs::for('media', 'social', 'flickr.photos'))->toBe('View')
        ->and(ActionVerbs::for('media', 'social', 'medium.profile'))->toBe('Read');
});
