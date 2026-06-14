<?php

namespace App\Services\SmartLinks;

use App\Models\Core\Site\SmartLink;

/**
 * Re-resolves a stored smart link and updates its snapshot. Shared by the
 * dashboard "Refresh now" action and the smartlinks:refresh cron so the two
 * can't drift. Cache is bypassed (force-fresh). Commerce images re-ingest only
 * when the source URL changed.
 */
class SmartLinkRefresher
{
    public function __construct(
        private readonly SmartLinkResolver $resolver,
        private readonly SmartLinkImageService $imageService,
    ) {}

    public function refresh(SmartLink $link): SmartLink
    {
        $selection = $link->family === 'commerce'
            ? str_replace('commerce.', '', $link->type)
            : $link->platform;

        $rawUrl = $link->canonical_url.($link->tracking_query ? '?'.$link->tracking_query : '');
        $resolved = $this->resolver->resolve($rawUrl, $selection, bypassCache: true);

        if (! $resolved->valid || $resolved->data === null) {
            // Atomic increment so concurrent refreshes don't lose counts via
            // read-modify-write races. increment() fires `updating`/`updated` but NOT
            // `saved`; SmartLinkObserver is keyed on `saved`, so its cache bust is
            // skipped — acceptable because a FAILED refresh changes no public-facing
            // link data (the payload builder reads only is_active + title/image/metadata).
            $link->increment('consecutive_failures', 1, [
                'last_refreshed_at' => now(),
                'last_refresh_status' => 'unavailable',
            ]);

            return $link;
        }

        $rdata = $resolved->data;
        if ($resolved->family === 'commerce') {
            $rdata = $this->imageService->ingestCommerce(
                $rdata,
                (string) $link->site_id,
                previousSources: is_array($link->image_sources) ? $link->image_sources : [],
                previousHosted: [
                    'imageUrl' => $link->image_url,
                    'brandLogoUrl' => $link->brand_logo_url,
                    'faviconUrl' => $link->favicon_url,
                ],
            );
        }

        $link->fill([
            'title' => $rdata->title,
            'image_url' => $rdata->imageUrl,
            'favicon_url' => $rdata->faviconUrl,
            'brand_name' => $rdata->brandName,
            'brand_logo_url' => $rdata->brandLogoUrl,
            'metadata' => $rdata->metadata,
            'image_sources' => $rdata->imageSources,
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
            'consecutive_failures' => 0,
        ]);
        $link->save();

        return $link;
    }
}
