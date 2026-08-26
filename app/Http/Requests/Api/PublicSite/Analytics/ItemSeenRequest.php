<?php

namespace App\Http\Requests\Api\PublicSite\Analytics;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\ResolvesPublicSiteSubdomain;
use Illuminate\Validation\Rule;

// Analytics v2 — public ingest for item-seen impression events fired by the
// storefront IntersectionObserver, one per scored item entering the viewport.
// Mirrors SectionSeenRequest (site resolved by UUID or subdomain) but carries an
// item grain: item_type (constrained to the 7 scored-item taxonomy values that
// analytics.content_popularity_scores ranks), item_id (the product/item id), and
// an optional item_title / section_key.
class ItemSeenRequest extends BaseFormRequest
{
    use ResolvesPublicSiteSubdomain;

    /**
     * The scored-item taxonomy — the item_type values the popularity job ranks.
     * Excludes 'page' (that's the section/page grain, not an item) but otherwise
     * matches analytics.content_popularity_scores.content_type. Kept in lockstep
     * with ComputeContentPopularityScores + the DDL comment.
     *
     * listen_item / watch_item / link_item were added 2026-07-10 alongside the ONE
     * theme's tracks/videos/custom-links items: the storefront fires item-seen with
     * these types, and ComputeContentPopularityScores::CLICK_SECTION_TO_ITEM_TYPE
     * already emits them for clicks — so impressions must be accepted here too, or
     * those three grains only ever accrue click score, never view score.
     *
     * @var list<string>
     */
    public const ITEM_TYPES = [
        'shop_product',
        'menu_item',
        'menu_category',
        'service',
        'service_category',
        'block',
        'gallery_item',
        'engine_item',
        'listen_item',
        'watch_item',
        'link_item',
        'event_item',
    ];

    protected function prepareForValidation(): void
    {
        $this->mergeSubdomainFromRoute('X-Site-Subdomain');
    }

    public function rules(): array
    {
        return [
            'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')],
            'subdomain' => ['required_without:site_id', 'string', 'max:63'],
            // item_type is the scored-item taxonomy discriminator — reject anything
            // outside the ranked set so item_views only accrues scoreable rows.
            'item_type' => ['required', 'string', Rule::in(self::ITEM_TYPES)],
            // item_id is the product/item id (TEXT — shop product ids, menu item
            // uuids, service uuids, media uuids all land here).
            'item_id' => ['required', 'string', 'max:255'],
            // item_title is an optional convenience label (denormalized for the
            // scoring job's product_title-style rollups).
            'item_title' => ['nullable', 'string', 'max:512'],
            // section_key ties the impression back to the hosting page/section so
            // the scoring job can bucket by page as well as by item.
            'section_key' => ['nullable', 'string', 'max:64'],
            'session_id' => ['nullable', 'uuid'],
            'visitor_id' => ['nullable', 'uuid'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
        ];
    }
}
