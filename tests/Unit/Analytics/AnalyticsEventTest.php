<?php
// tests/Unit/Analytics/AnalyticsEventTest.php

use App\Services\Analytics\AnalyticsEvent;

it('round-trips through array preserving the minted id and all fields', function () {
    $event = new AnalyticsEvent(
        id: 'abc-id', type: AnalyticsEvent::TYPE_CLICK, occurredAt: '2026-05-30T00:00:00.000000Z',
        userId: 'user-1', siteId: 'site-1', sessionId: 'sess-1', visitorId: 'vis-1',
        ipHash: 'hash', userAgent: 'UA', referrer: 'https://x.test', utmSource: 's',
        utmMedium: 'm', utmCampaign: 'c', countryCode: 'AU', deviceType: 'mobile',
        blockId: 'block-1', sectionKey: null,
    );

    $restored = AnalyticsEvent::fromArray($event->toArray());

    expect($restored->id)->toBe('abc-id')
        ->and($restored->type)->toBe('click')
        ->and($restored->blockId)->toBe('block-1')
        ->and($restored->countryCode)->toBe('AU')
        ->and($restored->toArray())->toBe($event->toArray());
});
