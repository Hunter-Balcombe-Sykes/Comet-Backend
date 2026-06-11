<?php

use App\Services\Moderation\DedupHashCalculator;

it('produces the same hash for the same inputs', function () {
    $calc = new DedupHashCalculator;
    $a = $calc->forReport('Site', '123', 'spam', 'reporter@example.com', null);
    $b = $calc->forReport('Site', '123', 'spam', 'reporter@example.com', null);
    expect($a)->toBe($b);
});

it('produces different hashes for different reasons', function () {
    $calc = new DedupHashCalculator;
    $spam = $calc->forReport('Site', '123', 'spam', 'r@e.com', null);
    $harassmnt = $calc->forReport('Site', '123', 'harassment', 'r@e.com', null);
    expect($spam)->not->toBe($harassmnt);
});

it('falls back to ip_hash when email is null', function () {
    $calc = new DedupHashCalculator;
    $h1 = $calc->forReport('Site', '123', 'spam', null, 'ip-hash-abc');
    $h2 = $calc->forReport('Site', '123', 'spam', null, 'ip-hash-abc');
    expect($h1)->toBe($h2);
});

it('produces different hashes for the same email but different targets', function () {
    $calc = new DedupHashCalculator;
    $h1 = $calc->forReport('Site', '111', 'spam', 'r@e.com', null);
    $h2 = $calc->forReport('Site', '222', 'spam', 'r@e.com', null);
    expect($h1)->not->toBe($h2);
});

it('produces hex digest of length 64 (sha256)', function () {
    $h = (new DedupHashCalculator)->forReport('Site', '1', 'spam', 'r@e.com', null);
    expect(strlen($h))->toBe(64);
    expect($h)->toMatch('/^[0-9a-f]+$/');
});

it('rejects when both email and ip_hash are null', function () {
    expect(fn () => (new DedupHashCalculator)->forReport('Site', '1', 'spam', null, null))
        ->toThrow(InvalidArgumentException::class);
});
