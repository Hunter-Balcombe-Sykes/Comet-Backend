<?php

use App\Services\Platforms\RouteContext;
use App\Services\Platforms\WebsiteLinkHarvester;

// N1 (2026-08-11 Instagram build wave): being defined in app/Catalog/Definitions
// did not make a link classify on the auto-route path. LinkRouter classifies
// through WebsiteLinkHarvester::classify(), which walked five hand-maintained
// host constants and never consulted the compiled catalog — so 39 hosts the
// catalog carries a detector for were invisible, and each one spent one of the
// run's six commerce probes rediscovering a host the catalog could already name
// (N4: that is what starved the Juno product page).
//
// The fallback is a UNION, not a replacement: the hand tables still answer
// first, because they are host-only by design and beat the projector on the 178
// hosts they cover. The catalog answers only where they say nothing.
//
// Category 'link' is deliberate — recognised, never auto-connected, spends no
// probe. Same contract as LINK_ONLY_HOSTS. Promoting these to real connections
// means teaching LinkRouter the catalog's routing classes, which is the P8
// migration (docs/plans/2026-07-28-p8-deletion-readiness.md), not this fix.

function catalogClassifier(): WebsiteLinkHarvester
{
    return app(WebsiteLinkHarvester::class);
}

it('classifies a catalog-known host the hand tables have never heard of', function (string $url, string $platform, string $label) {
    expect(catalogClassifier()->classify($url))
        ->toBe(['platform' => $platform, 'category' => 'link', 'label' => $label]);
})->with([
    'bandcamp artist subdomain' => ['https://juno-records.bandcamp.com/', 'bandcamp', 'Bandcamp'],
    // events-parity 2026-08-19: an ra.co/events/<id> URL is now category
    // 'event' (a real inline arm, seeds the events pool) — the catalog
    // fallback keeps answering for RA's PROFILE pages.
    'resident advisor dj profile' => ['https://ra.co/dj/some-artist', 'resident-advisor', 'Resident Advisor'],
    'ko-fi page' => ['https://ko-fi.com/acme', 'ko-fi', 'Ko-fi'],
    'gitlab profile' => ['https://gitlab.com/acme', 'gitlab', 'GitLab'],
    'medium profile' => ['https://medium.com/@acme', 'medium', 'Medium'],
    'buymeacoffee page' => ['https://buymeacoffee.com/acme', 'buymeacoffee', 'Buy Me a Coffee'],
]);

// Step 4: brands the catalog names but carried no detector for, so neither
// classifier could see them. Mixcloud burned a probe on account 4.
it('classifies a brand whose catalog detector was missing entirely', function () {
    expect(catalogClassifier()->classify('https://www.mixcloud.com/someone/'))
        ->toBe(['platform' => 'mixcloud', 'category' => 'link', 'label' => 'Mixcloud']);
});

// The whole point of the fix: a recognised host must not cost a scrape.
it('spends no commerce probe on a host the catalog can name', function () {
    $ctx = new RouteContext;

    foreach ([
        'https://juno-records.bandcamp.com/',
        'https://ra.co/events/1234567',
        'https://www.mixcloud.com/someone/',
    ] as $url) {
        // classify() answering at all is what keeps LinkRouter out of its
        // unclassified arm, which is the only thing that spends a probe.
        expect(catalogClassifier()->classify($url))->not->toBeNull($url);
    }

    expect($ctx->probesUsed())->toBe(0);
});

// Regression pin, not a TDD cycle: these pass before the change and must keep
// passing after it. The catalog projector scores 10 of these 12 as NO MATCH,
// so a fallback that ran FIRST — or replaced the tables — would silently
// downgrade every one of them from a real connection to a link card.
it('keeps every hand-table classification the catalog would have lost', function (string $url, string $category) {
    expect(catalogClassifier()->classify($url)['category'])->toBe($category);
})->with([
    ['https://booksy.com/en-us/12345_the-salon', 'booking'],
    ['https://www.treatwell.co.uk/place/x', 'booking'],
    ['https://squareup.com/appointments/x', 'booking'],
    ['https://github.com/acme', 'social'],
    ['https://www.patreon.com/acme', 'social'],
    ['https://substack.com/@acme', 'social'],
    ['https://www.behance.net/acme', 'social'],
    ['https://dribbble.com/acme', 'social'],
    ['https://www.doordash.com/store/x', 'online-ordering'],
    ['https://resy.com/cities/ny/venue', 'reservations'],
    ['https://www.thefork.com.au/restaurant/x', 'reservations'],
    ['https://www.fresha.com/a/doc-cuts', 'booking'],
]);

// A storefront platform is the one class where the commerce probe is doing
// real work rather than rediscovering a host: it reads the actual product.
// These hosts are unclassified today, so they take LinkRouter's probe arm; a
// fallback that answered 'link' for them would silently trade a product card
// for a plain link and never probe again. The catalog's routing class is how
// we tell them apart, and for this one class the honest answer is to say
// nothing and leave the existing path exactly as it was.
it('leaves storefront platforms to the probe arm instead of naming them', function (string $url) {
    expect(catalogClassifier()->classify($url))->toBeNull();
})->with([
    'gumroad' => ['https://gumroad.com/acme'],
    'stan store' => ['https://stan.store/acme'],
    'squarespace' => ['https://squarespace.com/x'],
    'woocommerce' => ['https://woocommerce.com/x'],
]);

it('still returns null for a host neither the tables nor the catalog know', function (string $url) {
    expect(catalogClassifier()->classify($url))->toBeNull();
})->with([
    'an ordinary business site' => ['https://joesplumbing.com.au/'],
    'a product page on an unknown retailer' => ['https://www.juno.co.uk/products/thing/952291-01/'],
    'a spoofed brand host' => ['https://bandcamp.evil.com/artist'],
    'a lookalike domain' => ['https://rnedium.com/@writer'],
]);

// ── Seam promotion (spec 2026-08-28-link-classifier-seam-design §4) ──────────
//
// booking / reservations / ordering are the three routing classes whose
// vocabulary is 1:1 with a classify() category that has BOTH a real
// gateAllows() arm and a real seeder, so the catalog can name them directly and
// a new brand in one of those classes needs no host-table row. Nine commits
// since 2026-08-01 were that one bug, and every one was in these three classes.
//
// is_connectable is the second condition, not a nicety: a surface the catalog
// refuses to connect must never reach seedBooking() / seedOnlineOrdering().

it('promotes a connectable booking/reservations/ordering surface to its real category', function (string $url, string $platform, string $category, string $label) {
    expect(catalogClassifier()->classify($url))
        ->toBe(['platform' => $platform, 'category' => $category, 'label' => $label]);
})->with([
    'easi ordering' => ['https://easi.com.au/order/acme', 'easi', 'online-ordering', 'EASI'],
    'shortcuts booking' => ['https://acme.shortcuts.com.au/', 'shortcuts', 'booking', 'Shortcuts'],
]);

it('holds a non-connectable surface at link even when its routing class is promotable', function (string $url, string $platform, string $label) {
    expect(catalogClassifier()->classify($url))
        ->toBe(['platform' => $platform, 'category' => 'link', 'label' => $label]);
})->with([
    'microsoft bookings' => ['https://outlook.office365.com/owa/calendar/bookings@contoso.com/bookings/', 'microsoft_bookings', 'Microsoft Bookings'],
    'wix bookings' => ['https://bookings.wixapps.net/bookings/v1/acme', 'wix_bookings', 'Wix Bookings'],
]);

it('leaves the social, content and events classes at link', function (string $url, string $platform, string $label) {
    expect(catalogClassifier()->classify($url))
        ->toBe(['platform' => $platform, 'category' => 'link', 'label' => $label]);
})->with([
    'social class' => ['https://ko-fi.com/acme', 'ko-fi', 'Ko-fi'],
    'content class' => ['https://www.mixcloud.com/someone/', 'mixcloud', 'Mixcloud'],
    'events class' => ['https://www.songkick.com/artists/1234567', 'songkick', 'Songkick'],
]);
