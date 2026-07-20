<?php

use App\Mail\Notifications\AccountDeletionCancelledMail;
use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Services\User\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
    Mail::fake();
});

function seedPendingDeletionUser(array $overrides = []): User
{
    $id = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'pro-'.substr($id, 0, 6),
        'handle_lc' => 'pro-'.substr($id, 0, 6),
        'display_name' => 'Pro',
        'primary_email' => 'pro-'.substr($id, 0, 6).'@example.com',
        'status' => 'pending_deletion',
        'deletion_previous_status' => 'active',
        'deletion_confirmed_at' => now()->toIso8601String(),
    ], $overrides);

    DB::connection('pgsql')->table('core.users')->insert($data);

    return User::query()->where('id', $id)->first();
}

it('restores previous status on cancel', function () {
    $pro = seedPendingDeletionUser(['deletion_previous_status' => 'active']);

    $service = new AccountDeletionService;
    $result = $service->cancel($pro, Request::create('/', 'POST'));

    expect($result['success'])->toBeTrue()
        ->and($result['code'])->toBe(200);

    $pro->refresh();
    expect($pro->status)->toBe('active')
        ->and($pro->deletion_previous_status)->toBeNull()
        ->and($pro->deletion_confirmed_at)->toBeNull()
        ->and($pro->deletion_requested_at)->toBeNull();
});

it('falls back to active when previous_status is null', function () {
    $pro = seedPendingDeletionUser(['deletion_previous_status' => null]);

    $service = new AccountDeletionService;
    $service->cancel($pro, Request::create('/', 'POST'));

    $pro->refresh();
    expect($pro->status)->toBe('active');
});

it('sends cancellation mail', function () {
    $pro = seedPendingDeletionUser();

    $service = new AccountDeletionService;
    $service->cancel($pro, Request::create('/', 'POST'));

    Mail::assertQueued(AccountDeletionCancelledMail::class, function ($mail) use ($pro) {
        return $mail->hasTo($pro->primary_email);
    });
});

it('writes cancelled audit event', function () {
    $pro = seedPendingDeletionUser();

    $service = new AccountDeletionService;
    $service->cancel($pro, Request::create('/', 'POST'));

    $audit = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('user_id', $pro->id)
        ->where('event', 'cancelled')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_type)->toBe(UserDeletionAuditEntry::ACTOR_TYPE_PROFESSIONAL);
});

it('re-publishes the site when cancelling a deletion that had programmatically unpublished it', function () {
    $pro = seedPendingDeletionUser();

    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $pro->id,
        'subdomain' => 'pro-'.$pro->id,
        'is_published' => 0,
        'unpublished_at' => now()->toIso8601String(),
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    $pro = User::query()->with('site')->find($pro->id);

    $service = app(AccountDeletionService::class);
    $service->cancel($pro, Request::create('/', 'POST'));

    $site = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->first();
    expect((bool) $site->is_published)->toBeTrue()
        ->and($site->unpublished_at)->toBeNull();
});

it('does not re-publish a site that was manually unpublished before deletion was cancelled', function () {
    $pro = seedPendingDeletionUser();

    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $pro->id,
        'subdomain' => 'pro-'.$pro->id,
        'is_published' => 0,
        'unpublished_at' => null, // manual unpublish — no unpublished_at set
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    $pro = User::query()->with('site')->find($pro->id);

    $service = app(AccountDeletionService::class);
    $service->cancel($pro, Request::create('/', 'POST'));

    $site = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->first();
    // Site stays offline — we don't own manually-unpublished state.
    expect((bool) $site->is_published)->toBeFalse();
});

it('leaves admin_notes null after cancel, pinning the accepted-loss semantics as deliberate (PRIV-102)', function () {
    $rawToken = 'raw-token-'.Str::random(54);
    $sentinel = 'SENTINEL-STAFF-NOTE-'.Str::random(12);
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'pro-'.substr($id, 0, 6),
        'handle_lc' => 'pro-'.substr($id, 0, 6),
        'display_name' => 'Pro',
        'primary_email' => 'pro-'.substr($id, 0, 6).'@example.com',
        'status' => 'active',
        'admin_notes' => $sentinel,
        'deletion_token_hash' => hash('sha256', $rawToken),
        'deletion_requested_at' => now()->toIso8601String(),
    ]);
    $pro = User::query()->where('id', $id)->first();

    $service = new AccountDeletionService;
    $confirmResult = $service->confirm($pro, $rawToken, Request::create('/', 'POST'));
    expect($confirmResult['success'])->toBeTrue();

    $pro->refresh();
    expect($pro->admin_notes)->toBeNull(); // pseudonymised at confirm time

    $service->cancel($pro, Request::create('/', 'POST'));

    $pro->refresh();
    // Cancel restores status/email but NOT admin_notes — an accepted, documented
    // tradeoff (see AccountDeletionService::pseudonymiseAccountPii docblock), not
    // a bug: the value was never preserved anywhere to restore it from.
    expect($pro->status)->toBe('active')
        ->and($pro->admin_notes)->toBeNull();
});
