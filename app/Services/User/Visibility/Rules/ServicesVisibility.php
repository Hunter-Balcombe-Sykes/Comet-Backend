<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Services\Content\ManualServiceItems;
use App\Services\User\Visibility\SectionVisibilityContract;

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
        // B3: routed through ManualServiceItems::activePricedQuery() rather
        // than a fourth hand-rolled copy of this predicate — the missing
        // site.section_items join (so exclude()-hidden services still
        // counted here) is exactly the bug fixed there.
        return [
            'has_priced_service' => app(ManualServiceItems::class)->activePricedQuery($userId, $siteId),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return ($context['has_priced_service'] ?? false)
            ? [true, null]
            : [false, 'Services section requires at least 1 service with a title and price.'];
    }
}
