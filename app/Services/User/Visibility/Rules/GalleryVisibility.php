<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Services\User\Visibility\SectionVisibilityContract;

// RETIRED SEMANTICS (Wave 6, 2026-09-02): the gallery BLOCK published off the
// legacy site_media gallery pool, which Item 5 retired (2026-09-01 backfill
// emptied it; nothing writes it). Every site still carries a gallery block
// row, so the rule stays registered — answering the same always-false the
// empty pool produced — rather than deleting the registration and changing
// what an unknown block type resolves to. The LIVE gallery page publishes
// through the media pool (presence-via-pools), not through this block.
class GalleryVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'gallery';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return [false, 'The legacy gallery section is retired — media publishes through the media pool.'];
    }
}
