<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Staff\PartnaStaff;
use App\Notifications\Moderation\EdgePurgeFailedStaffNotification;
use App\Services\Cloudflare\CloudflarePurgeService;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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
        ->and($job->timeout)->toBe(30)
        ->and($job->queue)->toBe('cloudflare');
});

// 2026-07-20 follow-up: raised from 15 -> 180 with real margin over the derived
// worst case (~152s: ~50 sequential purge_cache HTTP calls + the 2s pacing
// ceiling — see the property's docblock). Pinned as a floor (not an exact
// value) so a future accidental reduction below the derived-safe minimum goes
// red, while still leaving room to tune upward without breaking this test.
it('keeps the purge job timeout at the two-request worst case, and short (owner plan, 2026-08-19)', function () {
    $job = new CloudflareCachePurgeJob('h');

    // Two bounded requests (timeout 10 + connect 3 each) = 26 s worst case;
    // 30 keeps margin. Short on purpose: the job's lock is sized off it, and
    // a wide lock is what swallowed a rapid second edit for minutes.
    expect($job->timeout)->toBeGreaterThanOrEqual(26)
        ->and($job->timeout)->toBeLessThanOrEqual(60)
        // And must stay clear of the 'redis' queue connection's retry_after
        // (config/queue.php, default 360s) so Redis can't re-reserve this job to
        // a second worker mid-purge — duplicating purges.
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'));
});

it('delegates to CloudflarePurgeService::purgeHandle with the lowered handle', function () {
    $job = new CloudflareCachePurgeJob('Mixed-CASE');

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldReceive('purgeHandle')->once()->with('mixed-case', null);

    $job->handle($purge);
});

it('does not touch the purge service for empty handle when called directly (fail() no-ops without a bound queue job)', function () {
    $job = new CloudflareCachePurgeJob('   ');

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldNotReceive('purgeHandle');

    $job->handle($purge);
});

it('fails the job (Nightwatch-visible) instead of silently succeeding when the handle is empty and a queue job is attached', function () {
    // Without a bound queue job, InteractsWithQueue::fail() is a no-op — Horizon
    // only sees the failure when a real dispatch (with a Job contract) hits this
    // path, so the test must attach one to prove the signal actually fires.
    $job = new CloudflareCachePurgeJob('   ');

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(RuntimeException::class));
    $job->setJob($queueJob);

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldNotReceive('purgeHandle');

    $job->handle($purge);
});

it('is unique per lowered handle so a burst of site touches coalesces to one purge', function () {
    $job = new CloudflareCachePurgeJob('Mixed-CASE');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('mixed-case|')
        ->and($job->uniqueFor)->toBe(35);
});

// The invariant behind that 240, pinned separately from the literal so a future
// $timeout bump can't silently break it again. ShouldBeUnique's lock is a cache
// lock with TTL = $uniqueFor, force-released only on CLEAN completion — a
// timeout-kill leaves it to expire on its own. So uniqueFor <= timeout means a
// slow purge loses dedupe protection while still running and a duplicate slips
// through. This exact regression shipped when $timeout went 15 -> 180 while
// uniqueFor stayed at 120.
it('keeps the primary purge lock longer than the job can run — but only just, so a second edit is never swallowed for long', function () {
    $job = new CloudflareCachePurgeJob('somehandle');

    expect($job->uniqueFor)->toBeGreaterThan($job->timeout)
        ->and($job->uniqueFor)->toBeLessThanOrEqual(60);
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

it('dispatches one follow-up per schedule entry, up-front, at the configured offsets', function () {
    // Up-front dispatch, not a chain: a chain loses its tail if any link
    // exhausts its retries. The depth discriminator in uniqueId() keeps the
    // entries from coalescing, and the 5s uniqueFor lock expires long before
    // any delay elapses. Three entries here to exercise the loop — the
    // shipped default is ONE (60 s) since the prefix-purge rewrite.
    config()->set('partna.cache.purge_followup_schedule', [120, 300, 900]);

    $job = new CloudflareCachePurgeJob('Jane', 'Tuesdae.co');

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldReceive('purgeHandle')->once();
    $job->handle($purge);

    Queue::assertPushed(CloudflareCachePurgeJob::class, 3);

    foreach ([1, 2, 3] as $depth) {
        Queue::assertPushed(CloudflareCachePurgeJob::class,
            fn (CloudflareCachePurgeJob $f) => $f->followUp === true
                && $f->followUpDepth === $depth
                && $f->handle === 'Jane'
                && $f->customDomain === 'Tuesdae.co'
                && $f->delay !== null
        );
    }
});

it('honours a shortened follow-up schedule', function () {
    config()->set('partna.cache.purge_followup_schedule', [60]);

    $job = new CloudflareCachePurgeJob('jane');

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldReceive('purgeHandle')->once();
    $job->handle($purge);

    Queue::assertPushed(CloudflareCachePurgeJob::class, 1);
    Queue::assertPushed(CloudflareCachePurgeJob::class,
        fn (CloudflareCachePurgeJob $f) => $f->followUp === true && $f->followUpDepth === 1
    );
});

it('follow-up purges but never dispatches anything itself', function () {
    // followUpDepth exists only to feed uniqueId() and logging. No job ever
    // re-dispatches — the primary owns the whole schedule.
    $job = new CloudflareCachePurgeJob('jane', null, followUp: true, followUpDepth: 2);

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

it('gives each follow-up depth its own lock namespace and a lock shorter than its delay', function () {
    config()->set('partna.cache.purge_followup_schedule', [120, 300, 900]);

    $first = new CloudflareCachePurgeJob('Jane', 'Tuesdae.co', followUp: true, followUpDepth: 1);
    $third = new CloudflareCachePurgeJob('Jane', 'Tuesdae.co', followUp: true, followUpDepth: 3);

    // Depth in the id: without it the three up-front follow-ups coalesce into one.
    expect($first->uniqueId())->toBe('jane|tuesdae.co|fu1')
        ->and($third->uniqueId())->toBe('jane|tuesdae.co|fu3')
        ->and($first->uniqueId())->not->toBe($third->uniqueId())
        ->and($first->uniqueFor)->toBe(5)
        ->and($first->uniqueFor)->toBeLessThan(min(config('partna.cache.purge_followup_schedule')));
});

it('defaults followUpDepth to a class-level 0 so pre-deploy payloads survive unserialization', function () {
    // A promoted readonly param has no class default: an in-flight payload
    // serialized before this change would unserialize with the property
    // uninitialized and fatal in uniqueId() on retry. Same scar as $bulk.
    $reflection = new ReflectionProperty(CloudflareCachePurgeJob::class, 'followUpDepth');

    expect($reflection->isPromoted())->toBeFalse()
        ->and($reflection->hasDefaultValue())->toBeTrue()
        ->and($reflection->getDefaultValue())->toBe(0);
});

it('carries the moderation discriminator in uniqueId when a case id is set (EDGE-1)', function () {
    // A moderation purge must not be coalesced away by a routine purge for the
    // same handle already in flight — it's the sole backstop for hide_content.
    $job = new CloudflareCachePurgeJob('Jane', 'Tuesdae.co', moderationCaseId: 'case-123');

    expect($job->uniqueId())->toBe('jane|tuesdae.co|mcase-123');
});

it('uniqueId is plain (no discriminator) when moderationCaseId is not set', function () {
    $job = new CloudflareCachePurgeJob('Jane', 'Tuesdae.co');

    expect($job->uniqueId())->toBe('jane|tuesdae.co');
});

// R3-CACHE-1: a delayed bulk (takedown fan-out) purge must NOT hold the same
// dispatch-time ShouldBeUnique lock as a real-time purge for the same handle —
// that would silently suppress the affected user's own real-time purge.
it('carries the bulk discriminator in uniqueId when bulk:true, and is unchanged otherwise', function () {
    $bulk = new CloudflareCachePurgeJob('jane', 'tuesdae.co', bulk: true);
    $routine = new CloudflareCachePurgeJob('jane', 'tuesdae.co');

    expect($bulk->uniqueId())->toBe('jane|tuesdae.co|b')
        ->and($routine->uniqueId())->toBe('jane|tuesdae.co');
});

it('routes to the cloudflare_bulk queue when bulk:true, and cloudflare otherwise', function () {
    $bulk = new CloudflareCachePurgeJob('jane', bulk: true);
    $routine = new CloudflareCachePurgeJob('jane');

    expect($bulk->queue)->toBe(config('partna.queues.cloudflare_bulk'))
        ->and($routine->queue)->toBe('cloudflare');
});

it('forwards bulk:true to the delayed follow-up purge, so it also stays on the cloudflare_bulk lane', function () {
    $job = new CloudflareCachePurgeJob('Jane', 'Tuesdae.co', bulk: true);

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldReceive('purgeHandle')->once();
    $job->handle($purge);

    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $followUp) {
        return $followUp->followUp === true
            && $followUp->bulk === true
            && $followUp->queue === config('partna.queues.cloudflare_bulk');
    });
});

it('forwards moderationCaseId to the delayed follow-up purge', function () {
    $job = new CloudflareCachePurgeJob('Jane', 'Tuesdae.co', moderationCaseId: 'case-123');

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldReceive('purgeHandle')->once();
    $job->handle($purge);

    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $followUp) {
        return $followUp->followUp === true
            && $followUp->moderationCaseId === 'case-123';
    });
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

it('escalates to on-call staff on terminal failure when moderationCaseId is set (EDGE-1)', function () {
    // hide_content leaves the owner active, so SyncSubdomainToKvJob never retires
    // the KV entry — this purge is the ONLY backstop. A silent permanent failure
    // must page on-call, not just log.
    setupPartnaStaffTable();
    Exceptions::fake();
    Log::spy();
    Notification::fake();
    $staff = PartnaStaff::factory()->create(['role' => 'admin']);

    (new CloudflareCachePurgeJob('jane', moderationCaseId: 'case-123'))
        ->failed(new RuntimeException('zone error'));

    Exceptions::assertReported(RuntimeException::class);
    Log::shouldHaveReceived('error')->once();
    Notification::assertSentTo(
        $staff,
        EdgePurgeFailedStaffNotification::class,
        fn ($notification) => $notification->handle === 'jane' && $notification->moderationCaseId === 'case-123',
    );
});

it('does NOT escalate on terminal failure for a routine (non-moderation) purge — alert-fatigue guard', function () {
    setupPartnaStaffTable();
    Exceptions::fake();
    Log::spy();
    Notification::fake();
    PartnaStaff::factory()->create(['role' => 'admin']);

    (new CloudflareCachePurgeJob('jane'))->failed(new RuntimeException('zone error'));

    Exceptions::assertReported(RuntimeException::class);
    Log::shouldHaveReceived('error')->once();
    Notification::assertNothingSent();
});

it('ships ONE follow-up by default — 60 s, clearing the API payload s-maxage window (owner plan, 2026-08-19)', function () {
    $schedule = config('partna.cache.purge_followup_schedule');

    expect($schedule)->toBe([60]);
    // Must clear the follow-up lock (5) and the API layer's 30 s s-maxage.
    expect(min($schedule))->toBeGreaterThan(30);
});
