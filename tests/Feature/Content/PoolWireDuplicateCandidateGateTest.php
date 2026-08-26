<?php

use App\Models\Core\Site\Site;
use App\Services\PublicSite\SitepageDataResolverService;
use App\Site\Pools\PoolResolver;
use App\Site\Pools\PoolWire;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * #API-7 (= SCALE-9, 2026-08-24 remainder sweep): itemPayloads() ran the
 * content.identity_candidates read for EVERY audience and then let PoolWire
 * unset the key via DASHBOARD_ONLY_ITEM_KEYS — the public payload build,
 * GET /site/actions and the scoring job each paid a round trip for an answer
 * none of them reads.
 *
 * Asserted on SQL PRESENCE, not query count: itemPayloads() issues a fixed
 * batch of facet queries, so the number moves whenever a facet is added or
 * removed and a count assertion would be brittle for reasons unrelated to
 * this finding. Same DB::listen style as PoolWireLibraryHydrationTest (the
 * SCALE-2 sibling fix in this same seam).
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    setupMediaTables();
    Queue::fake();
});

/** Every statement issued during $run() that touches content.identity_candidates. */
function captureIdentityCandidateSql(callable $run): array
{
    $hits = [];
    DB::connection('pgsql')->listen(function ($q) use (&$hits): void {
        if (str_contains(strtolower((string) $q->sql), 'identity_candidates')) {
            $hits[] = $q->sql;
        }
    });

    $run();

    return $hits;
}

/** A live open candidate pairing two of this owner's items. */
function gateCandidateRow(string $userId, string $leftItemId, string $rightItemId): void
{
    DB::connection('pgsql')->table('content.identity_candidates')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'left_item_id' => $leftItemId,
        'right_item_id' => $rightItemId,
        'score' => 80,
        'evidence' => json_encode(['key' => 'title_similarity']),
        'dismissed_at' => null,
        'created_at' => now(),
    ]);
}

it('never queries content.identity_candidates while building the public pools map', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, poolConnection($pro->id));

    $itemOne = poolItem($pro->id, $sourceId, 'video', 'Video One', now()->subDay()->toDateTimeString());
    $itemTwo = poolItem($pro->id, $sourceId, 'video', 'Video Two', now()->toDateTimeString());
    poolPin($siteId, 'watch', $itemOne);
    poolPin($siteId, 'watch', $itemTwo);
    gateCandidateRow($pro->id, $itemOne, $itemTwo);

    $site = Site::query()->findOrFail($siteId);

    $out = null;
    $hits = captureIdentityCandidateSql(function () use ($site, &$out): void {
        $out = app(PoolWire::class)->forSite($site, app(SitepageDataResolverService::class));
    });

    // The control: prove the hydrate actually ran, so an empty $hits below
    // cannot be "PoolWire bailed early" wearing the costume of a pass.
    expect($out)->toHaveKey('watch');
    expect(array_column($out['watch']['items'], 'id'))->toContain($itemOne);

    expect($hits)->toBe([]);
});

it('still queries content.identity_candidates on the dashboard resolve() path', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, poolConnection($pro->id));

    $itemOne = poolItem($pro->id, $sourceId, 'video', 'Video One', now()->subDay()->toDateTimeString());
    $itemTwo = poolItem($pro->id, $sourceId, 'video', 'Video Two', now()->toDateTimeString());
    poolPin($siteId, 'watch', $itemOne);
    poolPin($siteId, 'watch', $itemTwo);
    gateCandidateRow($pro->id, $itemOne, $itemTwo);

    $site = Site::query()->findOrFail($siteId);

    $resolved = null;
    $hits = captureIdentityCandidateSql(function () use ($site, &$resolved): void {
        $resolved = app(PoolResolver::class)->resolve($site, 'watch');
    });

    expect($hits)->toHaveCount(1);

    // And the chip the dashboard renders from it survives — the gate must not
    // have quietly become "nobody gets candidates".
    $byId = collect($resolved['selection'])->keyBy('id');
    expect($byId[$itemOne]['duplicateCandidates'])->toBe([
        ['itemId' => $itemTwo, 'headline' => 'Video Two', 'evidence' => 'title_similarity'],
    ]);
});

it('keeps duplicateCandidates in the payload shape even when the read is skipped', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, poolConnection($pro->id));
    $itemId = poolItem($pro->id, $sourceId, 'video', 'Video One', now()->toDateTimeString());

    $site = Site::query()->findOrFail($siteId);
    [$payloads] = app(PoolResolver::class)->hydrateItems($site, [$itemId]);

    // Shape invariance: the key is always present (ITEM_KEYS lists it and
    // PoolWireShapeTest pins that list exactly), just always empty. Dropping
    // the key instead of the query would have made this a wire change.
    expect($payloads[$itemId])->toHaveKey('duplicateCandidates');
    expect($payloads[$itemId]['duplicateCandidates'])->toBe([]);
});
