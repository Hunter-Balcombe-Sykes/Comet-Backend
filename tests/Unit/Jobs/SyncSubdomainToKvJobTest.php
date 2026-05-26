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
    setupProfessionalsTable();
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
