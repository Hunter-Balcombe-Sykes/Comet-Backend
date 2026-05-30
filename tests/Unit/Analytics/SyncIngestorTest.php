<?php

// tests/Unit/Analytics/SyncIngestorTest.php

use App\Services\Analytics\AnalyticsCacheService;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Contracts\AnalyticsEventWriter;
use App\Services\Analytics\Ingestors\SyncIngestor;

it('writes inline via the writer and bumps the summary version (parity with the queued path)', function () {
    $event = AnalyticsEvent::fromArray([
        'id' => 'i', 'type' => AnalyticsEvent::TYPE_PAGEVIEW, 'occurred_at' => 'now',
        'user_id' => 'u', 'site_id' => 's',
    ]);

    $writer = Mockery::mock(AnalyticsEventWriter::class);
    $writer->shouldReceive('write')->once()->with($event);

    $cache = Mockery::mock(AnalyticsCacheService::class);
    $cache->shouldReceive('bumpVersion')->once()->with('u');

    (new SyncIngestor($writer, $cache))->ingest($event);
});
