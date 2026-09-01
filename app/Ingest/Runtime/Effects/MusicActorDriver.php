<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Cache\ApifyBudget;
use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\Actors\MusicActorAdapter;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use App\Services\Platforms\ScrapeCreators\SoundcloudTracksNormalizer;
use App\Services\Platforms\ScrapeCreators\SpotifyArtistNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ('actor', 'music') — the paid Apify track scrape behind Spotify and
 * SoundCloud. Written for convergence Phase 4; before it existed, a connector
 * declaring this effect died in HttpIo::runBilledEffect() with "No
 * billed-effect driver is wired", which is the wall slice 4's menu proof hit.
 *
 * ORDERING IS LOAD-BEARING, for the same reason it is in InstagramActorDriver:
 * every check that can refuse a run happens BEFORE the budget claim, so a
 * missing token or an unconfigured platform cannot burn a daily slot doing
 * nothing. Both refusals throw EffectNotAttempted, which releases the ledger
 * claim rather than locking the source for the whole freshness window.
 *
 * The two outcomes are deliberately NOT interchangeable (BilledEffectDriver
 * rule 2). An artist with no public tracks is Answered([]) — settled, not
 * re-billed next run. A vendor that did not respond is NoAnswer — never cached
 * as truth.
 *
 * Per-platform actor id and adapter live in config('partna.music.platforms'),
 * so adding a third music platform is a config entry plus one adapter class.
 * The daily caps live under partna.limits.apify.actors keyed `music-{platform}`
 * — ApifyBudget defaults a missing cap to 0, which denies every claim, so a new
 * platform MUST add its cap or it will silently never run.
 */
final class MusicActorDriver implements BilledEffectDriver
{
    public function __construct(private readonly ApifyBudget $budget) {}

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'actor' && $name === 'music';
    }

    public function run(BilledEffectContext $ctx): BilledEffectResult
    {
        $platform = trim((string) ($ctx->input['platform'] ?? ''));
        $identifier = trim((string) ($ctx->input['identifier'] ?? ''));

        if ($identifier === '') {
            return BilledEffectResult::noAnswer('music actor effect carried no identifier');
        }

        // Item 8 (2026-09-01, G3: both music platforms = SC primary): the
        // ScrapeCreators lane fronts the actor — ~2-4s REST against a 15-60s
        // run-sync hold. ANY vendor outcome other than usable rows — no key,
        // budget denied, transport/HTTP failure, NotFound husk, shape drift,
        // empty list — falls through to the Apify path completely unchanged.
        // Deliberately BEFORE the Apify config/token check: a vendor answer
        // needs no actor. Empty-catalogue semantics stay Apify's alone —
        // the vendor never produces Answered([]), so "this artist has no
        // tracks" remains a claim only the incumbent lane can settle.
        $vendor = $this->vendorRows($platform, $identifier, $ctx->userId);
        if ($vendor !== null) {
            return BilledEffectResult::answered($vendor);
        }

        $config = config("partna.music.platforms.{$platform}");
        $token = config('services.apify.token');

        if (! is_array($config) || ! is_string($config['actor'] ?? null) || $config['actor'] === '' || ! $token) {
            throw new EffectNotAttempted(
                "no Apify actor or token configured for music platform '{$platform}'"
            );
        }

        /** @var MusicActorAdapter $adapter */
        $adapter = app($config['adapter']);

        if (! $this->budget->tryClaim("music-{$platform}")) {
            throw new EffectNotAttempted("Apify daily cap reached for actor 'music-{$platform}'");
        }

        // Bearer, NOT a `token` key in the body. Apify accepts the token as a
        // header or a QUERY param; putting it in the JSON body makes it part of
        // the ACTOR INPUT and leaves the call unauthenticated, which a
        // pay-per-event actor rejects with an x402 payment error rather than a
        // 401 — so the symptom reads as a billing failure and sends you to the
        // wrong place entirely. InstagramScraper has always used withToken();
        // this matches it.
        $response = Http::withToken($token)
            ->timeout((int) config('partna.limits.apify.run_sync_timeout_seconds'))
            ->post(
                'https://api.apify.com/v2/acts/'.$config['actor'].'/run-sync-get-dataset-items',
                $adapter->input($identifier, (int) ($config['max_tracks'] ?? 50)),
            );

        if (! $response->successful()) {
            return BilledEffectResult::noAnswer(
                "music actor '{$platform}' returned {$response->status()}"
            );
        }

        $dataset = $response->json();

        return BilledEffectResult::answered(
            $adapter->tracks(is_array($dataset) ? $dataset : [])
        );
    }

    /**
     * The vendor lane, contract-lossy by design: normalized rows or null,
     * never a failure classification. One /v1/spotify/artist call carries
     * BOTH Spotify streams (discography lists for releases, topTracks for
     * tracks), so both platforms spend the one 'spotify' budget source.
     * Budget is claimed before the call and released on transport-null; a
     * billed husk keeps its slot spent (NotFound bills a credit upstream).
     *
     * @return non-empty-list<array<string, mixed>>|null
     */
    private function vendorRows(string $platform, string $identifier, ?string $userId): ?array
    {
        $client = app(ScrapeCreatorsClient::class);
        if (! $client->enabled()) {
            return null;
        }

        if ($platform === 'soundcloud') {
            // The connection's own stored URL, exactly — handles are
            // exact-match with no fuzzy resolution (a squatter answers
            // "successfully"), so the handle is READ from the identifier the
            // existing lane already trusts, never guessed.
            if (! preg_match('~soundcloud\.com/([A-Za-z0-9_.-]+)~i', $identifier, $m)) {
                return null;
            }
            $source = 'soundcloud';
            $path = '/v1/soundcloud/artist/tracks';
            $query = ['handle' => $m[1]];
        } elseif ($platform === 'spotify' || $platform === 'spotify_releases') {
            if (! preg_match('~open\.spotify\.com/(?:intl-[a-z]{2}/)?artist/([A-Za-z0-9]+)~', $identifier, $m)) {
                return null;
            }
            $source = 'spotify';
            $path = '/v1/spotify/artist';
            $query = ['id' => $m[1]];
        } else {
            return null;
        }

        $budget = app(ScrapeCreatorsBudget::class);
        if (! $budget->tryClaim($source)) {
            return null;
        }

        $body = $client->get($path, $query, $userId);
        if ($body === null) {
            $budget->release($source);

            return null;
        }

        $rows = match ($platform) {
            'spotify' => app(SpotifyArtistNormalizer::class)->tracks($body),
            'spotify_releases' => app(SpotifyArtistNormalizer::class)->releases($body),
            default => app(SoundcloudTracksNormalizer::class)->tracks($body),
        };
        if ($rows === null) {
            // Billed upstream even as a husk — the slot stays spent; only
            // transport-level nulls release (same rule as the Instagram lane).
            Log::info('scrapecreators.music.unusable_shape', [
                'platform' => $platform,
                'user_id' => $userId,
            ]);

            return null;
        }

        return $rows;
    }
}
