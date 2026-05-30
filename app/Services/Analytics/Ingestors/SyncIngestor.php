<?php
// app/Services/Analytics/Ingestors/SyncIngestor.php
namespace App\Services\Analytics\Ingestors;

use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Contracts\AnalyticsEventWriter;
use App\Services\Analytics\Contracts\AnalyticsIngestor;

// Inline ingest for local/testing — writes straight through the writer so dev and the
// test suite observe rows immediately (no queue worker required).
class SyncIngestor implements AnalyticsIngestor
{
    public function __construct(private readonly AnalyticsEventWriter $writer) {}

    public function ingest(AnalyticsEvent $event): void
    {
        $this->writer->write($event);
    }
}
