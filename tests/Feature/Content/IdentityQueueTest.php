<?php

use App\Content\Identity\Decision;
use App\Content\Identity\IdentityKey;
use App\Content\Identity\KeyClass;
use App\Content\Identity\Resolver;
use App\Content\Identity\SourceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupContentCurationTables();
});

/** A source item with a coord, so a ruling has something to be recorded against. */
function seedSourceItem(string $sourceId, string $itemId, string $coord, string $kind = 'track'): void
{
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(),
        'source_id' => $sourceId,
        'coord' => $coord,
        'item_id' => $itemId,
        'kind' => $kind,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

/** What the endpoint wrote, rebuilt as the value objects the resolver takes. */
function storedDecisions(string $userId): array
{
    return DB::table('content.identity_decisions')->where('user_id', $userId)->get()
        ->map(fn ($row) => new Decision($row->left_coord, $row->right_coord, $row->verdict))
        ->all();
}

function seedCandidate(string $userId, string $leftItemId, string $rightItemId, array $overrides = []): string
{
    $id = (string) Str::uuid();

    DB::table('content.identity_candidates')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'left_item_id' => $leftItemId,
        'right_item_id' => $rightItemId,
        'score' => 60,
        'evidence' => json_encode(['class' => 'TitleDuration']),
        'created_at' => now(),
    ], $overrides));

    return $id;
}

/** Two items, each with one source record — the shape a real candidate has. */
function seedDuplicatePair(string $userId): array
{
    $left = seedContentItem($userId, ['kind' => 'track', 'headline_cache' => 'Older', 'first_seen_at' => now()->subDay()]);
    $right = seedContentItem($userId, ['kind' => 'track', 'headline_cache' => 'Newer', 'first_seen_at' => now()]);

    seedSourceItem('src-a', $left, 'spotify:acct:1');
    seedSourceItem('src-b', $right, 'bandcamp:acct:2');

    return [$left, $right, seedCandidate($userId, $left, $right)];
}

it('lists open candidates with both sides described', function () {
    $pro = createTenant('identity-list');
    [, , $candidateId] = seedDuplicatePair($pro->id);

    $response = actingAsUser($pro)->getJson('/api/content/identity/candidates');

    $response->assertOk();
    expect($response->json('candidates'))->toHaveCount(1)
        ->and($response->json('candidates.0.id'))->toBe($candidateId)
        // A card showing only UUIDs is a card nobody can answer.
        ->and($response->json('candidates.0.left.headline'))->toBe('Older')
        ->and($response->json('candidates.0.right.headline'))->toBe('Newer')
        ->and($response->json('openCount'))->toBe(1);
});

it('never shows a settled candidate again', function () {
    $pro = createTenant('identity-settled');
    [$left, $right] = seedDuplicatePair($pro->id);
    seedCandidate($pro->id, $right, $left, ['dismissed_at' => now()]);

    expect(actingAsUser($pro)->getJson('/api/content/identity/candidates')->json('candidates'))->toHaveCount(1);
});

it('records a same ruling as a decision the resolver can read', function () {
    $pro = createTenant('identity-same');
    [, , $candidateId] = seedDuplicatePair($pro->id);

    $response = actingAsUser($pro)->postJson("/api/content/identity/candidates/{$candidateId}/rule", [
        'verdict' => 'same',
    ]);

    $response->assertOk();

    $decision = DB::table('content.identity_decisions')->where('user_id', $pro->id)->first();
    expect($decision->verdict)->toBe('same')
        // Coords, not item ids: item ids are the resolver's OUTPUT and would
        // evaporate the next time identity was recomputed.
        ->and([$decision->left_coord, $decision->right_coord])->toBe(['bandcamp:acct:2', 'spotify:acct:1']);
});

it('keeps the item that was seen first and logs the discard', function () {
    $pro = createTenant('identity-survivor');
    [$older, $newer, $candidateId] = seedDuplicatePair($pro->id);

    $response = actingAsUser($pro)->postJson("/api/content/identity/candidates/{$candidateId}/rule", ['verdict' => 'same']);

    expect($response->json('keptItemId'))->toBe($older)
        ->and($response->json('discardedItemId'))->toBe($newer)
        ->and(DB::table('content.items')->where('id', $newer)->exists())->toBeFalse();

    $merge = DB::table('content.item_merges')->where('user_id', $pro->id)->first();
    expect($merge->kept_item_id)->toBe($older)
        ->and($merge->discarded_item_id)->toBe($newer);
});

it('repoints the source records onto the survivor', function () {
    // Source items are the durable input; facets are derived from them, so
    // repointing the input IS the merge.
    $pro = createTenant('identity-repoint');
    [$older, $newer, $candidateId] = seedDuplicatePair($pro->id);

    actingAsUser($pro)->postJson("/api/content/identity/candidates/{$candidateId}/rule", ['verdict' => 'same'])->assertOk();

    expect(DB::table('content.source_items')->where('item_id', $older)->count())->toBe(2)
        ->and(DB::table('content.source_items')->where('item_id', $newer)->count())->toBe(0);
});

it('moves a pin from the discarded item onto the survivor', function () {
    // The user pinned a thing, not a row id.
    $pro = createTenant('identity-pin-move');
    [$older, $newer, $candidateId] = seedDuplicatePair($pro->id);
    [, $sectionId] = seedPageWithSection($pro->site->id);

    actingAsUser($pro)->putJson("/api/site/sections/{$sectionId}/items/{$newer}", ['state' => 'pinned'])->assertOk();
    actingAsUser($pro)->postJson("/api/content/identity/candidates/{$candidateId}/rule", ['verdict' => 'same'])->assertOk();

    expect(DB::table('site.section_items')->where('section_id', $sectionId)->value('item_id'))->toBe($older);
});

it('keeps the survivor edit when both items edited the same field', function () {
    $pro = createTenant('identity-override-clash');
    [$older, $newer, $candidateId] = seedDuplicatePair($pro->id);

    actingAsUser($pro)->putJson("/api/content/items/{$older}/overrides", [
        'facet' => 'f_text', 'column' => 'headline', 'value' => 'Kept title',
    ])->assertOk();
    actingAsUser($pro)->putJson("/api/content/items/{$newer}/overrides", [
        'facet' => 'f_text', 'column' => 'headline', 'value' => 'Discarded title',
    ])->assertOk();

    actingAsUser($pro)->postJson("/api/content/identity/candidates/{$candidateId}/rule", ['verdict' => 'same'])->assertOk();

    $overrides = DB::table('content.manual_overrides')->where('item_id', $older)->get();
    expect($overrides)->toHaveCount(1)
        ->and(json_decode($overrides->first()->value, true))->toBe('Kept title');
});

it('records a different ruling as a cut and leaves both items alone', function () {
    $pro = createTenant('identity-different');
    [$left, $right, $candidateId] = seedDuplicatePair($pro->id);

    actingAsUser($pro)->postJson("/api/content/identity/candidates/{$candidateId}/rule", ['verdict' => 'different'])
        ->assertOk();

    expect(DB::table('content.identity_decisions')->where('user_id', $pro->id)->value('verdict'))->toBe('different')
        ->and(DB::table('content.items')->whereIn('id', [$left, $right])->count())->toBe(2)
        ->and(DB::table('content.item_merges')->count())->toBe(0);
});

it('lets a user change their mind without tripping the unique key', function () {
    $pro = createTenant('identity-rerule');
    [$left, $right, $first] = seedDuplicatePair($pro->id);

    actingAsUser($pro)->postJson("/api/content/identity/candidates/{$first}/rule", ['verdict' => 'different'])->assertOk();

    // The resolver keeps producing the pair, so the same question comes back
    // — and the second answer must REPLACE the first, not be dropped by the
    // unique index. Changing your mind is the point of the queue.
    $second = seedCandidate($pro->id, $right, $left);
    actingAsUser($pro)->postJson("/api/content/identity/candidates/{$second}/rule", ['verdict' => 'same'])->assertOk();

    expect(DB::table('content.identity_decisions')->where('user_id', $pro->id)->count())->toBe(1)
        ->and(DB::table('content.identity_decisions')->value('verdict'))->toBe('same');
});

it('writes a same ruling the pure resolver honours', function () {
    // The endpoint's whole job is to write in the resolver's vocabulary, so
    // the proof is feeding what it wrote back through the real resolver.
    $pro = createTenant('identity-resolver-same');
    $left = seedContentItem($pro->id, ['kind' => 'track', 'first_seen_at' => now()->subDay()]);
    $right = seedContentItem($pro->id, ['kind' => 'track']);
    seedSourceItem('src-a', $left, 'spotify:acct:1');
    seedSourceItem('src-b', $right, 'bandcamp:acct:2');
    $candidateId = seedCandidate($pro->id, $left, $right);

    actingAsUser($pro)->postJson("/api/content/identity/candidates/{$candidateId}/rule", ['verdict' => 'same'])
        ->assertOk();

    // No shared key at all — only the user's ruling can join these.
    $items = [
        new SourceItem('spotify:acct:1', 'src-a', 'track'),
        new SourceItem('bandcamp:acct:2', 'src-b', 'track'),
    ];

    $resolution = (new Resolver)->resolve($items, storedDecisions($pro->id));

    expect($resolution->sameItem('spotify:acct:1', 'bandcamp:acct:2'))->toBeTrue();
});

it('writes a different ruling as a cut — which DisjointSet applies in only one direction (KNOWN GAP)', function () {
    // CHARACTERISATION, not an endorsement. Decision's own contract says a
    // human's ruling beats every key "in both directions" (C8), and this
    // endpoint stores one row per ruling with the coords sorted, exactly as
    // the unique index expects.
    //
    // DisjointSet::separate() re-roots its SECOND argument, so a cut only
    // bites when that argument is the union CHILD. Whether the sorted order
    // puts the child second depends on which coord happened to win the union
    // — something no writer can know. So a `different` ruling currently fails
    // to split roughly half the time.
    //
    // The fix belongs in app/Content/Identity/DisjointSet.php (lane A), not
    // here: writing the row twice to compensate would have to be un-done the
    // day it is fixed. When it IS fixed, the second expectation below flips —
    // delete this test and merge it into the one above.
    $pro = createTenant('identity-resolver-diff');
    [, , $candidateId] = seedDuplicatePair($pro->id);

    actingAsUser($pro)->postJson("/api/content/identity/candidates/{$candidateId}/rule", ['verdict' => 'different'])
        ->assertOk();

    $stored = DB::table('content.identity_decisions')->where('user_id', $pro->id)->first();
    expect($stored->verdict)->toBe('different');

    // A shared joining key would otherwise merge these outright.
    $items = [
        new SourceItem('spotify:acct:1', 'src-a', 'track', [new IdentityKey(KeyClass::Isrc, 'USRC17607839')]),
        new SourceItem('bandcamp:acct:2', 'src-b', 'track', [new IdentityKey(KeyClass::Isrc, 'USRC17607839')]),
    ];

    $childSecond = (new Resolver)->resolve($items, [new Decision('spotify:acct:1', 'bandcamp:acct:2', 'different')]);
    expect($childSecond->sameItem('spotify:acct:1', 'bandcamp:acct:2'))->toBeFalse();

    // The stored (sorted) order puts the union ROOT second here, and the cut
    // is silently lost. This is the gap.
    $rootSecond = (new Resolver)->resolve($items, storedDecisions($pro->id));
    expect($rootSecond->sameItem('spotify:acct:1', 'bandcamp:acct:2'))->toBeTrue();
});

it('warns rather than lying when an item has no source records', function () {
    $pro = createTenant('identity-no-coords');
    $left = seedContentItem($pro->id, ['kind' => 'track', 'first_seen_at' => now()->subDay()]);
    $right = seedContentItem($pro->id, ['kind' => 'track']);
    $candidateId = seedCandidate($pro->id, $left, $right);

    $response = actingAsUser($pro)->postJson("/api/content/identity/candidates/{$candidateId}/rule", ['verdict' => 'same']);

    $response->assertOk();
    expect($response->json('decisionsWritten'))->toBe(0)
        ->and($response->json('warnings'))->toHaveCount(2)
        ->and($response->json('warnings.0'))->toContain('no source records');
});

it('dismisses a candidate permanently', function () {
    $pro = createTenant('identity-dismiss');
    [, , $candidateId] = seedDuplicatePair($pro->id);

    actingAsUser($pro)->postJson("/api/content/identity/candidates/{$candidateId}/dismiss")->assertOk();

    expect(actingAsUser($pro)->getJson('/api/content/identity/candidates')->json('candidates'))->toBeEmpty()
        ->and(DB::table('content.identity_candidates')->where('id', $candidateId)->value('dismissed_at'))->not->toBeNull();

    // Ruling on it again is refused: the question is settled.
    actingAsUser($pro)->postJson("/api/content/identity/candidates/{$candidateId}/rule", ['verdict' => 'same'])
        ->assertStatus(404);
});

it('rejects a verdict that is neither same nor different', function () {
    $pro = createTenant('identity-verdict');
    [, , $candidateId] = seedDuplicatePair($pro->id);

    actingAsUser($pro)->postJson("/api/content/identity/candidates/{$candidateId}/rule", ['verdict' => 'maybe'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('verdict');
});

it('never lets one user rule on another user\'s duplicates', function () {
    $mine = createTenant('identity-mine');
    $theirs = createTenant('identity-theirs');
    [$left, $right, $candidateId] = seedDuplicatePair($theirs->id);

    actingAsUser($mine)->postJson("/api/content/identity/candidates/{$candidateId}/rule", ['verdict' => 'same'])
        ->assertStatus(404);
    actingAsUser($mine)->postJson("/api/content/identity/candidates/{$candidateId}/dismiss")
        ->assertStatus(404);

    expect(DB::table('content.items')->whereIn('id', [$left, $right])->count())->toBe(2)
        ->and(DB::table('content.identity_decisions')->count())->toBe(0);
});

it('requires authentication', function () {
    $this->getJson('/api/content/identity/candidates')->assertStatus(401);
});
