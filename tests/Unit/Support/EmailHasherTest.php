<?php

use App\Support\EmailHasher;
use Tests\TestCase;

// Needs the Laravel app booted for config('app.key') — opt this Unit file in.
uses(TestCase::class)->in(__FILE__);

it('matches the legacy SupabaseEmailEventService hashing scheme (WHK-3 row correlation)', function () {
    $email = 'hashcheck@partna.au';

    // The exact scheme WHK-3 stored rows with — asserted verbatim in
    // SupabaseEmailHookTest: HMAC-SHA256 of the lowercased email, app.key pepper.
    // If EmailHasher diverges, suppression lookups never match existing rows.
    $legacy = hash_hmac('sha256', strtolower($email), config('app.key'));

    expect(EmailHasher::hash($email))->toBe($legacy);
});

it('returns null for null, empty, and whitespace-only input', function () {
    expect(EmailHasher::hash(null))->toBeNull();
    expect(EmailHasher::hash(''))->toBeNull();
    expect(EmailHasher::hash('   '))->toBeNull();
});

it('is case-insensitive and trims surrounding whitespace', function () {
    expect(EmailHasher::hash('Tobias@Example.com'))->toBe(EmailHasher::hash('tobias@example.com'));
    expect(EmailHasher::hash('  a@b.com  '))->toBe(EmailHasher::hash('a@b.com'));
});

it('produces a 64-char hex digest', function () {
    $h = EmailHasher::hash('x@y.com');

    expect(strlen((string) $h))->toBe(64)
        ->and(ctype_xdigit((string) $h))->toBeTrue();
});
