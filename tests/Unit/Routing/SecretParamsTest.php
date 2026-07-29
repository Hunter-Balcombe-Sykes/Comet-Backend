<?php

use App\Routing\SecretParams;

it('flags secret-shaped keys by segment', function (string $key) {
    expect(SecretParams::isSecret($key, 'somevalue'))->toBeTrue();
})->with([
    'token',
    'access_token',
    'accessToken',
    'auth_token_v2',
    'X-Api-Key',
    'apiKey',
    'sessionId',
    'sig',
    'signature',
]);

it('never flags names that merely contain a secret segment as a substring', function (string $key) {
    expect(SecretParams::isSecret($key, 'somevalue'))->toBeFalse();
})->with([
    'owner',
    'rid',
    'accountid',
    'venueid',
    'keyword',
    'resident',
    'monkey',
    'is_from_webapp',
    'id',
    'v',
    'variant',
]);

it('flags a JWT-shaped value regardless of the key name', function () {
    expect(SecretParams::isSecret('state', 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.x'))->toBeTrue();
});

it('flags a known vendor secret prefix regardless of the key name', function () {
    expect(SecretParams::isSecret('ref', 'sk_live_abc123'))->toBeTrue();
});

it('does not flag a long opaque-looking value with no secret shape', function () {
    // Pins the no-entropy-heuristic decision: a benign base64-ish resource id
    // must survive, or real links silently break.
    expect(SecretParams::isSecret('listing', 'aGVsbG8gd29ybGQgdGhpcw'))->toBeFalse();
});

it('lets the identity allowlist override even a JWT-shaped value', function () {
    expect(SecretParams::isSecret('rid', 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.x'))->toBeFalse();
});

it('redacts values in place and keeps the keys', function () {
    expect(SecretParams::redactUrl('https://example.com/x?rid=123&token=eyJabc'))
        ->toBe('https://example.com/x?rid=123&token=[redacted]');
});

it('redacts a fragment token an OAuth implicit flow would leave behind', function () {
    expect(SecretParams::redactUrl('https://x.com/cb#access_token=eyJhead.eyJib.sig'))
        ->toBe('https://x.com/cb#access_token=[redacted]');
});

it('redacts inside a prose-wrapped paste without leaking the secret', function () {
    // The pair-scanning regex has no notion of sentence punctuation, so a
    // trailing "." glued onto the value is consumed as part of it — the
    // guarantee here is graceful degradation (no crash, no leak), not
    // byte-perfect prose preservation.
    $result = SecretParams::redactUrl('Check this out: https://x.com/cb?token=abc123. Thanks!');

    expect($result)->toContain('https://x.com/cb?token=[redacted]')
        ->and($result)->not->toContain('abc123');
});

it('redacts inside an angle-bracket-wrapped paste without leaking the secret', function () {
    $result = SecretParams::redactUrl('<https://x.com/cb?token=abc123>,');

    expect($result)->toContain('https://x.com/cb?token=[redacted]')
        ->and($result)->not->toContain('abc123');
});

it('leaves a bare non-URL string untouched', function () {
    expect(SecretParams::redactUrl('just some text with no query pairs'))
        ->toBe('just some text with no query pairs');
});

it('passes null through unchanged', function () {
    expect(SecretParams::redactUrl(null))->toBeNull();
});

it('rejects an oversized string outright instead of running the pair-scanning regex against it', function () {
    // A string packed with bare separators and no '=' makes the regex
    // re-attempt its backtrack search from every separator — quadratic in
    // the number of separators, observed at ~60s for a 20MB string. The
    // length guard must reject before the regex ever runs.
    $huge = str_repeat('?', 9000);

    expect(SecretParams::redactUrl($huge))->toBe('');
});

it('still redacts a normal URL comfortably under the length guard', function () {
    $url = 'https://example.com/x?rid=123&token='.str_repeat('a', 100);

    expect(SecretParams::redactUrl($url))
        ->toBe('https://example.com/x?rid=123&token=[redacted]');
});

it('fails closed to an empty string, never the unredacted secret, when the PCRE engine errors (#SEC-1)', function () {
    // preg_replace_callback() returns null (not an exception) on a PCRE
    // engine error. The only reliable way to force that for THIS regex is
    // to lower pcre.backtrack_limit — but doing that via ini_set() inside
    // the shared test process is exactly what crashed the whole runner in
    // tests/Unit/Platforms/GenericShopScraperTest.php ("fix-round P4"):
    // restoring the ini value in a finally isn't enough, because OTHER
    // regexes (framework internals, deprecation handlers, Pest's own
    // machinery) run inside the same mutated window and engine-error too,
    // cascading into a fatal error that isn't contained to this test.
    //
    // So the ini mutation happens in a throwaway CLI subprocess instead —
    // `php -d pcre.backtrack_limit=1`, scoped to that one process only.
    // Nothing in the shared test-runner process is ever touched: true
    // isolation, not a restore-and-hope. This also organically proves the
    // reviewer's finding — with default settings this regex "auto-
    // possessifies" and cannot be made to return null from input alone.
    $secretValue = str_repeat('a', 200);
    $autoload = realpath(__DIR__.'/../../../vendor/autoload.php');
    $script = sprintf(
        'require %s; $url = %s; echo json_encode(["result" => \App\Routing\SecretParams::redactUrl($url)]);',
        var_export($autoload, true),
        var_export('https://example.com/x?token='.$secretValue, true),
    );

    $process = proc_open(
        [PHP_BINARY, '-d', 'pcre.backtrack_limit=1', '-r', $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname($autoload, 2),
    );

    expect($process)->not->toBeFalse();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    expect($exitCode)->toBe(0, "subprocess failed (stderr: {$stderr})");

    $decoded = json_decode($stdout, true);
    expect($decoded)->toBeArray()->and($decoded['result'] ?? null)->not->toBeNull();

    // The actual assertion: fails CLOSED (empty), never leaks the secret.
    expect($decoded['result'])->toBe('')
        ->and($decoded['result'])->not->toContain($secretValue);
});
