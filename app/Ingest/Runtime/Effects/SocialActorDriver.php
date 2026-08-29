<?php

namespace App\Ingest\Runtime\Effects;

use App\Exceptions\Platforms\VendorAccountFaultException;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Cache\ApifyBudget;
use App\Support\ThrottledReport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ('actor', 'tiktok') and ('actor', 'facebook') — the paid Apify social-feed
 * scrapes (T27c, 2026-08-28). One driver for both because the two calls are
 * shape-identical (post an input, read a dataset of feed items) and differ
 * only in the actor id, the input envelope and the field that proves a row is
 * a real feed item — a per-name spec below, not an adapter layer: the vendor
 * dataset mapping lives in the connectors (the MenuActorDriver precedent),
 * so an adapter here would be a third copy of nothing.
 *
 * ORDERING IS LOAD-BEARING, as in every other actor driver: every check that
 * can refuse a run happens BEFORE the budget claim, so a missing token or
 * actor id cannot burn a daily slot. Refusals before the claim throw
 * EffectNotAttempted (ledger claim deleted, digest free to retry); after the
 * claim only answered/noAnswer may leave — rule 1 of BilledEffectDriver.
 *
 * Single attempt, like Instagram and unlike the menu actors: profile-feed
 * scrapes answer reliably (both actors run their own retry pools), and a
 * miss costs nothing but a later re-run.
 *
 * Empty dataset ⇒ noAnswer, never answered([]). For these actors an empty
 * dataset is indistinguishable from a bot-wall miss (the menu lane's def.uber
 * lesson), and settling it ok would serve "this account posts nothing" for
 * the whole freshness window.
 */
final class SocialActorDriver implements BilledEffectDriver
{
    /**
     * Same set as MenuActorDriver::ACCOUNT_FAULT_STATUSES and for the same
     * reason: an unrented actor / revoked token / x402 fault is a verdict on
     * OUR account, not on this profile.
     */
    private const ACCOUNT_FAULT_STATUSES = [401, 402, 403, 404];

    /**
     * name => how to call it. `config` roots at partna.social_actors.{name};
     * `proof` is the dataset-row key whose presence marks a real feed item —
     * both actors answer error conditions with rows shaped differently
     * (error objects, notice rows), and a dataset with no proven row is a
     * miss, not an empty account.
     */
    private const SPECS = [
        'tiktok' => ['proof' => 'id'],
        'facebook' => ['proof' => 'postId'],
    ];

    public function __construct(private readonly ApifyBudget $budget) {}

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'actor' && isset(self::SPECS[$name]);
    }

    public function run(BilledEffectContext $ctx): BilledEffectResult
    {
        $name = $ctx->name;
        $token = config('services.apify.token');
        $actor = trim((string) config("partna.social_actors.{$name}.actor"));
        $input = $this->actorInput($name, $ctx);

        // ── Everything that can refuse, before the claim ─────────────────────
        if (! is_string($token) || $token === '') {
            throw new EffectNotAttempted("no Apify token configured for the {$name} actor");
        }

        if ($actor === '') {
            throw new EffectNotAttempted("no actor id configured at partna.social_actors.{$name}.actor");
        }

        if ($input === null) {
            return BilledEffectResult::noAnswer("{$name} actor effect carried no identifier");
        }

        if (! $this->budget->tryClaim($name)) {
            throw new EffectNotAttempted("Apify daily cap reached for actor '{$name}'");
        }

        try {
            // Bearer header, never a body key — a token in the body becomes
            // actor INPUT and pay-per-event actors answer x402, not 401 (F30).
            $response = Http::withToken($token)
                ->timeout((int) config('partna.limits.apify.run_sync_timeout_seconds'))
                ->post('https://api.apify.com/v2/acts/'.$actor.'/run-sync-get-dataset-items', $input);
        } catch (Throwable $e) {
            report($e);
            $this->log('social.actor.threw', $name, $ctx, ['error' => $e->getMessage()]);

            return BilledEffectResult::noAnswer("{$name} actor threw: {$e->getMessage()}");
        }

        // run-sync-get-dataset-items answers 201 — successful(), not ok().
        if (! $response->successful()) {
            $status = $response->status();
            $this->log('social.actor.not_ok', $name, $ctx, ['status' => $status]);

            if ($status >= 500) {
                report(new \RuntimeException("Apify {$name} actor '{$actor}' failed with status {$status}"));
            }

            if (in_array($status, self::ACCOUNT_FAULT_STATUSES, true)) {
                // B3 (#W2-OBS-5): a plain report() here would fire once per profile
                // per digest run — an account-wide fault (revoked token, unrented
                // actor, x402) fires N times per run. Throttled per actor+status.
                ThrottledReport::once(
                    "ingest:apify:account_fault:{$name}:{$status}",
                    new VendorAccountFaultException('apify', "actor:{$name}", $status),
                );

                return BilledEffectResult::noAnswer(
                    "{$name} actor returned {$status} — account or actor fault, not a verdict on the profile"
                );
            }

            return BilledEffectResult::noAnswer("{$name} actor returned {$status}");
        }

        $dataset = $response->json();
        $proof = self::SPECS[$name]['proof'];
        $rows = is_array($dataset)
            ? array_values(array_filter($dataset, fn ($row) => is_array($row) && ($row[$proof] ?? null) !== null))
            : [];

        if ($rows === []) {
            $first = is_array($dataset) ? ($dataset[0] ?? null) : null;
            $this->log('social.actor.no_payload', $name, $ctx, [
                'rows' => is_array($dataset) ? count($dataset) : 0,
                // The actor's own diagnosis when it offers one, bounded.
                'first_keys' => is_array($first) ? array_slice(array_keys($first), 0, 12) : gettype($first),
            ]);

            return BilledEffectResult::noAnswer("{$name} actor returned no {$proof}-bearing rows");
        }

        $this->log('social.actor.ok', $name, $ctx, ['rows' => count($rows)]);

        return BilledEffectResult::answered($rows);
    }

    /**
     * The actor input envelope, or null when the effect carries no usable
     * identifier. resultsLimit bounds SPEND (both actors bill per result).
     *
     * @return array<string, mixed>|null
     */
    private function actorInput(string $name, BilledEffectContext $ctx): ?array
    {
        $limit = max(1, (int) config("partna.social_actors.{$name}.results_limit", 30));

        if ($name === 'tiktok') {
            $username = strtolower(ltrim(trim((string) ($ctx->input['username'] ?? '')), '@'));

            return $username === '' ? null : ['profiles' => [$username], 'resultsPerPage' => $limit];
        }

        $pageUrl = trim((string) ($ctx->input['page_url'] ?? ''));

        return preg_match('~^https://(www\.)?facebook\.com/~i', $pageUrl) !== 1
            ? null
            : ['startUrls' => [['url' => $pageUrl]], 'resultsLimit' => $limit];
    }

    /** @param array<string, mixed> $extra */
    private function log(string $event, string $name, BilledEffectContext $ctx, array $extra): void
    {
        // info level: cloud env:logs surfaces info, and a failed scrape must
        // be diagnosable from the stream.
        Log::info($event, $extra + [
            'actor_name' => $name,
            'source_id' => $ctx->sourceId,
            'run_id' => $ctx->runId,
            'user_id' => $ctx->userId,
        ]);
    }
}
