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

    // has_integration reads site.platform_connections via the model relation.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.platform_connections (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        platform TEXT NULL,
        is_active INTEGER NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('DELETE FROM core.users');
    DB::connection('pgsql')->statement('DELETE FROM site.platform_connections');
    DB::connection('pgsql')->statement('DELETE FROM core.early_access_signups');
});

function ovaSeedUser(array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert(array_merge([
        'id' => $id,
        'handle' => 'u-'.Str::random(8),
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
        ['id' => (string) Str::uuid(), 'user_id' => $withInsta, 'platform' => 'instagram', 'is_active' => 1, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
        ['id' => (string) Str::uuid(), 'user_id' => $withSquareInactive, 'platform' => 'square', 'is_active' => 0, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
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
