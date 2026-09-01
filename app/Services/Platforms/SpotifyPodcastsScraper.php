<?php

namespace App\Services\Platforms;

use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use App\Services\Platforms\ScrapeCreators\SpotifyPodcastNormalizer;
use Illuminate\Support\Facades\Log;

// Item 11f (2026-09-01): the connect lane's home for the Spotify-podcasts
// show card — the TwitchScraper seam, one endpoint. The spine's connect
// controller (and the refresher, via SpotifyPodcastsFetch) resolves an
// open.spotify.com/show/{id} link through fetchShow(); the EPISODES call
// lives in SpotifyPodcastsVendorDriver on the ingest side, so budget claims
// have exactly two homes and neither lane can spend through the other's.
//
// ScrapeCreators is the ONLY fetch path — there is no Apify actor and no
// keyless endpoint for a show's identity (oEmbed answers a show link, but
// with the player card, not publisher/description). Every miss (no key,
// budget denied, transport, husk, shape drift) returns null and the caller
// degrades to its own connect/refresh failure, never to "this show is empty".
//
// Budget contract (Item 8 adapter notes): claim BEFORE the call, release on
// transport-null, keep the slot spent on billed husks — NotFound bills a
// credit as success:true with __typename "NotFound" (recorded 2026-09-01),
// so the gate is payload shape, never HTTP status.
class SpotifyPodcastsScraper
{
    private const SOURCE = 'spotify_podcasts';

    public function __construct(
        private readonly ScrapeCreatorsClient $client,
        private readonly ScrapeCreatorsBudget $budget,
        private readonly SpotifyPodcastNormalizer $shows,
    ) {}

    /**
     * The show's identity card: id/name/url/publisher/description/artwork
     * (shape: SpotifyPodcastNormalizer::show). Accepts the show URL or the
     * bare id, because the connect strategy hands the parsed link and the
     * refresher hands whatever the payload stored.
     *
     * @return array<string, mixed>|null
     */
    public function fetchShow(string $showIdOrUrl, ?string $userId = null): ?array
    {
        $id = self::showId($showIdOrUrl);
        if ($id === null || ! $this->client->enabled() || ! $this->budget->tryClaim(self::SOURCE)) {
            return null;
        }

        $body = $this->client->get('/v1/spotify/podcast', ['id' => $id], $userId);
        if ($body === null) {
            $this->budget->release(self::SOURCE);

            return null;
        }

        $card = $this->shows->show($body);
        if ($card === null) {
            // Success-shaped husk or shape drift — billed either way, the
            // slot stays spent. Show ids are public catalogue keys, logged
            // raw like the Twitch lane's logins.
            Log::info('scrapecreators.spotify_podcasts.unusable_shape', ['endpoint' => 'podcast', 'show_id' => $id]);

            return null;
        }

        return $card;
    }

    /**
     * The show id off an open.spotify.com/show/{id} link (SpotifyConnect's
     * intl-tolerant grammar, narrowed to the one ACCOUNT kind this lane
     * owns), or a bare 22-char base62 id — the only form Spotify mints, so
     * anything looser would let a stray slug reach the billed endpoint. Pure
     * and static on purpose: the connect strategy, the refresher and the
     * vendor driver all parse through here, so the three lanes cannot drift
     * on what counts as a show.
     */
    public static function showId(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('~^https?://open\.spotify\.com/(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?show/([A-Za-z0-9]+)~i', $value, $m)) {
            return $m[1];
        }

        return preg_match('~^[A-Za-z0-9]{22}$~', $value) === 1 ? $value : null;
    }
}
