<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Cache\ApifyBudget;
use App\Services\Platforms\Actors\MusicActorAdapter;
use Illuminate\Support\Facades\Http;

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
}
