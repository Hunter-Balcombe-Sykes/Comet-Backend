<?php

/**
 * Pure-logic unit tests for LogoAutoGrabber's private static-analysis helpers —
 * no DB, no HTTP, no container. upsizeUrl()/svgIsSafe() are invoked via
 * reflection on a constructor-less instance (they never touch $this->fetcher
 * or $this->uploads); icoLargestPngFrame() is public static so it's called
 * directly.
 */

use App\Services\Design\LogoAutoGrabber;

function upsize(string $url): ?string
{
    $grabber = (new ReflectionClass(LogoAutoGrabber::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($grabber, 'upsizeUrl');

    return $method->invoke($grabber, $url);
}

function svgSafe(string $svg): bool
{
    $grabber = (new ReflectionClass(LogoAutoGrabber::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($grabber, 'svgIsSafe');

    return $method->invoke($grabber, $svg);
}

/**
 * Builds a minimal .ico container: 6-byte header (reserved, type=1, count)
 * followed by 16-byte directory entries, followed by the frame bytes back to
 * back at increasing offsets — matching icoLargestPngFrame()'s expected layout.
 *
 * @param  list<array{width: int, bytes: string}>  $frames
 */
function buildIco(array $frames): string
{
    $count = count($frames);
    $header = pack('v3', 0, 1, $count);

    $offset = 6 + (16 * $count);
    $entries = '';
    $data = '';
    foreach ($frames as $frame) {
        $bytes = $frame['bytes'];
        $widthByte = $frame['width'] === 256 ? 0 : $frame['width']; // 0 encodes 256
        $entries .= pack('C4v2V2', $widthByte, 0, 0, 0, 0, 0, strlen($bytes), $offset);
        $data .= $bytes;
        $offset += strlen($bytes);
    }

    return $header.$entries.$data;
}

// ── upsizeUrl ────────────────────────────────────────────────────────────────

it('upsizes a Shopify CDN width param to 1024', function () {
    $url = 'https://cdn.shopify.com/s/files/1/0001/logo.png?width=64';

    expect(upsize($url))->toBe('https://cdn.shopify.com/s/files/1/0001/logo.png?width=1024');
});

it('upsizes a /cdn/shop/ path width+height regardless of host', function () {
    $url = 'https://example.com/cdn/shop/files/logo.png?width=48&height=48';

    expect(upsize($url))->toBe('https://example.com/cdn/shop/files/logo.png?width=1024&height=1024');
});

it('returns null for a Shopify URL with no width/height param to rewrite', function () {
    expect(upsize('https://cdn.shopify.com/s/files/1/logo.png'))->toBeNull();
});

it('upsizes Wix static w_/h_ path segments to 512', function () {
    $url = 'https://static.wixstatic.com/media/abc_fill/w_80,h_80/logo.png';

    expect(upsize($url))->toBe('https://static.wixstatic.com/media/abc_fill/w_512,h_512/logo.png');
});

it('upsizes Cloudinary w_/h_ path segments to 512', function () {
    $url = 'https://res.cloudinary.com/demo/image/upload/w_100,h_60/logo.png';

    expect(upsize($url))->toBe('https://res.cloudinary.com/demo/image/upload/w_512,h_512/logo.png');
});

it('upsizes a Squarespace format=Nw query param to 1500w', function () {
    $url = 'https://images.squarespace-cdn.com/content/v1/abc/logo.png?format=100w';

    expect(upsize($url))->toBe('https://images.squarespace-cdn.com/content/v1/abc/logo.png?format=1500w');
});

it('upsizes imgix w=/h= query params to 1024', function () {
    $url = 'https://mybrand.imgix.net/logo.png?w=50&h=50';

    expect(upsize($url))->toBe('https://mybrand.imgix.net/logo.png?w=1024&h=1024');
});

it('returns null for an imgix URL with no w=/h= param to rewrite', function () {
    expect(upsize('https://mybrand.imgix.net/logo.png'))->toBeNull();
});

it('returns null for an unrecognized CDN host', function () {
    expect(upsize('https://example.com/logo.png?width=64'))->toBeNull();
});

// ── icoLargestPngFrame ───────────────────────────────────────────────────────

it('returns the largest declared-width PNG frame among several PNG frames', function () {
    $sig = "\x89PNG\r\n\x1a\n";
    $small = $sig.'AAAAAA';
    $large = $sig.'BBBBBBBB';

    $ico = buildIco([
        ['width' => 16, 'bytes' => $small],
        ['width' => 64, 'bytes' => $large],
    ]);

    expect(LogoAutoGrabber::icoLargestPngFrame($ico))->toBe($large);
});

it('picks the largest PNG frame over a declared-bigger legacy BMP frame', function () {
    $sig = "\x89PNG\r\n\x1a\n";
    $png32 = $sig.'C';
    $png64 = $sig.'DDDD';
    $bmp128 = 'BM'.str_repeat('X', 20); // no PNG signature — legacy BMP frame

    $ico = buildIco([
        ['width' => 32, 'bytes' => $png32],
        ['width' => 64, 'bytes' => $png64],
        ['width' => 128, 'bytes' => $bmp128],
    ]);

    expect(LogoAutoGrabber::icoLargestPngFrame($ico))->toBe($png64);
});

it('returns null when every frame is legacy BMP (no PNG signature)', function () {
    $bmp = 'BM'.str_repeat('Z', 20);
    $ico = buildIco([['width' => 32, 'bytes' => $bmp]]);

    expect(LogoAutoGrabber::icoLargestPngFrame($ico))->toBeNull();
});

it('returns null for an empty string', function () {
    expect(LogoAutoGrabber::icoLargestPngFrame(''))->toBeNull();
});

it('returns null for a truncated 4-byte string (shorter than the header)', function () {
    expect(LogoAutoGrabber::icoLargestPngFrame('ABCD'))->toBeNull();
});

it('returns null when the declared count has no directory entries after it', function () {
    $header = pack('v3', 0, 1, 3); // count=3, but the string ends right after the header

    expect(LogoAutoGrabber::icoLargestPngFrame($header))->toBeNull();
});

it('returns null when a frame offset points past the end of the buffer', function () {
    $entry = pack('C4v2V2', 64, 0, 0, 0, 0, 0, 8, 9999); // offset 9999 is out of range
    $ico = pack('v3', 0, 1, 1).$entry;

    expect(LogoAutoGrabber::icoLargestPngFrame($ico))->toBeNull();
});

// ── svgIsSafe ────────────────────────────────────────────────────────────────

it('accepts a clean svg with no external references', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40"/></svg>';

    expect(svgSafe($svg))->toBeTrue();
});

it('accepts a local fragment xlink:href (#id)', function () {
    expect(svgSafe('<svg><use xlink:href="#icon"/></svg>'))->toBeTrue();
});

it('rejects an svg containing a <script> tag', function () {
    expect(svgSafe('<svg><script>alert(1)</script></svg>'))->toBeFalse();
});

it('rejects an svg with an on*= event handler attribute', function () {
    expect(svgSafe('<svg onload="alert(1)"><circle/></svg>'))->toBeFalse();
});

it('rejects an svg containing a <foreignObject>', function () {
    expect(svgSafe('<svg><foreignObject><div>x</div></foreignObject></svg>'))->toBeFalse();
});

it('rejects an svg embedding a data: URI', function () {
    expect(svgSafe('<svg><style>.a{fill:url(data:image/png;base64,ABC)}</style></svg>'))->toBeFalse();
});

it('rejects an svg with an external https href', function () {
    expect(svgSafe('<svg><use href="https://evil.com/x.svg#a"/></svg>'))->toBeFalse();
});

it('rejects an svg with a javascript: href', function () {
    expect(svgSafe('<svg><a href="javascript:alert(1)">click</a></svg>'))->toBeFalse();
});

it('rejects a string with no <svg> tag at all', function () {
    expect(svgSafe('<div>not svg</div>'))->toBeFalse();
});

it('rejects an svg containing an embedded <image> tag', function () {
    expect(svgSafe('<svg><image xlink:href="logo.png"/></svg>'))->toBeFalse();
});
