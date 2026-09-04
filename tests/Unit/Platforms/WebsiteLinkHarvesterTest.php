<?php

use App\Catalog\LegacyPlatformMap;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\WebsiteLinkHarvester;
use Tests\TestCase;

// Boots the app because classify() now falls back to the compiled catalog for
// hosts its own constants do not cover, and CompiledCatalog::path() resolves
// through base_path(). Same reason and same idiom as RoutingCorpusTest — still
// DB-free, still sub-second.
uses(TestCase::class)->in(__FILE__);

function harvesterFor(string $html): WebsiteLinkHarvester
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturn([
        'status' => 200,
        'body' => $html,
        'finalUrl' => 'https://example.com.au/',
    ]);

    return new WebsiteLinkHarvester($fetcher);
}

it('classifies socials, reservations, ordering and booking links off a homepage', function () {
    $html = <<<'HTML'
    <html><body>
      <a href="https://www.instagram.com/docpizza">IG</a>
      <a href="https://www.facebook.com/docpizzabar">FB</a>
      <a href="https://www.facebook.com/sharer/sharer.php?u=x">share widget</a>
      <a href="https://www.opentable.com.au/r/doc-pizza?rid=12345">Book a table</a>
      <a href="https://www.ubereats.com/au/store/doc-pizza/abc">Order</a>
      <a href="https://www.fresha.com/a/doc-cuts">Book a cut</a>
      <a href="/menu">Menu</a>
      <a href="mailto:hi@example.com">Email</a>
    </body></html>
    HTML;

    $out = harvesterFor($html)->harvest('https://example.com.au');

    expect($out['socials']['instagram'])->toBe('https://www.instagram.com/docpizza')
        ->and($out['socials']['facebook'])->toBe('https://www.facebook.com/docpizzabar')
        ->and($out['reservation']['links'][0]['url'])->toContain('opentable.com.au')
        ->and($out['order']['providers'][0]['name'])->toBe('Uber Eats')
        ->and($out['booking'][0])->toContain('fresha.com');
});

it('ignores share widgets and bare social domains', function () {
    $html = '<a href="https://twitter.com/intent/tweet?x=1">t</a><a href="https://instagram.com/">bare</a>';

    expect(harvesterFor($html)->harvest('https://example.com.au'))->toBe([]);
});

it('returns nothing for a missing or non-http website', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $harvester = new WebsiteLinkHarvester($fetcher);

    expect($harvester->harvest(null))->toBe([])
        ->and($harvester->harvest('not-a-url'))->toBe([]);
});

// ── A3.1: harvestHtml() / allOutboundLinks() — fetch split from parse ────────

it('harvests from a raw html string the same way harvest() does from a url', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $harvester = new WebsiteLinkHarvester($fetcher);

    $html = '<a href="https://www.fresha.com/a/venue-1">Book</a>';
    $out = $harvester->harvestHtml($html, 'https://venue.example');

    expect($out['booking'][0])->toContain('fresha.com');
});

// ── The catalog fall-through (spec 2026-08-28 §6.1 follow-up, 2026-08-30) ────
// The four host constants are hand-maintained; a booking/ordering brand the
// catalog knows and they do not used to vanish from the payload rather than be
// mis-bucketed. The catalog-derived agreement guard lives in
// tests/Feature/Platforms/CatalogClassificationSweepTest.php — these two pin
// the brands that were actually recovered, by name.

it('buckets a catalog-only booking brand no host constant covers', function () {
    $harvester = new WebsiteLinkHarvester(Mockery::mock(SafeUrlFetcher::class));

    $out = $harvester->harvestHtml('<a href="https://acme.shortcuts.com.au/">Book</a>', 'https://venue.example');

    expect($out['booking'])->toBe(['https://acme.shortcuts.com.au/']);
});

it('buckets a catalog-only ordering brand under its catalog display name', function () {
    $harvester = new WebsiteLinkHarvester(Mockery::mock(SafeUrlFetcher::class));

    $out = $harvester->harvestHtml('<a href="https://easi.com.au/order/acme">Order</a>', 'https://venue.example');

    expect($out['order']['providers'])->toHaveCount(1)
        ->and($out['order']['providers'][0]['url'])->toBe('https://easi.com.au/order/acme')
        // The catalog's authored display_name verbatim (as the constants path
        // puts 'Uber Eats' there) — EASI is the brand's own casing, not a slip.
        ->and($out['order']['providers'][0]['name'])->toBe('EASI');
});

it('leaves a detect-only social host out of every bucket', function () {
    // yelp.listing is a catalog social surface not hand-added to
    // SOCIAL_HOSTS above; classify() answers 'link' via classifyFromCatalog.
    // The fall-through buckets only the three promotable categories, so it
    // stays absent. Bucketing it would silently reverse that policy — the
    // single most likely way to get this change wrong.
    //
    // ko-fi used to be this test's example, but 2026-09-04 added it to
    // SOCIAL_HOSTS/SOCIAL_PLATFORM by name (see the harvestHtml() sibling
    // test below) — it now lands in $socials directly and would no longer
    // demonstrate the fall-through policy this test guards.
    $harvester = new WebsiteLinkHarvester(Mockery::mock(SafeUrlFetcher::class));

    expect($harvester->harvestHtml('<a href="https://www.yelp.com/biz/acme-cafe">Yelp</a>', 'https://venue.example'))->toBe([]);
});

it('classifies a hand-added SOCIAL_HOSTS brand into socials directly (2026-09-04 wave)', function () {
    $harvester = new WebsiteLinkHarvester(Mockery::mock(SafeUrlFetcher::class));

    $out = $harvester->harvestHtml('<a href="https://ko-fi.com/acme">Support me on Ko-fi</a>', 'https://venue.example');

    expect($out['socials']['ko-fi'])->toBe('https://ko-fi.com/acme');
});

it('allOutboundLinks returns every absolute outbound link, not just categorized ones', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $harvester = new WebsiteLinkHarvester($fetcher);

    $html = '<a href="https://www.fresha.com/a/venue-1">Book</a><a href="https://someblog.example">Blog</a><a href="#anchor">skip</a>';
    $links = $harvester->allOutboundLinks($html, 'https://venue.example');

    expect($links)->toContain('https://www.fresha.com/a/venue-1', 'https://someblog.example');
    expect($links)->toHaveCount(2);
});

it('allOutboundLinks resolves relative hrefs against the given base url', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $harvester = new WebsiteLinkHarvester($fetcher);

    $links = $harvester->allOutboundLinks('<a href="/menu">Menu</a>', 'https://venue.example');

    expect($links)->toBe(['https://venue.example/menu']);
});

it('harvest() still delegates through harvestHtml() with byte-identical behavior (regression)', function () {
    $html = '<a href="https://www.instagram.com/docpizza">IG</a><a href="https://www.fresha.com/a/doc-cuts">Book</a>';
    $out = harvesterFor($html)->harvest('https://example.com.au');

    expect($out['socials']['instagram'])->toBe('https://www.instagram.com/docpizza');
    expect($out['booking'][0])->toContain('fresha.com');
});

// ── classify() — single-URL host classification (BE2: reused by InstagramAutoSync) ──

function classifierHarvester(): WebsiteLinkHarvester
{
    return new WebsiteLinkHarvester(Mockery::mock(SafeUrlFetcher::class));
}

it('classifies each known social host to its platform + label', function (string $url, string $platform, string $label) {
    $out = classifierHarvester()->classify($url);

    expect($out)->toBe(['platform' => $platform, 'category' => 'social', 'label' => $label]);
})->with([
    ['https://www.instagram.com/acme', 'instagram', 'Instagram'],
    ['https://www.facebook.com/acme', 'facebook', 'Facebook'],
    ['https://www.tiktok.com/@acme', 'tiktok', 'TikTok'],
    ['https://twitter.com/acme', 'x', 'X'],
    ['https://x.com/acme', 'x', 'X'],
    ['https://www.linkedin.com/in/acme', 'linkedin', 'LinkedIn'],
    ['https://www.youtube.com/@acme', 'youtube', 'YouTube'],
]);

// F8 (2026-09-04 overnight sweep). Two halves of one invariant, and the reason
// this dataset exists at all: of the twelve social brands added earlier that
// day, only ko-fi had any test pin, and a sweep of all 104 platform values
// across the four *_PLATFORM maps found 8 that resolved to no surface at all —
// every one of them in SOCIAL_PLATFORM. Every
// value these maps emit must survive IntegrationConnection's own guard —
// LinkRouter::seedSocial() hands it straight to setPlatformAttribute(), and an
// unresolvable one trips booted()'s isKnownSurface() check, reports an
// UnregisteredPlatformException, and degrades the link to the plain card the
// brand was added here to avoid. bluesky and deezer name their SURFACE KEY for
// exactly that reason: neither declares a ->legacyPlatform() in the catalog.
it('resolves every social platform value to a real catalog surface', function (string $url, string $platform, string $label) {
    expect(classifierHarvester()->classify($url))->toBe(['platform' => $platform, 'category' => 'social', 'label' => $label]);

    // The guard that the pre-F8 values tripped, asserted directly.
    $connection = new IntegrationConnection;
    $connection->platform = $platform;
    expect(LegacyPlatformMap::isKnownSurface($connection->getAttributes()['surface_key'] ?? ''))->toBeTrue();
})->with([
    ['https://bsky.app/profile/acme.bsky.social', 'bluesky.profile', 'Bluesky'],
    ['https://www.deezer.com/artist/12345', 'deezer.artist', 'Deezer'],
    ['https://buymeacoffee.com/acme', 'buymeacoffee', 'Buy Me a Coffee'],
    ['https://codepen.io/acme', 'codepen', 'CodePen'],
    ['https://gitlab.com/acme', 'gitlab', 'GitLab'],
    ['https://kick.com/acme', 'kick', 'Kick'],
    ['https://ko-fi.com/acme', 'ko-fi', 'Ko-fi'],
]);

// The other half of F8: a `->notConnectable()` catalog surface must NOT sit in
// SOCIAL_HOSTS. Six did for one night. This is the same policy the
// yelp.listing test above guards ("bucketing it would silently reverse that
// policy"), asserted for the six that actually reversed it. venmo doubles as
// the over-matching case: its catalog detector is path-qualified (/u/<handle>),
// which the host-only SOCIAL_HOSTS map cannot express, so bucketing it there
// also claimed venmo.com/about as a profile.
it('leaves every detect-only social brand to the catalog link fall-through', function (string $url, string $expected) {
    $out = classifierHarvester()->classify($url);

    expect($out)->not->toBeNull()
        ->and($out['category'])->toBe('link')
        ->and($out['platform'])->toBe($expected);
})->with([
    ['https://www.cameo.com/acme', 'cameo'],
    ['https://cash.app/$acme', 'cash_app'],
    ['https://paypal.me/acme', 'paypal'],
    ['https://www.tumblr.com/acme', 'tumblr'],
    ['https://venmo.com/u/acme', 'venmo'],
    ['https://vsco.co/acme', 'vsco'],
]);

it('classifies booking hosts to their specific provider platform', function (string $url, string $platform, string $label) {
    expect(classifierHarvester()->classify($url))->toBe(['platform' => $platform, 'category' => 'booking', 'label' => $label]);
})->with([
    ['https://www.fresha.com/a/doc-cuts', 'fresha', 'Fresha'],
    ['https://squareup.com/book/acme', 'square', 'Square'],
    ['https://acme.square.site', 'square', 'Square'],
]);

it('classifies reservation hosts to their specific provider platform', function (string $url, string $platform, string $label) {
    expect(classifierHarvester()->classify($url))->toBe(['platform' => $platform, 'category' => 'reservations', 'label' => $label]);
})->with([
    ['https://www.opentable.com.au/r/doc-pizza', 'opentable', 'OpenTable'],
    ['https://www.resdiary.com/restaurant/doc', 'resdiary', 'ResDiary'],
    ['https://acme.nowbookit.com', 'nowbookit', 'NowBookit'],
]);

// Convergence Phase 6: ordering hosts name their BRAND, not the retired
// 'online-ordering' pseudo-platform. That collapse is the defect scope §1.6
// describes — every ordering link looked identical to ingest, so a scraped Uber
// Eats menu and a DoorDash one could not be told apart by surface. The category
// and label are deliberately unchanged: the category still drives the capability
// gate, and the label is still what renders on the card.
it('classifies ordering hosts to their own brand surface, keeping the provider as the label', function () {
    expect(classifierHarvester()->classify('https://www.ubereats.com/au/store/doc-pizza'))
        ->toBe(['platform' => 'uber_eats.order', 'category' => 'online-ordering', 'label' => 'Uber Eats']);
    expect(classifierHarvester()->classify('https://www.doordash.com/store/doc-pizza'))
        ->toBe(['platform' => 'doordash.order', 'category' => 'online-ordering', 'label' => 'DoorDash']);
    // Found live 2026-07-20 directly on a real AU restaurant's homepage
    // (errols.com.au's "Order Now" button) — a real AU/NZ ordering platform
    // this list didn't cover yet.
    expect(classifierHarvester()->classify('https://ordermate.online/errols/menu'))
        ->toBe(['platform' => 'ordermate.order', 'category' => 'online-ordering', 'label' => 'OrderMate']);
    // bopple.app was missing from ORDERING_HOSTS entirely until Phase 6, so a
    // real ollies ordering link on it classified as nothing and burned a
    // commerce probe rediscovering a host the catalog could already name.
    expect(classifierHarvester()->classify('https://bopple.app/carlton-doc-pizza'))
        ->toBe(['platform' => 'bopple', 'category' => 'online-ordering', 'label' => 'Bopple']);
});

it('returns null for an unrecognised host', function () {
    expect(classifierHarvester()->classify('https://linktr.ee/acme'))->toBeNull();
    expect(classifierHarvester()->classify('https://www.acme-personal-site.example'))->toBeNull();
});

it('returns null for a bare social domain or a share/intent widget link', function () {
    expect(classifierHarvester()->classify('https://instagram.com/'))->toBeNull();
    expect(classifierHarvester()->classify('https://www.facebook.com/sharer/sharer.php?u=x'))->toBeNull();
    expect(classifierHarvester()->classify('https://twitter.com/intent/tweet?text=hi'))->toBeNull();
});

it('returns null for a malformed or non-http url', function () {
    expect(classifierHarvester()->classify('not-a-url'))->toBeNull();
    expect(classifierHarvester()->classify('javascript:alert(1)'))->toBeNull();
    expect(classifierHarvester()->classify(''))->toBeNull();
});

// ── classify(): event / event-organiser / shop (signup-v2 C1) ────────────────

it('classifies event-organiser and event urls for both event platforms', function (string $url, string $platform, string $category) {
    $out = classifierHarvester()->classify($url);

    expect($out['platform'])->toBe($platform);
    expect($out['category'])->toBe($category);
})->with([
    ['https://www.eventbrite.com.au/o/melbourne-food-collective-1234', 'eventbrite', 'event-organiser'],
    ['https://www.eventbrite.com/e/winter-tasting-tickets-99887', 'eventbrite', 'event'],
    ['https://events.humanitix.com/host/supper-club', 'humanitix', 'event-organiser'],
    ['https://events.humanitix.com/winter-supper-2026', 'humanitix', 'event'],
]);

// 2026-09-04 — the fourteen new events brands added to classify() this same
// run. Every URL below is either a real row from tests/fixtures/Routing/
// corpus-real.php or hand-traced against the arm's own regex; no URL here is
// invented. eventim is DELIBERATELY absent: its only corpus rows are artist
// pages (no '/event/' in the path), which the eventim arm's own comment says
// falls through without classifying — not a valid pair for this dataset.
// Coverage is one verified pair per brand, not both categories for every
// brand: some organiser shapes (admitone /organizer/, etix /ticket/p/,
// eventfinda venue, megatix, moshtix venues, see_tickets /tour/, skiddle /g/,
// ticketweb /venue/, tickethype, bandsintown /e/, dice /event/, songkick
// /concerts/) have no real URL in the corpus or a concrete example in the
// code's own comments, so they are skipped rather than guessed.
it('classifies event and event-organiser urls for the fourteen new events brands', function (string $url, string $platform, string $category) {
    $out = classifierHarvester()->classify($url);

    expect($out['platform'])->toBe($platform);
    expect($out['category'])->toBe($category);
})->with([
    ['https://tickets.admitonelive.com/event/dropout-improv-vancouver-9812776', 'admitone', 'event'],
    ['https://www.etix.com/ticket/v/18346/epic-event-center', 'etix', 'event-organiser'],
    ['https://www.eventfinda.co.nz/2026/faulty-towers-the-dining-experience/napier', 'eventfinda', 'event'],
    ['https://megatix.com.au/events/kelmscott-agricultural-show-2026', 'megatix', 'event'],
    ['https://www.moshtix.com.au/v2/event/freeform-festival-2026/195722', 'moshtix', 'event'],
    ['https://tickethype.com.mt/HOUDINI', 'tickethype', 'event'],
    ['https://www.tixr.com/groups/riotfest/events/riot-fest-2026-158068', 'tixr', 'event'],
    ['https://53degrees.seetickets.com/event/limehouse-lizzy/53-degrees/3633054', 'see_tickets', 'event'],
    ['https://www.skiddle.com/whats-on/Liverpool/Blackstone-Street-Warehouse/Circus-Birthday-Liverpool-Saturday-26th-September/42368835/', 'skiddle', 'event'],
    ['https://www.ticketweb.com/event/evanston-folk-festival-2026-dawes-park-tickets/14705073', 'ticketweb', 'event'],
    // These three pair with the catalog's own EXISTING artist/venue capture
    // (corpus-real.php) that the new arm reclasses as event-organiser — no
    // real 'event' URL exists in the corpus or code comments for any of them.
    ['https://www.bandsintown.com/a/1-akon', 'bandsintown', 'event-organiser'],
    ['https://dice.fm/artist/valentina-magaletti-l3knp', 'dice', 'event-organiser'],
    ['https://www.songkick.com/artists/175070-ink', 'songkick', 'event-organiser'],
]);

// Megatix mints some event slugs straight off the root, so the bare-root arm
// has to tell an event title from site chrome with no prefix to go on. Its
// first denylist was hand-enumerated from imagination: it named paths megatix
// does not serve while missing three it does, so /privacy-policy,
// /terms-conditions and /support each classified as an EVENT — and since this
// grammar answer is the only server-side gate on the events pool, a legal page
// could be added to it. Shape carries the weight now; the word list only covers
// single lowercase words, where shape says nothing.
it('reads a megatix root slug as an event only when it is shaped like an event title', function (string $path, ?string $category) {
    $out = classifierHarvester()->classify('https://megatix.com.au/'.$path);

    expect($out['category'] ?? null)->toBe($category);
})->with([
    // All three confirmed-genuine slugs: CamelCase, or a single lowercase word.
    'camelcase title (corpus-real)' => ['SnowMachineQueenstownAud', 'event'],
    'single lowercase word' => ['miniraves', 'event'],
    'capitalised word' => ['Restricted', 'event'],
    // Kebab-case is chrome, by shape — no entry in any list needed.
    'the three that leaked: privacy' => ['privacy-policy', 'link'],
    'the three that leaked: terms' => ['terms-conditions', 'link'],
    'unlisted variants stay refused' => ['help-centre', 'link'],
    'and the -us family' => ['contact-us', 'link'],
    // Single lowercase words shape cannot see — this is what the list is for.
    'the third that leaked: support' => ['support', 'link'],
    'a word the first list missed' => ['blog', 'link'],
    'and one it had' => ['orders', 'link'],
    // The prefixed shapes are untouched.
    'prefixed event' => ['events/kelmscott-agricultural-show-2026', 'event'],
    'white-label checkout' => ['white-label/some-promoter', 'event'],
]);

it('pins humanitix org-before-event ordering (shared host, /host/ discriminates)', function () {
    // If the event check ran first, /host/ pages would classify as events —
    // NON_EVENT_SLUGS guards it scraper-side, but the ordering must hold too.
    $out = classifierHarvester()->classify('https://events.humanitix.com/host/supper-club');

    expect($out['category'])->toBe('event-organiser');
});

it('classifies decisive store hosts as shop', function (string $url, string $label) {
    $out = classifierHarvester()->classify($url);

    expect($out)->toBe(['platform' => 'shop', 'category' => 'shop', 'label' => $label]);
})->with([
    ['https://acme-goods.myshopify.com/', 'Shopify'],
    ['https://acmegoods.bigcartel.com/products', 'Big Cartel'],
]);

it('classifies marketplace and affiliate hosts as link, never shop', function (string $url, string $platform, string $label) {
    // category 'link' is what keeps these off the probe budget — 'shop' would
    // still spend one (LinkRouter::seedShop), which is the bug this replaced.
    $out = classifierHarvester()->classify($url);

    expect($out)->toBe(['platform' => $platform, 'category' => 'link', 'label' => $label]);
})->with([
    ['https://www.liketoknow.it/creator', 'ltk', 'LTK'],
    ['https://shopltk.com/explore/creator', 'ltk', 'LTK'],
    ['https://www.amazon.com/shop/creator', 'amazon', 'Amazon'],
    ['https://www.amazon.com.au/dp/B01', 'amazon', 'Amazon'],
    ['https://amazon.co.uk/dp/B01', 'amazon', 'Amazon'],
    ['https://amzn.to/3abcDEF', 'amazon', 'Amazon'],
    ['https://poshmark.com/closet/creator', 'poshmark', 'Poshmark'],
    ['https://shopmy.us/collections/12345', 'shopmy', 'ShopMy'],
    ['https://au.pinterest.com/creator', 'pinterest', 'Pinterest'],
    ['https://pin.it/abc123', 'pinterest', 'Pinterest'],
]);

it('leaves maker marketplaces unclassified so their listings keep a probe', function (string $url) {
    // Deliberate boundary, not an oversight: the unclassified arm runs
    // GenericShopScraper::readProductPage(), which reads schema.org product
    // markup off ANY page — so an Etsy listing can become a real product card,
    // and for a maker that is the most valuable thing on their page.
    expect(classifierHarvester()->classify($url))->toBeNull();
})->with([
    'https://www.etsy.com/listing/12345/hand-thrown-mug',
    'https://www.depop.com/products/creator-jacket',
]);

it('keeps the two link-only maps in lockstep', function () {
    // Parallel hand-maintained maps: a label in one and not the other yields
    // 'platform' => null out of classify(), violating its own array shape.
    $consts = (new ReflectionClass(WebsiteLinkHarvester::class))->getConstants();

    expect(array_keys($consts['LINK_ONLY_PLATFORM']))->toBe(array_keys($consts['LINK_ONLY_HOSTS']));
});

it('does not let a marketplace pattern swallow an unrelated host', function (string $url) {
    // The (^|\.) prefix and the $ anchor are load-bearing: without them a
    // business's own domain would be misread as a marketplace and lose the
    // probe that could have found its storefront.
    expect(classifierHarvester()->classify($url))->toBeNull();
})->with([
    'https://notamazon.com/',
    'https://amazon.evilproxy.example/',
    'https://mypinterestagency.com.au/',
    'https://etsy.com.evil.example/',
]);

it('keeps square.site classified as booking, never shop (pinned ambiguity)', function () {
    $out = classifierHarvester()->classify('https://acme.square.site/');

    expect($out['category'])->toBe('booking');
    expect($out['platform'])->toBe('square');
});

it('still returns null for a plain unclassifiable website', function () {
    expect(classifierHarvester()->classify('https://acme-restaurant.example'))->toBeNull();
});

it('classifies a square.site /s/order URL as Square Online ordering, and a bare square.site root as booking-side', function () {
    $harvester = app(WebsiteLinkHarvester::class);

    $ordering = $harvester->classify('https://ischia-restaurant.square.site/s/order');
    expect($ordering)->not->toBeNull()
        ->and($ordering['platform'])->toBe('square.order')
        ->and($ordering['category'])->toBe('online-ordering');

    // The bare root must NOT be claimed as ordering — booking owns the host
    // default; content evidence (the probe) reclassifies, never the URL.
    $root = $harvester->classify('https://ischia-restaurant.square.site/');
    expect($root === null || $root['platform'] !== 'square.order')->toBeTrue();
});

// ── #W1-SEC-13 / #W2-SEC-16: LIBXML_NONET on an untrusted parse ──────────────

it('still harvests links from a page carrying an external DOCTYPE and entity declarations', function () {
    // LIBXML_NONET's whole risk is that it changes how a page with external
    // references parses. SafeUrlFetcher guards the FETCH; the flag guards the
    // PARSE, and this pins that adding it costs no links on the exact page
    // shape that would exercise it — a real-world XHTML doctype plus an
    // external entity a hostile page would use to make libxml fetch a URL of
    // the attacker's choosing from inside our worker.
    $html = <<<'HTML'
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
      "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd" [
      <!ENTITY probe SYSTEM "http://169.254.169.254/latest/meta-data/">
    ]>
    <html><body>
      <a href="https://www.instagram.com/doccuts">IG</a>
      <a href="https://www.fresha.com/a/doc-cuts">Book</a>
    </body></html>
    HTML;

    $out = harvesterFor($html)->harvest('https://example.com.au');

    expect($out['socials']['instagram'] ?? null)->toBe('https://www.instagram.com/doccuts');
    expect($out['booking'] ?? [])->toContain('https://www.fresha.com/a/doc-cuts');
});
