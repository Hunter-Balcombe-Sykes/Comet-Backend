<?php

namespace App\Services\Analytics;

// Single source of truth for which blocks are click-trackable. The normalized
// section-type allowlist is read from config('partna.section_block_types') and
// consumed by the ingest writer (PostgresEventWriter — per-block boolean).
// AnalyticsQueryService::topSections() no longer derives from block clicks —
// it reads analytics.section_views directly (block model retired).
final class TrackableBlockTypes
{
    /**
     * Normalized (lowercased, trimmed, non-empty) trackable section block types.
     *
     * @return list<string>
     */
    public static function sectionTypes(): array
    {
        return collect(config('partna.section_block_types', ['gallery', 'services', 'booking']))
            ->filter(fn ($t) => is_string($t) && trim($t) !== '')
            ->map(fn (string $t) => strtolower(trim($t)))
            ->values()
            ->all();
    }

    // A block is click-trackable if it is a links/link block, or a sections block
    // whose type is in the configured allowlist. Group/type are matched case-insensitively.
    public static function isClickTrackable(?string $blockGroup, ?string $blockType): bool
    {
        $group = strtolower((string) $blockGroup);
        $type = strtolower((string) $blockType);

        return ($group === 'links' && $type === 'link')
            || ($group === 'sections' && in_array($type, self::sectionTypes(), true));
    }
}
