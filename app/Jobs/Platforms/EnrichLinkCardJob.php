<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\LinkCardScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

// Slow-fetch enrichment for an async link-card connect (JOB-1). The connect action
// writes a usable MINIMAL card synchronously (status 'pending') and returns 202;
// this job runs snapshot() (the outbound HTTP that used to block the request thread)
// on the queue and upgrades ONLY the display fields — name/description/favicon/logo —
// leaving the stored url untouched so resource_id / storeKey dedup stays stable. A
// failed snapshot is fine: the minimal card is an acceptable final state, so the row
// still flips to 'ok'. Shared by custom links, online-ordering, booking, reservations.
class EnrichLinkCardJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public string $userId,
        public string $platform,
        public string $resourceId,
        public string $url,
    ) {
        $this->onQueue(config('partna.queues.scraping'));
    }

    public function uniqueId(): string
    {
        return $this->platform.':'.$this->resourceId;
    }

    public function handle(LinkCardScraper $scraper): void
    {
        $row = IntegrationConnection::query()
            ->where('user_id', $this->userId)
            ->where('platform', $this->platform)
            ->where('resource_id', $this->resourceId)
            ->first();

        if ($row === null) {
            return; // removed between dispatch and run
        }

        $snapshot = $scraper->snapshot($this->url); // slow HTTP; null on failure

        $payload = $row->payload;
        if ($snapshot !== null) {
            // Upgrade DISPLAY fields only — never the stored url (keeps dedup keys stable).
            foreach (['name', 'description', 'favicon', 'logo'] as $field) {
                if (($snapshot[$field] ?? null) !== null) {
                    $payload[$field] = $snapshot[$field];
                }
            }
        }

        $row->update([
            'payload' => $payload,
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
        ]);
    }
}
