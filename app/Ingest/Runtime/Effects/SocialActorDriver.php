<?php

namespace App\Ingest\Runtime\Effects;

use App\Exceptions\Platforms\VendorAccountFaultException;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Cache\ApifyBudget;
use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\FacebookPostsNormalizer;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use App\Services\Platforms\ScrapeCreators\TiktokVideosNormalizer;
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
 *
 * Item 8 G3 (2026-09-01): the ScrapeCreators lane fronts both actors, the
 * InstagramScraper pattern verbatim — vendor rows mapped into the EXACT actor
 * dataset shape the connectors read, so downstream cannot tell the lanes
 * apart; ANY vendor outcome short of usable rows (no key, budget denied,
 * transport/HTTP failure, success-shaped husk) falls through to the untouched
 * Apify path below. Vendor rules: claim ScrapeCreatorsBudget per page before
 * the call, release on a transport-level null, keep the slot spent on billed
 * husks; empty vendor rows fall through, never answered([]).
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
        'tiktok' => [
            'proof' => 'id',
            // /v1 profile itemList is UNRELIABLE for content (G3) — the
            // videos endpoint is the only vendor content source. ~10/page.
            'vendor_path' => '/v3/tiktok/profile/videos',
            // 1 page (2026-09-04): the mirror budget keeps 10 posts per pull
            // (partna.media.pull_budget), so pages 2–3 were billed for frames
            // nobody would ever see.
            'vendor_pages' => 1,
            'order' => 'createTimeISO',
        ],
        'facebook' => [
            'proof' => 'postId',
            // 3 posts/call upstream — the page cap bounds spend per run.
            'vendor_path' => '/v1/facebook/profile/posts',
            'vendor_pages' => 4,
            'order' => 'time',
        ],
    ];

    /**
     * A definite vendor verdict on the ACCOUNT, not on the request (O.2,
     * 2026-09-02): ScrapeCreators answers a deactivated TikTok handle with a
     * 200 `{account_deactivated: true, message: "Account doesn't exist"}`
     * while tiktok.com serves a placeholder page. When the vendor lane sees
     * it, the actor lane is not consulted — an Apify run cannot un-delete an
     * account — and the effect answers noAnswer with THIS reason so the
     * connection can be retired rather than left pending.
     */
    public const REASON_ACCOUNT_DEACTIVATED = 'account_deactivated';

    public function __construct(private readonly ApifyBudget $budget) {}

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'actor' && isset(self::SPECS[$name]);
    }

    public function run(BilledEffectContext $ctx): BilledEffectResult
    {
        $name = $ctx->name;

        // ── Vendor lane first (Item 8): before even the token checks, like
        // InstagramScraper — a usable vendor answer needs no Apify config.
        $vendorRows = $this->vendorRows($ctx);
        if ($vendorRows === self::REASON_ACCOUNT_DEACTIVATED) {
            $this->log('social.vendor.account_deactivated', $name, $ctx, []);

            return BilledEffectResult::noAnswer(self::REASON_ACCOUNT_DEACTIVATED);
        }
        if ($vendorRows !== null) {
            $this->log('social.vendor.ok', $name, $ctx, ['rows' => count($vendorRows)]);

            return BilledEffectResult::answered($vendorRows);
        }

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
     * The vendor lane, contract-lossy by design: actor-shaped rows or null,
     * never a failure classification. Pages are claimed one budget slot each;
     * a transport-null releases its slot, a billed husk keeps it spent. A
     * mid-pagination failure keeps the pages already landed — fewer rows than
     * the actor's window is the economics G3 signed off on, and paid content
     * is not discarded to re-buy it from Apify.
     *
     * @return list<array<string, mixed>>|null
     */
    /** @return array<int, array<string, mixed>>|string|null rows, the deactivation verdict, or nothing */
    private function vendorRows(BilledEffectContext $ctx): array|string|null
    {
        $name = $ctx->name;
        $spec = self::SPECS[$name];

        $client = app(ScrapeCreatorsClient::class);
        if (! $client->enabled()) {
            return null;
        }

        $identifier = $this->vendorIdentifier($name, $ctx);
        if ($identifier === null) {
            return null;
        }

        $budget = app(ScrapeCreatorsBudget::class);
        $limit = max(1, (int) config("partna.social_actors.{$name}.results_limit", 30));

        /** @var array<string, array<string, mixed>> $rows keyed by proof id — pagination dedupe */
        $rows = [];
        $cursor = null;

        for ($page = 0; $page < $spec['vendor_pages']; $page++) {
            if (! $budget->tryClaim($name)) {
                break;
            }

            $query = $name === 'tiktok' ? ['handle' => $identifier] : ['url' => $identifier];
            if ($cursor !== null) {
                $query[$name === 'tiktok' ? 'max_cursor' : 'cursor'] = $cursor;
            }

            $body = $client->get($spec['vendor_path'], $query, $ctx->userId);
            if ($body === null) {
                // Transport-level nothing: hand the slot back.
                $budget->release($name);
                break;
            }

            // From here the call was billed upstream — the slot stays spent.
            if (($body['account_deactivated'] ?? false) === true) {
                return self::REASON_ACCOUNT_DEACTIVATED;
            }
            $pageRows = $name === 'tiktok'
                ? app(TiktokVideosNormalizer::class)->rows($body, $identifier)
                : app(FacebookPostsNormalizer::class)->rows($body);

            if ($pageRows === null) {
                $this->log('social.vendor.unusable_shape', $name, $ctx, ['page' => $page]);
                break;
            }

            $before = count($rows);
            foreach ($pageRows as $row) {
                $rows[(string) $row[$spec['proof']]] ??= $row;
            }

            $cursor = $this->vendorNextCursor($name, $body);
            // Stop on: no next page, enough rows, or a page that added
            // nothing — a cursor loop must not burn the page cap on dupes.
            if ($cursor === null || count($rows) >= $limit || count($rows) === $before) {
                break;
            }
        }

        if ($rows === []) {
            return null;
        }

        // Re-sort desc after pagination: pinned TikTok videos (is_top=1) lead
        // the vendor feed out of order, exactly like the actor's grid.
        $order = $spec['order'];
        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b) => strcmp((string) ($b[$order] ?? ''), (string) ($a[$order] ?? '')));

        return array_slice($rows, 0, $limit);
    }

    /** Same extraction + validation as actorInput — the lanes must agree on what an identifier is. */
    private function vendorIdentifier(string $name, BilledEffectContext $ctx): ?string
    {
        if ($name === 'tiktok') {
            $username = strtolower(ltrim(trim((string) ($ctx->input['username'] ?? '')), '@'));

            return $username === '' ? null : $username;
        }

        $pageUrl = trim((string) ($ctx->input['page_url'] ?? ''));

        return preg_match('~^https://(www\.)?facebook\.com/~i', $pageUrl) === 1 ? $pageUrl : null;
    }

    /** @param array<string, mixed> $body */
    private function vendorNextCursor(string $name, array $body): int|string|null
    {
        if ($name === 'tiktok') {
            return ! empty($body['has_more']) && is_numeric($body['max_cursor'] ?? null)
                ? (int) $body['max_cursor']
                : null;
        }

        $cursor = $body['cursor'] ?? null;

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
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
