<?php

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use Illuminate\Support\Carbon;
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

it('casts claim_token_issued_at to a date', function () {
    $build = new PreAccountBuild;
    $build->forceFill(['claim_token_issued_at' => '2026-08-25 04:24:13']);

    expect($build->claim_token_issued_at)->toBeInstanceOf(Carbon::class);
});

// Regression guard, not a red-then-green test: $fillable already drops unknown
// keys, so this passes before the change too. It exists to fail loudly if
// someone later adds these columns to $fillable.
it('does not mass-assign the claim token columns', function () {
    $build = new PreAccountBuild([
        'claim_token_hash' => 'attacker-supplied',
        'claim_idempotency_key' => 'attacker-supplied',
    ]);

    expect($build->claim_token_hash)->toBeNull()
        ->and($build->claim_idempotency_key)->toBeNull();
});
