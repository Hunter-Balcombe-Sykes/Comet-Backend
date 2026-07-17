<?php

use App\Jobs\Platforms\ReconcilePlatformTakedownJob;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();
    DB::connection('pgsql')->statement('DELETE FROM core.user_segments');
});

function takedownUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function skoolConn(User $u): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $u->id, 'platform' => 'skool', 'resource_id' => 'c-'.Str::random(5),
        'payload' => ['url' => 'https://www.skool.com/demo', 'name' => 'Demo'], 'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

it('globally flips is_active=false without deleting data', function () {
    $a = skoolConn(takedownUser('tka'));
    $b = skoolConn(takedownUser('tkb'));

    (new ReconcilePlatformTakedownJob('skool'))->handle(app(App\Services\Segments\SegmentResolver::class));

    $a->refresh();
    $b->refresh();
    expect($a->is_active)->toBeFalse()
        ->and($b->is_active)->toBeFalse()
        ->and($a->payload)->toBe(['url' => 'https://www.skool.com/demo', 'name' => 'Demo']) // data intact
        ->and($a->deleted_at)->toBeNull(); // not soft-deleted
});

it('leaves other platforms untouched', function () {
    $user = takedownUser('tkother');
    $ig = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'ig-1',
        'payload' => ['username' => 'x'], 'is_active' => true,
    ]);

    (new ReconcilePlatformTakedownJob('skool'))->handle(app(App\Services\Segments\SegmentResolver::class));

    expect($ig->refresh()->is_active)->toBeTrue();
});

it('scopes a segment takedown to that segment members only', function () {
    $member = takedownUser('tkmember');
    $outsider = takedownUser('tkoutsider');
    $mConn = skoolConn($member);
    $oConn = skoolConn($outsider);

    $segment = UserSegment::query()->create(['name' => 'seg-'.Str::random(4), 'filters' => []]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $member->id]);

    (new ReconcilePlatformTakedownJob('skool', $segment->id))->handle(app(App\Services\Segments\SegmentResolver::class));

    expect($mConn->refresh()->is_active)->toBeFalse()
        ->and($oConn->refresh()->is_active)->toBeTrue();
});
