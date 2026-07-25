<?php

/** @phpstan-ignore-all */

// End-to-end wiring of the integration-connected bell notice at every emit point:
// the controller trait (synchronous connects), ConnectFetchJob (deferred ones),
// and the three paths that bypass the trait but are still user-initiated —
// InstagramConnectJob, EnrichLinkCardJob and EventsCatalog::writeRow.

use App\Jobs\Platforms\ConnectFetchJob;
use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\SkoolScraper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();

    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
});

function icwUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function icwRows(User $user)
{
    return DB::table('notifications.notifications')
        ->where('user_id', $user->id)
        ->where('category', 'integration_connected')
        ->get();
}

it('notifies on a synchronous dashboard connect', function () {
    config(['partna.connect.deferred' => []]);
    $user = icwUser('icw1');

    $this->mock(OEmbedService::class, fn ($m) => $m->shouldReceive('resolve')->once()->andReturn([
        'name' => 'Artist', 'thumbnail' => 'https://i.scdn.co/t.jpg', 'embedUrl' => null,
    ]));

    actingAsUser($user)
        ->postJson('/api/platforms/spotify/connect', ['url' => 'https://open.spotify.com/artist/abc123'])
        ->assertOk();

    $rows = icwRows($user);
    expect($rows)->toHaveCount(1);
    expect($rows->first()->title)->toContain('Spotify');
});

it('does not notify when a custom link is added', function () {
    $user = icwUser('icw2');

    actingAsUser($user)
        ->postJson('/api/platforms/custom/links', ['url' => 'https://example.com', 'label' => 'My site'])
        ->assertSuccessful();

    expect(icwRows($user))->toHaveCount(0);
});

it('DELIBERATELY VACUOUS — a connection created outside the dashboard trait does not notify (boundary guard)', function () {
    // Passes regardless of this task's implementation: IntegrationConnection::create()
    // never routes through upsertConnection(), so wasRecentlyCreated is never checked
    // and the notifier is never called — true whether the guard in the trait is
    // correct, backwards, or deleted entirely. It exists to fail if the notify hook
    // is ever moved to a model-level observer (the obvious-looking refactor, rejected
    // during design because saveQuietly() on the 304 path would make it invisible),
    // which would make pre-account and auto-sync seeder rows notify a user who never
    // connected anything.
    $user = icwUser('icw3');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['handle' => 'seeded'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    expect(icwRows($user))->toHaveCount(0);
});

it('does not notify while a deferred connect is still pending', function () {
    $user = icwUser('icw4');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/example'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    expect(icwRows($user))->toHaveCount(0);
});

it('notifies once ConnectFetchJob completes a deferred connect', function () {
    $user = icwUser('icw5');

    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/example'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    // Copied from SkoolAsyncConnectTest.php's poll-to-ready test: handle()
    // resolves SkoolScraper fresh from the container, and SkoolFetch::fetch()
    // only ever calls fetchCommunity() (normalizeUrl() is connect-time only).
    $this->mock(SkoolScraper::class, fn ($m) => $m->shouldReceive('fetchCommunity')->once()->andReturn([
        'url' => 'https://www.skool.com/example',
        'name' => 'Some Community',
        'image' => 'https://img.example/avatar.jpg',
        'description' => 'A great community',
    ]));

    app()->call([new ConnectFetchJob($row->id, 'skool'), 'handle']);

    expect($row->fresh()->last_refresh_status)->toBe('ok');

    $rows = icwRows($user);
    expect($rows)->toHaveCount(1);
    expect($rows->first()->title)->toContain('Skool');
});

it('does not notify when ConnectFetchJob fails terminally', function () {
    $user = icwUser('icw6');

    // An unregistered platform takes markTerminal('error', 'unsupported_platform')
    // — the earliest terminal branch in the job, and the one that needs no vendor
    // stubbing to reach. IntegrationConnection's own write guard (booted() in the
    // model) rejects an unregistered platform at create() time, so the row must
    // be created with a real registered platform — same pattern as
    // ConnectFetchJobTest.php's 'marks unsupported_platform...' test. The job
    // resolves ITS OWN platform from the constructor arg, not the row's stored
    // column, so passing 'not-a-real-platform' there still reaches the branch.
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'icw6',
        'payload' => [],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    app()->call([new ConnectFetchJob($row->id, 'not-a-real-platform'), 'handle']);

    expect($row->fresh()->last_refresh_status)->toBe('error');
    expect(icwRows($user))->toHaveCount(0);
});

// ── Instagram: the job notifies, the shared seeder must not ──────────────────

// Vendor-boundary stub, copied from InstagramAsyncConnectTest: InstagramConnectionSeeder
// resolves its OWN InstagramScraper from the container, so the mock has to be bound as
// an instance, not just handed to handle(). No media is returned — mirroring needs
// Storage/Http fakes and has nothing to do with the notice.
function icwStubInstagramScraper(): void
{
    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->andReturn(['fullName' => 'Test User']);
    $scraper->shouldReceive('latestMedia')->andReturn(['photo' => null, 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->andReturn(null);
    $scraper->shouldReceive('bioLinks')->andReturn([]);
    app()->instance(InstagramScraper::class, $scraper);
}

function icwPendingInstagramRow(User $user): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => [],
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);
}

it('notifies once InstagramConnectJob completes a dashboard connect', function () {
    $user = icwUser('icw7');
    $connection = icwPendingInstagramRow($user);
    icwStubInstagramScraper();

    app()->call([new InstagramConnectJob($user->id, 'icwig', $connection->id), 'handle']);

    expect($connection->fresh()->last_refresh_status)->toBe('ok');

    $rows = icwRows($user);
    expect($rows)->toHaveCount(1);
    expect($rows->first()->title)->toContain('Instagram');
});

it('does not notify for a pre-account Instagram seed', function () {
    // PreAccount\Generators\InstagramSourceGenerator::generate() calls seed()
    // DIRECTLY and never dispatches InstagramConnectJob, so the hook belongs on
    // the job. Hooking the seeder — the code both paths share — would hand an
    // unclaimed build's owner a bell for work the system did on their behalf.
    // The row still reaches 'ok' below, so nothing but the hook's placement is
    // keeping this at zero.
    $user = icwUser('icw8');
    $connection = icwPendingInstagramRow($user);
    icwStubInstagramScraper();

    app(InstagramConnectionSeeder::class)->seed($connection, 'icwig', $user->id, ['fullName' => 'Test User']);

    expect($connection->fresh()->last_refresh_status)->toBe('ok');
    expect(icwRows($user))->toHaveCount(0);
});

// ── Link cards: resource_kind decides ────────────────────────────────────────

it('notifies once EnrichLinkCardJob completes a resource_kind-NULL card', function () {
    // Booking / reservations / online ordering leave resource_kind NULL (only
    // custom links stamp 'link'), so this is the link-card path that DOES notify.
    $user = icwUser('icw9');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'booking',
        'resource_id' => 'booking',
        'payload' => ['provider' => 'custom', 'url' => 'https://book.example'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    $this->mock(LinkCardScraper::class, fn ($m) => $m->shouldReceive('snapshot')->once()->andReturn([
        'url' => 'https://book.example', 'name' => 'Book Me', 'description' => null,
        'favicon' => null, 'logo' => null,
    ]));

    app()->call([new EnrichLinkCardJob($user->id, 'booking', 'booking', 'https://book.example'), 'handle']);

    $rows = icwRows($user);
    expect($rows)->toHaveCount(1);
    expect($rows->first()->title)->toContain('Booking');
});

it('does not notify when a seeded custom link completes enrichment', function () {
    // CustomLinkSeeder is the pre-account / auto-sync writer; its rows carry
    // resource_kind 'link', which the notifier drops. That guard is the ONLY
    // thing keeping this at zero — the row below reaches 'ok' like any other.
    $user = icwUser('icw10');

    // Without this the seeder's own EnrichLinkCardJob::dispatch()->afterCommit()
    // runs inline on the sync driver with the REAL scraper (outbound HTTP).
    Queue::fake();
    $row = app(CustomLinkSeeder::class)->seed($user, 'https://example.com');
    expect($row)->not->toBeNull();
    expect($row->resource_kind)->toBe('link');

    $this->mock(LinkCardScraper::class, fn ($m) => $m->shouldReceive('snapshot')->once()->andReturn([
        'url' => 'https://example.com', 'name' => 'Example', 'description' => null,
        'favicon' => null, 'logo' => null,
    ]));

    app()->call([new EnrichLinkCardJob($user->id, 'custom', $row->resource_id, 'https://example.com'), 'handle']);

    expect($row->fresh()->last_refresh_status)->toBe('ok');
    expect(icwRows($user))->toHaveCount(0);
});

// ── Events catalogue ─────────────────────────────────────────────────────────

it('notifies on a synchronous events-organiser connect', function () {
    config(['partna.connect.deferred' => []]);
    $user = icwUser('icw11');

    // Scraper stubbing copied from EventsCatalogTest's organiser test.
    $url = 'https://www.eventbrite.com/o/my-org-456';
    $scraper = Mockery::mock(EventbriteScraper::class);
    $scraper->shouldReceive('normalizeEventUrl')->andReturn(null);
    $scraper->shouldReceive('normalizeOrgUrl')->andReturn($url);
    $scraper->shouldReceive('fetchEvents')->with($url)->andReturn(['organiser' => 'My Org', 'events' => []]);
    app()->instance(EventbriteScraper::class, $scraper);

    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $url])->assertOk();

    $rows = icwRows($user);
    expect($rows)->toHaveCount(1);
    expect($rows->first()->title)->toContain('Eventbrite');
});

// ── Shop: a delete must never resurrect the marker row ───────────────────────

it('does not notify when removeBrand runs with no shop connection', function () {
    // removeBrand used to call writeConnection() unconditionally, so a stale
    // delete created the marker row at status 'ok' and rang "Shop connected"
    // on a DELETE. It now 404s first, like removeProduct.
    $user = icwUser('icw12');

    actingAsUser($user)->deleteJson('/api/platforms/shop/brands/brand-x')->assertStatus(404);

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'shop')->exists())->toBeFalse();
    expect(icwRows($user))->toHaveCount(0);
});
