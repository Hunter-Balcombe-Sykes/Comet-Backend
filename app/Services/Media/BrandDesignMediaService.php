<?php

namespace App\Services\Media;

use App\Jobs\ProcessImageVariantsJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// V2: Single seam for brand design media — logo (full + square) and
// placeholders. Both ProfessionalUploadController (multipart) and
// SyncShopifyBrandDesignJob (Shopify-fetched bytes) call into this service so
// every brand design asset goes through the same persistence + variant pipeline.
class BrandDesignMediaService
{
    public const PLACEHOLDER_MAX = 5;

    private const ALLOWED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly ImageVariantService $images,
    ) {}

    /**
     * Upload a logo from a multipart UploadedFile. Variant is 'full' or 'square'.
     * Soft-deletes any prior active row with the same purpose so the singleton
     * index holds.
     */
    public function upsertLogoFromUploadedFile(Site $site, string $proId, UploadedFile $file, string $variant): SiteMedia
    {
        $purpose = $this->purposeForLogoVariant($variant);

        // finfo against actual bytes — getMimeType() trusts the client Content-Type header.
        $this->assertMimeAllowed((new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()));

        // Store the file BEFORE inserting the row so the row commits with
        // `path` already populated. Eliminates the soft-delete-during-flight
        // race where two concurrent uploads of the same variant would let
        // Logo B's singleton-replace (inside createDesignRow) soft-delete
        // Logo A's row before A's `$media->update(['path' => ...])` lands,
        // silently no-opping the update and leaving the file orphaned.
        // The deterministic content-hash basePath also dedupes identical
        // bytes across re-uploads.
        $contentHash = hash_file('sha256', $file->getRealPath());
        $basePath = "images/{$proId}/by-hash/{$contentHash}";

        try {
            $originalPath = $this->images->storeOriginal($file, $basePath);
        } catch (Throwable $e) {
            Log::error('BrandDesignMediaService: failed to store logo original.', [
                'request_id' => request()->header('X-Request-Id', ''),
                'operation' => __METHOD__,
                'site_id' => $site->id,
                'professional_id' => (string) $site->professional_id,
                'purpose' => $purpose,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $media = $this->createDesignRow(
            $site,
            $purpose,
            $file->getMimeType(),
            $file->getSize(),
            0,
            $originalPath,
        );

        $this->dispatchVariantJob($media->id, $originalPath, $basePath, (string) $site->id);
        $this->invalidateSiteCache($site);

        return $media->refresh();
    }


    /**
     * Append a placeholder image. Throws PlaceholderLimitExceededException if
     * 5 active placeholders already exist for the site.
     */
    public function addPlaceholder(Site $site, string $proId, UploadedFile $file): SiteMedia
    {
        $this->assertMimeAllowed((new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()));

        $media = DB::transaction(function () use ($site, $file) {
            // Only count rows that are actually visible in the UI (listDesignMedia
            // filters identically). Rows stuck in 'failed' or with is_active=false
            // must not consume quota — they are invisible to the brand dashboard
            // and there is no affordance to clear them from there.
            // lockForUpdate serializes concurrent addPlaceholder calls inside
            // the transaction so the count-then-insert cannot race past
            // PLACEHOLDER_MAX. Matches the locking pattern already in
            // deletePlaceholder/reorderPlaceholders below.
            // Lock the rows and count them in PHP. PostgreSQL rejects
            // `SELECT count(*) ... FOR UPDATE` (aggregate + row-level lock
            // is not supported, SQLSTATE 0A000); SQLite accepted it which
            // is why this slipped past the test suite. Pluck on the locked
            // rows is the canonical Laravel-on-Postgres workaround.
            $activeCount = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->whereNotIn('processing_state', [SiteMedia::PROCESSING_STATE_FAILED])
                ->lockForUpdate()
                ->pluck('id')
                ->count();

            if ($activeCount >= self::PLACEHOLDER_MAX) {
                throw new PlaceholderLimitExceededException(self::PLACEHOLDER_MAX);
            }

            $maxSort = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->whereNull('deleted_at')
                ->max('sort_order');

            return SiteMedia::create([
                'site_id' => $site->id,
                'pool' => SiteMedia::POOL_DESIGN,
                'purpose' => SiteMedia::PURPOSE_PLACEHOLDER,
                'path' => '',
                'alt_text' => $file->getClientOriginalName(),
                'sort_order' => is_null($maxSort) ? 0 : ((int) $maxSort + 1),
                'is_active' => true,
                'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
                'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
                'original_mime' => $file->getMimeType(),
                'original_size_bytes' => $file->getSize(),
            ]);
        });

        $basePath = "images/{$proId}/{$media->id}";

        try {
            $originalPath = $this->images->storeOriginal($file, $basePath);
        } catch (Throwable $e) {
            Log::error('BrandDesignMediaService: failed to store placeholder original.', [
                'request_id' => request()->header('X-Request-Id', ''),
                'operation' => __METHOD__,
                'site_id' => $site->id,
                'professional_id' => (string) $site->professional_id,
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
            $media->delete();
            throw $e;
        }

        $media->update(['path' => $originalPath]);
        $this->dispatchVariantJob($media->id, $originalPath, $basePath, (string) $site->id);
        $this->invalidateSiteCache($site);

        return $media->refresh();
    }

    /**
     * Soft-delete a placeholder and repack the remaining sort_order so the list
     * has no gaps. Throws if the media id doesn't belong to this site / isn't a
     * placeholder.
     */
    public function deletePlaceholder(Site $site, string $mediaId): void
    {
        DB::transaction(function () use ($site, $mediaId) {
            $row = SiteMedia::query()
                ->where('id', $mediaId)
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if (! $row) {
                abort(404, 'Placeholder not found.');
            }

            $row->delete();

            // Re-pack remaining placeholders to (0, 1, 2, ...) — first push to
            // a high offset to avoid colliding with the partial unique index,
            // then assign final values.
            $remaining = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->get();

            $offset = self::PLACEHOLDER_MAX + 1000;
            foreach ($remaining as $idx => $r) {
                SiteMedia::query()->where('id', $r->id)->update(['sort_order' => $offset + $idx]);
            }
            foreach ($remaining as $idx => $r) {
                SiteMedia::query()->where('id', $r->id)->update(['sort_order' => $idx]);
            }
        });

        $this->invalidateSiteCache($site);
    }

    /**
     * Replace the existing sort_order of placeholders with the supplied ordering.
     * The id list must contain exactly the active placeholder ids for this site
     * (no extras, no missing rows). Two-pass update to avoid index collisions.
     */
    public function reorderPlaceholders(Site $site, array $orderedIds): void
    {
        DB::transaction(function () use ($site, $orderedIds) {
            $existing = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            $existingSet = array_flip($existing);
            $orderedSet = array_flip($orderedIds);

            if (count($orderedIds) !== count($existing) || array_diff_key($existingSet, $orderedSet) !== []) {
                abort(422, 'Reorder ids must match active placeholders exactly.');
            }

            $offset = self::PLACEHOLDER_MAX + 1000;
            foreach ($orderedIds as $idx => $id) {
                SiteMedia::query()->where('id', $id)->update(['sort_order' => $offset + $idx]);
            }
            foreach ($orderedIds as $idx => $id) {
                SiteMedia::query()->where('id', $id)->update(['sort_order' => $idx]);
            }
        });

        $this->invalidateSiteCache($site);
    }

    /**
     * Soft-delete a logo row by variant (full or square). No-ops if no active
     * row exists for the given purpose.
     */
    public function deleteLogo(Site $site, string $variant): void
    {
        $purpose = $this->purposeForLogoVariant($variant);

        $row = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->where('purpose', $purpose)
            ->whereNull('deleted_at')
            ->first();

        if (! $row) {
            abort(404, 'Logo not found.');
        }

        $row->delete();

        $this->invalidateSiteCache($site);
    }

    /**
     * Resolve all brand design media for a site into the shape that every reader
     * (HydrogenBrandDesignController, BrandDesignController, SiteCacheService)
     * consumes. Failed rows are skipped; pending/processing rows are included
     * (with an empty url) so the dashboard can show a spinner instead of silently
     * hiding them.
     *
     * @return array{
     *     logo: array{full_url: ?string, square_url: ?string},
     *     placeholders: array<int, array{id: string, alt_text: ?string, url: string, sort_order: int, processing_state: string}>
     * }
     */
    public function listDesignMedia(string $siteId): array
    {
        $rows = SiteMedia::query()
            ->where('site_id', $siteId)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNotIn('processing_state', [SiteMedia::PROCESSING_STATE_FAILED])
            ->with('mediaVariants')
            ->orderBy('sort_order')
            ->get();

        $logo = ['full_url' => null, 'square_url' => null];
        $placeholders = [];

        foreach ($rows as $row) {
            $rawUrl = $row->variantUrls()['optimized'] ?? null;
            // MediaVariant::getUrlAttribute returns '' on a fallback failure
            // (disk not in config, Storage::disk() throws). Treat that the
            // same as a missing variant — both mean "no usable URL".
            $url = (is_string($rawUrl) && $rawUrl !== '') ? $rawUrl : null;

            if ($row->purpose === SiteMedia::PURPOSE_LOGO_FULL) {
                // Logos are single-slot. If the URL didn't resolve (variant
                // missing, disk misconfigured) leave the slot null so the
                // dashboard renders the empty-state uploader instead of a
                // broken image.
                if ($url !== null) {
                    $logo['full_url'] = $url;
                }
            } elseif ($row->purpose === SiteMedia::PURPOSE_LOGO_SQUARE) {
                if ($url !== null) {
                    $logo['square_url'] = $url;
                }
            } elseif ($row->purpose === SiteMedia::PURPOSE_PLACEHOLDER) {
                // Placeholders are always returned — the count in addPlaceholder
                // (PLACEHOLDER_MAX gate) counts every non-failed row, so the
                // list MUST too or the user hits "limit reached" while seeing
                // zero placeholders, with no UI affordance to clear the
                // orphans. Rows without a resolvable URL render as empty
                // cards with the remove control intact.
                if ($url === null && $row->processing_state === SiteMedia::PROCESSING_STATE_READY) {
                    Log::warning('BrandDesignMediaService: ready placeholder has no resolvable optimized URL.', [
                        'site_id' => $siteId,
                        'media_id' => $row->id,
                        'disk_keys' => $row->mediaVariants->pluck('disk')->unique()->values()->all(),
                        'variant_keys' => $row->mediaVariants->pluck('variant_key')->all(),
                    ]);
                }
                $placeholders[] = [
                    'id' => $row->id,
                    'alt_text' => $row->alt_text,
                    'url' => $url ?? '',
                    'sort_order' => (int) $row->sort_order,
                    'processing_state' => $row->processing_state,
                ];
            }
        }

        return ['logo' => $logo, 'placeholders' => $placeholders];
    }

    /**
     * Resolve the optimized full-logo URL for a single site, or null if absent.
     * Reads from site_media (pool=design, purpose=logo_full) — the same source
     * BrandDesignResource exposes as logo.full_url. Use this anywhere the brand's
     * full logo needs to be displayed (invites, partner cards, public pages).
     */
    public function getLogoFullUrl(string $siteId): ?string
    {
        return $this->getLogoFullUrls([$siteId])[$siteId] ?? null;
    }

    /**
     * Bulk-resolve optimized full-logo URLs for multiple sites in a single query.
     * Returns a [site_id => url] map; sites without a logo are omitted.
     *
     * @param  array<int, string>  $siteIds
     * @return array<string, string>
     */
    public function getLogoFullUrls(array $siteIds): array
    {
        if (empty($siteIds)) {
            return [];
        }

        return SiteMedia::query()
            ->whereIn('site_id', $siteIds)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->where('purpose', SiteMedia::PURPOSE_LOGO_FULL)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('processing_state', '!=', SiteMedia::PROCESSING_STATE_FAILED)
            ->with('mediaVariants')
            ->get()
            ->mapWithKeys(fn (SiteMedia $m): array => [
                (string) $m->site_id => $m->variantUrls()['optimized'] ?? null,
            ])
            ->filter()
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Internal helpers */
    /* ------------------------------------------------------------------ */

    private function assertMimeAllowed(string $actual): void
    {
        if (! in_array($actual, self::ALLOWED_IMAGE_MIMES, true)) {
            throw new UnprocessableImageException(
                "Rejected: MIME type '{$actual}' is not an accepted image format."
            );
        }
    }

    private function purposeForLogoVariant(string $variant): string
    {
        return match ($variant) {
            'full' => SiteMedia::PURPOSE_LOGO_FULL,
            'square' => SiteMedia::PURPOSE_LOGO_SQUARE,
            default => throw new \InvalidArgumentException("Unknown logo variant: {$variant}"),
        };
    }

    private function createDesignRow(
        Site $site,
        string $purpose,
        ?string $mime,
        ?int $size,
        int $sortOrder,
        ?string $path = null,
    ): SiteMedia {
        return DB::transaction(function () use ($site, $purpose, $mime, $size, $sortOrder, $path) {
            // Singleton-replace: soft-delete any prior active row with this purpose.
            SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', $purpose)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->get()
                ->each(fn (SiteMedia $row) => $row->delete());

            // Logo callers pass $path so the row commits with it already set
            // (the file was stored before this call to avoid the soft-delete-
            // during-flight race). Callers that legitimately need an empty
            // path can omit it.
            return SiteMedia::create([
                'site_id' => $site->id,
                'pool' => SiteMedia::POOL_DESIGN,
                'purpose' => $purpose,
                'path' => $path ?? '',
                'sort_order' => $sortOrder,
                'is_active' => true,
                'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
                'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
                'original_mime' => $mime,
                'original_size_bytes' => $size,
            ]);
        });
    }

    private function dispatchVariantJob(string $imageId, string $originalPath, string $basePath, string $siteId = ''): void
    {
        $queueDefault = (string) config('queue.default', 'sync');
        $processInline = in_array(app()->environment(), ['local', 'testing'], true)
            || $queueDefault === 'sync';

        if ($processInline) {
            try {
                ProcessImageVariantsJob::dispatchSync(
                    originalPath: $originalPath,
                    imageId: $imageId,
                    basePath: $basePath,
                    siteId: $siteId,
                );
            } catch (Throwable $e) {
                report($e);
                Log::error('BrandDesignMediaService: inline variant processing failed.', [
                    'request_id' => request()->header('X-Request-Id', ''),
                    'operation' => __METHOD__,
                    'image_id' => $imageId,
                    'site_id' => $siteId,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        try {
            ProcessImageVariantsJob::dispatch(
                originalPath: $originalPath,
                imageId: $imageId,
                basePath: $basePath,
                siteId: $siteId,
            );
        } catch (Throwable $e) {
            report($e);
            Log::error('BrandDesignMediaService: queue dispatch failed; falling back to sync.', [
                'request_id' => request()->header('X-Request-Id', ''),
                'operation' => __METHOD__,
                'image_id' => $imageId,
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);
            try {
                ProcessImageVariantsJob::dispatchSync(
                    originalPath: $originalPath,
                    imageId: $imageId,
                    basePath: $basePath,
                    siteId: $siteId,
                );
            } catch (Throwable $sync) {
                report($sync);
                Log::error('BrandDesignMediaService: sync fallback also failed.', [
                    'request_id' => request()->header('X-Request-Id', ''),
                    'operation' => __METHOD__,
                    'image_id' => $imageId,
                    'site_id' => $siteId,
                    'error' => $sync->getMessage(),
                ]);
            }
        }
    }

    private function invalidateSiteCache(Site $site): void
    {
        try {
            $siteCache = app(SiteCacheService::class);
            $siteCache->invalidateSite($site);
            // Also bust the Hydrogen brand-design cache so dashboard saves
            // (logo, placeholder upload/delete/reorder) surface on Hydrogen
            // inside its 5s staleWhileRevalidate window.
            $siteCache->forgetBrandDesign((string) $site->id);
        } catch (Throwable $e) {
            report($e);
            Log::warning('BrandDesignMediaService: cache invalidation failed.', [
                'request_id' => request()->header('X-Request-Id', ''),
                'operation' => __METHOD__,
                'site_id' => $site->id,
                'professional_id' => (string) $site->professional_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extensionFromMime(string $mime): string
    {
        return match (strtolower(trim(explode(';', $mime)[0] ?? ''))) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
    }
}
