<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\BlueskyPostsNormalizer;
use App\Services\Platforms\ScrapeCreators\BlueskyProfileNormalizer;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use Illuminate\Support\Facades\Log;

/**
 * ('vendor', 'bluesky') — profile + own authored posts into media-pool
 * candidate rows (Item 10b, 2026-09-01). The pinterest pattern: ScrapeCreators
 * is the ONLY lane (no Apify actor behind it), so a vendor miss is this
 * driver's own noAnswer, never someone else's fall-through.
 *
 * ORDERING IS LOAD-BEARING, as in every billed driver: every check that can
 * refuse a run happens BEFORE the first budget claim. Refusals before a claim
 * throw EffectNotAttempted (ledger claim deleted, digest free to retry); after
 * the first claim only answered/noAnswer may leave — including the mid-run cap
 * check before the posts call, which folds to noAnswer, never a throw.
 *
 * Two endpoints per run, each call one budget slot: the profile first
 * (identity + did), then the posts feed keyed by the ANSWERED did. Budget
 * rules are the Item 8 contract verbatim: claim before the call, release on
 * transport-null, keep the slot spent on billed husks (bluesky's NotFound
 * husk happens to bill ZERO credits — recorded 2026-09-01 — but the slot
 * stays spent anyway: shape gating must not depend on a vendor billing
 * quirk staying generous).
 *
 * EXACT-ACCOUNT VALIDATION IS LOAD-BEARING. The profile endpoint answers
 * success for ANY existing handle — a squatter sitting on the handle a
 * prospect meant, or a loosely-resolved lookalike, answers exactly like the
 * real account — so the answered profile is checked against what was ASKED
 * (did-to-did for a did identifier, handle-to-handle otherwise) and a
 * mismatch is a billed noAnswer, never landed rows. The posts call then runs
 * on the VALIDATED did, which also makes the normalizer's own-author filter
 * (the vendor strips repost markers) exact rather than handle-string fuzzy.
 *
 * Empty/reply-only/foreign-only feeds ⇒ noAnswer, never answered([]) — the
 * lane may never be the reason an account reads as empty for the whole
 * effect-freshness window.
 */
final class BlueskyVendorDriver implements BilledEffectDriver
{
    private const SOURCE = 'bluesky';

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'vendor' && $name === self::SOURCE;
    }

    public function run(BilledEffectContext $ctx): BilledEffectResult
    {
        $account = strtolower(ltrim(trim((string) ($ctx->input['handle'] ?? '')), '@'));
        if ($account === '') {
            return BilledEffectResult::noAnswer('bluesky vendor effect carried no identifier');
        }

        $client = app(ScrapeCreatorsClient::class);
        if (! $client->enabled()) {
            // No fallback lane exists for bluesky — a missing key refuses the
            // run outright instead of failing it, so the digest may retry
            // once the key lands.
            throw new EffectNotAttempted('no ScrapeCreators key configured for the bluesky vendor');
        }

        $budget = app(ScrapeCreatorsBudget::class);
        if (! $budget->tryClaim(self::SOURCE)) {
            throw new EffectNotAttempted("ScrapeCreators daily cap reached for source '".self::SOURCE."'");
        }

        $body = $client->get('/v1/bluesky/profile', ['handle' => $account], $ctx->userId);
        if ($body === null) {
            $budget->release(self::SOURCE);

            return BilledEffectResult::noAnswer('bluesky profile call did not answer');
        }

        // From here the profile call reached the vendor — the slot stays spent.
        $profile = app(BlueskyProfileNormalizer::class)->normalize($body);
        if ($profile === null) {
            $this->log('bluesky.vendor.unusable_shape', $ctx, ['endpoint' => 'profile']);

            return BilledEffectResult::noAnswer('bluesky profile answer carried no usable account');
        }

        if (! $this->isRequestedAccount($profile, $account)) {
            $this->log('bluesky.vendor.account_mismatch', $ctx, [
                'asked' => $account,
                'answered' => $profile['handle'],
            ]);

            return BilledEffectResult::noAnswer(
                "bluesky profile answered '{$profile['handle']}' for '{$account}' — refusing the mismatch"
            );
        }

        if (! $budget->tryClaim(self::SOURCE)) {
            // Mid-run exhaustion after a billed call may NOT throw
            // EffectNotAttempted (rule 1: only before the first vendor call) —
            // fold it like any other unanswered posts fetch.
            return BilledEffectResult::noAnswer("ScrapeCreators daily cap reached for source '".self::SOURCE."' before the posts call");
        }

        $body = $client->get('/v1/bluesky/user/posts', ['user_id' => $profile['did']], $ctx->userId);
        if ($body === null) {
            $budget->release(self::SOURCE);

            return BilledEffectResult::noAnswer('bluesky posts call did not answer');
        }

        $rows = app(BlueskyPostsNormalizer::class)->rows($body, (string) $profile['did']);
        if ($rows === null) {
            $this->log('bluesky.vendor.unusable_shape', $ctx, ['endpoint' => 'user/posts']);

            return BilledEffectResult::noAnswer('bluesky feed carried no own authored post');
        }

        $this->log('bluesky.vendor.ok', $ctx, ['rows' => count($rows)]);

        return BilledEffectResult::answered($rows);
    }

    /**
     * The answered profile is the account that was asked for — did-to-did
     * when the identifier is a did, handle-to-handle otherwise (handles are
     * domains, so the comparison is case-insensitive by that grammar).
     *
     * @param  array<string, mixed>  $profile
     */
    private function isRequestedAccount(array $profile, string $account): bool
    {
        if (str_starts_with($account, 'did:')) {
            return strcasecmp((string) $profile['did'], $account) === 0;
        }

        return strcasecmp((string) $profile['handle'], $account) === 0;
    }

    /** @param array<string, mixed> $extra */
    private function log(string $event, BilledEffectContext $ctx, array $extra): void
    {
        // info level: cloud env:logs surfaces info, and a failed scrape must
        // be diagnosable from the stream.
        Log::info($event, $extra + [
            'source_id' => $ctx->sourceId,
            'run_id' => $ctx->runId,
            'user_id' => $ctx->userId,
        ]);
    }
}
