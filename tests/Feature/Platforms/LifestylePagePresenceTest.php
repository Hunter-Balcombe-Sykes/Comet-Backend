<?php

// Business Partna accounts don't offer the lifestyle/creator pages (Listen /
// Strava / Skool) — those integration groups are hidden from the
// business dashboard (Partna-Frontend HIDDEN_GROUPS), so such a connection
// can't be managed there. Without the standard-gate in presentPageIds, a
// business account with a stale/orphaned listen (etc.) connection would still
// advertise a page it can't see or remove. Standard (partna) accounts keep
// these pages. Shop is intentionally NOT gated — business accounts keep Shop
// (managed via the dedicated Products page), covered by ShopPagePresenceTest.

use App\Catalog\LegacyPlatformMap;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    AccountCapabilities::flushCache();
});

function lppConnection(string $userId, string $platform, array $payload = []): void
{
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'surface_key' => LegacyPlatformMap::surfaceFor($platform),
        'routing_class' => LegacyPlatformMap::routingClassFor(LegacyPlatformMap::surfaceFor($platform)),
        'resource_id' => 'res-'.Str::random(6),
        'payload' => json_encode($payload),
        'is_active' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function lppTenant(string $handle, string $accountType): User
{
    $pro = createTenant($handle);
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update(['account_type' => $accountType]);
    AccountCapabilities::flushCache();

    return User::query()->with('site')->findOrFail($pro->id);
}

function lppPresentPages(User $pro): array
{
    $r = app(SitepageDataResolverService::class);

    return $r->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());
}

it('drops Listen from presence for a business account with an active music connection', function () {
    $pro = lppTenant('lpp-biz-listen', 'business');
    lppConnection($pro->id, 'apple-music', ['input' => 'someone', 'name' => 'An Album']);

    expect(lppPresentPages($pro))->not->toContain('listen');
});

it('keeps Listen present for a standard account with the same music connection', function () {
    $pro = lppTenant('lpp-partna-listen', 'partna');
    lppConnection($pro->id, 'apple-music', ['input' => 'someone', 'name' => 'An Album']);

    expect(lppPresentPages($pro))->toContain('listen');
});

it('never emits the retired strava/skool pages, for any account type (pages left the taxonomy 2026-08-27)', function () {
    foreach (['business' => 'lpp-biz-lifestyle', 'partna' => 'lpp-partna-lifestyle'] as $type => $handle) {
        $pro = lppTenant($handle, $type);
        lppConnection($pro->id, 'strava', ['name' => 'A Club']);
        lppConnection($pro->id, 'skool', ['name' => 'A Community']);

        $pages = lppPresentPages($pro);
        expect($pages)->not->toContain('strava')
            ->and($pages)->not->toContain('skool');
    }
});

it('still keeps Watch present for a business account (not a lifestyle-gated page)', function () {
    // Watch (youtube/twitch/vimeo) is NOT in the business-hidden groups, so a
    // business account keeps it — guards against over-gating.
    $pro = lppTenant('lpp-biz-watch', 'business');
    lppConnection($pro->id, 'youtube', ['handle' => 'someone']);

    expect(lppPresentPages($pro))->toContain('watch');
});
