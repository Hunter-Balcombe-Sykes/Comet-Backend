<?php

// The Reviews page may only be advertised when a review the OWNER is named in
// survives the scope. That is a gate, and it is code.
//
// Until 2026-09-01 the structural statement was SitepageId::BUSINESS_ONLY
// listing `reviews`: crude (it read the account type) but real. The commit that
// replaced it deleted the entry and left prose saying whoever restores Reviews
// presence should gate it "on the account's own review data" — in the same
// commit family whose whole brief is that a review must not appear on a page
// whose owner is not named in it. Nothing enforced the prose. One line adding
// 'reviews' to the presence-probe loop would have advertised a barber's page
// over reviews about the stylist upstairs, and no test would have failed.
//
// SitepageId::ATTRIBUTION_GATED + SitepageDataResolverService
// ::gateOwnerAttribution() are the restored gate. Presence does not set
// `reviews` today (Reviews stopped being its own page 2026-07-13 and folds into
// About when that is built), so these call the gate directly rather than
// through presentPageIds — the point of a dormant gate is that it is armed
// BEFORE the line that wakes it, and a test that could only reach it after
// that line lands is the same promise-in-a-comment again.

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    setupMediaTables();
    // Person-scoping joins ingest.sources to spot vendor employee-scoped
    // review sources, so the mirror must exist.
    setupIngestTables();
    Queue::fake();
});

/**
 * A partna tenant whose venue-level Google listing landed one review.
 *
 * @param  array<string, mixed>  $review
 * @return array{object, string}
 */
function ragFixture(string $ownerName, array $review): array
{
    [$pro, $siteId] = poolTenant();
    DB::table('core.users')->where('id', $pro->id)->update([
        'display_name' => $ownerName,
        'first_name' => explode(' ', $ownerName)[0],
    ]);
    AccountCapabilities::flushCache();

    $connectionId = poolConnection($pro->id, 'google_business.listing');
    $sourceId = poolSource($pro->id, $connectionId);

    $itemId = (string) Str::uuid();
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

    return [$pro, $siteId];
}

/** @return array<string, bool> */
function ragGate(?string $siteId): array
{
    return app(SitepageDataResolverService::class)->gateOwnerAttribution(
        ['home' => true, 'reviews' => true],
        $siteId === null ? null : Site::query()->findOrFail($siteId),
    );
}

it('drops the Reviews page when the venue review names someone other than the owner', function () {
    // ollies' incident verbatim in shape: a venue listing's review praising a
    // colleague, sitting on a named individual's page.
    [, $siteId] = ragFixture('Simon Doyle', [
        'text' => 'Jack is the one to go to. Amazing fade.',
        'staff_name' => 'Jack',
    ]);

    expect(ragGate($siteId))->not->toHaveKey('reviews')
        ->and(ragGate($siteId))->toHaveKey('home');
});

it('keeps the Reviews page when a review names the owner', function () {
    // Without this the gate is just "publish nothing", which is not a gate.
    [, $siteId] = ragFixture('Simon Doyle', [
        'text' => 'Simon gave me the best cut of my life.',
    ]);

    expect(ragGate($siteId))->toHaveKey('reviews');
});

it('drops the Reviews page when the owner switched the source\'s reviews off', function () {
    // The owner's own suppression is part of the same arithmetic — nav must not
    // advertise a page whose pool the owner emptied.
    [$pro, $siteId] = ragFixture('Simon Doyle', [
        'text' => 'Simon gave me the best cut of my life.',
    ]);
    DB::table('site.platform_connections')->where('user_id', $pro->id)
        ->update(['display_settings' => json_encode(['reviews' => false])]);

    expect(ragGate($siteId))->not->toHaveKey('reviews');
});

it('drops the Reviews page when there is no site at all', function () {
    expect(ragGate(null))->not->toHaveKey('reviews');
});

it('drops the Reviews page when the attribution probe faults — fail CLOSED', function () {
    // The one place this class inverts its fail-open presence posture. A
    // faulted probe means "cannot prove these reviews are yours", and the
    // answer to that is not to publish them. Fault injected the way the
    // presence suites do it: drop the table the probe really reads.
    [, $siteId] = ragFixture('Simon Doyle', [
        'text' => 'Simon gave me the best cut of my life.',
    ]);
    DB::connection('pgsql')->statement('DROP TABLE content.f_review');

    expect(ragGate($siteId))->not->toHaveKey('reviews');
});

it('never lets presentPageIds advertise Reviews over another person\'s reviews', function () {
    // The forward guard, and the only honest thing to say about it: today it
    // passes because presence never sets `reviews` at all, so it cannot fail
    // under mutation — deleting the gateOwnerAttribution() call from
    // presentPageIds leaves it green. It is here for the commit that adds
    // reviews presence, which is the commit that can make it fail: at that
    // moment this asserts the gate is in the path and not just in the file.
    [$pro, $siteId] = ragFixture('Simon Doyle', [
        'text' => 'Jack is the one to go to. Amazing fade.',
        'staff_name' => 'Jack',
    ]);

    $pages = app(SitepageDataResolverService::class)->presentPageIds(
        Site::query()->findOrFail($siteId),
        AccountCapabilities::for(User::query()->findOrFail($pro->id)),
        collect(),
    );

    expect($pages)->not->toContain('reviews');
});

it('leaves every page it was not asked about alone', function () {
    [, $siteId] = ragFixture('Simon Doyle', ['text' => 'Jack is the one to go to.', 'staff_name' => 'Jack']);

    $gated = app(SitepageDataResolverService::class)->gateOwnerAttribution(
        ['home' => true, 'menu' => true, 'gallery' => true, 'reviews' => true],
        Site::query()->findOrFail($siteId),
    );

    expect(array_keys($gated))->toBe(['home', 'menu', 'gallery']);
});
