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

/**
 * Marks $connectionId as scoped to a single team member at the vendor.
 *
 * TWO selections, because they are two different facts and conflating them is
 * the blocker (2026-09-01): $ingestedUnder is what was in force when the
 * connector landed these reviews — stamped on content.source_items by
 * ProjectionWriter — and $currentlySetTo is what ingest.sources says the
 * source is scoped to NOW. They diverge for real whenever an owner narrows or
 * widens a Fresha connection, and the person-scope gate must read the first.
 */
function personScopeEmployeeSource(
    string $userId,
    string $connectionId,
    string $ingestedUnder = '5035183',
    ?string $currentlySetTo = null,
): void {
    DB::table('ingest.sources')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId,
        'connection_id' => $connectionId, 'source_key' => 'fresha',
        'surface_key' => 'fresha.book', 'identifier' => 'vision-hair-studio-melbourne-tzo6gxk0',
        'selection_ref' => $currentlySetTo ?? $ingestedUnder, 'cost_units' => 1,
        'min_interval_secs' => 3600, 'max_interval_secs' => 604800,
        'auto_sync' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('content.source_items')
        ->whereIn('source_id', DB::table('content.sources')->where('connection_id', $connectionId)->pluck('id'))
        ->update(['ingest_selection_ref' => $ingestedUnder]);
}

/**
 * A SECOND vendor carrying the same review item, the way a deduped review
 * really sits in the database: its own content.sources row on its own
 * connection, its own source_items link, and — because content.f_review is
 * keyed (item_id, source_id) — its own f_review row. $updatedAt decides which
 * row an `orderBy(updated_at)` leaves last.
 *
 * @param  array<string, mixed>  $review
 */
function personScopeSecondSource(string $userId, string $itemId, array $review, string $updatedAt): string
{
    $connectionId = poolConnection($userId, 'fresha.book');
    $sourceId = poolSource($userId, $connectionId);
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
        'updated_at' => $updatedAt,
    ]);

    return $connectionId;
}

/** @return list<string> the ids the reviews pool would publish */
function personScopePublished(string $siteId): array
{
    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    return array_map(static fn (array $item): string => (string) $item['id'], $resolved['selection']);
}

/**
 * The review CARDS the pool would publish — the prose a visitor actually
 * reads, which is the half every earlier pin was blind to.
 *
 * @return list<?string>
 */
function personScopeCards(string $siteId): array
{
    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');

    return array_map(static fn (array $item): ?string => $item['review']['text'] ?? null, $resolved['selection']);
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

it('does not treat a storewide Fresha connection as employee-scoped', function () {
    // The employee-scope gate is the ONE tier that admits a review carrying no
    // name evidence whatsoever — no staff attribution, no mention in the prose
    // — purely on the vendor's word that the feed was already narrowed to this
    // person. So 'storewide' has to be excluded from selection_ref as firmly
    // as the empty string is: vision-hair-studio-melbourne-tzo6gxk0 storewide
    // is the WHOLE salon, which is the venue-level case this scope exists to
    // suppress. Its sibling above (selection_ref 5035183, the same review,
    // published) pins the other direction so the gate cannot be "fixed" shut.
    [$pro, $siteId, , $connectionId] = personScopeFixture('Raff McGuiness', 'Raff', [
        ['text' => 'Best cut in Melbourne, could not be happier.'],
    ]);
    personScopeEmployeeSource($pro->id, $connectionId, 'storewide');

    expect(personScopePublished($siteId))->toBe([]);
});

it('lets a staff attribution naming someone else veto a text mention too', function () {
    // "Raff made the coffee while Ciel did my hair" with staff_name Ciel is
    // Ciel's review. The vendor's structured answer outranks our text guess.
    [, $siteId] = personScopeFixture('Raff McGuiness', 'Raff', [
        ['staff_name' => 'Ciel', 'text' => 'Ciel did my hair and Raff made the coffee.'],
    ]);

    expect(personScopePublished($siteId))->toBe([]);
});

// ── Hole 2b: the veto saw ONE facet row per item ────────────────────────────

it('lets a staff attribution on ANY facet row veto a deduped review', function (bool $attributionIsNewer) {
    // BLOCKER (2026-09-01, second pass). content.f_review is keyed
    // (item_id, source_id). ollies' hair reviews arrive from TWO vendors — the
    // Google listing and the Fresha page vision-hair-studio-melbourne-tzo6gxk0
    // — and dedupe to one content.items row, so the item carries two facet
    // rows. reviewsOutsidePersonScope() read them with keyBy('item_id'), which
    // keeps whichever row the ordering left last and discards the other. The
    // Fresha row is the one carrying staff_name "Ciel"; on every run where the
    // Google row won, the veto never saw the attribution and "Ciel" published
    // on Raff McGuiness's page exactly as before.
    //
    // Two orderings, because "which row wins" must stop being a question the
    // answer depends on. Both must suppress.
    [$pro, $siteId, $itemIds, $googleConnectionId] = personScopeFixture('ST. ALi Coffee', 'Raff', [
        ['text' => 'Raff made the coffee while Ciel did my hair, both were lovely.'],
    ]);
    $freshaConnectionId = personScopeSecondSource($pro->id, $itemIds[0], [
        'staff_name' => 'Ciel',
        'text' => 'Raff made the coffee while Ciel did my hair, both were lovely.',
    ], (string) ($attributionIsNewer ? now()->addDay() : now()->subDay()));

    // Both live admissions are open under it: the text names Raff, and the
    // Fresha source is employee-scoped at the vendor. The veto has to outrank
    // both, from whichever row happens to carry it.
    personScopeEmployeeSource($pro->id, $freshaConnectionId);

    expect(personScopePublished($siteId))->toBe([])
        ->and($googleConnectionId)->not->toBe($freshaConnectionId)
        ->and(app(PoolResolver::class)->hasSelection(Site::query()->findOrFail($siteId), 'reviews'))->toBeFalse();
})->with([
    'attribution on the row orderBy discards' => false,
    'attribution on the row orderBy keeps' => true,
]);

it('still publishes a deduped review both facet rows attribute to the owner', function () {
    // The other direction, or the fix above is just "suppress anything with
    // two sources". Same two-row shape, and Fresha names Raff.
    [$pro, $siteId, $itemIds] = personScopeFixture('Raff McGuiness', 'Raff', [
        ['text' => 'Best cut in Melbourne, could not be happier.'],
    ]);
    personScopeSecondSource($pro->id, $itemIds[0], [
        'staff_name' => 'Raff',
        'text' => 'Best cut in Melbourne, could not be happier.',
    ], (string) now()->subDay());

    expect(personScopePublished($siteId))->toBe($itemIds);
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

// ── THE ROOT CAUSE: admission and publication read different rows ───────────
//
// Three waves of guards produced three more blockers because every one of them
// reasoned about a row the visitor was not looking at.
// reviewsOutsidePersonScope() read content.f_review orderBy(updated_at) and
// admitted on the FIRST row whose prose named the owner; itemPayloads() read
// the same rows orderBy(updated_at)->keyBy(item_id), which keeps the LAST. The
// PK is (item_id, source_id), so a deduped review has two rows and the two
// readings were about different ones.
//
// These pin the PROPERTY — the row that justifies admission is the row that
// publishes — rather than pinning two call sites that happen to agree today.

it('decides a deduped review on the copy that will actually be published', function (bool $namingCopyPublishes) {
    // The divergence, in the shape it really has: one review, two vendors, two
    // wordings. The Google copy names Raff; the Fresha copy — the same visit,
    // as Fresha renders it — names nobody. Under the split reading the naming
    // copy admitted the item and the OTHER one went on the page: a venue
    // review published on a named person's page, admitted by a sentence no
    // visitor could see.
    [$pro, $siteId, $itemIds] = personScopeFixture('Raff McGuiness', 'Raff', [
        ['text' => 'Raff was wonderful, best cut in Melbourne.'],
    ]);
    personScopeSecondSource($pro->id, $itemIds[0], [
        'text' => 'Great service today, thanks!',
    ], (string) ($namingCopyPublishes ? now()->subDay() : now()->addDay()));

    if ($namingCopyPublishes) {
        // Admitted, and admitted on the words that are on the card.
        expect(personScopePublished($siteId))->toBe($itemIds)
            ->and(personScopeCards($siteId))->toBe(['Raff was wonderful, best cut in Melbourne.']);
    } else {
        // The card would have said "Great service today, thanks!" — which
        // claims nothing about Raff — so there is no admission to make.
        expect(personScopePublished($siteId))->toBe([])
            ->and(app(PoolResolver::class)->hasSelection(Site::query()->findOrFail($siteId), 'reviews'))->toBeFalse();
    }
})->with([
    'the naming copy is the one that renders' => true,
    'the silent copy is the one that renders' => false,
]);

it('reads content.f_review once per pool read, so the two answers are about the same rows', function () {
    // The structural half, and the one that survives a rewrite: as long as
    // there is exactly ONE read, admission and publication have nothing left
    // to disagree about. A second query here is the regression — it is how the
    // last three fixes each shipped a guard over rows the payload never saw.
    [, $siteId] = personScopeFixture('Raff McGuiness', 'Raff', [
        ['text' => 'Raff was wonderful.'],
        ['text' => 'The salon is lovely.'],
    ]);

    $reads = 0;
    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'f_review')) {
            $reads++;
        }
    });

    app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');
    expect($reads)->toBe(1);

    // And the nav probe, which decides the same question without rendering.
    $reads = 0;
    app(PoolResolver::class)->hasSelection(Site::query()->findOrFail($siteId), 'reviews');
    expect($reads)->toBe(1);
});

it('publishes the freshest copy of a deduped review whichever order it is written in', function (bool $freshIsSecond) {
    // Which row is authoritative must be an ANSWER, not a coin toss: the same
    // two rows in either write order publish the same card. Both copies
    // attribute the review to Raff, so admission is not in question here —
    // only which wording lands on the page.
    [$pro, $siteId, $itemIds] = personScopeFixture('Raff McGuiness', 'Raff', [
        ['staff_name' => 'Raff', 'text' => $freshIsSecond ? 'Older copy: Raff was great.' : 'Newer copy: Raff was great.'],
    ]);
    personScopeSecondSource($pro->id, $itemIds[0], [
        'staff_name' => 'Raff',
        'text' => $freshIsSecond ? 'Newer copy: Raff was great.' : 'Older copy: Raff was great.',
    ], (string) ($freshIsSecond ? now()->addDay() : now()->subDay()));

    expect(personScopePublished($siteId))->toBe($itemIds)
        ->and(personScopeCards($siteId))->toBe(['Newer copy: Raff was great.']);
})->with([
    'the fresher row is written second' => true,
    'the fresher row is written first' => false,
]);

// ── SURVIVING MUTANT: the veto looked at one staff name ─────────────────────

it('lets ANY of several staff attributions veto a review, not just the first', function (bool $ownerNamedFirst) {
    // `foreach ($staffNames as $staffName)` mutated to `foreach
    // ([$staffNames[0]] as $staffName)` survived all 146 tests across the five
    // review suites. It is reachable on a live shape: ollies' deduped reviews
    // carry Fresha's "Ciel" on one row and can carry the salon's own team
    // spelling on the other, and stopping at the first match admits exactly
    // the review the second name disowns.
    //
    // Both orders, so the veto cannot be "fixed" by looking at the last name
    // instead of the first.
    [$pro, $siteId, $itemIds] = personScopeFixture('Raff McGuiness', 'Raff', [
        ['staff_name' => $ownerNamedFirst ? 'Raff' : 'Ciel', 'text' => 'Lovely visit, thank you.'],
    ]);
    personScopeSecondSource($pro->id, $itemIds[0], [
        'staff_name' => $ownerNamedFirst ? 'Ciel' : 'Raff',
        'text' => 'Lovely visit, thank you.',
    ], (string) now()->addDay());

    expect(personScopePublished($siteId))->toBe([])
        ->and(app(PoolResolver::class)->hasSelection(Site::query()->findOrFail($siteId), 'reviews'))->toBeFalse();
})->with([
    'the owner is the first name read' => true,
    'the colleague is the first name read' => false,
]);

// ── BLOCKER: a storewide corpus re-labelled by a later narrowing ────────────

it('keeps a review ingested storewide out of the pool after the connection is narrowed to one employee', function () {
    // Proved and permanent before this fix. The employee-scope tier publishes
    // a review carrying NO name evidence at all, purely on the vendor's word
    // that the feed was already narrowed to this person — and it read the
    // source's CURRENT selection_ref. So harvesting
    // vision-hair-studio-melbourne-tzo6gxk0 storewide and then picking
    // employee 5035183 republished the whole salon's corpus as Raff's, with
    // nothing in those rows ever saying otherwise.
    [$pro, $siteId, , $connectionId] = personScopeFixture('Raff McGuiness', 'Raff', [
        ['text' => 'Best cut in Melbourne, could not be happier.'],
    ]);
    personScopeEmployeeSource($pro->id, $connectionId, ingestedUnder: 'storewide', currentlySetTo: '5035183');

    expect(personScopePublished($siteId))->toBe([])
        ->and(app(PoolResolver::class)->hasSelection(Site::query()->findOrFail($siteId), 'reviews'))->toBeFalse();
});

it('keeps publishing a review ingested under an employee selection after the connection is widened to storewide', function () {
    // The other direction, or the fix above is just "never trust the gate".
    // These reviews DID arrive from a feed narrowed to this person; widening
    // the connection afterwards changes what the NEXT harvest brings, not what
    // the last one meant.
    [$pro, $siteId, $itemIds, $connectionId] = personScopeFixture('Raff McGuiness', 'Raff', [
        ['text' => 'Best cut in Melbourne, could not be happier.'],
    ]);
    personScopeEmployeeSource($pro->id, $connectionId, ingestedUnder: '5035183', currentlySetTo: 'storewide');

    expect(personScopePublished($siteId))->toBe($itemIds);
});

it('does not read employee scope off a review with no record of what it was ingested under', function () {
    // Every row that existed before content.source_items.ingest_selection_ref
    // did. NULL is "we do not know", and the one tier that publishes on no
    // name evidence at all must never open on an unknown.
    [$pro, $siteId, , $connectionId] = personScopeFixture('Raff McGuiness', 'Raff', [
        ['text' => 'Best cut in Melbourne, could not be happier.'],
    ]);
    personScopeEmployeeSource($pro->id, $connectionId);
    DB::table('content.source_items')->update(['ingest_selection_ref' => null]);

    expect(personScopePublished($siteId))->toBe([]);
});
