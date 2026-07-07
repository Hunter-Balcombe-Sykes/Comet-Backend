<?php

namespace App\Services\Design\Presets\Factors;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\Presets\CategoryStylePresets;
use App\Services\Design\Presets\SiteDesignFactor;
use App\Services\Profile\SectorTaxonomy;
use Illuminate\Support\Collection;

// The user's own declared sector (core.users.sector, picked from the curated
// SectorTaxonomy) resolves to its taxonomy bucket and applies that bucket's
// preset. Only MANUALLY-picked sectors contribute (sector_source = 'manual',
// stamped by SectorController): a google-derived sector was mapped from the
// same category string GoogleBusinessTypeFactor already classifies, so
// contributing it again would only duplicate a lower-confidence copy of the
// same signal.
//
// Priority sits ABOVE Google Business (50): a person who explicitly tells us
// "I'm a therapist" outranks what their scraped Google listing implies —
// declared identity beats inferred identity. The previous-website factor
// (100) still beats both: their actual brand evidence wins over any
// classification.
class SectorFactor implements SiteDesignFactor
{
    public const SOURCE = 'profile:sector';

    public function key(): string
    {
        return self::SOURCE;
    }

    public function integrationLabel(): string
    {
        return 'sector';
    }

    public function priority(): int
    {
        return 60;
    }

    /** @return array<string, string> */
    public function detect(User $user, Site $site, Collection $activeConnections): array
    {
        // $activeConnections unused: this factor reads the user row only.
        if (($user->sector_source ?? null) !== 'manual') {
            return [];
        }

        $slug = trim((string) ($user->sector ?? ''));
        if ($slug === '') {
            return [];
        }

        $bucket = SectorTaxonomy::bucketFor($slug);

        return $bucket === null ? [] : CategoryStylePresets::forBucket($bucket);
    }
}
