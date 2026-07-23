<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Workplace;
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Workplace goes live once the site's workplace card has a non-empty name OR
// street address. PR3 (FOUND-4) moved the card from site.sites.settings.workplace
// JSONB to the site.workplaces table (1:1 with sites). The flat `address` display
// column was dropped 2026-07-23 (signup testing repairs item 1) — the predicate
// now reads the structured `address_line1` column in its place (no behavior
// change beyond the column swap: either still counts as "has a location").
class WorkplaceVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'workplace';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            'has_workplace' => Workplace::query()
                ->select(DB::raw('1'))
                ->where('site_id', $siteId)
                ->where(function ($q) {
                    $q->where(fn ($n) => $n->whereNotNull('name')->where('name', '<>', ''))
                        ->orWhere(fn ($a) => $a->whereNotNull('address_line1')->where('address_line1', '<>', ''));
                })
                ->getQuery(),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return ($context['has_workplace'] ?? false)
            ? [true, null]
            : [false, 'Workplace section requires a name or address before it can go live.'];
    }
}
