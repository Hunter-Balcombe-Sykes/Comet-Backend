<?php

use App\Models\Core\Site\Site;
use App\Services\Accounts\AccountCapabilities;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// ── The other half of the 2026-09-01 incident ───────────────────────────────
// The person scope fixed the review CARDS and left the badge above them
// publishing the VENUE. ollies is a partna account — one barista, Raff, at a
// coffee shop — and it carries three content.source_stats rows tonight:
//
//   google-business (retired 08-26 02:42)   4.2 / 3919   stats @ 08-26 02:41
//   google-business (live)                  4.2 / 3925   stats @ 08-29 20:45
//   fresha          (live)                  5.0 /  174   stats @ 08-31 08:45
//
// The fresha connection is a HAIR SALON's venue page (ingest selection_ref
// 5035183, vision-hair-studio-melbourne), and it is the one whose review
// survived the person scope. statsFor() reaches a stats row THROUGH the
// selection, so the coffee shop published "5/5 — Based on 174 reviews": a hair
// salon's badge, beside one review, on one barista's page.
//
// The owner rule the person scope was decided under, carried up to the
// aggregate: an aggregate over reviews that are not this person's is not this
// person's aggregate. 3,925 is as wrong as 174 — a count of other people's
// reviews is meaningless beside one shown review even when the venue is right.
// So a person-scoped page publishes no venue aggregate at all, and the only
// number it may show is the one computed over the reviews it actually
// published — which the reader can check by counting the cards.
//
// The second half of this file is the tie-break between stats rows, which the
// same page made visible: `orderByDesc(rating_count)` alone was neither total
// nor safe against a row that carries a count and no average.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    setupMediaTables();
    setupIngestTables();
    Queue::fake();
});

/**
 * A partna tenant with the name pair on file, ready to be handed reviews.
 *
 * @return array{object, string}
 */
function rsPerson(?string $displayName, ?string $firstName): array
{
    [$pro, $siteId] = poolTenant();
    DB::table('core.users')->where('id', $pro->id)->update([
        'display_name' => $displayName,
        'first_name' => $firstName,
    ]);
    AccountCapabilities::flushCache();

    return [$pro, $siteId];
}

/** One landed review item on $sourceId. $facet overrides the f_review row. */
function rsReview(string $userId, string $sourceId, array $facet = []): string
{
    $itemId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $userId, 'kind' => 'review',
        'headline_cache' => null, 'facets_cache' => '["f_review"]',
        'first_seen_at' => now()->subDay(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'review:'.Str::random(10), 'item_id' => $itemId, 'kind' => 'review',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.f_review')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'author_name' => null, 'author_photo_url' => null, 'author_uri' => null,
        'rating' => 5.0, 'text' => null, 'reviewed_at' => null, 'staff_name' => null,
        ...$facet,
        'updated_at' => now(),
    ]);

    return $itemId;
}

/** Bind an already-landed item to a SECOND source, as a two-listing item is. */
function rsAlsoCarriedBy(string $itemId, string $sourceId): void
{
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'review:'.Str::random(10), 'item_id' => $itemId, 'kind' => 'review',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
}

/** The venue-level aggregate a connected place lands on its source. */
function rsStats(string $sourceId, ?float $avg, ?int $count, ?string $summary, ?string $at = null): void
{
    DB::table('content.source_stats')->insert([
        'source_id' => $sourceId, 'rating_avg' => $avg, 'rating_count' => $count,
        'summary_text' => $summary, 'updated_at' => $at ?? (string) now(),
    ]);
}

/** Marks $connectionId as scoped to a single team member at the vendor. */
function rsEmployeeScoped(string $userId, string $connectionId): void
{
    DB::table('ingest.sources')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId,
        'connection_id' => $connectionId, 'source_key' => 'fresha',
        'surface_key' => 'fresha.book', 'identifier' => 'vision-hair-studio-melbourne-tzo6gxk0',
        'selection_ref' => '5035183', 'cost_units' => 1,
        'min_interval_secs' => 3600, 'max_interval_secs' => 604800,
        'auto_sync' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return array{ratingAvg: ?float, ratingCount: ?int, summaryText: ?string}|null */
function rsBadge(string $siteId): ?array
{
    return app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews')['stats'];
}

// ── The venue aggregate on a person's page ──────────────────────────────────

it('stops a coffee shop publishing the hair salon\'s five-star badge', function () {
    // ollies, verbatim. Raff's page, the salon's Fresha listing, and the
    // coffee shop's own Google listing hanging off the same account.
    [$pro, $siteId] = rsPerson('ST. ALi Coffee', 'Raff');

    $fresha = poolConnection($pro->id, 'fresha.book');
    $freshaSource = poolSource($pro->id, $fresha);
    rsEmployeeScoped($pro->id, $fresha);
    rsStats($freshaSource, 5.0, 174, 'Guests love the colour work.');

    $googleSource = poolSource($pro->id, poolConnection($pro->id, 'google_business.listing'));
    rsStats($googleSource, 4.2, 3925, 'People mention the coffee and the pastries.');

    // The one review that survives the person scope: unattributed, off a
    // source the vendor already narrowed to this person.
    $mine = rsReview($pro->id, $freshaSource, ['rating' => 5.0, 'text' => 'Best cut in Melbourne.']);
    // The venue's own, which the person scope suppresses.
    rsReview($pro->id, $googleSource, ['rating' => 1.0, 'text' => 'Bad capuchino , no taste of coffee at all .']);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    expect(array_column($resolved['selection'], 'id'))->toBe([$mine]);

    // Neither venue's aggregate. One published review, rated 5 — a number the
    // reader can check by counting the cards under it.
    expect($resolved['stats'])->toBe([
        'ratingAvg' => 5.0,
        'ratingCount' => 1,
        'summaryText' => null,
    ]);
});

it('averages the reviews it published, not the ones it suppressed', function () {
    // Without this the fix is indistinguishable from "copy whatever f_review
    // rating is lying around": three of these are on the page and one is not.
    [$pro, $siteId] = rsPerson('Emma Dinon', 'Emma');

    $source = poolSource($pro->id, poolConnection($pro->id, 'google_business.listing'));
    rsStats($source, 4.2, 3925, 'People mention the coffee and the pastries.');

    rsReview($pro->id, $source, ['rating' => 5.0, 'text' => 'Emma was fantastic — best colour I have ever had.']);
    rsReview($pro->id, $source, ['rating' => 4.0, 'text' => 'Emma took her time and got it right.']);
    rsReview($pro->id, $source, ['rating' => 3.0, 'text' => 'Emma is lovely but the wait was long.']);
    // Suppressed: the venue's, and a 1 that would drag the average to 3.25.
    rsReview($pro->id, $source, ['rating' => 1.0, 'text' => 'Bad capuchino , no taste of coffee at all .']);

    expect(rsBadge($siteId))->toBe([
        'ratingAvg' => 4.0,
        'ratingCount' => 3,
        'summaryText' => null,
    ]);
});

it('withholds Google\'s prose about the venue from a person\'s page', function () {
    // summary_text is Google-authored prose about the BUSINESS, derived from
    // the whole review corpus. There is no person-scoped version of it to
    // compute, so a person's page carries none.
    [$pro, $siteId] = rsPerson('Emma Dinon', 'Emma');

    $source = poolSource($pro->id, poolConnection($pro->id, 'google_business.listing'));
    rsStats($source, 4.2, 3925, 'Guests mention the flat whites, the sourdough and the courtyard.');
    rsReview($pro->id, $source, ['rating' => 5.0, 'text' => 'Emma was fantastic.']);

    expect(rsBadge($siteId)['summaryText'])->toBeNull();
});

it('shows no rating when no published review carries one', function () {
    // Null, not 0.0 — f_review.rating's bare-cast trap in aggregate form. A
    // Fresha review with no score must not publish a zero-star person.
    [$pro, $siteId] = rsPerson('Emma Dinon', 'Emma');

    $source = poolSource($pro->id, poolConnection($pro->id, 'google_business.listing'));
    rsStats($source, 4.2, 3925, 'People mention the coffee.');
    rsReview($pro->id, $source, ['rating' => null, 'text' => 'Emma was fantastic.']);

    expect(rsBadge($siteId))->toBeNull();
});

it('leaves a business account\'s venue aggregate exactly as it was', function () {
    // The venue's reviews ARE its reviews, and so is the count of them. This
    // is the behaviour the person branch must not reach.
    [$pro, $siteId] = rsPerson('ST. ALi Coffee', 'Raff');
    DB::table('core.users')->where('id', $pro->id)->update(['account_type' => 'business']);
    AccountCapabilities::flushCache();

    $source = poolSource($pro->id, poolConnection($pro->id, 'google_business.listing'));
    rsStats($source, 4.2, 3925, 'People mention the coffee and the pastries.');
    rsReview($pro->id, $source, ['rating' => 1.0, 'text' => 'Bad capuchino , no taste of coffee at all .']);

    expect(rsBadge($siteId))->toBe([
        'ratingAvg' => 4.2,
        'ratingCount' => 3925,
        'summaryText' => 'People mention the coffee and the pastries.',
    ]);
});

// ── The tie-break between stats rows ────────────────────────────────────────

it('does not let a countable row with no average shadow the real listing', function () {
    // ProjectionWriter writes EVERY source_stats column on every run, so a run
    // that carried place_rating_count but no place_rating leaves rating_avg
    // NULL beside a real count. Ordered on rating_count alone that row wins,
    // and the badge goes blank while a 4.2 sits one row away: the consumer
    // renders nothing when rating is null (`if (gb?.rating == null) return
    // null`). A row that cannot produce a badge is not a candidate for it.
    [$pro, $siteId] = rsPerson('ST. ALi Coffee', 'Raff');
    DB::table('core.users')->where('id', $pro->id)->update(['account_type' => 'business']);
    AccountCapabilities::flushCache();

    $blank = poolSource($pro->id, poolConnection($pro->id, 'fresha.book'));
    $google = poolSource($pro->id, poolConnection($pro->id, 'google_business.listing'));

    $review = rsReview($pro->id, $google, ['rating' => 4.0, 'text' => 'Good coffee.']);
    rsAlsoCarriedBy($review, $blank);

    rsStats($blank, null, 9999, 'Prose with no score attached.');
    rsStats($google, 4.2, 3925, 'People mention the coffee and the pastries.');

    expect(rsBadge($siteId))->toBe([
        'ratingAvg' => 4.2,
        'ratingCount' => 3925,
        'summaryText' => 'People mention the coffee and the pastries.',
    ]);
});

it('picks the fresher of two rows for the same place', function () {
    // ollies holds two google-business connections for place_id
    // ChIJJ5bS6P9n1moRx76U3LjtN1A — the 02:40:59 one retired 91 seconds later,
    // the 02:43:07 one live — and therefore two stats rows for one coffee
    // shop. On any day the venue's count has not moved they are identical
    // except for updated_at, and rating_count alone leaves the winner to
    // whatever order the engine happened to return. Freshest is the current
    // truth about the place; the stale row is inserted FIRST here because that
    // is the order that used to decide it.
    [$pro, $siteId] = rsPerson('ST. ALi Coffee', 'Raff');
    DB::table('core.users')->where('id', $pro->id)->update(['account_type' => 'business']);
    AccountCapabilities::flushCache();

    $first = poolSource($pro->id, poolConnection($pro->id, 'google_business.listing'));
    $second = poolSource($pro->id, poolConnection($pro->id, 'google_business.listing'));

    $review = rsReview($pro->id, $first, ['rating' => 4.0, 'text' => 'Good coffee.']);
    rsAlsoCarriedBy($review, $second);

    rsStats($first, 4.2, 3925, 'The older read of the same place.', '2026-08-26 02:41:00');
    rsStats($second, 4.2, 3925, 'The current read of the same place.', '2026-08-29 20:45:17');

    expect(rsBadge($siteId)['summaryText'])->toBe('The current read of the same place.');
});

it('keeps the retired half of a duplicated listing out of the badge', function () {
    // The same two connections, with the counts they really carry: the retired
    // one read 3,919 on 08-26 and the live one 3,925 on 08-29. Disconnect =
    // hide (#LIFE-2) has to hold whichever way the numbers point, so the
    // retired row is given the bigger count here — the arrangement where the
    // ordering would pick it if liveness were not filtering first.
    [$pro, $siteId] = rsPerson('ST. ALi Coffee', 'Raff');
    DB::table('core.users')->where('id', $pro->id)->update(['account_type' => 'business']);
    AccountCapabilities::flushCache();

    $retiredConn = poolConnection($pro->id, 'google_business.listing');
    $retired = poolSource($pro->id, $retiredConn);
    $live = poolSource($pro->id, poolConnection($pro->id, 'google_business.listing'));

    $review = rsReview($pro->id, $live, ['rating' => 4.0, 'text' => 'Good coffee.']);
    rsAlsoCarriedBy($review, $retired);

    rsStats($retired, 4.9, 9999, 'The listing the owner disconnected.', '2026-08-30 00:00:00');
    rsStats($live, 4.2, 3925, 'People mention the coffee and the pastries.', '2026-08-29 20:45:17');

    DB::table('site.platform_connections')->where('id', $retiredConn)->update(['deleted_at' => now()]);

    expect(rsBadge($siteId))->toBe([
        'ratingAvg' => 4.2,
        'ratingCount' => 3925,
        'summaryText' => 'People mention the coffee and the pastries.',
    ]);
});
