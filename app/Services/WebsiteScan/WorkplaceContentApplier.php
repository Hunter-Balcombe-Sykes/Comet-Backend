<?php

namespace App\Services\WebsiteScan;

use App\Models\Core\Site\Workplace;

/** Fill-if-empty write of previous-website-scraped "about" text onto Workplace.description — mirrors InstagramIdentitySync's exact fill-if-empty/field_sources shape. */
class WorkplaceContentApplier
{
    private const SOURCE = 'website-scan';

    public function applyDescription(Workplace $workplace, ?string $text): void
    {
        if ($text === null || trim($text) === '' || ! ($workplace->description === null || $workplace->description === '')) {
            return;
        }
        $sources = is_array($workplace->field_sources) ? $workplace->field_sources : [];
        $workplace->description = trim($text);
        $sources['description'] = ['source' => self::SOURCE, 'at' => now()->toIso8601String()];
        $workplace->field_sources = $sources;
        $workplace->save();
    }
}
