<?php

/**
 * OV-A — SegmentResolver: each dynamic filter, the manual-member union, and
 * the zero-dynamic-keys = manual-only rule.
 */

use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Services\Segments\SegmentResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSegmentsTables();
    setupEarlyAccessTable();
    setupSiteVisitsTable();
    setupLinkClicksTable();

    // has_integration and ig_followers read site.platform_connections via the
    // model relation — setupSitesTable() creates that table (payload mirrors
    // the real jsonb column as TEXT) along with site.sites.
    setupSitesTable();

    DB::connection('pgsql')->statement('DELETE FROM core.users');
    DB::connection('pgsql')->statement('DELETE FROM site.platform_connections');
    DB::connection('pgsql')->statement('DELETE FROM core.early_access_signups');
    DB::connection('pgsql')->statement('DELETE FROM analytics.site_visits');
    DB::connection('pgsql')->statement('DELETE FROM analytics.link_clicks');
});

function ovaSeedUser(array $overrides = []): string
{
    $id = (string) Str::uuid();
    $handle = 'u-'.Str::random(8);
    DB::connection('pgsql')->table('core.users')->insert(array_merge([
        'id' => $id,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'primary_email' => 'u-'.Str::random(8).'@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));

    return $id;
}

function ovaSegment(array $filters, array $attrs = []): UserSegment
{
    return UserSegment::query()->create(array_merge([
        'name' => 'Segment '.Str::random(4),
        'filters' => $filters,
    ], $attrs));
}

it('resolves account_type, sector and created-range filters (AND-combined)', function () {
    $hairBiz = ovaSeedUser(['account_type' => 'business', 'sector' => 'hairdresser']);
    ovaSeedUser(['account_type' => 'partna', 'sector' => 'hairdresser']);
    ovaSeedUser(['account_type' => 'business', 'sector' => 'dj']);
    $oldBiz = ovaSeedUser([
        'account_type' => 'business',
        'sector' => 'hairdresser',
        'created_at' => now()->subYears(2)->toDateTimeString(),
    ]);

    $resolver = app(SegmentResolver::class);

    $ids = $resolver->userIds(ovaSegment(['account_type' => 'business', 'sector' => ['hairdresser']]));
    expect($ids)->toContain($hairBiz)->toContain($oldBiz)->toHaveCount(2);

    $recent = $resolver->userIds(ovaSegment([
        'account_type' => 'business',
        'sector' => ['hairdresser'],
        'created_from' => now()->subDays(30)->format('Y-m-d'),
    ]));
    expect($recent)->toBe([$hairBiz]);
});

it('resolves has_integration as any-active or a specific platform', function () {
    $withInsta = ovaSeedUser();
    $withSquareInactive = ovaSeedUser();
    ovaSeedUser(); // no connections

    DB::connection('pgsql')->table('site.platform_connections')->insert([
        ['id' => (string) Str::uuid(), 'user_id' => $withInsta, 'surface_key' => 'instagram.profile', 'routing_class' => 'social', 'resource_id' => 'instagram', 'is_active' => 1, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
        ['id' => (string) Str::uuid(), 'user_id' => $withSquareInactive, 'surface_key' => 'square.book', 'routing_class' => 'booking', 'resource_id' => 'square', 'is_active' => 0, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
    ]);

    $resolver = app(SegmentResolver::class);

    expect($resolver->userIds(ovaSegment(['has_integration' => true])))->toBe([$withInsta])
        ->and($resolver->userIds(ovaSegment(['has_integration' => 'instagram'])))->toBe([$withInsta])
        ->and($resolver->userIds(ovaSegment(['has_integration' => 'square'])))->toBeEmpty();
});

it('resolves the early_access flag by matching primary email against the signups table', function () {
    $earlyUser = ovaSeedUser(['primary_email' => 'Early@Example.Test']);
    $normalUser = ovaSeedUser();

    DB::connection('pgsql')->table('core.early_access_signups')->insert([
        'id' => (string) Str::uuid(),
        'email' => 'early@example.test',
        'email_lc' => 'early@example.test',
        'type' => 'partna',
        'status' => 'signed_up',
        'source' => 'marketing',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $resolver = app(SegmentResolver::class);

    expect($resolver->userIds(ovaSegment(['early_access' => true])))->toBe([$earlyUser])
        ->and($resolver->userIds(ovaSegment(['early_access' => false])))->toBe([$normalUser]);
});

it('unions manual members on top of dynamic filters and dedupes', function () {
    $dj = ovaSeedUser(['sector' => 'dj']);
    $manual = ovaSeedUser(['sector' => 'hairdresser']);

    $segment = ovaSegment(['sector' => ['dj']]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $manual]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $dj]); // already matched dynamically

    $ids = app(SegmentResolver::class)->userIds($segment);

    expect($ids)->toContain($dj)->toContain($manual)->toHaveCount(2);
});

it('treats a segment with zero dynamic keys as manual-members-only', function () {
    ovaSeedUser();
    $manual = ovaSeedUser();

    $segment = ovaSegment([]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $manual]);

    expect(app(SegmentResolver::class)->userIds($segment))->toBe([$manual]);
});

it('excludes soft-deleted users from dynamic results', function () {
    ovaSeedUser(['sector' => 'dj', 'deleted_at' => now()->toDateTimeString()]);
    $live = ovaSeedUser(['sector' => 'dj']);

    expect(app(SegmentResolver::class)->userIds(ovaSegment(['sector' => ['dj']])))->toBe([$live]);
});

it('resolves country_code, location_state and location_city', function () {
    $sydney = ovaSeedUser(['country_code' => 'AU', 'location_state' => 'NSW', 'location_city' => 'Sydney']);
    $melb = ovaSeedUser(['country_code' => 'AU', 'location_state' => 'Victoria', 'location_city' => 'Melbourne']);
    $auckland = ovaSeedUser(['country_code' => 'NZ', 'location_state' => 'Auckland', 'location_city' => 'Auckland']);
    ovaSeedUser(['country_code' => null, 'location_state' => null, 'location_city' => null]);

    $resolver = app(SegmentResolver::class);

    expect($resolver->userIds(ovaSegment(['country_code' => ['AU']])))
        ->toContain($sydney)->toContain($melb)->toHaveCount(2)
        ->and($resolver->userIds(ovaSegment(['country_code' => ['AU', 'NZ']])))->toHaveCount(3)
        ->and($resolver->userIds(ovaSegment(['location_state' => ['Victoria']])))->toBe([$melb])
        ->and($resolver->userIds(ovaSegment(['location_city' => ['Auckland']])))->toBe([$auckland]);
});

it('matches location_state and location_city case-insensitively', function () {
    $melb = ovaSeedUser(['location_state' => 'Victoria', 'location_city' => 'Melbourne']);

    $resolver = app(SegmentResolver::class);

    expect($resolver->userIds(ovaSegment(['location_state' => ['VICTORIA']])))->toBe([$melb])
        ->and($resolver->userIds(ovaSegment(['location_city' => ['melbourne']])))->toBe([$melb]);
});

it('excludes users with a blank location from location filters', function () {
    ovaSeedUser(['location_city' => null]);
    ovaSeedUser(['location_city' => '']);
    $sydney = ovaSeedUser(['location_city' => 'Sydney']);

    expect(app(SegmentResolver::class)->userIds(ovaSegment(['location_city' => ['Sydney']])))->toBe([$sydney]);
});

it('resolves tenure_days_min and tenure_days_max against signup date', function () {
    $veteran = ovaSeedUser(['created_at' => now()->subDays(200)->toDateTimeString()]);
    $midterm = ovaSeedUser(['created_at' => now()->subDays(60)->toDateTimeString()]);
    $rookie = ovaSeedUser(['created_at' => now()->subDays(3)->toDateTimeString()]);

    $resolver = app(SegmentResolver::class);

    // on Partna >= 30 days
    expect($resolver->userIds(ovaSegment(['tenure_days_min' => 30])))
        ->toContain($veteran)->toContain($midterm)->toHaveCount(2)
        // on Partna <= 90 days
        ->and($resolver->userIds(ovaSegment(['tenure_days_max' => 90])))
        ->toContain($midterm)->toContain($rookie)->toHaveCount(2)
        // both bounds → the 30-90 day band
        ->and($resolver->userIds(ovaSegment(['tenure_days_min' => 30, 'tenure_days_max' => 90])))->toBe([$midterm]);
});

it('AND-combines tenure with an existing criterion', function () {
    $djMid = ovaSeedUser(['sector' => 'dj', 'created_at' => now()->subDays(60)->toDateTimeString()]);
    ovaSeedUser(['sector' => 'dj', 'created_at' => now()->subDays(3)->toDateTimeString()]);
    ovaSeedUser(['sector' => 'hairdresser', 'created_at' => now()->subDays(60)->toDateTimeString()]);

    expect(app(SegmentResolver::class)->userIds(ovaSegment(['sector' => ['dj'], 'tenure_days_min' => 30])))
        ->toBe([$djMid]);
});

it('treats null location and tenure keys as inert', function () {
    ovaSeedUser(['location_city' => 'Sydney']);
    $manual = ovaSeedUser();

    $segment = ovaSegment(['country_code' => null, 'location_city' => null, 'tenure_days_min' => null]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $manual]);

    // No criterion active → dynamic set empty → manual members only.
    expect(app(SegmentResolver::class)->userIds($segment))->toBe([$manual]);
});

function ovaSeedInstagram(string $userId, mixed $followers, array $overrides = []): void
{
    DB::connection('pgsql')->table('site.platform_connections')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'surface_key' => 'instagram.profile',
        'routing_class' => 'social',
        'resource_id' => 'instagram',
        'payload' => json_encode(['followersCount' => $followers]),
        'is_active' => 1,
        'last_refreshed_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));
}

it('resolves ig_followers min and max bounds', function () {
    $small = ovaSeedUser();
    $mid = ovaSeedUser();
    $huge = ovaSeedUser();
    ovaSeedUser(); // no instagram connection at all

    ovaSeedInstagram($small, 500);
    ovaSeedInstagram($mid, 10000);
    ovaSeedInstagram($huge, 900000);

    $resolver = app(SegmentResolver::class);

    expect($resolver->userIds(ovaSegment(['ig_followers' => ['min' => 1000]])))
        ->toContain($mid)->toContain($huge)->toHaveCount(2)
        ->and($resolver->userIds(ovaSegment(['ig_followers' => ['max' => 1000]])))->toBe([$small])
        ->and($resolver->userIds(ovaSegment(['ig_followers' => ['min' => 1000, 'max' => 50000]])))->toBe([$mid]);
});

it('reads ig follower counts stored as numeric strings', function () {
    $stringy = ovaSeedUser();
    ovaSeedInstagram($stringy, '2500');

    expect(app(SegmentResolver::class)->userIds(ovaSegment(['ig_followers' => ['min' => 1000]])))->toBe([$stringy]);
});

it('excludes non-numeric and missing ig follower counts without erroring', function () {
    $garbage = ovaSeedUser();
    $nulled = ovaSeedUser();
    $absent = ovaSeedUser();
    $good = ovaSeedUser();

    ovaSeedInstagram($garbage, '1.2M');
    ovaSeedInstagram($nulled, null);
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $absent,
        'surface_key' => 'instagram.profile', 'routing_class' => 'social', 'resource_id' => 'instagram',
        'payload' => json_encode(['username' => 'nofollowers']),
        'is_active' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    ovaSeedInstagram($good, 5000);

    expect(app(SegmentResolver::class)->userIds(ovaSegment(['ig_followers' => ['min' => 1]])))->toBe([$good]);
});

it('ignores inactive instagram connections for ig_followers', function () {
    $inactive = ovaSeedUser();
    ovaSeedInstagram($inactive, 9999, ['is_active' => 0]);

    expect(app(SegmentResolver::class)->userIds(ovaSegment(['ig_followers' => ['min' => 100]])))->toBeEmpty();
});

it('applies synced_within_days, falling back to created_at when never refreshed', function () {
    $freshConnect = ovaSeedUser();   // never refreshed, but connected today
    $staleConnect = ovaSeedUser();   // never refreshed, connected long ago
    $refreshed = ovaSeedUser();      // connected long ago, refreshed today

    ovaSeedInstagram($freshConnect, 5000);
    ovaSeedInstagram($staleConnect, 5000, [
        'created_at' => now()->subDays(200)->toDateTimeString(),
    ]);
    ovaSeedInstagram($refreshed, 5000, [
        'created_at' => now()->subDays(200)->toDateTimeString(),
        'last_refreshed_at' => now()->toDateTimeString(),
    ]);

    $ids = app(SegmentResolver::class)->userIds(ovaSegment([
        'ig_followers' => ['min' => 1000, 'synced_within_days' => 30],
    ]));

    expect($ids)->toContain($freshConnect)->toContain($refreshed)->toHaveCount(2);
});

it('treats an empty or all-null ig_followers object as inert', function () {
    ovaSeedInstagram(ovaSeedUser(), 5000);
    $manual = ovaSeedUser();

    $segment = ovaSegment(['ig_followers' => ['min' => null, 'max' => null]]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $manual]);

    expect(app(SegmentResolver::class)->userIds($segment))->toBe([$manual])
        ->and(app(SegmentResolver::class)->userIds(ovaSegment(['ig_followers' => []])))->toBeEmpty();
});

it('AND-combines ig_followers with an existing criterion', function () {
    $djBig = ovaSeedUser(['sector' => 'dj']);
    $hairBig = ovaSeedUser(['sector' => 'hairdresser']);
    ovaSeedInstagram($djBig, 20000);
    ovaSeedInstagram($hairBig, 20000);

    expect(app(SegmentResolver::class)->userIds(ovaSegment(['sector' => ['dj'], 'ig_followers' => ['min' => 1000]])))
        ->toBe([$djBig]);
});

function ovaSeedVisit(string $userId, string $visitorId, int $daysAgo): void
{
    DB::connection('pgsql')->table('analytics.site_visits')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'visitor_id' => $visitorId,
        'occurred_at' => now()->subDays($daysAgo)->toDateTimeString(),
        'created_at' => now()->subDays($daysAgo)->toDateTimeString(),
    ]);
}

function ovaSeedClick(string $userId, string $visitorId, int $daysAgo): void
{
    DB::connection('pgsql')->table('analytics.link_clicks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'visitor_id' => $visitorId,
        'occurred_at' => now()->subDays($daysAgo)->toDateTimeString(),
        'created_at' => now()->subDays($daysAgo)->toDateTimeString(),
    ]);
}

it('resolves an analytics visits minimum over the window', function () {
    $busy = ovaSeedUser();
    $quiet = ovaSeedUser();
    ovaSeedUser(); // zero rows

    foreach (range(1, 5) as $i) {
        ovaSeedVisit($busy, "v{$i}", 3);
    }
    ovaSeedVisit($quiet, 'v1', 3);

    expect(app(SegmentResolver::class)->userIds(ovaSegment([
        'analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 3],
    ])))->toBe([$busy]);
});

it('ignores analytics events outside the window', function () {
    $lapsed = ovaSeedUser();

    foreach (range(1, 5) as $i) {
        ovaSeedVisit($lapsed, "v{$i}", 60); // older than the 30-day window
    }

    expect(app(SegmentResolver::class)->userIds(ovaSegment([
        'analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 1],
    ])))->toBeEmpty();
});

it('counts unique_visitors distinctly from raw visits', function () {
    $repeat = ovaSeedUser();

    // 4 visits, but only 2 distinct visitors.
    ovaSeedVisit($repeat, 'v1', 2);
    ovaSeedVisit($repeat, 'v1', 3);
    ovaSeedVisit($repeat, 'v2', 4);
    ovaSeedVisit($repeat, 'v2', 5);

    $resolver = app(SegmentResolver::class);

    expect($resolver->userIds(ovaSegment(['analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 4]])))->toBe([$repeat])
        ->and($resolver->userIds(ovaSegment(['analytics' => ['metric' => 'unique_visitors', 'window_days' => 30, 'min' => 4]])))->toBeEmpty()
        ->and($resolver->userIds(ovaSegment(['analytics' => ['metric' => 'unique_visitors', 'window_days' => 30, 'min' => 2]])))->toBe([$repeat]);
});

it('resolves click metrics from the link_clicks table', function () {
    $clicker = ovaSeedUser();
    $visitorOnly = ovaSeedUser();

    ovaSeedClick($clicker, 'v1', 2);
    ovaSeedClick($clicker, 'v2', 3);
    ovaSeedVisit($visitorOnly, 'v9', 2);

    $resolver = app(SegmentResolver::class);

    expect($resolver->userIds(ovaSegment(['analytics' => ['metric' => 'clicks', 'window_days' => 30, 'min' => 2]])))->toBe([$clicker])
        ->and($resolver->userIds(ovaSegment(['analytics' => ['metric' => 'unique_clickers', 'window_days' => 30, 'min' => 2]])))->toBe([$clicker]);
});

it('INCLUDES zero-row users under a max-only analytics filter', function () {
    $busy = ovaSeedUser();
    $quiet = ovaSeedUser();
    $silent = ovaSeedUser(); // no analytics rows whatsoever

    foreach (range(1, 10) as $i) {
        ovaSeedVisit($busy, "v{$i}", 3);
    }
    ovaSeedVisit($quiet, 'v1', 3);

    // "low traffic" must mean quiet AND silent — a user with no rows has 0
    // visits, which is <= the max. This is the semantic most likely to regress.
    $ids = app(SegmentResolver::class)->userIds(ovaSegment([
        'analytics' => ['metric' => 'visits', 'window_days' => 30, 'max' => 5],
    ]));

    expect($ids)->toContain($quiet)->toContain($silent)->not->toContain($busy)->toHaveCount(2);
});

it('EXCLUDES zero-row users when a min is set', function () {
    $silent = ovaSeedUser();
    $busy = ovaSeedUser();
    ovaSeedVisit($busy, 'v1', 3);

    expect(app(SegmentResolver::class)->userIds(ovaSegment([
        'analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 1],
    ])))->toBe([$busy])->not->toContain($silent);
});

it('applies both analytics bounds as a band', function () {
    $low = ovaSeedUser();
    $mid = ovaSeedUser();
    $high = ovaSeedUser();

    ovaSeedVisit($low, 'v1', 2);
    foreach (range(1, 5) as $i) {
        ovaSeedVisit($mid, "v{$i}", 2);
    }
    foreach (range(1, 20) as $i) {
        ovaSeedVisit($high, "v{$i}", 2);
    }

    expect(app(SegmentResolver::class)->userIds(ovaSegment([
        'analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 3, 'max' => 10],
    ])))->toBe([$mid]);
});

it('treats an all-null analytics object as inert', function () {
    $busy = ovaSeedUser();
    ovaSeedVisit($busy, 'v1', 2);
    $manual = ovaSeedUser();

    $segment = ovaSegment(['analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => null, 'max' => null]]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $manual]);

    expect(app(SegmentResolver::class)->userIds($segment))->toBe([$manual]);
});

it('AND-combines analytics with an existing criterion', function () {
    $djBusy = ovaSeedUser(['sector' => 'dj']);
    $hairBusy = ovaSeedUser(['sector' => 'hairdresser']);

    foreach (range(1, 5) as $i) {
        ovaSeedVisit($djBusy, "v{$i}", 2);
        ovaSeedVisit($hairBusy, "v{$i}", 2);
    }

    expect(app(SegmentResolver::class)->userIds(ovaSegment([
        'sector' => ['dj'],
        'analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 3],
    ])))->toBe([$djBusy]);
});

/**
 * `min: 0` = "no lower bound". A count is never negative, so the zero is inert
 * as a threshold — but taking it literally routes the query down the
 * EXISTS/GROUP BY branch, which cannot see zero-activity users at all. These
 * pin the corrected routing.
 */
it('treats analytics min:0 as no lower bound, keeping zero-row users in a band', function () {
    $busy = ovaSeedUser();
    $quiet = ovaSeedUser();
    $silent = ovaSeedUser(); // no analytics rows whatsoever

    foreach (range(1, 10) as $i) {
        ovaSeedVisit($busy, "v{$i}", 3);
    }
    ovaSeedVisit($quiet, 'v1', 3);

    $resolver = app(SegmentResolver::class);

    $banded = $resolver->userIds(ovaSegment([
        'analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 0, 'max' => 5],
    ]));

    // "0 to 5 visits" must include the user with literally zero.
    expect($banded)->toContain($quiet)->toContain($silent)->not->toContain($busy)->toHaveCount(2);

    // ...and must agree exactly with the max-only form, since min:0 adds nothing.
    $maxOnly = $resolver->userIds(ovaSegment([
        'analytics' => ['metric' => 'visits', 'window_days' => 30, 'max' => 5],
    ]));

    expect(array_values($banded))->toBe(array_values($maxOnly));
});

it('resolves analytics min:0,max:0 to exactly the zero-activity users', function () {
    $busy = ovaSeedUser();
    $silent = ovaSeedUser();
    $lapsed = ovaSeedUser();

    ovaSeedVisit($busy, 'v1', 3);
    ovaSeedVisit($lapsed, 'v1', 60); // outside the window — zero *in window*

    // Under the old EXISTS/HAVING form this was unsatisfiable by construction:
    // a GROUP BY group only exists when there is >= 1 row, so COUNT(*) is never
    // 0 for a group that exists, and `>= 0 AND <= 0` matched nobody, ever.
    expect(app(SegmentResolver::class)->userIds(ovaSegment([
        'analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 0, 'max' => 0],
    ])))->toContain($silent)->toContain($lapsed)->not->toContain($busy)->toHaveCount(2);
});

it('leaves a positive analytics min excluding zero-row users', function () {
    // Guards the other direction: normalising min:0 must not soften min:1.
    $silent = ovaSeedUser();
    $quiet = ovaSeedUser();
    ovaSeedVisit($quiet, 'v1', 3);

    $resolver = app(SegmentResolver::class);

    expect($resolver->userIds(ovaSegment(['analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 1]])))
        ->toBe([$quiet])->not->toContain($silent)
        ->and($resolver->userIds(ovaSegment(['analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 1, 'max' => 5]])))
        ->toBe([$quiet])->not->toContain($silent);
});

it('treats a stored analytics min:0 with no max as inert rather than matching everyone', function () {
    // Not reachable through the API (validation 422s it), but a hand-written
    // filters JSONB could carry this shape. It must NOT fall through to a
    // `> NULL` binding, which would make NOT EXISTS true for every user and
    // silently resolve the segment to the entire user base.
    $busy = ovaSeedUser();
    ovaSeedVisit($busy, 'v1', 2);
    $manual = ovaSeedUser();

    $segment = ovaSegment(['analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 0]]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $manual]);

    expect(app(SegmentResolver::class)->userIds($segment))->toBe([$manual]);
});

it('leaves ig_followers min:0 paired with a max behaving as the max alone', function () {
    // The shared requiresABound()/isLowerBound() helper is used by ig_followers
    // too. Its query shape is a plain comparison, not GROUP BY/HAVING, so
    // `>= 0` was already a logical no-op there — this pins that the shared
    // change did not alter ig_followers' resolved set.
    $small = ovaSeedUser();
    $large = ovaSeedUser();
    ovaSeedUser(); // no instagram connection at all

    ovaSeedInstagram($small, 500);
    ovaSeedInstagram($large, 90000);

    $resolver = app(SegmentResolver::class);

    expect($resolver->userIds(ovaSegment(['ig_followers' => ['min' => 0, 'max' => 1000]])))
        ->toBe($resolver->userIds(ovaSegment(['ig_followers' => ['max' => 1000]])))
        ->toBe([$small])->not->toContain($large);
});
