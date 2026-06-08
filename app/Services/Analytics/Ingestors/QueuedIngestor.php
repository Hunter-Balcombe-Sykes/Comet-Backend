<?php

namespace App\Services\Analytics\Ingestors;

use App\Jobs\Analytics\RecordAnalyticsEventJob;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Contracts\AnalyticsIngestor;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

// Production ingest: hand the event to the queue and return. Lossy fail-open — if the
// dispatch throws (Redis down) we log a breadcrumb and return normally. We deliberately
// do NOT fall back to an inline write (unlike the image pipeline): that would reintroduce
// the request-path DB coupling this whole design removes, and a single lost beacon is
// acceptable. The visitor beacon is fire-and-forget; never throw at it.
class QueuedIngestor implements AnalyticsIngestor
{
    public function __construct(private readonly Dispatcher $bus) {}

    public function ingest(AnalyticsEvent $event): void
    {
        try {
            $this->bus->dispatch(new RecordAnalyticsEventJob($event->toArray()));
        } catch (Throwable $e) {
            Log::warning('analytics.ingest.dispatch_failed', [
                'type' => $event->type,
                'site_id' => $event->siteId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
