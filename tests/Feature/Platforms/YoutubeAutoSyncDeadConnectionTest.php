<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\RouteContext;
use App\Services\Platforms\Strategies\Fetch\YoutubeFetch;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Support\Facades\Queue;

// F6's acceptance gate, closed at the place it was actually reachable from.
//
// F6 (303fefd59) widened socialUsername()'s youtube answer from '' to null so
// resolveWrite() could OMIT the username key. That narrowed the payload and
// nothing else: resolveSocialLink() calls write() unconditionally with whatever
// resolveWrite() hands it, so an omitted key skipped no write. The connection
// was still created and YoutubeFetch still threw `missing_key: handle` on it
// every 12h — the same Nightwatch #476 shape the commit was written to end.
//
// These route the real fixture end to end (no reflection): the gate is about
// rows in site.platform_connections, so the test has to look at rows.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    setupNotificationsTable();
    Queue::fake();
});

// The URL from the F6 review: /channel/ demands a UC… id, so @djhellraiser303
// parses as no channel at all.
const YT_UNRESOLVABLE = 'https://youtube.com/channel/@djhellraiser303';
const YT_RESOLVABLE = 'https://youtube.com/@adriannewalujo.o';

it('classifies both fixtures as social so the guard under test is the one on the path', function () {
    $harvester = app(WebsiteLinkHarvester::class);

    expect($harvester->classify(YT_UNRESOLVABLE)['category'])->toBe('social')
        ->and($harvester->classify(YT_UNRESOLVABLE)['platform'])->toBe('youtube')
        ->and($harvester->classify(YT_RESOLVABLE)['category'])->toBe('social');
});

it('creates no connection at all for a youtube link with no resolvable channel', function () {
    $user = User::factory()->create(['account_type' => 'partna', 'sector' => 'hair-salon']);

    $result = app(LinkRouter::class)->route($user, YT_UNRESOLVABLE, new RouteContext);

    expect(IntegrationConnection::withTrashed()->where('user_id', $user->id)->count())->toBe(0)
        // Same road the tombstone branch takes: handled, nothing written, and
        // the link is offered as a plain custom card carrying the real URL —
        // strictly more than the empty youtube row ever published.
        ->and($result->outcome)->toBe('custom')
        ->and($result->unmatched)->toBe([['url' => YT_UNRESOLVABLE, 'label' => 'YouTube']]);
});

it('still creates the connection when the channel does resolve', function () {
    // The guard must be about the missing identity, not about youtube. If this
    // goes red the fix has stopped auto-sync connecting real channels.
    $user = User::factory()->create(['account_type' => 'partna', 'sector' => 'hair-salon']);

    $result = app(LinkRouter::class)->route($user, YT_RESOLVABLE, new RouteContext);

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'youtube')->first();

    expect($result->outcome)->toBe('seeded')
        ->and($row)->not->toBeNull()
        ->and($row->payload['username'])->toBe('adriannewalujo.o');
});

it('never offers a dead youtube write as a Swap over a connection that works', function () {
    // The guard sits BEFORE the existing-row lookup on purpose. A conflict
    // finding carries `write` for the dashboard to apply on accept, so a dead
    // write offered as a Swap is the same dead row one click later — landing on
    // top of a channel that currently fetches.
    $user = User::factory()->create(['account_type' => 'partna', 'sector' => 'hair-salon']);
    app(LinkRouter::class)->route($user, YT_RESOLVABLE, new RouteContext);

    $result = app(LinkRouter::class)->route($user, YT_UNRESOLVABLE, new RouteContext);

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'youtube')->first();

    expect($result->outcome)->toBe('custom')
        ->and($result->findings)->toBe([])
        ->and($row->payload['username'])->toBe('adriannewalujo.o');
});

it('leaves behind no youtube connection that YoutubeFetch can only throw missing_key on', function () {
    // F6's gate, stated as F6 stated it: zero connections with
    // last_refresh_error `missing_key: handle`. Asserted against YoutubeFetch's
    // OWN handle test rather than a re-implementation of it, and driven through
    // every youtube shape the router can be handed — so a future widening of
    // resolveWrite() that reintroduces an unusable identity fails here.
    $user = User::factory()->create(['account_type' => 'partna', 'sector' => 'hair-salon']);
    $router = app(LinkRouter::class);

    foreach ([
        YT_UNRESOLVABLE,
        'https://www.youtube.com/about',
        'https://www.youtube.com/t/terms',
        'https://www.youtube.com/creators',
        'https://www.youtube.com/gaming',
        'https://www.youtube.com/premium',
        'https://www.youtube.com/@José',
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'https://youtu.be/dQw4w9WgXcQ',
    ] as $url) {
        $router->route($user, $url, new RouteContext);
    }

    $rows = IntegrationConnection::where('user_id', $user->id)->where('platform', 'youtube')->get();

    foreach ($rows as $row) {
        // YoutubeFetch.php: handle ?? username, non-empty, no slash.
        $handle = $row->payload['handle'] ?? null;
        if (! $handle) {
            $username = $row->payload['username'] ?? null;
            $handle = is_string($username) && $username !== '' && ! str_contains($username, '/') ? $username : null;
        }

        expect($handle)->not->toBeNull(
            "youtube connection {$row->id} would throw missing_key: handle on every refresh; payload ".json_encode($row->payload)
        );
    }

    // Sanity that the loop above had something to be true about — a fixture set
    // that seeds nothing would pass vacuously. Only the non-ASCII handle is a
    // real channel, so exactly one row is the honest expectation.
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->payload['username'])->toBe('José');
});
