<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

// Replaces PublicEventSlugTest, which exercised the legacy events lane on
// GET /api/public/profiles/{handle}/platforms — slug/aliases stamped onto
// each eventbrite/humanitix/events-custom payload from site.item_slugs.
//
// That lane was retired in slice 2, Task 9 (2026-08-12): events reach the
// public wire through `profile.pools.events` instead, carrying occurrence,
// place and price facets the legacy shape could not, plus slug/aliases from
// content.item_slugs (see EventsPoolTest).
//
// These tests pin the RETIREMENT, because a retirement with no guard is one
// merge away from being undone. Two properties matter:
//
//  1. the events platforms emit NOTHING publicly — not a filtered subset, an
//     empty payload. A re-added allowlist key would silently republish an
//     organiser's whole event list on a second surface;
//  2. the connections are still REGISTERED, so the dashboard keeps working
//     and PublicAllowlistCoverageTest keeps passing. Deleting the allowlist
//     keys outright would report MissingPublicAllowlistException to
//     Nightwatch on every public request — the empty array is that test's
//     sanctioned "dashboard-only" form.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIntegrationConnectionsTable();
});

function retiredLaneUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'business',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('publishes nothing for an events account row', function () {
    $user = retiredLaneUser('evretired1');
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

    expect($payload)->toBe([]);
});

// RETIRED 2026-08-16 (slice 7 Phase 4 — parent §7 step 3). Slice 2 left this
// arm publishing because a standalone row has no ingest connector
// (ConnectorRegistry::MAP holds organiser-level eventbrite/humanitix only) and
// so had no pool representation at all. Slice 0b's manual write lane gave it
// one: StandaloneEventBackfiller carries every existing row onto content.* and
// EventsCatalog::storeStandalone() lands every new one there, so
// `profile.pools.events` now serves these events with the occurrence, place
// and price facets this flat payload could not carry — plus slug/aliases from
// content.item_slugs.
//
// This is the LAST reader of the legacy events wire. Nothing on
// /integrations publishes an event any more, for any row kind.
it('publishes nothing for a standalone event row', function () {
    $user = retiredLaneUser('evstandalone1');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'humanitix',
        'resource_id' => 'event-hexbbb',
        'resource_kind' => 'event',
        'payload' => ['kind' => 'event', 'id' => 'hexbbb', 'name' => 'Comedy Gala', 'link' => 'https://events.humanitix.com/comedy', 'venue' => 'The Depot'],
        'is_active' => true,
    ]);

    $payload = $this->getJson("/api/public/profiles/{$user->handle_lc}/platforms")
        ->assertOk()
        ->json('data.platforms.humanitix.0.payload');

    expect($payload)->toBe([]);
});

it('publishes nothing for a standalone eventbrite row either', function () {
    $user = retiredLaneUser('evstandalone2');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => 'event-hexccc',
        'resource_kind' => 'event',
        'payload' => ['kind' => 'event', 'id' => 'hexccc', 'name' => 'Launch Party', 'link' => 'https://example.com/launch'],
        'is_active' => true,
    ]);

    $payload = $this->getJson("/api/public/profiles/{$user->handle_lc}/platforms")
        ->assertOk()
        ->json('data.platforms.eventbrite.0.payload');

    expect($payload)->toBe([]);
});

// The third member of the retired trio, `events-custom`, has no case of its
// own: IntegrationConnection::RETIRED_SURFACES refuses the write, so a fixture
// for it cannot exist. Its allowlist entry is `[]` alongside the other two and
// the standalone branch that could have overridden it is gone.

// hiddenEventIds was ALWAYS private, and must stay private now that the
// payload is empty rather than filtered — the two are different mechanisms
// and only one of them is being relied on here.
it('still never leaks hiddenEventIds', function () {
    $user = retiredLaneUser('evretired4');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => 'acct-hidden',
        'payload' => ['url' => 'https://eventbrite.com/o/acme', 'organiser' => 'Acme', 'next' => null, 'upcoming' => [], 'hiddenEventIds' => ['hexzzz']],
        'is_active' => true,
    ]);

    $payload = $this->getJson("/api/public/profiles/{$user->handle_lc}/platforms")
        ->assertOk()
        ->json('data.platforms.eventbrite.0.payload');

    expect($payload)->not->toHaveKey('hiddenEventIds');
});

// The envelope survives — only the payload emptied. A consumer iterating
// platforms must not start seeing a null row.
it('keeps the connection envelope so the row shape does not change', function () {
    $user = retiredLaneUser('evretired5');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => 'acct-envelope',
        'payload' => ['url' => 'https://eventbrite.com/o/acme', 'organiser' => 'Acme', 'next' => null, 'upcoming' => []],
        'is_active' => true,
    ]);

    $row = $this->getJson("/api/public/profiles/{$user->handle_lc}/platforms")
        ->assertOk()
        ->json('data.platforms.eventbrite.0');

    expect($row)->toHaveKey('resourceId');
    expect($row['resourceId'])->toBe('acct-envelope');
    expect($row)->toHaveKey('payload');
});
