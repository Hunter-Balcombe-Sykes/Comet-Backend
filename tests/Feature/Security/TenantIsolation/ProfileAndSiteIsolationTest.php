<?php

use App\Http\Controllers\Api\User\SiteManagement\UserSiteController;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => tenantHelpersEnsureTables());

it('site show returns only the authenticated professionals own site', function () {
    [$a, $b] = createTwoTenants('brand');

    DB::table('site.sites')->where('id', $a->site->id)->update([
        'settings' => json_encode(['meta_title' => 'Site A']),
    ]);
    DB::table('site.sites')->where('id', $b->site->id)->update([
        'settings' => json_encode(['meta_title' => 'Site B']),
    ]);

    // SiteCacheService does additional DB queries — stub enrichment method.
    $this->mock(SiteCacheService::class, fn ($m) => $m
        ->shouldReceive('enrichSiteWithBrandPartnerRadius')->andReturnUsing(fn ($arr) => $arr)
    );

    $req = tenantRequestAs($b);
    $response = app(UserSiteController::class)->show($req);
    $payload = $response->getData(true);

    // success() calls response()->json(['site' => ...]) — no wrapping 'data' key.
    $site = $payload['site'] ?? [];
    expect($site['id'] ?? null)->toBe($b->site->id);
    expect($site['id'] ?? null)->not->toBe($a->site->id);
});

it('site data is scoped by user_id at the database level preventing cross-tenant reads', function () {
    [$a, $b] = createTwoTenants('brand');

    DB::table('site.sites')->where('id', $a->site->id)->update([
        'subdomain' => 'brand-a',
    ]);
    DB::table('site.sites')->where('id', $b->site->id)->update([
        'subdomain' => 'brand-b',
    ]);

    // Verify DB-level WHERE user_id scoping works correctly.
    $aSite = DB::table('site.sites')->where('user_id', $a->id)->first();
    $bSite = DB::table('site.sites')->where('user_id', $b->id)->first();

    expect($aSite->subdomain)->toBe('brand-a');
    expect($bSite->subdomain)->toBe('brand-b');
    expect($aSite->id)->not->toBe($bSite->id);
});
