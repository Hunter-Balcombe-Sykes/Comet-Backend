<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Notifications\FindingsNotifier;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\InstagramAutoSync;
use App\Services\Platforms\Registry\Platform;
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
        $ownHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        $seenPlatforms = [];
        $findings = [];
        $unmatched = [];
        foreach ($harvester->allOutboundLinks($html, $baseUrl) as $url) {
            // A curated bio-link page (Linktree et al) is itself a platform with
            // its own site-wide chrome — pricing, blog, help centre — mixed into
            // the same anchor soup as the 2-3 links the account owner actually
            // put there. Confirmed live: every chrome link shares the bio page's
            // own host, every real content link is on a different host. Without
            // this, the "nothing vanishes" fallback below would seed every one
            // of those chrome links as a custom link on the connecting user's
            // site instead of just their own.
            if ($ownHost !== '' && strtolower((string) parse_url($url, PHP_URL_HOST)) === $ownHost) {
                continue;
            }

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

        $this->mergeFindingsBack($findings);
    }

    /**
     * Fold this scan's findings into the Instagram connection payload's
     * syncFindings — the SAME list GET /platforms/instagram/synced serves — so
     * a conflict found one hop into the bio page gets the identical Swap
     * surface as one found directly in the bio, instead of vanishing when this
     * job returns. Deduped by platform (the direct bio scan ran first and its
     * finding for a platform stands). New findings also raise a notification:
     * this job races the connect modal's lifetime, so by the time it lands the
     * user has usually navigated away.
     *
     * @param  list<array<string,mixed>>  $findings
     */
    private function mergeFindingsBack(array $findings): void
    {
        if ($findings === []) {
            return;
        }

        $ig = IntegrationConnection::query()
            ->where('user_id', $this->userId)
            ->where('platform', Platform::Instagram->value)
            ->first();
        if ($ig === null) {
            return;
        }

        $payload = is_array($ig->payload) ? $ig->payload : [];
        $existing = array_values(array_filter((array) ($payload['syncFindings'] ?? []), 'is_array'));
        $seenPlatforms = array_flip(array_filter(array_map(
            static fn (array $f) => $f['platform'] ?? null,
            $existing,
        ), 'is_string'));

        $fresh = [];
        foreach ($findings as $finding) {
            $platform = $finding['platform'] ?? null;
            if (is_string($platform) && ! isset($seenPlatforms[$platform])) {
                $seenPlatforms[$platform] = true;
                $fresh[] = $finding;
            }
        }
        if ($fresh === []) {
            return;
        }

        // Quiet write, matching InstagramController::applySync's findings-only
        // update: syncFindings are internal (never in the public resource), so
        // no cache purge or observer churn is warranted.
        $ig->forceFill(['payload' => [...$payload, 'syncFindings' => [...$existing, ...$fresh]]])->saveQuietly();

        // Bell only for conflicts — a decision the user doesn't know they have.
        // Seeded findings are already visible as real connections in
        // Integrations; pinging for those would just be noise.
        $hasConflict = array_filter($fresh, static fn (array $f) => ($f['outcome'] ?? null) === 'conflict') !== [];
        if ($hasConflict) {
            app(FindingsNotifier::class)->notify(
                $this->userId,
                'link-in-bio-findings:'.$this->userId.':'.sha1($this->bioPageUrl),
                'We found more in your bio link',
                'Your link-in-bio page mentions an integration that clashes with one you have connected — review it in Integrations.',
            );
        }
    }
}
