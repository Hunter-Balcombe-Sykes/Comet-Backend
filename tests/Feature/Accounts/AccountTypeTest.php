<?php

use App\Http\Requests\Api\BootstrapRequest;
use App\Http\Resources\UserDashboardResource;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function accountTypeUser(string $h, string $type = 'partna'): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => $type,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

// ── PATCH /me: the settings "change account type" flow ───────────────────────

it('switches account type from partna to business via PATCH /me', function () {
    $user = accountTypeUser('switcher', 'partna');

    actingAsUser($user)
        ->patchJson('/api/me', ['account_type' => 'business'])
        ->assertOk();

    expect($user->fresh()->account_type->value)->toBe('business');
});

it('rejects the legacy individual value on PATCH /me', function () {
    $user = accountTypeUser('legacy', 'partna');

    actingAsUser($user)
        ->patchJson('/api/me', ['account_type' => 'individual'])
        ->assertStatus(422);

    expect($user->fresh()->account_type->value)->toBe('partna');
});

// ── Signup: BootstrapRequest validates the chosen account type ───────────────

it('accepts business and omitted account_type but rejects individual at bootstrap', function () {
    $make = function (?string $type, string $email): BootstrapRequest {
        $payload = ['display_name' => 'Type Tester', 'primary_email' => $email];
        if ($type !== null) {
            $payload['account_type'] = $type;
        }
        $request = BootstrapRequest::create('/api/bootstrap', 'POST', $payload);
        $request->setContainer(app())->setRedirector(app('redirect'));

        return $request;
    };

    // business + omitted both validate cleanly.
    expect(fn () => $make('business', 'biz@example.com')->validateResolved())
        ->not->toThrow(ValidationException::class);
    expect(fn () => $make(null, 'none@example.com')->validateResolved())
        ->not->toThrow(ValidationException::class);

    // 'individual' is the legacy value — not a selectable type at signup.
    expect(fn () => $make('individual', 'indiv@example.com')->validateResolved())
        ->toThrow(ValidationException::class);
});

// ── /me exposes is_staff (independent of account_type) ───────────────────────
// Staff-ness lives in core.partna_staff, not account_type; the dashboard reads
// this flag to switch to the staff surface. Tested at the resource level (the
// UserSelfController controller sets the partnaStaff relation off a fresh
// lookup; UserDashboardResource projects it) — a full GET /me HTTP 200 drags in
// the whole tenant fan-out (services/customers/media/…), unrelated to is_staff.

it('projects is_staff=true when a partna_staff relation is present, account_type stays partna', function () {
    $user = accountTypeUser('staffalso', 'partna');
    // role is intentionally not $fillable on PartnaStaff — presence alone (the
    // relation being non-null) is what is_staff keys on, so an empty instance
    // stands in for "this user has a staff record".
    $user->setRelation('partnaStaff', new PartnaStaff);

    $data = (new UserDashboardResource($user))->resolve(request());

    expect($data['account_type'])->toBe('partna');
    expect($data['is_staff'])->toBeTrue();
});

it('projects is_staff=false when there is no partna_staff relation', function () {
    $user = accountTypeUser('plainuser', 'partna');
    $user->setRelation('partnaStaff', null);

    $data = (new UserDashboardResource($user))->resolve(request());

    expect($data['is_staff'])->toBeFalse();
});

// ── PATCH /me: display_name cap follows the business-name rule ────────────────

it('caps display_name at 15 chars for a business account (display name IS the business name)', function () {
    $user = accountTypeUser('bizdncap', 'business');

    actingAsUser($user)
        ->patchJson('/api/me', ['display_name' => 'Sixteen Char Nme!'])
        ->assertStatus(422);

    actingAsUser($user)
        ->patchJson('/api/me', ['display_name' => 'D.O.C Pizza'])
        ->assertOk();
});

it('keeps the roomy 255-char display_name for a partna account (personal names are long)', function () {
    $user = accountTypeUser('partnadn', 'partna');

    actingAsUser($user)
        ->patchJson('/api/me', ['display_name' => 'Bartholomew Montgomery-Featherstonehaugh III'])
        ->assertOk();
});
