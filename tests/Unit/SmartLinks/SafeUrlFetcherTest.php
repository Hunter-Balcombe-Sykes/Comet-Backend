<?php

use App\Services\SmartLinks\SafeUrlException;
use App\Services\SmartLinks\SafeUrlFetcher;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('rejects loopback IPv4', function () {
    expect(fn () => app(SafeUrlFetcher::class)->fetch('http://127.0.0.1/'))
        ->toThrow(SafeUrlException::class);
});

it('rejects the cloud metadata endpoint', function () {
    expect(fn () => app(SafeUrlFetcher::class)->fetch('http://169.254.169.254/latest/meta-data/'))
        ->toThrow(SafeUrlException::class);
});

it('rejects private RFC1918 ranges', function () {
    expect(fn () => app(SafeUrlFetcher::class)->fetch('http://10.0.0.5/x'))
        ->toThrow(SafeUrlException::class);
    expect(fn () => app(SafeUrlFetcher::class)->fetch('http://192.168.1.1/x'))
        ->toThrow(SafeUrlException::class);
});

it('rejects loopback IPv6', function () {
    expect(fn () => app(SafeUrlFetcher::class)->fetch('http://[::1]/'))
        ->toThrow(SafeUrlException::class);
});

it('rejects non-http(s) schemes', function () {
    expect(fn () => app(SafeUrlFetcher::class)->fetch('ftp://example.com/x'))
        ->toThrow(SafeUrlException::class);
    expect(fn () => app(SafeUrlFetcher::class)->fetch('file:///etc/passwd'))
        ->toThrow(SafeUrlException::class);
});
