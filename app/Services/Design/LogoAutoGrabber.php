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
 * Auto-populates EMPTY logo slots from a previous-website's icon candidates
 * (recorded by WebsiteStyleAnalyzer). Fills, never replaces: a slot with any
 * active media — user-uploaded or previously auto-grabbed — is left alone,
 * and nothing is ever deleted (once in the slot it's the user's media).
 *
 * Confidence gates (a wrong invisible logo is worse than none):
 *   - favicon / apple-touch-icon → logo_square, shorter side ≥ 96px. Dedicated
 *     icon signals are trusted; .ico containers are decoded (largest embedded
 *     PNG frame — legacy BMP frames + SVG icons are v1 gaps).
 *   - og:image → logo_full, only past vetting: the ~1.91:1 social-card ratio
 *     is rejected as "auto-generated share card, not a logo" (a transparent
 *     PNG overrides — photos never carry alpha), shorter side ≥ 200px.
 *
 * Accepted uploads run the standard manual pipeline (uploadSingleton →
 * ProcessLogoVariantsJob: bg-removal + vectorization + WebP variants) with its
 * existing graceful fallbacks.
 */
class LogoAutoGrabber
{
    private const ICON_MIN_PX = 96;

    private const OG_MIN_PX = 200;

    private const ALLOWED_MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly MediaUploadService $uploads,
    ) {}

    /** @param array<string, ?string> $candidates {appleTouchIcon, favicon, ogImage} */
    public function grabIfEmpty(User $pro, Site $site, array $candidates): void
    {
        $occupied = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->whereIn('purpose', [SiteMedia::PURPOSE_LOGO_FULL, SiteMedia::PURPOSE_LOGO_SQUARE])
            ->where('is_active', true)
            ->pluck('purpose')
            ->all();

        if (! in_array(SiteMedia::PURPOSE_LOGO_SQUARE, $occupied, true)) {
            foreach ([$candidates['appleTouchIcon'] ?? null, $candidates['favicon'] ?? null] as $url) {
                if ($url !== null && $this->tryIcon($pro, $site, $url)) {
                    break;
                }
            }
        }

        if (! in_array(SiteMedia::PURPOSE_LOGO_FULL, $occupied, true)
            && ($candidates['ogImage'] ?? null) !== null) {
            $this->tryOgImage($pro, $site, (string) $candidates['ogImage']);
        }
    }

    private function tryIcon(User $pro, Site $site, string $url): bool
    {
        $image = $this->fetchImage($url);
        if ($image === null) {
            return false;
        }
        [$bytes, $mime, $width, $height] = $image;

        if (min($width, $height) < self::ICON_MIN_PX) {
            return false;
        }

        return $this->upload($pro, $site, $bytes, $mime, SiteMedia::PURPOSE_LOGO_SQUARE);
    }

    private function tryOgImage(User $pro, Site $site, string $url): bool
    {
        $image = $this->fetchImage($url);
        if ($image === null) {
            return false;
        }
        [$bytes, $mime, $width, $height] = $image;

        if (min($width, $height) < self::OG_MIN_PX) {
            return false;
        }

        // The 1200×630-style share-card ratio is diagnostic of a generated
        // marketing card, not a logo — unless it's a transparent PNG (a
        // designed graphic; photos never carry alpha).
        $ratio = $height > 0 ? $width / $height : 0;
        if ($ratio >= 1.85 && $ratio <= 2.0 && ! $this->pngHasAlpha($bytes, $mime)) {
            return false;
        }

        return $this->upload($pro, $site, $bytes, $mime, SiteMedia::PURPOSE_LOGO_FULL);
    }

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
        if (strlen($bytes) > 8 * 1024 * 1024) {
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
     * legacy BMP (v1 gap) or the directory is malformed.
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

    private function upload(User $pro, Site $site, string $bytes, string $mime, string $purpose): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'partna_autologo_');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            return false;
        }

        try {
            $file = new UploadedFile($tmp, 'auto-logo.'.self::ALLOWED_MIMES[$mime], $mime, null, true);
            $this->uploads->uploadSingleton(pro: $pro, site: $site, file: $file, purpose: $purpose);

            Log::info('LogoAutoGrabber: populated empty logo slot from previous website.', [
                'site_id' => $site->id,
                'purpose' => $purpose,
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
