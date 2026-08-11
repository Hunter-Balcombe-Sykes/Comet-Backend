<?php

use App\Models\Core\Site\Site;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolResolver;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Queue::fake();
});

function eventsConnection(string $userId): string
{
    $id = (string) Str::uuid();
    DB::table('site.platform_connections')->insert([
        'id' => $id, 'user_id' => $userId, 'surface_key' => 'eventbrite.organiser',
        'routing_class' => 'content', 'resource_id' => 'acct-'.Str::random(6),
        'payload' => '{}', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function eventsSource(string $userId, string $connectionId): string
{
    $id = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'connection',
        'connection_id' => $connectionId, 'priority' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function eventItem(string $userId, string $sourceId, string $headline, ?string $startsAtUtc): string
{
    $id = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'event',
        'headline_cache' => $headline, 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now()->subDays(10), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'eventbrite:acct-test:'.Str::random(10), 'item_id' => $id,
        'kind' => 'event', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    if ($startsAtUtc !== null) {
        DB::table('content.f_occurrence')->insert([
            'item_id' => $id, 'source_id' => $sourceId,
            'starts_at_local' => $startsAtUtc, 'starts_at_utc' => $startsAtUtc,
            'zone_confidence' => 'offset_only', 'is_all_day' => 0,
            'updated_at' => now(),
        ]);
    }

    return $id;
}

it('registers events as a pool on the events page without a latest tag', function () {
    expect(PoolRegistry::isPool('events'))->toBeTrue();
    expect(PoolRegistry::kinds('events'))->toBe(['event']);
    expect(PoolRegistry::PAGE_KEYS['events'])->toBe('events');
    expect(PoolRegistry::PAGE_LABELS['events'])->toBe('Events');
    // A rolling list of dated events has no single "latest" — the soonest is
    // simply the first item, and a Latest badge on it would read as "new".
    expect(PoolRegistry::carriesLatestTag('events'))->toBeFalse();
});

it('provisions the events section with the upcoming rule, not latest-per-source', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();

    $section = app(PoolSectionProvisioner::class)->ensure($site, 'events');
    $ops = array_column(json_decode((string) $section->rule, true)['all'], 'op');

    expect($ops)->toContain('kind_is');
    expect($ops)->toContain('upcoming_occurrence');
    expect($ops)->not->toContain('latest_per_auto_source');
    expect($section->order_by)->toBe('occurrence');
    expect($section->mode)->toBe('mixed');
});

it('leaves the watch and listen sections on the latest-per-source rule', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();

    foreach (['watch', 'listen'] as $pool) {
        $section = app(PoolSectionProvisioner::class)->ensure($site, $pool);
        $ops = array_column(json_decode((string) $section->rule, true)['all'], 'op');

        expect($ops)->toContain('latest_per_auto_source');
        expect($section->order_by)->toBe('recency');
    }
});

it('hangs the events section off the existing events page', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();

    $section = app(PoolSectionProvisioner::class)->ensure($site, 'events');
    $pageKey = DB::connection('pgsql')->table('site.pages')->where('id', $section->page_id)->value('key');

    expect($pageKey)->toBe('events');
});

it('selects every upcoming event, not one per source', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $source = eventsSource($pro->id, eventsConnection($pro->id));

    eventItem($pro->id, $source, 'Sewing workshop', now()->addDays(3)->toDateTimeString());
    eventItem($pro->id, $source, 'Grant writing', now()->addDays(10)->toDateTimeString());
    eventItem($pro->id, $source, 'Clothes swap', now()->addDays(20)->toDateTimeString());

    $resolved = app(PoolResolver::class)->resolve($site, 'events');

    // Three events from ONE source. latest_per_auto_source would give one.
    expect(array_column($resolved['selection'], 'headline'))
        ->toBe(['Sewing workshop', 'Grant writing', 'Clothes swap']);
});

it('drops a past event from the selection but keeps it in the library', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $source = eventsSource($pro->id, eventsConnection($pro->id));

    eventItem($pro->id, $source, 'Last month', now()->subDays(30)->toDateTimeString());
    eventItem($pro->id, $source, 'Next week', now()->addDays(7)->toDateTimeString());

    $resolved = app(PoolResolver::class)->resolve($site, 'events');

    expect(array_column($resolved['selection'], 'headline'))->toBe(['Next week']);
    expect(array_column($resolved['library'], 'headline'))->toContain('Last month', 'Next week');
});

it('keeps an event that started earlier today', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $source = eventsSource($pro->id, eventsConnection($pro->id));

    eventItem($pro->id, $source, 'Running now', now()->subHours(2)->toDateTimeString());

    expect(array_column(app(PoolResolver::class)->resolve($site, 'events')['selection'], 'headline'))
        ->toBe(['Running now']);
});

it('does not auto-select an undated event but keeps it pinnable', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $source = eventsSource($pro->id, eventsConnection($pro->id));

    eventItem($pro->id, $source, 'No date given', null);

    $resolved = app(PoolResolver::class)->resolve($site, 'events');

    expect($resolved['selection'])->toBe([]);
    expect(array_column($resolved['library'], 'headline'))->toBe(['No date given']);
});

it('orders the events selection soonest first regardless of ingest order', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $source = eventsSource($pro->id, eventsConnection($pro->id));

    // Inserted furthest-first on purpose — recency ordering would keep this.
    eventItem($pro->id, $source, 'Furthest', now()->addDays(30)->toDateTimeString());
    eventItem($pro->id, $source, 'Middle', now()->addDays(14)->toDateTimeString());
    eventItem($pro->id, $source, 'Soonest', now()->addDays(2)->toDateTimeString());

    expect(array_column(app(PoolResolver::class)->resolve($site, 'events')['selection'], 'headline'))
        ->toBe(['Soonest', 'Middle', 'Furthest']);
});

it('does not duplicate an event carried by two sources', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $sourceA = eventsSource($pro->id, eventsConnection($pro->id));
    $sourceB = eventsSource($pro->id, eventsConnection($pro->id));

    $itemId = eventItem($pro->id, $sourceA, 'Double-listed', now()->addDays(5)->toDateTimeString());
    DB::table('content.f_occurrence')->insert([
        'item_id' => $itemId, 'source_id' => $sourceB,
        'starts_at_local' => now()->addDays(5)->toDateTimeString(),
        'starts_at_utc' => now()->addDays(5)->toDateTimeString(),
        'zone_confidence' => 'offset_only', 'is_all_day' => 0,
        'updated_at' => now(),
    ]);

    expect(app(PoolResolver::class)->resolve($site, 'events')['selection'])->toHaveCount(1);
});
