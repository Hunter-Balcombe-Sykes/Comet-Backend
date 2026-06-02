<?php

// app/Services/Analytics/Contracts/AnalyticsIngestor.php

namespace App\Services\Analytics\Contracts;

use App\Services\Analytics\AnalyticsEvent;

// Transport seam: gets an event OFF the request path. Swap impls (queued → buffered)
// without touching the controller.
interface AnalyticsIngestor
{
    public function ingest(AnalyticsEvent $event): void;
}
