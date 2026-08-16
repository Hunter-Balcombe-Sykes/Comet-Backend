<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Cache\ApifyBudget;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ('actor', 'menu') — the paid Apify store-menu scrape behind the Uber Eats,
 * DoorDash and Square connectors. Written for convergence Phase 5; before it
 * existed all three died in HttpIo::runBilledEffect() with "No billed-effect
 * driver is wired", which is the wall slice 4's menu proof hit (spec §23.6).
 *
 * WHY THERE ARE NO ADAPTERS, unlike MusicActorDriver. Music's connectors send
 * ['platform', 'identifier'] and know nothing of the vendor, so a
 * MusicActorAdapter has to own the input shape and the dataset field names.
 * The menu connectors send ['actor', 'input'] — each one already resolves its
 * own actor from config('partna.menu.platforms') and already maps the dataset
 * back (the mapping was ported into them from the legacy *MenuDriver classes,
 * which are still live on the MenuFetchJob/MenuApifyScraper lane and are NOT
 * free to move). The prescribed split still holds — the driver owns budget,
 * token and transport, the vendor's shape lives elsewhere — it is just that
 * "elsewhere" is the connector here, and adding adapters would make a THIRD
 * copy of a mapping that already exists twice.
 *
 * ORDERING IS LOAD-BEARING, as in InstagramActorDriver and MusicActorDriver:
 * every check that can refuse a run happens BEFORE the first budget claim, so
 * a missing token or an unconfigured platform cannot burn a daily slot doing
 * nothing. Those refusals throw EffectNotAttempted, which deletes the ledger
 * claim rather than locking the digest for the whole freshness window.
 *
 * FOUR THINGS MENUS NEED THAT MUSIC DID NOT, carried across from the legacy
 * lane's MenuApifyScraper::attemptScrape():
 *
 *   1. Retries. These actors scrape WAF-protected pages and return an EMPTY
 *      dataset on a large fraction of runs for a perfectly valid, open store.
 *      A one-shot driver looks broken at random.
 *   2. run-sync-get-dataset-items answers 201, so success is successful(), not
 *      ok() — ok() accepts 200 alone and would reject every good run.
 *   3. A 4xx is hard (do not retry); a 5xx is Apify infra and is retryable.
 *   4. The budget is claimed per REAL actor run, inside the loop — not once
 *      per driver call — because each attempt is a separately billed run.
 *
 * The cap key is the bare string 'menu', which must match
 * config('partna.limits.apify.actors.menu') EXACTLY: ApifyBudget defaults a
 * missing cap to 0, which denies every claim silently and reads as a dead
 * actor rather than as a configuration error.
 */
final class MenuActorDriver implements BilledEffectDriver
{
    /**
     * The budget tag. Deliberately platform-agnostic: one shared daily menu
     * ceiling across all three platforms, which is how the cap has always been
     * configured (PARTNA_MENU_APIFY_DAILY_CAP).
     */
    private const BUDGET_TAG = 'menu';

    /**
     * A 4xx that means OUR account or actor is wrong, not that this store is
     * absent — unrented actor, revoked token, x402 payment fault, actor id
     * typo. Settling these as answered([]) would cache a configuration break
     * as "this store has no menu" for the whole freshness window, and would do
     * it for every store at once. They get NoAnswer instead: the row stays
     * settled, but nothing is ever served from it as truth.
     */
    private const ACCOUNT_FAULT_STATUSES = [401, 402, 403, 404];

    public function __construct(private readonly ApifyBudget $budget) {}

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'actor' && $name === 'menu';
    }

    public function run(BilledEffectContext $ctx): BilledEffectResult
    {
        $actor = trim((string) ($ctx->input['actor'] ?? ''));
        $input = $ctx->input['input'] ?? null;
        $token = config('services.apify.token');

        // ── Everything that can refuse, before the first claim ──────────────
        if (! is_string($token) || $token === '') {
            throw new EffectNotAttempted('no Apify token configured for the menu actor');
        }

        if (! is_array($input) || $input === []) {
            throw new EffectNotAttempted("menu actor '{$actor}' was handed no actor input");
        }

        // The actor id arrives in the effect payload rather than being read
        // here, because each connector resolves its own. Checking it against
        // the configured set is what keeps that from being a blank cheque: it
        // pins the spend to actors someone registered, and it keeps this class
        // in SSRF category A (the URL is a constant host plus a config-derived
        // path segment) rather than category D.
        if (! in_array($actor, $this->configuredActors(), true)) {
            throw new EffectNotAttempted(
                "menu actor '{$actor}' is not registered in config('partna.menu.platforms')"
            );
        }

        $attempts = max(1, (int) config('partna.menu.actor_attempts'));
        $timeout = (int) config('partna.menu.actor_attempt_timeout_seconds');
        $lastReason = "menu actor '{$actor}' returned nothing on {$attempts} attempts";

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if (! $this->budget->tryClaim(self::BUDGET_TAG)) {
                // On the FIRST attempt nothing has left the process, so the
                // claim can be released and the digest left free to retry. On
                // a later one a billed run has already gone out, and rule 1 of
                // BilledEffectDriver forbids EffectNotAttempted after that —
                // raising it here would let the same run be billed twice.
                if ($attempt === 1) {
                    throw new EffectNotAttempted("Apify daily cap reached for actor '".self::BUDGET_TAG."'");
                }

                return BilledEffectResult::noAnswer(
                    "Apify daily cap reached for actor '".self::BUDGET_TAG."' after {$attempt} attempts"
                );
            }

            $outcome = $this->attempt($actor, $token, $input, $timeout, $attempt, $ctx);

            if ($outcome instanceof BilledEffectResult) {
                return $outcome;
            }

            $lastReason = $outcome;
        }

        // Every attempt was retryable and none answered. NOT answered([]): an
        // empty dataset from a WAF-blocked scrape is indistinguishable from a
        // genuinely empty store, and settling it ok would serve "this
        // restaurant has no menu" for the rest of the freshness window.
        return BilledEffectResult::noAnswer($lastReason);
    }

    /**
     * One billed actor run. Returns a BilledEffectResult when the attempt is
     * final, or a string reason when it is retryable.
     *
     * @param  array<string, mixed>  $input
     */
    private function attempt(
        string $actor,
        string $token,
        array $input,
        int $timeout,
        int $attempt,
        BilledEffectContext $ctx,
    ): BilledEffectResult|string {
        try {
            // Bearer, NOT a `token` key in the body. Apify accepts the token as
            // a header or a query param; putting it in the JSON body makes it
            // part of the ACTOR INPUT and leaves the call unauthenticated,
            // which a pay-per-event actor rejects with an x402 payment error
            // rather than a 401 — so the symptom reads as a billing failure and
            // sends you to the wrong place entirely (F30).
            $response = Http::withToken($token)
                ->timeout($timeout)
                ->post('https://api.apify.com/v2/acts/'.$actor.'/run-sync-get-dataset-items', $input);
        } catch (Throwable $e) {
            report($e);
            $this->log('menu.actor.threw', $ctx, $attempt, ['error' => $e->getMessage()]);

            return "menu actor '{$actor}' threw on attempt {$attempt}: {$e->getMessage()}";
        }

        // run-sync-get-dataset-items answers 201, not 200 — ->ok() would reject
        // every successful run.
        if (! $response->successful()) {
            $status = $response->status();
            $this->log('menu.actor.not_ok', $ctx, $attempt, ['status' => $status]);

            if ($status >= 500) {
                // Genuine Apify infra, worth alerting on, and worth retrying.
                report(new \RuntimeException("Apify menu actor '{$actor}' failed with status {$status}"));

                return "menu actor '{$actor}' returned {$status} on attempt {$attempt}";
            }

            if (in_array($status, self::ACCOUNT_FAULT_STATUSES, true)) {
                return BilledEffectResult::noAnswer(
                    "menu actor '{$actor}' returned {$status} — account or actor fault, not a verdict on the store"
                );
            }

            // Any other 4xx is the vendor positively rejecting THIS store or
            // input (unknown store, malformed url). Settling it ok is what
            // stops a dead store being re-billed on every run.
            return BilledEffectResult::answered([]);
        }

        $dataset = $response->json();

        if (! is_array($dataset) || $dataset === []) {
            // The WAF case (see the class docblock). Retryable, never an answer.
            $this->log('menu.actor.empty', $ctx, $attempt, []);

            return "menu actor '{$actor}' returned an empty dataset on attempt {$attempt}";
        }

        $this->log('menu.actor.ok', $ctx, $attempt, [
            'rows' => count($dataset),
            // The first row's keys, so a mapping can be tuned against real data
            // without dumping a large dataset into the log stream.
            'first_keys' => is_array($dataset[0] ?? null) ? array_keys($dataset[0]) : gettype($dataset[0] ?? null),
        ]);

        return BilledEffectResult::answered($dataset);
    }

    /**
     * Every registered platform's actor id.
     *
     * @return list<string>
     */
    private function configuredActors(): array
    {
        $actors = [];

        foreach ((array) config('partna.menu.platforms', []) as $platform) {
            $actor = is_array($platform) ? ($platform['actor'] ?? null) : null;
            if (is_string($actor) && $actor !== '') {
                $actors[] = $actor;
            }
        }

        return $actors;
    }

    /** @param array<string, mixed> $extra */
    private function log(string $event, BilledEffectContext $ctx, int $attempt, array $extra): void
    {
        // info level: the Laravel Cloud log stream (cloud env:logs) only
        // surfaces info, so a failed scrape must log here to be diagnosable.
        Log::info($event, $extra + [
            'source_id' => $ctx->sourceId,
            'run_id' => $ctx->runId,
            'user_id' => $ctx->userId,
            'attempt' => $attempt,
        ]);
    }
}
