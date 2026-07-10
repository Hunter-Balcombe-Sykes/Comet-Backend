<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Services\Cloudflare\CloudflarePurgeService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// The job now resolves the active custom domain from the handle when a dispatcher
// didn't pass one (so every purge busts the custom-domain edge cache too), so handle()
// reads site.sites — set it up. Queue is faked because a primary purge dispatches a
// delayed follow-up purge; on the sync test queue that would execute inline.
beforeEach(function () {
    setupSitesTable();
    Queue::fake();
});

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

it('dispatches one delayed follow-up purge after the primary purge', function () {
    // A visitor racing the primary purge can re-pin payload-stale HTML into the
    // router's 24h edge cache (the Laravel Cloud edge in front of
    // api/public/profiles honours s-maxage and our zone purge can't reach it).
    // The delayed follow-up evicts that re-pin once every payload TTL has lapsed.
    $job = new CloudflareCachePurgeJob('Jane', 'Tuesdae.co');

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldReceive('purgeHandle')->once();
    $job->handle($purge);

    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $followUp) {
        return $followUp->followUp === true
            && $followUp->handle === 'Jane'
            && $followUp->customDomain === 'Tuesdae.co'
            && $followUp->delay !== null;
    });
    Queue::assertPushed(CloudflareCachePurgeJob::class, 1);
});

it('follow-up purges but never chains another follow-up', function () {
    $job = new CloudflareCachePurgeJob('jane', null, followUp: true);

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldReceive('purgeHandle')->once()->with('jane', null);
    $job->handle($purge);

    Queue::assertNothingPushed();
});

it('does not dispatch a follow-up when the handle no-ops', function () {
    $job = new CloudflareCachePurgeJob('   ');

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldNotReceive('purgeHandle');
    $job->handle($purge);

    Queue::assertNothingPushed();
});

it('gives follow-ups their own lock namespace and a shorter lock than their delay', function () {
    $followUp = new CloudflareCachePurgeJob('Jane', 'Tuesdae.co', followUp: true);

    // '|fu' suffix: the primary's in-flight lock must not swallow the follow-up.
    // uniqueFor (30) < delay (120): a lock outliving the dispatch delay would
    // coalesce away the follow-up owed to a later edit.
    expect($followUp->uniqueId())->toBe('jane|tuesdae.co|fu')
        ->and($followUp->uniqueFor)->toBe(30)
        ->and($followUp->uniqueFor)->toBeLessThan((int) config('partna.cache.purge_followup_seconds', 120));
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
