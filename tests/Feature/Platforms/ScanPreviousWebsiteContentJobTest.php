<?php

use App\Jobs\Platforms\ResolveSiteAccentJob;
use App\Jobs\Platforms\ScanPreviousWebsiteContentJob;
use App\Jobs\Platforms\WebsiteGalleryScanJob;
use App\Jobs\Platforms\WebsiteMenuHtmlScanJob;
use App\Jobs\Platforms\WebsiteMenuPdfScanJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Design\LogoAutoGrabber;
use App\Services\Http\MetadataParser;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\MenuScanApplier;
use App\Services\Platforms\WebsiteLinkHarvester;
use App\Services\WebsiteScan\AboutProseExtractor;
use App\Services\WebsiteScan\AboutTextExtractor;
use App\Services\WebsiteScan\ContactEmailExtractor;
use App\Services\WebsiteScan\FaviconFetcher;
use App\Services\WebsiteScan\MenuTextExtractor;
use App\Services\WebsiteScan\PdfLinkDetector;
use App\Services\WebsiteScan\SquarespaceMenuExtractor;
use App\Services\WebsiteScan\VisibleTextExtractor;
use App\Services\WebsiteScan\WebsiteAccentExtractor;
use App\Services\WebsiteScan\WebsiteGalleryCandidateExtractor;
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
    setupNotificationsTable();
    setupSiteMediaTable();
});

function spwcjUser(string $handle, string $accountType = 'business', string $sector = 'restaurant'): array
{
    $user = User::create([
        'handle' => $handle, 'handle_lc' => strtolower($handle), 'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
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
        app(AboutProseExtractor::class),
        app(ContactEmailExtractor::class),
        app(MenuTextExtractor::class),
        app(PdfLinkDetector::class),
        app(WorkplaceContentApplier::class),
        app(MenuScanApplier::class),
        app(GoogleBusinessAutoSync::class),
        app(FaviconFetcher::class),
        app(WebsiteAccentExtractor::class),
        app(WebsiteLogoCandidateExtractor::class),
        app(LogoAutoGrabber::class),
        app(MetadataParser::class),
        app(SquarespaceMenuExtractor::class),
        app(VisibleTextExtractor::class),
        app(WebsiteGalleryCandidateExtractor::class),
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

it('dispatches one scan job per menu-relevant PDF found, not just the first', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj12', 'business', 'restaurant');
    Workplace::create(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response(
        '<a href="/food-menu.pdf">Food Menu</a><a href="/wine-list.pdf">Wine List</a>',
        200
    )]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Queue::assertPushed(WebsiteMenuPdfScanJob::class, 2);
    Queue::assertPushed(WebsiteMenuPdfScanJob::class, fn ($job) => $job->documentUrl === 'https://example.com/food-menu.pdf');
    Queue::assertPushed(WebsiteMenuPdfScanJob::class, fn ($job) => $job->documentUrl === 'https://example.com/wine-list.pdf');
});

it('skips a PDF whose link text and url give no menu-relevance signal at all', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj13', 'business', 'restaurant');
    Workplace::create(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response(
        '<a href="/menu.pdf">Menu</a><a href="/terms-and-conditions.pdf">Terms &amp; Conditions</a>',
        200
    )]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Queue::assertPushed(WebsiteMenuPdfScanJob::class, 1);
    Queue::assertPushed(WebsiteMenuPdfScanJob::class, fn ($job) => $job->documentUrl === 'https://example.com/menu.pdf');
});

it('follows the schema.org hasMenu JSON-LD pointer ahead of the path-substring guess', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj14', 'business', 'restaurant');
    Workplace::create(['site_id' => (string) $site->id]);

    // The pointer names a URL that would NEVER match the '~menu~i' path
    // heuristic — proves the pointer is actually being read, not coincidence.
    Http::fake([
        'example.com/food-and-drink' => Http::response('<a href="/wine.pdf">Wine</a>', 200),
        'example.com' => Http::response(
            '<script type="application/ld+json">{"@type":"Restaurant","hasMenu":"/food-and-drink"}</script>'
            .'<a href="/food-and-drink">See our offerings</a>',
            200
        ),
    ]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Queue::assertPushed(WebsiteMenuPdfScanJob::class, fn ($job) => $job->documentUrl === 'https://example.com/wine.pdf');
});

it('applies items directly from the Squarespace fast-path without dispatching an AI job', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj15', 'business', 'restaurant');
    Workplace::create(['site_id' => (string) $site->id]);

    $html = <<<'HTML'
    <div class="menu-section">
      <div class="menu-section-title">STARTERS</div>
      <div class="menu-item">
        <div class="menu-item-title">House made kimchi</div>
        <div class="menu-item-description">13</div>
      </div>
    </div>
    HTML;
    Http::fake(['example.com' => Http::response($html, 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    $item = MenuItem::query()->whereHas('menu', fn ($q) => $q->where('user_id', $user->id))->first();
    expect($item)->not->toBeNull()->and($item->name)->toBe('House made kimchi');
    Queue::assertNotPushed(WebsiteMenuHtmlScanJob::class);
});

it('dispatches WebsiteMenuHtmlScanJob when the page is menu-dense but not JSON-LD or Squarespace markup', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj16', 'business', 'restaurant');
    Workplace::create(['site_id' => (string) $site->id]);

    // Plain divs, no Squarespace classes, no JSON-LD Menu — three price-only
    // lines clears the density pre-filter (MIN_PRICE_LINES).
    $html = '<div>Negroni</div><div>14</div><div>Old Fashioned</div><div>15</div><div>Martini</div><div>16</div>'
        .'<p>'.str_repeat('padding text to clear the density length floor. ', 5).'</p>';
    Http::fake(['example.com' => Http::response($html, 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Queue::assertPushed(WebsiteMenuHtmlScanJob::class, fn ($job) => $job->userId === (string) $user->id
        && str_contains($job->text, 'Negroni'));
});

it('does not dispatch WebsiteMenuHtmlScanJob for a sparse page with no real price signal', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj17', 'business', 'restaurant');
    Workplace::create(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response('<p>Welcome to our restaurant. We look forward to seeing you.</p>', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Queue::assertNotPushed(WebsiteMenuHtmlScanJob::class);
});

it('notifies the user when the website scan surfaces a conflict finding', function () {
    // The scan runs from an observer on website change — no modal is open, no
    // HTTP response carries the result. A conflict (found link clashing with an
    // existing connection) previously vanished with the job; now it must raise
    // a bell notification pointing at Integrations.
    [$user, $site] = spwcjUser('spwcj10', 'business', 'hair-salon');
    Workplace::create(['site_id' => (string) $site->id]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/old-venue'], 'is_active' => true,
    ]);

    Http::fake(['example.com' => Http::response('<a href="https://www.fresha.com/a/new-venue">Book</a>', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    $note = DB::connection('pgsql')->table('notifications.notifications')->where('user_id', $user->id)->first();
    expect($note)->not->toBeNull();
    expect($note->cta_url)->toBe('/account/integrations');
});

it('does not notify when the website scan finds nothing conflicting', function () {
    [$user, $site] = spwcjUser('spwcj11', 'business', 'hair-salon');
    Workplace::create(['site_id' => (string) $site->id]);

    // A clean seed (no existing connection to clash with) writes the fresha
    // connection outright — nothing needs the user's attention.
    Http::fake(['example.com' => Http::response('<a href="https://www.fresha.com/a/venue">Book</a>', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    expect(DB::connection('pgsql')->table('notifications.notifications')->where('user_id', $user->id)->count())->toBe(0);
});

// Accent RESOLUTION (fill-if-empty, priority chain) is ResolveSiteAccentJob's
// own responsibility now — covered end to end in ResolveSiteAccentJobTest.
// This job's job is only to extract the theme-color/favicon candidates from
// the already-fetched page and dispatch the resolver (twice: immediate +
// delayed, see the dispatch site's comment) with them.

it('dispatches ResolveSiteAccentJob twice, carrying the extracted theme-color candidate', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj7', 'partna');
    Workplace::create(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response('<meta name="theme-color" content="#ff5500">', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Queue::assertPushed(ResolveSiteAccentJob::class, fn ($job) => $job->siteId === (string) $site->id
        && $job->themeColor === '#ff5500');
    Queue::assertPushed(ResolveSiteAccentJob::class, 2);
});

it('still dispatches ResolveSiteAccentJob when an accent already exists (fill-if-empty is the resolver job\'s call, not this one\'s)', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj7b', 'partna');
    Workplace::create(['site_id' => (string) $site->id]);
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => (string) $site->id, 'color_accent' => '#000000']);

    Http::fake(['example.com' => Http::response('<meta name="theme-color" content="#ff5500">', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Queue::assertPushed(ResolveSiteAccentJob::class, 2);
    // The existing manual accent is untouched at THIS layer — dispatch alone
    // never writes anything (ResolveSiteAccentJobTest pins the actual guard).
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

// ── Contact email (item 8) ────────────────────────────────────────────────────

it('fills contact_email from a mailto: link on the homepage', function () {
    [$user, $site] = spwcjUser('spwcj18', 'partna');
    Workplace::create(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response('<a href="mailto:owner@example.com">Email us</a>', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    expect(Workplace::where('site_id', (string) $site->id)->first()->contact_email)->toBe('owner@example.com');
});

// ── About-text prose precedence (item 8) ──────────────────────────────────────

it('overwrites a Google-sourced description with heading-prose found on the homepage', function () {
    [$user, $site] = spwcjUser('spwcj19', 'partna');
    Workplace::create([
        'site_id' => (string) $site->id,
        'description' => "Google's editorial summary.",
        'field_sources' => ['description' => ['source' => 'google-business', 'at' => now()->toIso8601String()]],
    ]);

    Http::fake(['example.com' => Http::response(
        '<h2>About Us</h2><p>A properly long paragraph of authored prose describing exactly who we are and what we do.</p>',
        200
    )]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    expect(Workplace::where('site_id', (string) $site->id)->first()->description)
        ->toBe('A properly long paragraph of authored prose describing exactly who we are and what we do.');
});

it('never overwrites a manually-set description even when heading-prose is found', function () {
    [$user, $site] = spwcjUser('spwcj20', 'partna');
    $workplace = Workplace::create(['site_id' => (string) $site->id, 'description' => 'Owner-written description.']);
    // field_sources is system-written, not in $fillable — forceFill bypasses
    // mass-assignment protection, same convention IdentitySyncTest uses.
    $workplace->forceFill(['field_sources' => ['description' => ['source' => 'manual', 'at' => now()->toIso8601String()]]])->save();

    Http::fake(['example.com' => Http::response(
        '<h2>About Us</h2><p>A properly long paragraph of authored prose that should never overwrite anything here.</p>',
        200
    )]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    expect(Workplace::where('site_id', (string) $site->id)->first()->description)->toBe('Owner-written description.');
});

// ── One-hop /about + /contact (item 8) ────────────────────────────────────────

it('follows one-hop /about and /contact links concurrently when the homepage has neither', function () {
    [$user, $site] = spwcjUser('spwcj21', 'partna');
    Workplace::create(['site_id' => (string) $site->id]);

    Http::fake([
        'example.com/about*' => Http::response(
            '<h2>About Us</h2><p>A properly long paragraph of authored prose found one hop away on the about page.</p>',
            200
        ),
        'example.com/contact*' => Http::response('<a href="mailto:hello@example.com">Email</a>', 200),
        'example.com' => Http::response('<a href="/about/">About</a><a href="/contact/">Contact</a>', 200),
    ]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    $workplace = Workplace::where('site_id', (string) $site->id)->first();
    expect($workplace->description)->toBe('A properly long paragraph of authored prose found one hop away on the about page.');
    expect($workplace->contact_email)->toBe('hello@example.com');
});

// ── Gallery photos (item 8) ────────────────────────────────────────────────────

it('dispatches WebsiteGalleryScanJob when the homepage carries gallery-candidate photos', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj22', 'partna');
    Workplace::create(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response(
        '<body><img src="/photos/dining-room.jpg" width="800" height="600"></body>',
        200
    )]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Queue::assertPushed(WebsiteGalleryScanJob::class, fn ($job) => $job->userId === (string) $user->id
        && in_array('https://example.com/photos/dining-room.jpg', $job->candidateUrls, true));
});

it('does not dispatch WebsiteGalleryScanJob when the homepage has no gallery-candidate photos', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj23', 'partna');
    Workplace::create(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response('<p>No photos here.</p>', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Queue::assertNotPushed(WebsiteGalleryScanJob::class);
});
