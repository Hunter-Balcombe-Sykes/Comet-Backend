<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\SiteMedia;
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Gallery is publishable once the site has at least one active gallery image.
class GalleryVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'gallery';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            'has_gallery_image' => SiteMedia::query()
                ->select(DB::raw('1'))
                ->where('site_id', $siteId)
                ->where('pool', SiteMedia::POOL_GALLERY)
                ->where('media_type', SiteMedia::MEDIA_TYPE_IMAGE)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->getQuery(),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return ($context['has_gallery_image'] ?? false)
            ? [true, null]
            : [false, 'Gallery section requires at least 1 uploaded image.'];
    }
}
