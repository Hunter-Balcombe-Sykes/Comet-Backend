<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Services\Cloudflare\CloudflareKvService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('has the correct retry policy and queue configuration', function () {
    $job = new SyncSubdomainToKvJob('00000000-0000-0000-0000-000000000001');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 30, 60])
        ->and($job->timeout)->toBe(30)
        ->and($job->queue)->toBe('integrations');
});

it('implements ShouldBeUnique with a 45s window keyed by professional_id (§28.6a)', function () {
    $proId = (string) Str::uuid();
    $job = new SyncSubdomainToKvJob($proId);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueFor)->toBe(45)
        ->and($job->uniqueId())->toBe($proId);
});

it('writes {type:"brand"} for a brand professional', function () {
    setupProfessionalsTable();
    setupBrandLinkTables();
    setupHandleAliasesTable();
    try {
        DB::connection('pgsql')->statement('ALTER TABLE brand.brand_partner_links ADD COLUMN site_url TEXT NULL');
    } catch (\Throwable) {
        // column already present from a prior test run in this process
    }

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'brandco',
        'handle_lc' => 'brandco',
        'professional_type' => 'brand',
        'account_type' => 'brand',
        'status' => 'active',
        'primary_email' => 'b@example.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldReceive('put')->once()->with('brandco', ['type' => 'brand'], null);
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

it('writes {type:"affiliate", redirect} when an active brand_partner_link exists', function () {
    setupProfessionalsTable();
    setupBrandLinkTables();
    setupHandleAliasesTable();
    try {
        DB::connection('pgsql')->statement('ALTER TABLE brand.brand_partner_links ADD COLUMN site_url TEXT NULL');
    } catch (\Throwable) {
        // column already present from a prior test run in this process
    }

    $proId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'partpro',
        'handle_lc' => 'partpro',
        'professional_type' => 'affiliate',
        'account_type' => 'partner',
        'status' => 'active',
        'primary_email' => 'p@example.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    // brand.brand_partner_links has no `site_url` trigger in the in-memory schema,
    // so seed it manually. The column was added in beforeEach via ALTER TABLE.
    DB::connection('pgsql')->table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $proId,
        'slot' => 0,
        'site_url' => 'https://acme.partna.au/partpro',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldReceive('put')->once()->with('partpro', [
        'type' => 'affiliate',
        'redirect' => 'https://acme.partna.au/partpro',
    ], null);
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

it('writes {type:"individual"} for a non-brand pro with no active brand_partner_links (§28.6)', function () {
    setupProfessionalsTable();
    setupBrandLinkTables();
    setupHandleAliasesTable();
    try {
        DB::connection('pgsql')->statement('ALTER TABLE brand.brand_partner_links ADD COLUMN site_url TEXT NULL');
    } catch (\Throwable) {
        // column already present from a prior test run in this process
    }

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'soloact',
        'handle_lc' => 'soloact',
        'professional_type' => 'affiliate',
        'account_type' => 'individual',
        'status' => 'active',
        'primary_email' => 'i@example.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    // §28.6: NEVER call delete() — individuals get a positive KV entry.
    $kv->shouldNotReceive('delete');
    $kv->shouldReceive('put')->once()->with('soloact', ['type' => 'individual'], null);
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});
