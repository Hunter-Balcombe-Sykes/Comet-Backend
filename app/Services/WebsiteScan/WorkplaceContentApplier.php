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

    /**
     * Overwrite-unless-manual write of AboutProseExtractor's richer, actually-
     * authored prose onto Workplace.description. Decision (2026-07-23, signup
     * testing repairs item 8): the business's OWN words win over anything
     * else — Google's editorial summary, and even this class's own plain
     * applyDescription() fill above — because real authored prose is a
     * stronger signal than a JSON-LD/meta description or Google's summary.
     * The one thing that stays untouchable is a value the user typed
     * themselves (same "manual is permanent" precedent as IdentitySync's
     * sector precedence).
     */
    public function applyProseDescription(Workplace $workplace, ?string $text): void
    {
        if ($text === null || trim($text) === '') {
            return;
        }
        $sources = is_array($workplace->field_sources) ? $workplace->field_sources : [];
        if (($sources['description']['source'] ?? null) === 'manual') {
            return;
        }
        $workplace->description = trim($text);
        $sources['description'] = ['source' => self::SOURCE, 'at' => now()->toIso8601String()];
        $workplace->field_sources = $sources;
        $workplace->save();
    }

    /** Fill-if-empty write of a previous-website-scraped contact email onto Workplace.contact_email — same shape as applyDescription(). */
    public function applyContactEmail(Workplace $workplace, ?string $email): void
    {
        if ($email === null || trim($email) === '' || ! ($workplace->contact_email === null || $workplace->contact_email === '')) {
            return;
        }
        $sources = is_array($workplace->field_sources) ? $workplace->field_sources : [];
        $workplace->contact_email = trim($email);
        $sources['contact_email'] = ['source' => self::SOURCE, 'at' => now()->toIso8601String()];
        $workplace->field_sources = $sources;
        $workplace->save();
    }
}
