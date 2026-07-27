<?php

namespace App\Routing\Importers;

use App\Models\Core\User\User;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\WebsiteLinkHarvester;

/**
 * Previous-website scan, re-pointed onto the router (plan §3).
 *
 * The important change from the old harvester path: this does not classify
 * anything itself and does not write connections. It finds links and hands
 * each one to the SAME routing pipeline a user's paste goes through — so a
 * link found on a website and a link typed by hand are judged identically,
 * by one engine, with one set of gates, and both leave an observation
 * explaining what happened.
 *
 * Harvested links are marked `website_import` rather than `paste`, which the
 * placement policy treats as a weaker claim: something found incidentally
 * needs more confidence to auto-apply than something a person typed.
 */
class WebsiteImporter
{
    /** Hard cap on links considered from one page. */
    private const MAX_LINKS = 200;

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly WebsiteLinkHarvester $harvester,
        private readonly LinkRoutingService $routing,
    ) {}

    /**
     * @return array{outcome: string, observations: int, connected: int, suggested: int, noted: int}
     */
    public function import(User $user, string $websiteUrl): array
    {
        $runId = ImportRun::start((string) $user->id, 'website', $websiteUrl);

        if ($runId === null) {
            return ['outcome' => 'cooldown', 'observations' => 0, 'connected' => 0, 'suggested' => 0, 'noted' => 0];
        }

        $response = $this->fetcher->tryFetch($websiteUrl);

        if ($response === null || $response['status'] !== 200 || $response['body'] === '') {
            ImportRun::finish($runId, 'unavailable', errorClass: 'fetch_failed');

            return ['outcome' => 'unavailable', 'observations' => 0, 'connected' => 0, 'suggested' => 0, 'noted' => 0];
        }

        $links = array_slice(
            $this->harvester->allOutboundLinks($response['body'], $response['finalUrl'] ?? $websiteUrl),
            0,
            self::MAX_LINKS,
        );

        $context = RoutingContext::forUser($user, 'website_import', $runId);
        $tally = ['connected' => 0, 'suggested' => 0, 'noted' => 0];
        $seen = [];

        foreach ($links as $link) {
            // One decision per distinct link: a page linking its Instagram in
            // the header and the footer is one intent, not two.
            $fingerprint = strtolower(trim($link));
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;

            $result = $this->routing->route($link, $context);

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
