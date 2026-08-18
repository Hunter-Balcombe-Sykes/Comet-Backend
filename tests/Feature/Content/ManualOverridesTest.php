<?php

use App\Content\Values\Contribution;
use App\Content\Values\ValueResolver;
use App\Models\Content\ManualOverride;
use App\Models\Core\Site\Site;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupContentCurationTables();
    // #PGR-36: ManualOverrideController now routes through SiteCacheLanes::
    // bust(), which dispatches CloudflareCachePurgeJob. QUEUE_CONNECTION=sync
    // in phpunit.xml means an unfaked queue runs the job inline, including its
    // self-dispatched delayed follow-ups (sync ignores delay()) — four
    // executions per bust. Faked so this file's tests measure the override
    // behaviour, not job side effects.
    Queue::fake();
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

    // AUDIT-2026-08-05: GET /api/content/items/{item}/overrides (the index
    // route) was removed as an orphaned endpoint — read the stored row
    // directly through the model instead.
    expect(DB::table('content.manual_overrides')->where('item_id', $itemId)->count())->toBe(1)
        ->and(ManualOverride::query()->where('item_id', $itemId)->firstOrFail()->value)
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

    actingAsUser($mine)->putJson("/api/content/items/{$theirItem}/overrides", [
        'facet' => 'f_text', 'column' => 'headline', 'value' => 'Pwned',
    ])->assertStatus(404);

    expect(DB::table('content.manual_overrides')->where('item_id', $theirItem)->exists())->toBeFalse();
});

it('404s an item that does not exist', function () {
    $pro = createTenant('override-nonexistent');

    actingAsUser($pro)->putJson('/api/content/items/'.Str::uuid().'/overrides', [
        'facet' => 'f_text', 'column' => 'headline', 'value' => 'x',
    ])->assertStatus(404);
});

it('requires authentication', function () {
    $this->putJson('/api/content/items/'.Str::uuid().'/overrides', [
        'facet' => 'f_text', 'column' => 'headline', 'value' => 'x',
    ])->assertStatus(401);
});

it('applies every override column on the wire, not just headline (owner, 2026-08-18)', function () {
    // The sheets wrote overrides for description/duration/venue/… and the
    // resolver read back headline alone — a save that toasts and no-ops.
    [$pro] = poolTenant();
    $source = poolSource($pro->id, poolConnection($pro->id));
    $itemId = poolItem($pro->id, $source, 'video', 'Original title', now()->toDateTimeString());

    foreach ([
        ['f_text', 'body', 'Edited description'],
        ['f_duration', 'seconds', 1800],
        ['f_authored', 'creator', 'Edited Creator'],
    ] as [$facet, $column, $value]) {
        DB::connection('pgsql')->table('content.manual_overrides')->insert([
            'id' => (string) Str::uuid(), 'item_id' => $itemId,
            'facet' => $facet, 'column_name' => $column,
            'value' => json_encode($value),
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ]);
    }

    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $item = collect(app(PoolResolver::class)->resolve($site, 'watch')['selection'])
        ->firstWhere('id', $itemId);

    expect($item['description'])->toBe('Edited description')
        ->and($item['durationSeconds'])->toBe(1800)
        ->and($item['creator'])->toBe('Edited Creator')
        ->and($item['headline'])->toBe('Original title');
});
