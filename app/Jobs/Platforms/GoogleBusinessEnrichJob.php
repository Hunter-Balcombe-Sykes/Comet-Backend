<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\GoogleBusinessApifyScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Background half of the Google Business connect. The Place Details snapshot is
// fetched synchronously by the controller (so the card renders instantly), while
// the slower Apify enrichment — menu / reservation / order / booking / social
// links — runs here so connect() returns immediately instead of blocking a
// PHP-FPM worker on a multi-second actor run.
//
// The connection row is written by the controller with payload.apifyStatus =
// 'pending' BEFORE this job is dispatched; the job merges the Apify result and
// flips the status to 'ok' / 'unavailable'. The dashboard polls the selection
// endpoint until the status leaves 'pending'.
class GoogleBusinessEnrichJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Apify single-place run is usually well under a minute; allow headroom.
    public int $timeout = 130;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $maxExceptions = 2;

    // One enrichment per connection at a time. The window exceeds $timeout so a
    // duplicate dispatch can't slip in and bill a second Apify run mid-flight.
    public int $uniqueFor = 180;

    public function __construct(
        public readonly string $userId,
        public readonly string $placeId,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    // Key on user + place: a true duplicate (retry / double connect of the same
    // place) dedups; reconnecting a DIFFERENT place still runs.
    public function uniqueId(): string
    {
        return $this->userId.':'.$this->placeId;
    }

    public function handle(GoogleBusinessApifyScraper $scraper): void
    {
        $connection = $this->connection();
        if (! $connection) {
            return;
        }

        $enrichment = $scraper->fetch($this->placeId, $this->userId);

        if ($enrichment === null) {
            // Soft failure: keep the Place Details payload, just mark the Apify
            // layer 'unavailable' so the dashboard stops polling. No hard fail —
            // the core card is unaffected and a re-connect can retry.
            $this->mark($connection, 'unavailable');

            return;
        }

        // Merge over the existing payload; the scraper already dropped absent
        // Apify keys, so Place Details values are never clobbered. saveQuietly:
        // nothing public changes this phase (the keys aren't in the public
        // allowlist), so there's no sitepage cache to purge.
        $connection->forceFill([
            'payload' => [
                ...$this->payloadOf($connection),
                ...$enrichment,
                'apifyStatus' => 'ok',
                'apifyFetchedAt' => now()->toIso8601String(),
            ],
        ])->saveQuietly();
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('google_business.enrich_job.failed', [
            'user_id' => $this->userId,
            'place_id' => $this->placeId,
            'error' => $e->getMessage(),
        ]);

        $connection = $this->connection();
        if ($connection) {
            $this->mark($connection, 'unavailable');
        }
    }

    // The user's single google-business connection, but only if its stored
    // placeId still matches — guards against clobbering after the user
    // reconnected a different place while this job was queued. ::query()->first()
    // respects the soft-delete scope, so a disconnected row returns null.
    private function connection(): ?IntegrationConnection
    {
        $connection = IntegrationConnection::query()
            ->where('user_id', $this->userId)
            ->where('platform', 'google-business')
            ->first();

        if (! $connection) {
            return null;
        }

        return data_get($connection->payload, 'placeId') === $this->placeId ? $connection : null;
    }

    private function mark(IntegrationConnection $connection, string $status): void
    {
        $connection->forceFill([
            'payload' => [
                ...$this->payloadOf($connection),
                'apifyStatus' => $status,
                'apifyFetchedAt' => now()->toIso8601String(),
            ],
        ])->saveQuietly();
    }

    /** @return array<string,mixed> */
    private function payloadOf(IntegrationConnection $connection): array
    {
        return is_array($connection->payload) ? $connection->payload : [];
    }
}
