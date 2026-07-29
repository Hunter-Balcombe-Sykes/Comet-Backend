<?php

use App\Routing\IriCanonicalizer;
use App\Routing\SecretParams;

/**
 * #PRIV-5's isMinimisable()/minimiseUrl() tier, layered on top of the
 * existing isSecret()/redactUrl() this class already shipped for #SEC-1.
 * SecretParamsTest.php (unmodified by this change) is the proof that
 * extracting the shared redactWith() helper did not alter redactUrl()'s
 * behaviour — this file only tests the NEW predicate/entry-point.
 */
it('mirrors IriCanonicalizer::TRACKING_PARAMS verbatim — the two lists cannot drift apart', function () {
    $mirror = (new ReflectionClass(SecretParams::class))->getConstant('TRACKING_PARAMS_MIRROR');

    expect($mirror)->toBe(IriCanonicalizer::TRACKING_PARAMS);
});

it('flags direct-PII and third-party-tracking param names', function (string $key) {
    expect(SecretParams::isMinimisable($key, 'somevalue'))->toBeTrue();
})->with([
    'utm_content',
    'mc_eid',
    'fbclid',
    'email',
    'user_email',
    'contactPhone',
]);

it('never flags names that merely resemble a PII/tracking segment', function (string $key) {
    expect(SecretParams::isMinimisable($key, 'somevalue'))->toBeFalse();
})->with([
    'mailbox',
    'telemetry',
    'rid',
    'listing',
]);

it('never flags any PRESENTATION_PARAMS name — those are frequently load-bearing on a CDN URL', function () {
    $presentation = (new ReflectionClass(IriCanonicalizer::class))->getConstant('PRESENTATION_PARAMS');

    expect($presentation)->not->toBeEmpty();

    foreach ($presentation as $key) {
        expect(SecretParams::isMinimisable($key, 'somevalue'))->toBeFalse();
    }
});

it('still flags everything isSecret() already flags — isMinimisable() is a superset', function () {
    expect(SecretParams::isMinimisable('token', 'somevalue'))->toBeTrue()
        ->and(SecretParams::isMinimisable('rid', 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.x'))->toBeFalse();
});

it('preserves a signed CDN URL\'s load-bearing params verbatim — the anti-over-stripping assertion', function () {
    // Instagram (_nc_ht/oh/oe), Shopify (v), ytimg (sqp) signed-URL params.
    // A blanket "strip all query strings" remedy would 403 every one of
    // these; minimiseUrl() must leave them untouched.
    $url = 'https://scontent.cdninstagram.com/v/img.jpg?_nc_ht=scontent.cdninstagram.com&oh=abc123&oe=64FDE000&v=1&sqp=xyz';

    expect(SecretParams::minimiseUrl($url))->toBe($url);
});

it('redacts PII and tracking params in place while keeping a load-bearing identity param intact', function () {
    $url = 'https://x.com/p?utm_content=jane%40example.com&token=eyJa.eyJb.c&id=42';

    expect(SecretParams::minimiseUrl($url))
        ->toBe('https://x.com/p?utm_content=[redacted]&token=[redacted]&id=42');
});

it('passes null through unchanged', function () {
    expect(SecretParams::minimiseUrl(null))->toBeNull();
});

it('fails closed to an empty string on the 8 KB length guard', function () {
    $huge = str_repeat('?', 9000);

    expect(SecretParams::minimiseUrl($huge))->toBe('');
});

it('fails closed to an empty string, never the unredacted PII, when the PCRE engine errors', function () {
    // Reuses SecretParamsTest's proc_open SUBPROCESS technique — a
    // process-global ini_set() inside the shared test runner previously
    // crashed the whole suite (documented incident), so the pcre.backtrack_limit
    // mutation happens in a throwaway CLI subprocess instead, isolated from
    // every other test.
    $piiValue = 'jane'.str_repeat('a', 200).'@example.com';
    $autoload = realpath(__DIR__.'/../../../vendor/autoload.php');
    $script = sprintf(
        'require %s; $url = %s; echo json_encode(["result" => \App\Routing\SecretParams::minimiseUrl($url)]);',
        var_export($autoload, true),
        var_export('https://example.com/x?email='.$piiValue, true),
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

    expect($decoded['result'])->toBe('')
        ->and($decoded['result'])->not->toContain($piiValue);
});
