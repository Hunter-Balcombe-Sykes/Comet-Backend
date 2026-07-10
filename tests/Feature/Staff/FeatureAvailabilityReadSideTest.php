<?php

/**
 * OV-A — FeatureAvailability::for($user) resolution:
 * absence = available · global rows apply to everyone · segment rows beat the
 * global row · disabled wins across multiple segment rows · flush busts cache.
 */

use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();

    DB::connection('pgsql')->statement('DELETE FROM core.users');
    DB::connection('pgsql')->statement('DELETE FROM core.feature_availability');
    DB::connection('pgsql')->statement('DELETE FROM core.user_segments');

    FeatureAvailability::flush();
});

function ovaAvailUser(): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'avail-'.Str::random(6),
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return User::query()->findOrFail($id);
}

function ovaManualSegmentWith(string $userId): UserSegment
{
    $segment = UserSegment::query()->create(['name' => 'seg-'.Str::random(4), 'filters' => []]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $userId]);

    return $segment;
}

it('defaults every key to available when no rules exist', function () {
    expect(FeatureAvailability::for(ovaAvailUser())->allows('integration.instagram'))->toBeTrue();
});

it('applies a global disabled rule to everyone', function () {
    FeatureAvailabilityRule::query()->create(['feature_key' => 'integration.instagram', 'mode' => 'disabled']);
    FeatureAvailability::flush();

    expect(FeatureAvailability::for(ovaAvailUser())->allows('integration.instagram'))->toBeFalse();
});

it('lets a segment rule beat the global rule for members only', function () {
    $member = ovaAvailUser();
    $outsider = ovaAvailUser();
    $segment = ovaManualSegmentWith($member->id);

    FeatureAvailabilityRule::query()->create(['feature_key' => 'feature.shop', 'mode' => 'disabled']);
    FeatureAvailabilityRule::query()->create(['feature_key' => 'feature.shop', 'mode' => 'enabled', 'segment_id' => $segment->id]);
    FeatureAvailability::flush();

    expect(FeatureAvailability::for($member)->allows('feature.shop'))->toBeTrue()
        ->and(FeatureAvailability::for($outsider)->allows('feature.shop'))->toBeFalse();
});

it('lets disabled win when a user is in several ruled segments', function () {
    $user = ovaAvailUser();
    $segA = ovaManualSegmentWith($user->id);
    $segB = ovaManualSegmentWith($user->id);

    FeatureAvailabilityRule::query()->create(['feature_key' => 'feature.beta', 'mode' => 'enabled', 'segment_id' => $segA->id]);
    FeatureAvailabilityRule::query()->create(['feature_key' => 'feature.beta', 'mode' => 'disabled', 'segment_id' => $segB->id]);
    FeatureAvailability::flush();

    expect(FeatureAvailability::for($user)->allows('feature.beta'))->toBeFalse();
});

it('reflects rule changes immediately after flush', function () {
    $user = ovaAvailUser();

    expect(FeatureAvailability::for($user)->allows('integration.square'))->toBeTrue();

    $rule = FeatureAvailabilityRule::query()->create(['feature_key' => 'integration.square', 'mode' => 'disabled']);
    FeatureAvailability::flush();
    expect(FeatureAvailability::for($user)->allows('integration.square'))->toBeFalse();

    $rule->delete();
    FeatureAvailability::flush();
    expect(FeatureAvailability::for($user)->allows('integration.square'))->toBeTrue();
});
