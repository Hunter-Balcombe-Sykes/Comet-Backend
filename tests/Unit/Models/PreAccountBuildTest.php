<?php

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use Illuminate\Support\Str;
use Tests\TestCase;

// Relationship assertions require a booted Laravel app (DB resolver).
uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupPreAccountBuildsTable();
});

it('links 1:1 to its provisional user and scopes live builds', function () {
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);

    $build = new PreAccountBuild([
        'source_type' => 'instagram',
        'source_ref' => 'JaneDoe',
        'source_ref_lc' => 'janedoe',
        'built_via' => PreAccountBuild::VIA_SIGNUP,
        'expires_at' => now()->addDays(30),
    ]);
    $build->user()->associate($user);
    $build->save();

    expect($user->fresh()->preAccountBuild->id)->toBe($build->id)
        ->and($user->fresh()->isUnclaimed())->toBeTrue()
        ->and(PreAccountBuild::live()->count())->toBe(1);

    $build->forceFill(['claimed_at' => now()])->save(); // B11 SEC-4: claimed_at no longer fillable
    expect(PreAccountBuild::live()->count())->toBe(0);
});

it('does not mass-assign tenancy FKs', function () {
    expect((new PreAccountBuild)->isFillable('user_id'))->toBeFalse()
        ->and((new PreAccountBuild)->isFillable('built_by_staff_id'))->toBeFalse();
});

it('treats a VIA_STAFF build as outreach even with no built_by_staff_id', function () {
    // built_by_staff_id is ON DELETE SET NULL — deleting the staff row that
    // created a build must not silently un-gate it. built_via survives that
    // deletion, so isOutreach() must check it too, not just the FK.
    $build = new PreAccountBuild(['built_via' => PreAccountBuild::VIA_STAFF]);

    expect($build->isOutreach())->toBeTrue();
});

it('does not treat a VIA_SIGNUP build as outreach', function () {
    $build = new PreAccountBuild(['built_via' => PreAccountBuild::VIA_SIGNUP]);

    expect($build->isOutreach())->toBeFalse();
});

// Dark Until Claimed: deliberately narrower than isOutreach(). expires_at
// !== null is the "staff approved this early-access lead" signal — requestBuild()
// gives every early-access build a null expiry at creation; only
// ApproveEarlyAccessBuildJob re-stamps a real one.
it('is visible while unclaimed for a staff-built build regardless of expiry', function () {
    // built_by_staff_id is not fillable — direct property set.
    $build = new PreAccountBuild;
    $build->built_by_staff_id = (string) Str::uuid();

    expect($build->isVisibleWhileUnclaimed())->toBeTrue();
});

it('is visible while unclaimed for a staff-approved early-access build', function () {
    $build = new PreAccountBuild(['built_via' => PreAccountBuild::VIA_EARLY_ACCESS, 'expires_at' => now()->addDays(30)]);

    expect($build->isVisibleWhileUnclaimed())->toBeTrue();
});

it('is not visible while unclaimed for an unapproved early-access build', function () {
    $build = new PreAccountBuild(['built_via' => PreAccountBuild::VIA_EARLY_ACCESS, 'expires_at' => null]);

    expect($build->isVisibleWhileUnclaimed())->toBeFalse();
});

it('is not visible while unclaimed for a self-serve signup build', function () {
    $build = new PreAccountBuild(['built_via' => PreAccountBuild::VIA_SIGNUP]);

    expect($build->isVisibleWhileUnclaimed())->toBeFalse();
});

it('is not visible while unclaimed for a VIA_STAFF build with a deleted staff row', function () {
    // Deliberately different from isOutreach(): visibility does not carry the
    // VIA_STAFF-survives-staff-deletion arm — failing toward dark is the safe
    // default here, unlike the claim gate where failing to recognise outreach
    // status would make the build first-come claimable.
    $build = new PreAccountBuild(['built_via' => PreAccountBuild::VIA_STAFF]);

    expect($build->isVisibleWhileUnclaimed())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| #PRIV-3 — created_ip_hash must not be a reversible digest
|--------------------------------------------------------------------------
| sha256(ip) is a pseudonym only against someone who cannot enumerate the
| inputs, and the whole IPv4 space is 4.3B candidates. hashIp() keys the digest
| on a secret so the stored value is not a lookup away from the visitor's IP.
*/

it('hashes a visitor IP with a keyed HMAC, not a bare digest', function () {
    config(['partna.pre_account.ip_hash_key' => 'test-pepper']);

    expect(PreAccountBuild::hashIp('1.2.3.4'))
        ->not->toBe(hash('sha256', '1.2.3.4'))
        ->and(PreAccountBuild::hashIp('1.2.3.4'))
        ->toBe(hash_hmac('sha256', '1.2.3.4', 'test-pepper'));
});

it('produces a different digest for the same IP under a different key', function () {
    config(['partna.pre_account.ip_hash_key' => 'key-a']);
    $a = PreAccountBuild::hashIp('1.2.3.4');

    config(['partna.pre_account.ip_hash_key' => 'key-b']);

    expect(PreAccountBuild::hashIp('1.2.3.4'))->not->toBe($a);
});

it('decodes a base64: prefixed APP_KEY before using it as the pepper', function () {
    // Laravel stores APP_KEY as "base64:<b64>"; hashing the literal prefixed
    // string would work but would disagree with any consumer that decodes.
    $raw = random_bytes(32);
    config(['partna.pre_account.ip_hash_key' => 'base64:'.base64_encode($raw)]);

    expect(PreAccountBuild::hashIp('1.2.3.4'))->toBe(hash_hmac('sha256', '1.2.3.4', $raw));
});

it('returns null rather than a constant digest when there is no IP', function () {
    // Staff-built rows have no visitor IP; a digest of '' would be a shared,
    // meaningless value that the per-IP build cap would then count together.
    expect(PreAccountBuild::hashIp(null))->toBeNull()
        ->and(PreAccountBuild::hashIp(''))->toBeNull()
        ->and(PreAccountBuild::hashIp('   '))->toBeNull();
});
