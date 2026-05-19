<?php

namespace App\Observers\Core;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Site\Site;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Cache\SiteCacheService;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Syncs affiliate subdomain routing in Cloudflare KV when a brand connection is
// created or removed. Affiliates redirect from their own subdomain to brand.partna.au/affiliate.
//
// Also publishes `brand_links` notifications to BOTH sides of the connection
// (affiliate + brand) on create and delete — wires up the category that was
// previously dead (Mailable + view registered, but no emit sites).
//
// Master Pattern 15: also busts the Hydrogen affiliate-page cache — the cache
// entry's existence depends on the link (the controller 404s without it), and
// the affiliate-products cache depends on it indirectly.
class BrandPartnerLinkObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    // Memoize professional display names within the request — bulk imports
    // (CSV connect) can fire this observer many times for the same brand.
    private static array $nameCache = [];

    public function __construct(
        private readonly SiteCacheService $siteCache,
        private readonly NotificationPublisher $publisher,
    ) {}

    public function created(BrandPartnerLink $link): void
    {
        $this->dispatchSync($link);
        $this->bustHydrogenCaches($link);
        $this->publishCreated($link);
        $this->setHistoricalFlag($link, true);
    }

    // SoftDeletes-trait models fire `deleted` on soft-delete (NOT on force-delete).
    // Stays true on soft-delete (the row is still in the table, just tombstoned) —
    // the boolean reflects "ever had a link", not "currently has one".
    public function deleted(BrandPartnerLink $link): void
    {
        $this->dispatchSync($link);
        $this->bustHydrogenCaches($link);
        $this->publishDeleted($link);
    }

    // Fires when PurgeSoftDeleted removes a row at the end of the retention
    // window. Re-evaluate from withTrashed() — if this was the last link the
    // professional ever had, flip back to false.
    public function forceDeleted(BrandPartnerLink $link): void
    {
        $this->reevaluateHistoricalFlag($link);
    }

    private function setHistoricalFlag(BrandPartnerLink $link, bool $value): void
    {
        $affiliateId = trim((string) ($link->affiliate_professional_id ?? ''));
        if ($affiliateId === '') {
            return;
        }

        // Direct UPDATE (no model load) — the column is observer-maintained,
        // not $fillable, so this is the documented write path.
        DB::table('core.professionals')
            ->where('id', $affiliateId)
            ->where('has_historical_partner_links', '!=', $value)
            ->update(['has_historical_partner_links' => $value]);
    }

    private function reevaluateHistoricalFlag(BrandPartnerLink $link): void
    {
        $affiliateId = trim((string) ($link->affiliate_professional_id ?? ''));
        if ($affiliateId === '') {
            return;
        }

        // Intentional: this query INCLUDES soft-deleted rows (no whereNull on
        // deleted_at). has_historical_partner_links is "ever had a link", so
        // tombstones must keep it true. Only flips false when the last
        // physical row is force-deleted by PurgeSoftDeleted at end of
        // retention. Do not "fix" by filtering deleted_at.
        $stillHasAny = DB::table('brand.brand_partner_links')
            ->where('affiliate_professional_id', $affiliateId)
            ->exists();

        $this->setHistoricalFlag($link, $stillHasAny);
    }

    private function dispatchSync(BrandPartnerLink $link): void
    {
        $affiliateId = trim((string) ($link->affiliate_professional_id ?? ''));
        if ($affiliateId === '') {
            return;
        }

        try {
            SyncSubdomainToKvJob::dispatch($affiliateId);
        } catch (\Throwable $e) {
            Log::warning('BrandPartnerLinkObserver: KV sync dispatch failed', $this->logContext(__METHOD__, [
                'affiliate_professional_id' => $affiliateId,
                'brand_professional_id' => $link->brand_professional_id,
                'message' => $e->getMessage(),
            ]));
        }
    }

    private function bustHydrogenCaches(BrandPartnerLink $link): void
    {
        $affiliateId = trim((string) ($link->affiliate_professional_id ?? ''));
        if ($affiliateId === '') {
            return;
        }

        try {
            $siteId = Site::query()
                ->where('professional_id', $affiliateId)
                ->value('id');
            if (is_string($siteId) && $siteId !== '') {
                $this->siteCache->forgetHydrogenAffiliate($siteId);
            }
            $this->siteCache->forgetHydrogenAffiliateProducts($affiliateId);
        } catch (\Throwable $e) {
            Log::warning('BrandPartnerLinkObserver: Hydrogen cache bust failed', $this->logContext(__METHOD__, [
                'affiliate_professional_id' => $affiliateId,
                'brand_professional_id' => $link->brand_professional_id,
                'message' => $e->getMessage(),
            ]));
        }
    }

    private function publishCreated(BrandPartnerLink $link): void
    {
        $affiliateId = trim((string) ($link->affiliate_professional_id ?? ''));
        $brandId = trim((string) ($link->brand_professional_id ?? ''));
        if ($affiliateId === '' || $brandId === '') {
            return;
        }

        // Pair-keyed dedupe: stable across recreate within retention. Using
        // the link id would defeat dedupe since recreates get a fresh uuid.
        $dedupeKey = "brand_link.created.{$affiliateId}.{$brandId}";

        try {
            $brandName = $this->professionalName($brandId, 'Brand');
            $affiliateName = $this->professionalName($affiliateId, 'An affiliate');

            // To affiliate.
            $this->publisher->publish(
                professionalId: $affiliateId,
                frontendType: 'Success',
                category: 'brand_links',
                title: 'Brand connection live',
                body: "Your link to {$brandName} is live.",
                dedupeKey: $dedupeKey,
                ctaUrl: '/account/affiliates',
                retentionConfigKey: 'brand_link',
            );

            // To brand.
            $this->publisher->publish(
                professionalId: $brandId,
                frontendType: 'Success',
                category: 'brand_links',
                title: 'New affiliate connected',
                body: "{$affiliateName} is now connected.",
                dedupeKey: $dedupeKey,
                ctaUrl: '/account/affiliates',
                retentionConfigKey: 'brand_link',
            );
        } catch (\Throwable $e) {
            Log::warning('BrandPartnerLinkObserver: created notification failed', $this->logContext(__METHOD__, [
                'affiliate_professional_id' => $affiliateId,
                'brand_professional_id' => $brandId,
                'message' => $e->getMessage(),
            ]));
        }
    }

    private function publishDeleted(BrandPartnerLink $link): void
    {
        $affiliateId = trim((string) ($link->affiliate_professional_id ?? ''));
        $brandId = trim((string) ($link->brand_professional_id ?? ''));
        if ($affiliateId === '' || $brandId === '') {
            return;
        }

        // Removal dedupes on link id — each removal is its own event.
        $dedupeKey = "brand_link.removed.{$link->id}";

        try {
            $brandName = $this->professionalName($brandId, 'a brand');
            $affiliateName = $this->professionalName($affiliateId, 'An affiliate');

            $this->publisher->publish(
                professionalId: $affiliateId,
                frontendType: 'Info',
                category: 'brand_links',
                title: 'Brand connection removed',
                body: "Your link to {$brandName} has been removed.",
                dedupeKey: $dedupeKey,
                ctaUrl: '/account/affiliates',
                retentionConfigKey: 'brand_link',
            );

            $this->publisher->publish(
                professionalId: $brandId,
                frontendType: 'Info',
                category: 'brand_links',
                title: 'Affiliate connection removed',
                body: "{$affiliateName}'s connection has been removed.",
                dedupeKey: $dedupeKey,
                ctaUrl: '/account/affiliates',
                retentionConfigKey: 'brand_link',
            );
        } catch (\Throwable $e) {
            Log::warning('BrandPartnerLinkObserver: deleted notification failed', $this->logContext(__METHOD__, [
                'affiliate_professional_id' => $affiliateId,
                'brand_professional_id' => $brandId,
                'message' => $e->getMessage(),
            ]));
        }
    }

    private function professionalName(string $professionalId, string $fallback): string
    {
        if (! isset(self::$nameCache[$professionalId])) {
            $name = (string) (DB::table('core.professionals')
                ->where('id', $professionalId)
                ->whereNull('deleted_at')
                ->value(DB::raw("COALESCE(NULLIF(display_name, ''), NULLIF(handle, ''), '')")));

            self::$nameCache[$professionalId] = $name !== '' ? $name : $fallback;
        }

        return self::$nameCache[$professionalId];
    }
}
