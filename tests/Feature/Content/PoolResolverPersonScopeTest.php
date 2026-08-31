<?php

use App\Models\Core\Site\Site;
use App\Services\Accounts\AccountCapabilities;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// ── The incident (2026-09-01) ───────────────────────────────────────────────
// We were publishing other people's reviews on real people's pages. Three
// independent holes, all in reviewsOutsidePersonScope(), all reproduced here
// with the strings that were live:
//
//   1. The name match meant nothing. broken-oven's first_name is "The Broken
//      Oven Pizza Bar", so the needle was "the" and seven of the venue's
//      eleven Google reviews matched — a 1-star about a cappuccino, praise
//      for a barber called Shuki, praise for a stylist called Sayuri.
//   2. An employee-scoped source skipped the facet read entirely, so a review
//      whose own staff attribution named a different person could not veto
//      itself. ollies published "Ciel was amazing" on Raff McGuiness's page.
//   3. An unresolvable owner returned an EMPTY suppression map — everything
//      passes — while the docblock two lines above claimed the opposite.
//
// The owner rule these assert: a review belongs on a person's page only if it
// MENTIONS THEM BY NAME.

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
 * A partna tenant with the venue's name pair on file and $reviews landed from
 * a venue-level Google listing.
 *
 * @param  list<array<string, mixed>>  $reviews
 * @return array{object, string, list<string>, string}
 */
function personScopeFixture(?string $displayName, ?string $firstName, array $reviews): array
{
    [$pro, $siteId] = poolTenant();
    DB::table('core.users')->where('id', $pro->id)->update([
        'display_name' => $displayName,
        'first_name' => $firstName,
    ]);
    AccountCapabilities::flushCache();

    $connectionId = poolConnection($pro->id, 'google_business.listing');
    $sourceId = poolSource($pro->id, $connectionId);

    $itemIds = [];
    foreach ($reviews as $review) {
        $itemId = (string) Str::uuid();
        $itemIds[] = $itemId;
        DB::table('content.items')->insert([
            'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'review',
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
            ...$review,
            'updated_at' => now(),
        ]);
    }

    return [$pro, $siteId, $itemIds, $connectionId];
}

/** Marks $connectionId as scoped to a single team member at the vendor. */
function personScopeEmployeeSource(string $userId, string $connectionId, string $selectionRef = '5035183'): void
{
    DB::table('ingest.sources')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId,
        'connection_id' => $connectionId, 'source_key' => 'fresha',
        'surface_key' => 'fresha.book', 'identifier' => 'vision-hair-studio-melbourne-tzo6gxk0',
        'selection_ref' => $selectionRef, 'cost_units' => 1,
        'min_interval_secs' => 3600, 'max_interval_secs' => 604800,
        'auto_sync' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return list<string> the ids the reviews pool would publish */
function personScopePublished(string $siteId): array
{
    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    return array_map(static fn (array $item): string => (string) $item['id'], $resolved['selection']);
}

// ── Hole 1: the name match meant nothing ────────────────────────────────────

it('publishes none of broken-oven\'s seven live reviews on its owner\'s page', function () {
    // Verbatim from content.f_review tonight. Every one of these was on a
    // named individual's page an hour ago.
    [, $siteId, $itemIds] = personScopeFixture('Lower East by', 'The Broken Oven Pizza Bar', [
        ['text' => 'Absolutely loved my breakfast here! I ordered an extra-hot cappuccino along with bacon and eggs everything was perfect. The coffee came out piping hot just the way I like it, and the bacon and eggs were cooked beautifully.'],
        ['text' => 'Absolutely everything we tried was sensational!!! The presentation, the flavours, the quality of the food! The service was also fantastic.'],
        ['text' => 'This is my first ever google review because we were so impressed by this place. Delicious food (always appreciate an all day menu because I love breakfast food).'],
        ['text' => 'Moved into the area about a month ago and discovered this little gem doing big things. A really creative and extensive menu.'],
        ['rating' => 1.0, 'text' => 'Bad capuchino , no taste of coffee at all . I am Sorry to have to write this because I normally like this coffee place in the Mall But in this other location my capuchino was just warm milk.'],
        ['staff_name' => 'Shuki', 'text' => 'Shuki did a fantastic job today. Keep up the great work, and thanks for your service!'],
        ['staff_name' => 'Sayuri', 'text' => 'Really loved my visit! Sayuri is incredibly talented, and the salon has such a calm and relaxing atmosphere.'],
    ]);

    expect(personScopePublished($siteId))->toBe([])
        ->and($itemIds)->toHaveCount(7)
        // The nav probe has to agree, or the page is advertised over an empty pool.
        ->and(app(PoolResolver::class)->hasSelection(Site::query()->findOrFail($siteId), 'reviews'))->toBeFalse();
});

it('still admits the review the owner rule exists to admit', function () {
    // The true positive. Without this the fix is just "publish nothing".
    [, $siteId, $itemIds] = personScopeFixture('Emma Dinon', 'Emma', [
        ['text' => 'Emma was fantastic — best colour I have ever had.'],
        ['text' => 'The salon is lovely and the coffee is great.'],
    ]);

    expect(personScopePublished($siteId))->toBe([$itemIds[0]])
        ->and(app(PoolResolver::class)->hasSelection(Site::query()->findOrFail($siteId), 'reviews'))->toBeTrue();
});

// ── Hole 2: the employee-scoped bypass ──────────────────────────────────────

it('lets a staff attribution naming someone else veto an employee-scoped source', function () {
    // ollies, live tonight: a Fresha source with selection_ref 5035183 hangs
    // off the account, and "Ciel was amazing" rode it onto Raff's page
    // because the employee-scoped branch never read the facet.
    [$pro, $siteId, $itemIds, $connectionId] = personScopeFixture('ST. ALi Coffee', 'Raff', [
        ['staff_name' => 'Ciel', 'text' => 'Ciel was amazing. Very helpful, accommodating of my young daughter and gave me a quick and professional service which suited my needs exactly.'],
    ]);
    personScopeEmployeeSource($pro->id, $connectionId);

    expect(personScopePublished($siteId))->toBe([])
        ->and($itemIds)->toHaveCount(1);
});

it('keeps an unattributed review from an employee-scoped source', function () {
    // The tier must still work: the vendor already filtered to this person, so
    // an unattributed review it landed is theirs even with no name in the text.
    [$pro, $siteId, $itemIds, $connectionId] = personScopeFixture('Raff McGuiness', 'Raff', [
        ['text' => 'Best cut in Melbourne, could not be happier.'],
    ]);
    personScopeEmployeeSource($pro->id, $connectionId);

    expect(personScopePublished($siteId))->toBe($itemIds);
});

it('lets a staff attribution naming someone else veto a text mention too', function () {
    // "Raff made the coffee while Ciel did my hair" with staff_name Ciel is
    // Ciel's review. The vendor's structured answer outranks our text guess.
    [, $siteId] = personScopeFixture('Raff McGuiness', 'Raff', [
        ['staff_name' => 'Ciel', 'text' => 'Ciel did my hair and Raff made the coffee.'],
    ]);

    expect(personScopePublished($siteId))->toBe([]);
});

// ── Hole 3: fail open on an unresolvable owner ──────────────────────────────

it('suppresses every review when the owner cannot be resolved', function () {
    // The docblock said "fail closed" and the code returned the empty map,
    // which means "publish everything".
    [$pro, $siteId, $itemIds] = personScopeFixture('Emma Dinon', 'Emma', [
        ['text' => 'Emma was fantastic — best colour I have ever had.'],
    ]);
    expect(personScopePublished($siteId))->toBe($itemIds);

    // The owner row goes away underneath the still-live site row.
    DB::table('core.users')->where('id', $pro->id)->delete();
    AccountCapabilities::flushCache();

    expect(personScopePublished($siteId))->toBe([])
        ->and(app(PoolResolver::class)->hasSelection(Site::query()->findOrFail($siteId), 'reviews'))->toBeFalse();
});

it('suppresses a venue review with no text and no attribution', function () {
    // A review that cannot mention anyone by name is not attributable to
    // anyone; "no usable text" is an uncertainty like any other.
    [, $siteId] = personScopeFixture('Emma Dinon', 'Emma', [
        ['text' => null],
        ['text' => ''],
    ]);

    expect(personScopePublished($siteId))->toBe([]);
});

// ── The business account keeps the venue behaviour ──────────────────────────

it('leaves a business account\'s venue reviews alone', function () {
    [$pro, $siteId, $itemIds] = personScopeFixture('Lower East by', 'The Broken Oven Pizza Bar', [
        ['rating' => 1.0, 'text' => 'Bad capuchino , no taste of coffee at all .'],
    ]);
    DB::table('core.users')->where('id', $pro->id)->update(['account_type' => 'business']);
    AccountCapabilities::flushCache();

    expect(personScopePublished($siteId))->toBe($itemIds);
});
