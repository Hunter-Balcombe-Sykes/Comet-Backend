<?php

// TEST-3: UserStaffResource (staff admin view of a professional) had zero
// test coverage — SEC-101's $showPii gate (auth_user_id, phone, primary_email,
// public_contact_number, location_*, admin_notes) was never exercised either
// way. Unlike IndividualProfileResource, this class IS entirely
// $this->-attribute-driven, so the UserPublicResourceTest.php style (assert
// specific keys/values against a raw-attribute model) transfers directly —
// no vacuousness risk here since every field genuinely reads off the model.

use App\Http\Resources\UserStaffResource;
use App\Models\Core\User\User;
use Illuminate\Http\Request;

/** A User with every PII-gated + non-PII field populated to a distinct value. */
function staffResourceTestUser(): User
{
    $pro = new User;
    $pro->setRawAttributes([
        'id' => 'staff-pro-1',
        'auth_user_id' => 'auth-secret-uuid',
        'account_type' => 'partna',
        'display_name' => 'Staff Target',
        'partna_url' => 'https://target.partna.au',
        'first_name' => 'Target',
        'last_name' => 'User',
        'phone' => '+61400000001',
        'primary_email' => 'target@example.com',
        'country_code' => 'AU',
        'timezone' => 'Australia/Sydney',
        'status' => 'active',
        'onboarding_step' => 3,
        'public_contact_number' => '+61400000002',
        'public_contact_email' => 'public@example.com',
        'location_street_address' => '1 Secret St',
        'location_city' => 'Sydney',
        'location_state' => 'NSW',
        'location_postcode' => '2000',
        'location_country' => 'AU',
        'admin_notes' => 'Flagged for follow-up',
        'deleted_at' => null,
    ]);

    return $pro;
}

it('nulls every PII-gated field when showPii is false, but keeps non-PII fields', function () {
    $pro = staffResourceTestUser();

    $array = (new UserStaffResource($pro, showPii: false))->resolve(Request::create('/'));

    // PII gate — SEC-101: nulled, never leaked to non-admin staff.
    expect($array['auth_user_id'])->toBeNull();
    expect($array['phone'])->toBeNull();
    expect($array['primary_email'])->toBeNull();
    expect($array['public_contact_number'])->toBeNull();
    expect($array['location_street_address'])->toBeNull();
    expect($array['location_city'])->toBeNull();
    expect($array['location_state'])->toBeNull();
    expect($array['location_postcode'])->toBeNull();
    expect($array['location_country'])->toBeNull();
    expect($array['admin_notes'])->toBeNull();

    // Not PII-gated — always visible to any staff member.
    expect($array['id'])->toBe('staff-pro-1');
    expect($array['account_type'])->toBe('partna');
    expect($array['display_name'])->toBe('Staff Target');
    expect($array['partna_url'])->toBe('https://target.partna.au');
    expect($array['first_name'])->toBe('Target');
    expect($array['last_name'])->toBe('User');
    expect($array['country_code'])->toBe('AU');
    expect($array['timezone'])->toBe('Australia/Sydney');
    expect($array['status'])->toBe('active');
    expect($array['onboarding_step'])->toBe(3);
    // public_contact_email is deliberately NOT gated — it's the whole point
    // of the field (the professional wants it publicly visible).
    expect($array['public_contact_email'])->toBe('public@example.com');
    expect($array['parent_status'])->toBe('active');
});

it('exposes every PII-gated field when showPii is true (admin staff)', function () {
    $pro = staffResourceTestUser();

    $array = (new UserStaffResource($pro, showPii: true))->resolve(Request::create('/'));

    expect($array['auth_user_id'])->toBe('auth-secret-uuid');
    expect($array['phone'])->toBe('+61400000001');
    expect($array['primary_email'])->toBe('target@example.com');
    expect($array['public_contact_number'])->toBe('+61400000002');
    expect($array['location_street_address'])->toBe('1 Secret St');
    expect($array['location_city'])->toBe('Sydney');
    expect($array['location_state'])->toBe('NSW');
    expect($array['location_postcode'])->toBe('2000');
    expect($array['location_country'])->toBe('AU');
    expect($array['admin_notes'])->toBe('Flagged for follow-up');
});

it('omits pre_account_build entirely when the relation is not loaded, regardless of showPii', function () {
    $pro = staffResourceTestUser();

    $array = (new UserStaffResource($pro, showPii: true))->resolve(Request::create('/'));

    // Gated on the resolved relation value, not just relationLoaded() — see
    // the class docblock. Key must be fully ABSENT (not present-as-null) so
    // staff clients can key off presence.
    expect($array)->not->toHaveKey('pre_account_build');
});

it('omits pre_account_build when the relation IS loaded but resolves to null', function () {
    $pro = staffResourceTestUser();

    // The case a bare whenLoaded() gets wrong: an eager-load that found no row
    // leaves relationLoaded() true with a null value, so whenLoaded() would
    // emit the key as null. UserStaffResource guards on the resolved value for
    // exactly this reason — without setRelation(null) here the test never
    // exercises it and passes against the buggy form.
    $pro->setRelation('preAccountBuild', null);

    $array = (new UserStaffResource($pro, showPii: true))->resolve(Request::create('/'));

    expect($array)->not->toHaveKey('pre_account_build');
});
