<?php

/**
 * #API-1 — UrlSafety is the single http/https scheme gate shared by the
 * write path (LinkBlockRequestHelpers::isAllowedScheme) and the emit paths
 * (SiteActionsService, SitepageDataResolverService). Pure function, no app
 * boot needed.
 */

use App\Support\UrlSafety;

it('gates non-http(s) input to null: scheme attacks, protocol-relative, relative, blank, non-string', function (mixed $input) {
    expect(UrlSafety::safeHref($input))->toBeNull();
})->with([
    'javascript scheme' => ['javascript:alert(1)'],
    'javascript scheme, mixed case' => ['JavaScript:alert(1)'],
    'data scheme' => ['data:text/html;base64,PHNjcmlwdD4='],
    'file scheme' => ['file:///etc/passwd'],
    'ftp scheme' => ['ftp://x/y'],
    'vbscript scheme' => ['vbscript:x'],
    'protocol-relative (scheme-less)' => ['//evil.com/x'],
    'relative path' => ['/relative/path'],
    'empty string' => [''],
    'whitespace only' => ['   '],
    'null' => [null],
    'non-string (int)' => [123],
]);

it('gates the same table to false via isAllowedScheme()', function (mixed $input) {
    expect(UrlSafety::isAllowedScheme((string) $input))->toBeFalse();
})->with([
    'javascript scheme' => ['javascript:alert(1)'],
    'javascript scheme, mixed case' => ['JavaScript:alert(1)'],
    'data scheme' => ['data:text/html;base64,PHNjcmlwdD4='],
    'file scheme' => ['file:///etc/passwd'],
    'ftp scheme' => ['ftp://x/y'],
    'vbscript scheme' => ['vbscript:x'],
    'protocol-relative (scheme-less)' => ['//evil.com/x'],
    'relative path' => ['/relative/path'],
    'empty string' => [''],
    'whitespace only' => ['   '],
]);

it('passes http/https through trimmed and otherwise unmodified — safeHref gates, it does not rewrite', function (string $input, string $expected) {
    expect(UrlSafety::safeHref($input))->toBe($expected);
    expect(UrlSafety::isAllowedScheme($input))->toBeTrue();
})->with([
    'plain http' => ['http://example.com/x', 'http://example.com/x'],
    'https, mixed-case scheme, host untouched' => ['HTTPS://Example.com/x', 'HTTPS://Example.com/x'],
    'leading/trailing whitespace trimmed only' => ['  https://example.com/x  ', 'https://example.com/x'],
]);
