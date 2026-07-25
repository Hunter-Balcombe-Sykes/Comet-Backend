<?php

use App\Services\Http\UrlAbsolutizer;

it('resolves a protocol-relative href against the base scheme', function () {
    expect(UrlAbsolutizer::absolutize('//cdn.example.com/logo.png', 'https://venue.example'))
        ->toBe('https://cdn.example.com/logo.png');
});

it('inherits http, not a hardcoded https, for a protocol-relative href', function () {
    expect(UrlAbsolutizer::absolutize('//cdn.example.com/logo.png', 'http://venue.example'))
        ->toBe('http://cdn.example.com/logo.png');
});

it('returns null for a protocol-relative href against a hostless base', function () {
    expect(UrlAbsolutizer::absolutize('//cdn/x', 'not-a-url'))->toBeNull();
});

it('preserves the base URL port', function () {
    expect(UrlAbsolutizer::absolutize('/a/b', 'https://venue.example:8443/menu'))
        ->toBe('https://venue.example:8443/a/b');
});

it('resolves relative to the origin root, not the base directory', function () {
    expect(UrlAbsolutizer::absolutize('assets/x.png', 'https://venue.example/deep/page'))
        ->toBe('https://venue.example/assets/x.png');
});

it('returns null for an empty href', function () {
    expect(UrlAbsolutizer::absolutize('', 'https://venue.example'))->toBeNull();
});

it('returns null for a data: href', function () {
    expect(UrlAbsolutizer::absolutize('data:image/png;base64,AAA', 'https://venue.example'))->toBeNull();
});

it('returns an already-absolute href verbatim', function () {
    expect(UrlAbsolutizer::absolutize('https://other/x', 'https://venue.example'))->toBe('https://other/x');
});

it('matches the https?:// scheme check case-insensitively', function () {
    expect(UrlAbsolutizer::absolutize('HTTPS://other/x', 'https://venue.example'))->toBe('HTTPS://other/x');
});

it('does not generically reject mailto: — that is the caller\'s job', function () {
    expect(UrlAbsolutizer::absolutize('mailto:a@b.com', 'https://venue.example'))
        ->toBe('https://venue.example/mailto:a@b.com');
});

it('resolves a bare fragment against the origin root', function () {
    expect(UrlAbsolutizer::absolutize('#frag', 'https://venue.example'))->toBe('https://venue.example/#frag');
});
