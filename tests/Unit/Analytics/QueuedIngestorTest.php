<?php
// tests/Unit/Analytics/QueuedIngestorTest.php

use App\Jobs\Analytics\RecordAnalyticsEventJob;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Ingestors\QueuedIngestor;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;

// Uses Queue::fake(), app(), config() (the job constructor reads
// partna.analytics_queue.name), and Log::warning in the fail-open path — all need a
// booted app. tests/Pest.php binds only Feature to Tests\TestCase, so opt in here.
uses(Tests\TestCase::class);

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
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('redis down'));

    $ingestor = new QueuedIngestor($bus);

    expect(fn () => $ingestor->ingest(ingestorEvent()))->not->toThrow(Throwable::class);
});
