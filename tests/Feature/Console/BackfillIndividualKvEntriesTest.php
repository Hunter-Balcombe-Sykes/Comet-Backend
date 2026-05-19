<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupBrandLinkTables();
});

it('dispatches SyncSubdomainToKvJob for each individual (non-brand, no active link)', function () {
    Bus::fake();

    $brandId = (string) Str::uuid();
    $partnerId = (string) Str::uuid();
    $individualId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.professionals')->insert([
        [
            'id' => $brandId,
            'handle' => 'brand1',
            'handle_lc' => 'brand1',
            'professional_type' => 'brand',
            'account_type' => 'brand',
            'status' => 'active',
            'primary_email' => 'b@x.test',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
        [
            'id' => $partnerId,
            'handle' => 'partner1',
            'handle_lc' => 'partner1',
            'professional_type' => 'affiliate',
            'account_type' => 'partner',
            'status' => 'active',
            'primary_email' => 'p@x.test',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
        [
            'id' => $individualId,
            'handle' => 'solo1',
            'handle_lc' => 'solo1',
            'professional_type' => 'affiliate',
            'account_type' => 'individual',
            'status' => 'active',
            'primary_email' => 'i@x.test',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
    ]);

    DB::connection('pgsql')->table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $partnerId,
        'slot' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->artisan('partna:backfill-individual-kv-entries')
        ->expectsOutputToContain('Target cohort: 1')
        ->assertSuccessful();

    Bus::assertDispatched(SyncSubdomainToKvJob::class, fn ($job) => $job->professionalId === $individualId);
    Bus::assertDispatchedTimes(SyncSubdomainToKvJob::class, 1);
});

it('--dry-run reports the cohort and dispatches nothing', function () {
    Bus::fake();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => (string) Str::uuid(),
        'handle' => 'solo2',
        'handle_lc' => 'solo2',
        'professional_type' => 'affiliate',
        'account_type' => 'individual',
        'status' => 'active',
        'primary_email' => 'i2@x.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->artisan('partna:backfill-individual-kv-entries', ['--dry-run' => true])
        ->expectsOutputToContain('Target cohort: 1')
        ->assertSuccessful();

    Bus::assertNothingDispatched();
});
