<?php

namespace App\Routing\Importers;

use App\Models\Core\User\User;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\EventsSeeder;
use App\Services\Platforms\MediaSeeder;
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
    private const MAX_LINKS = 500;

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly WebsiteLinkHarvester $harvester,
        private readonly LinkRoutingService $routing,
        private readonly EventsSeeder $events,
        private readonly MediaSeeder $media,
    ) {}

    /**
     * @return array{outcome: string, observations: int, connected: int, suggested: int, noted: int, items: int}
     */
    public function import(User $user, string $websiteUrl): array
    {
        $empty = ['observations' => 0, 'connected' => 0, 'suggested' => 0, 'noted' => 0, 'items' => 0];
        $runId = ImportRun::start((string) $user->id, 'website', $websiteUrl);

        if ($runId === null) {
            return ['outcome' => 'cooldown'] + $empty;
        }

        $response = $this->fetcher->tryFetch($websiteUrl);

        if ($response === null || $response['status'] !== 200 || $response['body'] === '') {
            ImportRun::finish($runId, 'unavailable', errorClass: 'fetch_failed');

            return ['outcome' => 'unavailable'] + $empty;
        }

        $links = array_slice(
            // finalUrl, not the requested URL: relative hrefs must resolve
            // against wherever the redirect chain actually landed.
            $this->harvester->allOutboundLinks($response['body'], $response['finalUrl']),
            0,
            self::MAX_LINKS,
        );

        $context = RoutingContext::forUser($user, 'website_import', $runId);
        $tally = ['connected' => 0, 'suggested' => 0, 'noted' => 0, 'items' => 0];
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

            // T8 (owner, 2026-08-20): the item arms run on the website scan
            // too — a video/track/episode or event page linked from the
            // owner's own site lands as a REAL pool item, exactly as the bio
            // scan does. Deliberately NO card write and NO commerce probe on
            // this lane: a website's outbound links are mostly navigation,
            // and carding them was the flooding this importer's notes-only
            // tally always avoided. (Third copy of the note-arm shape —
            // consolidating the three onto one service is flagged P8 work.)
            $classified = $this->harvester->classify($link);
            $category = $classified['category'] ?? null;

            // The item arm is asked of EVERY link, not only of the ones that
            // reached Note (2026-09-03). classify() states the precedence — an
            // item claim beats every account answer — but until the confidence
            // system was deleted this arm sat behind a Note gate that a
            // /track/ or /episode/ URL only cleared because it scored low. It
            // now projects to the artist's own surface, so the gate would send
            // a release to the suggestions inbox instead of the listen pool.
            //
            // The EVENT arms below stay behind the Note gate deliberately:
            // their URL shapes are `reserved_paths` in the catalog, so an
            // event page never projects to a surface in the first place.
            try {
                if ($category === 'content-item'
                    && $this->media->seedItem($user, $link, origin: 'website_import') !== null) {
                    $tally['items']++;

                    continue;
                }
            } catch (\Throwable $e) {
                report($e);
            }

            if ($result['verdict'] === 'note') {
                try {
                    // 2026-09-06: an 'event-organiser' link used to seed a
                    // live organiser account right here — the same "no
                    // harvest ever auto-connects, only ever suggests" bug
                    // already fixed tonight for LinkRouter::seedEvent()'s own
                    // eventbrite/humanitix arm (gated behind
                    // $ctx->autoConnectBooking there). This importer has no
                    // equivalent staff/no-dialog flag for any caller, so an
                    // organiser link no longer auto-connects here either — it
                    // falls through to the ordinary 'noted' tally below, the
                    // same outcome any other link this importer can't place
                    // already gets (this lane deliberately writes no card).
                    if ($category === 'event'
                        && $this->events->seedStandalone($user, (string) $classified['platform'], $link, origin: 'website_import') !== null) {
                        $tally['connected']++;

                        continue;
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }

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
