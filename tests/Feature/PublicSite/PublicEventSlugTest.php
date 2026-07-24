<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

// GET /api/public/profiles/{handle}/platforms — event slug + aliases exposure
// (item-url-slugs). Two payload shapes, same as PublicIntegrationAllowlistTest's
// events coverage: an account row (upcoming[]/next event objects, pass through
// whole) and a standalone/custom row (the payload IS the event, top-level
// allowlist-filtered).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIntegrationConnectionsTable();
});

function publicEventUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'business',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('serves slug + aliases on every event in an account row upcoming/next list', function () {
    setupItemSlugsTable();
    $user = publicEventUser('evslug1');
    $event = ['id' => 'hexaaa', 'name' => 'Trivia Night', 'link' => 'https://eventbrite.com/e/trivia'];

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => 'acct-abc',
        'payload' => ['url' => 'https://eventbrite.com/o/acme', 'organiser' => 'Acme', 'next' => $event, 'upcoming' => [$event], 'hiddenEventIds' => []],
        'is_active' => true,
    ]);

    $payload = $this->getJson("/api/public/profiles/{$user->handle_lc}/platforms")
        ->assertOk()
        ->json('data.platforms.eventbrite.0.payload');

    expect($payload['upcoming'][0]['slug'])->toBe('trivia-night');
    expect($payload['upcoming'][0]['aliases'])->toBe(['hexaaa']);
    expect($payload['next']['slug'])->toBe('trivia-night');
    expect($payload['next']['aliases'])->toBe(['hexaaa']);
});

it('serves slug + aliases on a standalone event row', function () {
    setupItemSlugsTable();
    $user = publicEventUser('evslug2');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'humanitix',
        'resource_id' => 'event-hexbbb',
        'resource_kind' => 'event',
        'payload' => ['kind' => 'event', 'id' => 'hexbbb', 'name' => 'Comedy Gala', 'link' => 'https://events.humanitix.com/comedy'],
        'is_active' => true,
    ]);

    $payload = $this->getJson("/api/public/profiles/{$user->handle_lc}/platforms")
        ->assertOk()
        ->json('data.platforms.humanitix.0.payload');

    expect($payload['kind'])->toBe('event');
    expect($payload['slug'])->toBe('comedy-gala');
    expect($payload['aliases'])->toBe(['hexbbb']);
});

it('serves slug + aliases on an events-custom standalone row', function () {
    setupItemSlugsTable();
    $user = publicEventUser('evslug3');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'events-custom',
        'resource_id' => 'event-hexccc',
        'resource_kind' => 'event',
        'payload' => ['kind' => 'event', 'id' => 'hexccc', 'name' => 'Pop-Up Market'],
        'is_active' => true,
    ]);

    $payload = $this->getJson("/api/public/profiles/{$user->handle_lc}/platforms")
        ->assertOk()
        ->json('data.platforms.events-custom.0.payload');

    expect($payload['slug'])->toBe('pop-up-market');
    expect($payload['aliases'])->toBe(['hexccc']);
});

it('degrades to a null slug and id-only aliases without breaking the endpoint when item_slugs is unavailable', function () {
    // No setupItemSlugsTable() — mirrors an item_slugs outage/pre-migration window.
    $user = publicEventUser('evslug4');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'humanitix',
        'resource_id' => 'event-hexddd',
        'resource_kind' => 'event',
        'payload' => ['kind' => 'event', 'id' => 'hexddd', 'name' => 'No Slug Yet'],
        'is_active' => true,
    ]);

    $payload = $this->getJson("/api/public/profiles/{$user->handle_lc}/platforms")
        ->assertOk()
        ->json('data.platforms.humanitix.0.payload');

    expect($payload['slug'])->toBeNull();
    expect($payload['aliases'])->toBe(['hexddd']);
});

it('never leaks hiddenEventIds while annotating slugs on an account row', function () {
    setupItemSlugsTable();
    $user = publicEventUser('evslug5');
    $event = ['id' => 'hexeee', 'name' => 'Hidden Adjacent Show'];

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => 'acct-xyz',
        'payload' => ['url' => 'https://eventbrite.com/o/acme', 'organiser' => 'Acme', 'next' => $event, 'upcoming' => [$event], 'hiddenEventIds' => ['some-other-hex']],
        'is_active' => true,
    ]);

    $payload = $this->getJson("/api/public/profiles/{$user->handle_lc}/platforms")
        ->assertOk()
        ->json('data.platforms.eventbrite.0.payload');

    expect($payload)->not->toHaveKey('hiddenEventIds');
    expect($payload['upcoming'][0]['slug'])->toBe('hidden-adjacent-show');
});
