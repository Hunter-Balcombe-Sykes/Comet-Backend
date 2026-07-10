<?php

// tests/Unit/Analytics/QueuedIngestorTest.php

use App\Jobs\Analytics\RecordAnalyticsEventJob;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Ingestors\QueuedIngestor;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

// Uses Queue::fake(), app(), config() (the job constructor reads
// partna.analytics_queue.name), and Log::warning in the fail-open path — all need a
// booted app. tests/Pest.php binds only Feature to Tests\TestCase, so opt in here.
uses(TestCase::class);

// EscalatesRepeatedFaults' counter now lives in the cache (via RateLimiter::hit(),
// see the trait's docblock for why), and Laravel's testing TestCase rebuilds the
// whole application — including a fresh array cache store — before every test, so
// no manual reset is strictly needed between test cases. Clear explicitly anyway —
// cheap, and it removes any dependence on that rebuild-per-test behavior.
beforeEach(function () {
    RateLimiter::clear('analytics:fault:ingest');
});

function ingestorEvent(): AnalyticsEvent
{
    return AnalyticsEvent::fromArray([
        'id' => 'i', 'type' => AnalyticsEvent::TYPE_CLICK, 'occurred_at' => 'now',
        'user_id' => 'u', 'site_id' => 's', 'block_id' => 'b',
    ]);
}

it('dispatches a RecordAnalyticsEventJob onto the analytics queue', function () {
    Queue::fake();

    app(QueuedIngestor::class)->ingest(ingestorEvent());

    Queue::assertPushed(RecordAnalyticsEventJob::class, function ($job) {
        return $job->payload['id'] === 'i' && $job->queue === 'analytics';
    });
});

it('fails open — a dispatch exception is swallowed, never thrown', function () {
    Exceptions::fake();

    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('redis down'));

    $ingestor = new QueuedIngestor($bus);

    expect(fn () => $ingestor->ingest(ingestorEvent()))->not->toThrow(Throwable::class);

    // A single blip is a quiet breadcrumb — it must NOT reach Nightwatch.
    Exceptions::assertNothingReported();
});

it('escalates to Nightwatch only once a sustained run of dispatch failures crosses the threshold', function () {
    Exceptions::fake();

    $threshold = QueuedIngestor::FAULT_THRESHOLD;

    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->times($threshold)->andThrow(new RuntimeException('redis down'));

    $ingestor = new QueuedIngestor($bus);

    // Every failure short of the threshold stays a breadcrumb.
    for ($i = 1; $i < $threshold; $i++) {
        $ingestor->ingest(ingestorEvent());
        Exceptions::assertNothingReported();
    }

    // The threshold-th consecutive failure inside the window escalates.
    $ingestor->ingest(ingestorEvent());
    Exceptions::assertReported(RuntimeException::class);
});

// #OBS-2/#OBS-3 round-2 review gap: the old Tier 1 keyed its counter on a
// calendar-minute bucket (`intdiv(time(), 60)`), i.e. it needed 5 faults
// inside the SAME wall-clock minute. At ingest() the failing subsystem is the
// queue connection (REDIS_QUEUE_DB) — a different logical connection from the
// cache connection (REDIS_CACHE_DB) Tier 1 writes to — so Tier 1 never threw,
// and Tier 2 (which only engages when Tier 1 itself throws) never got a
// chance either. A queue-only outage at pre-beta trickle (faults minutes
// apart, not 5-within-60s) reset the calendar-minute bucket before it ever
// reached the threshold: permanent silence for the life of the outage. This
// test drives faults 90 simulated seconds apart via Carbon::setTestNow() —
// comfortably more than one calendar minute, comfortably less than the new
// 600s window — reproducing exactly that cadence.
it('escalates on a sustained run of dispatch failures spread minutes apart, not just a same-minute burst', function () {
    Exceptions::fake();

    $threshold = QueuedIngestor::FAULT_THRESHOLD; // 5

    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->times($threshold + 2)->andThrow(new RuntimeException('redis down'));

    $ingestor = new QueuedIngestor($bus);

    Carbon::setTestNow(Carbon::now());

    // Every failure short of the threshold stays a breadcrumb, even though
    // each one lands in a different real calendar minute.
    for ($i = 1; $i < $threshold; $i++) {
        $ingestor->ingest(ingestorEvent());
        Exceptions::assertNothingReported();
        Carbon::setTestNow(Carbon::now()->addSeconds(90));
    }

    // The 5th fault, 360 simulated seconds after the 1st — a calendar-minute
    // bucket would have reset itself 5+ times by now and never seen more than
    // one fault per bucket. The fixed 600s window still sees all 5.
    $ingestor->ingest(ingestorEvent());
    Exceptions::assertReportedCount(1);

    // 6th and 7th faults, still inside the same 600s window — must not re-arm.
    Carbon::setTestNow(Carbon::now()->addSeconds(90));
    $ingestor->ingest(ingestorEvent());
    Exceptions::assertReportedCount(1);

    Carbon::setTestNow(Carbon::now()->addSeconds(90));
    $ingestor->ingest(ingestorEvent());
    Exceptions::assertReportedCount(1);
});
