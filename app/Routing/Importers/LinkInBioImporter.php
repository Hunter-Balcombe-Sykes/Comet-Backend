<?php

namespace App\Routing\Importers;

use App\Models\Core\User\User;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\WebsiteLinkHarvester;

/**
 * Link-in-bio unroll (Linktree, Beacons, Stan…), on the new router.
 *
 * Nearly the same shape as WebsiteImporter, with one rule that is NOT
 * incidental: a bio-link page is itself a platform, and its own chrome —
 * pricing, blog, help centre, signup — sits in the same anchor soup as the
 * two or three links the account owner actually put there. Measured on a real
 * page (2026-07-20): 58 anchors, 55 of them Linktree's own, all on
 * linktr.ee itself. Skipping same-host links is what stops an unroll turning
 * a user's page into a directory of someone else's marketing.
 *
 * That rule is carried forward verbatim from LinkInBioScanJob, which this
 * replaces once its findings-merge path is migrated too.
 */
class LinkInBioImporter
{
    /** Hard cap on links considered from one page. */
    private const MAX_LINKS = 100;

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly WebsiteLinkHarvester $harvester,
        private readonly LinkRoutingService $routing,
    ) {}

    /**
     * @return array{outcome: string, observations: int, connected: int, suggested: int, noted: int, skipped_chrome: int}
     */
    public function import(User $user, string $bioPageUrl): array
    {
        $empty = ['observations' => 0, 'connected' => 0, 'suggested' => 0, 'noted' => 0, 'skipped_chrome' => 0];

        $runId = ImportRun::start((string) $user->id, 'link_in_bio', $bioPageUrl);
        if ($runId === null) {
            return ['outcome' => 'cooldown'] + $empty;
        }

        $response = $this->fetcher->tryFetch($bioPageUrl);

        if ($response === null || $response['status'] !== 200 || $response['body'] === '') {
            ImportRun::finish($runId, 'unavailable', errorClass: 'fetch_failed');

            return ['outcome' => 'unavailable'] + $empty;
        }

        $baseUrl = $response['finalUrl'];
        $ownHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        $context = RoutingContext::forUser($user, 'link_in_bio', $runId);
        $tally = ['connected' => 0, 'suggested' => 0, 'noted' => 0, 'skipped_chrome' => 0];
        $seen = [];

        foreach ($this->harvester->allOutboundLinks($response['body'], $baseUrl) as $url) {
            if (count($seen) >= self::MAX_LINKS) {
                break;
            }

            // The chrome rule. Same host as the bio page itself = the
            // platform's own navigation, not the user's link.
            if ($ownHost !== '' && strtolower((string) parse_url($url, PHP_URL_HOST)) === $ownHost) {
                $tally['skipped_chrome']++;

                continue;
            }

            $fingerprint = strtolower(trim($url));
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;

            $result = $this->routing->route($url, $context);

            match ($result['verdict']) {
                'place' => $tally['connected']++,
                'choose', 'hold' => $tally['suggested']++,
                default => $tally['noted']++,
            };
        }

        $observations = count($seen);

        ImportRun::finish(
            $runId,
            'ok',
            observations: $observations,
            intents: $tally['connected'] + $tally['suggested'],
            detail: $tally,
        );

        return ['outcome' => 'ok', 'observations' => $observations] + $tally;
    }
}
