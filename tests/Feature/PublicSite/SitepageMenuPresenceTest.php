<?php

// The Menu page is gated on can_use_menu — the capability named for the job —
// and can_use_menu is derived from the account's SECTOR, not its account_type.
//
// Before 2026-09-01 both halves of that sentence were false. The render gate in
// presentPageIds dropped SitepageId::BUSINESS_ONLY (menu + reviews) whenever
// can_use_multipage_site was false, and that capability answers one unrelated
// question — "may this account select the atlas skeleton?" (#30) — which is
// account_type verbatim. So ollies, a Google-Business-sourced CAFE filed
// account_type=partna, shipped 105 ingested menu items in its public payload
// with no page to render them; broken-oven (171 items) and fred-sarson (69)
// did the same. A capability follows what an account IS, not the enum it was
// filed under.
//
// The guard against the obvious over-correction is the same sector read:
// ra33rty is the fourth partna account with a Google-sourced sector and it is a
// GYM, so it gets no Menu page no matter what rows exist under it.

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

function smpTenant(string $handle, string $accountType, ?string $sector): User
{
    $pro = createTenant($handle);
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)
        ->update(['account_type' => $accountType, 'sector' => $sector]);
    AccountCapabilities::flushCache();

    return User::query()->with('site')->findOrFail($pro->id);
}

/** A Google-fetched menu — the row presentPageIds reads to grant the Menu page. */
function smpFetchedMenu(string $userId): void
{
    DB::connection('pgsql')->table('site.menus')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'store_name' => 'Test Menu',
        'last_fetched_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function smpPages(User $pro): array
{
    return app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());
}

it('presents the Menu page on a sitepage for a food-sector partna account (the ollies case)', function () {
    $pro = smpTenant('smp-ollies', 'partna', 'cafe');
    smpFetchedMenu($pro->id);

    expect(AccountCapabilities::for($pro)->can_use_menu)->toBeTrue()
        ->and(smpPages($pro))->toContain('menu');
});

it('withholds the sitepage Menu page from a non-food partna account with a menu row (the ra33rty guard)', function () {
    // The over-correction this pins shut: "partna accounts may have menus now".
    // They may not — food accounts may, and a gym is not one, so the stray row
    // buys it nothing.
    $pro = smpTenant('smp-ra33rty', 'partna', 'gym');
    smpFetchedMenu($pro->id);

    expect(AccountCapabilities::for($pro)->can_use_menu)->toBeFalse()
        ->and(smpPages($pro))->not->toContain('menu');
});

it('withholds the sitepage Menu page while the account industry is unknown, for either account type', function () {
    // A null sector reads as not-food on purpose — "we do not know what this
    // is" must not grant a food page. Pret A Manger sits here: Google calls it
    // a "Sandwich Shop", SectorTaxonomy maps no sector from that string, and
    // the account has no menu at all as a result. That is a classification gap
    // upstream in SectorTaxonomy, NOT something the capability layer should
    // paper over by reading account_type again.
    foreach (['partna' => 'smp-unknown-partna', 'business' => 'smp-unknown-biz'] as $type => $handle) {
        $pro = smpTenant($handle, $type, null);
        smpFetchedMenu($pro->id);

        expect(smpPages($pro))->not->toContain('menu');
    }
});

it('still presents the sitepage Menu page for a food business — the amendment widened the gate, it did not move it', function () {
    $pro = smpTenant('smp-food-biz', 'business', 'restaurant');
    smpFetchedMenu($pro->id);

    expect(smpPages($pro))->toContain('menu');
});

it('does not let the atlas-skeleton capability decide the sitepage Menu page any more', function () {
    // can_use_multipage_site is still account_type-derived and still false for
    // a partna — that is #30's contract and it is untouched. What changed is
    // that presentPageIds no longer consults it: the Menu page above survives
    // for a partna cafe precisely while this stays false.
    $pro = smpTenant('smp-atlas', 'partna', 'cafe');
    smpFetchedMenu($pro->id);

    expect(AccountCapabilities::for($pro)->can_use_multipage_site)->toBeFalse()
        ->and(smpPages($pro))->toContain('menu');
});
