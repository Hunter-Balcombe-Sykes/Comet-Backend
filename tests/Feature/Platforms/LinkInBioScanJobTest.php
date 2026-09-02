<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Jobs\Platforms\LinkInBioScanJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Routing\Importers\LinkInBioImporter;
use App\Services\Content\LinkPoolReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();
    setupRoutingTables();
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

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

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

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

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

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->exists())->toBeTrue();
    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(0);
});

it('does nothing when the fetch fails', function () {
    $user = User::factory()->create();
    Http::fake(['linktr.ee/*' => Http::response('', 404)]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

    expect(IntegrationConnection::where('user_id', $user->id)->exists())->toBeFalse();
});

it('holds a conflicting booking link as a blocked intent and notifies the user', function () {
    // NEW CONTRACT (LinkInBioImporter migration, 2026-08-18): a conflict
    // discovered one hop into a Linktree no longer writes payload
    // syncFindings — it lands in the intent ledger (state=blocked,
    // block_reason=conflict) which SyncFindingsBridge folds into
    // GET /platforms/instagram/synced at read time (B4). The notification
    // survives: the connect modal is long closed by the time this job lands.
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

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

    $intent = DB::table('routing.source_intents')->where('user_id', $user->id)->first();
    expect($intent)->not->toBeNull();
    expect($intent->state)->toBe('blocked');
    expect($intent->block_reason)->toBe('conflict');

    $note = DB::connection('pgsql')->table('notifications.notifications')->where('user_id', $user->id)->first();
    expect($note)->not->toBeNull();
    expect($note->cta_url)->toBe('/account/platforms');
});

it('leaves a payload finding from the direct bio scan untouched', function () {
    // Transitional split (LinkInBioImporter migration, 2026-08-18): the
    // direct bio scan (InstagramAutoSync, still legacy) writes payload
    // syncFindings; the unroll writes blocked intents. This job must never
    // touch the payload again — the /synced endpoint merges both surfaces.
    // The unroll's own conflict still pings: its dedupe key is
    // page-scoped (insertOrIgnore), so re-runs of this page stay at one row.
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

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));
    // A second run of the SAME page: the intent upserts and the notification
    // key dedupes — nothing stacks.
    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

    $findings = $ig->fresh()->payload['syncFindings'] ?? [];
    expect($findings)->toHaveCount(1);
    expect($findings[0]['foundUrl'])->toBe('https://www.fresha.com/a/from-bio');
    expect(DB::connection('pgsql')->table('notifications.notifications')->where('user_id', $user->id)->count())->toBe(1);
});

it('reports how much of the probe budget the scan spent, and cards the links it starved', function () {
    // The budget must never run out SILENTLY (found live 2026-08-10): on the
    // importer path a starved unknown is CARDED — visible on the site — and
    // the completion log carries probed/noted so the split survives the run.
    Queue::fake();
    Log::spy();
    $user = libSite(User::factory()->create(['account_type' => 'business']));

    // Two more unknown-host links than the run's probe budget (18, T9), each on its
    // own WEBSITE — the per-host probe dedupe means only distinct sites can
    // exhaust the budget.
    $starved = 2;
    $total = 18 + $starved;
    $anchors = collect(range(1, $total))
        ->map(fn (int $i) => '<a href="https://someblog-'.$i.'.example/page">Page '.$i.'</a>')
        ->implode('');
    Http::fake(['linktr.ee/*' => Http::response($anchors, 200)]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

    Queue::assertPushed(CommerceProbeJob::class, 18);
    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'platforms.link_in_bio_scan.completed'
            && $context['observations'] === $total
            && $context['probed'] === 18
            && $context['noted'] === $starved)
        ->once();
    // The starved links landed as cards — never vanished.
    $urls = array_column(app(LinkPoolReader::class)->cards($user->refresh()), 'url');
    expect($urls)->toContain('https://someblog-19.example/page')
        ->and($urls)->toContain('https://someblog-20.example/page');
});

it('spends one probe per unknown host, and cards that host\'s other pages', function () {
    // Probe economy carried from the legacy host dedupe: five sub-pages of
    // one website are ONE storefront question. The siblings are carded, so
    // nothing vanishes and one site's nav cannot eat the whole budget.
    Queue::fake();
    Log::spy();
    $user = libSite(User::factory()->create(['account_type' => 'business']));

    $anchors = collect(['/', '/appointment.html', '/artists.html', '/aftercare.html'])
        ->map(fn (string $path) => '<a href="https://crucibletattooco.com.au'.$path.'">Page</a>')
        ->implode('');
    Http::fake(['linktr.ee/*' => Http::response($anchors, 200)]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

    Queue::assertPushed(CommerceProbeJob::class, 1);
    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'platforms.link_in_bio_scan.completed'
            && $context['observations'] === 4
            && $context['probed'] === 1
            && $context['noted'] === 3)
        ->once();
});

it('writes no payload finding and rings no bell for an unclaimed pre-account user', function () {
    // PRIV-2 (F1, found live 2026-08-11): the payload half is now structural —
    // the importer path never writes payload syncFindings for anyone. The bell
    // half is a guard the importer carries: a pre-claim user has no dashboard
    // to read /account/platforms in. The conflict still lands as a blocked
    // intent, ready for the claim flow.
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

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

    $payload = $ig->fresh()->payload;
    expect(array_key_exists('syncFindings', $payload))->toBeFalse();
    expect($payload['username'])->toBe('venue');
    // No bell: unclaimed guard, carried verbatim from the legacy job.
    expect(DB::connection('pgsql')->table('notifications.notifications')->where('user_id', $user->id)->count())->toBe(0);
    // A.2 (setup-dialog run): an unclaimed non-paste build is a SIGN-UP
    // context, and sign-up builds never auto-apply — so the conflict is no
    // longer discovered here at all. The find still lands in the ledger as a
    // banded suggestion for the setup dialog; the slot conflict surfaces at
    // accept time (SuggestionApplier's slot_taken arm) instead of at scan.
    $row = DB::table('routing.source_intents')->where('user_id', $user->id)->first();
    expect($row)->not->toBeNull()
        ->and((string) $row->state)->toBe('proposed')
        ->and($row->block_reason)->not->toBe('conflict');
});

it('notifies a claimed owner about a conflict via the intent ledger', function () {
    // Mirror of the guard above, pinning status explicitly: the notification
    // suppression applies to unclaimed users ONLY. A claimed user gets the
    // bell; the finding itself is served by SyncFindingsBridge folding the
    // blocked intent into GET /platforms/instagram/synced — not the payload,
    // which the importer path never writes.
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

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

    expect(array_key_exists('syncFindings', $ig->fresh()->payload))->toBeFalse();
    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->where('block_reason', 'conflict')->count())->toBe(1);
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

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

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

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/creator'))->handle(app(LinkInBioImporter::class));

    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(0);
});

it('does nothing when the user no longer exists', function () {
    // Must not throw — mirrors ScanPreviousWebsiteContentJob's own null-user guard.
    (new LinkInBioScanJob((string) Str::uuid(), 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

    expect(true)->toBeTrue(); // reaching here without throwing is the assertion
});

/**
 * N2 (2026-08-18 Instagram wave) — a matched bio host that yields ZERO routable
 * links must not leave the user with nothing.
 *
 * `linkin.bio` is an Ember SPA: LinkInBioDetector matches it, this job fetches
 * it, and the delivered shell carries 0 <a href> (measured live on
 * linkin.bio/supernormal_180: HTTP 200, 6,441 bytes, zero anchors — identical
 * on 2026-08-11 and 2026-08-18). The scan then logged links_seen: 0 and exited
 * clean, so an 83K-follower account got a site with nothing on it.
 *
 * The 2026-07-23 host-list fix made this strictly WORSE than before: the bio
 * URL used to land as one inert custom link, and afterwards it landed as none.
 * This is the floor the deferred note calls for "regardless of which option is
 * chosen" — restore the URL itself so nothing vanishes.
 */
it('seeds the bio url itself when a matched bio page yields no routable links', function () {
    Queue::fake();
    $user = libSite(User::factory()->create(['account_type' => 'partna']));
    Http::fake([
        // The real linkin.bio shell: chrome and scripts, not one anchor.
        'linkin.bio/*' => Http::response(
            '<!DOCTYPE html><html><head><title>Linkin.bio</title></head>'
            .'<body><div id="app"></div><script src="/assets/linkinbio.js"></script></body></html>',
            200,
        ),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linkin.bio/supernormal_180'))->handle(app(LinkInBioImporter::class));

    $cards = app(LinkPoolReader::class)->cards($user->refresh());
    expect($cards)->toHaveCount(1)
        ->and($cards[0]['url'])->toBe('https://linkin.bio/supernormal_180');
});

/**
 * The floor above must NOT fire when the page really did unroll — otherwise
 * every Linktree would gain a redundant card for the Linktree itself, which is
 * the behaviour the 2026-07-23 change deliberately removed.
 */
it('does not seed the bio url when the page yielded routable links', function () {
    Queue::fake();
    $user = libSite(User::factory()->create(['account_type' => 'partna']));
    Http::fake([
        'linktr.ee/*' => Http::response('<a href="https://someblog.example/post">Blog</a>', 200),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

    $urls = array_column(app(LinkPoolReader::class)->cards($user->refresh()), 'url');
    expect($urls)->not->toContain('https://linktr.ee/venue');
});

/**
 * Own-host-only is the same failure wearing a different hat: anchors were seen,
 * every one was the bio platform's own chrome, so nothing routed. The floor is
 * keyed on "nothing routed", not on "no anchors at all".
 */
it('seeds the bio url when every anchor was the bio host is own chrome', function () {
    Queue::fake();
    $user = libSite(User::factory()->create(['account_type' => 'partna']));
    Http::fake([
        'linktr.ee/*' => Http::response(
            '<a href="https://linktr.ee/pricing">Pricing</a><a href="https://linktr.ee/blog">Blog</a>',
            200,
        ),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

    $cards = app(LinkPoolReader::class)->cards($user->refresh());
    expect($cards)->toHaveCount(1)
        ->and($cards[0]['url'])->toBe('https://linktr.ee/venue');
});
