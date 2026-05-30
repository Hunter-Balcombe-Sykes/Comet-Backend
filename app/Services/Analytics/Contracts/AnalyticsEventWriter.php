<?php
// app/Services/Analytics/Contracts/AnalyticsEventWriter.php
namespace App\Services\Analytics\Contracts;

use App\Services\Analytics\AnalyticsEvent;

// Storage seam: decides WHERE an event lands. Swap impls (Postgres → ClickHouse)
// without touching the job.
interface AnalyticsEventWriter
{
    public function write(AnalyticsEvent $event): void;

    /** @param  AnalyticsEvent[]  $events */
    public function writeMany(array $events): void;
}
