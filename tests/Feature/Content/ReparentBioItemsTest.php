<?php

use App\Jobs\Content\ReparentBioItemsJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolWriter;
use App\Services\Platforms\CustomLinkSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// A.6: sign-up scrapes seed the library, never the page (pin: false), and
// the reparent job folds a bio-seeded manual item into its ingested twin.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

function reparentUser(array $attrs = []): User
{
    $user = User::factory()->create($attrs);
    $site = new Site(['subdomain' => 'rep'.substr((string) $user->id, 0, 8), 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

it('writes a library-only item when pin is false', function () {
    Queue::fake();
    $user = reparentUser();

    $itemId = app(LinkPoolWriter::class)->add($user, 'https://example.com/quiet', pin: false);

    expect(DB::table('content.items')->where('id', $itemId)->exists())->toBeTrue()
        ->and(DB::table('site.section_items')->where('item_id', $itemId)->exists())->toBeFalse();
});

it('seeds unpinned for an unclaimed user and pinned for a claimed one', function () {
    Queue::fake();
    $unclaimed = reparentUser(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
    $claimed = reparentUser();

    app(CustomLinkSeeder::class)->seedCustom($unclaimed, 'https://someblog.example/one');
    app(CustomLinkSeeder::class)->seedCustom($claimed, 'https://someblog.example/two');

    $unclaimedItem = DB::table('content.items')->where('user_id', $unclaimed->id)->value('id');
    $claimedItem = DB::table('content.items')->where('user_id', $claimed->id)->value('id');
    expect(DB::table('site.section_items')->where('item_id', $unclaimedItem)->exists())->toBeFalse()
        ->and(DB::table('site.section_items')->where('item_id', $claimedItem)->exists())->toBeTrue();
});

it('folds a manual bio item into its ingested twin — pins moved, origin tag kept, one item left', function () {
    Queue::fake();
    $user = reparentUser();

    // The bio-seeded manual item, pinned (a claimed-user seed pins on add).
    app(LinkPoolWriter::class)->add($user, 'https://www.youtube.com/watch?v=abc123', origin: 'scrape');
    $manualId = (string) DB::table('content.items')->where('user_id', $user->id)->value('id');
    expect(DB::table('site.section_items')->where('item_id', $manualId)->exists())->toBeTrue();

    // The platform connection and its ingested twin carrying the same URL.
    $connection = new IntegrationConnection([
        'user_id' => $user->id, 'surface_key' => 'youtube.channel', 'routing_class' => 'content',
        'resource_id' => 'somechannel', 'payload' => ['url' => 'https://youtube.com/@somechannel'],
        'is_active' => true,
    ]);
    $connection->save();
    $sourceId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $user->id, 'kind' => 'connection',
        'connection_id' => $connection->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $ingestedId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $ingestedId, 'user_id' => $user->id, 'kind' => 'video',
        'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId, 'coord' => 'youtube:somechannel:abc123',
        'item_id' => $ingestedId, 'kind' => 'video', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.f_link')->insert([
        'item_id' => $ingestedId, 'source_id' => $sourceId,
        'url' => 'https://www.youtube.com/watch?v=abc123', 'updated_at' => now(),
    ]);

    app()->call([new ReparentBioItemsJob((string) $connection->id), 'handle']);

    expect(DB::table('content.items')->where('id', $manualId)->exists())->toBeFalse()
        ->and(DB::table('content.items')->where('id', $ingestedId)->exists())->toBeTrue()
        ->and(DB::table('site.section_items')->where('item_id', $ingestedId)->exists())->toBeTrue()
        ->and(DB::table('content.item_tags')->where('item_id', $ingestedId)->where('tag_type', 'link_origin')->value('tag'))->toBe('scrape');
});
