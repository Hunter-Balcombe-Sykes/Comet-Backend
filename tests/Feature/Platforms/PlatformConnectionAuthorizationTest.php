<?php

// CONS-10 + CONS-35 authorization enforcement tests.
// Covers: pending-deletion gate (423), cross-user isolation (404),
// happy-path owners (2xx), and Form Request validation (422).
// Mirrors the setup patterns from ScraperPlatformsConnectionTest and
// LinkPlatformsConnectionTest (actingAsUser + setupUsersTable + setupSitesTable).

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

// ── Helpers ──────────────────────────────────────────────────────────────────

function authzUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'account_type' => 'individual',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

/** Set an existing user's status to pending_deletion and reload. */
function makePendingDeletion(User $user): User
{
    DB::connection('pgsql')->table('core.users')->where('id', $user->id)->update([
        'status' => 'pending_deletion',
    ]);

    return $user->fresh();
}

// ── 1. Pending-deletion gate — trait controllers ──────────────────────────────

describe('pending-deletion gate on trait controllers', function () {
    it('returns 423 on Eventbrite connect for a pending-deletion user', function () {
        $user = makePendingDeletion(authzUser('pd-eventbrite'));

        $this->mock(EventbriteScraper::class, function ($m) {
            $m->shouldReceive('normalizeOrgUrl')->andReturn('https://www.eventbrite.com/o/acme-1');
            $m->shouldReceive('fetchEvents')->andReturn([
                'organiser' => 'Acme',
                'events' => [],
            ]);
        });

        actingAsUser($user)
            ->postJson('/api/platforms/eventbrite/connect', ['url' => 'https://www.eventbrite.com/o/acme-1'])
            ->assertStatus(423);
    });

    it('returns 423 on YouTube connect for a pending-deletion user', function () {
        $user = makePendingDeletion(authzUser('pd-youtube'));

        $this->mock(YoutubeScraper::class, function ($m) {
            $m->shouldReceive('normalizeHandle')->andReturn('mychannel');
            $m->shouldReceive('fetchRecentVideos')->andReturn([
                ['videoId' => 'v1', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't'],
            ]);
        });

        actingAsUser($user)
            ->postJson('/api/platforms/youtube/connect', ['channel' => '@mychannel'])
            ->assertStatus(423);
    });

    it('returns 423 on YouTube highlights for a pending-deletion user (update path)', function () {
        $user = authzUser('pd-yt-highlights');
        // Seed an active connection so the update path fires.
        IntegrationConnection::create([
            'user_id' => $user->id,
            'platform' => 'youtube',
            'resource_id' => 'youtube',
            'payload' => ['handle' => 'mychannel', 'highlights' => []],
            'is_active' => true,
        ]);
        $user = makePendingDeletion($user);

        $this->mock(YoutubeScraper::class, function ($m) {
            $m->shouldReceive('fetchRecentVideos')->andReturn([
                ['videoId' => 'v1', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't'],
            ]);
        });

        actingAsUser($user)
            ->postJson('/api/platforms/youtube/highlights', ['videoIds' => []])
            ->assertStatus(423);
    });

    it('returns 423 on Eventbrite forget for a pending-deletion user', function () {
        $user = authzUser('pd-eb-forget');
        IntegrationConnection::create([
            'user_id' => $user->id,
            'platform' => 'eventbrite',
            'resource_id' => 'eventbrite',
            'payload' => ['url' => 'https://www.eventbrite.com/o/acme-1'],
            'is_active' => true,
        ]);
        $user = makePendingDeletion($user);

        actingAsUser($user)
            ->deleteJson('/api/platforms/eventbrite')
            ->assertStatus(423);
    });
});

// ── 2. Pending-deletion gate — Apple (migrated path) ─────────────────────────

describe('pending-deletion gate on Apple (migrated trait path)', function () {
    it('returns 423 on Apple Music connect for a pending-deletion user', function () {
        $user = makePendingDeletion(authzUser('pd-apple-music'));

        $this->mock(AppleSearch::class, function ($m) {
            $m->shouldReceive('fetchAlbums')->andReturn([
                ['collectionId' => 'a1', 'name' => 'Album', 'thumbnail' => 't', 'releaseDate' => '2026-01-01', 'link' => 'l'],
            ]);
        });

        actingAsUser($user)
            ->postJson('/api/platforms/apple/music/connect', ['artist' => 'Artist'])
            ->assertStatus(423);
    });

    it('returns 423 on Apple Podcast connect for a pending-deletion user', function () {
        $user = makePendingDeletion(authzUser('pd-apple-podcast'));

        $this->mock(AppleSearch::class, function ($m) {
            $m->shouldReceive('fetchEpisodes')->andReturn([
                ['trackId' => 'e1', 'name' => 'Ep', 'thumbnail' => 't', 'description' => 'd', 'link' => 'l'],
            ]);
        });

        actingAsUser($user)
            ->postJson('/api/platforms/apple/podcast/connect', ['show' => 'Show'])
            ->assertStatus(423);
    });

    it('returns 423 on Apple Music highlights for a pending-deletion user (update path)', function () {
        $user = authzUser('pd-apple-music-hl');
        IntegrationConnection::create([
            'user_id' => $user->id,
            'platform' => 'apple-music',
            'resource_id' => 'apple-music',
            'payload' => ['input' => 'Artist', 'highlights' => []],
            'is_active' => true,
        ]);
        $user = makePendingDeletion($user);

        $this->mock(AppleSearch::class, function ($m) {
            $m->shouldReceive('fetchAlbums')->andReturn([
                ['collectionId' => 'a1', 'name' => 'Album', 'thumbnail' => 't', 'releaseDate' => '2026-01-01', 'link' => 'l'],
            ]);
        });

        actingAsUser($user)
            ->postJson('/api/platforms/apple/music/highlights', ['albumIds' => []])
            ->assertStatus(423);
    });

    it('returns 423 on Apple Podcast highlights for a pending-deletion user (update path)', function () {
        $user = authzUser('pd-apple-pod-hl');
        IntegrationConnection::create([
            'user_id' => $user->id,
            'platform' => 'apple-podcast',
            'resource_id' => 'apple-podcast',
            'payload' => ['input' => 'Show', 'highlights' => []],
            'is_active' => true,
        ]);
        $user = makePendingDeletion($user);

        $this->mock(AppleSearch::class, function ($m) {
            $m->shouldReceive('fetchEpisodes')->andReturn([
                ['trackId' => 'e1', 'name' => 'Ep', 'thumbnail' => 't', 'description' => 'd', 'link' => 'l'],
            ]);
        });

        actingAsUser($user)
            ->postJson('/api/platforms/apple/podcast/highlights', ['episodeIds' => []])
            ->assertStatus(423);
    });
});

// ── 3. Pending-deletion gate — Instagram direct-write path ───────────────────

describe('pending-deletion gate on Instagram connect (direct-write path)', function () {
    it('returns 423 on Instagram connect (new connection) for a pending-deletion user', function () {
        $user = makePendingDeletion(authzUser('pd-instagram'));
        config(['services.apify.token' => 'test-token']);

        Queue::fake();

        actingAsUser($user)
            ->postJson('/api/platforms/instagram/connect', ['username' => 'igtestuser'])
            ->assertStatus(423);
    });

    it('returns 423 on Instagram connect (re-connect) for a pending-deletion user', function () {
        $user = authzUser('pd-instagram-reconnect');
        IntegrationConnection::create([
            'user_id' => $user->id,
            'platform' => 'instagram',
            'resource_id' => 'instagram',
            'payload' => null,
            'is_active' => false,
            'last_refresh_status' => 'pending',
        ]);
        $user = makePendingDeletion($user);
        config(['services.apify.token' => 'test-token']);

        Queue::fake();

        actingAsUser($user)
            ->postJson('/api/platforms/instagram/connect', ['username' => 'igtestuser'])
            ->assertStatus(423);
    });
});

// ── 4. Reads survive pending deletion ────────────────────────────────────────

describe('reads survive pending deletion', function () {
    it('allows a pending-deletion user to GET their Eventbrite selection (200)', function () {
        $user = authzUser('pd-eb-read');
        IntegrationConnection::create([
            'user_id' => $user->id,
            'platform' => 'eventbrite',
            'resource_id' => 'eventbrite',
            'payload' => ['url' => 'https://www.eventbrite.com/o/acme-1', 'organiser' => 'Acme', 'upcoming' => []],
            'is_active' => true,
        ]);
        $user = makePendingDeletion($user);

        actingAsUser($user)
            ->getJson('/api/platforms/eventbrite/selection')
            ->assertOk();
    });

    it('allows a pending-deletion user to GET their Apple Music selection (200)', function () {
        $user = authzUser('pd-apple-read');
        IntegrationConnection::create([
            'user_id' => $user->id,
            'platform' => 'apple-music',
            'resource_id' => 'apple-music',
            'payload' => ['input' => 'Artist', 'highlights' => []],
            'is_active' => true,
        ]);
        $user = makePendingDeletion($user);

        actingAsUser($user)
            ->getJson('/api/platforms/apple/music/selection')
            ->assertOk()
            ->assertJsonPath('selection.input', 'Artist');
    });

    it('allows a pending-deletion user to GET their YouTube selection (200)', function () {
        $user = authzUser('pd-yt-read');
        IntegrationConnection::create([
            'user_id' => $user->id,
            'platform' => 'youtube',
            'resource_id' => 'youtube',
            'payload' => ['handle' => 'mychannel', 'highlights' => []],
            'is_active' => true,
        ]);
        $user = makePendingDeletion($user);

        actingAsUser($user)
            ->getJson('/api/platforms/youtube/selection')
            ->assertOk()
            ->assertJsonPath('selection.handle', 'mychannel');
    });
});

// ── 5. Happy path — active owner ─────────────────────────────────────────────

describe('happy path — active owner', function () {
    it('allows an active owner to connect and read Eventbrite', function () {
        $user = authzUser('hp-eventbrite');

        $this->mock(EventbriteScraper::class, function ($m) {
            $m->shouldReceive('normalizeOrgUrl')->andReturn('https://www.eventbrite.com/o/acme-1');
            $m->shouldReceive('fetchEvents')->andReturn([
                'organiser' => 'Acme',
                'events' => [['name' => 'Gig', 'startDate' => '2099-01-01T00:00:00+00:00', 'endDate' => '2099-01-01T02:00:00+00:00']],
            ]);
        });

        actingAsUser($user)
            ->postJson('/api/platforms/eventbrite/connect', ['url' => 'https://www.eventbrite.com/o/acme-1'])
            ->assertOk()
            ->assertJsonPath('organiser', 'Acme');

        actingAsUser($user)
            ->getJson('/api/platforms/eventbrite/selection')
            ->assertOk();

        actingAsUser($user)
            ->deleteJson('/api/platforms/eventbrite')
            ->assertOk();
    });

    it('allows an active owner to connect and read Apple Music', function () {
        $user = authzUser('hp-apple');

        $this->mock(AppleSearch::class, function ($m) {
            $m->shouldReceive('fetchAlbums')->andReturn([
                ['collectionId' => 'a1', 'name' => 'Album', 'thumbnail' => 't', 'releaseDate' => '2026-01-01', 'link' => 'l'],
            ]);
        });

        actingAsUser($user)
            ->postJson('/api/platforms/apple/music/connect', ['artist' => 'Artist'])
            ->assertOk()
            ->assertJsonPath('name', 'Album');

        actingAsUser($user)
            ->getJson('/api/platforms/apple/music/selection')
            ->assertOk()
            ->assertJsonPath('selection.input', 'Artist');
    });
});

// ── 6. Cross-user isolation — scoped query hides other users' rows ────────────
//
// Every endpoint resolves connections through $user->integrationConnections(),
// so another user's row is never even fetched — the policy's denyAsNotFound()
// (404) is therefore dormant on these routes (it is defense-in-depth for any
// future by-UUID fetch). The 404/403 policy contract itself is verified directly
// in tests/Unit/Policies/IntegrationConnectionPolicyTest.php.

describe('cross-user isolation — scoped query hides other users\' rows', function () {
    it('a non-owner forget is a scoped no-op (200) and leaves the owner\'s row intact', function () {
        $userA = authzUser('isolation-a-eb');
        $userB = authzUser('isolation-b-eb');

        IntegrationConnection::create([
            'user_id' => $userA->id,
            'platform' => 'eventbrite',
            'resource_id' => 'eventbrite',
            'payload' => ['url' => 'https://www.eventbrite.com/o/acme-1', 'organiser' => 'Acme', 'upcoming' => []],
            'is_active' => true,
        ]);

        // B's scoped query finds no row → forgetConnection is a no-op (no authz throw) → 200.
        actingAsUser($userB)
            ->deleteJson('/api/platforms/eventbrite')
            ->assertOk();

        // A's connection is untouched — isolation holds.
        expect(IntegrationConnection::where('user_id', $userA->id)->where('platform', 'eventbrite')->exists())->toBeTrue();
    });

    it('a non-owner read returns their own empty selection (200, null) — no leakage from the owner', function () {
        $userA = authzUser('isolation-a-yt');
        $userB = authzUser('isolation-b-yt');

        IntegrationConnection::create([
            'user_id' => $userA->id,
            'platform' => 'youtube',
            'resource_id' => 'youtube',
            'payload' => ['handle' => 'channelA', 'highlights' => []],
            'is_active' => true,
        ]);

        // B's scoped query finds no row → selection is null, never A's data.
        actingAsUser($userB)
            ->getJson('/api/platforms/youtube/selection')
            ->assertOk()
            ->assertJsonPath('selection', null);
    });
});

// ── 7. Form Request validation — 422 on invalid input ───────────────────────

describe('Form Request validation returns 422', function () {
    it('returns 422 when Eventbrite connect is missing the url field', function () {
        $user = authzUser('frq-eventbrite');

        actingAsUser($user)
            ->postJson('/api/platforms/eventbrite/connect', [])
            ->assertStatus(422);
    });

    it('returns 422 when YouTube connect is missing the channel field', function () {
        $user = authzUser('frq-youtube');

        actingAsUser($user)
            ->postJson('/api/platforms/youtube/connect', [])
            ->assertStatus(422);
    });

    it('returns 422 when YouTube highlights is missing the videoIds field', function () {
        $user = authzUser('frq-yt-highlights');

        actingAsUser($user)
            ->postJson('/api/platforms/youtube/highlights', [])
            ->assertStatus(422);
    });

    it('returns 422 when Apple Music connect is missing the artist field', function () {
        $user = authzUser('frq-apple-music');

        actingAsUser($user)
            ->postJson('/api/platforms/apple/music/connect', [])
            ->assertStatus(422);
    });

    it('returns 422 when Apple Podcast connect is missing the show field', function () {
        $user = authzUser('frq-apple-podcast');

        actingAsUser($user)
            ->postJson('/api/platforms/apple/podcast/connect', [])
            ->assertStatus(422);
    });

    it('returns 422 when Apple Music highlights is missing the albumIds field', function () {
        $user = authzUser('frq-apple-music-hl');

        actingAsUser($user)
            ->postJson('/api/platforms/apple/music/highlights', [])
            ->assertStatus(422);
    });

    it('returns 422 when TikTok connect is missing the username field', function () {
        $user = authzUser('frq-tiktok');

        actingAsUser($user)
            ->postJson('/api/platforms/tiktok/connect', [])
            ->assertStatus(422);
    });

    it('returns 422 when Facebook connect is missing the username field', function () {
        $user = authzUser('frq-facebook');

        actingAsUser($user)
            ->postJson('/api/platforms/facebook/connect', [])
            ->assertStatus(422);
    });
});

// ── 8. Middleware-level pending-deletion read-only on integration routes ──────
//
// EnforcePendingDeletionReadOnly is applied to the integration route group (same
// stack as the main user API) so a pending-deletion write is blocked at the HTTP
// edge with the cancel-prompt body (pending_deletion + deletes_at), consistent
// with the rest of the app. The policy gate remains as defense-in-depth for
// non-HTTP and future by-UUID paths.

describe('middleware-level pending-deletion read-only', function () {
    it('blocks an integration write with the pending_deletion cancel-prompt body (423)', function () {
        $user = makePendingDeletion(authzUser('mw-pd-eventbrite'));

        actingAsUser($user)
            ->postJson('/api/platforms/eventbrite/connect', ['url' => 'https://www.eventbrite.com/o/acme-1'])
            ->assertStatus(423)
            ->assertJsonPath('pending_deletion', true);
    });

    it('lets a pending-deletion user keep reading an integration GET (200)', function () {
        $user = authzUser('mw-pd-read');
        IntegrationConnection::create([
            'user_id' => $user->id,
            'platform' => 'eventbrite',
            'resource_id' => 'eventbrite',
            'payload' => ['url' => 'https://www.eventbrite.com/o/acme-1', 'organiser' => 'Acme', 'upcoming' => []],
            'is_active' => true,
        ]);
        $user = makePendingDeletion($user);

        actingAsUser($user)
            ->getJson('/api/platforms/eventbrite/selection')
            ->assertOk();
    });
});
