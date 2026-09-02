<?php

use App\Catalog\CompiledCatalog;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\HiddenConnections;
use App\Routing\IriCanonicalizer;
use App\Routing\Placement;
use App\Routing\RoutingContext;
use App\Routing\SourceReconciler;
use App\Routing\SuggestionApplier;
use App\Routing\Verdict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// A.3 (setup-dialog run): hidden connections ingest but touch no
// consumer-facing surface until revealed; discard deletes the row and the
// ingested items nothing else vouches for.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
});

function hiddenRoutingConnection(string $userId, array $overrides = []): IntegrationConnection
{
    $connection = new IntegrationConnection(array_merge([
        'user_id' => $userId,
        'surface_key' => 'instagram.profile',
        'routing_class' => 'social',
        'resource_id' => 'hidden-one',
        'payload' => ['url' => 'https://www.instagram.com/hidden-one', 'username' => 'hidden-one'],
        'is_active' => true,
        'visibility' => IntegrationConnection::VISIBILITY_HIDDEN,
    ], $overrides));
    $connection->save();

    return $connection;
}

function seedHiddenTestIntent(string $userId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('routing.source_intents')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'surface_key' => 'instagram.profile',
        'routing_class' => 'social',
        'identifier' => 'someone',
        'canonical_url' => 'https://www.instagram.com/someone',
        'state' => 'proposed',
        'origin' => 'website_import',
        'first_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

it('keeps hidden rows out of the connections list', function () {
    $pro = createTenant('hidden-list');
    hiddenRoutingConnection($pro->id);
    hiddenRoutingConnection($pro->id, ['resource_id' => 'visible-one', 'visibility' => 'visible']);

    $response = actingAsUser($pro)->getJson('/api/routing/connections');

    $response->assertOk();
    $ids = collect($response->json('connections'))->pluck('resourceId', 'id')->values();
    expect($response->json('connections'))->toHaveCount(1);
});

it('does not let a hidden row hold a surface slot against the cap', function () {
    $pro = createTenant('hidden-cap');
    // instagram.profile is a single-account surface (max_accounts 1).
    hiddenRoutingConnection($pro->id, ['resource_id' => 'hidden-dj']);

    $iri = app(IriCanonicalizer::class)->canonicalize('https://www.instagram.com/real-dj/');
    app(SourceReconciler::class)->reconcile(
        new Placement(Verdict::Place, 'instagram.profile', 'real-dj'),
        RoutingContext::forUser($pro, 'link_in_bio'),
        $iri,
    );

    $intent = DB::table('routing.source_intents')->where('user_id', $pro->id)->where('identifier', 'real-dj')->first();
    expect($intent->state)->not->toBe('blocked')
        ->and($intent->block_reason)->toBeNull();
});

it('keeps asking about an intent whose only matching connection is hidden', function () {
    $pro = createTenant('hidden-inbox');
    hiddenRoutingConnection($pro->id, ['resource_id' => 'someone']);
    seedHiddenTestIntent($pro->id); // instagram.profile / someone

    $response = actingAsUser($pro)->getJson('/api/routing/suggestions');

    expect($response->json('suggestions'))->toHaveCount(1);
});

it('applies a suggestion as a hidden connection that settles the intent but skips the publish effects', function () {
    Queue::fake();
    $pro = createTenant('hidden-apply');
    $intentId = seedHiddenTestIntent($pro->id);
    $intent = DB::table('routing.source_intents')->where('id', $intentId)->first();

    $connection = app(SuggestionApplier::class)->apply($pro, $intent, CompiledCatalog::surface('instagram.profile'), hidden: true);

    expect($connection->visibility)->toBe('hidden')
        ->and(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('applied');
    Queue::assertNotPushed(CloudflareCachePurgeJob::class);
});

it('reveal flips visibility and runs the skipped publish effects', function () {
    Queue::fake();
    $pro = createTenant('hidden-reveal');
    $connection = hiddenRoutingConnection($pro->id);
    Queue::assertNotPushed(CloudflareCachePurgeJob::class);

    app(HiddenConnections::class)->reveal($connection);

    expect($connection->refresh()->visibility)->toBe('visible');
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('reveal of an ordering connection dispatches the menu fetch the hidden create skipped', function () {
    Queue::fake();
    $pro = createTenant('hidden-menu');
    $connection = hiddenRoutingConnection($pro->id, [
        'surface_key' => 'uber_eats.order',
        'routing_class' => 'ordering',
        'resource_id' => 'akro-studio',
        'payload' => ['url' => 'https://www.ubereats.com/store/akro-studio/abc'],
    ]);
    Queue::assertNotPushed(MenuFetchJob::class);

    app(HiddenConnections::class)->reveal($connection);

    Queue::assertPushed(MenuFetchJob::class);
});

it('discard deletes the connection and its solo-sourced unpinned items, keeping pinned ones', function () {
    $pro = createTenant('hidden-discard');
    $connection = hiddenRoutingConnection($pro->id);

    $sourceId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $pro->id, 'kind' => 'connection',
        'connection_id' => $connection->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $mk = function (string $kind) use ($pro, $sourceId) {
        $itemId = (string) Str::uuid();
        DB::table('content.items')->insert([
            'id' => $itemId, 'user_id' => $pro->id, 'kind' => $kind,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('content.source_items')->insert([
            'id' => (string) Str::uuid(), 'source_id' => $sourceId, 'coord' => 'ig:x:'.$itemId,
            'item_id' => $itemId, 'kind' => $kind, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        return $itemId;
    };
    $unpinned = $mk('media');
    $pinned = $mk('media');
    $sectionId = (string) Str::uuid();
    DB::table('site.section_items')->insert([
        'id' => (string) Str::uuid(), 'section_id' => $sectionId,
        'item_id' => $pinned, 'state' => 'pinned', 'created_at' => now(),
    ]);

    app(HiddenConnections::class)->discard($connection);

    expect(DB::table('content.items')->where('id', $unpinned)->exists())->toBeFalse()
        ->and(DB::table('content.items')->where('id', $pinned)->exists())->toBeTrue()
        ->and(IntegrationConnection::query()->whereKey($connection->id)->exists())->toBeFalse();
});
