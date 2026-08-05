<?php

namespace App\Services\Analytics\Ingestors;

use App\Jobs\Analytics\RecordAnalyticsEventJob;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Concerns\EscalatesRepeatedFaults;
use App\Services\Analytics\Contracts\AnalyticsIngestor;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

// Production ingest: hand the event to the queue and return. Lossy fail-open — if the
// dispatch throws (Redis down) we log a breadcrumb and return normally. We deliberately
// do NOT fall back to an inline write (unlike the image pipeline): that would reintroduce
// the request-path DB coupling this whole design removes, and a single lost beacon is
// acceptable. The visitor beacon is fire-and-forget; never throw at it.
//
// Only bound outside local/testing when queue.default !== 'sync' (see
// AppServiceProvider) — its fault escalation is latent until a queue worker is provisioned.
class QueuedIngestor implements AnalyticsIngestor
{
    use EscalatesRepeatedFaults;

    public function __construct(private readonly Dispatcher $bus) {}

    public function ingest(AnalyticsEvent $event): void
    {
        try {
            $job = new RecordAnalyticsEventJob($event->toArray());

            // Request-path dispatch takes the 3.0s-bounded `app` Redis connection, not `default`'s
            // 15.0s worker bound (drill 03, 2026-08-05: 15.06s beacon against a hung Redis). Gated on
            // the driver so `sync`/`database` queues in tests and local dev keep dispatching inline.
            if (config('queue.connections.'.config('queue.default').'.driver') === 'redis') {
                $job->onConnection('redis_request');
            }

            $this->bus->dispatch($job);
        } catch (Throwable $e) {
            Log::warning('analytics.ingest.dispatch_failed', [
                'type' => $event->type,
                'site_id' => $event->siteId,
                'error' => $e->getMessage(),
            ]);

            // A single blip stays a breadcrumb; a sustained run (queue/Redis genuinely
            // down) escalates to Nightwatch — see EscalatesRepeatedFaults.
            self::escalateIfSustained($e, 'ingest');
        }
    }
}
