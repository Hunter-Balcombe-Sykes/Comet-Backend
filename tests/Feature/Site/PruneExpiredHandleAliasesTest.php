<?php

use App\Console\Commands\PruneExpiredHandleAliases;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
});

function makePrunePro(string $handle): string
{
    $proId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.users')->insert([
        'id'               => $proId,
        'handle'           => $handle,
        'handle_lc'        => $handle,
        'status'           => 'active',
        'primary_email'    => $handle.'@example.test',
        
        'created_at'       => $now,
        'updated_at'       => $now,
    ]);
    return $proId;
}

it('deletes expired aliases and re-dispatches KV sync, leaving active ones and legacy NULL-expires_at alone', function () {
    Bus::fake([SyncSubdomainToKvJob::class]);

    $proId = makePrunePro('prunetest');
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id'              => $siteId,
        'professional_id' => $proId,
        'subdomain'       => 'prunetest',
        'is_published'    => 0,
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);

    // Expired handle alias
    DB::connection('pgsql')->table('core.professional_handle_aliases')->insert([
        'id'              => (string) Str::uuid(),
        'professional_id' => $proId,
        'handle'          => 'gone-handle',
        'reclaim_until'   => now()->subDays(91)->toDateTimeString(),
        'expires_at'      => now()->subDay()->toDateTimeString(),
        'created_at'      => now()->subDays(91)->toDateTimeString(),
        'updated_at'      => now()->subDays(91)->toDateTimeString(),
    ]);

    // Active handle alias
    DB::connection('pgsql')->table('core.professional_handle_aliases')->insert([
        'id'              => (string) Str::uuid(),
        'professional_id' => $proId,
        'handle'          => 'alive-handle',
        'reclaim_until'   => now()->addDays(5)->toDateTimeString(),
        'expires_at'      => now()->addDays(60)->toDateTimeString(),
        'created_at'      => now()->subDays(30)->toDateTimeString(),
        'updated_at'      => now()->subDays(30)->toDateTimeString(),
    ]);

    // Legacy NULL-expires_at alias
    DB::connection('pgsql')->table('core.professional_handle_aliases')->insert([
        'id'              => (string) Str::uuid(),
        'professional_id' => $proId,
        'handle'          => 'legacy-handle',
        'reclaim_until'   => null,
        'expires_at'      => null,
        'created_at'      => now()->subYears(2)->toDateTimeString(),
        'updated_at'      => now()->subYears(2)->toDateTimeString(),
    ]);

    // Expired subdomain alias
    DB::connection('pgsql')->table('site.site_subdomain_aliases')->insert([
        'id'          => (string) Str::uuid(),
        'site_id'     => $siteId,
        'subdomain'   => 'gone-sub',
        'reclaim_until' => now()->subDays(91)->toDateTimeString(),
        'expires_at'  => now()->subDay()->toDateTimeString(),
        'created_at'  => now()->subDays(91)->toDateTimeString(),
    ]);

    $this->artisan(PruneExpiredHandleAliases::class)->assertSuccessful();

    expect(DB::connection('pgsql')->table('core.professional_handle_aliases')
        ->where('handle', 'gone-handle')->exists())->toBeFalse();
    expect(DB::connection('pgsql')->table('site.site_subdomain_aliases')
        ->where('subdomain', 'gone-sub')->exists())->toBeFalse();
    expect(DB::connection('pgsql')->table('core.professional_handle_aliases')
        ->where('handle', 'alive-handle')->exists())->toBeTrue();
    expect(DB::connection('pgsql')->table('core.professional_handle_aliases')
        ->where('handle', 'legacy-handle')->exists())->toBeTrue();

    Bus::assertDispatched(SyncSubdomainToKvJob::class, fn ($j) => $j->professionalId === $proId);
});
