<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Policies\PreAccountBuildPolicy;

// Finding 7: attachContactEmail was gated on staffCreate (unconditionally
// true for any staff role), making it a site-takeover primitive reachable by
// support staff. staffAttachContactEmail must be admin only.
it('allows an admin to attach a contact email', function () {
    $staff = new PartnaStaff;
    $staff->role = PartnaStaff::ROLE_ADMIN;

    expect((new PreAccountBuildPolicy)->staffAttachContactEmail($staff))->toBeTrue();
});

it('denies a support-role staff member from attaching a contact email', function () {
    $staff = new PartnaStaff;
    $staff->role = PartnaStaff::ROLE_SUPPORT;

    expect((new PreAccountBuildPolicy)->staffAttachContactEmail($staff))->toBeFalse();
});
