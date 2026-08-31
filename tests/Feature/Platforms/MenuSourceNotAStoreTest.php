<?php

// guzman-y-gomez, 2026-08-31. An ordering connection was created from
// https://www.ubereats.com/au/brand/guzman-y-gomez — a chain landing page, not
// a store. The sitepage rendered a live Order button over a menu that stayed
// empty, and stayed empty: the Apify actor answers a brand page 201 with an
// empty dataset, MenuApifyScraper::responseRetryable() reads any successful
// response as retryable, so the target burned its fallback attempts, negative-
// cached as 'blocked', wrote status 'unavailable', and menu:retry-unavailable
// force-dispatched the whole job again 15 minutes later. Forever.
//
// a6329551b hardened SourceProvisioner, which provisions ingest.sources rows —
// a lane that is inert for uber_eats (Actor cost class, no eagerOnConnect, not
// in ingest_scheduled_paid_sources, so auto_sync=false and nothing ever
// dispatches it). Correct in itself, but it cannot produce or prevent this
// symptom. The lane that scrapes is MenuSource -> MenuFetchJob ->
// MenuApifyScraper, and this file pins the guard there.
//
// The three URLs are the real ones from the incident and its control:
// blue-bottle's DoorDash /store/ link landed 63 items on the same code path.

use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\MenuSource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

const GYG_BRAND_URL = 'https://www.ubereats.com/au/brand/guzman-y-gomez';
const ST_ALI_STORE_URL = 'https://www.ubereats.com/au/store/st-ali/nK322';
const BLUE_BOTTLE_STORE_URL = 'https://www.doordash.com/en-CA/store/blue-bottle-2188491';

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    Queue::fake([MenuFetchJob::class]);
});

function nasUser(string $handle): User
{
    return User::create([
        'handle' => $handle, 'handle_lc' => $handle, 'display_name' => $handle,
        'first_name' => $handle,
        'account_type' => 'business', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => $handle.'@example.com',
    ]);
}

/**
 * An ordering connection carrying the surface stamp, which is the path the
 * incident took — surfaceSlug() maps uber_eats.order -> uber-eats off the stamp
 * ALONE, never looking at the url, so a guard that only hardened platformOf()
 * would not have fired here.
 */
function nasOrdering(User $user, string $surface, string $url): IntegrationConnection
{
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);

    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => $surface,
        'resource_id' => $rid,
        'payload' => ['id' => $rid, 'provider' => 'custom', 'url' => $url, 'name' => 'Order', 'source' => 'manual'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

it('builds no scrape plan and no scrape target for an uber eats brand page', function () {
    $user = nasUser('gyg');
    nasOrdering($user, 'uber_eats.order', GYG_BRAND_URL);

    // resolveAll() null => MenuFetchJob::handle() takes the clearScrapedContent
    // branch and returns before Menu::updateOrCreate — no 'pending' row, no
    // 'unavailable' row, and nothing for menu:retry-unavailable to select.
    expect(app(MenuSource::class)->resolveAll($user->id))->toBeNull();
    // storeLinks() empty => MenuApifyScraper::fetchStores() builds zero targets
    // and posts nothing to the actor. This is the assertion that stops the bill.
    expect(app(MenuSource::class)->storeLinks($user->id))->toBe([]);
});

it('keeps the brand page as a customer-facing order link', function () {
    // The whole reason the guard lives in entries()' menuPlatform and not
    // upstream at the write: a brand page is a perfectly good place to send a
    // customer, and useless only as a scrape target. Refusing the connection at
    // LinkRouter::seedOnlineOrdering() would have thrown a working Order button
    // away to fix a scraping problem.
    $user = nasUser('gyg2');
    nasOrdering($user, 'uber_eats.order', GYG_BRAND_URL);

    expect(app(MenuSource::class)->links($user->id)['orderUrl'])->toBe(GYG_BRAND_URL);
});

it('names the rejection in the log, which nothing else on this lane could', function () {
    // The scraper's own signals cannot tell "not a store" from "bot-blocked":
    // an empty dataset is indistinguishable either way, so status 'unavailable'
    // and menu.apify.empty were the only evidence the incident produced. This
    // log line is the one place the distinction exists.
    Log::spy();
    $user = nasUser('gyg3');
    nasOrdering($user, 'uber_eats.order', GYG_BRAND_URL);

    app(MenuSource::class)->resolveAll($user->id);

    Log::shouldHaveReceived('info')->withArgs(
        fn (string $event, array $ctx = []) => $event === 'menu.source.not_a_store'
            && $ctx['platform'] === 'uber-eats'
            && $ctx['path'] === '/au/brand/guzman-y-gomez'
            && $ctx['user_id'] === (string) $user->id,
    )->atLeast()->once();
});

it('still scrapes a real uber eats store url', function () {
    $user = nasUser('stali');
    nasOrdering($user, 'uber_eats.order', ST_ALI_STORE_URL);

    $plan = app(MenuSource::class)->resolveAll($user->id);

    expect($plan)->not->toBeNull()
        ->and($plan['contentSource'])->toBe('uber-eats')
        ->and($plan['storeUrls']['uber-eats'])->toBe(ST_ALI_STORE_URL)
        ->and(app(MenuSource::class)->storeLinks($user->id)['uber-eats']['storeUrl'])->toBe(ST_ALI_STORE_URL);
});

it('still scrapes the doordash control that landed 63 items', function () {
    $user = nasUser('bluebottle');
    nasOrdering($user, 'doordash.order', BLUE_BOTTLE_STORE_URL);

    $plan = app(MenuSource::class)->resolveAll($user->id);

    expect($plan)->not->toBeNull()
        ->and($plan['contentSource'])->toBe('doordash')
        ->and($plan['storeUrls']['doordash'])->toBe(BLUE_BOTTLE_STORE_URL)
        ->and(app(MenuSource::class)->storeLinks($user->id)['doordash']['storeUrl'])->toBe(BLUE_BOTTLE_STORE_URL);
});

it('leaves square alone, whose storefront IS the host root', function () {
    // Square registers no store_path_pattern on purpose. If a future edit
    // "completes the set", every real Square Online store stops scraping — the
    // storefront is served at the bare host root, with no path segment to match.
    $user = nasUser('fattuna');
    nasOrdering($user, 'square.order', 'https://fat-tuna.square.site/');

    expect(app(MenuSource::class)->storeLinks($user->id))->toHaveKey('square');
});
