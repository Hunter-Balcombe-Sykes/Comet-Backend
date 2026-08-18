<?php

use App\Ingest\Projection\ArtworkDims;

it('reads the size music CDNs encode in the artwork path', function (string $url, ?array $dims) {
    expect(ArtworkDims::infer($url))->toBe($dims);
})->with([
    'apple 1200' => ['https://is1-ssl.mzstatic.com/image/thumb/Music211/v4/66/2d/f5/662df515/artwork.jpg/1200x1200bb.jpg', [1200, 1200]],
    'apple 100' => ['https://is1-ssl.mzstatic.com/image/thumb/Music211/v4/66/2d/f5/662df515/artwork.jpg/100x100bb.jpg', [100, 100]],
    'spotify 640' => ['https://i.scdn.co/image/ab67616d0000b273e39911ac44a41ac82d44fa89', [640, 640]],
    'spotify 300' => ['https://i.scdn.co/image/ab67616d00001e02e39911ac44a41ac82d44fa89', [300, 300]],
    'bandcamp _10' => ['https://f4.bcbits.com/img/a0128806043_10.jpg', [1200, 1200]],
    'bandcamp _2' => ['https://f4.bcbits.com/img/a0128806043_2.jpg', [350, 350]],
    'soundcloud t500' => ['https://i1.sndcdn.com/artworks-TorlprCiNdiS-0-t500x500.jpg', [500, 500]],
    'soundcloud large' => ['https://i1.sndcdn.com/artworks-TorlprCiNdiS-0-large.jpg', [100, 100]],
    'unknown host' => ['https://example.com/img/1200x1200bb.jpg', null],
    'apple no size' => ['https://is1-ssl.mzstatic.com/image/thumb/Music211/artwork.jpg', null],
]);
