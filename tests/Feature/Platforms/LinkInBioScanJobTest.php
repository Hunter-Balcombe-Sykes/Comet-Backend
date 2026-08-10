<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Jobs\Platforms\LinkInBioScanJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
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
});

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
    $user = User::factory()->create(['account_type' => 'business', 'sector' => 'restaurant']);
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
    $custom = IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->first();
    expect($custom)->not->toBeNull();
    expect($custom->payload['url'])->toBe('https://www.fresha.com/a/venue-1');
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
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->count())->toBe(0);
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

    // Two more unclassified links than the run's probe budget.
    $starved = 2;
    $total = RouteContext::DEFAULT_MAX_PROBES + $starved;
    $anchors = collect(range(1, $total))
        ->map(fn (int $i) => '<a href="https://someblog.example/page-'.$i.'">Page '.$i.'</a>')
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
            && $context['probes_denied'] === $starved)
        ->once();
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
