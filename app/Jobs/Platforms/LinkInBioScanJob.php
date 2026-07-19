<?php

namespace App\Jobs\Platforms;

use App\Models\Core\User\User;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\InstagramAutoSync;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// Unrolls a curated link-in-bio page (Linktree/Milkshake/Beacons/Stan Store)
// found in an Instagram bio: one plain fetch, every outbound link classified
// through the SAME gating InstagramAutoSync::seed() uses for a direct bio
// link (via handleClassifiedLink()), with anything that isn't auto-syncable —
// fully unclassified, or classified but gated/not-actionable — falling
// through to CustomLinkSeeder instead of vanishing. Nothing about the bio-link
// URL itself is persisted; this is a one-time inline scan.
//
// Dispatched off InstagramAutoSync's main loop rather than run inline there:
// a slow or JS-heavy link-in-bio fetch could otherwise blow
// InstagramConnectJob's own timeout and lose already-completed work in the
// same run (mirrors why PDF menu OCR is its own job too).
class LinkInBioScanJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [30];

    public int $timeout = 60;

    public function __construct(public readonly string $userId, public readonly string $bioPageUrl)
    {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->userId.':'.sha1($this->bioPageUrl);
    }

    public function handle(SafeUrlFetcher $fetcher, WebsiteLinkHarvester $harvester, InstagramAutoSync $autoSync, CustomLinkSeeder $seeder): void
    {
        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        $response = $fetcher->tryFetch($this->bioPageUrl);
        $html = is_array($response) && ($response['status'] ?? 0) === 200 ? (string) ($response['body'] ?? '') : '';
        if ($html === '') {
            return;
        }
        $baseUrl = $response['finalUrl'] ?? $this->bioPageUrl;

        $seenPlatforms = [];
        $findings = [];
        $unmatched = [];
        foreach ($harvester->allOutboundLinks($html, $baseUrl) as $url) {
            $classified = $harvester->classify($url);
            if ($classified === null) {
                $seeder->seed($user, $url);

                continue;
            }
            $autoSync->handleClassifiedLink($user, $classified, $url, $seenPlatforms, $findings, $unmatched);
        }

        // Anything handleClassifiedLink() couldn't auto-sync (gated, not
        // actionable) still becomes a custom link — there's no "unmatched
        // suggestions" surface to offer it through in this async context, so
        // the fallback is to seed it directly rather than let it vanish.
        foreach ($unmatched as $entry) {
            $url = is_array($entry) ? ($entry['url'] ?? null) : null;
            if (is_string($url)) {
                $seeder->seed($user, $url);
            }
        }
    }
}
