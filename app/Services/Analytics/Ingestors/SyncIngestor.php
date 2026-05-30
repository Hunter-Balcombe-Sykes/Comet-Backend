<?php

// app/Services/Analytics/Ingestors/SyncIngestor.php

namespace App\Services\Analytics\Ingestors;

use App\Services\Analytics\AnalyticsCacheService;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Contracts\AnalyticsEventWriter;
use App\Services\Analytics\Contracts\AnalyticsIngestor;

// Inline ingest for local/testing — writes straight through the writer so dev and the
// test suite observe rows immediately (no queue worker required). Also performs the
// debounced summary-version bump inline so its side effects match the queued path
// (RecordAnalyticsEventJob bumps after the write inside the worker).
class SyncIngestor implements AnalyticsIngestor
{
    public function __construct(
        private readonly AnalyticsEventWriter $writer,
        private readonly AnalyticsCacheService $cache,
    ) {}

    public function ingest(AnalyticsEvent $event): void
    {
        $this->writer->write($event);

        $this->cache->bumpVersion($event->userId);
    }
}
