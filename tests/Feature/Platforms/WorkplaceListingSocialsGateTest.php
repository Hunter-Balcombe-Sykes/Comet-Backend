<?php

// The Google half of the 2026-09-03 workplace-identity fix. A partna never
// connects a Google listing as their own identity — the one attached to them
// is the VENUE's, put there by FreshaWorkplaceLinker. Its socials are the
// salon's.
//
// Owner ruling R14 (2026-08-18) said socials seed for every account type, on
// the premise of "an individual who CONNECTS THEIR listing". That premise does
// not hold in the pre-account flow, and the gap produced lukemunnn's inbox
// row: a conflict finding whose `apply` payload swapped their own Instagram
// for the shop's @Youthofdulwich. R14 is NARROWED here, not revoked — its
// workplace, booking and website-scan clauses are untouched, and a business
// account still seeds socials exactly as before.
//
// Both of the listing's doors are covered: the legacy seedSocials() path
// (claimed accounts) and the signup-build path, which routes through
// PlacementPolicy with origin 'google_business'.

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\GoogleBusinessAutoSync;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    AccountCapabilities::flushCache();
});

function wlsgUser(string $handle, string $accountType, string $status = 'active'): User
{
    $user = User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => $accountType,
        'sector' => null,
    ]);

    // `status` is NOT in User::$fillable, so passing it to create() above is
    // silently dropped and the row lands 'active' — which quietly sent the
    // unclaimed case down the claimed branch and made its assertion pass for
    // the wrong reason. Assigned explicitly instead.
    $user->status = $status;
    $user->save();

    return $user->refresh();
}

/** The venue's listing as the Apify contacts add-on returns it. */
function wlsgListingSocials(): array
{
    return ['socials' => [
        'instagram' => 'https://www.instagram.com/Youthofdulwich',
        'facebook' => 'https://www.facebook.com/youthofdulwich',
    ]];
}

it('does not seed the workplace listing\'s Instagram onto a claimed partna account', function () {
    config(['services.apify.token' => 'apify-token']);
    Bus::fake([InstagramConnectJob::class]);
    $user = wlsgUser('wlsg-partna', 'partna');

    $findings = app(GoogleBusinessAutoSync::class)->seed((string) $user->id, wlsgListingSocials(), 'Youth Of Dulwich');

    // No connection, no finding — so nothing folds into the suggestions inbox
    // via SyncFindingsBridge offering to replace their own Instagram.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'instagram')->exists())->toBeFalse()
        ->and(collect($findings)->whereIn('category', ['social']))->toBeEmpty();

    // And no paid Apify scrape of the salon's profile.
    Bus::assertNotDispatched(InstagramConnectJob::class);
});

it('still seeds a business account\'s own listing socials — R14 is narrowed, not revoked', function () {
    config(['services.apify.token' => 'apify-token']);
    Bus::fake([InstagramConnectJob::class]);
    $user = wlsgUser('wlsg-business', 'business');

    $findings = app(GoogleBusinessAutoSync::class)->seed((string) $user->id, wlsgListingSocials(), 'Youth Of Dulwich');

    expect(collect($findings)->where('category', 'social'))->not->toBeEmpty();
});

it('refuses the listing\'s socials on the signup-build path too, where they route instead of seeding', function () {
    config(['services.apify.token' => 'apify-token']);
    Bus::fake([InstagramConnectJob::class]);
    // Unclaimed: seed() takes the routeSignupFindings branch, which hands each
    // URL to the router with origin 'google_business' rather than seeding it.
    $user = wlsgUser('wlsg-signup', 'partna', 'unclaimed');

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, wlsgListingSocials(), 'Youth Of Dulwich');

    expect(DB::table('routing.source_intents')
        ->where('user_id', $user->id)
        ->whereIn('routing_class', ['social', 'content'])
        ->count())->toBe(0);

    // Non-vacuity: the router DID run on both URLs and refused them, rather
    // than the branch never reaching it (which would pass the count above for
    // the wrong reason).
    expect(DB::table('routing.link_observations')
        ->where('user_id', $user->id)
        ->where('source', 'google_business')
        ->pluck('block_reason')->all())
        ->toBe(['workplace_not_identity', 'workplace_not_identity']);
});
