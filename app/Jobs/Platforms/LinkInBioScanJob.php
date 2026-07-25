<?php

namespace App\Jobs\Platforms;

use App\Models\Core\User\User;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Unrolls a curated link-in-bio page (Linktree/Milkshake/Beacons/Stan Store)
// found in an Instagram bio: one plain fetch, every outbound link routed
// through CustomLinkSeeder::seed() → LinkRouter. Classification, capability
// gating, and commerce probes all live in the router now (2026-07-25).
//
// Dispatched off InstagramAutoSync's main loop rather than run inline there
// because a slow fetch could blow InstagramConnectJob's timeout.
class LinkInBioScanJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [30];
    public int $timeout = 60;
    public int $uniqueFor = 300;

    public function __construct(public readonly string $userId, public readonly string $bioPageUrl)
    {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->userId.':'.sha1($this->bioPageUrl);
    }

    public function handle(SafeUrlFetcher $fetcher, WebsiteLinkHarvester $harvester, CustomLinkSeeder $seeder): void
    {
        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        $response = $fetcher->tryFetch($this->bioPageUrl);
        $html = is_array($response) && $response['status'] === 200 ? (string) $response['body'] : '';
        if ($html === '') {
            return;
        }
        $baseUrl = $response['finalUrl'] ?? $this->bioPageUrl;
        $ownHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        foreach ($harvester->allOutboundLinks($html, $baseUrl) as $url) {
            if ($ownHost !== '' && strtolower((string) parse_url($url, PHP_URL_HOST)) === $ownHost) {
                continue;
            }

            // LinkRouter (inside CustomLinkSeeder::seed()) handles classification,
            // routing, commerce probes, and custom-link fallback.
            $seeder->seed($user, $url);
        }
    }

    // mergeFindingsBack removed 2026-07-25 — findings are now real
    // IntegrationConnection rows created by LinkRouter, not payload-level
    // metadata needing merge-back. The Instagram synced modal reads from
    // IntegrationConnection directly.

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('platforms.link_in_bio_scan.failed', [
            'user_id' => $this->userId,
            'bio_page_url' => $this->bioPageUrl,
            'error' => $e->getMessage(),
        ]);
    }
}
