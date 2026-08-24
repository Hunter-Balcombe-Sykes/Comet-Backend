<?php

// SCALE-20: PlacementPolicy::isTombstoned() issued one EXISTS query per link
// during a batch import — an N-link import ran N queries against
// routing.item_tombstones. The importers cannot pre-compute the projected
// (surfaceKey, identifier) pairs (projection happens per-link inside
// LinkRoutingService::route(), after canonicalisation), so the fix is an
// import-run-scoped memo inside PlacementPolicy itself: one prefetch query
// per RoutingContext::importRunId, answered from memory thereafter. A paste
// (importRunId === null) keeps the original per-call EXISTS query.

use App\Models\Core\User\User;
use App\Routing\Importers\WebsiteImporter;
use App\Routing\RoutingContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

/** Matches TombstoneResurrectionTest's backfilledTombstone() shape. */
function itpTombstone(User $user, string $surfaceKey, string $resourceId): void
{
    DB::table('routing.item_tombstones')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'source_ref' => $surfaceKey.':'.$resourceId,
        'scope' => 'this_source',
        'reason' => 'test refusal',
        'created_at' => now()->subDays(7),
    ]);
}

/**
 * @return int number of queries whose SQL touches routing.item_tombstones
 */
function itpCountTombstoneQueries(callable $work): int
{
    $count = 0;
    DB::listen(function ($query) use (&$count) {
        if (str_contains($query->sql, 'item_tombstones')) {
            $count++;
        }
    });

    $work();

    return $count;
}

/** 20 distinct instagram.profile links: some refused, most not. */
function itpTwentyInstagramLinks(array $refusedHandles): string
{
    $anchors = '';
    for ($i = 1; $i <= 20; $i++) {
        $handle = in_array($i, [3, 17], true)
            ? $refusedHandles[array_search($i, [3, 17], true)]
            : 'creator'.$i;

        $anchors .= '<a href="https://www.instagram.com/'.$handle.'">Link '.$i.'</a>';
    }

    return '<html><body>'.$anchors.'</body></html>';
}

it('issues one tombstone query per import run, not one per link (SCALE-20)', function () {
    // example.com resolves for real under SafeUrlFetcher's SSRF check even
    // through Http::fake() — same reasoning as WebsiteImporterTest.
    $pro = createTenant('scale20-prefetch');
    itpTombstone($pro, 'instagram.profile', 'refused_a');
    itpTombstone($pro, 'instagram.profile', 'refused_b');

    Http::fake(['*' => Http::response(itpTwentyInstagramLinks(['refused_a', 'refused_b']), 200, ['Content-Type' => 'text/html'])]);

    $result = null;
    $queries = itpCountTombstoneQueries(function () use (&$result, $pro) {
        $result = app(WebsiteImporter::class)->import($pro, 'https://example.com/');
    });

    expect($result['outcome'])->toBe('ok')
        ->and($result['observations'])->toBe(20)
        ->and($queries)->toBe(1);

    // Both refused links are still rejected — the memo must not miss a
    // tombstone. block_reason on the observation is the C8 tell.
    $refusedRefs = DB::table('routing.link_observations')
        ->where('user_id', $pro->id)
        ->where('canonical_url', 'like', '%refused_a%')
        ->orWhere(fn ($q) => $q->where('user_id', $pro->id)->where('canonical_url', 'like', '%refused_b%'))
        ->pluck('block_reason');

    expect($refusedRefs->all())->toBe(['tombstoned', 'tombstoned']);

    // 18 matched links go through gates/confidence as normal; the 2 refused
    // ones are rejected and counted 'noted' (WebsiteImporter has no separate
    // reject bucket). Under the 2026-08-18 ruling (harvest origins auto-apply
    // the suggest band) the first link PLACES until the instagram.profile
    // account cap holds the rest — and FI-1 (2026-08-20) made socials
    // single-account, so exactly ONE connects and 17 cap-hold as suggestions
    // (this split was 5/13 while instagram was multiAccount(5)).
    expect($result['suggested'])->toBe(17)
        ->and($result['noted'])->toBe(2)
        ->and($result['connected'])->toBe(1);

    // No intent is written for either refused link — a Hold would put the
    // platform back in front of the user as a suggestion, which is the
    // slower resurrection TombstoneResurrectionTest guards against.
    expect(DB::table('routing.source_intents')->where('user_id', $pro->id)->count())->toBe(18);
});

it('still runs its own query per call on the paste path, and lets a direct re-add through', function () {
    // Belt-and-braces over TombstoneResurrectionTest: the memo branch must
    // never engage for a paste (importRunId === null), and a direct re-add
    // must still beat the tombstone exactly as before this fix.
    $pro = createTenant('scale20-paste');
    itpTombstone($pro, 'instagram.profile', 'theartist');

    $context = RoutingContext::forUser($pro, 'paste');
    expect($context->importRunId)->toBeNull();

    $queries = itpCountTombstoneQueries(function () use ($pro) {
        actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://www.instagram.com/theartist'])
            ->assertStatus(202)
            ->assertJsonPath('verdict', 'place');
    });

    expect($queries)->toBeGreaterThanOrEqual(1);

    expect(DB::table('routing.item_tombstones')
        ->where('user_id', $pro->id)
        ->where('source_ref', 'instagram.profile:theartist')
        ->exists())->toBeFalse();
});
