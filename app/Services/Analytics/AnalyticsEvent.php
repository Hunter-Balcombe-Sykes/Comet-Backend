<?php

namespace App\Services\Analytics;

// Immutable description of one analytics event. Flows through both seams and onto the
// queue payload, so it holds only scalars (no Eloquent model). `id` is minted at the
// controller and BECOMES the row primary key — the linchpin of at-least-once
// idempotency (the writer uses insertOrIgnore on it). `occurredAt` is an ISO-8601
// string captured at request time; `countryCode`/`regionCode`/`deviceType`/`ipHash`
// are likewise request-derived and front-loaded here because the worker has no
// request object.
//
// v2 (skeleton sitepages): clicks describe their destination directly —
// url/platform/product/section/label — instead of requiring a site.blocks FK
// (legacy rows keep blockId). TYPE_SESSION_PING upserts analytics.site_sessions;
// durationSeconds is the client's cumulative visible-time, idempotent under the
// writer's GREATEST() merge.
final class AnalyticsEvent
{
    public const TYPE_PAGEVIEW = 'pageview';

    public const TYPE_CLICK = 'click';

    public const TYPE_SECTION_VIEW = 'section_view';

    public const TYPE_SESSION_PING = 'session_ping';

    // Item-level impression (analytics v2, popularity scoring). Fired by the
    // storefront IntersectionObserver per scored item (shop product, menu item,
    // service, gallery item, …); writes analytics.item_views. Mirrors
    // TYPE_SECTION_VIEW but swaps the section grain for item_type/item_id.
    public const TYPE_ITEM_VIEW = 'item_view';

    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $occurredAt,
        public readonly string $userId,
        public readonly string $siteId,
        public readonly ?string $sessionId,
        public readonly ?string $visitorId,
        public readonly ?string $ipHash,
        public readonly ?string $userAgent,
        public readonly ?string $referrer,
        public readonly ?string $utmSource,
        public readonly ?string $utmMedium,
        public readonly ?string $utmCampaign,
        public readonly ?string $countryCode,
        public readonly ?string $deviceType,
        public readonly ?string $blockId,
        public readonly ?string $sectionKey,
        public readonly ?string $regionCode = null,
        public readonly ?string $city = null,
        public readonly ?string $url = null,
        public readonly ?string $platform = null,
        public readonly ?string $productId = null,
        public readonly ?string $productTitle = null,
        public readonly ?string $label = null,
        public readonly ?int $durationSeconds = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        // Item-impression grain (TYPE_ITEM_VIEW only): item_type is the scored-item
        // taxonomy value, item_id the product/item id, item_title an optional label.
        public readonly ?string $itemType = null,
        public readonly ?string $itemId = null,
        public readonly ?string $itemTitle = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'occurred_at' => $this->occurredAt,
            'user_id' => $this->userId,
            'site_id' => $this->siteId,
            'session_id' => $this->sessionId,
            'visitor_id' => $this->visitorId,
            'ip_hash' => $this->ipHash,
            'user_agent' => $this->userAgent,
            'referrer' => $this->referrer,
            'utm_source' => $this->utmSource,
            'utm_medium' => $this->utmMedium,
            'utm_campaign' => $this->utmCampaign,
            'country_code' => $this->countryCode,
            'device_type' => $this->deviceType,
            'block_id' => $this->blockId,
            'section_key' => $this->sectionKey,
            'region_code' => $this->regionCode,
            'city' => $this->city,
            'url' => $this->url,
            'platform' => $this->platform,
            'product_id' => $this->productId,
            'product_title' => $this->productTitle,
            'label' => $this->label,
            'duration_seconds' => $this->durationSeconds,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'item_type' => $this->itemType,
            'item_id' => $this->itemId,
            'item_title' => $this->itemTitle,
        ];
    }

    /** @param  array<string, mixed>  $d */
    public static function fromArray(array $d): self
    {
        return new self(
            id: $d['id'],
            type: $d['type'],
            occurredAt: $d['occurred_at'],
            userId: $d['user_id'],
            siteId: $d['site_id'],
            sessionId: $d['session_id'] ?? null,
            visitorId: $d['visitor_id'] ?? null,
            ipHash: $d['ip_hash'] ?? null,
            userAgent: $d['user_agent'] ?? null,
            referrer: $d['referrer'] ?? null,
            utmSource: $d['utm_source'] ?? null,
            utmMedium: $d['utm_medium'] ?? null,
            utmCampaign: $d['utm_campaign'] ?? null,
            countryCode: $d['country_code'] ?? null,
            deviceType: $d['device_type'] ?? null,
            blockId: $d['block_id'] ?? null,
            sectionKey: $d['section_key'] ?? null,
            regionCode: $d['region_code'] ?? null,
            city: $d['city'] ?? null,
            url: $d['url'] ?? null,
            platform: $d['platform'] ?? null,
            productId: $d['product_id'] ?? null,
            productTitle: $d['product_title'] ?? null,
            label: $d['label'] ?? null,
            durationSeconds: isset($d['duration_seconds']) ? (int) $d['duration_seconds'] : null,
            latitude: isset($d['latitude']) ? (float) $d['latitude'] : null,
            longitude: isset($d['longitude']) ? (float) $d['longitude'] : null,
            itemType: $d['item_type'] ?? null,
            itemId: $d['item_id'] ?? null,
            itemTitle: $d['item_title'] ?? null,
        );
    }
}
