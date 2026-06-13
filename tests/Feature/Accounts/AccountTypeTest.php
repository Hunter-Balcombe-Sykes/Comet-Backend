<?php

use App\Http\Requests\Api\BootstrapRequest;
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
