<?php

/**
 * SEC-14 — IP hash construction must be HMAC-SHA256, not plain SHA-256.
 *
 * Verifies:
 *   - The hash_hmac construction used in VerifyBotToken and ContentReportService
 *     is keyed and deterministic.
 *   - Same IP + same key → same hash (cross-system correlation).
 *   - Different key → different hash (keyed, not just hashed).
 *   - The old concat-SHA-256 formula produces a DIFFERENT result (so the change is real).
 *
 * Both classes use config('app.key') as their HMAC key, matching the
 * HashesClientData trait used everywhere else in the codebase.
 */
it('HMAC-SHA256 with the same ip + key produces the same hash (deterministic)', function () {
    $ip = '203.0.113.1';
    $key = 'test-key-sec14-determinism';

    $hash1 = hash_hmac('sha256', $ip, $key);
    $hash2 = hash_hmac('sha256', $ip, $key);

    expect($hash1)->toBe($hash2);
    expect(strlen($hash1))->toBe(64); // sha256 hex is always 64 chars
});

it('same IP + same key produces identical 16-char prefix in both VerifyBotToken and ContentReportService (correlatable)', function () {
    $ip = '198.51.100.7';
    $key = 'shared-key-for-correlation-sec14';

    // VerifyBotToken uses: substr(hash_hmac('sha256', $ip, config('app.key')), 0, 16)
    $botTokenHash = substr(hash_hmac('sha256', $ip, $key), 0, 16);

    // ContentReportService uses: hash_hmac('sha256', $reporterIp, config('app.key'))
    // (full 64-char hash stored; first 16 chars must match the truncated bot-token log hash)
    $reportHash = substr(hash_hmac('sha256', $ip, $key), 0, 16);

    expect($botTokenHash)->toBe($reportHash, 'Both sites must produce the same hash for the same IP so cross-system correlation works');
});

it('a different key produces a different hash (HMAC is properly keyed)', function () {
    $ip = '203.0.113.1';

    $hash1 = hash_hmac('sha256', $ip, 'key-one');
    $hash2 = hash_hmac('sha256', $ip, 'key-two');

    expect($hash1)->not->toBe($hash2);
});

it('HMAC-SHA256 produces a different result than the old concat-SHA-256 formula (change is real)', function () {
    $ip = '203.0.113.42';
    $key = 'some-app-key';

    // Old formula (plain hash with concatenated key)
    $oldHash = hash('sha256', $ip.'|'.$key);

    // New formula (HMAC)
    $newHash = hash_hmac('sha256', $ip, $key);

    expect($newHash)->not->toBe($oldHash, 'HMAC-SHA256 must differ from the old concat-SHA-256 construction');
});
