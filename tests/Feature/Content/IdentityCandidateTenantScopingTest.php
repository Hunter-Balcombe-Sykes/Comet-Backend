<?php

use App\Models\Core\Site\Site;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * #SEC-5: itemPayloads()'s content.identity_candidates read only required
 * ONE side of the pair (`ic.left_item_id`/`ic.right_item_id`) to be in the
 * requested $ids — same-tenancy was a convention the writer was trusted to
 * uphold, not something the query itself enforced. A candidate row pairing
 * one tenant's item with another tenant's satisfied the OR and leaked the
 * other tenant's headline_cache into this tenant's dashboard payload. The
 * fix scopes ic/li/ri to $site->user_id explicitly.
 *
 * Goes through PoolResolver::hydrateItems() — the public seam itemPayloads()
 * sits behind — rather than resolve(), which provisions a pool section as a
 * side effect (reference_poolresolver_resolve_provisions_a_section).
 *
 * #API-7 gated that read behind `withDuplicateCandidates`, defaulting OFF on
 * this seam. Both cases below pass it explicitly: without the flag the query
 * never runs, `duplicateCandidates` is [] for every input, and the first case
 * would pass for the wrong reason — a vacuous guard over the leak it exists to
 * pin. The flag is what keeps this test about TENANCY rather than about the
 * gate. resolve() (the dashboard) passes true, so this matches production.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    Queue::fake();
});

function identityCandidateRow(string $userId, string $leftItemId, string $rightItemId): void
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

it('never surfaces a cross-tenant identity candidate, and never leaks the other tenant headline', function () {
    [$proA, $siteAId] = poolTenant();
    [$proB, $siteBId] = poolTenant();

    $sourceA = poolSource($proA->id, poolConnection($proA->id));
    $sourceB = poolSource($proB->id, poolConnection($proB->id));

    $itemA = poolItem($proA->id, $sourceA, 'video', 'Owner A Video', now()->toDateTimeString());
    $itemB = poolItem($proB->id, $sourceB, 'video', 'Owner B Secret Video', now()->toDateTimeString());

    // The row a writer bug (or an attacker) could produce: user_id says A,
    // but right_item_id points at B's item. Structural scoping must catch
    // this even though the writer's OWN user_id claims A.
    identityCandidateRow($proA->id, $itemA, $itemB);

    $siteA = Site::query()->findOrFail($siteAId);
    [$payloads] = app(PoolResolver::class)->hydrateItems($siteA, [$itemA], withDuplicateCandidates: true);

    expect($payloads[$itemA]['duplicateCandidates'])->toBe([]);

    $encoded = json_encode($payloads);
    expect($encoded)->not->toContain('Owner B Secret Video');
    expect($encoded)->not->toContain($itemB);
});

it('still surfaces a legitimate same-tenant identity candidate', function () {
    [$proA, $siteAId] = poolTenant();
    $sourceA = poolSource($proA->id, poolConnection($proA->id));

    $itemA1 = poolItem($proA->id, $sourceA, 'video', 'Owner A Video One', now()->toDateTimeString());
    $itemA2 = poolItem($proA->id, $sourceA, 'video', 'Owner A Video Two', now()->toDateTimeString());

    identityCandidateRow($proA->id, $itemA1, $itemA2);

    $siteA = Site::query()->findOrFail($siteAId);
    [$payloads] = app(PoolResolver::class)->hydrateItems($siteA, [$itemA1, $itemA2], withDuplicateCandidates: true);

    expect($payloads[$itemA1]['duplicateCandidates'])->toBe([
        ['itemId' => $itemA2, 'headline' => 'Owner A Video Two', 'evidence' => 'title_similarity'],
    ]);
    expect($payloads[$itemA2]['duplicateCandidates'])->toBe([
        ['itemId' => $itemA1, 'headline' => 'Owner A Video One', 'evidence' => 'title_similarity'],
    ]);
});
