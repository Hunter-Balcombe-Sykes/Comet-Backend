<?php

// TEST-5: CI guard that enforces design_kit field parity between the two
// request classes. If a migration adds or drops a design_kits column, one
// of these classes must be updated to match the other — this test catches
// the case where only one is updated.

use App\Http\Requests\Api\Staff\UserSite\StaffUpdateSiteRequest;
use App\Http\Requests\Api\User\Site\UpdateSiteRequest;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

function designKitKeysFrom(array $rules): array
{
    $keys = array_filter(
        array_keys($rules),
        fn (string $k) => str_starts_with($k, 'design_kit.'),
    );
    sort($keys);

    return array_values($keys);
}

it('UpdateSiteRequest and StaffUpdateSiteRequest have identical design_kit keys', function () {
    $userKeys = designKitKeysFrom((new UpdateSiteRequest)->rules());
    $staffKeys = designKitKeysFrom((new StaffUpdateSiteRequest)->rules());

    $onlyInUser = array_values(array_diff($userKeys, $staffKeys));
    $onlyInStaff = array_values(array_diff($staffKeys, $userKeys));

    expect($onlyInUser)->toBe(
        [],
        'Keys in UpdateSiteRequest but missing from StaffUpdateSiteRequest: '.implode(', ', $onlyInUser),
    );

    expect($onlyInStaff)->toBe(
        [],
        'Keys in StaffUpdateSiteRequest but missing from UpdateSiteRequest: '.implode(', ', $onlyInStaff),
    );
});
