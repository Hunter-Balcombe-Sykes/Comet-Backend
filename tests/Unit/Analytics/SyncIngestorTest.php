<?php
// tests/Unit/Analytics/SyncIngestorTest.php

use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Contracts\AnalyticsEventWriter;
use App\Services\Analytics\Ingestors\SyncIngestor;

it('writes inline via the writer', function () {
    $event = AnalyticsEvent::fromArray([
        'id' => 'i', 'type' => AnalyticsEvent::TYPE_PAGEVIEW, 'occurred_at' => 'now',
        'user_id' => 'u', 'site_id' => 's',
    ]);

    $writer = Mockery::mock(AnalyticsEventWriter::class);
    $writer->shouldReceive('write')->once()->with($event);

    (new SyncIngestor($writer))->ingest($event);
});
