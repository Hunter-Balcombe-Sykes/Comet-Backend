<?php

/**
 * OV-A — public early-access endpoint: creates a waitlist row + queues the
 * thank-you mail, honeypot pretends success, repeat submissions refresh
 * waitlist rows but never downgrade invited/signed_up status.
 */

use App\Mail\EarlyAccess\EarlyAccessThankYouMail;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Services\EarlyAccess\EarlyAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
    // Uniform 200 (was 201): new-vs-existing must not be distinguishable by status.
    $this->postJson('/api/public/early-access', ovaEarlyAccessPayload())
        ->assertStatus(200)
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
        ->assertStatus(200)
        ->assertJson(['ok' => true]);

    expect(EarlyAccessSignup::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('refreshes fields on repeat waitlist submissions without a second email', function () {
    // Both submissions now return 200 (uniform status) — the second was already 200.
    $this->postJson('/api/public/early-access', ovaEarlyAccessPayload())->assertStatus(200);
    $this->postJson('/api/public/early-access', ovaEarlyAccessPayload(['type' => 'business', 'platforms' => ['square', 'google-business']]))
        ->assertStatus(200);

    $signup = EarlyAccessSignup::query()->where('email_lc', 'jess@example.test')->first();

    expect(EarlyAccessSignup::query()->count())->toBe(1)
        ->and($signup->type)->toBe('business')
        ->and($signup->platforms)->toBe(['square', 'google-business']);

    Mail::assertQueuedCount(1);
});

it('LIFE-1: absorbs a concurrent double-submit UniqueConstraintViolationException instead of 500ing', function () {
    // Root cause was an unguarded read-then-create on email_lc in
    // EarlyAccessService::signupFromMarketing — two requests racing the same email
    // both pass the pre-check SELECT, then the loser's INSERT 500s on
    // early_access_signups_email_lc_unique. firstOrCreate()'s internal createOrFirst
    // catches that UniqueConstraintViolationException and re-fetches instead.
    $emailLc = 'racer@example.test';
    $fired = false;

    // One-shot hook: the instant the service's own INSERT is about to run (i.e.
    // strictly AFTER its internal firstOrCreate() pre-check SELECT already ran and
    // found nothing), commit a rival row for the same email_lc on the SAME
    // connection — standing in for a second request winning the race. Guarded by
    // $fired (set before the rival insert) so this doesn't recurse on its own
    // write, and by an exact insert+table match so it never fires on the SELECTs.
    DB::connection('pgsql')->beforeExecuting(function ($query, $bindings, $connection) use (&$fired, $emailLc) {
        if ($fired || stripos($query, 'insert into') === false || ! str_contains($query, 'early_access_signups')) {
            return;
        }
        $fired = true;

        $connection->table('core.early_access_signups')->insert([
            'id' => (string) Str::uuid(),
            'email' => $emailLc,
            'email_lc' => $emailLc,
            'type' => 'partna',
            'workplace_or_industry' => null,
            'platforms' => json_encode([]),
            'status' => EarlyAccessSignup::STATUS_WAITLIST,
            'source' => 'marketing',
            'consent_ip_hash' => null,
            'consent_user_agent' => null,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    });

    $result = app(EarlyAccessService::class)->signupFromMarketing([
        'email' => $emailLc,
        'type' => 'business',
        'workplace_or_industry' => 'Studio',
        'platforms' => ['instagram', 'square'],
    ]);

    expect($fired)->toBeTrue('The rival insert never fired — the race was not actually exercised.')
        ->and($result['created'])->toBeFalse()
        ->and($result['signup']->email_lc)->toBe($emailLc)
        ->and(EarlyAccessSignup::query()->where('email_lc', $emailLc)->count())->toBe(1);

    // The race loser must not send a second thank-you — only the winning insert does.
    Mail::assertNothingQueued();
});

it('LIFE-1: is idempotent for a same-email different-case call straight through the service', function () {
    $emailLc = 'lower@example.test';
    $existing = EarlyAccessSignup::query()->create([
        'email' => $emailLc,
        'email_lc' => $emailLc,
        'type' => 'partna',
        'workplace_or_industry' => 'Studio',
        'platforms' => ['instagram'],
        'status' => EarlyAccessSignup::STATUS_WAITLIST,
        'source' => 'marketing',
    ]);

    $result = app(EarlyAccessService::class)->signupFromMarketing([
        'email' => 'LOWER@Example.Test',
        'type' => 'business',
        'workplace_or_industry' => 'New Studio',
        'platforms' => ['square', 'google-business'],
    ]);

    expect($result['created'])->toBeFalse()
        ->and($result['signup']->id)->toBe($existing->id)
        ->and(EarlyAccessSignup::query()->where('email_lc', $emailLc)->count())->toBe(1);

    Mail::assertNothingQueued();
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

it('treats an invite token older than the 14-day TTL as invalid', function () {
    $token = 'ExpiredToken000000000000000000000000000000000000';
    EarlyAccessSignup::query()->create([
        'email' => 'stale@example.test',
        'email_lc' => 'stale@example.test',
        'type' => 'partna',
        'status' => 'invited',
        'source' => 'manual',
        // Minted 15 days ago — one day past INVITE_TTL_DAYS.
        'invited_at' => now()->subDays(15),
        'invite_token_hash' => hash('sha256', $token),
    ]);

    // Model-level: the shared resolver refuses the expired token.
    expect(EarlyAccessSignup::findByInviteToken($token))->toBeNull();

    // Endpoint-level: no PII prefill, indistinguishable from unknown.
    $this->getJson('/api/public/early-access/invite/'.$token)
        ->assertStatus(404);

    // A row minted just inside the window still resolves.
    EarlyAccessSignup::query()->where('email_lc', 'stale@example.test')
        ->update(['invited_at' => now()->subDays(13)]);

    expect(EarlyAccessSignup::findByInviteToken($token)?->email_lc)->toBe('stale@example.test');
});
