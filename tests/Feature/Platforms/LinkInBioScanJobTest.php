<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Jobs\Platforms\LinkInBioScanJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolReader;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\RouteContext;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();
    // Phase 6: an unrecognised bio link becomes a custom_links POOL item.
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

/**
 * Phase 6: an unrecognised bio link lands in the custom_links POOL, and a pool
 * item needs a section, which hangs off the site. The connection lane could
 * store a link for a siteless user; the pool cannot.
 */
function libSite(User $user): User
{
    $site = new Site(['subdomain' => 'lib'.substr((string) $user->id, 0, 8), 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

/** The pending IG connection row the scan job's findings merge back into. */
function libIgConnection(User $user, array $payload = []): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => $payload,
        'is_active' => true,
    ]);
}

it('unrolls a link-in-bio page into a seeded integration and a commerce probe, without persisting the bio-link url itself', function () {
    Queue::fake(); // do not let CustomLinkSeeder's EnrichLinkCardJob actually run
    $user = User::factory()->create(['account_type' => 'business']);
    Http::fake([
        'linktr.ee/*' => Http::response(
            '<a href="https://www.fresha.com/a/venue-1">Book</a><a href="https://someblog.example">Blog</a>',
            200
        ),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(
        app(SafeUrlFetcher::class),
        app(WebsiteLinkHarvester::class),
        app(LinkRouter::class),
        app(CustomLinkSeeder::class),
    );

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->exists())->toBeTrue();
    // The unclassified blog link goes to a commerce probe (signup-v2 C4) — the
    // probe job owns the custom-link fallback on a miss.
    Queue::assertPushed(
        CommerceProbeJob::class,
        fn ($job) => $job->url === 'https://someblog.example' && $job->category === null,
    );
    expect(IntegrationConnection::where('payload->url', 'https://linktr.ee/venue')->exists())->toBeFalse();
});

it('falls back to CustomLinkSeeder for a classified-but-gated link instead of dropping it', function () {
    Queue::fake();
    // A food-sector BUSINESS account: can_use_booking is sector-gated only for
    // Business accounts (AccountCapabilities::individualCapabilities() — a
    // partna account's can_use_booking is always true, food sector or not).
    // fresha is gated here, so LinkRouter returns outcome 'custom' — this job
    // must still turn that into a custom link, not silently drop it.
    $user = libSite(User::factory()->create(['account_type' => 'business', 'sector' => 'restaurant']));
    Http::fake([
        'linktr.ee/*' => Http::response('<a href="https://www.fresha.com/a/venue-1">Book</a>', 200),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(
        app(SafeUrlFetcher::class),
        app(WebsiteLinkHarvester::class),
        app(LinkRouter::class),
        app(CustomLinkSeeder::class),
    );

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->exists())->toBeFalse();
    $cards = app(LinkPoolReader::class)->cards($user->refresh());
    expect($cards)->toHaveCount(1)
        ->and($cards[0]['url'])->toBe('https://www.fresha.com/a/venue-1');
});

it("excludes links back to the bio page's own host — platform chrome, not the account owner's content", function () {
    // Reproduced live 2026-07-20 against a real Linktree page: of 58 total
    // <a href> on the page, only 3 were the account owner's own links — the
    // other 55 were Linktree's own site-wide chrome (pricing, blog, help
    // centre…), every single one on linktr.ee itself. Without this exclusion,
    // "nothing vanishes" (CustomLinkSeeder fallback) meant scanning one real
    // bio page could flood the user's custom links with the platform's own
    // marketing pages instead of their 2-3 real links.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    Http::fake([
        'linktr.ee/*' => Http::response(
            '<a href="https://www.fresha.com/a/venue-1">Book</a>'
            .'<a href="https://linktr.ee/s/pricing">Pricing</a>'
            .'<a href="https://linktr.ee/blog/some-post">Blog</a>',
            200
        ),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(
        app(SafeUrlFetcher::class),
        app(WebsiteLinkHarvester::class),
        app(LinkRouter::class),
        app(CustomLinkSeeder::class),
    );

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->exists())->toBeTrue();
    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(0);
});

it('does nothing when the fetch fails', function () {
    $user = User::factory()->create();
    Http::fake(['linktr.ee/*' => Http::response('', 404)]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(
        app(SafeUrlFetcher::class),
        app(WebsiteLinkHarvester::class),
        app(LinkRouter::class),
        app(CustomLinkSeeder::class),
    );

    expect(IntegrationConnection::where('user_id', $user->id)->exists())->toBeFalse();
});

it('merges a conflict finding into the IG payload syncFindings and notifies the user', function () {
    // Before this, a conflict discovered one hop into a Linktree (e.g. a NEW
    // booking provider clashing with the connected one) was computed and then
    // thrown away — the user never learned a better link was found. Now it
    // must land in the same payload syncFindings the /synced endpoint serves,
    // plus a notification since the connect modal is likely long closed.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $ig = libIgConnection($user, ['syncFindings' => [], 'unmatched' => []]);
    // An existing fresha connection with a DIFFERENT url — the scanned link conflicts.
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/old-venue'], 'is_active' => true,
    ]);
    Http::fake([
        'linktr.ee/*' => Http::response('<a href="https://www.fresha.com/a/new-venue">Book</a>', 200),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(
        app(SafeUrlFetcher::class),
        app(WebsiteLinkHarvester::class),
        app(LinkRouter::class),
        app(CustomLinkSeeder::class),
    );

    $findings = $ig->fresh()->payload['syncFindings'] ?? [];
    expect($findings)->toHaveCount(1);
    expect($findings[0]['platform'])->toBe('fresha');
    expect($findings[0]['outcome'])->toBe('conflict');

    $note = DB::connection('pgsql')->table('notifications.notifications')->where('user_id', $user->id)->first();
    expect($note)->not->toBeNull();
    expect($note->cta_url)->toBe('/account/platforms');
});

it('does not duplicate a finding for a platform the direct bio scan already recorded', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    $existingFinding = [
        'platform' => 'fresha', 'resourceId' => 'main', 'category' => 'booking',
        'label' => 'Fresha', 'foundUrl' => 'https://www.fresha.com/a/from-bio',
        'outcome' => 'conflict', 'apply' => ['remove' => ['fresha'], 'write' => []],
    ];
    $ig = libIgConnection($user, ['syncFindings' => [$existingFinding], 'unmatched' => []]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/old-venue'], 'is_active' => true,
    ]);
    Http::fake([
        'linktr.ee/*' => Http::response('<a href="https://www.fresha.com/a/new-venue">Book</a>', 200),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(
        app(SafeUrlFetcher::class),
        app(WebsiteLinkHarvester::class),
        app(LinkRouter::class),
        app(CustomLinkSeeder::class),
    );

    $findings = $ig->fresh()->payload['syncFindings'] ?? [];
    expect($findings)->toHaveCount(1);
    expect($findings[0]['foundUrl'])->toBe('https://www.fresha.com/a/from-bio');
    // Nothing new surfaced — no notification either.
    expect(DB::connection('pgsql')->table('notifications.notifications')->where('user_id', $user->id)->count())->toBe(0);
});

it('reports how much of the probe budget the scan spent and how many links it starved', function () {
    // The budget runs out SILENTLY: LinkRouter returns 'custom' for a link past
    // the cap, which is byte-identical to the 'custom' a gate denial or a probe
    // miss returns, and the fallback below seeds it like any other. Found live
    // 2026-08-10 — one studio's six nav links ate the whole budget and the three
    // links after them were never examined, with nothing anywhere recording it.
    Queue::fake();
    Log::spy();
    $user = User::factory()->create(['account_type' => 'business']);

    // Two more unclassified links than the run's probe budget, each on its own
    // WEBSITE — since the host dedupe, pages of one site cost a single probe
    // between them, so only distinct sites can still exhaust the budget.
    $starved = 2;
    $total = RouteContext::DEFAULT_MAX_PROBES + $starved;
    $anchors = collect(range(1, $total))
        ->map(fn (int $i) => '<a href="https://someblog-'.$i.'.example/page">Page '.$i.'</a>')
        ->implode('');
    Http::fake(['linktr.ee/*' => Http::response($anchors, 200)]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(
        app(SafeUrlFetcher::class),
        app(WebsiteLinkHarvester::class),
        app(LinkRouter::class),
        app(CustomLinkSeeder::class),
    );

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'platforms.link_in_bio_scan.completed'
            && $context['links_seen'] === $total
            && $context['probes_spent'] === RouteContext::DEFAULT_MAX_PROBES
            && $context['probes_denied'] === $starved
            // Distinct hosts must NOT be deduped — otherwise this test could go
            // green on the dedupe absorbing the links rather than on starvation.
            && $context['sites_deduped'] === 0)
        ->once();
});

it('reports how many links the website dedupe absorbed', function () {
    // sites_deduped and probes_denied must stay separate: denied means links
    // went unexamined (bad), deduped means the guard worked (good). One number
    // for both would report the fix as if it were the bug.
    Queue::fake();
    Log::spy();
    $user = User::factory()->create(['account_type' => 'business']);

    $anchors = collect(['/', '/appointment.html', '/artists.html', '/aftercare.html'])
        ->map(fn (string $path) => '<a href="https://crucibletattooco.com.au'.$path.'">Page</a>')
        ->implode('');
    Http::fake(['linktr.ee/*' => Http::response($anchors, 200)]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(
        app(SafeUrlFetcher::class),
        app(WebsiteLinkHarvester::class),
        app(LinkRouter::class),
        app(CustomLinkSeeder::class),
    );

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'platforms.link_in_bio_scan.completed'
            && $context['links_seen'] === 4
            && $context['probes_spent'] === 1
            && $context['probes_denied'] === 0
            && $context['sites_deduped'] === 3)
        ->once();
});

it('does not write syncFindings back onto an unclaimed pre-account payload', function () {
    // PRIV-2 regression (F1, found live 2026-08-11): InstagramSourceGenerator strips
    // bioLinks/syncFindings/unmatched from a provisional payload, but this job is
    // QUEUED — it lands seconds later and re-added syncFindings, undoing exactly one
    // third of the strip. The evidence signature was precisely that: syncFindings
    // present, the other two absent, because the merge spreads the already-stripped
    // payload and only re-adds its own key.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business', 'status' => 'unclaimed']);
    // Post-strip shape: the key is ABSENT, not an empty array.
    $ig = libIgConnection($user, ['username' => 'venue']);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/old-venue'], 'is_active' => true,
    ]);
    Http::fake([
        'linktr.ee/*' => Http::response('<a href="https://www.fresha.com/a/new-venue">Book</a>', 200),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(
        app(SafeUrlFetcher::class),
        app(WebsiteLinkHarvester::class),
        app(LinkRouter::class),
        app(CustomLinkSeeder::class),
    );

    $payload = $ig->fresh()->payload;
    expect(array_key_exists('syncFindings', $payload))->toBeFalse();
    // The rest of the payload is untouched — this guard skips a write, it does not clear one.
    expect($payload['username'])->toBe('venue');
    // No bell either: a pre-claim user has no dashboard to read /account/platforms in.
    expect(DB::connection('pgsql')->table('notifications.notifications')->where('user_id', $user->id)->count())->toBe(0);
});

it('still writes syncFindings back once the owner has claimed the account', function () {
    // Mirror of the guard above, pinning status explicitly: PRIV-2 minimisation
    // applies to unclaimed users ONLY, so a guard widened to skip everyone would
    // silently delete the conflict surface for real users. The claimed case must
    // keep both the payload write and the notification.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business', 'status' => 'active']);
    $ig = libIgConnection($user, ['username' => 'venue']);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/old-venue'], 'is_active' => true,
    ]);
    Http::fake([
        'linktr.ee/*' => Http::response('<a href="https://www.fresha.com/a/new-venue">Book</a>', 200),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(
        app(SafeUrlFetcher::class),
        app(WebsiteLinkHarvester::class),
        app(LinkRouter::class),
        app(CustomLinkSeeder::class),
    );

    $findings = $ig->fresh()->payload['syncFindings'] ?? [];
    expect($findings)->toHaveCount(1);
    expect($findings[0]['platform'])->toBe('fresha');
    expect($findings[0]['outcome'])->toBe('conflict');
    expect(DB::connection('pgsql')->table('notifications.notifications')->where('user_id', $user->id)->count())->toBe(1);
});

it('still writes a card for a link skipped because its platform already won the slot', function () {
    // First-link-per-platform is a rule about CONNECTIONS — you have one Fresha
    // account. It must not delete the SECOND link: a creator with a profile link
    // and a specific booking link had one of them silently disappear, which is
    // the "nothing vanishes" promise broken by the one outcome the loop forgot.
    Queue::fake();
    $user = libSite(User::factory()->create(['account_type' => 'business']));
    Http::fake([
        'linktr.ee/*' => Http::response(
            '<a href="https://www.fresha.com/a/venue-1">Book</a>'
            .'<a href="https://www.fresha.com/a/venue-1/services">Services</a>',
            200
        ),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(
        app(SafeUrlFetcher::class),
        app(WebsiteLinkHarvester::class),
        app(LinkRouter::class),
        app(CustomLinkSeeder::class),
    );

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->exists())->toBeTrue();
    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(1);
});

it('writes no card for a link already synced to a live connection', function () {
    // 'skipped' has THREE producers, and only ONE of them means "the link had
    // nowhere to go". This is the no-op one: LinkRouter::outcomeFrom() returns
    // skipped when the platform is already connected to THIS EXACT url. A card
    // here would render the user's TikTok twice — once as the platform block,
    // once as a raw link card sitting over a live connection.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'tiktok', 'resource_id' => 'tiktok',
        'payload' => ['kind' => 'profile', 'url' => 'https://www.tiktok.com/@creator'],
        'is_active' => true,
    ]);
    Http::fake([
        'linktr.ee/*' => Http::response('<a href="https://www.tiktok.com/@creator">TikTok</a>', 200),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/creator'))->handle(
        app(SafeUrlFetcher::class),
        app(WebsiteLinkHarvester::class),
        app(LinkRouter::class),
        app(CustomLinkSeeder::class),
    );

    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(0);
});

it('does nothing when the user no longer exists', function () {
    // Must not throw — mirrors ScanPreviousWebsiteContentJob's own null-user guard.
    (new LinkInBioScanJob((string) Str::uuid(), 'https://linktr.ee/venue'))->handle(
        app(SafeUrlFetcher::class),
        app(WebsiteLinkHarvester::class),
        app(LinkRouter::class),
        app(CustomLinkSeeder::class),
    );

    expect(true)->toBeTrue(); // reaching here without throwing is the assertion
});
