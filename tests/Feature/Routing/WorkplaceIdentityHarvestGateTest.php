<?php

// A partna account's `previous_website` is their WORKPLACE's site —
// WorkplaceObserver dispatches the scan off the workplace row, so the page
// being harvested belongs to the salon, gym or clinic they work at. The
// socials on it are the venue's, and (on a staff page) every colleague's.
//
// Routing those as the individual's own identity produced both halves of the
// 2026-09-03 report: the venue's Instagram offered as a Swap against the
// user's own — "You already have Instagram connected — swap it for this
// one?" — and, wherever the slot happened to be free, the venue's TikTok /
// Facebook / LinkedIn silently APPLIED (the 2026-08-18 harvest-maximisation
// ruling auto-applies the suggest band on any indirect origin).
//
// The owner ruling this enforces is not new: ScanPreviousWebsiteContentJob
// already refuses to take DESIGN evidence (logo, accent) off that same page
// for the same reason, 2026-08-19 — "a partna account's workplace website is
// someone else's brand". This extends it from brand to identity. It stays
// scoped to the `social` class: a booking, ordering or reservations link on
// that page IS the individual's to use (a barber books through the shop's
// Fresha), and keeps routing untouched.

use App\Models\Core\Site\IntegrationConnection;
use App\Routing\Importers\WebsiteImporter;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// example.com throughout: SafeUrlFetcher::assertSafe() does a REAL DNS lookup
// before the fetch, which runs even under Http::fake(). Same reasoning as
// WebsiteImporterTest.
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    AccountCapabilities::flushCache();
});

/** The venue's page: the shop's Instagram and YouTube (accounts), and its Fresha (an action link). */
function wihgVenuePage(): void
{
    Http::fake(['*' => Http::response('<html><body>
        <a href="https://www.instagram.com/thebarberclubau">Our Instagram</a>
        <a href="https://www.youtube.com/@thebarberclubau">Our YouTube</a>
        <a href="https://www.fresha.com/a/the-barber-club-port-melbourne-melbourne-103a-bay-street-tjggebwn">Book now</a>
    </body></html>', 200, ['Content-Type' => 'text/html'])]);
}

it('does not route the workplace website\'s Instagram onto a partna account', function () {
    $pro = createTenant('wihg-partna');
    wihgVenuePage();

    app(WebsiteImporter::class)->import($pro, 'https://example.com/');

    // No intent — so nothing reaches the suggestions inbox to be swapped in.
    expect(DB::table('routing.source_intents')
        ->where('user_id', $pro->id)->where('surface_key', 'instagram.profile')->count())->toBe(0);

    // And nothing silently connected either.
    expect(IntegrationConnection::query()
        ->where('user_id', $pro->id)->where('surface_key', 'instagram.profile')->exists())->toBeFalse();
});

it('does not route the workplace\'s YouTube channel either — a channel is an account, same as a profile', function () {
    // jaidenacallar carried @sondermens on BOTH tiktok and youtube: refusing
    // the shop's Instagram while keeping its YouTube would be the same wrong
    // claim in a different vocabulary.
    $pro = createTenant('wihg-content');
    wihgVenuePage();

    app(WebsiteImporter::class)->import($pro, 'https://example.com/');

    expect(DB::table('routing.source_intents')
        ->where('user_id', $pro->id)->where('routing_class', 'content')->count())->toBe(0)
        ->and(IntegrationConnection::query()
            ->where('user_id', $pro->id)->where('surface_key', 'youtube.channel')->exists())->toBeFalse();
});

it('still records the observation, so the decision is explained rather than invisible', function () {
    $pro = createTenant('wihg-observed');
    wihgVenuePage();

    app(WebsiteImporter::class)->import($pro, 'https://example.com/');

    $observation = DB::table('routing.link_observations')
        ->where('user_id', $pro->id)
        ->where('surface_key', 'instagram.profile')
        ->first();

    expect($observation)->not->toBeNull()
        ->and($observation->verdict)->toBe('reject')
        ->and($observation->block_reason)->toBe('workplace_not_identity');
});

it('still routes the workplace\'s BOOKING link — a barber books through the shop\'s Fresha', function () {
    $pro = createTenant('wihg-booking');
    wihgVenuePage();

    app(WebsiteImporter::class)->import($pro, 'https://example.com/');

    // The gate is scoped to identity; the action classes are untouched.
    expect(DB::table('routing.source_intents')
        ->where('user_id', $pro->id)->where('surface_key', 'fresha.book')->count())->toBe(1);
});

it('leaves a business account alone — its website IS its own brand', function () {
    $pro = createTenant('wihg-business', ['account_type' => 'business']);
    wihgVenuePage();

    app(WebsiteImporter::class)->import($pro, 'https://example.com/');

    expect(DB::table('routing.source_intents')
        ->where('user_id', $pro->id)->where('surface_key', 'instagram.profile')->count())->toBe(1);
});

it('gates the harvest, not the platform: a partna pasting that same Instagram still routes it', function () {
    $pro = createTenant('wihg-paste');

    app(LinkRoutingService::class)->route(
        'https://www.instagram.com/thebarberclubau',
        RoutingContext::forUser($pro, 'paste'),
    );

    // A person typing a URL is stating a fact about themselves — the same
    // distinction PreviousWebsiteGate draws between manual and automatic writes.
    expect(DB::table('routing.source_intents')
        ->where('user_id', $pro->id)->where('surface_key', 'instagram.profile')->count())->toBe(1);
});
