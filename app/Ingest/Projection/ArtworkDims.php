<?php

namespace App\Ingest\Projection;

/**
 * Declared dimensions for music-CDN artwork URLs that encode their size in the
 * path. PoolResolver::cover() picks the largest KNOWN area across sources
 * (W5 best-cover ruling); without dims every music cover was "unknown" and
 * the pick fell back to source priority — a 640px Spotify cover beat Apple's
 * 1200px art on every merged release (session 3, Men I Trust). These are
 * 'declared' dims (ProjectionWriter stamps dims_confidence accordingly), the
 * same standing as a connector's own width/height claim.
 */
final class ArtworkDims
{
    /** @return array{int, int}|null [width, height] */
    public static function infer(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        // Apple: …/artwork.jpg/1200x1200bb.jpg (any WxH the rewrite asked for).
        if (str_ends_with($host, 'mzstatic.com') && preg_match('~/(\d{2,4})x(\d{2,4})(?:bb|cc|sr|bf|-\d+)?\.(?:jpe?g|png|webp)$~i', $path, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        // Spotify: /image/ab67616d0000b273… = 640, …1e02… = 300, …4851… = 64
        // (album art); ab6761610000e5eb / 5174 / f178 are the artist sizes.
        if ($host === 'i.scdn.co') {
            foreach (['ab67616d0000b273' => 640, 'ab67616d00001e02' => 300, 'ab67616d00004851' => 64,
                'ab6761610000e5eb' => 640, 'ab67616100005174' => 320, 'ab6761610000f178' => 160] as $prefix => $edge) {
                if (str_contains($path, $prefix)) {
                    return [$edge, $edge];
                }
            }
        }
        // Bandcamp: a{id}_{format}.jpg — 10 = 1200, 16 = 700, 5 = 700, 2 = 350, 7 = 150, 3 = 100.
        if (str_ends_with($host, 'bcbits.com') && preg_match('~/img/a\d+_(\d+)\.(?:jpe?g|png)$~', $path, $m)) {
            $edge = [10 => 1200, 16 => 700, 5 => 700, 2 => 350, 7 => 150, 3 => 100][(int) $m[1]] ?? null;

            return $edge === null ? null : [$edge, $edge];
        }
        // SoundCloud: artworks-…-t500x500.jpg / -large.jpg (100) / -t300x300.jpg
        if (str_ends_with($host, 'sndcdn.com')) {
            if (preg_match('~-t(\d{2,4})x(\d{2,4})\.(?:jpe?g|png)$~', $path, $m)) {
                return [(int) $m[1], (int) $m[2]];
            }
            if (preg_match('~-(large|crop|badge|small|tiny|mini|original)\.(?:jpe?g|png)$~', $path, $m)) {
                $edge = ['large' => 100, 'crop' => 400, 'badge' => 47, 'small' => 32, 'tiny' => 20, 'mini' => 16][$m[1]] ?? null;

                return $edge === null ? null : [$edge, $edge];
            }
        }

        return null;
    }
}
