<?php

use App\Support\Concerns\MinimisesClientData;
use Tests\TestCase;

// Needs the Laravel app bound (hashClientIp reads config('app.key')) — Unit
// tests don't get TestCase by default (tests/Pest.php only auto-binds Feature).
uses(TestCase::class)->in(__FILE__);

function makeClientDataMinimiser(): object
{
    return new class
    {
        use MinimisesClientData;

        public function ipHash(?string $ip): ?string
        {
            return $this->hashClientIp($ip);
        }

        public function uaSummary(?string $ua): ?string
        {
            return $this->summariseUserAgent($ua);
        }
    };
}

it('B7/PRIV-1: hashes an IP to an HMAC-SHA256 digest, not the raw value', function () {
    $minimiser = makeClientDataMinimiser();

    $hash = $minimiser->ipHash('203.0.113.42');

    expect($hash)->not->toBe('203.0.113.42');
    expect($hash)->toBe(hash_hmac('sha256', '203.0.113.42', config('app.key')));
});

it('returns null for an empty/missing IP', function () {
    $minimiser = makeClientDataMinimiser();

    expect($minimiser->ipHash(null))->toBeNull();
    expect($minimiser->ipHash(''))->toBeNull();
});

it('B7/PRIV-1: summarises a User-Agent to browser family / platform, dropping the full string', function () {
    $minimiser = makeClientDataMinimiser();

    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    $summary = $minimiser->uaSummary($ua);

    expect($summary)->toBe('Chrome / macOS');
    expect($summary)->not->toContain('Mozilla');
    expect($summary)->not->toContain('AppleWebKit');
});

it('summarises an iOS Safari user agent correctly', function () {
    $minimiser = makeClientDataMinimiser();

    $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';

    expect($minimiser->uaSummary($ua))->toBe('iOS Safari / iOS');
});

it('returns null for an empty/missing User-Agent', function () {
    $minimiser = makeClientDataMinimiser();

    expect($minimiser->uaSummary(null))->toBeNull();
    expect($minimiser->uaSummary(''))->toBeNull();
});
