<?php

namespace App\Services\WebsiteScan;

use App\Models\Core\Site\Site;
use App\Services\Cache\SiteCacheInvalidator;
use Illuminate\Support\Facades\DB;

/**
 * Direct fill-if-empty write to site.design_kits.color_accent — the one
 * surviving auto-source for accent colour now that the priority-merge
 * factor system's website-derived contributor (PreviousWebsiteFactor) is
 * gone (D1, PARTNA-SCRAPER-AND-DKIT-REWORKING-PLAN.md). No provenance
 * column exists for this field; a plain NULL check is the only "provenance"
 * it needs, since there's exactly one auto-source for it.
 */
class DesignKitAccentApplier
{
    public function __construct(private SiteCacheInvalidator $invalidator) {}

    public function apply(string $siteId, ?string $hex): void
    {
        if ($hex === null) {
            return;
        }

        $wrote = false;

        DB::connection('pgsql')->transaction(function () use ($siteId, $hex, &$wrote) {
            $existing = DB::connection('pgsql')->table('site.design_kits')
                ->where('site_id', $siteId)->lockForUpdate()->first();

            if ($existing !== null && $existing->color_accent !== null) {
                return;
            }

            DB::connection('pgsql')->table('site.design_kits')->updateOrInsert(
                ['site_id' => $siteId],
                ['color_accent' => $hex, 'updated_at' => now()],
            );
            $wrote = true;
        });

        if (! $wrote) {
            return;
        }

        $this->invalidator->touchSite(
            fn () => Site::find($siteId),
            'website-accent-scan',
            ['site_id' => $siteId],
        );
    }
}
