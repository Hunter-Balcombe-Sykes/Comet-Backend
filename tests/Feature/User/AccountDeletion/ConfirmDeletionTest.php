<?php

use App\Mail\Notifications\AccountDeletionScheduledMail;
use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Services\User\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
    Mail::fake();
});

function seedRequestedProfessional(string $rawToken = 'a-raw-token-64-chars-long-for-testing-purposes-1234567890123456', array $overrides = []): User
{
    $id = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'pro-'.substr($id, 0, 6),
        'handle_lc' => 'pro-'.substr($id, 0, 6),
        'display_name' => 'Pro',
        'primary_email' => 'pro-'.substr($id, 0, 6).'@example.com',
        'status' => 'active',
        'stripe_manual_balance_cents' => 0,
        'deletion_token_hash' => hash('sha256', $rawToken),
        'deletion_requested_at' => now()->toIso8601String(),
    ], $overrides);

    DB::connection('pgsql')->table('core.users')->insert($data);

    return User::query()->where('id', $id)->first();
}

it('confirms with valid token: flips status, snapshots previous status, nulls token', function () {
    $rawToken = 'raw-token-'.Str::random(54);
    $pro = seedRequestedProfessional($rawToken);

    $service = new AccountDeletionService;
    $result = $service->confirm($pro, $rawToken, Request::create('/', 'POST'));

    expect($result['success'])->toBeTrue()
        ->and($result['code'])->toBe(200)
        ->and($result['deletes_at'])->not->toBeEmpty();

    $pro->refresh();
    expect($pro->status)->toBe('pending_deletion')
        ->and($pro->deletion_previous_status)->toBe('active')
        ->and($pro->deletion_token_hash)->toBeNull()
        ->and($pro->deletion_confirmed_at)->not->toBeNull();

    Mail::assertQueued(AccountDeletionScheduledMail::class);
});

it('rejects with 410 when token is older than 24 hours', function () {
    $rawToken = 'raw-token-'.Str::random(54);
    $pro = seedRequestedProfessional($rawToken, [
        'deletion_requested_at' => Carbon::now()->subHours(25)->toIso8601String(),
    ]);

    $service = new AccountDeletionService;
    $result = $service->confirm($pro, $rawToken, Request::create('/', 'POST'));

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(410);

    $pro->refresh();
    expect($pro->deletion_token_hash)->toBeNull()
        ->and($pro->deletion_requested_at)->toBeNull();
});

it('rejects with 404 when token does not match', function () {
    $rawToken = 'raw-token-'.Str::random(54);
    $pro = seedRequestedProfessional($rawToken);

    $service = new AccountDeletionService;
    $result = $service->confirm($pro, 'wrong-token', Request::create('/', 'POST'));

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(404);

    $pro->refresh();
    expect($pro->status)->toBe('active');
});

it('rejects with 404 when no deletion request exists', function () {
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'plain',
        'handle_lc' => 'plain',
        'display_name' => 'Plain',
        'primary_email' => 'plain@example.com',
        'status' => 'active',
    ]);
    $pro = User::query()->where('id', $id)->first();

    $service = new AccountDeletionService;
    $result = $service->confirm($pro, 'any-token', Request::create('/', 'POST'));

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(404);
});

it('pseudonymises PII columns at confirm time so live row is unreadable during grace window', function () {
    $rawToken = 'raw-token-'.Str::random(54);
    $pro = seedRequestedProfessional($rawToken, [
        'phone' => '+61400000000',
        'first_name' => 'Jane',
        'last_name' => 'Doer',
        'location_street_address' => '1 Test Lane',
        'location_postcode' => '2000',
        'location_city' => 'Sydney',
        'location_state' => 'NSW',
        'location_country' => 'AU',
    ]);
    $authUserId = (string) $pro->auth_user_id;
    $handle = (string) $pro->handle;
    $originalEmail = (string) $pro->primary_email;

    $service = new AccountDeletionService;
    $result = $service->confirm($pro, $rawToken, Request::create('/', 'POST'));

    expect($result['success'])->toBeTrue();

    $pro->refresh();

    // PII redacted to non-identifiable placeholders.
    expect($pro->phone)->toBe('redacted');
    expect($pro->first_name)->toBe('Deleted');
    expect($pro->last_name)->toBeNull();
    expect($pro->location_street_address)->toBeNull();
    expect($pro->location_postcode)->toBeNull();
    expect($pro->location_city)->toBeNull();
    expect($pro->location_state)->toBeNull();
    expect($pro->location_country)->toBeNull();
    // primary_email replaced with a deterministic placeholder so future Mail::to() calls
    // would noisily fail rather than silently exfiltrate to a real address.
    expect($pro->primary_email)
        ->not->toBe($originalEmail)
        ->toContain('@partna.au');

    // Recovery-window invariants: handle + auth_user_id must survive so a user
    // can cancel deletion within 30 days and resume their account.
    expect($pro->handle)->toBe($handle);
    expect((string) $pro->auth_user_id)->toBe($authUserId);

    // Audit row preserves the original email so support can re-identify the user.
    $audit = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('user_id', $pro->id)
        ->where('event', 'confirmed')
        ->first();
    expect($audit->professional_email_snapshot)->toBe($originalEmail);
});

it('cancel after confirm restores primary_email from audit snapshot for the cancel mail', function () {
    $rawToken = 'raw-token-'.Str::random(54);
    $pro = seedRequestedProfessional($rawToken);
    $originalEmail = (string) $pro->primary_email;

    $service = new AccountDeletionService;
    $service->confirm($pro, $rawToken, Request::create('/', 'POST'));

    $pro->refresh();
    expect($pro->primary_email)->not->toBe($originalEmail); // pseudonymised after confirm

    $service->cancel($pro, Request::create('/', 'POST'));

    $pro->refresh();
    expect($pro->primary_email)->toBe($originalEmail); // restored from audit snapshot

    // Cancel audit row carries the real email, not the placeholder.
    $cancelAudit = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('user_id', $pro->id)
        ->where('event', 'cancelled')
        ->first();
    expect($cancelAudit->professional_email_snapshot)->toBe($originalEmail);
});

it('writes confirmed audit event', function () {
    $rawToken = 'raw-token-'.Str::random(54);
    $pro = seedRequestedProfessional($rawToken);

    $service = new AccountDeletionService;
    $service->confirm($pro, $rawToken, Request::create('/', 'POST'));

    $audit = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('user_id', $pro->id)
        ->where('event', 'confirmed')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_type)->toBe(UserDeletionAuditEntry::ACTOR_TYPE_PROFESSIONAL);
});

it('unpublishes the site immediately when deletion is confirmed', function () {
    $rawToken = 'raw-token-'.Str::random(54);
    $pro = seedRequestedProfessional($rawToken);

    // Seed a published site for this professional.
    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $pro->id,
        'subdomain' => 'pro-'.$pro->id,
        'is_published' => 1,
        'unpublished_at' => null,
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    // Reload with site relation.
    $pro = User::query()->with('site')->find($pro->id);

    $service = app(AccountDeletionService::class);
    $result = $service->confirm($pro, $rawToken, Request::create('/', 'POST'));

    expect($result['success'])->toBeTrue();

    $site = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->first();
    expect((bool) $site->is_published)->toBeFalse()
        ->and($site->unpublished_at)->not->toBeNull();
});

it('pseudonymises professionals public_contact and about PII at confirm time', function () {
    $rawToken = 'raw-token-'.Str::random(54);
    $pro = seedRequestedProfessional($rawToken, [
        'public_contact_email' => 'public@example.com',
        'public_contact_number' => '+61400111222',
        'about' => '{"headline":"Test bio"}',
        'bio' => 'I am a real person',
    ]);

    $service = new AccountDeletionService;
    $service->confirm($pro, $rawToken, Request::create('/', 'POST'));

    $pro->refresh();

    expect($pro->public_contact_email)->toBeNull()
        ->and($pro->public_contact_number)->toBeNull()
        ->and($pro->bio)->toBeNull();
    // about cast as array — empty array after scrub
    expect($pro->about)->toBe([]);
});

it('rolls back the entire confirmation when pseudonymisation fails — status, audit row, and PII all reverted', function () {
    $rawToken = 'raw-token-'.Str::random(54);
    $pro = seedRequestedProfessional($rawToken, [
        'phone' => '+61400000000',
        'first_name' => 'Jane',
    ]);
    $originalEmail = (string) $pro->primary_email;

    // Subclass that simulates a DB error mid-pseudonymise (e.g., constraint
    // violation, connection drop). The bug we're guarding against:
    // executeConfirmation() committing status='pending_deletion' but leaving
    // live PII intact. The unified DB::transaction must roll back EVERYTHING.
    $service = new class extends AccountDeletionService
    {
        protected function pseudonymiseAccountPii(\App\Models\Core\User\User $professional): void
        {
            throw new \RuntimeException('Simulated DB failure mid-pseudonymise');
        }
    };

    expect(fn () => $service->confirm($pro, $rawToken, Request::create('/', 'POST')))
        ->toThrow(\RuntimeException::class);

    $pro->refresh();

    // Status NOT flipped — the whole transaction rolled back.
    expect($pro->status)->toBe('active')
        ->and($pro->deletion_previous_status)->toBeNull()
        ->and($pro->deletion_confirmed_at)->toBeNull()
        // PII unchanged — pseudonymise threw before it could overwrite.
        ->and($pro->primary_email)->toBe($originalEmail)
        ->and($pro->phone)->toBe('+61400000000')
        ->and($pro->first_name)->toBe('Jane');

    // No audit row persisted — logAuditEvent ran inside the transaction so it
    // rolls back with the rest. Without rollback, an EVENT_CONFIRMED audit
    // would exist for a user whose deletion never actually applied.
    $audit = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('user_id', $pro->id)
        ->where('event', 'confirmed')
        ->first();
    expect($audit)->toBeNull();
});
