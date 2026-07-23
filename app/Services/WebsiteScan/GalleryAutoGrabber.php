<?php

namespace App\Services\WebsiteScan;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Media\Exceptions\PoolLimitExceededException;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Auto-populates an EMPTY gallery pool from a previous-website's own content
 * photos (WebsiteGalleryCandidateExtractor). Fills, never tops up: any site
 * with even one active gallery photo already — user-uploaded or previously
 * auto-grabbed — is left alone entirely, same "fills empty slots only, never
 * touches populated ones" contract as LogoAutoGrabber. MediaUploadService::
 * upload() is the single source of truth for the pool cap (PoolLimitExceededException
 * stops the loop once full) — no duplicate count-check here.
 */
class GalleryAutoGrabber
{
    private const MIN_LONG_EDGE_PX = 400;

    private const MAX_ASPECT = 3.0;

    private const MIN_ASPECT = 0.34; // 1 / MAX_ASPECT

    private const MAX_DOWNLOAD_BYTES = 8 * 1024 * 1024;

    private const ALLOWED_MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /** Safety cap on fetch attempts per run, independent of the pool's own max — a long candidate list shouldn't turn into an unbounded fetch spree. */
    private const MAX_ATTEMPTS = 10;

    /** Delay between outbound candidate fetches — same pacing rationale as LogoAutoGrabber. */
    private const FETCH_PACING_US = 200_000;

    private bool $fetchedBefore = false;

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly MediaUploadService $uploads,
    ) {}

    /**
     * @param  list<string>  $candidateUrls
     * @return list<array{url: string, outcome: string}> decisions, uploaded-first
     */
    public function grabIfEmpty(User $pro, Site $site, array $candidateUrls): array
    {
        if ($candidateUrls === []) {
            return [];
        }

        $hasExisting = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_GALLERY)
            ->where('is_active', true)
            ->where('processing_state', '!=', SiteMedia::PROCESSING_STATE_FAILED)
            ->exists();
        if ($hasExisting) {
            return [];
        }

        $decisions = [];
        foreach (array_slice($candidateUrls, 0, self::MAX_ATTEMPTS) as $url) {
            $image = $this->pacedFetchImage($url);
            if ($image === null) {
                $decisions[] = ['url' => $url, 'outcome' => 'rejected:unfetchable'];

                continue;
            }
            [$bytes, $mime, $width, $height] = $image;

            $reason = $this->rejection($width, $height);
            if ($reason !== null) {
                $decisions[] = ['url' => $url, 'outcome' => "rejected:{$reason} ({$width}x{$height})"];

                continue;
            }

            try {
                $this->upload($pro, $site, $bytes, $mime);
                $decisions[] = ['url' => $url, 'outcome' => "uploaded ({$width}x{$height})"];
            } catch (PoolLimitExceededException) {
                $decisions[] = ['url' => $url, 'outcome' => 'pool-full'];

                break;
            } catch (\Throwable $e) {
                report($e);
                $decisions[] = ['url' => $url, 'outcome' => 'rejected:upload-failed'];
            }
        }

        return $decisions;
    }

    private function rejection(int $width, int $height): ?string
    {
        if ($width < 1 || $height < 1) {
            return 'empty';
        }
        if (max($width, $height) < self::MIN_LONG_EDGE_PX) {
            return 'too-small';
        }
        $aspect = $width / $height;

        return ($aspect > self::MAX_ASPECT || $aspect < self::MIN_ASPECT) ? 'extreme-aspect' : null;
    }

    private function pacedFetchImage(string $url): ?array
    {
        if ($this->fetchedBefore) {
            usleep(self::FETCH_PACING_US);
        }
        $this->fetchedBefore = true;

        $response = $this->fetcher->tryFetch($url, ['Accept' => 'image/*,*/*;q=0.5']);
        if ($response === null || $response['status'] >= 400 || $response['body'] === '') {
            return null;
        }
        $bytes = $response['body'];
        if (strlen($bytes) > self::MAX_DOWNLOAD_BYTES) {
            return null;
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

    private function upload(User $pro, Site $site, string $bytes, string $mime): void
    {
        $ext = self::ALLOWED_MIMES[$mime];
        $tmp = tempnam(sys_get_temp_dir(), 'partna_autogallery_');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            throw new \RuntimeException('Could not write temp file for gallery auto-grab.');
        }

        try {
            $file = new UploadedFile($tmp, "auto-gallery.{$ext}", $mime, null, true);
            $this->uploads->upload(
                pro: $pro,
                site: $site,
                file: $file,
                pool: SiteMedia::POOL_GALLERY,
                isVideo: false,
                altText: null,
                caption: null,
            );

            Log::info('GalleryAutoGrabber: populated empty gallery from previous website.', [
                'site_id' => $site->id,
                'mime' => $mime,
            ]);
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
