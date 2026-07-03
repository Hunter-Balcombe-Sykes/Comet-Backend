<?php

// A literal public IP (8.8.8.8) bypasses assertSafe()'s DNS resolution → hermetic
// (matches the Plan 4 SafeUrlFetcher test convention). Http::fake stubs the GET.

use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Http;

it('surfaces ETag and Last-Modified from a 200 response', function () {
    Http::fake(['8.8.8.8/*' => Http::response('ok', 200, [
        'ETag' => '"v1"',
        'Last-Modified' => 'Wed, 21 Oct 2026 07:28:00 GMT',
        'Content-Type' => 'text/html',
    ])]);

    $res = app(SafeUrlFetcher::class)->fetch('https://8.8.8.8/x');

    expect($res['status'])->toBe(200)
        ->and($res['etag'])->toBe('"v1"')
        ->and($res['lastModified'])->toBe('Wed, 21 Oct 2026 07:28:00 GMT');
});

it('returns a 304 cleanly (terminal, not treated as a redirect) with its ETag', function () {
    Http::fake(['8.8.8.8/*' => Http::response('', 304, ['ETag' => '"v1"'])]);

    $res = app(SafeUrlFetcher::class)->fetch('https://8.8.8.8/x', ['If-None-Match' => '"v1"']);

    expect($res['status'])->toBe(304)
        ->and($res['body'])->toBe('')
        ->and($res['etag'])->toBe('"v1"');
});

it('reports null validators when the response carries none', function () {
    Http::fake(['8.8.8.8/*' => Http::response('ok', 200, ['Content-Type' => 'text/plain'])]);

    $res = app(SafeUrlFetcher::class)->fetch('https://8.8.8.8/x');

    expect($res['etag'])->toBeNull()->and($res['lastModified'])->toBeNull();
});
