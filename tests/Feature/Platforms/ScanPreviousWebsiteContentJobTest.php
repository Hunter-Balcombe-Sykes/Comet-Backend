<?php

use App\Jobs\Platforms\ScanPreviousWebsiteContentJob;
use App\Jobs\Platforms\WebsiteMenuPdfScanJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Design\LogoAutoGrabber;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\MenuScanApplier;
use App\Services\Platforms\WebsiteLinkHarvester;
use App\Services\WebsiteScan\AboutTextExtractor;
use App\Services\WebsiteScan\DesignKitAccentApplier;
use App\Services\WebsiteScan\FaviconFetcher;
use App\Services\WebsiteScan\MenuTextExtractor;
use App\Services\WebsiteScan\PdfLinkDetector;
use App\Services\WebsiteScan\WebsiteAccentExtractor;
use App\Services\WebsiteScan\WebsiteLogoCandidateExtractor;
use App\Services\WebsiteScan\WorkplaceContentApplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Uses https://example.com throughout (not the usual venue.example fixture
// domain) — SafeUrlFetcher::assertSafe() does a REAL DNS lookup before the
// fetch, which runs even under Http::fake() and fails for a non-resolving
// .example domain. example.com is one of the few RFC 2606 reserved domains
// IANA actually keeps resolving, so it clears assertSafe() while Http::fake()
// still intercepts the real request.
//
// Every test calls ->handle(...) directly (all deps resolved via app())
// rather than ::dispatchSync() — confirmed directly (not assumed) that
// Queue::fake() silently blocks ::dispatchSync() from running handle() at
// all for a job implementing both ShouldQueue and ShouldBeUnique, which
// would otherwise make the WebsiteMenuPdfScanJob-dispatch assertion below
// pass for the wrong reason (nothing ran) rather than the right one.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupWorkplacesTable();
    setupDesignKitsTable();
});

function spwcjUser(string $handle, string $accountType = 'business', string $sector = 'restaurant'): array
{
    $user = User::create([
        'handle' => $handle, 'handle_lc' => strtolower($handle), 'display_name' => ucfirst($handle),
        'account_type' => $accountType, 'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => "{$handle}@mail.example.com",
    ]);
    $site = Site::factory()->for($user, 'user')->create();

    return [$user, $site];
}

function spwcjRun(string $userId, string $siteId, string $url): void
{
    (new ScanPreviousWebsiteContentJob($userId, $siteId, $url))->handle(
        app(SafeUrlFetcher::class),
        app(WebsiteLinkHarvester::class),
        app(AboutTextExtractor::class),
        app(MenuTextExtractor::class),
        app(PdfLinkDetector::class),
        app(WorkplaceContentApplier::class),
        app(MenuScanApplier::class),
        app(GoogleBusinessAutoSync::class),
        app(FaviconFetcher::class),
        app(WebsiteAccentExtractor::class),
        app(DesignKitAccentApplier::class),
        app(WebsiteLogoCandidateExtractor::class),
        app(LogoAutoGrabber::class),
    );
}

it('fills blank about-text on the workplace from an already-fetched previous website', function () {
    [$user, $site] = spwcjUser('spwcj1', 'partna'); // partna: skips food-menu branch, isolates the about-text assertion
    Workplace::create(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response(
        '<meta name="description" content="Wood-fired pizza since 1985.">',
        200
    )]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    $workplace = Workplace::where('site_id', (string) $site->id)->first();
    expect($workplace->description)->toBe('Wood-fired pizza since 1985.');
});

it('creates menu items tagged website-scan for a food-Business account', function () {
    [$user, $site] = spwcjUser('spwcj2', 'business', 'restaurant');
    Workplace::create(['site_id' => (string) $site->id]);

    $html = '<script type="application/ld+json">{"@type":"Menu","hasMenuSection":[{"name":"Mains","hasMenuItem":[{"name":"Margherita","offers":{"price":"18"}}]}]}</script>';
    Http::fake(['example.com' => Http::response($html, 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    $menu = Menu::query()->where('user_id', $user->id)->first();
    expect($menu)->not->toBeNull();
    expect($menu->content_source)->toBe('website-scan');
    $category = MenuCategory::query()->where('menu_id', $menu->id)->first();
    expect($category->source_platform)->toBe('website-scan');
});

it('does not attempt menu extraction for a non-food-capable account', function () {
    [$user, $site] = spwcjUser('spwcj3', 'business', 'hair-salon'); // not a food sector
    Workplace::create(['site_id' => (string) $site->id]);

    $html = '<script type="application/ld+json">{"@type":"Menu","hasMenuSection":[{"name":"Mains","hasMenuItem":[{"name":"Margherita","offers":{"price":"18"}}]}]}</script>';
    Http::fake(['example.com' => Http::response($html, 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    expect(Menu::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('seeds a matched integration link through the real, capability-gated seed() path — not a bypass', function () {
    [$user, $site] = spwcjUser('spwcj4', 'business', 'restaurant'); // food sector — can_use_booking false
    Workplace::create(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response('<a href="https://www.fresha.com/a/venue">Book</a>', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    // The gate GoogleBusinessAutoSync::seed() enforces (food-sector -> no
    // booking) is preserved through this job's wholesale reuse of seed().
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->exists())->toBeFalse();
});

it('seeds a matched social link for a non-food business (booking capability intact)', function () {
    [$user, $site] = spwcjUser('spwcj5', 'business', 'hair-salon');
    Workplace::create(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response('<a href="https://www.fresha.com/a/venue">Book</a>', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->exists())->toBeTrue();
});

it('dispatches WebsiteMenuPdfScanJob when a PDF menu link is found, for a food-Business account', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj6', 'business', 'restaurant');
    Workplace::create(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response('<a href="/menu.pdf">Menu (PDF)</a>', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Queue::assertPushed(WebsiteMenuPdfScanJob::class, fn ($job) => $job->userId === (string) $user->id
        && $job->documentUrl === 'https://example.com/menu.pdf');
});

it('follows a one-hop same-site link to a dedicated menu page when the homepage itself has no menu data', function () {
    // Reproduced live 2026-07-20 (errols.com.au, a real restaurant site): the
    // homepage carried neither a JSON-LD menu nor a PDF link — both lived one
    // hop away on the site's own /menu page. Without following that link,
    // this job extracts nothing for what's a common real-world pattern.
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj9', 'business', 'restaurant');
    Workplace::create(['site_id' => (string) $site->id]);

    Http::fake([
        'example.com/menu*' => Http::response('<a href="/menu/breakfast.pdf">Breakfast Menu</a>', 200),
        'example.com' => Http::response('<a href="/menu/">View Menu</a><a href="/book-now/">Book</a>', 200),
    ]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Queue::assertPushed(WebsiteMenuPdfScanJob::class, fn ($job) => $job->userId === (string) $user->id
        && $job->documentUrl === 'https://example.com/menu/breakfast.pdf');
});

it('does not follow the one-hop menu link when the homepage already has menu data', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj9b', 'business', 'restaurant');
    Workplace::create(['site_id' => (string) $site->id]);

    // If the homepage already has a PDF, the /menu page must never be
    // fetched — FaviconFetcher's own separate request is expected either way.
    Http::fake(['example.com' => Http::response('<a href="/menu.pdf">Menu</a><a href="/menu/">View Menu</a>', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/menu/'));
});

it('fills the design_kits accent colour off the same fetch', function () {
    [$user, $site] = spwcjUser('spwcj7', 'partna');
    Workplace::create(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response('<meta name="theme-color" content="#ff5500">', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    $row = DB::connection('pgsql')->table('site.design_kits')->where('site_id', (string) $site->id)->first();
    expect($row->color_accent)->toBe('#ff5500');
});

it('does not overwrite an existing accent colour', function () {
    [$user, $site] = spwcjUser('spwcj7b', 'partna');
    Workplace::create(['site_id' => (string) $site->id]);
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => (string) $site->id, 'color_accent' => '#000000']);

    Http::fake(['example.com' => Http::response('<meta name="theme-color" content="#ff5500">', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    $row = DB::connection('pgsql')->table('site.design_kits')->where('site_id', (string) $site->id)->first();
    expect($row->color_accent)->toBe('#000000');
});

it('does nothing when the fetch fails, without throwing', function () {
    [$user, $site] = spwcjUser('spwcj8', 'partna');
    Workplace::create(['site_id' => (string) $site->id]);
    Http::fake(['example.com' => Http::response('', 404)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    expect(Workplace::where('site_id', (string) $site->id)->first()->description)->toBeNull();
});

it('does nothing when the user or site no longer exists', function () {
    Http::fake();
    spwcjRun((string) Str::uuid(), (string) Str::uuid(), 'https://example.com');
    Http::assertNothingSent();
});
