<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\SiteMedia;
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Documents is publishable once the site has at least one active document.
class DocumentsVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'documents';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            'has_document' => SiteMedia::query()
                ->select(DB::raw('1'))
                ->where('site_id', $siteId)
                ->where('usage', SiteMedia::USAGE_DOCUMENTS)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->getQuery(),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return ($context['has_document'] ?? false)
            ? [true, null]
            : [false, 'Documents section requires an uploaded document.'];
    }
}
