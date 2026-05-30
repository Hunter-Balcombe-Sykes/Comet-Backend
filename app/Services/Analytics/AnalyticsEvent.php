<?php
// app/Services/Analytics/AnalyticsEvent.php
namespace App\Services\Analytics;

// Immutable description of one analytics event. Flows through both seams and onto the
// queue payload, so it holds only scalars (no Eloquent model). `id` is minted at the
// controller and BECOMES the row primary key — the linchpin of at-least-once
// idempotency (the writer uses insertOrIgnore on it). `occurredAt` is an ISO-8601
// string captured at request time; `countryCode`/`deviceType`/`ipHash` are likewise
// request-derived and front-loaded here because the worker has no request object.
final class AnalyticsEvent
{
    public const TYPE_PAGEVIEW = 'pageview';
    public const TYPE_CLICK = 'click';
    public const TYPE_SECTION_VIEW = 'section_view';

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
        );
    }
}
