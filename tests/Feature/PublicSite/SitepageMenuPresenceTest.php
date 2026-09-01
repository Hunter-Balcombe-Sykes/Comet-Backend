<?php

// A Menu page follows the MENU. Not the account_type enum, and not a capability
// recomputed from a column that moves under it.
//
// Two gates have stood on this line and both dropped pages whose content was
// real. Until 2026-09-01 presentPageIds dropped SitepageId::BUSINESS_ONLY (menu
// + reviews) whenever can_use_multipage_site was false — a flag answering "may
// this account select the atlas skeleton?" (#30), which is account_type
// verbatim. ollies, a Google-Business-sourced CAFE filed account_type=partna,
// shipped 105 ingested menu items in its public payload with no page to render
// them; broken-oven (171) and fred-sarson (69) did the same. The fix swapped
// that for `! $caps->can_use_menu` — narrower, correctly named, and still a
// render-time capability veto over a `sector` column with three writers and one
// path (Google → a partna account) that never stamps it at all. Any account
// whose sector went null or non-food after its menu was ingested lost the page
// silently, items still in the payload: the same incident, re-created by the
// commit that quoted it.
//
// So there is no veto here now. The guard against "partna accounts may have
// menus now" did not disappear with it — it moved to the seam that can
// actually say no, the WRITE seam, and the last test pins it there.

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

/**
 * A fetched menu row. Only MenuController, MenuContentController, MenuFetchJob
 * and the three scan jobs write this column, and every one of them checks
 * can_use_menu first — so in production its existence is itself a record that
 * the capability question was asked and answered yes.
 */
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

it('still presents the sitepage Menu page for a food business', function () {
    $pro = smpTenant('smp-food-biz', 'business', 'restaurant');
    smpFetchedMenu($pro->id);

    expect(smpPages($pro))->toContain('menu');
});

it('does not let the atlas-skeleton capability decide the sitepage Menu page', function () {
    // can_use_multipage_site is still account_type-derived and still false for
    // a partna — that is #30's contract and it is untouched. What changed is
    // that presentPageIds no longer consults it.
    $pro = smpTenant('smp-atlas', 'partna', 'cafe');
    smpFetchedMenu($pro->id);

    expect(AccountCapabilities::for($pro)->can_use_multipage_site)->toBeFalse()
        ->and(smpPages($pro))->toContain('menu');
});

it('keeps the sitepage Menu page for an account whose industry is unknown but whose menu is real', function () {
    // THE REGRESSION, both types. A null sector reads as not-food, so
    // can_use_menu is false here — and until this file was corrected that was
    // enough to strip the page while the ingested items stayed in the payload.
    // It is not enough. Two ways an account reaches this state with a real
    // menu: Google classified it into a string SectorTaxonomy maps nothing from
    // (Pret A Manger is a "Sandwich Shop"), or the sector was cleared after the
    // menu was ingested — nothing guards a manual pick the way
    // SectorProvenance::isFoodDemotion guards the Google writer.
    foreach (['partna' => 'smp-unknown-partna', 'business' => 'smp-unknown-biz'] as $type => $handle) {
        $pro = smpTenant($handle, $type, null);
        smpFetchedMenu($pro->id);

        expect(AccountCapabilities::for($pro)->can_use_menu)->toBeFalse()
            ->and(smpPages($pro))->toContain('menu');
    }
});

it('keeps the sitepage Menu page after the account moves to a non-food industry', function () {
    // The sharpest form of the same thing: a cafe that re-files itself as a
    // gym still has 105 dishes in site.menu_items. Withdrawing the page is how
    // "my Menu page disappeared and nothing told me why" happens; withdrawing
    // the ability to EDIT it is the write seam's job, below.
    $pro = smpTenant('smp-demoted', 'partna', 'gym');
    smpFetchedMenu($pro->id);

    expect(AccountCapabilities::for($pro)->can_use_menu)->toBeFalse()
        ->and(smpPages($pro))->toContain('menu');
});

it('refuses a non-food account the menu itself — the guard the render path no longer duplicates', function () {
    // The over-correction this pins shut is still pinned shut: "partna accounts
    // may have menus now" is false. A gym cannot MINT one, so in production it
    // never holds the row the tests above insert by hand, and the page it can
    // never grant needs no second refusal at render.
    foreach (['smp-gate-gym' => 'gym', 'smp-gate-unknown' => null] as $handle => $sector) {
        $pro = smpTenant($handle, 'partna', $sector);

        actingAsUser($pro)->postJson('/api/platforms/menu/refresh')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Menu is not available for your account.');
    }
});
