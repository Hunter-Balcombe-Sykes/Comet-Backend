<?php

namespace App\Services\SmartLinks;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Rehosts commerce-family images (product / collection / brand / event) onto
 * our media CDN so the card survives merchant URL rotation + deletion, and to
 * avoid leaking visitor IPs to merchant CDNs. Content-family images (music /
 * video / podcast) are hotlinked and never pass through here.
 *
 * Fetch goes through SafeUrlFetcher (SSRF guard). On any failure we keep the
 * original source URL (graceful hotlink fallback) so a card is never broken by
 * a rehost hiccup.
 */
class SmartLinkImageService
{
    /** Source-bag key => ResolvedSmartLinkData property. */
    private const FIELDS = [
        'image_url' => 'imageUrl',
        'brand_logo_url' => 'brandLogoUrl',
        'favicon_url' => 'faviconUrl',
    ];

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
    ) {}

    /**
     * Rehost every commerce image on the data in place. `$previousSources` lets
     * the refresh cron skip re-ingest when the merchant URL hasn't changed.
     *
     * @param  array<string,string>  $previousSources  field => last-seen source URL
     * @param  array<string,string>  $previousHosted  field => our-CDN URL from last time
     */
    public function ingestCommerce(
        ResolvedSmartLinkData $data,
        string $siteId,
        array $previousSources = [],
        array $previousHosted = [],
    ): ResolvedSmartLinkData {
        foreach (self::FIELDS as $srcKey => $prop) {
            $source = $data->imageSources[$srcKey] ?? null;
            if (! $source) {
                continue;
            }
            // Unchanged source → reuse the already-hosted URL, skip the re-fetch.
            if (($previousSources[$srcKey] ?? null) === $source && ! empty($previousHosted[$prop])) {
                $data->{$prop} = $previousHosted[$prop];

                continue;
            }
            $hosted = $this->rehost($source, $siteId);
            if ($hosted !== null) {
                $data->{$prop} = $hosted;
            }
        }

        return $data;
    }

    public function rehost(string $sourceUrl, string $siteId): ?string
    {
        try {
            $res = $this->fetcher->fetch($sourceUrl, ['Accept' => 'image/*']);
            if ($res['status'] >= 400) {
                return null;
            }
            $contentType = strtolower(trim(explode(';', $res['contentType'])[0]));
            if (! str_starts_with($contentType, 'image/')) {
                return null;
            }
            $ext = $this->extension($contentType);
            $path = "smart-links/{$siteId}/".md5($sourceUrl).'.'.$ext;

            Storage::disk($this->disk())->put($path, $res['body'], [
                'ContentType' => $contentType,
                'visibility' => 'public',
            ]);

            return Storage::disk($this->disk())->url($path);
        } catch (\Throwable $e) {
            Log::warning('SmartLink image rehost failed', [
                'source' => $sourceUrl,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function disk(): string
    {
        return (string) config('partna.media_disk', 'media');
    }

    private function extension(string $contentType): string
    {
        return match ($contentType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            default => 'jpg',
        };
    }
}
