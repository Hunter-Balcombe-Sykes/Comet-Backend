<?php

// `site.platform_connections.owner_scope` — whose a connection is: the account
// holder's own ('self') or the workplace they work AT ('workplace').
//
// The gap it closes: a barber who books through the shop's Fresha and someone
// with their own booking were stored identically, so the page could only ever
// say a bare "Book now" instead of "Book at Anseo Studio" vs "Book with me".
// The answer is only knowable AT WRITE TIME — we know we just read it off the
// salon's website — so it is recorded there rather than re-derived later from a
// re-scrape or a guess.
//
// It is the same inference RoutingCapabilityGate::foreignIdentityDenial makes,
// minus the class filter. Where that one REFUSES the workplace's accounts, this
// one KEEPS the workplace's action links (the booking really is the shop's) and
// records whose they are.

use App\Models\Core\Site\IntegrationConnection;
use App\Routing\Importers\WebsiteImporter;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingCapabilityGate;
use App\Routing\RoutingContext;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    AccountCapabilities::flushCache();
});

it('marks the workplace\'s booking link as the workplace\'s, not the person\'s', function () {
    $pro = createTenant('cos-partna');
    Http::fake(['*' => Http::response('<html><body>
        <a href="https://www.fresha.com/a/anseo-studio-v0v92jna">Book now</a>
    </body></html>', 200, ['Content-Type' => 'text/html'])]);

    app(WebsiteImporter::class)->import($pro, 'https://example.com/');

    $booking = IntegrationConnection::query()
        ->where('user_id', $pro->id)->where('routing_class', 'booking')->first();

    expect($booking)->not->toBeNull()
        ->and($booking->owner_scope)->toBe('workplace');
});

it('marks a booking found in the person\'s OWN bio as their own', function () {
    // link_in_bio, not paste: a paste is a direct request and deliberately ends
    // at Choose (the dashboard's confirm flow), so it writes an intent and no
    // connection — there would be no owner_scope to assert. The bio harvest is
    // the self-origin that actually places, and it is where most of a partna's
    // real links come from.
    $pro = createTenant('cos-ownbio');

    app(LinkRoutingService::class)->route(
        'https://www.fresha.com/a/anseo-studio-v0v92jna',
        RoutingContext::forUser($pro, 'link_in_bio'),
    );

    $booking = IntegrationConnection::query()
        ->where('user_id', $pro->id)->where('routing_class', 'booking')->first();

    expect($booking)->not->toBeNull()
        ->and($booking->owner_scope)->toBe('self');
});

it('marks a business account\'s own website find as its own — its workplace IS its identity', function () {
    $pro = createTenant('cos-business', ['account_type' => 'business']);
    Http::fake(['*' => Http::response('<html><body>
        <a href="https://www.fresha.com/a/anseo-studio-v0v92jna">Book now</a>
    </body></html>', 200, ['Content-Type' => 'text/html'])]);

    app(WebsiteImporter::class)->import($pro, 'https://example.com/');

    $booking = IntegrationConnection::query()
        ->where('user_id', $pro->id)->where('routing_class', 'booking')->first();

    expect($booking)->not->toBeNull()
        ->and($booking->owner_scope)->toBe('self');
});

it('is never mass-assignable — provenance is system-written, not caller-supplied', function () {
    // Same protection created_by_catalog_digest has. A request body that could
    // set this could relabel someone's own booking as their workplace's.
    expect((new IntegrationConnection)->getFillable())->not->toContain('owner_scope');

    $pro = createTenant('cos-guard');
    $c = new IntegrationConnection([
        'user_id' => $pro->id,
        'surface_key' => 'fresha.book',
        'routing_class' => 'booking',
        'resource_id' => 'mass-assign-probe',
        'owner_scope' => 'workplace',
        'is_active' => true,
    ]);

    expect($c->owner_scope)->toBeNull();
});

describe('the derivation itself', function () {
    it('answers from origin plus the workplace-identity capability', function (string $type, string $origin, string $expected) {
        $pro = createTenant('cos-'.substr(md5($type.$origin), 0, 8), ['account_type' => $type]);

        expect(RoutingCapabilityGate::ownerScopeFor($pro, $origin))->toBe($expected);
    })->with([
        // A partna's website/listing are the venue's.
        'partna + website scan' => ['partna', 'website_import', 'workplace'],
        'partna + google listing' => ['partna', 'google_business', 'workplace'],
        // ...but their own bio and their own paste are theirs.
        'partna + link in bio' => ['partna', 'link_in_bio', 'self'],
        'partna + paste' => ['partna', 'paste', 'self'],
        // A business's website and listing are its own, by definition.
        'business + website scan' => ['business', 'website_import', 'self'],
        'business + google listing' => ['business', 'google_business', 'self'],
    ]);
});
