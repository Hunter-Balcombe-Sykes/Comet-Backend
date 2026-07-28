<?php

use App\Content\Values\Contribution;
use App\Content\Values\ValueResolver;
use App\Models\Content\ManualOverride;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupContentCurationTables();
});

it('stores an override and reports it as edited', function () {
    $pro = createTenant('override-store');
    $itemId = seedContentItem($pro->id);

    $response = actingAsUser($pro)->putJson("/api/content/items/{$itemId}/overrides", [
        'facet' => 'f_text', 'column' => 'headline', 'value' => 'My own title',
    ]);

    $response->assertOk();
    expect($response->json('override.value'))->toBe('My own title')
        ->and($response->json('override.isCleared'))->toBeFalse();
});

it('treats an explicit null as a clear rather than a missing field', function () {
    // "The user blanked this" and "nothing here" are different answers, and
    // only the first must beat every source.
    $pro = createTenant('override-clear');
    $itemId = seedContentItem($pro->id);

    $response = actingAsUser($pro)->putJson("/api/content/items/{$itemId}/overrides", [
        'facet' => 'f_text', 'column' => 'body', 'value' => null,
    ]);

    $response->assertOk();
    expect($response->json('override.isCleared'))->toBeTrue()
        ->and(DB::table('content.manual_overrides')->where('item_id', $itemId)->exists())->toBeTrue();
});

it('rejects a write that omits the value entirely', function () {
    // `present` not `required`: omitting the key is a client bug, sending null
    // is a deliberate clear, and the two must not be confused.
    $pro = createTenant('override-missing');
    $itemId = seedContentItem($pro->id);

    actingAsUser($pro)->putJson("/api/content/items/{$itemId}/overrides", [
        'facet' => 'f_text', 'column' => 'headline',
    ])->assertStatus(422)->assertJsonValidationErrors('value');
});

it('refuses a field that is not hand-editable', function () {
    // An override is honoured absolutely and forever, so an unvalidated pair
    // would be a durable row nothing reads.
    $pro = createTenant('override-unknown');
    $itemId = seedContentItem($pro->id);

    actingAsUser($pro)->putJson("/api/content/items/{$itemId}/overrides", [
        'facet' => 'f_text', 'column' => 'nonsense', 'value' => 'x',
    ])->assertStatus(422)->assertJsonValidationErrors('column');

    actingAsUser($pro)->putJson("/api/content/items/{$itemId}/overrides", [
        'facet' => 'f_catalog', 'column' => 'isrc', 'value' => 'USRC17607839',
    ])->assertStatus(422)->assertJsonValidationErrors('column');
});

it('replaces the value on a second edit of the same field', function () {
    $pro = createTenant('override-replace');
    $itemId = seedContentItem($pro->id);

    actingAsUser($pro)->putJson("/api/content/items/{$itemId}/overrides", [
        'facet' => 'f_text', 'column' => 'headline', 'value' => 'First',
    ])->assertOk();
    actingAsUser($pro)->putJson("/api/content/items/{$itemId}/overrides", [
        'facet' => 'f_text', 'column' => 'headline', 'value' => 'Second',
    ])->assertOk();

    expect(DB::table('content.manual_overrides')->where('item_id', $itemId)->count())->toBe(1)
        ->and(actingAsUser($pro)->getJson("/api/content/items/{$itemId}/overrides")->json('overrides.0.value'))
        ->toBe('Second');
});

it('never freezes a sibling column', function () {
    // Editing one field freezes THAT field. A user who fixes a typo in a title
    // has not asked for the body to stop updating.
    $pro = createTenant('override-siblings');
    $itemId = seedContentItem($pro->id);

    actingAsUser($pro)->putJson("/api/content/items/{$itemId}/overrides", [
        'facet' => 'f_text', 'column' => 'headline', 'value' => 'Mine',
    ])->assertOk();

    $overrides = DB::table('content.manual_overrides')->where('item_id', $itemId)->get();
    expect($overrides)->toHaveCount(1)
        ->and($overrides->first()->column_name)->toBe('headline');
});

it('resets to source by deleting the row', function () {
    $pro = createTenant('override-reset');
    $itemId = seedContentItem($pro->id);

    actingAsUser($pro)->putJson("/api/content/items/{$itemId}/overrides", [
        'facet' => 'f_text', 'column' => 'headline', 'value' => 'Mine',
    ])->assertOk();

    actingAsUser($pro)->deleteJson("/api/content/items/{$itemId}/overrides/f_text/headline")->assertOk();

    expect(DB::table('content.manual_overrides')->where('item_id', $itemId)->count())->toBe(0);
});

it('404s a reset of a field that was never edited', function () {
    $pro = createTenant('override-reset-missing');
    $itemId = seedContentItem($pro->id);

    actingAsUser($pro)->deleteJson("/api/content/items/{$itemId}/overrides/f_text/headline")->assertStatus(404);
});

it('writes a row ValueResolver reads as the winning value', function () {
    // The endpoint's contract is the resolver's read shape, so the proof is
    // feeding the stored row back through the real ValueResolver.
    $pro = createTenant('override-resolver');
    $itemId = seedContentItem($pro->id);

    actingAsUser($pro)->putJson("/api/content/items/{$itemId}/overrides", [
        'facet' => 'f_text', 'column' => 'headline', 'value' => 'What the user typed',
    ])->assertOk();

    $override = ManualOverride::query()->where('item_id', $itemId)->firstOrFail();

    $resolved = (new ValueResolver)->resolve(
        'f_text',
        'headline',
        [new Contribution('src-a', 'What the platform says', 900)],
        $override->toOverride(),
    );

    expect($resolved)->toBe('What the user typed');
});

it('writes a cleared row ValueResolver reads as an explicit blank', function () {
    $pro = createTenant('override-resolver-clear');
    $itemId = seedContentItem($pro->id);

    actingAsUser($pro)->putJson("/api/content/items/{$itemId}/overrides", [
        'facet' => 'f_text', 'column' => 'body', 'value' => null,
    ])->assertOk();

    $override = ManualOverride::query()->where('item_id', $itemId)->firstOrFail();

    $resolved = (new ValueResolver)->resolve(
        'f_text',
        'body',
        [new Contribution('src-a', 'A description the user deleted', 900)],
        $override->toOverride(),
    );

    // Not "no override" — the user cleared it, and the clear must win.
    expect($resolved)->toBeNull();
});

it('marks the built document stale on every edit', function () {
    $pro = createTenant('override-bump');
    $itemId = seedContentItem($pro->id);

    actingAsUser($pro)->putJson("/api/content/items/{$itemId}/overrides", [
        'facet' => 'f_text', 'column' => 'headline', 'value' => 'Mine',
    ])->assertOk();

    expect((int) DB::table('site.site_build_state')->where('site_id', $pro->site->id)->value('content_revision'))
        ->toBeGreaterThan(0);
});

it('never lets one user edit another user\'s item', function () {
    $mine = createTenant('override-mine');
    $theirs = createTenant('override-theirs');
    $theirItem = seedContentItem($theirs->id);

    actingAsUser($mine)->getJson("/api/content/items/{$theirItem}/overrides")->assertStatus(404);
    actingAsUser($mine)->putJson("/api/content/items/{$theirItem}/overrides", [
        'facet' => 'f_text', 'column' => 'headline', 'value' => 'Pwned',
    ])->assertStatus(404);

    expect(DB::table('content.manual_overrides')->where('item_id', $theirItem)->exists())->toBeFalse();
});

it('404s an item that does not exist', function () {
    $pro = createTenant('override-nonexistent');

    actingAsUser($pro)->getJson('/api/content/items/'.Str::uuid().'/overrides')->assertStatus(404);
});

it('requires authentication', function () {
    $this->getJson('/api/content/items/'.Str::uuid().'/overrides')->assertStatus(401);
});
