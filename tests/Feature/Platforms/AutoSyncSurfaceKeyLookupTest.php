<?php

// tests/Feature/Platforms/AutoSyncSurfaceKeyLookupTest.php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\RouteContext;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * `platform` is a GENERATED column — split_part(surface_key,'.',1) plus the
 * SPECIAL_TO_LEGACY CASE — so it only ever holds a brand prefix. 23 of the
 * harvester's classify() values are DOTTED surface keys with no legacy slug
 * (21 booking + bluesky.profile + deezer.artist), and
 * BuildsAutoSyncFindings::write() has always keyed on surface_key. Its two
 * sibling lookups (resolveBookingLink, resolveSocialLink) did not: they asked
 * `where('platform', 'calendly.book')`, which can never match, so an existing
 * connection was INVISIBLE — no Swap conflict was raised and write()'s
 * updateOrCreate landed on the same row, silently overwriting the user's live
 * booking URL with a freshly-harvested one. The tombstone check missed for the
 * same reason, resurrecting a connection the user had removed.
 *
 * Found 2026-09-04. The SQLite stand-in declares the same generated column, so
 * these run in the cheap lane.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    setupNotificationsTable();
    setupRoutingTables();
    Queue::fake();
    Http::fake();
});

/** The booking/social intents a route() call proposed for $user. */
function surfaceLookupIntents(User $user)
{
    return DB::table('routing.source_intents')->where('user_id', $user->id)->get();
}

function surfaceLookupUser(string $accountType = 'partna'): User
{
    $user = User::factory()->create(['account_type' => $accountType, 'sector' => 'hair-salon']);
    $site = new Site(['subdomain' => 'sk'.substr((string) $user->id, 0, 8), 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

function surfaceLookupConnection(User $user, string $platform, string $url, string $resourceId): IntegrationConnection
{
    $c = new IntegrationConnection([
        'platform' => $platform,
        'resource_id' => $resourceId,
        'payload' => ['url' => $url, 'source' => 'auto'],
    ]);
    $c->user()->associate($user);
    $c->save();

    return $c->refresh();
}

it('classifies the fixture urls to the dotted surface keys this test is about', function () {
    // If either of these stops being dotted the rest of the file proves nothing.
    $h = app(WebsiteLinkHarvester::class);
    expect($h->classify('https://calendly.com/acme/30min')['platform'])->toBe('calendly.book')
        ->and($h->classify('https://bsky.app/profile/acme.bsky.social')['platform'])->toBe('bluesky.profile');
});

it('sees an existing calendly connection and proposes a suggestion instead of overwriting it', function () {
    // Booking changed shape 2026-09-05 (owner policy: a harvest never
    // auto-adds or auto-conflicts a booking platform, only ever suggests
    // one) — a second calendly link is now a proposed intent, never a
    // conflict on the live connection.
    $user = surfaceLookupUser();
    $incumbent = surfaceLookupConnection($user, 'calendly.book', 'https://calendly.com/acme/consultation', 'calendly');

    $result = app(LinkRouter::class)->route($user, 'https://calendly.com/acme', new RouteContext);

    expect($result->outcome)->toBe('custom')->and($result->handled)->toBeTrue();

    // The live URL is untouched: the whole point is that the user decides.
    expect($incumbent->fresh()->payload['url'])->toBe('https://calendly.com/acme/consultation');
});

it('sees an existing bluesky connection and raises a conflict instead of overwriting it', function () {
    $user = surfaceLookupUser();
    $incumbent = surfaceLookupConnection($user, 'bluesky.profile', 'https://bsky.app/profile/old.bsky.social', 'old.bsky.social');

    $result = app(LinkRouter::class)->route($user, 'https://bsky.app/profile/acme.bsky.social', new RouteContext);

    expect($result->outcome)->toBe('conflict')
        ->and($incumbent->fresh()->payload['url'])->toBe('https://bsky.app/profile/old.bsky.social');
});

it('does not resurrect a disconnected calendly connection', function () {
    $user = surfaceLookupUser();
    surfaceLookupConnection($user, 'calendly.book', 'https://calendly.com/acme/consultation', 'calendly')->delete();

    $result = app(LinkRouter::class)->route($user, 'https://calendly.com/acme/30min', new RouteContext);

    // Tombstoned -> offered as a custom link, never re-seeded.
    expect($result->outcome)->toBe('custom')
        ->and(IntegrationConnection::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('does not resurrect a disconnected bluesky connection', function () {
    $user = surfaceLookupUser();
    surfaceLookupConnection($user, 'bluesky.profile', 'https://bsky.app/profile/old.bsky.social', 'old.bsky.social')->delete();

    $result = app(LinkRouter::class)->route($user, 'https://bsky.app/profile/acme.bsky.social', new RouteContext);

    expect($result->outcome)->toBe('custom')
        ->and(IntegrationConnection::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('still proposes when there is no incumbent — the fix must not block the happy path', function () {
    // Booking never auto-connects from a passive harvest (2026-09-05 policy)
    // — even with nothing to conflict with, this lands as a pre-ticked
    // suggestion, not a live connection.
    $user = surfaceLookupUser();

    $result = app(LinkRouter::class)->route($user, 'https://calendly.com/acme', new RouteContext);

    expect($result->outcome)->toBe('custom')->and($result->handled)->toBeTrue();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
    expect(surfaceLookupIntents($user)->pluck('surface_key')->all())->toBe(['calendly.book']);
});

it('leaves a legacy-slug brand behaving exactly as calendly does under the booking policy', function () {
    // booksy classifies as the SLUG 'booksy' rather than a dotted key, but
    // it is still routing_class=booking, so the 2026-09-05 policy applies
    // exactly as it does to calendly.book: a suggestion, never a conflict
    // on the live connection.
    $user = surfaceLookupUser();
    $incumbent = surfaceLookupConnection($user, 'booksy', 'https://booksy.com/en-au/1111_old', 'booksy');

    $result = app(LinkRouter::class)->route($user, 'https://booksy.com/en-au/2222_new', new RouteContext);

    expect($result->outcome)->toBe('custom')->and($result->handled)->toBeTrue()
        ->and($incumbent->fresh()->payload['url'])->toBe('https://booksy.com/en-au/1111_old');
    expect(surfaceLookupIntents($user)->pluck('surface_key')->all())->toBe(['booksy.book']);
});
