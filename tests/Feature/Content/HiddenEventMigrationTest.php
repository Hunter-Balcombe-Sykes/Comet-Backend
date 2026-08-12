<?php

use App\Models\Core\Site\Site;
use App\Services\Platforms\EventsPayload;
use App\Site\Pools\PoolResolver;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Dev carries ZERO hiddenEventIds, so this lane is unverifiable against live
// data and every case here is fabricated. Said plainly rather than implied —
// the slice checkpoint must not claim a live assertion that does not exist.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Queue::fake();
});

function hiddenConnection(string $userId, array $hiddenIds, array $upcoming = []): string
{
    $id = (string) Str::uuid();
    DB::table('site.platform_connections')->insert([
        'id' => $id, 'user_id' => $userId, 'surface_key' => 'eventbrite.organiser',
        'routing_class' => 'events', 'resource_id' => 'acct-'.Str::random(6),
        'payload' => json_encode([
            'url' => 'https://www.eventbrite.com/o/acme',
            'organiser' => 'Acme',
            'next' => $upcoming[0] ?? null,
            'upcoming' => $upcoming,
            'hiddenEventIds' => $hiddenIds,
        ]),
        'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function hiddenEventItem(string $userId, string $sourceId, string $headline, string $link): string
{
    $id = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'event',
        'headline_cache' => $headline, 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'eventbrite:acct:'.Str::random(8), 'item_id' => $id,
        'kind' => 'event', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.f_link')->insert([
        'item_id' => $id, 'source_id' => $sourceId,
        'url' => $link, 'updated_at' => now(),
    ]);
    DB::table('content.f_occurrence')->insert([
        'item_id' => $id, 'source_id' => $sourceId,
        'starts_at_local' => now()->addDays(7)->toDateTimeString(),
        'starts_at_utc' => now()->addDays(7)->toDateTimeString(),
        'zone_confidence' => 'offset_only', 'is_all_day' => 0, 'updated_at' => now(),
    ]);

    return $id;
}

function hiddenSource(string $userId, string $connectionId): string
{
    $id = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'connection',
        'connection_id' => $connectionId, 'priority' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

// The load-bearing property: a hidden event is ABSENT from upcoming[], so the
// mapping cannot come from the payload. It comes from hashing the content
// item's own link, which reproduces the id the dashboard stored.
it('excludes a hidden event from the pool selection', function () {
    $pro = createTenant('hidden-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();

    $hiddenLink = 'https://www.eventbrite.com/e/hidden-thing-tickets-123';
    $connection = hiddenConnection($pro->id, [EventsPayload::id($hiddenLink)]);
    $source = hiddenSource($pro->id, $connection);

    $hidden = hiddenEventItem($pro->id, $source, 'Hidden thing', $hiddenLink);
    hiddenEventItem($pro->id, $source, 'Visible thing', 'https://www.eventbrite.com/e/visible-thing-tickets-456');

    // Before: the pool shows BOTH — this is the regression the migration exists
    // to prevent, so assert it rather than assuming it.
    expect(array_column(app(PoolResolver::class)->resolve($site, 'events')['selection'], 'headline'))
        ->toContain('Hidden thing');

    $this->artisan('content:migrate-hidden-events')
        ->expectsOutputToContain('excluded 1')
        ->assertExitCode(0);

    expect(array_column(app(PoolResolver::class)->resolve($site, 'events')['selection'], 'headline'))
        ->toBe(['Visible thing']);
    expect(DB::table('content.items')->where('id', $hidden)->value('removed_at'))->toBeNull();
});

it('writes nothing under --dry-run', function () {
    $pro = createTenant('hidden-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();

    $link = 'https://www.eventbrite.com/e/hidden-thing-tickets-123';
    $source = hiddenSource($pro->id, hiddenConnection($pro->id, [EventsPayload::id($link)]));
    hiddenEventItem($pro->id, $source, 'Hidden thing', $link);

    $this->artisan('content:migrate-hidden-events --dry-run')
        ->expectsOutputToContain('would exclude 1')
        ->assertExitCode(0);

    expect(DB::table('site.section_items')->count())->toBe(0);
});

it('re-runs without duplicating the exclude', function () {
    $pro = createTenant('hidden-'.Str::lower(Str::random(6)));
    $link = 'https://www.eventbrite.com/e/hidden-thing-tickets-123';
    $source = hiddenSource($pro->id, hiddenConnection($pro->id, [EventsPayload::id($link)]));
    hiddenEventItem($pro->id, $source, 'Hidden thing', $link);

    $this->artisan('content:migrate-hidden-events')->assertExitCode(0);
    $this->artisan('content:migrate-hidden-events')->assertExitCode(0);

    expect(DB::table('site.section_items')->where('state', 'excluded')->count())->toBe(1);
});

// A pin is a newer, more deliberate signal than a legacy hide: the owner
// chose this item in the pool lane after hiding it in the old one.
it('leaves a pinned item pinned rather than excluding it', function () {
    $pro = createTenant('hidden-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();

    $link = 'https://www.eventbrite.com/e/hidden-thing-tickets-123';
    $source = hiddenSource($pro->id, hiddenConnection($pro->id, [EventsPayload::id($link)]));
    $itemId = hiddenEventItem($pro->id, $source, 'Hidden thing', $link);

    $section = app(PoolSectionProvisioner::class)->ensure($site, 'events');
    DB::table('site.section_items')->insert([
        'id' => (string) Str::uuid(), 'section_id' => $section->id, 'item_id' => $itemId,
        'state' => 'pinned', 'sort_key' => 1.0, 'created_at' => now(),
    ]);

    $this->artisan('content:migrate-hidden-events')->assertExitCode(0);

    expect(DB::table('site.section_items')->where('item_id', $itemId)->value('state'))->toBe('pinned');
});

it('reports a hidden id with no matching content item as unmatched', function () {
    $pro = createTenant('hidden-'.Str::lower(Str::random(6)));
    $source = hiddenSource($pro->id, hiddenConnection($pro->id, [EventsPayload::id('https://www.eventbrite.com/e/never-ingested-999')]));
    hiddenEventItem($pro->id, $source, 'Something else', 'https://www.eventbrite.com/e/something-else-tickets-1');

    $this->artisan('content:migrate-hidden-events')
        ->expectsOutputToContain('unmatched 1')
        ->assertExitCode(0);

    expect(DB::table('site.section_items')->count())->toBe(0);
});

it('does nothing when no connection hides anything', function () {
    $pro = createTenant('hidden-'.Str::lower(Str::random(6)));
    $source = hiddenSource($pro->id, hiddenConnection($pro->id, []));
    hiddenEventItem($pro->id, $source, 'Visible', 'https://www.eventbrite.com/e/visible-1');

    $this->artisan('content:migrate-hidden-events')
        ->expectsOutputToContain('none to migrate')
        ->assertExitCode(0);
});

// The id is a pure function of the canonical link — this is what makes the
// mapping total for hidden events, which are absent from the payload.
it('derives the dashboard id from the link exactly', function () {
    expect(EventsPayload::id('https://www.eventbrite.com/e/connect-sydney-grant-writing-workshop-tickets-1993572537124'))
        ->toBe('a53103b7e77fd64f');
});
