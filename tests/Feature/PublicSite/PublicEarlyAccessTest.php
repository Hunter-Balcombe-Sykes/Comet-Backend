<?php

/**
 * OV-A — public early-access endpoint: creates a waitlist row + queues the
 * thank-you mail, honeypot pretends success, repeat submissions refresh
 * waitlist rows but never downgrade invited/signed_up status.
 */

use App\Mail\EarlyAccess\EarlyAccessThankYouMail;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    setupEarlyAccessTable();
    DB::connection('pgsql')->statement('DELETE FROM core.early_access_signups');
    Mail::fake();
});

function ovaEarlyAccessPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'jess@example.test',
        'type' => 'partna',
        'workplace_or_industry' => 'Hair Dresser',
        'platforms' => ['instagram', 'fresha'],
    ], $overrides);
}

it('creates a waitlist row and queues the thank-you email', function () {
    $this->postJson('/api/public/early-access', ovaEarlyAccessPayload())
        ->assertStatus(201)
        ->assertJson(['ok' => true]);

    $signup = EarlyAccessSignup::query()->where('email_lc', 'jess@example.test')->first();

    expect($signup)->not->toBeNull()
        ->and($signup->status)->toBe('waitlist')
        ->and($signup->source)->toBe('marketing')
        ->and($signup->type)->toBe('partna')
        ->and($signup->platforms)->toBe(['instagram', 'fresha']);

    Mail::assertQueued(EarlyAccessThankYouMail::class, fn ($mail) => $mail->recipientEmail === 'jess@example.test');
});

it('validates type and the 2-3 platforms requirement', function () {
    $this->postJson('/api/public/early-access', ovaEarlyAccessPayload(['type' => 'individual']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['type']);

    $this->postJson('/api/public/early-access', ovaEarlyAccessPayload(['platforms' => ['instagram']]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['platforms']);

    $this->postJson('/api/public/early-access', ovaEarlyAccessPayload(['platforms' => ['a', 'b', 'c', 'd']]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['platforms']);
});

it('pretends success on honeypot hits without writing a row', function () {
    $this->postJson('/api/public/early-access', ovaEarlyAccessPayload(['website' => 'http://spam.example']))
        ->assertStatus(201)
        ->assertJson(['ok' => true]);

    expect(EarlyAccessSignup::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('refreshes fields on repeat waitlist submissions without a second email', function () {
    $this->postJson('/api/public/early-access', ovaEarlyAccessPayload())->assertStatus(201);
    $this->postJson('/api/public/early-access', ovaEarlyAccessPayload(['type' => 'business', 'platforms' => ['square', 'google-business']]))
        ->assertStatus(200);

    $signup = EarlyAccessSignup::query()->where('email_lc', 'jess@example.test')->first();

    expect(EarlyAccessSignup::query()->count())->toBe(1)
        ->and($signup->type)->toBe('business')
        ->and($signup->platforms)->toBe(['square', 'google-business']);

    Mail::assertQueuedCount(1);
});

it('never downgrades an invited row back to waitlist state', function () {
    EarlyAccessSignup::query()->create([
        'email' => 'jess@example.test',
        'email_lc' => 'jess@example.test',
        'type' => 'business',
        'status' => 'invited',
        'source' => 'manual',
        'invited_at' => now(),
        'invite_token_hash' => hash('sha256', 'tok'),
    ]);

    $this->postJson('/api/public/early-access', ovaEarlyAccessPayload(['type' => 'partna']))
        ->assertStatus(200);

    $signup = EarlyAccessSignup::query()->where('email_lc', 'jess@example.test')->first();

    expect($signup->status)->toBe('invited')
        ->and($signup->type)->toBe('business') // fields frozen once invited
        ->and($signup->invite_token_hash)->not->toBeNull();

    Mail::assertNothingQueued();
});

it('resolves a valid invite token to its prefill payload and 404s otherwise', function () {
    $token = 'AbCdEf123456AbCdEf123456AbCdEf123456AbCdEf123456';
    EarlyAccessSignup::query()->create([
        'email' => 'invited@example.test',
        'email_lc' => 'invited@example.test',
        'type' => 'business',
        'workplace_or_industry' => 'Cafe',
        'platforms' => ['google-business', 'instagram'],
        'status' => 'invited',
        'source' => 'manual',
        'invited_at' => now(),
        'invite_token_hash' => hash('sha256', $token),
    ]);

    $this->getJson('/api/public/early-access/invite/'.$token)
        ->assertStatus(200)
        ->assertJson([
            'invite' => [
                'email' => 'invited@example.test',
                'type' => 'business',
                'workplace_or_industry' => 'Cafe',
                'platforms' => ['google-business', 'instagram'],
            ],
        ]);

    $this->getJson('/api/public/early-access/invite/UnknownToken123')
        ->assertStatus(404);

    // Consumed tokens (signed_up) stop resolving — no enumeration signal.
    EarlyAccessSignup::query()->where('email_lc', 'invited@example.test')
        ->update(['status' => 'signed_up']);

    $this->getJson('/api/public/early-access/invite/'.$token)
        ->assertStatus(404);
});
