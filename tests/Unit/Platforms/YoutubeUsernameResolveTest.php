<?php

use App\Services\Platforms\LinkRouter;
use Tests\TestCase;

// The container is needed: socialUsername() resolves PlatformRegistry and
// YoutubeScraper out of it rather than taking them as constructor args (the
// trait is shared by three classes with unrelated constructors).
uses(TestCase::class)->in(__FILE__);

// F6, 2026-08-31. Two live youtube connections carry
// {"url":"https://youtube.com/@adriannewalujo.o","username":""} and have thrown
// `missing_key: handle` on every scheduled refresh since — 20 exceptions in two
// days (Nightwatch #476). YoutubeFetch.php:26 already named this a WRITE defect
// and refuses to paper over it; these pin the write side.
//
// socialUsername() and resolveWrite() are protected trait methods; reach them
// through LinkRouter, which uses the trait unmodified (LinkRouter.php:32).

function ytUsername(string $url): mixed
{
    $m = new ReflectionMethod(LinkRouter::class, 'socialUsername');

    return $m->invoke(app(LinkRouter::class), 'youtube', $url);
}

/** @return array<string,mixed> */
function ytWritePayload(string $url): array
{
    $m = new ReflectionMethod(LinkRouter::class, 'resolveWrite');

    return $m->invoke(app(LinkRouter::class), 'youtube', $url)['payload'];
}

it('resolves a handle that contains a dot', function () {
    // adriannewalujo.o — the dot is legal in a @handle, and the router stored "".
    expect(ytUsername('https://youtube.com/@adriannewalujo.o'))->toBe('adriannewalujo.o');
});

it('resolves a handle carrying a share parameter', function () {
    expect(ytUsername('https://youtube.com/@themarshallartschannel?si=PnzjFPB0GG1r7yzr'))
        ->toBe('themarshallartschannel');
});

it('resolves the other channel shapes the scraper already understood', function (string $url, string $expected) {
    expect(ytUsername($url))->toBe($expected);
})->with([
    'channel id' => ['https://www.youtube.com/channel/UCX6OQ3DkcsbYNE6H8uQQuVA', 'UCX6OQ3DkcsbYNE6H8uQQuVA'],
    'legacy /c/ vanity' => ['https://www.youtube.com/c/MrBeast', 'MrBeast'],
    'legacy /user/ vanity' => ['https://www.youtube.com/user/PewDiePie', 'PewDiePie'],
    'bare vanity' => ['https://www.youtube.com/MrBeast', 'MrBeast'],
]);

it('returns null rather than an empty string when there is no handle', function (string $url) {
    expect(ytUsername($url))->toBeNull();
})->with([
    'bare host' => ['https://youtube.com/'],
    'at sign with nothing after it' => ['https://youtube.com/@'],
    'a video is not a channel' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
    'a short is not a channel' => ['https://youtube.com/shorts/abc123'],
    'youtu.be short link' => ['https://youtu.be/dQw4w9WgXcQ'],
]);

it('writes the resolved handle into the payload', function () {
    expect(ytWritePayload('https://youtube.com/@adriannewalujo.o'))
        ->toMatchArray(['username' => 'adriannewalujo.o', 'url' => 'https://youtube.com/@adriannewalujo.o']);
});

it('omits the username key entirely rather than writing an empty one', function () {
    // "" is the worst value available: YoutubeFetch throws missing_key: handle
    // on it forever, and the row looks connected. No key at all is a row the
    // reader can fail loudly and recoverably on.
    expect(ytWritePayload('https://www.youtube.com/watch?v=dQw4w9WgXcQ'))
        ->not->toHaveKey('username');
});
