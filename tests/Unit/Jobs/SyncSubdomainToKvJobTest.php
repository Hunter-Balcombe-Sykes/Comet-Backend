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
        ->and($job->queue)->toBe('default');
});

it('implements ShouldBeUnique with a 45s window keyed by user_id (§28.6a)', function () {
    $proId = (string) Str::uuid();
    $job = new SyncSubdomainToKvJob($proId);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueFor)->toBe(45)
        ->and($job->uniqueId())->toBe($proId);
});

it('writes {type:"individual"} for an individual professional (§28.6)', function () {
    setupUsersTable();
    setupHandleAliasesTable();

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'soloact',
        'handle_lc' => 'soloact',
        'account_type' => 'individual',
        'status' => 'active',
        'primary_email' => 'i@example.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    // §28.6: individuals get a positive KV entry.
    $kv->shouldNotReceive('delete');
    $kv->shouldReceive('put')->once()->with('soloact', ['type' => 'individual'], null);
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

it('deletes the KV entry for a soft-deleted professional (#P2-45)', function () {
    setupUsersTable();
    setupHandleAliasesTable();

    $proId = (string) Str::uuid();
    // Soft-deleted: deleted_at set. find() excludes it; withTrashed() finds it,
    // and trashed() routes the job into the retire branch.
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'goneact',
        'handle_lc' => 'goneact',
        'account_type' => 'individual',
        'status' => 'active',
        'primary_email' => 'g@example.test',
        'deleted_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('put');
    $kv->shouldReceive('delete')->once()->with('goneact');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

it('deletes the captured handle when the professional row is hard-deleted (#P2-45)', function () {
    setupUsersTable();
    setupHandleAliasesTable();

    // No row exists (hard delete) — the job must fall back to the handle
    // captured at dispatch time by UserObserver::deleted.
    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('put');
    $kv->shouldReceive('delete')->once()->with('vanished');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob((string) Str::uuid(), 'vanished'))->handle($kv);
});

it('no-ops when the professional is gone and no handle was captured (#P2-45)', function () {
    setupUsersTable();
    setupHandleAliasesTable();

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('put');
    $kv->shouldNotReceive('delete');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob((string) Str::uuid()))->handle($kv);
});
