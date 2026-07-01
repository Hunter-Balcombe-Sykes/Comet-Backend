<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Models\Core\User\Service;
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Services & Pricing is publishable once there is at least one active, non-deleted
// service with a title and a price > 0 — the "valid enough to show publicly" bar.
class ServicesVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'services';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            'has_priced_service' => Service::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->whereNotNull('title')
                ->where('title', '!=', '')
                ->where('price_cents', '>', 0)
                ->getQuery(),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return ($context['has_priced_service'] ?? false)
            ? [true, null]
            : [false, 'Services section requires at least 1 service with a title and price.'];
    }
}
