<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Services\Cloudflare\CloudflarePurgeService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('has its own retry policy and queue (not the KV trait — see §28.7)', function () {
    $job = new CloudflareCachePurgeJob('h');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([5, 15, 60])
        ->and($job->timeout)->toBe(15)
        ->and($job->queue)->toBe('default');
});

it('delegates to CloudflarePurgeService::purgeHandle with the lowered handle', function () {
    $job = new CloudflareCachePurgeJob('Mixed-CASE');

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldReceive('purgeHandle')->once()->with('mixed-case');

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
        ->and($job->uniqueId())->toBe('mixed-case')
        ->and($job->uniqueFor)->toBe(120);
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
