<?php

namespace App\Services\Design;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Auto-populates EMPTY logo slots from a previous-website's brand-scan
 * candidates (analysis logo.candidates, WebsiteStyleAnalyzer v2). Fills, never
 * replaces: a slot with any active media — user-uploaded or previously
 * auto-grabbed — is left alone, and nothing is ever deleted.
 *
 * v2 sourcing (v1 was favicon/apple-touch/og only):
 *  - the RENDERED header logo (img currentSrc / inline <svg>) is the premier
 *    source — it's the mark the brand actually shows;
 *  - icon links (largest declared size), apple-touch, manifest icons;
 *  - og:image/twitter:image demoted to last resort (usually a share card).
 *  - known-CDN URLs are size-UPGRADED before download (Shopify width=,
 *    Wix w_/h_, imgix/generic w=/h=, Squarespace format=) — tiny declared
 *    variants often front a full-resolution original.
 *  - SVG (inline or file) is accepted when the logo pipeline is enabled: the
 *    processor rasterizes it and stores the sanitized source as the vector.
 *
 * Every candidate's outcome is returned (and persisted onto the analysis by
 * the calling job) so "why did it grab THAT" is always answerable.
 */
class LogoAutoGrabber
{
    private const SQUARE_MIN_PX = 96;

    private const SQUARE_ASPECT = [0.75, 1.33];

    private const FULL_MIN_W = 240;

    private const FULL_MIN_H = 80;

    private const FULL_ASPECT_MIN = 1.4;

    private const SVG_MAX_BYTES = 512 * 1024;

    private const MAX_DOWNLOAD_BYTES = 8 * 1024 * 1024;

    private const ALLOWED_MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /** Rejections: scripts/handlers, foreignObject, embedded rasters, any external/data ref. */
    private const SVG_FORBIDDEN = '/<\s*script|<\s*foreignobject|\bon[a-z]+\s*=|javascript:|<\s*image\b|data:/i';

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly MediaUploadService $uploads,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $candidates  analysis logo.candidates (v2)
     * @return list<array{slot: string, kind: string, ref: string, outcome: string}> decisions, uploaded-first
     */
    public function grabIfEmpty(User $pro, Site $site, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $occupied = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->whereIn('purpose', [SiteMedia::PURPOSE_LOGO_FULL, SiteMedia::PURPOSE_LOGO_SQUARE])
            ->where('is_active', true)
            ->pluck('purpose')
            ->all();

        $squareEmpty = ! in_array(SiteMedia::PURPOSE_LOGO_SQUARE, $occupied, true);
        $fullEmpty = ! in_array(SiteMedia::PURPOSE_LOGO_FULL, $occupied, true);
        if (! $squareEmpty && ! $fullEmpty) {
            return [];
        }

        $ranked = $this->rank($candidates);

        $decisions = [];
        if ($fullEmpty) {
            $this->attemptSlot($pro, $site, SiteMedia::PURPOSE_LOGO_FULL, $ranked, $decisions);
        }
        if ($squareEmpty) {
            $this->attemptSlot($pro, $site, SiteMedia::PURPOSE_LOGO_SQUARE, $ranked, $decisions);
        }

        return array_slice($decisions, 0, 12);
    }

    // ── candidate ranking ──────────────────────────────────────────────────

    /**
     * Normalize + trust-score candidates; expand the manifest into its icon
     * entries. Higher trust first.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function rank(array $candidates): array
    {
        $trust = ['header-img' => 100, 'inline-svg' => 100, 'apple-touch' => 80, 'manifest-icon' => 75, 'icon' => 60, 'og-image' => 30, 'twitter-image' => 25];

        $out = [];
        foreach ($candidates as $c) {
            $kind = (string) ($c['kind'] ?? '');
            if ($kind === 'manifest' && is_string($c['url'] ?? null)) {
                foreach ($this->manifestIcons($c['url']) as $icon) {
                    $out[] = $icon + ['trust' => $trust['manifest-icon']];
                }

                continue;
            }
            if (! isset($trust[$kind])) {
                continue;
            }
            $score = $trust[$kind]
                + (($c['hint'] ?? false) ? 8 : 0)
                + (($c['inHeader'] ?? false) ? 4 : 0)
                + $this->declaredSizeBonus($c['sizes'] ?? null);
            $out[] = $c + ['trust' => $score];
        }

        usort($out, fn (array $a, array $b): int => $b['trust'] <=> $a['trust']);

        return $out;
    }

    /** Bigger declared icon sizes are better candidates ("512x512" ≫ "32x32"). */
    private function declaredSizeBonus(mixed $sizes): int
    {
        if (! is_string($sizes) || ! preg_match('/(\d+)x\d+/i', $sizes, $m)) {
            return 0;
        }

        return min(10, (int) ($m[1] / 64));
    }

    /** @return list<array<string, mixed>> icon candidates from a web manifest */
    private function manifestIcons(string $manifestUrl): array
    {
        $response = $this->fetcher->tryFetch($manifestUrl, ['Accept' => 'application/manifest+json,application/json,*/*;q=0.5']);
        if ($response === null || $response['status'] >= 400) {
            return [];
        }
        $manifest = json_decode($response['body'], true);
        if (! is_array($manifest) || ! is_array($manifest['icons'] ?? null)) {
            return [];
        }

        $out = [];
        foreach (array_slice($manifest['icons'], 0, 6) as $icon) {
            if (! is_array($icon) || ! is_string($icon['src'] ?? null)) {
                continue;
            }
            $abs = $this->absolutize($icon['src'], $response['finalUrl']);
            if ($abs !== null) {
                $out[] = ['kind' => 'manifest-icon', 'url' => $abs, 'sizes' => $icon['sizes'] ?? null];
            }
        }

        return $out;
    }

    // ── slot filling ───────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $ranked
     * @param  list<array<string, mixed>>  $decisions
     */
    private function attemptSlot(User $pro, Site $site, string $purpose, array $ranked, array &$decisions): void
    {
        foreach ($ranked as $candidate) {
            $ref = (string) ($candidate['url'] ?? ($candidate['kind'] === 'inline-svg' ? 'inline-svg' : ''));
            $decide = function (string $outcome) use (&$decisions, $purpose, $candidate, $ref): void {
                $decisions[] = ['slot' => $purpose, 'kind' => (string) $candidate['kind'], 'ref' => mb_substr($ref, 0, 200), 'outcome' => $outcome];
            };

            if (($candidate['kind'] ?? '') === 'inline-svg') {
                if ($this->tryInlineSvg($pro, $site, $purpose, $candidate, $decide)) {
                    return;
                }

                continue;
            }

            if (! is_string($candidate['url'] ?? null)) {
                continue;
            }

            // Upsized CDN variant first, original bytes as fallback.
            foreach (array_unique(array_filter([$this->upsizeUrl($candidate['url']), $candidate['url']])) as $url) {
                $image = $this->fetchImage($url);
                if ($image === null) {
                    continue;
                }
                [$bytes, $mime, $width, $height] = $image;

                $reason = $this->slotRejection($purpose, (string) ($candidate['kind'] ?? ''), $bytes, $mime, $width, $height);
                if ($reason !== null) {
                    $decide("rejected:{$reason} ({$width}x{$height})");

                    continue 2; // smaller variant of the same asset can't pass either
                }

                if ($this->upload($pro, $site, $bytes, $mime, $purpose)) {
                    $decide("uploaded ({$width}x{$height})");

                    return;
                }
                $decide('rejected:upload-failed');

                continue 2;
            }

            $decide('rejected:unfetchable');
        }
    }

    /** Null when the image fits the slot; else a bounded rejection reason. */
    private function slotRejection(string $purpose, string $kind, string $bytes, string $mime, int $width, int $height): ?string
    {
        if ($height < 1 || $width < 1) {
            return 'empty';
        }
        $aspect = $width / $height;

        if ($purpose === SiteMedia::PURPOSE_LOGO_SQUARE) {
            if (min($width, $height) < self::SQUARE_MIN_PX) {
                return 'too-small';
            }
            if ($aspect < self::SQUARE_ASPECT[0] || $aspect > self::SQUARE_ASPECT[1]) {
                return 'not-square';
            }

            return null;
        }

        // logo_full — wide wordmark shapes only.
        if ($width < self::FULL_MIN_W || $height < self::FULL_MIN_H) {
            return 'too-small';
        }
        if ($aspect < self::FULL_ASPECT_MIN) {
            return 'not-wide';
        }
        // The 1200×630-style share-card ratio is diagnostic of a generated
        // marketing card, not a logo — unless it carries alpha (designed art).
        if (in_array($kind, ['og-image', 'twitter-image'], true)
            && $aspect >= 1.85 && $aspect <= 1.97
            && ! $this->pngHasAlpha($bytes, $mime)) {
            return 'share-card';
        }

        return null;
    }

    /** @param callable(string): void $decide */
    private function tryInlineSvg(User $pro, Site $site, string $purpose, array $candidate, callable $decide): bool
    {
        // No processor → no rasterization path → skip SVG sources entirely.
        if (! (bool) config('partna.logo_removal.enabled', false)) {
            $decide('rejected:svg-pipeline-disabled');

            return false;
        }
        $svg = $candidate['svg'] ?? null;
        if (! is_string($svg) || strlen($svg) > self::SVG_MAX_BYTES || ! $this->svgIsSafe($svg)) {
            $decide('rejected:svg-unsafe');

            return false;
        }

        $aspect = $this->svgAspect($candidate);
        if ($aspect !== null) {
            $wantWide = $purpose === SiteMedia::PURPOSE_LOGO_FULL;
            if ($wantWide && $aspect < self::FULL_ASPECT_MIN) {
                $decide('rejected:not-wide');

                return false;
            }
            if (! $wantWide && ($aspect < self::SQUARE_ASPECT[0] || $aspect > self::SQUARE_ASPECT[1])) {
                $decide('rejected:not-square');

                return false;
            }
        }

        if ($this->upload($pro, $site, $svg, 'image/svg+xml', $purpose)) {
            $decide('uploaded (svg)');

            return true;
        }
        $decide('rejected:upload-failed');

        return false;
    }

    private function svgIsSafe(string $svg): bool
    {
        if (stripos($svg, '<svg') === false || preg_match(self::SVG_FORBIDDEN, $svg)) {
            return false;
        }

        // External references — only local fragment hrefs (#id) are allowed.
        return ! preg_match('/(?:xlink:)?href\s*=\s*["\'](?!#)/i', $svg);
    }

    /** Aspect from the collector's rendered rect, else the viewBox. */
    private function svgAspect(array $candidate): ?float
    {
        $w = $candidate['w'] ?? null;
        $h = $candidate['h'] ?? null;
        if (is_numeric($w) && is_numeric($h) && (float) $h > 0) {
            return (float) $w / (float) $h;
        }
        if (is_string($candidate['viewBox'] ?? null)
            && preg_match('/^[\d.-]+[\s,]+[\d.-]+[\s,]+([\d.]+)[\s,]+([\d.]+)/', trim($candidate['viewBox']), $m)
            && (float) $m[2] > 0) {
            return (float) $m[1] / (float) $m[2];
        }

        return null;
    }

    // ── CDN size upgrading ─────────────────────────────────────────────────

    /**
     * Rewrite known-CDN resize parameters upward — favicons declared at 32px
     * routinely front a full-resolution original. Unknown hosts return null
     * (no guessing at arbitrary URLs).
     */
    private function upsizeUrl(string $url): ?string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        // Shopify CDN: ?width= / ?height= query params.
        if (str_contains($host, 'shopify') || str_contains($url, '/cdn/shop/')) {
            $upsized = preg_replace(['/([?&])width=\d+/', '/([?&])height=\d+/'], ['${1}width=1024', '${1}height=1024'], $url);

            return $upsized !== $url ? $upsized : null;
        }

        // Wix statics + Cloudinary: path segments w_N / h_N (comma may be %2C).
        if (str_contains($host, 'wixstatic.com') || str_contains($host, 'cloudinary.com')) {
            $upsized = preg_replace(['/\bw_\d+/', '/\bh_\d+/'], ['w_512', 'h_512'], $url);

            return $upsized !== $url ? $upsized : null;
        }

        // Squarespace: ?format=NNNw.
        if (str_contains($host, 'squarespace')) {
            $upsized = preg_replace('/([?&])format=\d+w/', '${1}format=1500w', $url);

            return $upsized !== $url ? $upsized : null;
        }

        // imgix and friends: ?w= / ?h=.
        if (str_contains($host, 'imgix.net')) {
            $upsized = preg_replace(['/([?&])w=\d+/', '/([?&])h=\d+/'], ['${1}w=1024', '${1}h=1024'], $url);

            return $upsized !== $url ? $upsized : null;
        }

        return null;
    }

    // ── fetch / decode ─────────────────────────────────────────────────────

    /**
     * Fetch + sniff a candidate. .ico containers are decoded to their largest
     * embedded PNG frame. Returns [bytes, mime, width, height] or null.
     *
     * @return array{0:string,1:string,2:int,3:int}|null
     */
    private function fetchImage(string $url): ?array
    {
        $response = $this->fetcher->tryFetch($url, ['Accept' => 'image/*,*/*;q=0.5']);
        if ($response === null || $response['status'] >= 400 || $response['body'] === '') {
            return null;
        }
        $bytes = $response['body'];
        if (strlen($bytes) > self::MAX_DOWNLOAD_BYTES) {
            return null;
        }

        // ICO container → extract the largest PNG-encoded frame.
        if (str_starts_with($bytes, "\x00\x00\x01\x00")) {
            $bytes = self::icoLargestPngFrame($bytes);
            if ($bytes === null) {
                return null;
            }
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }
        $mime = (string) ($info['mime'] ?? '');
        if (! isset(self::ALLOWED_MIMES[$mime])) {
            return null;
        }

        return [$bytes, $mime, (int) $info[0], (int) $info[1]];
    }

    /**
     * Largest PNG-encoded frame in an .ico container; null when every frame is
     * legacy BMP or the directory is malformed.
     */
    public static function icoLargestPngFrame(string $ico): ?string
    {
        if (strlen($ico) < 6) {
            return null;
        }
        $count = unpack('v', substr($ico, 4, 2))[1] ?? 0;
        $best = null;
        $bestSize = -1;

        for ($i = 0; $i < min($count, 32); $i++) {
            $entry = substr($ico, 6 + $i * 16, 16);
            if (strlen($entry) < 16) {
                break;
            }
            $width = ord($entry[0]) ?: 256; // 0 encodes 256
            $length = unpack('V', substr($entry, 8, 4))[1] ?? 0;
            $offset = unpack('V', substr($entry, 12, 4))[1] ?? 0;
            $frame = substr($ico, $offset, $length);

            if (str_starts_with($frame, "\x89PNG\r\n\x1a\n") && $width > $bestSize) {
                $bestSize = $width;
                $best = $frame;
            }
        }

        return $best;
    }

    /** IHDR colour type 4/6 = greyscale+alpha / truecolour+alpha. */
    private function pngHasAlpha(string $bytes, string $mime): bool
    {
        return $mime === 'image/png'
            && strlen($bytes) > 25
            && in_array(ord($bytes[25]), [4, 6], true);
    }

    private function absolutize(string $href, string $baseUrl): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return null;
        }
        if (str_starts_with($href, '//')) {
            return "{$scheme}:{$href}";
        }
        if (str_starts_with($href, '/')) {
            return "{$scheme}://{$host}{$href}";
        }
        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');

        return "{$scheme}://{$host}{$dir}/".ltrim($href, './');
    }

    private function upload(User $pro, Site $site, string $bytes, string $mime, string $purpose): bool
    {
        $ext = self::ALLOWED_MIMES[$mime] ?? ($mime === 'image/svg+xml' ? 'svg' : null);
        if ($ext === null) {
            return false;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'partna_autologo_');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            return false;
        }

        try {
            $file = new UploadedFile($tmp, "auto-logo.{$ext}", $mime, null, true);
            $this->uploads->uploadSingleton(pro: $pro, site: $site, file: $file, purpose: $purpose);

            Log::info('LogoAutoGrabber: populated empty logo slot from previous website.', [
                'site_id' => $site->id,
                'purpose' => $purpose,
                'mime' => $mime,
            ]);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
