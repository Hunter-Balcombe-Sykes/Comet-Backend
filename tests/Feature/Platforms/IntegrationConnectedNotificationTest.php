<?php

use App\Jobs\Platforms\ConnectFetchJob;
/** @phpstan-ignore-all */

// End-to-end wiring of the integration-connected bell notice at every emit point:
// the controller trait (synchronous connects), ConnectFetchJob (deferred ones),
// and the three paths that bypass the trait but are still user-initiated —
// InstagramConnectJob, EnrichLinkCardJob and EventsCatalog::writeRow.

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolReader;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\SkoolScraper;
use App\Services\Shop\ShopConnections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Phase 6: custom links live in the custom_links POOL.
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    setupNotificationsTable();

    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
});

function icwUser(string $handle): User
{
    $user = User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        // NOT NULL in prod (core.users.first_name) — supplying it here keeps the
        // helper's rows insertable against the real schema, not just SQLite's.
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);

    // Phase 6: the custom-link case below writes a pool item, which needs a
    // section, which hangs off the site.
    $site = new Site(['subdomain' => $handle, 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
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

    // notifyOnConnect mirrors InstagramController::connect — the one dispatcher
    // that means "the user just added Instagram".
    app()->call([new InstagramConnectJob($user->id, 'icwig', $connection->id, notifyOnConnect: true), 'handle']);

    expect($connection->fresh()->last_refresh_status)->toBe('ok');

    $rows = icwRows($user);
    expect($rows)->toHaveCount(1);
    expect($rows->first()->title)->toContain('Instagram');
});

it('does not notify for a system-initiated InstagramConnectJob', function () {
    // The other two dispatchers construct the job with three args, leaving
    // notifyOnConnect at its fail-closed default:
    //   - GoogleBusinessAutoSync::dispatchInstagram, reached from an UNCLAIMED
    //     pre-account GBP build (GoogleBusinessSourceGenerator → GoogleBusinessEnrichJob
    //     → seed()); its google_business_full_sync gate has no claimed-status term and
    //     every GBP build is account_type business, so nothing downstream stops it.
    //     ClaimSiteService purges no notifications, so a bell published here would
    //     survive the claim and greet whoever claims the site.
    //   - RefreshController::refreshInstagram, a manual re-pull of an account that is
    //     ALREADY connected (the row is deliberately not reset to pending). Dedupe
    //     hides that only until the 30-day retention prune hard-deletes the row.
    // The user below is partna + active on purpose: the intent flag is the only thing
    // holding this at zero, so no capability gate can mask a regression.
    $user = icwUser('icw13');
    $connection = icwPendingInstagramRow($user);
    // The source tag GoogleBusinessAutoSync writes on its placeholder row.
    $connection->forceFill(['payload' => ['source' => 'google-business']])->saveQuietly();
    icwStubInstagramScraper();

    app()->call([new InstagramConnectJob($user->id, 'icwig', $connection->id), 'handle']);

    expect($connection->fresh()->last_refresh_status)->toBe('ok');
    expect(icwRows($user))->toHaveCount(0);
});

it('runs a job enqueued before notifyOnConnect existed', function () {
    // A job already sitting in Redis at deploy time carries no notifyOnConnect key,
    // and SerializesModels::__unserialize skips absent properties. A PROMOTED default
    // is not a DECLARED one, so the typed property would restore uninitialized and
    // fatal on read — AFTER seed() wrote 'ok', costing a re-billed Apify scrape
    // ($tries = 0) and a false 'unavailable' from failed(). Hence the declared default.
    $user = icwUser('icw14');
    $connection = icwPendingInstagramRow($user);
    icwStubInstagramScraper();

    $payload = (new InstagramConnectJob($user->id, 'icwig', $connection->id, notifyOnConnect: true))->__serialize();
    unset($payload['notifyOnConnect']);

    $restored = (new ReflectionClass(InstagramConnectJob::class))->newInstanceWithoutConstructor();
    $restored->__unserialize($payload);

    app()->call([$restored, 'handle']);

    expect($connection->fresh()->last_refresh_status)->toBe('ok');
    expect(icwRows($user))->toHaveCount(0);   // fail-closed across the deploy window
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

it('notifies once EnrichLinkCardJob completes a resource_kind-NULL card', function () {
    // Booking / reservations / online ordering leave resource_kind NULL (only
    // custom links stamp 'link'), so this is the link-card path that DOES notify.
    $user = icwUser('icw9');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'booksy.book',
        // The brand prefix — what ManagesIntegrationConnection::brandResourceId()
        // produces for a single-slot brand since convergence Phase 6.
        'resource_id' => 'booksy',
        'payload' => ['provider' => 'custom', 'url' => 'https://book.example'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    $this->mock(LinkCardScraper::class, fn ($m) => $m->shouldReceive('snapshot')->once()->andReturn([
        'url' => 'https://book.example', 'name' => 'Book Me', 'description' => null,
        'favicon' => null, 'logo' => null,
    ]));

    app()->call([new EnrichLinkCardJob($user->id, 'booking', 'booksy', 'https://book.example', 'booksy.book'), 'handle']);

    $rows = icwRows($user);
    expect($rows)->toHaveCount(1);
    // "Booksy connected", not "Booking connected" — convergence Phase 6 put the
    // card on the BRAND's surface, so the bell names the brand the owner
    // actually connected instead of the retired category bucket. A better
    // notice, and a consequence of the move rather than a regression.
    expect($rows->first()->title)->toContain('Booksy');
});

it('does not notify when a seeded custom link completes enrichment', function () {
    // CustomLinkSeeder is the pre-account / auto-sync writer. It used to write a
    // connection with resource_kind 'link', and the notifier's guard on that kind
    // was the ONLY thing keeping this at zero. Convergence Phase 6 removed the
    // row entirely — a seeded link is a custom_links POOL item — so the
    // guarantee now holds for a stronger reason: the notifier fires on connection
    // writes, and there is no longer a connection to fire on.
    $user = icwUser('icw10');

    // Without this the seeder's own enrichment dispatch runs inline on the sync
    // driver with the REAL scraper (outbound HTTP).
    Queue::fake();
    // seedCustom(), not seed(): seed() is the routing gateway now and an
    // unclassified URL there becomes a commerce probe, writing nothing.
    app(CustomLinkSeeder::class)->seedCustom($user, 'https://example.com');

    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(1)
        ->and(IntegrationConnection::query()->where('user_id', $user->id)->count())->toBe(0);
    expect(icwRows($user))->toHaveCount(0);
});

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

it('does not notify when removeBrand runs with no shop connection', function () {
    // removeBrand used to call writeConnection() unconditionally, so a stale
    // delete created the marker row at status 'ok' and rang "Shop connected"
    // on a DELETE. It now 404s first, like removeProduct.
    $user = icwUser('icw12');

    actingAsUser($user)->deleteJson('/api/platforms/shop/brands/brand-x')->assertStatus(404);

    expect(IntegrationConnection::where('user_id', $user->id)->whereIn('surface_key', ShopConnections::surfaces())->exists())->toBeFalse();
    expect(icwRows($user))->toHaveCount(0);
});
