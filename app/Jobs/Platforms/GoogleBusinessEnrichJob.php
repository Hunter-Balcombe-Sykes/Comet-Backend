<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\GoogleBusinessApifyScraper;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\WebsiteLinkHarvester;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
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
class GoogleBusinessEnrichJob implements ShouldBeUnique, ShouldQueue, ThrottledByProvider
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Apify single-place run is usually well under a minute; allow headroom.
    public int $timeout = 130;

    // Unlimited attempts, bounded by retryUntil() below — the 'platform-connect'
    // RateLimited middleware RELEASES this job when the actor is over-limit, and every
    // release counts as an attempt. A finite $tries would mass-fail enrichments during
    // a burst the gate exists to absorb. Genuine errors stay capped by $maxExceptions.
    public int $tries = 0;

    /** @var list<int> Backoff between exception-triggered retries (not rate-limit releases). */
    public array $backoff = [30, 120];

    public int $maxExceptions = 2;

    // One enrichment per connection at a time. The window matches retryUntil() so a job
    // parked in rate-limit purgatory can't have a duplicate slip in and bill a second
    // Apify run. The lock also releases on completion/failure — worst-case backstop.
    public int $uniqueFor = 900;

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

    /** Apify actor for the 'platform-connect' rate budget. */
    public function providerRateKey(): string
    {
        return Platform::GoogleBusiness->value;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new RateLimited('platform-connect')];
    }

    // Wall-clock deadline for rate-limit releases. An enrichment held behind the actor's
    // per-minute limit keeps retrying until it runs or 15 min elapses, then lapses to
    // failed() (terminal) — never an infinite park.
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    public function handle(GoogleBusinessApifyScraper $scraper, GoogleBusinessAutoSync $autoSync, WebsiteLinkHarvester $harvester): void
    {
        $connection = $this->connection();
        if (! $connection) {
            return;
        }

        // In-house first: the business's OWN website usually carries the
        // social / reservation / ordering / booking links — one free fetch
        // recovers them instantly. The paid Apify run only fires when the
        // listing plausibly holds Maps-only data the site can't provide
        // (food places: menu + google reserve/food links) or the harvest
        // came back empty.
        $payload = $this->payloadOf($connection);
        $harvest = $harvester->harvest(data_get($payload, 'website'));

        $enrichment = null;
        if ($this->needsApify($harvest, data_get($payload, 'category'))) {
            $enrichment = $scraper->fetch($this->placeId, $this->userId);
        }

        if ($enrichment === null && $harvest === []) {
            // Soft failure: keep the Place Details payload, just mark the Apify
            // layer 'unavailable' so the dashboard stops polling. No hard fail —
            // the core card is unaffected and a re-connect can retry.
            $this->mark($connection, 'unavailable');

            return;
        }

        // Merge: Apify (when it ran) is the base; harvested keys overlay it —
        // links published on the business's own site are at least as
        // authoritative as ones crawled off the listing. Socials merge per
        // network so each source can fill networks the other missed.
        $enrichment = [
            ...($enrichment ?? []),
            ...array_diff_key($harvest, ['socials' => true]),
            ...(($harvest['socials'] ?? []) !== [] || ($enrichment['socials'] ?? []) !== []
                ? ['socials' => [...($enrichment['socials'] ?? []), ...($harvest['socials'] ?? [])]]
                : []),
        ];

        // The harvested links live on their OWN integrations now (Reservations /
        // Online-ordering / Social), not on the Google Business payload. Seed them
        // only into slots the user hasn't filled, tagged source:'google-business'.
        // Booking syncs for every account type; the reservation/ordering/workplace/
        // social seeds are Business-Partna only (see GoogleBusinessAutoSync::seed).
        $gbp = GoogleBusinessPayload::fromArray($connection->payload);
        $findings = $autoSync->seed(
            $this->userId,
            $enrichment,
            $gbp->name(),
            $gbp->toArray(),
        );

        // Write back business-info only: strip the enrichment keys (stale ones from
        // a pre-cleanup connect included) and record apifyFetchedAt + this run's
        // findings. apifyStatus is now a real column, not a payload key. The GB
        // payload has no public change, so saveQuietly — the seeded rows above
        // fired their own sitepage cache purges.
        $connection->forceFill([
            'payload' => [
                ...Arr::except($this->payloadOf($connection), ['menu', 'reservation', 'order', 'booking', 'socials']),
                'apifyFetchedAt' => now()->toIso8601String(),
                // What THIS scrape produced — drives the connect modal's "found
                // platforms" list (only this run's, with live status + Change-to).
                'syncFindings' => $findings,
            ],
            'apify_status' => 'ok',
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

    /**
     * Whether the paid Apify run is still needed after the website harvest.
     * Food-oriented places keep it (menu action link + google reserve/food
     * URLs only exist on the Maps listing); everyone else skips it once the
     * harvest found anything usable. An empty harvest always falls through
     * to Apify so coverage never regresses.
     */
    private function needsApify(array $harvest, mixed $category): bool
    {
        if ($harvest === []) {
            return true;
        }

        $cat = is_string($category) ? strtolower($category) : '';
        foreach (['restaurant', 'cafe', 'coffee', 'bar', 'bakery', 'food', 'pizza', 'kitchen', 'diner', 'eatery', 'bistro', 'pub', 'takeaway', 'grill'] as $kw) {
            if (str_contains($cat, $kw)) {
                return true;
            }
        }

        return false;
    }

    // The user's single google-business connection, matched on the indexed
    // place_id column — guards against clobbering after the user reconnected a
    // DIFFERENT place while this job was queued. The model's soft-delete scope
    // adds deleted_at IS NULL, matching the partial index.
    private function connection(): ?IntegrationConnection
    {
        return IntegrationConnection::query()
            ->where('user_id', $this->userId)
            ->where('platform', Platform::GoogleBusiness->value)
            ->where('place_id', $this->placeId)
            ->first();
    }

    private function mark(IntegrationConnection $connection, string $status): void
    {
        $connection->forceFill([
            'payload' => [
                ...$this->payloadOf($connection),
                'apifyFetchedAt' => now()->toIso8601String(),
            ],
            'apify_status' => $status,
        ])->saveQuietly();
    }

    /** @return array<string,mixed> */
    private function payloadOf(IntegrationConnection $connection): array
    {
        return GoogleBusinessPayload::fromArray($connection->payload)->toArray();
    }
}
