<?php

use App\Models\Core\Site\Site;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// #LIFE-2, #LIFE-4, #API-1 — the three PoolResolver surfaces W2's
// "disconnect = hide" contract never reached.
//
// W2 introduced LiveSourceScope and applied it to resolve()'s library query and
// the pinned-items re-check. Everything ELSE that reads through a source kept
// publishing from dead ones: the source_stats badge, the item's f_link rows and
// the newer per-offer links.
//
// The scenario that makes these reachable is an item carried by TWO sources.
// With only one source, disconnecting it empties the selection and the bug is
// masked — which is presumably why it survived review. With two, the item
// legitimately stays alive via the live source, and the dead one's rating and
// url ride along with it.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

/** Bind an existing item to a SECOND source, as a two-platform item really is. */
function alsoCarriedBy(string $itemId, string $sourceId, string $kind): void
{
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'x:'.Str::random(8), 'item_id' => $itemId, 'kind' => $kind,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
}

function sourceStats(string $sourceId, float $avg, int $count, string $summary): void
{
    DB::table('content.source_stats')->insert([
        'source_id' => $sourceId, 'rating_avg' => $avg, 'rating_count' => $count,
        'summary_text' => $summary, 'updated_at' => now(),
    ]);
}

it('stops publishing a disconnected listing\'s rating, while keeping the live one\'s (LIFE-2)', function () {
    [$pro, $siteId] = poolBusinessTenant();
    $site = Site::findOrFail($siteId);

    $liveConn = poolConnection($pro->id, 'instagram.profile');
    $liveSource = poolSource($pro->id, $liveConn);
    $deadConn = poolConnection($pro->id, 'google.business');
    $deadSource = poolSource($pro->id, $deadConn);

    $review = poolItem($pro->id, $liveSource, 'review', 'A review', '2026-08-01T00:00:00Z');
    alsoCarriedBy($review, $deadSource, 'review');
    poolPin($siteId, 'reviews', $review);

    // The Google listing is the busier one, so orderByDesc(rating_count) picks
    // it — that is what makes this observable rather than theoretical.
    sourceStats($deadSource, 4.8, 900, 'Loved by everyone');
    sourceStats($liveSource, 4.1, 12, 'A few kind words');

    expect(app(PoolResolver::class)->resolve($site, 'reviews')['stats']['ratingAvg'])->toBe(4.8);

    // The owner disconnects Google because the reviews are bad.
    DB::table('site.platform_connections')->where('id', $deadConn)->update(['deleted_at' => now()]);

    $stats = app(PoolResolver::class)->resolve($site, 'reviews')['stats'];

    // Before #LIFE-2 this still returned 4.8 / 900 — the items went and the
    // badge summarising them stayed, which is the same disclosure in aggregate.
    expect($stats['ratingAvg'])->toBe(4.1)
        ->and($stats['ratingCount'])->toBe(12)
        ->and($stats['summaryText'])->toBe('A few kind words');
});

it('drops the rating entirely when the only stats-bearing source is disconnected', function () {
    [$pro, $siteId] = poolBusinessTenant();
    $site = Site::findOrFail($siteId);

    $liveConn = poolConnection($pro->id, 'instagram.profile');
    $liveSource = poolSource($pro->id, $liveConn);
    $deadConn = poolConnection($pro->id, 'google.business');
    $deadSource = poolSource($pro->id, $deadConn);

    $review = poolItem($pro->id, $liveSource, 'review', 'A review', '2026-08-01T00:00:00Z');
    alsoCarriedBy($review, $deadSource, 'review');
    poolPin($siteId, 'reviews', $review);
    sourceStats($deadSource, 4.8, 900, 'Loved by everyone');

    DB::table('site.platform_connections')->where('id', $deadConn)->update(['deleted_at' => now()]);

    // The item survives on the live source; there is simply no rating to show.
    // Null, not 0.0 — a zero-star business is worse than no badge.
    expect(app(PoolResolver::class)->resolve($site, 'reviews')['stats'])->toBeNull();
});

it('stops surfacing a link belonging to a disconnected source (LIFE-4)', function () {
    [$pro, $siteId] = poolBusinessTenant();
    $site = Site::findOrFail($siteId);

    $liveConn = poolConnection($pro->id, 'instagram.profile');
    $liveSource = poolSource($pro->id, $liveConn);
    $deadConn = poolConnection($pro->id, 'fresha.venue');
    $deadSource = poolSource($pro->id, $deadConn);

    $item = poolItem($pro->id, $liveSource, 'video', 'A video', '2026-08-01T00:00:00Z');
    alsoCarriedBy($item, $deadSource, 'video');
    poolPin($siteId, 'watch', $item);

    // Higher priority, so ->first() per item makes it the PRIMARY link — the
    // "book now" the visitor actually taps.
    DB::table('content.sources')->where('id', $deadSource)->update(['priority' => 500]);
    DB::table('content.f_link')->insert([
        'item_id' => $item, 'source_id' => $deadSource,
        'url' => 'https://www.fresha.com/a/dead-venue', 'updated_at' => now(),
    ]);
    DB::table('content.f_link')->insert([
        'item_id' => $item, 'source_id' => $liveSource,
        'url' => 'https://www.instagram.com/p/live', 'updated_at' => now(),
    ]);

    $before = app(PoolResolver::class)->resolve($site, 'watch')['selection'][0];
    expect($before['url'])->toBe('https://www.fresha.com/a/dead-venue');

    DB::table('site.platform_connections')->where('id', $deadConn)->update(['deleted_at' => now()]);

    $after = app(PoolResolver::class)->resolve($site, 'watch')['selection'][0];

    // Before #LIFE-4 the disconnected platform's url kept publishing as the
    // item's primary link, because the item stayed alive on the OTHER source.
    expect($after['url'])->toBe('https://www.instagram.com/p/live');
});

it('emits publishedAt and firstSeenAt as ISO-8601 with an explicit zone, not naive local time (API-1)', function () {
    [$pro, $siteId] = poolTenant();
    $site = Site::findOrFail($siteId);

    $source = poolSource($pro->id, poolConnection($pro->id, 'instagram.profile'));
    $item = poolItem($pro->id, $source, 'video', 'A video', '2026-08-01T09:30:00Z');
    poolPin($siteId, 'watch', $item);

    $row = app(PoolResolver::class)->resolve($site, 'watch')['selection'][0];

    // The defect: a naive "2026-08-01 09:30:00" is read by a browser's Date()
    // as LOCAL time — a silent +10h error for an AEST reader, with no parse
    // failure to notice it by.
    expect($row['publishedAt'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
    expect($row['firstSeenAt'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');

    // Rendered in UTC, not merely "some offset". latestFor() compares these as
    // STRINGS in a tuple, so a mixed-offset payload would sort a later instant
    // before an earlier one.
    expect($row['publishedAt'])->toEndWith('+00:00')
        ->and($row['firstSeenAt'])->toEndWith('+00:00');

    // Same instant, not merely a reformatted one.
    expect(Carbon\Carbon::parse($row['publishedAt'])->equalTo(Carbon\Carbon::parse('2026-08-01T09:30:00Z')))->toBeTrue();
});

it('leaves an unparseable manual override as null rather than emitting it to the wire', function () {
    [$pro, $siteId] = poolTenant();
    $site = Site::findOrFail($siteId);

    $source = poolSource($pro->id, poolConnection($pro->id, 'instagram.profile'));
    $item = poolItem($pro->id, $source, 'video', 'A video', '2026-08-01T09:30:00Z');
    poolPin($siteId, 'watch', $item);

    DB::table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $item,
        'facet' => 'f_published', 'column_name' => 'published_from',
        'value' => json_encode('not a date at all'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Not the raw string, and not an exception either: an overridden date that
    // will not parse means we have no date, and saying so is better than
    // publishing something a client cannot read.
    expect(app(PoolResolver::class)->resolve($site, 'watch')['selection'][0]['publishedAt'])->toBeNull();
});

it('emits the nested review.reviewedAt as ISO-8601 too, not just the top-level dates (API-1, review follow-up)', function () {
    // Missed on the first pass and found by review. `review` is a PUBLIC wire
    // field (ITEM_KEYS, not dashboard-only) and content.f_review.reviewed_at is
    // timestamptz, so on Postgres the driver hands back
    // "2026-07-01 10:00:00+00" — space separator, colon-less offset, and a
    // rendering that shifts with the session TimeZone.
    //
    // This assertion still cannot see that difference: SQLite returns the
    // seeded string verbatim, which is exactly why the pre-existing
    // ReviewsPoolTest assertion passed while the field was unconverted. What it
    // CAN prove is that the value now goes through iso() at all — the format is
    // normalised rather than passed through — and that is enough to catch a
    // regression that drops the conversion.
    [$pro, $siteId] = poolBusinessTenant();
    $site = Site::findOrFail($siteId);

    $source = poolSource($pro->id, poolConnection($pro->id, 'google.business'));
    $item = poolItem($pro->id, $source, 'review', 'A review', '2026-08-01T00:00:00Z');
    poolPin($siteId, 'reviews', $item);

    DB::table('content.f_review')->insert([
        'item_id' => $item, 'source_id' => $source,
        'rating' => 5, 'text' => 'Excellent service.',
        // Seeded WITHOUT a zone marker — the naive shape the query builder
        // hands back, and the one a browser reads as local time.
        'reviewed_at' => '2026-07-01 10:00:00',
        'updated_at' => now(),
    ]);

    $row = app(PoolResolver::class)->resolve($site, 'reviews')['selection'][0];

    expect($row['review']['reviewedAt'])->toBe('2026-07-01T10:00:00+00:00');
});

it('does not publish a PAUSED connection as the item\'s fallback platform/url (LIFE-3)', function () {
    // The residual half of #LIFE-3, left open when the first pass fixed
    // statsFor()/$sourceLinks/$offerLinks. $sourceRows deliberately KEEPS a
    // paused connection — the item sheet's Sources list badges it
    // `active: false`, and the owner needs to see the source they paused. But
    // $sourcePlatforms reads that same row set to supply an item's PUBLIC
    // fallback platform/url, and filtered only on source_kind/connection_id.
    //
    // Reachable exactly when the item has no link of its own. The #LIFE-4 fix
    // makes that MORE common, not less: dropping a paused source's f_link is
    // precisely what leaves $primary null and falls through to here.
    [$pro, $siteId] = poolTenant();
    $site = Site::findOrFail($siteId);

    // A Fresha-style venue: carries the item, but has no per-service page of
    // its own, so no f_link. This is the real shape the fallback exists for.
    $liveConn = poolConnection($pro->id, 'fresha.venue');
    $liveSource = poolSource($pro->id, $liveConn);
    $pausedConn = poolConnection($pro->id, 'google.business');
    $pausedSource = poolSource($pro->id, $pausedConn);

    // `platform` is a GENERATED column off surface_key — fresha.venue -> fresha,
    // google.business -> google. Set the payload only; writing platform errors.
    DB::table('site.platform_connections')->where('id', $liveConn)->update([
        'payload' => json_encode(['url' => 'https://www.fresha.com/a/live-venue']),
    ]);
    DB::table('site.platform_connections')->where('id', $pausedConn)->update([
        'payload' => json_encode(['url' => 'https://maps.google.com/paused-listing']),
    ]);
    // Higher priority, so ->first() picks it — what makes this observable.
    DB::table('content.sources')->where('id', $pausedSource)->update(['priority' => 500]);

    $item = poolItem($pro->id, $liveSource, 'video', 'A video', '2026-08-01T00:00:00Z');
    alsoCarriedBy($item, $pausedSource, 'video');
    poolPin($siteId, 'watch', $item);

    // Both connected: Google legitimately supplies the fallback.
    expect(app(PoolResolver::class)->resolve($site, 'watch')['selection'][0]['url'])
        ->toBe('https://maps.google.com/paused-listing');

    // The owner PAUSES Google — not deletes it.
    DB::table('site.platform_connections')->where('id', $pausedConn)->update(['is_active' => 0]);

    $after = app(PoolResolver::class)->resolve($site, 'watch')['selection'][0];

    // Owner ruling 2026-08-19: pause = hide, the same reading LiveSourceScope
    // takes on the six surfaces that already had it.
    expect($after['url'])->toBe('https://www.fresha.com/a/live-venue')
        ->and($after['platform'])->not->toBe('google');
});

it('still shows a paused connection in the item sheet Sources list, badged inactive (LIFE-3 bound)', function () {
    // The other half of the ruling, and the reason the filter sits on
    // $sourcePlatforms rather than on $sourceRows: hiding a paused source from
    // the PUBLIC fallback must not hide it from the OWNER's own source list.
    //
    // The item needs a LIVE source to survive at all — with only the paused one
    // it is hidden outright, which is the ruling working correctly and not what
    // this test is about.
    [$pro, $siteId] = poolBusinessTenant();
    $site = Site::findOrFail($siteId);

    $liveConn = poolConnection($pro->id, 'instagram.profile');
    $liveSource = poolSource($pro->id, $liveConn);
    $pausedConn = poolConnection($pro->id, 'google.business');
    $pausedSource = poolSource($pro->id, $pausedConn);

    $item = poolItem($pro->id, $liveSource, 'video', 'A video', '2026-08-01T00:00:00Z');
    alsoCarriedBy($item, $pausedSource, 'video');
    poolPin($siteId, 'watch', $item);

    DB::table('site.platform_connections')->where('id', $pausedConn)->update(['is_active' => 0]);

    $sources = app(PoolResolver::class)->resolve($site, 'watch')['selection'][0]['sources'] ?? [];
    $byPlatform = collect($sources)->keyBy('platform');

    // Both listed. The paused one carries active:false so the dashboard can say
    // WHY it stopped syncing, instead of the source silently vanishing.
    expect($sources)->toHaveCount(2)
        ->and($byPlatform['google']['active'])->toBeFalse()
        ->and($byPlatform['instagram']['active'])->toBeTrue();
});
