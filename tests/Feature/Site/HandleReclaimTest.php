<?php

use App\Models\Core\HandleChangeLog;
use App\Services\Site\ReclaimHandleAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    setupHandleChangeLogTable();
    setupHandleAliasesTable();
});

function makeReclaimPro(string $handle = 'current'): array
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id'                => $proId,
        'handle'            => $handle,
        'handle_lc'         => $handle,
        'status'            => 'active',
        'primary_email'     => $handle.'@example.test',
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id'                   => $siteId,
        'professional_id'      => $proId,
        'subdomain'            => $handle,
        'subdomain_changed_at' => now()->subDays(3)->toDateTimeString(),
        'is_published'         => 0,
        'created_at'           => $now,
        'updated_at'           => $now,
    ]);

    return ['proId' => $proId, 'siteId' => $siteId];
}

it('lets the original owner reclaim within the grace window, bypassing the 30-day cooldown', function () {
    ['proId' => $proId, 'siteId' => $siteId] = makeReclaimPro('current');
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.professional_handle_aliases')->insert([
        'id'              => (string) Str::uuid(),
        'professional_id' => $proId,
        'handle'          => 'old',
        'reclaim_until'   => now()->addDays(11)->toDateTimeString(),
        'expires_at'      => now()->addDays(87)->toDateTimeString(),
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);
    DB::connection('pgsql')->table('site.site_subdomain_aliases')->insert([
        'id'            => (string) Str::uuid(),
        'site_id'       => $siteId,
        'subdomain'     => 'old',
        'reclaim_until' => now()->addDays(11)->toDateTimeString(),
        'expires_at'    => now()->addDays(87)->toDateTimeString(),
        'created_at'    => $now,
    ]);

    $pro = \App\Models\Core\Professional\User::find($proId);
    app(ReclaimHandleAction::class)->execute($pro, 'old');

    // Alias should be gone (collapsed by UpdateSiteAction)
    expect(DB::connection('pgsql')->table('site.site_subdomain_aliases')
        ->where('subdomain', 'old')->where('site_id', $siteId)->exists())->toBeFalse();

    // Reason should be reclaim in log
    $log = \App\Models\Core\HandleChangeLog::where('professional_id', $proId)->latest('changed_at')->first();
    expect($log?->reason)->toBe(HandleChangeLog::REASON_RECLAIM);
});

it('refuses to reclaim once the reclaim window has passed', function () {
    ['proId' => $proId] = makeReclaimPro('current2');

    DB::connection('pgsql')->table('site.professional_handle_aliases')->insert([
        'id'              => (string) Str::uuid(),
        'professional_id' => $proId,
        'handle'          => 'oldexpired',
        'reclaim_until'   => now()->subDay()->toDateTimeString(),
        'expires_at'      => now()->addDays(60)->toDateTimeString(),
        'created_at'      => now()->subDays(15)->toDateTimeString(),
        'updated_at'      => now()->subDays(15)->toDateTimeString(),
    ]);

    $pro = \App\Models\Core\Professional\User::find($proId);

    expect(fn () => app(ReclaimHandleAction::class)->execute($pro, 'oldexpired'))
        ->toThrow(ValidationException::class);
});

it('throws 404 for a handle alias that belongs to a different professional', function () {
    ['proId' => $proIdSelf] = makeReclaimPro('self1');
    ['proId' => $proIdOther] = makeReclaimPro('other1');

    DB::connection('pgsql')->table('site.professional_handle_aliases')->insert([
        'id'              => (string) Str::uuid(),
        'professional_id' => $proIdOther,
        'handle'          => 'wantedhandle',
        'reclaim_until'   => now()->addDays(5)->toDateTimeString(),
        'expires_at'      => now()->addDays(60)->toDateTimeString(),
        'created_at'      => now()->toDateTimeString(),
        'updated_at'      => now()->toDateTimeString(),
    ]);

    $self = \App\Models\Core\Professional\User::find($proIdSelf);

    expect(fn () => app(ReclaimHandleAction::class)->execute($self, 'wantedhandle'))
        ->toThrow(NotFoundHttpException::class);
});
