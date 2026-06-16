<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Services\Cloudflare\CloudflarePurgeService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// The job now resolves the active custom domain from the handle when a dispatcher
// didn't pass one (so every purge busts the custom-domain edge cache too), so handle()
// reads site.sites — set it up.
beforeEach(fn () => setupSitesTable());

it('has its own retry policy and queue (not the KV trait — see §28.7)', function () {
    $job = new CloudflareCachePurgeJob('h');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([5, 15, 60])
        ->and($job->timeout)->toBe(15)
        ->and($job->queue)->toBe('cloudflare');
});

it('delegates to CloudflarePurgeService::purgeHandle with the lowered handle', function () {
    $job = new CloudflareCachePurgeJob('Mixed-CASE');

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldReceive('purgeHandle')->once()->with('mixed-case', null);

    $job->handle($purge);
});

it('no-ops for empty handle without touching the service', function () {
    $job = new CloudflareCachePurgeJob('   ');

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldNotReceive('purgeHandle');

    $job->handle($purge);
});

it('is unique per lowered handle so a burst of site touches coalesces to one purge', function () {
    $job = new CloudflareCachePurgeJob('Mixed-CASE');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('mixed-case|')
        ->and($job->uniqueFor)->toBe(120);
});

it('passes the custom domain through to the service and into uniqueId', function () {
    $job = new CloudflareCachePurgeJob('Jane', 'Tuesdae.co');

    expect($job->uniqueId())->toBe('jane|tuesdae.co');

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldReceive('purgeHandle')->once()->with('jane', 'Tuesdae.co');
    $job->handle($purge);
});

it('resolves the active custom domain from the handle when none is passed', function () {
    // The handle-only dispatch path (IntegrationConnectionObserver et al.) must still
    // bust the custom-domain cache — the job looks it up.
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => (string) Str::uuid(),
        'subdomain' => 'jane',
        'custom_domain' => 'tuesdae.co',
        'custom_domain_status' => 'active',
    ]);

    $job = new CloudflareCachePurgeJob('Jane');

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldReceive('purgeHandle')->once()->with('jane', 'tuesdae.co');
    $job->handle($purge);
});

it('purges handle-only when the resolved custom domain is not active', function () {
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => (string) Str::uuid(),
        'subdomain' => 'jane',
        'custom_domain' => 'tuesdae.co',
        'custom_domain_status' => 'pending',
    ]);

    $job = new CloudflareCachePurgeJob('Jane');

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldReceive('purgeHandle')->once()->with('jane', null);
    $job->handle($purge);
});

it('reports to Nightwatch and logs on terminal failure', function () {
    Exceptions::fake();
    Log::spy();

    (new CloudflareCachePurgeJob('jane'))->failed(new RuntimeException('zone error'));

    // Terminal failure must reach Nightwatch (report) AND the structured log so an
    // accidental deletion of either is caught by CI.
    Exceptions::assertReported(RuntimeException::class);
    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $msg, array $ctx) => $msg === 'cloudflare.cache_purge.failed'
            && ($ctx['handle'] ?? null) === 'jane'
            && ($ctx['error'] ?? null) === 'zone error');
});
