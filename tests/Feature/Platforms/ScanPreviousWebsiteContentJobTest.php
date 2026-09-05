<?php

use App\Jobs\Platforms\InstagramConnectJob;
use App\Jobs\Platforms\ResolveSiteAccentJob;
use App\Jobs\Platforms\ScanPreviousWebsiteContentJob;
use App\Jobs\Platforms\WebsiteGalleryScanJob;
use App\Jobs\Platforms\WebsiteMenuHtmlScanJob;
use App\Jobs\Platforms\WebsiteMenuPdfScanJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Models\Core\User\User;
use App\Services\Cache\ApifyBudget;
use App\Services\Content\ManualMenuItems;
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
use App\Services\WebsiteScan\WebsiteLogoCandidateExtractor;
use App\Services\WebsiteScan\WorkplaceContentApplier;
use Illuminate\Support\Facades\Bus;
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
    // Slice 7 Task 8: the menu half of this scan lands in content.* through
    // MenuScanApplier → ManualMenuWriter, not in site.menu_items.
    setupContentTables();
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
    );
}

it('fills blank about-text on the workplace from an already-fetched previous website', function () {
    [$user, $site] = spwcjUser('spwcj1', 'partna'); // partna: skips food-menu branch, isolates the about-text assertion
    Workplace::forceCreate(['site_id' => (string) $site->id]);

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
    Workplace::forceCreate(['site_id' => (string) $site->id]);

    $html = '<script type="application/ld+json">{"@type":"Menu","hasMenuSection":[{"name":"Mains","hasMenuItem":[{"name":"Margherita","offers":{"price":"18"}}]}]}</script>';
    Http::fake(['example.com' => Http::response($html, 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    $menu = Menu::query()->where('user_id', $user->id)->first();
    expect($menu)->not->toBeNull();
    expect($menu->content_source)->toBe('website-scan');
    // The 'website-scan' tag lives in the category's external_ref namespace now
    // (MenuScanApplier::categoryRefFor), not in a source_platform column.
    expect((string) app(ManualMenuItems::class)->categories((string) $user->id)[0]->external_ref)
        ->toBe(MenuScanApplier::categoryRefFor('website-scan', 'Mains'));
});

it('does not attempt menu extraction for a non-food-capable account', function () {
    [$user, $site] = spwcjUser('spwcj3', 'business', 'hair-salon'); // not a food sector
    Workplace::forceCreate(['site_id' => (string) $site->id]);

    $html = '<script type="application/ld+json">{"@type":"Menu","hasMenuSection":[{"name":"Mains","hasMenuItem":[{"name":"Margherita","offers":{"price":"18"}}]}]}</script>';
    Http::fake(['example.com' => Http::response($html, 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    expect(Menu::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('seeds a matched integration link through the real, capability-gated seed() path — not a bypass', function () {
    [$user, $site] = spwcjUser('spwcj4', 'business', 'restaurant'); // food sector — can_use_booking false
    Workplace::forceCreate(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response('<a href="https://www.fresha.com/a/venue">Book</a>', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    // The gate GoogleBusinessAutoSync::seed() enforces (food-sector -> no
    // booking) is preserved through this job's wholesale reuse of seed().
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->exists())->toBeFalse();
});

it('seeds a matched social link for a non-food business (booking capability intact)', function () {
    [$user, $site] = spwcjUser('spwcj5', 'business', 'hair-salon');
    Workplace::forceCreate(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response('<a href="https://www.fresha.com/a/venue">Book</a>', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->exists())->toBeTrue();
});

it('dispatches WebsiteMenuPdfScanJob when a PDF menu link is found, for a food-Business account', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj6', 'business', 'restaurant');
    Workplace::forceCreate(['site_id' => (string) $site->id]);

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
    Workplace::forceCreate(['site_id' => (string) $site->id]);

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
    Workplace::forceCreate(['site_id' => (string) $site->id]);

    // If the homepage already has a PDF, the /menu page must never be
    // fetched — FaviconFetcher's own separate request is expected either way.
    Http::fake(['example.com' => Http::response('<a href="/menu.pdf">Menu</a><a href="/menu/">View Menu</a>', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/menu/'));
});

it('dispatches one scan job per menu-relevant PDF found, not just the first', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj12', 'business', 'restaurant');
    Workplace::forceCreate(['site_id' => (string) $site->id]);

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
    Workplace::forceCreate(['site_id' => (string) $site->id]);

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
    Workplace::forceCreate(['site_id' => (string) $site->id]);

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
    Workplace::forceCreate(['site_id' => (string) $site->id]);

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

    $row = app(ManualMenuItems::class)->rows((string) $user->id)->first();
    expect($row)->not->toBeNull()->and($row->headline)->toBe('House made kimchi');
    Queue::assertNotPushed(WebsiteMenuHtmlScanJob::class);
});

it('dispatches WebsiteMenuHtmlScanJob when the page is menu-dense but not JSON-LD or Squarespace markup', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj16', 'business', 'restaurant');
    Workplace::forceCreate(['site_id' => (string) $site->id]);

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
    Workplace::forceCreate(['site_id' => (string) $site->id]);

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
    Workplace::forceCreate(['site_id' => (string) $site->id]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/old-venue'], 'is_active' => true,
    ]);

    Http::fake(['example.com' => Http::response('<a href="https://www.fresha.com/a/new-venue">Book</a>', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    $note = DB::connection('pgsql')->table('notifications.notifications')->where('user_id', $user->id)->first();
    expect($note)->not->toBeNull();
    expect($note->cta_url)->toBe('/account/platforms');
});

it('does not notify when the website scan finds nothing conflicting', function () {
    [$user, $site] = spwcjUser('spwcj11', 'business', 'hair-salon');
    Workplace::forceCreate(['site_id' => (string) $site->id]);

    // A clean seed (no existing connection to clash with) writes the fresha
    // connection outright — nothing needs the user's attention.
    Http::fake(['example.com' => Http::response('<a href="https://www.fresha.com/a/venue">Book</a>', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    expect(DB::connection('pgsql')->table('notifications.notifications')->where('user_id', $user->id)->count())->toBe(0);
});

// Accent RESOLUTION (fill-if-empty, priority chain) is ResolveSiteAccentJob's
// own responsibility now — covered end to end in ResolveSiteAccentJobTest.
// This job's job is only to extract the theme-color/favicon candidates from
// the already-fetched page and dispatch the resolver ONCE with them — the
// async logo/gallery tiers chain from SiteMediaObserver since 9e, not from a
// second delayed dispatch here.

it('dispatches ResolveSiteAccentJob once, carrying the extracted theme-color candidate', function () {
    Queue::fake();
    // A BUSINESS: the workplace's website IS the site's brand, so its colours
    // are design evidence. (A partna account's are not — see the test below.)
    [$user, $site] = spwcjUser('spwcj7', 'business');
    Workplace::forceCreate(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response('<meta name="theme-color" content="#ff5500">', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Queue::assertPushed(ResolveSiteAccentJob::class, fn ($job) => $job->siteId === (string) $site->id
        && $job->themeColor === '#ff5500');
    Queue::assertPushed(ResolveSiteAccentJob::class, 1);
});

it('still dispatches ResolveSiteAccentJob when an accent already exists (fill-if-empty is the resolver job\'s call, not this one\'s)', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj7b', 'business');
    Workplace::forceCreate(['site_id' => (string) $site->id]);
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => (string) $site->id, 'color_accent' => '#000000']);

    Http::fake(['example.com' => Http::response('<meta name="theme-color" content="#ff5500">', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Queue::assertPushed(ResolveSiteAccentJob::class, 1);
    // The existing manual accent is untouched at THIS layer — dispatch alone
    // never writes anything (ResolveSiteAccentJobTest pins the actual guard).
    $row = DB::connection('pgsql')->table('site.design_kits')->where('site_id', (string) $site->id)->first();
    expect($row->color_accent)->toBe('#000000');
});

it('skips the design evidence (accent, logo, font) for a partna account — the workplace website is someone else\'s brand (owner, 2026-08-19)', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj7c', 'partna');
    Workplace::forceCreate(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response('<meta name="theme-color" content="#ff5500"><link rel="icon" href="/favicon.ico">', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Queue::assertNotPushed(ResolveSiteAccentJob::class);
});

// ── 'website' build-stage terminal note (2026-09-05 fix) ──────────────────────
//
// Before this fix, the ONLY terminal STAGE_WEBSITE note this job ever wrote
// was "No photos to grab" (the now-removed gallery section) — a business
// account whose page carried gallery-shaped photos, or whose design-evidence
// section ran at all, left the stage open forever (reproduced live on St Ali,
// 2026-09-05: `started`, never `landed`/`failed`, and site.logo_candidates
// stayed empty). Design evidence now answers the stage itself, synchronously,
// regardless of gallery content.
it('lands the website stage as skipped, with a logo-specific label, when the page has no logo signal at all', function () {
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
    [$user, $site] = spwcjUser('spwcj7d', 'business');
    Workplace::forceCreate(['site_id' => (string) $site->id]);
    $build = PreAccountBuild::factory()->make(['source_type' => 'instagram']);
    $build->user()->associate($user);
    $build->save();

    Http::fake(['example.com' => Http::response('<meta name="theme-color" content="#ff5500">', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    $event = PreAccountBuildEvent::query()->where('build_id', $build->id)
        ->where('stage', PreAccountBuildEvent::STAGE_WEBSITE)->latest('created_at')->first();
    expect($event)->not->toBeNull();
    expect($event->status)->toBe(PreAccountBuildEvent::STATUS_SKIPPED);
    expect($event->label)->toBe('No logo found on your website');
});

it('lands the website stage as skipped for a partna account, without ever depending on the gallery job', function () {
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
    [$user, $site] = spwcjUser('spwcj7e', 'partna');
    Workplace::forceCreate(['site_id' => (string) $site->id]);
    $build = PreAccountBuild::factory()->make(['source_type' => 'instagram']);
    $build->user()->associate($user);
    $build->save();

    Http::fake(['example.com' => Http::response(
        '<body><img src="/photos/dining-room.jpg" width="800" height="600"></body>',
        200
    )]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    $event = PreAccountBuildEvent::query()->where('build_id', $build->id)
        ->where('stage', PreAccountBuildEvent::STAGE_WEBSITE)->latest('created_at')->first();
    expect($event)->not->toBeNull();
    expect($event->status)->toBe(PreAccountBuildEvent::STATUS_SKIPPED);
    expect($event->label)->toBe('Looked at your website');
});

it('does nothing when the fetch fails, without throwing', function () {
    [$user, $site] = spwcjUser('spwcj8', 'partna');
    Workplace::forceCreate(['site_id' => (string) $site->id]);
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
    Workplace::forceCreate(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response('<a href="mailto:owner@example.com">Email us</a>', 200)]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    expect(Workplace::where('site_id', (string) $site->id)->first()->contact_email)->toBe('owner@example.com');
});

// ── About-text prose precedence (item 8) ──────────────────────────────────────

it('overwrites a Google-sourced description with heading-prose found on the homepage', function () {
    [$user, $site] = spwcjUser('spwcj19', 'partna');
    Workplace::forceCreate([
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
    $workplace = Workplace::forceCreate(['site_id' => (string) $site->id, 'description' => 'Owner-written description.']);
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
    Workplace::forceCreate(['site_id' => (string) $site->id]);

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

// ── Gallery photos (dropped 2026-09-05 — logos only from here on) ─────────────
//
// Gallery/content-photo grabbing from a previous website was removed from this
// job entirely (owner instruction, 2026-09-05 — the 'website' build stage was
// leaning on WebsiteGalleryScanJob's own terminal note to close out, and that
// separately-dispatched job was one more thing a queue-starved worker could
// lose before ever landing). This regression guard uses the SAME
// gallery-photo fixture the old positive test used, to prove the dispatch is
// genuinely gone rather than merely untriggered by an empty-page fixture.

it('never dispatches WebsiteGalleryScanJob, even when the homepage carries gallery-candidate photos', function () {
    Queue::fake();
    [$user, $site] = spwcjUser('spwcj22', 'partna');
    Workplace::forceCreate(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response(
        '<body><img src="/photos/dining-room.jpg" width="800" height="600"></body>',
        200
    )]);

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Queue::assertNotPushed(WebsiteGalleryScanJob::class);
});

// ── #JOB-1 stale-premise regression guard ─────────────────────────────────────
//
// The audit that flagged #JOB-1 believed a retry re-triggers a paid Instagram
// scrape. That is NOT reproducible: GoogleBusinessAutoSync::seedInstagram()
// checks for an existing connection (has()) BEFORE any budget claim, so a
// second run against the SAME Instagram link finds the placeholder the first
// run already wrote and short-circuits to a conflict finding instead of a
// second dispatchInstagram()/tryClaim(). This test converts that currently-
// implicit protection into an explicit guard, so a future refactor of
// seedInstagram()'s has() check can't silently re-open the money hole the
// audit believed was already open.

it('a second scan run does not re-dispatch the paid Instagram scrape — the placeholder connection already exists', function () {
    config(['services.apify.token' => 'apify-token']);
    Bus::fake([InstagramConnectJob::class]);
    [$user, $site] = spwcjUser('spwcjretry-ig', 'business', 'hair-salon'); // non-food: isolates the IG path from the menu branch
    Workplace::forceCreate(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response('<a href="https://instagram.com/fadelab">Follow us</a>', 200)]);

    $budget = new ApifyBudget;
    $before = $budget->remaining('instagram');

    // Run 1: no existing connection — the real spend path (placeholder write +
    // InstagramConnectJob dispatch + one budget slot consumed).
    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    // Run 2: simulates the retry #JOB-1 used to allow (ScanPreviousWebsiteContentJob's
    // own $tries=2, now fixed to 1) — same user, same URL, same harvested IG link.
    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Bus::assertDispatchedTimes(InstagramConnectJob::class, 1);
    expect($budget->remaining('instagram'))->toBe($before - 1); // exactly one slot consumed, not two

    // The second run's IG finding is a phantom conflict against the placeholder
    // this very scan created — documented behaviour (§1.3 of the plan), not a
    // clean seed.
    $notification = DB::connection('pgsql')->table('notifications.notifications')
        ->where('user_id', $user->id)->latest('created_at')->first();
    expect($notification)->not->toBeNull();
});

// The partna counterpart of the test above, and the second half of the
// 2026-09-03 workplace-identity fix. For a partna the scanned page is the
// WORKPLACE's website (WorkplaceObserver dispatches this job off the workplace
// row), so its Instagram is the salon's. seed()'s social branch used to file
// it as this account's own — and, per this class's docblock, pay Apify to
// scrape it. Both stop here: the socials are dropped from the harvest INPUT,
// which is the caller's knowledge ("this page isn't theirs"), not a bypass of
// seed()'s own capability gates. The business case above is untouched.
it('does not seed — or pay to scrape — the workplace website\'s Instagram for a partna account', function () {
    config(['services.apify.token' => 'apify-token']);
    Bus::fake([InstagramConnectJob::class]);
    [$user, $site] = spwcjUser('spwcj-workplace-ig', 'partna', 'barber');
    Workplace::forceCreate(['site_id' => (string) $site->id]);

    Http::fake(['example.com' => Http::response('<a href="https://instagram.com/thebarberclubau">Follow the shop</a>', 200)]);

    $budget = new ApifyBudget;
    $before = $budget->remaining('instagram');

    spwcjRun((string) $user->id, (string) $site->id, 'https://example.com');

    Bus::assertNotDispatched(InstagramConnectJob::class);
    expect($budget->remaining('instagram'))->toBe($before);
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'instagram')->exists())
        ->toBeFalse();
});
