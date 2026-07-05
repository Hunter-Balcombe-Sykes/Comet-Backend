<?php

use App\Mail\Notifications\AccountDeletionCancelledMail;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

// #TEST-1: regression tripwire for the withoutMiddleware([EnforcePendingDeletionReadOnly::class])
// exclusion on POST /api/me/deletion/cancel (routes/api/user.php). Sibling tests exercise the
// middleware in isolation (ReadOnlyEnforcementTest) and the service in isolation (CancelDeletionTest),
// but neither drives a pending_deletion user through the REAL HTTP route + full middleware stack —
// so a future accidental removal of the withoutMiddleware() call would deadlock cancel and go unnoticed.
beforeEach(function () {
    AccountDeletionTestCase::boot();
    Mail::fake();
});

it('lets a pending_deletion user cancel through the real HTTP route without the read-only lock blocking it', function () {
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'cancel-route-'.substr($id, 0, 6),
        'handle_lc' => 'cancel-route-'.substr($id, 0, 6),
        'display_name' => 'Cancel Route Pro',
        'primary_email' => 'cancel-route-'.substr($id, 0, 6).'@example.com',
        'status' => 'pending_deletion',
        'deletion_previous_status' => 'active',
        'deletion_confirmed_at' => now()->toIso8601String(),
        'deletion_requested_at' => now()->subDay()->toIso8601String(),
    ]);
    $pro = User::query()->where('id', $id)->first();

    $response = actingAsUser($pro)->postJson('/api/me/deletion/cancel');

    // The read-only lock returns 423 for every other write route while
    // pending_deletion — this route must be the one exception.
    $response->assertStatus(200);
    expect($response->json('message'))
        ->toBe('Account deletion cancelled. Your account is active again.');

    $pro->refresh();
    expect($pro->status)->toBe('active')
        ->and($pro->deletion_previous_status)->toBeNull()
        ->and($pro->deletion_confirmed_at)->toBeNull()
        ->and($pro->deletion_requested_at)->toBeNull();

    Mail::assertSent(AccountDeletionCancelledMail::class);
});
