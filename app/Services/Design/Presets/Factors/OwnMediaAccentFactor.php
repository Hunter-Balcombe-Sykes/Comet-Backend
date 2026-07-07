<?php

namespace App\Services\Design\Presets\Factors;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Design\Presets\SiteDesignFactor;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// Accent colour from the user's OWN uploaded square logo — the most literal
// brand signal we hold. Sits near the bottom of the hierarchy (20): the
// previous-website scan (100) reads the accent in real usage context and any
// category bucket (30–60) carries a curated palette; this factor only fills
// color_accent when nothing stronger claimed it, so a fresh account with just
// a logo still lands on-brand instead of package blue.
//
// The 192px icon variant (a few KB) is fetched over HTTP and dominant-colour
// sampled via GD; the result is cached against the variant URL so repeated
// resolves don't refetch. Low-saturation logos (black/white/grey marks)
// contribute nothing — a grey accent reads as broken, not branded.
class OwnMediaAccentFactor implements SiteDesignFactor
{
    public const SOURCE = 'own-media:accent';

    private const CACHE_TTL_SECONDS = 30 * 86400;

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    public function key(): string
    {
        return self::SOURCE;
    }

    public function integrationLabel(): string
    {
        return 'own-media';
    }

    public function priority(): int
    {
        return 20;
    }

    /** @return array<string, string> */
    public function detect(User $user, Site $site, Collection $activeConnections): array
    {
        // $activeConnections unused: this factor reads the design media pool.
        $media = SiteMedia::query()
            ->where('user_id', $user->id)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->where('purpose', SiteMedia::PURPOSE_LOGO_SQUARE)
            ->first();

        $iconUrl = $media?->iconVariantUrl();
        if (! is_string($iconUrl) || $iconUrl === '') {
            return [];
        }

        $hex = Cache::remember(
            'design:own-media-accent:'.md5($iconUrl),
            self::CACHE_TTL_SECONDS,
            fn () => $this->dominantSaturatedHex($iconUrl) ?? 'none',
        );

        return is_string($hex) && $hex !== 'none' ? ['color_accent' => $hex] : [];
    }

    /** Dominant sufficiently-saturated colour of the image at $url, or null. */
    private function dominantSaturatedHex(string $url): ?string
    {
        $response = $this->fetcher->tryFetch($url);
        $bytes = is_array($response) && ($response['status'] ?? 0) === 200
            ? ($response['body'] ?? '')
            : '';

        // The icon variant is a 192px PNG (a few KB); anything huge is not
        // the artifact we expect — bail rather than feed GD a surprise.
        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > 512 * 1024) {
            Log::info('own-media-accent fetch unusable', ['url' => $url]);

            return null;
        }

        return $this->extractAccentFromBytes($bytes);
    }

    /** GD pixel pipeline — protected so tests can drive it with raw bytes. */
    protected function extractAccentFromBytes(string $bytes): ?string
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;
        }

        // Downscale so the histogram is a few hundred pixels regardless of input.
        $sample = imagescale($img, 24, 24);
        imagedestroy($img);
        if ($sample === false) {
            return null;
        }

        $buckets = [];
        for ($y = 0; $y < 24; $y++) {
            for ($x = 0; $x < 24; $x++) {
                $rgba = imagecolorat($sample, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                if ($alpha > 32) {
                    continue; // mostly-transparent pixel — logo negative space
                }
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                $max = max($r, $g, $b);
                $min = min($r, $g, $b);
                $saturation = $max === 0 ? 0 : ($max - $min) / $max;
                $value = $max / 255;

                // Skip greys/near-white/near-black — they aren't brand accents.
                if ($saturation < 0.35 || $value < 0.2 || $value > 0.97) {
                    continue;
                }

                // Quantize to 32-step buckets so anti-aliased shades merge.
                $key = (intdiv($r, 32) << 10) | (intdiv($g, 32) << 5) | intdiv($b, 32);
                if (! isset($buckets[$key])) {
                    $buckets[$key] = ['n' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
                }
                $buckets[$key]['n']++;
                $buckets[$key]['r'] += $r;
                $buckets[$key]['g'] += $g;
                $buckets[$key]['b'] += $b;
            }
        }
        imagedestroy($sample);

        if ($buckets === []) {
            return null;
        }

        // Require the winning bucket to be a real presence (≥4% of pixels),
        // not a stray anti-aliasing artefact.
        usort($buckets, fn ($a, $b) => $b['n'] <=> $a['n']);
        $top = $buckets[0];
        if ($top['n'] < 24) {
            return null;
        }

        return sprintf(
            '#%02x%02x%02x',
            intdiv($top['r'], $top['n']),
            intdiv($top['g'], $top['n']),
            intdiv($top['b'], $top['n']),
        );
    }
}
